<?php
/**
 * WPCV_Runner_Async クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Cron・「今すぐ実行」・REST・CLI `--async` が収束する単一の enqueue エントリ
 * ポイント(v0.3 §Step4)。Action Scheduler の可用性で同期/非同期を内部分岐する
 * (WPMAR のパターンを踏襲)。
 *
 * CLI の既定(引数無し実行)はこのクラスを経由しない(§6の「CLIは実行時間制限が
 * 無く同期が最も確実」という方針。`WPCV_CLI_Command` の docblock 参照。`--async`
 * のときのみここを通る).
 *
 * enqueue するのは `$run_trigger`(文字列)のみで、`$context` 全体は渡さない.
 * 当初案(enqueue 時点で `$context` をシリアライズしてそのまま持ち回る)は実地
 * 検証で破綻した: `get_plugins()` の全ヘッダーを含む `$context` は実測で
 * 23,435文字(JSON化後)になり、Action Scheduler の `ActionScheduler_Action`
 * は「args を JSON 化した文字列が8,000文字を超えると保存できない」という
 * ハード制限を持つ(超過分は `ActionScheduler_ActionFactory::create()` 内部で
 * 例外として握り潰され、`as_enqueue_async_action()` は静かに `0` を返す。
 * `debug.log` の `Caught exception while enqueuing action ...: ... $args too
 * long` で判明)。ワーカー実行時に `WPCV_Context_Builder::build()` で毎回
 * 組み立て直す設計に変更したことで、この文字数上限を回避しつつ、enqueue から
 * 実行までの間にプラグイン構成が変わるリスク(§6.3で許容していたもの)もむしろ
 * 解消される(実行時点の最新状態を使うため).
 */
class WPCV_Runner_Async {

	/**
	 * Enqueue された run を実行する Action Scheduler フック名.
	 *
	 * @var string
	 */
	const HOOK = 'wpcv_run_async';

	/**
	 * 1回の run を enqueue するか、Action Scheduler が使えなければ同期フォールバック実行する.
	 *
	 * 可用性チェックを `function_exists( 'as_enqueue_async_action' )` 固定にせず
	 * 注入可能な callable にしているのは、PHPUnit プロセス内で一度スタブとして
	 * 定義した関数を後から未定義に戻せない(PHP の言語仕様上、関数の再定義・削除は
	 * 不可能)ため。固定にすると、あるテストで「利用可能」を模倣する目的で
	 * `as_enqueue_async_action` 関数を定義した場合、その状態がプロセス内の以降の
	 * 全テストに漏れて「利用不可」の分岐を検証できなくなる事故が起きる.
	 *
	 * @param string        $run_trigger          `'cron'|'manual'|'cli'|'rest'`.
	 * @param callable|null $availability_checker 省略時は `function_exists( 'as_enqueue_async_action' )`.
	 * @return array{
	 *     enqueued: bool,
	 *     action_id: int|null,
	 *     result: array|null,
	 * } enqueue できたときは `action_id` のみ、同期フォールバックしたときは
	 *   `WPCV_Run_Coordinator::run()` の戻り値をそのまま `result` に入れる.
	 */
	public static function enqueue_run( $run_trigger, ?callable $availability_checker = null ) {
		if ( null === $availability_checker ) {
			$availability_checker = static function () {
				return function_exists( 'as_enqueue_async_action' );
			};
		}

		if ( call_user_func( $availability_checker ) ) {
			$action_id = as_enqueue_async_action( self::HOOK, array( $run_trigger ) );

			return array(
				'enqueued'  => true,
				'action_id' => (int) $action_id,
				'result'    => null,
			);
		}

		$context           = WPCV_Context_Builder::build( $run_trigger );
		$context['runner'] = 'sync';

		return array(
			'enqueued'  => false,
			'action_id' => null,
			'result'    => WPCV_Plugin::run_coordinator()->run( $context ),
		);
	}

	/**
	 * `self::HOOK` のフックハンドラ. Action Scheduler のワーカーから呼ばれる.
	 *
	 * `$run_trigger` から `WPCV_Context_Builder::build()` で `$context` を都度
	 * 組み立て直す(クラス docblock 参照。enqueue 時点の `$context` は保持しない).
	 *
	 * @param string $run_trigger `enqueue_run()` に渡されたもの.
	 * @return void
	 */
	public static function run_async_action( $run_trigger ) {
		$context           = WPCV_Context_Builder::build( (string) $run_trigger );
		$context['runner'] = 'async';

		WPCV_Plugin::run_coordinator()->run( $context );
	}
}

add_action( WPCV_Runner_Async::HOOK, array( 'WPCV_Runner_Async', 'run_async_action' ) );
