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
 * RESTトークン認証(v0.3 §Step9、§12.3。v0.4.0 §Step7でscope分離).
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
 *
 * v0.4.0 §Step7で `POST /run`(`SCOPE_RUN`)と `GET /status`・`GET /findings`
 * (`SCOPE_READ`)のトークンを分離した。移行互換のため、v0.3〜v0.3.1で発行済みの
 * トークン(`OPTION_NAME` に保存済みのハッシュ)はそのまま `SCOPE_RUN` として
 * 扱い続ける(読み取り専用トークンは新設の `OPTION_NAME_READ` に別枠で保存し、
 * 管理画面から別途発行する。§Step7プラン「移行互換のため既存tokenはrun scope
 * として扱い、read scopeは管理画面から別発行する」)。`WPCV_REST_TOKEN` 定数は
 * 従来どおり `SCOPE_RUN` のみに適用する(定数の唯一の既存用途を変えないため。
 * read scope向けの定数上書きは本ステップの対象外).
 *
 * `is_rate_limited()`/`record_failed_attempt()`/`clear_failed_attempts()` は
 * scopeを問わず呼び出し元(IP)単位で共有する(scopeごとにbucketを分けても
 * ブルートフォース対策上の意味が薄く、実装を複雑にするだけのため).
 */
class WPCV_Rest_Token {

	/**
	 * 検証実行(`POST /run`)用のscope. v0.3〜v0.3.1由来の唯一のscopeで、
	 * 後方互換のため `OPTION_NAME` にそのまま保存され続ける.
	 *
	 * @var string
	 */
	const SCOPE_RUN = 'run';

	/**
	 * 読み取り専用(`GET /status`・`GET /findings`)用のscope(v0.4.0 §Step7で新設).
	 *
	 * @var string
	 */
	const SCOPE_READ = 'read';

	/**
	 * `SCOPE_RUN` トークンのハッシュを保存するオプション名(`wp_options`/
	 * `wp_sitemeta` 共通).
	 *
	 * 設定値全般をまとめる `WPCV_Settings::OPTION_NAME`(`wpcv_settings`)とは
	 * あえて分離した専用オプションにしている。セキュリティ上機微な値を、
	 * 都度読み書きする一般設定の配列と混在させたくないため.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wpcv_rest_token_hash';

	/**
	 * `SCOPE_READ` トークンのハッシュを保存するオプション名(v0.4.0 §Step7で新設。
	 * `OPTION_NAME` とは別枠にすることで、run scopeトークンの取り扱い
	 * (`WPCV_REST_TOKEN` 定数優先・既存の発行状況)に一切影響を与えない).
	 *
	 * @var string
	 */
	const OPTION_NAME_READ = 'wpcv_rest_token_hash_read';

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
	 * @param string $scope `SCOPE_RUN`(既定)または `SCOPE_READ`.
	 * @return string 生成した平文トークン(64文字の16進数文字列).
	 */
	public static function generate( $scope = self::SCOPE_RUN ) {
		$token = bin2hex( random_bytes( 32 ) );

		self::store_hash( $scope, self::hash( $token ) );

		return $token;
	}

	/**
	 * 設定画面から発行したトークンが存在するかどうか.
	 *
	 * `WPCV_REST_TOKEN` 定数の有無は問わない(あくまでDB保存分の有無。定数は
	 * `SCOPE_RUN` にしか適用されないため、`SCOPE_READ` では常にDB保存分のみを見る).
	 *
	 * @param string $scope `SCOPE_RUN`(既定)または `SCOPE_READ`.
	 * @return bool
	 */
	public static function has_stored_token( $scope = self::SCOPE_RUN ) {
		return '' !== self::stored_hash( $scope );
	}

