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
    currentLineDefaultId: null
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
    changeImageBtn: document.getElementById('changeImageBtn'),
    clearAreasBtn: document.getElementById('clearAreasBtn'),

    // ツールバー
    sizeToggleBtns: document.querySelectorAll('.btn-toggle'),
    presetBtns: document.querySelectorAll('.btn-preset'),

    // プロパティパネル
    menuTitleInput: document.getElementById('menuTitleInput'),
    chatBarTextInput: document.getElementById('chatBarTextInput'),
    areaNoSelectionMsg: document.getElementById('areaNoSelectionMsg'),
    areaConfigForm: document.getElementById('areaConfigForm'),
    selectedAreaLabel: document.getElementById('selectedAreaLabel'),
    deleteSelectedAreaBtn: document.getElementById('deleteSelectedAreaBtn'),

    // 座標
    valCoordX: document.getElementById('valCoordX'),
    valCoordY: document.getElementById('valCoordY'),
    valCoordW: document.getElementById('valCoordW'),
    valCoordH: document.getElementById('valCoordH'),

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
            onActionTypeChanged(radio.value);
        });
    });
    elements.postbackDataInput.addEventListener('input', syncCurrentAreaFromForm);
    elements.postbackDisplayTextInput.addEventListener('input', syncCurrentAreaFromForm);
    elements.uriInput.addEventListener('input', syncCurrentAreaFromForm);
    elements.messageTextInput.addEventListener('input', syncCurrentAreaFromForm);

    // クイック入力チップ
    document.querySelectorAll('.quick-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            if (chip.dataset.val) {
                elements.postbackDataInput.value = chip.dataset.val;
                syncCurrentAreaFromForm();
            } else if (chip.dataset.uri) {
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
    elements.stageImage.src = src;
    elements.uploadDropzone.style.display = 'none';
    elements.canvasStage.style.display = 'inline-block';
    elements.changeImageBtn.style.display = 'inline-block';

    // もし枠が空ならデフォルトで6分割を適用
    if (state.areas.length === 0) {
        applyPreset(state.menuSize === 'large' ? 'grid6' : 'grid3');
    } else {
        renderAreas();
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

    const stageW = elements.stageImage.clientWidth || 600;
    const stageH = elements.stageImage.clientHeight || 400;
    const scaleX = stageW / state.width;
    const scaleY = stageH / state.height;

    state.areas.forEach((area, index) => {
        const box = document.createElement('div');
        box.className = 'area-box' + (area.id === state.selectedAreaId ? ' selected' : '');
        box.dataset.id = area.id;

        // ピクセル単位に変換
        const left = Math.round(area.bounds.x * scaleX);
        const top = Math.round(area.bounds.y * scaleY);
        const width = Math.round(area.bounds.width * scaleX);
        const height = Math.round(area.bounds.height * scaleY);

        box.style.left = left + 'px';
        box.style.top = top + 'px';
        box.style.width = width + 'px';
        box.style.height = height + 'px';

        // ラベルバッジ
        const badge = document.createElement('div');
        badge.className = 'area-box-badge';
        let actionSummary = area.action.type;
        if (area.action.type === 'postback') {
            actionSummary = area.action.displayText || area.action.data || 'Postback';
        } else if (area.action.type === 'uri') {
            actionSummary = 'リンク';
        } else if (area.action.type === 'message') {
            actionSummary = area.action.text || 'Message';
        }
        badge.textContent = `枠${index + 1}: ${actionSummary}`;
        box.appendChild(badge);

        // 選択中の場合はリサイズハンドルを追加
        if (area.id === state.selectedAreaId) {
            ['nw', 'ne', 'se', 'sw'].forEach(handleType => {
                const handle = document.createElement('div');
                handle.className = `resize-handle handle-${handleType}`;
                handle.dataset.handle = handleType;
                box.appendChild(handle);
            });
        }

        elements.stageOverlay.appendChild(box);
    });
}

// ================= キャンバス上でのインタラクション =================
function initCanvasInteractions() {
    const overlay = elements.stageOverlay;

    overlay.addEventListener('mousedown', (e) => {
        const rect = overlay.getBoundingClientRect();
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
            state.dragTargetArea = state.areas.find(a => a.id === state.selectedAreaId);
            state.initialBounds = { ...state.dragTargetArea.bounds };
            return;
        }

        // 枠内クリック判定 (移動または選択)
        const areaBoxEl = e.target.closest('.area-box');
        if (areaBoxEl) {
            e.stopPropagation();
            const areaId = parseInt(areaBoxEl.dataset.id);
            selectArea(areaId);

            state.isDragging = true;
            state.dragAction = 'move';
            state.dragStart = { x: actualX, y: actualY };
            state.dragTargetArea = state.areas.find(a => a.id === areaId);
            state.initialBounds = { ...state.dragTargetArea.bounds };
            return;
        }

        // 空白クリック時: 新規エリア描画の開始
        state.isDragging = true;
        state.dragAction = 'create';
        state.dragStart = { x: actualX, y: actualY };

        const newId = (state.areas.length > 0 ? Math.max(...state.areas.map(a => a.id)) : 0) + 1;
        const newArea = {
            id: newId,
            bounds: { x: actualX, y: actualY, width: 10, height: 10 },
            action: { type: 'postback', data: 'action=search_all' }
        };
        state.areas.push(newArea);
        state.selectedAreaId = newId;
        state.dragTargetArea = newArea;
        state.initialBounds = { ...newArea.bounds };
        renderAreas();
    });

    window.addEventListener('mousemove', (e) => {
        if (!state.isDragging && !state.isResizing) return;

        const rect = overlay.getBoundingClientRect();
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
            // 移動
            let newX = init.x + deltaX;
            let newY = init.y + deltaY;
            newX = Math.max(0, Math.min(state.width - area.bounds.width, newX));
            newY = Math.max(0, Math.min(state.height - area.bounds.height, newY));
            area.bounds.x = newX;
            area.bounds.y = newY;
        } else if (state.dragAction === 'create' || state.dragAction === 'se') {
            // 右下へのリサイズ
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

        renderAreas();
        updateCoordsDisplay(area);
    });

    window.addEventListener('mouseup', () => {
        if (state.isDragging || state.isResizing) {
            state.isDragging = false;
            state.isResizing = false;
            state.dragAction = null;
            state.dragTargetArea = null;
            state.initialBounds = null;
            updateAreaConfigForm();
        }
    });

    // ウィンドウリサイズ時に再描画
    window.addEventListener('resize', () => {
        if (state.imageSrc) renderAreas();
    });
}

