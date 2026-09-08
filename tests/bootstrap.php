<?php
/**
 * PHPUnit ブートストラップ.
 *
 * 本プラグインの各ファイルは先頭で `if ( ! defined( 'ABSPATH' ) ) { exit; }` を
 * 持つ(直接アクセス防止)。テストは WordPress を読み込まずに実行するため、
 * production クラスを require するより前に ABSPATH を定義しておく必要がある
 * (WPMAR のテストと同じパターン。定義しないと require 時に無言で exit し、
 * PHPUnit が 0 件のまま何も出力せず終了するという分かりにくい失敗になる).
 *
 * WordPress 関数そのもののモック(brain/monkey 等)は、それが必要になるテスト対象
 * (WP 関数を呼ぶソースクラス等)を実装するステップで導入する.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
