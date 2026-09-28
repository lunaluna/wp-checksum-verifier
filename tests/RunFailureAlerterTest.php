<?php
/**
 * WPCV_Run_Failure_Alerter のテスト.
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
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-diff-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-alert-sender.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-failure-alerter.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Run_Failure_Alerter::handle()` のテスト(v0.5後半 §Step15a設計§2.3・§3.3・
 * §3.4の全セル).
 *
 * `find_failure_streak()`自体の全パターン(§2.3の組み合わせ表)は
 * `RunRepositoryTest`で検証済みのため、ここでは`handle()`がその結果を正しく
 * 解釈し、正しく分岐して送信を呼び出す(または呼び出さない)ことと、
 * 送信中の例外を確実に握りつぶすことだけを確認する.
 */
class RunFailureAlerterTest extends TestCase {

	/**
	 * このテストで固定する「現在時刻」.
	 *
	 * @var string
	 */
	const NOW = '2026-01-15 00:00:00';

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * `_wpcv_test_added_actions`はここでは触らない(`feedback-shared-test-global-
	 * unset-hidden-by-suite`参照).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		unset(
			$GLOBALS['_wpcv_test_options'],
			$GLOBALS['_wpcv_test_wp_mail_calls'],
			$GLOBALS['_wpcv_test_wp_mail_return'],
			$GLOBALS['_wpcv_test_wp_mail_trigger_failed'],
			$GLOBALS['_wpcv_test_do_action_calls'],
			$GLOBALS['_wpcv_test_filters']['wpcv_alert_channels'],
			$GLOBALS['_wpcv_test_filters']['wpcv_alert_run_failure_streak']
		);
	}

	/**
	 * 固定時刻の`WPCV_Run_Repository`と、それを共有する`WPCV_Alert_Sender`・
	 * `WPCV_Run_Failure_Alerter`一式を作る.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb         フェイクwpdb.
	 * @param WPCV_Alert_Sender   $alert_sender 差し替える`WPCV_Alert_Sender`
	 *                                          (省略時は`$run_repository`を使って
	 *                                          実際に組み立てたものを使う).
	 * @return array{alerter: WPCV_Run_Failure_Alerter, run_repository: WPCV_Run_Repository}
	 */
	private function make_environment( WPCV_Test_Fake_WPDB $wpdb, ?WPCV_Alert_Sender $alert_sender = null ) {
		$now                    = static function () {
			return self::NOW;
		};
		$run_repository         = new WPCV_Run_Repository( $wpdb, $now );
		$target_run_repository  = new WPCV_Target_Run_Repository( $wpdb, $now );
		$finding_repository     = new WPCV_Finding_Repository( $wpdb );

		if ( null === $alert_sender ) {
			$alert_sender = new WPCV_Alert_Sender( $run_repository, $target_run_repository, $finding_repository, $now );
		}

		return array(
			'alerter'        => new WPCV_Run_Failure_Alerter( $run_repository, $alert_sender ),
			'run_repository' => $run_repository,
		);
	}

	/**
	 * 呼ばれると必ず例外を投げる `WPCV_Alert_Sender` のテストダブルを作る
	 * (`test_handle_swallows_exception_from_alert_sender()`・
	 * `test_mark_run_failed_is_unaffected_when_handler_throws()`共通).
	 *
	 * 親クラスのコンストラクタ(`WPCV_Run_Repository`等の依存を要求する)は
	 * 呼ばない ―― このダブルは`send_run_failure()`しか使わないため、依存を
	 * 用意する必要が無い.
	 *
	 * @return WPCV_Alert_Sender
	 */
	private function make_throwing_alert_sender() {
		return new class() extends WPCV_Alert_Sender {

			/**
			 * 親のコンストラクタを呼ばない no-op(クラスdocblock参照).
			 */
			public function __construct() {
				// 依存を持たないダブルのため、あえて何もしない.
			}

			/**
			 * テスト専用: 何もせず例外を投げる.
			 *
			 * @param int   $run_id      無視する.
			 * @param array $streak_runs 無視する.
			 * @return array{action: string} 到達しない(常に例外を投げる).
			 * @throws RuntimeException 常に投げる.
			 */
			public function send_run_failure( $run_id, array $streak_runs ) {
				throw new RuntimeException( 'boom' );
			}
		};
	}

	/**
	 * `wpcv_runs`の1行分を直接insertするヘルパー(`RunRepositoryTest`と同じ形).
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb      フェイクwpdb.
	 * @param array               $overrides 上書きするフィールド(`status`は必須).
	 * @return int insertした行のid.
	 */
	private function insert_run_row( WPCV_Test_Fake_WPDB $wpdb, array $overrides ) {
		$wpdb->insert(
			'wp_wpcv_runs',
			array_merge(
				array(
					'started_at'  => self::NOW,
					'run_trigger' => 'cron',
					'runner'      => 'sync',
				),
				$overrides
			)
		);

		return $wpdb->insert_id;
	}

	/**
	 * `$status`がsuccess/partialのときは何もしない(送信もDB更新も行わない)
	 * ことを確認する(§2.3の表の1行目.アラートは差分処理のalerting段階が送る).
	 *
	 * @return void
	 */
	public function test_handle_does_nothing_when_status_is_success_or_partial() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$run_id = $this->insert_run_row( $wpdb, array( 'status' => 'success' ) );
		$env    = $this->make_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );

		$env['alerter']->handle( $run_id, 'success' );

		$this->assertArrayNotHasKey( '_wpcv_test_wp_mail_calls', $GLOBALS );
		$this->assertNull( $wpdb->rows['wp_wpcv_runs'][ $run_id ]['alert_status'] ?? null );
	}

	/**
	 * 連続の長さが閾値未満のときは送らないことを確認する(§2.3の表.
	 * `L < N`).
	 *
	 * @return void
	 */
	public function test_handle_does_not_send_when_streak_is_below_threshold() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$this->insert_run_row( $wpdb, array( 'status' => 'success' ) );
		$run_id = $this->insert_run_row( $wpdb, array( 'status' => 'failed' ) );
		$env    = $this->make_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_run_failure_streak'][] = static function () {
			return 3;
		};

		$env['alerter']->handle( $run_id, 'failed' );

		$this->assertArrayNotHasKey( '_wpcv_test_wp_mail_calls', $GLOBALS );
		$this->assertNull( $wpdb->rows['wp_wpcv_runs'][ $run_id ]['alert_status'] ?? null );
	}

	/**
	 * 連続の長さが閾値ちょうどに達したら送信し、失敗したrun自身の行に
	 * `alert_status = sent`が記録されることを確認する(§2.3の表.`L = N`).
	 *
	 * @return void
	 */
	public function test_handle_sends_when_streak_reaches_threshold() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$this->insert_run_row( $wpdb, array( 'status' => 'success' ) );
		$this->insert_run_row( $wpdb, array( 'status' => 'failed' ) );
		$run_id = $this->insert_run_row( $wpdb, array( 'status' => 'failed' ) );
		$env    = $this->make_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_run_failure_streak'][] = static function () {
			return 2;
		};

		$env['alerter']->handle( $run_id, 'failed' );

		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_mail_calls'] );
		$this->assertStringContainsString( '2 runs failed in a row', $GLOBALS['_wpcv_test_wp_mail_calls'][0]['subject'] );
		$this->assertSame( 'sent', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['alert_status'] );
	}

	/**
	 * 既にこの連続で通知済み(閾値の位置以降に`alert_status = sent`のrunが
	 * ある)なら、閾値に達していても再送しないことを確認する(§2.3の表.
	 * 「通知済み」の分岐).
	 *
	 * @return void
	 */
	public function test_handle_does_not_resend_when_already_notified() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$this->insert_run_row( $wpdb, array( 'status' => 'success' ) );
		$this->insert_run_row( $wpdb, array( 'status' => 'failed' ) ); // 位置1.
		// 位置2(閾値ちょうどで最初に送ったrun): 送信成功済み.
		$this->insert_run_row(
			$wpdb,
			array(
				'status'       => 'failed',
				'alert_status' => 'sent',
			)
		);
		$run_id = $this->insert_run_row( $wpdb, array( 'status' => 'failed' ) ); // 位置3(今回).
		$env    = $this->make_environment( $wpdb );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_run_failure_streak'][] = static function () {
			return 2;
		};

		$env['alerter']->handle( $run_id, 'failed' );

		$this->assertArrayNotHasKey( '_wpcv_test_wp_mail_calls', $GLOBALS );
		$this->assertNull( $wpdb->rows['wp_wpcv_runs'][ $run_id ]['alert_status'] ?? null );
	}

	/**
	 * 送信中に例外が発生しても`handle()`の外へ漏れず、静かに終わることを
	 * 確認する(§3.4「送信は`try/catch ( Throwable )`で囲み、runの終端処理を
	 * 壊さない」).
	 *
	 * @return void
	 */
	public function test_handle_swallows_exception_from_alert_sender() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$this->insert_run_row( $wpdb, array( 'status' => 'success' ) );
		$run_id = $this->insert_run_row( $wpdb, array( 'status' => 'failed' ) );

		$env = $this->make_environment( $wpdb, $this->make_throwing_alert_sender() );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_run_failure_streak'][] = static function () {
			return 1;
		};

		$env['alerter']->handle( $run_id, 'failed' );

		$this->assertNull( $wpdb->rows['wp_wpcv_runs'][ $run_id ]['alert_status'] ?? null, '例外を投げた場合は通知済みにならない' );
	}

	/**
	 * `handle()`が投げた例外を`WPCV_Run_Repository::mark_run_failed()`まで
	 * 漏らさず、戻り値・run行の`status`/`notes`/`finished_at`が影響を受け
	 * ないことを確認する(実際に`wpcv_run_terminated`フックへ登録して統合的に
	 * 確認する.設計§6「送信の失敗でrunの終端処理を壊さない」の完了条件).
	 *
	 * @return void
	 */
	public function test_mark_run_failed_is_unaffected_when_handler_throws() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$env = $this->make_environment( $wpdb, $this->make_throwing_alert_sender() );

		WPCV_Settings::update_alert_to( 'ops@example.com' );
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_run_failure_streak'][] = static function () {
			return 1;
		};

		// `includes/runners/class-wpcv-run-failure-alerter.php`のrequire時点で
		// 登録済みの`array( 'WPCV_Plugin', 'handle_run_terminated' )`を外す ――
		// このテストファイルは`WPCV_Plugin`をrequireしないため(このテストは
		// `WPCV_Run_Failure_Alerter::handle()`単体の例外安全性だけを見たい)、
		// 外さないと`do_action()`がその登録を呼ぼうとして
		// 「クラスが見つからない」エラーになる(`_wpcv_test_added_actions`は
		// ファイル全体で1回だけ登録され、setUp()側で消せない共有グローバルの
		// ため.`feedback-shared-test-global-unset-hidden-by-suite`参照).
		remove_action( 'wpcv_run_terminated', array( 'WPCV_Plugin', 'handle_run_terminated' ), 10 );

		add_action(
			'wpcv_run_terminated',
			static function ( $run_id, $status ) use ( $env ) {
				$env['alerter']->handle( $run_id, $status );
			},
			10,
			2
		);

		$run_id = $env['run_repository']->reserve_run()['run_id'];

		$result = $env['run_repository']->mark_run_failed( $run_id, 'boom' );

		$this->assertTrue( $result, 'handle()内の例外がmark_run_failed()まで伝播してはならない' );

		$row = $wpdb->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( 'boom', $row['notes'] );
		$this->assertSame( self::NOW, $row['finished_at'] );
	}
}
