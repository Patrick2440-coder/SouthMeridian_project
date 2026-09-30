<?php

if (!function_exists('otFullName')) {
    function otFullName(array $row): string
    {
        return trim(
            (string)($row['first_name'] ?? '') . ' ' .
            (string)($row['middle_name'] ?? '') . ' ' .
            (string)($row['last_name'] ?? '')
        );
    }
}

if (!function_exists('otBlockLot')) {
    function otBlockLot(array $row): array
    {
        $block = (int)($row['block'] ?? 0);
        $lot = (int)($row['lot'] ?? 0);

        if ($block > 0 && $lot > 0) {
            return [$block, $lot];
        }

        $legacy = trim((string)($row['house_lot_number'] ?? ''));
        if ($legacy !== '' && preg_match('/(?:block|blk|b)\s*[-:#]?\s*(\d+)\D+(?:lot|l)\s*[-:#]?\s*(\d+)/i', $legacy, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }

        return [0, 0];
    }
}

if (!function_exists('otSameProperty')) {
    function otSameProperty(array $left, array $right): bool
    {
        [$leftBlock, $leftLot] = otBlockLot($left);
        [$rightBlock, $rightLot] = otBlockLot($right);

        return
            trim((string)($left['phase'] ?? '')) !== '' &&
            trim((string)($left['phase'] ?? '')) === trim((string)($right['phase'] ?? '')) &&
            $leftBlock > 0 &&
            $leftLot > 0 &&
            $leftBlock === $rightBlock &&
            $leftLot === $rightLot;
    }
}

if (!function_exists('otEmailsDifferent')) {
    function otEmailsDifferent(array $left, array $right): bool
    {
        $a = strtolower(trim((string)($left['email'] ?? '')));
        $b = strtolower(trim((string)($right['email'] ?? '')));
        return $a !== '' && $b !== '' && $a !== $b;
    }
}

if (!function_exists('otNormalizeSourceType')) {
    function otNormalizeSourceType(string $source): string
    {
        return in_array($source, ['import_queue', 'pending_homeowner'], true)
            ? $source
            : '';
    }
}

if (!function_exists('otPropertyRegex')) {
    function otPropertyRegex(int $block, int $lot): string
    {
        return '[Bb](lock|lk)?[[:space:]]*' . $block . '[^0-9]+[Ll]ot[[:space:]]*' . $lot;
    }
}

if (!function_exists('otFindApprovedPropertyOwner')) {
    function otFindApprovedPropertyOwner(mysqli $conn, array $incoming, int $excludeHomeownerId = 0): ?array
    {
        [$block, $lot] = otBlockLot($incoming);
        $phase = trim((string)($incoming['phase'] ?? ''));
        if ($phase === '' || $block <= 0 || $lot <= 0) {
            return null;
        }

        $regex = otPropertyRegex($block, $lot);
        $stmt = $conn->prepare(
            "SELECT * FROM homeowners
             WHERE phase=?
               AND status='approved'
               AND id<>?
               AND (
                    (CAST(block AS UNSIGNED)=? AND CAST(lot AS UNSIGNED)=?)
                    OR house_lot_number REGEXP ?
               )
             ORDER BY id ASC
             LIMIT 1"
        );
        $stmt->bind_param('siiis', $phase, $excludeHomeownerId, $block, $lot, $regex);
        $stmt->execute();
        $owner = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $owner;
    }
}

if (!function_exists('otTransferSchemaReady')) {
    function otTransferSchemaReady(mysqli $conn): bool
    {
        try {
            $table = $conn->query("SHOW TABLES LIKE 'homeowner_ownership_transfers'");
            if (!$table || $table->num_rows === 0) return false;

            $needed = ['source_type', 'source_queue_id', 'source_homeowner_id'];
            foreach ($needed as $column) {
                $safe = $conn->real_escape_string($column);
                $res = $conn->query("SHOW COLUMNS FROM homeowner_ownership_transfers LIKE '{$safe}'");
                if (!$res || $res->num_rows === 0) return false;
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('otLoadIncoming')) {
    function otLoadIncoming(mysqli $conn, string $sourceType, int $sourceId, string $adminRole, string $adminPhase, bool $forUpdate = false): ?array
    {
        $sourceType = otNormalizeSourceType($sourceType);
        if ($sourceType === '' || $sourceId <= 0) return null;
        $lock = $forUpdate ? ' FOR UPDATE' : '';

        if ($sourceType === 'import_queue') {
            if ($adminRole === 'superadmin') {
                $stmt = $conn->prepare("SELECT * FROM homeowner_import_queue WHERE id=? AND status='duplicate' LIMIT 1{$lock}");
                $stmt->bind_param('i', $sourceId);
            } else {
                $stmt = $conn->prepare("SELECT * FROM homeowner_import_queue WHERE id=? AND phase=? AND status='duplicate' LIMIT 1{$lock}");
                $stmt->bind_param('is', $sourceId, $adminPhase);
            }
        } else {
            if ($adminRole === 'superadmin') {
                $stmt = $conn->prepare("SELECT * FROM homeowners WHERE id=? AND status='pending' LIMIT 1{$lock}");
                $stmt->bind_param('i', $sourceId);
            } else {
                $stmt = $conn->prepare("SELECT * FROM homeowners WHERE id=? AND phase=? AND status='pending' LIMIT 1{$lock}");
                $stmt->bind_param('is', $sourceId, $adminPhase);
            }
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('otFindTransferBySource')) {
    function otFindTransferBySource(mysqli $conn, string $sourceType, int $sourceId, bool $forUpdate = false): ?array
    {
        $sourceType = otNormalizeSourceType($sourceType);
        if ($sourceType === '' || $sourceId <= 0) return null;
        $lock = $forUpdate ? ' FOR UPDATE' : '';

        if ($sourceType === 'import_queue') {
            $stmt = $conn->prepare("SELECT * FROM homeowner_ownership_transfers WHERE source_type='import_queue' AND source_queue_id=? LIMIT 1{$lock}");
        } else {
            $stmt = $conn->prepare("SELECT * FROM homeowner_ownership_transfers WHERE source_type='pending_homeowner' AND source_homeowner_id=? LIMIT 1{$lock}");
        }
        $stmt->bind_param('i', $sourceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }
}

if (!function_exists('otLoadMailConfig')) {
    function otLoadMailConfig(): array
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!is_file($autoload)) throw new RuntimeException('PHPMailer Composer autoload was not found.');
        require_once $autoload;

        $secretsFile = __DIR__ . '/private/hoa_secrets.php';
        if (!is_file($secretsFile)) throw new RuntimeException('SMTP configuration file was not found.');

        $secrets = require $secretsFile;
        if (!is_array($secrets)) throw new RuntimeException('SMTP configuration file is invalid.');

        $smtpUsername = trim((string)($secrets['smtp_username'] ?? ''));
        $smtpPassword = preg_replace('/\s+/', '', trim((string)($secrets['smtp_password'] ?? '')));
        $appBaseUrl = rtrim(trim((string)($secrets['app_base_url'] ?? '')), '/');

        if (!filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP Gmail address is not configured correctly.');
        if ($smtpPassword === '') throw new RuntimeException('SMTP Gmail App Password is not configured.');
        if (!filter_var($appBaseUrl, FILTER_VALIDATE_URL)) throw new RuntimeException('Application base URL is not configured correctly.');

        return ['smtp_username'=>$smtpUsername, 'smtp_password'=>$smtpPassword, 'app_base_url'=>$appBaseUrl];
    }
}

if (!function_exists('otSendMail')) {
    function otSendMail(array $mailConfig, string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody): void
    {
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) throw new RuntimeException('PHPMailer is not available.');
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid recipient email address is required.');

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $mailConfig['smtp_username'];
        $mail->Password = $mailConfig['smtp_password'];
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        $mail->SMTPDebug = 0;
        $mail->Timeout = 30;
        $mail->setFrom($mailConfig['smtp_username'], 'South Meridian HOA');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $altBody;
        $mail->send();
    }
}

if (!function_exists('otConfirmationBadge')) {
    function otConfirmationBadge(string $status): string
    {
        return match ($status) {
            'confirmed' => 'Confirmed by email',
            'manual_verified' => 'Verified by admin',
            'denied' => 'Denied',
            default => 'Pending',
        };
    }
}
