"""Outgoing requests: only to allowed hosts, and only to public addresses.

Every download and callback goes through `GuardedTransport`. It checks the host against ALLOWED_HOSTS, resolves
it, refuses loopback, private, link-local and other non-public addresses, and then connects to the address it
checked, so a second DNS answer can't swap in another one. Redirects are not followed by the client: the worker
follows them itself, and each hop is a new request through this transport.
"""
from __future__ import annotations

import asyncio
import ipaddress
import logging
import socket
from collections.abc import Awaitable, Callable

import httpx

from .config import host_allowed

log = logging.getLogger(__name__)

Address = ipaddress.IPv4Address | ipaddress.IPv6Address
Resolver = Callable[[str, int], Awaitable[list[str]]]

NAT64 = ipaddress.ip_network("64:ff9b::/96")


class DestinationNotAllowed(Exception):
    """The request would go to a host or address the service may not contact."""


def is_public(address: Address) -> bool:
    """Whether an address is publicly routable, including IPv4 addresses carried inside IPv6 ones."""
    if isinstance(address, ipaddress.IPv6Address):
        embedded = address.ipv4_mapped or address.sixtofour
        if embedded is None and address in NAT64:
            embedded = ipaddress.IPv4Address(int(address) & 0xFFFFFFFF)
        if embedded is not None and not is_public(embedded):
            return False
    return address.is_global and not address.is_multicast


def literal_address(host: str | None) -> Address | None:
    try:
        return ipaddress.ip_address((host or "").strip("[]"))
    except ValueError:
        return None


async def resolve(host: str, port: int) -> list[str]:
    infos = await asyncio.get_running_loop().getaddrinfo(host, port, type=socket.SOCK_STREAM)
    return list(dict.fromkeys(info[4][0] for info in infos))


class GuardedTransport(httpx.AsyncBaseTransport):
    def __init__(
        self,
        allowed_hosts: tuple[str, ...],
        allow_private: bool,
        transport: httpx.AsyncBaseTransport | None = None,
        resolver: Resolver = resolve,
    ) -> None:
        self.allowed_hosts = allowed_hosts
        self.allow_private = allow_private
        # No keep-alive: connections are pooled by address, and one opened for another host name must not be reused.
        self.transport = transport or httpx.AsyncHTTPTransport(limits=httpx.Limits(max_keepalive_connections=0))
        self.resolver = resolver

    async def handle_async_request(self, request: httpx.Request) -> httpx.Response:
        url = request.url
        host = url.host
        if url.scheme not in ("http", "https"):
            raise DestinationNotAllowed(f"Only http and https URLs are allowed, not {url.scheme!r}")
        if not host_allowed(host, self.allowed_hosts):
            raise DestinationNotAllowed(f"Host {host!r} is not in ALLOWED_HOSTS")
        if self.allow_private:
            return await self.transport.handle_async_request(request)

        literal = literal_address(host)
        if literal is not None:
            addresses = [literal]
        else:
            try:
                found = await self.resolver(host, url.port or (443 if url.scheme == "https" else 80))
            except OSError as exc:
                raise httpx.ConnectError(f"Could not resolve {host!r}", request=request) from exc
            addresses = [ipaddress.ip_address(a.split("%", 1)[0]) for a in found]
        # All of them, not just the first: which one gets used is up to the resolver's ordering.
        blocked = [str(a) for a in addresses if not is_public(a)]
        if not addresses or blocked:
            log.warning("Refusing request to %s: resolves to non-public address(es) %s", host, ", ".join(blocked))
            raise DestinationNotAllowed(
                f"Host {host!r} is not a public address; set ALLOW_PRIVATE_NETWORKS=true to allow private networks"
            )

        if literal is None:
            # Connect to the checked address. The Host header still names the host, and TLS checks the
            # certificate against it through the SNI host name. A copy, as redirects are resolved against
            # the original request's URL.
            extensions = {**request.extensions, "sni_hostname": host} if url.scheme == "https" else request.extensions
            request = httpx.Request(
                request.method, url.copy_with(host=str(addresses[0])),
                headers=request.headers, stream=request.stream, extensions=extensions,
            )
        return await self.transport.handle_async_request(request)

    async def aclose(self) -> None:
        await self.transport.aclose()
