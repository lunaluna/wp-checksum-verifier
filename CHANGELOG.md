# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [0.4.0] - 2026-09-12

Feature release: file-level chunked execution with persistent cursor and
resume (replacing the "1 action = 1 run" model for the async/external-HTTP
paths), a run-level deadline (`aborted` status), a three-layer suppression
engine with a strict mode setting, new REST read endpoints, and three new
admin screens (Findings, Suppressions, Run History).

### Added

- **File-level chunked execution with persistent cursor and resume.** Runs
  enumerate every target up front, then process each one a bounded batch of
  files at a time (bounded by count, elapsed time, and memory headroom),
  saving a cursor after each chunk. A target whose manifest fingerprint or
  version changed since the cursor was saved is reset and retried from
  scratch instead of silently continuing with mismatched data. WP-Cron, CLI
  `--async`, and `POST /run` now drive this dispatcher via Action Scheduler
  actions (or the caller's next poll); synchronous CLI and the admin "Run
  now" button loop it in-process, so results stay identical across every
  mode.
- **Run-level deadline (`aborted` status).** A run that has not reached a
  terminal state within 6 hours (and any of its still-non-terminal targets)
  is marked `aborted`, so a stuck run can no longer block the next
  scheduled run indefinitely.
- **Suppression engine** with three rule types — `exclude_target` (skip
  verification of a plugin/core/MU-plugin entirely), `exclude_path`
  (suppress findings for a specific path within a target), and
  `allowlist_hash` (approve one specific file hash for one specific
  version) — plus a global **strict mode** setting (off by default) that,
  when enabled, stops treating `readme.txt`/`readme.md` changes as a
  suppressed low-risk "soft change".
- **New admin screens**: **Findings** (per-run findings list with
  dimension/status/severity filters and one-click "exclude path" /
  "approve hash" / "exclude target" actions, each requiring a reason and
  taking effect from the next run onward), **Suppressions** (every rule
  ever created, with revoke), and **Run History** (every run with a
  per-target detail view, including translated `error_code` reasons).
- **REST read API**: `GET /wp-json/wpcv/v1/status` (current/last run
  summaries, target-status tallies, next scheduled time) and
  `GET /wp-json/wpcv/v1/findings` (filterable, sortable, paginated), both
  behind a new **read**-scoped bearer token independent of the existing
  **run**-scoped token.
- **Settings screen status panel**: current/last run summaries, next
  scheduled time, WP-Cron/Action Scheduler availability, and the most
  recent CLI-triggered run, without needing to call the REST API.

### Changed

- **`POST /wp-json/wpcv/v1/run` is now meant to be polled**, not called
  once per run. If a run is in progress, it advances that run's chunked
  execution for a configurable time budget (default 20s) and returns; if
  none is in progress, it only starts one if the configured daily run time
  has passed and no run exists yet for today. The response now includes
  `pending_targets`, `retry_targets`, and `next_retry_at` in addition to
  `run_id`/`status`.
- The REST time-budget setting is now the **external HTTP time budget**
  for this polling behavior (distinct from the run-level deadline above),
  replacing the REST queue-draining budget removed in 0.3.1.

### Fixed

Issues found during code review of the chunked-execution work
(`docs/reviews/0.4.0-code-review.md`), all verified against a real
database and filesystem before release:

- A run still in the planning stage (targets not yet enumerated) could be
  reported as complete by a concurrent dispatch call instead of waiting
  for planning to finish.
- A worker whose lease had already expired and been reassigned to another
  worker could still overwrite that other worker's result (no fencing on
  stale writes).
- A failed `$wpdb` insert/update/query (e.g. a value too long for its
  column) was treated as success, committing incomplete data instead of
  rolling back and raising an error.
- Findings from a previous manifest/version were not deleted when a target
  was reset and retried after a mid-run fingerprint/version change,
  leaving stale findings alongside the new ones.
- A failed schema migration could still advance the stored DB version,
  permanently skipping the migration on every later request.
- A failed Action Scheduler enqueue for the next chunk was not detected,
  silently stalling the run instead of failing it.
- The old 3-hour "stale running" sweep (a leftover from the pre-chunking
  "1 action = 1 run" model) could fail a run that was still legitimately
  in progress under the new 6-hour deadline; it has been removed along
  with its dead code.
- Unknown-file scanning ignored the chunk's time/memory budget, risking a
  timeout or out-of-memory error on a target with a very large number of
  files; it now respects the same budget as manifest comparison and yields
  a retry instead.
- A target that finished a manifest comparison successfully did not have
  its `manifest_status` updated from the initial placeholder value.
- Strict mode could only be toggled by editing the database option
  directly; the Settings screen now has a checkbox for it.

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
