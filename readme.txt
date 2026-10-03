=== WP Checksum Verifier ===
Contributors: lunaluna_dev
Tags: security, checksum, integrity, malware, audit
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress core, plugin, theme, and must-use plugin checksum verifier. Detects tampering against official checksum manifests.

== Description ==

WP Checksum Verifier compares the files on disk against official checksum manifests for WordPress core and official plugins, and against manifests built from the WordPress.org zips of official themes, and reports unknown files not present in any manifest (including must-use plugins). It is not published on the WordPress.org Plugin Directory; see README.md for distribution details.

Plugins and themes distributed through GitHub Releases can be mapped to a repository and verified against the Release asset for the installed version; see README.md (GitHub Releases verification).

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