	/**
	 * トークンを検証する(本番用の入口. `WPCV_REST_TOKEN` 定数を実際に読む).
	 *
	 * @param string $token リクエストから抽出した平文トークン(空文字を許容).
	 * @param string $scope `SCOPE_RUN`(既定)または `SCOPE_READ`.
	 * @return bool
	 */
	public static function verify( $token, $scope = self::SCOPE_RUN ) {
		return self::verify_against( $token, self::configured_token_override(), $scope );
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
	 * `$token_override` は `$scope` が `SCOPE_RUN` のときのみ考慮する(クラス
	 * docblock「`WPCV_REST_TOKEN` 定数は `SCOPE_RUN` のみに適用する」参照)。
	 * `SCOPE_READ` では常にDB保存分のハッシュのみと比較する.
	 *
	 * @param string      $token          リクエストから抽出した平文トークン.
	 * @param string|null $token_override `WPCV_REST_TOKEN` 定数相当の値
	 *                                    (未定義相当は `null`).
	 * @param string      $scope          `SCOPE_RUN`(既定)または `SCOPE_READ`.
	 * @return bool
	 */
	public static function verify_against( $token, $token_override, $scope = self::SCOPE_RUN ) {
		$token = (string) $token;

		if ( '' === $token ) {
			return false;
		}

		$expected_hash = ( self::SCOPE_RUN === $scope && null !== $token_override && '' !== $token_override )
			? self::hash( $token_override )
			: self::stored_hash( $scope );

		if ( '' === $expected_hash ) {
			return false;
		}

		return hash_equals( $expected_hash, self::hash( $token ) );
	}

	/**
	 * REST パーミッションコールバックの共通実装(v0.4.0 §Step7)。
	 *
	 * `WPCV_Rest_Run_Controller`・`WPCV_Rest_Status_Controller`・
	 * `WPCV_Rest_Findings_Controller` の3コントローラーが同じ手順(レート制限
	 * 確認 → トークン抽出 → scope付き検証 → 成功/失敗の記録)を必要とするため
	 * (2箇所目以降の利用が出た時点で共通化する、というこのプロジェクトの方針)、
	 * ここに集約した。`current_user_can()` によるcapabilityチェックとは併用しない
	 * (§12.3。WordPressログインセッションを持たない外部システムcronから呼べる
	 * ことが目的のため).
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @param string          $scope   `SCOPE_RUN` または `SCOPE_READ`.
	 * @return true|WP_Error
	 */
	public static function check_permission( $request, $scope ) {
		$identifier = self::client_identifier();

		if ( self::is_rate_limited( $identifier ) ) {
			return new WP_Error(
				'wpcv_rest_rate_limited',
				__( 'Too many failed authentication attempts. Try again later.', 'wp-checksum-verifier' ),
				array( 'status' => 429 )
			);
		}

		$token = self::extract_from_request( $request );

		if ( self::verify( $token, $scope ) ) {
			self::clear_failed_attempts( $identifier );

			return true;
		}

		self::record_failed_attempt( $identifier );

		return new WP_Error(
			'wpcv_rest_forbidden',
			__( 'Invalid or missing REST token.', 'wp-checksum-verifier' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * レート制限の単位に使う呼び出し元の識別子(IPアドレス)を返す.
	 *
	 * `X-Forwarded-For` 等のクライアントが自由に指定できるヘッダーは信用しない
	 * (プロキシ経由の実運用でIPアドレスが偏る可能性はあるが、v0.3では
	 * 詐称されうる値をセキュリティ判定に使わないことを優先する).
	 *
	 * @return string
	 */
	private static function client_identifier() {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
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
	 * `$identifier` が空文字の場合は常に false を返す(v0.3.1 §Step5。
	 * `REMOTE_ADDR` が取得できない呼び出し元を空文字で識別すると、由来の異なる
	 * 複数の呼び出し元が同じレート制限バケツを共有してしまい、無関係な呼び出し元が
	 * 巻き添えでロックアウトされ得る〔プラン§P1〕。識別子が無い場合はレート制限
	 * そのものを適用しない、という安全側の判断にする。`record_failed_attempt()`/
	 * `clear_failed_attempts()` も同様に空文字では何もしない).
	 *
	 * @param string $identifier レート制限の単位(呼び出し元が決める).
	 * @return bool
	 */
	public static function is_rate_limited( $identifier ) {
		if ( '' === $identifier ) {
			return false;
		}

		return self::RATE_LIMIT_MAX_ATTEMPTS <= (int) get_transient( self::rate_limit_key( $identifier ) );
	}

	/**
	 * 認証失敗を記録する.
	 *
	 * @param string $identifier `is_rate_limited()` と同じ単位. 空文字では何もしない
	 *                           (`is_rate_limited()` の docblock参照).
	 * @return void
	 */
	public static function record_failed_attempt( $identifier ) {
		if ( '' === $identifier ) {
			return;
		}

		$key   = self::rate_limit_key( $identifier );
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW_SECONDS );
	}

	/**
	 * 認証成功時、それまでの失敗記録を消す.
	 *
	 * @param string $identifier `is_rate_limited()` と同じ単位. 空文字では何もしない.
	 * @return void
	 */
	public static function clear_failed_attempts( $identifier ) {
		if ( '' === $identifier ) {
			return;
		}

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
	 * @param string $scope `SCOPE_RUN` または `SCOPE_READ`.
	 * @return string 未発行なら空文字.
	 */
	private static function stored_hash( $scope ) {
		$option = self::option_name_for_scope( $scope );
		$hash   = is_multisite()
			? get_site_option( $option, '' )
			: get_option( $option, '' );

		return (string) $hash;
	}

	/**
	 * ハッシュを保存する. autoloadを無効化する(毎リクエストの `alloptions` に
	 * 含めるべきでない値のため).
	 *
	 * @param string $scope `SCOPE_RUN` または `SCOPE_READ`.
	 * @param string $hash  `self::hash()` の戻り値.
	 * @return void
	 */
	private static function store_hash( $scope, $hash ) {
		$option = self::option_name_for_scope( $scope );

		if ( is_multisite() ) {
			update_site_option( $option, $hash );
			return;
		}

		update_option( $option, $hash, false );
	}

	/**
	 * Scopeに対応するオプション名を返す(`SCOPE_READ` 以外は既定で `SCOPE_RUN`
	 * 扱いにする。未知のscope値を渡された場合に静かに `SCOPE_RUN`〔既存の唯一の
	 * 用途〕へフォールバックさせるための設計).
	 *
	 * @param string $scope `SCOPE_RUN` または `SCOPE_READ`.
	 * @return string
	 */
	private static function option_name_for_scope( $scope ) {
		return self::SCOPE_READ === $scope ? self::OPTION_NAME_READ : self::OPTION_NAME;
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
