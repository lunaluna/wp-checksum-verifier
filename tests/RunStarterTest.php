<?php
/**
 * WPCV_Run_Starter のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-suppression-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-starter.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Run_Starter::plan_and_save()`(v0.4.0 §Step5、v0.4.0コードレビューCR-01是正で
 * `planning→running`遷移を追加)のテスト。
 *
 * `WPCV_Run_Coordinator::run()`と`WPCV_Runner_Async::run_async_action()`の両方が
 * このメソッドに依存するため、共有ロジックとして直接テストする(重複を避けるため
 * 呼び出し元側では個別にテストしない).
 */
class RunStarterTest extends TestCase {

	/**
	 * 固定時刻でRepositoryを組み立てる.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb フェイク wpdb.
	 * @return array{run_repository: WPCV_Run_Repository, target_run_repository: WPCV_Target_Run_Repository, planner: WPCV_Run_Planner}
	 */
	private function make_dependencies( WPCV_Test_Fake_WPDB $wpdb ) {
		$now = static function () {
			return '2026-09-12 09:00:00';
		};

		return array(
			'run_repository'        => new WPCV_Run_Repository( $wpdb, $now ),
			'target_run_repository' => new WPCV_Target_Run_Repository( $wpdb, $now ),
			'planner'                => new WPCV_Run_Planner( new WPCV_Suppression_Repository( $wpdb, $now ) ),
		);
	}

	/**
	 * `plan_and_save()` が列挙・保存後にrunを `planning` から `running` へ
	 * 遷移させることを確認する(v0.4.0コードレビューCR-01是正の核心).
	 *
	 * @return void
	 */
	public function test_plan_and_save_transitions_run_from_planning_to_running() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$deps = $this->make_dependencies( $wpdb );

		$reservation = $deps['run_repository']->reserve_run();
		$run_id      = $reservation['run_id'];

		$this->assertSame( WPCV_Run_Status::PLANNING, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );

		$context = array(
			'version'       => '6.8',
			'plugins'       => array(),
			'plugin_dir'    => '/tmp/plugins',
			'mu_plugin_dir' => null,
			'mu_plugins'    => array(),
		);

		$planned = WPCV_Run_Starter::plan_and_save( $deps['run_repository'], $deps['planner'], $deps['target_run_repository'], $run_id, $context );

		$this->assertNotEmpty( $planned );
		$this->assertSame( WPCV_Run_Status::RUNNING, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
		$this->assertNotEmpty( $deps['target_run_repository']->find_all_by_run( $run_id ) );
	}

	/**
	 * `plan_and_save()` が `$planner->plan()` の例外を run failed 化したうえで
	 * 再送出することを確認する(v0.3.1 §Step1由来の契約。CR-01是正後も
	 * 変わらないことの確認).
	 *
	 * @return void
	 */
	public function test_plan_and_save_marks_run_failed_and_rethrows_when_planner_throws() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$deps = $this->make_dependencies( $wpdb );

		$reservation = $deps['run_repository']->reserve_run();
		$run_id      = $reservation['run_id'];

		// `version` を欠いた $context は `WPCV_Run_Planner::plan()` が
		// InvalidArgumentException を投げる(クラスdocblock参照).
		$this->expectException( InvalidArgumentException::class );

		try {
			WPCV_Run_Starter::plan_and_save( $deps['run_repository'], $deps['planner'], $deps['target_run_repository'], $run_id, array() );
		} finally {
			$this->assertSame( WPCV_Run_Status::FAILED, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
		}
	}

	/**
	 * `plan_and_save()` は、列挙・保存自体は成功しても`planning→running`遷移が
	 * 失敗した場合(呼び出し元が想定していた`planning`状態から既に離れていた
	 * 場合。deadline超過sweep等との競合を想定)、run failed 化したうえで
	 * 例外を送出することを確認する(v0.4.0コードレビューCR-01是正で新設した
	 * 分岐).
	 *
	 * @return void
	 */
	public function test_plan_and_save_marks_run_failed_and_throws_when_transition_fails() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$deps = $this->make_dependencies( $wpdb );

		$reservation = $deps['run_repository']->reserve_run();
		$run_id      = $reservation['run_id'];

		// deadline超過sweep等が先にrunをabortedへ倒していた状況を模す
		// (この時点で既に`planning`ではない).
		$wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] = WPCV_Run_Status::ABORTED;

		$context = array(
			'version'       => '6.8',
			'plugins'       => array(),
			'plugin_dir'    => '/tmp/plugins',
			'mu_plugin_dir' => null,
			'mu_plugins'    => array(),
		);

		$this->expectException( RuntimeException::class );

		try {
			WPCV_Run_Starter::plan_and_save( $deps['run_repository'], $deps['planner'], $deps['target_run_repository'], $run_id, $context );
		} finally {
			// mark_run_failed() 自身は「active」ないため false を返すだけだが
			// (このrunは既に`aborted`= terminal)、abortedのまま変化しない
			// ことを確認する(誤って running や failed に上書きされないこと).
			$this->assertSame( WPCV_Run_Status::ABORTED, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
		}
	}
}
