**JCORE Pakkaus security audit — 3 October 2026**

Reviewed the WordPress plugin in `wordpress-plugin/jcore-pakkaus`, its bundled updater, the companion optimizer service, and the build/release configuration. The final source reference is commit `316deb53da67f59f9a0bd249d7ac95d8a97c0fba`. The workspace changed from the legacy plugin to JCORE Pakkaus during the review; findings below refer to JCORE Pakkaus.

Three actionable findings were identified. Severity reflects this application's prerequisites, rather than copying upstream advisory scores. This audit did not modify application code.

**Remediation in `fix/security-audit-findings`**

The changes are based on released `main` (`598418aa`), which contains the same affected service paths. The evidence below records the original audit; it does not describe the patched state.

- **F1 fixed:** ASGI middleware verifies the bearer token before receiving protected request bodies and enforces `MAX_REQUEST_KB` (default 64) against declared and actual byte counts. Exact-limit submissions still work; oversized chunked and understated requests are rejected.
- **F2 fixed for the demonstrated attack:** FFprobe and FFmpeg restrict both input protocols and demuxers to self-contained video formats. Local-reference DASH, HLS and concat inputs are rejected, and MP4/MOV/WebM/MKV/AVI still transcode. Per-job filesystem isolation remains additional defense rather than a claim of this fix.
- **F3 fixed:** FastAPI and Uvicorn are upgraded; Starlette is explicitly pinned to 1.7.0. Malformed Range requests are rejected, and partial and full output downloads work.
- **Build dependencies remediated:** WordPress tooling is upgraded and affected transitives are overridden to patched versions. `braces@3.0.3` has no published upstream fix for GHSA-vfj7-8cjw-p6xm; a lockfile-enforced local patch bounds nesting in parsing, recursive AST walkers and array helpers. Four regressions exercise ordinary patterns, malicious nesting and direct AST input. Only this locally patched advisory is excluded from the registry's version-based audit. The patch is excluded from the release distribution, which contains no Node dependencies.
- CI now audits Python, Composer and Node dependencies, checks the local dependency patch, and runs the service regressions inside the production image. The 19 service tests passed on the host and in that image; the 12 PHP regressions also passed. Python dependency auditing reported no known vulnerabilities.

The additional configuration and defense recommendations below remain deployment or follow-up work; this branch addresses the three demonstrated findings and build dependency advisories.

| ID | Severity | Finding | Prerequisite |
| --- | --- | --- | --- |
| F1 | High | Unbounded job request bodies are buffered and parsed before authentication | Network access to the service; no API token |
| F2 | Medium | Media manifests can read and transcode files outside their job directory | Control of an accepted job source and knowledge of a readable local media path |
| F3 | Medium | The installed Starlette version has a reachable Range-header denial of service | API token and a completed job |

**F1 — Unauthenticated request-body resource exhaustion**

Location: [main.py](optimizer-service/app/main.py), lines 80–81; [models.py](optimizer-service/app/models.py), `JobCreate`; [docker-compose.yml](optimizer-service/docker-compose.yml).

`POST /jobs` authenticates using a FastAPI dependency. In the installed FastAPI implementation, the request handler first calls `request.body()` and `request.json()`, then resolves dependencies. The application supplies no request-body byte limit. `MAX_INPUT_MB` only limits the later video download; it does not limit API JSON. The compose configuration does not establish a request-size limit or a container memory limit.

An unauthenticated client can therefore force allocation and JSON parsing of arbitrarily large bodies before receiving a 401. Concurrent requests can exhaust memory; JSON decoding can also stall the single service event loop. This does not bypass authorization or create an unauthorized job.

**Evidence:** An in-memory ASGI request without an Authorization header received HTTP 401, but instrumentation of the real `Request.json()` recorded that **1,048,653 bytes** had already been buffered and passed to the JSON decoder. The test intentionally used a modest payload and did not attempt to exhaust memory.

**Fix:** Authenticate protected paths in middleware before reading the body. Add a small JSON body limit, appropriate for URL/options payloads, at the proxy and in an ASGI receive wrapper. Count actual received bytes so chunked requests cannot bypass the limit; checking Content-Length alone is insufficient. Also bound metadata size and queue admission. Verify rejection before body parsing with missing/invalid tokens, oversized chunked bodies, and valid small submissions.

