<?php
/**
 * WPCV_Alert_Sender のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-finding-key.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-generation-differ.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-alert-composer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-diff-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-alert-sender.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Alert_Sender::send_for_run()` のテスト(v0.5後半 §Step14・§2.4・§2.5・§4.2・
 * §4.3の全セル).
 *
 * `WPCV_Generation_Differ::should_notify_finding()`/`should_send_alert()`自体の
 * 全パターン(§2.4・§2.5の判定ロジック)は`GenerationDifferTest`(Step11)で
 * 検証済みのため、ここでは`WPCV_Alert_Sender`がそれらを正しく呼び出し、正しく
 * 分岐してDB・メール・追加チャネルを操作することだけを確認する.
 */
class AlertSenderTest extends TestCase {

	/**
	 * このテストで固定する「現在時刻」.
	 *
	 * @var string
	 */
	const NOW = '2026-01-15 00:00:00';

	/**
	 * このテストでrunを`alerting`としてclaimしているowner
	 * (`record_alert_result()`のfencingに使う).
	 *
	 * @var string
	 */
	const OWNER = 'owner-test';

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * `_wpcv_test_added_actions`はここでは触らない(`feedback-shared-test-global-
	 * unset-hidden-by-suite`参照. 他クラスがrequire時点で1回だけ登録するグロー
	 * バルなため、丸ごと消すと全体スイート実行で他ファイルを壊す).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		unset(
			$GLOBALS['_wpcv_test_options'],
			$GLOBALS['_wpcv_test_is_multisite'],
			$GLOBALS['_wpcv_test_wp_mail_calls'],
			$GLOBALS['_wpcv_test_wp_mail_return'],
			$GLOBALS['_wpcv_test_wp_mail_trigger_failed'],
			$GLOBALS['_wpcv_test_do_action_calls'],
			$GLOBALS['_wpcv_test_filters']['wpcv_alert_channels'],
			$GLOBALS['_wpcv_test_filters']['wpcv_alert_resend_days']
		);
	}

	/**
	 * `wpcv_runs`の1行分の既定値を作る.
	 *
	 * @param int   $id        run の id.
	 * @param array $overrides 上書きするフィールド.
	 * @return array
	 */
	private function make_run_row( $id, array $overrides = array() ) {
		return array_merge(
			array(
				'id'                     => $id,
				'finished_at'            => self::NOW,
				'run_trigger'            => 'cron',
				'status'                 => 'success',
				'findings_new'           => 0,
				'findings_resolved'      => 0,
				'findings_continuing'    => 0,
				// `record_alert_result()`がfencingに使う.`alerting`をclaim済みの状態を模す.
				'diff_status'            => 'alerting',
				'diff_owner'             => self::OWNER,
				'alert_status'           => null,
				'alert_attempted_at'     => null,
				'alert_error'            => null,
				'alert_channel_failures' => null,
			),
			$overrides
		);
	}

	/**
	 * `wpcv_findings`の1行分を作る(`wpcv_test_make_finding_row()`に差分処理系の
	 * 列の上書きを重ねる).
	 *
	 * @param int   $id        finding の id.
	 * @param array $overrides 上書きするフィールド.
	 * @return array
	 */
	private function make_finding_row( $id, array $overrides = array() ) {
		return array_merge(
			wpcv_test_make_finding_row(),
			array( 'id' => $id ),
			$overrides
		);
	}

	/**
	 * `wpcv_target_runs`の1行分を作る.
	 *
	 * @param int   $id        target_run の id.
	 * @param array $overrides 上書きするフィールド.
	 * @return array
	 */
	private function make_target_run_row( $id, array $overrides = array() ) {
		return array_merge(
			wpcv_test_make_target_run(),
			array(
				'id'     => $id,
				'run_id' => 1,
			),
			$overrides
		);
	}

	/**
	 * `WPCV_Alert_Sender`と、それが使う3つのRepositoryを同一の`$wpdb`ダブルで
	 * 組み立てる.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb `$wpdb`ダブル.
	 * @return array{sender: WPCV_Alert_Sender, run_repository: WPCV_Run_Repository,
	 *               target_run_repository: WPCV_Target_Run_Repository,
	 *               finding_repository: WPCV_Finding_Repository}
	 */
	private function make_sender_environment( WPCV_Test_Fake_WPDB $wpdb ) {
		$now                    = static function () {
			return self::NOW;
		};
		$run_repository         = new WPCV_Run_Repository( $wpdb, $now );
		$target_run_repository  = new WPCV_Target_Run_Repository( $wpdb, $now );
		$finding_repository     = new WPCV_Finding_Repository( $wpdb );

		return array(
			'sender'                => new WPCV_Alert_Sender( $run_repository, $target_run_repository, $finding_repository, $now ),
			'run_repository'        => $run_repository,
			'target_run_repository' => $target_run_repository,
			'finding_repository'    => $finding_repository,
		);
	}

	/**
	 * 存在しないrun_idを渡すと、何のDB操作もせずに`run_not_found`を返すことを
	 * 確認する.
	 *
	 * @return void
	 */
	public function test_run_not_found_returns_action_without_side_effects() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$env  = $this->make_sender_environment( $wpdb );

		$result = $env['sender']->send_for_run( 999, self::OWNER );

		$this->assertSame( array( 'action' => 'run_not_found' ), $result );
		$this->assertSame( array(), $wpdb->rows );
	}

	/**
	 * 通知対象・resolvedのどちらも0件なら`not_needed`になり、
	 * `alert_attempted_at`を書かない(送信を試みていないため)ことを確認する.
	 *
	 * @return void
	 */
	public function test_not_needed_when_nothing_to_report() {
		$wpdb                           = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1] = $this->make_run_row(
			1,
			// 送信を試みていないことを「初期値のnullのまま」ではなく「更新されて
			// いないこと」として確認するため、あえてnull以外の番兵値を入れておく.
			array( 'alert_attempted_at' => '2020-01-01 00:00:00' )
		);
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$result = $env['sender']->send_for_run( 1, self::OWNER );

		$this->assertSame( array( 'action' => 'not_needed' ), $result );
		$this->assertSame( 'not_needed', $wpdb->rows['wp_wpcv_runs'][1]['alert_status'] );
		$this->assertSame( '2020-01-01 00:00:00', $wpdb->rows['wp_wpcv_runs'][1]['alert_attempted_at'] );
		$this->assertArrayNotHasKey( '_wpcv_test_wp_mail_calls', $GLOBALS );
	}

	/**
	 * stat基準の作り直し(`baseline_rebuilt`)だけの run では送らないことを確認する
	 * (U3. baseline_rebuiltはnotify_count/resolved_countのどちらにも含まれない).
	 *
	 * @return void
	 */
	public function test_not_needed_when_only_baseline_rebuilt() {
		$wpdb                              = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1]       = $this->make_run_row( 1 );
		$wpdb->rows['wp_wpcv_target_runs'][1] = $this->make_target_run_row(
			1,
			array(
				'target_id'  => 'core:_stat',
				'error_code' => WPCV_Error_Code::BASELINE_REBUILT,
				'version'    => '6.9',
			)
		);
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$result = $env['sender']->send_for_run( 1, self::OWNER );

		$this->assertSame( array( 'action' => 'not_needed' ), $result );
	}

	/**
	 * `error_code = version_changed_unrecorded`(v0.6プラン §3.1・D5)だけの run でも
	 * アラートを送ること(`should_send_alert()`への入力として効くこと)を確認する.
	 *
	 * @return void
	 */
	public function test_sends_when_only_unrecorded_version_change() {
		$wpdb                                = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1]         = $this->make_run_row( 1 );
		$wpdb->rows['wp_wpcv_target_runs'][1]   = $this->make_target_run_row(
			1,
			array(
				'target_id'  => 'plugin:foo',
				'error_code' => WPCV_Error_Code::VERSION_CHANGED_UNRECORDED,
				'version'    => '2.0',
			)
		);
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$result = $env['sender']->send_for_run( 1, self::OWNER );

		$this->assertSame( array( 'action' => 'sent' ), $result );
		$this->assertSame( 'sent', $wpdb->rows['wp_wpcv_runs'][1]['alert_status'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_mail_calls'] );

		// 件名: findingが0件のときは専用の文言になる(`WPCV_Alert_Composer::build_subject()`参照).
		$this->assertStringContainsString( '1 version change', $GLOBALS['_wpcv_test_wp_mail_calls'][0]['subject'] );
		// 本文: 節見出しと`target from -> to`が載る(基準target_runは用意していないため from は空文字列).
		$this->assertStringContainsString( 'Version changed without a WordPress update:', $GLOBALS['_wpcv_test_wp_mail_calls'][0]['message'] );
		$this->assertStringContainsString( 'plugin:foo', $GLOBALS['_wpcv_test_wp_mail_calls'][0]['message'] );
		$this->assertStringContainsString( '-> 2.0', $GLOBALS['_wpcv_test_wp_mail_calls'][0]['message'] );
	}

	/**
	 * 宛先未設定のときは`no_recipient`を記録し、メール・追加チャネルのどちらも
	 * 実行しないことを確認する(U1・§4.3「no_recipientのときは実行しない」).
	 *
	 * @return void
	 */
	public function test_no_recipient_when_alert_to_empty() {
		$wpdb                           = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1]     = $this->make_run_row( 1 );
		$wpdb->rows['wp_wpcv_findings'][1] = $this->make_finding_row(
			1,
			array(
				'finding_key' => 'key-1',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_NEW,
			)
		);
		$env = $this->make_sender_environment( $wpdb );

		$channel_invoked = false;
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_channels'][] = static function ( $channels ) use ( &$channel_invoked ) {
			$channel_invoked = true;

			return $channels;
		};

		$result = $env['sender']->send_for_run( 1, self::OWNER );

		$this->assertSame( array( 'action' => 'no_recipient' ), $result );
		$this->assertSame( 'no_recipient', $wpdb->rows['wp_wpcv_runs'][1]['alert_status'] );
		$this->assertSame( self::NOW, $wpdb->rows['wp_wpcv_runs'][1]['alert_attempted_at'] );
		$this->assertArrayNotHasKey( '_wpcv_test_wp_mail_calls', $GLOBALS );
		$this->assertFalse( $channel_invoked );
	}

	/**
	 * §2.4の4状態(new/continuing/event/抑制済みNULL)×`notified_at`の有無×
	 * 再送抑制の期限内外の全セルを、1回の送信の中でまとめて確認する.
	 *
	 * @return void
	 */
	public function test_sent_notifies_correct_findings_across_diff_states_and_resend_window() {
		$wpdb                     = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1] = $this->make_run_row( 1, array( 'findings_new' => 3 ) );

		// 過去run(id=2)の行. 同じ finding_key の直近 notified_at を持つだけの
		// 「幽霊」行(このrun自体の通知候補にはならない).
		$wpdb->rows['wp_wpcv_findings'][101] = $this->make_finding_row(
			101,
			array(
				'run_id'      => 2,
				'finding_key' => 'key-b-recent',
				'diff_state'  => null,
				'notified_at' => '2026-01-14 00:00:00', // 1日前(7日以内): 再送抑制.
			)
		);
		$wpdb->rows['wp_wpcv_findings'][102] = $this->make_finding_row(
			102,
			array(
				'run_id'      => 2,
				'finding_key' => 'key-c-old',
				'diff_state'  => null,
				'notified_at' => '2025-12-20 00:00:00', // 26日前(7日超): 再送してよい.
			)
		);
		$wpdb->rows['wp_wpcv_findings'][103] = $this->make_finding_row(
			103,
			array(
				'run_id'      => 2,
				'finding_key' => 'key-e-notified',
				'diff_state'  => null,
				'notified_at' => '2026-01-10 00:00:00', // continuingは日数を問わず抑制.
			)
		);

		// 今回run(id=1)の行.
		$wpdb->rows['wp_wpcv_findings'][1] = $this->make_finding_row(
			1,
			array(
				'finding_key' => 'key-a-new',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_NEW,
			)
		); // NEW・過去の通知なし → 送る.
		$wpdb->rows['wp_wpcv_findings'][2] = $this->make_finding_row(
			2,
			array(
				'finding_key' => 'key-b-recent',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_NEW,
			)
		); // NEW・7日以内に通知済み → 送らない.
		$wpdb->rows['wp_wpcv_findings'][3] = $this->make_finding_row(
			3,
			array(
				'finding_key' => 'key-c-old',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_NEW,
			)
		); // NEW・7日超前に通知済み → 送る.
		$wpdb->rows['wp_wpcv_findings'][4] = $this->make_finding_row(
			4,
			array(
				'finding_key' => 'key-d-new',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_CONTINUING,
			)
		); // CONTINUING・過去の通知なし → 送る.
		$wpdb->rows['wp_wpcv_findings'][5] = $this->make_finding_row(
			5,
			array(
				'finding_key' => 'key-e-notified',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_CONTINUING,
			)
		); // CONTINUING・通知済み → 送らない.
		$wpdb->rows['wp_wpcv_findings'][6] = $this->make_finding_row(
			6,
			array(
				'finding_key' => 'key-f-event',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_EVENT,
				'notified_at' => null,
			)
		); // EVENT → 常に送る.
		$wpdb->rows['wp_wpcv_findings'][7] = $this->make_finding_row(
			7,
			array(
				'finding_key' => 'key-g-suppressed',
				'diff_state'  => null,
			)
		); // 抑制済み(NULL) → そもそも候補に入らない.

		$env = $this->make_sender_environment( $wpdb );
		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$result = $env['sender']->send_for_run( 1, self::OWNER );

		$this->assertSame( array( 'action' => 'sent' ), $result );

		$notified_ids     = array( 1, 3, 4, 6 );
		$not_notified_ids = array( 2, 5, 7 );

		foreach ( $notified_ids as $id ) {
			$this->assertSame( self::NOW, $wpdb->rows['wp_wpcv_findings'][ $id ]['notified_at'], "id={$id}" );
		}

		foreach ( $not_notified_ids as $id ) {
			$this->assertNull( $wpdb->rows['wp_wpcv_findings'][ $id ]['notified_at'], "id={$id}" );
		}

		$this->assertSame( 'sent', $wpdb->rows['wp_wpcv_runs'][1]['alert_status'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_mail_calls'] );
		$this->assertSame( array( 'ops@example.com' ), $GLOBALS['_wpcv_test_wp_mail_calls'][0]['to'] );
	}

	/**
	 * `wp_mail()`が`false`を返す(`wp_mail_failed`は発火しない)場合、
	 * `failed`を記録し、`notified_at`を書かないことを確認する.
	 *
	 * @return void
	 */
	public function test_mail_returning_false_records_failed_without_marking_notified() {
		$wpdb                           = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1]     = $this->make_run_row( 1 );
		$wpdb->rows['wp_wpcv_findings'][1] = $this->make_finding_row(
			1,
			array(
				'finding_key' => 'key-1',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_NEW,
			)
		);
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_wp_mail_return'] = false;

		$result = $env['sender']->send_for_run( 1, self::OWNER );

		$this->assertSame( array( 'action' => 'failed' ), $result );
		$this->assertSame( 'failed', $wpdb->rows['wp_wpcv_runs'][1]['alert_status'] );
		$this->assertNull( $wpdb->rows['wp_wpcv_runs'][1]['alert_error'] );
		$this->assertNull( $wpdb->rows['wp_wpcv_findings'][1]['notified_at'] );
	}

	/**
	 * `wp_mail()`が`true`を返しても、送信中に`wp_mail_failed`が発火すれば失敗
	 * 扱いにし、`WP_Error::get_error_message()`だけを`alert_error`に保存する
	 * ことを確認する(§6: `get_error_data()`は保存しない).
	 *
	 * @return void
	 */
	public function test_wp_mail_failed_event_overrides_true_return_value() {
		$wpdb                           = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1]     = $this->make_run_row( 1 );
		$wpdb->rows['wp_wpcv_findings'][1] = $this->make_finding_row(
			1,
			array(
				'finding_key' => 'key-1',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_NEW,
			)
		);
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_wp_mail_return']         = true;
		$GLOBALS['_wpcv_test_wp_mail_trigger_failed'] = new WP_Error( 'wp_mail_failed', 'SMTP connect() failed', array( 'phpmailer_exception_code' => 2 ) );

		$result = $env['sender']->send_for_run( 1, self::OWNER );

		$this->assertSame( array( 'action' => 'failed' ), $result );
		$this->assertSame( 'SMTP connect() failed', $wpdb->rows['wp_wpcv_runs'][1]['alert_error'] );
		$this->assertNull( $wpdb->rows['wp_wpcv_findings'][1]['notified_at'] );
	}

	/**
	 * 追加チャネル(`wpcv_alert_channels`)の成功・`false`・例外の組み合わせを
	 * 確認する. 1つの失敗は他のチャネル・メールに影響せず、失敗した`name`だけを
	 * カンマ区切りで記録する(§4.3). `$context`にrun_id/subject/body/counts/
	 * details_urlのみが渡ることも確認する.
	 *
	 * @return void
	 */
	public function test_channels_success_false_and_exception_are_recorded_independently() {
		$wpdb                           = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1]     = $this->make_run_row( 1 );
		$wpdb->rows['wp_wpcv_findings'][1] = $this->make_finding_row(
			1,
			array(
				'finding_key' => 'key-1',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_NEW,
			)
		);
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$captured_context = null;

		$GLOBALS['_wpcv_test_filters']['wpcv_alert_channels'][] = static function ( $channels, $context ) use ( &$captured_context ) {
			$captured_context = $context;

			return array(
				array(
					'name' => 'ok',
					'send' => static function () {
						return true;
					},
				),
				array(
					'name' => 'bad',
					'send' => static function () {
						return false;
					},
				),
				array(
					'name' => 'boom',
					'send' => static function () {
						throw new RuntimeException( 'channel exploded' );
					},
				),
			);
		};

		$result = $env['sender']->send_for_run( 1, self::OWNER );

		$this->assertSame( array( 'action' => 'sent' ), $result );
		$this->assertSame( 'bad, boom', $wpdb->rows['wp_wpcv_runs'][1]['alert_channel_failures'] );
		$this->assertSame( self::NOW, $wpdb->rows['wp_wpcv_findings'][1]['notified_at'] );

		$this->assertSame(
			array( 'type', 'run_id', 'subject', 'body', 'counts', 'details_url' ),
			array_keys( $captured_context )
		);
		$this->assertSame( 1, $captured_context['run_id'] );
		$this->assertSame( 'diff', $captured_context['type'], '差分アラートは`type => diff`を渡す(v0.5後半 §Step15a設計§3.3.Q3)' );
	}

	/**
	 * メールが失敗しても、追加チャネルは実行することを確認する
	 * (§4.3「実行する条件」はsent/failedの両方).
	 *
	 * @return void
	 */
	public function test_channels_run_even_when_mail_fails() {
		$wpdb                           = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1]     = $this->make_run_row( 1 );
		$wpdb->rows['wp_wpcv_findings'][1] = $this->make_finding_row(
			1,
			array(
				'finding_key' => 'key-1',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_NEW,
			)
		);
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_wp_mail_return'] = false;

		$channel_invoked = false;
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_channels'][] = static function ( $channels ) use ( &$channel_invoked ) {
			return array(
				array(
					'name' => 'ok',
					'send' => static function () use ( &$channel_invoked ) {
						$channel_invoked = true;

						return true;
					},
				),
			);
		};

		$result = $env['sender']->send_for_run( 1, self::OWNER );

		$this->assertSame( array( 'action' => 'failed' ), $result );
		$this->assertTrue( $channel_invoked );
		$this->assertNull( $wpdb->rows['wp_wpcv_runs'][1]['alert_channel_failures'] );
	}

	/**
	 * `mark_notified_by_ids()`が本文の上位N件だけでなく、通知対象の全件
	 * (1,235件)に対して呼ばれることを確認する.
	 *
	 * @return void
	 */
	public function test_mark_notified_by_ids_covers_full_set_not_only_top_items() {
		$wpdb                       = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1] = $this->make_run_row( 1, array( 'findings_new' => 1235 ) );

		for ( $i = 1; $i <= 1235; $i++ ) {
			$wpdb->rows['wp_wpcv_findings'][ $i ] = $this->make_finding_row(
				$i,
				array(
					'finding_key' => "key-{$i}",
					'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_NEW,
					'path'        => sprintf( 'unknown-%04d.php', $i ),
				)
			);
		}

		$env = $this->make_sender_environment( $wpdb );
		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$result = $env['sender']->send_for_run( 1, self::OWNER );

		$this->assertSame( array( 'action' => 'sent' ), $result );

		$notified_count = 0;

		foreach ( $wpdb->rows['wp_wpcv_findings'] as $row ) {
			if ( self::NOW === $row['notified_at'] ) {
				++$notified_count;
			}
		}

		$this->assertSame( 1235, $notified_count );
		$this->assertStringContainsString( 'Top 20 by severity:', $GLOBALS['_wpcv_test_wp_mail_calls'][0]['message'] );
	}

	/**
	 * 通知候補をバッチで読みながら上位N件だけを持つようにしても(コードレビュー
	 * 指摘4)、後のバッチにある重要度の高いfindingが本文の先頭に載り、一覧は
	 * 上位N件(既定20件)に収まることを確認する.
	 *
	 * 1バッチ目(id 1〜500)と2バッチ目の前半はすべて`low`、2バッチ目の最後
	 * (id 601)だけ`high`にする.
	 *
	 * @return void
	 */
	public function test_top_items_keep_high_severity_from_later_batch() {
		$wpdb                          = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1] = $this->make_run_row( 1, array( 'findings_new' => 601 ) );

		for ( $i = 1; $i <= 601; $i++ ) {
			$wpdb->rows['wp_wpcv_findings'][ $i ] = $this->make_finding_row(
				$i,
				array(
					'finding_key' => "key-{$i}",
					'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_NEW,
					'severity'    => 601 === $i ? 'high' : 'low',
					'path'        => 601 === $i ? 'late-high.php' : sprintf( 'low-%04d.php', $i ),
				)
			);
		}

		$env = $this->make_sender_environment( $wpdb );
		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$this->assertSame( array( 'action' => 'sent' ), $env['sender']->send_for_run( 1, self::OWNER ) );

		$body  = $GLOBALS['_wpcv_test_wp_mail_calls'][0]['message'];
		$lines = explode( "\n", $body );
		$head  = array_search( 'Top 20 by severity:', $lines, true );

		$this->assertNotFalse( $head, '一覧は上位20件に収まる' );
		$this->assertStringContainsString( 'late-high.php', $lines[ $head + 1 ], '2バッチ目のhighが先頭に載る' );
		$this->assertStringContainsString( 'low-0001.php', $lines[ $head + 2 ], '残りはlowが並び順どおりに続く' );
	}

	/**
	 * `no_recipient`だったrunの次に、宛先を設定した別のrunでは`sent`になる
	 * ことを確認する(streak〔連続の記録〕はStep15の範囲のため見ない.
	 * それぞれのrunの`alert_status`が正しく記録されることだけを見る).
	 *
	 * @return void
	 */
	public function test_status_recorded_independently_across_runs() {
		$wpdb                           = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1]     = $this->make_run_row( 1 );
		$wpdb->rows['wp_wpcv_findings'][1] = $this->make_finding_row(
			1,
			array(
				'finding_key' => 'key-1',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_NEW,
			)
		);
		$wpdb->rows['wp_wpcv_runs'][2]     = $this->make_run_row( 2 );
		$wpdb->rows['wp_wpcv_findings'][2] = $this->make_finding_row(
			2,
			array(
				'run_id'      => 2,
				'finding_key' => 'key-2',
				'diff_state'  => WPCV_Generation_Differ::DIFF_STATE_NEW,
			)
		);
		$env = $this->make_sender_environment( $wpdb );

		$first = $env['sender']->send_for_run( 1, self::OWNER );
		$this->assertSame( array( 'action' => 'no_recipient' ), $first );
		$this->assertSame( 'no_recipient', $wpdb->rows['wp_wpcv_runs'][1]['alert_status'] );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$second = $env['sender']->send_for_run( 2, self::OWNER );
		$this->assertSame( array( 'action' => 'sent' ), $second );
		$this->assertSame( 'sent', $wpdb->rows['wp_wpcv_runs'][2]['alert_status'] );
	}

	/**
	 * `send_test()`が宛先未設定のとき`no_recipient`を返し、メールを送らないことを
	 * 確認する(v0.5後半 §Step14d).
	 *
	 * @return void
	 */
	public function test_send_test_returns_no_recipient_when_alert_to_empty() {
		$env = $this->make_sender_environment( new WPCV_Test_Fake_WPDB() );

		$result = $env['sender']->send_test();

		$this->assertSame( array( 'action' => 'no_recipient', 'error' => null ), $result );
		$this->assertArrayNotHasKey( '_wpcv_test_wp_mail_calls', $GLOBALS );
	}

	/**
	 * `send_test()`が宛先ありで`wp_mail()`成功時に`sent`を返し、実際に
	 * 保存済み`alert_to`宛にメールを送ることを確認する(v0.5後半 §Step14d).
	 * DBには何も記録しない(run に紐付かないテスト送信のため)ことも確認する.
	 *
	 * @return void
	 */
	public function test_send_test_sends_to_saved_alert_to_and_records_nothing() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$env  = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$result = $env['sender']->send_test();

		$this->assertSame( array( 'action' => 'sent', 'error' => null ), $result );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_mail_calls'] );
		$this->assertSame( array( 'ops@example.com' ), $GLOBALS['_wpcv_test_wp_mail_calls'][0]['to'] );
		$this->assertStringContainsString( '[WPCV] Test alert', $GLOBALS['_wpcv_test_wp_mail_calls'][0]['subject'] );
		$this->assertSame( array(), $wpdb->rows );
	}

	/**
	 * `send_test()`が`wp_mail()`失敗時に`failed`とエラーメッセージを返すことを
	 * 確認する(v0.5後半 §Step14d).
	 *
	 * @return void
	 */
	public function test_send_test_returns_failed_with_error_message() {
		$env = $this->make_sender_environment( new WPCV_Test_Fake_WPDB() );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_wp_mail_trigger_failed'] = new WP_Error( 'wp_mail_failed', 'SMTP connect() failed' );

		$result = $env['sender']->send_test();

		$this->assertSame( array( 'action' => 'failed', 'error' => 'SMTP connect() failed' ), $result );
	}

	// ------------------------------------------------------------------
	// v0.5後半 §Step15b: 連続unverifiable(`send_for_run()`への組み込み)
	// ------------------------------------------------------------------

	/**
	 * 連続unverifiableが閾値に達すると、通知対象のfindingが0件でも送ることを
	 * 確認する(§2.5「新しく連続unverifiableの閾値に達したtargetがある→する」).
	 * 本文に「Unverifiable N times in a row:」の節とtarget_idが出ることも確認する.
	 *
	 * @return void
	 */
	public function test_unverifiable_streak_triggers_alert_even_without_findings() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1] = array(
			'id'           => 1,
			'status'       => 'success',
			'run_trigger'  => 'cron',
			'runner'       => 'sync',
			'alert_status' => null,
		);
		$wpdb->rows['wp_wpcv_target_runs'][1] = $this->make_target_run_row(
			1,
			array(
				'run_id'     => 1,
				'target_id'  => 'plugin:foo',
				'status'     => 'unverifiable',
				'error_code' => 'http_error',
			)
		);

		$wpdb->rows['wp_wpcv_runs'][2]         = $this->make_run_row( 2 );
		$wpdb->rows['wp_wpcv_target_runs'][2] = $this->make_target_run_row(
			2,
			array(
				'run_id'     => 2,
				'target_id'  => 'plugin:foo',
				'status'     => 'unverifiable',
				'error_code' => 'http_error',
			)
		);

		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_unverifiable_streak'][] = static function () {
			return 2;
		};

		$result = $env['sender']->send_for_run( 2, self::OWNER );

		$this->assertSame( array( 'action' => 'sent' ), $result );
		$this->assertSame( 'sent', $wpdb->rows['wp_wpcv_runs'][2]['alert_status'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_mail_calls'] );
		$body = $GLOBALS['_wpcv_test_wp_mail_calls'][0]['message'];
		$this->assertStringContainsString( 'Unverifiable 2 times in a row:', $body );
		$this->assertStringContainsString( 'plugin:foo', $body );
	}

	/**
	 * 連続が閾値未満のときは送らないことを確認する(§2.5.通知対象の
	 * findingも無いケース).
	 *
	 * @return void
	 */
	public function test_unverifiable_streak_below_threshold_does_not_trigger_alert() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1]         = $this->make_run_row( 1 );
		$wpdb->rows['wp_wpcv_target_runs'][1] = $this->make_target_run_row(
			1,
			array(
				'run_id'     => 1,
				'target_id'  => 'plugin:foo',
				'status'     => 'unverifiable',
				'error_code' => 'http_error',
			)
		);

		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_unverifiable_streak'][] = static function () {
			return 2;
		};

		$result = $env['sender']->send_for_run( 1, self::OWNER );

		$this->assertSame( array( 'action' => 'not_needed' ), $result );
	}

	/**
	 * 既にその連続で通知済み(閾値の位置以降に`alert_status = sent`のrunが
	 * ある)なら、連続がまだ続いていても再送しないことを確認する(§2.2の
	 * 「通知済み」判定).
	 *
	 * @return void
	 */
	public function test_unverifiable_streak_not_resent_once_already_notified() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][1] = array(
			'id'           => 1,
			'status'       => 'success',
			'run_trigger'  => 'cron',
			'runner'       => 'sync',
			'alert_status' => null,
		);
		$wpdb->rows['wp_wpcv_target_runs'][1] = $this->make_target_run_row(
			1,
			array(
				'run_id'     => 1,
				'target_id'  => 'plugin:foo',
				'status'     => 'unverifiable',
				'error_code' => 'http_error',
			)
		);

		// 位置2(閾値ちょうどで最初に送ったrun): 送信成功済み.
		$wpdb->rows['wp_wpcv_runs'][2] = array(
			'id'           => 2,
			'status'       => 'success',
			'run_trigger'  => 'cron',
			'runner'       => 'sync',
			'alert_status' => 'sent',
		);
		$wpdb->rows['wp_wpcv_target_runs'][2] = $this->make_target_run_row(
			2,
			array(
				'run_id'     => 2,
				'target_id'  => 'plugin:foo',
				'status'     => 'unverifiable',
				'error_code' => 'http_error',
			)
		);

		$wpdb->rows['wp_wpcv_runs'][3]         = $this->make_run_row( 3 );
		$wpdb->rows['wp_wpcv_target_runs'][3] = $this->make_target_run_row(
			3,
			array(
				'run_id'     => 3,
				'target_id'  => 'plugin:foo',
				'status'     => 'unverifiable',
				'error_code' => 'http_error',
			)
		);

		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_unverifiable_streak'][] = static function () {
			return 2;
		};

		$result = $env['sender']->send_for_run( 3, self::OWNER );

		$this->assertSame( array( 'action' => 'not_needed' ), $result );
	}

	// ------------------------------------------------------------------
	// v0.5後半 §Step15a: run の連続失敗アラート(`send_run_failure()`)
	// ------------------------------------------------------------------

	/**
	 * `alert_to`が空のとき、メール・追加チャネルのどちらも実行せず
	 * `no_recipient`を記録することを確認する(§4.3「実行しない」.U1と同じ考え方).
	 *
	 * @return void
	 */
	public function test_send_run_failure_no_recipient_when_alert_to_empty() {
		$wpdb                       = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][6] = array(
			'id'     => 6,
			'status' => 'failed',
		);
		$env = $this->make_sender_environment( $wpdb );

		$channel_invoked = false;
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_channels'][] = static function ( $channels ) use ( &$channel_invoked ) {
			$channel_invoked = true;

			return $channels;
		};

		$result = $env['sender']->send_run_failure(
			6,
			array(
				array(
					'id'          => 6,
					'status'      => 'failed',
					'started_at'  => self::NOW,
					'run_trigger' => 'cron',
				),
			)
		);

		$this->assertSame( array( 'action' => 'no_recipient' ), $result );
		$this->assertSame( 'no_recipient', $wpdb->rows['wp_wpcv_runs'][6]['alert_status'] );
		$this->assertArrayNotHasKey( '_wpcv_test_wp_mail_calls', $GLOBALS );
		$this->assertFalse( $channel_invoked );
	}

	/**
	 * 宛先ありでメール送信成功時、件名・本文が`compose_run_failure()`の内容に
	 * なり、`sent`が記録され、追加チャネルに`type => 'run_failure'`・空の
	 * `counts`が渡ることを確認する.
	 *
	 * @return void
	 */
	public function test_send_run_failure_sends_mail_and_records_sent() {
		$wpdb                       = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][6] = array(
			'id'     => 6,
			'status' => 'failed',
		);
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$captured_context = null;
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_channels'][] = static function ( $channels, $context ) use ( &$captured_context ) {
			$captured_context = $context;

			return $channels;
		};

		$streak_runs = array(
			array(
				'id'          => 6,
				'status'      => 'failed',
				'started_at'  => '2026-01-15 00:00:00',
				'run_trigger' => 'cron',
			),
			array(
				'id'          => 5,
				'status'      => 'aborted',
				'started_at'  => '2026-01-14 00:00:00',
				'run_trigger' => 'manual',
			),
		);

		$result = $env['sender']->send_run_failure( 6, $streak_runs );

		$this->assertSame( array( 'action' => 'sent' ), $result );
		$this->assertSame( 'sent', $wpdb->rows['wp_wpcv_runs'][6]['alert_status'] );
		$this->assertSame( self::NOW, $wpdb->rows['wp_wpcv_runs'][6]['alert_attempted_at'] );

		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_mail_calls'] );
		$mail = $GLOBALS['_wpcv_test_wp_mail_calls'][0];
		$this->assertSame( array( 'ops@example.com' ), $mail['to'] );
		$this->assertStringContainsString( '2 runs failed in a row', $mail['subject'] );
		$this->assertStringContainsString( '#6  failed  2026-01-15 00:00:00 +00:00  cron', $mail['message'] );
		$this->assertStringContainsString( '#5  aborted  2026-01-14 00:00:00 +00:00  manual', $mail['message'] );
		$this->assertStringContainsString( 'Details: http://example.com/wp-admin/admin.php?page=wpcv-runs', $mail['message'] );

		$this->assertSame( 'run_failure', $captured_context['type'] );
		$this->assertSame( array(), $captured_context['counts'] );
	}

	/**
	 * `wp_mail()`失敗時に`failed`とエラーメッセージを記録することを確認する
	 * (§4.2・§6).
	 *
	 * @return void
	 */
	public function test_send_run_failure_records_failed_when_mail_fails() {
		$wpdb                       = new WPCV_Test_Fake_WPDB();
		$wpdb->rows['wp_wpcv_runs'][6] = array(
			'id'     => 6,
			'status' => 'failed',
		);
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_wp_mail_trigger_failed'] = new WP_Error( 'wp_mail_failed', 'SMTP connect() failed' );

		$result = $env['sender']->send_run_failure(
			6,
			array(
				array(
					'id'          => 6,
					'status'      => 'failed',
					'started_at'  => self::NOW,
					'run_trigger' => 'cron',
				),
			)
		);

		$this->assertSame( array( 'action' => 'failed' ), $result );
		$this->assertSame( 'failed', $wpdb->rows['wp_wpcv_runs'][6]['alert_status'] );
		$this->assertSame( 'SMTP connect() failed', $wpdb->rows['wp_wpcv_runs'][6]['alert_error'] );
	}

	// ------------------------------------------------------------------
	// v0.9 §Step7: GitHub のトークンの失効・権限不足(`source_access_denied`)の通知(R8).
	// ------------------------------------------------------------------

	/**
	 * 3つの run と、`plugin:foo` の target_run を用意する(`$statuses` は run 1・2 の状態、
	 * 3つ目の run が今回).
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb     フェイク wpdb.
	 * @param array               $previous run 1・2 の target_run の `status`/`error_code`(run_id => 上書き).
	 * @return void
	 */
	private function seed_access_denied_history( WPCV_Test_Fake_WPDB $wpdb, array $previous ) {
		foreach ( array( 1, 2, 3 ) as $run_id ) {
			$wpdb->rows['wp_wpcv_runs'][ $run_id ] = $this->make_run_row( $run_id );
		}

		foreach ( $previous as $run_id => $overrides ) {
			$wpdb->rows['wp_wpcv_target_runs'][ $run_id ] = $this->make_target_run_row(
				$run_id,
				array_merge(
					array(
						'run_id'    => $run_id,
						'target_id' => 'plugin:foo',
					),
					$overrides
				)
			);
		}

		$wpdb->rows['wp_wpcv_target_runs'][3] = $this->make_target_run_row(
			3,
			array(
				'run_id'     => 3,
				'target_id'  => 'plugin:foo',
				'status'     => 'unverifiable',
				'error_code' => WPCV_Error_Code::SOURCE_ACCESS_DENIED,
			)
		);
	}

	/**
	 * 初めて `source_access_denied` になった(前回の結果が無い)ときは、他に理由が無くてもメールを送る(R8).
	 * 件名は専用の文言で、本文に「(new)」と、トークンを確認する案内が載る.
	 *
	 * @return void
	 */
	public function test_sends_when_access_denied_appears_for_the_first_time() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$this->seed_access_denied_history( $wpdb, array() );
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$this->assertSame( array( 'action' => 'sent' ), $env['sender']->send_for_run( 3, self::OWNER ) );

		$mail = $GLOBALS['_wpcv_test_wp_mail_calls'][0];

		$this->assertStringContainsString( '1 target(s) cannot be compared with the source', $mail['subject'] );
		$this->assertStringContainsString( 'Cannot compare with the source', $mail['message'] );
		$this->assertStringContainsString( 'plugin:foo  (new)', $mail['message'] );
		$this->assertStringContainsString( 'WPCV_GITHUB_TOKEN', $mail['message'] );
	}

	/**
	 * 前回も同じ `source_access_denied` なら「続いている」ので、それだけではメールを送らない(`not_needed`).
	 *
	 * @return void
	 */
	public function test_does_not_send_when_access_denied_continues() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$this->seed_access_denied_history(
			$wpdb,
			array(
				2 => array(
					'status'     => 'unverifiable',
					'error_code' => WPCV_Error_Code::SOURCE_ACCESS_DENIED,
				),
			)
		);
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$this->assertSame( array( 'action' => 'not_needed' ), $env['sender']->send_for_run( 3, self::OWNER ) );
		$this->assertArrayNotHasKey( '_wpcv_test_wp_mail_calls', $GLOBALS );
	}

	/**
	 * 中断(aborted)の run を挟んでも、確定した前回の結果で「続いている」と判定する(また初めてにならない).
	 *
	 * @return void
	 */
	public function test_aborted_previous_run_is_skipped_when_judging_new() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$this->seed_access_denied_history(
			$wpdb,
			array(
				1 => array(
					'status'     => 'unverifiable',
					'error_code' => WPCV_Error_Code::SOURCE_ACCESS_DENIED,
				),
				2 => array(
					'status'     => 'aborted',
					'error_code' => null,
				),
			)
		);
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$this->assertSame( array( 'action' => 'not_needed' ), $env['sender']->send_for_run( 3, self::OWNER ) );
	}

	/**
	 * 直って(前回 success)からまた失効したときは、もう一度「初めて」として送る.
	 *
	 * @return void
	 */
	public function test_sends_again_when_access_denied_recurs_after_recovery() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$this->seed_access_denied_history(
			$wpdb,
			array(
				1 => array(
					'status'     => 'unverifiable',
					'error_code' => WPCV_Error_Code::SOURCE_ACCESS_DENIED,
				),
				2 => array(
					'status'     => 'success',
					'error_code' => null,
				),
			)
		);
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$this->assertSame( array( 'action' => 'sent' ), $env['sender']->send_for_run( 3, self::OWNER ) );
		$this->assertStringContainsString( 'plugin:foo  (new)', $GLOBALS['_wpcv_test_wp_mail_calls'][0]['message'] );
	}

	/**
	 * 続いている `source_access_denied` も、ほかの理由でメールを送るとき(ここでは解消した finding)の
	 * 本文には載る(「(new)」は付かない. 件名は通常のまま).
	 *
	 * @return void
	 */
	public function test_continuing_access_denied_is_listed_when_alert_is_sent_for_another_reason() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$this->seed_access_denied_history(
			$wpdb,
			array(
				2 => array(
					'status'     => 'unverifiable',
					'error_code' => WPCV_Error_Code::SOURCE_ACCESS_DENIED,
				),
			)
		);
		// 別の理由: 今回の run が解消した finding を持つ.
		$wpdb->rows['wp_wpcv_runs'][3]['findings_resolved'] = 2;
		$env = $this->make_sender_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$this->assertSame( array( 'action' => 'sent' ), $env['sender']->send_for_run( 3, self::OWNER ) );

		$mail = $GLOBALS['_wpcv_test_wp_mail_calls'][0];

		$this->assertStringContainsString( '0 new findings, 2 resolved', $mail['subject'] );
		$this->assertStringContainsString( 'Cannot compare with the source', $mail['message'] );
		$this->assertStringContainsString( '  plugin:foo', $mail['message'] );
		$this->assertStringNotContainsString( '(new)', $mail['message'] );
	}
}
