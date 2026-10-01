<?php
/**
 * WPCV_Stat_Bench クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wpcv bench-stat` の計測ロジック本体(v0.5 §4.2 Step4. rev.3 §3.9参照).
 *
 * **読み取り専用**。DBには一切書き込まない(`wp_wpcv_bench_stat`はベンチマーク
 * 目的のCLIコマンドであり、本番の検証runとは無関係).
 *
 * rev.3 §3.9で要求されている2つの計測を行う:
 *
 * 1. **本番と同じ経路**(`WPCV_Unknown_File_Scanner::scan()` の `collect_stat`
 *    経路。内部で `lstat()` を使う)を cold(`clearstatcache()` 直後)/warm
 *    (2回目以降)に分けて計測する。「日次フル走査が共有ホスティングで成立
 *    するか」を判断する一次データになる.
 * 2. **`filesize()`+`filectime()`+`filemtime()` の3回呼びと `lstat()` 1回の
 *    比較**。PHPはstatキャッシュを持つため3回呼びでも実syscallは1回で
 *    済んでいる可能性が高く、「lstatのほうが速い」は未検証の仮説である
 *    (rev.3 §3.9)。ただし速度差の有無に関わらず、symlinkを追わない
 *    (`WPCV_Unknown_File_Scanner::lstat_summary()` 参照)という理由で
 *    `lstat()` を採用する方針自体は変わらない.
 *
 * 上記1(`lstat_cold`/`lstat_warm`)はディレクトリ走査(`scandir()`の再帰)込みの
 * 本番全体のコストを測る一方、2の比較(`lstat_only_*`/`triple_call_*`)は
 * ファイル一覧を先に確保した上で「統計取得関数の呼び出しコストのみ」を
 * 測る。**両者を単純に並べて比較しない**こと(test-armfu.localでの実測時に
 * 実際に混同しかけた。1はwalk全体、2は関数呼び出しのみで、含まれるコストが
 * 異なるため直接比較できない).
 *
 * 各計測の対象ファイル一覧は毎回 `WPCV_Unknown_File_Scanner::scan()` で
 * 取り直す(cold測定の前提を壊さないため。ファイル一覧を使い回すと
 * ディレクトリエントリ自体のstatキャッシュが温まったままになる).
 *
 * `$include_content_hash`(v0.6 §Step8. rev.3 §3.5「層2」の実測)が真のとき、
 * 上記1・2とは別に3つ目の計測として `WPCV_File_Hasher::hash()`(sha256)を
 * 全ファイルに対して行う所要時間を測る。1・2は既存(layer 1)の判定コストの
 * 実測であるのに対し、こちらは「設定ファイル・ドロップイン(常時)/既存stat
 * target(オプトイン)に内容ハッシュを追加すると共有ホスティングで成立するか」
 * を判断する一次データになる(プラン§5.2「層2の実測」)。既定では計測しない
 * (大きいディレクトリではハッシュ計算自体が数百MB〜数GBの読み取りを伴い
 * 重いため.CLIの`--hash`フラグで明示的に有効化する).
 */
class WPCV_Stat_Bench {

	/**
	 * 計測対象を走査する `WPCV_Unknown_File_Scanner`.
	 *
	 * @var WPCV_Unknown_File_Scanner
	 */
	private $scanner;

	/**
	 * 現在時刻を秒(float。`microtime( true )` 相当)で返す callable.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Unknown_File_Scanner|null $scanner 省略時は `new WPCV_Unknown_File_Scanner()`.
	 * @param callable|null                  $now     省略時は `microtime( true )`.
	 */
	public function __construct( ?WPCV_Unknown_File_Scanner $scanner = null, ?callable $now = null ) {
		$this->scanner = $scanner ?? new WPCV_Unknown_File_Scanner();
		$this->now     = $now ?? static function () {
			return microtime( true );
		};
	}

