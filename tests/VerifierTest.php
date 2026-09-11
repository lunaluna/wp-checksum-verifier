<?php
/**
 * WPCV_Verifier のテスト.
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
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Verifier` static ユーティリティ(§5.2 の run 集計ロジック・
 * `compare_one_file()`/`make_finding_for_unknown_file()` の finding 組み立て・
 * §3.3/§3.6 の共有ヘルパー)のテスト.
 *
 * v0.4.0 §Step5で `verify_core()`/`verify_plugin()`/`verify_muplugin_area()`
 * (§3.2/§3.4/§3.6のマニフェスト取得+ファイル比較を1メソッド内で行っていた
 * 一括版)を削除した(唯一の呼び出し元だった旧`WPCV_Run_Coordinator`が
 * chunk dispatcherベースへ書き換わったため。`WPCV_Verifier` のクラス docblock
 * 参照)。これに伴い、それらのオーケストレーションを検証していたテストは
 * `ChunkVerifierTest`/`ChunkDispatcherTest`(chunk単位の比較・走査ロジック)と
 * `RunCoordinatorTest`(orchestration全体の結合)に引き継がれており、
 * ここでは残った static ユーティリティのみを対象にする。
 *
 * `tests/fixtures/fake-root/`(ABSPATH)配下に実ファイルを作って検証する。
 * 掃除は「ABSPATH 直下を .gitkeep 以外すべて削除」方式(UnknownFileScannerTest と同じ).
 */
class VerifierTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clean_fixtures();
		unset( $GLOBALS['_wpcv_test_filters'] );
	}

	/**
	 * 各テストの後に作成したフィクスチャを掃除する.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->clean_fixtures();
		unset( $GLOBALS['_wpcv_test_filters'] );
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
	 * 指定した内容の sha256 マニフェストエントリを組み立てる.
	 *
	 * @param string $content マニフェストが期待するファイル内容.
	 * @return array
	 */
	private function sha256_entry( $content ) {
		return array(
			'algorithm' => WPCV_File_Hasher::ALGO_SHA256,
			'hashes'    => array( hash( 'sha256', $content ) ),
		);
	}

	/**
	 * 一致する場合、finding は null で `verified: true` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_compare_one_file_returns_verified_when_hash_matches() {
		$this->put_fixture_file( 'wp-load.php', 'ok-content' );

		$result = WPCV_Verifier::compare_one_file( 'core', 'core', 'wordpress', '6.8', 'wporg', rtrim( ABSPATH, '/' ), 'wp-load.php', $this->sha256_entry( 'ok-content' ) );

		$this->assertTrue( $result['verified'] );
		$this->assertNull( $result['finding'] );
	}

	/**
	 * ローカルの内容がマニフェストと異なる場合、`modified` finding になることを確認する.
	 *
	 * @return void
	 */
	public function test_compare_one_file_detects_modified_file() {
		$this->put_fixture_file( 'wp-load.php', 'tampered-content' );

		$result = WPCV_Verifier::compare_one_file( 'core', 'core', 'wordpress', '6.8', 'wporg', rtrim( ABSPATH, '/' ), 'wp-load.php', $this->sha256_entry( 'original-content' ) );

		$this->assertFalse( $result['verified'] );
		$this->assertSame( 'modified', $result['finding']['status'] );
		$this->assertSame( 'high', $result['finding']['severity'] );
		$this->assertSame( hash( 'sha256', 'original-content' ), $result['finding']['expected_hash'] );
		$this->assertSame( hash( 'sha256', 'tampered-content' ), $result['finding']['actual_hash'] );
		$this->assertSame( 'core', $result['finding']['target_id'] );
		$this->assertSame( '6.8', $result['finding']['version'] );
	}

	/**
	 * ローカルにファイルが存在しない場合、`missing` finding(severity medium、
	 * expected_hash あり・actual_hash 無し)になることを確認する.
	 *
	 * @return void
	 */
	public function test_compare_one_file_detects_missing_file() {
		$result = WPCV_Verifier::compare_one_file( 'core', 'core', 'wordpress', '6.8', 'wporg', rtrim( ABSPATH, '/' ), 'wp-login.php', $this->sha256_entry( 'anything' ) );

		$this->assertFalse( $result['verified'] );
		$this->assertSame( 'missing', $result['finding']['status'] );
		$this->assertSame( 'medium', $result['finding']['severity'] );
		$this->assertSame( hash( 'sha256', 'anything' ), $result['finding']['expected_hash'] );
		$this->assertNull( $result['finding']['actual_hash'] );
		$this->assertNull( $result['finding']['file_size'] );
	}

	/**
	 * ローカルのパスがディレクトリ(=読み取れないファイル扱い)の場合、
	 * `unreadable` finding になることを確認する.
	 *
	 * @return void
	 */
	public function test_compare_one_file_detects_unreadable_file() {
		// マニフェストが .php ファイルを期待している場所に、あえてディレクトリを
		// 作ることで「実行時に読み取れない」状況を再現する(パーミッション操作は
		// 環境依存でテストが不安定になるため避ける).
		mkdir( ABSPATH . 'wp-settings.php', 0777, true );

		$result = WPCV_Verifier::compare_one_file( 'core', 'core', 'wordpress', '6.8', 'wporg', rtrim( ABSPATH, '/' ), 'wp-settings.php', $this->sha256_entry( 'anything' ) );

		$this->assertFalse( $result['verified'] );
		$this->assertSame( 'unreadable', $result['finding']['status'] );
		$this->assertSame( 'medium', $result['finding']['severity'] );
		$this->assertNull( $result['finding']['actual_hash'] );
	}

	/**
	 * `make_finding_for_unknown_file()` が `added`(severity指定どおり)の finding を
	 * 実ファイルの hash 込みで組み立てることを確認する.
	 *
	 * @return void
	 */
	public function test_make_finding_for_unknown_file_builds_added_finding_with_hash() {
		$this->put_fixture_file( 'wp-admin/evil.php', 'backdoor' );

		$finding = WPCV_Verifier::make_finding_for_unknown_file( 'core', 'core', 'wordpress', '6.8', 'wporg', 'wp-admin/evil.php', 'high' );

		$this->assertSame( 'added', $finding['status'] );
		$this->assertSame( 'high', $finding['severity'] );
		$this->assertSame( 'core', $finding['target_id'] );
		$this->assertSame( '6.8', $finding['version'] );
		$this->assertNull( $finding['expected_hash'] );
		$this->assertSame( hash( 'sha256', 'backdoor' ), $finding['actual_hash'] );
	}

	/**
	 * `core_unknown_file_areas()` が wp-admin/wp-includes/ABSPATH直下(非再帰、
	 * .htaccess/wp-config.php除外)の3領域を返すことを確認する(§3.3).
	 *
	 * @return void
	 */
	public function test_core_unknown_file_areas_returns_three_areas() {
		$areas = WPCV_Verifier::core_unknown_file_areas();

		$this->assertCount( 3, $areas );
		$this->assertSame( ABSPATH . 'wp-admin', $areas[0]['dir'] );
		$this->assertTrue( $areas[0]['args']['recursive'] );
		$this->assertSame( ABSPATH . 'wp-includes', $areas[1]['dir'] );
		$this->assertTrue( $areas[1]['args']['recursive'] );
		$this->assertSame( rtrim( ABSPATH, '/' ), $areas[2]['dir'] );
		$this->assertFalse( $areas[2]['args']['recursive'] );
		$this->assertContains( '.htaccess', $areas[2]['args']['extra_excluded_paths'] );
		$this->assertContains( 'wp-config.php', $areas[2]['args']['extra_excluded_paths'] );
	}

	/**
	 * `known_muplugin_loader_files()` が loader の相対ファイル名を
	 * ABSPATH相対パス(`wp-content/mu-plugins/{basename}`)へ変換することを確認する(§3.6).
	 *
	 * @return void
	 */
	public function test_known_muplugin_loader_files_maps_to_abspath_relative_paths() {
		$known_files = WPCV_Verifier::known_muplugin_loader_files( ABSPATH . 'wp-content/mu-plugins', array( 'loader.php' ) );

		$this->assertArrayHasKey( 'wp-content/mu-plugins/loader.php', $known_files );
	}

	/**
	 * すべて success の場合、run.status = success になることを確認する.
	 *
	 * @return void
	 */
	public function test_summarize_all_success() {
		$summary = WPCV_Verifier::summarize(
			array(
				array(
					'status'         => 'success',
					'findings_total' => 2,
				),
				array(
					'status'         => 'success',
					'findings_total' => 0,
				),
			)
		);

		$this->assertSame( 'success', $summary['status'] );
		$this->assertSame( 2, $summary['targets_total'] );
		$this->assertSame( 2, $summary['targets_verified'] );
		$this->assertSame( 2, $summary['findings_total'] );
	}

	/**
	 * 1件でも unverifiable/failed が混ざると run.status = partial になることを確認する.
	 *
	 * @return void
	 */
	public function test_summarize_partial_when_any_non_success() {
		$summary = WPCV_Verifier::summarize(
			array(
				array(
					'status'         => 'success',
					'findings_total' => 0,
				),
				array(
					'status'         => 'unverifiable',
					'findings_total' => 0,
				),
				array(
					'status'         => 'failed',
					'findings_total' => 0,
				),
			)
		);

		$this->assertSame( 'partial', $summary['status'] );
		$this->assertSame( 3, $summary['targets_total'] );
		$this->assertSame( 1, $summary['targets_verified'] );
		$this->assertSame( 1, $summary['targets_unverifiable'] );
		$this->assertSame( 1, $summary['targets_failed'] );
	}

	/**
	 * target_runs が空の場合は success(空である以上「失敗した target」は
	 * 存在しない)になることを確認する.
	 *
	 * @return void
	 */
	public function test_summarize_empty_is_success() {
		$summary = WPCV_Verifier::summarize( array() );

		$this->assertSame( 'success', $summary['status'] );
		$this->assertSame( 0, $summary['targets_total'] );
	}

	/**
	 * `.js` の modified severity が `wpcv_severity` フィルタで上書きできることを確認する(§5.5).
	 *
	 * @return void
	 */
	public function test_modified_js_severity_is_filterable() {
		$this->put_fixture_file( 'wp-includes/js/script.js', 'new-code' );

		$GLOBALS['_wpcv_test_filters']['wpcv_severity'][] = static function ( $severity, $path ) {
			return ( 'wp-includes/js/script.js' === $path ) ? 'low' : $severity;
		};

		$result = WPCV_Verifier::compare_one_file( 'core', 'core', 'wordpress', '6.8', 'wporg', rtrim( ABSPATH, '/' ), 'wp-includes/js/script.js', $this->sha256_entry( 'old-code' ) );

		$this->assertSame( 'low', $result['finding']['severity'] );
	}
}
