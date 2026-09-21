<?php
/**
 * LINE Messaging API Webhook ハンドラー
 * LINE公式アカウントからのメッセージを受信し、データベースの車両情報をFlex Messageで返信します。
 */

require_once __DIR__ . '/config.php';

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
        $whUrl = "https://" . ($_SERVER['HTTP_HOST'] ?? 'example.com') . dirname($_SERVER['SCRIPT_NAME'] ?? '') . "/webhook.php" . ($acc['is_default'] ? '' : "?account={$acc['id']}");
        $whUrl = str_replace('\\', '/', $whUrl);
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'webhook.php' || basename($_SERVER['PHP_SELF'] ?? '') === 'webhook.php')) {
    // 生のリクエストボディを取得
    $rawInput = file_get_contents('php://input');
    $lineSignature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
    writeDebugLog("Webhook受信", ['account' => $activeAccount, 'bytes' => strlen($rawInput)]);

    // 署名検証 (Channel Secretが設定されている場合)
    if (!empty($channelSecret) && $channelSecret !== 'YOUR_CHANNEL_SECRET_HERE' && !empty($lineSignature)) {
        $hash = base64_encode(hash_hmac('sha256', $rawInput, $channelSecret, true));
        if (!hash_equals($hash, $lineSignature)) {
            writeDebugLog("署名検証エラー (Signature mismatch)", ['account' => $activeAccount]);
            http_response_code(403);
            echo 'Invalid signature';
            exit;
        }
    }

    try {
        $db = getDbConnection($activeAccount);
    } catch (Exception $e) {
        writeDebugLog("DB接続例外: " . $e->getMessage(), ['account' => $activeAccount]);
        http_response_code(500);
        exit;
    }

    // 【最重要】プロライン (ProLine) へリクエストを即座に完全中継（プロキシPOST）
    // LINE公式アカウントから届いた生のJSONおよび署名をそのままプロラインのWebhook URLへ転送
    $prolineRelayResult = relayWebhookToProline($rawInput, $lineSignature, $db);
    writeDebugLog("プロライン中継実行", $prolineRelayResult);

    $data = json_decode($rawInput, true);
    if (empty($data['events'])) {
        writeDebugLog("イベントなし (検証Pingなど)");
        http_response_code(200);
        echo 'OK (No events)';
        exit;
    }

    $prolineSettings = getProlineSettings($db);
    $isProlineActive = (!empty($prolineSettings['webhook_url']) && $prolineSettings['relay_enabled']);

    foreach ($data['events'] as $event) {
        $replyToken = $event['replyToken'] ?? null;
        $userId = $event['source']['userId'] ?? '';
        $type = $event['type'] ?? '';
        writeDebugLog("イベント処理開始", ['type' => $type, 'userId' => $userId, 'prolineActive' => $isProlineActive]);

        try {
            // 友だち追加・メッセージ送信・ボタン操作時に自動で受講生管理へ登録＆名前同期
            if (!empty($userId) && str_starts_with($userId, 'U')) {
                ensureCustomerExists($db, $userId);
            }

            if ($type === 'follow') {
                // 友だち追加時: 受講生登録、管理者通知、リッチメニュー自動適用など
                recordCustomerInteraction($db, $userId, 'follow', "友だち登録");
                try {
                    $prof = getLineUserProfile($userId);
                    $uName = $prof['displayName'] ?? '受講生';
                    $pUrl = $prof['pictureUrl'] ?? '';
                    sendSlackFollowNotification([
                        'user_id' => $userId,
                        'user_name' => $uName,
                        'picture_url' => $pUrl,
                        'event_text' => '新しいユーザーが追加されました！'
                    ], $prof, $db);
                } catch (Throwable $sEx) {
                    writeDebugLog("Slackフォロー通知エラー", ['error' => $sEx->getMessage()]);
                }
            } elseif ($type === 'message') {
                $msgType = $event['message']['type'] ?? 'text';
                $userText = '';
                $preview = '';
                $payloadJson = json_encode($event['message'] ?? [], JSON_UNESCAPED_UNICODE);

                if ($msgType === 'text') {
                    $userText = trim($event['message']['text'] ?? '');
                    writeDebugLog("テキスト受信", ['text' => $userText, 'userId' => $userId]);
                    $preview = "💬 " . mb_substr($userText, 0, 45);
                } elseif ($msgType === 'sticker') {
                    $userText = '🎨 スタンプを受信しました';
                    $preview = '🎨 スタンプを受信';
                    writeDebugLog("スタンプ受信", ['userId' => $userId]);
                } elseif ($msgType === 'image') {
                    $userText = '📷 画像を受信しました';
                    $preview = '📷 画像を受信';
                    writeDebugLog("画像受信", ['userId' => $userId]);
                } else {
                    $userText = '📎 メッセージを受信しました';
                    $preview = '📎 メッセージを受信';
                    writeDebugLog("その他メッセージ受信", ['msgType' => $msgType, 'userId' => $userId]);
                }

                // 1. チャットメッセージ履歴テーブルへ保存
                try {
                    $nowJst = date('Y-m-d H:i:s');
                    $chatStmt = $db->prepare("
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
                } catch (Throwable $chatEx) {
                    writeDebugLog("chat_messages 保存エラー", ['error' => $chatEx->getMessage()]);
                }

                // 2. 顧客カルテの最終やり取り更新
                recordCustomerInteraction($db, $userId, 'user_message', $preview);

                // 3. Discord & Slack Webhook通知送信
                try {
                    $cStmt = $db->prepare("SELECT user_name, picture_url FROM customer_cars WHERE user_id = :uid LIMIT 1");
                    $cStmt->execute([':uid' => $userId]);
                    $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
                    $userName = !empty($cRow['user_name']) ? $cRow['user_name'] : '';
                    $picUrl = $cRow['picture_url'] ?? '';

                    $userProfile = null;
                    if (empty($userName) || empty($picUrl) || $userName === '受講生' || $userName === '新規顧客') {
                        $userProfile = getLineUserProfile($userId);
                        if (!empty($userProfile['displayName'])) {
                            $userName = $userProfile['displayName'];
                        }
                        if (!empty($userProfile['pictureUrl'])) {
                            $picUrl = $userProfile['pictureUrl'];
                        }
                    }
                    if (empty($userName)) {
                        $userName = 'LINE受講生';
                    }

                    $msgDataPayload = [
                        'user_id' => $userId,
                        'user_name' => $userName,
                        'picture_url' => $picUrl,
                        'message_text' => $userText,
                        'message_type' => $msgType
                    ];

                    sendDiscordChatMessageNotification($msgDataPayload, $userProfile, $db);
                    sendSlackChatMessageNotification($msgDataPayload, $userProfile, $db);
                } catch (Throwable $disEx) {
                    writeDebugLog("チャット通知送信エラー", ['error' => $disEx->getMessage()]);
                }

                // 4. プロライン非中継時の自動返信（中継時はプロラインへ委譲）
                if ($msgType === 'text') {
                    if ($isProlineActive) {
                        writeDebugLog("プロライン中継モードのため本システムの自動テキスト返信はスキップ（プロラインへ委譲）");
                    } else {
                        if ($replyToken) {
                            handleTextMessage($db, $replyToken, $userText, $userId);
                        }
                    }
                }
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
                recordCustomerInteraction($db, $userId, 'user_action', $actionLabel);

                handlePostback($db, $replyToken, $postbackData, $userId, $event['postback']['params'] ?? []);
            } elseif ($type === 'follow') {
                writeDebugLog("友だち追加イベント", ['userId' => $userId]);
                recordCustomerInteraction($db, $userId, 'follow', '✨ 友だち追加');
                handleFollow($replyToken, $userId);
            }
        } catch (Throwable $e) {
            writeDebugLog("イベント処理例外エラー", [
                'type' => $type,
                'userId' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

// --- イベント処理関数群 ---

/**
 * テキストメッセージの処理
 */
function handleTextMessage(PDO $db, string $replyToken, string $text, string $userId = '') {
    // 0. LIFFからのご来店・ご相談受付メッセージを受信した場合（api.phpで処理済みのため二重返信を防止）
    if (str_contains($text, '【ご来店・ご相談の受付】') || str_contains($text, '【修理・点検・カスタム相談】')) {
        // すでにPush送信・Discord通知済みのため、追加返信は行わず正常終了
        return;
    }

    // 1. LIFFマイカー画面からの予約確定メッセージを受信した場合（例: 【12ヶ月定期点検の来店予約】など）
    if (preg_match('/【(.*?)の来店予約】/u', $text, $m)) {
        $bookingType = $m[1]; // オイル交換, 12ヶ月定期点検, 車検 など
        
        $carModel = '愛車';
        if (preg_match('/愛車:\s*(.+)/u', $text, $carM)) {
            $carModel = trim($carM[1]);
        }
        $prefTime = '希望日時指定あり';
        if (preg_match('/希望日時:\s*(.+)/u', $text, $prefM)) {
            $prefTime = trim($prefM[1]);
        }

        recordCustomerInteraction($db, $userId, 'user_action', "📅 {$bookingType}予約: {$carModel}");
        handleSubmitMaintenanceBooking($replyToken, $bookingType, $carModel, $prefTime, $userId);
        return;
    }

    // --- お役立ち情報の明示キーワード応答 ---
    $cleanText = trim(mb_convert_kana($text, 'asKV', 'UTF-8'));
    if (in_array(mb_strtolower($cleanText), ['pdf', 'pdf詐欺', 'pdf広告', '偽広告'], true)) {
        recordCustomerInteraction($db, $userId, 'user_action', "📄 偽PDF注意カード呼出: {$cleanText}");
        sendKnowledgeDetailMessage($replyToken, 'scam_fake_pdf', $userId, $db);
        return;
    }
    if (in_array($cleanText, ['お役立ち', 'お役立ち情報', '豆知識', '知恵袋', 'スマホ', 'パソコン', 'ガイド', '使い方', '防犯', '詐欺'], true)) {
        recordCustomerInteraction($db, $userId, 'user_action', "💡 お役立ちガイド呼出: {$cleanText}");
        sendKnowledgeMenuMessage($replyToken);
        return;
    }

    // --- 会社DX向けアンケートのキーワード応答 ---
    if (in_array(mb_strtolower($cleanText), ['アンケート', 'dxアンケート', 'dx相談', 'dx診断', 'dx', '関心度'], true)) {
        recordCustomerInteraction($db, $userId, 'user_action', "📋 DXアンケート呼出: {$cleanText}");
        $q1Message = buildDxSurveyQ1Message();
        sendReplyMessage($replyToken, [$q1Message], $userId);
        return;
    }

    // --- キーワード自動応答の停止 ---
    // ※管理者側の通知・チャット妨害防止のため、上記以外の通常テキストメッセージに対するボット自動応答は行わず、
    //   通常のスタッフとの1対1チャットに任せてサイレント終了します。
    writeDebugLog("テキスト受信（スタッフチャット優先のためサイレント終了）", ['text' => $text, 'userId' => $userId]);
    return;
}

/**
 * ポストバックイベントの処理
 */
function handlePostback(PDO $db, string $replyToken, string $dataStr, string $userId = '', array $postbackParams = []) {
    $cleanData = ltrim(trim($dataStr), '?');
    parse_str($cleanData, $params);
    $action = trim($params['action'] ?? '');

    // アクション名が未設定の場合の補正 (エイリアス切替やclose_notice等の直書き対応)
    if (empty($action)) {
        if (!empty($postbackParams['newRichMenuAliasId']) || !empty($params['to_alias']) || !empty($params['alias'])) {
            $action = 'richmenu_switched';
        } elseif (str_contains($cleanData, 'close_notice') || str_contains($cleanData, 'back_normal') || str_contains($cleanData, 'back_main') || str_contains($cleanData, 'close') || str_contains($cleanData, 'back')) {
            $action = 'close_notice';
        }
    }

    writeDebugLog("handlePostback実行", ['action' => $action, 'raw' => $dataStr, 'userId' => $userId, 'postbackParams' => $postbackParams]);

    // お知らせメニュー表示中に「別のアクション（在庫検索や点検予約等）」を実行した場合のみ、自動的にお知らせメニューを解除して復帰
    // ※リッチメニューの切り替えアクション（richmenu_switched等）や、お知らせを開く・閉じる操作自体は絶対に妨害しない！
    $isMenuSwitchOrNoticeAction = in_array($action, [
        'show_notice_menu', 'show_notice', 'notice', 'open_notice',
        'richmenu_switched', 'richmenu_switch', 'switch_tab', 'tab_switch', 'none',
        'close_notice', 'close_notice_menu', 'close_notice_richmenu', 'back_normal', 'back_main', 'back_to_main', 'return_main', 'close', 'back', 'default_menu'
    ]);
    if (!$isMenuSwitchOrNoticeAction && !empty($userId)) {
        $currentLinkedMenuId = lineGetUserRichMenuId($userId);
        if (!empty($currentLinkedMenuId) && isNoticeMenu($db, $currentLinkedMenuId)) {
            writeDebugLog("別アクション実行検知: お知らせメニューから通常メニューへ自動復帰", ['userId' => $userId, 'action' => $action]);
            handleCloseNoticeMenu($db, '', $userId);
        }
    }

    try {
        switch ($action) {
        // --- 1. 車両問い合わせ確認ステップ (誤タップ防止) ---
        case 'ask_inquiry':
            $carId = $params['id'] ?? '';
            sendInquiryConfirmMessage($db, $replyToken, $carId, $userId);
            break;

        // --- 2. 正式問い合わせ送信実行 (真剣度高) ---
        case 'submit_inquiry':
            $carId = $params['id'] ?? '';
            $inquiryType = $params['type'] ?? '在庫確認';
            handleSubmitInquiry($db, $replyToken, $carId, $inquiryType, $userId);
            break;

        // --- 3. メンテナンス(オイル交換/車検)予約確認ステップ (誤タップ防止) ---
        case 'ask_maintenance':
            $maintType = $params['type'] ?? 'oil';
            $carModel = $params['car'] ?? '愛車';
            $date = $params['date'] ?? '近日中';
            sendMaintenanceBookingConfirmMessage($replyToken, $maintType, $carModel, $date, $userId);
            break;

        // --- 4. メンテナンス(オイル交換/車検)予約確定送信 (真剣度高) ---
        case 'submit_maintenance':
            $maintType = $params['type'] ?? 'oil';
            $carModel = $params['car'] ?? '愛車';
            $prefTime = $params['pref'] ?? '近日中の希望';
            handleSubmitMaintenanceBooking($replyToken, $maintType, $carModel, $prefTime, $userId);
            break;

        // --- 5. キャンセル ---
        case 'cancel_inquiry':
        case 'cancel_maintenance':
            $messages = [
                [
                    'type' => 'text',
                    'text' => "ご案内をキャンセルしました。\n気になるお車やメンテナンスのご相談はお気軽に下のボタンよりどうぞ🚗",
                    'quickReply' => getQuickReplyItems()
                ]
            ];
            sendReplyMessage($replyToken, $messages);
            break;

        // --- 5-0. 教室への質問・相談 (お役立ち情報からの質問) ---
        case 'ask_class':
            $topic = $params['topic'] ?? 'シニアお役立ち情報';
            $replyText = "「{$topic}」についてのお問い合わせ・ご質問を受け付けました！\n\n教室スタッフがメッセージを確認後、順次ご案内・回答いたします。\nそのまま気になる点やお困りの内容を、このトーク画面にメッセージ送信してお知らせください😊";
            $messages = [
                [
                    'type' => 'text',
                    'text' => $replyText
                ]
            ];
            sendReplyMessage($replyToken, $messages);
            recordCustomerInteraction($db, $userId, 'knowledge_inquiry', "質問受付: {$topic}");
            break;

        // --- 5-0-2. 会社DXアンケート Q1（興味度回答） ---
        case 'dx_survey_q1':
            handleDxSurveyQ1($db, $replyToken, $params, $userId);
            break;

        // --- 5-0-3. 会社DXアンケート Q2（関心テーマ回答） ---
        case 'dx_survey_q2':
            handleDxSurveyQ2($db, $replyToken, $params, $userId);
            break;

        // --- 5-1. お知らせリッチメニュー表示 ---
        case 'show_notice_menu':
        case 'show_notice':
        case 'notice':
        case 'open_notice':
            handleShowNoticeMenu($replyToken, $userId);
            break;

        // --- 5-1-2. お知らせを閉じる（専用メニュー / 通常メニューへ戻る） ---
        case 'close_notice':
        case 'close_notice_menu':
        case 'close_notice_richmenu':
        case 'back_normal':
        case 'back_main':
        case 'back_to_main':
        case 'return_main':
        case 'close':
        case 'back':
        case 'default_menu':
            handleCloseNoticeMenu($db, $replyToken, $userId);
            break;

        // --- 5-2. 点検受付メニュー表示 ---
        case 'open_mycar':
            sendMyCarMenuMessage($db, $replyToken, $userId);
            break;

        // --- 6. サイレント検索: 価格帯メニュー表示 ---
        case 'show_price_menu':
            sendPriceMenuMessage($db, $replyToken);
            break;

        // --- 7. サイレント検索: 車種・ボディタイプメニュー表示 ---
        case 'show_type_menu':
            sendTypeMenuMessage($db, $replyToken);
            break;

        // --- 7-2. サイレント検索: 装備・仕様メニュー表示 ---
        case 'show_equipment_menu':
            sendEquipmentMenuMessage($db, $replyToken);
            break;

        // --- 7-3. サイレント検索: 走行距離メニュー表示 ---
        case 'show_distance_menu':
            sendDistanceMenuMessage($db, $replyToken);
            break;

        // --- 7-4. お役立ちガイドメニュー表示（クイックリプライ） ---
        case 'show_knowledge_menu':
        case 'show_knowledge_guide':
        case 'senior_kb_menu':
            sendKnowledgeMenuMessage($replyToken);
            recordCustomerInteraction($db, $userId, 'user_action', '📚 お役立ちメニュー表示');
            break;

        // --- 7-5. お役立ちガイド・個別記事詳細表示（Flexカード＋クイックリプライ） ---
        case 'show_knowledge':
        case 'show_senior_kb':
            $topic = trim($params['topic'] ?? 'scam_virus_alert');
            sendKnowledgeDetailMessage($replyToken, $topic, $userId, $db);
            break;

        // --- 8. サイレント検索: 価格帯絞り込み実行 ---
        case 'search_price':
            $maxPrice = (float)($params['max_price'] ?? 0);
            $minPrice = (float)($params['min_price'] ?? 0);
            $criteria = [];
            if ($maxPrice > 0 && $minPrice > 0) {
                $criteria['min_price'] = $minPrice;
                $criteria['max_price'] = $maxPrice;
                $title = "支払総額 {$minPrice}万〜{$maxPrice}万円の車両";
            } elseif ($maxPrice > 0) {
                $criteria['max_price'] = $maxPrice;
                $title = "支払総額 {$maxPrice}万円以下の車両";
            } elseif ($minPrice > 0) {
                $criteria['min_price'] = $minPrice;
                $title = "支払総額 {$minPrice}万円以上の車両";
            } else {
                $title = "全在庫車両";
            }
            searchCarsAndReply($db, $replyToken, $criteria, $title, $userId);
            break;

        // --- 8-2. サイレント検索: 走行距離絞り込み実行 ---
        case 'search_distance':
            $maxD = isset($params['max_distance']) ? (float)$params['max_distance'] : null;
            $minD = isset($params['min_distance']) ? (float)$params['min_distance'] : null;
            $criteria = [];
            $title = "走行距離で絞り込み";
            if ($maxD !== null) {
                $criteria['max_distance'] = $maxD;
                $title = "走行距離 {$maxD}万km以下の車両";
            }
            if ($minD !== null) {
                $criteria['min_distance'] = $minD;
                $title = "走行距離 {$minD}万km以上の車両";
            }
            searchCarsAndReply($db, $replyToken, $criteria, $title, $userId);
            break;

        // --- 9. サイレント検索: 軽自動車専用絞り込み ---
        case 'search_kei':
            searchCarsAndReply($db, $replyToken, ['is_kei' => true], "軽自動車の一覧", $userId);
            break;

        // --- 9-2. サイレント検索: 装備・仕様絞り込み ---
        case 'search_equip':
            $keyword = trim($params['keyword'] ?? '');
            searchCarsAndReply($db, $replyToken, ['equip' => $keyword], "「{$keyword}」装備の車両一覧", $userId);
            break;

        // --- 9-3. サイレント検索: 修復歴なし ---
        case 'search_repair_none':
            searchCarsAndReply($db, $replyToken, ['repair' => 'none'], "修復歴なし（無事故車）の一覧", $userId);
            break;

        // --- 9-4. サイレント検索: 届出済未使用車 / 低走行 ---
        case 'search_low_mileage':
            searchCarsAndReply($db, $replyToken, ['low_mileage' => true], "届出済未使用車・低走行車の一覧", $userId);
            break;

        // --- 9-5. サイレント検索: 車種・キーワード絞り込み実行 ---
        case 'search_type':
        case 'search_keyword':
            $keyword = trim($params['keyword'] ?? '');
            if ($keyword === '軽' || $keyword === '軽自動車') {
                searchCarsAndReply($db, $replyToken, ['is_kei' => true], "軽自動車の一覧", $userId);
            } else {
                $title = !empty($keyword) ? "「{$keyword}」の車両一覧" : "最新の在庫車両一覧";
                searchCarsAndReply($db, $replyToken, ['keyword' => $keyword], $title, $userId);
            }
            break;

        // --- 9-6. リッチメニュー切替アクション (タブ切替・エイリアス切替・お知らせ復帰等の完了通知) ---
        case 'richmenu_switched':
        case 'richmenu_switch':
        case 'switch_tab':
        case 'tab_switch':
        case 'close_notice':
        case 'back_main':
        case 'none':
            $targetAlias = trim($params['to_alias'] ?? ($params['alias'] ?? ($postbackParams['newRichMenuAliasId'] ?? '')));
            $isBranchCustom = !empty($params['branch_custom']) || !empty($params['from_notice']) || ($action === 'close_notice') || ($action === 'back_main');

            writeDebugLog("リッチメニュー切替通知受信", [
                'action' => $action,
                'targetAlias' => $targetAlias,
                'isBranchCustom' => $isBranchCustom,
                'userId' => $userId,
                'params' => $postbackParams
            ]);

            if (!empty($userId)) {
                // 切替先がお知らせメニューである場合はお知らせ表示中のため何もしない
                $isTargetNotice = !empty($targetAlias) && isNoticeMenu($db, $targetAlias);
                if (!$isTargetNotice) {
                    // 専用リッチメニュー（専用メッセージ帯付き）が設定されている顧客か判定
                    $customMenuId = getUserCustomRichMenuId($db, $userId);

                    if (!empty($customMenuId)) {
                        // 専用メッセージ設定顧客には、専用メッセージ帯付きメニューを即座に再リンク復帰！
                        writeDebugLog("リッチメニュー切替: 専用メッセージ設定顧客を検知したため専用メニューを復帰", [
                            'userId' => $userId,
                            'customMenuId' => $customMenuId,
                            'targetAlias' => $targetAlias,
                            'isBranchCustom' => $isBranchCustom
                        ]);
                        handleCloseNoticeMenu($db, '', $userId);
                    } else {
                        // 専用メニューがない通常顧客
                        writeDebugLog("リッチメニュー切替: 通常顧客のため通常メニューを維持", [
                            'userId' => $userId,
                            'targetAlias' => $targetAlias
                        ]);
                        // もし過去にお知らせ個別紐付けが残っていれば解除して全体本番メニューを表示
                        $currentMenuId = lineGetUserRichMenuId($userId);
                        if (!empty($currentMenuId) && isNoticeMenu($db, $currentMenuId)) {
                            handleCloseNoticeMenu($db, '', $userId);
                        }
                    }
                }
            }
            break;

        // --- 10. サイレント検索: 在庫全台一覧 ---
        case 'search_all':
            searchCarsAndReply($db, $replyToken, [], '現在の在庫車両一覧', $userId);
            break;

        default:
            writeDebugLog("未処理のPostbackアクション（サイレント処理）", ['action' => $action, 'raw' => $dataStr, 'userId' => $userId]);
            break;
        }
    } catch (Throwable $e) {
        writeDebugLog("handlePostback例外エラー", [
            'action' => $action,
            'userId' => $userId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

/**
 * LIFF Trigger からのPush送信用サイレントPostback実行関数
 */
function executeSilentPostbackPush(PDO $db, string $userId, string $dataStr): array {
    $cleanData = ltrim(trim($dataStr), '?');
    parse_str($cleanData, $params);
    $action = trim($params['action'] ?? '');

    writeDebugLog("LIFF Silent Postback Push実行", ['uid' => $userId, 'action' => $action, 'data' => $dataStr]);

    if (!str_starts_with($userId, 'U')) {
        writeDebugLog("Push送信スキップ: 有効なLINEユーザーIDではありません ({$userId})");
        return ['success' => false, 'error' => "無効なLINEユーザーID: {$userId}"];
    }

    $messages = [];

    switch ($action) {
        case 'open_mycar':
            $messages = generateMyCarMenuMessages($db, $userId);
            break;

        case 'show_price_menu':
            $messages = generatePriceMenuMessages($db);
            break;

        case 'show_type_menu':
            $messages = generateTypeMenuMessages($db);
            break;

        case 'show_equipment_menu':
            $messages = generateEquipmentMenuMessages($db);
            break;

        case 'show_distance_menu':
            $messages = generateDistanceMenuMessages($db);
            break;

        case 'show_knowledge_menu':
            $messages = generateKnowledgeMenuMessages();
            break;

        case 'show_knowledge':
            $topic = trim($params['topic'] ?? 'used_car');
            $messages = generateKnowledgeDetailMessage($topic);
            break;

        case 'search_price':
            $maxPrice = (float)($params['max_price'] ?? 0);
            $minPrice = (float)($params['min_price'] ?? 0);
            $criteria = [];
            if ($maxPrice > 0 && $minPrice > 0) {
                $criteria['min_price'] = $minPrice;
                $criteria['max_price'] = $maxPrice;
                $title = "支払総額 {$minPrice}万〜{$maxPrice}万円の車両";
            } elseif ($maxPrice > 0) {
                $criteria['max_price'] = $maxPrice;
                $title = "支払総額 {$maxPrice}万円以下の車両";
            } elseif ($minPrice > 0) {
                $criteria['min_price'] = $minPrice;
                $title = "支払総額 {$minPrice}万円以上の車両";
            } else {
                $title = "全在庫車両";
            }
            $messages = generateCarSearchMessages($db, $criteria, $title, $userId);
            break;

        case 'search_distance':
            $maxD = isset($params['max_distance']) ? (float)$params['max_distance'] : null;
            $minD = isset($params['min_distance']) ? (float)$params['min_distance'] : null;
            $criteria = [];
            $title = "走行距離で絞り込み";
            if ($maxD !== null) {
                $criteria['max_distance'] = $maxD;
                $title = "走行距離 {$maxD}万km以下の車両";
            }
            if ($minD !== null) {
                $criteria['min_distance'] = $minD;
                $title = "走行距離 {$minD}万km以上の車両";
            }
            $messages = generateCarSearchMessages($db, $criteria, $title, $userId);
            break;

        case 'search_kei':
            $messages = generateCarSearchMessages($db, ['is_kei' => true], "軽自動車の一覧", $userId);
            break;

        case 'search_equip':
            $keyword = trim($params['keyword'] ?? '');
            $messages = generateCarSearchMessages($db, ['equip' => $keyword], "「{$keyword}」装備の車両一覧", $userId);
            break;

        case 'search_repair_none':
            $messages = generateCarSearchMessages($db, ['repair' => 'none'], "修復歴なし（無事故車）の一覧", $userId);
            break;

        case 'search_low_mileage':
            $messages = generateCarSearchMessages($db, ['low_mileage' => true], "届出済未使用車・低走行車の一覧", $userId);
            break;

        case 'search_type':
        case 'search_keyword':
            $keyword = trim($params['keyword'] ?? '');
            if ($keyword === '軽' || $keyword === '軽自動車') {
                $messages = generateCarSearchMessages($db, ['is_kei' => true], "軽自動車の一覧", $userId);
            } else {
                $title = !empty($keyword) ? "「{$keyword}」の車両一覧" : "最新の在庫車両一覧";
                $messages = generateCarSearchMessages($db, ['keyword' => $keyword], $title, $userId);
            }
            break;

        // --- お知らせを閉じる（専用メニュー / 通常メニューへ戻る） ---
        case 'close_notice':
        case 'close_notice_menu':
        case 'close_notice_richmenu':
        case 'back_normal':
        case 'back_main':
        case 'back_to_main':
        case 'return_main':
        case 'close':
        case 'back':
        case 'default_menu':
            handleCloseNoticeMenu($db, '', $userId);
            return ['success' => true, 'message' => 'お知らせ終了・メニュー復帰完了'];

        case 'richmenu_switched':
        case 'richmenu_switch':
        case 'switch_tab':
        case 'tab_switch':
        case 'none':
            // リッチメニュー切り替え完了通知等
            $targetAlias = trim($params['to_alias'] ?? ($params['alias'] ?? ($postbackParams['newRichMenuAliasId'] ?? '')));
            if (!empty($userId)) {
                $isTargetNotice = !empty($targetAlias) && isNoticeMenu($db, $targetAlias);
                if (!$isTargetNotice) {
                    $customMenuId = getUserCustomRichMenuId($db, $userId);
                    if (!empty($customMenuId)) {
                        writeDebugLog("executeSilentPostbackPush: 専用メッセージ設定顧客を検知したため専用メニュー復帰処理を実行", [
                            'userId' => $userId,
                            'customMenuId' => $customMenuId,
                            'targetAlias' => $targetAlias
                        ]);
                        handleCloseNoticeMenu($db, '', $userId);
                        return ['success' => true, 'message' => 'お知らせ復帰・専用メニュー復元完了'];
                    } else {
                        $currentMenuId = lineGetUserRichMenuId($userId);
                        if (!empty($currentMenuId) && isNoticeMenu($db, $currentMenuId)) {
                            handleCloseNoticeMenu($db, '', $userId);
                            return ['success' => true, 'message' => 'お知らせ復帰・通常メニュー復帰完了'];
                        }
                    }
                }
            }
            writeDebugLog("リッチメニュー切替/サイレントPostback受信(通常メニュー維持)", ['action' => $action, 'userId' => $userId]);
            return ['success' => true, 'message' => 'サイレント処理完了'];

        case 'search_all':
            $messages = generateCarSearchMessages($db, [], '現在の在庫車両一覧', $userId);
            break;

        default:
            // 未知または明示的にハンドリングされていないPostbackアクションではカルーセルを誤送信せずサイレント終了
            writeDebugLog("未処理のPostbackアクション（サイレント無視）", ['action' => $action, 'data' => $dataStr, 'userId' => $userId]);
            return ['success' => true, 'message' => '未処理アクション（サイレント無視）'];
    }

    if (!empty($messages)) {
        try {
            if (count($messages) > 5) {
                $chunks = array_chunk($messages, 5);
                $lastRes = [];
                foreach ($chunks as $chunk) {
                    $lastRes = sendLinePushMessage($userId, $chunk);
                    writeDebugLog("Push分割送信結果", ['userId' => $userId, 'success' => $lastRes['success'] ?? false, 'response' => $lastRes['response'] ?? '']);
                }
                return ['success' => true, 'response' => $lastRes['response'] ?? ''];
            } else {
                $res = sendLinePushMessage($userId, $messages);
                writeDebugLog("Push送信結果", ['userId' => $userId, 'success' => $res['success'] ?? false, 'response' => $res['response'] ?? '']);
                return [
                    'success' => !empty($res['success']),
                    'httpCode' => $res['httpCode'] ?? 0,
                    'response' => $res['response'] ?? '',
                    'error' => $res['error'] ?? ''
                ];
            }
        } catch (Exception $e) {
            writeDebugLog("Silent Postback Push送信例外: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    return ['success' => false, 'error' => '送信メッセージの生成に失敗しました'];
}

/**
 * 友だち追加時のあいさつメッセージ
 */
function handleFollow(string $replyToken, string $userId = '') {
    $messages = [
        [
            'type' => 'text',
            'text' => "友だち追加ありがとうございます！🚗✨\n\n【" . SHOP_NAME . "】の最新在庫車両をいつでもLINEから検索いただけます。\n\n気になる車種名を入力するか、下のボタンをタップしてみてください！",
            'quickReply' => getQuickReplyItems()
        ]
    ];
    sendReplyMessage($replyToken, $messages, $userId);
}

/**
 * 車両検索 & Flex Message返信
 */
function searchCarsAndReply(PDO $db, string $replyToken, array $criteria, string $heading, string $userId = '') {
    $messages = generateCarSearchMessages($db, $criteria, $heading, $userId);
    if (!empty($messages)) {
        sendReplyMessage($replyToken, $messages, $userId);
    }
}

/**
 * 車両検索メッセージ配列を生成 (Reply / Push 共通)
 */
function generateCarSearchMessages(PDO $db, array $criteria, string $heading, string $userId = ''): array {
    try {
        $where = ["is_active = 1"];
        $params = [];

        if (!empty($criteria['keyword'])) {
            $kw = trim($criteria['keyword']);
            if ($kw === 'ミラ' || $kw === 'ミライース') {
                $where[] = "(
                    (title LIKE '%ミライース%' OR title LIKE '%ミラココア%' OR title LIKE '%ミラジーノ%' OR title LIKE '%ミラトコット%' OR title LIKE 'ダイハツ ミラ%' OR title LIKE '% ミラ %' OR title LIKE 'ミラ %' OR title LIKE '% ミラ')
                    OR (title LIKE '%ミラ%' AND title NOT LIKE '%ミラー%')
                )";
            } elseif ($kw === 'デイズ' || $kw === 'ルークス') {
                $where[] = "(title LIKE '%デイズ%' OR title LIKE '%ルークス%' OR title LIKE '%DAYZ%' OR title LIKE '%ROOX%')";
            } elseif ($kw === 'N-BOX' || $kw === 'エヌボックス') {
                $where[] = "(title LIKE '%N-BOX%' OR title LIKE '%Ｎ－ＢＯＸ%' OR title LIKE '%NBOX%')";
            } elseif ($kw === 'タント') {
                $where[] = "(title LIKE '%タント%' OR title LIKE '%TANTO%')";
            } elseif ($kw === 'スペーシア') {
                $where[] = "(title LIKE '%スペーシア%' OR title LIKE '%SPACIA%')";
            } elseif ($kw === 'ワゴンR') {
                $where[] = "(title LIKE '%ワゴンR%' OR title LIKE '%ワゴンＲ%' OR title LIKE '%スティングレー%')";
            } elseif ($kw === 'ムーヴ') {
                $where[] = "(title LIKE '%ムーヴ%' OR title LIKE '%キャンバス%' OR title LIKE '%MOVE%')";
            } elseif ($kw === 'アルト') {
                $where[] = "(title LIKE '%アルト%' OR title LIKE '%ラパン%' OR title LIKE '%ALTO%')";
            } elseif ($kw === 'ハスラー') {
                $where[] = "(title LIKE '%ハスラー%' OR title LIKE '%HUSTLER%')";
            } elseif ($kw === '輸入車' || $kw === '外車') {
                $where[] = "(title LIKE '%ベンツ%' OR title LIKE '%BMW%' OR title LIKE '%フォルクスワーゲン%' OR title LIKE '%アウディ%' OR title LIKE '%キャデラック%' OR title LIKE '%MINI%' OR title LIKE '%ボルボ%' OR title LIKE '%Bクラス%' OR title LIKE '%B180%' OR title LIKE '%CTS%')";
            } elseif ($kw === 'コンパクト') {
                $where[] = "(displacement != '660cc' AND (title LIKE '%コンパクト%' OR title LIKE '%フィット%' OR title LIKE '%アクア%' OR title LIKE '%ヤリス%' OR title LIKE '%ノート%' OR title LIKE '%パッソ%' OR title LIKE '%スイフト%' OR title LIKE '%ヴィッツ%' OR title LIKE '%デミオ%' OR title LIKE '%マーチ%' OR title LIKE '%ポロ%' OR title LIKE '%ゴルフ%' OR title LIKE '%ルーミー%' OR title LIKE '%ソリオ%' OR title LIKE '%タンク%' OR title LIKE '%FIT%' OR title LIKE '%AQUA%' OR title LIKE '%NOTE%'))";
            } elseif ($kw === 'ワゴン') {
                $where[] = "(title LIKE '%ワゴン%' OR title LIKE '%セレナ%' OR title LIKE '%ヴォクシー%' OR title LIKE '%ノア%' OR title LIKE '%ステップワゴン%' OR title LIKE '%フリード%' OR title LIKE '%シエンタ%' OR title LIKE '%アルファード%' OR title LIKE '%ヴェルファイア%' OR title LIKE '%デリカ%' OR title LIKE '%エスティマ%' OR title LIKE '%オデッセイ%')";
            } elseif ($kw === '4WD') {
                $where[] = "(
                    drive_type LIKE '%4WD%' OR drive_type LIKE '%４ＷＤ%' OR drive_type LIKE '%四駆%' OR drive_type LIKE '%AWD%' OR drive_type LIKE '%ＡＷＤ%'
                    OR title LIKE '%4WD%' OR title LIKE '%４ＷＤ%' OR title LIKE '%AWD%' OR title LIKE '%ＡＷＤ%' OR title LIKE '%SUV%' OR title LIKE '%クロスオーバー%' OR title LIKE '%キャデラック%' OR title LIKE '%XT5%'
                    OR title LIKE '%ハスラー%' OR title LIKE '%ジムニー%' OR title LIKE '%ヴェゼル%' OR title LIKE '%ヤリスクロス%' OR title LIKE '%ライズ%' OR title LIKE '%ロッキー%' OR title LIKE '%エクストレイル%' OR title LIKE '%フォレスター%' OR title LIKE '%CX-%' OR title LIKE '%C-HR%'
                )";
            } else {
                $where[] = "(title LIKE :kw OR displacement LIKE :kw OR year LIKE :kw)";
                $params[':kw'] = "%{$kw}%";
            }
        }

        if (!empty($criteria['is_kei'])) {
            $where[] = "(displacement = '660cc' OR displacement LIKE '66%' OR title LIKE '%軽自動車%')";
        }

        if (!empty($criteria['equip'])) {
            $eq = $criteria['equip'];
            if ($eq === 'ナビ') {
                $where[] = "(title LIKE '%ナビ%' OR title LIKE '%地デジ%' OR title LIKE '%TV%' OR title LIKE '%ＴＶ%' OR title LIKE '%オーディオ%' OR equipments LIKE '%ナビ%' OR equipments LIKE '%テレビ%')";
            } elseif ($eq === 'TV' || $eq === 'テレビ' || $eq === '地デジ') {
                $where[] = "(title LIKE '%地デジ%' OR title LIKE '%フルセグ%' OR title LIKE '%ワンセグ%' OR title LIKE '%ＴＶ%' OR title LIKE '%TV%' OR title LIKE '%テレビ%' OR equipments LIKE '%テレビ%' OR equipments LIKE '%地デジ%' OR equipments LIKE '%ワンセグ%' OR equipments LIKE '%フルセグ%')";
            } elseif ($eq === 'バックカメラ' || $eq === 'カメラ') {
                $where[] = "(title LIKE '%バックカメラ%' OR title LIKE '%全方位%' OR title LIKE '%アラウンドビュー%' OR title LIKE '%カメラ%' OR equipments LIKE '%バックカメラ%' OR equipments LIKE '%カメラ%')";
            } elseif ($eq === 'Bluetooth' || $eq === 'ブルートゥース') {
                $where[] = "(title LIKE '%Bluetooth%' OR title LIKE '%Ｂｌｕｅｔｏｏｔｈ%' OR title LIKE '%ブルートゥース%' OR title LIKE '%カープレイ%' OR title LIKE '%carplay%' OR equipments LIKE '%Bluetooth%')";
            } elseif ($eq === 'ETC' || $eq === 'ＥＴＣ') {
                $where[] = "(title LIKE '%ETC%' OR title LIKE '%ＥＴＣ%' OR equipments LIKE '%ETC%')";
            } elseif ($eq === 'ドラレコ' || $eq === 'ドライブレコーダー') {
                $where[] = "(title LIKE '%ドラレコ%' OR title LIKE '%ドライブレコーダー%' OR equipments LIKE '%ドライブレコーダー%' OR equipments LIKE '%ドラレコ%')";
            } elseif ($eq === 'スライド' || $eq === 'パワースライド') {
                $where[] = "(title LIKE '%スライド%' OR title LIKE '%パワースライド%' OR equipments LIKE '%スライド%')";
            } elseif ($eq === 'スマートキー' || $eq === 'キーレス') {
                $where[] = "(title LIKE '%スマートキー%' OR title LIKE '%インテリジェント%' OR title LIKE '%プッシュスタート%' OR title LIKE '%キーレス%' OR equipments LIKE '%スマートキー%')";
            } elseif ($eq === 'シートヒーター' || $eq === 'ヒーター') {
                $where[] = "(title LIKE '%シートヒーター%' OR equipments LIKE '%シートヒーター%')";
            } elseif ($eq === 'LED' || $eq === 'ＬＥＤ' || $eq === 'HID') {
                $where[] = "(title LIKE '%LED%' OR title LIKE '%ＬＥＤ%' OR title LIKE '%HID%' OR title LIKE '%ＨＩＤ%' OR title LIKE '%オートライト%' OR equipments LIKE '%LED%' OR equipments LIKE '%オートライト%')";
            } elseif ($eq === 'アルミ' || $eq === 'ホイール') {
                $where[] = "(title LIKE '%アルミ%' OR title LIKE '%ホイール%' OR equipments LIKE '%アルミ%')";
            } elseif ($eq === 'レザー' || $eq === '本革') {
                $where[] = "(title LIKE '%本革%' OR title LIKE '%レザー%' OR title LIKE '%ハーフレザー%' OR title LIKE '%革調%' OR equipments LIKE '%本革%' OR equipments LIKE '%レザー%')";
            } elseif ($eq === '軽減' || $eq === '安全' || $eq === 'ブレーキ') {
                $where[] = "(title LIKE '%軽減%' OR title LIKE '%ブレーキ%' OR title LIKE '%センシング%' OR title LIKE '%スマートアシスト%' OR title LIKE '%セーフティ%' OR title LIKE '%プロパイロット%' OR equipments LIKE '%安全%' OR equipments LIKE '%衝突%')";
            } elseif ($eq === '4WD' || $eq === '４ＷＤ' || $eq === '四駆') {
                $where[] = "(
                    drive_type LIKE '%4WD%' OR drive_type LIKE '%４ＷＤ%' OR drive_type LIKE '%四駆%' OR drive_type LIKE '%AWD%' OR drive_type LIKE '%ＡＷＤ%'
                    OR title LIKE '%4WD%' OR title LIKE '%４ＷＤ%' OR title LIKE '%AWD%' OR title LIKE '%四駆%' OR title LIKE '%クロスオーバー%' OR title LIKE '%キャデラック%' OR title LIKE '%XT5%'
                )";
            } else {
                $where[] = "(title LIKE :eq OR equipments LIKE :eq)";
                $params[':eq'] = "%{$eq}%";
            }
        }

        if (isset($criteria['repair']) && $criteria['repair'] === 'none') {
            $where[] = "(repair_history = 'なし' OR repair_history = '-' OR repair_history IS NULL)";
        }

        if (!empty($criteria['low_mileage'])) {
            $where[] = "(title LIKE '%未使用%' OR (distance NOT LIKE '%万km%' AND distance LIKE '%km%') OR (distance_num IS NOT NULL AND distance_num <= 0.5))";
        }

        if (!empty($criteria['min_price'])) {
            $where[] = "total_price_num >= :min_price";
            $params[':min_price'] = $criteria['min_price'];
        }

        if (!empty($criteria['max_price'])) {
            $where[] = "total_price_num <= :max_price";
            $params[':max_price'] = $criteria['max_price'];
        }

        if (isset($criteria['max_distance']) && is_numeric($criteria['max_distance'])) {
            $where[] = "(distance_num IS NOT NULL AND distance_num <= :max_dist)";
            $params[':max_dist'] = $criteria['max_distance'];
        }

        if (isset($criteria['min_distance']) && is_numeric($criteria['min_distance'])) {
            $where[] = "(distance_num IS NOT NULL AND distance_num >= :min_dist)";
            $params[':min_dist'] = $criteria['min_distance'];
        }

        $whereSql = implode(' AND ', $where);
        
        // 最大20台まで取得 (LINEのAPIサイズ上限・タイムアウト防止: 10台×最大2カルーセル = 20台)
        $stmt = $db->prepare("SELECT * FROM cars WHERE {$whereSql} ORDER BY (total_price_num IS NULL), total_price_num ASC LIMIT 20");
        $stmt->execute($params);
        $cars = $stmt->fetchAll();

        writeDebugLog("検索実行完了", ['heading' => $heading, 'hitCount' => count($cars), 'userId' => $userId]);

        if (empty($cars)) {
            writeDebugLog("車両検索0件のため案内メッセージ返信", ['heading' => $heading, 'criteria' => $criteria]);
            return [
                [
                    'type' => 'text',
                    'text' => "🚗 {$heading}\n\n現在、条件に一致する車両がございません。\n最新の未掲載在庫や近日入庫予定の車両もございますので、お気軽にスタッフまでご相談ください！",
                    'quickReply' => getQuickReplyItems()
                ]
            ];
        }

        // カルーセルバブルを構築
        $bubbles = [];
        foreach ($cars as $car) {
            $bubble = buildCarFlexBubble($car, $userId);
            if ($bubble) {
                $bubbles[] = $bubble;
            }
        }

        if (empty($bubbles)) {
            throw new Exception("バブル生成に失敗しました");
        }

        // LINEの仕様: 1カルーセルあたり最大10件 -> 10件ずつ分割して複数カルーセルで一括返信
        $bubbleChunks = array_chunk($bubbles, 10);
        $totalCount = count($bubbles);

        $messages = [
            [
                'type' => 'text',
                'text' => "🔍 {$heading} （全{$totalCount}件）"
            ]
        ];

        // 最大2カルーセル（20台）まで（LINEのAPIペイロード制限を遵守し、確実に高速送信）
        $bubbleChunks = array_slice($bubbleChunks, 0, 2);

        foreach ($bubbleChunks as $idx => $chunk) {
            $messages[] = [
                'type' => 'flex',
                'altText' => "{$heading} (" . ($idx * 10 + 1) . "〜" . ($idx * 10 + count($chunk)) . "件目)",
                'contents' => [
                    'type' => 'carousel',
                    'contents' => $chunk
                ]
            ];
        }

        // quickReply はLINE仕様により最後のメッセージオブジェクトにのみ付与
        if (!empty($messages)) {
            $lastIdx = count($messages) - 1;
            $messages[$lastIdx]['quickReply'] = getQuickReplyItems();
        }

        return $messages;
    } catch (Exception $e) {
        writeDebugLog("検索生成例外エラー: " . $e->getMessage());
        return [
            [
                'type' => 'text',
                'text' => "申し訳ありません。検索中にエラーが発生しました。\nしばらくしてからもう一度お試しください。",
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }
}

/**
 * 車両1台分のFlex Messageバブルを構築
 */
function buildCarFlexBubble(array $car, string $userId = ''): array {
    $rawTitle = trim($car['title'] ?? '');
    if (empty($rawTitle)) {
        $rawTitle = '車両情報';
    }
    $shortTitle = mb_substr($rawTitle, 0, 32) . (mb_strlen($rawTitle) > 32 ? '...' : '');

    $imgUrl = !empty($car['image_url']) ? $car['image_url'] : 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';
    if (!str_starts_with($imgUrl, 'https://')) {
        $imgUrl = 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';
    }

    $totalPrice = !empty($car['total_price_text']) ? $car['total_price_text'] : '要問合せ';
    $year = !empty(trim($car['year'] ?? '')) ? trim($car['year']) : '-';
    $distance = !empty(trim($car['distance'] ?? '')) ? trim($car['distance']) : '-';
    $repair = !empty(trim($car['repair_history'] ?? '')) ? trim($car['repair_history']) : '-';
    $detailUrl = !empty($car['detail_url']) ? $car['detail_url'] : SHOP_GOO_URL;
    $baseUrl = getBaseUrl();
    if (!str_starts_with($baseUrl, 'https://')) {
        $baseUrl = 'https://' . ltrim($baseUrl, 'http://');
    }
    $trackingUrl = $baseUrl . '/redirect.php?id=' . urlencode($car['id']) . '&uid=' . urlencode($userId) . '&src=' . urlencode('LINE Flex Message');

    // 問い合わせ文面
    $inquiryText = "【車両問い合わせ】\n車名: {$rawTitle}\n支払総額: {$totalPrice}\n詳細: {$detailUrl}\n\nこちらの車両について詳しく知りたいです。";
    if (mb_strlen($inquiryText) > 290) {
        $inquiryText = mb_substr($inquiryText, 0, 290) . '...';
    }

    return [
        'type' => 'bubble',
        'size' => 'kilo',
        'hero' => [
            'type' => 'image',
            'url' => $imgUrl,
            'size' => 'full',
            'aspectRatio' => '4:3',
            'aspectMode' => 'cover',
            'action' => [
                'type' => 'uri',
                'label' => '詳細を見る',
                'uri' => $trackingUrl
            ]
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '12px',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => $shortTitle,
                    'weight' => 'bold',
                    'size' => 'sm',
                    'wrap' => true,
                    'maxLines' => 2
                ],
                [
                    'type' => 'box',
                    'layout' => 'baseline',
                    'margin' => 'md',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '支払総額',
                            'size' => 'xs',
                            'color' => '#888888',
                            'flex' => 0
                        ],
                        [
                            'type' => 'text',
                            'text' => $totalPrice,
                            'weight' => 'bold',
                            'size' => 'lg',
                            'color' => '#E02424',
                            'margin' => 'sm',
                            'flex' => 0
                        ]
                    ]
                ],
                [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'margin' => 'md',
                    'spacing' => 'xs',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'baseline',
                            'contents' => [
                                ['type' => 'text', 'text' => '年式', 'color' => '#999999', 'size' => 'xxs', 'flex' => 2],
                                ['type' => 'text', 'text' => $year, 'size' => 'xxs', 'color' => '#333333', 'flex' => 5]
                            ]
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'baseline',
                            'contents' => [
                                ['type' => 'text', 'text' => '走行距離', 'color' => '#999999', 'size' => 'xxs', 'flex' => 2],
                                ['type' => 'text', 'text' => $distance, 'size' => 'xxs', 'color' => '#333333', 'flex' => 5]
                            ]
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'baseline',
                            'contents' => [
                                ['type' => 'text', 'text' => '修復歴', 'color' => '#999999', 'size' => 'xxs', 'flex' => 2],
                                ['type' => 'text', 'text' => $repair, 'size' => 'xxs', 'color' => '#333333', 'flex' => 5]
                            ]
                        ]
                    ]
                ]
            ]
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'paddingAll' => '10px',
            'contents' => [
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => '#06C755',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '💬 お問い合わせ・相談',
                        'data' => 'action=ask_inquiry&id=' . urlencode($car['id'])
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'secondary',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'uri',
                        'label' => 'グーネットで詳細を見る',
                        'uri' => $trackingUrl
                    ]
                ]
            ]
        ]
    ];
}

/**
 * 問い合わせ確認カード（誤タップ防止 & 要望選択）を送信
 */
function sendInquiryConfirmMessage(PDO $db, string $replyToken, string $carId, string $userId = '') {
    $stmt = $db->prepare("SELECT * FROM cars WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $carId]);
    $car = $stmt->fetch();

    if (!$car) {
        $messages = [['type' => 'text', 'text' => '該当の車両情報が見つかりませんでした。', 'quickReply' => getQuickReplyItems()]];
        sendReplyMessage($replyToken, $messages);
        return;
    }

    $rawTitle = trim($car['title'] ?? '車両');
    $shortTitle = mb_substr($rawTitle, 0, 30) . (mb_strlen($rawTitle) > 30 ? '...' : '');
    $totalPrice = !empty($car['total_price_text']) ? $car['total_price_text'] : '要問合せ';
    $imgUrl = !empty($car['image_url']) ? $car['image_url'] : 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';

    $confirmBubble = [
        'type' => 'bubble',
        'size' => 'mega',
        'hero' => [
            'type' => 'image',
            'url' => $imgUrl,
            'size' => 'full',
            'aspectRatio' => '16:9',
            'aspectMode' => 'cover'
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '16px',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '📋 お問い合わせ内容の確認',
                    'weight' => 'bold',
                    'size' => 'md',
                    'color' => '#1e293b'
                ],
                [
                    'type' => 'text',
                    'text' => $shortTitle,
                    'weight' => 'bold',
                    'size' => 'sm',
                    'color' => '#475569',
                    'margin' => 'sm',
                    'wrap' => true
                ],
                [
                    'type' => 'text',
                    'text' => "支払総額: {$totalPrice}",
                    'weight' => 'bold',
                    'size' => 'md',
                    'color' => '#E02424',
                    'margin' => 'xs'
                ],
                [
                    'type' => 'separator',
                    'margin' => 'md'
                ],
                [
                    'type' => 'text',
                    'text' => "ご希望のお問い合わせ項目をタップしてください。\n（スタッフが確認の上、本トークにてご案内します）",
                    'size' => 'xs',
                    'color' => '#64748b',
                    'margin' => 'md',
                    'wrap' => true
                ]
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
                    'color' => '#06C755',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '📦 在庫・状態を確認したい',
                        'data' => 'action=submit_inquiry&id=' . urlencode($carId) . '&type=' . urlencode('在庫・状態確認'),
                        'displayText' => "【在庫・状態確認】をお願いします"
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => '#3b82f6',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '📑 支払総額の見積もりが欲しい',
                        'data' => 'action=submit_inquiry&id=' . urlencode($carId) . '&type=' . urlencode('総額見積もり依頼'),
                        'displayText' => "【支払総額の見積もり】をお願いします"
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => '#f59e0b',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '🚗 実車見学・試乗を希望',
                        'data' => 'action=submit_inquiry&id=' . urlencode($carId) . '&type=' . urlencode('実車見学・試乗予約'),
                        'displayText' => "【実車見学・試乗】を希望します"
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'secondary',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '❌ キャンセル',
                        'data' => 'action=cancel_inquiry',
                        'displayText' => "キャンセルします"
                    ]
                ]
            ]
        ]
    ];

    $messages = [
        [
            'type' => 'flex',
            'altText' => "【お問い合わせ確認】{$shortTitle}",
            'contents' => $confirmBubble
        ]
    ];

    sendReplyMessage($replyToken, $messages);
}

/**
 * 正式問い合わせ実行（Discord通知 ＆ 受付完了メッセージ）
 */
function handleSubmitInquiry(PDO $db, string $replyToken, string $carId, string $inquiryType, string $userId = '') {
    $stmt = $db->prepare("SELECT * FROM cars WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $carId]);
    $car = $stmt->fetch();

    if (!$car) {
        $messages = [['type' => 'text', 'text' => '車両情報が見つかりませんでした。', 'quickReply' => getQuickReplyItems()]];
        sendReplyMessage($replyToken, $messages);
        return;
    }

    $rawTitle = trim($car['title'] ?? '車両');
    $totalPrice = !empty($car['total_price_text']) ? $car['total_price_text'] : '要問合せ';

    // ユーザー情報取得
    $userProfile = !empty($userId) ? getLineUserProfile($userId) : null;
    $userName = $userProfile['displayName'] ?? 'お客様';

    // 1. Discord & Slack へ正式問い合わせ通知を送信！
    if (function_exists('sendDiscordInquiryNotification')) {
        sendDiscordInquiryNotification($car, $inquiryType, $userProfile, $userId);
        writeDebugLog("正式問い合わせ通知送信完了", ['carId' => $carId, 'type' => $inquiryType, 'user' => $userName]);
    }
    if (function_exists('sendSlackInquiryNotification')) {
        sendSlackInquiryNotification($car, $inquiryType, $userProfile, $userId);
    }

    // 2. ユーザーへ受付完了メッセージを返信
    $messages = [
        [
            'type' => 'text',
            'text' => "{$userName} 様\n\n【{$inquiryType}】のご依頼を承りました！🚗✨\n\n対象車両: {$rawTitle}\n支払総額: {$totalPrice}\n\n担当スタッフが内容を確認し、本トークにて折り返しご連絡・ご案内させていただきます。今しばらくお待ちくださいませ！",
            'quickReply' => getQuickReplyItems()
        ]
    ];

    sendReplyMessage($replyToken, $messages);
}

/**
 * メンテナンス（オイル交換/車検点検）予約確認メッセージ (誤タップ防止)
 */
function sendMaintenanceBookingConfirmMessage(string $replyToken, string $type, string $carModel, string $targetDate, string $userId = '') {
    if ($type === 'oil') {
        $title = '🛢 オイル交換 来店予約のご確認';
        $color = '#f59e0b';
        $labelDate = '次回オイル予定日';
        $typeName = 'オイル交換';
    } elseif ($type === 'periodic') {
        $title = '📋 12ヶ月定期点検 ご予約のご確認';
        $color = '#10b981';
        $labelDate = '次回点検予定日';
        $typeName = '12ヶ月定期点検';
    } else {
        $title = '🚗 車検 来店予約のご確認';
        $color = '#3b82f6';
        $labelDate = '車検満了日';
        $typeName = '車検';
    }

    $confirmBubble = [
        'type' => 'bubble',
        'size' => 'mega',
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '20px',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => $title,
                    'weight' => 'bold',
                    'size' => 'md',
                    'color' => $color
                ],
                [
                    'type' => 'text',
                    'text' => "ご希望のご来店日時・時間帯をお選びください。\n担当スタッフが空き状況を確認し、本トークにて折り返しご案内いたします！",
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
                    'spacing' => 'xs',
                    'backgroundColor' => '#f8fafc',
                    'paddingAll' => '10px',
                    'cornerRadius' => 'md',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'baseline',
                            'contents' => [
                                ['type' => 'text', 'text' => '対象愛車', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                ['type' => 'text', 'text' => $carModel, 'size' => 'xs', 'weight' => 'bold', 'color' => '#1e293b', 'flex' => 6]
                            ]
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'baseline',
                            'contents' => [
                                ['type' => 'text', 'text' => $labelDate, 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                ['type' => 'text', 'text' => $targetDate, 'size' => 'xs', 'weight' => 'bold', 'color' => '#e02424', 'flex' => 6]
                            ]
                        ]
                    ]
                ]
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
                    'color' => '#06C755',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '☀️ 平日（午前中）を希望',
                        'data' => 'action=submit_maintenance&type=' . urlencode($typeName) . '&car=' . urlencode($carModel) . '&pref=' . urlencode('平日（午前中）'),
                        'displayText' => "【{$typeName}】平日（午前中）に来店を希望します"
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => '#06C755',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '🌤️ 平日（午後）を希望',
                        'data' => 'action=submit_maintenance&type=' . urlencode($typeName) . '&car=' . urlencode($carModel) . '&pref=' . urlencode('平日（午後）'),
                        'displayText' => "【{$typeName}】平日（午後）に来店を希望します"
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => '#3b82f6',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '🎈 土日・祝日を希望',
                        'data' => 'action=submit_maintenance&type=' . urlencode($typeName) . '&car=' . urlencode($carModel) . '&pref=' . urlencode('土日・祝日'),
                        'displayText' => "【{$typeName}】土日・祝日に来店を希望します"
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'secondary',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '💬 日程を個別にLINE相談',
                        'data' => 'action=submit_maintenance&type=' . urlencode($typeName) . '&car=' . urlencode($carModel) . '&pref=' . urlencode('日程を個別に相談したい'),
                        'displayText' => "【{$typeName}】日程について個別に相談したいです"
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'link',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '❌ キャンセル',
                        'data' => 'action=cancel_maintenance',
                        'displayText' => "キャンセルします"
                    ]
                ]
            ]
        ]
    ];

    $messages = [
        [
            'type' => 'flex',
            'altText' => "【ご予約確認】{$title}",
            'contents' => $confirmBubble
        ]
    ];

    sendReplyMessage($replyToken, $messages);
}

