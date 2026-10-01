<?php
/**
 * WPCV_Generation_Differ のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-generation-differ.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Generation_Differ`(v0.5後半プラン §2. Step11)のテスト.
 *
 * DBアクセスの無い純粋なロジックのため、finding/target_runを模した配列を
 * 直接渡して検証する(`SuppressionMatcherTest`と同じ方針).プラン§2.1〜§2.5の
 * 組み合わせ表の各セルに対応するテストを1つずつ用意する.
 */
class GenerationDifferTest extends TestCase {

	/**
	 * finding 1件分の最小データを作る.
	 *
	 * @param string $finding_key finding_key.
	 * @param bool   $suppressed  抑制済みかどうか.
	 * @return array
	 */
	private function make_finding( $finding_key, $suppressed = false ) {
		return array(
			'finding_key' => $finding_key,
			'suppressed'  => $suppressed,
		);
	}

	// ------------------------------------------------------------------
	// §2.1: determine_diff_mode()
	// ------------------------------------------------------------------

	/**
	 * `success` かつ基準が同じ version なら `compared` になることを確認する.
	 *
	 * @return void
	 */
	public function test_determine_diff_mode_returns_compared_when_success_and_same_version() {
		$baseline = array(
			'version' => '1.2.0',
			'usable'  => true,
		);

		$this->assertSame(
			WPCV_Generation_Differ::DIFF_MODE_COMPARED,
			WPCV_Generation_Differ::determine_diff_mode( WPCV_Target_Status::SUCCESS, null, '1.2.0', $baseline )
		);
	}

	/**
	 * `success` かつ基準の version が異なるなら `version_changed` になることを確認する.
	 *
	 * @return void
	 */
	public function test_determine_diff_mode_returns_version_changed_when_version_differs() {
		$baseline = array(
			'version' => '1.2.0',
			'usable'  => true,
		);

		$this->assertSame(
			WPCV_Generation_Differ::DIFF_MODE_VERSION_CHANGED,
			WPCV_Generation_Differ::determine_diff_mode( WPCV_Target_Status::SUCCESS, null, '1.3.0', $baseline )
		);
	}

	/**
	 * `success` かつ基準が無い(初回)なら `first` になることを確認する.
	 *
	 * @return void
	 */
	public function test_determine_diff_mode_returns_first_when_no_baseline() {
		$this->assertSame(
			WPCV_Generation_Differ::DIFF_MODE_FIRST,
			WPCV_Generation_Differ::determine_diff_mode( WPCV_Target_Status::SUCCESS, null, '1.0.0', null )
		);
	}

	/**
	 * 基準の target_run 自体は存在するが、その finding が v4 より前の行しか
	 * 無い(`usable = false`)場合は `first` として扱うことを確認する
	 * (プラン §1.4「その行を基準にする target は基準なし〔first〕として扱う」).
	 *
	 * @return void
	 */
	public function test_determine_diff_mode_returns_first_when_baseline_has_only_pre_v4_findings() {
		$baseline = array(
			'version' => '1.0.0',
			'usable'  => false,
		);

		$this->assertSame(
			WPCV_Generation_Differ::DIFF_MODE_FIRST,
			WPCV_Generation_Differ::determine_diff_mode( WPCV_Target_Status::SUCCESS, null, '1.0.0', $baseline )
		);
	}

	/**
	 * `unverifiable`/`failed`/`aborted` はいずれも `not_verified` になることを確認する
	 * (基準の有無に関わらず、diff_mode自体は変わらない).
	 *
	 * @return void
	 */
	public function test_determine_diff_mode_returns_not_verified_for_unverifiable_failed_and_aborted() {
		foreach ( array( WPCV_Target_Status::UNVERIFIABLE, WPCV_Target_Status::FAILED, WPCV_Target_Status::ABORTED ) as $status ) {
			$this->assertSame(
				WPCV_Generation_Differ::DIFF_MODE_NOT_VERIFIED,
				WPCV_Generation_Differ::determine_diff_mode( $status, WPCV_Error_Code::HTTP_ERROR, '1.0.0', null ),
				"status={$status} should map to not_verified"
			);
		}
	}

