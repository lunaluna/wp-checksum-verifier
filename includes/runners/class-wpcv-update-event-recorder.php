<?php
/**
 * WPCV_Update_Event_Recorder クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress の更新機構(手動更新・自動更新・WP-CLI)を通ってプラグイン・コアの
 * version が変わったことを `WPCV_Update_Event_Repository` へ記録する
 * (v0.6プラン §Step2. D2〜D4・D11参照).
 *
 * 購読するフックは `upgrader_process_complete`(プラグイン)と
 * `_core_updated_successfully`(コア)の2つだけ(D2)。`upgrader_pre_install`/
 * `upgrader_post_install` は使わない(コア更新では呼ばれず、翻訳の一括更新が
 * `remove_all_filters()` するため。プラン§1.4参照).
 *
 * テーマ・翻訳の更新イベントはv0.6では記録しない(D11。翻訳ファイルはどの
 * 走査の対象でもなく、テーマはv0.7までtargetとして列挙されないため).
 *
 * 記録する version は「フックの時点でディスクから読み直した値」であり、更新が
 * 成功したかどうかは判定しない(D3)。`upgrader_process_complete` は
 * `install_package()` が失敗しても発火する(ダウンロード・展開の失敗では発火
 * しない)ため、成否を判定する代わりに「更新処理のあと、ディスク上のversionは
 * これだった」を記録すれば、失敗した更新はversionが変わらず、突き合わせ
 * (`WPCV_Update_Event_Repository::find_matching()`)に使われないだけになる.
 *
 * 各ハンドラは丸ごと `try/catch ( Throwable )` で包む(`WPCV_Run_Failure_Alerter`
 * と同じ理由): これらはWordPress自身の更新処理の呼び出しスタックの中で同期的に
 * 実行されるフックハンドラであり、ここで例外を外に漏らすと更新処理自体を
 * 壊しかねない(記録の失敗は「次の run で version_changed かつ記録なし」に
 * なるだけで、実害は通知が1回余計に飛ぶ程度に留まる).
 */
class WPCV_Update_Event_Recorder {

