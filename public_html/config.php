<?php
/**
 * システム共通設定ファイル
 * Xserver環境およびLINE公式アカウント、Discord通知、顧客メンテナンス管理の設定を管理します。
 */

// タイムゾーン設定
date_default_timezone_set('Asia/Tokyo');

// --- LINE公式アカウント設定 ---
define('LINE_CHANNEL_ACCESS_TOKEN', 'JixCe0rnnP4omxlVgYbU3aC0As5sUV7mZtwmgLNULVePqlCEfdr85oAzjWoMacmU++aNCQmSNyDwR70g9JkOyZK9AU5M0gkdttBHYWCcXacUQxavZuw4ftsuDATXEHlN+lVPuxTEcDi0I8N5xYMsfQdB04t89/1O/w1cDnyilFU='); // チャネルアクセストークン (長期)
define('LINE_CHANNEL_SECRET', 'a94b53929e0ab8e1c1f183b54a6cc695');             // チャネルシークレット
define('LINE_LIFF_ID', '2011340718-OaRM8tV4');                           // LIFF ID (例: 1234567890-AbcdEfgh)
define('LIFF_ID', '2011340718-OaRM8tV4');                                // エイリアス用LIFF ID

// --- 新着車両の自動配信設定 ---
define('ENABLE_NEW_CAR_BROADCAST', true); // 新着検知時にLINE公式アカウントの友だち全員へ自動一斉配信するか (true: 送信する, false: 送信しない)
define('ENABLE_NEW_CAR_DISCORD', true);   // 新着検知時にDiscordへ通知するか

// --- 店舗管理画面設定 ---
define('ADMIN_PASSWORD', '1020143'); // 店舗用管理画面（/admin/）のログインパスワード

// --- Discord 通知設定 ---
define('DISCORD_WEBHOOK_URL', 'https://discord.com/api/webhooks/1543636005582667776/8hnE-kLsB545xgS923mTvgIUaBuTz8TQQLrJXFvqB-A0oh92LmqC8Zn-1jaOIhW20YEZ');

// --- 店舗・システム設定 ---
define('SHOP_CODE', '0601492');
define('SHOP_NAME', 'アップファーレン');
define('SHOP_GOO_URL', 'https://www.goo-net.com/usedcar_shop/0601492/stock.html');

// --- リッチメニュー画像保存ディレクトリ ---
define('RICHMENU_UPLOAD_DIR', __DIR__ . '/uploads/richmenu');
if (!is_dir(RICHMENU_UPLOAD_DIR)) {
    @mkdir(RICHMENU_UPLOAD_DIR, 0777, true);
}
@chmod(RICHMENU_UPLOAD_DIR, 0777);

/**
 * データベースファイルのパスを自動検出
 */
