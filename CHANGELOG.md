# Changelog

All notable changes to this project will be documented in this file.

## [0.9.1] - Unreleased

### Fixed

- **History retention could stall.** Cleanup stopped after 500 per-target
  results *examined*, and results kept only because of an already-emailed
  finding counted toward that limit. With 500 or more such results at the
  oldest end, every run end re-examined the same ones and never reached the
  expired history behind them. Results kept without deleting anything no
  longer count toward the limit. Also, of the already-emailed findings that
  still exist in the baseline, only the newest one per finding is kept now
  (the re-send suppression reads only the latest email time, so older ones
  changed nothing); a file that changes every run under stat-based tracking
  used to keep one result per run. On a real site, 65 emailed rows in 9
  results became 46 rows in 7 results (read-only count; nothing was old enough
  to be deleted there).

### Changed

- **Creating and revoking suppression rules has its own capability.** The
  `wpcv_required_capability` filter now also receives the name
  `manage_suppressions`, used for creating a rule (Findings screen) and
  revoking one (Suppressions screen); the "Actions" column is hidden from
  people without it. The default is the same as every other screen. Before,
  these used the screen's own capability, so loosening `findings` alone also
  let people create rules. **If you loosened `findings` or `suppressions` and
  want people to keep creating or revoking rules, loosen `manage_suppressions`
  too.** Opening the screen still needs the screen's capability as well.
- The Findings screen explains, above the table on multisite, that "Active on"
  shows the current state, not the state when the run took place.
- Internal: the duplicated `count_new_access_denied()` is now one method
  (`WPCV_Alert_Composer`); a test checks that the status / severity / diff
  state allowlists of the Findings screen and the REST API stay identical.

## [0.9.0] - 2026-10-03

### Added

- **History retention.** A new "History retention" setting (Keep forever —
  the default —, 3, 6, 12 or 24 months). At the end of each run, runs,
  per-target results, findings and expired suppression rules older than the
  period are deleted (up to 500 per-target results and 500 run records per
  run: about 0.26 s per run end on a local MySQL 8.4 test database holding a
  year of history, and 7 ms when there is nothing to delete; not measured on
  shared hosting). Always kept: each
  target's most recent successfully verified result with its findings and run
  record (the diff baseline), runs still being processed and the result they
  compare against, already-emailed findings that still exist in the baseline
  (so they are not emailed again), and active suppression rules. An open
  ("unended") finding does not by itself keep an old record: on a real site
  only 14 of 1,065 such findings were in a target's latest verified result,
  because stat-based findings are never closed and a continuing finding leaves
  its older copies open. With a finite period, a failure streak that outlasts
  it can make the repeated-unverifiable / run-failure alert fire again about
  once per period. See README ("History retention").
- **Multisite: "Active on" column** in the Findings screen — where a plugin or
  theme is in use (network-wide, the sites that have it active, or the sites
  using a theme as the parent of a child theme). Computed when the screen is
  shown, with `switch_to_blog()` (about 1 ms per site, measured on a 20-site
  network); not stored and not computed during a run, no schema change. Above
  500 sites (provisional, unmeasured) it says "Not shown"; change the limit
  with the new `wpcv_affected_sites_scan_limit` filter.
- **Multisite warning.** When the plugin is active only on a sub-site (not
  network-wide and not on the main site) no scheduled run is ever started, so
  that sub-site's Dashboard and Plugins screens show a warning to users who can
  activate plugins. Activation is not blocked. README now says to activate it
  network-wide.
- **`wpcv_required_capability` filter** (capability, screen name) to change
  who can use the admin screens, forms and notices. The defaults are unchanged
  (`manage_options` on a single site, `manage_network_options` on multisite).
  A value that is not a non-empty string is ignored. See README
  ("Permissions") for the warning about suppression rules.
- **`source_access_denied`** error code: a GitHub token that is rejected (401,
  or a 403 without rate-limit signs). Such a target is no longer counted toward
  the repeated-unverifiable alert, goes to stat-based change detection, shows an
  admin notice with the number of affected targets, and triggers one email the
  first time it appears (not while it continues; other alert emails list the
  affected targets). The reason column of the run history now also says when a
  target that cannot be compared with its source is monitored by file-change
  tracking instead.

