<?php
/**
 * WPCV_Data_Usage クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * このプラグインが DB に保存しているデータの量(テーブルごとの行数・容量)と、一番古い run の日時を
 * 返す(v0.10.0 P1. プラン §8.2. 設定画面の「保存しているデータ」の表の元データ).
 *
 * 取り方は `information_schema.tables` の `table_rows`・`data_length`・`index_length` 1クエリと、
 * `MIN(started_at)` 1クエリ. 正確な `COUNT(*)` は大きなサイトで画面の表示が遅くなるので使わない.
 * InnoDB の `table_rows` は**推定値**で、統計は最大 `information_schema_stats_expiry` 秒
 * (MySQL 8.4 の既定は 86400 = 24時間)古いことがあるため、画面には「おおよそ」と書く.
 *
 * 実測(2026-10-06. test-armfu.local・MySQL 8.4.0・同じクエリを1セッションで50回実行しクライアント起動分を引いた):
 * `information_schema.tables`(7テーブル)は 1回 0.38 ms、`MIN(started_at)`(136 行)は 0.06 ms.
 * どちらも画面を開くたびに実行して問題ない速さなので、結果のキャッシュはしない.
 * **共有ホスティングなどでの速さは未測定**(`information_schema` を読めない環境では、値が返らなければ
 * 「取得できません」と表示する. 例外にはしない).
 *
 * Action Scheduler のテーブルは他のプラグインと共有で、完了したアクションは Action Scheduler 自身が
 * 31日で消すため、ここには含めない(実測: test-armfu.local で `wpcv_` のアクション 3,716 行を数えるクエリは
 * 3.3 ms で、行数に比例して遅くなる).
 *
 * テーブルは installation-level(`base_prefix`)なので、マルチサイトでもネットワークで1組だけ数える.
 */
class WPCV_Data_Usage {

	/**
	 * 数えるテーブル(接頭辞なし. 表示の順).
	 *
	 * @var string[]
	 */
	const TABLES = array(
		'wpcv_runs',
		'wpcv_target_runs',
		'wpcv_findings',
		'wpcv_suppressions',
		'wpcv_file_states',
		'wpcv_update_events',
		'wpcv_manifest_cache',
	);

	/**
	 * データベースオブジェクト(`wpdb` またはテストダブル).
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * コンストラクタ.
	 *
	 * @param object $wpdb `wpdb` またはテストダブル.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * テーブルごとの行数・容量と、一番古い run の日時を返す.
	 *
	 * @return array{
	 *     tables: array<string, array{rows: int, bytes: int}>|null,
	 *     missing: string[],
	 *     oldest_run_at: string|null
	 * } `tables` は `information_schema` から取れなければ `null`(テーブルが1つも返らない場合を含む).
	 *   キーは接頭辞なしのテーブル名(`TABLES` の要素). `missing` は、ほかのテーブルは返ったのに返らなかった
	 *   テーブル(接頭辞なし. 0.10.0 のコードレビュー指摘4). 一部だけが欠けた状態を、取得できた分の合計だけで
	 *   「全部」に見せないため. `tables` が `null` のときは、権限が無いのかテーブルが無いのか区別できないので空.
	 *   `oldest_run_at` は run が無ければ `null`(UTC).
	 */
	public function collect() {
		$tables  = $this->collect_tables();
		$missing = array();

		if ( null !== $tables ) {
			$missing = array_values( array_diff( self::TABLES, array_keys( $tables ) ) );
		}

		return array(
			'tables'        => $tables,
			'missing'       => $missing,
			'oldest_run_at' => $this->oldest_run_at(),
		);
	}

	/**
	 * `information_schema.tables` から、このプラグインのテーブルの行数と容量を読む.
	 *
	 * @return array<string, array{rows: int, bytes: int}>|null 取れなければ `null`.
	 */
	private function collect_tables() {
		$prefix = $this->wpdb->base_prefix;
		$names  = array();

		foreach ( self::TABLES as $table ) {
			$names[] = $prefix . $table;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );

		// 列名は MySQL のバージョンで大文字小文字が変わりうるので、別名で小文字にそろえる.
		$sql = "SELECT table_name AS name, table_rows AS row_count, data_length AS data_bytes, index_length AS index_bytes FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ( {$placeholders} )";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from fixed literals and placeholders only; the table names are bound via prepare().
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $names ), ARRAY_A );

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return null; // 権限が無い・値が返らない環境. 画面では「取得できません」と表示する.
		}

		$by_name = array();

		foreach ( $rows as $row ) {
			$by_name[ strtolower( (string) $row['name'] ) ] = array(
				'rows'  => (int) $row['row_count'],
				'bytes' => (int) $row['data_bytes'] + (int) $row['index_bytes'],
			);
		}

		$tables = array();

		foreach ( self::TABLES as $table ) {
			if ( isset( $by_name[ strtolower( $prefix . $table ) ] ) ) {
				$tables[ $table ] = $by_name[ strtolower( $prefix . $table ) ];
			}
		}

		return empty( $tables ) ? null : $tables;
	}

	/**
	 * 一番古い run の `started_at`(UTC)を返す. run が無ければ `null`.
	 *
	 * `started_at` にインデックスは無いが、runs は1日1行程度(1年で数百行)なので全行を見ても速い
	 * (136 行で 0.06 ms. 実測は上のクラス docblock).
	 *
	 * @return string|null
	 */
	private function oldest_run_at() {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed query; table name only.
		$value = $this->wpdb->get_var( "SELECT MIN(started_at) FROM {$table}" );

		return ( null === $value || '' === $value ) ? null : (string) $value;
	}
}
