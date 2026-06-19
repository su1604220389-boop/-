<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self';");

const MAX_JSON_BODY_BYTES = 2097152;
const DEFAULT_JSON_DEPTH = 64;
const MAX_AUDIT_LOGS = 500;
const SESSION_IDLE_TIMEOUT_SECONDS = 1800;
const MAX_ANNOUNCEMENT_LENGTH = 1000;
const MAX_SERIAL_REMARK_LENGTH = 500;
const MAX_SERIAL_EXTRA_INFO_LENGTH = 500;
const MAX_SERIAL_BATCH_LENGTH = 100;
const RATE_LIMIT_GC_PROBABILITY = 2;
const RATE_LIMIT_GC_STALE_SECONDS = 3600;

function is_https_request(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }

    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto === 'https') {
        return true;
    }

    $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
    if ($forwardedSsl === 'on') {
        return true;
    }

    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_name('serial_query_admin');
    session_start();
}

const ROOT_PATH = __DIR__ . '/..';
const DATA_PATH = ROOT_PATH . '/data';
const RATE_LIMIT_PATH = DATA_PATH . '/rate_limits';
const UPLOAD_PATH = ROOT_PATH . '/assets/uploads/backgrounds';

const ROLE_VIEWER = 'viewer_admin';
const ROLE_CONTENT = 'content_admin';
const ROLE_SUPER = 'super_admin';

function respond(bool $success, string $message, $data = null, int $statusCode = 200, array $errors = []): void
{
    http_response_code($statusCode);

    $payload = [
        'success' => $success,
        'message' => $message,
    ];

    if ($data !== null) {
        $payload['data'] = $data;
    }

    if ($errors !== []) {
        $payload['errors'] = $errors;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function destroy_active_session(): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }
}

function ensure_csrf_token(): string
{
    $token = $_SESSION['csrf_token'] ?? null;
    if (is_string($token) && $token !== '') {
        return $token;
    }

    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $exception) {
        $token = hash('sha256', uniqid('csrf_', true));
    }

    $_SESSION['csrf_token'] = $token;
    return $token;
}

function csrf_token(): string
{
    return ensure_csrf_token();
}

function extract_csrf_token_from_request(): string
{
    $headerToken = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($headerToken !== '') {
        return $headerToken;
    }

    $postToken = trim((string) ($_POST['_csrf'] ?? ''));
    return $postToken;
}

function require_csrf_token(): void
{
    $sessionToken = ensure_csrf_token();
    $requestToken = extract_csrf_token_from_request();

    if ($requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        respond(false, 'CSRF 校验失败，请刷新后台页面后重试。', null, 403);
    }
}

function ensure_json_body_size_within_limit(int $maxBytes = MAX_JSON_BODY_BYTES): void
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxBytes) {
        respond(false, '请求体过大，已拒绝处理。', null, 413, [
            'maxBytes' => $maxBytes,
        ]);
    }
}

function get_json_input(): array
{
    ensure_json_body_size_within_limit();

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    if (strlen($raw) > MAX_JSON_BODY_BYTES) {
        respond(false, '请求体过大，已拒绝处理。', null, 413, [
            'maxBytes' => MAX_JSON_BODY_BYTES,
        ]);
    }

    $decoded = json_decode($raw, true, DEFAULT_JSON_DEPTH);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        respond(false, '请求体必须是有效的 JSON。', null, 400);
    }

    return $decoded;
}

function ensure_request_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        respond(false, '请求方法不正确。', null, 405);
    }
}

function storage_file(string $name): string
{
    return DATA_PATH . '/' . $name . '.json';
}

function ensure_file_directory(string $file): void
{
    $directory = dirname($file);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        respond(false, '无法创建数据目录。', null, 500);
    }
}

function lock_file_path(string $file): string
{
    return dirname($file) . '/.' . basename($file) . '.lock';
}

