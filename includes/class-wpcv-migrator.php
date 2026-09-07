<?php
/**
 * DB スキーマの作成・更新 (dbDelta ベース).
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPCV_DB_VERSION に基づき、installation-level のテーブルを作成・更新する.
 *
 * 検出結果(findings)は物理ファイルシステムに対する事象でありサイト(blog)には
 * 属さないため、テーブルは `$wpdb->prefix` ではなく `$wpdb->base_prefix` を使い、
 * ネットワーク全体で 1 セットだけ作る(マルチサイト全体で共有. 単一サイト環境は
 * サイト 1 つの installation として同一コードパスで扱う).
 *
 * dbDelta は「現在のスキーマ全体の CREATE TABLE」を毎回渡す前提で差分を検出する
 * ため、バージョンごとの ALTER 文は持たず、このファイルのスキーマ定義そのものを
 * 更新していくスタイルを取る。データ変換を伴う移行(型変更に伴う値の書き換え等)が
 * 必要になった場合は、`maybe_upgrade()` 内で dbDelta 実行後にバージョン別の
 * 変換ステップを追加すること.
 */
class WPCV_Migrator {

	/**
	 * DB バージョンを保持する wp_options のキー.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'wpcv_db_version';

	/**
	 * 保存されている DB バージョンが WPCV_DB_VERSION より古ければスキーマを更新する.
	 *
	 * プラグイン有効化時に加え、`plugins_loaded` にもフックする(自動更新で
	 * 有効化フックを経由せずにバージョンが上がるケースに対応するため).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$stored = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $stored >= WPCV_DB_VERSION ) {
			return;
		}

		self::create_or_update_tables();

		update_option( self::DB_VERSION_OPTION, WPCV_DB_VERSION, true );
	}

	/**
	 * 4 テーブル(runs / target_runs / findings / suppressions)を dbDelta で作成・更新する.
	 *
	 * @return void
	 */
	protected static function create_or_update_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$runs_table         = $wpdb->base_prefix . 'wpcv_runs';
		$target_runs_table  = $wpdb->base_prefix . 'wpcv_target_runs';
		$findings_table     = $wpdb->base_prefix . 'wpcv_findings';
		$suppressions_table = $wpdb->base_prefix . 'wpcv_suppressions';

		// §5.2: run 全体の集計値. status = partial は「1 つ以上の target が
		// unverifiable / failed だが run 自体は完走した」を意味する.
		// プラン§5.2は列名を trigger としているが、MySQL/MariaDB の予約語のため
		// バッククォート無しでは CREATE TABLE が構文エラーになる(実際に CI の
		// Plugin Check が実環境の dbDelta 実行で検出した)。DB スキーマは
		// Public API contract に含まれない(§5.1)ため run_trigger に変更した.
		$sql_runs = "CREATE TABLE {$runs_table} (
	id bigint unsigned NOT NULL auto_increment,
	started_at datetime NULL,
	finished_at datetime NULL,
	status varchar(16) NOT NULL default 'running',
	run_trigger varchar(16) NOT NULL default 'cron',
	runner varchar(16) NOT NULL default 'sync',
	targets_total int unsigned NOT NULL default 0,
	targets_verified int unsigned NOT NULL default 0,
	targets_unverifiable int unsigned NOT NULL default 0,
	targets_failed int unsigned NOT NULL default 0,
	findings_total int unsigned NOT NULL default 0,
	notes text NULL,
	PRIMARY KEY (id)
) {$charset_collate};";

		// §5.3: target 単位の検証結果. unverifiable の理由は error_code で必ず
		// コード化する(§5.4 の一覧は WPCV_Error_Code 側で定数として列挙する).
		$sql_target_runs = "CREATE TABLE {$target_runs_table} (
	id bigint unsigned NOT NULL auto_increment,
	run_id bigint unsigned NOT NULL,
	target_id varchar(191) NOT NULL,
	dimension varchar(16) NOT NULL,
	slug varchar(191) NOT NULL,
	version varchar(32) NULL,
	source varchar(16) NULL,
	source_ref varchar(191) NULL,
	manifest_status varchar(24) NOT NULL default 'missing',
	started_at datetime NULL,
	finished_at datetime NULL,
	status varchar(16) NOT NULL default 'success',
	error_code varchar(32) NULL,
	error_message text NULL,
	files_total int unsigned NOT NULL default 0,
	files_verified int unsigned NOT NULL default 0,
	findings_total int unsigned NOT NULL default 0,
	PRIMARY KEY (id),
	KEY idx_run_id (run_id),
	KEY idx_target_id (target_id)
) {$charset_collate};";

		// §5.5: path 単位の検出結果. version を差分キーに含めることで、バージョン
		// 世代ごとの比較(§8.2)を成立させる. unverifiable はここには置かない
		// (target_runs 側の事象。§16-A 参照).
		$sql_findings = "CREATE TABLE {$findings_table} (
	id bigint unsigned NOT NULL auto_increment,
	run_id bigint unsigned NOT NULL,
	target_run_id bigint unsigned NOT NULL,
	target_id varchar(191) NOT NULL,
	dimension varchar(16) NOT NULL,
	slug varchar(191) NOT NULL,
	version varchar(32) NOT NULL,
	source varchar(16) NOT NULL,
	path varchar(500) NOT NULL,
	status varchar(16) NOT NULL,
	severity varchar(8) NOT NULL,
	hash_algorithm varchar(8) NOT NULL,
	expected_hash varchar(64) NULL,
	actual_hash varchar(64) NULL,
	file_size bigint unsigned NULL,
	suppressed_by varchar(16) NULL,
	closed_at datetime NULL,
	closed_reason varchar(24) NULL,
	PRIMARY KEY (id),
	KEY idx_run_id (run_id),
	KEY idx_target_version (target_id, version),
	KEY idx_status (status),
	KEY idx_closed_at (closed_at),
	KEY idx_path (path(191))
) {$charset_collate};";

		// §7: 抑制 3 層(対象除外・パス除外・ハッシュ承認)を 1 テーブルに保持する.
		// 「誰がなぜ許可したか」を必須にするため reason / created_by は NOT NULL.
		$sql_suppressions = "CREATE TABLE {$suppressions_table} (
	id bigint unsigned NOT NULL auto_increment,
	type varchar(20) NOT NULL,
	dimension varchar(16) NULL,
	slug varchar(191) NULL,
	pattern varchar(500) NULL,
	expected_hash varchar(64) NULL,
	hash_algorithm varchar(8) NULL,
	version varchar(32) NULL,
	reason text NOT NULL,
	created_by bigint unsigned NOT NULL default 0,
	created_at datetime NOT NULL,
	expired_at datetime NULL,
	expired_reason varchar(24) NULL,
	PRIMARY KEY (id),
	KEY idx_type_dimension_slug (type, dimension, slug)
) {$charset_collate};";

		dbDelta( $sql_runs );
		dbDelta( $sql_target_runs );
		dbDelta( $sql_findings );
		dbDelta( $sql_suppressions );
	}
}
