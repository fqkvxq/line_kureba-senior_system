const manualState = {
    accounts: [],
    activeAccount: 'senior',
    activeAccountInfo: null
};

document.addEventListener('DOMContentLoaded', async () => {
    await loadAccounts();
    initAuth();
    initSearch();
    initScrollSpy();
});

async function loadAccounts() {
    const accountSelect = document.getElementById('accountSelect');
    const badgeDot = document.getElementById('accountBadgeDot');
    const brandTitle = document.getElementById('systemBrandTitle');
    const brandBadge = document.getElementById('systemBrandBadge');

    try {
        const savedAccount = localStorage.getItem('active_line_account') || '';
        const url = `../api.php?action=get_accounts${savedAccount ? '&account=' + encodeURIComponent(savedAccount) : ''}`;
        const res = await fetch(url);
        const data = await res.json();
        if (data.success && Array.isArray(data.accounts)) {
            manualState.accounts = data.accounts;
            manualState.activeAccount = data.active_account || 'senior';
            manualState.activeAccountInfo = data.active_account_info || null;
            localStorage.setItem('active_line_account', manualState.activeAccount);

            if (accountSelect) {
                accountSelect.innerHTML = manualState.accounts.map(acc => {
                    const isSelected = acc.id === manualState.activeAccount;
                    const configNote = !acc.is_configured ? ' (⚠️未設定)' : '';
                    return `<option value="${escapeHtml(acc.id)}" ${isSelected ? 'selected' : ''}>${escapeHtml(acc.name)}${configNote}</option>`;
                }).join('');

                accountSelect.addEventListener('change', async (e) => {
                    const newKey = e.target.value;
                    if (!newKey || newKey === manualState.activeAccount) return;
                    try {
                        const switchRes = await fetch('../api.php?action=switch_account', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `account=${encodeURIComponent(newKey)}`
                        });
                        const switchData = await switchRes.json();
                        if (switchData.success) {
                            manualState.activeAccount = switchData.active_account;
                            manualState.activeAccountInfo = switchData.active_account_info;
                            localStorage.setItem('active_line_account', manualState.activeAccount);
                            updateBrandDisplay();
                        }
                    } catch (err) {
                        console.error('Account switch failed:', err);
                    }
                });
            }

            updateBrandDisplay();
        }
    } catch (e) {
        console.error('Failed to load accounts in manual:', e);
    }

    function updateBrandDisplay() {
        const currentAcc = manualState.accounts.find(a => a.id === manualState.activeAccount) || manualState.activeAccountInfo;
        if (currentAcc) {
            if (brandTitle) brandTitle.textContent = `${currentAcc.name} 管理システム`;
            if (brandBadge) brandBadge.style.background = currentAcc.theme_color || '#4f46e5';
            if (badgeDot) badgeDot.style.background = currentAcc.theme_color || '#ff8700';
        }
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[m]);
}

function initAuth() {
    const getCookie = (name) => {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return decodeURIComponent(parts.pop().split(';').shift());
        return '';
    };
    const savedPass = sessionStorage.getItem('admin_pass') || getCookie('admin_pass');
    const loginModal = document.getElementById('loginModal');
    const adminApp = document.getElementById('adminApp');
    const adminPasswordInput = document.getElementById('adminPasswordInput');
    const loginBtn = document.getElementById('loginBtn');
    const loginErrorMsg = document.getElementById('loginErrorMsg');
    const logoutBtn = document.getElementById('logoutBtn');

    if (savedPass) {
        // すでに認証済み
        sessionStorage.setItem('admin_pass', savedPass);
        if (loginModal) loginModal.style.display = 'none';
        if (adminApp) adminApp.style.display = 'flex';
    } else {
        // パスワード入力要求
        if (loginModal) loginModal.style.display = 'flex';
        if (adminApp) adminApp.style.display = 'none';
    }

    const attemptLogin = async () => {
        const pass = adminPasswordInput.value.trim();
        if (!pass) return;

        try {
            loginBtn.disabled = true;
            loginBtn.textContent = '認証中...';

            const res = await fetch(`../api.php?action=admin_list_customers&password=${encodeURIComponent(pass)}`, {
                headers: { 
                    'X-Admin-Password': pass,
                    'Authorization': `Bearer ${pass}`
                }
            });
            const data = await res.json();

            if (data.success) {
                sessionStorage.setItem('admin_pass', pass);
                document.cookie = "admin_pass=" + encodeURIComponent(pass) + "; path=/; max-age=" + (86400 * 30) + "; SameSite=Lax";
                if (loginModal) loginModal.style.display = 'none';
                if (adminApp) adminApp.style.display = 'flex';
            } else {
                sessionStorage.removeItem('admin_pass');
                document.cookie = "admin_pass=; path=/; max-age=0; SameSite=Lax";
                if (loginErrorMsg) {
                    loginErrorMsg.textContent = data.error || 'パスワードが正しくありません';
                    loginErrorMsg.style.display = 'block';
                }
            }
        } catch (e) {
            sessionStorage.removeItem('admin_pass');
            document.cookie = "admin_pass=; path=/; max-age=0; SameSite=Lax";
            if (loginErrorMsg) {
                loginErrorMsg.textContent = '通信エラーが発生しました';
                loginErrorMsg.style.display = 'block';
            }
        } finally {
            loginBtn.disabled = false;
            loginBtn.textContent = 'ログインして閲覧';
        }
    };

    if (loginBtn) loginBtn.addEventListener('click', attemptLogin);
    if (adminPasswordInput) {
        adminPasswordInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') attemptLogin();
        });
    }

    if (logoutBtn) {
        logoutBtn.addEventListener('click', () => {
            sessionStorage.removeItem('admin_pass');
            document.cookie = "admin_pass=; path=/; max-age=0; SameSite=Lax";
            if (adminApp) adminApp.style.display = 'none';
            if (loginModal) loginModal.style.display = 'flex';
            if (adminPasswordInput) adminPasswordInput.value = '';
        });
    }
}

function initSearch() {
    const searchInput = document.getElementById('manualSearchInput');
    if (!searchInput) return;

    const sections = document.querySelectorAll('.manual-section');

    searchInput.addEventListener('input', (e) => {
        const query = e.target.value.trim().toLowerCase();

        sections.forEach(sec => {
            if (!query) {
                sec.style.display = 'block';
                return;
            }

            const text = sec.textContent.toLowerCase();
            if (text.includes(query)) {
                sec.style.display = 'block';
            } else {
                sec.style.display = 'none';
            }
        });
    });
}

function initScrollSpy() {
    const tocLinks = document.querySelectorAll('.toc-item a');
    const sections = document.querySelectorAll('.manual-section');

    window.addEventListener('scroll', () => {
        let currentId = '';
        const scrollY = window.pageYOffset || document.documentElement.scrollTop;

        sections.forEach(section => {
            const sectionTop = section.offsetTop - 120;
            const sectionHeight = section.offsetHeight;
            if (scrollY >= sectionTop && scrollY < sectionTop + sectionHeight) {
                currentId = section.getAttribute('id');
            }
        });

        tocLinks.forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href') === `#${currentId}`) {
                link.classList.add('active');
            }
        });
    });
}
