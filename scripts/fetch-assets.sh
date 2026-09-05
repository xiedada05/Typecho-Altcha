#!/usr/bin/env bash
# 下载前端组件构建产物到 Altcha/assets/ (产物不入库, 仅发布 zip 与本地开发使用)
# 版本单一来源: Altcha/Plugin.php 的 WIDGET_VERSION 常量
#
# 用法: bash scripts/fetch-assets.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_PHP="$ROOT/Altcha/Plugin.php"
DEST="$ROOT/Altcha/assets"

VER=$(grep -oP "const WIDGET_VERSION = '\K[0-9.]+" "$PLUGIN_PHP")
if [ -z "$VER" ]; then
  echo "错误: 未能从 Plugin.php 解析出 WIDGET_VERSION" >&2
  exit 1
fi

BASE="https://cdn.jsdelivr.net/npm/altcha@${VER}"
mkdir -p "$DEST"

download() {
  local url="$1" out="$2"
  echo "下载 $(basename "$out") (altcha@$VER) ..."
  curl -fsSL --retry 3 "$url" -o "$out"
}

download "$BASE/dist/main/altcha.min.js"        "$DEST/altcha.min.js"
download "$BASE/dist/main/altcha.i18n.min.js"   "$DEST/altcha.i18n.min.js"
download "$BASE/dist/plugins/obfuscation.plugin.min.js" "$DEST/obfuscation.plugin.min.js"

echo "完成: $DEST"
