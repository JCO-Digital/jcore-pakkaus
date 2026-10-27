# JCORE Pakkaus

Automatic video and image optimization for WordPress, powered by a self-hosted service (FFmpeg for
video, Pillow and pngquant for images).

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

1. A video or image is uploaded → the plugin sends a job to the service (only URLs, no upload from PHP).
2. The service downloads the files, optimizes them, and calls WordPress back.
3. The plugin downloads the results and puts them in place: an optimized video replaces the original
   attachment file; images get WebP versions next to their files and smaller PNG files.
   If the callback can't get through, WP-Cron polls the service every minute while jobs are in flight.

| Directory | What |
|---|---|
| [`optimizer-service/`](optimizer-service) | The FFmpeg service (Docker, deploy on Coolify) |
| [`wordpress-plugin/jcore-pakkaus/`](wordpress-plugin/jcore-pakkaus) | The WordPress plugin, **JCORE Pakkaus** ([development notes](wordpress-plugin/jcore-pakkaus/README.md)) |
| [`dev/`](dev) | Local end-to-end stack (WordPress + MariaDB + service) |
| [`docs/`](docs) | [Serving the WebP versions with Nginx](docs/webp-nginx.md) |

## What the optimization does

### Videos

- Re-encodes to **H.264** (default, plays everywhere) or **H.265/HEVC** MP4 with a configurable CRF and preset.
- **Downscales** to a max resolution (applied to the short side, so portrait phone videos work; never upscales).
- Honors **rotation** metadata and non-square pixels.
- **Tonemaps HDR** (iPhone HLG / HDR10 PQ) to SDR so it doesn't look washed out in browsers.
- Optional frame-rate cap, AAC audio (copied when already small enough), or audio removal.
- Strips metadata (GPS, device info) and adds `faststart` so playback starts before the download finishes.
- **Keeps the original** if the result isn't at least N % smaller (default 5 %).

### Images

JPEG and PNG attachments, together with every resized copy WordPress made of them (the unscaled
original of a big upload is left out; it is never shown).

- **WebP versions** are saved next to each file with the extension appended: `photo.jpg` →
  `photo.jpg.webp`. The originals stay untouched, and **Nginx sends the WebP to browsers that accept it
  and the original to everything else**, by the `Accept` header. This needs a few lines of web server
  configuration: see [Serving the WebP versions with Nginx](docs/webp-nginx.md).
  JPEGs become lossy WebP (quality 80); PNGs lossless WebP by default, so graphics and text stay sharp.
- **PNG recompression** replaces the PNG files themselves, for every browser. WordPress saves resized PNG
  files in full colour and barely compressed. *Lossy* (the default) reduces them to a palette of at most
  256 colours with pngquant when that stays within the quality setting, and otherwise falls back to
  *lossless*: the same pixels, with an unused alpha channel dropped, an exact palette where the image has
  256 colours or fewer, and maximum compression. JPEG files are not recompressed.
- The WebP version is made from the recompressed PNG, and each result is only kept if it is at least N %
  smaller (default 5 %) than what it would replace.
- WebP versions are upright (EXIF orientation applied), keep the colour profile and drop other metadata.
- When something rewrites the files (the image editor, regenerating thumbnails), WebP versions that no
  longer match are deleted and the image is optimized again. Deleting a file deletes its WebP version.
- Images have their own queue and workers on the service, so a long video transcode never holds them up.
- **Other image optimizers pause it.** While EWWW Image Optimizer, ShortPixel, Smush, Imagify, Optimole,
  WebP Express, Converter for Media, TinyPNG, Modern Image Formats or a similar plugin is active, image
  optimization does nothing at all: no new jobs, no results put in place, no WebP files deleted (several
  of them name theirs `photo.jpg.webp` too). A warning shows on the plugin screen, the media library and
  the Plugins screen. Videos keep going. Image jobs already sent wait and resume when the plugin is
  deactivated, as long as the service still has them (`JOB_TTL_HOURS`).

