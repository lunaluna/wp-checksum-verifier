<?php
/**
 * WPCV_Suppression_Matcher のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-matcher.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Suppression_Matcher`(v0.4.0 §Step8)のテスト.
 *
 * DBアクセスの無い純粋なロジックのため、`WPCV_Suppression_Repository` が返す
 * ルール配列を模した配列を直接渡して検証する.
 */
class SuppressionMatcherTest extends TestCase {

	/**
	 * `*` がスラッシュを跨がずマッチすることを確認する.
	 *
	 * @return void
	 */
	public function test_matches_glob_single_star_does_not_cross_slash() {
		$this->assertTrue( WPCV_Suppression_Matcher::matches_glob( 'wp-content/plugins/*/readme.txt', 'wp-content/plugins/foo/readme.txt' ) );
		$this->assertFalse( WPCV_Suppression_Matcher::matches_glob( 'wp-content/plugins/*/readme.txt', 'wp-content/plugins/foo/bar/readme.txt' ) );
	}

	/**
	 * `**` がスラッシュを跨いでマッチすることを確認する.
	 *
	 * @return void
	 */
	public function test_matches_glob_double_star_crosses_slash() {
		$this->assertTrue( WPCV_Suppression_Matcher::matches_glob( 'wp-content/plugins/**/vendor/*.php', 'wp-content/plugins/foo/bar/vendor/autoload.php' ) );
	}

	/**
	 * `?` がスラッシュ以外の任意の1文字にマッチすることを確認する.
	 *
	 * @return void
	 */
	public function test_matches_glob_question_mark_matches_single_char() {
		$this->assertTrue( WPCV_Suppression_Matcher::matches_glob( 'file-?.php', 'file-1.php' ) );
		$this->assertFalse( WPCV_Suppression_Matcher::matches_glob( 'file-?.php', 'file-12.php' ) );
		$this->assertFalse( WPCV_Suppression_Matcher::matches_glob( 'file-?.php', 'file-/.php' ) );
	}

	/**
	 * `*`/`**`/`?` 以外の正規表現特殊文字はリテラルとして扱われ、意図しない
	 * マッチ(正規表現インジェクション)を起こさないことを確認する.
	 *
	 * @return void
	 */
	public function test_matches_glob_escapes_regex_special_characters() {
		$this->assertTrue( WPCV_Suppression_Matcher::matches_glob( 'readme.txt', 'readme.txt' ) );
		// '.' はリテラルのドットのみにマッチし、任意の1文字にはマッチしない.
		$this->assertFalse( WPCV_Suppression_Matcher::matches_glob( 'readme.txt', 'readmeXtxt' ) );
	}

	/**
	 * 空文字パターンは何にもマッチしないことを確認する.
	 *
	 * @return void
	 */
	public function test_matches_glob_empty_pattern_never_matches() {
		$this->assertFalse( WPCV_Suppression_Matcher::matches_glob( '', 'anything' ) );
	}

	/**
	 * `exclude_path` ルールの `pattern` に一致するfindingのルールを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_find_matching_exclude_path_rule_returns_matching_rule() {
		$finding = wpcv_test_make_finding( array( 'path' => 'wp-content/plugins/foo/readme.txt' ) );
		$rules   = array(
			array(
				'id'      => 5,
				'pattern' => 'wp-content/plugins/foo/readme.txt',
			),
		);

		$rule = WPCV_Suppression_Matcher::find_matching_exclude_path_rule( $finding, $rules );

		$this->assertNotNull( $rule );
		$this->assertSame( 5, $rule['id'] );
	}

	/**
	 * 一致するルールが無ければ null を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_find_matching_exclude_path_rule_returns_null_when_no_match() {
		$finding = wpcv_test_make_finding( array( 'path' => 'wp-admin/index.php' ) );
		$rules   = array( array( 'pattern' => 'wp-content/plugins/foo/readme.txt' ) );

		$this->assertNull( WPCV_Suppression_Matcher::find_matching_exclude_path_rule( $finding, $rules ) );
	}

	/**
	 * `allowlist_hash` ルールが、path・version・hash_algorithm・expected_hash の
	 * すべてが一致した場合にのみマッチすることを確認する.
	 *
	 * @return void
	 */
	public function test_find_matching_allowlist_hash_rule_requires_all_fields_to_match() {
		$finding = wpcv_test_make_finding(
			array(
				'status'         => 'modified',
				'version'        => '6.8',
				'hash_algorithm' => 'sha256',
				'actual_hash'    => str_repeat( 'b', 64 ),
				'path'           => 'wp-admin/index.php',
			)
		);

		$matching_rule = array(
			'id'             => 9,
			'pattern'        => 'wp-admin/index.php',
			'expected_hash'  => str_repeat( 'b', 64 ),
			'hash_algorithm' => 'sha256',
			'version'        => '6.8',
		);

		$rule = WPCV_Suppression_Matcher::find_matching_allowlist_hash_rule( $finding, array( $matching_rule ) );

		$this->assertNotNull( $rule );
		$this->assertSame( 9, $rule['id'] );
	}

