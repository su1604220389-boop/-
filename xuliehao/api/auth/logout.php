<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('POST');
$admin = require_login();
require_csrf_token();

write_audit_log($admin, 'auth.logout', 'session', (string) ($admin['id'] ?? ''), (string) ($admin['username'] ?? ''), '管理员已退出登录。');

destroy_active_session();

respond(true, '已安全退出。');
