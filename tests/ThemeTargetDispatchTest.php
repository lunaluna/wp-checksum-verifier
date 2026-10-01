<?php
/**
 * テーマの target の列挙と処理(v0.7 §Step3)のテスト.
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
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-chunk-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-starter.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-update-event-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-update-event-matcher.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Run_Planner` がテーマの target(`theme:{stylesheet}`・`:_stat`)を列挙し、
 * `WPCV_Chunk_Dispatcher` が本体を照合ソースで照合し、照合できなかったテーマだけを
 * stat で走査することを、run を完走させて確かめる(プラン §3.7 の組み合わせ表).
 *
 * ABSPATH(tests/fixtures/fake-root/)配下にテーマのファイルを作る.
 * 照合ソースは、stylesheet ごとに結果を返し、受け取った引数を記録する偽物を使う.
 */
class ThemeTargetDispatchTest extends TestCase {

	/**
	 * 偽の照合ソースが受け取った `$context` の記録.
	 *
	 * @var array<int, array>
	 */
	private $source_calls = array();

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clean_fixtures();
		$this->source_calls = array();
		unset( $GLOBALS['_wpcv_test_options'] );
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
	 * テーマのディレクトリの中にファイルを作る.
	 *
	 * @param string $stylesheet テーマの stylesheet(`dir/sub` も可).
	 * @param string $relative   テーマ内の相対パス.
	 * @param string $content    中身.
	 * @return void
	 */
	private function put_theme_file( $stylesheet, $relative, $content ) {
		$path = $this->theme_dir( $stylesheet ) . '/' . $relative;

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}

