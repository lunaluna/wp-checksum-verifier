<?php
/**
 * WPCV_Alert_Sender クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 差分アラートの送信(v0.5後半 §Step14. D4・D6・D10)を1 run分行う.
 *
 * `WPCV_Alert_Composer`(Step13. 本文組み立てのみ)とは別クラスにしてある.
 * 将来WPMARと共有ライブラリへ切り出すとき、送信部分だけを移せるようにするため
 * (`WPCV_Alert_Composer`のクラスdocblock参照).
 *
 * 公開メソッドは`send_for_run( $run_id )`(alerting段階からStep14cで配線済み)と
 * `send_test()`(v0.5後半 §Step14d. 設定画面の「Send test alert」ボタン専用.
 * runに依存せず、保存済み`alert_to`へ固定文面のテストメールを送るだけの軽量な
 * 経路).
 *
 * `$unverifiable_streak_triggered`は常にfalseで`WPCV_Generation_Differ::should_send_alert()`
 * を呼ぶ(連続unverifiableのアラートはStep15の範囲).
 */
class WPCV_Alert_Sender {

	/**
	 * 通知候補(`WPCV_Finding_Repository::find_notify_candidates_batch()`)を
	 * 1回のバッチで読む最大件数.
	 *
	 * 未実測: `WPCV_Diff_Dispatcher::DEFAULT_BATCH_SIZE`と同じ値を流用した暫定値.
	 *
	 * @var int
	 */
	const NOTIFY_BATCH_SIZE = 500;

	/**
	 * 本文の`resolved_items`向けに`find_resolved_items()`へ渡す取得件数の上限.
	 *
	 * 未実測: `WPCV_Alert_Composer::max_items()`(既定20)より大きめに取り、
	 * Composer側の`sort_resolved_items()`がさらに上位N件へ切り詰める
	 * (2段階の絞り込み. `top_items`と同じ考え方)ための暫定値.
	 *
	 * @var int
	 */
	const RESOLVED_ITEMS_FETCH_LIMIT = 1000;

	/**
	 * `wpcv_runs`の永続化層.
	 *
	 * @var WPCV_Run_Repository
	 */
	private $run_repository;

	/**
	 * `wpcv_target_runs`の永続化層.
	 *
	 * @var WPCV_Target_Run_Repository
	 */
	private $target_run_repository;

	/**
	 * `wpcv_findings`の永続化層.
	 *
	 * @var WPCV_Finding_Repository
	 */
	private $finding_repository;

	/**
	 * 現在時刻を返すcallable(`gmdate( 'Y-m-d H:i:s' )`形式の文字列を返す).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Run_Repository        $run_repository        `wpcv_runs`の永続化層.
	 * @param WPCV_Target_Run_Repository $target_run_repository `wpcv_target_runs`の永続化層.
	 * @param WPCV_Finding_Repository    $finding_repository    `wpcv_findings`の永続化層.
	 * @param callable|null              $now                   現在時刻を返すcallable.
	 *                                                          省略時は`gmdate( 'Y-m-d H:i:s' )`.
	 */
	public function __construct( WPCV_Run_Repository $run_repository, WPCV_Target_Run_Repository $target_run_repository, WPCV_Finding_Repository $finding_repository, ?callable $now = null ) {
		$this->run_repository        = $run_repository;
		$this->target_run_repository = $target_run_repository;
		$this->finding_repository    = $finding_repository;
		$this->now                   = $now ?? static function () {
			return gmdate( 'Y-m-d H:i:s' );
		};
	}

