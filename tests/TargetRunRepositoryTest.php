<?php
/**
 * WPCV_Target_Run_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `wpcv_target_runs` の DB 永続化(§4.2: Repository 層。v0.4.0 §Step1で
 * `WPCV_Repository` から分割)のテスト.
 */
class TargetRunRepositoryTest extends TestCase {

	/**
	 * save_target_runs() が target_runs テーブルに行を insert し、
	 * target_id => target_run_id の対応表を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_save_target_runs_inserts_rows_and_returns_id_map() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository( $wpdb );

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
	 * `update_chunk_progress()`(v0.4.0 §Step3)が cursor_path/manifest_fingerprint/
	 * files_total を上書きし、files_verified/findings_total は既存値に加算することを確認する。
	 * `completed:false` のとき `status` を `RETRY` へ進めlease列をクリアすることも
	 * あわせて確認する(v0.4.0 §Step4。Step3時点では `status` を変更しないままで、
	 * claim済みの `running` に留まり続けてしまう欠落があった).
	 *
	 * @return void
	 */
	public function test_update_chunk_progress_accumulates_verified_and_findings_counts() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository(
			$wpdb,
			static function () {
				return '2026-09-11 12:00:00';
			}
		);

		$run_id         = 1;
		$target_run_ids = $repository->save_target_runs(
			$run_id,
			array(
				wpcv_test_make_target_run(
					array(
						'files_verified' => 3,
						'findings_total' => 1,
					)
				),
			)
		);
		$target_run_id  = $target_run_ids['core'];

		$updated = $repository->update_chunk_progress(
			$target_run_id,
			array(
				'cursor_path'          => 'wp-admin/index.php',
				'manifest_fingerprint' => 'abc123',
				'files_total'          => 10,
				'files_verified_delta' => 2,
				'findings'             => array( array( 'dummy' => true ) ),
				'completed'            => false,
			)
		);

		$this->assertTrue( $updated );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ];
		$this->assertSame( 'wp-admin/index.php', $row['cursor_path'] );
		$this->assertSame( 'abc123', $row['manifest_fingerprint'] );
		$this->assertSame( 10, $row['files_total'] );
		// 既存値3 + 今回の増分2 = 5.
		$this->assertSame( 5, $row['files_verified'] );
		// 既存値1 + 今回のfindings件数1 = 2.
		$this->assertSame( 2, $row['findings_total'] );
		// completed:false のときは status を RETRY へ進め、lease列をクリアする
		// (v0.4.0 §Step4。`claim_next()` が次回すぐschedulableと判定できるように).
		$this->assertSame( WPCV_Target_Status::RETRY, $row['status'] );
		$this->assertNull( $row['lease_owner'] );
		$this->assertNull( $row['lease_expires_at'] );
		$this->assertNull( $row['retry_after'] );
	}

	/**
	 * `completed: true` のとき status を `WPCV_Target_Status::SUCCESS` へ進め、
	 * `finished_at` を記録することを確認する.
	 *
	 * @return void
	 */
	public function test_update_chunk_progress_marks_success_and_finished_at_when_completed() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository(
			$wpdb,
			static function () {
				return '2026-09-11 12:00:00';
			}
		);

		$target_run_ids = $repository->save_target_runs( 1, array( wpcv_test_make_target_run( array( 'status' => 'queued' ) ) ) );
		$target_run_id  = $target_run_ids['core'];

		$repository->update_chunk_progress(
			$target_run_id,
			array(
				'cursor_path'          => null,
				'manifest_fingerprint' => 'abc123',
				'files_total'          => 5,
				'files_verified_delta' => 5,
				'findings'             => array(),
				'completed'            => true,
			)
		);

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ];
		$this->assertSame( WPCV_Target_Status::SUCCESS, $row['status'] );
		$this->assertSame( '2026-09-11 12:00:00', $row['finished_at'] );
	}

	/**
	 * 対象行が存在しない場合、`update_chunk_progress()` は false を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_update_chunk_progress_returns_false_when_row_not_found() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository( $wpdb );

		$updated = $repository->update_chunk_progress(
			999,
			array(
				'cursor_path'          => null,
				'manifest_fingerprint' => null,
				'files_total'          => 0,
				'files_verified_delta' => 0,
				'findings'             => array(),
				'completed'            => false,
			)
		);

		$this->assertFalse( $updated );
	}

	/**
	 * `reset_for_retry()`(v0.4.0 §Step3)が status を `RETRY` へ戻し、
	 * cursor・集計値をリセットすることを確認する
	 * (§Step3「resume時にversion/fingerprintが変わっていたらchunk結果を確定せず
	 * retryへ戻す」).
	 *
	 * @return void
	 */
	public function test_reset_for_retry_resets_cursor_and_counters() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository( $wpdb );

		$target_run_ids = $repository->save_target_runs(
			1,
			array(
				wpcv_test_make_target_run(
					array(
						'status'         => 'running',
						'files_total'    => 10,
						'files_verified' => 7,
						'findings_total' => 2,
					)
				),
			)
		);
		$target_run_id  = $target_run_ids['core'];

		$updated = $repository->reset_for_retry( $target_run_id, 'new-fingerprint' );

		$this->assertTrue( $updated );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ];
		$this->assertSame( WPCV_Target_Status::RETRY, $row['status'] );
		$this->assertNull( $row['cursor_path'] );
		$this->assertSame( 'new-fingerprint', $row['manifest_fingerprint'] );
		$this->assertSame( 0, $row['files_total'] );
		$this->assertSame( 0, $row['files_verified'] );
		$this->assertSame( 0, $row['findings_total'] );
	}
}
