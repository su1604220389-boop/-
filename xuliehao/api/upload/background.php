<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('POST');

$admin = require_permission('settings.update');
require_csrf_token();
ensure_directory_writable(UPLOAD_PATH, '背景图片目录初始化失败。');

if (!isset($_FILES['background'])) {
    respond(false, '请上传背景图片文件。', null, 422);
}

$file = $_FILES['background'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    respond(false, upload_error_message((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)), null, 400);
}

$fileSize = (int) ($file['size'] ?? 0);
if ($fileSize <= 0) {
    respond(false, '上传的背景图片为空。', null, 422);
}

if ($fileSize > 5 * 1024 * 1024) {
    respond(false, '背景图片不能超过 5MB。', null, 422);
}

$tmpName = (string) ($file['tmp_name'] ?? '');
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    respond(false, '未识别到有效的上传临时文件。', null, 400);
}

$extension = detect_image_extension($tmpName, (string) ($file['name'] ?? ''));
if ($extension === null) {
    respond(false, '仅支持 JPG、PNG、WEBP、GIF 图片。', null, 422);
}

$fileName = 'bg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
$targetPath = UPLOAD_PATH . '/' . $fileName;

if (!move_uploaded_file($tmpName, $targetPath)) {
    respond(false, '保存背景图片失败，请检查上传目录权限。', null, 500);
}

$relativePath = 'assets/uploads/backgrounds/' . $fileName;
$settings = update_settings_store(function (array $settings) use ($relativePath, $admin): array {
    $settings['backgroundImage'] = $relativePath;
    $settings['updatedAt'] = now_iso();
    $settings['updatedBy'] = $admin['username'];
    return $settings;
});

write_audit_log(
    $admin,
    'settings.background.update',
    'settings',
    'site_background',
    $relativePath,
    '更新了查询页背景图。',
    [
        'backgroundImage' => $relativePath,
    ]
);

respond(true, '背景图片已上传并生效。', [
    'backgroundImage' => $relativePath,
    'settings' => $settings,
]);
