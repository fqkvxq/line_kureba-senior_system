<?php
/**
 * 7都市 天気予報連動リッチメニュー 1時間毎 自動更新バッチ
 * （三島市・静岡市・浜松市・横浜市・東京都・大阪市・福岡市）
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

// ログ出力関数
function logWeatherBatch(string $msg) {
    $now = date('Y-m-d H:i:s');
    $line = "[{$now}] {$msg}\n";
    echo $line;
    $logFile = __DIR__ . '/data/weather_batch.log';
    if (!is_dir(__DIR__ . '/data')) {
        @mkdir(__DIR__ . '/data', 0777, true);
    }
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/quote_engine.php';
require_once __DIR__ . '/webhook_handlers.php';

// 今時間の名言を取得（10文字以内）
$quoteInfo = getHourlyQuote();
$currentQuote = $quoteInfo['quote'] ?? '継続は力なり';
logWeatherBatch("今時間の名言取得: 「{$currentQuote}」 (文字数: " . mb_strlen($currentQuote, 'UTF-8') . "文字)");

// アカウント指定
$targetAccount = $_REQUEST['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? 'senior');
setActiveAccountKey($targetAccount);
$accConfig = getAccountConfig($targetAccount);
$db = getDbConnection($targetAccount);

$channelAccessToken = $accConfig['channel_access_token'] ?? '';
if (empty($channelAccessToken) || $channelAccessToken === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
    die("エラー: LINE公式アカウントのアクセストークンが設定されていません。\n");
}

logWeatherBatch("=== 全7都市 天気リッチメニュー自動更新バッチ 開始 (アカウント: {$targetAccount}) ===");

// 1. 全7都市の定義
$cities = [
    'mishima' => [
        'name' => '三島市',
        'lat' => 35.1184,
        'lon' => 138.9184,
        'weathernews_url' => 'https://weathernews.jp/onebox/tenki/shizuoka/22206/'
    ],
    'shizuoka' => [
        'name' => '静岡市',
        'lat' => 34.9756,
        'lon' => 138.3828,
        'weathernews_url' => 'https://weathernews.jp/onebox/tenki/shizuoka/22100/'
    ],
    'hamamatsu' => [
        'name' => '浜松市',
        'lat' => 34.7108,
        'lon' => 137.7261,
        'weathernews_url' => 'https://weathernews.jp/onebox/tenki/shizuoka/22130/'
    ],
    'yokohama' => [
        'name' => '横浜市',
        'lat' => 35.4437,
        'lon' => 139.6380,
        'weathernews_url' => 'https://weathernews.jp/onebox/tenki/kanagawa/14100/'
    ],
    'tokyo' => [
        'name' => '東京都',
        'lat' => 35.6895,
        'lon' => 139.6917,
        'weathernews_url' => 'https://weathernews.jp/onebox/tenki/tokyo/13101/'
    ],
    'osaka' => [
        'name' => '大阪市',
        'lat' => 34.6937,
        'lon' => 135.5023,
        'weathernews_url' => 'https://weathernews.jp/onebox/tenki/osaka/27100/'
    ],
    'fukuoka' => [
        'name' => '福岡市',
        'lat' => 33.5904,
        'lon' => 130.4017,
        'weathernews_url' => 'https://weathernews.jp/onebox/tenki/fukuoka/40130/'
    ]
];

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
    $localPath = __DIR__ . '/uploads/richmenu/' . basename($baseMenu['image_url'] ?? '');
    if (file_exists($localPath)) {
        $imgBin = file_get_contents($localPath);
    }
}

if (empty($imgBin)) {
    logWeatherBatch("エラー: ベースリッチメニュー画像の取得に失敗しました。");
    exit(1);
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

// 既存のマッピングファイルを読み込み
$weatherMappingFile = __DIR__ . '/data/weather_richmenus.json';
$oldMapping = [];
if (file_exists($weatherMappingFile)) {
    $oldMapping = json_decode(file_get_contents($weatherMappingFile), true) ?: [];
}

$newMapping = [];
$citySummaries = [];
$now = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
$dayNames = ['日', '月', '火', '水', '木', '金', '土'];
$dayStr = $dayNames[(int)$now->format('w')];
$datePrefix = $now->format('n/j') . "({$dayStr})";
$hourStr = $now->format('G') . "時時点";

$chatBar = !empty($currentQuote) ? mb_substr($currentQuote, 0, 14) : 'メニュー';

// 各都市の処理ループ
foreach ($cities as $cityKey => $cityInfo) {
    $cityName = $cityInfo['name'];
    $lat = $cityInfo['lat'];
    $lon = $cityInfo['lon'];
    $weathernewsUrl = $cityInfo['weathernews_url'];

    logWeatherBatch("--- [{$cityName}] 天気情報取得 & リッチメニュー作成開始 ---");

    // A. Open-Meteo 天気取得
    $weatherLabel = '晴れ時々曇り';
    $weatherIconKey = 'sun_cloud';
    $weatherEmoji = '🌤️';
    $maxTemp = 25;
    $minTemp = 18;
    $nextRainStr = '';
    $futureRainDays = [];

    try {
        $apiUrl = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}&current=weather_code,temperature_2m&hourly=precipitation_probability,precipitation,weathercode&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=Asia%2FTokyo&forecast_days=8";
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
                $maxTemp = (int)round($weatherData['daily']['temperature_2m_max'][0]);
                $minTemp = (int)round($weatherData['daily']['temperature_2m_min'][0]);
            }

            // リアルタイム(current)の気象コードを最優先で適用
            $code = isset($weatherData['current']['weather_code'])
                ? (int)$weatherData['current']['weather_code']
                : (isset($weatherData['daily']['weathercode'][0]) ? (int)$weatherData['daily']['weathercode'][0] : 1);

            if ($code === 0) { $weatherLabel = '快晴'; $weatherIconKey = 'sun'; $weatherEmoji = '☀️'; }
            elseif ($code >= 1 && $code <= 2) { $weatherLabel = '晴れ時々曇り'; $weatherIconKey = 'sun_cloud'; $weatherEmoji = '🌤️'; }
            elseif ($code === 3) { $weatherLabel = '曇り'; $weatherIconKey = 'cloud'; $weatherEmoji = '☁️'; }
            elseif ($code >= 45 && $code <= 48) { $weatherLabel = '霧'; $weatherIconKey = 'cloud'; $weatherEmoji = '🌫️'; }
            elseif ($code >= 51 && $code <= 67) { $weatherLabel = '雨'; $weatherIconKey = 'rain'; $weatherEmoji = '🌧️'; }
            elseif ($code >= 71 && $code <= 77) { $weatherLabel = '雪'; $weatherIconKey = 'snow'; $weatherEmoji = '❄️'; }
            elseif ($code >= 80 && $code <= 82) { $weatherLabel = 'にわか雨'; $weatherIconKey = 'rain'; $weatherEmoji = '🌧️'; }
            elseif ($code >= 95) { $weatherLabel = '雷雨'; $weatherIconKey = 'thunder'; $weatherEmoji = '⛈️'; }

            $nowTs = time();

            // 最も近い雨の降り始め時間（時間別予報）
            if (!empty($weatherData['hourly']['time'])) {
                $hTimes = $weatherData['hourly']['time'];
                $hProbs = $weatherData['hourly']['precipitation_probability'] ?? [];
                $hCodes = $weatherData['hourly']['weathercode'] ?? [];

                for ($i = 0; $i < count($hTimes); $i++) {
                    $tTs = strtotime($hTimes[$i]);
                    if ($tTs >= $nowTs - 3600) {
                        $p = (int)($hProbs[$i] ?? 0);
                        $c = (int)($hCodes[$i] ?? 0);

                        // 直近で降水確率60%以上の場合は、アイコンを雨に補正
                        if ($i === 0 && $p >= 60 && $weatherIconKey !== 'rain' && $weatherIconKey !== 'thunder') {
                            $weatherLabel = '雨';
                            $weatherIconKey = 'rain';
                            $weatherEmoji = '🌧️';
                        }

                        if ($p >= 40 || ($c >= 51 && $c <= 67) || ($c >= 80 && $c <= 82)) {
                            if (empty($nextRainStr) && $tTs >= $nowTs) {
                                $isToday = (date('Y-m-d', $tTs) === date('Y-m-d', $nowTs));
                                $isTomorrow = (date('Y-m-d', $tTs) === date('Y-m-d', $nowTs + 86400));
                                $dayPrefix = $isToday ? '今日' : ($isTomorrow ? '明日' : date('n/j', $tTs) . '(' . $dayNames[(int)date('w', $tTs)] . ')');
                                $hourNum = (int)date('G', $tTs);
                                $nextRainStr = "{$dayPrefix} {$hourNum}時〜 (降水確率{$p}%)";
                            }
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
        logWeatherBatch("天気API取得エラー ({$cityName}): " . $e->getMessage());
    }

    // サマリデータ（クイックリプライ用）
    $citySummaries[$cityKey] = [
        'name' => $cityName,
        'weatherText' => $weatherLabel,
        'code' => (string)($code ?? 300),
        'emoji' => $weatherEmoji,
        'label' => "{$weatherEmoji} {$cityName}"
    ];

    // B. テキスト構築
    $line1_pre = "{$datePrefix} {$hourStr}　{$cityName}の天気：";
    $line1_suf = " {$weatherLabel}（最高 {$maxTemp}℃ / 最低 {$minTemp}℃）";

    $hasRain = !empty($nextRainStr);
    if ($hasRain) {
        $line2_text = "直近の雨：{$nextRainStr}";
        if (!empty($futureRainDays)) {
            $weekStr = implode('・', array_slice($futureRainDays, 0, 4));
            $line3_text = "週間予報：{$weekStr}も雨予報";
        } else {
            $line3_text = "週間予報：目先1週間は晴れ間が広がる見込み";
        }
    } else {
        $line2_text = "直近の雨の心配はありません";
        $line3_text = "週間予報：目先1週間はまとまった雨の心配なし";
    }

    // C. 画像合成
    $srcImg = imagecreatefromstring($imgBin);
    $dstImg = imagecreatetruecolor($width, $height);
    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $width, $height, imagesx($srcImg), imagesy($srcImg));
    imagedestroy($srcImg);

    $bannerHeight = 300;

    // 上部300pxにオレンジグラデーション
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

    $white = imagecolorallocate($dstImg, 255, 255, 255);
    $yellow = imagecolorallocate($dstImg, 254, 240, 138);
    $greenLight = imagecolorallocate($dstImg, 240, 253, 244);
    $shadow = imagecolorallocatealpha($dstImg, 0, 0, 0, 75);

    $STABLE_FONT_SIZE_LINE1 = 52;
    $STABLE_ICON_SIZE_LINE1 = 54;
    $STABLE_Y_LINE1 = 66;

    $STABLE_FONT_SIZE_LINE2 = 48;
    $STABLE_ICON_SIZE_LINE2 = 50;
    $STABLE_Y_LINE2 = 156;

    $STABLE_FONT_SIZE_LINE3 = 48;
    $STABLE_ICON_SIZE_LINE3 = 50;
    $STABLE_Y_LINE3 = 248;

    $maxWidth = 2450;

    if ($fontFile) {
        // 1行目描画
        $fontSize1 = $STABLE_FONT_SIZE_LINE1;
        $icon1Size = $STABLE_ICON_SIZE_LINE1;
        while ($fontSize1 > 24) {
            $bb_pre = imagettfbbox($fontSize1, 0, $fontFile, $line1_pre);
            $bb_suf = imagettfbbox($fontSize1, 0, $fontFile, $line1_suf);
            $w_pre = abs($bb_pre[4] - $bb_pre[0]);
            $w_suf = abs($bb_suf[4] - $bb_suf[0]);
            $total1W = $w_pre + $icon1Size + 8 + $w_suf;
            if ($total1W <= $maxWidth) break;
            $fontSize1 -= 1;
            $icon1Size = (int)round($fontSize1 * 1.04);
        }
        $bb_pre = imagettfbbox($fontSize1, 0, $fontFile, $line1_pre);
        $bb_suf = imagettfbbox($fontSize1, 0, $fontFile, $line1_suf);
        $w_pre = abs($bb_pre[4] - $bb_pre[0]);
        $w_suf = abs($bb_suf[4] - $bb_suf[0]);
        $total1W = $w_pre + $icon1Size + 8 + $w_suf;
        $start1X = max(25, (int)(($width - $total1W) / 2));
        $y1 = $STABLE_Y_LINE1;

        imagettftext($dstImg, $fontSize1, 0, $start1X + 2, $y1 + 2, $shadow, $fontFile, $line1_pre);
        imagettftext($dstImg, $fontSize1, 0, $start1X, $y1, $white, $fontFile, $line1_pre);

        $emoji1Path = __DIR__ . "/data/emojis/{$weatherIconKey}.png";
        if (file_exists($emoji1Path)) {
            $e1Img = imagecreatefrompng($emoji1Path);
            if ($e1Img) {
                $e1X = $start1X + $w_pre + 4;
                $e1Y = $y1 - (int)($fontSize1 * 0.85);
                imagecopyresampled($dstImg, $e1Img, $e1X, $e1Y, 0, 0, $icon1Size, $icon1Size, imagesx($e1Img), imagesy($e1Img));
                imagedestroy($e1Img);
            }
        }

        $suf1X = $start1X + $w_pre + 4 + $icon1Size + 4;
        imagettftext($dstImg, $fontSize1, 0, $suf1X + 2, $y1 + 2, $shadow, $fontFile, $line1_suf);
        imagettftext($dstImg, $fontSize1, 0, $suf1X, $y1, $white, $fontFile, $line1_suf);

        // 2行目描画
        $fontSize2 = $STABLE_FONT_SIZE_LINE2;
        $icon2Size = $STABLE_ICON_SIZE_LINE2;
        while ($fontSize2 > 20) {
            $bb2 = imagettfbbox($fontSize2, 0, $fontFile, $line2_text);
            $w2_text = abs($bb2[4] - $bb2[0]);
            $total2W = $icon2Size + 10 + $w2_text;
            if ($total2W <= $maxWidth) break;
            $fontSize2 -= 1;
            $icon2Size = (int)round($fontSize2 * 1.05);
        }
        $bb2 = imagettfbbox($fontSize2, 0, $fontFile, $line2_text);
        $w2_text = abs($bb2[4] - $bb2[0]);
        $total2W = $icon2Size + 10 + $w2_text;
        $start2X = max(25, (int)(($width - $total2W) / 2));
        $y2 = $STABLE_Y_LINE2;

        $emoji2Key = $hasRain ? 'umbrella' : 'sun';
        $emoji2Path = __DIR__ . "/data/emojis/{$emoji2Key}.png";
        if (file_exists($emoji2Path)) {
            $e2Img = imagecreatefrompng($emoji2Path);
            if ($e2Img) {
                $e2Y = $y2 - (int)($fontSize2 * 0.85);
                imagecopyresampled($dstImg, $e2Img, $start2X, $e2Y, 0, 0, $icon2Size, $icon2Size, imagesx($e2Img), imagesy($e2Img));
                imagedestroy($e2Img);
            }
        }

        $text2X = $start2X + $icon2Size + 10;
        $color2 = $hasRain ? $yellow : $greenLight;
        imagettftext($dstImg, $fontSize2, 0, $text2X + 2, $y2 + 2, $shadow, $fontFile, $line2_text);
        imagettftext($dstImg, $fontSize2, 0, $text2X, $y2, $color2, $fontFile, $line2_text);

        // 3行目描画
        $fontSize3 = $STABLE_FONT_SIZE_LINE3;
        $icon3Size = $STABLE_ICON_SIZE_LINE3;
        while ($fontSize3 > 20) {
            $bb3 = imagettfbbox($fontSize3, 0, $fontFile, $line3_text);
            $w3_text = abs($bb3[4] - $bb3[0]);
            $total3W = $icon3Size + 10 + $w3_text;
            if ($total3W <= $maxWidth) break;
            $fontSize3 -= 1;
            $icon3Size = (int)round($fontSize3 * 1.05);
        }
        $bb3 = imagettfbbox($fontSize3, 0, $fontFile, $line3_text);
        $w3_text = abs($bb3[4] - $bb3[0]);
        $total3W = $icon3Size + 10 + $w3_text;
        $start3X = max(25, (int)(($width - $total3W) / 2));
        $y3 = $STABLE_Y_LINE3;

        $hasFutureRain = !empty($futureRainDays);
        $emoji3Key = $hasFutureRain ? 'umbrella' : 'sun';
        $emoji3Path = __DIR__ . "/data/emojis/{$emoji3Key}.png";
        if (file_exists($emoji3Path)) {
            $e3Img = imagecreatefrompng($emoji3Path);
            if ($e3Img) {
                $e3Y = $y3 - (int)($fontSize3 * 0.85);
                imagecopyresampled($dstImg, $e3Img, $start3X, $e3Y, 0, 0, $icon3Size, $icon3Size, imagesx($e3Img), imagesy($e3Img));
                imagedestroy($e3Img);
            }
        }

        $text3X = $start3X + $icon3Size + 10;
        $color3 = $hasFutureRain ? $yellow : $greenLight;
        imagettftext($dstImg, $fontSize3, 0, $text3X + 2, $y3 + 2, $shadow, $fontFile, $line3_text);
        imagettftext($dstImg, $fontSize3, 0, $text3X, $y3, $color3, $fontFile, $line3_text);
    }

    $tmpJpg = __DIR__ . "/data/weather_temp_{$cityKey}_" . time() . '.jpg';
    imagejpeg($dstImg, $tmpJpg, 92);
    imagedestroy($dstImg);

    // D. LINE リッチメニュー作成
    $areas = [
        [
            'bounds' => ['x' => 0, 'y' => 0, 'width' => 2500, 'height' => 300],
            'action' => [
                'type' => 'uri',
                'label' => "{$cityName}の天気詳細",
                'uri' => $weathernewsUrl
            ]
        ]
    ];

    $baseAreas = !empty($baseMenu['areas_json']) ? json_decode($baseMenu['areas_json'], true) : [];
    if (!empty($baseAreas) && is_array($baseAreas)) {
        foreach ($baseAreas as $ba) {
            $areas[] = $ba;
        }
    } else {
        $areas[] = [
            'bounds' => ['x' => 1375, 'y' => 320, 'width' => 1100, 'height' => 1315],
            'action' => [
                'type' => 'uri',
                'label' => '受講生マイページ',
                'uri' => 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcp%2FA9xhz7MWZF%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1'
            ]
        ];
        $areas[] = [
            'bounds' => ['x' => 25, 'y' => 320, 'width' => 1350, 'height' => 1332],
            'action' => [
                'type' => 'uri',
                'label' => '教室案内・予約',
                'uri' => 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1'
            ]
        ];
    }

    $menuTitle = "Weather_{$cityKey}_" . $now->format('n_j_G');
    $menuData = [
        'size' => ['width' => $width, 'height' => $height],
        'selected' => true,
        'name' => mb_substr($menuTitle, 0, 300),
        'chatBarText' => $chatBar,
        'areas' => $areas
    ];

    $createRes = lineCreateRichMenu($menuData, $targetAccount);
    if (!$createRes['success'] || empty($createRes['richMenuId'])) {
        @unlink($tmpJpg);
        logWeatherBatch("エラー: {$cityName} リッチメニュー作成失敗: " . ($createRes['error'] ?? ''));
        continue;
    }
    $newRichMenuId = $createRes['richMenuId'];

    // 画像アップロード
    $uploadRes = lineUploadRichMenuImage($newRichMenuId, $tmpJpg, 'image/jpeg', $targetAccount);
    @unlink($tmpJpg);

    if (!$uploadRes['success']) {
        lineDeleteRichMenu($newRichMenuId, $targetAccount);
        logWeatherBatch("エラー: {$cityName} 画像アップロード失敗: " . ($uploadRes['error'] ?? ''));
        continue;
    }

    logWeatherBatch("✅ {$cityName} リッチメニュー作成成功: {$newRichMenuId}");
    $newMapping[$cityKey] = $newRichMenuId;
    $newMapping[$cityName] = $newRichMenuId;

    // 三島市の場合は全体デフォルトリッチメニューに設定
    if ($cityKey === 'mishima') {
        $setDefRes = lineSetDefaultRichMenu($newRichMenuId, $targetAccount);
        if ($setDefRes['success']) {
            logWeatherBatch("🌟 三島市メニュー [{$newRichMenuId}] を全体デフォルトリッチメニューに設定完了！");
        }
    }
}

// 3. マッピングファイル (weather_richmenus.json) の保存
if (!empty($newMapping)) {
    @file_put_contents($weatherMappingFile, json_encode($newMapping, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    logWeatherBatch("📝 weather_richmenus.json を全7都市の最新メニューIDで更新完了！");
}

// 4. クイックリプライ用サマリ (city_weather_summary.json) の保存
if (!empty($citySummaries)) {
    $summaryFile = __DIR__ . '/data/city_weather_summary.json';
    @file_put_contents($summaryFile, json_encode($citySummaries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    logWeatherBatch("🌤️ city_weather_summary.json を最新のお天気サマリ・絵文字で更新完了！");
}

// 5. 個別都市・占いを選択しているユーザーの最新メニュー再リンク & クイックリプライ再表示
try {
    $stmtUsers = $db->query("
        SELECT DISTINCT TRIM(user_id) AS user_id, custom_menu_text, custom_line_menu_id 
        FROM customer_cars 
        WHERE user_id IS NOT NULL AND TRIM(user_id) LIKE 'U%'
    ");
    $users = $stmtUsers ? $stmtUsers->fetchAll(PDO::FETCH_ASSOC) : [];
    
    // chat_messages からも直近アクティブなユーザーを取得して結合
    $stmtChatUsers = $db->query("
        SELECT DISTINCT TRIM(user_id) AS user_id 
        FROM chat_messages 
        WHERE user_id IS NOT NULL AND TRIM(user_id) LIKE 'U%'
        ORDER BY id DESC LIMIT 50
    ");
    $chatUsers = $stmtChatUsers ? $stmtChatUsers->fetchAll(PDO::FETCH_ASSOC) : [];
    
    $userMap = [];
    foreach ($users as $u) {
        $uid = $u['user_id'];
        $userMap[$uid] = $u;
    }
    foreach ($chatUsers as $cu) {
        $uid = $cu['user_id'];
        if (!isset($userMap[$uid])) {
            $userMap[$uid] = [
                'user_id' => $uid,
                'custom_menu_text' => null,
                'custom_line_menu_id' => null
            ];
        }
    }

    $relinkCount = 0;
    $qrRestoredCount = 0;
    $nowJst = date('Y-m-d H:i:s');

    foreach ($userMap as $uid => $uData) {
        $customText = $uData['custom_menu_text'] ?? '';
        $userMode = 'weather';
        $userCityKey = 'mishima';
        $userCityName = '三島市';

        // A. 個別都市リッチメニューの再リンク
        if (!empty($customText) && str_starts_with($customText, 'weather_')) {
            $cityKey = str_replace('weather_', '', $customText);
            if (isset($newMapping[$cityKey])) {
                $newMid = $newMapping[$cityKey];
                $linkRes = lineLinkUserRichMenu($uid, $newMid, $targetAccount);
                if ($linkRes['success']) {
                    $relinkCount++;
                    try {
                        $upStmt = $db->prepare("UPDATE customer_cars SET custom_line_menu_id = :mid, updated_at = :now WHERE TRIM(user_id) = :uid");
                        $upStmt->execute([':mid' => $newMid, ':now' => $nowJst, ':uid' => $uid]);
                    } catch (Throwable $e) {}
                }
                $userCityKey = $cityKey;
                $userCityName = $cities[$cityKey]['name'] ?? $cityKey;
            }
        } elseif ($customText === 'fortune_mode') {
            $userMode = 'fortune';
        }

        // B. クイックリプライの再表示（消音/サイレントPushでユーザーの手元に復帰）
        if (function_exists('getModeSwitchQuickReply')) {
            $qr = getModeSwitchQuickReply($userMode);
            if (!empty($qr)) {
                $statusText = ($userMode === 'fortune')
                    ? "🔮 星占いを更新しました😊\n（下のボタンから星座の切替や天気メニューへ戻れます）"
                    : "🌤️ {$hourStr}の{$userCityName}のお天気を更新しました😊\n（下のボタンから都市の切替や占いができます）";

                $msg = [
                    'type' => 'text',
                    'text' => $statusText,
                    'quickReply' => $qr
                ];

                // notificationDisabled = true（消音プッシュ）で通知音を鳴らさずにクイックリプライを付与
                $pushRes = sendLinePushMessage($uid, [$msg], $targetAccount, true);
                if (!empty($pushRes['success'])) {
                    $qrRestoredCount++;
                }
            }
        }
    }

    logWeatherBatch("🔄 個別リッチメニュー再リンク: {$relinkCount}件, 📲 クイックリプライ再表示送信: {$qrRestoredCount}件");
} catch (Throwable $e) {
    logWeatherBatch("ユーザー個別メニュー・クイックリプライ処理エラー: " . $e->getMessage());
}

// 6. 以前の古い都市リッチメニューのクリーンアップ（LINE上限対策）
foreach ($oldMapping as $k => $oldId) {
    if (!empty($oldId) && !in_array($oldId, $newMapping)) {
        lineDeleteRichMenu($oldId, $targetAccount);
        logWeatherBatch("🗑️ 古いリッチメニューを削除しました: {$oldId} ({$k})");
    }
}

logWeatherBatch("🎉 全7都市 天気リッチメニュー＆サマリ 1時間毎自動更新バッチ 完了！\n");
