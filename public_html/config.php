<?php
/**
 * システム共通設定ファイル
 * Xserver環境およびLINE公式アカウント、Discord通知、顧客メンテナンス管理の設定を管理します。
 */

// タイムゾーン設定 (日本時間 / JST)
date_default_timezone_set('Asia/Tokyo');
ini_set('date.timezone', 'Asia/Tokyo');
putenv('TZ=Asia/Tokyo');

// --- LINE公式アカウント設定 ---
define('LINE_CHANNEL_ACCESS_TOKEN', 'n1ItOIEh+8mNJiEpXK+hG0T4/b1Z9taR2FkYQrAwA6J/3XMdUUfHnkP3DX+7u+nGgirA4helNntS1qT2m2kOtV7yiYM2MwxrEB7qj09J/yXhItpCqKGS7l4lcaffcvukX/jHGFOLDSloz0vBLIQAdQdB04t89/1O/w1cDnyilFU='); // チャネルアクセストークン (長期)
define('LINE_CHANNEL_SECRET', 'a5dbfb92fa7be994b6e8f38f870b97b8');             // チャネルシークレット
define('LINE_LIFF_ID', '2000276344-YL1wXh0h');                           // LIFF ID (例: 1234567890-AbcdEfgh)
define('LIFF_ID', '2000276344-YL1wXh0h');                                // エイリアス用LIFF ID

// --- 新着車両の自動配信設定 ---
define('ENABLE_NEW_CAR_BROADCAST', false); // 新着検知時にLINE公式アカウントの友だち全員へ自動一斉配信するか (true: 送信する, false: 送信しない)
define('ENABLE_NEW_CAR_DISCORD', true);   // 新着検知時にDiscordへ通知するか

// --- 店舗管理画面設定 ---
define('ADMIN_PASSWORD', '1020143'); // 店舗用管理画面（/admin/）のログインパスワード

// --- Discord 通知設定 ---
define('DISCORD_WEBHOOK_URL', 'https://discord.com/api/webhooks/1543636005582667776/8hnE-kLsB545xgS923mTvgIUaBuTz8TQQLrJXFvqB-A0oh92LmqC8Zn-1jaOIhW20YEZ');

// --- 店舗・教室・システム設定 ---
define('SHOP_CODE', '0601492');
define('SHOP_NAME', 'シニア向けパソコン教室');
define('SHOP_GOO_URL', '');

