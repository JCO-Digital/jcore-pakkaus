# Video Optimizer

Automatic video optimization for WordPress, powered by a self-hosted FFmpeg service.

```
┌────────────┐  1. POST /jobs (source URL, options)   ┌──────────────────────┐
│ WordPress  │ ─────────────────────────────────────▶ │  Optimizer service   │
│  + plugin  │ ◀───────────────────────────────────── │  (FastAPI + FFmpeg)  │
│            │  2. GET source video                   │                      │
│            │ ◀───────────────────────────────────── │                      │
│            │  3. POST callback (HMAC signed)        │                      │
│            │ ─────────────────────────────────────▶ │                      │
│            │  4. GET /jobs/{id}/output, DELETE      │                      │
└────────────┘                                        └──────────────────────┘
```

1. A video is uploaded → the plugin sends a job to the service (only the URL, no upload from PHP).
2. The service downloads the video, transcodes it to a web-optimized MP4, and calls WordPress back.
3. The plugin downloads the result and swaps it in for the original attachment file, regenerating metadata.
   If the callback can't get through, WP-Cron polls the service every minute while jobs are in flight.

| Directory | What |
|---|---|
| [`optimizer-service/`](optimizer-service) | The FFmpeg service (Docker, deploy on Coolify) |
| [`wordpress-plugin/video-optimizer/`](wordpress-plugin/video-optimizer) | The WordPress plugin |
| [`dev/`](dev) | Local end-to-end stack (WordPress + MariaDB + service) |

## What the optimization does

- Re-encodes to **H.264** (default, plays everywhere) or **H.265/HEVC** MP4 with a configurable CRF and preset.
- **Downscales** to a max resolution (applied to the short side, so portrait phone videos work; never upscales).
- Honors **rotation** metadata and non-square pixels.
- **Tonemaps HDR** (iPhone HLG / HDR10 PQ) to SDR so it doesn't look washed out in browsers.
- Optional frame-rate cap, AAC audio (copied when already small enough), or audio removal.
- Strips metadata (GPS, device info) and adds `faststart` so playback starts before the download finishes.
- **Keeps the original** if the result isn't at least N % smaller (default 5 %).

---

## 1. Deploy the service on Coolify

1. Push this repository to your Git provider.
2. In Coolify: **New resource → Application → (your repo)**.
   - **Build pack:** `Docker Compose`
   - **Base directory:** `/optimizer-service`
   - **Docker Compose location:** `/docker-compose.yml`
3. **Environment variables:** set `API_TOKEN` to a long random secret:
   ```sh
   openssl rand -hex 32
   ```
4. **Domains:** set the domain for the `optimizer` service with port 8000, e.g.
   `https://video-optimizer.example.com:8000` (Coolify proxies HTTPS on 443 → container port 8000).
5. Deploy. `https://video-optimizer.example.com/health` should return `{"status":"ok",...}`.

> Alternatively use the **Dockerfile** build pack with base directory `/optimizer-service`, port `8000`,
> and add a persistent storage volume mounted at `/data`.

### Service configuration

| Variable | Default | Description |
|---|---|---|
| `API_TOKEN` | **required** | Bearer token clients must send (min. 24 characters). |
| `WORKERS` | `1` | Videos transcoded in parallel. Each FFmpeg uses all cores, so 1–2 is usually right. |
| `FFMPEG_THREADS` | `0` | Threads per FFmpeg (0 = auto). Lower it if you raise `WORKERS`. |
| `MAX_INPUT_MB` | `4096` | Reject sources larger than this. |
| `JOB_TIMEOUT_SECONDS` | `14400` | Kill a transcode that runs longer than this. |
| `JOB_TTL_HOURS` | `24` | Finished jobs and their files are deleted after this (the plugin deletes them sooner). |
| `ALLOWED_HOSTS` | *(any)* | Comma-separated hosts the service may download from / call back to, e.g. `example.com,*.example.com`. Recommended. |
| `CALLBACK_RETRIES` | `5` | Callback attempts (with back-off) before relying on the plugin's polling. |
| `LOG_LEVEL` | `info` | |

Resources: transcoding is CPU-bound. A 1-minute 1080p clip takes roughly 15–60 s on 4 cores with
the `medium` preset. Scratch space under `/data` needs about 2× the largest video.

### API

All endpoints except `/health` require `Authorization: Bearer <API_TOKEN>`. Interactive docs are at `/docs`.

