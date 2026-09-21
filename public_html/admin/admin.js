/**
 * アップファーレン 顧客メンテナンス管理ダッシュボード JS
 */

const state = {
    password: '',
    authToken: '',
    twoFactorSessionToken: '',
    resendTimerInterval: null,
    resendCountdown: 0,
    allCustomers: [],
    currentFilter: 'all',
    currentSort: 'last_interaction',
    searchQuery: '',
    richMenus: [],
    activeUserMenuCust: null,
    loadedBaseImg: null,
    userMenuBannerBounds: null,
    userMenuHeightMode: 'auto',
    // マルチアカウント管理
    activeAccount: localStorage.getItem('active_line_account') || 'senior',
    accounts: [],
    // ページネーション & 検索
    currentPage: 1,
    pageSize: 50,
    searchDebounceTimer: null,
    currentFilteredList: [],
    // チャット & Discord
    unreadChatCounts: {},
    activeChatUser: null,
    chatPollTimer: null
};

// アプリケーション直下のベースURLを自動取得（どのディレクトリ名・階層に配置されても自動追従）
function getAppBaseUrl() {
    const loc = window.location;
    let path = loc.pathname;
    path = path.substring(0, path.lastIndexOf('/'));
    if (path.endsWith('/admin')) {
        path = path.substring(0, path.length - 6);
    }
    return `${loc.protocol}//${loc.host}${path}`;
}

// APIリクエストに安全な認証ヘッダーとアクティブアカウントを自動付与するfetchインターセプター
const originalFetch = window.fetch;
window.fetch = function (resource, init = {}) {
    let url = (typeof resource === 'string') ? resource : (resource && resource.url ? resource.url : '');
    if (url.includes('api.php')) {
        const getCookie = (name) => {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return decodeURIComponent(parts.pop().split(';').shift());
            return '';
        };
        const currentToken = state.authToken || sessionStorage.getItem('admin_auth_token') || getCookie('admin_auth_token') || '';
        const currentPass = state.password || sessionStorage.getItem('admin_pass') || getCookie('admin_pass') || '';
        if (!init.headers) {
            init.headers = {};
        }
        if (init.headers instanceof Headers) {
            if (currentToken && !init.headers.has('X-Admin-Auth-Token')) {
                init.headers.set('X-Admin-Auth-Token', currentToken);
            }
            if (currentPass && !init.headers.has('X-Admin-Password')) {
                init.headers.set('X-Admin-Password', currentPass);
            }
            if (currentToken && !init.headers.has('Authorization')) {
                init.headers.set('Authorization', 'Bearer ' + currentToken);
            } else if (currentPass && !init.headers.has('Authorization')) {
                init.headers.set('Authorization', 'Bearer ' + currentPass);
            }
            if (state.activeAccount && !init.headers.has('X-Line-Account')) {
                init.headers.set('X-Line-Account', state.activeAccount);
            }
        } else if (Array.isArray(init.headers)) {
            if (currentToken && !init.headers.some(h => h[0].toLowerCase() === 'x-admin-auth-token')) {
                init.headers.push(['X-Admin-Auth-Token', currentToken]);
            }
            if (currentPass && !init.headers.some(h => h[0].toLowerCase() === 'x-admin-password')) {
                init.headers.push(['X-Admin-Password', currentPass]);
            }
            if (currentToken && !init.headers.some(h => h[0].toLowerCase() === 'authorization')) {
                init.headers.push(['Authorization', 'Bearer ' + currentToken]);
            } else if (currentPass && !init.headers.some(h => h[0].toLowerCase() === 'authorization')) {
                init.headers.push(['Authorization', 'Bearer ' + currentPass]);
            }
            if (state.activeAccount && !init.headers.some(h => h[0].toLowerCase() === 'x-line-account')) {
                init.headers.push(['X-Line-Account', state.activeAccount]);
            }
        } else {
            if (currentToken && !init.headers['X-Admin-Auth-Token']) {
                init.headers['X-Admin-Auth-Token'] = currentToken;
            }
            if (currentPass && !init.headers['X-Admin-Password']) {
                init.headers['X-Admin-Password'] = currentPass;
            }
            if (currentToken && !init.headers['Authorization']) {
                init.headers['Authorization'] = 'Bearer ' + currentToken;
            } else if (currentPass && !init.headers['Authorization']) {
                init.headers['Authorization'] = 'Bearer ' + currentPass;
            }
            if (state.activeAccount && !init.headers['X-Line-Account']) {
                init.headers['X-Line-Account'] = state.activeAccount;
            }
        }

        // FastCGI / プロキシ等でHTTPヘッダーが欠落する環境への安全なフォールバック: URLパラメータ & POSTボディへのパスワード/トークン自動付与
        if (currentToken && !url.includes('auth_token=')) {
            url += (url.includes('?') ? '&' : '?') + 'auth_token=' + encodeURIComponent(currentToken);
        }
        if (currentPass && !url.includes('password=')) {
            url += (url.includes('?') ? '&' : '?') + 'password=' + encodeURIComponent(currentPass);
        }
        if (currentToken || currentPass) {
            if (typeof resource === 'string') {
                resource = url;
            } else if (resource && resource.url) {
                resource = new Request(url, init);
            }
            if (currentPass) {
                if (init && init.body && init.body instanceof URLSearchParams && !init.body.has('password')) {
                    init.body.append('password', currentPass);
                }
                if (init && init.body && typeof FormData !== 'undefined' && init.body instanceof FormData && !init.body.has('password')) {
                    init.body.append('password', currentPass);
                }
            }
        }

        if (state.activeAccount) {
            const acc = state.activeAccount;
            if (!url.includes('account=')) {
                url += (url.includes('?') ? '&' : '?') + 'account=' + encodeURIComponent(acc);
                if (typeof resource === 'string') {
                    resource = url;
                } else if (resource && resource.url) {
                    resource = new Request(url, init);
                }
            }
            // POSTボディにURLSearchParamsまたはFormDataがある場合もaccountを付与
            if (init && init.body && init.body instanceof URLSearchParams && !init.body.has('account')) {
                init.body.append('account', acc);
            }
            if (init && init.body && typeof FormData !== 'undefined' && init.body instanceof FormData && !init.body.has('account')) {
                init.body.append('account', acc);
            }
        }
    }
    return originalFetch.call(this, resource, init);
};

const elements = {
    loginModal: document.getElementById('loginModal'),
    loginStep1Wrap: document.getElementById('loginStep1Wrap'),
    loginStep2Wrap: document.getElementById('loginStep2Wrap'),
    login2FAEmailHint: document.getElementById('login2FAEmailHint'),
    adminPasswordInput: document.getElementById('adminPasswordInput'),
    admin2FACodeInput: document.getElementById('admin2FACodeInput'),
    loginBtn: document.getElementById('loginBtn'),
    btnVerify2FA: document.getElementById('btnVerify2FA'),
    btnBackToPassword: document.getElementById('btnBackToPassword'),
    btnResend2FACode: document.getElementById('btnResend2FACode'),
    resendTimerText: document.getElementById('resendTimerText'),
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
    btnClearSearch: document.getElementById('btnClearSearch'),
    adminTagFilterSelect: document.getElementById('adminTagFilterSelect'),
    filterMatchedCount: document.getElementById('filterMatchedCount'),
    adminSortSelect: document.getElementById('adminSortSelect'),
    tabBtns: document.querySelectorAll('.tab-btn'),
    tabCountAll: document.getElementById('tabCountAll'),
    tabCountOil: document.getElementById('tabCountOil'),
    tabCountPeriodic: document.getElementById('tabCountPeriodic'),
    tabCountInsp: document.getElementById('tabCountInsp'),

    // 一括操作
    selectAllCheckbox: document.getElementById('selectAllCheckbox'),
    bulkActionBar: document.getElementById('bulkActionBar'),
    bulkSelectedCount: document.getElementById('bulkSelectedCount'),
    btnBulkAddTags: document.getElementById('btnBulkAddTags'),
    btnBulkRemoveTags: document.getElementById('btnBulkRemoveTags'),
    btnBulkClearSelection: document.getElementById('btnBulkClearSelection'),
    bulkTagModal: document.getElementById('bulkTagModal'),
    bulkTagModalTitle: document.getElementById('bulkTagModalTitle'),
    bulkTagModalDesc: document.getElementById('bulkTagModalDesc'),
    bulkTagInput: document.getElementById('bulkTagInput'),
    bulkTagSuggestions: document.getElementById('bulkTagSuggestions'),
    closeBulkTagModalBtn: document.getElementById('closeBulkTagModalBtn'),
    cancelBulkTagBtn: document.getElementById('cancelBulkTagBtn'),
    executeBulkTagBtn: document.getElementById('executeBulkTagBtn'),
    bulkTagStatusMsg: document.getElementById('bulkTagStatusMsg'),

    // テーブル
    customerTableBody: document.getElementById('customerTableBody'),
    emptyTablePlaceholder: document.getElementById('emptyTablePlaceholder'),

    // ページネーション
    paginationBar: document.getElementById('paginationBar'),
    paginationInfoText: document.getElementById('paginationInfoText'),
    pageSizeSelect: document.getElementById('pageSizeSelect'),
    paginationNav: document.getElementById('paginationNav'),

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
    editTagsInput: document.getElementById('editTagsInput'),
    editTagSuggestions: document.getElementById('editTagSuggestions'),
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
    userMenuBannerHeightInput: document.getElementById('userMenuBannerHeightInput'),
    userMenuBannerHeightNumber: document.getElementById('userMenuBannerHeightNumber'),
    userMenuHeightModeBadge: document.getElementById('userMenuHeightModeBadge'),
    chipHeightAuto: document.getElementById('chipHeightAuto'),
    userMenuTextAlignV: document.getElementById('userMenuTextAlignV'),
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
    notifyChatCheck: document.getElementById('notifyChatCheck'),
    notifyFollowCheck: document.getElementById('notifyFollowCheck'),
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

    // Discord通知設定モーダル
    openDiscordSettingsBtn: document.getElementById('openDiscordSettingsBtn'),
    discordSettingsModal: document.getElementById('discordSettingsModal'),
    btnCloseDiscordModal: document.getElementById('btnCloseDiscordModal'),
    btnCancelDiscordModal: document.getElementById('btnCancelDiscordModal'),
    discordWebhookUrlInput: document.getElementById('discordWebhookUrlInput'),
    discordNotifyMessage: document.getElementById('discordNotifyMessage'),
    discordNotifyFollow: document.getElementById('discordNotifyFollow'),
    discordNotifyConsultation: document.getElementById('discordNotifyConsultation'),
    btnTestDiscordWebhook: document.getElementById('btnTestDiscordWebhook'),
    btnSaveDiscordSettings: document.getElementById('btnSaveDiscordSettings'),
    discordTestStatusBanner: document.getElementById('discordTestStatusBanner'),

    // メール二段階認証 (2FA) セキュリティ設定モーダル
    open2FASettingsBtn: document.getElementById('open2FASettingsBtn'),
    twoFaSettingsModal: document.getElementById('twoFaSettingsModal'),
    btnClose2FASettingsModal: document.getElementById('btnClose2FASettingsModal'),
    btnCancel2FAModal: document.getElementById('btnCancel2FAModal'),
    twoFaEnabledToggle: document.getElementById('twoFaEnabledToggle'),
    twoFaEmailInput: document.getElementById('twoFaEmailInput'),
    twoFaLifetimeInput: document.getElementById('twoFaLifetimeInput'),
    twoFaMaxAttemptsInput: document.getElementById('twoFaMaxAttemptsInput'),
    btnTest2FAEmail: document.getElementById('btnTest2FAEmail'),
    btnSave2FASettings: document.getElementById('btnSave2FASettings'),
    twoFaStatusBanner: document.getElementById('twoFaStatusBanner'),

    // Slack通知設定モーダル
    openSlackSettingsBtn: document.getElementById('openSlackSettingsBtn'),
    slackSettingsModal: document.getElementById('slackSettingsModal'),
    btnCloseSlackModal: document.getElementById('btnCloseSlackModal'),
    btnCancelSlackModal: document.getElementById('btnCancelSlackModal'),
    slackWebhookUrlInput: document.getElementById('slackWebhookUrlInput'),
    slackNotifyMessage: document.getElementById('slackNotifyMessage'),
    slackNotifyFollow: document.getElementById('slackNotifyFollow'),
    slackNotifyConsultation: document.getElementById('slackNotifyConsultation'),
    btnTestSlackWebhook: document.getElementById('btnTestSlackWebhook'),
    btnSaveSlackSettings: document.getElementById('btnSaveSlackSettings'),
    slackTestStatusBanner: document.getElementById('slackTestStatusBanner'),

    // 会社DX アンケートモーダル
    openDxSurveyModalBtn: document.getElementById('openDxSurveyModalBtn'),
    dxSurveyModal: document.getElementById('dxSurveyModal'),
    closeDxSurveyModalBtn: document.getElementById('closeDxSurveyModalBtn'),
    cancelDxSurveyBtn: document.getElementById('cancelDxSurveyBtn'),
    submitDxSurveyBtn: document.getElementById('submitDxSurveyBtn'),
    dxSurveyUserSelect: document.getElementById('dxSurveyUserSelect'),
    dxSurveySingleTargetWrap: document.getElementById('dxSurveySingleTargetWrap'),
    dxSurveyTargetSummaryText: document.getElementById('dxSurveyTargetSummaryText'),

    // 個別LINEチャットモーダル
    chatModal: document.getElementById('chatModal'),
    btnCloseChatModal: document.getElementById('btnCloseChatModal'),

    // LINE友だち一括同期 プログレスモーダル
    syncProgressModal: document.getElementById('syncProgressModal'),
    btnCloseSyncProgressModal: document.getElementById('btnCloseSyncProgressModal'),
    btnFinishSyncModal: document.getElementById('btnFinishSyncModal'),
    syncProgressStatusText: document.getElementById('syncProgressStatusText'),
    syncProgressPercent: document.getElementById('syncProgressPercent'),
    syncProgressBar: document.getElementById('syncProgressBar'),
    syncProgressCount: document.getElementById('syncProgressCount'),
    syncProgressSpeed: document.getElementById('syncProgressSpeed'),
    syncProgressImportedCount: document.getElementById('syncProgressImportedCount'),
    syncProgressUpdatedCount: document.getElementById('syncProgressUpdatedCount'),
    syncLogBox: document.getElementById('syncLogBox'),
    syncProgressActions: document.getElementById('syncProgressActions'),
    btnRefreshChatMessages: document.getElementById('btnRefreshChatMessages'),
    chatModalAvatar: document.getElementById('chatModalAvatar'),
    chatModalUserName: document.getElementById('chatModalUserName'),
    chatModalUidTag: document.getElementById('chatModalUidTag'),
    chatModalCourseInfo: document.getElementById('chatModalCourseInfo'),
    chatModalTagsRow: document.getElementById('chatModalTagsRow'),
    chatMessagesContainer: document.getElementById('chatMessagesContainer'),
    chatInputText: document.getElementById('chatInputText'),
    btnSendChatMessage: document.getElementById('btnSendChatMessage'),

    toast: document.getElementById('adminToast')
};

state.allTags = [];
state.currentTagFilter = '';
state.selectedCustomerIds = new Set();
state.bulkTagMode = 'add';

