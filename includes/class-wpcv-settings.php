<?php
/**
 * WPCV_Settings クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定値(WP-Cron の実行時刻・外部HTTPモードの時間予算)の読み書きを集約する.
 *
 * REST時間予算の設定(v0.3 §Step8で追加)はv0.3.1 §Step4で廃止した(RESTが
 * オポチュニスティックなキュー消化をやめ同期専用になったため、`max_execution_time`
 * にクランプする時間予算という概念自体が不要になった。`WPCV_Rest_Run_Controller`
 * のクラス docblock 参照)。既存の option に `rest_time_budget_seconds` キーが
 * 残っていても読み捨てるだけで migration は行わない(§Step4のプラン記載通り).
 *
 * v0.4.0 §Step6で `external_http_time_budget_seconds` を新設した。旧
 * `rest_time_budget_seconds`(global AS runnerをオポチュニスティックに走らせる
 * 時間予算)とはキー名を変え、読み捨て対象のキーと混同しないようにしている。
 * こちらはWPCV自身のchunk dispatcherだけを繰り返す予算であり、global AS runnerは
 * 一切呼ばない(`WPCV_Rest_Run_Controller` のクラス docblock 参照).
 *
 * 検出結果等(§5.6)と同様 installation-level のデータであるため、マルチサイトでは
 * ネットワーク全体で1つの設定を `wp_sitemeta`(`get_site_option`/`update_site_option`)
 * に、単一サイトでは `wp_options`(`get_option`/`update_option`)に保存する。この
 * 分岐は `WPCV_Page_Settings::required_capability()` の `is_multisite()` 分岐と
 * 同じ方針(§11).
 */
class WPCV_Settings {

	/**
	 * オプション名(`wp_options`/`wp_sitemeta` 共通).
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wpcv_settings';

	/**
	 * 既定の実行時(UTC).
	 *
	 * 計画時点(v0.3)でユーザーと確認済みの仮値(実装セッションへの申し送り参照)。
	 * 実地運用でのアクセス傾向(負荷の低い時間帯)を見て見直す余地がある.
	 *
	 * @var int
	 */
	const DEFAULT_RUN_HOUR = 3;

	/**
	 * 既定の実行分(UTC).
	 *
	 * @var int
	 */
	const DEFAULT_RUN_MINUTE = 0;

	/**
	 * 外部HTTPモード(v0.4.0 §Step6。`WPCV_Rest_Run_Controller`)がdispatcherを
	 * 繰り返す時間予算の既定値(秒).
	 *
	 * 未実測: 暫定値。共有ホスティングの `max_execution_time`(30〜60秒。
	 * `WPCV_Chunk_Dispatcher::DEFAULT_BUDGET_MAX_SECONDS` のdocblock参照)より
	 * 十分短く取り、chunk 1回分の処理(既定20秒)がちょうど1回分は収まりつつ、
	 * レスポンス組み立て・DB書き込みのオーバーヘッド分の余裕を残す値として
	 * 20秒を仮置きする。v0.3.1 §Step4で廃止した `rest_time_budget_seconds`
	 * (global AS runnerをオポチュニスティックに走らせる時間予算)とは意味が
	 * 異なる(こちらはWPCV自身のdispatcherだけをループさせる予算. 廃止の経緯は
	 * このクラスの docblock 参照).
	 *
	 * @var int
	 */
	const DEFAULT_EXTERNAL_HTTP_TIME_BUDGET_SECONDS = 20;

	/**
	 * Strict mode(v0.4.0 §Step8)の既定値.
	 *
	 * 既定は false(soft change扱い)。`readme.txt`/`readme.md` の差分は
	 * 実運用上ほぼ無害な変更(翻訳・changelog更新等)であることが多く、
	 * 既定で通常findingとして毎回目に入るとノイズになるため
	 * `WPCV_Suppression_Matcher::SOFT_CHANGE_REASON` として抑制する。
	 * 厳密に全差分を検出したい運用者向けに strict mode で無効化できるようにする.
	 *
	 * @var bool
	 */
	const DEFAULT_STRICT_MODE = false;

	/**
	 * 既定値.
	 *
	 * @return array{run_hour:int,run_minute:int,external_http_time_budget_seconds:int,strict_mode:bool}
	 */
	public static function defaults() {
		return array(
			'run_hour'                          => self::DEFAULT_RUN_HOUR,
			'run_minute'                        => self::DEFAULT_RUN_MINUTE,
			'external_http_time_budget_seconds' => self::DEFAULT_EXTERNAL_HTTP_TIME_BUDGET_SECONDS,
			'strict_mode'                       => self::DEFAULT_STRICT_MODE,
		);
	}

