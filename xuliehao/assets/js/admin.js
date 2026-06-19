const state = {
    activeSection: window.location.hash ? window.location.hash.slice(1) : 'overview',
    currentAdmin: null,
    stats: { serialCount: 0, adminCount: 0 },
    serials: [],
    admins: [],
    logs: [],
    settings: {},
    csrfToken: '',
};

const roleDefaults = {
    viewer_admin: ['serial.view'],
    content_admin: ['serial.view', 'serial.create', 'serial.update', 'serial.delete', 'settings.update'],
    super_admin: ['serial.view', 'serial.create', 'serial.update', 'serial.delete', 'settings.update', 'admins.manage'],
};

const refs = {
    adminIdentity: document.getElementById('adminIdentity'),
    globalMessage: document.getElementById('globalMessage'),
    statRole: document.getElementById('statRole'),
    statSerialCount: document.getElementById('statSerialCount'),
    statAdminCount: document.getElementById('statAdminCount'),
    statSettingsUpdated: document.getElementById('statSettingsUpdated'),
    serialTableBody: document.getElementById('serialTableBody'),
    serialForm: document.getElementById('serialForm'),
    serialFormTitle: document.getElementById('serialFormTitle'),
    serialSearchForm: document.getElementById('serialSearchForm'),
    serialCancelButton: document.getElementById('serialCancelButton'),
    serialResetButton: document.getElementById('serialResetButton'),
    settingsForm: document.getElementById('settingsForm'),
    announcementInput: document.getElementById('announcementInput'),
    backgroundUploadForm: document.getElementById('backgroundUploadForm'),
    backgroundPreview: document.getElementById('backgroundPreview'),
    backgroundPath: document.getElementById('backgroundPath'),
    adminSection: document.getElementById('admins'),
    adminsNavLink: document.getElementById('adminsNavLink'),
    logsSection: document.getElementById('logs'),
    logsNavLink: document.getElementById('logsNavLink'),
    logTableBody: document.getElementById('logTableBody'),
    adminTableBody: document.getElementById('adminTableBody'),
    adminForm: document.getElementById('adminForm'),
    adminFormTitle: document.getElementById('adminFormTitle'),
    adminCancelButton: document.getElementById('adminCancelButton'),
    profileForm: document.getElementById('profileForm'),
    logoutButton: document.getElementById('logoutButton'),
    sectionLinks: Array.from(document.querySelectorAll('.sidebar-nav a[data-section]')),
    panelSections: Array.from(document.querySelectorAll('.admin-main > .panel-section')),
};

function field(form, name) {
    return form.querySelector(`[name="${name}"]`);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function setGlobalMessage(message, isError = false) {
    refs.globalMessage.textContent = message;
    refs.globalMessage.style.color = isError ? '#ffb8b4' : '';
}

function getAvailableSectionIds() {
    return refs.sectionLinks
        .filter((link) => !link.classList.contains('hidden'))
        .map((link) => link.dataset.section)
        .filter((sectionId) => {
            const section = document.getElementById(sectionId);
            return Boolean(section) && !section.classList.contains('hidden');
        });
}

function normalizeSectionId(sectionId) {
    const availableSections = getAvailableSectionIds();
    if (availableSections.includes(sectionId)) {
        return sectionId;
    }

    if (availableSections.includes('overview')) {
        return 'overview';
    }

    return availableSections[0] || 'overview';
}

function setActiveSection(sectionId, options = {}) {
    const { updateHash = true } = options;
    const nextSectionId = normalizeSectionId(sectionId);
    state.activeSection = nextSectionId;

    refs.panelSections.forEach((section) => {
        const shouldShow = section.id === nextSectionId && !section.classList.contains('hidden');
        section.classList.toggle('panel-collapsed', !shouldShow);
    });

    refs.sectionLinks.forEach((link) => {
        link.classList.toggle('is-active', link.dataset.section === nextSectionId);
    });

    if (updateHash) {
        window.history.replaceState(null, '', `#${nextSectionId}`);
    }
}

function ensureActiveSectionVisible() {
    setActiveSection(state.activeSection, { updateHash: false });
}

function bindSidebarNavigation() {
    refs.sectionLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            setActiveSection(link.dataset.section);
        });
    });
}

