<?php
/**
 * WPCV_Run_Coordinator のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-cursor.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-verifier.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-chunk-result-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-chunk-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-starter.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Run_Coordinator::run()`(v0.4.0 §Step5でchunk dispatcherを完走まで
 * ループするadapterへ書き換え済み)のテスト.
 *
 * 個々のchunk比較・claim・lease・retryの正しさは `ChunkVerifierTest`/
 * `ChunkDispatcherTest`/各Repositoryのテストで検証済みのため、ここでは
 * 「plan→保存→dispatchループ→summary」というorchestration自体が正しく
 * 完走することの確認に絞る.
 *
 * ABSPATH(tests/fixtures/fake-root/)配下に実ファイルを作って検証する。
 * 掃除は「ABSPATH 直下を .gitkeep 以外すべて削除」方式(VerifierTest 等と同じ).
 */
class RunCoordinatorTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
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
	 * ABSPATH 直下を `.gitkeep` 以外すべて削除する.
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
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( scandir( $path ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$this->remove_path( $path . '/' . $entry );
			}
			rmdir( $path );
			return;
		}

		if ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * ABSPATH 相対パスを指定してテスト用ファイルを作る.
	 *
	 * @param string $relative_path ABSPATH 相対パス.
	 * @param string $content       ファイルの中身.
	 * @return void
	 */
	private function put_fixture_file( $relative_path, $content = '' ) {
		$absolute_path = ABSPATH . $relative_path;
		$dir           = dirname( $absolute_path );

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		file_put_contents( $absolute_path, $content );
	}

	/**
	 * `wpcv_test_make_fake_environment()` で組み立てた環境で run を予約し、
	 * `coordinator->run()` を呼ぶ.
	 *
	 * @param array $made    `wpcv_test_make_fake_environment()` の戻り値.
	 * @param array $context `run()` に渡す `$context`.
	 * @return array `run()` の戻り値.
	 */
	private function reserve_and_run( array $made, array $context ) {
		$run_id = $made['run_repository']->reserve_run()['run_id'];

		return $made['coordinator']->run( $run_id, $context );
	}

	/**
	 * コアのみ(プラグイン・MU プラグイン無し)で run が完走し、
	 * runs/target_runs テーブルに `core`/`core:_scan` の2件が保存されることを確認する.
	 *
	 * @return void
	 */
	public function test_run_persists_core_only_run() {
		$made   = wpcv_test_make_fake_environment();
		$result = $this->reserve_and_run( $made, array( 'version' => '6.8' ) );

		$this->assertSame( 1, $result['run_id'] );
		$this->assertSame( 'success', $result['summary']['status'] );
		$this->assertSame( 2, $result['summary']['targets_total'] );

		$run_row = $made['wpdb']->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'success', $run_row['status'] );

		$target_ids = array_column( $made['wpdb']->rows['wp_wpcv_target_runs'], 'target_id' );
		$this->assertContains( 'core', $target_ids );
		$this->assertContains( 'core:_scan', $target_ids );
	}

	/**
	 * ディレクトリ型プラグイン(`{slug}/{file}.php`)の slug が
	 * ディレクトリ名から正しく解決され、run完走まで到達することを確認する.
	 *
	 * @return void
	 */
	public function test_run_resolves_directory_style_plugin_slug() {
		$this->put_fixture_file( 'wp-content/plugins/akismet/akismet.php', 'main' );

		$plugin_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array( 'akismet.php' => array( 'algorithm' => 'sha256', 'hashes' => array( hash( 'sha256', 'main' ) ) ) ),
			)
		);

		$made   = wpcv_test_make_fake_environment( null, $plugin_source );
		$result = $this->reserve_and_run(
			$made,
			array(
				'version'    => '6.8',
				'plugins'    => array(
					'akismet/akismet.php' => array( 'Version' => '5.3' ),
				),
				'plugin_dir' => ABSPATH . 'wp-content/plugins',
			)
		);

		// core + core:_scan + plugin:akismet の3件.
		$this->assertSame( 3, $result['summary']['targets_total'] );
		$this->assertSame( 'success', $result['summary']['status'] );

		$plugin_row = null;
		foreach ( $made['wpdb']->rows['wp_wpcv_target_runs'] as $row ) {
			if ( 'plugin:akismet' === $row['target_id'] ) {
				$plugin_row = $row;
			}
		}

		$this->assertNotNull( $plugin_row );
		$this->assertSame( 'akismet', $plugin_row['slug'] );
		$this->assertSame( '5.3', $plugin_row['version'] );
		$this->assertSame( 'success', $plugin_row['status'] );
	}

	/**
	 * `hello.php` はコアの checksums に含まれる(§3.2)ため、
	 * プラグイン次元では二重に検証されない(target_run が作られない)ことを確認する.
	 *
	 * @return void
	 */
	public function test_run_skips_hello_php_as_plugin_target() {
		$made   = wpcv_test_make_fake_environment();
		$result = $this->reserve_and_run(
			$made,
			array(
				'version'    => '6.8',
				'plugins'    => array(
					'hello.php' => array( 'Version' => '1.7.2' ),
				),
				'plugin_dir' => ABSPATH . 'wp-content/plugins',
			)
		);

		// core + core:_scan のみ(hello.php 分の target_run は増えない).
		$this->assertSame( 2, $result['summary']['targets_total'] );
	}

	/**
	 * plugins が空でないのに plugin_dir が指定されていない場合、
	 * `WPCV_Run_Planner::plan()` が投げる例外がそのまま伝播することを確認する.
	 *
	 * @return void
	 */
	public function test_run_throws_when_plugin_dir_missing() {
		$this->expectException( InvalidArgumentException::class );

		$made = wpcv_test_make_fake_environment();
		$this->reserve_and_run(
			$made,
			array(
				'version' => '6.8',
				'plugins' => array( 'akismet/akismet.php' => array() ),
			)
		);
	}

	/**
	 * mu_plugin_dir を渡すと MU プラグイン領域も検証され、
	 * loader の target_run と合成 target `muplugin:_scan` が保存されることを確認する.
	 *
	 * @return void
	 */
	public function test_run_verifies_muplugin_area_when_dir_given() {
		$this->put_fixture_file( 'wp-content/mu-plugins/loader.php', 'loader' );
		$this->put_fixture_file( 'wp-content/mu-plugins/vendor/backdoor.php', 'backdoor' );

		$made   = wpcv_test_make_fake_environment();
		$result = $this->reserve_and_run(
			$made,
			array(
				'version'       => '6.8',
				'mu_plugin_dir' => ABSPATH . 'wp-content/mu-plugins',
				'mu_plugins'    => array( 'loader.php' => array() ),
			)
		);

		// core + core:_scan + loader + muplugin:_scan の4件.
		$this->assertSame( 4, $result['summary']['targets_total'] );

		$target_ids = array_column( $made['wpdb']->rows['wp_wpcv_target_runs'], 'target_id' );
		$this->assertContains( 'muplugin:loader.php', $target_ids );
		$this->assertContains( 'muplugin:_scan', $target_ids );

		$finding_paths = array_column( $made['wpdb']->rows['wp_wpcv_findings'], 'path' );
		$this->assertContains( 'wp-content/mu-plugins/vendor/backdoor.php', $finding_paths );
	}

	/**
	 * version が指定されていない場合、`WPCV_Run_Planner::plan()` が投げる例外が
	 * そのまま伝播することを確認する.
	 *
	 * @return void
	 */
	public function test_run_throws_when_version_missing() {
		$this->expectException( InvalidArgumentException::class );

		$made = wpcv_test_make_fake_environment();
		$this->reserve_and_run( $made, array() );
	}

	/**
	 * バリデーション例外(version 未指定)発生時、予約済み run が `mark_run_failed()`
	 * により failed 化されることを確認する(`WPCV_Run_Starter::plan_and_save()` の
	 * 責務。v0.3.1 §Step1由来の「途中の例外で失敗記録が残らない」への対策が
	 * chunk dispatcherベースへの書き換え後も維持されていることの確認).
	 *
	 * @return void
	 */
	public function test_run_marks_run_failed_when_version_missing() {
		$made   = wpcv_test_make_fake_environment();
		$run_id = $made['run_repository']->reserve_run()['run_id'];

		try {
			$made['coordinator']->run( $run_id, array() );
			$this->fail( 'InvalidArgumentException を期待していたが投げられなかった.' );
		} catch ( InvalidArgumentException $e ) {
			unset( $e );
		}

		$row = $made['wpdb']->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringContainsString( 'InvalidArgumentException', $row['notes'] );
	}

	/**
	 * Manifest取得時に例外を投げるソースがあっても、そのtargetだけが `failed` に
	 * なり、run全体は例外を投げずに完走(`partial`)することを確認する
	 * (v0.4.0 §Step5: 個別targetの処理失敗はrun全体を止めない、という
	 * `WPCV_Chunk_Dispatcher::dispatch()` の設計がCoordinator経由でも有効なことの
	 * 確認。旧`WPCV_Run_Coordinator`〔v0.3.1まで〕はrun全体を即failedにしていたが、
	 * これは意図的な仕様変更).
	 *
	 * @return void
	 */
	public function test_run_marks_only_failing_target_and_still_finalizes_as_partial() {
		$throwing_source = new class() implements WPCV_Manifest_Source {
			/**
			 * 呼ばれたら必ず例外を投げる(検証中の想定外エラーを模す).
			 *
			 * @param array $context 無視する.
			 * @return never
			 * @throws RuntimeException 常に投げる.
			 */
			public function get_manifest( array $context ) {
				unset( $context );
				throw new RuntimeException( 'checksums API unreachable' );
			}
		};

		$made   = wpcv_test_make_fake_environment( $throwing_source );
		$result = $this->reserve_and_run( $made, array( 'version' => '6.8' ) );

		$this->assertSame( 'partial', $result['summary']['status'] );

		$core_row = null;
		foreach ( $made['wpdb']->rows['wp_wpcv_target_runs'] as $row ) {
			if ( 'core' === $row['target_id'] ) {
				$core_row = $row;
			}
		}

		$this->assertNotNull( $core_row );
		$this->assertSame( 'failed', $core_row['status'] );
		$this->assertStringContainsString( 'checksums API unreachable', $core_row['error_message'] );

		// run自体は失敗記録されず、完走(success|partial)していることを確認する.
		$run_row = $made['wpdb']->rows['wp_wpcv_runs'][ $result['run_id'] ];
		$this->assertNotSame( 'failed', $run_row['status'] );
	}
}
