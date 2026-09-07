/**
 * アップファーレン リッチメニュー管理エディタ JS
 */

const state = {
    password: '',
    currentView: 'editor', // 'editor' or 'history'
    
    // エディタ設定状態
    menuSize: 'large', // 'large' (2500x1686) or 'small' (2500x843)
    width: 2500,
    height: 1686,
    imageFile: null,
    imageSrc: '', // data URL or server URL
    baseImageFile: null, // 装飾テキスト無しのクリーンな元画像ファイル
    baseImageSrc: '', // 装飾テキスト無しのクリーンな元画像URL
    
    // エリア配列: [{ id, bounds: {x, y, width, height}, action: {type, data, uri, text, displayText} }]
    areas: [],
    selectedAreaId: null,

    // ドラッグ＆リサイズ追跡
    isDragging: false,
    isResizing: false,
    dragAction: null, // 'create', 'move', 'nw', 'ne', 'se', 'sw'
    dragStart: { x: 0, y: 0 },
    dragTargetArea: null,
    initialBounds: null,

    // 履歴
    historyList: [],
    currentLineDefaultId: null,
    historyFilter: 'all', // 'all' | 'normal' | 'notice'
    activeNoticeId: null,

    // 既存メニュー編集中状態
    editingMenuId: null,
    editingMenuAliasId: null,
    editingMenuTitle: '',

    // 装飾テキスト・お知らせバナー
    textOverlays: [],
    isOverlayDragging: false,
    dragOverlayItem: null,
    dragOverlayEl: null,
    overlayDragStart: null
};

const elements = {
    // 認証
    loginModal: document.getElementById('loginModal'),
    adminPasswordInput: document.getElementById('adminPasswordInput'),
    loginBtn: document.getElementById('loginBtn'),
    loginErrorMsg: document.getElementById('loginErrorMsg'),
    adminApp: document.getElementById('adminApp'),
    logoutBtn: document.getElementById('logoutBtn'),

    // ビュー
    tabEditorBtn: document.getElementById('tabEditorBtn'),
    tabHistoryBtn: document.getElementById('tabHistoryBtn'),
    editorView: document.getElementById('editorView'),
    historyView: document.getElementById('historyView'),
    historyCount: document.getElementById('historyCount'),
    currentLiveBadge: document.getElementById('currentLiveBadge'),
    liveMenuName: document.getElementById('liveMenuName'),

    // キャンバス
    canvasViewport: document.getElementById('canvasViewport'),
    uploadDropzone: document.getElementById('uploadDropzone'),
    imageFileInput: document.getElementById('imageFileInput'),
    selectImageBtn: document.getElementById('selectImageBtn'),
    canvasStage: document.getElementById('canvasStage'),
    stageImage: document.getElementById('stageImage'),
    stageOverlay: document.getElementById('stageOverlay'),
    textOverlaysStage: document.getElementById('textOverlaysStage'),
    changeImageBtn: document.getElementById('changeImageBtn'),
    clearAreasBtn: document.getElementById('clearAreasBtn'),

    // ツールバー
    sizeToggleBtns: document.querySelectorAll('.btn-toggle'),
    presetBtns: document.querySelectorAll('.btn-preset'),

    // 装飾テキスト・お知らせバナー
    addTextOverlayBtn: document.getElementById('addTextOverlayBtn'),
    textOverlayList: document.getElementById('textOverlayList'),
    textOverlayEmptyMsg: document.getElementById('textOverlayEmptyMsg'),

    // プロパティパネル
    menuTitleInput: document.getElementById('menuTitleInput'),
    chatBarTextInput: document.getElementById('chatBarTextInput'),
    areaNoSelectionMsg: document.getElementById('areaNoSelectionMsg'),
    areaConfigForm: document.getElementById('areaConfigForm'),
    selectedAreaLabel: document.getElementById('selectedAreaLabel'),
    deleteSelectedAreaBtn: document.getElementById('deleteSelectedAreaBtn'),

    // 座標直接入力
    inputCoordX: document.getElementById('inputCoordX'),
    inputCoordY: document.getElementById('inputCoordY'),
    inputCoordW: document.getElementById('inputCoordW'),
    inputCoordH: document.getElementById('inputCoordH'),

    // アクション
    actionTypeRadios: document.querySelectorAll('input[name="actionType"]'),
    fieldPostback: document.getElementById('fieldPostback'),
    fieldUri: document.getElementById('fieldUri'),
    fieldMessage: document.getElementById('fieldMessage'),
    fieldRichMenuSwitch: document.getElementById('fieldRichMenuSwitch'),
    switchMenuSelect: document.getElementById('switchMenuSelect'),
    switchBranchCustomCheck: document.getElementById('switchBranchCustomCheck'),
    postbackDataInput: document.getElementById('postbackDataInput'),
    postbackDisplayTextInput: document.getElementById('postbackDisplayTextInput'),
    uriInput: document.getElementById('uriInput'),
    messageTextInput: document.getElementById('messageTextInput'),

    // お知らせ作成モーダル
    btnOpenNoticeModal: document.getElementById('btnOpenNoticeModal'),
    noticeWizardModal: document.getElementById('noticeWizardModal'),
    btnCloseNoticeWizard: document.getElementById('btnCloseNoticeWizard'),
    btnCancelNoticeWizard: document.getElementById('btnCancelNoticeWizard'),
    btnSubmitNoticePublish: document.getElementById('btnSubmitNoticePublish'),
    noticeTitleInput: document.getElementById('noticeTitleInput'),
    noticeTitleSizeInput: document.getElementById('noticeTitleSizeInput'),
    noticeTitleColorInput: document.getElementById('noticeTitleColorInput'),
    noticeTitleColorCode: document.getElementById('noticeTitleColorCode'),
    noticeTitleAlignSelect: document.getElementById('noticeTitleAlignSelect'),
    noticeBodyInput: document.getElementById('noticeBodyInput'),
    noticeBodySizeInput: document.getElementById('noticeBodySizeInput'),
    noticeBodyColorInput: document.getElementById('noticeBodyColorInput'),
    noticeBodyColorCode: document.getElementById('noticeBodyColorCode'),
    noticeBodyAlignSelect: document.getElementById('noticeBodyAlignSelect'),
    btnResetThemeColors: document.getElementById('btnResetThemeColors'),
    btnNoticeSizeLarge: document.getElementById('btnNoticeSizeLarge'),
    btnNoticeSizeSmall: document.getElementById('btnNoticeSizeSmall'),
    noticeReturnMenuSelect: document.getElementById('noticeReturnMenuSelect'),
    noticeCloseBtnTextInput: document.getElementById('noticeCloseBtnTextInput'),
    noticeLinkUrlInput: document.getElementById('noticeLinkUrlInput'),
    noticeCanvasPreview: document.getElementById('noticeCanvasPreview'),
    noticePublishToAllCheckbox: document.getElementById('noticePublishToAllCheckbox'),

    // ボタン & 編集中バナー
    editingStatusBanner: document.getElementById('editingStatusBanner'),
    btnCancelEditMode: document.getElementById('btnCancelEditMode'),
    publishMenuBtn: document.getElementById('publishMenuBtn'),
    saveDraftBtn: document.getElementById('saveDraftBtn'),
    saveAsCopyBtn: document.getElementById('saveAsCopyBtn'),

    // 履歴
    historyGrid: document.getElementById('historyGrid'),
    historyEmpty: document.getElementById('historyEmpty'),
    historyCreateNewBtn: document.getElementById('historyCreateNewBtn'),
    emptyCreateBtn: document.getElementById('emptyCreateBtn'),
    countFilterAll: document.getElementById('countFilterAll'),
    countFilterNormal: document.getElementById('countFilterNormal'),
    countFilterNotice: document.getElementById('countFilterNotice'),
    filterTabs: document.querySelectorAll('.btn-filter-tab'),

    // ローディング & トースト
    loadingOverlay: document.getElementById('loadingOverlay'),
    loadingMsg: document.getElementById('loadingMsg'),
    toast: document.getElementById('adminToast')
};

// ================= 初期化 =================
document.addEventListener('DOMContentLoaded', () => {
    initAuth();
    initEventListeners();
});

function initAuth() {
    const savedPass = sessionStorage.getItem('admin_pass');
    if (savedPass) {
        state.password = savedPass;
        showApp();
    } else {
        elements.loginModal.style.display = 'flex';
        elements.adminApp.style.display = 'none';
    }
}

function showApp() {
    elements.loginModal.style.display = 'none';
    elements.adminApp.style.display = 'block';
    renderTextOverlayControls();
    loadHistoryList();
}

function attemptLogin() {
    const pass = elements.adminPasswordInput.value.trim();
    if (!pass) {
        elements.loginErrorMsg.textContent = 'パスワードを入力してください';
        return;
    }

    showLoading('認証中...');
    fetch('../api.php?action=admin_list_richmenus&password=' + encodeURIComponent(pass))
        .then(res => res.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                state.password = pass;
                sessionStorage.setItem('admin_pass', pass);
                elements.loginErrorMsg.textContent = '';
                showApp();
            } else {
                elements.loginErrorMsg.textContent = data.error || 'パスワードが正しくありません';
            }
        })
        .catch(err => {
            hideLoading();
            elements.loginErrorMsg.textContent = '通信エラーが発生しました';
        });
}

function logout() {
    sessionStorage.removeItem('admin_pass');
    state.password = '';
    elements.adminApp.style.display = 'none';
    elements.loginModal.style.display = 'flex';
    elements.adminPasswordInput.value = '';
    elements.loginErrorMsg.textContent = '';
}

// ================= イベントリスナー設定 =================
function initEventListeners() {
    // 認証
    elements.loginBtn.addEventListener('click', attemptLogin);
    elements.adminPasswordInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') attemptLogin();
    });
    elements.logoutBtn.addEventListener('click', logout);

    // ビュー切り替え
    elements.tabEditorBtn.addEventListener('click', () => switchView('editor'));
    elements.tabHistoryBtn.addEventListener('click', () => switchView('history'));
    elements.historyCreateNewBtn.addEventListener('click', () => {
        resetEditorForm();
        switchView('editor');
    });
    elements.emptyCreateBtn.addEventListener('click', () => {
        resetEditorForm();
        switchView('editor');
    });

    // 履歴フィルタータブ (すべて / 通常メニュー / 📢 お知らせ専用メニュー)
    if (elements.filterTabs) {
        elements.filterTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                elements.filterTabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                state.historyFilter = tab.dataset.filter || 'all';
                renderHistoryList();
            });
        });
    }

    // サイズトグル
    elements.sizeToggleBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            elements.sizeToggleBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            setMenuSize(btn.dataset.size);
        });
    });

    // プリセット分割ボタン
    elements.presetBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            applyPreset(btn.dataset.preset);
        });
    });

    // 枠クリア
    elements.clearAreasBtn.addEventListener('click', () => {
        if (state.areas.length === 0) return;
        if (confirm('設定中のタップ枠をすべてクリアしますか？')) {
            state.areas = [];
            state.selectedAreaId = null;
            renderAreas();
            updateAreaConfigForm();
        }
    });

    // 画像アップロード関連
    elements.selectImageBtn.addEventListener('click', () => elements.imageFileInput.click());
    elements.uploadDropzone.addEventListener('click', (e) => {
        if (e.target !== elements.selectImageBtn) elements.imageFileInput.click();
    });
    elements.changeImageBtn.addEventListener('click', () => elements.imageFileInput.click());
    elements.imageFileInput.addEventListener('change', handleFileSelect);

    // ドロップゾーン ドラッグ＆ドロップ
    elements.uploadDropzone.addEventListener('dragover', (e) => {
        e.preventDefault();
        elements.uploadDropzone.classList.add('dragover');
    });
    elements.uploadDropzone.addEventListener('dragleave', () => {
        elements.uploadDropzone.classList.remove('dragover');
    });
    elements.uploadDropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        elements.uploadDropzone.classList.remove('dragover');
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            processImageFile(e.dataTransfer.files[0]);
        }
    });

    // キャンバスステージ上でのマウス操作 (枠描画・移動・リサイズ)
    initCanvasInteractions();

    // アクション設定フォームの同期
    elements.actionTypeRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            showActionFieldGroup(radio.value);
            syncCurrentAreaFromForm();
        });
    });
    elements.postbackDataInput.addEventListener('input', syncCurrentAreaFromForm);
    elements.postbackDisplayTextInput.addEventListener('input', syncCurrentAreaFromForm);
    elements.uriInput.addEventListener('input', () => {
        const val = elements.uriInput.value.trim();
        if (val.includes('shopCard') || val.includes('shopcard')) {
            localStorage.setItem('line_shopcard_url', val);
        }
        syncCurrentAreaFromForm();
    });
    elements.messageTextInput.addEventListener('input', syncCurrentAreaFromForm);
    if (elements.switchMenuSelect) {
        elements.switchMenuSelect.addEventListener('change', syncCurrentAreaFromForm);
    }
    if (elements.switchBranchCustomCheck) {
        elements.switchBranchCustomCheck.addEventListener('change', syncCurrentAreaFromForm);
    }

    // 座標直接入力の同期
    if (elements.inputCoordX) elements.inputCoordX.addEventListener('input', syncCoordsFromInputs);
    if (elements.inputCoordY) elements.inputCoordY.addEventListener('input', syncCoordsFromInputs);
    if (elements.inputCoordW) elements.inputCoordW.addEventListener('input', syncCoordsFromInputs);
    if (elements.inputCoordH) elements.inputCoordH.addEventListener('input', syncCoordsFromInputs);

    // クイック入力チップ
    document.querySelectorAll('.quick-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            // LINE公式スタンプカード（ショップカード）の場合
            if (chip.id === 'chipStampCard') {
                elements.actionTypeRadios.forEach(r => { r.checked = (r.value === 'uri'); });
                showActionFieldGroup('uri');

                const savedUrl = localStorage.getItem('line_shopcard_url') || '';
                if (savedUrl) {
                    elements.uriInput.value = savedUrl;
                    syncCurrentAreaFromForm();
                    showToast('保存済みのLINEスタンプカードURLを設定しました', 'success');
                } else {
                    const inputUrl = prompt(
                        '【LINE公式スタンプカード（ショップカード）URL設定】\n\n' +
                        'LINE Official Account Manager（管理画面）＞ ツール ＞ ショップカード にて発行された「カードURL」を入力または貼り付けてください：\n' +
                        '（例: https://line.me/R/nv/shopCard/... または https://line.me/R/ch/...）'
                    );
                    if (inputUrl && inputUrl.trim()) {
                        const clean = inputUrl.trim();
                        localStorage.setItem('line_shopcard_url', clean);
                        elements.uriInput.value = clean;
                        syncCurrentAreaFromForm();
                        showToast('スタンプカードURLを設定しました！次回以降はワンクリックで自動入力されます', 'success');
                    }
                }
                return;
            }

            if (chip.dataset.val) {
                elements.actionTypeRadios.forEach(r => { r.checked = (r.value === 'postback'); });
                showActionFieldGroup('postback');
                elements.postbackDataInput.value = chip.dataset.val;
                syncCurrentAreaFromForm();
            } else if (chip.dataset.uri) {
                elements.actionTypeRadios.forEach(r => { r.checked = (r.value === 'uri'); });
                showActionFieldGroup('uri');
                elements.uriInput.value = chip.dataset.uri;
                syncCurrentAreaFromForm();
            }
        });
    });

    // 枠削除ボタン
    elements.deleteSelectedAreaBtn.addEventListener('click', () => {
        if (state.selectedAreaId !== null) {
            deleteArea(state.selectedAreaId);
        }
    });

    // 装飾テキスト追加ボタン
    if (elements.addTextOverlayBtn) {
        elements.addTextOverlayBtn.addEventListener('click', () => {
            addTextOverlay();
        });
    }

    // 保存・公開ボタン
    elements.publishMenuBtn.addEventListener('click', () => saveRichMenu(true, false));
    elements.saveDraftBtn.addEventListener('click', () => saveRichMenu(false, false));
    if (elements.saveAsCopyBtn) {
        elements.saveAsCopyBtn.addEventListener('click', () => saveRichMenu(false, true));
    }
    if (elements.btnCancelEditMode) {
        elements.btnCancelEditMode.addEventListener('click', () => exitEditMode());
    }

    // クイックお知らせ作成ウィザードイベント
    initNoticeWizardEvents();
}

// ================= ビュー切り替え =================
function switchView(viewName) {
    state.currentView = viewName;
    if (viewName === 'editor') {
        elements.tabEditorBtn.classList.add('active');
        elements.tabHistoryBtn.classList.remove('active');
        elements.editorView.style.display = 'grid';
        elements.historyView.style.display = 'none';
    } else {
        elements.tabEditorBtn.classList.remove('active');
        elements.tabHistoryBtn.classList.add('active');
        elements.editorView.style.display = 'none';
        elements.historyView.style.display = 'block';
        loadHistoryList();
    }
}

