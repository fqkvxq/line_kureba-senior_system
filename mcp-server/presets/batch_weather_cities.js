/**
 * =========================================================================
 * 5都市（三島市、静岡市、浜松市、横浜市、東京都）天気リッチメニュー一括生成＆登録バッチ
 * 使用法:
 *   node presets/batch_weather_cities.js
 * =========================================================================
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { generate, CITIES_CONFIG } from './city_weather.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const accountsJsonPath = path.join(__dirname, '..', '..', 'public_html', 'data', 'line_accounts.json');
let CHANNEL_ACCESS_TOKEN = process.env.LINE_CHANNEL_ACCESS_TOKEN;
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

export async function runCityWeatherBatch() {
    console.log('=== [バッチ開始] 5都市リアルタイム天気リッチメニュー一括生成＆登録 ===');

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

    const createdMenuMap = {};

    for (const [cityKey, city] of Object.entries(CITIES_CONFIG)) {
        console.log(`Generating weather richmenu for: ${city.name} (${cityKey})...`);
        const result = await generate(baseImgBuf, { cityKey, cityName: city.name, lat: city.lat, lon: city.lon });
        const menuId = await createAndUploadLineRichmenu(result, `Weather_${cityKey}`);
        if (menuId) {
            createdMenuMap[cityKey] = menuId;
            createdMenuMap[city.name] = menuId;
            console.log(` -> Registered [${city.name}]: ${menuId}`);

            // 三島市は全体デフォルトメニューに設定
            if (cityKey === 'mishima') {
                await setDefaultRichmenu(menuId);
                console.log(` -> Set as default richmenu: ${menuId}`);
            }
        }
    }

    // マッピングファイルを保存
    const outDataDir = path.join(__dirname, '..', '..', 'public_html', 'data');
    if (!fs.existsSync(outDataDir)) {
        fs.mkdirSync(outDataDir, { recursive: true });
    }
    const mapPath = path.join(outDataDir, 'weather_richmenus.json');
    fs.writeFileSync(mapPath, JSON.stringify(createdMenuMap, null, 2), 'utf8');
    console.log(`Saved city weather richmenu map to: ${mapPath}`);

    console.log('🎉 5都市天気リッチメニュー一括生成＆登録が正常に完了しました！');
    return { success: true, createdMenuMap };
}

async function createAndUploadLineRichmenu(result, prefix) {
    if (!CHANNEL_ACCESS_TOKEN) return null;

    const now = new Date();
    const menuBody = {
        size: { width: 2500, height: 1686 },
        selected: true,
        name: `${prefix}_${now.getMonth() + 1}_${now.getDate()}_${now.getHours()}`,
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
        console.error(`Failed to create richmenu for ${prefix}:`, createJson);
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
    if (!CHANNEL_ACCESS_TOKEN || !richMenuId) return;
    await fetch(`https://api.line.me/v2/bot/user/all/richmenu/${richMenuId}`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${CHANNEL_ACCESS_TOKEN}` }
    });
}

const isMain = process.argv[1] && path.resolve(process.argv[1]) === path.resolve(__filename);
if (isMain) {
    runCityWeatherBatch().catch(console.error);
}
