import { GlobalFonts, createCanvas, loadImage } from '@napi-rs/canvas';
import * as fs from 'fs';
import * as path from 'path';

// LINE Seed JP フォント登録
const fontPath = path.resolve(process.cwd(), 'fonts/LINESeedJP-Bold.ttf');
if (fs.existsSync(fontPath)) {
    GlobalFonts.registerFromPath(fontPath, 'LINESeedJP');
    console.log(`Registered font: ${fontPath}`);
} else {
    console.warn(`Font not found: ${fontPath}`);
}

async function run() {
    // 1. 静岡県三島市 天気API (8日間の日別＆時間別予報)
    const apiUrl = 'https://api.open-meteo.com/v1/forecast?latitude=35.1184&longitude=138.9184&hourly=precipitation_probability,precipitation,weathercode&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=Asia%2FTokyo&forecast_days=8';
    const res = await fetch(apiUrl);
    const weatherData = await res.json() as any;

    let weatherLabel = '晴れ時々曇り';
    let weatherIconKey = 'sun_cloud';
    let maxTemp = 28;
    let minTemp = 20;

    if (weatherData.daily && weatherData.daily.weathercode && weatherData.daily.weathercode.length > 0) {
        const code = weatherData.daily.weathercode[0];
        maxTemp = Math.round(weatherData.daily.temperature_2m_max[0]);
        minTemp = Math.round(weatherData.daily.temperature_2m_min[0]);

        if (code === 0) { weatherLabel = '快晴'; weatherIconKey = 'sun'; }
        else if (code >= 1 && code <= 3) { weatherLabel = '晴れ時々曇り'; weatherIconKey = 'sun_cloud'; }
        else if (code >= 45 && code <= 48) { weatherLabel = '霧'; weatherIconKey = 'cloud'; }
        else if (code >= 51 && code <= 67) { weatherLabel = '雨'; weatherIconKey = 'rain'; }
        else if (code >= 71 && code <= 77) { weatherLabel = '雪'; weatherIconKey = 'snow'; }
        else if (code >= 80 && code <= 82) { weatherLabel = 'にわか雨'; weatherIconKey = 'rain'; }
        else if (code >= 95) { weatherLabel = '雷雨'; weatherIconKey = 'thunder'; }
    }

    const now = new Date();
    const dayNames = ['日', '月', '火', '水', '木', '金', '土'];
    const dayStr = dayNames[now.getDay()];
    const datePrefix = `${now.getMonth() + 1}/${now.getDate()}(${dayStr})`;
    const hourStr = `${now.getHours()}時時点`;

    // 1行目のテキストパーツ (アイコンを間に挟む)
    const line1_prefix = `${datePrefix} ${hourStr}　三島市の天気：`;
    const line1_suffix = ` ${weatherLabel}（最高 ${maxTemp}℃ / 最低 ${minTemp}℃）`;

    // 2. 直近1週間（8日間）の雨予報スキャン
    let nextRainStr = '';
    const nowTs = Date.now();
    const hourlyTimes = weatherData.hourly?.time || [];
    const hourlyProbs = weatherData.hourly?.precipitation_probability || [];
    const hourlyCodes = weatherData.hourly?.weathercode || [];

    // 最も近い雨の降り始め
    for (let i = 0; i < hourlyTimes.length; i++) {
        const tTs = new Date(hourlyTimes[i]).getTime();
        if (tTs >= nowTs) {
            const p = hourlyProbs[i] || 0;
            const c = hourlyCodes[i] || 0;
            if (p >= 40 || (c >= 51 && c <= 67) || (c >= 80 && c <= 82)) {
                const targetDate = new Date(hourlyTimes[i]);
                const todayDate = new Date();
                const isToday = targetDate.getDate() === todayDate.getDate() && targetDate.getMonth() === todayDate.getMonth();
                const isTomorrow = targetDate.getDate() === (todayDate.getDate() + 1) && targetDate.getMonth() === todayDate.getMonth();
                const dayPrefix = isToday ? '今日' : (isTomorrow ? '明日' : `${targetDate.getMonth() + 1}/${targetDate.getDate()}(${dayNames[targetDate.getDay()]})`);
                const hourNum = targetDate.getHours();
                nextRainStr = `${dayPrefix} ${hourNum}時〜 (降水確率${p}%)`;
                break;
            }
        }
    }

    // 週間（3日目以降）の雨の日をリストアップ
    const futureRainDays: string[] = [];
    if (weatherData.daily && weatherData.daily.time) {
        const dailyTimes = weatherData.daily.time;
        const dailyProbs = weatherData.daily.precipitation_probability_max || [];
        const dailyCodes = weatherData.daily.weathercode || [];

        for (let d = 2; d < dailyTimes.length; d++) {
            const p = dailyProbs[d] || 0;
            const c = dailyCodes[d] || 0;
            if (p >= 50 || (c >= 51 && c <= 67) || (c >= 80 && c <= 82)) {
                const dObj = new Date(dailyTimes[d]);
                futureRainDays.push(`${dObj.getDate()}日(${dayNames[dObj.getDay()]})`);
            }
        }
    }

    // 2行目のテキストパーツ
    let line2_text = '';
    let hasRain = false;
    if (nextRainStr) {
        hasRain = true;
        if (futureRainDays.length > 0) {
            line2_text = `直近の雨：${nextRainStr} ｜ 週間：${futureRainDays.slice(0, 3).join('・')}も雨予報`;
        } else {
            line2_text = `直近の雨：${nextRainStr} ｜ その後は晴れ間が広がる見込み`;
        }
    } else {
        line2_text = `目先1週間はまとまった雨の心配はありません`;
    }

    console.log(`Line 1: ${line1_prefix}[${weatherIconKey}]${line1_suffix}`);
    console.log(`Line 2: [umbrella] ${line2_text}`);

    // 3. チャンネルアクセストークン取得 (config.phpより)
    const configPath = path.resolve(process.cwd(), '../public_html/config.php');
    const configContent = fs.readFileSync(configPath, 'utf8');
    const tokenMatch = configContent.match(/'channel_access_token'\s*=>\s*'([^']+)'/);
    if (!tokenMatch) {
        throw new Error('channel_access_token not found');
    }
    const token = tokenMatch[1];

    // 4. ベース画像ダウンロード (【使用中】占いメニュー)
    const baseMenuId = 'richmenu-96d1e2e472554ce1f83b1d2aab13ec3f';
    const imgRes = await fetch(`https://api-data.line.me/v2/bot/richmenu/${baseMenuId}/content`, {
        headers: { 'Authorization': `Bearer ${token}` }
    });
    if (!imgRes.ok) {
        throw new Error(`Failed to download base image: ${imgRes.statusText}`);
    }
    const imgBuf = Buffer.from(await imgRes.arrayBuffer());

    // 絵文字画像読み込み
    const weatherIconPath = path.resolve(process.cwd(), `emojis/${weatherIconKey}.png`);
    const weatherIconImg = await loadImage(fs.readFileSync(weatherIconPath));
    const umbrellaIconPath = path.resolve(process.cwd(), `emojis/${hasRain ? 'umbrella' : 'sun'}.png`);
    const umbrellaIconImg = await loadImage(fs.readFileSync(umbrellaIconPath));

    // 5. Canvas描画
    const baseImage = await loadImage(imgBuf);
    const canvas = createCanvas(2500, 1686);
    const ctx = canvas.getContext('2d');

    // 下地
    ctx.drawImage(baseImage, 0, 0, 2500, 1686);

    // 上部300pxオレンジ帯
    const bannerHeight = 300;
    const grad = ctx.createLinearGradient(0, 0, 0, bannerHeight);
    grad.addColorStop(0, '#ea580c');
    grad.addColorStop(1, '#c2410c');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, 2500, bannerHeight);

    // 区切り線
    ctx.fillStyle = '#fed7aa';
    ctx.fillRect(0, bannerHeight - 6, 2500, 6);

    const maxWidth = 2420;
    const y1 = 98;
    const y2 = 202;

    // --- 1行目描画 (prefix + 絵文字アイコン + suffix) ---
    let font1Size = 64;
    ctx.font = `bold ${font1Size}px "LINESeedJP", sans-serif`;
    let icon1Size = 60;
    let w_pre = ctx.measureText(line1_prefix).width;
    let w_suf = ctx.measureText(line1_suffix).width;
    let total1W = w_pre + icon1Size + 10 + w_suf;

    while (total1W > maxWidth && font1Size > 34) {
        font1Size -= 2;
        icon1Size = Math.round(font1Size * 0.95);
        ctx.font = `bold ${font1Size}px "LINESeedJP", sans-serif`;
        w_pre = ctx.measureText(line1_prefix).width;
        w_suf = ctx.measureText(line1_suffix).width;
        total1W = w_pre + icon1Size + 10 + w_suf;
    }

    const start1X = Math.max(30, (2500 - total1W) / 2);

    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';

    // prefix
    ctx.fillStyle = 'rgba(0, 0, 0, 0.5)';
    ctx.fillText(line1_prefix, start1X + 2, y1 + 2);
    ctx.fillStyle = '#ffffff';
    ctx.fillText(line1_prefix, start1X, y1);

    // icon1
    const icon1X = start1X + w_pre + 5;
    const icon1Y = y1 - (icon1Size / 2);
    ctx.drawImage(weatherIconImg, icon1X, icon1Y, icon1Size, icon1Size);

    // suffix
    const suf1X = icon1X + icon1Size + 5;
    ctx.fillStyle = 'rgba(0, 0, 0, 0.5)';
    ctx.fillText(line1_suffix, suf1X + 2, y1 + 2);
    ctx.fillStyle = '#ffffff';
    ctx.fillText(line1_suffix, suf1X, y1);

    // --- 2行目描画 (傘アイコン + line2_text) ---
    let font2Size = 58;
    ctx.font = `bold ${font2Size}px "LINESeedJP", sans-serif`;
    let icon2Size = 54;
    let w2_text = ctx.measureText(line2_text).width;
    let total2W = icon2Size + 12 + w2_text;

    while (total2W > maxWidth && font2Size > 30) {
        font2Size -= 2;
        icon2Size = Math.round(font2Size * 0.92);
        ctx.font = `bold ${font2Size}px "LINESeedJP", sans-serif`;
        w2_text = ctx.measureText(line2_text).width;
        total2W = icon2Size + 12 + w2_text;
    }

    const start2X = Math.max(30, (2500 - total2W) / 2);

    // icon2 (傘 or 太陽)
    const icon2Y = y2 - (icon2Size / 2);
    ctx.drawImage(umbrellaIconImg, start2X, icon2Y, icon2Size, icon2Size);

    // line2_text
    const text2X = start2X + icon2Size + 12;
    ctx.fillStyle = 'rgba(0, 0, 0, 0.5)';
    ctx.fillText(line2_text, text2X + 2, y2 + 2);
    ctx.fillStyle = hasRain ? '#fef08a' : '#f0fdf4'; // 雨予報があるときはイエロー
    ctx.fillText(line2_text, text2X, y2);

    const outJpg = path.resolve(process.cwd(), 'weather_richmenu.jpg');
    fs.writeFileSync(outJpg, canvas.toBuffer('image/jpeg', 92));
    console.log(`JPEG saved to ${outJpg} (Font1: ${font1Size}px, Font2: ${font2Size}px)`);

    // 6. LINE リッチメニュー作成
    const menuBody = {
        size: { width: 2500, height: 1686 },
        selected: true,
        name: `三島天気 (${now.getMonth() + 1}/${now.getDate()} ${now.getHours()}:${now.getMinutes()})`,
        chatBarText: 'メニュー',
        areas: [
            {
                bounds: { x: 0, y: 0, width: 2500, height: 300 },
                action: {
                    type: 'uri',
                    label: '三島市の天気詳細',
                    uri: 'https://weathernews.jp/onebox/tenki/shizuoka/22206/'
                }
            },
            {
                bounds: { x: 0, y: 300, width: 833, height: 693 },
                action: { type: 'uri', label: '今日の運勢', uri: 'https://fortune.line.me/' }
            },
            {
                bounds: { x: 833, y: 300, width: 834, height: 693 },
                action: { type: 'uri', label: 'タロット占い', uri: 'https://fortune.line.me/' }
            },
            {
                bounds: { x: 1667, y: 300, width: 833, height: 693 },
                action: { type: 'uri', label: '相性占い', uri: 'https://fortune.line.me/' }
            },
            {
                bounds: { x: 0, y: 993, width: 1250, height: 693 },
                action: { type: 'message', label: '教室への問い合わせ', text: '問い合わせ' }
            },
            {
                bounds: { x: 1250, y: 993, width: 1250, height: 693 },
                action: { type: 'message', label: '予約確認', text: '予約確認' }
            }
        ]
    };

    const createRes = await fetch('https://api.line.me/v2/bot/richmenu', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(menuBody)
    });
    const createData = await createRes.json() as any;
    console.log('Create Rich Menu Response:', createData);
    const newMenuId = createData.richMenuId;
    if (!newMenuId) {
        throw new Error('Failed to create rich menu');
    }

    // 7. 画像アップロード
    const uploadRes = await fetch(`https://api-data.line.me/v2/bot/richmenu/${newMenuId}/content`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'image/jpeg'
        },
        body: fs.readFileSync(outJpg)
    });
    console.log('Upload image status:', uploadRes.status);

    // 8. かわいたくや様へアタッチ
    const userId = 'U38c887032d23d83bcc44ae08c1f987a2';
    
    // 現在のメニューID取得
    const curRes = await fetch(`https://api.line.me/v2/bot/user/${userId}/richmenu`, {
        headers: { 'Authorization': `Bearer ${token}` }
    });
    const curData = await curRes.json() as any;
    const oldMenuId = curData.richMenuId;
    console.log(`Current user menu: ${oldMenuId}`);

    // 新メニューリンク
    const linkRes = await fetch(`https://api.line.me/v2/bot/user/${userId}/richmenu/${newMenuId}`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${token}` }
    });
    console.log('Link menu status:', linkRes.status);

    // 9. 古いリッチメニューを削除
    if (oldMenuId && oldMenuId !== newMenuId && oldMenuId !== baseMenuId) {
        const delRes = await fetch(`https://api.line.me/v2/bot/richmenu/${oldMenuId}`, {
            method: 'DELETE',
            headers: { 'Authorization': `Bearer ${token}` }
        });
        console.log(`Deleted old menu ${oldMenuId}: ${delRes.status}`);
    }

    console.log('✅ 高画質絵文字アイコン付きリッチメニューの適用完了！');
}

run().catch(console.error);