function hasPermission(permission) {
    if (!state.currentAdmin) {
        return false;
    }

    if (state.currentAdmin.role === 'super_admin') {
        return true;
    }

    return (state.currentAdmin.permissions || []).includes(permission);
}

async function requestJson(url, options = {}) {
    const requestOptions = { ...options };
    const method = String(requestOptions.method || 'GET').toUpperCase();
    const headers = new Headers(requestOptions.headers || {});

    if (method !== 'GET' && state.csrfToken && !headers.has('X-CSRF-Token')) {
        headers.set('X-CSRF-Token', state.csrfToken);
    }

    requestOptions.headers = headers;

    const response = await fetch(url, requestOptions);
    const rawText = await response.text();
    let payload;

    try {
        payload = JSON.parse(rawText);
    } catch (error) {
        throw new Error(`接口返回了无效响应：${response.status} ${response.statusText}`);
    }

    if (!payload.success) {
        throw new Error(payload.message || '请求失败');
    }

    if (payload.data && typeof payload.data === 'object' && payload.data.csrfToken) {
        state.csrfToken = String(payload.data.csrfToken);
    }

    return payload;
}

function resetSerialForm() {
    refs.serialForm.reset();
    field(refs.serialForm, 'id').value = '';
    refs.serialFormTitle.textContent = '新增序列号';
}

function resetAdminForm() {
    refs.adminForm.reset();
    field(refs.adminForm, 'id').value = '';
    refs.adminFormTitle.textContent = '创建管理员';
    applyRoleDefaults(field(refs.adminForm, 'role').value || 'viewer_admin');
}

function collectPermissions() {
    return Array.from(refs.adminForm.querySelectorAll('input[name="permissions"]:checked')).map((item) => item.value);
}

function applyRoleDefaults(role) {
    const defaults = roleDefaults[role] || [];
    refs.adminForm.querySelectorAll('input[name="permissions"]').forEach((input) => {
        input.checked = defaults.includes(input.value);
    });
}

function fillPermissionCheckboxes(permissions = []) {
    refs.adminForm.querySelectorAll('input[name="permissions"]').forEach((input) => {
        input.checked = permissions.includes(input.value);
    });
}

function renderOverview() {
    refs.adminIdentity.textContent = `${state.currentAdmin.username} / ${state.currentAdmin.role}`;
    refs.statRole.textContent = state.currentAdmin.role;
    refs.statSerialCount.textContent = String(state.stats.serialCount);
    refs.statAdminCount.textContent = String(state.stats.adminCount);
    refs.statSettingsUpdated.textContent = state.settings.updatedAt || '-';

    field(refs.profileForm, 'id').value = state.currentAdmin.id;
    field(refs.profileForm, 'username').value = state.currentAdmin.username;
}

function renderSerials() {
    const canEdit = hasPermission('serial.update');
    const canDelete = hasPermission('serial.delete');

    if (!state.serials.length) {
        refs.serialTableBody.innerHTML = '<tr><td colspan="6">暂无序列号记录。</td></tr>';
        return;
    }

    refs.serialTableBody.innerHTML = state.serials.map((record) => `
        <tr>
            <td>${escapeHtml(record.serial)}</td>
            <td>${escapeHtml(record.status)}</td>
            <td>${escapeHtml(record.batch || '-')}</td>
            <td>${escapeHtml(record.remark || '-')}</td>
            <td>${escapeHtml(record.updatedAt || '-')}</td>
            <td>
                <div class="table-actions">
                    ${canEdit ? `<button type="button" class="mini-button" data-action="edit-serial" data-id="${record.id}">编辑</button>` : ''}
                    ${canDelete ? `<button type="button" class="mini-button danger" data-action="delete-serial" data-id="${record.id}">删除</button>` : ''}
                </div>
            </td>
        </tr>
    `).join('');
}