// --- プロライン (ProLine) Webhook中継・連携設定 ---
define('PROLINE_WEBHOOK_URL', ''); // プロラインのWebhook URL (例: https://autosns.pro/.../webhook/...)
define('PROLINE_RELAY_ENABLED', true); // プロラインへのWebhook転送を有効にするか (true: 有効, false: 無効)
define('PROLINE_CALENDAR_URL', 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1'); // レッスン予約・日程変更URL (受講生自動ログインLIFF)


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
            created_at DATETIME DEFAULT (datetime('now', '+9 hours')),
            updated_at DATETIME DEFAULT (datetime('now', '+9 hours'))
        )
    ");

    // インデックス作成
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_user_id ON customer_cars(user_id)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_oil_next ON customer_cars(oil_next_date)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_periodic_next ON customer_cars(periodic_insp_next_date)"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_inspection_next ON customer_cars(inspection_next_date)"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN custom_line_menu_id TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN custom_menu_text TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN custom_menu_set_at DATETIME"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN picture_url TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN last_interaction_at DATETIME"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN last_interaction_type TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE customer_cars ADD COLUMN last_interaction_preview TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cars_last_interaction ON customer_cars(last_interaction_at)"); } catch (Exception $e) {}

    // システム設定・マイグレーション管理テーブル
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at DATETIME
            )
        ");
    } catch (Exception $e) {}

    // 初期化マイグレーション: last_interaction_at が NULL の既存顧客に対して最新日付を補完
    try {
        $nowJst = date('Y-m-d H:i:s');
        $pdo->exec("
            UPDATE customer_cars
            SET 
                last_interaction_at = COALESCE(
                    inspection_reminded_at,
                    periodic_reminded_at,
                    oil_reminded_at,
                    custom_menu_set_at,
                    updated_at,
                    created_at,
                    '{$nowJst}'
                ),
                last_interaction_type = CASE
                    WHEN inspection_reminded_at IS NOT NULL THEN 'admin_reminder'
                    WHEN periodic_reminded_at IS NOT NULL THEN 'admin_reminder'
                    WHEN oil_reminded_at IS NOT NULL THEN 'admin_reminder'
                    WHEN custom_menu_set_at IS NOT NULL THEN 'custom_menu'
                    ELSE 'follow'
                END,
                last_interaction_preview = CASE
                    WHEN inspection_reminded_at IS NOT NULL THEN '車検リマインド送信'
                    WHEN periodic_reminded_at IS NOT NULL THEN '12ヶ月点検リマインド送信'
                    WHEN oil_reminded_at IS NOT NULL THEN 'オイル交換リマインド送信'
                    WHEN custom_menu_set_at IS NOT NULL THEN '個別リッチメニュー設定'
                    ELSE '友だち登録'
                END
            WHERE last_interaction_at IS NULL
        ");
    } catch (Exception $e) {}

    // 【重要】既存データのUTC→JST(+9時間) 一括変換マイグレーション (1回限り実行)
    // 過去に DEFAULT CURRENT_TIMESTAMP 等で記録されたUTC時刻データを日本標準時(JST)に揃える
    try {
        $checkStmt = $pdo->prepare("SELECT value FROM system_settings WHERE key = 'tz_migrated_to_jst_v2'");
        $checkStmt->execute();
        $isMigrated = $checkStmt->fetchColumn();
        if (!$isMigrated) {
            $nowJst = date('Y-m-d H:i:s');
            $pdo->beginTransaction();

            // 1. 過去の既存レコードの各日時カラムを +9時間 して日本時間に補正
            $pdo->exec("
                UPDATE customer_cars
                SET 
                    created_at = datetime(created_at, '+9 hours'),
                    updated_at = datetime(updated_at, '+9 hours'),
                    last_interaction_at = CASE 
                        WHEN last_interaction_at IS NOT NULL THEN datetime(last_interaction_at, '+9 hours')
                        ELSE datetime(COALESCE(updated_at, created_at), '+9 hours')
                    END,
                    oil_reminded_at = CASE WHEN oil_reminded_at IS NOT NULL THEN datetime(oil_reminded_at, '+9 hours') ELSE NULL END,
                    periodic_reminded_at = CASE WHEN periodic_reminded_at IS NOT NULL THEN datetime(periodic_reminded_at, '+9 hours') ELSE NULL END,
                    inspection_reminded_at = CASE WHEN inspection_reminded_at IS NOT NULL THEN datetime(inspection_reminded_at, '+9 hours') ELSE NULL END,
                    custom_menu_set_at = CASE WHEN custom_menu_set_at IS NOT NULL THEN datetime(custom_menu_set_at, '+9 hours') ELSE NULL END
            ");

            // 2. 念のための安全防護策: 補正により現在時刻より未来（10分以上先）になってしまったものは現在時刻(JST)に丸める
            $safeStmt = $pdo->prepare("
                UPDATE customer_cars 
                SET last_interaction_at = :now_jst 
                WHERE last_interaction_at > datetime(:now_jst_check, '+10 minutes')
            ");
            $safeStmt->execute([':now_jst' => $nowJst, ':now_jst_check' => $nowJst]);

            // 完了フラグを記録
            $pdo->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES ('tz_migrated_to_jst_v2', '1', :now_jst)")
                ->execute([':now_jst' => $nowJst]);
            $pdo->commit();
            writeDebugLog("全顧客データのUTC→JST一括マイグレーション完了");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        writeDebugLog("JSTマイグレーション例外", ['error' => $e->getMessage()]);
    }

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
            created_at DATETIME DEFAULT (datetime('now', '+9 hours')),
            updated_at DATETIME DEFAULT (datetime('now', '+9 hours'))
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
 * LINE Messaging API: 全フォロワー（友だち）の User ID 一覧を取得
 * GET https://api.line.me/v2/bot/followers/ids
 */
function getLineFollowerUserIds(?string $start = null): array {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークンが未設定です'];
    }

    $url = "https://api.line.me/v2/bot/followers/ids?limit=1000";
    if (!empty($start)) {
        $url .= "&start=" . urlencode($start);
    }

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
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 && !empty($res)) {
        $data = json_decode($res, true);
        return [
            'success' => true,
            'userIds' => $data['userIds'] ?? [],
            'next' => $data['next'] ?? null
        ];
    }

    return [
        'success' => false,
        'httpCode' => $httpCode,
        'error' => $curlErr ?: $res
    ];
}

/**
 * LINEユーザー（友だち）を customer_cars に自動登録・名前同期する共通関数
 */
function ensureCustomerExists(PDO $db, string $userId): ?array {
    if (empty($userId) || !str_starts_with($userId, 'U')) {
        return null;
    }

    try {
        $stmt = $db->prepare("SELECT * FROM customer_cars WHERE user_id = :uid ORDER BY id ASC LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // 名前が仮名またはアイコンが未登録ならプロフィール取得して更新
            $needUpdateName = empty($existing['user_name']) || in_array($existing['user_name'], ['新規お客様', 'お客様', 'LINE友だち', '']);
            $needUpdatePic = empty($existing['picture_url']);
            if ($needUpdateName || $needUpdatePic) {
                $prof = getLineUserProfile($userId);
                if ($prof) {
                    $nowJst = date('Y-m-d H:i:s');
                    $upName = (!empty($prof['displayName']) && $needUpdateName) ? $prof['displayName'] : $existing['user_name'];
                    $upPic = !empty($prof['pictureUrl']) ? $prof['pictureUrl'] : ($existing['picture_url'] ?? '');
                    $upStmt = $db->prepare("UPDATE customer_cars SET user_name = :uname, picture_url = :pic, updated_at = :updated_at WHERE id = :id");
                    $upStmt->execute([':uname' => $upName, ':pic' => $upPic, ':updated_at' => $nowJst, ':id' => $existing['id']]);
                    $existing['user_name'] = $upName;
                    $existing['picture_url'] = $upPic;
                }
            }
            return $existing;
        }

        // 新規登録
        $prof = getLineUserProfile($userId);
        $displayName = !empty($prof['displayName']) ? $prof['displayName'] : 'お客様';
        $pictureUrl = !empty($prof['pictureUrl']) ? $prof['pictureUrl'] : '';
        $nowJst = date('Y-m-d H:i:s');

        $insertStmt = $db->prepare("
            INSERT INTO customer_cars (
                user_id, user_name, picture_url, car_model, car_number,
                last_interaction_at, last_interaction_type, last_interaction_preview,
                created_at, updated_at
            ) VALUES (
                :uid, :uname, :pic, '【未設定】受講コース未設定', '',
                :now_jst1, 'follow', 'LINE受講生登録',
                :now_jst2, :now_jst3
            )
        ");
        $insertStmt->execute([
            ':uid' => $userId,
            ':uname' => $displayName,
            ':pic' => $pictureUrl,
            ':now_jst1' => $nowJst,
            ':now_jst2' => $nowJst,
            ':now_jst3' => $nowJst
        ]);
        $newId = (int)$db->lastInsertId();
        writeDebugLog("LINEユーザー自動顧客登録完了", ['uid' => $userId, 'name' => $displayName, 'pic' => $pictureUrl, 'id' => $newId]);

        return [
            'id' => $newId,
            'user_id' => $userId,
            'user_name' => $displayName,
            'picture_url' => $pictureUrl,
            'car_model' => '【未登録】愛車登録待ち',
            'last_interaction_at' => $nowJst,
            'last_interaction_type' => 'follow',
            'last_interaction_preview' => '友だち登録'
        ];
    } catch (Throwable $e) {
        writeDebugLog("ensureCustomerExists 例外", ['error' => $e->getMessage()]);
        return null;
    }
}

/**
 * 顧客との最新チャット・やり取り日時を記録
 * @param PDO $db
 * @param string $userId LINE User ID
 * @param string $type やり取り種別 (user_message, user_action, admin_reminder, follow, custom_menu)
 * @param string $preview プレビュー文字列 (50文字程度推奨)
 * @param int|null $carId 特定の車両レコードID (省略時は該当userIdのレコードを更新)
 */
function recordCustomerInteraction(PDO $db, string $userId, string $type, string $preview, ?int $carId = null): void {
    if (empty($userId) || !str_starts_with($userId, 'U')) {
        return;
    }

    try {
        $preview = mb_substr(trim($preview), 0, 80);
        $nowJst = date('Y-m-d H:i:s');
        if ($carId) {
            $stmt = $db->prepare("
                UPDATE customer_cars 
                SET last_interaction_at = :now_jst1,
                    last_interaction_type = :type,
                    last_interaction_preview = :preview,
                    updated_at = :now_jst2
                WHERE id = :id
            ");
            $stmt->execute([':now_jst1' => $nowJst, ':type' => $type, ':preview' => $preview, ':now_jst2' => $nowJst, ':id' => $carId]);
        } else {
            $stmt = $db->prepare("
                UPDATE customer_cars 
                SET last_interaction_at = :now_jst1,
                    last_interaction_type = :type,
                    last_interaction_preview = :preview,
                    updated_at = :now_jst2
                WHERE user_id = :uid
            ");
            $stmt->execute([':now_jst1' => $nowJst, ':type' => $type, ':preview' => $preview, ':now_jst2' => $nowJst, ':uid' => $userId]);
        }
        writeDebugLog("顧客インタラクション記録", ['uid' => $userId, 'type' => $type, 'preview' => $preview, 'time' => $nowJst]);
    } catch (Throwable $e) {
        writeDebugLog("recordCustomerInteraction 例外", ['error' => $e->getMessage()]);
    }
}

/**
 * 日時文字列から親切な相対時間表記を生成
 * 例: たった今, 15分前, 3時間前, 昨日 14:20, 3日前, 2026/09/01
 */
function formatTimeDiffText(?string $datetimeStr): string {
    if (empty($datetimeStr)) {
        return '未記録';
    }
    $ts = strtotime($datetimeStr);
    if (!$ts) {
        return '未記録';
    }
    $now = time();
    $diff = $now - $ts;

    // わずかな時計ズレ（10分以内の未来）は「たった今」とする
    if ($diff < 0) {
        if ($diff > -600) {
            return 'たった今';
        }
        return date('Y/m/d H:i', $ts);
    }
    if ($diff < 60) {
        return 'たった今';
    }
    if ($diff < 3600) {
        $m = max(1, floor($diff / 60));
        return "{$m}分前";
    }
    if ($diff < 86400) {
        $h = floor($diff / 3600);
        return "{$h}時間前";
    }
    if ($diff < 86400 * 2) {
        return '昨日 ' . date('H:i', $ts);
    }
    if ($diff < 86400 * 7) {
        $d = floor($diff / 86400);
        return "{$d}日前";
    }
    return date('Y/m/d', $ts);
}

/**
 * ユーザーが専用リッチメニューを持っているか確認してそのLINEメニューIDを返す
 */
function getUserCustomRichMenuId(?PDO $db, string $userId): ?string {
    if (empty($userId) || !str_starts_with($userId, 'U')) {
        return null;
    }
    if (!$db) {
        $db = getDbConnection();
    }
    try {
        // 空文字ではない有効な custom_line_menu_id を持つ最新レコードを確実に取得
        $stmt = $db->prepare("
            SELECT custom_line_menu_id 
            FROM customer_cars 
            WHERE user_id = :uid 
              AND custom_line_menu_id IS NOT NULL 
              AND TRIM(custom_line_menu_id) != '' 
            ORDER BY COALESCE(custom_menu_set_at, updated_at) DESC, id DESC 
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['custom_line_menu_id'])) {
            $menuId = trim($row['custom_line_menu_id']);

            // 【重要】もし custom_line_menu_id がお知らせリッチメニュー(is_notice=1)だった場合、
            // それは通常メニューへの復帰対象（専用メニュー）ではないため除外
            $noticeMenu = getActiveNoticeRichMenu($db);
            $isNotice = false;
            if ($noticeMenu && !empty($noticeMenu['line_menu_id']) && $noticeMenu['line_menu_id'] === $menuId) {
                $isNotice = true;
            } elseif (isNoticeMenu($db, $menuId)) {
                $isNotice = true;
            }

            if ($isNotice) {
                return null;
            }

            return $menuId;
        }
    } catch (Throwable $e) {
        writeDebugLog("getUserCustomRichMenuId例外", ['error' => $e->getMessage()]);
    }
    return null;
}

/**
 * LINE Messaging API: ユーザーに現在リンクされているリッチメニューIDを取得
 * GET https://api.line.me/v2/bot/user/{userId}/richmenu
 */
function lineGetUserRichMenuId(string $userId): ?string {
    if (empty($userId) || LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return null;
    }

    $url = "https://api.line.me/v2/bot/user/{$userId}/richmenu";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($res)) {
        $data = json_decode($res, true);
        if (!empty($data['richMenuId'])) {
            return trim($data['richMenuId']);
        }
    }
    return null;
}

/**
 * 指定されたエイリアスIDまたはメニューIDがお知らせメニューかどうか判定
 */
function isNoticeMenu(?PDO $db, string $aliasOrMenuId): bool {
    if (empty($aliasOrMenuId)) return false;
    if (!$db) {
        $db = getDbConnection();
    }
    try {
        $stmt = $db->prepare("
            SELECT is_notice, title 
            FROM rich_menus 
            WHERE alias_id = :aid OR line_menu_id = :mid 
            LIMIT 1
        ");
        $stmt->execute([':aid' => $aliasOrMenuId, ':mid' => $aliasOrMenuId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ((int)$row['is_notice'] === 1 || str_contains($row['title'] ?? '', 'お知らせ') || str_contains($row['title'] ?? '', 'ご案内')) {
                return true;
            }
        }
        if (str_contains($aliasOrMenuId, 'notice')) {
            return true;
        }
    } catch (Throwable $e) {
        writeDebugLog("isNoticeMenu例外", ['error' => $e->getMessage()]);
    }
    return false;
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

    $errorMsg = $curlErr;
    if (empty($errorMsg) && $httpCode !== 200) {
        $json = json_decode((string)$res, true);
        if (is_array($json) && !empty($json['message'])) {
            $errorMsg = $json['message'];
            if (!empty($json['details']) && is_array($json['details'])) {
                $details = [];
                foreach ($json['details'] as $d) {
                    $details[] = (!empty($d['property']) ? $d['property'] . ': ' : '') . ($d['message'] ?? '');
                }
                $errorMsg .= ' [' . implode(', ', $details) . ']';
            }
        } elseif (!empty($res)) {
            $errorMsg = substr((string)$res, 0, 300);
        } else {
            $errorMsg = "HTTPエラー {$httpCode}";
        }
    }

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'response' => $res,
        'error' => $errorMsg
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
        CURLOPT_TIMEOUT => 15,
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

    $errorMsg = $curlErr;
    if (empty($errorMsg) && $httpCode !== 200) {
        $json = json_decode((string)$res, true);
        if (is_array($json) && !empty($json['message'])) {
            $errorMsg = $json['message'];
            if (!empty($json['details']) && is_array($json['details'])) {
                $details = [];
                foreach ($json['details'] as $d) {
                    $details[] = (!empty($d['property']) ? $d['property'] . ': ' : '') . ($d['message'] ?? '');
                }
                $errorMsg .= ' [' . implode(', ', $details) . ']';
            }
        } elseif (!empty($res)) {
            $errorMsg = substr((string)$res, 0, 300);
        } else {
            $errorMsg = "HTTPエラー {$httpCode}";
        }
    }

    return [
        'success' => ($httpCode === 200),
        'httpCode' => $httpCode,
        'response' => $res,
        'error' => $errorMsg
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
 * LINE Messaging API: リッチメニュー詳細取得 (LINEサーバー上の実データ)
 */
function lineGetRichMenu(string $richMenuId): ?array {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE' || empty($richMenuId)) {
        return null;
    }

    $url = "https://api.line.me/v2/bot/richmenu/{$richMenuId}";
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
        $data = json_decode($res, true);
        return is_array($data) ? $data : null;
    }
    return null;
}

/**
 * LINE Messaging API: LINEサーバー上の全リッチメニュー一覧取得
 * GET https://api.line.me/v2/bot/richmenu/list
 */
function lineGetRichMenuList(): array {
    if (LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return ['success' => false, 'error' => 'LINEアクセストークン未設定', 'richmenus' => []];
    }

    $url = "https://api.line.me/v2/bot/richmenu/list";
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
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        $json = json_decode($res, true);
        return [
            'success' => true,
            'richmenus' => $json['richmenus'] ?? []
        ];
    }

    return [
        'success' => false,
        'httpCode' => $httpCode,
        'error' => $curlErr ?: $res,
        'richmenus' => []
    ];
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
 * LINE Messaging API: ユーザーに現在個別紐付けされているリッチメニューIDを取得
 * GET https://api.line.me/v2/bot/user/{userId}/richmenu
 * @return string|null 個別紐付けリッチメニューID（個別紐付けなし/全体デフォルト表示中の場合はnull）
 */
function lineGetUserRichMenu(string $userId): ?string {
    if (empty($userId) || LINE_CHANNEL_ACCESS_TOKEN === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
        return null;
    }

    $url = "https://api.line.me/v2/bot/user/" . urlencode($userId) . "/richmenu";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
        ]
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && !empty($res)) {
        $json = json_decode($res, true);
        return $json['richMenuId'] ?? null;
    }

    // 404等は個別リッチメニューなし（全体デフォルトメニューを表示中）
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

        // 2. 最新の is_notice = 1 のメニュー
        $stmt2 = $pdo->query("SELECT * FROM rich_menus WHERE is_notice = 1 ORDER BY id DESC LIMIT 1");
        $menu2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($menu2) return $menu2;

        // 3. タイトルまたはchat_bar_textに「お知らせ」「案内」「キャンペーン」「イベント」が含まれるメニュー
        $stmt3 = $pdo->query("SELECT * FROM rich_menus WHERE (title LIKE '%お知らせ%' OR chat_bar_text LIKE '%お知らせ%' OR title LIKE '%案内%' OR chat_bar_text LIKE '%案内%' OR title LIKE '%キャンペーン%' OR title LIKE '%イベント%') ORDER BY id DESC LIMIT 1");
        $menu3 = $stmt3->fetch(PDO::FETCH_ASSOC);
        if ($menu3) return $menu3;

        // 4. 全体本番（is_active=1）以外の最新メニュー（サブメニュー候補）
        $stmt4 = $pdo->query("SELECT * FROM rich_menus WHERE is_active = 0 ORDER BY id DESC LIMIT 1");
        $menu4 = $stmt4->fetch(PDO::FETCH_ASSOC);
        if ($menu4) return $menu4;

        // 5. 登録されている最新のメニュー
        $stmt5 = $pdo->query("SELECT * FROM rich_menus ORDER BY id DESC LIMIT 1");
        $menu5 = $stmt5->fetch(PDO::FETCH_ASSOC);
        if ($menu5) return $menu5;
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

    // 管理者LINEアカウントへも通知
    try {
        sendAdminLineInquiryNotification($car, $inquiryType, $userProfile, $rawUserId);
    } catch (Throwable $e) {
        writeDebugLog("sendAdminLineInquiryNotification失敗", ['error' => $e->getMessage()]);
    }
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

    // 管理者LINEアカウントへも通知
    try {
        sendAdminLineMaintenanceBookingNotification($bookingType, $carModel, $prefTime, $userProfile, $rawUserId);
    } catch (Throwable $e) {
        writeDebugLog("sendAdminLineMaintenanceBookingNotification失敗", ['error' => $e->getMessage()]);
    }
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

    // 管理者LINEアカウントへも通知
    try {
        sendAdminLineNewCarsNotification($newCars);
    } catch (Throwable $e) {
        writeDebugLog("sendAdminLineNewCarsNotification失敗", ['error' => $e->getMessage()]);
    }
}

/**
 * リマインド定期配信実行結果の Discord レポート
 */
function sendDiscordReminderReport(int $oilCount, int $periodicCount, int $shakenCount, array $details = []) {
    // 管理者LINEアカウントへも通知
    try {
        sendAdminLineReminderReport($oilCount, $periodicCount, $shakenCount, $details);
    } catch (Throwable $e) {
        writeDebugLog("sendAdminLineReminderReport失敗", ['error' => $e->getMessage()]);
    }

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
    // 管理者LINEアカウントへも通知
    try {
        sendAdminLineNewCustomerNotification($userId, $userName);
    } catch (Throwable $e) {
        writeDebugLog("sendAdminLineNewCustomerNotification失敗", ['error' => $e->getMessage()]);
    }

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

// ==========================================
// 管理者向けLINE通知エンジン
// ==========================================

/**
 * 管理者LINE通知設定を取得
 */
function getAdminLineSettings(?PDO $pdo = null): array {
    if (!$pdo) {
        $pdo = getDbConnection();
    }
    $defaults = [
        'admin_uids' => [],
        'notify_inquiry' => true,
        'notify_booking' => true,
        'notify_new_customer' => true,
        'notify_new_cars' => false,
        'notify_reminder' => true
    ];
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at DATETIME
            )
        ");
        $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE key = 'admin_line_settings' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['value'])) {
            $saved = json_decode($row['value'], true);
            if (is_array($saved)) {
                return array_merge($defaults, $saved);
            }
        }
    } catch (Throwable $e) {
        writeDebugLog("getAdminLineSettings例外", ['error' => $e->getMessage()]);
    }
    return $defaults;
}

