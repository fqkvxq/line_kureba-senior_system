<?php
/**
 * グーネット特定店舗 車両情報スクレイピング & SQLite同期スクリプト (PHP版)
 * XserverのCronで定期実行 (例: 0 6,12,18 * * *) して使用します。
 * 新着車両が検知された場合、LINE公式アカウントの友だち全員へFlex Message自動一斉配信＆Discord通知を行います。
 */

// タイムゾーンとエラー設定
date_default_timezone_set('Asia/Tokyo');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// 共通設定の読み込み
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

// 設定
$shopCode = defined('SHOP_CODE') ? SHOP_CODE : '0601492';
$shopName = defined('SHOP_NAME') ? SHOP_NAME : 'アップファーム';
$baseUrl = "https://www.goo-net.com/usedcar_shop/{$shopCode}/";
$dbFile = defined('DB_PATH') ? DB_PATH : (__DIR__ . '/cars.db');

echo "[" . date('Y-m-d H:i:s') . "] === グーネット車両データ同期処理を開始します ===\n";
echo "対象店舗コード: {$shopCode}\n";
echo "データベースファイル: {$dbFile}\n";

// データベース接続・初期化
try {
    $db = new PDO("sqlite:{$dbFile}");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // テーブル作成
    $db->exec("
        CREATE TABLE IF NOT EXISTS cars (
            id TEXT PRIMARY KEY,
            shop_code TEXT NOT NULL,
            title TEXT NOT NULL,
            total_price_text TEXT,
            total_price_num REAL,
            base_price_text TEXT,
            base_price_num REAL,
            year TEXT,
            distance TEXT,
            distance_num REAL,
            displacement TEXT,
            repair_history TEXT,
            shaken TEXT,
            image_url TEXT,
            detail_url TEXT,
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_cars_active ON cars(is_active);
        CREATE INDEX IF NOT EXISTS idx_cars_price ON cars(total_price_num);
        CREATE INDEX IF NOT EXISTS idx_cars_title ON cars(title);
    ");
} catch (Exception $e) {
    die("DB接続エラー: " . $e->getMessage() . "\n");
}

// 同期前の既存アクティブ車両ID一覧を取得 (新着検知用)
$existingCarIds = [];
$initialDbCarCount = 0;
try {
    $stmt = $db->query("SELECT id FROM cars WHERE is_active = 1 AND shop_code = '{$shopCode}'");
    while ($row = $stmt->fetch()) {
        $existingCarIds[$row['id']] = true;
    }
    $initialDbCarCount = count($existingCarIds);
    echo "同期前の既存在庫台数: {$initialDbCarCount} 台\n";
} catch (Exception $e) {
    echo "既存ID取得警告: " . $e->getMessage() . "\n";
}

// スクレイピング実行
$page = 1;
$allCars = [];
$newCars = [];
$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

while (true) {
    $targetUrl = ($page === 1) ? "{$baseUrl}stock.html" : "{$baseUrl}stock_{$page}.html";
    echo "ページ取得中: {$targetUrl} ...\n";

    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $rawHtml = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($rawHtml)) {
        echo "ページが存在しないか取得できませんでした (HTTP: {$httpCode})。巡回を終了します。\n";
        break;
    }

    // 文字コード変換 (EUC-JP / SJIS -> UTF-8)
    $encoding = mb_detect_encoding($rawHtml, ['EUC-JP', 'UTF-8', 'SJIS', 'CP51932'], true) ?: 'EUC-JP';
    $html = mb_convert_encoding($rawHtml, 'UTF-8', $encoding);

    // 車両ブロックを抽出
    // グーネットの車両コンテナ: div.box_item_detail
    preg_match_all('/<div class="box_item_detail[^"]*" id="tr_([^"]+)"[^>]*>(.*?)<!--\/\/ \.application -->/s', $html, $matches, PREG_SET_ORDER);

    if (empty($matches)) {
        echo "車両データが見つかりませんでした。巡回を終了します。\n";
        break;
    }

    $pageCarCount = 0;
    foreach ($matches as $match) {
        $carId = trim($match[1]);
        $block = $match[2];

        // 1. 車名・タイトル
        $title = '';
        if (preg_match('/<h3 class="car_box_title">.*?<a[^>]*>(.*?)<\/a>/s', $block, $tMatch)) {
            $title = trim(preg_replace('/\s+/', ' ', strip_tags($tMatch[1])));
        }

        // 2. 詳細URL
        $detailUrl = "https://www.goo-net.com/usedcar/spread/goo/15/{$carId}.html";

        // 3. 画像URL
        $imageUrl = '';
        if (preg_match('/src="(https:\/\/picture1\.goo-net\.com\/[^"]+)"/', $block, $iMatch)) {
            $imageUrl = $iMatch[1];
        }

        // 4. 支払総額 (例: 60万円)
        $totalPriceText = '';
        $totalPriceNum = null;
        if (preg_match('/<div class="priceAllNum"><em>(.*?)<\/em><span>(.*?)<\/span>/s', $block, $pMatch)) {
            $numStr = trim(strip_tags($pMatch[1]));
            $unit = trim(strip_tags($pMatch[2]));
            $totalPriceText = $numStr . $unit;
            if (is_numeric($numStr)) {
                $totalPriceNum = (float)$numStr;
            }
        }

        // 5. 本体価格
        $basePriceText = '';
        $basePriceNum = null;
        if (preg_match('/<p class="car">.*?<em>(.*?)<\/em><span>(.*?)<\/span>/s', $block, $bMatch)) {
            $bNum = trim(strip_tags($bMatch[1]));
            $bUnit = trim(strip_tags($bMatch[2]));
            $basePriceText = $bNum . $bUnit;
            if (is_numeric($bNum)) {
                $basePriceNum = (float)$bNum;
            }
        }

        // 6. スペックテーブル (年式, 走行距離, 排気量, 修復歴, 車検)
        $year = '';
        $distance = '';
        $distanceNum = null;
        $displacement = '';
        $repairHistory = '';
        $shaken = '';

        if (preg_match('/<table>.*?<tr>(.*?)<\/tr>.*?<\/table>/s', $block, $tableMatch)) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $tableMatch[1], $tds);
            if (!empty($tds[1]) && count($tds[1]) >= 6) {
                $year = trim(preg_replace('/\s+/', ' ', strip_tags($tds[1][1])));
                $distance = trim(preg_replace('/\s+/', ' ', strip_tags($tds[1][2])));
                $displacement = trim(preg_replace('/\s+/', ' ', strip_tags($tds[1][3])));
                $repairHistory = trim(preg_replace('/\s+/', ' ', strip_tags($tds[1][4])));
                $shaken = trim(preg_replace('/\s+/', ' ', strip_tags($tds[1][5])));

                if (preg_match('/([0-9\.]+)\s*万km/', $distance, $dMatch)) {
                    $distanceNum = (float)$dMatch[1];
                } elseif (preg_match('/([0-9,]+)\s*km/', $distance, $dMatch)) {
                    $distanceNum = (float)str_replace(',', '', $dMatch[1]) / 10000.0;
                }
            }
        }

        $carData = [
            'id' => $carId,
            'shop_code' => $shopCode,
            'title' => $title,
            'total_price_text' => $totalPriceText,
            'total_price_num' => $totalPriceNum,
            'base_price_text' => $basePriceText,
            'base_price_num' => $basePriceNum,
            'year' => $year,
            'distance' => $distance,
            'distance_num' => $distanceNum,
            'displacement' => $displacement,
            'repair_history' => $repairHistory,
            'shaken' => $shaken,
            'image_url' => $imageUrl,
            'detail_url' => $detailUrl,
        ];

        $allCars[$carId] = $carData;

        // 新着車両判定 (初回起動時は除外、2回目以降で既存DBに存在しないIDを新着とする)
        if ($initialDbCarCount > 0 && !isset($existingCarIds[$carId])) {
            $newCars[$carId] = $carData;
            echo "  [NEW!] 新着車両を検知しました: {$title} ({$totalPriceText})\n";
        }

        $pageCarCount++;
    }

    echo "  -> {$pageCarCount} 台の車両データを抽出しました。\n";

    if ($pageCarCount < 20) {
        break;
    }
    $page++;
    usleep(500000);
}

