import asyncio
from pathlib import Path
import sys
from types import SimpleNamespace
import unittest

from opentelemetry import metrics
from opentelemetry.sdk.metrics import MeterProvider
from opentelemetry.sdk.metrics.export import InMemoryMetricReader

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "optimizer-service"))

from app import telemetry

# The app's instruments are created against the global proxy meter at import time and start
# recording once a provider is set, the same way FastAPI's environment setup enables them.
READER = InMemoryMetricReader()
metrics.set_meter_provider(MeterProvider(metric_readers=[READER]))


def collect() -> dict[str, list]:
    points: dict[str, list] = {}
    for resource_metrics in READER.get_metrics_data().resource_metrics:
        for scope in resource_metrics.scope_metrics:
            for metric in scope.metrics:
                points.setdefault(metric.name, []).extend(metric.data.data_points)
    return points


class TelemetryTests(unittest.TestCase):
    def test_record_job(self):
        telemetry.record_job("completed", "h265", 12.5, input_bytes=1000, output_bytes=400)
        telemetry.record_job("failed", "h265", 0.5)
        points = collect()

        jobs = {p.attributes["status"]: p.value for p in points["pakkaus.jobs"] if p.attributes["codec"] == "h265"}
        self.assertEqual(jobs["completed"], 1)
        self.assertEqual(jobs["failed"], 1)
        completed = [p for p in points["pakkaus.job.duration"]
                     if p.attributes == {"status": "completed", "codec": "h265"}]
        self.assertEqual((completed[0].count, completed[0].sum), (1, 12.5))
        # Labelled by status so savings can be computed from completed jobs only; skipped ones keep the original.
        completed_only = {"status": "completed", "codec": "h265"}
        self.assertEqual([p.value for p in points["pakkaus.input.size"] if p.attributes == completed_only], [1000])
        self.assertEqual([p.value for p in points["pakkaus.output.size"] if p.attributes == completed_only], [400])

    def test_start_reports_queue_and_zeroes_counters(self):
        queue = asyncio.Queue()
        queue.put_nowait("a")
        queue.put_nowait("b")
        telemetry.start(SimpleNamespace(queue=queue, active={"c"}))
        points = collect()
        self.assertEqual(points["pakkaus.queue.waiting"][-1].value, 2)
        self.assertEqual(points["pakkaus.jobs.active"][-1].value, 1)

        # Every series exists before its first job, so Prometheus sees the first increment.
        jobs = {(p.attributes["status"], p.attributes["codec"]) for p in points["pakkaus.jobs"]}
        self.assertLessEqual({(s, c) for s in ("completed", "skipped", "failed", "cancelled")
                              for c in ("h264", "h265")}, jobs)
        results = {p.attributes["result"] for p in points["pakkaus.callbacks"]}
        self.assertLessEqual({"delivered", "rejected", "gave_up"}, results)
        self.assertIn({"status": "completed", "codec": "h264"},
                      [dict(p.attributes) for p in points["pakkaus.output.size"]])

    def test_health_checks_are_excluded(self):
        exclude = telemetry.CONFIG["exclude"]
        self.assertTrue(exclude({"path": "/health"}))
        self.assertTrue(exclude({"path": "/admin/static/dashboard.js"}))
        self.assertFalse(exclude({"path": "/jobs"}))


if __name__ == "__main__":
    unittest.main()
