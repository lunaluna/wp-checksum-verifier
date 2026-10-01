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
	 * D6の基準時刻を保持する wp_options のキー(v0.6 §2.3。
	 * `maybe_record_update_events_since()`/`get_update_events_since()` 参照).
	 *
	 * @var string
	 */
	const UPDATE_EVENTS_SINCE_OPTION = 'wpcv_update_events_since';

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

		self::maybe_record_update_events_since();
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
	 * 6 テーブル(runs / target_runs / findings / suppressions / file_states /
	 * update_events)を dbDelta で作成・更新する. file_states は v0.5(rev.3 §3.3)、
	 * update_events は v0.6(§2.1)で追加.
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
	 * 実DBの5テーブルが `table_definitions()` の期待する列をすべて持っているかを
	 * 検証する(v0.4.0コードレビューCR-05是正。`maybe_upgrade()` のクラス
	 * docblock参照)。
	 *
	 * @return bool 5テーブルすべてが期待する列を持っていれば true.
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
			$wpdb->base_prefix . 'wpcv_file_states',
			$wpdb->base_prefix . 'wpcv_update_events',
		);

		$by_table = array();

		foreach ( array_combine( $tables, self::table_definitions() ) as $table => $sql ) {
			$by_table[ $table ] = self::parse_column_names( $sql );
		}

		return $by_table;
	}

	/**
	 * インデックス・制約定義の行の先頭に来るキーワード(`parse_column_names()` 専用).
	 *
	 * これらで始まる行は列定義ではないため、列名として扱わない. v0.5 で
	 * `wpcv_file_states` に `UNIQUE KEY idx_state_key (state_key)` を追加した際、
	 * 以前のパーサーが `PRIMARY KEY`/`KEY` 行しか除外しておらず `UNIQUE` を列名と
	 * 誤認したため、実DBに存在しない列を期待して `schema_is_current()` が常に
	 * false になり、有効化が必ず失敗する不具合があった. 今後 dbDelta が解釈できる
	 * 他の種類のインデックス行を追加しても同じ事故にならないよう、まとめて列挙する.
	 *
	 * @var string[]
	 */
	const NON_COLUMN_LINE_KEYWORDS = array( 'PRIMARY', 'KEY', 'INDEX', 'UNIQUE', 'FULLTEXT', 'SPATIAL', 'CONSTRAINT', 'FOREIGN', 'CHECK' );

	/**
	 * 1つの CREATE TABLE 文から列名だけを抽出する(インデックス・制約の行は除く。
	 * `expected_columns_by_table()` 専用のヘルパー)。
	 *
	 * `table_definitions()` のSQLは「1列 = 1行、行頭が列名」という単純な整形
	 * ルールで書かれているため、この前提に依存した簡易パーサーで十分(汎用的な
	 * SQL構文解析は行わない). 行頭の単語が `NON_COLUMN_LINE_KEYWORDS` に
	 * 含まれる行はインデックス・制約定義として読み飛ばす.
	 *
	 * @param string $sql CREATE TABLE 文.
	 * @return string[]
	 */
	private static function parse_column_names( $sql ) {
		$columns = array();

		foreach ( explode( "\n", $sql ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || 0 === stripos( $line, 'CREATE TABLE' ) || 0 === strpos( $line, ')' ) ) {
				continue;
			}

			if ( 1 !== preg_match( '/^(\w+)\s/', $line, $matches ) ) {
				continue;
			}

			// 行頭がインデックス・制約のキーワードなら列定義ではない(大文字小文字は区別しない).
			if ( in_array( strtoupper( $matches[1] ), self::NON_COLUMN_LINE_KEYWORDS, true ) ) {
				continue;
			}

			$columns[] = $matches[1];
		}

		return $columns;
	}

	/**
	 * 6 テーブル分の CREATE TABLE 文を組み立てて返す(`create_or_update_tables()` から分離).
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

		$runs_table          = $wpdb->base_prefix . 'wpcv_runs';
		$target_runs_table   = $wpdb->base_prefix . 'wpcv_target_runs';
		$findings_table      = $wpdb->base_prefix . 'wpcv_findings';
		$suppressions_table  = $wpdb->base_prefix . 'wpcv_suppressions';
		$file_states_table   = $wpdb->base_prefix . 'wpcv_file_states';
		$update_events_table = $wpdb->base_prefix . 'wpcv_update_events';

		// §5.2: run 全体の集計値. status = partial は「1 つ以上の target が
		// unverifiable / failed だが run 自体は完走した」を意味する。
		// scheduled_for/heartbeat_at/deadline_at は v0.4.0 §Step1 で追加(日次due判定・
		// stale worker検知・run deadline超過sweepに使う。いずれもStep1時点では
		// 列を用意するのみで、書き込むロジックはStep2以降で追加する).
		// プラン§5.2は列名を trigger としているが、MySQL/MariaDB の予約語のため
		// バッククォート無しでは CREATE TABLE が構文エラーになる(実際に CI の
		// Plugin Check が実環境の dbDelta 実行で検出した)。DB スキーマは
		// Public API contract に含まれない(§5.1)ため run_trigger に変更した.
		// diff_status以下は v0.5後半 §Step10(差分検出基盤・アラート)で追加. §3.1の
		// 状態遷移(NULL→pending→processing→alerting→done. 失敗時skipped/failed)を
		// 持つ. diff_owner/diff_lease_expires_at/diff_attempt_countは
		// target_runsのlease方式(lease_owner/lease_expires_at/attempt_count)を
		// 差分処理向けに流用したもの. diff_cursorは2 pass分の位置(JSON文字列)を
		// 保持する. findings_new/resolved/continuingとalert_*はStep12〜15で
		// 書き込みロジックを追加するまでは常にNULLのまま(Step10時点では列を
		// 用意するのみ).
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
	diff_status varchar(16) NULL,
	diff_owner varchar(64) NULL,
	diff_lease_expires_at datetime NULL,
	diff_attempt_count int unsigned NOT NULL default 0,
	diff_cursor text NULL,
	findings_new int unsigned NULL,
	findings_resolved int unsigned NULL,
	findings_continuing int unsigned NULL,
	alert_status varchar(16) NULL,
	alert_attempted_at datetime NULL,
	alert_error varchar(500) NULL,
	alert_channel_failures varchar(500) NULL,
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
		// baseline_target_run_id/diff_modeは v0.5後半 §Step10で追加(§2.1の
		// diff_modeの値〔compared/version_changed/first/not_verified/excluded/
		// event/skipped〕と、比較に使った基準target_runへの参照. 書き込みは
		// 差分処理〔Step12〕が行う. idx_target_status_runは基準target_runの検索
		// (target_id・status='success'・run_id<今回)に使う.
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
	baseline_target_run_id bigint unsigned NULL,
	diff_mode varchar(16) NULL,
	PRIMARY KEY (id),
	KEY idx_run_id (run_id),
	KEY idx_target_id (target_id),
	KEY idx_run_status (run_id, status),
	KEY idx_target_status_run (target_id, status, run_id)
) {$charset_collate};";

		// §5.5: path 単位の検出結果. version を差分キーに含めることで、バージョン
		// 世代ごとの比較(§8.2)を成立させる. unverifiable はここには置かない
		// (target_runs 側の事象。§16-A 参照)。suppression_id は v0.4.0 §Step1で
		// 追加(ユーザー作成の抑制ルール `wpcv_suppressions.id` への参照。既存の
		// `suppressed_by`(system suppressionの理由コード文字列)とは別列にし、
		// ユーザー作成ルールを監査可能な参照として持てるようにする. §Step8参照).
		// detail は rev.3 §3.5 で追加(stat差分検知用)。`stat_changed` status は
		// expected_hash/actual_hash が空になるため、「何が変わったのか」を
		// JSON文字列(size/ctime/mtime の old/new と timestomp フラグ)で保持する.
		// 他の status では NULL のまま(用途は stat_changed に限定. §3.5参照).
		// finding_key以下は v0.5後半 §Step10で追加(§1.1・§1.4参照). finding_keyは
		// `WPCV_Finding_Key::compute()` で保存時に計算する差分キー(v4より前の行は
		// NULLのまま. 移行処理での一括計算は行わない). diff_state/notified_at/
		// ended_in_run_id/end_reasonの書き込みは差分処理(Step12)が行う.
		// ended_in_run_id/end_reasonを書いても既存の`query()`の絞り込み
		// (closed_at基準)には影響しない(過去のrunを開いたときにfindingが
		// 消えないようにするための設計. D3参照).
		// idx_target_run_id_seqはv0.5後半 §Step12で追加(schema v5). 差分処理の
		// `find_batch_by_target_run()`/`find_baseline_batch()`が発行する
		// `WHERE target_run_id=? AND id>? ORDER BY id ASC LIMIT ?`は、既存の
		// `idx_target_run_key(target_run_id, finding_key)`ではid順に読めず、
		// 実地検証(test-armfu.local、1万・10万件規模)でPRIMARY(id)を使う
		// クエリプランになっていることが判明した(テーブル全体の件数に比例して
		// コストが増える). `(target_run_id, id)`の複合indexを追加し、
		// target_run単位でid順に直接絞り込めるようにする.
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
	detail text NULL,
	finding_key char(64) NULL,
	diff_state varchar(12) NULL,
	notified_at datetime NULL,
	ended_in_run_id bigint unsigned NULL,
	end_reason varchar(24) NULL,
	PRIMARY KEY (id),
	KEY idx_run_id (run_id),
	KEY idx_target_version (target_id, version),
	KEY idx_status (status),
	KEY idx_closed_at (closed_at),
	KEY idx_path (path(191)),
	KEY idx_suppression_id (suppression_id),
	KEY idx_target_run_key (target_run_id, finding_key),
	KEY idx_run_diff (run_id, diff_state),
	KEY idx_key_notified (finding_key, notified_at),
	KEY idx_ended_run (ended_in_run_id),
	KEY idx_target_run_id_seq (target_run_id, id)
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

		// rev.3 §3.3: stat 差分検知(層1)のベースライン. チェックサム照合ができない
		// target(独自・有料プラグイン等)について、前回スキャン時点の path/size/
		// ctime/mtime を保持し、次回スキャンとの差分で変更・新規・削除を検出する.
		//
		// `state_key`(`sha256( target_id . "\0" . path )` の生バイト)を BINARY(32)
		// にしているのは、`UNIQUE KEY (target_id, path)` だと utf8mb4 環境で index
		// prefix の上限(COMPACT 行形式で767バイト)を超えうるため. BINARY(32) は
		// 照合順序に依存せず32バイト固定で、古い MySQL/MariaDB でも確実に作成できる.
		// `target_id` はクエリ・可読性のため別列としても保持する(state_key からは
		// 逆引きできないため).
		//
		// 削除検出は `last_seen_run_id` 方式(§3.3参照)。ファイル単位の分割実行
		// (chunk / cursor による resume)と両立させるため、「今回走査した path
		// 集合」をリクエストをまたいで保持する方式は取れない。代わりに chunk ごとの
		// upsert で `last_seen_run_id` を今回の run_id に更新し、target が完走した
		// 最終 chunk でのみ `last_seen_run_id < 今回の run_id` の行を削除対象とする
		// (このクエリには (target_id, last_seen_run_id) の複合indexが効く).
		//
		// ctime/mtime は Unix time で保持する(絶対日付比較を採らない理由は
		// §3.2-a参照)。`content_hash`/`hash_algorithm` は層2(v0.6以降のオプトイン。
		// §3.8)専用で層1では常に NULL. `baseline_version` はベースライン作成時点の
		// 対象 target の version(正規更新によるベースライン再構築判定. §3.7-a参照).
		$sql_file_states = "CREATE TABLE {$file_states_table} (
	id bigint unsigned NOT NULL auto_increment,
	state_key binary(32) NOT NULL,
	target_id varchar(191) NOT NULL,
	dimension varchar(16) NOT NULL,
	slug varchar(191) NOT NULL,
	path varchar(500) NOT NULL,
	file_size bigint unsigned NOT NULL,
	ctime bigint NOT NULL,
	mtime bigint NOT NULL,
	content_hash char(64) NULL,
	hash_algorithm varchar(8) NULL,
	baseline_version varchar(32) NULL,
	first_seen_run_id bigint unsigned NOT NULL,
	last_seen_run_id bigint unsigned NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY idx_state_key (state_key),
	KEY idx_target_last_seen (target_id, last_seen_run_id),
	KEY idx_path (path(191))
) {$charset_collate};";

		// v0.6 §2.1: 更新イベントの記録(D1〜D5参照). 追記のみのテーブルで、option
		// のような単一の値を読み書きする方式にしなかった理由は D1 参照
		// (同時に2つの更新が走ると片方の記録が失われ、誤った通知につながるため).
		// `created_by` は `get_current_user_id()`(cron・CLIでは0. §2.1).
		// version はフックの時点でディスクから読み直した値で、読めなければ NULL
		// (D3・D4参照. 成否は判定しない).
		// idx_target_event は D5 の突き合わせ(target_id・version一致 かつ
		// event_at が基準target_runのrun開始より後)に使う. idx_event_at は
		// 掃除(`delete_older_than()`. run終端での呼び出しはStep2以降)に使う.
		$sql_update_events = "CREATE TABLE {$update_events_table} (
	id bigint unsigned NOT NULL auto_increment,
	target_id varchar(191) NOT NULL,
	version varchar(32) NULL,
	event_at datetime NOT NULL,
	source varchar(24) NOT NULL,
	created_by bigint unsigned NULL,
	PRIMARY KEY (id),
	KEY idx_target_event (target_id, event_at),
	KEY idx_event_at (event_at)
) {$charset_collate};";

		return array( $sql_runs, $sql_target_runs, $sql_findings, $sql_suppressions, $sql_file_states, $sql_update_events );
	}

	/**
	 * `wpcv_update_events_since`(D6)を、まだ保存されていなければ現在時刻で保存する.
	 *
	 * `v0.5.x` → `v0.6.0` への更新そのものは、更新イベントを記録するフックが
	 * まだ登録されていない古いコードで走るため、WPCV自身のバージョン変化には
	 * 更新イベントが残らない(D6参照)。この値を「更新イベント連動の突き合わせを
	 * 開始した時刻」の基準として保存しておき、基準target_runのrun開始がこれより
	 * 前なら「記録なし」を理由に通知しない、という運用にする(§3.1参照).
	 *
	 * 新規インストール(v0 → v6)でも同じロジックで保存する(D6の趣旨が
	 * 「WPCVのバージョンアップでフックが間に合わなかった」ことなので新規
	 * インストールでは本来不要だが、値を保存しておいても無害であり、
	 * 分岐を増やさないほうが単純なため).
	 *
	 * `maybe_upgrade()` からスキーマ確認後にのみ呼ぶ(`$stored >= WPCV_DB_VERSION` の
	 * 早期returnでは呼ばれない). 既に値がある場合は何もしない(冪等. 将来
	 * v7以降に上がる際にもこのメソッドは呼ばれ続けるが、副作用は無い).
	 *
	 * @return void
	 */
	private static function maybe_record_update_events_since() {
		if ( is_multisite() ) {
			if ( null === get_site_option( self::UPDATE_EVENTS_SINCE_OPTION, null ) ) {
				update_site_option( self::UPDATE_EVENTS_SINCE_OPTION, gmdate( 'Y-m-d H:i:s' ) );
			}
			return;
		}

		if ( false === get_option( self::UPDATE_EVENTS_SINCE_OPTION, false ) ) {
			update_option( self::UPDATE_EVENTS_SINCE_OPTION, gmdate( 'Y-m-d H:i:s' ), true );
		}
	}

	/**
	 * `wpcv_update_events_since`(D6)を読み取る(v0.6 §Step3から呼ばれる想定).
	 *
	 * 基準target_runのrun開始時刻がこれより前なら「期間外」とみなし、更新イベント
	 * の記録なしを理由にした通知(§3.1)を出さない。値が無い(=`maybe_upgrade()`が
	 * まだ一度もv6のスキーマ確認を終えていない、通常は起こらない状態)場合は
	 * `null`を返す ―― 呼び出し側は`null`を「期間外」と同じ扱いにする想定
	 * (安全側: 基準時刻が無いのに「期間内」と誤判定して通知しないため).
	 *
	 * @return string|null UTCのMySQL DATETIME文字列、または未設定なら `null`.
	 */
	public static function get_update_events_since() {
		$value = is_multisite()
			? get_site_option( self::UPDATE_EVENTS_SINCE_OPTION, null )
			: get_option( self::UPDATE_EVENTS_SINCE_OPTION, null );

		return ( null === $value || false === $value || '' === $value ) ? null : (string) $value;
	}
}
