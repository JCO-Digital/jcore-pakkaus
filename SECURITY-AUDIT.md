**JCORE Pakkaus security audit — 26 October 2026**

Reviewed the whole repository at `main` commit `4753042f` (v2.5.0):

- the WordPress plugin in `wordpress-plugin/jcore-pakkaus`, its PHP and admin JavaScript;
- the optimizer service in `optimizer-service`;
- the build, dependency and release configuration;
- the dev environment.

This release adds the image pipeline (WebP versions and smaller PNGs), per-site API keys and the service dashboard. The previous audit (3 October 2026, commit `316deb53`) is in git history; its F1–F3 fixes were rechecked here.

No unauthenticated path into the plugin or the service was found. This document covers the findings fixed or mitigated on this branch (A1, A4, A5 and A6). Further findings are tracked privately and will be added here once fixed.

**Remediation in `fix/security-audit-2026-10`**

The evidence below records the original audit; it does not describe the patched state.

- **A1 fixed:** a new `Uploads` helper rejects stored paths that are absolute, contain `..` or a colon, or are not in the attachment's own folder. Image records must name JPEG/PNG files, and kept image originals must be named `*.original.png`. Video backups and restore targets must have a video file type. Records that fail the checks are ignored, so they are never read, renamed or deleted. Imports and exports skip `_jcore_pakkaus_*` post meta. Three PHP regressions reproduce the attack paths; they fail on the old code. Its tests are in the PHP suite.
- **A4 fixed:** the HTTP client no longer follows redirects and asks for uncompressed responses. `_download()` follows at most five redirects, closing each one without reading its body. Callbacks are streamed and closed unread, each attempt has a 30 s overall deadline, and 3xx responses are not retried. Checked with a real local server: a 400 MiB gzip redirect body and callback body left peak memory unchanged.
- **A5 fixed for addresses:** every download and callback goes through `GuardedTransport`. It checks `ALLOWED_HOSTS`, resolves the host, and refuses the request if any address is non-public (loopback, private, link-local, CGNAT, multicast, reserved, and IPv4 inside IPv6). It then connects to the checked address, keeping the original Host header and TLS SNI, so DNS rebinding cannot swap the address. Environment proxies are ignored. `ALLOW_PRIVATE_NETWORKS=true` restores private access; the dev stack sets it. Non-public IP literals are rejected with 422 at submission. Connection errors in job messages are reduced to "Could not connect to the source". A real HTTPS fetch through the pinned address verified the certificate. Traffic amplification to public hosts and the HTTP-status oracle for public hosts remain; A6's quotas would limit the former.
- **A6 mitigated:**
  - Image jobs have an overall deadline (`IMAGE_JOB_TIMEOUT_SECONDS`, default 600). When it passes, a running pngquant is killed and the job fails.
  - Each image download has its own timeout (`IMAGE_DOWNLOAD_TIMEOUT_SECONDS`, default 60).
  - Both queues take waiting jobs from each API key in turn, so one key's backlog only delays its own jobs.
  - Unfinished jobs per key are capped (`MAX_JOBS_PER_KEY`, default 50 videos; `MAX_IMAGE_JOBS_PER_KEY`, default 500 images). Further submissions get HTTP 429 with `Retry-After`.
  - The plugin treats 429 as "busy": the file stays pending without using up an attempt, and no more files of that kind are sent until the next poll.
  - Remaining: Pillow work running when the deadline passes finishes in its thread (seconds per image), and video jobs keep their 30-minute download and 4-hour transcode limits, so a single video worker is still shared fairly but not quickly.
- New service tests are in `tests/test_network.py` (13) and `tests/test_fairness.py` (6). The Python suite runs 68 tests, all passing, with 1 skipped (pngquant not installed). The PHP suite passes 28/28.

Severity reflects this application's prerequisites. "API key" means any valid service key; keys are issued per site, so a compromised WordPress admin has one.