/**
 * 管理者LINE通知設定を保存
 */
function saveAdminLineSettings(array $settings, ?PDO $pdo = null): array {
    if (!$pdo) {
        $pdo = getDbConnection();
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at DATETIME
            )
        ");

        $cleanUids = [];
        if (!empty($settings['admin_uids']) && is_array($settings['admin_uids'])) {
            foreach ($settings['admin_uids'] as $uid) {
                $uid = trim($uid);
                if (!empty($uid) && str_starts_with($uid, 'U') && !in_array($uid, $cleanUids)) {
                    $cleanUids[] = $uid;
                }
            }
        }
        $dataToSave = [
            'admin_uids' => $cleanUids,
            'notify_inquiry' => isset($settings['notify_inquiry']) ? (bool)$settings['notify_inquiry'] : true,
            'notify_booking' => isset($settings['notify_booking']) ? (bool)$settings['notify_booking'] : true,
            'notify_new_customer' => isset($settings['notify_new_customer']) ? (bool)$settings['notify_new_customer'] : true,
            'notify_new_cars' => isset($settings['notify_new_cars']) ? (bool)$settings['notify_new_cars'] : false,
            'notify_reminder' => isset($settings['notify_reminder']) ? (bool)$settings['notify_reminder'] : true,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $json = json_encode($dataToSave, JSON_UNESCAPED_UNICODE);

        // 古いSQLiteでも100%確実に動作する INSERT OR REPLACE
        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO system_settings (key, value, updated_at) 
            VALUES ('admin_line_settings', :val, datetime('now', '+9 hours'))
        ");
        $ok = $stmt->execute([':val' => $json]);
        if ($ok) {
            return ['success' => true];
        } else {
            $err = $stmt->errorInfo();
            writeDebugLog("saveAdminLineSettings失敗", ['error' => $err]);
            return ['success' => false, 'error' => $err[2] ?? 'DB保存に失敗しました'];
        }
    } catch (Throwable $e) {
        writeDebugLog("saveAdminLineSettings例外", ['error' => $e->getMessage()]);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 登録されている全管理者へLINEメッセージをPush送信
 */
function sendAdminLineBroadcast(array $messages, ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    $adminUids = $settings['admin_uids'] ?? [];
    if (empty($adminUids)) {
        writeDebugLog("管理者LINE通知スキップ: 管理者UID未登録");
        return ['success' => false, 'sent_count' => 0, 'error' => '管理者LINEアカウントが登録されていません'];
    }

    $results = [];
    $successCount = 0;
    foreach ($adminUids as $uid) {
        $res = sendLinePushMessage($uid, $messages);
        $results[$uid] = $res;
        if (!empty($res['success'])) {
            $successCount++;
        }
    }

    writeDebugLog("管理者LINE通知送信結果", [
        'total' => count($adminUids),
        'success' => $successCount,
        'results' => $results
    ]);

    return [
        'success' => ($successCount > 0),
        'sent_count' => $successCount,
        'total' => count($adminUids),
        'results' => $results
    ];
}

/**
 * 車両問い合わせ時の管理者LINE通知
 */
function sendAdminLineInquiryNotification(array $car, string $inquiryType, ?array $userProfile = null, ?string $rawUserId = null, ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    if (empty($settings['notify_inquiry'])) {
        return ['success' => false, 'reason' => '通知OFF'];
    }

    $title = $car['title'] ?? '車両問い合わせ';
    $totalPrice = $car['total_price_text'] ?? '要問合せ';
    $year = $car['year'] ?? '-';
    $distance = $car['distance'] ?? '-';
    $detailUrl = $car['detail_url'] ?? SHOP_GOO_URL;

    $userName = 'お客様 (名称未設定)';
    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'] . ' 様';
    } elseif (!empty($rawUserId)) {
        $userName = "お客様 (ID: " . substr($rawUserId, 0, 8) . "...)";
    }

    $textMsg = "🚨【車両お問い合わせ】\n"
             . "━━━━━━━━━━━━━━\n"
             . "👤 お客様: {$userName}\n"
             . "🎯 ご希望: {$inquiryType}\n"
             . "🚗 車両: {$title}\n"
             . "💰 総額: {$totalPrice} (年式:{$year} / 走行:{$distance})\n"
             . "━━━━━━━━━━━━━━\n"
             . "LINE公式アカウントのチャット等で詳細をご確認ください。\n"
             . "🔗 車両詳細: {$detailUrl}";

    $messages = [
        ['type' => 'text', 'text' => $textMsg]
    ];

    return sendAdminLineBroadcast($messages, $pdo);
}

/**
 * 来店・点検予約時の管理者LINE通知
 */
function sendAdminLineMaintenanceBookingNotification(string $bookingType, string $carModel, string $prefTime, ?array $userProfile = null, ?string $rawUserId = null, ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    if (empty($settings['notify_booking'])) {
        return ['success' => false, 'reason' => '通知OFF'];
    }

    $userName = 'お客様 (名称未設定)';
    if (!empty($userProfile['displayName'])) {
        $userName = $userProfile['displayName'] . ' 様';
    } elseif (!empty($rawUserId)) {
        $userName = "お客様 (ID: " . substr($rawUserId, 0, 8) . "...)";
    }

    $textMsg = "🛠️【来店・点検予約のお申し込み】\n"
             . "━━━━━━━━━━━━━━\n"
             . "👤 お客様: {$userName}\n"
             . "📋 種別: {$bookingType}\n"
             . "🚗 愛車: {$carModel}\n"
             . "📅 ご希望: {$prefTime}\n"
             . "━━━━━━━━━━━━━━\n"
             . "ピット状況を確認し、LINEチャットにて確定日程やお見積もりをご案内してください。";

    $messages = [
        ['type' => 'text', 'text' => $textMsg]
    ];

    return sendAdminLineBroadcast($messages, $pdo);
}

/**
 * 新規顧客LINE連携時の管理者LINE通知
 */
function sendAdminLineNewCustomerNotification(string $userId, string $userName, ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    if (empty($settings['notify_new_customer'])) {
        return ['success' => false, 'reason' => '通知OFF'];
    }

    $displayName = $userName ?: '名称未設定のお客様';

    $textMsg = "🆕【新規顧客登録 (LINE連携)】\n"
             . "━━━━━━━━━━━━━━\n"
             . "👤 お客様: {$displayName} 様\n"
             . "🆔 LINE UID: {$userId}\n"
             . "━━━━━━━━━━━━━━\n"
             . "マイカー点検パスポートが開かれました。\n店舗管理画面より車両情報や車検日をご登録いただけます。";

    $messages = [
        ['type' => 'text', 'text' => $textMsg]
    ];

    return sendAdminLineBroadcast($messages, $pdo);
}

/**
 * 新着在庫車両検知時の管理者LINE通知
 */
function sendAdminLineNewCarsNotification(array $newCars, ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    if (empty($settings['notify_new_cars'])) {
        return ['success' => false, 'reason' => '通知OFF'];
    }

    $count = count($newCars);
    if ($count === 0) return ['success' => false, 'reason' => '0件'];

    $lines = ["🚗✨【新着在庫情報】グーネットに新着車両が {$count}台 掲載されました！\n━━━━━━━━━━━━━━"];
    foreach (array_slice($newCars, 0, 5) as $c) {
        $t = $c['title'] ?? '車両';
        $p = $c['total_price_text'] ?? '要問合せ';
        $lines[] = "• {$t} [{$p}]";
    }
    $lines[] = "━━━━━━━━━━━━━━\n店舗在庫一覧: " . SHOP_GOO_URL;

    $messages = [
        ['type' => 'text', 'text' => implode("\n", $lines)]
    ];

    return sendAdminLineBroadcast($messages, $pdo);
}

/**
 * リマインド定期配信結果の管理者LINE通知
 */
function sendAdminLineReminderReport(int $oilCount, int $periodicCount, int $shakenCount, array $details = [], ?PDO $pdo = null): array {
    $settings = getAdminLineSettings($pdo);
    if (empty($settings['notify_reminder'])) {
        return ['success' => false, 'reason' => '通知OFF'];
    }

    $total = $oilCount + $periodicCount + $shakenCount;
    if ($total === 0) return ['success' => false, 'reason' => '0件'];

    $lines = [
        "⏰【定期配信完了レポート】\n"
        . "本日 {$total}名様へメンテナンス通知を自動送信しました。\n"
        . "━━━━━━━━━━━━━━\n"
        . "🛢️ オイル交換: {$oilCount}件\n"
        . "📋 12ヶ月点検: {$periodicCount}件\n"
        . "🚗 車検満了: {$shakenCount}件\n"
        . "━━━━━━━━━━━━━━"
    ];
    if (!empty($details)) {
        $lines[] = "送信先（一部）:";
        foreach (array_slice($details, 0, 5) as $d) {
            $lines[] = "• {$d['name']}様 ({$d['car']}) ➡ {$d['type']}";
        }
    }

    $messages = [
        ['type' => 'text', 'text' => implode("\n", $lines)]
    ];

    return sendAdminLineBroadcast($messages, $pdo);
}

/**
 * 管理者LINE通知テスト送信
 */
function sendAdminLineTestNotification(string $targetUid, ?PDO $pdo = null): array {
    $targetUid = trim($targetUid);
    if (empty($targetUid) || !str_starts_with($targetUid, 'U')) {
        return ['success' => false, 'error' => '有効なLINE UID (Uから始まる33文字のID) を指定してください'];
    }

    $time = date('Y/m/d H:i:s');
    $textMsg = "🔔【管理者LINE通知 テスト送信】\n"
             . "━━━━━━━━━━━━━━\n"
             . "このメッセージはアップファーレン店舗管理システムの動作確認テストです。\n"
             . "送信日時: {$time}\n"
             . "━━━━━━━━━━━━━━\n"
             . "✅ 設定は正常に動作しています！\n"
             . "お客様からの「車両問い合わせ」「点検・来店予約」「新規顧客登録」などの重要通知が、このアカウントへ自動でプッシュ通知されます。";

    $messages = [
        ['type' => 'text', 'text' => $textMsg]
    ];

    return sendLinePushMessage($targetUid, $messages);
}

/**
 * プロライン連携設定を取得 (DB優先、未設定時は定数デフォルト)
 */
function getProlineSettings(?PDO $pdo = null): array {
    if (!$pdo) {
        try {
            $pdo = getDbConnection();
        } catch (Exception $e) {
            return [
                'webhook_url' => defined('PROLINE_WEBHOOK_URL') ? PROLINE_WEBHOOK_URL : '',
                'relay_enabled' => defined('PROLINE_RELAY_ENABLED') ? PROLINE_RELAY_ENABLED : true,
                'calendar_url' => defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : 'https://fsmk.co/t/yQ7ocg-grscdt?openExternalBrowser=1',
                'last_relay_at' => '',
                'last_relay_status' => '',
                'last_relay_http_code' => 0
            ];
        }
    }

    try {
        $stmt = $pdo->prepare("SELECT key, value FROM system_settings WHERE key LIKE 'proline_%'");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $url = isset($rows['proline_webhook_url']) ? $rows['proline_webhook_url'] : (defined('PROLINE_WEBHOOK_URL') ? PROLINE_WEBHOOK_URL : '');
        $enabled = isset($rows['proline_relay_enabled']) ? (bool)(int)$rows['proline_relay_enabled'] : (defined('PROLINE_RELAY_ENABLED') ? PROLINE_RELAY_ENABLED : true);
        $defaultCalUrl = defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1';
        $calUrl = isset($rows['proline_calendar_url']) ? $rows['proline_calendar_url'] : $defaultCalUrl;

        // 旧URL（fsmk.co や裸のautosns.app）がDBに残っている場合は自動でLIFF個別予約URLへ更新
        if (str_contains($calUrl, 'fsmk.co') || (str_contains($calUrl, 'autosns.app') && !str_contains($calUrl, 'liff.line.me')) || empty($calUrl)) {
            $calUrl = $defaultCalUrl;
            try {
                $upStmt = $pdo->prepare("INSERT INTO system_settings (key, value, updated_at) VALUES ('proline_calendar_url', :val, datetime('now', '+9 hours')) ON CONFLICT(key) DO UPDATE SET value = :val, updated_at = datetime('now', '+9 hours')");
                $upStmt->execute([':val' => $calUrl]);
            } catch (Exception $ign) {}
        }

        return [
            'webhook_url' => trim($url),
            'relay_enabled' => $enabled,
            'calendar_url' => trim($calUrl),
            'last_relay_at' => $rows['proline_last_relay_at'] ?? '',
            'last_relay_status' => $rows['proline_last_relay_status'] ?? '',
            'last_relay_http_code' => (int)($rows['proline_last_relay_http_code'] ?? 0)
        ];
    } catch (Exception $e) {
        return [
            'webhook_url' => defined('PROLINE_WEBHOOK_URL') ? PROLINE_WEBHOOK_URL : '',
            'relay_enabled' => defined('PROLINE_RELAY_ENABLED') ? PROLINE_RELAY_ENABLED : true,
            'calendar_url' => defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1',
            'last_relay_at' => '',
            'last_relay_status' => '',
            'last_relay_http_code' => 0
        ];
    }
}

/**
 * プロライン連携設定を保存
 */
function saveProlineSettings(string $url, bool $enabled, string $calendarUrl = '', ?PDO $pdo = null): array {
    if (!$pdo) {
        $pdo = getDbConnection();
    }

    $url = trim($url);
    if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'error' => '有効なWebhook URL形式（https://...）を入力してください'];
    }

    $calendarUrl = trim($calendarUrl);
    if (!empty($calendarUrl) && !filter_var($calendarUrl, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'error' => '有効なカレンダーURL形式（https://...）を入力してください'];
    }

    $nowJst = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES (:key, :val, :updated_at)");
    
    $stmt->execute([':key' => 'proline_webhook_url', ':val' => $url, ':updated_at' => $nowJst]);
    $stmt->execute([':key' => 'proline_relay_enabled', ':val' => $enabled ? '1' : '0', ':updated_at' => $nowJst]);
    if (!empty($calendarUrl)) {
        $stmt->execute([':key' => 'proline_calendar_url', ':val' => $calendarUrl, ':updated_at' => $nowJst]);
    }

    return ['success' => true, 'settings' => getProlineSettings($pdo)];
}

