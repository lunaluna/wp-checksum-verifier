<?php
/**
 * WPCV_Generation_Differ クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 世代(今回の run と基準の run)を比較し、NEW/CONTINUING/RESOLVED を判定する
 * (v0.5後半プラン §2. Step12〔差分処理の実行〕・Step13〔本文組み立て〕・
 * Step15〔別種のアラート〕が呼び出し元).
 *
 * DB に一切触れない純粋なロジックのみで構成する(`WPCV_Suppression_Matcher`・
 * `WPCV_Verifier` と同じ方針).DB アクセス(基準 target_run の検索・finding の
 * 一括取得・`ended_in_run_id`/`diff_state` の書き込み)は Step12 の
 * 差分処理(chunk 実行)の責務とし、このクラスは「入力の配列に対して何を
 * 書くべきか」を返すだけにする.
 *
 * 各メソッドが受け取る finding は、呼び出し元(Step12)が DB 行から必要な
 * フィールドだけを取り出した連想配列(`finding_key`・呼び出し元が識別に使う
 * 任意のキー〔例: `id`〕・`suppressed`〔bool.`suppressed_by`/`suppression_id`の
 * いずれかが設定済みかどうか呼び出し元が判定した結果〕)を前提とする.
 * `suppressed` を持たない場合は「抑制なし」として扱う(`empty()`判定).
 *
 * 呼び出し元が保証すべき前提(§2.1「基準」の定義):
 * - `$baseline_findings` には、抑制済み(`suppressed_by`/`suppression_id`が
 *   設定済み)の行、`finding_key` が NULL の行(v4 より前の行)、`ended_in_run_id`
 *   が既に設定済みの行を含めないこと(すべて呼び出し元が事前に除外する).
 */
class WPCV_Generation_Differ {

	/** §2.1: 基準と今回が同じ version で、finding_key を突き合わせて比較した. */
	const DIFF_MODE_COMPARED = 'compared';

	/** §2.1: 基準と今回で version が異なるため、比較せずすべて new 扱いにした. */
	const DIFF_MODE_VERSION_CHANGED = 'version_changed';

	/** §2.1: 使える基準が無い(初回、または基準が v4 より前の行しか持たない). */
	const DIFF_MODE_FIRST = 'first';

	/** §2.1: 今回 unverifiable/failed/aborted のため照合していない. */
	const DIFF_MODE_NOT_VERIFIED = 'not_verified';

	/** §2.1: exclude_target 抑制ルールにより今回 skipped になった. */
	const DIFF_MODE_EXCLUDED = 'excluded';

	/** §2.1: exclude_target 以外の理由で今回 skipped になった(基準を変えない). */
	const DIFF_MODE_SKIPPED = 'skipped';

	/**
	 * §2.3(D2): stat 差分検知 target が実際に走査を実行した(status=success).
	 * stat target は世代比較の概念を持たないため(クラス docblock参照)、
	 * `determine_diff_mode()` はこの値を返さない ―― 呼び出し元
	 * (`WPCV_Diff_Dispatcher`)が stat target かどうかを先に判定し、
	 * success の場合のみこの値を直接使う(v0.5後半 §Step12).
	 */
	const DIFF_MODE_EVENT = 'event';

	/** §2.1/§2.3: 基準に無いキー、または stat 由来で新規に検出された. */
	const DIFF_STATE_NEW = 'new';

	/** §2.1: 基準にも同じキーがあり、引き続き存在している. */
	const DIFF_STATE_CONTINUING = 'continuing';

	/** §2.3(D2): stat 由来の finding は毎回「出来事」として扱う. */
	const DIFF_STATE_EVENT = 'event';

	/** §2.1: 基準にあったキーが今回は無くなった(解決). */
	const END_REASON_RESOLVED = 'resolved';

	/** §2.1: version が変わったため、基準のfindingをすべて終わらせた. */
	const END_REASON_VERSION_CHANGED = 'version_changed';

	/** §2.2: 今回の同じキーの finding が抑制されたため終わらせた. */
	const END_REASON_SUPPRESSED = 'suppressed';

	/** §2.1: exclude_target 抑制ルールにより target ごと終わらせた. */
	const END_REASON_EXCLUDED = 'excluded';

	/** §2.1: target がアンインストールされ、今回の run に現れなかった. */
	const END_REASON_TARGET_REMOVED = 'target_removed';