function renderAdmins() {
    if (!hasPermission('admins.manage')) {
        refs.adminSection.classList.add('hidden');
        refs.adminsNavLink.classList.add('hidden');
        ensureActiveSectionVisible();
        return;
    }

    refs.adminSection.classList.remove('hidden');
    refs.adminsNavLink.classList.remove('hidden');

    if (!state.admins.length) {
        refs.adminTableBody.innerHTML = '<tr><td colspan="6">暂无管理员记录。</td></tr>';
        return;
    }

    refs.adminTableBody.innerHTML = state.admins.map((admin) => `
        <tr>
            <td>${escapeHtml(admin.username)}</td>
            <td>${escapeHtml(admin.role)}</td>
            <td>${escapeHtml(admin.status)}</td>
            <td>${escapeHtml((admin.permissions || []).join(', ') || '-')}</td>
            <td>${escapeHtml(admin.updatedAt || '-')}</td>
            <td>
                <div class="table-actions">
                    <button type="button" class="mini-button" data-action="edit-admin" data-id="${admin.id}">编辑</button>
                    ${admin.role !== 'super_admin' ? `<button type="button" class="mini-button danger" data-action="delete-admin" data-id="${admin.id}">删除</button>` : ''}
                </div>
            </td>
        </tr>
    `).join('');
    ensureActiveSectionVisible();
}

function renderLogs() {
    if (!hasPermission('admins.manage')) {
        refs.logsSection.classList.add('hidden');
        refs.logsNavLink.classList.add('hidden');
        ensureActiveSectionVisible();
        return;
    }

    refs.logsSection.classList.remove('hidden');
    refs.logsNavLink.classList.remove('hidden');

    if (!state.logs.length) {
        refs.logTableBody.innerHTML = '<tr><td colspan="6">暂无操作日志。</td></tr>';
        return;
    }

    refs.logTableBody.innerHTML = state.logs.map((entry) => `
        <tr>
            <td>${escapeHtml(entry.createdAt || '-')}</td>
            <td>${escapeHtml(entry.operator?.username || '-')}</td>
            <td>${escapeHtml(entry.action || '-')}</td>
            <td>${escapeHtml(entry.targetType || '-')} / ${escapeHtml(entry.targetLabel || '-')}</td>
            <td>${escapeHtml(entry.ip || '-')}</td>
            <td>${escapeHtml(entry.summary || '-')}</td>
        </tr>
    `).join('');
    ensureActiveSectionVisible();
}

function renderSettings() {
    refs.announcementInput.value = state.settings.announcement || '';
    refs.backgroundPath.textContent = `当前路径：${state.settings.backgroundImage || '未设置'}`;

    if (state.settings.backgroundImage) {
        refs.backgroundPreview.style.backgroundImage = `linear-gradient(rgba(4, 14, 28, 0.35), rgba(4, 14, 28, 0.58)), url("./${state.settings.backgroundImage}")`;
        refs.backgroundPreview.textContent = '当前查询页背景图预览';
    } else {
        refs.backgroundPreview.style.backgroundImage = '';
        refs.backgroundPreview.textContent = '当前未设置自定义背景图';
    }
}

function applyPermissionVisibility() {
    const serialFormCard = refs.serialForm.closest('.form-card');
    const settingsSection = document.getElementById('settings');

    if (!hasPermission('serial.create') && !hasPermission('serial.update')) {
        serialFormCard.classList.add('hidden');
    }

    if (!hasPermission('settings.update')) {
        settingsSection.classList.add('hidden');
    }

    ensureActiveSectionVisible();
}

async function loadCurrentAdmin() {
    const payload = await requestJson('./api/auth/me.php');
    state.currentAdmin = payload.data.admin;
    state.csrfToken = String(payload.data.csrfToken || '');
    state.stats.serialCount = payload.data.stats.serialCount;
    state.stats.adminCount = payload.data.stats.adminCount;
}

async function loadSettings() {
    const payload = await requestJson('./api/settings/get.php');
    state.settings = payload.data;
}

async function loadSerials(filters = {}) {
    if (!hasPermission('serial.view')) {
        state.serials = [];
        return;
    }

    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value) {
            params.set(key, value);
        }
    });

    const url = `./api/serials/list.php${params.toString() ? `?${params.toString()}` : ''}`;
    const payload = await requestJson(url);
    state.serials = payload.data.records || [];
}

