/**
 * マイカー点検パスポート LIFFフロントエンドロジック (複数台対応)
 */

const state = {
    userId: '',
    userName: '',
    userAvatar: '',
    cars: [],
    activeCarIndex: 0
};

function getEl(id) {
    return document.getElementById(id);
}

document.addEventListener('DOMContentLoaded', async () => {
    await initLiff();
    initEventListeners();
    if (state.userId) {
        await fetchCustomerData();
    }

    // URLパラメータでシェアが指定されている場合、即座に友だち選択ピッカーを起動
    const urlParams = new URLSearchParams(window.location.search);
    const shareTopic = urlParams.get('share') || urlParams.get('share_topic');
    if (shareTopic) {
        setTimeout(async () => {
            await shareKnowledgeToFriends(shareTopic);
        }, 200);
    }
});

const LIFF_ID = '2011340718-OaRM8tV4';

async function initLiff() {
    // 1. ローカルストレージから永続IDを取得または生成
    let storedUid = localStorage.getItem('mycar_user_id');
    let storedUname = localStorage.getItem('mycar_user_name');
    if (!storedUid) {
        storedUid = 'USER_' + Math.random().toString(36).substring(2, 10);
        localStorage.setItem('mycar_user_id', storedUid);
    }
    state.userId = storedUid;
    state.userName = storedUname || 'お客様';

    // 2. LIFF SDKによるLINEプロファイル取得を試みる
    try {
        if (typeof liff !== 'undefined') {
            await liff.init({ liffId: LIFF_ID });
            
            if (liff.isLoggedIn()) {
                const profile = await liff.getProfile();
                state.userId = profile.userId;
                state.userName = profile.displayName;
                state.userAvatar = profile.pictureUrl || '';

                localStorage.setItem('mycar_user_id', state.userId);
                localStorage.setItem('mycar_user_name', state.userName);

                const uNameEl = getEl('userNameText');
                if (uNameEl) uNameEl.textContent = state.userName + ' 様';
                const uAvatarEl = getEl('userAvatar');
                if (uAvatarEl && state.userAvatar) {
                    uAvatarEl.src = state.userAvatar;
                }
            }
        }
    } catch (e) {
        console.warn('LIFF init error / browser fallback:', e);
    }

    // 3. URLパラメータ（?uid=...&uname=...）があれば優先
    const urlParams = new URLSearchParams(window.location.search);
    const paramUid = urlParams.get('uid');
    const paramUname = urlParams.get('uname');
    if (paramUid) {
        state.userId = paramUid;
        localStorage.setItem('mycar_user_id', state.userId);
    }
    if (paramUname) {
        state.userName = paramUname;
        localStorage.setItem('mycar_user_name', state.userName);
    }

    const uNameEl = getEl('userNameText');
    if (uNameEl) uNameEl.textContent = state.userName + ' 様';
}

let currentBookingType = '次回レッスン';

function initEventListeners() {
    const saveBtn = getEl('saveCustBtn');
    if (saveBtn) {
        saveBtn.addEventListener('click', async () => {
            await saveCustomerData();
        });
    }

    const deleteBtn = getEl('deleteCarBtn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', async () => {
            await deleteActiveCar();
        });
    }

    const initLinkBtn = getEl('initLinkBtn');
    if (initLinkBtn) {
        initLinkBtn.addEventListener('click', async () => {
            await triggerInitialLink();
        });
    }

    const bookingModal = getEl('bookingModal');
    const modalTitle = getEl('modalBookingTitle');
    const closeBtn = getEl('closeBookingModalBtn');
    const cancelBtn = getEl('cancelBookingModalBtn');

    function openModal(type) {
        currentBookingType = type;
        if (modalTitle) modalTitle.textContent = `【${type}】予約・相談の確認`;
        if (bookingModal) bookingModal.style.display = 'flex';
    }

    function closeModal() {
        if (bookingModal) bookingModal.style.display = 'none';
    }

    getEl('bookOilBtn')?.addEventListener('click', () => openModal('次回レッスン'));
    getEl('bookPeriodicBtn')?.addEventListener('click', () => openModal('PC健康診断'));
    getEl('bookInspBtn')?.addEventListener('click', () => openModal('会員更新・月謝'));

    closeBtn?.addEventListener('click', closeModal);
    cancelBtn?.addEventListener('click', closeModal);
    bookingModal?.addEventListener('click', (e) => {
        if (e.target === bookingModal) closeModal();
    });

    document.querySelectorAll('.maint-choice-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const pref = btn.getAttribute('data-pref');
            closeModal();
            await submitMaintenanceBooking(currentBookingType, pref);
        });
    });

    // 一般来店・相談モーダル
    const generalModal = getEl('generalInquiryModal');
    const openGeneralBtn = getEl('openGeneralInquiryBtn');
    const closeGeneralBtn = getEl('closeGeneralInquiryBtn');
    const cancelGeneralBtn = getEl('cancelGeneralInquiryBtn');
    const submitGeneralBtn = getEl('submitGeneralInquiryBtn');

    function openGeneralModal() {
        const currentCar = getCurrentActiveCar();
        const carInput = getEl('inquiryCarModelInput');
        if (carInput && currentCar) {
            carInput.value = currentCar.car_model || '';
        }
        const dateInput = getEl('inquiryPreferredDate');
        if (dateInput) {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            dateInput.min = new Date().toISOString().split('T')[0];
            if (!dateInput.value) {
                dateInput.value = tomorrow.toISOString().split('T')[0];
            }
        }
        if (generalModal) generalModal.style.display = 'flex';
    }

    function closeGeneralModal() {
        if (generalModal) generalModal.style.display = 'none';
    }

    openGeneralBtn?.addEventListener('click', openGeneralModal);
    closeGeneralBtn?.addEventListener('click', closeGeneralModal);
    cancelGeneralBtn?.addEventListener('click', closeGeneralModal);
    generalModal?.addEventListener('click', (e) => {
        if (e.target === generalModal) closeGeneralModal();
    });

    submitGeneralBtn?.addEventListener('click', async () => {
        await submitGeneralInquiry();
    });

    // 📚 豆知識ガイド モーダル制御
    const kModal = getEl('knowledgeDetailModal');
    const closeKModalBtn = getEl('closeKnowledgeModalBtn');
    const closeKBottomBtn = getEl('closeKnowledgeBottomBtn');
    const shareKBtn = getEl('shareKnowledgeBtn');
    let currentKnowledgeTopic = '';

    function closeKModal() {
        if (kModal) kModal.style.display = 'none';
    }

    closeKModalBtn?.addEventListener('click', closeKModal);
    closeKBottomBtn?.addEventListener('click', closeKModal);
    kModal?.addEventListener('click', (e) => {
        if (e.target === kModal) closeKModal();
    });

    shareKBtn?.addEventListener('click', async () => {
        if (!currentKnowledgeTopic) return;
        await shareKnowledgeToFriends(currentKnowledgeTopic);
    });

    // 豆知識ガイドボタン（タップで即座にモーダル表示のみ。自動トーク送信はなし）
    document.querySelectorAll('.btn-knowledge-item').forEach(btn => {
        btn.addEventListener('click', () => {
            const topic = btn.getAttribute('data-topic');
            currentKnowledgeTopic = topic;
            openKnowledgeDetailModal(topic);
        });
    });
}

