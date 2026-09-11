# WP Checksum Verifier

WordPress のコア・プラグイン・MU プラグインの checksum を検証し、改ざんを検出する
プラグイン。公式の checksum マニフェスト(wp.org のコア/プラグイン checksum)と
実ファイルを突き合わせ、どのマニフェストにも存在しない未知のファイルも報告する。

> **ステータス**: v0.3.1。検証エンジンと計画していた全ての実行モデル
> (WP-CLI・WP-Cron・管理画面の「今すぐ実行」ボタン・REST API)を実装済み。
> 公式テーマの照合と、GitHub Releases 上の非公式プラグイン/テーマの照合は
> まだ未実装。詳細は `CHANGELOG.md` を参照.

## 検証対象

- **WordPress コア**: インストール済みバージョン/ロケールの公式 checksum と照合する.
- **公式(wp.org)プラグイン**: インストール済みバージョンの公式 checksum と照合する.
- **MU プラグイン**: 公式の checksum ソースが存在しないため、未知ファイルの検出のみ行う.
- **未知ファイル**: 上記いずれの対象についても、マニフェストに存在しないファイルは
  finding として報告する.
- 未実装: 公式テーマの照合、GitHub Releases 上の非公式プラグイン/テーマの照合.

## 検証の実行方法

| モード | 方法 | 備考 |
| --- | --- | --- |
| WP-CLI(同期) | `wp wpcv run` | 既定. 完了まで待機する. |
| WP-CLI(非同期) | `wp wpcv run --async` | Action Scheduler が利用可能なら非同期でキューに追加. 利用不可なら同期にフォールバック. |
| WP-Cron | 自動 | 設定画面で指定したUTC時刻に毎日実行. 実行のたびに次回分を自己連鎖で再予約する. |
| 管理画面のボタン | 設定画面の「今すぐ実行」 | リクエストをブロックせず即時実行を予約する. `DISABLE_WP_CRON` が設定されている場合は無効化される. |
| REST API | `POST /wp-json/wpcv/v1/run` | WP-Cronを使えない外部スケジューラー向け. 詳細は下記. |

いずれの経路でも、実行開始前に `running` のまま止まっている過去の run
(実行途中の致命的エラー等)を検知し `failed` にしてから開始する.

### 実行モデル

1回のrunは全targetを一気に検証するのではなく、まずコア・各公式プラグイン・
MUプラグインすべてをDB上のtarget行として先に列挙し、その後1chunkずつ
処理する:

- 各chunkは件数・経過時間・メモリ余裕で区切られた範囲のファイルだけを検証し、
  yieldする前にcursor(最後に確認したpath、manifestのfingerprint、target
  のversion)を保存する。次のchunkはそのcursorから再開する.
- cursor保存後にmanifestのfingerprintやtargetのversionが変わっていた場合
  (例: run途中でプラグインが更新された)は、そのままの内容で続行せず
  targetを最初からやり直す(retry)扱いにする.
- WP-Cron・CLI `--async`・`POST /run` はすべて同じdispatcherをAction
  Scheduler action経由(RESTの場合は呼び出し元の次回ポーリング経由)で
  起動する ―― 「非同期用の別ロジック」を別途保守する必要はない.
- WP-CLI(同期)と管理画面の「今すぐ実行」も同じchunk単位のdispatcherを
  使い、同一プロセス内でrunが終端状態に達するまで同期的にループするだけ
  なので、どのモードでも結果は同一になる.

つまり大規模サイトのrunは(意図的にdispatcherを自らループさせて同期的に
ブロックするCLI同期・管理画面ボタンのモードを除き)1つの長時間ブロッキング
処理ではない ―― 別々のHTTPリクエスト・別々のAction Scheduler action・
プロセス再起動をまたいでも進捗は失われない.

### タイムアウトとstale状態

時間に関連する概念が3つ登場し、混同しやすいので整理する:

- **chunkの時間予算** — 1回のchunkがcursorを保存してyieldするまでに
  許容される時間(例: `POST /run`のExternal HTTP time budget設定、既定20秒)。
  この上限に達すること自体は正常な動作でエラーではない ―― targetの状態は
  `retry`になり、次のchunkが続きから処理する.
- **stale lease/worker** — claim済み(`running`)のtargetはそれぞれ
  期限付きのleaseを持つ。claimしたworkerがleaseの期限までに応答しなかった
  場合(クラッシュ・強制終了・PHP fatal等)、そのtargetは再claimされ
  retryされる。これが最大試行回数を超えると、error_code
  `lease_expired`とともに`failed`になる.
