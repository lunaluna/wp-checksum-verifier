<?php
/**
 * GitHub と対応付けた target の dispatcher 接続(v0.8 §Step6)のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-static-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-budget.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-cursor.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-diff-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-finding-key.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-generation-differ.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-matcher.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-suppression-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-migrator.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-chunk-result-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-file-state-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-current-version-reader.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-update-lock-detector.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-chunk-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-starter.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-update-event-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-update-event-matcher.php';
require_once __DIR__ . '/doubles.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-github-mappings.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Run_Planner`・`WPCV_Chunk_Dispatcher` が、GitHub と対応付けたプラグイン・テーマを
 * wp.org に問い合わせず GitHub のソースで照合すること(D11)と、プラン §4.7 の組み合わせ表
 * (R1・R2・D10)のテスト.
 *
 * | 本体の状態                                 | `:_stat`         | テスト                                           |
 * |--------------------------------------------|------------------|--------------------------------------------------|
 * | 照合できた(success)                       | checksum_covered | test_mapped_plugin_is_checked_against_github     |
 * | `unknown_source`(.git・ソース無し)        | stat 走査        | test_unknown_source_runs_stat                    |
 * | `no_release_asset` / `asset_ambiguous`(R1) | stat 走査        | test_unmatched_release_asset_runs_stat           |
 * | `rate_limited` / `http_error`              | skipped          | test_transient_failures_skip_stat                |
 * | テーマ: 本体 success                       | `:_scan` が走査  | test_mapped_theme_is_checked_and_scanned         |
 * | テーマ: コア同梱(R2)                      | wp.org の処理    | test_core_bundled_theme_ignores_mapping          |
 */
class GitHubTargetDispatchTest extends TestCase {

	/**
	 * 偽の照合ソースへの呼び出しの記録(ソース名 => context の配列).
	 *
	 * @var array<string, array<int, array>>
	 */
	private $calls = array();

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clean_fixtures();
		$this->calls = array();
		unset( $GLOBALS['_wpcv_test_options'], $GLOBALS['_wpcv_test_dispatcher_now'] );
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
	 * ABSPATH 相対パスにファイルを作る.
	 *
	 * @param string $relative_path ABSPATH 相対パス.
	 * @param string $content       中身.
	 * @return void
	 */
	private function put_fixture_file( $relative_path, $content ) {
		$path = ABSPATH . $relative_path;

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}

