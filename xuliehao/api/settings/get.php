<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('GET');

$settings = load_settings_store();

respond(true, '站点配置获取成功。', public_settings($settings));
