<?php
/**
 * WPCV_Rest_Findings_Controller クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /wp-json/wpcv/v1/findings`(v0.4.0 §Step7)。
 *
 * 既定では最新のrun(`WPCV_Run_Repository::find_most_recent_run()`。ステータスは
 * 問わない ―― 進行中のrunが既に確定したchunk分のfindingsを持つこともあるため)の
 * findingsを、`per_page`に既定値・上限を設けたpagination付きで返す。`run_id`
 * クエリパラメータで対象runを明示的に指定することもできる(Step9の実行履歴UIから
 * 過去のrunを閲覧する用途を見越して、コストの低い今のうちに対応しておく).
 *
 * `dimension`/`status`/`severity`/`sort`/`order` はすべてallowlist方式で検証し、
 * 許可されない値は `400` を返す(§Step7プラン「allowlist方式のfilter/sort」)。
 * `suppressed`/`closed` は既定で除外し、`include_suppressed`/`include_closed`
 * (`'1'`/`'true'`/`'yes'` のいずれかで真)で含められる(§7「APIの既定では除外し、
 * `include_suppressed`で取得可能にする」).
 *
 * 実際のfilter/sort/pagination処理は `WPCV_Finding_Repository::query()` に
 * 委譲する(このクラスはリクエストパラメータの検証とレスポンス整形のみを担う).
 *
 * 認証は `WPCV_Rest_Token::check_permission()` に `SCOPE_READ` を渡す
 * (`WPCV_Rest_Status_Controller` と同じ. `WPCV_Rest_Token` のクラス docblock参照)。
 * `Cache-Control: no-store` は `WPCV_Rest_Support` 経由で付与する.
 */
class WPCV_Rest_Findings_Controller {

	/**
	 * REST名前空間.
	 *
	 * @var string
	 */
	const NAMESPACE_NAME = 'wpcv/v1';

	/**
	 * ルートのパス(名前空間からの相対パス).
	 *
	 * @var string
	 */
	const ROUTE = '/findings';

	/**
	 * `status` クエリパラメータのallowlist(`WPCV_Verifier::make_finding()` /
	 * `verify_manifest_chunk()` 等が実際に作るfinding.statusの値。単一の定数
	 * クラスに集約されていないため、ここに直接列挙する).
	 *
	 * @var string[]
	 */
	const VALID_STATUSES = array( 'added', 'modified', 'missing', 'unreadable' );

	/**
	 * `severity` クエリパラメータのallowlist.
	 *
	 * @var string[]
	 */
	const VALID_SEVERITIES = array( 'high', 'medium', 'low' );

	/**
	 * `order` クエリパラメータのallowlist.
	 *
	 * @var string[]
	 */
	const VALID_ORDERS = array( 'asc', 'desc' );

	/**
	 * ルートを登録する. `rest_api_init` フックから呼ぶ.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_NAME,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_findings' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * パーミッションコールバック(`SCOPE_READ`).
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return true|WP_Error
	 */
	public static function check_permission( $request ) {
		return WPCV_Rest_Token::check_permission( $request, WPCV_Rest_Token::SCOPE_READ );
	}

	/**
	 * `GET /findings` のハンドラ.
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_findings( $request ) {
		$run_repository = WPCV_Plugin::run_repository();
		$run_id         = self::resolve_run_id( $request, $run_repository );

		if ( null === $run_id ) {
			return WPCV_Rest_Support::response(
				array(
					'findings'    => array(),
					'run_id'      => null,
					'page'        => 1,
					'per_page'    => WPCV_Finding_Repository::DEFAULT_PER_PAGE,
					'total'       => 0,
					'total_pages' => 0,
				)
			);
		}

		$dimension = self::validate_allowlist( $request->get_param( 'dimension' ), WPCV_Target_Resolver::DIMENSIONS, 'dimension' );
		if ( is_wp_error( $dimension ) ) {
			return $dimension;
		}

		$status = self::validate_allowlist( $request->get_param( 'status' ), self::VALID_STATUSES, 'status' );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$severity = self::validate_allowlist( $request->get_param( 'severity' ), self::VALID_SEVERITIES, 'severity' );
		if ( is_wp_error( $severity ) ) {
			return $severity;
		}

		$sort = self::validate_one_of( $request->get_param( 'sort' ), WPCV_Finding_Repository::SORTABLE_COLUMNS, 'id', 'sort' );
		if ( is_wp_error( $sort ) ) {
			return $sort;
		}

		$order = self::validate_one_of( $request->get_param( 'order' ), self::VALID_ORDERS, 'asc', 'order' );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$page     = self::positive_int( $request->get_param( 'page' ), 1 );
		$per_page = min(
			WPCV_Finding_Repository::MAX_PER_PAGE,
			self::positive_int( $request->get_param( 'per_page' ), WPCV_Finding_Repository::DEFAULT_PER_PAGE )
		);

		$result = WPCV_Plugin::finding_repository()->query(
			array(
				'run_id'             => $run_id,
				'dimension'          => $dimension,
				'status'             => $status,
				'severity'           => $severity,
				'include_suppressed' => self::to_bool( $request->get_param( 'include_suppressed' ) ),
				'include_closed'     => self::to_bool( $request->get_param( 'include_closed' ) ),
				'sort'               => $sort,
				'order'              => $order,
				'page'               => $page,
				'per_page'           => $per_page,
			)
		);

		return WPCV_Rest_Support::response(
			array(
				'findings'    => array_values( $result['rows'] ),
				'run_id'      => $run_id,
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $result['total'],
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	/**
	 * `run_id` クエリパラメータ、無ければ最新runのidを返す.
	 *
	 * @param WP_REST_Request     $request         リクエスト.
	 * @param WPCV_Run_Repository $run_repository `wpcv_runs` の永続化層.
	 * @return int|null 対象runが無ければ `null`.
	 */
	private static function resolve_run_id( $request, WPCV_Run_Repository $run_repository ) {
		$run_id_param = $request->get_param( 'run_id' );

		if ( null !== $run_id_param && '' !== $run_id_param ) {
			return (int) $run_id_param;
		}

		$latest = $run_repository->find_most_recent_run();

		return null === $latest ? null : (int) $latest['id'];
	}

