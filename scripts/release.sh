#!/bin/bash
# 发布新版本：./scripts/release.sh 1.3.1
# 前提：CHANGELOG.md 里已写好 “## v1.3.1” 这一节；gh 已登录
# 会做：改版本号 → 提交并打标签 → 打包 zip 和指纹 → 推送 → 创建 GitHub Release
set -euo pipefail
cd "$(dirname "$0")/.."
VER="${1:-}"
[[ "${VER}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "用法：./scripts/release.sh 1.3.1"; exit 1; }
TAG="v$VER"
git rev-parse "${TAG}" >/dev/null 2>&1 && { echo "❌ ${TAG} 已经存在"; exit 1; }
grep -q "^## ${TAG}\$" CHANGELOG.md || { echo "❌ 请先在 CHANGELOG.md 里写好 “## ${TAG}” 这一节"; exit 1; }
gh auth status >/dev/null 2>&1 || { echo "❌ 还没登录 GitHub，先运行 gh auth login"; exit 1; }

NOTES=$(awk -v tag="## ${TAG}" '$0 == tag {on=1; next} /^## v/ {on=0} on' CHANGELOG.md | sed -e '/./,$!d')

MAIN=cos-uploader/cos-uploader.php
sed -i '' -E "s/^( \* Version: +).*/\1${VER}/" "$MAIN"
sed -i '' -E "s/^define\('COSU_VERSION', '[^']*'\);/define('COSU_VERSION', '${VER}');/" "$MAIN"
grep -q "Version:           ${VER}\$" "$MAIN" && grep -q "define('COSU_VERSION', '${VER}');" "$MAIN" || { echo "❌ 版本号没改成功"; exit 1; }
for f in cos-uploader/*.php cos-uploader/includes/*.php; do php -l "$f" >/dev/null; done

git add -A
git diff --cached --quiet || git commit -qm "发布 ${TAG}" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git tag -a "${TAG}" -m "COS 图片上传 ${TAG}"

rm -rf dist && mkdir -p dist
git archive --format=zip --prefix=cos-uploader/ -o dist/cos-uploader.zip "${TAG}:cos-uploader"
(cd dist && shasum -a 256 cos-uploader.zip > cos-uploader.zip.sha256)
cp dist/cos-uploader.zip ./cos-uploader.zip

BRANCH=$(git rev-parse --abbrev-ref HEAD)
git push -q origin "$BRANCH" "${TAG}"
gh release create "${TAG}" dist/cos-uploader.zip dist/cos-uploader.zip.sha256 --title "COS 图片上传 ${TAG}" --notes "$NOTES"
echo "🎉 已发布 ${TAG}，网站后台「设置 → COS 图片上传 → 更新」点检查更新就能看到"