/**
 * プロラインへWebhookリクエストを完全中継（プロキシPOST）
 * 
 * @param string $rawBody LINEから受信した生のJSONペイロード
 * @param string $signature LINE署名（X-Line-Signature）
 * @param PDO|null $pdo DB接続インスタンス
 * @return array 中継結果
 */
function relayWebhookToProline(string $rawBody, string $signature = '', ?PDO $pdo = null): array {
    $settings = getProlineSettings($pdo);
    $url = $settings['webhook_url'];
    $enabled = $settings['relay_enabled'];

    if (!$enabled || empty($url)) {
        return [
            'success' => false,
            'relayed' => false,
            'reason' => empty($url) ? 'Proline Webhook URL is empty' : 'Proline Relay is disabled',
            'http_code' => 0
        ];
    }

    $startTime = microtime(true);
    $headers = [
        'Content-Type: application/json; charset=UTF-8',
        'User-Agent: LineBot-ProLine-Relay-Proxy/1.0'
    ];
    if (!empty($signature)) {
        $headers[] = 'X-Line-Signature: ' . $signature;
        $headers[] = 'x-line-signature: ' . $signature;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $rawBody,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,           // LINEのタイムアウト対策のため短めに設定
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrNo = curl_errno($ch);
    $curlError = curl_error($ch);
    $durationMs = round((microtime(true) - $startTime) * 1000, 2);
    curl_close($ch);

    $nowJst = date('Y-m-d H:i:s');
    $isSuccess = ($curlErrNo === 0 && $httpCode >= 200 && $httpCode < 400);
    $statusText = $isSuccess ? "OK ({$durationMs}ms)" : "FAIL ({$httpCode}: {$curlError})";

    // ログ記録
    $logLine = "[{$nowJst}] PROLINE_RELAY: {$statusText} | URL: {$url} | Bytes: " . strlen($rawBody) . "\n";
    @file_put_contents(__DIR__ . '/proline_relay.log', $logLine, FILE_APPEND | LOCK_EX);

    // DBに直近の中継状況を保存
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES (:key, :val, :updated_at)");
            $stmt->execute([':key' => 'proline_last_relay_at', ':val' => $nowJst, ':updated_at' => $nowJst]);
            $stmt->execute([':key' => 'proline_last_relay_status', ':val' => $statusText, ':updated_at' => $nowJst]);
            $stmt->execute([':key' => 'proline_last_relay_http_code', ':val' => (string)$httpCode, ':updated_at' => $nowJst]);
        } catch (Exception $e) {}
    }

    return [
        'success' => $isSuccess,
        'relayed' => true,
        'http_code' => $httpCode,
        'duration_ms' => $durationMs,
        'error' => $curlError,
        'response' => $response
    ];
}

