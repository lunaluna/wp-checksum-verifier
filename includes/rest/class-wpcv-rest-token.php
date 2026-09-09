<?php
/**
 * WPCV_Rest_Token クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RESTトークン認証(v0.3 §Step9、§12.3).
 *
 * WordPressログインセッションを持たない外部システムcronから
 * `POST /wp-json/wpcv/v1/run` を呼べるようにするための、cookie認証とは独立した
 * トークン方式。生成したトークンの平文はどこにも保存せず、`wp_salt( 'auth' )` を
 * 鍵にした HMAC-SHA256 ハッシュのみを `wp_options`/`wp_sitemeta` に保存する
 * (トークン自体は生成時に1回だけ画面に表示し、以後は再表示できない設計。
 * `WPCV_Page_Settings` のトークン発行UI参照).
 *
 * 検証対象の値は2通りある(定数が設定画面より優先):
 * 1. `WPCV_REST_TOKEN` 定数(wp-config.php等で定義。運用者がDBを介さずに
 *    トークンを指定したい場合向け)
 * 2. 設定画面から発行しDBに保存したトークンのハッシュ
 *
 * リクエストからのトークン抽出は `Authorization: Bearer <token>` ヘッダーを
 * 優先し、`X-WPCV-Token: <token>` ヘッダーも許容する。クエリパラメータ
 * (`?token=...`)は意図的に読まない(アクセスログやリファラーにトークンが
 * 残る事故を避けるため。§12.3の要件).
 */
class WPCV_Rest_Token {

	/**
	 * トークンのハッシュを保存するオプション名(`wp_options`/`wp_sitemeta` 共通).
	 *
	 * 設定値全般をまとめる `WPCV_Settings::OPTION_NAME`(`wpcv_settings`)とは
	 * あえて分離した専用オプションにしている。セキュリティ上機微な値を、
	 * 都度読み書きする一般設定の配列と混在させたくないため.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wpcv_rest_token_hash';

	/**
	 * 失敗回数ベースのレート制限: この回数を超えたら以後を拒否する.
	 *
	 * 【未実測】計画時点の仮値. 実地運用でのブルートフォース耐性・正規の
	 * リトライ挙動(外部cronの再試行間隔等)を見て見直す余地がある.
	 *
	 * @var int
	 */
	const RATE_LIMIT_MAX_ATTEMPTS = 10;

	/**
	 * レート制限のカウンタが有効な期間(秒).
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW_SECONDS = 300;

	/**
	 * 新しいトークンを生成し、そのハッシュを保存する.
	 *
	 * 平文のトークンは戻り値としてのみ得られ、呼び出し側(`WPCV_Page_Settings`)が
	 * その場で1回だけ画面に表示する。以後はハッシュからの復元ができないため
	 * 再表示できない.
	 *
	 * @return string 生成した平文トークン(64文字の16進数文字列).
	 */
	public static function generate() {
		$token = bin2hex( random_bytes( 32 ) );

		self::store_hash( self::hash( $token ) );

		return $token;
	}

	/**
	 * 設定画面から発行したトークンが存在するかどうか.
	 *
	 * `WPCV_REST_TOKEN` 定数の有無は問わない(あくまでDB保存分の有無).
	 *
	 * @return bool
	 */
	public static function has_stored_token() {
		return '' !== self::stored_hash();
	}

	/**
	 * トークンを検証する(本番用の入口. `WPCV_REST_TOKEN` 定数を実際に読む).
	 *
	 * @param string $token リクエストから抽出した平文トークン(空文字を許容).
	 * @return bool
	 */
	public static function verify( $token ) {
		return self::verify_against( $token, self::configured_token_override() );
	}

