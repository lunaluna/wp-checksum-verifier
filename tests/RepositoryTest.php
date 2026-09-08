<?php
/**
 * WPCV_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-repository.php';

use PHPUnit\Framework\TestCase;

/**
 * テスト用の最小 `$wpdb` ダブル.
 *
 * `insert()` / `update()` の呼び出しをそのままメモリ上の配列に記録するだけの
 * 実装で、実際の SQL は発行しない。`WPCV_Repository` が呼ぶメソッド群だけを
 * 満たす(実 wpdb クラスは実装しない。ダックタイピングで十分なため).
 */
class WPCV_Test_Fake_WPDB {

	/**
	 * インストールレベルのテーブル接頭辞(本番の `$wpdb->base_prefix` に相当).
	 *
	 * @var string
	 */
	public $base_prefix = 'wp_';

	/**
	 * 直近の `insert()` が採番した id(本番の `$wpdb->insert_id` に相当).
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * テーブルごとの行(id をキーにした連想配列).
	 *
	 * @var array<string, array<int, array>>
	 */
	public $rows = array();

	/**
	 * テーブルごとの次の auto increment id.
	 *
	 * @var array<string, int>
	 */
	private $next_id = array();

	/**
	 * 行を追加する.
	 *
	 * @param string     $table  テーブル名.
	 * @param array      $data   カラム => 値.
	 * @param array|null $format 無視する(本番の型指定に相当。ダブルでは検証しない).
	 * @return int 常に1(本番の `$wpdb->insert()` の成功時と同じ).
	 */
	public function insert( $table, $data, $format = null ) {
		unset( $format );

		if ( ! isset( $this->next_id[ $table ] ) ) {
			$this->next_id[ $table ] = 1;
		}

		$id         = $this->next_id[ $table ]++;
		$data['id'] = $id;

		$this->rows[ $table ][ $id ] = $data;
		$this->insert_id             = $id;

		return 1;
	}

	/**
	 * 条件に一致する行を更新する.
	 *
	 * @param string     $table        テーブル名.
	 * @param array      $data         更新するカラム => 値.
	 * @param array      $where        カラム => 値(すべて一致する行を更新).
	 * @param array|null $format       無視する.
	 * @param array|null $where_format 無視する.
	 * @return int 更新した行数.
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		unset( $format, $where_format );

		$updated = 0;

		foreach ( $this->rows[ $table ] as $id => $row ) {
			$matches = true;

			foreach ( $where as $column => $value ) {
				if ( ! isset( $row[ $column ] ) || $row[ $column ] !== $value ) {
					$matches = false;
					break;
				}
			}

			if ( $matches ) {
				$this->rows[ $table ][ $id ] = array_merge( $row, $data );
				++$updated;
			}
		}

		return $updated;
	}
}

/**
 * `WPCV_Verifier` の各 `verify_*()` が返す target_run の最小形を作る.
 *
 * @param array $overrides 上書きするフィールド.
 * @return array
 */
function wpcv_test_make_target_run( array $overrides = array() ) {
	return array_merge(
		array(
			'target_id'       => 'core',
			'dimension'       => 'core',
			'slug'            => 'wordpress',
			'version'         => '6.8',
			'source'          => 'wporg',
			'source_ref'      => null,
			'manifest_status' => 'ok',
			'status'          => 'success',
			'error_code'      => null,
			'error_message'   => null,
			'files_total'     => 10,
			'files_verified'  => 10,
			'findings_total'  => 0,
		),
		$overrides
	);
}

/**
 * `WPCV_Verifier` の各 `verify_*()` が返す finding の最小形を作る.
 *
 * @param array $overrides 上書きするフィールド.
 * @return array
 */
function wpcv_test_make_finding( array $overrides = array() ) {
	return array_merge(
		array(
			'target_id'      => 'core',
			'dimension'      => 'core',
			'slug'           => 'wordpress',
			'version'        => '6.8',
			'source'         => 'wporg',
			'path'           => 'wp-admin/index.php',
			'status'         => 'modified',
			'severity'       => 'high',
			'hash_algorithm' => 'sha256',
			'expected_hash'  => str_repeat( 'a', 64 ),
			'actual_hash'    => str_repeat( 'b', 64 ),
			'file_size'      => 123,
		),
		$overrides
	);
}

/**
 * `WPCV_Verifier` が返すデータ構造の DB 永続化(§4.2: Repository 層)のテスト.
 */
class RepositoryTest extends TestCase {

	/**
	 * 固定時刻を返す Repository を作る.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb フェイク wpdb.
	 * @return WPCV_Repository
	 */
	private function make_repository( WPCV_Test_Fake_WPDB $wpdb ) {
		return new WPCV_Repository(
			$wpdb,
			static function () {
				return '2026-09-08 12:00:00';
			}
		);
	}

