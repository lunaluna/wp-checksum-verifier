<?php
/**
 * WPCV_Manifest_Cache_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-manifest-cache-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `wpcv_manifest_cache` の永続化層のテスト(v0.7プラン §Step1).
 *
 * `save()` は `INSERT IGNORE` を `$wpdb->query()` で発行する. テストダブル
 * `WPCV_Test_Fake_WPDB` はこの形を解釈して `$rows` へ反映し、`unique_keys_by_table`
 * の列(source, slug, version)で重複を判定する(`tests/doubles.php` の
 * `apply_insert_ignore()` 参照).
 */
class ManifestCacheRepositoryTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * テスト用のマニフェスト(`{ path: { sha256, md5 } }` の形. D3).
	 *
	 * @return array<string, array{sha256: string, md5: string}>
	 */
	private function sample_files() {
		return array(
			'style.css'           => array(
				'sha256' => str_repeat( 'a', 64 ),
				'md5'    => str_repeat( 'b', 32 ),
			),
			'templates/index.html' => array(
				'sha256' => str_repeat( 'c', 64 ),
				'md5'    => str_repeat( 'd', 32 ),
			),
			"assets/it's (1).css" => array(
				'sha256' => str_repeat( 'e', 64 ),
				'md5'    => str_repeat( 'f', 32 ),
			),
		);
	}

	/**
	 * `save()` で保存したものを `find()` で同じ形のまま読み戻せることを確認する
	 * (パスに `/`・`'`・括弧を含む場合も壊れない).
	 *
	 * @return void
	 */
	public function test_save_then_find_round_trips_files() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Manifest_Cache_Repository(
			$wpdb,
			static function () {
				return '2026-10-01 12:00:00';
			}
		);

		$this->assertTrue( $repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfive', '1.5', $this->sample_files(), 7800000 ) );

		$found = $repository->find( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfive', '1.5' );

		$this->assertSame(
			array(
				'files'         => $this->sample_files(),
				'file_count'    => 3,
				'archive_bytes' => 7800000,
				'fetched_at'    => '2026-10-01 12:00:00',
			),
			$found
		);
	}

	/**
	 * `find()` が source・slug・version のどれか1つでも違えば `null` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_find_returns_null_when_key_does_not_match() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Manifest_Cache_Repository( $wpdb );

		$repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfive', '1.5', $this->sample_files() );

		$this->assertNull( $repository->find( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfive', '1.4' ) );
		$this->assertNull( $repository->find( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfour', '1.5' ) );
		$this->assertNull( $repository->find( WPCV_Manifest_Cache_Repository::SOURCE_CORE, 'twentytwentyfive', '1.5' ) );
	}

	/**
	 * 同じキーで2回 `save()` しても行は1つのままで、最初の中身が残ることを確認する
	 * (§3.1: 2つのワーカーが同じテーマを同時に取りに行った場合は後の書き込みを捨てる).
	 *
	 * @return void
	 */
	public function test_save_twice_with_same_key_keeps_first_row() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Manifest_Cache_Repository( $wpdb );

		$this->assertTrue( $repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfive', '1.5', $this->sample_files(), 100 ) );
		$this->assertTrue( $repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfive', '1.5', array(), 200 ) );

		$this->assertCount( 1, $wpdb->rows['wp_wpcv_manifest_cache'] );

		$found = $repository->find( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfive', '1.5' );

		$this->assertSame( 100, $found['archive_bytes'] );
		$this->assertCount( 3, $found['files'] );
	}

	/**
	 * slug・version が列の長さを超える場合は保存せず `false` を返すことを確認する
	 * (切り詰められて別のキーと衝突するのを防ぐ. `save()` の docblock 参照).
	 * ちょうど上限の長さは保存できる.
	 *
	 * @return void
	 */
	public function test_save_refuses_slug_or_version_longer_than_column() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Manifest_Cache_Repository( $wpdb );

		$this->assertFalse( $repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, str_repeat( 's', 101 ), '1.0', $this->sample_files() ) );
		$this->assertFalse( $repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'theme', str_repeat( '9', 65 ), $this->sample_files() ) );
		$this->assertArrayNotHasKey( 'wp_wpcv_manifest_cache', $wpdb->rows );
		$this->assertSame( array(), $wpdb->query_calls );

		$this->assertTrue( $repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, str_repeat( 's', 100 ), str_repeat( '9', 64 ), $this->sample_files() ) );
		$this->assertCount( 1, $wpdb->rows['wp_wpcv_manifest_cache'] );
	}

	/**
	 * 長さの判定はバイト数ではなく文字数で行うことを確認する(varchar の長さは
	 * 文字数のため. マルチバイトの slug 100文字は保存できる).
	 *
	 * @return void
	 */
	public function test_save_counts_length_in_characters_not_bytes() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Manifest_Cache_Repository( $wpdb );

		$this->assertTrue( $repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, str_repeat( 'あ', 100 ), '1.0', $this->sample_files() ) );
	}

	/**
	 * `save()` が `$wpdb->query()` の失敗を例外にすることを確認する.
	 *
	 * @return void
	 */
	public function test_save_throws_when_query_fails() {
		$wpdb                    = new WPCV_Test_Fake_WPDB();
		$wpdb->query_should_fail = true;
		$repository              = new WPCV_Manifest_Cache_Repository( $wpdb );

		$this->expectException( RuntimeException::class );

		$repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfive', '1.5', $this->sample_files() );
	}

	/**
	 * `files` 列の JSON が壊れている行は、`find()` が消して `null` を返すことを確認する
	 * (残すと `INSERT IGNORE` で上書きできず、毎回 zip を取り直し続けるため).
	 * 消したあとは `save()` で保存し直せる.
	 *
	 * @return void
	 */
	public function test_find_deletes_row_with_broken_json() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Manifest_Cache_Repository( $wpdb );

		$repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfive', '1.5', $this->sample_files() );

		$id = array_key_first( $wpdb->rows['wp_wpcv_manifest_cache'] );
		$wpdb->rows['wp_wpcv_manifest_cache'][ $id ]['files'] = '{"style.css":';

		$this->assertNull( $repository->find( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfive', '1.5' ) );
		$this->assertSame( array(), $wpdb->rows['wp_wpcv_manifest_cache'] );

		$repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfive', '1.5', $this->sample_files() );

		$this->assertNotNull( $repository->find( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'twentytwentyfive', '1.5' ) );
	}

	/**
	 * 空のマニフェスト(ファイル0件)も保存・復元できることを確認する
	 * (`json_encode( array() )` は `[]` になり、配列として読み戻せる).
	 *
	 * @return void
	 */
	public function test_save_and_find_empty_files() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Manifest_Cache_Repository( $wpdb );

		$repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'empty', '1.0', array() );

		$found = $repository->find( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'empty', '1.0' );

		$this->assertSame( array(), $found['files'] );
		$this->assertSame( 0, $found['file_count'] );
	}

	/**
	 * `delete_except()` が、指定した source のうち `$keep` に無い (slug, version) の
	 * 行だけを消し、他の source の行は残すことを確認する(D4).
	 *
	 * @return void
	 */
	public function test_delete_except_removes_only_unlisted_rows_of_source() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Manifest_Cache_Repository( $wpdb );

		$theme = WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME;

		$repository->save( $theme, 'twentytwentyfive', '1.4', $this->sample_files() );
		$repository->save( $theme, 'twentytwentyfive', '1.5', $this->sample_files() );
		$repository->save( $theme, 'removed-theme', '2.0', $this->sample_files() );
		$repository->save( WPCV_Manifest_Cache_Repository::SOURCE_CORE, 'en_US', '7.1.2', $this->sample_files() );

		$deleted = $repository->delete_except( $theme, array( array( 'twentytwentyfive', '1.5' ) ) );

		$this->assertSame( 2, $deleted );
		$this->assertNotNull( $repository->find( $theme, 'twentytwentyfive', '1.5' ) );
		$this->assertNull( $repository->find( $theme, 'twentytwentyfive', '1.4' ) );
		$this->assertNull( $repository->find( $theme, 'removed-theme', '2.0' ) );
		$this->assertNotNull( $repository->find( WPCV_Manifest_Cache_Repository::SOURCE_CORE, 'en_US', '7.1.2' ) );
	}

	/**
	 * `delete_except()` に空の `$keep` を渡すと、その source の行をすべて消すことを
	 * 確認する(テーマが1つも無くなった場合).
	 *
	 * @return void
	 */
	public function test_delete_except_with_empty_keep_removes_all_rows_of_source() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Manifest_Cache_Repository( $wpdb );

		$repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'a', '1.0', $this->sample_files() );
		$repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'b', '1.0', $this->sample_files() );

		$this->assertSame( 2, $repository->delete_except( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, array() ) );
		$this->assertSame( array(), $wpdb->rows['wp_wpcv_manifest_cache'] );
	}

	/**
	 * `delete_except()` は slug と version の組で判定し、slug の末尾と version の先頭を
	 * つないだ文字列が偶然一致しても取り違えないことを確認する
	 * (`a1` + `.0` と `a` + `1.0` を区別する).
	 *
	 * @return void
	 */
	public function test_delete_except_distinguishes_slug_version_boundary() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Manifest_Cache_Repository( $wpdb );

		$repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'a1', '.0', $this->sample_files() );

		$this->assertSame( 1, $repository->delete_except( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, array( array( 'a', '1.0' ) ) ) );
	}

	/**
	 * `delete_except()` が `$wpdb->delete()` の失敗を例外にすることを確認する.
	 *
	 * @return void
	 */
	public function test_delete_except_throws_when_delete_fails() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Manifest_Cache_Repository( $wpdb );

		$repository->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'a', '1.0', $this->sample_files() );

		$wpdb->delete_should_fail = true;

		$this->expectException( RuntimeException::class );

		$repository->delete_except( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, array() );
	}
}
