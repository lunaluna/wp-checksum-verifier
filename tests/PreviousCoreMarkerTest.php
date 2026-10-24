<?php
/**
 * WPCV_Previous_Core_Marker のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-finding-key.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-generation-differ.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-previous-core-marker.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Previous_Core_Marker`(v0.11.0 項目3-1)のテスト.
 *
 * クラスの docblock にある判定の組み合わせ表の各行を1つずつ確かめる.
 * 中身を読むファイルは ABSPATH(`tests/fixtures/fake-root/`)の下に作り、テストの後で消す.
 */
class PreviousCoreMarkerTest extends TestCase {

	/**
	 * テスト用のファイルを置くディレクトリ(ABSPATH 相対).
	 *
	 * @var string
	 */
	const DIR = 'wpcv-marker-test';

	/**
	 * 今回の run の id.
	 *
	 * @var int
	 */
	const RUN_ID = 10;

	/**
	 * フェイク wpdb.
	 *
	 * @var WPCV_Test_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * 前のマニフェストを取った回数を数えるソース.
	 *
	 * @var WPCV_Manifest_Source
	 */
	private $source;

	/**
	 * 作ったファイルの絶対パス(後で消す).
	 *
	 * @var string[]
	 */
	private $created_files = array();

	/**
	 * 各テストの前にフェイク wpdb と、数えるソースを用意する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb = new WPCV_Test_Fake_WPDB();
		$this->use_manifest( array() );
	}

	/**
	 * 作ったファイルとディレクトリを消す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->created_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		if ( is_dir( ABSPATH . self::DIR ) ) {
			rmdir( ABSPATH . self::DIR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		parent::tearDown();
	}

	/**
	 * 新規で、前の version の配布物と同じ内容(md5 一致・sha256 も走査の時点と一致)なら印を付ける.
	 *
	 * @return void
	 */
	public function test_marks_new_finding_that_matches_previous_release() {
		$finding = $this->make_file_and_finding( 'leftover.css', 'body{}' );
		$this->add_core_baseline( '7.1.3' );
		$this->use_manifest( array( self::DIR . '/leftover.css' => md5( 'body{}' ) ) );

		$result = $this->marker()->mark( self::RUN_ID, array( $finding ), '7.2-beta1' );

		$this->assertSame( '7.1.3', WPCV_Previous_Core_Marker::marked_version( $result[0]['detail'] ?? null ) );
		$this->assertSame( 1, $this->source->calls );
	}

	/**
	 * 前の一覧の md5 と違えば印を付けない(同じパスでも中身が違う).
	 *
	 * @return void
	 */
	public function test_does_not_mark_when_md5_differs() {
		$finding = $this->make_file_and_finding( 'leftover.css', 'body{}' );
		$this->add_core_baseline( '7.1.3' );
		$this->use_manifest( array( self::DIR . '/leftover.css' => md5( 'changed' ) ) );

		$result = $this->marker()->mark( self::RUN_ID, array( $finding ), '7.2-beta1' );

		$this->assertArrayNotHasKey( 'detail', $result[0] );
	}

	/**
	 * 前の一覧にそのパスが無ければ印を付けない(本体の消し残しではない追加ファイル).
	 *
	 * @return void
	 */
	public function test_does_not_mark_when_path_is_not_in_previous_release() {
		$finding = $this->make_file_and_finding( 'wordfence-waf.php', '<?php' );
		$this->add_core_baseline( '7.1.3' );
		$this->use_manifest( array( 'wp-includes/version.php' => md5( 'x' ) ) );

		$result = $this->marker()->mark( self::RUN_ID, array( $finding ), '7.2-beta1' );

		$this->assertArrayNotHasKey( 'detail', $result[0] );
	}

	/**
	 * 走査のあとで中身が変わった(読み直した sha256 が finding と違う)なら印を付けない.
	 *
	 * @return void
	 */
	public function test_does_not_mark_when_file_changed_after_scan() {
		$finding                = $this->make_file_and_finding( 'leftover.css', 'body{}' );
		$finding['actual_hash'] = hash( 'sha256', 'scanned-before-change' );
		$this->add_core_baseline( '7.1.3' );
		$this->use_manifest( array( self::DIR . '/leftover.css' => md5( 'body{}' ) ) );

		$result = $this->marker()->mark( self::RUN_ID, array( $finding ), '7.2-beta1' );

		$this->assertArrayNotHasKey( 'detail', $result[0] );
	}

