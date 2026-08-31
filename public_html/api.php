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
