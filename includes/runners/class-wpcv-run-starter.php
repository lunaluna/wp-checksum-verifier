<?php
/**
 * WPCV_Run_Starter クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run開始時の「列挙(plan)→保存→`planning`から`running`への遷移」を、失敗時の
 * 後始末(run failed 化)込みで行う共通処理(v0.4.0 §Step5)。
 *
 * `WPCV_Run_Coordinator::run()`(同期ループの先頭)と `WPCV_Runner_Async::run_async_action()`
 * (AS worker が最初に触れた時点)のどちらも「予約済みのrun_idに対して、まだ
 * target_runsが存在しない状態からplanして保存する」という同じ処理を必要とする。
 * これを2箇所に別々に書くと、`WPCV_Run_Planner::plan()` が投げる例外の扱い
 * (`WPCV_Run_Repository::mark_run_failed()` を呼んでから再送出する、という
 * v0.3.1 §Step1由来の「途中の例外で失敗記録が残らない」への対策)が2箇所で
 * ドリフトする実害があるため、共有クラスとして抽出した.
 *
 * v0.4.0コードレビューCR-01是正: `save_target_runs()` が完了するまでrunを
 * `running`にしない(呼び出し元は`planning`状態のrun_idを渡す。`WPCV_Run_Repository::
 * reserve_run()`/`reserve_due_run()`の既定`initial_status`参照)。target_runsの
 * 保存完了直後に`mark_planning_running()`で`planning→running`へ遷移させて
 * 初めて、他プロセス(RESTのポーリング等が同じ`run_id`を「進行中」と見て
 * `WPCV_Chunk_Dispatcher::dispatch()`を呼ぶ場合)がtarget_runsを安全に
 * 参照できるようになる。この遷移より前に他プロセスが同じrunを見た場合、
 * `dispatch()`は`queued`/`planning`をclaim対象外として扱い待機するだけで、
 * 「target 0件 = 完了」と誤認することは無い(`WPCV_Chunk_Dispatcher::dispatch()`
 * のクラスdocblock参照。この誤認が実際に起きていたレースコンディションが
 * このメソッドを見直す直接の契機になった).
 *
 * v0.5後半 §Step12: 新しいrunの開始時に、差分処理の「取りこぼしの回収」
 * (`WPCV_Run_Repository::find_stale_diff_run()`)も併せて行う。`pending`のまま
 * 一度もdispatchされていない、またはlease切れのまま放置されたrunが最大1件だけ
 * 見つかれば、その継続action(`WPCV_Chunk_Dispatcher::HOOK`)を1つ予約する
 * (回収自体はその場で処理しない ―― 新しいrunの開始をタイムアウトさせないため).
 * この回収処理の失敗は新しいrunの開始を妨げてはならない(ベストエフォート.
 * `recover_stale_diff_run()`参照).
 */
class WPCV_Run_Starter {

	/**
	 * `$context` を列挙(plan)し、`$run_id` の target_runs として保存したうえで、
	 * runを`planning`から`running`へ遷移させる.
	 *
	 * 例外(`WPCV_Run_Planner::plan()` のバリデーション例外・DB書き込み例外・
	 * `planning→running`遷移の失敗)が発生した場合、`$run_id` の run を
	 * `mark_run_failed()` で failed 化してから再送出する(呼び出し元は個別に
	 * try/catch する必要が無い).
	 *
	 * @param WPCV_Run_Repository        $run_repository         `wpcv_runs` の永続化層.
	 * @param WPCV_Run_Planner           $planner                target列挙.
	 * @param WPCV_Target_Run_Repository $target_run_repository  `wpcv_target_runs` の永続化層.
	 * @param int                        $run_id                 予約済みの(`planning`
	 *                                                            状態の)run の id.
	 * @param array                      $context                `WPCV_Run_Planner::plan()` に
	 *                                                            渡す `$context`.
	 * @param callable|null              $continuation_scheduler 取りこぼし回収が使う継続予約
	 *                                                            callable(`function( int $run_id,
	 *                                                            int $delay_seconds ): void`)。
	 *                                                            省略時は
	 *                                                            `WPCV_Chunk_Dispatcher::schedule_via_action_scheduler()`.
	 * @return array `$planner->plan( $context )` の戻り値(呼び出し元が使わなくてもよい).
	 *
	 * @throws RuntimeException `planning→running`遷移が失敗した場合(failed 記録後に再送出).
	 * @throws Throwable        Plan・保存中に発生したその他の例外(failed 記録後に再送出).
	 */
	public static function plan_and_save( WPCV_Run_Repository $run_repository, WPCV_Run_Planner $planner, WPCV_Target_Run_Repository $target_run_repository, $run_id, array $context, ?callable $continuation_scheduler = null ) {
		self::recover_stale_diff_run( $run_repository, $continuation_scheduler );

		try {
			$planned = $planner->plan( $context );

			$target_run_repository->save_target_runs( $run_id, $planned );

			if ( ! $run_repository->mark_planning_running( $run_id ) ) {
				throw new RuntimeException(
					esc_html(
						sprintf(
							'WPCV_Run_Starter::plan_and_save() は run #%d を planning から running へ遷移できませんでした(既に active な planning 状態ではありません).',
							$run_id
						)
					)
				);
			}

			return $planned;
		} catch ( Throwable $e ) {
			$run_repository->mark_run_failed( $run_id, get_class( $e ) . ': ' . $e->getMessage() );

			throw $e;
		}
	}

	/**
	 * 差分処理の取りこぼし(`pending`のまま放置された、またはlease切れのまま
	 * 放置されたrun)を最大1件だけ回収する(クラスdocblock参照).
	 *
	 * この処理自体の失敗(継続予約の失敗等)は、新しいrunの開始という本来の
	 * 目的を妨げてはならないため、例外はここで握りつぶす(ベストエフォート.
	 * 回収し損ねても、次回どこかのrunの開始時に再試行されるだけで実害が
	 * 蓄積しない ―― `find_stale_diff_run()`は毎回独立に最古1件を探すため).
	 *
	 * @param WPCV_Run_Repository $run_repository         `wpcv_runs` の永続化層.
	 * @param callable|null       $continuation_scheduler `plan_and_save()` と同じ.
	 * @return void
	 */
	private static function recover_stale_diff_run( WPCV_Run_Repository $run_repository, ?callable $continuation_scheduler ) {
		try {
			$stale_run_id = $run_repository->find_stale_diff_run();

			if ( null === $stale_run_id ) {
				return;
			}

			$scheduler = $continuation_scheduler ?? array( 'WPCV_Chunk_Dispatcher', 'schedule_via_action_scheduler' );

			call_user_func( $scheduler, $stale_run_id, 0 );
		} catch ( Throwable $e ) {
			// ベストエフォート. 握りつぶす理由はこのメソッドのdocblock参照.
			unset( $e );
		}
	}
}
