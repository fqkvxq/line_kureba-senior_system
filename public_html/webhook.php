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

// --- イベント処理関数群 ---

/**
 * テキストメッセージの処理
 */
function handleTextMessage(PDO $db, string $replyToken, string $text, string $userId = '') {
    // 1. 特殊キーワードの判定
    if (in_array($text, ['在庫一覧', '車を探す', 'メニュー', '在庫', '車', '全台'])) {
        searchCarsAndReply($db, $replyToken, [], '現在の在庫車両一覧', $userId);
        return;
    }

    // 2. 価格帯キーワードの判定 (例: 50万以下, 100万円以下, 50万円)
    if (preg_match('/([0-9\.]+)\s*(万|万円)?\s*(以下|未満)?/u', $text, $matches)) {
        $price = (float)$matches[1];
        if ($price > 0 && $price < 2000) {
            searchCarsAndReply($db, $replyToken, ['max_price' => $price], "支払総額 {$price}万円以下の車両", $userId);
            return;
        }
    }

    // 3. フリーワード検索 (車名など)
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

        // --- 3. キャンセル ---
        case 'cancel_inquiry':
            $messages = [
                [
                    'type' => 'text',
                    'text' => "お問い合わせをキャンセルしました。\n他の車両もぜひご覧ください🚗",
                    'quickReply' => getQuickReplyItems()
                ]
            ];
            sendReplyMessage($replyToken, $messages);
            break;

        case 'search_price':
            $maxPrice = (float)($params['max_price'] ?? 0);
            searchCarsAndReply($db, $replyToken, ['max_price' => $maxPrice], "支払総額 {$maxPrice}万円以下の車両", $userId);
            break;

        case 'search_type':
            $keyword = $params['keyword'] ?? '';
            searchCarsAndReply($db, $replyToken, ['keyword' => $keyword], "「{$keyword}」の車両一覧", $userId);
            break;

        default:
            searchCarsAndReply($db, $replyToken, [], '最新の在庫車両一覧', $userId);
            break;
    }
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
    try {
        $where = ["is_active = 1"];
        $params = [];

        if (!empty($criteria['keyword'])) {
            $kw = $criteria['keyword'];
            $where[] = "(title LIKE :kw OR displacement LIKE :kw OR year LIKE :kw)";
            $params[':kw'] = "%{$kw}%";
        }

        if (!empty($criteria['max_price'])) {
            $where[] = "total_price_num <= :max_price";
            $params[':max_price'] = $criteria['max_price'];
        }

        $whereSql = implode(' AND ', $where);
        
        // 最大40台まで取得 (LINEの1回返信上限: 10台×4カルーセル = 40台)
        $stmt = $db->prepare("SELECT * FROM cars WHERE {$whereSql} ORDER BY (total_price_num IS NULL), total_price_num ASC LIMIT 40");
        $stmt->execute($params);
        $cars = $stmt->fetchAll();

        writeDebugLog("検索実行完了", ['heading' => $heading, 'hitCount' => count($cars), 'userId' => $userId]);

        if (empty($cars)) {
            $messages = [
                [
                    'type' => 'text',
                    'text' => "申し訳ありません。ご指定の条件に一致する車両が見つかりませんでした。\n\n別のキーワードや価格帯でお試しください！",
                    'quickReply' => getQuickReplyItems()
                ]
            ];
            sendReplyMessage($replyToken, $messages);
            return;
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

        sendReplyMessage($replyToken, $messages);
    } catch (Exception $e) {
        writeDebugLog("検索・返信例外エラー: " . $e->getMessage());
        // フォールバック返信
        $fallbackMessages = [
            [
                'type' => 'text',
                'text' => "申し訳ありません。検索中にエラーが発生しました。\nしばらくしてからもう一度お試しください。",
                'quickReply' => getQuickReplyItems()
            ]
        ];
        sendReplyMessage($replyToken, $fallbackMessages);
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
                        'data' => 'action=ask_inquiry&id=' . urlencode($car['id']),
                        'displayText' => "【{$shortTitle}】について問い合わせたい"
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
 * クイックリプライボタン一覧
 */
function getQuickReplyItems(): array {
    return [
        'items' => [
            [
                'type' => 'action',
                'action' => [
                    'type' => 'message',
                    'label' => '🚗 在庫一覧',
                    'text' => '在庫一覧'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'message',
                    'label' => '💰 50万円以下',
                    'text' => '50万円以下'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'message',
                    'label' => '💎 70万円以下',
                    'text' => '70万円以下'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'message',
                    'label' => '🚘 ワゴンR',
                    'text' => 'ワゴンR'
                ]
            ],
            [
                'type' => 'action',
                'action' => [
                    'type' => 'message',
                    'label' => '🚙 N-BOX',
                    'text' => 'N-BOX'
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
