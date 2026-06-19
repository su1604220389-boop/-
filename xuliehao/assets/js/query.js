const queryForm = document.getElementById('queryForm');
const serialInput = document.getElementById('serialInput');
const queryMessage = document.getElementById('queryMessage');
const announcementText = document.getElementById('announcementText');
const resultCard = document.getElementById('resultCard');
const resultTitle = document.getElementById('resultTitle');
const resultDescription = document.getElementById('resultDescription');
const resultDetails = document.getElementById('resultDetails');
const queryShell = document.getElementById('queryShell');
const settingsUpdatedAt = document.getElementById('settingsUpdatedAt');

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function setMessage(message, isError = false) {
    queryMessage.textContent = message;
    queryMessage.style.color = isError ? '#ffb8b4' : '';
}

function applySettings(settings = {}) {
    announcementText.textContent = settings.announcement || '暂无公告。';
    settingsUpdatedAt.textContent = settings.updatedAt ? `更新于 ${settings.updatedAt}` : '暂无更新记录';

    if (settings.backgroundImage) {
        queryShell.style.backgroundImage = `linear-gradient(rgba(3, 10, 20, 0.42), rgba(3, 10, 20, 0.8)), url("./${settings.backgroundImage}")`;
    }
}

function renderResult(data) {
    const { found, record } = data;
    resultCard.classList.remove('result-empty', 'result-miss', 'result-success');

    if (!found || !record) {
        resultCard.classList.add('result-miss');
        resultTitle.textContent = '未找到序列号';
        resultDescription.textContent = '当前系统中没有匹配记录，请确认输入是否正确。';
        resultDetails.innerHTML = '';
        return;
    }

    resultCard.classList.add('result-success');
    resultTitle.textContent = record.serial;
    resultDescription.textContent = record.remark || '该序列号已存在于系统中。';
    resultDetails.innerHTML = `
        <div><dt>状态</dt><dd>${escapeHtml(record.status)}</dd></div>
        <div><dt>批次</dt><dd>${escapeHtml(record.batch || '-')}</dd></div>
        <div><dt>额外说明</dt><dd>${escapeHtml(record.extraInfo || '-')}</dd></div>
        <div><dt>最后更新</dt><dd>${escapeHtml(record.updatedAt || '-')}</dd></div>
    `;
}

async function loadSettings() {
    try {
        const response = await fetch('./api/settings/get.php');
        const payload = await response.json();
        if (payload.success) {
            applySettings(payload.data);
        }
    } catch (error) {
        setMessage('公告和背景图加载失败。', true);
    }
}

queryForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const serial = serialInput.value.trim();

    if (!serial) {
        setMessage('请输入序列号后再查询。', true);
        return;
    }

    setMessage('正在查询，请稍候...');

    try {
        const response = await fetch('./api/query.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ serial }),
        });
        const payload = await response.json();
        if (!payload.success) {
            throw new Error(payload.message || '查询失败');
        }

        applySettings(payload.data.settings);
        renderResult(payload.data);
        setMessage(payload.message);
    } catch (error) {
        renderResult({ found: false, record: null });
        setMessage(error.message || '查询失败，请稍后重试。', true);
    }
});

loadSettings();
