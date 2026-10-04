# Regression checks

From the repository root, with Python 3.13+, PHP 8.2+, and FFmpeg/FFprobe:

```sh
python -m venv .venv
.venv/bin/python -m pip install -r optimizer-service/requirements.txt
.venv/bin/python -m unittest discover -s tests -v
php tests/test_plugin.php
```

The Python suite uses HTTPX mock streaming responses to check absolute download deadlines,
cancellation, size limits, terminal-job expiration, and CRF validation through the API.
Security tests verify authentication before body reads, actual request byte limits,
patched Range parsing, rejection of reference-bearing media manifests, and successful
MP4/MOV/WebM/MKV/AVI processing. Media tests skip when FFmpeg is unavailable locally;
CI also runs the suite inside the production image, where FFmpeg is installed.
The PHP suite runs the actual plugin classes with WordPress HTTP, metadata-cache, and mutex
test doubles. It checks stale responses, poll fairness, retry followed by successful file
replacement, permanent errors, duplicate finalization, settings validation and partial
updates, and bulk queueing. It creates and cleans up its own temporary files; it does not
access a live WordPress site.

From `wordpress-plugin/jcore-pakkaus`, run `pnpm install --frozen-lockfile`,
`pnpm test:security`, and `pnpm audit` to verify the build dependencies. The local
`braces@3.0.3` patch bounds parser and AST recursion to address
GHSA-vfj7-8cjw-p6xm, for which no upstream patch is published. Its four regressions
exercise the actual build dependency, including direct AST input. This single
advisory is excluded from the version-based registry audit because the lockfile
applies the patch; all other advisories remain enforced. Remove the patch and
exception together when a tested upstream fix is available.
