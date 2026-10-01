# WP Checksum Verifier

WordPress core, plugin, theme, and must-use plugin checksum verifier. Detects
tampering by comparing installed files against official checksum manifests
(wp.org core/plugin checksums, and manifests built from wp.org theme zips)
and reports unknown files not present in any manifest.

> **Status**: v0.8.0. The verification engine, all planned execution model
> entry points (WP-CLI, WP-Cron, admin "Run now" button, REST API),
> file-level chunked execution with resume, the suppression engine
> (`exclude_target`/`exclude_path`/`allowlist_hash` plus strict mode),
> stat-based change detection for plugins without official checksums
> (with optional content-hash comparison), configuration-file and drop-in
> monitoring, update-event tracking, diff-based email alerts (see Alerts
> below), official theme verification, and the Findings/Suppressions/Run
> History admin screens are implemented, as is verification of plugins and
> themes mapped to a GitHub repository (see "GitHub Releases verification"
> below). See `CHANGELOG.md` for details.

## Verification targets

- **WordPress core**: compared against the official checksums for the
  installed version/locale.
- **Official (wp.org) plugins**: compared against each plugin's official
  checksums for its installed version.
- **Official (wp.org) themes**: compared against a manifest built from the
  theme's wp.org zip for its installed version (see "Official theme
  verification" below).
- **Must-use plugins**: scanned for unknown files (no official checksum
  source exists for MU plugins).
- **Unknown files**: files present on disk but absent from the relevant
  manifest are reported as findings, for every target above.
- **Plugins and themes without official checksums** (custom or premium
  plugins and themes, and MU-plugin loaders): tracked by stat-based change
  detection (see below).
- **Configuration files and drop-ins** (`wp-config.php`, `.htaccess`,
  `.user.ini`, and any WordPress-recognized drop-in that is actually present,
  e.g. `object-cache.php`): tracked the same way, but always with
  content-hash comparison (see "Content-hash comparison" below) — these
  targets exist unconditionally, independent of the "Stat-based change
  detection" setting.
- **Plugins and themes mapped to a GitHub repository** (e.g. unofficial plugins
  distributed through GitHub Releases): compared against the asset of the
  Release for the installed version (see "GitHub Releases verification"
  below).

### Official theme verification

wordpress.org publishes no checksum API for themes, so the plugin downloads
`https://downloads.wordpress.org/theme/{slug}.{version}.zip` for each
installed theme and hashes the files inside it (sha256 and md5) without ever
extracting the zip to disk. The resulting manifest is cached in the database
(`wpcv_manifest_cache`) and reused until the theme's version changes, so only
the first run after installing or updating a theme pays for the download
(about 1.5–2.5 seconds per theme in a local measurement). Cache rows for
themes and versions that no longer appear in a run are deleted when a run
finishes as `success` or `partial`. The WordPress core manifest is cached the
same way (except when it fell back to `en_US` because the locale's manifest
was not published yet).

- **Targets**: each theme gets `theme:{stylesheet}` (checksum comparison),
  `theme:{stylesheet}:_stat` (stat-based change detection, used only when the
  theme could not be compared against wp.org), and `theme:{stylesheet}:_scan`
  (unknown files, used only when it could). All three share the theme's
  suppression rules. Themes with errors (e.g. a child theme whose parent is
  missing) are included.
- **Not compared against wp.org** (no download; the theme goes to stat-based
  change detection instead): the theme is not on wp.org (the zip returns 404:
  `manifest_not_found`), its `Update URI` header points to a host other than
  `wordpress.org`/`w.org` — including `Update URI: false` — so a same-named
  theme on wp.org is never used (`unknown_source`), it lives in a
  sub-directory of the theme root (`unknown_source`), or its version is empty
  (`version_unknown`).
- **Themes bundled with WordPress core** (e.g. twentytwentyfive): a bundled
  copy can differ from the wp.org zip of the same version, so each file is
  accepted if it matches either the wp.org zip or the core checksums. Core
  verification no longer checks anything under `wp-content/themes/`; the theme
  target is responsible for those files. Which themes count as bundled depends
  on the core manifest for your locale (for example, the `ja` 7.1.2 manifest
  also includes twentytwentytwo, the `en_US` one does not).
