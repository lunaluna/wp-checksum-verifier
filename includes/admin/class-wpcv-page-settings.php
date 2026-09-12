<?php
/**
 * WPCV_Page_Settings クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定画面.
 *
 * 実行時刻(UTC)の変更フォームをv0.3 §Step6で、「今すぐ実行」ボタンを§Step7で、
 * REST時間予算の変更フォームを§Step8で、RESTトークンの発行UIを§Step9で追加した
 * (v0.3計画の全9ステップの最後のUI追加)。§Step8のREST時間予算はv0.3.1 §Step4で
 * 廃止した(`WPCV_Settings` のクラス docblock 参照)。v0.4.0 §Step6で、外部HTTP
 * モード専用の時間予算(`external_http_time_budget_seconds`)を実行時刻フォームの
 * 下に追加した(キー名を変えており、廃止済みの旧設定とは別物)。v0.4.0 §Step7で
 * トークン発行UIを run/read の2 scope に分離した(`WPCV_Rest_Token` のクラス
 * docblock参照。既存の発行フォームは `SCOPE_RUN` のまま、`GET /status`・
 * `GET /findings` 用の `SCOPE_READ` トークンを発行する新しいフォームを追加した)。
 * v0.4.0 §Step10で状態パネル(`render_status_panel()`)を追加した。current_run/
 * last_run/next_scheduled_atは`WPCV_Rest_Status_Controller::handle_status()`を
 * 直接呼び出して再利用し(REST側とロジックを重複させない)、WP-Cron状態・
 * Action Scheduler可用性・最後にWP-CLIで実行した時刻はこのクラス自身で判定する。
 * v0.4.0コードレビューCR-10是正: strict mode(§Step8。`WPCV_Settings::
 * update_strict_mode()`/`WPCV_Suppression_Matcher`)はAPI・matcher側は実装済み
 * だったが、この保存フォームにチェックボックスと保存処理が無く通常操作では
 * 既定値`false`のまま変更できなかったため、実行時刻フォームの下に追加した.
 */
class WPCV_Page_Settings {

	/**
	 * 保存フォームの nonce action/name.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wpcv_save_settings';

	/**
	 * 保存フォームの nonce name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'wpcv_settings_nonce';

	/**
	 * 「今すぐ実行」フォームの nonce action.
	 *
	 * @var string
	 */
	const RUN_NOW_NONCE_ACTION = 'wpcv_run_now';

	/**
	 * 「今すぐ実行」フォームの nonce name.
	 *
	 * @var string
	 */
	const RUN_NOW_NONCE_NAME = 'wpcv_run_now_nonce';

	/**
	 * Run scopeトークン発行フォームの nonce action.
	 *
	 * @var string
	 */
	const TOKEN_NONCE_ACTION = 'wpcv_generate_token';

	/**
	 * Run scopeトークン発行フォームの nonce name.
	 *
	 * @var string
	 */
	const TOKEN_NONCE_NAME = 'wpcv_token_nonce';

	/**
	 * Read scopeトークン発行フォームの nonce action(v0.4.0 §Step7).
	 *
	 * @var string
	 */
	const READ_TOKEN_NONCE_ACTION = 'wpcv_generate_read_token';

	/**
	 * Read scopeトークン発行フォームの nonce name(v0.4.0 §Step7).
	 *
	 * @var string
	 */
	const READ_TOKEN_NONCE_NAME = 'wpcv_read_token_nonce';

