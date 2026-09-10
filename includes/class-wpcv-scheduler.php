<?php
/**
 * WPCV_Scheduler クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 既定の自動実行経路(WP-Cron。v0.3 §Step6)。
 *
 * WPMAR のパターン(単発イベントの自己連鎖)を踏襲し `wp_schedule_event()`(定期
 * イベント)は使わない: 設定画面で実行時刻を変更したとき、`wp_schedule_event()` で
 * 登録した recurring イベントの時刻をその場で差し替える WP-Cron API は無く、結局
 * 一度 `wp_clear_scheduled_hook()` してから登録し直す必要がある。単発イベントの
 * 自己連鎖であれば、ハンドラの末尾でそのときの設定値から次回時刻を計算し直すだけで
 * 済み、設定変更時の再予約(`reschedule()`)と自己連鎖時の再予約が同じ計算ロジック
 * (`next_timestamp_after()`)を共有できる.
 *
 * stale run 検知(`WPCV_Repository::sweep_stale_running()`。v0.3 §Step5)は専用の
 * Cron を立てず、このハンドラの冒頭でオポチュニスティックに呼ぶ(WPMAR の
 * `sweep_stale_running()` と同じ「アクセスのたびに掃除する」方式).
 */
class WPCV_Scheduler {

	/**
	 * 自己連鎖する単発イベントの hook 名(定時実行専用).
	 *
	 * @var string
	 */
	const HOOK = 'wpcv_scheduled_verify';

	/**
	 * 「今すぐ実行」専用の単発イベントの hook 名(v0.3.1 §Step3).
	 *
	 * 定時実行(`self::HOOK`)と同じ hook・args で予約すると、WordPress の
	 * `wp_schedule_single_event()` が持つ「同一 hook・同一 args の予約が10分以内に
	 * 既にあれば拒否する」という重複判定(`wp-includes/cron.php` の
	 * `wp_schedule_single_event()` 実装参照。実測確認済み)に、定時イベントの
	 * 予約時刻の近くで「今すぐ実行」を押すと引っかかってしまう(プラン§P1
	 * 「「今すぐ実行」が定時イベントと衝突する」への対策)。hook を分ければ
	 * args が同じでも重複とみなされない.
	 *
	 * @var string
	 */
	const MANUAL_HOOK = 'wpcv_manual_verify';

	/**
	 * Stale 判定の閾値(分。`WPCV_Repository::sweep_stale_running()` に渡す).
	 *
	 * 【未実測】v0.3計画時点の仮値(「1アクション=1run全体」の想定所要時間より
	 * 十分長い値、という以上の根拠は無い)。実地検証(§14)で検証サイトの
	 * `wp wpcv run` の実測所要時間を計測し、その最大値に安全マージンを載せた値へ
	 * 見直すこと.
	 *
	 * @var int
	 */
	const STALE_THRESHOLD_MINUTES = 180;

	/**
	 * フックを登録する. `wp-checksum-verifier.php` から常に(WP-Cron が
	 * `DISABLE_WP_CRON` で無効化されている環境でも)呼ぶ. `wp_schedule_single_event()`
	 * 自体は `DISABLE_WP_CRON` の影響を受けず「予約」までは行われ、実際の発火だけが
	 * 抑止される設計のため(§6).
	 *
	 * 毎リクエストで `ensure_scheduled()` を呼び、定時イベントが欠落していれば
	 * 補完予約する(v0.3.1 §Step3。プラン§P1「有効化時にしか初回予約を補完しない
	 * ため、プラグイン更新やcron option消失から自己修復しない」への対策).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'handle_event' ) );
		add_action( self::MANUAL_HOOK, array( __CLASS__, 'handle_manual_event' ) );

		self::ensure_scheduled();
	}

	/**
	 * 有効化フックから呼ぶ. 既に予約済みなら何もしない(二重予約防止).
	 *
	 * @return void
	 */
	public static function activate() {
		self::ensure_scheduled();
	}

