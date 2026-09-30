<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() === PHP_SESSION_NONE) session_start();

function respond(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once 'admin_access.php';
require_once '../config/database.php';
require_once 'ownership_transfer_common.php';

if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)
) {
    respond(['success'=>false,'message'=>'Unauthorized.'], 401);
}
requireAccess('homeowner_management');

$sourceType = otNormalizeSourceType(trim((string)($_GET['source'] ?? 'import_queue')));
$sourceId = (int)($_GET['id'] ?? 0);
if ($sourceType === '' || $sourceId <= 0) {
    respond(['success'=>false,'message'=>'Invalid resident conflict record.'], 400);
}

$adminId = (int)$_SESSION['admin_id'];
$stmt = $conn->prepare('SELECT phase, role FROM admins WHERE id=? LIMIT 1');
$stmt->bind_param('i', $adminId);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$admin) respond(['success'=>false,'message'=>'Admin account not found.'], 403);

$adminRole = (string)$admin['role'];
$adminPhase = (string)$admin['phase'];
$incoming = otLoadIncoming($conn, $sourceType, $sourceId, $adminRole, $adminPhase, false);
if (!$incoming) {
    respond(['success'=>false,'message'=>'Resident conflict record was not found, was already processed, or is outside your phase.'], 404);
}

$existing = null;
if ($sourceType === 'import_queue') {
    $existingId = (int)($incoming['duplicate_homeowner_id'] ?? 0);
    if ($existingId > 0) {
        $oldStmt = $conn->prepare('SELECT * FROM homeowners WHERE id=? LIMIT 1');
        $oldStmt->bind_param('i', $existingId);
        $oldStmt->execute();
        $existing = $oldStmt->get_result()->fetch_assoc() ?: null;
        $oldStmt->close();
    }
} else {
    $existing = otFindApprovedPropertyOwner($conn, $incoming, $sourceId);
}

if (!$existing) {
    respond(['success'=>false,'message'=>'No current approved homeowner is linked to this property conflict.'], 404);
}

[$incomingBlock, $incomingLot] = otBlockLot($incoming);
[$existingBlock, $existingLot] = otBlockLot($existing);
$sameProperty = otSameProperty($incoming, $existing);
$differentEmail = otEmailsDifferent($incoming, $existing);
$isPossibleTransfer = $sameProperty && $differentEmail && (string)$existing['status'] === 'approved';

$matchType = 'duplicate_account';
if ($sourceType === 'pending_homeowner') {
    $matchType = $isPossibleTransfer ? 'possible_ownership_transfer' : 'property_conflict';
} elseif ($isPossibleTransfer) {
    $matchType = 'possible_ownership_transfer';
}

$schemaReady = otTransferSchemaReady($conn);
$transfer = $schemaReady ? otFindTransferBySource($conn, $sourceType, $sourceId, false) : null;
if ($transfer) {
    $transfer['old_confirmation_label'] = otConfirmationBadge((string)$transfer['old_confirmation']);
    $transfer['new_confirmation_label'] = otConfirmationBadge((string)$transfer['new_confirmation']);
    $transfer['ready_for_admin'] =
        in_array((string)$transfer['old_confirmation'], ['confirmed','manual_verified'], true) &&
        (string)$transfer['new_confirmation'] === 'confirmed' &&
        !in_array((string)$transfer['status'], ['completed','cancelled','denied'], true);
}

respond([
    'success'=>true,
    'source_type'=>$sourceType,
    'source_id'=>$sourceId,
    'match_type'=>$matchType,
    'schema_ready'=>$schemaReady,
    /* Keep queue for compatibility with the current modal JavaScript. */
    'queue'=>[
        'id'=>$sourceId,
        'name'=>otFullName($incoming),
        'first_name'=>(string)($incoming['first_name'] ?? ''),
        'middle_name'=>(string)($incoming['middle_name'] ?? ''),
        'last_name'=>(string)($incoming['last_name'] ?? ''),
        'email'=>(string)($incoming['email'] ?? ''),
        'contact_number'=>(string)($incoming['contact_number'] ?? ''),
        'phase'=>(string)($incoming['phase'] ?? ''),
        'block'=>$incomingBlock,
        'lot'=>$incomingLot,
        'street'=>(string)($incoming['street'] ?? ''),
        'residential_type'=>(string)($incoming['residential_type'] ?? ''),
        'created_at'=>(string)($incoming['created_at'] ?? ''),
        'public_id'=>(string)($incoming['public_id'] ?? ''),
        'source_label'=>$sourceType === 'import_queue' ? 'Imported Resident' : 'Pending Registered Homeowner',
    ],
    'incoming'=>[
        'id'=>$sourceId,
        'name'=>otFullName($incoming),
        'email'=>(string)($incoming['email'] ?? ''),
        'contact_number'=>(string)($incoming['contact_number'] ?? ''),
        'phase'=>(string)($incoming['phase'] ?? ''),
        'block'=>$incomingBlock,
        'lot'=>$incomingLot,
        'street'=>(string)($incoming['street'] ?? ''),
        'residential_type'=>(string)($incoming['residential_type'] ?? ''),
        'public_id'=>(string)($incoming['public_id'] ?? ''),
    ],
    'existing'=>[
        'id'=>(int)$existing['id'],
        'public_id'=>(string)($existing['public_id'] ?? ''),
        'name'=>otFullName($existing),
        'email'=>(string)($existing['email'] ?? ''),
        'contact_number'=>(string)($existing['contact_number'] ?? ''),
        'phase'=>(string)($existing['phase'] ?? ''),
        'block'=>$existingBlock,
        'lot'=>$existingLot,
        'street'=>(string)($existing['street'] ?? ''),
        'status'=>(string)($existing['status'] ?? ''),
        'created_at'=>(string)($existing['created_at'] ?? ''),
    ],
    'transfer'=>$transfer,
]);
