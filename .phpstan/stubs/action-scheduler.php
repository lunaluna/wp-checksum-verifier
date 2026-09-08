<?php
/**
 * PHPStan 用の Action Scheduler スタブ.
 *
 * `as_enqueue_async_action()` は Action Scheduler(`lib/` または `vendor/`。
 * `WPCV_Action_Scheduler_Loader` 参照)が読み込まれたときにのみ定義される
 * グローバル関数で、`vendor/`・`lib/` はどちらも PHPStan の解析対象外
 * (`phpstan.neon.dist` の `excludePaths`)のため、型情報だけをここで宣言する
 * (`scanFiles` から読み込み、解析対象そのものにはしない。実行されることは無い).
 *
 * @package WPChecksumVerifier
 */

/**
 * @param string $hook     フック名.
 * @param array  $args     フックに渡す引数.
 * @param string $group    グループ.
 * @param bool   $unique   一意にするか.
 * @param int    $priority 優先度.
 * @return int|string アクション id.
 */
function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {}