document.addEventListener('DOMContentLoaded', async () => {
    await loadAccounts();
    initAuth();
    initEventListeners();
    initKnowledgeBroadcastStudio();
    initDxSurveyModal();
    initAccountManagement();
    initChatModal();
    initDiscordSettings();
    initSlackSettings();
    init2FASettings();
    initQuickReplySettings();
    initSystemUpdater();
    initBrowserNotifControls();
    await initWebPushServiceWorker();
    updateBrowserNotifUi();
    loadUnreadChatCounts();
    if (!globalChatUnreadTimer) {
        globalChatUnreadTimer = setInterval(loadUnreadChatCounts, 4000);
    }
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
            if (state.activeAccountInfo && state.activeAccountInfo.custom_labels) {
                applyDynamicLabels(state.activeAccountInfo.custom_labels);
            }
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
    const isDx = (state.activeAccount === 'kaisya_dx');
    if (currentAcc) {
        if (elements.systemBrandTitle) {
            elements.systemBrandTitle.textContent = `${currentAcc.name} 管理ダッシュボード`;
        }
        if (elements.systemBrandBadge) {
            elements.systemBrandBadge.style.background = currentAcc.theme_color || '#4f46e5';
        }
        if (elements.accountBadgeDot) {
            elements.accountBadgeDot.style.background = currentAcc.theme_color || '#ff8700';
        }
    }

    // アカウントに応じた配信ボタン出し分け (kaisya_dx vs senior)
    const dxSurveyBtn = document.getElementById('openDxSurveyModalBtn') || elements.openDxSurveyModalBtn;
    const kbBtn = document.getElementById('openKnowledgeBroadcastModalBtn') || elements.openKnowledgeBroadcastModalBtn;
    if (dxSurveyBtn) dxSurveyBtn.style.display = isDx ? 'inline-flex' : 'none';
    if (kbBtn) kbBtn.style.display = isDx ? 'none' : 'inline-flex';
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
            if (state.activeAccountInfo && state.activeAccountInfo.custom_labels) {
                applyDynamicLabels(state.activeAccountInfo.custom_labels);
            }
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

    const btnCopyProlineEvent = document.getElementById('btnCopyAccProlineEventUrl');
    if (btnCopyProlineEvent) {
        btnCopyProlineEvent.addEventListener('click', () => {
            const urlField = document.getElementById('accFormDisplayProlineEventUrl');
            if (urlField && urlField.value) {
                navigator.clipboard.writeText(urlField.value).then(() => {
                    showToast('プロライン連携用 Webhook URLをコピーしました！');
                }).catch(() => {
                    urlField.select();
                    document.execCommand('copy');
                    showToast('プロライン連携用 Webhook URLをコピーしました！');
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
                formData.append('password', state.password || sessionStorage.getItem('admin_pass') || '');
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

    const baseUrl = getAppBaseUrl();

    wrap.innerHTML = state.accounts.map(acc => {
        const isActive = acc.id === state.activeAccount;
        const isDefault = !!acc.is_default;
        const isConfigured = !!acc.is_configured;
        const color = acc.theme_color || '#6366f1';
        const whUrl = `${baseUrl}/webhook.php${isDefault ? '' : '?account=' + encodeURIComponent(acc.id)}`;

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
                            DB: <code>${escapeHtml(acc.db_file || 'kureba-senior-system.db')}</code><br>
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

window.INDUSTRY_PRESETS = {
    senior: {
        name: 'パソコン教室・シニア向け',
        item1: '受講コース',
        item2: '使用機器・PC環境',
        date1: '次回レッスン',
        date2: 'PC健康診断',
        date3: '会員・月謝更新'
    },
    auto: {
        name: '自動車販売・整備工場',
        item1: '車種名',
        item2: '車両ナンバー',
        date1: '次回オイル交換',
        date2: '12ヶ月定期点検',
        date3: '車検満了日'
    },
    salon: {
        name: 'サロン・エステ・整体院',
        item1: '施術メニュー・コース',
        item2: 'カルテ番号・担当者',
        date1: '次回施術・来店予約',
        date2: '定期メンテナンス',
        date3: '回数券・会員期限'
    },
    school: {
        name: 'スクール・学習塾・習い事',
        item1: '受講クラス・学年',
        item2: '生徒番号・所属',
        date1: '次回授業・レッスン',
        date2: '定期面談・検定日',
        date3: '月謝・年会費更新'
    },
    fitness: {
        name: 'ジム・フィットネス',
        item1: '会員プラン・コース',
        item2: '会員番号・ロッカー',
        date1: '次回トレーニング予約',
        date2: '定期測定・カウンセリング',
        date3: '会費更新日'
    },
    b2b: {
        name: '士業・コンサル・B2Bサポート',
        item1: '契約プラン・種別',
        item2: '企業ID・担当者名',
        date1: '次回定期面談',
        date2: '進捗レビュー日',
        date3: '年間契約更新日'
    },
    custom: {
        name: '自由設定（カスタム）',
        item1: '項目1',
        item2: '項目2',
        date1: '期日1',
        date2: '期日2',
        date3: '期日3'
    }
};

window.onAccFormIndustryChange = function(typeKey) {
    const preset = window.INDUSTRY_PRESETS[typeKey];
    if (!preset) return;
    if (typeKey === 'custom') return;
    const item1 = document.getElementById('accFormLabelItem1');
    const item2 = document.getElementById('accFormLabelItem2');
    const date1 = document.getElementById('accFormLabelDate1');
    const date2 = document.getElementById('accFormLabelDate2');
    const date3 = document.getElementById('accFormLabelDate3');
    if (item1) item1.value = preset.item1;
    if (item2) item2.value = preset.item2;
    if (date1) date1.value = preset.date1;
    if (date2) date2.value = preset.date2;
    if (date3) date3.value = preset.date3;
};

function normalizeCustomLabels(labels) {
    if (!labels) {
        return {
            item1: '受講コース',
            item2: '使用機器',
            date1: '次回レッスン',
            date2: 'PC健康診断',
            date3: '会員・月謝更新',
            customerTerm: '受講生'
        };
    }
    return {
        item1: labels.label_item1 || labels.item1 || '項目1',
        item2: labels.label_item2 || labels.item2 || '項目2',
        date1: labels.label_date1 || labels.date1 || '期日1',
        date2: labels.label_date2 || labels.date2 || '期日2',
        date3: labels.label_date3 || labels.date3 || '期日3',
        customerTerm: labels.customer_term || labels.customerTerm || '顧客'
    };
}

function applyDynamicLabels(rawLabels) {
    if (!rawLabels) return;
    const labels = normalizeCustomLabels(rawLabels);
    state.customLabels = labels;
    
    // 1. 統計カード
    const stat1 = document.getElementById('lblStatOilSoon');
    const stat2 = document.getElementById('lblStatPeriodicSoon');
    const stat3 = document.getElementById('lblStatInspSoon');
    if (stat1) stat1.textContent = `${labels.date1}近し (30日以内)`;
    if (stat2) stat2.textContent = `${labels.date2}近し (30日以内)`;
    if (stat3) stat3.textContent = `${labels.date3}近し (30日以内)`;

    // 2. フィルタータブ
    const tab1 = document.getElementById('lblTabOil');
    const tab2 = document.getElementById('lblTabPeriodic');
    const tab3 = document.getElementById('lblTabInsp');
    if (tab1) tab1.textContent = labels.date1;
    if (tab2) tab2.textContent = labels.date2;
    if (tab3) tab3.textContent = labels.date3;

    // 3. 並び替えオプション
    const opt1 = document.getElementById('optSortOil');
    const opt2 = document.getElementById('optSortPeriodic');
    const opt3 = document.getElementById('optSortInsp');
    if (opt1) opt1.textContent = `${labels.date1}が近い順`;
    if (opt2) opt2.textContent = `${labels.date2}が近い順`;
    if (opt3) opt3.textContent = `${labels.date3}が近い順`;

    // 4. カラム表示切替ドロップダウン
    const colCourse = document.getElementById('colPickerLabelCourse');
    const colOil = document.getElementById('colPickerLabelOil');
    const colPeriodic = document.getElementById('colPickerLabelPeriodic');
    const colInsp = document.getElementById('colPickerLabelInsp');
    if (colCourse) colCourse.innerHTML = `<i class="fa-solid fa-tag"></i> ${escapeHtml(labels.item1)}・${escapeHtml(labels.item2)}`;
    if (colOil) colOil.innerHTML = `<i class="fa-solid fa-calendar-day"></i> ${escapeHtml(labels.date1)}`;
    if (colPeriodic) colPeriodic.innerHTML = `<i class="fa-solid fa-calendar-check"></i> ${escapeHtml(labels.date2)}`;
    if (colInsp) colInsp.innerHTML = `<i class="fa-solid fa-calendar-days"></i> ${escapeHtml(labels.date3)}`;

    // 5. テーブルヘッダー
    const thCourse = document.getElementById('thCourse');
    const thOil = document.getElementById('thOil');
    const thPeriodic = document.getElementById('thPeriodic');
    const thInsp = document.getElementById('thInsp');
    if (thCourse) thCourse.textContent = `${labels.item1}・${labels.item2}`;
    if (thOil) thOil.textContent = labels.date1;
    if (thPeriodic) thPeriodic.textContent = labels.date2;
    if (thInsp) thInsp.textContent = labels.date3;

    // 6. 顧客登録・編集モーダル
    const lblEditItem1 = document.getElementById('lblEditCarModel');
    const lblEditItem2 = document.getElementById('lblEditCarNumber');
    const lblEditOilLast = document.getElementById('lblEditOilLastDate');
    const lblEditDate1 = document.getElementById('lblEditOilNextDate');
    const lblEditDate2 = document.getElementById('lblEditPeriodicNextDate');
    const lblEditDate3 = document.getElementById('lblEditInspectionNextDate');
    if (lblEditItem1) lblEditItem1.textContent = `${labels.item1} (主項目)`;
    if (lblEditItem2) lblEditItem2.textContent = `${labels.item2} (番号・詳細)`;
    if (lblEditOilLast) lblEditOilLast.textContent = `前回 利用・実施日 (${labels.date1})`;
    if (lblEditDate1) lblEditDate1.textContent = `次回 予定日 (${labels.date1})`;
    if (lblEditDate2) lblEditDate2.textContent = `定期メンテ・診断日 (${labels.date2})`;
    if (lblEditDate3) lblEditDate3.textContent = `更新・満了期日 (${labels.date3})`;

    // モーダルのプレースホルダー
    const inItem1 = document.getElementById('editCarModel');
    const inItem2 = document.getElementById('editCarNumber');
    if (inItem1) inItem1.placeholder = `例: ${labels.item1}を入力`;
    if (inItem2) inItem2.placeholder = `例: ${labels.item2}を入力`;

    // 7. 検索入力ボックスのプレースホルダー
    const searchIn = document.getElementById('adminSearchInput');
    if (searchIn) {
        searchIn.placeholder = `顧客名、${labels.item1}、${labels.item2}、メモで検索...`;
    }
}

/**
 * 項目名・期日名クイック変更モーダルの制御
 */
function openQuickLabelModal(focusTarget = null) {
    const modal = document.getElementById('quickLabelModal');
    if (!modal) return;

    const labels = state.customLabels || normalizeCustomLabels(null);
    const i1 = document.getElementById('quickLabelItem1');
    const i2 = document.getElementById('quickLabelItem2');
    const d1 = document.getElementById('quickLabelDate1');
    const d2 = document.getElementById('quickLabelDate2');
    const d3 = document.getElementById('quickLabelDate3');
    const presetSelect = document.getElementById('quickLabelPresetSelect');

    if (i1) i1.value = labels.item1 || '';
    if (i2) i2.value = labels.item2 || '';
    if (d1) d1.value = labels.date1 || '';
    if (d2) d2.value = labels.date2 || '';
    if (d3) d3.value = labels.date3 || '';

    // 現在の業種プリセットに合わせて初期選択
    if (presetSelect) {
        let matchedKey = 'custom';
        for (const [k, p] of Object.entries(window.INDUSTRY_PRESETS)) {
            if (p.item1 === labels.item1 && p.date1 === labels.date1) {
                matchedKey = k;
                break;
            }
        }
        presetSelect.value = matchedKey;
    }

    modal.classList.add('active');

    // フォーカス制御
    setTimeout(() => {
        if (focusTarget === 'course' && i1) i1.focus();
        else if (focusTarget === 'date1' && d1) d1.focus();
        else if (focusTarget === 'date2' && d2) d2.focus();
        else if (focusTarget === 'date3' && d3) d3.focus();
        else if (i1) i1.focus();
    }, 100);
}

function closeQuickLabelModal() {
    const modal = document.getElementById('quickLabelModal');
    if (modal) modal.classList.remove('active');
}

async function saveQuickLabels() {
    const btnSave = document.getElementById('btnSaveQuickLabels');
    const i1 = document.getElementById('quickLabelItem1');
    const i2 = document.getElementById('quickLabelItem2');
    const d1 = document.getElementById('quickLabelDate1');
    const d2 = document.getElementById('quickLabelDate2');
    const d3 = document.getElementById('quickLabelDate3');
    const presetSelect = document.getElementById('quickLabelPresetSelect');

    const item1Val = i1 ? i1.value.trim() : '';
    const item2Val = i2 ? i2.value.trim() : '';
    const date1Val = d1 ? d1.value.trim() : '';
    const date2Val = d2 ? d2.value.trim() : '';
    const date3Val = d3 ? d3.value.trim() : '';
    const industryVal = presetSelect ? presetSelect.value : 'senior';

    if (!item1Val || !date1Val) {
        alert('項目1名と期日1名は必須です。');
        return;
    }

    try {
        if (btnSave) {
            btnSave.disabled = true;
            btnSave.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 保存中...';
        }

        const formData = new FormData();
        formData.append('account', state.activeAccount);
        formData.append('industry_type', industryVal);
        formData.append('label_item1', item1Val);
        formData.append('label_item2', item2Val);
        formData.append('label_date1', date1Val);
        formData.append('label_date2', date2Val);
        formData.append('label_date3', date3Val);
        formData.append('password', state.password || sessionStorage.getItem('admin_pass') || '');

        const res = await fetch('../api.php?action=save_custom_labels', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showToast('✅ 項目名・期日名を更新しました！');
            closeQuickLabelModal();
            if (data.custom_labels) {
                applyDynamicLabels(data.custom_labels);
            }
            // 顧客テーブル再描画
            renderTable();
        } else {
            alert(`保存失敗: ${data.error || '不明なエラー'}`);
        }
    } catch (e) {
        console.error('Quick labels save error:', e);
        alert('通信エラーが発生しました: ' + e.message);
    } finally {
        if (btnSave) {
            btnSave.disabled = false;
            btnSave.innerHTML = '<i class="fa-solid fa-check"></i> 変更を保存して反映';
        }
    }
}

function initQuickLabelModalEvents() {
    const btnOpen = document.getElementById('btnOpenQuickLabelModal');
    const btnClose = document.getElementById('closeQuickLabelModalBtn');
    const btnCloseFooter = document.getElementById('closeQuickLabelModalFooterBtn');
    const btnSave = document.getElementById('btnSaveQuickLabels');
    const modal = document.getElementById('quickLabelModal');
    const presetSelect = document.getElementById('quickLabelPresetSelect');

    if (btnOpen) {
        btnOpen.addEventListener('click', (e) => {
            e.stopPropagation();
            openQuickLabelModal();
        });
    }

    if (btnClose) btnClose.addEventListener('click', closeQuickLabelModal);
    if (btnCloseFooter) btnCloseFooter.addEventListener('click', closeQuickLabelModal);
    if (btnSave) btnSave.addEventListener('click', saveQuickLabels);

    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeQuickLabelModal();
        });
    }

    if (presetSelect) {
        presetSelect.addEventListener('change', (e) => {
            const key = e.target.value;
            const preset = window.INDUSTRY_PRESETS[key];
            if (!preset || key === 'custom') return;
            const i1 = document.getElementById('quickLabelItem1');
            const i2 = document.getElementById('quickLabelItem2');
            const d1 = document.getElementById('quickLabelDate1');
            const d2 = document.getElementById('quickLabelDate2');
            const d3 = document.getElementById('quickLabelDate3');
            if (i1) i1.value = preset.item1;
            if (i2) i2.value = preset.item2;
            if (d1) d1.value = preset.date1;
            if (d2) d2.value = preset.date2;
            if (d3) d3.value = preset.date3;
        });
    }
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
    const industrySelect = document.getElementById('accFormIndustryType');
    const item1Input = document.getElementById('accFormLabelItem1');
    const item2Input = document.getElementById('accFormLabelItem2');
    const date1Input = document.getElementById('accFormLabelDate1');
    const date2Input = document.getElementById('accFormLabelDate2');
    const date3Input = document.getElementById('accFormLabelDate3');
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
        if (industrySelect) industrySelect.value = 'senior';
        if (item1Input) item1Input.value = '受講コース';
        if (item2Input) item2Input.value = '使用機器・PC環境';
        if (date1Input) date1Input.value = '次回レッスン';
        if (date2Input) date2Input.value = 'PC健康診断';
        if (date3Input) date3Input.value = '会員・月謝更新';
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
            const res = await fetch(`../api.php?action=get_account_detail&target_account=${encodeURIComponent(accountId)}`);
            const data = await res.json();
            if (data.success && data.account) {
                const acc = data.account;
                if (nameInput) nameInput.value = acc.name || '';
                if (shortNameInput) shortNameInput.value = acc.short_name || '';
                const c = acc.theme_color || '#6366f1';
                if (colorPicker) colorPicker.value = c;
                if (colorHex) colorHex.value = c.toUpperCase();
                if (isDefaultCheck) isDefaultCheck.checked = !!acc.is_default;
                if (industrySelect) industrySelect.value = acc.industry_type || 'senior';
                const customLabels = acc.custom_labels || {};
                if (item1Input) item1Input.value = acc.label_item1 || customLabels.item1 || '';
                if (item2Input) item2Input.value = acc.label_item2 || customLabels.item2 || '';
                if (date1Input) date1Input.value = acc.label_date1 || customLabels.date1 || '';
                if (date2Input) date2Input.value = acc.label_date2 || customLabels.date2 || '';
                if (date3Input) date3Input.value = acc.label_date3 || customLabels.date3 || '';
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
    const displayProlineField = document.getElementById('accFormDisplayProlineEventUrl');
    const baseUrl = getAppBaseUrl();
    const cleanId = (accId || '').toLowerCase().replace(/[^a-z0-9_\-]/g, '');
    const query = (isDefault || cleanId === 'senior') ? '' : `?account=${cleanId || 'your_id'}`;
    
    if (displayField) {
        displayField.value = `${baseUrl}/webhook.php${query}`;
    }
    if (displayProlineField) {
        displayProlineField.value = `${baseUrl}/proline_webhook.php${query}`;
    }
}

window.appendToolWebhookUrl = function(toolType) {
    const input = document.getElementById('accFormWebhookUrl');
    if (!input) return;
    let sample = '';
    if (toolType === 'proline') sample = 'https://autosns.pro/api/webhook/YOUR_KEY';
    else if (toolType === 'lmessh') sample = 'https://l-messh.com/api/webhook/YOUR_KEY';
    else if (toolType === 'harness') sample = 'https://line-harness.com/api/webhook/YOUR_KEY';
    
    if (sample) {
        const cur = input.value.trim();
        input.value = cur ? (cur + "\n" + sample) : sample;
        input.focus();
    }
};

async function submitAccountForm() {
    const idInput = document.getElementById('accFormId');
    const nameInput = document.getElementById('accFormName');
    const shortNameInput = document.getElementById('accFormShortName');
    const colorHex = document.getElementById('accFormColorHex');
    const isDefaultCheck = document.getElementById('accFormIsDefault');
    const industrySelect = document.getElementById('accFormIndustryType');
    const item1Input = document.getElementById('accFormLabelItem1');
    const item2Input = document.getElementById('accFormLabelItem2');
    const date1Input = document.getElementById('accFormLabelDate1');
    const date2Input = document.getElementById('accFormLabelDate2');
    const date3Input = document.getElementById('accFormLabelDate3');
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
        formData.append('industry_type', industrySelect ? industrySelect.value : 'senior');
        formData.append('label_item1', item1Input ? item1Input.value.trim() : '');
        formData.append('label_item2', item2Input ? item2Input.value.trim() : '');
        formData.append('label_date1', date1Input ? date1Input.value.trim() : '');
        formData.append('label_date2', date2Input ? date2Input.value.trim() : '');
        formData.append('label_date3', date3Input ? date3Input.value.trim() : '');
        formData.append('channel_access_token', (tokenInput.value || '').trim());
        formData.append('channel_secret', (secretInput.value || '').trim());
        formData.append('liff_id', (liffIdInput.value || '').trim());
        formData.append('proline_calendar_url', (calUrlInput.value || '').trim());
        formData.append('proline_webhook_url', (whUrlInput.value || '').trim());
        formData.append('password', state.password || sessionStorage.getItem('admin_pass') || '');

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
            // 現在のアカウントを更新した場合は再取得してラベル反映
            if (cleanId === state.activeAccount) {
                await fetchCustomers();
            }
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
        formData.append('password', state.password || sessionStorage.getItem('admin_pass') || '');

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
    const getCookie = (name) => {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return decodeURIComponent(parts.pop().split(';').shift());
        return '';
    };
    const savedToken = sessionStorage.getItem('admin_auth_token') || getCookie('admin_auth_token');
    const savedPass = sessionStorage.getItem('admin_pass') || getCookie('admin_pass');
    if (savedToken || savedPass) {
        state.authToken = savedToken || '';
        state.password = savedPass || '';
        if (savedToken) sessionStorage.setItem('admin_auth_token', savedToken);
        if (savedPass) sessionStorage.setItem('admin_pass', savedPass);
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

    // ログイン & 二段階認証イベント
    if (elements.loginBtn) {
        elements.loginBtn.addEventListener('click', () => attemptLogin());
    }
    if (elements.adminPasswordInput) {
        elements.adminPasswordInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') attemptLogin();
        });
    }
    if (elements.btnVerify2FA) {
        elements.btnVerify2FA.addEventListener('click', () => attemptVerify2FA());
    }
    if (elements.admin2FACodeInput) {
        elements.admin2FACodeInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') attemptVerify2FA();
        });
    }
    if (elements.btnBackToPassword) {
        elements.btnBackToPassword.addEventListener('click', () => backToPasswordStep());
    }
    if (elements.btnResend2FACode) {
        elements.btnResend2FACode.addEventListener('click', () => attemptResend2FA());
    }

    // ログアウト
    if (elements.logoutBtn) {
        elements.logoutBtn.addEventListener('click', async () => {
            try {
                await fetch('../api.php?action=admin_logout', { method: 'POST' });
            } catch (e) {}
            sessionStorage.removeItem('admin_auth_token');
            sessionStorage.removeItem('admin_pass');
            document.cookie = "admin_auth_token=; path=/; max-age=0; SameSite=Lax";
            document.cookie = "admin_pass=; path=/; max-age=0; SameSite=Lax";
            state.password = '';
            state.authToken = '';
            state.twoFactorSessionToken = '';
            if (state.resendTimerInterval) clearInterval(state.resendTimerInterval);
            if (globalDashboardPollTimer) clearInterval(globalDashboardPollTimer);
            if (elements.adminApp) elements.adminApp.style.display = 'none';
            if (elements.loginModal) elements.loginModal.style.display = 'flex';
            if (elements.loginStep1Wrap) elements.loginStep1Wrap.style.display = 'block';
            if (elements.loginStep2Wrap) elements.loginStep2Wrap.style.display = 'none';
            if (elements.adminPasswordInput) {
                elements.adminPasswordInput.value = '';
                elements.adminPasswordInput.focus();
            }
            hideLoginError();
        });
    }

    // 検索入力 (デバウンス250msで入力中のブラウザ固まりを完全に防止 & クリアボタン連動)
    if (elements.adminSearchInput) {
        elements.adminSearchInput.addEventListener('input', (e) => {
            const query = e.target.value;
            if (elements.btnClearSearch) {
                elements.btnClearSearch.style.display = query ? 'flex' : 'none';
            }
            clearTimeout(state.searchDebounceTimer);
            state.searchDebounceTimer = setTimeout(() => {
                state.searchQuery = query.trim().toLowerCase();
                state.currentPage = 1; // 検索時は1ページ目へ
                renderTable();
            }, 250);
        });
    }

    // 検索クリアボタン
    if (elements.btnClearSearch) {
        elements.btnClearSearch.addEventListener('click', () => {
            if (elements.adminSearchInput) {
                elements.adminSearchInput.value = '';
                elements.adminSearchInput.focus();
            }
            elements.btnClearSearch.style.display = 'none';
            state.searchQuery = '';
            state.currentPage = 1;
            renderTable();
        });
    }

    // 表示件数切り替え (25件 / 50件 / 100件 / 全件)
    if (elements.pageSizeSelect) {
        elements.pageSizeSelect.addEventListener('change', (e) => {
            state.pageSize = e.target.value;
            state.currentPage = 1; // 表示件数変更時は1ページ目へ
            renderTable();
        });
    }

    // タグ絞り込みセレクト
    if (elements.adminTagFilterSelect) {
        elements.adminTagFilterSelect.addEventListener('change', (e) => {
            state.currentTagFilter = e.target.value;
            state.currentPage = 1;
            renderTable();
        });
    }

    // 一括操作 全選択チェックボックス
    if (elements.selectAllCheckbox) {
        elements.selectAllCheckbox.addEventListener('change', (e) => {
            toggleSelectAllCustomers(e.target.checked);
        });
    }

    // 一括操作ボタン
    if (elements.btnBulkAddTags) {
        elements.btnBulkAddTags.addEventListener('click', () => openBulkTagModal('add'));
    }
    if (elements.btnBulkRemoveTags) {
        elements.btnBulkRemoveTags.addEventListener('click', () => openBulkTagModal('remove'));
    }
    if (elements.btnBulkClearSelection) {
        elements.btnBulkClearSelection.addEventListener('click', () => clearCustomerSelection());
    }
    if (elements.closeBulkTagModalBtn) {
        elements.closeBulkTagModalBtn.addEventListener('click', closeBulkTagModal);
    }
    if (elements.cancelBulkTagBtn) {
        elements.cancelBulkTagBtn.addEventListener('click', closeBulkTagModal);
    }
    if (elements.executeBulkTagBtn) {
        elements.executeBulkTagBtn.addEventListener('click', executeBulkTagUpdate);
    }

    // 並び替えセレクト
    if (elements.adminSortSelect) {
        elements.adminSortSelect.addEventListener('change', (e) => {
            state.currentSort = e.target.value;
            state.currentPage = 1;
            renderTable();
        });
    }

    // フィルタータブ
    elements.tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            elements.tabBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            state.currentFilter = btn.getAttribute('data-filter');
            state.currentPage = 1; // タブ切り替え時は1ページ目へ
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
            state.currentPage = 1; // 統計カードクリック時も1ページ目へ
            renderTable();
        });
    });

    // 受講生テーブルのイベント委譲初期化
    initCustomerTableEvents();

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

    // 帯の高さ設定（スライダー ⇄ 数値入力 ⇄ クイックチップ & 自動伸縮/固定モード）
    if (elements.userMenuBannerHeightInput) {
        elements.userMenuBannerHeightInput.addEventListener('input', (e) => {
            const val = parseInt(e.target.value, 10) || 300;
            if (elements.userMenuBannerHeightNumber) elements.userMenuBannerHeightNumber.value = val;
            state.userMenuHeightMode = 'fixed';
            document.querySelectorAll('.btn-height-quick-chip').forEach(btn => {
                btn.classList.toggle('active', parseInt(btn.dataset.height, 10) === val);
            });
            renderUserMenuPreview();
        });
    }
    if (elements.userMenuBannerHeightNumber) {
        elements.userMenuBannerHeightNumber.addEventListener('input', (e) => {
            const val = Math.max(50, Math.min(1000, parseInt(e.target.value, 10) || 300));
            if (elements.userMenuBannerHeightInput) elements.userMenuBannerHeightInput.value = val;
            state.userMenuHeightMode = 'fixed';
            document.querySelectorAll('.btn-height-quick-chip').forEach(btn => {
                btn.classList.toggle('active', parseInt(btn.dataset.height, 10) === val);
            });
            renderUserMenuPreview();
        });
    }
    // 帯の高さ クイックチップ
    document.querySelectorAll('.btn-height-quick-chip').forEach(btn => {
        btn.addEventListener('click', () => {
            const heightAttr = btn.dataset.height;
            if (heightAttr === 'auto') {
                state.userMenuHeightMode = 'auto';
            } else {
                state.userMenuHeightMode = 'fixed';
                const h = parseInt(heightAttr, 10);
                if (elements.userMenuBannerHeightInput) elements.userMenuBannerHeightInput.value = h;
                if (elements.userMenuBannerHeightNumber) elements.userMenuBannerHeightNumber.value = h;
            }
            document.querySelectorAll('.btn-height-quick-chip').forEach(b => b.classList.toggle('active', b === btn));
            renderUserMenuPreview();
        });
    });

    if (elements.userMenuTextAlignV) {
        elements.userMenuTextAlignV.addEventListener('change', renderUserMenuPreview);
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
    if (elements.btnCloseSyncProgressModal) {
        elements.btnCloseSyncProgressModal.addEventListener('click', () => {
            if (elements.syncProgressModal) elements.syncProgressModal.style.display = 'none';
        });
    }
    if (elements.btnFinishSyncModal) {
        elements.btnFinishSyncModal.addEventListener('click', async () => {
            if (elements.syncProgressModal) elements.syncProgressModal.style.display = 'none';
            await fetchCustomers();
        });
    }

    // 表示カラム設定の初期化
    initColumnPicker();
}

/**
 * 表示カラム設定の管理 (localStorage連携 & 即時DOMトグル)
 */
const DEFAULT_COLUMN_VISIBILITY = {
    tags: true,
    course: true,
    oil: true,
    periodic: true,
    insp: true,
    interaction: true,
    memo: true
};

const COLUMN_STORAGE_KEY = 'kureba_admin_column_visibility_v1';

function getColumnVisibility() {
    try {
        const saved = localStorage.getItem(COLUMN_STORAGE_KEY);
        if (saved) {
            return { ...DEFAULT_COLUMN_VISIBILITY, ...JSON.parse(saved) };
        }
    } catch (e) {
        console.warn('Failed to load column visibility from localStorage', e);
    }
    return { ...DEFAULT_COLUMN_VISIBILITY };
}

function saveColumnVisibility(config) {
    try {
        localStorage.setItem(COLUMN_STORAGE_KEY, JSON.stringify(config));
    } catch (e) {
        console.warn('Failed to save column visibility to localStorage', e);
    }
}

function applyColumnVisibility(config) {
    const table = document.getElementById('customerDataTable');
    if (!table) return;

    let visibleCount = 2; // お名前 と 操作（固定2列）
    const totalCount = 8; // 全8列

    Object.keys(DEFAULT_COLUMN_VISIBILITY).forEach(colKey => {
        const isVisible = (config[colKey] !== false);
        const className = `hide-col-${colKey}`;
        if (isVisible) {
            table.classList.remove(className);
            visibleCount++;
        } else {
            table.classList.add(className);
        }

        // チェックボックスの状態を同期
        const cb = document.querySelector(`.col-toggle-cb[data-col="${colKey}"]`);
        if (cb) {
            cb.checked = isVisible;
        }
    });

    const countText = document.getElementById('colPickerCountText');
    if (countText) {
        countText.textContent = `表示中: ${visibleCount} / ${totalCount} 列`;
    }
}

function initColumnPicker() {
    const btnToggle = document.getElementById('btnToggleColPicker');
    const menu = document.getElementById('colPickerMenu');
    const btnReset = document.getElementById('btnResetColPicker');
    const container = document.getElementById('colPickerContainer');

    // 初期設定の読み込み & 反映
    const currentConfig = getColumnVisibility();
    applyColumnVisibility(currentConfig);

    if (!btnToggle || !menu) return;

    // ドロップダウン開閉トグル (画面端はみ出し防止のスマートポジショニング付き)
    btnToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = (menu.style.display !== 'none');
        if (!isOpen) {
            menu.style.display = 'block';
            btnToggle.classList.add('active');
            requestAnimationFrame(() => {
                const rect = menu.getBoundingClientRect();
                if (rect.left < 8) {
                    menu.style.left = '0';
                    menu.style.right = 'auto';
                } else if (rect.right > window.innerWidth - 8) {
                    menu.style.right = '0';
                    menu.style.left = 'auto';
                }
            });
        } else {
            menu.style.display = 'none';
            btnToggle.classList.remove('active');
        }
    });

    // カラムチェックボックス変更イベント
    document.querySelectorAll('.col-toggle-cb').forEach(cb => {
        cb.addEventListener('change', () => {
            const colKey = cb.dataset.col;
            if (!colKey) return;
            const config = getColumnVisibility();
            config[colKey] = cb.checked;
            saveColumnVisibility(config);
            applyColumnVisibility(config);
        });
    });

    // 初期化ボタン
    if (btnReset) {
        btnReset.addEventListener('click', (e) => {
            e.stopPropagation();
            saveColumnVisibility(DEFAULT_COLUMN_VISIBILITY);
            applyColumnVisibility(DEFAULT_COLUMN_VISIBILITY);
            showToast('表示カラム設定を初期化しました');
        });
    }

    // メニュー内クリックで閉じないようにする
    menu.addEventListener('click', (e) => {
        e.stopPropagation();
    });

    // 各カラム行のクイック編集鉛筆ボタン
    document.querySelectorAll('.btn-col-quick-edit').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            e.preventDefault();
            const target = btn.getAttribute('data-target');
            openQuickLabelModal(target);
        });
    });

    // クイックラベルモーダルのイベント初期化
    initQuickLabelModalEvents();

    // 外側クリックでメニューを閉じる
    document.addEventListener('click', (e) => {
        if (container && !container.contains(e.target)) {
            menu.style.display = 'none';
            btnToggle.classList.remove('active');
        }
    });
}

function showLoginError(msg) {
    if (elements.loginErrorMsg) {
        elements.loginErrorMsg.textContent = msg;
        elements.loginErrorMsg.style.display = 'block';
    }
}

function hideLoginError() {
    if (elements.loginErrorMsg) {
        elements.loginErrorMsg.textContent = '';
        elements.loginErrorMsg.style.display = 'none';
    }
}

function startResendTimer(seconds = 60) {
    if (state.resendTimerInterval) clearInterval(state.resendTimerInterval);
    state.resendCountdown = seconds;

    const updateUI = () => {
        if (elements.resendTimerText) {
            elements.resendTimerText.textContent = state.resendCountdown > 0 ? `(${state.resendCountdown}秒)` : '';
        }
        if (elements.btnResend2FACode) {
            elements.btnResend2FACode.disabled = state.resendCountdown > 0;
            elements.btnResend2FACode.style.opacity = state.resendCountdown > 0 ? '0.5' : '1';
            elements.btnResend2FACode.style.cursor = state.resendCountdown > 0 ? 'not-allowed' : 'pointer';
        }
    };

    updateUI();
    state.resendTimerInterval = setInterval(() => {
        state.resendCountdown--;
        if (state.resendCountdown <= 0) {
            clearInterval(state.resendTimerInterval);
            state.resendCountdown = 0;
        }
        updateUI();
    }, 1000);
}

function backToPasswordStep() {
    if (state.resendTimerInterval) clearInterval(state.resendTimerInterval);
    state.twoFactorSessionToken = '';
    hideLoginError();
    if (elements.loginStep2Wrap) elements.loginStep2Wrap.style.display = 'none';
    if (elements.loginStep1Wrap) elements.loginStep1Wrap.style.display = 'block';
    if (elements.adminPasswordInput) elements.adminPasswordInput.focus();
}

function finishLoginSuccess(pass, authToken) {
    state.password = pass;
    state.authToken = authToken || '';
    if (authToken) {
        sessionStorage.setItem('admin_auth_token', authToken);
        document.cookie = "admin_auth_token=" + encodeURIComponent(authToken) + "; path=/; max-age=" + (86400 * 30) + "; SameSite=Lax";
    }
    if (pass) {
        sessionStorage.setItem('admin_pass', pass);
        document.cookie = "admin_pass=" + encodeURIComponent(pass) + "; path=/; max-age=" + (86400 * 30) + "; SameSite=Lax";
    }
    if (elements.loginModal) elements.loginModal.style.display = 'none';
    if (elements.adminApp) elements.adminApp.style.display = 'block';
    loadDashboard();
}

async function attemptLogin() {
    const pass = elements.adminPasswordInput ? elements.adminPasswordInput.value.trim() : '';
    if (!pass) {
        showLoginError('パスワードを入力してください');
        return;
    }

    if (elements.loginBtn) {
        elements.loginBtn.disabled = true;
        elements.loginBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 確認中...';
    }
    hideLoginError();

    try {
        const payload = new URLSearchParams({
            action: 'admin_login_step1',
            password: pass
        });
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (!data.success) {
            showLoginError(data.error || 'パスワードが正しくありません');
            return;
        }

        if (data.require_2fa) {
            // STEP 2 (2FAコード入力) へ遷移
            state.twoFactorSessionToken = data.session_token;
            state.password = pass;
            if (elements.login2FAEmailHint) {
                elements.login2FAEmailHint.textContent = data.email_hint || 'kawai@kureba.co.jp';
            }
            if (elements.loginStep1Wrap) elements.loginStep1Wrap.style.display = 'none';
            if (elements.loginStep2Wrap) elements.loginStep2Wrap.style.display = 'block';
            if (elements.admin2FACodeInput) {
                elements.admin2FACodeInput.value = '';
                elements.admin2FACodeInput.focus();
            }
            startResendTimer(60);
        } else {
            // 2FA不要の場合は直接ログイン完了
            finishLoginSuccess(pass, data.auth_token);
        }
    } catch (e) {
        console.error('Login Step1 Error:', e);
        showLoginError('通信エラーが発生しました: ' + e.message);
    } finally {
        if (elements.loginBtn) {
            elements.loginBtn.disabled = false;
            elements.loginBtn.innerHTML = 'ログイン';
        }
    }
}