function with_file_lock(string $file, int $lockType, callable $callback)
{
    ensure_file_directory($file);

    $lockHandle = fopen(lock_file_path($file), 'c+');
    if ($lockHandle === false) {
        respond(false, '无法创建锁文件。', null, 500);
    }

    try {
        if (!flock($lockHandle, $lockType)) {
            respond(false, '数据文件加锁失败。', null, 500);
        }

        return $callback();
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function decode_json_payload(string $content, array $default): array
{
    if (trim($content) === '') {
        return $default;
    }

    $decoded = json_decode($content, true, DEFAULT_JSON_DEPTH);
    if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
        return $decoded;
    }

    respond(false, '数据文件已损坏，请检查磁盘空间并从备份恢复。', null, 500);
}

function read_json_payload_from_disk(string $file, array $default): array
{
    if (!is_file($file)) {
        return $default;
    }

    $content = file_get_contents($file);
    if ($content === false) {
        respond(false, '无法读取数据文件。', null, 500);
    }

    return decode_json_payload($content, $default);
}

function atomic_write_json_file(string $file, string $encoded): void
{
    ensure_file_directory($file);

    $tmpFile = tempnam(dirname($file), basename($file) . '.');
    if ($tmpFile === false) {
        respond(false, '无法创建临时数据文件。', null, 500);
    }

    $written = file_put_contents($tmpFile, $encoded);
    if ($written === false || $written !== strlen($encoded)) {
        @unlink($tmpFile);
        respond(false, '写入数据文件失败（可能磁盘已满）。', null, 500);
    }

    if (!@rename($tmpFile, $file)) {
        @unlink($tmpFile);
        respond(false, '替换数据文件失败。', null, 500);
    }
}

function read_json_file(string $file, array $default): array
{
    return with_file_lock($file, LOCK_SH, function () use ($file, $default): array {
        return read_json_payload_from_disk($file, $default);
    });
}

function write_json_file(string $file, array $payload): void
{
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded === false) {
        respond(false, 'JSON 编码失败。', null, 500);
    }

    with_file_lock($file, LOCK_EX, function () use ($file, $encoded): void {
        atomic_write_json_file($file, $encoded);
    });
}

function mutate_json_file(string $file, array $default, callable $mutator): array
{
    return with_file_lock($file, LOCK_EX, function () use ($file, $default, $mutator): array {
        $current = read_json_payload_from_disk($file, $default);
        $updated = $mutator($current);
        if (!is_array($updated)) {
            respond(false, '数据处理结果无效。', null, 500);
        }

        $encoded = json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            respond(false, 'JSON 编码失败。', null, 500);
        }

        atomic_write_json_file($file, $encoded);
        return $updated;
    });
}

function now_iso(): string
{
    return date(DATE_ATOM);
}

function string_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function ensure_max_length(string $value, int $maxLength, string $message): void
{
    if (string_length($value) > $maxLength) {
        respond(false, $message, null, 422, [
            'maxLength' => $maxLength,
        ]);
    }
}

function generate_id(string $prefix): string
{
    try {
        return $prefix . '_' . bin2hex(random_bytes(6));
    } catch (Throwable $exception) {
        return $prefix . '_' . uniqid();
    }
}

function role_permissions(string $role): array
{
    $map = [
        ROLE_VIEWER => [
            'serial.view',
        ],
        ROLE_CONTENT => [
            'serial.view',
            'serial.create',
            'serial.update',
            'serial.delete',
            'settings.update',
        ],
        ROLE_SUPER => [
            'serial.view',
            'serial.create',
            'serial.update',
            'serial.delete',
            'settings.update',
            'admins.manage',
        ],
    ];

    return $map[$role] ?? [];
}

function sanitize_admin(array $admin): array
{
    return [
        'id' => $admin['id'],
        'username' => $admin['username'],
        'role' => $admin['role'],
        'permissions' => $admin['permissions'],
        'status' => $admin['status'],
        'createdAt' => $admin['createdAt'],
        'updatedAt' => $admin['updatedAt'],
    ];
}

function public_settings(array $settings): array
{
    return [
        'announcement' => (string) ($settings['announcement'] ?? ''),
        'backgroundImage' => (string) ($settings['backgroundImage'] ?? ''),
        'updatedAt' => (string) ($settings['updatedAt'] ?? ''),
    ];
}

function public_serial_record(array $record): array
{
    return [
        'serial' => (string) ($record['serial'] ?? ''),
        'status' => (string) ($record['status'] ?? ''),
        'batch' => (string) ($record['batch'] ?? ''),
        'remark' => (string) ($record['remark'] ?? ''),
        'extraInfo' => (string) ($record['extraInfo'] ?? ''),
        'updatedAt' => (string) ($record['updatedAt'] ?? ''),
    ];
}

