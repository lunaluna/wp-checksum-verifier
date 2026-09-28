<?php
/**
 * Plugin Name:       WP Checksum Verifier
 * Plugin URI:        https://github.com/lunaluna/wp-checksum-verifier
 * Description:       WordPress コア・プラグイン・テーマ・MU プラグインの checksum を日次で検証し、改ざんを検出するプラグイン.
 * Version:           0.4.0
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
 *
 * チャンク分割実行・run deadline・抑制ルール参照のための列を runs/target_runs/
 * findingsへ追加したため、v0.4.0 §Step1で2へ更新した(`WPCV_Migrator::table_definitions()` 参照).
 *
 * stat 差分検知(rev.3 §3.3)用の `wpcv_file_states` テーブル新設と、
 * `wpcv_findings.detail` 列の追加のため、v0.5 §4.2 Step1で3へ更新した.
 *
 * 差分検出基盤・アラート(v0.5後半プラン §1)用に、runs/target_runs/findingsへ
 * finding_key・diff_state・diff_status等の列とインデックスを追加したため、
 * v0.5後半 §Step10で4へ更新した.
 *
 * Step12の実地検証(test-armfu.local、1万・10万件規模)で、
 * `WPCV_Finding_Repository::find_batch_by_target_run()`/`find_baseline_batch()`
 * (`WHERE target_run_id=? AND id>? ORDER BY id ASC LIMIT ?`)が
 * `(target_run_id, finding_key)`しか無いためPRIMARY(id)スキャンになり、
 * テーブル全体の件数に比例してコストが増える性能上の懸念が見つかったため、
 * `(target_run_id, id)`の複合indexを追加してv0.5後半 §Step12で5へ更新した.
 */
define( 'WPCV_DB_VERSION', 5 );

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
 *
 * `WPCV_Migrator::maybe_upgrade()` の戻り値(bool。v0.4.0コードレビューCR-05是正)は
 * ここでは意図的に無視する(`maybe_upgrade()` 自身のdocblock参照。`plugins_loaded`
 * は毎リクエスト無条件で発火するため、失敗を可視化する責務は有効化フック
 * 〔`WPCV_Activator::activate()`〕側にある)。クロージャで包むのは、action
 * コールバックはPHPStanの規約上値を返すべきではないため(`add_action()` に
 * メソッド参照を直接渡すと戻り値の型が伝播してしまう).
 */
add_action(
	'plugins_loaded',
	static function () {
		WPCV_Migrator::maybe_upgrade();
	}
);