/**
 * メンテナンス予約実行（Discord通知 ＆ 受付完了メッセージ）
 */
function handleSubmitMaintenanceBooking(string $replyToken, string $bookingType, string $carModel, string $prefTime, string $userId = '') {
    // ユーザー情報取得
    $userProfile = !empty($userId) ? getLineUserProfile($userId) : null;
    $userName = $userProfile['displayName'] ?? 'お客様';

    // 1. Discord & Slack へ予約申し込み通知を送信！
    if (function_exists('sendDiscordMaintenanceBookingNotification')) {
        sendDiscordMaintenanceBookingNotification($bookingType, $carModel, $prefTime, $userProfile, $userId);
        writeDebugLog("メンテナンス予約Discord通知完了", ['type' => $bookingType, 'car' => $carModel, 'user' => $userName, 'pref' => $prefTime]);
    }
    if (function_exists('sendSlackInquiryNotification')) {
        sendSlackInquiryNotification(['user_id' => $userId, 'user_name' => $userName], "{$bookingType}（{$carModel} / {$prefTime}）", $userProfile, $userId);
    }

    // 2. ユーザーへ受付完了メッセージを返信
    $messages = [
        [
            'type' => 'text',
            'text' => "{$userName} 様\n\n【{$bookingType}】のご予約相談を承りました！🛠️✨\n\n対象愛車: {$carModel}\nご希望日時: {$prefTime}\n\n店舗スタッフがピットの空き状況を確認し、本トークにて確定日程・お見積もりのご案内をお送りいたします。どうぞよろしくお願いいたします！🚗",
            'quickReply' => getQuickReplyItems()
        ]
    ];

    sendReplyMessage($replyToken, $messages);
}

