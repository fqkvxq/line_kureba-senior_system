/**
 * =========================================================================
 * 12星座 毎日の占いエンジン (Fortune Engine)
 * =========================================================================
 */

export const ZODIAC_LIST = [
    { key: 'aries', name: '牡羊座', period: '3/21〜4/19', emoji: '♈' },
    { key: 'taurus', name: '牡牛座', period: '4/20〜5/20', emoji: '♉' },
    { key: 'gemini', name: '双子座', period: '5/21〜6/21', emoji: '♊' },
    { key: 'cancer', name: '蟹座', period: '6/22〜7/22', emoji: '♋' },
    { key: 'leo', name: '獅子座', period: '7/23〜8/22', emoji: '♌' },
    { key: 'virgo', name: '乙女座', period: '8/23〜9/22', emoji: '♍' },
    { key: 'libra', name: '天秤座', period: '9/23〜10/23', emoji: '♎' },
    { key: 'scorpio', name: '蠍座', period: '10/24〜11/22', emoji: '♏' },
    { key: 'sagittarius', name: '射手座', period: '11/23〜12/21', emoji: '♐' },
    { key: 'capricorn', name: '山羊座', period: '12/22〜1/19', emoji: '♑' },
    { key: 'aquarius', name: '水瓶座', period: '1/20〜2/18', emoji: '♒' },
    { key: 'pisces', name: '魚座', period: '2/19〜3/20', emoji: '♓' }
];

const LUCKY_COLORS = [
    'ゴールド', 'シルバー', 'ロイヤルブルー', 'ローズピンク', 'エメラルドグリーン',
    'サンシャインイエロー', 'ラベンダー', 'ピュアホワイト', 'ワインレッド', 'スカイブルー',
    'オレンジ', 'ミントグリーン', 'ターコイズ', 'パールホワイト', 'サーモンピンク'
];

const LUCKY_ITEMS = [
    '温かい緑茶', 'お気に入りの靴', 'ハンドクリーム', 'メモ帳とペン', 'キーホルダー',
    '季節の花', 'ハンカチ', '小さなコインケース', '本・雑誌', 'マグカップ',
    '折りたたみ傘', 'ミントキャンディ', '腕時計', 'エコバッグ', 'お気に入りの写真'
];

const ADVICES = [
    '直感を信じて一歩踏み出すと嬉しい展開が訪れそう！',
    '周囲への感謝を言葉にすると素敵なご縁が深まります。',
    'マイペースな行動が吉。深呼吸してリラックスを。',
    '新しい発見がある日。いつもと違う道を通ってみて。',
    '笑顔で過ごすことで周りにも元気を分け合えます。',
    '小さな積み重ねが大きな成果に繋がるチャンスの日！',
    '美味しいものを食べて心と体に栄養をチャージして。',
    '懐かしい人への連絡や挨拶が幸運を引き寄せます。',
    '好きなことに集中すると想像以上のパワーを発揮！',
    '穏やかな会話が心をほぐし、良い情報が入ります。',
    '身の回りを整えると気持ちがすっきり前向きに。',
    '温かい飲み物で一息ついて、自分を褒めてあげて。'
];

// 日付シード型疑似乱数
function seededRandom(seed) {
    const x = Math.sin(seed++) * 10000;
    return x - Math.floor(x);
}

/**
 * 指定日の12星座占い結果を一括生成
 * @param {Date} date 
 */
export function getDailyFortune(date = new Date()) {
    const y = date.getFullYear();
    const m = date.getMonth() + 1;
    const d = date.getDate();
    const dateStr = `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    const dateSeed = y * 10000 + m * 100 + d;

    // 順位シャッフル (1〜12位)
    const indices = Array.from({ length: 12 }, (_, i) => i);
    for (let i = indices.length - 1; i > 0; i--) {
        const j = Math.floor(seededRandom(dateSeed + i * 17) * (i + 1));
        [indices[i], indices[j]] = [indices[j], indices[i]];
    }

    const fortunes = {};
    const rankingList = [];

    ZODIAC_LIST.forEach((zodiac, idx) => {
        const rank = indices[idx] + 1;
        const zodiacSeed = dateSeed + idx * 31;
        
        // 星の数 (1位: ★5, 12位: ★2 等)
        let starsCount = 5 - Math.floor((rank - 1) / 3);
        if (starsCount < 2) starsCount = 2;
        const stars = '★'.repeat(starsCount) + '☆'.repeat(5 - starsCount);

        const color = LUCKY_COLORS[Math.floor(seededRandom(zodiacSeed + 1) * LUCKY_COLORS.length)];
        const item = LUCKY_ITEMS[Math.floor(seededRandom(zodiacSeed + 2) * LUCKY_ITEMS.length)];
        const advice = ADVICES[Math.floor(seededRandom(zodiacSeed + 3) * ADVICES.length)];

        const data = {
            key: zodiac.key,
            name: zodiac.name,
            period: zodiac.period,
            emoji: zodiac.emoji,
            rank,
            stars,
            starsCount,
            luckyColor: color,
            luckyItem: item,
            advice,
            dateStr
        };

        fortunes[zodiac.key] = data;
        fortunes[zodiac.name] = data;
        rankingList.push(data);
    });

    rankingList.sort((a, b) => a.rank - b.rank);
    const topRank = rankingList[0];

    return {
        dateStr,
        fortunes,
        ranking: rankingList,
        topRank
    };
}