/**
 * 友だちにLINEで豆知識をシェア（バイラル拡散用 Flex Message）
 */
async function shareKnowledgeToFriends(topic) {
    const data = KNOWLEDGE_ARTICLES[topic] || KNOWLEDGE_ARTICLES['used_car'];
    
    // シェア用 Flex Message の生成
    const sectionContents = data.sections.map(s => ({
        type: 'box',
        layout: 'vertical',
        margin: 'sm',
        backgroundColor: '#f8fafc',
        paddingAll: '8px',
        cornerRadius: 'md',
        contents: [
            {
                type: 'text',
                text: `${s.icon} ${s.title}`,
                weight: 'bold',
                size: 'xs',
                color: '#1e293b'
            },
            {
                type: 'text',
                text: s.desc,
                size: 'xxs',
                color: '#475569',
                wrap: true,
                margin: 'xs'
            }
        ]
    }));

    const shareFlexMessage = {
        type: 'flex',
        altText: `【クルマの豆知識】${data.title}`,
        contents: {
            type: 'bubble',
            size: 'mega',
            body: {
                type: 'box',
                layout: 'vertical',
                paddingAll: '16px',
                contents: [
                    {
                        type: 'text',
                        text: '📢 友だちからのお役立ちクルマ情報！',
                        weight: 'bold',
                        size: 'xxs',
                        color: '#06C755'
                    },
                    {
                        type: 'text',
                        text: data.badge,
                        weight: 'bold',
                        size: 'xs',
                        color: '#3b82f6',
                        margin: 'xs'
                    },
                    {
                        type: 'text',
                        text: data.title,
                        weight: 'bold',
                        size: 'md',
                        color: '#1e293b',
                        margin: 'xs',
                        wrap: true
                    },
                    {
                        type: 'text',
                        text: data.subtitle,
                        size: 'xs',
                        color: '#64748b',
                        margin: 'xs'
                    },
                    {
                        type: 'separator',
                        margin: 'md'
                    },
                    {
                        type: 'box',
                        layout: 'vertical',
                        margin: 'sm',
                        contents: sectionContents
                    },
                    {
                        type: 'separator',
                        margin: 'md'
                    },
                    {
                        type: 'text',
                        text: `💡 ${data.summary}`,
                        size: 'xxs',
                        color: '#334155',
                        wrap: true,
                        margin: 'sm'
                    }
                ]
            },
            footer: {
                type: 'box',
                layout: 'vertical',
                spacing: 'sm',
                paddingAll: '12px',
                contents: [
                    {
                        type: 'button',
                        style: 'primary',
                        color: '#06C755',
                        height: 'sm',
                        action: {
                            type: 'uri',
                            label: '📚 他の豆知識ガイドも見る',
                            uri: 'https://liff.line.me/2011340718-OaRM8tV4/trigger.html?action=show_knowledge_menu'
                        }
                    },
                    {
                        type: 'button',
                        style: 'secondary',
                        height: 'sm',
                        action: {
                            type: 'uri',
                            label: '🚗 アップファーレンの在庫を見る',
                            uri: 'https://liff.line.me/2011340718-OaRM8tV4/index.html'
                        }
                    }
                ]
            }
        }
    };

    // 1. LIFF shareTargetPicker を試行（LINEアプリ内の友だち選択ピッカー起動）
    if (typeof liff !== 'undefined' && liff.isApiAvailable('shareTargetPicker')) {
        try {
            const res = await liff.shareTargetPicker([shareFlexMessage]);
            if (res) {
                showToast('✅ 友だちに豆知識をシェアしました！');
                setTimeout(() => {
                    if (liff.isInClient()) {
                        liff.closeWindow();
                    }
                }, 800);
            }
            return;
        } catch (err) {
            console.warn('shareTargetPicker error:', err);
        }
    }

    // 2. ブラウザや未対応環境時のフォールバック (LINE URLスキーム共有)
    const shareText = `【クルマのお役立ち豆知識】\n${data.title}\n\n${data.subtitle}\n\n▼豆知識ガイド一覧はこちら\nhttps://liff.line.me/2011340718-OaRM8tV4/trigger.html?action=show_knowledge_menu\n\n▼アップファーレンの展示在庫を見る\nhttps://liff.line.me/2011340718-OaRM8tV4/index.html`;
    const lineShareUrl = `https://line.me/R/msg/text/?${encodeURIComponent(shareText)}`;
    
    if (navigator.share) {
        try {
            await navigator.share({
                title: data.title,
                text: shareText
            });
            showToast('✅ シェアしました！');
            return;
        } catch (e) {}
    }
    
    window.open(lineShareUrl, '_blank');
}

/**
 * 全21テーマのナレッジ記事マスター辞書
 */
