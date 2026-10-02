<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This reminder runner must be executed from PHP CLI.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "PHPMailer is unavailable. Composer autoload was not found.\n");
    exit(1);
}

require_once $autoload;

if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    fwrite(STDERR, "PHPMailer is not installed in the vendor folder.\n");
    exit(1);
}

$secretsFile = __DIR__ . '/private/hoa_secrets.php';

if (!is_file($secretsFile)) {
    fwrite(STDERR, "SMTP configuration file was not found.\n");
    exit(1);
}

$secrets = require $secretsFile;

if (!is_array($secrets)) {
    fwrite(STDERR, "SMTP configuration file is invalid.\n");
    exit(1);
}

$smtpUsername = trim((string)($secrets['smtp_username'] ?? ''));
$smtpPassword = preg_replace(
    '/\s+/',
    '',
    trim((string)($secrets['smtp_password'] ?? ''))
);
$appBaseUrl = rtrim(
    trim((string)($secrets['app_base_url'] ?? '')),
    '/'
);

if (
    $smtpUsername === '' ||
    !filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)
) {
    fwrite(STDERR, "SMTP Gmail address is not configured correctly.\n");
    exit(1);
}

if ($smtpPassword === '') {
    fwrite(STDERR, "SMTP Gmail App Password is not configured.\n");
    exit(1);
}

if (
    $appBaseUrl === '' ||
    !filter_var($appBaseUrl, FILTER_VALIDATE_URL)
) {
    fwrite(STDERR, "app_base_url is not configured correctly.\n");
    exit(1);
}

