/**
 * アップファーレン 店舗管理 操作マニュアル用 JavaScript
 */

document.addEventListener('DOMContentLoaded', () => {
    initAuth();
    initSearch();
    initScrollSpy();
});

function initAuth() {
    const savedPass = sessionStorage.getItem('admin_pass');
    const loginModal = document.getElementById('loginModal');
    const adminApp = document.getElementById('adminApp');
    const adminPasswordInput = document.getElementById('adminPasswordInput');
    const loginBtn = document.getElementById('loginBtn');
    const loginErrorMsg = document.getElementById('loginErrorMsg');
    const logoutBtn = document.getElementById('logoutBtn');

    if (savedPass) {
        // すでに認証済み
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

            const res = await fetch(`../api.php?action=admin_get_customers&password=${encodeURIComponent(pass)}`);
            const data = await res.json();

            if (data.success) {
                sessionStorage.setItem('admin_pass', pass);
                if (loginModal) loginModal.style.display = 'none';
                if (adminApp) adminApp.style.display = 'flex';
            } else {
                if (loginErrorMsg) {
                    loginErrorMsg.textContent = data.error || 'パスワードが正しくありません';
                    loginErrorMsg.style.display = 'block';
                }
            }
        } catch (e) {
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
