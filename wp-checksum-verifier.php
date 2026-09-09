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
 * Action Scheduler(非同期実行の基盤. v0.3 §6 Step4以降で使用)を可能なら読み込む.
 *
 * `plugins_loaded`(優先度0)より前、プラグインファイルの読み込み時点で呼び出す
 * 必要がある(Action Scheduler 自身の `plugins_loaded` 優先度0のブートストラップに
 * 間に合わせるため。`WPCV_Action_Scheduler_Loader` の docblock 参照)。
 * ライブラリが同梱されていない環境でもプラグイン自体は動作し続ける(同期実行の
 * WP-CLI/WP-Cron/REST パスは Action Scheduler に依存しない設計。§6).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/runners/class-wpcv-action-scheduler-loader.php';
WPCV_Action_Scheduler_Loader::maybe_load( plugin_dir_path( __FILE__ ) );

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
 * 照合ソース(§3). コア照合(§3.2)と wp.org 公式プラグイン照合(§3.4).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/sources/interface-wpcv-manifest-source.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/sources/class-wpcv-source-core.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/sources/class-wpcv-source-wporg-plugin.php';

/**
 * 未知ファイル検出(§3.6の土台)と検証エンジン本体.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-unknown-file-scanner.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-verifier.php';

/**
 * DB 永続化層(§4.2)と、1回分の run のライフサイクルを統括する Coordinator.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-repository.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/runners/class-wpcv-run-coordinator.php';

/**
 * 実際の WordPress 環境から $context を組み立てる builder と、本番用の依存を
 * 配線する composition root(v0.3 §6: 実行モデルのエントリポイント共通の土台).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/runners/class-wpcv-context-builder.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-plugin.php';

/**
 * WP-Cron・「今すぐ実行」・REST・CLI `--async` が収束する非同期実行の一本化
 * エントリポイント(v0.3 §Step4). Step5以降の呼び出し元(WP-Cron等)より前に
 * 読み込む必要があるため、常に読み込む(WP-CLI 同様の条件分岐はしない).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/runners/class-wpcv-runner-async.php';

/**
 * 設定値の保存機構(実行時刻)と、既定の自動実行経路である WP-Cron の
 * 自己連鎖(v0.3 §Step6).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-scheduler.php';
WPCV_Scheduler::init();
register_activation_hook( __FILE__, array( 'WPCV_Scheduler', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPCV_Scheduler', 'deactivate' ) );

/**
 * REST `POST /wp-json/wpcv/v1/run`(v0.3 §Step8. モードC・簡略版).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/rest/class-wpcv-rest-run-controller.php';
add_action( 'rest_api_init', array( 'WPCV_Rest_Run_Controller', 'register_routes' ) );

/**
 * Public API(§10). WPMAR 連携用に後方互換を維持する契約.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-api.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/functions-api.php';

/**
 * WP-CLI エントリポイント(§6.2). `wp` 経由で実行されたときのみ読み込む
 * (`WP_CLI_Command` 等 WP-CLI 自身が定義するクラスへの依存を、通常のリクエストで
 * 読み込まないようにするため).
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/cli/class-wpcv-cli-command.php';
}

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
