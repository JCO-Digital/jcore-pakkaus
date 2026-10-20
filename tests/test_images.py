import asyncio
from dataclasses import replace
import io
import os
from pathlib import Path
import shutil
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

import httpx
from PIL import Image, ImageCms, ImageDraw

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "optimizer-service"))

from app import images
from app.config import load_settings
from app.ffmpeg import Capabilities
from app.images import ImageCapabilities, ImageError
from app.models import ImageOptions
from app.store import Store
from app.worker import JobRunner

TOKEN = "test-token-0123456789abcdef"


def photo(size=(320, 200)) -> Image.Image:
    """A noisy gradient, so it doesn't compress to nothing."""
    im = Image.linear_gradient("L").resize(size).convert("RGB")
    noise = Image.effect_noise(size, 40).convert("RGB")
    return Image.blend(im, noise, 0.3)


def graphic(size=(320, 200), alpha=255) -> Image.Image:
    """Flat colours, the kind of PNG WordPress saves as 32-bit RGBA."""
    im = Image.new("RGBA", size, (255, 255, 255, alpha))
    draw = ImageDraw.Draw(im)
    draw.rectangle((20, 20, 150, 120), fill=(200, 30, 30, alpha))
    draw.ellipse((160, 40, 300, 180), fill=(30, 90, 200, alpha))
    return im


def encode(im: Image.Image, fmt: str, **options) -> bytes:
    buffer = io.BytesIO()
    im.save(buffer, fmt, **options)
    return buffer.getvalue()


class ImageFunctionTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.root = Path(self.folder.name)

    def tearDown(self):
        self.folder.cleanup()

    def write(self, name: str, data: bytes) -> Path:
        path = self.root / name
        path.write_bytes(data)
        return path

    def test_inspect_only_accepts_jpeg_and_png(self):
        self.assertEqual(images.inspect(self.write("a", encode(photo(), "JPEG")), 10**8).format, "JPEG")
        self.assertEqual(images.inspect(self.write("b", encode(graphic(), "PNG")), 10**8).format, "PNG")
        for name, data in (("gif", encode(graphic().convert("P"), "GIF")),
                           ("svg", b'<svg xmlns="http://www.w3.org/2000/svg"/>'),
                           ("webp", encode(photo(), "WEBP"))):
            with self.assertRaises(ImageError, msg=name):
                images.inspect(self.write(name, data), 10**8)

    def test_inspect_enforces_the_pixel_limit_from_the_header(self):
        path = self.write("big.png", encode(Image.new("L", (1000, 1000)), "PNG"))
        with self.assertRaisesRegex(ImageError, "megapixel"):
            images.inspect(path, 999_999)

    def test_webp_applies_exif_orientation_and_keeps_the_colour_profile(self):
        exif = Image.Exif()
        exif[0x0112] = 6  # Rotate 90° clockwise to display.
        icc = ImageCms.ImageCmsProfile(ImageCms.createProfile("sRGB")).tobytes()
        src = self.write("rotated.jpg", encode(photo((300, 100)), "JPEG", exif=exif, icc_profile=icc))
        dst = self.root / "rotated.webp"
        images.encode_webp(src, dst, quality=80, lossless=False)
        with Image.open(dst) as result:
            self.assertEqual(result.format, "WEBP")
            self.assertEqual(result.size, (100, 300))
            self.assertEqual(result.info.get("icc_profile"), icc)
            self.assertNotIn("exif", result.info)

    def test_webp_keeps_transparency_and_converts_cmyk(self):
        src = self.write("alpha.png", encode(graphic(alpha=128), "PNG"))
        images.encode_webp(src, self.root / "alpha.webp", quality=80, lossless=True)
        with Image.open(self.root / "alpha.webp") as result:
            self.assertEqual(result.mode, "RGBA")
            self.assertEqual(result.getpixel((5, 5))[3], 128)

        src = self.write("cmyk.jpg", encode(photo().convert("CMYK"), "JPEG"))
        images.encode_webp(src, self.root / "cmyk.webp", quality=80, lossless=False)
        with Image.open(self.root / "cmyk.webp") as result:
            self.assertEqual(result.mode, "RGB")

    def test_lossless_png_keeps_every_pixel_and_uses_a_palette(self):
        source = graphic()
        src = self.write("graphic.png", encode(source, "PNG", compress_level=1))
        dst = self.root / "graphic.optimized"
        self.assertTrue(images.optimize_png_lossless(src, dst))
        self.assertLess(dst.stat().st_size, src.stat().st_size)
        with Image.open(dst) as result:
            self.assertEqual(result.mode, "P")
            self.assertEqual(list(result.convert("RGBA").getdata()), list(source.getdata()))

    def test_lossless_png_leaves_16_bit_images_alone(self):
        src = self.write("deep.png", encode(Image.new("I;16", (64, 64), 40000), "PNG"))
        self.assertFalse(images.optimize_png_lossless(src, self.root / "deep.optimized"))
        self.assertFalse((self.root / "deep.optimized").exists())

    def test_non_srgb_profiles_are_converted_before_pngquant_drops_them(self):
        icc = ImageCms.ImageCmsProfile(ImageCms.createProfile("sRGB")).tobytes()
        plain = self.write("plain.png", encode(graphic(), "PNG"))
        srgb = self.write("srgb.png", encode(graphic(), "PNG", icc_profile=icc))
        self.assertEqual(images.prepare_for_quantize(plain, self.root / "out"), plain)
        self.assertEqual(images.prepare_for_quantize(srgb, self.root / "out"), srgb)

        # Pillow can only build sRGB profiles, so make this one claim to be Display P3.
        with patch.object(ImageCms, "getProfileDescription", return_value="Display P3"):
            converted = images.prepare_for_quantize(srgb, self.root / "p3.png")
        self.assertEqual(converted, self.root / "p3.png")
        with Image.open(converted) as result:
            self.assertEqual((result.mode, result.size), ("RGBA", graphic().size))
            self.assertNotIn("icc_profile", result.info)

        # A profile that can't be applied skips lossy compression rather than shifting the colours.
        with patch.object(ImageCms, "getProfileDescription", return_value="Display P3"), \
                patch.object(ImageCms, "profileToProfile", side_effect=ImageCms.PyCMSError("bad profile")):
            self.assertIsNone(images.prepare_for_quantize(srgb, self.root / "bad.png"))

    @unittest.skipUnless(shutil.which("pngquant"), "pngquant is not installed")
    def test_pngquant_shrinks_and_reports_unreachable_quality(self):
        src = self.write("photo.png", encode(photo((400, 300)), "PNG"))
        dst = self.root / "photo.optimized"
        tool = shutil.which("pngquant")
        if asyncio.run(images.quantize_png(tool, src, dst, 100)):
            self.assertLess(dst.stat().st_size, src.stat().st_size)
        src = self.write("graphic.png", encode(graphic(), "PNG", compress_level=0))
        self.assertTrue(asyncio.run(images.quantize_png(tool, src, dst, 80)))
        with Image.open(dst) as result:
            self.assertEqual((result.format, result.mode), ("PNG", "P"))


class ImageRunnerTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.root = Path(self.folder.name)
        with patch.dict(os.environ, {"API_TOKEN": TOKEN, "DATA_DIR": str(self.root)}):
            settings = load_settings()
        self.store = Store(self.root / "jobs.sqlite3")
        caps = ImageCapabilities(webp=True, pngquant=shutil.which("pngquant"))
        self.runner = JobRunner(settings, self.store, Capabilities("test"), caps)
        self.runner._spawn = lambda coro: coro.close()  # No callbacks.
        self.served = {
            "/photo.jpg": encode(photo((640, 480)), "JPEG", quality=95),
            "/photo-300x200.jpg": encode(photo((300, 200)), "JPEG", quality=95),
            "/logo.png": encode(graphic(), "PNG", compress_level=0),
        }
        await self.runner._http.aclose()
        self.runner._http = httpx.AsyncClient(transport=httpx.MockTransport(self.serve))

    async def asyncTearDown(self):
        await self.runner.stop()
        self.store._conn.close()
        self.folder.cleanup()

    def serve(self, request):
        body = self.served.get(request.url.path)
        return httpx.Response(200, stream=httpx.ByteStream(body)) if body else httpx.Response(404)

    def create(self, paths, **options):
        files = [{"name": path.strip("/"), "url": f"https://example.test{path}"} for path in paths]
        return self.store.create_job({
            "id": "img", "kind": "image", "status": "queued", "source_url": files[0]["url"], "files": files,
            "options": ImageOptions(**options).model_dump(),
        })

    async def test_creates_webp_versions_and_a_smaller_png(self):
        self.create(["/photo.jpg", "/photo-300x200.jpg", "/logo.png", "/missing.jpg"])
        await self.runner._process("img")
        job = self.store.get_job("img")
        self.assertEqual(job["status"], "completed", job["message"])
        files = job["output"]["files"]
        self.assertEqual([f["name"] for f in files], ["photo.jpg", "photo-300x200.jpg", "logo.png", "missing.jpg"])

        self.assertEqual(set(files[0]["variants"]), {"webp"})
        self.assertNotIn("optimized", files[0]["variants"])  # JPEGs are only converted.
        self.assertEqual(set(files[2]["variants"]), {"optimized", "webp"})
        self.assertIn("404", files[3]["error"])

        for index, variant in ((0, "webp"), (2, "optimized"), (2, "webp")):
            path = self.runner.image_output_path("img", index, variant)
            self.assertEqual(path.stat().st_size, files[index]["variants"][variant]["size"])
        self.assertFalse(self.runner.image_output_path("img", 0, "source").exists())

        source = sum(len(self.served[f"/{f['name']}"]) for f in files[:3])
        self.assertEqual(job["input"]["size"], source)
        self.assertEqual(job["output"]["size"], sum(f["served_size"] for f in files[:3]))
        self.assertLess(job["output"]["size"], source)

    async def test_nothing_smaller_enough_is_skipped(self):
        self.create(["/photo.jpg"], min_savings_percent=99)
        await self.runner._process("img")
        job = self.store.get_job("img")
        self.assertEqual(job["status"], "skipped")
        self.assertEqual(job["output"]["files"][0]["variants"], {})
        self.assertFalse(any((self.runner.job_dir("img") / "files").iterdir()))

    async def test_every_file_failing_fails_the_job(self):
        self.create(["/missing.jpg", "/gone.png"])
        await self.runner._process("img")
        job = self.store.get_job("img")
        self.assertEqual(job["status"], "failed")
        self.assertIn("404", job["message"])

    async def test_png_recompression_can_be_turned_off(self):
        self.create(["/logo.png"], png="off", formats=[])
        await self.runner._process("img")
        self.assertEqual(self.store.get_job("img")["status"], "skipped")

    async def test_image_jobs_use_their_own_queue(self):
        self.runner.enqueue("a", "image")
        self.runner.enqueue("b")
        self.assertEqual((self.runner.image_queue.qsize(), self.runner.queue.qsize()), (1, 1))