| ID | Severity | Area | Finding | Prerequisite |
| --- | --- | --- | --- | --- |
| A1 | Medium (High on multisite) | Plugin | Stored backup paths are not contained to uploads: arbitrary file read, delete and overwrite | `import` capability plus a later view/restore/delete, or another way to write post meta |
| A4 | Medium | Service | Redirect and callback response bodies are decompressed into memory without limit | API key, or control of a source or callback host |
| A5 | Medium | Service | SSRF to any host by default, with an error oracle and media read-back | API key |
| A6 | Medium | Service | One key holder can stall the image and video queues for every tenant | API key and a slow HTTP server |

**A1 — Stored backup paths are not contained to the uploads directory**

Location: [class-images.php](wordpress-plugin/jcore-pakkaus/includes/class-images.php) `path()` at line 634, `original_path()` at 326, `restore()` at 301, `delete_backups()` at 359; [class-processor.php](wordpress-plugin/jcore-pakkaus/includes/class-processor.php) `backup_path()` at 840, `restore()` at 771, `on_delete_attachment()` at 874; [class-media.php](wordpress-plugin/jcore-pakkaus/includes/admin/class-media.php) `handle_original()` at 147.

Both helpers build absolute paths with `path_join( $uploads['basedir'], <post meta value> )`. `path_join` passes absolute paths through unchanged and does not resolve `../`. The results are used to:

- read: `handle_original()` streams `Images::original_path()` with `readfile`;
- delete: on attachment deletion and when removing image backups;
- rename over another file: video and image restore.

The previous audit noted the missing containment but found no low-privilege way to write the meta. There is one now: the WordPress Importer imports every post meta key except a short core list (`is_valid_meta_key`), so a WXR file can carry `_jcore_pakkaus_backup` and `_jcore_pakkaus_stats`. An attachment with `stats.files[0] = { file: "../index.php", backup: "2026/10/payload.png" }` turns "Restore original" into moving an uploaded PNG with appended PHP over `wp-content/index.php`. A `backup` of `../../wp-config.php` makes the compare view stream `wp-config.php`. Deleting the attachment deletes the referenced file.

On multisite a site admin (not a super admin) has `import` and can do every step themselves. On a single site it needs an admin to import a malicious or "demo content" WXR file and later restore, compare or delete.

**Evidence:** using the real plugin classes on WordPress test doubles, `original_path()` returned `uploads/../wp-config.php` and its contents were read. Video `restore()` moved `wp-config.php` to `uploads/2026/10/leak.txt`. `Images::restore()` overwrote `index.php` with PNG bytes followed by `<?php`. Deleting the attachment removed a file outside uploads. The importer step is code review of wordpress-importer; it was not run end to end on a live site.

**Fix:** resolve every meta-derived path with `realpath()` and require it to sit inside `realpath( basedir )`. For backups, also require the attached file's directory and a `.original.` name. Store basenames only, and delete through `wp_delete_file_from_directory()`. Skip `_jcore_pakkaus_*` keys on import with the `import_post_meta_key` filter and on export with `wxr_export_skip_postmeta`.

**A4 — Redirect and callback response bodies are decompressed into memory without limit**

Location: [worker.py](optimizer-service/app/worker.py) lines 54–59 (shared `httpx` client with `follow_redirects=True`), `_download` at 348, `_send_callback` at 394.

httpx reads each redirect response body with `aread()` before following it. That happens before the `MAX_INPUT_MB` and `MAX_IMAGE_MB` checks, which only apply to `aiter_raw()` on the final 200 response. Callback responses are also read in full. Both bodies are decompressed according to `Content-Encoding`. A source URL that returns a 302 with a gzip body, or a callback URL that returns a gzip body, makes the service hold the decompressed size in memory, and up to 20 redirect hops multiply it. Compose sets no memory limit, so an out-of-memory kill ends every tenant's in-flight jobs.

Callbacks also have no overall deadline: each read has a 30 s timeout, so a server sending one byte every 29 s holds the callback task open indefinitely.

**Evidence:** a 398 KiB gzip body that decompresses to 400 MiB raised peak service memory from 57 MiB to 870 MiB, in both the redirect and the callback case. The image job still completed after following the redirect.

**Fix:** use separate clients per purpose with `follow_redirects=False`, and follow redirects manually. Re-check the host on every hop, never read redirect bodies, and send `Accept-Encoding: identity`. Stream callback responses and discard the body, or read at most a few KB. Wrap each callback attempt in `asyncio.timeout`.

