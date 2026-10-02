<?php
declare(strict_types=1);

define('SMH_LOGIN_SECURITY_VERSION', '2026-10-02-30SEC-V4');

/*
|--------------------------------------------------------------------------
| South Meridian Homes - Login Security Helper
|--------------------------------------------------------------------------
|
| Expected flow:
|   1st set: 3 wrong passwords -> 30-second cooldown
|   Next wrong password after cooldown -> hard lock
|   Hard lock -> email + appeal link
|   Admin unlock -> failed-attempt cycle resets
|
*/

if (!function_exists('security_client_ip')) {
    function security_client_ip(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? ''
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);

            if ($candidate === '') {
                continue;
            }

            if (str_contains($candidate, ',')) {
                $candidate = trim(explode(',', $candidate)[0]);
            }

            return mb_substr($candidate, 0, 45);
        }

        return '';
    }
}

if (!function_exists('security_user_agent')) {
    function security_user_agent(): string
    {
        return mb_substr(
            trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
            0,
            500
        );
    }
}

if (!function_exists('security_config')) {
    function security_config(): array
    {
        static $config = null;

        if (is_array($config)) {
            return $config;
        }

        $config = [];

        $secretsFile = __DIR__ . '/admin/private/hoa_secrets.php';

        if (is_file($secretsFile)) {
            $loaded = require $secretsFile;

            if (is_array($loaded)) {
                $config = $loaded;
            }
        }

        return $config;
    }
}

if (!function_exists('security_app_base_url')) {
    function security_app_base_url(): string
    {
        $config = security_config();

        $configured = rtrim(
            trim((string)($config['app_base_url'] ?? '')),
            '/'
        );

        if (
            $configured !== '' &&
            filter_var($configured, FILTER_VALIDATE_URL)
        ) {
            return $configured;
        }

        $https =
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';

        $scheme = $https ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

        if ($host === '') {
            return '';
        }

        $scriptName = str_replace(
            '\\',
            '/',
            (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')
        );

        $directory = rtrim(
            str_replace(
                '\\',
                '/',
                dirname($scriptName)
            ),
            '/'
        );

        /*
         * index.php is in the project root.
         * If this helper is called from /admin/login_security.php,
         * step back from /admin to the project root.
         */
        if (str_ends_with($directory, '/admin')) {
            $directory = substr($directory, 0, -6);
        }

        return
            $scheme .
            '://' .
            $host .
            ($directory !== '' && $directory !== '.'
                ? $directory
                : '');
    }
}

if (!function_exists('security_appeal_url')) {
    function security_appeal_url(string $token): string
    {
        $baseUrl = security_app_base_url();

        if ($baseUrl === '') {
            return 'login_security_appeal.php?token=' .
                rawurlencode($token);
        }

        return
            $baseUrl .
            '/login_security_appeal.php?token=' .
            rawurlencode($token);
    }
}

if (!function_exists('security_ensure_state')) {
    function security_ensure_state(
        mysqli $conn,
        array $account
    ): array {
        $accountType = trim((string)($account['type'] ?? ''));
        $accountId = (int)($account['id'] ?? 0);
        $email = strtolower(trim((string)($account['email'] ?? '')));
        $phase = trim((string)($account['phase'] ?? ''));

        if (
            !in_array(
                $accountType,
                ['admin', 'homeowner', 'tenant'],
                true
            )
            || $accountId <= 0
            || $email === ''
        ) {
            throw new InvalidArgumentException(
                'Invalid account data for login security.'
            );
        }

        $stmt = $conn->prepare("
            INSERT INTO login_security_state
            (
                account_type,
                account_id,
                email,
                phase
            )
            VALUES (?, ?, ?, NULLIF(?, ''))
            ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                phase = VALUES(phase)
        ");

        $stmt->bind_param(
            'siss',
            $accountType,
            $accountId,
            $email,
            $phase
        );

        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT
                *,
                CASE
                    WHEN hard_locked = 1 THEN 0
                    WHEN cooldown_stage = 1
                         AND failed_attempts = 0
                         AND last_failed_at IS NOT NULL
                    THEN GREATEST(
                        30 - TIMESTAMPDIFF(
                            SECOND,
                            last_failed_at,
                            NOW()
                        ),
                        0
                    )
                    ELSE 0
                END AS cooldown_seconds
            FROM login_security_state
            WHERE account_type = ?
              AND account_id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'si',
            $accountType,
            $accountId
        );

        $stmt->execute();

        $state = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if (!$state) {
            throw new RuntimeException(
                'Unable to create login security state.'
            );
        }

        return $state;
    }
}

if (!function_exists('security_send_email')) {
    function security_send_email(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody
    ): bool {
        $toEmail = trim($toEmail);

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $autoload = __DIR__ . '/vendor/autoload.php';

        if (!is_file($autoload)) {
            error_log(
                'Login security email failed: vendor/autoload.php not found.'
            );

            return false;
        }

        require_once $autoload;

        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            error_log(
                'Login security email failed: PHPMailer is unavailable.'
            );

            return false;
        }

        $config = security_config();

        $smtpHost = trim(
            (string)($config['smtp_host'] ?? 'smtp.gmail.com')
        );

        $smtpUsername = trim(
            (string)($config['smtp_username'] ?? '')
        );

        $smtpPassword = preg_replace(
            '/\s+/',
            '',
            trim((string)($config['smtp_password'] ?? ''))
        );

        $smtpPort = (int)($config['smtp_port'] ?? 587);

        $smtpEncryption = strtolower(
            trim((string)($config['smtp_encryption'] ?? 'tls'))
        );

        $fromEmail = trim(
            (string)($config['smtp_from_email'] ?? $smtpUsername)
        );

        $fromName = trim(
            (string)($config['smtp_from_name'] ?? 'South Meridian HOA')
        );

        if (
            $smtpUsername === ''
            || $smtpPassword === ''
            || !filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)
        ) {
            error_log(
                'Login security email failed: SMTP configuration is incomplete.'
            );

            return false;
        }

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $smtpUsername;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUsername;
            $mail->Password = $smtpPassword;
            $mail->Port = $smtpPort;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 30;
            $mail->SMTPDebug = 0;

            if ($smtpEncryption === 'ssl') {
                $mail->SMTPSecure =
                    \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure =
                    \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom(
                $fromEmail,
                $fromName
            );

            $mail->addAddress(
                $toEmail,
                $toName !== '' ? $toName : $toEmail
            );

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            $mail->send();

            return true;
        } catch (Throwable $e) {
            error_log(
                'Login security email failed: ' .
                $e->getMessage()
            );

            return false;
        }
    }
}

