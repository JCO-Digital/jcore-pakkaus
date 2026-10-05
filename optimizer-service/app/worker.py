from __future__ import annotations

import asyncio
import hashlib
import hmac
import json
import logging
import shutil
import time
from pathlib import Path

import httpx

from . import __version__, telemetry
from .config import Settings, host_allowed
from .ffmpeg import Capabilities, FFmpegError, build_command, probe, transcode
from .models import JobOptions
from .store import ACTIVE_STATUSES, Store, public_job

log = logging.getLogger(__name__)

CALLBACK_BACKOFF = (5, 30, 120, 600, 1800)
PROGRESS_INTERVAL = 2.0


class JobError(Exception):
    pass


class JobCancelled(Exception):
    pass


class JobRunner:
    def __init__(self, settings: Settings, store: Store, caps: Capabilities) -> None:
        self.settings = settings
        self.store = store
        self.caps = caps
        self.queue: asyncio.Queue[str] = asyncio.Queue()
        self.active: set[str] = set()
        self._tasks: list[asyncio.Task] = []
        self._background: set[asyncio.Task] = set()
        self._procs: dict[str, asyncio.subprocess.Process] = {}
        self._cancelled: set[str] = set()
        self._http = httpx.AsyncClient(
            follow_redirects=True,
            timeout=httpx.Timeout(30, read=120),
            headers={"User-Agent": f"jcore-pakkaus-service/{__version__}"},
            event_hooks={"request": [self._check_host]},
        )

    @property
    def jobs_dir(self) -> Path:
        return self.settings.data_dir / "jobs"

    def job_dir(self, job_id: str) -> Path:
        return self.jobs_dir / job_id

    def output_path(self, job_id: str) -> Path:
        return self.job_dir(job_id) / "output.mp4"

    async def _check_host(self, request: httpx.Request) -> None:
        # Runs for every request including redirects, so a redirect can't escape the allowlist.
        if not host_allowed(request.url.host, self.settings.allowed_hosts):
            raise JobError(f"Host {request.url.host!r} is not in ALLOWED_HOSTS")

    # ------------------------------------------------------------ lifecycle

    async def start(self) -> None:
        self.jobs_dir.mkdir(parents=True, exist_ok=True)
        known = self.store.job_ids()
        for path in self.jobs_dir.iterdir():
            if path.name not in known:
                shutil.rmtree(path, ignore_errors=True)

        for job in self.store.jobs_with_status(ACTIVE_STATUSES):
            log.info("Re-queueing interrupted job %s", job["id"])
            self.store.update_job(job["id"], status="queued", progress=0)
            self.queue.put_nowait(job["id"])

        for n in range(self.settings.workers):
            self._tasks.append(asyncio.create_task(self._worker(n), name=f"worker-{n}"))
        self._tasks.append(asyncio.create_task(self._cleanup_loop(), name="cleanup"))

    async def stop(self) -> None:
        for proc in list(self._procs.values()):
            if proc.returncode is None:
                proc.kill()
        for task in [*self._tasks, *self._background]:
            task.cancel()
        await asyncio.gather(*self._tasks, *self._background, return_exceptions=True)
        await self._http.aclose()

    def enqueue(self, job_id: str) -> None:
        self.queue.put_nowait(job_id)

    def cancel(self, job_id: str) -> None:
        self._cancelled.add(job_id)
        proc = self._procs.get(job_id)
        if proc and proc.returncode is None:
            proc.kill()

    def _check_cancel(self, job_id: str) -> None:
        if job_id in self._cancelled:
            raise JobCancelled

    # ----------------------------------------------------------- processing

    async def _worker(self, n: int) -> None:
        while True:
            job_id = await self.queue.get()
            self.active.add(job_id)
            try:
                await self._process(job_id)
            except Exception:  # never let one job kill the worker
                log.exception("Unexpected error while processing job %s", job_id)
            finally:
                self.active.discard(job_id)
                self._cancelled.discard(job_id)
                self.queue.task_done()

    async def _process(self, job_id: str) -> None:
        job = self.store.get_job(job_id)
        if job is None or job["status"] != "queued" or job_id in self._cancelled:
            return

        workdir = self.job_dir(job_id)
        workdir.mkdir(parents=True, exist_ok=True)
        src = workdir / "input"
        tmp = workdir / "output.part.mp4"
        dst = self.output_path(job_id)
        started = time.monotonic()
        codec = job["options"].get("codec", "unknown")

        try:
            opts = JobOptions(**job["options"])
            self.store.update_job(job_id, status="downloading", progress=0)
            log.info("Job %s: downloading %s", job_id, job["source_url"])
            await self._download(job_id, job["source_url"], src)

            info = await probe(src)
            if info.video_index is None:
                raise JobError("The source file does not contain a video stream")
            self.store.update_job(job_id, status="processing", input=info.to_dict())
            log.info("Job %s: transcoding %dx%d %s (%.1fs)", job_id, info.width, info.height,
                     info.video_codec, info.duration)

            last_update = 0.0

            def on_progress(fraction: float) -> None:
                nonlocal last_update
                now = time.monotonic()
                if now - last_update >= PROGRESS_INTERVAL:
                    last_update = now
                    self.store.update_job(job_id, progress=round(fraction * 100, 1))

            cmd = build_command(src, tmp, info, opts, self.caps, self.settings.ffmpeg_threads)
            await transcode(
                cmd, info.duration, on_progress, self.settings.job_timeout,
                on_start=lambda proc: self._procs.__setitem__(job_id, proc),
            )
            self._check_cancel(job_id)
            tmp.rename(dst)

            out = await probe(dst)
            savings = (1 - out.size / info.size) * 100 if info.size else 0
            result = {**out.to_dict(), "savings_percent": round(savings, 2),
                      "elapsed_seconds": round(time.monotonic() - started, 1)}

            if savings < opts.min_savings_percent:
                dst.unlink(missing_ok=True)
                message = (f"Optimized file would only be {savings:.1f}% smaller "
                           f"(minimum {opts.min_savings_percent:g}%); keeping the original.")
                self.store.update_job(job_id, status="skipped", progress=100, output=result, message=message)
                telemetry.record_job("skipped", codec, time.monotonic() - started, input_bytes=info.size)
                log.info("Job %s: skipped (%s)", job_id, message)
            else:
                self.store.update_job(job_id, status="completed", progress=100, output=result, message=None)
                telemetry.record_job("completed", codec, time.monotonic() - started,
                                     input_bytes=info.size, output_bytes=out.size)
                log.info("Job %s: completed, %s -> %s bytes (-%.1f%%)", job_id, info.size, out.size, savings)
        except JobCancelled:
            log.info("Job %s: cancelled", job_id)
            telemetry.record_job("cancelled", codec, time.monotonic() - started)
            shutil.rmtree(workdir, ignore_errors=True)
            return
        except (JobError, FFmpegError, httpx.HTTPError, OSError, ValueError) as exc:
            if job_id in self._cancelled:
                log.info("Job %s: cancelled", job_id)
                telemetry.record_job("cancelled", codec, time.monotonic() - started)
                shutil.rmtree(workdir, ignore_errors=True)
                return
            message = str(exc) or exc.__class__.__name__
            log.warning("Job %s: failed: %s", job_id, message)
            self.store.update_job(job_id, status="failed", message=message)
            telemetry.record_job("failed", codec, time.monotonic() - started)
        finally:
            self._procs.pop(job_id, None)
            src.unlink(missing_ok=True)
            tmp.unlink(missing_ok=True)

        self._spawn(self._send_callback(job_id))

    async def _download(self, job_id: str, url: str, dest: Path) -> None:
        limit = self.settings.max_input_bytes
        total = 0
        try:
            async with asyncio.timeout(self.settings.download_timeout):
                self._check_cancel(job_id)
                async with self._http.stream("GET", url) as resp:
                    if resp.status_code != 200:
                        raise JobError(f"Downloading the source failed with HTTP {resp.status_code}")
                    length = resp.headers.get("content-length")
                    if length and length.isdigit() and int(length) > limit:
                        raise JobError(f"Source is larger than the {limit // 1048576} MB limit")
                    with dest.open("wb") as fh:
                        async for chunk in resp.aiter_raw():
                            total += len(chunk)
                            if total > limit:
                                raise JobError(f"Source is larger than the {limit // 1048576} MB limit")
                            self._check_cancel(job_id)
                            fh.write(chunk)
        except TimeoutError as exc:
            raise JobError("Downloading the source timed out") from exc
        if total == 0:
            raise JobError("Downloaded source file is empty")

    # ------------------------------------------------------------ callbacks

    def _spawn(self, coro) -> None:
        task = asyncio.create_task(coro)
        self._background.add(task)
        task.add_done_callback(self._background.discard)

    async def _send_callback(self, job_id: str) -> None:
        job = self.store.get_job(job_id)
        if not job or not job["callback_url"]:
            return
        body = json.dumps(
            {"event": f"job.{job['status']}", "job": public_job(job)}, separators=(",", ":")
        ).encode()

        for attempt in range(self.settings.callback_retries):
            headers = {"Content-Type": "application/json"}
            if job["callback_secret"]:
                timestamp = str(int(time.time()))
                signature = hmac.new(
                    job["callback_secret"].encode(), timestamp.encode() + b"." + body, hashlib.sha256
                ).hexdigest()
                headers["X-Jcore-Pakkaus-Timestamp"] = timestamp
                headers["X-Jcore-Pakkaus-Signature"] = f"sha256={signature}"
                # Legacy names, for sites still running the stand-alone Video Optimizer plugin.
                headers["X-Video-Optimizer-Timestamp"] = timestamp
                headers["X-Video-Optimizer-Signature"] = f"sha256={signature}"
            try:
                resp = await self._http.post(job["callback_url"], content=body, headers=headers, timeout=30)
                if resp.status_code < 300:
                    log.info("Job %s: callback delivered", job_id)
                    telemetry.callbacks.add(1, {"result": "delivered"})
                    return
                if 400 <= resp.status_code < 500 and resp.status_code not in (408, 409, 425, 429):
                    log.warning("Job %s: callback rejected with HTTP %s, not retrying", job_id, resp.status_code)
                    telemetry.callbacks.add(1, {"result": "rejected"})
                    return
                log.warning("Job %s: callback returned HTTP %s", job_id, resp.status_code)
            except Exception as exc:  # noqa: BLE001 - network errors, allowlist errors, ...
                log.warning("Job %s: callback failed: %s", job_id, exc)
            if attempt + 1 < self.settings.callback_retries:
                await asyncio.sleep(CALLBACK_BACKOFF[min(attempt, len(CALLBACK_BACKOFF) - 1)])
        log.error("Job %s: giving up on callback; the client has to poll", job_id)
        telemetry.callbacks.add(1, {"result": "gave_up"})

    # -------------------------------------------------------------- cleanup

    def remove(self, job_id: str) -> None:
        # Queued-but-not-started jobs are skipped by the worker once their row is gone.
        if job_id in self.active:
            self.cancel(job_id)
        shutil.rmtree(self.job_dir(job_id), ignore_errors=True)
        self.store.delete_job(job_id)

    async def _cleanup_loop(self) -> None:
        while True:
            try:
                cutoff = time.time() - self.settings.job_ttl_seconds
                for job_id in self.store.expired_job_ids(cutoff):
                    if job_id not in self.active:
                        log.info("Removing expired job %s", job_id)
                        self.remove(job_id)
            except Exception:
                log.exception("Cleanup failed")
            await asyncio.sleep(600)