- **Older default themes** that are no longer bundled with core may differ
  from their wp.org zip by build differences only (e.g. a re-minified
  `style.min.css`). These show up as `modified` on the first run; approve
  them with `allowlist_hash`. The approval expires when the theme's version
  changes, so updating the theme clears it.
- **Unknown files**: for themes that matched wp.org, files not in the zip
  (nor, for bundled themes, in the core checksums) are reported as `added`
  (`high` for PHP-like files, `medium` otherwise). For themes that did not,
  stat-based change detection already reports new files, so they are not
  reported twice.
- **When the zip cannot be used**: a temporary download failure
  (`http_error`), a zip that fails the safety checks (`archive_rejected`) or
  is corrupt (`archive_invalid`), and a server without PHP's ZipArchive
  extension (`ziparchive_missing`) leave the theme `unverifiable` for that
  run **without** falling back to stat-based change detection, so no
  baseline is ever created by accident. On a server without ZipArchive this
  means themes stay unchecked on every run — and, like custom themes, they do
  not trigger the repeated-unverifiable alert — so check Run History if your
  host may lack the extension.
- **Safety checks and limits**: entries are checked before any file is read —
  absolute paths, drive letters, `..`/`.`/empty segments, control characters,
  a root other than `{slug}/`, symlinks, and duplicate names are rejected.
  Size limits (provisional, about 10× the largest measured wp.org theme):
  download 100 MB (`wpcv_theme_zip_max_archive_bytes`), 20,000 entries
  (`wpcv_theme_zip_max_entries`), 50 MB per file
  (`wpcv_theme_zip_max_entry_bytes`), 500 MB in total
  (`wpcv_theme_zip_max_total_bytes`), and a compression ratio of 100
  (`wpcv_theme_zip_max_compression_ratio`). The download timeout is 30
  seconds (`wpcv_theme_zip_download_timeout`); measured downloads took at most
  about 2 seconds per theme on shared hosting and 4.6 seconds locally.

### GitHub Releases verification

Plugins and themes that are not on wordpress.org but publish their releases on
GitHub can be verified against the assets of their GitHub Releases. Nothing is
detected automatically: you map each plugin or theme to a repository on the
Settings screen ("GitHub repository mappings", one line each) or with the
`wpcv_github_mappings` filter. Plugins and themes without a mapping are
verified exactly as before.

```
plugin:forced-auto-update-controller lunaluna/forced-auto-update-controller
theme:my-theme lunaluna/my-theme my-theme-pro
```

The first field is `plugin:{slug}` or `theme:{stylesheet}`, the second is
`owner/repo`, and the optional third is the start of the asset file name.
Invalid lines and repeated targets are dropped when saving (the screen says
which), and comment lines are not kept. The filter receives and returns a list
of `array( 'target' => ..., 'repo' => ..., 'asset' => ... )`; it is applied
after the saved mappings, and a target already mapped on the screen wins.

- **Which release**: the Release for the version installed on this site, not
  the latest one (`/releases/latest` is never used, so a site that has not
  been updated is not reported as modified). The tag is tried as `{version}`
  and then, only if that was a 404, as `v{version}`; change the candidates
  with `wpcv_github_tag_candidates`. A Release that is still a draft is not
  visible, so it cannot be compared until it is published (the target goes
  to stat-based change detection).
- **Which asset**: with a third field, the `.zip` asset whose name starts with
  it; otherwise `{slug}.{version}.zip` if it exists, otherwise the single
  `.zip` whose name starts with `{slug}`. Several candidates give
  `asset_ambiguous`; none gives `no_release_asset`. GitHub's automatic
  source archives (zipball/tarball) are never used.
