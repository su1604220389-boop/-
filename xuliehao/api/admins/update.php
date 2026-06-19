<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('POST');

$currentAdmin = require_login();
require_csrf_token();
$input = get_json_input();

$id = trim((string) ($input['id'] ?? ''));
if ($id === '') {
    respond(false, '缺少管理员 ID。', null, 422);
}

$updatedAdmin = null;
$isSelfUpdate = false;
$store = update_admin_store(function (array $store) use ($id, $input, $currentAdmin, &$updatedAdmin, &$isSelfUpdate): array {
    $admins = is_array($store['admins'] ?? null) ? $store['admins'] : [];
    $targetIndex = null;

    foreach ($admins as $index => $admin) {
        if (($admin['id'] ?? '') === $id) {
            $targetIndex = $index;
            break;
        }
    }

    if ($targetIndex === null) {
        respond(false, '未找到目标管理员。', null, 404);
    }

    $targetAdmin = $admins[$targetIndex];
    $isSelfUpdate = ($currentAdmin['id'] ?? '') === ($targetAdmin['id'] ?? '');
    $isCurrentSuper = ($currentAdmin['role'] ?? '') === ROLE_SUPER;

    if (!$isCurrentSuper && !$isSelfUpdate) {
        respond(false, '你只能修改自己的账号资料。', null, 403);
    }

    if (($targetAdmin['role'] ?? '') === ROLE_SUPER && !$isCurrentSuper) {
        respond(false, '只有超级管理员可以修改超级管理员。', null, 403);
    }

    $newUsername = trim((string) ($input['username'] ?? $targetAdmin['username']));
    $newPassword = (string) ($input['password'] ?? '');
    $newRole = (string) ($targetAdmin['role'] ?? ROLE_VIEWER);
    $newPermissions = $targetAdmin['permissions'] ?? role_permissions($newRole);
    $newStatus = (string) ($targetAdmin['status'] ?? 'active');

    if ($newUsername === '') {
        respond(false, '管理员账号不能为空。', null, 422);
    }

    foreach ($admins as $admin) {
        if (($admin['id'] ?? '') !== $id && strcasecmp((string) ($admin['username'] ?? ''), $newUsername) === 0) {
            respond(false, '管理员账号已存在。', null, 409);
        }
    }

    if ($isCurrentSuper) {
        if (array_key_exists('role', $input)) {
            $newRole = normalize_role(trim((string) $input['role']));
        }

        if (array_key_exists('permissions', $input) && is_array($input['permissions'])) {
            $newPermissions = array_values(array_unique(array_filter(array_map('strval', $input['permissions']))));
        } else {
            $newPermissions = role_permissions($newRole);
        }

        if (array_key_exists('status', $input)) {
            $newStatus = trim((string) $input['status']);
            if (!in_array($newStatus, ['active', 'disabled'], true)) {
                respond(false, '管理员状态不合法。', null, 422);
            }
        }

        if (($targetAdmin['role'] ?? '') === ROLE_SUPER && ($newRole !== ROLE_SUPER || $newStatus !== 'active')) {
            if (count_active_super_admins($admins) <= 1) {
                respond(false, '系统中必须至少保留一个激活状态的超级管理员。', null, 422);
            }
        }
    } else {
        $newRole = (string) ($targetAdmin['role'] ?? ROLE_VIEWER);
        $newPermissions = $targetAdmin['permissions'] ?? role_permissions($newRole);
        $newStatus = (string) ($targetAdmin['status'] ?? 'active');
    }

    $admins[$targetIndex]['username'] = $newUsername;
    $admins[$targetIndex]['role'] = $newRole;
    $admins[$targetIndex]['permissions'] = $newPermissions === [] ? role_permissions($newRole) : $newPermissions;
    $admins[$targetIndex]['status'] = $newStatus;
    $admins[$targetIndex]['updatedAt'] = now_iso();

    if ($newPassword !== '') {
        $admins[$targetIndex]['passwordHash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    $updatedAdmin = $admins[$targetIndex];
    $store['admins'] = $admins;
    return $store;
});

if ($isSelfUpdate) {
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $updatedAdmin['id'];
}

if (is_array($updatedAdmin)) {
    write_audit_log(
        $currentAdmin,
        'admin.update',
        'admin',
        (string) ($updatedAdmin['id'] ?? $id),
        (string) ($updatedAdmin['username'] ?? ''),
        $isSelfUpdate ? '更新了自己的管理员资料。' : '更新了管理员资料。',
        [
            'role' => (string) ($updatedAdmin['role'] ?? ''),
            'status' => (string) ($updatedAdmin['status'] ?? ''),
            'selfUpdate' => $isSelfUpdate,
        ]
    );
}

respond(true, '管理员资料已更新。', [
    'records' => array_map('sanitize_admin', $store['admins']),
    'updatedAdmin' => sanitize_admin($updatedAdmin),
]);