$tableCheck = $conn->query("
    SHOW TABLES LIKE 'finance_dues_reminders'
");

if ($tableCheck->num_rows === 0) {
    fwrite(
        STDERR,
        "finance_dues_reminders table is missing. Import create_finance_dues_reminders.sql first.\n"
    );
    exit(1);
}

$target = new DateTimeImmutable('first day of this month 00:00:00');
$target = $target->modify('-1 month');

$targetYear = (int)$target->format('Y');
$targetMonth = (int)$target->format('n');
$targetMonthLabel = $target->format('F Y');

$stmt = $conn->prepare("
    SELECT
        h.id,
        h.first_name,
        h.middle_name,
        h.last_name,
        h.email,
        h.phase,
        h.house_lot_number,
        h.created_at,
        COALESCE(fds.monthly_dues, 0) AS monthly_dues
    FROM homeowners h
    LEFT JOIN finance_dues_settings fds
        ON fds.phase = h.phase
    WHERE h.status = 'approved'
      AND h.email IS NOT NULL
      AND h.email <> ''
    ORDER BY h.id ASC
");
$stmt->execute();
$homeowners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sent = 0;
$skippedPaid = 0;
$skippedNotApplicable = 0;
$skippedAlreadySent = 0;
$skippedInvalidEmail = 0;
$failed = 0;

foreach ($homeowners as $homeowner) {
    $homeownerId = (int)$homeowner['id'];
    $email = strtolower(trim((string)$homeowner['email']));
    $monthlyDues = (float)$homeowner['monthly_dues'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $skippedInvalidEmail++;
        continue;
    }

    if ($monthlyDues <= 0) {
        $skippedNotApplicable++;
        continue;
    }

    $createdAt = strtotime((string)($homeowner['created_at'] ?? ''));

    if (!$createdAt) {
        $skippedNotApplicable++;
        continue;
    }

    $startYear = (int)date('Y', $createdAt);
    $startMonth = (int)date('n', $createdAt);

    if (
        $targetYear < $startYear ||
        ($targetYear === $startYear && $targetMonth < $startMonth)
    ) {
        $skippedNotApplicable++;
        continue;
    }

    $paidStmt = $conn->prepare("
        SELECT id
        FROM finance_payments
        WHERE homeowner_id = ?
          AND pay_year = ?
          AND pay_month = ?
          AND status = 'paid'
        LIMIT 1
    ");
    $paidStmt->bind_param(
        'iii',
        $homeownerId,
        $targetYear,
        $targetMonth
    );
    $paidStmt->execute();
    $paid = $paidStmt->get_result()->fetch_assoc();
    $paidStmt->close();

    if ($paid) {
        $skippedPaid++;
        continue;
    }

    $reminderStmt = $conn->prepare("
        SELECT
            id,
            status,
            attempts
        FROM finance_dues_reminders
        WHERE homeowner_id = ?
          AND pay_year = ?
          AND pay_month = ?
        LIMIT 1
    ");
    $reminderStmt->bind_param(
        'iii',
        $homeownerId,
        $targetYear,
        $targetMonth
    );
    $reminderStmt->execute();
    $reminder = $reminderStmt->get_result()->fetch_assoc();
    $reminderStmt->close();

    if (
        $reminder &&
        (string)$reminder['status'] === 'sent'
    ) {
        $skippedAlreadySent++;
        continue;
    }

    $fullName = trim(
        (string)($homeowner['first_name'] ?? '') . ' ' .
        (string)($homeowner['middle_name'] ?? '') . ' ' .
        (string)($homeowner['last_name'] ?? '')
    );
    $fullName = preg_replace('/\s+/', ' ', $fullName) ?: 'Homeowner';

    $safeName = htmlspecialchars(
        $fullName,
        ENT_QUOTES,
        'UTF-8'
    );
    $safeMonth = htmlspecialchars(
        $targetMonthLabel,
        ENT_QUOTES,
        'UTF-8'
    );
    $safePhase = htmlspecialchars(
        (string)($homeowner['phase'] ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
    $safeHouseLot = htmlspecialchars(
        (string)($homeowner['house_lot_number'] ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
    $safeAmount = number_format(
        $monthlyDues,
        2
    );

    $paymentUrl =
        $appBaseUrl .
        '/homeowner/homeowner_pay_dues.php?year=' .
        $targetYear;

    $safePaymentUrl = htmlspecialchars(
        $paymentUrl,
        ENT_QUOTES,
        'UTF-8'
    );

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure =
            \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
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
        $mail->Subject =
            "South Meridian HOA Monthly Dues Reminder - {$targetMonthLabel}";

        $mail->Body = "
            <div style=\"font-family:Arial,sans-serif;color:#222;line-height:1.6;\">
                <h2 style=\"color:#077f46;margin-bottom:8px;\">South Meridian HOA</h2>

                <p>Hello <strong>{$safeName}</strong>,</p>

                <p>
                    Our records show that your HOA monthly dues for
                    <strong>{$safeMonth}</strong> have not yet been recorded as paid.
                </p>

                <div style=\"background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin:16px 0;\">
                    <div><strong>Phase:</strong> {$safePhase}</div>
                    <div><strong>Property:</strong> {$safeHouseLot}</div>
                    <div><strong>Month:</strong> {$safeMonth}</div>
                    <div><strong>Amount:</strong> ₱{$safeAmount}</div>
                </div>

                <p>
                    Please settle the unpaid monthly dues through the South Meridian
                    homeowner portal.
                </p>

                <p style=\"margin:22px 0;\">
                    <a
                        href=\"{$safePaymentUrl}\"
                        style=\"display:inline-block;background:#077f46;color:#fff;padding:11px 16px;text-decoration:none;border-radius:8px;font-weight:bold;\"
                    >
                        View Monthly Dues
                    </a>
                </p>

                <p>
                    If you have already paid and the payment is still being processed,
                    please wait for the payment status to update or contact the HOA office.
                </p>

                <p>
                    Regards,<br>
                    <strong>South Meridian HOA</strong>
                </p>
            </div>
        ";

        $mail->AltBody =
            "Hello {$fullName},\n\n" .
            "Our records show that your South Meridian HOA monthly dues for {$targetMonthLabel} " .
            "have not yet been recorded as paid.\n\n" .
            "Amount: PHP " . number_format($monthlyDues, 2) . "\n" .
            "Phase: " . (string)($homeowner['phase'] ?? '') . "\n" .
            "Property: " . (string)($homeowner['house_lot_number'] ?? '') . "\n\n" .
            "View your monthly dues here:\n{$paymentUrl}\n\n" .
            "South Meridian HOA";

        $mail->send();

        if ($reminder) {
            $updateStmt = $conn->prepare("
                UPDATE finance_dues_reminders
                SET
                    email = ?,
                    amount = ?,
                    status = 'sent',
                    attempts = attempts + 1,
                    last_error = NULL,
                    sent_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
                LIMIT 1
            ");
            $reminderId = (int)$reminder['id'];
            $updateStmt->bind_param(
                'sdi',
                $email,
                $monthlyDues,
                $reminderId
            );
            $updateStmt->execute();
            $updateStmt->close();
        } else {
            $insertStmt = $conn->prepare("
                INSERT INTO finance_dues_reminders
                (
                    homeowner_id,
                    email,
                    pay_year,
                    pay_month,
                    amount,
                    status,
                    attempts,
                    last_error,
                    sent_at,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    ?, ?, ?, ?, ?,
                    'sent',
                    1,
                    NULL,
                    NOW(),
                    NOW(),
                    NOW()
                )
            ");
            $insertStmt->bind_param(
                'isiid',
                $homeownerId,
                $email,
                $targetYear,
                $targetMonth,
                $monthlyDues
            );
            $insertStmt->execute();
            $insertStmt->close();
        }

        $sent++;
    } catch (Throwable $e) {
        $failed++;
        $errorMessage = mb_substr(
            $e->getMessage(),
            0,
            1000
        );

        error_log(
            "Monthly dues reminder failed for homeowner {$homeownerId}: {$errorMessage}"
        );

        if ($reminder) {
            $updateStmt = $conn->prepare("
                UPDATE finance_dues_reminders
                SET
                    email = ?,
                    amount = ?,
                    status = 'failed',
                    attempts = attempts + 1,
                    last_error = ?,
                    updated_at = NOW()
                WHERE id = ?
                LIMIT 1
            ");
            $reminderId = (int)$reminder['id'];
            $updateStmt->bind_param(
                'sdsi',
                $email,
                $monthlyDues,
                $errorMessage,
                $reminderId
            );
            $updateStmt->execute();
            $updateStmt->close();
        } else {
            $insertStmt = $conn->prepare("
                INSERT INTO finance_dues_reminders
                (
                    homeowner_id,
                    email,
                    pay_year,
                    pay_month,
                    amount,
                    status,
                    attempts,
                    last_error,
                    sent_at,
                    created_at,
                    updated_at
                )
                VALUES
                (
                    ?, ?, ?, ?, ?,
                    'failed',
                    1,
                    ?,
                    NULL,
                    NOW(),
                    NOW()
                )
            ");
            $insertStmt->bind_param(
                'isiids',
                $homeownerId,
                $email,
                $targetYear,
                $targetMonth,
                $monthlyDues,
                $errorMessage
            );
            $insertStmt->execute();
            $insertStmt->close();
        }
    }
}

echo "South Meridian HOA monthly dues reminder run\n";
echo "Target month: {$targetMonthLabel}\n";
echo "Emails sent: {$sent}\n";
echo "Already paid: {$skippedPaid}\n";
echo "Already reminded: {$skippedAlreadySent}\n";
echo "Not applicable/no dues: {$skippedNotApplicable}\n";
echo "Invalid email: {$skippedInvalidEmail}\n";
echo "Failed: {$failed}\n";