	/**
	 * `target` 1つ分の `diff_mode`(§2.1 表の左端の列)を決定する.
	 *
	 * 「今回の run に無い target」(アンインストール.§2.1 末尾の行)はここでは
	 * 扱わない.今回の target_run 自体が存在しないため、呼び出し元は
	 * `determine_diff_mode()` を呼ばず `diff_removed_target()` を直接使うこと.
	 *
	 * @param string      $status              今回の target_run の status
	 *                                         (`WPCV_Target_Status` の定数).
	 * @param string|null $error_code          今回の target_run の error_code.
	 * @param string|null $current_version     今回の target_run の version.
	 *                                         version を持たない target(`_scan`)
	 *                                         では NULL(v0.5後半 §16 前提バグ修正.
	 *                                         基準側〔`$baseline_target_run['version']`〕
	 *                                         も NULL のままなので、両方 NULL なら
	 *                                         `null === null` で `compared` になる).
	 * @param array|null  $baseline_target_run 基準の target_run(無ければ null).
	 *                                         `version`(string|null)と `usable`
	 *                                         (bool.§1.4: 基準の target_run に
	 *                                         finding_key 付きの行が1件も無ければ
	 *                                         false)を持つ連想配列.
	 * @return string `DIFF_MODE_*` のいずれか.
	 */
	public static function determine_diff_mode( $status, $error_code, $current_version, ?array $baseline_target_run ) {
		if ( WPCV_Target_Status::SUCCESS === $status ) {
			if ( null === $baseline_target_run || empty( $baseline_target_run['usable'] ) ) {
				return self::DIFF_MODE_FIRST;
			}

			return ( $baseline_target_run['version'] === $current_version )
				? self::DIFF_MODE_COMPARED
				: self::DIFF_MODE_VERSION_CHANGED;
		}

		if ( in_array( $status, array( WPCV_Target_Status::UNVERIFIABLE, WPCV_Target_Status::FAILED, WPCV_Target_Status::ABORTED ), true ) ) {
			return self::DIFF_MODE_NOT_VERIFIED;
		}

		if ( WPCV_Target_Status::SKIPPED === $status ) {
			return ( WPCV_Error_Code::EXCLUDED === $error_code ) ? self::DIFF_MODE_EXCLUDED : self::DIFF_MODE_SKIPPED;
		}

		// 呼び出し元は terminal な target_run のみを渡す前提だが、想定外の
		// statusを渡された場合は「何もしない」skippedへ倒す(安全側のフォールバック).
		return self::DIFF_MODE_SKIPPED;
	}

	/**
	 * `compared`/`version_changed`/`first`/`not_verified` の4モードについて、
	 * 今回の finding の `diff_state` と、終わらせるべき基準の finding を決定する
	 * (§2.1 表の右3列 + §2.2 の抑制との重ね合わせ).
	 *
	 * `excluded`/`skipped`/target 削除は個別のメソッド(`diff_excluded_target()`/
	 * `diff_removed_target()`)を使う(この4モードとは今回のfindingの有無・
	 * 終わらせ方の性質が異なるため).
	 *
	 * @param string $diff_mode         `DIFF_MODE_COMPARED`/`DIFF_MODE_VERSION_CHANGED`/
	 *                                  `DIFF_MODE_FIRST`/`DIFF_MODE_NOT_VERIFIED` のいずれか.
	 * @param array  $current_findings  今回の run の finding(クラスdocblock参照).
	 * @param array  $baseline_findings 基準の finding(クラスdocblock参照.
	 *                                  抑制済み・finding_key無し・既に終わった行は
	 *                                  呼び出し元が除外済みの前提).
	 * @return array{current: array, ended_baseline: array} `current` は
	 *         `diff_state` を追加した `$current_findings`.`ended_baseline` は
	 *         `end_reason` を追加した、終わらせるべき `$baseline_findings` の部分集合.
	 *
	 * @throws InvalidArgumentException `$diff_mode` が対応外の値の場合.
	 */
	public static function diff_target( $diff_mode, array $current_findings, array $baseline_findings ) {
		switch ( $diff_mode ) {
			case self::DIFF_MODE_COMPARED:
				$result = self::diff_by_key( $current_findings, $baseline_findings, true );
				break;

			case self::DIFF_MODE_NOT_VERIFIED:
				$result = self::diff_by_key( $current_findings, $baseline_findings, false );
				break;

			case self::DIFF_MODE_VERSION_CHANGED:
				$result = array(
					'current'        => self::with_diff_state( $current_findings, self::DIFF_STATE_NEW ),
					'ended_baseline' => self::with_end_reason( $baseline_findings, self::END_REASON_VERSION_CHANGED ),
				);
				break;

			case self::DIFF_MODE_FIRST:
				$result = array(
					'current'        => self::with_diff_state( $current_findings, self::DIFF_STATE_NEW ),
					'ended_baseline' => array(),
				);
				break;

			default:
				throw new InvalidArgumentException(
					esc_html(
						sprintf( 'WPCV_Generation_Differ::diff_target() got an unsupported diff_mode: %s', (string) $diff_mode )
					)
				);
		}

		return self::apply_suppression_overlay( $result, $baseline_findings );
	}

