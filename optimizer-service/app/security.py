from __future__ import annotations

from typing import Callable

from starlette.datastructures import Headers
from starlette.responses import JSONResponse
from starlette.types import ASGIApp, Receive, Scope, Send


class RequestGuard:
    """Authenticate before reading JSON and bound actual bytes, including chunked bodies."""

    PUBLIC_PATHS = frozenset({"/health", "/docs", "/docs/oauth2-redirect", "/redoc", "/openapi.json"})
    # The dashboard authenticates with its session cookie instead; its bodies are still bounded.
    SESSION_PREFIX = "/admin"

    def __init__(
        self, app: ASGIApp, authenticate: Callable[[str], str | None], max_request_bytes: int
    ) -> None:
        """`authenticate` maps a bearer token to the id of its API key, or None if it is invalid."""
        self.app = app
        self.authenticate = authenticate
        self.max_request_bytes = max_request_bytes

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http" or scope["path"] in self.PUBLIC_PATHS:
            await self.app(scope, receive, send)
            return

        headers = Headers(scope=scope)
        path = scope["path"]
        if path != self.SESSION_PREFIX and not path.startswith(self.SESSION_PREFIX + "/"):
            scheme, _, token = headers.get("authorization", "").partition(" ")
            key_id = self.authenticate(token) if scheme.lower() == "bearer" and token else None
            if key_id is None:
                await JSONResponse(
                    {"detail": "Invalid or missing API token"}, status_code=401,
                    headers={"WWW-Authenticate": "Bearer"},
                )(scope, receive, send)
                return
            scope.setdefault("state", {})["api_key_id"] = key_id

        length = headers.get("content-length")
        if length is not None:
            if not length.isascii() or not length.isdigit():
                await JSONResponse({"detail": "Invalid Content-Length"}, status_code=400)(scope, receive, send)
                return
            # Compare as decimal strings to avoid converting arbitrarily large integers.
            length = length.lstrip("0") or "0"
            limit = str(self.max_request_bytes)
            if len(length) > len(limit) or (len(length) == len(limit) and length > limit):
                await self.reject_large_body(scope, receive, send)
                return

        chunks: list[bytes] = []
        size = 0
        while True:
            message = await receive()
            if message["type"] == "http.disconnect":
                return
            chunk = message.get("body", b"")
            size += len(chunk)
            if size > self.max_request_bytes:
                await self.reject_large_body(scope, receive, send)
                return
            if chunk:
                chunks.append(chunk)
            if not message.get("more_body", False):
                break

        body = b"".join(chunks)
        delivered = False

        async def bounded_receive():
            nonlocal delivered
            if not delivered:
                delivered = True
                return {"type": "http.request", "body": body, "more_body": False}
            return await receive()

        await self.app(scope, bounded_receive, send)

    async def reject_large_body(self, scope: Scope, receive: Receive, send: Send) -> None:
        await JSONResponse({"detail": "Request body is too large"}, status_code=413)(scope, receive, send)
