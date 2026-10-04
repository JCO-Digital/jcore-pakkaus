import asyncio
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "optimizer-service"))

from app.ffmpeg import Capabilities, FFmpegError, build_command, probe, transcode
from app.models import JobOptions
from app.store import Store

TOKEN = "test-token-0123456789abcdef"


class RequestSecurityTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        with patch.dict(os.environ, {"API_TOKEN": TOKEN}):
            from app.main import app, settings
        self.app = app
        self.limit = settings.max_request_bytes
        self.folder = tempfile.TemporaryDirectory()
        self.store = Store(Path(self.folder.name) / "jobs.sqlite3")
        self.enqueue = Mock()
        app.state.store = self.store
        app.state.runner = SimpleNamespace(enqueue=self.enqueue)
        app.state.caps = Capabilities("test", encoders={"libx264"})

    async def asyncTearDown(self):
        self.store._conn.close()
        self.folder.cleanup()

    async def request(self, chunks=(), headers=(), path="/jobs", method="POST", forbid_read=False):
        scope = {
            "type": "http", "asgi": {"version": "3.0", "spec_version": "2.4"},
            "http_version": "1.1", "method": method, "scheme": "http", "path": path,
            "raw_path": path.encode(), "query_string": b"", "root_path": "",
            "server": ("testserver", 80), "client": ("127.0.0.1", 1),
            "headers": [(b"content-type", b"application/json"), *headers],
        }
        remaining = list(chunks or [b""])
        received = []
        responses = []

        async def receive():
            if forbid_read:
                raise AssertionError("Request body must not be read")
            if not remaining:
                return {"type": "http.disconnect"}
            chunk = remaining.pop(0)
            received.append(chunk)
            return {"type": "http.request", "body": chunk, "more_body": bool(remaining)}

        async def send(message):
            responses.append(message)

        await self.app(scope, receive, send)
        status = next(m["status"] for m in responses if m["type"] == "http.response.start")
        body = b"".join(m.get("body", b"") for m in responses if m["type"] == "http.response.body")
        return status, body, received

    @property
    def authorized(self):
        return [(b"authorization", f"Bearer {TOKEN}".encode())]

    async def test_missing_or_invalid_token_rejected_without_reading_body(self):
        for header in ([], [(b"authorization", b"Bearer incorrect")], [(b"authorization", b"Basic x")]):
            with self.subTest(header=header):
                status, _, received = await self.request(headers=header, forbid_read=True)
                self.assertEqual(status, 401)
                self.assertEqual(received, [])
        self.assertEqual(self.store.job_counts(), {})

    async def test_oversized_content_length_rejected_without_reading_body(self):
        status, _, _ = await self.request(
            headers=[*self.authorized, (b"content-length", str(self.limit + 1).encode())],
            forbid_read=True,
        )
        self.assertEqual(status, 413)

    async def test_chunked_and_understated_bodies_cannot_bypass_limit(self):
        for length in ([], [(b"content-length", b"1")]):
            with self.subTest(length=length):
                status, _, received = await self.request(
                    chunks=[b"x" * self.limit, b"x", b"unread"], headers=[*self.authorized, *length],
                )
                self.assertEqual(status, 413)
                self.assertEqual(len(received), 2)
        self.assertEqual(self.store.job_counts(), {})

    async def test_invalid_content_length_is_rejected(self):
        for length in (b"-1", b"not-a-number"):
            status, _, _ = await self.request(
                headers=[*self.authorized, (b"content-length", length)], forbid_read=True,
            )
            self.assertEqual(status, 400)

    async def test_valid_job_at_exact_body_limit_is_queued(self):
        payload = {"source_url": "https://example.test/video.mp4", "metadata": {"padding": ""}}
        payload["metadata"]["padding"] = "x" * (self.limit - len(json.dumps(payload).encode()))
        body = json.dumps(payload).encode()
        self.assertEqual(len(body), self.limit)
        status, result, _ = await self.request(chunks=[body[:100], body[100:]], headers=self.authorized)
        self.assertEqual(status, 201)
        self.assertEqual(json.loads(result)["metadata"], payload["metadata"])
        self.enqueue.assert_called_once()

    async def test_health_remains_public(self):
        status, body, _ = await self.request(path="/health", method="GET", forbid_read=True)
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(body)["status"], "ok")

    async def test_output_range_requests_are_safe_and_downloads_work(self):
        output = Path(self.folder.name) / "output.mp4"
        output.write_bytes(b"0123456789")
        job = self.store.create_job({"id": "completed", "status": "completed",
                                     "source_url": "https://example.test/video.mp4", "options": {}})
        self.app.state.runner.output_path = lambda job_id: output

        # Assert the old quadratic parser implementation is no longer installed.
        import starlette
        self.assertGreaterEqual(tuple(map(int, starlette.__version__.split(".")[:3])), (0, 49, 1))
        malformed = b"bytes=" + b"0" * 8000 + b"a-"
        status, _, _ = await self.request(
            path=f"/jobs/{job['id']}/output", method="GET",
            headers=[*self.authorized, (b"range", malformed)],
        )
        self.assertEqual(status, 400)
        status, body, _ = await self.request(
            path=f"/jobs/{job['id']}/output", method="GET",
            headers=[*self.authorized, (b"range", b"bytes=0-3")],
        )
        self.assertEqual((status, body), (206, b"0123"))
        status, body, _ = await self.request(
            path=f"/jobs/{job['id']}/output", method="GET", headers=self.authorized,
        )
        self.assertEqual((status, body), (200, b"0123456789"))