	/**
	 * 画面を描画する.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::required_capability() ) ) {
			return;
		}

		$saved                = self::maybe_handle_save();
		$run_now_result       = self::maybe_handle_run_now();
		$generated_run_token  = self::maybe_handle_generate_token( self::TOKEN_NONCE_NAME, self::TOKEN_NONCE_ACTION, WPCV_Rest_Token::SCOPE_RUN );
		$generated_read_token = self::maybe_handle_generate_token( self::READ_TOKEN_NONCE_NAME, self::READ_TOKEN_NONCE_ACTION, WPCV_Rest_Token::SCOPE_READ );

		$run_time                          = WPCV_Settings::get_run_time();
		$external_http_time_budget_seconds = WPCV_Settings::get_external_http_time_budget_seconds();
		$strict_mode                       = WPCV_Settings::get_strict_mode();
		$button_state                      = self::run_now_button_state( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Checksum Verifier', 'wp-checksum-verifier' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Settings saved.', 'wp-checksum-verifier' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( true === $run_now_result ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'A verification run has been scheduled.', 'wp-checksum-verifier' ); ?></p>
				</div>
			<?php elseif ( is_array( $run_now_result ) ) : ?>
				<div class="notice notice-info is-dismissible">
					<p><?php echo esc_html( self::format_active_run_notice( $run_now_result['run_id'] ) ); ?></p>
				</div>
			<?php elseif ( is_wp_error( $run_now_result ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $run_now_result->get_error_message() ); ?></p>
				</div>
			<?php endif; ?>

			<?php self::render_status_panel(); ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wpcv_run_hour"><?php echo esc_html__( 'Daily run time (UTC)', 'wp-checksum-verifier' ); ?></label>
						</th>
						<td>
							<input type="number" min="0" max="23" step="1" name="wpcv_run_hour" id="wpcv_run_hour" value="<?php echo esc_attr( (string) $run_time['hour'] ); ?>" style="width: 4em;" />
							:
							<input type="number" min="0" max="59" step="1" name="wpcv_run_minute" id="wpcv_run_minute" value="<?php echo esc_attr( (string) $run_time['minute'] ); ?>" style="width: 4em;" />
							<p class="description">
								<?php echo esc_html__( 'The verification run starts automatically at this time every day (UTC). External HTTP mode (below) also uses this time to decide when to start the daily run.', 'wp-checksum-verifier' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpcv_external_http_time_budget_seconds"><?php echo esc_html__( 'External HTTP time budget (seconds)', 'wp-checksum-verifier' ); ?></label>
						</th>
						<td>
							<input type="number" min="5" max="55" step="1" name="wpcv_external_http_time_budget_seconds" id="wpcv_external_http_time_budget_seconds" value="<?php echo esc_attr( (string) $external_http_time_budget_seconds ); ?>" style="width: 5em;" />
							<p class="description">
								<?php echo esc_html__( 'When an external scheduler calls POST /wp-json/wpcv/v1/run, this is how long (per request) it keeps advancing the run before returning. Keep it well under your host\'s max_execution_time.', 'wp-checksum-verifier' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php echo esc_html__( 'Strict mode', 'wp-checksum-verifier' ); ?>
						</th>
						<td>
							<label for="wpcv_strict_mode">
								<input type="checkbox" name="wpcv_strict_mode" id="wpcv_strict_mode" value="1" <?php checked( $strict_mode ); ?> />
								<?php echo esc_html__( 'Report readme.txt / readme.md changes as findings instead of suppressing them.', 'wp-checksum-verifier' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'By default, changes limited to readme.txt/readme.md are treated as a low-risk "soft change" and suppressed automatically. Enable strict mode to see every difference, including those files.', 'wp-checksum-verifier' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Changes', 'wp-checksum-verifier' ) ); ?>
			</form>

			<h2><?php echo esc_html__( 'Run now', 'wp-checksum-verifier' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::RUN_NOW_NONCE_ACTION, self::RUN_NOW_NONCE_NAME ); ?>
				<p class="description">
					<?php echo esc_html__( 'Start a verification run immediately instead of waiting for the daily schedule.', 'wp-checksum-verifier' ); ?>
				</p>
				<?php if ( $button_state['notice'] ) : ?>
					<p class="description"><?php echo esc_html( $button_state['notice'] ); ?></p>
				<?php endif; ?>
				<?php
				submit_button(
					__( 'Run now', 'wp-checksum-verifier' ),
					'secondary',
					'wpcv_run_now_submit',
					true,
					$button_state['disabled'] ? array( 'disabled' => 'disabled' ) : array()
				);
				?>
			</form>

			<h2><?php echo esc_html__( 'REST API token (run)', 'wp-checksum-verifier' ); ?></h2>
			<?php if ( null !== $generated_run_token ) : ?>
				<div class="notice notice-success">
					<p>
						<strong><?php echo esc_html__( 'New token generated. Copy it now — it will not be shown again:', 'wp-checksum-verifier' ); ?></strong>
					</p>
					<p><code><?php echo esc_html( $generated_run_token ); ?></code></p>
				</div>
			<?php endif; ?>
			<p class="description">
				<?php if ( defined( 'WPCV_REST_TOKEN' ) ) : ?>
					<?php echo esc_html__( 'A token is defined via the WPCV_REST_TOKEN constant and takes precedence over any token generated here.', 'wp-checksum-verifier' ); ?>
				<?php elseif ( WPCV_Rest_Token::has_stored_token( WPCV_Rest_Token::SCOPE_RUN ) ) : ?>
					<?php echo esc_html__( 'A token has been issued. Generating a new one immediately invalidates the previous token.', 'wp-checksum-verifier' ); ?>
				<?php else : ?>
					<?php echo esc_html__( 'No token has been issued yet. External systems need this token to call POST /wp-json/wpcv/v1/run.', 'wp-checksum-verifier' ); ?>
				<?php endif; ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( self::TOKEN_NONCE_ACTION, self::TOKEN_NONCE_NAME ); ?>
				<?php submit_button( __( 'Generate new token', 'wp-checksum-verifier' ), 'secondary', 'wpcv_generate_token_submit' ); ?>
			</form>

			<h2><?php echo esc_html__( 'REST API token (read-only)', 'wp-checksum-verifier' ); ?></h2>
			<?php if ( null !== $generated_read_token ) : ?>
				<div class="notice notice-success">
					<p>
						<strong><?php echo esc_html__( 'New token generated. Copy it now — it will not be shown again:', 'wp-checksum-verifier' ); ?></strong>
					</p>
					<p><code><?php echo esc_html( $generated_read_token ); ?></code></p>
				</div>
			<?php endif; ?>
			<p class="description">
				<?php if ( WPCV_Rest_Token::has_stored_token( WPCV_Rest_Token::SCOPE_READ ) ) : ?>
					<?php echo esc_html__( 'A read-only token has been issued. Generating a new one immediately invalidates the previous one.', 'wp-checksum-verifier' ); ?>
				<?php else : ?>
					<?php echo esc_html__( 'No read-only token has been issued yet. External systems need this (separate) token to call GET /wp-json/wpcv/v1/status and GET /wp-json/wpcv/v1/findings — it cannot start a run.', 'wp-checksum-verifier' ); ?>
				<?php endif; ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( self::READ_TOKEN_NONCE_ACTION, self::READ_TOKEN_NONCE_NAME ); ?>
				<?php submit_button( __( 'Generate new read-only token', 'wp-checksum-verifier' ), 'secondary', 'wpcv_generate_read_token_submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * 「今すぐ実行」ボタンの表示状態を判定する.
	 *
	 * `DISABLE_WP_CRON` 定数を直接読まず引数で受け取る形にしている(レンダリングから
	 * 分岐ロジックを分離してテストするため。定数は一度定義すると PHP の言語仕様上
	 * 未定義に戻せず、テストごとに値を変えられない. `WPCV_Runner_Async::enqueue_run()`
	 * の可用性チェッカー注入と同じ考え方).
	 *
	 * @param bool $wp_cron_disabled `DISABLE_WP_CRON` が真かどうか.
	 * @return array{disabled: bool, notice: string|null}
	 */
	public static function run_now_button_state( $wp_cron_disabled ) {
		if ( $wp_cron_disabled ) {
			return array(
				'disabled' => true,
				'notice'   => __( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Use WP-CLI (`wp wpcv run`) or the REST endpoint instead.', 'wp-checksum-verifier' ),
			);
		}

		return array(
			'disabled' => false,
			'notice'   => null,
		);
	}

	/**
	 * フォームが POST されていれば nonce・capability を検証したうえで実行時刻を
	 * 保存し、WP-Cron の予約を新しい時刻に更新する.
	 *
	 * `check_admin_referer()` による nonce 検証と `$_POST` の読み取りを同じ
	 * メソッド内で行う(呼び出し元の `render()` 側で検証済みという前提を作らない。
	 * PHPCS の nonce 検証チェックが呼び出し元をまたいだ検証を追跡できないための
	 * 設計でもある)。`check_admin_referer()` は検証に失敗すると `wp_die()` で
	 * 処理を終了する(戻り値を見て分岐する必要が無い. 戻り値を否定して分岐すると
	 * 「`wp_die()` が never 型のため常に false」と PHPStan に判定される).
	 *
	 * @return bool 保存を実行したかどうか(POST されていない・capability
	 *              検証に失敗した場合は false. nonce 検証失敗時は `wp_die()` で
	 *              終了するためここには到達しない).
	 */
	private static function maybe_handle_save() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return false;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		if ( ! current_user_can( self::required_capability() ) ) {
			return false;
		}

		$hour   = isset( $_POST['wpcv_run_hour'] ) ? absint( wp_unslash( $_POST['wpcv_run_hour'] ) ) : WPCV_Settings::DEFAULT_RUN_HOUR;
		$minute = isset( $_POST['wpcv_run_minute'] ) ? absint( wp_unslash( $_POST['wpcv_run_minute'] ) ) : WPCV_Settings::DEFAULT_RUN_MINUTE;

		WPCV_Settings::update_run_time( $hour, $minute );
		WPCV_Scheduler::reschedule();

		$time_budget_seconds = isset( $_POST['wpcv_external_http_time_budget_seconds'] )
			? absint( wp_unslash( $_POST['wpcv_external_http_time_budget_seconds'] ) )
			: WPCV_Settings::DEFAULT_EXTERNAL_HTTP_TIME_BUDGET_SECONDS;

		WPCV_Settings::update_external_http_time_budget_seconds( $time_budget_seconds );

		// v0.4.0コードレビューCR-10是正: strict modeはAPI(`WPCV_Settings::
		// update_strict_mode()`)・matcher(`WPCV_Suppression_Matcher`)側は
		// v0.4.0 §Step8から実装済みだったが、この保存フォームに入力欄・保存処理が
		// 無く、通常の管理画面操作では既定値`false`のまま変更できなかった
		// (レビュー指摘)。チェックボックスは未チェック時に`$_POST`へキー自体が
		// 送られてこないため、`isset()`の有無だけで有効・無効を判定できる
		// (`wpcv_run_hour`等の数値項目のような「未送信時は既定値を使う」フォールバックは
		// 不要).
		WPCV_Settings::update_strict_mode( isset( $_POST['wpcv_strict_mode'] ) );

		return true;
	}