// ==========================================================================
// シニア向けスマホ・PCお役立ち情報 & クイックリプライ閲覧エンジン
// ==========================================================================

/**
 * シニア向けお役立ち全10テーマのマスター定義
 */
function getSeniorKnowledgePresets(): array {
    $bookingUrl = defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1';

    return [
        'scam_virus_alert' => [
            'id' => 'scam_virus_alert',
            'category' => '🚨 偽警告・詐欺対策',
            'badge_color' => '#e11d48',
            'label' => '🚨 偽警告詐欺対策',
            'title' => '🚨 パソコンの「ウイルス感染警告」は詐欺！慌てず閉じる方法',
            'subtitle' => '画面に突然ピーッと警告音や電話番号が出ても絶対に電話をかけてはいけません！',
            'points' => [
                '画面に表示される電話番号には絶対に電話しない',
                'キーボードの「Esc」長押し、または「Ctrl+Alt+Delete」で画面を閉じる',
                '不安な時は電源ボタン長押しで強制終了し、教室へご相談ください'
            ],
            'advice' => '「警告画面が消えない」「操作が不安」という時は、無理に触らずそのまま教室へお持ちください。スタッフが一緒に安全を確認します！',
            'btn1_label' => '📅 教室で直接相談・予約する',
            'btn1_url' => $bookingUrl
        ],
        'scam_fake_sms' => [
            'id' => 'scam_fake_sms',
            'category' => '⚠️ 不在通知詐欺対策',
            'badge_color' => '#e11d48',
            'label' => '⚠️ 偽SMS対策',
            'title' => '⚠️ ヤマトや佐川を名乗る偽SMS（不在通知）にご注意！',
            'subtitle' => '「お荷物をお届けにあがりましたが…」というSMSのリンクは絶対に開かないでください！',
            'points' => [
                'SMSに書かれた青い英数字リンク（URL）は絶対に押さない',
                '荷物の確認は、公式アプリやLINEの公式通知から行う',
                '万が一リンクを開いてしまっても、電話番号やパスワードは絶対に入力しない'
            ],
            'advice' => '心当たりのない不審なSMSが届いた時は、削除するか、スクリーンショットを撮って教室でお見せください！',
            'btn1_label' => '📅 教室で直接相談・予約する',
            'btn1_url' => $bookingUrl
        ],
        'phone_large_text' => [
            'id' => 'phone_large_text',
            'category' => '📱 スマホ見やすさ設定',
            'badge_color' => '#0284c7',
            'label' => '📱 スマホ文字拡大',
            'title' => '📱 スマホの文字をもっと大きく！目に優しい簡単設定',
            'subtitle' => '「画面の文字が小さくて読みづらい…」とお悩みの方へ。文字を大きく太くする設定です！',
            'points' => [
                'iPhone: 「設定」→「画面表示と明るさ」→「テキストサイズを変更」',
                'Android: 「設定」→「ディスプレイ」→「フォントサイズと表示サイズ」',
                '「文字を太くする」をオンにすると、さらにクッキリ見やすくなります'
            ],
            'advice' => '教室のレッスンで、ご自身のスマホに合わせて一番読みやすい大きさに一緒に設定調整いたします！',
            'btn1_label' => '📅 スマホ設定を教室で相談する',
            'btn1_url' => $bookingUrl
        ],
        'line_font_size' => [
            'id' => 'line_font_size',
            'category' => '💬 LINE便利ワザ',
            'badge_color' => '#7c3aed',
            'label' => '💬 LINE文字特大化',
            'title' => '💬 LINEのメッセージ文字だけを特大サイズにする方法',
            'subtitle' => 'お友だちやご家族からのメッセージがぐんと読みやすくなります！',
            'points' => [
                'LINEの「ホーム」右上の歯車マーク（設定）をタップ',
                '「トーク」→「フォントサイズ」を選ぶ',
                '「特大」を選ぶと、トークの文字が大きく見やすくなります'
            ],
            'advice' => 'スマホ全体の文字は変えずに、LINEだけ大きくすることも可能です。教室で一緒にやってみましょう！',
            'btn1_label' => '📅 レッスン予約・日程変更',
            'btn1_url' => $bookingUrl
        ],
        'battery_care' => [
            'id' => 'battery_care',
            'category' => '🔋 スマホ長持ちのコツ',
            'badge_color' => '#0284c7',
            'label' => '🔋 電池長持ちの習慣',
            'title' => '🔋 スマートフォンのバッテリーを長持ちさせる3つの習慣',
            'subtitle' => '電池の減りが早くなってきたと感じたら、この使い方を試してみてください！',
            'points' => [
                '充電しながらの長時間の動画視聴や操作を避ける（発熱予防）',
                '画面の明るさを「自動調整」にするか、少し暗めに設定する',
                '使っていない時はWi-FiやBluetoothをこまめにオフにする'
            ],
            'advice' => '「夕方には充電が切れてしまう」「スマホが熱くなる」などの点検も教室で行っています。お気軽に診断へお越しください！',
            'btn1_label' => '🛠️ スマホ・PC健康診断を予約',
            'btn1_url' => $bookingUrl
        ],
        'photo_cleanup' => [
            'id' => 'photo_cleanup',
            'category' => '📸 写真・容量整理',
            'badge_color' => '#0284c7',
            'label' => '📸 写真の簡単整理術',
            'title' => '📸 スマホの容量がいっぱい？たまった写真の簡単整理術',
            'subtitle' => 'お孫さんの写真や旅行の写真でメモリがいっぱいになる前の安心お手入れ法です！',
            'points' => [
                'ブレた写真や連写写真、不要なスクリーンショットを先に削除',
                'お気に入りの写真には「♡（ハートマーク）」を付けて整理',
                'Googleフォトやパソコンへ定期バックアップしてスマホをスッキリ'
            ],
            'advice' => '「写真が消えたら怖い」「パソコンへ写真を移したい」時は、USBケーブルを持って教室へお越しください。安全なバックアップ手順をお教えします！',
            'btn1_label' => '📅 写真整理レッスンを予約',
            'btn1_url' => $bookingUrl
        ],
        'pc_restart_magic' => [
            'id' => 'pc_restart_magic',
            'category' => '⚡ パソコン快適化',
            'badge_color' => '#059669',
            'label' => '⚡ PC再起動の魔法',
            'title' => '⚡ パソコンが重い・動かない？「再起動」の魔法とシャットダウンの違い',
            'subtitle' => '調子が悪い時は、まず「再起動」を試すのが一番の特効薬です！',
            'points' => [
                'Windowsの「シャットダウン」は前回の状態を一部保存して終了します',
                '「再起動」を選ぶと、メモリが完全にリセットされて動作が軽くなります',
                '週に1〜2回は「スタート」→「電源」→「再起動」を行うのがオススメ'
            ],
            'advice' => '再起動しても動きが遅い・ファンが大きな音で回る場合は、不要ソフトの整理が必要かもしれません。教室でPC健康診断をお受けいただけます！',
            'btn1_label' => '🛠️ パソコン健康診断を予約',
            'btn1_url' => $bookingUrl
        ],
        'pc_shortcuts' => [
            'id' => 'pc_shortcuts',
            'category' => '⌨️ パソコン便利技',
            'badge_color' => '#059669',
            'label' => '⌨️ 3大ショートカット',
            'title' => '⌨️ これだけは覚えたい！パソコン3大魔法のショートカットキー',
            'subtitle' => 'マウスで何度もカチカチ探すより、左手ひとつでパッと操作できるようになります！',
            'points' => [
                '【元に戻す】Ctrl ＋ Z（間違えて消してしまった文字や操作が一瞬で復活！）',
                '【コピー】Ctrl ＋ C（選んだ文字や写真をサッと複製）',
                '【貼り付け】Ctrl ＋ V（コピーした内容を好きな場所へペタッと貼る）'
            ],
            'advice' => 'Ctrl（コントロールキー）はキーボードの左下にあります！レッスンで実際に指を置いて練習してみましょう。',
            'btn1_label' => '📅 レッスン予約・日程変更',
            'btn1_url' => $bookingUrl
        ],
        'pc_caps_lock' => [
            'id' => 'pc_caps_lock',
            'category' => '🔤 文字入力トラブル',
            'badge_color' => '#059669',
            'label' => '🔤 大文字ロック解除',
            'title' => '🔤 文字が勝手に大文字になる？「Caps Lock」のワンキー解決法',
            'subtitle' => 'パスワードやアルファベットを入力した時、全部大文字になって困ったことはありませんか？',
            'points' => [
                '原因はキーボードの「Shift」と「Caps Lock」を一緒に押してしまったこと',
                '解決法: 「Shift」キーを押しながら「Caps Lock」キーをもう1度押すだけ！',
                'キーボード上の小さなランプ（Aのランプ）が消えれば通常入力に戻ります'
            ],
            'advice' => '入力トラブルの多くはキーボードのちょっとした押し間違いです。焦らず教室スタッフにいつでもご質問ください！',
            'btn1_label' => '📅 教室で質問・予約',
            'btn1_url' => $bookingUrl
        ],
        'disaster_apps' => [
            'id' => 'disaster_apps',
            'category' => '🏥 安心・暮らしのデジタル',
            'badge_color' => '#d97706',
            'label' => '🏥 スマホ防災速報',
            'title' => '🏥 いざという時に安心！スマホで見る防災速報・ハザードマップ',
            'subtitle' => '大雨や地震の際、スマホが命を守る一番の味方になります！',
            'points' => [
                '自治体の公式LINEや「Yahoo!防災速報」を登録しておくと警報が即届く',
                'スマホのGoogleマップで近くの「指定避難所」を事前確認しておく',
                '災害用伝言ダイヤル「171」やLINEでの安否確認方法を家族で決めておく'
            ],
            'advice' => '避難所の場所の登録や防災アプリの入れ方がわからない時は、教室でスタッフと一緒に設定しましょう！',
            'btn1_label' => '📅 防災アプリ設定を教室で相談',
            'btn1_url' => $bookingUrl
        ]
    ];
}

