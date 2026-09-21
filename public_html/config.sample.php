<?php
/**
 * システム共通設定ファイル (サンプル)
 * 実際の環境に合わせて設定し、config.php として保存してください。
 */

// タイムゾーン設定
date_default_timezone_set('Asia/Tokyo');

// --- LINE公式アカウント設定 ---
// LINE Developersコンソールで取得した情報を入力してください
define('LINE_CHANNEL_ACCESS_TOKEN', 'YOUR_CHANNEL_ACCESS_TOKEN_HERE'); // チャネルアクセストークン (長期)
define('LINE_CHANNEL_SECRET', 'YOUR_CHANNEL_SECRET_HERE');             // チャネルシークレット
define('LINE_LIFF_ID', 'YOUR_LIFF_ID_HERE');                           // LIFF ID (例: 1234567890-AbcdEfgh)

// --- 店舗・システム設定 ---
define('SHOP_CODE', '0601492');
define('SHOP_NAME', 'アップファーレン');
define('SHOP_GOO_URL', 'https://www.goo-net.com/usedcar_shop/0601492/stock.html');

// データベースファイルへのパス
define('DB_PATH', __DIR__ . '/../batch/kureba-senior-system.db');

/**
 * データベース接続オブジェクト (PDO) を取得
 * @return PDO
 */
function getDbConnection(): PDO {
    $dbFile = DB_PATH;
    if (!file_exists($dbFile)) {
        $fallback = __DIR__ . '/kureba-senior-system.db';
        if (file_exists($fallback)) {
            $dbFile = $fallback;
        } else {
            $db = new PDO("sqlite:{$dbFile}");
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $db;
        }
    }
    
    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}
