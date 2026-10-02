<?php

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../config/database.php';

if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    $_SESSION['admin_role'] !== 'superadmin'
) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Superadmin access is required.',
    ]);
    exit;
}

$superadminId = (int)$_SESSION['admin_id'];

$POSITIONS = [
    'President',
    'Vice President',
    'Secretary',
    'Treasurer',
    'Auditor',
    'Board of Director',
];

$PHASES = [
    'Phase 1',
    'Phase 2',
    'Phase 3',
];

function officer_account_json(
    bool $success,
    string $message = '',
    array $extra = [],
    int $status = 200
): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message,
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function oa_column_exists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ");

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $count =
        (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

    $stmt->close();

    return $count > 0;
}

function oa_full_name(array $row): string
{
    return trim(
        (string)($row['first_name'] ?? '') . ' ' .
        (!empty($row['middle_name'])
            ? (string)$row['middle_name'] . ' '
            : '') .
        (string)($row['last_name'] ?? '')
    );
}

function oa_phase_code(string $phase): string
{
    return match ($phase) {
        'Phase 1' => 'p1',
        'Phase 2' => 'p2',
        'Phase 3' => 'p3',
        default => 'phase',
    };
}

function oa_position_slug(string $position): string
{
    $slug = strtolower($position);

    $slug = str_replace(
        [
            'vice president',
            'board of director',
        ],
        [
            'vicepresident',
            'board',
        ],
        $slug
    );

    $slug =
        preg_replace(
            '/[^a-z0-9]+/',
            '',
            $slug
        );

    return $slug !== ''
        ? $slug
        : 'officer';
}

function oa_suggest_login(
    string $phase,
    string $position,
    string $publicId,
    int $homeownerId
): string {
    $identity =
        strtolower(
            trim($publicId)
        );

    if ($identity === '') {
        $identity =
            'h' . $homeownerId;
    }

    $identity =
        preg_replace(
            '/[^a-z0-9]+/',
            '',
            $identity
        );

    return
        oa_phase_code($phase)
        . '.'
        . oa_position_slug($position)
        . '.'
        . $identity
        . '@officer.southmeridian.local';
}

function oa_temp_password(int $length = 14): string
{
    $upper =
        'ABCDEFGHJKLMNPQRSTUVWXYZ';

    $lower =
        'abcdefghijkmnopqrstuvwxyz';

    $digits =
        '23456789';

    $symbols =
        '!@#$%';

    $characters = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
    ];

    $pool =
        $upper .
        $lower .
        $digits .
        $symbols;

    while (
        count($characters) <
        max(12, $length)
    ) {
        $characters[] =
            $pool[
                random_int(
                    0,
                    strlen($pool) - 1
                )
            ];
    }

    for (
        $i = count($characters) - 1;
        $i > 0;
        $i--
    ) {
        $j =
            random_int(
                0,
                $i
            );

        [
            $characters[$i],
            $characters[$j],
        ] = [
            $characters[$j],
            $characters[$i],
        ];
    }

    return implode(
        '',
        $characters
    );
}

function oa_log(
    mysqli $conn,
    int $adminId,
    string $phase,
    string $action,
    string $details
): void {
    try {
        $tableResult =
            $conn->query(
                "SHOW TABLES LIKE 'activity_logs'"
            );

        $exists =
            $tableResult &&
            $tableResult->num_rows > 0;

        if ($tableResult) {
            $tableResult->free();
        }

        if (!$exists) {
            return;
        }

        $moduleKey =
            'user_management';

        $ip =
            $_SERVER['REMOTE_ADDR']
            ?? null;

        $stmt = $conn->prepare("
            INSERT INTO activity_logs
                (
                    admin_id,
                    phase,
                    action,
                    module_key,
                    details,
                    ip_address
                )
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'isssss',
            $adminId,
            $phase,
            $action,
            $moduleKey,
            $details,
            $ip
        );

        $stmt->execute();
        $stmt->close();

    } catch (Throwable $e) {
        error_log(
            'Officer account activity log failed: '
            . $e->getMessage()
        );
    }
}


