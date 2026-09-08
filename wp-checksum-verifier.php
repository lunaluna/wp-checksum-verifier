<?php
/**
 * Plugin Name:       WP Checksum Verifier
 * Plugin URI:        https://github.com/lunaluna/wp-checksum-verifier
 * Description:       WordPress コア・プラグイン・テーマ・MU プラグインの checksum を日次で検証し、改ざんを検出するプラグイン.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            lunaluna_dev
 * Author URI:        https://profiles.wordpress.org/lunaluna_dev/
 * Update URI:        false
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-checksum-verifier
 * Domain Path:       /languages
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // セキュリティ: 直接アクセスを防止.
}

/**
 * DB スキーマの内部バージョン. migration の判定に使う(§5.1: 自由に上げてよい).
 */
define( 'WPCV_DB_VERSION', 1 );

/**
 * Public API contract のバージョン. 後方互換を維持する契約(§10).
 */
define( 'WPCV_API_VERSION', 1 );

/**
 * 翻訳ファイル (.mo) を読み込む.
 *
 * GitHub 配布で wp.org 未登録のため、翻訳の自動読み込みに頼らず明示的に読み込む.
 *
 * @return void
 */
function wpcv_load_textdomain() {
	load_plugin_textdomain(
		'wp-checksum-verifier',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'init', 'wpcv_load_textdomain' );

/**
 * プラグイン有効化時の環境チェック (PHP 7.4+, WP 6.8+).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/functions-activation.php';
register_activation_hook( __FILE__, 'wpcv_check_environment' );

/**
 * DB スキーマの作成・更新.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-migrator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-activator.php';
register_activation_hook( __FILE__, array( 'WPCV_Activator', 'activate' ) );

/**
 * 自動更新など有効化フックを経由せずに WPCV_DB_VERSION が上がった場合の追従.
 */
add_action( 'plugins_loaded', array( 'WPCV_Migrator', 'maybe_upgrade' ) );

/**
 * エラーコードの列挙(§5.4)と target モデル(§5.3: target_id の生成・分解).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-error-code.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-target-resolver.php';

/**
 * ファイルハッシュ算出とパス正規化(検証エンジンの土台).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-file-hasher.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-path-normalizer.php';

/**
 * Public API(§10). WPMAR 連携用に後方互換を維持する契約.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-api.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/functions-api.php';

/**
 * 管理画面(§11). フロントエンドの読み込みを避けるため管理画面でのみ読み込む.
 */
if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-wpcv-page-settings.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-wpcv-admin-menu.php';
	WPCV_Admin_Menu::register();
}

/**
 * GitHub Releases ベースの自己更新機構の読み込み(l2d-wp-github-update-lib).
 */
$wpcv_updater_register = require plugin_dir_path( __FILE__ ) . 'lib/l2d-updater/loader.php';
$wpcv_updater_register(
	array(
		'plugin_file' => __FILE__,
		'github_repo' => 'lunaluna/wp-checksum-verifier',
	)
);

/**
 * プラグイン一覧のメタ情報欄に GitHub へのリンクを追加する関数.
 *
 * @param string[] $links 既存のリンク(詳細、設定など).
 * @param string   $file  プラグインのベースファイル名.
 * @return string[] $links に追加した結果を返す.
 */
function wpcv_set_plugin_meta( $links, $file ) {
	static $this_plugin;
	$this_plugin = plugin_basename( __FILE__ );

	if ( $file === $this_plugin ) {
		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( 'https://github.com/lunaluna/wp-checksum-verifier' ),
			esc_html__( 'GitHub', 'wp-checksum-verifier' )
		);
	}

	return $links;
}
add_filter( 'plugin_row_meta', 'wpcv_set_plugin_meta', 10, 2 );
