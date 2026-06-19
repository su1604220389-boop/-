<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

ensure_request_method('POST');
consume_rate_limit('public_query', 30, 60, '查询过于频繁，请稍后再试。');

$input = get_json_input();
$serial = trim((string) ($input['serial'] ?? ''));

if ($serial === '') {
    respond(false, '请输入要查询的序列号。', null, 422);
}

$store = load_serial_store();
$matchedRecord = null;

foreach ($store['serials'] as $record) {
    if (strcasecmp((string) ($record['serial'] ?? ''), $serial) === 0) {
        $matchedRecord = $record;
        break;
    }
}

$settings = load_settings_store();

if ($matchedRecord === null) {
    respond(true, '未找到对应的序列号记录。', [
        'found' => false,
        'record' => null,
        'settings' => public_settings($settings),
    ]);
}

respond(true, '查询成功。', [
    'found' => true,
    'record' => public_serial_record($matchedRecord),
    'settings' => public_settings($settings),
]);
