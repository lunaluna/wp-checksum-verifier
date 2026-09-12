<?php
/**
 * WPCV_CLI_Command のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-budget.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-cursor.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-matcher.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-suppression-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-chunk-result-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-chunk-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-starter.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-context-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-plugin.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-runner-async.php';
require_once dirname( __DIR__ ) . '/includes/cli/class-wpcv-cli-command.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `wp wpcv run` の実体である `WPCV_CLI_Command::__invoke()` のテスト.
 *
 * 実際の `WPCV_Plugin::run_coordinator()`(composition root)は `global $wpdb`
 * を必要とするため、`doubles.php` の `wpcv_test_make_fake_environment()` /
 * `wpcv_test_inject_run_coordinator()` / `wpcv_test_inject_run_repository()` で
 * 手書きテストダブルに差し替える(composition root 自体は `PluginTest` で別途検証済み)。
 * v0.3.1 §Step1で `__invoke()` が `WPCV_Plugin::run_repository()->reserve_run()` を
 * 直接呼ぶようになったため、`run_coordinator()` と `run_repository()` の両方を
 * (本番の composition root が同じインスタンスを共有するのと同様に)同じ
 * `WPCV_Run_Repository` インスタンスで差し替える必要がある(`wpcv_test_make_fake_environment()`
 * の docblock 参照).
 */
class CliCommandTest extends TestCase {

	/**
	 * ファイル読み込み時(`require_once` 直後、いずれかのテストの `setUp()` が
	 * `$GLOBALS` を掃除するより前)に記録された `WP_CLI::add_command()` の呼び出し引数.
	 *
	 * @var array|false
	 */
	private static $registered_command;