- **runのdeadline** — run全体には既定6時間の上限がある。それまでに
  終端状態に達しなかった場合、runおよび終端に達していないtargetは
  すべて`aborted`にされる。これにより、詰まったrunが次回予定runを
  無期限にブロックすることはない.

### REST API

以下のすべてのエンドポイントはbearerトークンが必要:
`Authorization: Bearer <token>`(優先)または `X-WPCV-Token: <token>` で
送る。クエリパラメータでの指定は意図的にサポートしていない。すべての応答に
`Cache-Control: no-store` を付与する。同一IPからの認証失敗が続くとレート
制限がかかる。トークンは互いに独立した2つのscopeに分かれており、それぞれ
設定画面から個別に発行する(生成時に1回だけ画面に表示し、DBにはソルト付き
ハッシュのみを保存する):

- **run** — `POST /run` に必要。`wp-config.php` 等で `WPCV_REST_TOKEN` 定数を
  定義すると、設定画面で発行したrun scopeのトークンより優先される
  (read scopeには適用されない)。
- **read** — `GET /status`・`GET /findings` に必要。run scopeとは別に発行し、
  run scopeのトークンではこれらを呼べず、read scopeのトークンでは
  `POST /run` を呼べない。

#### `POST /run`

このエンドポイントは外部スケジューラーから(例: 5分間隔で)繰り返し呼ばれる
ことを前提にしており、呼ばれるたびに新規runを作るわけではない:

- 既にrunが進行中(`queued`または`running`)なら、そのrunのchunk分割実行
  (WP-Cron・WP-CLIと同じtarget/ファイル単位のdispatcher)を、設定画面の
  時間予算(既定20秒)の範囲内で前進させてから応答する.
- 進行中のrunが無く、設定した日次実行時刻(UTC。WP-Cronと共通の設定値)を
  過ぎていて、かつ本日分のrunがまだ無ければ、新規runを作成して同様に
  前進させる.
- それ以外(まだ実行時刻前、または本日分は既に作成済み)の場合は何も
  作成せず、直近runの状態を報告する.

応答には `run_id`・`status`・`pending_targets`・`retry_targets`・
`next_retry_at` を含み、呼び出し元はrunがまだ進行中かどうかを見て
ポーリングを続けられる。このエンドポイントはグローバルなAction Scheduler
キューを実行することは無く、あくまで自身のrunだけを前進させる。
busy(advisory lockの競合)や内部エラーの場合は200ではなくエラー応答を返す.

WP-Cronが機能しないホスト(`DISABLE_WP_CRON`設定時や、トリガーとなる
アクセスが無いサイト等)では、外部スケジューラーからこのエンドポイントを
5分間隔で叩く。crontabの例:

```cron
*/5 * * * * curl -s -X POST -H "Authorization: Bearer <run scopeトークン>" https://example.com/wp-json/wpcv/v1/run >/dev/null
```

runが進行中のときの応答例:

```json
{
  "run_id": 42,
  "status": "running",
  "pending_targets": 3,
  "retry_targets": 1,
  "next_retry_at": "2026-09-11T13:05:00+00:00"
}
```

#### `GET /status`

read scopeのトークンが必要。`current_run`(進行中のrun。無ければ `null`)・
`last_run`(直近に完了したrun。無ければ `null`)・`next_scheduled_at`
(設定画面の実行時刻から計算した次回の日次due時刻。実際にどのモードが
それを起動するかは問わない)を返す。各runには、target状態別の集計
(`queued`・`retry`・`running`・`success`・`unverifiable`・`failed`・
`skipped`・`aborted`・`total`)・`findings_total`・`scheduled_for`・
`deadline_at`・`last_activity_at`(最後にtargetがclaim・確定された時刻。
進捗が止まったrunを見つけるのに使える)を含む.

応答例:

```json
{
  "current_run": null,
  "last_run": {
    "run_id": 37,
    "status": "partial",
    "run_trigger": "cli",
    "started_at": "2026-09-11T05:45:00+00:00",
    "finished_at": "2026-09-11T05:46:12+00:00",
    "scheduled_for": "2026-09-11T05:45:00+00:00",
    "deadline_at": "2026-09-11T11:45:00+00:00",
    "last_activity_at": "2026-09-11T05:46:10+00:00",
    "findings_total": 7,
    "targets": {
      "queued": 0, "retry": 0, "running": 0, "success": 36,
      "unverifiable": 7, "failed": 0, "skipped": 0, "aborted": 0, "total": 43
    }
  },
  "next_scheduled_at": "2026-09-12T05:45:00+00:00"
}
```

#### `GET /findings`

