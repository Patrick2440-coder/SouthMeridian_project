<?php

declare(strict_types=1);

if (!function_exists('finance_audit_table_exists')) {
    function finance_audit_table_exists(mysqli $conn): bool
    {
        try {
            $result = $conn->query("SHOW TABLES LIKE 'finance_audit_logs'");
            return $result && $result->num_rows > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('finance_audit_log')) {
    function finance_audit_log(
        mysqli $conn,
        string $action,
        string $entityType,
        ?int $entityId = null,
        string $details = '',
        mixed $beforeData = null,
        mixed $afterData = null,
        ?string $batchId = null,
        ?int $adminId = null,
        ?string $phase = null
    ): void {
        try {
            if (!finance_audit_table_exists($conn)) {
                return;
            }

            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }

            $adminId = $adminId ?? (int)($_SESSION['admin_id'] ?? 0);
            if ($adminId <= 0) {
                $adminId = null;
            }

            $phase = trim((string)($phase ?? ($_SESSION['admin_phase'] ?? ($_SESSION['phase'] ?? ''))));
            if (!in_array($phase, ['Phase 1','Phase 2','Phase 3','Superadmin'], true)) {
                $phase = null;
            }

            $action = mb_substr(trim($action), 0, 150);
            $entityType = mb_substr(trim($entityType), 0, 100);
            $details = mb_substr(trim($details), 0, 1000);
            $batchId = $batchId !== null ? mb_substr(trim($batchId), 0, 64) : null;

            $beforeJson = $beforeData === null
                ? null
                : json_encode($beforeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $afterJson = $afterData === null
                ? null
                : json_encode($afterData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ip = mb_substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
            $ua = mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);

            $stmt = $conn->prepare(
                "INSERT INTO finance_audit_logs
                (admin_id, phase, action, entity_type, entity_id, batch_id, details, before_data, after_data, ip_address, user_agent)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->bind_param(
                'isssissssss',
                $adminId,
                $phase,
                $action,
                $entityType,
                $entityId,
                $batchId,
                $details,
                $beforeJson,
                $afterJson,
                $ip,
                $ua
            );
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('Finance audit log failed: ' . $e->getMessage());
        }
    }
}
