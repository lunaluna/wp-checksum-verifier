=== WP Checksum Verifier ===
Contributors: lunaluna_dev
Tags: security, checksum, integrity, malware, audit
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress のコア・プラグイン・テーマ・MU プラグインの checksum を検証し、公式マニフェストとの差分から改ざんを検出するプラグインです。

== 説明 ==

WP Checksum Verifier は、WordPress コア・公式プラグイン・MU プラグインの公式 checksum マニフェストと、実際に配置されているファイルを突き合わせて検証し、どのマニフェストにも存在しない未知のファイルも報告します。WordPress.org Plugin Directory には公開していません。配布方法の詳細は README-ja.md を参照してください。

公式テーマおよび GitHub Releases 上の非公式プラグイン/テーマの照合はまだ未実装です。進捗は CHANGELOG.md を参照してください。

= 検証の実行方法 =

* **WP-CLI**: `wp wpcv run` で同期実行(既定)。`wp wpcv run --async` は Action Scheduler が利用可能なら非同期でキューに追加する。
* **WP-Cron**: 設定画面で指定したUTC時刻に毎日自動実行する(プラグイン有効化と同時に有効になる)。
* **管理画面のボタン**: 設定画面の「今すぐ実行」ボタンで即時実行を予約する。
* **REST API**: `POST /wp-json/wpcv/v1/run`。設定画面で発行するトークンによる認証が必要(WP-Cronを使えないマネージドホスティング等の外部スケジューラー向け)。

== インストール ==

1. GitHub Releases からリリース zip をダウンロードする.
2. 他のプラグインと同様にアップロード・有効化する.

== 変更履歴 ==

CHANGELOG.md を参照してください。