- **Checks on the asset**: if GitHub reports a `sha256` digest for the asset
  it must match the downloaded file (`archive_invalid` otherwise); the zip
  must have exactly one top-level directory (its name does not have to match
  the slug) and passes the same safety checks as theme zips; and the `Version`
  header of the plugin's main file (or the theme's `style.css`) inside the zip
  must equal the installed version, so a wrong mapping or a tag that does not
  match its contents becomes `asset_ambiguous` instead of a wall of
  `modified` findings. Size limits are the same provisional values as for
  theme zips, adjustable with `wpcv_github_zip_max_archive_bytes`,
  `wpcv_github_zip_max_entries`, `wpcv_github_zip_max_entry_bytes`,
  `wpcv_github_zip_max_total_bytes` and
  `wpcv_github_zip_max_compression_ratio`. The asset download timeout is 30
  seconds (`wpcv_github_download_timeout`); the API timeout is 10 seconds
  (`wpcv_github_api_timeout`; measured at 0.2–0.5 seconds locally and 0.26
  seconds on shared hosting, release asset downloads at most 0.9 seconds). The `X-GitHub-Api-Version` header is `2022-11-28`
  (`wpcv_github_api_version`).
- **Cache**: the manifest is stored in the manifest cache with the key
  `owner/repo` + installed version and reused until the version changes, so
  an ordinary run makes no GitHub request. Rows no longer used (a mapping
  that was removed, an older version) are deleted when a run ends as
  `success` or `partial`. A release asset replaced under the same tag is not
  noticed until the version changes.
- **Directories with `.git`**: a plugin or theme directory that contains a
  `.git` entry (a development checkout, or a symlink to one — the repository
  tree differs from the release zip) is never compared with GitHub; it
  becomes `unknown_source` and goes to stat-based change detection.
- **Outcomes**: the target's `source` (and its findings' `source`) is
  `github`; wordpress.org is not asked about a mapped target.

  | Result | Stat-based change detection |
  | --- | --- |
  | Compared (`success`) | skipped (`checksum_covered`) |
  | `manifest_not_found` (no such tag, draft, or a private repository without a token), `unknown_source` (`.git`), `version_unknown`, `no_release_asset`, `asset_ambiguous` | runs, so a wrong mapping never leaves the target unchecked |
  | `rate_limited`, `http_error`, `archive_invalid`, `archive_rejected`, `ziparchive_missing` | skipped for that run (no baseline is created by accident) |

- **Token and private repositories**: define `WPCV_GITHUB_TOKEN` in
  `wp-config.php` (or return a token from the `wpcv_github_token` filter,
  which also receives `owner/repo`). The token is never stored in the
  database and is never shown; the Settings screen only says whether one is
  configured. With a token, assets are downloaded through the GitHub API
  (`Accept: application/octet-stream`), which also works for private
  repositories; without one, the public `browser_download_url` is used. The
  permissions a fine-grained token needs are not documented by GitHub in the
  pages checked and have not been verified yet.
- **Rate limits**: unauthenticated GitHub API requests are limited to 60 per
  hour (measured: a 404 and an `If-None-Match` request also count; downloading a
  public asset through `browser_download_url` does not). Because manifests are
  cached, a run only calls the API after a mapped plugin or theme changes
  version. When GitHub answers 403/429 with `x-ratelimit-remaining: 0` or a
  `retry-after` header, the target is `rate_limited` and GitHub is not
  contacted again until the time GitHub gave (`retry-after`, otherwise
  `x-ratelimit-reset`, otherwise 60 seconds as the GitHub documentation
  advises); the next run tries again. `rate_limited` counts toward the
  repeated-unverifiable alert like `http_error`.
- **Themes bundled with WordPress core** (e.g. twentytwentyfive) ignore a
  mapping and are verified against wordpress.org and the core checksums; the
  Settings screen warns about this once the core manifest is cached (after the
  first run).
- **Not supported yet**: must-use plugin loaders, and plugins that consist of
  a single file directly in `wp-content/plugins/`.

### Stat-based change detection

Checksums can only prove a file is correct when an official copy exists to
compare against. For plugins that have none, the plugin instead records
each file's size, ctime, and mtime (via `lstat()`, so symlinks are not
followed and file contents are never read) and reports what changed since
the previous run. It points you at files worth reviewing; it does not judge
whether a change is malicious.

