<?php
/**
 * 12星座占いエンジン & Flex Message 生成
 */

// 12星座の定義
const ZODIAC_LIST = [
    'aries'       => ['name' => '牡羊座', 'kana' => 'おひつじざ', 'symbol' => '♈', 'period' => '3/21〜4/19', 'element' => '火'],
    'taurus'      => ['name' => '牡牛座', 'kana' => 'おうしざ',   'symbol' => '♉', 'period' => '4/20〜5/20', 'element' => '地'],
    'gemini'      => ['name' => '双子座', 'kana' => 'ふたござ',   'symbol' => '♊', 'period' => '5/21〜6/21', 'element' => '風'],
    'cancer'      => ['name' => '蟹座',   'kana' => 'かにざ',     'symbol' => '♋', 'period' => '6/22〜7/22', 'element' => '水'],
    'leo'         => ['name' => '獅子座', 'kana' => 'ししざ',     'symbol' => '♌', 'period' => '7/23〜8/22', 'element' => '火'],
    'virgo'       => ['name' => '乙女座', 'kana' => 'おとめざ',   'symbol' => '♍', 'period' => '8/23〜9/22', 'element' => '地'],
    'libra'       => ['name' => '天秤座', 'kana' => 'てんびんざ', 'symbol' => '♎', 'period' => '9/23〜10/23', 'element' => '風'],
    'scorpio'     => ['name' => '蠍座',   'kana' => 'さそりざ',   'symbol' => '♏', 'period' => '10/24〜11/22', 'element' => '水'],
    'sagittarius' => ['name' => '射手座', 'kana' => 'いてざ',     'symbol' => '♐', 'period' => '11/23〜12/21', 'element' => '火'],
    'capricorn'   => ['name' => '山羊座', 'kana' => 'やぎざ',     'symbol' => '♑', 'period' => '12/22〜1/19', 'element' => '地'],
    'aquarius'    => ['name' => '水瓶座', 'kana' => 'みずがめざ', 'symbol' => '♒', 'period' => '1/20〜2/18', 'element' => '風'],
    'pisces'      => ['name' => '魚座',   'kana' => 'うおざ',     'symbol' => '♓', 'period' => '2/19〜3/20', 'element' => '水'],
];

/**
 * 星座キーを正規化
 */
function normalizeZodiacKey(string $input): ?string {
    $clean = trim(mb_strtolower($input));
    foreach (ZODIAC_LIST as $key => $info) {
        if ($clean === $key || $clean === $info['name'] || $clean === $info['kana'] || str_contains($clean, str_replace('座', '', $info['name'])) || str_contains($clean, $info['symbol'])) {
            return $key;
        }
    }
    return null;
}

/**
 * 今日の星座占いデータを算出・生成
 */
function getTodayFortune(string $zodiacKey, ?string $targetDate = null): array {
    $zKey = normalizeZodiacKey($zodiacKey) ?: 'libra';
    $zInfo = ZODIAC_LIST[$zKey];
    $dateStr = $targetDate ?: date('Y-m-d');
    
    // 日付と星座キーに基づく一貫した決定論的ハッシュ乱数
    $seed = crc32($dateStr . '_' . $zKey);
    srand($seed);

    $score = ($seed % 5) + 1; // 1〜5
    if ($score < 3 && ($seed % 3 === 0)) $score = 4; // ポジティブ補正

    $colors = ['ゴールド', 'オレンジ', 'スカイブルー', 'エメラルドグリーン', 'ラベンダー', 'ローズピンク', 'ホワイト', 'イエロー', 'ネイビー', 'シルバー'];
    $items = ['温かいお茶', '手帳・メモ帳', 'お気に入りのペン', '散歩・軽い運動', '柑橘系のフルーツ', 'お気に入りの音楽', '季節の花', '本・雑誌', '新しい靴', 'メガネ・スマホクロス'];
    
    $messages = [
        5 => [
            '絶好調の一日！直感を信じて行動すると素晴らしい成果や新しい出会いが待っています。',
            '運気は最高潮！気になっていた新しいことや学びをスタートするのに最適な日です。',
            '周りからの信頼や評価が高まる日。自信を持って前向きに進めましょう！'
        ],
        4 => [
            '充実した穏やかな一日。落ち着いて取り組むことで予想以上の結果が出せます。',
            'コミュニケーション運が良好。家族や仲間と楽しい会話が弾みそうです。',
            'ひらめきが冴える日。ふと思いついたアイデアをメモしておくと吉。'
        ],
        3 => [
            'マイペースを大切にしたい日。焦らず一つずつ丁寧にこなせば順調に進みます。',
            'リフレッシュを意識すると運気アップ。好きな音楽や温かい飲み物で一息つきましょう。',
            '身の回りの整理整頓をすると良い運気を引き寄せられます。'
        ],
        2 => [
            '慎重な確認が幸運を呼ぶ日。スケジュールや持ち物を再確認して安心の一日に。',
            '無理をせず、自分の体をいたわる時間を大切にすると運気が回復します。',
            '人の意見に耳を傾けることで、良いヒントが見つかりそうです。'
        ],
        1 => [
            '充電にぴったりの日。のんびり過ごして好きなことに没頭すると心が整います。',
            '早めの休息が吉。温かいお風呂に入ってリラックスしましょう。'
        ]
    ];

    $msgList = $messages[$score] ?? $messages[4];
    $msg = $msgList[abs($seed) % count($msgList)];
    $color = $colors[abs($seed >> 2) % count($colors)];
    $item = $items[abs($seed >> 4) % count($items)];

    // 順位（1〜12位）
    $rank = (abs($seed >> 3) % 12) + 1;

    // 乱数シード復元
    srand();

    return [
        'key' => $zKey,
        'name' => $zInfo['name'],
        'kana' => $zInfo['kana'],
        'symbol' => $zInfo['symbol'],
        'period' => $zInfo['period'],
        'date' => date('n月j日', strtotime($dateStr)),
        'score' => $score,
        'stars' => str_repeat('★', $score) . str_repeat('☆', 5 - $score),
        'rank' => $rank,
        'color' => $color,
        'item' => $item,
        'message' => $msg
    ];
}

