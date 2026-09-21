/**
 * LINE受講生・カルテ管理システム Service Worker
 * バックグラウンド WebPush 通知の受信 & クリック時のチャットモーダル直接起動
 */

const SW_VERSION = '20260921_webpush_v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

// バックグラウンド Push 通知の受信ハンドラー
self.addEventListener('push', (event) => {
    let payload = {
        title: '🔔 新着LINEメッセージ',
        body: '受講生から新しいメッセージが届きました',
        icon: 'https://scdn.line-apps.com/n/channel_devcenter/img/fx/linecorp_code_withborder.png',
        badge: 'https://scdn.line-apps.com/n/channel_devcenter/img/fx/linecorp_code_withborder.png',
        data: {
            url: 'admin/index.html',
            user_id: '',
            user_name: '',
            account: 'senior'
        }
    };

    if (event.data) {
        try {
            const data = event.data.json();
            payload = { ...payload, ...data };
        } catch (e) {
            payload.body = event.data.text() || payload.body;
        }
    }

    const title = payload.title || '🔔 新着LINEメッセージ';
    const options = {
        body: payload.body,
        icon: payload.icon || 'https://scdn.line-apps.com/n/channel_devcenter/img/fx/linecorp_code_withborder.png',
        badge: payload.badge || payload.icon,
        image: payload.image || undefined,
        data: payload.data || {},
        tag: 'line-msg-' + (payload.data && payload.data.user_id ? payload.data.user_id : Date.now()),
        renotify: true,
        vibrate: [200, 100, 200, 100, 200],
        requireInteraction: false,
        actions: [
            { action: 'open_chat', title: '💬 チャットを開く' },
            { action: 'dismiss', title: '閉じる' }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

// 通知クリック時のハンドラー
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    if (event.action === 'dismiss') {
        return;
    }

    const notifData = event.notification.data || {};
    const userId = notifData.user_id || '';
    const account = notifData.account || '';

    let targetUrl = 'admin/index.html';
    if (userId) {
        targetUrl += '?open_chat=' + encodeURIComponent(userId);
        if (account) {
            targetUrl += '&account=' + encodeURIComponent(account);
        }
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // 既存の管理画面タブが存在するか探す
            for (const client of clientList) {
                if (client.url.includes('/admin/') && 'focus' in client) {
                    client.postMessage({
                        type: 'OPEN_CHAT_BY_PUSH',
                        user_id: userId,
                        account: account
                    });
                    return client.focus();
                }
            }
            // 存在しない場合は新規タブで開く
            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }
        })
    );
});