// ================= サイズ切り替え =================
function setMenuSize(size) {
    state.menuSize = size;
    if (size === 'large') {
        state.width = 2500;
        state.height = 1686;
    } else {
        state.width = 2500;
        state.height = 843;
    }

    // 既存の枠を再調整
    clampAreasToCanvas();
    renderAreas();
    updateAreaConfigForm();
}

function clampAreasToCanvas() {
    state.areas.forEach(a => {
        if (a.bounds.y + a.bounds.height > state.height) {
            if (a.bounds.y >= state.height) {
                a.bounds.y = Math.max(0, state.height - 300);
                a.bounds.height = 300;
            } else {
                a.bounds.height = state.height - a.bounds.y;
            }
        }
    });
}

// ================= 画像処理 =================
function handleFileSelect(e) {
    if (e.target.files && e.target.files[0]) {
        processImageFile(e.target.files[0]);
    }
}

function processImageFile(file) {
    if (!file.type.match('image/jpeg') && !file.type.match('image/png')) {
        showToast('JPGまたはPNG形式の画像を選択してください', 'error');
        return;
    }

    state.imageFile = file;
    state.baseImageFile = file;

    const reader = new FileReader();
    reader.onload = (event) => {
        state.imageSrc = event.target.result;
        state.baseImageSrc = event.target.result;
        displayLoadedImage(state.imageSrc);
    };
    reader.readAsDataURL(file);
}

function displayLoadedImage(src) {
    elements.uploadDropzone.style.display = 'none';
    elements.canvasStage.style.display = 'inline-block';
    elements.changeImageBtn.style.display = 'inline-block';

    elements.stageImage.onload = () => {
        renderAreas();
        renderTextOverlays();
        renderTextOverlayControls();
        updateAreaConfigForm();
    };
    elements.stageImage.src = src;

    // もし枠が空ならデフォルトで6分割を適用
    if (state.areas.length === 0) {
        applyPreset(state.menuSize === 'large' ? 'grid6' : 'grid3');
    } else {
        renderAreas();
        renderTextOverlays();
        renderTextOverlayControls();
        updateAreaConfigForm();
    }
}

// ================= プリセット分割 =================
function applyPreset(presetType) {
    const W = state.width;
    const H = state.height;
    state.areas = [];
    state.selectedAreaId = null;

    if (presetType === 'grid6') {
        // 2行 × 3列
        const colW = Math.round(W / 3);
        const rowH = Math.round(H / 2);
        let idCounter = 1;

        const defaultActions = [
            { type: 'postback', data: 'action=show_price_menu', displayText: '価格で探す' },
            { type: 'postback', data: 'action=search_all', displayText: '在庫全台' },
            { type: 'postback', data: 'action=open_mycar', displayText: '点検受付' },
            { type: 'postback', data: 'action=show_type_menu', displayText: '車種で探す' },
            { type: 'postback', data: 'action=show_equipment_menu', displayText: '装備で探す' },
            { type: 'postback', data: 'action=show_distance_menu', displayText: '距離で探す' }
        ];

        for (let row = 0; row < 2; row++) {
            for (let col = 0; col < 3; col++) {
                const x = col * colW;
                const y = row * rowH;
                const w = (col === 2) ? (W - x) : colW;
                const h = (row === 1) ? (H - y) : rowH;
                const act = defaultActions[(idCounter - 1)] || { type: 'postback', data: 'action=search_all' };

                state.areas.push({
                    id: idCounter++,
                    bounds: { x, y, width: w, height: h },
                    action: act
                });
            }
        }
    } else if (presetType === 'grid4') {
        // 2行 × 2列
        const colW = Math.round(W / 2);
        const rowH = Math.round(H / 2);
        let idCounter = 1;

        const defaultActions = [
            { type: 'postback', data: 'action=show_price_menu', displayText: '価格で探す' },
            { type: 'postback', data: 'action=open_mycar', displayText: '点検受付' },
            { type: 'postback', data: 'action=search_all', displayText: '在庫全台' },
            { type: 'uri', uri: 'https://liff.line.me/2000276344-YL1wXh0h/mycar.html' }
        ];

        for (let row = 0; row < 2; row++) {
            for (let col = 0; col < 2; col++) {
                const x = col * colW;
                const y = row * rowH;
                const w = (col === 1) ? (W - x) : colW;
                const h = (row === 1) ? (H - y) : rowH;

                state.areas.push({
                    id: idCounter++,
                    bounds: { x, y, width: w, height: h },
                    action: defaultActions[idCounter - 2] || { type: 'postback', data: 'action=search_all' }
                });
            }
        }
    } else if (presetType === 'grid3') {
        // 1行 × 3列
        const colW = Math.round(W / 3);
        let idCounter = 1;
        for (let col = 0; col < 3; col++) {
            const x = col * colW;
            const w = (col === 2) ? (W - x) : colW;
            state.areas.push({
                id: idCounter++,
                bounds: { x, y: 0, width: w, height: H },
                action: { type: 'postback', data: 'action=search_all' }
            });
        }
    } else if (presetType === 'hero') {
        // 左大1枠 (半分)、右4枠 (2x2)
        const halfW = Math.round(W / 2);
        const rightColW = Math.round(halfW / 2);
        const rowH = Math.round(H / 2);

        state.areas.push({
            id: 1,
            bounds: { x: 0, y: 0, width: halfW, height: H },
            action: { type: 'postback', data: 'action=search_all', displayText: 'おすすめ在庫を見る' }
        });

        let idCounter = 2;
        for (let r = 0; r < 2; r++) {
            for (let c = 0; c < 2; c++) {
                const x = halfW + c * rightColW;
                const y = r * rowH;
                const w = (c === 1) ? (W - x) : rightColW;
                const h = (r === 1) ? (H - y) : rowH;
                state.areas.push({
                    id: idCounter++,
                    bounds: { x, y, width: w, height: h },
                    action: { type: 'postback', data: 'action=search_all' }
                });
            }
        }
    } else if (presetType === 'full') {
        state.areas.push({
            id: 1,
            bounds: { x: 0, y: 0, width: W, height: H },
            action: { type: 'postback', data: 'action=search_all' }
        });
    }

    if (state.areas.length > 0) {
        state.selectedAreaId = state.areas[0].id;
    }

    renderAreas();
    updateAreaConfigForm();
}

// ================= エリア描画 =================
function renderAreas() {
    elements.stageOverlay.innerHTML = '';

    state.areas.forEach((area, index) => {
        const isSelected = Number(area.id) === Number(state.selectedAreaId);
        const box = document.createElement('div');
        box.className = 'area-box' + (isSelected ? ' selected' : '');
        box.dataset.id = String(area.id);

        // パーセント座標指定 (解像度・画面サイズ・ロードタイミングに左右されず画像と100%完全一致)
        const leftPercent = (area.bounds.x / state.width) * 100;
        const topPercent = (area.bounds.y / state.height) * 100;
        const widthPercent = (area.bounds.width / state.width) * 100;
        const heightPercent = (area.bounds.height / state.height) * 100;

        box.style.left = leftPercent + '%';
        box.style.top = topPercent + '%';
        box.style.width = widthPercent + '%';
        box.style.height = heightPercent + '%';

        // ラベルバッジ
        const badge = document.createElement('div');
        badge.className = 'area-box-badge';
        let actionSummary = area.action?.type || 'postback';
        if (area.action?.type === 'postback') {
            actionSummary = area.action.displayText || area.action.data || 'Postback';
        } else if (area.action?.type === 'uri') {
            const uriVal = area.action.uri || '';
            if (uriVal.includes('shopCard') || uriVal.includes('shopcard')) {
                actionSummary = 'スタンプカード';
            } else if (uriVal.includes('mycar')) {
                actionSummary = '点検パスポート';
            } else if (uriVal.includes('goo-net')) {
                actionSummary = 'Goo-net';
            } else {
                actionSummary = 'リンク';
            }
        } else if (area.action?.type === 'message') {
            actionSummary = area.action.text || 'Message';
        }
        badge.textContent = `枠${index + 1}: ${actionSummary}`;
        box.appendChild(badge);

        // 選択中の場合はリサイズハンドルを追加
        if (isSelected) {
            ['nw', 'ne', 'se', 'sw'].forEach(handleType => {
                const handle = document.createElement('div');
                handle.className = `resize-handle handle-${handleType}`;
                handle.dataset.handle = handleType;
                box.appendChild(handle);
            });
        }

        // 枠自体への直接クリック/タップで即座に再選択
        box.addEventListener('click', (e) => {
            e.stopPropagation();
            selectArea(area.id);
        });
        box.addEventListener('pointerdown', (e) => {
            if (!e.target.classList.contains('resize-handle')) {
                selectArea(area.id);
            }
        });

        elements.stageOverlay.appendChild(box);
    });

    renderAreaPills();
}

// 選択枠切り替え用クイックピル一覧
function renderAreaPills() {
    const wrap = document.getElementById('areaPillsWrap');
    if (!wrap) return;

    if (state.areas.length === 0) {
        wrap.style.display = 'none';
        wrap.innerHTML = '';
        return;
    }

    wrap.style.display = 'flex';
    wrap.innerHTML = '';

    state.areas.forEach((area, index) => {
        const isSelected = Number(area.id) === Number(state.selectedAreaId);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'area-pill-btn' + (isSelected ? ' active' : '');
        btn.dataset.id = String(area.id);

        let title = `枠 ${index + 1}`;
        if (area.action?.type === 'uri' && (area.action.uri?.includes('shopCard') || area.action.uri?.includes('shopcard'))) {
            title += ': 🎫スタンプ';
        } else if (area.action?.displayText) {
            title += `: ${area.action.displayText}`;
        } else if (area.action?.data) {
            title += `: ${area.action.data.replace('action=', '')}`;
        }

        btn.textContent = title;
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            selectArea(area.id);
        });
        wrap.appendChild(btn);
    });
}

// 入力時のリアルタイムバッジ＆ピル更新 (DOM全破棄を行わない)
function updateAreaBadgeAndPill(area) {
    const numId = Number(area.id);
    const box = elements.stageOverlay.querySelector(`.area-box[data-id="${numId}"]`);
    const index = state.areas.findIndex(a => Number(a.id) === numId);
    if (index === -1) return;

    let actionSummary = area.action?.type || 'postback';
    if (area.action?.type === 'postback') {
        actionSummary = area.action.displayText || area.action.data || 'Postback';
    } else if (area.action?.type === 'uri') {
        const uriVal = area.action.uri || '';
        if (uriVal.includes('shopCard') || uriVal.includes('shopcard')) {
            actionSummary = 'スタンプカード';
        } else if (uriVal.includes('mycar')) {
            actionSummary = '点検パスポート';
        } else if (uriVal.includes('goo-net')) {
            actionSummary = 'Goo-net';
        } else {
            actionSummary = 'リンク';
        }
    } else if (area.action?.type === 'message') {
        actionSummary = area.action.text || 'Message';
    } else if (area.action?.type === 'richmenuswitch') {
        const targetMenu = (state.historyList || []).find(m => (m.alias_id && m.alias_id === area.action.richMenuAliasId) || ('rm_' + m.id) === area.action.richMenuAliasId);
        actionSummary = targetMenu ? `切替: ${targetMenu.title}` : 'メニュー切替';
    }

    if (box) {
        let badge = box.querySelector('.area-box-badge');
        if (!badge) {
            badge = document.createElement('div');
            badge.className = 'area-box-badge';
            box.appendChild(badge);
        }
        badge.textContent = `枠${index + 1}: ${actionSummary}`;
    }

    const pill = document.querySelector(`#areaPillsWrap .area-pill-btn[data-id="${numId}"]`);
    if (pill) {
        let title = `枠 ${index + 1}`;
        if (area.action?.type === 'uri' && (area.action.uri?.includes('shopCard') || area.action.uri?.includes('shopcard'))) {
            title += ': 🎫スタンプ';
        } else if (area.action?.type === 'richmenuswitch') {
            title += ': 📋切替';
        } else if (area.action?.displayText) {
            title += `: ${area.action.displayText}`;
        } else if (area.action?.data) {
            title += `: ${area.action.data.replace('action=', '')}`;
        }
        pill.textContent = title;
    }
}

// ================= キャンバス上でのインタラクション =================
function initCanvasInteractions() {
    const overlay = elements.stageOverlay;

    overlay.addEventListener('mousedown', (e) => {
        const rect = overlay.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) return;

        const mouseX = e.clientX - rect.left;
        const mouseY = e.clientY - rect.top;

        const scaleX = state.width / rect.width;
        const scaleY = state.height / rect.height;
        const actualX = Math.round(mouseX * scaleX);
        const actualY = Math.round(mouseY * scaleY);

        // リサイズハンドル判定
        if (e.target.classList.contains('resize-handle')) {
            e.stopPropagation();
            state.isResizing = true;
            state.dragAction = e.target.dataset.handle;
            state.dragStart = { x: actualX, y: actualY };
            state.dragTargetArea = state.areas.find(a => Number(a.id) === Number(state.selectedAreaId));
            state.initialBounds = { ...state.dragTargetArea.bounds };
            return;
        }

        // 枠内クリック判定 (選択 & 移動)
        const areaBoxEl = e.target.closest('.area-box');
        if (areaBoxEl) {
            e.stopPropagation();
            const areaId = parseInt(areaBoxEl.dataset.id, 10);
            selectArea(areaId);

            state.isDragging = true;
            state.dragAction = 'move';
            state.dragStart = { x: actualX, y: actualY };
            state.dragTargetArea = state.areas.find(a => Number(a.id) === areaId);
            state.initialBounds = { ...state.dragTargetArea.bounds };
            return;
        }

        // 空白クリック時: 新規エリア描画の開始
        state.isDragging = true;
        state.dragAction = 'create';
        state.dragStart = { x: actualX, y: actualY };

        const newId = (state.areas.length > 0 ? Math.max(...state.areas.map(a => Number(a.id) || 0)) : 0) + 1;
        const newArea = {
            id: newId,
            bounds: { x: actualX, y: actualY, width: 10, height: 10 },
            action: { type: 'postback', data: 'action=search_all', displayText: '' }
        };
        state.areas.push(newArea);
        state.selectedAreaId = newId;
        state.dragTargetArea = newArea;
        state.initialBounds = { ...newArea.bounds };
        renderAreas();
        updateAreaConfigForm();
    });

    const handleMove = (e) => {
        // 1. 装飾テキストのドラッグ移動
        if (state.isOverlayDragging && state.dragOverlayItem && state.overlayDragStart && state.dragOverlayEl) {
            const dx = e.clientX - state.overlayDragStart.pointerX;
            const dy = e.clientY - state.overlayDragStart.pointerY;

            const origW = Number(state.width) || 2500;
            const origH = Number(state.height) || 1686;

            const scaleX = origW / state.overlayDragStart.stageW;
            const scaleY = origH / state.overlayDragStart.stageH;

            let newX = Math.round(state.overlayDragStart.initX + dx * scaleX);
            let newY = Math.round(state.overlayDragStart.initY + dy * scaleY);

            // リッチメニュー画像領域内に収まるよう制限
            newX = Math.max(0, Math.min(origW - 60, newX));
            newY = Math.max(0, Math.min(origH - 40, newY));

            state.dragOverlayItem.x = newX;
            state.dragOverlayItem.y = newY;

            // DOMスタイルをパーセントで即座に同期
            state.dragOverlayEl.style.left = ((newX / origW) * 100) + '%';
            state.dragOverlayEl.style.top = ((newY / origH) * 100) + '%';

            // 右側パネルのX/Y入力欄にリアルタイム反映
            syncOverlayInputs(state.dragOverlayItem.id, newX, newY);
            return;
        }

        if (!state.isDragging && !state.isResizing) return;

        const rect = overlay.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) return;

        const mouseX = Math.max(0, Math.min(rect.width, e.clientX - rect.left));
        const mouseY = Math.max(0, Math.min(rect.height, e.clientY - rect.top));

        const scaleX = state.width / rect.width;
        const scaleY = state.height / rect.height;
        const currentActualX = Math.round(mouseX * scaleX);
        const currentActualY = Math.round(mouseY * scaleY);

        const deltaX = currentActualX - state.dragStart.x;
        const deltaY = currentActualY - state.dragStart.y;
        const area = state.dragTargetArea;
        const init = state.initialBounds;

        if (!area || !init) return;

        if (state.dragAction === 'move') {
            let newX = init.x + deltaX;
            let newY = init.y + deltaY;
            newX = Math.max(0, Math.min(state.width - area.bounds.width, newX));
            newY = Math.max(0, Math.min(state.height - area.bounds.height, newY));
            area.bounds.x = newX;
            area.bounds.y = newY;
        } else if (state.dragAction === 'create' || state.dragAction === 'se') {
            let newW = init.width + deltaX;
            let newH = init.height + deltaY;
            if (newW > 20 && init.x + newW <= state.width) area.bounds.width = newW;
            if (newH > 20 && init.y + newH <= state.height) area.bounds.height = newH;
        } else if (state.dragAction === 'nw') {
            let newX = init.x + deltaX;
            let newY = init.y + deltaY;
            let newW = init.width - deltaX;
            let newH = init.height - deltaY;
            if (newX >= 0 && newW > 20) { area.bounds.x = newX; area.bounds.width = newW; }
            if (newY >= 0 && newH > 20) { area.bounds.y = newY; area.bounds.height = newH; }
        } else if (state.dragAction === 'ne') {
            let newY = init.y + deltaY;
            let newW = init.width + deltaX;
            let newH = init.height - deltaY;
            if (init.x + newW <= state.width && newW > 20) { area.bounds.width = newW; }
            if (newY >= 0 && newH > 20) { area.bounds.y = newY; area.bounds.height = newH; }
        } else if (state.dragAction === 'sw') {
            let newX = init.x + deltaX;
            let newW = init.width - deltaX;
            let newH = init.height + deltaY;
            if (newX >= 0 && newW > 20) { area.bounds.x = newX; area.bounds.width = newW; }
            if (init.y + newH <= state.height && newH > 20) { area.bounds.height = newH; }
        }

        // DOM再描画なしで該当boxの位置と大きさを高速更新
        const boxEl = overlay.querySelector(`.area-box[data-id="${area.id}"]`);
        if (boxEl) {
            boxEl.style.left = ((area.bounds.x / state.width) * 100) + '%';
            boxEl.style.top = ((area.bounds.y / state.height) * 100) + '%';
            boxEl.style.width = ((area.bounds.width / state.width) * 100) + '%';
            boxEl.style.height = ((area.bounds.height / state.height) * 100) + '%';
        }
        updateCoordsDisplay(area);
    };

    const handleEnd = () => {
        if (state.isOverlayDragging) {
            state.isOverlayDragging = false;
            if (state.dragOverlayEl) {
                state.dragOverlayEl.classList.remove('dragging');
            }
            state.dragOverlayItem = null;
            state.dragOverlayEl = null;
            state.overlayDragStart = null;
        }

        if (state.isDragging || state.isResizing) {
            const finishedAction = state.dragAction;
            const targetArea = state.dragTargetArea;

            // 新規作成で極小（誤クリック）だった場合は枠を破棄して以前の枠に戻す
            if (finishedAction === 'create' && targetArea) {
                if (targetArea.bounds.width < 40 || targetArea.bounds.height < 40) {
                    state.areas = state.areas.filter(a => Number(a.id) !== Number(targetArea.id));
                    state.selectedAreaId = state.areas.length > 0 ? state.areas[0].id : null;
                    renderAreas();
                }
            }

            state.isDragging = false;
            state.isResizing = false;
            state.dragAction = null;
            state.dragTargetArea = null;
            state.initialBounds = null;
            updateAreaConfigForm();
        }
    };

    window.addEventListener('mousemove', handleMove);
    window.addEventListener('pointermove', handleMove);

    window.addEventListener('mouseup', handleEnd);
    window.addEventListener('pointerup', handleEnd);
    window.addEventListener('pointercancel', handleEnd);

    // ウィンドウリサイズ時に再描画 (エリア座標および装飾文字サイズを完全同期)
    window.addEventListener('resize', () => {
        if (state.imageSrc) {
            renderAreas();
            renderTextOverlays();
        }
    });
}

