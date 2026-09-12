<?php
/**
 * WPCV_Chunk_Verifier のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-budget.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-cursor.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-verifier.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Chunk_Verifier`(v0.4.0 §Step3)のテスト.
 *
 * ABSPATH(tests/fixtures/fake-root/)配下に実ファイルを作って検証する
 * (`VerifierTest`/`RunCoordinatorTest` と同じ方式).
 */
class ChunkVerifierTest extends TestCase {

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
	 * 基本のコンテキスト(target情報)を組み立てる.
	 *
	 * @param array $overrides 上書きするフィールド.
	 * @return array
	 */
	private function base_context( array $overrides = array() ) {
		return array_merge(
			array(
				'target_id' => 'plugin:sample',
				'dimension' => 'plugin',
				'slug'      => 'sample',
				'version'   => '1.0',
				'source'    => 'wporg',
				'base_dir'  => ABSPATH . 'wp-content/plugins/sample',
			),
			$overrides
		);
	}

	/**
	 * 全ファイルが一致する場合、findingsが空で `completed: true` を返し、
	 * `cursor_path` が null(完走)になることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_manifest_chunk_completes_when_all_files_match() {
		$this->put_fixture_file( 'wp-content/plugins/sample/a.php', 'A' );
		$this->put_fixture_file( 'wp-content/plugins/sample/b.php', 'B' );

		$manifest_files = array(
			'a.php' => $this->sha256_entry( 'A' ),
			'b.php' => $this->sha256_entry( 'B' ),
		);

		$verifier = new WPCV_Chunk_Verifier();
		$result   = $verifier->verify_manifest_chunk(
			$this->base_context( array( 'manifest_files' => $manifest_files ) )
		);

		$this->assertTrue( $result['completed'] );
		$this->assertSame( array(), $result['findings'] );
		$this->assertNull( $result['cursor_path'] );
		$this->assertSame( 2, $result['files_verified_delta'] );
		$this->assertSame( 2, $result['files_total'] );
		$this->assertFalse( $result['needs_retry'] );
	}

	/**
	 * 改ざんされたファイルが1件あれば `modified` finding が生成されることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_manifest_chunk_detects_modified_file() {
		$this->put_fixture_file( 'wp-content/plugins/sample/a.php', 'TAMPERED' );

		$manifest_files = array( 'a.php' => $this->sha256_entry( 'ORIGINAL' ) );

		$verifier = new WPCV_Chunk_Verifier();
		$result   = $verifier->verify_manifest_chunk(
			$this->base_context( array( 'manifest_files' => $manifest_files ) )
		);

		$this->assertCount( 1, $result['findings'] );
		$this->assertSame( 'modified', $result['findings'][0]['status'] );
		$this->assertSame( 0, $result['files_verified_delta'] );
	}

	/**
	 * `cursor_path` を指定すると、それより後(sort順)のファイルのみ処理されることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_manifest_chunk_resumes_after_cursor_path() {
		$this->put_fixture_file( 'wp-content/plugins/sample/a.php', 'A' );
		$this->put_fixture_file( 'wp-content/plugins/sample/b.php', 'TAMPERED' );

		$manifest_files = array(
			'a.php' => $this->sha256_entry( 'A' ),
			'b.php' => $this->sha256_entry( 'ORIGINAL' ),
		);

		$verifier = new WPCV_Chunk_Verifier();
		$result   = $verifier->verify_manifest_chunk(
			$this->base_context(
				array(
					'manifest_files' => $manifest_files,
					'cursor_path'    => 'a.php',
				)
			)
		);

		// a.php は cursor より前なので再処理されず、b.php(modified)のみ処理される.
		$this->assertCount( 1, $result['findings'] );
		$this->assertSame( 0, $result['files_verified_delta'] );
		$this->assertTrue( $result['completed'] );
	}

	/**
	 * `budget.max_files` に達すると、途中で打ち切り `completed: false` を返し、
	 * `cursor_path` が最後に処理したファイルを指すことを確認する.
	 *
	 * @return void
	 */
	public function test_verify_manifest_chunk_stops_at_max_files_budget() {
		$this->put_fixture_file( 'wp-content/plugins/sample/a.php', 'A' );
		$this->put_fixture_file( 'wp-content/plugins/sample/b.php', 'B' );
		$this->put_fixture_file( 'wp-content/plugins/sample/c.php', 'C' );

		$manifest_files = array(
			'a.php' => $this->sha256_entry( 'A' ),
			'b.php' => $this->sha256_entry( 'B' ),
			'c.php' => $this->sha256_entry( 'C' ),
		);

		$verifier = new WPCV_Chunk_Verifier();
		$result   = $verifier->verify_manifest_chunk(
			$this->base_context(
				array(
					'manifest_files' => $manifest_files,
					'budget'         => array( 'max_files' => 2 ),
				)
			)
		);

		$this->assertFalse( $result['completed'] );
		$this->assertSame( 'b.php', $result['cursor_path'] );
		$this->assertSame( 2, $result['files_verified_delta'] );
		$this->assertSame( 3, $result['files_total'] );
	}