read scopeのトークンが必要。1つのrun(既定は最新run。`run_id`クエリ
パラメータで指定も可能)のfindingsを返す。`dimension`・`status`・
`severity`(単一値または配列。例: `dimension[]=core&dimension[]=plugin`。
それぞれ固定のallowlist外の値を渡すと`400`)・`sort`/`order`
(allowlistされた列のみ)・`page`/`per_page`(小さい既定値・上限あり)の
pagination に対応する。suppressed・closedなfindingは既定で除外し、
`include_suppressed=1`/`include_closed=1` で含められる。応答には
`findings`・`run_id`・`page`・`per_page`・`total`・`total_pages` を含む.

応答例:

```json
{
  "findings": [
    {
      "id": 101, "run_id": 37, "target_id": "plugin:hello-dolly",
      "dimension": "plugin", "slug": "hello-dolly", "version": "1.7.2",
      "path": "readme.txt", "status": "modified", "severity": "low",
      "hash_algorithm": "sha256", "expected_hash": "...", "actual_hash": "...",
      "suppressed_by": "soft_change", "suppression_id": null
    }
  ],
  "run_id": 37,
  "page": 1,
  "per_page": 20,
  "total": 1,
  "total_pages": 1
}
```

## 管理画面

設定画面(下記)に加えて、同じ「Checksum Verifier」トップレベルメニュー配下
(マルチサイトではネットワーク管理画面)に3つの読み書き画面がある:

- **検出結果(Findings)** — 直近run(または指定した`run_id`)のfindingsを、
  `GET /findings`と同じ`dimension`/`status`/`severity`/suppressed/closed
  フィルタ付きで表示する。各行から理由入力必須の3操作をワンクリックで
  実行できる: **パス除外**(そのtarget・pathに限定した`exclude_path`
  ルールを作成)、**このhashを承認**(表示中のhash/versionをそのまま
  `allowlist_hash`ルールとして作成。`added`/`modified`のfinding ―— 承認
  対象となるhashを実際に持つもの ―— にのみ表示)、**targetごと除外**
  (そのプラグイン・コア・MUプラグインを次回run以降まるごと検証対象外に
  する`exclude_target`ルールを作成)。いずれも画面に表示中のfindingを
  遡って書き換えることはなく、次回run以降から適用される.
- **抑制一覧(Suppressions)** — これまでに作成された全ての抑制ルール
  (3種別すべて)を、対象・理由・作成者・作成日時・(`allowlist_hash`のみ)
  承認済みversionとhashの先頭部分とともに表示する。有効なルールは
  (理由入力必須で)取消でき、削除ではなく取消として記録・併記される.
- **実行履歴(Run History)** — 全runを新しい順に表示し、run詳細では
  各targetの状態・`error_code`(`unverifiable`/`retry`/`aborted`/`skipped`
  なtargetの理由を人間可読なラベルに変換したもの)・ファイル件数・
  試行回数を確認できる.

## 設定

設定画面(マルチサイトではネットワーク管理画面)は**状態パネル**から
始まる: 進行中のrunと直近完了run(それぞれのtarget集計・最終活動時刻)、
次回予定実行時刻、WP-Cronが有効かどうか、Action Schedulerが利用可能か
どうか(利用不可の場合、非同期実行は黙って同期実行にフォールバックする)、
このサイトで記録されている直近のWP-CLI実行(あくまで「ここに記録された
run」を反映するだけで、サーバーにWP-CLI自体がインストールされているか
どうかを検出するものではない)。その下から、日次実行時刻
(UTC。WP-CronとRESTの日次due判定が共通で使う)・RESTエンドポイントの
時間予算・RESTトークンの発行を設定できる.

## 配布方針

本プラグインは WordPress.org Plugin Directory には公開しない。GitHub Releases
経由の自己更新機構(`Update URI: false`、
[l2d-wp-github-update-lib](https://github.com/lunaluna/l2d-wp-github-update-lib)
を利用)で配布する。

## 動作要件

- PHP 7.4+
- WordPress 6.8+ (同梱する Action Scheduler の最新安定版が要求する最小バージョンに
  合わせている)

## 開発

```sh
composer install
composer run lint     # PHPCS
composer run analyse  # PHPStan
composer run test     # PHPUnit
```

`composer install` するだけで、非同期実行(v0.3以降)の基盤である Action
Scheduler もローカルで使えるようになる(`lib/` への手動コピーは不要)。
開発環境では `vendor/woocommerce/action-scheduler` から自動的に読み込む
(`WPCV_Action_Scheduler_Loader` 参照)。`lib/` へのコピーはリリースビルド時
(`bin/build-zip.pre.sh`)にのみ生成される、配布zip専用のものである.

English version: [README.md](README.md)