function selectArea(id) {
    const numId = Number(id);
    if (isNaN(numId)) return;

    state.selectedAreaId = numId;

    // DOM要素を破壊せずに高速に選択状態とハンドルを切り替え
    const allBoxes = elements.stageOverlay.querySelectorAll('.area-box');
    allBoxes.forEach(b => {
        const isSelected = Number(b.dataset.id) === numId;
        b.classList.toggle('selected', isSelected);

        // 既存のリサイズハンドルをクリーンアップ
        b.querySelectorAll('.resize-handle').forEach(h => h.remove());

        if (isSelected) {
            ['nw', 'ne', 'se', 'sw'].forEach(handleType => {
                const handle = document.createElement('div');
                handle.className = `resize-handle handle-${handleType}`;
                handle.dataset.handle = handleType;
                b.appendChild(handle);
            });
        }
    });

    // クイックピルのactive状態を同期
    const allPills = document.querySelectorAll('#areaPillsWrap .area-pill-btn');
    allPills.forEach(p => {
        p.classList.toggle('active', Number(p.dataset.id) === numId);
    });

    // 設定フォームに選択中エリアの内容を反映
    updateAreaConfigForm();
}

function deleteArea(id) {
    const numId = Number(id);
    state.areas = state.areas.filter(a => Number(a.id) !== numId);
    state.selectedAreaId = state.areas.length > 0 ? state.areas[0].id : null;
    renderAreas();
    updateAreaConfigForm();
}

// ================= プロパティ設定フォームの更新 =================
function updateAreaConfigForm() {
    const targetId = Number(state.selectedAreaId);
    const area = state.areas.find(a => Number(a.id) === targetId);

    if (!area) {
        elements.areaNoSelectionMsg.style.display = 'block';
        elements.areaConfigForm.style.display = 'none';
        elements.selectedAreaLabel.textContent = '未選択';
        elements.deleteSelectedAreaBtn.style.display = 'none';
        return;
    }

    elements.areaNoSelectionMsg.style.display = 'none';
    elements.areaConfigForm.style.display = 'block';
    elements.deleteSelectedAreaBtn.style.display = 'inline-block';

    const index = state.areas.findIndex(a => Number(a.id) === targetId);
    elements.selectedAreaLabel.textContent = `枠 ${index + 1}`;

    updateCoordsDisplay(area);

    // 1. 各入力欄に保存されている値を正確に反映
    if (!area.action) area.action = { type: 'postback', data: 'action=search_all', displayText: '' };
    elements.postbackDataInput.value = area.action.data || '';
    elements.postbackDisplayTextInput.value = area.action.displayText || '';
    elements.uriInput.value = area.action.uri || '';
    elements.messageTextInput.value = area.action.text || '';

    // 切替先メニューのプルダウン一覧を更新
    if (elements.switchMenuSelect) {
        elements.switchMenuSelect.innerHTML = '<option value="">-- 切替先メニューを選択 --</option>';
        
        // お知らせメニュー編集時、または自動分岐を推奨するオプション
        const autoBranchOpt = document.createElement('option');
        autoBranchOpt.value = 'default_branch';
        autoBranchOpt.textContent = '🔀 【自動分岐】専用メッセージ者は専用帯付き / 通常の方は本番メニューへ (推奨)';
        elements.switchMenuSelect.appendChild(autoBranchOpt);

        (state.historyList || []).forEach(m => {
            const aliasVal = m.alias_id || ('rm_' + m.id);
            const isLive = (m.is_active == 1 || (m.line_menu_id && m.line_menu_id === state.currentLineDefaultId));
            const opt = document.createElement('option');
            opt.value = aliasVal;
            opt.textContent = (isLive ? '★ [本番中] ' : '') + m.title + (m.is_notice == 1 ? ' (お知らせ)' : '');
            if (area.action.richMenuAliasId === aliasVal) {
                opt.selected = true;
            }
            elements.switchMenuSelect.appendChild(opt);
        });

        if (area.action.type === 'richmenuswitch') {
            if (area.action.richMenuAliasId) {
                elements.switchMenuSelect.value = area.action.richMenuAliasId;
            } else if (state.isNotice) {
                elements.switchMenuSelect.value = 'default_branch';
            }
        }
    }

    if (elements.switchBranchCustomCheck) {
        elements.switchBranchCustomCheck.checked = (area.action.branchCustom !== false);
    }

    // 2. アクション種別のラジオボタンを選択
    const actionType = area.action.type || 'postback';
    elements.actionTypeRadios.forEach(radio => {
        radio.checked = (radio.value === actionType);
    });

    // 3. フィールド表示の切替のみを実行（syncCurrentAreaFromFormで上書きさせない）
    showActionFieldGroup(actionType);
}

function updateCoordsDisplay(area) {
    if (!area || !area.bounds) return;
    if (elements.inputCoordX) elements.inputCoordX.value = Math.round(area.bounds.x);
    if (elements.inputCoordY) elements.inputCoordY.value = Math.round(area.bounds.y);
    if (elements.inputCoordW) elements.inputCoordW.value = Math.round(area.bounds.width);
    if (elements.inputCoordH) elements.inputCoordH.value = Math.round(area.bounds.height);
}

function syncCoordsFromInputs() {
    const area = state.areas.find(a => Number(a.id) === Number(state.selectedAreaId));
    if (!area || !area.bounds) return;

    let x = parseInt(elements.inputCoordX.value, 10);
    let y = parseInt(elements.inputCoordY.value, 10);
    let w = parseInt(elements.inputCoordW.value, 10);
    let h = parseInt(elements.inputCoordH.value, 10);

    if (isNaN(x)) x = 0;
    if (isNaN(y)) y = 0;
    if (isNaN(w) || w < 20) w = 20;
    if (isNaN(h) || h < 20) h = 20;

    x = Math.max(0, Math.min(state.width - 20, x));
    y = Math.max(0, Math.min(state.height - 20, y));
    if (x + w > state.width) w = state.width - x;
    if (y + h > state.height) h = state.height - y;

    area.bounds.x = x;
    area.bounds.y = y;
    area.bounds.width = w;
    area.bounds.height = h;

    const box = elements.stageOverlay.querySelector(`.area-box[data-id="${area.id}"]`);
    if (box) {
        box.style.left = ((x / state.width) * 100) + '%';
        box.style.top = ((y / state.height) * 100) + '%';
        box.style.width = ((w / state.width) * 100) + '%';
        box.style.height = ((h / state.height) * 100) + '%';
    }
}

function showActionFieldGroup(type) {
    elements.fieldPostback.style.display = (type === 'postback') ? 'block' : 'none';
    elements.fieldUri.style.display = (type === 'uri') ? 'block' : 'none';
    elements.fieldMessage.style.display = (type === 'message') ? 'block' : 'none';
    if (elements.fieldRichMenuSwitch) {
        elements.fieldRichMenuSwitch.style.display = (type === 'richmenuswitch') ? 'block' : 'none';
    }
}

function syncCurrentAreaFromForm() {
    const area = state.areas.find(a => Number(a.id) === Number(state.selectedAreaId));
    if (!area) return;

    let selectedType = 'postback';
    elements.actionTypeRadios.forEach(r => {
        if (r.checked) selectedType = r.value;
    });

    if (!area.action) area.action = {};
    area.action.type = selectedType;

    if (selectedType === 'postback') {
        area.action.data = elements.postbackDataInput.value.trim() || 'action=search_all';
        area.action.displayText = elements.postbackDisplayTextInput.value.trim();
    } else if (selectedType === 'uri') {
        area.action.uri = elements.uriInput.value.trim() || 'https://www.goo-net.com';
    } else if (selectedType === 'message') {
        area.action.text = elements.messageTextInput.value.trim() || 'メニュー';
    } else if (selectedType === 'richmenuswitch') {
        let swAlias = (elements.switchMenuSelect ? elements.switchMenuSelect.value : '') || '';
        const branchCustom = elements.switchBranchCustomCheck ? elements.switchBranchCustomCheck.checked : true;
        
        // default_branch が選ばれた場合、本番公開中メニューのエイリアス、または最初の通常メニューのエイリアスを自動特定
        if (swAlias === 'default_branch' || !swAlias) {
            const normalLiveMenu = (state.historyList || []).find(m => (!m.is_notice || m.is_notice == 0) && (m.is_active == 1 || m.line_menu_id === state.currentLineDefaultId)) 
                || (state.historyList || []).find(m => (!m.is_notice || m.is_notice == 0));
            swAlias = normalLiveMenu ? (normalLiveMenu.alias_id || ('rm_' + normalLiveMenu.id)) : '';
        }

        area.action.richMenuAliasId = swAlias;
        area.action.branchCustom = branchCustom;
        const fromNoticeFlag = (state.isNotice ? '&from_notice=1' : '');
        const branchFlag = (branchCustom ? '&branch_custom=1' : '');
        area.action.data = swAlias 
            ? `action=richmenu_switched&to_alias=${encodeURIComponent(swAlias)}${fromNoticeFlag}${branchFlag}` 
            : `action=richmenu_switched${fromNoticeFlag}${branchFlag}`;
    }

    updateAreaBadgeAndPill(area);
}

// ================= 装飾テキスト・お知らせバナー管理 =================
// 幅2500px基準のフォントサイズマスター定数 (Canvas合成とエディタプレビューで100%比率同期)
const OVERLAY_FONT_SIZES = {
    sm: 75,   // 小 (長文・補足向け / 2500px時 75px / スマホ換算 約11.2px)
    md: 100,  // 中 (標準ニュース・告知向け / 2500px時 100px / スマホ換算 約15px)
    lg: 135,  // 大 (強調タイトル・バッジ向け / 2500px時 135px / スマホ換算 約20.2px)
    xl: 175   // 特大 (SALE・NEW等キャッチ向け / 2500px時 175px / スマホ換算 約26.2px)
};

function renderTextOverlays() {
    if (!elements.textOverlaysStage) return;
    elements.textOverlaysStage.innerHTML = '';

    // 現在のエディタステージの表示幅を取得し、原寸(state.width, 通常2500px)に対するスケール比を計算
    const stageW = elements.stageImage.clientWidth || elements.canvasStage.clientWidth || 600;
    const originalW = Number(state.width) || 2500;
    const scale = stageW / originalW;

    state.textOverlays.forEach((overlay) => {
        const text = (overlay.text || '').trim();
        if (!text) return;

        const el = document.createElement('div');
        const type = overlay.type || 'banner_top';
        const theme = overlay.theme || 'red';
        const sizeKey = overlay.size || 'md';

        el.className = `overlay-text-item ${type} overlay-theme-${theme}`;
        el.dataset.id = String(overlay.id);

        // 画像原寸(2500px)に対する比率をそのまま縮小し、エディタ上でもCanvasと100%同一サイズで表示
        const baseFontSize = OVERLAY_FONT_SIZES[sizeKey] || OVERLAY_FONT_SIZES.md;
        const previewFontSize = Math.max(8, Math.round(baseFontSize * scale));
        el.style.fontSize = previewFontSize + 'px';

        const lines = text.split('\n');
        const lineCount = lines.length;
        const lineHeightPx = Math.round(previewFontSize * 1.35);

        if (type === 'banner_top') {
            const paddingY = Math.round(previewFontSize * 0.45);
            const bannerH = lineCount * lineHeightPx + paddingY * 2;
            el.style.left = '0';
            el.style.top = '0';
            el.style.width = '100%';
            el.style.height = bannerH + 'px';
            el.style.lineHeight = lineHeightPx + 'px';
            el.style.padding = `${paddingY}px ${Math.round(previewFontSize * 0.4)}px`;
            el.style.whiteSpace = 'pre-wrap';
        } else if (type === 'banner_bottom') {
            const paddingY = Math.round(previewFontSize * 0.45);
            const bannerH = lineCount * lineHeightPx + paddingY * 2;
            el.style.left = '0';
            el.style.bottom = '0';
            el.style.width = '100%';
            el.style.height = bannerH + 'px';
            el.style.lineHeight = lineHeightPx + 'px';
            el.style.padding = `${paddingY}px ${Math.round(previewFontSize * 0.4)}px`;
            el.style.whiteSpace = 'pre-wrap';
        } else if (type === 'badge') {
            const leftPercent = ((overlay.x || 60) / (state.width || 2500)) * 100;
            const topPercent = ((overlay.y || 60) / (state.height || 1686)) * 100;
            el.style.left = leftPercent + '%';
            el.style.top = topPercent + '%';
            el.style.lineHeight = lineHeightPx + 'px';
            el.style.padding = `${Math.round(previewFontSize * 0.35)}px ${Math.round(previewFontSize * 0.7)}px`;
            el.style.borderRadius = `${Math.round(previewFontSize * 0.9)}px`;
            el.style.whiteSpace = 'pre-wrap';
        } else if (type === 'free') {
            const leftPercent = ((overlay.x || 60) / (state.width || 2500)) * 100;
            const topPercent = ((overlay.y || 60) / (state.height || 1686)) * 100;
            el.style.left = leftPercent + '%';
            el.style.top = topPercent + '%';
            el.style.lineHeight = lineHeightPx + 'px';
            el.style.padding = `${Math.round(previewFontSize * 0.3)}px ${Math.round(previewFontSize * 0.5)}px`;
            el.style.whiteSpace = 'pre-wrap';
        }
        el.textContent = text;

        // タップ時アクションが設定されている場合の視覚的インジケータ表示
        if (overlay.action && overlay.action.type && overlay.action.type !== 'none') {
            el.classList.add('has-action');
            const ind = document.createElement('span');
            ind.className = 'overlay-action-indicator';
            if (overlay.action.type === 'postback') actionLabel = 'ポストバック';
            if (overlay.action.type === 'message') actionLabel = 'メッセージ';
            if (overlay.action.type === 'richmenuswitch') actionLabel = 'メニュー切替';
            ind.innerHTML = `<i class="fa-solid fa-bolt"></i> ${actionLabel}`;
            el.appendChild(ind);
        }

        // 掴んで自由に移動するためのドラッグイベントリスナー
        el.addEventListener('pointerdown', (e) => {
            e.stopPropagation(); // 下層のステージ枠選択や枠作成を防止
            e.preventDefault();

            const stageRect = elements.canvasStage.getBoundingClientRect();
            if (stageRect.width === 0 || stageRect.height === 0) return;

            state.isOverlayDragging = true;
            state.dragOverlayItem = overlay;
            state.dragOverlayEl = el;
            el.classList.add('dragging');

            try {
                el.setPointerCapture(e.pointerId);
            } catch (err) {}

            const origW = Number(state.width) || 2500;
            const origH = Number(state.height) || 1686;

            // もし上部帯や下部帯だった場合、掴んで動かしたら自動的に「自由配置(free)」に昇格して任意位置へ移動可能にする
            if (overlay.type === 'banner_top' || overlay.type === 'banner_bottom') {
                overlay.type = 'free';
                const elRect = el.getBoundingClientRect();
                const relX = ((elRect.left - stageRect.left) / stageRect.width) * origW;
                const relY = ((elRect.top - stageRect.top) / stageRect.height) * origH;
                overlay.x = Math.round(Math.max(10, Math.min(origW - 200, relX)));
                overlay.y = Math.round(Math.max(10, Math.min(origH - 100, relY)));
                el.className = `overlay-text-item free overlay-theme-${overlay.theme || 'red'} dragging`;
                el.style.width = 'auto';
                el.style.height = 'auto';
                el.style.bottom = 'auto';
                el.style.right = 'auto';
                el.style.lineHeight = 'normal';
                renderTextOverlayControls(); // カード側も自由配置に切り替え
            }

            const currentX = Number(overlay.x || 60);
            const currentY = Number(overlay.y || 60);

            state.overlayDragStart = {
                pointerX: e.clientX,
                pointerY: e.clientY,
                initX: currentX,
                initY: currentY,
                stageW: stageRect.width,
                stageH: stageRect.height
            };

            // 右側カードのハイライト＆自動スクロール
            highlightOverlayCard(overlay.id);
        });

        const releaseOverlayDrag = (ev) => {
            try {
                if (ev && ev.pointerId) el.releasePointerCapture(ev.pointerId);
            } catch (err) {}
            if (state.isOverlayDragging) {
                state.isOverlayDragging = false;
                if (state.dragOverlayEl) {
                    state.dragOverlayEl.classList.remove('dragging');
                }
                state.dragOverlayItem = null;
                state.dragOverlayEl = null;
                state.overlayDragStart = null;
            }
        };
        el.addEventListener('pointerup', releaseOverlayDrag);
        el.addEventListener('pointercancel', releaseOverlayDrag);

        elements.textOverlaysStage.appendChild(el);
    });
}

