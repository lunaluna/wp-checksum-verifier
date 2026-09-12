<?php
/**
 * WPCV_Chunk_Result_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-matcher.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-suppression-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-chunk-result-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Chunk_Result_Repository`(v0.4.0 §Step3)のテスト.
 *
 * `WPCV_Chunk_Verifier::verify_*_chunk()` の戻り値を模した配列を直接渡し、
 * `wpcv_target_runs`/`wpcv_findings` への反映とtransaction制御
 * (`START TRANSACTION`/`COMMIT`/`ROLLBACK` の呼び出し)を確認する。
 * `WPCV_Test_Fake_WPDB::query()` は呼び出しを記録するだけで実際のロールバック
 * 効果は再現しないため、「呼ばれたこと」自体をアサーションする.
 */
class ChunkResultRepositoryTest extends TestCase {

	/**
	 * `commit_chunk()` が(`needs_retry: false` のとき)findings を保存し、
	 * target_run の進捗を更新し、`START TRANSACTION`/`COMMIT` を呼ぶことを確認する.
	 *
	 * @return void
	 */
	public function test_commit_chunk_saves_findings_and_updates_progress_within_transaction() {
		$wpdb                  = new WPCV_Test_Fake_WPDB();
		$target_run_repository = new WPCV_Target_Run_Repository( $wpdb );
		$finding_repository    = new WPCV_Finding_Repository( $wpdb );
		$repository            = new WPCV_Chunk_Result_Repository( $wpdb, $target_run_repository, $finding_repository, new WPCV_Suppression_Repository( $wpdb ) );

		$target_run_ids = $target_run_repository->save_target_runs(
			1,
			array(
				wpcv_test_make_target_run(
					array(
						'status'         => WPCV_Target_Status::RUNNING,
						'files_verified' => 0,
						'findings_total' => 0,
					)
				),
			)
		);
		$target_run_id  = $target_run_ids['core'];
		// `save_target_runs()` はlease_owner列をinsertしない(claim_next()が
		// 後から設定する値のため)。fencing検証のためclaim済み状態を直接設定する
		// (`WPCV_Target_Run_Repository`のクラスdocblock「CR-02是正」参照).
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['lease_owner'] = 'lease-1';

		$repository->commit_chunk(
			1,
			$target_run_id,
			'core',
			array(
				'findings'             => array( wpcv_test_make_finding() ),
				'cursor_path'          => null,
				'files_verified_delta' => 10,
				'files_total'          => 10,
				'completed'            => true,
				'manifest_fingerprint' => 'abc123',
				'needs_retry'          => false,
			),
			'lease-1'
		);

		$this->assertCount( 1, $wpdb->rows['wp_wpcv_findings'] );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ];
		$this->assertSame( WPCV_Target_Status::SUCCESS, $row['status'] );
		$this->assertSame( 10, $row['files_verified'] );

