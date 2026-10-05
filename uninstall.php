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
// v0.6(プラン§2.1・D1)で追加した更新イベントの記録テーブル.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->base_prefix . 'wpcv_update_events' );
// v0.7(プラン§3.1・U3)で追加したマニフェストキャッシュのテーブル.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->base_prefix . 'wpcv_manifest_cache' );

// DBバージョン(WPCV_Migrator::DB_VERSION_OPTION)・設定画面の値(alert_to等.
// WPCV_Settings::OPTION_NAME)・REST APIトークンのハッシュ(read/write.
// WPCV_Rest_Token::OPTION_NAME/OPTION_NAME_READ)・更新イベント連動の基準時刻
// (v0.6 §2.3. WPCV_Migrator::maybe_record_update_events_since())・「古い履歴を今すぐ削除」の
// 結果(v0.10.0. WPCV_Prune_Job::STATUS_OPTION)を消す. この
// ファイルはプラグイン本体のクラスを読み込まないため、値は直接指定する
// (§7-3是正. v0.5.1. v0.6でwpcv_update_events_sinceを追加).
$wpcv_uninstall_options = array(
	'wpcv_db_version',
	'wpcv_settings',
	'wpcv_rest_token_hash',
	'wpcv_rest_token_hash_read',
	'wpcv_update_events_since',
	// v0.10.0: 「古い履歴を今すぐ削除」の結果(WPCV_Prune_Job::STATUS_OPTION).
	'wpcv_prune_status',
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

// 無効化を経ずに uninstall.php が実行される経路での cron・Action Scheduler の予約について
// (v0.9 プラン §3.4・Step6 で確認). WordPress コアの `delete_plugins()` は無効化を呼ばないが、
// プラグイン画面の削除は有効なプラグインを対象から外す(`wp-admin/plugins.php` の
// `is_plugin_inactive` による絞り込み. WP 6.9.9 の実ソースで確認). 例外はネットワーク管理画面での
// 「サブサイトだけで有効なプラグイン」の削除で、この場合は cron が元々どのサイトにも予約されて
// いない(Step3 の実測)ので、cron は残らない. このファイルはプラグイン本体(同梱の Action Scheduler
// を含む)を読み込まないため `as_unschedule_all_actions()` は呼べないが、下の掃除が WPCV の
// アクションの行を直接消す.

// transient(v0.9 §Step6. プラン §3.4・U7). 名前を完全一致で消す ―― 同梱ライブラリの
// キャッシュは、同じライブラリを使う他のプラグインも `l2dwpghul_updater_` で始まる別のキーを
// 持つため、前方一致で消すと他のプラグインのキャッシュまで消してしまう.
// `delete_site_transient()` は、マルチサイトでは `wp_sitemeta`、単一サイトでは `wp_options` を
// 対象にし、外部オブジェクトキャッシュを使うサイトでもキャッシュ側から消える.
// 対象は2つ: GitHub のレート制限の解除時刻(WPCV_GitHub_Client::RATE_LIMIT_TRANSIENT)と、
// 同梱ライブラリ(lib/l2d-updater)の更新確認のキャッシュ(キーは 'l2dwpghul_updater_' . md5( repo ).
// repo は `wp-checksum-verifier.php` の `github_repo` と同じ値).
delete_site_transient( 'wpcv_github_rate_limited_until' );
delete_site_transient( 'l2dwpghul_updater_' . md5( 'lunaluna/wp-checksum-verifier' ) );

/**
 * サイト単位で残る WPCV の行を消す(現在のサイトのコンテキストで呼ぶ).
 *
 * - REST トークンの認証失敗の回数(transient. `wpcv_rest_token_fail_{md5(識別子)}`).
 *   識別子(IP 等)ごとに名前が変わり列挙できないため、前方一致で消す. `set_transient()` は
 *   リクエストを受けたサイトの `wp_options` に書くので、サイトごとに消す必要がある.
 * - Action Scheduler の WPCV のアクション(フックが `wpcv_` で始まる. 完了済み・取消済みを含む)・
 *   そのログ・グループ `wpcv`. Action Scheduler のテーブルは他のプラグインと共有なので、テーブルは
 *   消さず、WPCV の行だけを消す. 同梱の Action Scheduler はプラグインと一緒に消えるため、残すと
 *   完了済みの行を掃除する者がいなくなる(実測: 3回の run で 224 行).
 *
 * @param wpdb $wpcv_db WordPress のデータベースオブジェクト.
 * @return void
 */
$wpcv_uninstall_clean_site = static function ( $wpcv_db ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- uninstall cleanup. Table names come from $wpdb properties and fixed literals; all values are bound via prepare().
	$wpcv_db->query(
		$wpcv_db->prepare(
			"DELETE FROM {$wpcv_db->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpcv_db->esc_like( '_transient_wpcv_rest_token_fail_' ) . '%',
			$wpcv_db->esc_like( '_transient_timeout_wpcv_rest_token_fail_' ) . '%'
		)
	);

	$wpcv_actions = $wpcv_db->prefix . 'actionscheduler_actions';
	$wpcv_logs    = $wpcv_db->prefix . 'actionscheduler_logs';
	$wpcv_groups  = $wpcv_db->prefix . 'actionscheduler_groups';

	// Action Scheduler のテーブルが無いサイト(未使用・別のプラグインも使っていない)では何もしない.
	if ( $wpcv_actions !== $wpcv_db->get_var( $wpcv_db->prepare( 'SHOW TABLES LIKE %s', $wpcv_db->esc_like( $wpcv_actions ) ) ) ) {
		return;
	}

	$wpcv_hook_like = $wpcv_db->esc_like( 'wpcv_' ) . '%';

	// ログ → アクション → グループの順に消す(参照される側を最後に).
	$wpcv_db->query( $wpcv_db->prepare( "DELETE l FROM {$wpcv_logs} l INNER JOIN {$wpcv_actions} a ON l.action_id = a.action_id WHERE a.hook LIKE %s", $wpcv_hook_like ) );
	$wpcv_db->query( $wpcv_db->prepare( "DELETE FROM {$wpcv_actions} WHERE hook LIKE %s", $wpcv_hook_like ) );
	// 他のプラグインが同じ名前のグループにアクションを入れていれば、グループは残す.
	$wpcv_db->query( $wpcv_db->prepare( "DELETE FROM {$wpcv_groups} WHERE slug = %s AND NOT EXISTS ( SELECT 1 FROM {$wpcv_actions} a WHERE a.group_id = {$wpcv_groups}.group_id )", 'wpcv' ) );
	// phpcs:enable
};

$wpcv_uninstall_clean_site( $wpdb );

// マルチサイト: 他のサイトにも同じ行が残りうる(REST は各サイトで受け付け、Action Scheduler の
// テーブルはサイトごとにある). `uninstall_plugin()` はメインサイトのコンテキストで呼ばれるので
// (上のコメント)、メインサイトは済んでいる. 残りのサイトを順に切り替えて消す. 巨大なネットワーク
// (`wp_is_large_network()`. 既定はサイト数 10,000 超)では、アンインストールの時間が読めないので
// 行わない(残るのは、REST の失敗回数〔TTL 300 秒〕と WPCV の完了済みアクションだけ).
// 未実測: 多数のサイトでの所要時間(サイトごとに数クエリ).
if ( is_multisite() && ! wp_is_large_network( 'sites' ) ) {
	$wpcv_uninstall_main_blog_id = get_current_blog_id();
	$wpcv_uninstall_offset       = 0;
	$wpcv_uninstall_fetched      = 0;

	do {
		$wpcv_uninstall_site_ids = get_sites(
			array(
				'fields'  => 'ids',
				'number'  => 100,
				'offset'  => $wpcv_uninstall_offset,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		foreach ( $wpcv_uninstall_site_ids as $wpcv_uninstall_blog_id ) {
			if ( (int) $wpcv_uninstall_blog_id === (int) $wpcv_uninstall_main_blog_id ) {
				continue;
			}

			switch_to_blog( (int) $wpcv_uninstall_blog_id );
			$wpcv_uninstall_clean_site( $wpdb );
			restore_current_blog();
		}

		$wpcv_uninstall_fetched = count( $wpcv_uninstall_site_ids );
		$wpcv_uninstall_offset += 100;
	} while ( 100 === $wpcv_uninstall_fetched );
}