		file_put_contents( $path, $content );
		clearstatcache();
	}

	/**
	 * 呼び出しを記録し、固定の結果(または callable の結果)を返す偽の照合ソース.
	 *
	 * @param string         $name   記録に使うソース名.
	 * @param array|callable $result `get_manifest()` の戻り値、または `function( array $context ): array`.
	 * @return WPCV_Manifest_Source
	 */
	private function source( $name, $result ) {
		$calls = &$this->calls;

		return new class( $name, $result, $calls ) implements WPCV_Manifest_Source {

			/**
			 * 記録に使うソース名.
			 *
			 * @var string
			 */
			private $name;

			/**
			 * 戻り値、または戻り値を作る callable.
			 *
			 * @var array|callable
			 */
			private $result;

			/**
			 * 呼び出しの記録(テストのプロパティへの参照).
			 *
			 * @var array
			 */
			private $calls;

			/**
			 * コンストラクタ.
			 *
			 * @param string         $name   記録に使うソース名.
			 * @param array|callable $result 戻り値、または戻り値を作る callable.
			 * @param array          $calls  呼び出しの記録(参照).
			 */
			public function __construct( $name, $result, array &$calls ) {
				$this->name   = $name;
				$this->result = $result;
				$this->calls  = &$calls;
			}

			/**
			 * 呼び出しを記録して応答する.
			 *
			 * @param array $context 照合ソースへの context.
			 * @return array
			 */
			public function get_manifest( array $context ) {
				$this->calls[ $this->name ][] = $context;

				return is_callable( $this->result ) ? call_user_func( $this->result, $context ) : $this->result;
			}
		};
	}

	/**
	 * マニフェストの戻り値(成功).
	 *
	 * @param array<string, string> $contents パス => 期待する中身.
	 * @return array
	 */
	private static function manifest( array $contents ) {
		$files = array();

		foreach ( $contents as $path => $content ) {
			$files[ $path ] = array(
				'algorithm' => 'sha256',
				'hashes'    => array( hash( 'sha256', $content ) ),
			);
		}

		return array(
			'manifest_status' => 'ok',
			'error_code'      => null,
			'files'           => $files,
		);
	}

	/**
	 * マニフェストの戻り値(失敗).
	 *
	 * @param string $error_code エラーコード.
	 * @return array
	 */
	private static function missing( $error_code ) {
		return array(
			'manifest_status' => 'missing',
			'error_code'      => $error_code,
			'files'           => array(),
		);
	}

	/**
	 * プラグイン `ghplug` の `$context`.
	 *
	 * @return array
	 */
	private function plugin_context() {
		return array(
			'version'         => '6.8',
			'plugins'         => array( 'ghplug/ghplug.php' => array( 'Version' => '1.0' ) ),
			'plugin_dir'      => ABSPATH . 'wp-content/plugins',
			'github_mappings' => array(
				'plugin:ghplug' => array(
					'repo'  => 'lunaluna/ghplug',
					'asset' => 'ghplug-pro',
				),
			),
		);
	}

	/**
	 * プラグイン `ghplug` のメインファイルを作る.
	 *
	 * @return string メインファイルの中身.
	 */
	private function put_plugin() {
		$content = "<?php\n/**\n * Plugin Name: GH\n * Version: 1.0\n */\n";

		$this->put_fixture_file( 'wp-content/plugins/ghplug/ghplug.php', $content );

		return $content;
	}

	/**
	 * Run を予約して完走させる.
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
	 * 指定 target_id の target_run 行を探す.
	 *
	 * @param array  $made      `wpcv_test_make_fake_environment()` の戻り値.
	 * @param string $target_id target_id.
	 * @return array|null
	 */
	private function find_target_run( array $made, $target_id ) {
		foreach ( $made['wpdb']->rows['wp_wpcv_target_runs'] as $row ) {
			if ( $target_id === $row['target_id'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * 指定 target の findings.
	 *
	 * @param array  $made      `wpcv_test_make_fake_environment()` の戻り値.
	 * @param string $target_id target_id.
	 * @return array[]
	 */
	private function findings_of( array $made, $target_id ) {
		return array_values(
			array_filter(
				$made['wpdb']->rows['wp_wpcv_findings'] ?? array(),
				static function ( $row ) use ( $target_id ) {
					return $target_id === $row['target_id'];
				}
			)
		);
	}

	/**
	 * 対応付けのあるプラグインは GitHub のソースで照合し、wp.org には問い合わせない(D11).
	 * source は target_run・finding とも `github`. `:_stat` は checksum_covered で飛ばす.
	 *
	 * @return void
	 */
	public function test_mapped_plugin_is_checked_against_github() {
		$content = $this->put_plugin();
		$this->put_fixture_file( 'wp-content/plugins/ghplug/changed.php', 'local edit' );

		$github = $this->source(
			'github',
			self::manifest(
				array(
					'ghplug.php'  => $content,
					'changed.php' => 'original',
				)
			)
		);
		$wporg  = $this->source( 'wporg', self::missing( WPCV_Error_Code::MANIFEST_NOT_FOUND ) );
		$made   = wpcv_test_make_fake_environment( null, $wporg, null, null, null, $github );

		$this->reserve_and_run( $made, $this->plugin_context() );

		$body = $this->find_target_run( $made, 'plugin:ghplug' );

		$this->assertSame( 'github', $body['source'] );
		$this->assertSame( 'success', $body['status'] );
		$this->assertArrayNotHasKey( 'wporg', $this->calls );

		$context = $this->calls['github'][0];

		$this->assertSame( 'plugin', $context['dimension'] );
		$this->assertSame( 'ghplug', $context['slug'] );
		$this->assertSame( '1.0', $context['version'] );
		$this->assertSame( 'lunaluna/ghplug', $context['repo'] );
		$this->assertSame( 'ghplug-pro', $context['asset'] );
		$this->assertSame( 'ghplug.php', $context['main_file'] );
		$this->assertSame( rtrim( ABSPATH, '/' ) . '/wp-content/plugins/ghplug', $context['base_dir'] );

		$findings = $this->findings_of( $made, 'plugin:ghplug' );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'github', $findings[0]['source'] );
		$this->assertSame( 'modified', $findings[0]['status'] );

		$stat = $this->find_target_run( $made, 'plugin:ghplug:_stat' );

		$this->assertSame( 'skipped', $stat['status'] );
		$this->assertSame( WPCV_Error_Code::CHECKSUM_COVERED, $stat['error_code'] );
	}

	/**
	 * 対応付けの無いプラグインは今までと同じ(wp.org). GitHub のソースは呼ばれない.
	 *
	 * @return void
	 */
	public function test_unmapped_plugin_still_uses_wporg() {
		$content = $this->put_plugin();

		$github  = $this->source( 'github', self::missing( WPCV_Error_Code::MANIFEST_NOT_FOUND ) );
		$wporg   = $this->source( 'wporg', self::manifest( array( 'ghplug.php' => $content ) ) );
		$made    = wpcv_test_make_fake_environment( null, $wporg, null, null, null, $github );
		$context = $this->plugin_context();

		unset( $context['github_mappings'] );

		$this->reserve_and_run( $made, $context );

		$this->assertSame( 'wporg', $this->find_target_run( $made, 'plugin:ghplug' )['source'] );
		$this->assertArrayNotHasKey( 'github', $this->calls );
	}

	/**
	 * `unknown_source`(.git があるディレクトリ・GitHub のソースが無い)は stat に回る(D10).
	 *
	 * @return void
	 */
	public function test_unknown_source_runs_stat() {
		$this->put_plugin();

		$cases = array(
			'github says unknown_source' => array( $this->source( 'github', self::missing( WPCV_Error_Code::UNKNOWN_SOURCE ) ) ),
			'no github source injected'  => array( null ),
		);

		foreach ( $cases as $name => $case ) {
			$made = wpcv_test_make_fake_environment( null, null, null, null, null, $case[0] );

			$this->reserve_and_run( $made, $this->plugin_context() );

			$body = $this->find_target_run( $made, 'plugin:ghplug' );
			$stat = $this->find_target_run( $made, 'plugin:ghplug:_stat' );

			$this->assertSame( 'unverifiable', $body['status'], $name );
			$this->assertSame( WPCV_Error_Code::UNKNOWN_SOURCE, $body['error_code'], $name );
			$this->assertSame( 'success', $stat['status'], $name );
			$this->assertNotEmpty( $made['wpdb']->rows['wp_wpcv_file_states'], $name );
		}
	}

	/**
	 * 対応付けはあるが照合できる配布物が無い(`no_release_asset` / `asset_ambiguous`)ときも
	 * stat に回す(R1). 対応付けの誤りが続いても、その target が何も見られなくならない.
	 *
	 * @return void
	 */
	public function test_unmatched_release_asset_runs_stat() {
		$this->put_plugin();

		foreach ( array( WPCV_Error_Code::NO_RELEASE_ASSET, WPCV_Error_Code::ASSET_AMBIGUOUS ) as $code ) {
			$made = wpcv_test_make_fake_environment( null, null, null, null, null, $this->source( 'github', self::missing( $code ) ) );

			$this->reserve_and_run( $made, $this->plugin_context() );

			$this->assertSame( $code, $this->find_target_run( $made, 'plugin:ghplug' )['error_code'], $code );
			$this->assertSame( 'success', $this->find_target_run( $made, 'plugin:ghplug:_stat' )['status'], $code );
		}
	}

	/**
	 * 一時的な障害(`rate_limited` / `http_error`)・zip の不正は stat に回さず skipped(D10).
	 *
	 * @return void
	 */
	public function test_transient_failures_skip_stat() {
		$this->put_plugin();

		$codes = array(
			WPCV_Error_Code::RATE_LIMITED,
			WPCV_Error_Code::HTTP_ERROR,
			WPCV_Error_Code::ARCHIVE_INVALID,
			WPCV_Error_Code::ARCHIVE_REJECTED,
			WPCV_Error_Code::ZIPARCHIVE_MISSING,
		);

		foreach ( $codes as $code ) {
			$made = wpcv_test_make_fake_environment( null, null, null, null, null, $this->source( 'github', self::missing( $code ) ) );

			$this->reserve_and_run( $made, $this->plugin_context() );

			$stat = $this->find_target_run( $made, 'plugin:ghplug:_stat' );

			$this->assertSame( $code, $this->find_target_run( $made, 'plugin:ghplug' )['error_code'], $code );
			$this->assertSame( 'skipped', $stat['status'], $code );
			$this->assertEmpty( $made['wpdb']->rows['wp_wpcv_file_states'] ?? array(), $code );
		}
	}

	/**
	 * テーマの `$context`.
	 *
	 * @return array
	 */
	private function theme_context() {
		return array(
			'version'         => '6.8',
			'themes'          => array(
				'ghtheme' => array(
					'version'        => '2.0',
					'template'       => 'ghtheme',
					'stylesheet_dir' => ABSPATH . 'wp-content/themes/ghtheme',
					'update_uri'     => '',
				),
			),
			'github_mappings' => array(
				'theme:ghtheme' => array(
					'repo'  => 'lunaluna/ghtheme',
					'asset' => '',
				),
			),
		);
	}

	/**
	 * テーマを GitHub で照合し、`:_scan` も同じ GitHub のマニフェストで既知のファイルを決める.
	 * マニフェストに無いファイルは未知のファイルとして `github` の finding になる.
	 *
	 * @return void
	 */
	public function test_mapped_theme_is_checked_and_scanned() {
		$css = "/*\nTheme Name: GH\nVersion: 2.0\n*/\n";

		$this->put_fixture_file( 'wp-content/themes/ghtheme/style.css', $css );
		$this->put_fixture_file( 'wp-content/themes/ghtheme/extra.php', '<?php // dropped in' );

		$github       = $this->source( 'github', self::manifest( array( 'style.css' => $css ) ) );
		$theme_source = $this->source( 'wporg_theme', self::missing( WPCV_Error_Code::MANIFEST_NOT_FOUND ) );
		$made         = wpcv_test_make_fake_environment( null, null, null, null, $theme_source, $github );

		$this->reserve_and_run( $made, $this->theme_context() );

		$body = $this->find_target_run( $made, 'theme:ghtheme' );
		$scan = $this->find_target_run( $made, 'theme:ghtheme:_scan' );

		$this->assertSame( 'github', $body['source'] );
		$this->assertSame( 'github', $scan['source'] );
		$this->assertSame( 'success', $body['status'] );
		$this->assertArrayNotHasKey( 'wporg_theme', $this->calls );
		$this->assertSame( 'theme', $this->calls['github'][0]['dimension'] );
		$this->assertSame( 'style.css', $this->calls['github'][0]['main_file'] );
		$this->assertSame( 'lunaluna/ghtheme', $this->calls['github'][0]['repo'] );

		$scan_findings = $this->findings_of( $made, 'theme:ghtheme:_scan' );

		$this->assertCount( 1, $scan_findings );
		$this->assertSame( 'github', $scan_findings[0]['source'] );
		$this->assertStringEndsWith( 'ghtheme/extra.php', $scan_findings[0]['path'] );
		$this->assertSame( 'skipped', $this->find_target_run( $made, 'theme:ghtheme:_stat' )['status'] );
	}

	/**
	 * テーマの本体が照合できなければ(R1: アセット無し)、`:_scan` は走査せず skipped、
	 * `:_stat` が stat で走査する.
	 *
	 * @return void
	 */
	public function test_mapped_theme_without_asset_runs_stat_and_skips_scan() {
		$this->put_fixture_file( 'wp-content/themes/ghtheme/style.css', "/*\nVersion: 2.0\n*/\n" );

		$github = $this->source( 'github', self::missing( WPCV_Error_Code::NO_RELEASE_ASSET ) );
		$made   = wpcv_test_make_fake_environment( null, null, null, null, $this->source( 'wporg_theme', self::missing( WPCV_Error_Code::MANIFEST_NOT_FOUND ) ), $github );

		$this->reserve_and_run( $made, $this->theme_context() );

		$this->assertSame( 'unverifiable', $this->find_target_run( $made, 'theme:ghtheme' )['status'] );
		$this->assertSame( 'success', $this->find_target_run( $made, 'theme:ghtheme:_stat' )['status'] );
		$this->assertSame( 'skipped', $this->find_target_run( $made, 'theme:ghtheme:_scan' )['status'] );
	}

	/**
	 * コア同梱のテーマ(今のコアのマニフェストに `wp-content/themes/{slug}/` があるもの)は、
	 * 対応付けを無視して wp.org の処理にする(R2). target_run の source も `wporg` に直る.
	 *
	 * @return void
	 */
	public function test_core_bundled_theme_ignores_mapping() {
		$css = "/*\nTheme Name: GH\nVersion: 2.0\n*/\n";

		$this->put_fixture_file( 'wp-content/themes/ghtheme/style.css', $css );

		$core         = $this->source( 'core', self::manifest( array( 'wp-content/themes/ghtheme/style.css' => $css ) ) );
		$github       = $this->source( 'github', self::manifest( array( 'style.css' => $css ) ) );
		$theme_source = $this->source( 'wporg_theme', self::manifest( array( 'style.css' => $css ) ) );
		$made         = wpcv_test_make_fake_environment( $core, null, null, null, $theme_source, $github );

		$this->reserve_and_run( $made, $this->theme_context() );

		$this->assertArrayNotHasKey( 'github', $this->calls );
		$this->assertArrayHasKey( 'wporg_theme', $this->calls );

		$body = $this->find_target_run( $made, 'theme:ghtheme' );

		$this->assertSame( 'wporg', $body['source'] );
		$this->assertSame( 'success', $body['status'] );
		// `:_scan` の source も本体に合わせて `wporg` に直り、wp.org の zip(を持つ照合ソース)で
		// 既知のファイルを決める. GitHub のマニフェストは使わない.
		$this->assertSame( 'wporg', $this->find_target_run( $made, 'theme:ghtheme:_scan' )['source'] );
		$this->assertSame( 'success', $this->find_target_run( $made, 'theme:ghtheme:_scan' )['status'] );
	}
}
