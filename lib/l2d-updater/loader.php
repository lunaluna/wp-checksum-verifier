<?php
/**
 * バージョン交渉ローダー(l2d-wp-github-update-lib).
 *
 * 複数プラグインが異なるバージョンのこのライブラリを同梱していても、
 * 実行時に最も新しいバージョンのコピーだけが起動する.
 *
 * このファイルが lib/ 配下にある場合はベンダーコピーである。直接編集せず、
 * 上流 https://github.com/lunaluna/l2d-wp-github-update-lib を更新して
 * git subtree pull で取り込むこと。このライブラリのリリース手順は上流にあり、
 * 同梱先プラグインには適用されない。
 *
 * 契約(初版で凍結。後から変更しない):
 * 1. l2dwpghul_updater_register( $version, $class_file, array $config ) の引数を増やさない.
 *    拡張は $config のキー追加で行う.
 * 2. このローダーは $config を検証も加工もせず素通しする.
 * 3. クラス名は L2dwpghul_GitHub_Updater 固定. コンストラクタは array $config 1 引数.
 * 4. plugins_loaded 優先度 -100 で起動する.
 *
 * @package L2dWpGithubUpdateLib
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 直接のアクセスを防止.
}

if ( ! function_exists( 'l2dwpghul_updater_register' ) ) {

	/**
	 * 同梱版を候補として登録する.
	 *
	 * @param string $version    このコピーのライブラリ版 (例 '1.0.0').
	 * @param string $class_file このコピーの本体ファイルの絶対パス.
	 * @param array  $config     利用側プラグインの設定(素通しする).
	 */
	function l2dwpghul_updater_register( $version, $class_file, array $config ) {
		global $l2dwpghul_updater_registry;
		if ( ! is_array( $l2dwpghul_updater_registry ) ) {
			$l2dwpghul_updater_registry = array(
				'files'   => array(),
				'configs' => array(),
			);
		}
		$l2dwpghul_updater_registry['files'][ (string) $version ] = $class_file;
		$l2dwpghul_updater_registry['configs'][]                  = $config;
	}

	/**
	 * 登録された候補のうち最も新しいバージョンの本体だけを読み込み、
	 * 登録された設定の数だけインスタンス化する.
	 */
	function l2dwpghul_updater_boot() {
		global $l2dwpghul_updater_registry;
		if ( empty( $l2dwpghul_updater_registry['files'] ) ) {
			return;
		}

		$files = $l2dwpghul_updater_registry['files'];
		uksort( $files, 'version_compare' );
		$winner = end( $files );

		require_once $winner;

		foreach ( $l2dwpghul_updater_registry['configs'] as $config ) {
			new L2dwpghul_GitHub_Updater( $config );
		}
	}

	add_action( 'plugins_loaded', 'l2dwpghul_updater_boot', -100 );
}

/**
 * このコピー固有のバージョンとファイルパスをクロージャでキャプチャして返す.
 *
 * グローバル定数(例: L2DWPGHUL_UPDATER_LIB_VERSION)を使わない理由: 複数プラグインが
 * 異なるバージョンのこのファイルを同梱していると、最初に読み込まれたコピーが
 * define() した値を後続のコピーが再定義できず、各コピーが自分の実際のバージョンを
 * 誤って報告してしまう(バージョン交渉が壊れる)。require の戻り値としてこのファイル
 * のスコープに閉じた値を渡すことで、コピーごとの値の混線を避ける。
 *
 * @return callable array $config を受け取り、このコピーを登録する関数.
 */
return function ( array $config ) {
	l2dwpghul_updater_register( '1.2.0', __DIR__ . '/class-l2d-github-updater.php', $config );
};
