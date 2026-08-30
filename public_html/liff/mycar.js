/**
 * マイカー点検パスポート LIFFフロントエンドロジック
 */

const state = {
    userId: '',
    userName: '',
    userAvatar: '',
    customerData: null
};

const elements = {
    userAvatar: document.getElementById('userAvatar'),
    userNameText: document.getElementById('userNameText'),
    carModelDisplay: document.getElementById('carModelDisplay'),
    carNumDisplay: document.getElementById('carNumDisplay'),
    oilNextDateDisplay: document.getElementById('oilNextDateDisplay'),
    oilStatusBadge: document.getElementById('oilStatusBadge'),
    inspNextDateDisplay: document.getElementById('inspNextDateDisplay'),
    inspStatusBadge: document.getElementById('inspStatusBadge'),
    
    // 入力フォーム
    inputCarModel: document.getElementById('inputCarModel'),
    inputCarNumber: document.getElementById('inputCarNumber'),
    inputOilNextDate: document.getElementById('inputOilNextDate'),
    inputInspNextDate: document.getElementById('inputInspNextDate'),
    saveCustBtn: document.getElementById('saveCustBtn'),
    
    // 相談ボタン
    bookOilBtn: document.getElementById('bookOilBtn'),
    bookInspBtn: document.getElementById('bookInspBtn'),
    toast: document.getElementById('appToast')
};

document.addEventListener('DOMContentLoaded', async () => {
    await initLiff();
    initEventListeners();
    if (state.userId) {
        await fetchCustomerData();
    }
});

async function initLiff() {
    try {
        if (typeof liff !== 'undefined') {
            // await liff.init({ liffId: '2011335169-9x8ydjaV' });
            if (liff.isLoggedIn()) {
                const profile = await liff.getProfile();
                state.userId = profile.userId;
                state.userName = profile.displayName;
                state.userAvatar = profile.pictureUrl || '';

                elements.userNameText.textContent = state.userName + ' 様';
                if (state.userAvatar) {
                    elements.userAvatar.src = state.userAvatar;
                }
            }
        }
    } catch (e) {
        console.warn('LIFF init error / browser fallback:', e);
    }

    // ブラウザテスト用のフォールバック
    if (!state.userId) {
        const urlParams = new URLSearchParams(window.location.search);
        state.userId = urlParams.get('uid') || 'DEMO_USER_001';
        state.userName = urlParams.get('uname') || 'ゲストユーザー';
        elements.userNameText.textContent = state.userName + ' 様';
    }
}

function initEventListeners() {
    elements.saveCustBtn.addEventListener('click', async () => {
        await saveCustomerData();
    });

    elements.bookOilBtn.addEventListener('click', () => {
        const car = state.customerData?.car_model || elements.inputCarModel.value || '愛車';
        const date = state.customerData?.oil_next_date || elements.inputOilNextDate.value || '近日中';
        const msg = `【オイル交換の予約相談】\n愛車: ${car}\n次回予定日: ${date}\n\nオイル交換の来店予約・空き状況を相談したいです。`;
        sendLineChatMessage(msg);
    });

    elements.bookInspBtn.addEventListener('click', () => {
        const car = state.customerData?.car_model || elements.inputCarModel.value || '愛車';
        const date = state.customerData?.inspection_next_date || elements.inputInspNextDate.value || '未定';
        const msg = `【車検・定期点検の予約相談】\n愛車: ${car}\n車検満了日: ${date}\n\n車検のお見積もりや代車の手配について相談したいです。`;
        sendLineChatMessage(msg);
    });
}

async function fetchCustomerData() {
    try {
        const res = await fetch(`../api.php?action=get_customer&uid=${encodeURIComponent(state.userId)}`);
        const data = await res.json();
        if (data.success && data.customer) {
            state.customerData = data.customer;
            renderCustomerInfo(data.customer);
        }
    } catch (e) {
        console.warn('Fetch customer error:', e);
    }
}

function renderCustomerInfo(cust) {
    if (cust.car_model) {
        elements.carModelDisplay.textContent = cust.car_model;
        elements.inputCarModel.value = cust.car_model;
    }
    if (cust.car_number) {
        elements.carNumDisplay.textContent = `No. ${cust.car_number}`;
        elements.inputCarNumber.value = cust.car_number;
    }
    if (cust.oil_next_date) {
        elements.oilNextDateDisplay.textContent = cust.oil_next_date;
        elements.inputOilNextDate.value = cust.oil_next_date;
        updateBadge(elements.oilStatusBadge, cust.oil_next_date);
    } else {
        elements.oilStatusBadge.textContent = '未設定';
        elements.oilStatusBadge.className = 'maint-badge badge-warning';
    }

    if (cust.inspection_next_date) {
        elements.inspNextDateDisplay.textContent = cust.inspection_next_date;
        elements.inputInspNextDate.value = cust.inspection_next_date;
        updateBadge(elements.inspStatusBadge, cust.inspection_next_date);
    } else {
        elements.inspStatusBadge.textContent = '未設定';
        elements.inspStatusBadge.className = 'maint-badge badge-warning';
    }
}

function updateBadge(badgeEl, targetDateStr) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const target = new Date(targetDateStr);
    target.setHours(0, 0, 0, 0);

    const diffDays = Math.ceil((target - today) / (1000 * 60 * 60 * 24));

    if (diffDays < 0) {
        badgeEl.textContent = `期限切れ (${Math.abs(diffDays)}日前)`;
        badgeEl.className = 'maint-badge badge-danger';
    } else if (diffDays === 0) {
        badgeEl.textContent = '本日が予定日！';
        badgeEl.className = 'maint-badge badge-danger';
    } else if (diffDays <= 14) {
        badgeEl.textContent = `あと ${diffDays} 日`;
        badgeEl.className = 'maint-badge badge-warning';
    } else {
        badgeEl.textContent = `あと ${diffDays} 日`;
        badgeEl.className = 'maint-badge badge-safe';
    }
}

async function saveCustomerData() {
    const carModel = elements.inputCarModel.value.trim();
    const carNumber = elements.inputCarNumber.value.trim();
    const oilDate = elements.inputOilNextDate.value;
    const inspDate = elements.inputInspNextDate.value;

    if (!carModel) {
        showToast('愛車の車種名を入力してください');
        return;
    }

    const payload = new URLSearchParams({
        action: 'save_customer',
        uid: state.userId,
        uname: state.userName,
        car_model: carModel,
        car_number: carNumber,
        oil_next_date: oilDate,
        inspection_next_date: inspDate
    });

    try {
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        if (data.success) {
            showToast('✅ メンテナンス情報を保存しました！');
            await fetchCustomerData();
        } else {
            showToast(data.error || '保存に失敗しました');
        }
    } catch (e) {
        showToast('通信エラーが発生しました');
    }
}

function sendLineChatMessage(text) {
    if (typeof liff !== 'undefined' && liff.isInClient()) {
        liff.sendMessages([{ type: 'text', text: text }]).then(() => {
            showToast('✅ メッセージを送信しました！');
            setTimeout(() => { liff.closeWindow(); }, 1000);
        }).catch(() => {
            copyToClipboard(text);
        });
    } else {
        copyToClipboard(text);
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('📋 メッセージ文面をコピーしました！LINEトークに貼り付けて送信してください。');
    }).catch(() => {
        showToast('メッセージを作成しました。');
    });
}

function showToast(msg) {
    elements.toast.textContent = msg;
    elements.toast.classList.add('show');
    setTimeout(() => {
        elements.toast.classList.remove('show');
    }, 3500);
}
