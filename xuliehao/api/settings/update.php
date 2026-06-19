<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('POST');

$admin = require_permission('settings.update');
require_csrf_token();
$input = get_json_input();

if (array_key_exists('announcement', $input)) {
    ensure_max_length(trim((string) $input['announcement']), MAX_ANNOUNCEMENT_LENGTH, '公告内容过长。');
}

if (array_key_exists('backgroundImage', $input)) {
    ensure_max_length(trim((string) $input['backgroundImage']), MAX_BACKGROUND_IMAGE_LENGTH, '背景图路径过长。');
}

$settings = update_settings_store(function (array $settings) use ($input, $admin): array {
    if (array_key_exists('announcement', $input)) {
        $settings['announcement'] = trim((string) $input['announcement']);
    }

    if (array_key_exists('backgroundImage', $input)) {
        $settings['backgroundImage'] = trim((string) $input['backgroundImage']);
    }

    $settings['updatedAt'] = now_iso();
    $settings['updatedBy'] = $admin['username'];
    return $settings;
});

write_audit_log(
    $admin,
    'settings.update',
    'settings',
    'site_settings',
    '站点配置',
    '更新了查询页公告。',
    [
        'announcementLength' => function_exists('mb_strlen')
            ? mb_strlen((string) ($settings['announcement'] ?? ''), 'UTF-8')
            : strlen((string) ($settings['announcement'] ?? '')),
    ]
);

respond(true, '站点配置已更新。', $settings);
