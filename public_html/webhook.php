<?php
/**
 * LINE Messaging API Webhook ハンドラー
 * LINE公式アカウントからのメッセージを受信し、データベースの車両情報をFlex Messageで返信します。
 */

require_once __DIR__ . '/config.php';

// --- ブラウザ等からの直接GETアクセスの場合は診断画面を表示 ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    
    // DB状態確認
    $dbStatus = 'エラー';
    $carCount = 0;
    $dbPath = DB_PATH;
    try {
        $db = getDbConnection();
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM cars WHERE is_active = 1");
        $carCount = (int)$stmt->fetch()['cnt'];
        $dbStatus = "正常稼働中 (有効在庫: {$carCount}台)";
    } catch (Exception $e) {
        $dbStatus = "接続失敗: " . htmlspecialchars($e->getMessage());
    }

    $tokenConfigured = (LINE_CHANNEL_ACCESS_TOKEN !== 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') ? '<span style="color:green;">設定済み</span>' : '<span style="color:red;">未設定 (config.phpに貼り付けてください)</span>';
    $secretConfigured = (LINE_CHANNEL_SECRET !== 'YOUR_CHANNEL_SECRET_HERE') ? '<span style="color:green;">設定済み</span>' : '<span style="color:red;">未設定</span>';
    
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
        <h2>🚗 LINE Webhook 稼働ステータス</h2>
        <table>
            <tr><th>項目</th><th>状態</th></tr>
            <tr><td>Webhook エンドポイント</td><td>正常応答中 (200 OK)</td></tr>
            <tr><td>チャネルアクセストークン</td><td>{$tokenConfigured}</td></tr>
            <tr><td>チャネルシークレット</td><td>{$secretConfigured}</td></tr>
            <tr><td>DBパス</td><td><code>{$dbPath}</code></td></tr>
            <tr><td>データベース状態</td><td><strong>{$dbStatus}</strong></td></tr>
        </table>
        <h3>📋 最近のログ (最新35件)</h3>
        <div class="log-box">
HTML;
    $logFile = __DIR__ . '/webhook_debug.log';
    if (file_exists($logFile)) {
        $lines = array_slice(file($logFile), -35);
        echo htmlspecialchars(implode('', $lines));
    } else {
        echo "ログはまだありません。LINEでメッセージを送信すると記録されます。";
    }
    echo <<<HTML
        </div>
    </div>
    </body></html>
HTML;
    exit;
}

