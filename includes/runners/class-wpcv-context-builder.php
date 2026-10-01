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

		$context['themes'] = self::describe_themes();

		// v0.8 §Step6: GitHub との対応付け(target_id => repo・asset). Planner は HTTP・DB に
		// 触れない不変条件なので、設定とフィルターの解決はここで済ませて渡す.
		$context['github_mappings'] = WPCV_GitHub_Mappings::resolve();

		return $context;
	}

	/**
	 * インストールされているテーマを、`WPCV_Run_Planner`・`WPCV_Chunk_Dispatcher` が
	 * 使う形にまとめる(v0.7 §3.3).
	 *
	 * `errors => null` で、エラーのあるテーマ(親テーマが無い子テーマなど)も含める.
	 * 既定の `errors => false` はそれらを隠す(`wp-includes/theme.php:92-95`)が、
	 * そうしたテーマこそ stat で見たい(D5). マルチサイトでも `allowed` を指定しない
	 * ので、どのサイトで有効かに関係なく、ディスク上の全テーマが対象になる
	 * (照合はインストール全体が単位. `WPCV_Migrator` のクラス docblock 参照).
	 *
	 * ディレクトリは `get_stylesheet_directory()` で取る. `register_theme_directory()` で
	 * テーマのルートが複数ありうるため、`WP_CONTENT_DIR . '/themes/'` から組み立てない(§1.5).
	 *
	 * @return array<string, array{version: string, template: string, stylesheet_dir: string, update_uri: string}>
	 *         stylesheet をキーにした配列.
	 */
	private static function describe_themes() {
		if ( ! function_exists( 'wp_get_themes' ) ) {
			return array();
		}

		$themes = array();

		foreach ( wp_get_themes( array( 'errors' => null ) ) as $stylesheet => $theme ) {
			$themes[ (string) $stylesheet ] = array(
				'version'        => (string) $theme->get( 'Version' ),
				'template'       => (string) $theme->get_template(),
				'stylesheet_dir' => (string) $theme->get_stylesheet_directory(),
				'update_uri'     => (string) $theme->get( 'UpdateURI' ),
			);
		}

		return $themes;
	}
}