		file_put_contents( $path, $content );
	}

	/**
	 * テーマのディレクトリの絶対パス.
	 *
	 * @param string $stylesheet テーマの stylesheet.
	 * @return string
	 */
	private function theme_dir( $stylesheet ) {
		return ABSPATH . 'wp-content/themes/' . $stylesheet;
	}

	/**
	 * Run に渡す `$context`(コアだけ・プラグイン無し + 指定したテーマ).
	 *
	 * @param array<string, array> $themes stylesheet => `version`・`update_uri`(省略可).
	 * @return array
	 */
	private function context_with_themes( array $themes ) {
		$described = array();

		foreach ( $themes as $stylesheet => $theme ) {
			$described[ $stylesheet ] = array(
				'version'        => $theme['version'] ?? '1.0',
				'template'       => $stylesheet,
				'stylesheet_dir' => array_key_exists( 'stylesheet_dir', $theme ) ? $theme['stylesheet_dir'] : $this->theme_dir( $stylesheet ),
				'update_uri'     => $theme['update_uri'] ?? '',
			);
		}

		return array(
			'version' => '6.8',
			'themes'  => $described,
		);
	}

	/**
	 * Stylesheet ごとに結果を返し、受け取った `$context` を記録する偽の照合ソース.
	 *
	 * @param array<string, array> $results stylesheet => `get_manifest()` の戻り値.
	 * @return WPCV_Manifest_Source
	 */
	private function theme_source( array $results ) {
		$calls = &$this->source_calls;

		return new class( $results, $calls ) implements WPCV_Manifest_Source {

			/**
			 * Stylesheet => 戻り値.
			 *
			 * @var array
			 */
			private $results;

			/**
			 * 呼び出しの記録(テストのプロパティへの参照).
			 *
			 * @var array
			 */
			private $calls;

			/**
			 * コンストラクタ.
			 *
			 * @param array $results Stylesheet => 戻り値.
			 * @param array $calls   呼び出しの記録(参照).
			 */
			public function __construct( array $results, array &$calls ) {
				$this->results = $results;
				$this->calls   = &$calls;
			}

			/**
			 * 記録して、stylesheet に対応する結果を返す.
			 *
			 * @param array $context コンテキスト.
			 * @return array
			 */
			public function get_manifest( array $context ) {
				$this->calls[] = $context;

				return $this->results[ $context['slug'] ];
			}
		};
	}

	/**
	 * 照合できたときのマニフェスト.
	 *
	 * @param array<string, string> $files テーマ内の相対パス => 中身.
	 * @return array
	 */
	private static function ok_manifest( array $files ) {
		$manifest = array();

		foreach ( $files as $path => $content ) {
			$manifest[ $path ] = array(
				'algorithm' => 'sha256',
				'hashes'    => array( hash( 'sha256', $content ) ),
			);
		}

		return array(
			'manifest_status' => 'ok',
			'error_code'      => null,
			'files'           => $manifest,
		);
	}

	/**
	 * マニフェストが無いときの戻り値.
	 *
	 * @param string $error_code `WPCV_Error_Code` の値.
	 * @return array
	 */
	private static function missing_manifest( $error_code ) {
		return array(
			'manifest_status' => 'missing',
			'error_code'      => $error_code,
			'files'           => array(),
		);
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
	 * @param int    $run_id    run の id.
	 * @return array|null
	 */
	private function find_target_run( array $made, $target_id, $run_id = 1 ) {
		foreach ( $made['wpdb']->rows['wp_wpcv_target_runs'] as $row ) {
			if ( $target_id === $row['target_id'] && (int) $run_id === (int) $row['run_id'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * 指定 target_id・run の findings.
	 *
	 * @param array  $made      `wpcv_test_make_fake_environment()` の戻り値.
	 * @param string $target_id target_id.
	 * @param int    $run_id    run の id.
	 * @return array
	 */
	private function findings_of( array $made, $target_id, $run_id = 1 ) {
		return array_values(
			array_filter(
				$made['wpdb']->rows['wp_wpcv_findings'] ?? array(),
				static function ( $row ) use ( $target_id, $run_id ) {
					return $target_id === $row['target_id'] && (int) $run_id === (int) $row['run_id'];
				}
			)
		);
	}

	/**
	 * `wpcv_file_states` の行を path => 行 で返す.
	 *
	 * @param array $made `wpcv_test_make_fake_environment()` の戻り値.
	 * @return array<string, array>
	 */
	private function file_states_by_path( array $made ) {
		$by_path = array();

		foreach ( $made['wpdb']->rows['wp_wpcv_file_states'] ?? array() as $row ) {
			$by_path[ $row['path'] ] = $row;
		}

		return $by_path;
	}

	/**
	 * WordPress.org と照合できたテーマ: 本体は success で finding なし、`:_stat` は
	 * `checksum_covered` で走査しない. 照合ソースには slug・version・Update URI と、
	 * WordPress の version(v0.7 §Step4)が渡る.
	 *
	 * @return void
	 */
	public function test_wporg_theme_matching_zip_is_success_and_stat_is_covered() {
		$this->put_theme_file( 'acme', 'style.css', 'css' );
		$this->put_theme_file( 'acme', 'templates/index.html', 'html' );

		$made = wpcv_test_make_fake_environment(
			null,
			null,
			null,
			null,
			$this->theme_source(
				array(
					'acme' => self::ok_manifest(
						array(
							'style.css'            => 'css',
							'templates/index.html' => 'html',
						)
					),
				)
			)
		);

		$this->reserve_and_run(
			$made,
			$this->context_with_themes(
				array(
					'acme' => array(
						'version'    => '1.2',
						'update_uri' => 'https://wordpress.org/themes/acme/',
					),
				)
			)
		);

		$body = $this->find_target_run( $made, 'theme:acme' );
		$stat = $this->find_target_run( $made, 'theme:acme:_stat' );

		$this->assertSame( WPCV_Target_Status::SUCCESS, $body['status'] );
		$this->assertSame( 'theme', $body['dimension'] );
		$this->assertSame( 'acme', $body['slug'] );
		$this->assertSame( '1.2', $body['version'] );
		$this->assertSame( 'ok', $body['manifest_status'] );
		$this->assertSame( 2, (int) $body['files_verified'] );
		$this->assertSame( array(), $this->findings_of( $made, 'theme:acme' ) );

		$this->assertSame( WPCV_Target_Status::SKIPPED, $stat['status'] );
		$this->assertSame( WPCV_Error_Code::CHECKSUM_COVERED, $stat['error_code'] );
		$this->assertSame( array(), $this->file_states_by_path( $made ) );

		$this->assertSame(
			array(
				'slug'         => 'acme',
				'version'      => '1.2',
				'update_uri'   => 'https://wordpress.org/themes/acme/',
				// D7(v0.7 §Step4): コア同梱テーマの md5 を引くための WordPress の version.
				'core_version' => '6.8',
			),
			$this->source_calls[0]
		);
	}

	/**
	 * 照合できたテーマのファイルが zip と違えば、本体から `modified` が出る
	 * (古い既定テーマの minify 差もこの形で出る. U4). パスは ABSPATH からの相対パス.
	 *
	 * @return void
	 */
	public function test_wporg_theme_with_changed_file_reports_modified() {
		$this->put_theme_file( 'acme', 'style.css', 'tampered' );

		$made = wpcv_test_make_fake_environment(
			null,
			null,
			null,
			null,
			$this->theme_source( array( 'acme' => self::ok_manifest( array( 'style.css' => 'css' ) ) ) )
		);

		$this->reserve_and_run( $made, $this->context_with_themes( array( 'acme' => array() ) ) );

		$findings = $this->findings_of( $made, 'theme:acme' );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'modified', $findings[0]['status'] );
		$this->assertSame( 'wp-content/themes/acme/style.css', $findings[0]['path'] );
		$this->assertSame( hash( 'sha256', 'css' ), $findings[0]['expected_hash'] );
	}

	/**
	 * WordPress.org に無いテーマ(`manifest_not_found`)と、照合しないテーマ
	 * (`unknown_source`. Update URI が別・入れ子)は `:_stat` が走査し、初回は
	 * ベースラインだけを作る. 2回目に書き換えたファイルは `stat_changed` になる.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function stat_eligible_cases() {
		return array(
			'not on wporg'        => array( 'custom', WPCV_Error_Code::MANIFEST_NOT_FOUND ),
			'other update uri'    => array( 'premium', WPCV_Error_Code::UNKNOWN_SOURCE ),
			'nested stylesheet'   => array( 'collection/acme', WPCV_Error_Code::UNKNOWN_SOURCE ),
			'empty theme version' => array( 'noversion', WPCV_Error_Code::VERSION_UNKNOWN ),
		);
	}

	/**
	 * 照合できなかったテーマの stat 走査のテスト本体.
	 *
	 * @dataProvider stat_eligible_cases
	 *
	 * @param string $stylesheet テーマの stylesheet.
	 * @param string $error_code 照合ソースが返す error_code.
	 * @return void
	 */
	public function test_unverifiable_theme_is_stat_scanned( $stylesheet, $error_code ) {
		$this->put_theme_file( $stylesheet, 'style.css', 'css' );
		$this->put_theme_file( $stylesheet, 'functions.php', '<?php' );
		touch( $this->theme_dir( $stylesheet ) . '/style.css', 1700000000 );

		$source  = $this->theme_source( array( $stylesheet => self::missing_manifest( $error_code ) ) );
		$context = $this->context_with_themes( array( $stylesheet => array() ) );
		$made    = wpcv_test_make_fake_environment( null, null, null, null, $source );

		$this->reserve_and_run( $made, $context );

		$body_id = 'theme:' . $stylesheet;
		$stat_id = $body_id . ':_stat';

		$this->assertSame( WPCV_Target_Status::UNVERIFIABLE, $this->find_target_run( $made, $body_id )['status'] );
		$this->assertSame( $error_code, $this->find_target_run( $made, $body_id )['error_code'] );
		$this->assertSame( WPCV_Target_Status::SUCCESS, $this->find_target_run( $made, $stat_id )['status'] );
		$this->assertSame( array(), $this->findings_of( $made, $stat_id ) );

		$states = $this->file_states_by_path( $made );
		$this->assertCount( 2, $states );
		$this->assertSame( $stat_id, $states[ 'wp-content/themes/' . $stylesheet . '/style.css' ]['target_id'] );

		// 2回目: 書き換えたファイルが stat_changed になる(ベースラインと比較できている).
		file_put_contents( $this->theme_dir( $stylesheet ) . '/style.css', 'changed css' );
		touch( $this->theme_dir( $stylesheet ) . '/style.css', 1700000500 );

		$this->reserve_and_run( $made, $context );

		$findings = $this->findings_of( $made, $stat_id, 2 );
		$this->assertCount( 1, $findings );
		$this->assertSame( 'stat_changed', $findings[0]['status'] );
		$this->assertSame( 'wp-content/themes/' . $stylesheet . '/style.css', $findings[0]['path'] );
	}

	/**
	 * 一時的な障害と、zip の問題・`ZipArchive` が無い場合は、`:_stat` を走査しない
	 * (§9-5. 2026-10-01 ユーザー決定「回さない」. ベースラインを不意に作らない).
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function stat_ineligible_error_codes() {
		return array(
			'http error'         => array( WPCV_Error_Code::HTTP_ERROR ),
			'archive rejected'   => array( WPCV_Error_Code::ARCHIVE_REJECTED ),
			'archive invalid'    => array( WPCV_Error_Code::ARCHIVE_INVALID ),
			'ziparchive missing' => array( WPCV_Error_Code::ZIPARCHIVE_MISSING ),
		);
	}

	/**
	 * Stat に回さない場合のテスト本体.
	 *
	 * @dataProvider stat_ineligible_error_codes
	 *
	 * @param string $error_code 照合ソースが返す error_code.
	 * @return void
	 */
	public function test_stat_is_skipped_for_transient_or_archive_errors( $error_code ) {
		$this->put_theme_file( 'acme', 'style.css', 'css' );

		$made = wpcv_test_make_fake_environment(
			null,
			null,
			null,
			null,
			$this->theme_source( array( 'acme' => self::missing_manifest( $error_code ) ) )
		);

		$this->reserve_and_run( $made, $this->context_with_themes( array( 'acme' => array() ) ) );

		$stat = $this->find_target_run( $made, 'theme:acme:_stat' );

		$this->assertSame( WPCV_Target_Status::SKIPPED, $stat['status'] );
		$this->assertSame( $error_code, $stat['error_code'] );
		$this->assertSame( array(), $this->file_states_by_path( $made ) );
	}

	/**
	 * 実行時点でディレクトリが分からないテーマは `target_missing`(本体・stat とも).
	 *
	 * @return void
	 */
	public function test_theme_without_directory_is_target_missing() {
		$made = wpcv_test_make_fake_environment(
			null,
			null,
			null,
			null,
			$this->theme_source( array( 'gone' => self::ok_manifest( array() ) ) )
		);

		$this->reserve_and_run( $made, $this->context_with_themes( array( 'gone' => array( 'stylesheet_dir' => '' ) ) ) );

		$this->assertSame( WPCV_Error_Code::TARGET_MISSING, $this->find_target_run( $made, 'theme:gone' )['error_code'] );
		$this->assertSame( array(), $this->source_calls );
	}

	/**
	 * テーマのソースを注入しない呼び出し元(v0.6 まで)では、本体は `unknown_source` に
	 * なり、`:_stat` が走査する(後方互換).
	 *
	 * @return void
	 */
	public function test_without_theme_source_body_is_unknown_source_and_stat_runs() {
		$this->put_theme_file( 'acme', 'style.css', 'css' );

		$made = wpcv_test_make_fake_environment();

		$this->reserve_and_run( $made, $this->context_with_themes( array( 'acme' => array() ) ) );

		$this->assertSame( WPCV_Error_Code::UNKNOWN_SOURCE, $this->find_target_run( $made, 'theme:acme' )['error_code'] );
		$this->assertSame( WPCV_Target_Status::SUCCESS, $this->find_target_run( $made, 'theme:acme:_stat' )['status'] );
	}

	/**
	 * テーマのファイルと、ABSPATH 直下のファイルを1つずつ含むコアのマニフェスト
	 * (どちらもローカルと違う md5).
	 *
	 * @return WPCV_Test_Fake_Manifest_Source
	 */
	private static function core_source_with_theme_and_root_files() {
		return new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array(
					'wp-content/themes/acme/style.css'  => array(
						'algorithm' => 'md5',
						'hashes'    => array( md5( 'core-bundled' ) ),
					),
					'wp-content/themes/gone/style.css'  => array(
						'algorithm' => 'md5',
						'hashes'    => array( md5( 'not installed' ) ),
					),
					'wp-login.php'                      => array(
						'algorithm' => 'md5',
						'hashes'    => array( md5( 'core login' ) ),
					),
				),
			)
		);
	}

	/**
	 * D7・U7(v0.7 §Step4): テーマのソースが組み込まれていれば、コアの照合は
	 * `wp-content/themes/` 配下を見ない(改変も欠落も). それ以外のコアのファイルは
	 * 従来どおり照合する.
	 *
	 * @return void
	 */
	public function test_core_target_skips_theme_files_when_theme_source_is_set() {
		$this->put_theme_file( 'acme', 'style.css', 'updated from wordpress.org' );
		file_put_contents( ABSPATH . 'wp-login.php', 'tampered login' );

		$made = wpcv_test_make_fake_environment(
			self::core_source_with_theme_and_root_files(),
			null,
			null,
			null,
			$this->theme_source( array( 'acme' => self::ok_manifest( array( 'style.css' => 'updated from wordpress.org' ) ) ) )
		);

		$this->reserve_and_run( $made, $this->context_with_themes( array( 'acme' => array() ) ) );

		$core_paths = array_column( $this->findings_of( $made, 'core' ), 'path' );

		$this->assertSame( array( 'wp-login.php' ), $core_paths );
		$this->assertSame( 1, (int) $this->find_target_run( $made, 'core' )['files_total'] );
		$this->assertSame( array(), $this->findings_of( $made, 'theme:acme' ) );
	}

	/**
	 * テーマのソースが組み込まれていない呼び出し元(v0.6 まで)では、コアの照合は
	 * 従来どおり `wp-content/themes/` 配下の改変も出す(外すと、どこからも照合されなくなる).
	 *
	 * @return void
	 */
	public function test_core_target_still_checks_theme_files_without_theme_source() {
		$this->put_theme_file( 'acme', 'style.css', 'updated from wordpress.org' );
		file_put_contents( ABSPATH . 'wp-login.php', 'tampered login' );

		$made = wpcv_test_make_fake_environment( self::core_source_with_theme_and_root_files() );

		$this->reserve_and_run( $made, $this->context_with_themes( array( 'acme' => array() ) ) );

		$core_paths = array_column( $this->findings_of( $made, 'core' ), 'path' );
		sort( $core_paths );

		// 入っていない gone テーマの欠落は v0.5 U2 のとおり出さない.
		$this->assertSame( array( 'wp-content/themes/acme/style.css', 'wp-login.php' ), $core_paths );
	}

	/**
	 * D8・U5(v0.7 §Step5): wp.org と照合できたテーマは `:_scan` が走査し、zip に無い
	 * ファイルを `added` として出す(PHP は high、それ以外は medium). zip にあるファイルは
	 * 出さない. 消せば次の run では出ない.
	 *
	 * @return void
	 */
	public function test_scan_reports_files_not_in_zip_for_verified_theme() {
		$this->put_theme_file( 'acme', 'style.css', 'css' );
		$this->put_theme_file( 'acme', 'inc/backdoor.php', '<?php // x' );
		$this->put_theme_file( 'acme', 'notes.txt', 'memo' );

		$made    = wpcv_test_make_fake_environment(
			null,
			null,
			null,
			null,
			$this->theme_source( array( 'acme' => self::ok_manifest( array( 'style.css' => 'css' ) ) ) )
		);
		$context = $this->context_with_themes( array( 'acme' => array( 'version' => '1.2' ) ) );

		$this->reserve_and_run( $made, $context );

		$scan = $this->find_target_run( $made, 'theme:acme:_scan' );
		$this->assertSame( WPCV_Target_Status::SUCCESS, $scan['status'] );
		$this->assertSame( 'theme', $scan['dimension'] );
		$this->assertSame( 'acme', $scan['slug'] );

		$findings = array_column( $this->findings_of( $made, 'theme:acme:_scan' ), null, 'path' );
		ksort( $findings );

		$this->assertSame( array( 'wp-content/themes/acme/inc/backdoor.php', 'wp-content/themes/acme/notes.txt' ), array_keys( $findings ) );
		$this->assertSame( 'added', $findings['wp-content/themes/acme/inc/backdoor.php']['status'] );
		$this->assertSame( 'high', $findings['wp-content/themes/acme/inc/backdoor.php']['severity'] );
		$this->assertSame( 'medium', $findings['wp-content/themes/acme/notes.txt']['severity'] );
		$this->assertSame( '1.2', $findings['wp-content/themes/acme/notes.txt']['version'] );

		unlink( $this->theme_dir( 'acme' ) . '/inc/backdoor.php' );
		unlink( $this->theme_dir( 'acme' ) . '/notes.txt' );

		$this->reserve_and_run( $made, $context );

		$this->assertSame( array(), $this->findings_of( $made, 'theme:acme:_scan', 2 ) );
	}

	/**
	 * コア同梱テーマでは、コアのマニフェストにだけあるファイル(zip には無い)を
	 * 未知として出さない(D7 と合わせた既知のファイルの集合. v0.7 §Step5).
	 *
	 * @return void
	 */
	public function test_scan_treats_core_only_files_of_bundled_theme_as_known() {
		$this->put_theme_file( 'acme', 'style.css', 'css' );
		$this->put_theme_file( 'acme', 'core-only.php', 'from core package' );
		$this->put_theme_file( 'acme', 'unknown.php', 'x' );

		$core = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array(
					'wp-content/themes/acme/core-only.php' => array(
						'algorithm' => 'md5',
						'hashes'    => array( md5( 'from core package' ) ),
					),
				),
			)
		);

		$made = wpcv_test_make_fake_environment(
			$core,
			null,
			null,
			null,
			$this->theme_source( array( 'acme' => self::ok_manifest( array( 'style.css' => 'css' ) ) ) )
		);

		$this->reserve_and_run( $made, $this->context_with_themes( array( 'acme' => array() ) ) );

		$this->assertSame(
			array( 'wp-content/themes/acme/unknown.php' ),
			array_column( $this->findings_of( $made, 'theme:acme:_scan' ), 'path' )
		);
	}

	/**
	 * 本体が照合できなかったテーマは `:_scan` を走査せず、本体の error_code を引き継いで
	 * skipped になる(追加されたファイルは `:_stat` が出すので二重に出さない. D8).
	 *
	 * @return void
	 */
	public function test_scan_is_skipped_when_body_is_not_verified() {
		$this->put_theme_file( 'custom', 'style.css', 'css' );
		$this->put_theme_file( 'custom', 'extra.php', '<?php' );

		$made = wpcv_test_make_fake_environment(
			null,
			null,
			null,
			null,
			$this->theme_source( array( 'custom' => self::missing_manifest( WPCV_Error_Code::MANIFEST_NOT_FOUND ) ) )
		);

		$this->reserve_and_run( $made, $this->context_with_themes( array( 'custom' => array() ) ) );

		$scan = $this->find_target_run( $made, 'theme:custom:_scan' );

		$this->assertSame( WPCV_Target_Status::SKIPPED, $scan['status'] );
		$this->assertSame( WPCV_Error_Code::MANIFEST_NOT_FOUND, $scan['error_code'] );
		$this->assertSame( array(), $this->findings_of( $made, 'theme:custom:_scan' ) );
	}

	/**
	 * 更新イベントを1件記録する(基準 run の開始より後の時刻. StatTargetDispatchTest と同じ).
	 *
	 * @param array  $made      `wpcv_test_make_fake_environment()` の戻り値.
	 * @param string $target_id target_id.
	 * @param string $version   記録する version.
	 * @return void
	 */
	private function insert_update_event( array $made, $target_id, $version ) {
		$later_now = static function () {
			return '2026-09-08 12:00:01';
		};

		( new WPCV_Update_Event_Repository( $made['wpdb'], $later_now ) )->insert( $target_id, $version, 'theme_update' );
	}

	/**
	 * v0.7 §Step6(D9・v0.6 D8): 照合できなかったテーマの `:_stat` は、本体の target_id
	 * (`theme:{stylesheet}`. `body_id_of_stat()` で求める)の更新イベントがあれば、version が
	 * 同じでも黙ってベースラインを作り直す.
	 *
	 * @return void
	 */
	public function test_theme_stat_rebuilds_silently_when_update_event_recorded() {
		$this->put_theme_file( 'custom', 'style.css', 'css' );

		$source  = $this->theme_source( array( 'custom' => self::missing_manifest( WPCV_Error_Code::MANIFEST_NOT_FOUND ) ) );
		$context = $this->context_with_themes( array( 'custom' => array( 'version' => '1.0' ) ) );
		$made    = wpcv_test_make_fake_environment( null, null, null, null, $source );

		$this->reserve_and_run( $made, $context );

		$this->insert_update_event( $made, 'theme:custom', '1.0' );
		file_put_contents( $this->theme_dir( 'custom' ) . '/style.css', 'css updated by WordPress' );
		clearstatcache();

		$second = $this->reserve_and_run( $made, $context );

		$this->assertSame( WPCV_Error_Code::BASELINE_REBUILT, $this->find_target_run( $made, 'theme:custom:_stat', $second['run_id'] )['error_code'] );
		$this->assertSame( array(), $this->findings_of( $made, 'theme:custom:_stat', $second['run_id'] ) );
	}

	/**
	 * v0.7 §Step6(D9・v0.6 D7): 記録の無い version の変化は、作り直さずに比べて
	 * `version_changed_unrecorded` を残す(テーマでも同じ).
	 *
	 * @return void
	 */
	public function test_theme_stat_compares_and_flags_unrecorded_version_change() {
		$this->put_theme_file( 'custom', 'style.css', 'css' );
		touch( $this->theme_dir( 'custom' ) . '/style.css', 1700000000 );

		$source = $this->theme_source( array( 'custom' => self::missing_manifest( WPCV_Error_Code::MANIFEST_NOT_FOUND ) ) );
		$made   = wpcv_test_make_fake_environment( null, null, null, null, $source );

		$this->reserve_and_run( $made, $this->context_with_themes( array( 'custom' => array( 'version' => '1.0' ) ) ) );

		$GLOBALS['_wpcv_test_options']['wpcv_update_events_since'] = '2020-01-01 00:00:00';
		file_put_contents( $this->theme_dir( 'custom' ) . '/style.css', 'css changed by hand' );
		touch( $this->theme_dir( 'custom' ) . '/style.css', 1700000100 );
		clearstatcache();

		$second = $this->reserve_and_run( $made, $this->context_with_themes( array( 'custom' => array( 'version' => '1.1' ) ) ) );

		$this->assertSame( WPCV_Error_Code::VERSION_CHANGED_UNRECORDED, $this->find_target_run( $made, 'theme:custom:_stat', $second['run_id'] )['error_code'] );
		$this->assertSame( array( 'stat_changed' ), array_column( $this->findings_of( $made, 'theme:custom:_stat', $second['run_id'] ), 'status' ) );
	}
}
