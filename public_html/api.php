<?php
/**
 * REST API エンドポイント (JSON返却)
 * 車両一覧、絞り込み、メタ情報取得、顧客メンテナンス管理をサポート
 */

// タイムゾーンとエラー設定 (日本時間 / JST)
date_default_timezone_set('Asia/Tokyo');
ini_set('date.timezone', 'Asia/Tokyo');
putenv('TZ=Asia/Tokyo');
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Password, X-Line-Account');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 共通設定・DB接続
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/zodiac_api.php';

/**
 * 管理者認証パスワード / 2FA認証トークンを取得・検証
 */
function getAdminAuthPassword(?PDO $db = null): string {
    // 0. 2FA認証トークンの検証
    $token = $_SERVER['HTTP_X_ADMIN_AUTH_TOKEN']
        ?? ($_COOKIE['admin_auth_token']
        ?? ($_POST['auth_token']
        ?? ($_GET['auth_token'] ?? '')));

    if (!$token && function_exists('getallheaders')) {
        $headers = @getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, 'X-Admin-Auth-Token') === 0) {
                    $token = $v;
                    break;
                }
            }
        }
    }

    if (!empty($token)) {
        if ($db === null) {
            try { $db = getDbConnection(); } catch (Throwable $e) {}
        }
        if ($db) {
            try {
                if (isValidAdminAuthToken($db, trim($token))) {
                    return ADMIN_PASSWORD; // 認証トークンが有効な場合、ADMIN_PASSWORDと一致したとみなす
                }
            } catch (Throwable $e) {}
        }
    }

    // 1. 環境変数 (FastCGI等による各種プレフィックス対応)
    $pass = $_SERVER['HTTP_X_ADMIN_PASSWORD'] 
        ?? ($_SERVER['REDIRECT_HTTP_X_ADMIN_PASSWORD'] 
        ?? ($_SERVER['REDIRECT_REDIRECT_HTTP_X_ADMIN_PASSWORD'] ?? ''));

    // 2. apache_request_headers / getallheaders
    if (!$pass) {
        $headerFuncs = ['getallheaders', 'apache_request_headers'];
        foreach ($headerFuncs as $fn) {
            if (function_exists($fn)) {
                $headers = @$fn();
                if (is_array($headers)) {
                    foreach ($headers as $key => $val) {
                        if (strcasecmp($key, 'X-Admin-Password') === 0) {
                            $pass = $val;
                            break 2;
                        }
                    }
                }
            }
        }
    }

    // 3. Authorization: Bearer <pass_or_token>
    if (!$pass) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] 
            ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
            ?? ($_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (!$authHeader && function_exists('getallheaders')) {
            $headers = @getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $k => $v) {
                    if (strcasecmp($k, 'Authorization') === 0) {
                        $authHeader = $v;
                        break;
                    }
                }
            }
        }
        if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $bearerVal = $matches[1];
            if ($db === null) {
                try { $db = getDbConnection(); } catch (Exception $e) {}
            }
            if ($db && isValidAdminAuthToken($db, $bearerVal)) {
                return ADMIN_PASSWORD;
            }
            $pass = $bearerVal;
        }
    }

    // 4. $_SERVERの全キー走査 (FastCGIで任意プレフィックスが付くケース)
    if (!$pass) {
        foreach ($_SERVER as $k => $v) {
            if (is_string($v) && preg_match('/(?:HTTP_)?(?:REDIRECT_)*X_ADMIN_PASSWORD$/i', $k)) {
                $pass = $v;
                break;
            }
        }
    }

    // 5. POST / GET / REQUEST パラメータ (フォールバック)
    if (!$pass) {
        $pass = $_POST['password'] ?? ($_GET['password'] ?? ($_REQUEST['password'] ?? ''));
    }

    // 6. Cookie (セッション維持フォールバック)
    if (!$pass) {
        $pass = $_COOKIE['admin_pass'] ?? '';
    }

    $pass = trim((string)$pass);

    // 有効なパスワードであればCookieをセットして次回以降の通信を安定化
    if ($pass === ADMIN_PASSWORD && empty($_COOKIE['admin_pass'])) {
        @setcookie('admin_pass', $pass, [
            'expires' => time() + 86400 * 30,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax'
        ]);
    }

    return $pass;
}

