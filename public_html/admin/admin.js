/**
 * アップファーレン 顧客メンテナンス管理ダッシュボード JS
 */

const state = {
    password: '',
    allCustomers: [],
    currentFilter: 'all',
    searchQuery: ''
};

const elements = {
    loginModal: document.getElementById('loginModal'),
    adminPasswordInput: document.getElementById('adminPasswordInput'),
    loginBtn: document.getElementById('loginBtn'),
    loginErrorMsg: document.getElementById('loginErrorMsg'),
    adminApp: document.getElementById('adminApp'),
    logoutBtn: document.getElementById('logoutBtn'),

    // 統計
    statTotalUsers: document.getElementById('statTotalUsers'),
    statOilSoon: document.getElementById('statOilSoon'),
    statPeriodicSoon: document.getElementById('statPeriodicSoon'),
    statInspSoon: document.getElementById('statInspSoon'),

    // ツールバー
    adminSearchInput: document.getElementById('adminSearchInput'),
    tabBtns: document.querySelectorAll('.tab-btn'),
    tabCountAll: document.getElementById('tabCountAll'),
    tabCountOil: document.getElementById('tabCountOil'),
    tabCountPeriodic: document.getElementById('tabCountPeriodic'),
    tabCountInsp: document.getElementById('tabCountInsp'),

    // テーブル
    customerTableBody: document.getElementById('customerTableBody'),
    emptyTablePlaceholder: document.getElementById('emptyTablePlaceholder'),

    // モーダル
    customerEditModal: document.getElementById('customerEditModal'),
    openAddCustomerModalBtn: document.getElementById('openAddCustomerModalBtn'),
    closeEditModalBtn: document.getElementById('closeEditModalBtn'),
    cancelEditBtn: document.getElementById('cancelEditBtn'),
    saveCustomerBtn: document.getElementById('saveCustomerBtn'),
    modalTitle: document.getElementById('modalTitle'),
    
    // フォーム
    editUserId: document.getElementById('editUserId'),
    editUserUid: document.getElementById('editUserUid'),
    editUserName: document.getElementById('editUserName'),
    editCarModel: document.getElementById('editCarModel'),
    editCarNumber: document.getElementById('editCarNumber'),
    editOilLastDate: document.getElementById('editOilLastDate'),
    editOilNextDate: document.getElementById('editOilNextDate'),
    editPeriodicNextDate: document.getElementById('editPeriodicNextDate'),
    editInspectionNextDate: document.getElementById('editInspectionNextDate'),
    editStaffMemo: document.getElementById('editStaffMemo'),

    toast: document.getElementById('adminToast')
};

document.addEventListener('DOMContentLoaded', () => {
    initAuth();
    initEventListeners();
});

function initAuth() {
    const savedPass = sessionStorage.getItem('admin_pass');
    if (savedPass) {
        state.password = savedPass;
        loadDashboard();
    } else {
        elements.loginModal.style.display = 'flex';
        elements.adminApp.style.display = 'none';
    }
}

function initEventListeners() {
    // ログイン
    elements.loginBtn.addEventListener('click', () => attemptLogin());
    elements.adminPasswordInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') attemptLogin();
    });

    // ログアウト
    elements.logoutBtn.addEventListener('click', () => {
        sessionStorage.removeItem('admin_pass');
        state.password = '';
        elements.adminApp.style.display = 'none';
        elements.loginModal.style.display = 'flex';
        elements.adminPasswordInput.value = '';
    });

    // 検索入力
    elements.adminSearchInput.addEventListener('input', (e) => {
        state.searchQuery = e.target.value.trim().toLowerCase();
        renderTable();
    });

    // フィルタータブ
    elements.tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            elements.tabBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            state.currentFilter = btn.getAttribute('data-filter');
            renderTable();
        });
    });

    // 統計カードクリックでもフィルター切り替え
    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('click', () => {
            const filter = card.dataset.filter;
            elements.tabBtns.forEach(b => {
                b.classList.toggle('active', b.dataset.filter === filter);
            });
            state.currentFilter = filter;
            renderTable();
        });
    });

    // モーダル開閉
    elements.openAddCustomerModalBtn.addEventListener('click', () => openEditModal(null));
    elements.closeEditModalBtn.addEventListener('click', () => closeEditModal());
    elements.cancelEditBtn.addEventListener('click', () => closeEditModal());
    elements.saveCustomerBtn.addEventListener('click', () => saveCustomer());
}

