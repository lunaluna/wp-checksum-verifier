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
	 * v0.4.0コードレビューCR-05是正: `create_or_update_tables()`(`dbDelta()`)の
	 * 実行後、`schema_is_current()` で実際に必要な列がすべて揃ったかを確認して
	 * からでないと `write_stored_version()` を呼ばないようにした。`dbDelta()` は
	 * ALTER権限不足・index作成失敗・DB非互換等でSQLエラーが起きても例外を投げず、
	 * 部分的にしか適用されなかった場合でもそれと分かる形では呼び出し元に伝わらない
	 * (WordPressコアの既知の制約)。確認せずに常に `write_stored_version()` して
	 * いると、実際には移行が失敗しているのに `wpcv_db_version` だけが最新へ
	 * 進んでしまい、以降 `maybe_upgrade()` が(`$stored >= WPCV_DB_VERSION` の
	 * 早期returnにより)二度と再試行しなくなる不具合があった(レビュー指摘)。
	 *
	 * このメソッド自体は例外を投げない(`false` を返すのみ)。`plugins_loaded`
	 * には毎リクエスト無条件でフックされているため、ここで例外を投げると
	 * DB権限の問題が解消するまで**サイト全体が毎リクエスト致命的エラーになる**
	 * (元の不具合よりも被害が大きい退行)。「移行失敗を目に見える形にする」のは
	 * 一度きりの明示的な操作である有効化フック(`WPCV_Activator::activate()`。
	 * WordPress自身が有効化時の致命的エラーを捕捉しプラグインを自動的に
	 * 無効化する)側の責務とし、そちらで戻り値を確認して例外を投げる設計にした.
	 *
	 * @return bool 現在のバージョンが既に最新、または今回の更新でスキーマが
	 *              確認できたら true。更新を試みたがスキーマを確認できなかった
	 *              場合は false(`wpcv_db_version` は更新せず、次回の呼び出しで
	 *              再試行される).
	 */
	public static function maybe_upgrade() {
		$stored = self::get_stored_version();

		if ( $stored >= WPCV_DB_VERSION ) {
			return true;
		}

		self::create_or_update_tables();

		if ( ! self::schema_is_current() ) {
			return false;
		}

		self::write_stored_version( WPCV_DB_VERSION );

		return true;
	}

	/**
	 * 保存済みの DB バージョンを読み取る(v0.3.1 §Step5).
	 *
	 * `WPCV_API::is_available()`(WPMAR等の依存チェック用の公開関数)も
	 * このメソッドを使う。以前は `get_option()` を直接読んでおり、下記と
	 * 同じマルチサイト非対応のバグを独自に抱えていたため、正の在り処を
	 * このメソッド1箇所に一本化した(ロジック重複の排除).
	 *
	 * テーブル自体は `base_prefix` ベースの installation-level(クラス docblock
	 * 参照)だが、v0.3.0時点では DB バージョンを常に(マルチサイトでも)
	 * blog 単位の `wp_options` に保存していた。そのため別 blog を初めて
	 * ロードするたびに `wpcv_db_version` が未設定(0)と判定され、
	 * `create_or_update_tables()` が blog の数だけ重複実行されていた
	 * (プラン§P2「DB schema versionがマルチサイトでblog単位」への対策)。
	 *
	 * マルチサイトでは `wp_sitemeta` の site option を正とする。ただし
	 * site option が未設定(＝このバージョンのコードでまだ一度も書き込んで
	 * いない)場合は、旧実装が main site の `wp_options` に書き込んでいた値を
	 * 移行時の初期値として参照し、その場で site option 側へ書き込んでおく
	 * (無条件に dbDelta を再実行させないため。加えて、書き込まずにいると
	 * 次回以降のリクエストのたびにサブサイトで `switch_to_blog()` を伴う
	 * フォールバック読み取りが繰り返されてしまうため、読み取り時点でキャッシュする).
	 *
	 * @return int
	 */
	public static function get_stored_version() {
		if ( ! is_multisite() ) {
			return (int) get_option( self::DB_VERSION_OPTION, 0 );
		}

		$site_version = get_site_option( self::DB_VERSION_OPTION, null );

		if ( null !== $site_version ) {
			return (int) $site_version;
		}

		$legacy_version = (int) get_blog_option( get_main_site_id(), self::DB_VERSION_OPTION, 0 );

		update_site_option( self::DB_VERSION_OPTION, $legacy_version );

		return $legacy_version;
	}

	/**
	 * DB バージョンを保存する(v0.3.1 §Step5. マルチサイトでは site option へ).
	 *
	 * @param int $version 保存するバージョン.
	 * @return void
	 */
	private static function write_stored_version( $version ) {
		if ( is_multisite() ) {
			update_site_option( self::DB_VERSION_OPTION, $version );
			return;
		}

		update_option( self::DB_VERSION_OPTION, $version, true );
	}

	/**
	 * 4 テーブル(runs / target_runs / findings / suppressions)を dbDelta で作成・更新する.
	 *
	 * @return void
	 */
	protected static function create_or_update_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::table_definitions() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * 実DBの4テーブルが `table_definitions()` の期待する列をすべて持っているかを
	 * 検証する(v0.4.0コードレビューCR-05是正。`maybe_upgrade()` のクラス
	 * docblock参照)。
	 *
	 * @return bool 4テーブルすべてが期待する列を持っていれば true.
	 */
	protected static function schema_is_current() {
		global $wpdb;

		foreach ( self::expected_columns_by_table() as $table => $expected_columns ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only (base_prefix + 固定のテーブル名文字列。ユーザー入力を含まない).
			$actual_columns = $wpdb->get_col( "DESCRIBE {$table}" );
			$actual_columns = is_array( $actual_columns ) ? $actual_columns : array();

			foreach ( $expected_columns as $expected_column ) {
				if ( ! in_array( $expected_column, $actual_columns, true ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * `table_definitions()` の CREATE TABLE 文から、テーブル名 => 期待される
	 * 列名の配列を組み立てる(`schema_is_current()` 専用のヘルパー).
	 *
	 * @return array<string, string[]>
	 */
	private static function expected_columns_by_table() {
		global $wpdb;

		$tables = array(
			$wpdb->base_prefix . 'wpcv_runs',
			$wpdb->base_prefix . 'wpcv_target_runs',
			$wpdb->base_prefix . 'wpcv_findings',
			$wpdb->base_prefix . 'wpcv_suppressions',
		);

		$by_table = array();

		foreach ( array_combine( $tables, self::table_definitions() ) as $table => $sql ) {
			$by_table[ $table ] = self::parse_column_names( $sql );
		}

		return $by_table;
	}

	/**
	 * 1つの CREATE TABLE 文から列名だけを抽出する(`PRIMARY KEY`/`KEY` 行は除く。
	 * `expected_columns_by_table()` 専用のヘルパー)。
	 *
	 * `table_definitions()` のSQLは「1列 = 1行、行頭が列名」という単純な整形
	 * ルールで書かれているため、この前提に依存した簡易パーサーで十分(汎用的な
	 * SQL構文解析は行わない).
	 *
	 * @param string $sql CREATE TABLE 文.
	 * @return string[]
	 */
	private static function parse_column_names( $sql ) {
		$columns = array();

		foreach ( explode( "\n", $sql ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || 0 === stripos( $line, 'CREATE TABLE' ) || 0 === stripos( $line, 'PRIMARY KEY' ) || 0 === stripos( $line, 'KEY ' ) || 0 === strpos( $line, ')' ) ) {
				continue;
			}

			if ( 1 === preg_match( '/^(\w+)\s/', $line, $matches ) ) {
				$columns[] = $matches[1];
			}
		}

		return $columns;
	}

	/**
	 * 4 テーブル分の CREATE TABLE 文を組み立てて返す(`create_or_update_tables()` から分離).
	 *
	 * `global $wpdb` にしか依存しない純粋な文字列組み立てのため、単体テストから
	 * `ReflectionMethod` 経由で呼び出し、`WPCV_DB_VERSION` を上げた際に必要な
	 * 列・indexが SQL に含まれているかを実 DB 無しで検証できるようにする
	 * (v0.4.0 §Step1: 「migrationの再実行性」の確認は実 DB が必要なため実地検証
	 * 側の責務のままだが、「スキーマ定義に列が漏れていないか」はここで検証可能にする).
	 *
	 * @return string[] CREATE TABLE 文の配列(dbDelta に渡す順序).
	 */
	protected static function table_definitions() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$runs_table         = $wpdb->base_prefix . 'wpcv_runs';
		$target_runs_table  = $wpdb->base_prefix . 'wpcv_target_runs';
		$findings_table     = $wpdb->base_prefix . 'wpcv_findings';
		$suppressions_table = $wpdb->base_prefix . 'wpcv_suppressions';

		// §5.2: run 全体の集計値. status = partial は「1 つ以上の target が
		// unverifiable / failed だが run 自体は完走した」を意味する。
		// scheduled_for/heartbeat_at/deadline_at は v0.4.0 §Step1 で追加(日次due判定・
		// stale worker検知・run deadline超過sweepに使う。いずれもStep1時点では
		// 列を用意するのみで、書き込むロジックはStep2以降で追加する).
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
	scheduled_for datetime NULL,
	heartbeat_at datetime NULL,
	deadline_at datetime NULL,
	targets_total int unsigned NOT NULL default 0,
	targets_verified int unsigned NOT NULL default 0,
	targets_unverifiable int unsigned NOT NULL default 0,
	targets_failed int unsigned NOT NULL default 0,
	findings_total int unsigned NOT NULL default 0,
	notes text NULL,
	PRIMARY KEY (id)
) {$charset_collate};";

		// §5.3: target 単位の検証結果. unverifiable の理由は error_code で必ず
		// コード化する(§5.4 の一覧は WPCV_Error_Code 側で定数として列挙する)。
		// cursor_path/manifest_fingerprint/attempt_count/heartbeat_at/lease_owner/
		// lease_expires_at/retry_after は v0.4.0 §Step1 で追加(chunk単位の分割実行・
		// resume・lease制御に使う。列名の意味は §Step3・Step4 参照。Step1時点では
		// 列を用意するのみ)。idx_run_status は claim クエリ
		// (`status IN ('queued','retry') AND run_id = ?`)用に追加.
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
	cursor_path varchar(500) NULL,
	manifest_fingerprint varchar(64) NULL,
	attempt_count int unsigned NOT NULL default 0,
	heartbeat_at datetime NULL,
	lease_owner varchar(191) NULL,
	lease_expires_at datetime NULL,
	retry_after datetime NULL,
	PRIMARY KEY (id),
	KEY idx_run_id (run_id),
	KEY idx_target_id (target_id),
	KEY idx_run_status (run_id, status)
) {$charset_collate};";

		// §5.5: path 単位の検出結果. version を差分キーに含めることで、バージョン
		// 世代ごとの比較(§8.2)を成立させる. unverifiable はここには置かない
		// (target_runs 側の事象。§16-A 参照)。suppression_id は v0.4.0 §Step1で
		// 追加(ユーザー作成の抑制ルール `wpcv_suppressions.id` への参照。既存の
		// `suppressed_by`(system suppressionの理由コード文字列)とは別列にし、
		// ユーザー作成ルールを監査可能な参照として持てるようにする. §Step8参照).
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
	suppression_id bigint unsigned NULL,
	closed_at datetime NULL,
	closed_reason varchar(24) NULL,
	PRIMARY KEY (id),
	KEY idx_run_id (run_id),
	KEY idx_target_version (target_id, version),
	KEY idx_status (status),
	KEY idx_closed_at (closed_at),
	KEY idx_path (path(191)),
	KEY idx_suppression_id (suppression_id)
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

		return array( $sql_runs, $sql_target_runs, $sql_findings, $sql_suppressions );
	}
}
