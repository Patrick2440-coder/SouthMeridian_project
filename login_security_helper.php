<?php

if (!function_exists('security_client_ip')) {
    function security_client_ip(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }
}

if (!function_exists('security_user_agent')) {
    function security_user_agent(): string
    {
        return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }
}

if (!function_exists('security_base_url')) {
    function security_base_url(): string
    {
        $scheme =
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                ? 'https'
                : 'http';

        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

        $scriptName =
            str_replace(
                '\\',
                '/',
                (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')
            );

        $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($dir === '.' || $dir === '/') {
            $dir = '';
        }

        return $scheme . '://' . $host . $dir;
    }
}

if (!function_exists('security_load_smtp_config')) {
    function security_load_smtp_config(): array
    {
        $username = trim((string)getenv('HOA_SMTP_USERNAME'));
        $password = preg_replace(
            '/\s+/',
            '',
            trim((string)getenv('HOA_SMTP_PASSWORD'))
        );

        if ($username !== '' && $password !== '') {
            return [
                'username' => $username,
                'password' => $password
            ];
        }

        $possibleFiles = [
            __DIR__ . '/private/hoa_secrets.php',
            __DIR__ . '/admin/private/hoa_secrets.php'
        ];

        foreach ($possibleFiles as $file) {
            if (!is_file($file)) {
                continue;
            }

            $secrets = require $file;

            if (!is_array($secrets)) {
                continue;
            }

            $username = trim(
                (string)($secrets['smtp_username'] ?? '')
            );

            $password = preg_replace(
                '/\s+/',
                '',
                trim(
                    (string)($secrets['smtp_password'] ?? '')
                )
            );

            if ($username !== '' && $password !== '') {
                return [
                    'username' => $username,
                    'password' => $password
                ];
            }
        }

        return [
            'username' => '',
            'password' => ''
        ];
    }
}

if (!function_exists('security_send_email')) {
    function security_send_email(
        string $to,
        string $recipientName,
        string $subject,
        string $htmlBody,
        string $textBody = ''
    ): bool {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $autoload = __DIR__ . '/vendor/autoload.php';

        if (!is_file($autoload)) {
            error_log(
                'Login security email not sent: vendor/autoload.php not found.'
            );
            return false;
        }

        require_once $autoload;

        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            error_log(
                'Login security email not sent: PHPMailer is not installed.'
            );
            return false;
        }

        $smtp = security_load_smtp_config();

        if (
            $smtp['username'] === '' ||
            $smtp['password'] === '' ||
            !filter_var($smtp['username'], FILTER_VALIDATE_EMAIL)
        ) {
            error_log(
                'Login security email not sent: SMTP credentials are not configured.'
            );
            return false;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $smtp['username'];
            $mail->Password = $smtp['password'];
            $mail->SMTPSecure =
                \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 30;
            $mail->SMTPDebug = 0;

            $mail->setFrom(
                $smtp['username'],
                'South Meridian HOA'
            );

            $mail->addAddress(
                $to,
                $recipientName !== ''
                    ? $recipientName
                    : $to
            );

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody =
                $textBody !== ''
                    ? $textBody
                    : strip_tags($htmlBody);

            $mail->send();

            return true;

        } catch (\Throwable $e) {
            error_log(
                'Login security email error: ' .
                $e->getMessage()
            );

            return false;
        }
    }
}

if (!function_exists('security_upsert_state')) {
    function security_upsert_state(
        mysqli $conn,
        array $account
    ): int {
        $type = (string)$account['type'];
        $id = (int)$account['id'];
        $email = trim((string)$account['email']);
        $phase = trim((string)($account['phase'] ?? ''));

        $stmt = $conn->prepare("
            INSERT INTO login_security_state
            (
                account_type,
                account_id,
                email,
                phase
            )
            VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                phase = VALUES(phase)
        ");

        $stmt->bind_param(
            'siss',
            $type,
            $id,
            $email,
            $phase
        );

        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT id
            FROM login_security_state
            WHERE account_type = ?
              AND account_id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'si',
            $type,
            $id
        );

        $stmt->execute();

        $row =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        return (int)($row['id'] ?? 0);
    }
}

