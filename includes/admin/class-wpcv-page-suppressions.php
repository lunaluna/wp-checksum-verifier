<?php
/**
 * WPCV_Page_Suppressions クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 抑制一覧画面(v0.4.0 §Step9)。
 *
 * `wpcv_suppressions`の全行(`WPCV_Suppression_Repository::find_all()`)を新しい
 * ものから順に一覧表示する。抑制ルールは運用上せいぜい数百件程度であるという
 * 既存の設計判断(`WPCV_Suppression_Repository`のクラスdocblock参照)を踏襲し、
 * `WPCV_Page_Run_History`/`WPCV_Page_Findings`と異なりpaginationは設けない.
 *
 * 各行の種別(`WPCV_Suppression_Type`)・対象・理由・作成者・作成日時・
 * (`allowlist_hash`のみ)version・失効状態を表示し、まだ有効な行には
 * 「取消」操作(理由入力必須・nonce・capability検証)を提供する.
 */
class WPCV_Page_Suppressions {

	/**
	 * 取消フォームのnonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wpcv_revoke_suppression';

	/**
	 * 取消フォームのnonce name.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'wpcv_revoke_suppression_nonce';

	/**
	 * 画面を描画する.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::required_capability() ) ) {
			return;
		}

		$action_result = self::maybe_handle_revoke();

		$rows = WPCV_Plugin::suppression_repository()->find_all();
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $b['id'] <=> (int) $a['id'];
			}
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Suppressions', 'wp-checksum-verifier' ); ?></h1>

			<?php if ( true === $action_result ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'The suppression rule has been revoked.', 'wp-checksum-verifier' ); ?></p>
				</div>
			<?php elseif ( is_wp_error( $action_result ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $action_result->get_error_message() ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<p><?php echo esc_html__( 'No suppression rules have been created yet.', 'wp-checksum-verifier' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Type', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Target', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Reason', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Created by', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Created at', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Status', 'wp-checksum-verifier' ); ?></th>
							<th><?php echo esc_html__( 'Actions', 'wp-checksum-verifier' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row['type'] ); ?></td>
								<td><?php echo esc_html( self::format_target( $row ) ); ?></td>
								<td><?php echo esc_html( (string) $row['reason'] ); ?></td>
								<td><?php echo esc_html( self::format_created_by( (int) $row['created_by'] ) ); ?></td>
								<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
								<td><?php echo esc_html( self::status_label( $row ) ); ?></td>
								<td>
									<?php if ( null === $row['expired_at'] ) : ?>
										<?php self::render_revoke_form( (int) $row['id'] ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * 「対象」列の表示文字列を組み立てる(`render()`から分離してテスト可能にする)。
	 *
	 * `exclude_target`はdimension:slugのみ、`exclude_path`はそれに対象パス(pattern)を、
	 * `allowlist_hash`はさらにversionと(承認した)hashの先頭8文字を添える
	 * (hash全体は画面上では冗長なため。全体は`WPCV_Suppression_Repository::find_by_id()`
	 * 等で必要な時に確認できる).
	 *
	 * @param array $row `WPCV_Suppression_Repository::find_all()`の1行.
	 * @return string
	 */
	public static function format_target( array $row ) {
		$target = sprintf( '%s:%s', (string) $row['dimension'], (string) $row['slug'] );

		if ( WPCV_Suppression_Type::EXCLUDE_TARGET === $row['type'] ) {
			return $target;
		}

		$target .= ' / ' . (string) $row['pattern'];

		if ( WPCV_Suppression_Type::ALLOWLIST_HASH === $row['type'] ) {
			$target .= sprintf(
				' (version: %s, hash: %s…)',
				(string) $row['version'],
				substr( (string) $row['expected_hash'], 0, 8 )
			);
		}

		return $target;
	}

	/**
	 * 「状態」列の表示文字列を組み立てる(`render()`から分離してテスト可能にする).
	 *
	 * @param array $row `WPCV_Suppression_Repository::find_all()`の1行.
	 * @return string
	 */
	public static function status_label( array $row ) {
		if ( null === $row['expired_at'] ) {
			return __( 'Active', 'wp-checksum-verifier' );
		}

		return sprintf(
			/* translators: 1: revoked timestamp, 2: revoke reason. */
			__( 'Revoked at %1$s (%2$s)', 'wp-checksum-verifier' ),
			(string) $row['expired_at'],
			(string) $row['expired_reason']
		);
	}

	/**
	 * 「作成者」列の表示文字列を組み立てる. ユーザーが削除されている場合は
	 * user idのみを表示する(`get_userdata()`が`false`を返すケース).
	 *
	 * @param int $user_id `wpcv_suppressions.created_by`.
	 * @return string
	 */
	private static function format_created_by( $user_id ) {
		$user = get_userdata( $user_id );

		if ( false === $user ) {
			return sprintf(
				/* translators: %d: user id. */
				__( 'User #%d', 'wp-checksum-verifier' ),
				$user_id
			);
		}

		return $user->display_name;
	}

	/**
	 * 1件の取消フォームを描画する.
	 *
	 * @param int $id 対象の抑制ルールのid.
	 * @return void
	 */
	private static function render_revoke_form( $id ) {
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="wpcv_suppression_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<input
				type="text"
				name="wpcv_suppression_revoke_reason"
				maxlength="24"
				placeholder="<?php echo esc_attr__( 'Revoke reason (required, max 24 chars)', 'wp-checksum-verifier' ); ?>"
			/>
			<button type="submit" class="button">
				<?php echo esc_html__( 'Revoke', 'wp-checksum-verifier' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * 取消フォームがPOSTされていればnonce・capability・理由入力を検証したうえで
	 * 抑制ルールを失効させる.
	 *
	 * @return true|WP_Error|null 取消できたら`true`。理由未入力・対象idが無効・
	 *                            既に失効済み等で取消できなければ`WP_Error`。
	 *                            POSTされていない・capability検証に失敗した場合は`null`.
	 */
	private static function maybe_handle_revoke() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return null;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		if ( ! current_user_can( self::required_capability() ) ) {
			return null;
		}

		$reason = isset( $_POST['wpcv_suppression_revoke_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcv_suppression_revoke_reason'] ) ) : '';

		if ( '' === $reason ) {
			return new WP_Error( 'wpcv_reason_required', __( 'A reason is required.', 'wp-checksum-verifier' ) );
		}

		$id = isset( $_POST['wpcv_suppression_id'] ) ? absint( wp_unslash( $_POST['wpcv_suppression_id'] ) ) : 0;

		if ( $id <= 0 ) {
			return new WP_Error( 'wpcv_invalid_id', __( 'Invalid suppression rule id.', 'wp-checksum-verifier' ) );
		}

		$revoked = WPCV_Plugin::suppression_repository()->expire( $id, $reason );

		if ( ! $revoked ) {
			return new WP_Error( 'wpcv_revoke_failed', __( 'This rule could not be revoked (it may already be revoked, or no longer exists).', 'wp-checksum-verifier' ) );
		}

		return true;
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
