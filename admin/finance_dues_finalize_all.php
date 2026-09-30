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

$phase = trim((string)($admin['phase'] ?? ''));
$stmt = $conn->prepare("SELECT id FROM finance_dues_import_queue WHERE phase=? AND status='ready' AND COALESCE(is_archived,0)=0 ORDER BY id ASC LIMIT 2000");
$stmt->bind_param('s', $phase);
$stmt->execute();
$ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
$stmt->close();

if (!$ids) {
    fdm_json(['success'=>true,'message'=>'There are no Ready historical dues rows to finalize.','finalized'=>0,'changed'=>0]);
}

$finalized = 0;
$changed = 0;
$failed = 0;
foreach ($ids as $queueId) {
    $stmt = $conn->prepare('SELECT * FROM finance_dues_import_queue WHERE id=? AND phase=? LIMIT 1');
    $stmt->bind_param('is', $queueId, $phase);
    $stmt->execute();
    $beforeRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $conn->begin_transaction();
    try {
        $result = fdm_finalize_queue_row($conn, $queueId, $adminId, $phase);
        $conn->commit();

        $stmt = $conn->prepare('SELECT * FROM finance_dues_import_queue WHERE id=? AND phase=? LIMIT 1');
        $stmt->bind_param('is', $queueId, $phase);
        $stmt->execute();
        $afterRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result['finalized'] ?? false) {
            $finalized++;
            if (function_exists('finance_audit_log')) {
                finance_audit_log(
                    $conn,
                    'Historical dues payment finalized by bulk action',
                    'historical_dues_queue',
                    $queueId,
                    'Ready migration row finalized by bulk action.',
                    [
                        'queue'=>$beforeRow,
                        'payment_before'=>$result['payment_before'] ?? null
                    ],
                    [
                        'queue'=>$afterRow,
                        'payment_after'=>$result['payment_after'] ?? null
                    ],
                    (string)($beforeRow['batch_id'] ?? ''),
                    $adminId,
                    $phase
                );
            }
        } else {
            $changed++;
            if (function_exists('finance_audit_log')) {
                finance_audit_log(
                    $conn,
                    'Historical dues bulk finalization blocked',
                    'historical_dues_queue',
                    $queueId,
                    (string)($result['message'] ?? 'Row changed status during bulk finalization.'),
                    $beforeRow,
                    $afterRow,
                    (string)($beforeRow['batch_id'] ?? ''),
                    $adminId,
                    $phase
                );
            }
        }
    } catch (Throwable $e) {
        $conn->rollback();
        $failed++;
        error_log('Historical dues bulk row #' . $queueId . ' failed: ' . $e->getMessage());
    }
}

fdm_log(
    $conn,
    $adminId,
    $phase,
    'Historical dues bulk finalization completed',
    sprintf('%d row(s) finalized; %d row(s) changed to duplicate/conflict or otherwise blocked; %d row(s) failed.', $finalized, $changed, $failed),
    'historical_dues_batch',
    null,
    ['queue_ids'=>$ids],
    ['finalized'=>$finalized,'changed'=>$changed,'failed'=>$failed]
);

fdm_json([
    'success'=>true,
    'message'=>$finalized . ' Ready historical dues row(s) finalized.',
    'finalized'=>$finalized,
    'changed'=>$changed,
    'failed'=>$failed,
]);