	/**
	 * `excluded`(§2.1: exclude_target 抑制ルールにより今回 skipped)の場合の
	 * 基準の終わらせ方を決定する.今回の finding は存在しない(検証自体を
	 * 省略しているため)ので、基準の finding をすべて `excluded` で終わらせる.
	 *
	 * @param array $baseline_findings 基準の finding(クラスdocblock参照).
	 * @return array{current: array, ended_baseline: array} `current` は常に空配列.
	 */
	public static function diff_excluded_target( array $baseline_findings ) {
		return array(
			'current'        => array(),
			'ended_baseline' => self::with_end_reason( $baseline_findings, self::END_REASON_EXCLUDED ),
		);
	}

	/**
	 * 今回の run に target_run が無い(アンインストール等で列挙されなかった)
	 * target について、基準の finding をすべて `target_removed` で終わらせる
	 * (§2.1 表の最終行).
	 *
	 * @param array $baseline_findings 基準の finding(クラスdocblock参照).
	 * @return array `end_reason` を追加した `$baseline_findings`.
	 */
	public static function diff_removed_target( array $baseline_findings ) {
		return self::with_end_reason( $baseline_findings, self::END_REASON_TARGET_REMOVED );
	}

	/**
	 * `stat` 由来の finding(D2)の `diff_state` を決定する.stat の finding は
	 * 「継続」の概念を持たず、抑制されていなければ常に `event`(§2.3).
	 * 基準との比較は行わない(stat の finding は同じキーが再び出ることが
	 * 無いため、終わらせる対象も無い. クラスdocblock参照).
	 *
	 * `success + error_code = baseline_rebuilt`(finding が無い)・
	 * `skipped`(`checksum_covered` 等.finding が無い)は、そもそも finding が
	 * 発生しないため呼び出し元(Step12)が判断し、このメソッドには渡さない.
	 *
	 * @param array $stat_findings stat 由来の finding(クラスdocblock参照).
	 * @return array `diff_state` を追加した `$stat_findings`(抑制済みは null).
	 */
	public static function diff_stat_findings( array $stat_findings ) {
		return array_map(
			static function ( $finding ) {
				$finding['diff_state'] = empty( $finding['suppressed'] ) ? self::DIFF_STATE_EVENT : null;

				return $finding;
			},
			$stat_findings
		);
	}

	/**
	 * `finding` 1件を通知するかどうかを判定する(§2.4・D4).
	 *
	 * @param string|null $diff_state       finding の `diff_state`(`DIFF_STATE_*` の
	 *                                      いずれか、または抑制済みで null).
	 * @param string|null $last_notified_at 同じ `finding_key` の直近の
	 *                                      `notified_at`(MySQL DATETIME文字列.
	 *                                      一度も通知していなければ null).
	 * @param string      $now              現在時刻(MySQL DATETIME文字列).
	 * @param int         $resend_days      再送抑制の日数(既定7. `wpcv_alert_resend_days`
	 *                                      フィルターの値を呼び出し元が渡す).
	 * @return bool
	 */
	public static function should_notify_finding( $diff_state, $last_notified_at, $now, $resend_days = 7 ) {
		if ( null === $diff_state ) {
			return false;
		}

		if ( self::DIFF_STATE_EVENT === $diff_state ) {
			return true;
		}

		if ( self::DIFF_STATE_CONTINUING === $diff_state ) {
			return null === $last_notified_at;
		}

		// DIFF_STATE_NEW.
		if ( null === $last_notified_at ) {
			return true;
		}

		$elapsed_seconds = strtotime( $now ) - strtotime( $last_notified_at );

		return $elapsed_seconds >= ( $resend_days * DAY_IN_SECONDS );
	}

