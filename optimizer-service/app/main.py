from __future__ import annotations

import hmac
import logging
import uuid
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, HTTPException, Request, Response, status
from fastapi.responses import FileResponse
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

from . import __version__
from .config import host_allowed, load_settings
from .ffmpeg import detect_capabilities
from .models import JobCreate
from .security import RequestGuard
from .store import Store, public_job
from .worker import JobRunner

settings = load_settings()
logging.basicConfig(level=settings.log_level, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("video_optimizer")


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings.data_dir.mkdir(parents=True, exist_ok=True)
    store = Store(settings.data_dir / "jobs.sqlite3")
    caps = await detect_capabilities()
    log.info("Using %s (h264=%s, h265=%s, hdr tonemapping=%s)",
             caps.version, caps.codecs["h264"], caps.codecs["h265"], caps.can_tonemap)
    runner = JobRunner(settings, store, caps)
    await runner.start()
    app.state.store, app.state.runner, app.state.caps = store, runner, caps
    yield
    await runner.stop()


app = FastAPI(title="Video Optimizer Service", version=__version__, lifespan=lifespan)
app.add_middleware(RequestGuard, api_token=settings.api_token, max_request_bytes=settings.max_request_bytes)
bearer = HTTPBearer(auto_error=False)


def require_token(credentials: HTTPAuthorizationCredentials | None = Depends(bearer)) -> None:
    if credentials is None or not hmac.compare_digest(
        credentials.credentials.encode(), settings.api_token.encode()
    ):
        raise HTTPException(
            status.HTTP_401_UNAUTHORIZED, "Invalid or missing API token", headers={"WWW-Authenticate": "Bearer"}
        )


def get_job_or_404(request: Request, job_id: str) -> dict:
    job = request.app.state.store.get_job(job_id)
    if job is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "Job not found")
    return job


@app.get("/health")
def health(request: Request) -> dict:
    """Unauthenticated liveness probe (used by Docker/Coolify)."""
    return {"status": "ok", "version": __version__}


@app.get("/info", dependencies=[Depends(require_token)])
def info(request: Request) -> dict:
    """Authenticated capability check; the WordPress plugin uses this to test the connection."""
    caps, runner = request.app.state.caps, request.app.state.runner
    return {
        "status": "ok",
        "version": __version__,
        "ffmpeg": caps.version,
        "codecs": caps.codecs,
        "hdr_tonemapping": caps.can_tonemap,
        "workers": settings.workers,
        "queue": {"waiting": runner.queue.qsize(), "active": len(runner.active)},
        "jobs": request.app.state.store.job_counts(),
    }


@app.post("/jobs", status_code=status.HTTP_201_CREATED, dependencies=[Depends(require_token)])
def create_job(request: Request, body: JobCreate) -> dict:
    caps = request.app.state.caps
    if not caps.codecs.get(body.options.codec):
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, f"Codec {body.options.codec} is not available")
    for url in filter(None, (body.source_url, body.callback_url)):
        if not host_allowed(url.host, settings.allowed_hosts):
            raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, f"Host {url.host!r} is not allowed")

    job = request.app.state.store.create_job({
        "id": uuid.uuid4().hex,
        "status": "queued",
        "progress": 0,
        "source_url": str(body.source_url),
        "callback_url": str(body.callback_url) if body.callback_url else None,
        "callback_secret": body.callback_secret,
        "options": body.options.model_dump(),
        "metadata": body.metadata,
    })
    request.app.state.runner.enqueue(job["id"])
    return public_job(job)


@app.get("/jobs/{job_id}", dependencies=[Depends(require_token)])
def get_job(request: Request, job_id: str) -> dict:
    return public_job(get_job_or_404(request, job_id))


@app.get("/jobs/{job_id}/output", dependencies=[Depends(require_token)])
def get_output(request: Request, job_id: str) -> FileResponse:
    job = get_job_or_404(request, job_id)
    path = request.app.state.runner.output_path(job_id)
    if job["status"] != "completed" or not path.is_file():
        raise HTTPException(status.HTTP_409_CONFLICT, f"Job is {job['status']}; no output available")
    return FileResponse(path, media_type="video/mp4", filename=f"{job_id}.mp4")


@app.delete("/jobs/{job_id}", status_code=status.HTTP_204_NO_CONTENT, dependencies=[Depends(require_token)])
def delete_job(request: Request, job_id: str) -> Response:
    get_job_or_404(request, job_id)
    request.app.state.runner.remove(job_id)
    return Response(status_code=status.HTTP_204_NO_CONTENT)