class ImageApiTests(unittest.TestCase):
    def setUp(self):
        from fastapi.testclient import TestClient
        with patch.dict(os.environ, {"API_TOKEN": TOKEN}):
            from app.main import app
        self.folder = tempfile.TemporaryDirectory()
        self.root = Path(self.folder.name)
        self.store = Store(self.root / "jobs.sqlite3")
        self.enqueue = Mock()
        self.outputs = self.root / "files"
        self.outputs.mkdir()
        app.state.store = self.store
        app.state.image_caps = ImageCapabilities(webp=True, pngquant=None)
        app.state.runner = Mock(
            enqueue=self.enqueue,
            image_output_path=lambda job_id, index, variant: self.outputs / f"{index}.{variant}",
            output_path=lambda job_id: self.root / "output.mp4",
        )
        self.client = TestClient(app)
        self.headers = {"Authorization": f"Bearer {TOKEN}"}

    def tearDown(self):
        self.store._conn.close()
        self.folder.cleanup()

    def test_create_validates_and_queues_on_the_image_queue(self):
        response = self.client.post("/jobs/images", headers=self.headers, json={
            "files": [{"name": "full", "url": "https://example.test/a.jpg"},
                      {"name": "thumbnail", "url": "https://example.test/a-150x150.jpg"}],
        })
        self.assertEqual(response.status_code, 201, response.text)
        job = response.json()
        self.assertEqual((job["kind"], job["source_url"], len(job["files"])), ("image", "https://example.test/a.jpg", 2))
        self.assertIsNone(job["output_url"])
        self.enqueue.assert_called_once_with(job["id"], "image")

        for body in ({"files": []},
                     {"files": [{"name": "a", "url": "https://example.test/a.jpg"}], "options": {"formats": ["avif"]}},
                     {"files": [{"name": "a", "url": "https://example.test/a.jpg"}] * 101}):
            self.assertEqual(self.client.post("/jobs/images", headers=self.headers, json=body).status_code, 422)

    def test_unavailable_format_is_rejected(self):
        self.client.app.state.image_caps = ImageCapabilities(webp=False, pngquant=None)
        response = self.client.post("/jobs/images", headers=self.headers,
                                    json={"files": [{"name": "a", "url": "https://example.test/a.jpg"}]})
        self.assertEqual(response.status_code, 422)

    def test_outputs_are_served_per_file_and_variant(self):
        self.store.create_job({
            "id": "img", "kind": "image", "status": "completed", "source_url": "https://example.test/a.png",
            "files": [{"name": "full", "url": "https://example.test/a.png"}], "options": {},
            "output": {"files": [{"name": "full", "format": "png", "variants": {"webp": {"size": 4}, "optimized": {"size": 3}}}]},
        })
        (self.outputs / "0.webp").write_bytes(b"RIFF")
        (self.outputs / "0.optimized").write_bytes(b"PNG")

        response = self.client.get("/jobs/img/files/0/webp", headers=self.headers)
        self.assertEqual((response.status_code, response.headers["content-type"], response.content),
                         (200, "image/webp", b"RIFF"))
        response = self.client.get("/jobs/img/files/0/optimized", headers=self.headers)
        self.assertEqual((response.status_code, response.headers["content-type"]), (200, "image/png"))
        for path in ("/jobs/img/files/1/webp", "/jobs/img/files/0/source", "/jobs/img/files/-1/webp",
                     "/jobs/img/output"):
            self.assertEqual(self.client.get(path, headers=self.headers).status_code, 404, path)


if __name__ == "__main__":
    unittest.main()
