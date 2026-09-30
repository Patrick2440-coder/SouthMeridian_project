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
require_once 'homeowner_activity_logger.php';

if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)
) {
    respond(['success' => false, 'message' => 'Unauthorized.'], 401);
}
requireAccess('homeowner_management');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$csrf = trim((string)($_POST['csrf'] ?? ''));
$sessionCsrf = trim((string)($_SESSION['homeowner_import_csrf'] ?? ''));
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    respond(['success' => false, 'message' => 'Security token expired. Reload the page.'], 403);
}

$queueId = (int)($_POST['id'] ?? 0);
if ($queueId <= 0) {
    respond(['success' => false, 'message' => 'Invalid duplicate record.'], 400);
}

$adminId = (int)$_SESSION['admin_id'];
$adminStmt = $conn->prepare('SELECT phase, role FROM admins WHERE id=? LIMIT 1');
$adminStmt->bind_param('i', $adminId);
$adminStmt->execute();
$admin = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();
if (!$admin) respond(['success' => false, 'message' => 'Admin account not found.'], 403);

$adminRole = (string)$admin['role'];
$adminPhase = (string)$admin['phase'];

if ($adminRole === 'superadmin') {
    $queueStmt = $conn->prepare("SELECT * FROM homeowner_import_queue WHERE id=? AND status='duplicate' LIMIT 1");
    $queueStmt->bind_param('i', $queueId);
} else {
    $queueStmt = $conn->prepare("SELECT * FROM homeowner_import_queue WHERE id=? AND phase=? AND status='duplicate' LIMIT 1");
    $queueStmt->bind_param('is', $queueId, $adminPhase);
}
$queueStmt->execute();
$queue = $queueStmt->get_result()->fetch_assoc();
$queueStmt->close();
if (!$queue) respond(['success' => false, 'message' => 'Duplicate record was not found or is outside your phase.'], 404);

$existingId = (int)($queue['duplicate_homeowner_id'] ?? 0);
if ($existingId <= 0) respond(['success' => false, 'message' => 'This duplicate is not linked to an existing homeowner.'], 409);

$existingStmt = $conn->prepare('SELECT * FROM homeowners WHERE id=? LIMIT 1');
$existingStmt->bind_param('i', $existingId);
$existingStmt->execute();
$existing = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();
if (!$existing) respond(['success' => false, 'message' => 'The linked existing homeowner was not found.'], 404);

