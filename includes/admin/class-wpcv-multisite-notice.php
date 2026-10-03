<?php
/**
 * WPCV_Multisite_Notice クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * マルチサイトで、プラグインがサブサイトだけで有効化されているときの警告(v0.9プラン §3.2.1・M1・U10).
 *
 * 実測(alpine-dealer.local. 2026-10-03)で、ネットワーク有効化でも main site のみの有効化でもない
 * (= サブサイトだけで有効化した)場合は、`wpcv_scheduled_verify` の cron がどのサイトにも予約されず、
 * 自動実行が一度も走らないと分かった(cron の予約は main site だけ. `WPCV_Scheduler`)。メニューも
 * ネットワーク管理画面にしか登録しないので、通常の URL では画面にも出ない. 利用者が気づけない
 * ため、有効化したサブサイトの管理画面で警告する.
 *
 * 警告の対象は、そのサブサイトの管理画面を見る人(`activate_plugins` を持つ人). super admin とは
 * 限らないので、ネットワーク管理画面へのリンクは super admin にだけ出す. 表示する画面は
 * ダッシュボードとプラグイン一覧に限る(`WPCV_Admin_Notices` と同じ範囲. 全管理画面には出さない).
 * 有効化は止めない(U10: 意図的な使い方を塞がない).
 */
class WPCV_Multisite_Notice {

	/**
	 * 警告を出す画面の id(`get_current_screen()->id`).
	 *
	 * @var string[]
	 */
	const SCREEN_IDS = array( 'dashboard', 'plugins' );

	/**
	 * `admin_notices` へ登録する(単一サイトでは何もしない).
	 *
	 * `admin_notices` はネットワーク管理画面では発火しない(あちらは `network_admin_notices`)ので、
	 * サブサイトの管理画面にだけ出る.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'admin_notices', array( __CLASS__, 'maybe_render' ) );
	}

	/**
	 * 警告を出すべき状態かを判定する(DB・WordPress の関数に触れない純粋な判定).
	 *
	 * 自動実行が走るのは、ネットワーク有効、または main site で有効のとき(cron は main site の
	 * コンテキストで予約される. main site のコンテキストでプラグインが読み込まれるのは、その
	 * どちらかのとき). どちらでもなければ、警告する.
	 *
	 * @param bool $is_multisite       マルチサイトか.
	 * @param bool $network_active     ネットワーク有効か.
	 * @param bool $main_site_active   main site で有効か.
	 * @return bool
	 */
	public static function should_warn( $is_multisite, $network_active, $main_site_active ) {
		return $is_multisite && ! $network_active && ! $main_site_active;
	}

	/**
	 * 対象の画面で、権限があり、警告すべき状態なら、警告を出す.
	 *
	 * @return void
	 */
	public static function maybe_render() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || ! in_array( $screen->id, self::SCREEN_IDS, true ) ) {
			return;
		}

		$basename = plugin_basename( dirname( __DIR__, 2 ) . '/wp-checksum-verifier.php' );

		if ( ! self::should_warn(
			is_multisite(),
			is_plugin_active_for_network( $basename ),
			self::is_active_on_main_site( $basename )
		) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'WP Checksum Verifier is active only on this site, not network-wide. Its scheduled checks do not run and its screens are not available from the Network Admin. Network-activate it to use it on a multisite network.', 'wp-checksum-verifier' );

		if ( is_super_admin() ) {
			printf(
				' <a href="%s">%s</a>',
				esc_url( network_admin_url( 'plugins.php' ) ),
				esc_html__( 'Open Network Admin plugins', 'wp-checksum-verifier' )
			);
		}

		echo '</p></div>';
	}

	/**
	 * Main site で有効か.
	 *
	 * @param string $basename プラグインの basename(`dir/file.php`).
	 * @return bool
	 */
	private static function is_active_on_main_site( $basename ) {
		return in_array( $basename, (array) get_blog_option( get_main_site_id(), 'active_plugins', array() ), true );
	}
}
