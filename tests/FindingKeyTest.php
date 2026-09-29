<?php
/**
 * WPCV_Finding_Key のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-finding-key.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Finding_Key::compute()`(v0.5後半プラン §1.4)のテスト.
 *
 * DBアクセスの無い純粋なロジックのため、実際の finding 配列を模した引数を
 * 直接渡して検証する(`SuppressionMatcherTest` と同じ方針).
 */
class FindingKeyTest extends TestCase {

	/**
	 * 同じ入力からは常に同じキー(64文字のhex文字列)を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_compute_is_deterministic_and_64_char_hex() {
		$a = WPCV_Finding_Key::compute( 'plugin:acme-widgets', '1.2.0', 'acme-widgets.php', 'modified', 'sha256', 'expected', 'actual' );
		$b = WPCV_Finding_Key::compute( 'plugin:acme-widgets', '1.2.0', 'acme-widgets.php', 'modified', 'sha256', 'expected', 'actual' );

		$this->assertSame( $a, $b );
		$this->assertSame( 64, strlen( $a ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a );
	}

	/**
	 * path に区切り文字候補(`:`)が含まれていても、target_id との境界が
	 * ずれた別の組み合わせと衝突しないことを確認する(長さ前置き方式の核心。
	 * プラン §1.4「単純な区切り文字ではファイル名に同じ文字が入ると曖昧になる」).
	 *
	 * @return void
	 */
	public function test_compute_does_not_collide_when_path_contains_delimiter_like_characters() {
		// target_id="a", path="b:c" と target_id="a:b", path="c" は、単純な
		// 連結(`$target_id . ':' . $path` 等)では同じ文字列になってしまう組み合わせ.
		$a = WPCV_Finding_Key::compute( 'a', '1.0', 'b:c', 'modified', 'sha256', 'x', 'y' );
		$b = WPCV_Finding_Key::compute( 'a:b', '1.0', 'c', 'modified', 'sha256', 'x', 'y' );

		$this->assertNotSame( $a, $b );
	}

	/**
	 * path が UTF-8 として不正なバイト列でも例外を投げず、決定的なキーを返す
	 * ことを確認する(`strlen()` はバイト数を返すため、マルチバイト文字の解釈に
	 * 依存しない. プラン §1.4「どんなバイト列でも1通りに決まり」).
	 *
	 * @return void
	 */
	public function test_compute_handles_non_utf8_path_deterministically() {
		$invalid_utf8_path = "wp-content/plugins/acme/\xFF\xFE-broken.php";

		$a = WPCV_Finding_Key::compute( 'plugin:acme', '1.0', $invalid_utf8_path, 'modified', 'sha256', 'x', 'y' );
		$b = WPCV_Finding_Key::compute( 'plugin:acme', '1.0', $invalid_utf8_path, 'modified', 'sha256', 'x', 'y' );

		$this->assertSame( $a, $b );
		$this->assertSame( 64, strlen( $a ) );
	}

	/**
	 * NULL と空文字列を区別することを確認する(`added` finding の
	 * `expected_hash = null` と、仮に空文字列を渡した場合とで異なるキーになる。
	 * プラン §1.4「NULL と空文字も区別できる」).
	 *
	 * @return void
	 */
	public function test_compute_distinguishes_null_from_empty_string() {
		$with_null  = WPCV_Finding_Key::compute( 'plugin:acme', '1.0', 'acme.php', 'added', 'sha256', null, 'actual' );
		$with_empty = WPCV_Finding_Key::compute( 'plugin:acme', '1.0', 'acme.php', 'added', 'sha256', '', 'actual' );

		$this->assertNotSame( $with_null, $with_empty );
	}

	/**
	 * `expected_hash` だけが異なる場合、別のキーになることを確認する(プラン §1.4:
	 * 「同じ version でマニフェストが修正されて期待値が変わった場合は、別の
	 * finding(NEW)になる」という要件の核心).
	 *
	 * @return void
	 */
	public function test_compute_differs_when_only_expected_hash_differs() {
		$before = WPCV_Finding_Key::compute( 'plugin:acme', '1.2.0', 'acme.php', 'modified', 'sha256', 'old-expected', 'actual' );
		$after  = WPCV_Finding_Key::compute( 'plugin:acme', '1.2.0', 'acme.php', 'modified', 'sha256', 'new-expected', 'actual' );

		$this->assertNotSame( $before, $after );
	}

	/**
	 * `target_id`/`version`/`path`/`status` のいずれか1つでも異なれば別のキーに
	 * なることを確認する(通常の差分検知が成立するための基本要件).
	 *
	 * @return void
	 */
	public function test_compute_differs_when_any_core_field_differs() {
		$base = WPCV_Finding_Key::compute( 'plugin:acme', '1.2.0', 'acme.php', 'modified', 'sha256', 'x', 'y' );

		$this->assertNotSame( $base, WPCV_Finding_Key::compute( 'plugin:other', '1.2.0', 'acme.php', 'modified', 'sha256', 'x', 'y' ) );
		$this->assertNotSame( $base, WPCV_Finding_Key::compute( 'plugin:acme', '1.3.0', 'acme.php', 'modified', 'sha256', 'x', 'y' ) );
		$this->assertNotSame( $base, WPCV_Finding_Key::compute( 'plugin:acme', '1.2.0', 'other.php', 'modified', 'sha256', 'x', 'y' ) );
		$this->assertNotSame( $base, WPCV_Finding_Key::compute( 'plugin:acme', '1.2.0', 'acme.php', 'added', 'sha256', 'x', 'y' ) );
	}
}