/**
 * エラーコードの列挙(§5.4)と target モデル(§5.3: target_id の生成・分解).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-error-code.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-target-resolver.php';

/**
 * Run/target の状態定数と遷移検証(v0.4.0 §Step1). Repository群より前に
 * 読み込む必要がある(`WPCV_Run_Repository` 等が参照するため).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-run-status.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-target-status.php';

/**
 * `wpcv_runs.diff_status` の状態定数(v0.5後半 §Step12). `WPCV_Run_Repository` が
 * 参照するため、他のstatus定数クラスと同じ位置(Repository群より前)に置く.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-diff-status.php';

/**
 * 抑制ルールのtype定数(v0.4.0 §Step8). `WPCV_Suppression_Repository`/
 * `WPCV_Run_Planner` 等より前に読み込む必要がある.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-suppression-type.php';

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
 * Chunk予算(時間・件数・メモリ)判定の共有ロジック(v0.4.0コードレビューCR-08是正)。
 * `WPCV_Unknown_File_Scanner`・`WPCV_Chunk_Verifier` の両方より前に読み込む必要がある.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-chunk-budget.php';

/**
 * 未知ファイル検出(§3.6の土台)と検証エンジン本体.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-unknown-file-scanner.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-verifier.php';

/**
 * Stat差分検知(rev.3 §3)の負荷実測ロジック(v0.5 §4.2 Step4)。
 * `WPCV_Unknown_File_Scanner` に依存するため、その後に読み込む.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-stat-bench.php';

/**
 * Chunk分割実行のための決定的な順序付け・fingerprint計算とchunk単位の検証本体
 * (v0.4.0 §Step3). `WPCV_Chunk_Dispatcher`(§Step4)が呼び出し元.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-chunk-cursor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-chunk-verifier.php';

/**
 * Finding単位の抑制判定(v0.4.0 §Step8。exclude_path/allowlist_hash/soft change)。
 * `WPCV_Chunk_Result_Repository` が呼び出し元のため先に読み込む.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-suppression-matcher.php';

/**
 * `finding` の差分キー計算(v0.5後半 §Step10). `WPCV_Finding_Repository::save_findings()`
 * が呼び出し元のため先に読み込む.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-finding-key.php';

/**
 * 世代比較(NEW/CONTINUING/RESOLVED)の判定ロジック(v0.5後半 §Step11. DBに触れない
 * 純粋ロジック). 差分処理の実行(Step12以降)が呼び出し元になる予定だが、
 * `WPCV_Target_Status`/`WPCV_Error_Code` に依存するため、それらより後・
 * 差分処理本体より前のこの位置で読み込む.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-generation-differ.php';

/**
 * アラートメールの件名・本文の組み立て(v0.5後半 §Step13. DBに触れない純粋ロジック).
 * `WPCV_Target_Resolver`/`WPCV_Target_Status` に依存するため、それらより後に読み込む.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/engine/class-wpcv-alert-composer.php';

/**
 * DB 永続化層(§4.2. v0.4.0 §Step1でrun/target_run/findingの3責務に分割)と、
 * 1回分の run のライフサイクルを統括する Coordinator.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-run-repository.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-target-run-repository.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-finding-repository.php';

/**
 * 抑制ルール(`wpcv_suppressions`)の永続化層(v0.4.0 §Step8)。
 * `WPCV_Chunk_Result_Repository`・`WPCV_Run_Planner` より前に読み込む必要がある.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-suppression-repository.php';

/**
 * Stat差分検知(rev.3 §3)のベースライン(`wpcv_file_states`)の永続化層
 * (v0.5 §4.2 Step2)。他クラスからの依存はまだ無いため読み込み順の制約は無いが、
 * 他のRepository群と同じ場所にまとめる.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-file-state-repository.php';

/**
 * Chunk結果(cursor更新とfindings保存)をtransactionで確定する調整役(v0.4.0 §Step3).
 * `WPCV_Target_Run_Repository`/`WPCV_Finding_Repository`/`WPCV_Suppression_Repository`
 * より後に読み込む必要がある.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-chunk-result-repository.php';

/**
 * Run 開始時点でのtarget列挙(v0.4.0 §Step2). `WPCV_Chunk_Dispatcher` が列挙
 * ロジック(`CORE_BUNDLED_PLUGIN_FILES`・slug解決)を利用するため、先に読み込む.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/runners/class-wpcv-run-planner.php';

/**
 * Chunk分割実行のdispatcher(v0.4.0 §Step4)。`WPCV_Run_Coordinator`(次で読み込む)
 * がコンストラクタで型宣言するため先に読み込む必要がある.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/runners/class-wpcv-chunk-dispatcher.php';

/**
 * 差分処理(v0.5後半 §Step12)のdispatcher。`WPCV_Generation_Differ`
 * (`determine_diff_mode()`等)に依存するため、それより後に読み込む必要がある.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/runners/class-wpcv-diff-dispatcher.php';

/**
 * アラートの送信(v0.5後半 §Step14). `WPCV_Alert_Composer`(本文組み立て)・
 * `WPCV_Generation_Differ`(通知要否の判定)・`WPCV_Run_Repository`/
 * `WPCV_Target_Run_Repository`/`WPCV_Finding_Repository`に依存するため、
 * いずれもそれより後に読み込む必要がある. このStep時点ではまだどこからも
 * 呼ばれない(配線はStep14cで`alerting`段階から行う).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/runners/class-wpcv-alert-sender.php';

/**
 * Run の連続失敗アラート(v0.5後半 §Step15a)。`wpcv_run_terminated`フックの
 * ハンドラをファイル末尾で登録するため、`WPCV_Run_Repository`(フックの発火元)・
 * `WPCV_Alert_Sender`(送信先)より後に読み込む必要がある。`WPCV_Plugin`本体は
 * まだ読み込まれていないが、フック登録は `array( 'WPCV_Plugin', ... )` という
 * 遅延解決の形のため問題ない(`WPCV_Chunk_Dispatcher::HOOK` の登録と同じ理由).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/runners/class-wpcv-run-failure-alerter.php';

/**
 * Run開始時の「列挙(plan)→保存」を失敗時の後始末込みで行う共通処理
 * (v0.4.0 §Step5)。`WPCV_Run_Coordinator`・`WPCV_Runner_Async` の両方が使う.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/runners/class-wpcv-run-starter.php';

/**
 * 1回分の検証(run)を、chunk分割実行の上で完走するまでループする薄いadapter
 * (v0.4.0 §Step5. `WPCV_Run_Coordinator` のクラス docblock 参照).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/runners/class-wpcv-run-coordinator.php';

/**
 * 実際の WordPress 環境から $context を組み立てる builder と、本番用の依存を
 * 配線する composition root(v0.3 §6: 実行モデルのエントリポイント共通の土台).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/runners/class-wpcv-context-builder.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpcv-plugin.php';

/**
 * WP-Cron・「今すぐ実行」・REST・CLI `--async` が収束する非同期実行の一本化
 * エントリポイント(v0.3 §Step4。v0.4.0 §Step5でAS worker側をchunk dispatcherの
 * 1回呼び出しに書き換えた). エントリポイント(WP-Cron等)より前に読み込む
 * 必要があるため、常に読み込む(WP-CLI 同様の条件分岐はしない).
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
 * REST `POST /wp-json/wpcv/v1/run`(v0.3 §Step8・v0.4.0 §Step6で日次due判定+
 * 時間予算ループに書き換え)、`GET /wp-json/wpcv/v1/status`・
 * `GET /wp-json/wpcv/v1/findings`(v0.4.0 §Step7)と、その認証を担うトークン方式
 * (v0.3 §Step9・v0.4.0 §Step7でscope分離).
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/rest/class-wpcv-rest-token.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/rest/class-wpcv-rest-support.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/rest/class-wpcv-rest-run-controller.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/rest/class-wpcv-rest-status-controller.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/rest/class-wpcv-rest-findings-controller.php';
add_action( 'rest_api_init', array( 'WPCV_Rest_Run_Controller', 'register_routes' ) );
add_action( 'rest_api_init', array( 'WPCV_Rest_Status_Controller', 'register_routes' ) );
add_action( 'rest_api_init', array( 'WPCV_Rest_Findings_Controller', 'register_routes' ) );

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
	require_once plugin_dir_path( __FILE__ ) . 'includes/cli/class-wpcv-cli-bench-stat-command.php';
}

/**
 * 管理画面(§11). フロントエンドの読み込みを避けるため管理画面でのみ読み込む.
 */
if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-wpcv-page-settings.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-wpcv-page-run-history.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-wpcv-page-findings.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-wpcv-page-suppressions.php';
	// v0.5後半 §Step14d: アラートの管理画面通知(§2.5). `WPCV_Admin_Menu`が
	// `register()`を呼ぶため、それより先に読み込む必要がある.
	require_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-wpcv-admin-notices.php';
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
