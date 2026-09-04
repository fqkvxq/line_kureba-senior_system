<?php
/**
 * REST API エンドポイント (JSON返却)
 * 車両一覧、絞り込み、メタ情報取得、顧客メンテナンス管理をサポート
 */

// タイムゾーンとエラー設定
date_default_timezone_set('Asia/Tokyo');
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 共通設定・DB接続
require_once __DIR__ . '/config.php';

try {
    $db = getDbConnection();
    $action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

    switch ($action) {
        // --- 1. 車両一覧取得 (検索・フィルター・ページネーション) ---
        case 'list':
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;

            $where = ["is_active = 1"];
            $params = [];

            // キーワード検索 (車名, 排気量, 年式)
            if (!empty($_GET['keyword'])) {
                $kw = trim($_GET['keyword']);
                $where[] = "(title LIKE :kw OR displacement LIKE :kw OR year LIKE :kw)";
                $params[':kw'] = "%{$kw}%";
            }

            // 支払総額 (上限)
            if (isset($_GET['max_price']) && is_numeric($_GET['max_price'])) {
                $where[] = "total_price_num <= :max_price";
                $params[':max_price'] = (float)$_GET['max_price'];
            }

            // 走行距離 (上限)
            if (isset($_GET['max_distance']) && is_numeric($_GET['max_distance'])) {
                $where[] = "distance_num <= :max_distance";
                $params[':max_distance'] = (float)$_GET['max_distance'];
            }

            // 修復歴 (0: なし, 1: あり)
            if (isset($_GET['repair'])) {
                if ($_GET['repair'] === 'none') {
                    $where[] = "(repair_history = 'なし' OR repair_history = '-' OR repair_history IS NULL)";
                }
            }

            $whereSql = implode(' AND ', $where);

            // ソート
            $sort = $_GET['sort'] ?? 'price_asc';
            $orderSql = match ($sort) {
                'price_desc' => '(total_price_num IS NULL), total_price_num DESC',
                'distance_asc' => '(distance_num IS NULL), distance_num ASC',
                'year_desc' => 'year DESC',
                default => '(total_price_num IS NULL), total_price_num ASC', // price_asc
            };

            // 総件数カウント
            $countStmt = $db->prepare("SELECT COUNT(*) as total FROM cars WHERE {$whereSql}");
            $countStmt->execute($params);
            $totalCount = (int)$countStmt->fetch()['total'];

            // 車両一覧取得
            $stmt = $db->prepare("
                SELECT * FROM cars 
                WHERE {$whereSql} 
                ORDER BY {$orderSql} 
                LIMIT :limit OFFSET :offset
            ");
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $cars = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit,
                'cars' => $cars
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 2. 車両単体詳細取得 ---
        case 'detail':
            $carId = $_GET['id'] ?? '';
            if (empty($carId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '車両IDが指定されていません。']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM cars WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $carId]);
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

        // --- 5. ユーザー用: 自身の全愛車・メンテナンス情報取得 ---
        case 'get_customer':
            $userId = $_GET['uid'] ?? ($_POST['uid'] ?? '');
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid ORDER BY id ASC");
            $stmt->execute([':uid' => $userId]);
            $cars = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'cars' => $cars,
                'customer' => !empty($cars) ? $cars[0] : null
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 5-2. ユーザー用: 店舗との初期連携（未登録ユーザーの管理画面自動認識） ---
        case 'init_customer_link':
            $userId = trim($_POST['uid'] ?? ($_GET['uid'] ?? ''));
            $userName = trim($_POST['uname'] ?? ($_GET['uname'] ?? ''));

            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }

            // 既存車両を確認
            $checkStmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid LIMIT 1");
            $checkStmt->execute([':uid' => $userId]);
            $existing = $checkStmt->fetch();

            if (!$existing) {
                // 初期連携レコードを登録
                $insertStmt = $db->prepare("
                    INSERT INTO customer_cars (
                        user_id, user_name, car_model, car_number,
                        created_at, updated_at
                    ) VALUES (
                        :uid, :uname, '【未登録】愛車登録待ち', '',
                        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                ");
                $insertStmt->execute([
                    ':uid' => $userId,
                    ':uname' => $userName ?: '新規お客様'
                ]);
                $newCarId = (int)$db->lastInsertId();

                writeDebugLog("店舗初期連携完了", ['uid' => $userId, 'name' => $userName, 'car_id' => $newCarId]);

                // Discordに新規ユーザー登録を通知
                if (function_exists('sendDiscordNewCustomerNotification')) {
                    sendDiscordNewCustomerNotification($userId, $userName);
                }

                // LINEメッセージで連携完了を通知
                if (str_starts_with($userId, 'U')) {
                    $displayName = $userName ?: 'お客様';
                    $welcomeMsg = [
                        'type' => 'text',
                        'text' => "{$displayName} 様\n\n【" . SHOP_NAME . "】愛車点検パスポートとの連携が完了しました！🚗✨\n\n店舗スタッフ側でお客様の愛車や点検予定日（オイル・定期点検・車検）の登録・設定が可能です。\nご自身で登録される場合は、メニューの「愛車点検パスポート」よりいつでもご入力いただけます。"
                    ];
                    try {
                        sendLinePushMessage($userId, [$welcomeMsg]);
                    } catch (Exception $e) {}
                }
            }

            // 最新の車両一覧を取得して返却
            $stmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid ORDER BY id ASC");
            $stmt->execute([':uid' => $userId]);
            $cars = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'message' => '店舗との連携が完了しました！',
                'cars' => $cars,
                'customer' => !empty($cars) ? $cars[0] : null
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 5-3. LIFFトリガー用: サイレントPostback送信実行 ---
        case 'trigger_postback':
            $userId = trim($_POST['uid'] ?? ($_GET['uid'] ?? ''));
            $dataStr = $_POST['data'] ?? ($_GET['data'] ?? '');

            // クエリパラメータから直接組み立てるフォールバック
            if (empty($dataStr)) {
                $postbackParams = $_POST ?: $_GET;
                unset($postbackParams['action']); // 'trigger_postback' 自体を除外
                if (isset($postbackParams['pb_action'])) {
                    $postbackParams['action'] = $postbackParams['pb_action'];
                    unset($postbackParams['pb_action']);
                }
                $dataStr = http_build_query($postbackParams);
            }

            writeDebugLog("api.php trigger_postback 受付", [
                'uid' => $userId,
                'dataStr' => $dataStr,
                'method' => $_SERVER['REQUEST_METHOD']
            ]);

            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です (uid missing)']);
                exit;
            }

            try {
                require_once __DIR__ . '/webhook.php';
                if (!function_exists('executeSilentPostbackPush')) {
                    throw new Exception("executeSilentPostbackPush 関数が見つかりません");
                }
                $success = executeSilentPostbackPush($db, $userId, $dataStr);

                echo json_encode([
                    'success' => $success,
                    'message' => $success ? 'サイレントPostbackを実行しました' : 'Push送信に失敗しました (詳細はwebhook_debug.logを確認)',
                    'uid' => $userId,
                    'data' => $dataStr
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } catch (Throwable $t) {
                writeDebugLog("trigger_postback 例外エラー", ['error' => $t->getMessage(), 'trace' => $t->getTraceAsString()]);
                echo json_encode([
                    'success' => false,
                    'error' => $t->getMessage()
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            break;

        // --- 6. ユーザー用: 愛車の登録・更新 ---
        case 'save_customer':
            $carId = !empty($_POST['car_id']) ? (int)$_POST['car_id'] : null;
            $userId = trim($_POST['uid'] ?? '');
            $userName = trim($_POST['uname'] ?? '');
            $carModel = trim($_POST['car_model'] ?? '');
            $carNumber = trim($_POST['car_number'] ?? '');
            $oilLastDate = !empty($_POST['oil_last_date']) ? $_POST['oil_last_date'] : null;
            $oilNextDate = !empty($_POST['oil_next_date']) ? $_POST['oil_next_date'] : null;
            $periodicInspNextDate = !empty($_POST['periodic_insp_next_date']) ? $_POST['periodic_insp_next_date'] : null;
            $inspectionNextDate = !empty($_POST['inspection_next_date']) ? $_POST['inspection_next_date'] : null;

            if (empty($userId)) {
                $userId = 'USER_' . uniqid();
            }

            writeDebugLog("顧客メンテナンス保存受付 (複数台対応)", [
                'car_id' => $carId,
                'uid' => $userId,
                'name' => $userName,
                'car' => $carModel,
                'oil' => $oilNextDate,
                'periodic' => $periodicInspNextDate,
                'shaken' => $inspectionNextDate
            ]);

            $savedCarId = $carId;
            if ($carId) {
                // 指定車両の更新
                $stmt = $db->prepare("
                    UPDATE customer_cars SET
                        user_name = :uname,
                        car_model = :car_model,
                        car_number = :car_number,
                        oil_last_date = :oil_last_date,
                        oil_next_date = :oil_next_date,
                        periodic_insp_next_date = :periodic_next_date,
                        inspection_next_date = :inspection_next_date,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :car_id AND user_id = :uid
                ");
                $stmt->execute([
                    ':car_id' => $carId,
                    ':uid' => $userId,
                    ':uname' => $userName,
                    ':car_model' => $carModel,
                    ':car_number' => $carNumber,
                    ':oil_last_date' => $oilLastDate,
                    ':oil_next_date' => $oilNextDate,
                    ':periodic_next_date' => $periodicInspNextDate,
                    ':inspection_next_date' => $inspectionNextDate,
                ]);
            } else {
                // 新規車両の追加
                $stmt = $db->prepare("
                    INSERT INTO customer_cars (
                        user_id, user_name, car_model, car_number,
                        oil_last_date, oil_next_date, periodic_insp_next_date, inspection_next_date,
                        created_at, updated_at
                    ) VALUES (
                        :uid, :uname, :car_model, :car_number,
                        :oil_last_date, :oil_next_date, :periodic_next_date, :inspection_next_date,
                        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                ");
                $stmt->execute([
                    ':uid' => $userId,
                    ':uname' => $userName,
                    ':car_model' => $carModel,
                    ':car_number' => $carNumber,
                    ':oil_last_date' => $oilLastDate,
                    ':oil_next_date' => $oilNextDate,
                    ':periodic_next_date' => $periodicInspNextDate,
                    ':inspection_next_date' => $inspectionNextDate,
                ]);
                $savedCarId = (int)$db->lastInsertId();
            }

            // LINEユーザーIDの場合、LINEトークへ登録完了メッセージを送信
            if (str_starts_with($userId, 'U')) {
                $displayName = $userName ?: 'お客様';
                $carDisplay = $carModel . ($carNumber ? " ({$carNumber})" : "");
                $oilDisplay = $oilNextDate ?: '未設定';
                $periodicDisplay = $periodicInspNextDate ?: '未設定';
                $inspDisplay = $inspectionNextDate ?: '未設定';

                $confirmFlex = [
                    'type' => 'flex',
                    'altText' => "【設定保存完了】{$carModel}のメンテナンス予定日を登録・更新しました",
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
                                        ['type' => 'text', 'text' => '✅ 愛車・点検情報の保存完了', 'weight' => 'bold', 'size' => 'sm', 'color' => '#06C755']
                                    ]
                                ],
                                [
                                    'type' => 'text',
                                    'text' => "{$displayName} 様",
                                    'weight' => 'bold',
                                    'size' => 'xl',
                                    'margin' => 'sm',
                                    'color' => '#1e293b'
                                ],
                                [
                                    'type' => 'text',
                                    'text' => "愛車【{$carModel}】のメンテナンス予定日を保存・更新しました！\n予定日が近づきましたら、LINEにてリマインドをお届けします。",
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
                                                ['type' => 'text', 'text' => '愛車', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 4],
                                                ['type' => 'text', 'text' => $carDisplay, 'size' => 'xs', 'weight' => 'bold', 'color' => '#1e293b', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '🛢 オイル交換', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 4],
                                                ['type' => 'text', 'text' => $oilDisplay, 'size' => 'xs', 'weight' => 'bold', 'color' => '#f59e0b', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '📋 12ヶ月点検', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 4],
                                                ['type' => 'text', 'text' => $periodicDisplay, 'size' => 'xs', 'weight' => 'bold', 'color' => '#10b981', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '🚗 車検満了日', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 4],
                                                ['type' => 'text', 'text' => $inspDisplay, 'size' => 'xs', 'weight' => 'bold', 'color' => '#3b82f6', 'flex' => 6]
                                            ]
                                        ]
                                    ]
                                ],
                                [
                                    'type' => 'text',
                                    'text' => "※予定日の変更・2台目以降の登録は、リッチメニューよりいつでも行えます。",
                                    'size' => 'xxs',
                                    'color' => '#64748b',
                                    'margin' => 'md',
                                    'wrap' => true
                                ]
                            ]
                        ]
                    ]
                ];

                require_once __DIR__ . '/webhook.php';
                $confirmFlex['quickReply'] = getQuickReplyItems();

                try {
                    sendLinePushMessage($userId, [$confirmFlex]);
                } catch (Exception $pushErr) {
                    writeDebugLog("LINE Push送信エラー (保存自体は成功)", ['error' => $pushErr->getMessage()]);
                }
            }

            echo json_encode([
                'success' => true,
                'message' => '愛車のメンテナンス情報を保存しました！',
                'car_id' => $savedCarId,
                'customer' => [
                    'id' => $savedCarId,
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'car_model' => $carModel,
                    'car_number' => $carNumber,
                    'oil_next_date' => $oilNextDate,
                    'periodic_insp_next_date' => $periodicInspNextDate,
                    'inspection_next_date' => $inspectionNextDate
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 6-2. ユーザー用: 指定愛車の削除 ---
        case 'delete_customer_car':
            $carId = (int)($_POST['car_id'] ?? 0);
            $userId = trim($_POST['uid'] ?? '');

            if (!$carId || !$userId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '車両IDとユーザーIDが必要です']);
                exit;
            }

            $stmt = $db->prepare("DELETE FROM customer_cars WHERE id = :car_id AND user_id = :uid");
            $stmt->execute([':car_id' => $carId, ':uid' => $userId]);

            echo json_encode(['success' => true, 'message' => '車両を削除しました']);
            break;

        // --- 6-3. ユーザー用: オイル・点検以外の来店希望・相談フォーム送信 ---
        case 'submit_general_inquiry':
            $userId = trim($_POST['uid'] ?? '');
            $userName = trim($_POST['uname'] ?? 'お客様');
            $carModel = trim($_POST['car_model'] ?? '愛車');
            $inquiryType = trim($_POST['inquiry_type'] ?? 'ご来店・ご相談');
            $preferredDate = trim($_POST['preferred_date'] ?? '未指定');
            $preferredTime = trim($_POST['preferred_time'] ?? 'いつでも');
            $details = trim($_POST['details'] ?? '');
            $needLoanCar = trim($_POST['need_loan_car'] ?? '不要');
            $phone = trim($_POST['phone'] ?? '');

            writeDebugLog("一般来店相談フォーム受付", [
                'uid' => $userId,
                'name' => $userName,
                'type' => $inquiryType,
                'car' => $carModel,
                'date' => $preferredDate,
                'time' => $preferredTime,
                'loan_car' => $needLoanCar,
                'details' => $details
            ]);

            // 1. Discord Webhookへ通知
            $webhookUrl = defined('DISCORD_WEBHOOK_URL') ? DISCORD_WEBHOOK_URL : '';
            if (!empty($webhookUrl)) {
                $discordPayload = [
                    'username' => 'アップファーレン 来店予約受付',
                    'avatar_url' => 'https://picture1.goo-net.com/shop/060/0601492/icon/0601492_icon_s.jpg',
                    'embeds' => [
                        [
                            'title' => "🛠️ 【来店・一般ご相談受付】{$inquiryType}",
                            'description' => "マイカー点検パスポートから新しいご来店予約・ご相談が届きました。",
                            'color' => 0xF59E0B, // オレンジ
                            'fields' => [
                                ['name' => '👤 お客様名', 'value' => "{$userName} 様", 'inline' => true],
                                ['name' => '🚗 愛車', 'value' => $carModel, 'inline' => true],
                                ['name' => '🏷️ ご用件', 'value' => $inquiryType, 'inline' => true],
                                ['name' => '📅 ご希望日時', 'value' => "{$preferredDate} ({$preferredTime})", 'inline' => true],
                                ['name' => '🚙 代車希望', 'value' => $needLoanCar, 'inline' => true],
                                ['name' => '📞 電話番号', 'value' => $phone ?: '未入力', 'inline' => true],
                                ['name' => '📝 ご相談・症状詳細', 'value' => $details ? "```\n" . mb_substr($details, 0, 950) . "\n```" : '特に指定なし', 'inline' => false],
                                ['name' => '🆔 LINE UserID', 'value' => "`{$userId}`", 'inline' => false],
                            ],
                            'footer' => ['text' => 'LINE Car Maintenance System'],
                            'timestamp' => date('c')
                        ]
                    ]
                ];

                $ch = curl_init($webhookUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($discordPayload, JSON_UNESCAPED_UNICODE),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false
                ]);
                curl_exec($ch);
                curl_close($ch);
            }

            // 2. LINE Push送信（LINEユーザーの場合）
            if (str_starts_with($userId, 'U')) {
                $confirmFlex = [
                    'type' => 'flex',
                    'altText' => "【受付完了】{$inquiryType}のご来店予約・相談を承りました",
                    'contents' => [
                        'type' => 'bubble',
                        'size' => 'mega',
                        'body' => [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'paddingAll' => '20px',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => '🛠️ ご来店予約・相談の受付完了',
                                    'weight' => 'bold',
                                    'size' => 'sm',
                                    'color' => '#06C755'
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
                                    'text' => "以下の内容でご来店予約・ご相談を承りました！\n店舗スタッフが内容を確認し、LINEトークにて折り返し日程等のご連絡を差し上げます。",
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
                                                ['type' => 'text', 'text' => 'ご用件', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => $inquiryType, 'size' => 'xs', 'weight' => 'bold', 'color' => '#1e293b', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '対象車両', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => $carModel, 'size' => 'xs', 'color' => '#1e293b', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '希望日時', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => "{$preferredDate} ({$preferredTime})", 'size' => 'xs', 'color' => '#e02424', 'flex' => 6]
                                            ]
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '代車希望', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => $needLoanCar, 'size' => 'xs', 'color' => '#1e293b', 'flex' => 6]
                                            ]
                                        ]
                                    ]
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $details ? "【相談内容】\n" . $details : "※何か追加のご要望やお急ぎの用件がございましたら、このままトークにメッセージをお送りください。",
                                    'size' => 'xxs',
                                    'color' => '#64748b',
                                    'margin' => 'md',
                                    'wrap' => true
                                ]
                            ]
                        ]
                    ]
                ];

                require_once __DIR__ . '/webhook.php';
                $confirmFlex['quickReply'] = getQuickReplyItems();

                try {
                    sendLinePushMessage($userId, [$confirmFlex]);
                } catch (Exception $pushErr) {
                    writeDebugLog("LINE Push送信エラー (相談受付自体は成功)", ['error' => $pushErr->getMessage()]);
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'ご来店予約・ご相談を承りました！スタッフより折り返しご連絡いたします。'
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 6. 豆知識・在庫検索等のPostbackアクションをLINEトークへPush送信 ---
        case 'trigger_postback':
            $userId = trim($_POST['uid'] ?? '');
            $postData = trim($_POST['data'] ?? '');

            if (!$userId || !str_starts_with($userId, 'U')) {
                echo json_encode(['success' => true, 'message' => 'ブラウザ環境のためPush送信をスキップしました']);
                break;
            }

            require_once __DIR__ . '/webhook.php';

            try {
                $res = executeSilentPostbackPush($db, $userId, $postData);
                if (is_array($res) && !empty($res['success'])) {
                    echo json_encode(['success' => true, 'message' => 'LINEトークに送信しました']);
                } else {
                    $errMsg = is_array($res) ? ($res['error'] ?: ($res['response'] ?: 'LINE送信エラー')) : '送信処理に失敗しました';
                    echo json_encode(['success' => false, 'error' => $errMsg, 'details' => $res]);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
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
            $filter = $_GET['filter'] ?? 'all'; // all, oil_soon, periodic_soon, inspection_soon

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
            } elseif ($filter === 'periodic_soon') {
                $where[] = "periodic_insp_next_date IS NOT NULL AND periodic_insp_next_date <= :in30";
                $params[':in30'] = $in30days;
            } elseif ($filter === 'inspection_soon') {
                $where[] = "inspection_next_date IS NOT NULL AND inspection_next_date <= :in30";
                $params[':in30'] = $in30days;
            }

            $whereSql = implode(' AND ', $where);
            $stmt = $db->prepare("SELECT * FROM customer_cars WHERE {$whereSql} ORDER BY updated_at DESC");
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

            $carId = !empty($_POST['car_id']) ? (int)$_POST['car_id'] : null;
            $userId = trim($_POST['uid'] ?? '');
            $userName = trim($_POST['uname'] ?? '');
            $carModel = trim($_POST['car_model'] ?? '');
            $carNumber = trim($_POST['car_number'] ?? '');
            $oilLastDate = !empty($_POST['oil_last_date']) ? $_POST['oil_last_date'] : null;
            $oilNextDate = !empty($_POST['oil_next_date']) ? $_POST['oil_next_date'] : null;
            $periodicInspNextDate = !empty($_POST['periodic_insp_next_date']) ? $_POST['periodic_insp_next_date'] : null;
            $inspectionNextDate = !empty($_POST['inspection_next_date']) ? $_POST['inspection_next_date'] : null;
            $staffMemo = trim($_POST['staff_memo'] ?? '');

            if (empty($userId)) {
                $userId = 'MANUAL_' . uniqid();
            }

            if ($carId) {
                $stmt = $db->prepare("
                    UPDATE customer_cars SET
                        user_name = :uname,
                        car_model = :car_model,
                        car_number = :car_number,
                        oil_last_date = :oil_last_date,
                        oil_next_date = :oil_next_date,
                        periodic_insp_next_date = :periodic_next_date,
                        inspection_next_date = :inspection_next_date,
                        staff_memo = :staff_memo,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $carId,
                    ':uname' => $userName,
                    ':car_model' => $carModel,
                    ':car_number' => $carNumber,
                    ':oil_last_date' => $oilLastDate,
                    ':oil_next_date' => $oilNextDate,
                    ':periodic_next_date' => $periodicInspNextDate,
                    ':inspection_next_date' => $inspectionNextDate,
                    ':staff_memo' => $staffMemo
                ]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO customer_cars (
                        user_id, user_name, car_model, car_number,
                        oil_last_date, oil_next_date, periodic_insp_next_date, inspection_next_date,
                        staff_memo, created_at, updated_at
                    ) VALUES (
                        :uid, :uname, :car_model, :car_number,
                        :oil_last_date, :oil_next_date, :periodic_next_date, :inspection_next_date,
                        :staff_memo, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                ");
                $stmt->execute([
                    ':uid' => $userId,
                    ':uname' => $userName,
                    ':car_model' => $carModel,
                    ':car_number' => $carNumber,
                    ':oil_last_date' => $oilLastDate,
                    ':oil_next_date' => $oilNextDate,
                    ':periodic_next_date' => $periodicInspNextDate,
                    ':inspection_next_date' => $inspectionNextDate,
                    ':staff_memo' => $staffMemo
                ]);
            }

            echo json_encode([
                'success' => true,
                'message' => '顧客メンテナンス情報を保存しました！'
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 9. 店舗管理者用: 顧客・車両削除 ---
        case 'admin_delete_customer':
            $authPass = $_POST['password'] ?? '';
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $carId = !empty($_POST['car_id']) ? (int)$_POST['car_id'] : null;
            $userId = trim($_POST['uid'] ?? '');

            writeDebugLog("店舗管理者 顧客・車両削除実行", ['car_id' => $carId, 'uid' => $userId]);

            if ($carId) {
                $stmt = $db->prepare("DELETE FROM customer_cars WHERE id = :id");
                $stmt->execute([':id' => $carId]);
            } elseif (!empty($userId)) {
                $stmt = $db->prepare("DELETE FROM customer_cars WHERE user_id = :uid");
                $stmt->execute([':uid' => $userId]);
            }

            // 旧 customers テーブルが存在していればそちらからも安全に削除
            if (!empty($userId)) {
                try {
                    $stmtLegacy = $db->prepare("DELETE FROM customers WHERE user_id = :uid");
                    $stmtLegacy->execute([':uid' => $userId]);
                } catch (Exception $e) {}
            }

            echo json_encode(['success' => true, 'message' => '削除しました'], JSON_UNESCAPED_UNICODE);
            break;

        // --- 10. 店舗管理者用: 個別手動リマインドLINE送信 ---
        case 'admin_send_reminder':
            $authPass = $_POST['password'] ?? '';
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗']);
                exit;
            }

            $carId = !empty($_POST['car_id']) ? (int)$_POST['car_id'] : null;
            $userId = $_POST['uid'] ?? '';
            $type = $_POST['type'] ?? 'oil'; // oil, periodic, or inspection (shaken)

            if (empty($userId) && empty($carId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDまたは車両IDが必要です']);
                exit;
            }

            if ($carId) {
                $stmt = $db->prepare("SELECT * FROM customer_cars WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $carId]);
            } else {
                $stmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid LIMIT 1");
                $stmt->execute([':uid' => $userId]);
            }
            $cust = $stmt->fetch();

            if (!$cust) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '顧客・車両情報が見つかりませんでした']);
                exit;
            }

            $userId = $cust['user_id'];
            if (!str_starts_with($userId, 'U')) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'この顧客は手動登録（LINE未連携）のため、LINE送信できません']);
                exit;
            }

            $userName = $cust['user_name'] ?: 'お客様';
            $carModel = $cust['car_model'] ?: '愛車';

            if ($type === 'oil') {
                $oilDate = $cust['oil_next_date'] ?: '近日中';
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
                                ['type' => 'text', 'text' => '🛢 オイル交換のお知らせ', 'weight' => 'bold', 'size' => 'sm', 'color' => '#f59e0b'],
                                ['type' => 'text', 'text' => "{$userName} 様", 'weight' => 'bold', 'size' => 'xl', 'margin' => 'sm', 'color' => '#1e293b'],
                                ['type' => 'text', 'text' => "いつも【" . SHOP_NAME . "】をご利用いただきありがとうございます！\n愛車の次回オイル交換予定日をお知らせいたします。", 'size' => 'xs', 'color' => '#475569', 'margin' => 'sm', 'wrap' => true],
                                ['type' => 'separator', 'margin' => 'md'],
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
                                                ['type' => 'text', 'text' => $oilDate, 'size' => 'xs', 'weight' => 'bold', 'color' => '#e02424', 'flex' => 6]
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
                                ['type' => 'text', 'text' => "※目安：走行5,000km〜10,000km、または半年〜1年のどちらか早い方での交換を推奨しております。\nご予約・空き状況のご相談は下のボタンよりお気軽にどうぞ！", 'size' => 'xxs', 'color' => '#64748b', 'margin' => 'md', 'wrap' => true]
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
            } elseif ($type === 'periodic') {
                $inspDate = $cust['periodic_insp_next_date'] ?: '近日中';
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
                                ['type' => 'text', 'text' => '📋 12ヶ月定期点検のご案内', 'weight' => 'bold', 'size' => 'sm', 'color' => '#10b981'],
                                ['type' => 'text', 'text' => "{$userName} 様", 'weight' => 'bold', 'size' => 'xl', 'margin' => 'sm', 'color' => '#1e293b'],
                                ['type' => 'text', 'text' => "いつも【" . SHOP_NAME . "】をご利用いただきありがとうございます！\n愛車【{$carModel}】の法定12ヶ月定期点検の時期をお知らせいたします。", 'size' => 'xs', 'color' => '#475569', 'margin' => 'sm', 'wrap' => true],
                                ['type' => 'separator', 'margin' => 'md'],
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
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'baseline',
                                            'contents' => [
                                                ['type' => 'text', 'text' => '点検の目安', 'color' => '#94a3b8', 'size' => 'xs', 'flex' => 3],
                                                ['type' => 'text', 'text' => "1年に1回 (前回の車検/点検から1年)", 'size' => 'xs', 'color' => '#475569', 'flex' => 6]
                                            ]
                                        ]
                                    ]
                                ],
                                ['type' => 'text', 'text' => "※目安：1年に1回受ける法律で定められた点検です。愛車のコンディション維持や故障の早期発見のため受検をおすすめしております。\nご予約・日程相談は下のボタンよりお気軽にどうぞ！", 'size' => 'xxs', 'color' => '#64748b', 'margin' => 'md', 'wrap' => true]
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
            } else {
                $inspDate = $cust['inspection_next_date'] ?: '未定';
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
                                ['type' => 'text', 'text' => '🚗 車検満了のご案内', 'weight' => 'bold', 'size' => 'sm', 'color' => '#3b82f6'],
                                ['type' => 'text', 'text' => "{$userName} 様", 'weight' => 'bold', 'size' => 'xl', 'margin' => 'sm', 'color' => '#1e293b'],
                                ['type' => 'text', 'text' => "いつも【" . SHOP_NAME . "】をご利用いただきありがとうございます！\n愛車【{$carModel}】の車検満了日が近づいております。", 'size' => 'xs', 'color' => '#475569', 'margin' => 'sm', 'wrap' => true],
                                ['type' => 'separator', 'margin' => 'md'],
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
                                ['type' => 'text', 'text' => "車検満了日の約1ヶ月前より受検が可能です。\n代車の手配や事前お見積もりも承っておりますので、お気軽にご連絡ください！", 'size' => 'xxs', 'color' => '#64748b', 'margin' => 'md', 'wrap' => true]
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
            }

            $res = sendLinePushMessage($userId, [$flexMessage]);
            if (!empty($res['success'])) {
                if ($type === 'oil') {
                    $db->prepare("UPDATE customers SET oil_reminded_at = CURRENT_TIMESTAMP WHERE user_id = :uid")->execute([':uid' => $userId]);
                } elseif ($type === 'periodic') {
                    $db->prepare("UPDATE customers SET periodic_reminded_at = CURRENT_TIMESTAMP WHERE user_id = :uid")->execute([':uid' => $userId]);
                } else {
                    $db->prepare("UPDATE customers SET inspection_reminded_at = CURRENT_TIMESTAMP WHERE user_id = :uid")->execute([':uid' => $userId]);
                }
                echo json_encode(['success' => true, 'message' => "{$userName} 様へLINEリマインドを送信しました！"], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => "LINE送信失敗: " . ($res['error'] ?? 'APIエラー')], JSON_UNESCAPED_UNICODE);
            }
            break;

        // --- 11-2. リッチメニュー画像配信 & LINE自動リカバリ ---
        case 'richmenu_image':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                exit('Rich menu not found');
            }

            // ローカルファイル名を取得
            $imgFileName = basename(parse_url($menu['image_url'], PHP_URL_PATH) ?? '');
            if (empty($imgFileName) || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $imgFileName)) {
                $imgFileName = "rm_{$menu['id']}.jpg";
            }
            $localFilePath = RICHMENU_UPLOAD_DIR . '/' . $imgFileName;

            // 1. ローカルに画像ファイルが存在し中身があれば即座に配信
            if (file_exists($localFilePath) && filesize($localFilePath) > 0) {
                $ext = strtolower(pathinfo($localFilePath, PATHINFO_EXTENSION));
                $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
                header("Content-Type: {$mime}");
                header("Content-Length: " . filesize($localFilePath));
                header("Cache-Control: public, max-age=86400");
                readfile($localFilePath);
                exit;
            }

            // 2. ローカルにない場合、LINE Messaging API から画像バイナリを自動取得・キャッシュ復元
            if (!empty($menu['line_menu_id'])) {
                $imgBinary = lineGetRichMenuImage($menu['line_menu_id']);
                if (!empty($imgBinary)) {
                    if (!is_dir(RICHMENU_UPLOAD_DIR)) {
                        @mkdir(RICHMENU_UPLOAD_DIR, 0777, true);
                    }
                    @file_put_contents($localFilePath, $imgBinary);
                    @chmod($localFilePath, 0666);

                    header("Content-Type: image/jpeg");
                    header("Content-Length: " . strlen($imgBinary));
                    header("Cache-Control: public, max-age=86400");
                    echo $imgBinary;
                    exit;
                }
            }

            // 3. LINE側にもない場合のフォールバック（SVGプレースホルダー）
            header("Content-Type: image/svg+xml");
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="540" viewBox="0 0 800 540"><rect width="800" height="540" fill="#f1f5f9"/><text x="400" y="270" font-family="sans-serif" font-size="28" fill="#94a3b8" text-anchor="middle" dominant-baseline="central">画像準備中</text></svg>';
            exit;

        // --- 11. リッチメニュー管理: 一覧取得 ---
        case 'admin_list_richmenus':
            $authPass = $_POST['password'] ?? ($_GET['password'] ?? '');
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            // LINE公式アカウントの現在のデフォルトリッチメニューIDを取得
            $currentLineDefaultId = lineGetDefaultRichMenuId();

            // 履歴一覧取得
            $stmt = $db->query("SELECT * FROM rich_menus ORDER BY id DESC");
            $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 現在のLINE設定と同期
            foreach ($menus as &$m) {
                $rawAreas = json_decode($m['areas_json'], true) ?: [];
                $cleanAreas = [];
                foreach ($rawAreas as $idx => $ra) {
                    $ra['id'] = !empty($ra['id']) ? $ra['id'] : ($idx + 1);
                    $cleanAreas[] = $ra;
                }
                $m['areas'] = $cleanAreas;
                $m['text_overlays'] = !empty($m['text_overlays_json']) ? (json_decode($m['text_overlays_json'], true) ?: []) : [];
                $m['is_line_default'] = (!empty($m['line_menu_id']) && $m['line_menu_id'] === $currentLineDefaultId);
                // DBのis_activeとLINE実状態の整合性を取る
                if ($m['is_line_default'] && !$m['is_active']) {
                    $db->prepare("UPDATE rich_menus SET is_active = 1 WHERE id = :id")->execute([':id' => $m['id']]);
                    $m['is_active'] = 1;
                } elseif (!$m['is_line_default'] && $m['is_active'] && !empty($currentLineDefaultId)) {
                    $db->prepare("UPDATE rich_menus SET is_active = 0 WHERE id = :id")->execute([':id' => $m['id']]);
                    $m['is_active'] = 0;
                }
                // エイリアス未設定の既存メニューがあれば自動生成＆同期
                if (!empty($m['line_menu_id']) && empty($m['alias_id'])) {
                    $genAlias = 'rm_' . substr(md5($m['line_menu_id']), 0, 20);
                    $reg = lineCreateOrUpdateRichMenuAlias($m['line_menu_id'], $genAlias);
                    if ($reg['success']) {
                        $db->prepare("UPDATE rich_menus SET alias_id = :aid WHERE id = :id")->execute([':aid' => $genAlias, ':id' => $m['id']]);
                        $m['alias_id'] = $genAlias;
                    }
                }
                $m['is_notice'] = (int)($m['is_notice'] ?? 0);

                // ローカル画像ファイルの存在チェック & 存在しなければLINE自動復元URLにフォールバック
                $localFileName = basename(parse_url($m['image_url'], PHP_URL_PATH) ?? '');
                $localFilePath = RICHMENU_UPLOAD_DIR . '/' . $localFileName;
                if (empty($localFileName) || !file_exists($localFilePath) || filesize($localFilePath) === 0) {
                    $m['image_url'] = '../api.php?action=richmenu_image&id=' . $m['id'];
                }

                if (!empty($m['base_image_url'])) {
                    $baseFileName = basename(parse_url($m['base_image_url'], PHP_URL_PATH) ?? '');
                    $baseFilePath = RICHMENU_UPLOAD_DIR . '/' . $baseFileName;
                    if (empty($baseFileName) || !file_exists($baseFilePath) || filesize($baseFilePath) === 0) {
                        $m['base_image_url'] = $m['image_url'];
                    }
                } else {
                    $m['base_image_url'] = $m['image_url'];
                }
            }
            unset($m);

            // 現在有効なお知らせメニューを取得
            $activeNotice = getActiveNoticeRichMenu($db);
            $activeNoticeId = $activeNotice ? (int)$activeNotice['id'] : null;

            echo json_encode([
                'success' => true,
                'menus' => $menus,
                'rich_menus' => $menus,
                'current_default_id' => $currentLineDefaultId,
                'active_notice_id' => $activeNoticeId
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 12. リッチメニュー管理: 作成 & 公開 ---
        case 'admin_save_richmenu':
            $authPass = $_POST['password'] ?? $_GET['password'] ?? '';
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $title = trim($_POST['title'] ?? '');
            if (empty($title)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'メニュー名（管理名）を入力してください']);
                exit;
            }

            $chatBarText = trim($_POST['chat_bar_text'] ?? 'メニュー');
            if (empty($chatBarText)) $chatBarText = 'メニュー';
            $chatBarText = mb_substr($chatBarText, 0, 14);

            $width = (int)($_POST['width'] ?? 2500);
            $height = (int)($_POST['height'] ?? 1686);
            if ($height !== 843 && $height !== 1686) $height = 1686;

            $areasJson = $_POST['areas'] ?? '[]';
            $areas = json_decode($areasJson, true);
            if (!is_array($areas) || empty($areas)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'タップ領域（エリア）が設定されていません']);
                exit;
            }

            $publish = (!empty($_POST['publish']) && $_POST['publish'] === '1');

            // 画像処理
            $uploadedFile = $_FILES['image'] ?? null;
            $existingImageUrl = trim($_POST['existing_image_url'] ?? '');
            $targetFilePath = '';
            $contentType = 'image/jpeg';

            if (!empty($uploadedFile) && $uploadedFile['error'] === UPLOAD_ERR_OK) {
                // 画像検証
                $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => '画像形式はJPGまたはPNGのみ対応しています']);
                    exit;
                }
                $contentType = ($ext === 'png') ? 'image/png' : 'image/jpeg';

                // ファイルサイズ確認 (1MB上限)
                if ($uploadedFile['size'] > 1048576 * 5) { // 5MB超は拒否
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => '画像サイズが大きすぎます (最大5MB)']);
                    exit;
                }

                $fileName = 'rm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . ($ext === 'png' ? 'png' : 'jpg');
                $targetFilePath = RICHMENU_UPLOAD_DIR . '/' . $fileName;

                // GDでリサイズまたはそのまま保存
                $resized = false;
                if (function_exists('imagecreatefromstring') && function_exists('imagecopyresampled')) {
                    $srcData = file_get_contents($uploadedFile['tmp_name']);
                    $srcImg = @imagecreatefromstring($srcData);
                    if ($srcImg !== false) {
                        $origW = imagesx($srcImg);
                        $origH = imagesy($srcImg);
                        $dstImg = imagecreatetruecolor($width, $height);
                        if ($contentType === 'image/png') {
                            imagealphablending($dstImg, false);
                            imagesavealpha($dstImg, true);
                        }
                        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $width, $height, $origW, $origH);
                        if ($contentType === 'image/png') {
                            imagepng($dstImg, $targetFilePath);
                        } else {
                            imagejpeg($dstImg, $targetFilePath, 90);
                        }
                        imagedestroy($srcImg);
                        imagedestroy($dstImg);
                        $resized = true;
                    }
                }
                if (!$resized) {
                    if (!move_uploaded_file($uploadedFile['tmp_name'], $targetFilePath)) {
                        http_response_code(500);
                        echo json_encode(['success' => false, 'error' => '画像ファイルの保存に失敗しました']);
                        exit;
                    }
                }
            } elseif (!empty($existingImageUrl)) {
                // 既存画像の流用
                $relPath = parse_url($existingImageUrl, PHP_URL_PATH);
                $localBase = basename($relPath);
                $candidatePath = RICHMENU_UPLOAD_DIR . '/' . $localBase;
                if (file_exists($candidatePath)) {
                    $targetFilePath = $candidatePath;
                    $ext = strtolower(pathinfo($candidatePath, PATHINFO_EXTENSION));
                    $contentType = ($ext === 'png') ? 'image/png' : 'image/jpeg';
                } else {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => '指定された元画像が見つかりません']);
                    exit;
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'メニュー画像ファイルをアップロードしてください']);
                exit;
            }

            // Web表示用URL
            $savedFileName = basename($targetFilePath);
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}";
            $imageUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/uploads/richmenu/' . $savedFileName;

            // クリーンな元画像（装飾テキストを焼き込んでいないベース画像）の保存処理
            $uploadedBaseFile = $_FILES['base_image'] ?? null;
            $existingBaseImageUrl = trim($_POST['existing_base_image_url'] ?? '');
            $baseImageUrl = '';

            if (!empty($uploadedBaseFile) && $uploadedBaseFile['error'] === UPLOAD_ERR_OK) {
                $bExt = strtolower(pathinfo($uploadedBaseFile['name'], PATHINFO_EXTENSION));
                if (in_array($bExt, ['jpg', 'jpeg', 'png']) && $uploadedBaseFile['size'] <= 1048576 * 5) {
                    $bFileName = 'base_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . ($bExt === 'png' ? 'png' : 'jpg');
                    $bTargetFilePath = RICHMENU_UPLOAD_DIR . '/' . $bFileName;
                    $bResized = false;
                    if (function_exists('imagecreatefromstring') && function_exists('imagecopyresampled')) {
                        $bSrcData = file_get_contents($uploadedBaseFile['tmp_name']);
                        $bSrcImg = @imagecreatefromstring($bSrcData);
                        if ($bSrcImg !== false) {
                            $bOrigW = imagesx($bSrcImg);
                            $bOrigH = imagesy($bSrcImg);
                            $bDstImg = imagecreatetruecolor($width, $height);
                            if ($bExt === 'png') {
                                imagealphablending($bDstImg, false);
                                imagesavealpha($bDstImg, true);
                                imagecopyresampled($bDstImg, $bSrcImg, 0, 0, 0, 0, $width, $height, $bOrigW, $bOrigH);
                                imagepng($bDstImg, $bTargetFilePath);
                            } else {
                                imagecopyresampled($bDstImg, $bSrcImg, 0, 0, 0, 0, $width, $height, $bOrigW, $bOrigH);
                                imagejpeg($bDstImg, $bTargetFilePath, 90);
                            }
                            imagedestroy($bSrcImg);
                            imagedestroy($bDstImg);
                            $baseImageUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/uploads/richmenu/' . $bFileName;
                            $bResized = true;
                        }
                    }
                    if (!$bResized) {
                        if (move_uploaded_file($uploadedBaseFile['tmp_name'], $bTargetFilePath)) {
                            $baseImageUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/uploads/richmenu/' . $bFileName;
                        }
                    }
                }
            } elseif (!empty($existingBaseImageUrl)) {
                $baseImageUrl = $existingBaseImageUrl;
            }

            if (empty($baseImageUrl)) {
                $baseImageUrl = $imageUrl;
            }

            // 1. LINE API用およびDB保存用メタデータ成形
            $lineAreas = [];
            $dbAreas = [];
            foreach ($areas as $idx => $a) {
                $areaId = !empty($a['id']) ? $a['id'] : ($idx + 1);
                $bounds = [
                    'x' => max(0, (int)($a['bounds']['x'] ?? 0)),
                    'y' => max(0, (int)($a['bounds']['y'] ?? 0)),
                    'width' => max(1, (int)($a['bounds']['width'] ?? 100)),
                    'height' => max(1, (int)($a['bounds']['height'] ?? 100))
                ];
                // 境界オーバー防止
                if ($bounds['x'] + $bounds['width'] > $width) {
                    $bounds['width'] = $width - $bounds['x'];
                }
                if ($bounds['y'] + $bounds['height'] > $height) {
                    $bounds['height'] = $height - $bounds['y'];
                }

                $actionType = $a['action']['type'] ?? 'uri';
                $action = ['type' => $actionType];
                if ($actionType === 'uri') {
                    $action['uri'] = trim($a['action']['uri'] ?? 'https://www.goo-net.com');
                } elseif ($actionType === 'postback') {
                    $action['data'] = trim($a['action']['data'] ?? 'action=search_all');
                    if (!empty($a['action']['displayText'])) {
                        $action['displayText'] = trim($a['action']['displayText']);
                    }
                } elseif ($actionType === 'message') {
                    $action['text'] = trim($a['action']['text'] ?? 'メニュー');
                } elseif ($actionType === 'richmenuswitch') {
                    $action['richMenuAliasId'] = trim($a['action']['richMenuAliasId'] ?? '');
                    $action['data'] = trim($a['action']['data'] ?? 'action=richmenu_switched');
                }

                $lineAreas[] = [
                    'bounds' => $bounds,
                    'action' => $action
                ];
                $dbAreas[] = [
                    'id' => $areaId,
                    'is_overlay' => !empty($a['is_overlay']),
                    'bounds' => $bounds,
                    'action' => $action
                ];
            }

            $lineMenuData = [
                'size' => [
                    'width' => $width,
                    'height' => $height
                ],
                'selected' => true,
                'name' => mb_substr($title, 0, 300),
                'chatBarText' => $chatBarText,
                'areas' => $lineAreas
            ];

            // 2. LINE API: リッチメニュー作成
            $createRes = lineCreateRichMenu($lineMenuData);
            if (!$createRes['success'] || empty($createRes['richMenuId'])) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'LINEリッチメニュー作成失敗: ' . ($createRes['error'] ?? '不明なエラー'),
                    'detail' => $createRes['raw'] ?? ''
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $lineMenuId = $createRes['richMenuId'];

            // 3. LINE API: 画像アップロード
            $uploadRes = lineUploadRichMenuImage($lineMenuId, $targetFilePath, $contentType);
            if (!$uploadRes['success']) {
                // ロールバック: 作成したリッチメニューを削除
                lineDeleteRichMenu($lineMenuId);
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'LINEリッチメニュー画像アップロード失敗: ' . ($uploadRes['error'] ?? '不明なエラー')
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $editId = !empty($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
            $existingMenu = null;
            if ($editId > 0) {
                $stmtExist = $db->prepare("SELECT * FROM rich_menus WHERE id = :id");
                $stmtExist->execute([':id' => $editId]);
                $existingMenu = $stmtExist->fetch(PDO::FETCH_ASSOC);
            }

            // 3.5 LINE API: エイリアス登録・更新
            // 既存メニューの編集なら、そのメニューの既存alias_idをそのまま引き継ぐ！
            // これにより、他メニューに設定された切替アクション（richmenuswitch）のエイリアスIDが一切壊れずシームレスに維持されます
            if ($existingMenu && !empty($existingMenu['alias_id'])) {
                $aliasId = $existingMenu['alias_id'];
            } else {
                $aliasId = 'rm_' . substr(md5($lineMenuId), 0, 20);
            }
            lineCreateOrUpdateRichMenuAlias($lineMenuId, $aliasId);

            // 4. LINE API: 本番適用 (publishフラグが真の場合、または既存メニューが元々本番中の場合)
            $isNotice = (!empty($_POST['is_notice']) && $_POST['is_notice'] === '1') ? 1 : ($existingMenu ? (int)$existingMenu['is_notice'] : 0);
            $isActive = 0;
            $applyError = null;

            // 既存メニューが元々本番中だった場合は、更新時に自動で本番も最新メニューへ切り替え
            $shouldApplyLive = $publish || ($existingMenu && (int)$existingMenu['is_active'] === 1 && !$isNotice);

            if ($shouldApplyLive) {
                $setDefRes = lineSetDefaultRichMenu($lineMenuId);
                if ($setDefRes['success']) {
                    $isActive = 1;
                    if ($isNotice) {
                        $db->exec("UPDATE rich_menus SET is_active = 0 WHERE is_notice = 1");
                    } else {
                        $db->exec("UPDATE rich_menus SET is_active = 0 WHERE is_notice = 0");
                    }
                } else {
                    $applyError = $setDefRes['error'] ?? '不明なエラー';
                }
            } elseif ($isNotice) {
                // お知らせ専用メニューとして保存された場合、アクティブお知らせとしてマーク
                $isActive = 1;
                $db->exec("UPDATE rich_menus SET is_active = 0 WHERE is_notice = 1");
            }

            $textOverlaysJson = $_POST['text_overlays'] ?? '[]';
            $textOverlays = json_decode($textOverlaysJson, true);
            if (!is_array($textOverlays)) $textOverlays = [];

            // 5. DBに保存 (既存更新 UPDATE or 新規登録 INSERT)
            if ($existingMenu) {
                $stmt = $db->prepare("
                    UPDATE rich_menus SET
                        line_menu_id = :line_menu_id,
                        alias_id = :alias_id,
                        title = :title,
                        chat_bar_text = :chat_bar_text,
                        image_url = :image_url,
                        base_image_url = :base_image_url,
                        areas_json = :areas_json,
                        text_overlays_json = :text_overlays_json,
                        width = :width,
                        height = :height,
                        is_active = :is_active,
                        is_notice = :is_notice,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':line_menu_id' => $lineMenuId,
                    ':alias_id' => $aliasId,
                    ':title' => $title,
                    ':chat_bar_text' => $chatBarText,
                    ':image_url' => $imageUrl,
                    ':base_image_url' => $baseImageUrl,
                    ':areas_json' => json_encode($dbAreas, JSON_UNESCAPED_UNICODE),
                    ':text_overlays_json' => json_encode($textOverlays, JSON_UNESCAPED_UNICODE),
                    ':width' => $width,
                    ':height' => $height,
                    ':is_active' => $isActive,
                    ':is_notice' => $isNotice,
                    ':id' => $editId
                ]);
                $savedId = $editId;

                // 古いLINEメニューIDをLINE APIから削除して整理
                if (!empty($existingMenu['line_menu_id']) && $existingMenu['line_menu_id'] !== $lineMenuId) {
                    lineDeleteRichMenu($existingMenu['line_menu_id']);
                }

                $msg = 'リッチメニューを上書き保存しました！';
                if ($shouldApplyLive) {
                    $msg = $isActive 
                        ? 'リッチメニューを更新し、LINE本番アカウントに即時反映しました！' 
                        : "リッチメニューは更新されましたが、LINE本番適用でエラーが発生しました: {$applyError}";
                }
            } else {
                $stmt = $db->prepare("
                    INSERT INTO rich_menus (
                        line_menu_id, alias_id, title, chat_bar_text, image_url, base_image_url, areas_json, text_overlays_json,
                        width, height, is_active, is_notice, created_at, updated_at
                    ) VALUES (
                        :line_menu_id, :alias_id, :title, :chat_bar_text, :image_url, :base_image_url, :areas_json, :text_overlays_json,
                        :width, :height, :is_active, :is_notice, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                ");
                $stmt->execute([
                    ':line_menu_id' => $lineMenuId,
                    ':alias_id' => $aliasId,
                    ':title' => $title,
                    ':chat_bar_text' => $chatBarText,
                    ':image_url' => $imageUrl,
                    ':base_image_url' => $baseImageUrl,
                    ':areas_json' => json_encode($dbAreas, JSON_UNESCAPED_UNICODE),
                    ':text_overlays_json' => json_encode($textOverlays, JSON_UNESCAPED_UNICODE),
                    ':width' => $width,
                    ':height' => $height,
                    ':is_active' => $isActive,
                    ':is_notice' => $isNotice
                ]);
                $savedId = (int)$db->lastInsertId();

                $msg = 'リッチメニューを下書きとして新規保存しました！';
                if ($publish) {
                    if ($isActive) {
                        $msg = 'リッチメニューを登録し、LINE本番アカウントに即時適用しました！';
                    } else {
                        $msg = "リッチメニューは保存されましたが、LINE本番適用でエラーが発生しました: {$applyError}";
                    }
                } elseif ($isNotice) {
                    $msg = 'お知らせ専用メニューを登録し、クイックリプライ「📢 お知らせ」のアクティブ対象に設定しました！';
                }
            }

            echo json_encode([
                'success' => true,
                'id' => $savedId,
                'line_menu_id' => $lineMenuId,
                'alias_id' => $aliasId,
                'image_url' => $imageUrl,
                'base_image_url' => $baseImageUrl,
                'is_active' => $isActive,
                'is_notice' => $isNotice,
                'message' => $msg
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 12-2. リッチメニュー管理: お知らせ専用メニューのアクティブ切り替え ---
        case 'admin_set_active_notice':
            $authPass = $_POST['password'] ?? ($_GET['password'] ?? '');
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
            $stmt = $db->prepare("SELECT * FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '指定されたメニューが見つかりません']);
                exit;
            }

            // 他のお知らせメニューのis_activeを0にして、このメニューをis_notice=1 & is_active=1にする
            $db->exec("UPDATE rich_menus SET is_active = 0 WHERE is_notice = 1");
            $db->prepare("UPDATE rich_menus SET is_notice = 1, is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute([':id' => $id]);

            echo json_encode([
                'success' => true,
                'message' => "「{$menu['title']}」を現在のアクティブなお知らせメニューに設定しました！LINEのクイックリプライ「📢 お知らせ」を押すとこのメニューが表示されます。"
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 13. リッチメニュー管理: 本番適用切り替え ---
        case 'admin_apply_richmenu':
            $authPass = $_POST['password'] ?? '';
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu || empty($menu['line_menu_id'])) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '対象のリッチメニューが見つかりません']);
                exit;
            }

            $setRes = lineSetDefaultRichMenu($menu['line_menu_id']);
            if (!$setRes['success']) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'LINEデフォルト設定エラー: ' . ($setRes['error'] ?? '')]);
                exit;
            }

            // DB更新
            $db->exec("UPDATE rich_menus SET is_active = 0");
            $db->prepare("UPDATE rich_menus SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute([':id' => $id]);

            echo json_encode([
                'success' => true,
                'message' => "「{$menu['title']}」をLINE公式アカウントの本番リッチメニューに適用しました！"
            ], JSON_UNESCAPED_UNICODE);
            break;

        // --- 14. リッチメニュー管理: 削除 ---
        case 'admin_delete_richmenu':
            $authPass = $_POST['password'] ?? '';
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $id = (int)($_POST['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '対象のリッチメニューが見つかりません']);
                exit;
            }

            // LINE側から削除
            if (!empty($menu['line_menu_id'])) {
                lineDeleteRichMenu($menu['line_menu_id']);
            }
            if (!empty($menu['alias_id'])) {
                lineDeleteRichMenuAlias($menu['alias_id']);
            }

            // 画像ファイルの削除
            if (!empty($menu['image_url'])) {
                $baseName = basename(parse_url($menu['image_url'], PHP_URL_PATH));
                $localPath = RICHMENU_UPLOAD_DIR . '/' . $baseName;
                if (file_exists($localPath)) {
                    @unlink($localPath);
                }
            }

            // DBから削除
            $db->prepare("DELETE FROM rich_menus WHERE id = :id")->execute([':id' => $id]);

            echo json_encode([
                'success' => true,
                'message' => "リッチメニュー「{$menu['title']}」を削除しました。"
            ], JSON_UNESCAPED_UNICODE);
            break;

        // --- 15. リッチメニュー管理: 管理名（タイトル）変更 ---
        case 'admin_rename_richmenu':
            $authPass = $_POST['password'] ?? '';
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $id = (int)($_POST['id'] ?? 0);
            $newTitle = trim($_POST['title'] ?? '');
            if (empty($newTitle)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'リッチメニュー名を入力してください']);
                exit;
            }

            $stmt = $db->prepare("SELECT id, title FROM rich_menus WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$menu) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => '対象のリッチメニューが見つかりません']);
                exit;
            }

            $updateStmt = $db->prepare("UPDATE rich_menus SET title = :title, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $updateStmt->execute([
                ':title' => $newTitle,
                ':id' => $id
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'リッチメニュー名を変更しました',
                'id' => $id,
                'title' => $newTitle
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 16. 特定ユーザー向け個別リッチメニュー適用 ---
        case 'admin_set_user_custom_richmenu':
            $authPass = $_POST['password'] ?? '';
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $userId = trim($_POST['uid'] ?? '');
            if (empty($userId) || str_starts_with($userId, 'MANUAL_')) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'LINE未連携の顧客にはリッチメニューを適用できません（友だち追加後のUIDが必要です）']);
                exit;
            }

            $customText = trim($_POST['custom_text'] ?? '');
            $baseMenuId = (int)($_POST['base_menu_id'] ?? 0);

            // ベースとなるリッチメニューを取得
            $baseMenu = null;
            if ($baseMenuId > 0) {
                $stmt = $db->prepare("SELECT * FROM rich_menus WHERE id = :id");
                $stmt->execute([':id' => $baseMenuId]);
                $baseMenu = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$baseMenu) {
                // デフォルトとして現在本番中の通常メニューを取得
                $stmt = $db->query("SELECT * FROM rich_menus WHERE is_active = 1 AND is_notice = 0 ORDER BY id DESC LIMIT 1");
                $baseMenu = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$baseMenu) {
                // さらに無ければ最新メニューを取得
                $stmt = $db->query("SELECT * FROM rich_menus ORDER BY id DESC LIMIT 1");
                $baseMenu = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$baseMenu) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ベースとなるリッチメニューが存在しません。先にリッチメニューを作成してください。']);
                exit;
            }

            // 合成画像の受信・保存
            $uploadedFile = $_FILES['image'] ?? null;
            if (empty($uploadedFile) || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => '合成画像のアップロードに失敗しました']);
                exit;
            }

            $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $ext = 'jpg';
            }
            $contentType = ($ext === 'png') ? 'image/png' : 'image/jpeg';
            $fileName = 'custom_' . substr(md5($userId), 0, 10) . '_' . date('Ymd_His') . '.' . $ext;
            $targetFilePath = RICHMENU_UPLOAD_DIR . '/' . $fileName;

            if (!move_uploaded_file($uploadedFile['tmp_name'], $targetFilePath)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => '画像ファイルの保存に失敗しました']);
                exit;
            }

            // ベースメニューのエリア設定を引き継ぐ（LINEサーバー実データを最優先）
            $rawAreas = [];

            // 優先順位1: ベースメニューの line_menu_id から LINEサーバー上の検証済み実データを直接取得
            $lineMenuIdToFetch = !empty($baseMenu['line_menu_id']) ? $baseMenu['line_menu_id'] : ($_POST['base_line_menu_id'] ?? '');
            if (!empty($lineMenuIdToFetch)) {
                $lineRemote = lineGetRichMenu($lineMenuIdToFetch);
                if (!empty($lineRemote['areas']) && is_array($lineRemote['areas']) && count($lineRemote['areas']) > 0) {
                    $rawAreas = $lineRemote['areas'];
                }
            }

            // 優先順位2: 現在のLINE全体デフォルトリッチメニューから実データを取得
            if (empty($rawAreas)) {
                $currentDefId = lineGetDefaultRichMenuId();
                if (!empty($currentDefId)) {
                    $lineRemote = lineGetRichMenu($currentDefId);
                    if (!empty($lineRemote['areas']) && is_array($lineRemote['areas']) && count($lineRemote['areas']) > 0) {
                        $rawAreas = $lineRemote['areas'];
                    }
                }
            }

            // 優先順位3: DBの areas_json
            if (empty($rawAreas) && !empty($baseMenu['areas_json'])) {
                $dbJson = json_decode($baseMenu['areas_json'], true);
                if (is_array($dbJson) && count($dbJson) > 0) {
                    $rawAreas = $dbJson;
                }
            }

            // 優先順位4: クライアントから送信された base_areas
            if (empty($rawAreas) && !empty($_POST['base_areas'])) {
                $posted = json_decode($_POST['base_areas'], true);
                if (is_array($posted) && count($posted) > 0) {
                    $rawAreas = $posted;
                }
            }

            $width = (int)($baseMenu['width'] ?? 2500);
            $height = (int)($baseMenu['height'] ?? 1686);
            $lineAreas = [];

            foreach ($rawAreas as $a) {
                if (empty($a['bounds']) || empty($a['action'])) continue;

                $bounds = [
                    'x' => max(0, (int)($a['bounds']['x'] ?? 0)),
                    'y' => max(0, (int)($a['bounds']['y'] ?? 0)),
                    'width' => max(1, (int)($a['bounds']['width'] ?? 100)),
                    'height' => max(1, (int)($a['bounds']['height'] ?? 100))
                ];
                if ($bounds['x'] + $bounds['width'] > $width) {
                    $bounds['width'] = $width - $bounds['x'];
                }
                if ($bounds['y'] + $bounds['height'] > $height) {
                    $bounds['height'] = $height - $bounds['y'];
                }

                $act = $a['action'];
                $actionType = $act['type'] ?? 'postback';
                $cleanAction = ['type' => $actionType];

                if ($actionType === 'uri') {
                    $cleanAction['uri'] = trim($act['uri'] ?? 'https://www.goo-net.com');
                    if (!empty($act['label'])) $cleanAction['label'] = $act['label'];
                } elseif ($actionType === 'postback') {
                    $cleanAction['data'] = trim($act['data'] ?? 'action=search_all');
                    if (!empty($act['displayText'])) {
                        $cleanAction['displayText'] = trim($act['displayText']);
                    }
                    if (!empty($act['label'])) $cleanAction['label'] = $act['label'];
                } elseif ($actionType === 'message') {
                    $cleanAction['text'] = trim($act['text'] ?? 'メニュー');
                    if (!empty($act['label'])) $cleanAction['label'] = $act['label'];
                } elseif ($actionType === 'richmenuswitch') {
                    $alias = trim($act['richMenuAliasId'] ?? '');
                    if (!empty($alias)) {
                        $cleanAction['richMenuAliasId'] = $alias;
                        $cleanAction['data'] = trim($act['data'] ?? 'action=richmenu_switched');
                    } else {
                        $cleanAction = ['type' => 'postback', 'data' => 'action=search_all', 'displayText' => 'メニュー切り替え'];
                    }
                } else {
                    $cleanAction = $act;
                }

                $lineAreas[] = [
                    'bounds' => $bounds,
                    'action' => $cleanAction
                ];
            }

            // 万が一エリアが0件の場合は空メニューの作成を阻止
            if (empty($lineAreas)) {
                @unlink($targetFilePath);
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'ベースメニューのボタン設定（タップ領域）が検出できませんでした。リッチメニュー管理でメニューにボタン枠が設定されているかご確認ください。'
                ]);
                exit;
            }

            writeDebugLog("個別専用リッチメニュー作成開始", [
                'userId' => $userId,
                'areasCount' => count($lineAreas),
                'firstArea' => $lineAreas[0] ?? null
            ]);

            // 顧客名を取得
            $stmtCust = $db->prepare("SELECT user_name, custom_line_menu_id FROM customer_cars WHERE user_id = :uid LIMIT 1");
            $stmtCust->execute([':uid' => $userId]);
            $custRow = $stmtCust->fetch(PDO::FETCH_ASSOC);
            $custName = $custRow['user_name'] ?? 'お客様';
            $oldLineMenuId = $custRow['custom_line_menu_id'] ?? '';

            // LINE API: 個別リッチメニュー作成
            $lineMenuData = [
                'size' => [
                    'width' => $width,
                    'height' => $height
                ],
                'selected' => true,
                'name' => mb_substr("【専用】{$custName}様 " . date('m/d H:i'), 0, 300),
                'chatBarText' => mb_substr($baseMenu['chat_bar_text'] ?: 'メニュー', 0, 14),
                'areas' => $lineAreas
            ];

            $createRes = lineCreateRichMenu($lineMenuData);
            if (!$createRes['success'] || empty($createRes['richMenuId'])) {
                @unlink($targetFilePath);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'LINEリッチメニュー作成失敗: ' . ($createRes['error'] ?? '')]);
                exit;
            }
            $newLineMenuId = $createRes['richMenuId'];

            // LINE API: 画像アップロード
            $uploadRes = lineUploadRichMenuImage($newLineMenuId, $targetFilePath, $contentType);
            if (!$uploadRes['success']) {
                lineDeleteRichMenu($newLineMenuId);
                @unlink($targetFilePath);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'LINE画像アップロード失敗: ' . ($uploadRes['error'] ?? '')]);
                exit;
            }

            // LINE API: ユーザーへ個別リンク実行！
            $linkRes = lineLinkUserRichMenu($userId, $newLineMenuId);
            if (!$linkRes['success']) {
                lineDeleteRichMenu($newLineMenuId);
                @unlink($targetFilePath);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'ユーザーへの個別メニュー割当失敗: ' . ($linkRes['error'] ?? '')]);
                exit;
            }

            // 以前の古い個別メニューがあれば削除
            if (!empty($oldLineMenuId) && $oldLineMenuId !== $newLineMenuId) {
                lineDeleteRichMenu($oldLineMenuId);
            }

            // DB更新
            $db->prepare("
                UPDATE customer_cars SET
                    custom_line_menu_id = :lmid,
                    custom_menu_text = :txt,
                    custom_menu_set_at = CURRENT_TIMESTAMP
                WHERE user_id = :uid
            ")->execute([
                ':lmid' => $newLineMenuId,
                ':txt' => $customText,
                ':uid' => $userId
            ]);

            $buttonSummaries = [];
            foreach ($lineAreas as $idx => $la) {
                $type = $la['action']['type'] ?? 'unknown';
                $detail = $la['action']['data'] ?? ($la['action']['uri'] ?? ($la['action']['text'] ?? ''));
                $buttonSummaries[] = "枠" . ($idx + 1) . " [{$type}: {$detail}]";
            }

            echo json_encode([
                'success' => true,
                'message' => "「{$custName}」様に専用メッセージ付きリッチメニューを適用しました！（ボタン" . count($lineAreas) . "個を正常に引き継ぎ）",
                'buttons' => $buttonSummaries,
                'areas_count' => count($lineAreas),
                'custom_line_menu_id' => $newLineMenuId,
                'custom_menu_text' => $customText
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        // --- 17. 特定ユーザーの個別リッチメニュー解除（全体共通メニューへ戻す） ---
        case 'admin_unlink_user_richmenu':
            $authPass = $_POST['password'] ?? '';
            if ($authPass !== ADMIN_PASSWORD) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => '認証失敗: パスワードが違います']);
                exit;
            }

            $userId = trim($_POST['uid'] ?? '');
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ユーザーIDが必要です']);
                exit;
            }

            // 既存の個別メニューIDを取得
            $stmtCust = $db->prepare("SELECT user_name, custom_line_menu_id FROM customer_cars WHERE user_id = :uid LIMIT 1");
            $stmtCust->execute([':uid' => $userId]);
            $custRow = $stmtCust->fetch(PDO::FETCH_ASSOC);
            $custName = $custRow['user_name'] ?? 'お客様';
            $oldLineMenuId = $custRow['custom_line_menu_id'] ?? '';

            // LINE API: 個別紐付け解除
            $unlinkRes = lineUnlinkUserRichMenu($userId);

            // 古いLINEメニューを削除
            if (!empty($oldLineMenuId)) {
                lineDeleteRichMenu($oldLineMenuId);
            }

            // DB更新
            $db->prepare("
                UPDATE customer_cars SET
                    custom_line_menu_id = '',
                    custom_menu_text = '',
                    custom_menu_set_at = NULL
                WHERE user_id = :uid
            ")->execute([':uid' => $userId]);

            echo json_encode([
                'success' => true,
                'message' => "「{$custName}」様の個別リッチメニューを解除し、全体共通メニューに戻しました！"
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
        'error' => 'サーバー内部エラーが発生しました: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