	/**
	 * 指定ディレクトリに対して計測を行う.
	 *
	 * @param string $dir                   計測対象ディレクトリの絶対パス(ABSPATH配下).
	 * @param int    $iterations            `lstat`経路の計測回数(cold/warmの組を何セット
	 *                                      繰り返すか). 1未満は1に切り上げる.
	 * @param bool   $include_content_hash  真なら各iterationで内容ハッシュ(sha256)の
	 *                                      cold/warmも測る(v0.6 §Step8. クラスdocblock参照.
	 *                                      既定は偽 ―— 対象が大きいと時間がかかるため).
	 * @return array{
	 *     runs: array<int, array{iteration:int, lstat_cold:array, lstat_warm:array, content_hash_cold?:array, content_hash_warm?:array}>,
	 *     lstat_only_cold: array,
	 *     lstat_only_warm: array,
	 *     triple_call_cold: array,
	 *     triple_call_warm: array,
	 * }
	 */
	public function measure( $dir, $iterations = 3, $include_content_hash = false ) {
		$iterations = max( 1, (int) $iterations );
		$runs       = array();

		for ( $iteration = 1; $iteration <= $iterations; $iteration++ ) {
			clearstatcache();
			$cold = $this->measure_lstat_scan( $dir );
			$warm = $this->measure_lstat_scan( $dir );

			$run = array(
				'iteration'  => $iteration,
				'lstat_cold' => $cold,
				'lstat_warm' => $warm,
			);

			if ( $include_content_hash ) {
				clearstatcache();
				$run['content_hash_cold'] = $this->measure_content_hash( $dir );
				$run['content_hash_warm'] = $this->measure_content_hash( $dir );
			}

			$runs[] = $run;
		}

		clearstatcache();
		$lstat_only_cold  = $this->measure_lstat_only( $dir );
		$lstat_only_warm  = $this->measure_lstat_only( $dir );
		$triple_call_cold = $this->measure_triple_call( $dir );
		$triple_call_warm = $this->measure_triple_call( $dir );

		return array(
			'runs'             => $runs,
			'lstat_only_cold'  => $lstat_only_cold,
			'lstat_only_warm'  => $lstat_only_warm,
			'triple_call_cold' => $triple_call_cold,
			'triple_call_warm' => $triple_call_warm,
		);
	}

	/**
	 * `WPCV_Unknown_File_Scanner::scan()` の `collect_stat` 経路(本番と同じ経路。
	 * `lstat()` を使う)で1回分の走査を計測する.
	 *
	 * @param string $dir 対象ディレクトリの絶対パス.
	 * @return array{entries:int, seconds:float, bytes:int, entries_per_sec:float, bytes_per_sec:float}
	 */
	private function measure_lstat_scan( $dir ) {
		$start   = call_user_func( $this->now );
		$result  = $this->scanner->scan( $dir, array(), array( 'collect_stat' => true ) );
		$elapsed = call_user_func( $this->now ) - $start;

		$entries = count( $result['items'] );
		$bytes   = array_sum( array_column( $result['items'], 'size' ) );

		return $this->build_stats( $entries, $elapsed, $bytes );
	}

	/**
	 * `lstat()` 1回のみで、対象ディレクトリ配下の全ファイルを計測する
	 * (rev.3 §3.9の比較対象。クラスdocblock参照)。`measure_triple_call()` と
	 * 条件を揃えるため、ファイル一覧の取得(`scan()`)は先に済ませておき、
	 * 統計取得関数の呼び出しコストのみを計測する.
	 *
	 * @param string $dir 対象ディレクトリの絶対パス.
	 * @return array{entries:int, seconds:float, bytes:int, entries_per_sec:float, bytes_per_sec:float}
	 */
	private function measure_lstat_only( $dir ) {
		$result = $this->scanner->scan( $dir, array() );

		$start   = call_user_func( $this->now );
		$entries = 0;
		$bytes   = 0;

		foreach ( $result['items'] as $item ) {
			$absolute_path = rtrim( ABSPATH, '/' ) . '/' . $item['path'];

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ベンチマーク対象のファイルが計測中に消える競合は無視してよい(計測の中断より継続を優先する).
			$stat = @lstat( $absolute_path );

			if ( false !== $stat ) {
				$bytes += (int) $stat['size'];
			}

			++$entries;
		}

		$elapsed = call_user_func( $this->now ) - $start;

		return $this->build_stats( $entries, $elapsed, $bytes );
	}

