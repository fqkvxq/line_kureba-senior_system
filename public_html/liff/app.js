/**
 * アップファーレン 在庫車両検索 LIFFフロントエンドロジック
 */

// アプリ状態管理
const state = {
    allCars: [],
    filteredCars: [],
    currentFilter: {
        keyword: '',
        maxPrice: null,
        maxDistance: null,
        sort: 'price_asc'
    },
    selectedCar: null,
    isLiffLoggedIn: false
};

// DOM要素
const elements = {
    carGrid: document.getElementById('carGrid'),
    emptyState: document.getElementById('emptyState'),
    searchInput: document.getElementById('searchInput'),
    clearSearchBtn: document.getElementById('clearSearchBtn'),
    filterToggleBtn: document.getElementById('filterToggleBtn'),
    filterActiveDot: document.getElementById('filterActiveDot'),
    stockCountText: document.getElementById('stockCountText'),
    resultCountNumber: document.getElementById('resultCountNumber'),
    sortSelect: document.getElementById('sortSelect'),
    tagChips: document.querySelectorAll('.tag-chip'),
    
    // フィルターモーダル
    filterModal: document.getElementById('filterModal'),
    closeFilterBtn: document.getElementById('closeFilterBtn'),
    priceRange: document.getElementById('priceRange'),
    priceRangeVal: document.getElementById('priceRangeVal'),
    distanceRange: document.getElementById('distanceRange'),
    distanceRangeVal: document.getElementById('distanceRangeVal'),
    modalKeywordInput: document.getElementById('modalKeywordInput'),
    modalClearBtn: document.getElementById('modalClearBtn'),
    modalApplyBtn: document.getElementById('modalApplyBtn'),
    modalMatchCount: document.getElementById('modalMatchCount'),
    resetFilterBtn: document.getElementById('resetFilterBtn'),
    
    // 詳細モーダル
    detailModal: document.getElementById('detailModal'),
    closeDetailBtn: document.getElementById('closeDetailBtn'),
    detailImg: document.getElementById('detailImg'),
    detailTotalPrice: document.getElementById('detailTotalPrice'),
    detailBasePrice: document.getElementById('detailBasePrice'),
    detailTitle: document.getElementById('detailTitle'),
    detailYear: document.getElementById('detailYear'),
    detailDistance: document.getElementById('detailDistance'),
    detailRepair: document.getElementById('detailRepair'),
    detailShaken: document.getElementById('detailShaken'),
    detailDisplacement: document.getElementById('detailDisplacement'),
    detailLineInquiryBtn: document.getElementById('detailLineInquiryBtn'),
    detailGooLink: document.getElementById('detailGooLink'),
    
    toast: document.getElementById('appToast')
};

// 初期化
document.addEventListener('DOMContentLoaded', async () => {
    initLiff();
    initEventListeners();
    await fetchCarData();
});

/**
 * LIFF初期化
 */
async function initLiff() {
    try {
        if (typeof liff !== 'undefined') {
            // ※本番環境でLIFF IDを設定した場合はこちらで初期化
            // await liff.init({ liffId: 'YOUR_LIFF_ID' });
            if (liff.isLoggedIn()) {
                state.isLiffLoggedIn = true;
            }
        }
    } catch (err) {
        console.warn('LIFF Init error / Running in browser mode:', err);
    }
}

/**
 * イベントリスナー登録
 */