/* Do not archive an ownership-transfer case while verification is active. */
if (otTransferSchemaReady($conn)) {
    $transferStmt = $conn->prepare('SELECT id, status FROM homeowner_ownership_transfers WHERE source_queue_id=? LIMIT 1');
    $transferStmt->bind_param('i', $queueId);
    $transferStmt->execute();
    $transfer = $transferStmt->get_result()->fetch_assoc();
    $transferStmt->close();

    if ($transfer && in_array((string)$transfer['status'], ['awaiting_confirmation', 'ready_for_admin'], true)) {
        respond([
            'success' => false,
            'message' => 'This record has an active ownership-transfer verification. Cancel or resolve that transfer before archiving it as a duplicate.'
        ], 409);
    }
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS homeowner_import_archive (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      source_queue_id INT NOT NULL,
      existing_homeowner_id INT NULL,
      first_name VARCHAR(100) NULL,
      middle_name VARCHAR(100) NULL,
      last_name VARCHAR(100) NULL,
      contact_number VARCHAR(50) NULL,
      email VARCHAR(190) NULL,
      phase VARCHAR(50) NULL,
      block INT NULL,
      lot INT NULL,
      street VARCHAR(190) NULL,
      residential_type VARCHAR(100) NULL,
      existing_email VARCHAR(190) NULL,
      archived_by_admin_id INT NULL,
      archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_homeowner_import_archive_source (source_queue_id),
      KEY idx_homeowner_import_archive_phase (phase),
      KEY idx_homeowner_import_archive_archived_at (archived_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$alreadyStmt = $conn->prepare('SELECT id FROM homeowner_import_archive WHERE source_queue_id=? LIMIT 1');
$alreadyStmt->bind_param('i', $queueId);
$alreadyStmt->execute();
$already = $alreadyStmt->get_result()->fetch_assoc();
$alreadyStmt->close();
if ($already) respond(['success' => false, 'message' => 'This duplicate record is already archived.'], 409);

[$block, $lot] = otBlockLot($queue);
$importedName = otFullName($queue) ?: 'Imported Resident';
$existingName = otFullName($existing) ?: 'Existing Homeowner';
$phase = (string)($queue['phase'] ?? '');
$property = $block > 0 && $lot > 0 ? "{$phase}, Block {$block}, Lot {$lot}" : $phase;
$incomingEmail = strtolower(trim((string)($queue['email'] ?? '')));
$existingEmail = strtolower(trim((string)($existing['email'] ?? '')));

$mailConfig = null;
$emailSent = true;
$emailErrors = [];

try {
    $mailConfig = otLoadMailConfig();

    if (filter_var($incomingEmail, FILTER_VALIDATE_EMAIL)) {
        $sameRecipient = $incomingEmail === $existingEmail;
        $body = "
        <div style=\"font-family:Arial,sans-serif;color:#222;line-height:1.6;\">
          <h2 style=\"color:#077f46;\">South Meridian HOA</h2>
          <p>Hello <strong>" . htmlspecialchars($importedName, ENT_QUOTES, 'UTF-8') . "</strong>,</p>
          <p>Your imported/registration record for <strong>" . htmlspecialchars($property, ENT_QUOTES, 'UTF-8') . "</strong> matched an existing homeowner record and was reviewed by the HOA administrator.</p>
          <p>The duplicate review record has been archived for audit/history. This action did not delete the existing homeowner history.</p>
          <p>If you believe this should instead be processed as a property ownership transfer, please contact the HOA office.</p>
        </div>";
        otSendMail(
            $mailConfig,
            $incomingEmail,
            $importedName,
            'South Meridian HOA Duplicate Record Review',
            $body,
            "Hello {$importedName},\n\nYour record for {$property} matched an existing homeowner record and was archived as a duplicate after admin review. Contact the HOA office if this should be an ownership transfer."
        );

        if ($sameRecipient) {
            $existingEmail = '';
        }
    }

    if (filter_var($existingEmail, FILTER_VALIDATE_EMAIL)) {
        $body = "
        <div style=\"font-family:Arial,sans-serif;color:#222;line-height:1.6;\">
          <h2 style=\"color:#077f46;\">South Meridian HOA</h2>
          <p>Hello <strong>" . htmlspecialchars($existingName, ENT_QUOTES, 'UTF-8') . "</strong>,</p>
          <p>A record that matched your homeowner/property information at <strong>" . htmlspecialchars($property, ENT_QUOTES, 'UTF-8') . "</strong> was reviewed and archived as a duplicate.</p>
          <p>Your existing homeowner account and historical HOA records were not deleted or replaced.</p>
          <p>If you recently sold or transferred this property, please contact the HOA office so it can be processed through the ownership-transfer verification flow.</p>
        </div>";
        otSendMail(
            $mailConfig,
            $existingEmail,
            $existingName,
            'South Meridian HOA Duplicate Record Notice',
            $body,
            "Hello {$existingName},\n\nA matching record for {$property} was archived as a duplicate. Your account/history was not deleted. If you transferred this property, contact the HOA office."
        );
    }
} catch (Throwable $mailError) {
    $emailSent = false;
    $emailErrors[] = $mailError->getMessage();
}

try {
    $archiveStmt = $conn->prepare(
        "INSERT INTO homeowner_import_archive
        (source_queue_id, existing_homeowner_id, first_name, middle_name, last_name,
         contact_number, email, phase, block, lot, street, residential_type,
         existing_email, archived_by_admin_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $first = (string)($queue['first_name'] ?? '');
    $middle = (string)($queue['middle_name'] ?? '');
    $last = (string)($queue['last_name'] ?? '');
    $contact = (string)($queue['contact_number'] ?? '');
    $street = (string)($queue['street'] ?? '');
    $residentialType = (string)($queue['residential_type'] ?? '');
    $archiveExistingEmail = strtolower(trim((string)($existing['email'] ?? '')));
    $archiveStmt->bind_param(
        'iissssssiiissi',
        $queueId, $existingId, $first, $middle, $last, $contact,
        $incomingEmail, $phase, $block, $lot, $street, $residentialType,
        $archiveExistingEmail, $adminId
    );
    $archiveStmt->execute();
    $archiveId = (int)$archiveStmt->insert_id;
    $archiveStmt->close();

    logHomeownerActivity(
        $conn,
        'Duplicate homeowner record archived',
        "Archive #{$archiveId}: imported resident {$importedName} (queue #{$queueId}) was archived as a duplicate of {$existingName} (#{$existingId}) for {$property}. No homeowner history was deleted.",
        $adminId,
        $phase
    );

    respond([
        'success' => true,
        'email_sent' => $emailSent,
        'email_error' => $emailErrors ? implode(' | ', $emailErrors) : '',
        'message' => $emailSent
            ? 'Duplicate record archived successfully. Notification email(s) were sent.'
            : 'Duplicate record was archived, but one or more email notifications could not be sent.'
    ]);

} catch (Throwable $e) {
    error_log('Archive duplicate failed: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Unable to archive the duplicate record: ' . $e->getMessage()], 500);
}
