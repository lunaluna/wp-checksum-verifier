<?php
/**
 * WPCV_Plugin クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 本番の WordPress 環境から `WPCV_Run_Coordinator` を組み立てる composition root(v0.3 §Step1).
 *
 * `WPCV_Verifier` / `WPCV_Repository` / `WPCV_Run_Coordinator` はいずれもコンストラクタ
 * 注入で依存(`WPCV_Manifest_Source` 実装・`$wpdb`)を受け取る設計(単体テストで
 * テストダブルに差し替えられるようにするため)にしてある。このクラスは唯一、
 * 実際の `global $wpdb` と本番用のソース実装(`WPCV_Source_Core` /
 * `WPCV_Source_Wporg_Plugin` / `WPCV_Unknown_File_Scanner`)を組み合わせて配線する
 * 場所として新設した(WP-CLI / WP-Cron / REST いずれのエントリポイントからも
 * 同じ組み立てを使い回すため、エントリポイントごとに `new` し直さない).
 */
class WPCV_Plugin {

	/**
	 * 組み立て済みの `WPCV_Run_Coordinator`(1リクエスト内で使い回す).
	 *
	 * @var WPCV_Run_Coordinator|null
	 */
	private static $run_coordinator = null;

	/**
	 * 本番用に配線された `WPCV_Run_Coordinator` を返す.
	 *
	 * @return WPCV_Run_Coordinator
	 */
	public static function run_coordinator() {
		if ( null === self::$run_coordinator ) {
			self::$run_coordinator = self::build_run_coordinator();
		}

		return self::$run_coordinator;
	}

	/**
	 * `WPCV_Run_Coordinator` を実際の依存で組み立てる.
	 *
	 * @return WPCV_Run_Coordinator
	 */
	private static function build_run_coordinator() {
		global $wpdb;

		$verifier = new WPCV_Verifier(
			new WPCV_Source_Core(),
			new WPCV_Source_Wporg_Plugin(),
			new WPCV_Unknown_File_Scanner()
		);

		$repository = new WPCV_Repository( $wpdb );

		return new WPCV_Run_Coordinator( $verifier, $repository );
	}
}
