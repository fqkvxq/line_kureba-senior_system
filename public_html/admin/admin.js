/**
 * アップファーム 顧客メンテナンス管理ダッシュボード JS
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
    statInspSoon: document.getElementById('statInspSoon'),

    // ツールバー
    adminSearchInput: document.getElementById('adminSearchInput'),
    tabBtns: document.querySelectorAll('.tab-btn'),
    tabCountAll: document.getElementById('tabCountAll'),
    tabCountOil: document.getElementById('tabCountOil'),
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
            state.currentFilter = btn.dataset.filter;
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

    // 新規登録モーダル開く
    elements.openAddCustomerModalBtn.addEventListener('click', () => {
        openEditModal(null);
    });

    // モーダル閉じる
    elements.closeEditModalBtn.addEventListener('click', () => closeEditModal());
    elements.cancelEditBtn.addEventListener('click', () => closeEditModal());

    // 顧客保存
    elements.saveCustomerBtn.addEventListener('click', () => saveCustomer());
}

async function attemptLogin() {
    const pass = elements.adminPasswordInput.value.trim();
    if (!pass) return;

    try {
        const res = await fetch(`../api.php?action=admin_list_customers&password=${encodeURIComponent(pass)}`);
        const data = await res.json();
        if (data.success) {
            state.password = pass;
            sessionStorage.setItem('admin_pass', pass);
            elements.loginModal.style.display = 'none';
            elements.loginErrorMsg.style.display = 'none';
            loadDashboard();
        } else {
            elements.loginErrorMsg.textContent = 'パスワードが違います';
            elements.loginErrorMsg.style.display = 'block';
        }
    } catch (e) {
        elements.loginErrorMsg.textContent = '通信エラーが発生しました';
        elements.loginErrorMsg.style.display = 'block';
    }
}

async function loadDashboard() {
    elements.adminApp.style.display = 'block';
    await fetchCustomers();
}

async function fetchCustomers() {
    try {
        const res = await fetch(`../api.php?action=admin_list_customers&password=${encodeURIComponent(state.password)}`);
        const data = await res.json();
        if (data.success && Array.isArray(data.customers)) {
            state.allCustomers = data.customers;
            updateStats();
            renderTable();
        } else if (res.status === 401) {
            sessionStorage.removeItem('admin_pass');
            elements.adminApp.style.display = 'none';
            elements.loginModal.style.display = 'flex';
        }
    } catch (e) {
        showToast('顧客データの取得に失敗しました');
    }
}

function updateStats() {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const in30Days = new Date();
    in30Days.setDate(today.getDate() + 30);

    let oilSoonCount = 0;
    let inspSoonCount = 0;

    state.allCustomers.forEach(c => {
        if (c.oil_next_date) {
            const d = new Date(c.oil_next_date);
            if (d <= in30Days) oilSoonCount++;
        }
        if (c.inspection_next_date) {
            const d = new Date(c.inspection_next_date);
            if (d <= in30Days) inspSoonCount++;
        }
    });

    elements.statTotalUsers.textContent = state.allCustomers.length;
    elements.statOilSoon.textContent = oilSoonCount;
    elements.statInspSoon.textContent = inspSoonCount;

    elements.tabCountAll.textContent = state.allCustomers.length;
    elements.tabCountOil.textContent = oilSoonCount;
    elements.tabCountInsp.textContent = inspSoonCount;
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

    elements.customerTableBody.innerHTML = filtered.map(c => {
        const oilBadge = getBadgeHtml(c.oil_next_date);
        const inspBadge = getBadgeHtml(c.inspection_next_date);
        const memo = c.staff_memo ? escapeHtml(c.staff_memo) : '<span style="color:#cbd5e1">-</span>';
        const updated = (c.updated_at || '').substring(0, 10);

        return `
            <tr>
                <td>
                    <div class="cust-name">${escapeHtml(c.user_name || '名前なし')}</div>
                    <div class="cust-uid">${escapeHtml(c.user_id || '')}</div>
                </td>
                <td>
                    <div class="car-tag">${escapeHtml(c.car_model || '-')}</div>
                    <div class="car-no">${escapeHtml(c.car_number || '')}</div>
                </td>
                <td>${oilBadge}</td>
                <td>${inspBadge}</td>
                <td style="max-width: 200px; font-size: 11px;">${memo}</td>
                <td style="font-size: 11px; color: #64748b;">${updated}</td>
                <td>
                    <div class="action-btns">
                        <button class="btn-edit" onclick="editCustomerById('${c.user_id}')">
                            <i class="fa-solid fa-pen"></i> 編集
                        </button>
                        <button class="btn-delete" onclick="deleteCustomerById('${c.user_id}', '${escapeHtml(c.user_name)}')">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

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

window.editCustomerById = function(userId) {
    const cust = state.allCustomers.find(c => c.user_id === userId);
    if (cust) openEditModal(cust);
};

window.deleteCustomerById = async function(userId, userName) {
    if (!confirm(`「${userName || 'この顧客'}」のデータを削除しますか？`)) return;

    try {
        const payload = new URLSearchParams({
            action: 'admin_delete_customer',
            password: state.password,
            uid: userId
        });
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        if (data.success) {
            showToast('削除しました');
            await fetchCustomers();
        }
    } catch (e) {
        showToast('削除に失敗しました');
    }
};

function openEditModal(cust) {
    if (cust) {
        elements.modalTitle.textContent = `顧客メンテナンス情報の編集: ${cust.user_name || ''}`;
        elements.editUserId.value = cust.user_id || '';
        elements.editUserUid.value = cust.user_id || '';
        elements.editUserName.value = cust.user_name || '';
        elements.editCarModel.value = cust.car_model || '';
        elements.editCarNumber.value = cust.car_number || '';
        elements.editOilLastDate.value = cust.oil_last_date || '';
        elements.editOilNextDate.value = cust.oil_next_date || '';
        elements.editInspectionNextDate.value = cust.inspection_next_date || '';
        elements.editStaffMemo.value = cust.staff_memo || '';
    } else {
        elements.modalTitle.textContent = '新規顧客メンテナンス情報の登録';
        elements.editUserId.value = '';
        elements.editUserUid.value = '';
        elements.editUserName.value = '';
        elements.editCarModel.value = '';
        elements.editCarNumber.value = '';
        elements.editOilLastDate.value = '';
        elements.editOilNextDate.value = '';
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
        uid: elements.editUserUid.value.trim() || elements.editUserId.value.trim(),
        uname: userName,
        car_model: carModel,
        car_number: elements.editCarNumber.value.trim(),
        oil_last_date: elements.editOilLastDate.value,
        oil_next_date: elements.editOilNextDate.value,
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
