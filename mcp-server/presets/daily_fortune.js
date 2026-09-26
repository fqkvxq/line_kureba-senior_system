/**
 * =========================================================================
 * 【今日の占いリッチメニュー】プリセット（文字化け完全解消版）
 * Preset Name: daily_fortune
 * 
 * 改善点:
 * - LINESeedフォントでグリフ欠損するカラー絵文字を排除し、
 *   美しい日本語タイポグラフィと記号（★、｜、◆、◎等）で100%確実に高精細描画。
 * =========================================================================
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { createCanvas, loadImage, GlobalFonts } from '@napi-rs/canvas';
import { getDailyFortune } from './fortune_engine.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// フォント登録
const fontPath = path.join(__dirname, '..', 'fonts', 'LINESeedJP-Bold.ttf');
if (fs.existsSync(fontPath)) {
    GlobalFonts.registerFromPath(fontPath, 'LINESeedJP-Bold');
}

// 元の公式リッチメニューの正しいタップ領域
export const ORIGINAL_AREAS = [
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

export async function generate(baseImgBuffer, options = {}) {
    const now = new Date();
    const fortuneData = getDailyFortune(now);
    const targetZodiacKey = options.zodiac || options.zodiacKey || 'all';

    const weekdaysFull = ['日', '月', '火', '水', '木', '金', '土'];
    const datePrefix = `${now.getMonth() + 1}月${now.getDate()}日(${weekdaysFull[now.getDay()]})`;

    let line1_text = '';
    let line2_text = '';
    let line3_text = '';
    let chatBar = '';
    let menuName = '';

    const isPersonalized = targetZodiacKey && targetZodiacKey !== 'all' && fortuneData.fortunes[targetZodiacKey];

    if (isPersonalized) {
        const f = fortuneData.fortunes[targetZodiacKey];
        line1_text = `${datePrefix}　【${f.name}】の運勢：第${f.rank}位 (総合運 ${f.stars})`;
        line2_text = `ラッキーカラー：${f.luckyColor} ｜ アイテム：${f.luckyItem}`;
        line3_text = `今日のアドバイス：${f.advice}`;
        chatBar = `今日の${f.name}の運勢：第${f.rank}位`;
        menuName = `Fortune_${f.key}_${now.getMonth()+1}_${now.getDate()}`;
    } else {
        const top = fortuneData.topRank;
        line1_text = `${datePrefix}　今日の星占い：本日の第1位は【${top.name}】`;
        line2_text = `全体のラッキーカラー：${top.luckyColor} ｜ アイテム：${top.luckyItem}`;
        line3_text = `星座を設定するとあなた専用の毎日の占いに変わります`;
        chatBar = `今日の占い：第1位は${top.name}`;
        menuName = `Fortune_All_${now.getMonth()+1}_${now.getDate()}`;
    }

    // キャンバス描画 (2500 x 1686)
    const canvas = createCanvas(2500, 1686);
    const ctx = canvas.getContext('2d');

    if (baseImgBuffer) {
        const baseImg = await loadImage(baseImgBuffer);
        ctx.drawImage(baseImg, 0, 0, 2500, 1686);
    }

    // 上部 300px 星空パープルグラデーション
    const grad = ctx.createLinearGradient(0, 0, 0, 300);
    grad.addColorStop(0, '#581C87'); // ディープパープル
    grad.addColorStop(1, '#3B0764'); // ダークバイオレット
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, 2500, 300);

    // 下部境界線
    ctx.strokeStyle = '#DDD6FE';
    ctx.lineWidth = 6;
    ctx.beginPath();
    ctx.moveTo(0, 300);
    ctx.lineTo(2500, 300);
    ctx.stroke();

    // 描画設定（3行構成：52px / 48px / 48px）
    const fontName = 'LINESeedJP-Bold';
    ctx.textBaseline = 'middle';

    // === 1行目: 星座と順位 (Y=62, 52px) ===
    const fontSize1 = 52;
    ctx.font = `bold ${fontSize1}px "${fontName}"`;
    const w1 = ctx.measureText(line1_text).width;
    const x1 = Math.max(25, Math.floor((2500 - w1) / 2));
    const y1 = 62;

    ctx.fillStyle = 'rgba(0, 0, 0, 0.45)';
    ctx.fillText(line1_text, x1 + 2, y1 + 2);
    ctx.fillStyle = '#FFFFFF';
    ctx.fillText(line1_text, x1, y1);

    // === 2行目: ラッキーカラー＆アイテム (Y=145, 48px) ===
    const fontSize2 = 48;
    ctx.font = `bold ${fontSize2}px "${fontName}"`;
    const w2 = ctx.measureText(line2_text).width;
    const x2 = Math.max(25, Math.floor((2500 - w2) / 2));
    const y2 = 145;

    ctx.fillStyle = 'rgba(0, 0, 0, 0.45)';
    ctx.fillText(line2_text, x2 + 2, y2 + 2);
    ctx.fillStyle = '#FEF08A'; // 明るいイエロー
    ctx.fillText(line2_text, x2, y2);

    // === 3行目: アドバイス / 登録案内 (Y=228, 48px) ===
    const fontSize3 = 48;
    ctx.font = `bold ${fontSize3}px "${fontName}"`;
    const w3 = ctx.measureText(line3_text).width;
    const x3 = Math.max(25, Math.floor((2500 - w3) / 2));
    const y3 = 228;

    ctx.fillStyle = 'rgba(0, 0, 0, 0.45)';
    ctx.fillText(line3_text, x3 + 2, y3 + 2);
    ctx.fillStyle = isPersonalized ? '#F0FDF4' : '#E9D5FF';
    ctx.fillText(line3_text, x3, y3);

    return {
        imageBuffer: canvas.toBuffer('image/png'),
        chatBarText: chatBar,
        name: menuName,
        areas: ORIGINAL_AREAS
    };
}

export const name = 'daily_fortune';
export const description = '12星座の今日の占い・ラッキーカラー・アドバイスリッチメニュー（文字化け完全解消版）';