async function attemptVerify2FA() {
    const code = elements.admin2FACodeInput ? elements.admin2FACodeInput.value.trim() : '';
    if (!code || code.length < 6) {
        showLoginError('6桁の認証コードを入力してください');
        return;
    }

    if (elements.btnVerify2FA) {
        elements.btnVerify2FA.disabled = true;
        elements.btnVerify2FA.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 認証中...';
    }
    hideLoginError();

    try {
        const payload = new URLSearchParams({
            action: 'admin_login_verify_2fa',
            session_token: state.twoFactorSessionToken,
            code: code
        });
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success && data.auth_token) {
            if (state.resendTimerInterval) clearInterval(state.resendTimerInterval);
            finishLoginSuccess(state.password || '', data.auth_token);
        } else {
            showLoginError(data.error || '認証コードが正しくありません');
        }
    } catch (e) {
        console.error('Verify 2FA Error:', e);
        showLoginError('認証通信エラー: ' + e.message);
    } finally {
        if (elements.btnVerify2FA) {
            elements.btnVerify2FA.disabled = false;
            elements.btnVerify2FA.innerHTML = '認証してログイン';
        }
    }
}

async function attemptResend2FA() {
    if (state.resendCountdown > 0) return;
    if (!state.twoFactorSessionToken) return;

    if (elements.btnResend2FACode) {
        elements.btnResend2FACode.disabled = true;
    }
    hideLoginError();

    try {
        const payload = new URLSearchParams({
            action: 'admin_login_resend_2fa',
            session_token: state.twoFactorSessionToken
        });
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success) {
            showToast('認証コードを再送信しました！メールをご確認ください。');
            startResendTimer(60);
        } else {
            showLoginError(data.error || 'コードの再送信に失敗しました');
        }
    } catch (e) {
        showLoginError('再送信エラー: ' + e.message);
    } finally {
        if (elements.btnResend2FACode && state.resendCountdown <= 0) {
            elements.btnResend2FACode.disabled = false;
        }
    }
}

let globalDashboardPollTimer = null;

function startDashboardPolling() {
    if (globalDashboardPollTimer) clearInterval(globalDashboardPollTimer);
    globalDashboardPollTimer = setInterval(() => {
        if ((state.password || state.authToken) && !elements.chatModal?.classList.contains('active')) {
            loadUnreadChatCounts();
        }
    }, 20000);
}

async function loadDashboard() {
    elements.loginModal.style.display = 'none';
    elements.adminApp.style.display = 'block';
    startDashboardPolling();
    await Promise.all([fetchCustomers(), loadRichMenus()]);
}

async function fetchCustomers() {
    try {
        const sortParam = encodeURIComponent(state.currentSort || 'last_interaction');
        const [custRes, unreadRes] = await Promise.all([
            fetch(`../api.php?action=admin_list_customers&sort=${sortParam}&account=${encodeURIComponent(state.activeAccount)}`),
            fetch(`../api.php?action=get_unread_chat_counts&account=${encodeURIComponent(state.activeAccount)}`).catch(() => null)
        ]);

        if (unreadRes && unreadRes.ok) {
            try {
                const unreadData = await unreadRes.json();
                if (unreadData && unreadData.success && unreadData.unread_counts) {
                    state.unreadChatCounts = unreadData.unread_counts;
                }
            } catch (err) {
                console.warn('Failed to parse unread chat counts:', err);
            }
        }

        const data = await custRes.json();
        if (data.success) {
            state.allCustomers = data.customers || [];
            state.allTags = data.all_tags || [];
            if (data.custom_labels) {
                applyDynamicLabels(data.custom_labels);
            }
            updateBrandDisplay();
            renderTagFilterOptions();
            updateStats();
            renderTable();
            checkUrlChatParam();
        } else if (custRes.status === 401) {
            sessionStorage.removeItem('admin_pass');
            elements.loginModal.style.display = 'flex';
            elements.adminApp.style.display = 'none';
        }
    } catch (e) {
        console.error('Fetch error:', e);
    }
}

async function syncLineFollowers() {
    if (!confirm("LINE公式アカウントの全友だち一覧を取得し、まだ顧客一覧にいない友だちを一括登録・最新の名前に同期しますか？\n\n※進捗状況はリアルタイムにパーセント表示されます。")) {
        return;
    }

    const modal = elements.syncProgressModal;
    if (!modal) {
        alert('同期モーダルが見つかりません');
        return;
    }

    // モーダル初期化 & 表示
    modal.style.display = 'flex';
    if (elements.btnCloseSyncProgressModal) elements.btnCloseSyncProgressModal.style.display = 'none';
    if (elements.syncProgressActions) elements.syncProgressActions.style.display = 'none';
    if (elements.syncProgressStatusText) elements.syncProgressStatusText.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="color: #06C755; margin-right: 6px;"></i> LINE友だちリストを取得中...';
    if (elements.syncProgressPercent) elements.syncProgressPercent.textContent = '0%';
    if (elements.syncProgressBar) elements.syncProgressBar.style.width = '0%';
    if (elements.syncProgressCount) elements.syncProgressCount.textContent = '0 / 0 名';
    if (elements.syncProgressSpeed) elements.syncProgressSpeed.textContent = 'LINE API通信中';
    if (elements.syncProgressImportedCount) elements.syncProgressImportedCount.innerHTML = '0 <span style="font-size: 12px; font-weight: 600;">名</span>';
    if (elements.syncProgressUpdatedCount) elements.syncProgressUpdatedCount.innerHTML = '0 <span style="font-size: 12px; font-weight: 600;">名</span>';
    if (elements.syncLogBox) {
        elements.syncLogBox.innerHTML = '<div class="sync-log-item" style="color: #64748b;"><i class="fa-solid fa-circle-notch fa-spin" style="font-size: 11px;"></i> LINE Messaging APIに接続しています...</div>';
    }

    const appendSyncLog = (text, iconClass = 'fa-solid fa-check', color = '#15803d') => {
        if (!elements.syncLogBox) return;
        const item = document.createElement('div');
        item.className = 'sync-log-item';
        item.innerHTML = `<i class="${iconClass}" style="color: ${color}; font-size: 11px;"></i> <span>${escapeHtml(text)}</span>`;
        elements.syncLogBox.appendChild(item);
        elements.syncLogBox.scrollTop = elements.syncLogBox.scrollHeight;
    };

    const btn = elements.syncLineFollowersBtn;
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 同期中...';
    }

    try {
        // Step 1: 全フォロワーUID一覧の取得
        const idRes = await fetch(`../api.php?action=admin_get_sync_follower_ids&account=${encodeURIComponent(state.activeAccount)}`);
        const idData = await idRes.json();

        if (!idData.success) {
            throw new Error(idData.error || 'LINE友だち一覧の取得に失敗しました');
        }

        const userIds = idData.userIds || [];
        const total = userIds.length;

        if (total === 0) {
            if (elements.syncProgressStatusText) elements.syncProgressStatusText.innerHTML = '<i class="fa-solid fa-circle-info" style="color: #3b82f6; margin-right: 6px;"></i> 同期対象の友だちがいません';
            if (elements.syncProgressPercent) elements.syncProgressPercent.textContent = '100%';
            if (elements.syncProgressBar) elements.syncProgressBar.style.width = '100%';
            appendSyncLog('LINE公式アカウントの友だちは0名でした。', 'fa-solid fa-info-circle', '#3b82f6');
            if (elements.syncProgressActions) elements.syncProgressActions.style.display = 'block';
            if (elements.btnCloseSyncProgressModal) elements.btnCloseSyncProgressModal.style.display = 'inline-block';
            return;
        }

        appendSyncLog(`全 ${total} 名の友だちリストを取得しました。プロフィール同期を開始します...`, 'fa-solid fa-users', '#06C755');
        if (elements.syncProgressStatusText) elements.syncProgressStatusText.innerHTML = `<i class="fa-solid fa-spinner fa-spin" style="color: #06C755; margin-right: 6px;"></i> プロフィールを高速同期中...`;

        // Step 2: チャンク分割バッチ処理 (1バッチ15件)
        const batchSize = 15;
        let processedCount = 0;
        let totalImported = 0;
        let totalUpdated = 0;

        for (let i = 0; i < total; i += batchSize) {
            const chunk = userIds.slice(i, i + batchSize);
            const batchIndex = Math.floor(i / batchSize) + 1;
            const totalBatches = Math.ceil(total / batchSize);

            const batchRes = await fetch(`../api.php?action=admin_sync_follower_batch&account=${encodeURIComponent(state.activeAccount)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ userIds: chunk })
            });
            const batchData = await batchRes.json();

            if (!batchData.success) {
                appendSyncLog(`バッチ ${batchIndex}/${totalBatches} で一部スキップが発生しました`, 'fa-solid fa-triangle-exclamation', '#e11d48');
            } else {
                totalImported += (batchData.imported || 0);
                totalUpdated += (batchData.updated || 0);
                processedCount += chunk.length;

                const percent = Math.min(100, Math.round((processedCount / total) * 100));
                if (elements.syncProgressPercent) elements.syncProgressPercent.textContent = `${percent}%`;
                if (elements.syncProgressBar) elements.syncProgressBar.style.width = `${percent}%`;
                if (elements.syncProgressCount) elements.syncProgressCount.textContent = `${processedCount} / ${total} 名 (${percent}%)`;
                if (elements.syncProgressImportedCount) elements.syncProgressImportedCount.innerHTML = `${totalImported} <span style="font-size: 12px; font-weight: 600;">名</span>`;
                if (elements.syncProgressUpdatedCount) elements.syncProgressUpdatedCount.innerHTML = `${totalUpdated} <span style="font-size: 12px; font-weight: 600;">名</span>`;

                if (batchData.names && batchData.names.length > 0) {
                    const sampleNames = batchData.names.slice(0, 3).join(', ');
                    const suffix = batchData.names.length > 3 ? ` 他` : '';
                    appendSyncLog(`${sampleNames}${suffix} を同期しました (${processedCount}/${total})`, 'fa-solid fa-check', '#10b981');
                }
            }
        }

        // Step 3: ブロック状態の整合同期 (LINEフォロワー一覧にいない既存友だちをブロック中として更新)
        appendSyncLog('LINE友だち状態とブロック状況を整合同期中...', 'fa-solid fa-arrows-rotate', '#6366f1');
        let blockedDetected = 0;
        try {
            const reconcileRes = await fetch(`../api.php?action=admin_sync_reconcile_blocked&account=${encodeURIComponent(state.activeAccount)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ activeUserIds: userIds })
            });
            const reconcileData = await reconcileRes.json();
            if (reconcileData.success) {
                blockedDetected = reconcileData.blocked_detected || 0;
                if (blockedDetected > 0) {
                    appendSyncLog(`🚫 ブロック中の友だち ${blockedDetected} 名を自動検知してカルテに反映しました`, 'fa-solid fa-user-slash', '#e11d48');
                } else {
                    appendSyncLog('✅ ブロック状態の整合同期が完了しました', 'fa-solid fa-check', '#10b981');
                }
            }
        } catch (rErr) {
            console.warn('Reconcile blocked error:', rErr);
        }

        // Step 4: 完了
        if (elements.syncProgressPercent) elements.syncProgressPercent.textContent = '100%';
        if (elements.syncProgressBar) elements.syncProgressBar.style.width = '100%';
        if (elements.syncProgressStatusText) elements.syncProgressStatusText.innerHTML = '<i class="fa-solid fa-circle-check" style="color: #06C755; margin-right: 6px;"></i> すべての同期が完了しました！';
        if (elements.syncProgressSpeed) elements.syncProgressSpeed.textContent = '同期完了';
        appendSyncLog(`🎉 全${total}名の同期が完了しました！（新規追加: ${totalImported}名、名前更新: ${totalUpdated}名、ブロック検知: ${blockedDetected}名）`, 'fa-solid fa-circle-check', '#06C755');

        if (elements.syncProgressActions) elements.syncProgressActions.style.display = 'block';
        if (elements.btnCloseSyncProgressModal) elements.btnCloseSyncProgressModal.style.display = 'inline-block';
        showToast(`✅ LINE友だち全${total}名を同期しました！（友だち: ${total - blockedDetected}名 / ブロック: ${blockedDetected}名）`);

        // 受講生カルテ一覧の再読込
        await fetchCustomers();

    } catch (e) {
        console.error('Sync followers error:', e);
        if (elements.syncProgressStatusText) elements.syncProgressStatusText.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color: #e11d48; margin-right: 6px;"></i> 同期中にエラーが発生しました';
        appendSyncLog(`エラー: ${e.message}`, 'fa-solid fa-xmark', '#e11d48');
        if (elements.syncProgressActions) elements.syncProgressActions.style.display = 'block';
        if (elements.btnCloseSyncProgressModal) elements.btnCloseSyncProgressModal.style.display = 'inline-block';
        alert('同期処理中にエラーが発生しました: ' + e.message);
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
    let activeCount = 0;
    let blockedCount = 0;

    state.allCustomers.forEach(c => {
        if (c.is_blocked == 1) {
            blockedCount++;
        } else {
            activeCount++;
        }

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
    const statActiveEl = document.getElementById('statActiveUsers');
    const statBlockedEl = document.getElementById('statBlockedUsers');
    if (statActiveEl) statActiveEl.textContent = activeCount;
    if (statBlockedEl) statBlockedEl.textContent = blockedCount;

    if (elements.statOilSoon) elements.statOilSoon.textContent = oilSoonCount;
    if (elements.statPeriodicSoon) elements.statPeriodicSoon.textContent = periodicSoonCount;
    if (elements.statInspSoon) elements.statInspSoon.textContent = inspSoonCount;

    if (elements.tabCountAll) elements.tabCountAll.textContent = state.allCustomers.length;
    const tabActiveEl = document.getElementById('tabCountActive');
    const tabBlockedEl = document.getElementById('tabCountBlocked');
    if (tabActiveEl) tabActiveEl.textContent = activeCount;
    if (tabBlockedEl) tabBlockedEl.textContent = blockedCount;

    if (elements.tabCountOil) elements.tabCountOil.textContent = oilSoonCount;
    if (elements.tabCountPeriodic) elements.tabCountPeriodic.textContent = periodicSoonCount;
    if (elements.tabCountInsp) elements.tabCountInsp.textContent = inspSoonCount;
}

function renderTable() {
    const labels = state.customLabels || normalizeCustomLabels(null);
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
            const matchTag = Array.isArray(c.tags) && c.tags.some(t => t.toLowerCase().includes(s));
            if (!matchName && !matchCar && !matchNo && !matchMemo && !matchTag) return false;
        }

        // タグ絞り込みフィルター
        if (state.currentTagFilter) {
            if (!Array.isArray(c.tags) || !c.tags.includes(state.currentTagFilter)) {
                return false;
            }
        }

        // タブフィルター
        if (state.currentFilter === 'active') {
            return (c.is_blocked != 1);
        }
        if (state.currentFilter === 'blocked') {
            return (c.is_blocked == 1);
        }
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

    state.currentFilteredList = filtered;
    const totalFiltered = filtered.length;

    // 該当件数バッジの更新
    if (elements.filterMatchedCount) {
        elements.filterMatchedCount.textContent = totalFiltered.toLocaleString();
    }

    if (totalFiltered === 0) {
        elements.customerTableBody.innerHTML = '';
        elements.emptyTablePlaceholder.style.display = 'block';
        if (elements.paginationBar) elements.paginationBar.style.display = 'none';
        return;
    }

    elements.emptyTablePlaceholder.style.display = 'none';

    // ページネーション計算
    const pageSize = (state.pageSize === 'all') ? totalFiltered : (parseInt(state.pageSize, 10) || 50);
    const totalPages = (state.pageSize === 'all') ? 1 : Math.max(1, Math.ceil(totalFiltered / pageSize));
    if (state.currentPage > totalPages) state.currentPage = totalPages;
    if (state.currentPage < 1) state.currentPage = 1;

    const startIndex = (state.currentPage - 1) * pageSize;
    const endIndex = (state.pageSize === 'all') ? totalFiltered : Math.min(startIndex + pageSize, totalFiltered);
    const pagedList = (state.pageSize === 'all') ? filtered : filtered.slice(startIndex, endIndex);

    elements.customerTableBody.innerHTML = pagedList.map((c, pageIdx) => {
        const globalIdx = startIndex + pageIdx;
        const isBlocked = (c.is_blocked == 1);
        const oilBadge = getBadgeHtml(c.oil_next_date);
        const periodicBadge = getBadgeHtml(c.periodic_insp_next_date);
        const inspBadge = getBadgeHtml(c.inspection_next_date);
        let memoContent = c.staff_memo ? escapeHtml(c.staff_memo) : '<span style="color:#cbd5e1">-</span>';
        const memoStr = String(c.staff_memo || '');
        if (memoStr.includes('DXアンケート') || memoStr.includes('DX関心度')) {
            memoContent = `<span style="display:inline-block; padding:2px 6px; border-radius:4px; font-size:10px; font-weight:700; background:#dbeafe; color:#1e40af; margin-bottom:4px;"><i class="fa-solid fa-clipboard-check"></i> DXアンケート回答済</span><br>` + memoContent;
        }
        const memo = memoContent;
        const isDxAccount = (state.activeAccount === 'kaisya_dx');
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
        const interactionType = c.last_interaction_type || (isBlocked ? 'unfollow' : 'follow');
        const interactionPreview = c.last_interaction_preview || (isBlocked ? '🚫 ブロック' : '友だち登録');
        const interactionDisplay = c.last_interaction_display || (c.last_interaction_at ? c.last_interaction_at.substring(0, 16).replace('-', '/') : '未記録');
        const diffText = c.last_interaction_diff_text || (c.last_interaction_at ? c.last_interaction_at.substring(0, 10) : '未記録');

        let badgeClass = 'badge-follow';
        let badgeIcon = '<i class="fa-solid fa-user-plus"></i>';
        let badgeLabel = '友だち登録';
        if (isBlocked || interactionType === 'unfollow') {
            badgeClass = 'badge-unfollow';
            badgeIcon = '<i class="fa-solid fa-user-slash"></i>';
            badgeLabel = 'ブロック';
        } else if (interactionType === 'user_message') {
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
                    <span class="interaction-badge ${badgeClass}" style="${isBlocked ? 'background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;' : ''}">${badgeIcon} ${badgeLabel}</span>
                </div>
                <div class="interaction-preview" style="${isBlocked ? 'color:#dc2626; font-weight:bold;' : ''}">${escapeHtml(interactionPreview)}</div>
                <div class="interaction-full-date">${escapeHtml(interactionDisplay)}</div>
            </div>
        `;

        const followBadgeHtml = isBlocked 
            ? `<span style="font-size:10.5px; background:#fee2e2; color:#b91c1c; border:1px solid #fecdd3; padding:1px 6px; border-radius:var(--radius-xs); font-weight:700; white-space:nowrap;"><i class="fa-solid fa-user-slash"></i> ブロック中</span>`
            : (userId && userId.startsWith('U') ? `<span style="font-size:10.5px; background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:1px 6px; border-radius:var(--radius-xs); font-weight:700; white-space:nowrap;"><i class="fa-solid fa-user-check"></i> 友だち</span>` : '');

        const isRowChecked = state.selectedCustomerIds.has(c.id);
        const tagsArr = Array.isArray(c.tags) ? c.tags : [];
        const tagsHtml = tagsArr.length > 0 
            ? `<div class="table-tags-wrap">${tagsArr.map(t => `<span class="tag-badge" onclick="event.stopPropagation(); filterByTag('${escapeHtml(t)}');" title="クリックでこのタグ絞り込み"><i class="fa-solid fa-tag" style="font-size:9px;"></i> ${escapeHtml(t)}</span>`).join('')}</div>`
            : `<button type="button" class="tag-chip-btn" onclick="event.stopPropagation(); openEditModalByIndex(${globalIdx});" title="タグを追加"><i class="fa-solid fa-plus" style="font-size:9px;"></i> タグ追加</button>`;

        return `
            <tr data-index="${globalIdx}" class="${isBlocked ? 'row-blocked' : ''} ${isRowChecked ? 'row-selected' : ''}" style="${isBlocked ? 'background: #fff8f8;' : (isRowChecked ? 'background: #f5f3ff;' : '')}">
                <td style="text-align: center; width: 38px;">
                    <input type="checkbox" class="customer-row-cb" data-id="${c.id}" ${isRowChecked ? 'checked' : ''} style="cursor: pointer; width: 16px; height: 16px;" onclick="event.stopPropagation(); toggleCustomerSelection(${c.id}, this.checked);">
                </td>
                <td data-col="name">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        ${c.picture_url ? `
                            <img src="${escapeHtml(c.picture_url)}" alt="" loading="lazy" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1.5px solid ${isBlocked ? '#fca5a5' : '#e2e8f0'}; flex-shrink: 0; box-shadow: 0 1px 3px rgba(0,0,0,0.08); ${isBlocked ? 'filter: grayscale(80%); opacity: 0.85;' : ''}" onerror="this.onerror=null; this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                            <div style="display: none; width: 38px; height: 38px; border-radius: 50%; background: #e2e8f0; color: #64748b; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;">
                                <i class="fa-solid fa-user"></i>
                            </div>
                        ` : `
                            <div style="width: 38px; height: 38px; border-radius: 50%; background: ${isBlocked ? '#fee2e2' : '#e2e8f0'}; color: ${isBlocked ? '#ef4444' : '#64748b'}; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;">
                                <i class="fa-solid fa-user"></i>
                            </div>
                        `}
                        <div style="min-width: 0;">
                            <div class="cust-name" style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                <span style="${isBlocked ? 'color:#991b1b; text-decoration: line-through;' : ''}">${escapeHtml(c.user_name || '名前なし')}</span>
                                ${followBadgeHtml}
                                ${state.unreadChatCounts && state.unreadChatCounts[userId] ? `
                                    <span class="badge-chat-unread" title="未読メッセージ ${state.unreadChatCounts[userId]}件">
                                        <i class="fa-solid fa-envelope" style="font-size: 9px; margin-right: 2px;"></i>${state.unreadChatCounts[userId]}
                                    </span>
                                ` : ''}
                            </div>
                            <div class="cust-uid" style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">
                                <span>${escapeHtml(c.user_id || '')}</span>
                                ${c.user_id && c.user_id.startsWith('U') ? `
                                    <button class="btn-copy-uid" title="UIDをクリップボードにコピー" onclick="event.stopPropagation(); copyCustUid('${escapeHtml(c.user_id)}');" style="background: none; border: none; color: #64748b; cursor: pointer; padding: 2px 4px; font-size: 11px; border-radius: var(--radius-xs);" onmouseover="this.style.color='#1e293b'; this.style.background='#f1f5f9';" onmouseout="this.style.color='#64748b'; this.style.background='none';">
                                        <i class="fa-regular fa-copy"></i>
                                    </button>
                                    ${!isBlocked ? `
                                        <button class="btn-add-admin-uid" title="このアカウントを管理者LINE通知先に登録" onclick="event.stopPropagation(); addAdminUidDirectly('${escapeHtml(c.user_id)}', '${escapeHtml(c.user_name || '')}');" style="background: none; border: none; color: #0284c7; cursor: pointer; padding: 2px 4px; font-size: 11px; border-radius: var(--radius-xs);" onmouseover="this.style.color='#0369a1'; this.style.background='#e0f2fe';" onmouseout="this.style.color='#0284c7'; this.style.background='none';">
                                            <i class="fa-solid fa-bell"></i> 通知先に登録
                                        </button>
                                    ` : ''}
                                ` : ''}
                            </div>
                            <div style="margin-top: 4px;">${menuBadgeHtml}</div>
                        </div>
                    </div>
                </td>
                <td data-col="tags">${tagsHtml}</td>
                <td data-col="course">
                    <div class="car-tag">${escapeHtml(c.car_model || '-')}</div>
                    <div class="car-no">${escapeHtml(c.car_number || '')}</div>
                </td>
                <td data-col="oil">${oilBadge}</td>
                <td data-col="periodic">${periodicBadge}</td>
                <td data-col="insp">${inspBadge}</td>
                <td data-col="interaction">${interactionCellHtml}</td>
                <td data-col="memo" style="max-width: 160px; font-size: 11px;">${memo}</td>
                <td data-col="actions">
                    <div class="action-btns">
                        <!-- 行1: メイン操作・個別対応 -->
                        <button class="btn-table-chat" data-action="chat" data-idx="${globalIdx}" data-uid="${escapeHtml(userId || c.id || '')}" onclick="event.stopPropagation(); openChatModalByIndex(${globalIdx});" title="この受講生との1対1トーク確認・返信">
                            <i class="fa-solid fa-comments"></i> チャット
                            ${state.unreadChatCounts && state.unreadChatCounts[userId] ? `
                                <span class="badge-chat-unread">${state.unreadChatCounts[userId]}</span>
                            ` : ''}
                        </button>
                        <button class="btn-user-richmenu ${isCustomized ? 'is-active' : ''}" data-action="custom-menu" data-idx="${globalIdx}" data-uid="${escapeHtml(userId || c.id || '')}" title="リッチメニューの確認・個別指定・メッセージ設定">
                            <i class="fa-solid fa-table-cells-large"></i> メニュー
                        </button>
                        ${isDxAccount ? `
                            <button class="btn-dx-survey-user-row" data-action="dx-survey-send" data-idx="${globalIdx}" data-uid="${escapeHtml(userId || c.id || '')}" title="この顧客へDX関心度アンケート（Flex Message）を送信">
                                <i class="fa-solid fa-clipboard-question"></i> アンケート
                            </button>
                        ` : `
                            <button class="btn-knowledge-user-row" data-action="knowledge-send" data-idx="${globalIdx}" data-uid="${escapeHtml(userId || c.id || '')}" title="この受講生へスマホ・PCお役立ち情報（Flex Message）を個別送信">
                                <i class="fa-solid fa-bullhorn"></i> お役立ち
                            </button>
                        `}
                        <button class="btn-edit" data-action="edit" data-idx="${globalIdx}" data-uid="${escapeHtml(userId || c.id || '')}" title="受講生情報を編集">
                            <i class="fa-solid fa-pen"></i> 編集
                        </button>
                        ${userId && userId.startsWith('U') ? `
                        <button class="btn-toggle-block ${isBlocked ? 'is-blocked' : 'is-active'}"
                            data-action="toggle-block"
                            data-idx="${globalIdx}"
                            data-uid="${escapeHtml(userId)}"
                            data-car-id="${c.id || ''}"
                            data-blocked="${isBlocked ? '1' : '0'}"
                            title="${isBlocked ? 'ブロック解除（友だちに戻す）' : 'ブロック中に設定'}">
                            <i class="fa-solid ${isBlocked ? 'fa-user-check' : 'fa-user-slash'}"></i>
                            ${isBlocked ? '解除' : 'ブロック'}
                        </button>` : ''}

                        <!-- 行2: 各種リマインド & 削除 -->
                        <button class="btn-remind-oil" data-action="remind-oil" data-idx="${globalIdx}" data-uid="${escapeHtml(userId || c.id || '')}" title="${escapeHtml(labels.date1 || '期日1')}リマインドをLINE送信">
                            <i class="fa-solid fa-calendar-day"></i> ${escapeHtml(labels.date1 || '期日1')}
                        </button>
                        <button class="btn-remind-periodic" data-action="remind-periodic" data-idx="${globalIdx}" data-uid="${escapeHtml(userId || c.id || '')}" title="${escapeHtml(labels.date2 || '期日2')}リマインドをLINE送信">
                            <i class="fa-solid fa-calendar-check"></i> ${escapeHtml(labels.date2 || '期日2')}
                        </button>
                        <button class="btn-remind-insp" data-action="remind-insp" data-idx="${globalIdx}" data-uid="${escapeHtml(userId || c.id || '')}" title="${escapeHtml(labels.date3 || '期日3')}リマインドをLINE送信">
                            <i class="fa-solid fa-calendar-days"></i> ${escapeHtml(labels.date3 || '期日3')}
                        </button>
                        <button class="btn-delete" data-action="delete" data-idx="${globalIdx}" data-uid="${escapeHtml(userId || c.id || '')}" title="この顧客データを削除">
                            <i class="fa-solid fa-trash"></i> 削除
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    // ページネーションコントロールの描画
    renderPagination(totalFiltered, totalPages, startIndex, endIndex);

    // イベント委譲リスナーの初回登録（多重バインド防止）
    initCustomerTableEvents();
}

/**
 * ページネーション ナビゲーションUIの生成
 */
function renderPagination(totalCount, totalPages, startIndex, endIndex) {
    if (!elements.paginationBar) return;

    if (totalCount === 0) {
        elements.paginationBar.style.display = 'none';
        return;
    }

    elements.paginationBar.style.display = 'flex';
    if (elements.paginationInfoText) {
        elements.paginationInfoText.innerHTML = `<strong>${startIndex + 1}〜${endIndex}件</strong> を表示中 (全${totalCount.toLocaleString()}件)`;
    }

    if (!elements.paginationNav) return;

    if (totalPages <= 1) {
        elements.paginationNav.innerHTML = '';
        return;
    }

    const current = state.currentPage;
    let buttonsHtml = '';

    // 「最初へ」「前へ」
    const pageNumbers = getPageNumbers(current, totalPages);
    pageNumbers.forEach(p => {
        if (p === '...') {
            buttonsHtml += `<span class="page-ellipsis">…</span>`;
        } else {
            const isActive = (p === current);
            buttonsHtml += `
                <button type="button" class="page-btn ${isActive ? 'active' : ''}" data-page="${p}" ${isActive ? 'disabled' : ''}>
                    ${p}
                </button>
            `;
        }
    });

    // 「次へ」「最後へ」
    const nextDisabled = current === totalPages ? 'disabled' : '';
    buttonsHtml += `
        <button type="button" class="page-btn" data-page="${current + 1}" ${nextDisabled} title="次のページへ">
            <i class="fa-solid fa-chevron-right"></i>
        </button>
        <button type="button" class="page-btn" data-page="${totalPages}" ${nextDisabled} title="最後のページへ">
            <i class="fa-solid fa-angles-right"></i>
        </button>
    `;

    elements.paginationNav.innerHTML = buttonsHtml;
}

/**
 * ページ番号配列の算出ヘルパー（省略記号対応）
 */
function getPageNumbers(current, total) {
    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1);
    }
    const pages = [];
    if (current <= 4) {
        for (let i = 1; i <= 5; i++) pages.push(i);
        pages.push('...');
        pages.push(total);
    } else if (current >= total - 3) {
        pages.push(1);
        pages.push('...');
        for (let i = total - 4; i <= total; i++) pages.push(i);
    } else {
        pages.push(1);
        pages.push('...');
        pages.push(current - 1);
        pages.push(current);
        pages.push(current + 1);
        pages.push('...');
        pages.push(total);
    }
    return pages;
}

/**
 * ページ切り替え & テーブル上部スクロール
 */
function changePage(newPage) {
    state.currentPage = newPage;
    renderTable();
    const tableContainer = document.querySelector('.table-container');
    if (tableContainer) {
        const top = tableContainer.getBoundingClientRect().top + window.pageYOffset - 80;
        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    }
}

/**
 * テーブルおよびページネーションのイベント委譲（Event Delegation）
 * 毎回の大量リスナー登録を廃止し、親コンテナで一括処理
 */
let isTableEventDelegated = false;
function initCustomerTableEvents() {
    if (isTableEventDelegated) return;
    isTableEventDelegated = true;

    // テーブルボディの委譲クリック
    const tableBody = elements.customerTableBody || document.getElementById('customerTableBody');
    if (tableBody) {
        tableBody.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-action]');
            if (!btn) return;
            e.stopPropagation();

            const action = btn.getAttribute('data-action');
            const idx = parseInt(btn.getAttribute('data-idx'), 10);
            const uid = btn.getAttribute('data-uid');

            let cust = null;
            if (uid && state.allCustomers && state.allCustomers.length > 0) {
                cust = state.allCustomers.find(c => (c.user_id && c.user_id === uid) || String(c.id) === String(uid));
            }
            if (!cust && state.currentFilteredList && !isNaN(idx) && state.currentFilteredList[idx]) {
                cust = state.currentFilteredList[idx];
            }
            if (!cust && state.allCustomers && !isNaN(idx) && state.allCustomers[idx]) {
                cust = state.allCustomers[idx];
            }
            if (!cust) {
                console.warn('[Table Event] Target customer could not be resolved. action:', action, 'idx:', idx, 'uid:', uid);
                return;
            }

            if (action === 'chat') {
                openChatModal(cust);
            } else if (action === 'custom-menu') {
                openUserRichMenuModal(cust);
            } else if (action === 'knowledge-send') {
                if (!cust.user_id || !cust.user_id.startsWith('U')) {
                    alert('この受講生は手動登録（LINE未連携）のため個別送信できません。\n配信スタジオを起動し、全体一斉配信または他の受講生を選択して送信できます。');
                    openKnowledgeBroadcastModal();
                } else {
                    openKnowledgeBroadcastModal(cust.user_id);
                }
            } else if (action === 'dx-survey-send') {
                if (!cust.user_id || !cust.user_id.startsWith('U')) {
                    alert('この顧客は手動登録（LINE未連携）のため個別送信できません。');
                    return;
                }
                openDxSurveyModal(cust.user_id);
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
            } else if (action === 'toggle-block') {
                toggleBlockStatus(cust, btn);
            }
        });
    }

    // ページネーションナビの委譲クリック
    if (elements.paginationNav) {
        elements.paginationNav.addEventListener('click', (e) => {
            const btn = e.target.closest('.page-btn[data-page]');
            if (!btn || btn.disabled) return;
            const targetPage = parseInt(btn.getAttribute('data-page'), 10);
            if (targetPage && targetPage !== state.currentPage) {
                changePage(targetPage);
            }
        });
    }
}