// --- Webhookリクエスト受信時のエントリポイント実行 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'webhook.php' || basename($_SERVER['PHP_SELF'] ?? '') === 'webhook.php')) {
    // 生のリクエストボディを取得
    $rawInput = file_get_contents('php://input');
    writeDebugLog("Webhook受信", ['bytes' => strlen($rawInput)]);

    // 署名検証 (Channel Secretが設定されている場合)
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
        writeDebugLog("イベントなし (検証Pingなど)");
        http_response_code(200);
        echo 'OK (No events)';
        exit;
    }

    try {
        $db = getDbConnection();
    } catch (Exception $e) {
        writeDebugLog("DB接続例外: " . $e->getMessage());
        http_response_code(500);
        exit;
    }

    foreach ($data['events'] as $event) {
        $replyToken = $event['replyToken'] ?? null;
        if (!$replyToken) continue;

        $userId = $event['source']['userId'] ?? '';
        $type = $event['type'];
        writeDebugLog("イベント処理開始", ['type' => $type, 'userId' => $userId]);

        if ($type === 'message' && $event['message']['type'] === 'text') {
            $userText = trim($event['message']['text']);
            writeDebugLog("テキスト受信", ['text' => $userText, 'userId' => $userId]);
            handleTextMessage($db, $replyToken, $userText, $userId);
        } elseif ($type === 'postback') {
            $postbackData = $event['postback']['data'] ?? '';
            writeDebugLog("ポストバック受信", ['data' => $postbackData, 'userId' => $userId]);
            handlePostback($db, $replyToken, $postbackData, $userId);
        } elseif ($type === 'follow') {
            writeDebugLog("友だち追加イベント", ['userId' => $userId]);
            handleFollow($replyToken);
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

        handleSubmitMaintenanceBooking($replyToken, $bookingType, $carModel, $prefTime, $userId);
        return;
    }

    // 2. オイル交換・定期点検・車検・メンテナンス関連のキーワード判定 (在庫検索の誤爆防止)
    if (preg_match('/(オイル|車検|点検|12ヶ月|法定|メンテ|予約|相談|パスポート)/u', $text)) {
        // 顧客の登録愛車を取得
        $carModel = '愛車';
        $oilDate = '近日中';
        $periodicDate = '近日中';
        $inspDate = '未定';
        if (!empty($userId)) {
            $stmt = $db->prepare("SELECT * FROM customers WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $cust = $stmt->fetch();
            if ($cust) {
                if (!empty($cust['car_model'])) $carModel = $cust['car_model'];
                if (!empty($cust['oil_next_date'])) $oilDate = $cust['oil_next_date'];
                if (!empty($cust['periodic_insp_next_date'])) $periodicDate = $cust['periodic_insp_next_date'];
                if (!empty($cust['inspection_next_date'])) $inspDate = $cust['inspection_next_date'];
            }
        }

        if (preg_match('/(点検|12ヶ月|法定)/u', $text)) {
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

    // 2. 特殊キーワードの判定
    if (in_array($text, ['在庫一覧', '車を探す', 'メニュー', '在庫', '車', '全台'])) {
        searchCarsAndReply($db, $replyToken, [], '現在の在庫車両一覧', $userId);
        return;
    }

    // 3. 価格帯キーワードの判定 (例: 50万以下, 100万円以下, 50万円)
    if (preg_match('/([0-9\.]+)\s*(万|万円)?\s*(以下|未満)?/u', $text, $matches)) {
        $price = (float)$matches[1];
        if ($price > 0 && $price < 2000) {
            searchCarsAndReply($db, $replyToken, ['max_price' => $price], "支払総額 {$price}万円以下の車両", $userId);
            return;
        }
    }

    // 4. フリーワード検索 (車名など)
    searchCarsAndReply($db, $replyToken, ['keyword' => $text], "「{$text}」の検索結果", $userId);
}

/**
 * ポストバックイベントの処理
 */
function handlePostback(PDO $db, string $replyToken, string $dataStr, string $userId = '') {
    parse_str($dataStr, $params);
    $action = $params['action'] ?? '';

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

        // --- 6. サイレント検索: 価格帯メニュー表示 ---
        case 'show_price_menu':
            sendPriceMenuMessage($replyToken);
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

        // --- 8. サイレント検索: 価格帯絞り込み実行 ---
        case 'search_price':
            $maxPrice = (float)($params['max_price'] ?? 0);
            $minPrice = (float)($params['min_price'] ?? 0);
            $criteria = [];
            $title = "支払総額 {$maxPrice}万円以下の車両";
            if ($maxPrice > 0) $criteria['max_price'] = $maxPrice;
            if ($minPrice > 0) {
                $criteria['min_price'] = $minPrice;
                $title = "支払総額 {$minPrice}万〜{$maxPrice}万円の車両";
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

        // --- 10. サイレント検索: 在庫全台一覧 ---
        case 'search_all':
        default:
            searchCarsAndReply($db, $replyToken, [], '現在の在庫車両一覧', $userId);
            break;
    }
}

/**
 * LIFF Trigger からのPush送信用サイレントPostback実行関数
 */
function executeSilentPostbackPush(PDO $db, string $userId, string $dataStr): bool {
    parse_str($dataStr, $params);
    $action = $params['action'] ?? '';

    writeDebugLog("LIFF Silent Postback Push実行", ['uid' => $userId, 'action' => $action, 'data' => $dataStr]);

    if (!str_starts_with($userId, 'U')) {
        writeDebugLog("Push送信スキップ: 有効なLINEユーザーIDではありません ({$userId})");
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

        case 'search_price':
            $maxPrice = (float)($params['max_price'] ?? 0);
            $minPrice = (float)($params['min_price'] ?? 0);
            $criteria = [];
            $title = "支払総額 {$maxPrice}万円以下の車両";
            if ($maxPrice > 0) $criteria['max_price'] = $maxPrice;
            if ($minPrice > 0) {
                $criteria['min_price'] = $minPrice;
                $title = "支払総額 {$minPrice}万〜{$maxPrice}万円の車両";
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
                    writeDebugLog("Push分割送信結果", ['userId' => $userId, 'success' => $res['success'] ?? false, 'response' => $res['response'] ?? '']);
                }
                return true;
            } else {
                $res = sendLinePushMessage($userId, $messages);
                writeDebugLog("Push送信結果", ['userId' => $userId, 'success' => $res['success'] ?? false, 'response' => $res['response'] ?? '']);
                return !empty($res['success']);
            }
        } catch (Exception $e) {
            writeDebugLog("Silent Postback Push送信例外: " . $e->getMessage());
            return false;
        }
    }

    return false;
}

/**
 * 友だち追加時のあいさつメッセージ
 */
function handleFollow(string $replyToken) {
    $messages = [
        [
            'type' => 'text',
            'text' => "友だち追加ありがとうございます！🚗✨\n\n【" . SHOP_NAME . "】の最新在庫車両をいつでもLINEから検索いただけます。\n\n気になる車種名を入力するか、下のボタンをタップしてみてください！",
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
        
        // 最大40台まで取得 (LINEの1回返信上限: 10台×4カルーセル = 40台)
        $stmt = $db->prepare("SELECT * FROM cars WHERE {$whereSql} ORDER BY (total_price_num IS NULL), total_price_num ASC LIMIT 40");
        $stmt->execute($params);
        $cars = $stmt->fetchAll();

        writeDebugLog("検索実行完了", ['heading' => $heading, 'hitCount' => count($cars), 'userId' => $userId]);

        if (empty($cars)) {
            return [
                [
                    'type' => 'text',
                    'text' => "申し訳ありません。ご指定の条件に一致する車両が見つかりませんでした。\n\n別のキーワードや価格帯でお試しください！",
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

        foreach ($bubbleChunks as $idx => $chunk) {
            $messages[] = [
                'type' => 'flex',
                'altText' => "{$heading} (" . ($idx * 10 + 1) . "〜" . ($idx * 10 + count($chunk)) . "件目)",
                'contents' => [
                    'type' => 'carousel',
                    'contents' => $chunk
                ],
                'quickReply' => getQuickReplyItems()
            ];
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

    // 1. Discord へ正式問い合わせ通知を送信！
    if (function_exists('sendDiscordInquiryNotification')) {
        sendDiscordInquiryNotification($car, $inquiryType, $userProfile, $userId);
        writeDebugLog("正式問い合わせ通知送信完了", ['carId' => $carId, 'type' => $inquiryType, 'user' => $userName]);
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

    // 1. Discord へ予約申し込み通知を送信！
    if (function_exists('sendDiscordMaintenanceBookingNotification')) {
        sendDiscordMaintenanceBookingNotification($bookingType, $carModel, $prefTime, $userProfile, $userId);
        writeDebugLog("メンテナンス予約Discord通知完了", ['type' => $bookingType, 'car' => $carModel, 'user' => $userName, 'pref' => $prefTime]);
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
 * 価格帯選択メニュー送信
 */
function sendPriceMenuMessage(string $replyToken) {
    $messages = generatePriceMenuMessages();
    sendReplyMessage($replyToken, $messages);
}

/**
 * 価格帯選択メニュー（サイレントボタン式Flex Message）生成
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
                    'text' => '💰 ご予算・支払総額から探す',
                    'weight' => 'bold',
                    'size' => 'md',
                    'color' => '#1e293b'
                ],
                [
                    'type' => 'text',
                    'text' => 'ご希望の価格帯をタップしてください。',
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
                                        'label' => '〜30万円',
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
                                        'label' => '〜50万円',
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
                                        'label' => '〜70万円',
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
                                        'label' => '〜100万円',
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
                                        'label' => '〜150万円',
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
                                        'label' => '〜200万円',
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
            'altText' => 'ご予算・支払総額から探す',
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
 * クイックリプライボタン一覧（完全サイレントPostback方式）
 */
function getQuickReplyItems(): array {
    return [
        'items' => [
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
                    'label' => '💰 価格で探す',
                    'data' => 'action=show_price_menu'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '🚙 車種で探す',
                    'data' => 'action=show_type_menu'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '⚙️ 装備で探す',
                    'data' => 'action=show_equipment_menu'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '🛣️ 距離で探す',
                    'data' => 'action=show_distance_menu'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '🚘 軽自動車',
                    'data' => 'action=search_kei'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'postback',
                    'label' => '💎 50万以下',
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
        writeDebugLog("返信スキップ: LINE_CHANNEL_ACCESS_TOKEN が未設定です");
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
