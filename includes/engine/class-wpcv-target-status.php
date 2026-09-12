<?php
/**
 * WPCV_Target_Status クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_target_runs.status` の状態定数と遷移検証(v0.4.0 §Step1).
 *
 * これまで target_run は「検証完了後にまとめて1回で保存する」設計
 * (`WPCV_Run_Coordinator::run()` 参照)のため、`success`/`unverifiable`/
 * `failed` の終端状態しか存在しなかった。v0.4.0のchunk分割実行(Step2以降)で
 * `queued`(未着手)・`retry`(chunk timeout/lease切れ後の再試行待ち)・
 * `running`(claim中)・`skipped`(plugin更新中等でスキップ)・`aborted`
 * (run deadline超過)を新設するにあたり、状態文字列をここに集約する。
 *
 * Step1時点ではこのクラスの定数と遷移表を追加するのみで、実際に
 * `queued`/`retry`/`skipped`/`aborted` へ遷移させる dispatcher・chunk verifier の
 * ロジックはまだ無い(Step3・Step4で実装する)。既存の `WPCV_Run_Coordinator` は
 * 引き続き `RUNNING` を経由せず直接 `TERMINAL` へ保存する.
 */
class WPCV_Target_Status {

	/** 未着手(次回のchunk実行で claim 対象になる). */
	const QUEUED = 'queued';

	/** 分割処理単位(chunk)の時間予算超過・lease切れ等により再試行待ち. */
	const RETRY = 'retry';

	/** いずれかの worker が claim して検証処理中. */
	const RUNNING = 'running';

	/** 検証が成功し差分無し(終端). */
	const SUCCESS = 'success';

	/** 期待値(manifest)の取得失敗等により検証できなかった(終端。理由は error_code で記録). */
	const UNVERIFIABLE = 'unverifiable';

	/** 検証処理自体が失敗した(終端). */
	const FAILED = 'failed';

	/** 更新処理中(`.maintenance`/updater lock検出)等によりスキップした(終端). */
	const SKIPPED = 'skipped';

	/** 所属する run が deadline を超過し強制終了した(終端). */
	const ABORTED = 'aborted';

	/**
	 * 次回の claim 対象になり得る状態の一覧.
	 *
	 * @var string[]
	 */
	const SCHEDULABLE = array( self::QUEUED, self::RETRY );

	/**
	 * 既に claim 済み(検証処理中)とみなす状態の一覧.
	 *
	 * @var string[]
	 */
	const CLAIMED = array( self::RUNNING );

	/**
	 * それ以上遷移しない状態の一覧.
	 *
	 * @var string[]
	 */
	const TERMINAL = array( self::SUCCESS, self::UNVERIFIABLE, self::FAILED, self::SKIPPED, self::ABORTED );

	/**
	 * 遷移元 => 遷移先として許される状態の一覧.
	 *
	 * @var array<string, string[]>
	 */
	const TRANSITIONS = array(
		self::QUEUED  => array( self::RUNNING, self::ABORTED ),
		self::RETRY   => array( self::RUNNING, self::ABORTED ),
		self::RUNNING => array( self::SUCCESS, self::UNVERIFIABLE, self::FAILED, self::SKIPPED, self::RETRY, self::ABORTED ),
	);

	/**
	 * `$status` が schedulable(`SCHEDULABLE` のいずれか)かどうかを判定する.
	 *
	 * @param string $status 判定対象.
	 * @return bool
	 */
	public static function is_schedulable( $status ) {
		return in_array( $status, self::SCHEDULABLE, true );
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
