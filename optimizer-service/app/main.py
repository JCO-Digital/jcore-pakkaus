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
from .ffmpeg import detect_capabilities
from .models import JobCreate
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
    log.info("Using %s (h264=%s, h265=%s, hdr tonemapping=%s)",
             caps.version, caps.codecs["h264"], caps.codecs["h265"], caps.can_tonemap)
    runner = JobRunner(settings, store, caps)
    await runner.start()
    telemetry.start(runner)
    app.state.store, app.state.runner, app.state.caps = store, runner, caps
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


@app.post("/jobs", status_code=status.HTTP_201_CREATED)
def create_job(request: Request, body: JobCreate, key_id: str = Depends(require_token)) -> dict:
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
        "api_key_id": key_id,
    })
    request.app.state.runner.enqueue(job["id"])
    return public_job(job)


@app.get("/jobs/{job_id}")
def get_job(job: dict = Depends(get_job_or_404)) -> dict:
    return public_job(job)


@app.get("/jobs/{job_id}/output")
def get_output(request: Request, job_id: str, job: dict = Depends(get_job_or_404)) -> FileResponse:
    path = request.app.state.runner.output_path(job_id)
    if job["status"] != "completed" or not path.is_file():
        raise HTTPException(status.HTTP_409_CONFLICT, f"Job is {job['status']}; no output available")
    return FileResponse(path, media_type="video/mp4", filename=f"{job_id}.mp4")


@app.delete("/jobs/{job_id}", status_code=status.HTTP_204_NO_CONTENT, dependencies=[Depends(get_job_or_404)])
def delete_job(request: Request, job_id: str) -> Response:
    request.app.state.runner.remove(job_id)
    return Response(status_code=status.HTTP_204_NO_CONTENT)
