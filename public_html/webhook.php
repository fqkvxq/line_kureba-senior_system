<?php
/**
 * LINE Messaging API Webhook ハンドラー
 * LINE公式アカウントから�EメチE��ージを受信し、データベ�Eスの車両惁E��をFlex Messageで返信します、E
 */

require_once __DIR__ . '/config.php';

// --- ブラウザ等から�E直接GETアクセスの場合�E診断画面を表示 ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    
    // DB状態確誁E
    $dbStatus = 'エラー';
    $carCount = 0;
    $dbPath = DB_PATH;
    try {
        $db = getDbConnection();
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM cars WHERE is_active = 1");
        $carCount = (int)$stmt->fetch()['cnt'];
        $dbStatus = "正常稼働中 (有効在庫: {$carCount}台)";
    } catch (Exception $e) {
        $dbStatus = "接続失敁E " . htmlspecialchars($e->getMessage());
    }

    $tokenConfigured = (LINE_CHANNEL_ACCESS_TOKEN !== 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') ? '<span style="color:green;">設定済み</span>' : '<span style="color:red;">未設宁E(config.phpに貼り付けてください)</span>';
    $secretConfigured = (LINE_CHANNEL_SECRET !== 'YOUR_CHANNEL_SECRET_HERE') ? '<span style="color:green;">設定済み</span>' : '<span style="color:red;">未設宁E/span>';
    
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="ja">
    <head><meta charset="utf-8"><title>LINE Car Search Webhook 診断</title>
    <style>body{font-family:sans-serif;padding:30px;line-height:1.6;background:#f8fafc;color:#1e293b}
    .card{background:#fff;padding:24px;border-radius:12px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);max-width:600px;margin:0 auto}
    h2{margin-top:0;color:#06C755}table{width:100%;border-collapse:collapse;margin:16px 0}
    td,th{padding:10px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:14px}
    .log-box{background:#0f172a;color:#a5f3fc;padding:12px;border-radius:8px;font-family:monospace;font-size:12px;max-height:260px;overflow-y:auto;white-space:pre-wrap}
    </style></head>
    <body>
    <div class="card">
        <h2>🚗 LINE Webhook 稼働スチE�Eタス</h2>
        <table>
            <tr><th>頁E��</th><th>状慁E/th></tr>
            <tr><td>Webhook エンド�EインチE/td><td>正常応答中 (200 OK)</td></tr>
            <tr><td>チャネルアクセスト�Eクン</td><td>{$tokenConfigured}</td></tr>
            <tr><td>チャネルシークレチE��</td><td>{$secretConfigured}</td></tr>
            <tr><td>DBパス</td><td><code>{$dbPath}</code></td></tr>
            <tr><td>チE�Eタベ�Eス状慁E/td><td><strong>{$dbStatus}</strong></td></tr>
        </table>
        <h3>📋 最近�Eログ (最新35件)</h3>
        <div class="log-box">
HTML;
    $logFile = __DIR__ . '/webhook_debug.log';
    if (file_exists($logFile)) {
        $lines = array_slice(file($logFile), -35);
        echo htmlspecialchars(implode('', $lines));
    } else {
        echo "ログはまだありません、EINEでメチE��ージを送信すると記録されます、E;
    }
    echo <<<HTML
        </div>
    </div>
    </body></html>
HTML;
    exit;
}

// --- Webhookリクエスト受信時�Eエントリポイント実衁E---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'webhook.php' || basename($_SERVER['PHP_SELF'] ?? '') === 'webhook.php')) {
    // 生�Eリクエスト�EチE��を取征E
    $rawInput = file_get_contents('php://input');
    writeDebugLog("Webhook受信", ['bytes' => strlen($rawInput)]);

    // 署名検証 (Channel Secretが設定されてぁE��場吁E
    if (LINE_CHANNEL_SECRET !== 'YOUR_CHANNEL_SECRET_HERE' && !empty($_SERVER['HTTP_X_LINE_SIGNATURE'])) {
        $signature = $_SERVER['HTTP_X_LINE_SIGNATURE'];
        $hash = base64_encode(hash_hmac('sha256', $rawInput, LINE_CHANNEL_SECRET, true));
        if (!hash_equals($hash, $signature)) {
            writeDebugLog("署名検証エラー (Signature mismatch)");
            http_response_code(403);
            echo 'Invalid signature';
            exit;
        }
    }

    $data = json_decode($rawInput, true);
    if (empty($data['events'])) {
        writeDebugLog("イベントなぁE(検証Pingなど)");
        http_response_code(200);
        echo 'OK (No events)';
        exit;
    }

    try {
        $db = getDbConnection();
    } catch (Exception $e) {
        writeDebugLog("DB接続例夁E " . $e->getMessage());
        http_response_code(500);
        exit;
    }

    foreach ($data['events'] as $event) {
        $replyToken = $event['replyToken'] ?? null;
        if (!$replyToken) continue;

        $userId = $event['source']['userId'] ?? '';
        $type = $event['type'];
        writeDebugLog("イベント�E琁E��姁E, ['type' => $type, 'userId' => $userId]);

        if ($type === 'message' && $event['message']['type'] === 'text') {
            $userText = trim($event['message']['text']);
            writeDebugLog("チE��スト受信", ['text' => $userText, 'userId' => $userId]);
            handleTextMessage($db, $replyToken, $userText, $userId);
        } elseif ($type === 'postback') {
            $postbackData = $event['postback']['data'] ?? '';
            writeDebugLog("ポストバチE��受信", ['data' => $postbackData, 'userId' => $userId]);
            handlePostback($db, $replyToken, $postbackData, $userId);
        } elseif ($type === 'follow') {
            writeDebugLog("友だち追加イベンチE, ['userId' => $userId]);
            handleFollow($replyToken);
        }
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

// --- イベント�E琁E��数群 ---

/**
 * チE��ストメチE��ージの処琁E
 */
function handleTextMessage(PDO $db, string $replyToken, string $text, string $userId = '') {
    // 0. LIFFからのご来店�Eご相諁E��付メチE��ージを受信した場合！Epi.phpで処琁E��みのため二重返信を防止�E�E
    if (str_contains($text, '【ご来店�Eご相諁E�E受付、E) || str_contains($text, '【修琁E�E点検�Eカスタム相諁E��E)) {
        // すでにPush送信・Discord通知済みのため、追加返信は行わず正常終亁E
        return;
    }

    // 1. LIFFマイカー画面からの予紁E��定メチE��ージを受信した場合（侁E 、E2ヶ月定期点検�E来店予紁E��など�E�E
    if (preg_match('/、E.*?)の来店予紁E��Eu', $text, $m)) {
        $bookingType = $m[1]; // オイル交揁E 12ヶ月定期点椁E 車椁Eなど
        
        $carModel = '愛軁E;
        if (preg_match('/愛軁E\s*(.+)/u', $text, $carM)) {
            $carModel = trim($carM[1]);
        }
        $prefTime = '希望日時指定あめE;
        if (preg_match('/希望日晁E\s*(.+)/u', $text, $prefM)) {
            $prefTime = trim($prefM[1]);
        }

        handleSubmitMaintenanceBooking($replyToken, $bookingType, $carModel, $prefTime, $userId);
        return;
    }

    // 2. オイル交換�E定期点検�E車検�EメンチE��ンス関連のキーワード判宁E(在庫検索の誤爁E��止)
    if (preg_match('/^(オイル|オイル交換|車検|点検|12ヶ朁E12ヶ月点検|法定点検|メンチE��ンス)$/u', trim($text))) {
        // 顧客の登録愛車を取征E
        $carModel = '愛軁E;
        $oilDate = '近日中';
        $periodicDate = '近日中';
        $inspDate = '未宁E;
        if (!empty($userId)) {
            $stmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid ORDER BY updated_at DESC LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $cust = $stmt->fetch();
            if ($cust) {
                if (!empty($cust['car_model'])) $carModel = $cust['car_model'];
                if (!empty($cust['oil_next_date'])) $oilDate = $cust['oil_next_date'];
                if (!empty($cust['periodic_insp_next_date'])) $periodicDate = $cust['periodic_insp_next_date'];
                if (!empty($cust['inspection_next_date'])) $inspDate = $cust['inspection_next_date'];
            }
        }

        if (preg_match('/(点検|12ヶ朁E法宁E/u', $text)) {
            $type = 'periodic';
            $targetDate = $periodicDate;
        } elseif (preg_match('/(オイル)/u', $text)) {
            $type = 'oil';
            $targetDate = $oilDate;
        } else {
            $type = 'inspection';
            $targetDate = $inspDate;
        }
        
        sendMaintenanceBookingConfirmMessage($replyToken, $type, $carModel, $targetDate, $userId);
        return;
    }

    // 2-2. カーライフ豁E��識�Eお役立ちガイド�E判宁E
    if (preg_match('/(豁E��譁Eお役立ち|ガイド|選び方|中古車�E選び方|知譁Eコラム|マガジン|ノウハウ)/u', $text)) {
        sendKnowledgeMenuMessage($replyToken);
        return;
    }

    // 3. 特殊キーワード�E判宁E
    if (in_array($text, ['在庫一覧', '車を探ぁE, 'メニュー', '在庫', '軁E, '全台'])) {
        searchCarsAndReply($db, $replyToken, [], '現在の在庫車両一覧', $userId);
        return;
    }

    // 3. 価格帯キーワード�E判宁E(侁E 50丁E��丁E 100丁E�E以丁E 50丁E�E)
    if (preg_match('/([0-9\.]+)\s*(丁E丁E�E)?\s*(以下|未満)?/u', $text, $matches)) {
        $price = (float)$matches[1];
        if ($price > 0 && $price < 2000) {
            searchCarsAndReply($db, $replyToken, ['max_price' => $price], "支払総顁E{$price}丁E�E以下�E車両", $userId);
            return;
        }
    }

    // 4. フリーワード検索 (車名など)
    searchCarsAndReply($db, $replyToken, ['keyword' => $text], "「{$text}」�E検索結果", $userId);
}

/**
 * ポストバチE��イベント�E処琁E
 */
function handlePostback(PDO $db, string $replyToken, string $dataStr, string $userId = '') {
    parse_str($dataStr, $params);
    $action = $params['action'] ?? '';

    switch ($action) {
        // --- 1. 車両問い合わせ確認スチE��チE(誤タチE�E防止) ---
        case 'ask_inquiry':
            $carId = $params['id'] ?? '';
            sendInquiryConfirmMessage($db, $replyToken, $carId, $userId);
            break;

        // --- 2. 正式問ぁE��わせ送信実衁E(真剣度髁E ---
        case 'submit_inquiry':
            $carId = $params['id'] ?? '';
            $inquiryType = $params['type'] ?? '在庫確誁E;
            handleSubmitInquiry($db, $replyToken, $carId, $inquiryType, $userId);
            break;

        // --- 3. メンチE��ンス(オイル交揁E車椁E予紁E��認スチE��チE(誤タチE�E防止) ---
        case 'ask_maintenance':
            $maintType = $params['type'] ?? 'oil';
            $carModel = $params['car'] ?? '愛軁E;
            $date = $params['date'] ?? '近日中';
            sendMaintenanceBookingConfirmMessage($replyToken, $maintType, $carModel, $date, $userId);
            break;

        // --- 4. メンチE��ンス(オイル交揁E車椁E予紁E��定送信 (真剣度髁E ---
        case 'submit_maintenance':
            $maintType = $params['type'] ?? 'oil';
            $carModel = $params['car'] ?? '愛軁E;
            $prefTime = $params['pref'] ?? '近日中の希望';
            handleSubmitMaintenanceBooking($replyToken, $maintType, $carModel, $prefTime, $userId);
            break;

        // --- 5. キャンセル ---
        case 'cancel_inquiry':
        case 'cancel_maintenance':
            $messages = [
                [
                    'type' => 'text',
                    'text' => "ご案�Eをキャンセルしました、En気になるお車やメンチE��ンスのご相諁E�Eお気軽に下�EボタンよりどぁE��🚗",
                    'quickReply' => getQuickReplyItems()
                ]
            ];
            sendReplyMessage($replyToken, $messages);
            break;

        // --- 6. サイレント検索: 価格帯メニュー表示 ---
        case 'show_price_menu':
            sendPriceMenuMessage($replyToken);
            break;

        // --- 7. サイレント検索: 車種・ボディタイプメニュー表示 ---
        case 'show_type_menu':
            sendTypeMenuMessage($db, $replyToken);
            break;

        // --- 7-2. サイレント検索: 裁E��・仕様メニュー表示 ---
        case 'show_equipment_menu':
            sendEquipmentMenuMessage($db, $replyToken);
            break;

        // --- 7-3. サイレント検索: 走行距離メニュー表示 ---
        case 'show_distance_menu':
            sendDistanceMenuMessage($db, $replyToken);
            break;

        // --- 7-4. お役立ちガイド�Eカーライフ豁E��識メニュー表示 ---
        case 'show_knowledge_menu':
            sendKnowledgeMenuMessage($replyToken);
            break;

        // --- 7-5. お役立ちガイド�E個別記事詳細表示 ---
        case 'show_knowledge':
            $topic = trim($params['topic'] ?? 'used_car');
            sendKnowledgeDetailMessage($replyToken, $topic);
            break;

        // --- 8. サイレント検索: 価格帯絞り込み実衁E---
        case 'search_price':
            $maxPrice = (float)($params['max_price'] ?? 0);
            $minPrice = (float)($params['min_price'] ?? 0);
            $criteria = [];
            $title = "支払総顁E{$maxPrice}丁E�E以下�E車両";
            if ($maxPrice > 0) $criteria['max_price'] = $maxPrice;
            if ($minPrice > 0) {
                $criteria['min_price'] = $minPrice;
                $title = "支払総顁E{$minPrice}丁E��{$maxPrice}丁E�Eの車両";
            }
            searchCarsAndReply($db, $replyToken, $criteria, $title, $userId);
            break;

        // --- 8-2. サイレント検索: 走行距離絞り込み実衁E---
        case 'search_distance':
            $maxD = isset($params['max_distance']) ? (float)$params['max_distance'] : null;
            $minD = isset($params['min_distance']) ? (float)$params['min_distance'] : null;
            $criteria = [];
            $title = "走行距離で絞り込み";
            if ($maxD !== null) {
                $criteria['max_distance'] = $maxD;
                $title = "走行距離 {$maxD}万km以下�E車両";
            }
            if ($minD !== null) {
                $criteria['min_distance'] = $minD;
                $title = "走行距離 {$minD}万km以上�E車両";
            }
            searchCarsAndReply($db, $replyToken, $criteria, $title, $userId);
            break;

        // --- 9. サイレント検索: 軽自動車専用絞り込み ---
        case 'search_kei':
            searchCarsAndReply($db, $replyToken, ['is_kei' => true], "軽自動車�E一覧", $userId);
            break;

        // --- 9-2. サイレント検索: 裁E��・仕様絞り込み ---
        case 'search_equip':
            $keyword = trim($params['keyword'] ?? '');
            searchCarsAndReply($db, $replyToken, ['equip' => $keyword], "「{$keyword}」裁E��の車両一覧", $userId);
            break;

        // --- 9-3. サイレント検索: 修復歴なぁE---
        case 'search_repair_none':
            searchCarsAndReply($db, $replyToken, ['repair' => 'none'], "修復歴なし（無事故車）�E一覧", $userId);
            break;

        // --- 9-4. サイレント検索: 届�E済未使用軁E/ 低走衁E---
        case 'search_low_mileage':
            searchCarsAndReply($db, $replyToken, ['low_mileage' => true], "届�E済未使用車�E低走行車�E一覧", $userId);
            break;

        // --- 9-5. サイレント検索: 車種・キーワード絞り込み実衁E---
        case 'search_type':
        case 'search_keyword':
            $keyword = trim($params['keyword'] ?? '');
            if ($keyword === '軽' || $keyword === '軽自動軁E) {
                searchCarsAndReply($db, $replyToken, ['is_kei' => true], "軽自動車�E一覧", $userId);
            } else {
                $title = !empty($keyword) ? "「{$keyword}」�E車両一覧" : "最新の在庫車両一覧";
                searchCarsAndReply($db, $replyToken, ['keyword' => $keyword], $title, $userId);
            }
            break;

        // --- 10. サイレント検索: 在庫全台一覧 ---
        case 'search_all':
        default:
            searchCarsAndReply($db, $replyToken, [], '現在の在庫車両一覧', $userId);
            break;
    }
}

/**
 * LIFF Trigger からのPush送信用サイレンチEostback実行関数
 */
function executeSilentPostbackPush(PDO $db, string $userId, string $dataStr): bool {
    parse_str($dataStr, $params);
    $action = $params['action'] ?? '';

    writeDebugLog("LIFF Silent Postback Push実衁E, ['uid' => $userId, 'action' => $action, 'data' => $dataStr]);

    if (!str_starts_with($userId, 'U')) {
        writeDebugLog("Push送信スキチE�E: 有効なLINEユーザーIDではありません ({$userId})");
        return false;
    }

    $messages = [];

    switch ($action) {
        case 'show_price_menu':
            $messages = generatePriceMenuMessages();
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
            $title = "支払総顁E{$maxPrice}丁E�E以下�E車両";
            if ($maxPrice > 0) $criteria['max_price'] = $maxPrice;
            if ($minPrice > 0) {
                $criteria['min_price'] = $minPrice;
                $title = "支払総顁E{$minPrice}丁E��{$maxPrice}丁E�Eの車両";
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
                $title = "走行距離 {$maxD}万km以下�E車両";
            }
            if ($minD !== null) {
                $criteria['min_distance'] = $minD;
                $title = "走行距離 {$minD}万km以上�E車両";
            }
            $messages = generateCarSearchMessages($db, $criteria, $title, $userId);
            break;

        case 'search_kei':
            $messages = generateCarSearchMessages($db, ['is_kei' => true], "軽自動車�E一覧", $userId);
            break;

        case 'search_equip':
            $keyword = trim($params['keyword'] ?? '');
            $messages = generateCarSearchMessages($db, ['equip' => $keyword], "「{$keyword}」裁E��の車両一覧", $userId);
            break;

        case 'search_repair_none':
            $messages = generateCarSearchMessages($db, ['repair' => 'none'], "修復歴なし（無事故車）�E一覧", $userId);
            break;

        case 'search_low_mileage':
            $messages = generateCarSearchMessages($db, ['low_mileage' => true], "届�E済未使用車�E低走行車�E一覧", $userId);
            break;

        case 'search_type':
        case 'search_keyword':
            $keyword = trim($params['keyword'] ?? '');
            if ($keyword === '軽' || $keyword === '軽自動軁E) {
                $messages = generateCarSearchMessages($db, ['is_kei' => true], "軽自動車�E一覧", $userId);
            } else {
                $title = !empty($keyword) ? "「{$keyword}」�E車両一覧" : "最新の在庫車両一覧";
                $messages = generateCarSearchMessages($db, ['keyword' => $keyword], $title, $userId);
            }
            break;

        case 'search_all':
        default:
            $messages = generateCarSearchMessages($db, [], '現在の在庫車両一覧', $userId);
            break;
    }

    if (!empty($messages)) {
        try {
            if (count($messages) > 5) {
                $chunks = array_chunk($messages, 5);
                foreach ($chunks as $chunk) {
                    $res = sendLinePushMessage($userId, $chunk);
                    writeDebugLog("Push刁E��送信結果", ['userId' => $userId, 'success' => $res['success'] ?? false, 'response' => $res['response'] ?? '']);
                }
                return true;
            } else {
                $res = sendLinePushMessage($userId, $messages);
                writeDebugLog("Push送信結果", ['userId' => $userId, 'success' => $res['success'] ?? false, 'response' => $res['response'] ?? '']);
                return !empty($res['success']);
            }
        } catch (Exception $e) {
            writeDebugLog("Silent Postback Push送信例夁E " . $e->getMessage());
            return false;
        }
    }

    return false;
}

/**
 * 友だち追加時�EあいさつメチE��ージ
 */
function handleFollow(string $replyToken) {
    $messages = [
        [
            'type' => 'text',
            'text' => "友だち追加ありがとぁE��ざいます！🚗✨\n\n、E . SHOP_NAME . "】�E最新在庫車両をいつでめEINEから検索ぁE��だけます、En\n気になる車種名を入力するか、下�EボタンをタチE�Eしてみてください�E�E,
            'quickReply' => getQuickReplyItems()
        ]
    ];
    sendReplyMessage($replyToken, $messages);
}

/**
 * 車両検索 & Flex Message返信
 */
function searchCarsAndReply(PDO $db, string $replyToken, array $criteria, string $heading, string $userId = '') {
    $messages = generateCarSearchMessages($db, $criteria, $heading, $userId);
    sendReplyMessage($replyToken, $messages);
}

/**
 * 車両検索メチE��ージ配�Eを生戁E(Reply / Push 共送E
 */
function generateCarSearchMessages(PDO $db, array $criteria, string $heading, string $userId = ''): array {
    try {
        $where = ["is_active = 1"];
        $params = [];

        if (!empty($criteria['keyword'])) {
            $kw = trim($criteria['keyword']);
            if ($kw === 'ミラ' || $kw === 'ミライース') {
                $where[] = "(
                    (title LIKE '%ミライース%' OR title LIKE '%ミラココア%' OR title LIKE '%ミラジーチE' OR title LIKE '%ミラトコチE��%' OR title LIKE 'ダイハツ ミラ%' OR title LIKE '% ミラ %' OR title LIKE 'ミラ %' OR title LIKE '% ミラ')
                    OR (title LIKE '%ミラ%' AND title NOT LIKE '%ミラー%')
                )";
            } elseif ($kw === 'チE��ズ' || $kw === 'ルークス') {
                $where[] = "(title LIKE '%チE��ズ%' OR title LIKE '%ルークス%' OR title LIKE '%DAYZ%' OR title LIKE '%ROOX%')";
            } elseif ($kw === 'N-BOX' || $kw === 'エヌ�EチE��ス') {
                $where[] = "(title LIKE '%N-BOX%' OR title LIKE '%�E��E�Ｂ�E��E�%' OR title LIKE '%NBOX%')";
            } elseif ($kw === 'タンチE) {
                $where[] = "(title LIKE '%タンチE' OR title LIKE '%TANTO%')";
            } elseif ($kw === 'スペ�Eシア') {
                $where[] = "(title LIKE '%スペ�Eシア%' OR title LIKE '%SPACIA%')";
            } elseif ($kw === 'ワゴンR') {
                $where[] = "(title LIKE '%ワゴンR%' OR title LIKE '%ワゴン�E�%' OR title LIKE '%スチE��ングレー%')";
            } elseif ($kw === 'ムーヴ') {
                $where[] = "(title LIKE '%ムーヴ%' OR title LIKE '%キャンバス%' OR title LIKE '%MOVE%')";
            } elseif ($kw === 'アルチE) {
                $where[] = "(title LIKE '%アルチE' OR title LIKE '%ラパン%' OR title LIKE '%ALTO%')";
            } elseif ($kw === 'ハスラー') {
                $where[] = "(title LIKE '%ハスラー%' OR title LIKE '%HUSTLER%')";
            } elseif ($kw === '輸入軁E || $kw === '外軁E) {
                $where[] = "(title LIKE '%ベンチE' OR title LIKE '%BMW%' OR title LIKE '%フォルクスワーゲン%' OR title LIKE '%アウチE��%' OR title LIKE '%キャチE��チE��%' OR title LIKE '%MINI%' OR title LIKE '%ボルチE' OR title LIKE '%Bクラス%' OR title LIKE '%B180%' OR title LIKE '%CTS%')";
            } elseif ($kw === 'コンパクチE) {
                $where[] = "(displacement != '660cc' AND (title LIKE '%コンパクチE' OR title LIKE '%フィチE��%' OR title LIKE '%アクア%' OR title LIKE '%ヤリス%' OR title LIKE '%ノ�EチE' OR title LIKE '%パッソ%' OR title LIKE '%スイフト%' OR title LIKE '%ヴィチE��%' OR title LIKE '%チE��オ%' OR title LIKE '%マ�EチE' OR title LIKE '%ポロ%' OR title LIKE '%ゴルチE' OR title LIKE '%ルーミ�E%' OR title LIKE '%ソリオ%' OR title LIKE '%タンク%' OR title LIKE '%FIT%' OR title LIKE '%AQUA%' OR title LIKE '%NOTE%'))";
            } elseif ($kw === 'ワゴン') {
                $where[] = "(title LIKE '%ワゴン%' OR title LIKE '%セレチE' OR title LIKE '%ヴォクシー%' OR title LIKE '%ノア%' OR title LIKE '%スチE��プワゴン%' OR title LIKE '%フリーチE' OR title LIKE '%シエンタ%' OR title LIKE '%アルファーチE' OR title LIKE '%ヴェルファイア%' OR title LIKE '%チE��カ%' OR title LIKE '%エスチE��チE' OR title LIKE '%オチE��セイ%')";
            } elseif ($kw === '4WD') {
                $where[] = "(
                    drive_type LIKE '%4WD%' OR drive_type LIKE '%�E�Ｗ�E�%' OR drive_type LIKE '%四駁E' OR drive_type LIKE '%AWD%' OR drive_type LIKE '%�E��E��E�%'
                    OR title LIKE '%4WD%' OR title LIKE '%�E�Ｗ�E�%' OR title LIKE '%AWD%' OR title LIKE '%�E��E��E�%' OR title LIKE '%SUV%' OR title LIKE '%クロスオーバ�E%' OR title LIKE '%キャチE��チE��%' OR title LIKE '%XT5%'
                    OR title LIKE '%ハスラー%' OR title LIKE '%ジムニ�E%' OR title LIKE '%ヴェゼル%' OR title LIKE '%ヤリスクロス%' OR title LIKE '%ライズ%' OR title LIKE '%ロチE��ー%' OR title LIKE '%エクストレイル%' OR title LIKE '%フォレスター%' OR title LIKE '%CX-%' OR title LIKE '%C-HR%'
                )";
            } else {
                $where[] = "(title LIKE :kw OR displacement LIKE :kw OR year LIKE :kw)";
                $params[':kw'] = "%{$kw}%";
            }
        }

        if (!empty($criteria['is_kei'])) {
            $where[] = "(displacement = '660cc' OR displacement LIKE '66%' OR title LIKE '%軽自動軁E')";
        }

        if (!empty($criteria['equip'])) {
            $eq = $criteria['equip'];
            if ($eq === 'ナビ') {
                $where[] = "(title LIKE '%ナビ%' OR title LIKE '%地チE��%' OR title LIKE '%TV%' OR title LIKE '%�E��E�%' OR title LIKE '%オーチE��オ%' OR equipments LIKE '%ナビ%' OR equipments LIKE '%チE��チE')";
            } elseif ($eq === 'TV' || $eq === 'チE��チE || $eq === '地チE��') {
                $where[] = "(title LIKE '%地チE��%' OR title LIKE '%フルセグ%' OR title LIKE '%ワンセグ%' OR title LIKE '%�E��E�%' OR title LIKE '%TV%' OR title LIKE '%チE��チE' OR equipments LIKE '%チE��チE' OR equipments LIKE '%地チE��%' OR equipments LIKE '%ワンセグ%' OR equipments LIKE '%フルセグ%')";
            } elseif ($eq === 'バックカメラ' || $eq === 'カメラ') {
                $where[] = "(title LIKE '%バックカメラ%' OR title LIKE '%全方佁E' OR title LIKE '%アラウンドビュー%' OR title LIKE '%カメラ%' OR equipments LIKE '%バックカメラ%' OR equipments LIKE '%カメラ%')";
            } elseif ($eq === 'Bluetooth' || $eq === 'ブルートゥース') {
                $where[] = "(title LIKE '%Bluetooth%' OR title LIKE '%�E��E�ｕａE��ｏｏｔａE' OR title LIKE '%ブルートゥース%' OR title LIKE '%カープレイ%' OR title LIKE '%carplay%' OR equipments LIKE '%Bluetooth%')";
            } elseif ($eq === 'ETC' || $eq === '�E��E��E�') {
                $where[] = "(title LIKE '%ETC%' OR title LIKE '%�E��E��E�%' OR equipments LIKE '%ETC%')";
            } elseif ($eq === 'ドラレコ' || $eq === 'ドライブレコーダー') {
                $where[] = "(title LIKE '%ドラレコ%' OR title LIKE '%ドライブレコーダー%' OR equipments LIKE '%ドライブレコーダー%' OR equipments LIKE '%ドラレコ%')";
            } elseif ($eq === 'スライチE || $eq === 'パワースライチE) {
                $where[] = "(title LIKE '%スライチE' OR title LIKE '%パワースライチE' OR equipments LIKE '%スライチE')";
            } elseif ($eq === 'スマ�Eトキー' || $eq === 'キーレス') {
                $where[] = "(title LIKE '%スマ�Eトキー%' OR title LIKE '%インチE��ジェンチE' OR title LIKE '%プッシュスターチE' OR title LIKE '%キーレス%' OR equipments LIKE '%スマ�Eトキー%')";
            } elseif ($eq === 'シートヒーター' || $eq === 'ヒ�Eター') {
                $where[] = "(title LIKE '%シートヒーター%' OR equipments LIKE '%シートヒーター%')";
            } elseif ($eq === 'LED' || $eq === '�E��E��E�' || $eq === 'HID') {
                $where[] = "(title LIKE '%LED%' OR title LIKE '%�E��E��E�%' OR title LIKE '%HID%' OR title LIKE '%�E��E��E�%' OR title LIKE '%オートライチE' OR equipments LIKE '%LED%' OR equipments LIKE '%オートライチE')";
            } elseif ($eq === 'アルチE || $eq === 'ホイール') {
                $where[] = "(title LIKE '%アルチE' OR title LIKE '%ホイール%' OR equipments LIKE '%アルチE')";
            } elseif ($eq === 'レザー' || $eq === '本革') {
                $where[] = "(title LIKE '%本革%' OR title LIKE '%レザー%' OR title LIKE '%ハ�Eフレザー%' OR title LIKE '%革調%' OR equipments LIKE '%本革%' OR equipments LIKE '%レザー%')";
            } elseif ($eq === '軽渁E || $eq === '安�E' || $eq === 'ブレーキ') {
                $where[] = "(title LIKE '%軽渁E' OR title LIKE '%ブレーキ%' OR title LIKE '%センシング%' OR title LIKE '%スマ�EトアシスチE' OR title LIKE '%セーフティ%' OR title LIKE '%プロパイロチE��%' OR equipments LIKE '%安�E%' OR equipments LIKE '%衝突E')";
            } elseif ($eq === '4WD' || $eq === '�E�Ｗ�E�' || $eq === '四駁E) {
                $where[] = "(
                    drive_type LIKE '%4WD%' OR drive_type LIKE '%�E�Ｗ�E�%' OR drive_type LIKE '%四駁E' OR drive_type LIKE '%AWD%' OR drive_type LIKE '%�E��E��E�%'
                    OR title LIKE '%4WD%' OR title LIKE '%�E�Ｗ�E�%' OR title LIKE '%AWD%' OR title LIKE '%四駁E' OR title LIKE '%クロスオーバ�E%' OR title LIKE '%キャチE��チE��%' OR title LIKE '%XT5%'
                )";
            } else {
                $where[] = "(title LIKE :eq OR equipments LIKE :eq)";
                $params[':eq'] = "%{$eq}%";
            }
        }

        if (isset($criteria['repair']) && $criteria['repair'] === 'none') {
            $where[] = "(repair_history = 'なぁE OR repair_history = '-' OR repair_history IS NULL)";
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
        
        // 最大40台まで取征E(LINEの1回返信上限: 10台ÁEカルーセル = 40台)
        $stmt = $db->prepare("SELECT * FROM cars WHERE {$whereSql} ORDER BY (total_price_num IS NULL), total_price_num ASC LIMIT 40");
        $stmt->execute($params);
        $cars = $stmt->fetchAll();

        writeDebugLog("検索実行完亁E, ['heading' => $heading, 'hitCount' => count($cars), 'userId' => $userId]);

        if (empty($cars)) {
            return [
                [
                    'type' => 'text',
                    'text' => "申し訳ありません。ご持E���E条件に一致する車両が見つかりませんでした、En\n別のキーワードや価格帯でお試しください�E�E,
                    'quickReply' => getQuickReplyItems()
                ]
            ];
        }

        // カルーセルバブルを構篁E
        $bubbles = [];
        foreach ($cars as $car) {
            $bubble = buildCarFlexBubble($car, $userId);
            if ($bubble) {
                $bubbles[] = $bubble;
            }
        }

        if (empty($bubbles)) {
            throw new Exception("バブル生�Eに失敗しました");
        }

        // LINEの仕槁E 1カルーセルあたり最大10件 -> 10件ずつ刁E��して褁E��カルーセルで一括返信
        $bubbleChunks = array_chunk($bubbles, 10);
        $totalCount = count($bubbles);

        $messages = [
            [
                'type' => 'text',
                'text' => "🔍 {$heading} �E��E{$totalCount}件�E�E
            ]
        ];

        foreach ($bubbleChunks as $idx => $chunk) {
            $messages[] = [
                'type' => 'flex',
                'altText' => "{$heading} (" . ($idx * 10 + 1) . "、E . ($idx * 10 + count($chunk)) . "件目)",
                'contents' => [
                    'type' => 'carousel',
                    'contents' => $chunk
                ],
                'quickReply' => getQuickReplyItems()
            ];
        }

        return $messages;
    } catch (Exception $e) {
        writeDebugLog("検索生�E例外エラー: " . $e->getMessage());
        return [
            [
                'type' => 'text',
                'text' => "申し訳ありません。検索中にエラーが発生しました、Enし�Eらくしてからもう一度お試しください、E,
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }
}

/**
 * 車両1台刁E�EFlex Messageバブルを構篁E
 */
function buildCarFlexBubble(array $car, string $userId = ''): array {
    $rawTitle = trim($car['title'] ?? '');
    if (empty($rawTitle)) {
        $rawTitle = '車両惁E��';
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
    $inquiryText = "【車両問い合わせ】\n車名: {$rawTitle}\n支払総顁E {$totalPrice}\n詳細: {$detailUrl}\n\nこちら�E車両につぁE��詳しく知りたぁE��す、E;
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
                            'text' => '支払総顁E,
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
                                ['type' => 'text', 'text' => '年弁E, 'color' => '#999999', 'size' => 'xxs', 'flex' => 2],
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
                        'label' => '💬 お問ぁE��わせ・相諁E,
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
 * 問い合わせ確認カード（誤タチE�E防止 & 要望選択）を送信
 */
function sendInquiryConfirmMessage(PDO $db, string $replyToken, string $carId, string $userId = '') {
    $stmt = $db->prepare("SELECT * FROM cars WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $carId]);
    $car = $stmt->fetch();

    if (!$car) {
        $messages = [['type' => 'text', 'text' => '該当�E車両惁E��が見つかりませんでした、E, 'quickReply' => getQuickReplyItems()]];
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
                    'text' => '📋 お問ぁE��わせ冁E��の確誁E,
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
                    'text' => "支払総顁E {$totalPrice}",
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
                    'text' => "ご希望のお問ぁE��わせ頁E��をタチE�Eしてください、En�E�スタチE��が確認�E上、本ト�Eクにてご案�Eします！E,
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
                        'data' => 'action=submit_inquiry&id=' . urlencode($carId) . '&type=' . urlencode('在庫・状態確誁E),
                        'displayText' => "【在庫・状態確認】をお願いしまぁE
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => '#3b82f6',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '📑 支払総額�E見積もりが欲しい',
                        'data' => 'action=submit_inquiry&id=' . urlencode($carId) . '&type=' . urlencode('総額見積もり依頼'),
                        'displayText' => "【支払総額�E見積もり】をお願いしまぁE
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
                        'data' => 'action=submit_inquiry&id=' . urlencode($carId) . '&type=' . urlencode('実車見学・試乗予紁E),
                        'displayText' => "【実車見学・試乗】を希望しまぁE
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'secondary',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '❁Eキャンセル',
                        'data' => 'action=cancel_inquiry',
                        'displayText' => "キャンセルしまぁE
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
 * 正式問ぁE��わせ実行！Eiscord通知 �E�E受付完亁E��チE��ージ�E�E
 */
function handleSubmitInquiry(PDO $db, string $replyToken, string $carId, string $inquiryType, string $userId = '') {
    $stmt = $db->prepare("SELECT * FROM cars WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $carId]);
    $car = $stmt->fetch();

    if (!$car) {
        $messages = [['type' => 'text', 'text' => '車両惁E��が見つかりませんでした、E, 'quickReply' => getQuickReplyItems()]];
        sendReplyMessage($replyToken, $messages);
        return;
    }

    $rawTitle = trim($car['title'] ?? '車両');
    $totalPrice = !empty($car['total_price_text']) ? $car['total_price_text'] : '要問合せ';

    // ユーザー惁E��取征E
    $userProfile = !empty($userId) ? getLineUserProfile($userId) : null;
    $userName = $userProfile['displayName'] ?? 'お客槁E;

    // 1. Discord へ正式問ぁE��わせ通知を送信�E�E
    if (function_exists('sendDiscordInquiryNotification')) {
        sendDiscordInquiryNotification($car, $inquiryType, $userProfile, $userId);
        writeDebugLog("正式問ぁE��わせ通知送信完亁E, ['carId' => $carId, 'type' => $inquiryType, 'user' => $userName]);
    }

    // 2. ユーザーへ受付完亁E��チE��ージを返信
    $messages = [
        [
            'type' => 'text',
            'text' => "{$userName} 様\n\n【{$inquiryType}】�Eご依頼を承りました�E�🚗✨\n\n対象車両: {$rawTitle}\n支払総顁E {$totalPrice}\n\n拁E��スタチE��が�E容を確認し、本ト�Eクにて折り返しご連絡・ご案�EさせてぁE��だきます。今しばらくお征E��くださいませ！E,
            'quickReply' => getQuickReplyItems()
        ]
    ];

    sendReplyMessage($replyToken, $messages);
}

/**
 * メンチE��ンス�E�オイル交揁E車検点検）予紁E��認メチE��ージ (誤タチE�E防止)
 */
function sendMaintenanceBookingConfirmMessage(string $replyToken, string $type, string $carModel, string $targetDate, string $userId = '') {
    if ($type === 'oil') {
        $title = '🛢 オイル交揁E来店予紁E�Eご確誁E;
        $color = '#f59e0b';
        $labelDate = '次回オイル予定日';
        $typeName = 'オイル交揁E;
    } elseif ($type === 'periodic') {
        $title = '📋 12ヶ月定期点椁Eご予紁E�Eご確誁E;
        $color = '#10b981';
        $labelDate = '次回点検予定日';
        $typeName = '12ヶ月定期点椁E;
    } else {
        $title = '🚗 車椁E来店予紁E�Eご確誁E;
        $color = '#3b82f6';
        $labelDate = '車検満亁E��';
        $typeName = '車椁E;
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
                    'text' => "ご希望のご来店日時�E時間帯をお選びください、En拁E��スタチE��が空き状況を確認し、本ト�Eクにて折り返しご案�EぁE��します！E,
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
                                ['type' => 'text', 'text' => '対象愛軁E, 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
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
                        'label' => '☀�E�E平日�E�午前中�E�を希望',
                        'data' => 'action=submit_maintenance&type=' . urlencode($typeName) . '&car=' . urlencode($carModel) . '&pref=' . urlencode('平日�E�午前中�E�E),
                        'displayText' => "【{$typeName}】平日�E�午前中�E�に来店を希望しまぁE
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => '#06C755',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '🌤�E�E平日�E�午後）を希望',
                        'data' => 'action=submit_maintenance&type=' . urlencode($typeName) . '&car=' . urlencode($carModel) . '&pref=' . urlencode('平日�E�午後！E),
                        'displayText' => "【{$typeName}】平日�E�午後）に来店を希望しまぁE
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
                        'displayText' => "【{$typeName}】土日・祝日に来店を希望しまぁE
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'secondary',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '💬 日程を個別にLINE相諁E,
                        'data' => 'action=submit_maintenance&type=' . urlencode($typeName) . '&car=' . urlencode($carModel) . '&pref=' . urlencode('日程を個別に相諁E��たい'),
                        'displayText' => "【{$typeName}】日程につぁE��個別に相諁E��たいでぁE
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'link',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '❁Eキャンセル',
                        'data' => 'action=cancel_maintenance',
                        'displayText' => "キャンセルしまぁE
                    ]
                ]
            ]
        ]
    ];

    $messages = [
        [
            'type' => 'flex',
            'altText' => "【ご予紁E��認】{$title}",
            'contents' => $confirmBubble
        ]
    ];

    sendReplyMessage($replyToken, $messages);
}

/**
 * メンチE��ンス予紁E��行！Eiscord通知 �E�E受付完亁E��チE��ージ�E�E
 */
function handleSubmitMaintenanceBooking(string $replyToken, string $bookingType, string $carModel, string $prefTime, string $userId = '') {
    // ユーザー惁E��取征E
    $userProfile = !empty($userId) ? getLineUserProfile($userId) : null;
    $userName = $userProfile['displayName'] ?? 'お客槁E;

    // 1. Discord へ予紁E��し込み通知を送信�E�E
    if (function_exists('sendDiscordMaintenanceBookingNotification')) {
        sendDiscordMaintenanceBookingNotification($bookingType, $carModel, $prefTime, $userProfile, $userId);
        writeDebugLog("メンチE��ンス予約Discord通知完亁E, ['type' => $bookingType, 'car' => $carModel, 'user' => $userName, 'pref' => $prefTime]);
    }

    // 2. ユーザーへ受付完亁E��チE��ージを返信
    $messages = [
        [
            'type' => 'text',
            'text' => "{$userName} 様\n\n【{$bookingType}】�Eご予紁E��諁E��承りました�E�🛠�E�✨\n\n対象愛軁E {$carModel}\nご希望日晁E {$prefTime}\n\n店�EスタチE��がピチE��の空き状況を確認し、本ト�Eクにて確定日程�Eお見積もり�Eご案�Eをお送りぁE��します。どぁE��よろしくお願いぁE��します！🚁E,
            'quickReply' => getQuickReplyItems()
        ]
    ];

    sendReplyMessage($replyToken, $messages);
}

/**
 * 価格帯選択メニュー送信
 */
function sendPriceMenuMessage(string $replyToken) {
    $messages = generatePriceMenuMessages();
    sendReplyMessage($replyToken, $messages);
}

/**
 * 価格帯選択メニュー�E�サイレント�Eタン式Flex Message�E�生戁E
 */
function generatePriceMenuMessages(): array {
    $priceBubble = [
        'type' => 'bubble',
        'size' => 'kilo',
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '16px',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '💰 ご予算�E支払総額から探ぁE,
                    'weight' => 'bold',
                    'size' => 'md',
                    'color' => '#1e293b'
                ],
                [
                    'type' => 'text',
                    'text' => 'ご希望の価格帯をタチE�Eしてください、E,
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
                    'spacing' => 'sm',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'spacing' => 'sm',
                            'contents' => [
                                [
                                    'type' => 'button',
                                    'style' => 'secondary',
                                    'height' => 'sm',
                                    'flex' => 1,
                                    'action' => [
                                        'type' => 'postback',
                                        'label' => '、E0丁E�E',
                                        'data' => 'action=search_price&max_price=30'
                                    ]
                                ],
                                [
                                    'type' => 'button',
                                    'style' => 'secondary',
                                    'height' => 'sm',
                                    'flex' => 1,
                                    'action' => [
                                        'type' => 'postback',
                                        'label' => '、E0丁E�E',
                                        'data' => 'action=search_price&max_price=50'
                                    ]
                                ]
                            ]
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'spacing' => 'sm',
                            'contents' => [
                                [
                                    'type' => 'button',
                                    'style' => 'secondary',
                                    'height' => 'sm',
                                    'flex' => 1,
                                    'action' => [
                                        'type' => 'postback',
                                        'label' => '、E0丁E�E',
                                        'data' => 'action=search_price&max_price=70'
                                    ]
                                ],
                                [
                                    'type' => 'button',
                                    'style' => 'secondary',
                                    'height' => 'sm',
                                    'flex' => 1,
                                    'action' => [
                                        'type' => 'postback',
                                        'label' => '、E00丁E�E',
                                        'data' => 'action=search_price&max_price=100'
                                    ]
                                ]
                            ]
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'spacing' => 'sm',
                            'contents' => [
                                [
                                    'type' => 'button',
                                    'style' => 'secondary',
                                    'height' => 'sm',
                                    'flex' => 1,
                                    'action' => [
                                        'type' => 'postback',
                                        'label' => '、E50丁E�E',
                                        'data' => 'action=search_price&max_price=150'
                                    ]
                                ],
                                [
                                    'type' => 'button',
                                    'style' => 'secondary',
                                    'height' => 'sm',
                                    'flex' => 1,
                                    'action' => [
                                        'type' => 'postback',
                                        'label' => '、E00丁E�E',
                                        'data' => 'action=search_price&max_price=200'
                                    ]
                                ]
                            ]
                        ],
                        [
                            'type' => 'button',
                            'style' => 'primary',
                            'color' => '#06C755',
                            'height' => 'sm',
                            'margin' => 'sm',
                            'action' => [
                                'type' => 'postback',
                                'label' => 'すべての在庫を見る',
                                'data' => 'action=search_all'
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];

    return [
        [
            'type' => 'flex',
            'altText' => 'ご予算�E支払総額から探ぁE,
            'contents' => $priceBubble,
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
 * 走行距離メニューを生戁E(Reply / Push 共送E - 動的在庫雁E��型
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
            'name' => '届�E済未使用軁E,
            'action' => 'search_low_mileage',
            'param' => '',
            'check' => function($car) {
                return (str_contains($car['title'] ?? '', '未使用') || (!empty($car['distance_num']) && $car['distance_num'] <= 0.05));
            }
        ],
        [
            'name' => '、E万km',
            'action' => 'search_distance',
            'param' => 'max_distance=1.0',
            'check' => function($car) {
                return (isset($car['distance_num']) && $car['distance_num'] !== null && (float)$car['distance_num'] <= 1.0);
            }
        ],
        [
            'name' => '、E万km',
            'action' => 'search_distance',
            'param' => 'max_distance=3.0',
            'check' => function($car) {
                return (isset($car['distance_num']) && $car['distance_num'] !== null && (float)$car['distance_num'] <= 3.0);
            }
        ],
        [
            'name' => '、E万km',
            'action' => 'search_distance',
            'param' => 'max_distance=5.0',
            'check' => function($car) {
                return (isset($car['distance_num']) && $car['distance_num'] !== null && (float)$car['distance_num'] <= 5.0);
            }
        ],
        [
            'name' => '、E万km',
            'action' => 'search_distance',
            'param' => 'max_distance=7.0',
            'check' => function($car) {
                return (isset($car['distance_num']) && $car['distance_num'] !== null && (float)$car['distance_num'] <= 7.0);
            }
        ],
        [
            'name' => '、E0万km',
            'action' => 'search_distance',
            'param' => 'max_distance=10.0',
            'check' => function($car) {
                return (isset($car['distance_num']) && $car['distance_num'] !== null && (float)$car['distance_num'] <= 10.0);
            }
        ],
        [
            'name' => '10万km趁E,
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
                            'text' => '走行距離で探ぁE . $titleSuffix,
                            'weight' => 'bold',
                            'size' => 'md',
                            'color' => '#1e293b'
                        ],
                        [
                            'type' => 'text',
                            'text' => '在庫に実在する走行距離帯から選べまぁE,
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
                'text' => "現在、該当する走行距離条件の在庫を更新中です、E,
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }

    return [
        [
            'type' => 'flex',
            'altText' => '走行距離から探ぁE,
            'contents' => [
                'type' => 'carousel',
                'contents' => $activeBubbles
            ],
            'quickReply' => getQuickReplyItems()
        ]
    ];
}

/**
 * カーライフ豁E��識�Eお役立ちガイドメニューを送信
 */
function sendKnowledgeMenuMessage(string $replyToken) {
    $messages = generateKnowledgeMenuMessages();
    sendReplyMessage($replyToken, $messages);
}

/**
 * カーライフ豁E��識�E個別記事を送信
 */
function sendKnowledgeDetailMessage(string $replyToken, string $topic) {
    $messages = generateKnowledgeDetailMessage($topic);
    sendReplyMessage($replyToken, $messages);
}

/**
 * カーライフ豁E��識�Eお役立ちガイド（目次3段カルーセル・全21チE�Eマ�E通し番号付き�E�を生�E
 */
function generateKnowledgeMenuMessages(): array {
    // 1段目: 車選び�E�E��入・手続きガイド（①〜⑦�E�E
    $group1Topics = [
        [
            'topic' => 'used_car',
            'badge' => '🚗 車選びの極愁E,
            'badge_color' => '#3b82f6',
            'title' => '① 失敗しなぁE��古車�E選び方',
            'desc' => "プロが教える�E�走行距離・修復歴・整備履歴など後悔しなぁE大チェチE��ポイント、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'kei_vs_compact',
            'badge' => '🚙 徹底比輁E��イチE,
            'badge_color' => '#8b5cf6',
            'title' => '② 軽自動軁Evs 普通車�E維持費比輁E,
            'desc' => "税��・車検�E燁E��・保険料�E年間コスト差と、ライフスタイル別の賢ぁE��び方、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'body_type_guide',
            'badge' => '🚙 目皁E��・車選び',
            'badge_color' => '#0284c7',
            'title' => '③ ボディタイプ別の特徴と選び方',
            'desc' => "軽・SUV・ミニバン・コンパクト�E特徴と、家族構�EめE��途に合った最適車種診断、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'car_loan',
            'badge' => '💳 ローン�E�E��E��計画',
            'badge_color' => '#4f46e5',
            'title' => '④ オートローンの賢ぁE��び方',
            'desc' => "金利の種類、無琁E�EなぁE��済比率�E�手取りの15、E0%�E�、事前仮審査のメリチE��、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'best_timing',
            'badge' => '💰 お得な買ぁE��',
            'badge_color' => '#ea580c',
            'title' => '⑤ 車�Eお得な買ぁE��・購入時期',
            'desc' => "決算期�E�E月�E9月）やモチE��チェンジ後、�E動車税�E課税時期から見るベストな時期、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'car_paperwork',
            'badge' => '📄 手続き�E�E��れ',
            'badge_color' => '#0891b2',
            'title' => '⑥ 忁E��書類と納車までの流れ',
            'desc' => "車庫証明や印鑑証明、住民票の準備から納車前点検�E受取までのスチE��プを解説、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'trade_in',
            'badge' => '🛡�E�E査定額UPの秘訣',
            'badge_color' => '#d97706',
            'title' => '⑦ 愛車を高く売る�E下取り�EコチE,
            'desc' => "査定士が見る重要�Eイント、純正パ�EチE��管、�Eストな売却タイミングを伝授、E,
            'read_time' => '紁E刁E��読める'
        ]
    ];

    // 2段目: メンチE��ンス・点検！E��アガイド（⑧〜⑭�E�E
    $group2Topics = [
        [
            'topic' => 'oil',
            'badge' => '🛢�E�E愛車長持ちの秘訣',
            'badge_color' => '#f59e0b',
            'title' => '⑧ エンジンオイル交換�E真宁E,
            'desc' => "「まだ走れる」�E危険�E�適刁E��交換サイクルとフィルター交換�E重要性を解説、E,
            'read_time' => '紁E.5刁E��読める'
        ],
        [
            'topic' => 'periodic',
            'badge' => '📋 予防整備�E基礁E,
            'badge_color' => '#10b981',
            'title' => '⑨ 法宁E2ヶ月点検�E忁E��性',
            'desc' => "車検に通ってぁE��も安忁E��きなぁE��受けるメリチE��と車検との違いを�Eロが解説、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'inspection',
            'badge' => '🔍 安忁E��E��ムーズ',
            'badge_color' => '#6366f1',
            'title' => '⑩ 車検�E基礎知識と賢ぁE��け方',
            'desc' => "満亁E��の1ヶ月前から受検可能�E�費用の冁E��めE��備物、安忁E��検�Eポイント、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'battery_tire',
            'badge' => '⚠�E�Eトラブル予防',
            'badge_color' => '#ef4444',
            'title' => '⑪ バッチE��ー・タイヤ・日常点椁E,
            'desc' => "出先での突然死を防ぐ！季節ごとのトラブル対策と交換サインの見極め方、E,
            'read_time' => '紁E.5刁E��読める'
        ],
        [
            'topic' => 'brake_care',
            'badge' => '🛑 安�Eの要�Eブレーキ',
            'badge_color' => '#e11d48',
            'title' => '⑫ ブレーキの寿命と重要チェチE��',
            'desc' => "パッド残厚3mmの危険サイン、キーキー音の正体、フルード吸湿劣化�E注意点、E,
            'read_time' => '紁E.5刁E��読める'
        ],
        [
            'topic' => 'aircon_care',
            'badge' => '❁E��E快適ドライチE,
            'badge_color' => '#0ea5e9',
            'title' => '⑬ カーエアコンの効き！E��臭ケア',
            'desc' => "エアコンフィルター交換時期、エバ�Eレーター消�E洗流E��ガス補�Eで冷え復活�E�E,
            'read_time' => '紁E.5刁E��読める'
        ],
        [
            'topic' => 'car_wash_care',
            'badge' => '🧼 愛車ケア�E�E��観',
            'badge_color' => '#06b6d4',
            'title' => '⑭ 洗車！E�EチE��コーチE��ング衁E,
            'desc' => "炎天下�E洗車NG琁E��、洗車キズを防ぐ洗い方、コーチE��ングを長持ちさせる秘訣、E,
            'read_time' => '紁E刁E��読める'
        ]
    ];

    // 3段目: 安�E運転・トラブル緊急対処�E�E��節対策（⑮〜㉑�E�E
    $group3Topics = [
        [
            'topic' => 'winter_driving',
            'badge' => '❁E��E冬道�E降雪対筁E,
            'badge_color' => '#0284c7',
            'title' => '⑮ 雪道運転と冬タイヤの極愁E,
            'desc' => "スタチE��レスの寿命見極めE���EラチE��ホ�Eム�E�と融雪剤による下回り防錁E��策、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'warning_lights',
            'badge' => '🚨 緊急・トラブル診断',
            'badge_color' => '#dc2626',
            'title' => '⑯ 警告�Eの意味と緊急時�E対処況E,
            'desc' => "黁E��と赤色の危険度の違い、異音�E�カタカタ・キーキー�E��E正体と初期対応、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'rain_driving',
            'badge' => '🌧�E�E雨天・悪天候対筁E,
            'badge_color' => '#2563eb',
            'title' => '⑰ 雨の日の安�E運転と冠水対筁E,
            'desc' => "冠水道路の走行限界、ハイドロプレーニング予防、撥水とワイパ�E視界確保、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'accident_guide',
            'badge' => '💥 緊急初動マニュアル',
            'badge_color' => '#b91c1c',
            'title' => '⑱ 事故・敁E��時�E緊急対応手頁E,
            'desc' => "二次災害防止、E19番・110番の義務、警察�E事故証明と保険会社連絡スチE��プ、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'fuel_economy',
            'badge' => '⛽ 燁E���E�E��紁E��E,
            'badge_color' => '#059669',
            'title' => '⑲ 燁E��アチE�E�E�E�E車�E節紁E��E,
            'desc' => "ふんわりアクセル・タイヤ空気圧・不要な荷物軽量化でガソリン代を大幁E��チE���E�E,
            'read_time' => '紁E.5刁E��読める'
        ],
        [
            'topic' => 'beginner_driver',
            'badge' => '🔰 安忁E��ライチE,
            'badge_color' => '#16a34a',
            'title' => '⑳ 初忁E��E�Eペ�Eパ�Eドライバ�E衁E,
            'desc' => "車幁E��覚�E掴み方、バチE��駐車�E目印、死角�E確認、車間距離の安�Eマニュアル、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'car_accessories',
            'badge' => '🔌 便利アイチE��・裁E��',
            'badge_color' => '#7c3aed',
            'title' => '㉁Eドラレコ・ETC・LED便利知譁E,
            'desc' => "前征Eカメラドラレコの選び方、ETC2.0の割引メリチE��、車検対応LED化�E注意点、E,
            'read_time' => '紁E刁E��読める'
        ]
    ];

    // 4段目: 愛車長持ち・査定UP�E�E��ラブルレスキュー�E�㉒〜㉘�E�E
    $group4Topics = [
        [
            'topic' => 'car_appraisal',
            'badge' => '💴 愛車売却�E�E��定UP',
            'badge_color' => '#d97706',
            'title' => '㉁E愛車を高く売る�E査定UP衁E,
            'desc' => "洗車�E車�E消�E・純正パ�EチE��管・査定時期�E見極めで買取額が大幁E��チE�E�E�E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'tire_rotation',
            'badge' => '🛞 タイヤ長持ち・安�E',
            'badge_color' => '#0284c7',
            'title' => '㉁EタイヤローチE�Eションと偏摩老E,
            'desc' => "前後�E摩耗差を解消！E,000kmごとの位置交換でタイヤ寿命ぁE.5倍に延びる、E,
            'read_time' => '紁E.5刁E��読める'
        ],
        [
            'topic' => 'disaster_car_stay',
            'badge' => '🏕�E�E防災・緊急車中況E,
            'badge_color' => '#dc2626',
            'title' => '㉁E車�E防災�E�E��害時車中泊�Eニュアル',
            'desc' => "大雪立ち往生�E地霁E��策。一酸化炭素中毒防止と車載すべき防災7つ道�E、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'headlight_yellowing',
            'badge' => '✨ 美観�E�E��間視界',
            'badge_color' => '#7c3aed',
            'title' => '㉁Eヘッドライト黁E�Eみ除去と予防',
            'desc' => "紫外線劣化�E黁E�Eみは光量不足で車検落ちの原因に�E�クリアな瞳を取り戻す方法、E,
            'read_time' => '紁E.5刁E��読める'
        ],
        [
            'topic' => 'smart_key_battery',
            'badge' => '🔑 トラブル緊急脱出',
            'badge_color' => '#e11d48',
            'title' => '㉁Eスマ�Eトキー電池刁E��時�E始動況E,
            'desc' => "鍵が開かなぁE�EエンジンがかからなぁE��の「�E蔵キー�E�E��チE��始動」完�Eガイド、E,
            'read_time' => '紁E.5刁E��読める'
        ],
        [
            'topic' => 'hybrid_battery_care',
            'badge' => '🔋 HV・EVの賢ぁE��り方',
            'badge_color' => '#059669',
            'title' => '㉁EハイブリチE��車�EバッチE��ー延命衁E,
            'desc' => "駁E��用バッチE��ーを長持ちさせる運転法と、見落としがちな「補機バチE��リー」�E盲点、E,
            'read_time' => '紁E刁E��読める'
        ],
        [
            'topic' => 'daily_car_check',
            'badge' => '🔍 5刁E��ルフ点椁E,
            'badge_color' => '#2563eb',
            'title' => '㉁E日常点検「�E・た�Eは・と・ぁE�Eみ・ず、E,
            'desc' => "ドライブ前に5刁E��できる�E��Eロも推奨する7大セルフチェチE��の合言葉、E,
            'read_time' => '紁E刁E��読める'
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
            'altText' => '【第1弾: 車選び�E�E��入・手続きガイチE①〜⑦】カーライフ豁E��譁E,
            'contents' => [
                'type' => 'carousel',
                'contents' => $buildCarouselBubbles($group1Topics)
            ]
        ],
        [
            'type' => 'flex',
            'altText' => '【第2弾: メンチE��ンス・点検！E��アガイチE⑧〜⑭】カーライフ豁E��譁E,
            'contents' => [
                'type' => 'carousel',
                'contents' => $buildCarouselBubbles($group2Topics)
            ]
        ],
        [
            'type' => 'flex',
            'altText' => '【第3弾: 安�E運転・トラブル対処�E�E��利知譁E⑮〜㉑】カーライフ豁E��譁E,
            'contents' => [
                'type' => 'carousel',
                'contents' => $buildCarouselBubbles($group3Topics)
            ]
        ],
        [
            'type' => 'flex',
            'altText' => '【第4弾: 愛車長持ち・査定UP�E�E��ラブルレスキュー ㉒〜㉘】カーライフ豁E��譁E,
            'contents' => [
                'type' => 'carousel',
                'contents' => $buildCarouselBubbles($group4Topics)
            ],
            'quickReply' => getQuickReplyItems()
        ]
    ];
}

/**
 * 吁E��ーマ�E詳細解説 Flex Message を生戁E
 */
function generateKnowledgeDetailMessage(string $topic): array {
    $articleData = [];

    switch ($topic) {
        case 'used_car':
            $articleData = [
                'badge' => '🚗 車選びの極意【①、E,
                'badge_color' => '#3b82f6',
                'title' => '① 失敗しなぁE��古車�E選び方',
                'subtitle' => 'プロが教える�E�後悔しなぁE大見極め衁E,
                'sections' => [
                    [
                        'icon' => '1�E�⃣',
                        'title' => '年式と走行距離のバランス',
                        'desc' => "一般皁E��走行距離の目安�E、E年�E�紁E,000km、E万km】です、En10年で1万kmなど極端に走行が少なぁE��置車よりも、年式相応に定期皁E��動いてオイル交換されてぁE��車両の方が好調なケースが多いです、E
                    ],
                    [
                        'icon' => '2�E�⃣',
                        'title' => '修復歴�E�事故歴�E��E有無を確誁E,
                        'desc' => "「修復歴あり」とは車�E骨格�E�フレーム�E�にダメージ・修琁E��がある車を持E��ます、En外見が綺麗でも走行安定性に影響が�Eる可能性があるため、修復歴の有無を�E確に開示してぁE��店�Eを選びましょぁE��E
                    ],
                    [
                        'icon' => '3�E�⃣',
                        'title' => '定期点検記録簿�E�整備手帳�E�E,
                        'desc' => "過去の点検や消耗品交換�E履歴が残ってぁE��記録簿は、前オーナ�Eが大刁E��乗ってぁE��最大の証拠です、E
                    ],
                    [
                        'icon' => '4�E�⃣',
                        'title' => '車�Eのニオイと下回り�EサチE,
                        'desc' => "写真ではわからなぁE��バコ・ペット�EめE��E��雪地・沿岸部特有�E下回りサビ�E要チェチE��です、E
                    ],
                    [
                        'icon' => '5�E�⃣',
                        'title' => '支払総額と保証冁E��',
                        'desc' => "車両本体価格の安さだけで判断せず、諸費用込みの「支払総額」と「保証期間・篁E��」を忁E��確認しましょぁE��E
                    ]
                ],
                'summary' => 'アチE�Eファーレンでは全車両の修復歴を開示し、厳選した高品質車両のみを支払総額�E瞭で展示しております！E,
                'action_btn' => [
                    'label' => '🚗 アチE�Eファーレンの在庫を見る',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'kei_vs_compact':
            $articleData = [
                'badge' => '🚙 徹底比輁E��イド【②、E,
                'badge_color' => '#8b5cf6',
                'title' => '② 軽自動軁Evs 普通車�E維持費比輁E,
                'subtitle' => '税��・車検�E使ぁE��手�Eリアルな違い',
                'sections' => [
                    [
                        'icon' => '💴',
                        'title' => '税��・固定費の圧倒的な差',
                        'desc' => "・自動車税（年�E�：軽 10,800冁Evs コンパクトカー 25,000、E0,500冁E��年間紁E.5、E丁E�E差�E�\n・重量税！E年�E�：軽 6,600冁Evs 普通軁E16,400、E4,600冁En・高速道路料�߁E�軽自動車�E普通車より紁E0%割引！E
                    ],
                    [
                        'icon' => '🚗',
                        'title' => '最新の軽自動車�E進匁E,
                        'desc' => "スライドドア�E�E-BOX・タント等）により大人4人がゆったり乗れ、シートアレンジめE��物の積載力も抜群、En衝突被害軽減ブレーキ等�E先進安�E裁E��も普通車同等です、E
                    ],
                    [
                        'icon' => '🛣�E�E,
                        'title' => '普通車（コンパクトカー�E�が向いてぁE��人',
                        'desc' => "高速道路を頻繁に利用する方、E��距離運転が多い方、E人乗車する機会がある方は、E��粛性めE��ワーに余裕がある普通車がおすすめです、E
                    ]
                ],
                'summary' => 'お客様�E使ぁE��めE��予算に合わせて、最適な車種選びを�EロがアドバイスぁE��します！E,
                'action_btn' => [
                    'label' => '🚘 軽自動車�E在庫一覧を見る',
                    'data' => 'action=search_kei'
                ]
            ];
            break;

        case 'body_type_guide':
            $articleData = [
                'badge' => '🚙 目皁E��・車選び【③、E,
                'badge_color' => '#0284c7',
                'title' => '③ ボディタイプ別の特徴と選び方',
                'subtitle' => '用途や家族構�Eに合わせた最適車種診断',
                'sections' => [
                    [
                        'icon' => '🚗',
                        'title' => '軽ハイトワゴン�E�E-BOX/タンチEスペ�Eシア等！E,
                        'desc' => "圧倒的な室冁E��とスライドドアで子育て世代めE��迎�Eお買ぁE��に最強、En維持費の安さとリセールバリューの高さも大きな魁E��です、E
                    ],
                    [
                        'icon' => '🚙',
                        'title' => 'SUV / クロスオーバ�E�E�ヤリスクロス/ヴェゼル等！E,
                        'desc' => "アイポイントが高く運転しやすいのが特徴。悪路めE��道に強ぁEWDモチE��も豊富で、アウトドア派めE�E道重視�E方に大人気です、E
                    ],
                    [
                        'icon' => '🚐',
                        'title' => 'ミニバン・コンパクトカー�E�セレチEフリーチEノ�Eト等！E,
                        'desc' => "3列シートで6、E人乗れるミニバンは家族旅行に最適、Enコンパクトカーは小回りと低燃費、E��速安定性のバランスに優れてぁE��す、E
                    ]
                ],
                'summary' => 'アチE�Eファーレンでは軽からSUV・ミニバンまで豊富な在庫をご用意しております！E,
                'action_btn' => [
                    'label' => '🚙 車種・ボディタイプで探ぁE,
                    'data' => 'action=show_type_menu'
                ]
            ];
            break;

        case 'car_loan':
            $articleData = [
                'badge' => '💳 ローン�E�E��E��計画【④、E,
                'badge_color' => '#4f46e5',
                'title' => '④ オートローンの賢ぁE��び方',
                'subtitle' => '金利の仕絁E��と無琁E�EなぁE��済�Eランの立て方',
                'sections' => [
                    [
                        'icon' => '🏦',
                        'title' => '主なローンの種類と特徴',
                        'desc' => "・チE��ーラー・提携ローン�E�店頭で即日審査可能�E�E��続きが簡単\n・銀行�Eイカーローン�E�低��利だが審査に数日、E週間程度\n・自社ローン�E�他社で審査に不安がある方向けの独自プラン"
                    ],
                    [
                        'icon' => '📊',
                        'title' => '実質年玁E��支払総額�E比輁E,
                        'desc' => "表面上�E月、E��済額だけでなく「�E割手数料を含めた最終的な総支払額」を忁E��確認しましょぁE��En頭金やボ�Eナス払いの併用で総��利を抑えられます、E
                    ],
                    [
                        'icon' => '💡',
                        'title' => '安忁E�E返済比率�E�手取りの15、E0%�E�E,
                        'desc' => "毎月の返済額�E手取り月収�E、E5%、E0%以冁E��に抑える�Eが、ガソリン代めE��険料を含めても無琁E��く維持できる黁E��比率です、E
                    ]
                ],
                'summary' => 'アチE�Eファーレンではお客様�Eライフスタイルに合わせた吁E��ローンシミュレーションを無料で行っております！E,
                'action_btn' => [
                    'label' => '💬 お支払いプランをLINE相諁E,
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'best_timing':
            $articleData = [
                'badge' => '💰 お得な買ぁE��【⑤、E,
                'badge_color' => '#ea580c',
                'title' => '⑤ 車�Eお得な買ぁE��・購入時期',
                'subtitle' => '賢く買って得する�Eストなタイミング',
                'sections' => [
                    [
                        'icon' => '🗓',
                        'title' => '決算期�E�E月�E9月！E,
                        'desc' => "自動車業界�E決算月である3月と中間決算�E9月�E、販売目標達成�Eため値引きめE��プションサービスなどの特典が�E実しめE��ぁE��ぁE��時期です、E
                    ],
                    [
                        'icon' => '🔄',
                        'title' => 'フルモチE��チェンジ直征E,
                        'desc' => "新型車が登場した直後�E、前型モチE��の下取り車や未使用車が多く市場に出回り、価格相場が下がりやすくお得に状態�E良ぁE��両が手に入ります、E
                    ],
                    [
                        'icon' => '💴',
                        'title' => '自動車税！E月課税）�Eタイミング',
                        'desc' => "自動車税�E毎年4朁E日時点の所有老E��1年刁E��税されます、En普通車�E月割り課税ですが、軽自動車�E月割り制度がなぁE��め、E朁E日以降�E購入】がお得です、E
                    ]
                ],
                'summary' => 'タイミングを見極めて、お目当ての愛車をお得に手に入れましょぁE��E,
                'action_btn' => [
                    'label' => '🚗 現在の厳選在庫一覧を見る',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'car_paperwork':
            $articleData = [
                'badge' => '📄 手続き�E�E��れ【⑥、E,
                'badge_color' => '#0891b2',
                'title' => '⑥ 忁E��書類と納車までの流れ',
                'subtitle' => '準備から納車当日までの完�EスチE��チE,
                'sections' => [
                    [
                        'icon' => '1�E�⃣',
                        'title' => 'ご契紁E��の忁E��書顁E,
                        'desc' => "・普通車：印鑑証明書�E�発行征Eヶ月以冁E��、実印\n・軽自動車：住民票�E�発行征Eヶ月以冁E��、認印\n※車庫証明が忁E��な地域では保管場所承諾証明書等を用意します、E
                    ],
                    [
                        'icon' => '2�E�⃣',
                        'title' => '納車前点検�E整傁E,
                        'desc' => "ご契紁E��、法定点検や消耗品交換（オイル・エレメント�EバッチE��ー・ワイパ�E等）、車検取得、�EチE��美裁E��徹底的に行います、E
                    ],
                    [
                        'icon' => '3�E�⃣',
                        'title' => '名義変更とナンバ�E登録',
                        'desc' => "管轁E�E陸運支局・軽自動車検査協会にて、お客様名義への登録手続きを店�Eが代行いたします、E
                    ],
                    [
                        'icon' => '4�E�⃣',
                        'title' => '納車（紁E、E週間！E,
                        'desc' => "お車�Eお引き渡し時に操作説明や保証書のお渡しを行い、安忁E�Eカーライフがスタートします！E
                    ]
                ],
                'summary' => 'アチE�Eファーレンでは面倒な名義変更めE��類作�Eもフルサポ�Eトいたします！E,
                'action_btn' => [
                    'label' => '💬 購入手続きにつぁE��LINEで相諁E,
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'trade_in':
            $articleData = [
                'badge' => '🛡�E�E査定額UPの秘訣【⑦、E,
                'badge_color' => '#d97706',
                'title' => '⑦ 愛車を高く売る�E下取り�EコチE,
                'subtitle' => '査定士が見るポイントと乗り換えのベスト時朁E,
                'sections' => [
                    [
                        'icon' => '📋',
                        'title' => '定期点検記録簿の完備',
                        'desc' => "整備手帳にチE��ーラーめE��備工場での点検印・記録が揃ってぁE��と、大刁E��扱われてぁE��証拠となり査定�Eラス評価になります、E
                    ],
                    [
                        'icon' => '💎',
                        'title' => '純正パ�EチE�E説明書・スペアキー',
                        'desc' => "社外ナビやホイールに交換してぁE��も、純正パ�EチE��保管しておくと査定が上がります、Enスペアキーの有無も数丁E�Eの査定差になることがあります、E
                    ],
                    [
                        'icon' => '🚭',
                        'title' => '車�Eの渁E��感とニオイ対筁E,
                        'desc' => "タバコ臭めE�EチE��臭、シート�Eシミ�E減額対象になります、En査定前に車�E渁E��と消�Eを行っておくのが鉄剁E��す、E
                    ],
                    [
                        'icon' => '🗓',
                        'title' => 'ベストな手放しタイミング',
                        'desc' => "車検が刁E��る直前や、中古車需要が高まめE、E月�E9月�E高額査定が出めE��ぁE��期です、E
                    ]
                ],
                'summary' => 'アチE�Eファーレンでは愛車�E下取り�E無料査定を実施中�E�お乗り換えのご相諁E��お気軽にどぁE��、E,
                'action_btn' => [
                    'label' => '💬 愛車�E下取り�E乗り換えを相諁E,
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'oil':
            $articleData = [
                'badge' => '🛢�E�E愛車長持ちの秘訣【⑧、E,
                'badge_color' => '#f59e0b',
                'title' => '⑧ エンジンオイル交換�E基本と真宁E,
                'subtitle' => '愛車�E忁E��を守る血液�E�E��刁E��交換サイクル',
                'sections' => [
                    [
                        'icon' => '🩸',
                        'title' => 'エンジンオイルの5大役割',
                        'desc' => "エンジン冁E��の「潤滑�E冷却・洗流E�E防錁E�E寁E��」を拁E��てぁE��す、En走行しなくても空気中の水刁E��熱で半年、E年で酸化劣化します、E
                    ],
                    [
                        'icon' => '⏱',
                        'title' => '適刁E��交換サイクルの目宁E,
                        'desc' => "・軽自動車／ターボ車！E,000、E,000km また�E 半年\n・普通車！EA�E�！E,000、E0,000km また�E 半年、E年\n※近距離のチョイ乗りが多い車�EシビアコンチE��ション�E�過酷環墁E��となり、早め�E交換が推奨されます、E
                    ],
                    [
                        'icon' => '⚠�E�E,
                        'title' => '交換を怠るとどぁE��る！E,
                        'desc' => "オイルがドロドロになり燃費が悪化、異音の発生、最悪の場合�Eエンジンが焼き付き、載せ替えで30丁E��E0丁E�E以上�E高額�E費になることもあります、E
                    ],
                    [
                        'icon' => '🔄',
                        'title' => 'オイルエレメント（フィルター�E�E,
                        'desc' => "オイル冁E�EスラチE���E�ゴミ）をろ過するフィルターです。【オイル交揁E回に1回】�E同時交換が鉁E��です、E
                    ]
                ],
                'summary' => '定期皁E��オイル交換こそが、�E車を最も安く・長く乗り続けるため�E最高�E予防メンチE��ンスです、E,
                'action_btn' => [
                    'label' => '📅 オイル交換�E来店予紁E�E相諁E,
                    'data' => 'action=ask_maintenance&type=oil'
                ]
            ];
            break;

        case 'periodic':
            $articleData = [
                'badge' => '📋 予防整備�E基礎【⑨、E,
                'badge_color' => '#10b981',
                'title' => '⑨ 法宁E2ヶ月定期点検�E忁E��性',
                'subtitle' => '車検だけでは不十刁E��法律で定められた点椁E,
                'sections' => [
                    [
                        'icon' => '⚖︁E,
                        'title' => '車検と12ヶ月点検�E決定的な違い',
                        'desc' => "・車検：受検した「その瞬間」に国の保安基準を満たしてぁE��かを確認する検査\n・12ヶ月点検：次の車検までの1年間、安�Eにトラブルなく走行できるかを刁E��・予防整備する点椁E
                    ],
                    [
                        'icon' => '🔍',
                        'title' => '主な点検頁E���E�E6、E7頁E���E�E,
                        'desc' => "ブレーキの刁E��・渁E��・残量確認、サスペンションのガタ、�Eルト類�E緩みめE��化、排気漏れ、オイル漏れなどを�Eロが徹底チェチE��します、E
                    ],
                    [
                        'icon' => '💡',
                        'title' => '定期点検を受けめE大メリチE��',
                        'desc' => "① 出先での突然の敁E��めE��故を未然に防止\n② 消耗品の早期発見で封E��の大きな修琁E��を節約\n③ 定期点検記録簿が残り、封E��の車売却・下取り時の査定額がアチE�E�E�E
                    ]
                ],
                'summary' => '1年に1回�Eプロによる健康診断で、安忁E��適なカーライフを守りましょぁE��E,
                'action_btn' => [
                    'label' => '📅 12ヶ月定期点検�E予紁E�E相諁E,
                    'data' => 'action=ask_maintenance&type=periodic'
                ]
            ];
            break;

        case 'inspection':
            $articleData = [
                'badge' => '🔍 安忁E��E��ムーズ【⑩、E,
                'badge_color' => '#6366f1',
                'title' => '⑩ 車検�E基礎知識と賢ぁE��け方',
                'subtitle' => '満亁E��の1ヶ月前から受検可能�E�準備と流れ',
                'sections' => [
                    [
                        'icon' => '🗓',
                        'title' => '受検�Eベストタイミング',
                        'desc' => "車検満亁E��の、Eヶ月前】から受けられます、En1ヶ月前に受けても次回�E満亁E��は短縮されず、有効期限は丸、E年�E�新車時3年�E�引き継がれます、E
                    ],
                    [
                        'icon' => '💰',
                        'title' => '車検費用の冁E��と仕絁E��',
                        'desc' => "① 法定費用�E�国に納める重量税�E自賠責保険料�E印紙代�E�どこでも一律）\n② 車検基本料�E検査料�E予防整備費用�E�お店によって異なる部刁E��E
                    ],
                    [
                        'icon' => '📄',
                        'title' => 'ご来店時の忁E��書顁E,
                        'desc' => "・自動車検査証�E�車検証�E�\n・自賠責保険証明書\n・自動車税納税証明書\n・認印 / ホイールロチE��ナットアダプター�E�該当車！E
                    ]
                ],
                'summary' => 'アチE�Eファーレンでは事前無料お見積もりを実施中�E�不要な過剰整備�E一刁E��わず、わかりめE��くご説明いたします、E,
                'action_btn' => [
                    'label' => '📅 車検�E事前見積もり�E予紁E��諁E,
                    'data' => 'action=ask_maintenance&type=inspection'
                ]
            ];
            break;

        case 'battery_tire':
            $articleData = [
                'badge' => '⚠�E�Eトラブル予防【⑪、E,
                'badge_color' => '#ef4444',
                'title' => '⑪ バッチE��ー・タイヤ・日常点椁E,
                'subtitle' => '突然の路上トラブルを防ぐ日常ケア',
                'sections' => [
                    [
                        'icon' => '🔋',
                        'title' => 'バッチE��ーの寿命�E�E、E年�E�E,
                        'desc' => "最近�EバッチE��ーは直前まで允E��に動くため前�Eがわかりにくく、夏（エアコン多用�E�や冬�E�寒さで性能低下）に突然死します、En2年以上経過してぁE��らテスター診断をおすすめします、E
                    ],
                    [
                        'icon' => '🛞',
                        'title' => 'タイヤの交換サイン',
                        'desc' => "・残り溁E.6mm以下（スリチE�Eサイン露出�E�車検不適合！E��天スリチE�E危険�E�\n・製造から4、E年経過�E�ゴムが硬化しひび割れ発生）\n・偏摩耗（片側だけ減る�E�E
                    ],
                    [
                        'icon' => '❁E��E,
                        'title' => 'エアコンの冷え�Eニオイ',
                        'desc' => "エアコンフィルターは1年また�E1万kmごとの交換が目安、En冷えが悪ぁE��合�Eエアコンガスのクリーニング・補�Eで驚くほど復活します、E
                    ]
                ],
                'summary' => '少しでも「いつもと違う音めE��動」を感じたら、放置せずお気軽にご相諁E��ださい�E�E,
                'action_btn' => [
                    'label' => '🛠�E�E来店�E点検相諁E��ォームを開ぁE,
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ];
            break;

        case 'brake_care':
            $articleData = [
                'badge' => '🛑 安�Eの要�Eブレーキ【⑫、E,
                'badge_color' => '#e11d48',
                'title' => '⑫ ブレーキの寿命と重要チェチE��',
                'subtitle' => '命を守る最重要パーチE��キーキー音は見送E��な',
                'sections' => [
                    [
                        'icon' => '📏',
                        'title' => 'ブレーキパッド�E残厚�E�Emmで即交換！E,
                        'desc' => "新品紁E0mmから摩耗し、残厚3mm以下�E危険水域です、En限界を趁E��るとチE��スクローターを削ってしまぁE��E��額な部品交換が忁E��になります、E
                    ],
                    [
                        'icon' => '🔊',
                        'title' => 'ブレーキ鳴き（キーキー音�E��Eサイン',
                        'desc' => "ブレーキを踏んだ時に金属音が鳴る�Eは、パチE��摩耗を知らせるセンサー�E�ウェアインジケーター�E�が接触してぁE��音です。早急に点検を受けましょぁE��E
                    ],
                    [
                        'icon' => '💧',
                        'title' => 'ブレーキフルード！E年毎交換！E,
                        'desc' => "ブレーキオイルは空気中の水刁E��吸収して劣化します、En劣化すると下り坂などでオイルが沸騰しブレーキが利かなくなる「�Eーパ�EロチE��現象」�E原因になります、E
                    ]
                ],
                'summary' => 'アチE�Eファーレンではブレーキの残量測定�Eフルード点検を迁E��に実施ぁE��します！E,
                'action_btn' => [
                    'label' => '🛠�E�Eブレーキ点検を予紁E�E相諁E,
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ];
            break;

        case 'aircon_care':
            $articleData = [
                'badge' => '❁E��E快適ドライブ【⑬、E,
                'badge_color' => '#0ea5e9',
                'title' => '⑬ カーエアコンの効き！E��臭ケア',
                'subtitle' => '夏場の冷え不良・カビ�EをスチE��リ解決�E�E,
                'sections' => [
                    [
                        'icon' => '🧽',
                        'title' => 'エアコンフィルター�E�年1回交換！E,
                        'desc' => "ホコリ・花粉�E排ガスをキャチE��するフィルターです、En目詰まりすると風量が弱くなり、湿気でカビや嫌なニオイが発生します、E
                    ],
                    [
                        'icon' => '💧',
                        'title' => 'エバ�Eレーターの冁E��洗流E,
                        'desc' => "エアコン冁E��の冷却ユニット（エバ�Eレーター�E��E結露でカビ�E温床になりがちです、En専用ケミカルでの高圧洗流E���Eで新車�Eような爽めE��な風が�Eります、E
                    ],
                    [
                        'icon' => '❁E��E,
                        'title' => 'エアコンガスのクリーニング・補�E',
                        'desc' => "配管の継ぎ目などからガスは毎年微量ずつ抜けます、Enガス圧の真空引き補�EとコンプレチE��ーオイル添加剤で冷却性能が驚くほどUPします、E
                    ]
                ],
                'summary' => '「�Eえが悪ぁE��「カビ�EぁE��と感じたら、本格皁E��夏�E冬の前にメンチE��ンスをおすすめします！E,
                'action_btn' => [
                    'label' => '🛠�E�Eエアコン点検�E相諁E��する',
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ];
            break;

        case 'car_wash_care':
            $articleData = [
                'badge' => '🧼 愛車ケア�E�E��観【⑭、E,
                'badge_color' => '#06b6d4',
                'title' => '⑭ 洗車！E�EチE��コーチE��ング衁E,
                'subtitle' => '愛車�E輝きを長く保つプロのお手入れ況E,
                'sections' => [
                    [
                        'icon' => '☀�E�E,
                        'title' => '炎天下�E直封E��光での洗車�ENG',
                        'desc' => "日差しで水滴がレンズの役割を果たし塗裁E��痛める「ウォータースポット」や、水道水のミネラル刁E��焼き付く「イオンチE�EジチE��」�E原因になります、En曁E��の日めE��夕�E涼しい時間帯が�Eストです、E
                    ],
                    [
                        'icon' => '🧽',
                        'title' => '洗車キズを防ぐ洗い方のコチE,
                        'desc' => "① まずたっぷり�E水で砂�Eホコリを上から洗い流す\n② カーシャンプ�Eをしっかり泡立てて「泡のクチE��ョン」で優しく洗う\n③ タイヤ・下回り�Eボディとスポンジを�Eける"
                    ],
                    [
                        'icon' => '✨',
                        'title' => 'ガラス系コーチE��ングのメリチE��',
                        'desc' => "塗裁E��面に硬ぁE��膜を形成し、紫外線や酸性雨、E��フンによる劣化を防ぎます、En水洗いで汚れがスルチE��落ちるため日頁E�Eお手入れが格段に楽になります、E
                    ]
                ],
                'summary' => 'アチE�Eファーレンでは納車時のプロコーチE��ング施工めE�EチE��ケアのご相諁E��承っております！E,
                'action_btn' => [
                    'label' => '🛠�E�EコーチE��ング・洗車相諁E��する',
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ];
            break;

        case 'winter_driving':
            $articleData = [
                'badge' => '❁E��E冬道�E降雪対策【⑮、E,
                'badge_color' => '#0284c7',
                'title' => '⑮ 雪道運転と冬タイヤの極愁E,
                'subtitle' => '降雪地域�E安忁E��ーライフ！�E支度の鉁E��',
                'sections' => [
                    [
                        'icon' => '🛞',
                        'title' => 'スタチE��レスタイヤの寿命基溁E,
                        'desc' => "溝�E深さが新品時の50%になると現れる【�EラチE��ホ�Eム】が露出すると冬用タイヤとしては使用不可になります、Enまた製造から3、Eシーズンでゴムが硬化し氷上ブレーキ性能が低下します、E
                    ],
                    [
                        'icon' => '🛡�E�E,
                        'title' => '下回り�E防錁E��ーチE��ング�E�塩害対策！E,
                        'desc' => "道路に撒かれる融雪剤�E�塩化カルシウム�E��E愛車�E下回りを急速にサビさせます、En冬前�E下回り高圧洗流E��防錁E��ンダーコート塗裁E��愛車を守ります、E
                    ],
                    [
                        'icon' => '💧',
                        'title' => '寒�E地用ウォチE��ャー液とワイパ�E',
                        'desc' => "通常のウォチE��ャー液は寒さで凍結しタンク破損�E原因になります、En冬用�E�原液-30℁E��応）への入れ替えと、凍りつかなぁE��ノ�Eワイパ�Eの裁E��が安忁E��す、E
                    ]
                ],
                'summary' => 'アチE�Eファーレンでは冬タイヤの履き替え�E下回り防錁E��検も随時承っております！E,
                'action_btn' => [
                    'label' => '🛠�E�Eタイヤ交換�E冬点検を相諁E,
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ];
            break;

        case 'warning_lights':
            $articleData = [
                'badge' => '🚨 緊急・トラブル診断【⑯、E,
                'badge_color' => '#dc2626',
                'title' => '⑯ 警告�Eの意味と緊急時�E対処況E,
                'subtitle' => '色でわかる危険度と初期対応�Eニュアル',
                'sections' => [
                    [
                        'icon' => '🔴',
                        'title' => '赤色ランプ＝【直ちに安�Eな場所へ停車、E,
                        'desc' => "・油圧警告�E�E�オイル不足・油圧低下）：エンジン破損�E危険\n・水温警告�E�E�オーバ�Eヒ�Eト）：直ちに停車しエンジン冷却\n・ブレーキ警告�E�E�フルード漏れ・残量ゼロ�E�：ブレーキ不�Eの恐れ\n・允E��警告�E�E�オルタネ�Eター敁E���E�：バチE��リー走行となり近、E��止"
                    ],
                    [
                        'icon' => '🟡',
                        'title' => '黁E��/オレンジ色ランプ＝【早めに整備工場へ、E,
                        'desc' => "・エンジン警告�E�E�センサー系・排気系の異常�E�\n・ABS警告�E�E�安�E裁E��の不作動�E�\n・空気圧警告�E�E�パンクの疑い�E�E
                    ],
                    [
                        'icon' => '🔊',
                        'title' => '走行中の異音チェチE��',
                        'desc' => "・ブレーキ時�Eキーキー音�E�パチE��摩耗サイン�E�\n・段差でのコトコト音�E�サスペンションブッシュ摩耗）\n・加速時のゴー音�E�ハブ�Eアリング寿命�E�E
                    ]
                ],
                'summary' => '警告�Eが点灯したり普段と違う異音を感じたら、無琁E��走行を続けずすぐにご連絡ください�E�E,
                'action_btn' => [
                    'label' => '🛠�E�E異音・不�E合�E点検を相諁E,
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ];
            break;

        case 'rain_driving':
            $articleData = [
                'badge' => '🌧�E�E雨天・悪天候対策【⑰、E,
                'badge_color' => '#2563eb',
                'title' => '⑰ 雨の日の安�E運転と冠水対筁E,
                'subtitle' => 'スリチE�E防止�E�E��雨時�E水没トラブル回避衁E,
                'sections' => [
                    [
                        'icon' => '🌊',
                        'title' => '冠水道路の走行限界（ドア下部まで�E�E,
                        'desc' => "水深が�EフラーめE��アクリーナ�E吸気口�E�グリルの高さ�E�を趁E��ると、エンジン冁E��に水が吸ぁE��まれ「ウォーターハンマ�E現象」でエンジンが�E損�E廁E��になります、En水たまり�E深さが不�Eな場所は絶対に進入してはぁE��ません、E
                    ],
                    [
                        'icon' => '🛞',
                        'title' => 'ハイドロプレーニング現象の恐态E,
                        'desc' => "溝�E減ったタイヤで雨の高速道路を走ると、水膜�E上に車が浮ぁE��ハンドルめE��レーキが一刁E��かなくなります、En雨天時�E通常より時送E0、E0km速度を落とす�Eが鉄剁E��す、E
                    ],
                    [
                        'icon' => '👀',
                        'title' => '雨天のクリアな視界確俁E,
                        'desc' => "フロントガラスの油膜取り＋撥水コーチE��ング施工と、拭きムラのなぁE��イパ�Eゴムの定期交換（半年、E年毎）が豪雨時�E安�Eを左右します、E
                    ]
                ],
                'summary' => '雨天時�E視界不良めE��リチE�Eが気になる方は、ワイパ�E交換やガラス撥水施工をお気軽にご相諁E��ださい�E�E,
                'action_btn' => [
                    'label' => '🛠�E�Eワイパ�E・撥水コーチE��ング相諁E,
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ];
            break;

        case 'accident_guide':
            $articleData = [
                'badge' => '💥 緊急初動マニュアル【⑱、E,
                'badge_color' => '#b91c1c',
                'title' => '⑱ 事故・敁E��時�E緊急対応手頁E,
                'subtitle' => '焦らず行動�E�現場で絶対にめE��べぁEスチE��チE,
                'sections' => [
                    [
                        'icon' => '1�E�⃣',
                        'title' => '二次災害の防止と安�E確俁E,
                        'desc' => "ハザードランプを点灯し、車を発煙筒や停止表示器材（三角板�E�で後続車に知らせます、En高速道路では車�Eに残らず、ガードレールの外�Eなど安�Eな場所に避難してください、E
                    ],
                    [
                        'icon' => '2�E�⃣',
                        'title' => '負傷老E�E救護�E�E19番通報�E�E,
                        'desc' => "けが人がいる場合�E直ちに救急車を呼び、忁E��に応じて止血などの応急手当を行います、E
                    ],
                    [
                        'icon' => '3�E�⃣',
                        'title' => '警察への届�E�E�E10番通報・忁E��！E,
                        'desc' => "どんなに軽微な物損事故めE�E損事故でも、警察への届�Eは法律上�E義務です、En届�EがなぁE��「交通事故証明書」が発行されず、保険金が支払われません、E
                    ],
                    [
                        'icon' => '4�E�⃣',
                        'title' => '相手�E確認と保険会社・店�Eへの連絡',
                        'desc' => "相手�E氏名・電話番号・車�Eナンバ�E・保険会社をメモします、Enそ�E場で示諁E��口紁E��はせず、ご加入の自動車保険会社と当店へすぐにご連絡ください、E
                    ]
                ],
                'summary' => '丁E��一の事故めE��車�Eトラブル時�E、アチE�Eファーレンへもお気軽にご相諁E��ださい。レチE��ー手�EめE��琁E��積もりをサポ�Eトいたします、E,
                'action_btn' => [
                    'label' => '💬 店�EへLINEで連絡する',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'fuel_economy':
            $articleData = [
                'badge' => '⛽ 燁E���E�E��紁E��【⑲、E,
                'badge_color' => '#059669',
                'title' => '⑲ 燁E��アチE�E�E�E�E車�E節紁E��E,
                'subtitle' => 'ちめE��としたコチE��年間数丁E�Eの節紁E���E�E,
                'sections' => [
                    [
                        'icon' => '🟢',
                        'title' => 'ふんわりアクセル「eスタート、E,
                        'desc' => "発進時�E最初�E5秒で時送E0kmを目安にめE��くり踏み出すだけで、紁E0%燁E��が向上します、En車間距離に余裕を持った等速走行も効果的です、E
                    ],
                    [
                        'icon' => '💨',
                        'title' => 'タイヤ空気圧の定期点椁E,
                        'desc' => "空気圧は走行しなくても�E然に、Eヶ月で紁E、E0%】低下します、En空気圧が適正値より50kPa低いと燁E��が紁E、E%悪化します。月1回�E補�Eがおすすめです、E
                    ],
                    [
                        'icon' => '📦',
                        'title' => '不要な積載物の降軁E,
                        'desc' => "100kgの荷物を積�Eと燁E��が紁E%悪化します、Enトランクに乗せっぱなし�Eアウトドア用品や工具類�E整琁E��ましょぁE��E
                    ],
                    [
                        'icon' => '🛢�E�E,
                        'title' => '低粘度オイルの活用',
                        'desc' => "持E��粘度�E�EW-20めEW-16など�E��E省燃費オイルを使用することで、エンジン冁E��の抵抗を減らし燃費を維持できます、E
                    ]
                ],
                'summary' => '日頁E�E小さな意識と定期皁E��点検で、ガソリン代を賢く節紁E��ましょぁE��E,
                'action_btn' => [
                    'label' => '🚗 燁E��良好な在庫車両を見る',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'beginner_driver':
            $articleData = [
                'badge' => '🔰 安忁E��ライブ【⑳、E,
                'badge_color' => '#16a34a',
                'title' => '⑳ 初忁E��E�Eペ�Eパ�Eドライバ�E安忁E��E,
                'subtitle' => '運転の不安を解消する基本チE��ニック',
                'sections' => [
                    [
                        'icon' => '📐',
                        'title' => '車幁E��覚�E掴み方',
                        'desc' => "運転席から見て「道路の白線がフロントガラスのどこを通るか」を目印に覚えると、左寁E��の感覚が簡単に掴めます、Enボンネット�E見�Eりが良ぁE��を選ぶのも�Eイントです、E
                    ],
                    [
                        'icon' => '�E�E�E,
                        'title' => 'バック駐車�EコチE,
                        'desc' => "駐車枠に対して紁E5度に車体を傾けてからバックを開始し、サイドミラーで隣の車�E角と自刁E�E後輪の位置関係を確認しながらめE��くり下がると一発で収まります、E
                    ],
                    [
                        'icon' => '👀',
                        'title' => '死角�E確認と車間距離',
                        'desc' => "ミラーだけでなく目視での死角確認が事故防止の鍵です、En車間距離は「前の車が通過した地点を�E刁E��2秒後に通過する」間隔を目安に保ちましょぁE��E
                    ]
                ],
                'summary' => '見�Eり�E良ぁE��ンパクトカーめE��チE��カメラ付きの軽自動車など、E��転しやすいお車を多数ご用意しております！E,
                'action_btn' => [
                    'label' => '🚘 運転しやすい軽・コンパクトを見る',
                    'data' => 'action=search_kei'
                ]
            ];
            break;

        case 'car_accessories':
            $articleData = [
                'badge' => '🔌 便利アイチE��・裁E��【㉑、E,
                'badge_color' => '#7c3aed',
                'title' => '㉁Eドラレコ・ETC・LED便利知譁E,
                'subtitle' => '後付け・アチE�Eグレードで愛車がもっと快適に�E�E,
                'sections' => [
                    [
                        'icon' => '📷',
                        'title' => 'ドライブレコーダー�E�前征Eカメラ忁E��時代�E�E,
                        'desc' => "あおり運転めE��突対策に「前方�E�後方録画」が今や常識です、En夜間も鮮明に映るSTARVIS�E�高感度センサー�E�搭載モチE��めE��車監視機�E付きがおすすめです、E
                    ],
                    [
                        'icon' => '🛣�E�E,
                        'title' => 'ETC2.0のメリチE��と活用況E,
                        'desc' => "圏央道などの高速料金割引（紁E割引）や、一時退出�E�道の駁E��用で高速を降りても料金据え置き）など、E��距離ドライブでお得な機�Eが満載です、E
                    ],
                    [
                        'icon' => '💡',
                        'title' => 'LEDヘッドライト化の注意点',
                        'desc' => "暗いハロゲンランプから高輝度LEDへ交換すると夜間の視認性が劇皁E��向上します、En車検対応�EカチE��ライン�E��E光性能�E�がしっかり出る高品質バルブを選ぶのが鉄剁E��す、E
                    ]
                ],
                'summary' => 'アチE�Eファーレンでは持ち込みドラレコやETC・ナビ・LEDの取り付け・配線加工も�Eロが丁寧に行います！E,
                'action_btn' => [
                    'label' => '🛠�E�Eパ�EチE��付�Eカスタム相諁E,
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ];
            break;

        case 'car_appraisal':
            $articleData = [
                'badge' => '💴 愛車売却�E�E��定UP【㉒、E,
                'badge_color' => '#d97706',
                'title' => '㉁E愛車を高く売る�E査定UP衁E,
                'subtitle' => '手放す前に知っておきたい高価買取�E4大鉁E��',
                'sections' => [
                    [
                        'icon' => '🧼',
                        'title' => '査定前の洗車と車�E消�E・渁E��',
                        'desc' => "第一印象は極めて重要です。タバコ・ペット�E芳香剤の臭ぁE��抜き、洗車と室冁E��E��をしておくだけで「大刁E��乗られてきた車」として査定士の評価が上がります、E
                    ],
                    [
                        'icon' => '📦',
                        'title' => '純正パ�EチE�E取扱説明書・スペアキーの保管',
                        'desc' => "社外アルミやナビに変えてぁE��場合も、純正品を揁E��ておくとプラス査定に、En整備手帳�E�記録簿�E�とスペアキーの有無で数丁E�Eの差がつきます、E
                    ],
                    [
                        'icon' => '🛠�E�E,
                        'title' => '小さなキズは無琁E��直さなぁE,
                        'desc' => "自刁E��タチE��ペン補修をするとかえって目立ち減額になることがあります、Enプロの板金費用以上�E査定アチE�Eは見込めなぁE��め、そのまま査定に出す�Eが鉄剁E��す、E
                    ],
                    [
                        'icon' => '🗓',
                        'title' => 'フルモチE��チェンジ前�E車検満亁E��に動く',
                        'desc' => "新型が出ると相場が下落します。車検を通す前�E1、Eヶ月前に査定比輁E��る�Eが一番得策です、E
                    ]
                ],
                'summary' => 'アチE�Eファーレンでは愛車�E無料�E張査定�E高価下取りをぁE��でも承っております！E,
                'action_btn' => [
                    'label' => '💬 愛車�E無料査定�E相諁E��する',
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ];
            break;

        case 'tire_rotation':
            $articleData = [
                'badge' => '🛞 タイヤ長持ち・安�E【㉓、E,
                'badge_color' => '#0284c7',
                'title' => '㉁EタイヤローチE�Eションと偏摩老E,
                'subtitle' => '寿命めE.5倍に延ばす位置交換�E基本',
                'sections' => [
                    [
                        'icon' => '🔄',
                        'title' => '前後�Eタイヤ摩耗差の正佁E,
                        'desc' => "前輪駁E���E�EF車）�Eハンドル操作と駁E��を同時に拁E��ため、前輪が後輪の2、E倍�E速さで摩耗します、En定期皁E��前後を入れ替えなぁE��前輪だけが早期にチE��チE��になってしまぁE��す、E
                    ],
                    [
                        'icon' => '⏱',
                        'title' => '交換目安（走衁E,000kmまた�E半年�E�E,
                        'desc' => "オイル交換や季節ごとのタイヤ履き替え（スタチE��レス↔夏タイヤ�E��Eタイミングで前後を入れ替えるのが最も効玁E��です、E
                    ],
                    [
                        'icon' => '📐',
                        'title' => '偏摩耗（片減り�E��E早期発要E,
                        'desc' => "冁E�EめE���Eだけが極端に削れてぁE��場合、空気圧不足めE��回りのアライメント狂ぁE��原因です。走行中の直進安定性にも影響します、E
                    ]
                ],
                'summary' => 'タイヤの無料残溝チェチE��めE��ーチE�Eション作業もお気軽にご用命ください�E�E,
                'action_btn' => [
                    'label' => '🛠�E�Eタイヤ点検�E交換を相諁E,
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ];
            break;

        case 'disaster_car_stay':
            $articleData = [
                'badge' => '🏕�E�E防災・緊急車中泊【㉔、E,
                'badge_color' => '#dc2626',
                'title' => '㉁E車�E防災�E�E��害時車中泊�Eニュアル',
                'subtitle' => '豪雪立ち往生や霁E��時に命を守る備え',
                'sections' => [
                    [
                        'icon' => '☠�E�E,
                        'title' => 'マフラー埋没による一酸化炭素中毒防止',
                        'desc' => "大雪で立ち往生した際、排気口�E��Eフラー�E�が雪で埋まると排ガスが車�Eに送E��し数十�Eで命の危険に�E�\nエンジンをかける時�Eマフラー周囲の除雪を欠かさず、E��下�Eの窓を数センチE��けておきます、E
                    ],
                    [
                        'icon' => '🔨',
                        'title' => '緊急脱出用ガラス割りハンマ�Eの車輁E,
                        'desc' => "冠水めE��故でドアが開かなくなった際、水圧がかかった窓�E手や足では絶対に割れません。手の届く運転席周りに専用ハンマ�Eを備えましょぁE��E
                    ],
                    [
                        'icon' => '🎒',
                        'title' => '車載すべき防災7つ道�E',
                        'desc' => "①毛币E�E防寒アルミシーチE②モバイルバッチE��ー ③非常食�E飲料水 ④携帯トイレ ⑤スコチE�E・解氷スプレー ⑥牽引ローチE⑦長靴・手袋、E
                    ]
                ],
                'summary' => '新潟�E厳しい冬めE��然の災害に備え、お車に防災グチE��を常備しておきましょぁE��E,
                'action_btn' => [
                    'label' => '🚗 在庫車両をチェチE��する',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'headlight_yellowing':
            $articleData = [
                'badge' => '✨ 美観�E�E��間視界【㉕、E,
                'badge_color' => '#7c3aed',
                'title' => '㉁Eヘッドライト黁E�Eみ除去と予防',
                'subtitle' => '見た目の若返り�E�E��検�E光量不足対筁E,
                'sections' => [
                    [
                        'icon' => '☀�E�E,
                        'title' => '黁E�Eみ・くすみの原因�E��Eリカーボネート�E紫外線劣化！E,
                        'desc' => "現代のヘッドライト�E樹脂製のため、日光�E紫外線と経年熱で表面のクリア塗裁E��劣化し黁E���E白濁します、E
                    ],
                    [
                        'icon' => '⚠�E�E,
                        'title' => '放置すると車検落ち�E�E��間危険�E�E,
                        'desc' => "黁E�Eみが進行すると光が拡散し、車検基準�E「すれ違ぁE��前�E灯�E�ロービ�Eム光度�E�」を満たせず車検に不合格になる事例が急増してぁE��す、E
                    ],
                    [
                        'icon' => '✨',
                        'title' => '研磨クリーニング�E�E��用コーチE��ング',
                        'desc' => "黁E�Eんだ表層を耐水研磨で削り落とし、ガラス系また�Eウレタンクリアコートで再保護することで、新車時の透�E感と照封E�E量が蘁E��ます、E
                    ]
                ],
                'summary' => 'アチE�Eファーレンではヘッドライト�Eクリーニング�E�E�EロコーチE��ングも施工可能です！E,
                'action_btn' => [
                    'label' => '🛠�E�Eヘッドライト磨きを相諁E,
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ];
            break;

        case 'smart_key_battery':
            $articleData = [
                'badge' => '🔑 トラブル緊急脱出【㉖、E,
                'badge_color' => '#e11d48',
                'title' => '㉁Eスマ�Eトキー電池刁E��時�E始動況E,
                'subtitle' => '鍵が開かなぁE�EかからなぁE��の完�E手頁E,
                'sections' => [
                    [
                        'icon' => '🗝�E�E,
                        'title' => '冁E��メカニカルキーでドアを解錠',
                        'desc' => "スマ�Eトキー側面の解除ボタンをスライドさせると、物琁E��ーが引き出せます。運転席ドアの鍵穴に差し込んで回せばドアが開きます、E
                    ],
                    [
                        'icon' => '🔘',
                        'title' => 'スタート�Eタンにスマ�EトキーをタチE���E�E,
                        'desc' => "ブレーキペダルを踏みながら、スマ�Eトキーの「エンブレム面」をプッシュスタート�Eタンに直接寁E���E�タチE���E�させると「ピチE��と音が鳴り、そのままボタンを押せ�Eエンジンが始動します、E
                    ],
                    [
                        'icon' => '🔋',
                        'title' => '電池寿命�E�紁E、E年�E�と交換用電池�E�ER2032等！E,
                        'desc' => "スマ�Eトキーは常に電波を受信してぁE��ため1、E年で消耗します。�Eタン電池�E�主にCR2032やCR1632など�E��Eコンビニ等で購入でき、�E刁E��簡単に交換可能です、E
                    ]
                ],
                'summary' => 'スマ�Eトキーの電池交換も店頭で数十秒で対応いたします�Eでお気軽にどぁE���E�E,
                'action_btn' => [
                    'label' => '🛠�E�E愛車�E相諁E�E点検予紁E,
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ];
            break;

        case 'hybrid_battery_care':
            $articleData = [
                'badge' => '🔋 HV・EVの賢ぁE��り方【㉗、E,
                'badge_color' => '#059669',
                'title' => '㉁EハイブリチE��車�EバッチE��ー延命衁E,
                'subtitle' => '駁E��用バッチE��ー長持ち�E�E��機バチE��リーの盲点',
                'sections' => [
                    [
                        'icon' => '🌡�E�E,
                        'title' => '高温放置と急加速�E急放電の回避',
                        'desc' => "リチウムイオン/ニッケル水素バッチE��ーは熱に弱ぁE��す。炎天下での長時間駐車を避け、�E却ファンの吸気口�E�後部座席横�E�に荷物を置かなぁE��ぁE��しましょぁE��E
                    ],
                    [
                        'icon' => '⚡',
                        'title' => '見落としがちな「補機バチE��リー」�E寿命�E�E年�E�E,
                        'desc' => "ハイブリチE��車には走行用とは別に「シスチE��起動用の12V補機バチE��リー」が載ってぁE��す。これが上がると大容量バチE��リーが満タンでも車が起動できません�E�E
                    ],
                    [
                        'icon' => '📉',
                        'title' => '定期皁E��走行で完�E放電を防ぁE,
                        'desc' => "数ヶ月放置すると自然放電で駁E��用バッチE��ーの容量が低下します。月2、E回�Eエンジンをかけて30刁E��上走行させましょぁE��E
                    ]
                ],
                'summary' => 'アチE�Eファーレンでは良質なハイブリチE��・低燃費エコカーを多数取り揁E��ております！E,
                'action_btn' => [
                    'label' => '🚗 ハイブリチE��在庫車両を見る',
                    'data' => 'action=search_all'
                ]
            ];
            break;

        case 'daily_car_check':
        default:
            $articleData = [
                'badge' => '🔍 5刁E��ルフ点検【㉘、E,
                'badge_color' => '#2563eb',
                'title' => '㉁E日常点検「�E・た�Eは・と・ぁE�Eみ・ず、E,
                'subtitle' => 'プロ推奨�E�ドライブ前の簡単セルフチェチE��',
                'sections' => [
                    [
                        'icon' => '🛑',
                        'title' => '【�E】ブレーキ�E�E�EルチE,
                        'desc' => "ブレーキペダルの踏みごたえ（床まで沈み込まなぁE���E�と、エンジン始動時�Eキュルキュル異音がなぁE��、E
                    ],
                    [
                        'icon' => '🛞',
                        'title' => '【た】タイヤ',
                        'desc' => "空気圧の見た目�E�極端に潰れてぁE��ぁE���E�、溝�E残り深さ、亀裂や釘刺さりがなぁE��、E
                    ],
                    [
                        'icon' => '💡',
                        'title' => '【�E・とぁE��バチE��リー�E�E�E火顁E,
                        'desc' => "セルモーターの始動音、�EチE��ライト�Eブレーキランプ�Eウインカーの琁E�EれがなぁE��、E
                    ],
                    [
                        'icon' => '💧',
                        'title' => '【み・ず】オイル�E�みず）�E冷却水・ウォチE��ャー液',
                        'desc' => "エンジンオイル量、ラジエーター冷却水の量（リザーブタンク�E�、ウォチE��ャー液の残量をチェチE��、E
                    ]
                ],
                'summary' => 'お�Eかけ前�E無料安忁E��検も店頭でぁE��でも承っております！お気軽にお立ち寁E��ください、E,
                'action_btn' => [
                    'label' => '🛠�E�E店�Eで無料点検を受けめE,
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
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
                        'label' => '👥 こ�E豁E��識を友だちにシェア',
                        'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/share.html?topic=' . urlencode($topic)
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'secondary',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'postback',
                        'label' => '📚 豁E��識ガイド一覧へ戻めE,
                        'data' => 'action=show_knowledge_menu'
                    ]
                ]
            ]
        ]
    ];

    return [
        [
            'type' => 'flex',
            'altText' => "【{$articleData['title']}】カーライフお役立ちガイチE,
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
 * 車種・ボディタイプ選択メニュー�E�サイレント�Eタン式Flex カルーセル�E�生戁E
 * 現在の有効在庫�E�EarsチE�Eブル�E�から実在するボディタイプおよ�E人気車種を�E動集計し、E
 * 「該当台数�E�侁E (10台)�E�」バチE��付きでカルーセル化！E件の車種は自動非表示�E�E
 */
function generateTypeMenuMessages(PDO $db): array {
    // 1. 有効在庫の全チE�Eタを取征E
    $stmt = $db->query("SELECT id, title, displacement, drive_type FROM cars WHERE is_active = 1");
    $cars = $stmt->fetchAll();
    $totalStock = count($cars);

    if (empty($cars)) {
        return [
            [
                'type' => 'text',
                'text' => "現在、展示中の在庫車両を準備中です、En最新の入庫状況�Eお気軽にお問ぁE��わせください�E�E,
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }

    // 2. ボディタイプ定義
    $bodyTypeDefs = [
        [
            'name' => '軽自動軁E,
            'action' => 'search_kei',
            'param' => '',
            'check' => function($car) {
                $disp = $car['displacement'] ?? '';
                $title = $car['title'] ?? '';
                return ($disp === '660cc' || str_starts_with($disp, '66') || str_contains($title, '軽自動軁E));
            }
        ],
        [
            'name' => '�E��E�ﾊﾟｸ�E�E���E�',
            'action' => 'search_type',
            'param' => 'keyword=' . urlencode('コンパクチE),
            'check' => function($car) {
                $disp = $car['displacement'] ?? '';
                $title = $car['title'] ?? '';
                if ($disp === '660cc' || str_starts_with($disp, '66')) return false;
                $kws = ['コンパクチE, 'フィチE��', 'アクア', 'ヤリス', 'ノ�EチE, 'パッソ', 'スイフト', 'ヴィチE��', 'チE��オ', 'マ�EチE, 'ポロ', 'ゴルチE, 'ルーミ�E', 'ソリオ', 'タンク', 'FIT', 'AQUA', 'NOTE', 'SWIFT'];
                foreach ($kws as $kw) {
                    if (stripos($title, $kw) !== false) return true;
                }
                return false;
            }
        ],
        [
            'name' => '�E�ﾁE��ﾞﾝ･�E�ｺ�E�ﾁE,
            'action' => 'search_type',
            'param' => 'keyword=' . urlencode('ワゴン'),
            'check' => function($car) {
                $title = $car['title'] ?? '';
                $kws = ['ワゴン', 'セレチE, 'ヴォクシー', 'ノア', 'スチE��プワゴン', 'フリーチE, 'シエンタ', 'アルファーチE, 'ヴェルファイア', 'チE��カ', 'エスチE��チE, 'オチE��セイ', 'SERENA', 'VOXY', 'NOAH'];
                foreach ($kws as $kw) {
                    if (stripos($title, $kw) !== false) return true;
                }
                return false;
            }
        ],
        [
            'name' => 'SUV�E�4WD',
            'action' => 'search_type',
            'param' => 'keyword=' . urlencode('4WD'),
            'check' => function($car) {
                $drive = $car['drive_type'] ?? '';
                $title = $car['title'] ?? '';
                if (stripos($drive, '4WD') !== false || stripos($drive, '�E�Ｗ�E�') !== false || stripos($drive, '四駁E) !== false || stripos($drive, 'AWD') !== false || stripos($drive, '�E��E��E�') !== false) {
                    return true;
                }
                if (stripos($title, '4WD') !== false || stripos($title, '�E�Ｗ�E�') !== false || stripos($title, 'AWD') !== false || stripos($title, 'SUV') !== false || stripos($title, 'クロスオーバ�E') !== false || stripos($title, 'キャチE��チE��') !== false || stripos($title, 'XT5') !== false) {
                    return true;
                }
                $kws = ['ハスラー', 'ジムニ�E', 'ヴェゼル', 'ヤリスクロス', 'ライズ', 'ロチE��ー', 'エクストレイル', 'フォレスター', 'CX-', 'C-HR'];
                foreach ($kws as $kw) {
                    if (stripos($title, $kw) !== false) return true;
                }
                return false;
            }
        ]
    ];

    // 3. 人気車種モチE��マスター定義
    $modelDefs = [
        ['name' => 'N-BOX', 'keyword' => 'N-BOX', 'match' => ['N-BOX', '�E��E�Ｂ�E��E�', 'NBOX', 'エヌ�EチE��ス']],
        ['name' => '�E��E�ﾁE, 'keyword' => 'タンチE, 'match' => ['タンチE, '�E��E�ﾁE, 'TANTO']],
        ['name' => '�E��E�ﾟｰ�E��E�', 'keyword' => 'スペ�Eシア', 'match' => ['スペ�Eシア', '�E��E�ﾟｰ�E��E�', 'SPACIA']],
        ['name' => '�E�ｺ�E�ﾝR', 'keyword' => 'ワゴンR', 'match' => ['ワゴンR', 'ワゴン�E�', '�E�ｺ�E�ﾝR', 'WAGON R', 'スチE��ングレー']],
        ['name' => '�E�E��ｲ�E��E�E�E�ｰ�E��E�', 'keyword' => 'チE��ズ', 'match' => ['チE��ズ', '�E�E��ｲ�E��E�E, 'ルークス', '�E�ｰ�E��E�', 'DAYZ', 'ROOX']],
        ['name' => '�E�ｽ�E�ｰ', 'keyword' => 'ハスラー', 'match' => ['ハスラー', '�E�ｽ�E�ｰ', 'HUSTLER']],
        ['name' => '�E�ｰ�E��E�E, 'keyword' => 'ムーヴ', 'match' => ['ムーヴ', '�E�ｰ�E��E�E, 'キャンバス', 'MOVE']],
        ['name' => '�E��E�ﾁE, 'keyword' => 'アルチE, 'match' => ['アルチE, '�E��E�ﾁE, 'ALTO', 'ラパン']],
        ['name' => '�E�ﾁE�E��E��E�', 'keyword' => 'ミラ', 'match' => ['ミライース', 'ミラ', '�E�ﾁE, 'MIRA']],
        ['name' => 'C-HR', 'keyword' => 'C-HR', 'match' => ['C-HR', 'CHR']],
        ['name' => '�E�ﾟﾘｳ�E�', 'keyword' => 'プリウス', 'match' => ['プリウス', '�E�ﾟﾘｳ�E�', 'PRIUS']],
        ['name' => '�E��E��E�', 'keyword' => 'アクア', 'match' => ['アクア', '�E��E��E�', 'AQUA']],
        ['name' => '�E�ｰ�E�E, 'keyword' => 'ノ�EチE, 'match' => ['ノ�EチE, '�E�ｰ�E�E, 'NOTE']],
        ['name' => '�E�ｨ�E��E�E, 'keyword' => 'フィチE��', 'match' => ['フィチE��', '�E�ｨ�E��E�E, 'FIT']],
        ['name' => '輸入軁E欧州軁E, 'keyword' => '輸入軁E, 'match' => ['ベンチE, 'BMW', 'フォルクスワーゲン', 'アウチE��', 'キャチE��チE��', 'MINI', 'ボルチE, 'Bクラス', 'B180', 'CTS']]
    ];

    $activeBubbles = [];

    // --- カード①: 実在するボディタイチE---
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
                        'text' => '�E�ﾞﾁE��ｨ�E��E��E�ﾟで探ぁE,
                        'weight' => 'bold',
                        'size' => 'md',
                        'color' => '#1e293b'
                    ],
                    [
                        'type' => 'text',
                        'text' => '在庫に実在するタイプから選べまぁE,
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

    // --- カード②: 実在する人気車種モチE�� ---
    $modelButtons = [];
    foreach ($modelDefs as $mDef) {
        $count = 0;
        foreach ($cars as $car) {
            $rawHaystack = ($car['title'] ?? '') . ' ' . ($car['displacement'] ?? '');
            // 「ミラー」「�Eレミアム」など部刁E��致誤爁E��防ぁE
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
            $cardTitle = '人気車種で探ぁE . (count($modelChunks) > 1 ? " (" . ($mIdx + 1) . ")" : "");
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
                            'text' => '在庫に実在するモチE��から選べまぁE,
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
                'text' => "現在、展示中の在庫車両を準備中です、E,
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }

    return [
        [
            'type' => 'flex',
            'altText' => '車種・ボディタイプから探ぁE,
            'contents' => [
                'type' => 'carousel',
                'contents' => array_slice($activeBubbles, 0, 10)
            ],
            'quickReply' => getQuickReplyItems()
        ]
    ];
}

/**
 * 裁E��・仕様選択メニュー送信
 */
function sendEquipmentMenuMessage(PDO $db, string $replyToken) {
    $messages = generateEquipmentMenuMessages($db);
    sendReplyMessage($replyToken, $messages);
}

/**
 * 裁E��・仕様選択メニュー�E�サイレント�Eタン式Flex カルーセル�E�生戁E
 * 現在の有効在庫�E�EarsチE�Eブル�E�からチェチE��がある裁E��だけを自動集計し、E
 * 「該当台数�E�侁E (10台)�E�」バチE��付きでカルーセル化（絵斁E��なしでスチE��リ表示�E�E
 */
function generateEquipmentMenuMessages(PDO $db): array {
    // 1. 有効在庫の全チE�Eタを取征E
    $stmt = $db->query("SELECT id, title, equipments, drive_type, repair_history, distance, distance_num, shaken FROM cars WHERE is_active = 1");
    $cars = $stmt->fetchAll();
    $totalStock = count($cars);

    if (empty($cars)) {
        return [
            [
                'type' => 'text',
                'text' => "現在、展示中の在庫車両を準備中です、En最新の入庫状況�Eお気軽にお問ぁE��わせください�E�E,
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }

    // 2. 裁E��マスター定義 (絵斁E��なし�E短縮チE��スチE
    $categoryDefs = [
        'navi_camera' => [
            'title' => '�E�E��ﾞ･�E��E�ﾗ･快適裁E��',
            'items' => [
                ['name' => '�E��E��E�E��ﾁESD�E�E��ﾁE, 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('ナビ'), 'match' => ['ナビ', 'メモリーナビ', '�E��E�ナビ', 'チE��スプレイオーチE��オ']],
                ['name' => '地�E�E��ｼ�E�TV', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('TV'), 'match' => ['地チE��', 'フルセグ', 'ワンセグ', '�E��E�', 'TV', 'チE��チE]],
                ['name' => '�E�ﾞｯ�E��E��E�ﾁE, 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('バックカメラ'), 'match' => ['バックカメラ', 'アラウンドビュー', '全方位カメラ', 'カメラ']],
                ['name' => 'Bluetooth', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('Bluetooth'), 'match' => ['Bluetooth', '�E��E�ｕａE��ｏｏｔａE, 'ブルートゥース', 'カープレイ', 'carplay']],
                ['name' => 'ETC車載器', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('ETC'), 'match' => ['ETC', '�E��E��E�', 'ETC2.0']],
                ['name' => '�E�E��ﾗﾚｺ', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('ドラレコ'), 'match' => ['ドラレコ', 'ドライブレコーダー']],
            ]
        ],
        'comfort_exterior' => [
            'title' => '�E�E��ｱ�E��E��E��E�E��外裁E,
            'items' => [
                ['name' => '�E�ﾟﾜｰ�E��E�ｲ�E�E��E, 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('スライチE), 'match' => ['スライチE, '両側電勁E, 'パワースライチE]],
                ['name' => '�E��E�ｰ�E�E���E�', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('スマ�Eトキー'), 'match' => ['スマ�Eトキー', 'インチE��ジェントキー', 'プッシュスターチE, 'キーレス']],
                ['name' => '�E��E��E�E��ｰ�E��E�', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('シートヒーター'), 'match' => ['シートヒーター', '前席ヒ�Eター']],
                ['name' => 'LED�E�ｲ�E�E, 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('LED'), 'match' => ['LED', '�E��E��E�', 'HID', '�E��E��E�', 'オートライチE]],
                ['name' => '�E��E�ﾐﾎｲ�E��E�E, 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('アルチE), 'match' => ['アルチE, 'アルミ�Eイール', '�E�５インチアルチE, '�E�４インチアルチE]],
                ['name' => '�E�ｻ�E�ｰ�E��E��E�E, 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('レザー'), 'match' => ['本革', 'レザー', 'ハ�Eフレザー', '革調']],
            ]
        ],
        'safety_drive' => [
            'title' => '安�E�E�駁E���E�状慁E,
            'items' => [
                ['name' => '自動ﾌﾞﾚｰ�E�', 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('軽渁E), 'match' => ['軽渁E, '安�E', 'ブレーキ', 'センシング', 'スマ�EトアシスチE, 'セーフティ', 'プロパイロチE��']],
                ['name' => '4WD/四駁E, 'action' => 'search_equip', 'param' => 'keyword=' . urlencode('4WD'), 'match' => ['4WD', '�E�Ｗ�E�', '四駁E, '4wd']],
                ['name' => '修復歴なぁE, 'action' => 'search_repair_none', 'param' => '', 'match' => ['_repair_none_']],
                ['name' => '未使用�E�低走衁E, 'action' => 'search_low_mileage', 'param' => '', 'match' => ['_low_mileage_']],
                ['name' => '�E��E��E�ﾞ軁E, 'action' => 'search_type', 'param' => 'keyword=' . urlencode('ターチE), 'match' => ['ターチE, 'TB', 'turbo']],
            ]
        ]
    ];

    // 3. 吁E��イチE��の該当台数を集訁E
    $activeBubbles = [];

    foreach ($categoryDefs as $catKey => $cat) {
        $buttons = [];

        foreach ($cat['items'] as $item) {
            $count = 0;

            foreach ($cars as $car) {
                $isMatch = false;
                $haystack = ($car['title'] ?? '') . ' ' . ($car['equipments'] ?? '') . ' ' . ($car['drive_type'] ?? '');

                if (in_array('_repair_none_', $item['match'])) {
                    if (empty($car['repair_history']) || $car['repair_history'] === 'なぁE || $car['repair_history'] === '-') {
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

            // 1台以上ある場合�Eみボタンを生成！E
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

        // ボタンぁEつ以上あるカチE��リのみバブルカードとして追加
        if (!empty($buttons)) {
            // 1バブルあたり最大4ボタンずつ刁E��
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
                                'text' => '在庫に実在する裁E��から選べまぁE,
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
                'text' => "現在、該当する裁E��条件の在庫を更新中です、E,
                'quickReply' => getQuickReplyItems()
            ]
        ];
    }

    return [
        [
            'type' => 'flex',
            'altText' => '基本仕様�E実在裁E��から探ぁE,
            'contents' => [
                'type' => 'carousel',
                'contents' => array_slice($activeBubbles, 0, 10) // LINE上限最大10极E
            ],
            'quickReply' => getQuickReplyItems()
        ]
    ];
}

/**
 * クイチE��リプライボタン一覧�E�完�EサイレンチEostback方式！E
 */
function getQuickReplyItems(): array {
    return [
        'items' => [
            [
                'type' => 'action',
                'action' => [
                    'type' => 'uri',
                    'label' => '🛠�E�E点検受仁E,
                    'uri' => 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '📚 豁E��識ガイチE,
                    'data' => 'action=show_knowledge_menu'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '🚗 在庫全台',
                    'data' => 'action=search_all'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '💰 価格で探ぁE,
                    'data' => 'action=show_price_menu'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '🚙 車種で探ぁE,
                    'data' => 'action=show_type_menu'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '⚙︁E裁E��で探ぁE,
                    'data' => 'action=show_equipment_menu'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '🛣�E�E距離で探ぁE,
                    'data' => 'action=show_distance_menu'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '🚘 軽自動軁E,
                    'data' => 'action=search_kei'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '💎 50丁E��丁E,
                    'data' => 'action=search_price&max_price=50'
                ]
            ]
        ]
    ];
}

/**
 * LINE Messaging API 返信送信
 */
function sendReplyMessage(string $replyToken, array $messages) {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        writeDebugLog("返信スキチE�E: LINE_CHANNEL_ACCESS_TOKEN が未設定でぁE);
        return;
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
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("LINE API返信結果", [
        'httpCode' => $httpCode,
        'response' => $res,
        'curlError' => $curlErr
    ]);
}

