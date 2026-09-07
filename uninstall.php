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

delete_option( 'wpcv_db_version' );
