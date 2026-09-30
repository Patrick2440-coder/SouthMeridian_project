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

function buildAccountMail(
    string $smtpUsername,
    string $smtpPassword,
    string $email,
    string $fullName,
    string $resetLink
): \PHPMailer\PHPMailer\PHPMailer {
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

    return $mail;
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
    $pendingStmt = $conn->prepare(
        "SELECT
            id,
            first_name,
            middle_name,
            last_name,
            email,
            phase,
            block,
            lot,
            house_lot_number
         FROM homeowners
         WHERE status='pending'
           AND valid_id_path LIKE 'imports/%'
         ORDER BY id ASC"
    );
} else {
    $pendingStmt = $conn->prepare(
        "SELECT
            id,
            first_name,
            middle_name,
            last_name,
            email,
            phase,
            block,
            lot,
            house_lot_number
         FROM homeowners
         WHERE status='pending'
           AND valid_id_path LIKE 'imports/%'
           AND phase=?
         ORDER BY id ASC"
    );

    $pendingStmt->bind_param(
        's',
        $adminPhase
    );
}

$pendingStmt->execute();
$pendingHomeowners = $pendingStmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);
$pendingStmt->close();

if (!$pendingHomeowners) {
    respond([
        'success' => true,
        'pushed' => 0,
        'failed' => 0,
        'email_sent' => 0,
        'message' => 'There are no valid pending imported homeowners to push.'
    ]);
}

$pushed = 0;
$failed = 0;
$errors = [];

foreach ($pendingHomeowners as $homeowner) {
    $homeownerId = (int)($homeowner['id'] ?? 0);

    $fullName = trim(
        (string)($homeowner['first_name'] ?? '') . ' ' .
        (string)($homeowner['middle_name'] ?? '') . ' ' .
        (string)($homeowner['last_name'] ?? '')
    );

    if ($fullName === '') {
        $fullName = 'Homeowner';
    }

    $email = strtolower(
        trim(
            (string)($homeowner['email'] ?? '')
        )
    );

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $failed++;
        $errors[] =
            "Homeowner ID {$homeownerId}: invalid email address.";
        continue;
    }

    $activePropertyOwner = otFindApprovedPropertyOwner($conn, $homeowner, $homeownerId);
    if ($activePropertyOwner && otEmailsDifferent($homeowner, $activePropertyOwner)) {
        [$conflictBlock, $conflictLot] = otBlockLot($homeowner);
        $failed++;
        $errors[] = "Homeowner ID {$homeownerId}: possible ownership transfer for {$homeowner['phase']}, Block {$conflictBlock}, Lot {$conflictLot}; use Ownership Transfer Verification.";
        continue;
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
                'Record changed before it could be finalized.'
            );
        }

        $mail = buildAccountMail(
            $smtpUsername,
            $smtpPassword,
            $email,
            $fullName,
            $resetLink
        );

        /*
         * Finalization becomes permanent only after the homeowner receives
         * the account-setup email successfully.
         */
        $mail->send();

        $conn->commit();
        $transactionStarted = false;

        $pushed++;

    } catch (Throwable $rowError) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log(
                    'Bulk push rollback failed for homeowner ID ' .
                    $homeownerId . ': ' .
                    $rollbackError->getMessage()
                );
            }
        }

        $failed++;
        $errors[] =
            "Homeowner ID {$homeownerId}: account notification could not be sent.";

        error_log(
            'Bulk imported-homeowner push failed for homeowner ID ' .
            $homeownerId . ': ' .
            $rowError->getMessage()
        );
    }
}

$message =
    "{$pushed} imported homeowner(s) were pushed to active homeowner data and emailed successfully.";

if ($failed > 0) {
    $message .=
        " {$failed} homeowner(s) were left in For Review because their account notification could not be completed.";
}

respond([
    'success' => true,
    'pushed' => $pushed,
    'failed' => $failed,
    'email_sent' => $pushed,
    'errors' => $errors,
    'message' => $message
]);
