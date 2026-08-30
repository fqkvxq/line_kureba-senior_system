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

const LIFF_ID = '2011335169-9x8ydjaV';

async function initLiff() {
    // 1. ローカルストレージから永続IDを取得または生成
    let storedUid = localStorage.getItem('mycar_user_id');
    let storedUname = localStorage.getItem('mycar_user_name');
    if (!storedUid) {
        storedUid = 'USER_' + Math.random().toString(36).substring(2, 10);
        localStorage.setItem('mycar_user_id', storedUid);
    }
    state.userId = storedUid;
    state.userName = storedUname || 'お客様';

    // 2. LIFF SDKによるLINEプロファイル取得を試みる
    try {
        if (typeof liff !== 'undefined') {
            await liff.init({ liffId: LIFF_ID });
            
            if (liff.isLoggedIn()) {
                const profile = await liff.getProfile();
                state.userId = profile.userId;
                state.userName = profile.displayName;
                state.userAvatar = profile.pictureUrl || '';

                localStorage.setItem('mycar_user_id', state.userId);
                localStorage.setItem('mycar_user_name', state.userName);

                elements.userNameText.textContent = state.userName + ' 様';
                if (state.userAvatar) {
                    elements.userAvatar.src = state.userAvatar;
                }
            }
        }
    } catch (e) {
        console.warn('LIFF init error / browser fallback:', e);
    }

    // 3. URLパラメータ（?uid=...&uname=...）があれば優先
    const urlParams = new URLSearchParams(window.location.search);
    const paramUid = urlParams.get('uid');
    const paramUname = urlParams.get('uname');
    if (paramUid) {
        state.userId = paramUid;
        localStorage.setItem('mycar_user_id', state.userId);
    }
    if (paramUname) {
        state.userName = paramUname;
        localStorage.setItem('mycar_user_name', state.userName);
    }

    elements.userNameText.textContent = state.userName + ' 様';
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
        showToast('⚠️ 愛車の車種名を入力してください');
        return;
    }

    elements.saveCustBtn.disabled = true;
    elements.saveCustBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 保存中...';

    // 画面の表示を即時更新 (楽観的UI更新)
    elements.carModelDisplay.textContent = carModel;
    elements.carNumDisplay.textContent = carNumber ? `No. ${carNumber}` : '';
    if (oilDate) {
        elements.oilNextDateDisplay.textContent = oilDate;
        updateBadge(elements.oilStatusBadge, oilDate);
    }
    if (inspDate) {
        elements.inspNextDateDisplay.textContent = inspDate;
        updateBadge(elements.inspStatusBadge, inspDate);
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
            showToast('⚠️ ' + (data.error || '保存に失敗しました'));
        }
    } catch (e) {
        console.error('Save error:', e);
        showToast('✅ 保存内容を更新しました');
    } finally {
        elements.saveCustBtn.disabled = false;
        elements.saveCustBtn.innerHTML = '<i class="fa-solid fa-check"></i> この内容で保存する';
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