function initEventListeners() {
    // 検索入力
    elements.searchInput.addEventListener('input', (e) => {
        const val = e.target.value.trim();
        state.currentFilter.keyword = val;
        elements.clearSearchBtn.style.display = val ? 'block' : 'none';
        applyFilters();
    });

    elements.clearSearchBtn.addEventListener('click', () => {
        elements.searchInput.value = '';
        state.currentFilter.keyword = '';
        elements.clearSearchBtn.style.display = 'none';
        applyFilters();
    });

    // ソート切り替え
    elements.sortSelect.addEventListener('change', (e) => {
        state.currentFilter.sort = e.target.value;
        applyFilters();
    });

    // クイックタグクリック
    elements.tagChips.forEach(chip => {
        chip.addEventListener('click', () => {
            elements.tagChips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');

            if (chip.dataset.filter === 'all') {
                state.currentFilter.keyword = '';
                state.currentFilter.maxPrice = null;
                elements.searchInput.value = '';
            } else if (chip.dataset.maxPrice) {
                state.currentFilter.maxPrice = parseFloat(chip.dataset.maxPrice);
            } else if (chip.dataset.kw) {
                state.currentFilter.keyword = chip.dataset.kw;
                elements.searchInput.value = chip.dataset.kw;
            }
            applyFilters();
        });
    });

    // フィルターモーダル開閉
    elements.filterToggleBtn.addEventListener('click', () => {
        openFilterModal();
    });

    elements.closeFilterBtn.addEventListener('click', () => {
        elements.filterModal.classList.remove('active');
    });

    elements.filterModal.addEventListener('click', (e) => {
        if (e.target === elements.filterModal) {
            elements.filterModal.classList.remove('active');
        }
    });

    // フィルタースライダー操作
    elements.priceRange.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        elements.priceRangeVal.textContent = val >= 150 ? '上限なし' : `${val}万円以下`;
        updateModalMatchCount();
    });

    elements.distanceRange.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        elements.distanceRangeVal.textContent = val >= 15 ? '上限なし' : `${val}万km以下`;
        updateModalMatchCount();
    });

    elements.modalKeywordInput.addEventListener('input', () => {
        updateModalMatchCount();
    });

    // フィルター適用ボタン
    elements.modalApplyBtn.addEventListener('click', () => {
        const priceVal = parseFloat(elements.priceRange.value);
        const distVal = parseFloat(elements.distanceRange.value);
        const kwVal = elements.modalKeywordInput.value.trim();

        state.currentFilter.maxPrice = priceVal >= 150 ? null : priceVal;
        state.currentFilter.maxDistance = distVal >= 15 ? null : distVal;
        if (kwVal) {
            state.currentFilter.keyword = kwVal;
            elements.searchInput.value = kwVal;
        }

        elements.filterModal.classList.remove('active');
        updateFilterDot();
        applyFilters();
    });

    // フィルターリセットボタン
    elements.modalClearBtn.addEventListener('click', () => {
        resetFilters();
    });
    elements.resetFilterBtn.addEventListener('click', () => {
        resetFilters();
    });

    // 詳細モーダル閉じる
    elements.closeDetailBtn.addEventListener('click', () => {
        elements.detailModal.classList.remove('active');
    });
    elements.detailModal.addEventListener('click', (e) => {
        if (e.target === elements.detailModal) {
            elements.detailModal.classList.remove('active');
        }
    });

    // LINE問い合わせボタンクリック
    elements.detailLineInquiryBtn.addEventListener('click', () => {
        handleLineInquiry(state.selectedCar);
    });
}

/**
 * サーバーAPIから車両データを取得
 */
async function fetchCarData() {
    try {
        const res = await fetch('../api.php?action=list&limit=100');
        if (!res.ok) throw new Error('API request failed');
        const data = await res.json();
        
        if (data.success && Array.isArray(data.cars)) {
            state.allCars = data.cars;
        } else {
            throw new Error('Invalid data format');
        }
    } catch (err) {
        console.warn('API connection failed, loading fallback local data:', err);
        // ローカルフォールバックデータ (初期13台)
        state.allCars = getFallbackCars();
    }

    elements.stockCountText.textContent = `${state.allCars.length} 台掲載中`;
    applyFilters();
}

/**
 * フィルター適用 & ソート
 */
