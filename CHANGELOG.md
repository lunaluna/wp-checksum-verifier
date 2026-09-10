# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [0.3.1] - 2026-09-10

Patch release: fixes run-lifecycle bugs found while reviewing v0.3.0
(duplicate runs under concurrent requests, lost failure records, a
stalled daily schedule, and a REST endpoint that could execute other
plugins' Action Scheduler actions), plus a couple of multisite/security
follow-ups. No new features. The "1 action = 1 run" execution model is
unchanged; file-level chunking, resume, and a strict per-request HTTP time
budget remain planned for a future release.

### Fixed

- **Run creation moved to acceptance time.** `WPCV_Repository::reserve_run()`
  now creates the `queued`/`running` row (guarded by a MySQL advisory lock)
  before verification starts, instead of after it completes. Previously a
  crash or timeout mid-run left no record of the run at all.
- **Concurrent requests no longer create duplicate runs.** All synchronous
  and Action-Scheduler-enqueuing entry points (CLI, REST, WP-Cron, the "Run
  now" button) now serialize through `reserve_run()`'s advisory lock and
  return the existing run instead of starting a second one.
- **Action Scheduler enqueue failures are no longer reported as success.**
  `as_enqueue_async_action()` returning a non-positive id now marks the run
  `failed` instead of silently returning success. The default availability
  check also verifies `ActionScheduler::is_initialized()`, not just that
  the function exists.
- **A stale-swept run can no longer be overwritten by success later.**
  `finish_run()` and the failure-marking methods now require the run to
  still be in the expected state, so a worker that resumes after being
  marked `failed` by the stale sweep can no longer overwrite it.
- **The daily WP-Cron schedule can no longer be lost permanently.** The next
  occurrence is now (re)scheduled before the run is accepted, so a crash
  mid-run no longer skips it, and every request self-heals a missing
  schedule (previously only plugin activation did).
- **"Run now" no longer collides with the daily schedule.** It now uses its
  own hook (`wpcv_manual_verify`) instead of reusing the daily one, is
  recorded with the `manual` trigger instead of `cron`, and surfaces a
  scheduling failure as an error notice instead of a false "scheduled"
  message.
- **REST no longer executes other plugins' Action Scheduler actions.**
  `POST /wp-json/wpcv/v1/run` no longer calls the site-wide
  `ActionScheduler::runner()->run()`; it now always runs the verification
  synchronously within the request, like the other synchronous entry
  points. A busy state (advisory lock contention) or an internal failure
  now returns an error response instead of a `200`.
- **Rate limiting no longer shares one bucket for every caller without a
  detectable IP.** `WPCV_Rest_Token` now skips rate limiting entirely for
  an empty identifier instead of bucketing unrelated callers together.
- Multisite: the DB schema version is now read from/written to the network
  site option instead of a per-site option, so loading a second site no
  longer re-runs the table migration; the value is migrated from the old
  per-site option on first read. The daily schedule is now registered only
  on the network's main site.

### Removed

- The REST run time budget setting (`Settings` screen and the underlying
  option) is gone, since REST no longer does opportunistic queue draining.
  Sites relying on `POST /wp-json/wpcv/v1/run` must now be small enough to
  complete a full run within a single HTTP request.

## [0.3.0] - 2026-09-09

First tagged release. Covers the initial development milestones (v0.1–v0.3):
the database schema and Public API, the verification engine, and all
planned execution model entry points.

### Added

- Database schema: `wpcv_runs`, `wpcv_target_runs`, `wpcv_findings`,
  `wpcv_suppressions` tables via `WPCV_Migrator` (installation-level,
  `$wpdb->base_prefix`), with `WPCV_Activator` wiring activation and
  `plugins_loaded` upgrade checks.
- `WPCV_Error_Code` (the `error_code` enumeration) and
  `WPCV_Target_Resolver` (`target_id` generation/parsing for core, plugin,
  theme, and must-use plugin targets).
- Public API: `wpcv_get_latest_run()`, `wpcv_get_latest_target_runs()`,
  `wpcv_get_latest_findings()`, `wpcv_is_available()`, plus the
  `wpcv_api_findings` / `wpcv_api_target_runs` filters.
- Admin menu skeleton (top-level settings page; network admin menu on
  multisite).
- Verification engine: `WPCV_File_Hasher`, `WPCV_Path_Normalizer`,
  `WPCV_Source_Core` (WordPress core checksums), `WPCV_Source_Wporg_Plugin`
  (official plugin checksums), `WPCV_Unknown_File_Scanner` (files absent
  from the manifest), and `WPCV_Verifier` (orchestrates verification and
  produces target runs / findings).
- `WPCV_Repository`: persists verification results, and detects/fails
  runs stuck in `running` state via `sweep_stale_running()`.
- `WPCV_Run_Coordinator`: runs one full verification pass (core, official
  plugins, must-use plugins) end-to-end and persists the result.
- Execution model entry points:
  - **WP-CLI**: `wp wpcv run` (synchronous, the default) and
    `wp wpcv run --async` (enqueues via Action Scheduler when available).
  - **Action Scheduler integration**: `WPCV_Action_Scheduler_Loader` and
    `WPCV_Runner_Async` provide a single `enqueue_run()` entry point shared
    by WP-Cron, the "Run now" button, REST, and CLI `--async`, falling back
    to synchronous execution when Action Scheduler is unavailable.
  - **WP-Cron**: `WPCV_Scheduler` runs a self-rescheduling daily run at a
    configurable UTC time (`WPCV_Settings`), sweeping stale runs on every
    fire.
  - **Admin "Run now" button**: schedules an immediate run without
    blocking the request; disabled with guidance when `DISABLE_WP_CRON` is
    set.
  - **REST API**: `POST /wp-json/wpcv/v1/run` (`WPCV_Rest_Run_Controller`)
    for externally-triggered runs (e.g. managed hosting without WP-Cron).
    Idempotent while a run is in progress, and opportunistically drains
    the Action Scheduler queue within a configurable time budget (clamped
    to 70% of `max_execution_time`).
  - **REST token authentication**: `WPCV_Rest_Token` issues a bearer token
    from the settings screen (shown once, stored only as a salted HMAC
    hash), checked via `Authorization: Bearer` or `X-WPCV-Token`, with a
    `WPCV_REST_TOKEN` constant override and failed-attempt rate limiting.
- Settings screen: daily WP-Cron run time (UTC), REST time budget, and
  REST token issuance.
