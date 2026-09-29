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

// DBバージョン(WPCV_Migrator::DB_VERSION_OPTION)・設定画面の値(alert_to等.
// WPCV_Settings::OPTION_NAME)・REST APIトークンのハッシュ(read/write.
// WPCV_Rest_Token::OPTION_NAME/OPTION_NAME_READ)を消す. このファイルは
// プラグイン本体のクラスを読み込まないため、値は直接指定する(§7-3是正. v0.5.1).
$wpcv_uninstall_options = array(
	'wpcv_db_version',
	'wpcv_settings',
	'wpcv_rest_token_hash',
	'wpcv_rest_token_hash_read',
);

// `uninstall_plugin()`(WordPressコア)はネットワーク管理画面から実行された
// 場合でも switch_to_blog() を行わず、常にメインサイト(blog 1)のコンテキストで
// 呼ばれる(実ソース wp-admin/includes/plugin.php で確認済み). そのため
// delete_option() は常に呼ぶ ―― v0.3.0以前はマルチサイトでもDBバージョンを
// メインサイトの wp_options に保存していた名残(WPCV_Migrator::get_stored_version()
// のdocblock参照)が残っていた場合の掃除にもなる.
foreach ( $wpcv_uninstall_options as $wpcv_uninstall_option ) {
	delete_option( $wpcv_uninstall_option );
}

// マルチサイトでは v0.3.1 以降、上記4つとも `wp_sitemeta` の site option に
// 保存している(WPCV_Migrator::write_stored_version()・WPCV_Settings::write_option()・
// WPCV_Rest_Token::store_hash() がいずれも is_multisite() で分岐). ここを
// 消さずに残すと、削除→再インストール時に `WPCV_Migrator::get_stored_version()`
// が古いバージョンを読み、`maybe_upgrade()` が早期returnしてテーブルを
// 作らない不具合になる(rev.3/roadmap §2.1 ★1a. 実地検証で確認済み).
if ( is_multisite() ) {
	foreach ( $wpcv_uninstall_options as $wpcv_uninstall_option ) {
		delete_site_option( $wpcv_uninstall_option );
	}
}

// Action Scheduler に予約済みの継続アクション(chunk継続・async起動)は
// ここでは対応しない ―― 無効化フック(WPCV_Chunk_Dispatcher::deactivate()。
// wp-checksum-verifier.phpのregister_deactivation_hook参照)で既に
// キャンセル済みのはず(アンインストールは必ず無効化後にしか実行されない.
// 同メソッドのdocblock参照). このファイルはプラグイン本体(bundled Action
// Scheduler含む)を読み込まないため、ここから `as_unschedule_all_actions()`
// を呼ぶこと自体ができない(v0.5.1. §7-3是正).