	/**
	 * トークンを検証する(`$token_override` を明示的に受け取る純粋関数).
	 *
	 * `WPCV_REST_TOKEN` 定数を直接読まず引数で受け取る形にしている(他ステップ
	 * (`WPCV_Page_Settings::run_now_button_state()` の `DISABLE_WP_CRON` 引数化等)
	 * と同じ理由。PHP の定数は一度定義すると未定義に戻せないため、`verify()` を
	 * そのままテストすると「定数が定義されていない場合」を検証したテストの後に
	 * 「定数が定義されている場合」を検証するテストがあると、以後のテスト全てに
	 * 定数の定義が漏れてしまう).
	 *
	 * @param string      $token          リクエストから抽出した平文トークン.
	 * @param string|null $token_override `WPCV_REST_TOKEN` 定数相当の値
	 *                                    (未定義相当は `null`).
	 * @return bool
	 */
	public static function verify_against( $token, $token_override ) {
		$token = (string) $token;

		if ( '' === $token ) {
			return false;
		}

		$expected_hash = ( null !== $token_override && '' !== $token_override )
			? self::hash( $token_override )
			: self::stored_hash();

		if ( '' === $expected_hash ) {
			return false;
		}

		return hash_equals( $expected_hash, self::hash( $token ) );
	}

	/**
	 * リクエストから平文トークンを抽出する.
	 *
	 * `Authorization: Bearer <token>` を優先し、無ければ `X-WPCV-Token` を見る。
	 * クエリパラメータは読まない(クラスdocblock参照).
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return string 見つからなければ空文字.
	 */
	public static function extract_from_request( $request ) {
		$authorization = $request->get_header( 'authorization' );

		if ( is_string( $authorization ) && 0 === stripos( $authorization, 'Bearer ' ) ) {
			return trim( substr( $authorization, strlen( 'Bearer ' ) ) );
		}

		$custom_header = $request->get_header( 'x-wpcv-token' );

		if ( is_string( $custom_header ) && '' !== trim( $custom_header ) ) {
			return trim( $custom_header );
		}

		return '';
	}

	/**
	 * `$identifier`(呼び出し元IPアドレス等)が失敗回数の上限に達しているかどうか.
	 *
	 * @param string $identifier レート制限の単位(呼び出し元が決める. 空文字も許容
	 *                           するが、その場合は全呼び出し元が同じバケツを
	 *                           共有することになるため呼び出し側で避けること).
	 * @return bool
	 */
	public static function is_rate_limited( $identifier ) {
		return self::RATE_LIMIT_MAX_ATTEMPTS <= (int) get_transient( self::rate_limit_key( $identifier ) );
	}

	/**
	 * 認証失敗を記録する.
	 *
	 * @param string $identifier `is_rate_limited()` と同じ単位.
	 * @return void
	 */
	public static function record_failed_attempt( $identifier ) {
		$key   = self::rate_limit_key( $identifier );
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW_SECONDS );
	}

	/**
	 * 認証成功時、それまでの失敗記録を消す.
	 *
	 * @param string $identifier `is_rate_limited()` と同じ単位.
	 * @return void
	 */
	public static function clear_failed_attempts( $identifier ) {
		delete_transient( self::rate_limit_key( $identifier ) );
	}

	/**
	 * `WPCV_REST_TOKEN` 定数が定義されていればその値を返す(`verify_against()` の
	 * `$token_override` に渡す実際の値).
	 *
	 * @return string|null 未定義または空文字なら `null`.
	 */
	private static function configured_token_override() {
		if ( defined( 'WPCV_REST_TOKEN' ) && '' !== (string) WPCV_REST_TOKEN ) {
			return (string) WPCV_REST_TOKEN;
		}

		return null;
	}

	/**
	 * トークンのハッシュを計算する.
	 *
	 * @param string $token 平文トークン.
	 * @return string
	 */
	private static function hash( $token ) {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * 保存済みのハッシュを読み取る.
	 *
	 * @return string 未発行なら空文字.
	 */
	private static function stored_hash() {
		$hash = is_multisite()
			? get_site_option( self::OPTION_NAME, '' )
			: get_option( self::OPTION_NAME, '' );

		return (string) $hash;
	}

	/**
	 * ハッシュを保存する. autoloadを無効化する(毎リクエストの `alloptions` に
	 * 含めるべきでない値のため).
	 *
	 * @param string $hash `self::hash()` の戻り値.
	 * @return void
	 */
	private static function store_hash( $hash ) {
		if ( is_multisite() ) {
			update_site_option( self::OPTION_NAME, $hash );
			return;
		}

		update_option( self::OPTION_NAME, $hash, false );
	}

	/**
	 * レート制限用の transient キーを組み立てる.
	 *
	 * @param string $identifier 呼び出し元の識別子.
	 * @return string
	 */
	private static function rate_limit_key( $identifier ) {
		return 'wpcv_rest_token_fail_' . md5( $identifier );
	}
}
