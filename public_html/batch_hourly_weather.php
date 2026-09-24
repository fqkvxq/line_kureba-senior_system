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

// 1. 静岡県三島市の天気を取得 (緯度: 35.1184, 経度: 138.9184, 8日間予報)
$weatherLabel = '晴れ時々曇り';
$weatherIconKey = 'sun_cloud';
$maxTemp = 28;
$minTemp = 20;
$nextRainStr = '';
$futureRainDays = [];

try {
    $apiUrl = 'https://api.open-meteo.com/v1/forecast?latitude=35.1184&longitude=138.9184&hourly=precipitation_probability,precipitation,weathercode&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=Asia%2FTokyo&forecast_days=8';
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

            if ($code === 0) { $weatherLabel = '快晴'; $weatherIconKey = 'sun'; }
            elseif ($code >= 1 && $code <= 3) { $weatherLabel = '晴れ時々曇り'; $weatherIconKey = 'sun_cloud'; }
            elseif ($code >= 45 && $code <= 48) { $weatherLabel = '霧'; $weatherIconKey = 'cloud'; }
            elseif ($code >= 51 && $code <= 67) { $weatherLabel = '雨'; $weatherIconKey = 'rain'; }
            elseif ($code >= 71 && $code <= 77) { $weatherLabel = '雪'; $weatherIconKey = 'snow'; }
            elseif ($code >= 80 && $code <= 82) { $weatherLabel = 'にわか雨'; $weatherIconKey = 'rain'; }
            elseif ($code >= 95) { $weatherLabel = '雷雨'; $weatherIconKey = 'thunder'; }
        }

        $nowTs = time();
        $dayNames = ['日', '月', '火', '水', '木', '金', '土'];

        // 最も近い雨の降り始め時間（時間別予報）
        if (!empty($weatherData['hourly']['time'])) {
            $hTimes = $weatherData['hourly']['time'];
            $hProbs = $weatherData['hourly']['precipitation_probability'] ?? [];
            $hCodes = $weatherData['hourly']['weathercode'] ?? [];

            for ($i = 0; $i < count($hTimes); $i++) {
                $tTs = strtotime($hTimes[$i]);
                if ($tTs >= $nowTs) {
                    $p = (int)($hProbs[$i] ?? 0);
                    $c = (int)($hCodes[$i] ?? 0);

                    if ($p >= 40 || ($c >= 51 && $c <= 67) || ($c >= 80 && $c <= 82)) {
                        $isToday = (date('Y-m-d', $tTs) === date('Y-m-d', $nowTs));
                        $isTomorrow = (date('Y-m-d', $tTs) === date('Y-m-d', $nowTs + 86400));
                        $dayPrefix = $isToday ? '今日' : ($isTomorrow ? '明日' : date('n/j', $tTs) . '(' . $dayNames[(int)date('w', $tTs)] . ')');
                        $hourNum = (int)date('G', $tTs);
                        $nextRainStr = "{$dayPrefix} {$hourNum}時〜 (降水確率{$p}%)";
                        break;
                    }
                }
            }
        }

        // 週間（3日目以降〜8日目）の雨の日をリストアップ
        if (!empty($weatherData['daily']['time'])) {
            $dTimes = $weatherData['daily']['time'];
            $dProbs = $weatherData['daily']['precipitation_probability_max'] ?? [];
            $dCodes = $weatherData['daily']['weathercode'] ?? [];

            for ($d = 2; $d < count($dTimes); $d++) {
                $p = (int)($dProbs[$d] ?? 0);
                $c = (int)($dCodes[$d] ?? 0);
                if ($p >= 50 || ($c >= 51 && $c <= 67) || ($c >= 80 && $c <= 82)) {
                    $dTs = strtotime($dTimes[$d]);
                    $futureRainDays[] = date('j', $dTs) . '日(' . $dayNames[(int)date('w', $dTs)] . ')';
                }
            }
        }
    }
} catch (Throwable $e) {
    logWeatherBatch("天気API取得フォールバック: " . $e->getMessage());
}