const KNOWLEDGE_ARTICLES = {
    used_car: {
        badge: '🚗 車選びの極意【①】',
        title: '① 失敗しない中古車の選び方',
        subtitle: 'プロが教える！後悔しない5大見極め術',
        sections: [
            { icon: '1️⃣', title: '年式と走行距離のバランス', desc: '一般的な走行距離の目安は【1年＝約8,000km〜1万km】です。極端な放置車よりも定期的に動いてオイル交換されていた車両が好調です。' },
            { icon: '2️⃣', title: '修復歴（事故歴）の有無を確認', desc: '骨格にダメージのある「修復歴あり」は走行安定性に影響が出る可能性があります。修復歴を明確に開示している店舗を選びましょう。' },
            { icon: '3️⃣', title: '定期点検記録簿（整備手帳）', desc: '過去の整備・消耗品交換履歴が残っている記録簿は大切に乗られていた最大の証拠です。' },
            { icon: '4️⃣', title: '車内のニオイと下回りのサビ', desc: '写真ではわからないタバコ・ペット臭や降雪地特有の下回りサビは要チェックです。' },
            { icon: '5️⃣', title: '支払総額と保証内容', desc: '諸費用込みの「支払総額」と「保証期間・範囲」を必ず確認しましょう。' }
        ],
        summary: 'アップファーレンでは全車両の修復歴を開示し、厳選した高品質車両のみを総額明瞭で展示しております！'
    },
    kei_vs_compact: {
        badge: '🚙 徹底比較ガイド【②】',
        title: '② 軽自動車 vs 普通車の維持費比較',
        subtitle: '税金・車検・使い勝手のリアルな違い',
        sections: [
            { icon: '💴', title: '税金・固定費の圧倒的な差', desc: '自動車税（年約1.5〜2万円差）や重量税、高速道路料金（軽は20%割引）など年間維持費で大きな差が出ます。' },
            { icon: '🚗', title: '最新軽自動車の進化', desc: 'スライドドア（N-BOX等）で室内広々。先進安全ブレーキも普通車同等に充実しています。' },
            { icon: '🛣️', title: '普通車が向いている人', desc: '高速道路を頻繁に利用する方、長距離運転が多い方、5人乗る機会がある方におすすめです。' }
        ],
        summary: 'お客様の使い方やご予算に合わせて、最適な車種選びをプロがアドバイスいたします！'
    },
    body_type_guide: {
        badge: '🚙 目的別・車選び【③】',
        title: '③ ボディタイプ別の特徴と選び方',
        subtitle: '用途や家族構成に合わせた最適車種診断',
        sections: [
            { icon: '🚗', title: '軽ハイトワゴン（N-BOX/タント等）', desc: '圧倒的な室内高とスライドドアで子育てやお買い物に最強。リセールも高いです。' },
            { icon: '🚙', title: 'SUV / クロスオーバー（ヴェゼル/ヤリスクロス等）', desc: 'アイポイントが高く運転しやすい。悪路や雪道に強い4WDが豊富で大人気です。' },
            { icon: '🚐', title: 'ミニバン・コンパクトカー', desc: '3列シートのミニバンは家族旅行に最適。コンパクトは小回りと低燃費が抜群です。' }
        ],
        summary: 'アップファーレンでは軽からSUV・ミニバンまで豊富な在庫をご用意しております！'
    },
    car_loan: {
        badge: '💳 ローン＆資金計画【④】',
        title: '④ オートローンの賢い選び方',
        subtitle: '金利の仕組みと無理のない返済プランの立て方',
        sections: [
            { icon: '🏦', title: '主なローンの種類と特徴', desc: '提携ローン（即日審査・手軽）、銀行マイカーローン（低金利）、自社ローン（独自審査）。' },
            { icon: '📊', title: '実質年率と支払総額の比較', desc: '月々の支払額だけでなく、分割手数料を含めた最終的な「総支払額」を確認しましょう。' },
            { icon: '💡', title: '安心の返済比率（手取りの15〜20%）', desc: '毎月の返済額を手取り月収の15〜20%以内に抑えるのが無理のない維持の黄金比率です。' }
        ],
        summary: 'ライフスタイルに合わせた各種ローンシミュレーションを無料で行っております！'
    },
    best_timing: {
        badge: '💰 お得な買い時【⑤】',
        title: '⑤ 車のお得な買い時・購入時期',
        subtitle: '賢く買って得するベストなタイミング',
        sections: [
            { icon: '🗓', title: '決算期（3月・9月）', desc: '販売目標達成のため値引きやオプションサービスなどの特典が充実しやすい狙い目時期です。' },
            { icon: '🔄', title: 'フルモデルチェンジ直後', desc: '前型モデルの下取り車が多く市場に出回り、価格相場が下がり状態の良い車が手に入ります。' },
            { icon: '💴', title: '自動車税（4月課税）のタイミング', desc: '軽自動車は月割り制度がないため【4月2日以降の購入】が税金面でお得です。' }
        ],
        summary: 'タイミングを見極めて、お目当ての愛車をお得に手に入れましょう！'
    },
    car_paperwork: {
        badge: '📄 手続き＆流れ【⑥】',
        title: '⑥ 必要書類と納車までの流れ',
        subtitle: '準備から納車当日までの完全ステップ',
        sections: [
            { icon: '1️⃣', title: 'ご契約時の必要書類', desc: '普通車：印鑑証明書・実印。軽自動車：住民票・認印。車庫証明を用意します。' },
            { icon: '2️⃣', title: '納車前点検・整備', desc: '法定点検や消耗品交換（オイル・バッテリー等）、車検取得、ボディ美装を徹底します。' },
            { icon: '3️⃣', title: '名義変更とナンバー登録', desc: '陸運局にてお客様名義への登録手続きを店舗が代行いたします。' },
            { icon: '4️⃣', title: '納車（約1〜3週間）', desc: '操作説明や保証書のお渡しを行い、安心のカーライフがスタートします！' }
        ],
        summary: '面倒な名義変更や書類作成も店舗スタッフがフルサポートいたします！'
    },
    trade_in: {
        badge: '🛡️ 査定額UPの秘訣【⑦】',
        title: '⑦ 愛車を高く売る・下取りのコツ',
        subtitle: '査定士が見るポイントと乗り換えのベスト時期',
        sections: [
            { icon: '📋', title: '定期点検記録簿の完備', desc: '整備手帳に点検印が揃っていると大切に扱われていた証拠となり査定プラス評価になります。' },
            { icon: '💎', title: '純正パーツ・説明書・スペアキー', desc: '純正パーツの保管やスペアキーの有無で数万円の査定差になることがあります。' },
            { icon: '🚭', title: '車内の清潔感とニオイ対策', desc: 'タバコ・ペット臭は減額対象です。査定前に清掃・消臭を行っておくのが鉄則です。' },
            { icon: '🗓', title: 'ベストな手放し時期', desc: '車検前や需要が高まる1〜3月・9月は高額査定が出やすい時期です。' }
        ],
        summary: 'アップファーレンでは愛車の下取り・無料査定を実施中！お気軽にご相談ください。'
    },
    oil: {
        badge: '🛢️ 愛車長持ちの秘訣【⑧】',
        title: '⑧ エンジンオイル交換の基本と真実',
        subtitle: '愛車の心臓を守る血液！適切な交換サイクル',
        sections: [
            { icon: '🩸', title: 'エンジンオイルの5大役割', desc: '潤滑・冷却・洗浄・防錆・密封を担っています。走行しなくても半年〜1年で酸化劣化します。' },
            { icon: '⏱', title: '交換サイクルの目安', desc: '軽・ターボ車：3,000〜5,000km または 半年。普通車：5,000〜10,000km または 半年〜1年。' },
            { icon: '⚠️', title: '交換を怠ると？', desc: 'オイルがドロドロになり最悪の場合はエンジンが焼き付き、載せ替えで30万〜50万円の高額出費に。' },
            { icon: '🔄', title: 'エレメント（フィルター）', desc: 'ゴミをろ過するフィルターです。【オイル交換2回に1回】の同時交換が鉄則です。' }
        ],
        summary: '定期的なオイル交換こそが、愛車を最も安く・長く乗り続けるための最高の予防メンテナンスです。'
    },
    periodic: {
        badge: '📋 予防整備の基礎【⑨】',
        title: '⑨ 法定12ヶ月定期点検の必要性',
        subtitle: '車検だけでは不十分！法律で定められた点検',
        sections: [
            { icon: '⚖️', title: '車検と12ヶ月点検の違い', desc: '車検はその瞬間の保安基準確認、12ヶ月点検は次の1年間トラブルなく走るための予防整備です。' },
            { icon: '🔍', title: '主な点検項目（26〜27項目）', desc: 'ブレーキ分解清掃・残量確認、足回りのガタ、ベルト類の劣化、オイル漏れ等を徹底チェック。' },
            { icon: '💡', title: '受ける3大メリット', desc: '①故障の未然防止 ②将来の修理代節約 ③記録簿が残り売却時の査定額がアップ！' }
        ],
        summary: '1年に1回の健康診断で、安心快適なカーライフを守りましょう！'
    },
    inspection: {
        badge: '🔍 安心＆スムーズ【⑩】',
        title: '⑩ 車検の基礎知識と賢い受け方',
        subtitle: '満了日の1ヶ月前から受検可能！準備と流れ',
        sections: [
            { icon: '🗓', title: '受検のベストタイミング', desc: '満了日の【1ヶ月前】から受けても有効期限は短縮されず、丸々引き継がれます。' },
            { icon: '💰', title: '車検費用の内訳', desc: '①法定費用（重量税・自賠責・印紙代＝一律）＋ ②基本料・整備費用。' },
            { icon: '📄', title: 'ご来店時の必要書類', desc: '車検証、自賠責証明書、自動車税納税証明書、認印、ロックナットアダプター。' }
        ],
        summary: 'アップファーレンでは事前無料見積もりを実施中！過剰整備は一切行いません。'
    },
    battery_tire: {
        badge: '⚠️ トラブル予防【⑪】',
        title: '⑪ バッテリー・タイヤ・日常点検',
        subtitle: '突然の路上トラブルを防ぐ日常ケア',
        sections: [
            { icon: '🔋', title: 'バッテリーの寿命（2〜3年）', desc: '夏（エアコン多用）や冬（寒さで性能低下）に突然死します。2年以上経過したらテスター診断を。' },
            { icon: '🛞', title: 'タイヤの交換サイン', desc: '残り溝1.6mm以下（スリップサイン露出）、製造から4〜5年経過のひび割れ、偏摩耗。' },
            { icon: '❄️', title: 'エアコンの冷え・ニオイ', desc: 'フィルターは年1回交換。冷えが悪い場合はガス補充・クリーニングで復活します。' }
        ],
        summary: '少しでも「いつもと違う音や振動」を感じたら、放置せずお気軽にご相談ください！'
    },
    brake_care: {
        badge: '🛑 安全の要・ブレーキ【⑫】',
        title: '⑫ ブレーキの寿命と重要チェック',
        subtitle: '命を守る最重要パーツ！キーキー音は見逃すな',
        sections: [
            { icon: '📏', title: 'パッド残厚（3mmで即交換）', desc: '新品約10mmから摩耗し、残厚3mm以下は危険水域。限界を超えるとローターを削り高額修理に。' },
            { icon: '🔊', title: 'キーキー音のサイン', desc: '金属音はパッド摩耗を知らせるセンサー音です。早急に点検を受けましょう。' },
            { icon: '💧', title: 'ブレーキフルード（2年毎交換）', desc: '吸湿劣化すると下り坂でブレーキが利かなくなる「ベーパーロック現象」の原因になります。' }
        ],
        summary: 'アップファーレンではブレーキ残量測定・フルード点検を迅速に実施いたします！'
    },
    aircon_care: {
        badge: '❄️ 快適ドライブ【⑬】',
        title: '⑬ カーエアコンの効き＆悪臭ケア',
        subtitle: '夏場の冷え不良・カビ臭をスッキリ解決！',
        sections: [
            { icon: '🧽', title: 'エアコンフィルター（年1回交換）', desc: '目詰まりすると風量が弱くなり、湿気でカビや嫌なニオイが発生します。' },
            { icon: '💧', title: 'エバポレーターの内部洗浄', desc: '冷却ユニットのカビを高圧洗浄消臭することで新車のような爽やかな風が蘇ります。' },
            { icon: '❄️', title: 'エアコンガスの補充・添加剤', desc: 'ガス圧の真空引き補充とコンプレッサーオイル添加剤で冷却性能が驚くほどUPします。' }
        ],
        summary: '「冷えが悪い」「カビ臭い」と感じたら、本格的な夏・冬の前にメンテナンスを！'
    },
    car_wash_care: {
        badge: '🧼 愛車ケア＆美観【⑭】',
        title: '⑭ 洗車＆ボディコーティング術',
        subtitle: '愛車の輝きを長く保つプロのお手入れ法',
        sections: [
            { icon: '☀️', title: '炎天下の洗車はNG', desc: '水滴が焼き付くウォータースポットやイオンデポジットの原因に。曇りの日や朝夕がベストです。' },
            { icon: '🧽', title: '洗車キズを防ぐコツ', desc: 'たっぷりの水で砂を流し、カーシャンプーの泡クッションで優しく洗うのが鉄則です。' },
            { icon: '✨', title: 'ガラス系コーティングの効果', desc: '硬い被膜が紫外線や酸性雨、鳥フンから守り、水洗いで汚れがスルッと落ちるようになります。' }
        ],
        summary: 'アップファーレンでは納車時のプロコーティング施工やボディケアも承っております！'
    },
    winter_driving: {
        badge: '❄️ 冬道・降雪対策【⑮】',
        title: '⑮ 雪道運転と冬タイヤの極意',
        subtitle: '降雪地域の安心カーライフ！冬支度の鉄則',
        sections: [
            { icon: '🛞', title: 'スタッドレスの寿命基準', desc: '溝深さ50%の【プラットホーム】露出で使用不可に。3〜4シーズンでゴムが硬化します。' },
            { icon: '🛡️', title: '下回り防錆コーティング', desc: '融雪剤（塩カル）は下回りを急速にサビさせます。防錆アンダーコート塗装が愛車を守ります。' },
            { icon: '💧', title: '寒冷地用ウォッシャー液とワイパー', desc: '原液-30℃対応ウォッシャー液と凍りつかないスノーワイパーの装着が安心です。' }
        ],
        summary: 'アップファーレンでは冬タイヤの履き替え・下回り防錆点検も随時承っております！'
    },
    warning_lights: {
        badge: '🚨 緊急・トラブル診断【⑯】',
        title: '⑯ 警告灯の意味と緊急時の対処法',
        subtitle: '色でわかる危険度と初期対応マニュアル',
        sections: [
            { icon: '🔴', title: '赤色＝【直ちに安全な場所へ停車】', desc: '油圧警告灯（エンジン破損危険）、水温（オーバーヒート）、ブレーキフルード、充電異常。' },
            { icon: '🟡', title: '黄色＝【早めに整備工場へ】', desc: 'エンジン警告灯（センサー系異常）、ABS警告灯、タイヤ空気圧警告灯。' },
            { icon: '🔊', title: '走行中の異音チェック', desc: 'ブレーキのキーキー音（パッド摩耗）、段差のコトコト音、加速時のゴー音（ベアリング）。' }
        ],
        summary: '警告灯が点灯したり異音を感じたら、無理に走行を続けずすぐにご連絡ください！'
    },
    rain_driving: {
        badge: '🌧️ 雨天・悪天候対策【⑰】',
        title: '⑰ 雨の日の安全運転と冠水対策',
        subtitle: 'スリップ防止＆大雨時の水没トラブル回避術',
        sections: [
            { icon: '🌊', title: '冠水道路の限界（ドア下部まで）', desc: '吸気口に水が入るとウォーターハンマーでエンジンが全損します。深さが不明な場所は進入NG！' },
            { icon: '🛞', title: 'ハイドロプレーニング現象', desc: '水膜の上に車が浮いて操作不能になります。雨天時は通常より時速10〜20km減速を。' },
            { icon: '👀', title: '雨天の視界確保', desc: '油膜取り＋ガラス撥水コーティングと、定期的なワイパーゴム交換が安全を左右します。' }
        ],
        summary: 'ワイパー交換やガラス撥水施工もお気軽にご相談ください！'
    },
    accident_guide: {
        badge: '💥 緊急初動マニュアル【⑱】',
        title: '⑱ 事故・故障時の緊急対応手順',
        subtitle: '焦らず行動！現場で絶対にやるべき4ステップ',
        sections: [
            { icon: '1️⃣', title: '二次災害防止と安全確保', desc: 'ハザード点灯、発煙筒・三角停止板設置。高速ではガードレール外側へ避難。' },
            { icon: '2️⃣', title: '負傷者の救護（119番通報）', desc: 'けが人がいる場合は直ちに救急車を呼び応急手当を行います。' },
            { icon: '3️⃣', title: '警察への届出（110番・必須）', desc: '軽微な事故でも届出がないと「事故証明書」が出ず保険金が支払われません。' },
            { icon: '4️⃣', title: '相手確認と保険会社・店舗連絡', desc: '相手の連絡先やナンバーを控え、その場で示談せず保険会社と当店へすぐご連絡を。' }
        ],
        summary: '万が一のトラブル時はアップファーレンへもご連絡ください。レッカーや修理をサポートします。'
    },
    fuel_economy: {
        badge: '⛽ 燃費＆節約術【⑲】',
        title: '⑲ 燃費アップ＆愛車の節約術',
        subtitle: 'ちょっとしたコツで年間数万円の節約に！',
        sections: [
            { icon: '🟢', title: 'ふんわりアクセル「eスタート」', desc: '最初の5秒で時速20kmを目安にゆっくり踏み出すだけで約10%燃費が向上します。' },
            { icon: '💨', title: 'タイヤ空気圧の点検', desc: '空気圧は自然に月5〜10%低下します。適正値より低いと燃費が2〜4%悪化します。' },
            { icon: '📦', title: '不要な積載物の降車', desc: '100kgの荷物で燃費が約3%悪化します。トランクの荷物を整理しましょう。' },
            { icon: '🛢️', title: '低粘度オイルの活用', desc: '指定の省燃費オイル（0W-20等）でエンジン抵抗を減らし燃費を維持できます。' }
        ],
        summary: '日頃の小さな意識と定期的な点検でガソリン代を賢く節約しましょう！'
    },
    beginner_driver: {
        badge: '🔰 安心ドライブ【⑳】',
        title: '⑳ 初心者・ペーパードライバー安心術',
        subtitle: '運転の不安を解消する基本テクニック',
        sections: [
            { icon: '📐', title: '車幅感覚の掴み方', desc: '道路の白線がフロントガラスのどこを通るかを目印に覚えると左寄りの感覚が簡単に掴めます。' },
            { icon: '🅿️', title: 'バック駐車のコツ', desc: '枠に対して約45度に傾けてから開始し、ミラーで隣車の角と後輪の位置を確認しながら下がります。' },
            { icon: '👀', title: '死角確認と車間距離', desc: 'ミラーだけでなく目視確認が必須。「前車通過から2秒後に自分が通過」の間隔を保ちましょう。' }
        ],
        summary: 'バックカメラ付きや見切りの良い軽・コンパクトカーを多数ご用意しております！'
    },
    car_accessories: {
        badge: '🔌 便利アイテム・装備【㉑】',
        title: '㉑ ドラレコ・ETC・LED便利知識',
        subtitle: '後付け・アップグレードで愛車がもっと快適に！',
        sections: [
            { icon: '📷', title: 'ドラレコ（前後2カメラ必須）', desc: 'あおり運転対策に前後録画＋夜間STARVIS＋駐車監視機能付きがおすすめです。' },
            { icon: '🛣️', title: 'ETC2.0の割引メリット', desc: '圏央道などの約2割引や、道の駅利用の一時退出再進入などお得な機能が満載です。' },
            { icon: '💡', title: 'LEDヘッドライト化の注意点', desc: '夜間の視認性が劇的UP。車検対応のカットラインがしっかり出るバルブを選びましょう。' }
        ],
        summary: '持ち込みドラレコやETC・LEDの取り付け・配線加工もプロが丁寧に行います！'
    },
    car_appraisal: {
        badge: '💴 愛車売却＆査定UP【㉒】',
        title: '㉒ 愛車を高く売る・査定UP術',
        subtitle: '手放す前に知っておきたい高価買取の4大鉄則',
        sections: [
            { icon: '🧼', title: '査定前の洗車＆車内消臭', desc: 'タバコ・ペットの臭いを抜き、洗車をしておくだけで大切に乗られてきた車として評価UP。' },
            { icon: '📦', title: '純正パーツ・取扱説明書・スペアキー', desc: '社外品に変えていても純正品や整備手帳・予備鍵を揃えておくとプラス査定。' },
            { icon: '🛠️', title: '小傷は無理に直さない', desc: 'DIYのタッチペン補修はかえって減額要因に。そのまま査定に出すのが鉄則です。' },
            { icon: '🗓', title: 'モデルチェンジ前・車検前が狙い目', desc: '新型発表前や車検満了の1〜2ヶ月前に査定比較するのが一番得策です。' }
        ],
        summary: 'アップファーレンでは愛車の無料出張査定・高価下取りをいつでも承っております！'
    },
    tire_rotation: {
        badge: '🛞 タイヤ長持ち・安全【㉓】',
        title: '㉓ タイヤローテーションと偏摩耗',
        subtitle: '寿命を1.5倍に延ばす位置交換の基本',
        sections: [
            { icon: '🔄', title: '前後のタイヤ摩耗差の正体', desc: 'FF車は前輪が後輪の2〜3倍摩耗します。定期的な前後ローテーションで偏摩耗を防止。' },
            { icon: '⏱', title: '5,000kmまたはタイヤ履き替え時', desc: 'スタッドレス↔夏タイヤ交換のタイミングで前後を入れ替えるのが最も効率的です。' },
            { icon: '📐', title: '偏摩耗（片減り）の早期発見', desc: '片側だけ削れている場合は空気圧不足やアライメント狂いのサイン。' }
        ],
        summary: 'タイヤの無料残溝チェックやローテーション作業もお気軽にご用命ください！'
    },
    disaster_car_stay: {
        badge: '🏕️ 防災・緊急車中泊【㉔】',
        title: '㉔ 車の防災＆災害時車中泊マニュアル',
        subtitle: '豪雪立ち往生や震災時に命を守る備え',
        sections: [
            { icon: '☠️', title: 'マフラー埋没によるCO中毒防止', desc: '大雪での立ち往生時、マフラー周囲の除雪を欠かさず風下側の窓を少し開けておきます。' },
            { icon: '🔨', title: '緊急脱出用ガラス割りハンマー', desc: '水圧がかかった窓は手足では割れません。運転席の手の届く場所に常備しましょう。' },
            { icon: '🎒', title: '車載すべき防災7つ道具', desc: '毛布・モバイルバッテリー・非常食・携帯トイレ・スコップ・解氷剤・牽引ロープ。' }
        ],
        summary: '新潟の厳しい冬や突然の災害に備え、お車に防災グッズを常備しておきましょう！'
    },
    headlight_yellowing: {
        badge: '✨ 美観＆夜間視界【㉕】',
        title: '㉕ ヘッドライト黄ばみ除去と予防',
        subtitle: '見た目の若返り＆車検の光量不足対策',
        sections: [
            { icon: '☀️', title: '紫外線による樹脂劣化が原因', desc: 'ポリカーボネート樹脂が日光の紫外線と経年熱で黄変・白濁します。' },
            { icon: '⚠️', title: '放置すると車検落ちの危険！', desc: '黄ばみが進むと光が拡散し、車検基準のすれ違い前照灯（光度不足）で不合格に。' },
            { icon: '✨', title: '研磨クリーニング＆専用コーティング', desc: '劣代表層を研磨除去し、クリアコーティングで新車の透明感と光量を復活させます。' }
        ],
        summary: 'アップファーレンではヘッドライトのクリーニング＆プロコーティングも施工可能です！'
    },
    smart_key_battery: {
        badge: '🔑 トラブル緊急脱出【㉖】',
        title: '㉖ スマートキー電池切れ時の始動法',
        subtitle: '鍵が開かない・かからない時の完全手順',
        sections: [
            { icon: '🗝️', title: '内蔵メカニカルキーで解錠', desc: '側面の解除ボタンをスライドして物理キーを引き出し、ドア鍵穴に差して開けます。' },
            { icon: '🔘', title: 'スタートボタンにキーをタッチ！', desc: 'ブレーキを踏み、キーのエンブレム面をスタートボタンに接触させると始動可能に。' },
            { icon: '🔋', title: '電池寿命は約1〜2年（CR2032等）', desc: '常時電波を受信しているため消耗します。コンビニ等で買え自分で簡単に交換可能。' }
        ],
        summary: 'スマートキーの電池交換も店頭で数十秒で対応いたしますのでお気軽にどうぞ！'
    },
    hybrid_battery_care: {
        badge: '🔋 HV・EVの賢い乗り方【㉗】',
        title: '㉗ ハイブリッド車のバッテリー延命術',
        subtitle: '駆動用バッテリー長持ち＆補機バッテリーの盲点',
        sections: [
            { icon: '🌡️', title: '高温放置の回避と冷却口確保', desc: '後席横のバッテリー冷却ファン吸気口を塞がないようにしましょう。' },
            { icon: '⚡', title: '補機バッテリー（12V）の寿命（3年）', desc: 'システム起動用バッテリーが上がると駆動用が満タンでも車が起動できません。' },
            { icon: '📉', title: '定期的な走行で完全放電を防ぐ', desc: '数ヶ月放置すると自然放電で劣化します。月2〜3回は30分以上走行させましょう。' }
        ],
        summary: 'アップファーレンでは良質なハイブリッド・低燃費エコカーを多数取り揃えております！'
    },
    daily_car_check: {
        badge: '🔍 5分セルフ点検【㉘】',
        title: '㉘ 日常点検「ぶ・た・は・と・う・み・ず」',
        subtitle: 'プロ推奨！ドライブ前の簡単セルフチェック',
        sections: [
            { icon: '🛑', title: '【ぶ】ブレーキ＆ベルト', desc: 'ブレーキの踏みごたえ、エンジン始動時のキュルキュル異音がないか。' },
            { icon: '🛞', title: '【た】タイヤ', desc: '空気圧の見た目、残り溝の深さ、亀裂や釘刺さりがないか。' },
            { icon: '💡', title: '【は・とう】バッテリー＆灯火類', desc: 'セルモーターの音、ライト・ブレーキランプの球切れがないか。' },
            { icon: '💧', title: '【み・ず】オイル・冷却水・ウォッシャー', desc: 'エンジンオイル量、冷却水リザーブタンク、ウォッシャー液の残量を確認。' }
        ],
        summary: 'お出かけ前の無料安心点検も店頭でいつでも承っております！お気軽にお立ち寄りください。'
    }
};

