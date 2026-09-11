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

### Execution model

A run does not verify every target in one pass. It first enumerates every
target (core, each official plugin, must-use plugins) into per-target rows
tracked in the database, then processes them one file-chunk at a time:

- Each chunk verifies a bounded batch of files (bounded by count, elapsed
  time, and memory headroom) and saves a cursor (the last verified path,
  plus a fingerprint of the manifest and the target's version) before
  yielding. The next chunk resumes from that cursor.
- If the manifest fingerprint or the target's version changed since the
  cursor was saved (e.g. the plugin was updated mid-run), the target is
  reset and retried from scratch rather than silently continuing with
  possibly-mismatched data.
- WP-Cron, CLI `--async`, and `POST /run` all drive the same dispatcher via
  Action Scheduler actions (or the caller's next poll, for the REST case) —
  there is no separate "async" verification logic to keep in sync.
- WP-CLI (sync) and the admin "Run now" button also use the same
  chunk-by-chunk dispatcher, just looped synchronously in-process until the
  run reaches a terminal state, so results are identical across every mode.

This means a run for a large site is not one long-blocking operation (except
for the synchronous CLI/admin-button modes, which intentionally block by
looping the dispatcher themselves) — progress survives across separate HTTP
requests, Action Scheduler actions, or process restarts.

### Timeouts and stale state

Three distinct time-related concepts are involved, and it's easy to
conflate them:

- **Chunk time budget** — how long a single chunk is allowed to run before
  it must save its cursor and yield (e.g. the External HTTP time budget
  setting, default 20s, for `POST /run`). Reaching this is normal operation,
  not an error: the target's status becomes `retry` and the next chunk
  picks up where it left off.
- **Stale lease / worker** — each claimed (`running`) target holds a
  time-limited lease. If the worker that claimed it never reports back
  before the lease expires (crash, kill -9, PHP fatal, etc.), the target is
  reclaimed and retried, up to a maximum attempt count, after which it is
  marked `failed` with error code `lease_expired`.
- **Run deadline** — the run as a whole has a hard ceiling (default 6
  hours). If a run has not reached a terminal state by then, it and any of
  its still-non-terminal targets are marked `aborted`, so a stuck run can
  never block the next scheduled run indefinitely.

### REST API

Every endpoint below requires a bearer token: send it as
`Authorization: Bearer <token>` (preferred) or `X-WPCV-Token: <token>`.
Query-string tokens are intentionally not supported, and every response is
sent with `Cache-Control: no-store`. Repeated authentication failures from
the same IP are rate-limited. Tokens are split into two independent scopes,
each issued separately from the Settings screen (shown once at generation
time; only a salted hash is stored):

- **run** — required by `POST /run`. A `WPCV_REST_TOKEN` constant (e.g. in
  `wp-config.php`) overrides the run-scope token issued from the Settings
  screen; it does not apply to the read scope below.
- **read** — required by `GET /status` and `GET /findings`. Issued
  separately; a run-scope token cannot call these, and a read-scope token
  cannot call `POST /run`.

#### `POST /run`

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

For a host without a working WP-Cron (e.g. `DISABLE_WP_CRON` set, or no
traffic to trigger it), point an external scheduler at this endpoint every
5 minutes, for example a crontab entry:

```cron
*/5 * * * * curl -s -X POST -H "Authorization: Bearer <run-scope token>" https://example.com/wp-json/wpcv/v1/run >/dev/null
```

Example response while a run is progressing:

```json
{
  "run_id": 42,
  "status": "running",
  "pending_targets": 3,
  "retry_targets": 1,
  "next_retry_at": "2026-09-11T13:05:00+00:00"
}
```

#### `GET /status`

Requires a read-scope token. Returns `current_run` (the in-progress run, or
`null` if none), `last_run` (the most recently completed run, or `null` if
none yet), and `next_scheduled_at` (the next daily due time, computed from
the Settings screen's run time regardless of which mode actually triggers
it). Each run object includes its target-status tally (`queued`, `retry`,
`running`, `success`, `unverifiable`, `failed`, `skipped`, `aborted`,
`total`), `findings_total`, `scheduled_for`, `deadline_at`, and
`last_activity_at` (the most recent target claim/finish timestamp — useful
for spotting a run that has stopped making progress).

Example response:

```json
{
  "current_run": null,
  "last_run": {
    "run_id": 37,
    "status": "partial",
    "run_trigger": "cli",
    "started_at": "2026-09-11T05:45:00+00:00",
    "finished_at": "2026-09-11T05:46:12+00:00",
    "scheduled_for": "2026-09-11T05:45:00+00:00",
    "deadline_at": "2026-09-11T11:45:00+00:00",
    "last_activity_at": "2026-09-11T05:46:10+00:00",
    "findings_total": 7,
    "targets": {
      "queued": 0, "retry": 0, "running": 0, "success": 36,
      "unverifiable": 7, "failed": 0, "skipped": 0, "aborted": 0, "total": 43
    }
  },
  "next_scheduled_at": "2026-09-12T05:45:00+00:00"
}
```

#### `GET /findings`

Requires a read-scope token. Returns findings for one run — the most
recent one by default, or a specific `run_id` query parameter. Supports
`dimension`, `status`, and `severity` filters (a single value or an array,
e.g. `dimension[]=core&dimension[]=plugin`; each value must be from a fixed
allowlist or the request returns `400`), `sort`/`order` (allowlisted
columns only), and `page`/`per_page`
pagination (small default, capped maximum). Suppressed and closed findings
are excluded by default; pass `include_suppressed=1`/`include_closed=1` to
include them. The response includes `findings`, `run_id`, `page`,
`per_page`, `total`, and `total_pages`.

Example response:

```json
{
  "findings": [
    {
      "id": 101, "run_id": 37, "target_id": "plugin:hello-dolly",
      "dimension": "plugin", "slug": "hello-dolly", "version": "1.7.2",
      "path": "readme.txt", "status": "modified", "severity": "low",
      "hash_algorithm": "sha256", "expected_hash": "...", "actual_hash": "...",
      "suppressed_by": "soft_change", "suppression_id": null
    }
  ],
  "run_id": 37,
  "page": 1,
  "per_page": 20,
  "total": 1,
  "total_pages": 1
}
```

## Admin screens

Alongside the Settings screen (see below), the plugin adds three read/write
screens under the same top-level "Checksum Verifier" menu (network admin
menu on multisite):

- **Findings** — the findings for the most recent run (or a specific
  `run_id`), with the same `dimension`/`status`/`severity`/suppressed/closed
  filters as `GET /findings`. Each row offers three one-click actions, each
  requiring a reason: **Exclude this path** (creates an `exclude_path`
  suppression rule scoped to that target and path), **Approve this hash**
  (creates an `allowlist_hash` rule for the exact hash/version shown — only
  offered for `added`/`modified` findings, which are the ones that actually
  have a hash to approve), and **Exclude entire target** (creates an
  `exclude_target` rule, skipping verification of that plugin/core/MU-plugin
  entirely from the next run onward). None of these retroactively change
  the findings currently on screen — the rule takes effect starting with
  the next run.
- **Suppressions** — every suppression rule ever created (all three types),
  with its target, reason, creator, creation time, and (for `allowlist_hash`
  rules) the approved version and hash prefix. Active rules can be revoked
  (also requiring a reason), which is recorded and shown alongside the rule
  rather than deleting it.
- **Run History** — every run, newest first, with a detail view per run
  showing each target's status, `error_code` (translated to a human-readable
  reason for `unverifiable`/`retry`/`aborted`/`skipped` targets), file
  counts, and attempt count.

## Settings

The plugin's settings screen (network admin menu on multisite) opens with a
**status panel**: the current run and last completed run (with their target
tallies and last-activity timestamp), the next scheduled run time, whether
WP-Cron is enabled, whether Action Scheduler is available (async runs
silently fall back to synchronous execution when it isn't), and the most
recent WP-CLI-triggered run recorded on this site (this only reflects runs
actually recorded here — it cannot detect whether WP-CLI itself is
installed on the server). Below that, you can configure: the daily run time
(UTC, shared by WP-Cron and the REST endpoint's due check), the REST
endpoint's per-request time budget, and REST token issuance.

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
