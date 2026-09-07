#!/usr/bin/env bash
# 汎用ビルダー(lib/l2d-updater/bin/build-zip.sh)がステージング前に実行する
# WP Checksum Verifier 固有の前処理フック.
#
# WPMAR (wp-maintenance-audit-reporter) の同名スクリプトと同じパターン: Action
# Scheduler は実行時依存だが vendor/ ごとは同梱しない(.distignore で vendor を
# 除外する)。composer install --no-dev でいったん vendor/ に取得し、
# Action Scheduler のパッケージディレクトリ 1 つだけを lib/ へコピーする.
set -euo pipefail

# 1. 本番依存のみをインストールする。vendor/ は .distignore で ZIP から除外される
#    が、Action Scheduler のコピー元として必要.
if command -v composer &>/dev/null; then
  echo "Running composer install --no-dev ..."
  composer install --no-dev --prefer-dist --no-progress --optimize-autoloader
else
  echo "Warning: composer not found. Skipping dependency install." >&2
fi

# 2. 初回有効化から必須の Action Scheduler を lib/ へコピーする.
#    Action Scheduler は自己完結(自前のブートストラップがクラスを読む)なので、
#    パッケージディレクトリ 1 つを配置すればよい.
AS_SRC="vendor/woocommerce/action-scheduler"
AS_DEST="lib/action-scheduler"
if [ ! -d "$AS_SRC" ]; then
  echo "Error: $AS_SRC not found. Run 'composer install' so Action Scheduler can be bundled into lib/." >&2
  exit 1
fi
mkdir -p "$AS_DEST"
rsync -a --exclude='.git' --exclude='.github' --exclude='tests' --exclude='docs' \
  "$AS_SRC/" "$AS_DEST/"
echo "Bundled Action Scheduler -> ${AS_DEST}"
