<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('POST');

$admin = require_permission('serial.delete');
require_csrf_token();
$input = get_json_input();
$id = trim((string) ($input['id'] ?? ''));

if ($id === '') {
    respond(false, '缺少要删除的序列号 ID。', null, 422);
}

$deletedRecord = null;
$store = update_serial_store(function (array $store) use ($id, &$deletedRecord): array {
    $records = is_array($store['serials'] ?? null) ? $store['serials'] : [];
    $before = count($records);
    foreach ($records as $record) {
        if ((string) ($record['id'] ?? '') === $id) {
            $deletedRecord = $record;
            break;
        }
    }
    $records = array_values(array_filter($records, static function (array $record) use ($id): bool {
        return (string) ($record['id'] ?? '') !== $id;
    }));

    if ($before === count($records)) {
        respond(false, '未找到要删除的序列号。', null, 404);
    }

    $store['serials'] = $records;
    return $store;
});

if (is_array($deletedRecord)) {
    write_audit_log(
        $admin,
        'serial.delete',
        'serial',
        (string) ($deletedRecord['id'] ?? $id),
        (string) ($deletedRecord['serial'] ?? ''),
        '删除了序列号记录。',
        [
            'serial' => (string) ($deletedRecord['serial'] ?? ''),
            'status' => (string) ($deletedRecord['status'] ?? ''),
            'batch' => (string) ($deletedRecord['batch'] ?? ''),
        ]
    );
}

respond(true, '序列号已删除。', [
    'records' => $store['serials'],
]);
