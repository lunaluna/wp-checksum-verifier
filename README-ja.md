# WP Checksum Verifier

WordPress のコア・プラグイン・テーマ・MU プラグインの checksum を検証し、改ざんを
検出するプラグイン。公式の checksum マニフェスト(wp.org のコア/プラグイン
checksum、自前展開して比較する公式テーマアーカイブ、非公式プラグイン/テーマ向けの
GitHub Releases)と実ファイルを突き合わせる。

> **ステータス**: 開発中(v0.1、未リリース)。進捗は `CHANGELOG.md` を参照。

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