	/**
	 * `skipped` かつ `error_code = excluded` なら `excluded` になることを確認する.
	 *
	 * @return void
	 */
	public function test_determine_diff_mode_returns_excluded_when_skipped_by_exclude_target() {
		$this->assertSame(
			WPCV_Generation_Differ::DIFF_MODE_EXCLUDED,
			WPCV_Generation_Differ::determine_diff_mode( WPCV_Target_Status::SKIPPED, WPCV_Error_Code::EXCLUDED, '1.0.0', null )
		);
	}

	/**
	 * `skipped` でも `error_code` が `excluded` 以外(例: `checksum_covered`)なら
	 * `skipped` のまま(`excluded` にはしない)ことを確認する.
	 *
	 * @return void
	 */
	public function test_determine_diff_mode_returns_skipped_for_other_skip_reasons() {
		$this->assertSame(
			WPCV_Generation_Differ::DIFF_MODE_SKIPPED,
			WPCV_Generation_Differ::determine_diff_mode( WPCV_Target_Status::SKIPPED, WPCV_Error_Code::CHECKSUM_COVERED, '1.0.0', null )
		);
	}

	// ------------------------------------------------------------------
	// §2.1: diff_target() — compared
	// ------------------------------------------------------------------

	/**
	 * `compared` モードで、キーが基準にある finding は `continuing`、無い finding は
	 * `new` になり、基準にあって今回に無いキーは `resolved` で終わることを確認する.
	 *
	 * @return void
	 */
	public function test_diff_target_compared_marks_continuing_new_and_resolved() {
		$current  = array( $this->make_finding( 'key-a' ), $this->make_finding( 'key-b' ) );
		$baseline = array( $this->make_finding( 'key-a' ), $this->make_finding( 'key-c' ) );

		$result = WPCV_Generation_Differ::diff_target( WPCV_Generation_Differ::DIFF_MODE_COMPARED, $current, $baseline );

		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_CONTINUING, $result['current'][0]['diff_state'] );
		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_NEW, $result['current'][1]['diff_state'] );

		$this->assertCount( 1, $result['ended_baseline'] );
		$this->assertSame( 'key-c', $result['ended_baseline'][0]['finding_key'] );
		$this->assertSame( WPCV_Generation_Differ::END_REASON_RESOLVED, $result['ended_baseline'][0]['end_reason'] );
	}

	// ------------------------------------------------------------------
	// §2.1: diff_target() — version_changed
	// ------------------------------------------------------------------

	/**
	 * `version_changed` モードでは、今回の finding はすべて `new` になり、
	 * 基準の finding はキーの一致に関わらずすべて `version_changed` で終わることを確認する.
	 *
	 * @return void
	 */
	public function test_diff_target_version_changed_marks_all_new_and_ends_all_baseline() {
		$current  = array( $this->make_finding( 'key-a' ) );
		$baseline = array( $this->make_finding( 'key-a' ), $this->make_finding( 'key-b' ) );

		$result = WPCV_Generation_Differ::diff_target( WPCV_Generation_Differ::DIFF_MODE_VERSION_CHANGED, $current, $baseline );

		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_NEW, $result['current'][0]['diff_state'] );
		$this->assertCount( 2, $result['ended_baseline'] );
		foreach ( $result['ended_baseline'] as $ended ) {
			$this->assertSame( WPCV_Generation_Differ::END_REASON_VERSION_CHANGED, $ended['end_reason'] );
		}
	}

	// ------------------------------------------------------------------
	// §2.1: diff_target() — first
	// ------------------------------------------------------------------

	/**
	 * `first` モードでは、基準が無いため今回の finding はすべて `new` になり、
	 * 終わらせる基準行も無いことを確認する.
	 *
	 * @return void
	 */
	public function test_diff_target_first_marks_all_new_and_ends_nothing() {
		$current = array( $this->make_finding( 'key-a' ) );

		$result = WPCV_Generation_Differ::diff_target( WPCV_Generation_Differ::DIFF_MODE_FIRST, $current, array() );

		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_NEW, $result['current'][0]['diff_state'] );
		$this->assertSame( array(), $result['ended_baseline'] );
	}

	// ------------------------------------------------------------------
	// §2.1: diff_target() — not_verified
	// ------------------------------------------------------------------

	/**
	 * `not_verified` モードで、基準ありの場合はキー一致で `continuing`/`new` を
	 * 判定しつつ、基準にあって今回に無いキーは終わらせない(照合していないため
	 * 解決とは言えない)ことを確認する.
	 *
	 * @return void
	 */
	public function test_diff_target_not_verified_compares_by_key_but_never_ends_baseline() {
		$current  = array( $this->make_finding( 'key-a' ), $this->make_finding( 'key-b' ) );
		$baseline = array( $this->make_finding( 'key-a' ), $this->make_finding( 'key-c' ) );

		$result = WPCV_Generation_Differ::diff_target( WPCV_Generation_Differ::DIFF_MODE_NOT_VERIFIED, $current, $baseline );

		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_CONTINUING, $result['current'][0]['diff_state'] );
		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_NEW, $result['current'][1]['diff_state'] );
		$this->assertSame( array(), $result['ended_baseline'] );
	}

	/**
	 * `not_verified` モードで基準が無い場合、今回の finding はすべて `new` になることを確認する.
	 *
	 * @return void
	 */
	public function test_diff_target_not_verified_marks_all_new_when_no_baseline() {
		$current = array( $this->make_finding( 'key-a' ) );

		$result = WPCV_Generation_Differ::diff_target( WPCV_Generation_Differ::DIFF_MODE_NOT_VERIFIED, $current, array() );

		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_NEW, $result['current'][0]['diff_state'] );
	}

	// ------------------------------------------------------------------
	// §2.1: diff_excluded_target() / diff_removed_target()
	// ------------------------------------------------------------------

	/**
	 * `excluded` モードでは今回の finding が存在せず、基準の finding はすべて
	 * `excluded` で終わることを確認する.
	 *
	 * @return void
	 */
	public function test_diff_excluded_target_ends_all_baseline_with_excluded() {
		$baseline = array( $this->make_finding( 'key-a' ), $this->make_finding( 'key-b' ) );

		$result = WPCV_Generation_Differ::diff_excluded_target( $baseline );

		$this->assertSame( array(), $result['current'] );
		$this->assertCount( 2, $result['ended_baseline'] );
		foreach ( $result['ended_baseline'] as $ended ) {
			$this->assertSame( WPCV_Generation_Differ::END_REASON_EXCLUDED, $ended['end_reason'] );
		}
	}

	/**
	 * 今回の run に target_run が無い(アンインストール)target について、
	 * 基準の finding をすべて `target_removed` で終わらせることを確認する.
	 *
	 * @return void
	 */
	public function test_diff_removed_target_ends_all_baseline_with_target_removed() {
		$baseline = array( $this->make_finding( 'key-a' ) );

		$ended = WPCV_Generation_Differ::diff_removed_target( $baseline );

		$this->assertSame( WPCV_Generation_Differ::END_REASON_TARGET_REMOVED, $ended[0]['end_reason'] );
	}

	// ------------------------------------------------------------------
	// §2.2: 抑制との重ね合わせ
	// ------------------------------------------------------------------

	/**
	 * 抑制済みの今回の finding が基準に同じキーを持つ場合、`compared` モードが
	 * 通常決める `resolved` ではなく `suppressed` で基準を終わらせ、今回の行の
	 * `diff_state` は null のままになることを確認する.
	 *
	 * @return void
	 */
	public function test_diff_target_suppression_overlay_ends_matching_baseline_as_suppressed() {
		$current  = array( $this->make_finding( 'key-a', true ) );
		$baseline = array( $this->make_finding( 'key-a' ) );

		$result = WPCV_Generation_Differ::diff_target( WPCV_Generation_Differ::DIFF_MODE_COMPARED, $current, $baseline );

		$this->assertNull( $result['current'][0]['diff_state'] );
		$this->assertCount( 1, $result['ended_baseline'] );
		$this->assertSame( WPCV_Generation_Differ::END_REASON_SUPPRESSED, $result['ended_baseline'][0]['end_reason'] );
	}

	/**
	 * 抑制済みの今回の finding が基準に同じキーを持たない場合、`diff_state` は
	 * null のままで、終わらせる基準行も無いことを確認する.
	 *
	 * @return void
	 */
	public function test_diff_target_suppressed_finding_without_baseline_match_ends_nothing() {
		$current = array( $this->make_finding( 'key-a', true ) );

		$result = WPCV_Generation_Differ::diff_target( WPCV_Generation_Differ::DIFF_MODE_COMPARED, $current, array() );

		$this->assertNull( $result['current'][0]['diff_state'] );
		$this->assertSame( array(), $result['ended_baseline'] );
	}

	/**
	 * `not_verified` モードは本来キー不一致の基準行を終わらせないが、今回の
	 * finding が抑制されていて基準に同じキーがある場合は、モードの既定の扱い
	 * (終わらせない)より抑制による終了(`suppressed`)を優先することを確認する.
	 *
	 * @return void
	 */
	public function test_diff_target_suppression_overlay_overrides_not_verified_no_ending_policy() {
		$current  = array( $this->make_finding( 'key-a', true ) );
		$baseline = array( $this->make_finding( 'key-a' ) );

		$result = WPCV_Generation_Differ::diff_target( WPCV_Generation_Differ::DIFF_MODE_NOT_VERIFIED, $current, $baseline );

		$this->assertNull( $result['current'][0]['diff_state'] );
		$this->assertCount( 1, $result['ended_baseline'] );
		$this->assertSame( WPCV_Generation_Differ::END_REASON_SUPPRESSED, $result['ended_baseline'][0]['end_reason'] );
	}

	// ------------------------------------------------------------------
	// §2.3: stat由来のfinding
	// ------------------------------------------------------------------

	/**
	 * 抑制されていない stat 由来の finding は常に `event` になることを確認する.
	 *
	 * @return void
	 */
	public function test_diff_stat_findings_marks_unsuppressed_as_event() {
		$findings = array( $this->make_finding( 'key-a' ) );

		$result = WPCV_Generation_Differ::diff_stat_findings( $findings );

		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_EVENT, $result[0]['diff_state'] );
	}

	/**
	 * 抑制済みの stat 由来の finding は `diff_state` が null のままになることを確認する.
	 *
	 * @return void
	 */
	public function test_diff_stat_findings_marks_suppressed_as_null() {
		$findings = array( $this->make_finding( 'key-a', true ) );

		$result = WPCV_Generation_Differ::diff_stat_findings( $findings );

		$this->assertNull( $result[0]['diff_state'] );
	}

	// ------------------------------------------------------------------
	// §2.4: should_notify_finding()
	// ------------------------------------------------------------------

	/**
	 * `new` かつ過去に通知していなければ通知することを確認する.
	 *
	 * @return void
	 */
	public function test_should_notify_finding_new_without_prior_notification() {
		$this->assertTrue(
			WPCV_Generation_Differ::should_notify_finding( WPCV_Generation_Differ::DIFF_STATE_NEW, null, '2026-09-26 00:00:00' )
		);
	}

	/**
	 * `new` かつ再送抑制の日数(既定7日)以内に通知済みなら通知しないことを確認する.
	 *
	 * @return void
	 */
	public function test_should_notify_finding_new_within_resend_window_is_suppressed() {
		$this->assertFalse(
			WPCV_Generation_Differ::should_notify_finding(
				WPCV_Generation_Differ::DIFF_STATE_NEW,
				'2026-09-20 00:00:00',
				'2026-09-26 00:00:00',
				7
			)
		);
	}

	/**
	 * `new` で再送抑制の日数より前にしか通知していなければ、再度通知することを確認する.
	 *
	 * @return void
	 */
	public function test_should_notify_finding_new_after_resend_window_notifies_again() {
		$this->assertTrue(
			WPCV_Generation_Differ::should_notify_finding(
				WPCV_Generation_Differ::DIFF_STATE_NEW,
				'2026-09-01 00:00:00',
				'2026-09-26 00:00:00',
				7
			)
		);
	}

	/**
	 * `continuing` かつ過去に通知していなければ通知する(前回の送信失敗の再送)ことを確認する.
	 *
	 * @return void
	 */
	public function test_should_notify_finding_continuing_without_prior_notification() {
		$this->assertTrue(
			WPCV_Generation_Differ::should_notify_finding( WPCV_Generation_Differ::DIFF_STATE_CONTINUING, null, '2026-09-26 00:00:00' )
		);
	}

	/**
	 * `continuing` かつ既に通知済みなら(日数に関わらず)通知しないことを確認する.
	 *
	 * @return void
	 */
	public function test_should_notify_finding_continuing_already_notified_is_suppressed() {
		$this->assertFalse(
			WPCV_Generation_Differ::should_notify_finding(
				WPCV_Generation_Differ::DIFF_STATE_CONTINUING,
				'2026-09-25 00:00:00',
				'2026-09-26 00:00:00'
			)
		);
	}

	/**
	 * `event`(stat由来)は通知履歴を見ず常に通知することを確認する(D2).
	 *
	 * @return void
	 */
	public function test_should_notify_finding_event_always_notifies() {
		$this->assertTrue(
			WPCV_Generation_Differ::should_notify_finding(
				WPCV_Generation_Differ::DIFF_STATE_EVENT,
				'2026-09-26 00:00:00',
				'2026-09-26 00:00:00'
			)
		);
	}

	/**
	 * `diff_state` が null(抑制済み)なら通知しないことを確認する.
	 *
	 * @return void
	 */
	public function test_should_notify_finding_null_diff_state_never_notifies() {
		$this->assertFalse(
			WPCV_Generation_Differ::should_notify_finding( null, null, '2026-09-26 00:00:00' )
		);
	}

	// ------------------------------------------------------------------
	// §2.5: should_send_alert()
	// ------------------------------------------------------------------

	/**
	 * 通知対象の finding が1件以上あれば送ることを確認する.
	 *
	 * @return void
	 */
	public function test_should_send_alert_true_when_notify_count_positive() {
		$this->assertTrue( WPCV_Generation_Differ::should_send_alert( 1, 0 ) );
	}

	/**
	 * RESOLVED が1件以上あれば送ることを確認する.
	 *
	 * @return void
	 */
	public function test_should_send_alert_true_when_resolved_count_positive() {
		$this->assertTrue( WPCV_Generation_Differ::should_send_alert( 0, 1 ) );
	}

	/**
	 * 連続unverifiableの閾値に新規到達した場合(§2.6.Step15で渡される想定)も
	 * 送ることを確認する.
	 *
	 * @return void
	 */
	public function test_should_send_alert_true_when_unverifiable_streak_triggered() {
		$this->assertTrue( WPCV_Generation_Differ::should_send_alert( 0, 0, true ) );
	}

	/**
	 * 通知対象・RESOLVED・連続unverifiableのいずれも無ければ送らないことを確認する
	 * (`baseline_rebuilt`/`target_removed`/`version_changed`/`excluded`/`suppressed`
	 * だけのrunは、これらのカウントに含まれないためこのケースに該当する. クラス
	 * docblock参照).
	 *
	 * @return void
	 */
	public function test_should_send_alert_false_when_nothing_to_report() {
		$this->assertFalse( WPCV_Generation_Differ::should_send_alert( 0, 0 ) );
	}

	/**
	 * 記録なしのversion変更(v0.6プラン §3.1・D5・U3)が1件以上あれば送ることを
	 * 確認する.
	 *
	 * @return void
	 */
	public function test_should_send_alert_true_when_unrecorded_version_change_count_positive() {
		$this->assertTrue( WPCV_Generation_Differ::should_send_alert( 0, 0, false, 1 ) );
	}

	/**
	 * 記録なしのversion変更が0件なら、他の入力も0/falseなら送らないことを確認する
	 * (第4引数の既定値が0であることも合わせて確認).
	 *
	 * @return void
	 */
	public function test_should_send_alert_false_when_unrecorded_version_change_count_is_zero() {
		$this->assertFalse( WPCV_Generation_Differ::should_send_alert( 0, 0, false, 0 ) );
		$this->assertFalse( WPCV_Generation_Differ::should_send_alert( 0, 0, false ) );
	}

	// ------------------------------------------------------------------
	// v0.5後半 §Step15b: is_unverifiable_streak_member()(§2.1の全セル)
	// ------------------------------------------------------------------

	/**
	 * target_run 1件分の最小データを作る.
	 *
	 * @param string      $status     `WPCV_Target_Status`の定数.
	 * @param string|null $error_code error_code.
	 * @param string      $target_id  target_id(既定は`plugin:foo`. coreの
	 *                                特例を確認するテストだけ明示的に上書きする).
	 * @return array
	 */
	private function make_target_run_for_streak( $status, $error_code = null, $target_id = 'plugin:foo' ) {
		return array(
			'target_id'  => $target_id,
			'status'     => $status,
			'error_code' => $error_code,
		);
	}

	/**
	 * `status = failed`はerror_codeを問わず数えることを確認する(§2.1の1行目.D5).
	 *
	 * @return void
	 */
	public function test_is_unverifiable_streak_member_true_when_failed_regardless_of_error_code() {
		$this->assertTrue(
			WPCV_Generation_Differ::is_unverifiable_streak_member(
				$this->make_target_run_for_streak( WPCV_Target_Status::FAILED, WPCV_Error_Code::ARCHIVE_INVALID )
			)
		);
	}

	/**
	 * `unverifiable`かつ`http_error`/`rate_limited`/`timeout`は数えることを確認する
	 * (§2.1.D5).
	 *
	 * @return void
	 */
	public function test_is_unverifiable_streak_member_true_for_transient_error_codes() {
		foreach ( array( WPCV_Error_Code::HTTP_ERROR, WPCV_Error_Code::RATE_LIMITED, WPCV_Error_Code::TIMEOUT ) as $error_code ) {
			$this->assertTrue(
				WPCV_Generation_Differ::is_unverifiable_streak_member(
					$this->make_target_run_for_streak( WPCV_Target_Status::UNVERIFIABLE, $error_code )
				),
				"error_code={$error_code}は数えるはず"
			);
		}
	}

	/**
	 * coreの`manifest_not_found`は数えることを確認する(§2.1.Q2).
	 *
	 * @return void
	 */
	public function test_is_unverifiable_streak_member_true_for_core_manifest_not_found() {
		$this->assertTrue(
			WPCV_Generation_Differ::is_unverifiable_streak_member(
				$this->make_target_run_for_streak( WPCV_Target_Status::UNVERIFIABLE, WPCV_Error_Code::MANIFEST_NOT_FOUND, 'core' )
			)
		);
	}

	/**
	 * core以外の`manifest_not_found`は独自プラグインの恒常状態のため途切れさせる
	 * ことを確認する(§2.1.Q2の反例).
	 *
	 * @return void
	 */
	public function test_is_unverifiable_streak_member_false_for_non_core_manifest_not_found() {
		$this->assertFalse(
			WPCV_Generation_Differ::is_unverifiable_streak_member(
				$this->make_target_run_for_streak( WPCV_Target_Status::UNVERIFIABLE, WPCV_Error_Code::MANIFEST_NOT_FOUND, 'plugin:foo' )
			)
		);

		// core:_scan はマニフェストを取得しないため対象外(設計書§2.1の注記).
		$this->assertFalse(
			WPCV_Generation_Differ::is_unverifiable_streak_member(
				$this->make_target_run_for_streak( WPCV_Target_Status::UNVERIFIABLE, WPCV_Error_Code::MANIFEST_NOT_FOUND, 'core:_scan' )
			)
		);
	}

	/**
	 * `unverifiable`かつ`unknown_source`/`target_missing`は一時障害ではないため
	 * 途切れさせることを確認する(§2.1).
	 *
	 * @return void
	 */
	public function test_is_unverifiable_streak_member_false_for_permanent_error_codes() {
		foreach ( array( WPCV_Error_Code::UNKNOWN_SOURCE, WPCV_Error_Code::TARGET_MISSING ) as $error_code ) {
			$this->assertFalse(
				WPCV_Generation_Differ::is_unverifiable_streak_member(
					$this->make_target_run_for_streak( WPCV_Target_Status::UNVERIFIABLE, $error_code )
				),
				"error_code={$error_code}は途切れさせるはず"
			);
		}
	}

	/**
	 * `success`(baseline_rebuiltを含む)は照合できたため途切れさせることを確認する
	 * (§2.1).
	 *
	 * @return void
	 */
	public function test_is_unverifiable_streak_member_false_when_success() {
		$this->assertFalse(
			WPCV_Generation_Differ::is_unverifiable_streak_member(
				$this->make_target_run_for_streak( WPCV_Target_Status::SUCCESS, WPCV_Error_Code::BASELINE_REBUILT )
			)
		);
	}

	/**
	 * `skipped`(checksum_covered等)は障害ではないため途切れさせることを確認する
	 * (§2.1).
	 *
	 * @return void
	 */
	public function test_is_unverifiable_streak_member_false_when_skipped() {
		$this->assertFalse(
			WPCV_Generation_Differ::is_unverifiable_streak_member(
				$this->make_target_run_for_streak( WPCV_Target_Status::SKIPPED, WPCV_Error_Code::CHECKSUM_COVERED )
			)
		);
	}
}
