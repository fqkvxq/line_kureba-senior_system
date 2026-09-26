import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { createCanvas, loadImage, GlobalFonts } from '@napi-rs/canvas';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// フォント登録
const fontPath = path.join(__dirname, 'fonts', 'LINESeedJP-Bold.ttf');
if (fs.existsSync(fontPath)) {
    GlobalFonts.registerFromPath(fontPath, 'LINESeedJP-Bold');
}

// line_accounts.json から senior アカウントの token 取得
const accountsJsonPath = path.join(__dirname, '..', 'public_html', 'data', 'line_accounts.json');
const accountsData = JSON.parse(fs.readFileSync(accountsJsonPath, 'utf8'));
const seniorAccount = accountsData.accounts.find(a => a.id === 'senior');
const CHANNEL_ACCESS_TOKEN = seniorAccount.channel_access_token;

async function run() {
    console.log('Fetching Mishima weather from Open-Meteo...');
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
    let line3_text = '';
    const hasRain = !!nextRainStr;
    if (hasRain) {
        line2_text = `直近の雨：${nextRainStr}`;
        if (futureRainDays.length > 0) {
            line3_text = `週間予報：${futureRainDays.slice(0, 4).join('・')}も雨予報`;
        } else {
            line3_text = `週間予報：目先1週間は晴れ間が広がる見込み`;
        }
    } else {
        line2_text = '直近の雨の心配はありません';
        line3_text = '週間予報：目先1週間はまとまった雨の心配なし';
    }

    console.log('Line 1:', line1_pre + ' [icon] ' + line1_suf);
    console.log('Line 2:', line2_text);
    console.log('Line 3:', line3_text);

    // LINEからベース元画像を取得
    console.log('Downloading base menu image from LINE...');
    const baseMenuImgRes = await fetch('https://api-data.line.me/v2/bot/richmenu/richmenu-96d1e2e472554ce1f83b1d2aab13ec3f/content', {
        headers: { 'Authorization': `Bearer ${CHANNEL_ACCESS_TOKEN}` }
    });
    const baseImgBuf = Buffer.from(await baseMenuImgRes.arrayBuffer());
    const baseImg = await loadImage(baseImgBuf);
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

    // 描画設定（3行構成：52px / 48px / 48px）
    const fontName = 'LINESeedJP-Bold';
    ctx.textBaseline = 'middle';

    // === 1行目: 今日の天気 (Y=62) ===
    const fontSize1 = 52;
    const icon1Size = 54;
    ctx.font = `bold ${fontSize1}px "${fontName}"`;
    const w_pre = ctx.measureText(line1_pre).width;
    const w_suf = ctx.measureText(line1_suf).width;
    const total1W = w_pre + icon1Size + 8 + w_suf;
    const start1X = Math.max(25, Math.floor((2500 - total1W) / 2));
    const y1 = 62;

    // 影 & 白文字
    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillText(line1_pre, start1X + 2, y1 + 2);
    ctx.fillStyle = '#FFFFFF';
    ctx.fillText(line1_pre, start1X, y1);

    // 天気アイコン
    const emoji1Path = path.join(__dirname, 'emojis', `${weatherIconKey}.png`);
    if (fs.existsSync(emoji1Path)) {
        const e1 = await loadImage(fs.readFileSync(emoji1Path));
        ctx.drawImage(e1, start1X + w_pre + 4, y1 - icon1Size/2, icon1Size, icon1Size);
    }

    // suffix
    const suf1X = start1X + w_pre + 4 + icon1Size + 4;
    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillText(line1_suf, suf1X + 2, y1 + 2);
    ctx.fillStyle = '#FFFFFF';
    ctx.fillText(line1_suf, suf1X, y1);

    // === 2行目: 直近の雨 (Y=145) ===
    const fontSize2 = 48;
    const icon2Size = 50;
    ctx.font = `bold ${fontSize2}px "${fontName}"`;
    const w2_text = ctx.measureText(line2_text).width;
    const total2W = icon2Size + 10 + w2_text;
    const start2X = Math.max(25, Math.floor((2500 - total2W) / 2));
    const y2 = 145;

    const emoji2Key = hasRain ? 'umbrella' : 'sun';
    const emoji2Path = path.join(__dirname, 'emojis', `${emoji2Key}.png`);
    if (fs.existsSync(emoji2Path)) {
        const e2 = await loadImage(fs.readFileSync(emoji2Path));
        ctx.drawImage(e2, start2X, y2 - icon2Size/2, icon2Size, icon2Size);
    }

    const text2X = start2X + icon2Size + 10;
    const color2 = hasRain ? '#FEF08A' : '#F0FDF4'; // 黄色 or 薄緑
    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillText(line2_text, text2X + 2, y2 + 2);
    ctx.fillStyle = color2;
    ctx.fillText(line2_text, text2X, y2);

    // === 3行目: 週間予報 (Y=228) ===
    const fontSize3 = 48;
    const icon3Size = 50;
    ctx.font = `bold ${fontSize3}px "${fontName}"`;
    const w3_text = ctx.measureText(line3_text).width;
    const total3W = icon3Size + 10 + w3_text;
    const start3X = Math.max(25, Math.floor((2500 - total3W) / 2));
    const y3 = 228;

    const hasFutureRain = futureRainDays.length > 0;
    const emoji3Key = hasFutureRain ? 'umbrella' : 'sun';
    const emoji3Path = path.join(__dirname, 'emojis', `${emoji3Key}.png`);
    if (fs.existsSync(emoji3Path)) {
        const e3 = await loadImage(fs.readFileSync(emoji3Path));
        ctx.drawImage(e3, start3X, y3 - icon3Size/2, icon3Size, icon3Size);
    }

    const text3X = start3X + icon3Size + 10;
    const color3 = hasFutureRain ? '#FEF08A' : '#F0FDF4';
    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillText(line3_text, text3X + 2, y3 + 2);
    ctx.fillStyle = color3;
    ctx.fillText(line3_text, text3X, y3);

    const imgBuffer = canvas.toBuffer('image/png');
    const outPath = path.join(__dirname, 'final_richmenu.png');
    fs.writeFileSync(outPath, imgBuffer);
    console.log('Saved final_richmenu.png');

    // 3. 名言取得（10文字以内）
    const quotes = [
        "継続は力なり",
        "初心忘るべからず",
        "笑う門には福来る",
        "日日是好日",
        "一期一会",
        "七転び八起き",
        "感謝の心を忘れずに",
        "一歩ずつの前進",
        "笑顔で元気に",
        "明日は明日の風が吹く",
        "今を楽しむ",
        "いつもありがとう",
        "塵も積もれば山となる",
        "思い立ったが吉日",
        "焦らず一歩ずつ"
    ];
    const currentQuote = quotes[now.getHours() % quotes.length];
    console.log(`Setting chatBarText to quote: "${currentQuote}"`);

    // 4. LINEリッチメニュー作成
    const menuBody = {
        size: { width: 2500, height: 1686 },
        selected: true,
        name: `MishimaWeather_${now.getHours()}h`,
        chatBarText: currentQuote,
        areas: [
            {
                bounds: { x: 0, y: 300, width: 1250, height: 1386 },
                action: { type: 'uri', uri: 'https://kureba-senior.com/' }
            },
            {
                bounds: { x: 1250, y: 300, width: 1250, height: 1386 },
                action: { type: 'uri', uri: 'https://kureba.co.jp/line_kureba-senior_system/public_html/liff.html' }
            }
        ]
    };

    console.log('Creating richmenu in LINE...');
    const createRes = await fetch('https://api.line.me/v2/bot/richmenu', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${CHANNEL_ACCESS_TOKEN}`,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(menuBody)
    });
    const createJson = await createRes.json();
    if (!createJson.richMenuId) {
        console.error('Failed to create richmenu:', createJson);
        return;
    }
    const richMenuId = createJson.richMenuId;
    console.log('Created richMenuId:', richMenuId);

    // 5. 画像アップロード
    console.log('Uploading image to LINE...');
    const uploadRes = await fetch(`https://api-data.line.me/v2/bot/richmenu/${richMenuId}/content`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${CHANNEL_ACCESS_TOKEN}`,
            'Content-Type': 'image/png'
        },
        body: imgBuffer
    });
    console.log('Upload response status:', uploadRes.status);

    // 6. デフォルトリッチメニューに設定
    console.log('Setting as default richmenu...');
    const setDefRes = await fetch(`https://api.line.me/v2/bot/user/all/richmenu/${richMenuId}`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${CHANNEL_ACCESS_TOKEN}`
        }
    });
    console.log('Set default status:', setDefRes.status);
    console.log('SUCCESS! Applied richmenu:', richMenuId);
}

run().catch(console.error);