/**
 * 豆知識詳細モーダルを開く
 */
function openKnowledgeDetailModal(topic) {
    const data = KNOWLEDGE_ARTICLES[topic] || KNOWLEDGE_ARTICLES['used_car'];
    const kModal = getEl('knowledgeDetailModal');
    const badgeEl = getEl('kModalBadge');
    const titleEl = getEl('kModalTitle');
    const subTitleEl = getEl('kModalSubtitle');
    const secContainer = getEl('kModalSections');
    const summaryEl = getEl('kModalSummary');

    if (badgeEl) badgeEl.textContent = data.badge;
    if (titleEl) titleEl.textContent = data.title;
    if (subTitleEl) subTitleEl.textContent = data.subtitle;
    if (summaryEl) summaryEl.textContent = '💡 ' + data.summary;

    if (secContainer) {
        secContainer.innerHTML = '';
        data.sections.forEach(sec => {
            const secBox = document.createElement('div');
            secBox.style.cssText = 'background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px;';
            secBox.innerHTML = `
                <div style="font-size: 13px; font-weight: 800; color: #1e293b; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                    <span>${sec.icon}</span> <span>${sec.title}</span>
                </div>
                <div style="font-size: 12px; color: #475569; line-height: 1.45;">
                    ${sec.desc.replace(/\\n/g, '<br>')}
                </div>
            `;
            secContainer.appendChild(secBox);
        });
    }

    if (kModal) kModal.style.display = 'flex';
}

