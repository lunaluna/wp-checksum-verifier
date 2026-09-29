<?php
/**
 * `WPCV_Diff_Dispatcher` の複数世代(run)にまたがる統合テスト.
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
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-alert-composer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-finding-key.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-file-state-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-migrator.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-update-event-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-update-event-matcher.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-alert-sender.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-diff-dispatcher.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Diff_Dispatcher::dispatch_diff()` を複数の run にまたがって連続実行し、
 * 世代を横断した NEW → CONTINUING → RESOLVED の遷移と、version変更時の
 * version_changed への切り替わりを確認する(v0.5後半 §Step12 進捗メモ §3・§4
 * 「次のセッションが最初にすべきこと」2番目の統合テスト).
 *
 * `DiffDispatcherTest.php` は各モードを1世代分(基準1件・今回1件)の孤立した
 * シナリオで確認しているのに対し、このテストは同一target(`plugin:foo`)を
 * 4世代(run1〜run4)にわたって実際に`save_findings()`(本番コード)で保存し続け、
 * finding_key の一致・不一致が本物のハッシュ計算を通じて世代をまたいで
 * 正しく機能することを確認する.
 */
class DiffMultiGenerationTest extends TestCase {

	/**
	 * 他のテストファイルが残した設定を引き継がないよう掃除する
	 * (`DiffDispatcherTest`のsetUp()と同じ理由).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_options'], $GLOBALS['_wpcv_test_wp_mail_calls'] );
	}

	/**
	 * 固定の現在時刻(他の差分処理テストと同じ値).
	 *
	 * @var string
	 */
	const NOW = '2026-09-08 12:00:00';

	/**
	 * このテストファイル共通の環境(Repository群・Dispatcher・`$wpdb`)を組み立てる
	 * (`DiffDispatcherTest::make_environment()`と同じ構成).
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

		$wpdb                    = new WPCV_Test_Fake_WPDB();
		$run_repository          = new WPCV_Run_Repository( $wpdb, $now );
		$target_run_repository   = new WPCV_Target_Run_Repository( $wpdb, $now );
		$finding_repository      = new WPCV_Finding_Repository( $wpdb );
		$file_state_repository   = new WPCV_File_State_Repository( $wpdb, $now );
		$alert_sender            = new WPCV_Alert_Sender( $run_repository, $target_run_repository, $finding_repository, $now );
		$update_event_repository = new WPCV_Update_Event_Repository( $wpdb, $now );
		$update_event_matcher    = new WPCV_Update_Event_Matcher( $update_event_repository, $run_repository );

		$owner_sequence = 0;
		$dispatcher     = new WPCV_Diff_Dispatcher(
			$run_repository,
			$target_run_repository,
			$finding_repository,
			$file_state_repository,
			$alert_sender,
			$update_event_matcher,
			static function () use ( &$owner_sequence ) {
				++$owner_sequence;
				return 'owner-' . $owner_sequence;
			}
		);

		return array(
			'wpdb'                     => $wpdb,
			'run_repository'           => $run_repository,
			'target_run_repository'    => $target_run_repository,
			'finding_repository'       => $finding_repository,
			'file_state_repository'    => $file_state_repository,
			'alert_sender'             => $alert_sender,
			'update_event_repository'  => $update_event_repository,
			'update_event_matcher'     => $update_event_matcher,
			'dispatcher'               => $dispatcher,
		);
	}

	/**
	 * `diff_status = pending` の run を1件作る(`DiffDispatcherTest`と同じ
	 * `reserve_run()`→`mark_planning_running()`→`finish_run()`の経路).
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
	 * target_run 行を1件insertする(`DiffDispatcherTest::insert_target_run()`と同じ).
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
	 * `dispatch_diff()`を、対象runが`diff_finalized`になるまで繰り返し呼ぶ.
	 *
	 * 実運用ではAction Schedulerの継続action自己連鎖がこれを行う
	 * (`WPCV_Chunk_Dispatcher`経由). このテストはそれを1プロセス内のループで
	 * 代替する.
	 *
	 * @param WPCV_Diff_Dispatcher $dispatcher     対象のdispatcher.
	 * @param int                  $run_id         対象のrun id.
	 * @param int                  $max_iterations 無限ループ防止用の上限(未実測.
	 *                                              このテストの最大4パス構成なら
	 *                                              十分すぎる余裕を見た値).
	 * @return void
	 */
	private function drive_diff_to_completion( WPCV_Diff_Dispatcher $dispatcher, $run_id, $max_iterations = 50 ) {
		for ( $i = 0; $i < $max_iterations; $i++ ) {
			$result = $dispatcher->dispatch_diff( $run_id );

			if ( 'diff_finalized' === $result['action'] ) {
				return;
			}

			if ( 'diff_failed' === $result['action'] || 'diff_not_claimable' === $result['action'] ) {
				$this->fail( 'dispatch_diff() が完了前に停止しました: action=' . $result['action'] );
			}
		}

		$this->fail( '差分処理が' . $max_iterations . '回のdispatch_diff()呼び出しでも完了しませんでした' );
	}

