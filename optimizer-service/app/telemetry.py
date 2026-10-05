"""Job and queue metrics.

FastAPI exports these, along with its own HTTP request metrics, over OTLP when OTEL_EXPORTER_OTLP_ENDPOINT
is set. Without an endpoint the instruments are no-ops.
"""
from __future__ import annotations

from opentelemetry import metrics

from . import __version__

# Request telemetry for FastAPI(): metrics only, and not for health checks or static files.
CONFIG = {
    "tracing": False,
    "operation_spans": False,
    "logs": False,
    "exclude": lambda scope: scope.get("path") == "/health" or scope.get("path", "").startswith("/admin/static/"),
}

meter = metrics.get_meter("jcore_pakkaus", __version__)

jobs = meter.create_counter(
    "pakkaus.jobs", unit="{job}", description="Jobs that reached a final status, by status and codec."
)
job_duration = meter.create_histogram(
    "pakkaus.job.duration", unit="s", description="Time from download start to a final status.",
    explicit_bucket_boundaries_advisory=[1, 5, 15, 30, 60, 120, 300, 600, 1200, 1800, 3600, 7200, 14400],
)
input_size = meter.create_counter(
    "pakkaus.input.size", unit="By", description="Bytes of source video transcoded, by status (completed or skipped)."
)
output_size = meter.create_counter("pakkaus.output.size", unit="By", description="Bytes of optimized video kept.")
callbacks = meter.create_counter(
    "pakkaus.callbacks", unit="{callback}", description="Callback outcomes: delivered, rejected or gave_up."
)


def record_job(status: str, codec: str, seconds: float, input_bytes: int = 0, output_bytes: int = 0) -> None:
    attributes = {"status": status, "codec": codec}
    jobs.add(1, attributes)
    job_duration.record(seconds, attributes)
    if input_bytes:
        input_size.add(input_bytes, attributes)
    if output_bytes:
        output_size.add(output_bytes, attributes)


def observe_runner(runner) -> None:
    """Report queue depth from the job runner as gauges."""
    def waiting(_options):
        yield metrics.Observation(runner.queue.qsize())

    def active(_options):
        yield metrics.Observation(len(runner.active))

    meter.create_observable_gauge("pakkaus.queue.waiting", [waiting], unit="{job}",
                                  description="Jobs waiting for a worker.")
    meter.create_observable_gauge("pakkaus.jobs.active", [active], unit="{job}",
                                  description="Jobs being downloaded or transcoded.")
