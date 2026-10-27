from __future__ import annotations

import hmac
import logging
import uuid
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, HTTPException, Request, Response, status
from fastapi.responses import FileResponse
from fastapi.security import HTTPBearer
from fastapi.staticfiles import StaticFiles
from starlette.middleware.sessions import SessionMiddleware

from . import __version__, admin, telemetry
from .config import host_allowed, load_settings
from .net import is_public, literal_address
from .ffmpeg import detect_capabilities
from .images import MIME_TYPES, detect_image_capabilities
from .models import ImageJobCreate, JobCreate
from .security import RequestGuard
from .store import ENV_KEY_ID, Store, public_job
from .worker import JobRunner

settings = load_settings()
logging.basicConfig(level=settings.log_level, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("jcore_pakkaus")


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings.data_dir.mkdir(parents=True, exist_ok=True)
    store = Store(settings.data_dir / "jobs.sqlite3")
    caps = await detect_capabilities()
    image_caps = detect_image_capabilities()
    log.info("Using %s (h264=%s, h265=%s, hdr tonemapping=%s; webp=%s, pngquant=%s)",
             caps.version, caps.codecs["h264"], caps.codecs["h265"], caps.can_tonemap,
             image_caps.webp, bool(image_caps.pngquant))
    runner = JobRunner(settings, store, caps, image_caps)
    await runner.start()
    telemetry.start(runner)
    app.state.store, app.state.runner, app.state.caps = store, runner, caps
    app.state.image_caps = image_caps
    yield
    await runner.stop()


def authenticate(token: str) -> str | None:
    """The API key id for a bearer token: a dashboard-created key, or the API_TOKEN variable."""
    if settings.api_token and hmac.compare_digest(token.encode(), settings.api_token.encode()):
        return ENV_KEY_ID
    return app.state.store.authenticate_api_key(token)


app = FastAPI(title="JCORE Pakkaus Service", version=__version__, lifespan=lifespan, telemetry=telemetry.CONFIG)
app.state.settings = settings
if settings.dashboard_enabled:
    app.include_router(admin.router)
    app.mount("/admin/static", StaticFiles(directory=admin.STATIC_DIR), name="admin-static")
    app.add_middleware(
        SessionMiddleware, secret_key=settings.session_secret, session_cookie="pakkaus_session",
        max_age=12 * 3600, path="/admin", same_site="lax", https_only=settings.public_url.startswith("https://"),
    )
    app.add_middleware(admin.DashboardHeaders)
# Outermost, so requests are authenticated and bounded before anything else runs.
app.add_middleware(RequestGuard, authenticate=authenticate, max_request_bytes=settings.max_request_bytes)
# Only documents the scheme in /docs; RequestGuard does the checking.
bearer = HTTPBearer(auto_error=False)


def require_token(request: Request, _=Depends(bearer)) -> str:
    key_id = getattr(request.state, "api_key_id", None)
    if key_id is None:
        raise HTTPException(
            status.HTTP_401_UNAUTHORIZED, "Invalid or missing API token", headers={"WWW-Authenticate": "Bearer"}
        )
    return key_id


def get_job_or_404(request: Request, job_id: str, key_id: str = Depends(require_token)) -> dict:
    job = request.app.state.store.get_job(job_id)
    # Each key only sees its own jobs.
    if job is None or job["api_key_id"] != key_id:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "Job not found")
    return job


@app.get("/health")
def health(request: Request) -> dict:
    """Unauthenticated liveness probe (used by Docker/Coolify)."""
    return {"status": "ok", "version": __version__}


@app.get("/info", dependencies=[Depends(require_token)])
def info(request: Request) -> dict:
    """Authenticated capability check; the WordPress plugin uses this to test the connection."""
    caps, image_caps, runner = request.app.state.caps, request.app.state.image_caps, request.app.state.runner
    return {
        "status": "ok",
        "version": __version__,
        "ffmpeg": caps.version,
        "codecs": caps.codecs,
        "hdr_tonemapping": caps.can_tonemap,
        "workers": settings.workers,
        "queue": {"waiting": runner.queue.qsize(), "active": len(runner.active) - len(runner.image_active)},
        "images": {
            "formats": image_caps.formats,
            "png_lossy": bool(image_caps.pngquant),
            "workers": settings.image_workers,
            "queue": {"waiting": runner.image_queue.qsize(), "active": len(runner.image_active)},
        },
        "jobs": request.app.state.store.job_counts(),
    }


def check_destinations(*urls) -> None:
    """Turn away URLs the worker would refuse anyway. Host names are only resolved when they're fetched."""
    for url in filter(None, urls):
        address = literal_address(url.host)
        if not host_allowed(url.host, settings.allowed_hosts) or (
            address is not None and not settings.allow_private_networks and not is_public(address)
        ):
            raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, f"Host {url.host!r} is not allowed")


