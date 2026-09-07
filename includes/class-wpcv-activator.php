<?php
/**
 * プラグイン有効化: スキーマ作成のエントリポイント.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 有効化フック(register_activation_hook)から呼ばれるエントリポイント.
 *
 * 検出結果(findings)等は installation-level(§5.6)であり blog 単位ではないため、
 * WPMAR のような「マルチサイトの各サイトをループしてテーブルを作る」処理は行わない。
 * ネットワーク全体で 1 セットのテーブルを作るだけでよい.
 */
class WPCV_Activator {

	/**
	 * 有効化時に呼ばれる. スキーマは installation-level で 1 セットのみのため、
	 * ネットワーク有効化かどうかで処理を分ける必要はない.
	 *
	 * @param bool $network_wide ネットワーク有効化かどうか(register_activation_hook が渡す).
	 *                            installation-level スキーマのため未使用.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		unset( $network_wide );

		WPCV_Migrator::maybe_upgrade();
	}
}