function oa_base_url(array $secrets): string
{
    $configured =
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
        $configured !== '' &&
        filter_var(
            $configured,
            FILTER_VALIDATE_URL
        )
    ) {
        return $configured;
    }

    $https =
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off';

    $scheme =
        $https
            ? 'https'
            : 'http';

    $host =
        (string)(
            $_SERVER['HTTP_HOST']
            ?? 'localhost'
        );

    $scriptName =
        str_replace(
            '\\',
            '/',
            (string)(
                $_SERVER['SCRIPT_NAME']
                ?? '/superadmin/officer_account.php'
            )
        );

    $projectBase =
        preg_replace(
            '#/superadmin/[^/]+$#',
            '',
            $scriptName
        );

    return
        $scheme
        . '://'
        . $host
        . rtrim(
            (string)$projectBase,
            '/'
        );
}

function oa_create_password_setup_token(
    mysqli $conn,
    int $adminAccountId
): array {
    $rawToken =
        bin2hex(
            random_bytes(32)
        );

    $tokenHash =
        hash(
            'sha256',
            $rawToken
        );

    $stmt = $conn->prepare("
        UPDATE admins
        SET password_setup_token = ?,
            password_setup_expires = NULL,
            must_change_password = 1
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'si',
        $tokenHash,
        $adminAccountId
    );

    $stmt->execute();
    $stmt->close();

    return [
        'raw_token' => $rawToken,
    ];
}

