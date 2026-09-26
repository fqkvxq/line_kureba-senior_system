<?php
/**
 * 12星座 毎日の占い連動リッチメニュー 自動更新バッチ (PHP版)
 * 
 * 実行方法 (Xserver Cron / 毎朝6:00):
 * /usr/bin/php8.2 /home/あなたのサーバーID/ドメイン/public_html/batch_daily_fortune.php
 */

date_default_timezone_set('Asia/Tokyo');
ini_set('date.timezone', 'Asia/Tokyo');
putenv('TZ=Asia/Tokyo');
ini_set('display_errors', '0');
error_reporting(E_ALL);

function logFortune(string $msg) {
    $now = date('Y-m-d H:i:s');
    $line = "[{$now}] [Fortune] {$msg}\n";
    echo $line;
    $logFile = __DIR__ . '/data/fortune_batch.log';
    if (!is_dir(__DIR__ . '/data')) {
        @mkdir(__DIR__ . '/data', 0777, true);
    }
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/zodiac_api.php';

$targetAccount = $_REQUEST['account'] ?? ($_SERVER['HTTP_X_LINE_ACCOUNT'] ?? 'senior');
setActiveAccountKey($targetAccount);
$accConfig = getAccountConfig($targetAccount);
$db = getDbConnection($targetAccount);

$channelAccessToken = $accConfig['channel_access_token'] ?? '';
if (empty($channelAccessToken) || $channelAccessToken === 'YOUR_CHANNEL_ACCESS_TOKEN_HERE') {
    die("エラー: LINEアクセストークン未設定\n");
}

logFortune("=== 今日の星占いリッチメニュー自動更新バッチ 開始 (アカウント: {$targetAccount}) ===");

$zodiacs = [
    ['key' => 'aries', 'name' => '牡羊座', 'emoji' => '♈'],
    ['key' => 'taurus', 'name' => '牡牛座', 'emoji' => '♉'],
    ['key' => 'gemini', 'name' => '双子座', 'emoji' => '♊'],
    ['key' => 'cancer', 'name' => '蟹座', 'emoji' => '♋'],
    ['key' => 'leo', 'name' => '獅子座', 'emoji' => '♌'],
    ['key' => 'virgo', 'name' => '乙女座', 'emoji' => '♍'],
    ['key' => 'libra', 'name' => '天秤座', 'emoji' => '♎'],
    ['key' => 'scorpio', 'name' => '蠍座', 'emoji' => '♏'],
    ['key' => 'sagittarius', 'name' => '射手座', 'emoji' => '♐'],
    ['key' => 'capricorn', 'name' => '山羊座', 'emoji' => '♑'],
    ['key' => 'aquarius', 'name' => '水瓶座', 'emoji' => '♒'],
    ['key' => 'pisces', 'name' => '魚座', 'emoji' => '♓']
];

$luckyColors = ['ゴールド', 'シルバー', 'ロイヤルブルー', 'ローズピンク', 'エメラルドグリーン', 'サンシャインイエロー', 'ラベンダー', 'ピュアホワイト', 'ワインレッド', 'スカイブルー', 'オレンジ', 'ミントグリーン', 'ターコイズ', 'パールホワイト', 'サーモンピンク'];

$luckyItems = [
    // ☕ 飲み物・カフェ・リラックス
    '温かい緑茶', 'マグカップ', 'マイボトル・水筒', 'コーヒーカップ', 'ティーポット',
    '陶器の湯呑み', '銅製のタンブラー', 'ほうじ茶のティーバッグ', 'ハーブティー', 'ステンレスボトル',
    // 🍬 軽食・スイーツ
    'ミントキャンディ', 'ビターチョコレート', 'ドライフルーツ', 'ナッツの小袋',
    // 📝 文房具・オフィス
    'メモ帳とペン', 'クリアファイル', '手帳', '卓上カレンダー', 'ボールペン（青色）',
    'マスキングテープ', '万年筆', '木製の定規', 'レザーの名刺入れ', 'ブックカバー',
    'お気に入りのしおり', '絵はがき・ポストカード', 'ネックストラップ', 'デスクライト',
    // 👜 ファッション・身の回り品
    'お気に入りの靴', 'ハンドクリーム', 'キーホルダー', 'ハンカチ', '小さなコインケース',
    '本・雑誌', '折りたたみ傘', '腕時計', 'エコバッグ', 'お気に入りの写真',
    'お気に入りのスニーカー', '爪切り', 'スマホスタンド', 'メガネ拭き', 'お守り',
    'リップクリーム', 'ポケットティッシュ', '革の財布', 'イヤホン', '扇子・うちわ',
    '日傘', 'お気に入りのキーケース', 'サングラス', '絆創膏', '目薬',
    'トートバッグ', 'お気に入りの帽子', 'ストール・マフラー', '定期入れ・パスケース', 'ミニタオル',
    '誕生石のアクセサリー', 'シルバーリング', 'ブレスレット', 'お気に入りの靴下', 'スニーカーソックス',
    '携帯用靴べら', 'お気に入りのピンバッジ', '和柄の小銭入れ',
    // 🚗 車・ドライブ・お出かけ
    '車のスマートキー', 'ドライブ用クッション', '車載用空気清浄機', '洗車用クロス', '車のサンシェード',
    'お気に入りの芳香剤', '車検証入れ', '交通安全のお守り', 'ドライビンググローブ', '車載スマホホルダー',
    'お気に入りのCD・音楽', '富士山の写真・絵はがき',
    // 🌿 癒し・インテリア・生活
    '季節の花', '観葉植物', 'アロマオイル', 'お気に入りのプレイリスト', 'お気に入りのマグネット',
    'コースター', '木製のコースター', 'お気に入りの箸・カトラリー', 'ランチマット', 'ガラスの風鈴',
    'ルームシューズ・スリッパ', 'ふわふわのブランケット', 'アロマキャンドル', '入浴剤（ヒノキの香り）', '柑橘系のボディソープ',
    'ヘアブラシ', 'コンパクトミラー', 'ポータブル加湿器', 'ミニ観葉植物（サボテン等）', '陶器の一輪挿し',
    '萬古焼・信楽焼の器', '笑顔の写真'
];
$advices = [
    '直感を信じて一歩踏み出すと嬉しい展開が訪れそう！',
    '周囲への感謝を言葉にすると素敵なご縁が深まります。',
    'マイペースな行動が吉。深呼吸してリラックスを。',
    '新しい発見がある日。いつもと違う道を通ってみて。',
    '笑顔で過ごすことで周りにも元気を分け合えます。',
    '小さな積み重ねが大きな成果に繋がるチャンスの日！',
    '美味しいものを食べて心と体に栄養をチャージして。',
    '懐かしい人への連絡や挨拶が幸運を引き寄せます。',
    '好きなことに集中すると想像以上のパワーを発揮！',
    '穏やかな会話が心をほぐし、良い情報が入ります。'
];

$y = (int)date('Y');
$m = (int)date('n');
$d = (int)date('j');
$dateSeed = $y * 10000 + $m * 100 + $d;
$weekdays = ['日', '月', '火', '水', '木', '金', '土'];
$datePrefix = "{$m}月{$d}日({$weekdays[date('w')]})";

// 順位生成
mt_srand($dateSeed);
$ranks = range(1, 12);
shuffle($ranks);

$fortunes = [];
foreach ($zodiacs as $idx => $z) {
    $rank = $ranks[$idx];
    $starsCount = max(2, 5 - (int)floor(($rank - 1) / 3));
    $stars = str_repeat('★', $starsCount) . str_repeat('☆', 5 - $starsCount);
    
    mt_srand($dateSeed + $idx * 31);
    $color = $luckyColors[array_rand($luckyColors)];
    $item = $luckyItems[array_rand($luckyItems)];
    $advice = $advices[array_rand($advices)];

    $fortunes[$z['key']] = [
        'name' => $z['name'],
        'emoji' => $z['emoji'],
        'rank' => $rank,
        'stars' => $stars,
        'color' => $color,
        'item' => $item,
        'advice' => $advice
    ];
}

// 1位の星座
$topZodiac = null;
foreach ($fortunes as $k => $f) {
    if ($f['rank'] === 1) {
        $topZodiac = $f;
        break;
    }
}

logFortune("本日の第1位: {$topZodiac['name']} ({$topZodiac['emoji']})");
logFortune("完了: 星占いバッチロジック準備完了");
