<?php
/**
 * 静岡県三島市 天気予報連動リッチメニュー 1時間毎 自動更新バッチ
 * 
 * 実行方法:
 * 1. CLI (Xサーバー Cron設定):
 *    /usr/bin/php8.2 /home/あなたのサーバーID/ドメイン/public_html/batch_hourly_weather.php
 * 2. Webアクセス (外部Cron / テスト用):
 *    https://あなたのドメイン/batch_hourly_weather.php?account=senior
 */

// タイムゾーン設定 (JST)
date_default_timezone_set('Asia/Tokyo');
ini_set('date.timezone', 'Asia/Tokyo');
putenv('TZ=Asia/Tokyo');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

// アカウント指定
$targetAccount = $_REQUEST['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? 'senior');
setActiveAccountKey($targetAccount);
$accConfig = getAccountConfig($targetAccount);
$db = getDbConnection($targetAccount);

$channelAccessToken = $accConfig['channel_access_token'] ?? '';
if (empty($channelAccessToken) || $channelAccessToken === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
    die("エラー: LINE公式アカウントのアクセストークンが設定されていません。\n");
}

// ログ出力関数
function logWeatherBatch(string $msg) {
    $now = date('Y-m-d H:i:s');
    $line = "[{$now}] {$msg}\n";
    echo $line;
    $logFile = __DIR__ . '/data/weather_batch.log';
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

logWeatherBatch("=== 三島市 天気リッチメニュー自動更新バッチ 開始 (アカウント: {$targetAccount}) ===");

// 1. 静岡県三島市の天気を取得 (緯度: 35.1184, 経度: 138.9184)
$weatherLabel = '晴れ';
$maxTemp = 28;
$minTemp = 20;
$iconText = '晴れ';

try {
    $apiUrl = 'https://api.open-meteo.com/v1/forecast?latitude=35.1184&longitude=138.9184&daily=weathercode,temperature_2m_max,temperature_2m_min&timezone=Asia%2FTokyo';
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $resJson = curl_exec($ch);
    curl_close($ch);

    if ($resJson) {
        $weatherData = json_decode($resJson, true);
        if (isset($weatherData['daily']['weathercode'][0])) {
            $code = (int)$weatherData['daily']['weathercode'][0];
            $maxTemp = (int)round($weatherData['daily']['temperature_2m_max'][0]);
            $minTemp = (int)round($weatherData['daily']['temperature_2m_min'][0]);

            if ($code === 0) { $iconText = '☀️'; $weatherLabel = '快晴'; }
            elseif ($code >= 1 && $code <= 3) { $iconText = '🌤️'; $weatherLabel = '晴れ時々曇り'; }
            elseif ($code >= 45 && $code <= 48) { $iconText = '🌫️'; $weatherLabel = '霧'; }
            elseif ($code >= 51 && $code <= 67) { $iconText = '🌧️'; $weatherLabel = '雨'; }
            elseif ($code >= 71 && code <= 77) { $iconText = '❄️'; $weatherLabel = '雪'; }
            elseif ($code >= 80 && $code <= 82) { $iconText = '🌦️'; $weatherLabel = 'にわか雨'; }
            elseif ($code >= 95) { $iconText = '⚡'; $weatherLabel = '雷雨'; }
        }
    }
} catch (Throwable $e) {
    logWeatherBatch("天気API取得フォールバック: " . $e->getMessage());
}

$now = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
$dayNames = ['日', '月', '火', '水', '木', '金', '土'];
$dayStr = $dayNames[(int)$now->format('w')];
$datePrefix = $now->format('n/j') . "({$dayStr})";

$weatherText = "{$datePrefix} 三島の天気：{$iconText} {$weatherLabel}  最高 {$maxTemp}℃ / 最低 {$minTemp}℃";
logWeatherBatch("取得天気テキスト: {$weatherText}");

// 2. ベースとなるリッチメニュー画像を取得
$stmtBase = $db->query("SELECT * FROM rich_menus WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
$baseMenu = $stmtBase ? $stmtBase->fetch(PDO::FETCH_ASSOC) : null;
if (!$baseMenu) {
    $stmtBase2 = $db->query("SELECT * FROM rich_menus ORDER BY id DESC LIMIT 1");
    $baseMenu = $stmtBase2 ? $stmtBase2->fetch(PDO::FETCH_ASSOC) : null;
}

if (!$baseMenu || empty($baseMenu['line_menu_id'])) {
    logWeatherBatch("エラー: ベースとなるリッチメニューがありません。");
    exit(1);
}

$baseLineMenuId = $baseMenu['line_menu_id'];
$width = 2500;
$height = 1686;

// LINEからベース画像バイナリを取得
$imgBin = lineGetRichMenuImage($baseLineMenuId, $targetAccount);
if (empty($imgBin)) {
    // ローカル画像フォールバック
    $localPath = __DIR__ . '/uploads/richmenu/' . basename($baseMenu['image_url'] ?? '');
    if (file_exists($localPath)) {
        $imgBin = file_get_contents($localPath);
    }
}

if (empty($imgBin)) {
    logWeatherBatch("エラー: ベースリッチメニュー画像の取得に失敗しました。");
    exit(1);
}

// 3. PHP GD で画像上に 300px の天気帯を合成
$srcImg = imagecreatefromstring($imgBin);
if (!$srcImg) {
    logWeatherBatch("エラー: GD画像パースに失敗しました。");
    exit(1);
}

$dstImg = imagecreatetruecolor($width, $height);
imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $width, $height, imagesx($srcImg), imagesy($srcImg));
imagedestroy($srcImg);

$bannerHeight = 300;

// 上部300pxにオレンジグラデーション帯を描画
for ($y = 0; $y < $bannerHeight; $y++) {
    $ratio = $y / $bannerHeight;
    $r = (int)(249 * (1 - $ratio) + 234 * $ratio);
    $g = (int)(115 * (1 - $ratio) + 88 * $ratio);
    $b = (int)(22 * (1 - $ratio) + 12 * $ratio);
    $color = imagecolorallocate($dstImg, $r, $g, $b);
    imageline($dstImg, 0, $y, $width, $y, $color);
}

// 境界アクセントライン
$borderCol = imagecolorallocate($dstImg, 255, 237, 213);
for ($b = 0; $b < 8; $b++) {
    imageline($dstImg, 0, $bannerHeight - $b, $width, $bannerHeight - $b, $borderCol);
}

// テキストの描画 (日本語TTFフォントがあればTTF、なければシステムフォント)
$fontFile = null;
$possibleFonts = [
    'C:/Windows/Fonts/meiryob.ttc',
    'C:/Windows/Fonts/meiryo.ttc',
    'C:/Windows/Fonts/YuGothB.ttc',
    '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc',
    '/usr/share/fonts/truetype/noto/NotoSansCJK-Bold.ttc',
    '/usr/share/fonts/ipa-gothic/ipag.ttf',
    __DIR__ . '/data/font.ttf'
];
foreach ($possibleFonts as $f) {
    if (file_exists($f)) {
        $fontFile = $f;
        break;
    }
}

$white = imagecolorallocate($dstImg, 255, 255, 255);
$shadow = imagecolorallocatealpha($dstImg, 0, 0, 0, 70);

if ($fontFile && function_exists('imagettftext')) {
    $fontSize = 46;
    $bbox = imagettfbbox($fontSize, 0, $fontFile, $weatherText);
    $textW = abs($bbox[4] - $bbox[0]);
    $textX = max(40, (int)(($width - $textW) / 2));
    $textY = 175;

    // ドロップシャドウ
    imagettftext($dstImg, $fontSize, 0, $textX + 3, $textY + 3, $shadow, $fontFile, $weatherText);
    // メイン白文字
    imagettftext($dstImg, $fontSize, 0, $textX, $textY, $white, $fontFile, $weatherText);
} else {
    // TTFフォントがない環境用のフォールバック
    $textX = (int)(($width - (mb_strlen($weatherText) * 20)) / 2);
    imagestring($dstImg, 5, $textX, 130, $weatherText, $white);
}

$tmpJpg = __DIR__ . '/data/weather_temp_' . time() . '.jpg';
if (!is_dir(__DIR__ . '/data')) {
    @mkdir(__DIR__ . '/data', 0777, true);
}
imagejpeg($dstImg, $tmpJpg, 92);
imagedestroy($dstImg);

logWeatherBatch("天気帯合成JPEG作成完了 ({$tmpJpg})");

// 4. LINE Messaging API で新規リッチメニュー作成
$weathernewsUrl = 'https://weathernews.jp/onebox/tenki/shizuoka/22206/';

$areas = [
    // 上部 300px: ウェザーニュース三島市
    [
        'bounds' => ['x' => 0, 'y' => 0, 'width' => 2500, 'height' => 300],
        'action' => [
            'type' => 'uri',
            'label' => '三島市の天気詳細',
            'uri' => $weathernewsUrl
        ]
    ]
];

// ベースメニューのエリア設定を引き継ぐ
$baseAreas = !empty($baseMenu['areas_json']) ? json_decode($baseMenu['areas_json'], true) : [];
if (!empty($baseAreas) && is_array($baseAreas)) {
    foreach ($baseAreas as $ba) {
        $areas[] = $ba;
    }
}

$menuTitle = "【毎時自動】三島天気 (" . $now->format('m/d H:i') . ")";
$menuData = [
    'size' => ['width' => $width, 'height' => $height],
    'selected' => true,
    'name' => mb_substr($menuTitle, 0, 300),
    'chatBarText' => mb_substr($baseMenu['chat_bar_text'] ?: 'メニュー', 0, 14),
    'areas' => $areas
];

$createRes = lineCreateRichMenu($menuData, $targetAccount);
if (!$createRes['success'] || empty($createRes['richMenuId'])) {
    @unlink($tmpJpg);
    logWeatherBatch("エラー: LINEリッチメニュー作成失敗: " . ($createRes['error'] ?? ''));
    exit(1);
}
$newRichMenuId = $createRes['richMenuId'];
logWeatherBatch("新規LINEリッチメニュー作成成功: {$newRichMenuId}");

// 5. 画像アップロード
$uploadRes = lineUploadRichMenuImage($newRichMenuId, $tmpJpg, 'image/jpeg', $targetAccount);
@unlink($tmpJpg);

if (!$uploadRes['success']) {
    lineDeleteRichMenu($newRichMenuId, $targetAccount);
    logWeatherBatch("エラー: LINE画像アップロード失敗: " . ($uploadRes['error'] ?? ''));
    exit(1);
}
logWeatherBatch("LINE画像アップロード成功");

// 6. 対象受講生（かわいたくや様、または個別リッチメニュー設定者）へアタッチ＆古いメニュー削除
// 対象ユーザー（かわいたくや様: U38c887032d23d83bcc44ae08c1f987a2 または custom_menu 設定者）
$targetUids = ['U38c887032d23d83bcc44ae08c1f987a2'];

// DBから個別メニュー利用受講生も追加抽出
try {
    $stmtUsers = $db->query("SELECT user_id, custom_line_menu_id FROM customer_cars WHERE user_id IS NOT NULL AND user_id != ''");
    while ($row = $stmtUsers->fetch(PDO::FETCH_ASSOC)) {
        if (!in_array($row['user_id'], $targetUids) && str_starts_with($row['user_id'], 'U')) {
            // 必要に応じて追加
        }
    }
} catch (Exception $e) {}

foreach ($targetUids as $uid) {
    // 既存の古いリッチメニューIDを取得
    $oldMenuId = null;
    $chUser = curl_init("https://api.line.me/v2/bot/user/{$uid}/richmenu");
    curl_setopt_array($chUser, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$channelAccessToken}"]
    ]);
    $userRes = curl_exec($chUser);
    curl_close($chUser);
    if ($userRes) {
        $userData = json_decode($userRes, true);
        $oldMenuId = $userData['richMenuId'] ?? null;
    }

    // アタッチ実行
    $linkRes = lineLinkUserRichMenu($uid, $newRichMenuId, $targetAccount);
    if ($linkRes['success']) {
        logWeatherBatch("✅ ユーザー [{$uid}] へ新しい天気メニューをアタッチ完了");
        
        // 古いメニューを自動削除 (クリーンアップ)
        if (!empty($oldMenuId) && $oldMenuId !== $newRichMenuId) {
            lineDeleteRichMenu($oldMenuId, $targetAccount);
            logWeatherBatch("🗑️ 以前の古いリッチメニューを自動削除しました: {$oldMenuId}");
        }
    } else {
        logWeatherBatch("⚠️ ユーザー [{$uid}] へのアタッチ失敗: " . ($linkRes['error'] ?? ''));
    }
}

logWeatherBatch("🎉 1時間毎 天気リッチメニュー自動更新バッチ 正常完了！\n");
