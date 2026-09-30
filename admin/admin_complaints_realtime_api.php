<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function complaint_json(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function complaint_display_datetime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }

    $ts = strtotime($value);
    return $ts ? date('M d, Y h:i A', $ts) : $value;
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $sessionRole = strtolower(trim((string)($_SESSION['admin_role'] ?? $_SESSION['role'] ?? '')));

    if ($adminId <= 0 || $sessionRole !== 'admin') {
        complaint_json([
            'success' => false,
            'authorized' => false,
            'message' => 'Admin session required.',
        ], 401);
    }

    $stmt = $conn->prepare("\n        SELECT id, phase, position, role\n        FROM admins\n        WHERE id = ?\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$admin || strtolower((string)($admin['role'] ?? '')) !== 'admin') {
        complaint_json([
            'success' => false,
            'authorized' => false,
            'message' => 'Admin account not found.',
        ], 401);
    }

    $phase = trim((string)($admin['phase'] ?? ''));
    $position = trim((string)($admin['position'] ?? ''));

    if ($phase === '') {
        complaint_json([
            'success' => false,
            'authorized' => false,
            'message' => 'Admin phase is missing.',
        ], 403);
    }

    // Respect an explicit complaints permission row when one exists.
    $authorized = true;
    if ($position !== '') {
        $permStmt = $conn->prepare("\n            SELECT is_allowed\n            FROM access_permissions\n            WHERE position = ? AND module_key = 'complaints'\n            LIMIT 1\n        ");
        $permStmt->bind_param('s', $position);
        $permStmt->execute();
        $perm = $permStmt->get_result()->fetch_assoc();
        $permStmt->close();

        if ($perm !== null) {
            $authorized = ((int)($perm['is_allowed'] ?? 0) === 1);
        }
    }

    if (!$authorized) {
        complaint_json([
            'success' => true,
            'authorized' => false,
            'phase' => $phase,
            'latest_id' => 0,
            'server_latest_id' => 0,
            'counts' => [],
            'complaints' => [],
            'message' => 'This officer does not have Complaints access.',
        ]);
    }

    $afterId = max(0, (int)($_GET['after_id'] ?? 0));
    $bootstrap = ((string)($_GET['bootstrap'] ?? '') === '1');
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));

    $countStmt = $conn->prepare("\n        SELECT\n            COUNT(*) AS all_count,\n            SUM(status = 'open') AS open_count,\n            SUM(status = 'in_progress') AS in_progress_count,\n            SUM(status = 'resolved') AS resolved_count,\n            SUM(status = 'closed') AS closed_count,\n            COALESCE(MAX(id), 0) AS latest_id\n        FROM complaints\n        WHERE phase = ?\n    ");
    $countStmt->bind_param('s', $phase);
    $countStmt->execute();
    $countRow = $countStmt->get_result()->fetch_assoc() ?: [];
    $countStmt->close();

    $serverLatestId = (int)($countRow['latest_id'] ?? 0);
    $counts = [
        'all'         => (int)($countRow['all_count'] ?? 0),
        'open'        => (int)($countRow['open_count'] ?? 0),
        'in_progress' => (int)($countRow['in_progress_count'] ?? 0),
        'resolved'    => (int)($countRow['resolved_count'] ?? 0),
        'closed'      => (int)($countRow['closed_count'] ?? 0),
    ];

    if ($bootstrap) {
        complaint_json([
            'success' => true,
            'authorized' => true,
            'admin_id' => $adminId,
            'phase' => $phase,
            'latest_id' => $serverLatestId,
            'server_latest_id' => $serverLatestId,
            'counts' => $counts,
            'complaints' => [],
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    }

    $databaseResetDetected = ($afterId > $serverLatestId);
    if ($databaseResetDetected) {
        $afterId = $serverLatestId;
    }

    $sql = "\n        SELECT\n            c.id, c.subject, c.category, c.status, c.priority,\n            c.created_at, c.updated_at,\n            h.first_name, h.middle_name, h.last_name, h.house_lot_number,\n            (\n                SELECT COUNT(*)\n                FROM complaint_attachments ca\n                WHERE ca.complaint_id = c.id\n            ) AS evidence_count\n        FROM complaints c\n        LEFT JOIN homeowners h ON h.id = c.homeowner_id\n        WHERE c.phase = ? AND c.id > ?\n        ORDER BY c.id ASC\n        LIMIT {$limit}\n    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $phase, $afterId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $complaints = [];
    $latestId = $afterId;

    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $latestId = max($latestId, $id);

        $name = trim(
            (string)($row['first_name'] ?? '') . ' ' .
            (string)($row['middle_name'] ?? '') . ' ' .
            (string)($row['last_name'] ?? '')
        );
        $name = preg_replace('/\s+/', ' ', $name) ?: 'Unknown Homeowner';

        $complaints[] = [
            'id' => $id,
            'subject' => (string)($row['subject'] ?? 'Complaint'),
            'category' => (string)($row['category'] ?? ''),
            'status' => (string)($row['status'] ?? 'open'),
            'priority' => strtolower((string)($row['priority'] ?? 'normal')),
            'homeowner_name' => $name,
            'house_lot_number' => (string)($row['house_lot_number'] ?? ''),
            'evidence_count' => (int)($row['evidence_count'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'created_at_display' => complaint_display_datetime($row['created_at'] ?? null),
            'updated_at_display' => complaint_display_datetime($row['updated_at'] ?? null),
        ];
    }

    complaint_json([
        'success' => true,
        'authorized' => true,
        'admin_id' => $adminId,
        'phase' => $phase,
        'latest_id' => max($latestId, $serverLatestId),
        'server_latest_id' => $serverLatestId,
        'database_reset_detected' => $databaseResetDetected,
        'counts' => $counts,
        'complaints' => $complaints,
        'server_time' => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    error_log('Complaint realtime API error: ' . $e->getMessage());
    complaint_json([
        'success' => false,
        'authorized' => false,
        'message' => 'Complaint notification service error.',
    ], 500);
}
