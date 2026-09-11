<?php
/**
 * WPCV_Run_Status クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_runs.status` の状態定数と遷移検証(v0.4.0 §Step1).
 *
 * これまでは `WPCV_Repository` に `STATUS_QUEUED`/`STATUS_RUNNING` のみが
 * 定数化されており、`success`/`partial`/`failed` は文字列リテラルのままだった。
 * run deadline超過による `aborted` を新設するにあたり、他の状態とまとめて
 * ここに集約し、`WPCV_Run_Repository`・`WPCV_Run_Coordinator` から参照できる
 * ようにする(状態文字列の重複定義・typoを防ぐため).
 *
 * `aborted` はStep4で導入するrun deadline sweepが遷移させる状態で、Step1時点
 * では定数と遷移表のみ用意し、実際に遷移させるロジックはまだ無い.
 *
 * `planning`はv0.4.0コードレビューCR-01是正で追加した(target_runsを列挙・
 * 保存している最中であることを表す中間状態。`running`になった時点で
 * target_runsの保存が完了していることを保証し、他プロセスが「target 0件 =
 * 完了」と誤認する競合を防ぐ。`WPCV_Run_Starter::plan_and_save()`のクラス
 * docblock参照)。
 */
class WPCV_Run_Status {

	/** キュー投入済み・実行待ち(Action Scheduler enqueue直後など). */
	const QUEUED = 'queued';

	/**
	 * Target_runsを列挙・保存している最中(v0.4.0コードレビューCR-01是正で追加).
	 *
	 * この状態のrunにはtarget_runsがまだ1件も存在しない可能性があるため、
	 * `WPCV_Chunk_Dispatcher::dispatch()`はclaim・完了判定を行わず待機する
	 * (`queued`と同様に扱う).
	 */
	const PLANNING = 'planning';

	/** 検証処理中(target_runsの保存が完了済み). */
	const RUNNING = 'running';

	/** 全 target が成功した(終端). */
	const SUCCESS = 'success';

	/** 1 つ以上の target が unverifiable/failed だが run 自体は完走した(終端). */
	const PARTIAL = 'partial';

	/** 全体が失敗した(終端). */
	const FAILED = 'failed';

	/** 期限(deadline)を超過し強制終了した(終端。v0.4.0 §Step4で導入). */
	const ABORTED = 'aborted';

	/**
	 * 実行権(active lease)を保持しているとみなす状態の一覧.
	 *
	 * @var string[]
	 */
	const ACTIVE = array( self::QUEUED, self::PLANNING, self::RUNNING );

	/**
	 * それ以上遷移しない状態の一覧.
	 *
	 * @var string[]
	 */
	const TERMINAL = array( self::SUCCESS, self::PARTIAL, self::FAILED, self::ABORTED );

	/**
	 * 遷移元 => 遷移先として許される状態の一覧.
	 *
	 * @var array<string, string[]>
	 */
	const TRANSITIONS = array(
		self::QUEUED   => array( self::PLANNING, self::FAILED, self::ABORTED ),
		self::PLANNING => array( self::RUNNING, self::FAILED, self::ABORTED ),
		self::RUNNING  => array( self::SUCCESS, self::PARTIAL, self::FAILED, self::ABORTED ),
	);

	/**
	 * `$status` が active(`ACTIVE` のいずれか)かどうかを判定する.
	 *
	 * @param string $status 判定対象.
	 * @return bool
	 */
	public static function is_active( $status ) {
		return in_array( $status, self::ACTIVE, true );
	}

	/**
	 * `$status` が terminal(`TERMINAL` のいずれか)かどうかを判定する.
	 *
	 * @param string $status 判定対象.
	 * @return bool
	 */
	public static function is_terminal( $status ) {
		return in_array( $status, self::TERMINAL, true );
	}

	/**
	 * `$from` から `$to` への遷移が `TRANSITIONS` に照らして妥当かどうかを判定する.
	 *
	 * @param string $from 遷移元の状態.
	 * @param string $to   遷移先の状態.
	 * @return bool
	 */
	public static function can_transition( $from, $to ) {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}
}
