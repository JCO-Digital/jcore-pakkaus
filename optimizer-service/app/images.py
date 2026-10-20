"""Image optimization: WebP versions of JPEG and PNG files, and smaller PNG files.

Decoding runs in Pillow, restricted to JPEG and PNG so job input never reaches the other decoders it ships.
Lossy PNG compression runs pngquant on files Pillow has already accepted.
"""
from __future__ import annotations

import asyncio
import logging
import shutil
from dataclasses import asdict, dataclass
from io import BytesIO
from pathlib import Path
from typing import Callable

from PIL import Image, ImageChops, ImageCms, ImageOps, features

log = logging.getLogger(__name__)

INPUT_FORMATS = ("JPEG", "PNG")
MIME_TYPES = {"JPEG": "image/jpeg", "PNG": "image/png", "webp": "image/webp"}
WEBP_MAX_SIDE = 16383
# pngquant discards results more than this far below the requested quality.
PNG_QUALITY_SPREAD = 20
TOOL_TIMEOUT = 120
# pngquant exit codes: result larger than the input, or quality below the minimum. Both keep the input.
PNGQUANT_KEPT = (98, 99)


class ImageError(Exception):
    pass


@dataclass
class ImageCapabilities:
    webp: bool
    pngquant: str | None

    @property
    def formats(self) -> dict[str, bool]:
        return {"webp": self.webp}


def detect_image_capabilities() -> ImageCapabilities:
    return ImageCapabilities(webp=features.check("webp"), pngquant=shutil.which("pngquant"))


@dataclass
class ImageInfo:
    size: int
    format: str
    width: int
    height: int
    mode: str
    animated: bool

    def to_dict(self) -> dict:
        return asdict(self)


def inspect(path: Path, max_pixels: int) -> ImageInfo:
    """Read the header only; refuses anything but JPEG and PNG, and images over `max_pixels`."""
    try:
        with Image.open(path, formats=INPUT_FORMATS) as im:
            width, height = im.size
            if width * height > max_pixels:
                raise ImageError(
                    f"Image is {width}×{height}, over the {max_pixels / 1_000_000:g} megapixel limit"
                )
            return ImageInfo(
                size=path.stat().st_size, format=im.format or "", width=width, height=height, mode=im.mode,
                animated=bool(getattr(im, "is_animated", False)),
            )
    except ImageError:
        raise
    except (OSError, ValueError, Image.DecompressionBombError) as exc:
        raise ImageError(f"Not a JPEG or PNG image Pillow can read: {exc}") from exc


def _has_alpha(im: Image.Image) -> bool:
    return im.mode in ("RGBA", "LA", "PA", "RGBa", "La") or "transparency" in im.info


def _browser_pixels(im: Image.Image) -> Image.Image:
    """Upright RGB or RGBA pixels, the way a browser shows the image."""
    im = ImageOps.exif_transpose(im)
    if im.mode in ("RGB", "RGBA"):
        return im
    if im.mode.startswith("I"):  # 16-bit greyscale: scale down instead of clipping to white.
        im = im.point(lambda value: value / 256).convert("L")
    return im.convert("RGBA" if _has_alpha(im) else "RGB")


def encode_webp(src: Path, dst: Path, *, quality: int, lossless: bool) -> None:
    """Write `src` as WebP, upright and without metadata except the colour profile."""
    with Image.open(src, formats=INPUT_FORMATS) as source:
        if max(source.size) > WEBP_MAX_SIDE:
            raise ImageError(f"Image is larger than WebP's {WEBP_MAX_SIDE} px limit")
        icc = source.info.get("icc_profile")
        if source.mode == "CMYK":
            # WebP has no CMYK; convert through the embedded profile so colours survive.
            im = ImageOps.exif_transpose(source)
            if icc:
                srgb = ImageCms.createProfile("sRGB")
                im = ImageCms.profileToProfile(im, ImageCms.ImageCmsProfile(BytesIO(icc)), srgb, outputMode="RGB")
            else:
                im = im.convert("RGB")
            icc = None
        else:
            im = _browser_pixels(source)
        options = {"lossless": lossless, "method": 4, "quality": 80 if lossless else quality, "exact": False}
        if icc:
            options["icc_profile"] = icc
        im.save(dst, "WEBP", **options)
    _verify(dst, "WEBP", im.size)


