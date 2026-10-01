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
 * 翻訳の更新イベントは記録しない(v0.6 D11。翻訳ファイルはどの走査の対象でもない
 * ため)。テーマは v0.7 §Step6(D9)から記録する(v0.7 でテーマが target になったため。
 * 経路と `$hook_extra` の形は v0.7 プラン §1.3 参照).
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
		$type   = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '';
		$action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '';

		if ( 'theme' === $type ) {
			$this->process_theme_hook_extra( $upgrader, $action, $hook_extra );
			return;
		}

		if ( 'plugin' !== $type ) {
			// core は `_core_updated_successfully` で処理する(D2)。translation は
			// D11のとおり記録しない.
			return;
		}

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
	 * テーマの経路を判定して記録する(v0.7 §Step6. D9. 経路は v0.7 プラン §1.3 の表).
	 *
	 * | 経路                    | `$hook_extra`                                       | source              |
	 * |-------------------------|-----------------------------------------------------|---------------------|
	 * | install(zip の上書きも)| `action = install`. slug は無い → `theme_info()`    | `theme_install`     |
	 * | 単体の更新・自動更新    | `action = update`・`theme`                          | `theme_update`      |
	 * | 一括更新                | `action = update`・`bulk = true`・`themes`(配列)  | `theme_bulk_update` |
	 *
	 * 子テーマのインストールで親テーマが自動で入る経路(`Theme_Upgrader::check_parent_theme_filter()`)
	 * では、親テーマの `run()` に `hook_extra` が渡らない(`class-theme-upgrader.php:173-180`)
	 * ので、`type` の無い発火になり親テーマは記録されない. 新しく入ったテーマには前回の
	 * 照合結果が無く、突き合わせ(D5)自体が起きないので害は無い.
	 *
	 * @param mixed  $upgrader   更新処理を行った upgrader インスタンス(通常は `Theme_Upgrader`).
	 * @param string $action     `install` / `update`.
	 * @param array  $hook_extra `handle_upgrader_process_complete()` から渡された配列.
	 * @return void
	 */
	private function process_theme_hook_extra( $upgrader, $action, array $hook_extra ) {
		if ( 'install' === $action ) {
			if ( ! is_object( $upgrader ) || ! method_exists( $upgrader, 'theme_info' ) ) {
				return;
			}

			$theme = $upgrader->theme_info();

			if ( ! is_object( $theme ) || ! method_exists( $theme, 'get_stylesheet' ) ) {
				return;
			}

			$this->record_theme( (string) $theme->get_stylesheet(), 'theme_install', (string) $theme->get_stylesheet_directory() );
			return;
		}

		if ( 'update' !== $action ) {
			return;
		}

		if ( ! empty( $hook_extra['bulk'] ) && ! empty( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
			foreach ( $hook_extra['themes'] as $stylesheet ) {
				$this->record_theme( (string) $stylesheet, 'theme_bulk_update' );
			}
			return;
		}

		if ( ! empty( $hook_extra['theme'] ) ) {
			$this->record_theme( (string) $hook_extra['theme'], 'theme_update' );
		}
	}

	/**
	 * 1件のテーマの更新イベントを記録する(v0.7 §Step6).
	 *
	 * @param string      $stylesheet テーマの stylesheet.
	 * @param string      $source     `theme_update` / `theme_bulk_update` / `theme_install`.
	 * @param string|null $dir        テーマのディレクトリ. 省略時は `get_theme_root( $stylesheet )`
	 *                                から組み立てる(`register_theme_directory()` で複数の
	 *                                テーマのルートがありうるため、`WP_CONTENT_DIR` からは組み立てない).
	 * @return void
	 */
	private function record_theme( $stylesheet, $source, $dir = null ) {
		if ( '' === $stylesheet ) {
			return;
		}

		$dir = null === $dir ? rtrim( (string) get_theme_root( $stylesheet ), '/' ) . '/' . $stylesheet : $dir;

		$this->repository->insert(
			WPCV_Target_Resolver::build_id( WPCV_Target_Resolver::DIMENSION_THEME, $stylesheet ),
			self::read_theme_version( $dir ),
			$source,
			get_current_user_id()
		);
	}

	/**
	 * フックの時点でディスクから テーマの version を読み直す(D9. プラグインの D4 と同じ考え方).
	 *
	 * `wp_get_theme()` は使わない. `WP_Theme` は `themes` グループのキャッシュに
	 * ヘッダーを持ち、自動更新(`clear_update_cache => false`. `class-wp-automatic-updater.php:481`)
	 * ではフックの時点でキャッシュが消えていない. `style.css` を直接読む.
	 *
	 * @param string $dir テーマのディレクトリ.
	 * @return string|null 読めなければ null.
	 */
	private static function read_theme_version( $dir ) {
		$style = rtrim( $dir, '/' ) . '/style.css';

		if ( ! is_readable( $style ) ) {
			return null;
		}

		$data    = get_file_data( $style, array( 'Version' => 'Version' ), 'theme' );
		$version = isset( $data['Version'] ) ? (string) $data['Version'] : '';

		return '' === $version ? null : $version;
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
