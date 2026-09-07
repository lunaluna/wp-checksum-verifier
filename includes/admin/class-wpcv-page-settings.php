<?php
/**
 * WPCV_Page_Settings クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定画面.
 *
 * 現時点(v0.1)では骨組み(メニュー登録先)のみ. 実行モード・タイムゾーン・
 * GitHub PAT 等(§11)の実項目は、各機能を実装するステップで追加する.
 */
class WPCV_Page_Settings {

	/**
	 * 画面を描画する.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::required_capability() ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Checksum Verifier', 'wp-checksum-verifier' ); ?></h1>
			<p><?php echo esc_html__( 'Settings are not yet available in this version.', 'wp-checksum-verifier' ); ?></p>
		</div>
		<?php
	}

	/**
	 * この画面に必要な capability を返す.
	 *
	 * @return string
	 */
	private static function required_capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}
}
