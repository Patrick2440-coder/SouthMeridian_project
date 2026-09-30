<?php
ob_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function respond(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit();
}

if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)
) {
    respond([
        'success' => false,
        'message' => 'Unauthorized.'
    ], 401);
}

require_once 'admin_access.php';
require_once '../config/database.php';
require_once 'ownership_transfer_common.php';

if (!canAccess('homeowner_management')) {
    respond([
        'success' => false,
        'message' => 'You do not have access to Homeowner Management.'
    ], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond([
        'success' => false,
        'message' => 'Invalid request method.'
    ], 405);
}

$csrf = trim((string)($_POST['csrf'] ?? ''));
$sessionCsrf = trim(
    (string)(
        $_SESSION['homeowner_import_csrf'] ?? ''
    )
);

if (
    $csrf === '' ||
    $sessionCsrf === '' ||
    !hash_equals($sessionCsrf, $csrf)
) {
    respond([
        'success' => false,
        'message' => 'Security token expired. Reload the page and try again.'
    ], 403);
}

$homeownerId = (int)($_POST['id'] ?? 0);

if ($homeownerId <= 0) {
    respond([
        'success' => false,
        'message' => 'Invalid migrated homeowner ID.'
    ], 400);
}

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoload)) {
    respond([
        'success' => false,
        'message' => 'PHPMailer is not available. Composer autoload was not found.'
    ], 500);
}

require_once $autoload;

if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    respond([
        'success' => false,
        'message' => 'PHPMailer is not installed in the vendor folder.'
    ], 500);
}

$secretsFile = __DIR__ . '/private/hoa_secrets.php';

if (!is_file($secretsFile)) {
    respond([
        'success' => false,
        'message' => 'SMTP configuration file was not found.'
    ], 500);
}

$secrets = require $secretsFile;

if (!is_array($secrets)) {
    respond([
        'success' => false,
        'message' => 'SMTP configuration file is invalid.'
    ], 500);
}

$smtpUsername = trim(
    (string)(
        $secrets['smtp_username'] ?? ''
    )
);

$smtpPassword = preg_replace(
    '/\s+/',
    '',
    trim(
        (string)(
            $secrets['smtp_password'] ?? ''
        )
    )
);

$appBaseUrl = rtrim(
    trim(
        (string)(
            $secrets['app_base_url'] ?? ''
        )
    ),
    '/'
);

if (
    $smtpUsername === '' ||
    !filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)
) {
    respond([
        'success' => false,
        'message' => 'SMTP Gmail address is not configured correctly.'
    ], 500);
}

if ($smtpPassword === '') {
    respond([
        'success' => false,
        'message' => 'SMTP Gmail App Password is not configured.'
    ], 500);
}

if (
    $appBaseUrl === '' ||
    !filter_var($appBaseUrl, FILTER_VALIDATE_URL)
) {
    respond([
        'success' => false,
        'message' => 'Application base URL is not configured correctly.'
    ], 500);
}

$adminId = (int)$_SESSION['admin_id'];

$adminStmt = $conn->prepare(
    "SELECT phase, role
     FROM admins
     WHERE id=?
     LIMIT 1"
);

$adminStmt->bind_param('i', $adminId);
$adminStmt->execute();
$admin = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();

if (!$admin) {
    respond([
        'success' => false,
        'message' => 'Admin account was not found.'
    ], 403);
}

$adminRole = (string)($admin['role'] ?? '');
$adminPhase = (string)($admin['phase'] ?? '');

if ($adminRole === 'superadmin') {
    $homeownerStmt = $conn->prepare(
        "SELECT
            id,
            first_name,
            middle_name,
            last_name,
            email,
            phase,
            block,
            lot,
            house_lot_number,
            status,
            valid_id_path
         FROM homeowners
         WHERE id=?
         LIMIT 1"
    );

    $homeownerStmt->bind_param('i', $homeownerId);
} else {
    $homeownerStmt = $conn->prepare(
        "SELECT
            id,
            first_name,
            middle_name,
            last_name,
            email,
            phase,
            block,
            lot,
            house_lot_number,
            status,
            valid_id_path
         FROM homeowners
         WHERE id=?
           AND phase=?
         LIMIT 1"
    );

    $homeownerStmt->bind_param(
        'is',
        $homeownerId,
        $adminPhase
    );
}

$homeownerStmt->execute();
$homeowner = $homeownerStmt->get_result()->fetch_assoc();
$homeownerStmt->close();

if (!$homeowner) {
    respond([
        'success' => false,
        'message' => 'Migrated homeowner record was not found or is outside your assigned phase.'
    ], 404);
}

$status = strtolower(
    trim(
        (string)($homeowner['status'] ?? '')
    )
);

$validIdPath = trim(
    (string)($homeowner['valid_id_path'] ?? '')
);

$isMigratedImport =
    $validIdPath !== '' &&
    str_starts_with(
        $validIdPath,
        'imports/'
    );

if (!$isMigratedImport) {
    respond([
        'success' => false,
        'message' => 'This record is not an Excel-migrated homeowner record.'
    ], 400);
}

if ($status !== 'pending') {
    respond([
        'success' => false,
        'message' => $status === 'approved'
            ? 'This migrated homeowner record has already been finalized.'
            : 'This migrated homeowner record cannot be finalized from its current status.'
    ], 409);
}

