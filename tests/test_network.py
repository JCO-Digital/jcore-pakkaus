import asyncio
from dataclasses import replace
import gzip
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

import httpx

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "optimizer-service"))

from app import worker
from app.config import load_settings
from app.ffmpeg import Capabilities
from app.images import ImageCapabilities
from app.net import DestinationNotAllowed, GuardedTransport
from app.store import Store
from app.worker import JobError, JobRunner

TOKEN = "test-token-0123456789abcdef"
PUBLIC = "93.184.215.14"

# A response body that must never be read: reading it fails the test.
BOMB = gzip.compress(b"\0" * (1 << 20))


class Unread(httpx.AsyncByteStream):
    def __init__(self):
        self.read = False

    async def __aiter__(self):
        self.read = True
        yield BOMB


def resolver(table):
    async def resolve(host, port):
        if host not in table:
            raise OSError("Name or service not known")
        return table[host]
    return resolve


class GuardedTransportTests(unittest.IsolatedAsyncioTestCase):
    async def fetch(self, url, table=None, allowed=(), allow_private=False):
        self.seen = []

        def handler(request):
            self.seen.append(request)
            return httpx.Response(200, content=b"ok")

        transport = GuardedTransport(allowed, allow_private, httpx.MockTransport(handler),
                                     resolver(table or {}))
        async with httpx.AsyncClient(transport=transport) as client:
            return await client.get(url)

    async def test_connects_to_the_checked_address_under_the_original_name(self):
        response = await self.fetch("https://example.test/a.jpg", {"example.test": [PUBLIC]})
        self.assertEqual(response.status_code, 200)
        sent = self.seen[0]
        self.assertEqual((sent.url.host, sent.headers["host"]), (PUBLIC, "example.test"))
        self.assertEqual(sent.extensions["sni_hostname"], "example.test")
        # Redirects are resolved against what the client asked for, not the address.
        self.assertEqual(response.request.url.host, "example.test")

    async def test_non_public_addresses_are_refused(self):
        table = {
            "loopback.test": ["127.0.0.1"], "metadata.test": ["169.254.169.254"], "lan.test": ["10.0.0.5"],
            "mixed.test": [PUBLIC, "192.168.1.1"], "v6.test": ["::1"], "mapped.test": ["::ffff:127.0.0.1"],
            "nat64.test": ["64:ff9b::a00:1"], "cgnat.test": ["100.64.0.1"], "multicast.test": ["224.0.0.1"],
        }
        urls = [f"http://{host}/" for host in table] + [
            "http://127.0.0.1:8000/", "http://[::1]/", "http://169.254.169.254/latest/meta-data/", "http://0.0.0.0/",
        ]
        for url in urls:
            with self.subTest(url=url), self.assertRaises(DestinationNotAllowed):
                await self.fetch(url, table)
        self.assertEqual(self.seen, [])

    async def test_private_networks_can_be_allowed(self):
        response = await self.fetch("http://wordpress/", {"wordpress": ["172.18.0.3"]}, allow_private=True)
        self.assertEqual(response.status_code, 200)

    async def test_allowed_hosts_still_apply(self):
        with self.assertRaisesRegex(DestinationNotAllowed, "ALLOWED_HOSTS"):
            await self.fetch("https://other.test/", {"other.test": [PUBLIC]}, allowed=("example.test",))

    async def test_resolution_failures_are_connection_errors(self):
        with self.assertRaises(httpx.ConnectError):
            await self.fetch("https://missing.test/")


class RunnerNetworkTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.root = Path(self.folder.name)
        with patch.dict(os.environ, {"API_TOKEN": TOKEN, "DATA_DIR": str(self.root)}):
            settings = load_settings()
        self.store = Store(self.root / "jobs.sqlite3")
        self.runner = JobRunner(replace(settings, callback_retries=1), self.store, Capabilities("test"))
        self.dest = self.root / "input"
        self.requests = []
        self.unread = []

    async def asyncTearDown(self):
        await self.runner.stop()
        self.store._conn.close()
        self.folder.cleanup()

    async def serve(self, routes, table=None):
        """Route by host and path through the real guard, with every host public unless the table says otherwise."""
        def handler(request):
            key = f"{request.headers['host']}{request.url.path}"
            self.requests.append(key)
            route = routes.get(key)
            return route() if callable(route) else (route or httpx.Response(404))

        async def resolve(host, port):
            return (table or {}).get(host, [PUBLIC])

        guard = GuardedTransport((), False, httpx.MockTransport(handler), resolve)
        await self.runner._http.aclose()
        self.runner._http = httpx.AsyncClient(transport=guard, trust_env=False)

    def redirect(self, location, status=302):
        stream = Unread()
        self.unread.append(stream)
        return lambda: httpx.Response(status, headers={"Location": location, "Content-Encoding": "gzip"},
                                      stream=stream)

    async def test_redirects_are_followed_without_reading_their_bodies(self):
        await self.serve({
            "source.test/a": self.redirect("/b"),
            "source.test/b": self.redirect("https://cdn.test/c", 307),
            "cdn.test/c": lambda: httpx.Response(200, stream=httpx.ByteStream(b"video")),
        })
        await self.runner._download("job", "https://source.test/a", self.dest)
        self.assertEqual(self.dest.read_bytes(), b"video")
        self.assertEqual(self.requests, ["source.test/a", "source.test/b", "cdn.test/c"])
        self.assertFalse(any(stream.read for stream in self.unread))

    async def test_redirect_to_a_private_address_is_refused(self):
        await self.serve({"source.test/a": self.redirect("http://internal.test/admin")},
                         {"internal.test": ["10.0.0.5"]})
        with self.assertRaisesRegex(JobError, "not a public address"):
            await self.runner._download("job", "https://source.test/a", self.dest)
        self.assertEqual(self.requests, ["source.test/a"])

    async def test_redirect_loops_stop(self):
        await self.serve({"source.test/a": self.redirect("/a")})
        with self.assertRaisesRegex(JobError, "redirected too many times"):
            await self.runner._download("job", "https://source.test/a", self.dest)
        self.assertEqual(len(self.requests), worker.MAX_REDIRECTS + 1)

    async def test_connection_errors_do_not_reveal_details(self):
        await self.serve({})
        await self.runner._http.aclose()

        def refuse(request):
            raise httpx.ConnectError("[Errno 111] Connection refused")

        self.runner._http = httpx.AsyncClient(transport=httpx.MockTransport(refuse))
        with self.assertRaises(JobError) as caught:
            await self.runner._download("job", "https://source.test/a", self.dest)
        self.assertEqual(str(caught.exception), "Could not connect to the source")

    def callback_job(self):
        self.store.create_job({"id": "cb", "status": "completed", "source_url": "https://source.test/a",
                               "callback_url": "https://site.test/callback", "callback_secret": "s" * 32,
                               "options": {}})

    async def test_callback_response_bodies_are_never_read(self):
        stream = Unread()
        await self.serve({"site.test/callback": lambda: httpx.Response(
            200, headers={"Content-Encoding": "gzip"}, stream=stream)})
        self.callback_job()
        with self.assertLogs("app.worker", "INFO") as logs:
            await self.runner._send_callback("cb")
        self.assertIn("callback delivered", "\n".join(logs.output))
        self.assertFalse(stream.read)

    async def test_callback_redirects_are_not_followed_or_retried(self):
        await self.serve({"site.test/callback": self.redirect("http://internal.test/")},
                         {"internal.test": ["10.0.0.5"]})
        self.runner.settings = replace(self.runner.settings, callback_retries=3)
        self.callback_job()
        with self.assertLogs("app.worker", "WARNING") as logs:
            await self.runner._send_callback("cb")
        self.assertIn("rejected with HTTP 302", "\n".join(logs.output))
        self.assertEqual(self.requests, ["site.test/callback"])

    async def test_callback_attempts_have_a_deadline(self):
        async def stall(request):
            await asyncio.sleep(5)
            return httpx.Response(200)

        await self.runner._http.aclose()
        self.runner._http = httpx.AsyncClient(transport=httpx.MockTransport(stall))
        self.callback_job()
        with patch.object(worker, "CALLBACK_TIMEOUT", 0.05), self.assertLogs("app.worker", "WARNING") as logs:
            await asyncio.wait_for(self.runner._send_callback("cb"), 1)
        self.assertIn("giving up on callback", "\n".join(logs.output))


class SubmissionTests(unittest.TestCase):
    def setUp(self):
        from fastapi.testclient import TestClient
        with patch.dict(os.environ, {"API_TOKEN": TOKEN}):
            from app.main import app
        self.folder = tempfile.TemporaryDirectory()
        self.store = Store(Path(self.folder.name) / "jobs.sqlite3")
        app.state.store = self.store
        app.state.runner = Mock()
        app.state.caps = Capabilities("test", encoders={"libx264"})
        app.state.image_caps = ImageCapabilities(webp=True, pngquant=None)
        self.client = TestClient(app)
        self.headers = {"Authorization": f"Bearer {TOKEN}"}

    def tearDown(self):
        self.store._conn.close()
        self.folder.cleanup()

    def test_non_public_address_literals_are_rejected_up_front(self):
        for url in ("http://127.0.0.1/a.mp4", "http://169.254.169.254/latest", "http://[::1]/a.mp4"):
            with self.subTest(url=url):
                video = self.client.post("/jobs", headers=self.headers, json={"source_url": url})
                image = self.client.post("/jobs/images", headers=self.headers,
                                         json={"files": [{"name": "full", "url": "https://example.test/a.jpg"}],
                                               "callback_url": url})
                self.assertEqual((video.status_code, image.status_code), (422, 422))
        ok = self.client.post("/jobs", headers=self.headers, json={"source_url": f"https://{PUBLIC}/a.mp4"})
        self.assertEqual(ok.status_code, 201, ok.text)


if __name__ == "__main__":
    unittest.main()