	/**
	 * 指定runのアラートを判定・送信する(§2.4・§2.5・§4.2・§4.3).
	 *
	 * `$owner`は結果の記録(`WPCV_Run_Repository::record_alert_result()`)の
	 * fencingに使う.lease切れ後に別ownerが再claimしていた場合、このプロセスの
	 * 結果は記録されない(送信自体は行われうる.D10「少なくとも1回」).
	 *
	 * @param int    $run_id 対象のrunのid.
	 * @param string $owner  `claim_diff()`が`alerting`のclaimで割り当てたowner.
	 * @return array{action: string} `action`は`run_not_found`/`not_needed`/
	 *                                `no_recipient`/`sent`/`failed`のいずれか.
	 */
	public function send_for_run( $run_id, $owner ) {
		$run_id = (int) $run_id;
		$run    = $this->run_repository->find_by_id( $run_id );

		if ( null === $run ) {
			return array( 'action' => 'run_not_found' );
		}

		$now         = call_user_func( $this->now );
		$resend_days = (int) apply_filters( 'wpcv_alert_resend_days', 7 );

		$candidates     = $this->gather_notify_candidates( $run_id, $now, $resend_days );
		$resolved_count = (int) ( $run['findings_resolved'] ?? 0 );

		if ( ! WPCV_Generation_Differ::should_send_alert( $candidates['notify_count'], $resolved_count, false ) ) {
			$this->run_repository->record_alert_result( $run_id, $owner, 'not_needed', null, null, false );

			return array( 'action' => 'not_needed' );
		}

		$alert_to = WPCV_Settings::get_alert_to();

		if ( empty( $alert_to ) ) {
			// 宛先未設定のあいだはメール・追加チャネルのどちらも実行しない
			// (§4.3「no_recipientのときは実行しない」. U1).
			$this->run_repository->record_alert_result( $run_id, $owner, 'no_recipient', null, null, true );

			return array( 'action' => 'no_recipient' );
		}

		$target_runs = $this->target_run_repository->find_all_by_run( $run_id );

		// 本文の counts(New/Resolved/Continuing)は、finding_repositoryで再集計せず
		// wpcv_runsに差分処理(Step12)が書き込み済みの集計値をそのまま使う
		// (「通知した件数」〔notify_count〕とは別概念. §3設計上の決定参照).
		$counts = array(
			'new'        => (int) ( $run['findings_new'] ?? 0 ),
			'resolved'   => $resolved_count,
			'continuing' => (int) ( $run['findings_continuing'] ?? 0 ),
		);

		$details_url = $this->details_url();

		$composed = WPCV_Alert_Composer::compose(
			array(
				'site_name'        => get_option( 'blogname' ),
				'run'              => $run,
				'target_runs'      => $target_runs,
				'counts'           => $counts,
				'top_items'        => $candidates['top_items'],
				'by_target'        => $this->finding_repository->count_by_target_and_status( $run_id ),
				'resolved_items'   => $this->finding_repository->find_resolved_items( $run_id, self::RESOLVED_ITEMS_FETCH_LIMIT ),
				'baseline_rebuilt' => $this->gather_baseline_rebuilt( $target_runs, $run_id ),
				'removed_targets'  => $this->finding_repository->count_removed_targets( $run_id ),
				'details_url'      => $details_url,
			)
		);

		$mail_error = null;
		$sent       = $this->send_mail( $alert_to, $composed['subject'], $composed['body'], $mail_error );

		// 追加チャネルはメールの成功・失敗を問わず実行する(§4.3「実行する条件」).
		$channel_failures = $this->run_channels( $run_id, $composed['subject'], $composed['body'], $counts, $details_url );

		if ( $sent ) {
			// notified_atを書くのはalert_status=sentのときだけ(§2.4).
			$this->finding_repository->mark_notified_by_ids( $candidates['notify_ids'], $now );
			$this->run_repository->record_alert_result( $run_id, $owner, 'sent', null, $channel_failures );

			return array( 'action' => 'sent' );
		}

		$this->run_repository->record_alert_result( $run_id, $owner, 'failed', $mail_error, $channel_failures );

		return array( 'action' => 'failed' );
	}

	/**
	 * 保存済み`alert_to`へ固定文面のテストメールを送る(v0.5後半 §Step14d.
	 * 設定画面の「Send test alert」ボタン専用).
	 *
	 * `send_for_run()`と異なりrunに依存しない・追加チャネル(`wpcv_alert_channels`)
	 * も実行しない(あくまで「メール送信経路そのものが機能するか」の疎通確認
	 * であり、本番のアラート送信の代わりにはしない).DBへの記録も行わない
	 * (`alert_status`等はrun単位の送信結果を表す列のため、run に紐付かない
	 * このテスト送信では書かない).
	 *
	 * @return array{action: string, error: string|null} `action`は`no_recipient`/
	 *         `sent`/`failed`のいずれか.`error`は`failed`のときだけ
	 *         `WP_Error::get_error_message()`(§6: `get_error_data()`は保存しない).
	 */
	public function send_test() {
		$alert_to = WPCV_Settings::get_alert_to();

		if ( empty( $alert_to ) ) {
			return array(
				'action' => 'no_recipient',
				'error'  => null,
			);
		}

		$subject = self::strip_subject_control_chars(
			sprintf(
				/* translators: %s: site name. */
				__( '[WPCV] Test alert from %s', 'wp-checksum-verifier' ),
				WPCV_Alert_Composer::clean_site_name( (string) get_option( 'blogname' ) )
			)
		);
		$body = __( 'This is a test alert from WP Checksum Verifier. If you received this message, your alert recipient setting is working correctly.', 'wp-checksum-verifier' ) . "\n";

		$mail_error = null;
		$sent       = $this->send_mail( $alert_to, $subject, $body, $mail_error );

		if ( $sent ) {
			return array(
				'action' => 'sent',
				'error'  => null,
			);
		}

		return array(
			'action' => 'failed',
			'error'  => $mail_error,
		);
	}