	/**
	 * `wpcv_update_events` の永続化層.
	 *
	 * @var WPCV_Update_Event_Repository
	 */
	private $repository;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Update_Event_Repository $repository `wpcv_update_events` の永続化層.
	 */
	public function __construct( WPCV_Update_Event_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * `upgrader_process_complete` フックのハンドラ本体(`WPCV_Plugin::handle_upgrader_process_complete()`
	 * から呼ばれる。優先度10で登録する。プラン§1.1の表参照).
	 *
	 * 型を `mixed` にしているのは、フックの引数は型宣言のないPHPのフィルター経由で
	 * 渡ってくるため(他のプラグインが `do_action( 'upgrader_process_complete', ... )`
	 * を独自に呼ぶ可能性も理論上ある)。`is_object()`/`is_array()` による実行時の
	 * 防御をそのまま活かすため、docblockでは意図的に型を絞らない.
	 *
	 * @param mixed $upgrader   更新処理を行った upgrader インスタンス(通常は `WP_Upgrader`).
	 * @param mixed $hook_extra `type`/`action`/`plugin`/`plugins`/`bulk` 等を持つ配列.
	 * @return void
	 */
	public function handle_upgrader_process_complete( $upgrader, $hook_extra ) {
		try {
			$this->process_hook_extra( $upgrader, is_array( $hook_extra ) ? $hook_extra : array() );
		} catch ( Throwable $e ) {
			// クラスdocblock参照: 記録の失敗で更新処理を壊さない.
			unset( $e );
		}
	}

	/**
	 * `$hook_extra` の内容から、プラン§1.1の表のどの経路かを判定し記録する.
	 *
	 * @param mixed $upgrader   更新処理を行った upgrader インスタンス(通常は `WP_Upgrader`).
	 * @param array $hook_extra `handle_upgrader_process_complete()` から渡された配列.
	 * @return void
	 */
	private function process_hook_extra( $upgrader, array $hook_extra ) {
		$type = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '';

		if ( 'plugin' !== $type ) {
			// core は `_core_updated_successfully` で処理する(D2)。theme/translationは
			// D11のとおり記録しない.
			return;
		}

		$action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '';

		if ( 'install' === $action ) {
			$this->record_install( $upgrader );
			return;
		}

		if ( 'update' !== $action ) {
			return;
		}

		if ( ! empty( $hook_extra['bulk'] ) && ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			foreach ( $hook_extra['plugins'] as $plugin_file ) {
				$this->record_plugin( (string) $plugin_file, 'plugin_bulk_update' );
			}
			return;
		}

		if ( ! empty( $hook_extra['plugin'] ) ) {
			$this->record_plugin( (string) $hook_extra['plugin'], 'plugin_update' );
		}
	}

	/**
	 * 新規インストール・zipアップロードでの上書き・`wp plugin update --version=X`
	 * (`action = install`)を記録する。slugは`$hook_extra`に含まれないため、
	 * `$upgrader->plugin_info()`(`result['destination_name']`から求める。
	 * プラン§1.1参照)で取る.
	 *
	 * @param mixed $upgrader 更新処理を行った upgrader インスタンス(通常は `WP_Upgrader`).
	 * @return void
	 */
	private function record_install( $upgrader ) {
		if ( ! is_object( $upgrader ) || ! method_exists( $upgrader, 'plugin_info' ) ) {
			return;
		}

		$plugin_file = $upgrader->plugin_info();

		if ( ! is_string( $plugin_file ) || '' === $plugin_file ) {
			return;
		}

		$this->record_plugin( $plugin_file, 'plugin_install' );
	}

	/**
	 * 1件のプラグイン更新イベントを記録する(`record_install()`・
	 * `process_hook_extra()` の共通処理).
	 *
	 * @param string $plugin_file `get_plugins()` のキー形式(`dir/file.php` または `file.php`).
	 * @param string $source      `plugin_update` / `plugin_bulk_update` / `plugin_install`.
	 * @return void
	 */
	private function record_plugin( $plugin_file, $source ) {
		$resolved  = WPCV_Run_Planner::resolve_plugin_slug_and_root( $plugin_file, WP_PLUGIN_DIR );
		$target_id = WPCV_Target_Resolver::build_id( WPCV_Target_Resolver::DIMENSION_PLUGIN, $resolved['slug'] );

		$this->repository->insert(
			$target_id,
			$this->read_plugin_version( $plugin_file ),
			$source,
			get_current_user_id()
		);
	}

	/**
	 * フックの時点でディスクから version を読み直す(D3・D4)。
	 *
	 * `get_plugins()` は使わない ―― `get_plugins()` はリクエスト内でキャッシュ
	 * されるが、自動更新は `clear_update_cache => false` のためフックの時点では
	 * キャッシュが消えていない(D4。プラン§1.3参照).
	 *
	 * @param string $plugin_file `get_plugins()` のキー形式.
	 * @return string|null 読めなければ null.
	 */
	private function read_plugin_version( $plugin_file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
		$version = (string) $data['Version'];

		return '' === $version ? null : $version;
	}

	/**
	 * `_core_updated_successfully` フックのハンドラ本体
	 * (`WPCV_Plugin::handle_core_updated_successfully()` から呼ばれる).
	 *
	 * `$wp_version` は新しいパッケージの `version.php` から読んだ新versionそのもの
	 * (プラン§1.2参照)であり、`read_plugin_version()` のようなディスクの
	 * 読み直しは不要.
	 *
	 * @param string $wp_version 更新後の WordPress version.
	 * @return void
	 */
	public function handle_core_updated_successfully( $wp_version ) {
		try {
			$version = (string) $wp_version;

			$this->repository->insert(
				WPCV_Target_Resolver::build_id( WPCV_Target_Resolver::DIMENSION_CORE ),
				'' === $version ? null : $version,
				'core_update',
				get_current_user_id()
			);
		} catch ( Throwable $e ) {
			// クラスdocblock参照: 記録の失敗で更新処理を壊さない.
			unset( $e );
		}
	}

	/**
	 * 既定の保持日数(v0.6プラン §2.1「掃除」。**未実測**: 連続unverifiableが
	 * 続いて基準が古いままのtargetでも3か月以内に1回は成功する、という前提の
	 * 暫定値。v0.9の保持期間の見直しで再検討する).
	 *
	 * @var int
	 */
	const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * `wpcv_run_terminated`フックのハンドラ本体(`WPCV_Plugin::handle_update_events_run_terminated()`
	 * から呼ばれる。v0.6 §Step7で配線 ―― Step1で`WPCV_Update_Event_Repository::delete_older_than()`を
	 * 実装した時点では「呼び出しはStep2・3で行う」としていたが、実際にはどちらでも
	 * 配線されないまま残っていた〔Step7のドキュメント作成中に発覚〕).
	 *
	 * 毎回のrun終端で掃除するのは、既存の`WPCV_Run_Failure_Alerter`
	 * (同じ`wpcv_run_terminated`を購読)と同じ設計(掃除専用のcronを新設せず、
	 * 既にある「runの節目」フックに載せる).掃除の失敗で他のリスナー
	 * (`WPCV_Run_Failure_Alerter`等)の実行やrun確定自体を妨げないよう
	 * try/catchで包む.
	 *
	 * @param int    $run_id 終端に達した run の id(このハンドラでは使わない).
	 * @param string $status 遷移後の `wpcv_runs.status`(このハンドラでは使わない).
	 * @return void
	 */
	public function handle_run_terminated( $run_id, $status ) {
		unset( $run_id, $status );

		try {
			$this->repository->delete_older_than( max( 1, (int) apply_filters( 'wpcv_update_events_retention_days', self::DEFAULT_RETENTION_DAYS ) ) );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}
}

add_action( 'upgrader_process_complete', array( 'WPCV_Plugin', 'handle_upgrader_process_complete' ), 10, 2 );
add_action( '_core_updated_successfully', array( 'WPCV_Plugin', 'handle_core_updated_successfully' ), 10, 1 );
add_action( 'wpcv_run_terminated', array( 'WPCV_Plugin', 'handle_update_events_run_terminated' ), 10, 2 );
