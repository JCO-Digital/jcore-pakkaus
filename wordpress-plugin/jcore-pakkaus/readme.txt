=== JCORE Pakkaus ===
Contributors: jcodigital
Tags: video, compression, ffmpeg, optimization, media
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optimizes uploaded videos with a self-hosted FFmpeg optimizer service, and shows the whole library's optimization state on one screen.

== Description ==

When a video is uploaded, the plugin asks your optimizer service to transcode it into a web-optimized MP4 (H.264 or H.265), then swaps the result in for the original file in the media library.

= Features =

* **Automatic optimization** of new uploads, plus per-video, bulk and "optimize all" actions.
* **Video dashboard** under Settings → JCORE Pakkaus: space saved, progress of running jobs, and every video with its status, sizes and actions.
* **Downscaling** (measured on the short side, so portrait videos work), frame-rate cap and **HDR to SDR** tonemapping.
* Keeps the original when the result is not meaningfully smaller.
* Optional **backups** with one-click restore.
* Status badges in the media library list, the attachment edit screen and the media modal.
* **WP-CLI**: `wp pakkaus optimize --all`, `wp pakkaus status`, `wp pakkaus poll --wait`, `wp pakkaus restore`, `wp pakkaus test`.
* **Automatic updates** through the J&Co Digital update service.

= External services =

Videos are transcoded by an optimizer service you run yourself (a Docker container; see the project README). The plugin sends it the URL of each video, the encoding settings and a callback URL on this site; the service downloads the video, and the plugin downloads the result. Nothing is sent anywhere else.

The plugin checks for updates against `https://update.jcore.fi`, operated by J&Co Digital Oy. It sends the plugin slug and the installed version, and the site URL as the request's origin.

= Source code =

The admin screen is built with `@wordpress/scripts`. Its readable source is published at https://github.com/JCO-Digital/jcore-pakkaus.

== Installation ==

1. Upload the `jcore-pakkaus` folder to `/wp-content/plugins/`, or install the zip from the Plugins screen.
2. Activate the plugin.
3. Open **Settings → JCORE Pakkaus → Settings**, enter the service URL and API token, and click **Test connection**.

The URL and token can also be set in `wp-config.php` with `JCORE_PAKKAUS_SERVICE_URL` and `JCORE_PAKKAUS_API_TOKEN`.

== Frequently Asked Questions ==

= I used the stand-alone Video Optimizer plugin. Do I need to do anything? =

No. Activating JCORE Pakkaus deactivates the old plugin and moves its settings, job state, statistics and backups over. The old `VIDEO_OPTIMIZER_SERVICE_URL` and `VIDEO_OPTIMIZER_API_TOKEN` constants keep working. The old plugin can then be deleted.

= Which codec should I pick? =

H.264 plays everywhere. H.265 makes noticeably smaller files, but not every browser can play it.

= What happens to my data when I delete the plugin? =

The settings and the job state are removed. Optimized files, backups of originals and the size statistics stay.

== Changelog ==

= 2.2.0 (2026-10-05) =

* Feature: plugin - default the service URL to pakkaus.prototype.bojaco.com

= v2.1.2 (2026-10-05) =

* Fix: service - make Coolify pick up the required dashboard variables

= v2.1.1 (2026-10-05) =

* Maintenance: service - require the dashboard settings in the Coolify compose file

= v2.1.0 (2026-10-05) =

* Feature: service - add admin dashboard with API keys and usage
* CI: only publish the plugin when it changed

= v2.0.2 (2026-10-04) =

* Fix: address security audit findings

= v2.0.1 (2026-10-03) =

* Maintenance: rename remaining Video Optimizer references to JCORE Pakkaus

= v2.0.0 (2026-10-03) =

* Feature: plugin - rebuild the plugin as JCORE Pakkaus (BREAKING CHANGE)

= v1.0.4 (2026-10-02) =

* Fix: deploy - read the API token from OPTIMIZER_API_TOKEN

= v1.0.3 (2026-10-02) =

* Fix: plugin - keep saved H.264 CRF within the valid range
* Fix: plugin - retry transient result download failures
* Fix: plugin - serialize job state changes per attachment
* Test: add plugin and service regression suites and run them in CI

= v1.0.2 (2026-10-02) =

* Fix: deploy - require API_TOKEN in the compose file

= v1.0.1 (2026-10-02) =

* Fix: plugin - require CRF of at least 1 for H.264
* Fix: service - enforce download timeout and only expire finished jobs
* CI: github - add foonver release pipeline

= v1.0.0 (2026-10-02) =

* Add WordPress video optimizer plugin and FFmpeg optimizer service