async function triggerInitialLink() {
    const btn = getEl('initLinkBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 連携中...';
    }

    try {
        const payload = new URLSearchParams({
            action: 'init_customer_link',
            uid: state.userId,
            uname: state.userName
        });
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        if (data.success) {
            showToast('✅ 店舗との連携が完了しました！');
            await fetchCustomerData();
        } else {
            showToast('⚠️ 連携に失敗しました');
        }
    } catch (e) {
        showToast('⚠️ 通信エラーが発生しました');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-link"></i> 店舗と連携して手帳を発行する';
        }
    }
}

async function submitMaintenanceBooking(bookingType, prefTime) {
    const currentCar = getCurrentActiveCar();
    const course = currentCar?.car_model || getEl('inputCarModel')?.value || 'パソコン受講コース';
    let date = '未定';
    if (bookingType === '次回レッスン') {
        date = currentCar?.oil_next_date || getEl('inputOilNextDate')?.value || '近日中';
    } else if (bookingType === 'PC健康診断') {
        date = currentCar?.periodic_insp_next_date || getEl('inputPeriodicNextDate')?.value || '近日中';
    } else {
        date = currentCar?.inspection_next_date || getEl('inputInspNextDate')?.value || '未定';
    }

    const msg = `【${bookingType}の予約・相談】\n受講コース: ${course}\n予定日: ${date}\n希望時間帯: ${prefTime}\n\n上記の日程で予約・相談をお願いいたします。`;

    showToast('予約相談を送信中...');
    sendLineChatMessage(msg);
}