function getDbFilePath(): string {
    $candidates = [
        __DIR__ . '/batch/cars.db',
        __DIR__ . '/../batch/cars.db',
        __DIR__ . '/cars.db',
        __DIR__ . '/../../batch/cars.db',
        dirname(__DIR__) . '/batch/cars.db'
    ];

    foreach ($candidates as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    // デフォルト
    return __DIR__ . '/batch/cars.db';
}

define('DB_PATH', getDbFilePath());

/**
 * データベース接続オブジェクト (PDO) を取得
 * @return PDO
 */
function getDbConnection(): PDO {
    $dbFile = DB_PATH;
    $dir = dirname($dbFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @chmod($dir, 0777);
    if (file_exists($dbFile)) {
        @chmod($dbFile, 0666);
    }

    $pdo = new PDO("sqlite:{$dbFile}", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 15
    ]);

    // WALモードで同時読み書きロックを防止
    try {
        $pdo->exec("PRAGMA journal_mode = WAL");
        $pdo->exec("PRAGMA busy_timeout = 5000");
    } catch (Exception $e) {}

    // 複数台対応: customer_cars テーブルの初期化
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_cars (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            user_name TEXT,
            car_model TEXT NOT NULL,
            car_number TEXT,
            oil_last_date DATE,
            oil_next_date DATE,
            periodic_insp_next_date DATE,
            inspection_next_date DATE,
            staff_memo TEXT,
            oil_reminded_at DATETIME,
            periodic_reminded_at DATETIME,
            inspection_reminded_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // インデックス作成
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_user_id ON customer_cars(user_id)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_oil_next ON customer_cars(oil_next_date)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_periodic_next ON customer_cars(periodic_insp_next_date)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_inspection_next ON customer_cars(inspection_next_date)"); } catch (Exception $e) {}

    // リッチメニュー履歴管理テーブルの初期化
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rich_menus (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            line_menu_id TEXT,
            title TEXT NOT NULL,
            chat_bar_text TEXT DEFAULT 'メニュー',
            image_url TEXT NOT NULL,
            base_image_url TEXT DEFAULT '',
            areas_json TEXT NOT NULL,
            text_overlays_json TEXT DEFAULT '[]',
            width INTEGER DEFAULT 2500,
            height INTEGER DEFAULT 1686,
            is_active INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rich_menus_active ON rich_menus(is_active)"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE rich_menus ADD COLUMN text_overlays_json TEXT DEFAULT '[]'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE rich_menus ADD COLUMN base_image_url TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE rich_menus ADD COLUMN alias_id TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE rich_menus ADD COLUMN is_notice INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rich_menus_notice ON rich_menus(is_notice)"); } catch (Exception $e) {}

    // 既存 customers テーブルからのデータ移行（初回1回のみ）
    try {
        $countCars = $pdo->query("SELECT COUNT(*) FROM customer_cars")->fetchColumn();
        if ($countCars == 0) {
            $hasLegacy = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='customers'")->fetch();
            if ($hasLegacy) {
                $legacyRows = $pdo->query("SELECT * FROM customers WHERE car_model IS NOT NULL AND car_model != ''")->fetchAll();
                $insertStmt = $pdo->prepare("
                    INSERT INTO customer_cars (
                        user_id, user_name, car_model, car_number,
                        oil_last_date, oil_next_date, periodic_insp_next_date, inspection_next_date,
                        staff_memo, oil_reminded_at, periodic_reminded_at, inspection_reminded_at,
                        created_at, updated_at
                    ) VALUES (
                        :user_id, :user_name, :car_model, :car_number,
                        :oil_last_date, :oil_next_date, :periodic_insp_next_date, :inspection_next_date,
                        :staff_memo, :oil_reminded_at, :periodic_reminded_at, :inspection_reminded_at,
                        :created_at, :updated_at
                    )
                ");
                foreach ($legacyRows as $r) {
                    $insertStmt->execute([
                        ':user_id' => $r['user_id'],
                        ':user_name' => $r['user_name'] ?? '',
                        ':car_model' => $r['car_model'],
                        ':car_number' => $r['car_number'] ?? '',
                        ':oil_last_date' => $r['oil_last_date'] ?? null,
                        ':oil_next_date' => $r['oil_next_date'] ?? null,
                        ':periodic_insp_next_date' => $r['periodic_insp_next_date'] ?? null,
                        ':inspection_next_date' => $r['inspection_next_date'] ?? null,
                        ':staff_memo' => $r['staff_memo'] ?? null,
                        ':oil_reminded_at' => $r['oil_reminded_at'] ?? null,
                        ':periodic_reminded_at' => $r['periodic_reminded_at'] ?? null,
                        ':inspection_reminded_at' => $r['inspection_reminded_at'] ?? null,
                        ':created_at' => $r['created_at'] ?? date('Y-m-d H:i:s'),
                        ':updated_at' => $r['updated_at'] ?? date('Y-m-d H:i:s')
                    ]);
                }
            }
        }
    } catch (Exception $e) {}

    return $pdo;
}

/**
 * 現在のベースURLを自動取得 (LINE Flex Message用に必ず https:// を保証)
 */
function getBaseUrl(): string {
    $protocol = "https://";
    $host = $_SERVER['HTTP_HOST'] ?? 'kureba.co.jp';
    
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDir = str_replace('\\', '/', $scriptDir);
    if ($scriptDir === '/' || $scriptDir === '.') {
        $scriptDir = '';
    }
    
    $url = $protocol . $host . $scriptDir;
    return rtrim($url, '/');
}

/**
 * LINEユーザーのプロフィール情報（表示名・アイコン）を取得
 */
function getLineUserProfile(string $userId): ?array {
    if (empty($userId) || LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return null;
    }

    $url = "https://api.line.me/v2/bot/profile/" . urlencode($userId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($res)) {
        return json_decode($res, true);
    }

    return null;
}

/**
 * 特定のユーザーへ個別プッシュ送信 (Push Message API)
 */
function sendLinePushMessage(string $userId, array $messages): array {
    if (empty($userId) || LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'Token or userId missing'];
    }

    $url = 'https://api.line.me/v2/bot/message/push';
    $payload = [
        'to' => $userId,
        'messages' => $messages
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
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

    writeDebugLog("LINE個別Push送信結果", [
        'userId' => $userId,
        'httpCode' => $httpCode,
        'response' => $res,
        'curlError' => $curlErr
    ]);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'response' => $res,
        'error' => $curlErr
    ];
}

/**
 * LINE公式アカウントの友だち全員へメッセージを一斉送信 (Broadcast API)
 */
function sendLineBroadcastMessage(array $messages): array {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        writeDebugLog("一斉配信スキップ: LINE_CHANNEL_ACCESS_TOKEN が未設定です");
        return ['success' => false, 'error' => 'Token not configured'];
    }

    $url = 'https://api.line.me/v2/bot/message/broadcast';
    $payload = [
        'messages' => $messages
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
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

    writeDebugLog("LINE一斉配信結果", [
        'httpCode' => $httpCode,
        'response' => $res,
        'curlError' => $curlErr
    ]);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'response' => $res,
        'error' => $curlErr
    ];
}

