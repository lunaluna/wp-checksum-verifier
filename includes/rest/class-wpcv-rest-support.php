<?php
/**
 * WPCV_Rest_Support クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPCV の REST コントローラー(`WPCV_Rest_Run_Controller`・
 * `WPCV_Rest_Status_Controller`・`WPCV_Rest_Findings_Controller`)が共有する
 * 小さなヘルパー(v0.4.0 §Step7)。
 *
 * `Cache-Control: no-store` を付与したレスポンス組み立てが3コントローラーで
 * 同一のため、2箇所目以降の利用が出た時点で共通化するというこのプロジェクトの
 * 方針に従い切り出した.
 */
class WPCV_Rest_Support {

	/**
	 * `Cache-Control: no-store` を付与したレスポンスを組み立てる.
	 *
	 * すべてのWPCV REST応答はトークン認証済みの動的な値を返すため、共有
	 * キャッシュ(プロキシ・CDN)に保存されてはならない(§Step7プラン
	 * 「すべて`Cache-Control: no-store`」).
	 *
	 * @param array $data レスポンスボディ.
	 * @return WP_REST_Response
	 */
	public static function response( array $data ) {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
