<?php
/**
 * WPCV_Diff_Status クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_runs.diff_status` の状態定数(v0.5後半プラン §3.1. Step12).
 *
 * `WPCV_Run_Status`/`WPCV_Target_Status` と同じ考え方で、状態文字列をこの
 * クラスに集約する(`wpcv_runs.status` とは別の、run単位の差分処理専用の
 * 状態機械. 両者は独立していて、`status` が終端(success/partial)になった
 * runだけが `diff_status = pending` として差分処理の対象になる).
 */
class WPCV_Diff_Status {

	/** `finish_run()` が success/partial を書いた直後(まだ差分処理のclaim対象になっただけ). */
	const PENDING = 'pending';

	/** いずれかのworkerがclaimして差分処理(2 passのchunk処理)を進めている. */
	const PROCESSING = 'processing';

	/** 全targetの差分が終わり、件数を集計してrunの行に書いた(アラート送信待ち). */
	const ALERTING = 'alerting';

	/** 差分処理・アラートともに完了した(終端). */
	const DONE = 'done';

	/** 試行回数の上限を超えた(終端). */
	const FAILED = 'failed';

	/** `run` が success/partial 以外(failed/aborted)で終わり、差分処理を行わない(終端). */
	const SKIPPED = 'skipped';

	/**
	 * Claim対象になり得る状態の一覧(`pending`・lease切れの`processing`/`alerting`.
	 * 実際のlease有効期限判定は呼び出し元〔`WPCV_Run_Repository::claim_diff()`〕が行う).
	 *
	 * @var string[]
	 */
	const CLAIMABLE = array( self::PENDING, self::PROCESSING, self::ALERTING );

	/**
	 * それ以上遷移しない状態の一覧.
	 *
	 * @var string[]
	 */
	const TERMINAL = array( self::DONE, self::FAILED, self::SKIPPED );
}
