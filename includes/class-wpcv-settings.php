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
 * 設定値(v0.3時点では WP-Cron の実行時刻のみ)の読み書きを集約する.
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
	 * 既定値.
	 *
	 * @return array{run_hour:int,run_minute:int}
	 */
	public static function defaults() {
		return array(
			'run_hour'   => self::DEFAULT_RUN_HOUR,
			'run_minute' => self::DEFAULT_RUN_MINUTE,
		);
	}

	/**
	 * 保存済みの設定値を既定値とマージして返す.
	 *
	 * @return array{run_hour:int,run_minute:int}
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