async function attemptLogin() {
    const pass = elements.adminPasswordInput.value.trim();
    if (!pass) {
        elements.loginErrorMsg.textContent = 'パスワードを入力してください';
        return;
    }

    elements.loginBtn.disabled = true;
    elements.loginBtn.textContent = '認証中...';
    elements.loginErrorMsg.textContent = '';

    try {
        const res = await fetch(`../api.php?action=admin_list_customers&password=${encodeURIComponent(pass)}`);
        const data = await res.json();

        if (data.success) {
            state.password = pass;
            sessionStorage.setItem('admin_pass', pass);
            elements.loginModal.style.display = 'none';
            elements.adminApp.style.display = 'block';
            state.allCustomers = data.customers || [];
            updateStats();
            renderTable();
        } else {
            elements.loginErrorMsg.textContent = data.error || 'パスワードが違います';
        }
    } catch (e) {
        elements.loginErrorMsg.textContent = 'サーバー通信エラーが発生しました';
    } finally {
        elements.loginBtn.disabled = false;
        elements.loginBtn.textContent = 'ログイン';
    }
}

async function loadDashboard() {
    elements.loginModal.style.display = 'none';
    elements.adminApp.style.display = 'block';
    await fetchCustomers();
}

async function fetchCustomers() {
    try {
        const res = await fetch(`../api.php?action=admin_list_customers&password=${encodeURIComponent(state.password)}`);
        const data = await res.json();
        if (data.success) {
            state.allCustomers = data.customers || [];
            updateStats();
            renderTable();
        } else if (res.status === 401) {
            sessionStorage.removeItem('admin_pass');
            elements.loginModal.style.display = 'flex';
            elements.adminApp.style.display = 'none';
        }
    } catch (e) {
        console.error('Fetch error:', e);
    }
}

function updateStats() {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const in30Days = new Date();
    in30Days.setDate(today.getDate() + 30);

    let oilSoonCount = 0;
    let periodicSoonCount = 0;
    let inspSoonCount = 0;

    state.allCustomers.forEach(c => {
        if (c.oil_next_date) {
            const d = new Date(c.oil_next_date);
            if (d <= in30Days) oilSoonCount++;
        }
        if (c.periodic_insp_next_date) {
            const d = new Date(c.periodic_insp_next_date);
            if (d <= in30Days) periodicSoonCount++;
        }
        if (c.inspection_next_date) {
            const d = new Date(c.inspection_next_date);
            if (d <= in30Days) inspSoonCount++;
        }
    });

    if (elements.statTotalUsers) elements.statTotalUsers.textContent = state.allCustomers.length;
    if (elements.statOilSoon) elements.statOilSoon.textContent = oilSoonCount;
    if (elements.statPeriodicSoon) elements.statPeriodicSoon.textContent = periodicSoonCount;
    if (elements.statInspSoon) elements.statInspSoon.textContent = inspSoonCount;

    if (elements.tabCountAll) elements.tabCountAll.textContent = state.allCustomers.length;
    if (elements.tabCountOil) elements.tabCountOil.textContent = oilSoonCount;
    if (elements.tabCountPeriodic) elements.tabCountPeriodic.textContent = periodicSoonCount;
    if (elements.tabCountInsp) elements.tabCountInsp.textContent = inspSoonCount;
}