### Changed

- **GitHub response classification** (based on GitHub's documentation): a 429
  is always `rate_limited` (before, a 429 without a numeric `retry-after` was
  `http_error`); a 403 whose message mentions a rate limit is `rate_limited` too
  (secondary rate limits can come without `retry-after`; the wording is matched
  loosely and has not been verified against a real secondary limit); the
  `x-ratelimit-reset` time is used only when the remaining count is 0, otherwise
  60 seconds. A 401, or a 403 without any of those signs, is
  `source_access_denied` (before, `http_error`). A private repository the token
  cannot access is still a 404 (`manifest_not_found`).
- The required capability is decided in one place (`WPCV_Capability`) instead
  of being repeated in four screen classes and the menu registration, so the
  menu, the screen, every form it posts and the notice always agree.

### Fixed

- **Uninstall left data behind**: the GitHub rate-limit transient, the REST
  token failure counters, the self-update check cache, and the plugin's rows in
  the Action Scheduler tables (actions whose hook starts with `wpcv_`, their
  logs and the `wpcv` group — 224 rows after three runs on a test site; the
  deactivation hook only cancels pending actions, and the bundled Action
  Scheduler goes away with the plugin so nothing cleans finished ones) are now
  removed. The Action Scheduler tables themselves are shared and stay. The
  self-update cache is removed by exact name so other plugins using the same
  library keep theirs. On multisite every site is cleaned (not on a large
  network, `wp_is_large_network()`).

## [0.8.0] - 2026-10-01

### Added

- **GitHub Releases verification.** A plugin or theme can be mapped to a
  GitHub repository (Settings screen "GitHub repository mappings", or the
  `wpcv_github_mappings` filter) and is then compared against the asset of
  the GitHub Release for the *installed* version instead of wordpress.org.
  Nothing is detected automatically. The tag is tried as `{version}` then
  `v{version}`; the asset is chosen by the optional asset-name prefix,
  `{slug}.{version}.zip`, or a single `{slug}*.zip`. The zip is hashed
  without extracting it, after the same safety checks as theme zips; the
  asset's `sha256` digest, a single top-level directory, and the `Version`
  header of the main file inside the zip must all agree with what is
  installed. Manifests are cached (`source = github`, key `owner/repo` +
  version, no schema change). Targets use `source = github`
  (also for their findings). Directories containing `.git` are not compared
  with GitHub (`unknown_source`); themes bundled with core ignore a mapping
  (the Settings screen warns once the core manifest is cached). New error
  codes in use: `no_release_asset`, `asset_ambiguous`, `rate_limited`
  (the three were defined but never returned before). See README.
- **GitHub token** via the `WPCV_GITHUB_TOKEN` constant or the
  `wpcv_github_token` filter only (never stored in the database; the
  Settings screen shows only whether one is configured). With a token,
  assets are downloaded through the GitHub API, so private repositories work.
- **Rate-limit handling.** A 403/429 with `x-ratelimit-remaining: 0` or a
  `retry-after` header makes the target `rate_limited` and pauses all GitHub
  requests until the time GitHub gave (`retry-after`, then
  `x-ratelimit-reset`, otherwise 60 seconds); the next run tries again.
- Filters: `wpcv_github_mappings`, `wpcv_github_token`,
  `wpcv_github_tag_candidates`, `wpcv_github_api_version`,
  `wpcv_github_api_timeout` (10 s; measured 0.2–0.5 s), `wpcv_github_download_timeout`
  (30 s), and `wpcv_github_zip_max_archive_bytes` / `_max_entries` /
  `_max_entry_bytes` / `_max_total_bytes` / `_max_compression_ratio`
  (the same provisional limits as for theme zips).

- **Japanese translation** of every string that goes through a translation
  function: the admin screens (Settings, Run History, Findings,
  Suppressions), the `error_code` labels, alert emails, and admin notices
  (`languages/wp-checksum-verifier-ja.po`, compiled to `.mo` and `.l10n.php`;
  the `.pot` template is included). WP-CLI messages are not translated. The
  admin screens follow the user's language; alert emails sent by cron or
  WP-CLI follow the site language.

### Changed

- **Versions are read from disk when a target is processed** (a plugin's
  main file, a theme's `style.css`, `wp-includes/version.php`), and a chunk
  whose result may mix files from before and after an update is discarded
  and retried. Previously, an automatic update that landed after a run was
  planned made the run compare the new files against the old version's
  manifest and report false `modified` findings (reproduced with 2 files on
  a real site; `get_plugins()` and `wp_get_themes()` cache their results
  within a process, so rebuilding the context was not enough). Not covered:
  an update that reinstalls the same version between two chunks, and the
  unknown-file scans.
- A GitHub-mapped target whose lookup ends in `no_release_asset` or
  `asset_ambiguous` is now handled by stat-based change detection (like
  `manifest_not_found`), so a wrong mapping never leaves it unchecked.
- The Settings screen always shows the daily run time's hour and minute with
  two digits (`03:00`). The stored values are unchanged.
- The zip inspection and hashing code was moved out of the wordpress.org
  theme source into a shared class; behavior for theme zips is unchanged.

### Known limitations

- A release asset replaced under the same tag is not noticed until the
  installed version changes (the cache is keyed by version).
- Must-use plugin loaders and single-file plugins directly in
  `wp-content/plugins/` cannot be mapped yet.
- A Release that is still a draft cannot be seen through the API, so it
  cannot be compared until it is published.

### Verified

- Private repositories: a fine-grained GitHub token limited to one repository
  with only "Contents: Read-only" is enough (Release lookup 0.39 s and asset
  download through the Assets API 0.61 s on shared hosting; without the token
  the repository is `manifest_not_found`).

## [0.7.0] - 2026-10-01

### Added

- **Official theme verification.** Each installed theme is compared against
  a manifest built from its wordpress.org zip
  (`downloads.wordpress.org/theme/{slug}.{version}.zip`; wordpress.org has
  no theme checksum API). The zip is hashed while being read and never
  extracted to disk. Each theme gets three targets: `theme:{stylesheet}`
  (checksum comparison), `theme:{stylesheet}:_stat` (stat-based change
  detection for themes that are not on wordpress.org or cannot be compared
  with it), and `theme:{stylesheet}:_scan` (unknown files in themes that
  were compared). Themes with errors (e.g. a child theme without its
  parent) are included.
- Themes whose `Update URI` header points to a host other than
  `wordpress.org`/`w.org` (including `false`), themes in a sub-directory of
  the theme root, and themes with an empty version are not compared against
  wordpress.org (no download) and go to stat-based change detection.
- Zip safety checks before any file is read (absolute paths, drive letters,
  `..`/`.`/empty segments, control characters, a root other than
  `{slug}/`, symlinks, duplicate names) and provisional size limits:
  download 100 MB, 20,000 entries, 50 MB per file, 500 MB in total,
  compression ratio 100 — each adjustable with a
  `wpcv_theme_zip_max_*` filter. Download timeout 30 seconds
  (`wpcv_theme_zip_download_timeout`); measured at most about 2 seconds per
  theme on shared hosting (Xserver) and 4.6 seconds locally.
- **Manifest cache** (new database table `wpcv_manifest_cache`, schema
  version 7). Theme manifests are reused until the theme's version
  changes, and the WordPress core manifest is cached too (except an
  `en_US` fallback). Rows for themes/versions that no longer appear in a
  run are deleted when a run ends as `success` or `partial`.
- Theme installs and updates are now recorded as update events
  (`theme_install`/`theme_update`/`theme_bulk_update`), so a theme updated
  through WordPress is not reported as a version change without an update.

### Changed

- **Core verification no longer checks files under `wp-content/themes/`.**
  Themes bundled with core are verified by their theme target instead,
  where each file is accepted if it matches either the wordpress.org zip
  or the core checksums (a bundled copy can differ from the zip of the same
  version). Previously, updating a bundled theme from wordpress.org ahead
  of core made core verification report the updated files as `modified`
  (reproduced with 75 files).
- The core target's `manifest_status` is now `cached` from the second run
  on, and its `files_total` no longer includes `wp-content/themes/`.

### Known limitations

- Older default themes no longer bundled with core can differ from their
  wordpress.org zip by build differences only (e.g. a re-minified
  `style.min.css`) and show up as `modified` on the first run; approve them
  with `allowlist_hash` (the approval expires when the theme is updated).
- On a server without PHP's ZipArchive extension, themes are reported as
  `unverifiable` (`ziparchive_missing`) on every run and are not checked by
  stat-based change detection either; this does not trigger the
  repeated-unverifiable alert.

## [0.6.0] - 2026-10-01

### Added

- The plugin now records an **update event** (target, version, source,
  who triggered it) whenever WordPress core or a plugin is updated through
  the admin screens, WP-CLI, or an automatic update. `wp --skip-plugins
  plugin update` does not fire the hooks this relies on and is not
  recorded. Events are kept for 90 days (`wpcv_update_events_retention_days`
  filter), pruned whenever a run terminates.
- New setting **"Alert on version changes that did not go through the
  WordPress updater"** (on by default). When a checksum target's version
  changes with no matching update event, the old baseline is compared as
  usual instead of being discarded, and the alert email gets a new
  "Version changed without a WordPress update:" section. Turn this off on
  sites that deploy via git/FTP/Composer, where every deployment would
  otherwise trigger it.
- Run History detail now shows an **"Update events since the previous
  run"** section listing recorded update events between the previous and
  current run.
- Verification runs are now **deferred** (rechecked every 30 seconds,
  never marked `skipped`) while `.maintenance` is present or an update
  lock (`core_updater.lock`/`auto_updater.lock`) is held, instead of
  claiming a target and possibly reading files mid-update.
- New `core:_config` and `dropin:_stat` targets track `wp-config.php`,
  `.htaccess`, `.user.ini`, and any present WordPress-recognized drop-in
  (`object-cache.php`, `advanced-cache.php`, etc.) the same way stat-based
  change detection does, but unconditionally — independent of the
  "Stat-based change detection" setting — and always with content-hash
  comparison (see below).
- **Content-hash comparison.** In addition to size/ctime/mtime, a sha256
  hash of each file's contents can now be compared against the previous
  run, catching a same-size, same-mtime rewrite that stat tracking alone
  cannot. Always on for the new configuration-file/drop-in targets above;
  opt-in for other stat-based targets (custom/premium plugins, MU-plugin
  loaders) via the new **"Content-hash comparison for custom plugins"**
  setting (off by default). A content change is reported as `modified`
  (with `hash_algorithm`/`expected_hash`/`actual_hash`, like a
  checksum-target finding) instead of `stat_changed`. A file over 10 MB is
  not hashed and falls back to stat-only tracking (`wpcv_content_hash_max_bytes`
  filter), and hashing within a single chunk stops after 200 MiB and
  resumes on the next cycle (`wpcv_content_hash_chunk_max_bytes` filter) —
  both measured against ~78–131 MB/s observed hashing throughput (see
  `wp wpcv bench-stat --hash` below).
- `wp wpcv bench-stat --hash` flag that additionally measures content-hash
  (sha256) throughput on a directory, used to size the content-hash byte
  limits above.

### Changed

- Stat-based change detection (for plugins without official checksums)
  now distinguishes a version change backed by a recorded update event
  (baseline silently rebuilt, as before) from one that isn't (compared
  against the old baseline instead, and marked
  `version_changed_unrecorded`).
- A hash-allowlist suppression (`allowlist_hash`) is now automatically
  expired when its target's version changes to anything other than the
  version it was approved for (shown as "version changed" in
  Suppressions). Previously, an old approved hash could keep suppressing
  findings indefinitely after reverting to an earlier version.
  `exclude_path`/`exclude_target` rules are unaffected.

## [0.5.1] - 2026-09-29

Patch release: fixes an uninstall bug found while reviewing the v0.6
roadmap. No new features.

### Fixed

- `uninstall.php` only deleted the `wpcv_db_version` option, leaving the
  alert-recipients setting and REST API token hashes behind. On multisite,
  the DB version is stored as a network-wide site option that was never
  deleted either; deleting and reinstalling the plugin would then leave
  the stale site option in place, causing the version check to think the
  schema was already current and **skip creating the five database
  tables entirely**. All four options (`wpcv_db_version`, `wpcv_settings`,
  `wpcv_rest_token_hash`, `wpcv_rest_token_hash_read`) are now deleted on
  both single-site and multisite installs, and a previously issued REST
  token no longer works after reinstalling.
- Deactivating the plugin now cancels any Action Scheduler actions still
  pending for its own chunk-continuation and async-run hooks, so a
  removed installation does not leave "no callbacks registered" errors in
  the Scheduled Actions log if the queue happens to run afterward.

## [0.5.0] - 2026-09-28

### Added

- **Stat-based change detection** for plugins that have no official
  checksums (custom or premium plugins, and MU-plugin loaders). Each file's
  size, ctime, and mtime (read with `lstat()`; file contents are never read)
  are stored as a baseline in the new `wpcv_file_states` table and compared
  on every run. Changes are reported as the new `stat_changed` status (with
  old/new values in the new `findings.detail` column), new files as
  `added`, and deleted files as `missing` (reported once). A size change
  with an unchanged mtime is flagged as possible timestamp forgery at
  `high` severity. The first run only builds the baseline.
- When a plugin's version changes, its stat baseline is rebuilt without
  reporting changes and the target is marked with the new
  `baseline_rebuilt` error code; plugins verified against checksums are
  skipped with the new `checksum_covered` code.
- Mass changes without a version bump are rolled up into one finding per
  plugin (provisional thresholds, adjustable via the new
  `wpcv_stat_rollup_min_count` and `wpcv_stat_rollup_ratio` filters).
- "Stat-based change detection" setting (on by default), a `stat_changed`
  status filter and a Details column on the Findings screen, and
  `status=stat_changed` support in `GET /findings`.
- `wp wpcv bench-stat` command that measures `lstat()` throughput on a
  directory (read-only), used to size the stat-scan budget.
- **Diff detection and email alerts.** Every run is now compared against
  each target's own baseline (its own most recently verified run) and
  findings are classified `new`, `continuing`, or `resolved`; stat-based
  findings are always `event`. An email summary is sent when new findings
  appear, findings resolve, a target has been unverifiable for several
  runs in a row, or the run itself has failed/aborted several times in a
  row (all thresholds unmeasured defaults, adjustable via new
  `wpcv_alert_max_items`, `wpcv_alert_resend_days`,
  `wpcv_alert_unverifiable_streak`, and `wpcv_alert_run_failure_streak`
  filters). New `wpcv_alert_channels` filter for registering additional
  delivery channels beyond email, and a new `wpcv_run_terminated` action
  fired whenever a run reaches a terminal status.
- Settings screen: alert recipients field and a "Send test alert" button.
  Admin notices (shown on this plugin's own screens, the dashboard, and the
  plugin list) when no recipients are configured or the last alert email
  failed to send.
- Findings screen: a **Diff** column and `diff_state` filter
  (`new`/`continuing`/`event`); `GET /findings` gained a matching
  `diff_state` query parameter.
- Run History screen: **Diff** and **Alert** summary columns on the list;
  the run detail view gained diff/alert state, alert error/failed-channel
  fields (admin-only), a **Diff mode** column per target, and a
  "Findings ended in this run" section. `GET /status` gained matching
  `diff_status`, `findings_new`, `findings_resolved`, `findings_continuing`,
  `alert_status`, and `alert_attempted_at` fields (internal fields such as
  `alert_error` are intentionally not exposed over REST).

### Changed

- Database schema version is now 5 (v4: diff/alert tracking columns on
  `wpcv_runs`/`wpcv_target_runs`/`wpcv_findings` plus `finding_key`; v5:
  supporting indexes added after load testing with 100k+ findings).
- Core verification no longer reports a `missing` finding for files under
  `wp-content/` (an unmodified WordPress core install does not ship
  anything there; a `modified` finding is still reported as before).
- Stat baseline rows for a plugin that has been uninstalled (or newly
  excluded) are now deleted instead of being left behind indefinitely.
- Run totals now include the stat targets that were actually scanned.
  Stat targets skipped as `checksum_covered` are left out of the totals and
  of the success/partial decision, so a site with only wordpress.org
  plugins still finishes as `success`.

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
