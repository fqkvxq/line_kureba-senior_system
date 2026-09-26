/**
 * =========================================================================
 * 12星座パーソナライズ リッチメニュー一括生成＆配布バッチ
 * 
 * 実行方法:
 *   node presets/batch_fortune_personalized.js
 * =========================================================================
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { generate } from './daily_fortune.js';
import { ZODIAC_LIST, getDailyFortune } from './fortune_engine.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// line_accounts.json から senior アカウントのトークンを取得
let CHANNEL_ACCESS_TOKEN = process.env.LINE_CHANNEL_ACCESS_TOKEN;
const accountsJsonPath = path.join(__dirname, '..', '..', 'public_html', 'data', 'line_accounts.json');
if (!CHANNEL_ACCESS_TOKEN && fs.existsSync(accountsJsonPath)) {
    try {
        const accountsData = JSON.parse(fs.readFileSync(accountsJsonPath, 'utf8'));
        const seniorAccount = accountsData.accounts?.find(a => a.id === 'senior') || accountsData.accounts?.[0];
        if (seniorAccount) {
            CHANNEL_ACCESS_TOKEN = seniorAccount.channel_access_token;
        }
    } catch (e) {
        console.warn('Could not read line_accounts.json:', e.message);
    }
}

const BASE_RICHMENU_ID = 'richmenu-96d1e2e472554ce1f83b1d2aab13ec3f';

export async function runPersonalizedFortuneBatch() {
    console.log('=== [バッチ開始] 12星座パーソナライズ リッチメニュー生成＆配信 ===');
    const now = new Date();
    const fortuneData = getDailyFortune(now);
    console.log(`日付: ${fortuneData.dateStr}, 今日の第1位: ${fortuneData.topRank.name} (${fortuneData.topRank.emoji})`);

    // 1. ベース元画像取得
    let baseImgBuf = null;
    const localBase = path.join(__dirname, '..', 'base_orig.jpg');
    if (fs.existsSync(localBase)) {
        baseImgBuf = fs.readFileSync(localBase);
    } else if (CHANNEL_ACCESS_TOKEN) {
        const baseRes = await fetch(`https://api-data.line.me/v2/bot/richmenu/${BASE_RICHMENU_ID}/content`, {
            headers: { 'Authorization': `Bearer ${CHANNEL_ACCESS_TOKEN}` }
        });
        if (baseRes.ok) {
            baseImgBuf = Buffer.from(await baseRes.arrayBuffer());
            fs.writeFileSync(localBase, baseImgBuf);
        }
    }

    const createdMenuMap = {}; // { 'all': 'richmenu-xxx', 'aries': 'richmenu-yyy', ... }

    // 2. 全体（総合）用リッチメニューの生成＆登録
    console.log('Generating All-users fortune richmenu...');
    const allResult = await generate(baseImgBuf, { zodiac: 'all' });
    const allMenuId = await createAndUploadLineRichmenu(allResult, 'Fortune_All');
    if (allMenuId) {
        createdMenuMap['all'] = allMenuId;
        // 全体のデフォルトリッチメニューに設定
        await setDefaultRichmenu(allMenuId);
        console.log(`Set default richmenu: ${allMenuId}`);
    }

    // 3. 12星座個別リッチメニューの生成＆登録
    for (const z of ZODIAC_LIST) {
        console.log(`Generating personalized menu for: ${z.name} (${z.key})...`);
        const zResult = await generate(baseImgBuf, { zodiac: z.key });
        const zMenuId = await createAndUploadLineRichmenu(zResult, `Fortune_${z.key}`);
        if (zMenuId) {
            createdMenuMap[z.key] = zMenuId;
            createdMenuMap[z.name] = zMenuId;
        }
    }

    // 4. ユーザー星座設定一覧を取得して個別アタッチ
    console.log('Fetching user zodiac settings from DB...');
    try {
        const dbPath = path.join(__dirname, '..', '..', 'public_html', 'data', 'senior.db');
        let userList = [];
        if (fs.existsSync(dbPath)) {
            // DBが存在する場合は user_zodiacs テーブルを照合
            const sqlite3 = await import('better-sqlite3').catch(() => null);
            if (sqlite3 && sqlite3.default) {
                const db = new sqlite3.default(dbPath);
                try {
                    userList = db.prepare('SELECT user_id, zodiac FROM user_zodiacs').all();
                } catch (e) {
                    console.log('No user_zodiacs table yet:', e.message);
                }
            }
        }

        console.log(`Found ${userList.length} users with zodiac settings.`);

        // ユーザー毎に個別アタッチ
        for (const u of userList) {
            const targetMenuId = createdMenuMap[u.zodiac];
            if (targetMenuId && u.user_id) {
                console.log(`Attaching ${u.zodiac} richmenu (${targetMenuId}) to user: ${u.user_id}`);
                await linkUserRichmenu(u.user_id, targetMenuId);
            }
        }
    } catch (e) {
        console.error('Error linking personalized user menus:', e);
    }

    console.log('🎉 12星座パーソナライズ配信バッチが正常に完了しました！');
    return { success: true, createdMenuMap };
}

async function createAndUploadLineRichmenu(result, prefix) {
    if (!CHANNEL_ACCESS_TOKEN) return null;

    const now = new Date();
    const menuBody = {
        size: { width: 2500, height: 1686 },
        selected: true,
        name: `${prefix}_${now.getMonth()+1}_${now.getDate()}`,
        chatBarText: result.chatBarText,
        areas: result.areas
    };

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
        console.error(`Failed to create richmenu ${prefix}:`, createJson);
        return null;
    }

    const richMenuId = createJson.richMenuId;

    await fetch(`https://api-data.line.me/v2/bot/richmenu/${richMenuId}/content`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${CHANNEL_ACCESS_TOKEN}`,
            'Content-Type': 'image/png'
        },
        body: result.imageBuffer
    });

    return richMenuId;
}

async function setDefaultRichmenu(richMenuId) {
    if (!CHANNEL_ACCESS_TOKEN) return;
    await fetch(`https://api.line.me/v2/bot/user/all/richmenu/${richMenuId}`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${CHANNEL_ACCESS_TOKEN}` }
    });
}

async function linkUserRichmenu(userId, richMenuId) {
    if (!CHANNEL_ACCESS_TOKEN) return;
    await fetch(`https://api.line.me/v2/bot/user/${userId}/richmenu/${richMenuId}`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${CHANNEL_ACCESS_TOKEN}` }
    });
}

// CLI直接実行時
const isMain = process.argv[1] && path.resolve(process.argv[1]) === path.resolve(__filename);
if (isMain) {
    runPersonalizedFortuneBatch().catch(console.error);
}