/**
 * LINE Messaging API: リッチメニュー作成 (メタデータ)
 */
function lineCreateRichMenu(array $menuData): array {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = 'https://api.line.me/v2/bot/richmenu';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ],
        CURLOPT_POSTFIELDS => json_encode($menuData, JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $json = json_decode($res, true);
    return [
        'success' => ($httpCode === 200 && !empty($json['richMenuId'])),
        'httpCode' => $httpCode,
        'richMenuId' => $json['richMenuId'] ?? null,
        'error' => $json['message'] ?? $curlErr,
        'raw' => $res
    ];
}

/**
 * LINE Messaging API: リッチメニュー画像アップロード
 */
function lineUploadRichMenuImage(string $richMenuId, string $imageFilePath, string $contentType): array {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = "https://api-data.line.me/v2/bot/richmenu/{$richMenuId}/content";
    $imageData = file_get_contents($imageFilePath);
    if ($imageData === false) {
        return ['success' => false, 'error' => '画像ファイル読み込み失敗'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            "Content-Type: {$contentType}",
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ],
        CURLOPT_POSTFIELDS => $imageData
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * LINE Messaging API: デフォルトリッチメニュー設定 (友だち全員に適用)
 */
function lineSetDefaultRichMenu(string $richMenuId): array {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = "https://api.line.me/v2/bot/user/all/richmenu/{$richMenuId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN,
            'Content-Length: 0'
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("lineSetDefaultRichMenu結果", [
        'richMenuId' => $richMenuId,
        'httpCode' => $httpCode,
        'response' => $res,
        'curlErr' => $curlErr
    ]);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * LINE Messaging API: デフォルトリッチメニュー解除
 */
function lineCancelDefaultRichMenu(): array {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = "https://api.line.me/v2/bot/user/all/richmenu";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("lineCancelDefaultRichMenu結果", [
        'httpCode' => $httpCode,
        'response' => $res,
        'curlErr' => $curlErr
    ]);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * LINE Messaging API: 現在のデフォルトリッチメニューID取得
 */
function lineGetDefaultRichMenuId(): ?string {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return null;
    }

    $url = "https://api.line.me/v2/bot/user/all/richmenu";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $json = json_decode($res, true);
        return $json['richMenuId'] ?? null;
    }
    return null;
}

/**
 * LINE Messaging API: リッチメニュー削除
 */
function lineDeleteRichMenu(string $richMenuId): array {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = "https://api.line.me/v2/bot/richmenu/{$richMenuId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("lineDeleteRichMenu結果", [
        'richMenuId' => $richMenuId,
        'httpCode' => $httpCode,
        'response' => $res,
        'curlErr' => $curlErr
    ]);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * LINE Messaging API: リッチメニューエイリアス作成・更新
 */
function lineCreateOrUpdateRichMenuAlias(string $richMenuId, string $aliasId): array {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    // 1. まず作成を試みる
    $url = 'https://api.line.me/v2/bot/richmenu/alias';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'richMenuId' => $richMenuId,
            'richMenuAliasId' => $aliasId
        ], JSON_UNESCAPED_UNICODE)
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        return ['success' => true, 'action' => 'created', 'aliasId' => $aliasId];
    }

    // 2. 既に存在する場合は更新(UPDATE)
    $updateUrl = "https://api.line.me/v2/bot/richmenu/alias/{$aliasId}";
    $ch = curl_init($updateUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'richMenuId' => $richMenuId
        ], JSON_UNESCAPED_UNICODE)
    ]);
    $resUpdate = curl_exec($ch);
    $httpCodeUpdate = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrUpdate = curl_error($ch);
    curl_close($ch);

    return [
        'success' => ($httpCodeUpdate === 200),
        'action' => 'updated',
        'httpCode' => $httpCodeUpdate,
        'error' => $curlErrUpdate ?: ($httpCodeUpdate !== 200 ? $resUpdate : null)
    ];
}

