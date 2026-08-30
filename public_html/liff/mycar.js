/**
 * マイカー点検パスポート LIFFフロントエンドロジック (複数台対応)
 */

const state = {
    userId: '',
    userName: '',
    userAvatar: '',
    cars: [],
    activeCarIndex: 0
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

    const deleteBtn = getEl('deleteCarBtn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', async () => {
            await deleteActiveCar();
        });
    }

    const initLinkBtn = getEl('initLinkBtn');
    if (initLinkBtn) {
        initLinkBtn.addEventListener('click', async () => {
            await triggerInitialLink();
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

async function triggerInitialLink() {
    const btn = getEl('initLinkBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 連携中...';
    }

    try {
        const payload = new URLSearchParams({
            action: 'init_customer_link',
            uid: state.userId,
            uname: state.userName
        });
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        if (data.success) {
            showToast('✅ 店舗との連携が完了しました！');
            await fetchCustomerData();
        } else {
            showToast('⚠️ 連携に失敗しました');
        }
    } catch (e) {
        showToast('⚠️ 通信エラーが発生しました');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-link"></i> 店舗と連携して手帳を発行する';
        }
    }
}

async function submitMaintenanceBooking(bookingType, prefTime) {
    const currentCar = getCurrentActiveCar();
    const car = currentCar?.car_model || getEl('inputCarModel')?.value || '愛車';
    let date = '未定';
    if (bookingType === 'オイル交換') {
        date = currentCar?.oil_next_date || getEl('inputOilNextDate')?.value || '近日中';
    } else if (bookingType === '12ヶ月定期点検') {
        date = currentCar?.periodic_insp_next_date || getEl('inputPeriodicNextDate')?.value || '近日中';
    } else {
        date = currentCar?.inspection_next_date || getEl('inputInspNextDate')?.value || '未定';
    }

    const msg = `【${bookingType}の来店予約】\n愛車: ${car}\n予定・満了日: ${date}\n希望日時: ${prefTime}\n\n上記の日程で予約・相談をお願いいたします。`;

    showToast('予約相談を送信中...');
    sendLineChatMessage(msg);
}

function getCurrentActiveCar() {
    if (state.cars && state.cars.length > state.activeCarIndex) {
        return state.cars[state.activeCarIndex];
    }
    return null;
}

async function fetchCustomerData() {
    try {
        const res = await fetch(`../api.php?action=get_customer&uid=${encodeURIComponent(state.userId)}`);
        const data = await res.json();
        console.log('Customer data fetched:', data);
        if (data.success) {
            state.cars = data.cars || [];
            
            const linkCard = getEl('initLinkCard');
            if (state.cars.length === 0) {
                if (linkCard) linkCard.style.display = 'block';
                state.activeCarIndex = -1;
                renderCarTabs();
                showNewCarForm();
            } else {
                if (linkCard) linkCard.style.display = 'none';
                if (state.activeCarIndex >= state.cars.length || state.activeCarIndex < 0) {
                    state.activeCarIndex = 0;
                }
                renderCarTabs();
                renderCarInfo(state.cars[state.activeCarIndex]);
            }
        }
    } catch (e) {
        console.warn('Fetch customer error:', e);
    }
}

function renderCarTabs() {
    const container = getEl('carTabsContainer');
    if (!container) return;

    container.innerHTML = '';

    state.cars.forEach((car, idx) => {
        const btn = document.createElement('button');
        btn.className = `car-tab-item ${idx === state.activeCarIndex ? 'active' : ''}`;
        const carTitle = car.car_model || `愛車 ${idx + 1}`;
        btn.innerHTML = `<i class="fa-solid fa-car-side"></i> <span>${escapeHtml(carTitle)}</span>`;
        btn.addEventListener('click', () => {
            state.activeCarIndex = idx;
            renderCarTabs();
            renderCarInfo(state.cars[idx]);
        });
        container.appendChild(btn);
    });

    // 「+ 愛車を追加」ボタン
    const addBtn = document.createElement('button');
    addBtn.className = 'car-tab-add';
    addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> 愛車を追加';
    addBtn.addEventListener('click', () => {
        showNewCarForm();
    });
    container.appendChild(addBtn);
}

function showNewCarForm() {
    state.activeCarIndex = -1; // 新規追加中モード
    
    // タブのactive解除
    document.querySelectorAll('.car-tab-item').forEach(el => el.classList.remove('active'));

    getEl('currentCarId').value = '';
    getEl('inputCarModel').value = '';
    getEl('inputCarNumber').value = '';
    getEl('inputOilNextDate').value = '';
    getEl('inputPeriodicNextDate').value = '';
    getEl('inputInspNextDate').value = '';

    const carModelEl = getEl('carModelDisplay');
    if (carModelEl) carModelEl.textContent = '新規登録の愛車';
    const carNumEl = getEl('carNumDisplay');
    if (carNumEl) carNumEl.textContent = '--';

    // バッジをリセット
    resetBadge(getEl('oilStatusBadge'), getEl('oilNextDateDisplay'));
    resetBadge(getEl('periodicStatusBadge'), getEl('periodicNextDateDisplay'));
    resetBadge(getEl('inspStatusBadge'), getEl('inspNextDateDisplay'));

    // 削除ボタン非表示
    const deleteBtn = getEl('deleteCarBtn');
    if (deleteBtn) deleteBtn.style.display = 'none';

    // フォーカス
    getEl('inputCarModel')?.focus();
    showToast('🚗 新しい愛車の情報を入力してください');
}

function resetBadge(badgeEl, displayEl) {
    if (badgeEl) {
        badgeEl.textContent = '未設定';
        badgeEl.className = 'maint-badge badge-warning';
    }
    if (displayEl) {
        displayEl.textContent = '未設定';
    }
}

function renderCarInfo(car) {
    if (!car) return;

    getEl('currentCarId').value = car.id || '';
    
    const carModelEl = getEl('carModelDisplay');
    const inputCarModelEl = getEl('inputCarModel');
    if (carModelEl) carModelEl.textContent = car.car_model || '未設定';
    if (inputCarModelEl) inputCarModelEl.value = car.car_model || '';

    const carNumEl = getEl('carNumDisplay');
    const inputCarNumEl = getEl('inputCarNumber');
    if (carNumEl) carNumEl.textContent = car.car_number ? `No. ${car.car_number}` : '--';
    if (inputCarNumEl) inputCarNumEl.value = car.car_number || '';

    // オイル交換
    const oilDateEl = getEl('oilNextDateDisplay');
    const inputOilEl = getEl('inputOilNextDate');
    const oilBadgeEl = getEl('oilStatusBadge');
    if (car.oil_next_date) {
        if (oilDateEl) oilDateEl.textContent = car.oil_next_date;
        if (inputOilEl) inputOilEl.value = car.oil_next_date;
        updateBadge(oilBadgeEl, car.oil_next_date);
    } else {
        resetBadge(oilBadgeEl, oilDateEl);
        if (inputOilEl) inputOilEl.value = '';
    }

    // 12ヶ月定期点検
    const periodicDateEl = getEl('periodicNextDateDisplay');
    const inputPeriodicEl = getEl('inputPeriodicNextDate');
    const periodicBadgeEl = getEl('periodicStatusBadge');
    if (car.periodic_insp_next_date) {
        if (periodicDateEl) periodicDateEl.textContent = car.periodic_insp_next_date;
        if (inputPeriodicEl) inputPeriodicEl.value = car.periodic_insp_next_date;
        updateBadge(periodicBadgeEl, car.periodic_insp_next_date);
    } else {
        resetBadge(periodicBadgeEl, periodicDateEl);
        if (inputPeriodicEl) inputPeriodicEl.value = '';
    }

    // 車検
    const inspDateEl = getEl('inspNextDateDisplay');
    const inputInspEl = getEl('inputInspNextDate');
    const inspBadgeEl = getEl('inspStatusBadge');
    if (car.inspection_next_date) {
        if (inspDateEl) inspDateEl.textContent = car.inspection_next_date;
        if (inputInspEl) inputInspEl.value = car.inspection_next_date;
        updateBadge(inspBadgeEl, car.inspection_next_date);
    } else {
        resetBadge(inspBadgeEl, inspDateEl);
        if (inputInspEl) inputInspEl.value = '';
    }

    // 複数台ある場合は削除ボタンを表示
    const deleteBtn = getEl('deleteCarBtn');
    if (deleteBtn) {
        deleteBtn.style.display = (state.cars.length > 1) ? 'flex' : 'none';
    }
}

function updateBadge(badgeEl, targetDateStr) {
    if (!badgeEl || !targetDateStr) return;
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
    const carId = getEl('currentCarId')?.value || '';
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

    const payload = new URLSearchParams({
        action: 'save_customer',
        car_id: carId,
        uid: state.userId,
        uname: state.userName,
        car_model: carModel,
        car_number: carNumber,
        oil_next_date: oilDate,
        periodic_insp_next_date: periodicDate,
        inspection_next_date: inspDate
    });

    console.log('Saving car with payload:', payload.toString());

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

async function deleteActiveCar() {
    const car = getCurrentActiveCar();
    if (!car || !car.id) return;

    if (!confirm(`愛車「${car.car_model || '選択中の車両'}」を削除してもよろしいですか？`)) {
        return;
    }

    const payload = new URLSearchParams({
        action: 'delete_customer_car',
        car_id: car.id,
        uid: state.userId
    });

    try {
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        if (data.success) {
            showToast('🗑️ 愛車を削除しました');
            state.activeCarIndex = 0;
            await fetchCustomerData();
        } else {
            showToast('⚠️ ' + (data.error || '削除に失敗しました'));
        }
    } catch (e) {
        showToast('⚠️ 通信エラーが発生しました');
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
    const toast = getEl('appToast');
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3500);
}

function escapeHtml(str) {
    return (str || '').replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    })[m]);
}
