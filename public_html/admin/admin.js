/**
 * アップファーレン 顧客メンテナンス管理ダッシュボード JS
 */

const state = {
    password: '',
    allCustomers: [],
    currentFilter: 'all',
    currentSort: 'last_interaction',
    searchQuery: '',
    richMenus: [],
    activeUserMenuCust: null,
    loadedBaseImg: null,
    userMenuBannerBounds: null
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
    adminSortSelect: document.getElementById('adminSortSelect'),
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
    editNearestMaintSummary: document.getElementById('editNearestMaintSummary'),
    editNearestMaintText: document.getElementById('editNearestMaintText'),
    editAutoApplyMenuRow: document.getElementById('editAutoApplyMenuRow'),
    editAutoApplyMenuCheckbox: document.getElementById('editAutoApplyMenuCheckbox'),

    // 特定ユーザー向け専用リッチメニュー設定モーダル
    userRichMenuModal: document.getElementById('userRichMenuModal'),
    userMenuModalTitle: document.getElementById('userMenuModalTitle'),
    closeUserMenuModalBtn: document.getElementById('closeUserMenuModalBtn'),
    cancelUserMenuBtn: document.getElementById('cancelUserMenuBtn'),
    applyUserMenuBtn: document.getElementById('applyUserMenuBtn'),
    applyDirectMenuBtn: document.getElementById('applyDirectMenuBtn'),
    btnUnlinkMenuBtn: document.getElementById('btnUnlinkMenuBtn'),
    modalCustName: document.getElementById('modalCustName'),
    modalCustCar: document.getElementById('modalCustCar'),
    pillOil: document.getElementById('pillOil'),
    pillPeriodic: document.getElementById('pillPeriodic'),
    pillInsp: document.getElementById('pillInsp'),
    userMenuStatusAlert: document.getElementById('userMenuStatusAlert'),
    currentCustomText: document.getElementById('currentCustomText'),

    // リアルタイムステータス & タブ & 既存メニュー指定
    userMenuRealtimeCard: document.getElementById('userMenuRealtimeCard'),
    realtimeMenuThumb: document.getElementById('realtimeMenuThumb'),
    realtimeMenuBadge: document.getElementById('realtimeMenuBadge'),
    realtimeMenuTitle: document.getElementById('realtimeMenuTitle'),
    tabModeExistingMenu: document.getElementById('tabModeExistingMenu'),
    tabModeCustomMessage: document.getElementById('tabModeCustomMessage'),
    sectionExistingMenu: document.getElementById('sectionExistingMenu'),
    sectionCustomMessageMenu: document.getElementById('sectionCustomMessageMenu'),
    directAssignMenuSelect: document.getElementById('directAssignMenuSelect'),
    directAssignMenuPreviewImg: document.getElementById('directAssignMenuPreviewImg'),
    directAssignMenuMeta: document.getElementById('directAssignMenuMeta'),

    userMenuBaseSelect: document.getElementById('userMenuBaseSelect'),
    userMenuTextInput: document.getElementById('userMenuTextInput'),
    userMenuThemeSelect: document.getElementById('userMenuThemeSelect'),
    userMenuPosSelect: document.getElementById('userMenuPosSelect'),
    userMenuPreviewCanvas: document.getElementById('userMenuPreviewCanvas'),
    chipCustAutoNearest: document.getElementById('chipCustAutoNearest'),
    chipCustInsp: document.getElementById('chipCustInsp'),
    chipCustOil: document.getElementById('chipCustOil'),
    chipCustPeriodic: document.getElementById('chipCustPeriodic'),
    chipCustNotice: document.getElementById('chipCustNotice'),
    btnReloadBaseMenus: document.getElementById('btnReloadBaseMenus'),
    userMenuFontSizeInput: document.getElementById('userMenuFontSizeInput'),
    userMenuFontSizeVal: document.getElementById('userMenuFontSizeVal'),
    userMenuBannerHeightSelect: document.getElementById('userMenuBannerHeightSelect'),
    userMenuBannerActionType: document.getElementById('userMenuBannerActionType'),
    userMenuBannerUriRow: document.getElementById('userMenuBannerUriRow'),
    userMenuBannerUriInput: document.getElementById('userMenuBannerUriInput'),
    userMenuBannerPostbackRow: document.getElementById('userMenuBannerPostbackRow'),
    userMenuBannerPostbackInput: document.getElementById('userMenuBannerPostbackInput'),
    userMenuShowTapHint: document.getElementById('userMenuShowTapHint'),
    syncLineFollowersBtn: document.getElementById('syncLineFollowersBtn'),

    // 管理者LINE通知設定モーダル
    openAdminLineSettingsBtn: document.getElementById('openAdminLineSettingsBtn'),
    adminLineSettingsModal: document.getElementById('adminLineSettingsModal'),
    closeAdminLineSettingsModalBtn: document.getElementById('closeAdminLineSettingsModalBtn'),
    cancelAdminLineSettingsBtn: document.getElementById('cancelAdminLineSettingsBtn'),
    saveAdminLineSettingsBtn: document.getElementById('saveAdminLineSettingsBtn'),
    testAdminLineNotificationBtn: document.getElementById('testAdminLineNotificationBtn'),
    adminLineUidsInput: document.getElementById('adminLineUidsInput'),
    adminLineSaveStatus: document.getElementById('adminLineSaveStatus'),
    notifyInquiryCheck: document.getElementById('notifyInquiryCheck'),
    notifyBookingCheck: document.getElementById('notifyBookingCheck'),
    notifyNewCustomerCheck: document.getElementById('notifyNewCustomerCheck'),
    notifyNewCarsCheck: document.getElementById('notifyNewCarsCheck'),
    notifyReminderCheck: document.getElementById('notifyReminderCheck'),
    btnQuickAddAdminFromEdit: document.getElementById('btnQuickAddAdminFromEdit'),

    // プロライン連携設定モーダル
    openProlineSettingsBtn: document.getElementById('openProlineSettingsBtn'),
    prolineSettingsModal: document.getElementById('prolineSettingsModal'),
    closeProlineSettingsModalBtn: document.getElementById('closeProlineSettingsModalBtn'),
    closeProlineSettingsBtn: document.getElementById('closeProlineSettingsBtn'),
    saveProlineSettingsBtn: document.getElementById('saveProlineSettingsBtn'),
    testProlineRelayBtn: document.getElementById('testProlineRelayBtn'),
    prolineWebhookUrlInput: document.getElementById('prolineWebhookUrlInput'),
    prolineRelayEnabledCheck: document.getElementById('prolineRelayEnabledCheck'),
    prolineTestResultBanner: document.getElementById('prolineTestResultBanner'),
    prolineRecentLogsWrap: document.getElementById('prolineRecentLogsWrap'),
    prolineSaveStatus: document.getElementById('prolineSaveStatus'),
    btnRefreshProlineLogs: document.getElementById('btnRefreshProlineLogs'),
    displayOurWebhookUrl: document.getElementById('displayOurWebhookUrl'),

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
    // プロライン連携設定モーダル開閉 & 操作
    if (elements.openProlineSettingsBtn) elements.openProlineSettingsBtn.addEventListener('click', openProlineSettingsModal);
    if (elements.closeProlineSettingsModalBtn) elements.closeProlineSettingsModalBtn.addEventListener('click', closeProlineSettingsModal);
    if (elements.closeProlineSettingsBtn) elements.closeProlineSettingsBtn.addEventListener('click', closeProlineSettingsModal);
    if (elements.saveProlineSettingsBtn) elements.saveProlineSettingsBtn.addEventListener('click', saveProlineSettings);
    if (elements.testProlineRelayBtn) elements.testProlineRelayBtn.addEventListener('click', testProlineRelay);
    if (elements.btnRefreshProlineLogs) elements.btnRefreshProlineLogs.addEventListener('click', loadProlineSettings);

    // 管理者LINE通知設定モーダル開閉 & 操作
    if (elements.openAdminLineSettingsBtn) elements.openAdminLineSettingsBtn.addEventListener('click', openAdminLineSettingsModal);
    if (elements.closeAdminLineSettingsModalBtn) elements.closeAdminLineSettingsModalBtn.addEventListener('click', closeAdminLineSettingsModal);
    if (elements.cancelAdminLineSettingsBtn) elements.cancelAdminLineSettingsBtn.addEventListener('click', closeAdminLineSettingsModal);
    if (elements.saveAdminLineSettingsBtn) elements.saveAdminLineSettingsBtn.addEventListener('click', saveAdminLineSettings);
    if (elements.testAdminLineNotificationBtn) elements.testAdminLineNotificationBtn.addEventListener('click', testAdminLineNotification);
    if (elements.btnQuickAddAdminFromEdit) elements.btnQuickAddAdminFromEdit.addEventListener('click', quickAddAdminUidFromEdit);

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

    // 並び替えセレクト
    if (elements.adminSortSelect) {
        elements.adminSortSelect.addEventListener('change', (e) => {
            state.currentSort = e.target.value;
            renderTable();
        });
    }

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
    if (elements.applyDirectMenuBtn) elements.applyDirectMenuBtn.addEventListener('click', applyDirectRichMenu);
    if (elements.btnUnlinkMenuBtn) elements.btnUnlinkMenuBtn.addEventListener('click', unlinkUserRichMenu);

    // タブ切替 (既存メニュー指定 vs 専用メッセージ帯作成)
    if (elements.tabModeExistingMenu) {
        elements.tabModeExistingMenu.addEventListener('click', () => switchUserMenuTab('existing'));
    }
    if (elements.tabModeCustomMessage) {
        elements.tabModeCustomMessage.addEventListener('click', () => switchUserMenuTab('custom'));
    }

    // 既存メニュー指定セレクタ変更時
    if (elements.directAssignMenuSelect) {
        elements.directAssignMenuSelect.addEventListener('change', updateDirectAssignPreview);
    }

    // 顧客登録・編集モーダルでの直近点検サマリー自動連動
    ['editOilNextDate', 'editPeriodicNextDate', 'editInspectionNextDate', 'editUserName'].forEach(id => {
        if (elements[id]) {
            elements[id].addEventListener('input', updateEditModalNearestSummary);
            elements[id].addEventListener('change', updateEditModalNearestSummary);
        }
    });

    // 定型文チップ
    if (elements.chipCustAutoNearest) elements.chipCustAutoNearest.addEventListener('click', applyNearestPhraseToCustomMenu);
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

    // メッセージ帯アクション切替
    if (elements.userMenuBannerActionType) {
        elements.userMenuBannerActionType.addEventListener('change', () => {
            const val = elements.userMenuBannerActionType.value;
            if (elements.userMenuBannerUriRow) {
                elements.userMenuBannerUriRow.style.display = (val === 'uri') ? 'block' : 'none';
            }
            if (elements.userMenuBannerPostbackRow) {
                elements.userMenuBannerPostbackRow.style.display = (val === 'postback') ? 'block' : 'none';
            }
            renderUserMenuPreview();
        });
    }
    if (elements.userMenuBannerUriInput) elements.userMenuBannerUriInput.addEventListener('input', renderUserMenuPreview);
    if (elements.userMenuBannerPostbackInput) elements.userMenuBannerPostbackInput.addEventListener('input', renderUserMenuPreview);
    if (elements.userMenuShowTapHint) elements.userMenuShowTapHint.addEventListener('change', renderUserMenuPreview);

    // LINE友だち一括同期
    if (elements.syncLineFollowersBtn) {
        elements.syncLineFollowersBtn.addEventListener('click', syncLineFollowers);
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
        const sortParam = encodeURIComponent(state.currentSort || 'last_interaction');
        const res = await fetch(`../api.php?action=admin_list_customers&password=${encodeURIComponent(state.password)}&sort=${sortParam}`);
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

async function syncLineFollowers() {
    if (!confirm("LINE公式アカウントの全友だち一覧を取得し、まだ顧客一覧にいない友だちを一括登録・最新の名前に同期しますか？\n\n※友だち数が多い場合、数十秒ほどかかる場合があります。")) {
        return;
    }

    const btn = elements.syncLineFollowersBtn;
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 同期中...';
    }

    try {
        const res = await fetch(`../api.php?action=admin_sync_line_followers&password=${encodeURIComponent(state.password)}`);
        const data = await res.json();

        if (data.success) {
            showToast(`✅ ${data.message}`);
            await fetchCustomers();
        } else {
            alert(data.error || '同期処理に失敗しました');
        }
    } catch (e) {
        console.error('Sync followers error:', e);
        alert('通信エラーが発生しました: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
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

    // 並び替え処理
    const sort = state.currentSort || 'last_interaction';
    const parseSafeTime = (val) => {
        if (!val) return 0;
        const str = String(val).replace(/-/g, '/').replace('T', ' ');
        const t = new Date(str).getTime();
        return isNaN(t) ? 0 : t;
    };

    filtered.sort((a, b) => {
        if (sort === 'last_interaction') {
            const timeA = parseSafeTime(a.last_interaction_at || a.updated_at || a.created_at);
            const timeB = parseSafeTime(b.last_interaction_at || b.updated_at || b.created_at);
            return timeB - timeA;
        } else if (sort === 'insp_soon') {
            if (!a.inspection_next_date && !b.inspection_next_date) return 0;
            if (!a.inspection_next_date) return 1;
            if (!b.inspection_next_date) return -1;
            return parseSafeTime(a.inspection_next_date) - parseSafeTime(b.inspection_next_date);
        } else if (sort === 'oil_soon') {
            if (!a.oil_next_date && !b.oil_next_date) return 0;
            if (!a.oil_next_date) return 1;
            if (!b.oil_next_date) return -1;
            return parseSafeTime(a.oil_next_date) - parseSafeTime(b.oil_next_date);
        } else if (sort === 'periodic_soon') {
            if (!a.periodic_insp_next_date && !b.periodic_insp_next_date) return 0;
            if (!a.periodic_insp_next_date) return 1;
            if (!b.periodic_insp_next_date) return -1;
            return parseSafeTime(a.periodic_insp_next_date) - parseSafeTime(b.periodic_insp_next_date);
        } else if (sort === 'name_asc') {
            return (a.user_name || '').localeCompare(b.user_name || '', 'ja');
        } else if (sort === 'created_desc') {
            return parseSafeTime(b.created_at) - parseSafeTime(a.created_at);
        } else if (sort === 'updated_desc') {
            return parseSafeTime(b.updated_at) - parseSafeTime(a.updated_at);
        }
        return 0;
    });

    elements.customerTableBody.innerHTML = filtered.map((c, idx) => {
        const oilBadge = getBadgeHtml(c.oil_next_date);
        const periodicBadge = getBadgeHtml(c.periodic_insp_next_date);
        const inspBadge = getBadgeHtml(c.inspection_next_date);
        const memo = c.staff_memo ? escapeHtml(c.staff_memo) : '<span style="color:#cbd5e1">-</span>';
        const carId = c.id || '';
        const userId = c.user_id || '';
        const hasCustomMenu = Boolean(c.custom_line_menu_id);
        const menuType = c.current_menu_type || (hasCustomMenu ? 'custom_message' : 'default');
        const menuName = c.current_menu_name || (hasCustomMenu ? '専用メニュー' : '通常メニュー');
        const isCustomized = (menuType === 'custom_message' || menuType === 'custom_assigned' || hasCustomMenu);

        let menuBadgeHtml = '';
        if (menuType === 'custom_message') {
            menuBadgeHtml = `<span class="badge-menu-status badge-menu-custom" title="専用メッセージ: ${escapeHtml(c.custom_menu_text || '')}"><i class="fa-solid fa-bolt"></i> 専用メッセージ中</span>`;
        } else if (menuType === 'custom_assigned') {
            menuBadgeHtml = `<span class="badge-menu-status badge-menu-assigned" title="個別メニュー指定中: ${escapeHtml(menuName)}"><i class="fa-solid fa-tag"></i> 個別: ${escapeHtml(menuName)}</span>`;
        } else {
            const displayName = (menuName && menuName !== '全体共通メニュー' && menuName !== '全体共通') ? menuName : '通常メニュー';
            menuBadgeHtml = `<span class="badge-menu-status badge-menu-default" title="設定中のリッチメニュー（全体共通）: ${escapeHtml(displayName)}"><i class="fa-solid fa-globe"></i> ${escapeHtml(displayName)}</span>`;
        }

        // 最終やり取り情報
        const interactionType = c.last_interaction_type || 'follow';
        const interactionPreview = c.last_interaction_preview || '友だち登録';
        const interactionDisplay = c.last_interaction_display || (c.last_interaction_at ? c.last_interaction_at.substring(0, 16).replace('-', '/') : '未記録');
        const diffText = c.last_interaction_diff_text || (c.last_interaction_at ? c.last_interaction_at.substring(0, 10) : '未記録');

        let badgeClass = 'badge-follow';
        let badgeIcon = '<i class="fa-solid fa-user-plus"></i>';
        let badgeLabel = '友だち登録';
        if (interactionType === 'user_message') {
            badgeClass = 'badge-user-msg';
            badgeIcon = '<i class="fa-solid fa-comment"></i>';
            badgeLabel = 'メッセージ';
        } else if (interactionType === 'user_action') {
            badgeClass = 'badge-user-action';
            badgeIcon = '<i class="fa-solid fa-bolt"></i>';
            badgeLabel = '操作・相談';
        } else if (interactionType === 'admin_reminder') {
            badgeClass = 'badge-admin-remind';
            badgeIcon = '<i class="fa-solid fa-paper-plane"></i>';
            badgeLabel = 'リマインド';
        } else if (interactionType === 'custom_menu') {
            badgeClass = 'badge-custom-menu';
            badgeIcon = '<i class="fa-solid fa-table-cells-large"></i>';
            badgeLabel = 'メニュー設定';
        }

        const interactionCellHtml = `
            <div class="interaction-cell" title="${escapeHtml(interactionPreview)} (${interactionDisplay})">
                <div class="interaction-header">
                    <span class="interaction-time-badge">${escapeHtml(diffText)}</span>
                    <span class="interaction-badge ${badgeClass}">${badgeIcon} ${badgeLabel}</span>
                </div>
                <div class="interaction-preview">${escapeHtml(interactionPreview)}</div>
                <div class="interaction-full-date">${escapeHtml(interactionDisplay)}</div>
            </div>
        `;

        return `
            <tr data-index="${idx}">
                <td>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        ${c.picture_url ? `
                            <img src="${escapeHtml(c.picture_url)}" alt="" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1.5px solid #e2e8f0; flex-shrink: 0; box-shadow: 0 1px 3px rgba(0,0,0,0.08);" onerror="this.onerror=null; this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                            <div style="display: none; width: 38px; height: 38px; border-radius: 50%; background: #e2e8f0; color: #64748b; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;">
                                <i class="fa-solid fa-user"></i>
                            </div>
                        ` : `
                            <div style="width: 38px; height: 38px; border-radius: 50%; background: #e2e8f0; color: #64748b; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;">
                                <i class="fa-solid fa-user"></i>
                            </div>
                        `}
                        <div style="min-width: 0;">
                            <div class="cust-name">${escapeHtml(c.user_name || '名前なし')}</div>
                            <div class="cust-uid" style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">
                                <span>${escapeHtml(c.user_id || '')}</span>
                                ${c.user_id && c.user_id.startsWith('U') ? `
                                    <button class="btn-copy-uid" title="UIDをクリップボードにコピー" onclick="event.stopPropagation(); copyCustUid('${escapeHtml(c.user_id)}');" style="background: none; border: none; color: #64748b; cursor: pointer; padding: 2px 4px; font-size: 11px; border-radius: 4px;" onmouseover="this.style.color='#1e293b'; this.style.background='#f1f5f9';" onmouseout="this.style.color='#64748b'; this.style.background='none';">
                                        <i class="fa-regular fa-copy"></i>
                                    </button>
                                    <button class="btn-add-admin-uid" title="このアカウントを管理者LINE通知先に登録" onclick="event.stopPropagation(); addAdminUidDirectly('${escapeHtml(c.user_id)}', '${escapeHtml(c.user_name || '')}');" style="background: none; border: none; color: #0284c7; cursor: pointer; padding: 2px 4px; font-size: 11px; border-radius: 4px;" onmouseover="this.style.color='#0369a1'; this.style.background='#e0f2fe';" onmouseout="this.style.color='#0284c7'; this.style.background='none';">
                                        <i class="fa-solid fa-bell"></i> 通知先に登録
                                    </button>
                                ` : ''}
                            </div>
                            <div style="margin-top: 4px;">${menuBadgeHtml}</div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="car-tag">${escapeHtml(c.car_model || '-')}</div>
                    <div class="car-no">${escapeHtml(c.car_number || '')}</div>
                </td>
                <td>${oilBadge}</td>
                <td>${periodicBadge}</td>
                <td>${inspBadge}</td>
                <td>${interactionCellHtml}</td>
                <td style="max-width: 160px; font-size: 11px;">${memo}</td>
                <td>
                    <div class="action-btns">
                        <button class="btn-user-richmenu ${isCustomized ? 'is-active' : ''}" data-action="custom-menu" data-idx="${idx}" title="リッチメニューの確認・個別指定・メッセージ設定">
                            <i class="fa-solid fa-table-cells-large"></i> メニュー設定
                        </button>
                        <button class="btn-remind-oil" data-action="remind-oil" data-idx="${idx}" title="次回レッスン案内リマインドをLINE送信" style="background:#e0f2fe; color:#0284c7; border:1px solid #bae6fd;">
                            <i class="fa-solid fa-laptop"></i> レッスン
                        </button>
                        <button class="btn-remind-periodic" data-action="remind-periodic" data-idx="${idx}" title="定期PC健康診断リマインドをLINE送信" style="background:#ecfdf5; color:#059669; border:1px solid #a7f3d0;">
                            <i class="fa-solid fa-shield-virus"></i> PC診断
                        </button>
                        <button class="btn-remind-insp" data-action="remind-insp" data-idx="${idx}" title="会員更新・月謝期日リマインドをLINE送信" style="background:#fffbeb; color:#d97706; border:1px solid #fde68a;">
                            <i class="fa-solid fa-calendar-check"></i> 更新期日
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
        alert('この受講生は手動登録（LINE未連携）のため、LINEメッセージを送信できません。');
        return;
    }

    let typeLabel = '💻 次回レッスン案内リマインド';
    if (type === 'periodic') typeLabel = '🔍 定期パソコン健康診断リマインド';
    if (type === 'inspection') typeLabel = '🗓️ 会員更新・月謝期日リマインド';

    if (!confirm(`【${userName || '受講生'} 様 (${carModel || '受講コース'})】へ\n「${typeLabel}」のLINEメッセージを今すぐ送信しますか？`)) {
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

// ==========================================================================
// 直近のメンテナンス期限判定 & 自動メッセージ生成
// ==========================================================================
function getNearestMaintenanceInfo(data, customName) {
    if (!data) return null;
    const name = customName || data.user_name || 'お客様';
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const items = [];

    // 1. オイル交換
    if (data.oil_next_date) {
        const d = new Date(data.oil_next_date);
        d.setHours(0, 0, 0, 0);
        const diff = Math.ceil((d - today) / (1000 * 60 * 60 * 24));
        items.push({
            type: 'oil',
            label: '次回オイル交換',
            shortLabel: '次回オイル',
            date: data.oil_next_date,
            diffDays: diff,
            icon: 'fa-oil-can',
            theme: diff <= 7 ? 'red' : (diff <= 30 ? 'orange' : 'amber'),
            phrase: (diff < 0)
                ? `${name}様 オイル交換の目安時期【${data.oil_next_date}】を過ぎています🛢️ 早めの交換をおすすめします！`
                : (diff === 0)
                ? `${name}様 本日はオイル交換の予定日です🛢️ ご来店をお待ちしております！`
                : (diff <= 14)
                ? `${name}様 次回オイル交換の目安は【${data.oil_next_date}】(あと${diff}日)です🛢️ お早めにご予約ください！`
                : `${name}様 次回オイル交換の目安は【${data.oil_next_date}】です🛢️`
        });
    }

    // 2. 12ヶ月定期点検
    if (data.periodic_insp_next_date) {
        const d = new Date(data.periodic_insp_next_date);
        d.setHours(0, 0, 0, 0);
        const diff = Math.ceil((d - today) / (1000 * 60 * 60 * 24));
        items.push({
            type: 'periodic',
            label: '12ヶ月定期点検',
            shortLabel: '12ヶ月点検',
            date: data.periodic_insp_next_date,
            diffDays: diff,
            icon: 'fa-clipboard-check',
            theme: diff <= 14 ? 'red' : (diff <= 45 ? 'blue' : 'emerald'),
            phrase: (diff < 0)
                ? `${name}様 12ヶ月定期点検の時期【${data.periodic_insp_next_date}】を過ぎています📋 お気軽にご相談ください！`
                : (diff === 0)
                ? `${name}様 本日は12ヶ月点検の予定日です📋 ご来店をお待ちしております！`
                : (diff <= 30)
                ? `${name}様 12ヶ月定期点検【${data.periodic_insp_next_date}】(あと${diff}日)が近づいています📋 ご予約受付中！`
                : `${name}様 【${data.periodic_insp_next_date}】は12ヶ月定期点検の時期です📋`
        });
    }

    // 3. 車検満了
    if (data.inspection_next_date) {
        const d = new Date(data.inspection_next_date);
        d.setHours(0, 0, 0, 0);
        const diff = Math.ceil((d - today) / (1000 * 60 * 60 * 24));
        items.push({
            type: 'insp',
            label: '車検満了日',
            shortLabel: '次回車検',
            date: data.inspection_next_date,
            diffDays: diff,
            icon: 'fa-shield-halved',
            theme: diff <= 30 ? 'red' : (diff <= 60 ? 'orange' : 'indigo'),
            phrase: (diff < 0)
                ? `${name}様 車検満了日【${data.inspection_next_date}】を過ぎています⚠️ お早めにご連絡ください！`
                : (diff === 0)
                ? `${name}様 本日は車検満了日です⚠️ お早めにご相談ください！`
                : (diff <= 60)
                ? `${name}様 次回車検は【${data.inspection_next_date}】(あと${diff}日)です🚗 お早めのご予約をおすすめします！`
                : `${name}様 次回車検は【${data.inspection_next_date}】です🚗 ご予約はお早めに！`
        });
    }

    if (items.length === 0) return null;

    // 未来(diff >= 0)の中で diffDays が最小のものを優先
    const futureItems = items.filter(it => it.diffDays >= 0);
    if (futureItems.length > 0) {
        futureItems.sort((a, b) => a.diffDays - b.diffDays);
        return futureItems[0];
    }

    // 未来がない場合は過去(diff < 0)の中で期限切れが最も浅いもの（絶対値が最小）
    items.sort((a, b) => b.diffDays - a.diffDays);
    return items[0];
}

function updateEditModalNearestSummary() {
    if (!elements.editNearestMaintSummary || !elements.editNearestMaintText) return;

    const dummyData = {
        oil_next_date: elements.editOilNextDate ? elements.editOilNextDate.value : '',
        periodic_insp_next_date: elements.editPeriodicNextDate ? elements.editPeriodicNextDate.value : '',
        inspection_next_date: elements.editInspectionNextDate ? elements.editInspectionNextDate.value : '',
        user_name: elements.editUserName ? elements.editUserName.value : ''
    };

    const nearest = getNearestMaintenanceInfo(dummyData);
    if (nearest) {
        elements.editNearestMaintSummary.style.display = 'flex';
        let diffBadge = nearest.diffDays < 0 
            ? `<span style="color: #ef4444; font-weight: bold;">(期限切れ)</span>`
            : (nearest.diffDays === 0 ? `<span style="color: #ef4444; font-weight: bold;">(本日！)</span>` : `<span style="color: #059669; font-weight: bold;">(あと${nearest.diffDays}日)</span>`);
        elements.editNearestMaintText.innerHTML = `<i class="fa-solid ${nearest.icon}"></i> ${escapeHtml(nearest.label)}: <strong>${nearest.date}</strong> ${diffBadge}`;
    } else {
        elements.editNearestMaintSummary.style.display = 'none';
    }
}

async function applyNearestRichMenuBackground(custData, nearest) {
    try {
        if (!state.richMenus || state.richMenus.length === 0) {
            await loadRichMenus();
        }

        // 【重要】お知らせメニュー(is_notice == 1)は絶対に専用メニューのベースにしない！通常メニュー(is_notice == 0)の本番メニューを特定
        const normalMenus = (state.richMenus || []).filter(m => !m.is_notice || m.is_notice == 0);
        const base = normalMenus.find(m => m.is_active == 1) || normalMenus[0] || state.richMenus.find(m => !m.is_notice) || state.richMenus[0];
        if (!base) {
            console.warn('No base menu available for auto apply');
            return;
        }

        const baseImgUrl = base.base_image_url || base.image_url;
        if (!baseImgUrl) {
            console.warn('Base menu has no image url');
            return;
        }

        // オフスクリーン画像読み込み
        const img = await new Promise((resolve, reject) => {
            const image = new Image();
            image.crossOrigin = 'anonymous';
            image.onload = () => resolve(image);
            image.onerror = (e) => reject(new Error('Base image load failed'));
            image.src = baseImgUrl;
        });

        const canvas = document.createElement('canvas');
        const w = parseInt(base.width, 10) || 2500;
        const h = parseInt(base.height, 10) || 1686;
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d');

        // ベース画像の描画
        ctx.drawImage(img, 0, 0, w, h);

        // メッセージ帯の描画
        const rawText = nearest.phrase;
        const lines = rawText.split('\n').map(l => l.trim()).filter(l => l.length > 0);
        const fontSize = 65;
        const showTapHint = true;
        const extraHintH = Math.round(fontSize * 0.75);
        const lineSpacing = fontSize * 1.32;
        const textBlockH = (lines.length * lineSpacing) + extraHintH;
        const bannerH = Math.max(150, Math.round(textBlockH + (fontSize * 0.95)));
        const bannerY = 0;
        const theme = nearest.theme || 'red';

        ctx.save();
        const grad = ctx.createLinearGradient(0, bannerY, w, bannerY + bannerH);
        if (theme === 'red') {
            grad.addColorStop(0, 'rgba(225, 29, 72, 0.96)');
            grad.addColorStop(1, 'rgba(190, 18, 60, 0.96)');
        } else if (theme === 'amber') {
            grad.addColorStop(0, 'rgba(217, 119, 6, 0.96)');
            grad.addColorStop(1, 'rgba(180, 83, 9, 0.96)');
        } else if (theme === 'orange') {
            grad.addColorStop(0, 'rgba(234, 88, 12, 0.96)');
            grad.addColorStop(1, 'rgba(194, 65, 12, 0.96)');
        } else if (theme === 'blue') {
            grad.addColorStop(0, 'rgba(37, 99, 235, 0.96)');
            grad.addColorStop(1, 'rgba(29, 78, 216, 0.96)');
        } else if (theme === 'emerald') {
            grad.addColorStop(0, 'rgba(5, 150, 105, 0.96)');
            grad.addColorStop(1, 'rgba(4, 120, 87, 0.96)');
        } else {
            grad.addColorStop(0, 'rgba(79, 70, 229, 0.96)');
            grad.addColorStop(1, 'rgba(67, 56, 202, 0.96)');
        }

        ctx.fillStyle = grad;
        ctx.fillRect(0, bannerY, w, bannerH);

        // 下部アクセントライン
        ctx.fillStyle = 'rgba(255, 255, 255, 0.35)';
        ctx.fillRect(0, bannerY + bannerH - 4, w, 4);

        // テキスト描画
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#ffffff';
        ctx.font = `bold ${fontSize}px "Noto Sans JP", -apple-system, sans-serif`;
        ctx.shadowColor = 'rgba(0, 0, 0, 0.45)';
        ctx.shadowBlur = 8;
        ctx.shadowOffsetY = 2;

        const totalLinesH = (lines.length - 1) * lineSpacing;
        const startY = bannerY + (bannerH / 2) - (totalLinesH / 2) - (extraHintH / 2);

        lines.forEach((line, index) => {
            const lineY = startY + (index * lineSpacing);
            ctx.fillText(line, w / 2, lineY, w - 120);
        });

        // タップ誘導
        const hintLabel = '👆 タップして点検・予約を開く';
        const hintFontSize = Math.max(26, Math.round(fontSize * 0.48));
        ctx.font = `bold ${hintFontSize}px "Noto Sans JP", -apple-system, sans-serif`;
        const hintY = startY + totalLinesH + (fontSize * 0.95);
        const textWidth = ctx.measureText(hintLabel).width;
        const pillW = textWidth + 40;
        const pillH = hintFontSize * 1.5;
        ctx.fillStyle = 'rgba(0, 0, 0, 0.28)';
        ctx.beginPath();
        const pillX = (w - pillW) / 2;
        const pillY = hintY - (pillH / 2);
        ctx.roundRect ? ctx.roundRect(pillX, pillY, pillW, pillH, pillH / 2) : ctx.rect(pillX, pillY, pillW, pillH);
        ctx.fill();
        ctx.fillStyle = 'rgba(255, 255, 255, 0.95)';
        ctx.fillText(hintLabel, w / 2, hintY);
        ctx.restore();

        // Blob化 & API送信
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
        if (!blob) throw new Error('Blob generation failed');

        const formData = new FormData();
        formData.append('password', state.password);
        formData.append('uid', custData.user_id);
        formData.append('base_menu_id', base.id || '');
        formData.append('base_line_menu_id', base.line_menu_id || '');
        formData.append('base_areas', JSON.stringify(base.areas || []));
        formData.append('custom_text', nearest.phrase);
        formData.append('image', blob, 'custom_menu.jpg');
        formData.append('banner_action_type', 'mycar_liff');
        formData.append('banner_action_uri', '');
        formData.append('banner_action_postback', '');
        formData.append('banner_bounds', JSON.stringify({ x: 0, y: bannerY, width: w, height: bannerH }));

        const res = await fetch('../api.php?action=admin_set_user_custom_richmenu', {
            method: 'POST',
            body: formData
        });
        const resData = await res.json();
        if (resData.success) {
            showToast(`🎉 【${custData.user_name} 様】の専用リッチメニュー（${nearest.shortLabel}）をLINEに自動適用しました！`);
        } else {
            console.error('Auto apply rich menu failed:', resData.error);
        }
    } catch (err) {
        console.error('applyNearestRichMenuBackground error:', err);
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

        // LINE連携ユーザーなら自動適用オプションを表示
        if (cust.user_id && cust.user_id.startsWith('U')) {
            if (elements.editAutoApplyMenuRow) elements.editAutoApplyMenuRow.style.display = 'block';
            if (elements.editAutoApplyMenuCheckbox) elements.editAutoApplyMenuCheckbox.checked = true;
        } else {
            if (elements.editAutoApplyMenuRow) elements.editAutoApplyMenuRow.style.display = 'none';
            if (elements.editAutoApplyMenuCheckbox) elements.editAutoApplyMenuCheckbox.checked = false;
        }
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
        if (elements.editAutoApplyMenuRow) elements.editAutoApplyMenuRow.style.display = 'none';
        if (elements.editAutoApplyMenuCheckbox) elements.editAutoApplyMenuCheckbox.checked = false;
    }

    // 直近点検サマリーの表示更新
    updateEditModalNearestSummary();

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

    const targetUid = elements.editUserUid.value.trim() || elements.editUserId.value.trim();
    const shouldAutoApply = elements.editAutoApplyMenuCheckbox && elements.editAutoApplyMenuCheckbox.checked;

    const payload = new URLSearchParams({
        action: 'admin_save_customer',
        password: state.password,
        car_id: activeEditingCarId || '',
        uid: targetUid,
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
            closeEditModal();

            // LINE連携ユーザーで自動適用チェックが付いている場合、直近の期限メッセージで専用メニューを自動適用
            if (shouldAutoApply && targetUid && targetUid.startsWith('U')) {
                const custData = {
                    user_id: targetUid,
                    user_name: userName,
                    car_model: carModel,
                    car_number: elements.editCarNumber.value.trim(),
                    oil_next_date: elements.editOilNextDate.value,
                    periodic_insp_next_date: elements.editPeriodicNextDate.value,
                    inspection_next_date: elements.editInspectionNextDate.value
                };
                const nearest = getNearestMaintenanceInfo(custData);
                if (nearest) {
                    showToast(`✅ 顧客情報を保存！直近の【${nearest.shortLabel}】で専用メニューをLINEに適用中...`);
                    await applyNearestRichMenuBackground(custData, nearest);
                } else {
                    showToast('✅ 顧客メンテナンス情報を保存しました！');
                }
            } else {
                showToast('✅ 顧客メンテナンス情報を保存しました！');
            }

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
    }, 4000);
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

    // 通常メニューを優先配置（お知らせメニューは個別専用メッセージのベースには非推奨）
    const normalMenus = state.richMenus.filter(m => !m.is_notice || m.is_notice == 0);
    const noticeMenus = state.richMenus.filter(m => m.is_notice == 1);
    const orderedMenus = [...normalMenus, ...noticeMenus];

    let selectedAssigned = false;
    orderedMenus.forEach(m => {
        const isNotice = (m.is_notice == 1);
        const isLive = (m.is_active == 1);
        const opt = document.createElement('option');
        opt.value = m.id;
        
        let label = '';
        if (isNotice) {
            label = `[📢お知らせ用・非推奨] ${m.title}`;
        } else if (isLive) {
            label = `★ [本番通常メニュー] ${m.title}`;
        } else {
            label = m.title;
        }
        opt.textContent = label;

        // 通常の本番メニューを最優先で選択
        if (!isNotice && isLive && !selectedAssigned) {
            opt.selected = true;
            selectedAssigned = true;
        }
        elements.userMenuBaseSelect.appendChild(opt);
    });

    if (!selectedAssigned && elements.userMenuBaseSelect.options.length > 0) {
        elements.userMenuBaseSelect.selectedIndex = 0;
    }
}

function updateDirectAssignSelect(preferredMenuId) {
    if (!elements.directAssignMenuSelect) return;
    elements.directAssignMenuSelect.innerHTML = '';

    if (!state.richMenus || state.richMenus.length === 0) {
        elements.directAssignMenuSelect.innerHTML = '<option value="">（作成済みリッチメニューがありません）</option>';
        updateDirectAssignPreview();
        return;
    }

    let foundMatch = false;
    state.richMenus.forEach(m => {
        const isLive = (m.is_active == 1);
        const opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = (isLive ? '★ [全社公開中] ' : '') + m.title + ` (ボタン${(m.areas || []).length}個)`;
        if (preferredMenuId && (String(m.id) === String(preferredMenuId) || String(m.line_menu_id) === String(preferredMenuId))) {
            opt.selected = true;
            foundMatch = true;
        }
        elements.directAssignMenuSelect.appendChild(opt);
    });

    if (!foundMatch && state.richMenus.length > 0) {
        const defaultMenu = state.richMenus.find(m => m.is_active == 1) || state.richMenus[0];
        if (defaultMenu) {
            elements.directAssignMenuSelect.value = defaultMenu.id;
        }
    }

    updateDirectAssignPreview();
}

function updateDirectAssignPreview() {
    if (!elements.directAssignMenuSelect) return;
    const menuId = elements.directAssignMenuSelect.value;
    const menu = state.richMenus.find(m => String(m.id) === String(menuId));

    if (!menu) {
        if (elements.directAssignMenuPreviewImg) elements.directAssignMenuPreviewImg.src = '';
        if (elements.directAssignMenuMeta) elements.directAssignMenuMeta.innerHTML = '<span style="color:#94a3b8;">リッチメニューが選択されていません</span>';
        return;
    }

    if (elements.directAssignMenuPreviewImg) {
        elements.directAssignMenuPreviewImg.src = menu.image_url || menu.base_image_url || '';
    }
    if (elements.directAssignMenuMeta) {
        const isLiveBadge = (menu.is_active == 1) ? '<span style="color:#059669; font-weight:700;">★ LINE公式全体のデフォルト公開中</span>' : '<span style="color:#64748b;">個別専用/下書き</span>';
        const buttonCount = (menu.areas || []).length;
        elements.directAssignMenuMeta.innerHTML = `
            <strong>${escapeHtml(menu.title)}</strong> （${menu.width || 2500} × ${menu.height || 1686}px / アクション枠: ${buttonCount}箇所）<br>
            <span style="font-size: 11px;">状態: ${isLiveBadge} | LINE Menu ID: <code>${escapeHtml(menu.line_menu_id || '未発行')}</code></span>
        `;
    }
}

function switchUserMenuTab(mode) {
    if (mode === 'existing') {
        if (elements.tabModeExistingMenu) elements.tabModeExistingMenu.classList.add('active');
        if (elements.tabModeCustomMessage) elements.tabModeCustomMessage.classList.remove('active');
        if (elements.sectionExistingMenu) elements.sectionExistingMenu.style.display = 'block';
        if (elements.sectionCustomMessageMenu) elements.sectionCustomMessageMenu.style.display = 'none';
        if (elements.applyDirectMenuBtn) elements.applyDirectMenuBtn.style.display = 'inline-flex';
        if (elements.applyUserMenuBtn) elements.applyUserMenuBtn.style.display = 'none';
    } else {
        if (elements.tabModeExistingMenu) elements.tabModeExistingMenu.classList.remove('active');
        if (elements.tabModeCustomMessage) elements.tabModeCustomMessage.classList.add('active');
        if (elements.sectionExistingMenu) elements.sectionExistingMenu.style.display = 'none';
        if (elements.sectionCustomMessageMenu) elements.sectionCustomMessageMenu.style.display = 'block';
        if (elements.applyDirectMenuBtn) elements.applyDirectMenuBtn.style.display = 'none';
        if (elements.applyUserMenuBtn) elements.applyUserMenuBtn.style.display = 'inline-flex';
        renderUserMenuPreview();
    }
}

async function checkUserRealtimeMenuStatus(userId) {
    if (!elements.userMenuRealtimeCard) return;

    if (elements.realtimeMenuThumb) elements.realtimeMenuThumb.style.display = 'none';
    if (elements.realtimeMenuBadge) {
        elements.realtimeMenuBadge.className = 'badge-menu-status badge-menu-default';
        elements.realtimeMenuBadge.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 確認中...';
    }
    if (elements.realtimeMenuTitle) {
        elements.realtimeMenuTitle.textContent = 'LINE公式アカウント側の表示状況をリアルタイム確認中...';
    }
    if (elements.btnUnlinkMenuBtn) {
        elements.btnUnlinkMenuBtn.style.display = 'none';
    }

    try {
        const res = await fetch(`../api.php?action=admin_get_user_richmenu_status&password=${encodeURIComponent(state.password)}&uid=${encodeURIComponent(userId)}`);
        const data = await res.json();

        if (data.success) {
            const status = data.status || 'default';
            const title = data.title || '全体共通メニュー';
            const imgUrl = data.image_url || '';

            if (elements.realtimeMenuTitle) {
                elements.realtimeMenuTitle.innerHTML = `<strong>${escapeHtml(title)}</strong> ${data.rich_menu_id ? `<span style="font-size: 11px; font-weight: normal; color: #64748b;">(ID: ${escapeHtml(data.rich_menu_id)})</span>` : ''}`;
            }

            if (elements.realtimeMenuThumb) {
                if (imgUrl) {
                    elements.realtimeMenuThumb.src = imgUrl;
                    elements.realtimeMenuThumb.style.display = 'block';
                } else {
                    elements.realtimeMenuThumb.style.display = 'none';
                }
            }

            if (elements.realtimeMenuBadge) {
                if (status === 'custom_message') {
                    elements.realtimeMenuBadge.className = 'badge-menu-status badge-menu-custom';
                    elements.realtimeMenuBadge.innerHTML = '<i class="fa-solid fa-bolt"></i> 専用メッセージ中';
                } else if (status === 'custom_assigned') {
                    elements.realtimeMenuBadge.className = 'badge-menu-status badge-menu-assigned';
                    elements.realtimeMenuBadge.innerHTML = '<i class="fa-solid fa-tag"></i> 個別割当';
                } else {
                    elements.realtimeMenuBadge.className = 'badge-menu-status badge-menu-default';
                    elements.realtimeMenuBadge.innerHTML = '<i class="fa-solid fa-globe"></i> 全体共通';
                }
            }

            // 個別リンクがある場合は「全体共通に戻す」ボタンを表示
            if (elements.btnUnlinkMenuBtn) {
                elements.btnUnlinkMenuBtn.style.display = data.has_custom_link ? 'inline-flex' : 'none';
            }

            // 既存メニュー指定セレクタの選択状態を更新
            if (data.menu_id) {
                updateDirectAssignSelect(data.menu_id);
            }
        } else {
            if (elements.realtimeMenuTitle) {
                elements.realtimeMenuTitle.textContent = data.error || 'LINEメニュー状態の取得に失敗しました';
            }
        }
    } catch (e) {
        console.error('Error fetching user richmenu status:', e);
        if (elements.realtimeMenuTitle) {
            elements.realtimeMenuTitle.textContent = 'LINEメニュー状態の取得中に通信エラーが発生しました';
        }
    }
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

    const avatarWrap = document.getElementById('modalCustAvatarWrap');
    if (avatarWrap) {
        if (cust.picture_url) {
            avatarWrap.innerHTML = `<img src="${escapeHtml(cust.picture_url)}" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.15);" alt="">`;
        } else {
            avatarWrap.innerHTML = `<div style="width: 48px; height: 48px; border-radius: 50%; background: #e2e8f0; color: #64748b; display: flex; align-items: center; justify-content: center; font-size: 18px;"><i class="fa-solid fa-user"></i></div>`;
        }
    }

    elements.pillOil.innerHTML = `次回オイル: <strong>${cust.oil_next_date || '未定'}</strong>`;
    elements.pillPeriodic.innerHTML = `12ヶ月点検: <strong>${cust.periodic_insp_next_date || '未定'}</strong>`;
    elements.pillInsp.innerHTML = `車検満了: <strong>${cust.inspection_next_date || '未定'}</strong>`;

    // 適用中ステータスバー（メッセージ帯案内）
    if (elements.userMenuStatusAlert) {
        if (cust.custom_line_menu_id && cust.custom_menu_text) {
            elements.userMenuStatusAlert.style.display = 'flex';
            if (elements.currentCustomText) elements.currentCustomText.textContent = cust.custom_menu_text;
        } else {
            elements.userMenuStatusAlert.style.display = 'none';
        }
    }

    // 初期タブを「既存メニューから選んで指定」にする
    switchUserMenuTab('existing');

    // リッチメニュー一覧が未取得なら取得
    if (!state.richMenus || state.richMenus.length === 0) {
        await loadRichMenus();
    } else {
        updateBaseMenuSelect();
        updateDirectAssignSelect(cust.custom_line_menu_id);
    }

    // 各種チップの残日数バッジと直近チップの表示更新
    updateChipBadges(cust);

    // メッセージ入力の初期値（保存済みメッセージがあれば優先、なければ最も期限の近い点検メッセージを自動セット）
    const nearest = getNearestMaintenanceInfo(cust);
    if (cust.custom_menu_text) {
        elements.userMenuTextInput.value = cust.custom_menu_text;
    } else if (nearest) {
        elements.userMenuTextInput.value = nearest.phrase;
        if (elements.userMenuThemeSelect && nearest.theme) {
            elements.userMenuThemeSelect.value = nearest.theme;
        }
    } else {
        elements.userMenuTextInput.value = getDefaultCustomPhrase(cust, 'insp');
    }

    // LINEリアルタイム表示ステータスの確認実行
    checkUserRealtimeMenuStatus(cust.user_id);

    elements.userRichMenuModal.classList.add('active');

    loadAndRenderUserMenuBaseImage();
}

function closeUserRichMenuModal() {
    elements.userRichMenuModal.classList.remove('active');
    state.activeUserMenuCust = null;
    state.loadedBaseImg = null;
}

function updateChipBadges(cust) {
    const nearest = getNearestMaintenanceInfo(cust);
    if (elements.chipCustAutoNearest) {
        if (nearest) {
            const diffStr = nearest.diffDays < 0 ? '期限切れ' : (nearest.diffDays === 0 ? '本日！' : `あと${nearest.diffDays}日`);
            elements.chipCustAutoNearest.innerHTML = `<i class="fa-solid fa-wand-magic-sparkles" style="color: #6366f1;"></i> 最も近い点検: <strong>${escapeHtml(nearest.shortLabel)} (${diffStr})</strong>`;
            elements.chipCustAutoNearest.style.background = '#eef2ff';
            elements.chipCustAutoNearest.style.borderColor = '#c7d2fe';
        } else {
            elements.chipCustAutoNearest.innerHTML = `<i class="fa-solid fa-wand-magic-sparkles" style="color: #6366f1;"></i> 最も期限が近い点検項目を自動選択`;
            elements.chipCustAutoNearest.style.background = '';
            elements.chipCustAutoNearest.style.borderColor = '';
        }
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const formatDiff = (dateStr) => {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        d.setHours(0, 0, 0, 0);
        const diff = Math.ceil((d - today) / (1000 * 60 * 60 * 24));
        return diff < 0 ? ' <span class="chip-badge-nearest" style="background:#ef4444;">期限切</span>'
             : (diff === 0 ? ' <span class="chip-badge-nearest" style="background:#ef4444;">本日</span>'
             : ` <span class="chip-badge-nearest">あと${diff}日</span>`);
    };

    if (elements.chipCustInsp) {
        elements.chipCustInsp.innerHTML = `<i class="fa-solid fa-shield-halved" style="color: #e11d48;"></i> 次回車検${formatDiff(cust.inspection_next_date)}`;
    }
    if (elements.chipCustOil) {
        elements.chipCustOil.innerHTML = `<i class="fa-solid fa-oil-can" style="color: #f59e0b;"></i> 次回オイル${formatDiff(cust.oil_next_date)}`;
    }
    if (elements.chipCustPeriodic) {
        elements.chipCustPeriodic.innerHTML = `<i class="fa-solid fa-clipboard-check" style="color: #059669;"></i> 12ヶ月点検${formatDiff(cust.periodic_insp_next_date)}`;
    }
}

function applyNearestPhraseToCustomMenu() {
    if (!state.activeUserMenuCust) return;
    const nearest = getNearestMaintenanceInfo(state.activeUserMenuCust);
    if (nearest) {
        elements.userMenuTextInput.value = nearest.phrase;
        if (elements.userMenuThemeSelect && nearest.theme) {
            elements.userMenuThemeSelect.value = nearest.theme;
        }
        renderUserMenuPreview();
        showToast(`✨ 最も期限が近い「${nearest.shortLabel}」のメッセージをセットしました！`);
    } else {
        showToast('⚠️ 点検日が入力されていません');
    }
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
    const normalMenus = (state.richMenus || []).filter(m => !m.is_notice || m.is_notice == 0);
    const fallback = normalMenus.find(m => m.is_active == 1) || normalMenus[0] || state.richMenus[0] || null;
    if (!baseId) return fallback;
    return state.richMenus.find(m => String(m.id) === String(baseId)) || fallback;
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

    const actionType = elements.userMenuBannerActionType ? elements.userMenuBannerActionType.value : 'mycar_liff';
    const showTapHint = elements.userMenuShowTapHint ? elements.userMenuShowTapHint.checked : true;
    const hasTapAction = actionType !== 'none';

    // 帯の高さ計算（文字サイズ・行数・帯の太さ・タップ案内設定を考慮）
    let bannerH;
    const extraHintH = (hasTapAction && showTapHint) ? Math.round(fontSize * 0.75) : 0;
    if (heightSetting === 'compact') {
        bannerH = Math.max(Math.round(fontSize * 1.5) + extraHintH, 140);
    } else if (heightSetting === 'standard') {
        bannerH = Math.max(Math.round(fontSize * 1.8) + extraHintH, 185);
    } else if (heightSetting === 'wide') {
        bannerH = Math.max(Math.round(fontSize * 2.2) + extraHintH, 240);
    } else {
        // auto: 行数とフォントサイズに応じて余白を最適化
        const lineSpacing = fontSize * 1.32;
        const textBlockH = (lines.length * lineSpacing) + extraHintH;
        bannerH = Math.max(150, Math.round(textBlockH + (fontSize * 0.95)));
    }

    const bannerY = (pos === 'top') ? 0 : (h - bannerH);

    // バナーの座標をstateに保存（適用時にAPIへ送信）
    state.userMenuBannerBounds = {
        x: 0,
        y: bannerY,
        width: w,
        height: bannerH
    };

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
    const totalLinesH = (lines.length - 1) * lineSpacing;
    // タップガイド表示がある場合は少し上寄りに配置
    const shiftY = (hasTapAction && showTapHint) ? -Math.round(extraHintH * 0.4) : 0;
    const startY = ((bannerY + bannerH / 2) - (totalLinesH / 2)) + shiftY;

    lines.forEach((line, idx) => {
        const lineY = startY + (idx * lineSpacing);
        ctx.fillText(line, w / 2, lineY, w - 120);
    });

    // タップ誘導ガイド（タップアクション有効時）
    if (hasTapAction && showTapHint) {
        let hintLabel = '👆 タップして詳細を見る';
        if (actionType === 'mycar_liff' || actionType === 'open_mycar') {
            hintLabel = '👆 タップして点検・予約を開く';
        } else if (actionType === 'search_all') {
            hintLabel = '👆 タップして在庫車両を見る';
        } else if (actionType === 'notice') {
            hintLabel = '👆 タップしてお知らせを見る';
        } else if (actionType === 'uri') {
            hintLabel = '👆 タップしてリンクを開く';
        }

        const hintFontSize = Math.max(26, Math.round(fontSize * 0.48));
        ctx.font = `bold ${hintFontSize}px "Noto Sans JP", -apple-system, sans-serif`;
        const hintY = startY + totalLinesH + (fontSize * 0.95);

        // 半透明ピル背景
        const textWidth = ctx.measureText(hintLabel).width;
        const pillW = textWidth + 40;
        const pillH = hintFontSize * 1.5;
        ctx.fillStyle = 'rgba(0, 0, 0, 0.28)';
        ctx.beginPath();
        const pillX = (w - pillW) / 2;
        const pillY = hintY - (pillH / 2);
        ctx.roundRect ? ctx.roundRect(pillX, pillY, pillW, pillH, pillH / 2) : ctx.rect(pillX, pillY, pillW, pillH);
        ctx.fill();

        ctx.fillStyle = 'rgba(255, 255, 255, 0.95)';
        ctx.fillText(hintLabel, w / 2, hintY);
    }

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
        formData.append('base_menu_id', base.id || '');
        formData.append('base_line_menu_id', base.line_menu_id || '');
        formData.append('base_areas', JSON.stringify(base.areas || []));
        formData.append('custom_text', text);
        formData.append('image', blob, 'custom_menu.jpg');

        // メッセージ帯タップ時のアクション設定
        const actionType = elements.userMenuBannerActionType ? elements.userMenuBannerActionType.value : 'mycar_liff';
        const actionUri = elements.userMenuBannerUriInput ? elements.userMenuBannerUriInput.value.trim() : '';
        const actionPostback = elements.userMenuBannerPostbackInput ? elements.userMenuBannerPostbackInput.value.trim() : '';
        const bannerBounds = state.userMenuBannerBounds || { x: 0, y: 0, width: base.width || 2500, height: 200 };

        formData.append('banner_action_type', actionType);
        formData.append('banner_action_uri', actionUri);
        formData.append('banner_action_postback', actionPostback);
        formData.append('banner_bounds', JSON.stringify(bannerBounds));

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

async function applyDirectRichMenu() {
    const cust = state.activeUserMenuCust;
    if (!cust || !cust.user_id) return;

    if (!elements.directAssignMenuSelect) return;
    const menuId = elements.directAssignMenuSelect.value;
    if (!menuId) {
        alert('適用するリッチメニューを選択してください');
        return;
    }

    const menu = state.richMenus.find(m => String(m.id) === String(menuId));
    const menuTitle = menu ? menu.title : '選択したリッチメニュー';

    if (!confirm(`【${cust.user_name || 'お客様'} 様】に、リッチメニュー「${menuTitle}」を個別に指定・適用しますか？\n（LINE画面下部が即座に切り替わります）`)) {
        return;
    }

    const btn = elements.applyDirectMenuBtn;
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> LINEに適用中...';
    }

    try {
        const payload = new URLSearchParams({
            action: 'admin_assign_richmenu_to_user',
            password: state.password,
            uid: cust.user_id,
            menu_id: menuId
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message || `「${menuTitle}」を友だちに適用しました！`);
            closeUserRichMenuModal();
            await fetchCustomers();
        } else {
            alert(data.error || '適用に失敗しました');
        }
    } catch (e) {
        console.error('Apply direct menu error:', e);
        alert('通信エラーが発生しました: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
}

async function unlinkUserRichMenu() {
    const cust = state.activeUserMenuCust;
    if (!cust || !cust.user_id) return;

    if (!confirm(`【${cust.user_name || 'お客様'} 様】の個別リッチメニュー設定を解除し、全体共通メニューに戻しますか？`)) {
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
            if (elements.userMenuStatusAlert) elements.userMenuStatusAlert.style.display = 'none';
            if (cust) {
                cust.custom_line_menu_id = '';
                cust.current_menu_type = 'default';
            }
            // リアルタイム表示ステータスを即時再取得
            await checkUserRealtimeMenuStatus(cust.user_id);
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

// ==========================================================================
// 管理者LINE通知設定 & UIDヘルパー
// ==========================================================================

window.copyCustUid = function(uid) {
    if (!uid) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(uid).then(() => {
            showToast('📋 LINE User ID をコピーしました');
        }).catch(() => {
            prompt('以下のLINE User IDをコピーしてください:', uid);
        });
    } else {
        prompt('以下のLINE User IDをコピーしてください:', uid);
    }
};

window.addAdminUidDirectly = function(uid, name) {
    if (!uid || !uid.startsWith('U')) {
        alert('有効なLINE User ID（Uから始まる33桁）ではありません。');
        return;
    }
    openAdminLineSettingsModal().then(() => {
        const textarea = elements.adminLineUidsInput;
        if (!textarea) return;
        const currentVal = textarea.value.trim();
        const lines = currentVal ? currentVal.split('\n').map(l => l.trim()).filter(l => l) : [];
        if (!lines.includes(uid)) {
            lines.push(uid);
            textarea.value = lines.join('\n');
            showToast(`🔔 【${name || '管理者'} 様】のUIDを追加しました。モーダル右下の「設定を保存する」を押してください。`);
        } else {
            showToast(`ℹ️ このUIDはすでに通知先リストに含まれています。`);
        }
        textarea.focus();
    });
};

function quickAddAdminUidFromEdit() {
    const uid = elements.editUserUid ? elements.editUserUid.value.trim() : '';
    const name = elements.editUserName ? elements.editUserName.value.trim() : '';
    if (!uid || !uid.startsWith('U')) {
        alert('この顧客データには有効なLINE User ID（Uから始まる33桁）がありません。\nLINE友だち登録済みの顧客から追加してください。');
        return;
    }
    window.addAdminUidDirectly(uid, name);
}

async function openAdminLineSettingsModal() {
    if (!elements.adminLineSettingsModal) return;
    elements.adminLineSettingsModal.style.display = 'flex';
    requestAnimationFrame(() => {
        elements.adminLineSettingsModal.classList.add('active');
    });
    
    if (elements.adminLineSaveStatus) {
        elements.adminLineSaveStatus.textContent = '設定を読み込み中...';
        elements.adminLineSaveStatus.style.color = '#64748b';
    }

    try {
        const res = await fetch(`../api.php?action=admin_get_line_notification_settings&password=${encodeURIComponent(state.password)}`);
        const data = await res.json();
        
        if (data.success && data.settings) {
            const s = data.settings;
            if (elements.adminLineUidsInput) {
                const rawArr = s.admin_line_uids || s.admin_uids || [];
                const uids = Array.isArray(rawArr) ? rawArr : [];
                elements.adminLineUidsInput.value = uids.join('\n');
            }
            if (elements.notifyInquiryCheck) elements.notifyInquiryCheck.checked = Boolean(s.notify_inquiry);
            if (elements.notifyBookingCheck) elements.notifyBookingCheck.checked = Boolean(s.notify_booking);
            if (elements.notifyNewCustomerCheck) elements.notifyNewCustomerCheck.checked = Boolean(s.notify_new_customer);
            if (elements.notifyNewCarsCheck) elements.notifyNewCarsCheck.checked = Boolean(s.notify_new_cars);
            if (elements.notifyReminderCheck) elements.notifyReminderCheck.checked = Boolean(s.notify_reminder);

            if (elements.adminLineSaveStatus) {
                elements.adminLineSaveStatus.textContent = '';
            }
        } else {
            if (elements.adminLineSaveStatus) {
                elements.adminLineSaveStatus.textContent = data.error || '設定の取得に失敗しました';
                elements.adminLineSaveStatus.style.color = '#ef4444';
            }
        }
    } catch (e) {
        console.error('Failed to load admin line settings:', e);
        if (elements.adminLineSaveStatus) {
            elements.adminLineSaveStatus.textContent = '通信エラーが発生しました';
            elements.adminLineSaveStatus.style.color = '#ef4444';
        }
    }
}

function closeAdminLineSettingsModal() {
    if (elements.adminLineSettingsModal) {
        elements.adminLineSettingsModal.classList.remove('active');
        setTimeout(() => {
            if (elements.adminLineSettingsModal && !elements.adminLineSettingsModal.classList.contains('active')) {
                elements.adminLineSettingsModal.style.display = 'none';
            }
        }, 200);
    }
}

async function saveAdminLineSettings() {
    const btn = elements.saveAdminLineSettingsBtn;
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 保存中...';
    }

    const rawUids = elements.adminLineUidsInput ? elements.adminLineUidsInput.value : '';
    const formData = new FormData();
    formData.append('password', state.password);
    formData.append('admin_line_uids', rawUids);
    formData.append('notify_inquiry', elements.notifyInquiryCheck && elements.notifyInquiryCheck.checked ? '1' : '0');
    formData.append('notify_booking', elements.notifyBookingCheck && elements.notifyBookingCheck.checked ? '1' : '0');
    formData.append('notify_new_customer', elements.notifyNewCustomerCheck && elements.notifyNewCustomerCheck.checked ? '1' : '0');
    formData.append('notify_new_cars', elements.notifyNewCarsCheck && elements.notifyNewCarsCheck.checked ? '1' : '0');
    formData.append('notify_reminder', elements.notifyReminderCheck && elements.notifyReminderCheck.checked ? '1' : '0');

    try {
        const res = await fetch('../api.php?action=admin_save_line_notification_settings', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast('✅ 管理者LINE通知設定を保存しました！');
            if (elements.adminLineSaveStatus) {
                elements.adminLineSaveStatus.textContent = `保存完了 (${new Date().toLocaleTimeString('ja-JP')})`;
                elements.adminLineSaveStatus.style.color = '#059669';
            }
            if (data.settings && elements.adminLineUidsInput) {
                elements.adminLineUidsInput.value = (data.settings.admin_line_uids || []).join('\n');
            }
        } else {
            alert('保存に失敗しました: ' + (data.error || ''));
            if (elements.adminLineSaveStatus) {
                elements.adminLineSaveStatus.textContent = data.error || '保存エラー';
                elements.adminLineSaveStatus.style.color = '#ef4444';
            }
        }
    } catch (e) {
        console.error('Save admin line settings error:', e);
        alert('通信エラーが発生しました: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
}

async function testAdminLineNotification() {
    const rawUids = elements.adminLineUidsInput ? elements.adminLineUidsInput.value.trim() : '';
    if (!rawUids) {
        alert('テスト送信する管理者LINE User ID（U...）を入力してください。');
        return;
    }

    const btn = elements.testAdminLineNotificationBtn;
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 送信中...';
    }

    const formData = new FormData();
    formData.append('password', state.password);
    formData.append('admin_line_uids', rawUids);

    try {
        const res = await fetch('../api.php?action=admin_test_line_notification', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            const results = data.results || [];
            const successCount = results.filter(r => r.success).length;
            const failCount = results.length - successCount;

            let msg = `【テスト送信結果】\n成功: ${successCount} 件 / 失敗: ${failCount} 件\n\n`;
            results.forEach(r => {
                msg += `・UID: ${r.uid.substring(0, 10)}... → ${r.success ? '✅ 送信成功' : '❌ 失敗: ' + (r.error || '')}\n`;
            });
            alert(msg);
            showToast(`🔔 テスト通知を送信しました (${successCount}件成功)`);
        } else {
            alert('テスト送信に失敗しました: ' + (data.error || ''));
        }
    } catch (e) {
        console.error('Test admin notification error:', e);
        alert('通信エラーが発生しました: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
}

// ==========================================
// プロライン (ProLine) Webhook中継・連携設定
// ==========================================

async function openProlineSettingsModal() {
    if (!elements.prolineSettingsModal) return;
    elements.prolineSettingsModal.style.display = 'flex';
    if (elements.prolineSaveStatus) elements.prolineSaveStatus.textContent = '';
    if (elements.prolineTestResultBanner) elements.prolineTestResultBanner.style.display = 'none';

    // 本システムのWebhook URL（LINE Developersに登録するURL）を自動生成表示
    if (elements.displayOurWebhookUrl) {
        const fullUrl = window.location.origin + window.location.pathname.replace(/\/admin\/.*$/, '/webhook.php');
        elements.displayOurWebhookUrl.textContent = fullUrl;
    }

    await loadProlineSettings();
}

function closeProlineSettingsModal() {
    if (elements.prolineSettingsModal) {
        elements.prolineSettingsModal.style.display = 'none';
    }
}

async function loadProlineSettings() {
    if (!elements.prolineRecentLogsWrap) return;
    try {
        elements.prolineRecentLogsWrap.textContent = '設定と中継ログを読み込み中...';
        const res = await fetch(`../api.php?action=admin_get_proline_settings&password=${encodeURIComponent(state.password)}`);
        const data = await res.json();
        if (data.success) {
            if (elements.prolineWebhookUrlInput) {
                elements.prolineWebhookUrlInput.value = data.settings.webhook_url || '';
            }
            if (elements.prolineRelayEnabledCheck) {
                elements.prolineRelayEnabledCheck.checked = Boolean(data.settings.relay_enabled);
            }
            
            if (data.recent_logs && data.recent_logs.length > 0) {
                elements.prolineRecentLogsWrap.textContent = data.recent_logs.join('\n');
            } else {
                elements.prolineRecentLogsWrap.textContent = 'まだ転送ログはありません。LINEメッセージや友だち追加があると記録されます。';
            }
        } else {
            showToast('プロライン設定の読み込みに失敗しました: ' + (data.error || ''));
        }
    } catch (e) {
        elements.prolineRecentLogsWrap.textContent = '通信エラーが発生しました';
    }
}

async function saveProlineSettings() {
    const url = elements.prolineWebhookUrlInput ? elements.prolineWebhookUrlInput.value.trim() : '';
    const enabled = elements.prolineRelayEnabledCheck && elements.prolineRelayEnabledCheck.checked ? 1 : 0;

    try {
        if (elements.saveProlineSettingsBtn) elements.saveProlineSettingsBtn.disabled = true;
        if (elements.prolineSaveStatus) elements.prolineSaveStatus.textContent = '保存中...';

        const payload = new URLSearchParams({
            action: 'admin_save_proline_settings',
            password: state.password,
            url: url,
            relay_enabled: enabled
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        if (data.success) {
            if (elements.prolineSaveStatus) elements.prolineSaveStatus.textContent = '✅ 設定を保存しました！';
            showToast('✅ プロライン連携設定を保存しました');
            setTimeout(() => {
                if (elements.prolineSaveStatus) elements.prolineSaveStatus.textContent = '';
            }, 3000);
        } else {
            alert('保存に失敗しました: ' + (data.error || ''));
            if (elements.prolineSaveStatus) elements.prolineSaveStatus.textContent = '❌ 保存エラー';
        }
    } catch (e) {
        alert('通信エラーが発生しました');
        if (elements.prolineSaveStatus) elements.prolineSaveStatus.textContent = '❌ 通信エラー';
    } finally {
        if (elements.saveProlineSettingsBtn) elements.saveProlineSettingsBtn.disabled = false;
    }
}

async function testProlineRelay() {
    const url = elements.prolineWebhookUrlInput ? elements.prolineWebhookUrlInput.value.trim() : '';
    if (!url) {
        alert('転送先のプロラインWebhook URLを入力してください');
        return;
    }

    const btn = elements.testProlineRelayBtn;
    const banner = elements.prolineTestResultBanner;

    try {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> テスト中...';
        }
        if (banner) {
            banner.style.display = 'block';
            banner.style.background = '#f1f5f9';
            banner.style.color = '#475569';
            banner.style.border = '1px solid #cbd5e1';
            banner.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> プロラインへテストWebhook（Pingペイロード）を転送しています...';
        }

        const payload = new URLSearchParams({
            action: 'admin_test_proline_relay',
            password: state.password,
            url: url
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success) {
            if (banner) {
                banner.style.background = '#ecfdf5';
                banner.style.color = '#065f46';
                banner.style.border = '1px solid #a7f3d0';
                banner.innerHTML = `<strong>${escapeHtml(data.message)}</strong><br><span style="font-size:11px;">応答所要時間: ${data.duration_ms}ms / HTTPステータス: ${data.http_code}</span>`;
            }
            showToast('✅ プロライン疎通テスト成功！');
            loadProlineSettings();
        } else {
            if (banner) {
                banner.style.background = '#fef2f2';
                banner.style.color = '#991b1b';
                banner.style.border = '1px solid #fecaca';
                banner.innerHTML = `<strong>⚠️ 疎通エラー: ${escapeHtml(data.message || data.error)}</strong><br><span style="font-size:11px;">詳細: ${escapeHtml(data.error || '')} (HTTP: ${data.http_code || 0})</span>`;
            }
            showToast('⚠️ 疎通テスト失敗');
        }
    } catch (e) {
        if (banner) {
            banner.style.background = '#fef2f2';
            banner.style.color = '#991b1b';
            banner.style.border = '1px solid #fecaca';
            banner.innerHTML = '<strong>❌ 通信エラーが発生しました</strong>';
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-rotate"></i> 疎通テスト';
        }
    }
}