/**
 * LINE Messaging API: リッチメニューエイリアス削除
 */
function lineDeleteRichMenuAlias(string $aliasId): array {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = "https://api.line.me/v2/bot/richmenu/alias/{$aliasId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * LINE Messaging API: リッチメニューエイリアス一覧取得
 */
function lineGetRichMenuAliasList(): array {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'aliases' => []];
    }

    $url = 'https://api.line.me/v2/bot/richmenu/alias/list';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $json = json_decode($res, true);
        return ['success' => true, 'aliases' => $json['aliases'] ?? []];
    }
    return ['success' => false, 'aliases' => []];
}

/**
 * LINE Messaging API: リッチメニューの画像バイナリを取得
 * GET https://api-data.line.me/v2/bot/richmenu/{richMenuId}/content
 */
function lineGetRichMenuImage(string $richMenuId): ?string {
    if (empty($richMenuId) || LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return null;
    }

    $url = "https://api-data.line.me/v2/bot/richmenu/{$richMenuId}/content";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($res)) {
        return $res;
    }
    return null;
}

/**
 * LINE Messaging API: ユーザーに個別リッチメニューを紐付け
 * POST https://api.line.me/v2/bot/user/{userId}/richmenu/{richMenuId}
 */
function lineLinkUserRichMenu(string $userId, string $richMenuId): array {
    if (empty($userId) || empty($richMenuId) || LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => '無効なパラメータまたはアクセストークン未設定'];
    }

    $url = "https://api.line.me/v2/bot/user/{$userId}/richmenu/{$richMenuId}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN,
            'Content-Length: 0'
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("lineLinkUserRichMenu結果", [
        'userId' => $userId,
        'richMenuId' => $richMenuId,
        'httpCode' => $httpCode,
        'response' => $res
    ]);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * LINE Messaging API: ユーザーの個別リッチメニュー紐付けを解除（全体デフォルトメニューに戻す）
 * DELETE https://api.line.me/v2/bot/user/{userId}/richmenu
 */
function lineUnlinkUserRichMenu(string $userId): array {
    if (empty($userId) || LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => '無効なパラメータまたはアクセストークン未設定'];
    }

    $url = "https://api.line.me/v2/bot/user/{$userId}/richmenu";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    writeDebugLog("lineUnlinkUserRichMenu結果", [
        'userId' => $userId,
        'httpCode' => $httpCode,
        'response' => $res
    ]);

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'error' => $curlErr ?: ($httpCode !== 200 ? $res : null)
    ];
}

/**
 * データベース接続オブジェクト (PDO) を取得（getDbConnectionのエイリアス）
 */
function getDB(): PDO {
    return getDbConnection();
}

/**
 * 現在有効なお知らせリッチメニューを取得
 */
function getActiveNoticeRichMenu(?PDO $pdo = null): ?array {
    try {
        if (!$pdo) {
            $pdo = getDbConnection();
        }
        // 1. is_notice = 1 かつ is_active = 1 のメニュー（明示的アクティブ）
        $stmt = $pdo->query("SELECT * FROM rich_menus WHERE is_notice = 1 AND is_active = 1 ORDER BY id DESC LIMIT 1");
        $menu = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($menu) return $menu;

        // 2. なければ最新の is_notice = 1 のメニュー
        $stmt2 = $pdo->query("SELECT * FROM rich_menus WHERE is_notice = 1 ORDER BY id DESC LIMIT 1");
        $menu2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($menu2) return $menu2;
    } catch (Throwable $e) {
        writeDebugLog("getActiveNoticeRichMenuエラー: " . $e->getMessage());
    }
    return null;
}

