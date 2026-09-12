<?php
/**
 * WPCV_Target_Run_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
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

		$this->assertContains( 'START TRANSACTION', $wpdb->query_calls );
		$this->assertContains( 'COMMIT', $wpdb->query_calls );
		$this->assertNotContains( 'ROLLBACK', $wpdb->query_calls );
	}

	/**
	 * `save_target_runs()` が `$wpdb->insert()` の失敗(`false`)を検知して
	 * `RuntimeException` を投げ、`ROLLBACK` を呼ぶことを確認する(v0.4.0コード
	 * レビューCR-03是正: DB容量不足・接続断等で insert が `false` を返しても
	 * 気付かず「plan成功」として処理を続けてしまう不具合への対策。全件を
	 * 1トランザクションにまとめたことで、1件目のinsert失敗時点でROLLBACKし、
	 * 2件目以降のinsertは一切行われない).
	 *
	 * @return void
	 */
	public function test_save_target_runs_throws_and_rolls_back_when_insert_fails() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository( $wpdb );

		$wpdb->insert_should_fail = true;

		$this->expectException( RuntimeException::class );

		try {
			$repository->save_target_runs(
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
		} finally {
			$this->assertArrayNotHasKey( 'wp_wpcv_target_runs', $wpdb->rows );
			$this->assertContains( 'START TRANSACTION', $wpdb->query_calls );
			$this->assertContains( 'ROLLBACK', $wpdb->query_calls );
			$this->assertNotContains( 'COMMIT', $wpdb->query_calls );
		}
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
						'status'         => WPCV_Target_Status::RUNNING,
						'files_verified' => 3,
						'findings_total' => 1,
					)
				),
			)
		);
		$target_run_id  = $target_run_ids['core'];
		// `save_target_runs()` は固定の列だけをinsertするため(`lease_owner`を
		// 含まない)、claim済み状態を模すにはinsert後に直接設定する
		// (`claim_next()`が本来設定する値。`WPCV_Target_Run_Repository`の
		// クラスdocblock「CR-02是正」参照).
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['lease_owner'] = 'lease-1';

		$updated = $repository->update_chunk_progress(
			$target_run_id,
			array(
				'cursor_path'          => 'wp-admin/index.php',
				'manifest_fingerprint' => 'abc123',
				'files_total'          => 10,
				'files_verified_delta' => 2,
				'findings'             => array( array( 'dummy' => true ) ),
				'completed'            => false,
			),
			'lease-1'
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

		$target_run_ids = $repository->save_target_runs(
			1,
			array(
				wpcv_test_make_target_run(
					array( 'status' => WPCV_Target_Status::RUNNING )
				),
			)
		);
		$target_run_id  = $target_run_ids['core'];
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['lease_owner'] = 'lease-1';

		$repository->update_chunk_progress(
			$target_run_id,
			array(
				'cursor_path'          => null,
				'manifest_fingerprint' => 'abc123',
				'files_total'          => 5,
				'files_verified_delta' => 5,
				'findings'             => array(),
				'completed'            => true,
			),
			'lease-1'
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
			),
			'lease-1'
		);

		$this->assertFalse( $updated );
	}

	/**
	 * `update_chunk_progress()` が、対象行は存在するが`lease_owner`が一致しない
	 * (既に別workerに再claimされていた)場合にfalseを返し、行を変更しないことを
	 * 確認する(v0.4.0コードレビューCR-02是正: lease失効後の旧workerに対する
	 * fencing).
	 *
	 * @return void
	 */
	public function test_update_chunk_progress_returns_false_when_lease_owner_mismatched() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository( $wpdb );

		$target_run_ids = $repository->save_target_runs(
			1,
			array(
				wpcv_test_make_target_run(
					array(
						'status'         => WPCV_Target_Status::RUNNING,
						'files_verified' => 20,
					)
				),
			)
		);
		$target_run_id = $target_run_ids['core'];
		// `save_target_runs()` はcursor_path/lease_ownerをinsertしないため
		// (`test_update_chunk_progress_accumulates_verified_and_findings_counts()`の
		// コメント参照)、worker Bが既にclaim・前進させた状態を直接設定する.
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['lease_owner'] = 'worker-b';
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['cursor_path'] = 'already/advanced/by/worker-b.php';

		// worker A(古いlease)が遅れて確定しようとする状況を模す.
		$updated = $repository->update_chunk_progress(
			$target_run_id,
			array(
				'cursor_path'          => 'stale/from/worker-a.php',
				'manifest_fingerprint' => 'stale-fingerprint',
				'files_total'          => 10,
				'files_verified_delta' => 999,
				'findings'             => array(),
				'completed'            => false,
			),
			'worker-a'
		);

		$this->assertFalse( $updated );

		// worker Bの結果(cursor・files_verified)が上書きされていないことを確認する.
		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ];
		$this->assertSame( 'already/advanced/by/worker-b.php', $row['cursor_path'] );
		$this->assertSame( 20, $row['files_verified'] );
		$this->assertSame( 'worker-b', $row['lease_owner'] );
	}

	/**
	 * `update_chunk_progress()` が、対象行のstatusが`running`でない(既に
	 * lease失効sweepで`retry`/`failed`へ倒されていた)場合にもfalseを返す
	 * ことを確認する(CR-02是正: `lease_owner`が偶然一致していても、statusが
	 * runningでなければ確定させない).
	 *
	 * @return void
	 */
	public function test_update_chunk_progress_returns_false_when_status_no_longer_running() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository( $wpdb );

		$target_run_ids = $repository->save_target_runs(
			1,
			array(
				wpcv_test_make_target_run(
					array( 'status' => WPCV_Target_Status::RETRY )
				),
			)
		);
		$target_run_id = $target_run_ids['core'];

		$updated = $repository->update_chunk_progress(
			$target_run_id,
			array(
				'cursor_path'          => 'stale.php',
				'manifest_fingerprint' => 'stale-fingerprint',
				'files_total'          => 10,
				'files_verified_delta' => 1,
				'findings'             => array(),
				'completed'            => false,
			),
			'worker-a'
		);

		$this->assertFalse( $updated );
		$this->assertSame( WPCV_Target_Status::RETRY, $wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['status'] );
	}

	/**
	 * `update_chunk_progress()` が、`$wpdb->update()` がSQLエラーで `false` を
	 * 返した場合に `RuntimeException` を投げることを確認する(v0.4.0コード
	 * レビューCR-03是正)。`test_update_chunk_progress_returns_false_when_lease_owner_mismatched()`
	 * (WHEREに一致する行が無いだけの正常系。整数 `0` が返り静かに `false` を返す)
	 * とは区別すべき異常系であることの確認.
	 *
	 * @return void
	 */
	public function test_update_chunk_progress_throws_when_wpdb_update_fails() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository( $wpdb );

		$target_run_ids = $repository->save_target_runs(
			1,
			array(
				wpcv_test_make_target_run( array( 'status' => WPCV_Target_Status::RUNNING ) ),
			)
		);
		$target_run_id = $target_run_ids['core'];
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['lease_owner'] = 'lease-1';

		$wpdb->update_should_fail = true;

		$this->expectException( RuntimeException::class );

		$repository->update_chunk_progress(
			$target_run_id,
			array(
				'cursor_path'          => 'core/wp-includes/foo.php',
				'manifest_fingerprint' => 'abc123',
				'files_total'          => 10,
				'files_verified_delta' => 5,
				'findings'             => array(),
				'completed'            => false,
			),
			'lease-1'
		);
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
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['lease_owner'] = 'lease-1';

		$updated = $repository->reset_for_retry( $target_run_id, 'new-fingerprint', false, 'lease-1' );

		$this->assertTrue( $updated );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ];
		$this->assertSame( WPCV_Target_Status::RETRY, $row['status'] );
		$this->assertNull( $row['cursor_path'] );
		$this->assertSame( 'new-fingerprint', $row['manifest_fingerprint'] );
		$this->assertSame( 0, $row['files_total'] );
		$this->assertSame( 0, $row['files_verified'] );
		$this->assertSame( 0, $row['findings_total'] );
	}

	/**
	 * `reset_for_retry()` が、`$wpdb->update()` がSQLエラーで `false` を返した
	 * 場合に `RuntimeException` を投げることを確認する(v0.4.0コードレビュー
	 * CR-03是正。`update_chunk_progress()` の同種テストと同じ理由).
	 *
	 * @return void
	 */
	public function test_reset_for_retry_throws_when_wpdb_update_fails() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository( $wpdb );

		$target_run_ids = $repository->save_target_runs(
			1,
			array(
				wpcv_test_make_target_run( array( 'status' => 'running' ) ),
			)
		);
		$target_run_id  = $target_run_ids['core'];
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['lease_owner'] = 'lease-1';

		$wpdb->update_should_fail = true;

		$this->expectException( RuntimeException::class );

		$repository->reset_for_retry( $target_run_id, 'new-fingerprint', false, 'lease-1' );
	}

	/**
	 * `finalize_immediate()`(v0.4.0 §Step4)が claim済み(`running`+一致する
	 * `lease_owner`)の行を更新できることを確認する.
	 *
	 * @return void
	 */
	public function test_finalize_immediate_updates_claimed_row() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository(
			$wpdb,
			static function () {
				return '2026-09-12 09:00:00';
			}
		);

		$target_run_ids = $repository->save_target_runs(
			1,
			array( wpcv_test_make_target_run( array( 'status' => WPCV_Target_Status::RUNNING ) ) )
		);
		$target_run_id  = $target_run_ids['core'];
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['lease_owner'] = 'lease-1';

		$updated = $repository->finalize_immediate(
			$target_run_id,
			array(
				'status'     => WPCV_Target_Status::UNVERIFIABLE,
				'error_code' => WPCV_Error_Code::TARGET_MISSING,
			),
			'lease-1'
		);

		$this->assertTrue( $updated );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ];
		$this->assertSame( WPCV_Target_Status::UNVERIFIABLE, $row['status'] );
		$this->assertSame( WPCV_Error_Code::TARGET_MISSING, $row['error_code'] );
		$this->assertSame( '2026-09-12 09:00:00', $row['finished_at'] );
	}

	/**
	 * `finalize_immediate()` が、`lease_owner`が一致しない(既に別workerに
	 * 再claimされていた)場合にfalseを返し、行を変更しないことを確認する
	 * (v0.4.0コードレビューCR-02是正: lease失効後の旧workerに対するfencing).
	 *
	 * @return void
	 */
	public function test_finalize_immediate_returns_false_when_lease_owner_mismatched() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository( $wpdb );

		$target_run_ids = $repository->save_target_runs(
			1,
			array( wpcv_test_make_target_run( array( 'status' => WPCV_Target_Status::RUNNING ) ) )
		);
		$target_run_id  = $target_run_ids['core'];
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['lease_owner'] = 'worker-b';

		$updated = $repository->finalize_immediate(
			$target_run_id,
			array(
				'status'     => WPCV_Target_Status::FAILED,
				'error_code' => 'exception_from_stale_worker',
			),
			'worker-a'
		);

		$this->assertFalse( $updated );
		$this->assertSame( WPCV_Target_Status::RUNNING, $wpdb->rows['wp_wpcv_target_runs'][ $target_run_id ]['status'] );
	}
}
