import asyncio
from dataclasses import replace
import os
from pathlib import Path
import sys
import tempfile
import time
import unittest
from unittest.mock import Mock, patch

import httpx

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "optimizer-service"))

from app.config import load_settings
from app.ffmpeg import Capabilities
from app.images import ImageCapabilities
from app.models import ImageOptions
from app.store import Store
from app.worker import FairQueue, JobRunner

TOKEN = "test-token-0123456789abcdef"


class FairQueueTests(unittest.IsolatedAsyncioTestCase):
    async def test_keys_take_turns(self):
        queue = FairQueue()
        for job_id, key in (("a1", "A"), ("a2", "A"), ("a3", "A"), ("b1", "B"), ("c1", "C"), ("b2", "B")):
            queue.put_nowait(job_id, key)
        self.assertEqual(queue.qsize(), 6)
        order = [await queue.get() for _ in range(6)]
        self.assertEqual(order, ["a1", "b1", "c1", "a2", "b2", "a3"])
        self.assertEqual(queue.qsize(), 0)

    async def test_get_waits_for_a_job(self):
        queue = FairQueue()
        waiting = asyncio.create_task(queue.get())
        await asyncio.sleep(0)
        self.assertFalse(waiting.done())
        queue.put_nowait("x", "A")
        self.assertEqual(await asyncio.wait_for(waiting, 1), "x")


class RunnerFairnessTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.root = Path(self.folder.name)
        with patch.dict(os.environ, {"API_TOKEN": TOKEN, "DATA_DIR": str(self.root)}):
            settings = load_settings()
        self.store = Store(self.root / "jobs.sqlite3")
        caps = ImageCapabilities(webp=True, pngquant=None)
        self.runner = JobRunner(settings, self.store, Capabilities("test"), caps)
        self.runner._spawn = lambda coro: coro.close()  # No callbacks.

    async def asyncTearDown(self):
        await self.runner.stop()
        self.store._conn.close()
        self.folder.cleanup()

    async def test_enqueue_uses_the_jobs_api_key(self):
        for job_id, key in (("a1", "A"), ("a2", "A"), ("b1", "B")):
            self.store.create_job({"id": job_id, "kind": "image", "status": "queued", "api_key_id": key,
                                   "source_url": "https://example.test/a.jpg", "options": {}})
            self.runner.enqueue(job_id, "image")
        self.assertEqual([await self.runner.image_queue.get() for _ in range(3)], ["a1", "b1", "a2"])

    async def serve_slowly(self, delay):
        async def handler(request):
            await asyncio.sleep(delay)
            return httpx.Response(404)

        await self.runner._http.aclose()
        self.runner._http = httpx.AsyncClient(transport=httpx.MockTransport(handler))

    def image_job(self, count):
        files = [{"name": f"f{n}", "url": f"https://example.test/{n}.jpg"} for n in range(count)]
        self.store.create_job({"id": "img", "kind": "image", "status": "queued", "source_url": files[0]["url"],
                               "files": files, "options": ImageOptions().model_dump()})

    async def test_image_jobs_have_an_overall_deadline(self):
        self.runner.settings = replace(self.runner.settings, image_job_timeout=0.2, image_download_timeout=5)
        await self.serve_slowly(0.15)
        self.image_job(10)
        started = time.monotonic()
        await self.runner._process("img")
        job = self.store.get_job("img")
        self.assertEqual(job["status"], "failed")
        self.assertIn("took longer than", job["message"])
        self.assertLess(time.monotonic() - started, 1)

    async def test_image_downloads_have_their_own_short_timeout(self):
        self.runner.settings = replace(self.runner.settings, image_download_timeout=0.05)
        await self.serve_slowly(5)
        self.image_job(2)
        started = time.monotonic()
        await self.runner._process("img")
        job = self.store.get_job("img")
        self.assertEqual((job["status"], job["message"]), ("failed", "Downloading the source timed out"))
        self.assertLess(time.monotonic() - started, 1)


class CapacityTests(unittest.TestCase):
    def setUp(self):
        from fastapi.testclient import TestClient
        with patch.dict(os.environ, {"API_TOKEN": TOKEN}):
            import app.main as main
        self.folder = tempfile.TemporaryDirectory()
        self.store = Store(Path(self.folder.name) / "jobs.sqlite3")
        main.app.state.store = self.store
        main.app.state.runner = Mock()
        main.app.state.caps = Capabilities("test", encoders={"libx264"})
        main.app.state.image_caps = ImageCapabilities(webp=True, pngquant=None)
        limits = replace(main.settings, max_jobs_per_key=2, max_image_jobs_per_key=1)
        self.patch = patch.object(main, "settings", limits)
        self.patch.start()
        self.client = TestClient(main.app)
        self.headers = {"Authorization": f"Bearer {TOKEN}"}

    def tearDown(self):
        self.patch.stop()
        self.store._conn.close()
        self.folder.cleanup()

    def video(self):
        return self.client.post("/jobs", headers=self.headers, json={"source_url": "https://example.test/a.mp4"})

    def image(self):
        return self.client.post("/jobs/images", headers=self.headers,
                                json={"files": [{"name": "full", "url": "https://example.test/a.jpg"}]})

    def test_unfinished_jobs_per_key_are_capped_per_kind(self):
        first, second = self.video(), self.video()
        self.assertEqual((first.status_code, second.status_code), (201, 201))
        refused = self.video()
        self.assertEqual(refused.status_code, 429)
        self.assertEqual(refused.headers["retry-after"], "60")
        self.assertIn("unfinished video jobs", refused.json()["detail"])

        # Images have their own limit.
        self.assertEqual((self.image().status_code, self.image().status_code), (201, 429))

        # Finished jobs make room.
        self.store.update_job(first.json()["id"], status="completed")
        self.assertEqual(self.video().status_code, 201)


if __name__ == "__main__":
    unittest.main()