	/**
	 * `run` 1回分についてアラートを送るかどうかを判定する(§2.5 の1つ目の表).
	 *
	 * `baseline_rebuilt`/`target_removed`/`version_changed`/`excluded`/
	 * `suppressed` だけの run で `false` になるのは、これらが `$notify_count`
	 * (`should_notify_finding()` が true を返した件数)にも `$resolved_count`
	 * (`end_reason = resolved` で終わった件数.他の end_reason は数えない)にも
	 * 含まれないためで、このメソッド自身が個別に除外するわけではない
	 * (呼び出し元の集計方法に委ねる設計).
	 *
	 * @param int  $notify_count                  通知対象の finding 件数.
	 * @param int  $resolved_count                 `end_reason = resolved` で
	 *                                             終わった finding 件数.
	 * @param bool $unverifiable_streak_triggered 連続 unverifiable/run失敗の
	 *                                             閾値に新規に達したか(§2.6.
	 *                                             Step15 まで実装されないため
	 *                                             既定 false).
	 * @param int  $unrecorded_version_change_count 今回のrunで`error_code =
	 *                                             version_changed_unrecorded`に
	 *                                             なったtarget_runの件数(v0.6
	 *                                             プラン §3.1・U3. Step3まで
	 *                                             実装されないため既定0).
	 * @return bool
	 */
	public static function should_send_alert( $notify_count, $resolved_count, $unverifiable_streak_triggered = false, $unrecorded_version_change_count = 0 ) {
		return $notify_count > 0 || $resolved_count > 0 || $unverifiable_streak_triggered || $unrecorded_version_change_count > 0;
	}

	/**
	 * 連続unverifiable(v0.5後半 §Step15b設計§2.1)で、1つのtarget_runを
	 * 「数える」か「途切れさせる」かを判定する.
	 *
	 * 呼び出し元(`WPCV_Target_Run_Repository::find_streak_for_target()`)が
	 * 「所属するrunがsuccess/partialである」ことを保証した上で渡す前提
	 * (runがfailed/abortedのときの「見ない」判定はDBの`wpcv_runs.status`を
	 * 見る必要があるため、このクラスの外〔呼び出し元〕が行う.クラスdocblock
	 * 「DBに一切触れない」の方針どおり).
	 *
	 * @param array $target_run 判定対象のtarget_run(`target_id`/`status`/`error_code`).
	 * @return bool `true`なら数える(連続を継続する).`false`なら途切れさせる.
	 */
	public static function is_unverifiable_streak_member( array $target_run ) {
		$status     = (string) ( $target_run['status'] ?? '' );
		$error_code = $target_run['error_code'] ?? null;

		if ( WPCV_Target_Status::FAILED === $status ) {
			return true;
		}

		if ( WPCV_Target_Status::UNVERIFIABLE !== $status ) {
			// success/skipped(baseline_rebuilt・checksum_covered等を含む). 障害ではない.
			return false;
		}

		if ( in_array( $error_code, array( WPCV_Error_Code::HTTP_ERROR, WPCV_Error_Code::RATE_LIMITED, WPCV_Error_Code::TIMEOUT ), true ) ) {
			return true;
		}

		if ( WPCV_Error_Code::MANIFEST_NOT_FOUND === $error_code ) {
			// core以外のmanifest_not_foundは独自プラグインの恒常状態のため途切れさせる.
			// coreのmanifest_not_foundはHTTPエラーでもこのコードになるため数える(Q2).
			return 'core' === (string) ( $target_run['target_id'] ?? '' );
		}

		// unknown_source/target_missing/その他: 一時障害ではない.
		return false;
	}

	/**
	 * `finding_key` で今回と基準を突き合わせ、今回の `diff_state`
	 * (`continuing`/`new`)と、基準側で終わらせる行(`$end_unmatched_as_resolved`
	 * が true のときだけ、基準にあって今回に無いキー)を決定する
	 * (`compared`/`not_verified` の共通ロジック. §2.1参照).
	 *
	 * @param array $current_findings         今回の finding.
	 * @param array $baseline_findings        基準の finding.
	 * @param bool  $end_unmatched_as_resolved true なら基準にあって今回に無い
	 *                                         キーを `resolved` で終わらせる
	 *                                         (`compared`).false なら終わらせない
	 *                                         (`not_verified`: 照合していないので
	 *                                         解決とは言えない).
	 * @return array{current: array, ended_baseline: array}
	 */
	private static function diff_by_key( array $current_findings, array $baseline_findings, $end_unmatched_as_resolved ) {
		$matched_keys = array();
		$current_out  = array();

		foreach ( $current_findings as $finding ) {
			$is_match = self::has_matching_key( $baseline_findings, $finding['finding_key'] );

			$finding['diff_state'] = $is_match ? self::DIFF_STATE_CONTINUING : self::DIFF_STATE_NEW;

			if ( $is_match ) {
				$matched_keys[ $finding['finding_key'] ] = true;
			}

			$current_out[] = $finding;
		}

		$ended = array();

		if ( $end_unmatched_as_resolved ) {
			foreach ( $baseline_findings as $finding ) {
				if ( ! isset( $matched_keys[ $finding['finding_key'] ] ) ) {
					$finding['end_reason'] = self::END_REASON_RESOLVED;
					$ended[]               = $finding;
				}
			}
		}

		return array(
			'current'        => $current_out,
			'ended_baseline' => $ended,
		);
	}