	/**
	 * 前の本体の version が今と同じなら判定せず、マニフェストも取らない.
	 *
	 * @return void
	 */
	public function test_skips_when_core_version_did_not_change() {
		$finding = $this->make_file_and_finding( 'leftover.css', 'body{}' );
		$this->add_core_baseline( '7.2-beta1' );

		$result = $this->marker()->mark( self::RUN_ID, array( $finding ), '7.2-beta1' );

		$this->assertArrayNotHasKey( 'detail', $result[0] );
		$this->assertSame( 0, $this->source->calls );
	}

	/**
	 * `core` の基準が無い(初回の run)なら判定しない.
	 *
	 * @return void
	 */
	public function test_skips_when_there_is_no_core_baseline() {
		$finding = $this->make_file_and_finding( 'leftover.css', 'body{}' );

		$result = $this->marker()->mark( self::RUN_ID, array( $finding ), '7.2-beta1' );

		$this->assertArrayNotHasKey( 'detail', $result[0] );
		$this->assertSame( 0, $this->source->calls );
	}

	/**
	 * 前のマニフェストが取れなければ印を付けず、同じ version は2回目から取りに行かない.
	 *
	 * @return void
	 */
	public function test_does_not_mark_and_fetches_once_when_manifest_is_unavailable() {
		$finding = $this->make_file_and_finding( 'leftover.css', 'body{}' );
		$this->add_core_baseline( '7.1.3' );
		$this->use_manifest( null );

		$marker = $this->marker();
		$first  = $marker->mark( self::RUN_ID, array( $finding ), '7.2-beta1' );
		$second = $marker->mark( self::RUN_ID, array( $finding ), '7.2-beta1' );

		$this->assertArrayNotHasKey( 'detail', $first[0] );
		$this->assertArrayNotHasKey( 'detail', $second[0] );
		$this->assertSame( 1, $this->source->calls );
	}

	/**
	 * 継続(基準に同じキーがある)なら、基準の印を引き継ぎ、判定はしない
	 * (beta1 → beta2 と続けて更新しても印が残る).
	 *
	 * @return void
	 */
	public function test_continuing_finding_inherits_baseline_mark_without_judging() {
		$finding = $this->make_file_and_finding( 'leftover.css', 'body{}' );
		$this->add_core_baseline( '7.2-beta1' );
		$this->add_scan_baseline_finding( $finding, '{"same_as_core":"7.1.3"}' );

		$result = $this->marker()->mark( self::RUN_ID, array( $finding ), '7.2-beta2' );

		$this->assertSame( '7.1.3', WPCV_Previous_Core_Marker::marked_version( $result[0]['detail'] ?? null ) );
		$this->assertSame( 0, $this->source->calls );
	}

	/**
	 * 継続で基準に印が無ければ、本体の version が変わっていても印を付けない(判定しない).
	 *
	 * @return void
	 */
	public function test_continuing_finding_without_baseline_mark_stays_unmarked() {
		$finding = $this->make_file_and_finding( 'leftover.css', 'body{}' );
		$this->add_core_baseline( '7.1.3' );
		$this->add_scan_baseline_finding( $finding, null );
		$this->use_manifest( array( self::DIR . '/leftover.css' => md5( 'body{}' ) ) );

		$result = $this->marker()->mark( self::RUN_ID, array( $finding ), '7.2-beta1' );

		$this->assertArrayNotHasKey( 'detail', $result[0] );
		$this->assertSame( 0, $this->source->calls );
	}

	/**
	 * `core:_scan` 以外の finding は変えない.
	 *
	 * @return void
	 */
	public function test_leaves_other_targets_untouched() {
		$finding              = $this->make_file_and_finding( 'leftover.css', 'body{}' );
		$finding['target_id'] = 'muplugin:_scan';
		$this->add_core_baseline( '7.1.3' );
		$this->use_manifest( array( self::DIR . '/leftover.css' => md5( 'body{}' ) ) );

		$result = $this->marker()->mark( self::RUN_ID, array( $finding ), '7.2-beta1' );

		$this->assertSame( array( $finding ), $result );
	}

	/**
	 * `marked_version()` は印の JSON だけを読み、他の形(stat の detail・壊れた JSON)は null にする.
	 *
	 * @return void
	 */
	public function test_marked_version_reads_only_the_mark() {
		$this->assertSame( '7.1.3', WPCV_Previous_Core_Marker::marked_version( '{"same_as_core":"7.1.3"}' ) );
		$this->assertNull( WPCV_Previous_Core_Marker::marked_version( null ) );
		$this->assertNull( WPCV_Previous_Core_Marker::marked_version( '' ) );
		$this->assertNull( WPCV_Previous_Core_Marker::marked_version( '{"size":{"old":1,"new":2}}' ) );
		$this->assertNull( WPCV_Previous_Core_Marker::marked_version( '{"same_as_core":""}' ) );
		$this->assertNull( WPCV_Previous_Core_Marker::marked_version( 'not json' ) );
	}

