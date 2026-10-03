from __future__ import annotations

import asyncio
import json
import logging
from collections import deque
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Callable

from .models import JobOptions

log = logging.getLogger(__name__)

HDR_TRANSFERS = {"smpte2084", "arib-std-b67"}

# Only self-contained video containers/streams. Excludes DASH, HLS, concat,
# image sequences and other demuxers that can open references to unrelated files.
# MOV's external data references are disabled by FFmpeg by default.
MEDIA_INPUT_OPTIONS = (
    "-protocol_whitelist", "file",
    "-format_whitelist", "mov,matroska,webm,avi,mpeg,mpegts,flv,ogg,asf,h264,hevc,m4v",
)


class FFmpegError(Exception):
    pass


@dataclass
class Capabilities:
    version: str
    encoders: set[str] = field(default_factory=set)
    filters: set[str] = field(default_factory=set)

    @property
    def codecs(self) -> dict[str, bool]:
        return {"h264": "libx264" in self.encoders, "h265": "libx265" in self.encoders}

    @property
    def can_tonemap(self) -> bool:
        return {"zscale", "tonemap"} <= self.filters


@dataclass
class MediaInfo:
    size: int
    duration: float
    format_name: str
    bit_rate: int | None
    video_index: int | None = None
    video_codec: str | None = None
    width: int = 0  # display width (after SAR and rotation)
    height: int = 0  # display height (after SAR and rotation)
    fps: float | None = None
    pix_fmt: str | None = None
    color_transfer: str | None = None
    hdr: bool = False
    audio_index: int | None = None
    audio_codec: str | None = None
    audio_bit_rate: int | None = None

    def to_dict(self) -> dict:
        return asdict(self)


async def _capture(*args: str, timeout: float = 60) -> tuple[int, str, str]:
    proc = await asyncio.create_subprocess_exec(
        *args, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE
    )
    try:
        out, err = await asyncio.wait_for(proc.communicate(), timeout)
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        raise FFmpegError(f"{args[0]} timed out after {timeout:.0f}s")
    return proc.returncode or 0, out.decode(errors="replace"), err.decode(errors="replace")


def _second_column(listing: str) -> set[str]:
    names = set()
    for line in listing.splitlines():
        parts = line.split()
        if len(parts) >= 2:
            names.add(parts[1])
    return names


async def detect_capabilities() -> Capabilities:
    _, version, _ = await _capture("ffmpeg", "-hide_banner", "-version")
    _, encoders, _ = await _capture("ffmpeg", "-hide_banner", "-encoders")
    _, filters, _ = await _capture("ffmpeg", "-hide_banner", "-filters")
    return Capabilities(
        version=version.splitlines()[0].split(" Copyright")[0] if version else "unknown",
        encoders=_second_column(encoders),
        filters=_second_column(filters),
    )


def _ratio(value: str | None) -> float | None:
    if not value or value in ("0/0", "N/A"):
        return None
    num, _, den = value.partition("/")
    try:
        return float(num) / float(den or 1)
    except (ValueError, ZeroDivisionError):
        return None


def _rotation(stream: dict) -> int:
    for side_data in stream.get("side_data_list") or []:
        if "rotation" in side_data:
            return abs(int(float(side_data["rotation"]))) % 360
    rotate = (stream.get("tags") or {}).get("rotate")
    return abs(int(rotate)) % 360 if rotate else 0


def _int_or_none(value: object) -> int | None:
    try:
        return int(value)  # type: ignore[arg-type]
    except (TypeError, ValueError):
        return None


async def probe(path: Path) -> MediaInfo:
    code, out, err = await _capture(
        "ffprobe", "-v", "error", *MEDIA_INPUT_OPTIONS,
        "-print_format", "json", "-show_format", "-show_streams", str(path)
    )
    if code != 0:
        detail = err.strip().replace(f"{path}: ", "") or "ffprobe failed"
        raise FFmpegError(f"Could not read the video file: {detail}")
    data = json.loads(out or "{}")
    fmt = data.get("format") or {}
    info = MediaInfo(
        size=path.stat().st_size,
        duration=float(fmt.get("duration") or 0),
        format_name=fmt.get("format_name", ""),
        bit_rate=_int_or_none(fmt.get("bit_rate")),
    )

    for stream in data.get("streams") or []:
        kind = stream.get("codec_type")
        if kind == "video" and info.video_index is None and not (stream.get("disposition") or {}).get("attached_pic"):
            width, height = int(stream.get("width") or 0), int(stream.get("height") or 0)
            sar = _ratio(stream.get("sample_aspect_ratio"))
            if sar and abs(sar - 1) > 0.01:
                width = round(width * sar)
            if _rotation(stream) in (90, 270):
                width, height = height, width
            transfer = stream.get("color_transfer")
            info.video_index = int(stream["index"])
            info.video_codec = stream.get("codec_name")
            info.width, info.height = width, height
            info.fps = _ratio(stream.get("avg_frame_rate")) or _ratio(stream.get("r_frame_rate"))
            info.pix_fmt = stream.get("pix_fmt")
            info.color_transfer = transfer
            info.hdr = transfer in HDR_TRANSFERS
            if not info.duration:
                info.duration = float(stream.get("duration") or 0)
        elif kind == "audio" and info.audio_index is None:
            info.audio_index = int(stream["index"])
            info.audio_codec = stream.get("codec_name")
            info.audio_bit_rate = _int_or_none(stream.get("bit_rate"))
    return info