if (!function_exists('security_get_state')) {
    function security_get_state(
        mysqli $conn,
        int $stateId,
        bool $forUpdate = false
    ): ?array {
        $sql = "
            SELECT *
            FROM login_security_state
            WHERE id = ?
            LIMIT 1
        ";

        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $stateId);
        $stmt->execute();

        $row =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('security_reset_after_success')) {
    function security_reset_after_success(
        mysqli $conn,
        array $account
    ): void {
        $stateId =
            security_upsert_state(
                $conn,
                $account
            );

        if ($stateId <= 0) {
            return;
        }

        $stmt = $conn->prepare("
            UPDATE login_security_state
            SET
                failed_attempts = 0,
                cooldown_stage = 0,
                cooldown_until = NULL,
                last_failed_at = NULL,
                last_failed_ip = NULL,
                last_user_agent = NULL
            WHERE id = ?
              AND hard_locked = 0
        ");

        $stmt->bind_param(
            'i',
            $stateId
        );

        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('security_current_block')) {
    function security_current_block(
        mysqli $conn,
        array $account
    ): array {
        $stateId =
            security_upsert_state(
                $conn,
                $account
            );

        $state =
            security_get_state(
                $conn,
                $stateId
            );

        if (!$state) {
            return [
                'blocked' => false
            ];
        }

        if ((int)$state['hard_locked'] === 1) {
            return [
                'blocked' => true,
                'code' => 'hard_locked',
                'message' =>
                    'This account is locked for security. Check your email for the appeal instructions. An administrator must unlock the account before you can sign in again.'
            ];
        }

        $until =
            !empty($state['cooldown_until'])
                ? strtotime(
                    (string)$state['cooldown_until']
                )
                : false;

        if ($until !== false && $until > time()) {
            $seconds =
                max(
                    1,
                    $until - time()
                );

            return [
                'blocked' => true,
                'code' => 'cooldown',
                'seconds' => $seconds,
                'message' =>
                    'Too many incorrect passwords. Please wait ' .
                    $seconds .
                    ' second' .
                    ($seconds === 1 ? '' : 's') .
                    ' before trying again.'
            ];
        }

        if (
            !empty($state['cooldown_until']) &&
            $until !== false &&
            $until <= time()
        ) {
            $stmt = $conn->prepare("
                UPDATE login_security_state
                SET cooldown_until = NULL
                WHERE id = ?
                  AND hard_locked = 0
            ");

            $stmt->bind_param(
                'i',
                $stateId
            );

            $stmt->execute();
            $stmt->close();
        }

        return [
            'blocked' => false
        ];
    }
}

if (!function_exists('security_create_appeal')) {
    function security_create_appeal(
        mysqli $conn,
        int $stateId
    ): array {
        $token =
            bin2hex(
                random_bytes(32)
            );

        $tokenHash =
            hash(
                'sha256',
                $token
            );

        $stmt = $conn->prepare("
            INSERT INTO login_security_appeals
            (
                security_state_id,
                token_hash,
                status
            )
            VALUES (?,?,'available')
        ");

        $stmt->bind_param(
            'is',
            $stateId,
            $tokenHash
        );

        $stmt->execute();

        $appealId =
            (int)$conn->insert_id;

        $stmt->close();

        return [
            'id' => $appealId,
            'token' => $token
        ];
    }
}

if (!function_exists('security_send_lock_email')) {
    function security_send_lock_email(
        array $account,
        string $appealUrl
    ): bool {
        $safeName =
            htmlspecialchars(
                (string)($account['name'] ?? 'Member'),
                ENT_QUOTES,
                'UTF-8'
            );

        $safeUrl =
            htmlspecialchars(
                $appealUrl,
                ENT_QUOTES,
                'UTF-8'
            );

        $html = '
        <div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#1f2937;">
          <div style="background:#077f46;color:#fff;padding:20px 24px;border-radius:14px 14px 0 0;">
            <h2 style="margin:0;font-size:21px;">South Meridian Homes</h2>
          </div>
          <div style="border:1px solid #e5e7eb;border-top:0;padding:24px;border-radius:0 0 14px 14px;">
            <p>Hello <strong>' . $safeName . '</strong>,</p>
            <p>
              Your account was temporarily locked after repeated incorrect password attempts.
              This is a security measure to protect your account.
            </p>
            <p>
              The account will remain locked until an authorized administrator reviews and unlocks it.
            </p>
            <div style="margin:26px 0;text-align:center;">
              <a href="' . $safeUrl . '"
                 style="display:inline-block;background:#077f46;color:#fff;text-decoration:none;padding:12px 22px;border-radius:9px;font-weight:700;">
                Submit Unlock Appeal
              </a>
            </div>
            <p style="font-size:13px;color:#6b7280;">
              If you did not attempt to sign in, submit the appeal and inform your HOA administrator.
            </p>
          </div>
        </div>';

        $text =
            "South Meridian Homes\n\n" .
            "Your account was locked after repeated incorrect password attempts.\n" .
            "An administrator must unlock it.\n\n" .
            "Submit your appeal here:\n" .
            $appealUrl;

        return security_send_email(
            (string)$account['email'],
            (string)($account['name'] ?? ''),
            'Security Alert: Your South Meridian account was locked',
            $html,
            $text
        );
    }
}

if (!function_exists('security_register_failure')) {
    function security_register_failure(
        mysqli $conn,
        array $account
    ): array {
        $stateId =
            security_upsert_state(
                $conn,
                $account
            );

        if ($stateId <= 0) {
            return [
                'code' => 'error',
                'message' =>
                    'Unable to update login security state.'
            ];
        }

        $ip = security_client_ip();
        $ua = security_user_agent();

        $conn->begin_transaction();

        try {
            $state =
                security_get_state(
                    $conn,
                    $stateId,
                    true
                );

            if (!$state) {
                throw new RuntimeException(
                    'Login security record not found.'
                );
            }

            if ((int)$state['hard_locked'] === 1) {
                $conn->commit();

                return [
                    'code' => 'hard_locked',
                    'message' =>
                        'This account is locked for security. Check your email for the appeal instructions. An administrator must unlock the account before you can sign in again.'
                ];
            }

            $until =
                !empty($state['cooldown_until'])
                    ? strtotime(
                        (string)$state['cooldown_until']
                    )
                    : false;

            if ($until !== false && $until > time()) {
                $seconds =
                    max(
                        1,
                        $until - time()
                    );

                $conn->commit();

                return [
                    'code' => 'cooldown',
                    'seconds' => $seconds,
                    'message' =>
                        'Too many incorrect passwords. Please wait ' .
                        $seconds .
                        ' second' .
                        ($seconds === 1 ? '' : 's') .
                        ' before trying again.'
                ];
            }

            $failedAttempts =
                (int)$state['failed_attempts'] + 1;

            $stage =
                (int)$state['cooldown_stage'];

            if ($failedAttempts < 3) {
                $stmt = $conn->prepare("
                    UPDATE login_security_state
                    SET
                        failed_attempts = ?,
                        cooldown_until = NULL,
                        last_failed_at = NOW(),
                        last_failed_ip = ?,
                        last_user_agent = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    'issi',
                    $failedAttempts,
                    $ip,
                    $ua,
                    $stateId
                );

                $stmt->execute();
                $stmt->close();

                $conn->commit();

                $remaining =
                    3 - $failedAttempts;

                return [
                    'code' => 'incorrect',
                    'remaining_attempts' =>
                        $remaining,
                    'message' =>
                        'Incorrect password. ' .
                        $remaining .
                        ' attempt' .
                        ($remaining === 1 ? '' : 's') .
                        ' remaining.'
                ];
            }

            /*
             * First set of 3 failures:
             * lock for exactly 10 seconds.
             */
            if ($stage === 0) {
                $stmt = $conn->prepare("
                    UPDATE login_security_state
                    SET
                        failed_attempts = 0,
                        cooldown_stage = 1,
                        cooldown_until = DATE_ADD(NOW(), INTERVAL 10 SECOND),
                        last_failed_at = NOW(),
                        last_failed_ip = ?,
                        last_user_agent = ?
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    'ssi',
                    $ip,
                    $ua,
                    $stateId
                );

                $stmt->execute();
                $stmt->close();

                $conn->commit();

                return [
                    'code' => 'cooldown',
                    'seconds' => 10,
                    'message' =>
                        'Too many incorrect passwords. Your account is locked for 10 seconds. After the timer ends, you may try again.'
                ];
            }

            /*
             * Second set of 3 failures:
             * hard lock until an admin manually unlocks.
             */
            $stmt = $conn->prepare("
                UPDATE login_security_state
                SET
                    failed_attempts = 0,
                    cooldown_until = NULL,
                    hard_locked = 1,
                    hard_locked_at = NOW(),
                    last_failed_at = NOW(),
                    last_failed_ip = ?,
                    last_user_agent = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                'ssi',
                $ip,
                $ua,
                $stateId
            );

            $stmt->execute();
            $stmt->close();

            $appeal =
                security_create_appeal(
                    $conn,
                    $stateId
                );

            $conn->commit();

            $appealUrl =
                security_base_url() .
                '/account_unlock_appeal.php?token=' .
                urlencode(
                    (string)$appeal['token']
                );

            $mailSent =
                security_send_lock_email(
                    $account,
                    $appealUrl
                );

            if ($mailSent) {
                $stmt = $conn->prepare("
                    UPDATE login_security_appeals
                    SET email_sent_at = NOW()
                    WHERE id = ?
                ");

                $appealId =
                    (int)$appeal['id'];

                $stmt->bind_param(
                    'i',
                    $appealId
                );

                $stmt->execute();
                $stmt->close();
            }

            return [
                'code' => 'hard_locked',
                'mail_sent' => $mailSent,
                'appeal_url' => $appealUrl,
                'message' =>
                    $mailSent
                        ? 'Your account is now locked for security. We sent an email with an appeal link. An administrator must unlock the account before you can sign in again.'
                        : 'Your account is now locked for security. The email could not be delivered, but you can use the appeal link below. An administrator must unlock the account before you can sign in again.'
            ];

        } catch (\Throwable $e) {
            $conn->rollback();

            error_log(
                'Login security failure handler error: ' .
                $e->getMessage()
            );

            return [
                'code' => 'error',
                'message' =>
                    'Unable to process the login security check. Please try again.'
            ];
        }
    }
}

if (!function_exists('security_send_unlock_email')) {
    function security_send_unlock_email(
        string $email,
        string $name
    ): bool {
        $safeName =
            htmlspecialchars(
                $name !== ''
                    ? $name
                    : 'Member',
                ENT_QUOTES,
                'UTF-8'
            );

        $loginUrl =
            security_base_url() .
            '/index.php';

        $safeLogin =
            htmlspecialchars(
                $loginUrl,
                ENT_QUOTES,
                'UTF-8'
            );

        $html = '
        <div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#1f2937;">
          <div style="background:#077f46;color:#fff;padding:20px 24px;border-radius:14px 14px 0 0;">
            <h2 style="margin:0;font-size:21px;">South Meridian Homes</h2>
          </div>
          <div style="border:1px solid #e5e7eb;border-top:0;padding:24px;border-radius:0 0 14px 14px;">
            <p>Hello <strong>' . $safeName . '</strong>,</p>
            <p>
              An administrator reviewed your login-security case and unlocked your account.
            </p>
            <p>You may now sign in again.</p>
            <p style="margin-top:24px;">
              <a href="' . $safeLogin . '"
                 style="display:inline-block;background:#077f46;color:#fff;text-decoration:none;padding:12px 22px;border-radius:9px;font-weight:700;">
                Sign In
              </a>
            </p>
          </div>
        </div>';

        return security_send_email(
            $email,
            $name,
            'Your South Meridian account has been unlocked',
            $html,
            "Your South Meridian account has been unlocked. You may sign in again: " .
            $loginUrl
        );
    }
}
?>
