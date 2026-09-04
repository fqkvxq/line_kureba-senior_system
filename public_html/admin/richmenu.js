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

    // 装飾テキスト・お知らせバナー
    textOverlays: []
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
    postbackDataInput: document.getElementById('postbackDataInput'),
    postbackDisplayTextInput: document.getElementById('postbackDisplayTextInput'),
    uriInput: document.getElementById('uriInput'),
    messageTextInput: document.getElementById('messageTextInput'),

    // ボタン
    publishMenuBtn: document.getElementById('publishMenuBtn'),
    saveDraftBtn: document.getElementById('saveDraftBtn'),

    // 履歴
    historyGrid: document.getElementById('historyGrid'),
    historyEmpty: document.getElementById('historyEmpty'),
    historyCreateNewBtn: document.getElementById('historyCreateNewBtn'),
    emptyCreateBtn: document.getElementById('emptyCreateBtn'),

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
    elements.publishMenuBtn.addEventListener('click', () => saveRichMenu(true));
    elements.saveDraftBtn.addEventListener('click', () => saveRichMenu(false));
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

    const reader = new FileReader();
    reader.onload = (event) => {
        state.imageSrc = event.target.result;
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
            { type: 'uri', uri: 'https://liff.line.me/2011340718-OaRM8tV4/mycar.html' }
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

    window.addEventListener('mousemove', (e) => {
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
    });

    window.addEventListener('mouseup', () => {
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
    });

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

        if (type === 'banner_top') {
            const bannerH = Math.round(previewFontSize * 2.1);
            el.style.left = '0';
            el.style.top = '0';
            el.style.width = '100%';
            el.style.height = bannerH + 'px';
            el.style.lineHeight = bannerH + 'px';
            el.style.padding = '0 ' + Math.round(previewFontSize * 0.4) + 'px';
        } else if (type === 'banner_bottom') {
            const bannerH = Math.round(previewFontSize * 2.1);
            el.style.left = '0';
            el.style.bottom = '0';
            el.style.width = '100%';
            el.style.height = bannerH + 'px';
            el.style.lineHeight = bannerH + 'px';
            el.style.padding = '0 ' + Math.round(previewFontSize * 0.4) + 'px';
        } else if (type === 'badge') {
            const leftPercent = ((overlay.x || 60) / (state.width || 2500)) * 100;
            const topPercent = ((overlay.y || 60) / (state.height || 1686)) * 100;
            el.style.left = leftPercent + '%';
            el.style.top = topPercent + '%';
            el.style.padding = `${Math.round(previewFontSize * 0.25)}px ${Math.round(previewFontSize * 0.7)}px`;
            el.style.borderRadius = `${Math.round(previewFontSize * 0.9)}px`;
        } else if (type === 'free') {
            const leftPercent = ((overlay.x || 60) / (state.width || 2500)) * 100;
            const topPercent = ((overlay.y || 60) / (state.height || 1686)) * 100;
            el.style.left = leftPercent + '%';
            el.style.top = topPercent + '%';
            el.style.padding = `${Math.round(previewFontSize * 0.25)}px ${Math.round(previewFontSize * 0.5)}px`;
            el.style.borderRadius = `${Math.max(4, Math.round(previewFontSize * 0.15))}px`;
        }

        el.textContent = text;
        elements.textOverlaysStage.appendChild(el);
    });
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

            <input type="text" class="overlay-text-input" value="${escapeHtml(overlay.text)}" placeholder="お知らせ文言を入力 (例: 🎉 秋の大感謝祭開催中！)">

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
                <div style="display: flex; gap: 6px; align-items: flex-end;">
                    <div>
                        <span class="option-group-label">X:</span>
                        <input type="number" class="coord-field-xs overlay-pos-x" value="${Math.round(overlay.x || 60)}" style="width: 55px; font-size: 11px; padding: 3px; border: 1px solid #cbd5e1; border-radius: 4px;">
                    </div>
                    <div>
                        <span class="option-group-label">Y:</span>
                        <input type="number" class="coord-field-xs overlay-pos-y" value="${Math.round(overlay.y || 60)}" style="width: 55px; font-size: 11px; padding: 3px; border: 1px solid #cbd5e1; border-radius: 4px;">
                    </div>
                </div>
                ` : ''}
            </div>
        `;

        // イベント: 文字入力
        const textInput = card.querySelector('.overlay-text-input');
        textInput.addEventListener('input', () => {
            overlay.text = textInput.value;
            renderTextOverlays();
        });

        // イベント: 定型文チップクリック
        card.querySelectorAll('.phrase-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                overlay.text = chip.dataset.phrase;
                textInput.value = overlay.text;
                renderTextOverlays();
            });
        });

        // イベント: 配置タイプ切り替え
        card.querySelectorAll('.btn-type-pill').forEach(btn => {
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
        y: data.y || 60
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
        img.src = state.imageSrc;
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

        if (type === 'banner_top') {
            const bannerH = Math.round(fontSize * 2.1);
            ctx.fillStyle = theme.bg;
            ctx.fillRect(0, 0, W, bannerH);
            ctx.fillStyle = theme.border;
            ctx.fillRect(0, bannerH - 4, W, 4);

            ctx.fillStyle = theme.text;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, W / 2, bannerH / 2);
        } else if (type === 'banner_bottom') {
            const bannerH = Math.round(fontSize * 2.1);
            const bannerY = H - bannerH;
            ctx.fillStyle = theme.bg;
            ctx.fillRect(0, bannerY, W, bannerH);
            ctx.fillStyle = theme.border;
            ctx.fillRect(0, bannerY, W, 4);

            ctx.fillStyle = theme.text;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, W / 2, bannerY + bannerH / 2);
        } else if (type === 'badge') {
            const metrics = ctx.measureText(text);
            const badgeW = Math.round(metrics.width + fontSize * 1.4);
            const badgeH = Math.round(fontSize * 1.8);
            const origW = Number(state.width) || 2500;
            const origH = Number(state.height) || 1686;
            const posX = Math.round(((overlay.x || 60) / origW) * W);
            const posY = Math.round(((overlay.y || 60) / origH) * H);

            // ドロップシャドウ
            ctx.shadowColor = 'rgba(0, 0, 0, 0.4)';
            ctx.shadowBlur = 16;
            ctx.shadowOffsetY = 6;

            drawCanvasRoundRect(ctx, posX, posY, badgeW, badgeH, badgeH / 2);
            ctx.fillStyle = theme.bg;
            ctx.fill();

            ctx.shadowColor = 'transparent';
            ctx.lineWidth = 4;
            ctx.strokeStyle = theme.border;
            ctx.stroke();

            ctx.fillStyle = theme.text;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, posX + badgeW / 2, posY + badgeH / 2);
        } else if (type === 'free') {
            const metrics = ctx.measureText(text);
            const boxW = Math.round(metrics.width + fontSize * 1.0);
            const boxH = Math.round(fontSize * 1.6);
            const origW = Number(state.width) || 2500;
            const origH = Number(state.height) || 1686;
            const posX = Math.round(((overlay.x || 100) / origW) * W);
            const posY = Math.round(((overlay.y || 100) / origH) * H);

            ctx.shadowColor = 'rgba(0, 0, 0, 0.35)';
            ctx.shadowBlur = 14;
            ctx.shadowOffsetY = 4;

            drawCanvasRoundRect(ctx, posX, posY, boxW, boxH, Math.max(8, Math.round(fontSize * 0.15)));
            ctx.fillStyle = theme.bg;
            ctx.fill();

            ctx.shadowColor = 'transparent';
            ctx.lineWidth = 3;
            ctx.strokeStyle = theme.border;
            ctx.stroke();

            ctx.fillStyle = theme.text;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, posX + boxW / 2, posY + boxH / 2);
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

