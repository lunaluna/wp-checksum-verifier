<?php
/**
 * WPCV_Chunk_Cursor のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-cursor.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Chunk_Cursor`(v0.4.0 §Step3)のテスト.
 */
class ChunkCursorTest extends TestCase {

	/**
	 * `sorted_safe_paths()` が manifest の path をアルファベット順に並べ、
	 * 挿入順に依存しないことを確認する.
	 *
	 * @return void
	 */
	public function test_sorted_safe_paths_sorts_regardless_of_insertion_order() {
		$manifest_files = array(
			'wp-includes/version.php' => array(
				'algorithm' => 'sha256',
				'hashes'    => array( 'a' ),
			),
			'index.php'               => array(
				'algorithm' => 'sha256',
				'hashes'    => array( 'b' ),
			),
			'wp-admin/index.php'      => array(
				'algorithm' => 'sha256',
				'hashes'    => array( 'c' ),
			),
		);

		$this->assertSame(
			array( 'index.php', 'wp-admin/index.php', 'wp-includes/version.php' ),
			WPCV_Chunk_Cursor::sorted_safe_paths( $manifest_files )
		);
	}

	/**
	 * `..` を含む不正なpathが除外されることを確認する
	 * (`WPCV_Verifier::compare_files()` と同じ方針. §12.5).
	 *
	 * @return void
	 */
	public function test_sorted_safe_paths_excludes_unsafe_paths() {
		$manifest_files = array(
			'index.php'    => array(
				'algorithm' => 'sha256',
				'hashes'    => array( 'a' ),
			),
			'../etc/passwd' => array(
				'algorithm' => 'sha256',
				'hashes'    => array( 'b' ),
			),
		);

		$this->assertSame(
			array( 'index.php' ),
			WPCV_Chunk_Cursor::sorted_safe_paths( $manifest_files )
		);
	}

	/**
	 * `sorted_scan_paths()` が `WPCV_Unknown_File_Scanner::scan()` の戻り値から
	 * path のみをソート済みで取り出すことを確認する.
	 *
	 * @return void
	 */
	public function test_sorted_scan_paths_sorts_paths_only() {
		$items = array(
			array(
				'path'     => 'wp-content/mu-plugins/z.php',
				'severity' => 'high',
			),
			array(
				'path'     => 'wp-content/mu-plugins/a.php',
				'severity' => 'high',
			),
		);

		$this->assertSame(
			array( 'wp-content/mu-plugins/a.php', 'wp-content/mu-plugins/z.php' ),
			WPCV_Chunk_Cursor::sorted_scan_paths( $items )
		);
	}

	/**
	 * 同じ対象集合からは常に同じ fingerprint が計算されることを確認する
	 * (順序が入力の並びに依存しないことの確認. `sorted_safe_paths()` で
	 * 事前にソート済みの配列を渡す前提).
	 *
	 * @return void
	 */
	public function test_compute_fingerprint_is_deterministic_for_same_input() {
		$identifiers = array( 'a.php|sha256|hash1', 'b.php|sha256|hash2' );

		$this->assertSame(
			WPCV_Chunk_Cursor::compute_fingerprint( $identifiers ),
			WPCV_Chunk_Cursor::compute_fingerprint( $identifiers )
		);
	}

	/**
	 * hash 値が変わると fingerprint も変わることを確認する
	 * (対象集合の変化を検知できることの確認).
	 *
	 * @return void
	 */
	public function test_compute_fingerprint_changes_when_hash_differs() {
		$before = WPCV_Chunk_Cursor::compute_fingerprint( array( 'a.php|sha256|hash1' ) );
		$after  = WPCV_Chunk_Cursor::compute_fingerprint( array( 'a.php|sha256|hash2' ) );

		$this->assertNotSame( $before, $after );
	}

	/**
	 * `manifest_identifiers()` が `path|algorithm|hashes` 形式の識別子を、
	 * 複数hash(`hashes`が複数件)もカンマ区切りで含めて組み立てることを確認する.
	 *
	 * @return void
	 */
	public function test_manifest_identifiers_builds_path_algorithm_hashes_string() {
		$manifest_files = array(
			'index.php' => array(
				'algorithm' => 'md5',
				'hashes'    => array( 'hash1', 'hash2' ),
			),
		);

		$this->assertSame(
			array( 'index.php|md5|hash1,hash2' ),
			WPCV_Chunk_Cursor::manifest_identifiers( $manifest_files, array( 'index.php' ) )
		);
	}

	/**
	 * `paths_after()` が cursor_path より後(sort順で厳密に大きい)のpathのみを
	 * 返すことを確認する.
	 *
	 * @return void
	 */
	public function test_paths_after_returns_only_paths_strictly_after_cursor() {
		$sorted = array( 'a.php', 'b.php', 'c.php', 'd.php' );

		$this->assertSame(
			array( 'c.php', 'd.php' ),
			WPCV_Chunk_Cursor::paths_after( $sorted, 'b.php' )
		);
	}

	/**
	 * cursor_path が null の場合、全件を返すことを確認する(最初から処理する場合).
	 *
	 * @return void
	 */
	public function test_paths_after_returns_all_when_cursor_is_null() {
		$sorted = array( 'a.php', 'b.php' );

		$this->assertSame( $sorted, WPCV_Chunk_Cursor::paths_after( $sorted, null ) );
	}

	/**
	 * cursor_path が末尾のpathと一致する場合、空配列を返す(全件処理済み)ことを確認する.
	 *
	 * @return void
	 */
	public function test_paths_after_returns_empty_when_cursor_is_last_path() {
		$sorted = array( 'a.php', 'b.php' );

		$this->assertSame( array(), WPCV_Chunk_Cursor::paths_after( $sorted, 'b.php' ) );
	}

	/**
	 * cursor_path が現在の対象集合に存在しない場合(対象集合が変わった等)、
	 * 安全側に倒して全件を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_paths_after_returns_all_when_cursor_not_found() {
		$sorted = array( 'a.php', 'b.php' );

		$this->assertSame( $sorted, WPCV_Chunk_Cursor::paths_after( $sorted, 'not-in-set.php' ) );
	}
}
