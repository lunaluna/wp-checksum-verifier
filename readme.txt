=== WP Checksum Verifier ===
Contributors: lunaluna_dev
Tags: security, checksum, integrity, malware, audit
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress core, plugin, theme, and must-use plugin checksum verifier. Detects tampering against official checksum manifests.

== Description ==

WP Checksum Verifier compares the files on disk against official checksum manifests for WordPress core, official plugins, and must-use plugins, and reports unknown files not present in any manifest. It is not published on the WordPress.org Plugin Directory; see README.md for distribution details.

Official theme and GitHub-hosted plugin/theme verification are not implemented yet; see CHANGELOG.md for progress.

= Running a verification =

* **WP-CLI**: `wp wpcv run` runs synchronously (the default). `wp wpcv run --async` enqueues the run via Action Scheduler when available.
* **WP-Cron**: a daily run at a configurable UTC time (Settings screen), enabled automatically once the plugin is active.
* **Admin button**: a "Run now" button on the settings screen schedules an immediate run.
* **REST API**: `POST /wp-json/wpcv/v1/run`, authenticated with a bearer token issued from the settings screen — for external schedulers (e.g. managed hosting without WP-Cron).

== Installation ==

1. Download a release zip from GitHub Releases.
2. Upload and activate it like any other plugin.

== Changelog ==

See CHANGELOG.md.