/**
 * 点検受付・マイカーメニュー送信
 */
function sendMyCarMenuMessage(PDO $db, string $replyToken, string $userId = '') {
    $messages = generateMyCarMenuMessages($db, $userId);
    sendReplyMessage($replyToken, $messages, $userId);
}

/**
 * 点検受付・マイカーメニュー（Flex Message）生成
 */
function generateMyCarMenuMessages(PDO $db, string $userId = ''): array {
    // 顧客の登録愛車情報を取得
    $carModel = '愛車';
    $oilDate = '近日中';
    $periodicDate = '近日中';
    $inspDate = '未定';
    $hasCustInfo = false;

    if (!empty($userId)) {
        try {
            $stmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid ORDER BY updated_at DESC LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $cust = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cust) {
                if (!empty($cust['car_model']) && !str_contains($cust['car_model'], '未登録')) {
                    $carModel = $cust['car_model'];
                    $hasCustInfo = true;
                }
                if (!empty($cust['oil_next_date'])) {
                    $oilDate = $cust['oil_next_date'];
                    $hasCustInfo = true;
                }
                if (!empty($cust['periodic_insp_next_date'])) {
                    $periodicDate = $cust['periodic_insp_next_date'];
                    $hasCustInfo = true;
                }
                if (!empty($cust['inspection_next_date'])) {
                    $inspDate = $cust['inspection_next_date'];
                    $hasCustInfo = true;
                }
            }
        } catch (Exception $e) {
            writeDebugLog("マイカーメニュー 愛車情報取得エラー: " . $e->getMessage());
        }
    }

    $liffId = defined('LIFF_ID') ? LIFF_ID : (defined('LINE_LIFF_ID') ? LINE_LIFF_ID : '2000276344-YL1wXh0h');
    $liffUrl = "https://liff.line.me/{$liffId}/mycar.html";

    $bodyContents = [
        [
            'type' => 'text',
            'text' => '🛠️ 点検・メンテナンス受付',
            'weight' => 'bold',
            'size' => 'md',
            'color' => '#1e293b'
        ],
        [
            'type' => 'text',
            'text' => "愛車の車検・定期点検・オイル交換など、\nメンテナンスのご相談をいつでも承ります！",
            'size' => 'xs',
            'color' => '#64748b',
            'margin' => 'xs',
            'wrap' => true
        ]
    ];

    if ($hasCustInfo) {
        $infoRows = [
            [
                'type' => 'box',
                'layout' => 'baseline',
                'contents' => [
                    ['type' => 'text', 'text' => '🚗 愛車', 'color' => '#64748b', 'size' => 'xs', 'flex' => 4],
                    ['type' => 'text', 'text' => $carModel, 'size' => 'xs', 'weight' => 'bold', 'color' => '#1e293b', 'flex' => 6]
                ]
            ]
        ];
        if ($inspDate !== '未定') {
            $infoRows[] = [
                'type' => 'box',
                'layout' => 'baseline',
                'contents' => [
                    ['type' => 'text', 'text' => '次回車検日', 'color' => '#64748b', 'size' => 'xs', 'flex' => 4],
                    ['type' => 'text', 'text' => $inspDate, 'size' => 'xs', 'weight' => 'bold', 'color' => '#e02424', 'flex' => 6]
                ]
            ];
        }
        if ($oilDate !== '近日中') {
            $infoRows[] = [
                'type' => 'box',
                'layout' => 'baseline',
                'contents' => [
                    ['type' => 'text', 'text' => '次回オイル', 'color' => '#64748b', 'size' => 'xs', 'flex' => 4],
                    ['type' => 'text', 'text' => $oilDate, 'size' => 'xs', 'weight' => 'bold', 'color' => '#1e293b', 'flex' => 6]
                ]
            ];
        }

        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'md',
            'spacing' => 'xs',
            'backgroundColor' => '#f8fafc',
            'paddingAll' => '12px',
            'cornerRadius' => 'md',
            'contents' => $infoRows
        ];
    }

    $bodyContents[] = [
        'type' => 'separator',
        'margin' => 'md'
    ];

    $bodyContents[] = [
        'type' => 'box',
        'layout' => 'vertical',
        'margin' => 'md',
        'spacing' => 'sm',
        'contents' => [
            [
                'type' => 'button',
                'style' => 'secondary',
                'height' => 'sm',
                'action' => [
                    'type' => 'postback',
                    'label' => '🛢️ オイル交換の予約',
                    'data' => 'action=ask_maintenance&type=oil&car=' . urlencode($carModel) . '&date=' . urlencode($oilDate),
                    'displayText' => "オイル交換の予約相談をしたいです"
                ]
            ],
            [
                'type' => 'button',
                'style' => 'secondary',
                'height' => 'sm',
                'action' => [
                    'type' => 'postback',
                    'label' => '📋 12ヶ月定期点検の予約',
                    'data' => 'action=ask_maintenance&type=periodic&car=' . urlencode($carModel) . '&date=' . urlencode($periodicDate),
                    'displayText' => "12ヶ月定期点検の予約相談をしたいです"
                ]
            ],
            [
                'type' => 'button',
                'style' => 'secondary',
                'height' => 'sm',
                'action' => [
                    'type' => 'postback',
                    'label' => '🚗 車検の来店予約',
                    'data' => 'action=ask_maintenance&type=inspection&car=' . urlencode($carModel) . '&date=' . urlencode($inspDate),
                    'displayText' => "車検の予約相談をしたいです"
                ]
            ],
            [
                'type' => 'button',
                'style' => 'primary',
                'color' => '#06C755',
                'height' => 'sm',
                'margin' => 'sm',
                'action' => [
                    'type' => 'uri',
                    'label' => '📱 ﾏｲｶｰ管理画面',
                    'uri' => $liffUrl
                ]
            ]
        ]
    ];

    $menuBubble = [
        'type' => 'bubble',
        'size' => 'kilo',
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '16px',
            'contents' => $bodyContents
        ]
    ];

    return [
        [
            'type' => 'flex',
            'altText' => '🛠️ 点検・メンテナンス受付',
            'contents' => $menuBubble,
            'quickReply' => getQuickReplyItems()
        ]
    ];
}

