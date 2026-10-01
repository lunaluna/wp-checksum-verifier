<?php
/**
 * WPCV_Chunk_Budget クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 時間・件数・メモリ予算のいずれかを超えたかを判定する共有ロジック(v0.4.0コード
 * レビューCR-08是正).
 *
 * 元々は `WPCV_Chunk_Verifier::budget_exceeded()` にのみ存在した判定だったが、
 * `WPCV_Unknown_File_Scanner::scan()`(未知ファイル走査そのものの時間・メモリを
 * 予算内に収める)にも同じ判定が必要になったため、「2箇所目の利用が確定してから
 * 共通化する」方針(プロジェクトの絶対ルール)に従いこのクラスへ切り出した.
 */
class WPCV_Chunk_Budget {

	/**
	 * メモリ予算判定の既定閾値(`memory_limit_bytes` に対する割合).
	 *
	 * `lib/action-scheduler` の `ActionScheduler_Abstract_QueueRunner::memory_exceeded()`
	 * が使う閾値(90%)を踏襲した(実測ではなく、既存の類似実装との整合を優先した値.
	 * 未実測であることに注意).
	 *
	 * @var float
	 */
	const DEFAULT_MEMORY_THRESHOLD_RATIO = 0.9;

	/**
	 * 予算(件数・経過時間・メモリ・累積バイト数)のいずれかを超えたかを判定する.
	 *
	 * `max_bytes`(v0.6 §Step11。層2の内容ハッシュをchunk全体でどれだけ読むかの
	 * 累積上限)は、既存の`max_seconds`(ファイル1件処理するごとにチェック済み)
	 * と役割が重なるが、ファイル単位の上限(`content_hash_max_bytes`)と組み合わせても
	 * 「経過時間というやや揺らぎのある指標」ではなく「読んだバイト数という決定的な
	 * 指標」でchunkを区切れるようにするための予算(2026-09-30ユーザー承認済み).
	 *
	 * @param float         $start_time      処理開始時刻(`$now` と同じ時間源の値. 通常
	 *                                       `microtime( true )` 相当).
	 * @param int           $processed       このchunkで既に処理した件数(`max_files`判定
	 *                                       用)。「処理件数」の概念が無い呼び出し元
	 *                                       (未知ファイル走査の enumerate 段階等)は
	 *                                       `0` を渡し、`$budget` に `max_files` を
	 *                                       含めないこと(誤って打ち切られてしまうため).
	 * @param array         $budget          `max_files`/`max_seconds`/`max_bytes`/
	 *                                       `memory_limit_bytes`/`memory_threshold_ratio`
	 *                                       (いずれも省略可。指定が無い項目は制限として
	 *                                       扱わない).
	 * @param callable      $now             現在時刻を秒(float. `microtime( true )` 相当)
	 *                                       で返す callable.
	 * @param callable|null $memory_usage    現在のメモリ使用量をバイト数
	 *                                       (`memory_get_usage( true )` 相当)で返す
	 *                                       callable。省略時は`memory_limit_bytes`判定を
	 *                                       行わない.
	 * @param int           $bytes_processed このchunkで既に読んだ累積バイト数
	 *                                       (`max_bytes`判定用)。「読んだバイト数」の
	 *                                       概念が無い呼び出し元は既定の`0`のままでよい
	 *                                       (`$budget`に`max_bytes`を含めなければ判定
	 *                                       自体が働かないため).
	 * @return bool
	 */
	public static function exceeded( $start_time, $processed, array $budget, callable $now, ?callable $memory_usage = null, $bytes_processed = 0 ) {
		if ( isset( $budget['max_files'] ) && $processed >= (int) $budget['max_files'] ) {
			return true;
		}

		if ( isset( $budget['max_seconds'] ) && ( call_user_func( $now ) - $start_time ) >= (float) $budget['max_seconds'] ) {
			return true;
		}

		if ( isset( $budget['max_bytes'] ) && $bytes_processed >= (int) $budget['max_bytes'] ) {
			return true;
		}

		if ( null !== $memory_usage && isset( $budget['memory_limit_bytes'] ) ) {
			$ratio     = isset( $budget['memory_threshold_ratio'] ) ? (float) $budget['memory_threshold_ratio'] : self::DEFAULT_MEMORY_THRESHOLD_RATIO;
			$threshold = (int) $budget['memory_limit_bytes'] * $ratio;

			if ( call_user_func( $memory_usage ) >= $threshold ) {
				return true;
			}
		}

		return false;
	}
}