	/**
	 * Target versionが一致しないルールはマッチしないことを確認する
	 * (バージョンドリフト検知。v0.6のversion-change失効を見据えた設計).
	 *
	 * @return void
	 */
	public function test_find_matching_allowlist_hash_rule_rejects_version_mismatch() {
		$finding = wpcv_test_make_finding(
			array(
				'status'         => 'modified',
				'version'        => '6.9',
				'hash_algorithm' => 'sha256',
				'actual_hash'    => str_repeat( 'b', 64 ),
				'path'           => 'wp-admin/index.php',
			)
		);

		$rule = array(
			'pattern'        => 'wp-admin/index.php',
			'expected_hash'  => str_repeat( 'b', 64 ),
			'hash_algorithm' => 'sha256',
			'version'        => '6.8',
		);

		$this->assertNull( WPCV_Suppression_Matcher::find_matching_allowlist_hash_rule( $finding, array( $rule ) ) );
	}

	/**
	 * Hash algorithmが一致しないルールはマッチしないことを確認する.
	 *
	 * @return void
	 */
	public function test_find_matching_allowlist_hash_rule_rejects_algorithm_mismatch() {
		$finding = wpcv_test_make_finding(
			array(
				'status'         => 'modified',
				'version'        => '6.8',
				'hash_algorithm' => 'sha256',
				'actual_hash'    => str_repeat( 'b', 64 ),
				'path'           => 'wp-admin/index.php',
			)
		);

		$rule = array(
			'pattern'        => 'wp-admin/index.php',
			'expected_hash'  => str_repeat( 'b', 64 ),
			'hash_algorithm' => 'md5',
			'version'        => '6.8',
		);

		$this->assertNull( WPCV_Suppression_Matcher::find_matching_allowlist_hash_rule( $finding, array( $rule ) ) );
	}

	/**
	 * Expected hashが実際のhashと一致しないルールはマッチしないことを確認する.
	 *
	 * @return void
	 */
	public function test_find_matching_allowlist_hash_rule_rejects_hash_mismatch() {
		$finding = wpcv_test_make_finding(
			array(
				'status'         => 'modified',
				'version'        => '6.8',
				'hash_algorithm' => 'sha256',
				'actual_hash'    => str_repeat( 'b', 64 ),
				'path'           => 'wp-admin/index.php',
			)
		);

		$rule = array(
			'pattern'        => 'wp-admin/index.php',
			'expected_hash'  => str_repeat( 'c', 64 ),
			'hash_algorithm' => 'sha256',
			'version'        => '6.8',
		);

		$this->assertNull( WPCV_Suppression_Matcher::find_matching_allowlist_hash_rule( $finding, array( $rule ) ) );
	}

	/**
	 * `missing`/`unreadable` finding は `actual_hash` が無い(比較対象が無い)ため、
	 * `allowlist_hash` の対象外であることを確認する.
	 *
	 * @return void
	 */
	public function test_find_matching_allowlist_hash_rule_excludes_missing_and_unreadable_statuses() {
		$rule = array(
			'pattern'        => 'wp-admin/index.php',
			'expected_hash'  => str_repeat( 'b', 64 ),
			'hash_algorithm' => 'sha256',
			'version'        => '6.8',
		);

		foreach ( array( 'missing', 'unreadable' ) as $status ) {
			$finding = wpcv_test_make_finding(
				array(
					'status'         => $status,
					'version'        => '6.8',
					'hash_algorithm' => 'sha256',
					'actual_hash'    => null,
					'path'           => 'wp-admin/index.php',
				)
			);

			$this->assertNull( WPCV_Suppression_Matcher::find_matching_allowlist_hash_rule( $finding, array( $rule ) ) );
		}
	}