**F2 — Media processing escapes the job's input file**

Location: [ffmpeg.py](optimizer-service/app/ffmpeg.py), `probe()` at line 117 and `build_command()` at line 170; [worker.py](optimizer-service/app/worker.py), lines 138–156.

The service downloads a source to `jobs/<id>/input` and gives it to FFprobe and FFmpeg with automatic format detection. A source can be a DASH manifest whose `BaseURL` references a `file:` URL outside the job. The tools open that media as the service user. Neither the HTTP host allowlist nor the download byte limit applies to this secondary file access.

**Evidence:** A mock download returned a 14,515-byte DASH manifest pointing to a synthetic local video outside the job directory. With `ALLOWED_HOSTS` restricted to the mock source host, the real worker completed the job and produced a 2,223-byte MP4 of that outside video. Padding the manifest made the result satisfy the normal savings check. No real private files were accessed.

This establishes local **media** disclosure and a bypass of the intended input boundary. Exploiting another job's media requires knowing its path; UUID job directory names are not predictable. Arbitrary text/credential-file disclosure was not demonstrated. A default WordPress upload path was not tested: WordPress MIME checks may reject the manifest before it reaches the plugin. The confirmed entry point is a token-authenticated service submission with an attacker-controlled source, or another integration that admits such content.

The HTTP-reference variant was also tested: the installed FFmpeg rejected it with `Protocol 'http' not on whitelist 'file,crypto,data'`. Network SSRF through that manifest was **not** demonstrated. FFmpeg's protocol defaults and available restrictions are documented in its [protocol reference](https://ffmpeg.org/ffmpeg-protocols.html).

**Fix:** Restrict input demuxers to the self-contained formats the service supports, for both probing and transcoding. A `file,pipe` protocol allowlist alone still allows this local-file attack. Run media subprocesses with a filesystem view limited to the current job, without access to other jobs, the job database, or unrelated media; deny network access there as well. Reject reference-bearing formats before opening their references. Verify with local-reference manifests and legitimate MP4/MOV/WebM inputs on the deployed FFmpeg build.

**F3 — Vulnerable Starlette file-response Range parsing**

Location: [requirements.txt](optimizer-service/requirements.txt), line 1; [main.py](optimizer-service/app/main.py), lines 108–114.

