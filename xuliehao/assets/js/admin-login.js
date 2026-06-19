const loginForm = document.getElementById('loginForm');
const loginMessage = document.getElementById('loginMessage');

function setLoginMessage(message, isError = false) {
    loginMessage.textContent = message;
    loginMessage.style.color = isError ? '#ffb8b4' : '';
}

async function checkLoginStatus() {
    try {
        const response = await fetch('./api/auth/me.php');
        if (!response.ok) {
            return;
        }

        const payload = await response.json();
        if (payload.success) {
            window.location.href = './admin.html';
        }
    } catch (error) {
        console.debug('管理员登录状态检查失败', error);
    }
}

loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const formData = new FormData(loginForm);
    const username = String(formData.get('username') || '').trim();
    const password = String(formData.get('password') || '');

    if (!username || !password) {
        setLoginMessage('请输入完整的账号和密码。', true);
        return;
    }

    setLoginMessage('正在验证身份...');

    try {
        const response = await fetch('./api/auth/login.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ username, password }),
        });
        const payload = await response.json();

        if (!payload.success) {
            throw new Error(payload.message || '登录失败');
        }

        setLoginMessage('登录成功，正在进入后台...');
        window.location.href = './admin.html';
    } catch (error) {
        setLoginMessage(error.message || '登录失败，请稍后再试。', true);
    }
});

checkLoginStatus();
