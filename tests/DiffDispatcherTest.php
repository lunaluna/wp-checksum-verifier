<?php
/**
 * WPCV_Diff_Dispatcher のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-diff-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-generation-differ.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-finding-key.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-file-state-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-diff-dispatcher.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Diff_Dispatcher::dispatch_diff()` のテスト(v0.5後半 §Step12).
 */
class DiffDispatcherTest extends TestCase {

	/**
	 * 固定の現在時刻(`WPCV_Run_Repository`等のテストと同じ値。
	 * `claim_diff()`のlease有効期限計算の起点として使う).
	 *
	 * @var string
	 */
	const NOW = '2026-09-08 12:00:00';

	/**
	 * このテストファイル共通の環境(Repository群・Dispatcher・`$wpdb`)を組み立てる.
	 *
	 * @return array{wpdb: WPCV_Test_Fake_WPDB, run_repository: WPCV_Run_Repository,
	 *               target_run_repository: WPCV_Target_Run_Repository,
	 *               finding_repository: WPCV_Finding_Repository,
	 *               file_state_repository: WPCV_File_State_Repository,
	 *               dispatcher: WPCV_Diff_Dispatcher}
	 */
	private function make_environment() {
		$now = static function () {
			return self::NOW;
		};

		$wpdb                   = new WPCV_Test_Fake_WPDB();
		$run_repository         = new WPCV_Run_Repository( $wpdb, $now );
		$target_run_repository  = new WPCV_Target_Run_Repository( $wpdb, $now );
		$finding_repository     = new WPCV_Finding_Repository( $wpdb );
		$file_state_repository  = new WPCV_File_State_Repository( $wpdb, $now );

		$owner_sequence = 0;
		$dispatcher     = new WPCV_Diff_Dispatcher(
			$run_repository,
			$target_run_repository,
			$finding_repository,
			$file_state_repository,
			static function () use ( &$owner_sequence ) {
				++$owner_sequence;
				return 'owner-' . $owner_sequence;
			}
		);

		return array(
			'wpdb'                   => $wpdb,
			'run_repository'         => $run_repository,
			'target_run_repository'  => $target_run_repository,
			'finding_repository'     => $finding_repository,
			'file_state_repository'  => $file_state_repository,
			'dispatcher'             => $dispatcher,
		);
	}

	/**
	 * `diff_status = pending` の run を1件作る(`reserve_run()`→
	 * `mark_planning_running()`→`finish_run()`と同じ、実際の遷移経路を通す
	 * ―― `finish_run()`が同じUPDATEで`diff_status=pending`を書くため.
	 * `WPCV_Run_Repository`のテストと同じパターン).
	 *
	 * @param WPCV_Run_Repository $run_repository 対象の repository.
	 * @return int 作成した run の id.
	 */
	private function make_run_ready_for_diff( WPCV_Run_Repository $run_repository ) {
		$run_id = $run_repository->reserve_run()['run_id'];
		$run_repository->mark_planning_running( $run_id );
		$run_repository->finish_run(
			$run_id,
			array(
				'status'               => 'success',
				'targets_total'        => 1,
				'targets_verified'     => 1,
				'targets_unverifiable' => 0,
				'targets_failed'       => 0,
				'findings_total'       => 0,
			)
		);

		return $run_id;
	}

