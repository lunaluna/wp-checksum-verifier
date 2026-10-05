<?php
/**
 * WPCV_Retention_Cleaner のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-diff-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-generation-differ.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-suppression-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-github-client.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-github-mappings.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-retention-cleaner.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * Run の終端での保持期間の掃除(v0.9 §Step2. プラン §3.1.1 の I1〜I5)のテスト.
 *
 * 現在時刻は 2026-10-03 00:00:00 UTC に固定し、保持期間 3 か月なら境界は
 * 2026-07-03 00:00:00 になる.
 */
class RetentionCleanerTest extends TestCase {

	/**
	 * 固定の現在時刻.
	 */
	const NOW = '2026-10-03 00:00:00';

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset(
			$GLOBALS['_wpcv_test_is_multisite'],
			$GLOBALS['_wpcv_test_options'],
			$GLOBALS['_wpcv_test_site_options']
		);
	}

	/**
	 * 保持期間(月)を設定する.
	 *
	 * @param int $months 月数.
	 * @return void
	 */
	private static function set_retention( $months ) {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array( 'retention_months' => $months );
	}

	/**
	 * Run を1件入れる.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb        フェイク wpdb.
	 * @param string              $started_at  開始日時.
	 * @param string              $status      status.
	 * @param string|null         $diff_status diff_status.
	 * @return int run の id.
	 */
	private static function insert_run( WPCV_Test_Fake_WPDB $wpdb, $started_at, $status = 'success', $diff_status = 'done' ) {
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => $started_at,
				'status'      => $status,
				'diff_status' => $diff_status,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Target_run を1件入れる.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb      フェイク wpdb.
	 * @param int                 $run_id    run の id.
	 * @param string              $target_id target_id.
	 * @param string              $status    status.
	 * @return int target_run の id.
	 */
	private static function insert_target_run( WPCV_Test_Fake_WPDB $wpdb, $run_id, $target_id, $status = 'success' ) {
		$wpdb->insert(
			'wp_wpcv_target_runs',
			array(
				'run_id'    => $run_id,
				'target_id' => $target_id,
				'status'    => $status,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Finding を1件入れる.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb          フェイク wpdb.
	 * @param int                 $run_id        run の id.
	 * @param int                 $target_run_id target_run の id.
	 * @param string              $target_id     target_id.
	 * @param string              $key           finding_key.
	 * @param string|null         $notified_at   notified_at.
	 * @return int finding の id.
	 */
	private static function insert_finding( WPCV_Test_Fake_WPDB $wpdb, $run_id, $target_run_id, $target_id, $key, $notified_at = null ) {
		$wpdb->insert(
			'wp_wpcv_findings',
			array(
				'run_id'        => $run_id,
				'target_run_id' => $target_run_id,
				'target_id'     => $target_id,
				'finding_key'   => $key,
				'notified_at'   => $notified_at,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * クリーナーを組み立てる.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb フェイク wpdb.
	 * @return WPCV_Retention_Cleaner
	 */
	private static function make_cleaner( WPCV_Test_Fake_WPDB $wpdb ) {
		return new WPCV_Retention_Cleaner(
			new WPCV_Run_Repository( $wpdb ),
			new WPCV_Target_Run_Repository( $wpdb ),
			new WPCV_Finding_Repository( $wpdb ),
			new WPCV_Suppression_Repository( $wpdb ),
			static function () {
				return self::NOW;
			}
		);
	}

	/**
	 * テーブルに残っている id の一覧を返す.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb  フェイク wpdb.
	 * @param string              $table テーブル名(接頭辞なし).
	 * @return int[]
	 */
	private static function ids( WPCV_Test_Fake_WPDB $wpdb, $table ) {
		$ids = array_map( 'intval', array_keys( $wpdb->rows[ 'wp_wpcv_' . $table ] ?? array() ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * 明示的に 0(無期限)が保存されているときは何も消さないことを確認する(U5・0.10.0 の U9).
	 *
	 * @return void
	 */
	public function test_explicit_zero_keeps_everything() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$run  = self::insert_run( $wpdb, '2020-01-01 00:00:00' );
		$tr   = self::insert_target_run( $wpdb, $run, 'plugin:a' );
		self::insert_target_run( $wpdb, $run, 'plugin:a' );
		self::insert_finding( $wpdb, $run, $tr, 'plugin:a', 'k1' );

		self::set_retention( 0 );
		self::make_cleaner( $wpdb )->handle_run_terminated( 999, 'success' );

		$this->assertSame( array( $run ), self::ids( $wpdb, 'runs' ) );
		$this->assertCount( 2, $wpdb->rows['wp_wpcv_target_runs'] );
		$this->assertCount( 1, $wpdb->rows['wp_wpcv_findings'] );
	}

	/**
	 * 保持期間を保存していなければ既定(12 か月)で動き、期限切れの履歴が消えることを確認する(0.10.0 の U8・U9).
	 *
	 * 最新の照合結果(I1)は古くても残るので、同じ target の古い方の 1 件だけが消える.
	 *
	 * @return void
	 */
	public function test_default_applies_twelve_months_when_not_saved() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$run  = self::insert_run( $wpdb, '2020-01-01 00:00:00' );
		$tr   = self::insert_target_run( $wpdb, $run, 'plugin:a' );
		self::insert_target_run( $wpdb, $run, 'plugin:a' );
		self::insert_finding( $wpdb, $run, $tr, 'plugin:a', 'k1' );

		// 設定を保存していない = 既定(12 か月).
		self::make_cleaner( $wpdb )->handle_run_terminated( 999, 'success' );

		$this->assertCount( 1, $wpdb->rows['wp_wpcv_target_runs'] );
	}

	/**
	 * 選択肢以外の保存値(手動での書き換え等)は無期限として扱い、何も消さないことを確認する.
	 *
	 * @return void
	 */
	public function test_invalid_stored_value_is_treated_as_unlimited() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$run  = self::insert_run( $wpdb, '2020-01-01 00:00:00' );
		self::insert_target_run( $wpdb, $run, 'plugin:a' );
		self::set_retention( 5 );

		self::make_cleaner( $wpdb )->handle_run_terminated( 999, 'success' );

		$this->assertCount( 1, $wpdb->rows['wp_wpcv_target_runs'] );
	}

	/**
	 * 期限切れの古い世代は(stat の event や継続中の古い世代を含め)消え、
	 * I1(直近の success)・I2(その run)・I3(今終わった run の基準)は残ることを確認する.
	 *
	 * @return void
	 */
	public function test_expired_rows_are_deleted_but_latest_success_baseline_and_their_runs_are_kept() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$r_old1   = self::insert_run( $wpdb, '2026-05-01 00:00:00' );
		$r_old2   = self::insert_run( $wpdb, '2026-06-01 00:00:00' );
		$r_prev   = self::insert_run( $wpdb, '2026-10-01 00:00:00' );
		$r_recent = self::insert_run( $wpdb, '2026-10-02 00:00:00', 'success', 'pending' );

		// target A: 古い success が2件、1つ前の success(I3: 今終わった run の基準)、今回の success(I1).
		$a1    = self::insert_target_run( $wpdb, $r_old1, 'plugin:a' );
		$a2    = self::insert_target_run( $wpdb, $r_old2, 'plugin:a' );
		$a_prv = self::insert_target_run( $wpdb, $r_prev, 'plugin:a' );
		$a3    = self::insert_target_run( $wpdb, $r_recent, 'plugin:a' );

		// target B: success が古い1件だけ(I1 は古くても残る).
		$b1 = self::insert_target_run( $wpdb, $r_old1, 'plugin:b' );

		// 未終端の finding(ended_in_run_id が NULL)でも、古い世代なら消える.
		$f_old_a1 = self::insert_finding( $wpdb, $r_old1, $a1, 'plugin:a', 'k1' );
		$f_old_a2 = self::insert_finding( $wpdb, $r_old2, $a2, 'plugin:a', 'k1' );
		$f_prv_a  = self::insert_finding( $wpdb, $r_prev, $a_prv, 'plugin:a', 'k1' );
		$f_new_a3 = self::insert_finding( $wpdb, $r_recent, $a3, 'plugin:a', 'k1' );
		$f_b1     = self::insert_finding( $wpdb, $r_old1, $b1, 'plugin:b', 'k9' );

		self::set_retention( 3 );
		self::make_cleaner( $wpdb )->handle_run_terminated( $r_recent, 'success' );

		$this->assertEqualsCanonicalizing( array( $a_prv, $a3, $b1 ), self::ids( $wpdb, 'target_runs' ) );
		$this->assertEqualsCanonicalizing( array( $f_prv_a, $f_new_a3, $f_b1 ), self::ids( $wpdb, 'findings' ) );
		// r_old1 は B の基準(I1)が属するので残る. r_old2 は子が無くなったので消える.
		$this->assertEqualsCanonicalizing( array( $r_old1, $r_prev, $r_recent ), self::ids( $wpdb, 'runs' ) );
		$this->assertNotContains( $f_old_a1, self::ids( $wpdb, 'findings' ) );
		$this->assertNotContains( $f_old_a2, self::ids( $wpdb, 'findings' ) );
	}

	/**
	 * 期限内の行は消えないことを確認する(境界は「今 - 3 か月」).
	 *
	 * @return void
	 */
	public function test_rows_within_the_period_are_kept() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$r_in     = self::insert_run( $wpdb, '2026-07-03 00:00:00' ); // 境界ちょうど = 期限内.
		$r_recent = self::insert_run( $wpdb, '2026-10-02 00:00:00' );

		$t1 = self::insert_target_run( $wpdb, $r_in, 'plugin:a' );
		$t2 = self::insert_target_run( $wpdb, $r_recent, 'plugin:a' );

		self::set_retention( 3 );
		self::make_cleaner( $wpdb )->handle_run_terminated( $r_recent, 'success' );

		$this->assertSame( array( $t1, $t2 ), self::ids( $wpdb, 'target_runs' ) );
		$this->assertSame( array( $r_in, $r_recent ), self::ids( $wpdb, 'runs' ) );
	}

	/**
	 * I4: `notified_at` があり、同じキーが直近の success にまだある finding は残る(その target_run も残る).
	 * キーが無くなった finding と、`notified_at` の無い finding は消える.
	 *
	 * @return void
	 */
	public function test_notified_finding_still_live_is_kept_and_others_are_deleted() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$r_old    = self::insert_run( $wpdb, '2026-05-01 00:00:00' );
		$r_prev   = self::insert_run( $wpdb, '2026-10-01 00:00:00' );
		$r_recent = self::insert_run( $wpdb, '2026-10-02 00:00:00', 'success', 'pending' );

		$old    = self::insert_target_run( $wpdb, $r_old, 'plugin:a' );
		$prev   = self::insert_target_run( $wpdb, $r_prev, 'plugin:a' );
		$latest = self::insert_target_run( $wpdb, $r_recent, 'plugin:a' );

		$kept_note = self::insert_finding( $wpdb, $r_old, $old, 'plugin:a', 'live', '2026-05-01 03:10:00' );
		self::insert_finding( $wpdb, $r_old, $old, 'plugin:a', 'gone', '2026-05-01 03:10:00' );
		self::insert_finding( $wpdb, $r_old, $old, 'plugin:a', 'live-but-not-notified' );
		$latest_finding = self::insert_finding( $wpdb, $r_recent, $latest, 'plugin:a', 'live' );

		self::set_retention( 3 );
		self::make_cleaner( $wpdb )->handle_run_terminated( $r_recent, 'success' );

		$this->assertEqualsCanonicalizing( array( $kept_note, $latest_finding ), self::ids( $wpdb, 'findings' ) );
		// 通知の記録の finding が指す target_run と、その run は残る.
		$this->assertEqualsCanonicalizing( array( $old, $prev, $latest ), self::ids( $wpdb, 'target_runs' ) );
		$this->assertEqualsCanonicalizing( array( $r_old, $r_prev, $r_recent ), self::ids( $wpdb, 'runs' ) );
	}

	/**
	 * I4: 同じキーが直近の success にもう無ければ、`notified_at` があっても消える(誤通知の元にならない).
	 *
	 * @return void
	 */
	public function test_notified_finding_whose_key_is_gone_is_deleted() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$r_old    = self::insert_run( $wpdb, '2026-05-01 00:00:00' );
		$r_prev   = self::insert_run( $wpdb, '2026-10-01 00:00:00' );
		$r_recent = self::insert_run( $wpdb, '2026-10-02 00:00:00', 'success', 'pending' );

		$old    = self::insert_target_run( $wpdb, $r_old, 'plugin:a' );
		$prev   = self::insert_target_run( $wpdb, $r_prev, 'plugin:a' );
		$latest = self::insert_target_run( $wpdb, $r_recent, 'plugin:a' );
		self::insert_finding( $wpdb, $r_old, $old, 'plugin:a', 'gone', '2026-05-01 03:10:00' );

		self::set_retention( 3 );
		self::make_cleaner( $wpdb )->handle_run_terminated( $r_recent, 'success' );

		$this->assertSame( array(), self::ids( $wpdb, 'findings' ) );
		$this->assertEqualsCanonicalizing( array( $prev, $latest ), self::ids( $wpdb, 'target_runs' ) );
		$this->assertEqualsCanonicalizing( array( $r_prev, $r_recent ), self::ids( $wpdb, 'runs' ) );
	}

	/**
	 * I3: 実行中の run と、差分処理前の run は、古くても、その target_run・findings とともに消えない.
	 *
	 * @return void
	 */
	public function test_unfinished_runs_are_kept() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$r_running = self::insert_run( $wpdb, '2026-05-01 00:00:00', 'running', null );
		$r_pending = self::insert_run( $wpdb, '2026-05-02 00:00:00', 'partial', 'pending' );
		$r_done    = self::insert_run( $wpdb, '2026-05-03 00:00:00', 'partial', 'done' );
		$r_recent  = self::insert_run( $wpdb, '2026-10-02 00:00:00' );

		$t_running = self::insert_target_run( $wpdb, $r_running, 'plugin:a', 'running' );
		$t_pending = self::insert_target_run( $wpdb, $r_pending, 'plugin:b', 'skipped' );
		self::insert_target_run( $wpdb, $r_done, 'plugin:c', 'skipped' );
		self::insert_target_run( $wpdb, $r_recent, 'plugin:a' );
		$f_running = self::insert_finding( $wpdb, $r_running, $t_running, 'plugin:a', 'k1' );

		self::set_retention( 3 );
		self::make_cleaner( $wpdb )->handle_run_terminated( $r_recent, 'success' );

		$this->assertContains( $t_running, self::ids( $wpdb, 'target_runs' ) );
		$this->assertContains( $t_pending, self::ids( $wpdb, 'target_runs' ) );
		$this->assertContains( $f_running, self::ids( $wpdb, 'findings' ) );
		$this->assertContains( $r_running, self::ids( $wpdb, 'runs' ) );
		$this->assertContains( $r_pending, self::ids( $wpdb, 'runs' ) );
		// 差分処理が終わった古い run は消える.
		$this->assertNotContains( $r_done, self::ids( $wpdb, 'runs' ) );
	}

	/**
	 * I3: 今終わった run(差分処理の前に `wpcv_run_terminated` が発火する)が基準にする、
	 * 1つ前の success は、古くても消えない.
	 *
	 * @return void
	 */
	public function test_baseline_of_the_terminated_run_is_kept() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$r_old        = self::insert_run( $wpdb, '2026-05-01 00:00:00' );
		$r_older_skip = self::insert_run( $wpdb, '2026-05-02 00:00:00' );
		$r_terminated = self::insert_run( $wpdb, '2026-10-02 00:00:00', 'success', 'pending' );

		// target D: 半年近く前の success のあと、今終わった run で success になった.
		$baseline = self::insert_target_run( $wpdb, $r_old, 'plugin:d' );
		$stale    = self::insert_target_run( $wpdb, $r_older_skip, 'plugin:d', 'unverifiable' );
		$newest   = self::insert_target_run( $wpdb, $r_terminated, 'plugin:d' );

		self::set_retention( 3 );
		self::make_cleaner( $wpdb )->handle_run_terminated( $r_terminated, 'success' );

		// 基準(baseline)は I3 で残り、差分処理が読める.古い unverifiable は消える.
		$this->assertSame( array( $baseline, $newest ), self::ids( $wpdb, 'target_runs' ) );
		$this->assertNotContains( $stale, self::ids( $wpdb, 'target_runs' ) );
		$this->assertContains( $r_old, self::ids( $wpdb, 'runs' ) );
	}

	/**
	 * I5: 失効から期限を過ぎた suppression は消え、有効なもの・失効が新しいものは残る.
	 *
	 * @return void
	 */
	public function test_expired_suppressions_are_deleted_and_active_ones_are_kept() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$wpdb->insert( 'wp_wpcv_suppressions', array( 'type' => 'exclude_path', 'expired_at' => '2026-05-01 00:00:00' ) );
		$wpdb->insert( 'wp_wpcv_suppressions', array( 'type' => 'exclude_path', 'expired_at' => null ) );
		$wpdb->insert( 'wp_wpcv_suppressions', array( 'type' => 'exclude_path', 'expired_at' => '2026-09-01 00:00:00' ) );

		self::set_retention( 3 );
		self::make_cleaner( $wpdb )->handle_run_terminated( 1, 'success' );

		$this->assertSame( array( 2, 3 ), self::ids( $wpdb, 'suppressions' ) );
	}

	/**
	 * 期限切れの run が無ければ何も消えない(境界が引けない場合).
	 *
	 * @return void
	 */
	public function test_nothing_is_deleted_when_no_run_is_expired() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$run  = self::insert_run( $wpdb, '2026-10-02 00:00:00' );
		self::insert_target_run( $wpdb, $run, 'plugin:a' );

		self::set_retention( 3 );
		self::make_cleaner( $wpdb )->handle_run_terminated( $run, 'success' );

		$this->assertCount( 1, $wpdb->rows['wp_wpcv_target_runs'] );
		$this->assertCount( 1, $wpdb->rows['wp_wpcv_runs'] );
	}

	/**
	 * 1回の呼び出しで消す target_run の数には上限があり、残りは次の呼び出しで消えることを確認する.
	 *
	 * @return void
	 */
	public function test_deletion_is_capped_per_call_and_finishes_over_calls() {
		$wpdb     = new WPCV_Test_Fake_WPDB();
		$r_old    = self::insert_run( $wpdb, '2026-05-01 00:00:00' );
		$r_recent = self::insert_run( $wpdb, '2026-10-02 00:00:00', 'success', 'pending' );
		$total    = WPCV_Retention_Cleaner::MAX_TARGET_RUNS_PER_CALL + 3;
		$last_old = 0;

		// 同じ target の success の古い世代を並べる(最後の1件は I3: 今終わった run の基準として残る).
		for ( $i = 0; $i < $total; $i++ ) {
			$last_old = self::insert_target_run( $wpdb, $r_old, 'plugin:a' );
		}

		$latest = self::insert_target_run( $wpdb, $r_recent, 'plugin:a' );

		self::set_retention( 3 );
		$cleaner = self::make_cleaner( $wpdb );

		$cleaner->handle_run_terminated( $r_recent, 'success' );
		// 消せる古い世代は total - 1 件. 上限の件数だけ消え、残りは次の呼び出しへ.
		$this->assertCount( ( $total - 1 - WPCV_Retention_Cleaner::MAX_TARGET_RUNS_PER_CALL ) + 2, $wpdb->rows['wp_wpcv_target_runs'] );

		$cleaner->handle_run_terminated( $r_recent, 'success' );
		$this->assertEqualsCanonicalizing( array( $last_old, $latest ), self::ids( $wpdb, 'target_runs' ) );
		// 基準が属する r_old は残る.
		$this->assertEqualsCanonicalizing( array( $r_old, $r_recent ), self::ids( $wpdb, 'runs' ) );
	}

	/**
	 * 同じキーを通知した期限切れの行が複数あっても、残るのは最新の1行だけで、
	 * 再送抑制が読む `MAX(notified_at)` は掃除の前後で変わらない(v0.9.1).
	 *
	 * @return void
	 */
	public function test_only_the_newest_notification_per_key_is_kept() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$r1       = self::insert_run( $wpdb, '2026-05-01 00:00:00' );
		$r2       = self::insert_run( $wpdb, '2026-05-02 00:00:00' );
		$r3       = self::insert_run( $wpdb, '2026-05-03 00:00:00' );
		$r_prev   = self::insert_run( $wpdb, '2026-10-01 00:00:00' );
		$r_recent = self::insert_run( $wpdb, '2026-10-02 00:00:00', 'success', 'pending' );

		$tr1    = self::insert_target_run( $wpdb, $r1, 'plugin:a' );
		$tr2    = self::insert_target_run( $wpdb, $r2, 'plugin:a' );
		$tr3    = self::insert_target_run( $wpdb, $r3, 'plugin:a' );
		$prev   = self::insert_target_run( $wpdb, $r_prev, 'plugin:a' );
		$latest = self::insert_target_run( $wpdb, $r_recent, 'plugin:a' );

		self::insert_finding( $wpdb, $r1, $tr1, 'plugin:a', 'k', '2026-05-01 03:10:00' );
		self::insert_finding( $wpdb, $r2, $tr2, 'plugin:a', 'k', '2026-05-02 03:10:00' );
		$f3      = self::insert_finding( $wpdb, $r3, $tr3, 'plugin:a', 'k', '2026-05-03 03:10:00' );
		$f_prev  = self::insert_finding( $wpdb, $r_prev, $prev, 'plugin:a', 'k' );
		$f_final = self::insert_finding( $wpdb, $r_recent, $latest, 'plugin:a', 'k' );

		$finding_repository = new WPCV_Finding_Repository( $wpdb );
		$before             = $finding_repository->find_last_notified_at_by_keys( array( 'k' ), 0 );

		self::set_retention( 3 );
		self::make_cleaner( $wpdb )->handle_run_terminated( $r_recent, 'success' );

		$this->assertEqualsCanonicalizing( array( $f3, $f_prev, $f_final ), self::ids( $wpdb, 'findings' ) );
		$this->assertEqualsCanonicalizing( array( $tr3, $prev, $latest ), self::ids( $wpdb, 'target_runs' ) );
		$this->assertSame( $before, $finding_repository->find_last_notified_at_by_keys( array( 'k' ), 0 ) );
	}

	/**
	 * 期限内の run により新しい通知がある場合、期限切れ側の同じキーの通知済み行は残らない(v0.9.1).
	 *
	 * @return void
	 */
	public function test_expired_notification_is_dropped_when_a_newer_one_is_within_the_period() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$r_old    = self::insert_run( $wpdb, '2026-05-01 00:00:00' );
		$r_in     = self::insert_run( $wpdb, '2026-09-01 00:00:00' );
		$r_prev   = self::insert_run( $wpdb, '2026-10-01 00:00:00' );
		$r_recent = self::insert_run( $wpdb, '2026-10-02 00:00:00', 'success', 'pending' );

		$old    = self::insert_target_run( $wpdb, $r_old, 'plugin:a' );
		$in     = self::insert_target_run( $wpdb, $r_in, 'plugin:a' );
		$prev   = self::insert_target_run( $wpdb, $r_prev, 'plugin:a' );
		$latest = self::insert_target_run( $wpdb, $r_recent, 'plugin:a' );

		self::insert_finding( $wpdb, $r_old, $old, 'plugin:a', 'k', '2026-05-01 03:10:00' );
		$f_in = self::insert_finding( $wpdb, $r_in, $in, 'plugin:a', 'k', '2026-09-01 03:10:00' );
		self::insert_finding( $wpdb, $r_recent, $latest, 'plugin:a', 'k' );

		self::set_retention( 3 );
		self::make_cleaner( $wpdb )->handle_run_terminated( $r_recent, 'success' );

		$this->assertContains( $f_in, self::ids( $wpdb, 'findings' ) );
		$this->assertNotContains( $old, self::ids( $wpdb, 'target_runs' ) );
		$this->assertCount( 2, $wpdb->rows['wp_wpcv_findings'] );
	}

	/**
	 * 同じ日時の通知が2つあっても、どちらも消えることはなく、1つだけ残る(v0.9.1).
	 *
	 * @return void
	 */
	public function test_equal_notification_times_keep_exactly_one() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		$r1       = self::insert_run( $wpdb, '2026-05-01 00:00:00' );
		$r2       = self::insert_run( $wpdb, '2026-05-02 00:00:00' );
		$r_prev   = self::insert_run( $wpdb, '2026-10-01 00:00:00' );
		$r_recent = self::insert_run( $wpdb, '2026-10-02 00:00:00', 'success', 'pending' );

		$tr1    = self::insert_target_run( $wpdb, $r1, 'plugin:a' );
		$tr2    = self::insert_target_run( $wpdb, $r2, 'plugin:a' );
		$prev   = self::insert_target_run( $wpdb, $r_prev, 'plugin:a' );
		$latest = self::insert_target_run( $wpdb, $r_recent, 'plugin:a' );

		self::insert_finding( $wpdb, $r1, $tr1, 'plugin:a', 'k', '2026-05-01 03:10:00' );
		$f2 = self::insert_finding( $wpdb, $r2, $tr2, 'plugin:a', 'k', '2026-05-01 03:10:00' );
		self::insert_finding( $wpdb, $r_recent, $latest, 'plugin:a', 'k' );

		self::set_retention( 3 );
		self::make_cleaner( $wpdb )->handle_run_terminated( $r_recent, 'success' );

		$this->assertContains( $f2, self::ids( $wpdb, 'findings' ) );
		$this->assertCount( 2, $wpdb->rows['wp_wpcv_findings'] );
		$this->assertEqualsCanonicalizing( array( $tr2, $prev, $latest ), self::ids( $wpdb, 'target_runs' ) );
	}

	/**
	 * 何も消さずに残した target_run が上限の件数以上先頭に並んでいても、その後ろの
	 * 期限切れの target_run が1回の呼び出しで消える(v0.9.1. 以前は上限に数えて停滞した).
	 *
	 * @return void
	 */
	public function test_kept_target_runs_do_not_block_later_expired_rows() {
		$wpdb     = new WPCV_Test_Fake_WPDB();
		$r_old    = self::insert_run( $wpdb, '2026-05-01 00:00:00' );
		$r_recent = self::insert_run( $wpdb, '2026-10-02 00:00:00', 'success', 'pending' );
		$kept     = WPCV_Retention_Cleaner::MAX_TARGET_RUNS_PER_CALL + 2;

		// 先頭: キーがそれぞれ別で、いまも続いている通知済みの finding を持つ target_run(残る).
		for ( $i = 0; $i < $kept; $i++ ) {
			$tr = self::insert_target_run( $wpdb, $r_old, 'plugin:a' );
			self::insert_finding( $wpdb, $r_old, $tr, 'plugin:a', 'key-' . $i, '2026-05-01 03:10:00' );
		}

		// その後ろ: 消せる期限切れの target_run.
		$deletable = self::insert_target_run( $wpdb, $r_old, 'plugin:a' );
		// 最後の1件は I3(今終わった run の基準)として残る.
		self::insert_target_run( $wpdb, $r_old, 'plugin:a' );

		$latest = self::insert_target_run( $wpdb, $r_recent, 'plugin:a' );

		for ( $i = 0; $i < $kept; $i++ ) {
			self::insert_finding( $wpdb, $r_recent, $latest, 'plugin:a', 'key-' . $i );
		}

		self::set_retention( 3 );
		self::make_cleaner( $wpdb )->handle_run_terminated( $r_recent, 'success' );

		$this->assertNotContains( $deletable, self::ids( $wpdb, 'target_runs' ) );
		// 残す行は消えていない.
		$this->assertCount( $kept + 2, $wpdb->rows['wp_wpcv_target_runs'] );
	}

	/**
	 * 掃除中の例外(DB エラー)を呼び出し元へ伝えない(run の確定を妨げない).
	 *
	 * @return void
	 */
	public function test_delete_failure_does_not_propagate() {
		$wpdb     = new WPCV_Test_Fake_WPDB();
		$r_old    = self::insert_run( $wpdb, '2026-05-01 00:00:00' );
		$r_recent = self::insert_run( $wpdb, '2026-10-02 00:00:00' );
		self::insert_target_run( $wpdb, $r_old, 'plugin:a' );
		self::insert_target_run( $wpdb, $r_recent, 'plugin:a' );

		self::set_retention( 3 );
		$wpdb->delete_should_fail = true;

		self::make_cleaner( $wpdb )->handle_run_terminated( $r_recent, 'success' );

		$this->assertCount( 2, $wpdb->rows['wp_wpcv_target_runs'] );
	}
}
