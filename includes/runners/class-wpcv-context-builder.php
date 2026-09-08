<?php
/**
 * WPCV_Context_Builder クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 実際の WordPress 環境から `WPCV_Run_Coordinator::run()` が要求する `$context` を組み立てる.
 *
 * `WPCV_Run_Coordinator` 自身は `get_plugins()` 等の WordPress 関数を呼ばない設計
 * (単体テストで実 WordPress 環境を必要としないため。同クラスの docblock 参照)に
 * なっているため、実環境からの読み取りをこのクラスに集約する(§6、v0.3 対象).
 */
class WPCV_Context_Builder {

	/**
	 * `$context` を組み立てる.
	 *
	 * @param string $run_trigger 呼び出し元の種別. `'manual'|'cli'|'cron'|'rest'`. 既定 `'manual'`.
	 * @return array `WPCV_Run_Coordinator::run()` にそのまま渡せる `$context`.
	 */
	public static function build( $run_trigger = 'manual' ) {
		$context = array(
			'version'     => get_bloginfo( 'version' ),
			'run_trigger' => (string) $run_trigger,
		);

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$context['plugins'] = get_plugins();

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$context['plugin_dir'] = WP_PLUGIN_DIR;
		}

		// WPMU_PLUGIN_DIR 未定義の環境(テスト等)では MU プラグイン領域の検証を
		// スキップさせる(`WPCV_Run_Coordinator::run()` は `mu_plugin_dir` が
		// 空ならスキップする設計. 同クラスの docblock 参照).
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$context['mu_plugin_dir'] = WPMU_PLUGIN_DIR;
			$context['mu_plugins']    = get_mu_plugins();
		}

		return $context;
	}
}