	/**
	 * start_run() が runs テーブルに1行 insert し、insert_id を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_start_run_inserts_row_and_returns_id() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->start_run();

		$this->assertSame( 1, $run_id );
		$row = $wpdb->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'running', $row['status'] );
		$this->assertSame( '2026-09-08 12:00:00', $row['started_at'] );
		$this->assertSame( 'manual', $row['run_trigger'] );
		$this->assertSame( 'sync', $row['runner'] );
	}

	/**
	 * run_trigger / runner を明示指定した場合はその値が使われることを確認する.
	 *
	 * @return void
	 */
	public function test_start_run_accepts_explicit_trigger_and_runner() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$repository->start_run(
			array(
				'run_trigger' => 'cli',
				'runner'      => 'async',
			)
		);

		$row = $wpdb->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'cli', $row['run_trigger'] );
		$this->assertSame( 'async', $row['runner'] );
	}

	/**
	 * save_target_runs() が target_runs テーブルに行を insert し、
	 * target_id => target_run_id の対応表を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_save_target_runs_inserts_rows_and_returns_id_map() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$target_run_ids = $repository->save_target_runs(
			42,
			array(
				wpcv_test_make_target_run( array( 'target_id' => 'core' ) ),
				wpcv_test_make_target_run(
					array(
						'target_id' => 'plugin:akismet',
						'dimension' => 'plugin',
						'slug'      => 'akismet',
					)
				),
			)
		);

		$this->assertSame(
			array(
				'core'           => 1,
				'plugin:akismet' => 2,
			),
			$target_run_ids
		);

		$row = $wpdb->rows['wp_wpcv_target_runs'][2];
		$this->assertSame( 42, $row['run_id'] );
		$this->assertSame( 'plugin:akismet', $row['target_id'] );
		$this->assertSame( 'akismet', $row['slug'] );
	}

	/**
	 * save_findings() が target_run_ids から target_run_id を解決して
	 * findings テーブルに insert することを確認する.
	 *
	 * @return void
	 */
	public function test_save_findings_resolves_target_run_id() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$repository->save_findings(
			42,
			array( 'core' => 7 ),
			array( wpcv_test_make_finding( array( 'target_id' => 'core' ) ) )
		);

		$row = $wpdb->rows['wp_wpcv_findings'][1];
		$this->assertSame( 42, $row['run_id'] );
		$this->assertSame( 7, $row['target_run_id'] );
		$this->assertSame( 'core', $row['target_id'] );
		$this->assertSame( 'wp-admin/index.php', $row['path'] );
	}

	/**
	 * 対応する target_run_id が無い finding を渡すと例外を投げることを確認する
	 * (target_runs と findings の target_id は同一バッチ内で必ず一致している前提のため).
	 *
	 * @return void
	 */
	public function test_save_findings_throws_when_target_run_id_missing() {
		$this->expectException( InvalidArgumentException::class );

		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$repository->save_findings(
			42,
			array(),
			array( wpcv_test_make_finding( array( 'target_id' => 'core' ) ) )
		);
	}

	/**
	 * finish_run() が対象の run 行を status = summary の値・finished_at・
	 * 各カウントで更新することを確認する.
	 *
	 * @return void
	 */
	public function test_finish_run_updates_row() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->start_run();

		$repository->finish_run(
			$run_id,
			array(
				'status'               => 'partial',
				'targets_total'        => 3,
				'targets_verified'     => 2,
				'targets_unverifiable' => 1,
				'targets_failed'       => 0,
				'findings_total'       => 5,
			)
		);

		$row = $wpdb->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 'partial', $row['status'] );
		$this->assertSame( '2026-09-08 12:00:00', $row['finished_at'] );
		$this->assertSame( 3, $row['targets_total'] );
		$this->assertSame( 2, $row['targets_verified'] );
		$this->assertSame( 1, $row['targets_unverifiable'] );
		$this->assertSame( 5, $row['findings_total'] );
		// start_run() 時点の status/started_at が上書きされず残っていることも確認する.
		$this->assertSame( 'manual', $row['run_trigger'] );
	}

	/**
	 * finish_run() が指定した run 以外の行を更新しないことを確認する.
	 *
	 * @return void
	 */
	public function test_finish_run_does_not_touch_other_rows() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$first_run_id  = $repository->start_run();
		$second_run_id = $repository->start_run();

		$repository->finish_run(
			$second_run_id,
			array(
				'status'               => 'success',
				'targets_total'        => 1,
				'targets_verified'     => 1,
				'targets_unverifiable' => 0,
				'targets_failed'       => 0,
				'findings_total'       => 0,
			)
		);

		$this->assertSame( 'running', $wpdb->rows['wp_wpcv_runs'][ $first_run_id ]['status'] );
		$this->assertSame( 'success', $wpdb->rows['wp_wpcv_runs'][ $second_run_id ]['status'] );
	}
}
