"""Admin dashboard: GitHub sign-in for members of one organization, API keys and their usage."""
from __future__ import annotations

import hmac
import html
import logging
import secrets
import time
from pathlib import Path
from typing import Any
from urllib.parse import quote, urlencode

import httpx
from fastapi import APIRouter, Depends, HTTPException, Request, status
from fastapi.responses import HTMLResponse, RedirectResponse, Response
from pydantic import BaseModel, Field

from . import __version__
from .config import Settings
from .store import ENV_KEY_ID

log = logging.getLogger(__name__)

STATIC_DIR = Path(__file__).parent / "static"
PERIODS = (7, 30, 90)
DAY = 86400
GITHUB_HEADERS = {"Accept": "application/vnd.github+json", "User-Agent": f"jcore-pakkaus-service/{__version__}"}

router = APIRouter(prefix="/admin", include_in_schema=False)


def settings_of(request: Request) -> Settings:
    return request.app.state.settings


# --------------------------------------------------------------- GitHub


async def exchange_code(settings: Settings, code: str) -> str | None:
    async with httpx.AsyncClient(timeout=10, headers=GITHUB_HEADERS) as client:
        resp = await client.post(
            "https://github.com/login/oauth/access_token",
            headers={"Accept": "application/json"},
            data={
                "client_id": settings.github_client_id,
                "client_secret": settings.github_client_secret,
                "code": code,
                "redirect_uri": redirect_uri(settings),
            },
        )
        resp.raise_for_status()
        return resp.json().get("access_token")


async def fetch_user(access_token: str) -> dict[str, Any]:
    async with httpx.AsyncClient(timeout=10, headers=GITHUB_HEADERS) as client:
        resp = await client.get("https://api.github.com/user", headers={"Authorization": f"Bearer {access_token}"})
        resp.raise_for_status()
        return resp.json()


async def is_active_member(org: str, access_token: str) -> bool:
    """404 means not a member. Pending invitations don't count."""
    async with httpx.AsyncClient(timeout=10, headers=GITHUB_HEADERS) as client:
        resp = await client.get(
            f"https://api.github.com/user/memberships/orgs/{quote(org, safe='')}",
            headers={"Authorization": f"Bearer {access_token}"},
        )
    if resp.status_code != 200:
        if resp.status_code != 404:
            log.warning("GitHub org membership check for %s returned HTTP %s", org, resp.status_code)
        return False
    return resp.json().get("state") == "active"


def redirect_uri(settings: Settings) -> str:
    return f"{settings.public_url}/admin/auth/github"


def login_allowed(settings: Settings, login: str) -> bool:
    return not settings.admin_users or login.lower() in settings.admin_users


# ------------------------------------------------------------- sessions


def current_user(request: Request) -> dict[str, Any] | None:
    user = request.session.get("user")
    # Re-checked on every request, so removing someone from ADMIN_USERS takes effect immediately.
    if not user or not login_allowed(settings_of(request), user["login"]):
        return None
    return user


def require_user(request: Request) -> dict[str, Any]:
    user = current_user(request)
    if user is None:
        raise HTTPException(status.HTTP_401_UNAUTHORIZED, "Sign in to continue")
    return user


def require_csrf(request: Request, user: dict[str, Any] = Depends(require_user)) -> dict[str, Any]:
    expected = request.session.get("csrf", "")
    if not expected or not hmac.compare_digest(request.headers.get("x-csrf-token", ""), expected):
        raise HTTPException(status.HTTP_403_FORBIDDEN, "Invalid CSRF token")
    return user


# ---------------------------------------------------------------- pages


SIGN_IN_ERRORS = {
    "org": "Only active members of the {org} GitHub organization can sign in.",
    "user": "Your GitHub account isn't on this dashboard's admin list.",
    "state": "The sign-in attempt expired. Try again.",
    "github": "Signing in with GitHub failed. Try again.",
}


@router.get("")
def dashboard(request: Request, error: str | None = None) -> Response:
    if current_user(request):
        return HTMLResponse((STATIC_DIR / "dashboard.html").read_text())
    message = SIGN_IN_ERRORS.get(error or "", "").format(org=settings_of(request).github_org)
    notice = f'<p class="notice error" role="alert">{html.escape(message)}</p>' if message else ""
    return HTMLResponse((STATIC_DIR / "login.html").read_text().replace("{{error}}", notice))