		$this->assertContains( 'START TRANSACTION', $wpdb->query_calls );
		$this->assertContains( 'COMMIT', $wpdb->query_calls );
		$this->assertNotContains( 'ROLLBACK', $wpdb->query_calls );
	}

	/**
	 * `needs_retry: true` のとき、findings は保存せず `reset_for_retry()` のみが
	 * 適用されることを確認する.
	 *
	 * @return void
	 */
	public function test_commit_chunk_resets_for_retry_and_skips_findings_when_needs_retry() {
		$wpdb                  = new WPCV_Test_Fake_WPDB();
		$target_run_repository = new WPCV_Target_Run_Repository( $wpdb );
		$finding_repository    = new WPCV_Finding_Repository( $wpdb );
		$repository            = new WPCV_Chunk_Result_Repository( $wpdb, $target_run_repository, $finding_repository, new WPCV_Suppression_Repository( $wpdb ) );

		$target_run_ids = $target_run_repository->save_target_runs(
			1,
			array(
				wpcv_test_make_target_run(
					array( 'status' => 'running' )
				),
			)
		);
		$target_run_id  = $target_run_ids['core'];
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['lease_owner'] = 'lease-1';

		$repository->commit_chunk(
			1,
			$target_run_id,
			'core',
			array(
				'findings'             => array( wpcv_test_make_finding() ),
				'cursor_path'          => null,
				'files_verified_delta' => 0,
				'files_total'          => 10,
				'completed'            => false,
				'manifest_fingerprint' => 'new-fingerprint',
				'fingerprint_changed'  => true,
				'version_changed'      => false,
				'needs_retry'          => true,
			),
			'lease-1'
		);

		$this->assertArrayNotHasKey( 'wp_wpcv_findings', $wpdb->rows );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ];
		$this->assertSame( WPCV_Target_Status::RETRY, $row['status'] );
		$this->assertSame( 'new-fingerprint', $row['manifest_fingerprint'] );

		$this->assertContains( 'START TRANSACTION', $wpdb->query_calls );
		$this->assertContains( 'COMMIT', $wpdb->query_calls );
	}

	/**
	 * `commit_chunk()` に渡した`$lease_owner`が対象行の現在値と一致しない
	 * (既に別workerに再claimされていた)場合、findingsのinsertが行われていても
	 * ROLLBACKし、`false`を返すことを確認する(v0.4.0コードレビューCR-02是正:
	 * `findings`だけ確定しcursor/集計は古いまま、という半端な状態を防ぐ).
	 *
	 * @return void
	 */
	public function test_commit_chunk_rolls_back_when_lease_owner_mismatched() {
		$wpdb                  = new WPCV_Test_Fake_WPDB();
		$target_run_repository = new WPCV_Target_Run_Repository( $wpdb );
		$finding_repository    = new WPCV_Finding_Repository( $wpdb );
		$repository            = new WPCV_Chunk_Result_Repository( $wpdb, $target_run_repository, $finding_repository, new WPCV_Suppression_Repository( $wpdb ) );

		$target_run_ids = $target_run_repository->save_target_runs(
			1,
			array(
				wpcv_test_make_target_run(
					array( 'status' => WPCV_Target_Status::RUNNING )
				),
			)
		);
		$target_run_id  = $target_run_ids['core'];
		// worker Bが既に再claimしている(lease_ownerが変わっている)状況を模す.
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['lease_owner'] = 'worker-b';

		// worker A(古いlease)がfindingsを保存しようとするが、fencingで
		// 弾かれるはず.
		$committed = $repository->commit_chunk(
			1,
			$target_run_id,
			'core',
			array(
				'findings'             => array( wpcv_test_make_finding() ),
				'cursor_path'          => null,
				'files_verified_delta' => 10,
				'files_total'          => 10,
				'completed'            => true,
				'manifest_fingerprint' => 'abc123',
				'needs_retry'          => false,
			),
			'worker-a'
		);

		$this->assertFalse( $committed );
		$this->assertContains( 'START TRANSACTION', $wpdb->query_calls );
		$this->assertContains( 'ROLLBACK', $wpdb->query_calls );
		$this->assertNotContains( 'COMMIT', $wpdb->query_calls );
	}

	/**
	 * findings の保存中に例外が発生した場合、`ROLLBACK` を呼んでから例外を
	 * 再送出することを確認する(`save_findings()` が投げる
	 * `InvalidArgumentException` を利用し、target_id をわざと一致させない).
	 *
	 * @return void
	 */
	public function test_commit_chunk_rolls_back_and_rethrows_on_exception() {
		$wpdb                  = new WPCV_Test_Fake_WPDB();
		$target_run_repository = new WPCV_Target_Run_Repository( $wpdb );
		$finding_repository    = new WPCV_Finding_Repository( $wpdb );
		$repository            = new WPCV_Chunk_Result_Repository( $wpdb, $target_run_repository, $finding_repository, new WPCV_Suppression_Repository( $wpdb ) );

		$target_run_ids = $target_run_repository->save_target_runs( 1, array( wpcv_test_make_target_run() ) );
		$target_run_id  = $target_run_ids['core'];

		$this->expectException( InvalidArgumentException::class );

		try {
			$repository->commit_chunk(
				1,
				$target_run_id,
				// finding の target_id('core')と一致しない target_id を渡し、
				// save_findings() 内の対応表引き当てを失敗させる.
				'mismatched-target-id',
				array(
					'findings'             => array( wpcv_test_make_finding( array( 'target_id' => 'core' ) ) ),
					'cursor_path'          => null,
					'files_verified_delta' => 0,
					'files_total'          => 10,
					'completed'            => true,
					'manifest_fingerprint' => 'abc123',
					'needs_retry'          => false,
				),
				'lease-1'
			);
		} finally {
			$this->assertContains( 'START TRANSACTION', $wpdb->query_calls );
			$this->assertContains( 'ROLLBACK', $wpdb->query_calls );
			$this->assertNotContains( 'COMMIT', $wpdb->query_calls );
		}
	}

	/**
	 * 有効な `exclude_path` 抑制ルールに一致するfindingが、`suppression_id` 付きで
	 * 保存されることを確認する(v0.4.0 §Step8。`apply_suppressions()` の適用先が
	 * `save_findings()` の直前であることの統合確認).
	 *
	 * @return void
	 */
	public function test_commit_chunk_applies_exclude_path_suppression_before_saving() {
		$wpdb                   = new WPCV_Test_Fake_WPDB();
		$target_run_repository  = new WPCV_Target_Run_Repository( $wpdb );
		$finding_repository     = new WPCV_Finding_Repository( $wpdb );
		$suppression_repository = new WPCV_Suppression_Repository( $wpdb );
		$repository             = new WPCV_Chunk_Result_Repository( $wpdb, $target_run_repository, $finding_repository, $suppression_repository );
		$suppression_id         = $suppression_repository->insert(
			array(
				'type'       => WPCV_Suppression_Type::EXCLUDE_PATH,
				'dimension'  => 'core',
				'slug'       => 'wordpress',
				'pattern'    => 'wp-admin/index.php',
				'reason'     => 'known noisy path',
				'created_by' => 1,
			)
		);

		$target_run_ids = $target_run_repository->save_target_runs(
			1,
			array(
				wpcv_test_make_target_run(
					array( 'status' => WPCV_Target_Status::RUNNING )
				),
			)
		);
		$target_run_id  = $target_run_ids['core'];
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['lease_owner'] = 'lease-1';

		$repository->commit_chunk(
			1,
			$target_run_id,
			'core',
			array(
				'findings'             => array( wpcv_test_make_finding( array( 'path' => 'wp-admin/index.php' ) ) ),
				'cursor_path'          => null,
				'files_verified_delta' => 10,
				'files_total'          => 10,
				'completed'            => true,
				'manifest_fingerprint' => 'abc123',
				'needs_retry'          => false,
			),
			'lease-1'
		);

		$saved_finding = current( $wpdb->rows['wp_wpcv_findings'] );

		$this->assertNull( $saved_finding['suppressed_by'] );
		$this->assertSame( $suppression_id, $saved_finding['suppression_id'] );
	}
}