$now = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
$dayNames = ['日', '月', '火', '水', '木', '金', '土'];
$dayStr = $dayNames[(int)$now->format('w')];
$datePrefix = $now->format('n/j') . "({$dayStr})";
$hourStr = $now->format('G') . "時時点";

// 1行目のパーツ
$line1_pre = "{$datePrefix} {$hourStr}　三島市の天気：";
$line1_suf = " {$weatherLabel}（最高 {$maxTemp}℃ / 最低 {$minTemp}℃）";

// 2行目のパーツ
$hasRain = !empty($nextRainStr);
if ($hasRain) {
    if (!empty($futureRainDays)) {
        $weekStr = implode('・', array_slice($futureRainDays, 0, 3));
        $line2_text = "直近の雨：{$nextRainStr} ｜ 週間：{$weekStr}も雨予報";
    } else {
        $line2_text = "直近の雨：{$nextRainStr} ｜ その後は晴れ間が広がる見込み";
    }
} else {
    $line2_text = "目先1週間はまとまった雨の心配はありません";
}

logWeatherBatch("1行目: {$line1_pre}[{$weatherIconKey}]{$line1_suf}");
logWeatherBatch("2行目: [umbrella] {$line2_text}");

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

// 上部300pxにオレンジ帯 (#FF8700) を描画
for ($y = 0; $y < $bannerHeight; $y++) {
    $ratio = $y / $bannerHeight;
    $r = (int)(255 * (1 - $ratio) + 234 * $ratio);
    $g = (int)(135 * (1 - $ratio) + 114 * $ratio);
    $b = (int)(0 * (1 - $ratio) + 0 * $ratio);
    $color = imagecolorallocate($dstImg, $r, $g, $b);
    imageline($dstImg, 0, $y, $width, $y, $color);
}

// 境界アクセントライン
$borderCol = imagecolorallocate($dstImg, 255, 224, 178);
for ($b = 0; $b < 6; $b++) {
    imageline($dstImg, 0, $bannerHeight - $b, $width, $bannerHeight - $b, $borderCol);
}

// フォントファイルの探索（LINE Seed JP を最優先）
$fontFile = null;
$possibleFonts = [
    __DIR__ . '/data/LINESeedJP-Bold.ttf',
    __DIR__ . '/data/font.ttf',
    'C:/Windows/Fonts/meiryob.ttc',
    'C:/Windows/Fonts/meiryo.ttc',
    'C:/Windows/Fonts/YuGothB.ttc',
    '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc',
    '/usr/share/fonts/truetype/noto/NotoSansCJK-Bold.ttc',
    '/usr/share/fonts/ipa-gothic/ipag.ttf'
];
foreach ($possibleFonts as $f) {
    if (file_exists($f)) {
        $fontFile = $f;
        break;
    }
}

$white = imagecolorallocate($dstImg, 255, 255, 255);
$yellow = imagecolorallocate($dstImg, 254, 240, 138); // 明るいイエロー
$greenLight = imagecolorallocate($dstImg, 240, 253, 244);
$shadow = imagecolorallocatealpha($dstImg, 0, 0, 0, 75);

$maxWidth = 2420;