window.sendManualReminder = async function(carId, userId, type, userName, carModel) {
    if (!userId || !userId.startsWith('U')) {
        alert('この顧客は手動登録（LINE未連携）のため、LINEメッセージを送信できません。');
        return;
    }

    const labels = state.customLabels || normalizeCustomLabels(null);
    let typeLabel = `💻 ${labels.date1 || '期日1'}リマインド`;
    if (type === 'periodic') typeLabel = `🔍 ${labels.date2 || '期日2'}リマインド`;
    if (type === 'inspection') typeLabel = `🗓️ ${labels.date3 || '期日3'}リマインド`;

    if (!confirm(`【${userName || 'お客様'} 様 (${carModel || labels.item1})】へ\n「${typeLabel}」のLINEメッセージを今すぐ送信しますか？`)) {
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

/**
 * ブロック状態の即時切り替え（楽観的UI更新）
 * @param {Object} cust - 顧客オブジェクト
 * @param {HTMLElement} btn - クリックされたボタン要素
 */
async function toggleBlockStatus(cust, btn) {
    if (!cust || !cust.user_id || !cust.user_id.startsWith('U')) {
        alert('LINE未連携の顧客はブロック操作できません。');
        return;
    }

    const currentlyBlocked = (cust.is_blocked == 1);
    const newBlocked = !currentlyBlocked;
    const name = cust.user_name || '友だち';

    const confirmMsg = newBlocked
        ? `【${name}】さんを「ブロック中」に設定しますか？\n（同期なしで即時反映されます）`
        : `【${name}】さんのブロックを解除して「友だち」に戻しますか？`;

    if (!confirm(confirmMsg)) return;

    // ── 楽観的UI更新: 先にstate内の同一UIDの全レコードとUIを更新 ──
    state.allCustomers.forEach(c => {
        if ((c.user_id && cust.user_id && c.user_id.trim() === cust.user_id.trim()) || String(c.id) === String(cust.id)) {
            c.is_blocked = newBlocked ? 1 : 0;
            c.last_interaction_type = newBlocked ? 'unfollow' : 'follow';
            c.last_interaction_preview = newBlocked ? '🚫 ブロック（手動）' : '✅ ブロック解除（手動）';
        }
    });

    // UIを即時再描画
    renderTable();
    updateStats();

    // トースト通知
    showToast(newBlocked
        ? `🚫 ${name} さんをブロック中に設定しました`
        : `✅ ${name} さんのブロックを解除しました`
    );

    // ── バックグラウンドでAPIに保存 ──
    try {
        const res = await fetch(`../api.php?action=admin_toggle_block&account=${encodeURIComponent(state.activeAccount)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                car_id: cust.id || null,
                uid: cust.user_id || '',
                is_blocked: newBlocked ? 1 : 0
            })
        });
        const data = await res.json();
        if (!data.success) {
            // 失敗したらロールバック
            console.warn('[toggleBlock] API error:', data.error);
            state.allCustomers.forEach(c => {
                if ((c.user_id && cust.user_id && c.user_id.trim() === cust.user_id.trim()) || String(c.id) === String(cust.id)) {
                    c.is_blocked = currentlyBlocked ? 1 : 0;
                    c.last_interaction_type = currentlyBlocked ? 'unfollow' : 'follow';
                }
            });
            renderTable();
            updateStats();
            alert('⚠️ ブロック状態の保存に失敗しました: ' + (data.error || '通信エラー'));
        }
    } catch (e) {
        // 通信エラー時もロールバック
        console.error('[toggleBlock] Fetch error:', e);
        state.allCustomers.forEach(c => {
            if ((c.user_id && cust.user_id && c.user_id.trim() === cust.user_id.trim()) || String(c.id) === String(cust.id)) {
                c.is_blocked = currentlyBlocked ? 1 : 0;
            }
        });
        renderTable();
        updateStats();
        alert('⚠️ 通信エラーが発生しました。ブロック状態が保存されていない可能性があります。');
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
    const labels = state.customLabels || normalizeCustomLabels(null);
    if (cust) {
        activeEditingCarId = cust.id;
        elements.modalTitle.textContent = `カルテ情報の編集: ${cust.car_model || ''} (${cust.user_name || ''})`;
        elements.editUserId.value = cust.id || '';
        elements.editUserUid.value = cust.user_id || '';
        elements.editUserName.value = cust.user_name || '';
        elements.editCarModel.value = cust.car_model || '';
        elements.editCarNumber.value = cust.car_number || '';
        if (elements.editTagsInput) {
            elements.editTagsInput.value = Array.isArray(cust.tags) ? cust.tags.join(', ') : (cust.tags || '');
        }
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
        elements.modalTitle.textContent = '新規顧客・カルテ情報の登録';
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
    const labels = state.customLabels || normalizeCustomLabels(null);
    const userName = elements.editUserName.value.trim();
    const carModel = elements.editCarModel.value.trim();

    if (!userName || !carModel) {
        alert(`お名前と${labels.item1 || '項目名'}は必須です`);
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
        tags: elements.editTagsInput ? elements.editTagsInput.value.trim() : '',
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
        const res = await fetch(`../api.php?action=admin_list_richmenus`);
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

    // LINE実存・公開中メニューを優先ソート
    const sortedMenus = [...state.richMenus].sort((a, b) => {
        if (a.is_active && !b.is_active) return -1;
        if (!a.is_active && b.is_active) return 1;
        if (a.is_line_synced && !b.is_line_synced) return -1;
        if (!a.is_line_synced && b.is_line_synced) return 1;
        return b.id - a.id;
    });

    let foundMatch = false;

    // カテゴリーごとに分類
    const liveMenus = sortedMenus.filter(m => (m.is_active == 1 || m.is_line_default));
    const customBannerMenus = sortedMenus.filter(m => !m.is_notice && ((Array.isArray(m.text_overlays) && m.text_overlays.length > 0) || (m.title || '').includes('専用メッセージ') || (m.title || '').includes('メッセージ帯') || (m.title || '').includes('専用帯') || (m.title || '').includes('帯付き')));
    const noticeMenus = sortedMenus.filter(m => m.is_notice == 1);
    const normalMenus = sortedMenus.filter(m => !m.is_notice && !customBannerMenus.includes(m));

    const addOpt = (m, container) => {
        const isLive = (m.is_active == 1 || m.is_line_default);
        const isSynced = (m.is_line_synced !== false);
        const opt = document.createElement('option');
        opt.value = m.id;
        let prefix = '';
        if (isLive) prefix = '★ ';
        else if (!isSynced) prefix = '⚠️ ';

        opt.textContent = prefix + m.title + ` (ボタン${(m.areas || []).length}個)`;
        if (preferredMenuId && (String(m.id) === String(preferredMenuId) || String(m.line_menu_id) === String(preferredMenuId))) {
            opt.selected = true;
            foundMatch = true;
        }
        container.appendChild(opt);
    };

    if (liveMenus.length > 0) {
        const group = document.createElement('optgroup');
        group.label = '★ LINE全体で現在公開中のメニュー';
        liveMenus.forEach(m => addOpt(m, group));
        elements.directAssignMenuSelect.appendChild(group);
    }
    if (normalMenus.length > 0) {
        const group = document.createElement('optgroup');
        group.label = '📁 通常メニュー';
        normalMenus.forEach(m => addOpt(m, group));
        elements.directAssignMenuSelect.appendChild(group);
    }
    if (customBannerMenus.length > 0) {
        const group = document.createElement('optgroup');
        group.label = '✨ 専用メッセージ帯付きメニュー';
        customBannerMenus.forEach(m => addOpt(m, group));
        elements.directAssignMenuSelect.appendChild(group);
    }
    if (noticeMenus.length > 0) {
        const group = document.createElement('optgroup');
        group.label = '📢 お知らせ専用メニュー';
        noticeMenus.forEach(m => addOpt(m, group));
        elements.directAssignMenuSelect.appendChild(group);
    }

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
            this.src = `../api.php?action=richmenu_image&id=${menu.id}`;
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
        const res = await fetch(`../api.php?action=admin_get_user_richmenu_status&uid=${encodeURIComponent(userId)}`);
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

    // 帯の高さモード初期化（デフォルトは標準300px固定）
    state.userMenuHeightMode = 'fixed';
    if (elements.userMenuBannerHeightInput) elements.userMenuBannerHeightInput.value = 300;
    if (elements.userMenuBannerHeightNumber) elements.userMenuBannerHeightNumber.value = 300;
    document.querySelectorAll('.btn-height-quick-chip').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.height === '300');
    });

    // 文字サイズ初期化（デフォルトは超特大180px）
    if (elements.userMenuFontSizeInput) elements.userMenuFontSizeInput.value = 180;
    if (elements.userMenuFontSizeNumber) elements.userMenuFontSizeNumber.value = 180;
    document.querySelectorAll('.btn-size-quick-chip').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.size === '180');
    });

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

    // 文字フォントサイズ (20px 〜 250px: デフォルト180px)
    let requestedFontSize = 180;
    if (elements.userMenuFontSizeNumber && elements.userMenuFontSizeNumber.value) {
        requestedFontSize = parseInt(elements.userMenuFontSizeNumber.value, 10) || 180;
    } else if (elements.userMenuFontSizeInput && elements.userMenuFontSizeInput.value) {
        requestedFontSize = parseInt(elements.userMenuFontSizeInput.value, 10) || 180;
    }
    requestedFontSize = Math.max(20, Math.min(250, requestedFontSize));

    // 帯の高さ設定（自動伸縮モード vs 高さ固定モード: デフォルト300px）
    let isHeightAuto = (state.userMenuHeightMode !== 'fixed');
    let specifiedHeight = 300;
    if (elements.userMenuBannerHeightNumber && elements.userMenuBannerHeightNumber.value) {
        specifiedHeight = parseInt(elements.userMenuBannerHeightNumber.value, 10) || 300;
    } else if (elements.userMenuBannerHeightInput && elements.userMenuBannerHeightInput.value) {
        specifiedHeight = parseInt(elements.userMenuBannerHeightInput.value, 10) || 300;
    }
    specifiedHeight = Math.max(50, Math.min(1000, specifiedHeight));

    // 複数行段落の分解
    const paragraphs = rawText.split('\n').map(l => l.trim()).filter(l => l.length > 0);
    if (paragraphs.length === 0) return;

    const actionType = elements.userMenuBannerActionType ? elements.userMenuBannerActionType.value : 'mycar_liff';
    const showTapHint = elements.userMenuShowTapHint ? elements.userMenuShowTapHint.checked : false;
    const hasTapAction = actionType !== 'none';

    // 最大描画横幅（左右余白各90px）
    const maxTextWidth = w - 180;

    // --- 自動折り返し（Word Wrap） & 帯枠内フィット（Auto Font Scaling） ---
    // 横幅を超えたら自然に折り返し、固定高さ内に収まりきらない場合はフォントサイズを縮小して完全に収める
    let curFontSize = requestedFontSize;
    const minFontSize = 20;
    let finalLines = [];
    let lineSpacing = curFontSize * 1.30;
    let extraHintH = (hasTapAction && showTapHint) ? Math.round(curFontSize * 0.72) : 0;
    let hintSpacing = (hasTapAction && showTapHint) ? Math.round(curFontSize * 0.85) : 0;
    let textBlockH = 0;

    const targetBannerH = isHeightAuto ? 0 : specifiedHeight;
    // 上下パディング（文字サイズに応じた適度な余白）
    const availableH = isHeightAuto ? 99999 : Math.max(40, targetBannerH - 36);

    while (curFontSize >= minFontSize) {
        ctx.font = `bold ${curFontSize}px "Noto Sans JP", -apple-system, BlinkMacSystemFont, sans-serif`;
        lineSpacing = curFontSize * 1.30;
        extraHintH = (hasTapAction && showTapHint) ? Math.round(curFontSize * 0.72) : 0;
        hintSpacing = (hasTapAction && showTapHint) ? Math.round(curFontSize * 0.85) : 0;
        finalLines = [];

        for (const para of paragraphs) {
            let currentLine = '';
            for (let i = 0; i < para.length; i++) {
                const char = para[i];
                const testLine = currentLine + char;
                const metrics = ctx.measureText(testLine);
                if (metrics.width > maxTextWidth && currentLine.length > 0) {
                    finalLines.push(currentLine);
                    currentLine = char;
                } else {
                    currentLine = testLine;
                }
            }
            if (currentLine.length > 0) {
                finalLines.push(currentLine);
            }
        }

        const totalLinesH = (finalLines.length - 1) * lineSpacing;
        textBlockH = totalLinesH + curFontSize + extraHintH + hintSpacing;

        // 高さ固定モード時は利用可能高さに収まっていれば決定、または自動伸縮時
        if (isHeightAuto || textBlockH <= availableH) {
            break;
        }

        // 収まらない場合はフォントサイズを2px下げて再試行
        curFontSize -= 2;
    }

    const actualFontSize = curFontSize;
    let bannerH;

    if (isHeightAuto) {
        // auto: 行数とフォントサイズに応じて余白を最適化
        bannerH = Math.max(140, Math.round(textBlockH + (actualFontSize * 0.85)));
        if (elements.userMenuBannerHeightNumber) {
            elements.userMenuBannerHeightNumber.value = bannerH;
        }
        if (elements.userMenuBannerHeightInput) {
            elements.userMenuBannerHeightInput.value = bannerH;
        }
        if (elements.userMenuHeightModeBadge) {
            elements.userMenuHeightModeBadge.textContent = `自動伸縮 (${bannerH}px)`;
            elements.userMenuHeightModeBadge.style.background = '#e0e7ff';
            elements.userMenuHeightModeBadge.style.color = '#4338ca';
        }
    } else {
        // 高さ固定モード（px固定指定: デフォルト300px）
        bannerH = specifiedHeight;
        if (elements.userMenuHeightModeBadge) {
            const sizeNotice = (actualFontSize < requestedFontSize) ? ` (自動縮小 ${actualFontSize}px)` : '';
            elements.userMenuHeightModeBadge.textContent = `高さ固定 (${bannerH}px)${sizeNotice}`;
            elements.userMenuHeightModeBadge.style.background = '#fef3c7';
            elements.userMenuHeightModeBadge.style.color = '#b45309';
        }
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
        ctx.shadowBlur = Math.min(16, Math.max(6, Math.round(actualFontSize * 0.16)));
        ctx.shadowOffsetY = 2;
    } else {
        ctx.shadowColor = 'rgba(255, 255, 255, 0.6)';
        ctx.shadowBlur = Math.min(10, Math.max(4, Math.round(actualFontSize * 0.12)));
        ctx.shadowOffsetY = 1;
    }

    ctx.font = `bold ${actualFontSize}px "Noto Sans JP", -apple-system, BlinkMacSystemFont, sans-serif`;

    const totalLinesH = (finalLines.length - 1) * lineSpacing;
    const vAlign = elements.userMenuTextAlignV ? elements.userMenuTextAlignV.value : 'center';
    const shiftY = (hasTapAction && showTapHint) ? -Math.round(extraHintH * 0.38) : 0;

    let startY;
    if (vAlign === 'top') {
        startY = bannerY + Math.max(25, Math.round(actualFontSize * 0.70)) + (actualFontSize / 2) + shiftY;
    } else if (vAlign === 'bottom') {
        const bottomPad = (hasTapAction && showTapHint) ? Math.round(extraHintH * 1.5) : Math.max(25, Math.round(actualFontSize * 0.70));
        startY = (bannerY + bannerH) - bottomPad - totalLinesH - (actualFontSize / 2);
    } else {
        // 中央揃え (標準)
        startY = ((bannerY + bannerH / 2) - (totalLinesH / 2)) + shiftY;
    }

    finalLines.forEach((line, idx) => {
        const lineY = startY + (idx * lineSpacing);
        ctx.fillText(line, w / 2, lineY); // 第4引数の強制長体縮小を使わずに自然描画
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

        const hintFontSize = Math.max(22, Math.round(actualFontSize * 0.46));
        ctx.font = `bold ${hintFontSize}px "Noto Sans JP", -apple-system, sans-serif`;
        const hintY = startY + totalLinesH + (actualFontSize * 0.90);

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
        const res = await fetch(`../api.php?action=admin_get_line_notification_settings`);
        const data = await res.json();
        
        if (data.success && data.settings) {
            const s = data.settings;
            if (elements.adminLineUidsInput) {
                const rawArr = s.admin_line_uids || s.admin_uids || [];
                const uids = Array.isArray(rawArr) ? rawArr : [];
                elements.adminLineUidsInput.value = uids.join('\n');
            }
            if (elements.notifyChatCheck) elements.notifyChatCheck.checked = (s.notify_chat !== false && s.notify_chat !== 0);
            if (elements.notifyFollowCheck) elements.notifyFollowCheck.checked = (s.notify_follow !== false && s.notify_follow !== 0);
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
    formData.append('notify_chat', elements.notifyChatCheck && elements.notifyChatCheck.checked ? '1' : '0');
    formData.append('notify_follow', elements.notifyFollowCheck && elements.notifyFollowCheck.checked ? '1' : '0');
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

    // 本システムのWebhook URL（LINE Developersに登録するURL）を対象アカウントに応じて自動生成表示
    if (elements.displayOurWebhookUrl) {
        const curAcc = state.activeAccount || 'senior';
        const query = (curAcc === 'senior') ? '' : `?account=${encodeURIComponent(curAcc)}`;
        const fullUrl = `${getAppBaseUrl()}/webhook.php${query}`;
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
        const curAcc = state.activeAccount || 'senior';
        const res = await fetch(`../api.php?action=admin_get_proline_settings&account=${encodeURIComponent(curAcc)}`);
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
    const curAcc = state.activeAccount || 'senior';

    try {
        if (elements.saveProlineSettingsBtn) elements.saveProlineSettingsBtn.disabled = true;
        if (elements.prolineSaveStatus) elements.prolineSaveStatus.textContent = '保存中...';

        const payload = new URLSearchParams({
            action: 'admin_save_proline_settings',
            password: state.password,
            account: curAcc,
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
            showToast('✅ プロライン連携設定を保存しました（アカウント設定と完全同期）');
            
            // state.accounts も同期更新
            if (state.accounts && state.accounts[curAcc]) {
                state.accounts[curAcc].proline_webhook_url = url;
                if (calendarUrl) state.accounts[curAcc].proline_calendar_url = calendarUrl;
            }

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
        alert('転送先のWebhook URLを入力してください');
        return;
    }

    const btn = elements.testProlineRelayBtn;
    const banner = elements.prolineTestResultBanner;
    const curAcc = state.activeAccount || 'senior';

    try {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> テスト実行中...';
        }
        if (banner) {
            banner.style.display = 'block';
            banner.style.background = '#f1f5f9';
            banner.style.color = '#475569';
            banner.style.border = '1px solid #cbd5e1';
            banner.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 登録先へテストWebhook（Pingペイロード）を転送しています...';
        }

        const payload = new URLSearchParams({
            action: 'admin_test_proline_relay',
            password: state.password,
            account: curAcc,
            url: url
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.results && data.results.length > 0) {
            const isAllOk = Boolean(data.success);
            if (banner) {
                banner.style.display = 'block';
                banner.style.background = isAllOk ? '#ecfdf5' : '#fffbeb';
                banner.style.color = isAllOk ? '#065f46' : '#92400e';
                banner.style.border = isAllOk ? '1px solid #a7f3d0' : '1px solid #fde68a';

                let resultsHtml = `<div style="font-weight: bold; margin-bottom: 6px;">${escapeHtml(data.message)}</div>`;
                resultsHtml += '<div style="display: flex; flex-direction: column; gap: 4px; font-size: 11.5px;">';
                data.results.forEach((r, i) => {
                    const badge = r.success 
                        ? `<span style="background: #10b981; color: #fff; padding: 1px 6px; border-radius: 4px; font-weight: bold; font-size: 10px;">OK (${r.http_code})</span>`
                        : `<span style="background: #ef4444; color: #fff; padding: 1px 6px; border-radius: 4px; font-weight: bold; font-size: 10px;">FAIL (${r.http_code})</span>`;
                    resultsHtml += `
                        <div style="background: rgba(255,255,255,0.7); padding: 6px 8px; border-radius: 4px; border: 1px solid rgba(0,0,0,0.06);">
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                                <div style="font-family: monospace; word-break: break-all; font-weight: 600;">${escapeHtml(r.url)}</div>
                                <div>${badge} <span style="font-size: 10.5px; color: #64748b;">${r.duration_ms}ms</span></div>
                            </div>
                            ${r.error ? `<div style="color: #dc2626; font-size: 10.5px; margin-top: 2px;">エラー: ${escapeHtml(r.error)}</div>` : ''}
                        </div>
                    `;
                });
                resultsHtml += '</div>';
                banner.innerHTML = resultsHtml;
            }
            if (isAllOk) {
                showToast('✅ 全ての転送先への疎通テストに成功しました！');
            } else {
                showToast('⚠️ 一部の転送先で疎通エラーが発生しました');
            }
            loadProlineSettings();
        } else if (data.success) {
            if (banner) {
                banner.style.display = 'block';
                banner.style.background = '#ecfdf5';
                banner.style.color = '#065f46';
                banner.style.border = '1px solid #a7f3d0';
                banner.innerHTML = `<strong>${escapeHtml(data.message)}</strong><br><span style="font-size:11px;">応答所要時間: ${data.duration_ms}ms / HTTPステータス: ${data.http_code}</span>`;
            }
            showToast('✅ 疎通テスト成功！');
            loadProlineSettings();
        } else {
            if (banner) {
                banner.style.display = 'block';
                banner.style.background = '#fef2f2';
                banner.style.color = '#991b1b';
                banner.style.border = '1px solid #fecaca';
                banner.innerHTML = `<strong>⚠️ 疎通エラー: ${escapeHtml(data.message || data.error)}</strong><br><span style="font-size:11px;">詳細: ${escapeHtml(data.error || '')} (HTTP: ${data.http_code || 0})</span>`;
            }
            showToast('⚠️ 疎通テスト失敗');
        }
    } catch (e) {
        if (banner) {
            banner.style.display = 'block';
            banner.style.background = '#fef2f2';
            banner.style.color = '#991b1b';
            banner.style.border = '1px solid #fecaca';
            banner.innerHTML = '<strong>❌ 通信エラーが発生しました</strong>';
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-rotate"></i> 疎通テスト実行';
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

/* ==========================================================================
   会社DX 関心度アンケート配信スタジオ機能
   ========================================================================== */

function initDxSurveyModal() {
    const openBtn = document.getElementById('openDxSurveyModalBtn') || elements.openDxSurveyModalBtn;
    const closeBtn = document.getElementById('closeDxSurveyModalBtn') || elements.closeDxSurveyModalBtn;
    const cancelBtn = document.getElementById('cancelDxSurveyBtn') || elements.cancelDxSurveyBtn;
    const submitBtn = document.getElementById('submitDxSurveyBtn') || elements.submitDxSurveyBtn;
    const modal = document.getElementById('dxSurveyModal') || elements.dxSurveyModal;

    openBtn?.addEventListener('click', () => openDxSurveyModal());
    closeBtn?.addEventListener('click', closeDxSurveyModal);
    cancelBtn?.addEventListener('click', closeDxSurveyModal);

    modal?.addEventListener('click', (e) => {
        if (e.target === modal) closeDxSurveyModal();
    });

    document.querySelectorAll('input[name="dxSurveyTargetType"]').forEach(radio => {
        radio.addEventListener('change', updateDxSurveyTargetUI);
    });

    const userSelect = document.getElementById('dxSurveyUserSelect') || elements.dxSurveyUserSelect;
    userSelect?.addEventListener('change', updateDxSurveyTargetUI);

    submitBtn?.addEventListener('click', submitDxSurvey);
}

function openDxSurveyModal(targetUserId = null) {
    const modal = document.getElementById('dxSurveyModal') || elements.dxSurveyModal;
    if (!modal) return;

    populateDxSurveyTargetUsers(targetUserId);

    const allRadio = document.getElementById('dxSurveyTargetAll');
    const singleRadio = document.getElementById('dxSurveyTargetSingle');

    if (targetUserId) {
        if (singleRadio) singleRadio.checked = true;
    } else {
        if (allRadio) allRadio.checked = true;
    }

    updateDxSurveyTargetUI();
    modal.classList.add('active');
}

function closeDxSurveyModal() {
    const modal = document.getElementById('dxSurveyModal') || elements.dxSurveyModal;
    if (modal) modal.classList.remove('active');
}

function populateDxSurveyTargetUsers(selectedUserId = null) {
    const select = document.getElementById('dxSurveyUserSelect') || elements.dxSurveyUserSelect;
    if (!select) return;

    select.innerHTML = '<option value="">-- 送信対象の顧客を選択 --</option>';
    const lineUsers = state.allCustomers.filter(c => c.user_id && c.user_id.startsWith('U'));
    lineUsers.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.user_id;
        const name = c.user_name || '名前なし';
        const model = c.car_model ? ` (${c.car_model})` : '';
        opt.textContent = `${name}${model}`;
        if (selectedUserId && c.user_id === selectedUserId) {
            opt.selected = true;
        }
        select.appendChild(opt);
    });
}

function updateDxSurveyTargetUI() {
    const targetType = document.querySelector('input[name="dxSurveyTargetType"]:checked')?.value || 'all';
    const singleWrap = document.getElementById('dxSurveySingleTargetWrap') || elements.dxSurveySingleTargetWrap;
    const summaryText = document.getElementById('dxSurveyTargetSummaryText') || elements.dxSurveyTargetSummaryText;
    const select = document.getElementById('dxSurveyUserSelect') || elements.dxSurveyUserSelect;

    if (targetType === 'single') {
        if (singleWrap) singleWrap.style.display = 'block';
        const uid = select?.value;
        const cust = state.allCustomers.find(c => c.user_id === uid);
        const name = cust ? (cust.user_name || '指定顧客') : '選択中の顧客';
        if (summaryText) summaryText.innerHTML = `配信先: <strong>👤 【${escapeHtml(name)} 様】へ個別送信</strong>`;
    } else {
        if (singleWrap) singleWrap.style.display = 'none';
        if (summaryText) summaryText.innerHTML = '配信先: <strong>👥 LINE公式アカウントの友だち全員（一斉配信）</strong>';
    }
}

async function submitDxSurvey() {
    const targetType = document.querySelector('input[name="dxSurveyTargetType"]:checked')?.value || 'all';
    const select = document.getElementById('dxSurveyUserSelect') || elements.dxSurveyUserSelect;
    let targetUid = '';
    let targetName = 'LINE友だち全員';

    if (targetType === 'single') {
        targetUid = select?.value || '';
        if (!targetUid) {
            alert('送信先の顧客を選択してください');
            return;
        }
        const cust = state.allCustomers.find(c => c.user_id === targetUid);
        targetName = cust ? `${cust.user_name || '顧客'} 様` : '指定顧客';
    }

    const confirmMsg = (targetType === 'all')
        ? '【会社DX LINE公式の友だち全員（一斉配信）】へ、DX関心度アンケート（Flex Message）を今すぐ配信しますか？\n（※全友だちのトーク画面へ即座に送信されます）'
        : `【${targetName}】へ、DX関心度アンケート（Flex Message）をLINE送信しますか？`;

    if (!confirm(confirmMsg)) return;

    const btn = document.getElementById('submitDxSurveyBtn') || elements.submitDxSurveyBtn;
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> LINE送信中...';
    }

    try {
        const payload = new URLSearchParams({
            action: 'send_dx_survey',
            account: state.activeAccount || 'kaisya_dx',
            target_type: (targetType === 'single' ? 'user' : 'all'),
            user_id: targetUid
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });

        const data = await res.json();
        if (data && data.success) {
            showToast(data.message || 'DXアンケートを送信しました！');
            closeDxSurveyModal();
            await fetchCustomers();
        } else {
            alert((data && data.error) ? data.error : '送信に失敗しました');
        }
    } catch (e) {
        console.error('DX survey send error:', e);
        alert('送信エラーが発生しました: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }
}

window.openDxSurveyModal = openDxSurveyModal;
window.closeDxSurveyModal = closeDxSurveyModal;

/* ==========================================================================
   個別LINEチャット機能（メッセージ履歴閲覧・Push返信・未読管理）
   ========================================================================== */

function initChatModal() {
    const closeBtn = elements.btnCloseChatModal || document.getElementById('btnCloseChatModal');
    if (closeBtn) {
        closeBtn.addEventListener('click', closeChatModal);
    }
    const refreshBtn = elements.btnRefreshChatMessages || document.getElementById('btnRefreshChatMessages');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            if (state.activeChatUser && state.activeChatUser.user_id) {
                loadChatMessages(state.activeChatUser.user_id);
            }
        });
    }
    const sendBtn = elements.btnSendChatMessage || document.getElementById('btnSendChatMessage');
    if (sendBtn) {
        sendBtn.addEventListener('click', sendChatMessage);
    }
    const inputArea = elements.chatInputText || document.getElementById('chatInputText');
    if (inputArea) {
        inputArea.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                sendChatMessage();
            }
        });
    }

    // 送信者名（担当者名）の初期化
    const senderInput = document.getElementById('chatSenderNameInput');
    const savedSender = localStorage.getItem('kureba_chat_sender_name') ?? '';

    if (senderInput) {
        senderInput.value = savedSender;
        senderInput.addEventListener('input', (e) => {
            const val = e.target.value.trim();
            localStorage.setItem('kureba_chat_sender_name', val);
            updateSenderPreviewHint();
            updateSenderChipActive(val);
        });
    }

    // 送信者名クイックチップ
    document.querySelectorAll('.chat-sender-chips .sender-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            const name = chip.getAttribute('data-name') || '';
            if (senderInput) senderInput.value = name;
            localStorage.setItem('kureba_chat_sender_name', name);
            updateSenderPreviewHint();
            updateSenderChipActive(name);
            showToast(name ? `送信者を「${name}」に設定しました` : '送信者を「なし（公式名・from非表示）」に設定しました', 'info');
        });
    });

    updateSenderPreviewHint();
    updateSenderChipActive(savedSender);

    // 定型文クイックチップ
    document.querySelectorAll('.chat-quick-templates .quick-tpl-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            const tpl = chip.getAttribute('data-tpl');
            const targetInput = elements.chatInputText || document.getElementById('chatInputText');
            if (tpl && targetInput) {
                const cur = targetInput.value;
                targetInput.value = cur ? (cur + "\n" + tpl) : tpl;
                targetInput.focus();
                targetInput.scrollTop = targetInput.scrollHeight;
            }
        });
    });

    // モーダル背景クリックで閉じる
    const modal = elements.chatModal || document.getElementById('chatModal');
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeChatModal();
            }
        });
    }
}

function updateSenderPreviewHint() {
    const hintEl = document.getElementById('chatSenderPreviewHint');
    const senderInput = document.getElementById('chatSenderNameInput');
    if (!hintEl) return;

    const name = (senderInput ? senderInput.value.trim() : '');

    if (!name) {
        hintEl.innerHTML = `<i class="fa-brands fa-line" style="color: #06C755;"></i> 公式アカウント名で送信（fromなし）`;
        hintEl.style.color = '#15803d';
    } else {
        hintEl.innerHTML = `<i class="fa-solid fa-tag" style="color: #4f46e5;"></i> 吹き出し上に「from ${escapeHtml(name)}」を表示`;
        hintEl.style.color = '#4338ca';
    }
}

function updateSenderChipActive(currentName = '') {
    document.querySelectorAll('.chat-sender-chips .sender-chip').forEach(chip => {
        const chipName = chip.getAttribute('data-name') || '';
        const isMatch = (chipName === currentName);
        if (isMatch) {
            chip.style.background = '#eef2ff';
            chip.style.borderColor = '#6366f1';
            chip.style.color = '#4338ca';
            chip.style.fontWeight = '700';
        } else {
            chip.style.background = '#f1f5f9';
            chip.style.borderColor = '#cbd5e1';
            chip.style.color = '#334155';
            chip.style.fontWeight = '500';
        }
    });
}

const DEFAULT_AVATAR_URL = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2394a3b8'%3E%3Cpath d='M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z'/%3E%3C/svg%3E";

function openChatModal(cust, targetIdx = null) {
    if (!cust && typeof targetIdx !== 'number') return;

    // 1. 数値インデックスからの解決
    if (typeof cust === 'number') {
        targetIdx = cust;
        cust = null;
    }
    if (!cust && typeof targetIdx === 'number') {
        cust = (state.currentFilteredList && state.currentFilteredList[targetIdx]) ||
               (state.allCustomers && state.allCustomers[targetIdx]);
    }

    // 2. 文字列（UIDまたはID）が渡された場合の解決
    if (typeof cust === 'string') {
        const targetId = cust.trim();
        const found = state.allCustomers && state.allCustomers.length > 0
            ? state.allCustomers.find(c => 
                (c.user_id && c.user_id === targetId) || 
                String(c.id) === String(targetId) ||
                (c.user_name && c.user_name === targetId)
            )
            : null;
        cust = found || {
            user_id: targetId.startsWith('U') ? targetId : '',
            id: targetId,
            user_name: 'LINE受講生',
            picture_url: '',
            car_model: 'コース未設定'
        };
    }

    if (!cust) return;

    state.activeChatUser = cust;

    const uid = cust.user_id || '';
    const name = cust.user_name || '名前なし受講生';
    const pic = cust.picture_url || DEFAULT_AVATAR_URL;
    const course = `${cust.car_model || 'コース未設定'}${cust.car_number ? ' / ' + cust.car_number : ''}`;

    const avatarEl = elements.chatModalAvatar || document.getElementById('chatModalAvatar');
    if (avatarEl) {
        avatarEl.referrerPolicy = 'no-referrer';
        avatarEl.src = pic;
        avatarEl.onerror = function() {
            this.onerror = null;
            this.src = DEFAULT_AVATAR_URL;
        };
    }

    const userNameEl = elements.chatModalUserName || document.getElementById('chatModalUserName');
    const isBlocked = (cust.is_blocked == 1);
    if (userNameEl) {
        const safeName = (typeof escapeHtml === 'function') ? escapeHtml(name) : name;
        const safeUid = (typeof escapeHtml === 'function') ? escapeHtml(uid || '未連携') : (uid || '未連携');
        const blockBadge = isBlocked 
            ? `<span style="font-size: 11px; background: #fee2e2; color: #b91c1c; border: 1px solid #fecdd3; padding: 1px 7px; border-radius: var(--radius-xs); font-weight: bold; margin-left: 6px;"><i class="fa-solid fa-user-slash"></i> ブロック中</span>`
            : `<span style="font-size: 11px; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 1px 7px; border-radius: var(--radius-xs); font-weight: bold; margin-left: 6px;"><i class="fa-solid fa-user-check"></i> 友だち中</span>`;
        userNameEl.innerHTML = `${safeName} ${blockBadge} <span id="chatModalUidTag" style="font-size: 11px; font-weight: normal; color: #64748b; font-family: monospace;">(${safeUid})</span>`;
    }

    const courseInfoEl = elements.chatModalCourseInfo || document.getElementById('chatModalCourseInfo');
    if (courseInfoEl) {
        courseInfoEl.innerHTML = isBlocked 
            ? `<span style="color: #ef4444; font-weight: bold;"><i class="fa-solid fa-triangle-exclamation"></i> この友だちは公式アカウントをブロック中です（送信したメッセージは届きません）</span>`
            : escapeHtml(course);
    }

    const inputArea = elements.chatInputText || document.getElementById('chatInputText');
    if (inputArea) {
        inputArea.value = '';
    }

    // 保存済み送信者名のセット
    const senderInput = document.getElementById('chatSenderNameInput');
    if (senderInput) {
        const savedSender = localStorage.getItem('kureba_chat_sender_name') ?? '';
        senderInput.value = savedSender;
        updateSenderPreviewHint();
        updateSenderChipActive(savedSender);
    }

    // 未読数をローカルでクリア（テーブル再描画は行わずバッジのみ非表示）
    if (uid && state.unreadChatCounts && state.unreadChatCounts[uid]) {
        delete state.unreadChatCounts[uid];
        const badges = document.querySelectorAll(`.badge-chat-unread[data-uid="${uid}"]`);
        badges.forEach(b => b.style.display = 'none');
    }

    const modal = elements.chatModal || document.getElementById('chatModal');
    if (modal) {
        modal.style.setProperty('display', 'flex', 'important');
        modal.style.setProperty('opacity', '1', 'important');
        modal.style.setProperty('visibility', 'visible', 'important');
        modal.style.setProperty('z-index', '99999', 'important');
        modal.classList.add('active');
    }

    loadChatMessages(uid);

    // 名前やアイコンが仮データの場合、受講生詳細APIから非同期で最新カルテ情報を補完
    if (uid && (cust.user_name === 'LINE受講生' || !cust.picture_url)) {
        fetch(`../api.php?action=get_customer_detail&user_id=${encodeURIComponent(uid)}&account=${encodeURIComponent(state.activeAccount)}`)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.customer) {
                    const fresh = data.customer;
                    state.activeChatUser = { ...cust, ...fresh };
                    if (userNameEl) {
                        const sName = escapeHtml(fresh.user_name || fresh.name || name);
                        userNameEl.innerHTML = `${sName} <span id="chatModalUidTag" style="font-size: 11px; font-weight: normal; color: #64748b; font-family: monospace;">(${escapeHtml(uid)})</span>`;
                    }
                    if (avatarEl && fresh.picture_url) {
                        avatarEl.src = fresh.picture_url;
                    }
                    if (courseInfoEl && (fresh.car_model || fresh.car_number)) {
                        courseInfoEl.textContent = `${fresh.car_model || 'コース未設定'}${fresh.car_number ? ' / ' + fresh.car_number : ''}`;
                    }
                }
            })
            .catch(() => {});
    }

    // ポーリングタイマー開始（10秒ごとに新着自動確認）
    if (state.chatPollTimer) clearInterval(state.chatPollTimer);
    state.chatPollTimer = setInterval(() => {
        const checkModal = elements.chatModal || document.getElementById('chatModal');
        if (state.activeChatUser && state.activeChatUser.user_id && checkModal && checkModal.classList.contains('active')) {
            loadChatMessages(state.activeChatUser.user_id, true);
        }
    }, 10000);
}

function closeChatModal() {
    const modal = elements.chatModal || document.getElementById('chatModal');
    if (modal) {
        modal.classList.remove('active');
        modal.style.removeProperty('display');
        modal.style.removeProperty('opacity');
        modal.style.removeProperty('visibility');
        modal.style.removeProperty('z-index');
    }
    if (state.chatPollTimer) {
        clearInterval(state.chatPollTimer);
        state.chatPollTimer = null;
    }
    state.activeChatUser = null;
    loadUnreadChatCounts();
}

function openChatModalByIndex(idx) {
    const cust = (state.currentFilteredList && state.currentFilteredList[idx]) ||
                 (state.allCustomers && state.allCustomers[idx]);
    if (cust) {
        openChatModal(cust, idx);
    } else {
        openChatModal(idx);
    }
}

function openChatModalByUid(uid, idx = null) {
    if (typeof idx === 'number') {
        openChatModalByIndex(idx);
        return;
    }
    openChatModal(uid);
}

window.openChatModal = openChatModal;
window.openChatModalByIndex = openChatModalByIndex;
window.openChatModalByUid = openChatModalByUid;
window.closeChatModal = closeChatModal;

async function loadChatMessages(userId, isSilent = false) {
    if (!userId) {
        if (elements.chatMessagesContainer) {
            elements.chatMessagesContainer.innerHTML = `
                <div class="chat-empty-state">
                    <div class="chat-empty-icon"><i class="fa-solid fa-triangle-exclamation" style="color: #f59e0b;"></i></div>
                    <p style="font-weight: bold;">LINEユーザーIDが未連携です</p>
                    <p style="font-size: 12px; opacity: 0.9;">この受講生は手動登録されているため、LINEメッセージを送受信できません。</p>
                </div>
            `;
        }
        return;
    }

    if (!isSilent && elements.chatMessagesContainer) {
        elements.chatMessagesContainer.innerHTML = `
            <div class="chat-empty-state">
                <div class="chat-empty-icon"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <p>メッセージ履歴を読み込み中...</p>
            </div>
        `;
    }

    try {
        const res = await fetch(`../api.php?action=get_chat_messages&user_id=${encodeURIComponent(userId)}&account=${encodeURIComponent(state.activeAccount)}`);
        const data = await res.json();

        if (data.success && Array.isArray(data.messages)) {
            renderChatMessages(data.messages);
        } else {
            if (!isSilent && elements.chatMessagesContainer) {
                elements.chatMessagesContainer.innerHTML = `
                    <div class="chat-empty-state">
                        <div class="chat-empty-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
                        <p>${escapeHtml(data.error || 'メッセージ履歴の取得に失敗しました')}</p>
                    </div>
                `;
            }
        }
    } catch (e) {
        console.error('Failed to load chat messages:', e);
        if (!isSilent && elements.chatMessagesContainer) {
            elements.chatMessagesContainer.innerHTML = `
                <div class="chat-empty-state">
                    <div class="chat-empty-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <p>通信エラーが発生しました: ${escapeHtml(e.message)}</p>
                </div>
            `;
        }
    }
}

function renderChatMessages(messages) {
    const container = elements.chatMessagesContainer;
    if (!container) return;

    if (!messages || messages.length === 0) {
        container.innerHTML = `
            <div class="chat-empty-state">
                <div class="chat-empty-icon"><i class="fa-regular fa-comment-dots"></i></div>
                <p style="font-weight: 700; font-size: 14px;">まだメッセージのやり取りはありません</p>
                <p style="font-size: 12px; opacity: 0.85;">下の入力欄からメッセージを送信すると、受講生のLINE公式トーク画面へ届きます。</p>
            </div>
        `;
        return;
    }

    let lastDateStr = '';
    const htmlParts = [];

    const defaultUserPic = (state.activeChatUser && state.activeChatUser.picture_url)
        ? state.activeChatUser.picture_url
        : DEFAULT_AVATAR_URL;

    messages.forEach(msg => {
        const createdAt = msg.created_at || '';
        const datePart = createdAt.substring(0, 10);
        const timePart = createdAt.length >= 16 ? createdAt.substring(11, 16) : '';

        // 日付が変わったら日付セパレータを挿入
        if (datePart && datePart !== lastDateStr) {
            lastDateStr = datePart;
            const parts = datePart.split('-');
            const m = parseInt(parts[1], 10);
            const d = parseInt(parts[2], 10);
            const formattedDate = `${m}月${d}日 (${getDayOfWeek(datePart)})`;
            htmlParts.push(`
                <div class="chat-date-separator">
                    <span class="chat-date-badge">${formattedDate}</span>
                </div>
            `);
        }

        const isIncoming = (msg.direction === 'incoming');
        const rowClass = isIncoming ? 'chat-row-incoming' : 'chat-row-outgoing';
        const senderTag = isIncoming ? '受講生' : (msg.sent_by || 'スタッフ');

        let bubbleContent = '';
        if (msg.message_type === 'image') {
            let previewUrl = '';
            try {
                let pObj = msg.payload;
                if (!pObj && msg.payload_json) {
                    pObj = typeof msg.payload_json === 'string' ? JSON.parse(msg.payload_json) : msg.payload_json;
                } else if (typeof pObj === 'string') {
                    pObj = JSON.parse(pObj);
                }
                previewUrl = (pObj && (pObj.url || pObj.imageUrl || pObj.originalContentUrl)) || '';
            } catch {
                previewUrl = '';
            }

            bubbleContent = previewUrl
                ? `<div class="chat-image-wrap">
                    <img class="chat-image-preview" src="${escapeHtml(previewUrl)}" alt="受信画像" loading="lazy" onclick="window.open('${escapeHtml(previewUrl)}', '_blank')">
                    <div style="font-size:10px; margin-top:4px; opacity:0.8; text-align:right;"><i class="fa-solid fa-up-right-from-square"></i> クリックで原寸表示</div>
                   </div>`
                : `<div style="display:flex; align-items:center; gap:6px;"><i class="fa-solid fa-image" style="font-size:18px;"></i> <span>[📷 画像メッセージ]</span></div>`;
        } else if (msg.message_type === 'video') {
            let videoUrl = '';
            try {
                let pObj = msg.payload || (msg.payload_json ? (typeof msg.payload_json === 'string' ? JSON.parse(msg.payload_json) : msg.payload_json) : {});
                videoUrl = (pObj && pObj.url) || '';
            } catch {}
            bubbleContent = videoUrl
                ? `<div class="chat-video-wrap">
                    <video src="${escapeHtml(videoUrl)}" controls style="max-width:240px; border-radius:8px; display:block;"></video>
                   </div>`
                : `<div style="display:flex; align-items:center; gap:6px;"><i class="fa-solid fa-video"></i> <span>[🎬 動画メッセージ]</span></div>`;
        } else if (msg.message_type === 'audio') {
            let audioUrl = '';
            try {
                let pObj = msg.payload || (msg.payload_json ? (typeof msg.payload_json === 'string' ? JSON.parse(msg.payload_json) : msg.payload_json) : {});
                audioUrl = (pObj && pObj.url) || '';
            } catch {}
            bubbleContent = audioUrl
                ? `<div class="chat-audio-wrap">
                    <audio src="${escapeHtml(audioUrl)}" controls style="max-width:220px; display:block;"></audio>
                   </div>`
                : `<div style="display:flex; align-items:center; gap:6px;"><i class="fa-solid fa-microphone"></i> <span>[🎵 音声メッセージ]</span></div>`;
        } else if (msg.message_type === 'sticker') {
            bubbleContent = `<div style="font-size: 24px; text-align:center;">😊</div><div style="font-size: 11px; opacity: 0.85; text-align:center;">[スタンプ]</div>`;
        } else {
            // テキストメッセージ
            bubbleContent = formatChatMessageText(msg.message_text || '');
        }

        htmlParts.push(`
            <div class="chat-message-row ${rowClass}">
                ${isIncoming ? `
                    <img class="chat-msg-avatar" src="${escapeHtml(defaultUserPic)}" alt="User" referrerpolicy="no-referrer" onerror="this.onerror=null; this.src=DEFAULT_AVATAR_URL;">
                ` : ''}
                <div class="chat-bubble-wrapper">
                    <div class="chat-bubble">${bubbleContent}</div>
                    <div class="chat-msg-meta">
                        <span class="chat-msg-sender-tag">${escapeHtml(senderTag)}</span>
                        <span>${escapeHtml(timePart)}</span>
                    </div>
                </div>
            </div>
        `);
    });

    container.innerHTML = htmlParts.join('');
    container.scrollTop = container.scrollHeight;
}

function formatChatMessageText(text) {
    if (!text) return '';
    const escaped = escapeHtml(text);
    const urlRegex = /(https?:\/\/[^\s]+)/g;
    return escaped.replace(urlRegex, (url) => {
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" style="color: inherit; text-decoration: underline; font-weight: 600;">${url}</a>`;
    });
}

function getDayOfWeek(dateStr) {
    try {
        const days = ['日', '月', '火', '水', '木', '金', '土'];
        const d = new Date(dateStr);
        return days[d.getDay()] || '';
    } catch {
        return '';
    }
}

async function sendChatMessage() {
    if (!state.activeChatUser || !state.activeChatUser.user_id) {
        alert('送信対象の受講生情報が見つかりません');
        return;
    }

    const text = elements.chatInputText ? elements.chatInputText.value.trim() : '';
    if (!text) {
        elements.chatInputText?.focus();
        return;
    }

    const sendBtn = elements.btnSendChatMessage;
    const origHtml = sendBtn ? sendBtn.innerHTML : '';
    if (sendBtn) {
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    }

    const senderInput = document.getElementById('chatSenderNameInput');
    const senderName = (senderInput ? senderInput.value : '').trim();

    try {
        const payload = new URLSearchParams({
            action: 'send_chat_message',
            password: state.password,
            user_id: state.activeChatUser.user_id,
            text: text,
            sender_name: senderName,
            sent_by: senderName,
            account: state.activeAccount
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success) {
            if (elements.chatInputText) elements.chatInputText.value = '';
            showToast('メッセージを送信しました！');
            await loadChatMessages(state.activeChatUser.user_id, true);
            fetchCustomers();
        } else {
            alert(data.error || 'メッセージの送信に失敗しました');
        }
    } catch (e) {
        console.error('Send message error:', e);
        alert('送信処理中にエラーが発生しました: ' + e.message);
    } finally {
        if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.innerHTML = origHtml;
            elements.chatInputText?.focus();
        }
    }
}

// ==========================================================================
// ブラウザ通知 & チャイム音 & チャット未読監視機能
// ==========================================================================

let lastTotalUnreadCount = -1;
const notifiedMsgIds = new Set();
let globalChatUnreadTimer = null;

// AudioContext を安全に自動アンロック
let sharedAudioCtx = null;
function getSharedAudioContext() {
    try {
        if (!sharedAudioCtx) {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (AudioCtx) sharedAudioCtx = new AudioCtx();
        }
        if (sharedAudioCtx && sharedAudioCtx.state === 'suspended') {
            sharedAudioCtx.resume().catch(() => {});
        }
    } catch(e) {}
    return sharedAudioCtx;
}
document.addEventListener('click', () => { getSharedAudioContext(); }, { once: true });
document.addEventListener('keydown', () => { getSharedAudioContext(); }, { once: true });

// Web Audio APIによる優しいチャイム音再生 (880Hz -> 1320Hz サイン波)
function playNotificationSound() {
    try {
        const ctx = getSharedAudioContext();
        if (!ctx) return;
        const now = ctx.currentTime;

        const osc1 = ctx.createOscillator();
        const gain1 = ctx.createGain();
        osc1.type = 'sine';
        osc1.frequency.setValueAtTime(880, now); // A5
        gain1.gain.setValueAtTime(0.15, now);
        gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.3);
        osc1.connect(gain1);
        gain1.connect(ctx.destination);
        osc1.start(now);
        osc1.stop(now + 0.3);

        const osc2 = ctx.createOscillator();
        const gain2 = ctx.createGain();
        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(1318.5, now + 0.12); // E6
        gain2.gain.setValueAtTime(0.18, now + 0.12);
        gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.55);
        osc2.connect(gain2);
        gain2.connect(ctx.destination);
        osc2.start(now + 0.12);
        osc2.stop(now + 0.55);
    } catch (e) {
        console.warn('Audio play skipped:', e);
    }
}