	/**
	 * `filesize()`+`filectime()`+`filemtime()` の3回呼びで、対象ディレクトリ
	 * 配下の全ファイルを計測する(rev.3 §3.9の比較対象。クラスdocblock参照).
	 *
	 * ファイル一覧の取得自体(`scan()`)は `collect_stat` 無しで行う(ここで
	 * 測りたいのは3関数呼びのコストであり、`scan()` 自体のwalkコストは
	 * `measure_lstat_scan()` 側で既に計測しているため、二重に含めない).
	 *
	 * @param string $dir 対象ディレクトリの絶対パス.
	 * @return array{entries:int, seconds:float, bytes:int, entries_per_sec:float, bytes_per_sec:float}
	 */
	private function measure_triple_call( $dir ) {
		$result = $this->scanner->scan( $dir, array() );

		$start   = call_user_func( $this->now );
		$entries = 0;
		$bytes   = 0;

		foreach ( $result['items'] as $item ) {
			$absolute_path = rtrim( ABSPATH, '/' ) . '/' . $item['path'];

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ベンチマーク対象のファイルが計測中に消える競合は無視してよい(計測の中断より継続を優先する).
			$size = @filesize( $absolute_path );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$ctime = @filectime( $absolute_path );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$mtime = @filemtime( $absolute_path );

			// ctime/mtimeの値自体は使わない(rev.3 §3.9で測りたいのは
			// filesize()+filectime()+filemtime()の3回呼び分の所要時間であり、
			// 戻り値そのものではない). 変数に代入せず式文のまま呼ぶと
			// 「結果を使っていない」というPHPStanの指摘(expr.resultUnused)を
			// 受けるため、意図的に無視することを明示する.
			unset( $ctime, $mtime );

			if ( false !== $size ) {
				$bytes += $size;
			}

			++$entries;
		}

		$elapsed = call_user_func( $this->now ) - $start;

		return $this->build_stats( $entries, $elapsed, $bytes );
	}

	/**
	 * `WPCV_File_Hasher::hash()`(sha256)で、対象ディレクトリ配下の全ファイルの
	 * 内容ハッシュを計測する(v0.6 §Step8. クラスdocblock参照).
	 *
	 * `collect_stat` 付きで走査する ―— `measure_lstat_only()`/`measure_triple_call()`
	 * と異なり、ここでは統計取得コストを切り離す必要が無く(測りたいのは
	 * ハッシュ計算そのものの所要時間)、`item['size']` をそのまま使えば
	 * ファイルサイズ取得のための追加のsyscallを増やさずに済む.
	 *
	 * @param string $dir 対象ディレクトリの絶対パス.
	 * @return array{entries:int, seconds:float, bytes:int, entries_per_sec:float, bytes_per_sec:float}
	 */
	private function measure_content_hash( $dir ) {
		$result = $this->scanner->scan( $dir, array(), array( 'collect_stat' => true ) );

		$start   = call_user_func( $this->now );
		$entries = 0;
		$bytes   = 0;

		foreach ( $result['items'] as $item ) {
			$absolute_path = rtrim( ABSPATH, '/' ) . '/' . $item['path'];
			$hash          = WPCV_File_Hasher::hash( $absolute_path, WPCV_File_Hasher::ALGO_SHA256 );

			if ( null !== $hash ) {
				$bytes += (int) ( $item['size'] ?? 0 );
			}

			++$entries;
		}

		$elapsed = call_user_func( $this->now ) - $start;

		return $this->build_stats( $entries, $elapsed, $bytes );
	}

	/**
	 * 計測結果を集計する.
	 *
	 * @param int   $entries 訪問エントリ数.
	 * @param float $elapsed 経過秒.
	 * @param int   $bytes   総バイト数.
	 * @return array{entries:int, seconds:float, bytes:int, entries_per_sec:float, bytes_per_sec:float}
	 */
	private function build_stats( $entries, $elapsed, $bytes ) {
		return array(
			'entries'         => $entries,
			'seconds'         => $elapsed,
			'bytes'           => $bytes,
			// PHPの除算演算子は割り切れる場合int型を返すため、明示的にfloatへ
			// キャストする(elapsed・entries・bytesがいずれも整数値で割り切れる
			// ケースでは結果がint型になり、常にfloatを期待する呼び出し元との
			// 型の食い違いを生むため).
			'entries_per_sec' => $elapsed > 0 ? (float) ( $entries / $elapsed ) : 0.0,
			'bytes_per_sec'   => $elapsed > 0 ? (float) ( $bytes / $elapsed ) : 0.0,
		);
	}
}