/**
 * Discord Webhook へのリッチ埋め込み通知送信 (ユーザー閲覧)
 */
function sendDiscordNotification(array $car, string $source = 'LINE Flex Message', ?array $userProfile = null, ?string $rawUserId = null) {
    if (empty(DISCORD_WEBHOOK_URL) || DISCORD_WEBHOOK_URL === 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
        return;
    }

    $title = $car['title'] ?? '車両詳細閲覧';
    $totalPrice = $car['total_price_text'] ?? '要問合せ';
    $basePrice = $car['base_price_text'] ?? '-';
    $year = $car['year'] ?? '-';
    $distance = $car['distance'] ?? '-';
    $repair = $car['repair_history'] ?? '-';
    $shaken = $car['shaken'] ?? '-';
    $detailUrl = $car['detail_url'] ?? SHOP_GOO_URL;
    $imgUrl = !empty($car['image_url']) ? $car['image_url'] : 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';

    // ユーザー情報
    $userName = 'LINEユーザー (匿名 / 不明)';
    $userAvatar = null;
    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'] . ' 様';
        if (!empty($userProfile['pictureUrl'])) {
            $userAvatar = $userProfile['pictureUrl'];
        }
    } elseif (!empty($rawUserId)) {
        $userName = "ユーザー (ID: " . substr($rawUserId, 0, 8) . "...)";
    }

    $fields = [
        [
            'name' => '👤 閲覧ユーザー',
            'value' => "**{$userName}**",
            'inline' => false
        ],
        [
            'name' => '💰 支払総額',
            'value' => "**{$totalPrice}** (本体: {$basePrice})",
            'inline' => true
        ],
        [
            'name' => '📅 年式',
            'value' => $year,
            'inline' => true
        ],
        [
            'name' => '🚗 走行距離',
            'value' => $distance,
            'inline' => true
        ],
        [
            'name' => '🛠 修復歴',
            'value' => $repair,
            'inline' => true
        ],
        [
            'name' => '📋 車検',
            'value' => $shaken,
            'inline' => true
        ],
        [
            'name' => '📱 流入元',
            'value' => $source,
            'inline' => true
        ]
    ];

    $embed = [
        'title' => '👀 車両詳細ページへのアクセスがありました！',
        'description' => "**[{$title}]({$detailUrl})**",
        'url' => $detailUrl,
        'color' => 0x06C755, // LINE Green
        'fields' => $fields,
        'thumbnail' => [
            'url' => $imgUrl
        ],
        'footer' => [
            'text' => 'アップファーレン LINE公式 在庫検索システム',
            'icon_url' => 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg'
        ],
        'timestamp' => date('c')
    ];

    if ($userAvatar) {
        $embed['author'] = [
            'name' => $userName,
            'icon_url' => $userAvatar
        ];
    }

    $payload = [
        'username' => 'LINE車両検索 Bot',
        'avatar_url' => 'https://img.goo-net.com/common_v2/img/idcars/icon_idlogo.png',
        'embeds' => [$embed]
    ];

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * ユーザーからの正式な車両問い合わせ時の Discord 通知
 */