function renderTable() {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const in30Days = new Date();
    in30Days.setDate(today.getDate() + 30);

    let filtered = state.allCustomers.filter(c => {
        // 検索フィルター
        if (state.searchQuery) {
            const s = state.searchQuery;
            const matchName = (c.user_name || '').toLowerCase().includes(s);
            const matchCar = (c.car_model || '').toLowerCase().includes(s);
            const matchNo = (c.car_number || '').toLowerCase().includes(s);
            const matchMemo = (c.staff_memo || '').toLowerCase().includes(s);
            if (!matchName && !matchCar && !matchNo && !matchMemo) return false;
        }

        // タブフィルター
        if (state.currentFilter === 'oil_soon') {
            if (!c.oil_next_date) return false;
            return new Date(c.oil_next_date) <= in30Days;
        }
        if (state.currentFilter === 'periodic_soon') {
            if (!c.periodic_insp_next_date) return false;
            return new Date(c.periodic_insp_next_date) <= in30Days;
        }
        if (state.currentFilter === 'inspection_soon') {
            if (!c.inspection_next_date) return false;
            return new Date(c.inspection_next_date) <= in30Days;
        }

        return true;
    });

    if (filtered.length === 0) {
        elements.customerTableBody.innerHTML = '';
        elements.emptyTablePlaceholder.style.display = 'block';
        return;
    }

    elements.emptyTablePlaceholder.style.display = 'none';

    elements.customerTableBody.innerHTML = filtered.map((c, idx) => {
        const oilBadge = getBadgeHtml(c.oil_next_date);
        const periodicBadge = getBadgeHtml(c.periodic_insp_next_date);
        const inspBadge = getBadgeHtml(c.inspection_next_date);
        const memo = c.staff_memo ? escapeHtml(c.staff_memo) : '<span style="color:#cbd5e1">-</span>';
        const updated = (c.updated_at || '').substring(0, 10);
        const carId = c.id || '';
        const userId = c.user_id || '';

        return `
            <tr data-index="${idx}">
                <td>
                    <div class="cust-name">${escapeHtml(c.user_name || '名前なし')}</div>
                    <div class="cust-uid">${escapeHtml(c.user_id || '')}</div>
                </td>
                <td>
                    <div class="car-tag">${escapeHtml(c.car_model || '-')}</div>
                    <div class="car-no">${escapeHtml(c.car_number || '')}</div>
                </td>
                <td>${oilBadge}</td>
                <td>${periodicBadge}</td>
                <td>${inspBadge}</td>
                <td style="max-width: 160px; font-size: 11px;">${memo}</td>
                <td style="font-size: 11px; color: #64748b;">${updated}</td>
                <td>
                    <div class="action-btns">
                        <button class="btn-remind-oil" data-action="remind-oil" data-idx="${idx}" title="オイル交換リマインドをLINE送信">
                            <i class="fa-solid fa-oil-can"></i> オイル
                        </button>
                        <button class="btn-remind-periodic" data-action="remind-periodic" data-idx="${idx}" title="12ヶ月点検リマインドをLINE送信">
                            <i class="fa-solid fa-clipboard-check"></i> 点検
                        </button>
                        <button class="btn-remind-insp" data-action="remind-insp" data-idx="${idx}" title="車検リマインドをLINE送信">
                            <i class="fa-solid fa-shield-halved"></i> 車検
                        </button>
                        <button class="btn-edit" data-action="edit" data-idx="${idx}" title="編集">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <button class="btn-delete" data-action="delete" data-idx="${idx}" title="削除">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    // 安全なイベントリスナー登録
    elements.customerTableBody.querySelectorAll('.action-btns button').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const action = btn.getAttribute('data-action');
            const idx = parseInt(btn.getAttribute('data-idx'), 10);
            const cust = filtered[idx];
            if (!cust) return;

            if (action === 'remind-oil') {
                sendManualReminder(cust.id, cust.user_id, 'oil', cust.user_name, cust.car_model);
            } else if (action === 'remind-periodic') {
                sendManualReminder(cust.id, cust.user_id, 'periodic', cust.user_name, cust.car_model);
            } else if (action === 'remind-insp') {
                sendManualReminder(cust.id, cust.user_id, 'inspection', cust.user_name, cust.car_model);
            } else if (action === 'edit') {
                openEditModal(cust);
            } else if (action === 'delete') {
                deleteCarRecord(cust);
            }
        });
    });
}

window.sendManualReminder = async function(carId, userId, type, userName, carModel) {
    if (!userId || !userId.startsWith('U')) {
        alert('この顧客は手動登録（LINE未連携）のため、LINEメッセージを送信できません。');
        return;
    }

    let typeLabel = '🛢 オイル交換リマインド';
    if (type === 'periodic') typeLabel = '📋 12ヶ月定期点検リマインド';
    if (type === 'inspection') typeLabel = '🚗 車検満了リマインド';

    if (!confirm(`【${userName || 'お客様'} 様 (${carModel || '愛車'})】へ\n「${typeLabel}」のLINEメッセージを今すぐ送信しますか？`)) {
        return;
    }

    try {
        showToast('LINEメッセージを送信中...');
        const payload = new URLSearchParams({
            action: 'admin_send_reminder',
            password: state.password,
            car_id: carId || '',
            uid: userId || '',
            type: type
        });
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        if (data.success) {
            showToast(`✅ ${userName || 'お客様'} 様へLINEリマインドを送信しました！`);
            await fetchCustomers();
        } else {
            alert(data.error || '送信に失敗しました');
        }
    } catch (e) {
        alert('通信エラーが発生しました');
    }
};

function getBadgeHtml(dateStr) {
    if (!dateStr) return '<span style="color:#94a3b8; font-size:11px;">未設定</span>';

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const target = new Date(dateStr);
    target.setHours(0, 0, 0, 0);

    const diffDays = Math.ceil((target - today) / (1000 * 60 * 60 * 24));

    if (diffDays < 0) {
        return `<span class="date-badge date-over">${dateStr} (期限切れ)</span>`;
    } else if (diffDays <= 7) {
        return `<span class="date-badge date-soon">${dateStr} (あと${diffDays}日)</span>`;
    } else if (diffDays <= 30) {
        return `<span class="date-badge date-soon">${dateStr} (あと${diffDays}日)</span>`;
    } else {
        return `<span class="date-badge date-ok">${dateStr}</span>`;
    }
}

let activeEditingCarId = null;

async function deleteCarRecord(cust) {
    if (!cust) return;
    const name = cust.user_name || '顧客';
    const car = cust.car_model || '愛車';

    if (!confirm(`【${name} 様】の愛車「${car}」のデータを削除しますか？\n（※この操作は取り消せません）`)) {
        return;
    }

    try {
        showToast('削除中...');
        const payload = new URLSearchParams({
            action: 'admin_delete_customer',
            password: state.password,
            car_id: cust.id || '',
            uid: cust.user_id || ''
        });
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        if (data.success) {
            showToast('✅ 削除しました');
            await fetchCustomers();
        } else {
            alert('⚠️ 削除に失敗しました: ' + (data.error || ''));
        }
    } catch (e) {
        alert('⚠️ 通信エラーが発生しました');
    }
}

function openEditModal(cust) {
    if (cust) {
        activeEditingCarId = cust.id;
        elements.modalTitle.textContent = `愛車・メンテナンス情報の編集: ${cust.car_model || ''} (${cust.user_name || ''})`;
        elements.editUserId.value = cust.id || '';
        elements.editUserUid.value = cust.user_id || '';
        elements.editUserName.value = cust.user_name || '';
        elements.editCarModel.value = cust.car_model || '';
        elements.editCarNumber.value = cust.car_number || '';
        elements.editOilLastDate.value = cust.oil_last_date || '';
        elements.editOilNextDate.value = cust.oil_next_date || '';
        elements.editPeriodicNextDate.value = cust.periodic_insp_next_date || '';
        elements.editInspectionNextDate.value = cust.inspection_next_date || '';
        elements.editStaffMemo.value = cust.staff_memo || '';
    } else {
        activeEditingCarId = null;
        elements.modalTitle.textContent = '新規顧客・愛車メンテナンス情報の登録';
        elements.editUserId.value = '';
        elements.editUserUid.value = '';
        elements.editUserName.value = '';
        elements.editCarModel.value = '';
        elements.editCarNumber.value = '';
        elements.editOilLastDate.value = '';
        elements.editOilNextDate.value = '';
        elements.editPeriodicNextDate.value = '';
        elements.editInspectionNextDate.value = '';
        elements.editStaffMemo.value = '';
    }

    elements.customerEditModal.classList.add('active');
}

function closeEditModal() {
    elements.customerEditModal.classList.remove('active');
}

async function saveCustomer() {
    const userName = elements.editUserName.value.trim();
    const carModel = elements.editCarModel.value.trim();

    if (!userName || !carModel) {
        alert('お名前と愛車の車種名は必須です');
        return;
    }

    const payload = new URLSearchParams({
        action: 'admin_save_customer',
        password: state.password,
        car_id: activeEditingCarId || '',
        uid: elements.editUserUid.value.trim() || elements.editUserId.value.trim(),
        uname: userName,
        car_model: carModel,
        car_number: elements.editCarNumber.value.trim(),
        oil_last_date: elements.editOilLastDate.value,
        oil_next_date: elements.editOilNextDate.value,
        periodic_insp_next_date: elements.editPeriodicNextDate.value,
        inspection_next_date: elements.editInspectionNextDate.value,
        staff_memo: elements.editStaffMemo.value.trim()
    });

    try {
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        if (data.success) {
            showToast('✅ 顧客メンテナンス情報を保存しました！');
            closeEditModal();
            await fetchCustomers();
        } else {
            alert(data.error || '保存に失敗しました');
        }
    } catch (e) {
        alert('通信エラーが発生しました');
    }
}

function showToast(msg) {
    elements.toast.textContent = msg;
    elements.toast.classList.add('show');
    setTimeout(() => {
        elements.toast.classList.remove('show');
    }, 3000);
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
