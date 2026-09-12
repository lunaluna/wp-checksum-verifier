<?php
/**
 * WPCV_Suppression_Type クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_suppressions.type` の定数(v0.4.0 §Step8。プラン§7の抑制3層).
 *
 * 優先順位は `EXCLUDE_TARGET` -> `EXCLUDE_PATH` -> `ALLOWLIST_HASH` -> 通常finding
 * の順(`WPCV_Suppression_Matcher` のクラス docblock 参照)。`EXCLUDE_TARGET` は
 * `WPCV_Run_Planner::plan()` が target 列挙時点で適用する(検証自体を行わず
 * `WPCV_Target_Status::SKIPPED` にする)ため、`WPCV_Suppression_Matcher` が扱うのは
 * `EXCLUDE_PATH`/`ALLOWLIST_HASH` の2層のみ.
 */
class WPCV_Suppression_Type {

	/** Target(dimension+slug)単位で検証自体をスキップする. */
	const EXCLUDE_TARGET = 'exclude_target';

	/** Target内の特定パス(glob)のfindingを抑制する. */
	const EXCLUDE_PATH = 'exclude_path';

	/** 特定パス・特定hashの組み合わせをユーザーが承認する. */
	const ALLOWLIST_HASH = 'allowlist_hash';

	/**
	 * 全 type の一覧.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::EXCLUDE_TARGET, self::EXCLUDE_PATH, self::ALLOWLIST_HASH );
	}

	/**
	 * 指定した文字列が既知の type かどうかを判定する.
	 *
	 * @param string $type 判定対象.
	 * @return bool
	 */
	public static function is_valid( $type ) {
		return in_array( $type, self::all(), true );
	}
}
