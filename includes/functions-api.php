<?php
/**
 * Public API(§10)のグローバル関数. WPMAR 連携用.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wpcv_get_latest_findings' ) ) {
	/**
	 * 最新の検証結果を取得する.
	 *
	 * @param array $args {
	 *     絞り込み条件.
	 *
	 *     @type array $dimension           core|plugin|theme|muplugin.
	 *     @type array $status              modified|added|missing|unreadable.
	 *     @type array $severity            high|medium|low.
	 *     @type bool  $include_suppressed  既定 false.
	 *     @type bool  $include_closed      既定 false.
	 *     @type int   $limit               既定 0(無制限).
	 * }
	 * @return array findings 配列(§5.5 のスキーマに準拠).
	 */
	function wpcv_get_latest_findings( $args = array() ) {
		return WPCV_API::get_latest_findings( $args );
	}
}

if ( ! function_exists( 'wpcv_get_latest_run' ) ) {
	/**
	 * 最新の実行サマリを取得する.
	 *
	 * @return array|null run 行(status, finished_at, 各カウント).
	 */
	function wpcv_get_latest_run() {
		return WPCV_API::get_latest_run();
	}
}

if ( ! function_exists( 'wpcv_get_latest_target_runs' ) ) {
	/**
	 * 検証対象(target)単位の検証結果を取得する. unverifiable の理由を含む.
	 *
	 * @param array $args {
	 *     絞り込み条件.
	 *
	 *     @type array $dimension core|plugin|theme|muplugin.
	 *     @type array $status    success|unverifiable|failed|skipped|retried.
	 *     @type int   $limit     既定 0(無制限).
	 * }
	 * @return array target_runs 配列(§5.3 のスキーマに準拠).
	 */
	function wpcv_get_latest_target_runs( $args = array() ) {
		return WPCV_API::get_latest_target_runs( $args );
	}
}

if ( ! function_exists( 'wpcv_is_available' ) ) {
	/**
	 * プラグインが利用可能かを返す. WPMAR の依存チェック用.
	 *
	 * @return bool
	 */
	function wpcv_is_available() {
		return WPCV_API::is_available();
	}
}
