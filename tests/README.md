# Regression checks

From the repository root, with Python 3.13+ and PHP 8.2+:

```sh
python -m venv .venv
.venv/bin/python -m pip install -r optimizer-service/requirements.txt
.venv/bin/python -m unittest discover -s tests -v
php tests/test_plugin.php
```

The Python suite uses HTTPX mock streaming responses to check absolute download deadlines,
cancellation, size limits, terminal-job expiration, and CRF validation through the API.
The PHP suite runs the actual plugin classes with WordPress HTTP, metadata-cache, and mutex
test doubles. It checks stale responses, poll fairness, retry followed by successful file
replacement, permanent errors, duplicate finalization, settings validation and partial
updates, and bulk queueing. It creates and cleans up its own temporary files; it does not
access a live WordPress site.