if (!function_exists('security_send_lock_email')) {
    function security_send_lock_email(
        string $email,
        string $displayName,
        string $appealUrl
    ): bool {
        $safeName = htmlspecialchars(
            $displayName !== '' ? $displayName : 'User',
            ENT_QUOTES,
            'UTF-8'
        );

        $safeAppealUrl = htmlspecialchars(
            $appealUrl,
            ENT_QUOTES,
            'UTF-8'
        );

        $subject =
            'South Meridian HOA - Account Temporarily Locked';

        $html = "
            <div style=\"font-family:Arial,sans-serif;color:#1f2937;line-height:1.6;\">
                <h2 style=\"margin:0 0 12px;color:#b91c1c;\">
                    Account Temporarily Locked
                </h2>

                <p>Hello <strong>{$safeName}</strong>,</p>

                <p>
                    Your South Meridian HOA account was temporarily locked
                    after repeated incorrect password attempts.
                </p>

                <p>
                    If this was you and you need access restored, submit an
                    unlock appeal using the button below.
                </p>

                <p style=\"margin:22px 0;\">
                    <a
                        href=\"{$safeAppealUrl}\"
                        style=\"display:inline-block;background:#b91c1c;color:#fff;padding:11px 16px;text-decoration:none;border-radius:8px;font-weight:bold;\"
                    >
                        Submit Unlock Appeal
                    </a>
                </p>

                <p>
                    An authorized HOA administrator must review and unlock
                    the account before you can sign in again.
                </p>

                <p>
                    If you did not attempt to sign in, please include that
                    information in your appeal.
                </p>

                <p>
                    Regards,<br>
                    <strong>South Meridian HOA</strong>
                </p>
            </div>
        ";

        $text =
            "Hello {$displayName},\n\n" .
            "Your South Meridian HOA account was temporarily locked after repeated incorrect password attempts.\n\n" .
            "Submit an unlock appeal here:\n{$appealUrl}\n\n" .
            "An authorized HOA administrator must unlock the account before you can sign in again.\n\n" .
            "South Meridian HOA";

        return security_send_email(
            $email,
            $displayName,
            $subject,
            $html,
            $text
        );
    }
}

