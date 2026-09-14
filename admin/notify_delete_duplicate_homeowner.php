<?php

ob_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once 'admin_access.php';
require_once '../config/database.php';

requireAccess('homeowner_management');

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


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


try {

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $csrf =
        trim(
            (string)(
                $_POST['csrf'] ?? ''
            )
        );

    $sessionCsrf =
        trim(
            (string)(
                $_SESSION['homeowner_import_csrf']
                ?? ''
            )
        );


    if (
        $csrf === '' ||
        $sessionCsrf === '' ||
        !hash_equals(
            $sessionCsrf,
            $csrf
        )
    ) {
        respond([
            'success' => false,
            'message' =>
                'Security token expired. Reload the page.'
        ], 403);
    }


    /*
    |--------------------------------------------------------------------------
    | Queue ID
    |--------------------------------------------------------------------------
    */

    $queueId =
        (int)(
            $_POST['id'] ?? 0
        );

    if ($queueId <= 0) {
        respond([
            'success' => false,
            'message' =>
                'Invalid duplicate queue ID.'
        ], 400);
    }


    /*
    |--------------------------------------------------------------------------
    | Current Admin
    |--------------------------------------------------------------------------
    */

    $adminId =
        (int)$_SESSION['admin_id'];

    $stmt =
        $conn->prepare(
            "SELECT
                phase,
                role
             FROM admins
             WHERE id=?
             LIMIT 1"
        );

    $stmt->bind_param(
        'i',
        $adminId
    );

    $stmt->execute();

    $admin =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();


    if (!$admin) {
        respond([
            'success' => false,
            'message' =>
                'Admin account was not found.'
        ], 403);
    }


    /*
    |--------------------------------------------------------------------------
    | Duplicate Record
    |--------------------------------------------------------------------------
    */

    if ($admin['role'] === 'superadmin') {

        $stmt =
            $conn->prepare(
                "SELECT
                    q.*,
                    h.public_id AS existing_public_id

                 FROM homeowner_import_queue q

                 INNER JOIN homeowners h
                    ON h.id =
                       q.duplicate_homeowner_id

                 WHERE q.id=?
                   AND q.status='duplicate'

                 LIMIT 1"
            );

        $stmt->bind_param(
            'i',
            $queueId
        );

    } else {

        $stmt =
            $conn->prepare(
                "SELECT
                    q.*,
                    h.public_id AS existing_public_id

                 FROM homeowner_import_queue q

                 INNER JOIN homeowners h
                    ON h.id =
                       q.duplicate_homeowner_id

                 WHERE q.id=?
                   AND q.status='duplicate'
                   AND q.phase=?

                 LIMIT 1"
            );

        $stmt->bind_param(
            'is',
            $queueId,
            $admin['phase']
        );
    }


    $stmt->execute();

    $duplicate =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();


    if (!$duplicate) {
        respond([
            'success' => false,
            'message' =>
                'Duplicate record was not found.'
        ], 404);
    }


    /*
    |--------------------------------------------------------------------------
    | Recipient
    |--------------------------------------------------------------------------
    */

    $email =
        strtolower(
            trim(
                (string)(
                    $duplicate['email']
                    ?? ''
                )
            )
        );


    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        respond([
            'success' => false,
            'message' =>
                'The duplicate record has an invalid email address.'
        ], 422);
    }


    $firstName =
        trim(
            (string)(
                $duplicate['first_name']
                ?? ''
            )
        );

    $lastName =
        trim(
            (string)(
                $duplicate['last_name']
                ?? ''
            )
        );


    /*
    |--------------------------------------------------------------------------
    | SMTP Credentials
    |--------------------------------------------------------------------------
    |
    | USE A NEW Gmail App Password here.
    |--------------------------------------------------------------------------
    */

    $smtpUsername =
        'baculpopatrick2440@gmail.com';

    $smtpPassword =
        'vxsx lmtv livx hgtl';


    /*
    |--------------------------------------------------------------------------
    | Send Email
    |--------------------------------------------------------------------------
    */

    try {

        $mail =
            new PHPMailer(true);


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


        /*
         * Do NOT enable SMTPDebug here.
         * Debug output can corrupt JSON.
         */

        $mail->SMTPDebug =
            0;


        $mail->CharSet =
            'UTF-8';

        $mail->Timeout =
            30;


        /*
        |--------------------------------------------------------------------------
        | Sender / Recipient
        |--------------------------------------------------------------------------
        */

        $mail->setFrom(
            $smtpUsername,
            'South Meridian HOA'
        );

        $mail->addAddress(
            $email,
            trim(
                $firstName .
                ' ' .
                $lastName
            )
        );


        /*
        |--------------------------------------------------------------------------
        | Message
        |--------------------------------------------------------------------------
        */

        $safeFirst =
            htmlspecialchars(
                $firstName,
                ENT_QUOTES,
                'UTF-8'
            );

        $safeLast =
            htmlspecialchars(
                $lastName,
                ENT_QUOTES,
                'UTF-8'
            );

        $safeEmail =
            htmlspecialchars(
                $email,
                ENT_QUOTES,
                'UTF-8'
            );


        $mail->isHTML(true);


        $mail->Subject =
            'South Meridian HOA Registration - Email Already Registered';


        $mail->Body = "
            <div style=\"
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #222;
            \">

                <h2 style=\"color:#077f46;\">
                    South Meridian HOA
                </h2>

                <p>
                    Hello
                    <strong>
                        {$safeFirst} {$safeLast}
                    </strong>,
                </p>

                <p>
                    We were unable to continue with
                    your homeowner registration.
                </p>

                <p>
                    The email address
                    <strong>{$safeEmail}</strong>
                    is already registered to an
                    existing homeowner account.
                </p>

                <p>
                    Duplicate homeowner accounts
                    using the same email address
                    cannot be approved.
                </p>

                <p>
                    If you believe this is an error
                    or need assistance accessing
                    your existing account, please
                    contact the South Meridian HOA
                    office.
                </p>

                <br>

                <p>
                    Regards,<br>
                    <strong>
                        South Meridian HOA
                    </strong>
                </p>

            </div>
        ";


        $mail->AltBody =
            "Hello {$firstName} {$lastName},

Your homeowner registration could not continue because {$email} is already registered to an existing homeowner account.

Duplicate homeowner accounts using the same email address cannot be approved.

If you believe this is an error, please contact the South Meridian HOA office.

South Meridian HOA";


        /*
        |--------------------------------------------------------------------------
        | Send
        |--------------------------------------------------------------------------
        */

        $mail->send();


    } catch (Throwable $mailError) {

        error_log(
            'PHPMailer error: ' .
            $mailError->getMessage()
        );


        /*
         * IMPORTANT:
         * Keep duplicate record if email failed.
         */

        respond([
            'success' => false,

            'email_sent' => false,

            'message' =>
                'Email could not be sent. The duplicate record was NOT deleted.',

            'email_error' =>
                $mailError->getMessage()
        ], 500);
    }


    /*
    |--------------------------------------------------------------------------
    | Email successfully sent
    |--------------------------------------------------------------------------
    |
    | Only NOW delete duplicate.
    |--------------------------------------------------------------------------
    */

    $stmt =
        $conn->prepare(
            "DELETE FROM homeowner_import_queue
             WHERE id=?
               AND status='duplicate'"
        );

    $stmt->bind_param(
        'i',
        $queueId
    );

    $stmt->execute();

    $deleted =
        $stmt->affected_rows;

    $stmt->close();


    if ($deleted !== 1) {

        respond([
            'success' => false,

            'email_sent' => true,

            'message' =>
                'The email was sent, but the duplicate record could not be deleted.'
        ], 500);
    }


    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */

    respond([
        'success' => true,

        'email_sent' => true,

        'message' =>
            "Email was successfully sent to {$email}. The duplicate import was removed."
    ]);


} catch (Throwable $e) {

    error_log(
        'notify_delete_duplicate_homeowner.php: ' .
        $e->getMessage()
    );


    respond([
        'success' => false,

        'message' =>
            'Server error: ' .
            $e->getMessage()
    ], 500);
}