import asyncio
from dataclasses import replace
from pathlib import Path
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

import httpx
from pydantic import ValidationError

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "optimizer-service"))

from app.config import load_settings
from app.ffmpeg import Capabilities
from app.models import JobOptions
from app.store import ACTIVE_STATUSES, TERMINAL_STATUSES, Store
from app.worker import JobCancelled, JobError, JobRunner


class StoreTests(unittest.TestCase):
    def test_expiration_only_removes_finished_jobs(self):
        with tempfile.TemporaryDirectory() as folder:
            store = Store(Path(folder) / "jobs.sqlite3")
            try:
                for status in (*ACTIVE_STATUSES, *TERMINAL_STATUSES):
                    store.create_job({"id": status, "status": status,
                                      "source_url": "https://example.test/video.mp4", "options": {}})
                    store._query("UPDATE jobs SET updated_at = 1 WHERE id = ?", (status,))
                store.create_job({"id": "recent", "status": "completed",
                                  "source_url": "https://example.test/video.mp4", "options": {}})
                self.assertEqual(set(store.expired_job_ids(time.time() - 86400)), set(TERMINAL_STATUSES))
            finally:
                store._conn.close()


class OptionsTests(unittest.TestCase):
    def test_h264_rejects_lossless_crf(self):
        with self.assertRaises(ValidationError):
            JobOptions(codec="h264", crf=0)
        self.assertEqual(JobOptions(codec="h264", crf=1).crf, 1)

    def test_h265_still_accepts_zero(self):
        self.assertEqual(JobOptions(codec="h265", crf=0).crf, 0)

    def test_api_rejects_h264_zero_before_queuing(self):
        from fastapi.testclient import TestClient
        with patch.dict("os.environ", {"API_TOKEN": "test-token-0123456789abcdef"}):
            from app.main import app
        response = TestClient(app).post(
            "/jobs", headers={"Authorization": "Bearer test-token-0123456789abcdef"},
            json={"source_url": "https://example.test/video.mp4", "options": {"crf": 0}},
        )
        self.assertEqual(response.status_code, 422)


class SlowStream(httpx.AsyncByteStream):
    def __init__(self, chunks, delay=0):
        self.chunks = chunks
        self.delay = delay
        self.closed = False

    async def __aiter__(self):
        for chunk in self.chunks:
            await asyncio.sleep(self.delay)
            yield chunk

    async def aclose(self):
        self.closed = True


class RunnerTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.root = Path(self.folder.name)
        with patch.dict("os.environ", {"API_TOKEN": "test-token-0123456789abcdef", "DATA_DIR": str(self.root)}):
            settings = load_settings()
        self.store = Store(self.root / "jobs.sqlite3")
        self.runner = JobRunner(replace(settings, download_timeout=0.04), self.store, Capabilities("test"))
        self.dest = self.root / "input"

    async def asyncTearDown(self):
        await self.runner.stop()
        self.store._conn.close()
        self.folder.cleanup()

    async def use_transport(self, handler):
        await self.runner._http.aclose()
        self.runner._http = httpx.AsyncClient(transport=httpx.MockTransport(handler))

    async def test_deadline_interrupts_small_trickling_chunks(self):
        stream = SlowStream([b"x"] * 100, delay=0.01)
        await self.use_transport(lambda request: httpx.Response(200, stream=stream))
        started = time.monotonic()
        with self.assertRaisesRegex(JobError, "timed out"):
            await self.runner._download("job", "https://example.test/video", self.dest)
        self.assertLess(time.monotonic() - started, 0.3)
        self.assertTrue(stream.closed)

    async def test_deadline_includes_waiting_for_headers(self):
        async def handler(request):
            await asyncio.sleep(1)
            return httpx.Response(200, content=b"video")
        await self.use_transport(handler)
        with self.assertRaisesRegex(JobError, "timed out"):
            await self.runner._download("job", "https://example.test/video", self.dest)
        self.assertFalse(self.dest.exists())

    async def test_small_chunks_check_cancellation(self):
        self.runner.settings = replace(self.runner.settings, download_timeout=1)
        stream = SlowStream([b"x"] * 100, delay=0.01)
        await self.use_transport(lambda request: httpx.Response(200, stream=stream))
        handle = asyncio.get_running_loop().call_later(0.015, self.runner.cancel, "job")
        try:
            with self.assertRaises(JobCancelled):
                await self.runner._download("job", "https://example.test/video", self.dest)
            self.assertTrue(stream.closed)
        finally:
            handle.cancel()

    async def test_success_preserves_bytes_and_closes_stream(self):
        stream = SlowStream([b"first", b"second"])
        await self.use_transport(lambda request: httpx.Response(200, stream=stream))
        await self.runner._download("job", "https://example.test/video", self.dest)
        self.assertEqual(self.dest.read_bytes(), b"firstsecond")
        self.assertTrue(stream.closed)

    async def test_size_limit_still_applies_to_streaming_response(self):
        self.runner.settings = replace(self.runner.settings, max_input_bytes=4)
        stream = SlowStream([b"123", b"456"])
        await self.use_transport(lambda request: httpx.Response(200, stream=stream))
        with self.assertRaisesRegex(JobError, "larger than"):
            await self.runner._download("job", "https://example.test/video", self.dest)
        self.assertTrue(stream.closed)

    async def test_old_persisted_lossless_job_fails_cleanly(self):
        self.store.create_job({"id": "old", "status": "queued", "source_url": "https://example.test/video",
                               "options": {"codec": "h264", "crf": 0}})
        await self.runner._process("old")
        self.assertEqual(self.store.get_job("old")["status"], "failed")
        self.assertIn("H.264 CRF", self.store.get_job("old")["message"])


if __name__ == "__main__":
    unittest.main()
