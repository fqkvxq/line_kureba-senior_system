<?php
/**
 * 定期点検・オイル交換・車検 自動リマインド配信スクリプト (PHP版)
 * XserverのCronで毎朝9:00に実行 (例: 0 9 * * *)
 * 
 * 判定条件 (上から順に処理):
 * 1. オイル交換: 次回予定日の7日前 & 当日
 * 2. 12ヶ月定期点検: 次回予定日の14日前 & 7日前 & 当日
 * 3. 車検満了: 次回満了日の30日前 & 14日前 & 当日
 */

date_default_timezone_set('Asia/Tokyo');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// 設定読み込み
$configPaths = [
    __DIR__ . '/../public_html/config.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/config.php',
    dirname(__DIR__) . '/public_html/config.php'
];
foreach ($configPaths as $cp) {
    if (file_exists($cp)) {
        require_once $cp;
        break;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] === メンテナンス自動リマインド処理を開始します ===\n";

try {
    $db = getDbConnection();
} catch (Exception $e) {
    die("DB接続エラー: " . $e->getMessage() . "\n");
}

$today = date('Y-m-d');
$in7days = date('Y-m-d', strtotime('+7 days'));
$in14days = date('Y-m-d', strtotime('+14 days'));
$in30days = date('Y-m-d', strtotime('+30 days'));

$oilSentCount = 0;
$inspectionSentCount = 0;
$reportDetails = [];

// ==========================================
// 1. オイル交換リマインド判定 & 送信
// ==========================================
$stmt = $db->prepare("
    SELECT * FROM customers 
    WHERE oil_next_date IS NOT NULL 
      AND (oil_next_date = :today OR oil_next_date = :in7days OR oil_next_date < :today)
      AND (oil_reminded_at IS NULL OR date(oil_reminded_at) != :today)
");
$stmt->execute([':today' => $today, ':in7days' => $in7days]);
$oilTargetCustomers = $stmt->fetchAll();

echo "🛢 オイル交換リマインド対象: " . count($oilTargetCustomers) . " 名\n";

foreach ($oilTargetCustomers as $cust) {
    $userId = $cust['user_id'];
    if (!str_starts_with($userId, 'U')) {
        echo "  [スキップ] 手動登録顧客のためLINE Pushスキップ: {$cust['user_name']}\n";
        continue;
    }

    $userName = $cust['user_name'] ?: 'お客様';
    $carModel = $cust['car_model'] ?: '愛車';
    $oilDate = $cust['oil_next_date'];

    $isToday = ($oilDate === $today);
    $isPast = ($oilDate < $today);
    $statusBadge = $isToday ? "本日が予定日です！" : ($isPast ? "予定日を過ぎています" : "まもなく予定日です（あと7日）");

    $flexMessage = [
        'type' => 'flex',
        'altText' => "【オイル交換のお知らせ】{$carModel}の交換時期が近づいています",
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
                            [
                                'type' => 'text',
                                'text' => '🛢 オイル交換のお知らせ',
                                'weight' => 'bold',
                                'size' => 'sm',
                                'color' => '#f59e0b'
                            ]
                        ]
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
                        'text' => "いつも【" . SHOP_NAME . "】をご利用いただきありがとうございます！\n愛車の次回オイル交換予定日をお知らせいたします。",
                        'size' => 'xs',
                        'color' => '#475569',
                        'margin' => 'sm',
                        'wrap' => true
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'md'
                    ],
                    // スペック枠
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
                                    ['type' => 'text', 'text' => '対象車両', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                    ['type' => 'text', 'text' => $carModel, 'size' => 'xs', 'weight' => 'bold', 'color' => '#1e293b', 'flex' => 6]
                                ]
                            ],
                            [
                                'type' => 'box',
                                'layout' => 'baseline',
                                'contents' => [
                                    ['type' => 'text', 'text' => '次回予定日', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                    ['type' => 'text', 'text' => "{$oilDate} ({$statusBadge})", 'size' => 'xs', 'weight' => 'bold', 'color' => '#e02424', 'flex' => 6]
                                ]
                            ],
                            [
                                'type' => 'box',
                                'layout' => 'baseline',
                                'contents' => [
                                    ['type' => 'text', 'text' => '交換の目安', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                    ['type' => 'text', 'text' => "5,000〜10,000km / 半年〜1年", 'size' => 'xs', 'color' => '#475569', 'flex' => 6]
                                ]
                            ]
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => "※目安：走行5,000km〜10,000km、または半年〜1年のどちらか早い方での交換を推奨しております。\nご予約・空き状況のご相談は下のボタンよりお気軽にどうぞ！",
                        'size' => 'xxs',
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
                            'label' => '📅 オイル交換の予約・相談',
                            'data' => 'action=ask_maintenance&type=oil&car=' . urlencode($carModel) . '&date=' . urlencode($oilDate),
                            'displayText' => "【{$carModel}】のオイル交換を予約・相談したい"
                        ]
                    ]
                ]
            ]
        ]
    ];

    $res = sendLinePushMessage($userId, [$flexMessage]);
    if (!empty($res['success'])) {
        $updateStmt = $db->prepare("UPDATE customers SET oil_reminded_at = CURRENT_TIMESTAMP WHERE user_id = :uid");
        $updateStmt->execute([':uid' => $userId]);
        $oilSentCount++;
        $reportDetails[] = [
            'name' => $userName,
            'car' => $carModel,
            'type' => '🛢 オイル交換リマインド',
            'date' => $oilDate
        ];
        echo "  [送信成功] {$userName} 様 ({$carModel}) -> {$oilDate}\n";
    } else {
        echo "  [送信失敗] {$userName} 様: " . ($res['error'] ?? 'APIエラー') . "\n";
    }
}