	/**
	 * 指定target_runに属するfindingのidを`id`昇順で返す
	 * (`save_findings()`はidを戻さないため、fakeのrows配列を直接読んで探す).
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb          テストダブル.
	 * @param int                 $target_run_id 対象のtarget_run id.
	 * @return int[]
	 */
	private function finding_ids_for_target_run( WPCV_Test_Fake_WPDB $wpdb, $target_run_id ) {
		$ids = array();

		foreach ( $wpdb->rows['wp_wpcv_findings'] ?? array() as $id => $row ) {
			if ( (int) $row['target_run_id'] === (int) $target_run_id ) {
				$ids[] = (int) $id;
			}
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * 同一target(`plugin:foo`)を4世代(run1〜run4)にわたって検証し、
	 * NEW → CONTINUING → RESOLVED → (version変更で再び)NEW という
	 * 一連の流れが、本番の`save_findings()`が計算する実際の`finding_key`を
	 * 通じて正しく機能することを確認する.
	 *
	 * - run1: 基準無し(first). 改ざんファイル`foo.php`を検出 → new.
	 * - run2: 同じ改ざん内容がそのまま続く(version不変) → continuing
	 *   (run1側のfindingはまだ終わらせない).
	 * - run3: ファイルを元に戻した(このtargetのfindingが0件になる) →
	 *   run2側のfindingがresolvedで終わる.
	 * - run4: versionが2.0に上がり、別の改ざん`bar.php`を検出 →
	 *   version_changed. 今回のfindingはnew(run3側は元々0件のため、
	 *   version_changedの「基準を終わらせる」処理は実質no-op).
	 *
	 * @return void
	 */
	public function test_full_generation_lifecycle_new_continuing_resolved_and_version_changed() {
		$env       = $this->make_environment();
		$target_id = 'plugin:foo';

		// --- run1: firstモード. 改ざんが1件見つかる. ---
		$run1_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		$tr1_id  = $this->insert_target_run( $env['wpdb'], $run1_id, array( 'target_id' => $target_id, 'version' => '1.0' ) );

		$finding_v1 = wpcv_test_make_finding(
			array(
				'target_id' => $target_id,
				'dimension' => 'plugin',
				'slug'      => 'foo',
				'version'   => '1.0',
				'path'      => 'foo.php',
			)
		);
		$env['finding_repository']->save_findings( $run1_id, array( $target_id => $tr1_id ), array( $finding_v1 ) );

		$this->drive_diff_to_completion( $env['dispatcher'], $run1_id );

		$this->assertSame( WPCV_Generation_Differ::DIFF_MODE_FIRST, $env['wpdb']->rows['wp_wpcv_target_runs'][ $tr1_id ]['diff_mode'] );

		$run1_finding_ids = $this->finding_ids_for_target_run( $env['wpdb'], $tr1_id );
		$this->assertCount( 1, $run1_finding_ids );
		$finding1_id = $run1_finding_ids[0];

		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_NEW, $env['wpdb']->rows['wp_wpcv_findings'][ $finding1_id ]['diff_state'] );
		$this->assertSame( 1, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run1_id ]['findings_new'] );

		// --- run2: 同じ内容が継続(continuing). ---
		$run2_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		$tr2_id  = $this->insert_target_run( $env['wpdb'], $run2_id, array( 'target_id' => $target_id, 'version' => '1.0' ) );
		$env['finding_repository']->save_findings( $run2_id, array( $target_id => $tr2_id ), array( $finding_v1 ) );

		$this->drive_diff_to_completion( $env['dispatcher'], $run2_id );

		$this->assertSame( WPCV_Generation_Differ::DIFF_MODE_COMPARED, $env['wpdb']->rows['wp_wpcv_target_runs'][ $tr2_id ]['diff_mode'] );

		$run2_finding_ids = $this->finding_ids_for_target_run( $env['wpdb'], $tr2_id );
		$this->assertCount( 1, $run2_finding_ids );
		$finding2_id = $run2_finding_ids[0];

		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_CONTINUING, $env['wpdb']->rows['wp_wpcv_findings'][ $finding2_id ]['diff_state'] );
		$this->assertNull( $env['wpdb']->rows['wp_wpcv_findings'][ $finding1_id ]['ended_in_run_id'], 'continuing中の基準findingはまだ終わらせない' );
		$this->assertSame( 0, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run2_id ]['findings_new'] );
		$this->assertSame( 1, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run2_id ]['findings_continuing'] );
		$this->assertSame( 0, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run2_id ]['findings_resolved'] );

		// --- run3: ファイルを元に戻した(このtargetのfindingは0件). resolved. ---
		$run3_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		$tr3_id  = $this->insert_target_run( $env['wpdb'], $run3_id, array( 'target_id' => $target_id, 'version' => '1.0' ) );
		// このrunではfindingを保存しない(検証結果がクリーンだったことを表す).

		$this->drive_diff_to_completion( $env['dispatcher'], $run3_id );

		$this->assertSame( WPCV_Generation_Differ::DIFF_MODE_COMPARED, $env['wpdb']->rows['wp_wpcv_target_runs'][ $tr3_id ]['diff_mode'] );
		$this->assertSame( array(), $this->finding_ids_for_target_run( $env['wpdb'], $tr3_id ) );

		$this->assertSame( $run3_id, $env['wpdb']->rows['wp_wpcv_findings'][ $finding2_id ]['ended_in_run_id'] );
		$this->assertSame( WPCV_Generation_Differ::END_REASON_RESOLVED, $env['wpdb']->rows['wp_wpcv_findings'][ $finding2_id ]['end_reason'] );
		$this->assertSame( 0, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run3_id ]['findings_new'] );
		$this->assertSame( 0, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run3_id ]['findings_continuing'] );
		$this->assertSame( 1, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run3_id ]['findings_resolved'] );

		// --- run4: versionが2.0に上がり、別の改ざんを検出(version_changed). ---
		$run4_id    = $this->make_run_ready_for_diff( $env['run_repository'] );
		$tr4_id     = $this->insert_target_run( $env['wpdb'], $run4_id, array( 'target_id' => $target_id, 'version' => '2.0' ) );
		$finding_v2 = wpcv_test_make_finding(
			array(
				'target_id' => $target_id,
				'dimension' => 'plugin',
				'slug'      => 'foo',
				'version'   => '2.0',
				'path'      => 'bar.php',
			)
		);
		$env['finding_repository']->save_findings( $run4_id, array( $target_id => $tr4_id ), array( $finding_v2 ) );

		$this->drive_diff_to_completion( $env['dispatcher'], $run4_id );

		$this->assertSame( WPCV_Generation_Differ::DIFF_MODE_VERSION_CHANGED, $env['wpdb']->rows['wp_wpcv_target_runs'][ $tr4_id ]['diff_mode'] );

		$run4_finding_ids = $this->finding_ids_for_target_run( $env['wpdb'], $tr4_id );
		$this->assertCount( 1, $run4_finding_ids );
		$finding4_id = $run4_finding_ids[0];

		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_NEW, $env['wpdb']->rows['wp_wpcv_findings'][ $finding4_id ]['diff_state'] );
		$this->assertSame( 1, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run4_id ]['findings_new'] );
		$this->assertSame( 0, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run4_id ]['findings_continuing'] );
		$this->assertSame( 0, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run4_id ]['findings_resolved'] );
	}

	/**
	 * 差分処理で終わった(resolved)過去のrunのfindingが、終わったあとも
	 * `query()`でそのrunの結果として見えることを確認する(v0.5後半プラン D3の核心:
	 * closed_atを書かずended_in_run_idで履歴を保つ設計. `query()`の既定の
	 * 絞り込みはclosed_at基準のため、ended_in_run_idを書いても消えない).
	 *
	 * @return void
	 */
	public function test_resolved_finding_remains_visible_in_past_run_query() {
		$env       = $this->make_environment();
		$target_id = 'plugin:foo';
		$finding   = wpcv_test_make_finding(
			array(
				'target_id' => $target_id,
				'dimension' => 'plugin',
				'slug'      => 'foo',
				'version'   => '1.0',
				'path'      => 'foo.php',
			)
		);

		// run1: 改ざんを検出.
		$run1_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		$tr1_id  = $this->insert_target_run( $env['wpdb'], $run1_id, array( 'target_id' => $target_id, 'version' => '1.0' ) );
		$env['finding_repository']->save_findings( $run1_id, array( $target_id => $tr1_id ), array( $finding ) );
		$this->drive_diff_to_completion( $env['dispatcher'], $run1_id );

		// run2: ファイルを元に戻した(findingが0件) → run1のfindingがresolvedになる.
		$run2_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		$this->insert_target_run( $env['wpdb'], $run2_id, array( 'target_id' => $target_id, 'version' => '1.0' ) );
		$this->drive_diff_to_completion( $env['dispatcher'], $run2_id );

		$finding1_id = $this->finding_ids_for_target_run( $env['wpdb'], $tr1_id )[0];
		$this->assertSame( $run2_id, $env['wpdb']->rows['wp_wpcv_findings'][ $finding1_id ]['ended_in_run_id'] );

		// 終わったあとも、run1の検出結果として既定の条件で見える.
		$result = $env['finding_repository']->query( array( 'run_id' => $run1_id ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( array( 'foo.php' ), array_column( $result['rows'], 'path' ) );
	}

	/**
	 * 差分処理のchunkの途中で例外が出ても、lease切れのあとに同じ位置から
	 * やり直して正しい結果で完了することを確認する(v0.5後半プラン Step12の
	 * 完了条件. `WPCV_Diff_Dispatcher`は例外を捕捉せず、runを`processing`のまま
	 * 残し、lease切れ後の`claim_diff()`に再開を任せる設計).
	 *
	 * あわせて、lease有効中は別の呼び出しがclaimできないこと(Dispatcher層での
	 * 二重claimの拒否)も確認する.
	 *
	 * @return void
	 */
	public function test_diff_resumes_after_exception_in_middle_of_chunk() {
		$env       = $this->make_environment();
		$target_id = 'plugin:foo';
		$make      = static function ( $path ) use ( $target_id ) {
			return wpcv_test_make_finding(
				array(
					'target_id' => $target_id,
					'dimension' => 'plugin',
					'slug'      => 'foo',
					'version'   => '1.0',
					'path'      => $path,
				)
			);
		};

		// 基準run: a.php(継続予定)・b.php(解消予定).
		$baseline_run_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		$baseline_tr_id  = $this->insert_target_run( $env['wpdb'], $baseline_run_id, array( 'target_id' => $target_id, 'version' => '1.0' ) );
		$env['finding_repository']->save_findings( $baseline_run_id, array( $target_id => $baseline_tr_id ), array( $make( 'a.php' ), $make( 'b.php' ) ) );
		$this->drive_diff_to_completion( $env['dispatcher'], $baseline_run_id );

		// 今回run: a.php(継続)・c.php(新規).
		$run_id = $this->make_run_ready_for_diff( $env['run_repository'] );
		$tr_id  = $this->insert_target_run( $env['wpdb'], $run_id, array( 'target_id' => $target_id, 'version' => '1.0' ) );
		$env['finding_repository']->save_findings( $run_id, array( $target_id => $tr_id ), array( $make( 'a.php' ), $make( 'c.php' ) ) );

		// 1回目: comparedモードを確定し、pass 1のcursorを置く(ここは成功させる).
		$this->assertSame( 'diff_claimed', $env['dispatcher']->dispatch_diff( $run_id )['action'] );

		// 2回目: pass 1本体の書き込みでDBエラーを起こす.
		$env['wpdb']->query_should_fail = true;

		try {
			$env['dispatcher']->dispatch_diff( $run_id );
			$this->fail( 'pass 1の書き込み失敗で例外が出るはず' );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		$env['wpdb']->query_should_fail = false;

		$run_row = $env['wpdb']->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 'processing', $run_row['diff_status'], '例外のあとはprocessingのまま残る' );

		foreach ( $this->finding_ids_for_target_run( $env['wpdb'], $tr_id ) as $id ) {
			$this->assertNull( $env['wpdb']->rows['wp_wpcv_findings'][ $id ]['diff_state'], '失敗したchunkの書き込みは反映されていない' );
		}

		// lease有効中は、別の呼び出しがclaimできない.
		$this->assertSame( 'diff_not_claimable', $env['dispatcher']->dispatch_diff( $run_id )['action'] );

		// lease切れにして再開させる(固定時刻NOWより前にする).
		$env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['diff_lease_expires_at'] = '2020-01-01 00:00:00';

		$this->drive_diff_to_completion( $env['dispatcher'], $run_id );

		$states = array();
		foreach ( $this->finding_ids_for_target_run( $env['wpdb'], $tr_id ) as $id ) {
			$row                    = $env['wpdb']->rows['wp_wpcv_findings'][ $id ];
			$states[ $row['path'] ] = $row['diff_state'];
		}

		$this->assertSame(
			array(
				'a.php' => WPCV_Generation_Differ::DIFF_STATE_CONTINUING,
				'c.php' => WPCV_Generation_Differ::DIFF_STATE_NEW,
			),
			$states
		);
		$this->assertSame( 1, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['findings_new'] );
		$this->assertSame( 1, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['findings_continuing'] );
		$this->assertSame( 1, (int) $env['wpdb']->rows['wp_wpcv_runs'][ $run_id ]['findings_resolved'] );
	}
}