	/**
	 * 無効化フックから呼ぶ. 予約済みの定時・手動の両イベントを止める.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::MANUAL_HOOK );
	}

	/**
	 * 設定画面での実行時刻変更を反映する. 既存の予約の有無に関わらず、必ず
	 * 一度クリアしてから新しい設定値で予約し直す(`ensure_scheduled()` の
	 * 「未予約のときだけ予約する」二重予約防止とは目的が異なり、こちらは
	 * 「予約済みでも新しい時刻で上書きしたい」ため).
	 *
	 * @return void
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		self::schedule_next();
	}

	/**
	 * `self::HOOK` のハンドラ. WP-Cron から呼ばれる.
	 *
	 * 次回分の自己連鎖予約を最初に確保してから run を受け付ける(v0.3.1 §Step3。
	 * v0.3.0までは「stale sweep → enqueue → 次回予約」の順だったため、enqueue が
	 * fatal error やタイムアウトで異常終了すると次回予約に到達せず、以降の定時
	 * 実行が永久に途絶えていた。プラン§P1「Cronの次回予約が異常終了で途絶える」
	 * への対策). WP-Cron は発火した単発イベントを自動的に削除するため、
	 * `ensure_scheduled()` が呼ぶ `wp_next_scheduled()` は通常 false を返す.
	 *
	 * @return void
	 */
	public static function handle_event() {
		self::ensure_scheduled();

		self::run_verification( 'cron' );
	}

	/**
	 * `self::MANUAL_HOOK` のハンドラ. 「今すぐ実行」ボタンから予約された単発
	 * イベントとして WP-Cron から呼ばれる(v0.3.1 §Step3). 自己連鎖予約は
	 * 行わない(単発の手動実行のため。`handle_event()` との違いはそこのみ).
	 *
	 * @return void
	 */
	public static function handle_manual_event() {
		self::run_verification( 'manual' );
	}

	/**
	 * Stale run 検知 → 非同期実行の enqueue、の共通処理(`handle_event()` と
	 * `handle_manual_event()` で共有する).
	 *
	 * @param string $run_trigger `WPCV_Runner_Async::enqueue_run()` に渡す
	 *                            `'cron'|'manual'`.
	 * @return void
	 */
	private static function run_verification( $run_trigger ) {
		WPCV_Plugin::repository()->sweep_stale_running( self::STALE_THRESHOLD_MINUTES );

		WPCV_Runner_Async::enqueue_run( $run_trigger );
	}

	/**
	 * 定時イベントが未予約なら補完予約する(`init()`・`activate()`・
	 * `handle_event()` の3箇所で共有する自己修復ロジック. v0.3.1 §Step3).
	 *
	 * @return void
	 */
	private static function ensure_scheduled() {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			self::schedule_next();
		}
	}

	/**
	 * `WPCV_Settings` の実行時刻から次回の Unix timestamp を計算し、単発イベントを
	 * 予約する.
	 *
	 * マルチサイトでは main site 以外では何もしない(v0.3.1 §Step3「マルチサイト
	 * ではmain siteのcronへinstallationあたり1系列だけ登録する」)。`wpcv_runs`
	 * テーブル等が `base_prefix` ベースの installation-level であり(`WPCV_Migrator`
	 * の docblock 参照)、サイトごとに検証を繰り返す必要が無いため。`is_main_site()`
	 * は非マルチサイトでは常に真を返す(WordPress core の仕様)ため、この関数
	 * 単体でシングルサイト・マルチサイト両方の分岐を賄える.
	 *
	 * @return void
	 */
	private static function schedule_next() {
		if ( ! is_main_site() ) {
			return;
		}

		$run_time = WPCV_Settings::get_run_time();
		$next     = self::next_timestamp_after( time(), $run_time['hour'], $run_time['minute'] );

		wp_schedule_single_event( $next, self::HOOK );
	}

	/**
	 * `$now_timestamp` 以降で最初に訪れる `$hour:$minute`(UTC)の Unix timestamp を返す.
	 *
	 * 当日のその時刻がまだ来ていなければ当日、既に過ぎていれば翌日を返す
	 * (WP-Cron の単発イベントは過去時刻でもすぐ発火する仕様のため、常に未来の
	 * 時刻を返す必要がある).
	 *
	 * @param int $now_timestamp 基準時刻(Unix timestamp). テストで固定注入するため引数化.
	 * @param int $hour          時(UTC. 0-23).
	 * @param int $minute        分(UTC. 0-59).
	 * @return int
	 */
	public static function next_timestamp_after( $now_timestamp, $hour, $minute ) {
		$today_at_time = gmmktime(
			$hour,
			$minute,
			0,
			(int) gmdate( 'n', $now_timestamp ),
			(int) gmdate( 'j', $now_timestamp ),
			(int) gmdate( 'Y', $now_timestamp )
		);

		return ( $today_at_time > $now_timestamp ) ? $today_at_time : $today_at_time + DAY_IN_SECONDS;
	}
}
