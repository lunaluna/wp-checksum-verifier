<?php
/**
 * WPCV_Capability クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理画面(設定・実行履歴・検出結果・抑制・アラート通知)に必要な権限を返す(v0.9プラン §3.3・U4).
 *
 * 以前(v0.8 まで)は同じ判定(`is_multisite() ? 'manage_network_options' : 'manage_options'`)が
 * 4つの画面クラスに重複して書かれ、メニューの登録は別に固定値を持っていた. この1か所へ集約し、
 * メニュー・画面の表示・POST の処理・通知のすべてが、同じ画面名で同じ値を引くようにした
 * (メニューだけ緩めて POST で弾く、またはその逆、にならないため).
 *
 * 既定の権限は変えていない: 単一サイトは管理者(`manage_options`)、マルチサイトはスーパー管理者
 * (`manage_network_options`). 専用の capability は足さない(U4. ロールの登録・解除・uninstall の
 * 掃除が増えるため).
 *
 * REST はトークン認証、WP-CLI はサーバーに入れる人が使うので、この権限の対象ではない.
 */
class WPCV_Capability {

	/**
	 * 画面名: 設定画面(トップレベルのメニューもこの権限).
	 */
	const SCREEN_SETTINGS = 'settings';

	/**
	 * 画面名: 実行履歴.
	 */
	const SCREEN_RUNS = 'runs';

	/**
	 * 画面名: 検出結果(抑制ルールの作成〔POST〕を含む).
	 */
	const SCREEN_FINDINGS = 'findings';

	/**
	 * 画面名: 抑制ルールの一覧(失効〔POST〕を含む).
	 */
	const SCREEN_SUPPRESSIONS = 'suppressions';

	/**
	 * 画面名: アラートの管理画面通知.
	 */
	const SCREEN_NOTICES = 'notices';

	/**
	 * 画面名の一覧.
	 *
	 * @var string[]
	 */
	const SCREENS = array(
		self::SCREEN_SETTINGS,
		self::SCREEN_RUNS,
		self::SCREEN_FINDINGS,
		self::SCREEN_SUPPRESSIONS,
		self::SCREEN_NOTICES,
	);

	/**
	 * 既定の権限を返す(フィルターを通さない).
	 *
	 * 抑制ルールは検出の通知を止められる機能なので、既定は単一サイトでも管理者に限る.
	 * マルチサイトは installation 全体のデータ(`WPCV_Settings` のクラス docblock 参照)なので
	 * スーパー管理者に限る.
	 *
	 * @return string
	 */
	public static function default_capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * 指定した画面に必要な権限を返す.
	 *
	 * 画面名が `SCREENS` に無い場合(呼び出し側の綴り間違い)は、フィルターを通さず既定の権限を返す
	 * (誤って権限を緩めない).
	 *
	 * @param string $screen `SCREEN_*` のいずれか.
	 * @return string capability.
	 */
	public static function required( $screen ) {
		$default = self::default_capability();

		if ( ! in_array( $screen, self::SCREENS, true ) ) {
			return $default;
		}

		/**
		 * 管理画面に必要な権限(capability)を変える(v0.9 §Step5).
		 *
		 * 既定は、単一サイトで `manage_options`、マルチサイトで `manage_network_options`.
		 * **権限を緩めると、その権限を持つ人が検出結果の確認と抑制ルールの作成・失効をできる**.
		 * 抑制ルールは検出の通知を止められるので、信頼できるロールにだけ渡すこと.
		 * 同じ画面名のメニュー・表示・POST の処理・通知は、すべてこの値を使う. 画面ごとに別の値を
		 * 返すこともできるが、たとえば `findings` だけを緩めても、検出結果から作る抑制ルールの
		 * 一覧(`suppressions`)は別の権限のままになる点に注意.
		 *
		 * 文字列以外・空文字を返した場合は、既定の権限として扱う.
		 *
		 * @param string $capability 既定の権限.
		 * @param string $screen     画面名(`settings`・`runs`・`findings`・`suppressions`・`notices`).
		 */
		return self::sanitize_filtered( apply_filters( 'wpcv_required_capability', $default, $screen ), $default );
	}

	/**
	 * フィルターが返した値を検査する. 空でない文字列でなければ既定の権限を返す.
	 *
	 * フィルターのコールバックは何でも返せるので、型の違う値や空文字をそのまま権限として使わない
	 * (空文字は、スーパー管理者以外を一律に拒否する意図しない結果になりうる).
	 *
	 * @param mixed  $value    フィルターが返した値.
	 * @param string $fallback 既定の権限.
	 * @return string
	 */
	private static function sanitize_filtered( $value, $fallback ) {
		return is_string( $value ) && '' !== $value ? $value : $fallback;
	}
}