function highlightOverlayCard(overlayId) {
    if (!elements.textOverlayList) return;
    const cards = elements.textOverlayList.querySelectorAll('.text-overlay-card');
    cards.forEach(c => {
        if (Number(c.dataset.id) === Number(overlayId)) {
            c.classList.add('highlighted');
            c.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            c.classList.remove('highlighted');
        }
    });
}

function syncOverlayInputs(overlayId, x, y) {
    if (!elements.textOverlayList) return;
    const card = elements.textOverlayList.querySelector(`.text-overlay-card[data-id="${overlayId}"]`);
    if (!card) return;
    const posXInput = card.querySelector('.overlay-pos-x');
    const posYInput = card.querySelector('.overlay-pos-y');
    if (posXInput) posXInput.value = Math.round(x);
    if (posYInput) posYInput.value = Math.round(y);
}

function renderTextOverlayControls() {
    if (!elements.textOverlayList) return;
    elements.textOverlayList.innerHTML = '';

    if (!state.textOverlays || state.textOverlays.length === 0) {
        if (elements.textOverlayEmptyMsg) elements.textOverlayEmptyMsg.style.display = 'flex';
        return;
    }

    if (elements.textOverlayEmptyMsg) elements.textOverlayEmptyMsg.style.display = 'none';

    state.textOverlays.forEach((overlay, idx) => {
        const card = document.createElement('div');
        card.className = 'text-overlay-card';
        card.dataset.id = String(overlay.id);

        const typeLabels = {
            banner_top: '上部帯',
            banner_bottom: '下部帯',
            badge: 'バッジ',
            free: '自由'
        };

        const action = overlay.action || { type: 'none', uri: '', data: '', displayText: '', text: '' };
        overlay.action = action;
        const actionType = action.type || 'none';

        card.innerHTML = `
            <div class="card-header-row">
                <span class="overlay-card-title">
                    <i class="fa-solid fa-tag"></i> テキスト ${idx + 1}
                    <span class="badge-opt">${typeLabels[overlay.type] || '上部帯'}</span>
                </span>
                <button type="button" class="btn-delete-card-xs" data-id="${overlay.id}" title="削除">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>

            <textarea class="overlay-text-input" rows="2" placeholder="お知らせ文言を入力 (Enterキーで改行できます)">${escapeHtml(overlay.text)}</textarea>

            <div class="phrase-chips-row">
                <button type="button" class="phrase-chip" data-phrase="📢 臨時休業のお知らせ">📢 臨時休業</button>
                <button type="button" class="phrase-chip" data-phrase="🎉 秋の大感謝祭 開催中！">🎉 セール開催</button>
                <button type="button" class="phrase-chip" data-phrase="🚗 今週の新着特選車 5台入庫！">🚗 新着入庫</button>
                <button type="button" class="phrase-chip" data-phrase="✨ おすすめ特選目玉車！">✨ おすすめ</button>
                <button type="button" class="phrase-chip" data-phrase="🔥 月末限定 Special Price！">🔥 限定特価</button>
                <button type="button" class="phrase-chip" data-phrase="🛠️ 車検・点検 24時間WEB受付中">🛠️ 点検受付</button>
            </div>

            <div class="overlay-options-grid">
                <div>
                    <span class="option-group-label">配置タイプ:</span>
                    <div class="type-buttons-group">
                        <button type="button" class="btn-type-pill ${overlay.type === 'banner_top' ? 'active' : ''}" data-type="banner_top">上部帯</button>
                        <button type="button" class="btn-type-pill ${overlay.type === 'banner_bottom' ? 'active' : ''}" data-type="banner_bottom">下部帯</button>
                        <button type="button" class="btn-type-pill ${overlay.type === 'badge' ? 'active' : ''}" data-type="badge">バッジ</button>
                        <button type="button" class="btn-type-pill ${overlay.type === 'free' ? 'active' : ''}" data-type="free">自由</button>
                    </div>
                </div>

                <div>
                    <span class="option-group-label">カラーテーマ:</span>
                    <div class="color-chips-group">
                        <button type="button" class="color-chip-btn theme-red ${overlay.theme === 'red' ? 'active' : ''}" data-theme="red" title="赤 (注目・緊急)"></button>
                        <button type="button" class="color-chip-btn theme-green ${overlay.theme === 'green' ? 'active' : ''}" data-theme="green" title="緑 (LINE・新着)"></button>
                        <button type="button" class="color-chip-btn theme-dark ${overlay.theme === 'dark' ? 'active' : ''}" data-theme="dark" title="黒 (シック・高級)"></button>
                        <button type="button" class="color-chip-btn theme-blue ${overlay.theme === 'blue' ? 'active' : ''}" data-theme="blue" title="青 (案内)"></button>
                        <button type="button" class="color-chip-btn theme-yellow ${overlay.theme === 'yellow' ? 'active' : ''}" data-theme="yellow" title="黄 (警告・セール)"></button>
                        <button type="button" class="color-chip-btn theme-white ${overlay.theme === 'white' ? 'active' : ''}" data-theme="white" title="白 (シンプル)"></button>
                    </div>
                </div>
            </div>

            <div class="overlay-options-grid" style="margin-top: 8px;">
                <div>
                    <span class="option-group-label">文字サイズ:</span>
                    <select class="select-xs overlay-size-select">
                        <option value="sm" ${overlay.size === 'sm' ? 'selected' : ''}>小 (標準・すっきり)</option>
                        <option value="md" ${overlay.size === 'md' || !overlay.size ? 'selected' : ''}>中 (おすすめ・見やすい)</option>
                        <option value="lg" ${overlay.size === 'lg' ? 'selected' : ''}>大 (目立つ・アピール)</option>
                        <option value="xl" ${overlay.size === 'xl' ? 'selected' : ''}>特大 (超特大テロップ)</option>
                    </select>
                </div>
                ${(overlay.type === 'badge' || overlay.type === 'free') ? `
                <div style="display: flex; gap: 6px; align-items: flex-end; flex-wrap: wrap;">
                    <div>
                        <span class="option-group-label">X:</span>
                        <input type="number" class="coord-field-xs overlay-pos-x" value="${Math.round(overlay.x || 60)}" style="width: 52px; font-size: 11px; padding: 3px; border: 1px solid #cbd5e1; border-radius: 4px;">
                    </div>
                    <div>
                        <span class="option-group-label">Y:</span>
                        <input type="number" class="coord-field-xs overlay-pos-y" value="${Math.round(overlay.y || 60)}" style="width: 52px; font-size: 11px; padding: 3px; border: 1px solid #cbd5e1; border-radius: 4px;">
                    </div>
                    <div style="font-size: 10px; color: #06C755; font-weight: 700; padding-bottom: 4px;" title="プレビュー上の文字を直接ドラッグして移動できます">
                        <i class="fa-solid fa-arrows-up-down-left-right"></i> 直接ドラッグ可
                    </div>
                </div>
                ` : ''}
            </div>

            <!-- タップ時アクション設定エリア -->
            <div class="overlay-action-section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                    <span class="option-group-label" style="margin-bottom: 0;">
                        <i class="fa-solid fa-hand-pointer" style="color: #06C755;"></i> タップ時のアクション:
                    </span>
                    <span class="action-active-badge ${actionType !== 'none' ? 'active' : ''}">
                        ${actionType === 'none' ? 'なし（下層枠が反応）' : (actionType === 'uri' ? '🔗 URL' : (actionType === 'postback' ? '⚡ ポストバック' : (actionType === 'richmenuswitch' ? '📋 メニュー切替' : '💬 メッセージ')))}
                    </span>
                </div>
                <div class="type-buttons-group overlay-action-type-group">
                    <button type="button" class="btn-type-pill btn-action-type ${actionType === 'none' ? 'active' : ''}" data-atype="none">なし</button>
                    <button type="button" class="btn-type-pill btn-action-type ${actionType === 'uri' ? 'active' : ''}" data-atype="uri">URL</button>
                    <button type="button" class="btn-type-pill btn-action-type ${actionType === 'postback' ? 'active' : ''}" data-atype="postback">ポストバック</button>
                    <button type="button" class="btn-type-pill btn-action-type ${actionType === 'message' ? 'active' : ''}" data-atype="message">メッセージ</button>
                    <button type="button" class="btn-type-pill btn-action-type ${actionType === 'richmenuswitch' ? 'active' : ''}" data-atype="richmenuswitch">切替</button>
                </div>

                <!-- URI 入力 -->
                <div class="overlay-action-field-group atype-field-uri" style="display: ${actionType === 'uri' ? 'block' : 'none'}; margin-top: 6px;">
                    <input type="url" class="overlay-action-uri-input" value="${escapeHtml(action.uri || '')}" placeholder="https://example.com" style="width: 100%; box-sizing: border-box; font-size: 11px; padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                    <div class="phrase-chips-row" style="margin-top: 4px; margin-bottom: 0;">
                        <button type="button" class="phrase-chip overlay-action-chip" data-atype="uri" data-val="https://www.goo-net.com/usedcar_shop/0205244/stock.html">Goo-net在庫</button>
                        <button type="button" class="phrase-chip overlay-action-chip" data-atype="uri" data-stamp="1">🎫 スタンプカード</button>
                    </div>
                </div>

                <!-- Postback 入力 -->
                <div class="overlay-action-field-group atype-field-postback" style="display: ${actionType === 'postback' ? 'block' : 'none'}; margin-top: 6px;">
                    <input type="text" class="overlay-action-data-input" value="${escapeHtml(action.data || '')}" placeholder="action=search_all" style="width: 100%; box-sizing: border-box; font-size: 11px; padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                    <div class="phrase-chips-row" style="margin-top: 4px; margin-bottom: 0;">
                        <button type="button" class="phrase-chip overlay-action-chip" data-atype="postback" data-val="action=search_all">在庫全台</button>
                        <button type="button" class="phrase-chip overlay-action-chip" data-atype="postback" data-val="action=open_mycar">点検WEB予約</button>
                        <button type="button" class="phrase-chip overlay-action-chip" data-atype="postback" data-val="action=show_price_menu">価格別</button>
                        <button type="button" class="phrase-chip overlay-action-chip" data-atype="postback" data-val="action=show_type_menu">車種別</button>
                    </div>
                </div>

                <!-- Message 入力 -->
                <div class="overlay-action-field-group atype-field-message" style="display: ${actionType === 'message' ? 'block' : 'none'}; margin-top: 6px;">
                    <input type="text" class="overlay-action-text-input" value="${escapeHtml(action.text || '')}" placeholder="タップ時に送信するメッセージ" style="width: 100%; box-sizing: border-box; font-size: 11px; padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                </div>

                <!-- RichMenuSwitch 入力 -->
                <div class="overlay-action-field-group atype-field-richmenuswitch" style="display: ${actionType === 'richmenuswitch' ? 'block' : 'none'}; margin-top: 6px;">
                    <select class="overlay-action-switch-select" style="width: 100%; box-sizing: border-box; font-size: 11px; padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 4px;">
                        <option value="">-- 切替先メニューを選択 --</option>
                        ${(state.historyList || []).map(m => {
                            const val = m.alias_id || ('rm_' + m.id);
                            const isLive = (m.is_active == 1 || (m.line_menu_id && m.line_menu_id === state.currentLineDefaultId));
                            return `<option value="${escapeHtml(val)}" ${(action.richMenuAliasId === val) ? 'selected' : ''}>${isLive ? '★ ' : ''}${escapeHtml(m.title)}</option>`;
                        }).join('')}
                    </select>
                </div>
            </div>
        `;

        // イベント: 文字入力
        const textInput = card.querySelector('.overlay-text-input');
        textInput.addEventListener('input', () => {
            overlay.text = textInput.value;
            renderTextOverlays();
        });

        // イベント: 定型文チップクリック
        card.querySelectorAll('.phrase-chip:not(.overlay-action-chip)').forEach(chip => {
            chip.addEventListener('click', () => {
                overlay.text = chip.dataset.phrase;
                textInput.value = overlay.text;
                renderTextOverlays();
            });
        });

        // イベント: 配置タイプ切り替え
        card.querySelectorAll('.type-buttons-group:not(.overlay-action-type-group) .btn-type-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                overlay.type = btn.dataset.type;
                renderTextOverlayControls();
                renderTextOverlays();
            });
        });

        // イベント: カラーテーマ切り替え
        card.querySelectorAll('.color-chip-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                overlay.theme = btn.dataset.theme;
                renderTextOverlayControls();
                renderTextOverlays();
            });
        });

        // イベント: サイズ選択
        const sizeSelect = card.querySelector('.overlay-size-select');
        sizeSelect.addEventListener('change', () => {
            overlay.size = sizeSelect.value;
            renderTextOverlays();
        });

        // イベント: X/Y座標入力
        const posXInput = card.querySelector('.overlay-pos-x');
        const posYInput = card.querySelector('.overlay-pos-y');
        if (posXInput) {
            posXInput.addEventListener('input', () => {
                overlay.x = parseInt(posXInput.value, 10) || 0;
                renderTextOverlays();
            });
        }
        if (posYInput) {
            posYInput.addEventListener('input', () => {
                overlay.y = parseInt(posYInput.value, 10) || 0;
                renderTextOverlays();
            });
        }

        // イベント: アクションタイプ切り替え
        card.querySelectorAll('.btn-action-type').forEach(btn => {
            btn.addEventListener('click', () => {
                const atype = btn.dataset.atype;
                overlay.action = overlay.action || {};
                overlay.action.type = atype;
                if (atype === 'uri' && !overlay.action.uri) {
                    overlay.action.uri = 'https://www.goo-net.com/usedcar_shop/0205244/stock.html';
                } else if (atype === 'postback' && !overlay.action.data) {
                    overlay.action.data = 'action=search_all';
                }
                renderTextOverlayControls();
                renderTextOverlays();
            });
        });

        // イベント: アクション入力同期
        const actionUriInput = card.querySelector('.overlay-action-uri-input');
        if (actionUriInput) {
            actionUriInput.addEventListener('input', () => {
                overlay.action = overlay.action || {};
                overlay.action.uri = actionUriInput.value.trim();
                renderTextOverlays();
            });
        }
        const actionDataInput = card.querySelector('.overlay-action-data-input');
        if (actionDataInput) {
            actionDataInput.addEventListener('input', () => {
                overlay.action = overlay.action || {};
                overlay.action.data = actionDataInput.value.trim();
                renderTextOverlays();
            });
        }
        const actionTextInput = card.querySelector('.overlay-action-text-input');
        if (actionTextInput) {
            actionTextInput.addEventListener('input', () => {
                overlay.action = overlay.action || {};
                overlay.action.text = actionTextInput.value.trim();
                renderTextOverlays();
            });
        }
        const actionSwitchSelect = card.querySelector('.overlay-action-switch-select');
        if (actionSwitchSelect) {
            actionSwitchSelect.addEventListener('change', () => {
                const swAlias = actionSwitchSelect.value || '';
                overlay.action = overlay.action || {};
                overlay.action.richMenuAliasId = swAlias;
                const fromNoticeFlag = (state.isNotice ? '&from_notice=1' : '');
                overlay.action.data = swAlias ? `action=richmenu_switched&to_alias=${encodeURIComponent(swAlias)}${fromNoticeFlag}&branch_custom=1` : `action=richmenu_switched${fromNoticeFlag}&branch_custom=1`;
                renderTextOverlays();
            });
        }

        // イベント: アクションクイックチップクリック
        card.querySelectorAll('.overlay-action-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                overlay.action = overlay.action || {};
                if (chip.dataset.stamp === '1') {
                    const savedUrl = localStorage.getItem('line_shopcard_url') || '';
                    if (savedUrl) {
                        overlay.action.uri = savedUrl;
                        if (actionUriInput) actionUriInput.value = savedUrl;
                        renderTextOverlays();
                        showToast('保存済みのLINEスタンプカードURLを設定しました', 'success');
                    } else {
                        const inputUrl = prompt('【LINE公式スタンプカードURL設定】\nスタンプカードURLを入力してください:');
                        if (inputUrl && inputUrl.trim()) {
                            const clean = inputUrl.trim();
                            localStorage.setItem('line_shopcard_url', clean);
                            overlay.action.uri = clean;
                            if (actionUriInput) actionUriInput.value = clean;
                            renderTextOverlays();
                            showToast('スタンプカードURLを設定しました！', 'success');
                        }
                    }
                    return;
                }

                if (chip.dataset.atype === 'uri' && chip.dataset.val) {
                    overlay.action.uri = chip.dataset.val;
                    if (actionUriInput) actionUriInput.value = chip.dataset.val;
                    renderTextOverlays();
                } else if (chip.dataset.atype === 'postback' && chip.dataset.val) {
                    overlay.action.data = chip.dataset.val;
                    if (actionDataInput) actionDataInput.value = chip.dataset.val;
                    renderTextOverlays();
                }
            });
        });

        // イベント: 削除
        card.querySelector('.btn-delete-card-xs').addEventListener('click', () => {
            deleteTextOverlay(overlay.id);
        });

        elements.textOverlayList.appendChild(card);
    });
}

