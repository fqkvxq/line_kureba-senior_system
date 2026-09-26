/**
 * =========================================================================
 * 【新規機能開発用テンプレート】リッチメニュー・プリセット (ESM)
 * Template Name: template_custom
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

export async function generate(baseImgBuffer, options = {}) {
    const canvas = createCanvas(2500, 1686);
    const ctx = canvas.getContext('2d');

    // 1. ベース画像（下部 1386px 分）を描画
    if (baseImgBuffer) {
        const baseImg = await loadImage(baseImgBuffer);
        ctx.drawImage(baseImg, 0, 0, 2500, 1686);
    }

    // 2. 上部ヘッダー（300px）の背景グラデーション（自由に色変更可能）
    const grad = ctx.createLinearGradient(0, 0, 0, 300);
    grad.addColorStop(0, options.headerColorStart || '#1E40AF');
    grad.addColorStop(1, options.headerColorEnd || '#1E3A8A');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, 2500, 300);

    // 境界線
    ctx.strokeStyle = options.borderColor || '#93C5FD';
    ctx.lineWidth = 6;
    ctx.beginPath();
    ctx.moveTo(0, 300);
    ctx.lineTo(2500, 300);
    ctx.stroke();

    // 3. テキスト描画設定
    const fontName = 'LINESeedJP-Bold';
    ctx.textBaseline = 'middle';

    // メインタイトル（中央揃え）
    const titleText = options.title || '【お知らせ】合同会社KUREBA 最新情報';
    ctx.font = `bold 54px "${fontName}"`;
    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillText(titleText, (2500 - ctx.measureText(titleText).width) / 2 + 2, 90 + 2);
    ctx.fillStyle = '#FFFFFF';
    ctx.fillText(titleText, (2500 - ctx.measureText(titleText).width) / 2, 90);

    // サブテキスト / 説明文（中央揃え）
    const subText = options.subText || 'タップして最新の点検予約・在庫情報をご確認いただけます';
    ctx.font = `bold 46px "${fontName}"`;
    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillText(subText, (2500 - ctx.measureText(subText).width) / 2 + 2, 195 + 2);
    ctx.fillStyle = '#FEF08A';
    ctx.fillText(subText, (2500 - ctx.measureText(subText).width) / 2, 195);

    return {
        imageBuffer: canvas.toBuffer('image/png'),
        chatBarText: options.chatBarText || 'メニューを開く',
        name: options.name || `CustomMenu_${Date.now()}`,
        areas: options.areas || [
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
}

export const name = 'template_custom';
export const description = '新規リッチメニュー作成用カスタムテンプレート';
