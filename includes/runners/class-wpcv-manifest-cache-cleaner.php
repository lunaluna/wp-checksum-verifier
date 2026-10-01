<?php
/**
 * WPCV_Manifest_Cache_Cleaner クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run の終端で、使われなくなったマニフェストキャッシュの行を消す(v0.7プラン §3.1・D4).
 *
 * キャッシュには期限を持たない(D4). 代わりに、run が終わるたびに「今回の run の
 * target に現れなかった (slug, version)」の行を消す. 日数の閾値を置くと実測が要るが、
 * 「今回列挙されたかどうか」なら数値が要らない.
 *
 * - テーマ(`source = wporg_theme`): 今回の run の `theme:{stylesheet}` の target_run の
 *   (stylesheet, version) だけを残す. 削除したテーマ・更新前の version の行が消える.
 * - コア(`source = core`): 今回の run の `core` の target_run の version と、今の locale
 *   (`WPCV_Source_Core::current_locale()`)の組だけを残す.
 * - GitHub(`source = github`. v0.8 §Step6): 今回の run の、`source = github` のプラグイン・
 *   テーマの target_run の (`{owner}/{repo}`, version) だけを残す. repo は target_run に
 *   無いので、run の終端の時点の対応付け(`WPCV_GitHub_Mappings::resolve()`)から求める.
 *   対応付けを外したリポジトリの行は、次の run の終端で消える.
 *
 * 消すのは run が `success` / `partial` で終わったときだけ. `failed` は列挙が途中で
 * 止まっている可能性があり(§3.1)、`aborted` は計画を保存する前に止まった run だと
 * target_run が1件も無く、全行を消してしまうため.
 *
 * `WPCV_Update_Event_Recorder::handle_run_terminated()` と同じく、掃除の失敗で
 * 他のリスナーや run の確定を妨げないよう try/catch で包む. 消せなかった行は
 * 次の run の終端でまた消そうとするだけで、照合の結果には影響しない.
 */
class WPCV_Manifest_Cache_Cleaner {

	/**
	 * `wpcv_manifest_cache` の永続化層.
	 *
	 * @var WPCV_Manifest_Cache_Repository
	 */
	private $cache;

	/**
	 * `wpcv_target_runs` の永続化層(今回の run の target を読む).
	 *
	 * @var WPCV_Target_Run_Repository
	 */
	private $target_run_repository;

	/**
	 * GitHub との対応付け(target_id => repo・asset)を返す callable(v0.8 §Step6).
	 *
	 * @var callable
	 */
	private $github_mappings;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Manifest_Cache_Repository $cache                 マニフェストのキャッシュ.
	 * @param WPCV_Target_Run_Repository     $target_run_repository `wpcv_target_runs` の永続化層.
	 * @param callable|null                  $github_mappings       GitHub との対応付けを返す. 省略時は
	 *                                                              `WPCV_GitHub_Mappings::resolve()`.
	 */
	public function __construct( WPCV_Manifest_Cache_Repository $cache, WPCV_Target_Run_Repository $target_run_repository, ?callable $github_mappings = null ) {
		$this->cache                 = $cache;
		$this->target_run_repository = $target_run_repository;
		$this->github_mappings       = $github_mappings ?? array( 'WPCV_GitHub_Mappings', 'resolve' );
	}

	/**
	 * `wpcv_run_terminated` フックのハンドラ本体(`WPCV_Plugin::handle_manifest_cache_run_terminated()`
	 * から呼ばれる).
	 *
	 * @param int    $run_id 終端に達した run の id.
	 * @param string $status 遷移後の `wpcv_runs.status`.
	 * @return void
	 */
	public function handle_run_terminated( $run_id, $status ) {
		if ( ! in_array( (string) $status, array( WPCV_Run_Status::SUCCESS, WPCV_Run_Status::PARTIAL ), true ) ) {
			return;
		}

		try {
			$this->clean( (int) $run_id );
		} catch ( Throwable $e ) {
			// クラス docblock 参照: 掃除の失敗で run の確定を妨げない.
			unset( $e );
		}
	}

	/**
	 * 今回の run の target_run から残す組を集めて、それ以外の行を消す.
	 *
	 * @param int $run_id run の id.
	 * @return void
	 */
	private function clean( $run_id ) {
		$keep_themes = array();
		$keep_core   = array();
		$keep_github = array();
		$mappings    = (array) call_user_func( $this->github_mappings );

		foreach ( $this->target_run_repository->find_all_by_run( $run_id ) as $target_run ) {
			$target_id = (string) $target_run['target_id'];
			$version   = isset( $target_run['version'] ) ? (string) $target_run['version'] : '';

			if ( '' === $version ) {
				continue;
			}

			if ( WPCV_Target_Resolver::DIMENSION_CORE === $target_id ) {
				$keep_core[] = array( WPCV_Source_Core::current_locale(), $version );
				continue;
			}

			// 本体の target だけを見る(`:_stat`・`:_scan` は本体と同じ組なので不要).
			$is_body = in_array( $target_run['dimension'], array( WPCV_Target_Resolver::DIMENSION_THEME, WPCV_Target_Resolver::DIMENSION_PLUGIN ), true )
				&& WPCV_Target_Resolver::build_id( (string) $target_run['dimension'], (string) $target_run['slug'] ) === $target_id;

			if ( ! $is_body ) {
				continue;
			}

			// GitHub で照合した target は、`wporg_theme` のキャッシュを使っていない. 取り違えて
			// 残さないよう、`github` の target は別の組に入れる.
			if ( 'github' === ( $target_run['source'] ?? null ) ) {
				if ( isset( $mappings[ $target_id ]['repo'] ) ) {
					$keep_github[] = array( (string) $mappings[ $target_id ]['repo'], $version );
				}
				continue;
			}

			if ( WPCV_Target_Resolver::DIMENSION_THEME === $target_run['dimension'] ) {
				$keep_themes[] = array( (string) $target_run['slug'], $version );
			}
		}

		$this->cache->delete_except( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, $keep_themes );
		$this->cache->delete_except( WPCV_Manifest_Cache_Repository::SOURCE_CORE, $keep_core );
		$this->cache->delete_except( WPCV_Manifest_Cache_Repository::SOURCE_GITHUB, $keep_github );
	}
}

add_action( 'wpcv_run_terminated', array( 'WPCV_Plugin', 'handle_manifest_cache_run_terminated' ), 10, 2 );
