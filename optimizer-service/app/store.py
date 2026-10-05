from __future__ import annotations

import hashlib
import json
import secrets
import sqlite3
import threading
import time
from pathlib import Path
from typing import Any, Iterable

JSON_FIELDS = ("options", "metadata", "input", "output")
TERMINAL_STATUSES = ("completed", "skipped", "failed")
ACTIVE_STATUSES = ("queued", "downloading", "processing")
# Owner of jobs created with the API_TOKEN environment variable (and of jobs from before API keys).
ENV_KEY_ID = "env"
KEY_PREFIX = "jpk_"
LAST_USED_RESOLUTION = 60

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
CREATE TABLE IF NOT EXISTS api_keys (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    prefix TEXT NOT NULL,
    key_hash TEXT NOT NULL UNIQUE,
    created_by TEXT,
    created_at REAL NOT NULL,
    last_used_at REAL,
    revoked_at REAL
);
-- One row per job, kept after the job itself expires so usage history survives.
CREATE TABLE IF NOT EXISTS usage (
    job_id TEXT PRIMARY KEY,
    key_id TEXT NOT NULL,
    status TEXT NOT NULL,
    created_at REAL NOT NULL,
    finished_at REAL,
    input_bytes INTEGER,
    output_bytes INTEGER,
    duration_seconds REAL,
    elapsed_seconds REAL
);
CREATE INDEX IF NOT EXISTS usage_key_created ON usage (key_id, created_at);
"""


def hash_key(key: str) -> str:
    # Keys are 256-bit random values, so a fast unsalted hash is enough.
    return hashlib.sha256(key.encode()).hexdigest()


class Store:
    """Tiny SQLite-backed job persistence. Access is serialised through one lock."""

    def __init__(self, path: Path) -> None:
        self._lock = threading.Lock()
        self._conn = sqlite3.connect(path, check_same_thread=False, isolation_level=None)
        self._conn.row_factory = sqlite3.Row
        self._conn.execute("PRAGMA journal_mode=WAL")
        self._conn.executescript(SCHEMA)
        columns = {row[1] for row in self._conn.execute("PRAGMA table_info(jobs)")}
        if "api_key_id" not in columns:
            self._conn.execute(f"ALTER TABLE jobs ADD COLUMN api_key_id TEXT NOT NULL DEFAULT '{ENV_KEY_ID}'")

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
        self._query(
            "INSERT OR REPLACE INTO usage (job_id, key_id, status, created_at) VALUES (?, ?, ?, ?)",
            (job["id"], job.get("api_key_id", ENV_KEY_ID), job["status"], now),
        )
        return self.get_job(job["id"])  # type: ignore[return-value]

    def get_job(self, job_id: str) -> dict[str, Any] | None:
        rows = self._query("SELECT * FROM jobs WHERE id = ?", (job_id,))
        return self._job(rows[0] if rows else None)

    def update_job(self, job_id: str, **fields: Any) -> None:
        fields = self._encode({**fields, "updated_at": time.time()})
        assignments = ", ".join(f"{key} = :{key}" for key in fields)
        self._query(f"UPDATE jobs SET {assignments} WHERE id = :id", {**fields, "id": job_id})
        if fields.get("status") in TERMINAL_STATUSES:
            self._query(
                """UPDATE usage SET status = jobs.status, finished_at = jobs.updated_at,
                       input_bytes = json_extract(jobs.input, '$.size'),
                       output_bytes = json_extract(jobs.output, '$.size'),
                       duration_seconds = json_extract(jobs.input, '$.duration'),
                       elapsed_seconds = json_extract(jobs.output, '$.elapsed_seconds')
                   FROM jobs WHERE jobs.id = usage.job_id AND usage.job_id = ?""",
                (job_id,),
            )

    def delete_job(self, job_id: str) -> None:
        marks = ", ".join("?" for _ in TERMINAL_STATUSES)
        self._query(
            f"UPDATE usage SET status = 'cancelled', finished_at = ? WHERE job_id = ? AND status NOT IN ({marks})",
            (time.time(), job_id, *TERMINAL_STATUSES),
        )
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

    # ------------------------------------------------------------ API keys

    def create_api_key(self, name: str, created_by: str | None) -> tuple[dict[str, Any], str]:
        """Returns the key record and the plaintext key, which is not stored and can't be shown again."""
        key = KEY_PREFIX + secrets.token_urlsafe(32)
        record = {
            "id": secrets.token_hex(8),
            "name": name,
            "prefix": key[:12],
            "key_hash": hash_key(key),
            "created_by": created_by,
            "created_at": time.time(),
        }
        self._query(
            "INSERT INTO api_keys (id, name, prefix, key_hash, created_by, created_at) "
            "VALUES (:id, :name, :prefix, :key_hash, :created_by, :created_at)",
            record,
        )
        return self.get_api_key(record["id"]), key  # type: ignore[return-value]

    def get_api_key(self, key_id: str) -> dict[str, Any] | None:
        rows = self._query(
            "SELECT id, name, prefix, created_by, created_at, last_used_at, revoked_at FROM api_keys WHERE id = ?",
            (key_id,),
        )
        return dict(rows[0]) if rows else None

    def list_api_keys(self) -> list[dict[str, Any]]:
        return [dict(row) for row in self._query(
            "SELECT id, name, prefix, created_by, created_at, last_used_at, revoked_at FROM api_keys "
            "ORDER BY revoked_at IS NOT NULL, created_at DESC"
        )]

    def revoke_api_key(self, key_id: str) -> bool:
        with self._lock:
            cursor = self._conn.execute(
                "UPDATE api_keys SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL", (time.time(), key_id)
            )
            return cursor.rowcount > 0

    def authenticate_api_key(self, key: str) -> str | None:
        """The id of the active key matching `key`, or None."""
        if not key.startswith(KEY_PREFIX):
            return None
        rows = self._query(
            "SELECT id, last_used_at FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL", (hash_key(key),)
        )
        if not rows:
            return None
        key_id, last_used = rows[0]
        now = time.time()
        # Clients poll, so only write when the stored time is noticeably stale.
        if last_used is None or now - last_used > LAST_USED_RESOLUTION:
            self._query("UPDATE api_keys SET last_used_at = ? WHERE id = ?", (now, key_id))
        return key_id

    # --------------------------------------------------------------- usage

    def usage_by_key(self, since: float) -> dict[str, dict[str, Any]]:
        rows = self._query(
            """SELECT key_id,
                      COUNT(*) AS jobs,
                      SUM(status = 'completed') AS completed,
                      SUM(status = 'skipped') AS skipped,
                      SUM(status = 'failed') AS failed,
                      SUM(status IN ('queued', 'downloading', 'processing')) AS active,
                      SUM(CASE WHEN status IN ('completed', 'skipped') THEN input_bytes END) AS input_bytes,
                      SUM(CASE WHEN status = 'completed' THEN input_bytes - output_bytes END) AS saved_bytes,
                      SUM(CASE WHEN status IN ('completed', 'skipped') THEN duration_seconds END) AS video_seconds,
                      SUM(elapsed_seconds) AS processing_seconds,
                      MAX(created_at) AS last_job_at
               FROM usage WHERE created_at >= ? GROUP BY key_id""",
            (since,),
        )
        return {row["key_id"]: {k: row[k] or 0 for k in row.keys() if k != "key_id"} for row in rows}

    def usage_by_day(self, since: float, key_id: str | None = None) -> list[dict[str, Any]]:
        """Jobs per UTC day. Days without jobs are left out."""
        where, params = "created_at >= ?", [since]
        if key_id is not None:
            where += " AND key_id = ?"
            params.append(key_id)
        rows = self._query(
            f"""SELECT date(created_at, 'unixepoch') AS day,
                       COUNT(*) AS jobs,
                       SUM(status = 'completed') AS completed,
                       SUM(status = 'skipped') AS skipped,
                       SUM(status = 'failed') AS failed,
                       SUM(CASE WHEN status = 'completed' THEN input_bytes - output_bytes END) AS saved_bytes
                FROM usage WHERE {where} GROUP BY day ORDER BY day""",
            params,
        )
        return [{k: row[k] or 0 for k in row.keys()} for row in rows]


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
