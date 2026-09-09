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
 * REST時間予算の変更フォームを§Step8で追加した。以降のステップでトークン発行
 * (§Step9)のUIをここに追加していく.
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
	 * 画面を描画する.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::required_capability() ) ) {
			return;
		}

		$saved         = self::maybe_handle_save();
		$run_triggered = self::maybe_handle_run_now();

		$run_time         = WPCV_Settings::get_run_time();
		$rest_time_budget = WPCV_Settings::get_rest_time_budget_seconds();
		$button_state     = self::run_now_button_state( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Checksum Verifier', 'wp-checksum-verifier' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Settings saved.', 'wp-checksum-verifier' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $run_triggered ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'A verification run has been scheduled.', 'wp-checksum-verifier' ); ?></p>
				</div>
			<?php endif; ?>

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
								<?php echo esc_html__( 'The verification run starts automatically at this time every day (UTC).', 'wp-checksum-verifier' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wpcv_rest_time_budget"><?php echo esc_html__( 'REST run time budget (seconds)', 'wp-checksum-verifier' ); ?></label>
						</th>
						<td>
							<input type="number" min="1" max="<?php echo esc_attr( (string) WPCV_Settings::MAX_REST_TIME_BUDGET_SECONDS ); ?>" step="1" name="wpcv_rest_time_budget" id="wpcv_rest_time_budget" value="<?php echo esc_attr( (string) $rest_time_budget ); ?>" style="width: 6em;" />
							<p class="description">
								<?php echo esc_html__( 'How long a single POST /wp-json/wpcv/v1/run request may spend draining the queue. Always clamped to 70% of the server\'s max_execution_time.', 'wp-checksum-verifier' ); ?>
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

		$hour             = isset( $_POST['wpcv_run_hour'] ) ? absint( wp_unslash( $_POST['wpcv_run_hour'] ) ) : WPCV_Settings::DEFAULT_RUN_HOUR;
		$minute           = isset( $_POST['wpcv_run_minute'] ) ? absint( wp_unslash( $_POST['wpcv_run_minute'] ) ) : WPCV_Settings::DEFAULT_RUN_MINUTE;
		$rest_time_budget = isset( $_POST['wpcv_rest_time_budget'] ) ? absint( wp_unslash( $_POST['wpcv_rest_time_budget'] ) ) : WPCV_Settings::DEFAULT_REST_TIME_BUDGET_SECONDS;

		WPCV_Settings::update_run_time( $hour, $minute );
		WPCV_Settings::update_rest_time_budget_seconds( $rest_time_budget );
		WPCV_Scheduler::reschedule();

		return true;
	}

	/**
	 * 「今すぐ実行」フォームが POST されていれば nonce・capability・`DISABLE_WP_CRON`を
	 * 検証したうえで、単発イベントを即時(`time()`)で予約し `spawn_cron()` する.
	 *
	 * 同期実行はしない(§6.4: 管理画面のリクエストを検証の完了までブロックしない
	 * ため。Step6の`WPCV_Scheduler::HOOK`ハンドラを再利用するため、実際の検証は
	 * WP-Cronの通常の発火経路(`spawn_cron()`が起こす非同期HTTPリクエスト)を通る).
	 *
	 * @return bool 予約を実行したかどうか.
	 */
	private static function maybe_handle_run_now() {
		if ( ! isset( $_POST[ self::RUN_NOW_NONCE_NAME ] ) ) {
			return false;
		}

		check_admin_referer( self::RUN_NOW_NONCE_ACTION, self::RUN_NOW_NONCE_NAME );

		if ( ! current_user_can( self::required_capability() ) ) {
			return false;
		}

		if ( self::run_now_button_state( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )['disabled'] ) {
			return false;
		}

		wp_schedule_single_event( time(), WPCV_Scheduler::HOOK );
		spawn_cron();

		return true;
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
