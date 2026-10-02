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
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function findApprovedPropertyOwner(mysqli $conn, array $homeowner, int $excludeId): ?array
{
    $phase = trim((string)($homeowner['phase'] ?? ''));
    $block = (int)($homeowner['block'] ?? 0);
    $lot = (int)($homeowner['lot'] ?? 0);
    $houseLot = trim((string)($homeowner['house_lot_number'] ?? ''));

    if ($block > 0 && $lot > 0) {
        $stmt = $conn->prepare(
            "SELECT id, first_name, middle_name, last_name, email, phase, block, lot, house_lot_number
             FROM homeowners
             WHERE status='approved'
               AND phase=?
               AND id<>?
               AND block=?
               AND lot=?
             LIMIT 1"
        );
        $stmt->bind_param('siii', $phase, $excludeId, $block, $lot);
    } elseif ($houseLot !== '') {
        $stmt = $conn->prepare(
            "SELECT id, first_name, middle_name, last_name, email, phase, block, lot, house_lot_number
             FROM homeowners
             WHERE status='approved'
               AND phase=?
               AND id<>?
               AND LOWER(TRIM(house_lot_number))=LOWER(TRIM(?))
             LIMIT 1"
        );
        $stmt->bind_param('sis', $phase, $excludeId, $houseLot);
    } else {
        return null;
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function buildHomeownerMail(
    string $smtpUsername,
    string $smtpPassword,
    string $email,
    string $fullName,
    string $resetLink
): \PHPMailer\PHPMailer\PHPMailer {
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

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
    $mail->setFrom($smtpUsername, 'South Meridian HOA');
    $mail->addAddress($email, $fullName);
    $mail->isHTML(true);
    $mail->Subject = 'South Meridian HOA - Create Your Homeowner Password';

    $mail->Body = "
        <div style='font-family:Arial,sans-serif;color:#1f2937;line-height:1.65;max-width:620px;margin:auto;'>
            <h2 style='color:#077f46;margin-bottom:8px;'>Your Homeowner Account Is Ready</h2>
            <p>Hello <strong>{$safeName}</strong>,</p>
            <p>
                Your existing South Meridian homeowner record has been added to the new HOA management system.
                Please create your homeowner password before signing in.
            </p>
            <p style='margin:24px 0;'>
                <a href='{$safeLink}'
                   style='display:inline-block;background:#077f46;color:#fff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:700;'>
                    Create Homeowner Password
                </a>
            </p>
            <p style='font-size:13px;color:#64748b;'>
                This setup link is valid for 1 hour. If the button does not work, copy this link into your browser:<br>
                <span style='word-break:break-all;'>{$safeLink}</span>
            </p>
            <p style='margin-top:24px;'>— South Meridian Homes HOA</p>
        </div>
    ";

    $mail->AltBody =
        "Hello {$fullName},\n\n" .
        "Your existing South Meridian homeowner record has been added to the new HOA management system.\n" .
        "Create your homeowner password here:\n{$resetLink}\n\n" .
        "This setup link is valid for 1 hour.\n\n" .
        "South Meridian Homes HOA";

    return $mail;
}

if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    $_SESSION['admin_role'] !== 'superadmin'
) {
    respond([
        'success' => false,
        'message' => 'Superadmin access is required.'
    ], 401);
}

require_once '../config/database.php';

// Bulk first-time migration can send many account emails in one request.
@set_time_limit(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond([
        'success' => false,
        'message' => 'Invalid request method.'
    ], 405);
}

$csrf = trim((string)($_POST['csrf'] ?? ''));
$sessionCsrf = trim((string)($_SESSION['superadmin_homeowner_import_csrf'] ?? ''));

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

if (!is_file($autoload)) {
    respond([
        'success' => false,
        'message' => 'PHPMailer is not available.'
    ], 500);
}

require_once $autoload;

$secretsFile = __DIR__ . '/../admin/private/hoa_secrets.php';

if (!is_file($secretsFile)) {
    respond([
        'success' => false,
        'message' => 'Email configuration was not found.'
    ], 500);
}

$secrets = require $secretsFile;

if (!is_array($secrets)) {
    respond([
        'success' => false,
        'message' => 'Email configuration is invalid.'
    ], 500);
}

$smtpUsername = trim((string)($secrets['smtp_username'] ?? ''));
$smtpPassword = preg_replace('/\s+/', '', trim((string)($secrets['smtp_password'] ?? '')));
$appBaseUrl = rtrim(trim((string)($secrets['app_base_url'] ?? '')), '/');

if (
    $smtpUsername === '' ||
    !filter_var($smtpUsername, FILTER_VALIDATE_EMAIL) ||
    $smtpPassword === '' ||
    $appBaseUrl === '' ||
    !filter_var($appBaseUrl, FILTER_VALIDATE_URL)
) {
    respond([
        'success' => false,
        'message' => 'Email or application URL configuration is incomplete.'
    ], 500);
}

$bulk = (int)($_POST['all'] ?? 0) === 1;
$id = (int)($_POST['id'] ?? 0);

if (!$bulk && $id <= 0) {
    respond([
        'success' => false,
        'message' => 'Invalid homeowner record.'
    ], 400);
}

if ($bulk) {
    $stmt = $conn->prepare(
        "SELECT id, first_name, middle_name, last_name, email, phase,
                block, lot, house_lot_number, status, valid_id_path
         FROM homeowners
         WHERE status='pending'
           AND valid_id_path LIKE 'imports/%'
         ORDER BY id ASC"
    );
} else {
    $stmt = $conn->prepare(
        "SELECT id, first_name, middle_name, last_name, email, phase,
                block, lot, house_lot_number, status, valid_id_path
         FROM homeowners
         WHERE id=?
           AND status='pending'
           AND valid_id_path LIKE 'imports/%'
         LIMIT 1"
    );
    $stmt->bind_param('i', $id);
}

$stmt->execute();
$homeowners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$homeowners) {
    respond([
        'success' => true,
        'finalized' => 0,
        'failed' => 0,
        'message' => $bulk
            ? 'There are no homeowner records waiting to be finalized.'
            : 'This homeowner record is no longer waiting for finalization.'
    ]);
}