	/**
	 * クラス内の全テストに先立って一度だけ実行する.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$registered_command = end( $GLOBALS['_wpcv_test_wp_cli_calls']['add_command'] );
	}

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_wp_cli_calls'], $GLOBALS['_wpcv_test_bloginfo'], $GLOBALS['_wpcv_test_plugins'], $GLOBALS['_wpcv_test_mu_plugins'], $GLOBALS['_wpcv_test_as_enqueue_calls'], $GLOBALS['_wpcv_test_action_scheduler_initialized'] );
		wpcv_test_inject_run_coordinator();
		wpcv_test_inject_run_repository();
	}

	/**
	 * 各テストの後、次のテストに影響しないよう `WPCV_Plugin` のキャッシュを戻す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wpcv_test_inject_run_coordinator();
		wpcv_test_inject_run_repository();
		parent::tearDown();
	}

	/**
	 * 正常系: run が完走し、`WP_CLI::success()` が呼ばれることを確認する.
	 *
	 * @return void
	 */
	public function test_invoke_runs_verification_and_reports_success() {
		$GLOBALS['_wpcv_test_bloginfo'] = array( 'version' => '6.8' );
		$made                           = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_run_repository( $made['run_repository'] );

		$command = new WPCV_CLI_Command();
		$command->__invoke( array(), array() );

		$this->assertArrayNotHasKey( 'error', $GLOBALS['_wpcv_test_wp_cli_calls'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_cli_calls']['success'] );
		$this->assertStringContainsString( 'run #1', $GLOBALS['_wpcv_test_wp_cli_calls']['success'][0] );
		$this->assertStringContainsString( 'success', $GLOBALS['_wpcv_test_wp_cli_calls']['success'][0] );
	}

	/**
	 * version が取得できない(空文字)場合、`WPCV_Run_Coordinator` が投げる
	 * `InvalidArgumentException` が `WP_CLI::error()` に変換されることを確認する.
	 *
	 * @return void
	 */
	public function test_invoke_converts_invalid_argument_exception_to_wp_cli_error() {
		// get_bloginfo('version') のスタブを未設定のままにし、空文字を返させる.
		$made = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_run_repository( $made['run_repository'] );

		$command = new WPCV_CLI_Command();
		$command->__invoke( array(), array() );

		$this->assertArrayNotHasKey( 'success', $GLOBALS['_wpcv_test_wp_cli_calls'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_cli_calls']['error'] );
		$this->assertStringContainsString( 'requires', $GLOBALS['_wpcv_test_wp_cli_calls']['error'][0] );
		$this->assertStringContainsString( 'version', $GLOBALS['_wpcv_test_wp_cli_calls']['error'][0] );
	}

	/**
	 * 既に active(running)な run がある場合、検証を実行せず `WP_CLI::error()` を
	 * 呼ぶことを確認する(v0.3.1 §Step1: 同期 CLI も `reserve_run()` を通す
	 * ようになったことで、同時実行の防止が効くようになったことの確認).
	 *
	 * @return void
	 */
	public function test_invoke_reports_error_when_run_already_active() {
		$made = wpcv_test_make_fake_environment();
		$made['run_repository']->reserve_run();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_run_repository( $made['run_repository'] );

		$command = new WPCV_CLI_Command();
		$command->__invoke( array(), array() );

		$this->assertArrayNotHasKey( 'success', $GLOBALS['_wpcv_test_wp_cli_calls'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_cli_calls']['error'] );
		$this->assertStringContainsString( 'run #1', $GLOBALS['_wpcv_test_wp_cli_calls']['error'][0] );
	}

	/**
	 * `--async` 指定時、`WPCV_Runner_Async::enqueue_run()` 経由で enqueue され、
	 * `WP_CLI::success()` に action_id を含むメッセージが渡されることを確認する
	 * (`ActionScheduler::is_initialized()` のスタブを真にして「利用可能」側の
	 * 分岐を模す。v0.3.1 §Step2で既定可用性チェックがこれも見るようになった
	 * ため明示的に設定する必要がある。`as_enqueue_async_action()` 自体は
	 * `wp-stubs.php` に常設のスタブがある).
	 *
	 * @return void
	 */
	public function test_invoke_with_async_flag_enqueues_via_runner_async() {
		$GLOBALS['_wpcv_test_action_scheduler_initialized'] = true;
		$made                                               = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_repository( $made['run_repository'] );

		$command = new WPCV_CLI_Command();
		$command->__invoke( array(), array( 'async' => true ) );

		$this->assertCount( 1, $GLOBALS['_wpcv_test_as_enqueue_calls'] );
		list( $hook, $args ) = $GLOBALS['_wpcv_test_as_enqueue_calls'][0];
		$this->assertSame( WPCV_Runner_Async::HOOK, $hook );
		$this->assertSame( array( 1, 'cli' ), $args );

		$this->assertArrayNotHasKey( 'error', $GLOBALS['_wpcv_test_wp_cli_calls'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_cli_calls']['success'] );
		$this->assertStringContainsString( 'action_id', $GLOBALS['_wpcv_test_wp_cli_calls']['success'][0] );
	}

	/**
	 * `format_result()` が summary を1行の要約文字列に整形することを確認する.
	 *
	 * @return void
	 */
	public function test_format_result_summarizes_counts() {
		$formatted = WPCV_CLI_Command::format_result(
			array(
				'run_id'  => 1,
				'summary' => array(
					'status'               => 'partial',
					'targets_total'        => 3,
					'targets_verified'     => 1,
					'targets_unverifiable' => 1,
					'targets_failed'       => 1,
					'findings_total'       => 5,
				),
			)
		);

		$this->assertSame( 'targets: 3 total / 1 verified / 1 unverifiable / 1 failed, findings: 5', $formatted );
	}

	/**
	 * ファイル読み込み時、`WP_CLI::add_command()` が正しい引数で呼ばれることを確認する
	 * (`class_exists( 'WP_CLI' )` を満たす本テストのスタブ環境下での確認).
	 *
	 * @return void
	 */
	public function test_command_registers_itself_with_wp_cli_on_require() {
		$this->assertNotFalse( self::$registered_command );
		$this->assertSame( 'wpcv run', self::$registered_command[0] );
		$this->assertSame( 'WPCV_CLI_Command', self::$registered_command[1] );
	}
}