function sanitize_audit_log(array $entry): array
{
    return [
        'id' => (string) ($entry['id'] ?? ''),
        'createdAt' => (string) ($entry['createdAt'] ?? ''),
        'action' => (string) ($entry['action'] ?? ''),
        'targetType' => (string) ($entry['targetType'] ?? ''),
        'targetId' => (string) ($entry['targetId'] ?? ''),
        'targetLabel' => (string) ($entry['targetLabel'] ?? ''),
        'summary' => (string) ($entry['summary'] ?? ''),
        'operator' => [
            'id' => (string) ($entry['operator']['id'] ?? ''),
            'username' => (string) ($entry['operator']['username'] ?? ''),
            'role' => (string) ($entry['operator']['role'] ?? ''),
        ],
        'ip' => (string) ($entry['ip'] ?? ''),
        'userAgent' => (string) ($entry['userAgent'] ?? ''),
        'details' => is_array($entry['details'] ?? null) ? $entry['details'] : [],
    ];
}

function load_admin_store(): array
{
    return read_json_file(storage_file('admins'), ['admins' => []]);
}

function save_admin_store(array $store): void
{
    write_json_file(storage_file('admins'), $store);
}

function update_admin_store(callable $mutator): array
{
    return mutate_json_file(storage_file('admins'), ['admins' => []], $mutator);
}

function load_serial_store(): array
{
    return read_json_file(storage_file('serials'), ['serials' => []]);
}

function save_serial_store(array $store): void
{
    write_json_file(storage_file('serials'), $store);
}

function update_serial_store(callable $mutator): array
{
    return mutate_json_file(storage_file('serials'), ['serials' => []], $mutator);
}

function load_settings_store(): array
{
    return read_json_file(storage_file('settings'), [
        'announcement' => '欢迎使用序列号查询系统',
        'backgroundImage' => '',
        'updatedAt' => now_iso(),
        'updatedBy' => 'system',
    ]);
}

function save_settings_store(array $store): void
{
    write_json_file(storage_file('settings'), $store);
}

function update_settings_store(callable $mutator): array
{
    return mutate_json_file(storage_file('settings'), [
        'announcement' => '欢迎使用序列号查询系统',
        'backgroundImage' => '',
        'updatedAt' => now_iso(),
        'updatedBy' => 'system',
    ], $mutator);
}

function load_log_store(): array
{
    return read_json_file(storage_file('logs'), ['logs' => []]);
}

function update_log_store(callable $mutator): array
{
    return mutate_json_file(storage_file('logs'), ['logs' => []], $mutator);
}

function get_client_ip(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
        return $remote;
    }

    return 'unknown';
}

function rate_limit_storage_file(string $scope, string $ip): string
{
    return RATE_LIMIT_PATH . '/' . md5($scope . '|' . $ip) . '.json';
}

function maybe_collect_stale_rate_limit_files(): void
{
    try {
        if (random_int(1, 100) > RATE_LIMIT_GC_PROBABILITY) {
            return;
        }
    } catch (Throwable $exception) {
        return;
    }

    if (!is_dir(RATE_LIMIT_PATH)) {
        return;
    }

    $gcLockHandle = fopen(RATE_LIMIT_PATH . '/.gc.lock', 'c+');
    if ($gcLockHandle === false) {
        return;
    }

    try {
        if (!flock($gcLockHandle, LOCK_EX | LOCK_NB)) {
            return;
        }

        $threshold = time() - RATE_LIMIT_GC_STALE_SECONDS;
        $iterator = new DirectoryIterator(RATE_LIMIT_PATH);

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->getExtension() !== 'json') {
                continue;
            }

            if ($fileInfo->getMTime() < $threshold) {
                @unlink($fileInfo->getPathname());
            }
        }
    } catch (Throwable $exception) {
        return;
    } finally {
        flock($gcLockHandle, LOCK_UN);
        fclose($gcLockHandle);
    }
}

