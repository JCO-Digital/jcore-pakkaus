from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, Field, HttpUrl, model_validator

Preset = Literal[
    "ultrafast", "superfast", "veryfast", "faster", "fast", "medium", "slow", "slower", "veryslow"
]


class JobOptions(BaseModel):
    codec: Literal["h264", "h265"] = "h264"
    crf: int = Field(
        23, ge=0, le=51,
        description="Constant rate factor. Lower = better quality, bigger file. H.264 requires at least 1.",
    )
    preset: Preset = "medium"
    max_resolution: int = Field(
        1080, ge=0, le=4320, description="Limit for the shorter side in pixels (1080 = 1080p). 0 keeps the source size."
    )
    max_fps: float = Field(0, ge=0, le=240, description="Cap the frame rate. 0 keeps the source frame rate.")
    audio_bitrate: int = Field(128, ge=32, le=512, description="AAC bitrate in kbit/s.")
    remove_audio: bool = False
    tonemap_hdr: bool = Field(True, description="Convert HDR (HLG/PQ) sources to SDR so they look right everywhere.")
    strip_metadata: bool = True
    min_savings_percent: float = Field(
        5, ge=0, le=100, description="Skip the result unless it is at least this much smaller than the source."
    )

    @model_validator(mode="after")
    def validate_crf(self) -> JobOptions:
        if self.codec == "h264" and self.crf == 0:
            raise ValueError("H.264 CRF must be at least 1; High profile does not support lossless encoding")
        return self


class JobCreate(BaseModel):
    source_url: HttpUrl
    callback_url: HttpUrl | None = None
    callback_secret: str | None = Field(None, min_length=16, max_length=256)
    options: JobOptions = Field(default_factory=JobOptions)
    metadata: dict[str, Any] = Field(default_factory=dict, description="Opaque data echoed back to the client.")
