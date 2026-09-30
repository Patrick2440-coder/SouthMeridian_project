<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

session_start();
require_once 'admin_access.php';
require_once '../config/database.php';
requireAccess('homeowner_management');

header('Content-Type: application/json; charset=UTF-8');

$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'PHPMailer Composer autoload was not found.',
    ]);
    exit();
}
require_once $autoloadPath;

$secretsPath = __DIR__ . '/private/hoa_secrets.php';
$secrets = is_file($secretsPath) ? require $secretsPath : [];
if (!is_array($secrets)) {
    $secrets = [];
}

$smtpUsername = trim((string)($secrets['smtp_username'] ?? getenv('HOA_SMTP_USERNAME') ?? ''));
$smtpPassword = trim((string)($secrets['smtp_password'] ?? getenv('HOA_SMTP_PASSWORD') ?? ''));

function json_response(bool $success, string $message, array $extra = [], int $status = 200): void {
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function clean_email(string $email): string {
    return strtolower(trim($email));
}

function send_duplicate_archive_notice(
    string $to,
    string $name,
    string $subject,
    string $html,
    string $smtpUsername,
    string $smtpPassword
): array {
    $to = trim($to);

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error' => 'Invalid or missing email address.'];
    }

    if ($smtpUsername === '' || $smtpPassword === '') {
        return ['sent' => false, 'error' => 'SMTP credentials are missing from admin/private/hoa_secrets.php.'];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        $mail->SMTPDebug = 0;
        $mail->Timeout = 30;

        $mail->setFrom($smtpUsername, 'South Meridian HOA');
        $mail->addAddress($to, $name !== '' ? $name : $to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ["\n", "\n", "\n", "\n"], $html)));
        $mail->send();

        return ['sent' => true, 'error' => ''];
    } catch (MailException $e) {
        return ['sent' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
    } catch (Throwable $e) {
        return ['sent' => false, 'error' => $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Method not allowed.', [], 405);
}

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)) {
    json_response(false, 'Unauthorized admin session.', [], 401);
}

$sessionCsrf = (string)($_SESSION['homeowner_import_csrf'] ?? '');
$postCsrf = (string)($_POST['csrf'] ?? '');
if ($sessionCsrf === '' || $postCsrf === '' || !hash_equals($sessionCsrf, $postCsrf)) {
    json_response(false, 'Invalid or expired security token. Refresh the page and try again.', [], 403);
}

$queueId = (int)($_POST['id'] ?? 0);
if ($queueId <= 0) {
    json_response(false, 'Invalid duplicate record.', [], 400);
}

$adminId = (int)$_SESSION['admin_id'];
$adminRole = (string)$_SESSION['admin_role'];

$adminStmt = $conn->prepare('SELECT phase FROM admins WHERE id=? LIMIT 1');
$adminStmt->bind_param('i', $adminId);
$adminStmt->execute();
$adminRow = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();
$adminPhase = (string)($adminRow['phase'] ?? '');

if ($adminRole === 'superadmin') {
    $queueStmt = $conn->prepare(
        "SELECT * FROM homeowner_import_queue WHERE id=? AND status='duplicate' LIMIT 1"
    );
    $queueStmt->bind_param('i', $queueId);
} else {
    $queueStmt = $conn->prepare(
        "SELECT * FROM homeowner_import_queue WHERE id=? AND status='duplicate' AND phase=? LIMIT 1"
    );
    $queueStmt->bind_param('is', $queueId, $adminPhase);
}

$queueStmt->execute();
$queueRow = $queueStmt->get_result()->fetch_assoc();
$queueStmt->close();

if (!$queueRow) {
    json_response(false, 'Duplicate import record was not found or is outside your assigned phase.', [], 404);
}

$importedEmail = clean_email((string)($queueRow['email'] ?? ''));
if ($importedEmail === '') {
    json_response(false, 'The duplicate import has no email address to match.', [], 422);
}

/*
 * The duplicate rule in the current import flow is based on an email already
 * existing in homeowners. We resolve that existing homeowner here so both
 * parties can be notified and linked in the archive record.
 */
$duplicateHomeownerId = (int)($queueRow['duplicate_homeowner_id'] ?? 0);

if ($duplicateHomeownerId > 0) {
    $existingStmt = $conn->prepare(
        "SELECT id, public_id, first_name, middle_name, last_name, email, phase, block, lot, house_lot_number, status
         FROM homeowners
         WHERE id=?
         LIMIT 1"
    );
    $existingStmt->bind_param('i', $duplicateHomeownerId);
} else {
    $existingStmt = $conn->prepare(
        "SELECT id, public_id, first_name, middle_name, last_name, email, phase, block, lot, house_lot_number, status
         FROM homeowners
         WHERE LOWER(TRIM(email)) = LOWER(TRIM(?))
         ORDER BY CASE WHEN status='approved' THEN 0 ELSE 1 END, id ASC
         LIMIT 1"
    );
    $existingStmt->bind_param('s', $importedEmail);
}

$existingStmt->execute();
$existing = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();

if (!$existing) {
    json_response(false, 'The matching existing homeowner could not be found. The duplicate was not archived.', [], 404);
}

