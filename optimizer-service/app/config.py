from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


def _int(name: str, default: int) -> int:
    value = os.getenv(name, "").strip()
    return int(value) if value else default


def _list(name: str) -> tuple[str, ...]:
    return tuple(item.strip() for item in os.getenv(name, "").split(",") if item.strip())


@dataclass(frozen=True)
class Settings:
    api_token: str
    data_dir: Path
    workers: int
    ffmpeg_threads: int
    max_input_bytes: int
    max_request_bytes: int
    job_timeout: int
    job_ttl_seconds: int
    download_timeout: int
    allowed_hosts: tuple[str, ...]
    callback_retries: int
    log_level: str


def load_settings() -> Settings:
    api_token = os.getenv("API_TOKEN", "").strip()
    if len(api_token) < 24:
        raise RuntimeError(
            "API_TOKEN must be set to a random secret of at least 24 characters "
            "(e.g. `openssl rand -hex 32`)."
        )

    return Settings(
        api_token=api_token,
        data_dir=Path(os.getenv("DATA_DIR", "/data")),
        workers=max(1, _int("WORKERS", 1)),
        ffmpeg_threads=max(0, _int("FFMPEG_THREADS", 0)),
        max_input_bytes=_int("MAX_INPUT_MB", 4096) * 1024 * 1024,
        max_request_bytes=max(1, _int("MAX_REQUEST_KB", 64)) * 1024,
        job_timeout=_int("JOB_TIMEOUT_SECONDS", 4 * 3600),
        job_ttl_seconds=_int("JOB_TTL_HOURS", 24) * 3600,
        download_timeout=_int("DOWNLOAD_TIMEOUT_SECONDS", 1800),
        allowed_hosts=tuple(h.lower() for h in _list("ALLOWED_HOSTS")),
        callback_retries=max(1, _int("CALLBACK_RETRIES", 5)),
        log_level=os.getenv("LOG_LEVEL", "info").upper(),
    )


def host_allowed(host: str | None, allowed: tuple[str, ...]) -> bool:
    """Empty allowlist means every host is allowed. Supports `*.example.com` wildcards."""
    if not allowed:
        return True
    if not host:
        return False
    host = host.lower().rstrip(".")
    for pattern in allowed:
        if pattern.startswith("*."):
            if host == pattern[2:] or host.endswith(pattern[1:]):
                return True
        elif host == pattern:
            return True
    return False
