from __future__ import annotations

import json
import sqlite3
import threading
import time
from pathlib import Path
from typing import Any, Iterable

JSON_FIELDS = ("options", "metadata", "input", "output")
TERMINAL_STATUSES = ("completed", "skipped", "failed")
ACTIVE_STATUSES = ("queued", "downloading", "processing")

SCHEMA = """
CREATE TABLE IF NOT EXISTS jobs (
    id TEXT PRIMARY KEY,
    status TEXT NOT NULL,
    progress REAL NOT NULL DEFAULT 0,
    message TEXT,
    source_url TEXT NOT NULL,
    callback_url TEXT,
    callback_secret TEXT,
    options TEXT NOT NULL,
    metadata TEXT,
    input TEXT,
    output TEXT,
    created_at REAL NOT NULL,
    updated_at REAL NOT NULL
);
CREATE INDEX IF NOT EXISTS jobs_status ON jobs (status);
"""


class Store:
    """Tiny SQLite-backed job persistence. Access is serialised through one lock."""

    def __init__(self, path: Path) -> None:
        self._lock = threading.Lock()
        self._conn = sqlite3.connect(path, check_same_thread=False, isolation_level=None)
        self._conn.row_factory = sqlite3.Row
        self._conn.execute("PRAGMA journal_mode=WAL")
        self._conn.executescript(SCHEMA)

    def _query(self, sql: str, params: Any = ()) -> list[sqlite3.Row]:
        with self._lock:
            return self._conn.execute(sql, params).fetchall()

    @staticmethod
    def _job(row: sqlite3.Row | None) -> dict[str, Any] | None:
        if row is None:
            return None
        job = dict(row)
        for field in JSON_FIELDS:
            job[field] = json.loads(job[field]) if job[field] else None
        return job

    @staticmethod
    def _encode(fields: dict[str, Any]) -> dict[str, Any]:
        return {
            key: json.dumps(value) if key in JSON_FIELDS and value is not None else value
            for key, value in fields.items()
        }

    def create_job(self, job: dict[str, Any]) -> dict[str, Any]:
        now = time.time()
        record = self._encode({**job, "created_at": now, "updated_at": now})
        columns = ", ".join(record)
        placeholders = ", ".join(f":{key}" for key in record)
        self._query(f"INSERT INTO jobs ({columns}) VALUES ({placeholders})", record)
        return self.get_job(job["id"])  # type: ignore[return-value]

    def get_job(self, job_id: str) -> dict[str, Any] | None:
        rows = self._query("SELECT * FROM jobs WHERE id = ?", (job_id,))
        return self._job(rows[0] if rows else None)

    def update_job(self, job_id: str, **fields: Any) -> None:
        fields = self._encode({**fields, "updated_at": time.time()})
        assignments = ", ".join(f"{key} = :{key}" for key in fields)
        self._query(f"UPDATE jobs SET {assignments} WHERE id = :id", {**fields, "id": job_id})

    def delete_job(self, job_id: str) -> None:
        self._query("DELETE FROM jobs WHERE id = ?", (job_id,))

    def jobs_with_status(self, statuses: Iterable[str]) -> list[dict[str, Any]]:
        statuses = tuple(statuses)
        marks = ", ".join("?" for _ in statuses)
        rows = self._query(f"SELECT * FROM jobs WHERE status IN ({marks}) ORDER BY created_at", statuses)
        return [self._job(row) for row in rows]  # type: ignore[misc]

    def job_ids(self) -> set[str]:
        return {row[0] for row in self._query("SELECT id FROM jobs")}

    def expired_job_ids(self, older_than: float) -> list[str]:
        marks = ", ".join("?" for _ in TERMINAL_STATUSES)
        return [row[0] for row in self._query(
            f"SELECT id FROM jobs WHERE updated_at < ? AND status IN ({marks})",
            (older_than, *TERMINAL_STATUSES),
        )]

    def job_counts(self) -> dict[str, int]:
        return {row[0]: row[1] for row in self._query("SELECT status, COUNT(*) FROM jobs GROUP BY status")}


def public_job(job: dict[str, Any]) -> dict[str, Any]:
    """The job representation returned to API clients (never includes the callback secret)."""
    return {
        "id": job["id"],
        "status": job["status"],
        "progress": job["progress"],
        "message": job["message"],
        "source_url": job["source_url"],
        "options": job["options"],
        "metadata": job["metadata"] or {},
        "input": job["input"],
        "output": job["output"],
        "output_url": f"/jobs/{job['id']}/output" if job["status"] == "completed" else None,
        "created_at": job["created_at"],
        "updated_at": job["updated_at"],
    }
