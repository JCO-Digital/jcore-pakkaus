=== Video Optimizer ===
Tags: video, compression, ffmpeg, optimization, media
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically optimizes uploaded videos using a self-hosted FFmpeg optimizer service.

== Description ==

When a video is uploaded, the plugin asks your optimizer service to transcode it into a
web-optimized MP4 (H.264 or H.265), then replaces the original file in the media library.

* Automatic optimization on upload, plus bulk and per-video actions
* Downscaling (portrait-aware), frame-rate cap, HDR to SDR tonemapping
* Keeps the original when the result isn't meaningfully smaller
* Optional backups with one-click restore
* WP-CLI: `wp video-optimizer optimize --all`

The optimizer service is a separate Docker container (see the project README).

== Installation ==

1. Upload and activate the plugin.
2. Go to Settings → Video Optimizer and enter the service URL and API token.
3. Click "Test connection".

== Changelog ==

= 1.0.0 =
* Initial release.