---

## 1. Deploy the service on Coolify

1. Push this repository to your Git provider.
2. In Coolify: **New resource → Application → (your repo)**.
   - **Build pack:** `Docker Compose`
   - **Base directory:** `/optimizer-service`
   - **Docker Compose location:** `/docker-compose.yml`
3. **Domains:** give the `optimizer` service its domain, e.g. `https://pakkaus.example.com`. Coolify
   fills `SERVICE_URL_OPTIMIZER` from it, which sets `PUBLIC_URL`, and proxies HTTPS to port 8000.
4. **GitHub OAuth app:** register one under the JCO-Digital organization (*Settings → Developer
   settings → OAuth apps*) with the callback URL `https://pakkaus.example.com/admin/auth/github`. If
   the organization restricts third-party OAuth app access, an owner has to approve the app,
   otherwise every membership check fails.
5. **Environment variables:** fill in the required `OAUTH_GITHUB_CLIENT_ID` and
   `OAUTH_GITHUB_CLIENT_SECRET` (passed to the container as `GITHUB_CLIENT_ID` and
   `GITHUB_CLIENT_SECRET`); the deploy fails without them. Coolify generates the session secret.
   Optionally set `ADMIN_USERS` to limit sign-in to some members.
6. Deploy. `https://pakkaus.example.com/health` should return `{"status":"ok",...}`.
7. Open `https://pakkaus.example.com/admin`, create a key per site and enter it in the plugin's
   settings.

### Dashboard

