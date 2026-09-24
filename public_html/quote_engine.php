<?php
/**
 * 10文字以内の名言・格言取得モジュール
 * 外部API連携 ＆ 毎時決定論的ローテーション
 */

// 厳選された10文字以内の名言・前向きな格言マスター（シニア・学び・日常向け）
const SHORT_QUOTES_MASTER = [
    '継続は力なり',
    '思い立ったが吉日',
    '一期一会',
    '日日是好日',
    '笑う門には福来たる',
    '千里の道も一歩から',
    '初心忘るべからず',
    '為せば成る',
    '自分を信じて',
    '一歩一歩前へ',
    '明日には明日の風',
    '失敗は成功の母',
    '今を大切に生きる',
    '夢は逃げない',
    '焦らずマイペース',
    '努力は裏切らない',
    '学ぶ心に老いなし',
    '感謝の心が道開く',
    '七転び八起き',
    '笑顔は最高の魔法',
    '好きこそ物の上手',
    '案ずるより生む易し',
    '希望は光となる',
    '知恵は無限の財産',
    '温故知新',
    '明日はもっと良く',
    '好奇心が若さの鍵',
    '楽しむことが一番',
    '感謝から始まる',
    '雨のち晴れ',
    '焦らず一歩ずつ',
    '今日を楽しもう',
    '心開けば世界広がる',
    '挑戦に遅いはない',
    'ありがとうの力',
    '今日が一番若い日',
    '道は必ず開ける',
    '優しさは力なり',
    '自分らしく輝く',
    '前を向いて歩こう'
];

/**
 * 10文字以内の今時間の名言を取得
 * @param string|null $targetDateTime Y-m-d H形式（省略時は現在時）
 * @return array ['quote' => string, 'author' => string]
 */
function getHourlyQuote(?string $targetDateTime = null): array {
    $nowStr = $targetDateTime ?: date('Y-m-d H');
    
    // 1. 外部名言APIからの取得を試行
    $apiQuote = fetchQuoteFromApi();
    if (!empty($apiQuote) && mb_strlen($apiQuote['quote'], 'UTF-8') <= 10) {
        return $apiQuote;
    }

    // 2. 毎時ハッシュシードによる厳選名言ローテーション（1時間毎に確実に変化）
    $seed = crc32($nowStr . '_kureba_hourly_quote');
    $index = abs($seed) % count(SHORT_QUOTES_MASTER);
    $selected = SHORT_QUOTES_MASTER[$index];

    return [
        'quote' => $selected,
        'source' => 'master',
        'hour' => date('H時', strtotime($nowStr . ':00:00'))
    ];
}

/**
 * 外部名言APIの呼び出し（タイムアウト1.5秒）
 */
function fetchQuoteFromApi(): ?array {
    try {
        // 日本語名言API（Doodlenote API）またはフォールバック
        $apiUrl = 'https://meigen.doodlenote.net/api/json.php';
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        if ($res) {
            $data = json_decode($res, true);
            if (is_array($data) && isset($data[0]['meigen'])) {
                $rawText = trim(strip_tags($data[0]['meigen']));
                // 句読点や余分な空白を除去
                $cleanText = preg_replace('/[。、！？\s]/u', '', $rawText);
                if (mb_strlen($cleanText, 'UTF-8') <= 10 && mb_strlen($cleanText, 'UTF-8') >= 3) {
                    return [
                        'quote' => $cleanText,
                        'author' => trim($data[0]['author'] ?? ''),
                        'source' => 'api'
                    ];
                }
            }
        }
    } catch (Throwable $e) {}
    
    return null;
}