/**
 * 星座占い Flex Message バブルを構築
 */
function buildFortuneFlexBubble(array $fortune): array {
    $scoreStars = $fortune['stars'];
    $scoreNum = $fortune['score'];
    $starColor = '#f59e0b'; // アンバー/ゴールド

    return [
        'type' => 'bubble',
        'size' => 'mega',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#4338ca', // ディープインディゴ
            'paddingAll' => '16px',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '🔮 今日の星占い',
                            'color' => '#c7d2fe',
                            'size' => 'xs',
                            'weight' => 'bold',
                            'flex' => 1
                        ],
                        [
                            'type' => 'text',
                            'text' => $fortune['date'] . ' の運勢',
                            'color' => '#e0e7ff',
                            'size' => 'xs',
                            'align' => 'end'
                        ]
                    ]
                ],
                [
                    'type' => 'text',
                    'text' => "{$fortune['symbol']} {$fortune['name']}（{$fortune['kana']}）",
                    'color' => '#ffffff',
                    'size' => 'xl',
                    'weight' => 'bold',
                    'margin' => 'md'
                ]
            ]
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '18px',
            'spacing' => 'md',
            'contents' => [
                // 総合運
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'alignItems' => 'center',
                    'contents' => [
                        ['type' => 'text', 'text' => '総合運', 'size' => 'sm', 'color' => '#64748b', 'flex' => 2],
                        ['type' => 'text', 'text' => $scoreStars, 'size' => 'lg', 'color' => $starColor, 'weight' => 'bold', 'flex' => 5]
                    ]
                ],
                // ラッキーカラー & アイテム
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'spacing' => 'sm',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'backgroundColor' => '#f8fafc',
                            'cornerRadius' => '8px',
                            'paddingAll' => '10px',
                            'flex' => 1,
                            'contents' => [
                                ['type' => 'text', 'text' => '🎨 ラッキーカラー', 'size' => 'xxs', 'color' => '#64748b'],
                                ['type' => 'text', 'text' => $fortune['color'], 'size' => 'sm', 'weight' => 'bold', 'color' => '#1e293b', 'margin' => 'xs']
                            ]
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'backgroundColor' => '#f8fafc',
                            'cornerRadius' => '8px',
                            'paddingAll' => '10px',
                            'flex' => 1,
                            'contents' => [
                                ['type' => 'text', 'text' => '🍀 ラッキーアイテム', 'size' => 'xxs', 'color' => '#64748b'],
                                ['type' => 'text', 'text' => $fortune['item'], 'size' => 'sm', 'weight' => 'bold', 'color' => '#1e293b', 'margin' => 'xs']
                            ]
                        ]
                    ]
                ],
                // メッセージ
                [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => '#fef3c7',
                    'cornerRadius' => '8px',
                    'paddingAll' => '12px',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => $fortune['message'],
                            'size' => 'sm',
                            'color' => '#92400e',
                            'wrap' => true,
                            'lineSpacing' => '4px'
                        ]
                    ]
                ]
            ]
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'horizontal',
            'spacing' => 'sm',
            'paddingAll' => '12px',
            'contents' => [
                [
                    'type' => 'button',
                    'style' => 'secondary',
                    'height' => 'sm',
                    'flex' => 1,
                    'action' => [
                        'type' => 'postback',
                        'label' => '🌟 星座を変更',
                        'data' => 'action=select_zodiac'
                    ]
                ],
                [
                    'type' => 'button',
                    'style' => 'primary',
                    'color' => '#f97316',
                    'height' => 'sm',
                    'flex' => 1,
                    'action' => [
                        'type' => 'postback',
                        'label' => '🌤️ 天気メニュー',
                        'data' => 'action=switch_weather_menu'
                    ]
                ]
            ]
        ]
    ];
}

/**
 * 12星座選択クイックリプライを生成
 */
function getZodiacSelectionQuickReply(): array {
    $items = [];
    foreach (ZODIAC_LIST as $key => $info) {
        $items[] = [
            'type' => 'action',
            'action' => [
                'type' => 'postback',
                'label' => "{$info['symbol']} {$info['name']}",
                'data' => "action=set_zodiac&sign={$key}"
            ]
        ];
    }
    return ['items' => $items];
}
