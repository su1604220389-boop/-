<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('GET');

$admin = require_login();
$serialStore = load_serial_store();
$adminStore = load_admin_store();

respond(true, '获取当前管理员信息成功。', [
    'admin' => sanitize_admin($admin),
    'csrfToken' => csrf_token(),
    'stats' => [
        'serialCount' => count($serialStore['serials'] ?? []),
        'adminCount' => count($adminStore['admins'] ?? []),
    ],
]);
