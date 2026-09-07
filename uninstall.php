<?php
/**
 * アンインストール.
 *
 * WordPress は register_uninstall_hook() でこのファイル内の関数を呼び出す.
 * 本プラグインで作成したオプション・テーブルを削除して環境をクリーンに戻す.
 *
 * v0.1 時点ではオプション・テーブルとも未実装のため、削除処理は今後のステップ
 * (DB スキーマ・設定画面の実装)に合わせて追記する.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // セキュリティ: 直接アクセスを防止.
}