| Method | Path | |
|---|---|---|
| `GET` | `/health` | Liveness (unauthenticated) |
| `GET` | `/info` | FFmpeg version, available codecs, queue size |
| `POST` | `/jobs` | Create a job: `{source_url, callback_url?, callback_secret?, options?, metadata?}` |
| `GET` | `/jobs/{id}` | Job status: `queued → downloading → processing → completed / skipped / failed` |
| `GET` | `/jobs/{id}/output` | Download the optimized MP4 |
| `DELETE` | `/jobs/{id}` | Cancel / delete a job and its files |

Callbacks are `POST`ed as JSON `{event, job}` with the headers `X-Video-Optimizer-Timestamp` and
`X-Video-Optimizer-Signature: sha256=HMAC_SHA256(callback_secret, "<timestamp>.<body>")`.
The plugin generates a fresh `callback_secret` for every job.

---

## 2. Install the WordPress plugin

1. Download `video-optimizer.zip` from the [latest release](../../releases/latest), upload it under
   **Plugins → Add New → Upload Plugin** (or copy `wordpress-plugin/video-optimizer` to
   `wp-content/plugins/`) and activate it.
2. Go to **Settings → Video Optimizer**, enter the service URL and API token, save, and click
   **Test connection**.

You can also put the connection details in `wp-config.php` (the fields are then locked):

```php
define( 'VIDEO_OPTIMIZER_SERVICE_URL', 'https://video-optimizer.example.com' );
define( 'VIDEO_OPTIMIZER_API_TOKEN', '...' );
```

**Requirements:** the service must be able to reach the site (to download the video and send the
callback). Sites behind HTTP basic auth or on `localhost` won't work unless the service can reach them.

### Using it

- New video uploads are optimized automatically (can be turned off; small files can be skipped).
- **Media → Library (list view)** shows a *Video optimization* column with status and savings,
  plus *Optimize video / Re-optimize / Restore original* row actions and an *Optimize videos* bulk action.
- The same status and actions appear in the attachment details sidebar.
- If the extension changes (e.g. `.mov` → `.mp4`) the attachment is renamed and URLs in post content
  are updated. Page builders storing URLs elsewhere can hook into `vopt_url_changed`.
- Turn on *Keep a backup of the original* to be able to restore originals.

### WP-CLI

```sh
wp video-optimizer test                 # check the connection
wp video-optimizer optimize --all       # optimize the existing library
wp video-optimizer optimize 123 456     # specific attachments
wp video-optimizer status               # table of all videos
wp video-optimizer poll --wait          # sync now instead of waiting for WP-Cron
wp video-optimizer restore 123          # restore a backed-up original
```

### Hooks

| Hook | Type | |
|---|---|---|
| `vopt_should_optimize( bool $optimize, int $id )` | filter | Skip auto-optimization for certain uploads |
| `vopt_job_payload( array $payload, int $id )` | filter | Change options per video (e.g. a different CRF) |
| `vopt_optimized( int $id, array $result, array $job )` | action | After the file was replaced |
| `vopt_url_changed( string $old, string $new, int[] $post_ids )` | action | After URLs in content were rewritten |

---

## Versioning and releases

Versioning is handled by [foonver](https://github.com/foonly/foonver) (same setup as the SuperQuest
plugin) via the [`Release`](.github/workflows/release.yml) workflow. On every push to `main` it:

1. lints the PHP (7.4) and builds the service image,
2. computes the next version from the [conventional commits](https://www.conventionalcommits.org/)
   since the last tag — `feat:` → minor, `fix:`/`chore:`/`docs:`/… → patch, `feat!:` or
   `BREAKING CHANGE:` → major,
3. writes it to `version.txt` and syncs it into the plugin header, `VOPT_VERSION`, `readme.txt`'s
   `Stable tag` and the service's `__version__`, regenerates the `== Changelog ==` section of
   `readme.txt`, then commits (`[skip ci]`) and tags `vX.Y.Z`,
4. publishes a GitHub release with `video-optimizer.zip` and the release notes.

So: write conventional commit messages (e.g. `feat(plugin): add poster image generation`,
`fix(service): handle videos without audio`), and never bump versions by hand.
Configuration lives in [`.foonver.toml`](.foonver.toml); preview the next version locally with
`foonver auto --dry-run`.

## Local development

```sh
cd dev
docker compose up -d --build
./setup.sh                                     # installs WP (admin/admin) + activates the plugin
docker compose run --rm wpcli wp media import /samples/your-video.mov
docker compose run --rm wpcli wp video-optimizer status
```

Put test videos in `dev/samples/`. WordPress runs at `http://localhost:8080` (its site URL is
`http://wordpress` so the optimizer container can reach it), the service at `http://localhost:8000/docs`.
