#!/bin/bash
# 他社配布用クリーンパッケージ生成スクリプト (Bash)
set -e

CURRENT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATE_STR="$(date +%Y%m%d)"
DIST_DIR="${CURRENT_DIR}/dist"
TEMP_STAGING="${DIST_DIR}/staging"
ZIP_PATH="${DIST_DIR}/line_system_clean_package_${DATE_STR}.zip"

echo "=== [配布用クリーンパッケージ生成] 開始 ==="

rm -rf "${TEMP_STAGING}"
mkdir -p "${TEMP_STAGING}" "${DIST_DIR}"

echo "--> ソースコードをステージングへコピー中..."
cp -R "${CURRENT_DIR}/public_html" "${TEMP_STAGING}/"
cp -R "${CURRENT_DIR}/batch" "${TEMP_STAGING}/"
[ -f "${CURRENT_DIR}/.htaccess" ] && cp "${CURRENT_DIR}/.htaccess" "${TEMP_STAGING}/"
[ -f "${CURRENT_DIR}/index.php" ] && cp "${CURRENT_DIR}/index.php" "${TEMP_STAGING}/"
[ -f "${CURRENT_DIR}/SETUP_GUIDE.md" ] && cp "${CURRENT_DIR}/SETUP_GUIDE.md" "${TEMP_STAGING}/"
[ -f "${CURRENT_DIR}/README.md" ] && cp "${CURRENT_DIR}/README.md" "${TEMP_STAGING}/"

echo "--> 個人情報・データベース・テスト画像を除外中..."
find "${TEMP_STAGING}" -name "*.db" -o -name "*.sqlite" -o -name "*.log" | xargs -r rm -f
find "${TEMP_STAGING}" -name "*.ps1" -o -name "*.py" -o -name ".DS_Store" | xargs -r rm -f

# アカウント情報初期化
if [ -f "${TEMP_STAGING}/public_html/data/line_accounts.sample.json" ]; then
    cp "${TEMP_STAGING}/public_html/data/line_accounts.sample.json" "${TEMP_STAGING}/public_html/data/line_accounts.json"
fi

# アップロード画像のクリーンアップ
find "${TEMP_STAGING}/public_html/uploads/richmenu" -name "custom_*" | xargs -r rm -f

echo "--> ZIPパッケージを作成中: ${ZIP_PATH}"
rm -f "${ZIP_PATH}"
(cd "${TEMP_STAGING}" && zip -r "${ZIP_PATH}" . -q)

rm -rf "${TEMP_STAGING}"
echo "=== [完了] クリーンパッケージ生成完了: ${ZIP_PATH} ==="
