# JCORE Pakkaus

The WordPress half of the video optimizer: it sends uploaded videos to the self-hosted FFmpeg service in [`optimizer-service/`](../../optimizer-service), swaps the results into the media library, and shows the library's optimization state under **Settings → Video Optimizer**.

This file covers the plugin's development workflow. What the plugin does, and how to use it, is in [readme.txt](readme.txt); how the whole system fits together is in the [repository README](../../README.md).

## Requirements

- PHP 8.2+
- WordPress 6.7+
- Node 22+ and [pnpm](https://pnpm.io/)
- [Composer](https://getcomposer.org/) and [WP-CLI](https://wp-cli.org/) (WP-CLI is only needed for the translation targets)

## Getting started

```sh
pnpm install
composer install
pnpm build
```

Then either run the full stack with the optimizer service (see [`dev/`](../../dev)), or a throwaway WordPress with just the plugin mounted:

```sh
pnpm playground
```

That serves [WordPress Playground](https://wordpress.org/playground/) on <http://localhost:8883> from `.wp/blueprint.json`, logged in as `admin` / `password` and landing on the plugin's screen. Plugin Check is installed alongside it. Without a reachable optimizer service the screen shows "Service unreachable", which is enough for UI work.

## Scripts

| Command | What it does |
| --- | --- |
| `pnpm build` | Build the admin app and the media library styles into `build/`. |
| `pnpm start` | Same, in watch mode. |
| `pnpm check` | Everything CI lints: ESLint, Stylelint and PHPCS. |
| `pnpm lint:js` / `lint:css` / `lint:php` | One linter at a time. |
| `pnpm format` | Format `src/` with `wp-scripts format`. |
| `composer lint:fix` | Fix what PHPCBF can fix. |
| `pnpm i18n` | Regenerate the POT, the MO files and the JS translation JSON. |
| `pnpm playground` | Serve the plugin in WordPress Playground. |

A `Makefile` wraps the same scripts (`make ci` is what the release workflow runs before packaging); it is a shim, not a second build system.

The PHP regression suite lives at the repository root: `php tests/test_plugin.php`.

## Layout

```
jcore-pakkaus.php              Plugin header, constants, autoloader, bootstrap
uninstall.php                  Removes the settings and job state on delete
includes/
  class-plugin.php             Wires every component to its hooks
  class-settings.php           Options, wp-config.php constants, sanitizing
  class-client.php             HTTP client for the optimizer service
  class-processor.php          Job lifecycle: submit, poll, finalize, restore
  class-library.php            Video list and library summary queries
  class-migration.php          Imports the stand-alone Video Optimizer plugin's data
  class-cli.php                `wp pakkaus` commands
  admin/class-menu.php         Settings > Video Optimizer page and its assets
  admin/class-media.php        Media library column, row and bulk actions, badges
  rest/class-controller.php    Shared namespace and permission check
  rest/class-*-controller.php  callback, settings, service, videos
views/admin/page.php           Mount point for the React app
src/admin/                     The admin app (@wordpress/scripts)
src/media/                     Status badge styles for the media library
languages/                     .po sources; .pot, .mo and .json are generated
```

Classes autoload from the `Jcore\Pakkaus` namespace: `Jcore\Pakkaus\Rest\Videos_Controller` lives in `includes/rest/class-videos-controller.php`.

## REST API

Everything the admin app does goes through `jcore-pakkaus/v1`. All routes require `manage_options`, except `POST /callback`, which the optimizer service calls and which is authenticated by a per-job HMAC signature instead.

| Route | Methods | |
| --- | --- | --- |
| `/callback` | POST | Job state changes from the service |
| `/settings` | GET, POST | The settings; the API token is write-only |
| `/service` | GET | Connection check against the service's `/info` |
| `/videos` | GET | A page of videos (`status`, `page`, `per_page`, `search`) plus the library summary |
| `/videos/optimize-all` | POST | Marks every never-optimized video for the poller |
| `/videos/{id}/optimize` | POST | Sends one video to the service |
| `/videos/{id}/restore` | POST | Puts the backed-up original back |

## Stored data

- Option `jcore_pakkaus_settings` – the settings.
- Option `jcore_pakkaus_db_version` – which migrations have run.
- Post meta `_jcore_pakkaus_*` on video attachments – status, job ID and secret, progress, message, statistics, backup location.
- Cron events `jcore_pakkaus_poll` (every minute, only while jobs are in flight) and `jcore_pakkaus_finalize`.

`Migration` moves the stand-alone plugin's `vopt_settings` option and `_vopt_*` meta to these keys once, and the activation hook deactivates that plugin.

## Hooks

- `jcore_pakkaus_should_optimize` (filter) – return `false` to skip automatic optimization of an upload. Receives the attachment ID.
- `jcore_pakkaus_job_payload` (filter) – the job sent to the service.
- `jcore_pakkaus_optimized` (action) – after an attachment's file was replaced.
- `jcore_pakkaus_url_changed` (action) – after post content was pointed at a new file URL (e.g. `.mov` → `.mp4`), so other storage can follow.

## Releasing

Releases are cut by the repository's `.github/workflows/release.yml` (see the [repository README](../../README.md#versioning-and-releases)): foonver bumps the version in `jcore-pakkaus.php`, `readme.txt` and `package.json`, then the publish job runs `make ci`, scopes the bundled jcore-update library, packages the tree minus `.distignore` as `jcore-pakkaus.zip`, attaches it to a GitHub release and registers it with `update.jcore.fi`.