def target_size(width: int, height: int, max_resolution: int) -> tuple[int, int]:
    """Scale so the shorter side is at most `max_resolution` (works for portrait and landscape)."""
    w, h = float(width), float(height)
    short_side = min(w, h)
    if max_resolution and short_side > max_resolution:
        factor = max_resolution / short_side
        w, h = w * factor, h * factor
    # 4:2:0 chroma subsampling needs even dimensions.
    return max(2, int(round(w / 2)) * 2), max(2, int(round(h / 2)) * 2)


def build_command(src: Path, dst: Path, info: MediaInfo, opts: JobOptions, caps: Capabilities, threads: int) -> list[str]:
    if info.video_index is None:
        raise FFmpegError("Source has no video stream")

    cmd = ["ffmpeg", "-hide_banner", "-nostdin", "-y", "-loglevel", "error",
           *MEDIA_INPUT_OPTIONS, "-i", str(src)]
    cmd += ["-map", f"0:{info.video_index}"]
    keep_audio = info.audio_index is not None and not opts.remove_audio
    if keep_audio:
        cmd += ["-map", f"0:{info.audio_index}"]

    width, height = target_size(info.width, info.height, opts.max_resolution)
    filters = [f"scale={width}:{height}:flags=lanczos", "setsar=1"]
    if info.hdr and opts.tonemap_hdr and caps.can_tonemap:
        filters += [
            f"zscale=tin={info.color_transfer}:pin=bt2020:min=2020_ncl:t=linear:npl=100",
            "format=gbrpf32le",
            "zscale=p=bt709",
            "tonemap=tonemap=hable:desat=0",
            "zscale=t=bt709:m=bt709:r=tv",
        ]
    filters.append("format=yuv420p")
    cmd += ["-vf", ",".join(filters)]

    if opts.max_fps and info.fps and info.fps > opts.max_fps + 0.01:
        cmd += ["-fpsmax", f"{opts.max_fps:g}"]

    if opts.codec == "h265":
        cmd += ["-c:v", "libx265", "-preset", opts.preset, "-crf", str(opts.crf),
                "-tag:v", "hvc1", "-x265-params", "log-level=error"]
    else:
        cmd += ["-c:v", "libx264", "-preset", opts.preset, "-crf", str(opts.crf), "-profile:v", "high"]
    if threads:
        cmd += ["-threads", str(threads)]

    if keep_audio:
        target_bps = opts.audio_bitrate * 1000
        if info.audio_codec == "aac" and info.audio_bit_rate and info.audio_bit_rate <= target_bps * 1.1:
            cmd += ["-c:a", "copy"]
        else:
            cmd += ["-c:a", "aac", "-b:a", f"{opts.audio_bitrate}k"]
    else:
        cmd += ["-an"]

    if opts.strip_metadata:
        cmd += ["-map_metadata", "-1", "-map_chapters", "-1"]
    cmd += ["-sn", "-dn", "-movflags", "+faststart", "-f", "mp4", "-progress", "pipe:1", "-nostats", str(dst)]
    return cmd


async def transcode(
    cmd: list[str],
    duration: float,
    on_progress: Callable[[float], None],
    timeout: float,
    on_start: Callable[[asyncio.subprocess.Process], None] | None = None,
) -> None:
    log.debug("Running %s", " ".join(cmd))
    proc = await asyncio.create_subprocess_exec(
        *cmd, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE, stdin=asyncio.subprocess.DEVNULL
    )
    if on_start:
        on_start(proc)
    stderr_tail: deque[str] = deque(maxlen=30)

    async def read_stderr() -> None:
        assert proc.stderr
        async for line in proc.stderr:
            stderr_tail.append(line.decode(errors="replace").rstrip())

    async def read_progress() -> None:
        assert proc.stdout
        async for raw in proc.stdout:
            key, _, value = raw.decode(errors="replace").strip().partition("=")
            if key == "out_time_us" and value.isdigit() and duration > 0:
                on_progress(min(0.999, int(value) / 1_000_000 / duration))

    try:
        await asyncio.wait_for(asyncio.gather(read_stderr(), read_progress(), proc.wait()), timeout)
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        raise FFmpegError(f"Transcoding timed out after {timeout:.0f}s")

    if proc.returncode != 0:
        detail = "\n".join(line for line in stderr_tail if line) or f"ffmpeg exited with code {proc.returncode}"
        raise FFmpegError(detail[-2000:])
