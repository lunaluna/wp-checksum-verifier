<?php
/**
 * WPCV_Finding_Key クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `finding` の差分キー(`wpcv_findings.finding_key`)を計算する(v0.5後半プラン §1.4).
 *
 * 差分処理(v0.5後半プラン §2. Step12以降)は、同一targetの「今回のrun」と
 * 「基準のrun」で同じキーを持つfindingがあるかどうかで NEW/CONTINUING/RESOLVED を
 * 判定する. DBに触れない純粋な計算のみを行い、`WPCV_Finding_Repository::save_findings()`
 * (保存時)と差分処理(比較時)の両方から共有する.
 *
 * `expected_hash`/`hash_algorithm` をキーに含めるのは、同じ version でマニフェストが
 * 修正され期待値が変わった場合に、別のfinding(NEW)として扱うため(同じpath・statusの
 * 行として上書き=継続と誤判定させないための設計. プラン §1.4参照).
 *
 * 例外として、`core:_scan`(コアの未知ファイル走査)だけは version をキーに含めない
 * (v0.11.0 項目2). `core:_scan` の target_run は version を持たない(null)ため、本体を
 * 更新しても差分処理は `compared` で比べる. 一方 finding の version 列には本体の version が
 * 入るため、version をキーに含めると、中身の変わっていないファイルまで本体の更新のたびに
 * 「解消+新規」になっていた. `core:_scan` の added は「配布物に無いファイルがある」という
 * 事実で、本体の version とは関係しない. finding の version 列には今までどおり本体の
 * version を保存する(画面・メールの表示用).
 *
 * 対象を `core:_scan` に限る理由: `muplugin:_scan` は最初から version が空文字.
 * `theme:{x}:_scan` は target_run もテーマの version を持ち、`version_changed` として正しく扱われる.
 */
class WPCV_Finding_Key {

	/**
	 * キーに version を含めない target_id(v0.11.0 項目2. クラスの docblock 参照).
	 *
	 * `WPCV_Target_Resolver::build_id( 'core', '_scan' )` と同じ値.
	 *
	 * @var string
	 */
	const VERSIONLESS_TARGET_ID = 'core:_scan';

	/**
	 * この target の finding で、version を区別に使わないかどうかを返す.
	 *
	 * キーの計算(`compute()`)と、`allowlist_hash` の照合
	 * (`WPCV_Suppression_Matcher::find_matching_allowlist_hash_rule()`)の両方が使う.
	 * 片方だけ version を見ると、承認したファイルが本体の更新後に「継続」なのに
	 * 承認が効かない、という食い違いが起きるため、判定を1か所にまとめる.
	 *
	 * @param string $target_id target_id.
	 * @return bool
	 */
	public static function ignores_version( $target_id ) {
		return self::VERSIONLESS_TARGET_ID === (string) $target_id;
	}

	/**
	 * `finding` 1件分の差分キーを計算する.
	 *
	 * 各値を「長さを前置きした文字列」に変換してから連結する(`encode()` 参照)。
	 * 単純な区切り文字(`|` 等)で連結すると、path に区切り文字と同じ文字が
	 * 含まれる場合に別々の入力から同じキーが作れてしまう(衝突)。長さを前置き
	 * すれば、どのバイト列を渡しても一意に決まり、NULL と空文字列も区別できる.
	 *
	 * `ignores_version()` が真の target(`core:_scan`)では、渡された version に関わらず
	 * 空文字列としてキーを作る(v0.11.0 項目2). 呼び出し側で場合分けしなくても、保存時
	 * (`WPCV_Finding_Repository::save_findings()`)と移行処理
	 * (`WPCV_Migrator::recompute_core_scan_finding_keys()`)が同じ値になる.
	 *
	 * @param string      $target_id      target_id.
	 * @param string      $version        version(`core:_scan` では無視する).
	 * @param string      $path           path.
	 * @param string      $status         status.
	 * @param string|null $hash_algorithm hash_algorithm(値が無ければ null).
	 * @param string|null $expected_hash  expected_hash(`added` 等では null).
	 * @param string|null $actual_hash    actual_hash(`missing`/`unreadable` 等では null).
	 * @return string sha256のhex文字列(64文字固定長. `char(64)` 列にそのまま保存できる).
	 */
	public static function compute( $target_id, $version, $path, $status, $hash_algorithm, $expected_hash, $actual_hash ) {
		if ( self::ignores_version( $target_id ) ) {
			$version = '';
		}

		$data = self::encode( $target_id )
			. self::encode( $version )
			. self::encode( $path )
			. self::encode( $status )
			. self::encode( $hash_algorithm )
			. self::encode( $expected_hash )
			. self::encode( $actual_hash );

		return hash( 'sha256', $data );
	}

	/**
	 * 1つの値を「長さを前置きした文字列」に変換する(プラン §1.4の`F(v)`).
	 *
	 * `strlen()` はバイト数を返す(マルチバイト文字数ではない)ため、UTF-8で
	 * ない(壊れた)バイト列を含む path でも安全に一意な長さを求められる.
	 *
	 * @param string|null $value 値.
	 * @return string
	 */
	private static function encode( $value ) {
		if ( null === $value ) {
			return '-';
		}

		$value = (string) $value;

		return strlen( $value ) . ':' . $value;
	}
}
