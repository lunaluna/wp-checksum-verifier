<?php
/**
 * WPCV_Plugin のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-static-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-budget.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-update-lock-detector.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-source-core.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-source-wporg-plugin.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-diff-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-finding-key.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-matcher.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-suppression-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-file-state-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-cursor.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-verifier.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-chunk-result-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-context-builder.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-chunk-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-generation-differ.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-alert-composer.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-alert-sender.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-diff-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-failure-alerter.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-update-event-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-update-event-recorder.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-update-event-matcher.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-plugin.php';

use PHPUnit\Framework\TestCase;

/**
 * composition root として正しい型の `WPCV_Run_Coordinator` を組み立てられることを検証する.
 *
 * DB アクセスを伴う組み立て後の挙動(`run()` を呼んだときの実際の検証結果)は
 * `RunCoordinatorTest` で個別のテストダブルを使って検証済みのため、ここでは
 * 「正しい型のインスタンスが返る」「使い回される」ことのみを確認する
 * (実地での動作確認は Step 2 の WP-CLI コマンドとあわせて行う).
 */
class PluginTest extends TestCase {

	/**
	 * `run_coordinator()` が `WPCV_Run_Coordinator` のインスタンスを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_run_coordinator_returns_run_coordinator_instance() {
		$this->assertInstanceOf( WPCV_Run_Coordinator::class, WPCV_Plugin::run_coordinator() );
	}

	/**
	 * 複数回呼び出しても同一インスタンスが返る(1回だけ組み立てる)ことを確認する.
	 *
	 * @return void
	 */
	public function test_run_coordinator_returns_same_instance_on_repeated_calls() {
		$this->assertSame( WPCV_Plugin::run_coordinator(), WPCV_Plugin::run_coordinator() );
	}

	/**
	 * `chunk_dispatcher()`(v0.4.0 §Step4)が `WPCV_Chunk_Dispatcher` のインスタンスを
	 * 返し、複数回呼び出しても同一インスタンスが返ることを確認する.
	 *
	 * @return void
	 */
	public function test_chunk_dispatcher_returns_same_instance_on_repeated_calls() {
		$this->assertInstanceOf( WPCV_Chunk_Dispatcher::class, WPCV_Plugin::chunk_dispatcher() );
		$this->assertSame( WPCV_Plugin::chunk_dispatcher(), WPCV_Plugin::chunk_dispatcher() );
	}

	/**
	 * `chunk_result_repository()`(v0.4.0 §Step4)が `WPCV_Chunk_Result_Repository` の
	 * インスタンスを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_chunk_result_repository_returns_chunk_result_repository_instance() {
		$this->assertInstanceOf( WPCV_Chunk_Result_Repository::class, WPCV_Plugin::chunk_result_repository() );
	}

	/**
	 * `sync_dispatcher()`(v0.4.0 §Step5/§Step6)が `WPCV_Chunk_Dispatcher` のインスタンスを
	 * 返し、複数回呼び出しても同一インスタンスが返ることを確認する.
	 *
	 * @return void
	 */
	public function test_sync_dispatcher_returns_same_instance_on_repeated_calls() {
		$this->assertInstanceOf( WPCV_Chunk_Dispatcher::class, WPCV_Plugin::sync_dispatcher() );
		$this->assertSame( WPCV_Plugin::sync_dispatcher(), WPCV_Plugin::sync_dispatcher() );
	}

	/**
	 * `sync_dispatcher()` と `chunk_dispatcher()` が別インスタンスであることを確認する
	 * (continuation schedulerの有無が異なるため。`WPCV_Plugin::sync_dispatcher()` の
	 * docblock参照).
	 *
	 * @return void
	 */
	public function test_sync_dispatcher_is_a_different_instance_from_chunk_dispatcher() {
		$this->assertNotSame( WPCV_Plugin::sync_dispatcher(), WPCV_Plugin::chunk_dispatcher() );
	}

	/**
	 * `file_state_repository()`(v0.5 §Step2)が `WPCV_File_State_Repository` の
	 * インスタンスを返し、複数回呼び出しても同一インスタンスが返ることを確認する.
	 *
	 * @return void
	 */
	public function test_file_state_repository_returns_same_instance_on_repeated_calls() {
		$this->assertInstanceOf( WPCV_File_State_Repository::class, WPCV_Plugin::file_state_repository() );
		$this->assertSame( WPCV_Plugin::file_state_repository(), WPCV_Plugin::file_state_repository() );
	}

	/**
	 * `run_failure_alerter()`(v0.5後半 §Step15a)が `WPCV_Run_Failure_Alerter` の
	 * インスタンスを返し、複数回呼び出しても同一インスタンスが返ることを確認する.
	 *
	 * @return void
	 */
	public function test_run_failure_alerter_returns_same_instance_on_repeated_calls() {
		$this->assertInstanceOf( WPCV_Run_Failure_Alerter::class, WPCV_Plugin::run_failure_alerter() );
		$this->assertSame( WPCV_Plugin::run_failure_alerter(), WPCV_Plugin::run_failure_alerter() );
	}

	/**
	 * `update_event_repository()`(v0.6 §Step1)が `WPCV_Update_Event_Repository` の
	 * インスタンスを返し、複数回呼び出しても同一インスタンスが返ることを確認する.
	 *
	 * @return void
	 */
	public function test_update_event_repository_returns_same_instance_on_repeated_calls() {
		$this->assertInstanceOf( WPCV_Update_Event_Repository::class, WPCV_Plugin::update_event_repository() );
		$this->assertSame( WPCV_Plugin::update_event_repository(), WPCV_Plugin::update_event_repository() );
	}

	/**
	 * `update_event_recorder()`(v0.6 §Step2)が `WPCV_Update_Event_Recorder` の
	 * インスタンスを返し、複数回呼び出しても同一インスタンスが返ることを確認する.
	 *
	 * @return void
	 */
	public function test_update_event_recorder_returns_same_instance_on_repeated_calls() {
		$this->assertInstanceOf( WPCV_Update_Event_Recorder::class, WPCV_Plugin::update_event_recorder() );
		$this->assertSame( WPCV_Plugin::update_event_recorder(), WPCV_Plugin::update_event_recorder() );
	}

	/**
	 * `update_event_matcher()`(v0.6 §Step3。§Step4で`WPCV_Chunk_Dispatcher`とも
	 * 共有するようにした)が `WPCV_Update_Event_Matcher` のインスタンスを返し、
	 * 複数回呼び出しても同一インスタンスが返ることを確認する.
	 *
	 * @return void
	 */
	public function test_update_event_matcher_returns_same_instance_on_repeated_calls() {
		$this->assertInstanceOf( WPCV_Update_Event_Matcher::class, WPCV_Plugin::update_event_matcher() );
		$this->assertSame( WPCV_Plugin::update_event_matcher(), WPCV_Plugin::update_event_matcher() );
	}
}
