<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('POST');

$admin = require_permission('serial.update');
require_csrf_token();
$input = get_json_input();

$id = trim((string) ($input['id'] ?? ''));
$serial = strtoupper(trim((string) ($input['serial'] ?? '')));
$status = trim((string) ($input['status'] ?? 'valid'));
$batch = trim((string) ($input['batch'] ?? ''));
$remark = trim((string) ($input['remark'] ?? ''));
$extraInfo = trim((string) ($input['extraInfo'] ?? ''));

if ($id === '' || $serial === '') {
    respond(false, '缺少必要的序列号参数。', null, 422);
}

if (!in_array($status, ['valid', 'used', 'disabled'], true)) {
    respond(false, '序列号状态不合法。', null, 422);
}

ensure_max_length($batch, MAX_SERIAL_BATCH_LENGTH, '批次内容过长。');
ensure_max_length($remark, MAX_SERIAL_REMARK_LENGTH, '备注内容过长。');
ensure_max_length($extraInfo, MAX_SERIAL_EXTRA_INFO_LENGTH, '额外说明内容过长。');

$store = update_serial_store(function (array $store) use ($id, $serial, $status, $batch, $remark, $extraInfo, $admin): array {
    $records = is_array($store['serials'] ?? null) ? $store['serials'] : [];
    $matched = false;

    foreach ($records as $index => $record) {
        if (($record['id'] ?? '') !== $id && strcasecmp((string) ($record['serial'] ?? ''), $serial) === 0) {
            respond(false, '已存在相同的序列号。', null, 409);
        }

        if (($record['id'] ?? '') !== $id) {
            continue;
        }

        $records[$index]['serial'] = $serial;
        $records[$index]['status'] = $status;
        $records[$index]['batch'] = $batch;
        $records[$index]['remark'] = $remark;
        $records[$index]['extraInfo'] = $extraInfo;
        $records[$index]['updatedAt'] = now_iso();
        $records[$index]['updatedBy'] = $admin['username'];
        $matched = true;
        break;
    }

    if (!$matched) {
        respond(false, '未找到要更新的序列号。', null, 404);
    }

    $store['serials'] = $records;
    return $store;
});

$updatedRecord = null;
foreach ($store['serials'] as $record) {
    if ((string) ($record['id'] ?? '') === $id) {
        $updatedRecord = $record;
        break;
    }
}

if (is_array($updatedRecord)) {
    write_audit_log(
        $admin,
        'serial.update',
        'serial',
        (string) ($updatedRecord['id'] ?? $id),
        (string) ($updatedRecord['serial'] ?? $serial),
        '更新了序列号记录。',
        [
            'serial' => (string) ($updatedRecord['serial'] ?? ''),
            'status' => (string) ($updatedRecord['status'] ?? ''),
            'batch' => (string) ($updatedRecord['batch'] ?? ''),
        ]
    );
}

respond(true, '序列号已更新。', [
    'records' => $store['serials'],
]);
