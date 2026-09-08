<?php
/**
 * WPCV_Manifest_Source インターフェースファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 照合ソース(コア・公式プラグイン・公式テーマ・GitHub)共通のインターフェース.
 *
 * 実装クラスは `get_manifest()` で一次ソースからマニフェストを取得し、以下の
 * 形式の連想配列で返す(§5.3/§5.4 に準拠する契約):
 *
 * ```
 * array(
 *     'manifest_status' => 'ok'|'locale_fallback'|'cached'|'missing', // target_runs.manifest_status
 *     'error_code'       => string|null,                              // 失敗時のみ WPCV_Error_Code の定数. 成功時は null
 *     'files'            => array(
 *         '相対パス' => array(
 *             'algorithm' => 'sha256'|'md5',
 *             'hashes'    => array( '期待するハッシュ値', ... ), // 複数候補があり得るため配列で持つ
 *         ),
 *     ),
 * )
 * ```
 *
 * マニフェストが取得できない場合は `manifest_status = 'missing'`、`files = array()`、
 * `error_code` に理由を設定して返す(例外を投げない。呼び出し側が
 * `target_runs.status = unverifiable` として記録する判断材料にするため).
 */
interface WPCV_Manifest_Source {

	/**
	 * マニフェストを取得する.
	 *
	 * @param array $context ソース固有の文脈(例: version, slug). 実装クラスの
	 *                        docblock を参照.
	 * @return array 契約はこのインターフェースの docblock を参照.
	 */
	public function get_manifest( array $context );
}