if (!function_exists('security_send_unlock_email')) {
    function security_send_unlock_email(
        string $email,
        string $displayName,
        string $passwordChangeUrl = ''
    ): bool {
        $safeName = htmlspecialchars(
            $displayName !== '' ? $displayName : 'User',
            ENT_QUOTES,
            'UTF-8'
        );

        $passwordChangeRequired =
            trim($passwordChangeUrl) !== '';

        if ($passwordChangeRequired) {
            $actionUrl =
                trim($passwordChangeUrl);
        } else {
            $actionUrl =
                security_app_base_url();

            if ($actionUrl === '') {
                $actionUrl =
                    'index.php?login=1';
            } else {
                $actionUrl =
                    rtrim(
                        $actionUrl,
                        '/'
                    ) .
                    '/index.php?login=1';
            }
        }

        $safeActionUrl =
            htmlspecialchars(
                $actionUrl,
                ENT_QUOTES,
                'UTF-8'
            );

        $subject =
            $passwordChangeRequired
                ? 'South Meridian HOA - Account Unlocked / Change Password'
                : 'South Meridian HOA - Account Unlocked';

        $passwordNoticeHtml =
            $passwordChangeRequired
                ? "
                    <div style=\"margin:18px 0;padding:13px 15px;border:1px solid #fde68a;border-radius:9px;background:#fffbeb;color:#92400e;\">
                        <strong>Password change required</strong><br>
                        Your account has been unlocked. For security, you must
                        create a new password using the button below before
                        continuing to use your homeowner account.
                    </div>
                "
                : '';

        $buttonText =
            $passwordChangeRequired
                ? 'Change Password'
                : 'Sign In';

        $html = "
            <div style=\"font-family:Arial,sans-serif;color:#1f2937;line-height:1.6;\">
                <h2 style=\"margin:0 0 12px;color:#047857;\">
                    Account Unlocked
                </h2>

                <p>Hello <strong>{$safeName}</strong>,</p>

                <p>
                    Your South Meridian HOA account has been unlocked by an
                    authorized administrator.
                </p>

                {$passwordNoticeHtml}

                <p style=\"margin:22px 0;\">
                    <a
                        href=\"{$safeActionUrl}\"
                        style=\"display:inline-block;background:#047857;color:#fff;padding:11px 16px;text-decoration:none;border-radius:8px;font-weight:bold;\"
                    >
                        {$buttonText}
                    </a>
                </p>

                <p>
                    The password-change link is valid for one hour.
                </p>

                <p>
                    If you did not request this unlock, please contact the HOA
                    office.
                </p>

                <p>
                    Regards,<br>
                    <strong>South Meridian HOA</strong>
                </p>
            </div>
        ";

        $passwordNoticeText =
            $passwordChangeRequired
                ? "Your account has been unlocked. You must create a new password using this one-time link:\n{$actionUrl}\n\nThe link is valid for one hour.\n\n"
                : "You may sign in again here:\n{$actionUrl}\n\n";

        $text =
            "Hello {$displayName},\n\n" .
            "Your South Meridian HOA account has been unlocked by an authorized administrator.\n\n" .
            $passwordNoticeText .
            "South Meridian HOA";

        return security_send_email(
            $email,
            $displayName,
            $subject,
            $html,
            $text
        );
    }
}

