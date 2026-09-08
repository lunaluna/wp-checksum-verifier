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
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * §4.2(runners/class-wpcv-run-coordinator.php)、`WPCV_Verifier` と
 * `WPCV_Repository` を結び付けて1回分の run を成立させるオーケストレーションの
 * テスト.
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
	 * 空の(常に成功する)コアマニフェストを持つ Coordinator を組み立てる.
	 *
	 * fake-root/.gitkeep をコアの既知ファイルに含めておく(ABSPATH 直下の
	 * 未知ファイル走査に拾われないようにするため。UnknownFileScannerTest 等と同じ対策).
	 *
	 * @param WPCV_Manifest_Source|null $plugin_source 省略時は空マニフェストの fake.
	 * @return array{coordinator: WPCV_Run_Coordinator, wpdb: WPCV_Test_Fake_WPDB}
	 */
	private function make_coordinator( $plugin_source = null ) {
		$core_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array( '.gitkeep' => array( 'algorithm' => 'sha256', 'hashes' => array( hash( 'sha256', '' ) ) ) ),
			)
		);

		$empty_plugin_manifest = array(
			'manifest_status' => 'missing',
			'error_code'      => WPCV_Error_Code::MANIFEST_NOT_FOUND,
			'files'           => array(),
		);

		$verifier = new WPCV_Verifier(
			$core_source,
			$plugin_source ?? new WPCV_Test_Fake_Manifest_Source( $empty_plugin_manifest ),
			new WPCV_Unknown_File_Scanner()
		);

		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Repository(
			$wpdb,
			static function () {
				return '2026-09-08 12:00:00';
			}
		);

		return array(
			'coordinator' => new WPCV_Run_Coordinator( $verifier, $repository ),
			'wpdb'        => $wpdb,
		);
	}

	/**
	 * コアのみ(プラグイン・MU プラグイン無し)で run が成立し、
	 * runs/target_runs テーブルに保存されることを確認する.
	 *
	 * @return void
	 */
	public function test_run_persists_core_only_run() {
		$made   = $this->make_coordinator();
		$result = $made['coordinator']->run( array( 'version' => '6.8' ) );

		$this->assertSame( 1, $result['run_id'] );
		$this->assertSame( 'success', $result['summary']['status'] );
		$this->assertSame( 1, $result['summary']['targets_total'] );

		$run_row = $made['wpdb']->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'success', $run_row['status'] );
		$this->assertSame( '2026-09-08 12:00:00', $run_row['finished_at'] );

		$target_run_rows = $made['wpdb']->rows['wp_wpcv_target_runs'];
		$this->assertCount( 1, $target_run_rows );
		$this->assertSame( 'core', $target_run_rows[1]['target_id'] );
	}

	/**
	 * ディレクトリ型プラグイン(`{slug}/{file}.php`)の slug が
	 * ディレクトリ名から正しく解決されることを確認する.
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

		$made   = $this->make_coordinator( $plugin_source );
		$result = $made['coordinator']->run(
			array(
				'version'    => '6.8',
				'plugins'    => array(
					'akismet/akismet.php' => array( 'Version' => '5.3' ),
				),
				'plugin_dir' => ABSPATH . 'wp-content/plugins',
			)
		);

		$this->assertSame( 2, $result['summary']['targets_total'] );

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
	 * 単一ファイルプラグイン(スラッシュを含まないキー)の slug が、
	 * ファイル名から拡張子を除いたものになることを確認する(§3.4 のベストエフォート方針).
	 *
	 * @return void
	 */
	public function test_run_resolves_single_file_plugin_slug_from_filename() {
		$plugin_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'missing',
				'error_code'      => WPCV_Error_Code::MANIFEST_NOT_FOUND,
				'files'           => array(),
			)
		);

		$made   = $this->make_coordinator( $plugin_source );
		$result = $made['coordinator']->run(
			array(
				'version'    => '6.8',
				'plugins'    => array(
					'my-single-file-plugin.php' => array( 'Version' => '1.0' ),
				),
				'plugin_dir' => ABSPATH . 'wp-content/plugins',
			)
		);

		unset( $result );

		$plugin_row = null;
		foreach ( $made['wpdb']->rows['wp_wpcv_target_runs'] as $row ) {
			if ( 'plugin:my-single-file-plugin' === $row['target_id'] ) {
				$plugin_row = $row;
			}
		}

		$this->assertNotNull( $plugin_row );
		$this->assertSame( 'my-single-file-plugin', $plugin_row['slug'] );
	}

	/**
	 * `hello.php` はコアの checksums に含まれる(§3.2)ため、
	 * プラグイン次元では二重に検証されない(target_run が作られない)ことを確認する.
	 *
	 * @return void
	 */
	public function test_run_skips_hello_php_as_plugin_target() {
		$made   = $this->make_coordinator();
		$result = $made['coordinator']->run(
			array(
				'version'    => '6.8',
				'plugins'    => array(
					'hello.php' => array( 'Version' => '1.7.2' ),
				),
				'plugin_dir' => ABSPATH . 'wp-content/plugins',
			)
		);

		// core のみ(hello.php 分の target_run は増えない)ことを確認する.
		$this->assertSame( 1, $result['summary']['targets_total'] );
	}

	/**
	 * plugins が空でないのに plugin_dir が指定されていない場合、例外を投げることを確認する.
	 *
	 * @return void
	 */
	public function test_run_throws_when_plugin_dir_missing() {
		$this->expectException( InvalidArgumentException::class );

		$made = $this->make_coordinator();
		$made['coordinator']->run(
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

		$made   = $this->make_coordinator();
		$result = $made['coordinator']->run(
			array(
				'version'       => '6.8',
				'mu_plugin_dir' => ABSPATH . 'wp-content/mu-plugins',
				'mu_plugins'    => array( 'loader.php' => array() ),
			)
		);

		// core + loader + muplugin:_scan の3件.
		$this->assertSame( 3, $result['summary']['targets_total'] );

		$target_ids = array_column( $made['wpdb']->rows['wp_wpcv_target_runs'], 'target_id' );
		$this->assertContains( 'muplugin:loader.php', $target_ids );
		$this->assertContains( 'muplugin:_scan', $target_ids );

		$finding_paths = array_column( $made['wpdb']->rows['wp_wpcv_findings'], 'path' );
		$this->assertContains( 'wp-content/mu-plugins/vendor/backdoor.php', $finding_paths );
	}

	/**
	 * mu_plugin_dir を渡さない場合、MU プラグイン領域の検証はスキップされることを確認する.
	 *
	 * @return void
	 */
	public function test_run_skips_muplugin_area_when_dir_absent() {
		$made   = $this->make_coordinator();
		$result = $made['coordinator']->run( array( 'version' => '6.8' ) );

		$this->assertSame( 1, $result['summary']['targets_total'] );
		$this->assertArrayNotHasKey( 'wp_wpcv_findings', $made['wpdb']->rows );
	}

	/**
	 * version が指定されていない場合に例外を投げることを確認する.
	 *
	 * @return void
	 */
	public function test_run_throws_when_version_missing() {
		$this->expectException( InvalidArgumentException::class );

		$made = $this->make_coordinator();
		$made['coordinator']->run( array() );
	}
}
