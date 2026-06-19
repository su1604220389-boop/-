<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('POST');

$admin = require_permission('serial.create');
require_csrf_token();
$input = get_json_input();

$serial = strtoupper(trim((string) ($input['serial'] ?? '')));
$status = trim((string) ($input['status'] ?? 'valid'));
$batch = trim((string) ($input['batch'] ?? ''));
$remark = trim((string) ($input['remark'] ?? ''));
$extraInfo = trim((string) ($input['extraInfo'] ?? ''));

if ($serial === '') {
    respond(false, '序列号不能为空。', null, 422);
}

if (!in_array($status, ['valid', 'used', 'disabled'], true)) {
    respond(false, '序列号状态不合法。', null, 422);
}

ensure_max_length($batch, MAX_SERIAL_BATCH_LENGTH, '批次内容过长。');
ensure_max_length($remark, MAX_SERIAL_REMARK_LENGTH, '备注内容过长。');
ensure_max_length($extraInfo, MAX_SERIAL_EXTRA_INFO_LENGTH, '额外说明内容过长。');

$timestamp = now_iso();
$createdRecord = null;
$store = update_serial_store(function (array $store) use ($serial, $status, $batch, $remark, $extraInfo, $timestamp, $admin, &$createdRecord): array {
    $records = is_array($store['serials'] ?? null) ? $store['serials'] : [];

    foreach ($records as $record) {
        if (strcasecmp((string) ($record['serial'] ?? ''), $serial) === 0) {
            respond(false, '该序列号已经存在。', null, 409);
        }
    }

    $createdRecord = [
        'id' => generate_id('serial'),
        'serial' => $serial,
        'status' => $status,
        'batch' => $batch,
        'remark' => $remark,
        'extraInfo' => $extraInfo,
        'createdAt' => $timestamp,
        'updatedAt' => $timestamp,
        'updatedBy' => $admin['username'],
    ];
    $records[] = $createdRecord;

    $store['serials'] = $records;
    return $store;
});

if (is_array($createdRecord)) {
    write_audit_log(
        $admin,
        'serial.create',
        'serial',
        (string) ($createdRecord['id'] ?? ''),
        (string) ($createdRecord['serial'] ?? $serial),
        '创建了序列号记录。',
        [
            'serial' => (string) ($createdRecord['serial'] ?? ''),
            'status' => (string) ($createdRecord['status'] ?? ''),
            'batch' => (string) ($createdRecord['batch'] ?? ''),
        ]
    );
}

respond(true, '序列号已创建。', [
    'records' => $store['serials'],
]);
