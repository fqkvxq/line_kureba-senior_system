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
    .log-box{background:#0f172a;color:#a5f3fc;padding:12px;border-radius:8px;font-family:monospace;font-size:12px;max-height:200px;overflow-y:auto;white-space:pre-wrap}
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
        <h3>📋 最近のログ (webhook_debug.log)</h3>
        <div class="log-box">
HTML;
    $logFile = __DIR__ . '/webhook_debug.log';
    if (file_exists($logFile)) {
        $lines = array_slice(file($logFile), -15);
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

    $type = $event['type'];
    writeDebugLog("イベント処理開始", ['type' => $type]);

    if ($type === 'message' && $event['message']['type'] === 'text') {
        $userText = trim($event['message']['text']);
        writeDebugLog("テキスト受信", ['text' => $userText]);
        handleTextMessage($db, $replyToken, $userText);
    } elseif ($type === 'postback') {
        $postbackData = $event['postback']['data'] ?? '';
        writeDebugLog("ポストバック受信", ['data' => $postbackData]);
        handlePostback($db, $replyToken, $postbackData);
    } elseif ($type === 'follow') {
        writeDebugLog("友だち追加イベント");
        handleFollow($replyToken);
    }
}

http_response_code(200);
echo 'OK';

// --- イベント処理関数群 ---

/**
 * テキストメッセージの処理
 */
function handleTextMessage(PDO $db, string $replyToken, string $text) {
    // 1. 特殊キーワードの判定
    if (in_array($text, ['在庫一覧', '車を探す', 'メニュー', '在庫', '車', '全台'])) {
        searchCarsAndReply($db, $replyToken, [], '現在の在庫車両一覧');
        return;
    }

    // 2. 価格帯キーワードの判定 (例: 50万以下, 100万円以下, 50万円)
    if (preg_match('/([0-9\.]+)\s*(万|万円)?\s*(以下|未満)?/u', $text, $matches)) {
        $price = (float)$matches[1];
        if ($price > 0 && $price < 2000) {
            searchCarsAndReply($db, $replyToken, ['max_price' => $price], "支払総額 {$price}万円以下の車両");
            return;
        }
    }

    // 3. フリーワード検索 (車名など)
    searchCarsAndReply($db, $replyToken, ['keyword' => $text], "「{$text}」の検索結果");
}

/**
 * ポストバックイベントの処理
 */
function handlePostback(PDO $db, string $replyToken, string $dataStr) {
    parse_str($dataStr, $params);
    $action = $params['action'] ?? '';

    switch ($action) {
        case 'search_price':
            $maxPrice = (float)($params['max_price'] ?? 0);
            searchCarsAndReply($db, $replyToken, ['max_price' => $maxPrice], "支払総額 {$maxPrice}万円以下の車両");
            break;

        case 'search_type':
            $keyword = $params['keyword'] ?? '';
            searchCarsAndReply($db, $replyToken, ['keyword' => $keyword], "「{$keyword}」の車両一覧");
            break;

        default:
            searchCarsAndReply($db, $replyToken, [], '最新の在庫車両一覧');
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
function searchCarsAndReply(PDO $db, string $replyToken, array $criteria, string $heading) {
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
    $stmt = $db->prepare("SELECT * FROM cars WHERE {$whereSql} ORDER BY total_price_num ASC NULLS LAST LIMIT 10");
    $stmt->execute($params);
    $cars = $stmt->fetchAll();

    writeDebugLog("検索実行完了", ['heading' => $heading, 'hitCount' => count($cars)]);

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
        $bubbles[] = buildCarFlexBubble($car);
    }

    $flexMessage = [
        'type' => 'flex',
        'altText' => "{$heading} (" . count($cars) . "件)",
        'contents' => [
            'type' => 'carousel',
            'contents' => $bubbles
        ],
        'quickReply' => getQuickReplyItems()
    ];

    $messages = [
        [
            'type' => 'text',
            'text' => "🔍 {$heading} をお送りします（" . count($cars) . "件）"
        ],
        $flexMessage
    ];

    sendReplyMessage($replyToken, $messages);
}

/**
 * 車両1台分のFlex Messageバブルを構築
 */
function buildCarFlexBubble(array $car): array {
    $title = $car['title'];
    $shortTitle = mb_substr($title, 0, 35) . (mb_strlen($title) > 35 ? '...' : '');
    $imgUrl = !empty($car['image_url']) ? $car['image_url'] : 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';
    $totalPrice = !empty($car['total_price_text']) ? $car['total_price_text'] : '要問合せ';
    $year = !empty($car['year']) ? $car['year'] : '-';
    $distance = !empty($car['distance']) ? $car['distance'] : '-';
    $repair = !empty($car['repair_history']) ? $car['repair_history'] : '-';
    $detailUrl = $car['detail_url'];

    // 問い合わせ文面
    $inquiryText = "【車両問い合わせ】\n車名: {$title}\n車両ID: {$car['id']}\n支払総額: {$totalPrice}\n詳細: {$detailUrl}\n\nこちらの車両について詳しく知りたいです。";

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
                'uri' => $detailUrl
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
                        'type' => 'message',
                        'label' => '💬 この車を問い合わせ',
                        'text' => $inquiryText
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'secondary',
                    'height' => 'sm',
                    'action' => [
                        'type' => 'uri',
                        'label' => 'グーネットで詳細を見る',
                        'uri' => $detailUrl
                    ]
                ]
            ]
        ]
    ];
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
