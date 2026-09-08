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
 * §3.2/§3.4/§3.6 の各検証と、§5.2 の run 集計ロジックのテスト.
 *
 * tests/fixtures/fake-root/(ABSPATH)配下に実ファイルを作って検証する。
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
	 * Verifier を組み立てる(未使用のソースには常に成功する空マニフェストを渡す).
	 *
	 * @param WPCV_Manifest_Source|null $core_source   省略時は空マニフェストの fake.
	 * @param WPCV_Manifest_Source|null $plugin_source 省略時は空マニフェストの fake.
	 * @return WPCV_Verifier
	 */
	private function make_verifier( $core_source = null, $plugin_source = null ) {
		$empty_ok = array(
			'manifest_status' => 'ok',
			'error_code'      => null,
			'files'           => array(),
		);

		return new WPCV_Verifier(
			$core_source ?? new WPCV_Test_Fake_Manifest_Source( $empty_ok ),
			$plugin_source ?? new WPCV_Test_Fake_Manifest_Source( $empty_ok ),
			new WPCV_Unknown_File_Scanner()
		);
	}

	/**
	 * findings から path をキーにした連想配列を作る(assertion を読みやすくする).
	 *
	 * @param array $findings verify_*() が返した findings.
	 * @return array path => finding.
	 */
	private function findings_by_path( array $findings ) {
		$by_path = array();
		foreach ( $findings as $finding ) {
			$by_path[ $finding['path'] ] = $finding;
		}
		return $by_path;
	}

	/**
	 * マニフェスト取得自体が失敗した場合、target_run.status = unverifiable になり、
	 * findings は0件であることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_core_returns_unverifiable_when_manifest_missing() {
		$core_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'missing',
				'error_code'      => WPCV_Error_Code::MANIFEST_NOT_FOUND,
				'files'           => array(),
			)
		);

		$verifier = $this->make_verifier( $core_source );
		$result   = $verifier->verify_core( array( 'version' => '6.8' ) );

		$this->assertSame( 'unverifiable', $result['target_run']['status'] );
		$this->assertSame( WPCV_Error_Code::MANIFEST_NOT_FOUND, $result['target_run']['error_code'] );
		$this->assertSame( array(), $result['findings'] );
	}

	/**
	 * 実ファイルの内容がマニフェストと一致する場合、finding を作らず
	 * files_verified に加算されることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_core_counts_matched_file_as_verified_without_finding() {
		$this->put_fixture_file( 'wp-load.php', 'ok-content' );

		$core_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array( 'wp-load.php' => $this->sha256_entry( 'ok-content' ) ),
			)
		);

		$verifier = $this->make_verifier( $core_source );
		$result   = $verifier->verify_core( array( 'version' => '6.8' ) );

		$this->assertSame( 'success', $result['target_run']['status'] );
		$this->assertSame( 1, $result['target_run']['files_total'] );
		$this->assertSame( 1, $result['target_run']['files_verified'] );
		$this->assertArrayNotHasKey( 'wp-load.php', $this->findings_by_path( $result['findings'] ) );
	}

	/**
	 * ローカルの内容がマニフェストと異なる場合、`modified` finding になることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_core_detects_modified_file() {
		$this->put_fixture_file( 'wp-load.php', 'tampered-content' );

		$core_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array( 'wp-load.php' => $this->sha256_entry( 'original-content' ) ),
			)
		);

		$verifier = $this->make_verifier( $core_source );
		$result   = $verifier->verify_core( array( 'version' => '6.8' ) );

		$finding = $this->findings_by_path( $result['findings'] )['wp-load.php'];

		$this->assertSame( 'modified', $finding['status'] );
		$this->assertSame( 'high', $finding['severity'] );
		$this->assertSame( hash( 'sha256', 'original-content' ), $finding['expected_hash'] );
		$this->assertSame( hash( 'sha256', 'tampered-content' ), $finding['actual_hash'] );
		$this->assertSame( 'core', $finding['target_id'] );
		$this->assertSame( '6.8', $finding['version'] );
		$this->assertSame( 0, $result['target_run']['files_verified'] );
	}

	/**
	 * ローカルにファイルが存在しない場合、`missing` finding(severity medium、
	 * expected_hash あり・actual_hash 無し)になることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_core_detects_missing_file() {
		$core_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array( 'wp-login.php' => $this->sha256_entry( 'anything' ) ),
			)
		);

		$verifier = $this->make_verifier( $core_source );
		$result   = $verifier->verify_core( array( 'version' => '6.8' ) );

		$finding = $this->findings_by_path( $result['findings'] )['wp-login.php'];

		$this->assertSame( 'missing', $finding['status'] );
		$this->assertSame( 'medium', $finding['severity'] );
		$this->assertSame( hash( 'sha256', 'anything' ), $finding['expected_hash'] );
		$this->assertNull( $finding['actual_hash'] );
		$this->assertNull( $finding['file_size'] );
	}

	/**
	 * ローカルのパスがディレクトリ(=読み取れないファイル扱い)の場合、
	 * `unreadable` finding になることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_core_detects_unreadable_file() {
		// マニフェストが .php ファイルを期待している場所に、あえてディレクトリを
		// 作ることで「実行時に読み取れない」状況を再現する(パーミッション操作は
		// 環境依存でテストが不安定になるため避ける).
		mkdir( ABSPATH . 'wp-settings.php', 0777, true );

		$core_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array( 'wp-settings.php' => $this->sha256_entry( 'anything' ) ),
			)
		);

		$verifier = $this->make_verifier( $core_source );
		$result   = $verifier->verify_core( array( 'version' => '6.8' ) );

		$finding = $this->findings_by_path( $result['findings'] )['wp-settings.php'];

		$this->assertSame( 'unreadable', $finding['status'] );
		$this->assertSame( 'medium', $finding['severity'] );
		$this->assertNull( $finding['actual_hash'] );
	}

	/**
	 * wp-admin 配下にマニフェストに無い .php ファイルがある場合、
	 * `added`(severity high)の finding が core の target_id で作られることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_core_detects_unknown_file_in_wp_admin() {
		$this->put_fixture_file( 'wp-admin/index.php', 'core-index' );
		$this->put_fixture_file( 'wp-admin/evil.php', 'backdoor' );

		$core_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array( 'wp-admin/index.php' => $this->sha256_entry( 'core-index' ) ),
			)
		);

		$verifier = $this->make_verifier( $core_source );
		$result   = $verifier->verify_core( array( 'version' => '6.8' ) );

		$finding = $this->findings_by_path( $result['findings'] )['wp-admin/evil.php'];

		$this->assertSame( 'added', $finding['status'] );
		$this->assertSame( 'high', $finding['severity'] );
		$this->assertSame( 'core', $finding['target_id'] );
		$this->assertSame( '6.8', $finding['version'] );
		$this->assertNull( $finding['expected_hash'] );
		$this->assertSame( hash( 'sha256', 'backdoor' ), $finding['actual_hash'] );
	}

	/**
	 * ABSPATH 直下の `.htaccess` / `wp-config.php` は未知ファイル検出の対象外
	 * であることを確認する(§3.3: ハッシュ承認対象で既定除外).
	 *
	 * @return void
	 */
	public function test_verify_core_excludes_htaccess_and_wp_config_at_root() {
		$this->put_fixture_file( '.htaccess', 'rules' );
		$this->put_fixture_file( 'wp-config.php', 'secrets' );

		$verifier = $this->make_verifier();
		$result   = $verifier->verify_core( array( 'version' => '6.8' ) );

		$by_path = $this->findings_by_path( $result['findings'] );

		$this->assertArrayNotHasKey( '.htaccess', $by_path );
		$this->assertArrayNotHasKey( 'wp-config.php', $by_path );
	}

	/**
	 * マニフェストに `..` を含む不正なパスが混ざっていても、無視され
	 * files_total に加算されないことを確認する(§12.5).
	 *
	 * @return void
	 */
	public function test_verify_core_skips_unsafe_manifest_paths() {
		$core_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				// fake-root/.gitkeep はこのテストクラスとは無関係に常設されている
				// フィクスチャファイルのため、ABSPATH 直下の未知ファイル走査に
				// 拾われないよう既知として含めておく.
				'files'           => array(
					'../outside.php' => $this->sha256_entry( 'anything' ),
					'.gitkeep'       => $this->sha256_entry( '' ),
				),
			)
		);

		$verifier = $this->make_verifier( $core_source );
		$result   = $verifier->verify_core( array( 'version' => '6.8' ) );

		$this->assertSame( 1, $result['target_run']['files_total'] );
		$this->assertSame( array(), $result['findings'] );
	}

	/**
	 * version が無い場合、`InvalidArgumentException` を投げることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_core_throws_when_version_missing() {
		$this->expectException( InvalidArgumentException::class );

		$verifier = $this->make_verifier();
		$verifier->verify_core( array() );
	}

	/**
	 * バージョンが空(version_unknown 相当)の場合、target_run.version が
	 * null になり、findings は0件であることを確認する(§3.4).
	 *
	 * @return void
	 */
	public function test_verify_plugin_returns_unverifiable_when_version_unknown() {
		$plugin_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'missing',
				'error_code'      => WPCV_Error_Code::VERSION_UNKNOWN,
				'files'           => array(),
			)
		);

		$verifier = $this->make_verifier( null, $plugin_source );
		$result   = $verifier->verify_plugin(
			array(
				'slug'            => 'akismet',
				'plugin_root_dir' => ABSPATH . 'wp-content/plugins/akismet',
			)
		);

		$this->assertSame( 'unverifiable', $result['target_run']['status'] );
		$this->assertSame( WPCV_Error_Code::VERSION_UNKNOWN, $result['target_run']['error_code'] );
		$this->assertNull( $result['target_run']['version'] );
		$this->assertSame( array(), $result['findings'] );
	}

	/**
	 * プラグインの実ファイルがマニフェストと一致・不一致の両方を検出し、
	 * finding の target_id/slug/version が正しく設定されることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_plugin_matches_and_detects_modified() {
		$this->put_fixture_file( 'wp-content/plugins/akismet/akismet.php', 'main-file' );
		$this->put_fixture_file( 'wp-content/plugins/akismet/readme.txt', 'tampered-readme' );

		$plugin_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array(
					'akismet.php' => $this->sha256_entry( 'main-file' ),
					'readme.txt'  => $this->sha256_entry( 'original-readme' ),
				),
			)
		);

		$verifier = $this->make_verifier( null, $plugin_source );
		$result   = $verifier->verify_plugin(
			array(
				'slug'            => 'akismet',
				'version'         => '5.3',
				'plugin_root_dir' => ABSPATH . 'wp-content/plugins/akismet',
			)
		);

		$this->assertSame( 'plugin:akismet', $result['target_run']['target_id'] );
		$this->assertSame( 1, $result['target_run']['files_verified'] );

		$finding = $this->findings_by_path( $result['findings'] )['wp-content/plugins/akismet/readme.txt'];
		$this->assertSame( 'modified', $finding['status'] );
		$this->assertSame( 'plugin:akismet', $finding['target_id'] );
		$this->assertSame( 'akismet', $finding['slug'] );
		$this->assertSame( '5.3', $finding['version'] );
	}

	/**
	 * loader が個別に `unverifiable`/`unknown_source` の target_run になることを確認する
	 * (§3.6: wp.org/GitHub のマッピングが無い既定状態).
	 *
	 * @return void
	 */
	public function test_verify_muplugin_area_marks_loaders_unverifiable_unknown_source() {
		$this->put_fixture_file( 'wp-content/mu-plugins/loader.php', 'loader-content' );

		$verifier = $this->make_verifier();
		$result   = $verifier->verify_muplugin_area(
			array(
				'mu_plugin_dir' => ABSPATH . 'wp-content/mu-plugins',
				'loaders'       => array( 'loader.php' ),
			)
		);

		$loader_target_run = null;
		foreach ( $result['target_runs'] as $target_run ) {
			if ( 'muplugin:loader.php' === $target_run['target_id'] ) {
				$loader_target_run = $target_run;
			}
		}

		$this->assertNotNull( $loader_target_run );
		$this->assertSame( 'unverifiable', $loader_target_run['status'] );
		$this->assertSame( WPCV_Error_Code::UNKNOWN_SOURCE, $loader_target_run['error_code'] );
	}

	/**
	 * mu-plugins のサブディレクトリ配下にある未知ファイルが、合成 target
	 * `muplugin:_scan` の finding(`added`)として検出されることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_muplugin_area_flags_subdirectory_file_as_added_on_scan_target() {
		$this->put_fixture_file( 'wp-content/mu-plugins/loader.php', 'loader-content' );
		$this->put_fixture_file( 'wp-content/mu-plugins/vendor/backdoor.php', 'backdoor' );

		$verifier = $this->make_verifier();
		$result   = $verifier->verify_muplugin_area(
			array(
				'mu_plugin_dir' => ABSPATH . 'wp-content/mu-plugins',
				'loaders'       => array( 'loader.php' ),
			)
		);

		$finding = $this->findings_by_path( $result['findings'] )['wp-content/mu-plugins/vendor/backdoor.php'];

		$this->assertSame( 'added', $finding['status'] );
		$this->assertSame( 'muplugin:_scan', $finding['target_id'] );
		$this->assertSame( 'none', $finding['source'] );
		$this->assertSame( '', $finding['version'] );

		$scan_target_run = null;
		foreach ( $result['target_runs'] as $target_run ) {
			if ( 'muplugin:_scan' === $target_run['target_id'] ) {
				$scan_target_run = $target_run;
			}
		}
		$this->assertNotNull( $scan_target_run );
		$this->assertSame( 1, $scan_target_run['findings_total'] );
	}

	/**
	 * loader 自身(直下の既知ファイル)は未知ファイルとして検出されないことを確認する.
	 *
	 * @return void
	 */
	public function test_verify_muplugin_area_does_not_flag_known_loader_file() {
		$this->put_fixture_file( 'wp-content/mu-plugins/loader.php', 'loader-content' );

		$verifier = $this->make_verifier();
		$result   = $verifier->verify_muplugin_area(
			array(
				'mu_plugin_dir' => ABSPATH . 'wp-content/mu-plugins',
				'loaders'       => array( 'loader.php' ),
			)
		);

		$this->assertSame( array(), $result['findings'] );
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

		$core_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array( 'wp-includes/js/script.js' => $this->sha256_entry( 'old-code' ) ),
			)
		);

		$verifier = $this->make_verifier( $core_source );
		$result   = $verifier->verify_core( array( 'version' => '6.8' ) );

		$finding = $this->findings_by_path( $result['findings'] )['wp-includes/js/script.js'];
		$this->assertSame( 'low', $finding['severity'] );
	}
}
