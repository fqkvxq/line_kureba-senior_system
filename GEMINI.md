# アップファーレン LINE顧客・点検管理システム 開発・運用ガイドライン

## 開発方針 & 最重要ルール

### 1. 操作マニュアルの継続的更新【最重要】
ユーザー様からの要求により、**「今後も機能追加した場合は、必ずマニュアルページ（`public_html/admin/manual.html`）を同時に更新すること」** が義務付けられています。
新機能の追加、仕様変更、画面UIの改修を行った際は、以下を必ず実施してください：
1. `public_html/admin/manual.html` に新機能の説明、操作手順、ワンポイントTips、必要に応じてFAQを追記・更新する。
2. 目次（TOC）やクイックリンク、検索対象のセクション構成をメンテナンスする。
3. キャッシュ対策として、JS/CSSのバージョンクエリ（`?v=...`）を更新する。

### 2. システム構成
- **顧客・点検管理**: `public_html/admin/index.html`, `admin.js`, `admin.css`
- **リッチメニュー管理**: `public_html/admin/richmenu.html`, `richmenu.js`, `richmenu.css`
- **操作マニュアル**: `public_html/admin/manual.html`, `manual.js`, `manual.css`
- **APIバックエンド**: `public_html/api.php`, `config.php`
- **顧客向けLIFF画面**: `public_html/liff.html`

### 3. デプロイ & バージョン管理
- 作業ブランチ: `main`
- 変更後は必ず `git add`, `git commit`, `git push origin main` を実行してリポジトリに反映する。