	/**
	 * target_run 行を1件insertする.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb      テストダブル.
	 * @param int                 $run_id    所属する run の id.
	 * @param array                $overrides `wpcv_test_make_target_run()`への上書き.
	 * @return int insertした行の id.
	 */
	private function insert_target_run( WPCV_Test_Fake_WPDB $wpdb, $run_id, array $overrides = array() ) {
		$wpdb->insert(
			'wp_wpcv_target_runs',
			array_merge(
				wpcv_test_make_target_run(),
				array( 'run_id' => $run_id ),
				$overrides
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * finding 行を1件insertする.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb      テストダブル.
	 * @param array                $overrides `wpcv_test_make_finding_row()`への上書き.
	 * @return int insertした行の id.
	 */
	private function insert_finding( WPCV_Test_Fake_WPDB $wpdb, array $overrides = array() ) {
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( $overrides ) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * `diff_status`が対象外の run は claim できず `diff_not_claimable` を
	 * 返すことを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_diff_returns_not_claimable_when_run_not_pending() {
		$env    = $this->make_environment();
		$run_id = $env['run_repository']->reserve_run()['run_id'];
		// mark_planning_running()/finish_run()を経ていないため diff_status は
		// まだ NULL のまま(claim対象外).

		$result = $env['dispatcher']->dispatch_diff( $run_id );

		$this->assertSame( 'diff_not_claimable', $result['action'] );
	}

	/**
	 * `claim_diff()`が試行上限超過で`failed`にした場合、`diff_failed`を
	 * 返すことを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_diff_returns_diff_failed_after_max_attempts() {
		$env    = $this->make_environment();
		$run_id = $this->make_run_ready_for_diff( $env['run_repository'] );

		// lease切れ・試行回数が`WPCV_Run_Repository::DIFF_MAX_ATTEMPTS`(既定5)に
		// 既に達した状態を直接作る(dispatcher自身は既定値でclaim_diff()を呼ぶため、
		// この行操作でなければ既定の試行上限を再現できない).
		$env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['diff_status']           = 'processing';
		$env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['diff_owner']           = 'dead-owner';
		$env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['diff_lease_expires_at'] = '2020-01-01 00:00:00';
		$env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['diff_attempt_count']    = WPCV_Run_Repository::DIFF_MAX_ATTEMPTS;

		$result = $env['dispatcher']->dispatch_diff( $run_id );

		$this->assertSame( 'diff_failed', $result['action'] );
		$this->assertSame( 'failed', $env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['diff_status'] );
	}

	/**
	 * `first`モード(基準なし): 今回の finding がすべて `new` になり、run全体の
	 * 完了(`diff_finalized`・`findings_new`集計・`alerting`への遷移)まで通ることを
	 * 確認する(v0.5後半 §Step12・§1.3).
	 *
	 * @return void
	 */
	public function test_dispatch_diff_first_mode_marks_new_and_finalizes() {
		$env    = $this->make_environment();
		$run_id = $this->make_run_ready_for_diff( $env['run_repository'] );

		$target_run_id = $this->insert_target_run( $env['wpdb'], $run_id, array( 'target_id' => 'core' ) );
		$finding_id    = $this->insert_finding(
			$env['wpdb'],
			array( 'target_run_id' => $target_run_id, 'run_id' => $run_id, 'finding_key' => str_repeat( 'a', 64 ) )
		);

		// 1回目: firstモードを確定し、今回のfindingをnewにする(bulk mode. 1回で完了).
		$first = $env['dispatcher']->dispatch_diff( $run_id );
		$this->assertSame( 'diff_claimed', $first['action'] );
		$this->assertSame( WPCV_Generation_Differ::DIFF_MODE_FIRST, $env['wpdb']->rows['wp_wpcv_target_runs'][ $target_run_id ]['diff_mode'] );
		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_NEW, $env['wpdb']->rows['wp_wpcv_findings'][ $finding_id ]['diff_state'] );

		// 2回目: これ以上diff_modeが未設定のtargetが無いため確定へ進む.
		$second = $env['dispatcher']->dispatch_diff( $run_id );
		$this->assertSame( 'diff_finalized', $second['action'] );
		$this->assertSame( 'alerting', $env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['diff_status'] );
		$this->assertSame( 1, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['findings_new'] );
		$this->assertSame( 0, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['findings_resolved'] );
		$this->assertSame( 0, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['findings_continuing'] );
	}

	/**
	 * `version_changed`モード: 今回のfindingがnewになり、基準のfindingが
	 * `version_changed`で終わることを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_diff_version_changed_mode() {
		$env = $this->make_environment();

		// 基準run(id=1. version=1.0. success).
		$baseline_run_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		$baseline_tr_id  = $this->insert_target_run( $env['wpdb'], $baseline_run_id, array( 'target_id' => 'plugin:foo', 'version' => '1.0' ) );
		$baseline_finding_id = $this->insert_finding(
			$env['wpdb'],
			array( 'target_run_id' => $baseline_tr_id, 'run_id' => $baseline_run_id, 'target_id' => 'plugin:foo', 'finding_key' => str_repeat( 'a', 64 ) )
		);

		// 今回run(version=2.0).
		$run_id        = $this->make_run_ready_for_diff( $env['run_repository'] );
		$target_run_id = $this->insert_target_run( $env['wpdb'], $run_id, array( 'target_id' => 'plugin:foo', 'version' => '2.0' ) );
		$finding_id    = $this->insert_finding(
			$env['wpdb'],
			array( 'target_run_id' => $target_run_id, 'run_id' => $run_id, 'target_id' => 'plugin:foo', 'finding_key' => str_repeat( 'b', 64 ) )
		);

		$env['dispatcher']->dispatch_diff( $run_id );

		$this->assertSame( WPCV_Generation_Differ::DIFF_MODE_VERSION_CHANGED, $env['wpdb']->rows['wp_wpcv_target_runs'][ $target_run_id ]['diff_mode'] );
		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_NEW, $env['wpdb']->rows['wp_wpcv_findings'][ $finding_id ]['diff_state'] );
		$this->assertSame( $run_id, $env['wpdb']->rows['wp_wpcv_findings'][ $baseline_finding_id ]['ended_in_run_id'] );
		$this->assertSame( WPCV_Generation_Differ::END_REASON_VERSION_CHANGED, $env['wpdb']->rows['wp_wpcv_findings'][ $baseline_finding_id ]['end_reason'] );
	}

	/**
	 * `excluded`モード: 今回のfindingは無いまま(検証自体を省略している)、
	 * 基準のfindingが`excluded`で終わることを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_diff_excluded_mode_ends_baseline_only() {
		$env = $this->make_environment();

		$baseline_run_id     = $this->make_run_ready_for_diff( $env['run_repository'] );
		$baseline_tr_id      = $this->insert_target_run( $env['wpdb'], $baseline_run_id, array( 'target_id' => 'plugin:foo' ) );
		$baseline_finding_id = $this->insert_finding(
			$env['wpdb'],
			array( 'target_run_id' => $baseline_tr_id, 'run_id' => $baseline_run_id, 'target_id' => 'plugin:foo', 'finding_key' => str_repeat( 'a', 64 ) )
		);

		$run_id        = $this->make_run_ready_for_diff( $env['run_repository'] );
		$target_run_id = $this->insert_target_run(
			$env['wpdb'],
			$run_id,
			array( 'target_id' => 'plugin:foo', 'status' => 'skipped', 'error_code' => WPCV_Error_Code::EXCLUDED )
		);

		$env['dispatcher']->dispatch_diff( $run_id );

		$this->assertSame( WPCV_Generation_Differ::DIFF_MODE_EXCLUDED, $env['wpdb']->rows['wp_wpcv_target_runs'][ $target_run_id ]['diff_mode'] );
		$this->assertSame( $run_id, $env['wpdb']->rows['wp_wpcv_findings'][ $baseline_finding_id ]['ended_in_run_id'] );
		$this->assertSame( WPCV_Generation_Differ::END_REASON_EXCLUDED, $env['wpdb']->rows['wp_wpcv_findings'][ $baseline_finding_id ]['end_reason'] );
	}

	/**
	 * `skipped`(exclude_target以外の理由)モード: 基準を一切変えないことを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_diff_skipped_mode_leaves_baseline_untouched() {
		$env = $this->make_environment();

		$baseline_run_id     = $this->make_run_ready_for_diff( $env['run_repository'] );
		$baseline_tr_id      = $this->insert_target_run( $env['wpdb'], $baseline_run_id, array( 'target_id' => 'plugin:foo' ) );
		$baseline_finding_id = $this->insert_finding(
			$env['wpdb'],
			array( 'target_run_id' => $baseline_tr_id, 'run_id' => $baseline_run_id, 'target_id' => 'plugin:foo', 'finding_key' => str_repeat( 'a', 64 ) )
		);

		$run_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		$this->insert_target_run(
			$env['wpdb'],
			$run_id,
			array( 'target_id' => 'plugin:foo', 'status' => 'skipped', 'error_code' => WPCV_Error_Code::CHECKSUM_COVERED )
		);

		$env['dispatcher']->dispatch_diff( $run_id );

		$this->assertNull( $env['wpdb']->rows['wp_wpcv_findings'][ $baseline_finding_id ]['ended_in_run_id'] );
	}

	/**
	 * Stat target(`success`)は世代比較を経由せず、無条件で`event`になり、
	 * 抑制済みのfindingには触れないことを確認する(§2.3).
	 *
	 * @return void
	 */
	public function test_dispatch_diff_stat_target_marks_event() {
		$env    = $this->make_environment();
		$run_id = $this->make_run_ready_for_diff( $env['run_repository'] );

		$stat_target_id = WPCV_Target_Resolver::build_stat_id( 'plugin:foo' );
		$target_run_id  = $this->insert_target_run( $env['wpdb'], $run_id, array( 'target_id' => $stat_target_id, 'status' => 'success' ) );

		$event_finding_id      = $this->insert_finding( $env['wpdb'], array( 'target_run_id' => $target_run_id, 'run_id' => $run_id, 'target_id' => $stat_target_id, 'finding_key' => str_repeat( 'a', 64 ) ) );
		$suppressed_finding_id = $this->insert_finding( $env['wpdb'], array( 'target_run_id' => $target_run_id, 'run_id' => $run_id, 'target_id' => $stat_target_id, 'finding_key' => str_repeat( 'b', 64 ), 'suppressed_by' => 'soft_change' ) );

		$env['dispatcher']->dispatch_diff( $run_id );

		$this->assertSame( WPCV_Generation_Differ::DIFF_MODE_EVENT, $env['wpdb']->rows['wp_wpcv_target_runs'][ $target_run_id ]['diff_mode'] );
		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_EVENT, $env['wpdb']->rows['wp_wpcv_findings'][ $event_finding_id ]['diff_state'] );
		$this->assertNull( $env['wpdb']->rows['wp_wpcv_findings'][ $suppressed_finding_id ]['diff_state'] );
	}

	/**
	 * `compared`モード(2-pass): continuing・new・抑制終了・resolvedのすべての
	 * 分岐を1シナリオで確認する(v0.5後半 §Step12・§1.4).
	 *
	 * @return void
	 */
	public function test_dispatch_diff_compared_mode_two_pass() {
		$env = $this->make_environment();

		$baseline_run_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		$baseline_tr_id  = $this->insert_target_run( $env['wpdb'], $baseline_run_id, array( 'target_id' => 'plugin:foo', 'version' => '1.0' ) );

		// 基準finding: key-continuing(継続予定)・key-suppressed(今回抑制終了予定)・key-resolved(今回無くなる予定).
		$baseline_continuing_id = $this->insert_finding( $env['wpdb'], array( 'target_run_id' => $baseline_tr_id, 'run_id' => $baseline_run_id, 'target_id' => 'plugin:foo', 'finding_key' => 'key-continuing' ) );
		$baseline_suppressed_id = $this->insert_finding( $env['wpdb'], array( 'target_run_id' => $baseline_tr_id, 'run_id' => $baseline_run_id, 'target_id' => 'plugin:foo', 'finding_key' => 'key-suppressed' ) );
		$baseline_resolved_id   = $this->insert_finding( $env['wpdb'], array( 'target_run_id' => $baseline_tr_id, 'run_id' => $baseline_run_id, 'target_id' => 'plugin:foo', 'finding_key' => 'key-resolved' ) );

		$run_id        = $this->make_run_ready_for_diff( $env['run_repository'] );
		$target_run_id = $this->insert_target_run( $env['wpdb'], $run_id, array( 'target_id' => 'plugin:foo', 'version' => '1.0' ) );

		$current_continuing_id = $this->insert_finding( $env['wpdb'], array( 'target_run_id' => $target_run_id, 'run_id' => $run_id, 'target_id' => 'plugin:foo', 'finding_key' => 'key-continuing' ) );
		$current_suppressed_id = $this->insert_finding( $env['wpdb'], array( 'target_run_id' => $target_run_id, 'run_id' => $run_id, 'target_id' => 'plugin:foo', 'finding_key' => 'key-suppressed', 'suppressed_by' => 'soft_change' ) );
		$current_new_id        = $this->insert_finding( $env['wpdb'], array( 'target_run_id' => $target_run_id, 'run_id' => $run_id, 'target_id' => 'plugin:foo', 'finding_key' => 'key-new' ) );

		// Call 1: mode確定 + pass1のcursorをセット.
		$call1 = $env['dispatcher']->dispatch_diff( $run_id );
		$this->assertSame( 'diff_claimed', $call1['action'] );
		$this->assertSame( WPCV_Generation_Differ::DIFF_MODE_COMPARED, $env['wpdb']->rows['wp_wpcv_target_runs'][ $target_run_id ]['diff_mode'] );

		// Call 2: pass1本体(1バッチで完了。3件のみのためbatch_sizeを超えない).
		$call2 = $env['dispatcher']->dispatch_diff( $run_id );
		$this->assertSame( 'diff_claimed', $call2['action'] );

		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_CONTINUING, $env['wpdb']->rows['wp_wpcv_findings'][ $current_continuing_id ]['diff_state'] );
		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_NEW, $env['wpdb']->rows['wp_wpcv_findings'][ $current_new_id ]['diff_state'] );
		$this->assertNull( $env['wpdb']->rows['wp_wpcv_findings'][ $current_suppressed_id ]['diff_state'] );

		// pass1時点で抑制終了(suppressed)が既に反映されている.
		$this->assertSame( $run_id, $env['wpdb']->rows['wp_wpcv_findings'][ $baseline_suppressed_id ]['ended_in_run_id'] );
		$this->assertSame( WPCV_Generation_Differ::END_REASON_SUPPRESSED, $env['wpdb']->rows['wp_wpcv_findings'][ $baseline_suppressed_id ]['end_reason'] );

		// Call 3: pass2本体(基準側. key-resolvedがresolvedになる).
		$call3 = $env['dispatcher']->dispatch_diff( $run_id );
		$this->assertSame( 'diff_claimed', $call3['action'] );

		$this->assertNull( $env['wpdb']->rows['wp_wpcv_findings'][ $baseline_continuing_id ]['ended_in_run_id'], 'continuingは終わらせない' );
		$this->assertSame( $run_id, $env['wpdb']->rows['wp_wpcv_findings'][ $baseline_resolved_id ]['ended_in_run_id'] );
		$this->assertSame( WPCV_Generation_Differ::END_REASON_RESOLVED, $env['wpdb']->rows['wp_wpcv_findings'][ $baseline_resolved_id ]['end_reason'] );

		// Call 4: 確定(alertingへ. new=1・continuing=1・resolved=1).
		$call4 = $env['dispatcher']->dispatch_diff( $run_id );
		$this->assertSame( 'diff_finalized', $call4['action'] );
		$this->assertSame( 1, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['findings_new'] );
		$this->assertSame( 1, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['findings_continuing'] );
		$this->assertSame( 1, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['findings_resolved'] );
	}

