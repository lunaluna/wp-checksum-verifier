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
 * 実行時刻(UTC)の変更フォームをv0.3 §Step6で追加した。以降のステップで
 * 「今すぐ実行」ボタン(§Step7)・REST時間予算(§Step8)・トークン発行(§Step9)の
 * UIをここに追加していく.
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
	 * 画面を描画する.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::required_capability() ) ) {
			return;
		}

		$saved = self::maybe_handle_save();

		$run_time = WPCV_Settings::get_run_time();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Checksum Verifier', 'wp-checksum-verifier' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Settings saved.', 'wp-checksum-verifier' ); ?></p>
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
				</table>
				<?php submit_button( __( 'Save Changes', 'wp-checksum-verifier' ) ); ?>
			</form>
		</div>
		<?php
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
