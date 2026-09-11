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
	 * Action Scheduler へ enqueue するときの group(v0.3.1 §Step2)。
	 *
	 * `as_enqueue_async_action()` の `unique = true` と組み合わせて使う補助防御
	 * (主制御は `WPCV_Run_Repository::reserve_run()` の advisory lock。クラス
	 * docblock 参照)。group を固定することで、他プラグインの同名 hook との
	 * 偶発的な unique 判定の混線も避けられる.
	 *
	 * @var string
	 */
	const GROUP = 'wpcv';

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
	 * 既定の可用性チェックは `function_exists( 'as_enqueue_async_action' )` だけでなく
	 * `ActionScheduler::is_initialized()`(データストアの `init` フック完了後にのみ
	 * 真になる。`lib/action-scheduler/classes/abstracts/ActionScheduler.php` 参照)も
	 * 見る(v0.3.1 §Step2「AS関数の存在だけでなく、初期化済みかを判定する」)。
	 * `as_enqueue_async_action()` 自体も内部で同じ判定をしており未初期化なら `0` を
	 * 返すだけだが、ここで事前に弾くことで(0を受けてrunをfailedにするのではなく)
	 * 同期フォールバックへ倒すという、より望ましい挙動になる.
	 *
	 * @param string        $run_trigger          `'cron'|'manual'|'cli'|'rest'`.
	 * @param callable|null $availability_checker 省略時は
	 *                                            `function_exists('as_enqueue_async_action') && class_exists('ActionScheduler') && ActionScheduler::is_initialized()`.
	 * @return array{
	 *     enqueued: bool,
	 *     action_id: int|null,
	 *     result: array|null,
	 *     busy: bool,
	 * } enqueue できたときは `action_id` のみ、同期フォールバックしたときは
	 *   `WPCV_Run_Coordinator::run()` の戻り値をそのまま `result` に入れる。
	 *   `busy` は実行権の予約が失敗した(lock取得失敗、または既に active な run が
	 *   ある)ことを表す(v0.3.1 §Step1・Step2。enqueue 経路・同期フォールバック
	 *   経路のどちらでも起こりうる).
	 */
	public static function enqueue_run( $run_trigger, ?callable $availability_checker = null ) {
		if ( null === $availability_checker ) {
			$availability_checker = static function () {
				return function_exists( 'as_enqueue_async_action' )
					&& class_exists( 'ActionScheduler' )
					&& ActionScheduler::is_initialized();
			};
		}

		if ( call_user_func( $availability_checker ) ) {
			return self::enqueue_via_action_scheduler( $run_trigger );
		}

		return self::run_sync_fallback( $run_trigger );
	}

	/**
	 * `queued` run を予約してから Action Scheduler へ enqueue する(v0.3.1 §Step2).
	 *
	 * Action Scheduler へ enqueue する args は `run_id` と `run_trigger` のみ($context 全体は渡さない。
	 * クラス docblock の「8,000文字上限」参照)。`unique = true` は補助防御に過ぎず、
	 * 同時受付の直列化そのものは `reserve_run()` の advisory lock が担う
	 * (`self::GROUP` の docblock 参照).
	 *
	 * @param string $run_trigger `'cron'|'manual'|'cli'|'rest'`.
	 * @return array `enqueue_run()` の戻り値と同じ形.
	 */
	private static function enqueue_via_action_scheduler( $run_trigger ) {
		$reservation = WPCV_Plugin::run_repository()->reserve_run(
			array(
				'run_trigger'    => $run_trigger,
				'runner'         => 'async',
				'initial_status' => WPCV_Run_Status::QUEUED,
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

		$run_id    = $reservation['run_id'];
		$action_id = as_enqueue_async_action( self::HOOK, array( $run_id, $run_trigger ), self::GROUP, true );

		if ( (int) $action_id <= 0 ) {
			// enqueue 自体の失敗(戻り値が正の整数でない)を成功扱いしない
			// (プラン§P1「enqueue失敗を成功扱いする」への対策)。予約済みの
			// queued run は failed として記録し、呼び出し元に受付失敗を返す.
			WPCV_Plugin::run_repository()->mark_run_failed(
				$run_id,
				sprintf( 'as_enqueue_async_action() が有効なaction_idを返しませんでした(戻り値: %d).', (int) $action_id )
			);

			return array(
				'enqueued'  => false,
				'action_id' => null,
				'result'    => null,
				'busy'      => false,
			);
		}

		return array(
			'enqueued'  => true,
			'action_id' => (int) $action_id,
			'result'    => null,
			'busy'      => false,
		);
	}

	/**
	 * 実行権(run 行)を予約してから同期実行する(Action Scheduler が使えない場合).
	 *
	 * @param string $run_trigger `'cron'|'manual'|'cli'|'rest'`.
	 * @return array `enqueue_run()` の戻り値と同じ形.
	 */
	private static function run_sync_fallback( $run_trigger ) {
		$reservation = WPCV_Plugin::run_repository()->reserve_run(
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
	 * `enqueue_via_action_scheduler()` が enqueue 時点で予約しておいた `queued` run を
	 * `mark_queued_running()` で引き継ぐ(v0.3.1 §Step2)。対象行が既に `queued`
	 * ではない場合(stale sweep に先を越された、Action Scheduler の再実行で
	 * 同じ action が2度発火した等)は検証を行わず no-op で戻る(プラン§Step2の
	 * テスト「同じAS actionが再実行されても検証は1回だけ行う」への対策).
	 *
	 * `$context` は `$run_trigger` から `WPCV_Context_Builder::build()` で都度
	 * 組み立て直す(クラス docblock 参照。enqueue 時点の `$context` は保持しない).
	 *
	 * @param int    $run_id      `enqueue_via_action_scheduler()` が enqueue した run の id.
	 * @param string $run_trigger `enqueue_run()` に渡されたもの.
	 * @return void
	 */
	public static function run_async_action( $run_id, $run_trigger ) {
		if ( ! WPCV_Plugin::run_repository()->mark_queued_running( (int) $run_id ) ) {
			return;
		}

		$context = WPCV_Context_Builder::build( (string) $run_trigger );

		WPCV_Plugin::run_coordinator()->run( (int) $run_id, $context );
	}
}

add_action( WPCV_Runner_Async::HOOK, array( 'WPCV_Runner_Async', 'run_async_action' ), 10, 2 );
