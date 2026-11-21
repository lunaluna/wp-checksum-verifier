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
 * アラートの宛先・送信結果の管理画面通知(v0.5後半 §Step14d・§2.5).
 *
 * - 宛先が空の警告は、今の設定(`WPCV_Settings::get_alert_to()`)で判定する.
 *   run の有無や、run に記録された `alert_status` は見ない.
 * - メール送信の失敗のエラーは、直近の終端run
 *   (`WPCV_Run_Repository::find_most_recent_terminal_run()`)の
 *   `alert_status` が `failed` のときに出す(`sent`/`not_needed`/`no_recipient`/
 *   未設定のときは出さない ―― §2.5「メール成功→通知を消す」に対応する).
 *
 * 以前は宛先が空の警告も、直近の終端runの `alert_status = no_recipient` で
 * 判定していた. そのため、設定画面で宛先を保存しても、次の run が終わるまで
 * 警告が消えなかった. WP-Cron が動かないサイトでは次の run が来ないため、
 * 警告が消えないままになっていた(0.12.0 で修正).
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

		if ( ! current_user_can( WPCV_Capability::required( WPCV_Capability::SCREEN_NOTICES ) ) ) {
			return;
		}

		// 宛先の警告は今の設定だけで決まるので、run が無くても(有効化した直後でも)出す.
		self::render_no_recipient_notice();

		$run = WPCV_Plugin::run_repository()->find_most_recent_terminal_run();

		if ( null === $run ) {
			return;
		}

		self::render_alert_failed_notice( $run );
		self::render_access_denied_notice( $run );
	}

	/**
	 * 今の設定でアラートの宛先が空なら、警告を出す.
	 *
	 * 宛先を保存した時点で消えるよう、run の `alert_status` ではなく設定を見る.
	 * 宛先が空のあいだは、検出結果があってもメールを送らない
	 * (`WPCV_Alert_Sender::send_for_run()` が `no_recipient` を記録して終わる).
	 *
	 * @return void
	 */
	private static function render_no_recipient_notice() {
		if ( ! empty( WPCV_Settings::get_alert_to() ) ) {
			return;
		}

		self::render_notice(
			'notice-warning',
			__( 'WP Checksum Verifier: no alert recipients are set. Findings are not being emailed to anyone. Set at least one address on the WP Checksum Verifier settings screen.', 'wp-checksum-verifier' )
		);
	}

	/**
	 * 直近の終端 run でアラートメールの送信に失敗していれば、エラーを出す.
	 *
	 * 宛先の警告(`render_no_recipient_notice()`)とは別に判定するので、
	 * 宛先が空で、かつ前回の送信に失敗していた場合は両方出る.
	 *
	 * @param array $run 直近の終端 run の行.
	 * @return void
	 */
	private static function render_alert_failed_notice( array $run ) {
		$alert_status = $run['alert_status'] ?? null;

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
	 * GitHub のトークンの失効・権限不足で、照合元と比較できていない target があるときの通知を出す
	 * (v0.9 §Step7・U9・E4). 直近の終端 run に `source_access_denied` の本体 target が1件以上あれば出す.
	 *
	 * 連続 unverifiable のアラートの対象外にした代わりに、直るまで常に見える場所で知らせる.
	 * その target は stat 差分検知(ファイルの変化)で監視している. トークンの値は出さない.
	 *
	 * @param array $run 直近の終端 run の行.
	 * @return void
	 */
	private static function render_access_denied_notice( array $run ) {
		$count = 0;

		foreach ( WPCV_Plugin::target_run_repository()->find_all_by_run( (int) ( $run['id'] ?? 0 ) ) as $target_run ) {
			if ( WPCV_Target_Status::UNVERIFIABLE === ( $target_run['status'] ?? null ) && WPCV_Error_Code::SOURCE_ACCESS_DENIED === ( $target_run['error_code'] ?? null ) ) {
				++$count;
			}
		}

		if ( $count < 1 ) {
			return;
		}

		self::render_notice(
			'notice-warning',
			sprintf(
				/* translators: %d: number of targets that cannot be compared with their source. */
				__( 'WP Checksum Verifier: %d target(s) cannot be compared with their source because the GitHub token is invalid, expired or lacks permission. They are monitored by file-change tracking meanwhile. Check the GitHub token (the WPCV_GITHUB_TOKEN constant or the wpcv_github_token filter).', 'wp-checksum-verifier' ),
				$count
			)
		);
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
