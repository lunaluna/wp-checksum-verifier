<?php
/**
 * WPCV_Suppression_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-suppression-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Suppression_Repository`(v0.4.0 §Step8)のテスト.
 */
class SuppressionRepositoryTest extends TestCase {

	/**
	 * `type`/`reason` を渡さないと例外を投げることを確認する.
	 *
	 * @return void
	 */
	public function test_insert_throws_when_type_missing() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$this->expectException( InvalidArgumentException::class );

		$repository->insert(
			array(
				'reason'     => 'r',
				'created_by' => 1,
			)
		);
	}

	/**
	 * `reason` を渡さないと例外を投げることを確認する.
	 *
	 * @return void
	 */
	public function test_insert_throws_when_reason_missing() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$this->expectException( InvalidArgumentException::class );

		$repository->insert( array( 'type' => WPCV_Suppression_Type::EXCLUDE_TARGET ) );
	}

	/**
	 * 挿入した行を `find_by_id()` でそのまま読み取れることを確認する.
	 *
	 * @return void
	 */
	public function test_insert_and_find_by_id_round_trip() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$id = $repository->insert(
			array(
				'type'       => WPCV_Suppression_Type::EXCLUDE_PATH,
				'dimension'  => 'plugin',
				'slug'       => 'foo',
				'pattern'    => 'readme.txt',
				'reason'     => 'noisy path',
				'created_by' => 7,
			)
		);

		$row = $repository->find_by_id( $id );

		$this->assertNotNull( $row );
		$this->assertSame( WPCV_Suppression_Type::EXCLUDE_PATH, $row['type'] );
		$this->assertSame( 'plugin', $row['dimension'] );
		$this->assertSame( 'foo', $row['slug'] );
		$this->assertSame( 'readme.txt', $row['pattern'] );
		$this->assertSame( 'noisy path', $row['reason'] );
		$this->assertSame( 7, $row['created_by'] );
		$this->assertNull( $row['expired_at'] );
	}

	/**
	 * `find_active_exclude_target_rule()` が dimension/slug が一致する
	 * `exclude_target` ルールを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_find_active_exclude_target_rule_returns_matching_rule() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$repository->insert(
			array(
				'type'       => WPCV_Suppression_Type::EXCLUDE_TARGET,
				'dimension'  => 'plugin',
				'slug'       => 'akismet',
				'reason'     => 'known false positive',
				'created_by' => 1,
			)
		);

		$rule = $repository->find_active_exclude_target_rule( 'plugin', 'akismet' );

		$this->assertNotNull( $rule );
		$this->assertSame( 'akismet', $rule['slug'] );

		$this->assertNull( $repository->find_active_exclude_target_rule( 'plugin', 'other-plugin' ) );
	}

	/**
	 * 失効済み(`expired_at` が非NULL)の `exclude_target` ルールは対象外になることを確認する.
	 *
	 * @return void
	 */
	public function test_find_active_exclude_target_rule_ignores_expired_rules() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$id = $repository->insert(
			array(
				'type'       => WPCV_Suppression_Type::EXCLUDE_TARGET,
				'dimension'  => 'plugin',
				'slug'       => 'akismet',
				'reason'     => 'known false positive',
				'created_by' => 1,
			)
		);
		$repository->expire( $id, 'no longer needed' );

		$this->assertNull( $repository->find_active_exclude_target_rule( 'plugin', 'akismet' ) );
	}

	/**
	 * `find_active_rules_for_target()` が `exclude_path`/`allowlist_hash` を
	 * type別に分類して返すことを確認する(`exclude_target` は含めない).
	 *
	 * @return void
	 */
	public function test_find_active_rules_for_target_groups_by_type() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$repository->insert(
			array(
				'type'       => WPCV_Suppression_Type::EXCLUDE_PATH,
				'dimension'  => 'core',
				'slug'       => 'wordpress',
				'pattern'    => 'wp-admin/index.php',
				'reason'     => 'r1',
				'created_by' => 1,
			)
		);
		$repository->insert(
			array(
				'type'           => WPCV_Suppression_Type::ALLOWLIST_HASH,
				'dimension'      => 'core',
				'slug'           => 'wordpress',
				'pattern'        => 'wp-admin/index.php',
				'expected_hash'  => str_repeat( 'a', 64 ),
				'hash_algorithm' => 'sha256',
				'version'        => '6.8',
				'reason'         => 'r2',
				'created_by'     => 1,
			)
		);
		$repository->insert(
			array(
				'type'       => WPCV_Suppression_Type::EXCLUDE_TARGET,
				'dimension'  => 'core',
				'slug'       => 'wordpress',
				'reason'     => 'r3',
				'created_by' => 1,
			)
		);

		$rules = $repository->find_active_rules_for_target( 'core', 'wordpress' );

		$this->assertCount( 1, $rules['exclude_path'] );
		$this->assertCount( 1, $rules['allowlist_hash'] );

		$this->assertCount( 0, $repository->find_active_rules_for_target( 'plugin', 'other' )['exclude_path'] );
	}

	/**
	 * `expire()` が有効なルールを失効させ、既に失効済みの行には二重に
	 * 適用できない(false を返す)ことを確認する.
	 *
	 * @return void
	 */
	public function test_expire_marks_rule_expired_and_rejects_double_expire() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$id = $repository->insert(
			array(
				'type'       => WPCV_Suppression_Type::EXCLUDE_TARGET,
				'dimension'  => 'plugin',
				'slug'       => 'akismet',
				'reason'     => 'r',
				'created_by' => 1,
			)
		);

		$this->assertTrue( $repository->expire( $id, 'no longer needed' ) );

		$row = $repository->find_by_id( $id );
		$this->assertNotNull( $row['expired_at'] );
		$this->assertSame( 'no longer needed', $row['expired_reason'] );

		$this->assertFalse( $repository->expire( $id, 'again' ) );
	}

	/**
	 * 存在しない id に対する `expire()` は false を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_expire_returns_false_for_unknown_id() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$this->assertFalse( $repository->expire( 999, 'reason' ) );
	}

	/**
	 * D9(v0.6プラン §3.3): `expire_allowlist_hash_rules_with_different_version()`が、
	 * versionが今回と異なる`allowlist_hash`ルールを失効させ、`expired_reason`に
	 * `version_changed`を書くことを確認する.
	 *
	 * @return void
	 */
	public function test_expire_allowlist_hash_rules_with_different_version_expires_mismatched_rule() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$id = $repository->insert(
			array(
				'type'           => WPCV_Suppression_Type::ALLOWLIST_HASH,
				'dimension'      => 'plugin',
				'slug'           => 'foo',
				'pattern'        => 'foo.php',
				'expected_hash'  => str_repeat( 'a', 64 ),
				'hash_algorithm' => 'sha256',
				'version'        => '1.0.0',
				'reason'         => 'approved 1.0.0',
				'created_by'     => 1,
			)
		);

		$expired = $repository->expire_allowlist_hash_rules_with_different_version( 'plugin', 'foo', '1.1.0' );

		$this->assertSame( 1, $expired );

		$row = $repository->find_by_id( $id );
		$this->assertNotNull( $row['expired_at'] );
		$this->assertSame( WPCV_Suppression_Repository::EXPIRED_REASON_VERSION_CHANGED, $row['expired_reason'] );
	}

	/**
	 * D9: versionが今回と同じ`allowlist_hash`ルールは失効させないことを確認する
	 * (「先に新しいversionで承認されたもの」. §3.3の表参照).
	 *
	 * @return void
	 */
	public function test_expire_allowlist_hash_rules_with_different_version_keeps_matching_version() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$id = $repository->insert(
			array(
				'type'           => WPCV_Suppression_Type::ALLOWLIST_HASH,
				'dimension'      => 'plugin',
				'slug'           => 'foo',
				'pattern'        => 'foo.php',
				'expected_hash'  => str_repeat( 'a', 64 ),
				'hash_algorithm' => 'sha256',
				'version'        => '1.1.0',
				'reason'         => 'approved 1.1.0',
				'created_by'     => 1,
			)
		);

		$expired = $repository->expire_allowlist_hash_rules_with_different_version( 'plugin', 'foo', '1.1.0' );

		$this->assertSame( 0, $expired );
		$this->assertNull( $repository->find_by_id( $id )['expired_at'] );
	}

	/**
	 * D9: `exclude_path`/`exclude_target`ルールは失効させないことを確認する
	 * (「exclude_path/exclude_targetは失効させない」. §3.3参照).
	 *
	 * @return void
	 */
	public function test_expire_allowlist_hash_rules_with_different_version_does_not_touch_other_types() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$exclude_path_id = $repository->insert(
			array(
				'type'       => WPCV_Suppression_Type::EXCLUDE_PATH,
				'dimension'  => 'plugin',
				'slug'       => 'foo',
				'pattern'    => 'readme.txt',
				'reason'     => 'noisy',
				'created_by' => 1,
			)
		);
		$exclude_target_id = $repository->insert(
			array(
				'type'       => WPCV_Suppression_Type::EXCLUDE_TARGET,
				'dimension'  => 'plugin',
				'slug'       => 'foo',
				'reason'     => 'skip',
				'created_by' => 1,
			)
		);

		$expired = $repository->expire_allowlist_hash_rules_with_different_version( 'plugin', 'foo', '1.1.0' );

		$this->assertSame( 0, $expired );
		$this->assertNull( $repository->find_by_id( $exclude_path_id )['expired_at'] );
		$this->assertNull( $repository->find_by_id( $exclude_target_id )['expired_at'] );
	}

	/**
	 * D9: 複数の`allowlist_hash`ルールがある場合、versionが一致しないものだけを
	 * まとめて失効させ、失効件数を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_expire_allowlist_hash_rules_with_different_version_handles_multiple_rules() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$stale_id = $repository->insert(
			array(
				'type'           => WPCV_Suppression_Type::ALLOWLIST_HASH,
				'dimension'      => 'plugin',
				'slug'           => 'foo',
				'pattern'        => 'a.php',
				'expected_hash'  => str_repeat( 'a', 64 ),
				'hash_algorithm' => 'sha256',
				'version'        => '1.0.0',
				'reason'         => 'old',
				'created_by'     => 1,
			)
		);
		$fresh_id = $repository->insert(
			array(
				'type'           => WPCV_Suppression_Type::ALLOWLIST_HASH,
				'dimension'      => 'plugin',
				'slug'           => 'foo',
				'pattern'        => 'b.php',
				'expected_hash'  => str_repeat( 'b', 64 ),
				'hash_algorithm' => 'sha256',
				'version'        => '1.1.0',
				'reason'         => 'new',
				'created_by'     => 1,
			)
		);

		$expired = $repository->expire_allowlist_hash_rules_with_different_version( 'plugin', 'foo', '1.1.0' );

		$this->assertSame( 1, $expired );
		$this->assertNotNull( $repository->find_by_id( $stale_id )['expired_at'] );
		$this->assertNull( $repository->find_by_id( $fresh_id )['expired_at'] );
	}

	/**
	 * `find_all()` が挿入済みの全ルールを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_find_all_returns_all_rules() {
		$repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );

		$repository->insert(
			array(
				'type'       => WPCV_Suppression_Type::EXCLUDE_TARGET,
				'dimension'  => 'plugin',
				'slug'       => 'a',
				'reason'     => 'r',
				'created_by' => 1,
			)
		);
		$repository->insert(
			array(
				'type'       => WPCV_Suppression_Type::EXCLUDE_TARGET,
				'dimension'  => 'plugin',
				'slug'       => 'b',
				'reason'     => 'r',
				'created_by' => 1,
			)
		);

		$this->assertCount( 2, $repository->find_all() );
	}
}
