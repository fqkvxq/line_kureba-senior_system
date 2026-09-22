<?php
/**
 * LINE Messaging API Webhook ハンドラー
 * LINE公式アカウントからのメッセージを受信し、データベースの車両情報をFlex Messageで返信します。
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/webhook_handlers.php';

// Webhookの対象アカウントをクエリパラメータから特定 (未指定時はデフォルトアカウント)
$webhookAccount = $_GET['account'] ?? ($_REQUEST['account'] ?? null);
if (!empty($webhookAccount)) {
    setActiveAccountKey($webhookAccount);
}
$activeAccount = getActiveAccountKey();
$activeConfig = getAccountConfig($activeAccount);
$channelAccessToken = getLineAccessToken($activeAccount);
$channelSecret = getLineChannelSecret($activeAccount);

// --- ブラウザ等からの直接GETアクセスの場合は診断画面を表示 ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    
    // DB状態確認
    $dbStatus = 'エラー';
    $studentCount = 0;
    $db = null;
    try {
        $db = getDbConnection($activeAccount);
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM customer_cars");
        $studentCount = (int)$stmt->fetch()['cnt'];
        $dbStatus = "正常稼働中 (受講生登録: {$studentCount}名)";
    } catch (Exception $e) {
        $dbStatus = "接続失敗: " . htmlspecialchars($e->getMessage());
    }

    $tokenConfigured = (!empty($channelAccessToken) && $channelAccessToken !== 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') ? '<span style="color:green;">設定済み</span>' : '<span style="color:red;">未設定 (config.phpに貼り付けてください)</span>';
    $secretConfigured = (!empty($channelSecret) && $channelSecret !== 'YOUR_CHANNEL_SECRET_HERE') ? '<span style="color:green;">設定済み</span>' : '<span style="color:red;">未設定</span>';
    
    // プロライン＆外部ツール連携状態
    $proline = getProlineSettings($db);
    $urlCount = count($proline['webhook_urls'] ?? []);
    $prolineStatusBadge = empty($urlCount)
        ? '<span style="color:#64748b;">未設定 (中継OFF)</span>'
        : ($proline['relay_enabled'] 
            ? "<span style=\"color:green;font-weight:bold;\">中継稼働中 ({$urlCount}件へ同時転送)</span>" 
            : '<span style="color:#d97706;font-weight:bold;">中継一時停止中 (無効)</span>');
    
    $urlsFormatted = !empty($proline['webhook_urls'])
        ? implode('<br>', array_map(fn($u) => '<code style="font-size:11px; word-break:break-all;">' . htmlspecialchars($u) . '</code>', $proline['webhook_urls']))
        : '（未登録）';
    $prolineLastRelay = !empty($proline['last_relay_at']) ? "{$proline['last_relay_at']} / {$proline['last_relay_status']}" : 'まだ転送履歴はありません';

    $accountListHtml = '';
    foreach (getAccountList() as $acc) {
        $isCurrent = ($acc['id'] === $activeAccount);
        $badge = $isCurrent ? '<strong style="color:#4f46e5;">[現在選択中]</strong>' : '';
        $whUrl = getBaseUrl() . "/webhook.php" . ($acc['is_default'] ? '' : "?account={$acc['id']}");
        $accountListHtml .= "<tr><td>{$acc['name']} ({$acc['id']}) {$badge}</td><td><code style='font-size:11px;'>{$whUrl}</code></td></tr>";
    }

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="ja">
    <head><meta charset="utf-8"><title>LINE受講生管理 ＆ プロライン・外部ツール中継 診断</title>
    <style>body{font-family:sans-serif;padding:30px;line-height:1.6;background:#f8fafc;color:#1e293b}
    .card{background:#fff;padding:24px;border-radius:12px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);max-width:760px;margin:0 auto}
    h2{margin-top:0;color:#06C755}table{width:100%;border-collapse:collapse;margin:16px 0}
    td,th{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:14px}
    .log-box{background:#0f172a;color:#a5f3fc;padding:12px;border-radius:8px;font-family:monospace;font-size:12px;max-height:260px;overflow-y:auto;white-space:pre-wrap}
    .tag{display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;background:#e2e8f0}
    </style></head>
    <body>
    <div class="card">
        <h2>💻 LINE Webhook 稼働ステータス (対象: {$activeConfig['name']})</h2>
        <table>
            <tr><th>項目</th><th>状態</th></tr>
            <tr><td>対象アカウントID</td><td><code>{$activeAccount}</code> ({$activeConfig['name']})</td></tr>
            <tr><td>Webhook エンドポイント</td><td>正常応答中 (200 OK)</td></tr>
            <tr><td>チャネルアクセストークン</td><td>{$tokenConfigured}</td></tr>
            <tr><td>チャネルシークレット</td><td>{$secretConfigured}</td></tr>
            <tr><td>受講生データベース状態</td><td><strong>{$dbStatus}</strong></td></tr>
            <tr><td>外部ツール中継ステータス</td><td>{$prolineStatusBadge}</td></tr>
            <tr><td>転送先Webhook URL一覧</td><td><div style="line-height:1.5;">{$urlsFormatted}</div></td></tr>
            <tr><td>直近の転送結果</td><td><small>{$prolineLastRelay}</small></td></tr>
        </table>

        <h3>🔗 登録アカウントごとの Webhook URL 一覧</h3>
        <table>
            <tr><th>アカウント名</th><th>LINE Developers登録用 Webhook URL</th></tr>
            {$accountListHtml}
        </table>
HTML;
    $prolineLogFile = __DIR__ . '/proline_relay.log';
    if (file_exists($prolineLogFile)) {
        $lines = array_slice(file($prolineLogFile), -20);
        $prolineLogContent = htmlspecialchars(implode('', $lines));
    } else {
        $prolineLogContent = "プロラインへの転送ログはまだありません。LINEでイベントが発生すると記録されます。\n";
    }

    $logFile = __DIR__ . '/webhook_debug.log';
    if (file_exists($logFile)) {
        $lines = array_slice(file($logFile), -20);
        $sysLogContent = htmlspecialchars(implode('', $lines));
    } else {
        $sysLogContent = "ログはまだありません。";
    }

    echo <<<HTML
        <h3>📋 プロライン転送ログ (最新20件)</h3>
        <div class="log-box">{$prolineLogContent}</div>
        <h3 style="margin-top:20px;">📋 システムデバッグログ (最新20件)</h3>
        <div class="log-box">{$sysLogContent}</div>
    </div>
    </body></html>
HTML;
    exit;
}

// --- Webhookリクエスト受信時のエントリポイント実行 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 生のリクエストボディを取得
    $rawInput = file_get_contents('php://input');
    
    // LINE署名ヘッダーを多重フォールバックで確実に取得
    $lineSignature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? ($_SERVER['REDIRECT_HTTP_X_LINE_SIGNATURE'] ?? '');
    if (empty($lineSignature) && function_exists('getallheaders')) {
        $hdrs = @getallheaders();
        if (is_array($hdrs)) {
            foreach ($hdrs as $k => $v) {
                if (strcasecmp($k, 'x-line-signature') === 0) {
                    $lineSignature = (string)$v;
                    break;
                }
            }
        }
    }
    if (empty($lineSignature) && function_exists('apache_request_headers')) {
        $hdrs = @apache_request_headers();
        if (is_array($hdrs)) {
            foreach ($hdrs as $k => $v) {
                if (strcasecmp($k, 'x-line-signature') === 0) {
                    $lineSignature = (string)$v;
                    break;
                }
            }
        }
    }

    // 署名ヘッダーが空でもChannel Secretがあれば自動補完
    if (empty($lineSignature) && !empty($channelSecret) && $channelSecret !== 'YOUR_CHANNEL_SECRET_HERE') {
        $lineSignature = base64_encode(hash_hmac('sha256', $rawInput, $channelSecret, true));
    }

    $sigValid = false;
    if (!empty($channelSecret) && $channelSecret !== 'YOUR_CHANNEL_SECRET_HERE' && !empty($lineSignature)) {
        $hash = base64_encode(hash_hmac('sha256', $rawInput, $channelSecret, true));
        $sigValid = hash_equals($hash, trim($lineSignature));
    }

    writeDebugLog("Webhook受信", [
        'account' => $activeAccount,
        'bytes' => strlen($rawInput),
        'has_sig' => !empty($lineSignature),
        'sig_valid' => $sigValid,
        'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);

    // Discord デバッグ通知: 1. Webhook 受信開始
    sendDiscordDebugNotification("📥 【LINE Webhook】リクエスト受信", [
        'アカウント' => "{$activeConfig['name']} (`{$activeAccount}`)",
        'サイズ' => strlen($rawInput) . ' bytes',
        '署名ヘッダー' => !empty($lineSignature) ? 'あり' : 'なし',
        '署名検証' => $sigValid ? '✅ 一致 (Valid)' : '⚠️ 不一致/未検証 (継続処理)',
        '送信元IP' => $_SERVER['REMOTE_ADDR'] ?? '不明'
    ], 0x3B82F6, strlen($rawInput) > 0 ? $rawInput : null);

    // 1. データベース接続の確立
    $db = null;
    try {
        $db = getDbConnection($activeAccount);
        // チャットメッセージテーブルの自動作成保証 (単一SQLごとに安全に実行)
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
        } catch (Throwable $tblEx) {}
    } catch (Throwable $e) {
        writeDebugLog("DB接続例外: " . $e->getMessage(), ['account' => $activeAccount]);
        sendDiscordDebugNotification("⚠️ 【DB接続例外】", [
            'アカウント' => $activeAccount,
            'エラー' => $e->getMessage()
        ], 0xEF4444);
    }

    $data = json_decode($rawInput, true);
    if (empty($data['events'])) {
        writeDebugLog("イベントなし (検証Pingなど - 200 OK返却)");
        sendDiscordDebugNotification("🧪 【LINE Webhook】接続検証Ping (Events空 - 正常200OK返却)", [
            'アカウント' => $activeAccount,
            '内容' => 'LINE Developersの検証ボタンまたはWebhookテストPing'
        ], 0x10B981);

        // プロライン中継
        if ($db) {
            $relayRes = relayWebhookToProline($rawInput, $lineSignature, $db, $activeAccount);
            if (!empty($relayRes['urls_sent'])) {
                sendDiscordDebugNotification("🔀 【プロライン中継】検証Ping転送結果", [
                    '転送件数' => count($relayRes['urls_sent']) . ' 件',
                    '結果一覧' => $relayRes['results'] ?? []
                ], 0x8B5CF6);
            }
        }
        http_response_code(200);
        echo 'OK (No events)';
        exit;
    }

    $eventCount = count($data['events']);
    $prolineSettings = $db ? getProlineSettings($db, $activeAccount) : [];
    $isProlineActive = (!empty($prolineSettings['webhook_urls']) && !empty($prolineSettings['relay_enabled']));

    // 全登録アカウントのキー一覧を取得（マルチアカウント間でチャットを取りこぼさないため）
    $allAccountKeys = [$activeAccount];
    if (function_exists('getAccountList')) {
        foreach (getAccountList() as $accItem) {
            if (!empty($accItem['id'])) {
                $allAccountKeys[] = $accItem['id'];
            }
        }
    }
    $allAccountKeys = array_values(array_unique($allAccountKeys));

    // 2. 【最優先】LINEメッセージ・イベントを即座に全ローカルデータベースに保存・反映
    foreach ($data['events'] as $idx => $event) {
        $replyToken = $event['replyToken'] ?? null;
        $userId = trim($event['source']['userId'] ?? '');
        $type = $event['type'] ?? '';

        writeDebugLog("イベント処理開始", ['type' => $type, 'userId' => $userId, 'account' => $activeAccount, 'event_index' => $idx + 1]);

        if (empty($userId)) continue;

        try {
            if ($type === 'message') {
                $msgType = $event['message']['type'] ?? 'text';
                $messageId = $event['message']['id'] ?? '';
                $userText = '';
                $preview = '';
                $imageUrl = '';
                $rawPayload = $event['message'] ?? [];

                if ($msgType === 'text') {
                    $userText = trim($event['message']['text'] ?? '');
                    $preview = "💬 " . mb_substr($userText, 0, 45);
                } elseif ($msgType === 'sticker') {
                    $userText = '🎨 スタンプを受信しました';
                    $preview = '🎨 スタンプを受信';
                } elseif ($msgType === 'image') {
                    $userText = '📷 画像を受信しました';
                    $preview = '📷 画像を受信';
                    // 画像ダウンロード (安全にtry-catch)
                    try {
                        if (!empty($messageId) && function_exists('downloadLineMessageContent')) {
                            $dlRes = downloadLineMessageContent($messageId, $activeAccount);
                            if (!empty($dlRes['success']) && !empty($dlRes['url'])) {
                                $imageUrl = $dlRes['url'];
                                $rawPayload['url'] = $imageUrl;
                                $rawPayload['file_name'] = $dlRes['file_name'] ?? '';
                                $rawPayload['file_path'] = $dlRes['file_path'] ?? '';
                            }
                        }
                    } catch (Throwable $dlEx) {
                        writeDebugLog("画像保存例外", ['error' => $dlEx->getMessage()]);
                    }
                } elseif ($msgType === 'video' || $msgType === 'audio' || $msgType === 'file') {
                    $mediaLabel = ($msgType === 'video' ? '🎬 動画' : ($msgType === 'audio' ? '🎵 音声' : '📎 ファイル'));
                    $userText = "{$mediaLabel}を受信しました";
                    $preview = "📎 {$msgType}を受信";
                    try {
                        if (!empty($messageId) && function_exists('downloadLineMessageContent')) {
                            $dlRes = downloadLineMessageContent($messageId, $activeAccount);
                            if (!empty($dlRes['success']) && !empty($dlRes['url'])) {
                                $imageUrl = $dlRes['url'];
                                $rawPayload['url'] = $imageUrl;
                            }
                        }
                    } catch (Throwable $mEx) {}
                } else {
                    $userText = '📎 メッセージを受信しました';
                    $preview = '📎 メッセージを受信';
                }

                $payloadJson = json_encode($rawPayload, JSON_UNESCAPED_UNICODE);
                $nowJst = date('Y-m-d H:i:s');

                // A. チャットメッセージ履歴テーブルへ即座に確実に保存 (最優先・全アカウントDBへ完全同期)
                $dbSavedCount = 0;
                foreach ($allAccountKeys as $targetAccKey) {
                    try {
                        $targetDb = ($targetAccKey === $activeAccount && $db) ? $db : getDbConnection($targetAccKey);
                        
                        // chat_messagesテーブルの存在を保証 (単一SQLごとに安全に実行)
                        try {
                            $targetDb->exec("
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
                            $targetDb->exec("CREATE INDEX IF NOT EXISTS idx_chat_uid ON chat_messages (user_id)");
                            $targetDb->exec("CREATE INDEX IF NOT EXISTS idx_chat_read ON chat_messages (direction, is_read)");
                        } catch (Throwable $tExx) {}

                        // 1. メッセージ保存
                        $chatStmt = $targetDb->prepare("
                            INSERT INTO chat_messages (
                                user_id, direction, message_type, message_text, payload_json, is_read, created_at
                            ) VALUES (
                                :uid, 'incoming', :mtype, :mtext, :payload, 0, :now
                            )
                        ");
                        $chatStmt->execute([
                            ':uid' => $userId,
                            ':mtype' => $msgType,
                            ':mtext' => $userText,
                            ':payload' => $payloadJson,
                            ':now' => $nowJst
                        ]);

                        // 2. 顧客カルテの自動登録・更新
                        ensureCustomerExists($targetDb, $userId, $targetAccKey);

                        $targetDb->prepare("
                            UPDATE customer_cars 
                            SET is_blocked = 0, 
                                blocked_at = NULL, 
                                last_interaction_at = :now, 
                                last_interaction_type = 'user_message', 
                                last_interaction_preview = :prev 
                            WHERE TRIM(user_id) = :uid
                        ")->execute([':now' => $nowJst, ':prev' => $preview, ':uid' => $userId]);

                        $dbSavedCount++;
                        writeDebugLog("チャットメッセージDB保存完了 ({$targetAccKey})", ['uid' => $userId, 'text' => $userText, 'msgType' => $msgType]);
                    } catch (Throwable $chatEx) {
                        writeDebugLog("chat_messages 保存エラー ({$targetAccKey})", ['error' => $chatEx->getMessage()]);
                    }
                }

                // C. 顧客プロファイルの取得 & 管理者マルチ通知送信
                $userName = 'LINE受講生';
                $picUrl = '';
                $userProfile = null;
                try {
                    if ($db) {
                        $cStmt = $db->prepare("SELECT user_name, picture_url FROM customer_cars WHERE TRIM(user_id) = :uid LIMIT 1");
                        $cStmt->execute([':uid' => $userId]);
                        $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                        if (!empty($cRow['user_name'])) $userName = $cRow['user_name'];
                        if (!empty($cRow['picture_url'])) $picUrl = $cRow['picture_url'];
                    }

                    if (empty($userName) || $userName === '受講生' || $userName === 'LINE受講生') {
                        $userProfile = getLineUserProfile($userId, $activeAccount);
                        if (!empty($userProfile['displayName'])) $userName = $userProfile['displayName'];
                        if (!empty($userProfile['pictureUrl'])) $picUrl = $userProfile['pictureUrl'];
                    }

                    $msgDataPayload = [
                        'user_id' => $userId,
                        'user_name' => $userName,
                        'picture_url' => $picUrl,
                        'message_text' => $userText,
                        'message_type' => $msgType,
                        'image_url' => $imageUrl
                    ];

                    // 管理者LINE Push通知
                    if (function_exists('sendAdminLineChatMessageNotification') && $db) {
                        sendAdminLineChatMessageNotification($msgDataPayload, $userProfile, $db);
                    }

                    // Discord & Slack 通知 (通常チャット通知)
                    if ($db) {
                        sendDiscordChatMessageNotification($msgDataPayload, $userProfile, $db);
                        sendSlackChatMessageNotification($msgDataPayload, $userProfile, $db);
                    }

                    // 🔔 ブラウザ WebPush 通知
                    if (function_exists('sendWebPushChatMessageNotification') && $db) {
                        sendWebPushChatMessageNotification($msgDataPayload, $db, $activeAccount);
                    }
                } catch (Throwable $disEx) {
                    writeDebugLog("チャット通知送信エラー", ['error' => $disEx->getMessage()]);
                }

                // Discord デバッグ通知: 2. LINEメッセージ処理 & DB保存完了
                sendDiscordDebugNotification("💬 【LINE受信】メッセージ処理 & DB保存完了", [
                    '受講生' => "{$userName} 様 (`{$userId}`)",
                    'メッセージ種別' => $msgType,
                    '受信本文' => $userText,
                    'DB保存' => "✅ {$dbSavedCount} 箇所のアカウントDBに保存完了",
                    '画像添付' => !empty($imageUrl) ? $imageUrl : 'なし',
                    'プロライン中継' => $isProlineActive ? '有効 (後続処理で中継)' : '無効 (自動応答または待機)'
                ], 0x10B981, json_encode($event, JSON_UNESCAPED_UNICODE));

                // D. プロライン中継が無効な場合の自動テキスト応答
                if (!$isProlineActive && !empty($replyToken) && $db) {
                    handleTextMessage($db, $replyToken, $userText, $userId);
                }

            } elseif ($type === 'follow') {
                // 友だち追加・ブロック解除時
                recordCustomerInteraction($db, $userId, 'follow', "✨ 友だち追加");
                $uName = 'LINE受講生';
                try {
                    $prof = getLineUserProfile($userId, $activeAccount);
                    $uName = $prof['displayName'] ?? 'LINE受講生';
                    $pUrl = $prof['pictureUrl'] ?? '';

                    $db->prepare("UPDATE customer_cars SET user_name = :uname, picture_url = :pic, is_blocked = 0, blocked_at = NULL, updated_at = :now WHERE TRIM(user_id) = :uid")
                        ->execute([':uname' => $uName, ':pic' => $pUrl, ':now' => date('Y-m-d H:i:s'), ':uid' => $userId]);

                    if (function_exists('sendAdminLineFollowNotification')) {
                        sendAdminLineFollowNotification($userId, $uName, $db);
                    }
                    sendSlackFollowNotification([
                        'user_id' => $userId,
                        'user_name' => $uName,
                        'picture_url' => $pUrl,
                        'event_text' => '新しいユーザーが友だち追加（またはブロック解除）しました！'
                    ], $prof, $db);
                    if (function_exists('sendDiscordNotification')) {
                        sendDiscordNotification($db, "✨【LINE】友だち追加・ブロック解除", "受講生: {$uName} 様\nLINE UID: {$userId}\n日時: " . date('Y-m-d H:i:s'), '#10b981');
                    }
                } catch (Throwable $sEx) {
                    writeDebugLog("フォロー通知エラー", ['error' => $sEx->getMessage()]);
                }

                // Discord デバッグ通知: 3. 友だち追加
                sendDiscordDebugNotification("✨ 【LINE受信】友だち追加・ブロック解除", [
                    '受講生' => "{$uName} 様 (`{$userId}`)",
                    'アカウント' => $activeAccount,
                    'ステータス' => '✅ カルテ登録 & ブロック解除完了'
                ], 0x10B981, json_encode($event, JSON_UNESCAPED_UNICODE));

                // プロライン中継が無効な場合の自動フォロー応答
                if (!$isProlineActive && !empty($replyToken)) {
                    handleFollow($replyToken, $userId);
                }

            } elseif ($type === 'unfollow') {
                $nowJst = date('Y-m-d H:i:s');
                try {
                    $db->prepare("
                        UPDATE customer_cars 
                        SET is_blocked = 1,
                            blocked_at = :blocked_at,
                            last_interaction_at = :last_at,
                            last_interaction_type = 'unfollow',
                            last_interaction_preview = '🚫 ブロック',
                            updated_at = :up_at
                        WHERE TRIM(user_id) = :uid
                    ")->execute([
                        ':blocked_at' => $nowJst,
                        ':last_at' => $nowJst,
                        ':up_at' => $nowJst,
                        ':uid' => $userId
                    ]);
                } catch (Throwable $dbEx) {}
                recordCustomerInteraction($db, $userId, 'unfollow', "🚫 ブロック");

                // Discord デバッグ通知: 4. ブロック
                sendDiscordDebugNotification("🚫 【LINE受信】ユーザーがブロックしました", [
                    'LINE UID' => $userId,
                    'アカウント' => $activeAccount,
                    'ステータス' => 'カルテをブロック状態に更新'
                ], 0x64748B, json_encode($event, JSON_UNESCAPED_UNICODE));

            } elseif ($type === 'postback') {
                $postbackData = $event['postback']['data'] ?? '';
                writeDebugLog("ポストバック受信", ['data' => $postbackData, 'userId' => $userId]);

                // ポストバック種別のプレビュー生成
                parse_str(ltrim($postbackData, '?'), $pbParams);
                $pbAction = $pbParams['action'] ?? '';
                $actionLabel = '⚡ メニュー操作';
                if (str_contains($pbAction, 'inquiry')) {
                    $actionLabel = '🚗 在庫問い合わせ';
                } elseif (str_contains($pbAction, 'maintenance')) {
                    $mType = $pbParams['type'] ?? '';
                    $actionLabel = ($mType === 'oil') ? '🛢️ オイル交換相談' : (($mType === 'inspection') ? '🚗 車検予約相談' : '📋 点検予約相談');
                } elseif ($pbAction === 'open_mycar') {
                    $actionLabel = '📱 マイカーメニュー表示';
                } elseif ($pbAction === 'search_all') {
                    $actionLabel = '🔍 在庫車両一覧の閲覧';
                } elseif ($pbAction === 'notice') {
                    $actionLabel = '📢 お知らせの確認';
                }

                // 操作があった＝確実にブロック解除
                try {
                    $db->prepare("UPDATE customer_cars SET is_blocked = 0, blocked_at = NULL WHERE TRIM(user_id) = :uid")
                        ->execute([':uid' => $userId]);
                } catch (Throwable $e) {}
                recordCustomerInteraction($db, $userId, 'user_action', $actionLabel);

                // Discord デバッグ通知: 5. ポストバック
                sendDiscordDebugNotification("⚡ 【LINE受信】ポストバック操作", [
                    'LINE UID' => $userId,
                    '操作種別' => $actionLabel,
                    'Postback Data' => $postbackData
                ], 0x3B82F6, json_encode($event, JSON_UNESCAPED_UNICODE));

                handlePostback($db, $replyToken, $postbackData, $userId, $event['postback']['params'] ?? []);
            }
        } catch (Throwable $e) {
            writeDebugLog("イベント処理例外エラー", [
                'type' => $type,
                'userId' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            sendDiscordDebugNotification("❌ 【LINE Webhook処理例外】", [
                'イベント種別' => $type,
                'LINE UID' => $userId,
                'エラー内容' => $e->getMessage(),
                '発生ファイル' => $e->getFile() . ':' . $e->getLine()
            ], 0xEF4444, $e->getTraceAsString());
        }
    }

    // 3. プロライン (ProLine) ＆ 外部ツールへ完全中継（DB保存・通知完了後に安全に並列送信）
    try {
        $prolineRelayResult = relayWebhookToProline($rawInput, $lineSignature, $db, $activeAccount);
        writeDebugLog("外部ツール中継実行", $prolineRelayResult);

        if (!empty($prolineRelayResult['urls_sent'])) {
            $isSuccess = ($prolineRelayResult['status'] === 'success');
            sendDiscordDebugNotification("🔀 【プロライン中継】転送実行結果", [
                '中継ステータス' => $isSuccess ? '✅ 全件中継成功' : '⚠️ 一部または全部失敗',
                '中継先URL数' => count($prolineRelayResult['urls_sent']) . ' 件',
                '転送結果詳細' => $prolineRelayResult['results'] ?? []
            ], $isSuccess ? 0x8B5CF6 : 0xF59E0B);
        }
    } catch (Throwable $prEx) {
        writeDebugLog("プロライン中継例外", ['error' => $prEx->getMessage()]);
        sendDiscordDebugNotification("❌ 【プロライン中継例外】", [
            'エラー' => $prEx->getMessage()
        ], 0xEF4444);
    }

    http_response_code(200);
    echo 'OK';
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    exit;
}
