<?php
/**
 * WPCV_Alert_Composer クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * アラートメールの件名と本文(text/plain)を組み立てる(v0.5後半プラン §4.1・§6.
 * Step13).
 *
 * DBに一切触れない純粋なロジック. 値の取得(件数・上位N件・target×statusの件数など)は
 * 呼び出し元(Step14の`WPCV_Alert_Sender`)がSQLで行い、その結果を`compose()`に渡す.
 * 送信と分けているのは、将来WPMARと共有ライブラリへ切り出すとき、送信部分だけを
 * 移せるようにするため(プラン §0.3).
 *
 * WPMARとの重複確認(2026-09-26): WPMARには差分アラートの本文組み立ては無い
 * (月次レポートのみ). 件名のサイト名の安全化だけが同種の処理で、WPMAR
 * `WPMAR_Notifier_Mail::send_pair()`の
 * `sanitize_text_field( wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) )`
 * と同じ書き方にそろえた(`clean_site_name()`).
 *
 * 並べ替えと上位N件への切り詰めはこのクラスでも行う. 呼び出し元のSQLが既に
 * `ORDER BY ... LIMIT N`済みでも、入力の並びに依存せず本文が確定するようにするため
 * (プラン §3.2の「SQLで絞り、PHP側でも同じ条件で絞り直す」方針と同じ考え方).
 */
class WPCV_Alert_Composer {

	/**
	 * 本文に載せる上位件数の既定値(プラン §4.5. 未実測. `wpcv_alert_max_items`で変更できる).
	 *
	 * @var int
	 */
	const DEFAULT_MAX_ITEMS = 20;

	/**
	 * Severity の並び順(小さいほど先. ここに無い値は最後).
	 *
	 * @var array<string, int>
	 */
	const SEVERITY_ORDER = array(
		'high'   => 0,
		'medium' => 1,
		'low'    => 2,
	);

	/**
	 * 本文に載せる上位件数を返す(`wpcv_alert_max_items`フィルターを通す. 1未満は1にする).
	 *
	 * @return int
	 */
	public static function max_items() {
		return max( 1, (int) apply_filters( 'wpcv_alert_max_items', self::DEFAULT_MAX_ITEMS ) );
	}

	/**
	 * 件名と本文を組み立てる.
	 *
	 * @param array $input {
	 *     すべて省略可(省略時は空・0として扱い、該当する節を出さない).
	 *
	 *     @type string $site_name              サイト名(`get_option( 'blogname' )`の値そのまま.
	 *                                          エスケープ解除と改行除去はこのメソッドで行う).
	 *     @type array  $run                    `id`/`finished_at`(GMT)/`run_trigger`.
	 *     @type array  $target_runs            その run の target_run 行(`target_id`/`status`/
	 *                                          `error_code`). 「Targets:」行の集計に使う.
	 *     @type array  $counts                 `new`/`resolved`/`continuing`の件数.
	 *     @type array  $top_items              本文の上位一覧に載せる候補(`severity`/`target_id`/
	 *                                          `path`/`status`). 件数が多くても上位N件だけ載せる.
	 *     @type array  $by_target              target×status の件数(`target_id`/`status`/`count`.
	 *                                          SQLの GROUP BY の結果).
	 *     @type array  $resolved_items         解消した finding(`target_id`/`path`/`status`).
	 *     @type array  $baseline_rebuilt       version 変更で基準を作り直した target
	 *                                          (`target_id`/`from_version`/`to_version`. U3).
	 *     @type array  $unverifiable_streaks   連続 unverifiable の閾値に達した target
	 *                                          (`target_id`/`error_code`. Step15で使う).
	 *     @type int    $unverifiable_threshold 連続 unverifiable の閾値(見出しの回数).
	 *     @type int    $removed_targets        今回見つからなかった(アンインストールされた)target の数.
	 *     @type string $details_url            検出結果画面の URL.
	 *     @type int    $max_items              上位件数. 省略時は`max_items()`.
	 * }
	 * @return array{subject: string, body: string}
	 */
	public static function compose( array $input ) {
		$input = array_merge(
			array(
				'site_name'              => '',
				'run'                    => array(),
				'target_runs'            => array(),
				'counts'                 => array(),
				'top_items'              => array(),
				'by_target'              => array(),
				'resolved_items'         => array(),
				'baseline_rebuilt'       => array(),
				'unverifiable_streaks'   => array(),
				'unverifiable_threshold' => 0,
				'removed_targets'        => 0,
				'details_url'            => '',
				'max_items'              => null,
			),
			$input
		);

		$max_items = null === $input['max_items'] ? self::max_items() : max( 1, (int) $input['max_items'] );
		$counts    = array_merge(
			array(
				'new'        => 0,
				'resolved'   => 0,
				'continuing' => 0,
			),
			(array) $input['counts']
		);

		return array(
			'subject' => self::build_subject( (string) $input['site_name'], (int) $counts['new'], (int) $counts['resolved'] ),
			'body'    => self::build_body( $input, $counts, $max_items ),
		);
	}