	/**
	 * §2.2 の抑制との重ね合わせを適用する.今回の finding が抑制されている
	 * 場合、`diff_target()` の各モードが決めた内容を上書きする: `diff_state` は
	 * null に、基準に同じキーがあれば `end_reason = suppressed` で終わらせる
	 * (`compared` が `resolved` にする、`not_verified` が終わらせない、といった
	 * モードごとの既定の扱いより、抑制による終了を常に優先する).
	 *
	 * @param array{current: array, ended_baseline: array} $result            `diff_by_key()`/
	 *                                                                        `with_diff_state()`等が
	 *                                                                        返した結果.
	 * @param array                                        $baseline_findings 基準の finding(重ね合わせで
	 *                                                                      キーを引くために使う).
	 * @return array{current: array, ended_baseline: array}
	 */
	private static function apply_suppression_overlay( array $result, array $baseline_findings ) {
		$ended_by_key = array();

		foreach ( $result['ended_baseline'] as $index => $finding ) {
			$ended_by_key[ $finding['finding_key'] ] = $index;
		}

		$current_out = array();

		foreach ( $result['current'] as $finding ) {
			if ( empty( $finding['suppressed'] ) ) {
				$current_out[] = $finding;
				continue;
			}

			$finding['diff_state'] = null;
			$current_out[]         = $finding;

			$baseline_match = self::find_by_key( $baseline_findings, $finding['finding_key'] );

			if ( null === $baseline_match ) {
				continue;
			}

			if ( isset( $ended_by_key[ $finding['finding_key'] ] ) ) {
				$result['ended_baseline'][ $ended_by_key[ $finding['finding_key'] ] ]['end_reason'] = self::END_REASON_SUPPRESSED;
				continue;
			}

			$baseline_match['end_reason']            = self::END_REASON_SUPPRESSED;
			$result['ended_baseline'][]              = $baseline_match;
			$ended_by_key[ $finding['finding_key'] ] = count( $result['ended_baseline'] ) - 1;
		}

		return array(
			'current'        => $current_out,
			'ended_baseline' => array_values( $result['ended_baseline'] ),
		);
	}

	/**
	 * `$findings` の中に `$finding_key` と一致する行があるかどうかを判定する.
	 *
	 * @param array  $findings    finding の配列.
	 * @param string $finding_key 探すキー.
	 * @return bool
	 */
	private static function has_matching_key( array $findings, $finding_key ) {
		return null !== self::find_by_key( $findings, $finding_key );
	}

	/**
	 * `$findings` の中から `$finding_key` と一致する最初の行を返す.
	 *
	 * @param array  $findings    finding の配列.
	 * @param string $finding_key 探すキー.
	 * @return array|null
	 */
	private static function find_by_key( array $findings, $finding_key ) {
		foreach ( $findings as $finding ) {
			if ( $finding['finding_key'] === $finding_key ) {
				return $finding;
			}
		}

		return null;
	}

	/**
	 * `$findings` の全行に同じ `diff_state` を設定する.
	 *
	 * @param array       $findings   finding の配列.
	 * @param string|null $diff_state 設定する値.
	 * @return array
	 */
	private static function with_diff_state( array $findings, $diff_state ) {
		return array_map(
			static function ( $finding ) use ( $diff_state ) {
				$finding['diff_state'] = $diff_state;

				return $finding;
			},
			$findings
		);
	}

	/**
	 * `$findings` の全行に同じ `end_reason` を設定する.
	 *
	 * @param array  $findings   finding の配列.
	 * @param string $end_reason 設定する値.
	 * @return array
	 */
	private static function with_end_reason( array $findings, $end_reason ) {
		return array_map(
			static function ( $finding ) use ( $end_reason ) {
				$finding['end_reason'] = $end_reason;

				return $finding;
			},
			$findings
		);
	}
}