async function loadUnreadChatCounts() {
    try {
        const res = await fetch(`../api.php?action=get_unread_chat_counts&account=${encodeURIComponent(state.activeAccount)}`);
        const data = await res.json();
        if (data.success && data.unread_counts) {
            const newCounts = data.unread_counts || {};
            const recentUnread = data.recent_unread || [];
            let totalUnread = 0;
            Object.values(newCounts).forEach(cnt => { totalUnread += parseInt(cnt, 10) || 0; });

            const checkSound = document.getElementById('checkNotifSound');
            const shouldPlaySound = !checkSound || checkSound.checked;

            const isNotifEnabled = ("Notification" in window) && (Notification.permission === "granted") && (localStorage.getItem('kureba_browser_notif_enabled') !== 'false');

            let hasNewIncoming = false;

            // 新着メッセージ検知時の通知処理
            if (Array.isArray(recentUnread) && recentUnread.length > 0) {
                recentUnread.forEach(msg => {
                    const msgId = msg.id;
                    if (!msgId || notifiedMsgIds.has(msgId)) return;
                    notifiedMsgIds.add(msgId);

                    // 初期ロード時でなければ通知を発行
                    if (lastTotalUnreadCount >= 0) {
                        hasNewIncoming = true;
                        if (shouldPlaySound) {
                            playNotificationSound();
                        }

                        const sName = msg.user_name || '受講生';
                        let previewText = msg.message_text || '';
                        if (msg.message_type === 'image') {
                            previewText = '📷 [画像を受信しました]';
                        } else if (msg.message_type === 'sticker') {
                            previewText = '🎨 [スタンプを受信しました]';
                        } else if (msg.message_type === 'video') {
                            previewText = '🎬 [動画を受信しました]';
                        } else if (msg.message_type === 'audio') {
                            previewText = '🎵 [音声を受信しました]';
                        }

                        // デスクトップ通知 (フォアグラウンド表示)
                        if (isNotifEnabled) {
                            try {
                                const notif = new Notification(`💬【新着LINE】${sName} 様`, {
                                    body: previewText,
                                    icon: msg.picture_url || 'https://scdn.line-apps.com/n/channel_devcenter/img/fx/linecorp_code_withborder.png',
                                    tag: `line_msg_${msgId}`,
                                    requireInteraction: false
                                });
                                notif.onclick = function () {
                                    window.focus();
                                    if (msg.user_id) {
                                        openChatModalByUid(msg.user_id);
                                    }
                                    notif.close();
                                };
                            } catch (e) {}
                        }

                        // 画面内トースト通知
                        showToast(`💬 ${escapeHtml(sName)} 様から新着メッセージ: ${escapeHtml(previewText)}`);
                    }
                });
            } else if (totalUnread > lastTotalUnreadCount && lastTotalUnreadCount >= 0) {
                hasNewIncoming = true;
                const diff = totalUnread - lastTotalUnreadCount;
                playNotificationSound();
                showToast(`💬 新着LINEメッセージが ${diff}件 届きました！`);
            }

            lastTotalUnreadCount = totalUnread;

            // ドキュメントタイトルに未読件数を反映
            const baseTitle = '受講生カルテ・点検管理システム';
            document.title = (totalUnread > 0) ? `(${totalUnread}) 💬 ${baseTitle}` : baseTitle;

            state.unreadChatCounts = newCounts;
            renderTable();

            // 新着があれば顧客カルテ一覧をバックグラウンドで最新同期
            if (hasNewIncoming && typeof loadCustomers === 'function') {
                loadCustomers();
            }

            // チャットモーダルが開いている場合は最新メッセージをリロード
            if (elements.chatModal && elements.chatModal.classList.contains('active') && state.activeChatUser?.user_id) {
                loadChatMessages(state.activeChatUser.user_id, true);
            }
        }
    } catch (e) {
        console.warn('loadUnreadChatCounts error:', e);
    }
}