	/**
	 * 「今すぐ実行」フォームが POST されていれば nonce・capability・`DISABLE_WP_CRON`を
	 * 検証したうえで、`WPCV_Scheduler::MANUAL_HOOK` の単発イベントを即時(`time()`)で
	 * 予約し `spawn_cron()` する.
	 *
	 * 同期実行はしない(§6.4: 管理画面のリクエストを検証の完了までブロックしない
	 * ため。実際の検証はWP-Cronの通常の発火経路(`spawn_cron()`が起こす非同期HTTP
	 * リクエスト)を通る)。定時実行用の `WPCV_Scheduler::HOOK` ではなく専用の
	 * `MANUAL_HOOK` を使う理由と、予約結果(`$wp_error = true` で `WP_Error` を
	 * 受け取る)を検査する理由は `WPCV_Scheduler::MANUAL_HOOK` の docblock参照
	 * (v0.3.1 §Step3。プラン§P1「「今すぐ実行」が定時イベントと衝突する」
	 * 「予約結果を検査しないため、失敗しても成功noticeを出す」への対策).
	 *
	 * v0.4.0 §Step5: 予約の前に active な run(`queued`/`running`)が無いかを確認する
	 * ようにした。以前は無条件に `MANUAL_HOOK` を予約していたため、既に実行中の
	 * runがある状態でクリックすると、後から`WPCV_Run_Repository::reserve_run()`の
	 * advisory lock内で黙って弾かれるだけで、管理画面には「予約しました」としか
	 * 出ない不整合があった(プラン§Step5「active runがあれば既存runを表示する」)。
	 *
	 * Active runの有無を見る前に `WPCV_Chunk_Dispatcher::sweep_deadline_and_expired_leases()`
	 * を呼ぶのは、stale化した run を active と誤判定して新規実行をブロックし
	 * 続けないため(v0.4.0コードレビューCR-07是正: 旧`sweep_stale_running()`
	 * 〔`started_at`基準・既定180分〕は、6時間`deadline_at`+target leaseの下で
	 * 正常に進行中のchunk実行runを誤って`failed`にしてしまう競合があったため、
	 * `deadline_at`基準の判定へ切り替えた。詳細は同メソッドのdocblock参照).
	 *
	 * @return true|array{run_id: int}|WP_Error|null 予約に成功すれば `true`、
	 *         既にactiveなrunがあれば`{run_id}`、予約自体が失敗すれば `WP_Error`。
	 *         POST されていない・capability検証に失敗した・`DISABLE_WP_CRON` で
	 *         無効化されている場合は `null`(何もnoticeを表示しない).
	 */
	private static function maybe_handle_run_now() {
		if ( ! isset( $_POST[ self::RUN_NOW_NONCE_NAME ] ) ) {
			return null;
		}

		check_admin_referer( self::RUN_NOW_NONCE_ACTION, self::RUN_NOW_NONCE_NAME );

		if ( ! current_user_can( self::required_capability() ) ) {
			return null;
		}

		if ( self::run_now_button_state( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )['disabled'] ) {
			return null;
		}

		$run_repository = WPCV_Plugin::run_repository();
		$active_run     = $run_repository->find_active_run_id();

		if ( null !== $active_run ) {
			WPCV_Plugin::chunk_dispatcher()->sweep_deadline_and_expired_leases( $active_run );
			// sweepでdeadline超過により aborted 化された可能性があるため、
			// 表示に使う前に active run の有無を読み直す.
			$active_run = $run_repository->find_active_run_id();
		}

		if ( null !== $active_run ) {
			// find_active_run_id() は id のみ返す(実際の status(queued|running)までは
			// 分からない)。利用者にとって重要なのは「もう1つ動いている」という
			// 事実であり内部状態の区別ではないため、案内文もidのみで組み立てる.
			return array( 'run_id' => $active_run );
		}

		$scheduled = wp_schedule_single_event( time(), WPCV_Scheduler::MANUAL_HOOK, array(), true );

		if ( is_wp_error( $scheduled ) ) {
			return $scheduled;
		}

		spawn_cron();

		return true;
	}

