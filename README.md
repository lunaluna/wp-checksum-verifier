# WP Checksum Verifier

WordPress core, plugin, theme, and must-use plugin checksum verifier. Detects
tampering by comparing installed files against official checksum manifests
(wp.org core/plugin checksums, self-extracted theme archives, and GitHub
Releases for unofficial plugins/themes).

> **Status**: under active development (v0.1, not yet released). See
> `CHANGELOG.md` for progress.

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
