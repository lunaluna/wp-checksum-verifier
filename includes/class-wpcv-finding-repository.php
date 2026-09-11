<?php
/**
 * WPCV_Finding_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_findings` テーブルの永続化を担当する(v0.4.0 §Step1でWPCV_Repositoryから分割).
 *
 * 分割の経緯は `WPCV_Run_Repository` のクラス docblock 参照.
 */
class WPCV_Finding_Repository {

	/**
	 * `$wpdb` 相当のオブジェクト(`insert()` / `base_prefix` を持つもの).
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
	 * 検出結果(finding)群を保存する.
	 *
	 * @param int   $run_id         `WPCV_Run_Repository::reserve_run()` が返した run の id.
	 * @param array $target_run_ids `WPCV_Target_Run_Repository::save_target_runs()` が返した
	 *                              `target_id => target_run_id` の対応表.
	 * @param array $findings       `WPCV_Verifier` の各 `verify_*()` が返す findings の配列
	 *                              (id/run_id/target_run_id 無し。§5.5 のスキーマに準拠).
	 * @return void
	 *
	 * @throws InvalidArgumentException 対応する target_run_id が `$target_run_ids` に無い場合(同一バッチの
	 *                                   target_runs と findings の target_id は必ず
	 *                                   一致している前提が崩れている、呼び出し側の実装ミス).
	 */
	public function save_findings( $run_id, array $target_run_ids, array $findings ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';

		foreach ( $findings as $finding ) {
			if ( ! isset( $target_run_ids[ $finding['target_id'] ] ) ) {
				throw new InvalidArgumentException(
					esc_html(
						sprintf(
							'WPCV_Finding_Repository::save_findings() has no matching target_run_id for target_id: %s',
							$finding['target_id']
						)
					)
				);
			}

			$this->wpdb->insert(
				$table,
				array(
					'run_id'         => $run_id,
					'target_run_id'  => $target_run_ids[ $finding['target_id'] ],
					'target_id'      => $finding['target_id'],
					'dimension'      => $finding['dimension'],
					'slug'           => $finding['slug'],
					'version'        => $finding['version'],
					'source'         => $finding['source'],
					'path'           => $finding['path'],
					'status'         => $finding['status'],
					'severity'       => $finding['severity'],
					'hash_algorithm' => $finding['hash_algorithm'],
					'expected_hash'  => $finding['expected_hash'],
					'actual_hash'    => $finding['actual_hash'],
					'file_size'      => $finding['file_size'],
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}
	}
}
