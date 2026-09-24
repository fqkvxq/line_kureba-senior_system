<?php
/**
 * 10文字以内の名言・格言取得モジュール
 * 外部API連携 ＆ 毎時決定論的ローテーション
 */

// 厳選された10文字以内の名言・前向きな格言マスター（シニア・学び・日常向け・最大10文字）
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
    '前を向いて歩こう',
    '健康第一',
    '小さな一歩が大きな前進',
    '心穏やかに過ごす',
    '日々の学びに感謝',
    '新しい発見を楽しもう',
    'いつでもスタートライン',
    '笑顔あふれる一日に',
    '心はずむ毎日を',
    '一歩ずつの成長',
    '今日という日に感謝'
];

/**
 * 更新ごとに確実に切り替わる名言を取得（ステートファイル保存付きローテーション）
 * @param string|null $targetDateTime 未使用（後方互換用）
 * @return array ['quote' => string, 'author' => string, 'index' => int]
 */
function getHourlyQuote(?string $targetDateTime = null): array {
    $stateDir = __DIR__ . '/data';
    if (!is_dir($stateDir)) {
        @mkdir($stateDir, 0777, true);
    }
    $stateFile = $stateDir . '/quote_state.json';
    
    $lastIndex = -1;
    if (file_exists($stateFile)) {
        $json = @file_get_contents($stateFile);
        if ($json) {
            $data = json_decode($json, true);
            if (isset($data['last_index']) && is_numeric($data['last_index'])) {
                $lastIndex = (int)$data['last_index'];
            }
        }
    }

    $total = count(SHORT_QUOTES_MASTER);
    
    if ($lastIndex < 0) {
        // 初回は現在時刻をベースにしたシード値
        $nextIndex = (int)date('H') % $total;
    } else {
        // 更新ごとに必ずインデックスを +1 して次の名言へ
        $nextIndex = ($lastIndex + 1) % $total;
    }

    $selected = SHORT_QUOTES_MASTER[$nextIndex];

    // 新しいインデックスと履歴を保存
    $saveData = [
        'last_index' => $nextIndex,
        'last_quote' => $selected,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    @file_put_contents($stateFile, json_encode($saveData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

    return [
        'quote' => $selected,
        'source' => 'master_rotation',
        'index' => $nextIndex,
        'total' => $total,
        'hour' => date('H時')
    ];
}

/**
 * 外部名言APIの呼び出し（フォールバック用）
 */
function fetchQuoteFromApi(): ?array {
    return null;
}
