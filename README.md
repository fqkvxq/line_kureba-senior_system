# グーネット特定店舗 × LINE公式アカウント 車両検索システム

グーネット上の店舗在庫（**アップファーレン様: 店舗コード 0601492**）の車両情報を自動取得し、LINE公式アカウントのトーク内検索およびLINE内専用Webミニアプリ（LIFF）で快適に検索・閲覧できるシステムです。

---

## 1. ディレクトリ・ファイル構成

Xserverにアップロードするファイル構成です。

```text
/home/あなたのサーバーID/
│
├── batch/                                # Web非公開の安全なバッチ処理フォルダ
│   ├── scraper.php                       # グーネット自動収集スクリプト (PHP版・推奨)
│   ├── scraper.py                        # グーネット自動収集スクリプト (Python版)
│   ├── cars.db                           # 車両データベース (SQLite・自動生成されます)
│   └── init_cars.sql                     # 初期テーブル定義SQL
│
└── public_html/                          # Web公開フォルダ (ドメイン直下など)
    └── car-system/                       # ※任意のフォルダ名でOK
        ├── config.php                    # LINEアクセストークンや店舗設定
        ├── webhook.php                   # LINE Bot メッセージ受信・返信処理
        ├── api.php                       # LIFF用 検索REST API
        └── liff/                         # LINE内検索ミニアプリ画面
            ├── index.html                # 検索画面HTML
            ├── style.css                 # スタイルシート (モバイル最適化UI)
            └── app.js                    # 検索・絞り込み・LINE問い合わせロジック
```

---

## 2. 導入手順（ステップ・バイ・ステップ）

### ステップ 1：LINE DevelopersでAPIキーを取得する

1. [LINE Developers コンソール](https://developers.line.biz/ja/) にログインします。
2. **「プロバイダー」** を作成（例: `アップファーレン`）。
3. **「新規チャネル作成」** ＞ **「Messaging API」** を選択して作成します。
4. 以下の2つの情報をコピーして控えます：
   - **チャネルシークレット**: 「チャネル基本設定」タブ内にあります。
   - **チャネルアクセストークン（長期）**: 「Messaging API設定」タブ下部の「発行」ボタンで生成します。

---

### ステップ 2：`config.php` を編集する

`public_html/car-system/config.php` を開き、ステップ1で取得したキーを入力します。

```php
// --- LINE公式アカウント設定 ---
define('LINE_CHANNEL_ACCESS_TOKEN', 'ここにチャネルアクセストークンを貼り付け');
define('LINE_CHANNEL_SECRET', 'ここにチャネルシークレットを貼り付け');
define('LINE_LIFF_ID', 'ここにLIFF_IDを貼り付け（ステップ6で取得）');
```

---

### ステップ 3：Xserverへファイルをアップロードする

XserverのファイルマネージャーまたはFTPソフト（FileZilla等）を使い、以下のようにファイルを配置します。

1. `/home/サーバーID/batch/` フォルダを作成し、`batch/` 内のスクリプトをアップロード。
2. `/home/サーバーID/ドメイン名/public_html/car-system/` フォルダを作成し、`public_html/` 配下の一式をアップロード。

---

### ステップ 4：Xserverで定期自動同期（Cron）を設定する

Xserverのサーバーパネル（管理画面）から、1日数回グーネットの最新在庫を自動取得するCronを設定します。

1. Xserverサーバーパネル ＞ **「Cron設定」** ＞ **「Cron追加」** を開きます。
2. 以下のように入力して「確認画面へ進む」＞「追加する」をクリックします。

* **時間設定**: 毎日 朝6時、昼12時、夕方18時に実行する場合
  - 分: `0`
  - 時間: `6,12,18`
  - 日: `*`
  - 月: `*`
  - 曜日: `*`
* **コマンド**:
  ```bash
  /usr/bin/php8.2 /home/あなたのサーバーID/batch/scraper.php > /dev/null 2>&1
  ```
  ※PHPのパスはXserverの標準パス（`/usr/bin/php` または `/usr/bin/php8.2`）です。

> [!TIP]
> 設置直後は、SSHまたはブラウザから一度実行するか、Cronを手動実行することで、即座に `cars.db` に初期データ（全車両）が保存されます。

---

### ステップ 5：LINE DevelopersにWebhook URLを登録する

1. LINE Developers の **「Messaging API設定」** タブを開きます。
2. **「Webhook URL」** に以下を入力します：
   ```text
   https://あなたのドメイン.com/car-system/webhook.php
   ```
3. **「検証」** ボタンを押し、「成功」と表示されたら **「Webhookの利用」** を **ON** にします。
4. [LINE Official Account Manager](https://manager.line.biz/) の「応答設定」で **応答モード: Bot**、**Webhook: オン** に設定します。

---

### ステップ 6：LIFF（LINE内ミニアプリ）を設定する

1. LINE Developers のチャネル画面で **「LIFF」タブ** ＞ **「追加」** をクリック。
2. 設定項目：
   - **LIFFアプリ名**: `在庫車両検索`
   - **サイズ**: `Full`（全画面）
   - **エンドポイントURL**: `https://あなたのドメイン.com/car-system/liff/`
   - **Scopes**: `profile`, `chat_message.write` にチェック
   - **Botリンク機能**: `On (Normal)`
3. 作成後に発行される **LIFF URL**（例: `https://liff.line.me/xxxx-xxxx`）をコピーします。
4. `config.php` の `LINE_LIFF_ID` に設定します。
5. このLIFF URLを、LINE公式アカウントの**リッチメニューのボタン（リンク）**に設定します。

---

## 3. 主な機能と使い方

### ① LINEトーク内での検索
* ユーザーが **「在庫一覧」** と送る ➡ 最新の車両カルーセル（Flex Message）が即時返信。
* ユーザーが **「50万円以下」** や **「ワゴンR」** と送る ➡ 条件に合う車両を絞り込んで返信。
* 各車両カードの「💬 この車を問い合わせ」を押すと、車両情報が入った問い合わせメッセージがトークに自動送信されます。

### ② LIFF検索ミニアプリ画面
* リッチメニューをタップすると、LINEアプリから離れずに専用の検索・閲覧画面が高速起動。
* **予算スライダー**、**走行距離スライダー**、**フリーワード検索**、**安い順/走行距離順ソート**がリアルタイムに動作。
* 気になる車両をタップして写真や詳細スペック（修復歴・車検・年式）を確認し、ワンタップでLINE問い合わせができます。

---

## 4. トラブルシューティング

* **Q. LINEで話しかけても返信がない**
  - `config.php` の `LINE_CHANNEL_ACCESS_TOKEN` が正しく入力されているか確認してください。
  - LINE Official Account Manager で「Webhook」が有効になっているか確認してください。
* **Q. 在庫データが0件と表示される**
  - Xserver上で `batch/scraper.php` が実行され、`batch/cars.db` が作成されているか確認してください。
  - `batch/` フォルダの書き込み権限（パーミッション: `755` または `777`）を確認してください。
