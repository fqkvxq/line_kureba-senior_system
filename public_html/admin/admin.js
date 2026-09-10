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
    userMenuBannerBounds: null,
    // マルチアカウント管理
    activeAccount: localStorage.getItem('active_line_account') || 'senior',
    accounts: [],
    activeAccountInfo: null
};

// APIリクエストに現在のアクティブアカウントパラメータを自動付与するfetchインターセプター
const originalFetch = window.fetch;
window.fetch = function (resource, init = {}) {
    let url = (typeof resource === 'string') ? resource : (resource && resource.url ? resource.url : '');
    if (url.includes('api.php') && state.activeAccount) {
        const acc = state.activeAccount;
        if (!url.includes('account=')) {
            url += (url.includes('?') ? '&' : '?') + 'account=' + encodeURIComponent(acc);
            if (typeof resource === 'string') {
                resource = url;
            }
        }
        // POSTボディにURLSearchParamsがある場合もaccountを付与
        if (init && init.body && init.body instanceof URLSearchParams && !init.body.has('account')) {
            init.body.append('account', acc);
        }
    }
    return originalFetch.call(this, resource, init);
};

const elements = {
    loginModal: document.getElementById('loginModal'),
    adminPasswordInput: document.getElementById('adminPasswordInput'),
    loginBtn: document.getElementById('loginBtn'),
    loginErrorMsg: document.getElementById('loginErrorMsg'),
    adminApp: document.getElementById('adminApp'),
    logoutBtn: document.getElementById('logoutBtn'),

    // アカウント切替
    accountSelect: document.getElementById('accountSelect'),
    accountBadgeDot: document.getElementById('accountBadgeDot'),
    systemBrandTitle: document.getElementById('systemBrandTitle'),
    systemBrandBadge: document.getElementById('systemBrandBadge'),

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
    userMenuFontSizeNumber: document.getElementById('userMenuFontSizeNumber'),
    userMenuFontSizeVal: document.getElementById('userMenuFontSizeVal'),
    userMenuBgColorPicker: document.getElementById('userMenuBgColorPicker'),
    userMenuBgColorHex: document.getElementById('userMenuBgColorHex'),
    userMenuTextColorPicker: document.getElementById('userMenuTextColorPicker'),
    userMenuTextColorHex: document.getElementById('userMenuTextColorHex'),
    userMenuGradientToggle: document.getElementById('userMenuGradientToggle'),
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
    prolineCalendarUrlInput: document.getElementById('prolineCalendarUrlInput'),
    prolineRelayEnabledCheck: document.getElementById('prolineRelayEnabledCheck'),
    prolineTestResultBanner: document.getElementById('prolineTestResultBanner'),
    prolineRecentLogsWrap: document.getElementById('prolineRecentLogsWrap'),
    prolineSaveStatus: document.getElementById('prolineSaveStatus'),
    btnRefreshProlineLogs: document.getElementById('btnRefreshProlineLogs'),
    displayOurWebhookUrl: document.getElementById('displayOurWebhookUrl'),

    // シニアお役立ち情報 配信スタジオ モーダル
    openKnowledgeBroadcastModalBtn: document.getElementById('openKnowledgeBroadcastModalBtn'),
    knowledgeBroadcastModal: document.getElementById('knowledgeBroadcastModal'),
    closeKnowledgeBroadcastModalBtn: document.getElementById('closeKnowledgeBroadcastModalBtn'),
    cancelKnowledgeBroadcastBtn: document.getElementById('cancelKnowledgeBroadcastBtn'),
    submitKnowledgeBroadcastBtn: document.getElementById('submitKnowledgeBroadcastBtn'),
    kbPresetChipsWrap: document.getElementById('kbPresetChipsWrap'),
    kbUserSelectWrap: document.getElementById('kbUserSelectWrap'),
    kbTargetUserSelect: document.getElementById('kbTargetUserSelect'),
    kbCategoryInput: document.getElementById('kbCategoryInput'),
    kbColorSelect: document.getElementById('kbColorSelect'),
    kbTitleInput: document.getElementById('kbTitleInput'),
    kbSubtitleInput: document.getElementById('kbSubtitleInput'),
    kbPoint1: document.getElementById('kbPoint1'),
    kbPoint2: document.getElementById('kbPoint2'),
    kbPoint3: document.getElementById('kbPoint3'),
    kbAdviceInput: document.getElementById('kbAdviceInput'),
    kbBtn1Label: document.getElementById('kbBtn1Label'),
    kbBtn1Url: document.getElementById('kbBtn1Url'),
    kbTargetSummaryText: document.getElementById('kbTargetSummaryText'),

    // プレビュー要素
    prevKbBadge: document.getElementById('prevKbBadge'),
    prevKbTitle: document.getElementById('prevKbTitle'),
    prevKbSubtitle: document.getElementById('prevKbSubtitle'),
    prevKbPoint1: document.getElementById('prevKbPoint1'),
    prevKbPoint2: document.getElementById('prevKbPoint2'),
    prevKbPoint3: document.getElementById('prevKbPoint3'),
    prevKbPointIcon1: document.getElementById('prevKbPointIcon1'),
    prevKbPointIcon2: document.getElementById('prevKbPointIcon2'),
    prevKbPointIcon3: document.getElementById('prevKbPointIcon3'),
    prevKbAdviceBox: document.getElementById('prevKbAdviceBox'),
    prevKbAdviceText: document.getElementById('prevKbAdviceText'),
    prevKbBtn1: document.getElementById('prevKbBtn1'),
    prevKbBtn2: document.getElementById('prevKbBtn2'),

    toast: document.getElementById('adminToast')
};

document.addEventListener('DOMContentLoaded', async () => {
    await loadAccounts();
    initAuth();
    initEventListeners();
    initKnowledgeBroadcastStudio();
    initAccountManagement();
});

async function loadAccounts() {
    try {
        const savedAccount = localStorage.getItem('active_line_account') || '';
        const url = `../api.php?action=get_accounts${savedAccount ? '&account=' + encodeURIComponent(savedAccount) : ''}`;
        const res = await fetch(url);
        const data = await res.json();
        if (data.success && Array.isArray(data.accounts)) {
            state.accounts = data.accounts;
            state.activeAccount = data.active_account || 'senior';
            state.activeAccountInfo = data.active_account_info || null;
            localStorage.setItem('active_line_account', state.activeAccount);
            renderAccountSwitcher();
            updateBrandDisplay();
        }
    } catch (e) {
        console.error('Failed to load accounts:', e);
    }
}

function renderAccountSwitcher() {
    if (!elements.accountSelect) return;
    elements.accountSelect.innerHTML = state.accounts.map(acc => {
        const isSelected = acc.id === state.activeAccount;
        const configNote = !acc.is_configured ? ' (⚠️未設定)' : '';
        return `<option value="${escapeHtml(acc.id)}" ${isSelected ? 'selected' : ''}>${escapeHtml(acc.name)}${configNote}</option>`;
    }).join('');

    const currentAcc = state.accounts.find(a => a.id === state.activeAccount);
    if (currentAcc && elements.accountBadgeDot) {
        elements.accountBadgeDot.style.background = currentAcc.theme_color || '#ff8700';
    }
}

function updateBrandDisplay() {
    const currentAcc = state.accounts.find(a => a.id === state.activeAccount) || state.activeAccountInfo;
    if (currentAcc) {
        if (elements.systemBrandTitle) {
            elements.systemBrandTitle.textContent = `${currentAcc.name} 受講生管理`;
        }
        if (elements.systemBrandBadge) {
            elements.systemBrandBadge.style.background = currentAcc.theme_color || '#4f46e5';
        }
        if (elements.accountBadgeDot) {
            elements.accountBadgeDot.style.background = currentAcc.theme_color || '#ff8700';
        }
    }
}

async function handleAccountSwitch(newAccountKey) {
    if (!newAccountKey || newAccountKey === state.activeAccount) return;
    try {
        const res = await fetch('../api.php?action=switch_account', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `account=${encodeURIComponent(newAccountKey)}`
        });
        const data = await res.json();
        if (data.success) {
            state.activeAccount = data.active_account;
            state.activeAccountInfo = data.active_account_info;
            state.accounts = data.accounts || state.accounts;
            localStorage.setItem('active_line_account', state.activeAccount);
            renderAccountSwitcher();
            updateBrandDisplay();
            showToast(`🔄 「${state.activeAccountInfo ? state.activeAccountInfo.name : newAccountKey}」に切り替えました`);
            
            // 対象アカウントのデータを再取得
            state.richMenus = [];
            if (state.password) {
                await Promise.all([fetchCustomers(), loadRichMenus()]);
            }
        }
    } catch (e) {
        console.error('Account switch failed:', e);
        showToast('⚠️ アカウント切り替えに失敗しました');
    }
}

// ==============================================================================
// LINE公式アカウント管理・新規追加モーダル機能
// ==============================================================================