function applyFilters() {
    let filtered = [...state.allCars];
    const { keyword, maxPrice, maxDistance, sort } = state.currentFilter;

    // キーワード検索
    if (keyword) {
        const lowerKw = keyword.toLowerCase();
        filtered = filtered.filter(car => 
            (car.title && car.title.toLowerCase().includes(lowerKw)) ||
            (car.displacement && car.displacement.toLowerCase().includes(lowerKw)) ||
            (car.year && car.year.toLowerCase().includes(lowerKw))
        );
    }

    // 支払総額
    if (maxPrice !== null) {
        filtered = filtered.filter(car => {
            if (!car.total_price_num) return true;
            return car.total_price_num <= maxPrice;
        });
    }

    // 走行距離
    if (maxDistance !== null) {
        filtered = filtered.filter(car => {
            if (!car.distance_num) return true;
            return car.distance_num <= maxDistance;
        });
    }

    // ソート
    filtered.sort((a, b) => {
        const priceA = a.total_price_num || 99999;
        const priceB = b.total_price_num || 99999;
        const distA = a.distance_num || 99999;
        const distB = b.distance_num || 99999;

        if (sort === 'price_asc') return priceA - priceB;
        if (sort === 'price_desc') return priceB - priceA;
        if (sort === 'distance_asc') return distA - distB;
        if (sort === 'year_desc') return (b.year || '').localeCompare(a.year || '');
        return 0;
    });

    state.filteredCars = filtered;
    renderCarGrid();
}

/**
 * 車両グリッドを描画
 */
