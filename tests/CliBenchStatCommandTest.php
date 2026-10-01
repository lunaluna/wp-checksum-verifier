<?php
/**
 * WPCV_CLI_Bench_Stat_Command のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-budget.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-stat-bench.php';
require_once dirname( __DIR__ ) . '/includes/cli/class-wpcv-cli-bench-stat-command.php';

use PHPUnit\Framework\TestCase;

/**
 * `wp wpcv bench-stat` の実体である `WPCV_CLI_Bench_Stat_Command::__invoke()` の
 * テスト(v0.5 §4.2 Step4)。実際の走査は `ABSPATH`(テスト用fixture-root)配下の
 * ディレクトリに対して行う(このコマンドはDBに一切触れないため、他のCLIコマンド
 * テストのような Repository 差し替えは不要).
 */
class CliBenchStatCommandTest extends TestCase {

	/**
	 * 各テストの前に `WP_CLI` スタブの呼び出し記録のうち、このテストが検証する
	 * `error`/`line` だけを空にする.
	 *
	 * `add_command` キーは意図的に触らない。`CliCommandTest::setUpBeforeClass()` が
	 * 「ファイル読み込み時点で記録された `add_command` 呼び出し」を later に参照する
	 * 設計になっており、ここで `$GLOBALS['_wpcv_test_wp_cli_calls']` 全体を unset
	 * すると(PHPUnitがテストスイート構築時に全テストファイルを一度requireするため、
	 * `add_command` の記録は実際のテスト実行より前に作られている)、実行順序次第で
	 * `CliCommandTest` 側が `end( null )` で壊れる(実際に踏んだ不具合).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_wp_cli_calls']['error'], $GLOBALS['_wpcv_test_wp_cli_calls']['line'] );
		$this->clean_fixtures();
	}

	/**
	 * 各テストの後に作成したフィクスチャを掃除する.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->clean_fixtures();
		parent::tearDown();
	}

	/**
	 * ABSPATH 直下を `.gitkeep` 以外すべて削除する
	 * (`UnknownFileScannerTest`/`StatBenchTest` と同じ理由).
	 *
	 * @return void
	 */
	private function clean_fixtures() {
		foreach ( scandir( ABSPATH ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || '.gitkeep' === $entry ) {
				continue;
			}
			$this->remove_path( ABSPATH . $entry );
		}
	}

