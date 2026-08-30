<?php
/**
 * グーネット特定店舗 車両情報スクレイピング & SQLite同期スクリプト (PHP版)
 * XserverのCronで定期実行 (例: 0 6,12,18 * * *) して使用します。
 */

// タイムゾーンとエラー設定
date_default_timezone_set('Asia/Tokyo');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// 設定
$shopCode = '0601492'; // 店舗コード
$baseUrl = "https://www.goo-net.com/usedcar_shop/{$shopCode}/";
$dbFile = __DIR__ . '/cars.db';

echo "[" . date('Y-m-d H:i:s') . "] === グーネット車両データ同期処理を開始します ===\n";
echo "対象店舗コード: {$shopCode}\n";
echo "データベースファイル: {$dbFile}\n";

// データベース接続・初期化
try {
    $db = new PDO("sqlite:{$dbFile}");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
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

// スクレイピング実行
$page = 1;
$allCars = [];
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
                // td[0] は価格枠、td[1]からスペック
                $year = trim(preg_replace('/\s+/', ' ', strip_tags($tds[1][1])));
                $distance = trim(preg_replace('/\s+/', ' ', strip_tags($tds[1][2])));
                $displacement = trim(preg_replace('/\s+/', ' ', strip_tags($tds[1][3])));
                $repairHistory = trim(preg_replace('/\s+/', ' ', strip_tags($tds[1][4])));
                $shaken = trim(preg_replace('/\s+/', ' ', strip_tags($tds[1][5])));

                // 走行距離の数値化 (例: "4.2万km" -> 4.2)
                if (preg_match('/([0-9\.]+)\s*万km/', $distance, $dMatch)) {
                    $distanceNum = (float)$dMatch[1];
                } elseif (preg_match('/([0-9,]+)\s*km/', $distance, $dMatch)) {
                    $distanceNum = (float)str_replace(',', '', $dMatch[1]) / 10000.0;
                }
            }
        }

        $allCars[$carId] = [
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
        $pageCarCount++;
    }

    echo "  -> {$pageCarCount} 台の車両データを抽出しました。\n";

    // 1ページあたりの件数が少ない場合は次ページなしと判定
    if ($pageCarCount < 20) {
        break;
    }
    $page++;
    usleep(500000); // サーバー負荷軽減のため0.5秒ウェイト
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
        INSERT INTO cars (
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
        ON CONFLICT(id) DO UPDATE SET
            title = excluded.title,
            total_price_text = excluded.total_price_text,
            total_price_num = excluded.total_price_num,
            base_price_text = excluded.base_price_text,
            base_price_num = excluded.base_price_num,
            year = excluded.year,
            distance = excluded.distance,
            distance_num = excluded.distance_num,
            displacement = excluded.displacement,
            repair_history = excluded.repair_history,
            shaken = excluded.shaken,
            image_url = excluded.image_url,
            detail_url = excluded.detail_url,
            is_active = 1,
            updated_at = CURRENT_TIMESTAMP
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
}
