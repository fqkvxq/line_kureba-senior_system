/**
 * アップファーレン 顧客メンテナンス管理ダッシュボード JS
 */

const state = {
    password: '',
    allCustomers: [],
    currentFilter: 'all',
    searchQuery: '',
    richMenus: [],
    activeUserMenuCust: null,
    loadedBaseImg: null
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

    // 顧客登録・編集モーダル
    customerEditModal: document.getElementById('customerEditModal'),
    openAddCustomerModalBtn: document.getElementById('openAddCustomerModalBtn'),
    closeEditModalBtn: document.getElementById('closeEditModalBtn'),
    cancelEditBtn: document.getElementById('cancelEditBtn'),
    saveCustomerBtn: document.getElementById('saveCustomerBtn'),
    modalTitle: document.getElementById('modalTitle'),
    
    // 顧客フォーム
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

    // 特定ユーザー向け専用リッチメニュー設定モーダル
    userRichMenuModal: document.getElementById('userRichMenuModal'),
    userMenuModalTitle: document.getElementById('userMenuModalTitle'),
    closeUserMenuModalBtn: document.getElementById('closeUserMenuModalBtn'),
    cancelUserMenuBtn: document.getElementById('cancelUserMenuBtn'),
    applyUserMenuBtn: document.getElementById('applyUserMenuBtn'),
    btnUnlinkMenuBtn: document.getElementById('btnUnlinkMenuBtn'),
    modalCustName: document.getElementById('modalCustName'),
    modalCustCar: document.getElementById('modalCustCar'),
    pillOil: document.getElementById('pillOil'),
    pillPeriodic: document.getElementById('pillPeriodic'),
    pillInsp: document.getElementById('pillInsp'),
    userMenuStatusAlert: document.getElementById('userMenuStatusAlert'),
    currentCustomText: document.getElementById('currentCustomText'),
    userMenuBaseSelect: document.getElementById('userMenuBaseSelect'),
    userMenuTextInput: document.getElementById('userMenuTextInput'),
    userMenuThemeSelect: document.getElementById('userMenuThemeSelect'),
    userMenuPosSelect: document.getElementById('userMenuPosSelect'),
    userMenuPreviewCanvas: document.getElementById('userMenuPreviewCanvas'),
    chipCustInsp: document.getElementById('chipCustInsp'),
    chipCustOil: document.getElementById('chipCustOil'),
    chipCustPeriodic: document.getElementById('chipCustPeriodic'),
    chipCustNotice: document.getElementById('chipCustNotice'),
    btnReloadBaseMenus: document.getElementById('btnReloadBaseMenus'),
    userMenuFontSizeInput: document.getElementById('userMenuFontSizeInput'),
    userMenuFontSizeVal: document.getElementById('userMenuFontSizeVal'),
    userMenuBannerHeightSelect: document.getElementById('userMenuBannerHeightSelect'),

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

    // 顧客登録・編集モーダル開閉
    elements.openAddCustomerModalBtn.addEventListener('click', () => openEditModal(null));
    elements.closeEditModalBtn.addEventListener('click', () => closeEditModal());
    elements.cancelEditBtn.addEventListener('click', () => closeEditModal());
    elements.saveCustomerBtn.addEventListener('click', () => saveCustomer());

    // 特定ユーザー向け専用リッチメニュー設定モーダル開閉 & 操作
    if (elements.closeUserMenuModalBtn) elements.closeUserMenuModalBtn.addEventListener('click', closeUserRichMenuModal);
    if (elements.cancelUserMenuBtn) elements.cancelUserMenuBtn.addEventListener('click', closeUserRichMenuModal);
    if (elements.applyUserMenuBtn) elements.applyUserMenuBtn.addEventListener('click', applyUserRichMenu);
    if (elements.btnUnlinkMenuBtn) elements.btnUnlinkMenuBtn.addEventListener('click', unlinkUserRichMenu);

    // 定型文チップ
    if (elements.chipCustInsp) elements.chipCustInsp.addEventListener('click', () => insertCustomPhrase('insp'));
    if (elements.chipCustOil) elements.chipCustOil.addEventListener('click', () => insertCustomPhrase('oil'));
    if (elements.chipCustPeriodic) elements.chipCustPeriodic.addEventListener('click', () => insertCustomPhrase('periodic'));
    if (elements.chipCustNotice) elements.chipCustNotice.addEventListener('click', () => insertCustomPhrase('notice'));

    // プレビュー変更トリガー
    if (elements.userMenuTextInput) elements.userMenuTextInput.addEventListener('input', renderUserMenuPreview);
    if (elements.userMenuThemeSelect) elements.userMenuThemeSelect.addEventListener('change', renderUserMenuPreview);
    if (elements.userMenuPosSelect) elements.userMenuPosSelect.addEventListener('change', renderUserMenuPreview);
    if (elements.userMenuBaseSelect) elements.userMenuBaseSelect.addEventListener('change', () => loadAndRenderUserMenuBaseImage());

    // 文字サイズ & 帯の高さ & リッチメニュー再読み込み
    if (elements.userMenuFontSizeInput) {
        elements.userMenuFontSizeInput.addEventListener('input', (e) => {
            if (elements.userMenuFontSizeVal) elements.userMenuFontSizeVal.textContent = e.target.value + 'px';
            renderUserMenuPreview();
        });
    }
    if (elements.userMenuBannerHeightSelect) {
        elements.userMenuBannerHeightSelect.addEventListener('change', renderUserMenuPreview);
    }
    if (elements.btnReloadBaseMenus) {
        elements.btnReloadBaseMenus.addEventListener('click', async () => {
            elements.btnReloadBaseMenus.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            await loadRichMenus();
            elements.btnReloadBaseMenus.innerHTML = '<i class="fa-solid fa-rotate-right"></i> 更新';
            loadAndRenderUserMenuBaseImage();
        });
    }
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
            loadRichMenus();
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
    await Promise.all([fetchCustomers(), loadRichMenus()]);
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
        const hasCustomMenu = Boolean(c.custom_line_menu_id);

        return `
            <tr data-index="${idx}">
                <td>
                    <div class="cust-name">${escapeHtml(c.user_name || '名前なし')}</div>
                    <div class="cust-uid">${escapeHtml(c.user_id || '')}</div>
                    ${hasCustomMenu ? `<div class="badge-custom-menu-active" title="専用メッセージ: ${escapeHtml(c.custom_menu_text || '')}"><i class="fa-solid fa-bolt"></i> 専用メニュー中</div>` : ''}
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
                        <button class="btn-user-richmenu ${hasCustomMenu ? 'is-active' : ''}" data-action="custom-menu" data-idx="${idx}" title="専用メッセージ付きリッチメニューを設定">
                            <i class="fa-solid fa-table-cells-large"></i> 専用メニュー
                        </button>
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

            if (action === 'custom-menu') {
                openUserRichMenuModal(cust);
            } else if (action === 'remind-oil') {
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

// ==========================================================================
// 特定ユーザー向け専用リッチメニュー管理
// ==========================================================================
async function loadRichMenus() {
    try {
        const res = await fetch(`../api.php?action=admin_list_richmenus&password=${encodeURIComponent(state.password)}`);
        const data = await res.json();
        if (data.success) {
            state.richMenus = data.menus || data.rich_menus || [];
            updateBaseMenuSelect();
        }
    } catch (e) {
        console.error('Failed to load richmenus:', e);
    }
}

function updateBaseMenuSelect() {
    if (!elements.userMenuBaseSelect) return;
    elements.userMenuBaseSelect.innerHTML = '';

    if (!state.richMenus || state.richMenus.length === 0) {
        elements.userMenuBaseSelect.innerHTML = '<option value="">（リッチメニューがありません）</option>';
        return;
    }

    state.richMenus.forEach(m => {
        const isLive = (m.is_active == 1);
        const opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = (isLive ? '★ [本番公開中] ' : '') + m.title;
        if (isLive) opt.selected = true;
        elements.userMenuBaseSelect.appendChild(opt);
    });
}

async function openUserRichMenuModal(cust) {
    if (!cust.user_id || cust.user_id.startsWith('MANUAL_')) {
        alert('この顧客は手動登録（LINE未連携）のため、専用リッチメニューを適用できません。\n友だち登録連携後のお客様のみご利用いただけます。');
        return;
    }

    state.activeUserMenuCust = cust;

    // ヘッダー・サマリー更新
    elements.userMenuModalTitle.innerHTML = `<i class="fa-solid fa-table-cells-large"></i> 【${escapeHtml(cust.user_name || 'お客様')} 様】専用リッチメニュー設定`;
    elements.modalCustName.textContent = `${cust.user_name || 'お客様'} 様`;
    elements.modalCustCar.textContent = `${cust.car_model || '-'} (${cust.car_number || 'ナンバー未登録'})`;

    elements.pillOil.innerHTML = `次回オイル: <strong>${cust.oil_next_date || '未定'}</strong>`;
    elements.pillPeriodic.innerHTML = `12ヶ月点検: <strong>${cust.periodic_insp_next_date || '未定'}</strong>`;
    elements.pillInsp.innerHTML = `車検満了: <strong>${cust.inspection_next_date || '未定'}</strong>`;

    // 適用中ステータス
    if (cust.custom_line_menu_id) {
        elements.userMenuStatusAlert.style.display = 'flex';
        elements.currentCustomText.textContent = cust.custom_menu_text || '専用メニュー適用中';
    } else {
        elements.userMenuStatusAlert.style.display = 'none';
    }

    // メッセージ入力の初期値
    elements.userMenuTextInput.value = cust.custom_menu_text || getDefaultCustomPhrase(cust, 'insp');

    // リッチメニュー一覧が未取得なら取得
    if (!state.richMenus || state.richMenus.length === 0) {
        await loadRichMenus();
    } else {
        updateBaseMenuSelect();
    }

    elements.userRichMenuModal.classList.add('active');

    loadAndRenderUserMenuBaseImage();
}

function closeUserRichMenuModal() {
    elements.userRichMenuModal.classList.remove('active');
    state.activeUserMenuCust = null;
    state.loadedBaseImg = null;
}

function getDefaultCustomPhrase(cust, type) {
    const name = cust.user_name || 'お客様';
    if (type === 'insp') {
        const d = cust.inspection_next_date || '近日';
        return `${name}様 次回車検は【${d}】です！ご予約はお早めに🚗`;
    } else if (type === 'oil') {
        const d = cust.oil_next_date || '近日';
        return `${name}様 次回オイル交換の目安は【${d}】です🛢️`;
    } else if (type === 'periodic') {
        const d = cust.periodic_insp_next_date || '近日';
        return `${name}様 【${d}】は12ヶ月定期点検の時期です📋`;
    } else if (type === 'notice') {
        return `${name}様 いつもありがとうございます！愛車の点検はお気軽にご相談ください✨`;
    }
    return `${name}様 点検・車検のご相談はお気軽にどうぞ！`;
}

function insertCustomPhrase(type) {
    if (!state.activeUserMenuCust) return;
    elements.userMenuTextInput.value = getDefaultCustomPhrase(state.activeUserMenuCust, type);
    renderUserMenuPreview();
}

function getSelectedBaseMenu() {
    const baseId = elements.userMenuBaseSelect ? elements.userMenuBaseSelect.value : '';
    if (!baseId && state.richMenus.length > 0) return state.richMenus[0];
    return state.richMenus.find(m => String(m.id) === String(baseId)) || state.richMenus[0] || null;
}

function loadAndRenderUserMenuBaseImage() {
    const base = getSelectedBaseMenu();
    if (!base || !base.image_url) {
        renderUserMenuPreview();
        return;
    }

    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
        state.loadedBaseImg = img;
        renderUserMenuPreview();
    };
    img.onerror = () => {
        state.loadedBaseImg = null;
        renderUserMenuPreview();
    };
    img.src = base.base_image_url || base.image_url;
}

function renderUserMenuPreview() {
    const canvas = elements.userMenuPreviewCanvas;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    const base = getSelectedBaseMenu();
    const w = base ? (parseInt(base.width, 10) || 2500) : 2500;
    const h = base ? (parseInt(base.height, 10) || 1686) : 1686;
    canvas.width = w;
    canvas.height = h;

    // 1. ベース画像の描画
    if (state.loadedBaseImg) {
        ctx.drawImage(state.loadedBaseImg, 0, 0, w, h);
    } else {
        // 画像未読み込み時は上品なプレースホルダー背景
        ctx.fillStyle = '#1e293b';
        ctx.fillRect(0, 0, w, h);
        ctx.fillStyle = '#64748b';
        ctx.font = 'bold 50px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('リッチメニュー読み込み中...', w / 2, h / 2);
    }

    // 2. メッセージテロップ帯の描画
    const rawText = elements.userMenuTextInput.value.trim();
    if (!rawText) return;

    const theme = elements.userMenuThemeSelect ? elements.userMenuThemeSelect.value : 'red';
    const pos = elements.userMenuPosSelect ? elements.userMenuPosSelect.value : 'top';
    const fontSize = elements.userMenuFontSizeInput ? parseInt(elements.userMenuFontSizeInput.value, 10) : 65;
    const heightSetting = elements.userMenuBannerHeightSelect ? elements.userMenuBannerHeightSelect.value : 'auto';

    // 複数行テキストの分解
    const lines = rawText.split('\n').map(l => l.trim()).filter(l => l.length > 0);
    if (lines.length === 0) return;

    // 帯の高さ計算（文字サイズ・行数・帯の太さ設定を考慮）
    let bannerH;
    if (heightSetting === 'compact') {
        bannerH = Math.max(Math.round(fontSize * 1.5), 140);
    } else if (heightSetting === 'standard') {
        bannerH = Math.max(Math.round(fontSize * 1.8), 185);
    } else if (heightSetting === 'wide') {
        bannerH = Math.max(Math.round(fontSize * 2.2), 240);
    } else {
        // auto: 行数とフォントサイズに応じて余白を最適化
        const lineSpacing = fontSize * 1.32;
        const textBlockH = (lines.length * lineSpacing);
        bannerH = Math.max(150, Math.round(textBlockH + (fontSize * 0.95)));
    }

    const bannerY = (pos === 'top') ? 0 : (h - bannerH);

    // テーマカラー設定
    let bgGrad;
    let accentBorder = 'rgba(255, 255, 255, 0.35)';
    if (theme === 'red') {
        bgGrad = ctx.createLinearGradient(0, bannerY, w, bannerY);
        bgGrad.addColorStop(0, '#e11d48');
        bgGrad.addColorStop(1, '#be123c');
    } else if (theme === 'blue') {
        bgGrad = ctx.createLinearGradient(0, bannerY, w, bannerY);
        bgGrad.addColorStop(0, '#2563eb');
        bgGrad.addColorStop(1, '#1d4ed8');
    } else if (theme === 'green') {
        bgGrad = ctx.createLinearGradient(0, bannerY, w, bannerY);
        bgGrad.addColorStop(0, '#059669');
        bgGrad.addColorStop(1, '#047857');
    } else if (theme === 'gold') {
        bgGrad = ctx.createLinearGradient(0, bannerY, w, bannerY);
        bgGrad.addColorStop(0, '#d97706');
        bgGrad.addColorStop(1, '#b45309');
    } else {
        // dark
        bgGrad = ctx.createLinearGradient(0, bannerY, w, bannerY);
        bgGrad.addColorStop(0, '#0f172a');
        bgGrad.addColorStop(1, '#1e293b');
    }

    // 背景ドロップシャドウ & 帯の描画
    ctx.save();
    ctx.shadowColor = 'rgba(0, 0, 0, 0.45)';
    ctx.shadowBlur = 24;
    ctx.shadowOffsetY = (pos === 'top') ? 8 : -8;
    ctx.fillStyle = bgGrad;
    ctx.fillRect(0, bannerY, w, bannerH);
    ctx.restore();

    // 縁取りライン
    ctx.strokeStyle = accentBorder;
    ctx.lineWidth = 3.5;
    ctx.beginPath();
    if (pos === 'top') {
        ctx.moveTo(0, bannerY + bannerH);
        ctx.lineTo(w, bannerY + bannerH);
    } else {
        ctx.moveTo(0, bannerY);
        ctx.lineTo(w, bannerY);
    }
    ctx.stroke();

    // テキスト描画
    ctx.save();
    ctx.fillStyle = '#ffffff';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.shadowColor = 'rgba(0, 0, 0, 0.7)';
    ctx.shadowBlur = 12;
    ctx.shadowOffsetY = 2;

    ctx.font = `bold ${fontSize}px "Noto Sans JP", -apple-system, BlinkMacSystemFont, sans-serif`;

    const lineSpacing = fontSize * 1.32;
    const totalTextH = (lines.length - 1) * lineSpacing;
    const startY = (bannerY + bannerH / 2) - (totalTextH / 2);

    lines.forEach((line, idx) => {
        const lineY = startY + (idx * lineSpacing);
        ctx.fillText(line, w / 2, lineY, w - 120);
    });

    ctx.restore();
}

async function applyUserRichMenu() {
    const cust = state.activeUserMenuCust;
    if (!cust || !cust.user_id) return;

    const text = elements.userMenuTextInput.value.trim();
    if (!text) {
        alert('表示するメッセージを入力してください');
        elements.userMenuTextInput.focus();
        return;
    }

    const base = getSelectedBaseMenu();
    if (!base) {
        alert('ベースリッチメニューを選択してください');
        return;
    }

    if (!confirm(`【${cust.user_name || 'お客様'} 様】へ、この専用メッセージ付きリッチメニューを適用しますか？\n（${cust.user_name} 様のLINE画面下部が即座に切り替わります）`)) {
        return;
    }

    elements.applyUserMenuBtn.disabled = true;
    elements.applyUserMenuBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> LINEに適用中...';

    const canvas = elements.userMenuPreviewCanvas;
    canvas.toBlob(async (blob) => {
        if (!blob) {
            elements.applyUserMenuBtn.disabled = false;
            elements.applyUserMenuBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> 専用リッチメニューをLINEに適用';
            alert('画像生成に失敗しました');
            return;
        }

        const formData = new FormData();
        formData.append('password', state.password);
        formData.append('uid', cust.user_id);
        formData.append('base_menu_id', base.id);
        formData.append('custom_text', text);
        formData.append('image', blob, 'custom_menu.jpg');

        try {
            const res = await fetch('../api.php?action=admin_set_user_custom_richmenu', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            elements.applyUserMenuBtn.disabled = false;
            elements.applyUserMenuBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> 専用リッチメニューをLINEに適用';

            if (data.success) {
                showToast(data.message || '専用リッチメニューをLINEに適用しました！');
                closeUserRichMenuModal();
                await fetchCustomers();
            } else {
                alert(data.error || '適用に失敗しました');
            }
        } catch (e) {
            elements.applyUserMenuBtn.disabled = false;
            elements.applyUserMenuBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> 専用リッチメニューをLINEに適用';
            alert('通信エラーが発生しました: ' + e.message);
        }
    }, 'image/jpeg', 0.92);
}

async function unlinkUserRichMenu() {
    const cust = state.activeUserMenuCust;
    if (!cust || !cust.user_id) return;

    if (!confirm(`【${cust.user_name || 'お客様'} 様】の専用リッチメニューを解除し、全体共通メニューに戻しますか？`)) {
        return;
    }

    elements.btnUnlinkMenuBtn.disabled = true;
    elements.btnUnlinkMenuBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 解除中...';

    const formData = new FormData();
    formData.append('password', state.password);
    formData.append('uid', cust.user_id);

    try {
        const res = await fetch('../api.php?action=admin_unlink_user_richmenu', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        elements.btnUnlinkMenuBtn.disabled = false;
        elements.btnUnlinkMenuBtn.innerHTML = '<i class="fa-solid fa-arrow-rotate-left"></i> 全体共通に戻す';

        if (data.success) {
            showToast(data.message || '全体共通メニューに戻しました！');
            elements.userMenuStatusAlert.style.display = 'none';
            if (cust) cust.custom_line_menu_id = '';
            await fetchCustomers();
        } else {
            alert(data.error || '解除に失敗しました');
        }
    } catch (e) {
        elements.btnUnlinkMenuBtn.disabled = false;
        elements.btnUnlinkMenuBtn.innerHTML = '<i class="fa-solid fa-arrow-rotate-left"></i> 全体共通に戻す';
        alert('通信エラーが発生しました: ' + e.message);
    }
}
