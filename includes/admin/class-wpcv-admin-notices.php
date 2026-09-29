<?php
/**
 * WPCV_Admin_Notices クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * アラート送信結果の管理画面通知(v0.5後半 §Step14d・§2.5).
 *
 * 直近の終端run(`WPCV_Run_Repository::find_most_recent_terminal_run()`)の
 * `alert_status`が`no_recipient`なら警告、`failed`ならエラーを表示する
 * (`sent`/`not_needed`/未設定のときは何も表示しない ―― §2.5「メール成功→
 * 通知を消す」に対応する).
 *
 * 表示対象はWPCVの各画面(設定・実行履歴・検出結果・抑制一覧。
 * `WPCV_Admin_Menu::register()`が渡すhook_suffix)・ダッシュボード・
 * プラグイン一覧に限る(全管理画面には出さない。プラン§2.5「レビュー
 * 『不要または優先度を下げられる機能』」参照).マルチサイトでは
 * ネットワーク管理画面側(`network_admin_notices`・`dashboard-network`・
 * `plugins-network`)に出す(installation-levelのデータであるため.
 * `WPCV_Admin_Menu`のクラスdocblock参照).
 */
class WPCV_Admin_Notices {

	/**
	 * WPCVの各画面のhook_suffixを受け取り、`admin_notices`(マルチサイトでは
	 * `network_admin_notices`)へ登録する.
	 *
	 * @param array<int, string|false> $hook_suffixes `WPCV_Admin_Menu`が
	 *                                                 `add_menu_page()`/
	 *                                                 `add_submenu_page()`から
	 *                                                 受け取った戻り値
	 *                                                 (失敗時`false`が混じり
	 *                                                 得るため除いておく).
	 * @return void
	 */
	public static function register( array $hook_suffixes ) {
		$hook_suffixes = array_values( array_filter( $hook_suffixes, 'is_string' ) );

		if ( is_multisite() ) {
			$targets = array_merge( $hook_suffixes, array( 'dashboard-network', 'plugins-network' ) );

			add_action(
				'network_admin_notices',
				static function () use ( $targets ) {
					self::maybe_render( $targets );
				}
			);

			return;
		}

		$targets = array_merge( $hook_suffixes, array( 'dashboard', 'plugins' ) );

		add_action(
			'admin_notices',
			static function () use ( $targets ) {
				self::maybe_render( $targets );
			}
		);
	}

	/**
	 * 現在の画面が対象で、かつ権限があれば通知を出す.
	 *
	 * @param string[] $targets 対象の画面id(`get_current_screen()->id`)一覧.
	 * @return void
	 */
	private static function maybe_render( array $targets ) {
		$screen = get_current_screen();

		if ( null === $screen || ! in_array( $screen->id, $targets, true ) ) {
			return;
		}

		if ( ! current_user_can( WPCV_Page_Settings::required_capability() ) ) {
			return;
		}

		$run = WPCV_Plugin::run_repository()->find_most_recent_terminal_run();

		if ( null === $run ) {
			return;
		}

		$alert_status = $run['alert_status'] ?? null;

		if ( 'no_recipient' === $alert_status ) {
			self::render_notice(
				'notice-warning',
				__( 'WP Checksum Verifier: no alert recipients are set. Findings are not being emailed to anyone. Set at least one address on the WP Checksum Verifier settings screen.', 'wp-checksum-verifier' )
			);

			return;
		}

		if ( 'failed' === $alert_status ) {
			$error = (string) ( $run['alert_error'] ?? '' );

			self::render_notice(
				'notice-error',
				'' === $error
					? __( 'WP Checksum Verifier: the last alert email failed to send.', 'wp-checksum-verifier' )
					: sprintf(
						/* translators: %s: error message from wp_mail(). */
						__( 'WP Checksum Verifier: the last alert email failed to send: %s', 'wp-checksum-verifier' ),
						$error
					)
			);
		}
	}

	/**
	 * 通知を1件出力する.
	 *
	 * @param string $notice_class `notice-warning`/`notice-error`等.
	 * @param string $message      表示するメッセージ(このメソッド内でエスケープする).
	 * @return void
	 */
	private static function render_notice( $notice_class, $message ) {
		printf(
			'<div class="notice %1$s"><p>%2$s</p></div>',
			esc_attr( $notice_class ),
			esc_html( $message )
		);
	}
}