$totalFetched = count($allCars);
echo "合計取得台数: {$totalFetched} 台\n";

if ($totalFetched === 0) {
    echo "車両データが取得できなかったため、DB更新をスキップします。\n";
    exit;
}

// データベースへの保存・更新 (UPSERT)
$db->beginTransaction();
try {
    // 1. 今回取得できた車両を保存・更新 (is_active = 1)
    $stmt = $db->prepare("
        INSERT OR REPLACE INTO cars (
            id, shop_code, title, total_price_text, total_price_num,
            base_price_text, base_price_num, year, distance, distance_num,
            displacement, repair_history, shaken, image_url, detail_url,
            is_active, updated_at
        ) VALUES (
            :id, :shop_code, :title, :total_price_text, :total_price_num,
            :base_price_text, :base_price_num, :year, :distance, :distance_num,
            :displacement, :repair_history, :shaken, :image_url, :detail_url,
            1, CURRENT_TIMESTAMP
        )
    ");

    foreach ($allCars as $car) {
        $stmt->execute([
            ':id' => $car['id'],
            ':shop_code' => $car['shop_code'],
            ':title' => $car['title'],
            ':total_price_text' => $car['total_price_text'],
            ':total_price_num' => $car['total_price_num'],
            ':base_price_text' => $car['base_price_text'],
            ':base_price_num' => $car['base_price_num'],
            ':year' => $car['year'],
            ':distance' => $car['distance'],
            ':distance_num' => $car['distance_num'],
            ':displacement' => $car['displacement'],
            ':repair_history' => $car['repair_history'],
            ':shaken' => $car['shaken'],
            ':image_url' => $car['image_url'],
            ':detail_url' => $car['detail_url'],
        ]);
    }

    // 2. 今回取得されなかった（掲載終了・売約済み）車両を is_active = 0 に更新
    $currentIds = array_keys($allCars);
    $inClause = implode(',', array_fill(0, count($currentIds), '?'));
    $deactivateStmt = $db->prepare("
        UPDATE cars SET is_active = 0, updated_at = CURRENT_TIMESTAMP
        WHERE shop_code = ? AND id NOT IN ({$inClause})
    ");
    $deactivateStmt->execute(array_merge([$shopCode], $currentIds));

    $db->commit();
    echo "[" . date('Y-m-d H:i:s') . "] データベースの同期が正常に完了しました！\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "DB更新エラー: " . $e->getMessage() . "\n";
    exit;
}

// --- 新着車両の通知処理 (LINE一斉配信 ＆ Discord通知) ---
$newCarCount = count($newCars);
if ($newCarCount > 0) {
    echo "\n=== 🆕 新着車両 {$newCarCount} 台の自動通知処理を開始します ===\n";

    // 1. Discord 通知
    if (function_exists('sendDiscordNewCarsNotification') && defined('ENABLE_NEW_CAR_DISCORD') && ENABLE_NEW_CAR_DISCORD) {
        echo "Discord へ新着通知を送信中...\n";
        sendDiscordNewCarsNotification($newCars);
        echo "  -> Discord通知送信完了\n";
    }

    // 2. LINE 公式アカウント友だちへの自動一斉配信 (Broadcast)
    if (function_exists('sendLineBroadcastMessage') && defined('ENABLE_NEW_CAR_BROADCAST') && ENABLE_NEW_CAR_BROADCAST) {
        echo "LINE 公式アカウントの友だちへ新着Flex Message一斉配信を送信中...\n";
        
        $broadcastMessages = buildNewCarsBroadcastMessages($newCars, $shopName);
        if (!empty($broadcastMessages)) {
            $res = sendLineBroadcastMessage($broadcastMessages);
            if (!empty($res['success'])) {
                echo "  -> 🚀 LINE一斉配信が正常に完了しました！ (HTTP: {$res['httpCode']})\n";
            } else {
                echo "  -> ⚠️ LINE一斉配信失敗 (HTTP: " . ($res['httpCode'] ?? 'N/A') . "): " . ($res['response'] ?? $res['error'] ?? '') . "\n";
            }
        }
    } else {
        echo "LINE一斉配信設定は無効(ENABLE_NEW_CAR_BROADCAST: false)のためスキップしました。\n";
    }
} else {
    echo "新着車両はありませんでした。\n";
}

/**
 * 新着車両用のLINE一斉配信メッセージを組み立てる
 */
function buildNewCarsBroadcastMessages(array $newCars, string $shopName): array {
    $count = count($newCars);
    $baseUrl = function_exists('getBaseUrl') ? getBaseUrl() : 'https://kureba.co.jp/line-car-search';
    if (!str_starts_with($baseUrl, 'https://')) {
        $baseUrl = 'https://' . ltrim($baseUrl, 'http://');
    }

    // メッセージ1: あいさつテキスト
    $textMsg = [
        'type' => 'text',
        'text' => "🚗✨ 【{$shopName}】新着車両が入荷しました！（{$count}台）\n\n新しく掲載された車両をお知らせします！気になる車両はお早めにチェックしてみてください👇"
    ];

    // メッセージ2: カルーセル (最大10台)
    $displayCars = array_slice($newCars, 0, 10);
    $bubbles = [];

    foreach ($displayCars as $car) {
        $rawTitle = trim($car['title'] ?? '新着車両');
        $shortTitle = mb_substr($rawTitle, 0, 32) . (mb_strlen($rawTitle) > 32 ? '...' : '');
        $imgUrl = !empty($car['image_url']) ? $car['image_url'] : 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';
        if (!str_starts_with($imgUrl, 'https://')) {
            $imgUrl = 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';
        }

        $totalPrice = !empty($car['total_price_text']) ? $car['total_price_text'] : '要問合せ';
        $year = !empty(trim($car['year'] ?? '')) ? trim($car['year']) : '-';
        $distance = !empty(trim($car['distance'] ?? '')) ? trim($car['distance']) : '-';
        $repair = !empty(trim($car['repair_history'] ?? '')) ? trim($car['repair_history']) : '-';
        $detailUrl = !empty($car['detail_url']) ? $car['detail_url'] : $baseUrl;
        $trackingUrl = "{$baseUrl}/redirect.php?id=" . urlencode($car['id']) . "&src=" . urlencode('LINE 新着入荷配信');

        $inquiryText = "【新着車両問い合わせ】\n車名: {$rawTitle}\n支払総額: {$totalPrice}\n詳細: {$detailUrl}\n\nこちらの新着車両について詳しく知りたいです。";
        if (mb_strlen($inquiryText) > 290) {
            $inquiryText = mb_substr($inquiryText, 0, 290) . '...';
        }

        $bubbles[] = [
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
                    // 新着バッジ
                    [
                        'type' => 'box',
                        'layout' => 'baseline',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => '🆕 新着入荷',
                                'weight' => 'bold',
                                'size' => 'xs',
                                'color' => '#FF8800'
                            ]
                        ]
                    ],
                    // 車名
                    [
                        'type' => 'text',
                        'text' => $shortTitle,
                        'weight' => 'bold',
                        'size' => 'sm',
                        'wrap' => true,
                        'maxLines' => 2,
                        'margin' => 'xs'
                    ],
                    // 価格
                    [
                        'type' => 'box',
                        'layout' => 'baseline',
                        'margin' => 'sm',
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
                    // スペック
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

    $flexMsg = [
        'type' => 'flex',
        'altText' => "【新着入荷】新しい車両が掲載されました！（{$count}台）",
        'contents' => [
            'type' => 'carousel',
            'contents' => $bubbles
        ]
    ];

    return [$textMsg, $flexMsg];
}
