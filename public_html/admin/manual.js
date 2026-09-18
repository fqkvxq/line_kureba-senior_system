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
    const savedToken = sessionStorage.getItem('admin_auth_token') || getCookie('admin_auth_token');
    const savedPass = sessionStorage.getItem('admin_pass') || getCookie('admin_pass');
    
    const loginModal = document.getElementById('loginModal');
    const loginStep1Wrap = document.getElementById('loginStep1Wrap');
    const loginStep2Wrap = document.getElementById('loginStep2Wrap');
    const login2FAEmailHint = document.getElementById('login2FAEmailHint');
    const adminPasswordInput = document.getElementById('adminPasswordInput');
    const admin2FACodeInput = document.getElementById('admin2FACodeInput');
    const loginBtn = document.getElementById('loginBtn');
    const btnVerify2FA = document.getElementById('btnVerify2FA');
    const btnBackToPassword = document.getElementById('btnBackToPassword');
    const btnResend2FACode = document.getElementById('btnResend2FACode');
    const resendTimerText = document.getElementById('resendTimerText');
    const loginErrorMsg = document.getElementById('loginErrorMsg');
    const adminApp = document.getElementById('adminApp');
    const logoutBtn = document.getElementById('logoutBtn');

    let twoFactorSessionToken = '';
    let resendTimerInterval = null;
    let resendCountdown = 0;

    const showLoginError = (msg) => {
        if (loginErrorMsg) {
            loginErrorMsg.textContent = msg;
            loginErrorMsg.style.display = 'block';
        }
    };

    const hideLoginError = () => {
        if (loginErrorMsg) {
            loginErrorMsg.textContent = '';
            loginErrorMsg.style.display = 'none';
        }
    };

    const finishLoginSuccess = (pass, authToken) => {
        if (authToken) {
            sessionStorage.setItem('admin_auth_token', authToken);
            document.cookie = "admin_auth_token=" + encodeURIComponent(authToken) + "; path=/; max-age=" + (86400 * 30) + "; SameSite=Lax";
        }
        if (pass) {
            sessionStorage.setItem('admin_pass', pass);
            document.cookie = "admin_pass=" + encodeURIComponent(pass) + "; path=/; max-age=" + (86400 * 30) + "; SameSite=Lax";
        }
        hideLoginError();
        if (loginModal) loginModal.style.display = 'none';
        if (adminApp) adminApp.style.display = 'flex';
    };

    const startResendTimer = (seconds = 60) => {
        if (resendTimerInterval) clearInterval(resendTimerInterval);
        resendCountdown = seconds;

        const updateUI = () => {
            if (resendTimerText) {
                resendTimerText.textContent = resendCountdown > 0 ? `(${resendCountdown}秒)` : '';
            }
            if (btnResend2FACode) {
                btnResend2FACode.disabled = resendCountdown > 0;
                btnResend2FACode.style.opacity = resendCountdown > 0 ? '0.5' : '1';
                btnResend2FACode.style.cursor = resendCountdown > 0 ? 'not-allowed' : 'pointer';
            }
        };

        updateUI();
        resendTimerInterval = setInterval(() => {
            resendCountdown--;
            if (resendCountdown <= 0) {
                clearInterval(resendTimerInterval);
                resendCountdown = 0;
            }
            updateUI();
        }, 1000);
    };

    const backToPasswordStep = () => {
        if (resendTimerInterval) clearInterval(resendTimerInterval);
        twoFactorSessionToken = '';
        hideLoginError();
        if (loginStep2Wrap) loginStep2Wrap.style.display = 'none';
        if (loginStep1Wrap) loginStep1Wrap.style.display = 'block';
        if (adminPasswordInput) adminPasswordInput.focus();
    };

    if (savedToken || savedPass) {
        if (savedToken) sessionStorage.setItem('admin_auth_token', savedToken);
        if (savedPass) sessionStorage.setItem('admin_pass', savedPass);
        if (loginModal) loginModal.style.display = 'none';
        if (adminApp) adminApp.style.display = 'flex';
    } else {
        if (loginModal) loginModal.style.display = 'flex';
        if (adminApp) adminApp.style.display = 'none';
    }

    const attemptLogin = async () => {
        const pass = adminPasswordInput ? adminPasswordInput.value.trim() : '';
        if (!pass) {
            showLoginError('パスワードを入力してください');
            return;
        }

        if (loginBtn) {
            loginBtn.disabled = true;
            loginBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 確認中...';
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
                twoFactorSessionToken = data.session_token;
                if (login2FAEmailHint) {
                    login2FAEmailHint.textContent = data.email_hint || 'kawai@kureba.co.jp';
                }
                if (loginStep1Wrap) loginStep1Wrap.style.display = 'none';
                if (loginStep2Wrap) loginStep2Wrap.style.display = 'block';
                if (admin2FACodeInput) {
                    admin2FACodeInput.value = '';
                    admin2FACodeInput.focus();
                }
                startResendTimer(60);
            } else {
                finishLoginSuccess(pass, data.auth_token);
            }
        } catch (e) {
            showLoginError('通信エラーが発生しました: ' + e.message);
        } finally {
            if (loginBtn) {
                loginBtn.disabled = false;
                loginBtn.innerHTML = 'ログインして閲覧';
            }
        }
    };

    const attemptVerify2FA = async () => {
        const code = admin2FACodeInput ? admin2FACodeInput.value.trim() : '';
        if (!code || code.length < 6) {
            showLoginError('6桁の認証コードを入力してください');
            return;
        }

        if (btnVerify2FA) {
            btnVerify2FA.disabled = true;
            btnVerify2FA.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 認証中...';
        }
        hideLoginError();

        try {
            const payload = new URLSearchParams({
                action: 'admin_login_verify_2fa',
                session_token: twoFactorSessionToken,
                code: code
            });
            const res = await fetch('../api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString()
            });
            const data = await res.json();

            if (data.success && data.auth_token) {
                if (resendTimerInterval) clearInterval(resendTimerInterval);
                finishLoginSuccess(adminPasswordInput ? adminPasswordInput.value.trim() : '', data.auth_token);
            } else {
                showLoginError(data.error || '認証コードが正しくありません');
            }
        } catch (e) {
            showLoginError('認証通信エラー: ' + e.message);
        } finally {
            if (btnVerify2FA) {
                btnVerify2FA.disabled = false;
                btnVerify2FA.innerHTML = '認証してログイン';
            }
        }
    };

    const attemptResend2FA = async () => {
        if (resendCountdown > 0) return;
        if (!twoFactorSessionToken) return;

        if (btnResend2FACode) btnResend2FACode.disabled = true;
        hideLoginError();

        try {
            const payload = new URLSearchParams({
                action: 'admin_login_resend_2fa',
                session_token: twoFactorSessionToken
            });
            const res = await fetch('../api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString()
            });
            const data = await res.json();

            if (data.success) {
                alert('認証コードを再送信しました！メールをご確認ください。');
                startResendTimer(60);
            } else {
                showLoginError(data.error || 'コードの再送信に失敗しました');
            }
        } catch (e) {
            showLoginError('再送信エラー: ' + e.message);
        } finally {
            if (btnResend2FACode && resendCountdown <= 0) {
                btnResend2FACode.disabled = false;
            }
        }
    };

    if (loginBtn) loginBtn.addEventListener('click', attemptLogin);
    if (adminPasswordInput) {
        adminPasswordInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') attemptLogin();
        });
    }
    if (btnVerify2FA) btnVerify2FA.addEventListener('click', attemptVerify2FA);
    if (admin2FACodeInput) {
        admin2FACodeInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') attemptVerify2FA();
        });
    }
    if (btnBackToPassword) btnBackToPassword.addEventListener('click', backToPasswordStep);
    if (btnResend2FACode) btnResend2FACode.addEventListener('click', attemptResend2FA);

    if (logoutBtn) {
        logoutBtn.addEventListener('click', async () => {
            try {
                await fetch('../api.php?action=admin_logout', { method: 'POST' });
            } catch (e) {}
            sessionStorage.removeItem('admin_auth_token');
            sessionStorage.removeItem('admin_pass');
            document.cookie = "admin_auth_token=; path=/; max-age=0; SameSite=Lax";
            document.cookie = "admin_pass=; path=/; max-age=0; SameSite=Lax";
            if (resendTimerInterval) clearInterval(resendTimerInterval);
            if (adminApp) adminApp.style.display = 'none';
            if (loginModal) loginModal.style.display = 'flex';
            if (loginStep1Wrap) loginStep1Wrap.style.display = 'block';
            if (loginStep2Wrap) loginStep2Wrap.style.display = 'none';
            if (adminPasswordInput) {
                adminPasswordInput.value = '';
                adminPasswordInput.focus();
            }
            hideLoginError();
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