$conn->query("CREATE TABLE IF NOT EXISTS homeowner_import_archive (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$alreadyStmt = $conn->prepare('SELECT id FROM homeowner_import_archive WHERE source_queue_id=? LIMIT 1');
$alreadyStmt->bind_param('i', $queueId);
$alreadyStmt->execute();
$alreadyArchived = $alreadyStmt->get_result()->fetch_assoc();
$alreadyStmt->close();

if ($alreadyArchived) {
    json_response(true, 'This duplicate record is already archived. No records were deleted.', [
        'email_sent' => true,
        'already_archived' => true,
    ]);
}

$existingId = (int)$existing['id'];
$firstName = trim((string)($queueRow['first_name'] ?? ''));
$middleName = trim((string)($queueRow['middle_name'] ?? ''));
$lastName = trim((string)($queueRow['last_name'] ?? ''));
$contactNumber = trim((string)($queueRow['contact_number'] ?? ''));
$phase = trim((string)($queueRow['phase'] ?? ''));
$block = (int)($queueRow['block'] ?? 0);
$lot = (int)($queueRow['lot'] ?? 0);
$street = trim((string)($queueRow['street'] ?? ''));
$residentialType = trim((string)($queueRow['residential_type'] ?? ''));
$existingEmail = clean_email((string)($existing['email'] ?? ''));

$archiveStmt = $conn->prepare(
    "INSERT INTO homeowner_import_archive
     (source_queue_id, existing_homeowner_id, first_name, middle_name, last_name,
      contact_number, email, phase, block, lot, street, residential_type,
      existing_email, archived_by_admin_id)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$archiveStmt->bind_param(
    'iissssssiiissi',
    $queueId,
    $existingId,
    $firstName,
    $middleName,
    $lastName,
    $contactNumber,
    $importedEmail,
    $phase,
    $block,
    $lot,
    $street,
    $residentialType,
    $existingEmail,
    $adminId
);

if (!$archiveStmt->execute()) {
    $error = $archiveStmt->error;
    $archiveStmt->close();
    json_response(false, 'Unable to archive the duplicate record: ' . $error, [], 500);
}
$archiveStmt->close();

$importedName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
$existingName = trim(
    (string)($existing['first_name'] ?? '') . ' ' .
    (string)($existing['middle_name'] ?? '') . ' ' .
    (string)($existing['last_name'] ?? '')
);
$existingDisplayId = trim((string)($existing['public_id'] ?? ''));
if ($existingDisplayId === '') {
    $existingDisplayId = (string)$existingId;
}

$subjectImported = 'South Meridian Homes - Duplicate Import Notice';
$bodyImported = '<p>Hello ' . htmlspecialchars($importedName ?: 'Resident', ENT_QUOTES, 'UTF-8') . ',</p>'
    . '<p>Your imported homeowner information matched an existing registered homeowner account using the same email address.</p>'
    . '<p>For record integrity, <strong>no record was deleted</strong>. Your imported entry was moved to the duplicate archive for review/history, while the existing homeowner record remains preserved.</p>'
    . '<p>If you believe this duplicate match is incorrect, please contact the South Meridian Homes HOA office.</p>'
    . '<p>South Meridian Homes HOA</p>';

$subjectExisting = 'South Meridian Homes - Duplicate Record Detected';
$bodyExisting = '<p>Hello ' . htmlspecialchars($existingName ?: 'Homeowner', ENT_QUOTES, 'UTF-8') . ',</p>'
    . '<p>A newly imported homeowner entry matched the email address of your existing homeowner record (ID: <strong>'
    . htmlspecialchars($existingDisplayId, ENT_QUOTES, 'UTF-8') . '</strong>).</p>'
    . '<p><strong>Your existing homeowner record was not deleted or replaced.</strong> The imported duplicate was preserved in the archive for audit/history purposes.</p>'
    . '<p>If you do not recognize this activity, please contact the South Meridian Homes HOA office.</p>'
    . '<p>South Meridian Homes HOA</p>';

$importedMail = send_duplicate_archive_notice($importedEmail, $importedName, $subjectImported, $bodyImported, $smtpUsername, $smtpPassword);
$existingMail = send_duplicate_archive_notice($existingEmail, $existingName, $subjectExisting, $bodyExisting, $smtpUsername, $smtpPassword);

$bothSent = $importedMail['sent'] && $existingMail['sent'];
$emailErrors = [];
if (!$importedMail['sent']) {
    $emailErrors[] = 'Imported resident: ' . $importedMail['error'];
}
if (!$existingMail['sent']) {
    $emailErrors[] = 'Existing homeowner: ' . $existingMail['error'];
}

json_response(true,
    $bothSent
        ? 'Both parties were notified and the duplicate record was archived. No records were deleted.'
        : 'The duplicate record was archived and preserved, but one or both email notifications could not be sent.',
    [
        'email_sent' => $bothSent,
        'imported_email_sent' => (bool)$importedMail['sent'],
        'existing_email_sent' => (bool)$existingMail['sent'],
        'email_error' => implode(' | ', $emailErrors),
        'archived' => true,
        'deleted' => false,
    ]
);
