<?php

declare(strict_types=1);

require_once __DIR__ . '/finance_helpers.php';
require_once __DIR__ . '/admin_access.php';
require_once __DIR__ . '/finance_dues_migration_common.php';

requireAccess('finance');
require_admin();
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Asia/Manila');

$conn = db_conn();
$adminId = admin_id();
$admin = fdm_assert_treasurer($conn, $adminId);
fdm_assert_csrf((string)($_POST['csrf'] ?? ''));

if (!fdm_table_exists($conn, 'finance_dues_import_queue')) {
    fdm_json(['success'=>false,'message'=>'Historical dues migration is not installed yet.'], 409);
}

$queueId = (int)($_POST['id'] ?? 0);
$phase = trim((string)($admin['phase'] ?? ''));
if ($queueId <= 0 || !in_array($phase, ['Phase 1','Phase 2','Phase 3'], true)) {
    fdm_json(['success'=>false,'message'=>'Invalid migration row.'], 422);
}

$stmt = $conn->prepare('SELECT * FROM finance_dues_import_queue WHERE id=? AND phase=? LIMIT 1');
$stmt->bind_param('is', $queueId, $phase);
$stmt->execute();
$beforeRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conn->begin_transaction();
try {
    $result = fdm_finalize_queue_row($conn, $queueId, $adminId, $phase);
    if (!($result['finalized'] ?? false)) {
        $conn->commit();
        fdm_json(['success'=>false,'message'=>(string)($result['message'] ?? 'The row could not be finalized.'),'status'=>$result['status'] ?? null], 409);
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Historical dues single finalization failed: ' . $e->getMessage());
    fdm_json(['success'=>false,'message'=>'The historical payment could not be finalized.'], 500);
}

$stmt = $conn->prepare('SELECT * FROM finance_dues_import_queue WHERE id=? AND phase=? LIMIT 1');
$stmt->bind_param('is', $queueId, $phase);
$stmt->execute();
$afterRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

fdm_log(
    $conn,
    $adminId,
    $phase,
    'Historical dues payment finalized',
    sprintf('Migration row #%d finalized as finance payment #%d for homeowner #%d, %04d-%02d, amount %.2f.',
        $queueId,
        (int)$result['payment_id'],
        (int)$result['homeowner_id'],
        (int)$result['year'],
        (int)$result['month'],
        (float)$result['amount']
    ),
    'historical_dues_queue',
    $queueId,
    [
        'queue'=>$beforeRow,
        'payment_before'=>$result['payment_before'] ?? null
    ],
    [
        'queue'=>$afterRow,
        'payment_after'=>$result['payment_after'] ?? null
    ],
    (string)($beforeRow['batch_id'] ?? '')
);

fdm_json(['success'=>true,'message'=>'Historical dues payment finalized successfully.','payment_id'=>(int)$result['payment_id']]);