	/**
	 * `readme.txt`/`readme.md` が(大文字小文字を問わず)soft change対象パスと
	 * 判定されることを確認する.
	 *
	 * @return void
	 */
	public function test_is_soft_change_path_matches_readme_case_insensitively() {
		$this->assertTrue( WPCV_Suppression_Matcher::is_soft_change_path( 'readme.txt' ) );
		$this->assertTrue( WPCV_Suppression_Matcher::is_soft_change_path( 'wp-content/plugins/foo/README.TXT' ) );
		$this->assertTrue( WPCV_Suppression_Matcher::is_soft_change_path( 'readme.md' ) );
		$this->assertFalse( WPCV_Suppression_Matcher::is_soft_change_path( 'wp-admin/index.php' ) );
	}

	/**
	 * 優先順位 `exclude_path` -> `allowlist_hash` -> soft change -> 通常finding が
	 * `apply()` で守られることを確認する(§Step8着手前に確定した組み合わせ表).
	 *
	 * @return void
	 */
	public function test_apply_prioritizes_exclude_path_over_allowlist_hash() {
		$finding = wpcv_test_make_finding(
			array(
				'status'         => 'modified',
				'version'        => '6.8',
				'hash_algorithm' => 'sha256',
				'actual_hash'    => str_repeat( 'b', 64 ),
				'path'           => 'wp-admin/index.php',
			)
		);

		$exclude_path_rules   = array(
			array(
				'id'      => 1,
				'pattern' => 'wp-admin/index.php',
			),
		);
		$allowlist_hash_rules = array(
			array(
				'id'             => 2,
				'pattern'        => 'wp-admin/index.php',
				'expected_hash'  => str_repeat( 'b', 64 ),
				'hash_algorithm' => 'sha256',
				'version'        => '6.8',
			),
		);

		$result = WPCV_Suppression_Matcher::apply( $finding, $exclude_path_rules, $allowlist_hash_rules, false );

		$this->assertNull( $result['suppressed_by'] );
		$this->assertSame( 1, $result['suppression_id'] );
	}

	/**
	 * どちらのユーザー定義ルールにもマッチしない場合、`strict_mode` が偽なら
	 * soft change(readme)がsystem suppressionとして適用されることを確認する.
	 *
	 * @return void
	 */
	public function test_apply_falls_back_to_soft_change_when_no_user_rule_matches() {
		$finding = wpcv_test_make_finding( array( 'path' => 'readme.txt' ) );

		$result = WPCV_Suppression_Matcher::apply( $finding, array(), array(), false );

		$this->assertSame( WPCV_Suppression_Matcher::SOFT_CHANGE_REASON, $result['suppressed_by'] );
		$this->assertNull( $result['suppression_id'] );
	}

	/**
	 * `strict_mode` が真なら、readmeのsoft change判定を無効化し通常findingのまま
	 * 返すことを確認する.
	 *
	 * @return void
	 */
	public function test_apply_does_not_suppress_soft_change_path_in_strict_mode() {
		$finding = wpcv_test_make_finding( array( 'path' => 'readme.txt' ) );

		$result = WPCV_Suppression_Matcher::apply( $finding, array(), array(), true );

		$this->assertNull( $result['suppressed_by'] );
		$this->assertNull( $result['suppression_id'] );
	}

	/**
	 * どのルールにもsoft changeにも該当しないfindingは、抑制されず通常finding
	 * として扱われることを確認する.
	 *
	 * @return void
	 */
	public function test_apply_returns_unsuppressed_when_nothing_matches() {
		$finding = wpcv_test_make_finding( array( 'path' => 'wp-admin/index.php' ) );

		$result = WPCV_Suppression_Matcher::apply( $finding, array(), array(), false );

		$this->assertNull( $result['suppressed_by'] );
		$this->assertNull( $result['suppression_id'] );
	}
}