// ================= 保存 & LINE公開 =================
async function saveRichMenu(publish) {
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

    showLoading(publish ? '画像合成＆LINE公式アカウントに公開中...' : '画像合成＆下書きを保存中...');

    try {
        // テキストオーバーレイがある場合は高解像度HTML5 Canvasで元画像と自動合成
        const compositedImageFile = await compositeRichMenuImage();

        const formData = new FormData();
        formData.append('password', state.password);
        formData.append('title', title);
        formData.append('chat_bar_text', elements.chatBarTextInput.value.trim() || 'メニュー');
        formData.append('width', state.width);
        formData.append('height', state.height);
        formData.append('publish', publish ? '1' : '0');
        formData.append('areas', JSON.stringify(state.areas));
        formData.append('text_overlays', JSON.stringify(state.textOverlays || []));

        if (compositedImageFile) {
            formData.append('image', compositedImageFile);
        } else if (state.imageFile) {
            formData.append('image', state.imageFile);
        } else if (state.imageSrc) {
            formData.append('existing_image_url', state.imageSrc);
        }

        const res = await fetch('../api.php?action=admin_save_richmenu', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        hideLoading();
        if (data.success) {
            showToast(data.message || '保存が完了しました！', 'success');
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
    fetch('../api.php?action=admin_list_richmenus&password=' + encodeURIComponent(state.password))
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                state.historyList = data.menus || [];
                state.currentLineDefaultId = data.current_default_id;
                renderHistoryList();
                updateLiveStatusBadge();
            }
        })
        .catch(err => {
            console.warn('履歴読み込みエラー:', err);
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

    if (state.historyList.length === 0) {
        elements.historyEmpty.style.display = 'block';
        return;
    }

    elements.historyEmpty.style.display = 'none';

    state.historyList.forEach(item => {
        const isLive = (item.is_active == 1 || (item.line_menu_id && item.line_menu_id === state.currentLineDefaultId));
        const card = document.createElement('div');
        card.className = 'history-card' + (isLive ? ' active-live' : '');

        const areaCount = item.areas ? item.areas.length : 0;
        const sizeLabel = (item.height == 843) ? '小 (2500×843)' : '大 (2500×1686)';

        card.innerHTML = `
            <div class="history-thumb-wrap">
                <img src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.title)}" loading="lazy">
                ${isLive ? '<span class="badge-live-now"><i class="fa-solid fa-circle-check"></i> 本番適用中</span>' : ''}
                <span class="badge-size">${sizeLabel}</span>
            </div>
            <div class="history-body">
                <h3 class="history-title">${escapeHtml(item.title)}</h3>
                <div class="history-meta">
                    <span><i class="fa-solid fa-clock"></i> 登録日時: ${escapeHtml(item.created_at || '-')}</span>
                    <span><i class="fa-solid fa-table-cells"></i> 設定エリア数: ${areaCount}枠</span>
                    <span><i class="fa-solid fa-comment-dots"></i> 下部バー表示: 「${escapeHtml(item.chat_bar_text || 'メニュー')}」</span>
                </div>
                <div class="history-actions">
                    <button class="btn-apply-card ${isLive ? 'disabled' : ''}" data-id="${item.id}" ${isLive ? 'disabled' : ''}>
                        ${isLive ? '<i class="fa-solid fa-check"></i> 本番公開中' : '<i class="fa-solid fa-bolt"></i> 本番に適用'}
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

    elements.menuTitleInput.value = item.title ? (item.title + ' (コピー)') : '';
    elements.chatBarTextInput.value = item.chat_bar_text || 'メニュー';
    setMenuSize(item.height == 843 ? 'small' : 'large');

    // サイズボタンの見た目同期
    elements.sizeToggleBtns.forEach(btn => {
        btn.classList.toggle('active', btn.dataset.size === (item.height == 843 ? 'small' : 'large'));
    });

    state.imageFile = null;
    state.imageSrc = item.image_url;

    // 2. エリア配列のIDと数値を安全に再構築 (ID欠落によるクリック不可バグを完全解消)
    state.areas = (item.areas || []).map((a, idx) => ({
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
            text: a.action?.text || ''
        }
    }));

    state.selectedAreaId = state.areas.length > 0 ? state.areas[0].id : null;

    // 3. 装飾テキストの復元
    state.textOverlays = (item.text_overlays || []).map((o, idx) => ({
        id: o.id ? parseInt(o.id, 10) : (idx + 1),
        text: o.text || '',
        type: o.type || 'banner_top',
        theme: o.theme || 'red',
        size: o.size || 'md',
        x: Number(o.x || 60),
        y: Number(o.y || 60)
    }));
    renderTextOverlays();
    renderTextOverlayControls();

    // 4. 画像を表示
    displayLoadedImage(item.image_url);

    // 5. 設定フォームとピルを更新
    updateAreaConfigForm();

    showToast(`「${item.title}」をエディタに読み込みました`, 'info');
}

function resetEditorForm() {
    elements.menuTitleInput.value = '';
    elements.chatBarTextInput.value = 'メニュー';
    state.imageFile = null;
    state.imageSrc = '';
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

function showToast(msg, type = 'info') {
    elements.toast.textContent = msg;
    elements.toast.className = 'toast-notification ' + type + ' show';
    setTimeout(() => {
        elements.toast.className = 'toast-notification';
    }, 4000);
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
