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
$queueId = (int)($_POST['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
if ($queueId <= 0 || !in_array($action, ['match','skip','recheck'], true)) {
    fdm_json(['success'=>false,'message'=>'Invalid migration action.'], 422);
}

$stmt = $conn->prepare('SELECT * FROM finance_dues_import_queue WHERE id=? AND phase=? LIMIT 1');
$stmt->bind_param('is', $queueId, $phase);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) {
    fdm_json(['success'=>false,'message'=>'Migration row not found for your phase.'], 404);
}

if (in_array((string)$row['status'], ['finalized','skipped'], true)) {
    fdm_json(['success'=>false,'message'=>'This migration row is already closed.'], 409);
}

if ($action === 'skip') {
    $stmt = $conn->prepare("UPDATE finance_dues_import_queue SET status='skipped', match_note='Skipped by Treasurer.' WHERE id=? AND phase=?");
    $stmt->bind_param('is', $queueId, $phase);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('SELECT * FROM finance_dues_import_queue WHERE id=? AND phase=? LIMIT 1');
    $stmt->bind_param('is', $queueId, $phase);
    $stmt->execute();
    $afterRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    fdm_log(
        $conn,
        $adminId,
        $phase,
        'Historical dues migration row skipped',
        'Migration row #' . $queueId . ' was skipped by Treasurer.',
        'historical_dues_queue',
        $queueId,
        $row,
        $afterRow,
        (string)($row['batch_id'] ?? '')
    );
    fdm_json(['success'=>true,'message'=>'Historical dues row skipped.']);
}

if ($action === 'match') {
    $homeownerId = (int)($_POST['homeowner_id'] ?? 0);
    if ($homeownerId <= 0) {
        fdm_json(['success'=>false,'message'=>'Select a homeowner to match this historical record.'], 422);
    }
    $result = fdm_recompute_queue_row($conn, $queueId, $homeownerId);
    if (!($result['success'] ?? false)) {
        fdm_json(['success'=>false,'message'=>(string)($result['message'] ?? 'The homeowner could not be matched.')], 422);
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
        'Historical dues homeowner manually matched',
        sprintf('Migration row #%d was manually matched to homeowner #%d. Result status: %s.', $queueId, $homeownerId, (string)($result['status'] ?? '')),
        'historical_dues_queue',
        $queueId,
        $row,
        $afterRow,
        (string)($row['batch_id'] ?? '')
    );
    fdm_json(['success'=>true,'message'=>'Homeowner match updated.','status'=>$result['status'] ?? null]);
}

$result = fdm_recompute_queue_row($conn, $queueId, null);
if (!($result['success'] ?? false)) {
    fdm_json(['success'=>false,'message'=>(string)($result['message'] ?? 'The row could not be rechecked.')], 422);
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
    'Historical dues migration row rechecked',
    sprintf('Migration row #%d was rechecked. Result status: %s.', $queueId, (string)($result['status'] ?? '')),
    'historical_dues_queue',
    $queueId,
    $row,
    $afterRow,
    (string)($row['batch_id'] ?? '')
);
fdm_json(['success'=>true,'message'=>'Historical dues row rechecked.','status'=>$result['status'] ?? null]);
