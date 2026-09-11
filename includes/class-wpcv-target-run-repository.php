<?php
/**
 * WPCV_Target_Run_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_target_runs` テーブルの永続化を担当する(v0.4.0 §Step1でWPCV_Repositoryから分割).
 *
 * 分割の経緯は `WPCV_Run_Repository` のクラス docblock 参照.
 */
class WPCV_Target_Run_Repository {

	/**
	 * `$wpdb` 相当のオブジェクト(`insert()` / `base_prefix` / `insert_id` を持つもの).
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * コンストラクタ.
	 *
	 * @param object $wpdb `$wpdb` 相当のオブジェクト.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * 検証対象(target_run)群を保存する.
	 *
	 * @param int   $run_id      `WPCV_Run_Repository::reserve_run()` が返した run の id.
	 * @param array $target_runs `WPCV_Verifier` の各 `verify_*()` が返す target_run の配列
	 *                           (id/run_id 無し。§5.3 のスキーマに準拠).
	 * @return array `target_id => target_run_id` の対応表(`WPCV_Finding_Repository::save_findings()` に渡す).
	 */
	public function save_target_runs( $run_id, array $target_runs ) {
		$table          = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$target_run_ids = array();

		foreach ( $target_runs as $target_run ) {
			$this->wpdb->insert(
				$table,
				array(
					'run_id'          => $run_id,
					'target_id'       => $target_run['target_id'],
					'dimension'       => $target_run['dimension'],
					'slug'            => $target_run['slug'],
					'version'         => $target_run['version'],
					'source'          => $target_run['source'],
					'source_ref'      => $target_run['source_ref'],
					'manifest_status' => $target_run['manifest_status'],
					'status'          => $target_run['status'],
					'error_code'      => $target_run['error_code'],
					'error_message'   => $target_run['error_message'],
					'files_total'     => $target_run['files_total'],
					'files_verified'  => $target_run['files_verified'],
					'findings_total'  => $target_run['findings_total'],
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
			);

			$target_run_ids[ $target_run['target_id'] ] = (int) $this->wpdb->insert_id;
		}

		return $target_run_ids;
	}
}