	/**
	 * `budget.max_seconds` に達すると打ち切られることを確認する
	 * (`$now` callable を注入して経過時間を制御).
	 *
	 * @return void
	 */
	public function test_verify_manifest_chunk_stops_at_max_seconds_budget() {
		$this->put_fixture_file( 'wp-content/plugins/sample/a.php', 'A' );
		$this->put_fixture_file( 'wp-content/plugins/sample/b.php', 'B' );

		$manifest_files = array(
			'a.php' => $this->sha256_entry( 'A' ),
			'b.php' => $this->sha256_entry( 'B' ),
		);

		// 1回目の呼び出し(開始時刻)は 0.0、以降は常に 100.0(経過100秒)を返す.
		$call_count = 0;
		$now        = function () use ( &$call_count ) {
			return 0 === $call_count++ ? 0.0 : 100.0;
		};

		$verifier = new WPCV_Chunk_Verifier( $now );
		$result   = $verifier->verify_manifest_chunk(
			$this->base_context(
				array(
					'manifest_files' => $manifest_files,
					'budget'         => array( 'max_seconds' => 10 ),
				)
			)
		);

		$this->assertFalse( $result['completed'] );
		$this->assertSame( 'a.php', $result['cursor_path'] );
	}

	/**
	 * `budget.memory_limit_bytes` に達すると打ち切られることを確認する
	 * (`$memory_usage` callable を注入).
	 *
	 * @return void
	 */
	public function test_verify_manifest_chunk_stops_at_memory_budget() {
		$this->put_fixture_file( 'wp-content/plugins/sample/a.php', 'A' );
		$this->put_fixture_file( 'wp-content/plugins/sample/b.php', 'B' );

		$manifest_files = array(
			'a.php' => $this->sha256_entry( 'A' ),
			'b.php' => $this->sha256_entry( 'B' ),
		);

		$verifier = new WPCV_Chunk_Verifier(
			null,
			static function () {
				return 950;
			}
		);

		$result = $verifier->verify_manifest_chunk(
			$this->base_context(
				array(
					'manifest_files' => $manifest_files,
					'budget'         => array(
						'memory_limit_bytes'     => 1000,
						'memory_threshold_ratio' => 0.9,
					),
				)
			)
		);

		$this->assertFalse( $result['completed'] );
		$this->assertSame( 'a.php', $result['cursor_path'] );
	}

	/**
	 * fingerprint が前回と異なる場合、実際の比較を行わず `needs_retry: true` を
	 * 返すことを確認する(§Step3「resume時にfingerprintが変わっていたらchunk結果を
	 * 確定せずretryへ戻す」).
	 *
	 * @return void
	 */
	public function test_verify_manifest_chunk_returns_needs_retry_when_fingerprint_changed() {
		$this->put_fixture_file( 'wp-content/plugins/sample/a.php', 'A' );

		$manifest_files = array( 'a.php' => $this->sha256_entry( 'A' ) );

		$verifier = new WPCV_Chunk_Verifier();
		$result   = $verifier->verify_manifest_chunk(
			$this->base_context(
				array(
					'manifest_files'       => $manifest_files,
					'previous_fingerprint' => 'stale-fingerprint-that-will-never-match',
				)
			)
		);

		$this->assertTrue( $result['needs_retry'] );
		$this->assertTrue( $result['fingerprint_changed'] );
		$this->assertFalse( $result['version_changed'] );
		$this->assertSame( array(), $result['findings'] );
		$this->assertFalse( $result['completed'] );
	}

