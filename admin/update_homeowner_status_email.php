<?php

ob_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================================================
   JSON RESPONSE HELPER
   ========================================================= */
function respond(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/* =========================================================
   BASIC SESSION CHECK
   ========================================================= */
if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    !in_array(
        $_SESSION['admin_role'],
        ['admin', 'superadmin'],
        true
    )
) {
    respond([
        'success' => false,
        'message' => 'Unauthorized.'
    ], 401);
}

/* =========================================================
   LOAD ACCESS SYSTEM + DATABASE
   ========================================================= */
require_once 'admin_access.php';

/*
 * IMPORTANT:
 * Do not use requireAccess() here because this is a JSON endpoint.
 * requireAccess() currently outputs HTML/JavaScript when denied.
 */
if (!canAccess('homeowner_management')) {
    respond([
        'success' => false,
        'message' => 'You do not have access to Homeowner Management.'
    ], 403);
}

/* =========================================================
   PHPMailer
   ========================================================= */

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoload)) {
    respond([
        'success' => false,
        'message' =>
            'PHPMailer autoload not found at: ' . $autoload
    ], 500);
}

require_once $autoload;

if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    respond([
        'success' => false,
        'message' =>
            'Composer autoload exists, but PHPMailer is not installed in this vendor folder.'
    ], 500);
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* =========================================================
   REQUEST METHOD
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond([
        'success' => false,
        'message' => 'Invalid request method.'
    ], 405);
}

/* =========================================================
   CSRF
   ========================================================= */
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

/* =========================================================
   INPUT
   ========================================================= */
$id = (int)($_POST['id'] ?? 0);

$status = strtolower(
    trim(
        (string)($_POST['status'] ?? '')
    )
);

$reason = trim(
    (string)($_POST['reason'] ?? '')
);

if ($id <= 0) {
    respond([
        'success' => false,
        'message' => 'Invalid homeowner ID.'
    ], 400);
}

if (!in_array($status, ['approved', 'rejected'], true)) {
    respond([
        'success' => false,
        'message' => 'Invalid homeowner status.'
    ], 400);
}

if ($status === 'rejected' && $reason === '') {
    respond([
        'success' => false,
        'message' => 'Rejection reason is required.'
    ], 400);
}

/*
 * Prevent extremely large rejection messages.
 */
if (mb_strlen($reason) > 1000) {
    respond([
        'success' => false,
        'message' => 'Rejection reason is too long.'
    ], 400);
}

/* =========================================================
   CURRENT ADMIN
   ========================================================= */
$adminId = (int)($_SESSION['admin_id'] ?? 0);

$stmt = $conn->prepare("
    SELECT
        id,
        role,
        phase,
        position
    FROM admins
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param(
    'i',
    $adminId
);

$stmt->execute();

$admin = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$admin) {
    respond([
        'success' => false,
        'message' => 'Admin account was not found.'
    ], 403);
}

$adminRole = (string)($admin['role'] ?? '');
$adminPhase = (string)($admin['phase'] ?? '');

/* =========================================================
   FETCH HOMEOWNER WITH PHASE SECURITY
   ========================================================= */

/*
 * Superadmin:
 * Can access any phase.
 *
 * Normal admin:
 * Can only access homeowners from their assigned phase.
 */
if ($adminRole === 'superadmin') {

    $stmt = $conn->prepare("
        SELECT
            id,
            first_name,
            middle_name,
            last_name,
            email,
            phase,
            status,
            password,
            must_change_password,
            reset_token,
            reset_expires
        FROM homeowners
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'i',
        $id
    );

} else {

    $stmt = $conn->prepare("
        SELECT
            id,
            first_name,
            middle_name,
            last_name,
            email,
            phase,
            status,
            password,
            must_change_password,
            reset_token,
            reset_expires
        FROM homeowners
        WHERE id = ?
          AND phase = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'is',
        $id,
        $adminPhase
    );
}

