<?php
/**
 * 初期セットアップウィザード & サーバー環境診断ツール (setup.php)
 * レンタルサーバーへの初回導入・移行時に環境要件の検証と初期設定を行います。
 */

// タイムゾーン設定
date_default_timezone_set('Asia/Tokyo');

// ディレクトリ・ファイル定義
$rootDir = dirname(__DIR__);
$publicHtmlDir = __DIR__;
$dataDir = $publicHtmlDir . '/data';
$uploadsDir = $publicHtmlDir . '/uploads';
$richmenuUploadsDir = $uploadsDir . '/richmenu';
$batchDir = $rootDir . '/batch';
$accountsFile = $dataDir . '/line_accounts.json';

// 必要なディレクトリがなければ自動作成
if (!file_exists($dataDir)) @mkdir($dataDir, 0777, true);
if (!file_exists($uploadsDir)) @mkdir($uploadsDir, 0777, true);
if (!file_exists($richmenuUploadsDir)) @mkdir($richmenuUploadsDir, 0777, true);

// Webhook URLの自動算出
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUri = dirname($_SERVER['SCRIPT_NAME']);
$baseUri = ($baseUri === '/' || $baseUri === '\\') ? '' : rtrim($baseUri, '/\\');
$currentBaseUrl = "{$protocol}{$host}{$baseUri}";
$webhookUrl = "{$currentBaseUrl}/webhook.php";
$prolineWebhookUrl = "{$currentBaseUrl}/proline_webhook.php?account=senior";
$adminUrl = "{$currentBaseUrl}/admin/index.html";
$manualUrl = "{$currentBaseUrl}/admin/manual.html";

