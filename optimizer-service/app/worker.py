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

from PIL import Image

from . import __version__, images, telemetry
from .config import Settings, host_allowed
from .ffmpeg import Capabilities, FFmpegError, build_command, probe, transcode
from .images import ImageCapabilities, ImageError
from .models import ImageOptions, JobOptions
from .store import ACTIVE_STATUSES, Store, public_job

log = logging.getLogger(__name__)

CALLBACK_BACKOFF = (5, 30, 120, 600, 1800)
PROGRESS_INTERVAL = 2.0


class JobError(Exception):
    pass


class JobCancelled(Exception):
    pass


class JobRunner:
    def __init__(
        self, settings: Settings, store: Store, caps: Capabilities, image_caps: ImageCapabilities | None = None
    ) -> None:
        self.settings = settings
        self.store = store
        self.caps = caps
        self.image_caps = image_caps or ImageCapabilities(webp=False, pngquant=None)
        # Images get their own queue and workers, so a long transcode never holds them up.
        self.queue: asyncio.Queue[str] = asyncio.Queue()
        self.image_queue: asyncio.Queue[str] = asyncio.Queue()
        self.active: set[str] = set()
        self.image_active: set[str] = set()
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
        # Pillow's own decompression-bomb guard, in line with the limit image jobs are checked against.
        Image.MAX_IMAGE_PIXELS = settings.max_image_pixels

    @property
    def jobs_dir(self) -> Path:
        return self.settings.data_dir / "jobs"

    def job_dir(self, job_id: str) -> Path:
        return self.jobs_dir / job_id

    def output_path(self, job_id: str) -> Path:
        return self.job_dir(job_id) / "output.mp4"

    def image_output_path(self, job_id: str, index: int, variant: str) -> Path:
        """One result of an image job: `optimized` (same format, smaller) or a format such as `webp`."""
        return self.job_dir(job_id) / "files" / f"{index}.{variant}"

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
            self.enqueue(job["id"], job.get("kind") or "video")

        for n in range(self.settings.workers):
            self._tasks.append(asyncio.create_task(self._worker(self.queue), name=f"worker-{n}"))
        for n in range(self.settings.image_workers):
            self._tasks.append(asyncio.create_task(
                self._worker(self.image_queue, self.image_active), name=f"image-worker-{n}"
            ))
        self._tasks.append(asyncio.create_task(self._cleanup_loop(), name="cleanup"))

    async def stop(self) -> None:
        for proc in list(self._procs.values()):
            if proc.returncode is None:
                proc.kill()
        for task in [*self._tasks, *self._background]:
            task.cancel()
        await asyncio.gather(*self._tasks, *self._background, return_exceptions=True)
        await self._http.aclose()

    def enqueue(self, job_id: str, kind: str = "video") -> None:
        (self.image_queue if kind == "image" else self.queue).put_nowait(job_id)

    def cancel(self, job_id: str) -> None:
        self._cancelled.add(job_id)
        proc = self._procs.get(job_id)
        if proc and proc.returncode is None:
            proc.kill()

    def _check_cancel(self, job_id: str) -> None:
        if job_id in self._cancelled:
            raise JobCancelled

    # ----------------------------------------------------------- processing

    async def _worker(self, queue: asyncio.Queue[str], lane: set[str] | None = None) -> None:
        while True:
            job_id = await queue.get()
            self.active.add(job_id)
            if lane is not None:
                lane.add(job_id)
            try:
                await self._process(job_id)
            except Exception:  # never let one job kill the worker
                log.exception("Unexpected error while processing job %s", job_id)
            finally:
                self.active.discard(job_id)
                if lane is not None:
                    lane.discard(job_id)
                self._cancelled.discard(job_id)
                queue.task_done()

    async def _process(self, job_id: str) -> None:
        job = self.store.get_job(job_id)
        if job is None or job["status"] != "queued" or job_id in self._cancelled:
            return

        is_image = job.get("kind") == "image"
        label = "image" if is_image else job["options"].get("codec", "unknown")
        started = time.monotonic()
        try:
            if is_image:
                await self._process_image(job_id, job, started)
            else:
                await self._process_video(job_id, job, started)
        except JobCancelled:
            self._discard_cancelled(job_id, label, started)
            return
        except (JobError, FFmpegError, ImageError, httpx.HTTPError, OSError, ValueError) as exc:
            if job_id in self._cancelled:
                self._discard_cancelled(job_id, label, started)
                return
            message = str(exc) or exc.__class__.__name__
            log.warning("Job %s: failed: %s", job_id, message)
            self.store.update_job(job_id, status="failed", message=message)
            telemetry.record_job("failed", label, time.monotonic() - started)
        finally:
            self._procs.pop(job_id, None)

        self._spawn(self._send_callback(job_id))

    def _discard_cancelled(self, job_id: str, label: str, started: float) -> None:
        log.info("Job %s: cancelled", job_id)
        telemetry.record_job("cancelled", label, time.monotonic() - started)
        shutil.rmtree(self.job_dir(job_id), ignore_errors=True)

    async def _process_video(self, job_id: str, job: dict, started: float) -> None:
        workdir = self.job_dir(job_id)
        workdir.mkdir(parents=True, exist_ok=True)
        src = workdir / "input"
        tmp = workdir / "output.part.mp4"
        dst = self.output_path(job_id)
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
        finally:
            src.unlink(missing_ok=True)
            tmp.unlink(missing_ok=True)

    # --------------------------------------------------------------- images

    async def _process_image(self, job_id: str, job: dict, started: float) -> None:
        opts = ImageOptions(**job["options"])
        files = job["files"] or []
        if not files:
            raise JobError("The job has no files")
        for fmt in opts.formats:
            if not self.image_caps.formats.get(fmt):
                raise JobError(f"Creating {fmt} images is not available")
        (self.job_dir(job_id) / "files").mkdir(parents=True, exist_ok=True)
        self.store.update_job(job_id, status="processing", progress=0)
        log.info("Job %s: optimizing %d image file(s)", job_id, len(files))

        results = []
        for index, item in enumerate(files):
            self._check_cancel(job_id)
            results.append(await self._optimize_image(job_id, index, item, opts))
            self.store.update_job(job_id, progress=round((index + 1) / len(files) * 99, 1))
        self._check_cancel(job_id)

        done = [r for r in results if "error" not in r]
        if not done:
            raise JobError(results[0]["error"])
        source_bytes = sum(r["size"] for r in done)
        served_bytes = sum(r["served_size"] for r in done)
        savings = (1 - served_bytes / source_bytes) * 100 if source_bytes else 0
        output = {"size": served_bytes, "files": results, "savings_percent": round(savings, 2),
                  "elapsed_seconds": round(time.monotonic() - started, 1)}
        update = {"progress": 100, "input": {"size": source_bytes, "files": len(files)}, "output": output}

        if any(r.get("variants") for r in done):
            self.store.update_job(job_id, status="completed", message=None, **update)
            telemetry.record_job("completed", "image", time.monotonic() - started,
                                 input_bytes=source_bytes, output_bytes=served_bytes)
            log.info("Job %s: completed, %s -> %s bytes served (-%.1f%%)", job_id, source_bytes, served_bytes, savings)
        else:
            message = (f"No file got at least {opts.min_savings_percent:g}% smaller; keeping the originals.")
            self.store.update_job(job_id, status="skipped", message=message, **update)
            telemetry.record_job("skipped", "image", time.monotonic() - started, input_bytes=source_bytes)
            log.info("Job %s: skipped (%s)", job_id, message)

    async def _optimize_image(self, job_id: str, index: int, item: dict, opts: ImageOptions) -> dict:
        """Optimize one file. Problems with it are recorded on its entry, not raised, so its siblings still run."""
        entry: dict = {"name": item["name"]}
        src = self.image_output_path(job_id, index, "source")
        keep = 1 - opts.min_savings_percent / 100
        try:
            await self._download(job_id, item["url"], src, self.settings.max_image_bytes)
            info = await asyncio.to_thread(images.inspect, src, self.settings.max_image_pixels)
            entry.update(size=info.size, format=info.format.lower(), width=info.width, height=info.height,
                         variants={}, served_size=info.size)
            if info.animated:
                entry["message"] = "Animated images are left as they are."
                return entry

            # The PNG goes first, so the WebP version starts from the smaller palette image.
            base, base_size = src, info.size
            if info.format == "PNG" and opts.png != "off":
                dst = self.image_output_path(job_id, index, "optimized")
                if await self._optimize_png(job_id, src, dst, opts) and dst.stat().st_size <= info.size * keep:
                    base, base_size = dst, dst.stat().st_size
                    entry["variants"]["optimized"] = {"size": base_size}
                else:
                    dst.unlink(missing_ok=True)

            for fmt in opts.formats:
                self._check_cancel(job_id)
                dst = self.image_output_path(job_id, index, fmt)
                lossless = info.format == "PNG" and opts.png_webp_lossless
                await asyncio.to_thread(
                    images.encode_webp, base, dst, quality=opts.webp_quality, lossless=lossless
                )
                if dst.stat().st_size <= base_size * keep:
                    entry["variants"][fmt] = {"size": dst.stat().st_size}
                else:
                    dst.unlink(missing_ok=True)

            entry["served_size"] = min([info.size, *(v["size"] for v in entry["variants"].values())])
        except (JobError, ImageError, httpx.HTTPError, OSError, ValueError) as exc:
            if job_id in self._cancelled:
                raise JobCancelled from exc
            entry["error"] = str(exc) or exc.__class__.__name__
            log.info("Job %s: file %d (%s) failed: %s", job_id, index, item["name"], entry["error"])
        finally:
            src.unlink(missing_ok=True)
        return entry

    async def _optimize_png(self, job_id: str, src: Path, dst: Path, opts: ImageOptions) -> bool:
        if opts.png == "lossy" and self.image_caps.pngquant:
            srgb = dst.with_suffix(".srgb")
            try:
                source = await asyncio.to_thread(images.prepare_for_quantize, src, srgb)
                if source and await images.quantize_png(
                    self.image_caps.pngquant, source, dst, opts.png_quality,
                    on_start=lambda proc: self._procs.__setitem__(job_id, proc),
                ):
                    return True
            finally:
                srgb.unlink(missing_ok=True)
            self._check_cancel(job_id)
        # Lossless keeps the colour profile as it is.
        return await asyncio.to_thread(images.optimize_png_lossless, src, dst)

    async def _download(self, job_id: str, url: str, dest: Path, limit: int | None = None) -> None:
        limit = limit or self.settings.max_input_bytes
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
