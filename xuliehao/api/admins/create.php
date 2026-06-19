<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('POST');

$currentAdmin = require_permission('admins.manage');
require_csrf_token();
$input = get_json_input();

$username = trim((string) ($input['username'] ?? ''));
$password = (string) ($input['password'] ?? '');
$role = normalize_role(trim((string) ($input['role'] ?? ROLE_VIEWER)));
$status = trim((string) ($input['status'] ?? 'active'));
$permissions = $input['permissions'] ?? role_permissions($role);

if ($username === '' || $password === '') {
    respond(false, '管理员账号和密码不能为空。', null, 422);
}

if (!in_array($status, ['active', 'disabled'], true)) {
    respond(false, '管理员状态不合法。', null, 422);
}

if (!is_array($permissions)) {
    $permissions = role_permissions($role);
}

$permissions = array_values(array_unique(array_filter(array_map('strval', $permissions))));
$timestamp = now_iso();

$createdAdmin = null;
$store = update_admin_store(function (array $store) use ($username, $password, $role, $permissions, $status, $timestamp, &$createdAdmin): array {
    $admins = is_array($store['admins'] ?? null) ? $store['admins'] : [];

    foreach ($admins as $admin) {
        if (strcasecmp((string) ($admin['username'] ?? ''), $username) === 0) {
            respond(false, '管理员账号已存在。', null, 409);
        }
    }

    $createdAdmin = [
        'id' => generate_id('admin'),
        'username' => $username,
        'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'permissions' => $permissions === [] ? role_permissions($role) : $permissions,
        'status' => $status,
        'createdAt' => $timestamp,
        'updatedAt' => $timestamp,
    ];
    $admins[] = $createdAdmin;

    $store['admins'] = $admins;
    return $store;
});

if (is_array($createdAdmin)) {
    write_audit_log(
        $currentAdmin,
        'admin.create',
        'admin',
        (string) ($createdAdmin['id'] ?? ''),
        (string) ($createdAdmin['username'] ?? $username),
        '创建了管理员账号。',
        [
            'role' => (string) ($createdAdmin['role'] ?? ''),
            'status' => (string) ($createdAdmin['status'] ?? ''),
        ]
    );
}

respond(true, '管理员账号已创建。', [
    'records' => array_map('sanitize_admin', $store['admins']),
]);