**A5 — SSRF to any host by default**

Location: [config.py](optimizer-service/app/config.py) lines 387–390; [main.py](optimizer-service/app/main.py) lines 119–121 and 145–148; [worker.py](optimizer-service/app/worker.py) lines 77–80 and 320.

An empty `ALLOWED_HOSTS`, the compose default, allows every host. With an API key:

- **Network scanning:** `/jobs/images` accepts up to 100 URLs per job. Each file's `error` in `GET /jobs/{id}` distinguishes success, non-image content, HTTP status, connection refused and unknown host. That probes `127.0.0.1`, `169.254.169.254`, Docker and private networks.
- **Read-back:** internal endpoints that serve images or videos can be downloaded re-encoded through `/jobs/{id}/files/N/webp` or `/output`.
- **Blind POSTs:** `callback_url` makes the service POST JSON containing attacker-controlled `metadata` to any host, retried up to five times on 5xx.
- **Traffic amplification:** 100 × 50 MB per image job, or 4 GB per video job, from third-party hosts.

URL schemes are limited to http/https, and the host check runs on every redirect hop; a redirect from an allowed `localhost` to `127.0.0.1` was blocked when `ALLOWED_HOSTS=localhost`. With an allowlist configured, the check still compares hostnames rather than resolved addresses, so DNS rebinding remains possible. This was not tested.

**Evidence:** against local test endpoints, the per-file errors read "webp produced", "cannot identify image file '/tmp/.../jobs/j/files/1.source'", "failed with HTTP 404", "All connection attempts failed" and "[Errno -2] Name or service not known".

**Fix:** resolve the hostname and reject loopback, link-local, private, CGNAT, multicast and reserved addresses, then pin the connection to the checked IP (for example with a custom httpx transport). Recommend a non-empty `ALLOWED_HOSTS` or add per-key allowed origins. Collapse network errors into one generic message. Block egress to internal ranges at the network level as well.

**A6 — One key holder can stall the queues for every tenant**

Location: [worker.py](optimizer-service/app/worker.py) lines 46–47 (unbounded queues), 240–256 (no image-job deadline), 346 (per-file download deadline), Pillow work in `asyncio.to_thread` at 287, 308, 330 and 340; [models.py](optimizer-service/app/models.py) line 48 (`MAX_IMAGE_FILES=100`).

`job_timeout` applies only to FFmpeg. An image job with 100 slow-drip URLs holds an image worker for about 100 × 1800 s ≈ 50 hours, so four requests block all four default image workers. With the single default video worker, one slow download followed by a 4-hour transcode blocks every video job. Queues are first-in first-out with no per-key quotas or rate limits, and outputs and the database have no disk quota.

**Evidence:** with `DOWNLOAD_TIMEOUT_SECONDS=2`, one job with four slow files held its worker for 8.0 s; the time grows linearly with the file count.

**Fix:** give image jobs an overall deadline and a much shorter per-file download timeout (for example 60 s). Bound both queues and return 429/503 when full. Cap queued and active jobs per key. Run Pillow work in a subprocess that can be killed on timeout.

**Previous audit items**

- **F1 (body read before authentication): still fixed**, including on `/jobs/images`. Unauthenticated requests get 401 without the body being read. Oversized declared and chunked bodies get 413. The guard covers every non-public path, and `require_token` still checks `request.state.api_key_id`.
- **F2 (FFmpeg reading local files through manifests): still fixed** on host FFmpeg n9.0.2. The allowlisted formats have no external references, and the image pipeline does not use FFmpeg.
- **F3 (Starlette Range DoS): still fixed.** Starlette is 1.7.0, malformed Range headers get 400, and partial downloads work, including image outputs.
- **Path containment for backups:** fixed, see A1.
- The previous audit's other hardening recommendations remain open and are tracked privately.

**Checked and found sound**

