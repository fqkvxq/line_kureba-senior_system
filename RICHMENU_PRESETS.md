# 🌟 KUREBA_LINE リッチメニュー・プリセット管理マニュアル

このドキュメントは、合同会社KUREBAのLINE公式アカウントにおけるリッチメニューの**黄金比・座標・フォント設定・各モードの仕様**を永久保存し、いつでも切り替え・復元できるようにまとめた設計書です。

---

## 📌 1. タップ領域（areas）の完全仕様（元設定）

下部のタップ領域は、以下の元のオートSNS（Proline）連携LIFFリンクが完全に設定されています：

- **左側エリア (x: 25, y: 320, width: 1350, height: 1332)**:
  `https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1`
- **右側エリア (x: 1375, y: 320, width: 1100, height: 1315)**:
  `https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcp%2FA9xhz7MWZF%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1`

---

## 📌 2. 【永久保存版】三島市リアルタイム天気＆名言リッチメニュー

### ■ 概要
- **機能**: 静岡県三島市のリアルタイム天気・最高/最低気温・直近24時間の雨予測・週間雨予報・時間別格言を表示。
- **ファイル**: `mcp-server/presets/mishima_weather.js`
- **ワンコマンド復元・適用**:
  ```bash
  node mcp-server/presets/runner.js mishima_weather
  ```

### ■ 黄金比デザイン仕様（上部 300px）
- **グラデーション**: `#FF8700` 〜 `#EA7200`（境界線: `#FFE0B2`, 6px）
- **フォント**: `LINESeedJP-Bold.ttf`（文字影: `rgba(0, 0, 0, 0.35)`）
- **1行目 (Y=62, 52px)**: 【日付・時間】 三島市の天気 [絵文字 54px] (最高/最低気温)
- **2行目 (Y=145, 48px)**: 直近の雨情報（傘/太陽絵文字 50px）
- **3行目 (Y=228, 48px)**: 週間予報（傘/太陽絵文字 50px）

---

## 🔮 3. 【新機能】毎日更新！12星座 星占いリッチメニュー（パーソナライズ対応）

### ■ 概要
天気メニューと**全く同じ枠（上部 300px）・同じ黄金比フォントサイズ**で、毎日「今日の運勢・ラッキーカラー・アドバイス」を表示します。
ユーザーが自分の星座を設定すると、**その人専用の星座占いリッチメニュー**に自動で切り替わります！

- **ファイル**:
  - 描画プリセット: `mcp-server/presets/daily_fortune.js`
  - 占いエンジン: `mcp-server/presets/fortune_engine.js`
  - 12星座一括パーソナライズ配信バッチ: `mcp-server/presets/batch_fortune_personalized.js`
  - LIFF星座選択画面: `public_html/liff/zodiac.html`

### ■ デザイン仕様（上部 300px）
- **グラデーション**: 上品な星空パープル `#6B21A8` 〜 `#3B0764`（境界線: `#DDD6FE`, 6px）
- **フォント**: `LINESeedJP-Bold.ttf`（文字影: `rgba(0, 0, 0, 0.4)`）

| モード | 1行目 (Y=62, 52px Bold) | 2行目 (Y=145, 48px Bold) | 3行目 (Y=228, 48px Bold) |
| :--- | :--- | :--- | :--- |
| **星座設定ユーザー (例: 獅子座)** | `【日付】 ♌ 獅子座の運勢：第1位 ✨ (★★★★★)` | `🎨 カラー：ゴールド ｜ 🍀 温かい緑茶` | `💡 直感を信じて一歩踏み出すと嬉しい展開が！` |
| **未設定ユーザー (全体モード)** | `【日付】 今日の星占い：第1位は【獅子座 ♌】👑✨` | `🎨 全体カラー：ゴールド ｜ 🍀 温かい緑茶` | `💡 星座を登録するとあなた専用の占いに変わります！` |

### ■ 実行・配信コマンド
```bash
# 全体占いメニューを適用
node mcp-server/presets/runner.js daily_fortune

# 12星座すべてを一括生成し、登録済みユーザーへ個別アタッチ配信
node mcp-server/presets/batch_fortune_personalized.js
```