	/**
	 * 件名から改行・制御文字を除く(§6: ヘッダーインジェクション対策.
	 * `WPCV_Alert_Composer::build_subject()`と同じ理由だが、あちらはprivateの
	 * `strip_control_chars()`のためここでは複製する ―― `send_test()`専用の
	 * 1行だけの処理であり、公開APIを増やすほどではないと判断した).
	 *
	 * @param string $subject 件名.
	 * @return string
	 */
	private static function strip_subject_control_chars( $subject ) {
		return (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $subject );
	}

	/**
	 * 通知候補(diff_state new/continuing/event)を500件ずつバッチで読み、
	 * `should_notify_finding()`で絞り込む(§2.4).
	 *
	 * バッチ内のfinding_keyをまとめて`find_last_notified_at_by_keys()`に照会する
	 * (targeted lookup. Step12 §1.4と同じ考え方).
	 *
	 * `top_items`は本文に載せる上位N件(`WPCV_Alert_Composer::max_items()`)だけを
	 * 持つ.バッチを読むたびに`WPCV_Alert_Composer::sort_top_items()`で切り詰める
	 * (コードレビュー指摘4. 以前は全件を持っており、10万件で約47MBになった
	 * 〔2026-09-27 実測〕).`notify_ids`は送信成功後に`notified_at`を書くために
	 * 全件持つ(10万件で約2MB〔同日実測〕のため許容する).
	 *
	 * @param int    $run_id      対象のrunのid.
	 * @param string $now         現在時刻(MySQL DATETIME文字列).
	 * @param int    $resend_days 再送抑制の日数.
	 * @return array{notify_count: int, notify_ids: int[], top_items: array<int, array>}
	 */
	private function gather_notify_candidates( $run_id, $now, $resend_days ) {
		$notify_count = 0;
		$notify_ids   = array();
		$top_items    = array();
		$after_id     = 0;
		$max_items    = WPCV_Alert_Composer::max_items();

		while ( true ) {
			$batch = $this->finding_repository->find_notify_candidates_batch( $run_id, $after_id, self::NOTIFY_BATCH_SIZE );

			if ( empty( $batch ) ) {
				break;
			}

			$keys = array();

			foreach ( $batch as $row ) {
				$keys[] = (string) ( $row['finding_key'] ?? '' );
			}

			$last_notified_at_by_key = $this->finding_repository->find_last_notified_at_by_keys( array_values( array_unique( $keys ) ), $run_id );

			foreach ( $batch as $row ) {
				$after_id = (int) $row['id'];

				$key              = (string) ( $row['finding_key'] ?? '' );
				$last_notified_at = $last_notified_at_by_key[ $key ] ?? null;

				if ( ! WPCV_Generation_Differ::should_notify_finding( $row['diff_state'] ?? null, $last_notified_at, $now, $resend_days ) ) {
					continue;
				}

				$notify_ids[] = (int) $row['id'];
				++$notify_count;

				$top_items[] = array(
					'severity'  => $row['severity'] ?? '',
					'target_id' => $row['target_id'] ?? '',
					'path'      => $row['path'] ?? '',
					'status'    => $row['status'] ?? '',
				);
			}

			// 1バッチ分を足したら上位N件まで切り詰める(このメソッドのdocblock参照).
			$top_items = WPCV_Alert_Composer::sort_top_items( $top_items, $max_items );

			if ( count( $batch ) < self::NOTIFY_BATCH_SIZE ) {
				break;
			}
		}

		return array(
			'notify_count' => $notify_count,
			'notify_ids'   => $notify_ids,
			'top_items'    => $top_items,
		);
	}