async function submitGeneralInquiry() {
    const submitBtn = getEl('submitGeneralInquiryBtn');
    const inquiryType = getEl('inquiryTypeSelect')?.value || '受講・相談';
    const carModel = getEl('inquiryCarModelInput')?.value.trim() || getCurrentActiveCar()?.car_model || 'パソコン・スマホ';
    const preferredDate = getEl('inquiryPreferredDate')?.value || '指定なし';
    const preferredTime = getEl('inquiryPreferredTime')?.value || 'いつでも';
    const details = getEl('inquiryDetailsInput')?.value.trim() || '';
    const needLoanCar = document.querySelector('input[name="needLoanCar"]:checked')?.value || '教室に来校';

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 送信中...';
    }

    try {
        const payload = new URLSearchParams({
            action: 'submit_general_inquiry',
            uid: state.userId,
            uname: state.userName,
            car_model: carModel,
            inquiry_type: inquiryType,
            preferred_date: preferredDate,
            preferred_time: preferredTime,
            details: details,
            need_loan_car: needLoanCar
        });

        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();

        if (data.success) {
            getEl('generalInquiryModal').style.display = 'none';
            showToast('✅ 受講予約・相談を送信しました！教室より折り返しご連絡いたします。');

            // LINEトークへのチャット送信
            const chatMsg = `【受講・PC相談の受付】\nご用件: ${inquiryType}\nご利用機器: ${carModel}\n希望日時: ${preferredDate} (${preferredTime})\nサポート形態: ${needLoanCar}` + (details ? `\n\n【相談内容】\n${details}` : '');
            sendLineChatMessage(chatMsg);
        } else {
            showToast('⚠️ ' + (data.error || '送信に失敗しました'));
        }
    } catch (e) {
        console.error('Submit general inquiry error:', e);
        showToast('⚠️ 通信エラーが発生しました');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> この内容で受講・相談を送信する';
        }
    }
}