`fastapi==0.115.12` requires `starlette>=0.40.0,<0.47.0`. The installed version is **0.46.2**. The output endpoint returns Starlette `FileResponse`, which invokes the vulnerable Range-header parser. [GHSA-7f5h-v6xp-fcq8 / CVE-2025-62727](https://github.com/Kludex/starlette/security/advisories/GHSA-7f5h-v6xp-fcq8) affects Starlette 0.39.0 through 0.49.0 and is fixed in 0.49.1.

**Evidence:** Calling the installed parser with malformed headers of 2,008, 4,008, and 8,008 bytes took approximately **0.009, 0.036, and 0.144 seconds**, respectively, demonstrating quadratic growth. Parsing occurs synchronously in the service event loop, so repeated crafted download requests can disrupt other requests and workers. This application's output route requires a valid bearer token, which reduces exposure relative to the upstream unauthenticated scenario.

**Fix:** Upgrade FastAPI to a compatible supported release that permits a patched Starlette, then resolve and record the dependency versions. Simply adding Starlette 0.49.1 conflicts with the current FastAPI pin. Recheck current advisories rather than treating the minimum historical patch as a complete dependency update. Temporarily reject Range headers on the output endpoint if needed; the plugin downloads whole outputs.

**Dependency audit results**

`composer audit --locked --no-interaction --format=json` completed successfully with no reported advisories or abandoned packages. This is a registry advisory check, not proof that the bundled updater is defect-free.

`pnpm audit --json` reported **37 affected package/advisory entries: 20 high, 16 moderate, and 1 low**. Every reported finding is marked as a development dependency. These are build/development risks; they are not 37 demonstrated vulnerabilities in the installed WordPress plugin. The release excludes `node_modules` using [.distignore](wordpress-plugin/jcore-pakkaus/.distignore), and the application source does not directly import the flagged packages. A final release ZIP was not rebuilt or inspected during this audit.

Representative installed packages include `serialize-javascript@6.0.2`, `webpack-dev-server@4.15.2`, `webpack-dev-middleware@5.3.4`, `extract-zip@2.0.1`, `adm-zip@0.5.18`, `minimatch@3.0.8/9.0.3`, and `braces@3.0.3`. For example, [serialize-javascript's code-injection advisory](https://github.com/advisories/GHSA-5c6j-r48x-rmvq) requires attacker-controlled serialization objects followed by evaluation of the result; that exploit path was not established in this build. Upgrade the tooling dependency chain, review remaining advisory prerequisites, and add dependency audits to CI. Major-version overrides should be tested for compatibility.

**Additional configuration and hardening concerns**

- **Cleartext service credentials:** [Settings::sanitize()](wordpress-plugin/jcore-pakkaus/includes/class-settings.php) at line 210 accepts `http` and `https`; [Client::request()](wordpress-plugin/jcore-pakkaus/includes/class-client.php) at line 42 attaches the bearer token to either. Job submissions also contain the callback secret. An HTTP deployment exposes these to observers on the network path. Require HTTPS for production, with an explicit local-development exception. Apply that policy to constant-based configuration as well. Restrict authenticated redirects to the intended origin and prevent HTTPS downgrades.
- **Original backup privacy:** [Processor::replace_file()](wordpress-plugin/jcore-pakkaus/includes/class-processor.php) at lines 575–583 retains backups in the uploads directory. MP4 backups have predictable `.original.mp4` names; non-MP4 originals remain at their previous URLs. On sites serving uploads publicly, `keep_original` leaves unstripped GPS/device metadata publicly available. It defaults to off. Use private backup storage or enforce access controls and explain this retention behavior to administrators. This was established by code review, not a live web-server test.
- **Resource and network containment:** The queue is unbounded and the compose file has no CPU/memory/process/storage limits. `ALLOWED_HOSTS` defaults to unrestricted and, when configured, checks hostnames rather than resolved IP addresses. Configure explicit allowed origins and outbound network restrictions suitable for the deployment, plus capacity limits. No DNS-rebinding exploit was attempted.
- **Defense around file operations:** Backup paths are reconstructed from stored post meta without verifying that the canonical path stays within the uploads directory. No low-privilege way to modify that protected meta was found. Add path containment checks to limit damage from malformed stored state or another vulnerable extension.

**Checks completed and limits**

- The existing PHP suite passed **12 regressions**; the existing Python suite passed **10 tests**.
- Eight focused checks using the actual callback controller passed: valid callback acceptance; forged-signature, changed-body, expired-timestamp, future-timestamp, superseded-job, and wrong-attachment rejection; and denial of a non-administrator by the shared REST permission callback. These used WordPress test doubles, not a live installation.
- Admin REST routes use the shared `manage_options` permission; media actions check nonces and attachment capabilities. Callback verification uses `hash_equals`, per-job secrets, and a five-minute timestamp window. The settings response suppresses the API token. SQL using external values is parameterized, and media command arguments use `create_subprocess_exec` rather than a shell.
- No confirmed WordPress authorization bypass, stored XSS, SQL injection, or shell-command injection was found in the inspected paths.
- The service reproductions ran on the host's FFmpeg `n9.0.2`, Python 3.14 environment, and installed application dependencies. The production Docker image, a live WordPress instance, proxy limits, and deployed FFmpeg version were not tested. Reproduce F2 on the deployment image before relying on version-specific mitigation.

The temporary evidence harnesses are `/tmp/pakkaus-request-audit.py`, `/tmp/pakkaus-local-media-audit.py`, `/tmp/pakkaus-media-audit.py`, and `/tmp/pakkaus-callback-audit.php`. The complete JavaScript advisory response is `/tmp/pakkaus-pnpm-audit.json`. They use synthetic data, mock HTTP or localhost only, and clean up generated media. Temporary files may disappear after this environment is reset.