/**
 * 価格帯選択メニュー送信
 */
function sendPriceMenuMessage(PDO $db, string $replyToken) {
    $messages = generatePriceMenuMessages($db);
    sendReplyMessage($replyToken, $messages);
}

/**
 * 価格帯選択メニュー（サイレントボタン式Flex Message）生成 - 動的在庫集計型
 * 現在の有効在庫（carsテーブル）から実在する価格帯を自動集計し、
 * 「該当台数（例: 〜50万円 (3台)）」バッジ付きで表示（0件の選択肢は自動非表示）
 */
function generatePriceMenuMessages(PDO $db): array {
    $cars = [];
    try {
        $stmt = $db->query("SELECT id, title, total_price_num, total_price_text FROM cars WHERE is_active = 1");
        $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        writeDebugLog("価格メニュー 在庫取得エラー: " . $e->getMessage());
    }

    $totalStock = count($cars);
    if (empty($cars)) {
        return [
            [
                'type' => 'text',
                'text' => "現在、展示中の在庫車両を準備中です。\n最新の入庫状況はお気軽にお問い合わせください！",
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }

    // 価格帯のマスター定義
    $priceDefs = [
        [
            'name' => '〜30万円',
            'action' => 'search_price',
            'param' => 'max_price=30',
            'check' => function($car) {
                return (isset($car['total_price_num']) && $car['total_price_num'] !== null && (float)$car['total_price_num'] > 0 && (float)$car['total_price_num'] <= 30);
            }
        ],
        [
            'name' => '〜50万円',
            'action' => 'search_price',
            'param' => 'max_price=50',
            'check' => function($car) {
                return (isset($car['total_price_num']) && $car['total_price_num'] !== null && (float)$car['total_price_num'] > 0 && (float)$car['total_price_num'] <= 50);
            }
        ],
        [
            'name' => '〜70万円',
            'action' => 'search_price',
            'param' => 'max_price=70',
            'check' => function($car) {
                return (isset($car['total_price_num']) && $car['total_price_num'] !== null && (float)$car['total_price_num'] > 0 && (float)$car['total_price_num'] <= 70);
            }
        ],
        [
            'name' => '〜100万円',
            'action' => 'search_price',
            'param' => 'max_price=100',
            'check' => function($car) {
                return (isset($car['total_price_num']) && $car['total_price_num'] !== null && (float)$car['total_price_num'] > 0 && (float)$car['total_price_num'] <= 100);
            }
        ],
        [
            'name' => '〜150万円',
            'action' => 'search_price',
            'param' => 'max_price=150',
            'check' => function($car) {
                return (isset($car['total_price_num']) && $car['total_price_num'] !== null && (float)$car['total_price_num'] > 0 && (float)$car['total_price_num'] <= 150);
            }
        ],
        [
            'name' => '〜200万円',
            'action' => 'search_price',
            'param' => 'max_price=200',
            'check' => function($car) {
                return (isset($car['total_price_num']) && $car['total_price_num'] !== null && (float)$car['total_price_num'] > 0 && (float)$car['total_price_num'] <= 200);
            }
        ],
        [
            'name' => '200万円以上',
            'action' => 'search_price',
            'param' => 'min_price=200',
            'check' => function($car) {
                return (isset($car['total_price_num']) && $car['total_price_num'] !== null && (float)$car['total_price_num'] >= 200);
            }
        ]
    ];

    $buttons = [];
    foreach ($priceDefs as $pDef) {
        $count = 0;
        foreach ($cars as $car) {
            if ($pDef['check']($car)) {
                $count++;
            }
        }
        if ($count > 0) {
            $btnLabel = "{$pDef['name']} ({$count}台)";
            $postbackData = "action={$pDef['action']}" . (!empty($pDef['param']) ? "&{$pDef['param']}" : "");
            $buttons[] = [
                'type' => 'button',
                'style' => 'secondary',
                'height' => 'sm',
                'action' => [
                    'type' => 'postback',
                    'label' => $btnLabel,
                    'data' => $postbackData
                ]
            ];
        }
    }

    // すべての在庫を見るボタン
    $allButton = [
        'type' => 'button',
        'style' => 'primary',
        'color' => '#06C755',
        'height' => 'sm',
        'margin' => 'sm',
        'action' => [
            'type' => 'postback',
            'label' => "すべての在庫を見る ({$totalStock}台)",
            'data' => 'action=search_all'
        ]
    ];

    $activeBubbles = [];
    if (!empty($buttons)) {
        // ボタンが多数ある場合は6個ずつチャンクしてカルーセル化
        $btnChunks = array_chunk($buttons, 6);
        foreach ($btnChunks as $idx => $chunk) {
            $titleSuffix = count($btnChunks) > 1 ? " (" . ($idx + 1) . ")" : "";
            $contents = $chunk;
            // 最後のBubbleに全在庫ボタンを追加
            if ($idx === count($btnChunks) - 1) {
                $contents[] = $allButton;
            }

            $activeBubbles[] = [
                'type' => 'bubble',
                'size' => 'kilo',
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'paddingAll' => '16px',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '💰 ご予算・支払総額から探す' . $titleSuffix,
                            'weight' => 'bold',
                            'size' => 'md',
                            'color' => '#1e293b'
                        ],
                        [
                            'type' => 'text',
                            'text' => '在庫に実在する価格帯から選べます',
                            'size' => 'xs',
                            'color' => '#64748b',
                            'margin' => 'xs'
                        ],
                        [
                            'type' => 'separator',
                            'margin' => 'sm'
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'spacing' => 'sm',
                            'contents' => $contents
                        ]
                    ]
                ]
            ];
        }
    } else {
        // 万が一価格設定された車両がない場合でも全在庫を見るボタンを表示
        $activeBubbles[] = [
            'type' => 'bubble',
            'size' => 'kilo',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '16px',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => '💰 ご予算・支払総額から探す',
                        'weight' => 'bold',
                        'size' => 'md',
                        'color' => '#1e293b'
                    ],
                    [
                        'type' => 'text',
                        'text' => '最新の在庫車両一覧からご覧いただけます',
                        'size' => 'xs',
                        'color' => '#64748b',
                        'margin' => 'xs'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'sm'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'md',
                        'spacing' => 'sm',
                        'contents' => [$allButton]
                    ]
                ]
            ]
        ];
    }

    return [
        [
            'type' => 'flex',
            'altText' => 'ご予算・支払総額から探す',
            'contents' => count($activeBubbles) === 1 ? $activeBubbles[0] : [
                'type' => 'carousel',
                'contents' => $activeBubbles
            ],
            'quickReply' => getQuickReplyItems()
        ]
    ];
}

/**
 * 走行距離メニューを送信
 */
function sendDistanceMenuMessage(PDO $db, string $replyToken) {
    $messages = generateDistanceMenuMessages($db);
    sendReplyMessage($replyToken, $messages);
}

/**
 * 走行距離メニューを生成 (Reply / Push 共通) - 動的在庫集計型
 */
function generateDistanceMenuMessages(PDO $db): array {
    $cars = [];
    try {
        $stmt = $db->query("SELECT id, title, distance, distance_num FROM cars WHERE is_active = 1");
        $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // 走行距離帯のマスター定義
    $distanceDefs = [
        [
            'name' => '届出済未使用車',
            'action' => 'search_low_mileage',
            'param' => '',
            'check' => function($car) {
                return (str_contains($car['title'] ?? '', '未使用') || (!empty($car['distance_num']) && $car['distance_num'] <= 0.05));
            }
        ],
        [
            'name' => '〜1万km',
            'action' => 'search_distance',
            'param' => 'max_distance=1.0',
            'check' => function($car) {
                return (isset($car['distance_num']) && $car['distance_num'] !== null && (float)$car['distance_num'] <= 1.0);
            }
        ],
        [
            'name' => '〜3万km',
            'action' => 'search_distance',
            'param' => 'max_distance=3.0',
            'check' => function($car) {
                return (isset($car['distance_num']) && $car['distance_num'] !== null && (float)$car['distance_num'] <= 3.0);
            }
        ],
        [
            'name' => '〜5万km',
            'action' => 'search_distance',
            'param' => 'max_distance=5.0',
            'check' => function($car) {
                return (isset($car['distance_num']) && $car['distance_num'] !== null && (float)$car['distance_num'] <= 5.0);
            }
        ],
        [
            'name' => '〜7万km',
            'action' => 'search_distance',
            'param' => 'max_distance=7.0',
            'check' => function($car) {
                return (isset($car['distance_num']) && $car['distance_num'] !== null && (float)$car['distance_num'] <= 7.0);
            }
        ],
        [
            'name' => '〜10万km',
            'action' => 'search_distance',
            'param' => 'max_distance=10.0',
            'check' => function($car) {
                return (isset($car['distance_num']) && $car['distance_num'] !== null && (float)$car['distance_num'] <= 10.0);
            }
        ],
        [
            'name' => '10万km超',
            'action' => 'search_distance',
            'param' => 'min_distance=10.0',
            'check' => function($car) {
                return (isset($car['distance_num']) && $car['distance_num'] !== null && (float)$car['distance_num'] > 10.0);
            }
        ]
    ];

    $buttons = [];
    foreach ($distanceDefs as $dDef) {
        $count = 0;
        foreach ($cars as $car) {
            if ($dDef['check']($car)) {
                $count++;
            }
        }
        if ($count > 0) {
            $btnLabel = "{$dDef['name']} ({$count}台)";
            $postbackData = "action={$dDef['action']}" . (!empty($dDef['param']) ? "&{$dDef['param']}" : "");
            $buttons[] = [
                'type' => 'button',
                'style' => 'secondary',
                'height' => 'sm',
                'action' => [
                    'type' => 'postback',
                    'label' => $btnLabel,
                    'data' => $postbackData
                ]
            ];
        }
    }

    $activeBubbles = [];
    if (!empty($buttons)) {
        $btnChunks = array_chunk($buttons, 4);
        foreach ($btnChunks as $idx => $chunk) {
            $titleSuffix = count($btnChunks) > 1 ? " (" . ($idx + 1) . ")" : "";
            $activeBubbles[] = [
                'type' => 'bubble',
                'size' => 'kilo',
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'paddingAll' => '14px',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '走行距離で探す' . $titleSuffix,
                            'weight' => 'bold',
                            'size' => 'md',
                            'color' => '#1e293b'
                        ],
                        [
                            'type' => 'text',
                            'text' => '在庫に実在する走行距離帯から選べます',
                            'size' => 'xs',
                            'color' => '#64748b',
                            'margin' => 'xs'
                        ],
                        [
                            'type' => 'separator',
                            'margin' => 'sm'
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'spacing' => 'sm',
                            'contents' => $chunk
                        ]
                    ]
                ]
            ];
        }
    }

    if (empty($activeBubbles)) {
        return [
            [
                'type' => 'text',
                'text' => "現在、該当する走行距離条件の在庫を更新中です。",
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }

    return [
        [
            'type' => 'flex',
            'altText' => '走行距離から探す',
            'contents' => [
                'type' => 'carousel',
                'contents' => $activeBubbles
            ],
            'quickReply' => getQuickReplyItems()
        ]
    ];
}

/**
 * シニア向けスマホ・PCお役立ちガイドメニューを送信（横スクロールカルーセル＋クイックリプライ付き）
 */
function sendKnowledgeMenuMessage(string $replyToken) {
    if (function_exists('generateSeniorKnowledgeCarouselMessages')) {
        $carouselMsgs = generateSeniorKnowledgeCarouselMessages(true);
        if (!empty($carouselMsgs)) {
            sendReplyMessage($replyToken, $carouselMsgs);
            return;
        }
    } elseif (function_exists('generateSeniorKnowledgeCarouselMessage')) {
        $carouselMsg = generateSeniorKnowledgeCarouselMessage(true);
        sendReplyMessage($replyToken, [$carouselMsg]);
        return;
    }

    $qr = getSeniorKnowledgeQuickReplyItems();
    $messages = [
        [
            'type' => 'text',
            'text' => "💡【シニア向け スマホ・PCお役立ちガイド】\n\n知っておくと安心・便利な知恵袋やトラブル対処法をまとめました！\n\n画面下のボタン（横スクロール）から、読みたいテーマをタップしてご覧ください👇",
            'quickReply' => $qr
        ]
    ];
    sendReplyMessage($replyToken, $messages);
}

/**
 * シニア向けお役立ち個別記事（Flex Messageカード＋クイックリプライ）を送信
 */
function sendKnowledgeDetailMessage(string $replyToken, string $topic, string $userId = '', ?PDO $db = null) {
    $flexMsg = generateSeniorKnowledgeFlexMessage($topic, true);
    sendReplyMessage($replyToken, [$flexMsg]);
    if ($db && !empty($userId)) {
        recordCustomerInteraction($db, $userId, 'knowledge_view', "お役立ち閲覧: {$topic}");
    }
}

/**
 * カーライフ豆知識・お役立ちガイド（目次3段カルーセル・全21テーマ・通し番号付き）を生成
 */
function generateKnowledgeMenuMessages(): array {
    // 1段目: 車選び＆購入・手続きガイド（①〜⑦）
    $group1Topics = [
        [
            'topic' => 'used_car',
            'badge' => '🚗 車選びの極意',
            'badge_color' => '#3b82f6',
            'title' => '① 失敗しない中古車の選び方',
            'desc' => "プロが教える！走行距離・修復歴・整備履歴など後悔しない5大チェックポイント。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'kei_vs_compact',
            'badge' => '🚙 徹底比較ガイド',
            'badge_color' => '#8b5cf6',
            'title' => '② 軽自動車 vs 普通車の維持費比較',
            'desc' => "税金・車検・燃費・保険料の年間コスト差と、ライフスタイル別の賢い選び方。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'body_type_guide',
            'badge' => '🚙 目的別・車選び',
            'badge_color' => '#0284c7',
            'title' => '③ ボディタイプ別の特徴と選び方',
            'desc' => "軽・SUV・ミニバン・コンパクトの特徴と、家族構成や用途に合った最適車種診断。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'car_loan',
            'badge' => '💳 ローン＆資金計画',
            'badge_color' => '#4f46e5',
            'title' => '④ オートローンの賢い選び方',
            'desc' => "金利の種類、無理のない返済比率（手取りの15〜20%）、事前仮審査のメリット。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'best_timing',
            'badge' => '💰 お得な買い時',
            'badge_color' => '#ea580c',
            'title' => '⑤ 車のお得な買い時・購入時期',
            'desc' => "決算期（3月・9月）やモデルチェンジ後、自動車税の課税時期から見るベストな時期。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'car_paperwork',
            'badge' => '📄 手続き＆流れ',
            'badge_color' => '#0891b2',
            'title' => '⑥ 必要書類と納車までの流れ',
            'desc' => "車庫証明や印鑑証明、住民票の準備から納車前点検・受取までのステップを解説。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'trade_in',
            'badge' => '🛡️ 査定額UPの秘訣',
            'badge_color' => '#d97706',
            'title' => '⑦ 愛車を高く売る・下取りのコツ',
            'desc' => "査定士が見る重要ポイント、純正パーツ保管、ベストな売却タイミングを伝授。",
            'read_time' => '約2分で読める'
        ]
    ];

    // 2段目: メンテナンス・点検＆ケアガイド（⑧〜⑭）
    $group2Topics = [
        [
            'topic' => 'oil',
            'badge' => '🛢️ 愛車長持ちの秘訣',
            'badge_color' => '#f59e0b',
            'title' => '⑧ エンジンオイル交換の真実',
            'desc' => "「まだ走れる」は危険？適切な交換サイクルとフィルター交換の重要性を解説。",
            'read_time' => '約1.5分で読める'
        ],
        [
            'topic' => 'periodic',
            'badge' => '📋 予防整備の基礎',
            'badge_color' => '#10b981',
            'title' => '⑨ 法定12ヶ月点検の必要性',
            'desc' => "車検に通っていても安心できない？受けるメリットと車検との違いをプロが解説。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'inspection',
            'badge' => '🔍 安心＆スムーズ',
            'badge_color' => '#6366f1',
            'title' => '⑩ 車検の基礎知識と賢い受け方',
            'desc' => "満了日の1ヶ月前から受検可能！費用の内訳や準備物、安心車検のポイント。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'battery_tire',
            'badge' => '⚠️ トラブル予防',
            'badge_color' => '#ef4444',
            'title' => '⑪ バッテリー・タイヤ・日常点検',
            'desc' => "出先での突然死を防ぐ！季節ごとのトラブル対策と交換サインの見極め方。",
            'read_time' => '約1.5分で読める'
        ],
        [
            'topic' => 'brake_care',
            'badge' => '🛑 安全の要・ブレーキ',
            'badge_color' => '#e11d48',
            'title' => '⑫ ブレーキの寿命と重要チェック',
            'desc' => "パッド残厚3mmの危険サイン、キーキー音の正体、フルード吸湿劣化の注意点。",
            'read_time' => '約1.5分で読める'
        ],
        [
            'topic' => 'aircon_care',
            'badge' => '❄️ 快適ドライブ',
            'badge_color' => '#0ea5e9',
            'title' => '⑬ カーエアコンの効き＆悪臭ケア',
            'desc' => "エアコンフィルター交換時期、エバポレーター消臭洗浄、ガス補充で冷え復活！",
            'read_time' => '約1.5分で読める'
        ],
        [
            'topic' => 'car_wash_care',
            'badge' => '🧼 愛車ケア＆美観',
            'badge_color' => '#06b6d4',
            'title' => '⑭ 洗車＆ボディコーティング術',
            'desc' => "炎天下の洗車NG理由、洗車キズを防ぐ洗い方、コーティングを長持ちさせる秘訣。",
            'read_time' => '約2分で読める'
        ]
    ];

    // 3段目: 安全運転・トラブル緊急対処＆季節対策（⑮〜㉑）
    $group3Topics = [
        [
            'topic' => 'winter_driving',
            'badge' => '❄️ 冬道・降雪対策',
            'badge_color' => '#0284c7',
            'title' => '⑮ 雪道運転と冬タイヤの極意',
            'desc' => "スタッドレスの寿命見極め（プラットホーム）と融雪剤による下回り防錆対策。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'warning_lights',
            'badge' => '🚨 緊急・トラブル診断',
            'badge_color' => '#dc2626',
            'title' => '⑯ 警告灯の意味と緊急時の対処法',
            'desc' => "黄色と赤色の危険度の違い、異音（カタカタ・キーキー）の正体と初期対応。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'rain_driving',
            'badge' => '🌧️ 雨天・悪天候対策',
            'badge_color' => '#2563eb',
            'title' => '⑰ 雨の日の安全運転と冠水対策',
            'desc' => "冠水道路の走行限界、ハイドロプレーニング予防、撥水とワイパー視界確保。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'accident_guide',
            'badge' => '💥 緊急初動マニュアル',
            'badge_color' => '#b91c1c',
            'title' => '⑱ 事故・故障時の緊急対応手順',
            'desc' => "二次災害防止、119番・110番の義務、警察の事故証明と保険会社連絡ステップ。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'fuel_economy',
            'badge' => '⛽ 燃費＆節約術',
            'badge_color' => '#059669',
            'title' => '⑲ 燃費アップ＆愛車の節約術',
            'desc' => "ふんわりアクセル・タイヤ空気圧・不要な荷物軽量化でガソリン代を大幅カット！",
            'read_time' => '約1.5分で読める'
        ],
        [
            'topic' => 'beginner_driver',
            'badge' => '🔰 安心ドライブ',
            'badge_color' => '#16a34a',
            'title' => '⑳ 初心者・ペーパードライバー術',
            'desc' => "車幅感覚の掴み方、バック駐車の目印、死角の確認、車間距離の安全マニュアル。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'car_accessories',
            'badge' => '🔌 便利アイテム・装備',
            'badge_color' => '#7c3aed',
            'title' => '㉑ ドラレコ・ETC・LED便利知識',
            'desc' => "前後2カメラドラレコの選び方、ETC2.0の割引メリット、車検対応LED化の注意点。",
            'read_time' => '約2分で読める'
        ]
    ];

    // 4段目: 愛車長持ち・査定UP＆トラブルレスキュー（㉒〜㉘）
    $group4Topics = [
        [
            'topic' => 'car_appraisal',
            'badge' => '💴 愛車売却＆査定UP',
            'badge_color' => '#d97706',
            'title' => '㉒ 愛車を高く売る・査定UP術',
            'desc' => "洗車・車内消臭・純正パーツ保管・査定時期の見極めで買取額が大幅アップ！",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'tire_rotation',
            'badge' => '🛞 タイヤ長持ち・安全',
            'badge_color' => '#0284c7',
            'title' => '㉓ タイヤローテーションと偏摩耗',
            'desc' => "前後の摩耗差を解消！5,000kmごとの位置交換でタイヤ寿命が1.5倍に延びる。",
            'read_time' => '約1.5分で読める'
        ],
        [
            'topic' => 'disaster_car_stay',
            'badge' => '🏕️ 防災・緊急車中泊',
            'badge_color' => '#dc2626',
            'title' => '㉔ 車の防災＆災害時車中泊マニュアル',
            'desc' => "大雪立ち往生・地震対策。一酸化炭素中毒防止と車載すべき防災7つ道具。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'headlight_yellowing',
            'badge' => '✨ 美観＆夜間視界',
            'badge_color' => '#7c3aed',
            'title' => '㉕ ヘッドライト黄ばみ除去と予防',
            'desc' => "紫外線劣化の黄ばみは光量不足で車検落ちの原因に！クリアな瞳を取り戻す方法。",
            'read_time' => '約1.5分で読める'
        ],
        [
            'topic' => 'smart_key_battery',
            'badge' => '🔑 トラブル緊急脱出',
            'badge_color' => '#e11d48',
            'title' => '㉖ スマートキー電池切れ時の始動法',
            'desc' => "鍵が開かない・エンジンがかからない時の「内蔵キー＆タッチ始動」完全ガイド。",
            'read_time' => '約1.5分で読める'
        ],
        [
            'topic' => 'hybrid_battery_care',
            'badge' => '🔋 HV・EVの賢い乗り方',
            'badge_color' => '#059669',
            'title' => '㉗ ハイブリッド車のバッテリー延命術',
            'desc' => "駆動用バッテリーを長持ちさせる運転法と、見落としがちな「補機バッテリー」の盲点。",
            'read_time' => '約2分で読める'
        ],
        [
            'topic' => 'daily_car_check',
            'badge' => '🔍 5分セルフ点検',
            'badge_color' => '#2563eb',
            'title' => '㉘ 日常点検「ぶ・た・は・と・う・み・ず」',
            'desc' => "ドライブ前に5分でできる！プロも推奨する7大セルフチェックの合言葉。",
            'read_time' => '約2分で読める'
        ]
    ];

    $buildCarouselBubbles = function($topicsList) {
        $bubbles = [];
        foreach ($topicsList as $t) {
            $desc = str_replace('\\n', "\n", $t['desc']);
            $bubbles[] = [
                'type' => 'bubble',
                'size' => 'kilo',
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'paddingAll' => '16px',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'baseline',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => $t['badge'],
                                    'weight' => 'bold',
                                    'size' => 'xs',
                                    'color' => $t['badge_color']
                                ]
                            ]
                        ],
                        [
                            'type' => 'text',
                            'text' => $t['title'],
                            'weight' => 'bold',
                            'size' => 'md',
                            'color' => '#1e293b',
                            'wrap' => true,
                            'margin' => 'sm'
                        ],
                        [
                            'type' => 'text',
                            'text' => $desc,
                            'size' => 'xs',
                            'color' => '#64748b',
                            'wrap' => true,
                            'margin' => 'sm'
                        ],
                        [
                            'type' => 'separator',
                            'margin' => 'md'
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'baseline',
                            'margin' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '⏱ ' . $t['read_time'], 'size' => 'xxs', 'color' => '#94a3b8']
                            ]
                        ]
                    ]
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'paddingAll' => '12px',
                    'contents' => [
                        [
                            'type' => 'button',
                            'style' => 'primary',
                            'color' => '#06C755',
                            'height' => 'sm',
                            'action' => [
                                'type' => 'postback',
                                'label' => '📖 詳しく読む',
                                'data' => 'action=show_knowledge&topic=' . urlencode($t['topic'])
                            ]
                        ]
                    ]
                ]
            ];
        }
        return $bubbles;
    };

    return [
        [
            'type' => 'flex',
            'altText' => '【第1弾: 車選び＆購入・手続きガイド ①〜⑦】カーライフ豆知識',
            'contents' => [
                'type' => 'carousel',
                'contents' => $buildCarouselBubbles($group1Topics)
            ]
        ],
        [
            'type' => 'flex',
            'altText' => '【第2弾: メンテナンス・点検＆ケアガイド ⑧〜⑭】カーライフ豆知識',
            'contents' => [
                'type' => 'carousel',
                'contents' => $buildCarouselBubbles($group2Topics)
            ]
        ],
        [
            'type' => 'flex',
            'altText' => '【第3弾: 安全運転・トラブル対処＆便利知識 ⑮〜㉑】カーライフ豆知識',
            'contents' => [
                'type' => 'carousel',
                'contents' => $buildCarouselBubbles($group3Topics)
            ]
        ],
        [
            'type' => 'flex',
            'altText' => '【第4弾: 愛車長持ち・査定UP＆トラブルレスキュー ㉒〜㉘】カーライフ豆知識',
            'contents' => [
                'type' => 'carousel',
                'contents' => $buildCarouselBubbles($group4Topics)
            ],
            'quickReply' => getQuickReplyItems()
        ]
    ];
}

