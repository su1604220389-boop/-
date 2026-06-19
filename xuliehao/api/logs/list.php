<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('GET');

$admin = require_permission('admins.manage');
$records = load_recent_audit_logs();

respond(true, '操作日志获取成功。', [
    'records' => $records,
    'currentAdmin' => sanitize_admin($admin),
]);