	/**
	 * クエリパラメータの値(単一またはカンマ区切り/配列)をallowlistで検証する.
	 *
	 * @param mixed    $value     `WP_REST_Request::get_param()` の戻り値.
	 * @param string[] $allowlist 許可される値の一覧.
	 * @param string   $param_name エラーメッセージに含めるパラメータ名.
	 * @return string[]|WP_Error 未指定なら絞り込み無し(空配列)。allowlist外の値が
	 *                            含まれていれば `WP_Error`(400).
	 */
	private static function validate_allowlist( $value, array $allowlist, $param_name ) {
		if ( null === $value || '' === $value || array() === $value ) {
			return array();
		}

		$values = array_map( 'strval', (array) $value );

		foreach ( $values as $single_value ) {
			if ( ! in_array( $single_value, $allowlist, true ) ) {
				return self::invalid_param_error( $param_name );
			}
		}

		return $values;
	}

	/**
	 * クエリパラメータの値がallowlist内の1つであることを検証する(`sort`/`order`用.
	 * `validate_allowlist()` と異なり複数値を許さない単一選択のパラメータ向け).
	 *
	 * @param mixed    $value      `WP_REST_Request::get_param()` の戻り値.
	 * @param string[] $allowlist  許可される値の一覧.
	 * @param string   $default_value    未指定時の既定値(`$allowlist` に含まれる前提).
	 * @param string   $param_name エラーメッセージに含めるパラメータ名.
	 * @return string|WP_Error
	 */
	private static function validate_one_of( $value, array $allowlist, $default_value, $param_name ) {
		if ( null === $value || '' === $value ) {
			return $default_value;
		}

		$value = (string) $value;

		if ( ! in_array( $value, $allowlist, true ) ) {
			return self::invalid_param_error( $param_name );
		}

		return $value;
	}

	/**
	 * `page`/`per_page` を正の整数として解決する(未指定・0以下は既定値).
	 *
	 * @param mixed $value   `WP_REST_Request::get_param()` の戻り値.
	 * @param int   $default_value 未指定・0以下の場合に使う既定値.
	 * @return int
	 */
	private static function positive_int( $value, $default_value ) {
		$int = null === $value ? 0 : (int) $value;

		return $int > 0 ? $int : $default_value;
	}

	/**
	 * クエリパラメータの文字列を真偽値として解釈する(`'1'`/`'true'`/`'yes'` を
	 * 真とする. 未指定・それ以外はすべて偽).
	 *
	 * @param mixed $value `WP_REST_Request::get_param()` の戻り値.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		if ( null === $value ) {
			return false;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes' ), true );
	}

	/**
	 * 不正なパラメータ値に対する `WP_Error`(400)を組み立てる.
	 *
	 * @param string $param_name パラメータ名.
	 * @return WP_Error
	 */
	private static function invalid_param_error( $param_name ) {
		return new WP_Error(
			'wpcv_rest_invalid_param',
			sprintf(
				/* translators: %s: parameter name. */
				__( 'Invalid value for parameter: %s', 'wp-checksum-verifier' ),
				$param_name
			),
			array( 'status' => 400 )
		);
	}
}
