<?php
/**
 * WPCV_Page_Findings クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 検出結果画面(v0.4.0 §Step9)。
 *
 * 既定では最新run(`WPCV_Run_Repository::find_most_recent_run()`)のfindingsを、
 * dimension/status/severityの絞り込み・suppressed/closedの表示切替・
 * pagination付きで一覧表示する(`WPCV_Rest_Findings_Controller`と同じ
 * `WPCV_Finding_Repository::query()`に処理を委譲する。REST側とは異なり
 * allowlist外の値は`400`で拒否せず、無条件で「絞り込み無し」として扱う ――
 * 管理画面はブラウザのクエリ文字列を人間が直接編集する経路であり、REST契約の
 * ような厳格なエラー応答よりも黙ってフォールバックする方が自然なため).
 *
 * 各行から「パス除外」(`exclude_path`)・「このhashを承認」(`allowlist_hash`)・
 * 「targetごと除外」(`exclude_target`)の3操作をワンクリックで実行できる
 * (§Step9プラン「target除外はtarget単位の操作として明示する」)。3つとも
 * 理由(reason)入力を必須にし、nonce・capabilityを検証する.
 *
 * ユーザー確認済みの設計判断: これらの操作は`wpcv_suppressions`へルールを
 * 作成するのみで、画面に表示中の既存findingへ即座に反映(`suppressed_by`の
 * 書き換え)はしない。抑制は次回run以降のchunk確定時(`WPCV_Chunk_Result_Repository::
 * commit_chunk()`)に適用される設計のため、`WPCV_Finding_Repository`に既存finding
 * を後から更新するメソッドは追加しない(v0.4.0 §Step9計画時のユーザー確認).
 */
class WPCV_Page_Findings {

	/**
	 * Finding操作フォームのnonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wpcv_finding_action';

	/**
	 * Finding操作フォームのnonce name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'wpcv_finding_action_nonce';

	/**
	 * `status`絞り込みのallowlist(`WPCV_Rest_Findings_Controller::VALID_STATUSES`と
	 * 同じ一覧。クラス定数の初期値として他クラスの定数を参照すると読み込み順序に
	 * 依存してしまうため、あえて値をそのまま複製している).
	 *
	 * @var string[]
	 */
	const VALID_STATUSES = array( 'added', 'modified', 'missing', 'unreadable' );

	/**
	 * `severity`絞り込みのallowlist(`WPCV_Rest_Findings_Controller::VALID_SEVERITIES`と
	 * 同じ一覧. 複製の理由は`VALID_STATUSES`と同じ).
	 *
	 * @var string[]
	 */
	const VALID_SEVERITIES = array( 'high', 'medium', 'low' );

	/**
	 * `allowlist_hash`の対象になるfinding.statusの一覧(v0.4.0 §Step8の設計判断:
	 * `actual_hash`を持つ`added`/`modified`のみが対象).
	 *
	 * @var string[]
	 */
	const HASH_APPROVABLE_STATUSES = array( 'added', 'modified' );