`/admin` is a dashboard for creating and revoking API keys and for seeing each key's usage (jobs,
failures, video minutes, data processed and saved, processing time, jobs per day). Sign-in is with
GitHub: only active members of `GITHUB_ORG` can sign in (pending invitations don't count).

Each key only sees the jobs it created. Revoking a key stops it working immediately; its usage
history is kept. Usage is recorded per job and outlives `JOB_TTL_HOURS`.

The compose file doesn't pass `API_TOKEN`, so on Coolify only dashboard keys work. Elsewhere,
`API_TOKEN` works alongside the keys and appears in the dashboard as its own row. Its usage
history stays visible after it's removed.

Outside the compose file (local development, the Dockerfile build pack), the dashboard is off unless
`GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, `PUBLIC_URL` and `SESSION_SECRET` are all set, and
`API_TOKEN` is then required.

> Alternatively use the **Dockerfile** build pack with base directory `/optimizer-service`, port `8000`,
> and add a persistent storage volume mounted at `/data`. Then set the variables below yourself,
> including `PUBLIC_URL` and `SESSION_SECRET` (`openssl rand -hex 32`).

### Service configuration

| Variable | Default | Description |
|---|---|---|
| `API_TOKEN` | *(none)* | Bearer token clients can send (min. 24 characters), in addition to dashboard keys. Required when the dashboard is off. Not passed by the compose file. |
| `PUBLIC_URL` | *(none)* | The service's public URL, e.g. `https://pakkaus.example.com`. Required for the dashboard (GitHub callback URL, secure cookies). Set from the Coolify domain by the compose file. |
| `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET` | *(none)* | GitHub OAuth app for the dashboard. Setting both enables `/admin`. **Required** by the compose file, where they're set as `OAUTH_GITHUB_CLIENT_ID` and `OAUTH_GITHUB_CLIENT_SECRET`. |
| `SESSION_SECRET` | *(none)* | Signs dashboard sessions (min. 32 characters). Required for the dashboard; changing it signs everyone out. Generated by Coolify through the compose file. |
| `GITHUB_ORG` | `JCO-Digital` | Only active members of this GitHub organization can sign in to the dashboard. |
| `ADMIN_USERS` | *(any member)* | Comma-separated GitHub usernames allowed to sign in. Checked on every request, so removing someone signs them out. |
| `WORKERS` | `1` | Videos transcoded in parallel. Each FFmpeg uses all cores, so 1–2 is usually right. |
| `FFMPEG_THREADS` | `0` | Threads per FFmpeg (0 = auto). Lower it if you raise `WORKERS`. |
| `IMAGE_WORKERS` | `4` | Image jobs processed in parallel, from their own queue. Each takes about one core while it encodes. |
| `MAX_INPUT_MB` | `4096` | Reject video sources larger than this. |
| `MAX_IMAGE_MB` | `50` | Skip image files larger than this. |
| `MAX_IMAGE_MEGAPIXELS` | `50` | Skip images with more pixels than this, checked from the header before decoding. |
| `MAX_REQUEST_KB` | `256` | Maximum API JSON body size. Authentication runs before the body is read; the limit includes chunked requests. An image job with 100 files fits comfortably. |
| `JOB_TIMEOUT_SECONDS` | `14400` | Kill a transcode that runs longer than this. |
| `IMAGE_JOB_TIMEOUT_SECONDS` | `600` | Fail an image job (all its files, downloads included) that takes longer than this. |
| `IMAGE_DOWNLOAD_TIMEOUT_SECONDS` | `60` | Give up on downloading one image file after this. Video downloads use `DOWNLOAD_TIMEOUT_SECONDS` (default 1800). |
| `MAX_JOBS_PER_KEY` | `50` | Unfinished (queued or running) video jobs one API key may have. More get HTTP 429, and the plugin sends them later. `0` = no limit. |
| `MAX_IMAGE_JOBS_PER_KEY` | `500` | The same for image jobs. Waiting jobs are taken from each key in turn, so one site's backlog doesn't hold up the others. |
| `JOB_TTL_HOURS` | `24` | Finished jobs and their files are deleted after this (the plugin deletes them sooner). |
| `ALLOWED_HOSTS` | *(any)* | Comma-separated hosts the service may download from / call back to, e.g. `example.com,*.example.com`. Recommended. |
| `ALLOW_PRIVATE_NETWORKS` | `false` | Downloads and callbacks only go to public addresses: host names resolving to loopback, private, link-local (e.g. cloud metadata) or other non-public addresses are refused. Set to `true` when the sites are on the same private network as the service. Redirects are followed for downloads only, at most 5, each checked the same way. |
| `CALLBACK_RETRIES` | `5` | Callback attempts (with back-off) before relying on the plugin's polling. |
| `LOG_LEVEL` | `info` | |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | *(none)* | OTLP/HTTP endpoint for metrics, e.g. `http://prometheus:9090/api/v1/otlp`. Exports HTTP request metrics and `pakkaus_*` job, byte, callback and queue metrics. Unset disables export. |
| `OTEL_SERVICE_NAME` | `pakkaus` | The `job` label on exported metrics. The default comes from the compose file. |

Resources: transcoding is CPU-bound. A 1-minute 1080p clip takes roughly 15–60 s on 4 cores with
the `medium` preset. Scratch space under `/data` needs about 2× the largest video.

### API

All endpoints except `/health` require `Authorization: Bearer <key>`, with a key from the dashboard or
`API_TOKEN`. Jobs are only visible to the key that created them. Interactive docs are at `/docs`.

| Method | Path | |
|---|---|---|
| `GET` | `/health` | Liveness (unauthenticated) |
| `GET` | `/info` | FFmpeg version, available codecs, queue size |
| `POST` | `/jobs` | Create a video job: `{source_url, callback_url?, callback_secret?, options?, metadata?}` |
| `POST` | `/jobs/images` | Create an image job: `{files: [{name, url}, …], callback_url?, callback_secret?, options?, metadata?}` (up to 100 files) |
| `GET` | `/jobs/{id}` | Job status: `queued → downloading → processing → completed / skipped / failed` |
| `GET` | `/jobs/{id}/output` | Download the optimized MP4 |
| `GET` | `/jobs/{id}/files/{index}/{variant}` | Download one result of an image job: `optimized` (the PNG, smaller) or `webp` |
| `DELETE` | `/jobs/{id}` | Cancel / delete a job and its files |

Callbacks are `POST`ed as JSON `{event, job}` with the headers `X-Jcore-Pakkaus-Timestamp` and
`X-Jcore-Pakkaus-Signature: sha256=HMAC_SHA256(callback_secret, "<timestamp>.<body>")`. The same values are
also sent as `X-Video-Optimizer-Timestamp` / `X-Video-Optimizer-Signature` for the stand-alone Video Optimizer plugin.
The plugin generates a fresh `callback_secret` for every job.

An image job's `output.files` lists one entry per file, in the order sent, with its `size`, `format`,
dimensions and the `variants` that were kept (`{"webp": {"size": …}, "optimized": {"size": …}}`), or an
`error` for a file that couldn't be processed; the others still are. The job is `skipped` when nothing
got smaller enough, and `failed` only when every file failed. `output.size` is what a browser that
takes WebP downloads in total.

Video inputs must be self-contained video containers or elementary video streams.
MP4/MOV, WebM/Matroska, AVI, MPEG, MPEG-TS, FLV, Ogg, ASF, H.264, HEVC and M4V
are accepted. DASH/HLS playlists, concat manifests and image sequences are rejected
by both probing and transcoding to prevent references to files outside the input.
Image inputs must be JPEG or PNG; Pillow is restricted to those two decoders, and pngquant only runs on
files Pillow has already accepted.

---

## 2. Install the WordPress plugin

The plugin is a JCORE plugin, **JCORE Pakkaus** (`jcore-pakkaus`). It requires PHP 8.2+ and WordPress 6.7+,
and updates itself through `update.jcore.fi` like the other JCORE plugins.

1. Download `jcore-pakkaus.zip` from the [latest release](../../releases/latest), upload it under
   **Plugins → Add New → Upload Plugin** and activate it.
2. Go to **Settings → JCORE Pakkaus → Settings**, enter the API token (and the service URL, if not
   using the default `https://pakkaus.prototype.bojaco.com`), and click **Save and test**.

You can also put the connection details in `wp-config.php` (the fields are then locked):

```php
define( 'JCORE_PAKKAUS_SERVICE_URL', 'https://pakkaus.example.com' );
define( 'JCORE_PAKKAUS_API_TOKEN', '...' );
```

**Requirements:** the service must be able to reach the site (to download the files and send the
callback). Sites behind HTTP basic auth or on `localhost` won't work unless the service can reach them.

**For WebP:** add the [Nginx rules](docs/webp-nginx.md) to the site, then check them under
**Settings → JCORE Pakkaus → Settings → WebP delivery**.

**Coming from the stand-alone "Video Optimizer" plugin?** Activating JCORE Pakkaus deactivates it and
moves its settings, job state, statistics and backups over; the old `VIDEO_OPTIMIZER_*` constants keep
working. Delete the old plugin afterwards.

### Using it

- New video and image uploads are optimized automatically (each can be turned off; small videos can be
  skipped).
- **Settings → JCORE Pakkaus** has a *Videos* and an *Images* tab: the library at a glance (count,
  optimized share, space saved, running jobs), every attachment with its status, sizes and actions, a
  side-by-side comparison of original and optimized, and an *Optimize all* button for files uploaded
  before the plugin was set up. Running jobs update live.
- **Media → Library (list view)** shows an *Optimization* column with a status badge and savings,
  plus *Optimize / Re-optimize / Restore original* row actions and an *Optimize videos and images* bulk
  action.
- The same status and actions appear in the attachment details sidebar.
- If the extension changes (e.g. `.mov` → `.mp4`) the attachment is renamed and URLs in post content
  are updated. Page builders storing URLs elsewhere can hook into `jcore_pakkaus_url_changed`.
- Turn on *Keep a backup of the original* to be able to restore original videos and PNG files
  (`logo.png` is kept as `logo.original.png`). *Restore original* on an image also deletes its WebP
  versions.

### WP-CLI

```sh
wp pakkaus test                       # check the connection
wp pakkaus optimize --all             # optimize the existing library
wp pakkaus optimize --all --type=image  # only images (or --type=video)
wp pakkaus optimize 123 456           # specific attachments
wp pakkaus status [--type=image]      # table of all videos and images
wp pakkaus poll --wait                # sync now instead of waiting for WP-Cron
wp pakkaus restore 123                # restore a backed-up original / delete an image's WebP versions
wp pakkaus serving                    # check that the web server hands out the WebP versions
```

### Hooks

| Hook | Type | |
|---|---|---|
| `jcore_pakkaus_should_optimize( bool $optimize, int $id )` | filter | Skip auto-optimization for certain uploads |
| `jcore_pakkaus_conflicting_plugins( array $plugins )` | filter | Plugin directories (`slug => name`) that pause image optimization while active |
| `jcore_pakkaus_job_payload( array $payload, int $id )` | filter | Change options per video (e.g. a different CRF) |
| `jcore_pakkaus_image_job_payload( array $payload, int $id )` | filter | Change options per image; keep the files in order |
| `jcore_pakkaus_optimized( int $id, array $result, array $job )` | action | After the results were put in place |
| `jcore_pakkaus_url_changed( string $old, string $new, int[] $post_ids )` | action | After URLs in content were rewritten |

---

## Versioning and releases

Versioning is handled by [foonver](https://github.com/foonly/foonver) (same setup as the SuperQuest
plugin) via the [`Release`](.github/workflows/release.yml) workflow. On every push to `main` it:

1. lints the plugin (PHPCS, ESLint, Stylelint), builds its assets, runs Plugin Check against what
   ships, runs both regression suites and builds the service image,
2. computes the next version from the [conventional commits](https://www.conventionalcommits.org/)
   since the last tag — `feat:` → minor, `fix:`/`chore:`/`docs:`/… → patch, `feat!:` or
   `BREAKING CHANGE:` → major,
3. writes it to `version.txt` and syncs it into the plugin header, `JCORE_PAKKAUS_VERSION`, the
   plugin's `package.json`, `readme.txt`'s `Stable tag` and the service's `__version__`, regenerates
   the `== Changelog ==` section of `readme.txt`, then commits (`[skip ci]`) and tags `vX.Y.Z`,
4. if anything under `wordpress-plugin/jcore-pakkaus` changed since the previous tag, builds the
   plugin (`make ci`), scopes its bundled [jcore-update](https://github.com/JCO-Digital/jcore-update)
   library the same way the shared JCORE publish workflow does, publishes a GitHub release with
   `jcore-pakkaus.zip` and registers the version with `update.jcore.fi` (needs the `UPDATE_API_KEY` secret).
   Service-only changes still get a version and tag, but no plugin release, so sites aren't offered
   an update that changes nothing. Published plugin versions can therefore skip numbers.

So: write conventional commit messages (e.g. `feat(plugin): add poster image generation`,
`fix(service): handle videos without audio`), and never bump versions by hand.
Configuration lives in [`.foonver.toml`](.foonver.toml); preview the next version locally with
`foonver auto --dry-run`.

## Local development

```sh
(cd wordpress-plugin/jcore-pakkaus && pnpm install && composer install && pnpm build)
cd dev
docker compose up -d --build
./setup.sh                                     # installs WP (admin/admin) + activates the plugin
docker compose run --rm wpcli wp media import /samples/your-video.mov
docker compose run --rm wpcli wp pakkaus status
```

Put test videos in `dev/samples/`. WordPress runs at `http://localhost:8080` (its site URL is
`http://wordpress` so the optimizer container can reach it), the service at `http://localhost:8000/docs`.