function selectArea(id) {
    state.selectedAreaId = id;
    renderAreas();
    updateAreaConfigForm();
}

function deleteArea(id) {
    state.areas = state.areas.filter(a => a.id !== id);
    state.selectedAreaId = state.areas.length > 0 ? state.areas[0].id : null;
    renderAreas();
    updateAreaConfigForm();
}

// ================= プロパティ設定フォームの更新 =================
function updateAreaConfigForm() {
    const area = state.areas.find(a => a.id === state.selectedAreaId);

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

    const index = state.areas.findIndex(a => a.id === area.id);
    elements.selectedAreaLabel.textContent = `枠 ${index + 1}`;

    updateCoordsDisplay(area);

    // アクション種別の反映
    const actionType = area.action.type || 'postback';
    elements.actionTypeRadios.forEach(radio => {
        radio.checked = (radio.value === actionType);
    });
    onActionTypeChanged(actionType);

    // フィールド値の反映
    elements.postbackDataInput.value = area.action.data || '';
    elements.postbackDisplayTextInput.value = area.action.displayText || '';
    elements.uriInput.value = area.action.uri || '';
    elements.messageTextInput.value = area.action.text || '';
}

function updateCoordsDisplay(area) {
    elements.valCoordX.textContent = area.bounds.x;
    elements.valCoordY.textContent = area.bounds.y;
    elements.valCoordW.textContent = area.bounds.width;
    elements.valCoordH.textContent = area.bounds.height;
}

function onActionTypeChanged(type) {
    elements.fieldPostback.style.display = (type === 'postback') ? 'block' : 'none';
    elements.fieldUri.style.display = (type === 'uri') ? 'block' : 'none';
    elements.fieldMessage.style.display = (type === 'message') ? 'block' : 'none';
    syncCurrentAreaFromForm();
}

function syncCurrentAreaFromForm() {
    const area = state.areas.find(a => a.id === state.selectedAreaId);
    if (!area) return;

    let selectedType = 'postback';
    elements.actionTypeRadios.forEach(r => {
        if (r.checked) selectedType = r.value;
    });

    area.action.type = selectedType;
    if (selectedType === 'postback') {
        area.action.data = elements.postbackDataInput.value.trim() || 'action=search_all';
        area.action.displayText = elements.postbackDisplayTextInput.value.trim();
    } else if (selectedType === 'uri') {
        area.action.uri = elements.uriInput.value.trim() || 'https://www.goo-net.com';
    } else if (selectedType === 'message') {
        area.action.text = elements.messageTextInput.value.trim() || 'メニュー';
    }

    renderAreas();
}

// ================= 保存 & LINE公開 =================
function saveRichMenu(publish) {
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

    const formData = new FormData();
    formData.append('password', state.password);
    formData.append('title', title);
    formData.append('chat_bar_text', elements.chatBarTextInput.value.trim() || 'メニュー');
    formData.append('width', state.width);
    formData.append('height', state.height);
    formData.append('publish', publish ? '1' : '0');
    formData.append('areas', JSON.stringify(state.areas));

    if (state.imageFile) {
        formData.append('image', state.imageFile);
    } else if (state.imageSrc) {
        formData.append('existing_image_url', state.imageSrc);
    }

    showLoading(publish ? 'LINE公式アカウントに公開・反映中...' : '下書きを保存中...');

    fetch('../api.php?action=admin_save_richmenu', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
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
    })
    .catch(err => {
        hideLoading();
        showToast('通信エラーが発生しました: ' + err.message, 'error');
    });
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
    elements.menuTitleInput.value = item.title + ' (コピー)';
    elements.chatBarTextInput.value = item.chat_bar_text || 'メニュー';
    setMenuSize(item.height == 843 ? 'small' : 'large');

    // サイズボタンの見た目同期
    elements.sizeToggleBtns.forEach(btn => {
        btn.classList.toggle('active', btn.dataset.size === (item.height == 843 ? 'small' : 'large'));
    });

    state.imageFile = null;
    state.imageSrc = item.image_url;
    state.areas = item.areas || [];
    state.selectedAreaId = state.areas.length > 0 ? state.areas[0].id : null;

    displayLoadedImage(item.image_url);
    switchView('editor');
    showToast(`「${item.title}」をエディタに読み込みました`, 'info');
}

function resetEditorForm() {
    elements.menuTitleInput.value = '';
    elements.chatBarTextInput.value = 'メニュー';
    state.imageFile = null;
    state.imageSrc = '';
    state.areas = [];
    state.selectedAreaId = null;
    elements.uploadDropzone.style.display = 'block';
    elements.canvasStage.style.display = 'none';
    elements.changeImageBtn.style.display = 'none';
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