/**
 * シニアお役立ち情報用 クイックリプライボタンスキーマ生成
 * LINE Messaging API 仕様準拠（最大13個・postbackアクション・ラベル20文字以内）
 */
function getSeniorKnowledgeQuickReplyItems(?string $currentTopic = null): array {
    $presets = getSeniorKnowledgePresets();
    $items = [];

    // 10テーマのボタンを生成
    foreach ($presets as $tId => $data) {
        $label = mb_substr($data['label'], 0, 20);
        $items[] = [
            'type' => 'action',
            'action' => [
                'type' => 'postback',
                'label' => $label,
                'data' => "action=show_senior_kb&topic={$tId}",
                'displayText' => "「{$label}」を読む"
            ]
        ];
    }

    // 教室質問・相談ボタン
    $items[] = [
        'type' => 'action',
        'action' => [
            'type' => 'postback',
            'label' => '💬 教室に質問・相談',
            'data' => 'action=ask_class&topic=お役立ち情報',
            'displayText' => 'お役立ち情報について教室に質問したい'
        ]
    ];

    // 全テーマ一覧メニュー表示ボタン
    $items[] = [
        'type' => 'action',
        'action' => [
            'type' => 'postback',
            'label' => '📚 全テーマ一覧',
            'data' => 'action=show_knowledge_menu',
            'displayText' => '📚 お役立ちテーマ一覧を見る'
        ]
    ];

    // LINE上限の13個以内に収める
    return [
        'items' => array_slice($items, 0, 13)
    ];
}

