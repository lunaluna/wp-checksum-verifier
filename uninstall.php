<?php
/**
 * アンインストール.
 *
 * WordPress は register_uninstall_hook() でこのファイル内の関数を呼び出す.
 * 本プラグインで作成したオプション・テーブルを削除して環境をクリーンに戻す.
 *
 * 設定画面(オプション)の削除処理は、実装するステップに合わせて追記する.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // セキュリティ: 直接アクセスを防止.
}

global $wpdb;

// findings 等は installation-level(§5.6)であり blog 単位ではないため、
// $wpdb->prefix ではなく $wpdb->base_prefix のテーブルを削除する
// (WPCV_Migrator がテーブルを作成する際と同じ規則).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->base_prefix . 'wpcv_findings' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->base_prefix . 'wpcv_target_runs' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->base_prefix . 'wpcv_runs' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->base_prefix . 'wpcv_suppressions' );
// v0.5(rev.3 §3.3)で追加した stat 差分検知のベースラインテーブル.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->base_prefix . 'wpcv_file_states' );

delete_option( 'wpcv_db_version' );

// v0.5後半 §Step14dの時点では`wpcv_alert_streaks`(連続unverifiable等のアラート
// streak. Step15で新設予定)はまだ存在しないが、アンインストール処理は実装した
// ステップに合わせて追記する方針(本ファイル冒頭コメント参照)のため、ここで
// 削除だけ先に用意しておく. `delete_option()`/`delete_site_option()`は対象の
// キーが無くても安全に`false`を返すだけなので、マルチサイト判定を待たず
// 両方呼んでおく.
delete_option( 'wpcv_alert_streaks' );
delete_site_option( 'wpcv_alert_streaks' );