	/**
	 * 件名を組み立てる(§6: ヘッダーインジェクション対策として改行と制御文字を除く).
	 *
	 * @param string $site_name      サイト名.
	 * @param int    $new_count      新規件数.
	 * @param int    $resolved_count 解消件数.
	 * @return string
	 */
	private static function build_subject( $site_name, $new_count, $resolved_count ) {
		$subject = sprintf(
			/* translators: 1: site name, 2: number of new findings, 3: number of resolved findings. */
			__( '[WPCV] %1$s: %2$s new findings, %3$s resolved', 'wp-checksum-verifier' ),
			self::clean_site_name( $site_name ),
			number_format( $new_count ),
			number_format( $resolved_count )
		);

		// 翻訳文に改行が入っていても件名が複数行にならないよう、最後にもう一度除く.
		return self::strip_control_chars( $subject );
	}

	/**
	 * 本文を組み立てる(§4.1の並び. 空の節は出さない).
	 *
	 * @param array $input     `compose()`の入力(既定値を補ったもの).
	 * @param array $counts    `new`/`resolved`/`continuing`.
	 * @param int   $max_items 上位件数.
	 * @return string 末尾に改行を1つ付けた本文.
	 */
	private static function build_body( array $input, array $counts, $max_items ) {
		$run   = (array) $input['run'];
		$lines = array();

		$lines[] = sprintf(
			/* translators: 1: run id, 2: finished time (UTC), 3: run trigger. */
			__( 'Run #%1$s (%2$s UTC, %3$s)', 'wp-checksum-verifier' ),
			(int) ( $run['id'] ?? 0 ),
			self::clean( $run['finished_at'] ?? '' ),
			self::clean( $run['run_trigger'] ?? '' )
		);

		$target_counts = self::count_targets( (array) $input['target_runs'] );
		$lines[]       = sprintf(
			/* translators: 1: checksum verified targets, 2: change-tracked (stat) targets, 3: unverifiable targets, 4: failed targets. */
			__( 'Targets: %1$s verified / %2$s change-tracked / %3$s unverifiable / %4$s failed', 'wp-checksum-verifier' ),
			number_format( $target_counts['verified'] ),
			number_format( $target_counts['change_tracked'] ),
			number_format( $target_counts['unverifiable'] ),
			number_format( $target_counts['failed'] )
		);

		$lines[] = sprintf(
			/* translators: 1: new findings, 2: resolved findings, 3: continuing findings. */
			__( 'New: %1$s  Resolved: %2$s  Continuing: %3$s (already reported)', 'wp-checksum-verifier' ),
			number_format( (int) $counts['new'] ),
			number_format( (int) $counts['resolved'] ),
			number_format( (int) $counts['continuing'] )
		);

		$top = self::sort_top_items( (array) $input['top_items'], $max_items );

		if ( ! empty( $top ) ) {
			$lines[] = '';
			/* translators: %d: number of findings listed below. */
			$lines[] = sprintf( __( 'Top %d by severity:', 'wp-checksum-verifier' ), count( $top ) );

			foreach ( $top as $item ) {
				$lines[] = sprintf(
					'  [%1$s] %2$s  %3$s  %4$s',
					self::clean( $item['severity'] ?? '' ),
					self::clean( $item['target_id'] ?? '' ),
					self::clean_path( $item['path'] ?? '' ),
					self::clean( $item['status'] ?? '' )
				);
			}
		}

		$by_target = self::group_by_target( (array) $input['by_target'] );

		if ( ! empty( $by_target ) ) {
			$lines[] = '';
			$lines[] = __( 'By target:', 'wp-checksum-verifier' );

			foreach ( $by_target as $target_id => $statuses ) {
				$parts = array();

				foreach ( $statuses as $status => $count ) {
					$parts[] = $status . ' ' . number_format( $count );
				}

				$lines[] = '  ' . $target_id . '  ' . implode( ', ', $parts );
			}
		}

		$resolved = self::sort_resolved_items( (array) $input['resolved_items'], $max_items );

		if ( ! empty( $resolved ) ) {
			$lines[] = '';
			$lines[] = __( 'Resolved:', 'wp-checksum-verifier' );

			foreach ( $resolved as $item ) {
				$lines[] = sprintf(
					'  %1$s  %2$s  %3$s',
					self::clean( $item['target_id'] ?? '' ),
					self::clean_path( $item['path'] ?? '' ),
					self::clean( $item['status'] ?? '' )
				);
			}
		}

		if ( ! empty( $input['baseline_rebuilt'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Not verified today (baseline rebuilt after a version change):', 'wp-checksum-verifier' );

			foreach ( (array) $input['baseline_rebuilt'] as $item ) {
				$lines[] = sprintf(
					'  %1$s %2$s -> %3$s',
					self::clean( $item['target_id'] ?? '' ),
					self::clean( $item['from_version'] ?? '' ),
					self::clean( $item['to_version'] ?? '' )
				);
			}
		}

		if ( ! empty( $input['unverifiable_streaks'] ) ) {
			$lines[] = '';
			/* translators: %d: consecutive unverifiable count threshold. */
			$lines[] = sprintf( __( 'Unverifiable %d times in a row:', 'wp-checksum-verifier' ), (int) $input['unverifiable_threshold'] );

			foreach ( (array) $input['unverifiable_streaks'] as $item ) {
				$lines[] = sprintf(
					'  %1$s  %2$s',
					self::clean( $item['target_id'] ?? '' ),
					self::clean( $item['error_code'] ?? '' )
				);
			}
		}

		if ( (int) $input['removed_targets'] > 0 ) {
			$lines[] = '';
			/* translators: %s: number of removed targets. */
			$lines[] = sprintf( __( 'Removed targets: %s', 'wp-checksum-verifier' ), number_format( (int) $input['removed_targets'] ) );
		}

		if ( '' !== (string) $input['details_url'] ) {
			$lines[] = '';
			/* translators: %s: URL of the findings screen. */
			$lines[] = sprintf( __( 'Details: %s', 'wp-checksum-verifier' ), self::clean( $input['details_url'] ) );
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * 「Targets:」行の件数を数える(rev.3 §12.3-(h)).
	 *
	 * - stat target(`{dimension}:{slug}:_stat`)は`success`のときだけ「change-tracked」
	 *   として数える. `checksum_covered`等で skipped になった stat target は数えない
	 *   (同じ plugin が checksum 側で既に数えられているため).
	 * - それ以外の`success`は「verified」.
	 * - `unverifiable`は stat かどうかを問わず「unverifiable」.
	 * - `failed`と`aborted`は「failed」.
	 * - `skipped`(除外など)は数えない.
	 *
	 * @param array $target_runs target_run 行の一覧.
	 * @return array{verified: int, change_tracked: int, unverifiable: int, failed: int}
	 */
	private static function count_targets( array $target_runs ) {
		$counts = array(
			'verified'       => 0,
			'change_tracked' => 0,
			'unverifiable'   => 0,
			'failed'         => 0,
		);

		foreach ( $target_runs as $target_run ) {
			$status  = (string) ( $target_run['status'] ?? '' );
			$is_stat = WPCV_Target_Resolver::is_stat_id( (string) ( $target_run['target_id'] ?? '' ) );

			if ( WPCV_Target_Status::SUCCESS === $status ) {
				++$counts[ $is_stat ? 'change_tracked' : 'verified' ];
			} elseif ( WPCV_Target_Status::UNVERIFIABLE === $status ) {
				++$counts['unverifiable'];
			} elseif ( WPCV_Target_Status::FAILED === $status || WPCV_Target_Status::ABORTED === $status ) {
				++$counts['failed'];
			}
		}

		return $counts;
	}

	/**
	 * 上位一覧を severity 降順(high > medium > low)→ target_id → path で並べ、
	 * 上位N件に切り詰める(§4.1).
	 *
	 * `WPCV_Alert_Sender`も、通知候補をバッチで読みながら上位N件だけを持ち続ける
	 * ためにこのメソッドを使う(コードレビュー指摘4で public にした).並び順は
	 * severity → target_id → path で必ず一意に決まるため、バッチごとに切り詰めても、
	 * 全件を並べてから切り詰めた場合と同じN件が残る.
	 *
	 * @param array $items     候補.
	 * @param int   $max_items 上位件数.
	 * @return array
	 */
	public static function sort_top_items( array $items, $max_items ) {
		usort(
			$items,
			static function ( $a, $b ) {
				$rank_a = self::SEVERITY_ORDER[ $a['severity'] ?? '' ] ?? count( self::SEVERITY_ORDER );
				$rank_b = self::SEVERITY_ORDER[ $b['severity'] ?? '' ] ?? count( self::SEVERITY_ORDER );

				return array( $rank_a, (string) ( $a['target_id'] ?? '' ), (string) ( $a['path'] ?? '' ) )
					<=> array( $rank_b, (string) ( $b['target_id'] ?? '' ), (string) ( $b['path'] ?? '' ) );
			}
		);

		return array_slice( $items, 0, $max_items );
	}

	/**
	 * 解消一覧を target_id → path で並べ、上位N件に切り詰める.
	 *
	 * @param array $items     解消した finding.
	 * @param int   $max_items 上位件数.
	 * @return array
	 */
	private static function sort_resolved_items( array $items, $max_items ) {
		usort(
			$items,
			static function ( $a, $b ) {
				return array( (string) ( $a['target_id'] ?? '' ), (string) ( $a['path'] ?? '' ) )
					<=> array( (string) ( $b['target_id'] ?? '' ), (string) ( $b['path'] ?? '' ) );
			}
		);

		return array_slice( $items, 0, $max_items );
	}

	/**
	 * Target×status の件数を target ごとにまとめる(target_id 昇順・status 昇順.
	 * 同じ組み合わせが複数行あれば合計する). 未知ファイル検出の 1,235 件のような
	 * 規模を1行にまとめるための節(rev.3 §12.3-(c)).
	 *
	 * @param array $rows `target_id`/`status`/`count`の行.
	 * @return array<string, array<string, int>> 安全化済みの target_id => ( status => 件数 ).
	 */
	private static function group_by_target( array $rows ) {
		$grouped = array();

		foreach ( $rows as $row ) {
			$target_id = self::clean( $row['target_id'] ?? '' );
			$status    = self::clean( $row['status'] ?? '' );
			$count     = (int) ( $row['count'] ?? 0 );

			if ( $count <= 0 ) {
				continue;
			}

			$grouped[ $target_id ][ $status ] = ( $grouped[ $target_id ][ $status ] ?? 0 ) + $count;
		}

		ksort( $grouped, SORT_STRING );

		foreach ( $grouped as &$statuses ) {
			ksort( $statuses, SORT_STRING );
		}
		unset( $statuses );

		return $grouped;
	}

	/**
	 * Run の連続失敗アラート(v0.5後半 §Step15a設計§3.3)の件名と本文を組み立てる.
	 *
	 * `compose()`(差分アラート)とは別の入口にしてある ―― 通知する対象が
	 * finding ではなく run そのものであり、`counts`/`top_items`/`by_target`等の
	 * 差分アラート専用の節を一切持たないため、`compose()`に無理に合わせるより
	 * 専用メソッドにするほうが単純になる.
	 *
	 * `notes`・`error_message`は本文に含めない(設計§0.1: 例外メッセージに
	 * サーバーの絶対パスが入りうるため).
	 *
	 * @param array $input {
	 *     すべて省略可(省略時は空・0として扱う).
	 *
	 *     @type string $site_name     サイト名(`get_option( 'blogname' )`の値そのまま.
	 *                                 `clean_site_name()`で安全化する).
	 *     @type int    $streak_length 連続した失敗runの件数(件名に使う).
	 *     @type array  $streak_runs   連続の各run(`id`/`status`/`started_at`/
	 *                                 `run_trigger`.新しい順、今回を含む.
	 *                                 `WPCV_Run_Repository::find_failure_streak()`の
	 *                                 `runs`をそのまま渡す想定).
	 *     @type string $details_url  実行履歴画面のURL.
	 * }
	 * @return array{subject: string, body: string}
	 */
	public static function compose_run_failure( array $input ) {
		$input = array_merge(
			array(
				'site_name'     => '',
				'streak_length' => 0,
				'streak_runs'   => array(),
				'details_url'   => '',
			),
			$input
		);

		$subject = self::strip_control_chars(
			sprintf(
				/* translators: 1: site name, 2: number of consecutive failed runs. */
				__( '[WPCV] %1$s: %2$s runs failed in a row', 'wp-checksum-verifier' ),
				self::clean_site_name( (string) $input['site_name'] ),
				number_format( (int) $input['streak_length'] )
			)
		);

		$lines   = array();
		$lines[] = sprintf(
			/* translators: %d: number of consecutive failed runs. */
			__( '%d runs failed in a row:', 'wp-checksum-verifier' ),
			(int) $input['streak_length']
		);

		foreach ( (array) $input['streak_runs'] as $run ) {
			$lines[] = sprintf(
				'  #%1$s  %2$s  %3$s UTC  %4$s',
				(int) ( $run['id'] ?? 0 ),
				self::clean( $run['status'] ?? '' ),
				self::clean( $run['started_at'] ?? '' ),
				self::clean( $run['run_trigger'] ?? '' )
			);
		}

		if ( '' !== (string) $input['details_url'] ) {
			$lines[] = '';
			/* translators: %s: URL of the run history screen. */
			$lines[] = sprintf( __( 'Details: %s', 'wp-checksum-verifier' ), self::clean( $input['details_url'] ) );
		}

		return array(
			'subject' => $subject,
			'body'    => implode( "\n", $lines ) . "\n",
		);
	}

	/**
	 * サイト名を件名向けに安全化する(WPMARと同じ`wp_specialchars_decode()`+
	 * `sanitize_text_field()`に、改行・制御文字の除去を重ねる).
	 *
	 * `WPCV_Alert_Sender::send_test()`(v0.5後半 §Step14d)の件名組み立てからも
	 * 使うためpublicにしてある(「Send test alert」の件名を本番のアラートと
	 * 同じ安全化ルールにそろえるため. `compose()`を経由しない軽量な経路).
	 *
	 * @param string $site_name `get_option( 'blogname' )`の値.
	 * @return string
	 */
	public static function clean_site_name( $site_name ) {
		return self::strip_control_chars( sanitize_text_field( wp_specialchars_decode( $site_name, ENT_QUOTES ) ) );
	}

	/**
	 * パスを本文向けに安全化する(§6: 制御文字を除き、サーバーの絶対パスを載せない).
	 *
	 * 保存済みの path は ABSPATH からの相対パス(`WPCV_Path_Normalizer`)のはずだが、
	 * 万一 ABSPATH で始まる値が来ても、サーバーの絶対パスがメールに出ないよう
	 * 相対パスに直す.
	 *
	 * @param mixed $path path.
	 * @return string
	 */
	private static function clean_path( $path ) {
		$path = self::clean( $path );

		if ( '' !== ABSPATH && 0 === strpos( $path, ABSPATH ) ) {
			$path = substr( $path, strlen( ABSPATH ) );
		}

		return $path;
	}

	/**
	 * 本文に載せる1つの値を安全化する(制御文字を除く).
	 *
	 * @param mixed $value 値.
	 * @return string
	 */
	private static function clean( $value ) {
		return self::strip_control_chars( (string) $value );
	}

	/**
	 * 制御文字(改行・タブ・NUL・DEL を含む)を除く.
	 *
	 * `/u`修飾子を付けないのは、UTF-8 として壊れた path(`WPCV_Finding_Key`の
	 * テスト参照)でも`preg_replace()`が null を返さず、バイト単位で確実に除けるようにするため.
	 *
	 * @param string $value 値.
	 * @return string
	 */
	private static function strip_control_chars( $value ) {
		return (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
	}
}
