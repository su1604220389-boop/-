<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

ensure_request_method('GET');

$admin = require_permission('serial.view');
$store = load_serial_store();

$keyword = trim((string) ($_GET['keyword'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$batch = trim((string) ($_GET['batch'] ?? ''));

$records = array_values(array_filter($store['serials'], static function (array $record) use ($keyword, $status, $batch): bool {
    $serial = (string) ($record['serial'] ?? '');
    $remark = (string) ($record['remark'] ?? '');
    $recordStatus = (string) ($record['status'] ?? '');
    $recordBatch = (string) ($record['batch'] ?? '');

    if ($keyword !== '' && stripos($serial . ' ' . $remark, $keyword) === false) {
        return false;
    }

    if ($status !== '' && $recordStatus !== $status) {
        return false;
    }

    if ($batch !== '' && stripos($recordBatch, $batch) === false) {
        return false;
    }

    return true;
}));

usort($records, static function (array $a, array $b): int {
    return strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? ''));
});

respond(true, '序列号列表获取成功。', [
    'records' => $records,
    'filters' => [
        'keyword' => $keyword,
        'status' => $status,
        'batch' => $batch,
    ],
    'currentAdmin' => sanitize_admin($admin),
]);