if (!function_exists('security_current_block')) {
    function security_current_block(
        mysqli $conn,
        array $account
    ): array {
        $state = security_ensure_state(
            $conn,
            $account
        );

        $stateId = (int)$state['id'];

        if ((int)$state['hard_locked'] === 1) {
            return [
                'blocked' => true,
                'code' => 'hard_locked',
                'seconds' => 0,
                'mail_sent' => false,
                'appeal_url' => '',
                'message' =>
                    'This account is temporarily locked. Use the unlock appeal link sent to your email or contact the HOA office.'
            ];
        }

        $cooldownSeconds =
            max(
                0,
                min(
                    30,
                    (int)($state['cooldown_seconds'] ?? 0)
                )
            );

        if ($cooldownSeconds > 0) {
            return [
                'blocked' => true,
                'code' => 'cooldown',
                'seconds' => $cooldownSeconds,
                'mail_sent' => false,
                'appeal_url' => '',
                'message' =>
                    "Too many incorrect passwords. Try again in {$cooldownSeconds} second" .
                    ($cooldownSeconds === 1 ? '.' : 's.')
            ];
        }

        /*
         * cooldown_until is kept only for backward compatibility.
         * The real cooldown timer now uses last_failed_at + 30 seconds.
         */
        if (!empty($state['cooldown_until'])) {
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
            'blocked' => false,
            'code' => '',
            'seconds' => 0,
            'mail_sent' => false,
            'appeal_url' => '',
            'message' => ''
        ];
    }
}