	/**
	 * テスト対象を組み立てる.
	 *
	 * @return WPCV_Previous_Core_Marker
	 */
	private function marker() {
		return new WPCV_Previous_Core_Marker(
			new WPCV_Finding_Repository( $this->wpdb ),
			new WPCV_Target_Run_Repository( $this->wpdb ),
			$this->source
		);
	}

	/**
	 * 前のマニフェストを返すソースを差し替える(null なら取得に失敗する).
	 *
	 * @param array<string, string>|null $md5_by_path パス => md5. null なら manifest_not_found.
	 * @return void
	 */
	private function use_manifest( $md5_by_path ) {
		$this->source = new class( $md5_by_path ) implements WPCV_Manifest_Source {

			/**
			 * `get_manifest()` が呼ばれた回数.
			 *
			 * @var int
			 */
			public $calls = 0;

			/**
			 * パス => md5(null なら取得に失敗する).
			 *
			 * @var array<string, string>|null
			 */
			private $md5_by_path;

			/**
			 * コンストラクタ.
			 *
			 * @param array<string, string>|null $md5_by_path パス => md5.
			 */
			public function __construct( $md5_by_path ) {
				$this->md5_by_path = $md5_by_path;
			}

			/**
			 * `WPCV_Source_Core::get_manifest()` と同じ形を返す.
			 *
			 * @param array $context 無視する.
			 * @return array
			 */
			public function get_manifest( array $context ) {
				unset( $context );
				++$this->calls;

				if ( null === $this->md5_by_path ) {
					return array(
						'manifest_status' => 'missing',
						'error_code'      => WPCV_Error_Code::MANIFEST_NOT_FOUND,
						'files'           => array(),
					);
				}

				$files = array();

				foreach ( $this->md5_by_path as $path => $md5 ) {
					$files[ $path ] = array(
						'algorithm' => 'md5',
						'hashes'    => array( $md5 ),
					);
				}

				return array(
					'manifest_status' => 'cached',
					'error_code'      => null,
					'files'           => $files,
				);
			}
		};
	}

	/**
	 * ABSPATH の下にファイルを作り、それに対応する `core:_scan` の added の finding を返す.
	 *
	 * @param string $name    ファイル名.
	 * @param string $content 中身.
	 * @return array
	 */
	private function make_file_and_finding( $name, $content ) {
		if ( ! is_dir( ABSPATH . self::DIR ) ) {
			mkdir( ABSPATH . self::DIR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		$path = self::DIR . '/' . $name;
		file_put_contents( ABSPATH . $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->created_files[] = ABSPATH . $path;

		return array(
			'target_id'      => 'core:_scan',
			'dimension'      => 'core',
			'slug'           => '_scan',
			'version'        => '7.2-beta1',
			'source'         => 'wporg',
			'path'           => $path,
			'status'         => 'added',
			'severity'       => 'high',
			'hash_algorithm' => 'sha256',
			'expected_hash'  => null,
			'actual_hash'    => hash( 'sha256', $content ),
			'file_size'      => strlen( $content ),
		);
	}

	/**
	 * `core` の基準の target_run(今回より前の success)を入れる.
	 *
	 * @param string $version 前の本体の version.
	 * @return void
	 */
	private function add_core_baseline( $version ) {
		$this->wpdb->insert(
			'wp_wpcv_target_runs',
			array(
				'run_id'    => self::RUN_ID - 1,
				'target_id' => 'core',
				'status'    => 'success',
				'version'   => $version,
			)
		);
	}

	/**
	 * `core:_scan` の基準の target_run と、そこに同じキーの finding を入れる.
	 *
	 * @param array       $finding 同じキーにする finding.
	 * @param string|null $detail  基準の finding の `detail`.
	 * @return void
	 */
	private function add_scan_baseline_finding( array $finding, $detail ) {
		$this->wpdb->insert(
			'wp_wpcv_target_runs',
			array(
				'run_id'    => self::RUN_ID - 1,
				'target_id' => 'core:_scan',
				'status'    => 'success',
				'version'   => null,
			)
		);
		$target_run_id = $this->wpdb->insert_id;

		$this->wpdb->insert(
			'wp_wpcv_findings',
			array(
				'run_id'        => self::RUN_ID - 1,
				'target_run_id' => $target_run_id,
				'target_id'     => 'core:_scan',
				'finding_key'   => WPCV_Finding_Key::compute( 'core:_scan', '', $finding['path'], 'added', 'sha256', null, $finding['actual_hash'] ),
				'detail'        => $detail,
			)
		);
	}
}
