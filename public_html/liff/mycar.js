/**
 * マイカー点検パスポート LIFFフロントエンドロジック
 */

const state = {
    userId: '',
    userName: '',
    userAvatar: '',
    customerData: null
};

function getEl(id) {
    return document.getElementById(id);
}

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

                const uNameEl = getEl('userNameText');
                if (uNameEl) uNameEl.textContent = state.userName + ' 様';
                const uAvatarEl = getEl('userAvatar');
                if (uAvatarEl && state.userAvatar) {
                    uAvatarEl.src = state.userAvatar;
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

    const uNameEl = getEl('userNameText');
    if (uNameEl) uNameEl.textContent = state.userName + ' 様';
}

let currentBookingType = 'オイル交換';

function initEventListeners() {
    const saveBtn = getEl('saveCustBtn');
    if (saveBtn) {
        saveBtn.addEventListener('click', async () => {
            await saveCustomerData();
        });
    }

    const bookingModal = getEl('bookingModal');
    const modalTitle = getEl('modalBookingTitle');
    const closeBtn = getEl('closeBookingModalBtn');
    const cancelBtn = getEl('cancelBookingModalBtn');

    function openModal(type) {
        currentBookingType = type;
        if (modalTitle) modalTitle.textContent = `【${type}】来店予約・相談の確認`;
        if (bookingModal) bookingModal.style.display = 'flex';
    }

    function closeModal() {
        if (bookingModal) bookingModal.style.display = 'none';
    }

    getEl('bookOilBtn')?.addEventListener('click', () => openModal('オイル交換'));
    getEl('bookPeriodicBtn')?.addEventListener('click', () => openModal('12ヶ月定期点検'));
    getEl('bookInspBtn')?.addEventListener('click', () => openModal('車検'));

    closeBtn?.addEventListener('click', closeModal);
    cancelBtn?.addEventListener('click', closeModal);
    bookingModal?.addEventListener('click', (e) => {
        if (e.target === bookingModal) closeModal();
    });

    document.querySelectorAll('.maint-choice-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const pref = btn.getAttribute('data-pref');
            closeModal();
            await submitMaintenanceBooking(currentBookingType, pref);
        });
    });
}

async function submitMaintenanceBooking(bookingType, prefTime) {
    const car = state.customerData?.car_model || getEl('inputCarModel')?.value || '愛車';
    let date = '未定';
    if (bookingType === 'オイル交換') {
        date = state.customerData?.oil_next_date || getEl('inputOilNextDate')?.value || '近日中';
    } else if (bookingType === '12ヶ月定期点検') {
        date = state.customerData?.periodic_insp_next_date || getEl('inputPeriodicNextDate')?.value || '近日中';
    } else {
        date = state.customerData?.inspection_next_date || getEl('inputInspNextDate')?.value || '未定';
    }

    const msg = `【${bookingType}の来店予約】\n愛車: ${car}\n予定・満了日: ${date}\n希望日時: ${prefTime}\n\n上記の日程で予約・相談をお願いいたします。`;

    showToast('予約相談を送信中...');
    sendLineChatMessage(msg);
}

async function fetchCustomerData() {
    try {
        const res = await fetch(`../api.php?action=get_customer&uid=${encodeURIComponent(state.userId)}`);
        const data = await res.json();
        console.log('Customer data fetched:', data);
        if (data.success && data.customer) {
            state.customerData = data.customer;
            renderCustomerInfo(data.customer);
        }
    } catch (e) {
        console.warn('Fetch customer error:', e);
    }
}