	/**
	 * 画面を描画する.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::required_capability() ) ) {
			return;
		}

		$action_result = self::maybe_handle_action();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 読み取り専用のクエリ引数のため(`WPCV_Page_Run_History::render()`と同じ理由).
		$run_id_param = isset( $_GET['run_id'] ) ? absint( wp_unslash( $_GET['run_id'] ) ) : 0;
		$run_id       = $run_id_param > 0 ? $run_id_param : self::latest_run_id();

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Findings', 'wp-checksum-verifier' ); ?></h1>

			<?php if ( true === $action_result ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'The suppression rule has been created. It will apply starting with the next run.', 'wp-checksum-verifier' ); ?></p>
				</div>
			<?php elseif ( is_wp_error( $action_result ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $action_result->get_error_message() ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( null === $run_id ) : ?>
				<p><?php echo esc_html__( 'No runs have been recorded yet.', 'wp-checksum-verifier' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php self::render_filter_form( $run_id ); ?>
			<?php self::render_findings_table( $run_id ); ?>
		</div>
		<?php
	}

	/**
	 * 絞り込みフォーム(GET)を描画する.
	 *
	 * @param int $run_id 対象runのid(hidden fieldとして維持する).
	 * @return void
	 */
	private static function render_filter_form( $run_id ) {
		$filters = self::filters_from_request();
		?>
		<form method="get">
			<input type="hidden" name="page" value="wpcv-findings" />
			<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) $run_id ); ?>" />
			<p>
				<label>
					<?php echo esc_html__( 'Dimension', 'wp-checksum-verifier' ); ?>
					<select name="dimension">
						<option value=""><?php echo esc_html__( 'All', 'wp-checksum-verifier' ); ?></option>
						<?php foreach ( WPCV_Target_Resolver::DIMENSIONS as $dimension ) : ?>
							<option value="<?php echo esc_attr( $dimension ); ?>" <?php selected( $filters['dimension'], $dimension ); ?>>
								<?php echo esc_html( $dimension ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Status', 'wp-checksum-verifier' ); ?>
					<select name="status">
						<option value=""><?php echo esc_html__( 'All', 'wp-checksum-verifier' ); ?></option>
						<?php foreach ( self::VALID_STATUSES as $status ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>>
								<?php echo esc_html( $status ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php echo esc_html__( 'Severity', 'wp-checksum-verifier' ); ?>
					<select name="severity">
						<option value=""><?php echo esc_html__( 'All', 'wp-checksum-verifier' ); ?></option>
						<?php foreach ( self::VALID_SEVERITIES as $severity ) : ?>
							<option value="<?php echo esc_attr( $severity ); ?>" <?php selected( $filters['severity'], $severity ); ?>>
								<?php echo esc_html( $severity ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<input type="checkbox" name="include_suppressed" value="1" <?php checked( $filters['include_suppressed'] ); ?> />
					<?php echo esc_html__( 'Show suppressed', 'wp-checksum-verifier' ); ?>
				</label>
				<label>
					<input type="checkbox" name="include_closed" value="1" <?php checked( $filters['include_closed'] ); ?> />
					<?php echo esc_html__( 'Show closed', 'wp-checksum-verifier' ); ?>
				</label>
				<?php submit_button( __( 'Filter', 'wp-checksum-verifier' ), 'secondary', '', false ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Findings一覧テーブルを描画する.
	 *
	 * @param int $run_id 対象runのid.
	 * @return void
	 */
	private static function render_findings_table( $run_id ) {
		$filters   = self::filters_from_request();
		$paged_raw = isset( $_GET['paged'] ) ? sanitize_text_field( wp_unslash( $_GET['paged'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 読み取り専用のクエリ引数のため.
		$page      = WPCV_Page_Run_History::current_page_from_request( $paged_raw );

		$result = WPCV_Plugin::finding_repository()->query(
			array(
				'run_id'             => $run_id,
				'dimension'          => '' === $filters['dimension'] ? array() : array( $filters['dimension'] ),
				'status'             => '' === $filters['status'] ? array() : array( $filters['status'] ),
				'severity'           => '' === $filters['severity'] ? array() : array( $filters['severity'] ),
				'include_suppressed' => $filters['include_suppressed'],
				'include_closed'     => $filters['include_closed'],
				'page'               => $page,
				'per_page'           => WPCV_Finding_Repository::DEFAULT_PER_PAGE,
			)
		);

		if ( empty( $result['rows'] ) ) {
			echo '<p>' . esc_html__( 'No findings match the current filters.', 'wp-checksum-verifier' ) . '</p>';
			return;
		}

		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Target', 'wp-checksum-verifier' ); ?></th>
					<th><?php echo esc_html__( 'Path', 'wp-checksum-verifier' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'wp-checksum-verifier' ); ?></th>
					<th><?php echo esc_html__( 'Severity', 'wp-checksum-verifier' ); ?></th>
					<th><?php echo esc_html__( 'Version', 'wp-checksum-verifier' ); ?></th>
					<th><?php echo esc_html__( 'Suppressed', 'wp-checksum-verifier' ); ?></th>
					<th><?php echo esc_html__( 'Actions', 'wp-checksum-verifier' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['rows'] as $finding ) : ?>
					<tr>
						<td><?php echo esc_html( $finding['dimension'] . ':' . $finding['slug'] ); ?></td>
						<td><?php echo esc_html( $finding['path'] ); ?></td>
						<td><?php echo esc_html( $finding['status'] ); ?></td>
						<td><?php echo esc_html( $finding['severity'] ); ?></td>
						<td><?php echo esc_html( (string) $finding['version'] ); ?></td>
						<td><?php echo esc_html( self::suppressed_label( $finding ) ); ?></td>
						<td><?php self::render_finding_actions( $finding ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * 1件のfinding行の操作フォーム(パス除外・hash承認・target除外)を描画する.
	 *
	 * @param array $finding `WPCV_Finding_Repository::query()`の1行.
	 * @return void
	 */
	private static function render_finding_actions( array $finding ) {
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="wpcv_finding_dimension" value="<?php echo esc_attr( $finding['dimension'] ); ?>" />
			<input type="hidden" name="wpcv_finding_slug" value="<?php echo esc_attr( $finding['slug'] ); ?>" />
			<input type="hidden" name="wpcv_finding_path" value="<?php echo esc_attr( $finding['path'] ); ?>" />
			<input type="hidden" name="wpcv_finding_hash_algorithm" value="<?php echo esc_attr( (string) $finding['hash_algorithm'] ); ?>" />
			<input type="hidden" name="wpcv_finding_actual_hash" value="<?php echo esc_attr( (string) $finding['actual_hash'] ); ?>" />
			<input type="hidden" name="wpcv_finding_version" value="<?php echo esc_attr( (string) $finding['version'] ); ?>" />
			<p>
				<input type="text" name="wpcv_finding_reason" placeholder="<?php echo esc_attr__( 'Reason (required)', 'wp-checksum-verifier' ); ?>" />
			</p>
			<p>
				<button type="submit" class="button" name="wpcv_finding_action" value="<?php echo esc_attr( WPCV_Suppression_Type::EXCLUDE_PATH ); ?>">
					<?php echo esc_html__( 'Exclude this path', 'wp-checksum-verifier' ); ?>
				</button>
				<?php if ( in_array( $finding['status'], self::HASH_APPROVABLE_STATUSES, true ) && ! empty( $finding['actual_hash'] ) ) : ?>
					<button type="submit" class="button" name="wpcv_finding_action" value="<?php echo esc_attr( WPCV_Suppression_Type::ALLOWLIST_HASH ); ?>">
						<?php echo esc_html__( 'Approve this hash', 'wp-checksum-verifier' ); ?>
					</button>
				<?php endif; ?>
				<button type="submit" class="button" name="wpcv_finding_action" value="<?php echo esc_attr( WPCV_Suppression_Type::EXCLUDE_TARGET ); ?>">
					<?php echo esc_html__( 'Exclude entire target', 'wp-checksum-verifier' ); ?>
				</button>
			</p>
		</form>
		<?php
	}

	/**
	 * 「Suppressed」列の表示文字列を組み立てる.
	 *
	 * @param array $finding `WPCV_Finding_Repository::query()`の1行.
	 * @return string
	 */
	private static function suppressed_label( array $finding ) {
		if ( ! empty( $finding['suppression_id'] ) ) {
			return sprintf(
				/* translators: %d: suppression rule id. */
				__( 'Rule #%d', 'wp-checksum-verifier' ),
				(int) $finding['suppression_id']
			);
		}

		if ( ! empty( $finding['suppressed_by'] ) ) {
			return (string) $finding['suppressed_by'];
		}

		return '';
	}

	/**
	 * `$_GET`から絞り込み条件を読み取る(`render_filter_form()`/`render_findings_table()`
	 * の両方から使う共通処理).allowlist外の値は「絞り込み無し」に読み替える
	 * (クラスdocblock参照).
	 *
	 * @return array{dimension: string, status: string, severity: string, include_suppressed: bool, include_closed: bool}
	 */
	private static function filters_from_request() {
		// 読み取り専用のクエリ引数のみを扱うため nonce 検証を要求しない
		// (`WPCV_Page_Run_History::render()` と同じ理由。allowlist外の値は
		// `resolve_filter()` が「絞り込み無し」へ読み替えるため、サニタイズ不足による
		// 実害も無い).
		$dimension_raw = isset( $_GET['dimension'] ) ? sanitize_text_field( wp_unslash( $_GET['dimension'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status_raw    = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$severity_raw  = isset( $_GET['severity'] ) ? sanitize_text_field( wp_unslash( $_GET['severity'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array(
			'dimension'          => self::resolve_filter( $dimension_raw, WPCV_Target_Resolver::DIMENSIONS ),
			'status'             => self::resolve_filter( $status_raw, self::VALID_STATUSES ),
			'severity'           => self::resolve_filter( $severity_raw, self::VALID_SEVERITIES ),
			'include_suppressed' => ! empty( $_GET['include_suppressed'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'include_closed'     => ! empty( $_GET['include_closed'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * 絞り込み値をallowlistで検証する(`render()`から分離してテスト可能にする)。
	 * allowlistに無い値・非文字列は「絞り込み無し」を意味する空文字にfallbackする.
	 *
	 * @param mixed    $value     `$_GET`の生値.
	 * @param string[] $allowlist 許可される値の一覧.
	 * @return string
	 */
	public static function resolve_filter( $value, array $allowlist ) {
		$value = is_string( $value ) ? $value : '';

		return in_array( $value, $allowlist, true ) ? $value : '';
	}

	/**
	 * Finding操作フォームの`$_POST`内容から、`WPCV_Suppression_Repository::insert()`に
	 * 渡すデータを組み立てる(`maybe_handle_action()`から分離してテスト可能にする)。
	 *
	 * @param string $action     `WPCV_Suppression_Type`のいずれか.
	 * @param array  $finding    hidden fieldから読み取ったfinding情報
	 *                           (`dimension`/`slug`/`path`/`hash_algorithm`/`actual_hash`/`version`).
	 * @param string $reason     登録理由(空文字は呼び出し前に弾く前提).
	 * @param int    $created_by 登録した user id.
	 * @return array|WP_Error `WPCV_Suppression_Repository::insert()`にそのまま渡せる配列。
	 *                         未知のactionまたは`allowlist_hash`でhashが無い場合は`WP_Error`.
	 */
	public static function build_suppression_data( $action, array $finding, $reason, $created_by ) {
		switch ( $action ) {
			case WPCV_Suppression_Type::EXCLUDE_TARGET:
				return array(
					'type'       => WPCV_Suppression_Type::EXCLUDE_TARGET,
					'dimension'  => $finding['dimension'],
					'slug'       => $finding['slug'],
					'reason'     => $reason,
					'created_by' => $created_by,
				);

			case WPCV_Suppression_Type::EXCLUDE_PATH:
				return array(
					'type'       => WPCV_Suppression_Type::EXCLUDE_PATH,
					'dimension'  => $finding['dimension'],
					'slug'       => $finding['slug'],
					'pattern'    => $finding['path'],
					'reason'     => $reason,
					'created_by' => $created_by,
				);

			case WPCV_Suppression_Type::ALLOWLIST_HASH:
				if ( empty( $finding['actual_hash'] ) ) {
					return new WP_Error(
						'wpcv_no_hash',
						__( 'This finding has no hash to approve.', 'wp-checksum-verifier' )
					);
				}

				return array(
					'type'           => WPCV_Suppression_Type::ALLOWLIST_HASH,
					'dimension'      => $finding['dimension'],
					'slug'           => $finding['slug'],
					'pattern'        => $finding['path'],
					'expected_hash'  => $finding['actual_hash'],
					'hash_algorithm' => $finding['hash_algorithm'],
					'version'        => $finding['version'],
					'reason'         => $reason,
					'created_by'     => $created_by,
				);

			default:
				return new WP_Error(
					'wpcv_invalid_action',
					__( 'Unknown action.', 'wp-checksum-verifier' )
				);
		}
	}

	/**
	 * Finding操作フォームがPOSTされていればnonce・capability・理由入力を検証した
	 * うえで抑制ルールを作成する.
	 *
	 * @return true|WP_Error|null 作成できたら`true`。理由未入力・不正なaction・
	 *                            hash無しでの承認試行は`WP_Error`。POSTされていない・
	 *                            capability検証に失敗した場合は`null`.
	 */
	private static function maybe_handle_action() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return null;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		if ( ! current_user_can( self::required_capability() ) ) {
			return null;
		}

		$reason = isset( $_POST['wpcv_finding_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcv_finding_reason'] ) ) : '';

		if ( '' === $reason ) {
			return new WP_Error( 'wpcv_reason_required', __( 'A reason is required.', 'wp-checksum-verifier' ) );
		}

		$action  = isset( $_POST['wpcv_finding_action'] ) ? sanitize_key( wp_unslash( $_POST['wpcv_finding_action'] ) ) : '';
		$finding = array(
			'dimension'      => isset( $_POST['wpcv_finding_dimension'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcv_finding_dimension'] ) ) : '',
			'slug'           => isset( $_POST['wpcv_finding_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcv_finding_slug'] ) ) : '',
			'path'           => isset( $_POST['wpcv_finding_path'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcv_finding_path'] ) ) : '',
			'hash_algorithm' => isset( $_POST['wpcv_finding_hash_algorithm'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcv_finding_hash_algorithm'] ) ) : '',
			'actual_hash'    => isset( $_POST['wpcv_finding_actual_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcv_finding_actual_hash'] ) ) : '',
			'version'        => isset( $_POST['wpcv_finding_version'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcv_finding_version'] ) ) : '',
		);

		$data = self::build_suppression_data( $action, $finding, $reason, get_current_user_id() );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		WPCV_Plugin::suppression_repository()->insert( $data );

		return true;
	}

	/**
	 * 最新runのidを返す(runが1件も無ければ`null`).
	 *
	 * @return int|null
	 */
	private static function latest_run_id() {
		$latest = WPCV_Plugin::run_repository()->find_most_recent_run();

		return null === $latest ? null : (int) $latest['id'];
	}

	/**
	 * この画面に必要なcapabilityを返す(`WPCV_Page_Settings::required_capability()`と
	 * 同じ判定).
	 *
	 * @return string
	 */
	private static function required_capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}
}