try {
    // リクエストのアカウント指定を反映
    $requestedAccount = $_REQUEST['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? null);
    if (!empty($requestedAccount)) {
        setActiveAccountKey($requestedAccount);
    }
    $activeAccountKey = getActiveAccountKey();
    $db = getDbConnection($activeAccountKey);
    $action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

    switch ($action) {
        case 'git_pull':
        case 'deploy_sync':
            $repoRoot = realpath(__DIR__ . '/..');
            $output = [];
            $code = 0;
            if ($repoRoot && is_dir($repoRoot . '/.git')) {
                exec("cd " . escapeshellarg($repoRoot) . " && git pull origin main 2>&1", $output, $code);
            } else {
                $output[] = "Git repository not found at " . ($repoRoot ?: 'null');
                $code = 1;
            }
            echo json_encode([
                'success' => ($code === 0),
                'code' => $code,
                'output' => implode("\n", $output)
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'save_user_zodiac':
        case 'get_user_zodiac':
        case 'list_user_zodiacs':
            $result = handleZodiacAction($action, $db, $req);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;

        // --- リッチメニュー・クイックリプライ利用分析・統計 ---
        case 'get_menu_analytics':
            $days = (int)($_GET['days'] ?? 30);
            if ($days < 1) $days = 30;
            $since = date('Y-m-d 00:00:00', strtotime("-{$days} days"));

            try {
                $db->exec("CREATE TABLE IF NOT EXISTS menu_action_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id TEXT NOT NULL,
                    user_name TEXT DEFAULT '',
                    action_type TEXT NOT NULL,
                    detail_label TEXT NOT NULL,
                    account_id TEXT NOT NULL,
                    created_at DATETIME NOT NULL
                )");

                // 1. 総操作数 & ユニークユーザー数
                $totalStmt = $db->prepare("SELECT COUNT(*) as total_actions, COUNT(DISTINCT user_id) as unique_users FROM menu_action_logs WHERE created_at >= :since");
                $totalStmt->execute([':since' => $since]);
                $summary = $totalStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_actions' => 0, 'unique_users' => 0];

                // 本日の操作数
                $todaySince = date('Y-m-d 00:00:00');
                $todayStmt = $db->prepare("SELECT COUNT(*) as cnt FROM menu_action_logs WHERE created_at >= :ts");
                $todayStmt->execute([':ts' => $todaySince]);
                $todayCount = (int)($todayStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

                // 2. 機能・地域・星座別集計ランキング
                $typeStmt = $db->prepare("SELECT detail_label, action_type, COUNT(*) as cnt FROM menu_action_logs WHERE created_at >= :since GROUP BY detail_label, action_type ORDER BY cnt DESC");
                $typeStmt->execute([':since' => $since]);
                $ranking = $typeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                // 3. 日別推移
                $dailyStmt = $db->prepare("SELECT DATE(created_at) as log_date, COUNT(*) as cnt FROM menu_action_logs WHERE created_at >= :since GROUP BY DATE(created_at) ORDER BY log_date ASC");
                $dailyStmt->execute([':since' => $since]);
                $dailyTrend = $dailyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                // 4. 最新利用ログ (直近50件)
                $logsStmt = $db->prepare("SELECT l.*, c.picture_url, c.user_name as c_name FROM menu_action_logs l LEFT JOIN customer_cars c ON TRIM(l.user_id) = TRIM(c.user_id) ORDER BY l.id DESC LIMIT 50");
                $logsStmt->execute();
                $recentLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                echo json_encode([
                    'success' => true,
                    'summary' => [
                        'total_actions' => (int)$summary['total_actions'],
                        'unique_users' => (int)$summary['unique_users'],
                        'today_actions' => $todayCount,
                        'period_days' => $days
                    ],
                    'ranking' => $ranking,
                    'daily_trend' => $dailyTrend,
                    'recent_logs' => $recentLogs
                ], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

        // --- 0. アカウント一覧取得 & 現在のアクティブアカウント情報 (マルチアカウント対応) ---
        case 'get_accounts':
            $accConfig = getAccountConfig($activeAccountKey);
            $hasToken = !empty($accConfig['channel_access_token']) && $accConfig['channel_access_token'] !== 'YOUR_CHANNEL_ACCESS_TOKEN_HERE';
            $hasSecret = !empty($accConfig['channel_secret']) && $accConfig['channel_secret'] !== 'YOUR_CHANNEL_SECRET_HERE';
            echo json_encode([
                'success' => true,
                'accounts' => getAccountList(),
                'active_account' => $activeAccountKey,
                'active_account_info' => [
                    'id' => $accConfig['id'],
                    'name' => $accConfig['name'],
                    'short_name' => $accConfig['short_name'] ?? $accConfig['name'],
                    'theme_color' => $accConfig['theme_color'] ?? '#ff8700',
                    'liff_id' => $accConfig['liff_id'] ?? '',
                    'is_configured' => ($hasToken && $hasSecret),
                    'custom_labels' => getAccountCustomLabels($activeAccountKey)
                ],
                'industry_presets' => $GLOBALS['INDUSTRY_PRESETS'] ?? [],
                'accounts' => getAccountList()
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-1. アカウント切り替え ---
        case 'switch_account':
            $targetAcc = $_POST['account'] ?? ($_GET['account'] ?? '');
            if (empty($targetAcc)) {
                echo json_encode(['success' => false, 'error' => 'アカウントIDが未指定です']);
                exit;
            }
            setActiveAccountKey($targetAcc);
            $newKey = getActiveAccountKey();
            @setcookie('active_line_account', $newKey, time() + 86400 * 30, '/');
            $accConfig = getAccountConfig($newKey);
            $hasToken = !empty($accConfig['channel_access_token']) && $accConfig['channel_access_token'] !== 'YOUR_CHANNEL_ACCESS_TOKEN_HERE';
            $hasSecret = !empty($accConfig['channel_secret']) && $accConfig['channel_secret'] !== 'YOUR_CHANNEL_SECRET_HERE';
            echo json_encode([
                'success' => true,
                'active_account' => $newKey,
                'active_account_info' => [
                    'id' => $accConfig['id'],
                    'name' => $accConfig['name'],
                    'short_name' => $accConfig['short_name'] ?? $accConfig['name'],
                    'theme_color' => $accConfig['theme_color'] ?? '#ff8700',
                    'liff_id' => $accConfig['liff_id'] ?? '',
                    'is_configured' => ($hasToken && $hasSecret),
                    'custom_labels' => getAccountCustomLabels($newKey)
                ],
                'industry_presets' => $GLOBALS['INDUSTRY_PRESETS'] ?? [],
                'accounts' => getAccountList()
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-0. システムオンライン更新確認 & 実行 (管理者認証必須) ---
        case 'check_system_update':
            $authPass = getAdminAuthPassword($db);
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }
            $updateInfo = checkSystemRemoteUpdate();
            echo json_encode($updateInfo, JSON_UNESCAPED_UNICODE);
            exit;

        case 'execute_system_update':
            $authPass = getAdminAuthPassword($db);
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }
            // タイムアウトを延長
            @set_time_limit(300);
            $updateResult = performSystemSelfUpdate();
            echo json_encode($updateResult, JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-0B. WebPush ブラウザプッシュ通知管理 ---
        case 'get_vapid_public_key':
            $keys = getOrCreateVapidKeys();
            echo json_encode([
                'success' => !empty($keys['publicKey']),
                'publicKey' => $keys['publicKey'] ?? '',
                'error' => empty($keys['publicKey']) ? 'VAPIDキーの生成に失敗しました' : null
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'save_push_subscription':
            $rawJson = file_get_contents('php://input');
            $subData = json_decode($rawJson, true) ?: $_POST;
            $endpoint = trim($subData['endpoint'] ?? '');
            $p256dh = trim($subData['keys']['p256dh'] ?? ($subData['p256dh'] ?? ''));
            $auth = trim($subData['keys']['auth'] ?? ($subData['auth'] ?? ''));
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

            if (empty($endpoint) || empty($p256dh) || empty($auth)) {
                echo json_encode(['success' => false, 'error' => '無効なPushSubscriptionデータです']);
                exit;
            }

            try {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS push_subscriptions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        account TEXT NOT NULL DEFAULT 'senior',
                        endpoint TEXT NOT NULL UNIQUE,
                        p256dh TEXT NOT NULL,
                        auth TEXT NOT NULL,
                        user_agent TEXT,
                        created_at DATETIME NOT NULL,
                        last_used_at DATETIME
                    );
                    CREATE INDEX IF NOT EXISTS idx_push_acc ON push_subscriptions (account);
                ");

                $stmt = $db->prepare("
                    INSERT INTO push_subscriptions (account, endpoint, p256dh, auth, user_agent, created_at, last_used_at)
                    VALUES (:acc, :endpoint, :p256dh, :auth, :ua, :now, :now)
                    ON CONFLICT(endpoint) DO UPDATE SET
                        account = :acc,
                        p256dh = :p256dh,
                        auth = :auth,
                        user_agent = :ua,
                        last_used_at = :now
                ");
                $nowStr = date('Y-m-d H:i:s');
                $stmt->execute([
                    ':acc' => $activeAccountKey,
                    ':endpoint' => $endpoint,
                    ':p256dh' => $p256dh,
                    ':auth' => $auth,
                    ':ua' => $userAgent,
                    ':now' => $nowStr
                ]);

                echo json_encode(['success' => true, 'message' => 'ブラウザプッシュ通知の購読を登録しました']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

        case 'delete_push_subscription':
            $rawJson = file_get_contents('php://input');
            $subData = json_decode($rawJson, true) ?: $_POST;
            $endpoint = trim($subData['endpoint'] ?? '');

            if (!empty($endpoint)) {
                try {
                    $stmt = $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = :endpoint");
                    $stmt->execute([':endpoint' => $endpoint]);
                } catch (Exception $e) {}
            }
            echo json_encode(['success' => true, 'message' => 'プッシュ購読を解除しました']);
            exit;

        case 'test_web_push':
            $authPass = getAdminAuthPassword($db);
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $testPayload = [
                'title' => '🔔 【テスト通知】ブラウザプッシュ連携',
                'body' => 'WebPush通知は正常に稼働しています！受講生からメッセージが届くと即座にここにお知らせします。',
                'icon' => 'https://scdn.line-apps.com/n/channel_devcenter/img/fx/linecorp_code_withborder.png',
                'data' => [
                    'url' => 'admin/index.html',
                    'user_id' => '',
                    'account' => $activeAccountKey
                ]
            ];

            $pushResult = sendWebPushNotification($testPayload, $db, $activeAccountKey);
            echo json_encode([
                'success' => ($pushResult['sent'] > 0),
                'result' => $pushResult,
                'message' => ($pushResult['sent'] > 0)
                    ? "{$pushResult['sent']}台のブラウザ端末へテストプッシュ通知を送信しました！"
                    : "送信対象の登録端末がありません。先にブラウザ側でプッシュ通知を「有効化」してください。"
            ], JSON_UNESCAPED_UNICODE);
            exit;


        // --- 0-2. アカウント詳細情報取得 (編集用・管理者認証必須) ---
        case 'get_account_detail':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $targetAcc = $_GET['target_account'] ?? ($_POST['target_account'] ?? getActiveAccountKey());
            $accConfig = getAccountConfig($targetAcc);
            if (empty($accConfig) || empty($accConfig['id'])) {
                echo json_encode(['success' => false, 'error' => '指定されたアカウントが見つかりません']);
                exit;
            }

            $webhookUrl = getBaseUrl() . "/webhook.php" . (!empty($accConfig['is_default']) ? '' : "?account={$accConfig['id']}");
            $prolineSettings = getProlineSettings(null, $targetAcc);

            echo json_encode([
                'success' => true,
                'account' => [
                    'id' => $accConfig['id'],
                    'name' => $accConfig['name'],
                    'short_name' => $accConfig['short_name'] ?? $accConfig['name'],
                    'theme_color' => $accConfig['theme_color'] ?? '#6366f1',
                    'industry_type' => $accConfig['industry_type'] ?? 'senior',
                    'label_item1' => $accConfig['label_item1'] ?? '',
                    'label_item2' => $accConfig['label_item2'] ?? '',
                    'label_date1' => $accConfig['label_date1'] ?? '',
                    'label_date2' => $accConfig['label_date2'] ?? '',
                    'label_date3' => $accConfig['label_date3'] ?? '',
                    'custom_labels' => getAccountCustomLabels($targetAcc),
                    'channel_access_token' => $accConfig['channel_access_token'] ?? '',
                    'channel_secret' => $accConfig['channel_secret'] ?? '',
                    'liff_id' => $accConfig['liff_id'] ?? '',
                    'proline_calendar_url' => $prolineSettings['calendar_url'] ?? ($accConfig['proline_calendar_url'] ?? ''),
                    'proline_webhook_url' => $prolineSettings['webhook_url'] ?? ($accConfig['proline_webhook_url'] ?? ''),
                    'db_file' => $accConfig['db_file'] ?? "cars_{$accConfig['id']}.db",
                    'is_default' => !empty($accConfig['is_default']),
                    'webhook_url' => $webhookUrl
                ],
                'industry_presets' => $GLOBALS['INDUSTRY_PRESETS'] ?? []
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-3. アカウント追加・更新保存 (管理者認証必須) ---
        case 'save_account':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $rawId = strtolower(trim($_POST['id'] ?? ''));
            $cleanId = preg_replace('/[^a-z0-9_\-]/', '', $rawId);
            if (empty($cleanId) || strlen($cleanId) < 2 || strlen($cleanId) > 32) {
                echo json_encode(['success' => false, 'error' => 'アカウントIDは半角英小文字・数字・アンダースコア・ハイフン（2〜32文字）で入力してください']);
                exit;
            }

            $name = trim($_POST['name'] ?? '');
            if (empty($name)) {
                echo json_encode(['success' => false, 'error' => 'アカウント表示名を入力してください']);
                exit;
            }

            $shortName = trim($_POST['short_name'] ?? '') ?: $name;
            $themeColor = trim($_POST['theme_color'] ?? '#6366f1');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $themeColor)) {
                $themeColor = '#6366f1';
            }

            $industryType = trim($_POST['industry_type'] ?? 'senior');
            $labelItem1 = trim($_POST['label_item1'] ?? '');
            $labelItem2 = trim($_POST['label_item2'] ?? '');
            $labelDate1 = trim($_POST['label_date1'] ?? '');
            $labelDate2 = trim($_POST['label_date2'] ?? '');
            $labelDate3 = trim($_POST['label_date3'] ?? '');

            $accessToken = trim($_POST['channel_access_token'] ?? '');
            $channelSecret = trim($_POST['channel_secret'] ?? '');
            $liffId = trim($_POST['liff_id'] ?? '');
            $prolineCalUrl = trim($_POST['proline_calendar_url'] ?? '');
            $prolineWebhookUrl = trim($_POST['proline_webhook_url'] ?? '');
            $isDefault = !empty($_POST['is_default']) ? true : false;

            // アカウント設定リスト取得
            $allAccounts = loadSystemLineAccounts();
            $isNew = !isset($allAccounts[$cleanId]);

            // デフォルト指定の場合、他アカウントのデフォルトを解除
            if ($isDefault) {
                foreach ($allAccounts as $k => $v) {
                    $allAccounts[$k]['is_default'] = false;
                }
            } else if ($isNew && empty($allAccounts)) {
                $isDefault = true;
            }

            $dbFile = ($cleanId === 'senior') ? 'kureba-senior-system.db' : "kureba_{$cleanId}.db";

            $allAccounts[$cleanId] = [
                'id' => $cleanId,
                'name' => $name,
                'short_name' => $shortName,
                'theme_color' => $themeColor,
                'industry_type' => $industryType,
                'label_item1' => $labelItem1,
                'label_item2' => $labelItem2,
                'label_date1' => $labelDate1,
                'label_date2' => $labelDate2,
                'label_date3' => $labelDate3,
                'channel_access_token' => $accessToken,
                'channel_secret' => $channelSecret,
                'liff_id' => $liffId,
                'proline_calendar_url' => $prolineCalUrl,
                'proline_webhook_url' => $prolineWebhookUrl,
                'db_file' => $dbFile,
                'is_default' => $isDefault,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $saved = saveSystemLineAccounts($allAccounts);
            if (!$saved) {
                echo json_encode(['success' => false, 'error' => 'アカウント設定ファイルの保存に失敗しました。サーバーのパーミッションを確認してください']);
                exit;
            }

            // 新規アカウントなら該当DBファイルとテーブル構造を自動初期化し、system_settingsへも完全同期
            try {
                $accDb = getDbConnection($cleanId);
                if ($accDb) {
                    $nowJst = date('Y-m-d H:i:s');
                    $upStmt = $accDb->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES (:key, :val, :updated_at)");
                    $upStmt->execute([':key' => 'proline_webhook_url', ':val' => $prolineWebhookUrl, ':updated_at' => $nowJst]);
                    if (!empty($prolineCalUrl)) {
                        $upStmt->execute([':key' => 'proline_calendar_url', ':val' => $prolineCalUrl, ':updated_at' => $nowJst]);
                    }
                }
            } catch (Exception $e) {
                // 初期化失敗時はログに記録するが設定自体は保持
                error_log("DB init/sync error for account {$cleanId}: " . $e->getMessage());
            }

            // グローバルアカウントをリロード
            global $SYSTEM_LINE_ACCOUNTS;
            $SYSTEM_LINE_ACCOUNTS = loadSystemLineAccounts();

            echo json_encode([
                'success' => true,
                'message' => $isNew ? "アカウント「{$name}」を新規登録しました" : "アカウント「{$name}」の設定を更新しました",
                'account_id' => $cleanId,
                'accounts' => getAccountList()
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-3-1. 項目名・期日名クイック更新保存 (管理者認証必須) ---
        case 'save_custom_labels':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $targetAcc = $_POST['account'] ?? ($_GET['account'] ?? getActiveAccountKey());
            $allAccounts = loadSystemLineAccounts();
            if (!isset($allAccounts[$targetAcc])) {
                echo json_encode(['success' => false, 'error' => '指定されたアカウントが存在しません']);
                exit;
            }

            if (isset($_POST['industry_type'])) {
                $allAccounts[$targetAcc]['industry_type'] = trim($_POST['industry_type']);
            }
            if (isset($_POST['label_item1']) || isset($_POST['item1'])) {
                $allAccounts[$targetAcc]['label_item1'] = trim($_POST['label_item1'] ?? ($_POST['item1'] ?? ''));
            }
            if (isset($_POST['label_item2']) || isset($_POST['item2'])) {
                $allAccounts[$targetAcc]['label_item2'] = trim($_POST['label_item2'] ?? ($_POST['item2'] ?? ''));
            }
            if (isset($_POST['label_date1']) || isset($_POST['date1'])) {
                $allAccounts[$targetAcc]['label_date1'] = trim($_POST['label_date1'] ?? ($_POST['date1'] ?? ''));
            }
            if (isset($_POST['label_date2']) || isset($_POST['date2'])) {
                $allAccounts[$targetAcc]['label_date2'] = trim($_POST['label_date2'] ?? ($_POST['date2'] ?? ''));
            }
            if (isset($_POST['label_date3']) || isset($_POST['date3'])) {
                $allAccounts[$targetAcc]['label_date3'] = trim($_POST['label_date3'] ?? ($_POST['date3'] ?? ''));
            }
            $allAccounts[$targetAcc]['updated_at'] = date('Y-m-d H:i:s');

            $saved = saveSystemLineAccounts($allAccounts);
            if (!$saved) {
                echo json_encode(['success' => false, 'error' => '設定ファイルの保存に失敗しました']);
                exit;
            }

            global $SYSTEM_LINE_ACCOUNTS;
            $SYSTEM_LINE_ACCOUNTS = loadSystemLineAccounts();

            echo json_encode([
                'success' => true,
                'message' => 'カルテ項目名・期日名を更新しました',
                'custom_labels' => getAccountCustomLabels($targetAcc)
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-4. アカウント削除 (管理者認証必須) ---
        case 'delete_account':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $targetAcc = strtolower(trim($_POST['target_account'] ?? ''));
            if (empty($targetAcc)) {
                echo json_encode(['success' => false, 'error' => '削除対象のアカウントIDが未指定です']);
                exit;
            }

            if ($targetAcc === 'senior') {
                echo json_encode(['success' => false, 'error' => 'メインの基本アカウント（senior）は削除できません']);
                exit;
            }

            $allAccounts = loadSystemLineAccounts();
            if (!isset($allAccounts[$targetAcc])) {
                echo json_encode(['success' => false, 'error' => '指定されたアカウントが見つかりません']);
                exit;
            }

            if (!empty($allAccounts[$targetAcc]['is_default'])) {
                echo json_encode(['success' => false, 'error' => 'デフォルトに設定されているアカウントは削除できません。先に別のアカウントをデフォルトに指定してください']);
                exit;
            }

            $delName = $allAccounts[$targetAcc]['name'] ?? $targetAcc;
            unset($allAccounts[$targetAcc]);

            $saved = saveSystemLineAccounts($allAccounts);
            if (!$saved) {
                echo json_encode(['success' => false, 'error' => 'アカウント設定ファイルの保存に失敗しました']);
                exit;
            }

            // グローバルアカウントをリロード
            global $SYSTEM_LINE_ACCOUNTS;
            $SYSTEM_LINE_ACCOUNTS = loadSystemLineAccounts();

            echo json_encode([
                'success' => true,
                'message' => "アカウント「{$delName}」を削除しました",
                'accounts' => getAccountList()
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-5. LINEアクセストークン接続テスト ---
        case 'test_line_credentials':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $token = trim($_POST['channel_access_token'] ?? '');
            if (empty($token)) {
                echo json_encode(['success' => false, 'error' => '検証するチャネルアクセストークンを入力してください']);
                exit;
            }

            $res = verifyLineBotCredentials($token);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-6. チャット履歴取得 (個別受講生とのやり取り) ---
        case 'get_chat_messages':
            $authPass = getAdminAuthPassword($db);
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $uid = trim($_GET['uid'] ?? ($_POST['uid'] ?? ($_GET['user_id'] ?? ($_POST['user_id'] ?? ''))));
            if (empty($uid)) {
                echo json_encode(['success' => false, 'error' => 'ユーザーID(uid)が未指定です']);
                exit;
            }

            // テーブル存在保証 (単一SQLごとに安全に実行)
            try {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS chat_messages (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id TEXT NOT NULL,
                        direction TEXT NOT NULL DEFAULT 'incoming',
                        message_type TEXT NOT NULL DEFAULT 'text',
                        message_text TEXT NOT NULL DEFAULT '',
                        payload_json TEXT DEFAULT '{}',
                        is_read INTEGER NOT NULL DEFAULT 0,
                        sent_by TEXT DEFAULT '',
                        created_at DATETIME NOT NULL
                    )
                ");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_chat_uid ON chat_messages (user_id)");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_chat_read ON chat_messages (direction, is_read)");
            } catch (Throwable $t) {}

            $customer = null;
            $messages = [];

            try {
                // 受講生情報を取得 (未登録なら自動生成・同期)
                $cStmt = $db->prepare("SELECT id, user_id, user_name, picture_url, car_model, car_number, is_blocked FROM customer_cars WHERE TRIM(user_id) = :uid LIMIT 1");
                $cStmt->execute([':uid' => $uid]);
                $customer = $cStmt->fetch(PDO::FETCH_ASSOC);

                if (!$customer && str_starts_with($uid, 'U')) {
                    $customer = ensureCustomerExists($db, $uid, $activeAccountKey);
                }

                // チャット履歴一覧を取得 (古い順)
                $msgStmt = $db->prepare("
                    SELECT id, user_id, direction, message_type, message_text, payload_json, is_read, sent_by, created_at 
                    FROM chat_messages 
                    WHERE TRIM(user_id) = :uid 
                    ORDER BY id ASC 
                    LIMIT 200
                ");
                $msgStmt->execute([':uid' => $uid]);
                $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

                // もし現在のアカウントDBにメッセージが無く、別アカウントDBに保存されている可能性がある場合、横断検索してマージ
                if (empty($messages) && function_exists('getAccountList')) {
                    foreach (getAccountList() as $otherAcc) {
                        if ($otherAcc['id'] === $activeAccountKey) continue;
                        try {
                            $otherDb = getDbConnection($otherAcc['id']);
                            $oStmt = $otherDb->prepare("SELECT id, user_id, direction, message_type, message_text, payload_json, is_read, sent_by, created_at FROM chat_messages WHERE TRIM(user_id) = :uid ORDER BY id ASC LIMIT 200");
                            $oStmt->execute([':uid' => $uid]);
                            $otherMsgs = $oStmt->fetchAll(PDO::FETCH_ASSOC);
                            if (!empty($otherMsgs)) {
                                $insStmt = $db->prepare("INSERT INTO chat_messages (user_id, direction, message_type, message_text, payload_json, is_read, sent_by, created_at) VALUES (:uid, :dir, :mtype, :mtext, :payload, :is_read, :sent_by, :created_at)");
                                foreach ($otherMsgs as $om) {
                                    try {
                                        $insStmt->execute([
                                            ':uid' => $om['user_id'],
                                            ':dir' => $om['direction'],
                                            ':mtype' => $om['message_type'],
                                            ':mtext' => $om['message_text'],
                                            ':payload' => $om['payload_json'],
                                            ':is_read' => $om['is_read'],
                                            ':sent_by' => $om['sent_by'] ?? '',
                                            ':created_at' => $om['created_at']
                                        ]);
                                    } catch (Throwable $exx) {}
                                }
                                $msgStmt->execute([':uid' => $uid]);
                                $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
                                break;
                            }
                        } catch (Throwable $e) {}
                    }
                }

                foreach ($messages as &$m) {
                    $m['payload'] = !empty($m['payload_json']) ? json_decode($m['payload_json'], true) : [];
                }
                unset($m);

                // 未読メッセージを既読に更新
                $updateRead = $db->prepare("UPDATE chat_messages SET is_read = 1 WHERE TRIM(user_id) = :uid AND direction = 'incoming' AND is_read = 0");
                $updateRead->execute([':uid' => $uid]);
            } catch (Throwable $e) {
                writeDebugLog("get_chat_messages 例外", ['error' => $e->getMessage()]);
            }

            echo json_encode([
                'success' => true,
                'customer' => $customer ?: ['user_id' => $uid, 'user_name' => 'LINE友だち', 'picture_url' => ''],
                'messages' => $messages
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-7. チャットメッセージ送信 (管理画面から受講生のLINEへ返信) ---
        case 'send_chat_message':
            $authPass = getAdminAuthPassword($db);
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $uid = trim($_POST['uid'] ?? ($_POST['user_id'] ?? ''));
            $message = trim($_POST['message'] ?? ($_POST['text'] ?? ''));
            $sentBy = trim($_POST['sent_by'] ?? ($_POST['sender_name'] ?? ''));
            $senderIcon = trim($_POST['sender_icon_url'] ?? '');

            if (empty($uid) || !str_starts_with($uid, 'U')) {
                echo json_encode(['success' => false, 'error' => '有効なLINE UserID(uid)が必要です']);
                exit;
            }
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'メッセージ本文を入力してください']);
                exit;
            }

            // LINE Messaging API で Push Message 送信
            $nowJst = date('Y-m-d H:i:s');

            $msgPayload = [
                'type' => 'text',
                'text' => $message
            ];

            // 送信者名が指定されている場合のみ LINE Sender（from 名前）を付与（空欄ならfrom非表示・公式名そのまま）
            if (!empty($sentBy)) {
                $senderObj = [
                    'name' => mb_substr($sentBy, 0, 20, 'UTF-8')
                ];
                if (!empty($senderIcon) && (str_starts_with($senderIcon, 'https://'))) {
                    $senderObj['iconUrl'] = mb_substr($senderIcon, 0, 1000, 'UTF-8');
                }
                $msgPayload['sender'] = $senderObj;
            }

            // スタイル3: none (公式アカウント名のみ / from ~~~ は一切表示されない)

            try {
                $lineResult = sendLinePushMessage($uid, [$msgPayload]);

                if (!$lineResult || (isset($lineResult['success']) && !$lineResult['success'])) {
                    $errMsg = $lineResult['error'] ?? 'LINEメッセージの送信に失敗しました。アクセストークン等をご確認ください';
                    echo json_encode(['success' => false, 'error' => $errMsg]);
                    exit;
                }

                // 送信ログを chat_messages に保存
                $stmt = $db->prepare("
                    INSERT INTO chat_messages (
                        user_id, direction, message_type, message_text, payload_json, is_read, sent_by, created_at
                    ) VALUES (
                        :uid, 'outgoing', 'text', :mtext, '{}', 1, :sent_by, :now
                    )
                ");
                $stmt->execute([
                    ':uid' => $uid,
                    ':mtext' => $message,
                    ':sent_by' => $sentBy,
                    ':now' => $nowJst
                ]);
                $msgId = (int)$db->lastInsertId();

                // 顧客カルテの最新インタラクションを更新
                $preview = "💬 返信: " . mb_substr($message, 0, 40);
                recordCustomerInteraction($db, $uid, 'admin_chat', $preview);

                echo json_encode([
                    'success' => true,
                    'message' => 'メッセージを送信しました',
                    'chat_id' => $msgId,
                    'sent_at' => $nowJst
                ], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                writeDebugLog("チャット返信エラー", ['error' => $e->getMessage()]);
                echo json_encode(['success' => false, 'error' => '送信処理中にエラーが発生しました: ' . $e->getMessage()]);
            }
            exit;

        // --- 0-8. 全受講生の未読メッセージ件数一覧取得 (ブラウザ通知データ含む) ---
        case 'get_unread_chat_counts':
            try {
                // テーブル存在保証 (単一SQLごとに安全に実行)
                try {
                    $db->exec("
                        CREATE TABLE IF NOT EXISTS chat_messages (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            user_id TEXT NOT NULL,
                            direction TEXT NOT NULL DEFAULT 'incoming',
                            message_type TEXT NOT NULL DEFAULT 'text',
                            message_text TEXT NOT NULL DEFAULT '',
                            payload_json TEXT DEFAULT '{}',
                            is_read INTEGER NOT NULL DEFAULT 0,
                            sent_by TEXT DEFAULT '',
                            created_at DATETIME NOT NULL
                        )
                    ");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_chat_uid ON chat_messages (user_id)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_chat_read ON chat_messages (direction, is_read)");
                } catch (Throwable $t) {}

                $counts = [];
                $totalUnread = 0;
                $recentUnread = [];

                try {
                    // 1. 各ユーザーの未読数
                    $stmt = $db->query("
                        SELECT TRIM(user_id) as user_id, COUNT(*) as unread_count 
                        FROM chat_messages 
                        WHERE direction = 'incoming' AND is_read = 0 
                        GROUP BY TRIM(user_id)
                    ");
                    if ($stmt) {
                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($rows as $r) {
                            $cnt = (int)$r['unread_count'];
                            $counts[$r['user_id']] = $cnt;
                            $totalUnread += $cnt;
                        }
                    }

                    // 2. 直近の未読メッセージ詳細リスト (ブラウザ通知・ポップアップ用)
                    $recentUnreadStmt = $db->query("
                        SELECT m.id, TRIM(m.user_id) as user_id, m.message_type, m.message_text, m.created_at,
                               COALESCE(c.user_name, 'LINE受講生') as user_name,
                               COALESCE(c.picture_url, '') as picture_url
                        FROM chat_messages m
                        LEFT JOIN customer_cars c ON TRIM(c.user_id) = TRIM(m.user_id)
                        WHERE m.direction = 'incoming' AND m.is_read = 0
                        ORDER BY m.id DESC
                        LIMIT 20
                    ");
                    if ($recentUnreadStmt) {
                        $recentUnread = $recentUnreadStmt->fetchAll(PDO::FETCH_ASSOC);
                    }

                    // もしcustomer_carsに未登録のUIDがあれば自動登録修復
                    foreach ($recentUnread as &$unMsg) {
                        if ($unMsg['user_name'] === 'LINE受講生' && str_starts_with($unMsg['user_id'], 'U')) {
                            try {
                                $synced = ensureCustomerExists($db, $unMsg['user_id'], $activeAccountKey);
                                if ($synced) {
                                    $unMsg['user_name'] = $synced['user_name'] ?: 'LINE受講生';
                                    $unMsg['picture_url'] = $synced['picture_url'] ?: '';
                                }
                            } catch (Throwable $sEx) {}
                        }
                    }
                    unset($unMsg);
                } catch (Throwable $qEx) {
                    writeDebugLog("get_unread_chat_counts クエリ例外", ['error' => $qEx->getMessage()]);
                }

                echo json_encode([
                    'success' => true,
                    'unread_counts' => $counts,
                    'total_unread' => $totalUnread,
                    'recent_unread' => $recentUnread
                ], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                writeDebugLog("get_unread_chat_counts 致命的例外", ['error' => $e->getMessage()]);
                echo json_encode(['success' => true, 'unread_counts' => [], 'total_unread' => 0, 'recent_unread' => []]);
            }
            exit;

        // --- 0-8B. Webhook受信シミュレーション (LINEチャット擬似受信テスト・デバッグ用) ---
        case 'simulate_line_chat_message':
            $authPass = getAdminAuthPassword($db);
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $testUid = trim($_POST['uid'] ?? ($_GET['uid'] ?? 'U_test_demo_' . substr(md5(uniqid('', true)), 0, 8)));
            if (!str_starts_with($testUid, 'U')) {
                $testUid = 'U' . $testUid;
            }
            $testUserName = trim($_POST['user_name'] ?? ($_GET['user_name'] ?? 'テスト受講生（田中 一郎）'));
            $testMsgText = trim($_POST['message_text'] ?? ($_GET['message_text'] ?? 'こんにちは！点検・受講の予約について相談したいです。(テスト送信)'));
            $testMsgType = trim($_POST['message_type'] ?? ($_GET['message_type'] ?? 'text'));
            $nowJst = date('Y-m-d H:i:s');

            try {
                // 1. customer_cars テーブルに受講生レコードが存在することを保証
                $chkC = $db->prepare("SELECT id, user_name, picture_url FROM customer_cars WHERE TRIM(user_id) = :uid LIMIT 1");
                $chkC->execute([':uid' => $testUid]);
                $cRow = $chkC->fetch(PDO::FETCH_ASSOC);

                if (!$cRow) {
                    $insC = $db->prepare("
                        INSERT INTO customer_cars (
                            user_id, user_name, picture_url, car_model, car_number,
                            last_interaction_at, last_interaction_type, last_interaction_preview,
                            created_at, updated_at
                        ) VALUES (
                            :uid, :uname, 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png', '【テスト】受講コース未設定', '',
                            :now1, 'user_message', :prev, :now2, :now3
                        )
                    ");
                    $prevText = "💬 " . mb_substr($testMsgText, 0, 45);
                    $insC->execute([
                        ':uid' => $testUid,
                        ':uname' => $testUserName,
                        ':now1' => $nowJst,
                        ':prev' => $prevText,
                        ':now2' => $nowJst,
                        ':now3' => $nowJst
                    ]);
                    $carId = (int)$db->lastInsertId();
                    $picUrl = 'https://cdn-icons-png.flaticon.com/512/3135/3135715.png';
                } else {
                    $carId = (int)$cRow['id'];
                    $testUserName = !empty($cRow['user_name']) ? $cRow['user_name'] : $testUserName;
                    $picUrl = $cRow['picture_url'] ?? '';
                    $prevText = "💬 " . mb_substr($testMsgText, 0, 45);
                    $db->prepare("
                        UPDATE customer_cars 
                        SET is_blocked = 0, 
                            blocked_at = NULL, 
                            last_interaction_at = :now, 
                            last_interaction_type = 'user_message', 
                            last_interaction_preview = :prev 
                        WHERE id = :id
                    ")->execute([':now' => $nowJst, ':prev' => $prevText, ':id' => $carId]);
                }

                // 2. chat_messages テーブルへ未読(is_read=0)で保存
                $insMsg = $db->prepare("
                    INSERT INTO chat_messages (
                        user_id, direction, message_type, message_text, payload_json, is_read, sent_by, created_at
                    ) VALUES (
                        :uid, 'incoming', :mtype, :mtext, :payload, 0, '', :now
                    )
                ");
                $payloadJson = json_encode(['text' => $testMsgText, 'simulated' => true], JSON_UNESCAPED_UNICODE);
                $insMsg->execute([
                    ':uid' => $testUid,
                    ':mtype' => $testMsgType,
                    ':mtext' => $testMsgText,
                    ':payload' => $payloadJson,
                    ':now' => $nowJst
                ]);
                $msgId = (int)$db->lastInsertId();

                writeDebugLog("Webhookシミュレーション受信実行", [
                    'account' => $activeAccountKey,
                    'uid' => $testUid,
                    'user_name' => $testUserName,
                    'msg_id' => $msgId,
                    'text' => $testMsgText
                ]);

                // 3. 通知送信シミュレーション (LINE Push / Discord / Slack / WebPush)
                $msgDataPayload = [
                    'user_id' => $testUid,
                    'user_name' => $testUserName,
                    'picture_url' => $picUrl,
                    'message_text' => $testMsgText,
                    'message_type' => $testMsgType,
                    'image_url' => ''
                ];

                $notifResults = [];
                // 管理者LINE Push通知
                if (function_exists('sendAdminLineChatMessageNotification')) {
                    try {
                        sendAdminLineChatMessageNotification($msgDataPayload, null, $db);
                        $notifResults['line_admin_push'] = 'sent';
                    } catch (Throwable $e) {
                        $notifResults['line_admin_push'] = 'error: ' . $e->getMessage();
                    }
                }

                // Discord通知
                try {
                    sendDiscordChatMessageNotification($msgDataPayload, null, $db);
                    $notifResults['discord'] = 'executed';
                } catch (Throwable $e) {
                    $notifResults['discord'] = 'error: ' . $e->getMessage();
                }

                // Slack通知
                try {
                    sendSlackChatMessageNotification($msgDataPayload, null, $db);
                    $notifResults['slack'] = 'executed';
                } catch (Throwable $e) {
                    $notifResults['slack'] = 'error: ' . $e->getMessage();
                }

                // WebPushブラウザ通知
                if (function_exists('sendWebPushChatMessageNotification')) {
                    try {
                        $webPushRes = sendWebPushChatMessageNotification($msgDataPayload, $db, $activeAccountKey);
                        $notifResults['web_push'] = $webPushRes;
                    } catch (Throwable $e) {
                        $notifResults['web_push'] = 'error: ' . $e->getMessage();
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'LINEチャット模擬受信テストが完了しました！管理画面の未読バッジ・新着トースト・音声チャイムをご確認ください。',
                    'simulated_data' => [
                        'user_id' => $testUid,
                        'user_name' => $testUserName,
                        'message_id' => $msgId,
                        'message_text' => $testMsgText,
                        'created_at' => $nowJst,
                        'notifications' => $notifResults
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                writeDebugLog("Webhookシミュレーション例外", ['error' => $e->getMessage()]);
                echo json_encode(['success' => false, 'error' => 'シミュレーション実行エラー: ' . $e->getMessage()]);
            }
            exit;

        // --- 0-8C. Webhook接続診断 & ログ取得 API ---
        case 'get_webhook_diagnostics':
            $authPass = getAdminAuthPassword($db);
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $accConfig = getAccountConfig($activeAccountKey);
            $hasToken = !empty($accConfig['channel_access_token']) && $accConfig['channel_access_token'] !== 'YOUR_CHANNEL_ACCESS_TOKEN_HERE';
            $hasSecret = !empty($accConfig['channel_secret']) && $accConfig['channel_secret'] !== 'YOUR_CHANNEL_SECRET_HERE';

            $tokenMasked = $hasToken 
                ? (substr($accConfig['channel_access_token'], 0, 8) . '...' . substr($accConfig['channel_access_token'], -6) . ' (設定済み・' . strlen($accConfig['channel_access_token']) . '文字)')
                : '未設定 (config.php または管理画面で登録してください)';
            
            $secretMasked = $hasSecret
                ? (substr($accConfig['channel_secret'], 0, 4) . '...' . substr($accConfig['channel_secret'], -4) . ' (設定済み・' . strlen($accConfig['channel_secret']) . '文字)')
                : '未設定';

            $baseUrl = getBaseUrl();
            $webhookUrlPublic = $baseUrl . "/webhook.php" . (!empty($accConfig['is_default']) ? '' : "?account={$activeAccountKey}");
            $webhookUrlDirect = $baseUrl . "/public_html/webhook.php" . (!empty($accConfig['is_default']) ? '' : "?account={$activeAccountKey}");

            // 受講生数 & 最新メッセージ数
            $studentCount = 0;
            $unreadCount = 0;
            $recentMessages = [];
            try {
                $stStmt = $db->query("SELECT COUNT(*) as cnt FROM customer_cars");
                $studentCount = (int)$stStmt->fetch()['cnt'];

                $unStmt = $db->query("SELECT COUNT(*) as cnt FROM chat_messages WHERE direction = 'incoming' AND is_read = 0");
                $unreadCount = (int)$unStmt->fetch()['cnt'];

                $msgStmt = $db->query("
                    SELECT m.id, m.user_id, m.direction, m.message_type, m.message_text, m.is_read, m.created_at,
                           COALESCE(c.user_name, '未登録') as user_name
                    FROM chat_messages m
                    LEFT JOIN customer_cars c ON TRIM(c.user_id) = TRIM(m.user_id)
                    ORDER BY m.id DESC LIMIT 10
                ");
                $recentMessages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $t) {}

            // デバッグログ読み込み (最新50行)
            $debugLogFile = __DIR__ . '/webhook_debug.log';
            $debugLogs = [];
            if (file_exists($debugLogFile)) {
                $lines = file($debugLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $debugLogs = array_slice($lines, -50);
            }

            // プロラインログ読み込み (最新30行)
            $prolineLogFile = __DIR__ . '/proline_relay.log';
            $prolineLogs = [];
            if (file_exists($prolineLogFile)) {
                $lines = file($prolineLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $prolineLogs = array_slice($lines, -30);
            }

            $prolineSettings = getProlineSettings($db, $activeAccountKey);

            echo json_encode([
                'success' => true,
                'account' => [
                    'id' => $activeAccountKey,
                    'name' => $accConfig['name'],
                    'has_token' => $hasToken,
                    'token_status' => $tokenMasked,
                    'has_secret' => $hasSecret,
                    'secret_status' => $secretMasked,
                    'student_count' => $studentCount,
                    'unread_count' => $unreadCount,
                    'webhook_url_recommended' => $webhookUrlPublic,
                    'webhook_url_alt' => $webhookUrlDirect,
                ],
                'proline' => [
                    'enabled' => !empty($prolineSettings['relay_enabled']),
                    'urls' => $prolineSettings['webhook_urls'] ?? [],
                    'last_relay_at' => $prolineSettings['last_relay_at'] ?? '',
                    'last_relay_status' => $prolineSettings['last_relay_status'] ?? ''
                ],
                'checklist' => [
                    [
                        'title' => '1. LINE Official Account Manager の応答設定',
                        'desc' => 'LINE公式アカウント管理画面 (manager.line.biz) の「設定」>「応答設定」で、応答モードが【Bot】、Webhookが【オン】になっていることを確認してください。（※「チャット」モードになっているとLINE社側でWebhookが遮断されます）',
                        'status' => 'critical'
                    ],
                    [
                        'title' => '2. LINE Developers の Webhook URL & Webhook利用トグル',
                        'desc' => 'LINE Developers コンソールの「Messaging API」設定で、Webhook URLに上記のURLを設定し、「Webhookの利用 (Use Webhook)」を【ON (有効)】にしてください。「検証 (Verify)」ボタンを押して「成功 (Success)」と表示されれば疎通完了です。',
                        'status' => 'critical'
                    ],
                    [
                        'title' => '3. チャネルアクセストークン & チャネルシークレット',
                        'desc' => 'LINE Developersから取得した最新の「長期アクセストークン」および「Channel Secret」が設定されていることを確認してください。',
                        'status' => ($hasToken && $hasSecret) ? 'ok' : 'warning'
                    ]
                ],
                'debug_logs' => $debugLogs,
                'proline_logs' => $prolineLogs,
                'recent_messages' => $recentMessages
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-8D. 全ログ統合ビューア API (デバッグページ用) ---
        case 'get_all_debug_logs':
            $authPass = getAdminAuthPassword($db);
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $maxLines = max(10, min(2000, (int)($_GET['limit'] ?? 500)));

            // 1. Webhookデバッグログ
            $whLogFile = __DIR__ . '/webhook_debug.log';
            $whLogs = [];
            $whSize = 0;
            if (file_exists($whLogFile)) {
                $whSize = filesize($whLogFile);
                $lines = file($whLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $whLogs = array_slice($lines, -$maxLines);
            }

            // 2. プロライン転送ログ
            $prolineLogFile = __DIR__ . '/proline_relay.log';
            $prolineLogs = [];
            $prolineSize = 0;
            if (file_exists($prolineLogFile)) {
                $prolineSize = filesize($prolineLogFile);
                $lines = file($prolineLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $prolineLogs = array_slice($lines, -$maxLines);
            }

            // 3. DB内 チャット送受信ログ (直近100件)
            $dbChatLogs = [];
            try {
                $stmt = $db->query("
                    SELECT m.id, m.user_id, m.direction, m.message_type, m.message_text, m.is_read, m.sent_by, m.created_at,
                           COALESCE(c.user_name, '未登録受講生') as user_name
                    FROM chat_messages m
                    LEFT JOIN customer_cars c ON TRIM(c.user_id) = TRIM(m.user_id)
                    ORDER BY m.id DESC LIMIT 100
                ");
                if ($stmt) {
                    $dbChatLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Throwable $e) {}

            // 4. システム環境情報
            $sysInfo = [
                'php_version' => PHP_VERSION,
                'os' => PHP_OS,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'active_account' => $activeAccountKey,
                'active_account_name' => getAccountConfig($activeAccountKey)['name'] ?? $activeAccountKey,
                'webhook_debug_log_size' => $whSize,
                'proline_relay_log_size' => $prolineSize,
                'now_jst' => date('Y-m-d H:i:s'),
                'accounts' => getAccountList()
            ];

            echo json_encode([
                'success' => true,
                'system_info' => $sysInfo,
                'webhook_logs' => $whLogs,
                'proline_logs' => $prolineLogs,
                'db_chat_logs' => $dbChatLogs
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-8E. ログ初期化・消去 API ---
        case 'clear_debug_logs':
            $authPass = getAdminAuthPassword($db);
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $target = $_POST['target'] ?? ($_GET['target'] ?? 'webhook');
            $nowJst = date('Y-m-d H:i:s');
            $cleared = [];

            if ($target === 'all' || $target === 'webhook') {
                $whLogFile = __DIR__ . '/webhook_debug.log';
                @file_put_contents($whLogFile, "[{$nowJst}] ログをクリアしました (管理者手動操作)\n");
                $cleared[] = 'webhook_debug.log';
            }
            if ($target === 'all' || $target === 'proline') {
                $prolineLogFile = __DIR__ . '/proline_relay.log';
                @file_put_contents($prolineLogFile, "[{$nowJst}] プロライン転送ログをクリアしました (管理者手動操作)\n");
                $cleared[] = 'proline_relay.log';
            }

            echo json_encode([
                'success' => true,
                'message' => '指定されたログファイルをクリアしました',
                'cleared' => $cleared,
                'cleared_at' => $nowJst
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-9. Discord通知設定の取得 ---
        case 'get_discord_settings':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $settings = getDiscordSettings($db, $activeAccountKey);
            echo json_encode(['success' => true, 'settings' => $settings], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-10. Discord通知設定の保存 ---
        case 'save_discord_settings':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $hasConsultation = !empty($_POST['notify_consultation']) || !empty($_POST['notify_inquiry']);
            $settings = [
                'webhook_url' => trim($_POST['webhook_url'] ?? ''),
                'enabled' => !empty($_POST['webhook_url']),
                'notify_message' => !empty($_POST['notify_message']),
                'notify_follow' => !empty($_POST['notify_follow']),
                'notify_consultation' => $hasConsultation,
                'notify_inquiry' => $hasConsultation
            ];

            $res = saveDiscordSettings($settings, $db, $activeAccountKey);
            if ($res) {
                echo json_encode(['success' => true, 'message' => 'Discord通知設定を保存しました', 'settings' => $settings], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'error' => '設定の保存に失敗しました']);
            }
            exit;

        // --- 0-11. Discord通知の疎通テスト送信 ---
        case 'test_discord_notification':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $webhookUrl = trim($_POST['webhook_url'] ?? '');
            if (empty($webhookUrl)) {
                $settings = getDiscordSettings($db, $activeAccountKey);
                $webhookUrl = $settings['webhook_url'] ?? '';
            }

            if (empty($webhookUrl)) {
                echo json_encode(['success' => false, 'error' => 'Discord Webhook URL を入力してください']);
                exit;
            }

            $res = sendDiscordTestNotification($webhookUrl);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-12. Slack通知設定の取得 ---
        case 'get_slack_settings':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $settings = getSlackSettings($db, $activeAccountKey);
            echo json_encode(['success' => true, 'settings' => $settings], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-13. Slack通知設定の保存 ---
        case 'save_slack_settings':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $hasConsultation = !empty($_POST['notify_consultation']) || !empty($_POST['notify_inquiry']);
            $settings = [
                'webhook_url' => trim($_POST['webhook_url'] ?? ''),
                'enabled' => !empty($_POST['webhook_url']),
                'notify_message' => !empty($_POST['notify_message']),
                'notify_follow' => !empty($_POST['notify_follow']),
                'notify_consultation' => $hasConsultation,
                'notify_inquiry' => $hasConsultation
            ];

            $res = saveSlackSettings($settings, $db, $activeAccountKey);
            if ($res) {
                echo json_encode(['success' => true, 'message' => 'Slack通知設定を保存しました', 'settings' => $settings], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'error' => '設定の保存に失敗しました']);
            }
            exit;

        // --- 0-14. Slack通知の疎通テスト送信 ---
        case 'test_slack_notification':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $webhookUrl = trim($_POST['webhook_url'] ?? '');
            if (empty($webhookUrl)) {
                $settings = getSlackSettings($db, $activeAccountKey);
                $webhookUrl = $settings['webhook_url'] ?? '';
            }

            if (empty($webhookUrl)) {
                echo json_encode(['success' => false, 'error' => 'Slack Incoming Webhook URL を入力してください']);
                exit;
            }

            $res = sendSlackTestNotification($webhookUrl);
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-15. 管理者ログイン (メール認証コード送信・パスワード不要) ---
        case 'admin_request_email_code':
        case 'admin_login_step1':
            $twoFa = getAdmin2FASettings($db);
            $targetEmail = !empty($twoFa['email']) ? $twoFa['email'] : 'kawai@kureba.co.jp';

            $sessionRes = createAdmin2FASession($db, $targetEmail);
            echo json_encode([
                'success' => true,
                'require_2fa' => true,
                'session_token' => $sessionRes['session_token'],
                'email' => $targetEmail,
                'email_hint' => $sessionRes['email_hint'],
                'expires_in' => $sessionRes['expires_in'],
                'mail_sent' => $sessionRes['mail_sent'],
                'message' => "認証コードを {$targetEmail} 宛てに送信しました"
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-16. 管理者ログイン STEP 2 (2FA 6桁コード検証) ---
        case 'admin_login_verify_2fa':
            $sessionToken = trim($_POST['session_token'] ?? ($_GET['session_token'] ?? ''));
            $inputCode = trim($_POST['code'] ?? ($_GET['code'] ?? ''));

            if (empty($sessionToken) || empty($inputCode)) {
                echo json_encode(['success' => false, 'error' => '認証コードを入力してください'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $verifyRes = verifyAdmin2FACode($db, $sessionToken, $inputCode);
            if ($verifyRes['success'] && !empty($verifyRes['auth_token'])) {
                $authToken = $verifyRes['auth_token'];
                @setcookie('admin_auth_token', $authToken, [
                    'expires' => time() + 86400 * 30,
                    'path' => '/',
                    'httponly' => false,
                    'samesite' => 'Lax'
                ]);
                @setcookie('admin_pass', ADMIN_PASSWORD, [
                    'expires' => time() + 86400 * 30,
                    'path' => '/',
                    'httponly' => false,
                    'samesite' => 'Lax'
                ]);
            }
            echo json_encode($verifyRes, JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-17. 2FAコード再送信 ---
        case 'admin_login_resend_2fa':
            $sessionToken = trim($_POST['session_token'] ?? ($_GET['session_token'] ?? ''));
            if (empty($sessionToken)) {
                echo json_encode(['success' => false, 'error' => '認証セッションが無効です'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $resendRes = resendAdmin2FACode($db, $sessionToken);
            echo json_encode($resendRes, JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-18. 2FA設定取得 ---
        case 'get_2fa_settings':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $settings = getAdmin2FASettings($db);
            echo json_encode(['success' => true, 'settings' => $settings], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-19. 2FA設定保存 ---
        case 'save_2fa_settings':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $email = trim($_POST['email'] ?? '');
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'error' => '有効なメールアドレスを入力してください']);
                exit;
            }

            $settings = [
                'enabled' => filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'email' => $email,
                'lifetime_minutes' => max(1, min(60, (int)($_POST['lifetime_minutes'] ?? 10))),
                'max_attempts' => max(1, min(20, (int)($_POST['max_attempts'] ?? 5)))
            ];

            $res = saveAdmin2FASettings($settings, $db);
            if ($res) {
                echo json_encode(['success' => true, 'message' => 'メール二段階認証設定を保存しました', 'settings' => $settings], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'error' => '設定の保存に失敗しました']);
            }
            exit;

        // --- 0-20. 2FAテストメール送信 ---
        case 'test_2fa_email':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $targetEmail = trim($_POST['email'] ?? '');
            if (empty($targetEmail)) {
                $currentSettings = getAdmin2FASettings($db);
                $targetEmail = $currentSettings['email'] ?? '';
            }

            if (empty($targetEmail) || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'error' => '有効な送信先メールアドレスを指定してください']);
                exit;
            }

            $testRes = sendAdmin2FATestEmail($targetEmail);
            echo json_encode($testRes, JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-21. クイックリプライ設定取得 ---
        case 'get_quick_reply_settings':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $targetAcc = trim($_GET['account'] ?? ($_POST['account'] ?? getActiveAccountKey()));
            $targetDb = getDbConnection($targetAcc);
            $settings = getQuickReplySettings($targetAcc, $targetDb);
            $accConfig = getAccountConfig($targetAcc);

            echo json_encode([
                'success' => true,
                'account' => $targetAcc,
                'account_name' => $accConfig['name'] ?? $targetAcc,
                'industry_type' => $accConfig['industry_type'] ?? 'senior',
                'settings' => $settings
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-22. クイックリプライ設定保存 ---
        case 'save_quick_reply_settings':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $targetAcc = trim($_POST['account'] ?? ($_GET['account'] ?? getActiveAccountKey()));
            $targetDb = getDbConnection($targetAcc);
            $enabled = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $mode = trim((string)($_POST['mode'] ?? 'none'));

            $customItems = [];
            if (!empty($_POST['custom_items'])) {
                if (is_array($_POST['custom_items'])) {
                    $customItems = $_POST['custom_items'];
                } else {
                    $decoded = json_decode($_POST['custom_items'], true);
                    if (is_array($decoded)) {
                        $customItems = $decoded;
                    }
                }
            }

            $settings = [
                'enabled' => $enabled,
                'mode' => $mode,
                'custom_items' => $customItems
            ];

            $res = saveQuickReplySettings($targetAcc, $settings, $targetDb);
            if ($res) {
                echo json_encode([
                    'success' => true,
                    'message' => 'クイックリプライ設定を保存しました',
                    'account' => $targetAcc,
                    'settings' => $settings
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'error' => '設定の保存に失敗しました']);
            }
            exit;

        // --- 0-23. 管理者ログアウト ---
        case 'admin_logout':
            $token = $_COOKIE['admin_auth_token'] ?? ($_POST['auth_token'] ?? ($_GET['auth_token'] ?? ''));
            if (!empty($token)) {
                revokeAdminAuthToken($db, $token);
            }
            @setcookie('admin_auth_token', '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
            @setcookie('admin_pass', '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
            echo json_encode(['success' => true, 'message' => 'ログアウトしました'], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 1. 車両一覧取得 (検索・フィルター・ページネーション) ---
        case 'list':
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;

            $where = ["is_active = 1"];
            $params = [];

            // キーワード検索 (車名, 排気量, 年式)
            if (!empty($_GET['keyword'])) {
                $kw = trim($_GET['keyword']);
                $where[] = "(title LIKE :kw OR displacement LIKE :kw OR year LIKE :kw)";
                $params[':kw'] = "%{$kw}%";
            }

            // 支払総額 (上限)
            if (isset($_GET['max_price']) && is_numeric($_GET['max_price'])) {
                $where[] = "total_price_num <= :max_price";
                $params[':max_price'] = (float)$_GET['max_price'];
            }

            // 走行距離 (上限)
            if (isset($_GET['max_distance']) && is_numeric($_GET['max_distance'])) {
                $where[] = "distance_num <= :max_distance";
                $params[':max_distance'] = (float)$_GET['max_distance'];
            }

            // 修復歴 (0: なし, 1: あり)
            if (isset($_GET['repair'])) {
                if ($_GET['repair'] === 'none') {
                    $where[] = "(repair_history = 'なし' OR repair_history = '-' OR repair_history IS NULL)";
                }
            }

            $whereSql = implode(' AND ', $where);

            // ソート
            $sort = $_GET['sort'] ?? 'price_asc';
            $orderSql = match ($sort) {
                'price_desc' => '(total_price_num IS NULL), total_price_num DESC',
                'distance_asc' => '(distance_num IS NULL), distance_num ASC',
                'year_desc' => 'year DESC',
                default => '(total_price_num IS NULL), total_price_num ASC', // price_asc
            };

            // 総件数カウント
            $countStmt = $db->prepare("SELECT COUNT(*) as total FROM cars WHERE {$whereSql}");
            $countStmt->execute($params);
            $totalCount = (int)$countStmt->fetch()['total'];

            // 車両一覧取得
            $stmt = $db->prepare("
                SELECT * FROM cars 
                WHERE {$whereSql} 
                ORDER BY {$orderSql} 
                LIMIT :limit OFFSET :offset
            ");
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $cars = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit,
                'cars' => $cars
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 2. 車両単体詳細取得 ---
        case 'detail':
            $carId = $_GET['id'] ?? '';
            if (empty($carId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '車両IDが指定されていません。']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM cars WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $carId]);
            $car = $stmt->fetch();

            if (!$car) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '車両が見つかりませんでした。']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'car' => $car
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 3. メタ情報取得 (価格帯範囲、総台数、店舗情報) ---
        case 'meta':
            $stmt = $db->query("
                SELECT 
                    COUNT(*) as total_active_cars,
                    MIN(total_price_num) as min_price,
                    MAX(total_price_num) as max_price,
                    MIN(distance_num) as min_distance,
                    MAX(distance_num) as max_distance,
                    MAX(updated_at) as last_updated
                FROM cars 
                WHERE is_active = 1
            ");
            $stats = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'shop' => [
                    'name' => SHOP_NAME,
                    'code' => SHOP_CODE,
                    'goo_url' => SHOP_GOO_URL
                ],
                'stats' => $stats
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 4. LIFFからの正式問い合わせ受付 & Discord通知 ---
        case 'inquiry':
            $carId = $_POST['id'] ?? ($_GET['id'] ?? '');
            $inquiryType = $_POST['type'] ?? ($_GET['type'] ?? '在庫確認');
            $userId = $_POST['uid'] ?? ($_GET['uid'] ?? '');
            $userName = $_POST['uname'] ?? ($_GET['uname'] ?? '');

            if (empty($carId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '車両IDが必要です']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM cars WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $carId]);
            $car = $stmt->fetch();

            if (!$car) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '車両が見つかりませんでした']);
                exit;
            }

            $userProfile = null;
            if (!empty($userId)) {
                $userProfile = getLineUserProfile($userId);
            }
            if (empty($userProfile) && !empty($userName)) {
                $userProfile = ['displayName' => $userName];
            }

            // Discord 通知送信
            if (function_exists('sendDiscordInquiryNotification')) {
                sendDiscordInquiryNotification($car, $inquiryType, $userProfile, $userId);
            }

            echo json_encode([
                'success' => true,
                'message' => 'お問い合わせを受付いたしました。'
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 5. ユーザー用: 自身の全愛車・メンテナンス情報取得 ---
        case 'get_customer':
            $userId = $_GET['uid'] ?? ($_POST['uid'] ?? '');
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid ORDER BY id ASC");
            $stmt->execute([':uid' => $userId]);
            $cars = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'cars' => $cars,
                'customer' => !empty($cars) ? $cars[0] : null
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 5-2. ユーザー用: 店舗との初期連携（未登録ユーザーの管理画面自動認識） ---
        case 'init_customer_link':
            $userId = trim($_POST['uid'] ?? ($_GET['uid'] ?? ''));
            $userName = trim($_POST['uname'] ?? ($_GET['uname'] ?? ''));

            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }

            // 既存車両を確認
            $checkStmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid LIMIT 1");
            $checkStmt->execute([':uid' => $userId]);
            $existing = $checkStmt->fetch();

            if (!$existing) {
                // 初期連携レコードを登録
                $insertStmt = $db->prepare("
                    INSERT INTO customer_cars (
                        user_id, user_name, car_model, car_number,
                        created_at, updated_at
                    ) VALUES (
                        :uid, :uname, '【未登録】愛車登録待ち', '',
                        datetime('now', '+9 hours'), datetime('now', '+9 hours')
                    )
                ");
                $insertStmt->execute([
                    ':uid' => $userId,
                    ':uname' => $userName ?: '新規お客様'
                ]);
                $newCarId = (int)$db->lastInsertId();

                writeDebugLog("店舗初期連携完了", ['uid' => $userId, 'name' => $userName, 'car_id' => $newCarId]);

                // Discord & Slack に新規ユーザー登録を通知
                if (function_exists('sendDiscordNewCustomerNotification')) {
                    sendDiscordNewCustomerNotification($userId, $userName);
                }
                if (function_exists('sendSlackFollowNotification')) {
                    sendSlackFollowNotification([
                        'user_id' => $userId,
                        'user_name' => $userName,
                        'event_text' => '新しいユーザーが追加されました！'
                    ], null, $db);
                }

                // LINEメッセージで連携完了を通知
                if (str_starts_with($userId, 'U')) {
                    $displayName = $userName ?: 'お客様';
                    $welcomeMsg = [
                        'type' => 'text',
                        'text' => "{$displayName} 様\n\n【" . SHOP_NAME . "】愛車点検パスポートとの連携が完了しました！🚗✨\n\n店舗スタッフ側でお客様の愛車や点検予定日（オイル・定期点検・車検）の登録・設定が可能です。\nご自身で登録される場合は、メニューの「愛車点検パスポート」よりいつでもご入力いただけます。"
                    ];
                    try {
                        sendLinePushMessage($userId, [$welcomeMsg]);
                    } catch (Exception $e) {}
                }
            }

            // 最新の車両一覧を取得して返却
            $stmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid ORDER BY id ASC");
            $stmt->execute([':uid' => $userId]);
            $cars = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'message' => '店舗との連携が完了しました！',
                'cars' => $cars,
                'customer' => !empty($cars) ? $cars[0] : null
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 5-3. LIFFトリガー用: サイレントPostback送信実行 ---
        case 'trigger_postback':
            $userId = trim($_POST['uid'] ?? ($_GET['uid'] ?? ''));
            $dataStr = $_POST['data'] ?? ($_GET['data'] ?? '');

            // クエリパラメータから直接組み立てるフォールバック
            if (empty($dataStr)) {
                $postbackParams = $_POST ?: $_GET;
                unset($postbackParams['action']); // 'trigger_postback' 自体を除外
                if (isset($postbackParams['pb_action'])) {
                    $postbackParams['action'] = $postbackParams['pb_action'];
                    unset($postbackParams['pb_action']);
                }
                $dataStr = http_build_query($postbackParams);
            }

            writeDebugLog("api.php trigger_postback 受付", [
                'uid' => $userId,
                'dataStr' => $dataStr,
                'method' => $_SERVER['REQUEST_METHOD']
            ]);

            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です (uid missing)']);
                exit;
            }

            try {
                require_once __DIR__ . '/webhook.php';
                if (!function_exists('executeSilentPostbackPush')) {
                    throw new Exception("executeSilentPostbackPush 関数が見つかりません");
                }
                $res = executeSilentPostbackPush($db, $userId, $dataStr);
                $isSuccess = is_array($res) ? !empty($res['success']) : (bool)$res;

                echo json_encode([
                    'success' => $isSuccess,
                    'message' => $isSuccess ? 'サイレントPostbackを実行しました' : ($res['error'] ?? 'Push送信に失敗しました (詳細はwebhook_debug.logを確認)'),
                    'uid' => $userId,
                    'data' => $dataStr
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } catch (Throwable $t) {
                writeDebugLog("trigger_postback 例外エラー", ['error' => $t->getMessage(), 'trace' => $t->getTraceAsString()]);
                echo json_encode([
                    'success' => false,
                    'error' => $t->getMessage()
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            break;

        // --- 6. ユーザー用: 愛車の登録・更新 ---
        case 'save_customer':
            $carId = !empty($_POST['car_id']) ? (int)$_POST['car_id'] : null;
            $userId = trim($_POST['uid'] ?? '');
            $userName = trim($_POST['uname'] ?? '');
            $carModel = trim($_POST['car_model'] ?? '');
            $carNumber = trim($_POST['car_number'] ?? '');
            $oilLastDate = !empty($_POST['oil_last_date']) ? $_POST['oil_last_date'] : null;
            $oilNextDate = !empty($_POST['oil_next_date']) ? $_POST['oil_next_date'] : null;
            $periodicInspNextDate = !empty($_POST['periodic_insp_next_date']) ? $_POST['periodic_insp_next_date'] : null;
            $inspectionNextDate = !empty($_POST['inspection_next_date']) ? $_POST['inspection_next_date'] : null;

            if (empty($userId)) {
                $userId = 'USER_' . uniqid();
            }

            writeDebugLog("顧客メンテナンス保存受付 (複数台対応)", [
                'car_id' => $carId,
                'uid' => $userId,
                'name' => $userName,
                'car' => $carModel,
                'oil' => $oilNextDate,
                'periodic' => $periodicInspNextDate,
                'shaken' => $inspectionNextDate
            ]);

            $savedCarId = $carId;
            if ($carId) {
                // 指定車両の更新
                $stmt = $db->prepare("
                    UPDATE customer_cars SET
                        user_name = :uname,
                        car_model = :car_model,
                        car_number = :car_number,
                        oil_last_date = :oil_last_date,
                        oil_next_date = :oil_next_date,
                        periodic_insp_next_date = :periodic_next_date,
                        inspection_next_date = :inspection_next_date,
                        updated_at = datetime('now', '+9 hours')
                    WHERE id = :car_id AND user_id = :uid
                ");
                $stmt->execute([
                    ':car_id' => $carId,
                    ':uid' => $userId,
                    ':uname' => $userName,
                    ':car_model' => $carModel,
                    ':car_number' => $carNumber,
                    ':oil_last_date' => $oilLastDate,
                    ':oil_next_date' => $oilNextDate,
                    ':periodic_next_date' => $periodicInspNextDate,
                    ':inspection_next_date' => $inspectionNextDate,
                ]);
            } else {
                // 新規車両の追加
                $stmt = $db->prepare("
                    INSERT INTO customer_cars (
                        user_id, user_name, car_model, car_number,
                        oil_last_date, oil_next_date, periodic_insp_next_date, inspection_next_date,
                        created_at, updated_at
                    ) VALUES (
                        :uid, :uname, :car_model, :car_number,
                        :oil_last_date, :oil_next_date, :periodic_next_date, :inspection_next_date,
                        datetime('now', '+9 hours'), datetime('now', '+9 hours')
                    )
                ");
                $stmt->execute([
                    ':uid' => $userId,
                    ':uname' => $userName,
                    ':car_model' => $carModel,
                    ':car_number' => $carNumber,
                    ':oil_last_date' => $oilLastDate,
                    ':oil_next_date' => $oilNextDate,
                    ':periodic_next_date' => $periodicInspNextDate,
                    ':inspection_next_date' => $inspectionNextDate,
                ]);
                $savedCarId = (int)$db->lastInsertId();
            }

            // LINEユーザーIDの場合、LINEトークへ登録完了メッセージを送信
            if (str_starts_with($userId, 'U')) {
                $displayName = $userName ?: 'お客様';
                $carDisplay = $carModel . ($carNumber ? " ({$carNumber})" : "");
                $oilDisplay = $oilNextDate ?: '未設定';
                $periodicDisplay = $periodicInspNextDate ?: '未設定';
                $inspDisplay = $inspectionNextDate ?: '未設定';

                $confirmFlex = [
                    'type' => 'flex',
                    'altText' => "【設定保存完了】{$carModel}のメンテナンス予定日を登録・更新しました",
                    'contents' => [
                        'type' => 'bubble',
                        'size' => 'mega',
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'paddingAll' => '20px',
                            'contents' => [
                                [
                                    'type' => 'box',
                                    'layout' => 'baseline',
                                    'contents' => [
                                        ['type' => 'text', 'text' => '✅ 愛車・点検情報の保存完了', 'weight' => 'bold', 'size' => 'sm', 'color' => '#06C755']
                                    ]
                                ],
                                [
                                    'type' => 'text',
                                    'text' => "{$displayName} 様",
                                    'weight' => 'bold',
                                    'size' => 'xl',
                                    'margin' => 'sm',
                                    'color' => '#1e293b'
                                ],
                                [
                                    'type' => 'text',
                                    'text' => "愛車【{$carModel}】のメンテナンス予定日を保存・更新しました！\n予定日が近づきましたら、LINEにてリマインドをお届けします。",
                                    'size' => 'xs',
                                    'color' => '#475569',
                                    'margin' => 'sm',
                                    'wrap' => true
                                ],
                                [
                                    'type' => 'separator',
                                    'margin' => 'md'
                                ],
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'margin' => 'md',
                                    'spacing' => 'sm',
                                    'backgroundColor' => '#f8fafc',
                                    'paddingAll' => '12px',
                                    'cornerRadius' => 'md',
                                    'contents' => [
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '愛車', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 4],
                                                ['type' => 'text', 'text' => $carDisplay, 'size' => 'xs', 'weight' => 'bold', 'color' => '#1e293b', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '🛢 オイル交換', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 4],
                                                ['type' => 'text', 'text' => $oilDisplay, 'size' => 'xs', 'weight' => 'bold', 'color' => '#f59e0b', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '📋 12ヶ月点検', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 4],
                                                ['type' => 'text', 'text' => $periodicDisplay, 'size' => 'xs', 'weight' => 'bold', 'color' => '#10b981', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '🚗 車検満了日', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 4],
                                                ['type' => 'text', 'text' => $inspDisplay, 'size' => 'xs', 'weight' => 'bold', 'color' => '#3b82f6', 'flex' => 6]
                                            ]
                                        ]
                                    ]
                                ],
                                [
                                    'type' => 'text',
                                    'text' => "※予定日の変更・2台目以降の登録は、リッチメニューよりいつでも行えます。",
                                    'size' => 'xxs',
                                    'color' => '#64748b',
                                    'margin' => 'md',
                                    'wrap' => true
                                ]
                            ]
                        ]
                    ]
                ];

                require_once __DIR__ . '/webhook.php';
                $confirmFlex['quickReply'] = getQuickReplyItems();

                try {
                    sendLinePushMessage($userId, [$confirmFlex]);
                } catch (Exception $pushErr) {
                    writeDebugLog("LINE Push送信エラー (保存自体は成功)", ['error' => $pushErr->getMessage()]);
                }
            }

            echo json_encode([
                'success' => true,
                'message' => '愛車のメンテナンス情報を保存しました！',
                'car_id' => $savedCarId,
                'customer' => [
                    'id' => $savedCarId,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'car_model' => $carModel,
                    'car_number' => $carNumber,
                    'oil_next_date' => $oilNextDate,
                    'periodic_insp_next_date' => $periodicInspNextDate,
                    'inspection_next_date' => $inspectionNextDate
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 6-2. ユーザー用: 指定愛車の削除 ---
        case 'delete_customer_car':
            $carId = (int)($_POST['car_id'] ?? 0);
            $userId = trim($_POST['uid'] ?? '');

            if (!$carId || !$userId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '車両IDとユーザーIDが必要です']);
                exit;
            }

            $stmt = $db->prepare("DELETE FROM customer_cars WHERE id = :car_id AND user_id = :uid");
            $stmt->execute([':car_id' => $carId, ':uid' => $userId]);

            echo json_encode(['success' => true, 'message' => '車両を削除しました']);
            break;

        // --- 6-3. ユーザー用: オイル・点検以外の来店希望・相談フォーム送信 ---
        case 'submit_general_inquiry':
            $userId = trim($_POST['uid'] ?? '');
            $userName = trim($_POST['uname'] ?? 'お客様');
            $carModel = trim($_POST['car_model'] ?? '愛車');
            $inquiryType = trim($_POST['inquiry_type'] ?? 'ご来店・ご相談');
            $preferredDate = trim($_POST['preferred_date'] ?? '未指定');
            $preferredTime = trim($_POST['preferred_time'] ?? 'いつでも');
            $details = trim($_POST['details'] ?? '');
            $needLoanCar = trim($_POST['need_loan_car'] ?? '不要');
            $phone = trim($_POST['phone'] ?? '');

            writeDebugLog("一般来店相談フォーム受付", [
                'uid' => $userId,
                'name' => $userName,
                'type' => $inquiryType,
                'car' => $carModel,
                'date' => $preferredDate,
                'time' => $preferredTime,
                'loan_car' => $needLoanCar,
                'details' => $details
            ]);

            // 1. Discord Webhookへ通知
            $webhookUrl = defined('DISCORD_WEBHOOK_URL') ? DISCORD_WEBHOOK_URL : '';
            if (!empty($webhookUrl)) {
                $discordPayload = [
                    'username' => 'アップファーレン 来店予約受付',
                    'avatar_url' => 'https://picture1.goo-net.com/shop/060/0601492/icon/0601492_icon_s.jpg',
                    'embeds' => [
                        [
                            'title' => "🛠️ 【来店・一般ご相談受付】{$inquiryType}",
                            'description' => "マイカー点検パスポートから新しいご来店予約・ご相談が届きました。",
                            'color' => 0xF59E0B, // オレンジ
                            'fields' => [
                                ['name' => '👤 お客様名', 'value' => "{$userName} 様", 'inline' => true],
                                ['name' => '🚗 愛車', 'value' => $carModel, 'inline' => true],
                                ['name' => '🏷️ ご用件', 'value' => $inquiryType, 'inline' => true],
                                ['name' => '📅 ご希望日時', 'value' => "{$preferredDate} ({$preferredTime})", 'inline' => true],
                                ['name' => '🚙 代車希望', 'value' => $needLoanCar, 'inline' => true],
                                ['name' => '📞 電話番号', 'value' => $phone ?: '未入力', 'inline' => true],
                                ['name' => '📝 ご相談・症状詳細', 'value' => $details ? "```\n" . mb_substr($details, 0, 950) . "\n```" : '特に指定なし', 'inline' => false],
                                ['name' => '🆔 LINE UserID', 'value' => "`{$userId}`", 'inline' => false],
                            ],
                            'footer' => ['text' => 'LINE Car Maintenance System'],
                            'timestamp' => date('c')
                        ]
                    ]
                ];

                $ch = curl_init($webhookUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($discordPayload, JSON_UNESCAPED_UNICODE),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                curl_exec($ch);
                curl_close($ch);
            }

            // 2. LINE Push送信（LINEユーザーの場合）
            if (str_starts_with($userId, 'U')) {
                $confirmFlex = [
                    'type' => 'flex',
                    'altText' => "【受付完了】{$inquiryType}のご来店予約・相談を承りました",
                    'contents' => [
                        'type' => 'bubble',
                        'size' => 'mega',
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'paddingAll' => '20px',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => '🛠️ ご来店予約・相談の受付完了',
                                    'weight' => 'bold',
                                    'size' => 'sm',
                                    'color' => '#06C755'
                                ],
                                [
                                    'type' => 'text',
                                    'text' => "{$userName} 様",
                                    'weight' => 'bold',
                                    'size' => 'xl',
                                    'margin' => 'sm',
                                    'color' => '#1e293b'
                                ],
                                [
                                    'type' => 'text',
                                    'text' => "以下の内容でご来店予約・ご相談を承りました！\n店舗スタッフが内容を確認し、LINEトークにて折り返し日程等のご連絡を差し上げます。",
                                    'size' => 'xs',
                                    'color' => '#475569',
                                    'margin' => 'sm',
                                    'wrap' => true
                                ],
                                [
                                    'type' => 'separator',
                                    'margin' => 'md'
                                ],
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'margin' => 'md',
                                    'spacing' => 'sm',
                                    'backgroundColor' => '#f8fafc',
                                    'paddingAll' => '12px',
                                    'cornerRadius' => 'md',
                                    'contents' => [
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => 'ご用件', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => $inquiryType, 'size' => 'xs', 'weight' => 'bold', 'color' => '#1e293b', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '対象車両', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => $carModel, 'size' => 'xs', 'color' => '#1e293b', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '希望日時', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => "{$preferredDate} ({$preferredTime})", 'size' => 'xs', 'color' => '#e02424', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '代車希望', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => $needLoanCar, 'size' => 'xs', 'color' => '#1e293b', 'flex' => 6]
                                            ]
                                        ]
                                    ]
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $details ? "【相談内容】\n" . $details : "※何か追加のご要望やお急ぎの用件がございましたら、このままトークにメッセージをお送りください。",
                                    'size' => 'xxs',
                                    'color' => '#64748b',
                                    'margin' => 'md',
                                    'wrap' => true
                                ]
                            ]
                        ]
                    ]
                ];

                require_once __DIR__ . '/webhook.php';
                $confirmFlex['quickReply'] = getQuickReplyItems();

                try {
                    sendLinePushMessage($userId, [$confirmFlex]);
                } catch (Exception $pushErr) {
                    writeDebugLog("LINE Push送信エラー (相談受付自体は成功)", ['error' => $pushErr->getMessage()]);
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'ご来店予約・ご相談を承りました！スタッフより折り返しご連絡いたします。'
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 6. 豆知識・在庫検索等のPostbackアクションをLINEトークへPush送信 ---
        case 'trigger_postback':
            $userId = trim($_POST['uid'] ?? '');
            $postData = trim($_POST['data'] ?? '');

            if (!$userId || !str_starts_with($userId, 'U')) {
                echo json_encode(['success' => true, 'message' => 'ブラウザ環境のためPush送信をスキップしました']);
                break;
            }

            require_once __DIR__ . '/webhook.php';

            try {
                $res = executeSilentPostbackPush($db, $userId, $postData);
                if (is_array($res) && !empty($res['success'])) {
                    echo json_encode(['success' => true, 'message' => 'LINEトークに送信しました']);
                } else {
                    $errMsg = is_array($res) ? ($res['error'] ?: ($res['response'] ?: 'LINE送信エラー')) : '送信処理に失敗しました';
                    echo json_encode(['success' => false, 'error' => $errMsg, 'details' => $res]);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        // --- 7. 店舗管理者用: 顧客一覧取得 ---
        case 'admin_list_customers':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'パスワードが違います']);
                exit;
            }

            $search = trim($_GET['search'] ?? '');
            $filter = $_GET['filter'] ?? 'all'; // all, oil_soon, periodic_soon, inspection_soon
            $sort = $_GET['sort'] ?? 'last_interaction'; // last_interaction, insp_soon, oil_soon, periodic_soon, name_asc, created_desc, updated_desc

            $where = ["1 = 1"];
            $params = [];

            if (!empty($search)) {
                $where[] = "(user_name LIKE :s OR car_model LIKE :s OR car_number LIKE :s OR staff_memo LIKE :s)";
                $params[':s'] = "%{$search}%";
            }

            $today = date('Y-m-d');
            $in30days = date('Y-m-d', strtotime('+30 days'));

            $tagFilter = trim($_GET['tag'] ?? '');

            if ($filter === 'oil_soon') {
                $where[] = "oil_next_date IS NOT NULL AND oil_next_date <= :in30";
                $params[':in30'] = $in30days;
            } elseif ($filter === 'periodic_soon') {
                $where[] = "periodic_insp_next_date IS NOT NULL AND periodic_insp_next_date <= :in30";
                $params[':in30'] = $in30days;
            } elseif ($filter === 'inspection_soon') {
                $where[] = "inspection_next_date IS NOT NULL AND inspection_next_date <= :in30";
                $params[':in30'] = $in30days;
            } elseif ($filter === 'blocked') {
                $where[] = "is_blocked = 1";
            } elseif ($filter === 'active') {
                $where[] = "(is_blocked = 0 OR is_blocked IS NULL)";
            }

            if (!empty($tagFilter)) {
                $where[] = "tags LIKE :tag_f";
                $params[':tag_f'] = "%\"" . $tagFilter . "\"%";
            }

            // ソート順の判定
            $orderBy = "COALESCE(last_interaction_at, updated_at, created_at) DESC";
            if ($sort === 'insp_soon') {
                $orderBy = "CASE WHEN inspection_next_date IS NULL OR inspection_next_date = '' THEN 1 ELSE 0 END ASC, inspection_next_date ASC";
            } elseif ($sort === 'oil_soon') {
                $orderBy = "CASE WHEN oil_next_date IS NULL OR oil_next_date = '' THEN 1 ELSE 0 END ASC, oil_next_date ASC";
            } elseif ($sort === 'periodic_soon') {
                $orderBy = "CASE WHEN periodic_insp_next_date IS NULL OR periodic_insp_next_date = '' THEN 1 ELSE 0 END ASC, periodic_insp_next_date ASC";
            } elseif ($sort === 'name_asc') {
                $orderBy = "user_name ASC";
            } elseif ($sort === 'created_desc') {
                $orderBy = "created_at DESC";
            } elseif ($sort === 'updated_desc') {
                $orderBy = "updated_at DESC";
            }

            $whereSql = implode(' AND ', $where);
            $stmt = $db->prepare("SELECT * FROM customer_cars WHERE {$whereSql} ORDER BY {$orderBy}");
            $stmt->execute($params);
            $customers = $stmt->fetchAll();

            // 全リッチメニューのマップ
            $allMenusStmt = $db->query("SELECT id, title, line_menu_id, is_active, is_notice FROM rich_menus");
            $menuMap = [];
            $activeDefaultMenu = null;
            $latestNormalMenu = null;
            while ($rm = $allMenusStmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($rm['line_menu_id'])) {
                    $menuMap[$rm['line_menu_id']] = $rm;
                }
                if (empty($rm['is_notice'])) {
                    if ((int)$rm['is_active'] === 1 && !$activeDefaultMenu) {
                        $activeDefaultMenu = $rm;
                    }
                    if (!$latestNormalMenu) {
                        $latestNormalMenu = $rm;
                    }
                }
            }

            // 現在LINE公式アカウントに設定されているデフォルトリッチメニューIDを取得
            $currentLineDefaultId = lineGetDefaultRichMenuId();
            $defaultMenuTitle = '';

            // 1. LINE公式アカウントに現在適用されているメニューIDとDBの突き合わせ
            if (!empty($currentLineDefaultId) && isset($menuMap[$currentLineDefaultId])) {
                $defaultMenuTitle = trim($menuMap[$currentLineDefaultId]['title'] ?? '');
            }

            // 2. DB上に無ければLINE APIから直接メニュー名を取得
            if (empty($defaultMenuTitle) && !empty($currentLineDefaultId)) {
                $lineRemote = lineGetRichMenu($currentLineDefaultId);
                if (!empty($lineRemote['name'])) {
                    $defaultMenuTitle = trim($lineRemote['name']);
                }
            }

            // 3. DBの現在アクティブ通常メニュー
            if (empty($defaultMenuTitle) && $activeDefaultMenu) {
                $defaultMenuTitle = trim($activeDefaultMenu['title'] ?? '');
            }

            // 4. DBの最新通常メニュー
            if (empty($defaultMenuTitle) && $latestNormalMenu) {
                $defaultMenuTitle = trim($latestNormalMenu['title'] ?? '');
            }

            // 5. フォールバック
            if (empty($defaultMenuTitle) || $defaultMenuTitle === '全体共通メニュー') {
                $defaultMenuTitle = !empty($latestNormalMenu['title']) ? $latestNormalMenu['title'] : '通常メニュー';
            }

            foreach ($customers as &$c) {
                $customMenuId = $c['custom_line_menu_id'] ?? '';
                if (empty($customMenuId)) {
                    $c['current_menu_type'] = 'default';
                    $c['current_menu_name'] = $defaultMenuTitle;
                } elseif (!empty($c['custom_menu_text'])) {
                    $c['current_menu_type'] = 'custom_message';
                    $c['current_menu_name'] = '専用メッセージ中';
                } elseif (isset($menuMap[$customMenuId])) {
                    $c['current_menu_type'] = 'custom_assigned';
                    $c['current_menu_name'] = $menuMap[$customMenuId]['title'] ?? '個別指定メニュー';
                } else {
                    $c['current_menu_type'] = 'custom_assigned';
                    $c['current_menu_name'] = '個別メニュー';
                }

                // タグ配列の正規化
                $c['tags'] = normalizeTagList($c['tags'] ?? '');
                $c['tags_text'] = implode(', ', $c['tags']);

                // 最後のやり取り情報の補完
                $interactionAt = !empty($c['last_interaction_at']) ? $c['last_interaction_at'] : (!empty($c['updated_at']) ? $c['updated_at'] : ($c['created_at'] ?? ''));
                $c['last_interaction_at'] = $interactionAt;
                $c['last_interaction_display'] = !empty($interactionAt) ? date('Y/m/d H:i', strtotime($interactionAt)) : '未記録';
                $c['last_interaction_diff_text'] = formatTimeDiffText($interactionAt);
                if (empty($c['last_interaction_type'])) {
                    $c['last_interaction_type'] = 'follow';
                }
                if (empty($c['last_interaction_preview'])) {
                    $c['last_interaction_preview'] = '友だち登録';
                }
            }
            unset($c);

            // ブロック数・友だち数の全体集計
            $statStmt = $db->query("
                SELECT 
                    COUNT(*) as total_all,
                    SUM(CASE WHEN is_blocked = 1 THEN 1 ELSE 0 END) as blocked_cnt,
                    SUM(CASE WHEN is_blocked = 0 OR is_blocked IS NULL THEN 1 ELSE 0 END) as active_cnt
                FROM customer_cars
            ");
            $statRow = $statStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $totalActive = (int)($statRow['active_cnt'] ?? 0);
            $totalBlocked = (int)($statRow['blocked_cnt'] ?? 0);

            echo json_encode([
                'success' => true,
                'customers' => $customers,
                'total' => count($customers),
                'total_active' => $totalActive,
                'total_blocked' => $totalBlocked,
                'default_menu_title' => $defaultMenuTitle,
                'active_account' => $activeAccountKey,
                'active_account_name' => getAccountShopName($activeAccountKey),
                'custom_labels' => getAccountCustomLabels($activeAccountKey),
                'all_tags' => getAccountAllTags($db, $activeAccountKey)
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 8. 店舗管理者用: 顧客情報の登録・編集 ---
        case 'admin_save_customer':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $carId = !empty($_POST['car_id']) ? (int)$_POST['car_id'] : null;
            $userId = trim($_POST['uid'] ?? '');
            $userName = trim($_POST['uname'] ?? '');
            $carModel = trim($_POST['car_model'] ?? '');
            $carNumber = trim($_POST['car_number'] ?? '');
            $oilLastDate = !empty($_POST['oil_last_date']) ? $_POST['oil_last_date'] : null;
            $oilNextDate = !empty($_POST['oil_next_date']) ? $_POST['oil_next_date'] : null;
            $periodicInspNextDate = !empty($_POST['periodic_insp_next_date']) ? $_POST['periodic_insp_next_date'] : null;
            $inspectionNextDate = !empty($_POST['inspection_next_date']) ? $_POST['inspection_next_date'] : null;
            $staffMemo = trim($_POST['staff_memo'] ?? '');

            $rawTags = $_POST['tags'] ?? '';
            $tagsJson = encodeTagsForDb(normalizeTagList($rawTags));

            if (empty($userId)) {
                $userId = 'MANUAL_' . uniqid();
            }

            $nowJst = date('Y-m-d H:i:s');
            if ($carId) {
                $stmt = $db->prepare("
                    UPDATE customer_cars SET
                        user_name = :uname,
                        car_model = :car_model,
                        car_number = :car_number,
                        oil_last_date = :oil_last_date,
                        oil_next_date = :oil_next_date,
                        periodic_insp_next_date = :periodic_next_date,
                        inspection_next_date = :inspection_next_date,
                        staff_memo = :staff_memo,
                        tags = :tags,
                        updated_at = :updated_at
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $carId,
                    ':uname' => $userName,
                    ':car_model' => $carModel,
                    ':car_number' => $carNumber,
                    ':oil_last_date' => $oilLastDate,
                    ':oil_next_date' => $oilNextDate,
                    ':periodic_next_date' => $periodicInspNextDate,
                    ':inspection_next_date' => $inspectionNextDate,
                    ':staff_memo' => $staffMemo,
                    ':tags' => $tagsJson,
                    ':updated_at' => $nowJst
                ]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO customer_cars (
                        user_id, user_name, car_model, car_number,
                        oil_last_date, oil_next_date, periodic_insp_next_date, inspection_next_date,
                        staff_memo, tags, last_interaction_at, last_interaction_type, last_interaction_preview,
                        created_at, updated_at
                    ) VALUES (
                        :uid, :uname, :car_model, :car_number,
                        :oil_last_date, :oil_next_date, :periodic_next_date, :inspection_next_date,
                        :staff_memo, :tags, :now_jst1, 'follow', '手動登録',
                        :now_jst2, :now_jst3
                    )
                ");
                $stmt->execute([
                    ':uid' => $userId,
                    ':uname' => $userName,
                    ':car_model' => $carModel,
                    ':car_number' => $carNumber,
                    ':oil_last_date' => $oilLastDate,
                    ':oil_next_date' => $oilNextDate,
                    ':periodic_next_date' => $periodicInspNextDate,
                    ':inspection_next_date' => $inspectionNextDate,
                    ':staff_memo' => $staffMemo,
                    ':tags' => $tagsJson,
                    ':now_jst1' => $nowJst,
                    ':now_jst2' => $nowJst,
                    ':now_jst3' => $nowJst
                ]);
            }

            // タグに応じたリッチメニューを自動連動・反映
            $tagMenuRes = null;
            if (str_starts_with($userId, 'U')) {
                try {
                    $tagMenuRes = applyTagBasedRichMenuForUser($userId, $activeAccountKey, $db);
                } catch (Throwable $e) {}
            }

            echo json_encode([
                'success' => true,
                'message' => '顧客メンテナンス情報を保存しました！',
                'tag_menu_result' => $tagMenuRes
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 8-1-1. 店舗管理者用: 複数顧客の一括タグ操作 (追加/削除/上書き) ---
        case 'admin_bulk_update_tags':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $rawCustomerIds = $_POST['customer_ids'] ?? [];
            $customerIds = is_array($rawCustomerIds) ? $rawCustomerIds : (explode(',', (string)$rawCustomerIds) ?: []);
            $customerIds = array_filter(array_map('intval', $customerIds));

            if (empty($customerIds)) {
                echo json_encode(['success' => false, 'error' => '対象の顧客が選択されていません']);
                exit;
            }

            $mode = $_POST['mode'] ?? 'add'; // 'add' (追加), 'remove' (削除), 'replace' (上書き)
            $inputTags = normalizeTagList($_POST['tags'] ?? []);

            if (empty($inputTags) && $mode !== 'replace') {
                echo json_encode(['success' => false, 'error' => 'タグが指定されていません']);
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
            $stmt = $db->prepare("SELECT id, user_id, user_name, tags FROM customer_cars WHERE id IN ({$placeholders})");
            $stmt->execute($customerIds);
            $targetRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $updatedCount = 0;
            $nowJst = date('Y-m-d H:i:s');

            foreach ($targetRows as $row) {
                $currentTags = normalizeTagList($row['tags'] ?? '');
                $newTags = $currentTags;

                if ($mode === 'add') {
                    foreach ($inputTags as $it) {
                        if (!in_array($it, $newTags, true)) {
                            $newTags[] = $it;
                        }
                    }
                } elseif ($mode === 'remove') {
                    $newTags = array_values(array_diff($newTags, $inputTags));
                } elseif ($mode === 'replace') {
                    $newTags = $inputTags;
                }

                $newTagsJson = encodeTagsForDb($newTags);
                $updateStmt = $db->prepare("UPDATE customer_cars SET tags = :tags, updated_at = :now_jst WHERE id = :id");
                $updateStmt->execute([
                    ':tags' => $newTagsJson,
                    ':now_jst' => $nowJst,
                    ':id' => $row['id']
                ]);

                // タグ連動メニューを即時更新
                if (!empty($row['user_id']) && str_starts_with($row['user_id'], 'U')) {
                    applyTagBasedRichMenuForUser($row['user_id'], $activeAccountKey, $db);
                }
                $updatedCount++;
            }

            echo json_encode([
                'success' => true,
                'updated_count' => $updatedCount,
                'message' => "{$updatedCount}件の顧客のタグを更新し、リッチメニューを連動適用しました！",
                'all_tags' => getAccountAllTags($db, $activeAccountKey)
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 8-1-2. 店舗管理者用: 全タグ一覧取得 ---
        case 'admin_get_account_tags':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }
            echo json_encode([
                'success' => true,
                'tags' => getAccountAllTags($db, $activeAccountKey)
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 8-2-1. 店舗管理者用: LINE友だち全UIDリスト取得 (リアルタイム進捗同期用) ---
        case 'admin_get_sync_follower_ids':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $allUserIds = [];
            $next = null;
            $maxPages = 20; // 最大20,000人まで
            $page = 0;

            do {
                $page++;
                $res = getLineFollowerUserIds($next);
                if (!$res['success']) {
                    writeDebugLog("フォロワー一覧取得エラー", ['error' => $res['error'] ?? '']);
                    if (empty($allUserIds)) {
                        echo json_encode([
                            'success' => false,
                            'error' => 'LINE友だち一覧の取得に失敗しました: ' . ($res['error'] ?? 'LINE APIエラー')
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    break;
                }
                $ids = $res['userIds'] ?? [];
                $allUserIds = array_merge($allUserIds, $ids);
                $next = $res['next'] ?? null;
            } while (!empty($next) && $page < $maxPages);

            $allUserIds = array_values(array_unique($allUserIds));

            echo json_encode([
                'success' => true,
                'total' => count($allUserIds),
                'userIds' => $allUserIds,
                'account' => $activeAccountKey
            ], JSON_UNESCAPED_UNICODE);
            break;

        // --- 8-2-2. 店舗管理者用: LINE友だちバッチ同期 (リアルタイム進捗更新用・高速並列処理) ---
        case 'admin_sync_follower_batch':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $inputJson = file_get_contents('php://input');
            $inputData = json_decode($inputJson, true) ?? [];
            $batchIds = $inputData['userIds'] ?? $_POST['userIds'] ?? [];

            if (is_string($batchIds)) {
                $batchIds = json_decode($batchIds, true) ?: [$batchIds];
            }
            if (!is_array($batchIds) || empty($batchIds)) {
                echo json_encode(['success' => true, 'processed' => 0, 'imported' => 0, 'updated' => 0, 'names' => []]);
                exit;
            }

            // curl_multi でバッチ内のプロフィールを並列一括取得
            $profiles = getLineUserProfilesBatch($batchIds);

            $importedCount = 0;
            $updatedCount = 0;
            $processedNames = [];
            $nowJst = date('Y-m-d H:i:s');

            foreach ($batchIds as $uid) {
                $uid = trim((string)$uid);
                if (empty($uid) || !str_starts_with($uid, 'U')) continue;

                $checkStmt = $db->prepare("SELECT id, user_name, picture_url FROM customer_cars WHERE TRIM(user_id) = :uid LIMIT 1");
                $checkStmt->execute([':uid' => $uid]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                $prof = $profiles[$uid] ?? null;
                // 万一並列取得で取れなかった場合は単体取得フォールバック
                if (!$prof) {
                    $prof = getLineUserProfile($uid);
                }

                $displayName = !empty($prof['displayName']) ? $prof['displayName'] : 'LINE友だち';
                $pictureUrl = !empty($prof['pictureUrl']) ? $prof['pictureUrl'] : '';
                $processedNames[] = $displayName;

                if (!$existing) {
                    $insertStmt = $db->prepare("
                        INSERT INTO customer_cars (
                            user_id, user_name, picture_url, car_model, car_number,
                            is_blocked, blocked_at,
                            last_interaction_at, last_interaction_type, last_interaction_preview,
                            created_at, updated_at
                        ) VALUES (
                            :uid, :uname, :pic, '【未設定】受講コース未設定', '',
                            0, NULL,
                            :now_jst1, 'follow', '友だち登録',
                            :now_jst2, :now_jst3
                        )
                    ");
                    $insertStmt->execute([
                        ':uid' => $uid,
                        ':uname' => $displayName,
                        ':pic' => $pictureUrl,
                        ':now_jst1' => $nowJst,
                        ':now_jst2' => $nowJst,
                        ':now_jst3' => $nowJst
                    ]);
                    $importedCount++;
                } else {
                    $isPlaceholderName = empty($existing['user_name']) || in_array($existing['user_name'], ['新規お客様', 'お客様', 'LINE友だち', '受講生', '']);
                    $currentName = $isPlaceholderName ? $displayName : $existing['user_name'];
                    $currentPic = !empty($pictureUrl) ? $pictureUrl : ($existing['picture_url'] ?? '');

                    // 同一UIDの全レコードを確実に is_blocked = 0 に更新
                    $db->prepare("
                        UPDATE customer_cars SET
                            user_name = :uname,
                            picture_url = :pic,
                            is_blocked = 0,
                            blocked_at = NULL,
                            updated_at = :updated_at
                        WHERE TRIM(user_id) = :uid
                    ")->execute([
                        ':uname' => $currentName,
                        ':pic' => $currentPic,
                        ':updated_at' => $nowJst,
                        ':uid' => $uid
                    ]);
                    $updatedCount++;
                }
            }

            echo json_encode([
                'success' => true,
                'processed' => count($batchIds),
                'imported' => $importedCount,
                'updated' => $updatedCount,
                'names' => array_slice($processedNames, 0, 5)
            ], JSON_UNESCAPED_UNICODE);
            break;

        // --- 8-2-3. 店舗管理者用: LINEフォロワーリスト外の既存友だちをブロック中として自動整合同期 ---
        case 'admin_sync_reconcile_blocked':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $inputJson = file_get_contents('php://input');
            $inputData = json_decode($inputJson, true) ?? [];
            $activeUserIds = $inputData['activeUserIds'] ?? $_POST['activeUserIds'] ?? [];

            if (is_string($activeUserIds)) {
                $activeUserIds = json_decode($activeUserIds, true) ?: [$activeUserIds];
            }

            $nowJst = date('Y-m-d H:i:s');
            $blockedCount = 0;

            if (is_array($activeUserIds) && !empty($activeUserIds)) {
                $cleanActive = array_values(array_filter(array_map('trim', $activeUserIds)));

                // 1. 有効なフォロワー一覧に存在するものは is_blocked = 0, blocked_at = NULL
                $chunks = array_chunk($cleanActive, 300);
                foreach ($chunks as $c) {
                    $inSql = implode(',', array_fill(0, count($c), '?'));
                    $db->prepare("UPDATE customer_cars SET is_blocked = 0, blocked_at = NULL WHERE TRIM(user_id) IN ($inSql)")->execute($c);
                }

                // 2. 有効なフォロワー一覧に含まれない既存のLINE友だち（user_id LIKE 'U%'）を is_blocked = 1（ブロック中）に同期
                $dbUserIds = $db->query("SELECT DISTINCT TRIM(user_id) as uid FROM customer_cars WHERE user_id LIKE 'U%'")->fetchAll(PDO::FETCH_COLUMN);
                $activeLookup = array_flip($cleanActive);
                $toBlock = [];

                foreach ($dbUserIds as $dUid) {
                    $dUid = trim((string)$dUid);
                    if (!empty($dUid) && str_starts_with($dUid, 'U') && !isset($activeLookup[$dUid])) {
                        $toBlock[] = $dUid;
                    }
                }

                if (!empty($toBlock)) {
                    $blockChunks = array_chunk($toBlock, 300);
                    foreach ($blockChunks as $bChunk) {
                        $inSql = implode(',', array_fill(0, count($bChunk), '?'));
                        $stmt = $db->prepare("
                            UPDATE customer_cars 
                            SET is_blocked = 1,
                                blocked_at = COALESCE(blocked_at, '{$nowJst}'),
                                last_interaction_type = CASE WHEN is_blocked = 0 THEN 'unfollow' ELSE last_interaction_type END,
                                last_interaction_preview = CASE WHEN is_blocked = 0 THEN '🚫 ブロック' ELSE last_interaction_preview END,
                                updated_at = '{$nowJst}'
                            WHERE TRIM(user_id) IN ($inSql)
                        ");
                        $stmt->execute($bChunk);
                        $blockedCount += count($bChunk);
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'blocked_detected' => $blockedCount,
                'message' => "整合同期完了: ブロック中 {$blockedCount} 名を反映しました"
            ], JSON_UNESCAPED_UNICODE);
            break;

        // --- 8-2. 店舗管理者用: LINE既存友だちの一括同期・自動取り込み (レガシー一括実行) ---
        case 'admin_sync_line_followers':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            // LINE公式アカウントの全フォロワー（友だち）のIDをページングしながら全件取得
            $allUserIds = [];
            $next = null;
            $maxPages = 10; // 最大10,000人まで
            $page = 0;

            do {
                $page++;
                $res = getLineFollowerUserIds($next);
                if (!$res['success']) {
                    writeDebugLog("フォロワー一覧取得エラー", ['error' => $res['error'] ?? '']);
                    break;
                }
                $ids = $res['userIds'] ?? [];
                $allUserIds = array_merge($allUserIds, $ids);
                $next = $res['next'] ?? null;
            } while (!empty($next) && $page < $maxPages);

            $allUserIds = array_values(array_unique($allUserIds));

            $importedCount = 0;
            $updatedCount = 0;

            foreach ($allUserIds as $uid) {
                $checkStmt = $db->prepare("SELECT id, user_name, picture_url FROM customer_cars WHERE user_id = :uid LIMIT 1");
                $checkStmt->execute([':uid' => $uid]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                $prof = getLineUserProfile($uid);
                $displayName = !empty($prof['displayName']) ? $prof['displayName'] : 'LINE友だち';
                $pictureUrl = !empty($prof['pictureUrl']) ? $prof['pictureUrl'] : '';

                $nowJst = date('Y-m-d H:i:s');
                if (!$existing) {
                    $insertStmt = $db->prepare("
                        INSERT INTO customer_cars (
                            user_id, user_name, picture_url, car_model, car_number,
                            is_blocked, last_interaction_at, last_interaction_type, last_interaction_preview,
                            created_at, updated_at
                        ) VALUES (
                            :uid, :uname, :pic, '受講コース未設定', '',
                            0, :now_jst1, 'follow', '✨ 友だち登録',
                            :now_jst2, :now_jst3
                        )
                    ");
                    $insertStmt->execute([
                        ':uid' => $uid,
                        ':uname' => $displayName,
                        ':pic' => $pictureUrl,
                        ':now_jst1' => $nowJst,
                        ':now_jst2' => $nowJst,
                        ':now_jst3' => $nowJst
                    ]);
                    $importedCount++;
                } else {
                    // 既存顧客: アイコン画像を最新化し、仮名なら名前も最新表示名に同期。ブロック状態を解除 (0)
                    $isPlaceholderName = empty($existing['user_name']) || in_array($existing['user_name'], ['新規お客様', 'お客様', 'LINE友だち', '受講生', '']);
                    $currentName = $isPlaceholderName ? $displayName : $existing['user_name'];
                    $currentPic = !empty($pictureUrl) ? $pictureUrl : ($existing['picture_url'] ?? '');

                    $db->prepare("
                        UPDATE customer_cars SET
                            user_name = :uname,
                            picture_url = :pic,
                            is_blocked = 0,
                            blocked_at = NULL,
                            updated_at = :updated_at
                        WHERE id = :id
                    ")->execute([
                        ':uname' => $currentName,
                        ':pic' => $currentPic,
                        ':updated_at' => $nowJst,
                        ':id' => $existing['id']
                    ]);
                    $updatedCount++;
                }
            }

            // フォロワーリストに含まれない既存のLINE友だち（Uから始まるUID）をブロック中 (is_blocked = 1) に同期
            $blockedDetectedCount = 0;
            if (!empty($allUserIds)) {
                $placeholders = implode(',', array_fill(0, count($allUserIds), '?'));
                $blockStmt = $db->prepare("
                    UPDATE customer_cars
                    SET is_blocked = 1,
                        blocked_at = COALESCE(blocked_at, :now_jst),
                        last_interaction_preview = CASE WHEN is_blocked = 0 THEN '🚫 ブロック' ELSE last_interaction_preview END,
                        updated_at = :now_jst2
                    WHERE user_id LIKE 'U%'
                      AND user_id NOT IN ({$placeholders})
                      AND (is_blocked = 0 OR is_blocked IS NULL)
                ");
                $params = array_merge([$nowJst, $nowJst], $allUserIds);
                $blockStmt->execute($params);
                $blockedDetectedCount = $blockStmt->rowCount();
            }

            writeDebugLog("LINE既存友だち一括同期完了", [
                'totalFollowers' => count($allUserIds),
                'newImported' => $importedCount,
                'updated' => $updatedCount,
                'blockedDetected' => $blockedDetectedCount
            ]);

            $msg = "LINE友だち全" . count($allUserIds) . "名を同期しました！（新規登録: {$importedCount}名、同期更新: {$updatedCount}名";
            if ($blockedDetectedCount > 0) {
                $msg .= "、ブロック検知: {$blockedDetectedCount}名";
            }
            $msg .= "）";

            echo json_encode([
                'success' => true,
                'total_followers' => count($allUserIds),
                'imported_count' => $importedCount,
                'updated_count' => $updatedCount,
                'blocked_count' => $blockedDetectedCount,
                'message' => $msg
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 8-5. 店舗管理者用: ブロック状態の手動切替 (即時反映) ---
        case 'admin_toggle_block':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $carId  = !empty($input['car_id'])    ? (int)$input['car_id']         : (!empty($_POST['car_id']) ? (int)$_POST['car_id'] : null);
            $userId = trim($input['uid']        ?? $_POST['uid']        ?? '');
            $setBlocked = isset($input['is_blocked']) ? (int)(bool)$input['is_blocked']
                        : (isset($_POST['is_blocked'])  ? (int)(bool)$_POST['is_blocked'] : null);

            if ($setBlocked === null) {
                echo json_encode(['success' => false, 'error' => 'is_blocked パラメータが必要です']);
                break;
            }
            if (!$carId && empty($userId)) {
                echo json_encode(['success' => false, 'error' => 'car_id または uid が必要です']);
                break;
            }

            // もし $userId が空で $carId がある場合は、user_id を取得
            if (empty($userId) && $carId) {
                $cStmt = $db->prepare("SELECT user_id FROM customer_cars WHERE id = :id LIMIT 1");
                $cStmt->execute([':id' => $carId]);
                $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                $userId = trim((string)($cRow['user_id'] ?? ''));
            }

            $nowJst = date('Y-m-d H:i:s');
            $preview = $setBlocked ? '🚫 ブロック（手動）' : '✅ ブロック解除（手動）';
            $lType = $setBlocked ? 'unfollow' : 'follow';
            $blockedAt = $setBlocked ? $nowJst : null;

            if (!empty($userId) && str_starts_with($userId, 'U')) {
                $stmt = $db->prepare("
                    UPDATE customer_cars SET
                        is_blocked = :blocked,
                        blocked_at = :blocked_at,
                        last_interaction_type = :ltype,
                        last_interaction_preview = :preview,
                        last_interaction_at = :now1,
                        updated_at = :now2
                    WHERE TRIM(user_id) = :uid
                ");
                $stmt->execute([
                    ':blocked' => $setBlocked,
                    ':blocked_at' => $blockedAt,
                    ':ltype' => $lType,
                    ':preview' => $preview,
                    ':now1' => $nowJst,
                    ':now2' => $nowJst,
                    ':uid' => $userId
                ]);
                $affected = $stmt->rowCount();
            } else {
                $stmt = $db->prepare("
                    UPDATE customer_cars SET
                        is_blocked = :blocked,
                        blocked_at = :blocked_at,
                        last_interaction_type = :ltype,
                        last_interaction_preview = :preview,
                        last_interaction_at = :now1,
                        updated_at = :now2
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':blocked' => $setBlocked,
                    ':blocked_at' => $blockedAt,
                    ':ltype' => $lType,
                    ':preview' => $preview,
                    ':now1' => $nowJst,
                    ':now2' => $nowJst,
                    ':id' => $carId
                ]);
                $affected = $stmt->rowCount();
            }

            echo json_encode([
                'success'    => true,
                'is_blocked' => $setBlocked,
                'affected'   => $affected,
                'message'    => $setBlocked ? 'ブロック中に設定しました' : 'ブロック解除しました'
            ], JSON_UNESCAPED_UNICODE);
            break;

        // --- 9. 店舗管理者用: 顧客・車両削除 ---
        case 'admin_delete_customer':

            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $carId = !empty($_POST['car_id']) ? (int)$_POST['car_id'] : null;
            $userId = trim($_POST['uid'] ?? '');

            writeDebugLog("店舗管理者 顧客・車両削除実行", ['car_id' => $carId, 'uid' => $userId]);

            if ($carId) {
                $stmt = $db->prepare("DELETE FROM customer_cars WHERE id = :id");
                $stmt->execute([':id' => $carId]);
            } elseif (!empty($userId)) {
                $stmt = $db->prepare("DELETE FROM customer_cars WHERE user_id = :uid");
                $stmt->execute([':uid' => $userId]);
            }

            // 旧 customers テーブルが存在していればそちらからも安全に削除
            if (!empty($userId)) {
                try {
                    $stmtLegacy = $db->prepare("DELETE FROM customers WHERE user_id = :uid");
                    $stmtLegacy->execute([':uid' => $userId]);
                } catch (Exception $e) {}
            }

            echo json_encode(['success' => true, 'message' => '削除しました'], JSON_UNESCAPED_UNICODE);
            break;

        // --- 10. 店舗管理者用: 個別手動リマインドLINE送信 ---
        case 'admin_send_reminder':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $carId = !empty($_POST['car_id']) ? (int)$_POST['car_id'] : null;
            $userId = $_POST['uid'] ?? '';
            $type = $_POST['type'] ?? 'oil'; // oil, periodic, or inspection (shaken)

            if (empty($userId) && empty($carId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDまたは車両IDが必要です']);
                exit;
            }

            if ($carId) {
                $stmt = $db->prepare("SELECT * FROM customer_cars WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $carId]);
            } else {
                $stmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid LIMIT 1");
                $stmt->execute([':uid' => $userId]);
            }
            $cust = $stmt->fetch();

            if (!$cust) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '顧客・車両情報が見つかりませんでした']);
                exit;
            }

            $userId = $cust['user_id'];
            if (!str_starts_with($userId, 'U')) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'この顧客は手動登録（LINE未連携）のため、LINE送信できません']);
                exit;
            }

            $userName = $cust['user_name'] ?: '受講生';
            $courseName = $cust['car_model'] ?: '受講コース';
            $deviceInfo = $cust['car_number'] ?: '登録機器';

            if ($type === 'oil') {
                $lessonDate = $cust['oil_next_date'] ?: '近日中';
                $flexMessage = [
                    'type' => 'flex',
                    'altText' => "【次回レッスンのご案内】{$courseName}の受講予定日のお知らせ",
                    'contents' => [
                        'type' => 'bubble',
                        'size' => 'mega',
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'paddingAll' => '20px',
                            'contents' => [
                                ['type' => 'text', 'text' => '💻 次回レッスンのご案内', 'weight' => 'bold', 'size' => 'sm', 'color' => '#0284c7'],
                                ['type' => 'text', 'text' => "{$userName} 様", 'weight' => 'bold', 'size' => 'xl', 'margin' => 'sm', 'color' => '#1e293b'],
                                ['type' => 'text', 'text' => "いつも【" . SHOP_NAME . "】をご利用いただきありがとうございます！\n次回レッスンの予定日をお知らせいたします。", 'size' => 'xs', 'color' => '#475569', 'margin' => 'sm', 'wrap' => true],
                                ['type' => 'separator', 'margin' => 'md'],
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'margin' => 'md',
                                    'spacing' => 'sm',
                                    'backgroundColor' => '#f0f9ff',
                                    'paddingAll' => '12px',
                                    'cornerRadius' => 'md',
                                    'contents' => [
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '受講コース', 'color' => '#0369a1', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => $courseName, 'size' => 'xs', 'weight' => 'bold', 'color' => '#0f172a', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '次回レッスン日', 'color' => '#0369a1', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => $lessonDate, 'size' => 'xs', 'weight' => 'bold', 'color' => '#e02424', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '持ち物', 'color' => '#0369a1', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => "筆記用具・使用機器（ノートPC/スマホなど）", 'size' => 'xs', 'color' => '#475569', 'flex' => 6]
                                            ]
                                        ]
                                    ]
                                ],
                                ['type' => 'text', 'text' => "※ご都合が悪くなった場合の日程変更やご相談は、下のボタンよりお気軽にご連絡くださいませ。", 'size' => 'xxs', 'color' => '#64748b', 'margin' => 'md', 'wrap' => true]
                            ]
                        ],
                        'footer' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'spacing' => 'sm',
                            'paddingAll' => '14px',
                            'contents' => [
                                [
                                    'type' => 'button',
                                    'style' => 'primary',
                                    'color' => '#0284c7',
                                    'height' => 'sm',
                                    'action' => [
                                        'type' => 'uri',
                                        'label' => '📅 レッスン予約・日程変更',
                                        'uri' => defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
            } elseif ($type === 'periodic') {
                $diagDate = $cust['periodic_insp_next_date'] ?: '近日中';
                $flexMessage = [
                    'type' => 'flex',
                    'altText' => "【定期PC健康診断のお知らせ】パソコン・スマホの点検時期です",
                    'contents' => [
                        'type' => 'bubble',
                        'size' => 'mega',
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'paddingAll' => '20px',
                            'contents' => [
                                ['type' => 'text', 'text' => '🔍 定期パソコン健康診断のご案内', 'weight' => 'bold', 'size' => 'sm', 'color' => '#10b981'],
                                ['type' => 'text', 'text' => "{$userName} 様", 'weight' => 'bold', 'size' => 'xl', 'margin' => 'sm', 'color' => '#1e293b'],
                                ['type' => 'text', 'text' => "いつも【" . SHOP_NAME . "】をご利用いただきありがとうございます！\n定期的なパソコン・スマホの健康診断・セキュリティ点検のご案内です。", 'size' => 'xs', 'color' => '#475569', 'margin' => 'sm', 'wrap' => true],
                                ['type' => 'separator', 'margin' => 'md'],
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'margin' => 'md',
                                    'spacing' => 'sm',
                                    'backgroundColor' => '#ecfdf5',
                                    'paddingAll' => '12px',
                                    'cornerRadius' => 'md',
                                    'contents' => [
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '対象機器', 'color' => '#047857', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => ($deviceInfo ?: 'ご利用端末'), 'size' => 'xs', 'weight' => 'bold', 'color' => '#0f172a', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '点検推奨日', 'color' => '#047857', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => $diagDate, 'size' => 'xs', 'weight' => 'bold', 'color' => '#e02424', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '点検内容', 'color' => '#047857', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => "動作改善・ウイルス対策・OS更新チェック", 'size' => 'xs', 'color' => '#475569', 'flex' => 6]
                                            ]
                                        ]
                                    ]
                                ],
                                ['type' => 'text', 'text' => "「最近パソコンが重い」「怪しい警告画面が出る」「容量がいっぱい」などのお悩みも教室スタッフにお気軽にご相談ください！", 'size' => 'xxs', 'color' => '#64748b', 'margin' => 'md', 'wrap' => true]
                            ]
                        ],
                        'footer' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'spacing' => 'sm',
                            'paddingAll' => '14px',
                            'contents' => [
                                [
                                    'type' => 'button',
                                    'style' => 'primary',
                                    'color' => '#10b981',
                                    'height' => 'sm',
                                    'action' => [
                                        'type' => 'postback',
                                        'label' => '🛠 パソコン診断の予約・相談',
                                        'data' => 'action=ask_class&type=diagnosis&device=' . urlencode($deviceInfo) . '&date=' . urlencode($diagDate)
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
            } else {
                $renewDate = $cust['inspection_next_date'] ?: '未定';
                $flexMessage = [
                    'type' => 'flex',
                    'altText' => "【受講・会員更新のお知らせ】月謝・会員期限のご案内",
                    'contents' => [
                        'type' => 'bubble',
                        'size' => 'mega',
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'paddingAll' => '20px',
                            'contents' => [
                                ['type' => 'text', 'text' => '🗓️ 会員更新・月謝期日のご案内', 'weight' => 'bold', 'size' => 'sm', 'color' => '#f59e0b'],
                                ['type' => 'text', 'text' => "{$userName} 様", 'weight' => 'bold', 'size' => 'xl', 'margin' => 'sm', 'color' => '#1e293b'],
                                ['type' => 'text', 'text' => "いつも【" . SHOP_NAME . "】をご愛顧いただき誠にありがとうございます。\n受講プラン・会員有効期限（月謝）のお知らせです。", 'size' => 'xs', 'color' => '#475569', 'margin' => 'sm', 'wrap' => true],
                                ['type' => 'separator', 'margin' => 'md'],
                                [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'margin' => 'md',
                                    'spacing' => 'sm',
                                    'backgroundColor' => '#fffbeb',
                                    'paddingAll' => '12px',
                                    'cornerRadius' => 'md',
                                    'contents' => [
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '受講プラン', 'color' => '#b45309', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => $courseName, 'size' => 'xs', 'weight' => 'bold', 'color' => '#1e293b', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '更新・期日', 'color' => '#b45309', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => $renewDate, 'size' => 'xs', 'weight' => 'bold', 'color' => '#e02424', 'flex' => 6]
                                            ]
                                        ]
                                    ]
                                ],
                                ['type' => 'text', 'text' => "コース変更や受講回数の追加、ご不明な点がございましたら教室受付またはLINEトークよりお気軽にお問い合わせください。", 'size' => 'xxs', 'color' => '#64748b', 'margin' => 'md', 'wrap' => true]
                            ]
                        ],
                        'footer' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'spacing' => 'sm',
                            'paddingAll' => '14px',
                            'contents' => [
                                [
                                    'type' => 'button',
                                    'style' => 'primary',
                                    'color' => '#f59e0b',
                                    'height' => 'sm',
                                    'action' => [
                                        'type' => 'postback',
                                        'label' => '💬 コース・更新について相談',
                                        'data' => 'action=ask_class&type=renew&course=' . urlencode($courseName) . '&date=' . urlencode($renewDate)
                                    ]
                                ]
                            ]
                        ]
                    ]
                ];
            }

            $res = sendLinePushMessage($userId, [$flexMessage]);
            if (!empty($res['success'])) {
                $remindLabel = ($type === 'oil') ? '次回レッスン' : (($type === 'periodic') ? '定期PC診断' : '会員更新');
                if ($carId) {
                    if ($type === 'oil') {
                        $db->prepare("UPDATE customer_cars SET oil_reminded_at = datetime('now', '+9 hours') WHERE id = :id")->execute([':id' => $carId]);
                    } elseif ($type === 'periodic') {
                        $db->prepare("UPDATE customer_cars SET periodic_reminded_at = datetime('now', '+9 hours') WHERE id = :id")->execute([':id' => $carId]);
                    } else {
                        $db->prepare("UPDATE customer_cars SET inspection_reminded_at = datetime('now', '+9 hours') WHERE id = :id")->execute([':id' => $carId]);
                    }
                } else {
                    if ($type === 'oil') {
                        $db->prepare("UPDATE customer_cars SET oil_reminded_at = datetime('now', '+9 hours') WHERE user_id = :uid")->execute([':uid' => $userId]);
                    } elseif ($type === 'periodic') {
                        $db->prepare("UPDATE customer_cars SET periodic_reminded_at = datetime('now', '+9 hours') WHERE user_id = :uid")->execute([':uid' => $userId]);
                    } else {
                        $db->prepare("UPDATE customer_cars SET inspection_reminded_at = datetime('now', '+9 hours') WHERE user_id = :uid")->execute([':uid' => $userId]);
                    }
                }
                recordCustomerInteraction($db, $userId, 'admin_reminder', "{$remindLabel}リマインド送信: {$courseName}", $carId);
                echo json_encode(['success' => true, 'message' => "{$userName} 様へLINEリマインドを送信しました！"], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => "LINE送信失敗: " . ($res['error'] ?? 'APIエラー')], JSON_UNESCAPED_UNICODE);
            }
            break;

        // --- 10-2. シニア向けスマホ・PC役立つ情報 リッチメッセージ配信 ---
        case 'admin_send_knowledge_message':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetType = trim($_POST['target_type'] ?? 'all'); // 'all' (一斉配信) or 'user' (個別送信)
            $userId = trim($_POST['uid'] ?? '');
            $title = trim($_POST['title'] ?? 'シニア向けお役立ち情報');
            $subtitle = trim($_POST['subtitle'] ?? '');
            $category = trim($_POST['category'] ?? 'スマホ・パソコンお役立ち');
            $pointsJson = $_POST['points'] ?? '[]';
            $points = is_array($pointsJson) ? $pointsJson : (json_decode($pointsJson, true) ?: []);
            $advice = trim($_POST['advice'] ?? '');
            $btn1Label = trim($_POST['btn1_label'] ?? '📅 教室で直接相談・予約する');
            $defaultBookingUrl = defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1';
            $btn1Url = trim($_POST['btn1_url'] ?? $defaultBookingUrl);
            if (empty($btn1Url) || str_contains($btn1Url, 'fsmk.co')) {
                $btn1Url = $defaultBookingUrl;
            }
            $btn2Label = trim($_POST['btn2_label'] ?? '💬 LINEで質問・相談する');
            $badgeColor = trim($_POST['badge_color'] ?? '#4f46e5');

            if (empty($title)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'タイトルを入力してください']);
                exit;
            }

            if ($targetType === 'user') {
                if (empty($userId) || str_starts_with($userId, 'MANUAL_')) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'LINE未連携の受講生には送信できません']);
                    exit;
                }
            }

            // ポイント一覧のFlex Box作成
            $pointBoxContents = [];
            $numIcons = ['①', '②', '③', '④', '⑤'];
            foreach ($points as $idx => $pt) {
                $ptText = trim((string)$pt);
                if (empty($ptText)) continue;
                $icon = $numIcons[$idx] ?? '・';
                $item = [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'spacing' => 'sm',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => $icon,
                            'weight' => 'bold',
                            'size' => 'sm',
                            'color' => $badgeColor,
                            'flex' => 1
                        ],
                        [
                            'type' => 'text',
                            'text' => $ptText,
                            'size' => 'sm',
                            'color' => '#1e293b',
                            'wrap' => true,
                            'weight' => 'bold',
                            'flex' => 11
                        ]
                    ]
                ];
                if ($idx > 0) {
                    $item['margin'] = 'md';
                }
                $pointBoxContents[] = $item;
            }

            // Flex Message 本体の構築（シニアに優しい大文字・高コントラスト設計）
            $bodyContents = [
                // 送信アナウンスヘッダー（お役立ち情報をお送りします！）
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '📢 お役立ち情報をお送りします！',
                            'weight' => 'bold',
                            'size' => 'xs',
                            'color' => '#0284c7'
                        ]
                    ],
                    'margin' => 'none',
                    'paddingBottom' => '6px'
                ],
                // 大見出しタイトル
                [
                    'type' => 'text',
                    'text' => $title,
                    'weight' => 'bold',
                    'size' => 'lg',
                    'margin' => 'sm',
                    'color' => '#0f172a',
                    'wrap' => true
                ]
            ];

            // サブタイトル / 要約
            if (!empty($subtitle)) {
                $bodyContents[] = [
                    'type' => 'text',
                    'text' => $subtitle,
                    'size' => 'xs',
                    'color' => '#475569',
                    'margin' => 'sm',
                    'wrap' => true
                ];
            }

            $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];

            // 要点まとめボックス（3つのポイント）
            if (!empty($pointBoxContents)) {
                $bodyContents[] = [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'margin' => 'md',
                    'backgroundColor' => '#f8fafc',
                    'paddingAll' => '14px',
                    'cornerRadius' => 'lg',
                    'borderColor' => '#e2e8f0',
                    'borderWidth' => '1px',
                    'contents' => array_merge([
                        [
                            'type' => 'text',
                            'text' => '【覚えておきたいポイント】',
                            'weight' => 'bold',
                            'size' => 'xs',
                            'color' => '#64748b'
                        ],
                        [
                            'type' => 'separator',
                            'margin' => 'sm'
                        ]
                    ], $pointBoxContents)
                ];
            }

            // 先生からのワンポイントアドバイス
            if (!empty($advice)) {
                $bodyContents[] = [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'margin' => 'md',
                    'backgroundColor' => '#fffbeb',
                    'paddingAll' => '12px',
                    'cornerRadius' => 'md',
                    'borderColor' => '#fef3c7',
                    'borderWidth' => '1px',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '👩‍🏫 教室スタッフからのアドバイス',
                            'weight' => 'bold',
                            'size' => 'xs',
                            'color' => '#b45309'
                        ],
                        [
                            'type' => 'text',
                            'text' => $advice,
                            'size' => 'xs',
                            'color' => '#78350f',
                            'wrap' => true,
                            'margin' => 'xs'
                        ]
                    ]
                ];
            }

            $bodyContents[] = [
                'type' => 'text',
                'text' => '※わからない操作や気になる点はお気軽に教室でお尋ねください。',
                'size' => 'xxs',
                'color' => '#94a3b8',
                'margin' => 'md',
                'wrap' => true
            ];

            // フッターアクションボタン
            $footerButtons = [];
            if (!empty($btn1Label)) {
                $footerButtons[] = [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => $badgeColor,
                    'height' => 'sm',
                    'action' => [
                        'type' => 'uri',
                        'label' => mb_substr($btn1Label, 0, 20),
                        'uri' => !empty($btn1Url) ? $btn1Url : (defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1')
                    ]
                ];
            }

            // その他のお役立ち情報を見るボタン（全テーマクイックリプライ呼出・サイレント送信）
            $footerButtons[] = [
                'type' => 'button',
                'style' => 'secondary',
                'height' => 'sm',
                'action' => [
                    'type' => 'postback',
                    'label' => '📚 その他のお役立ち情報',
                    'data' => 'action=show_knowledge_menu'
                ]
            ];

            if (!empty($btn2Label)) {
                // LINE postback action の data は最大300バイト制限（URLエンコード後）
                $shortTopic = mb_substr($title, 0, 15);
                $encodedTopic = urlencode($shortTopic);
                while (strlen("action=ask_class&topic={$encodedTopic}") > 250 && mb_strlen($shortTopic) > 0) {
                    $shortTopic = mb_substr($shortTopic, 0, -1);
                    $encodedTopic = urlencode($shortTopic);
                }
                $footerButtons[] = [
                    'type' => 'button',
                    'style' => 'secondary',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => mb_substr($btn2Label, 0, 20),
                        'data' => "action=ask_class&topic={$encodedTopic}"
                    ]
                ];
            }

            $bubble = [
                'type' => 'bubble',
                'size' => 'mega',
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'paddingAll' => '18px',
                    'contents' => $bodyContents
                ]
            ];
            if (!empty($footerButtons)) {
                $bubble['footer'] = [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'paddingAll' => '14px',
                    'contents' => $footerButtons
                ];
            }

            $flexMessage = [
                'type' => 'flex',
                'altText' => mb_substr("お役立ち情報をお送りします！【{$title}】", 0, 400),
                'contents' => $bubble
            ];
            $activeKey = getActiveAccountKey();
            $accConfig = getAccountConfig($activeKey);
            $indType = strtolower($accConfig['industry_type'] ?? 'senior');
            if (($indType === 'senior' || $activeKey === 'senior') && function_exists('getSeniorKnowledgeQuickReplyItems')) {
                $flexMessage['quickReply'] = getSeniorKnowledgeQuickReplyItems();
            }

            if ($targetType === 'all') {
                // LINE公式アカウント友だち全員へ一斉配信 (Broadcast)
                $res = sendLineBroadcastMessage([$flexMessage]);
                if (!empty($res['success'])) {
                    writeDebugLog("お役立ち情報一斉配信成功", ['title' => $title]);
                    echo json_encode([
                        'success' => true,
                        'message' => "LINE公式アカウントの友だち全員へ「{$title}」を一斉配信しました！"
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                } else {
                    $errMsg = !empty($res['error']) ? $res['error'] : (!empty($res['response']) ? $res['response'] : 'APIエラー');
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'error' => "一斉配信失敗: " . $errMsg
                    ], JSON_UNESCAPED_UNICODE);
                }
            } else {
                // 特定受講生への個別送信 (Push)
                $res = sendLinePushMessage($userId, [$flexMessage]);
                if (!empty($res['success'])) {
                    recordCustomerInteraction($db, $userId, 'knowledge_send', "お役立ち情報個別送信: {$title}");
                    echo json_encode([
                        'success' => true,
                        'message' => "指定された受講生へ「{$title}」を送信しました！"
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                } else {
                    $errMsg = !empty($res['error']) ? $res['error'] : (!empty($res['response']) ? $res['response'] : 'APIエラー');
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'error' => "送信失敗: " . $errMsg
                    ], JSON_UNESCAPED_UNICODE);
                }
            }
            break;

        // --- 11-2. リッチメニュー画像配信 & LINE自動リカバリ ---
        case 'richmenu_image':
            $targetAccount = trim($_GET['account'] ?? ($_POST['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $targetDb->prepare("SELECT * FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                // 他アカウントのDBも探索フォールバック
                if ($targetAccount !== 'senior') {
                    $stmtSenior = getDbConnection('senior')->prepare("SELECT * FROM rich_menus WHERE id = :id");
                    $stmtSenior->execute([':id' => $id]);
                    $menu = $stmtSenior->fetch(PDO::FETCH_ASSOC);
                }
                if (!$menu) {
                    http_response_code(404);
                    exit('Rich menu not found');
                }
            }

            // ローカルファイル名を取得
            $imgFileName = basename(parse_url($menu['image_url'], PHP_URL_PATH) ?? '');
            if (empty($imgFileName) || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $imgFileName)) {
                $imgFileName = "rm_{$menu['id']}.jpg";
            }
            $localFilePath = RICHMENU_UPLOAD_DIR . '/' . $imgFileName;

            // 1. ローカルに画像ファイルが存在し中身があれば即座に配信
            if (file_exists($localFilePath) && filesize($localFilePath) > 0) {
                $ext = strtolower(pathinfo($localFilePath, PATHINFO_EXTENSION));
                $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
                header("Content-Type: {$mime}");
                header("Content-Length: " . filesize($localFilePath));
                header("Cache-Control: public, max-age=86400");
                readfile($localFilePath);
                exit;
            }

            // 2. ローカルにない場合、LINE Messaging API から画像バイナリを自動取得・キャッシュ復元
            if (!empty($menu['line_menu_id'])) {
                $imgBinary = lineGetRichMenuImage($menu['line_menu_id'], $targetAccount);
                if (!empty($imgBinary)) {
                    if (!is_dir(RICHMENU_UPLOAD_DIR)) {
                        @mkdir(RICHMENU_UPLOAD_DIR, 0777, true);
                    }
                    @file_put_contents($localFilePath, $imgBinary);
                    @chmod($localFilePath, 0666);

                    header("Content-Type: image/jpeg");
                    header("Content-Length: " . strlen($imgBinary));
                    header("Cache-Control: public, max-age=86400");
                    echo $imgBinary;
                    exit;
                }
            }

            // 3. LINE側にもない場合のフォールバック（SVGプレースホルダー）
            header("Content-Type: image/svg+xml");
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="540" viewBox="0 0 800 540"><rect width="800" height="540" fill="#f1f5f9"/><text x="400" y="270" font-family="sans-serif" font-size="28" fill="#94a3b8" text-anchor="middle" dominant-baseline="central">画像準備中</text></svg>';
            exit;

        // --- 11. リッチメニュー管理: 一覧取得 ---
        case 'admin_list_richmenus':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetAccount = trim($_GET['account'] ?? ($_POST['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);

            // LINE公式アカウントの現在のデフォルトリッチメニューIDを取得
            $currentLineDefaultId = lineGetDefaultRichMenuId($targetAccount);

            // 基本URLの定義
            $publicBase = getBaseUrl();

            // LINEサーバー上の全リッチメニューを自動取得し、プロライン等の未登録メニューがあれば自動インポート
            $remoteList = lineGetRichMenuList($targetAccount);
            $validRemoteLmids = [];
            if (!empty($remoteList['success']) && !empty($remoteList['richmenus'])) {
                foreach ($remoteList['richmenus'] as $rm) {
                    $lmid = $rm['richMenuId'] ?? '';
                    if (empty($lmid)) continue;
                    $validRemoteLmids[$lmid] = true;

                    // すでにDBに登録済みかチェック
                    $chk = $targetDb->prepare("SELECT id FROM rich_menus WHERE line_menu_id = :lmid LIMIT 1");
                    $chk->execute([':lmid' => $lmid]);
                    if (!$chk->fetch()) {
                        // LINEから画像バイナリを自動取得してローカル保存
                        $imgBin = lineGetRichMenuImage($lmid, $targetAccount);
                        $imgFileName = 'line_imported_' . substr(md5($lmid), 0, 10) . '.jpg';
                        $imgSaved = false;
                        if (!empty($imgBin)) {
                            if (!is_dir(RICHMENU_UPLOAD_DIR)) {
                                @mkdir(RICHMENU_UPLOAD_DIR, 0777, true);
                            }
                            $imgSaved = (@file_put_contents(RICHMENU_UPLOAD_DIR . '/' . $imgFileName, $imgBin) !== false);
                        }

                        $fullImgUrl = $imgSaved ? ($publicBase . '/uploads/richmenu/' . $imgFileName) : '';

                        $isDef = ($lmid === $currentLineDefaultId) ? 1 : 0;
                        $menuTitle = $rm['name'] ?? 'LINE公式メニュー';
                        if ($isDef) {
                            $menuTitle = '★ [現在LINE公開中] ' . $menuTitle;
                        }

                        $targetDb->prepare("
                            INSERT INTO rich_menus (
                                title, line_menu_id, image_url, base_image_url,
                                areas_json, width, height, chat_bar_text,
                                is_active, is_notice, created_at, updated_at
                            ) VALUES (
                                :title, :lmid, :img_url, :base_img_url,
                                :areas_json, :width, :height, :chat_bar_text,
                                :is_active, 0, datetime('now', '+9 hours'), datetime('now', '+9 hours')
                            )
                        ")->execute([
                            ':title' => $menuTitle,
                            ':lmid' => $lmid,
                            ':img_url' => $fullImgUrl,
                            ':base_img_url' => $fullImgUrl,
                            ':areas_json' => json_encode($rm['areas'] ?? [], JSON_UNESCAPED_UNICODE),
                            ':width' => $rm['size']['width'] ?? 2500,
                            ':height' => $rm['size']['height'] ?? 1686,
                            ':chat_bar_text' => $rm['chatBarText'] ?? 'メニュー',
                            ':is_active' => $isDef
                        ]);
                    }
                }
            }

            // 履歴一覧取得
            $stmt = $targetDb->query("SELECT * FROM rich_menus ORDER BY id DESC");
            $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 現在のLINE設定と同期
            foreach ($menus as &$m) {
                $rawAreas = json_decode($m['areas_json'], true) ?: [];
                $cleanAreas = [];
                foreach ($rawAreas as $idx => $ra) {
                    $ra['id'] = !empty($ra['id']) ? $ra['id'] : ($idx + 1);
                    $cleanAreas[] = $ra;
                }
                $m['areas'] = $cleanAreas;
                $m['text_overlays'] = !empty($m['text_overlays_json']) ? (json_decode($m['text_overlays_json'], true) ?: []) : [];
                $m['is_line_default'] = (!empty($m['line_menu_id']) && $m['line_menu_id'] === $currentLineDefaultId);
                $m['is_line_synced'] = (!empty($m['line_menu_id']) && (isset($validRemoteLmids[$m['line_menu_id']]) || $m['is_line_default']));

                // DBのis_activeとLINE実状態の整合性を取る
                if ($m['is_line_default'] && !$m['is_active']) {
                    $targetDb->prepare("UPDATE rich_menus SET is_active = 1 WHERE id = :id")->execute([':id' => $m['id']]);
                    $m['is_active'] = 1;
                } elseif (!$m['is_line_default'] && $m['is_active'] && !empty($currentLineDefaultId)) {
                    $targetDb->prepare("UPDATE rich_menus SET is_active = 0 WHERE id = :id")->execute([':id' => $m['id']]);
                    $m['is_active'] = 0;
                }
                // エイリアス未設定の既存メニューがあれば自動生成＆同期
                if (!empty($m['line_menu_id']) && empty($m['alias_id'])) {
                    $genAlias = 'rm_' . substr(md5($m['line_menu_id']), 0, 20);
                    $reg = lineCreateOrUpdateRichMenuAlias($m['line_menu_id'], $genAlias, $targetAccount);
                    if ($reg['success']) {
                        $targetDb->prepare("UPDATE rich_menus SET alias_id = :aid WHERE id = :id")->execute([':aid' => $genAlias, ':id' => $m['id']]);
                        $m['alias_id'] = $genAlias;
                    }
                }
                // 対象タグの配列化
                $m['target_tags'] = normalizeTagList($m['target_tags'] ?? '');
                $m['target_tags_text'] = implode(', ', $m['target_tags']);

                $m['is_notice'] = (int)($m['is_notice'] ?? 0);

                // 画像URLの完全正規化（フルURL化または動的配信フォールバック）
                $localFileName = basename(parse_url($m['image_url'] ?? '', PHP_URL_PATH) ?? '');
                $localFilePath = !empty($localFileName) ? (RICHMENU_UPLOAD_DIR . '/' . $localFileName) : '';
                if (!empty($localFilePath) && file_exists($localFilePath) && filesize($localFilePath) > 0) {
                    $m['image_url'] = $publicBase . '/uploads/richmenu/' . $localFileName;
                } else {
                    $m['image_url'] = '../api.php?action=richmenu_image&id=' . $m['id'] . '&account=' . urlencode($targetAccount);
                }

                if (!empty($m['base_image_url'])) {
                    $baseFileName = basename(parse_url($m['base_image_url'], PHP_URL_PATH) ?? '');
                    $baseFilePath = !empty($baseFileName) ? (RICHMENU_UPLOAD_DIR . '/' . $baseFileName) : '';
                    if (!empty($baseFilePath) && file_exists($baseFilePath) && filesize($baseFilePath) > 0) {
                        $m['base_image_url'] = $publicBase . '/uploads/richmenu/' . $baseFileName;
                    } else {
                        $m['base_image_url'] = $m['image_url'];
                    }
                } else {
                    $m['base_image_url'] = $m['image_url'];
                }
            }
            unset($m);

            // 現在有効なお知らせメニューを取得
            $activeNotice = getActiveNoticeRichMenu($targetDb, $targetAccount);
            $activeNoticeId = $activeNotice ? (int)$activeNotice['id'] : null;

            echo json_encode([
                'success' => true,
                'account' => $targetAccount,
                'menus' => $menus,
                'rich_menus' => $menus,
                'current_default_id' => $currentLineDefaultId,
                'active_notice_id' => $activeNoticeId
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 12. リッチメニュー管理: 作成 & 公開 ---
        case 'admin_save_richmenu':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetAccount = trim($_POST['account'] ?? ($_GET['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);

            $title = trim($_POST['title'] ?? '');
            if (empty($title)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'メニュー名（管理名）を入力してください']);
                exit;
            }

            $chatBarText = trim($_POST['chat_bar_text'] ?? 'メニュー');
            if (empty($chatBarText)) $chatBarText = 'メニュー';
            $chatBarText = mb_substr($chatBarText, 0, 14);

            $width = (int)($_POST['width'] ?? 2500);
            $height = (int)($_POST['height'] ?? 1686);
            if ($height !== 843 && $height !== 1686) $height = 1686;

            $areasJson = $_POST['areas'] ?? '[]';
            $areas = json_decode($areasJson, true);
            if (!is_array($areas) || empty($areas)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'タップ領域（エリア）が設定されていません']);
                exit;
            }

            $publish = (!empty($_POST['publish']) && $_POST['publish'] === '1');

            // 画像処理
            $uploadedFile = $_FILES['image'] ?? null;
            $existingImageUrl = trim($_POST['existing_image_url'] ?? '');
            $targetFilePath = '';
            $contentType = 'image/jpeg';

            if (!empty($uploadedFile) && $uploadedFile['error'] === UPLOAD_ERR_OK) {
                // 画像検証
                $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => '画像形式はJPGまたはPNGのみ対応しています']);
                    exit;
                }
                $contentType = ($ext === 'png') ? 'image/png' : 'image/jpeg';

                // ファイルサイズ確認 (1MB上限)
                if ($uploadedFile['size'] > 1048576 * 5) { // 5MB超は拒否
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => '画像サイズが大きすぎます (最大5MB)']);
                    exit;
                }

                $fileName = 'rm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'png' ? 'png' : 'jpg');
                $targetFilePath = RICHMENU_UPLOAD_DIR . '/' . $fileName;

                // GDでリサイズまたはそのまま保存
                $resized = false;
                if (function_exists('imagecreatefromstring') && function_exists('imagecopyresampled')) {
                    $srcData = file_get_contents($uploadedFile['tmp_name']);
                    $srcImg = @imagecreatefromstring($srcData);
                    if ($srcImg !== false) {
                        $origW = imagesx($srcImg);
                        $origH = imagesy($srcImg);
                        $dstImg = imagecreatetruecolor($width, $height);
                        if ($contentType === 'image/png') {
                            imagealphablending($dstImg, false);
                            imagesavealpha($dstImg, true);
                        }
                        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $width, $height, $origW, $origH);
                        if ($contentType === 'image/png') {
                            imagepng($dstImg, $targetFilePath);
                        } else {
                            imagejpeg($dstImg, $targetFilePath, 90);
                        }
                        imagedestroy($srcImg);
                        imagedestroy($dstImg);
                        $resized = true;
                    }
                }
                if (!$resized) {
                    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetFilePath)) {
                        http_response_code(500);
                        echo json_encode(['success' => false, 'error' => '画像ファイルの保存に失敗しました']);
                        exit;
                    }
                }
            } elseif (!empty($existingImageUrl)) {
                // 既存画像の流用
                $relPath = parse_url($existingImageUrl, PHP_URL_PATH);
                $localBase = basename($relPath);
                $candidatePath = RICHMENU_UPLOAD_DIR . '/' . $localBase;
                if (file_exists($candidatePath)) {
                    $targetFilePath = $candidatePath;
                    $ext = strtolower(pathinfo($candidatePath, PATHINFO_EXTENSION));
                    $contentType = ($ext === 'png') ? 'image/png' : 'image/jpeg';
                } else {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => '指定された元画像が見つかりません']);
                    exit;
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'メニュー画像ファイルをアップロードしてください']);
                exit;
            }

            // Web表示用URL
            $savedFileName = basename($targetFilePath);
            $imageUrl = getBaseUrl() . '/uploads/richmenu/' . $savedFileName;

            // クリーンな元画像（装飾テキストを焼き込んでいないベース画像）の保存処理
            $uploadedBaseFile = $_FILES['base_image'] ?? null;
            $existingBaseImageUrl = trim($_POST['existing_base_image_url'] ?? '');
            $baseImageUrl = '';

            if (!empty($uploadedBaseFile) && $uploadedBaseFile['error'] === UPLOAD_ERR_OK) {
                $bExt = strtolower(pathinfo($uploadedBaseFile['name'], PATHINFO_EXTENSION));
                if (in_array($bExt, ['jpg', 'jpeg', 'png']) && $uploadedBaseFile['size'] <= 1048576 * 5) {
                    $bFileName = 'base_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . ($bExt === 'png' ? 'png' : 'jpg');
                    $bTargetFilePath = RICHMENU_UPLOAD_DIR . '/' . $bFileName;
                    $bResized = false;
                    if (function_exists('imagecreatefromstring') && function_exists('imagecopyresampled')) {
                        $bSrcData = file_get_contents($uploadedBaseFile['tmp_name']);
                        $bSrcImg = @imagecreatefromstring($bSrcData);
                        if ($bSrcImg !== false) {
                            $bOrigW = imagesx($bSrcImg);
                            $bOrigH = imagesy($bSrcImg);
                            $bDstImg = imagecreatetruecolor($width, $height);
                            if ($bExt === 'png') {
                                imagealphablending($bDstImg, false);
                                imagesavealpha($bDstImg, true);
                                imagecopyresampled($bDstImg, $bSrcImg, 0, 0, 0, 0, $width, $height, $bOrigW, $bOrigH);
                                imagepng($bDstImg, $bTargetFilePath);
                            } else {
                                imagecopyresampled($bDstImg, $bSrcImg, 0, 0, 0, 0, $width, $height, $bOrigW, $bOrigH);
                                imagejpeg($bDstImg, $bTargetFilePath, 90);
                            }
                            imagedestroy($bSrcImg);
                            imagedestroy($bDstImg);
                            $baseImageUrl = getBaseUrl() . '/uploads/richmenu/' . $bFileName;
                            $bResized = true;
                        }
                    }
                    if (!$bResized) {
                        if (move_uploaded_file($uploadedBaseFile['tmp_name'], $bTargetFilePath)) {
                            $baseImageUrl = getBaseUrl() . '/uploads/richmenu/' . $bFileName;
                        }
                    }
                }
            } elseif (!empty($existingBaseImageUrl)) {
                $baseImageUrl = $existingBaseImageUrl;
            }

            if (empty($baseImageUrl)) {
                $baseImageUrl = $imageUrl;
            }

            // 1. LINE API用およびDB保存用メタデータ成形
            $lineAreas = [];
            $dbAreas = [];
            foreach ($areas as $idx => $a) {
                $areaId = !empty($a['id']) ? $a['id'] : ($idx + 1);
                $bounds = [
                    'x' => max(0, (int)($a['bounds']['x'] ?? 0)),
                    'y' => max(0, (int)($a['bounds']['y'] ?? 0)),
                    'width' => max(1, (int)($a['bounds']['width'] ?? 100)),
                    'height' => max(1, (int)($a['bounds']['height'] ?? 100))
                ];
                // 境界オーバー防止
                if ($bounds['x'] + $bounds['width'] > $width) {
                    $bounds['width'] = $width - $bounds['x'];
                }
                if ($bounds['y'] + $bounds['height'] > $height) {
                    $bounds['height'] = $height - $bounds['y'];
                }

                $actionType = $a['action']['type'] ?? 'uri';
                $action = ['type' => $actionType];
                if ($actionType === 'uri') {
                    $action['uri'] = trim($a['action']['uri'] ?? 'https://www.goo-net.com');
                } elseif ($actionType === 'postback') {
                    $action['data'] = trim($a['action']['data'] ?? 'action=search_all');
                    // 管理者側通知防止のため、明示的に指定された場合のみ displayText をセット（自動補完は行わない）
                    if (!empty($a['action']['displayText']) && trim($a['action']['displayText']) !== '') {
                        $action['displayText'] = trim($a['action']['displayText']);
                    }
                } elseif ($actionType === 'message') {
                    $action['text'] = trim($a['action']['text'] ?? 'メニュー');
                } elseif ($actionType === 'richmenuswitch') {
                    $aliasId = trim($a['action']['richMenuAliasId'] ?? '');
                    $action['richMenuAliasId'] = $aliasId;
                    $dataVal = trim($a['action']['data'] ?? '');
                    $branchCustom = !empty($a['action']['branchCustom']) || !empty($isNotice) || str_contains($dataVal, 'branch_custom=1');
                    
                    if (empty($dataVal) || $dataVal === 'action=richmenu_switched' || !str_contains($dataVal, 'to_alias=')) {
                        $dataVal = !empty($aliasId) ? 'action=richmenu_switched&to_alias=' . urlencode($aliasId) : 'action=richmenu_switched';
                    }
                    if ($branchCustom && !str_contains($dataVal, 'branch_custom=1')) {
                        $dataVal .= '&branch_custom=1';
                    }
                    if (!empty($isNotice) && !str_contains($dataVal, 'from_notice=1')) {
                        $dataVal .= '&from_notice=1';
                    }
                    $action['data'] = $dataVal;
                }

                $lineAreas[] = [
                    'bounds' => $bounds,
                    'action' => $action
                ];
                $dbAreas[] = [
                    'id' => $areaId,
                    'is_overlay' => !empty($a['is_overlay']),
                    'bounds' => $bounds,
                    'action' => $action
                ];
            }

            $lineMenuData = [
                'size' => [
                    'width' => $width,
                    'height' => $height
                ],
                'selected' => true,
                'name' => mb_substr($title, 0, 300),
                'chatBarText' => $chatBarText,
                'areas' => $lineAreas
            ];

            // 2. LINE API: リッチメニュー作成
            $createRes = lineCreateRichMenu($lineMenuData, $targetAccount);
            if (!$createRes['success'] || empty($createRes['richMenuId'])) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'LINEリッチメニュー作成失敗: ' . ($createRes['error'] ?? '不明なエラー'),
                    'detail' => $createRes['raw'] ?? ''
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $lineMenuId = $createRes['richMenuId'];

            // 3. LINE API: 画像アップロード
            $uploadRes = lineUploadRichMenuImage($lineMenuId, $targetFilePath, $contentType, $targetAccount);
            if (!$uploadRes['success']) {
                // ロールバック: 作成したリッチメニューを削除
                lineDeleteRichMenu($lineMenuId, $targetAccount);
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'LINEリッチメニュー画像アップロード失敗: ' . ($uploadRes['error'] ?? '不明なエラー')
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $editId = !empty($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
            $existingMenu = null;
            if ($editId > 0) {
                $stmtExist = $targetDb->prepare("SELECT * FROM rich_menus WHERE id = :id");
                $stmtExist->execute([':id' => $editId]);
                $existingMenu = $stmtExist->fetch(PDO::FETCH_ASSOC);
            }

            // 3.5 LINE API: エイリアス登録・更新
            // 既存メニューの編集なら、そのメニューの既存alias_idをそのまま引き継ぐ！
            // これにより、他メニューに設定された切替アクション（richmenuswitch）のエイリアスIDが一切壊れずシームレスに維持されます
            if ($existingMenu && !empty($existingMenu['alias_id'])) {
                $aliasId = $existingMenu['alias_id'];
            } else {
                $aliasId = 'rm_' . substr(md5($lineMenuId), 0, 20);
            }
            lineCreateOrUpdateRichMenuAlias($lineMenuId, $aliasId, $targetAccount);

            // 4. LINE API: 本番適用 (publishフラグが真の場合、または既存メニューが元々本番中の場合)
            $isNotice = (!empty($_POST['is_notice']) && $_POST['is_notice'] === '1') ? 1 : ($existingMenu ? (int)$existingMenu['is_notice'] : 0);
            $isActive = 0;
            $applyError = null;

            // 既存メニューが元々本番中だった場合は、更新時に自動で本番も最新メニューへ切り替え
            $shouldApplyLive = $publish || ($existingMenu && (int)$existingMenu['is_active'] === 1 && !$isNotice);

            if ($shouldApplyLive) {
                $setDefRes = lineSetDefaultRichMenu($lineMenuId, $targetAccount);
                if ($setDefRes['success']) {
                    $isActive = 1;
                    if ($isNotice) {
                        $targetDb->exec("UPDATE rich_menus SET is_active = 0 WHERE is_notice = 1");
                    } else {
                        $targetDb->exec("UPDATE rich_menus SET is_active = 0 WHERE is_notice = 0");
                    }
                } else {
                    $applyError = $setDefRes['error'] ?? '不明なエラー';
                }
            } elseif ($isNotice) {
                // お知らせ専用メニューとして保存された場合、アクティブお知らせとしてマーク
                $isActive = 1;
                $targetDb->exec("UPDATE rich_menus SET is_active = 0 WHERE is_notice = 1");
            }

            $textOverlaysJson = $_POST['text_overlays'] ?? '[]';
            $textOverlays = json_decode($textOverlaysJson, true);
            if (!is_array($textOverlays)) $textOverlays = [];

            $rawTargetTags = $_POST['target_tags'] ?? '';
            $targetTagsJson = encodeTagsForDb(normalizeTagList($rawTargetTags));

            // 5. DBに保存 (既存更新 UPDATE or 新規登録 INSERT)
            if ($existingMenu) {
                $stmt = $targetDb->prepare("
                    UPDATE rich_menus SET
                        line_menu_id = :line_menu_id,
                        alias_id = :alias_id,
                        title = :title,
                        chat_bar_text = :chat_bar_text,
                        image_url = :image_url,
                        base_image_url = :base_image_url,
                        areas_json = :areas_json,
                        text_overlays_json = :text_overlays_json,
                        target_tags = :target_tags,
                        width = :width,
                        height = :height,
                        is_active = :is_active,
                        is_notice = :is_notice,
                        updated_at = datetime('now', '+9 hours')
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':line_menu_id' => $lineMenuId,
                    ':alias_id' => $aliasId,
                    ':title' => $title,
                    ':chat_bar_text' => $chatBarText,
                    ':image_url' => $imageUrl,
                    ':base_image_url' => $baseImageUrl,
                    ':areas_json' => json_encode($dbAreas, JSON_UNESCAPED_UNICODE),
                    ':text_overlays_json' => json_encode($textOverlays, JSON_UNESCAPED_UNICODE),
                    ':target_tags' => $targetTagsJson,
                    ':width' => $width,
                    ':height' => $height,
                    ':is_active' => $isActive,
                    ':is_notice' => $isNotice,
                    ':id' => $editId
                ]);
                $savedId = $editId;

                // 古いLINEメニューIDをLINE APIから削除して整理
                if (!empty($existingMenu['line_menu_id']) && $existingMenu['line_menu_id'] !== $lineMenuId) {
                    lineDeleteRichMenu($existingMenu['line_menu_id'], $targetAccount);
                }

                $msg = 'リッチメニューを上書き保存しました！';
                if ($shouldApplyLive) {
                    $msg = $isActive 
                        ? 'リッチメニューを更新し、LINE本番アカウントに即時反映しました！' 
                        : "リッチメニューは更新されましたが、LINE本番適用でエラーが発生しました: {$applyError}";
                }
            } else {
                $stmt = $targetDb->prepare("
                    INSERT INTO rich_menus (
                        line_menu_id, alias_id, title, chat_bar_text, image_url, base_image_url, areas_json, text_overlays_json, target_tags,
                        width, height, is_active, is_notice, created_at, updated_at
                    ) VALUES (
                        :line_menu_id, :alias_id, :title, :chat_bar_text, :image_url, :base_image_url, :areas_json, :text_overlays_json, :target_tags,
                        :width, :height, :is_active, :is_notice, datetime('now', '+9 hours'), datetime('now', '+9 hours')
                    )
                ");
                $stmt->execute([
                    ':line_menu_id' => $lineMenuId,
                    ':alias_id' => $aliasId,
                    ':title' => $title,
                    ':chat_bar_text' => $chatBarText,
                    ':image_url' => $imageUrl,
                    ':base_image_url' => $baseImageUrl,
                    ':areas_json' => json_encode($dbAreas, JSON_UNESCAPED_UNICODE),
                    ':text_overlays_json' => json_encode($textOverlays, JSON_UNESCAPED_UNICODE),
                    ':target_tags' => $targetTagsJson,
                    ':width' => $width,
                    ':height' => $height,
                    ':is_active' => $isActive,
                    ':is_notice' => $isNotice
                ]);
                $savedId = (int)$targetDb->lastInsertId();

                $msg = 'リッチメニューを下書きとして新規保存しました！';
                if ($publish) {
                    if ($isActive) {
                        $msg = 'リッチメニューを登録し、LINE本番アカウントに即時適用しました！';
                    } else {
                        $msg = "リッチメニューは保存されましたが、LINE本番適用でエラーが発生しました: {$applyError}";
                    }
                } elseif ($isNotice) {
                    $msg = 'お知らせ専用メニューを登録し、クイックリプライ「📢 お知らせ」のアクティブ対象に設定しました！';
                }
            }

            echo json_encode([
                'success' => true,
                'id' => $savedId,
                'line_menu_id' => $lineMenuId,
                'alias_id' => $aliasId,
                'image_url' => $imageUrl,
                'base_image_url' => $baseImageUrl,
                'is_active' => $isActive,
                'is_notice' => $isNotice,
                'message' => $msg
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 12-2. リッチメニュー管理: お知らせ専用メニューのアクティブ切り替え ---
        case 'admin_set_active_notice':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetAccount = trim($_POST['account'] ?? ($_GET['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);

            $id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
            $stmt = $targetDb->prepare("SELECT * FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '指定されたメニューが見つかりません']);
                exit;
            }

            // 他のお知らせメニューのis_activeを0にして、このメニューをis_notice=1 & is_active=1にする
            $targetDb->exec("UPDATE rich_menus SET is_active = 0 WHERE is_notice = 1");
            $targetDb->prepare("UPDATE rich_menus SET is_notice = 1, is_active = 1, updated_at = datetime('now', '+9 hours') WHERE id = :id")->execute([':id' => $id]);

            echo json_encode([
                'success' => true,
                'message' => "「{$menu['title']}」を現在のアクティブなお知らせメニューに設定しました！LINEのクイックリプライ「📢 お知らせ」を押すとこのメニューが表示されます。"
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 13. リッチメニュー管理: 本番適用切り替え ---
        case 'admin_apply_richmenu':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetAccount = trim($_POST['account'] ?? ($_GET['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);

            $id = (int)($_POST['id'] ?? 0);
            $stmt = $targetDb->prepare("SELECT * FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '対象のリッチメニューが見つかりません']);
                exit;
            }

            $targetLineMenuId = $menu['line_menu_id'] ?? '';
            $setRes = !empty($targetLineMenuId) ? lineSetDefaultRichMenu($targetLineMenuId, $targetAccount) : ['success' => false, 'error' => 'richmenu not found'];

            // LINEサーバー上でメニューが見つからない（richmenu not found）場合、自動自己修復（Auto-Recreate）
            if (!$setRes['success']) {
                $errStr = $setRes['error'] ?? '';
                if (empty($targetLineMenuId) || stripos($errStr, 'not found') !== false || stripos($errStr, 'NotFound') !== false || stripos($errStr, '404') !== false) {
                    $localFileName = basename(parse_url($menu['image_url'] ?? '', PHP_URL_PATH) ?? '');
                    $imgFilePath = !empty($localFileName) ? (RICHMENU_UPLOAD_DIR . '/' . $localFileName) : '';
                    if (empty($imgFilePath) || !file_exists($imgFilePath) || filesize($imgFilePath) === 0) {
                        $baseFileName = basename(parse_url($menu['base_image_url'] ?? '', PHP_URL_PATH) ?? '');
                        $imgFilePath = !empty($baseFileName) ? (RICHMENU_UPLOAD_DIR . '/' . $baseFileName) : '';
                    }

                    // areas 設定
                    $areas = json_decode($menu['areas_json'] ?? '[]', true) ?: [];
                    $lineAreas = [];
                    foreach ($areas as $a) {
                        if (empty($a['bounds']) || empty($a['action'])) continue;
                        $lineAreas[] = [
                            'bounds' => [
                                'x' => (int)($a['bounds']['x'] ?? 0),
                                'y' => (int)($a['bounds']['y'] ?? 0),
                                'width' => (int)($a['bounds']['width'] ?? 100),
                                'height' => (int)($a['bounds']['height'] ?? 100)
                            ],
                            'action' => $a['action']
                        ];
                    }
                    if (empty($lineAreas)) {
                        $lineAreas[] = [
                            'bounds' => ['x' => 0, 'y' => 0, 'width' => 2500, 'height' => 1686],
                            'action' => ['type' => 'postback', 'data' => 'action=open_mycar', 'label' => 'メニュー']
                        ];
                    }

                    $lineMenuData = [
                        'size' => [
                            'width' => (int)($menu['width'] ?: 2500),
                            'height' => (int)($menu['height'] ?: 1686)
                        ],
                        'selected' => true,
                        'name' => mb_substr($menu['title'] ?: '本番メニュー', 0, 300),
                        'chatBarText' => mb_substr($menu['chat_bar_text'] ?: 'メニュー', 0, 14),
                        'areas' => array_slice($lineAreas, 0, 20)
                    ];

                    $recreateRes = lineCreateRichMenu($lineMenuData, $targetAccount);
                    if (!empty($recreateRes['success']) && !empty($recreateRes['richMenuId'])) {
                        $recreatedLmid = $recreateRes['richMenuId'];
                        $uploadedOk = false;
                        if (!empty($imgFilePath) && file_exists($imgFilePath) && filesize($imgFilePath) > 0) {
                            $ext = strtolower(pathinfo($imgFilePath, PATHINFO_EXTENSION));
                            $cType = ($ext === 'png') ? 'image/png' : 'image/jpeg';
                            $upRes = lineUploadRichMenuImage($recreatedLmid, $imgFilePath, $cType, $targetAccount);
                            $uploadedOk = !empty($upRes['success']);
                        }

                        if ($uploadedOk) {
                            $targetLineMenuId = $recreatedLmid;
                            $targetDb->prepare("UPDATE rich_menus SET line_menu_id = :lmid, updated_at = datetime('now', '+9 hours') WHERE id = :id")
                               ->execute([':lmid' => $targetLineMenuId, ':id' => $id]);
                            // 再度デフォルト適用実行
                            $setRes = lineSetDefaultRichMenu($targetLineMenuId, $targetAccount);
                        }
                    }
                }
            }

            if (!$setRes['success']) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'LINEデフォルト設定エラー: 選択されたメニューはLINEサーバー上に存在せず、画像ファイルもないため復元できませんでした。画像を設定して再度お試しください。詳細: ' . ($setRes['error'] ?? '')
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // DB更新
            $targetDb->exec("UPDATE rich_menus SET is_active = 0");
            $targetDb->prepare("UPDATE rich_menus SET is_active = 1, updated_at = datetime('now', '+9 hours') WHERE id = :id")->execute([':id' => $id]);

            echo json_encode([
                'success' => true,
                'message' => "「{$menu['title']}」をLINE公式アカウントの本番リッチメニューに適用しました！"
            ], JSON_UNESCAPED_UNICODE);
            break;

        // --- 13-2. リッチメニュー管理: 全顧客へのタグ連動リッチメニュー一括適用・同期 ---
        case 'admin_sync_tag_richmenus':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetAccount = trim($_POST['account'] ?? ($_GET['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);

            try {
                $syncRes = syncAllTagBasedRichMenus($targetAccount, $targetDb);
                echo json_encode($syncRes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => '一括同期例外: ' . $e->getMessage()]);
            }
            break;

        // --- 14. リッチメニュー管理: 削除 ---
        case 'admin_delete_richmenu':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetAccount = trim($_POST['account'] ?? ($_GET['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);

            $id = (int)($_POST['id'] ?? 0);
            $stmt = $targetDb->prepare("SELECT * FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '対象のリッチメニューが見つかりません']);
                exit;
            }

            // LINE側から削除
            if (!empty($menu['line_menu_id'])) {
                lineDeleteRichMenu($menu['line_menu_id'], $targetAccount);
            }
            if (!empty($menu['alias_id'])) {
                lineDeleteRichMenuAlias($menu['alias_id'], $targetAccount);
            }

            // 画像ファイルの削除
            if (!empty($menu['image_url'])) {
                $baseName = basename(parse_url($menu['image_url'], PHP_URL_PATH));
                $localPath = RICHMENU_UPLOAD_DIR . '/' . $baseName;
                if (file_exists($localPath)) {
                    @unlink($localPath);
                }
            }

            // DBから削除
            $targetDb->prepare("DELETE FROM rich_menus WHERE id = :id")->execute([':id' => $id]);

            echo json_encode([
                'success' => true,
                'message' => "リッチメニュー「{$menu['title']}」を削除しました。"
            ], JSON_UNESCAPED_UNICODE);
            break;

        // --- 15. リッチメニュー管理: 管理名（タイトル）変更 ---
        case 'admin_rename_richmenu':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetAccount = trim($_POST['account'] ?? ($_GET['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);

            $id = (int)($_POST['id'] ?? 0);
            $newTitle = trim($_POST['title'] ?? '');
            if (empty($newTitle)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'リッチメニュー名を入力してください']);
                exit;
            }

            $stmt = $targetDb->prepare("SELECT id, title FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '対象のリッチメニューが見つかりません']);
                exit;
            }

            $updateStmt = $targetDb->prepare("UPDATE rich_menus SET title = :title, updated_at = datetime('now', '+9 hours') WHERE id = :id");
            $updateStmt->execute([
                ':title' => $newTitle,
                ':id' => $id
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'リッチメニュー名を変更しました',
                'id' => $id,
                'title' => $newTitle
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 15-2. リッチメニュー管理: 対象アカウントのリッチメニュー完全初期化 ---
        case 'admin_reset_account_richmenus':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetAccount = trim($_POST['account'] ?? ($_GET['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);

            // 1. LINE公式アカウントのデフォルトリッチメニューを解除
            lineCancelDefaultRichMenu($targetAccount);

            // 2. LINE公式アカウント上のすべてのリッチメニュー・エイリアスを安全に削除（オプション指定時またはデフォルト）
            $cleanLine = !empty($_POST['clean_line_server']) && ($_POST['clean_line_server'] === '1' || $_POST['clean_line_server'] === 'true' || $_POST['clean_line_server'] === 'yes');
            $deletedLineCount = 0;
            if ($cleanLine) {
                $remoteList = lineGetRichMenuList($targetAccount);
                if (!empty($remoteList['richmenus'])) {
                    foreach ($remoteList['richmenus'] as $rm) {
                        if (!empty($rm['richMenuId'])) {
                            lineDeleteRichMenu($rm['richMenuId'], $targetAccount);
                            $deletedLineCount++;
                        }
                    }
                }
                $aliasList = lineGetRichMenuAliasList($targetAccount);
                if (!empty($aliasList['aliases'])) {
                    foreach ($aliasList['aliases'] as $al) {
                        if (!empty($al['richMenuAliasId'])) {
                            lineDeleteRichMenuAlias($al['richMenuAliasId'], $targetAccount);
                        }
                    }
                }
            }

            // 3. 対象アカウントの rich_menus テーブルを完全クリア
            try {
                $targetDb->exec("DELETE FROM rich_menus");
                $targetDb->exec("DELETE FROM sqlite_sequence WHERE name = 'rich_menus'");
            } catch (Exception $e) {}

            // 4. 顧客テーブルの個別リッチメニュー設定もクリア
            try {
                $targetDb->exec("UPDATE customer_cars SET custom_line_menu_id = '', custom_menu_text = '', custom_menu_set_at = NULL");
            } catch (Exception $e) {}

            $accConfig = getAccountConfig($targetAccount);
            $accName = $accConfig['name'] ?? $targetAccount;

            echo json_encode([
                'success' => true,
                'account' => $targetAccount,
                'message' => "アカウント「{$accName}」のリッチメニューを初期状態にリセットしました！新規作成からまっさらな状態でスタートできます。"
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 16. 特定ユーザー向け個別リッチメニュー適用 ---
        case 'admin_set_user_custom_richmenu':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetAccount = trim($_POST['account'] ?? ($_GET['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);

            $userId = trim($_POST['uid'] ?? '');
            if (empty($userId) || str_starts_with($userId, 'MANUAL_')) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'LINE未連携の顧客にはリッチメニューを適用できません（友だち追加後のUIDが必要です）']);
                exit;
            }

            $customText = trim($_POST['custom_text'] ?? '');
            $baseMenuId = (int)($_POST['base_menu_id'] ?? 0);

            // ベースとなるリッチメニューを取得（お知らせメニューは絶対に専用メニューのベースにしない！）
            $baseMenu = null;
            if ($baseMenuId > 0) {
                $stmt = $targetDb->prepare("SELECT * FROM rich_menus WHERE id = :id");
                $stmt->execute([':id' => $baseMenuId]);
                $candidate = $stmt->fetch(PDO::FETCH_ASSOC);
                // お知らせメニューでなければ採用
                if ($candidate && empty($candidate['is_notice'])) {
                    $baseMenu = $candidate;
                }
            }
            if (!$baseMenu) {
                // デフォルトとして現在本番中の通常メニューを取得 (is_notice = 0)
                $stmt = $targetDb->query("SELECT * FROM rich_menus WHERE is_active = 1 AND is_notice = 0 ORDER BY id DESC LIMIT 1");
                $baseMenu = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$baseMenu) {
                // さらに無ければ通常メニューの最新を取得 (is_notice = 0)
                $stmt = $targetDb->query("SELECT * FROM rich_menus WHERE is_notice = 0 ORDER BY id DESC LIMIT 1");
                $baseMenu = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$baseMenu) {
                // 最後の手段として全体から取得
                $stmt = $targetDb->query("SELECT * FROM rich_menus ORDER BY id DESC LIMIT 1");
                $baseMenu = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$baseMenu) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ベースとなるリッチメニューが存在しません。先にリッチメニューを作成してください。']);
                exit;
            }

            // 合成画像の受信・保存
            $uploadedFile = $_FILES['image'] ?? null;
            if (empty($uploadedFile) || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '合成画像のアップロードに失敗しました']);
                exit;
            }

            $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $ext = 'jpg';
            }
            $contentType = ($ext === 'png') ? 'image/png' : 'image/jpeg';
            $fileName = 'custom_' . substr(md5($userId), 0, 10) . '_' . date('Ymd_His') . '.' . $ext;
            $targetFilePath = RICHMENU_UPLOAD_DIR . '/' . $fileName;

            if (!move_uploaded_file($uploadedFile['tmp_name'], $targetFilePath)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => '画像ファイルの保存に失敗しました']);
                exit;
            }

            // ベースメニューのエリア設定を引き継ぐ（画面送信・DB設定・LINEサーバー実データの最適マージ）
            $rawAreas = [];

            // 優先順位1: クライアントから送信された base_areas (管理画面で選択したメニューの現在のアクション定義)
            if (!empty($_POST['base_areas'])) {
                $posted = json_decode($_POST['base_areas'], true);
                if (is_array($posted) && count($posted) > 0) {
                    $rawAreas = $posted;
                }
            }

            // 優先順位2: DBの areas_json (保存済みの検証済みボタンアクション)
            if (empty($rawAreas) && !empty($baseMenu['areas_json'])) {
                $dbJson = json_decode($baseMenu['areas_json'], true);
                if (is_array($dbJson) && count($dbJson) > 0) {
                    $rawAreas = $dbJson;
                }
            }

            // 優先順位3: ベースメニューの line_menu_id から LINEサーバー実データを直接取得
            $lineMenuIdToFetch = !empty($baseMenu['line_menu_id']) ? $baseMenu['line_menu_id'] : ($_POST['base_line_menu_id'] ?? '');
            if (empty($rawAreas) && !empty($lineMenuIdToFetch)) {
                $lineRemote = lineGetRichMenu($lineMenuIdToFetch, $targetAccount);
                if (!empty($lineRemote['areas']) && is_array($lineRemote['areas']) && count($lineRemote['areas']) > 0) {
                    $rawAreas = $lineRemote['areas'];
                }
            }

            // 優先順位4: 現在のLINE全体デフォルトリッチメニューから実データを取得
            if (empty($rawAreas)) {
                $currentDefId = lineGetDefaultRichMenuId($targetAccount);
                if (!empty($currentDefId)) {
                    $lineRemote = lineGetRichMenu($currentDefId, $targetAccount);
                    if (!empty($lineRemote['areas']) && is_array($lineRemote['areas']) && count($lineRemote['areas']) > 0) {
                        $rawAreas = $lineRemote['areas'];
                    }
                }
            }

            $width = (int)($baseMenu['width'] ?? 2500);
            $height = (int)($baseMenu['height'] ?? 1686);
            $lineAreas = [];

            foreach ($rawAreas as $a) {
                if (empty($a['bounds']) || empty($a['action'])) continue;

                $bounds = [
                    'x' => max(0, (int)($a['bounds']['x'] ?? 0)),
                    'y' => max(0, (int)($a['bounds']['y'] ?? 0)),
                    'width' => max(1, (int)($a['bounds']['width'] ?? 100)),
                    'height' => max(1, (int)($a['bounds']['height'] ?? 100))
                ];
                if ($bounds['x'] + $bounds['width'] > $width) {
                    $bounds['width'] = $width - $bounds['x'];
                }
                if ($bounds['y'] + $bounds['height'] > $height) {
                    $bounds['height'] = $height - $bounds['y'];
                }

                $act = $a['action'];
                $actionType = $act['type'] ?? 'postback';
                $cleanAction = ['type' => $actionType];

                if ($actionType === 'uri') {
                    $cleanAction['uri'] = trim($act['uri'] ?? 'https://www.goo-net.com');
                    if (!empty($act['label'])) $cleanAction['label'] = $act['label'];
                } elseif ($actionType === 'postback') {
                    $data = trim($act['data'] ?? '');
                    if (empty($data)) $data = 'action=search_all';
                    $cleanAction['data'] = $data;

                    // 管理者側通知防止のため、元データに明示的に存在する場合のみ displayText を引き継ぐ（自動付与は行わない）
                    if (!empty($act['displayText']) && trim($act['displayText']) !== '') {
                        $cleanAction['displayText'] = trim($act['displayText']);
                    }
                    if (!empty($act['label'])) $cleanAction['label'] = $act['label'];
                } elseif ($actionType === 'message') {
                    $cleanAction['text'] = trim($act['text'] ?? 'メニュー');
                    if (!empty($act['label'])) $cleanAction['label'] = $act['label'];
                } elseif ($actionType === 'richmenuswitch') {
                    $alias = trim($act['richMenuAliasId'] ?? '');
                    if (!empty($alias)) {
                        $cleanAction['richMenuAliasId'] = $alias;
                        $dataVal = trim($act['data'] ?? '');
                        $branchCustom = !empty($act['branchCustom']) || !empty($isNotice) || str_contains($dataVal, 'branch_custom=1');

                        if (empty($dataVal) || $dataVal === 'action=richmenu_switched' || !str_contains($dataVal, 'to_alias=')) {
                            $dataVal = 'action=richmenu_switched&to_alias=' . urlencode($alias);
                        }
                        if ($branchCustom && !str_contains($dataVal, 'branch_custom=1')) {
                            $dataVal .= '&branch_custom=1';
                        }
                        if (!empty($isNotice) && !str_contains($dataVal, 'from_notice=1')) {
                            $dataVal .= '&from_notice=1';
                        }
                        $cleanAction['data'] = $dataVal;
                    } else {
                        $cleanAction = ['type' => 'postback', 'data' => 'action=search_all'];
                    }
                } else {
                    $cleanAction = $act;
                }

                $lineAreas[] = [
                    'bounds' => $bounds,
                    'action' => $cleanAction
                ];
            }

            // メッセージ帯タップ時のアクションを追加（最前面タップエリアとして先頭に配置）
            $bannerActionType = trim($_POST['banner_action_type'] ?? 'mycar_liff');
            $bannerActionUri = trim($_POST['banner_action_uri'] ?? '');
            $bannerActionPostback = trim($_POST['banner_action_postback'] ?? '');
            $bannerBounds = !empty($_POST['banner_bounds']) ? json_decode($_POST['banner_bounds'], true) : null;

            if ($bannerActionType !== 'none' && !empty($bannerBounds) && is_array($bannerBounds)) {
                $bannerCleanAction = null;
                if ($bannerActionType === 'reservation_cal' || $bannerActionType === 'proline_cal') {
                    $calUrl = getAccountProlineCalendarUrl($targetAccount);
                    $bannerCleanAction = [
                        'type' => 'uri',
                        'uri' => $calUrl,
                        'label' => '予約・日程変更'
                    ];
                } elseif ($bannerActionType === 'mycar_liff') {
                    $liffId = getLineLiffId($targetAccount);
                    $bannerCleanAction = [
                        'type' => 'uri',
                        'uri' => "https://liff.line.me/{$liffId}/mycar.html",
                        'label' => '受講予約・相談'
                    ];
                } elseif ($bannerActionType === 'open_mycar') {
                    $bannerCleanAction = [
                        'type' => 'postback',
                        'data' => 'action=open_mycar',
                        'label' => '点検受付'
                    ];
                } elseif ($bannerActionType === 'search_all') {
                    $bannerCleanAction = [
                        'type' => 'postback',
                        'data' => 'action=search_all',
                        'label' => '在庫一覧'
                    ];
                } elseif ($bannerActionType === 'notice') {
                    $bannerCleanAction = [
                        'type' => 'postback',
                        'data' => 'action=show_notice_menu',
                        'label' => 'お知らせ'
                    ];
                } elseif ($bannerActionType === 'uri') {
                    $bannerCleanAction = [
                        'type' => 'uri',
                        'uri' => !empty($bannerActionUri) ? $bannerActionUri : 'https://www.goo-net.com',
                        'label' => '詳細リンク'
                    ];
                } elseif ($bannerActionType === 'postback') {
                    $bannerCleanAction = [
                        'type' => 'postback',
                        'data' => !empty($bannerActionPostback) ? $bannerActionPostback : 'action=search_all',
                        'label' => 'アクション'
                    ];
                }

                if ($bannerCleanAction) {
                    $bX = max(0, (int)($bannerBounds['x'] ?? 0));
                    $bY = max(0, (int)($bannerBounds['y'] ?? 0));
                    $bW = min($width - $bX, max(1, (int)($bannerBounds['width'] ?? $width)));
                    $bH = min($height - $bY, max(1, (int)($bannerBounds['height'] ?? 200)));

                    $bannerAreaObj = [
                        'bounds' => [
                            'x' => $bX,
                            'y' => $bY,
                            'width' => $bW,
                            'height' => $bH
                        ],
                        'action' => $bannerCleanAction
                    ];

                    // LINEはareasの先頭からヒット判定するため、先頭に追加して最優先化
                    array_unshift($lineAreas, $bannerAreaObj);
                    writeDebugLog("専用メニューのメッセージ帯タップ領域追加", [
                        'action' => $bannerCleanAction,
                        'bounds' => $bannerAreaObj['bounds']
                    ]);
                }
            }

            // LINEリッチメニューは最大20エリア制限
            if (count($lineAreas) > 20) {
                $lineAreas = array_slice($lineAreas, 0, 20);
            }

            // 万が一エリアが0件の場合は空メニューの作成を阻止
            if (empty($lineAreas)) {
                @unlink($targetFilePath);
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'ベースメニューのボタン設定（タップ領域）が検出できませんでした。リッチメニュー管理でメニューにボタン枠が設定されているかご確認ください。'
                ]);
                exit;
            }

            writeDebugLog("個別専用リッチメニュー作成開始", [
                'userId' => $userId,
                'areasCount' => count($lineAreas),
                'firstArea' => $lineAreas[0] ?? null
            ]);

            // 顧客名を取得
            $stmtCust = $targetDb->prepare("SELECT user_name, custom_line_menu_id FROM customer_cars WHERE user_id = :uid LIMIT 1");
            $stmtCust->execute([':uid' => $userId]);
            $custRow = $stmtCust->fetch(PDO::FETCH_ASSOC);
            $custName = $custRow['user_name'] ?? 'お客様';
            $oldLineMenuId = $custRow['custom_line_menu_id'] ?? '';

            // LINE API: 個別リッチメニュー作成
            $lineMenuData = [
                'size' => [
                    'width' => $width,
                    'height' => $height
                ],
                'selected' => true,
                'name' => mb_substr("【専用】{$custName}様 " . date('m/d H:i'), 0, 300),
                'chatBarText' => mb_substr($baseMenu['chat_bar_text'] ?: 'メニュー', 0, 14),
                'areas' => $lineAreas
            ];

            $createRes = lineCreateRichMenu($lineMenuData, $targetAccount);
            if (!$createRes['success'] || empty($createRes['richMenuId'])) {
                @unlink($targetFilePath);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'LINEリッチメニュー作成失敗: ' . ($createRes['error'] ?? '')]);
                exit;
            }
            $newLineMenuId = $createRes['richMenuId'];

            // LINE API: 画像アップロード
            $uploadRes = lineUploadRichMenuImage($newLineMenuId, $targetFilePath, $contentType, $targetAccount);
            if (!$uploadRes['success']) {
                lineDeleteRichMenu($newLineMenuId, $targetAccount);
                @unlink($targetFilePath);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'LINE画像アップロード失敗: ' . ($uploadRes['error'] ?? '')]);
                exit;
            }

            // LINE API: ユーザーへ個別リンク実行！
            $linkRes = lineLinkUserRichMenu($userId, $newLineMenuId, $targetAccount);
            if (!$linkRes['success']) {
                lineDeleteRichMenu($newLineMenuId, $targetAccount);
                @unlink($targetFilePath);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'ユーザーへの個別メニュー割当失敗: ' . ($linkRes['error'] ?? '')]);
                exit;
            }

            // 以前の古い個別メニューがあれば削除
            if (!empty($oldLineMenuId) && $oldLineMenuId !== $newLineMenuId) {
                lineDeleteRichMenu($oldLineMenuId, $targetAccount);
            }

            // DB更新
            $targetDb->prepare("
                UPDATE customer_cars SET
                    custom_line_menu_id = :lmid,
                    custom_menu_text = :txt,
                    custom_menu_set_at = datetime('now', '+9 hours')
                WHERE user_id = :uid
            ")->execute([
                ':lmid' => $newLineMenuId,
                ':txt' => $customText,
                ':uid' => $userId
            ]);
            recordCustomerInteraction($targetDb, $userId, 'custom_menu', "専用メッセージ設定: " . mb_substr($customText, 0, 35));

            $buttonSummaries = [];
            foreach ($lineAreas as $idx => $la) {
                $type = $la['action']['type'] ?? 'unknown';
                $detail = $la['action']['data'] ?? ($la['action']['uri'] ?? ($la['action']['text'] ?? ''));
                $buttonSummaries[] = "枠" . ($idx + 1) . " [{$type}: {$detail}]";
            }

            echo json_encode([
                'success' => true,
                'message' => "「{$custName}」様に専用メッセージ付きリッチメニューを適用しました！（ボタン" . count($lineAreas) . "個を正常に引き継ぎ）",
                'buttons' => $buttonSummaries,
                'areas_count' => count($lineAreas),
                'custom_line_menu_id' => $newLineMenuId,
                'custom_menu_text' => $customText
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 17. 特定ユーザーの個別リッチメニュー解除（全体共通メニューへ戻す） ---
        case 'admin_unlink_user_richmenu':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetAccount = trim($_POST['account'] ?? ($_GET['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);

            $userId = trim($_POST['uid'] ?? '');
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }

            // 既存の個別メニューIDを取得
            $stmtCust = $targetDb->prepare("SELECT user_name, custom_line_menu_id FROM customer_cars WHERE user_id = :uid LIMIT 1");
            $stmtCust->execute([':uid' => $userId]);
            $custRow = $stmtCust->fetch(PDO::FETCH_ASSOC);
            $custName = $custRow['user_name'] ?? 'お客様';
            $oldLineMenuId = $custRow['custom_line_menu_id'] ?? '';

            // LINE API: 個別紐付け解除
            $unlinkRes = lineUnlinkUserRichMenu($userId, $targetAccount);

            // 既存の全体・作成済みリッチメニューでなければ（個別動的メニューなら）古いLINEメニューを削除
            if (!empty($oldLineMenuId)) {
                $checkExist = $targetDb->prepare("SELECT id FROM rich_menus WHERE line_menu_id = :mid LIMIT 1");
                $checkExist->execute([':mid' => $oldLineMenuId]);
                if (!$checkExist->fetch()) {
                    lineDeleteRichMenu($oldLineMenuId, $targetAccount);
                }
            }

            // DB更新
            $targetDb->prepare("
                UPDATE customer_cars SET
                    custom_line_menu_id = '',
                    custom_menu_text = '',
                    custom_menu_set_at = NULL
                WHERE user_id = :uid
            ")->execute([':uid' => $userId]);

            echo json_encode([
                'success' => true,
                'message' => "「{$custName}」様の個別リッチメニューを解除し、全体共通メニューに戻しました！"
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 17-2. 特定ユーザーの現在表示中リッチメニュー実態確認 ---
        case 'admin_get_user_richmenu_status':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetAccount = trim($_GET['account'] ?? ($_POST['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);

            $userId = trim($_GET['uid'] ?? ($_POST['uid'] ?? ''));
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }

            // LINEサーバー上の実態を取得
            $realLineMenuId = lineGetUserRichMenu($userId, $targetAccount);

            // 現在LINE公式アカウント全体のデフォルトリッチメニューID
            $currentLineDefaultId = lineGetDefaultRichMenuId($targetAccount);

            // DB上の現在全体デフォルトメニューを検索
            $defaultMenu = null;
            if (!empty($currentLineDefaultId)) {
                $stmtDef = $targetDb->prepare("SELECT id, title, line_menu_id, image_url FROM rich_menus WHERE line_menu_id = :mid LIMIT 1");
                $stmtDef->execute([':mid' => $currentLineDefaultId]);
                $defaultMenu = $stmtDef->fetch(PDO::FETCH_ASSOC);
            }
            if (!$defaultMenu) {
                $defMenuStmt = $targetDb->query("SELECT id, title, line_menu_id, image_url FROM rich_menus WHERE is_active = 1 AND is_notice = 0 ORDER BY id DESC LIMIT 1");
                $defaultMenu = $defMenuStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$defaultMenu) {
                $defMenuStmt = $targetDb->query("SELECT id, title, line_menu_id, image_url FROM rich_menus WHERE is_notice = 0 ORDER BY id DESC LIMIT 1");
                $defaultMenu = $defMenuStmt->fetch(PDO::FETCH_ASSOC);
            }

            $resolvedDefaultTitle = $defaultMenu['title'] ?? '';
            if (empty($resolvedDefaultTitle) && !empty($currentLineDefaultId)) {
                $lineRemote = lineGetRichMenu($currentLineDefaultId, $targetAccount);
                $resolvedDefaultTitle = $lineRemote['name'] ?? '通常メニュー';
            }
            if (empty($resolvedDefaultTitle) || $resolvedDefaultTitle === '全体共通メニュー') {
                $resolvedDefaultTitle = '通常メニュー';
            }

            // 顧客テーブルの記録
            $custStmt = $targetDb->prepare("SELECT user_name, custom_line_menu_id, custom_menu_text, custom_menu_set_at FROM customer_cars WHERE user_id = :uid LIMIT 1");
            $custStmt->execute([':uid' => $userId]);
            $cust = $custStmt->fetch(PDO::FETCH_ASSOC);

            $statusType = 'default';
            $menuTitle = $resolvedDefaultTitle;
            $menuImageUrl = $defaultMenu['image_url'] ?? '';
            $menuId = $realLineMenuId ?: ($defaultMenu['line_menu_id'] ?? $currentLineDefaultId);

            if (!empty($realLineMenuId)) {
                // DBの全リッチメニューから照合
                $stmtMenu = $targetDb->prepare("SELECT id, title, image_url, is_notice FROM rich_menus WHERE line_menu_id = :mid LIMIT 1");
                $stmtMenu->execute([':mid' => $realLineMenuId]);
                $matchedMenu = $stmtMenu->fetch(PDO::FETCH_ASSOC);

                if ($matchedMenu) {
                    $statusType = 'custom_assigned';
                    $menuTitle = $matchedMenu['title'];
                    $menuImageUrl = $matchedMenu['image_url'];
                } elseif (!empty($cust['custom_menu_text'])) {
                    $statusType = 'custom_message';
                    $menuTitle = "専用メッセージ付きメニュー";
                } else {
                    $statusType = 'custom_assigned';
                    $menuTitle = "個別指定メニュー ({$realLineMenuId})";
                }
            }

            $matchedMenuId = $matchedMenu['id'] ?? ($defaultMenu['id'] ?? null);

            echo json_encode([
                'success' => true,
                'account' => $targetAccount,
                'user_id' => $userId,
                'user_name' => $cust['user_name'] ?? '',
                'real_line_menu_id' => $realLineMenuId,
                'rich_menu_id' => $realLineMenuId,
                'menu_id' => $matchedMenuId,
                'has_custom_link' => !empty($realLineMenuId),
                'status' => $statusType,
                'status_type' => $statusType, // 'default', 'custom_assigned', 'custom_message'
                'title' => $menuTitle,
                'menu_title' => $menuTitle,
                'image_url' => $menuImageUrl,
                'menu_image_url' => $menuImageUrl,
                'custom_menu_text' => $cust['custom_menu_text'] ?? '',
                'custom_menu_set_at' => $cust['custom_menu_set_at'] ?? null,
                'default_menu_title' => $resolvedDefaultTitle
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 17-3. 作成済みリッチメニューを特定ユーザーに個別割り当て ---
        case 'admin_assign_richmenu_to_user':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $targetAccount = trim($_POST['account'] ?? ($_GET['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? getActiveAccountKey())));
            $targetDb = getDbConnection($targetAccount);

            $userId = trim($_POST['uid'] ?? '');
            $menuId = (int)($_POST['rich_menu_id'] ?? ($_POST['menu_id'] ?? 0));
            if (empty($userId) || str_starts_with($userId, 'MANUAL_')) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'LINE未連携の顧客にはリッチメニューを適用できません']);
                exit;
            }

            // 指定メニューを取得
            $stmtM = $targetDb->prepare("SELECT * FROM rich_menus WHERE id = :id LIMIT 1");
            $stmtM->execute([':id' => $menuId]);
            $targetMenu = $stmtM->fetch(PDO::FETCH_ASSOC);

            if (!$targetMenu || empty($targetMenu['line_menu_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '指定されたリッチメニューがLINEに未登録です']);
                exit;
            }

            $newLineMenuId = $targetMenu['line_menu_id'];

            // 既存の個別メニューIDを取得
            $stmtCust = $targetDb->prepare("SELECT user_name, custom_line_menu_id, custom_menu_text FROM customer_cars WHERE user_id = :uid LIMIT 1");
            $stmtCust->execute([':uid' => $userId]);
            $custRow = $stmtCust->fetch(PDO::FETCH_ASSOC);
            $custName = $custRow['user_name'] ?? 'お客様';
            $oldLineMenuId = $custRow['custom_line_menu_id'] ?? '';

            // LINE API: 個別リンク実行
            $linkRes = lineLinkUserRichMenu($userId, $newLineMenuId, $targetAccount);
            if (!$linkRes['success']) {
                $errStr = $linkRes['error'] ?? '';
                // LINEサーバー上でメニューが見つからない（richmenu not found / 削除済み）場合、自動自己修復（Auto-Recreate）
                if (stripos($errStr, 'not found') !== false || stripos($errStr, 'NotFound') !== false || stripos($errStr, '404') !== false) {
                    $localFileName = basename(parse_url($targetMenu['image_url'] ?? '', PHP_URL_PATH) ?? '');
                    $imgFilePath = !empty($localFileName) ? (RICHMENU_UPLOAD_DIR . '/' . $localFileName) : '';
                    if (empty($imgFilePath) || !file_exists($imgFilePath) || filesize($imgFilePath) === 0) {
                        $baseFileName = basename(parse_url($targetMenu['base_image_url'] ?? '', PHP_URL_PATH) ?? '');
                        $imgFilePath = !empty($baseFileName) ? (RICHMENU_UPLOAD_DIR . '/' . $baseFileName) : '';
                    }

                    // areas 設定
                    $areas = json_decode($targetMenu['areas_json'] ?? '[]', true) ?: [];
                    $lineAreas = [];
                    foreach ($areas as $a) {
                        if (empty($a['bounds']) || empty($a['action'])) continue;
                        $lineAreas[] = [
                            'bounds' => [
                                'x' => (int)($a['bounds']['x'] ?? 0),
                                'y' => (int)($a['bounds']['y'] ?? 0),
                                'width' => (int)($a['bounds']['width'] ?? 100),
                                'height' => (int)($a['bounds']['height'] ?? 100)
                            ],
                            'action' => $a['action']
                        ];
                    }
                    if (empty($lineAreas)) {
                        $lineAreas[] = [
                            'bounds' => ['x' => 0, 'y' => 0, 'width' => 2500, 'height' => 1686],
                            'action' => ['type' => 'postback', 'data' => 'action=open_mycar', 'label' => 'メニュー']
                        ];
                    }

                    $lineMenuData = [
                        'size' => [
                            'width' => (int)($targetMenu['width'] ?: 2500),
                            'height' => (int)($targetMenu['height'] ?: 1686)
                        ],
                        'selected' => false,
                        'name' => mb_substr($targetMenu['title'] ?: '復元メニュー', 0, 300),
                        'chatBarText' => mb_substr($targetMenu['chat_bar_text'] ?: 'メニュー', 0, 14),
                        'areas' => array_slice($lineAreas, 0, 20)
                    ];

                    $recreateRes = lineCreateRichMenu($lineMenuData, $targetAccount);
                    if (!empty($recreateRes['success']) && !empty($recreateRes['richMenuId'])) {
                        $recreatedLmid = $recreateRes['richMenuId'];
                        $uploadedOk = false;
                        if (!empty($imgFilePath) && file_exists($imgFilePath) && filesize($imgFilePath) > 0) {
                            $ext = strtolower(pathinfo($imgFilePath, PATHINFO_EXTENSION));
                            $cType = ($ext === 'png') ? 'image/png' : 'image/jpeg';
                            $upRes = lineUploadRichMenuImage($recreatedLmid, $imgFilePath, $cType, $targetAccount);
                            $uploadedOk = !empty($upRes['success']);
                        }

                        if ($uploadedOk) {
                            $newLineMenuId = $recreatedLmid;
                            $targetDb->prepare("UPDATE rich_menus SET line_menu_id = :lmid, updated_at = datetime('now', '+9 hours') WHERE id = :id")
                               ->execute([':lmid' => $newLineMenuId, ':id' => $targetMenu['id']]);
                            // 再試行
                            $linkRes = lineLinkUserRichMenu($userId, $newLineMenuId, $targetAccount);
                        }
                    }
                }
            }

            if (!$linkRes['success']) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => "メニュー割当失敗: 選択されたメニューはLINEサーバー上に存在しないか期限切れです。\nリッチメニュー管理画面で「LINEからメニュー同期」を実行するか、「専用メッセージ帯付きメニュー」タブから適用してください。（LINEエラー: " . ($linkRes['error'] ?? '') . "）"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 以前のメニューが「専用メッセージメニュー（custom_menu_textあり）」だった場合はLINE上の古い画像メニューを削除
            if (!empty($oldLineMenuId) && $oldLineMenuId !== $newLineMenuId && !empty($custRow['custom_menu_text'])) {
                $checkExist = $targetDb->prepare("SELECT id FROM rich_menus WHERE line_menu_id = :mid LIMIT 1");
                $checkExist->execute([':mid' => $oldLineMenuId]);
                if (!$checkExist->fetch()) {
                    lineDeleteRichMenu($oldLineMenuId, $targetAccount);
                }
            }

            // DB更新（専用メッセージテキストはクリア）
            $targetDb->prepare("
                UPDATE customer_cars SET
                    custom_line_menu_id = :lmid,
                    custom_menu_text = '',
                    custom_menu_set_at = datetime('now', '+9 hours')
                WHERE user_id = :uid
            ")->execute([
                ':lmid' => $newLineMenuId,
                ':uid' => $userId
            ]);
            recordCustomerInteraction($targetDb, $userId, 'custom_menu', "個別メニュー割当: {$targetMenu['title']}");

            echo json_encode([
                'success' => true,
                'message' => "「{$custName}」様にリッチメニュー「{$targetMenu['title']}」を割り当てました！",
                'custom_line_menu_id' => $newLineMenuId,
                'menu_title' => $targetMenu['title']
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 18. 管理者LINE通知設定: 設定取得 ---
        case 'admin_get_line_notification_settings':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $settings = getAdminLineSettings($db);
            $settings['admin_line_uids'] = $settings['admin_uids'] ?? [];
            echo json_encode([
                'success' => true,
                'settings' => $settings
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 18-2. 管理者LINE通知設定: 設定保存 ---
        case 'admin_save_line_notification_settings':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $rawUids = $_POST['admin_uids'] ?? ($_POST['admin_line_uids'] ?? '');
            $uids = [];
            if (is_array($rawUids)) {
                $uids = $rawUids;
            } elseif (is_string($rawUids)) {
                // 改行、カンマ、スペース区切り対応
                $lines = preg_split('/[\r\n,、\s]+/u', $rawUids);
                foreach ($lines as $l) {
                    $l = trim($l);
                    if (!empty($l)) {
                        $uids[] = $l;
                    }
                }
            }

            $settingsToSave = [
                'admin_uids' => $uids,
                'notify_chat' => isset($_POST['notify_chat']) ? filter_var($_POST['notify_chat'], FILTER_VALIDATE_BOOLEAN) : true,
                'notify_follow' => isset($_POST['notify_follow']) ? filter_var($_POST['notify_follow'], FILTER_VALIDATE_BOOLEAN) : true,
                'notify_inquiry' => isset($_POST['notify_inquiry']) ? filter_var($_POST['notify_inquiry'], FILTER_VALIDATE_BOOLEAN) : true,
                'notify_booking' => isset($_POST['notify_booking']) ? filter_var($_POST['notify_booking'], FILTER_VALIDATE_BOOLEAN) : true,
                'notify_new_customer' => isset($_POST['notify_new_customer']) ? filter_var($_POST['notify_new_customer'], FILTER_VALIDATE_BOOLEAN) : true,
                'notify_new_cars' => isset($_POST['notify_new_cars']) ? filter_var($_POST['notify_new_cars'], FILTER_VALIDATE_BOOLEAN) : false,
                'notify_reminder' => isset($_POST['notify_reminder']) ? filter_var($_POST['notify_reminder'], FILTER_VALIDATE_BOOLEAN) : true
            ];

            $saved = saveAdminLineSettings($settingsToSave, $db);
            if (!empty($saved['success'])) {
                $updated = getAdminLineSettings($db);
                $updated['admin_line_uids'] = $updated['admin_uids'] ?? [];
                echo json_encode([
                    'success' => true,
                    'message' => '管理者LINE通知設定を保存しました！',
                    'settings' => $updated
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => '設定の保存に失敗しました: ' . ($saved['error'] ?? '不明なエラー')
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            break;

        // --- 18-3. 管理者LINE通知設定: テスト通知送信 ---
        case 'admin_test_line_notification':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $rawTarget = $_POST['uid'] ?? ($_POST['admin_line_uids'] ?? ($_POST['admin_uids'] ?? ''));
            $targetUids = [];
            if (is_array($rawTarget)) {
                $targetUids = $rawTarget;
            } elseif (is_string($rawTarget)) {
                $lines = preg_split('/[\r\n,、\s]+/u', $rawTarget);
                foreach ($lines as $l) {
                    $l = trim($l);
                    if (!empty($l) && str_starts_with($l, 'U')) {
                        $targetUids[] = $l;
                    }
                }
            }

            if (empty($targetUids)) {
                $cur = getAdminLineSettings($db);
                $targetUids = $cur['admin_uids'] ?? [];
            }

            if (empty($targetUids)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '送信先の管理者LINE UID（Uから始まる33文字）が入力されていないか無効です']);
                exit;
            }

            $results = [];
            $successCount = 0;
            foreach ($targetUids as $uid) {
                $res = sendAdminLineTestNotification($uid, $db);
                $isOk = !empty($res['success']);
                if ($isOk) $successCount++;
                $results[] = [
                    'uid' => $uid,
                    'success' => $isOk,
                    'error' => $res['error'] ?? null
                ];
            }

            echo json_encode([
                'success' => ($successCount > 0),
                'message' => "{$successCount} 件のアカウントへテスト通知を送信しました！",
                'results' => $results
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 19. プロライン連携設定取得 ---
        case 'admin_get_proline_settings':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $targetAccount = trim($_GET['account'] ?? ($_POST['account'] ?? getActiveAccountKey()));
            $targetDb = getDbConnection($targetAccount);
            $settings = getProlineSettings($targetDb, $targetAccount);
            $logFile = __DIR__ . '/proline_relay.log';
            $recentLogs = [];
            if (file_exists($logFile)) {
                $lines = array_map('trim', file($logFile));
                $lines = array_filter($lines);
                $recentLogs = array_slice(array_reverse($lines), 0, 15);
            }

            echo json_encode([
                'success' => true,
                'account' => $targetAccount,
                'settings' => $settings,
                'recent_logs' => $recentLogs
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 20. プロライン連携設定保存 ---
        case 'admin_save_proline_settings':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $targetAccount = trim($_POST['account'] ?? ($_GET['account'] ?? getActiveAccountKey()));
            $targetDb = getDbConnection($targetAccount);
            $url = $_POST['url'] ?? '';
            $enabled = isset($_POST['relay_enabled']) ? (bool)(int)$_POST['relay_enabled'] : true;
            $calendarUrl = $_POST['calendar_url'] ?? '';

            $result = saveProlineSettings($url, $enabled, $calendarUrl, $targetDb, $targetAccount);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 21. プロライン＆外部ツールWebhook中継 疎通テスト送信 ---
        case 'admin_test_proline_relay':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $targetAccount = trim($_POST['account'] ?? ($_GET['account'] ?? getActiveAccountKey()));
            $targetDb = getDbConnection($targetAccount);

            $rawTargetUrl = trim($_POST['url'] ?? '');
            if (empty($rawTargetUrl)) {
                $cur = getProlineSettings($targetDb, $targetAccount);
                $rawTargetUrl = $cur['webhook_url'];
            }

            $targetUrls = parseWebhookUrls($rawTargetUrl);
            if (empty($targetUrls)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '転送先のWebhook URL（https://...）を入力してください']);
                exit;
            }

            // LINE DevelopersからのPingモックペイロード
            $mockPayload = json_encode([
                'destination' => 'U' . str_repeat('0', 32),
                'events' => []
            ], JSON_UNESCAPED_UNICODE);

            $channelSecret = getLineChannelSecret($targetAccount);
            $mockSignature = !empty($channelSecret) ? base64_encode(hash_hmac('sha256', $mockPayload, $channelSecret, true)) : '';
            $mockSignature = !empty($channelSecret) ? base64_encode(hash_hmac('sha256', $mockPayload, $channelSecret, true)) : '';

            $testResults = [];
            $allSuccess = true;
            $nowJst = date('Y-m-d H:i:s');

            foreach ($targetUrls as $url) {
                $startTime = microtime(true);
                $ch = curl_init($url);
                $headers = [
                    'Content-Type: application/json; charset=utf-8',
                    'User-Agent: LineBotWebhook/2.0'
                ];
                if (!empty($mockSignature)) {
                    $headers[] = 'X-Line-Signature: ' . $mockSignature;
                    $headers[] = 'x-line-signature: ' . $mockSignature;
                }

                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $mockPayload,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 6,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);
                $res = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                $durationMs = round((microtime(true) - $startTime) * 1000, 2);
                curl_close($ch);

                $isSuccess = ($httpCode >= 200 && $httpCode < 400);
                if (!$isSuccess) {
                    $allSuccess = false;
                }

                $statusText = $isSuccess ? "TEST OK ({$durationMs}ms)" : "TEST FAIL ({$httpCode}: {$curlErr})";
                @file_put_contents(__DIR__ . '/proline_relay.log', "[{$nowJst}] MANUAL_TEST: {$statusText} | URL: {$url}\n", FILE_APPEND | LOCK_EX);

                $testResults[] = [
                    'url' => $url,
                    'success' => $isSuccess,
                    'http_code' => $httpCode,
                    'duration_ms' => $durationMs,
                    'error' => $curlErr,
                    'response_snippet' => mb_substr((string)$res, 0, 150)
                ];
            }

            $successCount = count(array_filter($testResults, fn($r) => $r['success']));
            $totalCount = count($testResults);

            $msg = ($totalCount === 1)
                ? ($allSuccess 
                    ? "✅ 疎通テストに成功しました！(HTTP {$testResults[0]['http_code']} / {$testResults[0]['duration_ms']}ms)" 
                    : "⚠️ 転送先からの応答エラー (HTTP {$testResults[0]['http_code']}): " . ($testResults[0]['error'] ?: '応答ステータスをご確認ください'))
                : "疎通テスト完了: {$successCount} / {$totalCount} 件が成功";

            echo json_encode([
                'success' => $allSuccess,
                'total_count' => $totalCount,
                'success_count' => $successCount,
                'results' => $testResults,
                'message' => $msg
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 16. 会社DXアンケート送信 (個別 / 一斉配信) ---
        case 'send_dx_survey':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'パスワードが違います'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            require_once __DIR__ . '/webhook.php';

            $targetUid = trim($_POST['user_id'] ?? '');
            $q1Message = buildDxSurveyQ1Message();

            if (!empty($targetUid)) {
                // 個別配信
                $res = sendLinePushMessage($targetUid, [$q1Message]);
                if (!empty($res['success'])) {
                    recordCustomerInteraction($db, $targetUid, 'admin_action', '📋 DXアンケート送信');
                    echo json_encode(['success' => true, 'message' => 'DXアンケートを送信しました！', 'count' => 1], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode(['success' => false, 'error' => $res['error'] ?? 'LINE送信に失敗しました'], JSON_UNESCAPED_UNICODE);
                }
                exit;
            } else {
                // 一斉配信（現在のアカウントの全友だち）
                $stmt = $db->query("SELECT DISTINCT user_id FROM customer_cars WHERE user_id LIKE 'U%'");
                $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (empty($users)) {
                    echo json_encode(['success' => false, 'error' => '送信対象の友だち（LINEユーザー）が登録されていません'], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                $sentCount = 0;
                $failCount = 0;
                foreach ($users as $uid) {
                    $res = sendLinePushMessage($uid, [$q1Message]);
                    if (!empty($res['success'])) {
                        $sentCount++;
                        recordCustomerInteraction($db, $uid, 'admin_action', '📋 DXアンケート一斉配信');
                    } else {
                        $failCount++;
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => "DXアンケートを一斉配信しました（送信成功: {$sentCount}名" . ($failCount > 0 ? "、失敗: {$failCount}名" : "") . "）",
                    'sent_count' => $sentCount,
                    'fail_count' => $failCount,
                    'total' => count($users)
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

        // --- 19. プロライン フォーム・予約 Webhook 受信 (APIフォールバック) ---
        case 'proline_event_webhook':
            require __DIR__ . '/proline_webhook.php';
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '無効なアクションです。']);
            break;
    }
} catch (Throwable $e) {
    if (function_exists('writeDebugLog')) {
        writeDebugLog("API 最外側例外", [
            'action' => $_REQUEST['action'] ?? 'unknown',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
    
    // get_unread_chat_counts などの定期ポーリング系は UI をクラッシュさせないため 200 で空データを返却
    $curAction = $_REQUEST['action'] ?? '';
    if ($curAction === 'get_unread_chat_counts') {
        http_response_code(200);
        echo json_encode(['success' => true, 'unread_counts' => [], 'total_unread' => 0, 'recent_unread' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'サーバー内部エラーが発生しました: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