// ==============================================================================
// 🔔 ブラウザ WebPush 通知 & チャイム音管理システム
// ==============================================================================

// Base64URL を Uint8Array に変換 (VAPID公開鍵登録用)
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

let serviceWorkerRegistration = null;

// Service Worker の初期化 & WebPush 準備
async function initWebPushServiceWorker() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        console.log('Web Push / Service Worker is not supported in this browser.');
        return null;
    }

    try {
        // ルートまたは admin の Service Worker を登録
        const swUrl = '../sw.js?v=20260921_webpush_v1';
        serviceWorkerRegistration = await navigator.serviceWorker.register(swUrl, { scope: '../' }).catch(() => {
            return navigator.serviceWorker.register('sw.js?v=20260921_webpush_v1');
        });

        // Service Worker からのプッシュ通知クリックイベント受信
        navigator.serviceWorker.addEventListener('message', (event) => {
            if (event.data && event.data.type === 'OPEN_CHAT_BY_PUSH' && event.data.user_id) {
                openChatModalByUid(event.data.user_id);
            }
        });

        return serviceWorkerRegistration;
    } catch (e) {
        console.warn('Service Worker registration failed:', e);
        return null;
    }
}

// ブラウザ通知UI & モーダル状態の更新
async function updateBrowserNotifUi() {
    const btn = document.getElementById('btnToggleBrowserNotif');
    const icon = document.getElementById('iconBrowserNotif');
    const label = document.getElementById('labelBrowserNotif');

    const statusTitle = document.getElementById('pushStatusTitle');
    const statusDesc = document.getElementById('pushStatusDesc');
    const statusIcon = document.getElementById('pushStatusIcon');
    const statusIconWrap = document.getElementById('pushStatusIconWrap');
    const btnTogglePush = document.getElementById('btnTogglePushSubscription');
    const btnToggleLabel = document.getElementById('btnTogglePushLabel');

    const isSupported = ("Notification" in window);
    const permission = isSupported ? Notification.permission : "unsupported";

    let hasActiveSubscription = false;
    if (serviceWorkerRegistration && serviceWorkerRegistration.pushManager) {
        try {
            const sub = await serviceWorkerRegistration.pushManager.getSubscription();
            hasActiveSubscription = (sub !== null);
        } catch (e) {}
    }

    const isEnabled = isSupported && (permission === "granted") && (localStorage.getItem('kureba_browser_notif_enabled') !== 'false');

    // ツールバーボタンの更新
    if (btn) {
        if (!isSupported) {
            btn.style.display = 'none';
        } else if (isEnabled) {
            btn.classList.add('active');
            btn.style.background = '#e0f2fe';
            btn.style.borderColor = '#38bdf8';
            if (icon) {
                icon.className = 'fa-solid fa-bell-ring';
                icon.style.color = '#0284c7';
            }
            if (label) {
                label.innerHTML = 'プッシュ通知: <strong style="color:#0284c7;">ON</strong>';
            }
            btn.title = 'ブラウザプッシュ通知が稼働中です（クリックして設定・テスト）';
        } else {
            btn.classList.remove('active');
            btn.style.background = '';
            btn.style.borderColor = '';
            if (icon) {
                icon.className = 'fa-solid fa-bell';
                icon.style.color = '#64748b';
            }
            if (label) {
                label.textContent = 'プッシュ通知: OFF';
            }
            btn.title = 'ブラウザプッシュ通知はOFFです（クリックして設定・有効化）';
        }
    }

    // モーダル内のステータス表示更新
    if (statusTitle) {
        if (!isSupported) {
            statusTitle.textContent = 'お使いのブラウザはプッシュ通知非対応です';
            statusDesc.textContent = 'Chrome, Edge, Firefox, Safari(macOS) などの最新ブラウザをご利用ください';
            if (statusIconWrap) { statusIconWrap.style.background = '#f1f5f9'; statusIconWrap.style.color = '#94a3b8'; }
            if (btnTogglePush) btnTogglePush.disabled = true;
        } else if (permission === 'denied') {
            statusTitle.textContent = 'ブラウザ通知がブロックされています';
            statusDesc.textContent = 'アドレスバー左側の鍵アイコン（サイト設定）から「通知」を「許可」に変更してください';
            if (statusIconWrap) { statusIconWrap.style.background = '#fee2e2'; statusIconWrap.style.color = '#dc2626'; }
            if (statusIcon) statusIcon.className = 'fa-solid fa-ban';
            if (btnToggleLabel) btnToggleLabel.textContent = 'ブラウザ設定でブロック中';
            if (btnTogglePush) {
                btnTogglePush.disabled = true;
                btnTogglePush.style.background = '#ef4444';
            }
        } else if (isEnabled) {
            statusTitle.textContent = '✅ ブラウザプッシュ通知: 有効 (稼働中)';
            statusDesc.textContent = hasActiveSubscription 
                ? 'Service Workerバックグラウンド受信 & サウンド再生が正常に待機しています'
                : 'デスクトップ通知が許可されています（新着メッセージを画面右下にお知らせ）';
            if (statusIconWrap) { statusIconWrap.style.background = '#dcfce7'; statusIconWrap.style.color = '#15803d'; }
            if (statusIcon) statusIcon.className = 'fa-solid fa-bell-ring';
            if (btnToggleLabel) btnToggleLabel.textContent = 'プッシュ通知を無効化 (OFF)';
            if (btnTogglePush) {
                btnTogglePush.disabled = false;
                btnTogglePush.style.background = '#64748b';
            }
        } else {
            statusTitle.textContent = 'ブラウザプッシュ通知: 未設定 (OFF)';
            statusDesc.textContent = '「通知を有効化」を押すと、新着メッセージを画面右下に即座にお知らせします';
            if (statusIconWrap) { statusIconWrap.style.background = '#f1f5f9'; statusIconWrap.style.color = '#64748b'; }
            if (statusIcon) statusIcon.className = 'fa-solid fa-bell';
            if (btnToggleLabel) btnToggleLabel.textContent = '今すぐ通知を有効化 (ON)';
            if (btnTogglePush) {
                btnTogglePush.disabled = false;
                btnTogglePush.style.background = '#0284c7';
            }
        }
    }
}

// プッシュ通知設定モーダルを開く
function openBrowserNotifModal() {
    const modal = document.getElementById('browserNotifModal');
    if (modal) {
        modal.style.display = 'flex';
        updateBrowserNotifUi();
    }
}

// プッシュ通知設定モーダルを閉じる
function closeBrowserNotifModal() {
    const modal = document.getElementById('browserNotifModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// プッシュ通知購読の有効化 (Permission Request & PushManager Subscribe)
async function enableWebPushNotification() {
    if (!("Notification" in window)) {
        alert("お使いのブラウザはデスクトップ通知に対応していません。Google Chrome / Edge / Firefox等の最新版をご利用ください。");
        return;
    }

    try {
        const perm = await Notification.requestPermission();
        if (perm !== "granted") {
            alert("通知の許可が得られませんでした。ブラウザのアドレスバーの鍵アイコンから通知を「許可」に変更してください。");
            updateBrowserNotifUi();
            return;
        }

        localStorage.setItem('kureba_browser_notif_enabled', 'true');

        // Service Worker PushManager 購読
        if (serviceWorkerRegistration || ('serviceWorker' in navigator)) {
            if (!serviceWorkerRegistration) {
                serviceWorkerRegistration = await initWebPushServiceWorker();
            }

            if (serviceWorkerRegistration && serviceWorkerRegistration.pushManager) {
                // VAPID公開鍵をサーバーから取得
                const vapidRes = await fetch(`../api.php?action=get_vapid_public_key&account=${encodeURIComponent(state.activeAccount)}`);
                const vapidData = await vapidRes.json();

                if (vapidData.success && vapidData.publicKey) {
                    const convertedVapidKey = urlBase64ToUint8Array(vapidData.publicKey);
                    let subscription = await serviceWorkerRegistration.pushManager.getSubscription();
                    if (!subscription) {
                        subscription = await serviceWorkerRegistration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: convertedVapidKey
                        });
                    }

                    if (subscription) {
                        // サーバーに購読情報を保存
                        const subJson = subscription.toJSON();
                        await fetch(`../api.php?action=save_push_subscription&account=${encodeURIComponent(state.activeAccount)}`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                endpoint: subscription.endpoint,
                                keys: subJson.keys || {}
                            })
                        });
                    }
                }
            }
        }

        playNotificationSound();
        showToast("🔔 ブラウザプッシュ通知を有効にしました！");
        updateBrowserNotifUi();

        // 動作確認用バナー通知
        try {
            new Notification("🔔 LINE受講生管理システム", {
                body: "ブラウザプッシュ通知が有効化されました！受講生からメッセージが届くとここにお知らせします。",
                icon: "https://scdn.line-apps.com/n/channel_devcenter/img/fx/linecorp_code_withborder.png"
            });
        } catch (e) {}

    } catch (e) {
        console.error('WebPush enable error:', e);
        alert('プッシュ通知の登録中にエラーが発生しました: ' + e.message);
    }
}