	/**
	 * `not_verified`モード: pass 1のみ行い(基準側をresolvedにしない)、
	 * 途中失敗前に記録された部分的なfindingのcontinuing/new判定だけを行うことを
	 * 確認する(§1.4).
	 *
	 * @return void
	 */
	public function test_dispatch_diff_not_verified_mode_runs_pass1_only() {
		$env = $this->make_environment();

		$baseline_run_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		$baseline_tr_id  = $this->insert_target_run( $env['wpdb'], $baseline_run_id, array( 'target_id' => 'plugin:foo' ) );
		$baseline_unmatched_id = $this->insert_finding( $env['wpdb'], array( 'target_run_id' => $baseline_tr_id, 'run_id' => $baseline_run_id, 'target_id' => 'plugin:foo', 'finding_key' => 'key-a' ) );

		$run_id        = $this->make_run_ready_for_diff( $env['run_repository'] );
		$target_run_id = $this->insert_target_run(
			$env['wpdb'],
			$run_id,
			array( 'target_id' => 'plugin:foo', 'status' => 'failed', 'error_code' => WPCV_Error_Code::TIMEOUT )
		);
		$current_partial_id = $this->insert_finding( $env['wpdb'], array( 'target_run_id' => $target_run_id, 'run_id' => $run_id, 'target_id' => 'plugin:foo', 'finding_key' => 'key-a' ) );

		$env['dispatcher']->dispatch_diff( $run_id ); // mode確定 + pass1 cursor.
		$env['dispatcher']->dispatch_diff( $run_id ); // pass1本体.

		$this->assertSame( WPCV_Generation_Differ::DIFF_MODE_NOT_VERIFIED, $env['wpdb']->rows['wp_wpcv_target_runs'][ $target_run_id ]['diff_mode'] );
		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_CONTINUING, $env['wpdb']->rows['wp_wpcv_findings'][ $current_partial_id ]['diff_state'] );
		// not_verifiedはpass 2を行わないため、基準は一切終わらせない.
		$this->assertNull( $env['wpdb']->rows['wp_wpcv_findings'][ $baseline_unmatched_id ]['ended_in_run_id'] );
	}

	/**
	 * Target_removed(アンインストール)検出: 今回のrunのtarget_run一覧に
	 * 現れないtarget_idの基準findingが、全target確定タイミングで
	 * `target_removed`として終わることを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_diff_detects_removed_target() {
		$env = $this->make_environment();

		$baseline_run_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		$baseline_tr_id  = $this->insert_target_run( $env['wpdb'], $baseline_run_id, array( 'target_id' => 'plugin:uninstalled' ) );
		$removed_finding_id = $this->insert_finding( $env['wpdb'], array( 'target_run_id' => $baseline_tr_id, 'run_id' => $baseline_run_id, 'target_id' => 'plugin:uninstalled', 'finding_key' => 'key-a' ) );

		$run_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		// 今回のrunには`plugin:uninstalled`のtarget_runが無い(アンインストール済み).
		$this->insert_target_run( $env['wpdb'], $run_id, array( 'target_id' => 'core' ) );

		$env['dispatcher']->dispatch_diff( $run_id ); // coreをfirstモードで確定.
		$env['dispatcher']->dispatch_diff( $run_id ); // 全target完了→掃除+確定.

		$this->assertSame( $run_id, $env['wpdb']->rows['wp_wpcv_findings'][ $removed_finding_id ]['ended_in_run_id'] );
		$this->assertSame( WPCV_Generation_Differ::END_REASON_TARGET_REMOVED, $env['wpdb']->rows['wp_wpcv_findings'][ $removed_finding_id ]['end_reason'] );
	}

	/**
	 * 全target確定タイミングで、`wpcv_file_states`の掃除
	 * (`delete_targets_not_enumerated()`)が今回列挙されたstat target_idの
	 * 一覧をもとに呼ばれることを確認する(v0.5 §Step9との連携).
	 *
	 * @return void
	 */
	public function test_dispatch_diff_cleans_up_file_states_for_stat_targets() {
		$env    = $this->make_environment();
		$run_id = $this->make_run_ready_for_diff( $env['run_repository'] );

		$stat_target_id = WPCV_Target_Resolver::build_stat_id( 'plugin:foo' );
		$this->insert_target_run( $env['wpdb'], $run_id, array( 'target_id' => $stat_target_id, 'status' => 'success' ) );

		// 今回列挙されなかった(アンインストール済みの)stat targetの残骸行.
		$env['wpdb']->insert(
			'wp_wpcv_file_states',
			array(
				'state_key'         => WPCV_File_State_Repository::compute_state_key( 'plugin:stale:_stat', 'file.php' ),
				'target_id'         => 'plugin:stale:_stat',
				'dimension'         => 'plugin',
				'slug'              => 'stale',
				'path'              => 'file.php',
				'file_size'         => 1,
				'ctime'             => 1,
				'mtime'             => 1,
				'content_hash'      => null,
				'hash_algorithm'    => null,
				'baseline_version'  => null,
				'first_seen_run_id' => 1,
				'last_seen_run_id'  => 1,
				'updated_at'        => self::NOW,
			)
		);

		$env['dispatcher']->dispatch_diff( $run_id ); // statをeventモードで確定.
		$env['dispatcher']->dispatch_diff( $run_id ); // 全target完了→掃除+確定.

		$this->assertArrayNotHasKey( 1, $env['wpdb']->rows['wp_wpcv_file_states'] ?? array( 1 => true ), '列挙されなかったstat targetの行は削除される' );
	}
}
