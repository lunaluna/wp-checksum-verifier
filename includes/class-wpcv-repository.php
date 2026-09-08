<?php
/**
 * WPCV_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `WPCV_Verifier` が返す target_run / finding のデータ構造を DB に永続化する層.
 *
 * 検証ロジック(`WPCV_Verifier`)と DB アクセスを分離する(§4.2: `class-wpcv-verifier.php`
 * と `class-wpcv-repository.php` は別ファイル)。findings がどの target_run に
 * 属するかは `target_id` で相関させる設計(`WPCV_Verifier` の docblock参照)のため、
 * `save_target_runs()` が返す `target_id => target_run_id` の対応表を
 * `save_findings()` にそのまま渡すこと.
 *
 * 既存の `WPCV_API` / `WPCV_Migrator` は `global $wpdb;` を直接参照するが、この
 * クラスは単体テストで実 DB を使わずに検証したいため、コンストラクタで
 * `$wpdb` 相当のオブジェクトを注入できるようにする(既存クラスとの意図的な差異).
 */
class WPCV_Repository {

	/**
	 * `$wpdb` 相当のオブジェクト(`insert()` / `update()` / `base_prefix` / `insert_id` を持つもの).
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * 現在時刻(UTC の MySQL DATETIME 文字列)を返す callable.
	 *
	 * テストで固定時刻を注入できるようにするため引数で差し替え可能にする.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * コンストラクタ.
	 *
	 * @param object        $wpdb `$wpdb` 相当のオブジェクト.
	 * @param callable|null $now  現在時刻を返す callable. 省略時は `gmdate( 'Y-m-d H:i:s' )`.
	 */
	public function __construct( $wpdb, ?callable $now = null ) {
		$this->wpdb = $wpdb;
		$this->now  = $now ?? static function () {
			return gmdate( 'Y-m-d H:i:s' );
		};
	}

	/**
	 * 実行(run)行を作成する(status = running).
	 *
	 * @param array $args {
	 *     省略可能なオプション.
	 *
	 *     @type string $run_trigger cron|manual|cli|rest. 既定 'manual'
	 *                               (実行モデル・トリガーの実装は §6 で v0.3 対象のため、
	 *                               v0.2 時点では常にこの既定値を使う想定).
	 *     @type string $runner      sync|async. 既定 'sync'.
	 * }
	 * @return int 作成した run の id.
	 */
	public function start_run( array $args = array() ) {
		$run_trigger = isset( $args['run_trigger'] ) ? (string) $args['run_trigger'] : 'manual';
		$runner      = isset( $args['runner'] ) ? (string) $args['runner'] : 'sync';

		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		$this->wpdb->insert(
			$table,
			array(
				'started_at'  => call_user_func( $this->now ),
				'status'      => 'running',
				'run_trigger' => $run_trigger,
				'runner'      => $runner,
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * 検証対象(target_run)群を保存する.
	 *
	 * @param int   $run_id      `start_run()` が返した run の id.
	 * @param array $target_runs `WPCV_Verifier` の各 `verify_*()` が返す target_run の配列
	 *                           (id/run_id 無し。§5.3 のスキーマに準拠).
	 * @return array `target_id => target_run_id` の対応表(`save_findings()` に渡す).
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

	/**
	 * 検出結果(finding)群を保存する.
	 *
	 * @param int   $run_id         `start_run()` が返した run の id.
	 * @param array $target_run_ids `save_target_runs()` が返した `target_id => target_run_id` の対応表.
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
							'WPCV_Repository::save_findings() has no matching target_run_id for target_id: %s',
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

	/**
	 * 実行(run)行を完了状態にする(status・finished_at・集計値を更新する).
	 *
	 * @param int   $run_id  `start_run()` が返した run の id.
	 * @param array $summary `WPCV_Verifier::summarize()` の戻り値.
	 * @return void
	 */
	public function finish_run( $run_id, array $summary ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		$this->wpdb->update(
			$table,
			array(
				'finished_at'          => call_user_func( $this->now ),
				'status'               => $summary['status'],
				'targets_total'        => $summary['targets_total'],
				'targets_verified'     => $summary['targets_verified'],
				'targets_unverifiable' => $summary['targets_unverifiable'],
				'targets_failed'       => $summary['targets_failed'],
				'findings_total'       => $summary['findings_total'],
			),
			array( 'id' => $run_id ),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%d' ),
			array( '%d' )
		);
	}
}