async function loadAdmins() {
    if (!hasPermission('admins.manage')) {
        state.admins = [];
        return;
    }

    const payload = await requestJson('./api/admins/list.php');
    state.admins = payload.data.records || [];
}

async function loadLogs() {
    if (!hasPermission('admins.manage')) {
        state.logs = [];
        return;
    }

    const payload = await requestJson('./api/logs/list.php');
    state.logs = payload.data.records || [];
}

function bindDataActions() {
    document.addEventListener('click', async (event) => {
        const trigger = event.target.closest('[data-action]');
        if (!trigger) {
            return;
        }

        const action = trigger.dataset.action;
        const id = trigger.dataset.id;

        if (action === 'edit-serial') {
            const record = state.serials.find((item) => item.id === id);
            if (!record) {
                return;
            }

            field(refs.serialForm, 'id').value = record.id;
            field(refs.serialForm, 'serial').value = record.serial;
            field(refs.serialForm, 'status').value = record.status;
            field(refs.serialForm, 'batch').value = record.batch || '';
            field(refs.serialForm, 'remark').value = record.remark || '';
            field(refs.serialForm, 'extraInfo').value = record.extraInfo || '';
            refs.serialFormTitle.textContent = '编辑序列号';
            refs.serialForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }

        if (action === 'delete-serial') {
            if (!window.confirm('确定删除这个序列号吗？')) {
                return;
            }

            try {
                await requestJson('./api/serials/delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id }),
                });
                setGlobalMessage('序列号已删除。');
                await loadSerials(getSerialFilters());
                await loadLogs();
                renderSerials();
                renderLogs();
                renderOverview();
            } catch (error) {
                setGlobalMessage(error.message, true);
            }
            return;
        }

        if (action === 'edit-admin') {
            const admin = state.admins.find((item) => item.id === id);
            if (!admin) {
                return;
            }

            field(refs.adminForm, 'id').value = admin.id;
            field(refs.adminForm, 'username').value = admin.username;
            field(refs.adminForm, 'password').value = '';
            field(refs.adminForm, 'role').value = admin.role;
            field(refs.adminForm, 'status').value = admin.status;
            fillPermissionCheckboxes(admin.permissions || []);
            refs.adminFormTitle.textContent = '编辑管理员';
            refs.adminForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }

        if (action === 'delete-admin') {
            const admin = state.admins.find((item) => item.id === id);
            if (!admin) {
                return;
            }

            if (!window.confirm(`确定删除管理员账号“${admin.username}”吗？`)) {
                return;
            }

            try {
                await requestJson('./api/admins/delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id }),
                });
                resetAdminForm();
                await loadCurrentAdmin();
                await loadAdmins();
                await loadLogs();
                renderAdmins();
                renderLogs();
                renderOverview();
                setGlobalMessage('管理员账号已删除。');
            } catch (error) {
                setGlobalMessage(error.message, true);
            }
        }
    });
}

function getSerialFilters() {
    const formData = new FormData(refs.serialSearchForm);
    return {
        keyword: String(formData.get('keyword') || '').trim(),
        status: String(formData.get('status') || '').trim(),
        batch: String(formData.get('batch') || '').trim(),
    };
}

async function initializeDashboard() {
    try {
        await loadCurrentAdmin();
        await loadSettings();
        await loadSerials();
        await loadAdmins();
        await loadLogs();
        renderOverview();
        renderSettings();
        renderSerials();
        renderAdmins();
        renderLogs();
        applyPermissionVisibility();
        resetSerialForm();
        resetAdminForm();
        setActiveSection(state.activeSection, { updateHash: false });
        setGlobalMessage('后台数据已加载完成。');
    } catch (error) {
        setGlobalMessage(error.message || '后台初始化失败，请重新登录。', true);
        setTimeout(() => {
            window.location.href = './admin-login.html';
        }, 1200);
    }
}