// ==========================================
// 2. 12ヶ月定期点検リマインド判定 & 送信
// ==========================================
$stmt = $db->prepare("
    SELECT * FROM customers 
    WHERE periodic_insp_next_date IS NOT NULL 
      AND (periodic_insp_next_date = :today OR periodic_insp_next_date = :in7days OR periodic_insp_next_date = :in14days OR periodic_insp_next_date < :today)
      AND (periodic_reminded_at IS NULL OR date(periodic_reminded_at) != :today)
");
$stmt->execute([':today' => $today, ':in7days' => $in7days, ':in14days' => $in14days]);
$periodicTargetCustomers = $stmt->fetchAll();

$periodicSentCount = 0;
echo "📋 12ヶ月定期点検リマインド対象: " . count($periodicTargetCustomers) . " 名\n";

foreach ($periodicTargetCustomers as $cust) {
    $userId = $cust['user_id'];
    if (!str_starts_with($userId, 'U')) {
        continue;
    }

    $userName = $cust['user_name'] ?: 'お客様';
    $carModel = $cust['car_model'] ?: '愛車';
    $inspDate = $cust['periodic_insp_next_date'];

    $flexMessage = [
        'type' => 'flex',
        'altText' => "【12ヶ月定期点検のお知らせ】{$carModel}の点検時期が近づいています",
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
                            [
                                'type' => 'text',
                                'text' => '📋 12ヶ月定期点検のご案内',
                                'weight' => 'bold',
                                'size' => 'sm',
                                'color' => '#10b981'
                            ]
                        ]
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
                        'text' => "いつも【" . SHOP_NAME . "】をご利用いただきありがとうございます！\n愛車【{$carModel}】の法定12ヶ月定期点検の時期をお知らせいたします。",
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
                                    ['type' => 'text', 'text' => '対象車両', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                    ['type' => 'text', 'text' => $carModel, 'size' => 'xs', 'weight' => 'bold', 'color' => '#1e293b', 'flex' => 6]
                                ]
                            ],
                            [
                                'type' => 'box',
                                'layout' => 'baseline',
                                'contents' => [
                                    ['type' => 'text', 'text' => '次回点検日', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                    ['type' => 'text', 'text' => $inspDate, 'size' => 'xs', 'weight' => 'bold', 'color' => '#e02424', 'flex' => 6]
                                ]
                            ]
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => "愛車のコンディション維持や故障の早期発見のため、年1回の定期点検をおすすめしております。\nご予約・日程相談は下のボタンよりお気軽にどうぞ！",
                        'size' => 'xxs',
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
                        'color' => '#10b981',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '📅 12ヶ月点検の予約・相談',
                            'data' => 'action=ask_maintenance&type=periodic&car=' . urlencode($carModel) . '&date=' . urlencode($inspDate),
                            'displayText' => "【{$carModel}】の12ヶ月点検を予約・相談したい"
                        ]
                    ]
                ]
            ]
        ]
    ];

    $res = sendLinePushMessage($userId, [$flexMessage]);
    if (!empty($res['success'])) {
        $updateStmt = $db->prepare("UPDATE customers SET periodic_reminded_at = CURRENT_TIMESTAMP WHERE user_id = :uid");
        $updateStmt->execute([':uid' => $userId]);
        $periodicSentCount++;
        $reportDetails[] = [
            'name' => $userName,
            'car' => $carModel,
            'type' => '📋 12ヶ月定期点検',
            'date' => $inspDate
        ];
        echo "  [送信成功] {$userName} 様 ({$carModel}) -> {$inspDate}\n";
    } else {
        echo "  [送信失敗] {$userName} 様: " . ($res['error'] ?? 'APIエラー') . "\n";
    }
}

