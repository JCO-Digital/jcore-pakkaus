import importlib
import os
from pathlib import Path
import sys
import tempfile
import time
from types import SimpleNamespace
import unittest
from unittest.mock import AsyncMock, Mock, patch
from urllib.parse import parse_qs, urlparse

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "optimizer-service"))

from app.ffmpeg import Capabilities
from app.store import ENV_KEY_ID, Store

TOKEN = "test-token-0123456789abcdef"
DASHBOARD_ENV = {
    "API_TOKEN": TOKEN,
    "PUBLIC_URL": "http://testserver",
    "SESSION_SECRET": "s" * 40,
    "GITHUB_CLIENT_ID": "client-id",
    "GITHUB_CLIENT_SECRET": "client-secret",
    "GITHUB_ORG": "JCO-Digital",
}
JOB = {"source_url": "https://example.test/video.mp4"}


def load_main(env):
    """Import a fresh app.main with `env`, leaving the module other tests use in place."""
    previous = sys.modules.pop("app.main", None)
    try:
        with patch.dict(os.environ, env, clear=False):
            return importlib.import_module("app.main")
    finally:
        if previous is not None:
            sys.modules["app.main"] = previous
        else:
            sys.modules.pop("app.main", None)


class StoreKeyTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.store = Store(Path(self.folder.name) / "jobs.sqlite3")

    def tearDown(self):
        self.store._conn.close()
        self.folder.cleanup()

    def job(self, job_id, key_id=ENV_KEY_ID):
        return self.store.create_job({
            "id": job_id, "status": "queued", "progress": 0, "source_url": "https://x.test/a.mp4",
            "options": {}, "metadata": {}, "api_key_id": key_id,
        })

    def test_keys_are_hashed_and_revocable(self):
        record, key = self.store.create_api_key("Site", "octocat")
        self.assertTrue(key.startswith("jpk_"))
        self.assertTrue(key.startswith(record["prefix"]))
        raw = self.store._conn.execute("SELECT * FROM api_keys").fetchone()
        self.assertNotIn(key, [str(value) for value in tuple(raw)])

        self.assertEqual(self.store.authenticate_api_key(key), record["id"])
        self.assertIsNone(self.store.authenticate_api_key(key + "x"))
        self.assertIsNone(self.store.authenticate_api_key(TOKEN))
        self.assertIsNotNone(self.store.get_api_key(record["id"])["last_used_at"])

        self.assertTrue(self.store.revoke_api_key(record["id"]))
        self.assertFalse(self.store.revoke_api_key(record["id"]))
        self.assertIsNone(self.store.authenticate_api_key(key))

    def test_last_used_is_throttled(self):
        record, key = self.store.create_api_key("Site", None)
        self.store.authenticate_api_key(key)
        first = self.store.get_api_key(record["id"])["last_used_at"]
        self.store.authenticate_api_key(key)
        self.assertEqual(self.store.get_api_key(record["id"])["last_used_at"], first)

    def test_usage_is_recorded_and_outlives_jobs(self):
        self.job("done", "k1")
        self.store.update_job("done", status="processing", input={"size": 1000, "duration": 60.0})
        self.store.update_job("done", status="completed", output={"size": 400, "elapsed_seconds": 12.5})
        self.job("skip", "k1")
        self.store.update_job("skip", status="processing", input={"size": 500, "duration": 30.0})
        self.store.update_job("skip", status="skipped", output={"size": 490, "elapsed_seconds": 5.0})
        self.job("bad", "k1")
        self.store.update_job("bad", status="failed", message="nope")
        self.job("gone", "k1")
        self.job("other", "k2")

        self.store.delete_job("done")
        self.store.delete_job("gone")

        usage = self.store.usage_by_key(0)
        self.assertEqual(usage["k1"]["jobs"], 4)
        self.assertEqual(usage["k1"]["completed"], 1)
        self.assertEqual(usage["k1"]["skipped"], 1)
        self.assertEqual(usage["k1"]["failed"], 1)
        self.assertEqual(usage["k1"]["active"], 0)
        self.assertEqual(usage["k1"]["input_bytes"], 1500)
        self.assertEqual(usage["k1"]["saved_bytes"], 600)
        self.assertEqual(usage["k1"]["video_seconds"], 90)
        self.assertEqual(usage["k1"]["processing_seconds"], 17.5)
        self.assertEqual(usage["k2"]["active"], 1)
        status = dict(self.store._conn.execute("SELECT job_id, status FROM usage").fetchall())
        self.assertEqual(status["gone"], "cancelled")
        self.assertEqual(status["done"], "completed")

        today = time.strftime("%Y-%m-%d", time.gmtime())
        self.assertEqual(self.store.usage_by_day(0, "k1"), [
            {"day": today, "jobs": 4, "completed": 1, "skipped": 1, "failed": 1, "saved_bytes": 600},
        ])
        self.assertEqual(self.store.usage_by_key(time.time() + 1), {})

    def test_existing_jobs_are_owned_by_the_environment_token(self):
        path = Path(self.folder.name) / "old.sqlite3"
        conn = __import__("sqlite3").connect(path)
        conn.executescript(
            "CREATE TABLE jobs (id TEXT PRIMARY KEY, status TEXT NOT NULL, progress REAL NOT NULL DEFAULT 0,"
            " message TEXT, source_url TEXT NOT NULL, callback_url TEXT, callback_secret TEXT,"
            " options TEXT NOT NULL, metadata TEXT, input TEXT, output TEXT,"
            " created_at REAL NOT NULL, updated_at REAL NOT NULL);"
            "INSERT INTO jobs VALUES ('old', 'queued', 0, NULL, 'https://x', NULL, NULL, '{}', NULL, NULL, NULL, 1, 1);"
        )
        conn.close()
        store = Store(path)
        self.assertEqual(store.get_job("old")["api_key_id"], ENV_KEY_ID)
        store._conn.close()


