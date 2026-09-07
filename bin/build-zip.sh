#!/usr/bin/env bash
#
# 配布用 ZIP をビルドする.
#
# 実処理は同梱ライブラリの汎用ビルダーに委譲する。除外定義はプラグインルートの
# .distignore が単一の正であり、リリースワークフローの build-zip composite
# action もこのスクリプトを経由して同じ .distignore を読む.
#
# 呼び出し先はディレクトリを移動しないため、プラグインルートで実行すること.

set -euo pipefail

bash lib/l2d-updater/bin/build-zip.sh