refs.serialSearchForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        await loadSerials(getSerialFilters());
        renderSerials();
        renderOverview();
        setGlobalMessage('序列号搜索完成。');
    } catch (error) {
        setGlobalMessage(error.message, true);
    }
});

refs.serialResetButton.addEventListener('click', async () => {
    try {
        refs.serialSearchForm.reset();
        await loadSerials();
        renderSerials();
        setGlobalMessage('已重置搜索条件。');
    } catch (error) {
        setGlobalMessage(error.message, true);
    }
});

refs.serialCancelButton.addEventListener('click', resetSerialForm);

refs.serialForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(refs.serialForm);
    const payload = Object.fromEntries(formData.entries());
    const isEditing = Boolean(payload.id);
    const endpoint = isEditing ? './api/serials/update.php' : './api/serials/create.php';

    try {
        await requestJson(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        resetSerialForm();
        await loadCurrentAdmin();
        await loadSerials(getSerialFilters());
        await loadLogs();
        renderSerials();
        renderLogs();
        renderOverview();
        setGlobalMessage(isEditing ? '序列号已更新。' : '序列号已创建。');
    } catch (error) {
        setGlobalMessage(error.message, true);
    }
});

refs.settingsForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        const payload = await requestJson('./api/settings/update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ announcement: refs.announcementInput.value.trim() }),
        });
        state.settings = payload.data;
        renderSettings();
        await loadLogs();
        renderLogs();
        renderOverview();
        setGlobalMessage('公告已更新。');
    } catch (error) {
        setGlobalMessage(error.message, true);
    }
});

refs.backgroundUploadForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(refs.backgroundUploadForm);
    const backgroundFile = formData.get('background');

    if (!(backgroundFile instanceof File) || backgroundFile.size === 0) {
        setGlobalMessage('请选择要上传的背景图片。', true);
        return;
    }

    try {
        const payload = await requestJson('./api/upload/background.php', {
            method: 'POST',
            body: formData,
        });

        state.settings = payload.data.settings;
        renderSettings();
        await loadLogs();
        renderLogs();
        renderOverview();
        refs.backgroundUploadForm.reset();
        setGlobalMessage('背景图已更新。');
    } catch (error) {
        setGlobalMessage(error.message, true);
    }
});

field(refs.adminForm, 'role').addEventListener('change', (event) => {
    applyRoleDefaults(event.target.value);
});

refs.adminCancelButton.addEventListener('click', resetAdminForm);

refs.adminForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const formData = new FormData(refs.adminForm);
    const payload = {
        id: String(formData.get('id') || ''),
        username: String(formData.get('username') || '').trim(),
        password: String(formData.get('password') || ''),
        role: String(formData.get('role') || 'viewer_admin'),
        status: String(formData.get('status') || 'active'),
        permissions: collectPermissions(),
    };

    const isEditing = Boolean(payload.id);
    const endpoint = isEditing ? './api/admins/update.php' : './api/admins/create.php';

    try {
        await requestJson(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        resetAdminForm();
        await loadCurrentAdmin();
        await loadAdmins();
        await loadLogs();
        renderAdmins();
        renderLogs();
        renderOverview();
        setGlobalMessage(isEditing ? '管理员已更新。' : '管理员已创建。');
    } catch (error) {
        setGlobalMessage(error.message, true);
    }
});

refs.profileForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(refs.profileForm);
    const payload = {
        id: String(formData.get('id') || ''),
        username: String(formData.get('username') || '').trim(),
        password: String(formData.get('password') || ''),
    };

    try {
        const response = await requestJson('./api/admins/update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        state.currentAdmin = response.data.updatedAdmin;
        field(refs.profileForm, 'password').value = '';
        await loadLogs();
        renderLogs();
        renderOverview();
        setGlobalMessage('个人资料已更新。');
    } catch (error) {
        setGlobalMessage(error.message, true);
    }
});

refs.logoutButton.addEventListener('click', async () => {
    try {
        await requestJson('./api/auth/logout.php', { method: 'POST' });
    } finally {
        window.location.href = './admin-login.html';
    }
});

bindDataActions();
bindSidebarNavigation();
initializeDashboard();
