<?php
/**
 * PHPUnit ブートストラップ.
 *
 * v0.1 時点ではユニットテスト本体が未実装のため、Composer オートローダーの
 * 読み込みのみ行う. WordPress 関数のモック(brain/monkey 等)は、テスト対象の
 * コードを実装するステップで導入する.
 *
 * @package WPChecksumVerifier
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