// ==========================================
// 3. 車検満了リマインド判定 & 送信
// ==========================================
$stmt = $db->prepare("
    SELECT * FROM customers 
    WHERE inspection_next_date IS NOT NULL 
      AND (inspection_next_date = :today OR inspection_next_date = :in14days OR inspection_next_date = :in30days OR inspection_next_date < :today)
      AND (inspection_reminded_at IS NULL OR date(inspection_reminded_at) != :today)
");
$stmt->execute([':today' => $today, ':in14days' => $in14days, ':in30days' => $in30days]);
$inspTargetCustomers = $stmt->fetchAll();

$shakenSentCount = 0;
echo "🚗 車検満了リマインド対象: " . count($inspTargetCustomers) . " 名\n";

foreach ($inspTargetCustomers as $cust) {
    $userId = $cust['user_id'];
    if (!str_starts_with($userId, 'U')) {
        continue;
    }

    $userName = $cust['user_name'] ?: 'お客様';
    $carModel = $cust['car_model'] ?: '愛車';
    $inspDate = $cust['inspection_next_date'];

    $flexMessage = [
        'type' => 'flex',
        'altText' => "【車検満了のお知らせ】{$carModel}の満了日が近づいています",
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
                            [
                                'type' => 'text',
                                'text' => '🚗 車検満了のご案内',
                                'weight' => 'bold',
                                'size' => 'sm',
                                'color' => '#3b82f6'
                            ]
                        ]
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
                        'text' => "いつも【" . SHOP_NAME . "】をご利用いただきありがとうございます！\n愛車【{$carModel}】の車検満了日が近づいております。",
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
                                    ['type' => 'text', 'text' => '対象車両', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                    ['type' => 'text', 'text' => $carModel, 'size' => 'xs', 'weight' => 'bold', 'color' => '#1e293b', 'flex' => 6]
                                ]
                            ],
                            [
                                'type' => 'box',
                                'layout' => 'baseline',
                                'contents' => [
                                    ['type' => 'text', 'text' => '車検満了日', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                    ['type' => 'text', 'text' => $inspDate, 'size' => 'xs', 'weight' => 'bold', 'color' => '#e02424', 'flex' => 6]
                                ]
                            ]
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => "車検満了日の約1ヶ月前より受検が可能です。\n代車の手配や事前お見積もりも承っておりますので、お気軽にご連絡ください！",
                        'size' => 'xxs',
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
                        'color' => '#3b82f6',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '📅 車検の予約・見積もり',
                            'data' => 'action=ask_maintenance&type=inspection&car=' . urlencode($carModel) . '&date=' . urlencode($inspDate),
                            'displayText' => "【{$carModel}】の車検を予約・相談したい"
                        ]
                    ]
                ]
            ]
        ]
    ];

    $res = sendLinePushMessage($userId, [$flexMessage]);
    if (!empty($res['success'])) {
        $updateStmt = $db->prepare("UPDATE customers SET inspection_reminded_at = CURRENT_TIMESTAMP WHERE user_id = :uid");
        $updateStmt->execute([':uid' => $userId]);
        $shakenSentCount++;
        $reportDetails[] = [
            'name' => $userName,
            'car' => $carModel,
            'type' => '🚗 車検満了リマインド',
            'date' => $inspDate
        ];
        echo "  [送信成功] {$userName} 様 ({$carModel}) -> {$inspDate}\n";
    } else {
        echo "  [送信失敗] {$userName} 様: " . ($res['error'] ?? 'APIエラー') . "\n";
    }
}

// ==========================================
// 4. Discord レポート通知
// ==========================================
if ($oilSentCount > 0 || $periodicSentCount > 0 || $shakenSentCount > 0) {
    sendDiscordReminderReport($oilSentCount, $periodicSentCount, $shakenSentCount, $reportDetails);
    echo "[" . date('Y-m-d H:i:s') . "] Discordへ配信レポートを送信しました (合計: " . ($oilSentCount + $periodicSentCount + $shakenSentCount) . " 件)\n";
} else {
    echo "[" . date('Y-m-d H:i:s') . "] 本日送信対象のリマインドはありませんでした。\n";
}

echo "=== リマインド配信処理完了 ===\n";