	/**
	 * 今回のrunでversion変更によりstat基準を作り直した(`error_code = baseline_rebuilt`)
	 * target_runから、本文の`baseline_rebuilt`項目(U3)を組み立てる.
	 *
	 * 対象がstatターゲットの場合、`baseline_target_run_id`列は差分処理(Step12)が
	 * 設定しない設計のため、`from_version`を得るには`find_baseline_target_run()`
	 * をここでもう一度呼ぶ必要がある(§3設計上の決定参照).
	 *
	 * @param array<int, array> $target_runs 今回のrunのtarget_run行一覧.
	 * @param int               $run_id      対象のrunのid.
	 * @return array<int, array{target_id: string, from_version: string, to_version: string}>
	 */
	private function gather_baseline_rebuilt( array $target_runs, $run_id ) {
		$items = array();

		foreach ( $target_runs as $target_run ) {
			if ( WPCV_Error_Code::BASELINE_REBUILT !== ( $target_run['error_code'] ?? null ) ) {
				continue;
			}

			$target_id = (string) ( $target_run['target_id'] ?? '' );
			$baseline  = $this->target_run_repository->find_baseline_target_run( $target_id, $run_id );

			$items[] = array(
				'target_id'    => $target_id,
				'from_version' => (string) ( $baseline['version'] ?? '' ),
				'to_version'   => (string) ( $target_run['version'] ?? '' ),
			);
		}

		return $items;
	}

	/**
	 * 検出結果画面のURLを返す(マルチサイトならネットワーク管理画面).
	 *
	 * @return string
	 */
	private function details_url() {
		return is_multisite()
			? network_admin_url( 'admin.php?page=wpcv-findings' )
			: admin_url( 'admin.php?page=wpcv-findings' );
	}

	/**
	 * `wp_mail()`でメールを送る(§4.2. WPMAR `WPMAR_Notifier_Mail::send_pair()`と
	 * 同じ`wp_mail_failed`パターン).
	 *
	 * 成功 = `wp_mail()`がtrueを返し、かつ送信中に`wp_mail_failed`が発火しな
	 * かったこと. 失敗時は`$error_message`に`WP_Error::get_error_message()`
	 * だけを入れる(`get_error_data()`は保存しない. §6).
	 *
	 * @param string[]    $to             宛先.
	 * @param string      $subject        件名.
	 * @param string      $body           本文.
	 * @param string|null $error_message  参照渡し. 失敗時のエラーメッセージ
	 *                                    (原因不明なら`null`のまま).
	 * @return bool
	 */
	private function send_mail( array $to, $subject, $body, &$error_message ) {
		$mail_failed_error = null;

		$handler = static function ( $wp_error ) use ( &$mail_failed_error ) {
			$mail_failed_error = $wp_error;
		};

		add_action( 'wp_mail_failed', $handler );

		try {
			$result = wp_mail( $to, $subject, $body );
		} finally {
			remove_action( 'wp_mail_failed', $handler );
		}

		if ( null !== $mail_failed_error ) {
			$error_message = $mail_failed_error instanceof WP_Error ? $mail_failed_error->get_error_message() : null;

			return false;
		}

		return (bool) $result;
	}

	/**
	 * `wpcv_alert_channels`フィルターで登録された追加チャネルを実行する
	 * (§4.3. D6. WPMAR `wpmar_notification_channels`と同じ形だが、例外は
	 * 握りつぶさず失敗として記録する).
	 *
	 * `$context`にはalert_error・トークン・絶対パス・HTTPエラー本文を含めない(§6).
	 *
	 * @param int    $run_id      対象のrunのid.
	 * @param string $subject     件名.
	 * @param string $body        本文.
	 * @param array  $counts      new/resolved/continuingの件数.
	 * @param string $details_url 検出結果画面のURL.
	 * @return string|null 失敗したチャネルの`name`をカンマ区切りにした文字列
	 *                      (1件も失敗していなければ`null`).
	 */
	private function run_channels( $run_id, $subject, $body, array $counts, $details_url ) {
		$context = array(
			'run_id'      => $run_id,
			'subject'     => $subject,
			'body'        => $body,
			'counts'      => $counts,
			'details_url' => $details_url,
		);

		$channels = apply_filters( 'wpcv_alert_channels', array(), $context );
		$failed   = array();

		foreach ( (array) $channels as $channel ) {
			$name = (string) ( $channel['name'] ?? '' );
			$send = $channel['send'] ?? null;

			try {
				$ok = is_callable( $send ) && true === call_user_func( $send, $context );
			} catch ( Throwable $e ) {
				$ok = false;
			}

			if ( ! $ok ) {
				$failed[] = $name;
			}
		}

		return empty( $failed ) ? null : implode( ', ', $failed );
	}
}