def check_capacity(request: Request, key_id: str, kind: str) -> None:
    """One key's backlog is capped, so a single site can't fill the queues for everyone."""
    limit = settings.max_image_jobs_per_key if kind == "image" else settings.max_jobs_per_key
    if limit and request.app.state.store.unfinished_jobs(key_id, kind) >= limit:
        raise HTTPException(
            status.HTTP_429_TOO_MANY_REQUESTS,
            f"This API key already has {limit} unfinished {kind} jobs; try again when some have finished",
            headers={"Retry-After": "60"},
        )


@app.post("/jobs", status_code=status.HTTP_201_CREATED)
def create_job(request: Request, body: JobCreate, key_id: str = Depends(require_token)) -> dict:
    caps = request.app.state.caps
    if not caps.codecs.get(body.options.codec):
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, f"Codec {body.options.codec} is not available")
    check_destinations(body.source_url, body.callback_url)
    check_capacity(request, key_id, "video")

    job = request.app.state.store.create_job({
        "id": uuid.uuid4().hex,
        "status": "queued",
        "progress": 0,
        "source_url": str(body.source_url),
        "callback_url": str(body.callback_url) if body.callback_url else None,
        "callback_secret": body.callback_secret,
        "options": body.options.model_dump(),
        "metadata": body.metadata,
        "api_key_id": key_id,
    })
    request.app.state.runner.enqueue(job["id"])
    return public_job(job)


@app.post("/jobs/images", status_code=status.HTTP_201_CREATED)
def create_image_job(request: Request, body: ImageJobCreate, key_id: str = Depends(require_token)) -> dict:
    """Optimize an image and its resized copies: WebP versions next to each file, and smaller PNG files."""
    image_caps = request.app.state.image_caps
    for fmt in body.options.formats:
        if not image_caps.formats.get(fmt):
            raise HTTPException(status.HTTP_422_UNPROCESSABLE_ENTITY, f"Creating {fmt} images is not available")
    check_destinations(body.callback_url, *(item.url for item in body.files))
    check_capacity(request, key_id, "image")

    job = request.app.state.store.create_job({
        "id": uuid.uuid4().hex,
        "kind": "image",
        "status": "queued",
        "progress": 0,
        "source_url": str(body.files[0].url),
        "files": [{"name": item.name, "url": str(item.url)} for item in body.files],
        "callback_url": str(body.callback_url) if body.callback_url else None,
        "callback_secret": body.callback_secret,
        "options": body.options.model_dump(),
        "metadata": body.metadata,
        "api_key_id": key_id,
    })
    request.app.state.runner.enqueue(job["id"], "image")
    return public_job(job)


@app.get("/jobs/{job_id}")
def get_job(job: dict = Depends(get_job_or_404)) -> dict:
    return public_job(job)


@app.get("/jobs/{job_id}/output")
def get_output(request: Request, job_id: str, job: dict = Depends(get_job_or_404)) -> FileResponse:
    path = request.app.state.runner.output_path(job_id)
    if job.get("kind", "video") != "video":
        raise HTTPException(status.HTTP_404_NOT_FOUND, "Image jobs have one output per file under /files")
    if job["status"] != "completed" or not path.is_file():
        raise HTTPException(status.HTTP_409_CONFLICT, f"Job is {job['status']}; no output available")
    return FileResponse(path, media_type="video/mp4", filename=f"{job_id}.mp4")


@app.get("/jobs/{job_id}/files/{index}/{variant}")
def get_image_output(
    request: Request, job_id: str, index: int, variant: str, job: dict = Depends(get_job_or_404)
) -> FileResponse:
    """One result of an image job: `optimized` (the file in its own format, smaller) or a format, e.g. `webp`."""
    if job.get("kind") != "image":
        raise HTTPException(status.HTTP_404_NOT_FOUND, "Not an image job")
    if job["status"] != "completed":
        raise HTTPException(status.HTTP_409_CONFLICT, f"Job is {job['status']}; no output available")
    files = (job["output"] or {}).get("files") or []
    entry = files[index] if 0 <= index < len(files) else {}
    if variant not in (entry.get("variants") or {}):
        raise HTTPException(status.HTTP_404_NOT_FOUND, "No such output")
    path = request.app.state.runner.image_output_path(job_id, index, variant)
    if not path.is_file():
        raise HTTPException(status.HTTP_404_NOT_FOUND, "No such output")
    media_type = MIME_TYPES.get(entry["format"].upper()) if variant == "optimized" else MIME_TYPES.get(variant)
    return FileResponse(path, media_type=media_type or "application/octet-stream")


@app.delete("/jobs/{job_id}", status_code=status.HTTP_204_NO_CONTENT, dependencies=[Depends(get_job_or_404)])
def delete_job(request: Request, job_id: str) -> Response:
    request.app.state.runner.remove(job_id)
    return Response(status_code=status.HTTP_204_NO_CONTENT)
