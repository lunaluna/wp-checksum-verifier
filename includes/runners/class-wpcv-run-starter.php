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
	 * @param WPCV_Run_Repository        $run_repository        `wpcv_runs` の永続化層.
	 * @param WPCV_Run_Planner           $planner               target列挙.
	 * @param WPCV_Target_Run_Repository $target_run_repository `wpcv_target_runs` の永続化層.
	 * @param int                        $run_id                予約済みの(`planning`
	 *                                                          状態の)run の id.
	 * @param array                      $context               `WPCV_Run_Planner::plan()` に
	 *                                                          渡す `$context`.
	 * @return array `$planner->plan( $context )` の戻り値(呼び出し元が使わなくてもよい).
	 *
	 * @throws RuntimeException `planning→running`遷移が失敗した場合(failed 記録後に再送出).
	 * @throws Throwable        Plan・保存中に発生したその他の例外(failed 記録後に再送出).
	 */
	public static function plan_and_save( WPCV_Run_Repository $run_repository, WPCV_Run_Planner $planner, WPCV_Target_Run_Repository $target_run_repository, $run_id, array $context ) {
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
}
