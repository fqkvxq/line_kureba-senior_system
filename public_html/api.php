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

        // --- 5. ユーザー用: 自身のメンテナンス情報取得 ---
        case 'get_customer':
            $userId = $_GET['uid'] ?? ($_POST['uid'] ?? '');
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM customers WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $customer = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'customer' => $customer ?: null
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 6. ユーザー用: 自身の愛車・オイル交換日保存 ---
        case 'save_customer':
            $userId = $_POST['uid'] ?? '';
            $userName = $_POST['uname'] ?? '';
            $carModel = $_POST['car_model'] ?? '';
            $carNumber = $_POST['car_number'] ?? '';
            $oilLastDate = $_POST['oil_last_date'] ?? null;
            $oilNextDate = $_POST['oil_next_date'] ?? null;
            $inspectionNextDate = $_POST['inspection_next_date'] ?? null;

            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }

            // 日付が空文字ならNULLに
            $oilLastDate = !empty($oilLastDate) ? $oilLastDate : null;
            $oilNextDate = !empty($oilNextDate) ? $oilNextDate : null;
            $inspectionNextDate = !empty($inspectionNextDate) ? $inspectionNextDate : null;

            $stmt = $db->prepare("
                INSERT OR REPLACE INTO customers (
                    user_id, user_name, car_model, car_number,
                    oil_last_date, oil_next_date, inspection_next_date,
                    updated_at
                ) VALUES (
                    :uid, :uname, :car_model, :car_number,
                    :oil_last_date, :oil_next_date, :inspection_next_date,
                    CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                ':uid' => $userId,
                ':uname' => $userName,
                ':car_model' => $carModel,
                ':car_number' => $carNumber,
                ':oil_last_date' => $oilLastDate,
                ':oil_next_date' => $oilNextDate,
                ':inspection_next_date' => $inspectionNextDate,
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'メンテナンス情報を保存しました！'
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 7. 店舗管理者用: 顧客一覧取得 ---
        case 'admin_list_customers':
            $authPass = $_POST['password'] ?? ($_GET['password'] ?? '');
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'パスワードが違います']);
                exit;
            }

            $search = trim($_GET['search'] ?? '');
            $filter = $_GET['filter'] ?? 'all'; // all, oil_soon, inspection_soon

            $where = ["1 = 1"];
            $params = [];

            if (!empty($search)) {
                $where[] = "(user_name LIKE :s OR car_model LIKE :s OR car_number LIKE :s OR staff_memo LIKE :s)";
                $params[':s'] = "%{$search}%";
            }

            $today = date('Y-m-d');
            $in30days = date('Y-m-d', strtotime('+30 days'));

            if ($filter === 'oil_soon') {
                $where[] = "oil_next_date IS NOT NULL AND oil_next_date <= :in30";
                $params[':in30'] = $in30days;
            } elseif ($filter === 'inspection_soon') {
                $where[] = "inspection_next_date IS NOT NULL AND inspection_next_date <= :in30";
                $params[':in30'] = $in30days;
            }

            $whereSql = implode(' AND ', $where);
            $stmt = $db->prepare("SELECT * FROM customers WHERE {$whereSql} ORDER BY updated_at DESC");
            $stmt->execute($params);
            $customers = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'customers' => $customers,
                'total' => count($customers)
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 8. 店舗管理者用: 顧客情報の登録・編集 ---
        case 'admin_save_customer':
            $authPass = $_POST['password'] ?? '';
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $userId = trim($_POST['uid'] ?? '');
            $userName = trim($_POST['uname'] ?? '');
            $carModel = trim($_POST['car_model'] ?? '');
            $carNumber = trim($_POST['car_number'] ?? '');
            $oilLastDate = !empty($_POST['oil_last_date']) ? $_POST['oil_last_date'] : null;
            $oilNextDate = !empty($_POST['oil_next_date']) ? $_POST['oil_next_date'] : null;
            $inspectionNextDate = !empty($_POST['inspection_next_date']) ? $_POST['inspection_next_date'] : null;
            $staffMemo = trim($_POST['staff_memo'] ?? '');

            if (empty($userId)) {
                // 新規手動登録などでuserIdがない場合は生成
                $userId = 'MANUAL_' . uniqid();
            }

            $stmt = $db->prepare("
                INSERT OR REPLACE INTO customers (
                    user_id, user_name, car_model, car_number,
                    oil_last_date, oil_next_date, inspection_next_date,
                    staff_memo, updated_at
                ) VALUES (
                    :uid, :uname, :car_model, :car_number,
                    :oil_last_date, :oil_next_date, :inspection_next_date,
                    :staff_memo, CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                ':uid' => $userId,
                ':uname' => $userName,
                ':car_model' => $carModel,
                ':car_number' => $carNumber,
                ':oil_last_date' => $oilLastDate,
                ':oil_next_date' => $oilNextDate,
                ':inspection_next_date' => $inspectionNextDate,
                ':staff_memo' => $staffMemo
            ]);

            echo json_encode([
                'success' => true,
                'message' => '顧客メンテナンス情報を保存しました！'
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 9. 店舗管理者用: 顧客削除 ---
        case 'admin_delete_customer':
            $authPass = $_POST['password'] ?? '';
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $userId = $_POST['uid'] ?? '';
            if (!empty($userId)) {
                $stmt = $db->prepare("DELETE FROM customers WHERE user_id = :uid");
                $stmt->execute([':uid' => $userId]);
            }

            echo json_encode(['success' => true, 'message' => '削除しました'], JSON_UNESCAPED_UNICODE);
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
        'error' => 'サーバー内部エラーが発生しました: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
