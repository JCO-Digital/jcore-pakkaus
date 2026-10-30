=== JCORE Pakkaus ===
Contributors: jcodigital
Tags: video, image, webp, compression, optimization
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.6.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optimizes uploaded videos and images with a self-hosted optimizer service, and shows the whole library's optimization state on one screen.

== Description ==

When a video is uploaded, the plugin asks your optimizer service to transcode it into a web-optimized MP4 (H.264 or H.265), then swaps the result in for the original file in the media library.

When a JPEG or PNG image is uploaded, the service makes a WebP version of it and of every resized copy, saved next to each file as `photo.jpg.webp`. The originals stay where they are: your web server sends the WebP version to browsers that support it and the original to everything else. PNG files are also recompressed, as WordPress saves resized PNG files barely compressed.

= Features =

* **Automatic optimization** of new uploads, plus per-file, bulk and "optimize all" actions.
* **Video and image dashboards** under Settings → JCORE Pakkaus: space saved, progress of running jobs, and every file with its status, sizes and actions.
* **Side-by-side comparison** of the original and the optimized video or image.
* **WebP versions** of JPEG and PNG images, served by the browser's `Accept` header, so URLs never change and browsers without WebP support get the original.
* **PNG recompression**, lossy (up to 256 colours) or lossless.
* Pauses image optimization while another image optimization plugin is active, so the two never fight over the same files.
* **Downscaling** (measured on the short side, so portrait videos work), frame-rate cap and **HDR to SDR** tonemapping.
* Keeps the original when the result is not meaningfully smaller.
* Optional **backups** of videos and PNG files with one-click restore.
* Status badges in the media library list, the attachment edit screen and the media modal.
* **WP-CLI**: `wp pakkaus optimize --all`, `wp pakkaus status`, `wp pakkaus poll --wait`, `wp pakkaus restore`, `wp pakkaus serving`, `wp pakkaus test`.
* **Automatic updates** through the J&Co Digital update service.

= External services =

Videos and images are optimized by a service you run yourself (a Docker container; see the project README). The plugin sends it the URLs of each video or image file, the optimization settings and a callback URL on this site; the service downloads the files, and the plugin downloads the results. Nothing is sent anywhere else.

The plugin checks for updates against `https://update.jcore.fi`, operated by J&Co Digital Oy. It sends the plugin slug and the installed version, and the site URL as the request's origin.

= Source code =

The admin screen is built with `@wordpress/scripts`. Its readable source is published at https://github.com/JCO-Digital/jcore-pakkaus.

== Installation ==

1. Upload the `jcore-pakkaus` folder to `/wp-content/plugins/`, or install the zip from the Plugins screen.
2. Activate the plugin.
3. Open **Settings → JCORE Pakkaus → Settings**, enter the service URL and API token, and click **Test connection**.

The URL and token can also be set in `wp-config.php` with `JCORE_PAKKAUS_SERVICE_URL` and `JCORE_PAKKAUS_API_TOKEN`.

== Frequently Asked Questions ==

= How do browsers get the WebP versions? =

The web server picks them. Add this to the Nginx configuration: the `map` in the `http` block, the `location` in the site's `server` block, above any other location that matches images.

`map $http_accept $webp_suffix {
    default        "";
    "~*image/webp" ".webp";
}

location ~* ^/wp-content/uploads/.+\.(?:jpe?g|png)$ {
    add_header Vary Accept;
    try_files $uri$webp_suffix $uri =404;
}`

Then use **Settings → JCORE Pakkaus → Settings → WebP delivery → Check delivery**, or `wp pakkaus serving`. The **WebP test** tab checks the same from your own browser, through any CDN in front of the site. The project's `docs/webp-nginx.md` covers caching headers, CDNs and other upload paths.

= Can I use it alongside another image optimization plugin? =

No. While EWWW Image Optimizer, ShortPixel, Smush, Imagify, Optimole, WebP Express, Converter for Media, TinyPNG, Modern Image Formats or a similar plugin is active, JCORE Pakkaus leaves images alone and shows a warning; deactivate the other plugin to resume. Two optimizers on the same files recompress each other's results, and several write their WebP files under the same names. Videos are optimized either way.

= Are my images changed? =

JPEG files are not. PNG files are recompressed in place unless PNG compression is turned off; turn on *Keep a backup of the original* to be able to restore them. The WebP versions are new files next to the originals, and *Restore original* deletes them.

= I used the stand-alone Video Optimizer plugin. Do I need to do anything? =

No. Activating JCORE Pakkaus deactivates the old plugin and moves its settings, job state, statistics and backups over. The old `VIDEO_OPTIMIZER_SERVICE_URL` and `VIDEO_OPTIMIZER_API_TOKEN` constants keep working. The old plugin can then be deleted.

= Which codec should I pick? =

H.264 plays everywhere. H.265 makes noticeably smaller files, but not every browser can play it.

= What happens to my data when I delete the plugin? =

The settings and the job state are removed. Optimized files, WebP versions, backups of originals and the size statistics stay.

== Changelog ==

= 2.6.2 (2026-10-30) =

* Fix: service - read colon-separated sample aspect ratios

= v2.6.1 (2026-10-29) =

* Fix: plugin - keep bulk optimizing from tying up PHP workers

= v2.6.0 (2026-10-27) =

* Feature: plugin - add WebP test tab for browser-side delivery checks
* Fix: service - contact only public addresses and share queues fairly
* Fix: plugin - only touch stored files in the attachment's own folder
* Documentation: security audit of v2.5.0
* Maintenance: deps - update the service's OpenTelemetry to 1.45.1
* Maintenance: deps - update build tools past new high-severity advisories

= v2.5.0 (2026-10-20) =

* Feature: plugin - optimize images with WebP versions and smaller PNGs
* Feature: service - optimize images on their own queue
* Documentation: explain serving the WebP versions with Nginx

= v2.4.0 (2026-10-13) =

* Feature: plugin - compare optimized videos with originals
* Fix: plugin - enforce high-severity dependency audits

= v2.3.0 (2026-10-05) =

* Feature: service - export OpenTelemetry metrics over OTLP
* Fix: service - start counter series at zero
* Fix: service - label byte counters by job status

= v2.2.0 (2026-10-05) =

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