// プッシュ通知の解除
async function disableWebPushNotification() {
    localStorage.setItem('kureba_browser_notif_enabled', 'false');

    if (serviceWorkerRegistration && serviceWorkerRegistration.pushManager) {
        try {
            const subscription = await serviceWorkerRegistration.pushManager.getSubscription();
            if (subscription) {
                await fetch(`../api.php?action=delete_push_subscription&account=${encodeURIComponent(state.activeAccount)}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ endpoint: subscription.endpoint })
                });
                await subscription.unsubscribe();
            }
        } catch (e) {
            console.warn('Unsubscribe error:', e);
        }
    }

    showToast("🔕 ブラウザプッシュ通知をOFFにしました。");
    updateBrowserNotifUi();
}

// モーダルやヘッダーからのトグル処理
async function toggleBrowserNotification() {
    const isSupported = ("Notification" in window);
    if (!isSupported) {
        alert("お使いのブラウザはプッシュ通知に対応していません。");
        return;
    }

    const permission = Notification.permission;
    const isCurrentlyEnabled = (permission === "granted") && (localStorage.getItem('kureba_browser_notif_enabled') !== 'false');

    if (isCurrentlyEnabled) {
        await disableWebPushNotification();
    } else {
        await enableWebPushNotification();
    }
}

// テストプッシュ通知の送信
async function sendTestWebPush() {
    const btn = document.getElementById('btnSendTestWebPush');
    const resultMsg = document.getElementById('pushTestResultMsg');
    const origHtml = btn ? btn.innerHTML : '';

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> テスト送信中...';
    }
    if (resultMsg) {
        resultMsg.style.display = 'block';
        resultMsg.style.color = '#0284c7';
        resultMsg.textContent = 'サーバーからプッシュ通知を送信しています...';
    }

    try {
        playNotificationSound();

        const res = await fetch(`../api.php?action=test_web_push&account=${encodeURIComponent(state.activeAccount)}`, {
            method: 'POST'
        });
        const data = await res.json();

        if (data.success) {
            if (resultMsg) {
                resultMsg.style.color = '#15803d';
                resultMsg.textContent = `✅ ${data.message || 'テストプッシュ通知を正常に送信しました！'}`;
            }
            showToast('🎉 テストプッシュ通知を送信しました！');
        } else {
            // ローカルフォールバック通知を発行
            try {
                new Notification("🔔 【テスト通知】ブラウザプッシュ通知", {
                    body: "デスクトップ通知は正常に機能しています！（Service Worker登録もお試しください）",
                    icon: "https://scdn.line-apps.com/n/channel_devcenter/img/fx/linecorp_code_withborder.png"
                });
            } catch (ne) {}

            if (resultMsg) {
                resultMsg.style.color = '#d97706';
                resultMsg.textContent = `ℹ️ ${data.message || 'デスクトップ通知を表示しました'}`;
            }
        }
    } catch (e) {
        console.error('Test push error:', e);
        if (resultMsg) {
            resultMsg.style.color = '#dc2626';
            resultMsg.textContent = `❌ エラー: ${e.message}`;
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }
}

// 初期化リスナー
function initBrowserNotifControls() {
    const btnHdr = document.getElementById('btnToggleBrowserNotif');
    const btnClose = document.getElementById('closeBrowserNotifModalBtn');
    const btnCloseFooter = document.getElementById('closeBrowserNotifModalFooterBtn');
    const btnTogglePush = document.getElementById('btnTogglePushSubscription');
    const btnTestSound = document.getElementById('btnTestSoundOnly');
    const btnTestPush = document.getElementById('btnSendTestWebPush');

    if (btnHdr) {
        btnHdr.addEventListener('click', () => {
            openBrowserNotifModal();
        });
    }

    if (btnClose) btnClose.addEventListener('click', closeBrowserNotifModal);
    if (btnCloseFooter) btnCloseFooter.addEventListener('click', closeBrowserNotifModal);

    if (btnTogglePush) {
        btnTogglePush.addEventListener('click', () => {
            toggleBrowserNotification();
        });
    }

    if (btnTestSound) {
        btnTestSound.addEventListener('click', () => {
            playNotificationSound();
            showToast('🎵 チャイム音を再生しました');
        });
    }

    if (btnTestPush) {
        btnTestPush.addEventListener('click', () => {
            sendTestWebPush();
        });
    }

    // URLパラメータから `open_chat` が指定されている場合は自動でチャットモーダルを開く
    const urlParams = new URLSearchParams(window.location.search);
    const openChatUid = urlParams.get('open_chat');
    if (openChatUid) {
        setTimeout(() => {
            openChatModalByUid(openChatUid);
        }, 800);
    }
}

window.toggleBrowserNotification = toggleBrowserNotification;
window.enableWebPushNotification = enableWebPushNotification;
window.disableWebPushNotification = disableWebPushNotification;
window.openBrowserNotifModal = openBrowserNotifModal;
window.closeBrowserNotifModal = closeBrowserNotifModal;
window.sendTestWebPush = sendTestWebPush;
window.updateBrowserNotifUi = updateBrowserNotifUi;

function checkUrlChatParam() {
    const params = new URLSearchParams(window.location.search);
    const chatUid = params.get('chat_uid');
    if (!chatUid) return;

    const url = new URL(window.location);
    url.searchParams.delete('chat_uid');
    window.history.replaceState({}, '', url.pathname + url.search);

    const cust = state.allCustomers.find(c => c.user_id === chatUid);
    if (cust) {
        openChatModal(cust);
    } else {
        openChatModal({
            user_id: chatUid,
            user_name: 'LINE受講生',
            car_model: '未登録または連携待ち'
        });
    }
}

/* ==========================================================================
   Discord通知設定機能
   ========================================================================== */

function initDiscordSettings() {
    if (elements.openDiscordSettingsBtn) {
        elements.openDiscordSettingsBtn.addEventListener('click', openDiscordSettings);
    }
    if (elements.btnCloseDiscordModal) {
        elements.btnCloseDiscordModal.addEventListener('click', closeDiscordSettings);
    }
    if (elements.btnCancelDiscordModal) {
        elements.btnCancelDiscordModal.addEventListener('click', closeDiscordSettings);
    }
    if (elements.btnSaveDiscordSettings) {
        elements.btnSaveDiscordSettings.addEventListener('click', saveDiscordSettings);
    }
    if (elements.btnTestDiscordWebhook) {
        elements.btnTestDiscordWebhook.addEventListener('click', testDiscordNotification);
    }

    if (elements.discordSettingsModal) {
        elements.discordSettingsModal.addEventListener('click', (e) => {
            if (e.target === elements.discordSettingsModal) {
                closeDiscordSettings();
            }
        });
    }
}

async function openDiscordSettings() {
    if (elements.discordTestStatusBanner) {
        elements.discordTestStatusBanner.style.display = 'none';
    }

    if (elements.discordSettingsModal) {
        elements.discordSettingsModal.classList.add('active');
    }

    try {
        const res = await fetch(`../api.php?action=get_discord_settings`);
        const data = await res.json();

        if (data.success && data.settings) {
            const s = data.settings;
            if (elements.discordWebhookUrlInput) elements.discordWebhookUrlInput.value = s.webhook_url || '';
            if (elements.discordNotifyMessage) elements.discordNotifyMessage.checked = (s.notify_message !== false);
            if (elements.discordNotifyFollow) elements.discordNotifyFollow.checked = (s.notify_follow !== false);
            if (elements.discordNotifyConsultation) elements.discordNotifyConsultation.checked = (s.notify_consultation !== false);
        }
    } catch (e) {
        console.error('Failed to load discord settings:', e);
    }
}

function closeDiscordSettings() {
    if (elements.discordSettingsModal) {
        elements.discordSettingsModal.classList.remove('active');
    }
}

async function saveDiscordSettings() {
    const webhookUrl = elements.discordWebhookUrlInput ? elements.discordWebhookUrlInput.value.trim() : '';
    const notifyMessage = elements.discordNotifyMessage ? elements.discordNotifyMessage.checked : true;
    const notifyFollow = elements.discordNotifyFollow ? elements.discordNotifyFollow.checked : true;
    const notifyConsultation = elements.discordNotifyConsultation ? elements.discordNotifyConsultation.checked : true;

    const btn = elements.btnSaveDiscordSettings;
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 保存中...';
    }

    try {
        const payload = new URLSearchParams({
            action: 'save_discord_settings',
            password: state.password,
            webhook_url: webhookUrl,
            notify_message: notifyMessage ? '1' : '0',
            notify_follow: notifyFollow ? '1' : '0',
            notify_consultation: notifyConsultation ? '1' : '0'
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success) {
            showToast('Discord通知設定を保存しました！');
            closeDiscordSettings();
        } else {
            alert(data.error || '設定の保存に失敗しました');
        }
    } catch (e) {
        console.error('Save discord settings error:', e);
        alert('保存エラーが発生しました: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }
}

async function testDiscordNotification() {
    const webhookUrl = elements.discordWebhookUrlInput ? elements.discordWebhookUrlInput.value.trim() : '';
    if (!webhookUrl) {
        alert('テスト送信を行うには、Discord Webhook URL を入力してください。');
        elements.discordWebhookUrlInput?.focus();
        return;
    }

    const banner = elements.discordTestStatusBanner;
    if (banner) {
        banner.style.display = 'block';
        banner.style.background = '#e0f2fe';
        banner.style.color = '#0284c7';
        banner.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Discordへテスト通知を送信中...';
    }

    const testBtn = elements.btnTestDiscordWebhook;
    if (testBtn) testBtn.disabled = true;

    try {
        const payload = new URLSearchParams({
            action: 'test_discord_notification',
            password: state.password,
            webhook_url: webhookUrl
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success) {
            if (banner) {
                banner.style.background = '#dcfce7';
                banner.style.color = '#166534';
                banner.innerHTML = '<i class="fa-solid fa-circle-check"></i> Discordへのテスト送信に成功しました！チャンネルをご確認ください。';
            }
        } else {
            if (banner) {
                banner.style.background = '#fee2e2';
                banner.style.color = '#991b1b';
                banner.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> テスト送信失敗: ${escapeHtml(data.error || '不明なエラー')}`;
            }
        }
    } catch (e) {
        if (banner) {
            banner.style.background = '#fee2e2';
            banner.style.color = '#991b1b';
            banner.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> 通信エラー: ${escapeHtml(e.message)}`;
        }
    } finally {
        if (testBtn) testBtn.disabled = false;
    }
}

/* ==========================================================================
   Slack通知設定機能
   ========================================================================== */

function initSlackSettings() {
    if (elements.openSlackSettingsBtn) {
        elements.openSlackSettingsBtn.addEventListener('click', openSlackSettings);
    }
    if (elements.btnCloseSlackModal) {
        elements.btnCloseSlackModal.addEventListener('click', closeSlackSettings);
    }
    if (elements.btnCancelSlackModal) {
        elements.btnCancelSlackModal.addEventListener('click', closeSlackSettings);
    }
    if (elements.btnSaveSlackSettings) {
        elements.btnSaveSlackSettings.addEventListener('click', saveSlackSettings);
    }
    if (elements.btnTestSlackWebhook) {
        elements.btnTestSlackWebhook.addEventListener('click', testSlackNotification);
    }

    if (elements.slackSettingsModal) {
        elements.slackSettingsModal.addEventListener('click', (e) => {
            if (e.target === elements.slackSettingsModal) {
                closeSlackSettings();
            }
        });
    }
}

async function openSlackSettings() {
    if (elements.slackTestStatusBanner) {
        elements.slackTestStatusBanner.style.display = 'none';
    }

    if (elements.slackSettingsModal) {
        elements.slackSettingsModal.classList.add('active');
    }

    try {
        const res = await fetch(`../api.php?action=get_slack_settings&password=${encodeURIComponent(state.password || '')}`);
        const data = await res.json();

        if (data.success && data.settings) {
            const s = data.settings;
            if (elements.slackWebhookUrlInput) elements.slackWebhookUrlInput.value = s.webhook_url || '';
            if (elements.slackNotifyMessage) elements.slackNotifyMessage.checked = (s.notify_message !== false);
            if (elements.slackNotifyFollow) elements.slackNotifyFollow.checked = (s.notify_follow !== false);
            if (elements.slackNotifyConsultation) elements.slackNotifyConsultation.checked = (s.notify_consultation !== false);
        }
    } catch (e) {
        console.error('Failed to load slack settings:', e);
    }
}

function closeSlackSettings() {
    if (elements.slackSettingsModal) {
        elements.slackSettingsModal.classList.remove('active');
    }
}

async function saveSlackSettings() {
    const webhookUrl = elements.slackWebhookUrlInput ? elements.slackWebhookUrlInput.value.trim() : '';
    const notifyMessage = elements.slackNotifyMessage ? elements.slackNotifyMessage.checked : true;
    const notifyFollow = elements.slackNotifyFollow ? elements.slackNotifyFollow.checked : true;
    const notifyConsultation = elements.slackNotifyConsultation ? elements.slackNotifyConsultation.checked : true;

    const btn = elements.btnSaveSlackSettings;
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 保存中...';
    }

    try {
        const payload = new URLSearchParams({
            action: 'save_slack_settings',
            password: state.password,
            webhook_url: webhookUrl,
            notify_message: notifyMessage ? '1' : '0',
            notify_follow: notifyFollow ? '1' : '0',
            notify_consultation: notifyConsultation ? '1' : '0'
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success) {
            showToast('Slack通知設定を保存しました！');
            closeSlackSettings();
        } else {
            alert(data.error || '設定の保存に失敗しました');
        }
    } catch (e) {
        console.error('Save slack settings error:', e);
        alert('保存エラーが発生しました: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }
}

async function testSlackNotification() {
    const webhookUrl = elements.slackWebhookUrlInput ? elements.slackWebhookUrlInput.value.trim() : '';
    if (!webhookUrl) {
        alert('テスト送信を行うには、Slack Incoming Webhook URL を入力してください。');
        elements.slackWebhookUrlInput?.focus();
        return;
    }

    const banner = elements.slackTestStatusBanner;
    if (banner) {
        banner.style.display = 'block';
        banner.style.background = '#e0f2fe';
        banner.style.color = '#0284c7';
        banner.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Slackへテスト通知を送信中...';
    }

    const testBtn = elements.btnTestSlackWebhook;
    if (testBtn) testBtn.disabled = true;

    try {
        const payload = new URLSearchParams({
            action: 'test_slack_notification',
            password: state.password,
            webhook_url: webhookUrl
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success) {
            if (banner) {
                banner.style.background = '#dcfce7';
                banner.style.color = '#166534';
                banner.innerHTML = '<i class="fa-solid fa-circle-check"></i> Slackへのテスト送信に成功しました！チャンネルをご確認ください。';
            }
        } else {
            if (banner) {
                banner.style.background = '#fee2e2';
                banner.style.color = '#991b1b';
                banner.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> テスト送信失敗: ${escapeHtml(data.error || '不明なエラー')}`;
            }
        }
    } catch (e) {
        if (banner) {
            banner.style.background = '#fee2e2';
            banner.style.color = '#991b1b';
            banner.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> 通信エラー: ${escapeHtml(e.message)}`;
        }
    } finally {
        if (testBtn) testBtn.disabled = false;
    }
}

/* ==========================================================================
   メール二段階認証 (2FA) セキュリティ設定機能
   ========================================================================== */

function init2FASettings() {
    if (elements.open2FASettingsBtn) {
        elements.open2FASettingsBtn.addEventListener('click', open2FASettingsModal);
    }
    if (elements.btnClose2FASettingsModal) {
        elements.btnClose2FASettingsModal.addEventListener('click', close2FASettingsModal);
    }
    if (elements.btnCancel2FAModal) {
        elements.btnCancel2FAModal.addEventListener('click', close2FASettingsModal);
    }
    if (elements.btnSave2FASettings) {
        elements.btnSave2FASettings.addEventListener('click', save2FASettings);
    }
    if (elements.btnTest2FAEmail) {
        elements.btnTest2FAEmail.addEventListener('click', test2FAEmail);
    }
    if (elements.twoFaEnabledToggle) {
        elements.twoFaEnabledToggle.addEventListener('change', (e) => {
            update2FAToggleVisual(e.target.checked);
        });
    }
}

function update2FAToggleVisual(isEnabled) {
    const card = document.getElementById('twoFaStatusCard');
    const title = document.getElementById('twoFaStatusTitle');
    const subtitle = document.getElementById('twoFaStatusSubtitle');

    if (card) {
        if (isEnabled) {
            card.classList.remove('is-disabled');
            card.classList.add('is-enabled');
            if (title) title.innerHTML = '<i class="fa-solid fa-lock" style="color: #16a34a;"></i> メール二段階認証：<span style="color: #16a34a;">有効</span>';
            if (subtitle) {
                subtitle.style.color = '#15803d';
                subtitle.textContent = 'ログイン時に登録メールアドレス宛へ6桁の認証コードを送信します（推奨）';
            }
        } else {
            card.classList.remove('is-enabled');
            card.classList.add('is-disabled');
            if (title) title.innerHTML = '<i class="fa-solid fa-lock-open" style="color: #64748b;"></i> メール二段階認証：<span style="color: #64748b;">無効</span>';
            if (subtitle) {
                subtitle.style.color = '#64748b';
                subtitle.textContent = '二段階認証は行われず、管理者パスワードのみでログインします';
            }
        }
    }
}

async function open2FASettingsModal() {
    if (elements.twoFaStatusBanner) {
        elements.twoFaStatusBanner.style.display = 'none';
    }

    if (elements.twoFaSettingsModal) {
        elements.twoFaSettingsModal.classList.add('active');
        elements.twoFaSettingsModal.style.display = 'flex';
    }

    try {
        const res = await fetch(`../api.php?action=get_2fa_settings&password=${encodeURIComponent(state.password || '')}`);
        const data = await res.json();

        if (data.success && data.settings) {
            const s = data.settings;
            const isEnabled = !!s.enabled;
            if (elements.twoFaEnabledToggle) {
                elements.twoFaEnabledToggle.checked = isEnabled;
                update2FAToggleVisual(isEnabled);
            }
            if (elements.twoFaEmailInput) elements.twoFaEmailInput.value = s.email || 'kawai@kureba.co.jp';
            if (elements.twoFaLifetimeInput) elements.twoFaLifetimeInput.value = String(s.lifetime_minutes || 10);
            if (elements.twoFaMaxAttemptsInput) elements.twoFaMaxAttemptsInput.value = String(s.max_attempts || 5);
        }
    } catch (e) {
        console.error('Failed to load 2FA settings:', e);
    }
}

function close2FASettingsModal() {
    if (elements.twoFaSettingsModal) {
        elements.twoFaSettingsModal.classList.remove('active');
        elements.twoFaSettingsModal.style.display = 'none';
    }
}

async function save2FASettings() {
    const enabled = elements.twoFaEnabledToggle ? elements.twoFaEnabledToggle.checked : false;
    const email = elements.twoFaEmailInput ? elements.twoFaEmailInput.value.trim() : '';
    const lifetime = elements.twoFaLifetimeInput ? elements.twoFaLifetimeInput.value : '10';
    const maxAttempts = elements.twoFaMaxAttemptsInput ? elements.twoFaMaxAttemptsInput.value : '5';

    if (!email) {
        alert('認証コード送信先のメールアドレスを入力してください。');
        elements.twoFaEmailInput?.focus();
        return;
    }

    const btn = elements.btnSave2FASettings;
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 保存中...';
    }

    try {
        const payload = new URLSearchParams({
            action: 'save_2fa_settings',
            password: state.password,
            enabled: enabled ? '1' : '0',
            email: email,
            lifetime_minutes: lifetime,
            max_attempts: maxAttempts
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success) {
            showToast('メール二段階認証設定を保存しました！');
            close2FASettingsModal();
        } else {
            alert(data.error || '設定の保存に失敗しました');
        }
    } catch (e) {
        console.error('Save 2FA settings error:', e);
        alert('保存エラーが発生しました: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }
}

async function test2FAEmail() {
    const email = elements.twoFaEmailInput ? elements.twoFaEmailInput.value.trim() : '';
    if (!email) {
        alert('テスト送信を行う送信先メールアドレスを入力してください。');
        elements.twoFaEmailInput?.focus();
        return;
    }

    const banner = elements.twoFaStatusBanner;
    if (banner) {
        banner.style.display = 'block';
        banner.style.background = '#e0f2fe';
        banner.style.color = '#0284c7';
        banner.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 認証コードテストメールを送信中...';
    }

    const testBtn = elements.btnTest2FAEmail;
    if (testBtn) testBtn.disabled = true;

    try {
        const payload = new URLSearchParams({
            action: 'test_2fa_email',
            password: state.password,
            email: email
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success) {
            if (banner) {
                banner.style.background = '#dcfce7';
                banner.style.color = '#166534';
                banner.innerHTML = `<i class="fa-solid fa-circle-check"></i> ${escapeHtml(data.message || 'テストメールを送信しました！受信ボックスをご確認ください。')}`;
            }
        } else {
            if (banner) {
                banner.style.background = '#fee2e2';
                banner.style.color = '#991b1b';
                banner.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> 送信失敗: ${escapeHtml(data.error || data.message || 'メール送信に失敗しました')}`;
            }
        }
    } catch (e) {
        if (banner) {
            banner.style.background = '#fee2e2';
            banner.style.color = '#991b1b';
            banner.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> 通信エラー: ${escapeHtml(e.message)}`;
        }
    } finally {
        if (testBtn) testBtn.disabled = false;
    }
}

window.open2FASettingsModal = open2FASettingsModal;
window.close2FASettingsModal = close2FASettingsModal;
window.openChatModal = openChatModal;
window.closeChatModal = closeChatModal;
window.openDiscordSettings = openDiscordSettings;
window.closeDiscordSettings = closeDiscordSettings;
window.openSlackSettings = openSlackSettings;
window.closeSlackSettings = closeSlackSettings;
window.openChatModalByUid = function(uid) {
    if (!uid) return;
    let cust = null;
    if (state.customers && Array.isArray(state.customers)) {
        cust = state.customers.find(c => c.user_id === uid);
    }
    if (cust) {
        openChatModal(cust);
    } else {
        openChatModal({
            user_id: uid,
            user_name: 'LINE受講生',
            car_model: '未登録または連携待ち'
        });
    }
};

/* ==========================================================================
   クイックリプライ（画面下部ボタン）設定機能
   ========================================================================== */

let qrState = {
    account: '',
    enabled: false,
    mode: 'none',
    custom_items: []
};

function initQuickReplySettings() {
    const btnOpen = document.getElementById('openQuickReplySettingsBtn') || document.getElementById('btnOpenQuickReplySettingsModal');
    const modal = document.getElementById('quickReplySettingsModal');
    const btnClose = document.getElementById('btnCloseQuickReplyModal');
    const btnCancel = document.getElementById('btnCancelQuickReplyModal');
    const toggle = document.getElementById('qrEnabledToggle');
    const btnSave = document.getElementById('btnSaveQuickReplySettings');
    const btnAddItem = document.getElementById('btnAddQrCustomItem');
    const accSelect = document.getElementById('qrAccountSelect');
    const modeRadios = document.querySelectorAll('input[name="qrMode"]');

    if (btnOpen) {
        btnOpen.addEventListener('click', () => openQuickReplySettingsModal());
    }
    if (btnClose) {
        btnClose.addEventListener('click', closeQuickReplySettingsModal);
    }
    if (btnCancel) {
        btnCancel.addEventListener('click', closeQuickReplySettingsModal);
    }
    if (toggle) {
        toggle.addEventListener('change', (e) => {
            qrState.enabled = e.target.checked;
            updateQuickReplyVisual(qrState.enabled);
            renderQuickReplyLivePreview();
        });
    }
    if (accSelect) {
        accSelect.addEventListener('change', (e) => {
            loadQuickReplySettings(e.target.value);
        });
    }
    modeRadios.forEach(r => {
        r.addEventListener('change', (e) => {
            if (e.target.checked) {
                qrState.mode = e.target.value;
                const editorWrap = document.getElementById('qrCustomEditorWrap');
                if (editorWrap) editorWrap.style.display = (qrState.mode === 'custom') ? 'block' : 'none';
                renderQuickReplyLivePreview();
            }
        });
    });
    if (btnAddItem) {
        btnAddItem.addEventListener('click', () => {
            if (qrState.custom_items.length >= 13) {
                alert('クイックリプライボタンは最大13個までです。');
                return;
            }
            qrState.custom_items.push({
                label: `ボタン ${qrState.custom_items.length + 1}`,
                action_type: 'postback',
                data: 'action=open_mycar',
                uri: '',
                text: ''
            });
            renderQuickReplyCustomItems();
            renderQuickReplyLivePreview();
        });
    }
    if (btnSave) {
        btnSave.addEventListener('click', saveQuickReplySettings);
    }
}

function updateQuickReplyVisual(isEnabled) {
    const card = document.getElementById('qrStatusCard');
    const title = document.getElementById('qrStatusTitle');
    const subtitle = document.getElementById('qrStatusSubtitle');

    if (card) {
        if (isEnabled) {
            card.classList.remove('is-disabled');
            card.classList.add('is-enabled');
            if (title) title.innerHTML = '<i class="fa-solid fa-circle-check" style="color: #16a34a;"></i> クイックリプライ自動付与：<span style="color: #16a34a;">有効</span>';
            if (subtitle) {
                subtitle.style.color = '#15803d';
                subtitle.textContent = 'LINEメッセージ送信時に画面下部に選択肢ボタンを表示します';
            }
        } else {
            card.classList.remove('is-enabled');
            card.classList.add('is-disabled');
            if (title) title.innerHTML = '<i class="fa-solid fa-circle-xmark" style="color: #64748b;"></i> クイックリプライ自動付与：<span style="color: #64748b;">無効（オフ）</span>';
            if (subtitle) {
                subtitle.style.color = '#64748b';
                subtitle.textContent = 'メッセージ送信時にクイックリプライボタンを一切付けません（他社利用時推奨）';
            }
        }
    }
}

async function openQuickReplySettingsModal(targetAcc) {
    const modal = document.getElementById('quickReplySettingsModal');
    if (!modal) return;

    modal.classList.add('active');
    modal.style.display = 'flex';
    modal.style.zIndex = '9999';

    const accSelect = document.getElementById('qrAccountSelect');
    if (accSelect) {
        accSelect.innerHTML = '';
        const accounts = (state && state.accounts && state.accounts.length) ? state.accounts : [
            { id: 'senior', name: 'シニア向けパソコン教室' }
        ];
        accounts.forEach(acc => {
            const opt = document.createElement('option');
            opt.value = acc.id;
            opt.textContent = `${acc.name} (${acc.id})`;
            accSelect.appendChild(opt);
        });
        const currentTarget = targetAcc || state.activeAccount || 'senior';
        accSelect.value = currentTarget;
    }

    const currentAcc = (accSelect && accSelect.value) ? accSelect.value : (targetAcc || state.activeAccount || 'senior');
    await loadQuickReplySettings(currentAcc);
}

function closeQuickReplySettingsModal() {
    const modal = document.getElementById('quickReplySettingsModal');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

window.openQuickReplySettings = openQuickReplySettingsModal;
window.openQuickReplySettingsModal = openQuickReplySettingsModal;
window.closeQuickReplySettings = closeQuickReplySettingsModal;

async function loadQuickReplySettings(accountKey) {
    qrState.account = accountKey || state.activeAccount || 'senior';

    try {
        const res = await fetch(`../api.php?action=get_quick_reply_settings&account=${encodeURIComponent(qrState.account)}&password=${encodeURIComponent(state.password || '')}`);
        const data = await res.json();

        if (data.success && data.settings) {
            const s = data.settings;
            qrState.enabled = !!s.enabled;
            qrState.mode = s.mode || (data.industry_type === 'senior' ? 'senior_knowledge' : 'none');
            qrState.custom_items = Array.isArray(s.custom_items) ? s.custom_items : [];

            const toggle = document.getElementById('qrEnabledToggle');
            if (toggle) toggle.checked = qrState.enabled;
            updateQuickReplyVisual(qrState.enabled);

            const modeRadio = document.querySelector(`input[name="qrMode"][value="${qrState.mode}"]`);
            if (modeRadio) {
                modeRadio.checked = true;
            } else {
                const defaultRadio = document.querySelector('input[name="qrMode"][value="none"]');
                if (defaultRadio) defaultRadio.checked = true;
            }

            const editorWrap = document.getElementById('qrCustomEditorWrap');
            if (editorWrap) editorWrap.style.display = (qrState.mode === 'custom') ? 'block' : 'none';

            renderQuickReplyCustomItems();
            renderQuickReplyLivePreview();
        }
    } catch (e) {
        console.error('Failed to load Quick Reply settings:', e);
    }
}

function renderQuickReplyCustomItems() {
    const container = document.getElementById('qrCustomItemsList');
    if (!container) return;
    container.innerHTML = '';

    if (!qrState.custom_items.length) {
        container.innerHTML = '<div style="font-size: 11.5px; color: #94a3b8; text-align: center; padding: 10px;">「ボタン追加」を押してカスタムボタンを作成してください</div>';
        return;
    }

    qrState.custom_items.forEach((item, idx) => {
        const row = document.createElement('div');
        row.style.cssText = 'display: flex; align-items: center; gap: 8px; background: #fff; padding: 8px 10px; border-radius: var(--radius-xs); border: 1px solid #e2e8f0;';
        
        row.innerHTML = `
            <span style="font-size: 11px; font-weight: 800; color: #64748b; width: 20px;">#${idx + 1}</span>
            <input type="text" placeholder="ボタン名（例: 📋 カルテ）" value="${escapeHtml(item.label || '')}" maxlength="20" style="flex: 1.2; padding: 5px 8px; font-size: 12px; font-weight: bold; border: 1px solid #cbd5e1; border-radius: var(--radius-xs);" oninput="qrState.custom_items[${idx}].label = this.value; renderQuickReplyLivePreview();">
            <select style="flex: 1; padding: 5px 6px; font-size: 11.5px; border: 1px solid #cbd5e1; border-radius: var(--radius-xs); background: #fff;" onchange="qrState.custom_items[${idx}].action_type = this.value; renderQuickReplyCustomItems(); renderQuickReplyLivePreview();">
                <option value="postback" ${item.action_type === 'postback' ? 'selected' : ''}>カルテ/予約 (Postback)</option>
                <option value="uri" ${item.action_type === 'uri' ? 'selected' : ''}>URLを開く (URI)</option>
                <option value="message" ${item.action_type === 'message' ? 'selected' : ''}>メッセージ送信</option>
            </select>
            ${item.action_type === 'uri' ? `
                <input type="text" placeholder="https://..." value="${escapeHtml(item.uri || '')}" style="flex: 1.5; padding: 5px 8px; font-size: 11.5px; border: 1px solid #cbd5e1; border-radius: var(--radius-xs);" oninput="qrState.custom_items[${idx}].uri = this.value; renderQuickReplyLivePreview();">
            ` : (item.action_type === 'message' ? `
                <input type="text" placeholder="送信テキスト" value="${escapeHtml(item.text || '')}" style="flex: 1.5; padding: 5px 8px; font-size: 11.5px; border: 1px solid #cbd5e1; border-radius: var(--radius-xs);" oninput="qrState.custom_items[${idx}].text = this.value; renderQuickReplyLivePreview();">
            ` : `
                <input type="text" placeholder="action=open_mycar" value="${escapeHtml(item.data || '')}" style="flex: 1.5; padding: 5px 8px; font-size: 11.5px; font-family: monospace; border: 1px solid #cbd5e1; border-radius: var(--radius-xs);" oninput="qrState.custom_items[${idx}].data = this.value; renderQuickReplyLivePreview();">
            `)}
            <button type="button" style="background: #fee2e2; border: 1px solid #fca5a5; color: #dc2626; padding: 5px 8px; border-radius: var(--radius-xs); cursor: pointer; font-size: 11px;" onclick="qrState.custom_items.splice(${idx}, 1); renderQuickReplyCustomItems(); renderQuickReplyLivePreview();">
                <i class="fa-solid fa-trash-can"></i>
            </button>
        `;
        container.appendChild(row);
    });
}

function renderQuickReplyLivePreview() {
    const container = document.getElementById('qrLivePreviewContainer');
    if (!container) return;
    container.innerHTML = '';

    if (!qrState.enabled || qrState.mode === 'none') {
        container.innerHTML = '<span style="font-size: 11.5px; color: #94a3b8; padding: 4px;">（クイックリプライは無効です。メッセージにボタンは付与されません）</span>';
        return;
    }

    let buttons = [];

    if (qrState.mode === 'senior_knowledge') {
        buttons = [
            '📄 偽PDF詐欺', '🚨 偽警告対策', '📞 偽電話サポート', '⚠️ 偽SMS対策',
            '👤 LINE乗っ取り', '📱 文字拡大', '💬 LINE特大', '🗣️ 音声入力',
            '🔍 画面拡大', '⚡ PC再起動', '🔢 数字打てない', '💬 教室に質問・相談', '📚 全20テーマ一覧'
        ];
    } else if (qrState.mode === 'industry_preset') {
        buttons = ['🚗 マイカルテ/会員証', '💬 質問・相談', '📅 WEB予約'];
    } else if (qrState.mode === 'custom') {
        buttons = qrState.custom_items.map(it => it.label || 'ボタン');
        if (!buttons.length) {
            container.innerHTML = '<span style="font-size: 11.5px; color: #94a3b8; padding: 4px;">（カスタムボタンが未登録です）</span>';
            return;
        }
    }

    buttons.forEach(btnText => {
        const pill = document.createElement('div');
        pill.style.cssText = 'white-space: nowrap; background: #ffffff; border: 1px solid #06C755; color: #166534; font-size: 11.5px; font-weight: 700; padding: 6px 14px; border-radius: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px;';
        pill.innerHTML = escapeHtml(btnText);
        container.appendChild(pill);
    });
}

async function saveQuickReplySettings() {
    const btn = document.getElementById('btnSaveQuickReplySettings');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 保存中...';
    }

    try {
        const payload = new URLSearchParams({
            action: 'save_quick_reply_settings',
            password: state.password,
            account: qrState.account || state.activeAccount || 'senior',
            enabled: qrState.enabled ? '1' : '0',
            mode: qrState.mode || 'none',
            custom_items: JSON.stringify(qrState.custom_items || [])
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success) {
            if (typeof showToast === 'function') {
                showToast(`クイックリプライ設定を保存しました（${qrState.enabled ? '有効' : '完全無効'}）`, 'success');
            } else {
                alert('クイックリプライ設定を保存しました！');
            }
            closeQuickReplySettingsModal();
        } else {
            alert(data.error || '保存に失敗しました');
        }
    } catch (e) {
        console.error('Save Quick Reply settings error:', e);
        alert('保存エラーが発生しました: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }
}

window.openQuickReplySettings = openQuickReplySettingsModal;
window.closeQuickReplySettings = closeQuickReplySettingsModal;
window.qrState = qrState;
window.renderQuickReplyCustomItems = renderQuickReplyCustomItems;
window.renderQuickReplyLivePreview = renderQuickReplyLivePreview;

// ==============================================================================
// 属性タグ機能 (Tag System) & 一括操作 ロジック
// ==============================================================================

function renderTagFilterOptions() {
    if (!elements.adminTagFilterSelect) return;
    const current = state.currentTagFilter;
    const allTags = state.allTags || [];

    let optionsHtml = '<option value="">すべてのタグ (全件)</option>';
    allTags.forEach(t => {
        const isSel = (t.name === current);
        optionsHtml += `<option value="${escapeHtml(t.name)}" ${isSel ? 'selected' : ''}>#${escapeHtml(t.name)} (${t.count || 0})</option>`;
    });

    elements.adminTagFilterSelect.innerHTML = optionsHtml;
}

