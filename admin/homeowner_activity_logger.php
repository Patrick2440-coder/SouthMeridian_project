<?php

if (!function_exists('logHomeownerActivity')) {
    /**
     * Write one Homeowner Management audit event.
     * Logging failures never interrupt the main homeowner action.
     */
    function logHomeownerActivity(
        mysqli $conn,
        string $action,
        string $details = '',
        ?int $adminId = null,
        ?string $phase = null
    ): void {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }

            $adminId = $adminId ?? (int)($_SESSION['admin_id'] ?? 0);
            if ($adminId <= 0) {
                $adminId = null;
            }
            $phase = trim((string)($phase ?? ($_SESSION['admin_phase'] ?? ($_SESSION['phase'] ?? ''))));

            if ($adminId > 0 && $phase === '') {
                $adminStmt = $conn->prepare('SELECT phase FROM admins WHERE id=? LIMIT 1');
                $adminStmt->bind_param('i', $adminId);
                $adminStmt->execute();
                $row = $adminStmt->get_result()->fetch_assoc();
                $adminStmt->close();
                $phase = trim((string)($row['phase'] ?? ''));
            }

            if (!in_array($phase, ['Phase 1', 'Phase 2', 'Phase 3', 'Superadmin'], true)) {
                $phase = null;
            }

            $action = trim($action);
            if ($action === '') {
                return;
            }

            if (strlen($action) > 255) {
                $action = substr($action, 0, 255);
            }

            $ipAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
            if (strlen($ipAddress) > 45) {
                $ipAddress = substr($ipAddress, 0, 45);
            }

            $moduleKey = 'homeowner_management';

            $stmt = $conn->prepare(
                'INSERT INTO activity_logs
                 (admin_id, phase, action, module_key, details, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            $stmt->bind_param(
                'isssss',
                $adminId,
                $phase,
                $action,
                $moduleKey,
                $details,
                $ipAddress
            );

            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('Homeowner activity log failed: ' . $e->getMessage());
        }
    }
}