if (!function_exists('security_register_failure')) {
    function security_register_failure(
        mysqli $conn,
        array $account
    ): array {
        $accountType = trim((string)($account['type'] ?? ''));
        $accountId = (int)($account['id'] ?? 0);
        $email = strtolower(trim((string)($account['email'] ?? '')));
        $displayName = trim((string)($account['name'] ?? ''));

        security_ensure_state(
            $conn,
            $account
        );

        $ip = security_client_ip();
        $userAgent = security_user_agent();

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                SELECT
                    *,
                    CASE
                        WHEN hard_locked = 1 THEN 0
                        WHEN cooldown_stage = 1
                             AND failed_attempts = 0
                             AND last_failed_at IS NOT NULL
                        THEN GREATEST(
                            30 - TIMESTAMPDIFF(
                                SECOND,
                                last_failed_at,
                                NOW()
                            ),
                            0
                        )
                        ELSE 0
                    END AS cooldown_seconds
                FROM login_security_state
                WHERE account_type = ?
                  AND account_id = ?
                LIMIT 1
                FOR UPDATE
            ");

            $stmt->bind_param(
                'si',
                $accountType,
                $accountId
            );

            $stmt->execute();

            $state = $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();

            if (!$state) {
                throw new RuntimeException(
                    'Login security state was not found.'
                );
            }

            $stateId = (int)$state['id'];

            if ((int)$state['hard_locked'] === 1) {
                $conn->commit();

                return [
                    'code' => 'hard_locked',
                    'seconds' => 0,
                    'remaining_attempts' => 0,
                    'mail_sent' => false,
                    'appeal_url' => '',
                    'message' =>
                        'This account is temporarily locked. Use the unlock appeal link sent to your email or contact the HOA office.'
                ];
            }

            $cooldownSeconds =
                max(
                    0,
                    min(
                        30,
                        (int)($state['cooldown_seconds'] ?? 0)
                    )
                );

            if ($cooldownSeconds > 0) {
                $conn->commit();

                return [
                    'code' => 'cooldown',
                    'seconds' => $cooldownSeconds,
                    'remaining_attempts' => 0,
                    'mail_sent' => false,
                    'appeal_url' => '',
                    'message' =>
                        "Too many incorrect passwords. Try again in {$cooldownSeconds} second" .
                        ($cooldownSeconds === 1 ? '.' : 's.')
                ];
            }

            $stage = (int)$state['cooldown_stage'];
            $attempts =
                (int)$state['failed_attempts'] + 1;

            /*
             * First three incorrect passwords:
             * start one 30-second cooldown.
             */
            if ($stage === 0 && $attempts >= 3) {
                $stmt = $conn->prepare("
                    UPDATE login_security_state
                    SET
                        failed_attempts = 0,
                        cooldown_stage = 1,
                        cooldown_until = NULL,
                        last_failed_at = NOW(),
                        last_failed_ip = NULLIF(?, ''),
                        last_user_agent = NULLIF(?, '')
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    'ssi',
                    $ip,
                    $userAgent,
                    $stateId
                );

                $stmt->execute();
                $stmt->close();

                $conn->commit();

                return [
                    'code' => 'cooldown',
                    'seconds' => 30,
                    'remaining_attempts' => 1,
                    'mail_sent' => false,
                    'appeal_url' => '',
                    'message' =>
                        'Too many incorrect passwords. Try again in 30 seconds. The next incorrect password will lock the account.'
                ];
            }

            /*
             * After the 30-second cooldown, the very next wrong password
             * hard-locks the account.
             */
            if ($stage >= 1) {
                $stmt = $conn->prepare("
                    UPDATE login_security_state
                    SET
                        failed_attempts = 1,
                        cooldown_until = NULL,
                        hard_locked = 1,
                        hard_locked_at = NOW(),
                        last_failed_at = NOW(),
                        last_failed_ip = NULLIF(?, ''),
                        last_user_agent = NULLIF(?, ''),
                        unlocked_at = NULL,
                        unlocked_by_admin_id = NULL
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    'ssi',
                    $ip,
                    $userAgent,
                    $stateId
                );

                $stmt->execute();
                $stmt->close();

                /*
                 * Commit the hard lock first. Even if the appeal insert or
                 * email fails, the account must still appear in Login Security.
                 */
                $conn->commit();

                $appealUrl = '';
                $mailSent = false;

                try {
                    $stmt = $conn->prepare("
                        UPDATE login_security_appeals
                        SET
                            status = 'rejected',
                            reviewed_at = NOW(),
                            admin_remarks =
                                'Superseded by a newer account lock.'
                        WHERE security_state_id = ?
                          AND status = 'available'
                    ");

                    $stmt->bind_param(
                        'i',
                        $stateId
                    );

                    $stmt->execute();
                    $stmt->close();

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
                        INSERT INTO login_security_appeals
                        (
                            security_state_id,
                            token_hash,
                            status,
                            created_at
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            'available',
                            NOW()
                        )
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

                    $appealUrl =
                        security_appeal_url(
                            $rawToken
                        );

                    $mailSent =
                        security_send_lock_email(
                            $email,
                            $displayName,
                            $appealUrl
                        );

                    if ($mailSent) {
                        $stmt = $conn->prepare("
                            UPDATE login_security_appeals
                            SET email_sent_at = NOW()
                            WHERE id = ?
                            LIMIT 1
                        ");

                        $stmt->bind_param(
                            'i',
                            $appealId
                        );

                        $stmt->execute();
                        $stmt->close();
                    }
                } catch (Throwable $appealError) {
                    error_log(
                        'Login security appeal/email error: ' .
                        $appealError->getMessage()
                    );
                }

                return [
                    'code' => 'hard_locked',
                    'seconds' => 0,
                    'remaining_attempts' => 0,
                    'mail_sent' => $mailSent,
                    'appeal_url' => '',
                    'message' =>
                        'Your account has been temporarily locked after the incorrect password entered after the 30-second cooldown. Check your email for the unlock appeal link.'
                ];
            }

            $stmt = $conn->prepare("
                UPDATE login_security_state
                SET
                    failed_attempts = ?,
                    last_failed_at = NOW(),
                    last_failed_ip = NULLIF(?, ''),
                    last_user_agent = NULLIF(?, '')
                WHERE id = ?
            ");

            $stmt->bind_param(
                'issi',
                $attempts,
                $ip,
                $userAgent,
                $stateId
            );

            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $remaining =
                max(
                    0,
                    3 - $attempts
                );

            return [
                'code' => 'incorrect',
                'seconds' => 0,
                'remaining_attempts' => $remaining,
                'mail_sent' => false,
                'appeal_url' => '',
                'message' =>
                    "Incorrect password. {$remaining} attempt" .
                    ($remaining === 1 ? '' : 's') .
                    ' remaining before a 30-second cooldown.'
            ];
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }

            throw $e;
        }
    }
}

if (!function_exists('security_reset_after_success')) {
    function security_reset_after_success(
        mysqli $conn,
        array $account
    ): void {
        $accountType = trim((string)($account['type'] ?? ''));
        $accountId = (int)($account['id'] ?? 0);

        if ($accountType === '' || $accountId <= 0) {
            return;
        }

        $stmt = $conn->prepare("
            UPDATE login_security_state
            SET
                failed_attempts = 0,
                cooldown_stage = 0,
                cooldown_until = NULL,
                hard_locked = 0,
                hard_locked_at = NULL,
                unlocked_at = NULL,
                unlocked_by_admin_id = NULL
            WHERE account_type = ?
              AND account_id = ?
        ");

        $stmt->bind_param(
            'si',
            $accountType,
            $accountId
        );

        $stmt->execute();
        $stmt->close();
    }
}
