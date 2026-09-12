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
	 * `WPCV_Migrator::maybe_upgrade()` の戻り値(`false` はスキーマ更新を確認
	 * できなかったことを意味する。同メソッドのdocblock参照)を確認し、失敗時は
	 * 例外を投げる(v0.4.0コードレビューCR-05是正)。`maybe_upgrade()` 自身は
	 * `plugins_loaded`から毎リクエスト呼ばれるため例外を投げない設計にしたが、
	 * 有効化は一度きりの明示的な操作であり、WordPress自身が
	 * `register_activation_hook`実行中の致命的エラーを捕捉してプラグインを
	 * 自動的に無効化しエラーメッセージを表示する仕組みを持つため、ここで
	 * 例外化してもサイト全体を壊さず「有効化に失敗した」ことを利用者に伝えられる.
	 *
	 * @param bool $network_wide ネットワーク有効化かどうか(register_activation_hook が渡す).
	 *                            installation-level スキーマのため未使用.
	 * @return void
	 *
	 * @throws RuntimeException `WPCV_Migrator::maybe_upgrade()` がDBスキーマの
	 *                          作成・更新を確認できなかった場合.
	 */
	public static function activate( $network_wide = false ) {
		unset( $network_wide );

		if ( ! WPCV_Migrator::maybe_upgrade() ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Activator::activate() はDBスキーマの作成・更新を確認できませんでした(WPCV_DB_VERSION: %d)。データベースユーザーの権限(CREATE/ALTER/INDEX)を確認してください.',
						WPCV_DB_VERSION
					)
				)
			);
		}
	}
}
