# WP Checksum Verifier

WordPress core, plugin, and must-use plugin checksum verifier. Detects
tampering by comparing installed files against official checksum manifests
(wp.org core/plugin checksums) and reports unknown files not present in any
manifest.

> **Status**: v0.3.1. The verification engine and all planned execution
> model entry points (WP-CLI, WP-Cron, admin "Run now" button, REST API)
> are implemented. Official theme verification and GitHub-hosted
> plugin/theme verification are not implemented yet. See `CHANGELOG.md` for
> details.

## Verification targets

- **WordPress core**: compared against the official checksums for the
  installed version/locale.
- **Official (wp.org) plugins**: compared against each plugin's official
  checksums for its installed version.
- **Must-use plugins**: scanned for unknown files (no official checksum
  source exists for MU plugins).
- **Unknown files**: files present on disk but absent from the relevant
  manifest are reported as findings, for every target above.
- Not yet implemented: official theme verification, and checksum
  verification for unofficial plugins/themes hosted on GitHub Releases.

## Running a verification

| Mode | How | Notes |
| --- | --- | --- |
| WP-CLI (sync) | `wp wpcv run` | The default; blocks until the run completes. |
| WP-CLI (async) | `wp wpcv run --async` | Enqueues via Action Scheduler when available, otherwise falls back to sync. |
| WP-Cron | automatic | Runs once a day at a configurable UTC time (Settings screen); self-reschedules after each run. |
| Admin button | Settings screen → "Run now" | Schedules an immediate run without blocking the request; disabled when `DISABLE_WP_CRON` is set. |
| REST API | `POST /wp-json/wpcv/v1/run` | For external schedulers (e.g. managed hosting without WP-Cron). See below. |

Every run sweeps and fails any previous run stuck in `running` state
(e.g. after a fatal error mid-run) before starting.

### REST API

`POST /wp-json/wpcv/v1/run` requires a bearer token, issued from the
Settings screen (shown once at generation time; only a salted hash is
stored). Send it as `Authorization: Bearer <token>` (preferred) or
`X-WPCV-Token: <token>`. Query-string tokens are intentionally not
supported. A `WPCV_REST_TOKEN` constant (e.g. in `wp-config.php`) overrides
the token issued from the Settings screen. Repeated authentication failures
from the same IP are rate-limited.

The endpoint is meant to be polled by an external scheduler (e.g. every 5
minutes) and does not start a new run on every call:

- If a run is already in progress (`queued` or `running`), it advances that
  run's chunked execution (the same target/file-level dispatcher WP-Cron and
  WP-CLI use) for up to the configured time budget (Settings screen,
  default 20s) and returns.
- If no run is in progress and the configured daily run time (UTC, the same
  setting used by WP-Cron) has passed and no run has been made for today
  yet, it starts a new run and advances it the same way.
- Otherwise (not yet due, or today's run already exists) it does not start
  anything and reports the most recent run instead.

The response includes `run_id`, `status`, `pending_targets`,
`retry_targets`, and `next_retry_at`, so the caller can tell whether a run
is still in progress and keep polling. The endpoint never runs the global
Action Scheduler queue — only this plugin's own work advances. A busy
response (advisory lock contention) or an internal failure returns an
error instead of a 200.

## Settings

The plugin's settings screen (network admin menu on multisite) lets you
configure: the daily run time (UTC, shared by WP-Cron and the REST
endpoint's due check), the REST endpoint's per-request time budget, and
REST token issuance.

## Distribution

This plugin is not published on the WordPress.org Plugin Directory. It is
distributed via GitHub Releases with a self-update mechanism
(`Update URI: false`, powered by
[l2d-wp-github-update-lib](https://github.com/lunaluna/l2d-wp-github-update-lib)).

## Requirements

- PHP 7.4+
- WordPress 6.8+ (matches the minimum required by the bundled Action Scheduler
  release)

## Development

```sh
composer install
composer run lint     # PHPCS
composer run analyse  # PHPStan
composer run test     # PHPUnit
```

`composer install` alone is enough to make Action Scheduler (async execution,
v0.3+) available locally — no manual copy into `lib/` needed. The plugin picks
it up from `vendor/woocommerce/action-scheduler` automatically in a dev
checkout (see `WPCV_Action_Scheduler_Loader`). The `lib/` copy only exists in
release zips, produced by `bin/build-zip.pre.sh` during the release build.

日本語版は [README-ja.md](README-ja.md) を参照してください。
