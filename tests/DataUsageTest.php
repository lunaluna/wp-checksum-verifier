<?php
/**
 * WPCV_Data_Usage のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-data-usage.php';

use PHPUnit\Framework\TestCase;

/**
 * 設定画面の「保存しているデータ」の元データ `WPCV_Data_Usage::collect()` のテスト(0.10.0 プラン §8.2).
 *
 * `information_schema` を返す小さな `wpdb` のダブルで、取れた場合・取れない場合・一部だけ取れた場合を確かめる.
 * 実際の MySQL での値と速さは、test-armfu.local での実測(クラス docblock)で確認している.
 */
class DataUsageTest extends TestCase {

	/**
	 * `information_schema` の結果と `MIN(started_at)` を返す `wpdb` のダブルを作る.
	 *
	 * @param array|false $schema_rows `get_results()` の返す行(`false` なら失敗を表す).
	 * @param string|null $oldest      `get_var()` の返す値.
	 * @return object
	 */
	private function make_wpdb( $schema_rows, $oldest ) {
		return new class( $schema_rows, $oldest ) {

			/**
			 * テーブル名の接頭辞.
			 *
			 * @var string
			 */
			public $base_prefix = 'wp_';

			/**
			 * 実行された SQL(prepare 後).
			 *
			 * @var string[]
			 */
			public $queries = array();

			/**
			 * `get_results()` の戻り値.
			 *
			 * @var array|false
			 */
			private $schema_rows;

			/**
			 * `get_var()` の戻り値.
			 *
			 * @var string|null
			 */
			private $oldest;

			/**
			 * コンストラクタ.
			 *
			 * @param array|false $schema_rows 行.
			 * @param string|null $oldest      値.
			 */
			public function __construct( $schema_rows, $oldest ) {
				$this->schema_rows = $schema_rows;
				$this->oldest      = $oldest;
			}

			/**
			 * `prepare()` の簡易版(`%s` を引用符つきで埋める).
			 *
			 * @param string $query クエリ.
			 * @param mixed  ...$args 値.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}

				foreach ( $args as $arg ) {
					$query = preg_replace( '/%s/', "'" . $arg . "'", $query, 1 );
				}

				return $query;
			}

			/**
			 * `get_results()`.
			 *
			 * @param string $sql SQL.
			 * @return array|false
			 */
			public function get_results( $sql ) {
				$this->queries[] = $sql;

				return $this->schema_rows;
			}

			/**
			 * `get_var()`.
			 *
			 * @param string $sql SQL.
			 * @return string|null
			 */
			public function get_var( $sql ) {
				$this->queries[] = $sql;

				return $this->oldest;
			}
		};
	}

	/**
	 * `information_schema` の行が、接頭辞なしのテーブル名ごとの行数・容量(データ+インデックス)になり、
	 * 表示の順に並ぶことを確認する.
	 *
	 * @return void
	 */
	public function test_collect_returns_rows_and_bytes_per_table_in_display_order() {
		$wpdb = $this->make_wpdb(
			array(
				array( 'name' => 'wp_wpcv_findings', 'row_count' => '1329', 'data_bytes' => '1196032', 'index_bytes' => '1916928' ),
				array( 'name' => 'wp_wpcv_runs', 'row_count' => '136', 'data_bytes' => '49152', 'index_bytes' => '0' ),
			),
			'2026-09-08 14:01:03'
		);

		$usage = ( new WPCV_Data_Usage( $wpdb ) )->collect();

		$this->assertSame( array( 'wpcv_runs', 'wpcv_findings' ), array_keys( $usage['tables'] ) );
		$this->assertSame( array( 'rows' => 136, 'bytes' => 49152 ), $usage['tables']['wpcv_runs'] );
		$this->assertSame( array( 'rows' => 1329, 'bytes' => 3112960 ), $usage['tables']['wpcv_findings'] );
		$this->assertSame( '2026-09-08 14:01:03', $usage['oldest_run_at'] );
	}

	/**
	 * クエリは接頭辞つきの7テーブル名だけを束縛し、現在の DB に絞ることを確認する.
	 *
	 * @return void
	 */
	public function test_query_is_limited_to_current_schema_and_the_seven_tables() {
		$wpdb = $this->make_wpdb( array(), null );

		( new WPCV_Data_Usage( $wpdb ) )->collect();

		$this->assertStringContainsString( 'table_schema = DATABASE()', $wpdb->queries[0] );

		foreach ( WPCV_Data_Usage::TABLES as $table ) {
			$this->assertStringContainsString( "'wp_{$table}'", $wpdb->queries[0] );
		}
	}

	/**
	 * `information_schema` から何も返らない(権限が無いなど)ときは `tables` が `null` になり、
	 * 例外を投げないことを確認する.
	 *
	 * @return void
	 */
	public function test_collect_returns_null_tables_when_nothing_is_returned() {
		foreach ( array( array(), false ) as $rows ) {
			$usage = ( new WPCV_Data_Usage( $this->make_wpdb( $rows, null ) ) )->collect();

			$this->assertNull( $usage['tables'] );
			$this->assertNull( $usage['oldest_run_at'] );
		}
	}

	/**
	 * 大文字の列名・テーブル名(MySQL のバージョン差)でも読め、他のプラグインのテーブルは無視されることを確認する.
	 *
	 * @return void
	 */
	public function test_collect_ignores_unrelated_tables_and_handles_case() {
		$wpdb = $this->make_wpdb(
			array(
				array( 'name' => 'WP_WPCV_RUNS', 'row_count' => '5', 'data_bytes' => '16384', 'index_bytes' => '0' ),
				array( 'name' => 'wp_other', 'row_count' => '999', 'data_bytes' => '1', 'index_bytes' => '1' ),
			),
			''
		);

		$usage = ( new WPCV_Data_Usage( $wpdb ) )->collect();

		$this->assertSame( array( 'wpcv_runs' ), array_keys( $usage['tables'] ) );
		$this->assertNull( $usage['oldest_run_at'], '空文字は run が無い扱い' );
	}
}
