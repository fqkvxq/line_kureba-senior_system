/**
 * =========================================================================
 * 【全国・主要都市対応】リアルタイム天気＆名言リッチメニュー プリセット
 * Preset Name: city_weather
 * 対応都市: 三島市、静岡市、浜松市、横浜市、東京都 など
 * =========================================================================
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { createCanvas, loadImage, GlobalFonts } from '@napi-rs/canvas';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// フォント登録
const fontPath = path.join(__dirname, '..', 'fonts', 'LINESeedJP-Bold.ttf');
if (fs.existsSync(fontPath)) {
    GlobalFonts.registerFromPath(fontPath, 'LINESeedJP-Bold');
}

// 元の公式リッチメニューの正しいタップ領域 + 上部帯タップでモード切替
export const ORIGINAL_AREAS = [
    {
        bounds: {
            x: 0,
            y: 0,
            width: 2500,
            height: 300
        },
        action: {
            type: "postback",
            data: "action=ask_mode_switch&mode=weather"
        }
    },
    {
        bounds: {
            x: 25,
            y: 320,
            width: 1350,
            height: 1332
        },
        action: {
            type: "uri",
            uri: "https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1"
        }
    },
    {
        bounds: {
            x: 1375,
            y: 320,
            width: 1100,
            height: 1315
        },
        action: {
            type: "uri",
            uri: "https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcp%2FA9xhz7MWZF%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1"
        }
    }
];

export const CITIES_CONFIG = {
    mishima: { key: 'mishima', name: '三島市', label: '🌤️ 三島市', lat: 35.1184, lon: 138.9184 },
    shizuoka: { key: 'shizuoka', name: '静岡市', label: '🌤️ 静岡市', lat: 34.9756, lon: 138.3828 },
    hamamatsu: { key: 'hamamatsu', name: '浜松市', label: '🌤️ 浜松市', lat: 34.7108, lon: 137.7261 },
    yokohama: { key: 'yokohama', name: '横浜市', label: '🌤️ 横浜市', lat: 35.4437, lon: 139.6380 },
    tokyo: { key: 'tokyo', name: '東京都', label: '🌤️ 東京都', lat: 35.6895, lon: 139.6917 },
    osaka: { key: 'osaka', name: '大阪市', label: '🌤️ 大阪市', lat: 34.6937, lon: 135.5023 },
    fukuoka: { key: 'fukuoka', name: '福岡市', label: '🌤️ 福岡市', lat: 33.5904, lon: 130.4017 }
};

export const WEATHER_CODE_MAP = {
    0: { label: '快晴', icon: 'sun' },
    1: { label: '晴れ', icon: 'sun' },
    2: { label: '一部曇', icon: 'sun_cloud' },
    3: { label: '曇り', icon: 'cloud' },
    45: { label: '霧', icon: 'cloud' },
    48: { label: '霧', icon: 'cloud' },
    51: { label: '小雨', icon: 'rain' },
    53: { label: '小雨', icon: 'rain' },
    55: { label: '小雨', icon: 'rain' },
    61: { label: '雨', icon: 'rain' },
    63: { label: '雨', icon: 'rain' },
    65: { label: '大雨', icon: 'rain' },
    71: { label: '雪', icon: 'snow' },
    73: { label: '雪', icon: 'snow' },
    75: { label: '大雪', icon: 'snow' },
    80: { label: 'にわか雨', icon: 'rain' },
    81: { label: 'にわか雨', icon: 'rain' },
    82: { label: '激しい雨', icon: 'rain' },
    95: { label: '雷雨', icon: 'thunder' },
    96: { label: '雷雨', icon: 'thunder' },
    99: { label: '雷雨', icon: 'thunder' }
};

export const QUOTES = [
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

export async function generate(baseImgBuffer, options = {}) {
    const cityKey = options.cityKey || 'mishima';
    const city = CITIES_CONFIG[cityKey] || CITIES_CONFIG.mishima;
    const lat = options.lat || city.lat;
    const lon = options.lon || city.lon;
    const cityName = options.cityName || city.name;

    // 1. Open-Meteo 天気予報データ取得
    const weatherUrl = `https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max&hourly=weathercode,temperature_2m,precipitation,precipitation_probability&timezone=Asia%2FTokyo`;

    const res = await fetch(weatherUrl);
    const wData = await res.json();

    const todayCode = wData.daily?.weathercode?.[0] ?? 1;
    const maxTemp = Math.round(wData.daily?.temperature_2m_max?.[0] ?? 25);
    const minTemp = Math.round(wData.daily?.temperature_2m_min?.[0] ?? 18);
    const weatherInfo = WEATHER_CODE_MAP[todayCode] || { label: '晴れ', icon: 'sun' };
    const weatherLabel = weatherInfo.label;
    const weatherIconKey = weatherInfo.icon;

    const now = new Date();

    // 直近24時間の降水チェック
    let nextRainStr = null;
    const hourlyTime = wData.hourly?.time || [];
    const hourlyPrecipProb = wData.hourly?.precipitation_probability || [];
    const hourlyPrecip = wData.hourly?.precipitation || [];

    for (let i = 0; i < hourlyTime.length; i++) {
        const t = new Date(hourlyTime[i]);
        if (t >= now && t.getTime() <= now.getTime() + 24 * 60 * 60 * 1000) {
            const prob = hourlyPrecipProb[i] || 0;
            const amount = hourlyPrecip[i] || 0;
            if (prob >= 40 || amount >= 0.5) {
                const diffHours = Math.round((t - now) / (1000 * 60 * 60));
                const h = t.getHours();
                if (diffHours <= 1) {
                    nextRainStr = `現在〜1時間後から雨の可能性 (${prob}%)`;
                } else if (diffHours <= 6) {
                    nextRainStr = `本日 ${h}時頃から雨予報 (${prob}%)`;
                } else if (t.getDate() === now.getDate()) {
                    nextRainStr = `本日 夜（${h}時頃）から雨予報 (${prob}%)`;
                } else {
                    nextRainStr = `明日 ${h}時頃から雨予報 (${prob}%)`;
                }
                break;
            }
        }
    }

    // 週間予報で雨の日チェック
    const futureRainDays = [];
    const weekdays = ['日', '月', '火', '水', '木', '金', '土'];
    const dTime = wData.daily?.time || [];
    for (let i = 1; i < Math.min(7, dTime.length); i++) {
        const d = new Date(dTime[i]);
        const prob = wData.daily?.precipitation_probability_max?.[i] || 0;
        const code = wData.daily?.weathercode?.[i];
        if (prob >= 50 || [51,53,55,61,63,65,80,81,82,95,96,99].includes(code)) {
            futureRainDays.push(`${d.getMonth()+1}/${d.getDate()}(${weekdays[d.getDay()]})`);
        }
    }

    const weekdaysFull = ['日', '月', '火', '水', '木', '金', '土'];
    const datePrefix = `${now.getMonth() + 1}月${now.getDate()}日(${weekdaysFull[now.getDay()]})`;
    const hourStr = `${now.getHours()}時時点`;

    const line1_pre = `${datePrefix} ${hourStr}　${cityName}の天気：`;
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

    // キャンバス描画
    const canvas = createCanvas(2500, 1686);
    const ctx = canvas.getContext('2d');

    if (baseImgBuffer) {
        const baseImg = await loadImage(baseImgBuffer);
        ctx.drawImage(baseImg, 0, 0, 2500, 1686);
    }

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

    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillText(line1_pre, start1X + 2, y1 + 2);
    ctx.fillStyle = '#FFFFFF';
    ctx.fillText(line1_pre, start1X, y1);

    const emoji1Path = path.join(__dirname, '..', 'emojis', `${weatherIconKey}.png`);
    if (fs.existsSync(emoji1Path)) {
        const e1 = await loadImage(fs.readFileSync(emoji1Path));
        ctx.drawImage(e1, start1X + w_pre + 4, y1 - icon1Size/2, icon1Size, icon1Size);
    }

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
    const emoji2Path = path.join(__dirname, '..', 'emojis', `${emoji2Key}.png`);
    if (fs.existsSync(emoji2Path)) {
        const e2 = await loadImage(fs.readFileSync(emoji2Path));
        ctx.drawImage(e2, start2X, y2 - icon2Size/2, icon2Size, icon2Size);
    }

    const text2X = start2X + icon2Size + 10;
    const color2 = hasRain ? '#FEF08A' : '#F0FDF4';
    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillText(line2_text, text2X + 2, y2 + 2);
    ctx.fillStyle = color2;
    ctx.fillText(line2_text, text2X, y2);

    // === 3行目: 週間雨予報 (Y=228) ===
    const fontSize3 = 48;
    const icon3Size = 50;
    ctx.font = `bold ${fontSize3}px "${fontName}"`;
    const w3_text = ctx.measureText(line3_text).width;
    const total3W = icon3Size + 10 + w3_text;
    const start3X = Math.max(25, Math.floor((2500 - total3W) / 2));
    const y3 = 228;

    const emoji3Key = (futureRainDays.length > 0) ? 'umbrella' : 'sun';
    const emoji3Path = path.join(__dirname, '..', 'emojis', `${emoji3Key}.png`);
    if (fs.existsSync(emoji3Path)) {
        const e3 = await loadImage(fs.readFileSync(emoji3Path));
        ctx.drawImage(e3, start3X, y3 - icon3Size/2, icon3Size, icon3Size);
    }

    const text3X = start3X + icon3Size + 10;
    const color3 = (futureRainDays.length > 0) ? '#FEF08A' : '#F0FDF4';
    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillText(line3_text, text3X + 2, y3 + 2);
    ctx.fillStyle = color3;
    ctx.fillText(line3_text, text3X, y3);

    // 開閉バー名言
    const quoteIndex = now.getHours() % QUOTES.length;
    const chatBar = QUOTES[quoteIndex] || '焦らず一歩ずつ';

    return {
        imageBuffer: canvas.toBuffer('image/png'),
        chatBarText: chatBar,
        name: `Weather_${cityKey}_${now.getMonth()+1}_${now.getDate()}`,
        areas: ORIGINAL_AREAS,
        weatherSummary: `${cityName}の天気: ${weatherLabel} (${maxTemp}℃/${minTemp}℃)`
    };
}

export const name = 'city_weather';
export const description = '全国主要都市のリアルタイム天気・名言リッチメニュー';