	/**
	 * ファイル・ディレクトリを再帰的に削除する.
	 *
	 * @param string $path 絶対パス.
	 * @return void
	 */
	private function remove_path( $path ) {
		if ( is_dir( $path ) ) {
			foreach ( scandir( $path ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$this->remove_path( $path . '/' . $entry );
			}
			rmdir( $path );
			return;
		}

		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * `--dir` を指定しなかった場合、`WP_CLI::error()` で拒否され走査を行わない
	 * ことを確認する.
	 *
	 * @return void
	 */
	public function test_invoke_errors_when_dir_is_missing() {
		$command = new WPCV_CLI_Bench_Stat_Command();
		$command->__invoke( array(), array() );

		$this->assertNotEmpty( $GLOBALS['_wpcv_test_wp_cli_calls']['error'] );
		$this->assertStringContainsString( '--dir', $GLOBALS['_wpcv_test_wp_cli_calls']['error'][0] );
	}

	/**
	 * 存在しないディレクトリを指定した場合、`WP_CLI::error()` で拒否されることを確認する.
	 *
	 * @return void
	 */
	public function test_invoke_errors_when_dir_does_not_exist() {
		$command = new WPCV_CLI_Bench_Stat_Command();
		$command->__invoke( array(), array( 'dir' => ABSPATH . 'no-such-directory' ) );

		$this->assertNotEmpty( $GLOBALS['_wpcv_test_wp_cli_calls']['error'] );
	}

	/**
	 * 存在するディレクトリを指定すると、iterationごとの行と3回呼び比較の行が
	 * `WP_CLI::line()` で出力されることを確認する.
	 *
	 * @return void
	 */
	public function test_invoke_reports_lines_for_valid_directory() {
		mkdir( ABSPATH . 'wp-admin', 0777, true );
		file_put_contents( ABSPATH . 'wp-admin/a.php', 'x' );

		$command = new WPCV_CLI_Bench_Stat_Command();
		$command->__invoke( array(), array( 'dir' => ABSPATH . 'wp-admin', 'iterations' => 2 ) );

		$this->assertArrayNotHasKey( 'error', $GLOBALS['_wpcv_test_wp_cli_calls'] );

		$lines = $GLOBALS['_wpcv_test_wp_cli_calls']['line'];

		// iteration見出し2件(iterations=2) + iterationごとのcold/warm行2件ずつ +
		// 3回呼び比較の見出し1件 + cold/warm行2件、の合計行数を確認する.
		$this->assertSame(
			array( '--- iteration 1 ---', '--- iteration 2 ---' ),
			array_values(
				array_filter(
					$lines,
					static function ( $line ) {
						return 0 === strpos( $line, '--- iteration' );
					}
				)
			)
		);
		$this->assertContains( '--- lstat() 1回 vs filesize()+filectime()+filemtime() 3回呼びの比較(ファイル一覧確保後の関数呼び出しのみ) ---', $lines );
	}

	/**
	 * `--hash` を指定しない場合、content-hash行が出力されないことを確認する
	 * (v0.6 §Step8. 既定ではハッシュ計算を行わない).
	 *
	 * @return void
	 */
	public function test_invoke_omits_content_hash_lines_without_hash_flag() {
		mkdir( ABSPATH . 'wp-admin', 0777, true );
		file_put_contents( ABSPATH . 'wp-admin/a.php', 'x' );

		$command = new WPCV_CLI_Bench_Stat_Command();
		$command->__invoke( array(), array( 'dir' => ABSPATH . 'wp-admin' ) );

		$lines = $GLOBALS['_wpcv_test_wp_cli_calls']['line'];

		$this->assertSame( array(), array_values( array_filter( $lines, static function ( $line ) {
			return false !== strpos( $line, 'content-hash' );
		} ) ) );
	}

	/**
	 * `--hash` を指定すると、iterationごとにcontent-hash(cold/warm)の行が
	 * 出力されることを確認する(v0.6 §Step8. 層2の実測).
	 *
	 * @return void
	 */
	public function test_invoke_reports_content_hash_lines_with_hash_flag() {
		mkdir( ABSPATH . 'wp-admin', 0777, true );
		file_put_contents( ABSPATH . 'wp-admin/a.php', 'x' );

		$command = new WPCV_CLI_Bench_Stat_Command();
		$command->__invoke( array(), array( 'dir' => ABSPATH . 'wp-admin', 'hash' => true ) );

		$this->assertArrayNotHasKey( 'error', $GLOBALS['_wpcv_test_wp_cli_calls'] );

		$lines = $GLOBALS['_wpcv_test_wp_cli_calls']['line'];

		$this->assertNotEmpty(
			array_filter(
				$lines,
				static function ( $line ) {
					return 0 === strpos( $line, 'content-hash sha256 (cold)' );
				}
			)
		);
		$this->assertNotEmpty(
			array_filter(
				$lines,
				static function ( $line ) {
					return 0 === strpos( $line, 'content-hash sha256 (warm)' );
				}
			)
		);
	}

	/**
	 * `report()` が entries/seconds/bytes を含む行を整形して出力することを確認する
	 * (`__invoke()` から分離した静的メソッドの単体テスト).
	 *
	 * @return void
	 */
	public function test_report_formats_stats_lines() {
		WPCV_CLI_Bench_Stat_Command::report(
			array(
				'runs'             => array(
					array(
						'iteration'  => 1,
						'lstat_cold' => array(
							'entries'         => 10,
							'seconds'         => 0.5,
							'bytes'           => 1000,
							'entries_per_sec' => 20.0,
							'bytes_per_sec'   => 2000.0,
						),
						'lstat_warm' => array(
							'entries'         => 10,
							'seconds'         => 0.1,
							'bytes'           => 1000,
							'entries_per_sec' => 100.0,
							'bytes_per_sec'   => 10000.0,
						),
					),
				),
				'lstat_only_cold'  => array(
					'entries'         => 10,
					'seconds'         => 0.6,
					'bytes'           => 1000,
					'entries_per_sec' => 16.7,
					'bytes_per_sec'   => 1666.7,
				),
				'lstat_only_warm'  => array(
					'entries'         => 10,
					'seconds'         => 0.2,
					'bytes'           => 1000,
					'entries_per_sec' => 50.0,
					'bytes_per_sec'   => 5000.0,
				),
				'triple_call_cold' => array(
					'entries'         => 10,
					'seconds'         => 0.15,
					'bytes'           => 1000,
					'entries_per_sec' => 66.7,
					'bytes_per_sec'   => 6666.7,
				),
				'triple_call_warm' => array(
					'entries'         => 10,
					'seconds'         => 0.05,
					'bytes'           => 1000,
					'entries_per_sec' => 200.0,
					'bytes_per_sec'   => 20000.0,
				),
			)
		);

		$lines = $GLOBALS['_wpcv_test_wp_cli_calls']['line'];

		$this->assertStringContainsString( 'lstat (cold): entries=10', $lines[2] );
		$this->assertStringContainsString( 'lstat (warm): entries=10', $lines[3] );
		$this->assertStringContainsString( 'lstat-only (cold): entries=10', $lines[5] );
		$this->assertStringContainsString( 'lstat-only (warm): entries=10', $lines[6] );
		$this->assertStringContainsString( 'triple-call (cold): entries=10', $lines[7] );
		$this->assertStringContainsString( 'triple-call (warm): entries=10', $lines[8] );
	}

	/**
	 * `report()` が `content_hash_cold`/`content_hash_warm` を含む run に対して
	 * それぞれの行を出力することを確認する(v0.6 §Step8).
	 *
	 * @return void
	 */
	public function test_report_includes_content_hash_lines_when_present() {
		$stats = array(
			'entries'         => 5,
			'seconds'         => 0.3,
			'bytes'           => 500,
			'entries_per_sec' => 16.7,
			'bytes_per_sec'   => 1666.7,
		);

		WPCV_CLI_Bench_Stat_Command::report(
			array(
				'runs'             => array(
					array(
						'iteration'         => 1,
						'lstat_cold'        => $stats,
						'lstat_warm'        => $stats,
						'content_hash_cold' => $stats,
						'content_hash_warm' => $stats,
					),
				),
				'lstat_only_cold'  => $stats,
				'lstat_only_warm'  => $stats,
				'triple_call_cold' => $stats,
				'triple_call_warm' => $stats,
			)
		);

		$lines = $GLOBALS['_wpcv_test_wp_cli_calls']['line'];

		$this->assertNotEmpty(
			array_filter(
				$lines,
				static function ( $line ) {
					return 0 === strpos( $line, 'content-hash sha256 (cold): entries=5' );
				}
			)
		);
		$this->assertNotEmpty(
			array_filter(
				$lines,
				static function ( $line ) {
					return 0 === strpos( $line, 'content-hash sha256 (warm): entries=5' );
				}
			)
		);
	}
}