	/**
	 * Active runの案内文を組み立てる(`maybe_handle_run_now()` から分離してテスト可能にする).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return string
	 */
	public static function format_active_run_notice( $run_id ) {
		return sprintf(
			/* translators: %d: run id. */
			__( 'A verification run (#%d) is already in progress. Please wait for it to finish.', 'wp-checksum-verifier' ),
			(int) $run_id
		);
	}

	/**
	 * トークン発行フォームが POST されていれば nonce・capability を検証したうえで
	 * 新しいトークンを生成する(v0.4.0 §Step7: run/read 2つのフォームで共有できる
	 * よう nonce・scope を引数化した).
	 *
	 * @param string $nonce_name   このフォームの nonce name(`$_POST` のキー).
	 * @param string $nonce_action このフォームの nonce action.
	 * @param string $scope        `WPCV_Rest_Token::SCOPE_RUN` または `SCOPE_READ`.
	 * @return string|null 生成した平文トークン(1回だけ画面に表示するため呼び出し元が
	 *                      保持する). POST されていない・検証に失敗した場合は `null`.
	 */
	private static function maybe_handle_generate_token( $nonce_name, $nonce_action, $scope ) {
		if ( ! isset( $_POST[ $nonce_name ] ) ) {
			return null;
		}

		check_admin_referer( $nonce_action, $nonce_name );

		if ( ! current_user_can( self::required_capability() ) ) {
			return null;
		}

		return WPCV_Rest_Token::generate( $scope );
	}