function getCurrentActiveCar() {
    if (state.cars && state.cars.length > state.activeCarIndex) {
        return state.cars[state.activeCarIndex];
    }
    return null;
}

async function fetchCustomerData() {
    try {
        const res = await fetch(`../api.php?action=get_customer&uid=${encodeURIComponent(state.userId)}`);
        const data = await res.json();
        console.log('Customer data fetched:', data);
        if (data.success) {
            state.cars = data.cars || [];
            
            const linkCard = getEl('initLinkCard');
            if (state.cars.length === 0) {
                // LINEユーザーIDの場合、開いた瞬間に自動で店舗連携（ゼロタップ認識）を実行
                if (state.userId && state.userId.startsWith('U') && !state.hasAutoLinked) {
                    state.hasAutoLinked = true;
                    autoRegisterInitialLink();
                }

                if (linkCard) linkCard.style.display = 'block';
                state.activeCarIndex = -1;
                renderCarTabs();
                showNewCarForm();
            } else {
                if (linkCard) linkCard.style.display = 'none';
                if (state.activeCarIndex >= state.cars.length || state.activeCarIndex < 0) {
                    state.activeCarIndex = 0;
                }
                renderCarTabs();
                renderCarInfo(state.cars[state.activeCarIndex]);
            }
        }
    } catch (e) {
        console.warn('Fetch customer error:', e);
    }
}

async function autoRegisterInitialLink() {
    try {
        const payload = new URLSearchParams({
            action: 'init_customer_link',
            uid: state.userId,
            uname: state.userName
        });
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        if (data.success && data.cars && data.cars.length > 0) {
            state.cars = data.cars;
            state.activeCarIndex = 0;
            const linkCard = getEl('initLinkCard');
            if (linkCard) linkCard.style.display = 'none';
            renderCarTabs();
            renderCarInfo(state.cars[0]);
            showToast('🤝 店舗との連携が完了しました！');
        }
    } catch (e) {
        console.warn('Auto link error:', e);
    }
}

function renderCarTabs() {
    const container = getEl('carTabsContainer');
    if (!container) return;

    container.innerHTML = '';

    state.cars.forEach((car, idx) => {
        const btn = document.createElement('button');
        btn.className = `car-tab-item ${idx === state.activeCarIndex ? 'active' : ''}`;
        const carTitle = car.car_model || `受講コース ${idx + 1}`;
        btn.innerHTML = `<i class="fa-solid fa-graduation-cap"></i> <span>${escapeHtml(carTitle)}</span>`;
        btn.addEventListener('click', () => {
            state.activeCarIndex = idx;
            renderCarTabs();
            renderCarInfo(state.cars[idx]);
        });
        container.appendChild(btn);
    });

    // 「+ コースを追加」ボタン
    const addBtn = document.createElement('button');
    addBtn.className = 'car-tab-add';
    addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> コースを追加';
    addBtn.addEventListener('click', () => {
        showNewCarForm();
    });
    container.appendChild(addBtn);
}

function showNewCarForm() {
    state.activeCarIndex = -1; // 新規追加中モード
    
    // タブのactive解除
    document.querySelectorAll('.car-tab-item').forEach(el => el.classList.remove('active'));

    getEl('currentCarId').value = '';
    getEl('inputCarModel').value = '';
    getEl('inputCarNumber').value = '';
    getEl('inputOilNextDate').value = '';
    getEl('inputPeriodicNextDate').value = '';
    getEl('inputInspNextDate').value = '';

    const carModelEl = getEl('carModelDisplay');
    if (carModelEl) carModelEl.textContent = '新規登録のコース/機器';
    const carNumEl = getEl('carNumDisplay');
    if (carNumEl) carNumEl.textContent = '--';

    // バッジをリセット
    resetBadge(getEl('oilStatusBadge'), getEl('oilNextDateDisplay'));
    resetBadge(getEl('periodicStatusBadge'), getEl('periodicNextDateDisplay'));
    resetBadge(getEl('inspStatusBadge'), getEl('inspNextDateDisplay'));

    // 削除ボタン非表示
    const deleteBtn = getEl('deleteCarBtn');
    if (deleteBtn) deleteBtn.style.display = 'none';

    // フォーカス
    getEl('inputCarModel')?.focus();
    showToast('💻 受講コースや機器の情報を入力してください');
}