def optimize_png_lossless(src: Path, dst: Path) -> bool:
    """Re-save a PNG with the same pixels, smaller: drop an unused alpha channel, use a palette when the image
    has 256 colours or fewer, and compress harder. Returns False when it can't safely be done."""
    with Image.open(src, formats=("PNG",)) as source:
        if any(";16" in str(tile[3]) for tile in source.tile):
            return False  # 16 bits per channel; re-saving would drop the extra precision.
        source.load()
        if source.mode not in ("RGB", "RGBA", "P", "L", "LA"):
            return False
        im = source
        if im.mode == "RGBA" and im.getextrema()[3] == (255, 255):
            im = im.convert("RGB")
        if im.mode in ("RGB", "RGBA"):
            im = _exact_palette(im) or im
        options = {"optimize": True}
        if icc := source.info.get("icc_profile"):
            options["icc_profile"] = icc
        im.save(dst, "PNG", **options)

        with Image.open(dst, formats=("PNG",)) as result:
            if ImageChops.difference(result.convert("RGBA"), source.convert("RGBA")).getbbox() is not None:
                dst.unlink(missing_ok=True)
                return False
    return True


def _exact_palette(im: Image.Image) -> Image.Image | None:
    """The image as a palette image, if that loses nothing."""
    colors = im.getcolors(256)
    if colors is None:
        return None
    method = Image.Quantize.FASTOCTREE if im.mode == "RGBA" else Image.Quantize.MEDIANCUT
    palette = im.quantize(colors=len(colors), method=method)
    if ImageChops.difference(palette.convert(im.mode), im).getbbox() is not None:
        return None
    return palette


def prepare_for_quantize(src: Path, dst: Path) -> Path | None:
    """The file pngquant should compress. pngquant drops colour profiles, so an image with a profile other than
    sRGB is first converted to sRGB into `dst`. None when that isn't possible and lossy compression is skipped."""
    with Image.open(src, formats=("PNG",)) as im:
        icc = im.info.get("icc_profile")
        if not icc:
            return src
        try:
            profile = ImageCms.ImageCmsProfile(BytesIO(icc))
            if "srgb" in ImageCms.getProfileDescription(profile).lower():
                return src  # Browsers assume sRGB; dropping it changes nothing.
            mode = "RGBA" if _has_alpha(im) else "RGB"
            converted = ImageCms.profileToProfile(
                im.convert(mode), profile, ImageCms.createProfile("sRGB"), outputMode=mode
            )
        except (ImageCms.PyCMSError, OSError, ValueError):
            return None
        converted.info.pop("icc_profile", None)  # Copied from the source; the pixels are sRGB now.
        converted.save(dst, "PNG", compress_level=1)
    return dst


async def quantize_png(
    tool: str, src: Path, dst: Path, quality: int,
    on_start: Callable[[asyncio.subprocess.Process], None] | None = None,
) -> bool:
    """Lossy palette compression with pngquant. False when it would not reach the quality or not be smaller."""
    low = max(0, quality - PNG_QUALITY_SPREAD)
    proc = await asyncio.create_subprocess_exec(
        tool, "--quality", f"{low}-{quality}", "--speed", "3", "--strip", "--skip-if-larger", "--force",
        "--output", str(dst), "--", str(src),
        stdin=asyncio.subprocess.DEVNULL, stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.PIPE,
    )
    if on_start:
        on_start(proc)
    try:
        _, err = await asyncio.wait_for(proc.communicate(), TOOL_TIMEOUT)
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        raise ImageError(f"pngquant timed out after {TOOL_TIMEOUT}s")
    if proc.returncode in PNGQUANT_KEPT:
        dst.unlink(missing_ok=True)
        return False
    if proc.returncode != 0:
        detail = err.decode(errors="replace").strip()[-500:] or f"exit code {proc.returncode}"
        raise ImageError(f"pngquant failed: {detail}")
    with Image.open(src, formats=("PNG",)) as source:
        size = source.size
    _verify(dst, "PNG", size)
    return True


def _verify(path: Path, fmt: str, size: tuple[int, int]) -> None:
    """The encoder's output opens as the expected format and dimensions."""
    try:
        with Image.open(path, formats=(fmt,)) as result:
            if result.size != size:
                raise ImageError(f"{fmt} result is {result.size}, expected {size}")
    except (OSError, ValueError) as exc:
        raise ImageError(f"{fmt} result could not be read back: {exc}") from exc