@unittest.skipUnless(shutil.which("ffmpeg") and shutil.which("ffprobe"), "FFmpeg tools are required")
class MediaSecurityTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.root = Path(self.folder.name)
        self.video = self.root / "private.mp4"
        subprocess.run(
            ["ffmpeg", "-v", "error", "-f", "lavfi", "-i", "color=c=red:s=32x32:d=0.2",
             "-c:v", "libx264", "-pix_fmt", "yuv420p", str(self.video)],
            check=True, capture_output=True, timeout=15,
        )
        self.caps = Capabilities("test", encoders={"libx264"})

    async def asyncTearDown(self):
        self.folder.cleanup()

    async def test_reference_manifests_are_rejected_by_probe_and_transcode(self):
        manifests = {
            "dash": f'''<?xml version="1.0"?>
<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static" mediaPresentationDuration="PT1S" minBufferTime="PT1S" profiles="urn:mpeg:dash:profile:isoff-on-demand:2011">
<Period><AdaptationSet mimeType="video/mp4"><Representation id="1" bandwidth="1000" codecs="avc1.42c01e" width="32" height="32">
<BaseURL>file:{self.video}</BaseURL><SegmentBase><Initialization range="0-100000"/></SegmentBase>
</Representation></AdaptationSet></Period></MPD>''',
            "hls": f"#EXTM3U\n#EXT-X-TARGETDURATION:1\n#EXTINF:1,\nfile:{self.video}\n#EXT-X-ENDLIST\n",
            "concat": f"ffconcat version 1.0\nfile '{self.video}'\n",
        }
        info = await probe(self.video)
        for name, manifest in manifests.items():
            with self.subTest(format=name):
                # Newer FFmpeg also refuses extensionless HLS during detection;
                # use its recognized extension to exercise the demuxer restriction.
                src = self.root / ("input.m3u8" if name == "hls" else "input")
                src.write_text(manifest)
                with self.assertRaisesRegex(FFmpegError, "not on whitelist"):
                    await probe(src)
                output = self.root / f"{name}.mp4"
                cmd = build_command(src, output, info, JobOptions(), self.caps, 1)
                with self.assertRaisesRegex(FFmpegError, "not on whitelist"):
                    await transcode(cmd, info.duration, lambda fraction: None, timeout=10)
                self.assertFalse(output.exists())

    async def test_self_contained_containers_still_probe_and_transcode(self):
        for extension, codec in (("mp4", "libx264"), ("mov", "libx264"), ("webm", "libvpx"),
                                 ("mkv", "libx264"), ("avi", "mpeg4")):
            with self.subTest(format=extension):
                src = self.root / f"source.{extension}"
                subprocess.run(
                    ["ffmpeg", "-v", "error", "-i", str(self.video), "-c:v", codec, str(src)],
                    check=True, capture_output=True, timeout=15,
                )
                info = await probe(src)
                self.assertEqual((info.width, info.height), (32, 32))
                dst = self.root / f"{extension}-output.mp4"
                cmd = build_command(src, dst, info, JobOptions(), self.caps, 1)
                await transcode(cmd, info.duration, lambda fraction: None, timeout=15)
                out = await probe(dst)
                self.assertEqual(out.video_codec, "h264")
                self.assertGreater(out.size, 0)


if __name__ == "__main__":
    unittest.main()
