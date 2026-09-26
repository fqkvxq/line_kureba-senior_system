const fs = require('fs');
const path = require('path');
const { createCanvas, loadImage, registerFont } = require('canvas');

// フォント登録
const fontPath = path.join(__dirname, 'fonts', 'LINESeedJP-Bold.ttf');
if (fs.existsSync(fontPath)) {
    registerFont(fontPath, { family: 'LINESeedJP-Bold' });
}

async function run() {
    // 1. 三島市（緯度 35.1184, 経度 138.9184）の最新天気APIを取得
    const res = await fetch('https://api.open-meteo.com/v1/forecast?latitude=35.1184&longitude=138.9184&hourly=precipitation_probability,precipitation,weathercode&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=Asia%2FTokyo&forecast_days=8');
    const weatherData = await res.json();

    const maxTemp = Math.round(weatherData.daily.temperature_2m_max[0]);
    const minTemp = Math.round(weatherData.daily.temperature_2m_min[0]);
    const code = weatherData.daily.weathercode[0];

    let weatherLabel = '晴れ時々曇り';
    let weatherIconKey = 'sun_cloud';
    if (code === 0) { weatherLabel = '快晴'; weatherIconKey = 'sun'; }
    else if (code >= 1 && code <= 3) { weatherLabel = '晴れ時々曇り'; weatherIconKey = 'sun_cloud'; }
    else if (code >= 45 && code <= 48) { weatherLabel = '霧'; weatherIconKey = 'cloud'; }
    else if (code >= 51 && code <= 67) { weatherLabel = '雨'; weatherIconKey = 'rain'; }
    else if (code >= 71 && code <= 77) { weatherLabel = '雪'; weatherIconKey = 'snow'; }
    else if (code >= 80 && code <= 82) { weatherLabel = 'にわか雨'; weatherIconKey = 'rain'; }
    else if (code >= 95) { weatherLabel = '雷雨'; weatherIconKey = 'thunder'; }

    // 最も近い雨の降り始め時間（時間別予報）
    const nowTs = Date.now();
    const dayNames = ['日', '月', '火', '水', '木', '金', '土'];
    let nextRainStr = '';
    const futureRainDays = [];

    const hTimes = weatherData.hourly.time;
    const hProbs = weatherData.hourly.precipitation_probability;
    const hCodes = weatherData.hourly.weathercode;

    for (let i = 0; i < hTimes.length; i++) {
        const tTs = new Date(hTimes[i] + '+09:00').getTime();
        if (tTs >= nowTs - 3600000) {
            const p = hProbs[i] || 0;
            const c = hCodes[i] || 0;
            if (p >= 40 || (c >= 51 && c <= 67) || (c >= 80 && c <= 82)) {
                const rainDate = new Date(tTs);
                const nowDate = new Date(nowTs);
                const isToday = rainDate.getDate() === nowDate.getDate();
                const isTomorrow = rainDate.getDate() === (nowDate.getDate() + 1);
                const dayPrefix = isToday ? '今日' : (isTomorrow ? '明日' : `${rainDate.getMonth()+1}/${rainDate.getDate()}(${dayNames[rainDate.getDay()]})`);
                nextRainStr = `${dayPrefix} ${rainDate.getHours()}時〜 (降水確率${p}%)`;
                break;
            }
        }
    }

    // 週間雨予報 (3日目以降)
    const dTimes = weatherData.daily.time;
    const dProbs = weatherData.daily.precipitation_probability_max;
    const dCodes = weatherData.daily.weathercode;
    for (let d = 2; d < dTimes.length; d++) {
        const p = dProbs[d] || 0;
        const c = dCodes[d] || 0;
        if (p >= 50 || (c >= 51 && c <= 67) || (c >= 80 && c <= 82)) {
            const dDate = new Date(dTimes[d] + 'T00:00:00+09:00');
            futureRainDays.push(`${dDate.getDate()}日(${dayNames[dDate.getDay()]})`);
        }
    }

    const now = new Date();
    const datePrefix = `${now.getMonth()+1}/${now.getDate()}(${dayNames[now.getDay()]})`;
    const hourStr = `${now.getHours()}時時点`;

    const line1_pre = `${datePrefix} ${hourStr}　三島市の天気：`;
    const line1_suf = ` ${weatherLabel}（最高 ${maxTemp}℃ / 最低 ${minTemp}℃）`;

    let line2_text = '';
    const hasRain = !!nextRainStr;
    if (hasRain) {
        if (futureRainDays.length > 0) {
            line2_text = `直近の雨：${nextRainStr} ｜ 週間：${futureRainDays.slice(0, 3).join('・')}も雨予報`;
        } else {
            line2_text = `直近の雨：${nextRainStr} ｜ その後は晴れ間が広がる見込み`;
        }
    } else {
        line2_text = '目先1週間はまとまった雨の心配はありません';
    }

    console.log('Line 1:', line1_pre + ' [icon] ' + line1_suf);
    console.log('Line 2:', line2_text);

    // 2. 画像描画
    const baseImgPath = path.join(__dirname, 'base_restored.png');
    const baseImg = await loadImage(baseImgPath);
    const canvas = createCanvas(2500, 1686);
    const ctx = canvas.getContext('2d');

    ctx.drawImage(baseImg, 0, 0, 2500, 1686);

    // 上部 300px オレンジグラデーション
    const grad = ctx.createLinearGradient(0, 0, 0, 300);
    grad.addColorStop(0, '#FF8700');
    grad.addColorStop(1, '#EA7200');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, 2500, 300);

    // 下部境界線
    ctx.strokeStyle = '#FFE0B2';
    ctx.lineWidth = 6;
    ctx.beginPath();
    ctx.moveTo(0, 300);
    ctx.lineTo(2500, 300);
    ctx.stroke();

    // 描画設定（フォント 60px、行間をさらに狭く設定）
    // 1行目: Y=110, 2行目: Y=180 (行間ベースライン差 70px)
    const fontSize1 = 60;
    const fontSize2 = 60;
    const fontName = 'LINESeedJP-Bold';

    ctx.font = `bold ${fontSize1}px "${fontName}"`;
    ctx.textBaseline = 'middle';

    const w_pre = ctx.measureText(line1_pre).width;
    const w_suf = ctx.measureText(line1_suf).width;
    const icon1Size = 62;
    const total1W = w_pre + icon1Size + 10 + w_suf;
    const start1X = Math.max(25, Math.floor((2500 - total1W) / 2));
    const y1 = 110; // 上部から110px

    // 影
    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillText(line1_pre, start1X + 2, y1 + 2);

    // 白文字
    ctx.fillStyle = '#FFFFFF';
    ctx.fillText(line1_pre, start1X, y1);

    // 天気アイコン
    const emoji1Path = path.join(__dirname, 'emojis', `${weatherIconKey}.png`);
    if (fs.existsSync(emoji1Path)) {
        const e1 = await loadImage(emoji1Path);
        ctx.drawImage(e1, start1X + w_pre + 5, y1 - icon1Size/2, icon1Size, icon1Size);
    }

    // suffix
    const suf1X = start1X + w_pre + 5 + icon1Size + 5;
    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillText(line1_suf, suf1X + 2, y1 + 2);
    ctx.fillStyle = '#FFFFFF';
    ctx.fillText(line1_suf, suf1X, y1);

    // 2行目
    ctx.font = `bold ${fontSize2}px "${fontName}"`;
    const w2_text = ctx.measureText(line2_text).width;
    const icon2Size = 62;
    const total2W = icon2Size + 12 + w2_text;
    const start2X = Math.max(25, Math.floor((2500 - total2W) / 2));
    const y2 = 185; // 1行目から75px下（行間をギュッと引き締め）

    // 傘 or 太陽 アイコン
    const emoji2Key = hasRain ? 'umbrella' : 'sun';
    const emoji2Path = path.join(__dirname, 'emojis', `${emoji2Key}.png`);
    if (fs.existsSync(emoji2Path)) {
        const e2 = await loadImage(emoji2Path);
        ctx.drawImage(e2, start2X, y2 - icon2Size/2, icon2Size, icon2Size);
    }

    const text2X = start2X + icon2Size + 12;
    const color2 = hasRain ? '#FEF08A' : '#F0FDF4'; // 雨ならイエロー

    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillText(line2_text, text2X + 2, y2 + 2);
    ctx.fillStyle = color2;
    ctx.fillText(line2_text, text2X, y2);

    const outPath = path.join(__dirname, 'final_richmenu.png');
    fs.writeFileSync(outPath, canvas.toBuffer('image/png'));
    console.log('Saved to:', outPath);
}

run().catch(console.error);