- **Which targets**: only plugins and themes whose checksum lookup came back
  as "no checksums exist" (`manifest_not_found`, `unknown_source`,
  `version_unknown`, and for GitHub-mapped targets `no_release_asset` and
  `asset_ambiguous`) and MU-plugin loaders. Plugins and themes that verified
  against checksums are skipped (`checksum_covered`), and those whose lookup
  failed temporarily (`http_error`, `rate_limited`, or for themes a zip that
  could not be used — see "Official theme verification" above) are skipped
  for that run so an outage on wordpress.org never creates baselines by
  accident. Each such plugin gets an extra target named `plugin:{slug}:_stat`
  (`theme:{stylesheet}:_stat` for themes, `muplugin:{file}:_stat` for
  loaders) that shares its suppression rules.
- **First run**: only records a baseline; nothing is reported.
- **Findings**: `stat_changed` (size, ctime, or mtime differ — the `detail`
  field holds the old and new values), `added` (a new file), and `missing`
  (a file from the baseline is gone; reported once, then dropped from the
  baseline). A size change with an unchanged mtime is flagged as possible
  timestamp forgery and raised to `high` severity.
- **Plugin updates**: when a plugin's version differs from the version its
  baseline was built with, the plugin first checks whether that version
  change has a matching recorded WordPress update event (see "Update
  events" below). If it does, the baseline is discarded and rebuilt without
  reporting changes, and the target is marked `baseline_rebuilt` so the
  unchecked run stays visible in Run History. If it does not (no matching
  update event, or the tracking window doesn't reach far enough back), the
  old baseline is compared as usual instead of being discarded, and the
  target is marked `version_changed_unrecorded` — see Alerts below.
- **Mass changes without a version bump**: when a batch of up to 500 files
  has at least 20 changed files and at least 50% of the files compared,
  they are rolled up into a single `stat_changed` finding for the plugin
  root (timestamp-forgery findings are always kept individually). These
  thresholds are provisional; adjust them with the
  `wpcv_stat_rollup_min_count` and `wpcv_stat_rollup_ratio` filters.
- **Turning it off**: uncheck "Stat-based change detection" in Settings
  (on by default). Existing baselines are kept, so turning it back on later
  compares against the old baseline.
- **Known limitations**: a same-size edit that also restores the original
  mtime is still caught by ctime, but it is reported as an ordinary
  `stat_changed` rather than as forgery — unless content-hash comparison
  (below) is active for that target, in which case it is reported as
  `modified` directly.

### Content-hash comparison

Size/ctime/mtime tracking cannot tell a same-size, same-mtime rewrite from
no change at all. Content-hash comparison closes that gap by additionally
computing a sha256 hash of each file's contents and comparing it with the
hash recorded on the previous run:

- **Always on** for the configuration-file and drop-in targets
  (`core:_config`: `wp-config.php` — found the same way WordPress itself
  looks for it, one directory above `ABSPATH` if it isn't there directly and
  `wp-settings.php` isn't in that parent directory either, and reported as
  `wp-config.php` in both cases so the server's absolute path never appears
  in findings or alert emails — `.htaccess`, and
  `.user.ini`; `dropin:_stat`: whichever drop-ins WordPress
  recognizes (`advanced-cache.php`, `db.php`, `db-error.php`, `install.php`,
  `maintenance.php`, `object-cache.php`, `php-error.php`,
  `fatal-error-handler.php`, plus `sunrise.php`/`blog-deleted.php`/
  `blog-inactive.php`/`blog-suspended.php` on multisite) that actually exist
  in `wp-content/`). These two targets have no "body" plugin and no version
  of their own, so they are unaffected by the "Stat-based change detection"
  setting and by update-event tracking — any content, size, or mtime
  difference is reported on the very next run. The first run only builds a
  baseline, and adding or removing a drop-in is reported as `added`/`missing`
  like any other stat target.
- **Opt-in** for the other stat-based targets (custom/premium plugins and
  MU-plugin loaders): off by default (reading every file's contents on every
  run adds I/O cost), turned on per-site with "Content-hash comparison for
  custom plugins" in Settings.
- When the content hash differs from the previous run, the finding is
  `modified` (with `hash_algorithm`/`expected_hash`/`actual_hash`, the same
  shape as a checksum-target finding) instead of `stat_changed`, regardless
  of whether size/ctime/mtime also changed. When the content hash matches, a
  metadata-only change (e.g. `chmod`) is still reported as `stat_changed` as
  before.
- A file larger than 10 MB is not hashed and falls back to stat-only
  tracking for that file (`wpcv_content_hash_max_bytes` filter); per chunk,
  hashing stops after 200 MiB and resumes on the next cycle
  (`wpcv_content_hash_chunk_max_bytes` filter). Both defaults come from
  measured hashing throughput (see `CHANGELOG.md`), not a fixed rule of
  thumb — re-measure with `wp wpcv bench-stat --hash` on your own server if
  you change them.

### Update events

Whenever WordPress core, a plugin, or a theme is updated or installed —
through the admin screens, WP-CLI (`wp plugin update`/`wp theme
update`/`wp core update`), or an automatic update — the plugin records the
target, the version read from disk right after the update, the source
(manual/bulk/install/automatic/core update), and who triggered it (`0` for
cron/CLI). A parent theme that WordPress installs automatically together with
a child theme is not recorded; that is harmless, because a newly installed
theme has no previous result to compare against. This log is what "Plugin updates"
above and the checksum-target alert below use to tell a legitimate
WordPress-driven update apart from a version change that happened some
other way. `wp --skip-plugins plugin update` does not fire the hooks this
relies on, so updates made that way are not recorded. Recorded events are
kept for 90 days (unmeasured default; change it with the
`wpcv_update_events_retention_days` filter), pruned whenever a run
terminates.

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
target (core, each official plugin, each theme, must-use plugins) into per-target rows
tracked in the database, then processes them one file-chunk at a time:

- Each chunk verifies a bounded batch of files (bounded by count, elapsed
  time, and memory headroom) and saves a cursor (the last verified path,
  plus a fingerprint of the manifest and the target's version) before
  yielding. The next chunk resumes from that cursor.
- If the manifest fingerprint or the target's version changed since the
  cursor was saved (e.g. the plugin was updated mid-run), the target is
  reset and retried from scratch rather than silently continuing with
  possibly-mismatched data. Versions are read from the files on disk
  (a plugin's main file, a theme's `style.css`, `wp-includes/version.php`)
  when each chunk is processed, not from a list built when the run started
  or cached by WordPress in the same process, so an automatic update that
  lands after the run was planned is compared against the new version
  instead of producing false `modified` findings. A chunk whose result may
  mix files from before and after an update (the version changed during
  the chunk, `.maintenance` or an updater lock appeared, or an update event
  was recorded since the chunk started) is discarded and retried. Not
  covered: an update that reinstalls the same version *between* two chunks,
  and the unknown-file scans (`core:_scan`, `theme:{stylesheet}:_scan`).
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
- **Update in progress** — while WordPress core or a plugin is actively
  being updated (a fresh `.maintenance` file, or `core_updater.lock`/
  `auto_updater.lock` held by `WP_Upgrader`), the next chunk is deferred
  (rechecked every 30 seconds) instead of claiming a target and possibly
  reading files mid-write. Targets are not marked `skipped` for this — the
  run simply waits, up to the run deadline above. A single plugin update
  (which holds neither lock) is not detected; if it changes a target's
  version mid-run, the existing cursor-mismatch retry (above) still catches
  it.

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
`total`), `findings_total`, `scheduled_for`, `deadline_at`,
`last_activity_at` (the most recent target claim/finish timestamp — useful
for spotting a run that has stopped making progress), `diff_status` (the
per-run diff pipeline's state, `null` before it starts — see Alerts below),
`findings_new`/`findings_resolved`/`findings_continuing` (`null` until
counted, not zeroed, so "not yet counted" and "zero" stay distinguishable),
and `alert_status`/`alert_attempted_at`. Internal fields (`alert_error`, the
diff worker's lease/owner/cursor) are never exposed here; see the Run
History admin screen for those.

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
    },
    "diff_status": "done",
    "findings_new": 5,
    "findings_resolved": 0,
    "findings_continuing": 1,
    "alert_status": "sent",
    "alert_attempted_at": "2026-09-11T05:46:13+00:00"
  },
  "next_scheduled_at": "2026-09-12T05:45:00+00:00"
}
```

#### `GET /findings`

Requires a read-scope token. Returns findings for one run — the most
recent one by default, or a specific `run_id` query parameter. Supports
`dimension`, `status` (including `stat_changed`), `severity`, and
`diff_state` (`new`/`continuing`/`event` — see Alerts below) filters (a
single value or an array, e.g. `dimension[]=core&dimension[]=plugin`; each
value must be from a fixed allowlist or the request returns `400`),
`sort`/`order` (allowlisted columns only), and `page`/`per_page`
pagination (small default, capped maximum). Suppressed and closed findings
are excluded by default; pass `include_suppressed=1`/`include_closed=1` to
include them. The response includes `findings`, `run_id`, `page`,
`per_page`, `total`, and `total_pages`. Each finding's `detail` is `null`
except for stat-based findings, where it is a JSON string with the old and
new size/ctime/mtime (or, for a rolled-up finding, the change count and
sample paths). Each finding also carries `finding_key` (the identity used to
match it across runs), `diff_state`, `notified_at` (when this finding was
last emailed, or `null`), `ended_in_run_id`, and `end_reason` — `null` for
any finding the diff pipeline has not touched yet (a run still in progress,
a failed/aborted run, or a run from before this plugin tracked diffs).

Example response:

```json
{
  "findings": [
    {
      "id": 101, "run_id": 37, "target_id": "plugin:hello-dolly",
      "dimension": "plugin", "slug": "hello-dolly", "version": "1.7.2",
      "path": "readme.txt", "status": "modified", "severity": "low",
      "hash_algorithm": "sha256", "expected_hash": "...", "actual_hash": "...",
      "suppressed_by": "soft_change", "suppression_id": null,
      "finding_key": "...", "diff_state": "new", "notified_at": "2026-09-11T05:46:13+00:00",
      "ended_in_run_id": null, "end_reason": null
    }
  ],
  "run_id": 37,
  "page": 1,
  "per_page": 20,
  "total": 1,
  "total_pages": 1
}
```

## Alerts

The plugin compares each run against a baseline and emails a summary when
something worth looking at changed. The comparison is per target (core,
each plugin, each MU plugin) — a target's own most recent successfully
verified run is its baseline, independent of what happened to other
targets.

- **`new`** — a finding whose identity (target + path + hash, roughly) was
  not present in the baseline.
- **`continuing`** — a finding that was already present in the baseline and
  still is; summarized as a single count rather than re-listed in full.
- **`resolved`** — a finding present in the baseline but gone from this run.
- **`event`** — stat-based findings (see above) are always reported this
  way, every run, with no re-send suppression: there is no official
  manifest to diff against, so every stat-detected change is new
  information by definition.

A plugin or theme update does not, by itself, produce `new` findings: when a
target's version differs from its baseline's version, the old baseline is
discarded and findings are compared against nothing (marked
`version_changed`) rather than reported as newly added. A run that only
rebuilt a stat baseline after a version change, or only closed
`version_changed`/`excluded`/suppressed findings, does not trigger an
email by itself — it is folded into the next email that does go out
("Not verified today" section).

A checksum target's version change is treated differently when it has no
matching entry in the update-event log (see "Update events" above): the
old baseline is *not* discarded, findings are compared as usual, and — if
"Alert on version changes that did not go through the WordPress updater"
is checked in Settings (on by default) — the email gets a "Version changed
without a WordPress update:" section listing `target: from -> to`. This
can surface an unauthorized change to the version string just as easily as
a legitimate git/FTP/Composer deployment; turn the setting off on sites
that deploy that way, since every such deployment would otherwise alert.
If this is the only thing worth reporting (no ordinary new/resolved
findings), the subject line reads `N version change(s) without a WordPress
update` instead of `0 new findings, 0 resolved`.

A `new` finding is re-sent at most once every 7 days for the same
identity, so a file that keeps flipping between resolved and re-appearing
does not spam every run. If sending the email fails, the finding is not
marked as notified, so it is retried on the next run — the plugin aims for
"emailed at least once", not "emailed exactly once" (a redundant retry
after a transient failure is preferable to silently dropping a finding).

Two additional situations trigger an email even with no ordinary findings:

- A target has been `unverifiable` for 3 runs in a row (default; e.g.
  wp.org checksum lookups failing repeatedly) — see the
  `wpcv_alert_unverifiable_streak` filter below.
- The plugin's own verification run has failed or aborted 3 times in a row
  (default) — see `wpcv_alert_run_failure_streak` below. This alert is
  evaluated as soon as a run terminates in `failed`/`aborted`, independent
  of the diff pipeline above.

Both streak alerts fire once per streak (not on every run while the streak
continues) and are evaluated purely from run history, so there is nothing
to reset if the streak breaks and starts again later.

**Recipients and testing**: set one or more addresses in **Alert
recipients** on the Settings screen (one per line). If it is left empty, no
email is sent and a warning notice is shown instead of silently doing
nothing. Use **Send test alert** to confirm the configured recipients are
correct before relying on it — the result is shown immediately on the same
page.

**What alerts cannot detect**: if WP-Cron itself stops firing (e.g. no
traffic to a low-traffic site with `DISABLE_WP_CRON` unset, or the site is
down), no run happens at all, and this plugin has no way to notice from
the inside — point an external scheduler at `POST /run` (see above) if
that is a concern.

Beyond email, additional channels (Slack, a webhook, etc.) can be
registered via the `wpcv_alert_channels` filter:

```php
add_filter( 'wpcv_alert_channels', function ( $channels, $context ) {
    $channels[] = array(
        'name' => 'my-webhook',
        'send' => function ( $context ) {
            // $context includes: type ('diff' or 'run_failure'), run_id,
            // subject, body, counts, and the admin Findings-screen URL.
            // It never includes alert_error, tokens, or server file paths.
            return true; // or false/throw on failure.
        },
    );
    return $channels;
}, 10, 2 );
```

A channel only runs when the plugin actually attempts to send an email
(i.e. not when there is no recipient configured); one channel's failure
does not affect the email or other channels, and is not retried. There is
also a `wpcv_run_terminated` action (`do_action( 'wpcv_run_terminated',
$run_id, $status )`) fired whenever a run reaches a terminal status, for
integrations that want to react to run completion directly.

Numeric thresholds are intentionally not exposed on the Settings screen
(they are unmeasured defaults, adjust only if you have a reason to) and
can be changed with filters:

| Filter | Default | Controls |
| --- | --- | --- |
| `wpcv_alert_max_items` | 20 | Top items listed in the email body by severity |
| `wpcv_alert_resend_days` | 7 | Days before a resolved-then-recurring finding is re-sent |
| `wpcv_alert_unverifiable_streak` | 3 | Consecutive unverifiable runs before alerting on a target |
| `wpcv_alert_run_failure_streak` | 3 | Consecutive failed/aborted runs before alerting |

## Admin screens

Alongside the Settings screen (see below), the plugin adds three read/write
screens under the same top-level "Checksum Verifier" menu (network admin
menu on multisite):

- **Findings** — the findings for the most recent run (or a specific
  `run_id`), with the same `dimension`/`status`/`severity`/`diff_state`/
  suppressed/closed filters as `GET /findings`. A **Diff** column shows
  `new`/`continuing`/`event` (with the emailed timestamp when notified, e.g.
  `new (emailed 2026-09-11 05:46:13)`), or a dash for findings the diff
  pipeline has not touched yet. Each row offers three one-click actions,
  each requiring a reason: **Exclude this path** (creates an `exclude_path`
  suppression rule scoped to that target and path), **Approve this hash**
  (creates an `allowlist_hash` rule for the exact hash/version shown — only
  offered for `added`/`modified` findings, which are the ones that actually
  have a hash to approve), and **Exclude entire target** (creates an
  `exclude_target` rule, skipping verification of that plugin/core/MU-plugin
  entirely from the next run onward). None of these retroactively change
  the findings currently on screen — the rule takes effect starting with
  the next run. A **Details** column shows what changed for stat-based
  findings (old → new size and timestamps, or the rolled-up count).
- **Suppressions** — every suppression rule ever created (all three types),
  with its target, reason, creator, creation time, and (for `allowlist_hash`
  rules) the approved version and hash prefix. Active rules can be revoked
  (also requiring a reason), which is recorded and shown alongside the rule
  rather than deleting it. An `allowlist_hash` rule is also expired
  automatically (shown as "version changed") the next time its target's
  version changes to anything other than the version it was approved
  for — approving a hash no longer keeps working forever if you later
  revert to an older version. `exclude_path`/`exclude_target` rules are
  unaffected.
- **Run History** — every run, newest first, with **Diff** (`+new / −resolved
  / =continuing`, or the raw `diff_status` while the diff pipeline is still
  working through a run) and **Alert** (`sent`/`not_needed`/`no_recipient`/
  `failed`) columns. The detail view per run shows each target's status,
  `error_code` (translated to a human-readable reason for
  `unverifiable`/`retry`/`aborted`/`skipped` targets, including
  `version_changed_unrecorded` — see Alerts above), file counts, attempt
  count, and a **Diff mode** column (see Alerts above); the run-level detail
  also shows the diff/alert state, the alert error and any failed alert
  channels when present (admin-only — never exposed over REST), and a link
  to that run's findings. An **Update events since the previous run**
  section lists what this plugin recorded (see "Update events" above)
  between the previous run and this one (omitted for the very first run,
  which has no previous run to compare against). A **Findings ended in this
  run** section lists every finding this run resolved, excluded, or
  otherwise closed out (ordered resolved-first, paginated for runs with a
  large baseline).

## Settings

The plugin's settings screen (network admin menu on multisite) opens with a
**status panel**: the current run and last completed run (with their target
tallies, last-activity timestamp, and a diff/alert summary, e.g. `diff: +5 /
−0 / =1, alert: sent`), the next scheduled run time, whether WP-Cron is
enabled, whether Action Scheduler is available (async runs silently fall
back to synchronous execution when it isn't), and the most recent
WP-CLI-triggered run recorded on this site (this only reflects runs
actually recorded here — it cannot detect whether WP-CLI itself is
installed on the server). Below that, you can configure: the daily run time
(UTC, shared by WP-Cron and the REST endpoint's due check), the REST
endpoint's per-request time budget, strict mode (reports readme.txt/readme.md
changes as findings instead of suppressing them as a low-risk "soft change";
off by default), stat-based change detection (on by default), content-hash
comparison for custom plugins and MU-plugin loaders (off by default; see
"Content-hash comparison" above — configuration files and drop-ins are
always content-hashed regardless of this setting), whether to alert on
version changes that did not go through the WordPress updater (on by
default; see Alerts above), alert recipients and the "Send test alert"
button (see Alerts above), the GitHub repository mappings and whether a
GitHub token is configured (see "GitHub Releases verification" above), and
REST token issuance. Hours and minutes of the run time are always shown with
two digits.

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

### Translations

The admin screens, alert emails, and admin notices are translated into
Japanese (`languages/wp-checksum-verifier-ja.po`, compiled to `.mo` and
`.l10n.php`; the latter is what WordPress 6.5+ loads first). WP-CLI messages
are not translated. After changing a translatable string, regenerate the files
(the plugin's own `lib/` and `vendor/` are excluded because `lib/l2d-updater`
uses a different text domain):

```sh
wp i18n make-pot . languages/wp-checksum-verifier.pot --exclude=vendor,lib,tests,bin,node_modules
# update languages/wp-checksum-verifier-ja.po (msgmerge -U), translate new entries
wp i18n make-mo languages
wp i18n make-php languages
composer run test   # TranslationFilesTest: no untranslated/fuzzy entries, placeholders match
```

日本語版は [README-ja.md](README-ja.md) を参照してください。