function initAccountManagement() {
    const btnOpen = document.getElementById('btnOpenAccountManageModal');
    const modal = document.getElementById('accountManageModal');
    const btnClose = document.getElementById('closeAccountManageModalBtn');
    const btnCloseFooter = document.getElementById('closeAccountManageModalFooterBtn');
    const tabListBtn = document.getElementById('tabAccListBtn');
    const tabNewBtn = document.getElementById('tabAccNewBtn');
    const btnBackToList = document.getElementById('btnBackToAccList');
    const btnCancelForm = document.getElementById('btnCancelAccForm');
    const btnSave = document.getElementById('btnSubmitAccountSave');
    const btnTestLine = document.getElementById('btnTestLineCredentials');
    const colorPicker = document.getElementById('accFormColorPicker');
    const colorHex = document.getElementById('accFormColorHex');
    const idInput = document.getElementById('accFormId');
    const btnCopyWebhook = document.getElementById('btnCopyAccWebhookUrl');

    if (!btnOpen || !modal) return;

    btnOpen.addEventListener('click', () => {
        openAccountManageModal();
    });

    const closeModal = () => {
        modal.classList.remove('active');
    };
    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCloseFooter) btnCloseFooter.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    if (tabListBtn) tabListBtn.addEventListener('click', showAccountListView);
    if (tabNewBtn) tabNewBtn.addEventListener('click', () => openAccountEditForm(null));
    if (btnBackToList) btnBackToList.addEventListener('click', showAccountListView);
    if (btnCancelForm) btnCancelForm.addEventListener('click', showAccountListView);

    if (colorPicker && colorHex) {
        colorPicker.addEventListener('input', () => {
            colorHex.value = colorPicker.value.toUpperCase();
        });
        colorHex.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(colorHex.value)) {
                colorPicker.value = colorHex.value;
            }
        });
    }

    if (idInput) {
        idInput.addEventListener('input', () => {
            updateDisplayWebhookUrl(idInput.value.trim().toLowerCase());
        });
    }

    if (btnCopyWebhook) {
        btnCopyWebhook.addEventListener('click', () => {
            const urlField = document.getElementById('accFormDisplayWebhookUrl');
            if (urlField && urlField.value) {
                navigator.clipboard.writeText(urlField.value).then(() => {
                    showToast('Webhook URLをコピーしました！');
                }).catch(() => {
                    urlField.select();
                    document.execCommand('copy');
                    showToast('Webhook URLをコピーしました！');
                });
            }
        });
    }

    if (btnTestLine) {
        btnTestLine.addEventListener('click', async () => {
            const token = document.getElementById('accFormAccessToken').value.trim();
            const resultBanner = document.getElementById('lineTokenTestResultBanner');
            if (!token) {
                alert('チャネルアクセストークンを入力してください');
                return;
            }

            btnTestLine.disabled = true;
            btnTestLine.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 検証中...';
            resultBanner.style.display = 'block';
            resultBanner.style.background = '#eff6ff';
            resultBanner.style.border = '1px solid #93c5fd';
            resultBanner.style.color = '#1e40af';
            resultBanner.innerHTML = 'LINE Messaging APIに接続してBot認証をテストしています...';

            try {
                const formData = new FormData();
                formData.append('channel_access_token', token);
                formData.append('password', state.password || sessionStorage.getItem('admin_pass') || '1020143');
                const res = await fetch('../api.php?action=test_line_credentials', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.success && data.bot_info) {
                    const info = data.bot_info;
                    resultBanner.style.background = '#f0fdf4';
                    resultBanner.style.border = '1px solid #86efac';
                    resultBanner.style.color = '#166534';
                    resultBanner.innerHTML = `
                        <div style="display:flex; align-items:center; gap:10px;">
                            ${info.picture_url ? `<img src="${info.picture_url}" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">` : ''}
                            <div>
                                <strong style="font-size:12.5px;">✅ 接続成功！ LINEボット名: ${escapeHtml(info.display_name)}</strong><br>
                                <span style="font-size:11px;">Basic ID: <code>${escapeHtml(info.basic_id || '-')}</code> / チャネル連携正常</span>
                            </div>
                        </div>
                    `;
                } else {
                    resultBanner.style.background = '#fff1f2';
                    resultBanner.style.border = '1px solid #fecdd3';
                    resultBanner.style.color = '#9f1239';
                    resultBanner.innerHTML = `❌ 接続失敗: ${escapeHtml(data.error || '認証に失敗しました。トークンを確認してください')}`;
                }
            } catch (e) {
                resultBanner.style.background = '#fff1f2';
                resultBanner.style.border = '1px solid #fecdd3';
                resultBanner.style.color = '#9f1239';
                resultBanner.innerHTML = `❌ 通信エラー: ${escapeHtml(e.message)}`;
            } finally {
                btnTestLine.disabled = false;
                btnTestLine.innerHTML = '<i class="fa-solid fa-satellite-dish"></i> LINE接続テスト';
            }
        });
    }

    if (btnSave) {
        btnSave.addEventListener('click', async () => {
            await submitAccountForm();
        });
    }
}

function openAccountManageModal() {
    const modal = document.getElementById('accountManageModal');
    if (!modal) return;
    modal.classList.add('active');
    showAccountListView();
    renderAccountCardsList();
}

function showAccountListView() {
    const accListView = document.getElementById('accListView');
    const accEditView = document.getElementById('accEditView');
    const tabListBtn = document.getElementById('tabAccListBtn');
    const tabNewBtn = document.getElementById('tabAccNewBtn');

    if (accListView) accListView.style.display = 'block';
    if (accEditView) accEditView.style.display = 'none';
    if (tabListBtn) tabListBtn.classList.add('active');
    if (tabNewBtn) tabNewBtn.classList.remove('active');

    renderAccountCardsList();
}

function renderAccountCardsList() {
    const wrap = document.getElementById('accountCardsListWrap');
    const badge = document.getElementById('accCountBadge');
    if (!wrap) return;

    if (badge) badge.textContent = (state.accounts || []).length;

    if (!state.accounts || state.accounts.length === 0) {
        wrap.innerHTML = '<div style="text-align:center; padding:20px; color:#64748b;">登録されたアカウントがありません</div>';
        return;
    }

    const host = window.location.host;
    const path = window.location.pathname.replace(/\/admin\/.*$/, '');

    wrap.innerHTML = state.accounts.map(acc => {
        const isActive = acc.id === state.activeAccount;
        const isDefault = !!acc.is_default;
        const isConfigured = !!acc.is_configured;
        const color = acc.theme_color || '#6366f1';
        const whUrl = `${window.location.protocol}//${host}${path}/webhook.php${isDefault ? '' : '?account=' + encodeURIComponent(acc.id)}`;

        return `
            <div class="account-card-item ${isActive ? 'is-active-acc' : ''}">
                <div class="account-card-left">
                    <div class="account-card-badge" style="background: ${escapeHtml(color)};">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div class="account-card-info">
                        <h4>
                            <span>${escapeHtml(acc.name)}</span>
                            ${isActive ? '<span style="font-size:10.5px; background:#e0e7ff; color:#3730a3; padding:1px 6px; border-radius:var(--radius-xs); font-weight:700;">★ 現在選択中</span>' : ''}
                            ${isDefault ? '<span style="font-size:10.5px; background:#fef3c7; color:#92400e; padding:1px 6px; border-radius:var(--radius-xs); font-weight:700;">標準デフォルト</span>' : ''}
                            ${!isConfigured ? '<span style="font-size:10.5px; background:#fff1f2; color:#e11d48; padding:1px 6px; border-radius:var(--radius-xs); font-weight:700;">⚠️ LINE未設定</span>' : '<span style="font-size:10.5px; background:#ecfdf5; color:#065f46; padding:1px 6px; border-radius:var(--radius-xs); font-weight:700;">✅ 連携設定済</span>'}
                        </h4>
                        <p>
                            ID: <code>${escapeHtml(acc.id)}</code> &nbsp;|&nbsp; 
                            DB: <code>${escapeHtml(acc.db_file || 'cars.db')}</code><br>
                            Webhook: <code style="user-select:all;">${escapeHtml(whUrl)}</code>
                        </p>
                    </div>
                </div>
                <div class="account-card-actions">
                    ${!isActive ? `
                        <button type="button" class="btn-acc-action btn-acc-switch" onclick="handleAccountSwitch('${escapeHtml(acc.id)}')">
                            <i class="fa-solid fa-arrows-rotate"></i> 切り替え
                        </button>
                    ` : ''}
                    <button type="button" class="btn-acc-action btn-acc-edit" onclick="openAccountEditForm('${escapeHtml(acc.id)}')">
                        <i class="fa-solid fa-pen-to-square"></i> 編集
                    </button>
                    ${!isDefault && acc.id !== 'senior' ? `
                        <button type="button" class="btn-acc-action btn-acc-delete" onclick="handleAccountDelete('${escapeHtml(acc.id)}', '${escapeHtml(acc.name)}')">
                            <i class="fa-solid fa-trash-can"></i> 削除
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
    }).join('');
}

async function openAccountEditForm(accountId) {
    const accListView = document.getElementById('accListView');
    const accEditView = document.getElementById('accEditView');
    const tabListBtn = document.getElementById('tabAccListBtn');
    const tabNewBtn = document.getElementById('tabAccNewBtn');
    const formTitle = document.getElementById('accFormTitle');
    const formMode = document.getElementById('accFormMode');
    const idInput = document.getElementById('accFormId');
    const nameInput = document.getElementById('accFormName');
    const shortNameInput = document.getElementById('accFormShortName');
    const colorPicker = document.getElementById('accFormColorPicker');
    const colorHex = document.getElementById('accFormColorHex');
    const isDefaultCheck = document.getElementById('accFormIsDefault');
    const tokenInput = document.getElementById('accFormAccessToken');
    const secretInput = document.getElementById('accFormSecret');
    const liffIdInput = document.getElementById('accFormLiffId');
    const calUrlInput = document.getElementById('accFormCalendarUrl');
    const whUrlInput = document.getElementById('accFormWebhookUrl');
    const resultBanner = document.getElementById('lineTokenTestResultBanner');
    const saveMsg = document.getElementById('accSaveStatusMsg');

    if (accListView) accListView.style.display = 'none';
    if (accEditView) accEditView.style.display = 'block';
    if (resultBanner) resultBanner.style.display = 'none';
    if (saveMsg) saveMsg.textContent = '';

    if (!accountId) {
        // 新規作成モード
        if (tabListBtn) tabListBtn.classList.remove('active');
        if (tabNewBtn) tabNewBtn.classList.add('active');
        if (formTitle) formTitle.innerHTML = '<i class="fa-solid fa-circle-plus" style="color: #10b981;"></i> 新規LINE公式アカウントの追加';
        if (formMode) formMode.value = 'create';
        if (idInput) {
            idInput.value = '';
            idInput.readOnly = false;
            idInput.focus();
        }
        if (nameInput) nameInput.value = '';
        if (shortNameInput) shortNameInput.value = '';
        if (colorPicker) colorPicker.value = '#6366f1';
        if (colorHex) colorHex.value = '#6366F1';
        if (isDefaultCheck) isDefaultCheck.checked = false;
        if (tokenInput) tokenInput.value = '';
        if (secretInput) secretInput.value = '';
        if (liffIdInput) liffIdInput.value = '';
        if (calUrlInput) calUrlInput.value = '';
        if (whUrlInput) whUrlInput.value = '';
        updateDisplayWebhookUrl('');
    } else {
        // 既存編集モード
        if (tabListBtn) tabListBtn.classList.remove('active');
        if (tabNewBtn) tabNewBtn.classList.remove('active');
        if (formTitle) formTitle.innerHTML = `<i class="fa-solid fa-pen-to-square" style="color: #4f46e5;"></i> アカウント設定の編集 (${escapeHtml(accountId)})`;
        if (formMode) formMode.value = 'edit';
        if (idInput) {
            idInput.value = accountId;
            idInput.readOnly = true; // 識別IDは編集不可
        }

        // 詳細情報をAPIから取得
        try {
            const res = await fetch(`../api.php?action=get_account_detail&target_account=${encodeURIComponent(accountId)}&password=${encodeURIComponent(state.password || sessionStorage.getItem('admin_pass') || '1020143')}`);
            const data = await res.json();
            if (data.success && data.account) {
                const acc = data.account;
                if (nameInput) nameInput.value = acc.name || '';
                if (shortNameInput) shortNameInput.value = acc.short_name || '';
                const c = acc.theme_color || '#6366f1';
                if (colorPicker) colorPicker.value = c;
                if (colorHex) colorHex.value = c.toUpperCase();
                if (isDefaultCheck) isDefaultCheck.checked = !!acc.is_default;
                if (tokenInput) tokenInput.value = acc.channel_access_token || '';
                if (secretInput) secretInput.value = acc.channel_secret || '';
                if (liffIdInput) liffIdInput.value = acc.liff_id || '';
                if (calUrlInput) calUrlInput.value = acc.proline_calendar_url || '';
                if (whUrlInput) whUrlInput.value = acc.proline_webhook_url || '';
                updateDisplayWebhookUrl(accountId, !!acc.is_default);
            } else {
                alert('アカウント情報の読み込みに失敗しました: ' + (data.error || '不明なエラー'));
            }
        } catch (e) {
            console.error('Failed to get account detail:', e);
            alert('アカウント情報の取得中にエラーが発生しました');
        }
    }
}

function updateDisplayWebhookUrl(accId, isDefault = false) {
    const displayField = document.getElementById('accFormDisplayWebhookUrl');
    if (!displayField) return;
    const host = window.location.host;
    const path = window.location.pathname.replace(/\/admin\/.*$/, '');
    const cleanId = (accId || '').toLowerCase().replace(/[^a-z0-9_\-]/g, '');
    const query = (isDefault || cleanId === 'senior') ? '' : `?account=${cleanId || 'your_id'}`;
    displayField.value = `${window.location.protocol}//${host}${path}/webhook.php${query}`;
}

async function submitAccountForm() {
    const idInput = document.getElementById('accFormId');
    const nameInput = document.getElementById('accFormName');
    const shortNameInput = document.getElementById('accFormShortName');
    const colorHex = document.getElementById('accFormColorHex');
    const isDefaultCheck = document.getElementById('accFormIsDefault');
    const tokenInput = document.getElementById('accFormAccessToken');
    const secretInput = document.getElementById('accFormSecret');
    const liffIdInput = document.getElementById('accFormLiffId');
    const calUrlInput = document.getElementById('accFormCalendarUrl');
    const whUrlInput = document.getElementById('accFormWebhookUrl');
    const btnSave = document.getElementById('btnSubmitAccountSave');
    const statusMsg = document.getElementById('accSaveStatusMsg');

    const cleanId = (idInput.value || '').trim().toLowerCase().replace(/[^a-z0-9_\-]/g, '');
    if (!cleanId || cleanId.length < 2) {
        alert('アカウント識別IDを半角英小文字・数字（2文字以上）で入力してください');
        idInput.focus();
        return;
    }

    const name = (nameInput.value || '').trim();
    if (!name) {
        alert('アカウント表示名を入力してください');
        nameInput.focus();
        return;
    }

    btnSave.disabled = true;
    btnSave.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 保存中...';
    if (statusMsg) {
        statusMsg.style.color = '#4f46e5';
        statusMsg.textContent = 'アカウント設定を保存しています...';
    }

    try {
        const formData = new FormData();
        formData.append('id', cleanId);
        formData.append('name', name);
        formData.append('short_name', (shortNameInput.value || '').trim());
        formData.append('theme_color', (colorHex.value || '').trim());
        formData.append('is_default', isDefaultCheck.checked ? '1' : '0');
        formData.append('channel_access_token', (tokenInput.value || '').trim());
        formData.append('channel_secret', (secretInput.value || '').trim());
        formData.append('liff_id', (liffIdInput.value || '').trim());
        formData.append('proline_calendar_url', (calUrlInput.value || '').trim());
        formData.append('proline_webhook_url', (whUrlInput.value || '').trim());
        formData.append('password', state.password || sessionStorage.getItem('admin_pass') || '1020143');

        const res = await fetch('../api.php?action=save_account', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast(`✅ ${data.message || 'アカウント設定を保存しました'}`);
            if (Array.isArray(data.accounts)) {
                state.accounts = data.accounts;
                renderAccountSwitcher();
                updateBrandDisplay();
            }
            // 一覧ビューへ復帰
            showAccountListView();
        } else {
            if (statusMsg) {
                statusMsg.style.color = '#e11d48';
                statusMsg.textContent = `エラー: ${data.error || '保存に失敗しました'}`;
            }
            alert(`保存失敗: ${data.error || '不明なエラー'}`);
        }
    } catch (e) {
        console.error('Account save error:', e);
        if (statusMsg) {
            statusMsg.style.color = '#e11d48';
            statusMsg.textContent = `通信エラー: ${e.message}`;
        }
        alert('通信エラーが発生しました: ' + e.message);
    } finally {
        btnSave.disabled = false;
        btnSave.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> この設定で保存する';
    }
}

async function handleAccountDelete(accId, accName) {
    if (!confirm(`本当にアカウント「${accName}」を削除しますか？\n（※登録済みの受講生データベースファイル自体は安全のため残されます）`)) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('target_account', accId);
        formData.append('password', state.password || sessionStorage.getItem('admin_pass') || '1020143');

        const res = await fetch('../api.php?action=delete_account', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast(`🗑️ ${data.message || 'アカウントを削除しました'}`);
            if (Array.isArray(data.accounts)) {
                state.accounts = data.accounts;
                renderAccountSwitcher();
                renderAccountCardsList();
            }
        } else {
            alert(`削除失敗: ${data.error || '不明なエラー'}`);
        }
    } catch (e) {
        console.error('Account delete error:', e);
        alert('通信エラーが発生しました: ' + e.message);
    }
}

// グローバルスコープにも公開 (HTMLインラインonclick用)
window.handleAccountSwitch = handleAccountSwitch;
window.openAccountEditForm = openAccountEditForm;
window.handleAccountDelete = handleAccountDelete;

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
    // アカウント切替
    if (elements.accountSelect) {
        elements.accountSelect.addEventListener('change', (e) => {
            handleAccountSwitch(e.target.value);
        });
    }
    // プロライン連携設定モーダル開閉 & 操作
    if (elements.openProlineSettingsBtn) elements.openProlineSettingsBtn.addEventListener('click', openProlineSettingsModal);
    if (elements.closeProlineSettingsModalBtn) elements.closeProlineSettingsModalBtn.addEventListener('click', closeProlineSettingsModal);
    if (elements.closeProlineSettingsBtn) elements.closeProlineSettingsBtn.addEventListener('click', closeProlineSettingsModal);
    if (elements.saveProlineSettingsBtn) elements.saveProlineSettingsBtn.addEventListener('click', saveProlineSettings);
    if (elements.testProlineRelayBtn) elements.testProlineRelayBtn.addEventListener('click', testProlineRelay);
    if (elements.btnRefreshProlineLogs) elements.btnRefreshProlineLogs.addEventListener('click', loadProlineSettings);
    if (elements.prolineSettingsModal) {
        elements.prolineSettingsModal.addEventListener('click', (e) => {
            if (e.target === elements.prolineSettingsModal) closeProlineSettingsModal();
        });
    }

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
    if (elements.userMenuPosSelect) elements.userMenuPosSelect.addEventListener('change', renderUserMenuPreview);
    if (elements.userMenuBaseSelect) elements.userMenuBaseSelect.addEventListener('change', () => loadAndRenderUserMenuBaseImage());
    if (elements.userMenuGradientToggle) elements.userMenuGradientToggle.addEventListener('change', renderUserMenuPreview);

    // 文字サイズ (スライダー ⇄ 数値直接入力の双方向連動)
    if (elements.userMenuFontSizeInput) {
        elements.userMenuFontSizeInput.addEventListener('input', (e) => {
            const val = parseInt(e.target.value, 10) || 65;
            if (elements.userMenuFontSizeNumber) elements.userMenuFontSizeNumber.value = val;
            if (elements.userMenuFontSizeVal) elements.userMenuFontSizeVal.textContent = val + 'px';
            document.querySelectorAll('.btn-size-quick-chip').forEach(btn => {
                btn.classList.toggle('active', parseInt(btn.dataset.size, 10) === val);
            });
            renderUserMenuPreview();
        });
    }
    if (elements.userMenuFontSizeNumber) {
        elements.userMenuFontSizeNumber.addEventListener('input', (e) => {
            const val = Math.max(20, Math.min(220, parseInt(e.target.value, 10) || 65));
            if (elements.userMenuFontSizeInput) elements.userMenuFontSizeInput.value = val;
            if (elements.userMenuFontSizeVal) elements.userMenuFontSizeVal.textContent = val + 'px';
            document.querySelectorAll('.btn-size-quick-chip').forEach(btn => {
                btn.classList.toggle('active', parseInt(btn.dataset.size, 10) === val);
            });
            renderUserMenuPreview();
        });
    }
    // 文字サイズ クイックチップ
    document.querySelectorAll('.btn-size-quick-chip').forEach(btn => {
        btn.addEventListener('click', () => {
            const size = parseInt(btn.dataset.size, 10);
            if (!size) return;
            if (elements.userMenuFontSizeInput) elements.userMenuFontSizeInput.value = size;
            if (elements.userMenuFontSizeNumber) elements.userMenuFontSizeNumber.value = size;
            if (elements.userMenuFontSizeVal) elements.userMenuFontSizeVal.textContent = size + 'px';
            document.querySelectorAll('.btn-size-quick-chip').forEach(b => b.classList.toggle('active', b === btn));
            renderUserMenuPreview();
        });
    });

    // 帯の背景色 (カラーピッカー ⇄ HEX入力の双方向連動)
    if (elements.userMenuBgColorPicker) {
        elements.userMenuBgColorPicker.addEventListener('input', (e) => {
            const hex = e.target.value.toUpperCase();
            if (elements.userMenuBgColorHex) elements.userMenuBgColorHex.value = hex;
            document.querySelectorAll('.btn-color-quick-chip').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.bg.toUpperCase() === hex);
            });
            renderUserMenuPreview();
        });
    }
    if (elements.userMenuBgColorHex) {
        elements.userMenuBgColorHex.addEventListener('input', (e) => {
            let val = e.target.value.trim();
            if (!val.startsWith('#') && val.length > 0) val = '#' + val;
            if (/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/.test(val)) {
                if (elements.userMenuBgColorPicker) elements.userMenuBgColorPicker.value = (val.length === 4 ? `#${val[1]}${val[1]}${val[2]}${val[2]}${val[3]}${val[3]}` : val);
                document.querySelectorAll('.btn-color-quick-chip').forEach(btn => {
                    btn.classList.toggle('active', btn.dataset.bg.toUpperCase() === val.toUpperCase());
                });
                renderUserMenuPreview();
            }
        });
    }
    // 背景色 クイックチップ
    document.querySelectorAll('.btn-color-quick-chip').forEach(btn => {
        btn.addEventListener('click', () => {
            const bg = btn.dataset.bg;
            if (!bg) return;
            if (elements.userMenuBgColorPicker) elements.userMenuBgColorPicker.value = bg;
            if (elements.userMenuBgColorHex) elements.userMenuBgColorHex.value = bg.toUpperCase();
            document.querySelectorAll('.btn-color-quick-chip').forEach(b => b.classList.toggle('active', b === btn));
            renderUserMenuPreview();
        });
    });

    // 帯の文字色 (カラーピッカー ⇄ HEX入力の双方向連動)
    if (elements.userMenuTextColorPicker) {
        elements.userMenuTextColorPicker.addEventListener('input', (e) => {
            const hex = e.target.value.toUpperCase();
            if (elements.userMenuTextColorHex) elements.userMenuTextColorHex.value = hex;
            document.querySelectorAll('.btn-text-color-quick-chip').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.color.toUpperCase() === hex);
            });
            renderUserMenuPreview();
        });
    }
    if (elements.userMenuTextColorHex) {
        elements.userMenuTextColorHex.addEventListener('input', (e) => {
            let val = e.target.value.trim();
            if (!val.startsWith('#') && val.length > 0) val = '#' + val;
            if (/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/.test(val)) {
                if (elements.userMenuTextColorPicker) elements.userMenuTextColorPicker.value = (val.length === 4 ? `#${val[1]}${val[1]}${val[2]}${val[2]}${val[3]}${val[3]}` : val);
                document.querySelectorAll('.btn-text-color-quick-chip').forEach(btn => {
                    btn.classList.toggle('active', btn.dataset.color.toUpperCase() === val.toUpperCase());
                });
                renderUserMenuPreview();
            }
        });
    }
    // 文字色 クイックチップ
    document.querySelectorAll('.btn-text-color-quick-chip').forEach(btn => {
        btn.addEventListener('click', () => {
            const color = btn.dataset.color;
            if (!color) return;
            if (elements.userMenuTextColorPicker) elements.userMenuTextColorPicker.value = color;
            if (elements.userMenuTextColorHex) elements.userMenuTextColorHex.value = color.toUpperCase();
            document.querySelectorAll('.btn-text-color-quick-chip').forEach(b => b.classList.toggle('active', b === btn));
            renderUserMenuPreview();
        });
    });

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
        const res = await fetch(`../api.php?action=admin_list_customers&password=${encodeURIComponent(pass)}&account=${encodeURIComponent(state.activeAccount)}`);
        const data = await res.json();

        if (data.success) {
            state.password = pass;
            sessionStorage.setItem('admin_pass', pass);
            elements.loginModal.style.display = 'none';
            elements.adminApp.style.display = 'block';
            state.allCustomers = data.customers || [];
            updateBrandDisplay();
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
        const res = await fetch(`../api.php?action=admin_list_customers&password=${encodeURIComponent(state.password)}&sort=${sortParam}&account=${encodeURIComponent(state.activeAccount)}`);
        const data = await res.json();
        if (data.success) {
            state.allCustomers = data.customers || [];
            updateBrandDisplay();
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
        const res = await fetch(`../api.php?action=admin_sync_line_followers&password=${encodeURIComponent(state.password)}&account=${encodeURIComponent(state.activeAccount)}`);
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
                                    <button class="btn-copy-uid" title="UIDをクリップボードにコピー" onclick="event.stopPropagation(); copyCustUid('${escapeHtml(c.user_id)}');" style="background: none; border: none; color: #64748b; cursor: pointer; padding: 2px 4px; font-size: 11px; border-radius: var(--radius-xs);" onmouseover="this.style.color='#1e293b'; this.style.background='#f1f5f9';" onmouseout="this.style.color='#64748b'; this.style.background='none';">
                                        <i class="fa-regular fa-copy"></i>
                                    </button>
                                    <button class="btn-add-admin-uid" title="このアカウントを管理者LINE通知先に登録" onclick="event.stopPropagation(); addAdminUidDirectly('${escapeHtml(c.user_id)}', '${escapeHtml(c.user_name || '')}');" style="background: none; border: none; color: #0284c7; cursor: pointer; padding: 2px 4px; font-size: 11px; border-radius: var(--radius-xs);" onmouseover="this.style.color='#0369a1'; this.style.background='#e0f2fe';" onmouseout="this.style.color='#0284c7'; this.style.background='none';">
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
                        <button class="btn-knowledge-user-row" data-action="knowledge-send" data-idx="${idx}" title="この受講生へスマホ・PCお役立ち情報（Flex Message）を個別送信">
                            <i class="fa-solid fa-bullhorn"></i> お役立ち配信
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
            } else if (action === 'knowledge-send') {
                if (!cust.user_id || !cust.user_id.startsWith('U')) {
                    alert('この受講生は手動登録（LINE未連携）のため個別送信できません。\n配信スタジオを起動し、全体一斉配信または他の受講生を選択して送信できます。');
                    openKnowledgeBroadcastModal();
                } else {
                    openKnowledgeBroadcastModal(cust.user_id);
                }
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
    // LINE実存・公開中メニューを優先ソート
    const sortedMenus = [...state.richMenus].sort((a, b) => {
        if (a.is_active && !b.is_active) return -1;
        if (!a.is_active && b.is_active) return 1;
        if (a.is_line_synced && !b.is_line_synced) return -1;
        if (!a.is_line_synced && b.is_line_synced) return 1;
        return b.id - a.id;
    });

    sortedMenus.forEach(m => {
        const isLive = (m.is_active == 1 || m.is_line_default);
        const isSynced = (m.is_line_synced !== false);
        const opt = document.createElement('option');
        opt.value = m.id;
        let prefix = '';
        if (isLive) prefix = '★ [LINE公開中] ';
        else if (!isSynced) prefix = '⚠️ [LINE側未同期] ';

        opt.textContent = prefix + m.title + ` (ボタン${(m.areas || []).length}個)`;
        if (preferredMenuId && (String(m.id) === String(preferredMenuId) || String(m.line_menu_id) === String(preferredMenuId))) {
            opt.selected = true;
            foundMatch = true;
        }
        elements.directAssignMenuSelect.appendChild(opt);
    });

    if (!foundMatch && sortedMenus.length > 0) {
        const defaultMenu = sortedMenus.find(m => m.is_active == 1) || sortedMenus[0];
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
        // 画像エラーハンドラー
        elements.directAssignMenuPreviewImg.onerror = function() {
            this.onerror = null;
            this.src = `../api.php?action=richmenu_image&id=${menu.id}&password=${encodeURIComponent(state.password)}`;
        };
        elements.directAssignMenuPreviewImg.src = menu.image_url || menu.base_image_url || `../api.php?action=richmenu_image&id=${menu.id}`;
    }
    if (elements.directAssignMenuMeta) {
        const isLive = (menu.is_active == 1 || menu.is_line_default);
        const isLiveBadge = isLive ? '<span style="color:#059669; font-weight:700;">★ LINE公式全体の公開中メニュー</span>' : '<span style="color:#64748b;">個別専用 / 保存済み</span>';
        const syncBadge = (menu.is_line_synced !== false) ? '<span style="color:#2563eb; font-weight:600;"><i class="fa-solid fa-circle-check"></i> LINE接続OK</span>' : '<span style="color:#d97706;"><i class="fa-solid fa-triangle-exclamation"></i> LINE同期推奨</span>';
        const buttonCount = (menu.areas || []).length;
        elements.directAssignMenuMeta.innerHTML = `
            <strong>${escapeHtml(menu.title)}</strong> （${menu.width || 2500} × ${menu.height || 1686}px / アクション枠: ${buttonCount}箇所）<br>
            <span style="font-size: 11px;">状態: ${isLiveBadge} | ${syncBadge} | LINE Menu ID: <code>${escapeHtml(menu.line_menu_id || '未発行')}</code></span>
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
    const THEME_HEX_MAP = {
        red: '#E11D48',
        blue: '#2563EB',
        green: '#059669',
        amber: '#D97706',
        orange: '#EA580C',
        emerald: '#059669',
        gold: '#D97706',
        dark: '#0F172A'
    };
    if (cust.custom_menu_text) {
        elements.userMenuTextInput.value = cust.custom_menu_text;
    } else if (nearest) {
        elements.userMenuTextInput.value = nearest.phrase;
        if (nearest.theme) {
            const hex = THEME_HEX_MAP[nearest.theme] || '#E11D48';
            if (elements.userMenuBgColorPicker) elements.userMenuBgColorPicker.value = hex;
            if (elements.userMenuBgColorHex) elements.userMenuBgColorHex.value = hex;
            document.querySelectorAll('.btn-color-quick-chip').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.bg.toUpperCase() === hex.toUpperCase());
            });
        }
    } else {
        elements.userMenuTextInput.value = getDefaultCustomPhrase(cust, 'insp');
    }

    // タップ案内ガイドはデフォルトOFF
    if (elements.userMenuShowTapHint) elements.userMenuShowTapHint.checked = false;

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
        if (nearest.theme) {
            const THEME_HEX_MAP = {
                red: '#E11D48',
                blue: '#2563EB',
                green: '#059669',
                amber: '#D97706',
                orange: '#EA580C',
                emerald: '#059669',
                gold: '#D97706',
                dark: '#0F172A'
            };
            const hex = THEME_HEX_MAP[nearest.theme] || '#E11D48';
            if (elements.userMenuBgColorPicker) elements.userMenuBgColorPicker.value = hex;
            if (elements.userMenuBgColorHex) elements.userMenuBgColorHex.value = hex;
            document.querySelectorAll('.btn-color-quick-chip').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.bg.toUpperCase() === hex.toUpperCase());
            });
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

// --- HEXカラー & 明度計算ヘルパー ---
function sanitizeHexColor(hex, fallback) {
    if (!hex || typeof hex !== 'string') return fallback;
    let val = hex.trim();
    if (!val.startsWith('#') && val.length > 0) val = '#' + val;
    return /^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/.test(val) ? val : fallback;
}

function adjustHexBrightness(hex, percent) {
    if (!hex || typeof hex !== 'string') return hex;
    let cleanHex = hex.replace(/^#/, '');
    if (cleanHex.length === 3) {
        cleanHex = cleanHex.split('').map(c => c + c).join('');
    }
    if (cleanHex.length !== 6) return hex;
    const num = parseInt(cleanHex, 16);
    let r = (num >> 16) + Math.round(255 * (percent / 100));
    let g = ((num >> 8) & 0x00FF) + Math.round(255 * (percent / 100));
    let b = (num & 0x0000FF) + Math.round(255 * (percent / 100));
    r = Math.min(255, Math.max(0, r));
    g = Math.min(255, Math.max(0, g));
    b = Math.min(255, Math.max(0, b));
    return `#${((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1)}`;
}

function isHexColorLight(hex) {
    if (!hex) return false;
    let cleanHex = hex.replace(/^#/, '');
    if (cleanHex.length === 3) cleanHex = cleanHex.split('').map(c => c + c).join('');
    if (cleanHex.length !== 6) return false;
    const r = parseInt(cleanHex.substr(0, 2), 16);
    const g = parseInt(cleanHex.substr(2, 2), 16);
    const b = parseInt(cleanHex.substr(4, 2), 16);
    const yiq = ((r * 299) + (g * 587) + (b * 114)) / 1000;
    return yiq >= 135;
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

    // HEXカラー設定の取得 (背景色 & 文字色)
    const rawBgHex = elements.userMenuBgColorHex ? elements.userMenuBgColorHex.value : (elements.userMenuBgColorPicker ? elements.userMenuBgColorPicker.value : '#ff8700');
    const bgHex = sanitizeHexColor(rawBgHex, '#ff8700');

    const rawTextHex = elements.userMenuTextColorHex ? elements.userMenuTextColorHex.value : (elements.userMenuTextColorPicker ? elements.userMenuTextColorPicker.value : '#ffffff');
    const textHex = sanitizeHexColor(rawTextHex, '#ffffff');

    const useGradient = elements.userMenuGradientToggle ? elements.userMenuGradientToggle.checked : true;
    const pos = elements.userMenuPosSelect ? elements.userMenuPosSelect.value : 'top';

    // 文字フォントサイズ (20px 〜 220px)
    let fontSize = 65;
    if (elements.userMenuFontSizeNumber && elements.userMenuFontSizeNumber.value) {
        fontSize = parseInt(elements.userMenuFontSizeNumber.value, 10) || 65;
    } else if (elements.userMenuFontSizeInput && elements.userMenuFontSizeInput.value) {
        fontSize = parseInt(elements.userMenuFontSizeInput.value, 10) || 65;
    }
    fontSize = Math.max(20, Math.min(220, fontSize));

    const heightSetting = elements.userMenuBannerHeightSelect ? elements.userMenuBannerHeightSelect.value : 'auto';

    // 複数行テキストの分解
    const lines = rawText.split('\n').map(l => l.trim()).filter(l => l.length > 0);
    if (lines.length === 0) return;

    const actionType = elements.userMenuBannerActionType ? elements.userMenuBannerActionType.value : 'mycar_liff';
    const showTapHint = elements.userMenuShowTapHint ? elements.userMenuShowTapHint.checked : false;
    const hasTapAction = actionType !== 'none';

    // 帯の高さ計算（文字サイズ・行数・帯の太さ・タップ案内設定を考慮）
    let bannerH;
    const extraHintH = (hasTapAction && showTapHint) ? Math.round(fontSize * 0.72) : 0;
    if (heightSetting === 'compact') {
        bannerH = Math.max(Math.round(fontSize * 1.5) + extraHintH, 130);
    } else if (heightSetting === 'standard') {
        bannerH = Math.max(Math.round(fontSize * 1.8) + extraHintH, 175);
    } else if (heightSetting === 'wide') {
        bannerH = Math.max(Math.round(fontSize * 2.3) + extraHintH, 230);
    } else {
        // auto: 行数とフォントサイズに応じて余白を最適化
        const lineSpacing = fontSize * 1.32;
        const textBlockH = (lines.length * lineSpacing) + extraHintH;
        bannerH = Math.max(140, Math.round(textBlockH + (fontSize * 0.92)));
    }

    const bannerY = (pos === 'top') ? 0 : (h - bannerH);

    // バナーの座標をstateに保存（適用時にAPIへ送信）
    state.userMenuBannerBounds = {
        x: 0,
        y: bannerY,
        width: w,
        height: bannerH
    };

    // 背景の塗り（立体グラデーション または フラット単色）
    let bgFill;
    if (useGradient) {
        bgFill = ctx.createLinearGradient(0, bannerY, w, bannerY + bannerH);
        bgFill.addColorStop(0, bgHex);
        bgFill.addColorStop(1, adjustHexBrightness(bgHex, -14));
    } else {
        bgFill = bgHex;
    }

    const isBgLight = isHexColorLight(bgHex);
    const accentBorder = isBgLight ? 'rgba(0, 0, 0, 0.15)' : 'rgba(255, 255, 255, 0.35)';

    // 背景ドロップシャドウ & 帯の描画
    ctx.save();
    ctx.shadowColor = 'rgba(0, 0, 0, 0.45)';
    ctx.shadowBlur = 24;
    ctx.shadowOffsetY = (pos === 'top') ? 8 : -8;
    ctx.fillStyle = bgFill;
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
    ctx.fillStyle = textHex;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    const isTextLight = isHexColorLight(textHex);
    if (isTextLight) {
        ctx.shadowColor = 'rgba(0, 0, 0, 0.75)';
        ctx.shadowBlur = Math.min(16, Math.max(6, Math.round(fontSize * 0.16)));
        ctx.shadowOffsetY = 2;
    } else {
        ctx.shadowColor = 'rgba(255, 255, 255, 0.6)';
        ctx.shadowBlur = Math.min(10, Math.max(4, Math.round(fontSize * 0.12)));
        ctx.shadowOffsetY = 1;
    }

    ctx.font = `bold ${fontSize}px "Noto Sans JP", -apple-system, BlinkMacSystemFont, sans-serif`;

    const lineSpacing = fontSize * 1.32;
    const totalLinesH = (lines.length - 1) * lineSpacing;
    // タップガイド表示がある場合は少し上寄りに配置
    const shiftY = (hasTapAction && showTapHint) ? -Math.round(extraHintH * 0.38) : 0;
    const startY = ((bannerY + bannerH / 2) - (totalLinesH / 2)) + shiftY;

    lines.forEach((line, idx) => {
        const lineY = startY + (idx * lineSpacing);
        ctx.fillText(line, w / 2, lineY, w - 120);
    });

    // タップ誘導ガイド（タップアクション有効時）
    if (hasTapAction && showTapHint) {
        let hintLabel = '👆 タップして詳細を見る';
        if (actionType === 'mycar_liff' || actionType === 'open_mycar') {
            hintLabel = '👆 タップして予約・相談を開く';
        } else if (actionType === 'search_all') {
            hintLabel = '👆 タップして在庫を見る';
        } else if (actionType === 'notice') {
            hintLabel = '👆 タップしてお知らせを見る';
        } else if (actionType === 'uri') {
            hintLabel = '👆 タップしてリンクを開く';
        }

        const hintFontSize = Math.max(24, Math.round(fontSize * 0.46));
        ctx.font = `bold ${hintFontSize}px "Noto Sans JP", -apple-system, sans-serif`;
        const hintY = startY + totalLinesH + (fontSize * 0.95);

        // 半透明ピル背景
        const textWidth = ctx.measureText(hintLabel).width;
        const pillW = textWidth + 40;
        const pillH = hintFontSize * 1.5;
        
        ctx.beginPath();
        const pillX = (w - pillW) / 2;
        const pillY = hintY - (pillH / 2);
        ctx.roundRect ? ctx.roundRect(pillX, pillY, pillW, pillH, pillH / 2) : ctx.rect(pillX, pillY, pillW, pillH);

        if (isTextLight) {
            ctx.fillStyle = 'rgba(0, 0, 0, 0.32)';
            ctx.fill();
            ctx.fillStyle = textHex;
            ctx.fillText(hintLabel, w / 2, hintY);
        } else {
            ctx.fillStyle = 'rgba(255, 255, 255, 0.75)';
            ctx.fill();
            ctx.fillStyle = textHex;
            ctx.fillText(hintLabel, w / 2, hintY);
        }
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
    requestAnimationFrame(() => {
        elements.prolineSettingsModal.classList.add('active');
    });
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
        elements.prolineSettingsModal.classList.remove('active');
        setTimeout(() => {
            if (elements.prolineSettingsModal && !elements.prolineSettingsModal.classList.contains('active')) {
                elements.prolineSettingsModal.style.display = 'none';
            }
        }, 200);
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
            if (elements.prolineCalendarUrlInput) {
                elements.prolineCalendarUrlInput.value = data.settings.calendar_url || '';
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
    const calendarUrl = elements.prolineCalendarUrlInput ? elements.prolineCalendarUrlInput.value.trim() : '';
    const enabled = elements.prolineRelayEnabledCheck && elements.prolineRelayEnabledCheck.checked ? 1 : 0;

    try {
        if (elements.saveProlineSettingsBtn) elements.saveProlineSettingsBtn.disabled = true;
        if (elements.prolineSaveStatus) elements.prolineSaveStatus.textContent = '保存中...';

        const payload = new URLSearchParams({
            action: 'admin_save_proline_settings',
            password: state.password,
            url: url,
            calendar_url: calendarUrl,
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

// ==========================================================================
// シニアお役立ち情報 配信スタジオ (Knowledge Broadcast Studio)
// ==========================================================================

const DEFAULT_PROLINE_BOOKING_URL = 'https://liff.line.me/2000276344-XlmvL9qZ?r=https%3A%2F%2Fd0o2pa7q.autosns.app%2Fcl%2FQaOK41fkzp%3Fuid%3D%5B%5Buid%5D%5D%26openExternalBrowser%3D1';

const SENIOR_KNOWLEDGE_PRESETS = [
    // === 🚨【防犯・トラブル・緊急対策編】（10テーマ） ===
    {
        id: 'scam_virus_alert',
        category: '🚨 偽警告・詐欺対策',
        color: '#e11d48',
        label: '🚨 ウイルス警告詐欺対策',
        title: '🚨 パソコンの「ウイルス感染警告」は詐欺！慌てず閉じる方法',
        subtitle: '画面に突然ピーッと警告音や電話番号が出ても絶対に電話をかけてはいけません！',
        points: [
            '画面に表示される電話番号には絶対に電話しない',
            'キーボードの「Esc」長押し、または「Ctrl+Alt+Delete」で画面を閉じる',
            '不安な時は電源ボタン長押しで強制終了し、教室へご相談ください'
        ],
        advice: '「警告画面が消えない」「操作が不安」という時は、無理に触らずそのまま教室へお持ちください。スタッフが一緒に安全を確認します！',
        btn1Label: '📅 教室で直接相談・予約する',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'scam_fake_sms',
        category: '⚠️ 不在通知詐欺対策',
        color: '#ea580c',
        label: '⚠️ 偽SMS・不在通知の見分け方',
        title: '⚠️ ヤマトや佐川を名乗る偽SMS（不在通知）にご注意！',
        subtitle: '「お荷物をお届けにあがりましたが…」というSMSのリンクは絶対に開かないでください！',
        points: [
            'SMSに書かれた青い英数字リンク（URL）は絶対に押さない',
            '荷物の確認は、公式アプリやLINEの公式通知から行う',
            '万が一リンクを開いてしまっても、電話番号やパスワードは絶対に入力しない'
        ],
        advice: '心当たりのない不審なSMSが届いた時は、削除するか、スクリーンショットを撮って教室でお見せください！',
        btn1Label: '📅 教室で直接相談・予約する',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'scam_fake_pdf',
        category: '🚨 偽広告・詐欺対策',
        color: '#e11d48',
        label: '📄 偽PDFアプリ詐欺の悪影響',
        title: '📄「PDFを見るにはアプリが必要？」シニアを狙う偽広告と危険な悪影響',
        subtitle: 'ネット閲覧中に出る「PDFリーダーを更新・入手」の画面は危険な偽広告です！どんな悪影響があるのか解説します。',
        points: [
            '【悪影響①】画面中に消えない警告や迷惑広告が大量に出る（乗っ取り・アドウェア）',
            '【悪影響②】「無料」と見せかけて高額な定期購読（月額数千円〜数万円の引き落とし）',
            '【悪影響③】個人情報・連絡先の抜き取りや、スマホ動作が重くなり電池が急減する',
            '【知っておきたい真実】今のスマホやPCは、特別なアプリを入れなくても最初からPDFをそのまま開けます！'
        ],
        advice: '「PDFを見るためにインストール」と出たら絶対に押さず画面を閉じてください！万が一入れてしまったり不審な広告が出る場合は、すぐに端末を持って教室にご相談ください（無料点検・削除サポート実施中）。',
        btn1Label: '📅 教室でスマホ・PC点検を予約',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'scam_support_phone',
        category: '📞 偽サポート詐欺対策',
        color: '#dc2626',
        label: '📞 偽電話サポートの罠',
        title: '📞「マイクロソフトに電話を」？偽サポート電話詐欺の恐ろしい手口',
        subtitle: '画面に表示された電話番号に電話をかけると、片言の日本語で遠隔操作を迫られます！',
        points: [
            'マイクロソフトや大手企業が画面に電話番号を出して電話を求めることは100％ありません',
            '電話すると「遠隔操作ソフト」を入れられ、パソコン内の写真や個人情報を盗まれます',
            '「修理代」としてコンビニで電子マネー（Google Playカード等）を買わせるのは典型的な詐欺手口です'
        ],
        advice: '電話番号が表示されても絶対に電話をかけてはいけません！もし電話してしまったりカードを買うよう言われたら、すぐ電話を切り教室にご連絡ください。',
        btn1Label: '📅 教室で緊急相談・点検予約',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'scam_line_friend',
        category: '👤 LINE乗っ取り防止',
        color: '#e11d48',
        label: '👤 LINE乗っ取り詐欺の見破り方',
        title: '👤 友人から「認証番号教えて」と届いたら詐欺！LINE乗っ取りの防ぎ方',
        subtitle: '仲の良いお友だちのアカウントから突然届く「携帯が壊れたから番号教えて」は乗っ取り犯です！',
        points: [
            '「電話番号と4桁の暗証番号を教えて」というメッセージは絶対に信じてはいけません',
            'SMSに届いた「認証番号（セキュリティコード）」を他人に教えると、あなたのLINEが乗っ取られます',
            '怪しいと思ったらLINEではなく、直接電話してお友だち本人に確認しましょう'
        ],
        advice: 'お友だち本人が書いた文章に見えても、文面が不自然な時は要注意です。不安なメッセージが届いたら教室スタッフにお見せください！',
        btn1Label: '📅 LINE設定を教室で相談',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'safe_free_wifi',
        category: '📶 通信セキュリティ',
        color: '#ea580c',
        label: '📶 無料Wi-Fiの安全利用と危険',
        title: '📶 街や病院の「無料Wi-Fi」安全な使い方と危険な落とし穴',
        subtitle: 'カフェや商業施設のフリーWi-Fiは便利ですが、使い方を誤ると通信を盗み見られる危険があります！',
        points: [
            '鍵マークのない「暗号化されていないWi-Fi」では、パスワードやクレジットカード番号を入力しない',
            '本物そっくりに偽装した「偽アクセスポイント」に自動接続させないよう「Wi-Fi自動接続」はオフ推奨',
            '銀行のネットバンキングや大事な買い物は、自宅のWi-Fiかスマホの携帯電波（4G/5G）で行う'
        ],
        advice: '外出先で安全にWi-Fiをつなぐコツや、安全な設定方法は教室でわかりやすくレッスンいたします！',
        btn1Label: '📅 スマホ通信設定を教室で相談',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'pc_numlock_trouble',
        category: '🔢 キーボードトラブル',
        color: '#d97706',
        label: '🔢 数字が打てないNumLockの謎',
        title: '🔢 キーボード右の数字が打てない！「NumLock」ランプの謎を解決',
        subtitle: '「数字を押したのに画面が動くだけで打てない！」シニアの相談件数No.1トラブルです。',
        points: [
            'テンキー（右側の数字キー）の上にある「NumLock（ニューロック）」キーを1回押すだけ！',
            'キーボードの「NumLock」ランプが点灯していれば数字入力、消えていると矢印移動になります',
            'ノートパソコンで文字キーを押すと数字が出る場合は「Fn」＋「NumLock」で解除できます'
        ],
        advice: 'パソコンの故障ではなく、キーの押し間違いが原因です。教室のキーボードで実際にランプの点き方を確認してみましょう！',
        btn1Label: '📅 パソコン操作を教室で相談',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'pc_freeze_safety',
        category: '💻 故障防止・緊急対応',
        color: '#0284c7',
        label: '💻 画面フリーズ時の安全強制終了',
        title: '💻 画面がカチコチに固まった！慌てず行う「安全な強制終了」手順',
        subtitle: 'マウスも動かない時、いきなりコンセントを抜くのは故障の元！安全な終了手順を覚えましょう。',
        points: [
            '【手順①】まずは3分待ってみる（裏で更新作業中の一時的な停止の可能性があるため）',
            '【手順②】パソコン本体の「電源ボタン」を指でグッと約5〜8秒間押し続ける',
            '【手順③】ランプとファンの音が完全に消えたら、1分休ませてから再度電源を入れます'
        ],
        advice: '頻繁にフリーズを繰り返す場合は、ハードディスクの寿命やウイルス感染の疑いがあります。無理に使わず教室で無料健康診断をお受けください！',
        btn1Label: '🛠️ パソコン健康診断を予約',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'pc_fan_dust',
        category: '🧹 パソコン延命ケア',
        color: '#059669',
        label: '🧹 PCホコリ掃除と異音対策',
        title: '🧹 パソコンが熱い・急に切れる？寿命を延ばす「通気口のホコリ掃除」',
        subtitle: '「ファンがゴーッと唸る」「本体がやけどしそうに熱い」のはホコリ詰まりのサインです！',
        points: [
            'パソコンの側面や底面にあるスリット（通気口）にホコリがたまると、熱を逃がせず急に電源が落ちます',
            '必ず電源を切り電源コードを抜いてから、通気口のホコリを掃除機で弱く吸い取るか乾いた布で拭く',
            '布団やこたつ布団の上など、通気口がふさがる場所でノートPCを使うのは故障の最大原因です'
        ],
        advice: '内部の精密清掃やファンのお手入れは分解が必要な場合もあります。教室にお持ちいただければスタッフが安全に清掃・点検いたします！',
        btn1Label: '🛠️ パソコン内部清掃・点検予約',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'line_unsend_mistake',
        category: '💬 LINE誤送信防止',
        color: '#7c3aed',
        label: '💬 LINE送信間違い・24時間取消',
        title: '💬 LINEで間違えて別の友だちに送っちゃった！24時間以内の「送信取消」',
        subtitle: '「相手を間違えてメッセージや写真を送ってしまった！」そんな時の救済ワザです。',
        points: [
            '間違えた吹き出しを「指で長押し」してメニューを出す',
            '【超重要】「削除」ではなく「送信取消」を選ぶ（削除は自分の画面から消えるだけ！）',
            '送信後「24時間以内」なら相手のトーク画面からもメッセージを消すことができます'
        ],
        advice: '「削除」を押して相手の画面に残ってしまった…というご相談がよくあります。違いを教室のレッスンでマスターしておくと安心です！',
        btn1Label: '📅 LINE使い方レッスンを予約',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },

    // === 📱💻【スマホ・PC快適便利ワザ編】（10テーマ） ===
    {
        id: 'phone_large_text',
        category: '📱 スマホ見やすさ設定',
        color: '#0284c7',
        label: '📱 スマホの文字を大きくする',
        title: '📱 スマホの文字をもっと大きく！目に優しい簡単設定',
        subtitle: '「画面の文字が小さくて読みづらい…」とお悩みの方へ。文字を大きく太くする設定です！',
        points: [
            'iPhone: 「設定」→「画面表示と明るさ」→「テキストサイズを変更」',
            'Android: 「設定」→「ディスプレイ」→「フォントサイズと表示サイズ」',
            '「文字を太くする」をオンにすると、さらにクッキリ見やすくなります'
        ],
        advice: '教室のレッスンで、ご自身のスマホに合わせて一番読みやすい大きさに一緒に設定調整いたします！',
        btn1Label: '📅 スマホ設定を教室で相談する',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'line_font_size',
        category: '💬 LINE便利ワザ',
        color: '#7c3aed',
        label: '💬 LINEの文字サイズ特大化',
        title: '💬 LINEのメッセージ文字だけを特大サイズにする方法',
        subtitle: 'お友だちやご家族からのメッセージがぐんと読みやすくなります！',
        points: [
            'LINEの「ホーム」右上の歯車マーク（設定）をタップ',
            '「トーク」→「フォントサイズ」を選ぶ',
            '「特大」を選ぶと、トークの文字が大きく見やすくなります'
        ],
        advice: 'スマホ全体の文字は変えずに、LINEだけ大きくすることも可能です。教室で一緒にやってみましょう！',
        btn1Label: '📅 レッスン予約・日程変更',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'phone_voice_input',
        category: '🗣️ スマホ神ワザ',
        color: '#0284c7',
        label: '🗣️ 話すだけ！らくらく音声入力',
        title: '🗣️ キーボード入力不要！マイクで話すだけの「音声入力」超入門',
        subtitle: '「文字入力が遅い・ボタンが小さくて押しづらい」という方は、マイクに向かって話すだけでOK！',
        points: [
            'キーボードの端にある「マイクのマーク」をポンと1回タップする',
            '「こんにちは」「明日の10時に行きます」とスマホに話しかけるだけで文字が自動入力されます',
            '「まる」と言うと「。」、「てん」と言うと「、」、「かいぎょう」と言うと改行されます'
        ],
        advice: '今の音声認識は驚くほど正確です！手が疲れる方やメール作成に時間がかかる方はぜひ教室で練習してみましょう。世界が変わります！',
        btn1Label: '📅 音声入力レッスンを予約',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'pc_mouse_zoom',
        category: '🔍 パソコン便利技',
        color: '#059669',
        label: '🔍 画面拡大Ctrl+マウス車輪',
        title: '🔍 ホームページの文字が一瞬で特大に！「Ctrl ＋ マウス車輪」',
        subtitle: '「インターネットの文字が小さくて読めない…」メガネを探す前にこの操作をお試しください！',
        points: [
            'キーボード左下の「Ctrl（コントロール）」キーを押したまま、マウスの真ん中の車輪（ホイール）を上へ回す',
            'ホームページの文字や写真が一瞬でグングン拡大されます（下へ回すと縮小）',
            '元の100%サイズに戻したい時は、「Ctrl」キーを押しながら数字の「0」を押すだけ！'
        ],
        advice: 'Yahoo!ニュースやブログ、ネット検索を見るのが劇的に楽になります。教室のレッスンで感覚を掴んでみましょう！',
        btn1Label: '📅 パソコン便利技レッスン予約',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'battery_care',
        category: '🔋 スマホ長持ちのコツ',
        color: '#0284c7',
        label: '🔋 バッテリー長持ちの習慣',
        title: '🔋 スマートフォンのバッテリーを長持ちさせる3つの習慣',
        subtitle: '電池の減りが早くなってきたと感じたら、この使い方を試してみてください！',
        points: [
            '充電しながらの長時間の動画視聴や操作を避ける（発熱予防）',
            '画面の明るさを「自動調整」にするか、少し暗めに設定する',
            '使っていない時はWi-FiやBluetoothをこまめにオフにする'
        ],
        advice: '「夕方には充電が切れてしまう」「スマホが熱くなる」などの点検も教室で行っています。お気軽に診断へお越しください！',
        btn1Label: '🛠️ スマホ・PC健康診断を予約',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'photo_cleanup',
        category: '📸 写真・容量整理',
        color: '#0284c7',
        label: '📸 たまった写真の簡単整理術',
        title: '📸 スマホの容量がいっぱい？たまった写真の簡単整理術',
        subtitle: 'お孫さんの写真や旅行の写真でメモリがいっぱいになる前の安心お手入れ法です！',
        points: [
            'ブレた写真や連写写真、不要なスクリーンショットを先に削除',
            'お気に入りの写真には「♡（ハートマーク）」を付けて整理',
            'Googleフォトやパソコンへ定期バックアップしてスマホをスッキリ'
        ],
        advice: '「写真が消えたら怖い」「パソコンへ写真を移したい」時は、USBケーブルを持って教室へお越しください。安全なバックアップ手順をお教えします！',
        btn1Label: '📅 写真整理レッスンを予約',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'pc_restart_magic',
        category: '⚡ パソコン快適化',
        color: '#059669',
        label: '⚡ パソコン再起動の魔法',
        title: '⚡ パソコンが重い・動かない？「再起動」の魔法とシャットダウンの違い',
        subtitle: '調子が悪い時は、まず「再起動」を試すのが一番の特効薬です！',
        points: [
            'Windowsの「シャットダウン」は前回の状態を一部保存して終了します',
            '「再起動」を選ぶと、メモリが完全にリセットされて動作が軽くなります',
            '週に1〜2回は「スタート」→「電源」→「再起動」を行うのがオススメ'
        ],
        advice: '再起動しても動きが遅い・ファンが大きな音で回る場合は、不要ソフトの整理が必要かもしれません。教室でPC健康診断をお受けいただけます！',
        btn1Label: '🛠️ パソコン健康診断を予約',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'pc_shortcuts',
        category: '⌨️ パソコン便利技',
        color: '#059669',
        label: '⌨️ パソコン3大ショートカット',
        title: '⌨️ これだけは覚えたい！パソコン3大魔法のショートカットキー',
        subtitle: 'マウスで何度もカチカチ探すより、左手ひとつでパッと操作できるようになります！',
        points: [
            '【元に戻す】Ctrl ＋ Z（間違えて消してしまった文字や操作が一瞬で復活！）',
            '【コピー】Ctrl ＋ C（選んだ文字や写真をサッと複製）',
            '【貼り付け】Ctrl ＋ V（コピーした内容を好きな場所へペタッと貼る）'
        ],
        advice: 'Ctrl（コントロールキー）はキーボードの左下にあります！レッスンで実際に指を置いて練習してみましょう。',
        btn1Label: '📅 レッスン予約・日程変更',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'pc_caps_lock',
        category: '🔤 文字入力トラブル',
        color: '#059669',
        label: '🔤 勝手に大文字になる解決法',
        title: '🔤 文字が勝手に大文字になる？「Caps Lock」のワンキー解決法',
        subtitle: 'パスワードやアルファベットを入力した時、全部大文字になって困ったことはありませんか？',
        points: [
            '原因はキーボードの「Shift」と「Caps Lock」を一緒に押してしまったこと',
            '解決法: 「Shift」キーを押しながら「Caps Lock」キーをもう1度押すだけ！',
            'キーボード上の小さなランプ（Aのランプ）が消えれば通常入力に戻ります'
        ],
        advice: '入力トラブルの多くはキーボードのちょっとした押し間違いです。焦らず教室スタッフにいつでもご質問ください！',
        btn1Label: '📅 教室で質問・レッスン予約',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    },
    {
        id: 'disaster_apps',
        category: '🏥 安心・暮らしのデジタル',
        color: '#d97706',
        label: '🏥 防災・ハザードマップ活用',
        title: '🏥 いざという時に安心！スマホで見る防災速報・ハザードマップ',
        subtitle: '大雨や地震の際、スマホが命を守る一番の味方になります！',
        points: [
            '自治体の公式LINEや「Yahoo!防災速報」を登録しておくと警報が即届く',
            'スマホのGoogleマップで近くの「指定避難所」を事前確認しておく',
            '災害用伝言ダイヤル「171」やLINEでの安否確認方法を家族で決めておく'
        ],
        advice: '避難所の場所の登録や防災アプリの入れ方がわからない時は、教室でスタッフと一緒に設定しましょう！',
        btn1Label: '📅 防災アプリ設定を教室で相談',
        btn1Url: DEFAULT_PROLINE_BOOKING_URL
    }
];

function initKnowledgeBroadcastStudio() {
    if (!elements.knowledgeBroadcastModal) return;

    // 開閉イベント
    const openBtn = document.getElementById('openKnowledgeBroadcastModalBtn') || elements.openKnowledgeBroadcastModalBtn;
    openBtn?.addEventListener('click', () => openKnowledgeBroadcastModal());
    elements.closeKnowledgeBroadcastModalBtn?.addEventListener('click', closeKnowledgeBroadcastModal);
    elements.cancelKnowledgeBroadcastBtn?.addEventListener('click', closeKnowledgeBroadcastModal);

    // モーダル背景クリックで閉じる
    elements.knowledgeBroadcastModal.addEventListener('click', (e) => {
        if (e.target === elements.knowledgeBroadcastModal) {
            closeKnowledgeBroadcastModal();
        }
    });

    // プリセットチップの動的生成
    if (elements.kbPresetChipsWrap) {
        elements.kbPresetChipsWrap.innerHTML = '';
        SENIOR_KNOWLEDGE_PRESETS.forEach((preset, idx) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'kb-preset-btn' + (idx === 0 ? ' active' : '');
            btn.textContent = preset.label;
            btn.addEventListener('click', () => {
                applyKnowledgePreset(preset);
                elements.kbPresetChipsWrap.querySelectorAll('.kb-preset-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            });
            elements.kbPresetChipsWrap.appendChild(btn);
        });
    }

    // 配信対象ラジオボタン切り替え
    document.querySelectorAll('input[name="kbTargetType"]').forEach(radio => {
        radio.addEventListener('change', () => {
            updateKnowledgeTargetUI();
        });
    });

    // リアルタイムプレビュー連動イベント
    const inputIds = [
        'kbCategoryInput', 'kbColorSelect', 'kbTitleInput', 'kbSubtitleInput',
        'kbPoint1', 'kbPoint2', 'kbPoint3', 'kbAdviceInput', 'kbBtn1Label', 'kbBtn1Url'
    ];
    inputIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', updateKnowledgeLivePreview);
            el.addEventListener('change', updateKnowledgeLivePreview);
        }
    });

    // 送信ボタン
    elements.submitKnowledgeBroadcastBtn?.addEventListener('click', submitKnowledgeBroadcast);

    // 初期値として1件目のプリセットを反映
    if (SENIOR_KNOWLEDGE_PRESETS.length > 0) {
        applyKnowledgePreset(SENIOR_KNOWLEDGE_PRESETS[0]);
    }
}

function openKnowledgeBroadcastModal(targetUserId = null) {
    const modal = document.getElementById('knowledgeBroadcastModal') || elements.knowledgeBroadcastModal;
    if (!modal) return;

    // 受講生選択肢の更新
    populateKbTargetUsers(targetUserId);

    if (targetUserId) {
        const userRadio = document.querySelector('input[name="kbTargetType"][value="user"]');
        if (userRadio) userRadio.checked = true;
    } else {
        const allRadio = document.querySelector('input[name="kbTargetType"][value="all"]');
        if (allRadio) allRadio.checked = true;
    }

    updateKnowledgeTargetUI();
    updateKnowledgeLivePreview();

    modal.classList.add('active');
}

function closeKnowledgeBroadcastModal() {
    const modal = document.getElementById('knowledgeBroadcastModal') || elements.knowledgeBroadcastModal;
    if (modal) {
        modal.classList.remove('active');
    }
}

function populateKbTargetUsers(selectedUserId = null) {
    if (!elements.kbTargetUserSelect) return;
    elements.kbTargetUserSelect.innerHTML = '<option value="">受講生を選択してください...</option>';

    const lineUsers = state.allCustomers.filter(c => c.user_id && c.user_id.startsWith('U'));
    lineUsers.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.user_id;
        opt.textContent = `${c.user_name || '名前なし'} (${c.car_model || '受講コース未設定'})`;
        if (selectedUserId && c.user_id === selectedUserId) {
            opt.selected = true;
        }
        elements.kbTargetUserSelect.appendChild(opt);
    });
}

function updateKnowledgeTargetUI() {
    const targetType = document.querySelector('input[name="kbTargetType"]:checked')?.value || 'all';
    if (targetType === 'user') {
        if (elements.kbUserSelectWrap) elements.kbUserSelectWrap.style.display = 'block';
        const selectedUid = elements.kbTargetUserSelect?.value;
        const selectedCust = state.allCustomers.find(c => c.user_id === selectedUid);
        const name = selectedCust ? (selectedCust.user_name || '受講生') : '選択した受講生';
        if (elements.kbTargetSummaryText) {
            elements.kbTargetSummaryText.innerHTML = `配信先: <strong>👤 【${escapeHtml(name)} 様】へ個別送信</strong>`;
        }
    } else {
        if (elements.kbUserSelectWrap) elements.kbUserSelectWrap.style.display = 'none';
        if (elements.kbTargetSummaryText) {
            elements.kbTargetSummaryText.innerHTML = '配信先: <strong>👥 LINE公式アカウントの友だち全員（一斉配信）</strong>';
        }
    }
}

if (elements.kbTargetUserSelect) {
    elements.kbTargetUserSelect.addEventListener('change', updateKnowledgeTargetUI);
}

function applyKnowledgePreset(preset) {
    if (!preset) return;
    if (elements.kbCategoryInput) elements.kbCategoryInput.value = preset.category;
    if (elements.kbColorSelect) elements.kbColorSelect.value = preset.color;
    if (elements.kbTitleInput) elements.kbTitleInput.value = preset.title;
    if (elements.kbSubtitleInput) elements.kbSubtitleInput.value = preset.subtitle;
    if (elements.kbPoint1) elements.kbPoint1.value = preset.points[0] || '';
    if (elements.kbPoint2) elements.kbPoint2.value = preset.points[1] || '';
    if (elements.kbPoint3) elements.kbPoint3.value = preset.points[2] || '';
    if (elements.kbAdviceInput) elements.kbAdviceInput.value = preset.advice || '';
    if (elements.kbBtn1Label) elements.kbBtn1Label.value = preset.btn1Label || '📅 教室で直接相談・予約する';
    if (elements.kbBtn1Url) elements.kbBtn1Url.value = preset.btn1Url || DEFAULT_PROLINE_BOOKING_URL;

    updateKnowledgeLivePreview();
}

function updateKnowledgeLivePreview() {
    const category = elements.kbCategoryInput?.value || '安心・セキュリティ';
    const color = elements.kbColorSelect?.value || '#e11d48';
    const title = elements.kbTitleInput?.value || 'お役立ち情報タイトル';
    const subtitle = elements.kbSubtitleInput?.value || '';
    const p1 = elements.kbPoint1?.value || '';
    const p2 = elements.kbPoint2?.value || '';
    const p3 = elements.kbPoint3?.value || '';
    const advice = elements.kbAdviceInput?.value || '';
    const btn1Text = elements.kbBtn1Label?.value || '📅 教室で直接相談・予約する';

    if (elements.prevKbBadge) {
        elements.prevKbBadge.textContent = '💡 ' + category;
        elements.prevKbBadge.style.background = color;
    }
    if (elements.prevKbTitle) elements.prevKbTitle.textContent = title;
    if (elements.prevKbSubtitle) {
        elements.prevKbSubtitle.textContent = subtitle;
        elements.prevKbSubtitle.style.display = subtitle ? 'block' : 'none';
    }

    // ポイント
    const updatePointRow = (rowEl, iconEl, iconText, text) => {
        if (!rowEl) return;
        if (text) {
            rowEl.style.display = 'flex';
            rowEl.querySelector('span:last-child').textContent = text;
            if (iconEl) iconEl.style.color = color;
        } else {
            rowEl.style.display = 'none';
        }
    };
    updatePointRow(elements.prevKbPoint1, elements.prevKbPointIcon1, '①', p1);
    updatePointRow(elements.prevKbPoint2, elements.prevKbPointIcon2, '②', p2);
    updatePointRow(elements.prevKbPoint3, elements.prevKbPointIcon3, '③', p3);

    // 先生のアドバイス
    if (elements.prevKbAdviceBox) {
        elements.prevKbAdviceBox.style.display = advice ? 'block' : 'none';
    }
    if (elements.prevKbAdviceText) {
        elements.prevKbAdviceText.textContent = advice;
    }

    // ボタン
    if (elements.prevKbBtn1) {
        elements.prevKbBtn1.textContent = btn1Text;
        elements.prevKbBtn1.style.background = color;
    }
}

async function submitKnowledgeBroadcast() {
    const title = elements.kbTitleInput?.value.trim();
    if (!title) {
        alert('記事タイトルを入力してください');
        return;
    }

    const targetType = document.querySelector('input[name="kbTargetType"]:checked')?.value || 'all';
    let targetUid = '';
    let targetName = 'LINE友だち全員';

    if (targetType === 'user') {
        targetUid = elements.kbTargetUserSelect?.value;
        if (!targetUid) {
            alert('送信先の受講生を選択してください');
            return;
        }
        const cust = state.allCustomers.find(c => c.user_id === targetUid);
        targetName = cust ? `${cust.user_name || '受講生'} 様` : '指定受講生';
    }

    const confirmMsg = (targetType === 'all')
        ? `【LINE公式の友だち全員（一斉配信）】へ、お役立ちリッチカード「${title}」を今すぐ配信しますか？\n（※全受講生のトーク画面へ即座に送信されます）`
        : `【${targetName}】へ、お役立ちリッチカード「${title}」をLINE送信しますか？`;

    if (!confirm(confirmMsg)) return;

    const btn = elements.submitKnowledgeBroadcastBtn;
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> LINE送信中...';
    }

    const points = [
        elements.kbPoint1?.value.trim(),
        elements.kbPoint2?.value.trim(),
        elements.kbPoint3?.value.trim()
    ].filter(Boolean);

    const payload = new URLSearchParams({
        action: 'admin_send_knowledge_message',
        password: state.password,
        target_type: targetType,
        uid: targetUid,
        category: elements.kbCategoryInput?.value.trim() || 'スマホ・パソコンお役立ち',
        badge_color: elements.kbColorSelect?.value || '#e11d48',
        title: title,
        subtitle: elements.kbSubtitleInput?.value.trim() || '',
        points: JSON.stringify(points),
        advice: elements.kbAdviceInput?.value.trim() || '',
        btn1_label: elements.kbBtn1Label?.value.trim() || '📅 教室で直接相談・予約する',
        btn1_url: elements.kbBtn1Url?.value.trim() || DEFAULT_PROLINE_BOOKING_URL,
        btn2_label: '💬 LINEで質問・相談する'
    });

    try {
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const rawText = await res.text();
        let data = null;
        try {
            data = JSON.parse(rawText);
        } catch (jsonErr) {
            console.error('API raw response parse error:', rawText);
            throw new Error(rawText ? rawText.substring(0, 300) : 'サーバーからの応答の解析に失敗しました');
        }

        if (data && data.success) {
            showToast(data.message || 'お役立ち情報をLINE送信しました！');
            closeKnowledgeBroadcastModal();
            await fetchCustomers();
        } else {
            alert((data && data.error) ? data.error : '送信に失敗しました');
        }
    } catch (e) {
        console.error('Knowledge broadcast error:', e);
        alert('送信エラーが発生しました: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    }
}

window.openKnowledgeBroadcastModal = openKnowledgeBroadcastModal;
window.closeKnowledgeBroadcastModal = closeKnowledgeBroadcastModal;



