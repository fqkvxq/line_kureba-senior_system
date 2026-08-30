<?php
/**
 * システム共通設定ファイル
 * Xserver環境およびLINE公式アカウントの設定を管理します。
 */

// タイムゾーン設定
date_default_timezone_set('Asia/Tokyo');

// --- LINE公式アカウント設定 ---
// LINE Developersコンソールで取得した情報を入力してください
define('LINE_CHANNEL_ACCESS_TOKEN', '7NAJ7hIbVKu7Zr+JsK+ddFBqPM9EBCkWhqUy3kTuHO1nepA3As1ZWYAB5GuAmx8dQZ1+dqR0Ws57gz+jyTSJk0OUodDA2ci9d6f0xPCtNOT5t0edC9mZGq2GlmuXkJeVx5y6e4ggHd4/DuHr07cAgAdB04t89/1O/w1cDnyilFU='); // チャネルアクセストークン (長期)
define('LINE_CHANNEL_SECRET', '72b453597e968c1605852c809ee733e7');             // チャネルシークレット
define('LINE_LIFF_ID', 'YOUR_LIFF_ID_HERE');                           // LIFF ID (例: 1234567890-AbcdEfgh)

// --- 店舗・システム設定 ---
define('SHOP_CODE', '0601492');
define('SHOP_NAME', 'アップファーム');
define('SHOP_GOO_URL', 'https://www.goo-net.com/usedcar_shop/0601492/stock.html');

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
        @mkdir($dir, 0755, true);
    }

    $pdo = new PDO("sqlite:{$dbFile}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
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