function resetBadge(badgeEl, displayEl) {
    if (badgeEl) {
        badgeEl.textContent = '未設定';
        badgeEl.className = 'maint-badge badge-warning';
    }
    if (displayEl) {
        displayEl.textContent = '未設定';
    }
}

function renderCarInfo(car) {
    if (!car) return;

    getEl('currentCarId').value = car.id || '';
    
    const carModelEl = getEl('carModelDisplay');
    const inputCarModelEl = getEl('inputCarModel');
    if (carModelEl) carModelEl.textContent = car.car_model || '未設定';
    if (inputCarModelEl) inputCarModelEl.value = car.car_model || '';

    const carNumEl = getEl('carNumDisplay');
    const inputCarNumEl = getEl('inputCarNumber');
    if (carNumEl) carNumEl.textContent = car.car_number ? `No. ${car.car_number}` : '--';
    if (inputCarNumEl) inputCarNumEl.value = car.car_number || '';

    // オイル交換
    const oilDateEl = getEl('oilNextDateDisplay');
    const inputOilEl = getEl('inputOilNextDate');
    const oilBadgeEl = getEl('oilStatusBadge');
    if (car.oil_next_date) {
        if (oilDateEl) oilDateEl.textContent = car.oil_next_date;
        if (inputOilEl) inputOilEl.value = car.oil_next_date;
        updateBadge(oilBadgeEl, car.oil_next_date);
    } else {
        resetBadge(oilBadgeEl, oilDateEl);
        if (inputOilEl) inputOilEl.value = '';
    }

    // 12ヶ月定期点検
    const periodicDateEl = getEl('periodicNextDateDisplay');
    const inputPeriodicEl = getEl('inputPeriodicNextDate');
    const periodicBadgeEl = getEl('periodicStatusBadge');
    if (car.periodic_insp_next_date) {
        if (periodicDateEl) periodicDateEl.textContent = car.periodic_insp_next_date;
        if (inputPeriodicEl) inputPeriodicEl.value = car.periodic_insp_next_date;
        updateBadge(periodicBadgeEl, car.periodic_insp_next_date);
    } else {
        resetBadge(periodicBadgeEl, periodicDateEl);
        if (inputPeriodicEl) inputPeriodicEl.value = '';
    }

    // 車検
    const inspDateEl = getEl('inspNextDateDisplay');
    const inputInspEl = getEl('inputInspNextDate');
    const inspBadgeEl = getEl('inspStatusBadge');
    if (car.inspection_next_date) {
        if (inspDateEl) inspDateEl.textContent = car.inspection_next_date;
        if (inputInspEl) inputInspEl.value = car.inspection_next_date;
        updateBadge(inspBadgeEl, car.inspection_next_date);
    } else {
        resetBadge(inspBadgeEl, inspDateEl);
        if (inputInspEl) inputInspEl.value = '';
    }

    // 複数台ある場合は削除ボタンを表示
    const deleteBtn = getEl('deleteCarBtn');
    if (deleteBtn) {
        deleteBtn.style.display = (state.cars.length > 1) ? 'flex' : 'none';
    }
}

function updateBadge(badgeEl, targetDateStr) {
    if (!badgeEl || !targetDateStr) return;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const target = new Date(targetDateStr);
    target.setHours(0, 0, 0, 0);

    const diffDays = Math.ceil((target - today) / (1000 * 60 * 60 * 24));

    if (diffDays < 0) {
        badgeEl.textContent = `期限切れ (${Math.abs(diffDays)}日前)`;
        badgeEl.className = 'maint-badge badge-danger';
    } else if (diffDays === 0) {
        badgeEl.textContent = '本日が予定日！';
        badgeEl.className = 'maint-badge badge-danger';
    } else if (diffDays <= 14) {
        badgeEl.textContent = `あと ${diffDays} 日`;
        badgeEl.className = 'maint-badge badge-warning';
    } else {
        badgeEl.textContent = `あと ${diffDays} 日`;
        badgeEl.className = 'maint-badge badge-safe';
    }
}

async function saveCustomerData() {
    const carId = getEl('currentCarId')?.value || '';
    const carModel = getEl('inputCarModel')?.value.trim() || '';
    const carNumber = getEl('inputCarNumber')?.value.trim() || '';
    const oilDate = getEl('inputOilNextDate')?.value || '';
    const periodicDate = getEl('inputPeriodicNextDate')?.value || '';
    const inspDate = getEl('inputInspNextDate')?.value || '';

    if (!carModel) {
        showToast('⚠️ 受講コース名を入力してください');
        return;
    }

    const saveBtn = getEl('saveCustBtn');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 保存中...';
    }

    const payload = new URLSearchParams({
        action: 'save_customer',
        car_id: carId,
        uid: state.userId,
        uname: state.userName,
        car_model: carModel,
        car_number: carNumber,
        oil_next_date: oilDate,
        periodic_insp_next_date: periodicDate,
        inspection_next_date: inspDate
    });

    console.log('Saving course with payload:', payload.toString());

    try {
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        console.log('Save response:', data);
        if (data.success) {
            showToast('✅ 情報を保存し、LINEへ確認メッセージをお送りしました！');
            await fetchCustomerData();
        } else {
            showToast('⚠️ ' + (data.error || '保存に失敗しました'));
        }
    } catch (e) {
        console.error('Save error:', e);
        showToast('✅ 保存内容を更新しました');
    } finally {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> この内容で保存する';
        }
    }
}

async function deleteActiveCar() {
    const car = getCurrentActiveCar();
    if (!car || !car.id) return;

    if (!confirm(`受講コース「${car.car_model || '選択中のコース'}」を削除してもよろしいですか？`)) {
        return;
    }

    const payload = new URLSearchParams({
        action: 'delete_customer_car',
        car_id: car.id,
        uid: state.userId
    });

    try {
        const res = await fetch('../api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        });
        const data = await res.json();
        if (data.success) {
            showToast('🗑️ コース情報を削除しました');
            state.activeCarIndex = 0;
            await fetchCustomerData();
        } else {
            showToast('⚠️ ' + (data.error || '削除に失敗しました'));
        }
    } catch (e) {
        showToast('⚠️ 通信エラーが発生しました');
    }
}

function sendLineChatMessage(text) {
    if (typeof liff !== 'undefined' && liff.isInClient()) {
        liff.sendMessages([{ type: 'text', text: text }]).then(() => {
            showToast('✅ メッセージを送信しました！');
            setTimeout(() => { liff.closeWindow(); }, 1000);
        }).catch(() => {
            copyToClipboard(text);
        });
    } else {
        copyToClipboard(text);
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('📋 メッセージ文面をコピーしました！LINEトークに貼り付けて送信してください。');
    }).catch(() => {
        showToast('メッセージを作成しました。');
    });
}

function showToast(msg) {
    const toast = getEl('appToast');
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3500);
}

function escapeHtml(str) {
    return (str || '').replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    })[m]);
}