function filterByTag(tagName) {
    if (!tagName) return;
    state.currentTagFilter = tagName;
    if (elements.adminTagFilterSelect) {
        elements.adminTagFilterSelect.value = tagName;
    }
    state.currentPage = 1;
    renderTable();
    showToast(`🏷️ タグ「#${tagName}」で絞り込みました`);
}

function toggleCustomerSelection(id, isChecked) {
    id = parseInt(id, 10);
    if (isChecked) {
        state.selectedCustomerIds.add(id);
    } else {
        state.selectedCustomerIds.delete(id);
    }
    updateBulkActionBar();
}

function toggleSelectAllCustomers(isChecked) {
    const list = state.currentFilteredList || state.allCustomers || [];
    if (isChecked) {
        list.forEach(c => {
            if (c.id) state.selectedCustomerIds.add(c.id);
        });
    } else {
        state.selectedCustomerIds.clear();
    }
    renderTable();
    updateBulkActionBar();
}

function clearCustomerSelection() {
    state.selectedCustomerIds.clear();
    if (elements.selectAllCheckbox) {
        elements.selectAllCheckbox.checked = false;
    }
    renderTable();
    updateBulkActionBar();
}

function updateBulkActionBar() {
    const count = state.selectedCustomerIds.size;
    if (elements.bulkSelectedCount) {
        elements.bulkSelectedCount.textContent = count;
    }
    if (elements.bulkActionBar) {
        elements.bulkActionBar.style.display = count > 0 ? 'flex' : 'none';
    }
    if (elements.selectAllCheckbox) {
        const total = (state.currentFilteredList || []).length;
        elements.selectAllCheckbox.checked = (total > 0 && count >= total);
    }
}

function openBulkTagModal(mode = 'add') {
    if (state.selectedCustomerIds.size === 0) {
        alert('受講生が選択されていません。');
        return;
    }
    state.bulkTagMode = mode;
    const count = state.selectedCustomerIds.size;

    if (elements.bulkTagModalTitle) {
        elements.bulkTagModalTitle.innerHTML = mode === 'add'
            ? `<i class="fa-solid fa-tag" style="color: #6366f1;"></i> ${count}名へタグを一括追加`
            : `<i class="fa-solid fa-tags" style="color: #ef4444;"></i> ${count}名からタグを一括解除`;
    }
    if (elements.bulkTagModalDesc) {
        elements.bulkTagModalDesc.textContent = mode === 'add'
            ? `選択された ${count} 名の受講生に新しい属性タグを追加し、タグ連動リッチメニューがあれば即座に自動反映します。`
            : `選択された ${count} 名の受講生から指定のタグを解除し、リッチメニューを最新状態へ更新します。`;
    }
    if (elements.bulkTagInput) {
        elements.bulkTagInput.value = '';
    }
    if (elements.bulkTagStatusMsg) {
        elements.bulkTagStatusMsg.style.display = 'none';
    }

    renderBulkTagSuggestions();

    if (elements.bulkTagModal) {
        elements.bulkTagModal.style.display = 'flex';
    }
}

function closeBulkTagModal() {
    if (elements.bulkTagModal) {
        elements.bulkTagModal.style.display = 'none';
    }
}

function renderBulkTagSuggestions() {
    if (!elements.bulkTagSuggestions) return;
    const allTags = state.allTags || [];
    if (allTags.length === 0) {
        elements.bulkTagSuggestions.innerHTML = '<span style="font-size:11px;color:#94a3b8;">登録済みタグはまだありません</span>';
        return;
    }
    elements.bulkTagSuggestions.innerHTML = allTags.map(t => `
        <button type="button" class="tag-chip-btn" onclick="appendTagToBulkInput('${escapeHtml(t.name)}')">
            <i class="fa-solid fa-plus" style="font-size:9px;"></i> ${escapeHtml(t.name)} (${t.count})
        </button>
    `).join('');
}

function appendTagToBulkInput(tagName) {
    if (!elements.bulkTagInput || !tagName) return;
    const cur = elements.bulkTagInput.value.trim();
    const tags = cur ? cur.split(/[,、\s]+/).map(t => t.trim()).filter(Boolean) : [];
    if (!tags.includes(tagName)) {
        tags.push(tagName);
    }
    elements.bulkTagInput.value = tags.join(', ');
}

function renderEditTagSuggestions() {
    if (!elements.editTagSuggestions) return;
    const allTags = state.allTags || [];
    if (allTags.length === 0) {
        elements.editTagSuggestions.innerHTML = '<span style="font-size:11px;color:#94a3b8;">よく使うタグ候補: 月謝会員, 体験受講, スマホコース, Windows11 (入力すると次回から候補に表示されます)</span>';
        return;
    }
    elements.editTagSuggestions.innerHTML = allTags.map(t => `
        <button type="button" class="tag-chip-btn" onclick="appendTagToEditInput('${escapeHtml(t.name)}')">
            <i class="fa-solid fa-plus" style="font-size:9px;"></i> ${escapeHtml(t.name)}
        </button>
    `).join('');
}

function appendTagToEditInput(tagName) {
    if (!elements.editTagsInput || !tagName) return;
    const cur = elements.editTagsInput.value.trim();
    const tags = cur ? cur.split(/[,、\s]+/).map(t => t.trim()).filter(Boolean) : [];
    if (!tags.includes(tagName)) {
        tags.push(tagName);
    }
    elements.editTagsInput.value = tags.join(', ');
}

async function executeBulkTagUpdate() {
    const ids = Array.from(state.selectedCustomerIds);
    if (ids.length === 0) {
        alert('受講生が選択されていません。');
        return;
    }
    const inputVal = elements.bulkTagInput ? elements.bulkTagInput.value.trim() : '';
    if (!inputVal) {
        alert('タグを入力または候補から選択してください。');
        return;
    }

    const btn = elements.executeBulkTagBtn;
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 処理中...';
    }

    try {
        const formData = new FormData();
        formData.append('password', state.password);
        formData.append('customer_ids', ids.join(','));
        formData.append('mode', state.bulkTagMode || 'add');
        formData.append('tags', inputVal);

        const res = await fetch(`../api.php?action=admin_bulk_update_tags&account=${encodeURIComponent(state.activeAccount)}`, {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            closeBulkTagModal();
            clearCustomerSelection();
            showToast(`✅ ${data.message || 'タグを一括更新しました！'}`);
            await fetchCustomers();
        } else {
            alert(data.error || '一括更新に失敗しました');
        }
    } catch (e) {
        console.error('Bulk tag update error:', e);
        alert('通信エラーが発生しました: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }
}

function openEditModalByIndex(idx) {
    const list = state.currentFilteredList || state.allCustomers || [];
    const cust = list[idx];
    if (cust) {
        openEditModal(cust);
    }
}

// ==============================================================================
// 🚀 システムオンライン更新 (OTAアップデーター) 機能
// ==============================================================================
let isSystemUpdateInProgress = false;

function initSystemUpdater() {
    const btnHeader = document.getElementById('btnOpenSystemUpdateModal');
    const btnToolbar = document.getElementById('btnToolbarSystemUpdate');
    const modal = document.getElementById('systemUpdateModal');
    const btnClose = document.getElementById('closeSystemUpdateModalBtn');
    const btnCloseFooter = document.getElementById('closeSystemUpdateModalFooterBtn');
    const btnRefresh = document.getElementById('btnRefreshUpdateCheck');
    const btnExecute = document.getElementById('btnExecuteSystemUpdate');

    if (!modal) return;

    const openModal = () => {
        modal.style.display = 'flex';
        checkSystemUpdate(false);
    };

    const closeModal = () => {
        if (isSystemUpdateInProgress) {
            if (!confirm('システム更新が実行中です。途中で閉じると更新が不完全になる可能性があります。本当に閉じますか？')) {
                return;
            }
        }
        modal.style.display = 'none';
    };

    if (btnHeader) btnHeader.addEventListener('click', openModal);
    if (btnToolbar) btnToolbar.addEventListener('click', openModal);
    if (btnClose) btnClose.addEventListener('click', closeModal);
    if (btnCloseFooter) btnCloseFooter.addEventListener('click', closeModal);

    if (btnRefresh) {
        btnRefresh.addEventListener('click', () => {
            checkSystemUpdate(false);
        });
    }

    if (btnExecute) {
        btnExecute.addEventListener('click', () => {
            executeSystemUpdate();
        });
    }

    // 起動時にサイレントで更新チェック (1回)
    setTimeout(() => {
        checkSystemUpdate(true);
    }, 2500);
}

/**
 * リモートの最新更新情報をチェック
 * @param {boolean} isSilent - trueの場合はエラーや通知トーストを表示せずバッジのみ更新
 */
async function checkSystemUpdate(isSilent = false) {
    const currentVerEl = document.getElementById('updateCurrentVersion');
    const currentCommitEl = document.getElementById('updateCurrentCommit');
    const remoteVerEl = document.getElementById('updateRemoteVersion');
    const remoteCommitEl = document.getElementById('updateRemoteCommit');
    const alertBox = document.getElementById('updateStatusAlert');
    const alertIcon = document.getElementById('updateStatusIcon');
    const alertText = document.getElementById('updateStatusText');
    const commitWrap = document.getElementById('updateCommitDetailsWrap');
    const commitMsgEl = document.getElementById('updateCommitMessage');
    const remoteDateEl = document.getElementById('updateRemoteDate');
    const btnExecute = document.getElementById('btnExecuteSystemUpdate');
    const headerPulseDot = document.getElementById('headerUpdatePulseDot');
    const toolbarBadge = document.getElementById('toolbarUpdateBadge');

    if (!isSilent && alertBox) {
        alertBox.style.background = '#e0f2fe';
        alertBox.style.borderColor = '#bae6fd';
        alertBox.style.color = '#0369a1';
        if (alertIcon) alertIcon.className = 'fa-solid fa-spinner fa-spin';
        if (alertText) alertText.textContent = '最新の更新情報をチェックしています...';
        if (btnExecute) btnExecute.disabled = true;
    }

    try {
        const res = await fetch(`../api.php?action=check_system_update&account=${encodeURIComponent(state.activeAccount)}`);
        const data = await res.json();

        if (!data.success) {
            throw new Error(data.error || '更新チェックに失敗しました');
        }

        // ローカルバージョン情報の反映
        const local = data.local || {};
        if (currentVerEl) currentVerEl.textContent = `v${local.version || '2.5.0'}`;
        if (currentCommitEl) {
            const shortC = local.commit ? local.commit.substring(0, 7) : '不明';
            currentCommitEl.textContent = `コミット: ${shortC} (${local.updated_at ? local.updated_at.split(' ')[0] : '標準版'})`;
        }

        // リモートバージョン情報の反映
        const remote = data.remote || {};
        if (remoteVerEl) remoteVerEl.textContent = `v${remote.version || local.version || '2.5.0'}`;
        if (remoteCommitEl) {
            const shortR = remote.commit ? remote.commit.substring(0, 7) : '未検出';
            remoteCommitEl.textContent = `最新コミット: ${shortR}`;
        }
        if (remoteDateEl && remote.commit_date) {
            remoteDateEl.textContent = `更新日: ${remote.commit_date}`;
        }
        if (commitMsgEl && remote.commit_message) {
            commitMsgEl.textContent = remote.commit_message;
        }

        // アップデート有無の判定
        if (data.has_update) {
            if (headerPulseDot) headerPulseDot.style.display = 'block';
            if (toolbarBadge) toolbarBadge.style.display = 'inline-block';
            if (commitWrap) commitWrap.style.display = 'block';

            if (alertBox) {
                alertBox.style.background = '#fef3c7';
                alertBox.style.borderColor = '#fde68a';
                alertBox.style.color = '#92400e';
            }
            if (alertIcon) alertIcon.className = 'fa-solid fa-bell';
            if (alertText) {
                alertText.innerHTML = `<strong>🚀 新しいアップデートが利用可能です！</strong>（${escapeHtml(remote.commit ? remote.commit.substring(0, 7) : '')}）`;
            }
            if (btnExecute) {
                btnExecute.disabled = false;
                btnExecute.innerHTML = '<i class="fa-solid fa-cloud-arrow-down"></i> 今すぐシステムを更新';
            }
        } else {
            if (headerPulseDot) headerPulseDot.style.display = 'none';
            if (toolbarBadge) toolbarBadge.style.display = 'none';
            if (commitWrap) commitWrap.style.display = remote.commit_message ? 'block' : 'none';

            if (alertBox) {
                alertBox.style.background = '#ecfdf5';
                alertBox.style.borderColor = '#a7f3d0';
                alertBox.style.color = '#065f46';
            }
            if (alertIcon) alertIcon.className = 'fa-solid fa-circle-check';
            if (alertText) {
                alertText.innerHTML = '<strong>✅ お使いのシステムは最新バージョンです</strong>（更新の必要はありません）';
            }
            if (btnExecute) {
                btnExecute.disabled = true;
                btnExecute.innerHTML = '<i class="fa-solid fa-check"></i> 最新版が適用済みです';
            }
        }
    } catch (e) {
        console.error('System update check error:', e);
        if (!isSilent) {
            if (alertBox) {
                alertBox.style.background = '#fee2e2';
                alertBox.style.borderColor = '#fecaca';
                alertBox.style.color = '#b91c1c';
            }
            if (alertIcon) alertIcon.className = 'fa-solid fa-triangle-exclamation';
            if (alertText) {
                alertText.textContent = `更新情報の取得に失敗しました: ${e.message}`;
            }
            if (btnExecute) btnExecute.disabled = true;
        }
    }
}

/**
 * オンライン更新を実行
 */
async function executeSystemUpdate() {
    if (isSystemUpdateInProgress) return;

    const ok = confirm(
        "【システム更新の確認】\n\n" +
        "最新のシステムファイルをダウンロードし、自動アップデートを実行します。\n" +
        "※各社の顧客データベース・設定・画像は安全に保護され保持されます。\n\n" +
        "更新を開始してもよろしいですか？"
    );
    if (!ok) return;

    isSystemUpdateInProgress = true;
    const btnExecute = document.getElementById('btnExecuteSystemUpdate');
    const btnCloseFooter = document.getElementById('closeSystemUpdateModalFooterBtn');
    const btnRefresh = document.getElementById('btnRefreshUpdateCheck');
    const progressWrap = document.getElementById('updateProgressWrap');
    const progressStep = document.getElementById('updateProgressStep');
    const progressPercent = document.getElementById('updateProgressPercent');
    const progressBar = document.getElementById('updateProgressBar');
    const logBox = document.getElementById('updateLogBox');

    if (btnExecute) {
        btnExecute.disabled = true;
        btnExecute.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> アップデート実行中...';
    }
    if (btnCloseFooter) btnCloseFooter.disabled = true;
    if (btnRefresh) btnRefresh.disabled = true;
    if (progressWrap) progressWrap.style.display = 'block';

    const appendLog = (msg) => {
        if (!logBox) return;
        const line = document.createElement('div');
        line.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
        logBox.appendChild(line);
        logBox.scrollTop = logBox.scrollHeight;
    };

    const setProgress = (percent, stepText) => {
        if (progressBar) progressBar.style.width = `${percent}%`;
        if (progressPercent) progressPercent.textContent = `${percent}%`;
        if (progressStep) progressStep.textContent = stepText;
    };

    try {
        appendLog('🚀 システム自動更新シーケンスを開始します...');
        setProgress(15, '最新パッケージをGitHubより取得中...');

        const updateTimer = setTimeout(() => {
            setProgress(45, 'ZIPパッケージを展開・差分検証中...');
            appendLog('📦 パッケージアーカイブを展開中...');
        }, 1200);

        const updateTimer2 = setTimeout(() => {
            setProgress(75, '保護ファイルを除外して安全上書き中...');
            appendLog('🛡️ 顧客データベース・個別設定・画像を保護しています...');
        }, 2800);

        const res = await fetch(`../api.php?action=execute_system_update&account=${encodeURIComponent(state.activeAccount)}`, {
            method: 'POST'
        });
        clearTimeout(updateTimer);
        clearTimeout(updateTimer2);

        const data = await res.json();

        if (!data.success) {
            throw new Error(data.error || 'システム更新処理でエラーが発生しました');
        }

        setProgress(90, 'データベーススキーマの自動マイグレーション実行中...');
        appendLog(`📂 上書き更新完了: ${data.updated_files_count || 0} ファイルを更新しました`);

        if (Array.isArray(data.migrations) && data.migrations.length > 0) {
            data.migrations.forEach(m => appendLog(`⚡ DBマイグレーション: ${m}`));
        }

        setProgress(100, '更新完了！画面を再読み込みします...');
        appendLog(`✨ システム更新成功！ 現在バージョン: v${data.current_version || '2.5.0'} (${data.current_commit ? data.current_commit.substring(0, 7) : ''})`);
        appendLog('🔄 3秒後にブラウザを自動リフレッシュして新バージョンを適用します...');

        showToast('🎉 システムの最新アップデートが完了しました！');

        // 3秒後に自動リロード
        setTimeout(() => {
            window.location.reload(true);
        }, 3000);

    } catch (e) {
        console.error('Update execution failed:', e);
        isSystemUpdateInProgress = false;
        setProgress(0, 'アップデート失敗');
        appendLog(`❌ エラー: ${e.message}`);
        alert(`システム更新に失敗しました:\n${e.message}`);

        if (btnExecute) {
            btnExecute.disabled = false;
            btnExecute.innerHTML = '<i class="fa-solid fa-cloud-arrow-down"></i> 再試行';
        }
        if (btnCloseFooter) btnCloseFooter.disabled = false;
        if (btnRefresh) btnRefresh.disabled = false;
    }
}

// グローバルスコープ公開
window.filterByTag = filterByTag;
window.toggleCustomerSelection = toggleCustomerSelection;
window.toggleSelectAllCustomers = toggleSelectAllCustomers;
window.clearCustomerSelection = clearCustomerSelection;
window.openBulkTagModal = openBulkTagModal;
window.closeBulkTagModal = closeBulkTagModal;
window.appendTagToBulkInput = appendTagToBulkInput;
window.appendTagToEditInput = appendTagToEditInput;
window.executeBulkTagUpdate = executeBulkTagUpdate;
window.openEditModalByIndex = openEditModalByIndex;
window.initSystemUpdater = initSystemUpdater;
window.checkSystemUpdate = checkSystemUpdate;
window.executeSystemUpdate = executeSystemUpdate;

// ==========================================
// LINE公式アカウント Webhook 接続診断 & テスト受信
// ==========================================

async function openLineDiagnosticsModal() {
    const modal = document.getElementById('lineDiagnosticsModal');
    if (!modal) return;
    modal.style.display = 'flex';
    await loadLineDiagnosticsData();
}

function closeLineDiagnosticsModal() {
    const modal = document.getElementById('lineDiagnosticsModal');
    if (modal) modal.style.display = 'none';
}

async function loadLineDiagnosticsData() {
    const urlInput = document.getElementById('diagWebhookUrl');
    const urlAlt = document.getElementById('diagWebhookUrlAlt');
    const accName = document.getElementById('diagAccountName');
    const tokenStatus = document.getElementById('diagTokenStatus');
    const secretStatus = document.getElementById('diagSecretStatus');
    const studentCount = document.getElementById('diagStudentCount');
    const prolineStatus = document.getElementById('diagProlineStatus');
    const logBox = document.getElementById('diagDebugLogBox');

    if (logBox) logBox.textContent = 'リアルタイムログを取得中...';

    try {
        const res = await fetch(`api.php?action=get_webhook_diagnostics&account=${encodeURIComponent(currentActiveAccount || '')}`, {
            headers: getAuthHeaders()
        });
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || '診断データの取得に失敗しました');
        }

        if (urlInput) urlInput.value = data.account.webhook_url_recommended || '';
        if (urlAlt) urlAlt.textContent = data.account.webhook_url_alt || '';
        if (accName) accName.textContent = `${data.account.name} (ID: ${data.account.id})`;
        if (tokenStatus) {
            tokenStatus.innerHTML = data.account.has_token
                ? `<span style="color: #16a34a; font-weight: 700;">✅ ${escapeHtml(data.account.token_status)}</span>`
                : `<span style="color: #dc2626; font-weight: 700;">❌ 未設定</span>`;
        }
        if (secretStatus) {
            secretStatus.innerHTML = data.account.has_secret
                ? `<span style="color: #16a34a; font-weight: 700;">✅ ${escapeHtml(data.account.secret_status)}</span>`
                : `<span style="color: #dc2626; font-weight: 700;">❌ 未設定</span>`;
        }
        if (studentCount) {
            studentCount.innerHTML = `<strong>${data.account.student_count}名</strong> (未読メッセージ: <strong style="color: #ea580c;">${data.account.unread_count}件</strong>)`;
        }
        if (prolineStatus) {
            prolineStatus.innerHTML = data.proline.enabled
                ? `<span style="color: #16a34a; font-weight: 700;">✅ 稼働中 (${(data.proline.urls || []).length}件へ同時転送)</span>`
                : `<span style="color: #64748b;">未設定 (中継OFF)</span>`;
        }

        if (logBox) {
            if (data.debug_logs && data.debug_logs.length > 0) {
                logBox.textContent = data.debug_logs.join('\n');
                logBox.scrollTop = logBox.scrollHeight;
            } else {
                logBox.textContent = 'ログはまだありません。LINEでメッセージを受信するとここに表示されます。';
            }
        }
    } catch (e) {
        console.error('loadLineDiagnosticsData error:', e);
        if (logBox) logBox.textContent = `診断データ取得エラー: ${e.message}`;
    }
}

async function runSimulateChatMessage() {
    const btn = document.getElementById('btnRunSimulateChat');
    const resultBox = document.getElementById('simulateResultBox');
    if (!btn) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> テスト実行中...';
    if (resultBox) {
        resultBox.style.display = 'block';
        resultBox.className = 'alert alert-info';
        resultBox.style.background = '#e0f2fe';
        resultBox.style.border = '1px solid #bae6fd';
        resultBox.style.color = '#0369a1';
        resultBox.style.padding = '8px 12px';
        resultBox.style.borderRadius = '6px';
        resultBox.innerHTML = 'サーバーへ模擬LINEメッセージを送信しています...';
    }

    try {
        const formData = new FormData();
        formData.append('action', 'simulate_line_chat_message');
        formData.append('account', currentActiveAccount || '');
        formData.append('user_name', 'テスト受講生（田中 一郎）');
        formData.append('message_text', `こんにちは！点検・受講の予約について相談したいです。(テスト送信: ${new Date().toLocaleTimeString()})`);

        const res = await fetch('api.php', {
            method: 'POST',
            headers: getAuthHeaders(),
            body: formData
        });
        const data = await res.json();
        if (!data.success) {
            throw new Error(data.error || 'シミュレーションに失敗しました');
        }

        if (resultBox) {
            resultBox.style.background = '#ecfdf5';
            resultBox.style.border = '1px solid #a7f3d0';
            resultBox.style.color = '#065f46';
            resultBox.innerHTML = `
                <strong>🎉 模擬受信テスト成功！</strong><br>
                メッセージID: <code>${data.simulated_data.message_id}</code> / 受信者: <strong>${escapeHtml(data.simulated_data.user_name)}</strong><br>
                <span style="font-size: 11px;">※管理画面の未読バッジ加算・新着トースト・音声チャイム・一覧更新が実行されます。</span>
            `;
        }

        // 管理画面の受講生データと未読数を即座に再取得
        if (typeof pollUnreadChatCount === 'function') {
            await pollUnreadChatCount();
        }
        if (typeof loadCustomers === 'function') {
            await loadCustomers();
        }

        // ログを再取得
        await loadLineDiagnosticsData();

    } catch (e) {
        console.error('runSimulateChatMessage error:', e);
        if (resultBox) {
            resultBox.style.background = '#fee2e2';
            resultBox.style.border = '1px solid #fecaca';
            resultBox.style.color = '#b91c1c';
            resultBox.innerHTML = `❌ エラー: ${escapeHtml(e.message)}`;
        }
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-play"></i> 模擬チャットを受信テスト';
    }
}

function copyDiagWebhookUrl() {
    const input = document.getElementById('diagWebhookUrl');
    if (!input || !input.value) return;
    navigator.clipboard.writeText(input.value).then(() => {
        showToast('📋 Webhook URLをクリップボードにコピーしました！', 'success');
    }).catch(() => {
        input.select();
        document.execCommand('copy');
        showToast('📋 Webhook URLをコピーしました', 'success');
    });
}

// 診断モーダルのイベントリスナー登録
document.addEventListener('DOMContentLoaded', () => {
    const btnOpen = document.getElementById('openLineDiagnosticsBtn');
    if (btnOpen) btnOpen.addEventListener('click', openLineDiagnosticsModal);

    const btnClose = document.getElementById('closeLineDiagnosticsModalBtn');
    if (btnClose) btnClose.addEventListener('click', closeLineDiagnosticsModal);

    const btnCloseFooter = document.getElementById('closeLineDiagnosticsModalFooterBtn');
    if (btnCloseFooter) btnCloseFooter.addEventListener('click', closeLineDiagnosticsModal);

    const btnSimulate = document.getElementById('btnRunSimulateChat');
    if (btnSimulate) btnSimulate.addEventListener('click', runSimulateChatMessage);

    const btnCopy = document.getElementById('btnCopyDiagWebhookUrl');
    if (btnCopy) btnCopy.addEventListener('click', copyDiagWebhookUrl);

    const btnRefreshLogs = document.getElementById('btnRefreshDiagLogs');
    if (btnRefreshLogs) btnRefreshLogs.addEventListener('click', loadLineDiagnosticsData);
});

window.openLineDiagnosticsModal = openLineDiagnosticsModal;
window.closeLineDiagnosticsModal = closeLineDiagnosticsModal;
window.loadLineDiagnosticsData = loadLineDiagnosticsData;
window.runSimulateChatMessage = runSimulateChatMessage;