	/**
	 * version が前回と異なる場合も `needs_retry: true` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_verify_manifest_chunk_returns_needs_retry_when_version_changed() {
		$this->put_fixture_file( 'wp-content/plugins/sample/a.php', 'A' );

		$manifest_files = array( 'a.php' => $this->sha256_entry( 'A' ) );

		$verifier = new WPCV_Chunk_Verifier();
		$result   = $verifier->verify_manifest_chunk(
			$this->base_context(
				array(
					'manifest_files'   => $manifest_files,
					'previous_version' => '0.9',
				)
			)
		);

		$this->assertTrue( $result['needs_retry'] );
		$this->assertTrue( $result['version_changed'] );
		$this->assertFalse( $result['fingerprint_changed'] );
	}

	/**
	 * 初回(previous_fingerprint/previous_versionがnull)は比較をスキップし、
	 * 通常通り検証が進むことを確認する.
	 *
	 * @return void
	 */
	public function test_verify_manifest_chunk_skips_change_detection_on_first_run() {
		$this->put_fixture_file( 'wp-content/plugins/sample/a.php', 'A' );

		$manifest_files = array( 'a.php' => $this->sha256_entry( 'A' ) );

		$verifier = new WPCV_Chunk_Verifier();
		$result   = $verifier->verify_manifest_chunk(
			$this->base_context( array( 'manifest_files' => $manifest_files ) )
		);

		$this->assertFalse( $result['needs_retry'] );
		$this->assertTrue( $result['completed'] );
	}

	/**
	 * 未知ファイル走査のchunk処理が、scan結果を `added` finding に変換することを確認する.
	 *
	 * @return void
	 */
	public function test_verify_unknown_files_chunk_converts_scan_items_to_findings() {
		$this->put_fixture_file( 'wp-content/mu-plugins/vendor/backdoor.php', 'evil' );

		$scan_items = array(
			array(
				'path'     => 'wp-content/mu-plugins/vendor/backdoor.php',
				'severity' => 'high',
			),
		);

		$verifier = new WPCV_Chunk_Verifier();
		$result   = $verifier->verify_unknown_files_chunk(
			array(
				'target_id'  => 'muplugin:_scan',
				'dimension'  => 'muplugin',
				'slug'       => '_scan',
				'version'    => null,
				'source'     => null,
				'scan_items' => $scan_items,
			)
		);

		$this->assertTrue( $result['completed'] );
		$this->assertCount( 1, $result['findings'] );
		$this->assertSame( 'added', $result['findings'][0]['status'] );
		$this->assertSame( 'wp-content/mu-plugins/vendor/backdoor.php', $result['findings'][0]['path'] );
	}

	/**
	 * 未知ファイル走査でも `budget.max_files` による打ち切りが効くことを確認する.
	 *
	 * @return void
	 */
	public function test_verify_unknown_files_chunk_stops_at_max_files_budget() {
		$this->put_fixture_file( 'wp-content/mu-plugins/a.php', 'a' );
		$this->put_fixture_file( 'wp-content/mu-plugins/b.php', 'b' );

		$scan_items = array(
			array(
				'path'     => 'wp-content/mu-plugins/a.php',
				'severity' => 'high',
			),
			array(
				'path'     => 'wp-content/mu-plugins/b.php',
				'severity' => 'high',
			),
		);

		$verifier = new WPCV_Chunk_Verifier();
		$result   = $verifier->verify_unknown_files_chunk(
			array(
				'target_id'  => 'muplugin:_scan',
				'dimension'  => 'muplugin',
				'slug'       => '_scan',
				'scan_items' => $scan_items,
				'budget'     => array( 'max_files' => 1 ),
			)
		);

		$this->assertFalse( $result['completed'] );
		$this->assertCount( 1, $result['findings'] );
		$this->assertSame( 'wp-content/mu-plugins/a.php', $result['cursor_path'] );
	}

	/**
	 * 未知ファイル走査でも fingerprint 不一致時に `needs_retry: true` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_verify_unknown_files_chunk_returns_needs_retry_when_fingerprint_changed() {
		$scan_items = array(
			array(
				'path'     => 'wp-content/mu-plugins/a.php',
				'severity' => 'high',
			),
		);

		$verifier = new WPCV_Chunk_Verifier();
		$result   = $verifier->verify_unknown_files_chunk(
			array(
				'target_id'            => 'muplugin:_scan',
				'dimension'            => 'muplugin',
				'slug'                 => '_scan',
				'scan_items'           => $scan_items,
				'previous_fingerprint' => 'stale-fingerprint-that-will-never-match',
			)
		);

		$this->assertTrue( $result['needs_retry'] );
		$this->assertTrue( $result['fingerprint_changed'] );
	}
}
