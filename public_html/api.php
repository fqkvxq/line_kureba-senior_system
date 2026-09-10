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

/**
 * 管理者認証パスワードを取得 (X-Admin-Passwordヘッダー優先、Bearer、POST/GET互換)
 */
function getAdminAuthPassword(): string {
    $pass = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
    if (!$pass && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            $pass = $matches[1];
        }
    }
    if (!$pass) {
        $pass = $_POST['password'] ?? ($_GET['password'] ?? '');
    }
    return trim((string)$pass);
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
                    'is_configured' => ($hasToken && $hasSecret)
                ]
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
                    'is_configured' => ($hasToken && $hasSecret)
                ],
                'accounts' => getAccountList()
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

            $host = $_SERVER['HTTP_HOST'] ?? 'example.com';
            $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
            $webhookUrl = "https://{$host}" . rtrim($scriptDir, '/') . "/webhook.php" . (!empty($accConfig['is_default']) ? '' : "?account={$accConfig['id']}");
            $webhookUrl = str_replace('\\', '/', $webhookUrl);

            echo json_encode([
                'success' => true,
                'account' => [
                    'id' => $accConfig['id'],
                    'name' => $accConfig['name'],
                    'short_name' => $accConfig['short_name'] ?? $accConfig['name'],
                    'theme_color' => $accConfig['theme_color'] ?? '#6366f1',
                    'channel_access_token' => $accConfig['channel_access_token'] ?? '',
                    'channel_secret' => $accConfig['channel_secret'] ?? '',
                    'liff_id' => $accConfig['liff_id'] ?? '',
                    'proline_calendar_url' => $accConfig['proline_calendar_url'] ?? '',
                    'proline_webhook_url' => $accConfig['proline_webhook_url'] ?? '',
                    'db_file' => $accConfig['db_file'] ?? "cars_{$accConfig['id']}.db",
                    'is_default' => !empty($accConfig['is_default']),
                    'webhook_url' => $webhookUrl
                ]
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

            $dbFile = ($cleanId === 'senior') ? 'cars.db' : "cars_{$cleanId}.db";

            $allAccounts[$cleanId] = [
                'id' => $cleanId,
                'name' => $name,
                'short_name' => $shortName,
                'theme_color' => $themeColor,
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

            // 新規アカウントなら該当DBファイルとテーブル構造を自動初期化
            try {
                getDbConnection($cleanId);
            } catch (Exception $e) {
                // 初期化失敗時はログに記録するが設定自体は保持
                error_log("DB init error for account {$cleanId}: " . $e->getMessage());
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
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $uid = trim($_GET['uid'] ?? ($_POST['uid'] ?? ($_GET['user_id'] ?? ($_POST['user_id'] ?? ''))));
            if (empty($uid)) {
                echo json_encode(['success' => false, 'error' => 'ユーザーID(uid)が未指定です']);
                exit;
            }

            // 受講生情報を取得
            $cStmt = $db->prepare("SELECT id, user_id, user_name, picture_url, car_model, car_number FROM customer_cars WHERE user_id = :uid LIMIT 1");
            $cStmt->execute([':uid' => $uid]);
            $customer = $cStmt->fetch(PDO::FETCH_ASSOC);

            // チャット履歴一覧を取得 (古い順)
            $msgStmt = $db->prepare("
                SELECT id, user_id, direction, message_type, message_text, payload_json, is_read, sent_by, created_at 
                FROM chat_messages 
                WHERE user_id = :uid 
                ORDER BY id ASC 
                LIMIT 200
            ");
            $msgStmt->execute([':uid' => $uid]);
            $messages = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

            // 未読メッセージを既読に更新
            $updateRead = $db->prepare("UPDATE chat_messages SET is_read = 1 WHERE user_id = :uid AND direction = 'incoming' AND is_read = 0");
            $updateRead->execute([':uid' => $uid]);

            echo json_encode([
                'success' => true,
                'customer' => $customer ?: ['user_id' => $uid, 'user_name' => 'LINE友だち', 'picture_url' => ''],
                'messages' => $messages
            ], JSON_UNESCAPED_UNICODE);
            exit;

        // --- 0-7. チャットメッセージ送信 (管理画面から受講生のLINEへ返信) ---
        case 'send_chat_message':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                echo json_encode(['success' => false, 'error' => '管理者パスワードが正しくありません']);
                exit;
            }

            $uid = trim($_POST['uid'] ?? ($_POST['user_id'] ?? ''));
            $message = trim($_POST['message'] ?? ($_POST['text'] ?? ''));
            $sentBy = trim($_POST['sent_by'] ?? ($_POST['sender_name'] ?? '教室スタッフ'));

            if (empty($uid) || !str_starts_with($uid, 'U')) {
                echo json_encode(['success' => false, 'error' => '有効なLINE UserID(uid)が必要です']);
                exit;
            }
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'メッセージ本文を入力してください']);
                exit;
            }

            // LINE Messaging API で Push Message 送信
            require_once __DIR__ . '/webhook.php';
            $nowJst = date('Y-m-d H:i:s');

            try {
                $lineResult = sendLinePushMessage($uid, [
                    [
                        'type' => 'text',
                        'text' => $message
                    ]
                ]);

                if (!$lineResult) {
                    echo json_encode(['success' => false, 'error' => 'LINEメッセージの送信に失敗しました。アクセストークン等をご確認ください']);
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

        // --- 0-8. 全受講生の未読メッセージ件数一覧取得 ---
        case 'get_unread_chat_counts':
            try {
                $stmt = $db->query("
                    SELECT user_id, COUNT(*) as unread_count 
                    FROM chat_messages 
                    WHERE direction = 'incoming' AND is_read = 0 
                    GROUP BY user_id
                ");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $counts = [];
                $totalUnread = 0;
                foreach ($rows as $r) {
                    $cnt = (int)$r['unread_count'];
                    $counts[$r['user_id']] = $cnt;
                    $totalUnread += $cnt;
                }

                echo json_encode([
                    'success' => true,
                    'unread_counts' => $counts,
                    'total_unread' => $totalUnread
                ], JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                echo json_encode(['success' => true, 'unread_counts' => [], 'total_unread' => 0]);
            }
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

                // Discordに新規ユーザー登録を通知
                if (function_exists('sendDiscordNewCustomerNotification')) {
                    sendDiscordNewCustomerNotification($userId, $userName);
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

            if ($filter === 'oil_soon') {
                $where[] = "oil_next_date IS NOT NULL AND oil_next_date <= :in30";
                $params[':in30'] = $in30days;
            } elseif ($filter === 'periodic_soon') {
                $where[] = "periodic_insp_next_date IS NOT NULL AND periodic_insp_next_date <= :in30";
                $params[':in30'] = $in30days;
            } elseif ($filter === 'inspection_soon') {
                $where[] = "inspection_next_date IS NOT NULL AND inspection_next_date <= :in30";
                $params[':in30'] = $in30days;
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

            echo json_encode([
                'success' => true,
                'customers' => $customers,
                'total' => count($customers),
                'default_menu_title' => $defaultMenuTitle,
                'active_account' => $activeAccountKey,
                'active_account_name' => getAccountShopName($activeAccountKey)
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
                    ':updated_at' => $nowJst
                ]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO customer_cars (
                        user_id, user_name, car_model, car_number,
                        oil_last_date, oil_next_date, periodic_insp_next_date, inspection_next_date,
                        staff_memo, last_interaction_at, last_interaction_type, last_interaction_preview,
                        created_at, updated_at
                    ) VALUES (
                        :uid, :uname, :car_model, :car_number,
                        :oil_last_date, :oil_next_date, :periodic_next_date, :inspection_next_date,
                        :staff_memo, :now_jst1, 'follow', '手動登録',
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
                    ':now_jst1' => $nowJst,
                    ':now_jst2' => $nowJst,
                    ':now_jst3' => $nowJst
                ]);
            }

            echo json_encode([
                'success' => true,
                'message' => '顧客メンテナンス情報を保存しました！'
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 8-2. 店舗管理者用: LINE既存友だちの一括同期・自動取り込み ---
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
                            last_interaction_at, last_interaction_type, last_interaction_preview,
                            created_at, updated_at
                        ) VALUES (
                            :uid, :uname, :pic, '【未登録】愛車登録待ち', '',
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
                    // 既存顧客: アイコン画像を最新化し、仮名なら名前も最新表示名に同期
                    $isPlaceholderName = empty($existing['user_name']) || in_array($existing['user_name'], ['新規お客様', 'お客様', 'LINE友だち', '']);
                    $currentName = $isPlaceholderName ? $displayName : $existing['user_name'];
                    $currentPic = !empty($pictureUrl) ? $pictureUrl : ($existing['picture_url'] ?? '');

                    $db->prepare("
                        UPDATE customer_cars SET
                            user_name = :uname,
                            picture_url = :pic,
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

            writeDebugLog("LINE既存友だち一括同期完了", [
                'totalFollowers' => count($allUserIds),
                'newImported' => $importedCount,
                'updated' => $updatedCount
            ]);

            echo json_encode([
                'success' => true,
                'total_followers' => count($allUserIds),
                'imported_count' => $importedCount,
                'updated_count' => $updatedCount,
                'message' => "LINE友だち全" . count($allUserIds) . "名を同期しました！（新規追加: {$importedCount}名、名前・アイコン同期: {$updatedCount}名）"
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
            if (function_exists('getSeniorKnowledgeQuickReplyItems')) {
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
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                exit('Rich menu not found');
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
                $imgBinary = lineGetRichMenuImage($menu['line_menu_id']);
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

            // LINE公式アカウントの現在のデフォルトリッチメニューIDを取得
            $currentLineDefaultId = lineGetDefaultRichMenuId();

            // 基本URLの定義
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            $publicBase = rtrim($baseUrl . dirname($_SERVER['SCRIPT_NAME']), '/\\');

            // LINEサーバー上の全リッチメニューを自動取得し、プロライン等の未登録メニューがあれば自動インポート
            $remoteList = lineGetRichMenuList();
            $validRemoteLmids = [];
            if (!empty($remoteList['success']) && !empty($remoteList['richmenus'])) {
                foreach ($remoteList['richmenus'] as $rm) {
                    $lmid = $rm['richMenuId'] ?? '';
                    if (empty($lmid)) continue;
                    $validRemoteLmids[$lmid] = true;

                    // すでにDBに登録済みかチェック
                    $chk = $db->prepare("SELECT id FROM rich_menus WHERE line_menu_id = :lmid LIMIT 1");
                    $chk->execute([':lmid' => $lmid]);
                    if (!$chk->fetch()) {
                        // LINEから画像バイナリを自動取得してローカル保存
                        $imgBin = lineGetRichMenuImage($lmid);
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
                        $menuTitle = $rm['name'] ?? 'プロライン公式メニュー';
                        if ($isDef) {
                            $menuTitle = '★ [現在LINE公開中] ' . $menuTitle;
                        }

                        $db->prepare("
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
            $stmt = $db->query("SELECT * FROM rich_menus ORDER BY id DESC");
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
                    $db->prepare("UPDATE rich_menus SET is_active = 1 WHERE id = :id")->execute([':id' => $m['id']]);
                    $m['is_active'] = 1;
                } elseif (!$m['is_line_default'] && $m['is_active'] && !empty($currentLineDefaultId)) {
                    $db->prepare("UPDATE rich_menus SET is_active = 0 WHERE id = :id")->execute([':id' => $m['id']]);
                    $m['is_active'] = 0;
                }
                // エイリアス未設定の既存メニューがあれば自動生成＆同期
                if (!empty($m['line_menu_id']) && empty($m['alias_id'])) {
                    $genAlias = 'rm_' . substr(md5($m['line_menu_id']), 0, 20);
                    $reg = lineCreateOrUpdateRichMenuAlias($m['line_menu_id'], $genAlias);
                    if ($reg['success']) {
                        $db->prepare("UPDATE rich_menus SET alias_id = :aid WHERE id = :id")->execute([':aid' => $genAlias, ':id' => $m['id']]);
                        $m['alias_id'] = $genAlias;
                    }
                }
                $m['is_notice'] = (int)($m['is_notice'] ?? 0);

                // 画像URLの完全正規化（フルURL化または動的配信フォールバック）
                $localFileName = basename(parse_url($m['image_url'] ?? '', PHP_URL_PATH) ?? '');
                $localFilePath = !empty($localFileName) ? (RICHMENU_UPLOAD_DIR . '/' . $localFileName) : '';
                if (!empty($localFilePath) && file_exists($localFilePath) && filesize($localFilePath) > 0) {
                    $m['image_url'] = $publicBase . '/uploads/richmenu/' . $localFileName;
                } else {
                    $m['image_url'] = '../api.php?action=richmenu_image&id=' . $m['id'];
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
            $activeNotice = getActiveNoticeRichMenu($db);
            $activeNoticeId = $activeNotice ? (int)$activeNotice['id'] : null;

            echo json_encode([
                'success' => true,
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
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}";
            $imageUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/uploads/richmenu/' . $savedFileName;

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
                            $baseImageUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/uploads/richmenu/' . $bFileName;
                            $bResized = true;
                        }
                    }
                    if (!$bResized) {
                        if (move_uploaded_file($uploadedBaseFile['tmp_name'], $bTargetFilePath)) {
                            $baseImageUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/uploads/richmenu/' . $bFileName;
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
            $createRes = lineCreateRichMenu($lineMenuData);
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
            $uploadRes = lineUploadRichMenuImage($lineMenuId, $targetFilePath, $contentType);
            if (!$uploadRes['success']) {
                // ロールバック: 作成したリッチメニューを削除
                lineDeleteRichMenu($lineMenuId);
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
                $stmtExist = $db->prepare("SELECT * FROM rich_menus WHERE id = :id");
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
            lineCreateOrUpdateRichMenuAlias($lineMenuId, $aliasId);

            // 4. LINE API: 本番適用 (publishフラグが真の場合、または既存メニューが元々本番中の場合)
            $isNotice = (!empty($_POST['is_notice']) && $_POST['is_notice'] === '1') ? 1 : ($existingMenu ? (int)$existingMenu['is_notice'] : 0);
            $isActive = 0;
            $applyError = null;

            // 既存メニューが元々本番中だった場合は、更新時に自動で本番も最新メニューへ切り替え
            $shouldApplyLive = $publish || ($existingMenu && (int)$existingMenu['is_active'] === 1 && !$isNotice);

            if ($shouldApplyLive) {
                $setDefRes = lineSetDefaultRichMenu($lineMenuId);
                if ($setDefRes['success']) {
                    $isActive = 1;
                    if ($isNotice) {
                        $db->exec("UPDATE rich_menus SET is_active = 0 WHERE is_notice = 1");
                    } else {
                        $db->exec("UPDATE rich_menus SET is_active = 0 WHERE is_notice = 0");
                    }
                } else {
                    $applyError = $setDefRes['error'] ?? '不明なエラー';
                }
            } elseif ($isNotice) {
                // お知らせ専用メニューとして保存された場合、アクティブお知らせとしてマーク
                $isActive = 1;
                $db->exec("UPDATE rich_menus SET is_active = 0 WHERE is_notice = 1");
            }

            $textOverlaysJson = $_POST['text_overlays'] ?? '[]';
            $textOverlays = json_decode($textOverlaysJson, true);
            if (!is_array($textOverlays)) $textOverlays = [];

            // 5. DBに保存 (既存更新 UPDATE or 新規登録 INSERT)
            if ($existingMenu) {
                $stmt = $db->prepare("
                    UPDATE rich_menus SET
                        line_menu_id = :line_menu_id,
                        alias_id = :alias_id,
                        title = :title,
                        chat_bar_text = :chat_bar_text,
                        image_url = :image_url,
                        base_image_url = :base_image_url,
                        areas_json = :areas_json,
                        text_overlays_json = :text_overlays_json,
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
                    ':width' => $width,
                    ':height' => $height,
                    ':is_active' => $isActive,
                    ':is_notice' => $isNotice,
                    ':id' => $editId
                ]);
                $savedId = $editId;

                // 古いLINEメニューIDをLINE APIから削除して整理
                if (!empty($existingMenu['line_menu_id']) && $existingMenu['line_menu_id'] !== $lineMenuId) {
                    lineDeleteRichMenu($existingMenu['line_menu_id']);
                }

                $msg = 'リッチメニューを上書き保存しました！';
                if ($shouldApplyLive) {
                    $msg = $isActive 
                        ? 'リッチメニューを更新し、LINE本番アカウントに即時反映しました！' 
                        : "リッチメニューは更新されましたが、LINE本番適用でエラーが発生しました: {$applyError}";
                }
            } else {
                $stmt = $db->prepare("
                    INSERT INTO rich_menus (
                        line_menu_id, alias_id, title, chat_bar_text, image_url, base_image_url, areas_json, text_overlays_json,
                        width, height, is_active, is_notice, created_at, updated_at
                    ) VALUES (
                        :line_menu_id, :alias_id, :title, :chat_bar_text, :image_url, :base_image_url, :areas_json, :text_overlays_json,
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
                    ':width' => $width,
                    ':height' => $height,
                    ':is_active' => $isActive,
                    ':is_notice' => $isNotice
                ]);
                $savedId = (int)$db->lastInsertId();

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

            $id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
            $stmt = $db->prepare("SELECT * FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '指定されたメニューが見つかりません']);
                exit;
            }

            // 他のお知らせメニューのis_activeを0にして、このメニューをis_notice=1 & is_active=1にする
            $db->exec("UPDATE rich_menus SET is_active = 0 WHERE is_notice = 1");
            $db->prepare("UPDATE rich_menus SET is_notice = 1, is_active = 1, updated_at = datetime('now', '+9 hours') WHERE id = :id")->execute([':id' => $id]);

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

            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '対象のリッチメニューが見つかりません']);
                exit;
            }

            $targetLineMenuId = $menu['line_menu_id'] ?? '';
            $setRes = !empty($targetLineMenuId) ? lineSetDefaultRichMenu($targetLineMenuId) : ['success' => false, 'error' => 'richmenu not found'];

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

                    $recreateRes = lineCreateRichMenu($lineMenuData);
                    if (!empty($recreateRes['success']) && !empty($recreateRes['richMenuId'])) {
                        $recreatedLmid = $recreateRes['richMenuId'];
                        $uploadedOk = false;
                        if (!empty($imgFilePath) && file_exists($imgFilePath) && filesize($imgFilePath) > 0) {
                            $ext = strtolower(pathinfo($imgFilePath, PATHINFO_EXTENSION));
                            $cType = ($ext === 'png') ? 'image/png' : 'image/jpeg';
                            $upRes = lineUploadRichMenuImage($recreatedLmid, $imgFilePath, $cType);
                            $uploadedOk = !empty($upRes['success']);
                        }

                        if ($uploadedOk) {
                            $targetLineMenuId = $recreatedLmid;
                            $db->prepare("UPDATE rich_menus SET line_menu_id = :lmid, updated_at = datetime('now', '+9 hours') WHERE id = :id")
                               ->execute([':lmid' => $targetLineMenuId, ':id' => $id]);
                            // 再度デフォルト適用実行
                            $setRes = lineSetDefaultRichMenu($targetLineMenuId);
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
            $db->exec("UPDATE rich_menus SET is_active = 0");
            $db->prepare("UPDATE rich_menus SET is_active = 1, updated_at = datetime('now', '+9 hours') WHERE id = :id")->execute([':id' => $id]);

            echo json_encode([
                'success' => true,
                'message' => "「{$menu['title']}」をLINE公式アカウントの本番リッチメニューに適用しました！"
            ], JSON_UNESCAPED_UNICODE);
            break;

        // --- 14. リッチメニュー管理: 削除 ---
        case 'admin_delete_richmenu':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '対象のリッチメニューが見つかりません']);
                exit;
            }

            // LINE側から削除
            if (!empty($menu['line_menu_id'])) {
                lineDeleteRichMenu($menu['line_menu_id']);
            }
            if (!empty($menu['alias_id'])) {
                lineDeleteRichMenuAlias($menu['alias_id']);
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
            $db->prepare("DELETE FROM rich_menus WHERE id = :id")->execute([':id' => $id]);

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

            $id = (int)($_POST['id'] ?? 0);
            $newTitle = trim($_POST['title'] ?? '');
            if (empty($newTitle)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'リッチメニュー名を入力してください']);
                exit;
            }

            $stmt = $db->prepare("SELECT id, title FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '対象のリッチメニューが見つかりません']);
                exit;
            }

            $updateStmt = $db->prepare("UPDATE rich_menus SET title = :title, updated_at = datetime('now', '+9 hours') WHERE id = :id");
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

        // --- 16. 特定ユーザー向け個別リッチメニュー適用 ---
        case 'admin_set_user_custom_richmenu':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

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
                $stmt = $db->prepare("SELECT * FROM rich_menus WHERE id = :id");
                $stmt->execute([':id' => $baseMenuId]);
                $candidate = $stmt->fetch(PDO::FETCH_ASSOC);
                // お知らせメニューでなければ採用
                if ($candidate && empty($candidate['is_notice'])) {
                    $baseMenu = $candidate;
                }
            }
            if (!$baseMenu) {
                // デフォルトとして現在本番中の通常メニューを取得 (is_notice = 0)
                $stmt = $db->query("SELECT * FROM rich_menus WHERE is_active = 1 AND is_notice = 0 ORDER BY id DESC LIMIT 1");
                $baseMenu = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$baseMenu) {
                // さらに無ければ通常メニューの最新を取得 (is_notice = 0)
                $stmt = $db->query("SELECT * FROM rich_menus WHERE is_notice = 0 ORDER BY id DESC LIMIT 1");
                $baseMenu = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$baseMenu) {
                // 最後の手段として全体から取得
                $stmt = $db->query("SELECT * FROM rich_menus ORDER BY id DESC LIMIT 1");
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
                $lineRemote = lineGetRichMenu($lineMenuIdToFetch);
                if (!empty($lineRemote['areas']) && is_array($lineRemote['areas']) && count($lineRemote['areas']) > 0) {
                    $rawAreas = $lineRemote['areas'];
                }
            }

            // 優先順位4: 現在のLINE全体デフォルトリッチメニューから実データを取得
            if (empty($rawAreas)) {
                $currentDefId = lineGetDefaultRichMenuId();
                if (!empty($currentDefId)) {
                    $lineRemote = lineGetRichMenu($currentDefId);
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
                    $calUrl = defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1';
                    $bannerCleanAction = [
                        'type' => 'uri',
                        'uri' => $calUrl,
                        'label' => '予約・日程変更'
                    ];
                } elseif ($bannerActionType === 'mycar_liff') {
                    $liffId = defined('LINE_LIFF_ID') ? LINE_LIFF_ID : (defined('LIFF_ID') ? LIFF_ID : '2000276344-YL1wXh0h');
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
            $stmtCust = $db->prepare("SELECT user_name, custom_line_menu_id FROM customer_cars WHERE user_id = :uid LIMIT 1");
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

            $createRes = lineCreateRichMenu($lineMenuData);
            if (!$createRes['success'] || empty($createRes['richMenuId'])) {
                @unlink($targetFilePath);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'LINEリッチメニュー作成失敗: ' . ($createRes['error'] ?? '')]);
                exit;
            }
            $newLineMenuId = $createRes['richMenuId'];

            // LINE API: 画像アップロード
            $uploadRes = lineUploadRichMenuImage($newLineMenuId, $targetFilePath, $contentType);
            if (!$uploadRes['success']) {
                lineDeleteRichMenu($newLineMenuId);
                @unlink($targetFilePath);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'LINE画像アップロード失敗: ' . ($uploadRes['error'] ?? '')]);
                exit;
            }

            // LINE API: ユーザーへ個別リンク実行！
            $linkRes = lineLinkUserRichMenu($userId, $newLineMenuId);
            if (!$linkRes['success']) {
                lineDeleteRichMenu($newLineMenuId);
                @unlink($targetFilePath);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'ユーザーへの個別メニュー割当失敗: ' . ($linkRes['error'] ?? '')]);
                exit;
            }

            // 以前の古い個別メニューがあれば削除
            if (!empty($oldLineMenuId) && $oldLineMenuId !== $newLineMenuId) {
                lineDeleteRichMenu($oldLineMenuId);
            }

            // DB更新
            $db->prepare("
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
            recordCustomerInteraction($db, $userId, 'custom_menu', "専用メッセージ設定: " . mb_substr($customText, 0, 35));

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

            $userId = trim($_POST['uid'] ?? '');
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }

            // 既存の個別メニューIDを取得
            $stmtCust = $db->prepare("SELECT user_name, custom_line_menu_id FROM customer_cars WHERE user_id = :uid LIMIT 1");
            $stmtCust->execute([':uid' => $userId]);
            $custRow = $stmtCust->fetch(PDO::FETCH_ASSOC);
            $custName = $custRow['user_name'] ?? 'お客様';
            $oldLineMenuId = $custRow['custom_line_menu_id'] ?? '';

            // LINE API: 個別紐付け解除
            $unlinkRes = lineUnlinkUserRichMenu($userId);

            // 既存の全体・作成済みリッチメニューでなければ（個別動的メニューなら）古いLINEメニューを削除
            if (!empty($oldLineMenuId)) {
                $checkExist = $db->prepare("SELECT id FROM rich_menus WHERE line_menu_id = :mid LIMIT 1");
                $checkExist->execute([':mid' => $oldLineMenuId]);
                if (!$checkExist->fetch()) {
                    lineDeleteRichMenu($oldLineMenuId);
                }
            }

            // DB更新
            $db->prepare("
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

            $userId = trim($_GET['uid'] ?? ($_POST['uid'] ?? ''));
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }

            // LINEサーバー上の実態を取得
            $realLineMenuId = lineGetUserRichMenu($userId);

            // 現在LINE公式アカウント全体のデフォルトリッチメニューID
            $currentLineDefaultId = lineGetDefaultRichMenuId();

            // DB上の現在全体デフォルトメニューを検索
            $defaultMenu = null;
            if (!empty($currentLineDefaultId)) {
                $stmtDef = $db->prepare("SELECT id, title, line_menu_id, image_url FROM rich_menus WHERE line_menu_id = :mid LIMIT 1");
                $stmtDef->execute([':mid' => $currentLineDefaultId]);
                $defaultMenu = $stmtDef->fetch(PDO::FETCH_ASSOC);
            }
            if (!$defaultMenu) {
                $defMenuStmt = $db->query("SELECT id, title, line_menu_id, image_url FROM rich_menus WHERE is_active = 1 AND is_notice = 0 ORDER BY id DESC LIMIT 1");
                $defaultMenu = $defMenuStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$defaultMenu) {
                $defMenuStmt = $db->query("SELECT id, title, line_menu_id, image_url FROM rich_menus WHERE is_notice = 0 ORDER BY id DESC LIMIT 1");
                $defaultMenu = $defMenuStmt->fetch(PDO::FETCH_ASSOC);
            }

            $resolvedDefaultTitle = $defaultMenu['title'] ?? '';
            if (empty($resolvedDefaultTitle) && !empty($currentLineDefaultId)) {
                $lineRemote = lineGetRichMenu($currentLineDefaultId);
                $resolvedDefaultTitle = $lineRemote['name'] ?? '通常メニュー';
            }
            if (empty($resolvedDefaultTitle) || $resolvedDefaultTitle === '全体共通メニュー') {
                $resolvedDefaultTitle = '通常メニュー';
            }

            // 顧客テーブルの記録
            $custStmt = $db->prepare("SELECT user_name, custom_line_menu_id, custom_menu_text, custom_menu_set_at FROM customer_cars WHERE user_id = :uid LIMIT 1");
            $custStmt->execute([':uid' => $userId]);
            $cust = $custStmt->fetch(PDO::FETCH_ASSOC);

            $statusType = 'default';
            $menuTitle = $resolvedDefaultTitle;
            $menuImageUrl = $defaultMenu['image_url'] ?? '';
            $menuId = $realLineMenuId ?: ($defaultMenu['line_menu_id'] ?? $currentLineDefaultId);

            if (!empty($realLineMenuId)) {
                // DBの全リッチメニューから照合
                $stmtMenu = $db->prepare("SELECT id, title, image_url, is_notice FROM rich_menus WHERE line_menu_id = :mid LIMIT 1");
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

            $userId = trim($_POST['uid'] ?? '');
            $menuId = (int)($_POST['rich_menu_id'] ?? ($_POST['menu_id'] ?? 0));
            if (empty($userId) || str_starts_with($userId, 'MANUAL_')) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'LINE未連携の顧客にはリッチメニューを適用できません']);
                exit;
            }

            // 指定メニューを取得
            $stmtM = $db->prepare("SELECT * FROM rich_menus WHERE id = :id LIMIT 1");
            $stmtM->execute([':id' => $menuId]);
            $targetMenu = $stmtM->fetch(PDO::FETCH_ASSOC);

            if (!$targetMenu || empty($targetMenu['line_menu_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '指定されたリッチメニューがLINEに未登録です']);
                exit;
            }

            $newLineMenuId = $targetMenu['line_menu_id'];

            // 既存の個別メニューIDを取得
            $stmtCust = $db->prepare("SELECT user_name, custom_line_menu_id, custom_menu_text FROM customer_cars WHERE user_id = :uid LIMIT 1");
            $stmtCust->execute([':uid' => $userId]);
            $custRow = $stmtCust->fetch(PDO::FETCH_ASSOC);
            $custName = $custRow['user_name'] ?? 'お客様';
            $oldLineMenuId = $custRow['custom_line_menu_id'] ?? '';

            // LINE API: 個別リンク実行
            $linkRes = lineLinkUserRichMenu($userId, $newLineMenuId);
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

                    $recreateRes = lineCreateRichMenu($lineMenuData);
                    if (!empty($recreateRes['success']) && !empty($recreateRes['richMenuId'])) {
                        $recreatedLmid = $recreateRes['richMenuId'];
                        $uploadedOk = false;
                        if (!empty($imgFilePath) && file_exists($imgFilePath) && filesize($imgFilePath) > 0) {
                            $ext = strtolower(pathinfo($imgFilePath, PATHINFO_EXTENSION));
                            $cType = ($ext === 'png') ? 'image/png' : 'image/jpeg';
                            $upRes = lineUploadRichMenuImage($recreatedLmid, $imgFilePath, $cType);
                            $uploadedOk = !empty($upRes['success']);
                        }

                        if ($uploadedOk) {
                            $newLineMenuId = $recreatedLmid;
                            $db->prepare("UPDATE rich_menus SET line_menu_id = :lmid, updated_at = datetime('now', '+9 hours') WHERE id = :id")
                               ->execute([':lmid' => $newLineMenuId, ':id' => $targetMenu['id']]);
                            // 再試行
                            $linkRes = lineLinkUserRichMenu($userId, $newLineMenuId);
                        }
                    }
                }
            }

            if (!$linkRes['success']) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => "メニュー割当失敗: 選択されたメニューはLINEサーバー上に存在しないか期限切れです。\nリッチメニュー管理画面で「プロラインからメニュー同期」を実行するか、「専用メッセージ帯付きメニュー」タブから適用してください。（LINEエラー: " . ($linkRes['error'] ?? '') . "）"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 以前のメニューが「専用メッセージメニュー（custom_menu_textあり）」だった場合はLINE上の古い画像メニューを削除
            if (!empty($oldLineMenuId) && $oldLineMenuId !== $newLineMenuId && !empty($custRow['custom_menu_text'])) {
                $checkExist = $db->prepare("SELECT id FROM rich_menus WHERE line_menu_id = :mid LIMIT 1");
                $checkExist->execute([':mid' => $oldLineMenuId]);
                if (!$checkExist->fetch()) {
                    lineDeleteRichMenu($oldLineMenuId);
                }
            }

            // DB更新（専用メッセージテキストはクリア）
            $db->prepare("
                UPDATE customer_cars SET
                    custom_line_menu_id = :lmid,
                    custom_menu_text = '',
                    custom_menu_set_at = datetime('now', '+9 hours')
                WHERE user_id = :uid
            ")->execute([
                ':lmid' => $newLineMenuId,
                ':uid' => $userId
            ]);
            recordCustomerInteraction($db, $userId, 'custom_menu', "個別メニュー割当: {$targetMenu['title']}");

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

            $settings = getProlineSettings($db);
            $logFile = __DIR__ . '/proline_relay.log';
            $recentLogs = [];
            if (file_exists($logFile)) {
                $lines = array_map('trim', file($logFile));
                $lines = array_filter($lines);
                $recentLogs = array_slice(array_reverse($lines), 0, 15);
            }

            echo json_encode([
                'success' => true,
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

            $url = $_POST['url'] ?? '';
            $enabled = isset($_POST['relay_enabled']) ? (bool)(int)$_POST['relay_enabled'] : true;
            $calendarUrl = $_POST['calendar_url'] ?? '';

            $result = saveProlineSettings($url, $enabled, $calendarUrl, $db);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 21. プロラインWebhook中継 疎通テスト送信 ---
        case 'admin_test_proline_relay':
            $authPass = getAdminAuthPassword();
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $targetUrl = trim($_POST['url'] ?? '');
            if (empty($targetUrl)) {
                $cur = getProlineSettings($db);
                $targetUrl = $cur['webhook_url'];
            }

            if (empty($targetUrl)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '転送先のプロラインWebhook URLを入力してください']);
                exit;
            }

            // LINE DevelopersからのPingモックペイロード
            $mockPayload = json_encode([
                'destination' => 'U' . str_repeat('0', 32),
                'events' => []
            ], JSON_UNESCAPED_UNICODE);

            $mockSignature = base64_encode(hash_hmac('sha256', $mockPayload, LINE_CHANNEL_SECRET, true));

            // 一時的に指定URLへテスト中継送信
            $startTime = microtime(true);
            $ch = curl_init($targetUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $mockPayload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json; charset=UTF-8',
                    'X-Line-Signature: ' . $mockSignature,
                    'User-Agent: LineBot-ProLine-Relay-Proxy-Test/1.0'
                ],
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

            // ログ追記
            $nowJst = date('Y-m-d H:i:s');
            $statusText = $isSuccess ? "TEST OK ({$durationMs}ms)" : "TEST FAIL ({$httpCode}: {$curlErr})";
            @file_put_contents(__DIR__ . '/proline_relay.log', "[{$nowJst}] MANUAL_TEST: {$statusText} | URL: {$targetUrl}\n", FILE_APPEND | LOCK_EX);

            echo json_encode([
                'success' => $isSuccess,
                'http_code' => $httpCode,
                'duration_ms' => $durationMs,
                'error' => $curlErr,
                'response_snippet' => mb_substr((string)$res, 0, 200),
                'message' => $isSuccess 
                    ? "✅ プロラインへの疎通テストに成功しました！(HTTP {$httpCode} / {$durationMs}ms)"
                    : "⚠️ プロラインからの応答エラー (HTTP {$httpCode}): " . ($curlErr ?: '応答ステータスをご確認ください')
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '無効なアクションです。']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'サーバー内部エラーが発生しました: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
