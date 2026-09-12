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

		self::add_run_history_submenu( 'manage_options' );
		self::add_findings_submenu( 'manage_options' );
		self::add_suppressions_submenu( 'manage_options' );
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

		self::add_run_history_submenu( 'manage_network_options' );
		self::add_findings_submenu( 'manage_network_options' );
		self::add_suppressions_submenu( 'manage_network_options' );
	}

	/**
	 * 実行履歴画面(`WPCV_Page_Run_History`. v0.4.0 §Step9)のサブメニューを追加する.
	 *
	 * 単一サイト・ネットワーク管理画面のどちらからも同じ形で登録するため、
	 * capability だけを引数化して共通化した(`add_menu_page()`本体は単一サイト/
	 * ネットワークでtitleが同じで差異が無いため、あえて共通化していない).
	 *
	 * @param string $capability この画面に必要な capability.
	 * @return void
	 */
	private static function add_run_history_submenu( $capability ) {
		add_submenu_page(
			'wpcv-settings',
			__( 'Run History', 'wp-checksum-verifier' ),
			__( 'Run History', 'wp-checksum-verifier' ),
			$capability,
			'wpcv-runs',
			array( 'WPCV_Page_Run_History', 'render' )
		);
	}

	/**
	 * 検出結果画面(`WPCV_Page_Findings`. v0.4.0 §Step9)のサブメニューを追加する
	 * (`add_run_history_submenu()`と同じ理由でcapabilityだけを引数化する).
	 *
	 * @param string $capability この画面に必要な capability.
	 * @return void
	 */
	private static function add_findings_submenu( $capability ) {
		add_submenu_page(
			'wpcv-settings',
			__( 'Findings', 'wp-checksum-verifier' ),
			__( 'Findings', 'wp-checksum-verifier' ),
			$capability,
			'wpcv-findings',
			array( 'WPCV_Page_Findings', 'render' )
		);
	}

	/**
	 * 抑制一覧画面(`WPCV_Page_Suppressions`. v0.4.0 §Step9)のサブメニューを追加する
	 * (`add_run_history_submenu()`と同じ理由でcapabilityだけを引数化する).
	 *
	 * @param string $capability この画面に必要な capability.
	 * @return void
	 */
	private static function add_suppressions_submenu( $capability ) {
		add_submenu_page(
			'wpcv-settings',
			__( 'Suppressions', 'wp-checksum-verifier' ),
			__( 'Suppressions', 'wp-checksum-verifier' ),
			$capability,
			'wpcv-suppressions',
			array( 'WPCV_Page_Suppressions', 'render' )
		);
	}
}
