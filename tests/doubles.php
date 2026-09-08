<?php
/**
 * 複数のテストファイルで共有するテストダブル.
 *
 * `VerifierTest` と `RunCoordinatorTest` がどちらも固定結果を返す
 * `WPCV_Manifest_Source` を必要とし、`RepositoryTest` と `RunCoordinatorTest`
 * がどちらも `$wpdb` ダブルを必要とするため、重複を避けてここに集約する
 * (2箇所目の利用が出た時点で共通化する、という判断).
 *
 * @package WPChecksumVerifier
 */

require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';

/**
 * テスト用の固定結果を返す `WPCV_Manifest_Source` 実装.
 *
 * 実際の Source_Core / Source_Wporg_Plugin(HTTP・WP 関数依存)を経由せず、
 * 呼び出し側のオーケストレーションロジックだけを検証するために使う.
 */
class WPCV_Test_Fake_Manifest_Source implements WPCV_Manifest_Source {

	/**
	 * get_manifest() が返す固定値、または target_id => 固定値 のマップ.
	 *
	 * @var array
	 */
	private $result;

	/**
	 * コンストラクタ.
	 *
	 * @param array $result get_manifest() の戻り値としてそのまま返す配列.
	 */
	public function __construct( array $result ) {
		$this->result = $result;
	}

	/**
	 * 固定値をそのまま返す.
	 *
	 * @param array $context 無視する.
	 * @return array
	 */
	public function get_manifest( array $context ) {
		unset( $context );
		return $this->result;
	}
}

/**
 * テスト用の最小 `$wpdb` ダブル.
 *
 * `insert()` / `update()` の呼び出しをそのままメモリ上の配列に記録するだけの
 * 実装で、実際の SQL は発行しない。`WPCV_Repository` が呼ぶメソッド群だけを
 * 満たす(実 wpdb クラスは実装しない。ダックタイピングで十分なため).
 */
class WPCV_Test_Fake_WPDB {

	/**
	 * インストールレベルのテーブル接頭辞(本番の `$wpdb->base_prefix` に相当).
	 *
	 * @var string
	 */
	public $base_prefix = 'wp_';

	/**
	 * 直近の `insert()` が採番した id(本番の `$wpdb->insert_id` に相当).
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * テーブルごとの行(id をキーにした連想配列).
	 *
	 * @var array<string, array<int, array>>
	 */
	public $rows = array();

	/**
	 * テーブルごとの次の auto increment id.
	 *
	 * @var array<string, int>
	 */
	private $next_id = array();

	/**
	 * 行を追加する.
	 *
	 * @param string     $table  テーブル名.
	 * @param array      $data   カラム => 値.
	 * @param array|null $format 無視する(本番の型指定に相当。ダブルでは検証しない).
	 * @return int 常に1(本番の `$wpdb->insert()` の成功時と同じ).
	 */
	public function insert( $table, $data, $format = null ) {
		unset( $format );

		if ( ! isset( $this->next_id[ $table ] ) ) {
			$this->next_id[ $table ] = 1;
		}

		$id         = $this->next_id[ $table ]++;
		$data['id'] = $id;

		$this->rows[ $table ][ $id ] = $data;
		$this->insert_id             = $id;

		return 1;
	}

	/**
	 * 条件に一致する行を更新する.
	 *
	 * @param string     $table        テーブル名.
	 * @param array      $data         更新するカラム => 値.
	 * @param array      $where        カラム => 値(すべて一致する行を更新).
	 * @param array|null $format       無視する.
	 * @param array|null $where_format 無視する.
	 * @return int 更新した行数.
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		unset( $format, $where_format );

		$updated = 0;

		foreach ( $this->rows[ $table ] as $id => $row ) {
			$matches = true;

			foreach ( $where as $column => $value ) {
				if ( ! isset( $row[ $column ] ) || $row[ $column ] !== $value ) {
					$matches = false;
					break;
				}
			}

			if ( $matches ) {
				$this->rows[ $table ][ $id ] = array_merge( $row, $data );
				++$updated;
			}
		}

		return $updated;
	}
}

/**
 * `WPCV_Verifier` の各 `verify_*()` が返す target_run の最小形を作る.
 *
 * @param array $overrides 上書きするフィールド.
 * @return array
 */
function wpcv_test_make_target_run( array $overrides = array() ) {
	return array_merge(
		array(
			'target_id'       => 'core',
			'dimension'       => 'core',
			'slug'            => 'wordpress',
			'version'         => '6.8',
			'source'          => 'wporg',
			'source_ref'      => null,
			'manifest_status' => 'ok',
			'status'          => 'success',
			'error_code'      => null,
			'error_message'   => null,
			'files_total'     => 10,
			'files_verified'  => 10,
			'findings_total'  => 0,
		),
		$overrides
	);
}

/**
 * `WPCV_Verifier` の各 `verify_*()` が返す finding の最小形を作る.
 *
 * @param array $overrides 上書きするフィールド.
 * @return array
 */
function wpcv_test_make_finding( array $overrides = array() ) {
	return array_merge(
		array(
			'target_id'      => 'core',
			'dimension'      => 'core',
			'slug'           => 'wordpress',
			'version'        => '6.8',
			'source'         => 'wporg',
			'path'           => 'wp-admin/index.php',
			'status'         => 'modified',
			'severity'       => 'high',
			'hash_algorithm' => 'sha256',
			'expected_hash'  => str_repeat( 'a', 64 ),
			'actual_hash'    => str_repeat( 'b', 64 ),
			'file_size'      => 123,
		),
		$overrides
	);
}
