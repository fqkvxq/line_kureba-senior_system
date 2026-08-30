<?php
/**
 * LIFF検索画面向け REST API
 * JSON形式で車両一覧・詳細・フィルター用メタデータを返却します。
 */

require_once __DIR__ . '/config.php';

// CORS & JSONヘッダー
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? 'list';

try {
    $db = getDbConnection();

    switch ($action) {
        // --- 1. 車両一覧取得 (検索・フィルター・ソート) ---
        case 'list':
            $keyword = trim($_GET['keyword'] ?? '');
            $minPrice = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? (float)$_GET['min_price'] : null;
            $maxPrice = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (float)$_GET['max_price'] : null;
            $maxDistance = isset($_GET['max_distance']) && is_numeric($_GET['max_distance']) ? (float)$_GET['max_distance'] : null;
            $sort = $_GET['sort'] ?? 'price_asc'; // price_asc, price_desc, distance_asc, year_desc, updated_desc
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
            $offset = isset($_GET['offset']) ? max((int)$_GET['offset'], 0) : 0;

            $where = ["is_active = 1"];
            $params = [];

            // キーワード検索 (車名・スペック)
            if ($keyword !== '') {
                // 空白で区切ってAND検索
                $keywords = preg_split('/\s+/u', $keyword);
                foreach ($keywords as $idx => $kw) {
                    if ($kw !== '') {
                        $paramKey = ":kw_{$idx}";
                        $where[] = "(title LIKE {$paramKey} OR displacement LIKE {$paramKey} OR year LIKE {$paramKey})";
                        $params[$paramKey] = "%{$kw}%";
                    }
                }
            }

            // 価格フィルター
            if ($minPrice !== null) {
                $where[] = "total_price_num >= :min_price";
                $params[':min_price'] = $minPrice;
            }
            if ($maxPrice !== null) {
                $where[] = "total_price_num <= :max_price";
                $params[':max_price'] = $maxPrice;
            }

            // 走行距離フィルター (万km単位)
            if ($maxDistance !== null) {
                $where[] = "(distance_num IS NULL OR distance_num <= :max_distance)";
                $params[':max_distance'] = $maxDistance;
            }

            $whereSql = implode(' AND ', $where);

            // ソート条件 (全SQLiteバージョン互換)
            $orderSql = match ($sort) {
                'price_desc' => '(total_price_num IS NULL), total_price_num DESC',
                'price_asc' => '(total_price_num IS NULL), total_price_num ASC',
                'distance_asc' => '(distance_num IS NULL), distance_num ASC',
                'year_desc' => 'year DESC',
                'updated_desc' => 'updated_at DESC',
                default => '(total_price_num IS NULL), total_price_num ASC'
            };

            // 件数カウント
            $countStmt = $db->prepare("SELECT COUNT(*) as total FROM cars WHERE {$whereSql}");
            $countStmt->execute($params);
            $totalCount = (int)$countStmt->fetch()['total'];

            // データ取得
            $sql = "SELECT * FROM cars WHERE {$whereSql} ORDER BY {$orderSql} LIMIT :limit OFFSET :offset";
            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $cars = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'total' => $totalCount,
                'count' => count($cars),
                'limit' => $limit,
                'offset' => $offset,
                'cars' => $cars
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 2. 車両詳細取得 ---
        case 'detail':
            $id = $_GET['id'] ?? '';
            if (empty($id)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '車両IDが指定されていません。']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM cars WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $car = $stmt->fetch();

            if (!$car) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '車両が見つかりませんでした。']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'car' => $car
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 3. メタ情報取得 (価格帯範囲、総台数、店舗情報) ---
        case 'meta':
            $stmt = $db->query("
                SELECT 
                    COUNT(*) as total_active_cars,
                    MIN(total_price_num) as min_price,
                    MAX(total_price_num) as max_price,
                    MIN(distance_num) as min_distance,
                    MAX(distance_num) as max_distance,
                    MAX(updated_at) as last_updated
                FROM cars 
                WHERE is_active = 1
            ");
            $stats = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'shop' => [
                    'name' => SHOP_NAME,
                    'code' => SHOP_CODE,
                    'goo_url' => SHOP_GOO_URL
                ],
                'stats' => $stats
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 4. LIFFからの正式問い合わせ受付 & Discord通知 ---
        case 'inquiry':
            $carId = $_POST['id'] ?? ($_GET['id'] ?? '');
            $inquiryType = $_POST['type'] ?? ($_GET['type'] ?? '在庫確認');
            $userId = $_POST['uid'] ?? ($_GET['uid'] ?? '');
            $userName = $_POST['uname'] ?? ($_GET['uname'] ?? '');

            if (empty($carId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '車両IDが必要です']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM cars WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $carId]);
            $car = $stmt->fetch();

            if (!$car) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '車両が見つかりませんでした']);
                exit;
            }

            $userProfile = null;
            if (!empty($userId)) {
                $userProfile = getLineUserProfile($userId);
            }
            if (empty($userProfile) && !empty($userName)) {
                $userProfile = ['displayName' => $userName];
            }

            // Discord 通知送信
            if (function_exists('sendDiscordInquiryNotification')) {
                sendDiscordInquiryNotification($car, $inquiryType, $userProfile, $userId);
            }

            echo json_encode([
                'success' => true,
                'message' => 'お問い合わせを受付いたしました。'
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '無効なアクションです。']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'サーバーエラーが発生しました: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