$stmt->execute();

$user = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$user) {
    respond([
        'success' => false,
        'message' => 'Homeowner not found or outside your assigned phase.'
    ], 404);
}

/* =========================================================
   ONLY PENDING HOMEOWNERS MAY BE PROCESSED
   ========================================================= */
$currentStatus = strtolower(
    trim(
        (string)($user['status'] ?? '')
    )
);

if ($currentStatus !== 'pending') {
    respond([
        'success' => false,
        'message' =>
            'This homeowner has already been processed. Current status: ' .
            ucfirst($currentStatus) .
            '.'
    ], 409);
}

/* =========================================================
   VALIDATE EMAIL
   ========================================================= */
$email = trim(
    (string)($user['email'] ?? '')
);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond([
        'success' => false,
        'message' => 'The homeowner has an invalid email address.'
    ], 422);
}

/* =========================================================
   SMTP CONFIGURATION
   ========================================================= */

/*
|--------------------------------------------------------------------------
| Load private SMTP configuration
|--------------------------------------------------------------------------
*/

$secretsFile =
    __DIR__ .
    '/private/hoa_secrets.php';


if (!is_file($secretsFile)) {

    respond([
        'success' => false,
        'message' =>
            'SMTP configuration file was not found.'
    ], 500);
}


$secrets =
    require $secretsFile;


if (!is_array($secrets)) {

    respond([
        'success' => false,
        'message' =>
            'SMTP configuration file is invalid.'
    ], 500);
}


$smtpUsername =
    trim(
        (string)(
            $secrets['smtp_username']
            ?? ''
        )
    );


$smtpPassword =
    preg_replace(
        '/\s+/',
        '',
        trim(
            (string)(
                $secrets['smtp_password']
                ?? ''
            )
        )
    );


/*
|--------------------------------------------------------------------------
| Validate SMTP configuration
|--------------------------------------------------------------------------
*/

if (
    $smtpUsername === '' ||
    !filter_var(
        $smtpUsername,
        FILTER_VALIDATE_EMAIL
    )
) {

    respond([
        'success' => false,
        'message' =>
            'SMTP Gmail address is not configured correctly.'
    ], 500);
}


if ($smtpPassword === '') {

    respond([
        'success' => false,
        'message' =>
            'SMTP Gmail App Password is not configured.'
    ], 500);
}

/* =========================================================
   TRUSTED APPLICATION BASE URL
   ========================================================= */

$appBaseUrl =
    rtrim(
        trim(
            (string)(
                $secrets['app_base_url']
                ?? ''
            )
        ),
        '/'
    );


if (
    $appBaseUrl === '' ||
    !filter_var(
        $appBaseUrl,
        FILTER_VALIDATE_URL
    )
) {

    respond([
        'success' => false,
        'message' =>
            'Application base URL is not configured correctly.'
    ], 500);
}

/* =========================================================
   PREPARE USER NAME
   ========================================================= */
$firstName = trim(
    (string)($user['first_name'] ?? '')
);

$middleName = trim(
    (string)($user['middle_name'] ?? '')
);

$lastName = trim(
    (string)($user['last_name'] ?? '')
);

$fullName = trim(
    $firstName .
    ' ' .
    $middleName .
    ' ' .
    $lastName
);

/* =========================================================
   MAIN PROCESS
   ========================================================= */
