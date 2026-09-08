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
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-context-builder.php';
require_once dirname( __DIR__ ) . '/includes/cli/class-wpcv-cli-command.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `wp wpcv run` の実体である `WPCV_CLI_Command::__invoke()` のテスト.
 *
 * 実際の `WPCV_Plugin::run_coordinator()`(composition root)は `global $wpdb`
 * を必要とするため、ここでは `RunCoordinatorTest` と同じ手書きテストダブルで
 * 組み立てた `WPCV_Run_Coordinator` を `WPCV_Plugin` のキャッシュに直接差し込んで
 * 使う(private static プロパティへのリフレクション。composition root 自体は
 * `PluginTest` で別途検証済み).
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
		unset( $GLOBALS['_wpcv_test_wp_cli_calls'], $GLOBALS['_wpcv_test_bloginfo'], $GLOBALS['_wpcv_test_plugins'], $GLOBALS['_wpcv_test_mu_plugins'] );
		$this->reset_plugin_run_coordinator_cache();
	}

	/**
	 * 各テストの後、次のテストに影響しないよう `WPCV_Plugin` のキャッシュを戻す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->reset_plugin_run_coordinator_cache();
		parent::tearDown();
	}

	/**
	 * `WPCV_Plugin::$run_coordinator` を null に戻す.
	 *
	 * @return void
	 */
	private function reset_plugin_run_coordinator_cache() {
		$property = new ReflectionProperty( WPCV_Plugin::class, 'run_coordinator' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}

	/**
	 * `WPCV_Plugin::run_coordinator()` が返すインスタンスを、テストダブルで
	 * 組み立てたものに差し替える.
	 *
	 * @return void
	 */
	private function inject_fake_run_coordinator() {
		$core_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array(),
			)
		);

		$verifier = new WPCV_Verifier(
			$core_source,
			new WPCV_Test_Fake_Manifest_Source(
				array(
					'manifest_status' => 'missing',
					'error_code'      => WPCV_Error_Code::MANIFEST_NOT_FOUND,
					'files'           => array(),
				)
			),
			new WPCV_Unknown_File_Scanner()
		);

		$repository  = new WPCV_Repository(
			new WPCV_Test_Fake_WPDB(),
			static function () {
				return '2026-09-08 12:00:00';
			}
		);
		$coordinator = new WPCV_Run_Coordinator( $verifier, $repository );

		$property = new ReflectionProperty( WPCV_Plugin::class, 'run_coordinator' );
		$property->setAccessible( true );
		$property->setValue( null, $coordinator );
	}

	/**
	 * 正常系: run が完走し、`WP_CLI::success()` が呼ばれることを確認する.
	 *
	 * @return void
	 */
	public function test_invoke_runs_verification_and_reports_success() {
		$GLOBALS['_wpcv_test_bloginfo'] = array( 'version' => '6.8' );
		$this->inject_fake_run_coordinator();

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
		$this->inject_fake_run_coordinator();

		$command = new WPCV_CLI_Command();
		$command->__invoke( array(), array() );

		$this->assertArrayNotHasKey( 'success', $GLOBALS['_wpcv_test_wp_cli_calls'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_cli_calls']['error'] );
		$this->assertStringContainsString( 'requires', $GLOBALS['_wpcv_test_wp_cli_calls']['error'][0] );
		$this->assertStringContainsString( 'version', $GLOBALS['_wpcv_test_wp_cli_calls']['error'][0] );
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