function addTextOverlay(data = {}) {
    const newId = (state.textOverlays.length > 0 ? Math.max(...state.textOverlays.map(o => Number(o.id) || 0)) : 0) + 1;
    const item = {
        id: newId,
        text: data.text || '🎉 秋の大感謝祭 開催中！',
        type: data.type || (state.textOverlays.length === 0 ? 'banner_top' : 'badge'),
        theme: data.theme || (state.textOverlays.length === 0 ? 'red' : 'green'),
        size: data.size || 'md',
        x: data.x || 60,
        y: data.y || 60,
        action: data.action || { type: 'none', uri: '', data: '', displayText: '', text: '' }
    };
    state.textOverlays.push(item);
    renderTextOverlayControls();
    renderTextOverlays();
    showToast('装飾テキストを追加しました', 'info');
}

function deleteTextOverlay(id) {
    const numId = Number(id);
    state.textOverlays = state.textOverlays.filter(o => Number(o.id) !== numId);
    renderTextOverlayControls();
    renderTextOverlays();
}

async function compositeRichMenuImage() {
    if (!state.textOverlays || state.textOverlays.length === 0) {
        return state.imageFile || null;
    }

    const W = state.width;
    const H = state.height;
    const canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = H;
    const ctx = canvas.getContext('2d');

    // 1. ベース画像の描画
    await new Promise((resolve) => {
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = () => {
            ctx.drawImage(img, 0, 0, W, H);
            resolve();
        };
        img.onerror = () => {
            ctx.fillStyle = '#1e293b';
            ctx.fillRect(0, 0, W, H);
            resolve();
        };
        img.src = state.baseImageSrc || state.imageSrc;
    });

    // 2. Webフォント読み込み完了を待機
    try {
        if (document.fonts && document.fonts.ready) {
            await document.fonts.ready;
        }
    } catch (e) {}

    // 3. 各装飾テキストの合成描画
    const themeColors = {
        red: { bg: '#dc2626', text: '#ffffff', border: 'rgba(255,255,255,0.35)' },
        green: { bg: '#06C755', text: '#ffffff', border: 'rgba(255,255,255,0.35)' },
        dark: { bg: 'rgba(15, 23, 42, 0.92)', text: '#fef08a', border: 'rgba(234, 179, 8, 0.5)' },
        blue: { bg: '#2563eb', text: '#ffffff', border: 'rgba(255,255,255,0.35)' },
        yellow: { bg: '#f59e0b', text: '#0f172a', border: 'rgba(0,0,0,0.25)' },
        white: { bg: '#ffffff', text: '#0f172a', border: 'rgba(0,0,0,0.15)' }
    };

    state.textOverlays.forEach(overlay => {
        const text = (overlay.text || '').trim();
        if (!text) return;

        const theme = themeColors[overlay.theme] || themeColors.red;
        const canvasScale = W / 2500;
        const baseFontSize = OVERLAY_FONT_SIZES[overlay.size] || OVERLAY_FONT_SIZES.md;
        const fontSize = Math.round(baseFontSize * canvasScale);
        const type = overlay.type || 'banner_top';

        ctx.save();
        ctx.font = `800 ${fontSize}px "Noto Sans JP", sans-serif`;

        const lines = text.split('\n');
        const lineCount = lines.length;
        const lineHeight = Math.round(fontSize * 1.35);

        if (type === 'banner_top') {
            const paddingY = Math.round(fontSize * 0.45);
            const bannerH = lineCount * lineHeight + paddingY * 2;
            ctx.fillStyle = theme.bg;
            ctx.fillRect(0, 0, W, bannerH);
            ctx.fillStyle = theme.border;
            ctx.fillRect(0, bannerH - 4, W, 4);

            ctx.fillStyle = theme.text;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            const startY = paddingY + lineHeight / 2;
            lines.forEach((line, lIdx) => {
                ctx.fillText(line, W / 2, startY + lIdx * lineHeight);
            });
            overlay.exactBounds = { x: 0, y: 0, width: W, height: Math.min(H, bannerH) };
        } else if (type === 'banner_bottom') {
            const paddingY = Math.round(fontSize * 0.45);
            const bannerH = lineCount * lineHeight + paddingY * 2;
            const bannerY = H - bannerH;
            ctx.fillStyle = theme.bg;
            ctx.fillRect(0, bannerY, W, bannerH);
            ctx.fillStyle = theme.border;
            ctx.fillRect(0, bannerY, W, 4);

            ctx.fillStyle = theme.text;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            const startY = bannerY + paddingY + lineHeight / 2;
            lines.forEach((line, lIdx) => {
                ctx.fillText(line, W / 2, startY + lIdx * lineHeight);
            });
            overlay.exactBounds = { x: 0, y: Math.max(0, bannerY), width: W, height: Math.min(H, bannerH) };
        } else if (type === 'badge') {
            let maxLineW = 0;
            lines.forEach(l => {
                const w = ctx.measureText(l).width;
                if (w > maxLineW) maxLineW = w;
            });
            const paddingX = Math.round(fontSize * 0.7);
            const paddingY = Math.round(fontSize * 0.35);
            const badgeW = Math.round(maxLineW + paddingX * 2);
            const badgeH = Math.round(lineCount * lineHeight + paddingY * 2);
            const origW = Number(state.width) || 2500;
            const origH = Number(state.height) || 1686;
            const posX = Math.round(((overlay.x || 60) / origW) * W);
            const posY = Math.round(((overlay.y || 60) / origH) * H);

            // ドロップシャドウ
            ctx.shadowColor = 'rgba(0, 0, 0, 0.4)';
            ctx.shadowBlur = 16;
            ctx.shadowOffsetY = 6;

            drawCanvasRoundRect(ctx, posX, posY, badgeW, badgeH, Math.min(badgeH / 2, Math.round(fontSize * 0.9)));
            ctx.fillStyle = theme.bg;
            ctx.fill();

            ctx.shadowColor = 'transparent';
            ctx.lineWidth = 4;
            ctx.strokeStyle = theme.border;
            ctx.stroke();

            ctx.fillStyle = theme.text;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            const startY = posY + paddingY + lineHeight / 2;
            lines.forEach((line, lIdx) => {
                ctx.fillText(line, posX + badgeW / 2, startY + lIdx * lineHeight);
            });
            overlay.exactBounds = {
                x: Math.max(0, posX),
                y: Math.max(0, posY),
                width: Math.min(W - Math.max(0, posX), badgeW),
                height: Math.min(H - Math.max(0, posY), badgeH)
            };
        } else if (type === 'free') {
            let maxLineW = 0;
            lines.forEach(l => {
                const w = ctx.measureText(l).width;
                if (w > maxLineW) maxLineW = w;
            });
            const paddingX = Math.round(fontSize * 0.5);
            const paddingY = Math.round(fontSize * 0.3);
            const boxW = Math.round(maxLineW + paddingX * 2);
            const boxH = Math.round(lineCount * lineHeight + paddingY * 2);
            const origW = Number(state.width) || 2500;
            const origH = Number(state.height) || 1686;
            const posX = Math.round(((overlay.x || 100) / origW) * W);
            const posY = Math.round(((overlay.y || 100) / origH) * H);

            ctx.shadowColor = 'rgba(0, 0, 0, 0.35)';
            ctx.shadowBlur = 14;
            ctx.shadowOffsetY = 4;

            drawCanvasRoundRect(ctx, posX, posY, boxW, boxH, Math.max(8, Math.round(fontSize * 0.2)));
            ctx.fillStyle = theme.bg;
            ctx.fill();

            ctx.shadowColor = 'transparent';
            ctx.lineWidth = 3;
            ctx.strokeStyle = theme.border;
            ctx.stroke();

            ctx.fillStyle = theme.text;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            const startY = posY + paddingY + lineHeight / 2;
            lines.forEach((line, lIdx) => {
                ctx.fillText(line, posX + boxW / 2, startY + lIdx * lineHeight);
            });
            overlay.exactBounds = {
                x: Math.max(0, posX),
                y: Math.max(0, posY),
                width: Math.min(W - Math.max(0, posX), boxW),
                height: Math.min(H - Math.max(0, posY), boxH)
            };
        }

        ctx.restore();
    });

    return new Promise((resolve) => {
        canvas.toBlob((blob) => {
            if (blob) {
                resolve(new File([blob], 'richmenu_composite.jpg', { type: 'image/jpeg' }));
            } else {
                resolve(state.imageFile || null);
            }
        }, 'image/jpeg', 0.92);
    });
}

function getOverlayBounds(overlay) {
    if (overlay.exactBounds) {
        return overlay.exactBounds;
    }
    const W = Number(state.width) || 2500;
    const H = Number(state.height) || 1686;
    const canvasScale = W / 2500;
    const baseFontSize = OVERLAY_FONT_SIZES[overlay.size] || OVERLAY_FONT_SIZES.md;
    const fontSize = Math.round(baseFontSize * canvasScale);
    const text = (overlay.text || '').trim();
    const lines = text.split('\n');
    const lineCount = lines.length;
    const lineHeight = Math.round(fontSize * 1.35);
    const type = overlay.type || 'banner_top';

    if (type === 'banner_top') {
        const paddingY = Math.round(fontSize * 0.45);
        const bannerH = lineCount * lineHeight + paddingY * 2;
        return { x: 0, y: 0, width: W, height: Math.min(H, bannerH) };
    } else if (type === 'banner_bottom') {
        const paddingY = Math.round(fontSize * 0.45);
        const bannerH = lineCount * lineHeight + paddingY * 2;
        const bannerY = Math.max(0, H - bannerH);
        return { x: 0, y: bannerY, width: W, height: Math.min(H, bannerH) };
    } else if (type === 'badge' || type === 'free') {
        let maxLineLen = 0;
        lines.forEach(l => { if (l.length > maxLineLen) maxLineLen = l.length; });
        const approxTextW = maxLineLen * fontSize * 0.95;
        const paddingX = Math.round(fontSize * (type === 'badge' ? 0.7 : 0.5));
        const paddingY = Math.round(fontSize * (type === 'badge' ? 0.35 : 0.3));
        const boxW = Math.round(approxTextW + paddingX * 2);
        const boxH = Math.round(lineCount * lineHeight + paddingY * 2);
        const posX = Math.round(overlay.x || 60);
        const posY = Math.round(overlay.y || 60);

        const safeX = Math.max(0, Math.min(W - 10, posX));
        const safeY = Math.max(0, Math.min(H - 10, posY));
        const safeW = Math.max(10, Math.min(W - safeX, boxW));
        const safeH = Math.max(10, Math.min(H - safeY, boxH));
        return { x: safeX, y: safeY, width: safeW, height: safeH };
    }
    return { x: 0, y: 0, width: W, height: 100 };
}

function drawCanvasRoundRect(ctx, x, y, width, height, radius) {
    if (ctx.roundRect) {
        ctx.beginPath();
        ctx.roundRect(x, y, width, height, radius);
        return;
    }
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.lineTo(x + width - radius, y);
    ctx.quadraticCurveTo(x + width, y, x + width, y + radius);
    ctx.lineTo(x + width, y + height - radius);
    ctx.quadraticCurveTo(x + width, y + height, x + width - radius, y + height);
    ctx.lineTo(x + radius, y + height);
    ctx.quadraticCurveTo(x, y + height, x, y + height - radius);
    ctx.lineTo(x, y + radius);
    ctx.quadraticCurveTo(x, y, x + radius, y);
    ctx.closePath();
}

function dataURLtoBlob(dataurl) {
    const arr = dataurl.split(',');
    const mimeMatch = arr[0].match(/:(.*?);/);
    const mime = mimeMatch ? mimeMatch[1] : 'image/jpeg';
    const bstr = atob(arr[1]);
    let n = bstr.length;
    const u8arr = new Uint8Array(n);
    while (n--) {
        u8arr[n] = bstr.charCodeAt(n);
    }
    return new Blob([u8arr], { type: mime });
}

