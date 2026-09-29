<?php
/**
 * WPCV_Page_Run_History クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 実行履歴画面(v0.4.0 §Step9)。
 *
 * Run一覧(pagination付き)と、runを1件選択した際のtarget_runs詳細
 * (unverifiable/retry/aborted理由をerror_codeラベルで表示)の2ビューを、
 * `$_GET['run_id']` の有無で切り替えて同じクラスで扱う(親子関係にある2ビューの
 * ため`WPCV_Page_Settings`のような単一フォーム画面とは異なる構成にした)。
 *
 * この画面は読み取り専用(state-changing操作を持たない)。抑制の作成・取消は
 * §Step9の後続コミットで別画面(検出結果画面・抑制一覧画面)に持たせる.
 */
class WPCV_Page_Run_History {

	/**
	 * 一覧の1ページあたりの表示件数(`WPCV_Run_Repository::find_all()`へ渡す).
	 *
	 * @var int
	 */
	const PER_PAGE = 20;

	/**
	 * 画面を描画する.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::required_capability() ) ) {
			return;
		}

		// 読み取り専用のページ切り替え・詳細選択のみで状態変更を行わないため、
		// WP管理画面の一覧テーブル(`$_REQUEST['paged']`等)と同じくnonceを要求しない.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 読み取り専用のクエリ引数のため.
		$run_id = isset( $_GET['run_id'] ) ? absint( wp_unslash( $_GET['run_id'] ) ) : 0;

		if ( $run_id > 0 ) {
			self::render_detail( $run_id );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 読み取り専用のクエリ引数のため.
		$page = self::current_page_from_request( isset( $_GET['paged'] ) ? wp_unslash( $_GET['paged'] ) : null );

		self::render_list( $page );
	}

	/**
	 * Run一覧を描画する.
	 *
	 * @param int $page 表示するページ番号(1始まり).
	 * @return void
	 */
	private static function render_list( $page ) {
		$result      = WPCV_Plugin::run_repository()->find_all(
			array(
				'page'     => $page,
				'per_page' => self::PER_PAGE,
			)
		);
		$total_pages = self::total_pages( $result['total'], self::PER_PAGE );
		$detail_base = self::page_url();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Run history', 'wp-checksum-verifier' ); ?></h1>

			<?php if ( empty( $result['rows'] ) ) : ?>
				<p><?php echo esc_html__( 'No runs have been recorded yet.', 'wp-checksum-verifier' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Run', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Status', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Trigger', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Started', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Finished', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Targets', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Findings', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Diff (New / Resolved / Continuing)', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Alert', 'wp-checksum-verifier' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['rows'] as $run ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( add_query_arg( 'run_id', (int) $run['id'], $detail_base ) ); ?>">
										#<?php echo esc_html( (string) $run['id'] ); ?>
									</a>
								</td>
								<td><?php echo esc_html( (string) $run['status'] ); ?></td>
								<td><?php echo esc_html( isset( $run['run_trigger'] ) ? (string) $run['run_trigger'] : '' ); ?></td>
								<td><?php echo esc_html( self::display_datetime( $run['started_at'] ?? null ) ); ?></td>
								<td><?php echo esc_html( self::display_datetime( $run['finished_at'] ?? null ) ); ?></td>
								<td><?php echo esc_html( (string) ( $run['targets_total'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $run['findings_total'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( self::format_diff_summary( $run ) ); ?></td>
								<td><?php echo esc_html( self::format_alert_status( $run ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<p class="tablenav-pages">
						<?php if ( $page > 1 ) : ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1, $detail_base ) ); ?>">
								<?php echo esc_html__( '« Previous', 'wp-checksum-verifier' ); ?>
							</a>
						<?php endif; ?>
						<?php
						printf(
							/* translators: 1: current page, 2: total pages. */
							esc_html__( 'Page %1$d of %2$d', 'wp-checksum-verifier' ),
							(int) $page,
							(int) $total_pages
						);
						?>
						<?php if ( $page < $total_pages ) : ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1, $detail_base ) ); ?>">
								<?php echo esc_html__( 'Next »', 'wp-checksum-verifier' ); ?>
							</a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * 1件のrunの詳細(target_runs)を描画する.
	 *
	 * @param int $run_id 対象のrunのid.
	 * @return void
	 */
	private static function render_detail( $run_id ) {
		$run = WPCV_Plugin::run_repository()->find_by_id( $run_id );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Run history', 'wp-checksum-verifier' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( self::page_url() ); ?>">
					<?php echo esc_html__( '« Back to run history', 'wp-checksum-verifier' ); ?>
				</a>
			</p>

			<?php if ( null === $run ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html__( 'This run could not be found.', 'wp-checksum-verifier' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<h2>
				<?php
				printf(
					/* translators: %d: run id. */
					esc_html__( 'Run #%d', 'wp-checksum-verifier' ),
					(int) $run['id']
				);
				?>
			</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Status', 'wp-checksum-verifier' ); ?></th>
					<td><?php echo esc_html( (string) $run['status'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Trigger', 'wp-checksum-verifier' ); ?></th>
					<td><?php echo esc_html( isset( $run['run_trigger'] ) ? (string) $run['run_trigger'] : '' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Started', 'wp-checksum-verifier' ); ?></th>
					<td><?php echo esc_html( self::display_datetime( $run['started_at'] ?? null ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Finished', 'wp-checksum-verifier' ); ?></th>
					<td><?php echo esc_html( self::display_datetime( $run['finished_at'] ?? null ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Deadline', 'wp-checksum-verifier' ); ?></th>
					<td><?php echo esc_html( self::display_datetime( $run['deadline_at'] ?? null ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Diff', 'wp-checksum-verifier' ); ?></th>
					<td>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: diff_status (or a dash), 2: diff attempt count. */
								__( '%1$s (attempts: %2$d)', 'wp-checksum-verifier' ),
								empty( $run['diff_status'] ) ? '—' : (string) $run['diff_status'],
								(int) ( $run['diff_attempt_count'] ?? 0 )
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'New / Resolved / Continuing', 'wp-checksum-verifier' ); ?></th>
					<td><?php echo esc_html( self::format_diff_counts( $run ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Alert', 'wp-checksum-verifier' ); ?></th>
					<td>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: alert_status (or a dash), 2: alert_attempted_at (or a dash). */
								__( '%1$s (attempted: %2$s)', 'wp-checksum-verifier' ),
								self::format_alert_status( $run ),
								self::display_datetime( $run['alert_attempted_at'] ?? null )
							)
						);
						?>
					</td>
				</tr>
				<?php if ( ! empty( $run['alert_error'] ) ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Alert error', 'wp-checksum-verifier' ); ?></th>
						<td><?php echo esc_html( (string) $run['alert_error'] ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( ! empty( $run['alert_channel_failures'] ) ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Failed alert channels', 'wp-checksum-verifier' ); ?></th>
						<td><?php echo esc_html( (string) $run['alert_channel_failures'] ); ?></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Findings', 'wp-checksum-verifier' ); ?></th>
					<td>
						<a href="<?php echo esc_url( add_query_arg( 'run_id', (int) $run['id'], menu_page_url( 'wpcv-findings', false ) ) ); ?>">
							<?php echo esc_html__( 'View findings for this run', 'wp-checksum-verifier' ); ?>
						</a>
					</td>
				</tr>
			</table>

			<h3><?php echo esc_html__( 'Targets', 'wp-checksum-verifier' ); ?></h3>
			<?php $target_runs = WPCV_Plugin::target_run_repository()->find_all_by_run( $run_id ); ?>
			<?php if ( empty( $target_runs ) ) : ?>
				<p><?php echo esc_html__( 'No targets were recorded for this run.', 'wp-checksum-verifier' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Target', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Version', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Status', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Reason', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Files', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Findings', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Attempts', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Diff mode', 'wp-checksum-verifier' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $target_runs as $target_run ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $target_run['target_id'] ); ?></td>
								<td><?php echo esc_html( (string) ( $target_run['version'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) $target_run['status'] ); ?></td>
								<td><?php echo esc_html( self::target_run_reason_label( $target_run ) ); ?></td>
								<td>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: verified files, 2: total files. */
											__( '%1$d / %2$d', 'wp-checksum-verifier' ),
											(int) ( $target_run['files_verified'] ?? 0 ),
											(int) ( $target_run['files_total'] ?? 0 )
										)
									);
									?>
								</td>
								<td><?php echo esc_html( (string) ( $target_run['findings_total'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( (string) ( $target_run['attempt_count'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( empty( $target_run['diff_mode'] ) ? '—' : (string) $target_run['diff_mode'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php self::render_ended_findings( $run_id ); ?>
		</div>
		<?php
	}

	/**
	 * 「Findings ended in this run」節(v0.5後半 §16・§1.3)を描画する.
	 *
	 * `WPCV_Finding_Repository::query_ended_by_run()`でpagination付きに取得する
	 * (既存の一覧・詳細の`paged`と名前が衝突しないよう`ended_paged`を使う).
	 *
	 * @param int $run_id 対象のrunのid.
	 * @return void
	 */
	private static function render_ended_findings( $run_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 読み取り専用のクエリ引数のため(`render()`と同じ理由).
		$page = self::current_page_from_request( isset( $_GET['ended_paged'] ) ? wp_unslash( $_GET['ended_paged'] ) : null );

		$result = WPCV_Plugin::finding_repository()->query_ended_by_run( $run_id, $page, WPCV_Finding_Repository::DEFAULT_PER_PAGE );
		?>
		<h3><?php echo esc_html__( 'Findings ended in this run', 'wp-checksum-verifier' ); ?></h3>
		<?php if ( empty( $result['rows'] ) ) : ?>
			<p><?php echo esc_html__( 'No findings ended in this run.', 'wp-checksum-verifier' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Target', 'wp-checksum-verifier' ); ?></th>
					<th><?php echo esc_html__( 'Path', 'wp-checksum-verifier' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'wp-checksum-verifier' ); ?></th>
					<th><?php echo esc_html__( 'First seen in', 'wp-checksum-verifier' ); ?></th>
					<th><?php echo esc_html__( 'Reason', 'wp-checksum-verifier' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['rows'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['target_id'] ); ?></td>
						<td><?php echo esc_html( (string) $row['path'] ); ?></td>
						<td><?php echo esc_html( (string) $row['status'] ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( 'run_id', (int) $row['run_id'], self::page_url() ) ); ?>">
								#<?php echo esc_html( (string) $row['run_id'] ); ?>
							</a>
						</td>
						<td><?php echo esc_html( (string) $row['end_reason'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$total_pages = self::total_pages( $result['total'], WPCV_Finding_Repository::DEFAULT_PER_PAGE );

		if ( $total_pages > 1 ) :
			$ended_base = add_query_arg( 'run_id', $run_id, self::page_url() );
			?>
			<p class="tablenav-pages">
				<?php if ( $page > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'ended_paged', $page - 1, $ended_base ) ); ?>">
						<?php echo esc_html__( '« Previous', 'wp-checksum-verifier' ); ?>
					</a>
				<?php endif; ?>
				<?php
				printf(
					/* translators: 1: current page, 2: total pages. */
					esc_html__( 'Page %1$d of %2$d', 'wp-checksum-verifier' ),
					(int) $page,
					(int) $total_pages
				);
				?>
				<?php if ( $page < $total_pages ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'ended_paged', $page + 1, $ended_base ) ); ?>">
						<?php echo esc_html__( 'Next »', 'wp-checksum-verifier' ); ?>
					</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Target_run行の「理由」列に表示する文字列を組み立てる(`render()`から分離して
	 * テスト可能にする).
	 *
	 * `error_code`が設定されていれば`WPCV_Error_Code::all()`の説明文を、未知の
	 * error_codeであればコードそのものを、設定されていなければ空文字を返す
	 * (success等、理由を説明する必要がない状態のtarget_runでは空欄表示にする).
	 *
	 * @param array $target_run `WPCV_Target_Run_Repository::find_all_by_run()`の1行.
	 * @return string
	 */
	public static function target_run_reason_label( array $target_run ) {
		$error_code = isset( $target_run['error_code'] ) ? (string) $target_run['error_code'] : '';

		if ( '' === $error_code ) {
			return '';
		}

		$labels = WPCV_Error_Code::all();

		return isset( $labels[ $error_code ] ) ? $labels[ $error_code ] : $error_code;
	}

	/**
	 * 一覧・詳細の「Diff」列の表示文字列を組み立てる(v0.5後半 §16・§1.2).
	 *
	 * `alerting`/`done`(件数を持つ2状態)だけ`+新規 / −解消 / =継続`の形にする.
	 * それ以外(pending/processing/failed/NULL/skipped/未知の値)は`diff_status`の
	 * 値をそのまま出す(NULL・skippedは「—」にまとめる. `WPCV_Diff_Status`の
	 * 定数一覧に無い値も、`target_run_reason_label()`と同じ方針でそのまま出す).
	 *
	 * @param array $run `WPCV_Run_Repository::find_all()`/`find_by_id()`の1行.
	 * @return string
	 */
	public static function format_diff_summary( array $run ) {
		$diff_status = isset( $run['diff_status'] ) ? (string) $run['diff_status'] : '';

		if ( '' === $diff_status || WPCV_Diff_Status::SKIPPED === $diff_status ) {
			return '—';
		}

		if ( WPCV_Diff_Status::ALERTING !== $diff_status && WPCV_Diff_Status::DONE !== $diff_status ) {
			return $diff_status;
		}

		return sprintf(
			'+%1$s / −%2$s / =%3$s',
			self::nullable_count_label( $run, 'findings_new' ),
			self::nullable_count_label( $run, 'findings_resolved' ),
			self::nullable_count_label( $run, 'findings_continuing' )
		);
	}

	/**
	 * 「Alert」列の表示文字列を組み立てる(v0.5後半 §16・§1.2. `alert_status`の
	 * 値をそのまま出す. failed/abortedのrunで`alert_status`がrunの連続失敗
	 * アラート〔Step15a〕の結果でも、この列は分けない〔runのStatus列で区別できる〕).
	 *
	 * @param array $run `WPCV_Run_Repository::find_all()`/`find_by_id()`の1行.
	 * @return string
	 */
	public static function format_alert_status( array $run ) {
		return empty( $run['alert_status'] ) ? '—' : (string) $run['alert_status'];
	}

	/**
	 * 実行履歴詳細の「New / Resolved / Continuing」行の表示文字列を組み立てる
	 * (v0.5後半 §16・§1.3. 一覧の`format_diff_summary()`と異なり、`diff_status`に
	 * 関わらず3つの件数(NULLなら「—」)をそのまま並べる).
	 *
	 * @param array $run `WPCV_Run_Repository::find_by_id()`の戻り値.
	 * @return string
	 */
	public static function format_diff_counts( array $run ) {
		return sprintf(
			'%1$s / %2$s / %3$s',
			self::nullable_count_label( $run, 'findings_new' ),
			self::nullable_count_label( $run, 'findings_resolved' ),
			self::nullable_count_label( $run, 'findings_continuing' )
		);
	}

	/**
	 * `format_diff_summary()`用: NULLは0にせず「—」を返す(件数がNULLなのは
	 * 「まだ数えていない」であり、0件と区別するため).
	 *
	 * @param array  $run   Run行.
	 * @param string $field カラム名(`findings_new`等).
	 * @return string
	 */
	private static function nullable_count_label( array $run, $field ) {
		return isset( $run[ $field ] ) ? (string) (int) $run[ $field ] : '—';
	}

	/**
	 * `$_GET['paged']`相当の生値をpage番号(1始まり)へ変換する(`render()`から
	 * 分離してテスト可能にする)。数値化できない・1未満の値は1にclampする.
	 *
	 * @param mixed $raw `$_GET['paged']`相当の生値(未指定なら`null`).
	 * @return int
	 */
	public static function current_page_from_request( $raw ) {
		if ( null === $raw ) {
			return 1;
		}

		return max( 1, absint( $raw ) );
	}

	/**
	 * 全件数とper_pageから総ページ数を求める(`render_list()`から分離してテスト
	 * 可能にする)。全件数0件でも最低1ページとして扱う.
	 *
	 * @param int $total    全件数.
	 * @param int $per_page 1ページあたりの件数.
	 * @return int
	 */
	public static function total_pages( $total, $per_page ) {
		if ( $per_page <= 0 ) {
			return 1;
		}

		return max( 1, (int) ceil( $total / $per_page ) );
	}

	/**
	 * DATETIME文字列を表示用に整形する(未設定なら"—"を返す).
	 *
	 * @param string|null $value `wpcv_runs`/`wpcv_target_runs`のDATETIME列の値.
	 * @return string
	 */
	private static function display_datetime( $value ) {
		return empty( $value ) ? '—' : (string) $value;
	}

	/**
	 * この画面自身のURL(クエリ引数無し)を返す.
	 *
	 * @return string
	 */
	private static function page_url() {
		return menu_page_url( 'wpcv-runs', false );
	}

	/**
	 * この画面に必要なcapabilityを返す(`WPCV_Page_Settings::required_capability()`と
	 * 同じ判定。installation-levelのデータであるためマルチサイトではネットワーク
	 * 管理者権限を要求する).
	 *
	 * @return string
	 */
	private static function required_capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}
}