$email = strtolower(
    trim(
        (string)($homeowner['email'] ?? '')
    )
);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond([
        'success' => false,
        'message' => 'The homeowner has an invalid email address. Correct the email before pushing the record.'
    ], 422);
}

$fullName = trim(
    (string)($homeowner['first_name'] ?? '') . ' ' .
    (string)($homeowner['middle_name'] ?? '') . ' ' .
    (string)($homeowner['last_name'] ?? '')
);

if ($fullName === '') {
    $fullName = 'Homeowner';
}

/* Final safety check: never activate a second owner for an occupied property. */
$activePropertyOwner = otFindApprovedPropertyOwner($conn, $homeowner, $homeownerId);
if ($activePropertyOwner && otEmailsDifferent($homeowner, $activePropertyOwner)) {
    [$conflictBlock, $conflictLot] = otBlockLot($homeowner);
    respond([
        'success' => false,
        'message' => "Possible ownership transfer detected for {$homeowner['phase']}, Block {$conflictBlock}, Lot {$conflictLot}. Review both homeowners and complete Ownership Transfer Verification instead of pushing this record directly."
    ], 409);
}

$resetToken = bin2hex(random_bytes(32));
$resetTokenHash = hash('sha256', $resetToken);
$resetExpiry = date('Y-m-d H:i:s', time() + 3600);

$temporaryPasswordHash = password_hash(
    bin2hex(random_bytes(32)),
    PASSWORD_DEFAULT
);

$resetLink =
    $appBaseUrl .
    '/admin/reset-password.php?token=' .
    urlencode($resetToken);

$transactionStarted = false;

try {
    $conn->begin_transaction();
    $transactionStarted = true;

    $updateStmt = $conn->prepare(
        "UPDATE homeowners
         SET
            status='approved',
            password=?,
            must_change_password=1,
            reset_token=?,
            reset_expires=?
         WHERE id=?
           AND status='pending'
           AND valid_id_path LIKE 'imports/%'
         LIMIT 1"
    );

    $updateStmt->bind_param(
        'sssi',
        $temporaryPasswordHash,
        $resetTokenHash,
        $resetExpiry,
        $homeownerId
    );

    $updateStmt->execute();
    $affectedRows = $updateStmt->affected_rows;
    $updateStmt->close();

    if ($affectedRows !== 1) {
        throw new RuntimeException(
            'The migrated record changed before it could be finalized.'
        );
    }

    $safeName = htmlspecialchars(
        $fullName,
        ENT_QUOTES,
        'UTF-8'
    );

    $safeLink = htmlspecialchars(
        $resetLink,
        ENT_QUOTES,
        'UTF-8'
    );

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUsername;
    $mail->Password = $smtpPassword;
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->SMTPDebug = 0;
    $mail->Timeout = 30;

    $mail->setFrom(
        $smtpUsername,
        'South Meridian HOA'
    );

    $mail->addAddress(
        $email,
        $fullName
    );

    $mail->isHTML(true);
    $mail->Subject = 'Your South Meridian HOA Account Is Now Available';

    $mail->Body = "
        <div style=\"font-family:Arial,sans-serif;color:#222;line-height:1.6;\">
            <h2 style=\"color:#077f46;\">South Meridian HOA</h2>

            <p>Hello <strong>{$safeName}</strong>,</p>

            <p>
                Your homeowner information has been added to the
                <strong>South Meridian Homes HOA Management System</strong>.
                Your homeowner account is now available.
            </p>

            <p>
                For security, please create your password using the button below
                before signing in to your account.
            </p>

            <p style=\"margin:24px 0;\">
                <a href=\"{$safeLink}\"
                   style=\"display:inline-block;background:#077f46;color:#ffffff;padding:12px 18px;text-decoration:none;border-radius:8px;font-weight:bold;\">
                    Set My Password
                </a>
            </p>

            <p>If the button does not work, copy and paste this link into your browser:</p>
            <p style=\"word-break:break-all;\">{$safeLink}</p>

            <p><strong>This password setup link expires in 1 hour.</strong></p>

            <p>
                If you did not expect this message, please contact the South Meridian HOA office.
            </p>

            <br>
            <p>Regards,<br><strong>South Meridian HOA</strong></p>
        </div>
    ";

    $mail->AltBody =
        "Hello {$fullName},\n\n" .
        "Your homeowner information has been added to the South Meridian Homes HOA Management System. " .
        "Your homeowner account is now available.\n\n" .
        "Please create your password using this link:\n{$resetLink}\n\n" .
        "This password setup link expires in 1 hour.\n\n" .
        "South Meridian HOA";

    /*
     * Email must succeed before the finalization becomes permanent.
     * If email sending fails, rollback keeps the homeowner in For Review
     * so the admin can correct the email/configuration and retry.
     */
    $mail->send();

    $conn->commit();
    $transactionStarted = false;

    respond([
        'success' => true,
        'email_sent' => true,
        'message' => 'Homeowner was pushed to active data and the account setup email was sent successfully.'
    ]);

} catch (Throwable $e) {
    if ($transactionStarted) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log(
                'Migration finalization rollback failed: ' .
                $rollbackError->getMessage()
            );
        }
    }

    error_log(
        'Migration finalization failed for homeowner ID ' .
        $homeownerId . ': ' .
        $e->getMessage()
    );

    respond([
        'success' => false,
        'message' => 'The homeowner was not pushed because the account notification email could not be completed. Check the email configuration/address and try again.'
    ], 500);
}