	/**
	 * 保存済みの設定値を既定値とマージして返す.
	 *
	 * @return array{run_hour:int,run_minute:int,external_http_time_budget_seconds:int,strict_mode:bool}
	 */
	public static function get_all() {
		$stored = self::read_option();

		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * 実行時刻(UTC)を返す.
	 *
	 * @return array{hour:int,minute:int}
	 */
	public static function get_run_time() {
		$settings = self::get_all();

		return array(
			'hour'   => (int) $settings['run_hour'],
			'minute' => (int) $settings['run_minute'],
		);
	}

	/**
	 * 実行時刻(UTC)を保存する.
	 *
	 * @param int $hour   時. 範囲外(0-23 外)は clamp する.
	 * @param int $minute 分. 範囲外(0-59 外)は clamp する.
	 * @return bool `update_option()`/`update_site_option()` の戻り値.
	 */
	public static function update_run_time( $hour, $minute ) {
		$settings = self::get_all();

		$settings['run_hour']   = self::clamp_int( $hour, 0, 23 );
		$settings['run_minute'] = self::clamp_int( $minute, 0, 59 );

		return self::write_option( $settings );
	}

	/**
	 * 外部HTTPモード(v0.4.0 §Step6)の時間予算(秒)を返す.
	 *
	 * @return int
	 */
	public static function get_external_http_time_budget_seconds() {
		$settings = self::get_all();

		return (int) $settings['external_http_time_budget_seconds'];
	}

	/**
	 * 外部HTTPモードの時間予算(秒)を保存する.
	 *
	 * 上限を55秒にclampするのは、共有ホスティングで典型的な `max_execution_time`
	 * (30〜60秒。`DEFAULT_EXTERNAL_HTTP_TIME_BUDGET_SECONDS` のdocblock参照)の
	 * 下限側に対しても、レスポンス組み立てのオーバーヘッド分の余裕を必ず残すため
	 * (未実測: この上限値自体も暫定).
	 *
	 * @param int $seconds 時間予算(秒). 範囲外(5-55 外)は clamp する.
	 * @return bool `update_option()`/`update_site_option()` の戻り値.
	 */
	public static function update_external_http_time_budget_seconds( $seconds ) {
		$settings = self::get_all();

		$settings['external_http_time_budget_seconds'] = self::clamp_int( $seconds, 5, 55 );

		return self::write_option( $settings );
	}

	/**
	 * Strict mode(v0.4.0 §Step8)が有効かどうかを返す.
	 *
	 * @return bool
	 */
	public static function get_strict_mode() {
		$settings = self::get_all();

		return (bool) $settings['strict_mode'];
	}

	/**
	 * Strict modeの有効・無効を保存する.
	 *
	 * @param bool $enabled true で有効化.
	 * @return bool `update_option()`/`update_site_option()` の戻り値.
	 */
	public static function update_strict_mode( $enabled ) {
		$settings = self::get_all();

		$settings['strict_mode'] = (bool) $enabled;

		return self::write_option( $settings );
	}

	/**
	 * 保存済みの生値を読み取る(`wp_parse_args()` によるマージ前).
	 *
	 * @return mixed
	 */
	private static function read_option() {
		return is_multisite()
			? get_site_option( self::OPTION_NAME, array() )
			: get_option( self::OPTION_NAME, array() );
	}

	/**
	 * 設定値を保存する.
	 *
	 * @param array $settings 保存する設定値の配列.
	 * @return bool
	 */
	private static function write_option( array $settings ) {
		return is_multisite()
			? update_site_option( self::OPTION_NAME, $settings )
			: update_option( self::OPTION_NAME, $settings );
	}

	/**
	 * 値を `$min`-`$max` の範囲に収める.
	 *
	 * @param mixed $value 入力値.
	 * @param int   $min   最小値(含む).
	 * @param int   $max   最大値(含む).
	 * @return int
	 */
	private static function clamp_int( $value, $min, $max ) {
		$value = (int) $value;

		if ( $value < $min ) {
			return $min;
		}

		if ( $value > $max ) {
			return $max;
		}

		return $value;
	}
}