@router.get("/auth/github")
async def github_auth(request: Request, code: str | None = None, state: str | None = None) -> Response:
    settings = settings_of(request)
    if "error" in request.query_params:
        return RedirectResponse("/admin?error=github", status.HTTP_303_SEE_OTHER)

    if code is None:
        request.session["oauth_state"] = oauth_state = secrets.token_urlsafe(32)
        query = urlencode({
            "client_id": settings.github_client_id,
            "redirect_uri": redirect_uri(settings),
            # read:org lets us see private org memberships.
            "scope": "read:org",
            "state": oauth_state,
            "allow_signup": "false",
        })
        return RedirectResponse(f"https://github.com/login/oauth/authorize?{query}", status.HTTP_303_SEE_OTHER)

    expected = request.session.pop("oauth_state", None)
    if not expected or not state or not hmac.compare_digest(state, expected):
        return RedirectResponse("/admin?error=state", status.HTTP_303_SEE_OTHER)

    try:
        access_token = await exchange_code(settings, code)
        if not access_token:
            return RedirectResponse("/admin?error=github", status.HTTP_303_SEE_OTHER)
        user = await fetch_user(access_token)
        member = await is_active_member(settings.github_org, access_token)
    except (httpx.HTTPError, ValueError):
        log.exception("GitHub sign-in failed")
        return RedirectResponse("/admin?error=github", status.HTTP_303_SEE_OTHER)

    if not member:
        return RedirectResponse("/admin?error=org", status.HTTP_303_SEE_OTHER)
    if not login_allowed(settings, user["login"]):
        return RedirectResponse("/admin?error=user", status.HTTP_303_SEE_OTHER)

    request.session.clear()
    request.session["user"] = {
        "login": user["login"],
        "name": user.get("name") or user["login"],
        "avatar_url": user.get("avatar_url"),
    }
    request.session["csrf"] = secrets.token_urlsafe(32)
    request.session["signed_in_at"] = int(time.time())
    log.info("Dashboard sign-in by %s", user["login"])
    return RedirectResponse("/admin", status.HTTP_303_SEE_OTHER)


@router.post("/logout", status_code=status.HTTP_204_NO_CONTENT)
def logout(request: Request, _: dict = Depends(require_csrf)) -> Response:
    request.session.clear()
    return Response(status_code=status.HTTP_204_NO_CONTENT)


# ------------------------------------------------------------------ API


class KeyCreate(BaseModel):
    name: str = Field(min_length=1, max_length=100)


@router.get("/api/session")
def session_info(request: Request, user: dict = Depends(require_user)) -> dict:
    return {"user": user, "csrf": request.session["csrf"], "org": settings_of(request).github_org}


@router.get("/api/overview")
def overview(request: Request, days: int = 30, _: dict = Depends(require_user)) -> dict:
    if days not in PERIODS:
        raise HTTPException(status.HTTP_422_UNPROCESSABLE_CONTENT, f"days must be one of {PERIODS}")
    store = request.app.state.store
    # Periods cover whole UTC days, ending today.
    first_day = int(time.time() // DAY - (days - 1)) * DAY
    usage = store.usage_by_key(first_day)

    keys = [{**key, "kind": "key", "active": key["revoked_at"] is None} for key in store.list_api_keys()]
    env_token = bool(settings_of(request).api_token)
    if env_token or ENV_KEY_ID in usage:
        keys.append({
            "id": ENV_KEY_ID, "kind": "env", "name": "API_TOKEN environment variable", "prefix": None,
            "created_by": None, "created_at": None, "last_used_at": None, "revoked_at": None, "active": env_token,
        })
    for key in keys:
        key["usage"] = usage.get(key["id"], {})

    by_key: dict[str, list[dict[str, Any]]] = {}
    for key in keys:
        rows = {row["day"]: row for row in store.usage_by_day(first_day, key["id"])}
        by_key[key["id"]] = [rows.get(day, {"day": day}) for day in day_labels(first_day, days)]

    return {"days": days, "first_day": first_day, "keys": keys, "daily": by_key}


def day_labels(first_day: int, days: int) -> list[str]:
    return [time.strftime("%Y-%m-%d", time.gmtime(first_day + n * DAY)) for n in range(days)]


@router.post("/api/keys", status_code=status.HTTP_201_CREATED)
def create_key(request: Request, body: KeyCreate, user: dict = Depends(require_csrf)) -> dict:
    record, key = request.app.state.store.create_api_key(body.name.strip() or "Untitled", user["login"])
    log.info("API key %s (%s) created by %s", record["id"], record["name"], user["login"])
    return {"key": record, "secret": key}


@router.post("/api/keys/{key_id}/revoke")
def revoke_key(request: Request, key_id: str, user: dict = Depends(require_csrf)) -> dict:
    store = request.app.state.store
    if store.get_api_key(key_id) is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "API key not found")
    if store.revoke_api_key(key_id):
        log.info("API key %s revoked by %s", key_id, user["login"])
    return store.get_api_key(key_id)


# ------------------------------------------------------------ hardening


class DashboardHeaders:
    """Security headers for everything under /admin."""

    HEADERS = [
        (b"content-security-policy",
         b"default-src 'self'; img-src 'self' https://avatars.githubusercontent.com; "
         b"frame-ancestors 'none'; form-action 'self' https://github.com; base-uri 'none'"),
        (b"x-frame-options", b"DENY"),
        (b"x-content-type-options", b"nosniff"),
        (b"referrer-policy", b"no-referrer"),
        (b"cache-control", b"no-store"),
    ]

    def __init__(self, app) -> None:
        self.app = app

    async def __call__(self, scope, receive, send) -> None:
        if scope["type"] != "http" or not (scope["path"] == "/admin" or scope["path"].startswith("/admin/")):
            await self.app(scope, receive, send)
            return

        async def send_with_headers(message) -> None:
            if message["type"] == "http.response.start":
                message["headers"] = [*message.get("headers", []), *self.HEADERS]
            await send(message)

        await self.app(scope, receive, send_with_headers)