- **Plugin REST:** every route requires `manage_options` except the signed callback; there is no `__return_true`. Arguments are typed, and enums are used.
- **Callback:** HMAC-SHA256 over `timestamp.body` with `hash_equals`, a 40-character random secret per job, and the job/attachment pairing checked against meta. The callback body is never used as a download URL: `completed` re-fetches the job, and download paths are fixed (`/jobs/{id}/output`, `/files/{int}/{allowlisted variant}`).
- **Plugin file writes:** names are fixed (`<file>.webp`, the same path, or `.mp4`), magic bytes are checked (ftyp, PNG, RIFF/WEBP), image work is staged all-or-nothing, and files are checked for changes by size and mtime.
- **Media actions:** nonces plus `upload_files` and per-ID `edit_post`, with no `nopriv` handlers. `handle_original` sends `nosniff` and an extension-based type.
- **Escaping and SQL in the plugin:** the media column, modal fields, notices and the `confirm()` attribute are escaped. SQL is prepared and `LIKE` values are escaped. The token never reaches REST responses or JavaScript.
- **Admin JavaScript:** no `dangerouslySetInnerHTML`, `innerHTML`, jQuery, `eval` or `window.open`. Service errors, filenames and titles render as React text. Every `href` and `src` comes from WordPress functions with fixed prefixes. The `?tab=` value is allowlisted, and `apiFetch` uses core's nonce middleware. PHP-to-JS data uses `wp_json_encode`.
- **Service authentication:** the environment token is compared with `hmac.compare_digest`. API keys are 256-bit random values stored as SHA-256 hashes. An empty `API_TOKEN` is never accepted, and revoked keys are rejected immediately.
- **Tenant isolation in the service:** get, delete, `/output` and `/files` check `api_key_id`, and another key gets 404. `variant` must be a key in the server-written variants, so traversal gets 404. Job IDs are `uuid4().hex`.
- **Service SQL:** every value is parameterized; f-string column and table names come only from code constants.
- **Dashboard:** CSRF uses a session token plus a custom header, with SameSite=Lax and no CORS. OAuth `state` is bound to the session and compared in constant time. The CSP is strict (`default-src 'self'`, `frame-ancestors 'none'`). `dashboard.js` builds the page only with `textContent` and `append`.
- **Image formats:** `Image.open` is limited to JPEG and PNG, so SVG, PDF, PSD, ICO and GIF are rejected. Animated PNGs and multi-frame JPEGs are skipped. pngquant, FFmpeg and FFprobe run from argument lists, with `--` and timeouts, never through a shell.
- **Telemetry:** only counters and gauges labelled by status, codec, kind and result, plus request metrics by route template and status. No URLs, IPs, metadata or secrets. Nothing is exported unless `OTEL_EXPORTER_OTLP_ENDPOINT` is set.
- **Build and release:** the production build has no source maps. The lockfile uses registry sources only, with integrity hashes. The braces patch is pinned by hash and `pnpm test:security` passes 4/4. The release zip excludes `src/`, `node_modules/`, `patches/`, `.wp/blueprint.json`, lock and manifest files, `Makefile` and `webpack.config.js`. `dev/` and `tests/` are outside the plugin directory and cannot ship.
- **CI:** no `pull_request_target` or `workflow_run` triggers, and no `${{ github.event.* }}` in `run:` steps. The publish job uses no cache and checks out the exact release tag.
- **Secrets:** the tracked tree and full git history contain no real tokens, keys or `.env` files; only dev and test placeholders.
- **Tests:** the Python suite ran 49 tests, all passing, with 1 skipped because pngquant is not installed on the host. The PHP suite passed 24/24 on PHP 8.4.27.

**Not verified**

- The production Docker image: the F2 fix on Debian's FFmpeg, and Debian's pngquant, libpng and FFmpeg advisories.
- pngquant code paths (not installed on the host).
- Pathological progressive JPEGs at the pixel limit.
- A live WordPress site running the importer chain for A1, and whether multisite site admins can use the importer (depends on network settings).
- DNS rebinding, real cloud-metadata reachability, and the Coolify/Traefik proxy's header and body limits.

Evidence harnesses used synthetic data and localhost only: `/tmp/pakkaus-audit-service/` (service experiments) and `/tmp/pakkaus-audit-plugin/` (PHP harness and Nginx container configs). Test containers were removed. Temporary files may disappear after this environment is reset.
