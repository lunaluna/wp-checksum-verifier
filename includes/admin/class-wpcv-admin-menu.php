<?php
/**
 * WPCV_Admin_Menu クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理メニューの登録.
 *
 * 検出結果等は installation-level のデータであるため、マルチサイトでは
 * ネットワーク管理画面に配置する(§5.6/§11)。単一サイトはサイト1つの
 * installation として通常の管理画面に配置する.
 *
 * 単一サイトでの必要 capability は manage_options を仮採用している
 * (§17-9 未決事項)。設定画面に実項目(§11: 実行モード・GitHub PAT 等)を
 * 実装する際に見直すこと.
 */
class WPCV_Admin_Menu {

	/**
	 * フックを登録する.
	 *
	 * @return void
	 */
	public static function register() {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( __CLASS__, 'add_network_menu' ) );
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'add_site_menu' ) );
	}

	/**
	 * 単一サイトの管理画面にメニューを追加する.
	 *
	 * @return void
	 */
	public static function add_site_menu() {
		add_menu_page(
			__( 'WP Checksum Verifier', 'wp-checksum-verifier' ),
			__( 'Checksum Verifier', 'wp-checksum-verifier' ),
			'manage_options',
			'wpcv-settings',
			array( 'WPCV_Page_Settings', 'render' ),
			'dashicons-shield'
		);
	}

	/**
	 * ネットワーク管理画面にメニューを追加する.
	 *
	 * @return void
	 */
	public static function add_network_menu() {
		add_menu_page(
			__( 'WP Checksum Verifier', 'wp-checksum-verifier' ),
			__( 'Checksum Verifier', 'wp-checksum-verifier' ),
			'manage_network_options',
			'wpcv-settings',
			array( 'WPCV_Page_Settings', 'render' ),
			'dashicons-shield'
		);
	}
}
