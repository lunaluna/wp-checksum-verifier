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
 * Run開始時の「列挙(plan)→保存」を、失敗時の後始末(run failed 化)込みで
 * 行う共通処理(v0.4.0 §Step5)。
 *
 * `WPCV_Run_Coordinator::run()`(同期ループの先頭)と `WPCV_Runner_Async::run_async_action()`
 * (AS worker が最初に触れた時点)のどちらも「予約済みのrun_idに対して、まだ
 * target_runsが存在しない状態からplanして保存する」という同じ処理を必要とする。
 * これを2箇所に別々に書くと、`WPCV_Run_Planner::plan()` が投げる例外の扱い
 * (`WPCV_Run_Repository::mark_run_failed()` を呼んでから再送出する、という
 * v0.3.1 §Step1由来の「途中の例外で失敗記録が残らない」への対策)が2箇所で
 * ドリフトする実害があるため、共有クラスとして抽出した.
 */
class WPCV_Run_Starter {

	/**
	 * `$context` を列挙(plan)し、`$run_id` の target_runs として保存する.
	 *
	 * 例外(`WPCV_Run_Planner::plan()` のバリデーション例外・DB書き込み例外)が
	 * 発生した場合、`$run_id` の run を `mark_run_failed()` で failed 化してから
	 * 再送出する(呼び出し元は個別に try/catch する必要が無い).
	 *
	 * @param WPCV_Run_Repository        $run_repository        `wpcv_runs` の永続化層.
	 * @param WPCV_Run_Planner           $planner               target列挙.
	 * @param WPCV_Target_Run_Repository $target_run_repository `wpcv_target_runs` の永続化層.
	 * @param int                        $run_id                予約済みの(`running`/`queued`
	 *                                                          状態の)run の id.
	 * @param array                      $context               `WPCV_Run_Planner::plan()` に
	 *                                                          渡す `$context`.
	 * @return array `$planner->plan( $context )` の戻り値(呼び出し元が使わなくてもよい).
	 *
	 * @throws Throwable Plan・保存中に発生した例外(failed 記録後に再送出).
	 */
	public static function plan_and_save( WPCV_Run_Repository $run_repository, WPCV_Run_Planner $planner, WPCV_Target_Run_Repository $target_run_repository, $run_id, array $context ) {
		try {
			$planned = $planner->plan( $context );

			$target_run_repository->save_target_runs( $run_id, $planned );

			return $planned;
		} catch ( Throwable $e ) {
			$run_repository->mark_run_failed( $run_id, get_class( $e ) . ': ' . $e->getMessage() );

			throw $e;
		}
	}
}