function current_user_agent(): string
{
    return trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function write_audit_log(array $admin, string $action, string $targetType, string $targetId, string $targetLabel, string $summary, array $details = []): void
{
    update_log_store(function (array $store) use ($admin, $action, $targetType, $targetId, $targetLabel, $summary, $details): array {
        $logs = is_array($store['logs'] ?? null) ? $store['logs'] : [];
        $logs[] = [
            'id' => generate_id('log'),
            'createdAt' => now_iso(),
            'action' => $action,
            'targetType' => $targetType,
            'targetId' => $targetId,
            'targetLabel' => $targetLabel,
            'summary' => $summary,
            'operator' => [
                'id' => (string) ($admin['id'] ?? ''),
                'username' => (string) ($admin['username'] ?? ''),
                'role' => (string) ($admin['role'] ?? ''),
            ],
            'ip' => get_client_ip(),
            'userAgent' => current_user_agent(),
            'details' => $details,
        ];

        if (count($logs) > MAX_AUDIT_LOGS) {
            $logs = array_slice($logs, -MAX_AUDIT_LOGS);
        }

        $store['logs'] = $logs;
        return $store;
    });
}

function consume_rate_limit(string $scope, int $maxAttempts, int $windowSeconds, string $message): void
{
    $ip = get_client_ip();
    $file = rate_limit_storage_file($scope, $ip);
    $now = time();
    $retryAfter = 0;
    $blocked = false;

    mutate_json_file($file, [
        'scope' => $scope,
        'ip' => $ip,
        'count' => 0,
        'resetAt' => $now + $windowSeconds,
    ], function (array $entry) use ($scope, $ip, $now, $maxAttempts, $windowSeconds, &$retryAfter, &$blocked): array {
        if ((string) ($entry['scope'] ?? '') !== $scope || (string) ($entry['ip'] ?? '') !== $ip) {
            $entry = [
                'scope' => $scope,
                'ip' => $ip,
                'count' => 0,
                'resetAt' => $now + $windowSeconds,
            ];
        }

        if ((int) ($entry['resetAt'] ?? 0) <= $now) {
            $entry = [
                'scope' => $scope,
                'ip' => $ip,
                'count' => 0,
                'resetAt' => $now + $windowSeconds,
            ];
        }

        $entry['count'] = (int) ($entry['count'] ?? 0) + 1;
        if ($entry['count'] > $maxAttempts) {
            $blocked = true;
            $retryAfter = max(1, (int) $entry['resetAt'] - $now);
        }

        return $entry;
    });

    maybe_collect_stale_rate_limit_files();

    if ($blocked) {
        header('Retry-After: ' . $retryAfter);
        respond(false, $message, null, 429, [
            'retryAfter' => $retryAfter,
        ]);
    }
}

function clear_rate_limit(string $scope): void
{
    $ip = get_client_ip();
    $file = rate_limit_storage_file($scope, $ip);

    mutate_json_file($file, [
        'scope' => $scope,
        'ip' => $ip,
        'count' => 0,
        'resetAt' => 0,
    ], function (array $entry) use ($scope, $ip): array {
        $entry['scope'] = $scope;
        $entry['ip'] = $ip;
        $entry['count'] = 0;
        $entry['resetAt'] = 0;
        return $entry;
    });
}

function ensure_storage_initialized(): void
{
    if (!is_dir(DATA_PATH) && !mkdir(DATA_PATH, 0777, true) && !is_dir(DATA_PATH)) {
        respond(false, '无法初始化数据目录。', null, 500);
    }

    if (!is_dir(RATE_LIMIT_PATH) && !mkdir(RATE_LIMIT_PATH, 0777, true) && !is_dir(RATE_LIMIT_PATH)) {
        respond(false, '无法初始化限流目录。', null, 500);
    }

    if (!is_dir(UPLOAD_PATH) && !mkdir(UPLOAD_PATH, 0777, true) && !is_dir(UPLOAD_PATH)) {
        respond(false, '无法初始化上传目录。', null, 500);
    }

    $admins = load_admin_store();
    if (!isset($admins['admins']) || !is_array($admins['admins']) || count($admins['admins']) === 0) {
        $timestamp = now_iso();
        $initialPassword = bin2hex(random_bytes(6));
        $admins = [
            'admins' => [
                [
                    'id' => 'admin_root',
                    'username' => 'superadmin',
                    'passwordHash' => password_hash($initialPassword, PASSWORD_DEFAULT),
                    'role' => ROLE_SUPER,
                    'permissions' => role_permissions(ROLE_SUPER),
                    'status' => 'active',
                    'createdAt' => $timestamp,
                    'updatedAt' => $timestamp,
                ],
            ],
        ];
        save_admin_store($admins);
        error_log('[serial-query] Initial superadmin password: ' . $initialPassword);
    }

    $serials = load_serial_store();
    if (!isset($serials['serials']) || !is_array($serials['serials']) || count($serials['serials']) === 0) {
        $timestamp = now_iso();
        $serials = [
            'serials' => [
                [
                    'id' => 'serial_demo_001',
                    'serial' => 'SN-2026-000001',
                    'status' => 'valid',
                    'batch' => '2026-A',
                    'remark' => '系统初始化示例序列号',
                    'extraInfo' => '演示数据，可在后台修改或删除',
                    'createdAt' => $timestamp,
                    'updatedAt' => $timestamp,
                    'updatedBy' => 'superadmin',
                ],
            ],
        ];
        save_serial_store($serials);
    }

    load_settings_store();
}

function find_admin_by_username(string $username): ?array
{
    $store = load_admin_store();
    foreach ($store['admins'] as $admin) {
        if (strcasecmp($admin['username'], $username) === 0) {
            return $admin;
        }
    }

    return null;
}

function find_admin_by_id(string $id): ?array
{
    $store = load_admin_store();
    foreach ($store['admins'] as $admin) {
        if ($admin['id'] === $id) {
            return $admin;
        }
    }

    return null;
}

function current_admin(): ?array
{
    $adminId = $_SESSION['admin_id'] ?? null;
    if (!is_string($adminId) || $adminId === '') {
        return null;
    }

    $admin = find_admin_by_id($adminId);
    if ($admin === null || ($admin['status'] ?? 'disabled') !== 'active') {
        return null;
    }

    return $admin;
}

function require_login(): array
{
    $admin = current_admin();
    if ($admin === null) {
        respond(false, '请先登录管理员账号。', null, 401);
    }

    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity > SESSION_IDLE_TIMEOUT_SECONDS)) {
        destroy_active_session();
        respond(false, '登录已过期，请重新登录。', null, 401);
    }

    $_SESSION['last_activity'] = time();

    return $admin;
}