/**
 * 特定のトピックのお役立ちFlex Messageカードを構築
 */
function generateSeniorKnowledgeFlexMessage(string $topicId, bool $attachQuickReply = true): array {
    $presets = getSeniorKnowledgePresets();
    $data = $presets[$topicId] ?? reset($presets);

    $category = $data['category'];
    $badgeColor = $data['badge_color'];
    $title = $data['title'];
    $subtitle = $data['subtitle'];
    $points = $data['points'] ?? [];
    $advice = $data['advice'] ?? '';
    $btn1Label = $data['btn1_label'] ?? '📅 教室で直接相談・予約する';
    $btn1Url = $data['btn1_url'] ?? (defined('PROLINE_CALENDAR_URL') ? PROLINE_CALENDAR_URL : '');

    // ポイント一覧
    $pointBoxContents = [];
    $numIcons = ['①', '②', '③', '④', '⑤'];
    foreach ($points as $idx => $pt) {
        $ptText = trim((string)$pt);
        if (empty($ptText)) continue;
        $icon = $numIcons[$idx] ?? '・';
        $item = [
            'type' => 'box',
            'layout' => 'horizontal',
            'spacing' => 'sm',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => $icon,
                    'weight' => 'bold',
                    'size' => 'sm',
                    'color' => $badgeColor,
                    'flex' => 1
                ],
                [
                    'type' => 'text',
                    'text' => $ptText,
                    'size' => 'sm',
                    'color' => '#1e293b',
                    'wrap' => true,
                    'weight' => 'bold',
                    'flex' => 11
                ]
            ]
        ];
        if ($idx > 0) {
            $item['margin'] = 'md';
        }
        $pointBoxContents[] = $item;
    }

    $bodyContents = [
        // カテゴリピルバッジ
        [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'baseline',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '💡 ' . $category,
                            'size' => 'xs',
                            'weight' => 'bold',
                            'color' => '#ffffff'
                        ]
                    ],
                    'backgroundColor' => $badgeColor,
                    'paddingAll' => '5px',
                    'paddingStart' => '12px',
                    'paddingEnd' => '12px',
                    'cornerRadius' => 'xxl',
                    'flex' => 0
                ]
            ]
        ],
        // タイトル
        [
            'type' => 'text',
            'text' => $title,
            'weight' => 'bold',
            'size' => 'lg',
            'margin' => 'md',
            'color' => '#0f172a',
            'wrap' => true
        ]
    ];

    if (!empty($subtitle)) {
        $bodyContents[] = [
            'type' => 'text',
            'text' => $subtitle,
            'size' => 'xs',
            'color' => '#475569',
            'margin' => 'sm',
            'wrap' => true
        ];
    }

    $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];

    if (!empty($pointBoxContents)) {
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'md',
            'backgroundColor' => '#f8fafc',
            'paddingAll' => '14px',
            'cornerRadius' => 'lg',
            'borderColor' => '#e2e8f0',
            'borderWidth' => '1px',
            'contents' => array_merge([
                [
                    'type' => 'text',
                    'text' => '【覚えておきたいポイント】',
                    'weight' => 'bold',
                    'size' => 'xs',
                    'color' => '#64748b'
                ],
                [
                    'type' => 'separator',
                    'margin' => 'sm'
                ]
            ], $pointBoxContents)
        ];
    }

    if (!empty($advice)) {
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'md',
            'backgroundColor' => '#fffbeb',
            'paddingAll' => '12px',
            'cornerRadius' => 'md',
            'borderColor' => '#fef3c7',
            'borderWidth' => '1px',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => '👩‍🏫 教室スタッフからのアドバイス',
                    'weight' => 'bold',
                    'size' => 'xs',
                    'color' => '#b45309'
                ],
                [
                    'type' => 'text',
                    'text' => $advice,
                    'size' => 'xs',
                    'color' => '#78350f',
                    'wrap' => true,
                    'margin' => 'xs'
                ]
            ]
        ];
    }

    $bodyContents[] = [
        'type' => 'text',
        'text' => '※わからない操作や気になる点はお気軽に教室でお尋ねください。',
        'size' => 'xxs',
        'color' => '#94a3b8',
        'margin' => 'md',
        'wrap' => true
    ];

    // フッターボタン
    $footerButtons = [];
    if (!empty($btn1Label)) {
        $footerButtons[] = [
            'type' => 'button',
            'style' => 'primary',
            'color' => $badgeColor,
            'height' => 'sm',
            'action' => [
                'type' => 'uri',
                'label' => mb_substr($btn1Label, 0, 20),
                'uri' => !empty($btn1Url) ? $btn1Url : 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1'
            ]
        ];
    }

    // LINE postback action の data は250バイト以内に収める
    $shortTopic = mb_substr($title, 0, 15);
    $encodedTopic = urlencode($shortTopic);
    while (strlen("action=ask_class&topic={$encodedTopic}") > 250 && mb_strlen($shortTopic) > 0) {
        $shortTopic = mb_substr($shortTopic, 0, -1);
        $encodedTopic = urlencode($shortTopic);
    }
    $footerButtons[] = [
        'type' => 'button',
        'style' => 'secondary',
        'height' => 'sm',
        'action' => [
            'type' => 'postback',
            'label' => '💬 LINEで質問・相談する',
            'data' => "action=ask_class&topic={$encodedTopic}",
            'displayText' => mb_substr("「{$title}」について教室に質問・相談したい", 0, 100)
        ]
    ];

    $bubble = [
        'type' => 'bubble',
        'size' => 'mega',
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '18px',
            'contents' => $bodyContents
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'spacing' => 'sm',
            'paddingAll' => '14px',
            'contents' => $footerButtons
        ]
    ];

    $msg = [
        'type' => 'flex',
        'altText' => mb_substr("【お役立ち情報】{$title}", 0, 400),
        'contents' => $bubble
    ];

    if ($attachQuickReply) {
        $msg['quickReply'] = getSeniorKnowledgeQuickReplyItems($topicId);
    }

    return $msg;
}