// ================= 保存 & LINE公開 =================
async function saveRichMenu(publish, asCopy = false) {
    const title = elements.menuTitleInput.value.trim();
    if (!title) {
        showToast('メニュー管理名を入力してください', 'error');
        elements.menuTitleInput.focus();
        return;
    }

    if (!state.imageFile && !state.imageSrc) {
        showToast('メニュー画像を設定してください', 'error');
        return;
    }

    if (state.areas.length === 0) {
        showToast('タップ領域（枠）を1つ以上設定してください', 'error');
        return;
    }

    const isEditing = !asCopy && state.editingMenuId;
    let loadingText = '画像合成＆下書きを保存中...';
    if (publish) {
        loadingText = isEditing ? '画像合成＆LINE公式アカウントを更新中...' : '画像合成＆LINE公式アカウントに公開中...';
    } else if (asCopy) {
        loadingText = 'コピーを作成して新規保存中...';
    } else if (isEditing) {
        loadingText = '画像合成＆リッチメニューを上書き更新中...';
    }
    showLoading(loadingText);

    try {
        const hasOverlays = state.textOverlays && state.textOverlays.length > 0;
        let compositedImageFile = null;
        if (hasOverlays) {
            compositedImageFile = await compositeRichMenuImage();
        }

        // アクションが設定された装飾テキストを抽出して overlayAreas を生成
        const overlayAreas = [];
        (state.textOverlays || []).forEach(ov => {
            if (ov.action && ov.action.type && ov.action.type !== 'none') {
                const b = getOverlayBounds(ov);
                const safeX = Math.max(0, Math.round(b.x));
                const safeY = Math.max(0, Math.round(b.y));
                const safeW = Math.max(1, Math.min(state.width - safeX, Math.round(b.width)));
                const safeH = Math.max(1, Math.min(state.height - safeY, Math.round(b.height)));

                const actionObj = { type: ov.action.type };
                if (ov.action.type === 'uri') {
                    actionObj.uri = ov.action.uri || 'https://www.goo-net.com/usedcar_shop/0205244/stock.html';
                } else if (ov.action.type === 'postback') {
                    actionObj.data = ov.action.data || 'action=search_all';
                    if (ov.action.displayText) actionObj.displayText = ov.action.displayText;
                } else if (ov.action.type === 'message') {
                    actionObj.text = ov.action.text || ov.text || 'メニュー';
                } else if (ov.action.type === 'richmenuswitch') {
                    const swAlias = ov.action.richMenuAliasId || '';
                    actionObj.richMenuAliasId = swAlias;
                    const fromNoticeFlag = (state.isNotice ? '&from_notice=1' : '');
                    actionObj.data = ov.action.data || (swAlias ? `action=richmenu_switched&to_alias=${encodeURIComponent(swAlias)}${fromNoticeFlag}&branch_custom=1` : `action=richmenu_switched${fromNoticeFlag}&branch_custom=1`);
                }

                overlayAreas.push({
                    id: 'overlay_' + ov.id,
                    is_overlay: true,
                    bounds: { x: safeX, y: safeY, width: safeW, height: safeH },
                    action: actionObj
                });
            }
        });

        // LINE API は配列の前にあるエリアを優先判定するため、装飾テキストのタップ領域を先頭に配置
        // LINEのリッチメニュー仕様上限（20エリア）を超えないよう slice(0, 20)
        const combinedAreas = [...overlayAreas, ...state.areas].slice(0, 20);

        const formData = new FormData();
        formData.append('password', state.password);
        formData.append('title', title);
        formData.append('chat_bar_text', elements.chatBarTextInput.value.trim() || 'メニュー');
        formData.append('width', state.width);
        formData.append('height', state.height);
        formData.append('publish', publish ? '1' : '0');
        formData.append('areas', JSON.stringify(combinedAreas));
        formData.append('text_overlays', JSON.stringify(state.textOverlays || []));

        // 既存メニューの編集ならedit_idを送信（エイリアス引き継ぎ＆UPDATE）
        if (isEditing) {
            formData.append('edit_id', String(state.editingMenuId));
        }

        if (hasOverlays && compositedImageFile) {
            // LINEアップロード用: テキスト合成画像
            formData.append('image', compositedImageFile);

            // 編集復元用: クリーンな元画像（文字が焼き込まれていない画像）
            if (state.baseImageFile) {
                formData.append('base_image', state.baseImageFile);
            } else if (state.baseImageSrc) {
                if (state.baseImageSrc.startsWith('data:')) {
                    const blob = dataURLtoBlob(state.baseImageSrc);
                    formData.append('base_image', new File([blob], 'base_image.jpg', { type: 'image/jpeg' }));
                } else {
                    formData.append('existing_base_image_url', state.baseImageSrc);
                }
            }
        } else {
            // 装飾テキストが無い場合: 元画像がそのままLINE用かつ編集用
            if (state.baseImageFile || state.imageFile) {
                formData.append('image', state.baseImageFile || state.imageFile);
            } else if (state.baseImageSrc || state.imageSrc) {
                const targetSrc = state.baseImageSrc || state.imageSrc;
                if (targetSrc.startsWith('data:')) {
                    const blob = dataURLtoBlob(targetSrc);
                    formData.append('image', new File([blob], 'menu_image.jpg', { type: 'image/jpeg' }));
                } else {
                    formData.append('existing_image_url', targetSrc);
                }
            }
        }

        const res = await fetch('../api.php?action=admin_save_richmenu', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        hideLoading();
        if (data.success) {
            showToast(data.message || '保存が完了しました！', 'success');
            if (data.base_image_url) {
                state.baseImageSrc = data.base_image_url;
                state.imageSrc = data.base_image_url;
                state.baseImageFile = null;
                state.imageFile = null;
            }

            if (asCopy) {
                // コピー保存時は新メニューの編集モードに切り替える
                state.editingMenuId = data.id;
                state.editingMenuAliasId = data.alias_id || '';
                state.editingMenuTitle = title;
                updateEditingBanner();
            } else if (isEditing) {
                state.editingMenuTitle = title;
                state.editingMenuAliasId = data.alias_id || state.editingMenuAliasId;
                updateEditingBanner();
            } else if (data.id) {
                // 初回新規保存後も自動的に編集モードとしてIDを保持
                state.editingMenuId = data.id;
                state.editingMenuAliasId = data.alias_id || '';
                state.editingMenuTitle = title;
                updateEditingBanner();
            }

            loadHistoryList();
            if (publish) {
                switchView('history');
            }
        } else {
            showToast(data.error || '保存に失敗しました', 'error');
        }
    } catch (err) {
        hideLoading();
        showToast('通信エラーが発生しました: ' + err.message, 'error');
    }
}

// ================= 履歴管理 =================
function loadHistoryList() {
    const pass = state.password || sessionStorage.getItem('admin_pass') || '';
    if (!state.password && pass) {
        state.password = pass;
    }
    fetch('../api.php?action=admin_list_richmenus&password=' + encodeURIComponent(pass))
        .then(res => {
            if (!res.ok) {
                throw new Error(`HTTPエラー ${res.status}: ${res.statusText}`);
            }
            return res.json();
        })
        .then(data => {
            if (data.success) {
                state.historyList = data.menus || [];
                state.currentLineDefaultId = data.current_default_id;
                state.activeNoticeId = data.active_notice_id || null;
                renderHistoryList();
                updateLiveStatusBadge();
            } else {
                console.error('履歴データ取得失敗:', data.error);
                showToast(data.error || 'リッチメニュー履歴の取得に失敗しました', 'error');
            }
        })
        .catch(err => {
            console.error('履歴読み込みエラー:', err);
            showToast('リッチメニュー履歴の読み込みに失敗しました: ' + err.message, 'error');
        });
}

function updateLiveStatusBadge() {
    const liveMenu = state.historyList.find(m => m.is_active == 1 || (m.line_menu_id && m.line_menu_id === state.currentLineDefaultId));
    if (liveMenu) {
        elements.liveMenuName.textContent = liveMenu.title;
    } else {
        elements.liveMenuName.textContent = 'LINE公式で設定中または未適用';
    }
    elements.historyCount.textContent = state.historyList.length;
}

function renderHistoryList() {
    elements.historyGrid.innerHTML = '';

    const totalCount = state.historyList.length;
    const normalCount = state.historyList.filter(m => !m.is_notice || m.is_notice == 0).length;
    const noticeCount = state.historyList.filter(m => m.is_notice == 1).length;

    if (elements.countFilterAll) elements.countFilterAll.textContent = totalCount;
    if (elements.countFilterNormal) elements.countFilterNormal.textContent = normalCount;
    if (elements.countFilterNotice) elements.countFilterNotice.textContent = noticeCount;

    let filteredList = state.historyList;
    if (state.historyFilter === 'normal') {
        filteredList = state.historyList.filter(m => !m.is_notice || m.is_notice == 0);
    } else if (state.historyFilter === 'notice') {
        filteredList = state.historyList.filter(m => m.is_notice == 1);
    }

    if (filteredList.length === 0) {
        elements.historyEmpty.style.display = 'block';
        return;
    }

    elements.historyEmpty.style.display = 'none';

    filteredList.forEach(item => {
        const isLive = (item.is_active == 1 || (item.line_menu_id && item.line_menu_id === state.currentLineDefaultId));
        const isNotice = (item.is_notice == 1);
        const isActiveNotice = (isNotice && state.activeNoticeId && item.id == state.activeNoticeId);

        const card = document.createElement('div');
        card.className = 'history-card' + (isLive ? ' active-live' : '');

        const areaCount = item.areas ? item.areas.length : 0;
        const sizeLabel = (item.height == 843) ? '小 (2500×843)' : '大 (2500×1686)';

        card.innerHTML = `
            <div class="history-thumb-wrap">
                <img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.title)}" loading="lazy" onerror="if(this.dataset.retry!=='1'){this.dataset.retry='1';this.src='../api.php?action=richmenu_image&id=${item.id}';}">
                ${isLive ? '<span class="badge-live-now"><i class="fa-solid fa-circle-check"></i> 全体本番中</span>' : ''}
                ${isNotice ? '<span class="badge-notice-tag"><i class="fa-solid fa-bullhorn"></i> お知らせ専用</span>' : ''}
                ${isActiveNotice ? '<span class="badge-notice-live"><i class="fa-solid fa-bolt"></i> クイックリプライ連携中</span>' : ''}
                <span class="badge-size">${sizeLabel}</span>
            </div>
            <div class="history-body">
                <div class="history-title-row">
                    <h3 class="history-title" title="クリックして名前を変更">${escapeHtml(item.title)}</h3>
                    <button type="button" class="btn-edit-title" title="管理名を変更" data-id="${item.id}">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                </div>
                <div class="history-title-edit-form" style="display: none;">
                    <input type="text" class="input-title-edit" value="${escapeHtml(item.title)}" maxlength="100" placeholder="メニュー名を入力">
                    <button type="button" class="btn-save-title" title="保存"><i class="fa-solid fa-check"></i></button>
                    <button type="button" class="btn-cancel-title" title="キャンセル"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="history-meta">
                    <span><i class="fa-solid fa-clock"></i> 登録日時: ${escapeHtml(item.created_at || '-')}</span>
                    <span><i class="fa-solid fa-table-cells"></i> 設定エリア数: ${areaCount}枠</span>
                    <span><i class="fa-solid fa-comment-dots"></i> 下部バー表示: 「${escapeHtml(item.chat_bar_text || 'メニュー')}」</span>
                    ${item.alias_id ? `<span><i class="fa-solid fa-tag"></i> エイリアス: <code>${escapeHtml(item.alias_id)}</code></span>` : ''}
                </div>
                <div class="history-actions">
                    <button class="btn-set-active-notice ${isActiveNotice ? 'is-active' : ''}" data-id="${item.id}" ${isActiveNotice ? 'disabled' : ''} title="LINEのクイックリプライ「📢 お知らせ」を押した時にこのメニューを表示する">
                        ${isActiveNotice ? '<i class="fa-solid fa-check"></i> お知らせ連携中' : '<i class="fa-solid fa-bullhorn"></i> お知らせ連携に設定'}
                    </button>
                    <button class="btn-apply-card ${isLive ? 'disabled' : ''}" data-id="${item.id}" ${isLive ? 'disabled' : ''} title="LINE公式アカウント全体のデフォルトリッチメニューに設定">
                        ${isLive ? '<i class="fa-solid fa-check"></i> 全体本番公開中' : '<i class="fa-solid fa-paper-plane"></i> 全体本番に適用'}
                    </button>
                    <button class="btn-edit-card" data-id="${item.id}" title="エディタに読み込んで編集・複製">
                        <i class="fa-solid fa-pen-to-square"></i> 編集
                    </button>
                    <button class="btn-delete-card" data-id="${item.id}" title="削除">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
        `;

        // 管理名（タイトル）インライン変更
        const titleRow = card.querySelector('.history-title-row');
        const titleElem = card.querySelector('.history-title');
        const editTitleBtn = card.querySelector('.btn-edit-title');
        const editForm = card.querySelector('.history-title-edit-form');
        const titleInput = card.querySelector('.input-title-edit');
        const saveTitleBtn = card.querySelector('.btn-save-title');
        const cancelTitleBtn = card.querySelector('.btn-cancel-title');

        const openTitleEdit = () => {
            titleRow.style.display = 'none';
            editForm.style.display = 'flex';
            titleInput.value = item.title;
            titleInput.focus();
            titleInput.select();
        };

        const closeTitleEdit = () => {
            editForm.style.display = 'none';
            titleRow.style.display = 'flex';
        };

        titleElem.addEventListener('click', openTitleEdit);
        editTitleBtn.addEventListener('click', openTitleEdit);
        cancelTitleBtn.addEventListener('click', closeTitleEdit);

        const saveNewTitle = async () => {
            const newTitle = titleInput.value.trim();
            if (!newTitle) {
                showToast('リッチメニュー名を入力してください', 'error');
                titleInput.focus();
                return;
            }
            if (newTitle === item.title) {
                closeTitleEdit();
                return;
            }

            saveTitleBtn.disabled = true;
            saveTitleBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

            const formData = new FormData();
            formData.append('password', state.password);
            formData.append('id', item.id);
            formData.append('title', newTitle);

            try {
                const res = await fetch('../api.php?action=admin_rename_richmenu', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                saveTitleBtn.disabled = false;
                saveTitleBtn.innerHTML = '<i class="fa-solid fa-check"></i>';

                if (data.success) {
                    item.title = newTitle;
                    titleElem.textContent = newTitle;
                    showToast('リッチメニュー名を変更しました', 'success');
                    closeTitleEdit();
                    updateLiveStatusBadge();
                } else {
                    showToast(data.error || '名前の変更に失敗しました', 'error');
                }
            } catch (err) {
                saveTitleBtn.disabled = false;
                saveTitleBtn.innerHTML = '<i class="fa-solid fa-check"></i>';
                showToast('通信エラーが発生しました: ' + err.message, 'error');
            }
        };

        saveTitleBtn.addEventListener('click', saveNewTitle);
        titleInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveNewTitle();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeTitleEdit();
            }
        });

        // クイックリプライ連携ボタン (お知らせメニューのみ)
        const activeNoticeBtn = card.querySelector('.btn-set-active-notice');
        if (activeNoticeBtn && !isActiveNotice) {
            activeNoticeBtn.addEventListener('click', () => setActiveNotice(item.id, item.title));
        }

        // 本番適用ボタン
        const applyBtn = card.querySelector('.btn-apply-card');
        if (!isLive) {
            applyBtn.addEventListener('click', () => applyMenuToLive(item.id, item.title));
        }

        // 編集ボタン
        card.querySelector('.btn-edit-card').addEventListener('click', () => loadMenuIntoEditor(item));

        // 削除ボタン
        card.querySelector('.btn-delete-card').addEventListener('click', () => deleteHistoryMenu(item.id, item.title));

        elements.historyGrid.appendChild(card);
    });
}

function applyMenuToLive(id, title) {
    if (!confirm(`「${title}」をLINE公式アカウントの本番リッチメニューに適用しますか？\n（友だち全員のトーク画面が即座に切り替わります）`)) {
        return;
    }

    showLoading('LINE公式アカウントに適用中...');

    const formData = new FormData();
    formData.append('password', state.password);
    formData.append('id', id);

    fetch('../api.php?action=admin_apply_richmenu', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showToast(data.message || '本番に適用しました！', 'success');
            loadHistoryList();
        } else {
            showToast(data.error || '適用に失敗しました', 'error');
        }
    })
    .catch(err => {
        hideLoading();
        showToast('通信エラーが発生しました: ' + err.message, 'error');
    });
}

async function setActiveNotice(id, title) {
    if (!id) return;
    showLoading('クイックリプライ連携を設定中...');
    const formData = new FormData();
    formData.append('password', state.password || sessionStorage.getItem('admin_pass') || '');
    formData.append('id', String(id));

    try {
        const res = await fetch('../api.php?action=admin_set_active_notice', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        hideLoading();
        if (data.success) {
            showToast(data.message || 'お知らせメニューをクイックリプライ連携に設定しました！', 'success');
            loadHistoryList();
        } else {
            showToast(data.error || '設定に失敗しました', 'error');
        }
    } catch (err) {
        hideLoading();
        showToast('通信エラーが発生しました: ' + err.message, 'error');
    }
}

function deleteHistoryMenu(id, title) {
    if (!confirm(`リッチメニュー「${title}」を削除しますか？\n（LINE側の登録データおよび画像も削除されます）`)) {
        return;
    }

    showLoading('削除中...');

    const formData = new FormData();
    formData.append('password', state.password);
    formData.append('id', id);

    fetch('../api.php?action=admin_delete_richmenu', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showToast(data.message || '削除しました', 'success');
            loadHistoryList();
        } else {
            showToast(data.error || '削除に失敗しました', 'error');
        }
    })
    .catch(err => {
        hideLoading();
        showToast('通信エラーが発生しました: ' + err.message, 'error');
    });
}