class ConfigTests(unittest.TestCase):
    def test_dashboard_settings_are_validated(self):
        from app.config import load_settings

        cases = [
            ({}, "API_TOKEN"),
            ({"API_TOKEN": "short"}, "24 characters"),
            ({**DASHBOARD_ENV, "GITHUB_CLIENT_SECRET": ""}, "GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET"),
            ({**DASHBOARD_ENV, "PUBLIC_URL": ""}, "PUBLIC_URL"),
            ({**DASHBOARD_ENV, "SESSION_SECRET": "short"}, "SESSION_SECRET"),
        ]
        for env, message in cases:
            with self.subTest(env=env), patch.dict(os.environ, env, clear=True):
                with self.assertRaisesRegex(RuntimeError, message):
                    load_settings()

        # Without API_TOKEN the dashboard is the only way to get a key.
        with patch.dict(os.environ, {**DASHBOARD_ENV, "API_TOKEN": ""}, clear=True):
            settings = load_settings()
        self.assertEqual(settings.api_token, "")
        self.assertTrue(settings.dashboard_enabled)


class DashboardTests(unittest.TestCase):
    def setUp(self):
        from fastapi.testclient import TestClient

        self.main = load_main(DASHBOARD_ENV)
        self.folder = tempfile.TemporaryDirectory()
        self.store = Store(Path(self.folder.name) / "jobs.sqlite3")
        app = self.main.app
        app.state.store = self.store
        app.state.runner = SimpleNamespace(enqueue=Mock(), remove=self.store.delete_job, output_path=Mock())
        app.state.caps = Capabilities("test", encoders={"libx264"})
        self.client = TestClient(app)

    def tearDown(self):
        self.store._conn.close()
        self.folder.cleanup()

    def sign_in(self, login="octocat", member=True):
        start = self.client.get("/admin/auth/github", follow_redirects=False)
        self.assertEqual(start.status_code, 303)
        location = urlparse(start.headers["location"])
        query = parse_qs(location.query)
        self.assertEqual(location.netloc, "github.com")
        self.assertEqual(query["scope"], ["read:org"])
        self.assertEqual(query["redirect_uri"], ["http://testserver/admin/auth/github"])

        with patch("app.admin.exchange_code", AsyncMock(return_value="gho_token")), \
                patch("app.admin.fetch_user", AsyncMock(return_value={"login": login, "name": "Octo", "avatar_url": None})), \
                patch("app.admin.is_active_member", AsyncMock(return_value=member)) as membership:
            response = self.client.get(
                "/admin/auth/github", params={"code": "abc", "state": query["state"][0]}, follow_redirects=False
            )
        if member:
            membership.assert_awaited_once_with("JCO-Digital", "gho_token")
        return response

    def csrf(self):
        return {"X-CSRF-Token": self.client.get("/admin/api/session").json()["csrf"]}

    def test_dashboard_requires_github_sign_in(self):
        page = self.client.get("/admin")
        self.assertEqual(page.status_code, 200)
        self.assertIn("Sign in with GitHub", page.text)
        self.assertIn("frame-ancestors 'none'", page.headers["content-security-policy"])
        self.assertEqual(self.client.get("/admin/api/overview").status_code, 401)
        self.assertEqual(self.client.post("/admin/api/keys", json={"name": "x"}).status_code, 401)

        response = self.sign_in()
        self.assertEqual(response.headers["location"], "/admin")
        self.assertIn("path=/admin", response.headers["set-cookie"])
        self.assertIn("httponly", response.headers["set-cookie"].lower())
        self.assertIn('id="create-key"', self.client.get("/admin").text)
        self.assertEqual(self.client.get("/admin/api/session").json()["user"]["login"], "octocat")

        self.client.post("/admin/logout", headers=self.csrf())
        self.assertEqual(self.client.get("/admin/api/session").status_code, 401)

    def test_sign_in_rejections(self):
        self.assertEqual(self.sign_in(member=False).headers["location"], "/admin?error=org")
        self.assertIn("JCO-Digital GitHub organization", self.client.get("/admin?error=org").text)
        self.assertNotIn("<script>", self.client.get("/admin?error=<script>").text)

        response = self.client.get("/admin/auth/github", params={"code": "abc", "state": "forged"}, follow_redirects=False)
        self.assertEqual(response.headers["location"], "/admin?error=state")

        settings = self.main.app.state.settings
        self.main.app.state.settings = type(settings)(**{**settings.__dict__, "admin_users": ("someone",)})
        try:
            self.assertEqual(self.sign_in().headers["location"], "/admin?error=user")
        finally:
            self.main.app.state.settings = settings
        self.assertEqual(self.client.get("/admin/api/session").status_code, 401)

    def test_admin_list_applies_to_existing_sessions(self):
        self.sign_in()
        settings = self.main.app.state.settings
        self.main.app.state.settings = type(settings)(**{**settings.__dict__, "admin_users": ("someone",)})
        try:
            self.assertEqual(self.client.get("/admin/api/session").status_code, 401)
        finally:
            self.main.app.state.settings = settings

    def test_create_use_and_revoke_keys(self):
        self.sign_in()
        self.assertEqual(self.client.post("/admin/api/keys", json={"name": "Site A"}).status_code, 403)
        self.assertEqual(
            self.client.post("/admin/api/keys", json={"name": "Site A"}, headers={"X-CSRF-Token": "wrong"}).status_code,
            403,
        )
        created = self.client.post("/admin/api/keys", json={"name": "Site A"}, headers=self.csrf())
        self.assertEqual(created.status_code, 201)
        key_id, secret = created.json()["key"]["id"], created.json()["secret"]
        self.assertEqual(created.json()["key"]["created_by"], "octocat")
        other = self.client.post("/admin/api/keys", json={"name": "Site B"}, headers=self.csrf()).json()["secret"]

        auth = {"Authorization": f"Bearer {secret}"}
        job = self.client.post("/jobs", json=JOB, headers=auth)
        self.assertEqual(job.status_code, 201)
        job_id = job.json()["id"]
        self.assertNotIn("api_key_id", job.json())
        self.assertEqual(self.client.get(f"/jobs/{job_id}", headers=auth).status_code, 200)
        # Other keys, including the environment token, can't see or delete the job.
        for token in (other, TOKEN):
            with self.subTest(token=token):
                headers = {"Authorization": f"Bearer {token}"}
                self.assertEqual(self.client.get(f"/jobs/{job_id}", headers=headers).status_code, 404)
                self.assertEqual(self.client.delete(f"/jobs/{job_id}", headers=headers).status_code, 404)
        self.assertEqual(self.client.post("/jobs", json=JOB, headers={"Authorization": f"Bearer {TOKEN}"}).status_code, 201)

        overview = self.client.get("/admin/api/overview", params={"days": 7}).json()
        keys = {key["id"]: key for key in overview["keys"]}
        self.assertEqual(keys[key_id]["usage"]["jobs"], 1)
        self.assertEqual(keys[key_id]["usage"]["active"], 1)
        self.assertEqual(keys[ENV_KEY_ID]["usage"]["jobs"], 1)
        self.assertNotIn("key_hash", keys[key_id])
        self.assertEqual(len(overview["daily"][key_id]), 7)
        self.assertEqual(overview["daily"][key_id][-1]["jobs"], 1)
        self.assertEqual(self.client.get("/admin/api/overview", params={"days": 5}).status_code, 422)

        self.assertEqual(self.client.post(f"/admin/api/keys/{key_id}/revoke").status_code, 403)
        revoked = self.client.post(f"/admin/api/keys/{key_id}/revoke", headers=self.csrf())
        self.assertIsNotNone(revoked.json()["revoked_at"])
        self.assertEqual(self.client.get(f"/jobs/{job_id}", headers=auth).status_code, 401)
        self.assertEqual(self.client.post("/admin/api/keys/missing/revoke", headers=self.csrf()).status_code, 404)

    def test_admin_paths_dont_accept_api_keys_and_jobs_dont_accept_sessions(self):
        record, secret = self.store.create_api_key("Site", None)
        self.assertEqual(
            self.client.get("/admin/api/overview", headers={"Authorization": f"Bearer {secret}"}).status_code, 401
        )
        self.sign_in()
        self.assertEqual(self.client.post("/jobs", json=JOB).status_code, 401)

    def test_dashboard_is_off_without_github_settings(self):
        main = load_main({"API_TOKEN": TOKEN, "GITHUB_CLIENT_ID": "", "GITHUB_CLIENT_SECRET": ""})
        paths = {getattr(route, "path", None) for route in main.app.routes}
        self.assertFalse(any(path and path.startswith("/admin") for path in paths))


if __name__ == "__main__":
    unittest.main()
