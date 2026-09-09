# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

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
