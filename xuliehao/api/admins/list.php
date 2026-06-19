<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('GET');

$admin = require_permission('admins.manage');
$store = load_admin_store();
$records = array_map('sanitize_admin', $store['admins']);

usort($records, static function (array $a, array $b): int {
    return strcmp((string) ($a['username'] ?? ''), (string) ($b['username'] ?? ''));
});

respond(true, '管理员列表获取成功。', [
    'records' => $records,
    'currentAdmin' => sanitize_admin($admin),
]);
