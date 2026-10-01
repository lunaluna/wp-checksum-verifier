<?php
/**
 * 日本語訳ファイル(languages/)の整合性のテスト.
 *
 * @package WPChecksumVerifier
 */

use PHPUnit\Framework\TestCase;

/**
 * `languages/wp-checksum-verifier-ja.po` が `.pot` に追従し、未訳・fuzzy が無く、
 * プレースホルダー(`%s`・`%1$s`・`{slug}` など)が原文と一致していること(v0.8 §Step8)と、
 * 読み込まれる `.mo` / `.l10n.php` が存在することを確かめる.
 *
 * `.pot` が最新かどうか(コードの文字列と一致するか)は、`wp i18n make-pot` が要るため
 * ここでは確かめない(リリース時に release.yml のジョブが `Project-Id-Version` を照合する).
 */
class TranslationFilesTest extends TestCase {

	/**
	 * `.po` / `.pot` を読み、msgid => 付随情報の表にする.
	 *
	 * @param string $file languages/ 配下のファイル名.
	 * @return array<string, array{msgstr: string, fuzzy: bool}> ヘッダー(空の msgid)は除く.
	 */
	private static function parse( $file ) {
		$path    = dirname( __DIR__ ) . '/languages/' . $file;
		$content = (string) file_get_contents( $path );
		$entries = array();

		foreach ( preg_split( '/\n\n+/', $content ) as $block ) {
			if ( 1 !== preg_match( '/^msgid ((?:".*"\n?)+)\s*(?:msgid_plural .*\n)?msgstr ((?:".*"\n?)+)/m', $block, $m ) ) {
				continue;
			}

			$msgid = self::unquote( $m[1] );

			if ( '' === $msgid ) {
				continue;
			}

			$entries[ $msgid ] = array(
				'msgstr' => self::unquote( $m[2] ),
				'fuzzy'  => 1 === preg_match( '/^#,.*\bfuzzy\b/m', $block ),
			);
		}

		return $entries;
	}

	/**
	 * `"..."` が並んだ PO の文字列を、1つの文字列にする.
	 *
	 * @param string $quoted PO の引用符つき文字列(複数行可).
	 * @return string
	 */
	private static function unquote( $quoted ) {
		$out = '';

		foreach ( preg_split( '/\n/', trim( $quoted ) ) as $line ) {
			$out .= substr( trim( $line ), 1, -1 );
		}

		return stripcslashes( $out );
	}

	/**
	 * プレースホルダーを数えやすい形にする.
	 *
	 * @param string $text 文字列.
	 * @return string[]
	 */
	private static function placeholders( $text ) {
		preg_match_all( '/%(?:\d+\$)?[sd]|\{[a-z]+\}/', $text, $m );
		sort( $m[0] );

		return $m[0];
	}

	/**
	 * `.po` の msgid は `.pot` と同じ集合(`.pot` の作り直し忘れ・`.po` の更新忘れを防ぐ).
	 *
	 * @return void
	 */
	public function test_po_has_same_msgids_as_pot() {
		$this->assertSame(
			array_keys( self::parse( 'wp-checksum-verifier.pot' ) ),
			array_keys( self::parse( 'wp-checksum-verifier-ja.po' ) )
		);
	}

	/**
	 * 未訳(空の msgstr)と fuzzy が無い.
	 *
	 * @return void
	 */
	public function test_po_has_no_untranslated_or_fuzzy_entries() {
		foreach ( self::parse( 'wp-checksum-verifier-ja.po' ) as $msgid => $entry ) {
			$this->assertNotSame( '', $entry['msgstr'], "未訳: {$msgid}" );
			$this->assertFalse( $entry['fuzzy'], "fuzzy: {$msgid}" );
		}
	}

	/**
	 * 訳文のプレースホルダーが原文と(数も種類も)一致する.
	 *
	 * @return void
	 */
	public function test_po_placeholders_match_msgids() {
		foreach ( self::parse( 'wp-checksum-verifier-ja.po' ) as $msgid => $entry ) {
			$this->assertSame( self::placeholders( $msgid ), self::placeholders( $entry['msgstr'] ), "プレースホルダー不一致: {$msgid}" );
		}
	}

	/**
	 * WordPress が読み込む形式(`.mo`、WordPress 6.5 以降が先に読む `.l10n.php`)が置かれている.
	 *
	 * @return void
	 */
	public function test_compiled_translation_files_exist() {
		$this->assertFileExists( dirname( __DIR__ ) . '/languages/wp-checksum-verifier-ja.mo' );
		$this->assertFileExists( dirname( __DIR__ ) . '/languages/wp-checksum-verifier-ja.l10n.php' );
	}

	/**
	 * `.l10n.php` が `.po` の訳を含む(`.po` を直して `.mo` / `.l10n.php` を作り直し忘れていない).
	 *
	 * @return void
	 */
	public function test_l10n_php_matches_po() {
		$l10n = require dirname( __DIR__ ) . '/languages/wp-checksum-verifier-ja.l10n.php';

		$this->assertIsArray( $l10n );

		foreach ( self::parse( 'wp-checksum-verifier-ja.po' ) as $msgid => $entry ) {
			$this->assertSame( $entry['msgstr'], $l10n['messages'][ $msgid ] ?? null, "l10n.php が古い: {$msgid}" );
		}
	}
}
