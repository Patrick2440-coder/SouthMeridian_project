<?php
require_once __DIR__ . '/finance_helpers.php';
require_once __DIR__ . '/admin_access.php';
require_once __DIR__ . '/finance_audit_logger.php';
requireAccess('finance');
require_admin();
$conn = db_conn();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Manila');
if (!function_exists('esc')) {
    function esc($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);
$filterAction = trim((string)($_GET['action'] ?? ''));
$filterEntity = trim((string)($_GET['entity_type'] ?? ''));
$filterAdmin = max(0, (int)($_GET['admin_id'] ?? 0));
$filterFrom = trim((string)($_GET['from'] ?? ''));
$filterTo = trim((string)($_GET['to'] ?? ''));
$filterSearch = trim((string)($_GET['q'] ?? ''));
function financeLogValidDate(string $value): string {
    if ($value === '') return '';
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : '';
}
$filterFrom = financeLogValidDate($filterFrom);
$filterTo = financeLogValidDate($filterTo);
$auditReady = finance_audit_table_exists($conn);
$logs = [];
$actions = [];
$entityTypes = [];
$admins = [];
if ($auditReady) {
    $stmt = $conn->prepare("SELECT DISTINCT action FROM finance_audit_logs WHERE phase=? ORDER BY action");
    $stmt->bind_param('s', $phase);
    $stmt->execute();
    $actions = array_map(
        static fn($row) => (string)$row['action'],
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
    );
    $stmt->close();
    $stmt = $conn->prepare("SELECT DISTINCT entity_type FROM finance_audit_logs WHERE phase=? ORDER BY entity_type");
    $stmt->bind_param('s', $phase);
    $stmt->execute();
    $entityTypes = array_map(
        static fn($row) => (string)$row['entity_type'],
        $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
    );
    $stmt->close();
    $stmt = $conn->prepare("
        SELECT DISTINCT
            l.admin_id AS id,
            a.full_name,
            a.email
        FROM finance_audit_logs l
        LEFT JOIN admins a ON a.id = l.admin_id
        WHERE l.phase = ?
          AND l.admin_id IS NOT NULL
          AND l.admin_id > 0
        ORDER BY
            CASE WHEN a.full_name IS NULL OR TRIM(a.full_name) = '' THEN 1 ELSE 0 END,
            a.full_name,
            l.admin_id
    ");
    $stmt->bind_param('s', $phase);
    $stmt->execute();
    $admins = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $where = ['l.phase = ?'];
    $types = 's';
    $params = [$phase];
    if ($filterAction !== '') {
        $where[] = 'l.action = ?';
        $types .= 's';
        $params[] = $filterAction;
    }
    if ($filterEntity !== '') {
        $where[] = 'l.entity_type = ?';
        $types .= 's';
        $params[] = $filterEntity;
    }
    if ($filterAdmin > 0) {
        $where[] = 'l.admin_id = ?';
        $types .= 'i';
        $params[] = $filterAdmin;
    }
    if ($filterFrom !== '') {
        $where[] = 'DATE(l.created_at) >= ?';
        $types .= 's';
        $params[] = $filterFrom;
    }
    if ($filterTo !== '') {
        $where[] = 'DATE(l.created_at) <= ?';
        $types .= 's';
        $params[] = $filterTo;
    }
    if ($filterSearch !== '') {
        $where[] = '(
            l.action LIKE ?
            OR l.entity_type LIKE ?
            OR l.details LIKE ?
            OR l.batch_id LIKE ?
            OR l.ip_address LIKE ?
            OR a.full_name LIKE ?
            OR a.email LIKE ?
        )';
        $types .= 'sssssss';
        $like = '%' . $filterSearch . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    $sql = "
        SELECT
            l.*,
            a.full_name AS admin_name,
            a.email AS admin_email
        FROM finance_audit_logs l
        LEFT JOIN admins a ON a.id=l.admin_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.id DESC
        LIMIT 500
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare finance activity log query.');
    }
    if ($params) {
        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }
        unset($value);
        $stmt->bind_param($types, ...$refs);
    }
    $stmt->execute();
    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
function financeLogDecodeJson(?string $json) {
    $json = trim((string)$json);
    if ($json === '') {
        return null;
    }
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $json;
    }
    return $decoded;
}
function financeLogIsList(array $value): bool {
    if (function_exists('array_is_list')) {
        return array_is_list($value);
    }
    $expected = 0;
    foreach (array_keys($value) as $key) {
        if ($key !== $expected++) {
            return false;
        }
    }
    return true;
}
function financeLogFieldLabel(string $key): string {
    $labels = [
        'id' => 'Record ID',
        'admin_id' => 'Admin ID',
        'homeowner_id' => 'Homeowner ID',
        'payment_id' => 'Payment ID',
        'expense_id' => 'Expense ID',
        'report_request_id' => 'Report Request ID',
        'monthly_dues' => 'Monthly Dues',
        'pay_year' => 'Payment Year',
        'pay_month' => 'Payment Month',
        'report_year' => 'Report Year',
        'report_month' => 'Report Month',
        'paid_at' => 'Paid Date / Time',
        'created_at' => 'Created Date / Time',
        'updated_at' => 'Updated Date / Time',
        'expense_date' => 'Expense Date',
        'donation_date' => 'Donation Date',
        'reference_no' => 'Reference / OR Number',
        'receipt_no' => 'Receipt Number',
        'receipt_path' => 'Proof / Receipt File',
        'proof_path' => 'Proof / Receipt File',
        'proof_original_name' => 'Original Proof Filename',
        'created_by_admin_id' => 'Recorded By Admin ID',
        'requested_by_admin_id' => 'Requested By Admin ID',
        'requested_by' => 'Requested By',
        'vendor_payee' => 'Supplier / Payee',
        'project_name' => 'Project / Activity',
        'payment_method' => 'Payment Method',
        'detail_type' => 'Expense Detail Type',
        'report_type' => 'Report Type',
        'date_from' => 'Date From',
        'date_to' => 'Date To',
        'target_id' => 'Target Record ID',
        'target_type' => 'Target Type',
        'request_purpose' => 'Request Purpose',
        'scope_hash' => 'Internal Scope Reference',
        'status' => 'Status',
        'phase' => 'Phase',
        'amount' => 'Amount',
        'opening_balance' => 'Opening Balance',
        'unit_cost' => 'Unit Cost',
        'line_total' => 'Line Total',
        'notes' => 'Notes',
        'description' => 'Description',
        'category' => 'Category',
        'items' => 'Items',
    ];
    return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
}
function financeLogReadableCode(string $value): string {
    return ucwords(str_replace(['_', '-'], ' ', trim($value)));
}
function financeLogDisplayValue(string $key, $value): string {
    if ($value === null || $value === '') {
        return 'Not set';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    if (
        in_array(
            $key,
            ['amount', 'monthly_dues', 'opening_balance', 'unit_cost', 'line_total'],
            true
        ) &&
        is_numeric($value)
    ) {
        return '₱ ' . number_format((float)$value, 2);
    }
    if (in_array($key, ['pay_month', 'report_month'], true) && is_numeric($value)) {
        $month = (int)$value;
        if ($month >= 1 && $month <= 12) {
            return date('F', mktime(0, 0, 0, $month, 1)) . ' (' . $month . ')';
        }
    }
    if (
        in_array(
            $key,
            ['status', 'category', 'detail_type', 'payment_method', 'report_type', 'target_type'],
            true
        )
    ) {
        return financeLogReadableCode((string)$value);
    }
    if (substr($key, -3) === '_id' && is_numeric($value)) {
        return '#' . (int)$value;
    }
    if (is_float($value)) {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
    return (string)$value;
}
function financeLogFlattenData($data, string $prefix = ''): array {
    if (!is_array($data)) {
        return [];
    }
    $flat = [];
    foreach ($data as $key => $value) {
        $rawKey = (string)$key;
        $keyLabel = is_int($key)
            ? 'Item ' . ((int)$key + 1)
            : financeLogFieldLabel($rawKey);
        $label = $prefix !== ''
            ? $prefix . ' → ' . $keyLabel
            : $keyLabel;
        if (is_array($value)) {
            if ($value === []) {
                $flat[$label] = 'None';
                continue;
            }
            if (financeLogIsList($value)) {
                $allScalar = true;
                foreach ($value as $listValue) {
                    if (is_array($listValue) || is_object($listValue)) {
                        $allScalar = false;
                        break;
                    }
                }
                if ($allScalar) {
                    $flat[$label] = implode(
                        ', ',
                        array_map(
                            static fn($item) => $item === null || $item === '' ? 'Not set' : (string)$item,
                            $value
                        )
                    );
                    continue;
                }
            }
            $nested = financeLogFlattenData($value, $label);
            if ($nested) {
                $flat += $nested;
            } else {
                $flat[$label] = 'Recorded structured data';
            }
            continue;
        }
        $flat[$label] = financeLogDisplayValue($rawKey, $value);
    }
    return $flat;
}
function financeLogBuildChangeRows($beforeData, $afterData): array {
    $before = is_array($beforeData) ? financeLogFlattenData($beforeData) : [];
    $after = is_array($afterData) ? financeLogFlattenData($afterData) : [];
    $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
    $rows = [];
    foreach ($keys as $label) {
        $hasBefore = array_key_exists($label, $before);
        $hasAfter = array_key_exists($label, $after);
        $oldValue = $hasBefore ? $before[$label] : 'Not set';
        $newValue = $hasAfter ? $after[$label] : 'Not set';
        if ($hasBefore && $hasAfter && $oldValue === $newValue) {
            continue;
        }
        $rows[] = [
            'label' => $label,
            'before' => $oldValue,
            'after' => $newValue,
        ];
    }
    return $rows;
}
function financeLogAdminLabel(array $row): string {
    $adminId = (int)($row['admin_id'] ?? 0);
    $name = trim((string)($row['admin_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return $adminId > 0
        ? 'Former / Deleted Admin #' . $adminId
        : 'System / Unknown Admin';
}
function financeLogEntityLabel(?string $entityType): string {
    $entityType = trim((string)$entityType);
    return $entityType === '' ? 'Unknown Record' : financeLogReadableCode($entityType);
}
function financeLogNormalizeIp(string $ip): string {
    $ip = trim($ip);
    if (stripos($ip, '::ffff:') === 0) {
        $mapped = substr($ip, 7);
        if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $mapped;
        }
    }
    return $ip;
}
function financeLogIpMeta(?string $ip): array {
    $raw = trim((string)$ip);
    if ($raw === '') {
        return [
            'address' => 'Not recorded',
            'label' => 'No IP address was stored for this action.',
        ];
    }
    $normalized = financeLogNormalizeIp($raw);
    if ($normalized === '127.0.0.1' || $normalized === '::1') {
        return [
            'address' => $normalized,
            'label' => 'Localhost — the action came from the same computer/server running the system.',
        ];
    }
    if (!filter_var($normalized, FILTER_VALIDATE_IP)) {
        return [
            'address' => $raw,
            'label' => 'Stored network value could not be classified as a standard IP address.',
        ];
    }
    $isPublic = filter_var(
        $normalized,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
    if ($isPublic !== false) {
        return [
            'address' => $normalized,
            'label' => 'Public internet IP address recorded by the server.',
        ];
    }
    return [
        'address' => $normalized,
        'label' => 'Private / local network address. It may only identify the device inside the HOA network.',
    ];
}
function financeLogDeviceLabel(?string $userAgent): string {
    $ua = trim((string)$userAgent);
    if ($ua === '') {
        return 'Device information not recorded';
    }
    $platform = 'Unknown device';
    if (stripos($ua, 'Windows') !== false) {
        $platform = 'Windows';
    } elseif (stripos($ua, 'Android') !== false) {
        $platform = 'Android';
    } elseif (stripos($ua, 'iPhone') !== false) {
        $platform = 'iPhone';
    } elseif (stripos($ua, 'iPad') !== false) {
        $platform = 'iPad';
    } elseif (
        stripos($ua, 'Macintosh') !== false ||
        stripos($ua, 'Mac OS X') !== false
    ) {
        $platform = 'macOS';
    } elseif (stripos($ua, 'Linux') !== false) {
        $platform = 'Linux';
    }
    $browser = 'Browser';
    if (stripos($ua, 'Edg/') !== false) {
        $browser = 'Microsoft Edge';
    } elseif (
        stripos($ua, 'OPR/') !== false ||
        stripos($ua, 'Opera') !== false
    ) {
        $browser = 'Opera';
    } elseif (stripos($ua, 'Chrome/') !== false) {
        $browser = 'Google Chrome';
    } elseif (stripos($ua, 'Firefox/') !== false) {
        $browser = 'Mozilla Firefox';
    } elseif (
        stripos($ua, 'Safari/') !== false &&
        stripos($ua, 'Chrome/') === false
    ) {
        $browser = 'Safari';
    }
    return $browser . ' on ' . $platform;
}

function financeLogFindProofPath($data): string {
    if (!is_array($data)) {
        return '';
    }

    $preferredKeys = [
        'proof_path',
        'receipt_path',
        'proof',
        'receipt',
        'attachment_path',
        'file_path',
    ];

    foreach ($preferredKeys as $preferredKey) {
        foreach ($data as $key => $value) {
            if (
                is_string($key) &&
                strtolower($key) === $preferredKey &&
                is_string($value) &&
                trim($value) !== ''
            ) {
                return trim($value);
            }
        }
    }

    foreach ($data as $value) {
        if (is_array($value)) {
            $found = financeLogFindProofPath($value);
            if ($found !== '') {
                return $found;
            }
        }
    }

    return '';
}

function financeLogProofKind(string $path): string {
    $cleanPath = parse_url($path, PHP_URL_PATH);
    $extension = strtolower(pathinfo((string)$cleanPath, PATHINFO_EXTENSION));

    if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return 'image';
    }

    if ($extension === 'pdf') {
        return 'pdf';
    }

    return $path !== '' ? 'file' : 'none';
}

function financeLogHasPreviousData($beforeData): bool {
    if (is_array($beforeData)) {
        return !empty(financeLogFlattenData($beforeData));
    }

    return is_string($beforeData) && trim($beforeData) !== '';
}

function financeLogRecordedRows($afterData): array {
    if (!is_array($afterData)) {
        return [];
    }

    $flat = financeLogFlattenData($afterData);
    $rows = [];

    foreach ($flat as $label => $value) {
        $rows[] = [
            'label' => $label,
            'value' => $value,
        ];
    }

    return $rows;
}

$auditModalPayload = [];

foreach ($logs as $log) {
    $logId = (int)($log['id'] ?? 0);
    $beforeData = financeLogDecodeJson($log['before_data'] ?? null);
    $afterData = financeLogDecodeJson($log['after_data'] ?? null);
    $hasPrevious = financeLogHasPreviousData($beforeData);

    $proofPath = financeLogFindProofPath($afterData);
    if ($proofPath === '') {
        $proofPath = financeLogFindProofPath($beforeData);
    }

    $auditModalPayload[(string)$logId] = [
        'id' => $logId,
        'created_at' => (string)($log['created_at'] ?? ''),
        'admin' => financeLogAdminLabel($log),
        'admin_email' => (string)($log['admin_email'] ?? ''),
        'action' => (string)($log['action'] ?? ''),
        'record_type' => financeLogEntityLabel($log['entity_type'] ?? ''),
        'record_id' => isset($log['entity_id']) && (int)$log['entity_id'] > 0
            ? (int)$log['entity_id']
            : null,
        'batch_id' => (string)($log['batch_id'] ?? ''),
        'details' => trim((string)($log['details'] ?? '')),
        'has_previous' => $hasPrevious,
        'changes' => $hasPrevious
            ? financeLogBuildChangeRows($beforeData, $afterData)
            : [],
        'recorded_rows' => !$hasPrevious
            ? financeLogRecordedRows($afterData)
            : [],
        'legacy_before' => is_string($beforeData) ? $beforeData : '',
        'legacy_after' => is_string($afterData) ? $afterData : '',
        'proof_path' => $proofPath,
        'proof_kind' => financeLogProofKind($proofPath),
        'ip' => financeLogIpMeta($log['ip_address'] ?? null),
        'device' => financeLogDeviceLabel($log['user_agent'] ?? null),
        'user_agent' => (string)($log['user_agent'] ?? ''),
    ];
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Finance Activity Log - HOA Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
    <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">
    <style>
        .log-filter-card { border:1px solid #e5e7eb; border-radius:12px; }
        .log-detail { min-width:320px; max-width:520px; }
        .log-meta { font-size:12px; color:#6c757d; }
        .log-pre {
            white-space:pre-wrap;
            word-break:break-word;
            max-height:260px;
            overflow:auto;
            margin:8px 0 0;
            padding:10px;
            border-radius:8px;
            background:#f8f9fa;
            border:1px solid #e5e7eb;
            font-size:12px;
        }
        /* Finance Activity Log - dark mode extensions */
        html.dark body,
        html.dark .main-container {
            background: var(--admin-bg, #0f172a) !important;
            color: var(--admin-text, #e5e7eb) !important;
        }
        html.dark .header,
        html.dark .header-left,
        html.dark .header-right {
            background: var(--admin-surface, #1f2937) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border-color: var(--admin-border, #374151) !important;
        }
        html.dark .page-header,
        html.dark .card-box,
        html.dark .footer-wrap,
        html.dark .log-filter-card {
            background: var(--admin-surface, #1f2937) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border-color: var(--admin-border, #374151) !important;
        }
        html.dark .page-header h1,
        html.dark .page-header h2,
        html.dark .page-header h3,
        html.dark .page-header h4,
        html.dark .page-header h5,
        html.dark .page-header h6,
        html.dark .page-header .title,
        html.dark .page-header .title h4,
        html.dark .card-box h1,
        html.dark .card-box h2,
        html.dark .card-box h3,
        html.dark .card-box h4,
        html.dark .card-box h5,
        html.dark .card-box h6,
        html.dark .card-box label,
        html.dark .card-box strong,
        html.dark .card-box b,
        html.dark .footer-wrap {
            color: var(--admin-text, #e5e7eb) !important;
        }
        html.dark .text-secondary,
        html.dark .text-muted,
        html.dark .log-meta,
        html.dark small.text-secondary {
            color: var(--admin-muted, #9ca3af) !important;
        }
        html.dark .form-control,
        html.dark select.form-control,
        html.dark input.form-control,
        html.dark textarea.form-control {
            background: var(--admin-input, #111827) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border-color: var(--admin-border, #4b5563) !important;
        }
        html.dark .form-control:focus,
        html.dark select.form-control:focus,
        html.dark input.form-control:focus,
        html.dark textarea.form-control:focus {
            background: var(--admin-input, #111827) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border-color:#3b82f6 !important;
            box-shadow:0 0 0 .2rem rgba(59,130,246,.16) !important;
        }
        html.dark .form-control::placeholder {
            color:#64748b !important;
            opacity:1;
        }
        html.dark select.form-control option {
            background:#111827 !important;
            color:#e5e7eb !important;
        }
        html.dark input[type="date"] {
            color-scheme: dark;
        }
        html.dark .btn-outline-secondary {
            color:#cbd5e1 !important;
            border-color:#64748b !important;
        }
        html.dark .btn-outline-secondary:hover {
            background:#475569 !important;
            border-color:#64748b !important;
            color:#fff !important;
        }
        html.dark .table,
        html.dark table.dataTable {
            color: var(--admin-text, #e5e7eb) !important;
            background: var(--admin-surface, #1f2937) !important;
            border-color: var(--admin-border, #374151) !important;
        }
        html.dark .table thead th,
        html.dark table.dataTable thead th,
        html.dark table.dataTable thead td {
            background: var(--admin-surface-2, #253244) !important;
            color:#f8fafc !important;
            border-color: var(--admin-border, #374151) !important;
        }
        html.dark .table tbody tr,
        html.dark .table tbody td,
        html.dark .table tbody th,
        html.dark table.dataTable tbody tr,
        html.dark table.dataTable tbody td {
            background: var(--admin-surface, #1f2937) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border-color: var(--admin-border, #374151) !important;
        }
        html.dark .table-striped tbody tr:nth-of-type(odd),
        html.dark .table-striped tbody tr:nth-of-type(odd) > *,
        html.dark table.dataTable.stripe tbody tr.odd,
        html.dark table.dataTable.display tbody tr.odd {
            background: var(--admin-surface-2, #253244) !important;
            color: var(--admin-text, #e5e7eb) !important;
        }
        html.dark .table-striped tbody tr:nth-of-type(even),
        html.dark .table-striped tbody tr:nth-of-type(even) > * {
            background: var(--admin-surface, #1f2937) !important;
            color: var(--admin-text, #e5e7eb) !important;
        }
        html.dark .table-hover tbody tr:hover,
        html.dark .table-hover tbody tr:hover > *,
        html.dark table.dataTable tbody tr:hover,
        html.dark table.dataTable tbody tr:hover > * {
            background: var(--admin-hover, #334155) !important;
            color:#fff !important;
        }
        html.dark #financeActivityTable tbody td.text-secondary,
        html.dark #financeActivityTable tbody tr td[colspan] {
            background: var(--admin-surface, #1f2937) !important;
            color: var(--admin-muted, #9ca3af) !important;
        }
        html.dark .dataTables_wrapper,
        html.dark .dataTables_wrapper .dataTables_length,
        html.dark .dataTables_wrapper .dataTables_filter,
        html.dark .dataTables_wrapper .dataTables_info,
        html.dark .dataTables_wrapper .dataTables_paginate {
            color: var(--admin-muted, #9ca3af) !important;
        }
        html.dark .dataTables_wrapper .dataTables_filter input,
        html.dark .dataTables_wrapper .dataTables_length select {
            background: var(--admin-input, #111827) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border:1px solid var(--admin-border, #4b5563) !important;
        }
        html.dark .dataTables_wrapper .dataTables_paginate .paginate_button {
            color: var(--admin-text, #e5e7eb) !important;
        }
        html.dark .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        html.dark .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover,
        html.dark .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            color:#fff !important;
            border-color:#2563eb !important;
            background:#2563eb !important;
        }
        html.dark .log-pre {
            background: var(--admin-input, #111827) !important;
            border-color: var(--admin-border, #4b5563) !important;
            color: var(--admin-text, #e5e7eb) !important;
        }
        html.dark details summary {
            color:#93c5fd !important;
        }
        html.dark .dropdown-menu {
            background: var(--admin-surface, #1f2937) !important;
            border-color: var(--admin-border, #374151) !important;
        }
        html.dark .dropdown-item {
            color: var(--admin-text, #e5e7eb) !important;
        }
        html.dark .dropdown-item:hover {
            background: var(--admin-hover, #334155) !important;
            color:#fff !important;
        }
        .log-summary-text {
            line-height: 1.5;
            font-weight: 500;
        }
        .audit-history-note {
            padding: 10px 12px;
            border: 1px solid #dbeafe;
            border-radius: 9px;
            background: #eff6ff;
            color: #334155;
            font-size: 12px;
            line-height: 1.5;
        }
        .finance-audit-modal .modal-dialog {
            max-width: 1120px;
        }

        .finance-audit-modal .modal-content {
            border: 0;
            border-radius: 14px;
            overflow: hidden;
        }

        .audit-section-heading {
            margin-bottom: 10px;
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #475569;
        }

        .audit-proof-section {
            padding: 16px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
        }

        .audit-proof-preview {
            width: 100%;
            min-height: 260px;
            max-height: 560px;
            overflow: hidden;
            border-radius: 10px;
            background: #0f172a;
            text-align: center;
        }

        .audit-proof-preview img {
            display: block;
            width: 100%;
            max-height: 560px;
            object-fit: contain;
            margin: 0 auto;
            background: #0f172a;
        }

        .audit-proof-pdf iframe {
            width: 100%;
            height: 520px;
            border: 0;
            border-radius: 10px;
            background: #fff;
        }

        .audit-proof-file {
            padding: 18px;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            text-align: center;
        }

        .audit-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .audit-summary-card {
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
        }

        .audit-summary-card span,
        .audit-network-box span {
            display: block;
            margin-bottom: 4px;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        .audit-summary-card strong,
        .audit-network-box strong {
            display: block;
            color: #0f172a;
            font-size: 13px;
            line-height: 1.4;
        }

        .audit-summary-card small,
        .audit-network-box small {
            display: block;
            margin-top: 4px;
            color: #64748b;
            line-height: 1.4;
            word-break: break-word;
        }

        .audit-modal-description {
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
            line-height: 1.55;
            word-break: break-word;
        }

        .audit-modal-table {
            font-size: 12px;
        }

        .audit-modal-table th {
            white-space: nowrap;
        }

        .audit-modal-table td {
            vertical-align: top !important;
            white-space: normal;
            word-break: break-word;
        }

        .audit-help-text {
            color: #64748b;
            font-size: 11px;
            line-height: 1.45;
        }

        .audit-network-box {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .audit-network-box > div {
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
        }

        html.dark .finance-audit-modal .modal-content,
        html.dark .finance-audit-modal .modal-header,
        html.dark .finance-audit-modal .modal-footer {
            background: var(--admin-surface, #1f2937) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border-color: var(--admin-border, #374151) !important;
        }

        html.dark .finance-audit-modal .close {
            color: #fff !important;
            text-shadow: none !important;
        }

        html.dark .audit-section-heading {
            color: #cbd5e1 !important;
        }

        html.dark .audit-proof-section,
        html.dark .audit-summary-card,
        html.dark .audit-modal-description,
        html.dark .audit-network-box > div {
            background: var(--admin-surface-2, #253244) !important;
            border-color: var(--admin-border, #374151) !important;
            color: var(--admin-text, #e5e7eb) !important;
        }

        html.dark .audit-summary-card span,
        html.dark .audit-summary-card small,
        html.dark .audit-network-box span,
        html.dark .audit-network-box small,
        html.dark .audit-help-text {
            color: var(--admin-muted, #9ca3af) !important;
        }

        html.dark .audit-summary-card strong,
        html.dark .audit-network-box strong {
            color: var(--admin-text, #e5e7eb) !important;
        }

        html.dark .audit-modal-table,
        html.dark .audit-modal-table th,
        html.dark .audit-modal-table td {
            border-color: var(--admin-border, #374151) !important;
        }

        html.dark .audit-modal-table th {
            background: var(--admin-surface-3, #334155) !important;
            color: #f8fafc !important;
        }

        html.dark .audit-modal-table td {
            background: var(--admin-surface, #1f2937) !important;
            color: var(--admin-text, #e5e7eb) !important;
        }

        @media (max-width: 767.98px) {
            .audit-summary-grid,
            .audit-network-box {
                grid-template-columns: 1fr;
            }

            .audit-proof-preview {
                min-height: 180px;
            }

            .audit-proof-pdf iframe {
                height: 420px;
            }
        }
        .network-cell {
            min-width: 250px;
            max-width: 330px;
        }
        .device-friendly {
            font-size: 12px;
            line-height: 1.45;
        }
        .user-agent-text {
            word-break: break-word;
            line-height: 1.45;
        }
        .admin-page-logout-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 98px;
            min-height: 40px;
            margin: 0 18px 0 10px;
            padding: 8px 14px;
            border: 1px solid #fecaca;
            border-radius: 11px;
            background: #fff;
            color: #b91c1c !important;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .06);
            font-size: 12px;
            line-height: 1;
            font-weight: 800;
            text-decoration: none !important;
            white-space: nowrap;
            transition: background .18s ease, color .18s ease, border-color .18s ease,
                        transform .18s ease, box-shadow .18s ease;
        }
        .admin-page-logout-btn i {
            font-size: 17px;
            line-height: 1;
        }
        .admin-page-logout-btn:hover,
        .admin-page-logout-btn:focus {
            border-color: #ef4444;
            background: #fef2f2;
            color: #991b1b !important;
            box-shadow: 0 7px 18px rgba(220, 38, 38, .12);
            transform: translateY(-1px);
            outline: none;
        }
        html.dark .audit-history-note {
            border-color: rgba(96, 165, 250, .25) !important;
            background: rgba(30, 64, 175, .12) !important;
            color: #bfdbfe !important;
        }
        html.dark .admin-page-logout-btn {
            border-color: rgba(248, 113, 113, .30);
            background: rgba(127, 29, 29, .16);
            color: #fca5a5 !important;
            box-shadow: none;
        }
        html.dark .admin-page-logout-btn:hover,
        html.dark .admin-page-logout-btn:focus {
            border-color: rgba(248, 113, 113, .55);
            background: rgba(127, 29, 29, .28);
            color: #fecaca !important;
        }
        @media (max-width: 575.98px) {
            .admin-page-logout-btn {
                width: 40px;
                min-width: 40px;
                height: 40px;
                min-height: 40px;
                margin: 0 10px 0 6px;
                padding: 0;
                border-radius: 10px;
            }
            .admin-page-logout-btn span {
                display: none;
            }
            .admin-page-logout-btn i {
                font-size: 18px;
            }
        }
        .admin-theme-switch { display:flex; align-items:center; padding:0 10px; }
        .admin-theme-toggle { border:0; background:transparent; font-size:22px; cursor:pointer; }
        html.dark .admin-theme-toggle { color:#f8fafc !important; }
    </style>
    <script>
    (function () {
        try {
            const savedTheme = localStorage.getItem('hoa-theme');
            const dark = savedTheme === 'dark' || (!savedTheme && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        } catch (e) {}
    })();
    </script>
</head>
<body>
<div class="header">
    <div class="header-left">
        <div class="menu-icon dw dw-menu"></div>
        <div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>
    </div>
    <div class="header-right">
        <div class="admin-theme-switch">
            <button type="button" id="themeToggle" class="admin-theme-toggle" aria-label="Switch theme" title="Switch theme"><span id="themeIcon">☾</span></button>
        </div>
        <a href="logout.php"
           class="admin-page-logout-btn"
           title="Log out"
           aria-label="Log out">
            <i class="dw dw-logout" aria-hidden="true"></i>
            <span>Log Out</span>
        </a>
    </div>
</div>
<?php include 'sidebar.php'; ?>
<div class="main-container">
    <div class="pd-ltr-20">
        <div class="page-header mb-20">
            <div class="row align-items-center">
                <div class="col-md-6 col-sm-12">
                    <div class="title"><h4>Finance Activity Log</h4></div>
                    <div class="text-secondary">Phase: <b><?= esc($phase) ?></b> • Read-only audit history</div>
                </div>
                <div class="col-md-6 col-sm-12 text-right">
                    <?php if ($canPickPhase): ?>
                        <form method="get" class="d-inline-block">
                            <select name="phase" class="form-control d-inline-block" style="width:200px" onchange="this.form.submit()">
                                <?php foreach (['Phase 1','Phase 2','Phase 3'] as $p): ?>
                                    <option value="<?= esc($p) ?>" <?= $p === $phase ? 'selected' : '' ?>><?= esc($p) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if (!$auditReady): ?>
            <div class="alert alert-warning">
                Finance audit logging is not installed yet. Run the latest <b>finance_dues_migration_setup.sql</b> first.
            </div>
        <?php else: ?>
            <div class="card-box mb-20 p-3 log-filter-card">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="mb-1">Filter Activity</h5>
                        <div class="text-secondary">Search by admin, action, record type, date, batch, or details.</div>
                    </div>
                    <span class="badge badge-primary p-2">Showing up to 500 records</span>
                </div>
                <form method="get">
                    <?php if ($canPickPhase): ?><input type="hidden" name="phase" value="<?= esc($phase) ?>"><?php endif; ?>
                    <div class="row">
                        <div class="col-lg-3 col-md-6 mb-2">
                            <label>Search</label>
                            <input type="text" name="q" class="form-control" value="<?= esc($filterSearch) ?>" placeholder="Action, admin, batch, details, IP...">
                        </div>
                        <div class="col-lg-2 col-md-6 mb-2">
                            <label>Action</label>
                            <select name="action" class="form-control">
                                <option value="">All actions</option>
                                <?php foreach ($actions as $action): ?>
                                    <option value="<?= esc($action) ?>" <?= $filterAction === $action ? 'selected' : '' ?>><?= esc($action) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-6 mb-2">
                            <label>Record Type</label>
                            <select name="entity_type" class="form-control">
                                <option value="">All record types</option>
                                <?php foreach ($entityTypes as $entity): ?>
                                    <option value="<?= esc($entity) ?>" <?= $filterEntity === $entity ? 'selected' : '' ?>>
                                        <?= esc(financeLogEntityLabel($entity)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-6 mb-2">
                            <label>Admin</label>
                            <select name="admin_id" class="form-control">
                                <option value="0">All admins</option>
                                <?php foreach ($admins as $admin): ?>
                                    <?php
                                    $adminOptionId = (int)($admin['id'] ?? 0);
                                    $adminOptionName = trim((string)($admin['full_name'] ?? ''));
                                    $adminOptionLabel = $adminOptionName !== ''
                                        ? $adminOptionName
                                        : 'Former / Deleted Admin #' . $adminOptionId;
                                    ?>
                                    <option value="<?= $adminOptionId ?>" <?= $filterAdmin === $adminOptionId ? 'selected' : '' ?>>
                                        <?= esc($adminOptionLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-1 col-md-6 mb-2">
                            <label>From</label>
                            <input type="date" name="from" class="form-control" value="<?= esc($filterFrom) ?>">
                        </div>
                        <div class="col-lg-1 col-md-6 mb-2">
                            <label>To</label>
                            <input type="date" name="to" class="form-control" value="<?= esc($filterTo) ?>">
                        </div>
                        <div class="col-lg-1 col-md-12 mb-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-block">Filter</button>
                        </div>
                    </div>
                    <div class="mt-2">
                        <a href="finance_activity_log.php<?= $canPickPhase ? '?phase=' . urlencode($phase) : '' ?>" class="btn btn-sm btn-outline-secondary">Clear Filters</a>
                    </div>
                </form>
            </div>
            <div class="card-box mb-20 p-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="mb-1">Recorded Finance Actions</h5>
                        <div class="text-secondary">Logs are read-only and should not be manually edited or deleted.</div>
                    </div>
                    <span class="badge badge-info p-2"><?= count($logs) ?> record<?= count($logs) === 1 ? '' : 's' ?></span>
                </div>
                <div class="audit-history-note mb-3">
                    Historical logs remain visible even if an admin account is later removed.
                    If the current admin profile no longer exists, the log shows the stored Admin ID as
                    <strong>Former / Deleted Admin</strong>. Older logs may not contain a saved historical name or email.
                </div>
                <div class="table-responsive">
                    <table id="financeActivityTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Date / Time</th>
                                <th>Admin</th>
                                <th>Action</th>
                                <th>Record</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$logs): ?>
                            <tr><td colspan="5" class="text-center text-secondary">No finance activity matched the selected filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                $logId = (int)($log['id'] ?? 0);
                                $adminDisplay = financeLogAdminLabel($log);
                                $ipMeta = financeLogIpMeta($log['ip_address'] ?? null);
                                $deviceLabel = financeLogDeviceLabel($log['user_agent'] ?? null);
                                ?>
                                <tr>
                                    <td data-order="<?= esc($log['created_at'] ?? '') ?>">
                                        <strong><?= esc($log['created_at'] ?? '') ?></strong>
                                    </td>
                                    <td>
                                        <strong><?= esc($adminDisplay) ?></strong>
                                        <?php if (!empty($log['admin_email'])): ?>
                                            <div class="log-meta"><?= esc($log['admin_email']) ?></div>
                                        <?php elseif ((int)($log['admin_id'] ?? 0) > 0): ?>
                                            <div class="log-meta">Current admin account is no longer available.</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-primary"><?= esc($log['action'] ?? '') ?></span></td>
                                    <td>
                                        <strong><?= esc(financeLogEntityLabel($log['entity_type'] ?? '')) ?></strong>
                                        <?php if (!empty($log['entity_id'])): ?><div class="log-meta">Record #<?= (int)$log['entity_id'] ?></div><?php endif; ?>
                                        <?php if (!empty($log['batch_id'])): ?><div class="log-meta">Batch: <?= esc($log['batch_id']) ?></div><?php endif; ?>
                                    </td>
                                    <td class="log-detail">
                                        <?php if (trim((string)($log['details'] ?? '')) !== ''): ?>
                                            <div class="log-summary-text"><?= esc($log['details']) ?></div>
                                        <?php else: ?>
                                            <div class="text-secondary">Finance activity record</div>
                                        <?php endif; ?>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary mt-2 view-finance-audit"
                                            data-log-id="<?= $logId ?>"
                                        >
                                            <i class="dw dw-eye"></i>
                                            View Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal fade finance-audit-modal" id="financeAuditModal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title mb-1" id="auditModalTitle">Finance Activity Details</h5>
                                <div class="text-secondary small" id="auditModalSubtitle"></div>
                            </div>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>

                        <div class="modal-body">
                            <div id="auditProofSection" class="audit-proof-section mb-4" style="display:none;">
                                <div class="audit-section-heading">Proof / Receipt</div>

                                <div id="auditProofImageWrap" class="audit-proof-preview" style="display:none;">
                                    <a id="auditProofImageLink" href="#" target="_blank" rel="noopener noreferrer">
                                        <img id="auditProofImage" src="" alt="Finance proof preview">
                                    </a>
                                </div>

                                <div id="auditProofPdfWrap" class="audit-proof-pdf" style="display:none;">
                                    <iframe id="auditProofPdf" src="" title="Finance proof PDF preview"></iframe>
                                </div>

                                <div id="auditProofFileWrap" class="audit-proof-file" style="display:none;">
                                    <a id="auditProofFileLink" class="btn btn-outline-primary" href="#" target="_blank" rel="noopener noreferrer">
                                        Open Proof File
                                    </a>
                                </div>
                            </div>

                            <div class="audit-summary-grid mb-4">
                                <div class="audit-summary-card">
                                    <span>Date / Time</span>
                                    <strong id="auditDate">-</strong>
                                </div>
                                <div class="audit-summary-card">
                                    <span>Admin</span>
                                    <strong id="auditAdmin">-</strong>
                                    <small id="auditAdminEmail"></small>
                                </div>
                                <div class="audit-summary-card">
                                    <span>Action</span>
                                    <strong id="auditAction">-</strong>
                                </div>
                                <div class="audit-summary-card">
                                    <span>Record</span>
                                    <strong id="auditRecord">-</strong>
                                    <small id="auditBatch"></small>
                                </div>
                            </div>

                            <div class="audit-section-heading">Activity Summary</div>
                            <div class="audit-modal-description mb-4" id="auditDescription">
                                No additional description was recorded.
                            </div>

                            <div id="auditRecordedSection" class="mb-4" style="display:none;">
                                <div class="audit-section-heading">Recorded Information</div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered audit-modal-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Field</th>
                                                <th>Recorded Value</th>
                                            </tr>
                                        </thead>
                                        <tbody id="auditRecordedBody"></tbody>
                                    </table>
                                </div>
                                <div class="audit-help-text mt-2">
                                    This was a newly recorded action, so there is no previous value to compare.
                                </div>
                            </div>

                            <div id="auditChangesSection" class="mb-4" style="display:none;">
                                <div class="audit-section-heading">Changes Made</div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered audit-modal-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Field</th>
                                                <th>Before Change</th>
                                                <th>New / Recorded Value</th>
                                            </tr>
                                        </thead>
                                        <tbody id="auditChangesBody"></tbody>
                                    </table>
                                </div>
                                <div class="audit-help-text mt-2">
                                    “Before Change” shows the saved value before this finance action. It is kept for audit history so admins can see exactly what changed.
                                </div>
                            </div>

                            <div id="auditLegacySection" class="mb-4" style="display:none;">
                                <div class="audit-section-heading">Legacy Recorded Data</div>
                                <div id="auditLegacyContent" class="audit-modal-description"></div>
                            </div>

                            <div class="audit-section-heading">Network / Device Information</div>
                            <div class="audit-network-box">
                                <div>
                                    <span>IP Address</span>
                                    <strong id="auditIpAddress">-</strong>
                                    <small id="auditIpDescription"></small>
                                </div>
                                <div>
                                    <span>Device</span>
                                    <strong id="auditDevice">-</strong>
                                    <small id="auditUserAgent"></small>
                                </div>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>
        <div class="footer-wrap pd-20 mb-20 card-box">© Copyright South Meridian Homes All Rights Reserved</div>
    </div>
</div>
<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>
<script src="vendors/scripts/admin_theme.js"></script>
<script>
const financeAuditDetails = <?= json_encode(
    $auditModalPayload,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>;

(function () {
    function text(id, value) {
        const element = document.getElementById(id);
        if (!element) {
            return;
        }

        const normalized = value === null || typeof value === 'undefined' || String(value).trim() === ''
            ? '-'
            : String(value);

        element.textContent = normalized;
    }

    function clearTableBody(id) {
        const body = document.getElementById(id);
        if (body) {
            body.innerHTML = '';
        }
        return body;
    }

    function appendCell(row, value) {
        const cell = document.createElement('td');
        cell.textContent = value === null || typeof value === 'undefined' || String(value).trim() === ''
            ? 'Not set'
            : String(value);
        row.appendChild(cell);
    }

    function hideProofPreviews() {
        ['auditProofImageWrap', 'auditProofPdfWrap', 'auditProofFileWrap'].forEach(function (id) {
            const element = document.getElementById(id);
            if (element) {
                element.style.display = 'none';
            }
        });

        const image = document.getElementById('auditProofImage');
        const imageLink = document.getElementById('auditProofImageLink');
        const pdf = document.getElementById('auditProofPdf');
        const fileLink = document.getElementById('auditProofFileLink');

        if (image) image.removeAttribute('src');
        if (imageLink) imageLink.removeAttribute('href');
        if (pdf) pdf.removeAttribute('src');
        if (fileLink) fileLink.removeAttribute('href');
    }

    function renderProof(data) {
        const section = document.getElementById('auditProofSection');

        if (!section) {
            return;
        }

        hideProofPreviews();

        if (!data.proof_path) {
            section.style.display = 'none';
            return;
        }

        section.style.display = '';

        if (data.proof_kind === 'image') {
            const wrap = document.getElementById('auditProofImageWrap');
            const image = document.getElementById('auditProofImage');
            const link = document.getElementById('auditProofImageLink');

            if (wrap && image && link) {
                image.src = data.proof_path;
                link.href = data.proof_path;
                wrap.style.display = '';
            }
            return;
        }

        if (data.proof_kind === 'pdf') {
            const wrap = document.getElementById('auditProofPdfWrap');
            const pdf = document.getElementById('auditProofPdf');

            if (wrap && pdf) {
                pdf.src = data.proof_path;
                wrap.style.display = '';
            }
            return;
        }

        const wrap = document.getElementById('auditProofFileWrap');
        const link = document.getElementById('auditProofFileLink');

        if (wrap && link) {
            link.href = data.proof_path;
            wrap.style.display = '';
        }
    }

    function renderRecordedRows(data) {
        const section = document.getElementById('auditRecordedSection');
        const body = clearTableBody('auditRecordedBody');

        if (!section || !body) {
            return;
        }

        const rows = Array.isArray(data.recorded_rows) ? data.recorded_rows : [];

        if (!rows.length) {
            section.style.display = 'none';
            return;
        }

        rows.forEach(function (item) {
            const row = document.createElement('tr');
            appendCell(row, item.label);
            appendCell(row, item.value);
            body.appendChild(row);
        });

        section.style.display = '';
    }

    function renderChangeRows(data) {
        const section = document.getElementById('auditChangesSection');
        const body = clearTableBody('auditChangesBody');

        if (!section || !body) {
            return;
        }

        const rows = Array.isArray(data.changes) ? data.changes : [];

        if (!data.has_previous || !rows.length) {
            section.style.display = 'none';
            return;
        }

        rows.forEach(function (item) {
            const row = document.createElement('tr');
            appendCell(row, item.label);
            appendCell(row, item.before);
            appendCell(row, item.after);
            body.appendChild(row);
        });

        section.style.display = '';
    }

    function renderLegacyData(data) {
        const section = document.getElementById('auditLegacySection');
        const content = document.getElementById('auditLegacyContent');

        if (!section || !content) {
            return;
        }

        const parts = [];

        if (data.legacy_before) {
            parts.push('Previous: ' + data.legacy_before);
        }

        if (data.legacy_after) {
            parts.push('Recorded: ' + data.legacy_after);
        }

        if (!parts.length) {
            section.style.display = 'none';
            content.textContent = '';
            return;
        }

        content.textContent = parts.join('\n');
        section.style.display = '';
    }

    function openFinanceAudit(logId) {
        const data = financeAuditDetails[String(logId)];

        if (!data) {
            console.warn('Finance audit details not found for log ID:', logId);
            return;
        }

        text('auditModalTitle', data.action || 'Finance Activity Details');

        const recordText = data.record_id
            ? data.record_type + ' #' + data.record_id
            : data.record_type;

        text('auditModalSubtitle', recordText);
        text('auditDate', data.created_at);
        text('auditAdmin', data.admin);
        text('auditAdminEmail', data.admin_email);
        text('auditAction', data.action);
        text('auditRecord', recordText);
        text('auditBatch', data.batch_id ? 'Batch: ' + data.batch_id : '');
        text(
            'auditDescription',
            data.details || 'No additional description was recorded.'
        );

        text('auditIpAddress', data.ip ? data.ip.address : 'Not recorded');
        text('auditIpDescription', data.ip ? data.ip.label : '');
        text('auditDevice', data.device || 'Device information not recorded');
        text('auditUserAgent', data.user_agent || '');

        renderProof(data);
        renderRecordedRows(data);
        renderChangeRows(data);
        renderLegacyData(data);

        const modal = document.getElementById('financeAuditModal');

        if (
            window.jQuery &&
            jQuery.fn &&
            typeof jQuery.fn.modal === 'function'
        ) {
            jQuery(modal).modal('show');
            return;
        }

        modal.style.display = 'block';
        modal.classList.add('show');
        modal.setAttribute('aria-modal', 'true');
        modal.removeAttribute('aria-hidden');
        document.body.classList.add('modal-open');

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show finance-audit-manual-backdrop';
        document.body.appendChild(backdrop);
    }

    document.addEventListener('click', function (event) {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        const button = target.closest('.view-finance-audit');

        if (button) {
            event.preventDefault();
            openFinanceAudit(button.getAttribute('data-log-id'));
            return;
        }

        const manualClose = target.closest(
            '#financeAuditModal [data-dismiss="modal"], .finance-audit-manual-backdrop'
        );

        if (!manualClose) {
            return;
        }

        if (
            window.jQuery &&
            jQuery.fn &&
            typeof jQuery.fn.modal === 'function' &&
            target.closest('#financeAuditModal [data-dismiss="modal"]')
        ) {
            return;
        }

        const modal = document.getElementById('financeAuditModal');
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            modal.removeAttribute('aria-modal');
        }

        document.body.classList.remove('modal-open');

        document
            .querySelectorAll('.finance-audit-manual-backdrop')
            .forEach(function (backdrop) {
                backdrop.remove();
            });
    });
})();
</script>

<script>
$(function(){
    if ($('#financeActivityTable').length && $('#financeActivityTable tbody tr').length > 1) {
        $('#financeActivityTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            responsive: false,
            scrollX: true
        });
    }
});
</script>
</body>
</html>