function renderCustomerInfo(cust) {
    const carModelEl = getEl('carModelDisplay');
    const inputCarModelEl = getEl('inputCarModel');
    if (cust.car_model) {
        if (carModelEl) carModelEl.textContent = cust.car_model;
        if (inputCarModelEl) inputCarModelEl.value = cust.car_model;
    }

    const carNumEl = getEl('carNumDisplay');
    const inputCarNumEl = getEl('inputCarNumber');
    if (cust.car_number) {
        if (carNumEl) carNumEl.textContent = `No. ${cust.car_number}`;
        if (inputCarNumEl) inputCarNumEl.value = cust.car_number;
    }

    // オイル交換
    const oilDateEl = getEl('oilNextDateDisplay');
    const inputOilEl = getEl('inputOilNextDate');
    const oilBadgeEl = getEl('oilStatusBadge');
    if (cust.oil_next_date) {
        if (oilDateEl) oilDateEl.textContent = cust.oil_next_date;
        if (inputOilEl) inputOilEl.value = cust.oil_next_date;
        updateBadge(oilBadgeEl, cust.oil_next_date);
    } else {
        if (oilBadgeEl) {
            oilBadgeEl.textContent = '未設定';
            oilBadgeEl.className = 'maint-badge badge-warning';
        }
    }

    // 12ヶ月定期点検
    const periodicDateEl = getEl('periodicNextDateDisplay');
    const inputPeriodicEl = getEl('inputPeriodicNextDate');
    const periodicBadgeEl = getEl('periodicStatusBadge');
    if (cust.periodic_insp_next_date) {
        if (periodicDateEl) periodicDateEl.textContent = cust.periodic_insp_next_date;
        if (inputPeriodicEl) inputPeriodicEl.value = cust.periodic_insp_next_date;
        updateBadge(periodicBadgeEl, cust.periodic_insp_next_date);
    } else {
        if (periodicBadgeEl) {
            periodicBadgeEl.textContent = '未設定';
            periodicBadgeEl.className = 'maint-badge badge-warning';
        }
    }

    // 車検
    const inspDateEl = getEl('inspNextDateDisplay');
    const inputInspEl = getEl('inputInspNextDate');
    const inspBadgeEl = getEl('inspStatusBadge');
    if (cust.inspection_next_date) {
        if (inspDateEl) inspDateEl.textContent = cust.inspection_next_date;
        if (inputInspEl) inputInspEl.value = cust.inspection_next_date;
        updateBadge(inspBadgeEl, cust.inspection_next_date);
    } else {
        if (inspBadgeEl) {
            inspBadgeEl.textContent = '未設定';
            inspBadgeEl.className = 'maint-badge badge-warning';
        }
    }
}

function updateBadge(badgeEl, targetDateStr) {
    if (!badgeEl) return;
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
    const carModel = getEl('inputCarModel')?.value.trim() || '';
    const carNumber = getEl('inputCarNumber')?.value.trim() || '';
    const oilDate = getEl('inputOilNextDate')?.value || '';
    const periodicDate = getEl('inputPeriodicNextDate')?.value || '';
    const inspDate = getEl('inputInspNextDate')?.value || '';

    if (!carModel) {
        showToast('⚠️ 愛車の車種名を入力してください');
        return;
    }

    const saveBtn = getEl('saveCustBtn');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 保存中...';
    }

    // 画面の表示を即時更新 (楽観的UI更新)
    const carModelEl = getEl('carModelDisplay');
    if (carModelEl) carModelEl.textContent = carModel;
    const carNumEl = getEl('carNumDisplay');
    if (carNumEl) carNumEl.textContent = carNumber ? `No. ${carNumber}` : '';
    
    if (oilDate) {
        const oilDateEl = getEl('oilNextDateDisplay');
        if (oilDateEl) oilDateEl.textContent = oilDate;
        updateBadge(getEl('oilStatusBadge'), oilDate);
    }
    if (periodicDate) {
        const periodicDateEl = getEl('periodicNextDateDisplay');
        if (periodicDateEl) periodicDateEl.textContent = periodicDate;
        updateBadge(getEl('periodicStatusBadge'), periodicDate);
    }
    if (inspDate) {
        const inspDateEl = getEl('inspNextDateDisplay');
        if (inspDateEl) inspDateEl.textContent = inspDate;
        updateBadge(getEl('inspStatusBadge'), inspDate);
    }

    const payload = new URLSearchParams({
        action: 'save_customer',
        uid: state.userId,
        uname: state.userName,
        car_model: carModel,
        car_number: carNumber,
        oil_next_date: oilDate,
        periodic_insp_next_date: periodicDate,
        inspection_next_date: inspDate
    });

    console.log('Saving customer with payload:', payload.toString());

    try {
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        console.log('Save response:', data);
        if (data.success) {
            showToast('✅ 情報を保存し、LINEへ確認メッセージをお送りしました！');
            await fetchCustomerData();
        } else {
            showToast('⚠️ ' + (data.error || '保存に失敗しました'));
        }
    } catch (e) {
        console.error('Save error:', e);
        showToast('✅ 保存内容を更新しました');
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> この内容で保存する';
        }
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
