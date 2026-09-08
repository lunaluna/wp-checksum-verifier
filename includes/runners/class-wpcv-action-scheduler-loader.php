<?php
/**
 * WPCV_Action_Scheduler_Loader クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action Scheduler(`woocommerce/action-scheduler`)を「使えるときだけ使う」ための
 * 読み込みガード(v0.3 §Step3。非同期実行(Step4以降)が依存する).
 *
 * 配布方法を2通り許容する: 本番ビルドzipは `bin/build-zip.pre.sh` が
 * `vendor/woocommerce/action-scheduler` を `lib/action-scheduler/` へコピーする
 * 方式(README参照)。開発環境は `composer install` だけで `vendor/` 配下に
 * インストールされる(`composer.json` の `require`。dev ではない)ものを
 * そのまま使う。WPMAR(姉妹プラグイン)と同じ「`lib/` 優先、無ければ `vendor/`
 * にフォールバック」のパターンを踏襲する.
 *
 * 読み込みに成功しても `as_enqueue_async_action()` 等の関数が即座に使えるとは
 * 限らない(Action Scheduler 自身が `plugins_loaded` フックで自身の API を
 * 初期化するため。このクラスはあくまでファイルを require するだけ)。
 * 呼び出し側は必ず `function_exists( 'as_enqueue_async_action' )` でガード
 * すること(Step4以降の非同期実行エントリポイントの前提).
 */
class WPCV_Action_Scheduler_Loader {

	/**
	 * 読み込み候補の相対パス(`$plugin_dir` からの相対). 先に見つかった方を使う.
	 *
	 * @var string[]
	 */
	const CANDIDATE_RELATIVE_PATHS = array(
		'lib/action-scheduler/action-scheduler.php',
		'vendor/woocommerce/action-scheduler/action-scheduler.php',
	);

	/**
	 * Action Scheduler を可能なら読み込む.
	 *
	 * `plugins_loaded`(優先度0)より前、プラグインファイルの読み込み時点で
	 * 呼び出すこと(Action Scheduler 自身の `plugins_loaded` 優先度0の
	 * ブートストラップに間に合わせるため。呼び出し側の docblock 参照).
	 *
	 * @param string $plugin_dir プラグインのルートディレクトリの絶対パス.
	 * @return void
	 */
	public static function maybe_load( $plugin_dir ) {
		// 他プラグインが既に同梱の Action Scheduler を読み込み済みの場合、
		// 二重 require によるクラス再定義の致命的エラーを避ける.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		$plugin_dir = rtrim( $plugin_dir, '/' );

		foreach ( self::CANDIDATE_RELATIVE_PATHS as $relative_path ) {
			$absolute_path = $plugin_dir . '/' . $relative_path;

			if ( is_readable( $absolute_path ) ) {
				require_once $absolute_path;
				return;
			}
		}
	}
}
