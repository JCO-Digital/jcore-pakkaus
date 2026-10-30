from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "optimizer-service"))

from app.ffmpeg import _ratio, probe


class RatioTests(unittest.TestCase):
    def test_reads_both_separators(self):
        # ffprobe writes frame rates with "/" and sample aspect ratios with ":".
        self.assertAlmostEqual(_ratio("16:11"), 16 / 11)
        self.assertAlmostEqual(_ratio("30000/1001"), 30000 / 1001)
        self.assertEqual(_ratio("25"), 25.0)

    def test_unknown_zero_and_invalid_values_are_none(self):
        for value in (None, "", "N/A", "0/0", "0:0", "0:1", "0/1", "1:0", "-4:3", "abc", "16:x", "inf"):
            with self.subTest(value=value):
                self.assertIsNone(_ratio(value))


@unittest.skipUnless(shutil.which("ffmpeg") and shutil.which("ffprobe"), "FFmpeg tools are required")
class ProbeTests(unittest.IsolatedAsyncioTestCase):
    async def test_anamorphic_video_is_widened_to_display_size(self):
        with tempfile.TemporaryDirectory() as folder:
            video = Path(folder) / "pal.mp4"
            subprocess.run(
                ["ffmpeg", "-v", "error", "-f", "lavfi", "-i", "testsrc2=size=720x576:duration=1",
                 "-vf", "setsar=16/11", "-c:v", "libx264", "-pix_fmt", "yuv420p", str(video)],
                check=True, capture_output=True, timeout=30,
            )
            info = await probe(video)
        # 720 × 16/11 ≈ 1047 display pixels wide.
        self.assertEqual((info.width, info.height), (1047, 576))
        self.assertAlmostEqual(info.fps, 25)


if __name__ == "__main__":
    unittest.main()