/**
 * 各テーマの詳細解説 Flex Message を生成
 */
function generateKnowledgeDetailMessage(string $topic): array {
    $articleData = [];

    switch ($topic) {
        case 'used_car':
            $articleData = [
                'badge' => '🚗 車選びの極意【①】',
                'badge_color' => '#3b82f6',
                'title' => '① 失敗しない中古車の選び方',
                'subtitle' => 'プロが教える！後悔しない5大見極め術',
                'sections' => [
                    [
                        'icon' => '1️⃣',
                        'title' => '年式と走行距離のバランス',
                        'desc' => "一般的な走行距離の目安は【1年＝約8,000km〜1万km】です。\n10年で1万kmなど極端に走行が少ない放置車よりも、年式相応に定期的に動いてオイル交換されていた車両の方が好調なケースが多いです。"
                    ],
                    [
                        'icon' => '2️⃣',
                        'title' => '修復歴（事故歴）の有無を確認',
                        'desc' => "「修復歴あり」とは車の骨格（フレーム）にダメージ・修理歴がある車を指します。\n外見が綺麗でも走行安定性に影響が出る可能性があるため、修復歴の有無を明確に開示している店舗を選びましょう。"
                    ],
                    [
                        'icon' => '3️⃣',
                        'title' => '定期点検記録簿（整備手帳）',
                        'desc' => "過去の点検や消耗品交換の履歴が残っている記録簿は、前オーナーが大切に乗っていた最大の証拠です。"
                    ],
                    [
                        'icon' => '4️⃣',
                        'title' => '車内のニオイと下回りのサビ',
                        'desc' => "写真ではわからないタバコ・ペット臭や、降雪地・沿岸部特有の下回りサビは要チェックです。"
                    ],
                    [
                        'icon' => '5️⃣',
                        'title' => '支払総額と保証内容',
                        'desc' => "車両本体価格の安さだけで判断せず、諸費用込みの「支払総額」と「保証期間・範囲」を必ず確認しましょう。"
                    ]
                ],
                'summary' => 'アップファーレンでは全車両の修復歴を開示し、厳選した高品質車両のみを支払総額明瞭で展示しております！',
                'action_btn' => [
                    'label' => '🚗 アップファーレンの在庫を見る',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'kei_vs_compact':
            $articleData = [
                'badge' => '🚙 徹底比較ガイド【②】',
                'badge_color' => '#8b5cf6',
                'title' => '② 軽自動車 vs 普通車の維持費比較',
                'subtitle' => '税金・車検・使い勝手のリアルな違い',
                'sections' => [
                    [
                        'icon' => '💴',
                        'title' => '税金・固定費の圧倒的な差',
                        'desc' => "・自動車税（年）：軽 10,800円 vs コンパクトカー 25,000〜30,500円（年間約1.5〜2万円差）\n・重量税（2年）：軽 6,600円 vs 普通車 16,400〜24,600円\n・高速道路料金：軽自動車は普通車より約20%割引！"
                    ],
                    [
                        'icon' => '🚗',
                        'title' => '最新の軽自動車の進化',
                        'desc' => "スライドドア（N-BOX・タント等）により大人4人がゆったり乗れ、シートアレンジや荷物の積載力も抜群。\n衝突被害軽減ブレーキ等の先進安全装備も普通車同等です。"
                    ],
                    [
                        'icon' => '🛣️',
                        'title' => '普通車（コンパクトカー）が向いている人',
                        'desc' => "高速道路を頻繁に利用する方、長距離運転が多い方、5人乗車する機会がある方は、静粛性やパワーに余裕がある普通車がおすすめです。"
                    ]
                ],
                'summary' => 'お客様の使い方やご予算に合わせて、最適な車種選びをプロがアドバイスいたします！',
                'action_btn' => [
                    'label' => '🚘 軽自動車の在庫一覧を見る',
                    'data' => 'action=search_kei'
                ]
            ];
            break;

        case 'body_type_guide':
            $articleData = [
                'badge' => '🚙 目的別・車選び【③】',
                'badge_color' => '#0284c7',
                'title' => '③ ボディタイプ別の特徴と選び方',
                'subtitle' => '用途や家族構成に合わせた最適車種診断',
                'sections' => [
                    [
                        'icon' => '🚗',
                        'title' => '軽ハイトワゴン（N-BOX/タント/スペーシア等）',
                        'desc' => "圧倒的な室内高とスライドドアで子育て世代や送迎・お買い物に最強。\n維持費の安さとリセールバリューの高さも大きな魅力です。"
                    ],
                    [
                        'icon' => '🚙',
                        'title' => 'SUV / クロスオーバー（ヤリスクロス/ヴェゼル等）',
                        'desc' => "アイポイントが高く運転しやすいのが特徴。悪路や雪道に強い4WDモデルも豊富で、アウトドア派や冬道重視の方に大人気です。"
                    ],
                    [
                        'icon' => '🚐',
                        'title' => 'ミニバン・コンパクトカー（セレナ/フリード/ノート等）',
                        'desc' => "3列シートで6〜8人乗れるミニバンは家族旅行に最適。\nコンパクトカーは小回りと低燃費、高速安定性のバランスに優れています。"
                    ]
                ],
                'summary' => 'アップファーレンでは軽からSUV・ミニバンまで豊富な在庫をご用意しております！',
                'action_btn' => [
                    'label' => '🚙 車種・ボディタイプで探す',
                    'data' => 'action=show_type_menu'
                ]
            ];
            break;

        case 'car_loan':
            $articleData = [
                'badge' => '💳 ローン＆資金計画【④】',
                'badge_color' => '#4f46e5',
                'title' => '④ オートローンの賢い選び方',
                'subtitle' => '金利の仕組みと無理のない返済プランの立て方',
                'sections' => [
                    [
                        'icon' => '🏦',
                        'title' => '主なローンの種類と特徴',
                        'desc' => "・ディーラー・提携ローン：店頭で即日審査可能＆手続きが簡単\n・銀行マイカーローン：低金利だが審査に数日〜1週間程度\n・自社ローン：他社で審査に不安がある方向けの独自プラン"
                    ],
                    [
                        'icon' => '📊',
                        'title' => '実質年率と支払総額の比較',
                        'desc' => "表面上の月々返済額だけでなく「分割手数料を含めた最終的な総支払額」を必ず確認しましょう。\n頭金やボーナス払いの併用で総金利を抑えられます。"
                    ],
                    [
                        'icon' => '💡',
                        'title' => '安心の返済比率（手取りの15〜20%）',
                        'desc' => "毎月の返済額は手取り月収の【15%〜20%以内】に抑えるのが、ガソリン代や保険料を含めても無理なく維持できる黄金比率です。"
                    ]
                ],
                'summary' => 'アップファーレンではお客様のライフスタイルに合わせた各種ローンシミュレーションを無料で行っております！',
                'action_btn' => [
                    'label' => '💬 お支払いプランをLINE相談',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'best_timing':
            $articleData = [
                'badge' => '💰 お得な買い時【⑤】',
                'badge_color' => '#ea580c',
                'title' => '⑤ 車のお得な買い時・購入時期',
                'subtitle' => '賢く買って得するベストなタイミング',
                'sections' => [
                    [
                        'icon' => '🗓',
                        'title' => '決算期（3月・9月）',
                        'desc' => "自動車業界の決算月である3月と中間決算の9月は、販売目標達成のため値引きやオプションサービスなどの特典が充実しやすい狙い目時期です。"
                    ],
                    [
                        'icon' => '🔄',
                        'title' => 'フルモデルチェンジ直後',
                        'desc' => "新型車が登場した直後は、前型モデルの下取り車や未使用車が多く市場に出回り、価格相場が下がりやすくお得に状態の良い車両が手に入ります。"
                    ],
                    [
                        'icon' => '💴',
                        'title' => '自動車税（4月課税）のタイミング',
                        'desc' => "自動車税は毎年4月1日時点の所有者に1年分課税されます。\n普通車は月割り課税ですが、軽自動車は月割り制度がないため【4月2日以降の購入】がお得です。"
                    ]
                ],
                'summary' => 'タイミングを見極めて、お目当ての愛車をお得に手に入れましょう！',
                'action_btn' => [
                    'label' => '🚗 現在の厳選在庫一覧を見る',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'car_paperwork':
            $articleData = [
                'badge' => '📄 手続き＆流れ【⑥】',
                'badge_color' => '#0891b2',
                'title' => '⑥ 必要書類と納車までの流れ',
                'subtitle' => '準備から納車当日までの完全ステップ',
                'sections' => [
                    [
                        'icon' => '1️⃣',
                        'title' => 'ご契約時の必要書類',
                        'desc' => "・普通車：印鑑証明書（発行後3ヶ月以内）、実印\n・軽自動車：住民票（発行後3ヶ月以内）、認印\n※車庫証明が必要な地域では保管場所承諾証明書等を用意します。"
                    ],
                    [
                        'icon' => '2️⃣',
                        'title' => '納車前点検・整備',
                        'desc' => "ご契約後、法定点検や消耗品交換（オイル・エレメント・バッテリー・ワイパー等）、車検取得、ボディ美装を徹底的に行います。"
                    ],
                    [
                        'icon' => '3️⃣',
                        'title' => '名義変更とナンバー登録',
                        'desc' => "管轄の陸運支局・軽自動車検査協会にて、お客様名義への登録手続きを店舗が代行いたします。"
                    ],
                    [
                        'icon' => '4️⃣',
                        'title' => '納車（約1〜3週間）',
                        'desc' => "お車のお引き渡し時に操作説明や保証書のお渡しを行い、安心のカーライフがスタートします！"
                    ]
                ],
                'summary' => 'アップファーレンでは面倒な名義変更や書類作成もフルサポートいたします！',
                'action_btn' => [
                    'label' => '💬 購入手続きについてLINEで相談',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'trade_in':
            $articleData = [
                'badge' => '🛡️ 査定額UPの秘訣【⑦】',
                'badge_color' => '#d97706',
                'title' => '⑦ 愛車を高く売る・下取りのコツ',
                'subtitle' => '査定士が見るポイントと乗り換えのベスト時期',
                'sections' => [
                    [
                        'icon' => '📋',
                        'title' => '定期点検記録簿の完備',
                        'desc' => "整備手帳にディーラーや整備工場での点検印・記録が揃っていると、大切に扱われていた証拠となり査定プラス評価になります。"
                    ],
                    [
                        'icon' => '💎',
                        'title' => '純正パーツ・説明書・スペアキー',
                        'desc' => "社外ナビやホイールに交換していても、純正パーツを保管しておくと査定が上がります。\nスペアキーの有無も数万円の査定差になることがあります。"
                    ],
                    [
                        'icon' => '🚭',
                        'title' => '車内の清潔感とニオイ対策',
                        'desc' => "タバコ臭やペット臭、シートのシミは減額対象になります。\n査定前に車内清掃と消臭を行っておくのが鉄則です。"
                    ],
                    [
                        'icon' => '🗓',
                        'title' => 'ベストな手放しタイミング',
                        'desc' => "車検が切れる直前や、中古車需要が高まる1〜3月・9月は高額査定が出やすい時期です。"
                    ]
                ],
                'summary' => 'アップファーレンでは愛車の下取り・無料査定を実施中！お乗り換えのご相談もお気軽にどうぞ。',
                'action_btn' => [
                    'label' => '💬 愛車の下取り・乗り換えを相談',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'oil':
            $articleData = [
                'badge' => '🛢️ 愛車長持ちの秘訣【⑧】',
                'badge_color' => '#f59e0b',
                'title' => '⑧ エンジンオイル交換の基本と真実',
                'subtitle' => '愛車の心臓を守る血液！適切な交換サイクル',
                'sections' => [
                    [
                        'icon' => '🩸',
                        'title' => 'エンジンオイルの5大役割',
                        'desc' => "エンジン内部の「潤滑・冷却・洗浄・防錆・密封」を担っています。\n走行しなくても空気中の水分や熱で半年〜1年で酸化劣化します。"
                    ],
                    [
                        'icon' => '⏱',
                        'title' => '適切な交換サイクルの目安',
                        'desc' => "・軽自動車／ターボ車：3,000〜5,000km または 半年\n・普通車（NA）：5,000〜10,000km または 半年〜1年\n※近距離のチョイ乗りが多い車はシビアコンディション（過酷環境）となり、早めの交換が推奨されます。"
                    ],
                    [
                        'icon' => '⚠️',
                        'title' => '交換を怠るとどうなる？',
                        'desc' => "オイルがドロドロになり燃費が悪化、異音の発生、最悪の場合はエンジンが焼き付き、載せ替えで30万〜50万円以上の高額出費になることもあります。"
                    ],
                    [
                        'icon' => '🔄',
                        'title' => 'オイルエレメント（フィルター）',
                        'desc' => "オイル内のスラッジ（ゴミ）をろ過するフィルターです。【オイル交換2回に1回】の同時交換が鉄則です。"
                    ]
                ],
                'summary' => '定期的なオイル交換こそが、愛車を最も安く・長く乗り続けるための最高の予防メンテナンスです。',
                'action_btn' => [
                    'label' => '📅 オイル交換の来店予約・相談',
                    'data' => 'action=ask_maintenance&type=oil'
                ]
            ];
            break;

        case 'periodic':
            $articleData = [
                'badge' => '📋 予防整備の基礎【⑨】',
                'badge_color' => '#10b981',
                'title' => '⑨ 法定12ヶ月定期点検の必要性',
                'subtitle' => '車検だけでは不十分！法律で定められた点検',
                'sections' => [
                    [
                        'icon' => '⚖️',
                        'title' => '車検と12ヶ月点検の決定的な違い',
                        'desc' => "・車検：受検した「その瞬間」に国の保安基準を満たしているかを確認する検査\n・12ヶ月点検：次の車検までの1年間、安全にトラブルなく走行できるかを分解・予防整備する点検"
                    ],
                    [
                        'icon' => '🔍',
                        'title' => '主な点検項目（26〜27項目）',
                        'desc' => "ブレーキの分解・清掃・残量確認、サスペンションのガタ、ベルト類の緩みや劣化、排気漏れ、オイル漏れなどをプロが徹底チェックします。"
                    ],
                    [
                        'icon' => '💡',
                        'title' => '定期点検を受ける3大メリット',
                        'desc' => "① 出先での突然の故障や事故を未然に防止\n② 消耗品の早期発見で将来の大きな修理代を節約\n③ 定期点検記録簿が残り、将来の車売却・下取り時の査定額がアップ！"
                    ]
                ],
                'summary' => '1年に1回のプロによる健康診断で、安心快適なカーライフを守りましょう！',
                'action_btn' => [
                    'label' => '📅 12ヶ月定期点検の予約・相談',
                    'data' => 'action=ask_maintenance&type=periodic'
                ]
            ];
            break;

        case 'inspection':
            $articleData = [
                'badge' => '🔍 安心＆スムーズ【⑩】',
                'badge_color' => '#6366f1',
                'title' => '⑩ 車検の基礎知識と賢い受け方',
                'subtitle' => '満了日の1ヶ月前から受検可能！準備と流れ',
                'sections' => [
                    [
                        'icon' => '🗓',
                        'title' => '受検のベストタイミング',
                        'desc' => "車検満了日の【1ヶ月前】から受けられます。\n1ヶ月前に受けても次回の満了日は短縮されず、有効期限は丸々2年（新車時3年）引き継がれます。"
                    ],
                    [
                        'icon' => '💰',
                        'title' => '車検費用の内訳と仕組み',
                        'desc' => "① 法定費用（国に納める重量税・自賠責保険料・印紙代＝どこでも一律）\n② 車検基本料・検査料・予防整備費用（お店によって異なる部分）"
                    ],
                    [
                        'icon' => '📄',
                        'title' => 'ご来店時の必要書類',
                        'desc' => "・自動車検査証（車検証）\n・自賠責保険証明書\n・自動車税納税証明書\n・認印 / ホイールロックナットアダプター（該当車）"
                    ]
                ],
                'summary' => 'アップファーレンでは事前無料お見積もりを実施中！不要な過剰整備は一切行わず、わかりやすくご説明いたします。',
                'action_btn' => [
                    'label' => '📅 車検の事前見積もり・予約相談',
                    'data' => 'action=ask_maintenance&type=inspection'
                ]
            ];
            break;

        case 'battery_tire':
            $articleData = [
                'badge' => '⚠️ トラブル予防【⑪】',
                'badge_color' => '#ef4444',
                'title' => '⑪ バッテリー・タイヤ・日常点検',
                'subtitle' => '突然の路上トラブルを防ぐ日常ケア',
                'sections' => [
                    [
                        'icon' => '🔋',
                        'title' => 'バッテリーの寿命（2〜3年）',
                        'desc' => "最近のバッテリーは直前まで元気に動くため前兆がわかりにくく、夏（エアコン多用）や冬（寒さで性能低下）に突然死します。\n2年以上経過していたらテスター診断をおすすめします。"
                    ],
                    [
                        'icon' => '🛞',
                        'title' => 'タイヤの交換サイン',
                        'desc' => "・残り溝1.6mm以下（スリップサイン露出＝車検不適合＆雨天スリップ危険）\n・製造から4〜5年経過（ゴムが硬化しひび割れ発生）\n・偏摩耗（片側だけ減る）"
                    ],
                    [
                        'icon' => '❄️',
                        'title' => 'エアコンの冷え・ニオイ',
                        'desc' => "エアコンフィルターは1年または1万kmごとの交換が目安。\n冷えが悪い場合はエアコンガスのクリーニング・補充で驚くほど復活します。"
                    ]
                ],
                'summary' => '少しでも「いつもと違う音や振動」を感じたら、放置せずお気軽にご相談ください！',
                'action_btn' => [
                    'label' => '🛠️ 来店・点検相談フォームを開く',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;

        case 'brake_care':
            $articleData = [
                'badge' => '🛑 安全の要・ブレーキ【⑫】',
                'badge_color' => '#e11d48',
                'title' => '⑫ ブレーキの寿命と重要チェック',
                'subtitle' => '命を守る最重要パーツ！キーキー音は見逃すな',
                'sections' => [
                    [
                        'icon' => '📏',
                        'title' => 'ブレーキパッドの残厚（3mmで即交換）',
                        'desc' => "新品約10mmから摩耗し、残厚3mm以下は危険水域です。\n限界を超えるとディスクローターを削ってしまい、高額な部品交換が必要になります。"
                    ],
                    [
                        'icon' => '🔊',
                        'title' => 'ブレーキ鳴き（キーキー音）のサイン',
                        'desc' => "ブレーキを踏んだ時に金属音が鳴るのは、パッド摩耗を知らせるセンサー（ウェアインジケーター）が接触している音です。早急に点検を受けましょう。"
                    ],
                    [
                        'icon' => '💧',
                        'title' => 'ブレーキフルード（2年毎交換）',
                        'desc' => "ブレーキオイルは空気中の水分を吸収して劣化します。\n劣化すると下り坂などでオイルが沸騰しブレーキが利かなくなる「ベーパーロック現象」の原因になります。"
                    ]
                ],
                'summary' => 'アップファーレンではブレーキの残量測定・フルード点検を迅速に実施いたします！',
                'action_btn' => [
                    'label' => '🛠️ ブレーキ点検を予約・相談',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;

        case 'aircon_care':
            $articleData = [
                'badge' => '❄️ 快適ドライブ【⑬】',
                'badge_color' => '#0ea5e9',
                'title' => '⑬ カーエアコンの効き＆悪臭ケア',
                'subtitle' => '夏場の冷え不良・カビ臭をスッキリ解決！',
                'sections' => [
                    [
                        'icon' => '🧽',
                        'title' => 'エアコンフィルター（年1回交換）',
                        'desc' => "ホコリ・花粉・排ガスをキャッチするフィルターです。\n目詰まりすると風量が弱くなり、湿気でカビや嫌なニオイが発生します。"
                    ],
                    [
                        'icon' => '💧',
                        'title' => 'エバポレーターの内部洗浄',
                        'desc' => "エアコン内部の冷却ユニット（エバポレーター）は結露でカビの温床になりがちです。\n専用ケミカルでの高圧洗浄消臭で新車のような爽やかな風が蘇ります。"
                    ],
                    [
                        'icon' => '❄️',
                        'title' => 'エアコンガスのクリーニング・補充',
                        'desc' => "配管の継ぎ目などからガスは毎年微量ずつ抜けます。\nガス圧の真空引き補充とコンプレッサーオイル添加剤で冷却性能が驚くほどUPします。"
                    ]
                ],
                'summary' => '「冷えが悪い」「カビ臭い」と感じたら、本格的な夏・冬の前にメンテナンスをおすすめします！',
                'action_btn' => [
                    'label' => '🛠️ エアコン点検・相談をする',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;

        case 'car_wash_care':
            $articleData = [
                'badge' => '🧼 愛車ケア＆美観【⑭】',
                'badge_color' => '#06b6d4',
                'title' => '⑭ 洗車＆ボディコーティング術',
                'subtitle' => '愛車の輝きを長く保つプロのお手入れ法',
                'sections' => [
                    [
                        'icon' => '☀️',
                        'title' => '炎天下・直射日光での洗車はNG',
                        'desc' => "日差しで水滴がレンズの役割を果たし塗装を痛める「ウォータースポット」や、水道水のミネラル分が焼き付く「イオンデポジット」の原因になります。\n曇りの日や朝夕の涼しい時間帯がベストです。"
                    ],
                    [
                        'icon' => '🧽',
                        'title' => '洗車キズを防ぐ洗い方のコツ',
                        'desc' => "① まずたっぷりの水で砂・ホコリを上から洗い流す\n② カーシャンプーをしっかり泡立てて「泡のクッション」で優しく洗う\n③ タイヤ・下回りはボディとスポンジを分ける"
                    ],
                    [
                        'icon' => '✨',
                        'title' => 'ガラス系コーティングのメリット',
                        'desc' => "塗装表面に硬い被膜を形成し、紫外線や酸性雨、鳥フンによる劣化を防ぎます。\n水洗いで汚れがスルッと落ちるため日頃のお手入れが格段に楽になります。"
                    ]
                ],
                'summary' => 'アップファーレンでは納車時のプロコーティング施工やボディケアのご相談も承っております！',
                'action_btn' => [
                    'label' => '🛠️ コーティング・洗車相談をする',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;

        case 'winter_driving':
            $articleData = [
                'badge' => '❄️ 冬道・降雪対策【⑮】',
                'badge_color' => '#0284c7',
                'title' => '⑮ 雪道運転と冬タイヤの極意',
                'subtitle' => '降雪地域の安心カーライフ！冬支度の鉄則',
                'sections' => [
                    [
                        'icon' => '🛞',
                        'title' => 'スタッドレスタイヤの寿命基準',
                        'desc' => "溝の深さが新品時の50%になると現れる【プラットホーム】が露出すると冬用タイヤとしては使用不可になります。\nまた製造から3〜4シーズンでゴムが硬化し氷上ブレーキ性能が低下します。"
                    ],
                    [
                        'icon' => '🛡️',
                        'title' => '下回りの防錆コーティング（塩害対策）',
                        'desc' => "道路に撒かれる融雪剤（塩化カルシウム）は愛車の下回りを急速にサビさせます。\n冬前の下回り高圧洗浄と防錆アンダーコート塗装が愛車を守ります。"
                    ],
                    [
                        'icon' => '💧',
                        'title' => '寒冷地用ウォッシャー液とワイパー',
                        'desc' => "通常のウォッシャー液は寒さで凍結しタンク破損の原因になります。\n冬用（原液-30℃対応）への入れ替えと、凍りつかないスノーワイパーの装着が安心です。"
                    ]
                ],
                'summary' => 'アップファーレンでは冬タイヤの履き替え・下回り防錆点検も随時承っております！',
                'action_btn' => [
                    'label' => '🛠️ タイヤ交換・冬点検を相談',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;

        case 'warning_lights':
            $articleData = [
                'badge' => '🚨 緊急・トラブル診断【⑯】',
                'badge_color' => '#dc2626',
                'title' => '⑯ 警告灯の意味と緊急時の対処法',
                'subtitle' => '色でわかる危険度と初期対応マニュアル',
                'sections' => [
                    [
                        'icon' => '🔴',
                        'title' => '赤色ランプ＝【直ちに安全な場所へ停車】',
                        'desc' => "・油圧警告灯（オイル不足・油圧低下）：エンジン破損の危険\n・水温警告灯（オーバーヒート）：直ちに停車しエンジン冷却\n・ブレーキ警告灯（フルード漏れ・残量ゼロ）：ブレーキ不能の恐れ\n・充電警告灯（オルタネーター故障）：バッテリー走行となり近々停止"
                    ],
                    [
                        'icon' => '🟡',
                        'title' => '黄色/オレンジ色ランプ＝【早めに整備工場へ】',
                        'desc' => "・エンジン警告灯（センサー系・排気系の異常）\n・ABS警告灯（安全装置の不作動）\n・空気圧警告灯（パンクの疑い）"
                    ],
                    [
                        'icon' => '🔊',
                        'title' => '走行中の異音チェック',
                        'desc' => "・ブレーキ時のキーキー音（パッド摩耗サイン）\n・段差でのコトコト音（サスペンションブッシュ摩耗）\n・加速時のゴー音（ハブベアリング寿命）"
                    ]
                ],
                'summary' => '警告灯が点灯したり普段と違う異音を感じたら、無理に走行を続けずすぐにご連絡ください！',
                'action_btn' => [
                    'label' => '🛠️ 異音・不具合の点検を相談',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;

        case 'rain_driving':
            $articleData = [
                'badge' => '🌧️ 雨天・悪天候対策【⑰】',
                'badge_color' => '#2563eb',
                'title' => '⑰ 雨の日の安全運転と冠水対策',
                'subtitle' => 'スリップ防止＆大雨時の水没トラブル回避術',
                'sections' => [
                    [
                        'icon' => '🌊',
                        'title' => '冠水道路の走行限界（ドア下部まで）',
                        'desc' => "水深がマフラーやエアクリーナー吸気口（グリルの高さ）を超えると、エンジン内部に水が吸い込まれ「ウォーターハンマー現象」でエンジンが全損・廃車になります。\n水たまりの深さが不明な場所は絶対に進入してはいけません。"
                    ],
                    [
                        'icon' => '🛞',
                        'title' => 'ハイドロプレーニング現象の恐怖',
                        'desc' => "溝の減ったタイヤで雨の高速道路を走ると、水膜の上に車が浮いてハンドルやブレーキが一切利かなくなります。\n雨天時は通常より時速10〜20km速度を落とすのが鉄則です。"
                    ],
                    [
                        'icon' => '👀',
                        'title' => '雨天のクリアな視界確保',
                        'desc' => "フロントガラスの油膜取り＋撥水コーティング施工と、拭きムラのないワイパーゴムの定期交換（半年〜1年毎）が豪雨時の安全を左右します。"
                    ]
                ],
                'summary' => '雨天時の視界不良やスリップが気になる方は、ワイパー交換やガラス撥水施工をお気軽にご相談ください！',
                'action_btn' => [
                    'label' => '🛠️ ワイパー・撥水コーティング相談',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;

        case 'accident_guide':
            $articleData = [
                'badge' => '💥 緊急初動マニュアル【⑱】',
                'badge_color' => '#b91c1c',
                'title' => '⑱ 事故・故障時の緊急対応手順',
                'subtitle' => '焦らず行動！現場で絶対にやるべき4ステップ',
                'sections' => [
                    [
                        'icon' => '1️⃣',
                        'title' => '二次災害の防止と安全確保',
                        'desc' => "ハザードランプを点灯し、車を発煙筒や停止表示器材（三角板）で後続車に知らせます。\n高速道路では車内に残らず、ガードレールの外側など安全な場所に避難してください。"
                    ],
                    [
                        'icon' => '2️⃣',
                        'title' => '負傷者の救護（119番通報）',
                        'desc' => "けが人がいる場合は直ちに救急車を呼び、必要に応じて止血などの応急手当を行います。"
                    ],
                    [
                        'icon' => '3️⃣',
                        'title' => '警察への届出（110番通報・必須）',
                        'desc' => "どんなに軽微な物損事故や自損事故でも、警察への届出は法律上の義務です。\n届出がないと「交通事故証明書」が発行されず、保険金が支払われません。"
                    ],
                    [
                        'icon' => '4️⃣',
                        'title' => '相手の確認と保険会社・店舗への連絡',
                        'desc' => "相手の氏名・電話番号・車のナンバー・保険会社をメモします。\nその場で示談や口約束はせず、ご加入の自動車保険会社と当店へすぐにご連絡ください。"
                    ]
                ],
                'summary' => '万が一の事故やお車のトラブル時は、アップファーレンへもお気軽にご相談ください。レッカー手配や修理見積もりをサポートいたします。',
                'action_btn' => [
                    'label' => '💬 店舗へLINEで連絡する',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'fuel_economy':
            $articleData = [
                'badge' => '⛽ 燃費＆節約術【⑲】',
                'badge_color' => '#059669',
                'title' => '⑲ 燃費アップ＆愛車の節約術',
                'subtitle' => 'ちょっとしたコツで年間数万円の節約に！',
                'sections' => [
                    [
                        'icon' => '🟢',
                        'title' => 'ふんわりアクセル「eスタート」',
                        'desc' => "発進時の最初の5秒で時速20kmを目安にゆっくり踏み出すだけで、約10%燃費が向上します。\n車間距離に余裕を持った等速走行も効果的です。"
                    ],
                    [
                        'icon' => '💨',
                        'title' => 'タイヤ空気圧の定期点検',
                        'desc' => "空気圧は走行しなくても自然に【1ヶ月で約5〜10%】低下します。\n空気圧が適正値より50kPa低いと燃費が約2〜4%悪化します。月1回の補充がおすすめです。"
                    ],
                    [
                        'icon' => '📦',
                        'title' => '不要な積載物の降車',
                        'desc' => "100kgの荷物を積むと燃費が約3%悪化します。\nトランクに乗せっぱなしのアウトドア用品や工具類は整理しましょう。"
                    ],
                    [
                        'icon' => '🛢️',
                        'title' => '低粘度オイルの活用',
                        'desc' => "指定粘度（0W-20や0W-16など）の省燃費オイルを使用することで、エンジン内部の抵抗を減らし燃費を維持できます。"
                    ]
                ],
                'summary' => '日頃の小さな意識と定期的な点検で、ガソリン代を賢く節約しましょう！',
                'action_btn' => [
                    'label' => '🚗 燃費良好な在庫車両を見る',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'beginner_driver':
            $articleData = [
                'badge' => '🔰 安心ドライブ【⑳】',
                'badge_color' => '#16a34a',
                'title' => '⑳ 初心者・ペーパードライバー安心術',
                'subtitle' => '運転の不安を解消する基本テクニック',
                'sections' => [
                    [
                        'icon' => '📐',
                        'title' => '車幅感覚の掴み方',
                        'desc' => "運転席から見て「道路の白線がフロントガラスのどこを通るか」を目印に覚えると、左寄りの感覚が簡単に掴めます。\nボンネットの見切りが良い車を選ぶのもポイントです。"
                    ],
                    [
                        'icon' => '🅿️',
                        'title' => 'バック駐車のコツ',
                        'desc' => "駐車枠に対して約45度に車体を傾けてからバックを開始し、サイドミラーで隣の車の角と自分の後輪の位置関係を確認しながらゆっくり下がると一発で収まります。"
                    ],
                    [
                        'icon' => '👀',
                        'title' => '死角の確認と車間距離',
                        'desc' => "ミラーだけでなく目視での死角確認が事故防止の鍵です。\n車間距離は「前の車が通過した地点を自分が2秒後に通過する」間隔を目安に保ちましょう。"
                    ]
                ],
                'summary' => '見切りの良いコンパクトカーやバックカメラ付きの軽自動車など、運転しやすいお車を多数ご用意しております！',
                'action_btn' => [
                    'label' => '🚘 運転しやすい軽・コンパクトを見る',
                    'data' => 'action=search_kei'
                ]
            ];
            break;

        case 'car_accessories':
            $articleData = [
                'badge' => '🔌 便利アイテム・装備【㉑】',
                'badge_color' => '#7c3aed',
                'title' => '㉑ ドラレコ・ETC・LED便利知識',
                'subtitle' => '後付け・アップグレードで愛車がもっと快適に！',
                'sections' => [
                    [
                        'icon' => '📷',
                        'title' => 'ドライブレコーダー（前後2カメラ必須時代）',
                        'desc' => "あおり運転や追突対策に「前方＋後方録画」が今や常識です。\n夜間も鮮明に映るSTARVIS（高感度センサー）搭載モデルや駐車監視機能付きがおすすめです。"
                    ],
                    [
                        'icon' => '🛣️',
                        'title' => 'ETC2.0のメリットと活用法',
                        'desc' => "圏央道などの高速料金割引（約2割引）や、一時退出（道の駅利用で高速を降りても料金据え置き）など、長距離ドライブでお得な機能が満載です。"
                    ],
                    [
                        'icon' => '💡',
                        'title' => 'LEDヘッドライト化の注意点',
                        'desc' => "暗いハロゲンランプから高輝度LEDへ交換すると夜間の視認性が劇的に向上します。\n車検対応のカットライン（配光性能）がしっかり出る高品質バルブを選ぶのが鉄則です。"
                    ]
                ],
                'summary' => 'アップファーレンでは持ち込みドラレコやETC・ナビ・LEDの取り付け・配線加工もプロが丁寧に行います！',
                'action_btn' => [
                    'label' => '🛠️ パーツ取付・カスタム相談',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;

        case 'car_appraisal':
            $articleData = [
                'badge' => '💴 愛車売却＆査定UP【㉒】',
                'badge_color' => '#d97706',
                'title' => '㉒ 愛車を高く売る・査定UP術',
                'subtitle' => '手放す前に知っておきたい高価買取の4大鉄則',
                'sections' => [
                    [
                        'icon' => '🧼',
                        'title' => '査定前の洗車と車内消臭・清掃',
                        'desc' => "第一印象は極めて重要です。タバコ・ペット・芳香剤の臭いを抜き、洗車と室内清掃をしておくだけで「大切に乗られてきた車」として査定士の評価が上がります。"
                    ],
                    [
                        'icon' => '📦',
                        'title' => '純正パーツ・取扱説明書・スペアキーの保管',
                        'desc' => "社外アルミやナビに変えている場合も、純正品を揃えておくとプラス査定に。\n整備手帳（記録簿）とスペアキーの有無で数万円の差がつきます。"
                    ],
                    [
                        'icon' => '🛠️',
                        'title' => '小さなキズは無理に直さない',
                        'desc' => "自分でタッチペン補修をするとかえって目立ち減額になることがあります。\nプロの板金費用以上の査定アップは見込めないため、そのまま査定に出すのが鉄則です。"
                    ],
                    [
                        'icon' => '🗓',
                        'title' => 'フルモデルチェンジ前・車検満了前に動く',
                        'desc' => "新型が出ると相場が下落します。車検を通す前の1〜2ヶ月前に査定比較するのが一番得策です。"
                    ]
                ],
                'summary' => 'アップファーレンでは愛車の無料出張査定・高価下取りをいつでも承っております！',
                'action_btn' => [
                    'label' => '💬 愛車の無料査定・相談をする',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;

        case 'tire_rotation':
            $articleData = [
                'badge' => '🛞 タイヤ長持ち・安全【㉓】',
                'badge_color' => '#0284c7',
                'title' => '㉓ タイヤローテーションと偏摩耗',
                'subtitle' => '寿命を1.5倍に延ばす位置交換の基本',
                'sections' => [
                    [
                        'icon' => '🔄',
                        'title' => '前後のタイヤ摩耗差の正体',
                        'desc' => "前輪駆動（FF車）はハンドル操作と駆動を同時に担うため、前輪が後輪の2〜3倍の速さで摩耗します。\n定期的に前後を入れ替えないと前輪だけが早期にツルツルになってしまいます。"
                    ],
                    [
                        'icon' => '⏱',
                        'title' => '交換目安（走行5,000kmまたは半年）',
                        'desc' => "オイル交換や季節ごとのタイヤ履き替え（スタッドレス↔夏タイヤ）のタイミングで前後を入れ替えるのが最も効率的です。"
                    ],
                    [
                        'icon' => '📐',
                        'title' => '偏摩耗（片減り）の早期発見',
                        'desc' => "内側や外側だけが極端に削れている場合、空気圧不足や足回りのアライメント狂いが原因です。走行中の直進安定性にも影響します。"
                    ]
                ],
                'summary' => 'タイヤの無料残溝チェックやローテーション作業もお気軽にご用命ください！',
                'action_btn' => [
                    'label' => '🛠️ タイヤ点検・交換を相談',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;

        case 'disaster_car_stay':
            $articleData = [
                'badge' => '🏕️ 防災・緊急車中泊【㉔】',
                'badge_color' => '#dc2626',
                'title' => '㉔ 車の防災＆災害時車中泊マニュアル',
                'subtitle' => '豪雪立ち往生や震災時に命を守る備え',
                'sections' => [
                    [
                        'icon' => '☠️',
                        'title' => 'マフラー埋没による一酸化炭素中毒防止',
                        'desc' => "大雪で立ち往生した際、排気口（マフラー）が雪で埋まると排ガスが車内に逆流し数十分で命の危険に！\nエンジンをかける時はマフラー周囲の除雪を欠かさず、風下側の窓を数センチ開けておきます。"
                    ],
                    [
                        'icon' => '🔨',
                        'title' => '緊急脱出用ガラス割りハンマーの車載',
                        'desc' => "冠水や事故でドアが開かなくなった際、水圧がかかった窓は手や足では絶対に割れません。手の届く運転席周りに専用ハンマーを備えましょう。"
                    ],
                    [
                        'icon' => '🎒',
                        'title' => '車載すべき防災7つ道具',
                        'desc' => "①毛布・防寒アルミシート ②モバイルバッテリー ③非常食・飲料水 ④携帯トイレ ⑤スコップ・解氷スプレー ⑥牽引ロープ ⑦長靴・手袋。"
                    ]
                ],
                'summary' => '新潟の厳しい冬や突然の災害に備え、お車に防災グッズを常備しておきましょう！',
                'action_btn' => [
                    'label' => '🚗 在庫車両をチェックする',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'headlight_yellowing':
            $articleData = [
                'badge' => '✨ 美観＆夜間視界【㉕】',
                'badge_color' => '#7c3aed',
                'title' => '㉕ ヘッドライト黄ばみ除去と予防',
                'subtitle' => '見た目の若返り＆車検の光量不足対策',
                'sections' => [
                    [
                        'icon' => '☀️',
                        'title' => '黄ばみ・くすみの原因（ポリカーボネートの紫外線劣化）',
                        'desc' => "現代のヘッドライトは樹脂製のため、日光の紫外線と経年熱で表面のクリア塗装が劣化し黄変・白濁します。"
                    ],
                    [
                        'icon' => '⚠️',
                        'title' => '放置すると車検落ち＆夜間危険！',
                        'desc' => "黄ばみが進行すると光が拡散し、車検基準の「すれ違い用前照灯（ロービーム光度）」を満たせず車検に不合格になる事例が急増しています。"
                    ],
                    [
                        'icon' => '✨',
                        'title' => '研磨クリーニング＆専用コーティング',
                        'desc' => "黄ばんだ表層を耐水研磨で削り落とし、ガラス系またはウレタンクリアコートで再保護することで、新車時の透明感と照射光量が蘇ります。"
                    ]
                ],
                'summary' => 'アップファーレンではヘッドライトのクリーニング＆プロコーティングも施工可能です！',
                'action_btn' => [
                    'label' => '🛠️ ヘッドライト磨きを相談',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;

        case 'smart_key_battery':
            $articleData = [
                'badge' => '🔑 トラブル緊急脱出【㉖】',
                'badge_color' => '#e11d48',
                'title' => '㉖ スマートキー電池切れ時の始動法',
                'subtitle' => '鍵が開かない・かからない時の完全手順',
                'sections' => [
                    [
                        'icon' => '🗝️',
                        'title' => '内蔵メカニカルキーでドアを解錠',
                        'desc' => "スマートキー側面の解除ボタンをスライドさせると、物理キーが引き出せます。運転席ドアの鍵穴に差し込んで回せばドアが開きます。"
                    ],
                    [
                        'icon' => '🔘',
                        'title' => 'スタートボタンにスマートキーをタッチ！',
                        'desc' => "ブレーキペダルを踏みながら、スマートキーの「エンブレム面」をプッシュスタートボタンに直接密着（タッチ）させると「ピッ」と音が鳴り、そのままボタンを押せばエンジンが始動します。"
                    ],
                    [
                        'icon' => '🔋',
                        'title' => '電池寿命（約1〜2年）と交換用電池（CR2032等）',
                        'desc' => "スマートキーは常に電波を受信しているため1〜2年で消耗します。ボタン電池（主にCR2032やCR1632など）はコンビニ等で購入でき、自分で簡単に交換可能です。"
                    ]
                ],
                'summary' => 'スマートキーの電池交換も店頭で数十秒で対応いたしますのでお気軽にどうぞ！',
                'action_btn' => [
                    'label' => '🛠️ 愛車の相談・点検予約',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;

        case 'hybrid_battery_care':
            $articleData = [
                'badge' => '🔋 HV・EVの賢い乗り方【㉗】',
                'badge_color' => '#059669',
                'title' => '㉗ ハイブリッド車のバッテリー延命術',
                'subtitle' => '駆動用バッテリー長持ち＆補機バッテリーの盲点',
                'sections' => [
                    [
                        'icon' => '🌡️',
                        'title' => '高温放置と急加速・急放電の回避',
                        'desc' => "リチウムイオン/ニッケル水素バッテリーは熱に弱いです。炎天下での長時間駐車を避け、冷却ファンの吸気口（後部座席横）に荷物を置かないようにしましょう。"
                    ],
                    [
                        'icon' => '⚡',
                        'title' => '見落としがちな「補機バッテリー」の寿命（3年）',
                        'desc' => "ハイブリッド車には走行用とは別に「システム起動用の12V補機バッテリー」が載っています。これが上がると大容量バッテリーが満タンでも車が起動できません！"
                    ],
                    [
                        'icon' => '📉',
                        'title' => '定期的な走行で完全放電を防ぐ',
                        'desc' => "数ヶ月放置すると自然放電で駆動用バッテリーの容量が低下します。月2〜3回はエンジンをかけて30分以上走行させましょう。"
                    ]
                ],
                'summary' => 'アップファーレンでは良質なハイブリッド・低燃費エコカーを多数取り揃えております！',
                'action_btn' => [
                    'label' => '🚗 ハイブリッド在庫車両を見る',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'daily_car_check':
        default:
            $articleData = [
                'badge' => '🔍 5分セルフ点検【㉘】',
                'badge_color' => '#2563eb',
                'title' => '㉘ 日常点検「ぶ・た・は・と・う・み・ず」',
                'subtitle' => 'プロ推奨！ドライブ前の簡単セルフチェック',
                'sections' => [
                    [
                        'icon' => '🛑',
                        'title' => '【ぶ】ブレーキ＆ベルト',
                        'desc' => "ブレーキペダルの踏みごたえ（床まで沈み込まないか）と、エンジン始動時のキュルキュル異音がないか。"
                    ],
                    [
                        'icon' => '🛞',
                        'title' => '【た】タイヤ',
                        'desc' => "空気圧の見た目（極端に潰れていないか）、溝の残り深さ、亀裂や釘刺さりがないか。"
                    ],
                    [
                        'icon' => '💡',
                        'title' => '【は・とう】バッテリー＆灯火類',
                        'desc' => "セルモーターの始動音、ヘッドライト・ブレーキランプ・ウインカーの球切れがないか。"
                    ],
                    [
                        'icon' => '💧',
                        'title' => '【み・ず】オイル（みず）・冷却水・ウォッシャー液',
                        'desc' => "エンジンオイル量、ラジエーター冷却水の量（リザーブタンク）、ウォッシャー液の残量をチェック。"
                    ]
                ],
                'summary' => 'お出かけ前の無料安心点検も店頭でいつでも承っております！お気軽にお立ち寄りください。',
                'action_btn' => [
                    'label' => '🛠️ 店舗で無料点検を受ける',
                    'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html'
                ]
            ];
            break;
    }

    $sectionBoxes = [];
    foreach ($articleData['sections'] as $sec) {
        $desc = str_replace('\\n', "\n", $sec['desc']);
        $title = str_replace('\\n', "\n", $sec['title']);
        $sectionBoxes[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'md',
            'backgroundColor' => '#f8fafc',
            'paddingAll' => '12px',
            'cornerRadius' => 'md',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'baseline',
                    'contents' => [
                        ['type' => 'text', 'text' => $sec['icon'] . ' ' . $title, 'weight' => 'bold', 'size' => 'sm', 'color' => '#1e293b', 'wrap' => true]
                    ]
                ],
                [
                    'type' => 'text',
                    'text' => $desc,
                    'size' => 'xs',
                    'color' => '#475569',
                    'wrap' => true,
                    'margin' => 'sm'
                ]
            ]
        ];
    }

    $mainBtnAction = [];
    if (!empty($articleData['action_btn']['uri'])) {
        $mainBtnAction = [
            'type' => 'uri',
            'label' => $articleData['action_btn']['label'],
            'uri' => $articleData['action_btn']['uri']
        ];
    } else {
        $mainBtnAction = [
            'type' => 'postback',
            'label' => $articleData['action_btn']['label'],
            'data' => $articleData['action_btn']['data']
        ];
    }

    $detailBubble = [
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
                        [
                            'type' => 'text',
                            'text' => $articleData['badge'],
                            'weight' => 'bold',
                            'size' => 'xs',
                            'color' => $articleData['badge_color']
                        ]
                    ]
                ],
                [
                    'type' => 'text',
                    'text' => $articleData['title'],
                    'weight' => 'bold',
                    'size' => 'lg',
                    'color' => '#1e293b',
                    'margin' => 'xs'
                ],
                [
                    'type' => 'text',
                    'text' => $articleData['subtitle'],
                    'size' => 'xs',
                    'color' => '#64748b',
                    'margin' => 'xs'
                ],
                [
                    'type' => 'separator',
                    'margin' => 'md'
                ],
                [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'margin' => 'md',
                    'contents' => $sectionBoxes
                ],
                [
                    'type' => 'separator',
                    'margin' => 'lg'
                ],
                [
                    'type' => 'text',
                    'text' => '💡 ' . str_replace('\\n', "\n", $articleData['summary']),
                    'size' => 'xs',
                    'color' => '#334155',
                    'wrap' => true,
                    'margin' => 'md'
                ]
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
                    'color' => '#06C755',
                    'height' => 'sm',
                    'action' => $mainBtnAction
                ],
                [
                    'type' => 'button',
                    'style' => 'secondary',
                    'height' => 'sm',
                    'color' => '#f1f5f9',
                    'action' => [
                        'type' => 'uri',
                        'label' => '👥 この豆知識を友だちにシェア',
                        'uri' => 'https://liff.line.me/2000276344-YL1wXh0h/share.html?topic=' . urlencode($topic)
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'secondary',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '📚 豆知識ガイド一覧へ戻る',
                        'data' => 'action=show_knowledge_menu'
                    ]
                ]
            ]
        ]
    ];

    return [
        [
            'type' => 'flex',
            'altText' => "【{$articleData['title']}】カーライフお役立ちガイド",
            'contents' => $detailBubble,
            'quickReply' => getQuickReplyItems()
        ]
    ];
}

/**
 * 車種・ボディタイプ選択メニュー送信
 */
function sendTypeMenuMessage(PDO $db, string $replyToken) {
    $messages = generateTypeMenuMessages($db);
    sendReplyMessage($replyToken, $messages);
}

/**
 * 車種・ボディタイプ選択メニュー（サイレントボタン式Flex カルーセル）生成
 * 現在の有効在庫（carsテーブル）から実在するボディタイプおよび人気車種を自動集計し、
 * 「該当台数（例: (10台)）」バッジ付きでカルーセル化（0件の車種は自動非表示）
 */
function generateTypeMenuMessages(PDO $db): array {
    // 1. 有効在庫の全データを取得
    $stmt = $db->query("SELECT id, title, displacement, drive_type FROM cars WHERE is_active = 1");
    $cars = $stmt->fetchAll();
    $totalStock = count($cars);

    if (empty($cars)) {
        return [
            [
                'type' => 'text',
                'text' => "現在、展示中の在庫車両を準備中です。\n最新の入庫状況はお気軽にお問い合わせください！",
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }

    // 2. ボディタイプ定義
    $bodyTypeDefs = [
        [
            'name' => '軽自動車',
            'action' => 'search_kei',
            'param' => '',
            'check' => function($car) {
                $disp = $car['displacement'] ?? '';
                $title = $car['title'] ?? '';
                return ($disp === '660cc' || str_starts_with($disp, '66') || str_contains($title, '軽自動車'));
            }
        ],
        [
            'name' => 'ｺﾝﾊﾟｸﾄｶｰ',
            'action' => 'search_type',
            'param' => 'keyword=' . urlencode('コンパクト'),
            'check' => function($car) {
                $disp = $car['displacement'] ?? '';
                $title = $car['title'] ?? '';
                if ($disp === '660cc' || str_starts_with($disp, '66')) return false;
                $kws = ['コンパクト', 'フィット', 'アクア', 'ヤリス', 'ノート', 'パッソ', 'スイフト', 'ヴィッツ', 'デミオ', 'マーチ', 'ポロ', 'ゴルフ', 'ルーミー', 'ソリオ', 'タンク', 'FIT', 'AQUA', 'NOTE', 'SWIFT'];
                foreach ($kws as $kw) {
                    if (stripos($title, $kw) !== false) return true;
                }
                return false;
            }
        ],
        [
            'name' => 'ﾐﾆﾊﾞﾝ･ﾜｺﾞﾝ',
            'action' => 'search_type',
            'param' => 'keyword=' . urlencode('ワゴン'),
            'check' => function($car) {
                $title = $car['title'] ?? '';
                $kws = ['ワゴン', 'セレナ', 'ヴォクシー', 'ノア', 'ステップワゴン', 'フリード', 'シエンタ', 'アルファード', 'ヴェルファイア', 'デリカ', 'エスティマ', 'オデッセイ', 'SERENA', 'VOXY', 'NOAH'];
                foreach ($kws as $kw) {
                    if (stripos($title, $kw) !== false) return true;
                }
                return false;
            }
        ],
        [
            'name' => 'SUV･4WD',
            'action' => 'search_type',
            'param' => 'keyword=' . urlencode('4WD'),
            'check' => function($car) {
                $drive = $car['drive_type'] ?? '';
                $title = $car['title'] ?? '';
                if (stripos($drive, '4WD') !== false || stripos($drive, '４ＷＤ') !== false || stripos($drive, '四駆') !== false || stripos($drive, 'AWD') !== false || stripos($drive, 'ＡＷＤ') !== false) {
                    return true;
                }
                if (stripos($title, '4WD') !== false || stripos($title, '４ＷＤ') !== false || stripos($title, 'AWD') !== false || stripos($title, 'SUV') !== false || stripos($title, 'クロスオーバー') !== false || stripos($title, 'キャデラック') !== false || stripos($title, 'XT5') !== false) {
                    return true;
                }
                $kws = ['ハスラー', 'ジムニー', 'ヴェゼル', 'ヤリスクロス', 'ライズ', 'ロッキー', 'エクストレイル', 'フォレスター', 'CX-', 'C-HR'];
                foreach ($kws as $kw) {
                    if (stripos($title, $kw) !== false) return true;
                }
                return false;
            }
        ]
    ];

    // 3. 人気車種モデルマスター定義
    $modelDefs = [
        ['name' => 'N-BOX', 'keyword' => 'N-BOX', 'match' => ['N-BOX', 'Ｎ－ＢＯＸ', 'NBOX', 'エヌボックス']],
        ['name' => 'ﾀﾝﾄ', 'keyword' => 'タント', 'match' => ['タント', 'ﾀﾝﾄ', 'TANTO']],
        ['name' => 'ｽﾍﾟｰｼｱ', 'keyword' => 'スペーシア', 'match' => ['スペーシア', 'ｽﾍﾟｰｼｱ', 'SPACIA']],
        ['name' => 'ﾜｺﾞﾝR', 'keyword' => 'ワゴンR', 'match' => ['ワゴンR', 'ワゴンＲ', 'ﾜｺﾞﾝR', 'WAGON R', 'スティングレー']],
        ['name' => 'ﾃﾞｲｽﾞ/ﾙｰｸｽ', 'keyword' => 'デイズ', 'match' => ['デイズ', 'ﾃﾞｲｽﾞ', 'ルークス', 'ﾙｰｸｽ', 'DAYZ', 'ROOX']],
        ['name' => 'ﾊｽﾗｰ', 'keyword' => 'ハスラー', 'match' => ['ハスラー', 'ﾊｽﾗｰ', 'HUSTLER']],
        ['name' => 'ﾑｰｳﾞ', 'keyword' => 'ムーヴ', 'match' => ['ムーヴ', 'ﾑｰｳﾞ', 'キャンバス', 'MOVE']],
        ['name' => 'ｱﾙﾄ', 'keyword' => 'アルト', 'match' => ['アルト', 'ｱﾙﾄ', 'ALTO', 'ラパン']],
        ['name' => 'ﾐﾗ/ｲｰｽ', 'keyword' => 'ミラ', 'match' => ['ミライース', 'ミラ', 'ﾐﾗ', 'MIRA']],
        ['name' => 'C-HR', 'keyword' => 'C-HR', 'match' => ['C-HR', 'CHR']],
        ['name' => 'ﾌﾟﾘｳｽ', 'keyword' => 'プリウス', 'match' => ['プリウス', 'ﾌﾟﾘｳｽ', 'PRIUS']],
        ['name' => 'ｱｸｱ', 'keyword' => 'アクア', 'match' => ['アクア', 'ｱｸｱ', 'AQUA']],
        ['name' => 'ﾉｰﾄ', 'keyword' => 'ノート', 'match' => ['ノート', 'ﾉｰﾄ', 'NOTE']],
        ['name' => 'ﾌｨｯﾄ', 'keyword' => 'フィット', 'match' => ['フィット', 'ﾌｨｯﾄ', 'FIT']],
        ['name' => '輸入車/欧州車', 'keyword' => '輸入車', 'match' => ['ベンツ', 'BMW', 'フォルクスワーゲン', 'アウディ', 'キャデラック', 'MINI', 'ボルボ', 'Bクラス', 'B180', 'CTS']]
    ];

    $activeBubbles = [];

    // --- カード①: 実在するボディタイプ ---
    $typeButtons = [];
    foreach ($bodyTypeDefs as $bDef) {
        $count = 0;
        foreach ($cars as $car) {
            if ($bDef['check']($car)) {
                $count++;
            }
        }
        if ($count > 0) {
            $btnLabel = "{$bDef['name']} ({$count}台)";
            $postbackData = "action={$bDef['action']}" . (!empty($bDef['param']) ? "&{$bDef['param']}" : "");
            $typeButtons[] = [
                'type' => 'button',
                'style' => 'secondary',
                'height' => 'sm',
                'action' => [
                    'type' => 'postback',
                    'label' => $btnLabel,
                    'data' => $postbackData
                ]
            ];
        }
    }

    if (!empty($typeButtons)) {
        $activeBubbles[] = [
            'type' => 'bubble',
            'size' => 'kilo',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '14px',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'ﾎﾞﾃﾞｨﾀｲﾌﾟで探す',
                        'weight' => 'bold',
                        'size' => 'md',
                        'color' => '#1e293b'
                    ],
                    [
                        'type' => 'text',
                        'text' => '在庫に実在するタイプから選べます',
                        'size' => 'xs',
                        'color' => '#64748b',
                        'margin' => 'xs'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'sm'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'md',
                        'spacing' => 'sm',
                        'contents' => $typeButtons
                    ]
                ]
            ]
        ];
    }

    // --- カード②: 実在する人気車種モデル ---
    $modelButtons = [];
    foreach ($modelDefs as $mDef) {
        $count = 0;
        foreach ($cars as $car) {
            $rawHaystack = ($car['title'] ?? '') . ' ' . ($car['displacement'] ?? '');
            // 「ミラー」「プレミアム」など部分一致誤爆を防ぐ
            $haystack = str_replace(['ミラー', 'ミドル', 'プレミアム', 'ミラクル'], '', $rawHaystack);

            foreach ($mDef['match'] as $kw) {
                if (stripos($haystack, $kw) !== false) {
                    $count++;
                    break;
                }
            }
        }
        if ($count > 0) {
            $btnLabel = "{$mDef['name']} ({$count}台)";
            $postbackData = "action=search_type&keyword=" . urlencode($mDef['keyword']);
            $modelButtons[] = [
                'type' => 'button',
                'style' => 'secondary',
                'height' => 'sm',
                'action' => [
                    'type' => 'postback',
                    'label' => $btnLabel,
                    'data' => $postbackData
                ]
            ];
        }
    }

    if (!empty($modelButtons)) {
        $modelChunks = array_chunk($modelButtons, 4);
        foreach ($modelChunks as $mIdx => $chunkBtns) {
            $cardTitle = '人気車種で探す' . (count($modelChunks) > 1 ? " (" . ($mIdx + 1) . ")" : "");
            $activeBubbles[] = [
                'type' => 'bubble',
                'size' => 'kilo',
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'paddingAll' => '14px',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => $cardTitle,
                            'weight' => 'bold',
                            'size' => 'md',
                            'color' => '#1e293b'
                        ],
                        [
                            'type' => 'text',
                            'text' => '在庫に実在するモデルから選べます',
                            'size' => 'xs',
                            'color' => '#64748b',
                            'margin' => 'xs'
                        ],
                        [
                            'type' => 'separator',
                            'margin' => 'sm'
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'spacing' => 'sm',
                            'contents' => $chunkBtns
                        ]
                    ]
                ]
            ];
        }
    }

    if (empty($activeBubbles)) {
        return [
            [
                'type' => 'text',
                'text' => "現在、展示中の在庫車両を準備中です。",
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }

    return [
        [
            'type' => 'flex',
            'altText' => '車種・ボディタイプから探す',
            'contents' => [
                'type' => 'carousel',
                'contents' => array_slice($activeBubbles, 0, 10)
            ],
            'quickReply' => getQuickReplyItems()
        ]
    ];
}

/**
 * 装備・仕様選択メニュー送信
 */
function sendEquipmentMenuMessage(PDO $db, string $replyToken) {
    $messages = generateEquipmentMenuMessages($db);
    sendReplyMessage($replyToken, $messages);
}

/**
 * 装備・仕様選択メニュー（サイレントボタン式Flex カルーセル）生成
 * 現在の有効在庫（carsテーブル）からチェックがある装備だけを自動集計し、
 * 「該当台数（例: (10台)）」バッジ付きでカルーセル化（絵文字なしでスッキリ表示）
 */
function generateEquipmentMenuMessages(PDO $db): array {
    // 1. 有効在庫の全データを取得
    $stmt = $db->query("SELECT id, title, equipments, drive_type, repair_history, distance, distance_num, shaken FROM cars WHERE is_active = 1");
    $cars = $stmt->fetchAll();
    $totalStock = count($cars);

    if (empty($cars)) {
        return [
            [
                'type' => 'text',
                'text' => "現在、展示中の在庫車両を準備中です。\n最新の入庫状況はお気軽にお問い合わせください！",
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }

    // 2. 装備マスター定義 (絵文字なし・短縮テキスト)
    $categoryDefs = [
        'navi_camera' => [
            'title' => 'ﾅﾋﾞ･ｶﾒﾗ･快適装備',
            'items' => [
                ['name' => 'ｶｰﾅﾋﾞ/SDﾅﾋﾞ', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('ナビ'), 'match' => ['ナビ', 'メモリーナビ', 'ＳＤナビ', 'ディスプレイオーディオ']],
                ['name' => '地ﾃﾞｼﾞTV', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('TV'), 'match' => ['地デジ', 'フルセグ', 'ワンセグ', 'ＴＶ', 'TV', 'テレビ']],
                ['name' => 'ﾊﾞｯｸｶﾒﾗ', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('バックカメラ'), 'match' => ['バックカメラ', 'アラウンドビュー', '全方位カメラ', 'カメラ']],
                ['name' => 'Bluetooth', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('Bluetooth'), 'match' => ['Bluetooth', 'Ｂｌｕｅｔｏｏｔｈ', 'ブルートゥース', 'カープレイ', 'carplay']],
                ['name' => 'ETC車載器', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('ETC'), 'match' => ['ETC', 'ＥＴＣ', 'ETC2.0']],
                ['name' => 'ﾄﾞﾗﾚｺ', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('ドラレコ'), 'match' => ['ドラレコ', 'ドライブレコーダー']],
            ]
        ],
        'comfort_exterior' => [
            'title' => 'ﾄﾞｱ･ｼｰﾄ･外装',
            'items' => [
                ['name' => 'ﾊﾟﾜｰｽﾗｲﾄﾞ', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('スライド'), 'match' => ['スライド', '両側電動', 'パワースライド']],
                ['name' => 'ｽﾏｰﾄｷｰ', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('スマートキー'), 'match' => ['スマートキー', 'インテリジェントキー', 'プッシュスタート', 'キーレス']],
                ['name' => 'ｼｰﾄﾋｰﾀｰ', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('シートヒーター'), 'match' => ['シートヒーター', '前席ヒーター']],
                ['name' => 'LEDﾗｲﾄ', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('LED'), 'match' => ['LED', 'ＬＥＤ', 'HID', 'ＨＩＤ', 'オートライト']],
                ['name' => 'ｱﾙﾐﾎｲｰﾙ', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('アルミ'), 'match' => ['アルミ', 'アルミホイール', '１５インチアルミ', '１４インチアルミ']],
                ['name' => 'ﾚｻﾞｰｼｰﾄ', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('レザー'), 'match' => ['本革', 'レザー', 'ハーフレザー', '革調']],
            ]
        ],
        'safety_drive' => [
            'title' => '安全･駆動･状態',
            'items' => [
                ['name' => '自動ﾌﾞﾚｰｷ', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('軽減'), 'match' => ['軽減', '安全', 'ブレーキ', 'センシング', 'スマートアシスト', 'セーフティ', 'プロパイロット']],
                ['name' => '4WD/四駆', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('4WD'), 'match' => ['4WD', '４ＷＤ', '四駆', '4wd']],
                ['name' => '修復歴なし', 'action' => 'search_repair_none', 'param' => '', 'match' => ['_repair_none_']],
                ['name' => '未使用･低走行', 'action' => 'search_low_mileage', 'param' => '', 'match' => ['_low_mileage_']],
                ['name' => 'ﾀｰﾎﾞ車', 'action' => 'search_type', 'param' => 'keyword=' . urlencode('ターボ'), 'match' => ['ターボ', 'TB', 'turbo']],
            ]
        ]
    ];

    // 3. 各アイテムの該当台数を集計
    $activeBubbles = [];

    foreach ($categoryDefs as $catKey => $cat) {
        $buttons = [];

        foreach ($cat['items'] as $item) {
            $count = 0;

            foreach ($cars as $car) {
                $isMatch = false;
                $haystack = ($car['title'] ?? '') . ' ' . ($car['equipments'] ?? '') . ' ' . ($car['drive_type'] ?? '');

                if (in_array('_repair_none_', $item['match'])) {
                    if (empty($car['repair_history']) || $car['repair_history'] === 'なし' || $car['repair_history'] === '-') {
                        $isMatch = true;
                    }
                } elseif (in_array('_low_mileage_', $item['match'])) {
                    if (str_contains($car['title'], '未使用') || (!str_contains($car['distance'], '万km') && str_contains($car['distance'], 'km')) || (!empty($car['distance_num']) && $car['distance_num'] <= 0.5)) {
                        $isMatch = true;
                    }
                } else {
                    foreach ($item['match'] as $kw) {
                        if (stripos($haystack, $kw) !== false) {
                            $isMatch = true;
                            break;
                        }
                    }
                }

                if ($isMatch) {
                    $count++;
                }
            }

            // 1台以上ある場合のみボタンを生成！
            if ($count > 0) {
                $btnLabel = "{$item['name']} ({$count}台)";
                $postbackData = "action={$item['action']}" . (!empty($item['param']) ? "&{$item['param']}" : "");

                $buttons[] = [
                    'type' => 'button',
                    'style' => 'secondary',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => $btnLabel,
                        'data' => $postbackData
                    ]
                ];
            }
        }

        // ボタンが1つ以上あるカテゴリのみバブルカードとして追加
        if (!empty($buttons)) {
            // 1バブルあたり最大4ボタンずつ分割
            $btnChunks = array_chunk($buttons, 4);
            foreach ($btnChunks as $cIdx => $cButtons) {
                $cardTitle = $cat['title'] . (count($btnChunks) > 1 ? " (" . ($cIdx + 1) . ")" : "");
                $activeBubbles[] = [
                    'type' => 'bubble',
                    'size' => 'kilo',
                    'body' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'paddingAll' => '14px',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => $cardTitle,
                                'weight' => 'bold',
                                'size' => 'md',
                                'color' => '#1e293b'
                            ],
                            [
                                'type' => 'text',
                                'text' => '在庫に実在する装備から選べます',
                                'size' => 'xs',
                                'color' => '#64748b',
                                'margin' => 'xs'
                            ],
                            [
                                'type' => 'separator',
                                'margin' => 'sm'
                            ],
                            [
                                'type' => 'box',
                                'layout' => 'vertical',
                                'margin' => 'md',
                                'spacing' => 'sm',
                                'contents' => $cButtons
                            ]
                        ]
                    ]
                ];
            }
        }
    }

    if (empty($activeBubbles)) {
        return [
            [
                'type' => 'text',
                'text' => "現在、該当する装備条件の在庫を更新中です。",
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }

    return [
        [
            'type' => 'flex',
            'altText' => '基本仕様・実在装備から探す',
            'contents' => [
                'type' => 'carousel',
                'contents' => array_slice($activeBubbles, 0, 10) // LINE上限最大10枚
            ],
            'quickReply' => getQuickReplyItems()
        ]
    ];
}

/**
 * ユーザーへお知らせリッチメニューを表示（サイレント切り替え: タイムラインを流さないためメッセージ送信なし）
 */
function handleShowNoticeMenu(string $replyToken, string $userId): void {
    $noticeMenu = getActiveNoticeRichMenu();
    if ($noticeMenu && !empty($noticeMenu['line_menu_id'])) {
        // ユーザーに個別紐付け (トーク画面下部のリッチメニューをお知らせメニューにサイレント切り替え)
        $linkRes = lineLinkUserRichMenu($userId, $noticeMenu['line_menu_id']);
        writeDebugLog("お知らせリッチメニュー紐付け実行(サイレント)", [
            'userId' => $userId,
            'richMenuId' => $noticeMenu['line_menu_id'],
            'res' => $linkRes
        ]);
    } else {
        writeDebugLog("お知らせリッチメニューなし(サイレント)");
    }
}

/**
 * ユーザーのお知らせリッチメニューを解除して元のメニュー（専用メニューまたは全体デフォルトメニュー）に戻す（サイレント切り替え: タイムラインを流さないためメッセージ送信なし）
 */
function handleCloseNoticeMenu(?PDO $db, string $replyToken, string $userId): void {
    if (empty($userId)) return;

    if (!$db) {
        $db = getDB();
    }

    // ユーザーに有効な専用リッチメニューが設定されているか確認
    $customMenuId = getUserCustomRichMenuId($db, $userId);

    // 【重要防護】もし customMenuId がお知らせメニュー（is_notice=1等）だった場合、通常メニュー復帰時にそれを再リンクしない
    if (!empty($customMenuId) && isNoticeMenu($db, $customMenuId)) {
        writeDebugLog("handleCloseNoticeMenu: 取得メニューがお知らせメニューのため専用リンクをスキップし全体通常メニューへ復帰", [
            'userId' => $userId,
            'menuId' => $customMenuId
        ]);
        $customMenuId = null;
    }

    if (!empty($customMenuId)) {
        // 専用リッチメニューを設定されているお客様なら、通常専用リッチメニューを再リンクして復帰！
        $linkRes = lineLinkUserRichMenu($userId, $customMenuId);
        writeDebugLog("お知らせ終了: 専用リッチメニュー復帰実行(サイレント)", [
            'userId' => $userId,
            'customMenuId' => $customMenuId,
            'res' => $linkRes
        ]);

        // LINE APIでエラーが発生した場合（例: LINE上でメニューが削除されていた場合など）は全体共通へフォールバック
        if (empty($linkRes['success'])) {
            writeDebugLog("専用メニュー再リンク失敗のため全体共通リッチメニューにフォールバック解除", [
                'userId' => $userId,
                'customMenuId' => $customMenuId,
                'error' => $linkRes['error'] ?? ''
            ]);
            lineUnlinkUserRichMenu($userId);
        }
    } else {
        // 通常ユーザーは個別紐付けを解除（LINE公式アカウント全体のデフォルト通常リッチメニューに自動復帰）
        $unlinkRes = lineUnlinkUserRichMenu($userId);
        writeDebugLog("お知らせ終了: 全体デフォルトメニュー復帰実行(unlink)", [
            'userId' => $userId,
            'res' => $unlinkRes
        ]);
    }
}

/**
 * クイックリプライボタン一覧（LINE Messaging API 完全準拠: postbackのみ）
 * シニア向けお役立ち情報・相談・カレンダー予約のクイックリプライを返却
 */
function getQuickReplyItems(): array {
    if (function_exists('getSeniorKnowledgeQuickReplyItems')) {
        return getSeniorKnowledgeQuickReplyItems();
    }

    return [
        'items' => [
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '💡 お役立ち情報',
                    'data' => 'action=show_knowledge_menu'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '💻 受講生マイカルテ',
                    'data' => 'action=open_mycar'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '💬 教室に質問・相談',
                    'data' => 'action=ask_class&topic=お役立ち情報'
                ]
            ]
        ]
    ];
}

/**
 * LINE Messaging API 返信送信
 */
function sendReplyMessage(string $replyToken, array $messages, string $userId = '') {
    if (empty($messages)) {
        return;
    }
    $token = getLineAccessToken();
    if (empty($token) || $token === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        writeDebugLog("返信スキップ: LINEアクセストークンが未設定です");
        return;
    }

    // 【最重要】受講生のトーク画面最下部にクイックリプライボタンを常時表示し続けるため、
    // 最後のメッセージに quickReply が未設定であれば自動的にシニアお役立ちクイックリプライを付与
    $lastIdx = count($messages) - 1;
    if ($lastIdx >= 0 && !isset($messages[$lastIdx]['quickReply'])) {
        if (function_exists('getSeniorKnowledgeQuickReplyItems')) {
            $messages[$lastIdx]['quickReply'] = getSeniorKnowledgeQuickReplyItems();
        } elseif (function_exists('getQuickReplyItems')) {
            $messages[$lastIdx]['quickReply'] = getQuickReplyItems();
        }
    }

    $url = 'https://api.line.me/v2/bot/message/reply';
    $payload = [
        'replyToken' => $replyToken,
        'messages' => $messages
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("LINE API返信結果", [
        'httpCode' => $httpCode,
        'userId' => $userId,
        'response' => $res,
        'curlError' => $curlErr
    ]);

    // Replyが失敗（400エラー、replyToken失効等）した場合、userIdがあればPush送信で確実にメッセージを届ける
    if ($httpCode !== 200 && !empty($userId) && str_starts_with($userId, 'U')) {
        writeDebugLog("Reply失敗のためPushメッセージ送信で自動フォールバック試行", ['userId' => $userId, 'httpCode' => $httpCode]);
        try {
            sendLinePushMessage($userId, $messages);
        } catch (Throwable $e) {
            writeDebugLog("Pushフォールバック例外: " . $e->getMessage());
        }
    }
}

// =========================================================================
// 会社DXアンケート機能 (DX関心度・関心テーマヒアリング)
// =========================================================================

/**
 * 会社DXアンケート Q1（興味度ヒアリング）Flex Messageを生成
 */
function buildDxSurveyQ1Message(): array {
    return [
        'type' => 'flex',
        'altText' => '【アンケート】会社DXに関する関心度アンケート（所要時間: 約30秒）',
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#1e40af',
                'paddingAll' => '16px',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => '🏢 会社DX アンケート',
                        'color' => '#ffffff',
                        'weight' => 'bold',
                        'size' => 'md'
                    ],
                    [
                        'type' => 'text',
                        'text' => '2問の選択式アンケート（所要時間: 約30秒）',
                        'color' => '#93c5fd',
                        'size' => 'xs',
                        'margin' => 'xs'
                    ]
                ]
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '18px',
                'spacing' => 'md',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'Q1. 業務のDX（デジタル化・効率化）に関心はございますか？',
                        'weight' => 'bold',
                        'size' => 'sm',
                        'color' => '#1e293b',
                        'wrap' => true
                    ],
                    [
                        'type' => 'text',
                        'text' => '現在の状況に一番近いものをタップしてください。',
                        'size' => 'xs',
                        'color' => '#64748b'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'sm'
                    ],
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => '#2563eb',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '🔥 大いにある（すぐにでも取り組みたい）',
                            'data' => 'action=dx_survey_q1&answer=high&label=' . urlencode('大いにある'),
                            'displayText' => '【回答】DXに大いに関心があります'
                        ]
                    ],
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => '#3b82f6',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '💡 少しある（情報収集中・事例を知りたい）',
                            'data' => 'action=dx_survey_q1&answer=medium&label=' . urlencode('少しある'),
                            'displayText' => '【回答】DXに少し関心があります'
                        ]
                    ],
                    [
                        'type' => 'button',
                        'style' => 'secondary',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '☕ 今はまだない（状況を見守り中）',
                            'data' => 'action=dx_survey_q1&answer=none&label=' . urlencode('今はまだない'),
                            'displayText' => '【回答】今のところDXへの関心はありません'
                        ]
                    ]
                ]
            ]
        ]
    ];
}

/**
 * 会社DXアンケート Q2（関心テーマヒアリング）Flex Messageを生成
 */
function buildDxSurveyQ2Message(string $q1Answer, string $q1Label): array {
    return [
        'type' => 'flex',
        'altText' => '【Q2】特にどのような課題・テーマに関心がありますか？',
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#1e40af',
                'paddingAll' => '16px',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => '🎯 関心のあるテーマ・お困りごと',
                        'color' => '#ffffff',
                        'weight' => 'bold',
                        'size' => 'md'
                    ],
                    [
                        'type' => 'text',
                        'text' => "Q1回答: 「{$q1Label}」を承りました！続けてお選びください",
                        'color' => '#93c5fd',
                        'size' => 'xs',
                        'margin' => 'xs'
                    ]
                ]
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '16px',
                'spacing' => 'sm',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'Q2. 特にどのような課題・テーマに関心がありますか？',
                        'weight' => 'bold',
                        'size' => 'sm',
                        'color' => '#1e293b',
                        'wrap' => true
                    ],
                    [
                        'type' => 'text',
                        'text' => '当てはまるものを1つタップしてください。',
                        'size' => 'xs',
                        'color' => '#64748b'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'sm'
                    ],
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => '#2563eb',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '💻 ペーパーレス化・業務効率化',
                            'data' => 'action=dx_survey_q2&q1=' . urlencode($q1Answer) . '&topic=paperless&label=' . urlencode('ペーパーレス化・業務効率化'),
                            'displayText' => '【関心分野】ペーパーレス化・業務効率化に関心があります'
                        ]
                    ],
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => '#4f46e5',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '🤖 ChatGPT・生成AIの社内活用',
                            'data' => 'action=dx_survey_q2&q1=' . urlencode($q1Answer) . '&topic=genai&label=' . urlencode('生成AI社内活用'),
                            'displayText' => '【関心分野】生成AI（ChatGPT等）の社内活用に関心があります'
                        ]
                    ],
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => '#0284c7',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '📱 LINE公式アカウント活用・集客自動化',
                            'data' => 'action=dx_survey_q2&q1=' . urlencode($q1Answer) . '&topic=line_crm&label=' . urlencode('LINE活用・集客自動化'),
                            'displayText' => '【関心分野】LINE公式アカウント活用・集客自動化に関心があります'
                        ]
                    ],
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => '#0891b2',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '📊 クラウド導入・社内データ一元管理',
                            'data' => 'action=dx_survey_q2&q1=' . urlencode($q1Answer) . '&topic=cloud_data&label=' . urlencode('クラウド・データ一元管理'),
                            'displayText' => '【関心分野】クラウド導入・社内データ一元管理に関心があります'
                        ]
                    ],
                    [
                        'type' => 'button',
                        'style' => 'secondary',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '🤝 まずは他社事例を聞きたい（無料相談）',
                            'data' => 'action=dx_survey_q2&q1=' . urlencode($q1Answer) . '&topic=consultation&label=' . urlencode('他社事例・無料相談希望'),
                            'displayText' => '【関心分野】まずは他社事例を聞いてみたい（無料相談希望）'
                        ]
                    ],
                    [
                        'type' => 'button',
                        'style' => 'secondary',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '✏️ その他（トークで直接相談）',
                            'data' => 'action=dx_survey_q2&q1=' . urlencode($q1Answer) . '&topic=other&label=' . urlencode('その他・直接相談'),
                            'displayText' => '【関心分野】その他について相談したいです'
                        ]
                    ]
                ]
            ]
        ]
    ];
}

/**
 * 会社DXアンケート Q1 回答処理
 */
function handleDxSurveyQ1(PDO $db, string $replyToken, array $params, string $userId) {
    $answer = $params['answer'] ?? '';
    $label = $params['label'] ?? '';

    $userProfile = !empty($userId) ? getLineUserProfile($userId) : null;
    $userName = $userProfile['displayName'] ?? 'お客様';

    // 「今はまだない」の場合
    if ($answer === 'none') {
        $nowStr = date('Y-m-d H:i');
        $memoLine = "【DXアンケート】興味度: 今はまだない ({$nowStr})";
        recordCustomerDxSurveyResponse($db, $userId, $memoLine, 'DX関心: 今はまだない');

        $replyText = "アンケートにご回答いただき、誠にありがとうございました！✨\n\n今後、貴社の業務にお役立ていただけるDX導入事例や最新ニュースがございましたら、改めてお届けいたします。\n引き続きよろしくお願いいたします😊";
        sendReplyMessage($replyToken, [['type' => 'text', 'text' => $replyText]], $userId);

        notifyStaffOfDxSurvey($db, $userName, $userId, '今はまだない', 'なし');
        return;
    }

    // 「大いにある」「少しある」の場合は続けてQ2を提示
    $q2Message = buildDxSurveyQ2Message($answer, $label);
    sendReplyMessage($replyToken, [$q2Message], $userId);
}

/**
 * 会社DXアンケート Q2 回答処理
 */
function handleDxSurveyQ2(PDO $db, string $replyToken, array $params, string $userId) {
    $q1Code = $params['q1'] ?? '';
    $topic = $params['topic'] ?? '';
    $label = $params['label'] ?? '';

    $q1Map = [
        'high' => '大いにある',
        'medium' => '少しある',
        'none' => '今はまだない'
    ];
    $q1Label = $q1Map[$q1Code] ?? $q1Code;

    $userProfile = !empty($userId) ? getLineUserProfile($userId) : null;
    $userName = $userProfile['displayName'] ?? 'お客様';

    // DB記録
    $nowStr = date('Y-m-d H:i');
    $memoLine = "【DXアンケート回答】興味度: {$q1Label} / 関心テーマ: {$label} ({$nowStr})";
    recordCustomerDxSurveyResponse($db, $userId, $memoLine, "DX関心: {$q1Label} ({$label})");

    // ユーザーへの完了返信
    $replyText = "アンケートへのご協力、誠にありがとうございました！✨\n\nご回答いただいたテーマ【{$label}】につきまして、貴社の業界や業務課題に合わせた具体的な導入事例や活用ヒントをご用意可能です。\n\n「もっと詳しく事例を聞きたい」「自社で導入できるか相談したい」などがございましたら、このLINEトーク画面にお気軽にメッセージをお送りください😊";
    sendReplyMessage($replyToken, [['type' => 'text', 'text' => $replyText]], $userId);

    // 管理者への即時プッシュ通知
    notifyStaffOfDxSurvey($db, $userName, $userId, $q1Label, $label);
}

/**
 * 顧客カルテへのDXアンケート回答記録
 */
function recordCustomerDxSurveyResponse(PDO $db, string $userId, string $memoLine, string $preview) {
    try {
        $nowJst = date('Y-m-d H:i:s');
        $stmt = $db->prepare("SELECT id, staff_memo FROM customer_cars WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $existingMemo = trim((string)($row['staff_memo'] ?? ''));
            $newMemo = $existingMemo ? ($existingMemo . "\n" . $memoLine) : $memoLine;
            $update = $db->prepare("
                UPDATE customer_cars SET
                    staff_memo = :memo,
                    last_interaction_at = :now_jst,
                    last_interaction_type = 'dx_survey',
                    last_interaction_preview = :preview,
                    updated_at = :now_jst2
                WHERE id = :id
            ");
            $update->execute([
                ':memo' => $newMemo,
                ':now_jst' => $nowJst,
                ':preview' => $preview,
                ':now_jst2' => $nowJst,
                ':id' => $row['id']
            ]);
        }
    } catch (Throwable $e) {
        writeDebugLog("recordCustomerDxSurveyResponse エラー", ['error' => $e->getMessage()]);
    }
}

/**
 * DXアンケート回答時のスタッフ通知 (LINE Push + Discord)
 */
function notifyStaffOfDxSurvey(PDO $db, string $userName, string $userId, string $q1Label, string $topicLabel) {
    $msgText = "📋 【会社DX アンケート回答】\n\n👤 {$userName} 様よりDXアンケートの回答が届きました！\n\n■ DXへの関心度: {$q1Label}\n■ 関心テーマ: {$topicLabel}\n\n※管理画面の顧客カルテより詳細を確認し、チャット等で個別フォローいただけます。";

    // 1. 管理者LINEアカウントへPush通知
    try {
        sendAdminLineBroadcast([['type' => 'text', 'text' => $msgText]], $db);
    } catch (Throwable $e) {
        writeDebugLog("管理者LINE通知エラー(DXアンケート)", ['error' => $e->getMessage()]);
    }

    // 2. Discord Webhook通知
    try {
        if (defined('DISCORD_WEBHOOK_URL') && !empty(DISCORD_WEBHOOK_URL) && DISCORD_WEBHOOK_URL !== 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
            $payload = [
                'username' => '会社DX アンケート通知',
                'content' => "📢 **【会社DX】アンケート回答を受信しました！**",
                'embeds' => [
                    [
                        'title' => '📋 DX関心度アンケート 回答速報',
                        'color' => 0x2563eb,
                        'fields' => [
                            ['name' => '👤 お客様名', 'value' => "**{$userName}** 様", 'inline' => true],
                            ['name' => '🔥 DXへの関心度', 'value' => "**{$q1Label}**", 'inline' => true],
                            ['name' => '🎯 関心テーマ', 'value' => "**{$topicLabel}**", 'inline' => false]
                        ],
                        'timestamp' => date('c')
                    ]
                ]
            ];
            $ch = curl_init(DISCORD_WEBHOOK_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Throwable $e) {
        writeDebugLog("Discord通知エラー(DXアンケート)", ['error' => $e->getMessage()]);
    }
}
