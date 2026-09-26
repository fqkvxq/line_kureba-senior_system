import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const accountsJsonPath = path.join(__dirname, 'public_html', 'data', 'line_accounts.json');
let token = '';
if (fs.existsSync(accountsJsonPath)) {
    const d = JSON.parse(fs.readFileSync(accountsJsonPath, 'utf8'));
    token = d.accounts?.find(a => a.id === 'senior')?.channel_access_token;
}

const userId = 'U38c887032d23d83bcc44ae08c1f987a2'; // かわいたくや様

const payload = {
    to: userId,
    messages: [
        {
            type: 'text',
            text: '📱 メニュー切り替え\n下のボタンから表示したいメニューをお選びください😊',
            quickReply: {
                items: [
                    {
                        type: 'action',
                        action: {
                            type: 'postback',
                            label: '🌤️ 天気メニュー',
                            data: 'action=switch_weather_mode'
                        }
                    },
                    {
                        type: 'action',
                        action: {
                            type: 'postback',
                            label: '🔮 占いメニュー',
                            data: 'action=switch_fortune_mode'
                        }
                    },
                    {
                        type: 'action',
                        action: {
                            type: 'postback',
                            label: '📱 通常メニュー',
                            data: 'action=switch_default_mode'
                        }
                    },
                    {
                        type: 'action',
                        action: {
                            type: 'postback',
                            label: '♈ 星座設定',
                            data: 'action=ask_zodiac_selection'
                        }
                    }
                ]
            }
        }
    ]
};

async function send() {
    console.log('Sending quick reply message to:', userId);
    const res = await fetch('https://api.line.me/v2/bot/message/push', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    });
    const resJson = await res.json();
    console.log('Response:', res.status, resJson);
}

send().catch(console.error);