// AJAX / API リクエスト処理
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_REQUEST['action'];

    // 1. 環境診断API
    if ($action === 'check_env') {
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.0.0', '>=');

        $extPdoSqlite = extension_loaded('pdo_sqlite');
        $extCurl = extension_loaded('curl');
        $extGd = extension_loaded('gd');
        $extMbstring = extension_loaded('mbstring');
        $extJson = extension_loaded('json');

        $permData = is_writable($dataDir);
        $permUploads = is_writable($uploadsDir);
        $permRichmenu = is_writable($richmenuUploadsDir);
        $permBatch = file_exists($batchDir) ? is_writable($batchDir) : is_writable($rootDir);

        $allOk = $phpOk && $extPdoSqlite && $extCurl && $extGd && $extMbstring && $extJson && $permData && $permUploads;

        echo json_encode([
            'success' => true,
            'all_ok' => $allOk,
            'checks' => [
                'php_version' => ['ok' => $phpOk, 'value' => $phpVersion, 'label' => 'PHP 8.0 以上'],
                'pdo_sqlite' => ['ok' => $extPdoSqlite, 'label' => 'PDO SQLite 拡張 (データベース)'],
                'curl' => ['ok' => $extCurl, 'label' => 'cURL 拡張 (LINE通信)'],
                'gd' => ['ok' => $extGd, 'label' => 'GD 拡張 (画像合成)'],
                'mbstring' => ['ok' => $extMbstring, 'label' => 'mbstring 拡張 (日本語処理)'],
                'json' => ['ok' => $extJson, 'label' => 'JSON 拡張'],
                'perm_data' => ['ok' => $permData, 'path' => 'public_html/data', 'label' => 'データ保存権限 (data/)'],
                'perm_uploads' => ['ok' => $permUploads && $permRichmenu, 'path' => 'public_html/uploads', 'label' => '画像保存権限 (uploads/)'],
                'perm_batch' => ['ok' => $permBatch, 'path' => 'batch', 'label' => 'バッチ/DB保存権限 (batch/)'],
            ],
            'urls' => [
                'webhook' => $webhookUrl,
                'proline_webhook' => $prolineWebhookUrl,
                'admin' => $adminUrl,
                'manual' => $manualUrl
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. LINE Messaging API 疎通テスト
    if ($action === 'test_line_api') {
        $token = trim($_POST['token'] ?? '');
        if (empty($token)) {
            echo json_encode(['success' => false, 'error' => 'チャネルアクセストークンを入力してください']);
            exit;
        }

        $ch = curl_init('https://api.line.me/v2/bot/info');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            echo json_encode(['success' => false, 'error' => '通信エラーが発生しました: ' . $curlErr]);
            exit;
        }

        $data = json_decode($res, true);
        if ($httpCode === 200 && is_array($data)) {
            echo json_encode([
                'success' => true,
                'bot_name' => $data['displayName'] ?? 'LINE公式アカウント',
                'basic_id' => $data['basicId'] ?? '',
                'picture_url' => $data['pictureUrl'] ?? '',
                'chat_mode' => $data['chatMode'] ?? '',
                'message' => 'LINE APIとの通信に成功しました！'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $msg = $data['message'] ?? "HTTP {$httpCode}: LINEアクセストークンが無効です。";
            echo json_encode(['success' => false, 'error' => $msg]);
        }
        exit;
    }

    // 3. 設定保存 & DB初期化
    if ($action === 'save_setup') {
        $shopName = trim($_POST['shop_name'] ?? 'LINE公式アカウント');
        $token = trim($_POST['channel_access_token'] ?? '');
        $secret = trim($_POST['channel_secret'] ?? '');
        $liffId = trim($_POST['liff_id'] ?? '');
        $prolineCalendar = trim($_POST['proline_calendar_url'] ?? '');
        $prolineWebhook = trim($_POST['proline_webhook_url'] ?? '');
        $adminPassword = trim($_POST['admin_password'] ?? '');

        if (empty($token) || empty($secret)) {
            echo json_encode(['success' => false, 'error' => 'チャネルアクセストークンとチャネルシークレットは必須です。']);
            exit;
        }

        // line_accounts.json の保存
        $accountConfig = [
            'accounts' => [
                'senior' => [
                    'id' => 'senior',
                    'name' => $shopName,
                    'short_name' => $shopName,
                    'theme_color' => '#2563eb',
                    'channel_access_token' => $token,
                    'channel_secret' => $secret,
                    'liff_id' => $liffId,
                    'proline_calendar_url' => $prolineCalendar,
                    'proline_webhook_url' => $prolineWebhook,
                    'db_file' => 'kureba-senior-system.db',
                    'is_default' => true
                ]
            ]
        ];

        $saved = @file_put_contents($accountsFile, json_encode($accountConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($saved === false) {
            echo json_encode(['success' => false, 'error' => '設定ファイル (data/line_accounts.json) の保存に失敗しました。パーミッションを確認してください。']);
            exit;
        }

        // DB初期化の実行
        try {
            if (file_exists(__DIR__ . '/config.php')) {
                require_once __DIR__ . '/config.php';
                if (function_exists('getDbConnection')) {
                    $pdo = getDbConnection('senior');
                    // 管理者パスワードが指定されている場合は system_settings に保存
                    if (!empty($adminPassword) && $pdo) {
                        $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES ('admin_password', ?, datetime('now', '+9 hours'))");
                        $stmt->execute([$adminPassword]);
                    }
                }
            }
        } catch (Exception $e) {
            // DBエラー時もアカウント設定は保存済みなので警告を含めて返す
        }

        echo json_encode([
            'success' => true,
            'message' => '初期セットアップが完了しました！',
            'urls' => [
                'webhook' => $webhookUrl,
                'proline_webhook' => $prolineWebhookUrl,
                'admin' => $adminUrl,
                'manual' => $manualUrl
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => false, 'error' => '不明なアクションです']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>初期セットアップウィザード & 環境診断 - LINE管理システム</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;700;800&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --border: #e2e8f0;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --radius-md: 10px;
            --radius-lg: 16px;
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.08);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Noto Sans JP', sans-serif;
            background-color: var(--bg-page);
            color: var(--text-main);
            line-height: 1.6;
            padding: 40px 20px;
        }
        .container {
            max-width: 820px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #2563eb, #4f46e5);
            color: white;
            font-size: 26px;
            border-radius: 14px;
            margin-bottom: 12px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        .header h1 {
            font-size: 24px;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 6px;
        }
        .header p {
            color: var(--text-muted);
            font-size: 14px;
        }
        .card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 28px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            margin-bottom: 24px;
        }
        .card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }
        .card-title i { color: var(--primary); }
        .env-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        .env-table th, .env-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            text-align: left;
        }
        .env-table th { color: var(--text-muted); font-weight: 600; width: 45%; }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 9999px;
        }
        .status-pass { background: #dcfce7; color: #15803d; }
        .status-fail { background: #fee2e2; color: #b91c1c; }
        .status-warn { background: #fef3c7; color: #b45309; }

        .form-group {
            margin-bottom: 18px;
        }
        .form-label {
            display: block;
            font-size: 13.5px;
            font-weight: 700;
            margin-bottom: 6px;
            color: #334155;
        }
        .form-label .req {
            color: var(--danger);
            font-size: 11px;
            margin-left: 4px;
        }
        .form-input {
            width: 100%;
            padding: 10px 14px;
            font-size: 14px;
            border: 1px solid #cbd5e1;
            border-radius: var(--radius-md);
            outline: none;
            transition: border-color 0.2s;
            font-family: inherit;
        }
        .form-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
            line-height: 1.5;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 700;
            border-radius: var(--radius-md);
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #059669; }
        .btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .btn-secondary:hover { background: #e2e8f0; }

        .alert-box {
            padding: 14px 18px;
            border-radius: var(--radius-md);
            font-size: 13.5px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
        .alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        .copy-group {
            display: flex;
            gap: 6px;
            margin-top: 6px;
        }
        .copy-input {
            flex: 1;
            padding: 8px 12px;
            font-size: 12.5px;
            font-family: monospace;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: var(--radius-md);
            color: #334155;
        }
        .btn-copy {
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 700;
            background: #334155;
            color: white;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-copy:hover { background: #1e293b; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="header-logo"><i class="fa-solid fa-gear"></i></div>
        <h1>LINE管理システム 初期セットアップ</h1>
        <p>サーバー環境要件の診断およびLINE公式アカウントの初期設定を行います</p>
    </div>

    <!-- 1. サーバー環境診断 -->
    <div class="card">
        <div class="card-title">
            <i class="fa-solid fa-server"></i>
            <span>1. サーバー環境診断</span>
        </div>
        <div id="envCheckLoading" style="text-align: center; padding: 20px; color: var(--text-muted);">
            <i class="fa-solid fa-spinner fa-spin fa-2x"></i>
            <p style="margin-top: 8px; font-size: 13px;">サーバー環境をチェック中...</p>
        </div>
        <div id="envCheckResult" style="display: none;">
            <table class="env-table" id="envTable"></table>
            <div id="envSummaryAlert" style="margin-top: 16px;"></div>
        </div>
    </div>

    <!-- 2. LINE公式アカウント & システム初期設定 -->
    <div class="card">
        <div class="card-title">
            <i class="fa-brands fa-line" style="color: #06C755;"></i>
            <span>2. LINE公式アカウント & 管理者設定</span>
        </div>

        <form id="setupForm">
            <div class="form-group">
                <label class="form-label" for="shopName">店舗・企業・教室名 <span class="req">必須</span></label>
                <input type="text" id="shopName" name="shop_name" class="form-input" placeholder="例: パソコン教室〇〇 / 株式会社〇〇" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="channelAccessToken">LINE Messaging API チャネルアクセストークン (長期) <span class="req">必須</span></label>
                <textarea id="channelAccessToken" name="channel_access_token" class="form-input" rows="3" placeholder="LINE DevelopersのMessaging API設定で発行した長期アクセストークンを貼り付け" required style="font-family: monospace; font-size: 12px;"></textarea>
                <div style="margin-top: 6px;">
                    <button type="button" class="btn btn-secondary" id="btnTestLine" style="padding: 6px 14px; font-size: 12px;">
                        <i class="fa-solid fa-vial"></i> LINE API 疎通テスト
                    </button>
                    <span id="lineTestResult" style="margin-left: 10px; font-size: 12.5px;"></span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="channelSecret">LINE チャネルシークレット <span class="req">必須</span></label>
                <input type="text" id="channelSecret" name="channel_secret" class="form-input" placeholder="例: 32桁の英数字" required style="font-family: monospace;">
                <div class="form-hint">LINE Developersの「チャネル基本設定」にあるチャネルシークレットを入力します。</div>
            </div>

            <div class="form-group">
                <label class="form-label" for="liffId">LIFF ID <span style="font-size: 11px; color: var(--text-muted);">(任意・マイカルテ用)</span></label>
                <input type="text" id="liffId" name="liff_id" class="form-input" placeholder="例: 2000276344-YL1wXh0h" style="font-family: monospace;">
                <div class="form-hint">受講生・顧客向けマイカルテ画面（liff.html）を利用する場合はLIFF IDを入力します。</div>
            </div>

            <!-- 外部ツール並列中継設定 -->
            <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: var(--radius-md); padding: 14px; margin-bottom: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label class="form-label" for="prolineWebhookUrl" style="margin-bottom: 0;">
                        <i class="fa-solid fa-network-wired" style="color: #2563eb;"></i> 外部ツール転送先 Webhook URL（プロライン / エルメ / LINE Harness等・任意）
                    </label>
                    <div style="display: flex; gap: 4px;">
                        <button type="button" class="btn btn-secondary" onclick="appendSetupWebhook('proline')" style="padding: 2px 6px; font-size: 10.5px; height: auto;">＋ プロライン</button>
                        <button type="button" class="btn btn-secondary" onclick="appendSetupWebhook('lmessh')" style="padding: 2px 6px; font-size: 10.5px; height: auto;">＋ エルメ</button>
                        <button type="button" class="btn btn-secondary" onclick="appendSetupWebhook('harness')" style="padding: 2px 6px; font-size: 10.5px; height: auto;">＋ LINE Harness</button>
                    </div>
                </div>
                <textarea id="prolineWebhookUrl" name="proline_webhook_url" class="form-input" rows="2" placeholder="例:&#10;https://autosns.pro/api/webhook/... (プロライン)&#10;https://l-messh.com/api/webhook/... (エルメ)&#10;https://line-harness.com/api/webhook/... (LINE Harness)" style="font-family: monospace; font-size: 11.5px;"></textarea>
                <div class="form-hint">LINEから届いたWebhookを各外部ツールへ並列中継します。各ツールの自動返信・ステップ配信・シナリオと干渉せずに完全共存できます（1行に1件・複数登録可）。</div>
            </div>

            <div class="form-group">
                <label class="form-label" for="adminPassword">システム管理者パスワード <span class="req">必須</span></label>
                <input type="password" id="adminPassword" name="admin_password" class="form-input" placeholder="管理画面ログイン用の英数字パスワード" required>
                <div class="form-hint">管理画面（admin/index.html）および操作マニュアルへのログインパスワードを設定します。</div>
            </div>

            <div style="margin-top: 24px; text-align: center;">
                <button type="submit" class="btn btn-primary" id="btnSaveSetup" style="padding: 14px 36px; font-size: 16px; width: 100%;">
                    <i class="fa-solid fa-floppy-disk"></i> 設定を保存してシステムを初期化する
                </button>
            </div>
        </form>
    </div>

    <!-- 3. Webhook設定・完了ガイド -->
    <div class="card" id="completeCard" style="display: none;">
        <div class="card-title">
            <i class="fa-solid fa-circle-check" style="color: var(--success);"></i>
            <span>3. セットアップ完了 & Webhook登録</span>
        </div>
        
        <div class="alert-box alert-success">
            <i class="fa-solid fa-circle-check fa-lg"></i>
            <div>
                <strong>初期セットアップが正常に完了しました！</strong><br>
                データベースおよびテーブルが自動生成され、いつでも利用を開始できます。
            </div>
        </div>

        <h4 style="font-size: 14px; margin-bottom: 8px; color: #334155;">【重要】LINE Developers に登録する Webhook URL:</h4>
        <div class="copy-group">
            <input type="text" class="copy-input" id="copyWebhookInput" readonly value="<?= htmlspecialchars($webhookUrl) ?>">
            <button type="button" class="btn-copy" onclick="copyText('copyWebhookInput')"><i class="fa-solid fa-copy"></i> コピー</button>
        </div>
        <p class="form-hint" style="margin-top: 6px;">
            LINE Developers の「Messaging API設定」→「Webhook URL」に貼り付け、「Webhookの利用」を <strong>ON</strong> にしてください。
        </p>

        <h4 style="font-size: 14px; margin-top: 18px; margin-bottom: 8px; color: #334155;">プロライン連携用 Webhook URL（ご利用の場合）:</h4>
        <div class="copy-group">
            <input type="text" class="copy-input" id="copyProlineInput" readonly value="<?= htmlspecialchars($prolineWebhookUrl) ?>">
            <button type="button" class="btn-copy" onclick="copyText('copyProlineInput')"><i class="fa-solid fa-copy"></i> コピー</button>
        </div>

        <div style="display: flex; gap: 12px; margin-top: 24px;">
            <a href="<?= htmlspecialchars($adminUrl) ?>" class="btn btn-success" style="flex: 1;">
                <i class="fa-solid fa-gauge"></i> 管理画面を開く
            </a>
            <a href="<?= htmlspecialchars($manualUrl) ?>" class="btn btn-secondary" style="flex: 1;">
                <i class="fa-solid fa-book-open"></i> 操作マニュアルを見る
            </a>
        </div>
    </div>
</div>

<script>
// 1. 環境診断の実行
async function runEnvCheck() {
    try {
        const res = await fetch('setup.php?action=check_env');
        const data = await res.json();
        document.getElementById('envCheckLoading').style.display = 'none';
        document.getElementById('envCheckResult').style.display = 'block';

        if (data.success && data.checks) {
            let html = '';
            for (const [key, check] of Object.entries(data.checks)) {
                const badge = check.ok 
                    ? `<span class="status-badge status-pass"><i class="fa-solid fa-circle-check"></i> 正常 (OK)</span>`
                    : `<span class="status-badge status-fail"><i class="fa-solid fa-triangle-exclamation"></i> 要確認</span>`;
                const valText = check.value ? ` (${check.value})` : '';
                html += `<tr><th>${check.label}${valText}</th><td>${badge}</td></tr>`;
            }
            document.getElementById('envTable').innerHTML = html;

            const alertEl = document.getElementById('envSummaryAlert');
            if (data.all_ok) {
                alertEl.className = 'alert-box alert-success';
                alertEl.innerHTML = `<i class="fa-solid fa-circle-check"></i><div><strong>サーバー環境はすべて要件を満たしています。</strong> 次のステップへ進んでください。</div>`;
            } else {
                alertEl.className = 'alert-box alert-danger';
                alertEl.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i><div><strong>一部の環境要件が不足しています。</strong> レンタルサーバーの設定（PHPバージョンやディレクトリ権限 755/777）をご確認ください。</div>`;
            }
        }
    } catch (e) {
        document.getElementById('envCheckLoading').innerHTML = `<p style="color:red;">環境診断の取得に失敗しました: ${e.message}</p>`;
    }
}

// 2. LINE API テスト
document.getElementById('btnTestLine').addEventListener('click', async () => {
    const token = document.getElementById('channelAccessToken').value.trim();
    const resultEl = document.getElementById('lineTestResult');
    if (!token) {
        resultEl.innerHTML = '<span style="color:red;">トークンを入力してください</span>';
        return;
    }
    resultEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> テスト中...';
    try {
        const fd = new FormData();
        fd.append('action', 'test_line_api');
        fd.append('token', token);
        const res = await fetch('setup.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            resultEl.innerHTML = `<span style="color: #15803d; font-weight: bold;"><i class="fa-solid fa-check"></i> 接続成功: ${data.bot_name} (${data.basic_id})</span>`;
        } else {
            resultEl.innerHTML = `<span style="color: #dc2626; font-weight: bold;"><i class="fa-solid fa-xmark"></i> 失敗: ${data.error}</span>`;
        }
    } catch (e) {
        resultEl.innerHTML = `<span style="color: #dc2626;">エラー: ${e.message}</span>`;
    }
});

// 3. 設定保存
document.getElementById('setupForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnSaveSetup');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 初期化中...';

    try {
        const fd = new FormData(document.getElementById('setupForm'));
        fd.append('action', 'save_setup');
        const res = await fetch('setup.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            document.getElementById('completeCard').style.display = 'block';
            document.getElementById('completeCard').scrollIntoView({ behavior: 'smooth' });
        } else {
            alert('エラー: ' + data.error);
        }
    } catch (e) {
        alert('送信エラー: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
});

// コピー補助
function copyText(elemId) {
    const el = document.getElementById(elemId);
    if (!el) return;
    el.select();
    navigator.clipboard.writeText(el.value).then(() => {
        alert('クリップボードにコピーしました！');
    }).catch(() => {
        document.execCommand('copy');
        alert('コピーしました！');
    });
}

function appendSetupWebhook(toolType) {
    const input = document.getElementById('prolineWebhookUrl');
    if (!input) return;
    let sample = '';
    if (toolType === 'proline') sample = 'https://autosns.pro/api/webhook/YOUR_KEY';
    else if (toolType === 'lmessh') sample = 'https://l-messh.com/api/webhook/YOUR_KEY';
    else if (toolType === 'harness') sample = 'https://line-harness.com/api/webhook/YOUR_KEY';
    
    if (sample) {
        const cur = input.value.trim();
        input.value = cur ? (cur + "\n" + sample) : sample;
        input.focus();
    }
}

// 起動
runEnvCheck();
</script>
</body>
</html>