try {

    /*
     * Keep database changes uncommitted until email succeeds.
     *
     * If email fails:
     * rollback()
     *
     * Result:
     * homeowner stays Pending and admin can retry.
     */
    $conn->begin_transaction();

    /* =====================================================
       APPROVE
       ===================================================== */
    if ($status === 'approved') {

/*
|--------------------------------------------------------------------------
| Password setup token
|--------------------------------------------------------------------------
|
| $resetToken:
|     Raw one-time token sent to the homeowner by email.
|
| $resetTokenHash:
|     SHA-256 version stored in the database.
|
*/

$resetToken =
    bin2hex(
        random_bytes(32)
    );

$resetTokenHash =
    hash(
        'sha256',
        $resetToken
    );

$resetExpiry =
    date(
        'Y-m-d H:i:s',
        time() + 3600
    );

        /*
         * Homeowner must create a new password using email link.
         */
        $temporaryPasswordHash =
            password_hash(
                bin2hex(
                    random_bytes(32)
                ),
                PASSWORD_DEFAULT
            );

        $stmt = $conn->prepare("
            UPDATE homeowners
            SET
                status = 'approved',
                password = ?,
                must_change_password = 1,
                reset_token = ?,
                reset_expires = ?
            WHERE id = ?
              AND status = 'pending'
        ");

$stmt->bind_param(
    'sssi',
    $temporaryPasswordHash,
    $resetTokenHash,
    $resetExpiry,
    $id
);

        $stmt->execute();

        $affected =
            $stmt->affected_rows;

        $stmt->close();

        if ($affected !== 1) {
            throw new RuntimeException(
                'Homeowner could not be approved because the record has already changed.'
            );
        }

        /*
         * Correct project-aware reset link.
         */
$resetLink =
    $appBaseUrl .
    '/admin/reset-password.php?token=' .
    urlencode($resetToken);

        /* =================================================
           APPROVAL EMAIL
           ================================================= */
        $mail = new PHPMailer(true);

        $mail->isSMTP();

        $mail->Host =
            'smtp.gmail.com';

        $mail->SMTPAuth =
            true;

        $mail->Username =
            $smtpUsername;

        $mail->Password =
            $smtpPassword;

        $mail->SMTPSecure =
            PHPMailer::ENCRYPTION_STARTTLS;

        $mail->Port =
            587;

        $mail->CharSet =
            'UTF-8';

        $mail->SMTPDebug =
            0;

        $mail->Timeout =
            30;

        $mail->setFrom(
            $smtpUsername,
            'South Meridian HOA'
        );

        $mail->addAddress(
            $email,
            $fullName
        );

        $safeName =
            htmlspecialchars(
                $fullName,
                ENT_QUOTES,
                'UTF-8'
            );

        $safeLink =
            htmlspecialchars(
                $resetLink,
                ENT_QUOTES,
                'UTF-8'
            );

        $mail->isHTML(true);

        $mail->Subject =
            'South Meridian HOA Account Approved - Set Your Password';

        $mail->Body = "
            <div style=\"
                font-family:Arial,sans-serif;
                color:#222;
                line-height:1.6;
            \">

                <h2 style=\"color:#077f46;\">
                    South Meridian HOA
                </h2>

                <p>
                    Hello <strong>{$safeName}</strong>,
                </p>

                <p>
                    Your homeowner registration has been
                    <strong>approved</strong>.
                </p>

                <p>
                    To activate your account, please create
                    your password using the button below.
                </p>

                <p style=\"margin:24px 0;\">
                    <a
                        href=\"{$safeLink}\"
                        style=\"
                            display:inline-block;
                            background:#077f46;
                            color:#ffffff;
                            padding:12px 18px;
                            text-decoration:none;
                            border-radius:8px;
                            font-weight:bold;
                        \"
                    >
                        Set My Password
                    </a>
                </p>

                <p>
                    If the button does not work, copy and
                    paste this link into your browser:
                </p>

                <p style=\"word-break:break-all;\">
                    {$safeLink}
                </p>

                <p>
                    <strong>
                        This password setup link expires in 1 hour.
                    </strong>
                </p>

                <br>

                <p>
                    Regards,<br>
                    <strong>South Meridian HOA</strong>
                </p>

            </div>
        ";

        $mail->AltBody =
            "Hello {$fullName},

Your South Meridian HOA homeowner registration has been approved.

Please create your password using this link:

{$resetLink}

This link expires in 1 hour.

South Meridian HOA";

        /*
         * If this throws an exception,
         * the transaction below will rollback.
         */
        $mail->send();

        /*
         * Email succeeded.
         * Now permanently save the approval.
         */
        $conn->commit();

        respond([
            'success' => true,
            'message' =>
                'Homeowner approved successfully. Password setup email was sent.'
        ]);
    }

    /* =====================================================
       REJECT
       ===================================================== */

    $stmt = $conn->prepare("
        UPDATE homeowners
        SET
            status = 'rejected',
            reset_token = NULL,
            reset_expires = NULL
        WHERE id = ?
          AND status = 'pending'
    ");

    $stmt->bind_param(
        'i',
        $id
    );

    $stmt->execute();

    $affected =
        $stmt->affected_rows;

    $stmt->close();

    if ($affected !== 1) {
        throw new RuntimeException(
            'Homeowner could not be rejected because the record has already changed.'
        );
    }

    /* =====================================================
       REJECTION EMAIL
       ===================================================== */
    $mail = new PHPMailer(true);

    $mail->isSMTP();

    $mail->Host =
        'smtp.gmail.com';

    $mail->SMTPAuth =
        true;

    $mail->Username =
        $smtpUsername;

    $mail->Password =
        $smtpPassword;

    $mail->SMTPSecure =
        PHPMailer::ENCRYPTION_STARTTLS;

    $mail->Port =
        587;

    $mail->CharSet =
        'UTF-8';

    $mail->SMTPDebug =
        0;

    $mail->Timeout =
        30;

    $mail->setFrom(
        $smtpUsername,
        'South Meridian HOA'
    );

    $mail->addAddress(
        $email,
        $fullName
    );

    $safeName =
        htmlspecialchars(
            $fullName,
            ENT_QUOTES,
            'UTF-8'
        );

    $safeReason =
        nl2br(
            htmlspecialchars(
                $reason,
                ENT_QUOTES,
                'UTF-8'
            )
        );

    $mail->isHTML(true);

    $mail->Subject =
        'South Meridian HOA Registration Update';

    $mail->Body = "
        <div style=\"
            font-family:Arial,sans-serif;
            color:#222;
            line-height:1.6;
        \">

            <h2 style=\"color:#077f46;\">
                South Meridian HOA
            </h2>

            <p>
                Hello <strong>{$safeName}</strong>,
            </p>

            <p>
                Your homeowner registration has been
                <strong>rejected</strong>.
            </p>

            <p>
                <strong>Reason:</strong>
            </p>

            <div style=\"
                background:#f8f9fa;
                border-left:4px solid #dc3545;
                padding:12px;
                margin:12px 0;
            \">
                {$safeReason}
            </div>

            <p>
                Please contact the South Meridian HOA office
                if you need assistance or clarification.
            </p>

            <br>

            <p>
                Regards,<br>
                <strong>South Meridian HOA</strong>
            </p>

        </div>
    ";

    $mail->AltBody =
        "Hello {$fullName},

Your South Meridian HOA homeowner registration has been rejected.

Reason:
{$reason}

Please contact the HOA office if you need assistance.

South Meridian HOA";

    /*
     * Email must succeed before rejection is committed.
     */
    $mail->send();

    $conn->commit();

    respond([
        'success' => true,
        'message' =>
            'Homeowner rejected successfully. Notification email was sent.'
    ]);

} catch (Throwable $e) {

    /*
     * If anything fails, undo the approval/rejection.
     * Homeowner remains pending.
     */
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }


    /*
     * Save the real error in the PHP/Apache error log.
     */
    error_log(
        'update_homeowner_status_email.php: ' .
        $e->getMessage()
    );


    /*
     * DEVELOPMENT ONLY:
     * Show the actual error so we can diagnose SMTP problems.
     *
     * Once everything works, we will remove the detailed
     * error before deployment.
     */
respond([
    'success' => false,
    'message' =>
        'The request could not be completed. Please try again.'
], 500);
}