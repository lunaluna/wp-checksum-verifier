<?php
/**
 * PHPStan 用の WP-CLI スタブ.
 *
 * WP-CLI は Composer 依存ではなく(`wp` コマンドとしてグローバルインストールされる
 * ランタイム)、`WP_CLI` クラスは実際に `wp` 経由で実行されたときにしか存在しない
 * (`includes/cli/class-wpcv-cli-command.php` の `class_exists( 'WP_CLI' )` ガード
 * 参照)。szepeviktor/phpstan-wordpress は WP-CLI のスタブを含まないため、
 * 型情報だけをここで宣言する(`phpstan.neon.dist` の `scanFiles` から読み込み、
 * 解析対象そのものにはしない。実行されることは無い).
 *
 * @package WPChecksumVerifier
 */

/**
 * WP_CLI クラスの最小スタブ(このプラグインが呼ぶメソッドのみ).
 */
class WP_CLI {

	/**
	 * @param string          $name     コマンド名.
	 * @param callable|string $callable 実体.
	 * @param array           $args     追加オプション.
	 * @return void
	 */
	public static function add_command( $name, $callable, $args = array() ) {}

	/**
	 * @param string $message メッセージ.
	 * @return void
	 */
	public static function success( $message ) {}

	/**
	 * @param string|\WP_Error $message メッセージ.
	 * @param bool             $exit    終了するか.
	 * @return void
	 */
	public static function error( $message, $exit = true ) {}

	/**
	 * @param string $message メッセージ.
	 * @return void
	 */
	public static function line( $message = '' ) {}
}