function sendDiscordInquiryNotification(array $car, string $inquiryType, ?array $userProfile = null, ?string $rawUserId = null) {
    if (empty(DISCORD_WEBHOOK_URL) || DISCORD_WEBHOOK_URL === 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
        return;
    }

    $title = $car['title'] ?? '車両問い合わせ';
    $totalPrice = $car['total_price_text'] ?? '要問合せ';
    $year = $car['year'] ?? '-';
    $distance = $car['distance'] ?? '-';
    $detailUrl = $car['detail_url'] ?? SHOP_GOO_URL;
    $imgUrl = !empty($car['image_url']) ? $car['image_url'] : 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';

    $userName = 'LINEユーザー (匿名 / 不明)';
    $userAvatar = null;
    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'] . ' 様';
        if (!empty($userProfile['pictureUrl'])) {
            $userAvatar = $userProfile['pictureUrl'];
        }
    } elseif (!empty($rawUserId)) {
        $userName = "ユーザー (ID: " . substr($rawUserId, 0, 8) . "...)";
    }

    $embed = [
        'title' => "📩 【お問い合わせ】{$inquiryType}の依頼が届きました！",
        'description' => "**[{$title}]({$detailUrl})**\n\nお客様から正式なお問い合わせがありました。LINE公式アカウントのチャット等でご確認ください。",
        'url' => $detailUrl,
        'color' => 0xFF0055,
        'fields' => [
            ['name' => '👤 お問い合わせ者', 'value' => "**{$userName}**", 'inline' => false],
            ['name' => '📝 ご希望内容', 'value' => "🎯 **{$inquiryType}**", 'inline' => true],
            ['name' => '💰 支払総額', 'value' => "**{$totalPrice}**", 'inline' => true],
            ['name' => '📅 年式 / 走行', 'value' => "{$year} / {$distance}", 'inline' => true],
            ['name' => '🔗 車両詳細', 'value' => "[グーネットで見る]({$detailUrl})", 'inline' => true]
        ],
        'thumbnail' => ['url' => $imgUrl],
        'footer' => [
            'text' => 'アップファーレン LINE公式 問い合わせ通知',
            'icon_url' => 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg'
        ],
        'timestamp' => date('c')
    ];

    if ($userAvatar) {
        $embed['author'] = ['name' => $userName, 'icon_url' => $userAvatar];
    }

    $payload = [
        'username' => 'LINE公式 問い合わせ通知',
        'avatar_url' => 'https://img.goo-net.com/common_v2/img/idcars/icon_idlogo.png',
        'content' => "🚨 **【真剣度高】車両のお問い合わせが届きました！**",
        'embeds' => [$embed]
    ];

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * ユーザーからのオイル交換・車検来店予約時の Discord 通知
 */
function sendDiscordMaintenanceBookingNotification(string $bookingType, string $carModel, string $prefTime, ?array $userProfile = null, ?string $rawUserId = null) {
    if (empty(DISCORD_WEBHOOK_URL) || DISCORD_WEBHOOK_URL === 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
        return;
    }

    $userName = 'LINEユーザー (匿名 / 不明)';
    $userAvatar = null;
    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'] . ' 様';
        if (!empty($userProfile['pictureUrl'])) {
            $userAvatar = $userProfile['pictureUrl'];
        }
    } elseif (!empty($rawUserId)) {
        $userName = "ユーザー (ID: " . substr($rawUserId, 0, 8) . "...)";
    }

    if ($bookingType === 'オイル交換') {
        $color = 0xF59E0B;
    } elseif (str_contains($bookingType, '点検') || str_contains($bookingType, '定期')) {
        $color = 0x10B981;
    } else {
        $color = 0x3B82F6;
    }

    $embed = [
        'title' => "🛠️ 【来店予約】{$bookingType}の予約申し込みが届きました！",
        'description' => "**{$userName}** より愛車 **【{$carModel}】** の {$bookingType} 予約相談が届きました。\nLINE公式アカウントのチャット等で日程のご案内をお願いいたします。",
        'color' => $color,
        'fields' => [
            ['name' => '👤 お客様名', 'value' => "**{$userName}**", 'inline' => true],
            ['name' => '🚗 対象愛車', 'value' => "**{$carModel}**", 'inline' => true],
            ['name' => '📅 ご希望日程・時間帯', 'value' => "🎯 **{$prefTime}**", 'inline' => false]
        ],
        'footer' => [
            'text' => 'アップファーレン メンテナンス予約通知',
            'icon_url' => 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg'
        ],
        'timestamp' => date('c')
    ];

    if ($userAvatar) {
        $embed['author'] = ['name' => $userName, 'icon_url' => $userAvatar];
    }

    $payload = [
        'username' => 'LINEメンテナンス 予約受付',
        'avatar_url' => 'https://img.goo-net.com/common_v2/img/idcars/icon_idlogo.png',
        'content' => "🚨 **【来店予約】{$bookingType}のお申し込みがありました！**",
        'embeds' => [$embed]
    ];

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * 新着車両検知時の Discord 通知
 */
