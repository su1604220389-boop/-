<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('POST');
consume_rate_limit('auth_login', 10, 300, '登录失败次数过多，请 5 分钟后再试。');

$input = get_json_input();
$username = trim((string) ($input['username'] ?? ''));
$password = (string) ($input['password'] ?? '');
$dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

if ($username === '' || $password === '') {
    respond(false, '请输入管理员账号和密码。', null, 422);
}

$admin = find_admin_by_username($username);
$passwordHash = (string) ($admin['passwordHash'] ?? $dummyHash);
$passwordMatched = password_verify($password, $passwordHash);

if ($admin === null || !$passwordMatched) {
    respond(false, '账号或密码错误。', null, 401);
}

if (($admin['status'] ?? 'disabled') !== 'active') {
    respond(false, '该管理员账号已被禁用。', null, 403);
}

session_regenerate_id(true);
$_SESSION['admin_id'] = $admin['id'];
$_SESSION['last_activity'] = time();
csrf_token();
clear_rate_limit('auth_login');

write_audit_log($admin, 'auth.login', 'session', (string) ($admin['id'] ?? ''), (string) ($admin['username'] ?? ''), '管理员登录成功。');

respond(true, '登录成功。', sanitize_admin($admin));