function loadMenuIntoEditor(item) {
    // 1. 先にエディタビューを表示状態に切り替え (DOM要素を表示してサイズ計算を保証)
    switchView('editor');

    // 編集中状態を設定（既存ID・エイリアスを引き継ぎ、コピーを作らず上書き更新）
    state.editingMenuId = item.id;
    state.editingMenuAliasId = item.alias_id || '';
    state.editingMenuTitle = item.title || '';

    elements.menuTitleInput.value = item.title || '';
    elements.chatBarTextInput.value = item.chat_bar_text || 'メニュー';
    setMenuSize(item.height == 843 ? 'small' : 'large');

    // サイズボタンの見た目同期
    elements.sizeToggleBtns.forEach(btn => {
        btn.classList.toggle('active', btn.dataset.size === (item.height == 843 ? 'small' : 'large'));
    });

    state.imageFile = null;
    state.baseImageFile = null;
    const cleanImageUrl = item.base_image_url || item.image_url;
    state.imageSrc = cleanImageUrl;
    state.baseImageSrc = cleanImageUrl;

    // 2. エリア配列のIDと数値を安全に再構築 (装飾文字合成用の一時エリアを除外)
    state.areas = (item.areas || [])
        .filter(a => !a.is_overlay && !String(a.id || '').startsWith('overlay_'))
        .map((a, idx) => ({
            id: a.id ? parseInt(a.id, 10) : (idx + 1),
            bounds: {
                x: Math.round(Number(a.bounds?.x || 0)),
                y: Math.round(Number(a.bounds?.y || 0)),
                width: Math.round(Number(a.bounds?.width || 100)),
                height: Math.round(Number(a.bounds?.height || 100))
            },
            action: {
                type: a.action?.type || 'postback',
                data: a.action?.data || '',
                displayText: a.action?.displayText || '',
                uri: a.action?.uri || '',
                text: a.action?.text || '',
                richMenuAliasId: a.action?.richMenuAliasId || ''
            }
        }));

    state.selectedAreaId = state.areas.length > 0 ? state.areas[0].id : null;

    // 3. 装飾テキストの復元（アクション設定も復元）
    state.textOverlays = (item.text_overlays || []).map((o, idx) => ({
        id: o.id ? parseInt(o.id, 10) : (idx + 1),
        text: o.text || '',
        type: o.type || 'banner_top',
        theme: o.theme || 'red',
        size: o.size || 'md',
        x: Number(o.x || 60),
        y: Number(o.y || 60),
        action: o.action || { type: 'none', uri: '', data: '', displayText: '', text: '', richMenuAliasId: '' }
    }));
    renderTextOverlays();
    renderTextOverlayControls();

    // 4. 画像を表示 (装飾文字が焼き込まれていないクリーン画像)
    displayLoadedImage(cleanImageUrl);

    // 5. 設定フォームとピルを更新
    updateAreaConfigForm();

    // 6. 編集中ステータスバナーとボタン表示の更新
    updateEditingBanner();

    if (!item.base_image_url && item.text_overlays && item.text_overlays.length > 0) {
        showToast(`「${item.title}」を読み込みました（旧形式のため文字を変更・削除する場合は「画像を変更」から元画像を再選択してください）`, 'info');
    } else {
        showToast(`「${item.title}」をエディタに読み込みました（上書き保存モード）`, 'info');
    }
}

function updateEditingBanner() {
    if (state.editingMenuId) {
        if (elements.editingStatusBanner) {
            elements.editingStatusBanner.style.display = 'flex';
            const desc = elements.editingStatusBanner.querySelector('.editing-desc');
            if (desc) {
                desc.textContent = `「${state.editingMenuTitle}」を編集中。保存すると切替キー（エイリアス）を引き継いで更新されるため、他メニューからの切替リンクが一切外れません。`;
            }
        }
        if (elements.saveAsCopyBtn) elements.saveAsCopyBtn.style.display = 'inline-flex';
        if (elements.saveDraftBtn) elements.saveDraftBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> 上書き保存';
        if (elements.publishMenuBtn) elements.publishMenuBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> 本番に更新して反映';
    } else {
        if (elements.editingStatusBanner) elements.editingStatusBanner.style.display = 'none';
        if (elements.saveAsCopyBtn) elements.saveAsCopyBtn.style.display = 'none';
        if (elements.saveDraftBtn) elements.saveDraftBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> 下書きとして保存';
        if (elements.publishMenuBtn) elements.publishMenuBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> LINE公式アカウントに公開して反映';
    }
}

function exitEditMode(showToastMsg = true) {
    state.editingMenuId = null;
    state.editingMenuAliasId = null;
    state.editingMenuTitle = '';
    updateEditingBanner();
    if (showToastMsg) {
        showToast('新規作成モードに戻りました（保存すると新しいメニューとして作成されます）', 'info');
    }
}

function resetEditorForm() {
    exitEditMode(false);
    elements.menuTitleInput.value = '';
    elements.chatBarTextInput.value = 'メニュー';
    state.imageFile = null;
    state.imageSrc = '';
    state.baseImageFile = null;
    state.baseImageSrc = '';
    state.areas = [];
    state.selectedAreaId = null;
    state.textOverlays = [];
    renderTextOverlays();
    renderTextOverlayControls();
    elements.uploadDropzone.style.display = 'block';
    elements.canvasStage.style.display = 'none';
    elements.changeImageBtn.style.display = 'none';
    elements.stageImage.src = '';
    renderAreas();
    updateAreaConfigForm();
}

// ================= ユーティリティ =================
function showLoading(msg) {
    elements.loadingMsg.textContent = msg || '処理中...';
    elements.loadingOverlay.style.display = 'flex';
}

function hideLoading() {
    elements.loadingOverlay.style.display = 'none';
}

let toastTimeout = null;
function showToast(msg, type = 'info') {
    if (!elements.toast) return;
    if (toastTimeout) clearTimeout(toastTimeout);

    let iconHtml = '<i class="fa-solid fa-circle-info"></i>';
    if (type === 'success') {
        iconHtml = '<i class="fa-solid fa-circle-check"></i>';
    } else if (type === 'error') {
        iconHtml = '<i class="fa-solid fa-triangle-exclamation"></i>';
    }

    elements.toast.innerHTML = `<span class="toast-icon">${iconHtml}</span><span class="toast-msg">${escapeHtml(msg)}</span>`;
    elements.toast.className = 'toast-notification ' + type + ' show';

    toastTimeout = setTimeout(() => {
        elements.toast.classList.remove('show');
    }, 3800);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ================= クイックお知らせ作成ウィザード =================
const noticeState = {
    theme: 'red',
    size: 'large',
    titleSize: 76,
    titleColor: '#0f172a',
    titleAlign: 'left',
    bodySize: 50,
    bodyColor: '#334155',
    bodyAlign: 'left',
    customTitleColor: false,
    customBodyColor: false,
    okBounds: null,
    linkBounds: null
};

function getNoticeThemeDefaults(themeName) {
    const defaults = {
        red: {
            bgGrad: ['#7f1d1d', '#991b1b', '#b91c1c'],
            cardBg: '#ffffff',
            badgeBg: '#dc2626',
            badgeText: '#ffffff',
            badgeLabel: '📢 重要なお知らせ',
            titleColor: '#0f172a',
            bodyColor: '#334155',
            divider: '#e2e8f0',
            btnOkBg: '#dc2626',
            btnOkText: '#ffffff',
            btnLinkBg: '#f8fafc',
            btnLinkText: '#0f172a',
            btnLinkBorder: '#cbd5e1'
        },
        green: {
            bgGrad: ['#064e3b', '#065f46', '#047857'],
            cardBg: '#ffffff',
            badgeBg: '#059669',
            badgeText: '#ffffff',
            badgeLabel: '🌿 お知らせ・ご案内',
            titleColor: '#0f172a',
            bodyColor: '#334155',
            divider: '#e2e8f0',
            btnOkBg: '#059669',
            btnOkText: '#ffffff',
            btnLinkBg: '#f8fafc',
            btnLinkText: '#0f172a',
            btnLinkBorder: '#cbd5e1'
        },
        dark: {
            bgGrad: ['#090d16', '#0f172a', '#1e293b'],
            cardBg: '#1e293b',
            cardBorder: 'rgba(245, 158, 11, 0.4)',
            badgeBg: '#d97706',
            badgeText: '#ffffff',
            badgeLabel: '✨ SPECIAL NOTICE',
            titleColor: '#ffffff',
            bodyColor: '#cbd5e1',
            divider: 'rgba(255, 255, 255, 0.12)',
            btnOkBg: '#f59e0b',
            btnOkText: '#0f172a',
            btnLinkBg: 'rgba(255, 255, 255, 0.08)',
            btnLinkText: '#f8fafc',
            btnLinkBorder: 'rgba(255, 255, 255, 0.2)'
        },
        blue: {
            bgGrad: ['#1e3a8a', '#1d4ed8', '#2563eb'],
            cardBg: '#ffffff',
            badgeBg: '#2563eb',
            badgeText: '#ffffff',
            badgeLabel: '🔷 インフォメーション',
            titleColor: '#0f172a',
            bodyColor: '#334155',
            divider: '#e2e8f0',
            btnOkBg: '#2563eb',
            btnOkText: '#ffffff',
            btnLinkBg: '#f8fafc',
            btnLinkText: '#0f172a',
            btnLinkBorder: '#cbd5e1'
        },
        yellow: {
            bgGrad: ['#78350f', '#92400e', '#b45309'],
            cardBg: '#ffffff',
            badgeBg: '#d97706',
            badgeText: '#ffffff',
            badgeLabel: '🔔 ピックアップ情報',
            titleColor: '#0f172a',
            bodyColor: '#334155',
            divider: '#e2e8f0',
            btnOkBg: '#0f172a',
            btnOkText: '#ffffff',
            btnLinkBg: '#fef3c7',
            btnLinkText: '#92400e',
            btnLinkBorder: '#fde68a'
        }
    };
    return defaults[themeName] || defaults.red;
}

function applyNoticeTheme(themeName, forceResetColors = false) {
    noticeState.theme = themeName;

    // UIのactive切り替え
    document.querySelectorAll('.notice-theme-opt').forEach(opt => {
        const isTarget = (opt.dataset.theme === themeName);
        opt.classList.toggle('active', isTarget);
        const radio = opt.querySelector('input[type="radio"]');
        if (radio) radio.checked = isTarget;
    });

    // 推奨カラーの反映 (カスタムされていなければ、またはリセット要求時)
    const t = getNoticeThemeDefaults(themeName);
    if (!noticeState.customTitleColor || forceResetColors) {
        noticeState.titleColor = t.titleColor;
        if (elements.noticeTitleColorInput) elements.noticeTitleColorInput.value = t.titleColor;
        if (elements.noticeTitleColorCode) elements.noticeTitleColorCode.textContent = t.titleColor;
        noticeState.customTitleColor = false;
    }
    if (!noticeState.customBodyColor || forceResetColors) {
        noticeState.bodyColor = t.bodyColor;
        if (elements.noticeBodyColorInput) elements.noticeBodyColorInput.value = t.bodyColor;
        if (elements.noticeBodyColorCode) elements.noticeBodyColorCode.textContent = t.bodyColor;
        noticeState.customBodyColor = false;
    }

    drawNoticePreview();
}

function initNoticeWizardEvents() {
    if (!elements.btnOpenNoticeModal) return;

    // 開く・閉じる
    elements.btnOpenNoticeModal.addEventListener('click', openNoticeWizard);
    if (elements.btnCloseNoticeWizard) {
        elements.btnCloseNoticeWizard.addEventListener('click', closeNoticeWizard);
    }
    if (elements.btnCancelNoticeWizard) {
        elements.btnCancelNoticeWizard.addEventListener('click', closeNoticeWizard);
    }
    if (elements.noticeWizardModal) {
        elements.noticeWizardModal.addEventListener('click', (e) => {
            if (e.target === elements.noticeWizardModal) {
                closeNoticeWizard();
            }
        });
    }

    // サイズ切替
    if (elements.btnNoticeSizeLarge && elements.btnNoticeSizeSmall) {
        elements.btnNoticeSizeLarge.addEventListener('click', () => {
            elements.btnNoticeSizeLarge.classList.add('active');
            elements.btnNoticeSizeSmall.classList.remove('active');
            noticeState.size = 'large';
            drawNoticePreview();
        });
        elements.btnNoticeSizeSmall.addEventListener('click', () => {
            elements.btnNoticeSizeSmall.classList.add('active');
            elements.btnNoticeSizeLarge.classList.remove('active');
            noticeState.size = 'small';
            drawNoticePreview();
        });
    }

    // デザインカラーテーマ選択 (クリック & ラジオ変更)
    document.querySelectorAll('.notice-theme-opt').forEach(opt => {
        opt.addEventListener('click', (e) => {
            const theme = opt.dataset.theme || (opt.querySelector('input[type="radio"]') ? opt.querySelector('input[type="radio"]').value : 'red');
            applyNoticeTheme(theme, false);
        });
    });

    // タイトル文字サイズ・文字色・配置
    if (elements.noticeTitleInput) {
        elements.noticeTitleInput.addEventListener('input', drawNoticePreview);
    }
    if (elements.noticeTitleSizeInput) {
        elements.noticeTitleSizeInput.addEventListener('change', () => {
            noticeState.titleSize = parseInt(elements.noticeTitleSizeInput.value, 10) || 76;
            drawNoticePreview();
        });
    }
    if (elements.noticeTitleColorInput) {
        elements.noticeTitleColorInput.addEventListener('input', () => {
            noticeState.titleColor = elements.noticeTitleColorInput.value;
            if (elements.noticeTitleColorCode) elements.noticeTitleColorCode.textContent = elements.noticeTitleColorInput.value;
            noticeState.customTitleColor = true;
            drawNoticePreview();
        });
    }
    if (elements.noticeTitleAlignSelect) {
        elements.noticeTitleAlignSelect.addEventListener('change', () => {
            noticeState.titleAlign = elements.noticeTitleAlignSelect.value || 'left';
            drawNoticePreview();
        });
    }

    // 本文文字サイズ・文字色・配置
    if (elements.noticeBodyInput) {
        elements.noticeBodyInput.addEventListener('input', drawNoticePreview);
    }
    if (elements.noticeBodySizeInput) {
        elements.noticeBodySizeInput.addEventListener('change', () => {
            noticeState.bodySize = parseInt(elements.noticeBodySizeInput.value, 10) || 50;
            drawNoticePreview();
        });
    }
    if (elements.noticeBodyColorInput) {
        elements.noticeBodyColorInput.addEventListener('input', () => {
            noticeState.bodyColor = elements.noticeBodyColorInput.value;
            if (elements.noticeBodyColorCode) elements.noticeBodyColorCode.textContent = elements.noticeBodyColorInput.value;
            noticeState.customBodyColor = true;
            drawNoticePreview();
        });
    }
    if (elements.noticeBodyAlignSelect) {
        elements.noticeBodyAlignSelect.addEventListener('change', () => {
            noticeState.bodyAlign = elements.noticeBodyAlignSelect.value || 'left';
            drawNoticePreview();
        });
    }

    // 文字色リセットボタン
    if (elements.btnResetThemeColors) {
        elements.btnResetThemeColors.addEventListener('click', () => {
            applyNoticeTheme(noticeState.theme, true);
            showToast('文字色をテーマの標準色にリセットしました', 'info');
        });
    }

    if (elements.noticeCloseBtnTextInput) {
        elements.noticeCloseBtnTextInput.addEventListener('input', drawNoticePreview);
    }
    if (elements.noticeLinkUrlInput) {
        elements.noticeLinkUrlInput.addEventListener('input', drawNoticePreview);
    }

    // 公開ボタン
    if (elements.btnSubmitNoticePublish) {
        elements.btnSubmitNoticePublish.addEventListener('click', publishNoticeMenu);
    }
}

function openNoticeWizard() {
    if (!elements.noticeWizardModal) return;

    // 戻り先リッチメニューの選択肢を構築
    populateNoticeReturnMenuOptions();

    // デフォルト値が未入力ならセット
    if (elements.noticeTitleInput && !elements.noticeTitleInput.value.trim()) {
        elements.noticeTitleInput.value = '🎉 秋の大感謝祭セール開催！';
    }
    if (elements.noticeBodyInput && !elements.noticeBodyInput.value.trim()) {
        elements.noticeBodyInput.value = '9/10(水)〜9/25(木)まで秋の特別商談会を開催！\n期間中にご来店・ご成約のお客様に豪華特典をご用意しております。\n点検・オイル交換のご相談もお気軽にどうぞ！';
    }
    if (elements.noticeCloseBtnTextInput && !elements.noticeCloseBtnTextInput.value.trim()) {
        elements.noticeCloseBtnTextInput.value = '✓ OK (通常メニューへ)';
    }

    // フォームコントロールの値を state と同期
    if (elements.noticeTitleSizeInput) elements.noticeTitleSizeInput.value = String(noticeState.titleSize);
    if (elements.noticeTitleColorInput) elements.noticeTitleColorInput.value = noticeState.titleColor;
    if (elements.noticeTitleColorCode) elements.noticeTitleColorCode.textContent = noticeState.titleColor;
    if (elements.noticeTitleAlignSelect) elements.noticeTitleAlignSelect.value = noticeState.titleAlign;

    if (elements.noticeBodySizeInput) elements.noticeBodySizeInput.value = String(noticeState.bodySize);
    if (elements.noticeBodyColorInput) elements.noticeBodyColorInput.value = noticeState.bodyColor;
    if (elements.noticeBodyColorCode) elements.noticeBodyColorCode.textContent = noticeState.bodyColor;
    if (elements.noticeBodyAlignSelect) elements.noticeBodyAlignSelect.value = noticeState.bodyAlign;

    elements.noticeWizardModal.style.display = 'flex';
    drawNoticePreview();
}

function closeNoticeWizard() {
    if (!elements.noticeWizardModal) return;
    elements.noticeWizardModal.style.display = 'none';
}

function populateNoticeReturnMenuOptions() {
    if (!elements.noticeReturnMenuSelect) return;
    elements.noticeReturnMenuSelect.innerHTML = '';

    if (!state.historyList || state.historyList.length === 0) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = '保存済みリッチメニューがありません';
        elements.noticeReturnMenuSelect.appendChild(opt);
        return;
    }

    // 現在本番中（isLive）のものを優先して初期選択
    let selectedId = '';
    const liveMenu = state.historyList.find(m => m.is_active == 1 || (m.line_menu_id && m.line_menu_id === state.currentLineDefaultId));
    if (liveMenu) {
        selectedId = liveMenu.alias_id || liveMenu.id;
    }

    state.historyList.forEach(m => {
        const opt = document.createElement('option');
        const aliasOrId = m.alias_id || m.id;
        opt.value = aliasOrId;
        const isCurrent = (m.is_active == 1 || (m.line_menu_id && m.line_menu_id === state.currentLineDefaultId));
        opt.textContent = (isCurrent ? '★本番適用中: ' : '') + m.title + (m.alias_id ? ` [${m.alias_id}]` : '');
        if (isCurrent || (!selectedId && !opt.selected)) {
            opt.selected = true;
            selectedId = aliasOrId;
        }
        elements.noticeReturnMenuSelect.appendChild(opt);
    });
}