function admin_has_permission(array $admin, string $permission): bool
{
    if (($admin['role'] ?? '') === ROLE_SUPER) {
        return true;
    }

    $permissions = $admin['permissions'] ?? [];
    return is_array($permissions) && in_array($permission, $permissions, true);
}

function require_permission(string $permission): array
{
    $admin = require_login();
    if (!admin_has_permission($admin, $permission)) {
        respond(false, '当前账号没有操作权限。', null, 403);
    }

    return $admin;
}

function normalize_role(string $role): string
{
    $availableRoles = [ROLE_VIEWER, ROLE_CONTENT, ROLE_SUPER];
    if (!in_array($role, $availableRoles, true)) {
        respond(false, '管理员角色不合法。', null, 422);
    }

    return $role;
}

function count_active_super_admins(array $admins): int
{
    $count = 0;
    foreach ($admins as $admin) {
        if (($admin['role'] ?? '') === ROLE_SUPER && ($admin['status'] ?? '') === 'active') {
            $count++;
        }
    }

    return $count;
}

function base_url_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function upload_error_message(int $errorCode): string
{
    $map = [
        UPLOAD_ERR_INI_SIZE => '上传文件超过了服务器允许的大小限制，请调大 PHP 的 upload_max_filesize 和 post_max_size。',
        UPLOAD_ERR_FORM_SIZE => '上传文件超过了表单允许的大小限制。',
        UPLOAD_ERR_PARTIAL => '文件只上传了一部分，请重试。',
        UPLOAD_ERR_NO_FILE => '未检测到上传文件。',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录，无法处理上传。',
        UPLOAD_ERR_CANT_WRITE => '服务器没有写入上传文件的权限。',
        UPLOAD_ERR_EXTENSION => '上传被服务器扩展中断。',
    ];

    return $map[$errorCode] ?? '背景图片上传失败。';
}

function ensure_directory_writable(string $directory, string $errorMessage): void
{
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        respond(false, $errorMessage, null, 500);
    }

    if (!is_writable($directory)) {
        respond(false, $errorMessage . ' 目录没有写入权限。', null, 500);
    }
}

function detect_image_extension(string $tmpName, string $originalName = ''): ?string
{
    $mimeToExtension = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mimeType = finfo_file($finfo, $tmpName);
            finfo_close($finfo);
            if (is_string($mimeType) && isset($mimeToExtension[$mimeType])) {
                return $mimeToExtension[$mimeType];
            }
        }
    }

    if (function_exists('exif_imagetype')) {
        $imageType = @exif_imagetype($tmpName);
        $typeMap = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];

        if ($imageType !== false && isset($typeMap[$imageType])) {
            return $typeMap[$imageType];
        }
    }

    if (function_exists('getimagesize')) {
        $imageInfo = @getimagesize($tmpName);
        if (is_array($imageInfo) && isset($imageInfo['mime']) && is_string($imageInfo['mime']) && isset($mimeToExtension[$imageInfo['mime']])) {
            return $mimeToExtension[$imageInfo['mime']];
        }
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (in_array($extension, $allowedExtensions, true)) {
        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    return null;
}

ensure_storage_initialized();
