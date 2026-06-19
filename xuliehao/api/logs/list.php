<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('GET');

$admin = require_permission('admins.manage');
$store = load_log_store();
$records = array_map('sanitize_audit_log', $store['logs'] ?? []);

usort($records, static function (array $a, array $b): int {
    return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
});

respond(true, '操作日志获取成功。', [
    'records' => $records,
    'currentAdmin' => sanitize_admin($admin),
]);
