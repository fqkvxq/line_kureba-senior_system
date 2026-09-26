import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { ApiClient } from './dist/api-client.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// 1. LINEトークン取得
const accountsJson = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'public_html', 'data', 'line_accounts.json'), 'utf8'));
const token = accountsJson.accounts[0].channel_access_token;

// 2. お天気サマリ取得
const summary = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'public_html', 'data', 'city_weather_summary.json'), 'utf8'));

const quickReplyItems = [
    {
        type: 'action',
        action: {
            type: 'postback',
            label: '🔮 占いメニュー',
            data: 'action=switch_fortune_mode'
        }
    }
];

for (const [k, v] of Object.entries(summary)) {
    quickReplyItems.push({
        type: 'action',
        action: {
            type: 'postback',
            label: v.label,
            data: 'action=set_city_weather&city=' + k + '&name=' + encodeURIComponent(v.name)
        }
    });
}

const messageText = `こんばんは🌃

最近はすっきりしないお天気が続いていますね☔️
そこで、LINEでいつでもリアルタイムの天気が確認できる【天気メニュー】と、毎日の運勢がわかる【占いメニュー】を新しく作ってみました✨

画面下のメニューやボタンから簡単に切り替えてお使いいただけますので、まずはボタンをポチポチ押してみてくださいね！🌤️🔮

便利と感じたらぜひ教えてください😊
改善点や「こんな機能があればいいな」というのがあれば、それもまたお気軽に教えてくださいね✨`;

const messagePayload = {
    type: 'text',
    text: messageText,
    quickReply: {
        items: quickReplyItems
    }
};

async function main() {
    console.log('================================================================');
    console.log('🚀 [LINE一斉送信バッチ開始] 反応・アクティブ優先 上位740名配信');
    console.log('================================================================');

    // 1. 顧客一覧の取得 (ApiClient経由)
    const res = await ApiClient.call('admin_list_customers', { filter: 'active' }, { method: 'GET' });
    const customers = res.data.customers || [];
    console.log(`\n📋 取得した有効顧客数: ${customers.length} 名`);

    // 2. 有効なLINEユーザー（Uから始まる、ブロックされていない）を抽出
    const validTargets = customers.filter(c => {
        const uid = c.user_id ? c.user_id.trim() : '';
        return uid.startsWith('U') && !c.is_blocked;
    });
    console.log(`🔍 送信対象候補者数: ${validTargets.length} 名`);

    // 3. 優先順位スコアリング:
    //  - 反応実績（last_interaction_at）がある人を最優先（日時が新しい順）
    //  - メニュー操作・星座選択等の履歴がある人
    //  - 更新日時（updated_at）が新しい人
    //  - 登録日時（created_at）が新しい人
    validTargets.forEach(c => {
        let score = 0;
        if (c.last_interaction_at) {
            // 反応あり: 100,000,000 + 最終反応タイムスタンプ
            const interactTime = new Date(c.last_interaction_at).getTime() / 1000;
            score += 100000000 + (interactTime || 0);
        } else if (c.custom_menu_text || c.zodiac_sign) {
            // リッチメニュー操作歴あり: 50,000,000
            score += 50000000;
        }
        const updateTime = c.updated_at ? new Date(c.updated_at).getTime() / 1000 : 0;
        const createTime = c.created_at ? new Date(c.created_at).getTime() / 1000 : 0;
        c._score = score + (updateTime * 0.1) + (createTime * 0.001);
    });

    validTargets.sort((a, b) => b._score - a._score);

    // 4. 上位740名を厳選
    const targetLimit = 740;
    const selectedTargets = validTargets.slice(0, targetLimit);
    const selectedUids = selectedTargets.map(c => c.user_id.trim());

    console.log(`\n🎯 送信対象として厳選された人数: ${selectedUids.length} 名 (目標: ${targetLimit}名)`);
    
    console.log('\n---【優先度上位10名プレビュー】---');
    selectedTargets.slice(0, 10).forEach((c, idx) => {
        console.log(` ${idx + 1}. ${c.user_name || '名称未設定'} (${c.user_id}) | 最終反応: ${c.last_interaction_at || 'なし'} | 更新: ${c.updated_at || 'なし'}`);
    });

    console.log('\n---【ボーダーライン付近 (735〜740位) プレビュー】---');
    selectedTargets.slice(734, 740).forEach((c, idx) => {
        console.log(` ${735 + idx}. ${c.user_name || '名称未設定'} (${c.user_id}) | 最終反応: ${c.last_interaction_at || 'なし'} | 更新: ${c.updated_at || 'なし'}`);
    });

    // 5. LINE Multicast API は1リクエスト最大500名まで
    const batchSize = 500;
    const chunks = [];
    for (let i = 0; i < selectedUids.length; i += batchSize) {
        chunks.push(selectedUids.slice(i, i + batchSize));
    }

    console.log(`\n📦 送信バッチ分割: 全 ${chunks.length} バッチ (第1バッチ: ${chunks[0].length}名, 第2バッチ: ${chunks[1] ? chunks[1].length : 0}名)`);

    let totalSent = 0;
    for (let i = 0; i < chunks.length; i++) {
        const chunkUids = chunks[i];
        console.log(`\n⏳ [バッチ ${i + 1}/${chunks.length}] ${chunkUids.length} 名へ送信実行中...`);

        const res = await fetch('https://api.line.me/v2/bot/message/multicast', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                to: chunkUids,
                messages: [messagePayload]
            })
        });

        if (res.ok) {
            console.log(` ✅ バッチ ${i + 1} 送信完了！ (${chunkUids.length}名)`);
            totalSent += chunkUids.length;
        } else {
            const errText = await res.text();
            console.error(` ❌ バッチ ${i + 1} 送信失敗:`, res.status, errText);
            throw new Error(`Multicast batch ${i + 1} failed: ${errText}`);
        }

        if (i < chunks.length - 1) {
            console.log(' ⏸️ 待機中 (1.5秒)...');
            await new Promise(resolve => setTimeout(resolve, 1500));
        }
    }

    console.log(`\n🎉 一斉送信がすべて正常に完了しました！ 送信成功合計: ${totalSent} 名`);

    // 6. 送信後の残通数を確認
    try {
        const quotaRes = await fetch('https://api.line.me/v2/bot/message/quota', { headers: { 'Authorization': `Bearer ${token}` } });
        const consumRes = await fetch('https://api.line.me/v2/bot/message/quota/consumption', { headers: { 'Authorization': `Bearer ${token}` } });
        const quota = await quotaRes.json();
        const consum = await consumRes.json();
        const remaining = quota.value - consum.totalUsage;
        console.log(`\n📊 【最新の通数使用状況】:`);
        console.log(`   ・月間上限: ${quota.value} 通`);
        console.log(`   ・利用合計: ${consum.totalUsage} 通`);
        console.log(`   ・残り枠  : ${remaining} 通 (予約リマインド等の自動枠として確保済み)`);
    } catch (e) {
        console.log('Quota check error:', e.message);
    }
}

main().catch(err => {
    console.error('一斉送信処理でエラーが発生しました:', err);
    process.exit(1);
});