function oa_send_credentials(
    string $recipientEmail,
    string $homeownerName,
    string $phase,
    string $position,
    string $loginEmail,
    string $temporaryPassword,
    string $passwordSetupUrl,
    bool $homeownerSetupRequired
): array {
    $secretsFile =
        __DIR__
        . '/../admin/private/hoa_secrets.php';

    if (!is_file($secretsFile)) {
        return [
            'sent' => false,
            'message' =>
                'SMTP configuration file was not found in admin/private/hoa_secrets.php. Copy the credentials below.',
        ];
    }

    $secrets =
        require $secretsFile;

    if (!is_array($secrets)) {
        return [
            'sent' => false,
            'message' =>
                'SMTP configuration file is invalid. Copy the credentials below.',
        ];
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

    $smtpHost =
        trim(
            (string)(
                $secrets['smtp_host']
                ?? 'smtp.gmail.com'
            )
        );

    $smtpPort =
        (int)(
            $secrets['smtp_port']
            ?? 587
        );

    $smtpEncryption =
        strtolower(
            trim(
                (string)(
                    $secrets['smtp_encryption']
                    ?? 'tls'
                )
            )
        );

    $smtpFromEmail =
        trim(
            (string)(
                $secrets['smtp_from_email']
                ?? $smtpUsername
            )
        );

    $smtpFromName =
        trim(
            (string)(
                $secrets['smtp_from_name']
                ?? 'South Meridian HOA'
            )
        );

    if (
        $smtpUsername === '' ||
        !filter_var(
            $smtpUsername,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return [
            'sent' => false,
            'message' =>
                'SMTP Gmail address is not configured correctly in hoa_secrets.php. Copy the credentials below.',
        ];
    }

    if ($smtpPassword === '') {
        return [
            'sent' => false,
            'message' =>
                'SMTP Gmail App Password is not configured in hoa_secrets.php. Copy the credentials below.',
        ];
    }

    if (
        $smtpFromEmail === '' ||
        !filter_var(
            $smtpFromEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        $smtpFromEmail =
            $smtpUsername;
    }

    if ($smtpFromName === '') {
        $smtpFromName =
            'South Meridian HOA';
    }

    $autoloadPath =
        __DIR__
        . '/../vendor/autoload.php';

    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
    }

    if (
        !class_exists(
            '\PHPMailer\PHPMailer\PHPMailer'
        )
    ) {
        return [
            'sent' => false,
            'message' =>
                'PHPMailer is unavailable. Copy the credentials below.',
        ];
    }

    try {
        $mail =
            new \PHPMailer\PHPMailer\PHPMailer(
                true
            );

        $mail->isSMTP();

        $mail->Host =
            $smtpHost;

        $mail->SMTPAuth =
            true;

        $mail->Username =
            $smtpUsername;

        $mail->Password =
            $smtpPassword;

        $mail->Port =
            $smtpPort;

        $encryption =
            $smtpEncryption;

        $mail->SMTPSecure =
            (
                $encryption === 'ssl'
                || $encryption === 'smtps'
            )
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $fromEmail =
            $smtpFromEmail;

        $fromName =
            $smtpFromName;

        if (
            $fromEmail === '' ||
            !filter_var(
                $fromEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                'Invalid sender email.'
            );
        }

        $mail->setFrom(
            $fromEmail,
            $fromName
        );

        $mail->addAddress(
            $recipientEmail,
            $homeownerName
        );

        $mail->isHTML(true);

        $safeName =
            htmlspecialchars(
                $homeownerName,
                ENT_QUOTES,
                'UTF-8'
            );

        $safePhase =
            htmlspecialchars(
                $phase,
                ENT_QUOTES,
                'UTF-8'
            );

        $safePosition =
            htmlspecialchars(
                $position,
                ENT_QUOTES,
                'UTF-8'
            );

        $safeLogin =
            htmlspecialchars(
                $loginEmail,
                ENT_QUOTES,
                'UTF-8'
            );

        $safePassword =
            htmlspecialchars(
                $temporaryPassword,
                ENT_QUOTES,
                'UTF-8'
            );

        $safeSetupUrl =
            htmlspecialchars(
                $passwordSetupUrl,
                ENT_QUOTES,
                'UTF-8'
            );

        $mail->Subject =
            $homeownerSetupRequired
                ? 'South Meridian HOA - Set Up Your Homeowner and Officer Accounts'
                : 'South Meridian HOA - Set Up Your Officer Account';

        $setupHeading =
            $homeownerSetupRequired
                ? 'Set Up Your Homeowner and Officer Accounts'
                : 'Set Up Your Officer Account';

        $setupExplanation =
            $homeownerSetupRequired
                ? 'Because this is your first account setup in the new South Meridian system, the secure button below will let you create both your homeowner password and your officer password.'
                : 'Your homeowner account is already set up. The secure button below will let you create or replace your officer password.';

        $homeownerAccountText =
            $homeownerSetupRequired
                ? 'Create your homeowner password using the setup button below.'
                : 'Already set up — keep using your current homeowner password.';

        $setupButtonLabel =
            $homeownerSetupRequired
                ? 'Set Up My Accounts'
                : 'Set My Officer Password';

        $mail->Body = "
            <div style='font-family:Arial,sans-serif;color:#1f2937;line-height:1.65;max-width:620px;margin:auto;'>
                <h2 style='color:#077f46;margin-bottom:8px;'>
                    {$setupHeading}
                </h2>

                <p>Hello <strong>{$safeName}</strong>,</p>

                <p>
                    You have been assigned as
                    <strong>{$safePosition}</strong>
                    for <strong>{$safePhase}</strong>.
                </p>

                <p>
                    {$setupExplanation}
                </p>

                <div style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;margin:18px 0;'>
                    <div style='margin-bottom:9px;'>
                        <strong>Login Email:</strong><br>
                        {$safeLogin}
                        <div style='font-size:12px;color:#64748b;margin-top:4px;'>
                            Same email as your homeowner account
                        </div>
                    </div>

                    <div style='margin-bottom:9px;'>
                        <strong>Homeowner Account:</strong><br>
                        {$homeownerAccountText}
                    </div>

                    <div>
                        <strong>Officer Temporary Password:</strong><br>
                        {$safePassword}
                    </div>
                </div>

                <p>
                    The officer password above is temporary.
                    Please use the secure setup button below before using the system.
                </p>

                <p style='margin:22px 0;'>
                    <a
                        href='{$safeSetupUrl}'
                        style='display:inline-block;background:#077f46;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:700;'
                    >
                        {$setupButtonLabel}
                    </a>
                </p>

                <p style='font-size:13px;color:#64748b;'>
                    This secure setup link does not expire. It can only be used while this setup request is still active.
                    If the button does not work, copy this link into your browser:
                    <br>
                    <span style='word-break:break-all;'>{$safeSetupUrl}</span>
                </p>

                <p>
                    After setup:
                    homeowner password → Homeowner Dashboard,
                    officer password → Admin Dashboard.
                    Both use the same login email.
                </p>

                <p style='margin-top:24px;'>
                    — South Meridian Homes HOA
                </p>
            </div>
        ";

        $mail->AltBody =
            "South Meridian HOA Account Setup\n\n"
            . "Name: {$homeownerName}\n"
            . "Phase: {$phase}\n"
            . "Position: {$position}\n"
            . "Login Email: {$loginEmail}\n"
            . ($homeownerSetupRequired
                ? "Homeowner Account: Create your homeowner password using the setup link below.\n"
                : "Homeowner Account: Already set up. Keep using your current homeowner password.\n")
            . "Officer Temporary Password: {$temporaryPassword}\n\n"
            . "Secure account setup link:\n{$passwordSetupUrl}\n\n"
            . "This setup link does not expire. It becomes invalid after the account setup is completed or a new setup link is issued.\n"
            . "Homeowner password -> Homeowner Dashboard.\n"
            . "Officer password -> Admin Dashboard.";

        $mail->send();

        return [
            'sent' => true,
            'message' =>
                'Officer credentials were emailed to the homeowner.',
        ];

    } catch (Throwable $e) {
        error_log(
            'Officer credential email failed: '
            . $e->getMessage()
        );

        return [
            'sent' => false,
            'message' =>
                'The account was created, but the email could not be delivered. Copy the credentials below.',
        ];
    }
}

if (
    !oa_column_exists(
        $conn,
        'admins',
        'homeowner_id'
    ) ||
    !oa_column_exists(
        $conn,
        'admins',
        'account_enabled'
    ) ||
    !oa_column_exists(
        $conn,
        'admins',
        'account_issued_at'
    ) ||
    !oa_column_exists(
        $conn,
        'admins',
        'account_issued_by_admin_id'
    ) ||
    !oa_column_exists(
        $conn,
        'admins',
        'must_change_password'
    ) ||
    !oa_column_exists(
        $conn,
        'admins',
        'password_setup_token'
    ) ||
    !oa_column_exists(
        $conn,
        'admins',
        'password_setup_expires'
    )
) {
    officer_account_json(
        false,
        'Officer account setup is incomplete. Run the officer assignment and officer account SQL setup files first.',
        [],
        500
    );
}

if (
    $_SERVER['REQUEST_METHOD'] !==
    'POST'
) {
    officer_account_json(
        false,
        'POST request required.',
        [],
        405
    );
}

$action =
    trim(
        (string)(
            $_POST['action']
            ?? ''
        )
    );

$phase =
    trim(
        (string)(
            $_POST['phase']
            ?? ''
        )
    );

$position =
    trim(
        (string)(
            $_POST['position']
            ?? ''
        )
    );

$homeownerId =
    (int)(
        $_POST['homeowner_id']
        ?? 0
    );

if (
    !in_array(
        $phase,
        $PHASES,
        true
    ) ||
    !in_array(
        $position,
        $POSITIONS,
        true
    ) ||
    $homeownerId <= 0
) {
    officer_account_json(
        false,
        'Invalid officer account request.',
        [],
        422
    );
}

$stmt = $conn->prepare("
    SELECT
        h.id,
        h.public_id,
        h.first_name,
        h.middle_name,
        h.last_name,
        h.email,
        h.phase,
        h.status,
        IFNULL(h.must_change_password, 1) AS homeowner_must_change_password,
        o.id AS officer_row_id,
        o.is_active
    FROM homeowners h
    INNER JOIN hoa_officers o
            ON o.homeowner_id = h.id
           AND o.phase = h.phase
           AND o.position = ?
    WHERE h.id = ?
      AND h.phase = ?
      AND h.status = 'approved'
    LIMIT 1
");

$stmt->bind_param(
    'sis',
    $position,
    $homeownerId,
    $phase
);

$stmt->execute();

$homeowner =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();

if (!$homeowner) {
    officer_account_json(
        false,
        'The officer/homeowner assignment could not be verified.',
        [],
        404
    );
}

$fullName =
    oa_full_name(
        $homeowner
    );

$personalEmail =
    strtolower(
        trim(
            (string)(
                $homeowner['email']
                ?? ''
            )
        )
    );

$homeownerSetupRequired =
    (int)(
        $homeowner['homeowner_must_change_password']
        ?? 1
    ) === 1;

$suggestedLogin =
    oa_suggest_login(
        $phase,
        $position,
        (string)(
            $homeowner['public_id']
            ?? ''
        ),
        $homeownerId
    );

$stmt = $conn->prepare("
    SELECT
        id,
        email,
        account_enabled,
        account_issued_at
    FROM admins
    WHERE homeowner_id = ?
      AND phase = ?
      AND role = 'admin'
      AND position = ?
    ORDER BY id ASC
    LIMIT 1
");

$stmt->bind_param(
    'iss',
    $homeownerId,
    $phase,
    $position
);

$stmt->execute();

$currentAdmin =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();

if ($action === 'status') {
    officer_account_json(
        true,
        '',
        [
            'account_enabled' =>
                (int)(
                    $currentAdmin['account_enabled']
                    ?? 0
                ) === 1,

            'login_email' =>
                $personalEmail,

            'issued_at' =>
                (string)(
                    $currentAdmin['account_issued_at']
                    ?? ''
                ),
        ]
    );
}

if ($action !== 'issue') {
    officer_account_json(
        false,
        'Unknown officer-account action.',
        [],
        400
    );
}

if ((int)$homeowner['is_active'] !== 1) {
    officer_account_json(
        false,
        'Activate this officer before issuing an officer account.',
        [],
        409
    );
}

$loginEmail =
    $personalEmail;

$sendEmail =
    (int)(
        $_POST['send_email']
        ?? 0
    ) === 1;

if (
    $loginEmail === '' ||
    !filter_var(
        $loginEmail,
        FILTER_VALIDATE_EMAIL
    )
) {
    officer_account_json(
        false,
        'This homeowner does not have a valid email address.',
        [],
        422
    );
}

/*
 * The homeowner row and the linked officer/admin row intentionally use
 * the same email. index.php compares the submitted password against both
 * records and routes to the matching account.
 */

$adminAccountId =
    (int)(
        $currentAdmin['id']
        ?? 0
    );

if ($adminAccountId <= 0) {
    if ($position === 'Board of Director') {
        $stmt = $conn->prepare("
            SELECT id
            FROM admins
            WHERE phase = ?
              AND role = 'admin'
              AND position = 'Board of Director'
              AND (homeowner_id IS NULL OR homeowner_id = 0)
            ORDER BY id ASC
            LIMIT 1
        ");

        $stmt->bind_param(
            's',
            $phase
        );

    } else {
        $stmt = $conn->prepare("
            SELECT id
            FROM admins
            WHERE phase = ?
              AND role = 'admin'
              AND position = ?
            ORDER BY id ASC
            LIMIT 1
        ");

        $stmt->bind_param(
            'ss',
            $phase,
            $position
        );
    }

    $stmt->execute();

    $available =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if ($available) {
        $adminAccountId =
            (int)$available['id'];
    }
}

if ($adminAccountId > 0) {
    $stmt = $conn->prepare("
        SELECT id
        FROM admins
        WHERE email = ?
          AND id <> ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'si',
        $loginEmail,
        $adminAccountId
    );

} else {
    $stmt = $conn->prepare("
        SELECT id
        FROM admins
        WHERE email = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        's',
        $loginEmail
    );
}

$stmt->execute();

$emailConflict =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();

if ($emailConflict) {
    officer_account_json(
        false,
        'That officer login email is already used by another administrator account.',
        [],
        409
    );
}

$temporaryPassword =
    oa_temp_password();

$stmt = $conn->prepare("
    SELECT password
    FROM homeowners
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param('i', $homeownerId);
$stmt->execute();

$homeownerPasswordHash =
    (string)(
        $stmt
            ->get_result()
            ->fetch_assoc()['password']
        ?? ''
    );

$stmt->close();

$attempts = 0;

while (
    $homeownerPasswordHash !== '' &&
    password_verify(
        $temporaryPassword,
        $homeownerPasswordHash
    ) &&
    $attempts < 5
) {
    $temporaryPassword =
        oa_temp_password();

    $attempts++;
}

$passwordHash =
    password_hash(
        $temporaryPassword,
        PASSWORD_DEFAULT
    );

$conn->begin_transaction();

try {
    if ($adminAccountId > 0) {
        $stmt = $conn->prepare("
            UPDATE admins
            SET homeowner_id = ?,
                email = ?,
                full_name = ?,
                password = ?,
                phase = ?,
                role = 'admin',
                position = ?,
                account_enabled = 1,
                account_issued_at = NOW(),
                account_issued_by_admin_id = ?
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'isssssii',
            $homeownerId,
            $loginEmail,
            $fullName,
            $passwordHash,
            $phase,
            $position,
            $superadminId,
            $adminAccountId
        );

        $stmt->execute();
        $stmt->close();

    } else {
        $stmt = $conn->prepare("
            INSERT INTO admins
            (
                homeowner_id,
                email,
                full_name,
                password,
                phase,
                role,
                position,
                account_enabled,
                account_issued_at,
                account_issued_by_admin_id
            )
            VALUES (
                ?, ?, ?, ?, ?,
                'admin',
                ?,
                1,
                NOW(),
                ?
            )
        ");

        $stmt->bind_param(
            'isssssi',
            $homeownerId,
            $loginEmail,
            $fullName,
            $passwordHash,
            $phase,
            $position,
            $superadminId
        );

        $stmt->execute();

        $adminAccountId =
            (int)$conn->insert_id;

        $stmt->close();
    }

    $passwordSetup =
        oa_create_password_setup_token(
            $conn,
            $adminAccountId
        );

    oa_log(
        $conn,
        $superadminId,
        $phase,
        'Officer account issued',
        'homeowner_id='
        . $homeownerId
        . '; admin_id='
        . $adminAccountId
        . '; position='
        . $position
        . '; shared_login_email='
        . $loginEmail
    );

    $conn->commit();

} catch (Throwable $e) {
    $conn->rollback();

    error_log(
        'Officer account provisioning failed: '
        . $e->getMessage()
    );

    officer_account_json(
        false,
        'The officer account could not be created. Check the database setup.',
        [],
        500
    );
}

$mailResult = [
    'sent' => false,
    'message' =>
        'Credentials were not emailed. Copy them below and give them securely to the officer.',
];

$secretsForUrl = [];

$secretsPathForUrl =
    __DIR__
    . '/../admin/private/hoa_secrets.php';

if (is_file($secretsPathForUrl)) {
    $loadedSecrets =
        require $secretsPathForUrl;

    if (is_array($loadedSecrets)) {
        $secretsForUrl =
            $loadedSecrets;
    }
}

$passwordSetupUrl =
    oa_base_url(
        $secretsForUrl
    )
    . '/superadmin/officer_set_password.php?token='
    . urlencode(
        (string)$passwordSetup['raw_token']
    );

if (
    $sendEmail &&
    $personalEmail !== '' &&
    filter_var(
        $personalEmail,
        FILTER_VALIDATE_EMAIL
    )
) {
    $mailResult =
        oa_send_credentials(
            $personalEmail,
            $fullName,
            $phase,
            $position,
            $loginEmail,
            $temporaryPassword,
            $passwordSetupUrl,
            $homeownerSetupRequired
        );
}

officer_account_json(
    true,
    'Officer account created successfully.',
    [
        'account' => [
            'admin_id' =>
                $adminAccountId,

            'login_email' =>
                $loginEmail,

            'temporary_password' =>
                $temporaryPassword,

            'recipient_email' =>
                $personalEmail,

            'mail_sent' =>
                (bool)$mailResult['sent'],

            'mail_message' =>
                (string)$mailResult['message'],

            'password_setup_url' =>
                $passwordSetupUrl,

            'homeowner_setup_required' =>
                $homeownerSetupRequired,
        ],
    ]
);
