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
	 *     busy: bool,
	 * } enqueue できたときは `action_id` のみ、同期フォールバックしたときは
	 *   `WPCV_Run_Coordinator::run()` の戻り値をそのまま `result` に入れる。
	 *   `busy` は同期フォールバック時に、実行権の予約が失敗した(lock取得失敗、
	 *   または既に active な run がある)ことを表す(v0.3.1 §Step1).
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
				'busy'      => false,
			);
		}

		// 検証開始前に実行権(run 行)を予約する(v0.3.1 §Step1: CLI 同期パスと同じ理由。
		// `WPCV_CLI_Command::__invoke()` 参照。ここでの `queued` 経由の enqueue-time
		// 予約(プランの Step2 設計)はまだ実装しない — Action Scheduler 自体が
		// 使えない状況でのこの同期フォールバック自体には queued 状態は意味を
		// 持たないため、常に `running` で直接予約する).
		$reservation = WPCV_Plugin::repository()->reserve_run(
			array(
				'run_trigger' => $run_trigger,
				'runner'      => 'sync',
			)
		);

		if ( $reservation['lock_failed'] || $reservation['active'] ) {
			return array(
				'enqueued'  => false,
				'action_id' => null,
				'result'    => null,
				'busy'      => true,
			);
		}

		$context = WPCV_Context_Builder::build( $run_trigger );

		return array(
			'enqueued'  => false,
			'action_id' => null,
			'result'    => WPCV_Plugin::run_coordinator()->run( $reservation['run_id'], $context ),
			'busy'      => false,
		);
	}

	/**
	 * `self::HOOK` のフックハンドラ. Action Scheduler のワーカーから呼ばれる.
	 *
	 * `$run_trigger` から `WPCV_Context_Builder::build()` で `$context` を都度
	 * 組み立て直す(クラス docblock 参照。enqueue 時点の `$context` は保持しない)。
	 *
	 * 検証開始前に実行権(run 行)を予約する(v0.3.1 §Step1)。enqueue 時点で
	 * `queued` run を作って `run_id` を Action Scheduler の args として渡す設計
	 * (プランの Step2)はまだ実装していないため、ここではワーカー起動時点で
	 * `running` を直接予約する(v0.3.1 Step2 で `mark_queued_running()` を使う
	 * 形に置き換える想定。それまでの暫定実装であることに注意).
	 *
	 * @param string $run_trigger `enqueue_run()` に渡されたもの.
	 * @return void
	 */
	public static function run_async_action( $run_trigger ) {
		$reservation = WPCV_Plugin::repository()->reserve_run(
			array(
				'run_trigger' => (string) $run_trigger,
				'runner'      => 'async',
			)
		);

		if ( $reservation['lock_failed'] || $reservation['active'] ) {
			return;
		}

		$context = WPCV_Context_Builder::build( (string) $run_trigger );

		WPCV_Plugin::run_coordinator()->run( $reservation['run_id'], $context );
	}
}

add_action( WPCV_Runner_Async::HOOK, array( 'WPCV_Runner_Async', 'run_async_action' ) );
