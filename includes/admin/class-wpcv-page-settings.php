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
 * 実行時刻(v0.10.0 以降はサイトのタイムゾーン)の変更フォームをv0.3 §Step6で、「今すぐ実行」ボタンを§Step7で、
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
 * v0.5後半 §Step14aでアラートの宛先(`alert_to`)フォームを追加し、§Step14dで
 * 「Send test alert」ボタン(`WPCV_Alert_Sender::send_test()`を同期的に呼ぶ)を
 * 追加した.
 * v0.8 §Step7で、GitHub リポジトリとの対応付け(`github_mappings`. テキストエリア・
 * 保存時の検査結果・コア同梱テーマの警告)と、`WPCV_GITHUB_TOKEN` の有無の表示を追加した.
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
	 * 「古い履歴を今すぐ削除」フォームの nonce action(v0.10.0).
	 *
	 * @var string
	 */
	const PRUNE_NONCE_ACTION = 'wpcv_prune_history';

	/**
	 * 「古い履歴を今すぐ削除」フォームの nonce name(v0.10.0).
	 *
	 * @var string
	 */
	const PRUNE_NONCE_NAME = 'wpcv_prune_history_nonce';

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
	 * 「Send test alert」フォームの nonce action(v0.5後半 §Step14d).
	 *
	 * @var string
	 */
	const SEND_TEST_ALERT_NONCE_ACTION = 'wpcv_send_test_alert';

	/**
	 * 「Send test alert」フォームの nonce name(v0.5後半 §Step14d).
	 *
	 * @var string
	 */
	const SEND_TEST_ALERT_NONCE_NAME = 'wpcv_send_test_alert_nonce';

	/**
	 * 直近の保存で捨てた対応付けの行(`WPCV_GitHub_Mappings::parse_text()` の `errors`).
	 *
	 * 保存処理(`maybe_handle_save()`)と描画(`render()`)は同じリクエストの中で続けて
	 * 呼ばれるので、戻り値の型(bool)を変えずに受け渡すため静的に持つ.
	 *
	 * @var array<int, array{line: int, reason: string}>
	 */
	private static $github_mapping_errors = array();

	/**
	 * 画面を描画する.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( WPCV_Capability::required( WPCV_Capability::SCREEN_SETTINGS ) ) ) {
			return;
		}

		$saved                = self::maybe_handle_save();
		$run_now_result       = self::maybe_handle_run_now();
		$generated_run_token  = self::maybe_handle_generate_token( self::TOKEN_NONCE_NAME, self::TOKEN_NONCE_ACTION, WPCV_Rest_Token::SCOPE_RUN );
		$generated_read_token = self::maybe_handle_generate_token( self::READ_TOKEN_NONCE_NAME, self::READ_TOKEN_NONCE_ACTION, WPCV_Rest_Token::SCOPE_READ );
		$test_alert_result    = self::maybe_handle_send_test_alert();
		$prune_result         = self::maybe_handle_prune();

		$run_time                          = WPCV_Settings::get_run_time();
		$external_http_time_budget_seconds = WPCV_Settings::get_external_http_time_budget_seconds();
		$strict_mode                       = WPCV_Settings::get_strict_mode();
		$stat_detection                    = WPCV_Settings::get_stat_detection_enabled();
		$content_hash_stat_targets_enabled = WPCV_Settings::get_content_hash_stat_targets_enabled();
		$alert_unrecorded_version_change   = WPCV_Settings::get_alert_unrecorded_version_change_enabled();
		$retention_months                  = WPCV_Settings::get_retention_months();
		$alert_to                          = WPCV_Settings::get_alert_to();
		$github_mappings_text              = WPCV_GitHub_Mappings::format_text( WPCV_Settings::get_github_mappings() );
		$github_resolved                   = WPCV_GitHub_Mappings::resolve();
		$github_filter_only                = self::filter_only_mappings( $github_resolved, WPCV_Settings::get_github_mappings() );
		$github_bundled_themes             = self::find_core_bundled_themes( $github_resolved, self::load_cached_core_files() );
		$github_has_token                  = WPCV_GitHub_Client::has_token();
		$github_rate_limited_until         = ( new WPCV_GitHub_Client() )->get_rate_limited_until();
		$button_state                      = self::run_now_button_state( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Checksum Verifier', 'wp-checksum-verifier' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Settings saved.', 'wp-checksum-verifier' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( array() !== self::$github_mapping_errors ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php echo esc_html__( 'Some GitHub repository mappings were not saved:', 'wp-checksum-verifier' ); ?></p>
					<ul>
						<?php foreach ( self::$github_mapping_errors as $mapping_error ) : ?>
							<li><?php echo esc_html( self::format_mapping_error( $mapping_error['line'], $mapping_error['reason'] ) ); ?></li>
						<?php endforeach; ?>
					</ul>
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

			<?php
			// 「古い履歴を今すぐ削除」の結果(v0.10.0).
			$prune_notice = self::prune_result_notice( $prune_result );
			?>
			<?php if ( null !== $prune_notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $prune_notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $prune_notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php self::render_status_panel(); ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wpcv_run_hour">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: site time zone name (e.g. Asia/Tokyo or +09:00). */
										__( 'Daily run time (site time zone: %s)', 'wp-checksum-verifier' ),
										WPCV_Settings::site_timezone()->getName()
									)
								);
								?>
							</label>
						</th>
						<td>
							<input type="number" min="0" max="23" step="1" name="wpcv_run_hour" id="wpcv_run_hour" value="<?php echo esc_attr( self::format_two_digits( $run_time['hour'] ) ); ?>" style="width: 4em;" />
							:
							<input type="number" min="0" max="59" step="1" name="wpcv_run_minute" id="wpcv_run_minute" value="<?php echo esc_attr( self::format_two_digits( $run_time['minute'] ) ); ?>" style="width: 4em;" />
							<p class="description">
								<?php echo esc_html__( 'The verification run starts automatically at this time every day, in the site time zone (Settings > General). External HTTP mode (below) also uses this time to decide when to start the daily run.', 'wp-checksum-verifier' ); ?>
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
					<?php // v0.5 §Step8: stat 差分検知の有効・無効. ?>
					<tr>
						<th scope="row">
							<?php echo esc_html__( 'Stat-based change detection', 'wp-checksum-verifier' ); ?>
						</th>
						<td>
							<label for="wpcv_stat_detection">
								<input type="checkbox" name="wpcv_stat_detection" id="wpcv_stat_detection" value="1" <?php checked( $stat_detection ); ?> />
								<?php echo esc_html__( 'Track file size and timestamps of plugins that cannot be verified against checksums, and report changes since the previous run.', 'wp-checksum-verifier' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Applies to custom or premium plugins and mu-plugin loaders that have no official checksums. The first run only records a baseline. When a plugin version changes, its baseline is rebuilt without reporting changes. File contents are not read unless content-hash comparison is also enabled below.', 'wp-checksum-verifier' ); ?>
							</p>
						</td>
					</tr>
					<?php // v0.6 §Step11: 既存のstat targetの内容ハッシュ(層2)のオプトイン(プランU4・§5.3 L6). ?>
					<tr>
						<th scope="row">
							<?php echo esc_html__( 'Content-hash comparison for custom plugins', 'wp-checksum-verifier' ); ?>
						</th>
						<td>
							<label for="wpcv_content_hash_stat_targets">
								<input type="checkbox" name="wpcv_content_hash_stat_targets" id="wpcv_content_hash_stat_targets" value="1" <?php checked( $content_hash_stat_targets_enabled ); ?> />
								<?php echo esc_html__( 'Also compute a content hash for custom/premium plugins and mu-plugin loaders, and report a change even when file size and modified time stay the same.', 'wp-checksum-verifier' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Detects a same-size rewrite that preserves the timestamp, which size/timestamp tracking alone cannot catch. This reads file contents on every run and adds I/O cost, so it is off by default; consider enabling it only for sites with a small number of custom plugins. Files larger than a fixed size limit fall back to size/timestamp tracking only.', 'wp-checksum-verifier' ); ?>
							</p>
						</td>
					</tr>
					<?php // v0.6 §Step7: 更新機構を通らないversion変化の通知(プランU3・§3.1). ?>
					<tr>
						<th scope="row">
							<?php echo esc_html__( 'Unrecorded version change alerts', 'wp-checksum-verifier' ); ?>
						</th>
						<td>
							<label for="wpcv_alert_unrecorded_version_change">
								<input type="checkbox" name="wpcv_alert_unrecorded_version_change" id="wpcv_alert_unrecorded_version_change" value="1" <?php checked( $alert_unrecorded_version_change ); ?> />
								<?php echo esc_html__( 'Alert on version changes that did not go through the WordPress updater.', 'wp-checksum-verifier' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Turn this off on sites that deploy via git, FTP, or Composer, where legitimate version changes never produce an update event.', 'wp-checksum-verifier' ); ?>
							</p>
						</td>
					</tr>
					<?php // v0.9 §Step2: 履歴の保持期間(プラン §3.1・U5). 既定は 12 か月(v0.10.0 で無期限から変更. 無期限も選べる). ?>
					<tr>
						<th scope="row">
							<label for="wpcv_retention_months"><?php echo esc_html__( 'History retention', 'wp-checksum-verifier' ); ?></label>
						</th>
						<td>
							<select name="wpcv_retention_months" id="wpcv_retention_months">
								<?php foreach ( WPCV_Settings::RETENTION_MONTHS_CHOICES as $choice ) : ?>
									<option value="<?php echo esc_attr( (string) $choice ); ?>" <?php selected( $retention_months, $choice ); ?>>
										<?php echo esc_html( self::retention_choice_label( $choice ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php echo esc_html__( 'Delete run history, per-target results and findings older than this period. The newest verified result of each target, results still being processed, and records needed to avoid repeating an alert are always kept. The default is 12 months; choose "Keep forever" to keep everything. Older history is removed gradually at the end of the following runs.', 'wp-checksum-verifier' ); ?>
							</p>
						</td>
					</tr>
					<?php // v0.8 §Step7: GitHub リポジトリとの対応付け(プラン §4.1・U2)と、トークンの有無(U3). ?>
					<tr>
						<th scope="row">
							<label for="wpcv_github_mappings"><?php echo esc_html__( 'GitHub repository mappings', 'wp-checksum-verifier' ); ?></label>
						</th>
						<td>
							<textarea name="wpcv_github_mappings" id="wpcv_github_mappings" rows="4" cols="50" class="large-text code" placeholder="plugin:my-plugin owner/my-plugin"><?php echo esc_textarea( $github_mappings_text ); ?></textarea>
							<p class="description">
								<?php echo esc_html__( 'Verify a plugin or theme against the assets of its GitHub Release instead of wordpress.org. One mapping per line: plugin:{slug} or theme:{stylesheet}, then owner/repo, then optionally the start of the asset file name. The Release is found by the installed version (tag "1.2.3" or "v1.2.3"). Plugins and themes that are not listed are verified as before. Invalid lines and repeated targets are dropped when saving; comment lines (starting with #) are not kept. Directories that contain a .git entry are never compared with GitHub.', 'wp-checksum-verifier' ); ?>
							</p>
							<?php if ( array() !== $github_filter_only ) : ?>
								<p class="description">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: comma-separated list of "target → owner/repo". */
											__( 'Also mapped by the wpcv_github_mappings filter: %s', 'wp-checksum-verifier' ),
											implode( ', ', $github_filter_only )
										)
									);
									?>
								</p>
							<?php endif; ?>
							<?php if ( array() !== $github_bundled_themes ) : ?>
								<p class="description">
									<strong><?php echo esc_html__( 'Warning:', 'wp-checksum-verifier' ); ?></strong>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: comma-separated list of theme stylesheets. */
											__( 'These themes are bundled with WordPress core, so their mappings are ignored and they are verified against wordpress.org and the core checksums: %s', 'wp-checksum-verifier' ),
											implode( ', ', $github_bundled_themes )
										)
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'GitHub token', 'wp-checksum-verifier' ); ?></th>
						<td>
							<?php if ( $github_has_token ) : ?>
								<?php echo esc_html__( 'A token is configured (WPCV_GITHUB_TOKEN or the wpcv_github_token filter). Its value is never shown or stored in the database.', 'wp-checksum-verifier' ); ?>
							<?php else : ?>
								<?php echo esc_html__( 'No token is configured.', 'wp-checksum-verifier' ); ?>
								<p class="description">
									<?php echo esc_html__( 'Public repositories work without one, but unauthenticated GitHub API requests have a low rate limit. For private repositories or a higher limit, define WPCV_GITHUB_TOKEN in wp-config.php.', 'wp-checksum-verifier' ); ?>
								</p>
							<?php endif; ?>
							<?php if ( null !== $github_rate_limited_until ) : ?>
								<p class="description">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: date and time in the site time zone. */
											__( 'The GitHub rate limit has been reached. GitHub is not contacted until %s.', 'wp-checksum-verifier' ),
											WPCV_Settings::format_datetime( (int) $github_rate_limited_until )
										)
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<?php // v0.5後半 §Step14: アラートの宛先. 空なら送らず、管理画面に警告を出す(プラン U1). ?>
					<tr>
						<th scope="row">
							<label for="wpcv_alert_to"><?php echo esc_html__( 'Alert recipients', 'wp-checksum-verifier' ); ?></label>
						</th>
						<td>
							<textarea name="wpcv_alert_to" id="wpcv_alert_to" rows="3" cols="50" class="large-text code"><?php echo esc_textarea( implode( "\n", $alert_to ) ); ?></textarea>
							<p class="description">
								<?php echo esc_html__( 'Email addresses that receive an alert when new or resolved findings appear. One per line (commas and semicolons also work). Invalid addresses are dropped when saving. If empty, no alert is sent and a warning is shown in the admin screens.', 'wp-checksum-verifier' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Changes', 'wp-checksum-verifier' ) ); ?>
			</form>

			<h2><?php echo esc_html__( 'Send test alert', 'wp-checksum-verifier' ); ?></h2>
			<?php if ( null !== $test_alert_result ) : ?>
				<?php if ( 'sent' === $test_alert_result['action'] ) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php echo esc_html__( 'Test alert sent successfully.', 'wp-checksum-verifier' ); ?></p>
					</div>
				<?php elseif ( 'no_recipient' === $test_alert_result['action'] ) : ?>
					<div class="notice notice-warning is-dismissible">
						<p><?php echo esc_html__( 'Alert recipients is empty. Set at least one address above and save, then try again.', 'wp-checksum-verifier' ); ?></p>
					</div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible">
						<p>
							<?php
							echo esc_html(
								null === $test_alert_result['error']
									? __( 'Test alert failed to send.', 'wp-checksum-verifier' )
									: sprintf(
										/* translators: %s: error message from wp_mail(). */
										__( 'Test alert failed to send: %s', 'wp-checksum-verifier' ),
										$test_alert_result['error']
									)
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( self::SEND_TEST_ALERT_NONCE_ACTION, self::SEND_TEST_ALERT_NONCE_NAME ); ?>
				<p class="description">
					<?php echo esc_html__( 'Send a test email to the alert recipients above, to confirm the address is correct before relying on it.', 'wp-checksum-verifier' ); ?>
				</p>
				<?php submit_button( __( 'Send test alert', 'wp-checksum-verifier' ), 'secondary', 'wpcv_send_test_alert_submit' ); ?>
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

			<?php self::render_prune_section( $retention_months ); ?>

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
	 * 「古い履歴を今すぐ削除」フォームが POST されていれば、nonce・権限を確かめて削除を受け付ける(v0.10.0).
	 *
	 * 削除そのものは `WPCV_Prune_Job::request()` が Action Scheduler のアクションとして予約する
	 * (リクエストの中で最後までやらない). 二重に押された場合や、保持期間が無期限の場合の扱いも
	 * そちらが決める.
	 *
	 * @return array{result: string, error: string|null}|null POSTされていない・権限が無い場合は `null`.
	 */
	private static function maybe_handle_prune() {
		if ( ! isset( $_POST[ self::PRUNE_NONCE_NAME ] ) ) {
			return null;
		}

		check_admin_referer( self::PRUNE_NONCE_ACTION, self::PRUNE_NONCE_NAME );

		if ( ! current_user_can( WPCV_Capability::required( WPCV_Capability::SCREEN_SETTINGS ) ) ) {
			return null;
		}

		return WPCV_Prune_Job::request();
	}

	/**
	 * `WPCV_Prune_Job::request()` の結果を、画面に出す通知(種類と文言)にする(`render()` から分離してテスト可能にする).
	 *
	 * @param array{result: string, error: string|null}|null $outcome `maybe_handle_prune()` の戻り値.
	 * @return array{type: string, message: string}|null 表示しないなら `null`. `type` は notice の種類(success・info・warning・error).
	 */
	public static function prune_result_notice( $outcome ) {
		if ( null === $outcome ) {
			return null;
		}

		if ( null !== $outcome['error'] ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Could not start deleting old history. Please try again later.', 'wp-checksum-verifier' ),
			);
		}

		switch ( $outcome['result'] ) {
			case WPCV_Prune_Job::RESULT_SCHEDULED:
				return array(
					'type'    => 'success',
					'message' => __( 'Deleting old history in the background. Reload this page to see the progress.', 'wp-checksum-verifier' ),
				);
			case WPCV_Prune_Job::RESULT_ALREADY_RUNNING:
				return array(
					'type'    => 'info',
					'message' => __( 'Old history is already being deleted.', 'wp-checksum-verifier' ),
				);
			case WPCV_Prune_Job::RESULT_UNLIMITED:
				return array(
					'type'    => 'warning',
					'message' => __( 'History retention is set to "Keep forever", so nothing was deleted.', 'wp-checksum-verifier' ),
				);
			case WPCV_Prune_Job::RESULT_INLINE:
				return array(
					'type'    => 'success',
					'message' => __( 'Action Scheduler is not available, so one batch was deleted now. The rest will be removed automatically at the end of the following runs.', 'wp-checksum-verifier' ),
				);
		}

		return null;
	}

	/**
	 * 「古い履歴を今すぐ削除」ボタンの表示状態を判定する(v0.10.0. `run_now_button_state()` と同じ考え方).
	 *
	 * @param int  $retention_months 保存されている保持期間(月. 0 は無期限).
	 * @param bool $active           削除のアクションが予約済み・実行中か.
	 * @return array{disabled: bool, notice: string|null}
	 */
	public static function prune_button_state( $retention_months, $active ) {
		if ( (int) $retention_months < 1 ) {
			return array(
				'disabled' => true,
				'notice'   => __( 'Choose a retention period above and save to use this.', 'wp-checksum-verifier' ),
			);
		}

		if ( $active ) {
			return array(
				'disabled' => true,
				'notice'   => __( 'Old history is being deleted. Reload this page to see the progress.', 'wp-checksum-verifier' ),
			);
		}

		return array(
			'disabled' => false,
			'notice'   => null,
		);
	}

	/**
	 * 直近の「古い履歴を今すぐ削除」の結果を、1行の表示文字列にする(`render_prune_section()` から分離してテスト可能にする).
	 *
	 * 日時は UTC で保存されているので、サイトのタイムゾーンで表示する(`WPCV_Settings::format_datetime()`).
	 *
	 * @param array{state: string, months: int, started_at: string, finished_at: string|null, totals: array{runs:int,target_runs:int,findings:int,suppressions:int}} $status `WPCV_Prune_Job::get_status()` の値.
	 * @return string
	 */
	public static function format_prune_status( array $status ) {
		switch ( $status['state'] ) {
			case WPCV_Prune_Job::STATE_RUNNING:
				$label = __( 'In progress', 'wp-checksum-verifier' );
				break;
			case WPCV_Prune_Job::STATE_DONE:
				$label = __( 'Completed', 'wp-checksum-verifier' );
				break;
			case WPCV_Prune_Job::STATE_PARTIAL:
				$label = __( 'Partly finished (the rest will be removed at the end of the following runs)', 'wp-checksum-verifier' );
				break;
			case WPCV_Prune_Job::STATE_CANCELLED:
				$label = __( 'Stopped (retention was changed to "Keep forever")', 'wp-checksum-verifier' );
				break;
			case WPCV_Prune_Job::STATE_FAILED:
				$label = __( 'Failed', 'wp-checksum-verifier' );
				break;
			default:
				$label = __( 'Interrupted', 'wp-checksum-verifier' );
		}

		return sprintf(
			/* translators: 1: state (e.g. Completed), 2: start time, 3: end time or a dash, 4: runs deleted, 5: per-target results deleted, 6: findings deleted, 7: suppressions deleted. */
			__( '%1$s — started: %2$s, finished: %3$s — deleted: %4$d runs, %5$d per-target results, %6$d findings, %7$d suppressions', 'wp-checksum-verifier' ),
			$label,
			WPCV_Settings::format_datetime( $status['started_at'] ),
			WPCV_Settings::format_datetime( $status['finished_at'] ),
			$status['totals']['runs'],
			$status['totals']['target_runs'],
			$status['totals']['findings'],
			$status['totals']['suppressions']
		);
	}

	/**
	 * 「古い履歴を今すぐ削除」の節(説明・直近の結果・ボタン)を描画する(v0.10.0).
	 *
	 * ボタンは確認のダイアログを出す(削除は元に戻せないため). 画面の保持期間のプルダウンを変えて
	 * 保存していない場合は、保存済みの値で消す.
	 *
	 * @param int $retention_months 保存されている保持期間(月).
	 * @return void
	 */
	private static function render_prune_section( $retention_months ) {
		$state  = self::prune_button_state( $retention_months, WPCV_Prune_Job::is_active() );
		$status = WPCV_Prune_Job::get_status();
		?>
		<h2><?php echo esc_html__( 'Delete old history now', 'wp-checksum-verifier' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( self::PRUNE_NONCE_ACTION, self::PRUNE_NONCE_NAME ); ?>
			<p class="description">
				<?php echo esc_html__( 'Delete history older than the saved retention period right away, instead of waiting for the following runs. The newest verified result of each target, results still being processed, and records needed to avoid repeating an alert are kept. This cannot be undone.', 'wp-checksum-verifier' ); ?>
			</p>
			<?php if ( $state['notice'] ) : ?>
				<p class="description"><?php echo esc_html( $state['notice'] ); ?></p>
			<?php endif; ?>
			<?php if ( null !== $status ) : ?>
				<p>
					<strong><?php echo esc_html__( 'Last deletion:', 'wp-checksum-verifier' ); ?></strong>
					<?php echo esc_html( self::format_prune_status( $status ) ); ?>
				</p>
			<?php endif; ?>
			<?php
			$confirm_message = sprintf(
				/* translators: %d: retention period in months. */
				__( 'Delete history older than %d months? This cannot be undone.', 'wp-checksum-verifier' ),
				(int) $retention_months
			);
			$attributes = array( 'onclick' => 'return confirm(' . wp_json_encode( $confirm_message ) . ');' );

			if ( $state['disabled'] ) {
				$attributes['disabled'] = 'disabled';
			}

			submit_button( __( 'Delete old history now', 'wp-checksum-verifier' ), 'secondary', 'wpcv_prune_history_submit', true, $attributes );
			?>
		</form>
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
	 * 時・分を常に2桁の文字列にする(例: 5 → `05`, 0 → `00`).
	 *
	 * 保存する値は整数のままで、2桁にするのは表示だけ(v0.8 §Step2. U8・U9).
	 * `type="number"` の入力欄でも、`value` の文字列 `05` はそのまま表示される
	 * (Chrome で確認. 2026-10-01). 1桁で入力された値は保存時に整数になり、
	 * 次の表示から 0 が補われる(`05` と `5` は同じ値).
	 *
	 * @param int|string $number 時または分.
	 * @return string 2桁(3桁以上の値はそのまま).
	 */
	public static function format_two_digits( $number ) {
		return sprintf( '%02d', (int) $number );
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

		if ( ! current_user_can( WPCV_Capability::required( WPCV_Capability::SCREEN_SETTINGS ) ) ) {
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

		// v0.5 §Step8: strict mode と同じく、未チェック時はキー自体が送られてこない.
		WPCV_Settings::update_stat_detection_enabled( isset( $_POST['wpcv_stat_detection'] ) );

		// v0.6 §Step11: strict mode と同じく、未チェック時はキー自体が送られてこない.
		WPCV_Settings::update_content_hash_mode(
			isset( $_POST['wpcv_content_hash_stat_targets'] )
				? WPCV_Settings::CONTENT_HASH_MODE_STAT_TARGETS
				: WPCV_Settings::CONTENT_HASH_MODE_OFF
		);

		// v0.6 §Step7: strict mode と同じく、未チェック時はキー自体が送られてこない.
		WPCV_Settings::update_alert_unrecorded_version_change_enabled( isset( $_POST['wpcv_alert_unrecorded_version_change'] ) );

		// v0.9 §Step2: 保持期間. セレクトボックスは常に値が送られるので、キーが無いとき
		// (このフォーム以外からの POST)だけは既存の値を変えない. 選択肢以外の値は
		// `update_retention_months()` が無期限(0)に倒す.
		if ( isset( $_POST['wpcv_retention_months'] ) ) {
			WPCV_Settings::update_retention_months( absint( wp_unslash( $_POST['wpcv_retention_months'] ) ) );
		}

		// v0.5後半 §Step14: アラートの宛先. プラン §6 の順序(nonce → capability →
		// `wp_unslash()` → 再サニタイズ)どおり. `sanitize_textarea_field()`は改行を残す
		// ため区切りが保たれ、そのあと`parse_email_list()`がアドレス単位で検証し直す.
		// テキストエリアは空でもキーごと送られてくるので、キーが無いとき(このフォーム
		// 以外からの POST)だけは既存の値を変えない.
		if ( isset( $_POST['wpcv_alert_to'] ) ) {
			WPCV_Settings::update_alert_to( sanitize_textarea_field( wp_unslash( $_POST['wpcv_alert_to'] ) ) );
		}

		// v0.8 §Step7: GitHub リポジトリとの対応付け. 検査に落ちた行は保存せず、理由を
		// 画面に出す(`$github_mapping_errors`). alert_to と同じく、キーが無いとき
		// (このフォーム以外からの POST)は既存の値を変えない.
		if ( isset( $_POST['wpcv_github_mappings'] ) ) {
			$parsed = WPCV_GitHub_Mappings::parse_text( sanitize_textarea_field( wp_unslash( $_POST['wpcv_github_mappings'] ) ) );

			WPCV_Settings::update_github_mappings( $parsed['entries'] );

			self::$github_mapping_errors = $parsed['errors'];
		}

		return true;
	}

	/**
	 * 対応付けを捨てた理由のコードを、行番号つきの文言にする.
	 *
	 * @param int    $line   テキストエリアの行番号(1始まり).
	 * @param string $reason `WPCV_GitHub_Mappings::REASON_*`.
	 * @return string
	 */
	public static function format_mapping_error( $line, $reason ) {
		switch ( $reason ) {
			case WPCV_GitHub_Mappings::REASON_INVALID_TARGET:
				/* translators: %d: line number. */
				return sprintf( __( 'Line %d: the target must look like plugin:{slug} or theme:{stylesheet}. Skipped.', 'wp-checksum-verifier' ), (int) $line );
			case WPCV_GitHub_Mappings::REASON_INVALID_REPO:
				/* translators: %d: line number. */
				return sprintf( __( 'Line %d: the repository must look like owner/repo. Skipped.', 'wp-checksum-verifier' ), (int) $line );
			case WPCV_GitHub_Mappings::REASON_DUPLICATE_TARGET:
				/* translators: %d: line number. */
				return sprintf( __( 'Line %d: this target is already mapped on an earlier line. Skipped.', 'wp-checksum-verifier' ), (int) $line );
			default:
				/* translators: %d: line number. */
				return sprintf( __( 'Line %d: expected "target owner/repo" with an optional asset name. Skipped.', 'wp-checksum-verifier' ), (int) $line );
		}
	}

	/**
	 * 保持期間の選択肢の表示名を返す(v0.9 §Step2).
	 *
	 * @param int $months 月数(0 は無期限).
	 * @return string
	 */
	private static function retention_choice_label( $months ) {
		if ( 0 === (int) $months ) {
			return __( 'Keep forever', 'wp-checksum-verifier' );
		}

		return sprintf(
			/* translators: %d: number of months. */
			_n( '%d month', '%d months', (int) $months, 'wp-checksum-verifier' ),
			(int) $months
		);
	}

	/**
	 * フィルター(`wpcv_github_mappings`)だけが足した対応付けを「target → owner/repo」の
	 * 文字列で返す(設定画面のテキストエリアに出ないので、別に知らせるため).
	 *
	 * @param array<string, array{repo: string, asset: string}>              $resolved `WPCV_GitHub_Mappings::resolve()`.
	 * @param array<int, array{target: string, repo: string, asset: string}> $stored   設定に保存された対応付け.
	 * @return string[]
	 */
	public static function filter_only_mappings( array $resolved, array $stored ) {
		$stored_targets = array_column( $stored, 'target' );
		$list           = array();

		foreach ( $resolved as $target => $mapping ) {
			if ( ! in_array( $target, $stored_targets, true ) ) {
				$list[] = $target . ' → ' . $mapping['repo'];
			}
		}

		return $list;
	}

	/**
	 * 対応付けのうち、コア同梱テーマ(今のコアのマニフェストに `wp-content/themes/{stylesheet}/` が
	 * あるもの)の stylesheet を返す(v0.8 §Step7. R2).
	 *
	 * このようなテーマの対応付けは、実行時に無視される(`WPCV_Chunk_Dispatcher::process_theme()`).
	 * 判定にコアのマニフェストが要るが、画面の表示で HTTP は出さないため、キャッシュ済みのもの
	 * だけを使う(まだ run が一度も走っていないと null で、警告は出ない).
	 *
	 * @param array<string, array{repo: string, asset: string}> $resolved   `WPCV_GitHub_Mappings::resolve()`.
	 * @param array|null                                        $core_files コアのマニフェストの `files`(パスをキーにした配列). 無ければ null.
	 * @return string[]
	 */
	public static function find_core_bundled_themes( array $resolved, ?array $core_files ) {
		if ( null === $core_files ) {
			return array();
		}

		$bundled = array();

		foreach ( array_keys( $resolved ) as $target_id ) {
			if ( 0 !== strpos( (string) $target_id, 'theme:' ) ) {
				continue;
			}

			$stylesheet = substr( (string) $target_id, 6 );
			$prefix     = 'wp-content/themes/' . $stylesheet . '/';

			foreach ( array_keys( $core_files ) as $path ) {
				if ( 0 === strpos( (string) $path, $prefix ) ) {
					$bundled[] = $stylesheet;
					break;
				}
			}
		}

		return $bundled;
	}

	/**
	 * キャッシュ済みのコアのマニフェストの `files` を返す(HTTP は出さない).
	 *
	 * @return array|null まだキャッシュされていなければ null.
	 */
	private static function load_cached_core_files() {
		$version = WPCV_Current_Version_Reader::core_version();
		$version = null !== $version ? $version : (string) get_bloginfo( 'version' );

		if ( '' === $version ) {
			return null;
		}

		$cached = WPCV_Plugin::manifest_cache_repository()->find( WPCV_Manifest_Cache_Repository::SOURCE_CORE, WPCV_Source_Core::current_locale(), $version );

		return null === $cached ? null : $cached['files'];
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

		if ( ! current_user_can( WPCV_Capability::required( WPCV_Capability::SCREEN_SETTINGS ) ) ) {
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
	 * 「Send test alert」フォームが POST されていれば nonce・capability を
	 * 検証したうえで `WPCV_Alert_Sender::send_test()` を呼ぶ(v0.5後半 §Step14d.
	 * プラン §4.4「宛先の誤りを運用前に見つけるため」).
	 *
	 * `maybe_handle_run_now()`と異なりWP-Cronの非同期発火を経由せず、この
	 * リクエストの中で同期的に`wp_mail()`まで完了させる(テスト送信1通だけ
	 * であり、検証runのように時間がかかる処理ではないため).
	 *
	 * @return array{action: string, error: string|null}|null POSTされていない・
	 *         capability検証に失敗した場合は`null`(何もnoticeを表示しない).
	 */
	private static function maybe_handle_send_test_alert() {
		if ( ! isset( $_POST[ self::SEND_TEST_ALERT_NONCE_NAME ] ) ) {
			return null;
		}

		check_admin_referer( self::SEND_TEST_ALERT_NONCE_ACTION, self::SEND_TEST_ALERT_NONCE_NAME );

		if ( ! current_user_can( WPCV_Capability::required( WPCV_Capability::SCREEN_SETTINGS ) ) ) {
			return null;
		}

		return WPCV_Plugin::alert_sender()->send_test();
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

		if ( ! current_user_can( WPCV_Capability::required( WPCV_Capability::SCREEN_SETTINGS ) ) ) {
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
		<?php // 日時はサイトのタイムゾーンで表示する(v0.10.0). 以前の UTC 表示と混同しないよう注記を添える. ?>
		<p class="description"><?php echo esc_html( WPCV_Settings::datetime_notice() ); ?></p>
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
					<td><?php echo esc_html( WPCV_Settings::format_datetime( (string) $status['next_scheduled_at'] ) ); ?></td>
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
								: WPCV_Settings::format_datetime( (string) $last_cli_run['started_at'] )
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
	 * 末尾に差分・アラートを足した(v0.5後半 §16・§1.4).`describe_run()`が返す
	 * 連想配列は`diff_status`/`findings_new`等を`WPCV_Run_Repository`の行と
	 * 同じキー名で持つため、`WPCV_Page_Run_History::format_diff_summary()`/
	 * `format_alert_status()`をそのまま再利用できる(同じ表を2か所に持たない).
	 * `current_run`(検証中のrun)は`diff_status`がNULLのため必ず「—」になる.
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
			/* translators: 1: run id, 2: status, 3: pending (queued) target count, 4: retry target count, 5: findings count, 6: last activity timestamp or dash, 7: diff summary, 8: alert status. */
			__( '#%1$d (%2$s) — pending: %3$d, retry: %4$d, findings: %5$d, last activity: %6$s — diff: %7$s, alert: %8$s', 'wp-checksum-verifier' ),
			(int) $run['run_id'],
			(string) $run['status'],
			(int) $targets['queued'],
			(int) $targets['retry'],
			(int) $run['findings_total'],
			WPCV_Settings::format_datetime( $run['last_activity_at'] ),
			WPCV_Page_Run_History::format_diff_summary( $run ),
			WPCV_Page_Run_History::format_alert_status( $run )
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
}