$finalized = 0;
$failed = 0;
$errors = [];

foreach ($homeowners as $homeowner) {
    $homeownerId = (int)$homeowner['id'];
    $email = strtolower(trim((string)($homeowner['email'] ?? '')));
    $fullName = trim(
        (string)($homeowner['first_name'] ?? '') . ' ' .
        (string)($homeowner['middle_name'] ?? '') . ' ' .
        (string)($homeowner['last_name'] ?? '')
    );

    if ($fullName === '') {
        $fullName = 'Homeowner';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $failed++;
        $errors[] = "{$fullName}: invalid email address.";
        continue;
    }

    $propertyOwner = findApprovedPropertyOwner($conn, $homeowner, $homeownerId);

    if ($propertyOwner) {
        $failed++;
        $errors[] = "{$fullName}: the property is already assigned to an active homeowner.";
        continue;
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $resetExpiry = date('Y-m-d H:i:s', time() + 3600);
    $temporaryPasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $resetLink = $appBaseUrl . '/admin/reset-password.php?token=' . urlencode($rawToken);
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $update = $conn->prepare(
            "UPDATE homeowners
             SET status='approved',
                 password=?,
                 must_change_password=1,
                 reset_token=?,
                 reset_expires=?
             WHERE id=?
               AND status='pending'
               AND valid_id_path LIKE 'imports/%'
             LIMIT 1"
        );
        $update->bind_param(
            'sssi',
            $temporaryPasswordHash,
            $tokenHash,
            $resetExpiry,
            $homeownerId
        );
        $update->execute();
        $affected = $update->affected_rows;
        $update->close();

        if ($affected !== 1) {
            throw new RuntimeException('The homeowner record changed before it could be finalized.');
        }

        $mail = buildHomeownerMail(
            $smtpUsername,
            $smtpPassword,
            $email,
            $fullName,
            $resetLink
        );
        $mail->send();

        $conn->commit();
        $transactionStarted = false;
        $finalized++;

    } catch (Throwable $e) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log('Homeowner migration rollback failed: ' . $rollbackError->getMessage());
            }
        }

        $failed++;
        $errors[] = "{$fullName}: account email could not be completed.";
        error_log(
            'Superadmin homeowner finalization failed for ID ' .
            $homeownerId . ': ' . $e->getMessage()
        );
    }
}

$message = $finalized . ' homeowner' . ($finalized === 1 ? '' : 's') . ' finalized.';

if ($failed > 0) {
    $message .= ' ' . $failed . ' record' . ($failed === 1 ? '' : 's') . ' still need attention.';
}

respond([
    'success' => true,
    'finalized' => $finalized,
    'failed' => $failed,
    'errors' => $errors,
    'message' => $message
]);
