<?php
/**
 * シニア向けパソコン教室 自動リマインド配信スクリプト (PHP版)
 * XserverのCronで毎朝9:00に実行 (例: 0 9 * * *)
 * 
 * 判定条件 (上から順に処理):
 * 1. 次回レッスン予約: 次回予定日の7日前 & 1日前 & 当日
 * 2. 定期パソコン健康診断: 次回予定日の14日前 & 7日前 & 当日
 * 3. 会員更新・月謝期日: 次回満了日の30日前 & 14日前 & 当日
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

echo "[" . date('Y-m-d H:i:s') . "] === パソコン教室 自動リマインド処理を開始します ===\n";

try {
    $db = getDbConnection();
} catch (Exception $e) {
    die("DB接続エラー: " . $e->getMessage() . "\n");
}

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$in7days = date('Y-m-d', strtotime('+7 days'));
$in14days = date('Y-m-d', strtotime('+14 days'));
$in30days = date('Y-m-d', strtotime('+30 days'));

$lessonSentCount = 0;
$diagnosisSentCount = 0;
$renewSentCount = 0;
$reportDetails = [];

// ==========================================
// 1. 次回レッスン予約リマインド判定 & 送信
// ==========================================
$stmt = $db->prepare("
    SELECT * FROM customer_cars 
    WHERE oil_next_date IS NOT NULL 
      AND (oil_next_date = :today OR oil_next_date = :tomorrow OR oil_next_date = :in7days OR oil_next_date < :today)
      AND (oil_reminded_at IS NULL OR date(oil_reminded_at) != :today)
");
$stmt->execute([':today' => $today, ':tomorrow' => $tomorrow, ':in7days' => $in7days]);
$lessonTargetCustomers = $stmt->fetchAll();

echo "💻 次回レッスンリマインド対象: " . count($lessonTargetCustomers) . " 名\n";

foreach ($lessonTargetCustomers as $cust) {
    $userId = $cust['user_id'];
    if (!str_starts_with($userId, 'U')) {
        echo "  [スキップ] 手動登録受講生のためLINE Pushスキップ: {$cust['user_name']}\n";
        continue;
    }

    $userName = $cust['user_name'] ?: '受講生';
    $courseName = $cust['car_model'] ?: '受講コース';
    $lessonDate = $cust['oil_next_date'];

    $isToday = ($lessonDate === $today);
    $isTomorrow = ($lessonDate === $tomorrow);
    $isPast = ($lessonDate < $today);
    $statusBadge = $isToday ? "本日がレッスン日です！" : ($isTomorrow ? "明日がレッスン日です！" : ($isPast ? "予定日を過ぎています" : "まもなく受講日です（あと7日）"));

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
                    [
                        'type' => 'box',
                        'layout' => 'baseline',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => '💻 次回レッスンのご案内',
                                'weight' => 'bold',
                                'size' => 'sm',
                                'color' => '#0284c7'
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
                        'text' => "いつも【" . SHOP_NAME . "】をご利用いただきありがとうございます！\n次回レッスンの予定日をお知らせいたします。",
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
                                    ['type' => 'text', 'text' => '次回予定日', 'color' => '#0369a1', 'size' => 'xs', 'flex' => 3],
                                    ['type' => 'text', 'text' => "{$lessonDate} ({$statusBadge})", 'size' => 'xs', 'weight' => 'bold', 'color' => '#e02424', 'flex' => 6]
                                ]
                            ],
                            [
                                'type' => 'box',
                                'layout' => 'baseline',
                                'contents' => [
                                    ['type' => 'text', 'text' => '持ち物', 'color' => '#0369a1', 'size' => 'xs', 'flex' => 3],
                                    ['type' => 'text', 'text' => "筆記用具・ノートPCやスマホ", 'size' => 'xs', 'color' => '#475569', 'flex' => 6]
                                ]
                            ]
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => "※ご都合が悪くなった場合の日程変更やご相談は、下のボタンよりお気軽にご連絡くださいませ。",
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
                        'color' => '#0284c7',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'uri',
                            'label' => '📅 レッスン予約・日程変更',
                            'uri' => 'https://fsmk.co/t/yQ7ocg-grscdt?openExternalBrowser=1'
                        ]
                    ]
                ]
            ]
        ]
    ];

    $res = sendLinePushMessage($userId, [$flexMessage]);
    if (!empty($res['success'])) {
        $updateStmt = $db->prepare("UPDATE customer_cars SET oil_reminded_at = datetime('now', '+9 hours') WHERE id = :id");
        $updateStmt->execute([':id' => $cust['id']]);
        $lessonSentCount++;
        $reportDetails[] = [
            'name' => $userName,
            'car' => $courseName,
            'type' => '💻 レッスン予約リマインド',
            'date' => $lessonDate
        ];
        echo "  [送信成功] {$userName} 様 ({$courseName}) -> {$lessonDate}\n";
    } else {
        echo "  [送信失敗] {$userName} 様: " . ($res['error'] ?? 'APIエラー') . "\n";
    }
}

// ==========================================
// 2. 定期パソコン健康診断リマインド判定 & 送信
// ==========================================
$stmt = $db->prepare("
    SELECT * FROM customer_cars 
    WHERE periodic_insp_next_date IS NOT NULL 
      AND (periodic_insp_next_date = :today OR periodic_insp_next_date = :in7days OR periodic_insp_next_date = :in14days OR periodic_insp_next_date < :today)
      AND (periodic_reminded_at IS NULL OR date(periodic_reminded_at) != :today)
");
$stmt->execute([':today' => $today, ':in7days' => $in7days, ':in14days' => $in14days]);
$diagTargetCustomers = $stmt->fetchAll();

echo "🔍 定期パソコン健康診断リマインド対象: " . count($diagTargetCustomers) . " 名\n";

foreach ($diagTargetCustomers as $cust) {
    $userId = $cust['user_id'];
    if (!str_starts_with($userId, 'U')) {
        continue;
    }

    $userName = $cust['user_name'] ?: '受講生';
    $deviceInfo = $cust['car_number'] ?: ($cust['car_model'] ?: 'ご登録機器');
    $diagDate = $cust['periodic_insp_next_date'];

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
                    [
                        'type' => 'box',
                        'layout' => 'baseline',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => '🔍 定期パソコン健康診断のご案内',
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
                        'text' => "いつも【" . SHOP_NAME . "】をご利用いただきありがとうございます！\n定期的なパソコン・スマホの動作チェック・セキュリティ点検のご案内です。",
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
                        'backgroundColor' => '#ecfdf5',
                        'paddingAll' => '12px',
                        'cornerRadius' => 'md',
                        'contents' => [
                            [
                                'type' => 'box',
                                'layout' => 'baseline',
                                'contents' => [
                                    ['type' => 'text', 'text' => '対象機器', 'color' => '#047857', 'size' => 'xs', 'flex' => 3],
                                    ['type' => 'text', 'text' => $deviceInfo, 'size' => 'xs', 'weight' => 'bold', 'color' => '#0f172a', 'flex' => 6]
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
                    [
                        'type' => 'text',
                        'text' => "「最近パソコンが重い」「怪しい警告画面が出る」などのお悩みも教室スタッフにお気軽にご相談ください！",
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
                            'label' => '🛠 パソコン診断の予約・相談',
                            'data' => 'action=ask_class&type=diagnosis&device=' . urlencode($deviceInfo) . '&date=' . urlencode($diagDate),
                            'displayText' => "【{$deviceInfo}】の定期点検・診断を相談したい"
                        ]
                    ]
                ]
            ]
        ]
    ];

    $res = sendLinePushMessage($userId, [$flexMessage]);
    if (!empty($res['success'])) {
        $updateStmt = $db->prepare("UPDATE customer_cars SET periodic_reminded_at = datetime('now', '+9 hours') WHERE id = :id");
        $updateStmt->execute([':id' => $cust['id']]);
        $diagnosisSentCount++;
        $reportDetails[] = [
            'name' => $userName,
            'car' => $deviceInfo,
            'type' => '🔍 定期パソコン健康診断',
            'date' => $diagDate
        ];
        echo "  [送信成功] {$userName} 様 ({$deviceInfo}) -> {$diagDate}\n";
    } else {
        echo "  [送信失敗] {$userName} 様: " . ($res['error'] ?? 'APIエラー') . "\n";
    }
}

// ==========================================
// 3. 会員更新・月謝期日リマインド判定 & 送信
// ==========================================
$stmt = $db->prepare("
    SELECT * FROM customer_cars 
    WHERE inspection_next_date IS NOT NULL 
      AND (inspection_next_date = :today OR inspection_next_date = :in14days OR inspection_next_date = :in30days OR inspection_next_date < :today)
      AND (inspection_reminded_at IS NULL OR date(inspection_reminded_at) != :today)
");
$stmt->execute([':today' => $today, ':in14days' => $in14days, ':in30days' => $in30days]);
$renewTargetCustomers = $stmt->fetchAll();

echo "🗓️ 会員更新・月謝期日リマインド対象: " . count($renewTargetCustomers) . " 名\n";

foreach ($renewTargetCustomers as $cust) {
    $userId = $cust['user_id'];
    if (!str_starts_with($userId, 'U')) {
        continue;
    }

    $userName = $cust['user_name'] ?: '受講生';
    $courseName = $cust['car_model'] ?: '受講プラン';
    $renewDate = $cust['inspection_next_date'];

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
                    [
                        'type' => 'box',
                        'layout' => 'baseline',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => '🗓️ 会員更新・月謝期日のご案内',
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
                        'text' => "いつも【" . SHOP_NAME . "】をご愛顧いただき誠にありがとうございます。\n受講プラン・会員有効期限（月謝）のお知らせです。",
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
                    [
                        'type' => 'text',
                        'text' => "コース変更や受講回数の追加、ご不明な点がございましたら教室受付またはLINEトークよりお気軽にお問い合わせください。",
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
                        'color' => '#f59e0b',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'postback',
                            'label' => '💬 コース・更新について相談',
                            'data' => 'action=ask_class&type=renew&course=' . urlencode($courseName) . '&date=' . urlencode($renewDate),
                            'displayText' => "【{$courseName}】の受講更新・プランについて相談したい"
                        ]
                    ]
                ]
            ]
        ]
    ];

    $res = sendLinePushMessage($userId, [$flexMessage]);
    if (!empty($res['success'])) {
        $updateStmt = $db->prepare("UPDATE customer_cars SET inspection_reminded_at = datetime('now', '+9 hours') WHERE id = :id");
        $updateStmt->execute([':id' => $cust['id']]);
        $renewSentCount++;
        $reportDetails[] = [
            'name' => $userName,
            'car' => $courseName,
            'type' => '🗓️ 会員更新・月謝リマインド',
            'date' => $renewDate
        ];
        echo "  [送信成功] {$userName} 様 ({$courseName}) -> {$renewDate}\n";
    } else {
        echo "  [送信失敗] {$userName} 様: " . ($res['error'] ?? 'APIエラー') . "\n";
    }
}

// ==========================================
// 4. 管理者通知 (Discord / LINE)
// ==========================================
$totalSent = $lessonSentCount + $diagnosisSentCount + $renewSentCount;
if ($totalSent > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] 合計 {$totalSent} 件のリマインドを送信しました。\n";
} else {
    echo "[" . date('Y-m-d H:i:s') . "] 本日送信対象のリマインドはありませんでした。\n";
}

echo "=== リマインド配信処理完了 ===\n";
