/**
 * =========================================================================
 * リッチメニュー・プリセット統合ランナー (ESM)
 * 使用法:
 *   node presets/runner.js mishima_weather
 *   node presets/runner.js template_custom
 * =========================================================================
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

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

export async function runPreset(presetName = 'mishima_weather', customOptions = {}) {
    console.log(`=== Executing Richmenu Preset: [${presetName}] ===`);

    const presetPath = path.join(__dirname, `${presetName}.js`);
    if (!fs.existsSync(presetPath)) {
        console.error(`Error: Preset "${presetName}" not found at ${presetPath}`);
        console.log('Available presets:', fs.readdirSync(__dirname).filter(f => f.endsWith('.js') && f !== 'runner.js'));
        return;
    }

    const presetModule = await import(pathToFileURL(presetPath).href);
    console.log(`Loaded preset: ${presetModule.name} - ${presetModule.description}`);

    // 1. LINEからベース元画像を取得（またはローカルキャッシュ）
    console.log('Fetching base menu image...');
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

    // 2. プリセットで画像と設定を生成
    console.log('Generating richmenu assets...');
    const result = await presetModule.generate(baseImgBuf, customOptions);

    const outPath = path.join(__dirname, '..', 'final_richmenu.png');
    fs.writeFileSync(outPath, result.imageBuffer);
    console.log(`Image saved to ${outPath}`);

    // 3. LINE APIが使える場合はリッチメニュー登録＆適用
    if (!CHANNEL_ACCESS_TOKEN) {
        console.log('CHANNEL_ACCESS_TOKEN is not set. Generated image only.');
        return { success: true, imagePath: outPath, generated: result };
    }

    console.log('Creating richmenu on LINE...');
    const menuBody = {
        size: { width: 2500, height: 1686 },
        selected: true,
        name: result.name,
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
        console.error('Failed to create richmenu on LINE:', createJson);
        return { success: false, error: createJson };
    }

    const richMenuId = createJson.richMenuId;
    console.log(`Created richMenuId: ${richMenuId}`);

    console.log('Uploading richmenu image...');
    await fetch(`https://api-data.line.me/v2/bot/richmenu/${richMenuId}/content`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${CHANNEL_ACCESS_TOKEN}`,
            'Content-Type': 'image/png'
        },
        body: result.imageBuffer
    });

    console.log('Setting as default richmenu...');
    await fetch(`https://api.line.me/v2/bot/user/all/richmenu/${richMenuId}`, {
        method: 'POST',
        headers: { 'Authorization': `Bearer ${CHANNEL_ACCESS_TOKEN}` }
    });

    console.log(`🎉 SUCCESS! Preset [${presetName}] applied with richMenuId: ${richMenuId}`);
    return { success: true, richMenuId, preset: presetName };
}

// CLI直接実行時
const isMain = process.argv[1] && path.resolve(process.argv[1]) === path.resolve(__filename);
if (isMain) {
    const presetArg = process.argv[2] || 'mishima_weather';
    runPreset(presetArg).catch(console.error);
}
