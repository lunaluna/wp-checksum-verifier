<?php
/**
 * WPCV_Update_Event_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-update-event-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `wpcv_update_events` の永続化層のテスト(v0.6プラン §Step1)。
 *
 * `find_matching()` はD5の突き合わせ条件(target_id・version一致 かつ event_at が
 * `$after` より後)そのものを検証する。テストダブル`WPCV_Test_Fake_WPDB`は
 * `=`・`IS NULL`・比較演算子(`<`・`<=`・`>`・`>=`)をANDで結んだWHERE句を解釈できる
 * ため(`tests/doubles.php` の `parse_where_conditions_strict()` 参照)、SQLの結果を
 * そのまま使える.
 */
class UpdateEventRepositoryTest extends TestCase {

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
	 * `insert()` が指定した列で1行追加し、採番した id を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_insert_adds_row_and_returns_id() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-09-29 10:00:00';
		} );

		$id = $repository->insert( 'plugin:acme-widgets', '1.2.0', 'plugin_update', 5 );

		$this->assertSame( 1, $id );

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertSame(
			array(
				'target_id'  => 'plugin:acme-widgets',
				'version'    => '1.2.0',
				'event_at'   => '2026-09-29 10:00:00',
				'source'     => 'plugin_update',
				'created_by' => 5,
				'id'         => 1,
			),
			$wpdb->rows[ $table ][1]
		);
	}

	/**
	 * `insert()` に `$version = null`(D3: 読み取れなかった場合)を渡すと、NULLの
	 * ままデータに保持されることを確認する(cron・CLI由来のイベントは `created_by`
	 * を明示せず既定の 0 になる).
	 *
	 * @return void
	 */
	public function test_insert_stores_null_version_and_defaults_created_by_to_zero() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );

		$repository->insert( 'core', null, 'core_update' );

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$row   = $wpdb->rows[ $table ][1];

		$this->assertNull( $row['version'] );
		$this->assertSame( 0, $row['created_by'] );
	}

	/**
	 * `insert()` が `$wpdb->insert()` の失敗を例外にすることを確認する
	 * (他のRepositoryと同じ規則. v0.4.0コードレビューCR-03参照).
	 *
	 * @return void
	 */
	public function test_insert_throws_when_wpdb_insert_fails() {
		$wpdb                     = new WPCV_Test_Fake_WPDB();
		$wpdb->insert_should_fail = true;
		$repository               = new WPCV_Update_Event_Repository( $wpdb );

		$this->expectException( RuntimeException::class );

		$repository->insert( 'plugin:acme-widgets', '1.2.0', 'plugin_update' );
	}

	/**
	 * D5の境界: `event_at` が `$after` より後の、target_id・versionが一致する行が
	 * 見つかることを確認する(通常の一致ケース).
	 *
	 * @return void
	 */
	public function test_find_matching_returns_row_when_event_after_threshold() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-09-29 10:00:01';
		} );

		$repository->insert( 'plugin:acme-widgets', '1.2.0', 'plugin_update' );

		$found = $repository->find_matching( 'plugin:acme-widgets', '1.2.0', '2026-09-29 10:00:00' );

		$this->assertCount( 1, $found );
	}

	/**
	 * D5の境界: `event_at` が基準の run 開始(`$after`)と**同時刻**の行は、
	 * 「より後」ではないため一致とみなさないことを確認する(`find_matching()` の
	 * docblock参照. `$after` 自体は基準run開始の時点で既に記録されていた更新を
	 * 意味し、今回のversion変化の理由にはならない).
	 *
	 * @return void
	 */
	public function test_find_matching_excludes_event_at_exact_threshold() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-09-29 10:00:00';
		} );

		$repository->insert( 'plugin:acme-widgets', '1.2.0', 'plugin_update' );

		$found = $repository->find_matching( 'plugin:acme-widgets', '1.2.0', '2026-09-29 10:00:00' );

		$this->assertSame( array(), $found );
	}

	/**
	 * D5の境界: version が一致しない行は対象外になることを確認する.
	 *
	 * @return void
	 */
	public function test_find_matching_excludes_version_mismatch() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-09-29 10:00:01';
		} );

		$repository->insert( 'plugin:acme-widgets', '1.2.0', 'plugin_update' );

		$found = $repository->find_matching( 'plugin:acme-widgets', '1.3.0', '2026-09-29 10:00:00' );

		$this->assertSame( array(), $found );
	}

	/**
	 * D5の境界: 今回のversionがNULL(mu-plugin loader等. version を持たない target)
	 * のとき、記録側もversion NULLの行だけを一致とみなすことを確認する.
	 *
	 * @return void
	 */
	public function test_find_matching_matches_null_version_on_both_sides() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-09-29 10:00:01';
		} );

		$repository->insert( 'core', null, 'core_update' );

		$found = $repository->find_matching( 'core', null, '2026-09-29 10:00:00' );

		$this->assertCount( 1, $found );
	}

	/**
	 * D5の境界: 記録側のversionがNULLで、今回のversionが非NULL(またはその逆)の
	 * 組み合わせは一致しないことを確認する.
	 *
	 * @return void
	 */
	public function test_find_matching_does_not_match_null_version_against_non_null() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-09-29 10:00:01';
		} );

		$repository->insert( 'core', null, 'core_update' );

		$found = $repository->find_matching( 'core', '6.9', '2026-09-29 10:00:00' );

		$this->assertSame( array(), $found );
	}

	/**
	 * D5の境界: target_id が異なる行は対象外になることを確認する.
	 *
	 * @return void
	 */
	public function test_find_matching_excludes_different_target_id() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-09-29 10:00:01';
		} );

		$repository->insert( 'plugin:acme-widgets', '1.2.0', 'plugin_update' );

		$found = $repository->find_matching( 'plugin:other-widgets', '1.2.0', '2026-09-29 10:00:00' );

		$this->assertSame( array(), $found );
	}

	/**
	 * `delete_older_than()` が、しきい値より古い行だけを削除し、新しい行を
	 * 残すことを確認する.
	 *
	 * @return void
	 */
	public function test_delete_older_than_removes_only_old_rows() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$old_repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-06-01 00:00:00';
		} );
		$old_repository->insert( 'plugin:old', '1.0.0', 'plugin_update' );

		$new_repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-09-25 00:00:00';
		} );
		$new_repository->insert( 'plugin:new', '1.0.0', 'plugin_update' );

		$now_repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-09-29 00:00:00';
		} );

		$deleted = $now_repository->delete_older_than( 90 );

		$this->assertSame( 1, $deleted );

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertCount( 1, $wpdb->rows[ $table ] );
		$this->assertSame( 'plugin:new', reset( $wpdb->rows[ $table ] )['target_id'] );
	}

	/**
	 * `delete_older_than()` が、削除対象が無ければ 0 を返し何も消さないことを確認する.
	 *
	 * @return void
	 */
	public function test_delete_older_than_returns_zero_when_nothing_to_delete() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-09-29 00:00:00';
		} );
		$repository->insert( 'plugin:new', '1.0.0', 'plugin_update' );

		$deleted = $repository->delete_older_than( 90 );

		$this->assertSame( 0, $deleted );

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertCount( 1, $wpdb->rows[ $table ] );
	}

	/**
	 * `delete_older_than()` が `$wpdb->delete()` の失敗を例外にすることを確認する.
	 *
	 * @return void
	 */
	public function test_delete_older_than_throws_when_wpdb_delete_fails() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$old_repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-06-01 00:00:00';
		} );
		$old_repository->insert( 'plugin:old', '1.0.0', 'plugin_update' );

		$wpdb->delete_should_fail = true;

		$now_repository = new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-09-29 00:00:00';
		} );

		$this->expectException( RuntimeException::class );

		$now_repository->delete_older_than( 90 );
	}
}