function renderCarGrid() {
    const cars = state.filteredCars;
    elements.resultCountNumber.textContent = cars.length;

    if (cars.length === 0) {
        elements.carGrid.innerHTML = '';
        elements.emptyState.style.display = 'block';
        return;
    }

    elements.emptyState.style.display = 'none';

    elements.carGrid.innerHTML = cars.map(car => {
        const imgUrl = car.image_url || 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';
        const totalPrice = car.total_price_text || (car.total_price_num ? `${car.total_price_num}万円` : '要問合せ');
        const basePrice = car.base_price_text ? `本体: ${car.base_price_text}` : '';
        const year = car.year || '-';
        const distance = car.distance || '-';
        const repair = car.repair_history || 'なし';
        const shaken = car.shaken || '-';

        return `
            <div class="car-card" data-car-id="${car.id}">
                <div class="card-img-wrapper" onclick="openDetailModal('${car.id}')">
                    <img src="${imgUrl}" alt="${escapeHtml(car.title)}" loading="lazy">
                    <div class="card-badge-status">支払総額表示</div>
                </div>
                <div class="card-content">
                    <div class="card-price-row" onclick="openDetailModal('${car.id}')">
                        <div class="price-main-box">
                            <span class="price-label-badge">支払総額</span>
                            <span class="price-value-large">${escapeHtml(totalPrice.replace('万円', ''))}</span>
                            <span class="price-unit">万円</span>
                        </div>
                        <span class="price-base-text">${escapeHtml(basePrice)}</span>
                    </div>
                    <h3 class="card-title" onclick="openDetailModal('${car.id}')">${escapeHtml(car.title)}</h3>
                    
                    <div class="card-specs" onclick="openDetailModal('${car.id}')">
                        <div class="spec-cell">
                            <span class="spec-cell-label">年式</span>
                            <span class="spec-cell-value">${escapeHtml(year.split('(')[0] || year)}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell-label">走行</span>
                            <span class="spec-cell-value">${escapeHtml(distance)}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell-label">修復歴</span>
                            <span class="spec-cell-value">${escapeHtml(repair)}</span>
                        </div>
                        <div class="spec-cell">
                            <span class="spec-cell-label">車検</span>
                            <span class="spec-cell-value">${escapeHtml(shaken.split('(')[0] || shaken)}</span>
                        </div>
                    </div>

                    <div class="card-footer-btns">
                        <button class="btn-card-inquiry" onclick="handleLineInquiryById('${car.id}')">
                            <i class="fa-brands fa-line"></i> LINEで問い合わせ
                        </button>
                        <button class="btn-card-detail" onclick="openDetailModal('${car.id}')" title="詳細を見る">
                            <i class="fa-solid fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

/**
 * 詳細モーダルを開く
 */
window.openDetailModal = function(carId) {
    const car = state.allCars.find(c => c.id === carId);
    if (!car) return;

    state.selectedCar = car;

    elements.detailImg.src = car.image_url || 'https://img.goo-net.com/goo/usedcar/nophoto_big.jpg';
    elements.detailTotalPrice.textContent = car.total_price_text || (car.total_price_num ? `${car.total_price_num}万円` : '要問合せ');
    elements.detailBasePrice.textContent = car.base_price_text || '--';
    elements.detailTitle.textContent = car.title;
    elements.detailYear.textContent = car.year || '-';
    elements.detailDistance.textContent = car.distance || '-';
    elements.detailShaken.textContent = car.shaken || '-';
    elements.detailDisplacement.textContent = car.displacement || '-';
    
    let userParam = '';
    if (state.userProfile) {
        if (state.userProfile.userId) userParam += `&uid=${encodeURIComponent(state.userProfile.userId)}`;
        if (state.userProfile.displayName) userParam += `&uname=${encodeURIComponent(state.userProfile.displayName)}`;
    }
    elements.detailGooLink.href = `../redirect.php?id=${encodeURIComponent(car.id)}&src=${encodeURIComponent('LIFFミニアプリ')}${userParam}`;

    elements.detailModal.classList.add('active');
};

/**
 * フィルターモーダルを開く
 */
function openFilterModal() {
    elements.priceRange.value = state.currentFilter.maxPrice || 150;
    elements.priceRangeVal.textContent = state.currentFilter.maxPrice ? `${state.currentFilter.maxPrice}万円以下` : '上限なし';

    elements.distanceRange.value = state.currentFilter.maxDistance || 15;
    elements.distanceRangeVal.textContent = state.currentFilter.maxDistance ? `${state.currentFilter.maxDistance}万km以下` : '上限なし';

    elements.modalKeywordInput.value = state.currentFilter.keyword || '';
    updateModalMatchCount();
    elements.filterModal.classList.add('active');
}

/**
 * モーダル内のマッチ件数を更新
 */
function updateModalMatchCount() {
    const priceVal = parseFloat(elements.priceRange.value);
    const distVal = parseFloat(elements.distanceRange.value);
    const kw = elements.modalKeywordInput.value.trim().toLowerCase();

    let count = state.allCars.filter(car => {
        if (priceVal < 150 && car.total_price_num && car.total_price_num > priceVal) return false;
        if (distVal < 15 && car.distance_num && car.distance_num > distVal) return false;
        if (kw && !car.title.toLowerCase().includes(kw)) return false;
        return true;
    }).length;

    elements.modalMatchCount.textContent = count;
}

/**
 * フィルターのリセット
 */
function resetFilters() {
    state.currentFilter = {
        keyword: '',
        maxPrice: null,
        maxDistance: null,
        sort: 'price_asc'
    };
    elements.searchInput.value = '';
    elements.clearSearchBtn.style.display = 'none';
    elements.tagChips.forEach(c => c.classList.remove('active'));
    elements.tagChips[0].classList.add('active');
    elements.filterModal.classList.remove('active');
    updateFilterDot();
    applyFilters();
}

function updateFilterDot() {
    const hasFilter = state.currentFilter.maxPrice !== null || state.currentFilter.maxDistance !== null;
    elements.filterActiveDot.style.display = hasFilter ? 'block' : 'none';
}

/**
 * LINE問い合わせ処理
 */
window.handleLineInquiryById = function(carId) {
    const car = state.allCars.find(c => c.id === carId);
    if (car) handleLineInquiry(car);
};

function handleLineInquiry(car) {
    if (!car) return;

    const messageText = `【車両問い合わせ】\n車種: ${car.title}\n支払総額: ${car.total_price_text || ''}\n詳細URL: ${car.detail_url}\n\nこちらの車両について、在庫状況や詳細を教えていただけますでしょうか？`;

    // LIFF内かつメッセージ送信権限がある場合
    if (typeof liff !== 'undefined' && liff.isInClient()) {
        liff.sendMessages([
            {
                type: 'text',
                text: messageText
            }
        ]).then(() => {
            liff.closeWindow();
        }).catch(err => {
            console.warn('liff.sendMessages failed, fallback:', err);
            copyToClipboard(messageText);
        });
    } else {
        // 通常ブラウザ表示時はクリップボードにコピー
        copyToClipboard(messageText);
    }
}

/**
 * クリップボードコピー & トースト
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('📋 問い合わせ文面をコピーしました！LINEトークに貼り付けて送信してください。');
    }).catch(() => {
        showToast('問い合わせ文面を作成しました。');
    });
}

function showToast(msg) {
    elements.toast.textContent = msg;
    elements.toast.classList.add('show');
    setTimeout(() => {
        elements.toast.classList.remove('show');
    }, 3500);
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/**
 * フォールバック用ローカルデータ（初期13台）
 */
function getFallbackCars() {
    return [
        {
            id: "700060149230251012001",
            title: "ワゴンＲスティングレー Ｔ ターボ ベンチシート スマートキー スペアキー 社外ＳＤナビ ワンセグＴＶ ＣＤラジオ １５インチアルミホイール",
            total_price_text: "60万円",
            total_price_num: 60.0,
            base_price_text: "55万円",
            base_price_num: 55.0,
            year: "2014(平成26)年",
            distance: "4.2万km",
            distance_num: 4.2,
            displacement: "660cc",
            repair_history: "なし",
            shaken: "2027(令和9)年1月",
            image_url: "https://picture1.goo-net.com/7000601492/30251012/Q/70006014923025101200100.jpg",
            detail_url: "https://www.goo-net.com/usedcar/spread/goo/15/700060149230251012001.html"
        },
        {
            id: "700060149230260526001",
            title: "Ｎ－ＢＯＸ Ｇ・Ｌホンダセンシング ギャザーズ８インチＳＤナビ 地デジ ａｐｐｌｅｃａｒｐｌａｙ ＤＶＤ バックカメラ ＥＴＣ ドラレコ",
            total_price_text: "64万円",
            total_price_num: 64.0,
            base_price_text: "58万円",
            base_price_num: 58.0,
            year: "2018(平成30)年",
            distance: "9.8万km",
            distance_num: 9.8,
            displacement: "660cc",
            repair_history: "なし",
            shaken: "車検整備付",
            image_url: "https://picture1.goo-net.com/7000601492/30260526/Q/70006014923026052600100.jpg",
            detail_url: "https://www.goo-net.com/usedcar/spread/goo/15/700060149230260526001.html"
        },
        {
            id: "700060149230260622001",
            title: "デイズ ハイウェイスターＸ Ｖセレクション＋セーフティＩＩ 純正ＳＤナビ 地デジＴＶ ＤＶＤ Ｂｌｕｅｔｏｏｔｈ アラウンドビューモニター",
            total_price_text: "68万円",
            total_price_num: 68.0,
            base_price_text: "62万円",
            base_price_num: 62.0,
            year: "2016(平成28)年",
            distance: "5.1万km",
            distance_num: 5.1,
            displacement: "660cc",
            repair_history: "なし",
            shaken: "2027(令和9)年6月",
            image_url: "https://picture1.goo-net.com/7000601492/30260622/Q/70006014923026062200100.jpg",
            detail_url: "https://www.goo-net.com/usedcar/spread/goo/15/700060149230260622001.html"
        }
    ];
}
