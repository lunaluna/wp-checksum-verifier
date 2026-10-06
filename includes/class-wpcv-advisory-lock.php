<?php
/**
 * WPCV_Advisory_Lock クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MySQL の advisory lock(`GET_LOCK()` / `RELEASE_LOCK()`)の小さなラッパー(v0.10.0).
 *
 * 履歴の削除(`WPCV_Retention_Cleaner::prune()`)と、「古い履歴を今すぐ削除」の受け付け
 * (`WPCV_Prune_Job::request()`)を単一所有にするために使う(0.10.0 のコードレビュー指摘2).
 * `WPCV_Run_Repository::reserve_run()` の lock と同じ方式.
 *
 * - `GET_LOCK()` の名前空間は MySQL サーバー単位なので、同じサーバーに同居する他のインストールと
 *   衝突しないよう、名前に `base_prefix` を含める(`WPCV_Run_Repository::lock_name()` と同じ理由).
 * - lock は DB の接続(= PHP のリクエスト)に紐づく. プロセスが異常終了しても、接続が切れれば
 *   MySQL が解放するので、取りっぱなしで残ることはない.
 * - 名前は 64 文字まで(MySQL の制限). `wpcv_{name}_{base_prefix}` は通常十分に短い.
 */
class WPCV_Advisory_Lock {

	/**
	 * データベースオブジェクト(`wpdb` またはテストダブル).
	 *
	 * @var object|null
	 */
	private $wpdb;

	/**
	 * 用途を表す短い名前(例: `prune`). 完全な名前は `full_name()` が作る.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * コンストラクタ.
	 *
	 * `$wpdb` はここでは読まない(`base_prefix` も使うときに読む). `WPCV_Plugin` の配線は、
	 * `$wpdb` がまだ無い文脈(単体テストなど)でも作られることがあるため.
	 *
	 * @param object|null $wpdb `wpdb` またはテストダブル.
	 * @param string      $name 用途を表す短い名前(例: `prune`).
	 */
	public function __construct( $wpdb, $name ) {
		$this->wpdb = $wpdb;
		$this->name = (string) $name;
	}

	/**
	 * Lock を取る.
	 *
	 * @param int $timeout_seconds 待つ秒数. 0 なら待たずにすぐ結果を返す.
	 * @return bool 取れたら true.
	 */
	public function acquire( $timeout_seconds = 0 ) {
		// `$wpdb->prepare()` の形で書く(PHPCS がプロパティ経由の呼び出しを prepare と認識しないため.
		// `WPCV_Run_Repository::reserve_run()` と同じ書き方).
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK() はキャッシュ不可.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->full_name(), (int) $timeout_seconds ) );

		return '1' === (string) $acquired;
	}

	/**
	 * Lock を放す.
	 *
	 * @return void
	 */
	public function release() {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- RELEASE_LOCK() はキャッシュ不可.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->full_name() ) );
	}

	/**
	 * `base_prefix` を含む完全な名前を返す.
	 *
	 * @return string
	 */
	private function full_name() {
		return 'wpcv_' . $this->name . '_' . $this->wpdb->base_prefix;
	}
}