function sendDiscordNewCarsNotification(array $newCars) {
    if (empty(DISCORD_WEBHOOK_URL) || DISCORD_WEBHOOK_URL === 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
        return;
    }

    $count = count($newCars);
    $embeds = [];

    $displayCars = array_slice($newCars, 0, 4);
    foreach ($displayCars as $car) {
        $title = $car['title'] ?? '新着車両';
        $totalPrice = $car['total_price_text'] ?? '要問合せ';
        $year = $car['year'] ?? '-';
        $distance = $car['distance'] ?? '-';
        $detailUrl = $car['detail_url'] ?? SHOP_GOO_URL;
        $imgUrl = !empty($car['image_url']) ? $car['image_url'] : 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';

        $embeds[] = [
            'title' => "🆕 新着入荷: {$totalPrice}",
            'description' => "**[{$title}]({$detailUrl})**\n年式: {$year} | 走行: {$distance}",
            'url' => $detailUrl,
            'color' => 0xFF9900,
            'thumbnail' => ['url' => $imgUrl]
        ];
    }

    $payload = [
        'username' => 'LINE在庫更新 通知',
        'avatar_url' => 'https://img.goo-net.com/common_v2/img/idcars/icon_idlogo.png',
        'content' => "🚗✨ **【新着在庫情報】** グーネットに新しい車両が **{$count}台** 掲載されました！",
        'embeds' => $embeds
    ];

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * リマインド定期配信実行結果の Discord レポート
 */
function sendDiscordReminderReport(int $oilCount, int $periodicCount, int $shakenCount, array $details = []) {
    if (empty(DISCORD_WEBHOOK_URL) || DISCORD_WEBHOOK_URL === 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
        return;
    }

    $total = $oilCount + $periodicCount + $shakenCount;
    if ($total === 0) return;

    $descLines = [];
    foreach ($details as $d) {
        $descLines[] = "• **{$d['name']}** 様 (愛車: {$d['car']}) ➡ **{$d['type']}** [予定: {$d['date']}]";
    }

    $embed = [
        'title' => "⏰ 【定期配信】本日 {$total} 名様へメンテナンス通知を送信しました",
        'description' => implode("\n", array_slice($descLines, 0, 10)),
        'color' => 0x3B82F6,
        'fields' => [
            ['name' => '🛢 オイル交換', 'value' => "{$oilCount} 件", 'inline' => true],
            ['name' => '📋 12ヶ月定期点検', 'value' => "{$periodicCount} 件", 'inline' => true],
            ['name' => '🚗 車検満了', 'value' => "{$shakenCount} 件", 'inline' => true]
        ],
        'footer' => ['text' => 'アップファーレン メンテナンス自動リマインドシステム'],
        'timestamp' => date('c')
    ];

    $payload = [
        'username' => 'メンテリマインド 自動配信',
        'avatar_url' => 'https://img.goo-net.com/common_v2/img/idcars/icon_idlogo.png',
        'embeds' => [$embed]
    ];

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * マイカー点検パスポートを開いた新規ユーザーの Discord 通知
 */
function sendDiscordNewCustomerNotification(string $userId, string $userName) {
    if (empty(DISCORD_WEBHOOK_URL) || DISCORD_WEBHOOK_URL === 'YOUR_DISCORD_WEBHOOK_URL_HERE') {
        return;
    }

    $displayName = $userName ?: '名称未設定のお客様';

    $embed = [
        'title' => "🆕 新規ユーザー追加: マイカー点検パスポート開始",
        'description' => "**{$displayName}** 様がマイカー点検パスポートを開き、LINE連携が完了しました。\n管理画面からこのお客様の車両情報や車検日を入力できるようになりました。",
        'color' => 0x10B981, // Green
        'fields' => [
            ['name' => 'ユーザー名', 'value' => $displayName, 'inline' => true],
            ['name' => 'LINE ID', 'value' => "`{$userId}`", 'inline' => true]
        ],
        'footer' => ['text' => 'アップファーレン 顧客管理システム'],
        'timestamp' => date('c')
    ];

    $payload = [
        'username' => '顧客管理・新着通知',
        'avatar_url' => 'https://img.goo-net.com/common_v2/img/idcars/icon_idlogo.png',
        'embeds' => [$embed]
    ];

    $ch = curl_init(DISCORD_WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * デバッグログの出力
 */
function writeDebugLog(string $message, array $context = []) {
    $logFile = __DIR__ . '/webhook_debug.log';
    $time = date('Y-m-d H:i:s');
    $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
    $logLine = "[{$time}] {$message}{$contextStr}\n";
    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}