if ($fontFile && function_exists('imagettftext')) {
    // 1行目描画
    $fontSize1 = 48;
    $icon1Size = 58;
    while ($fontSize1 > 26) {
        $bb_pre = imagettfbbox($fontSize1, 0, $fontFile, $line1_pre);
        $w_pre = abs($bb_pre[4] - $bb_pre[0]);
        $bb_suf = imagettfbbox($fontSize1, 0, $fontFile, $line1_suf);
        $w_suf = abs($bb_suf[4] - $bb_suf[0]);
        $total1W = $w_pre + $icon1Size + 10 + $w_suf;
        if ($total1W <= $maxWidth) break;
        $fontSize1 -= 2;
        $icon1Size = (int)round($fontSize1 * 1.2);
    }
    $bb_pre = imagettfbbox($fontSize1, 0, $fontFile, $line1_pre);
    $w_pre = abs($bb_pre[4] - $bb_pre[0]);
    $bb_suf = imagettfbbox($fontSize1, 0, $fontFile, $line1_suf);
    $w_suf = abs($bb_suf[4] - $bb_suf[0]);
    $total1W = $w_pre + $icon1Size + 10 + $w_suf;
    $start1X = max(30, (int)(($width - $total1W) / 2));
    $y1 = 115;

    // 1行目 テキスト prefix
    imagettftext($dstImg, $fontSize1, 0, $start1X + 2, $y1 + 2, $shadow, $fontFile, $line1_pre);
    imagettftext($dstImg, $fontSize1, 0, $start1X, $y1, $white, $fontFile, $line1_pre);

    // 1行目 絵文字アイコン合成
    $emoji1Path = __DIR__ . "/data/emojis/{$weatherIconKey}.png";
    if (file_exists($emoji1Path)) {
        $e1Img = imagecreatefrompng($emoji1Path);
        if ($e1Img) {
            $e1X = $start1X + $w_pre + 5;
            $e1Y = $y1 - (int)($fontSize1 * 0.9);
            imagecopyresampled($dstImg, $e1Img, $e1X, $e1Y, 0, 0, $icon1Size, $icon1Size, imagesx($e1Img), imagesy($e1Img));
            imagedestroy($e1Img);
        }
    }

    // 1行目 テキスト suffix
    $suf1X = $start1X + $w_pre + 5 + $icon1Size + 5;
    imagettftext($dstImg, $fontSize1, 0, $suf1X + 2, $y1 + 2, $shadow, $fontFile, $line1_suf);
    imagettftext($dstImg, $fontSize1, 0, $suf1X, $y1, $white, $fontFile, $line1_suf);

    // 2行目描画
    $fontSize2 = 44;
    $icon2Size = 52;
    while ($fontSize2 > 24) {
        $bb2 = imagettfbbox($fontSize2, 0, $fontFile, $line2_text);
        $w2_text = abs($bb2[4] - $bb2[0]);
        $total2W = $icon2Size + 12 + $w2_text;
        if ($total2W <= $maxWidth) break;
        $fontSize2 -= 2;
        $icon2Size = (int)round($fontSize2 * 1.15);
    }
    $bb2 = imagettfbbox($fontSize2, 0, $fontFile, $line2_text);
    $w2_text = abs($bb2[4] - $bb2[0]);
    $total2W = $icon2Size + 12 + $w2_text;
    $start2X = max(30, (int)(($width - $total2W) / 2));
    $y2 = 220;

    // 2行目 絵文字アイコン合成 (傘 or 太陽)
    $emoji2Key = $hasRain ? 'umbrella' : 'sun';
    $emoji2Path = __DIR__ . "/data/emojis/{$emoji2Key}.png";
    if (file_exists($emoji2Path)) {
        $e2Img = imagecreatefrompng($emoji2Path);
        if ($e2Img) {
            $e2Y = $y2 - (int)($fontSize2 * 0.9);
            imagecopyresampled($dstImg, $e2Img, $start2X, $e2Y, 0, 0, $icon2Size, $icon2Size, imagesx($e2Img), imagesy($e2Img));
            imagedestroy($e2Img);
        }
    }

    // 2行目 テキスト
    $text2X = $start2X + $icon2Size + 12;
    $color2 = $hasRain ? $yellow : $greenLight;
    imagettftext($dstImg, $fontSize2, 0, $text2X + 2, $y2 + 2, $shadow, $fontFile, $line2_text);
    imagettftext($dstImg, $fontSize2, 0, $text2X, $y2, $color2, $fontFile, $line2_text);
} else {
    // フォールバック
    $text1X = (int)(($width - (mb_strlen($line1_pre . $line1_suf) * 18)) / 2);
    $text2X = (int)(($width - (mb_strlen($line2_text) * 18)) / 2);
    imagestring($dstImg, 5, $text1X, 90, $line1_pre . $line1_suf, $white);
    imagestring($dstImg, 5, $text2X, 195, $line2_text, $yellow);
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
