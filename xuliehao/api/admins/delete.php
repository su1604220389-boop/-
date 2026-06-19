<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('POST');

$currentAdmin = require_permission('admins.manage');
require_csrf_token();
$input = get_json_input();
$id = trim((string) ($input['id'] ?? ''));

if ($id === '') {
    respond(false, '缺少要删除的管理员 ID。', null, 422);
}

$deletedAdmin = null;
$store = update_admin_store(function (array $store) use ($id, $currentAdmin, &$deletedAdmin): array {
    $admins = is_array($store['admins'] ?? null) ? $store['admins'] : [];
    $targetIndex = null;

    foreach ($admins as $index => $admin) {
        if (($admin['id'] ?? '') === $id) {
            $targetIndex = $index;
            break;
        }
    }

    if ($targetIndex === null) {
        respond(false, '未找到要删除的管理员。', null, 404);
    }

    $targetAdmin = $admins[$targetIndex];
    $deletedAdmin = $targetAdmin;

    if (($targetAdmin['id'] ?? '') === ($currentAdmin['id'] ?? '')) {
        respond(false, '不能删除当前正在登录的管理员账号。', null, 422);
    }

    if (($targetAdmin['role'] ?? '') === ROLE_SUPER) {
        respond(false, '超级管理员账号不允许直接删除。', null, 422);
    }

    unset($admins[$targetIndex]);
    $store['admins'] = array_values($admins);
    return $store;
});

if (is_array($deletedAdmin)) {
    write_audit_log(
        $currentAdmin,
        'admin.delete',
        'admin',
        (string) ($deletedAdmin['id'] ?? $id),
        (string) ($deletedAdmin['username'] ?? ''),
        '删除了管理员账号。',
        [
            'role' => (string) ($deletedAdmin['role'] ?? ''),
            'status' => (string) ($deletedAdmin['status'] ?? ''),
        ]
    );
}

respond(true, '管理员账号已删除。', [
    'records' => array_map('sanitize_admin', $store['admins']),
]);