function drawNoticePreview() {
    const canvas = elements.noticeCanvasPreview;
    if (!canvas) return;

    const isLarge = (noticeState.size === 'large');
    const width = 2500;
    const height = isLarge ? 1686 : 843;

    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');

    const title = (elements.noticeTitleInput ? elements.noticeTitleInput.value.trim() : '') || 'お知らせタイトル';
    const body = (elements.noticeBodyInput ? elements.noticeBodyInput.value.trim() : '') || 'お知らせ本文を入力してください。';
    const closeBtnText = (elements.noticeCloseBtnTextInput ? elements.noticeCloseBtnTextInput.value.trim() : '') || '✓ OK (通常メニューへ)';
    const linkUrl = (elements.noticeLinkUrlInput ? elements.noticeLinkUrlInput.value.trim() : '');

    const t = getNoticeThemeDefaults(noticeState.theme);

    // 1. 背景グラデーション描画
    const grad = ctx.createLinearGradient(0, 0, width, height);
    grad.addColorStop(0, t.bgGrad[0]);
    grad.addColorStop(0.5, t.bgGrad[1]);
    grad.addColorStop(1, t.bgGrad[2]);
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, width, height);

    // 背景の微細なサークル装飾
    ctx.save();
    ctx.fillStyle = 'rgba(255, 255, 255, 0.035)';
    ctx.beginPath();
    ctx.arc(width * 0.85, height * 0.2, 380, 0, Math.PI * 2);
    ctx.fill();
    ctx.beginPath();
    ctx.arc(width * 0.15, height * 0.85, 420, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();

    // 2. メインカード描画
    const padX = isLarge ? 90 : 70;
    const padY = isLarge ? 80 : 50;
    const cardW = width - (padX * 2);
    const cardH = height - (padY * 2);
    const cardRadius = isLarge ? 48 : 36;

    // カードのドロップシャドウ
    ctx.save();
    ctx.shadowColor = 'rgba(0, 0, 0, 0.4)';
    ctx.shadowBlur = 40;
    ctx.shadowOffsetX = 0;
    ctx.shadowOffsetY = 16;
    drawRoundedRect(ctx, padX, padY, cardW, cardH, cardRadius);
    ctx.fillStyle = t.cardBg;
    ctx.fill();
    ctx.restore();

    if (t.cardBorder) {
        ctx.save();
        ctx.strokeStyle = t.cardBorder;
        ctx.lineWidth = 4;
        drawRoundedRect(ctx, padX, padY, cardW, cardH, cardRadius);
        ctx.stroke();
        ctx.restore();
    }

    // 3. バッジ描画
    const badgeX = (noticeState.titleAlign === 'center') ? (padX + cardW / 2) : (padX + 80);
    const badgeY = padY + (isLarge ? 65 : 42);
    const badgeH = isLarge ? 64 : 50;
    const badgePadX = 36;

    ctx.font = `bold ${isLarge ? 34 : 28}px "Outfit", "Noto Sans JP", sans-serif`;
    const badgeTextWidth = ctx.measureText(t.badgeLabel).width;
    const badgeW = badgeTextWidth + (badgePadX * 2);
    const badgeDrawX = (noticeState.titleAlign === 'center') ? (badgeX - badgeW / 2) : badgeX;

    ctx.save();
    drawRoundedRect(ctx, badgeDrawX, badgeY, badgeW, badgeH, badgeH / 2);
    ctx.fillStyle = t.badgeBg;
    ctx.fill();
    ctx.fillStyle = t.badgeText;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(t.badgeLabel, badgeDrawX + badgeW / 2, badgeY + badgeH / 2);
    ctx.restore();

    // 4. タイトル描画 (ユーザー設定のフォントサイズ・文字色・配置)
    const titleBaseFontSize = noticeState.titleSize || 76;
    const titleFontSize = isLarge ? titleBaseFontSize : Math.round(titleBaseFontSize * 0.75);
    const titleLineH = Math.round(titleFontSize * 1.25);
    const titleY = badgeY + badgeH + (isLarge ? 45 : 30);

    ctx.save();
    ctx.font = `900 ${titleFontSize}px "Outfit", "Noto Sans JP", sans-serif`;
    ctx.fillStyle = noticeState.titleColor || t.titleColor;
    ctx.textAlign = noticeState.titleAlign || 'left';
    ctx.textBaseline = 'top';

    const maxTextW = cardW - 160;
    const titleLines = getWrappedLines(ctx, title, maxTextW);
    const renderTitleLines = titleLines.slice(0, 2);
    const titleDrawX = (noticeState.titleAlign === 'center') ? (padX + cardW / 2) : (padX + 80);

    renderTitleLines.forEach((line, idx) => {
        ctx.fillText(line, titleDrawX, titleY + (idx * titleLineH));
    });
    ctx.restore();

    // 5. 区切り線描画
    const dividerY = titleY + (renderTitleLines.length * titleLineH) + (isLarge ? 28 : 18);
    ctx.save();
    ctx.strokeStyle = t.divider;
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(padX + 70, dividerY);
    ctx.lineTo(padX + cardW - 70, dividerY);
    ctx.stroke();
    ctx.restore();

    // 6. 本文テキスト描画 (ユーザー設定のフォントサイズ・文字色・配置)
    const bodyBaseFontSize = noticeState.bodySize || 50;
    const bodyFontSize = isLarge ? bodyBaseFontSize : Math.round(bodyBaseFontSize * 0.75);
    const bodyLineH = Math.round(bodyFontSize * 1.55);
    const bodyY = dividerY + (isLarge ? 36 : 22);

    ctx.save();
    ctx.font = `500 ${bodyFontSize}px "Noto Sans JP", sans-serif`;
    ctx.fillStyle = noticeState.bodyColor || t.bodyColor;
    ctx.textAlign = noticeState.bodyAlign || 'left';
    ctx.textBaseline = 'top';

    // 本文の最大行数計算（ボタン領域の手前まで）
    const btnAreaH = isLarge ? 220 : 160;
    const availBodyH = (padY + cardH - btnAreaH) - bodyY;
    const maxBodyLines = Math.max(2, Math.floor(availBodyH / bodyLineH));

    const rawBodyLines = body.split('\n');
    let allBodyLines = [];
    rawBodyLines.forEach(rawLine => {
        const wrapped = getWrappedLines(ctx, rawLine, maxTextW);
        allBodyLines = allBodyLines.concat(wrapped.length ? wrapped : ['']);
    });

    const displayBodyLines = allBodyLines.slice(0, maxBodyLines);
    if (allBodyLines.length > maxBodyLines && displayBodyLines.length > 0) {
        displayBodyLines[displayBodyLines.length - 1] += '...';
    }

    const bodyDrawX = (noticeState.bodyAlign === 'center') ? (padX + cardW / 2) : (padX + 80);
    displayBodyLines.forEach((line, idx) => {
        ctx.fillText(line, bodyDrawX, bodyY + (idx * bodyLineH));
    });
    ctx.restore();

    // 7. ボタン領域描画
    const btnH = isLarge ? 150 : 115;
    const btnY = padY + cardH - btnH - (isLarge ? 45 : 30);
    const btnRadius = isLarge ? 28 : 22;

    if (linkUrl) {
        // 2分割レイアウト: 左「詳細を見る」 / 右「確認しました（閉じる）」
        const gap = isLarge ? 40 : 30;
        const totalW = cardW - 140;
        const singleBtnW = Math.floor((totalW - gap) / 2);

        const leftBtnX = padX + 70;
        const rightBtnX = leftBtnX + singleBtnW + gap;

        // 詳細リンクボタン
        drawRoundedRect(ctx, leftBtnX, btnY, singleBtnW, btnH, btnRadius);
        ctx.fillStyle = t.btnLinkBg;
        ctx.fill();
        ctx.strokeStyle = t.btnLinkBorder;
        ctx.lineWidth = 3;
        ctx.stroke();

        ctx.save();
        ctx.fillStyle = t.btnLinkText;
        ctx.font = `bold ${isLarge ? 50 : 38}px "Noto Sans JP", sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('🔗 詳細を見る', leftBtnX + singleBtnW / 2, btnY + btnH / 2);
        ctx.restore();

        // OK / 閉じるボタン
        drawRoundedRect(ctx, rightBtnX, btnY, singleBtnW, btnH, btnRadius);
        ctx.fillStyle = t.btnOkBg;
        ctx.fill();

        ctx.save();
        ctx.fillStyle = t.btnOkText;
        ctx.font = `bold ${isLarge ? 50 : 38}px "Noto Sans JP", sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(closeBtnText, rightBtnX + singleBtnW / 2, btnY + btnH / 2);
        ctx.restore();

        // LINE API用タップ領域の座標記録
        noticeState.linkBounds = { x: leftBtnX, y: btnY, width: singleBtnW, height: btnH };
        noticeState.okBounds = { x: rightBtnX, y: btnY, width: singleBtnW, height: btnH };
    } else {
        // 1ボタンレイアウト: 全幅または中央ワイドボタン
        const singleBtnW = cardW - 140;
        const singleBtnX = padX + 70;

        drawRoundedRect(ctx, singleBtnX, btnY, singleBtnW, btnH, btnRadius);
        ctx.fillStyle = t.btnOkBg;
        ctx.fill();

        ctx.save();
        ctx.fillStyle = t.btnOkText;
        ctx.font = `bold ${isLarge ? 56 : 42}px "Noto Sans JP", sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(closeBtnText, singleBtnX + singleBtnW / 2, btnY + btnH / 2);
        ctx.restore();

        // LINE API用タップ領域の座標記録
        noticeState.linkBounds = null;
        noticeState.okBounds = { x: singleBtnX, y: btnY, width: singleBtnW, height: btnH };
    }
}

// 角丸長方形描画
function drawRoundedRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r);
    ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
}

// テキスト行分割ヘルパー
function getWrappedLines(ctx, text, maxWidth) {
    if (!text) return [];
    const lines = [];
    let currentLine = '';

    for (let i = 0; i < text.length; i++) {
        const char = text[i];
        const testLine = currentLine + char;
        const testWidth = ctx.measureText(testLine).width;
        if (testWidth > maxWidth && currentLine !== '') {
            lines.push(currentLine);
            currentLine = char;
        } else {
            currentLine = testLine;
        }
    }
    if (currentLine) {
        lines.push(currentLine);
    }
    return lines;
}

// お知らせリッチメニューを一斉公開
function publishNoticeMenu() {
    const title = elements.noticeTitleInput ? elements.noticeTitleInput.value.trim() : '';
    const body = elements.noticeBodyInput ? elements.noticeBodyInput.value.trim() : '';
    const returnAliasOrId = elements.noticeReturnMenuSelect ? elements.noticeReturnMenuSelect.value.trim() : '';
    const linkUrl = elements.noticeLinkUrlInput ? elements.noticeLinkUrlInput.value.trim() : '';

    if (!title) {
        showToast('お知らせのタイトルを入力してください', 'error');
        if (elements.noticeTitleInput) elements.noticeTitleInput.focus();
        return;
    }

    if (!body) {
        showToast('お知らせの本文を入力してください', 'error');
        if (elements.noticeBodyInput) elements.noticeBodyInput.focus();
        return;
    }

    if (!returnAliasOrId) {
        showToast('戻り先の通常リッチメニューを選択してください', 'error');
        return;
    }

    if (!noticeState.okBounds) {
        showToast('プレビュー描画が完了していません', 'error');
        return;
    }

    // 戻り先エイリアスIDの特定（state.historyListから）
    let returnAliasId = returnAliasOrId;
    const targetMenu = state.historyList.find(m => (m.alias_id && m.alias_id === returnAliasOrId) || String(m.id) === returnAliasOrId);
    if (targetMenu && targetMenu.alias_id) {
        returnAliasId = targetMenu.alias_id;
    } else if (targetMenu && !targetMenu.alias_id) {
        returnAliasId = 'rm_' + (targetMenu.line_menu_id ? targetMenu.line_menu_id.replace(/[^a-zA-Z0-9_-]/g, '').slice(-20) : targetMenu.id);
    }

    const publishToAll = elements.noticePublishToAllCheckbox ? elements.noticePublishToAllCheckbox.checked : false;

    const confirmMsg = publishToAll ?
        `【お知らせリッチメニューを友だち全員に一斉公開しますか？】\n\n` +
        `・LINE公式アカウントの全体メニューがこのお知らせに切り替わります。\n` +
        `・「閉じる/確認」をタップすると、指定した「${targetMenu ? targetMenu.title : '通常メニュー'}」へ瞬時に切り替わります。\n\n` +
        `今すぐ公開してよろしいですか？` :
        `【お知らせ専用メニューを登録・有効化しますか？】\n\n` +
        `・LINEのクイックリプライ「📢 お知らせ」を押したお客様にこのお知らせリッチメニューが表示されます。\n` +
        `・全体メニューは変更されず、通常メニューを維持したまま安全に設定できます。\n\n` +
        `登録してよろしいですか？`;

    if (!confirm(confirmMsg)) return;

    showLoading(publishToAll ? 'お知らせ画像を合成してLINE公式に全体公開中...' : 'お知らせ画像を合成してクイックリプライ連携に設定中...');

    // Canvasから画像Blobを生成
    elements.noticeCanvasPreview.toBlob((blob) => {
        if (!blob) {
            hideLoading();
            showToast('お知らせ画像の生成に失敗しました', 'error');
            return;
        }

        // タップ領域（areas）を構築（OKボタンタップ時にサイレントに通常メニューへ戻す）
        const areas = [
            {
                bounds: noticeState.okBounds,
                action: {
                    type: 'postback',
                    data: 'action=close_notice'
                }
            }
        ];

        if (noticeState.linkBounds && linkUrl) {
            areas.push({
                bounds: noticeState.linkBounds,
                action: {
                    type: 'uri',
                    uri: linkUrl
                }
            });
        }

        const currentPass = state.password || sessionStorage.getItem('admin_pass') || '';

        const formData = new FormData();
        formData.append('password', currentPass);
        formData.append('title', '【お知らせ】' + title);
        formData.append('chat_bar_text', '📢 お知らせ・ご案内');
        formData.append('width', '2500');
        formData.append('height', (noticeState.size === 'large') ? '1686' : '843');
        formData.append('is_notice', '1');
        formData.append('publish', publishToAll ? '1' : '0');
        formData.append('areas', JSON.stringify(areas));
        formData.append('image', blob, 'notice_menu.png');

        fetch('../api.php?action=admin_save_richmenu&password=' + encodeURIComponent(currentPass), {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                const toastMsg = publishToAll ?
                    '🎉 お知らせリッチメニューを一斉公開しました！友だち全員に表示されます' :
                    '🎉 お知らせ専用メニューを保存し、クイックリプライ「📢 お知らせ」の連携対象に設定しました！';
                showToast(toastMsg, 'success');
                closeNoticeWizard();
                switchView('history');
                loadHistoryList();
            } else {
                showToast(data.error || 'お知らせの保存に失敗しました', 'error');
            }
        })
        .catch(err => {
            hideLoading();
            showToast('通信エラーが発生しました: ' + err.message, 'error');
        });
    }, 'image/png');
}