	/**
	 * 状態パネル(v0.4.0 §Step10)を描画する.
	 *
	 * `GET /wp-json/wpcv/v1/status`(`WPCV_Rest_Status_Controller`)が計算する
	 * current_run/last_run/next_scheduled_at を、実際にHTTPを経由せず
	 * `handle_status()` を直接呼び出して再利用する(引数は内部で使われないため
	 * 空の `WP_REST_Request` を渡すだけでよい。target集計・heartbeat・deadlineの
	 * 計算ロジックをこのクラスへ複製せずに済む。認証はREST側の`SCOPE_READ`
	 * トークンではなく、この画面自体の`current_user_can()`チェック〔`render()`
	 * 冒頭〕がすでに担っているため、権限確認は二重に不要).
	 *
	 * @return void
	 */
	private static function render_status_panel() {
		$status = WPCV_Rest_Status_Controller::handle_status( new WP_REST_Request() )->get_data();

		$as_available  = self::action_scheduler_available();
		$wp_cron_state = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
			? __( 'Disabled (DISABLE_WP_CRON)', 'wp-checksum-verifier' )
			: __( 'Enabled', 'wp-checksum-verifier' );

		$last_cli_run = WPCV_Plugin::run_repository()->find_most_recent_by_trigger( 'cli' );
		?>
		<h2><?php echo esc_html__( 'Status', 'wp-checksum-verifier' ); ?></h2>
		<table class="widefat" style="max-width: 640px;">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Current run', 'wp-checksum-verifier' ); ?></th>
					<td><?php echo esc_html( self::format_run_summary( $status['current_run'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Last completed run', 'wp-checksum-verifier' ); ?></th>
					<td><?php echo esc_html( self::format_run_summary( $status['last_run'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Next scheduled run', 'wp-checksum-verifier' ); ?></th>
					<td><?php echo esc_html( (string) $status['next_scheduled_at'] ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'WP-Cron', 'wp-checksum-verifier' ); ?></th>
					<td><?php echo esc_html( $wp_cron_state ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Action Scheduler', 'wp-checksum-verifier' ); ?></th>
					<td>
						<?php echo esc_html( $as_available ? __( 'Available', 'wp-checksum-verifier' ) : __( 'Not available (async run falls back to a synchronous run)', 'wp-checksum-verifier' ) ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Last WP-CLI run', 'wp-checksum-verifier' ); ?></th>
					<td>
						<?php
						echo esc_html(
							null === $last_cli_run
								? __( 'Never observed on this site (this only reflects runs recorded here, not whether WP-CLI is installed).', 'wp-checksum-verifier' )
								: (string) $last_cli_run['started_at']
						);
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * `render_status_panel()`用に、1件のrun(`WPCV_Rest_Status_Controller::handle_status()`
	 * が返す`current_run`/`last_run`の形)を1行の表示文字列へ整形する
	 * (`render()`から分離してテスト可能にする).
	 *
	 * @param array|null $run `null`・`current_run`・`last_run`のいずれか.
	 * @return string
	 */
	public static function format_run_summary( $run ) {
		if ( null === $run ) {
			return __( 'None', 'wp-checksum-verifier' );
		}

		$targets = $run['targets'];

		return sprintf(
			/* translators: 1: run id, 2: status, 3: pending (queued) target count, 4: retry target count, 5: findings count, 6: last activity timestamp or dash. */
			__( '#%1$d (%2$s) — pending: %3$d, retry: %4$d, findings: %5$d, last activity: %6$s', 'wp-checksum-verifier' ),
			(int) $run['run_id'],
			(string) $run['status'],
			(int) $targets['queued'],
			(int) $targets['retry'],
			(int) $run['findings_total'],
			null === $run['last_activity_at'] ? '—' : (string) $run['last_activity_at']
		);
	}

	/**
	 * Action Schedulerが利用可能かどうかを判定する(`WPCV_Runner_Async::enqueue_run()`
	 * の既定の可用性チェックと同じ条件。あちらはテストでのプロセス内関数再定義の
	 * 制約から`callable`注入にしているが、この状態パネルは表示のみで注入の必要が
	 * 無いため、同じ判定をここでも直接書く).
	 *
	 * @return bool
	 */
	private static function action_scheduler_available() {
		return function_exists( 'as_enqueue_async_action' )
			&& class_exists( 'ActionScheduler' )
			&& ActionScheduler::is_initialized();
	}

	/**
	 * この画面に必要な capability を返す.
	 *
	 * @return string
	 */
	private static function required_capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}
}
