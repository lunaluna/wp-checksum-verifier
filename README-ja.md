# WP Checksum Verifier

WordPress のコア・プラグイン・MU プラグインの checksum を検証し、改ざんを検出する
プラグイン。公式の checksum マニフェスト(wp.org のコア/プラグイン checksum)と
実ファイルを突き合わせ、どのマニフェストにも存在しない未知のファイルも報告する。

> **ステータス**: v0.3.0。検証エンジンと計画していた全ての実行モデル
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

### REST API

`POST /wp-json/wpcv/v1/run` には、設定画面で発行するトークンが必要
(生成時に1回だけ画面に表示し、DBにはソルト付きハッシュのみを保存する)。
`Authorization: Bearer <token>`(優先)または `X-WPCV-Token: <token>` で
送る。クエリパラメータでの指定は意図的にサポートしていない。
`wp-config.php` 等で `WPCV_REST_TOKEN` 定数を定義すると、設定画面で発行した
トークンより優先される。同一IPからの認証失敗が続くとレート制限がかかる。

このエンドポイントは冪等: 既にrunが進行中(`queued`または`running`)なら
新規runを作らずそのrun_idと状態を返す。そうでなければ同じHTTPリクエスト内で
検証を同期実行し、完了してから応答する — オポチュニスティックなキュー消化や
時間予算は無い。そのため、1リクエスト内で完走できる規模のサイトでの利用に
限られる(ファイル単位の分割実行・resumeは将来のリリースで対応予定)。
busy(advisory lockの競合)や内部エラーの場合は200ではなくエラー応答を返す.

## 設定

設定画面(マルチサイトではネットワーク管理画面)から、WP-Cronの日次実行時刻
(UTC)・RESTトークンの発行を設定できる.

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
