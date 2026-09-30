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
        SELECT DISTINCT a.id, a.full_name
        FROM finance_audit_logs l
        INNER JOIN admins a ON a.id=l.admin_id
        WHERE l.phase=?
        ORDER BY a.full_name
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
        $where[] = '(l.action LIKE ? OR l.entity_type LIKE ? OR l.details LIKE ? OR l.batch_id LIKE ? OR a.full_name LIKE ?)';
        $types .= 'sssss';
        $like = '%' . $filterSearch . '%';
        array_push($params, $like, $like, $like, $like, $like);
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

function financeLogPrettyJson(?string $json): string {
    $json = trim((string)$json);
    if ($json === '') return '';
    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) return $json;
    $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $pretty !== false ? $pretty : $json;
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
        <div class="user-info-dropdown">
            <div class="dropdown">
                <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
                    <span class="user-icon"><img src="vendors/images/photo1.jpg" alt=""></span>
                </a>
                <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
                    <a class="dropdown-item" href="logout.php"><i class="dw dw-logout"></i> Log Out</a>
                </div>
            </div>
        </div>
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
                            <input type="text" name="q" class="form-control" value="<?= esc($filterSearch) ?>" placeholder="Action, admin, batch, details...">
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
                                    <option value="<?= esc($entity) ?>" <?= $filterEntity === $entity ? 'selected' : '' ?>><?= esc($entity) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-6 mb-2">
                            <label>Admin</label>
                            <select name="admin_id" class="form-control">
                                <option value="0">All admins</option>
                                <?php foreach ($admins as $admin): ?>
                                    <option value="<?= (int)$admin['id'] ?>" <?= $filterAdmin === (int)$admin['id'] ? 'selected' : '' ?>><?= esc($admin['full_name'] ?? ('Admin #' . (int)$admin['id'])) ?></option>
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

                <div class="table-responsive">
                    <table id="financeActivityTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Date / Time</th>
                                <th>Admin</th>
                                <th>Action</th>
                                <th>Record</th>
                                <th>Details</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$logs): ?>
                            <tr><td colspan="6" class="text-center text-secondary">No finance activity matched the selected filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                $beforePretty = financeLogPrettyJson($log['before_data'] ?? null);
                                $afterPretty = financeLogPrettyJson($log['after_data'] ?? null);
                                ?>
                                <tr>
                                    <td data-order="<?= esc($log['created_at'] ?? '') ?>">
                                        <strong><?= esc($log['created_at'] ?? '') ?></strong>
                                    </td>
                                    <td>
                                        <strong><?= esc($log['admin_name'] ?: ('Admin #' . (int)($log['admin_id'] ?? 0))) ?></strong>
                                        <?php if (!empty($log['admin_email'])): ?><div class="log-meta"><?= esc($log['admin_email']) ?></div><?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-primary"><?= esc($log['action'] ?? '') ?></span></td>
                                    <td>
                                        <strong><?= esc($log['entity_type'] ?? '') ?></strong>
                                        <?php if (!empty($log['entity_id'])): ?><div class="log-meta">Record #<?= (int)$log['entity_id'] ?></div><?php endif; ?>
                                        <?php if (!empty($log['batch_id'])): ?><div class="log-meta">Batch: <?= esc($log['batch_id']) ?></div><?php endif; ?>
                                    </td>
                                    <td class="log-detail">
                                        <div><?= esc($log['details'] ?? '') ?></div>
                                        <?php if ($beforePretty !== '' || $afterPretty !== ''): ?>
                                            <details class="mt-2">
                                                <summary style="cursor:pointer;">View recorded data</summary>
                                                <?php if ($beforePretty !== ''): ?>
                                                    <div class="mt-2"><strong>Before</strong></div>
                                                    <pre class="log-pre"><?= esc($beforePretty) ?></pre>
                                                <?php endif; ?>
                                                <?php if ($afterPretty !== ''): ?>
                                                    <div class="mt-2"><strong>After</strong></div>
                                                    <pre class="log-pre"><?= esc($afterPretty) ?></pre>
                                                <?php endif; ?>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= esc($log['ip_address'] ?? '') ?>
                                        <?php if (!empty($log['user_agent'])): ?>
                                            <details class="mt-1"><summary class="log-meta" style="cursor:pointer;">Device</summary><div class="log-meta mt-1"><?= esc($log['user_agent']) ?></div></details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
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
