# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Added

- Initial project scaffolding (bootstrap, build tooling, CI/release workflows).
- Database schema (v0.1): `wpcv_runs`, `wpcv_target_runs`, `wpcv_findings`,
  `wpcv_suppressions` tables via `WPCV_Migrator` (installation-level,
  `$wpdb->base_prefix`), with `WPCV_Activator` wiring activation and
  `plugins_loaded` upgrade checks.
- `WPCV_Error_Code`: the v0.1 `error_code` enumeration.
- `WPCV_Target_Resolver`: `target_id` generation/parsing for core, plugin,
  theme, and must-use plugin targets.
- Public API (§10): `wpcv_get_latest_run()`, `wpcv_get_latest_target_runs()`,
  `wpcv_get_latest_findings()`, `wpcv_is_available()`, plus the
  `wpcv_api_findings` / `wpcv_api_target_runs` filters.
- Admin menu skeleton: a top-level settings page (network admin menu on
  multisite), placeholder content only.
