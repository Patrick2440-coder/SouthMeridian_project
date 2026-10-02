<?php
session_start();
require_once 'admin_access.php';
require_once '../config/database.php';
requireAccess('homeowner_management');
if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)
) {
    header('Location: index.php');
    exit;
}
if (!function_exists('esc')) {
    function esc($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
$adminId = (int)$_SESSION['admin_id'];
$stmt = $conn->prepare('SELECT phase, role FROM admins WHERE id=? LIMIT 1');
$stmt->bind_param('i', $adminId);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();
$adminPhase = trim((string)($admin['phase'] ?? ''));
$adminRole  = trim((string)($admin['role'] ?? ''));
$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
$dateObject = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
    $date = date('Y-m-d');
}
$search = trim((string)($_GET['q'] ?? ''));
$actionFilter = trim((string)($_GET['action'] ?? ''));
$start = $date . ' 00:00:00';
$end = date('Y-m-d H:i:s', strtotime($date . ' +1 day'));
$where = ["l.module_key='homeowner_management'", 'l.created_at >= ?', 'l.created_at < ?'];
$params = [$start, $end];
$types = 'ss';
if ($adminRole !== 'superadmin') {
    $where[] = 'l.phase = ?';
    $params[] = $adminPhase;
    $types .= 's';
}
if ($actionFilter !== '') {
    $where[] = 'l.action = ?';
    $params[] = $actionFilter;
    $types .= 's';
}
if ($search !== '') {
    $where[] = '(l.action LIKE ? OR l.details LIKE ? OR a.full_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
$sql = "SELECT
            l.id,
            l.admin_id,
            l.phase,
            l.action,
            l.details,
            l.ip_address,
            l.created_at,
            a.full_name,
            a.position
        FROM activity_logs l
        LEFT JOIN admins a ON a.id=l.admin_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.created_at DESC, l.id DESC";
$logStmt = $conn->prepare($sql);
$logStmt->bind_param($types, ...$params);
$logStmt->execute();
$logs = $logStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$logStmt->close();
$actions = [];
if ($adminRole === 'superadmin') {
    $actionStmt = $conn->prepare(
        "SELECT DISTINCT action FROM activity_logs
         WHERE module_key='homeowner_management'
         ORDER BY action ASC"
    );
} else {
    $actionStmt = $conn->prepare(
        "SELECT DISTINCT action FROM activity_logs
         WHERE module_key='homeowner_management' AND phase=?
         ORDER BY action ASC"
    );
    $actionStmt->bind_param('s', $adminPhase);
}
$actionStmt->execute();
$actions = $actionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$actionStmt->close();
/*
 * Archived duplicate imports for the selected day.
 * These records used to be shown in Household Approval.
 * They now live in the Homeowner Management Archive only.
 */
$archivedDuplicates = [];
try {
    if ($adminRole === 'superadmin') {
        $duplicateArchiveStmt = $conn->prepare(
            "SELECT *
             FROM homeowner_import_archive
             WHERE archived_at >= ?
               AND archived_at < ?
             ORDER BY archived_at DESC, id DESC"
        );
        $duplicateArchiveStmt->bind_param('ss', $start, $end);
    } else {
        $duplicateArchiveStmt = $conn->prepare(
            "SELECT *
             FROM homeowner_import_archive
             WHERE phase=?
               AND archived_at >= ?
               AND archived_at < ?
             ORDER BY archived_at DESC, id DESC"
        );
        $duplicateArchiveStmt->bind_param('sss', $adminPhase, $start, $end);
    }
    $duplicateArchiveStmt->execute();
    $archivedDuplicates = $duplicateArchiveStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $duplicateArchiveStmt->close();
} catch (Throwable $e) {
    error_log('Archived duplicate records query failed: ' . $e->getMessage());
}
/*
 * Completed ownership transfers for the selected day.
 * The old and new homeowner rows are both retained so the property history
 * stays auditable without moving historical transactions between people.
 */
$ownershipTransfers = [];
try {
    $transferTable = $conn->query("SHOW TABLES LIKE 'homeowner_ownership_transfers'");
    if ($transferTable && $transferTable->num_rows > 0) {
        if ($adminRole === 'superadmin') {
            $transferArchiveStmt = $conn->prepare(
                "SELECT
                    t.id,
                    t.phase,
                    t.block,
                    t.lot,
                    t.verification_method,
                    t.admin_notes,
                    t.completed_at,
                    t.previous_homeowner_id,
                    t.new_homeowner_id,
                    oldh.public_id AS old_public_id,
                    oldh.first_name AS old_first_name,
                    oldh.middle_name AS old_middle_name,
                    oldh.last_name AS old_last_name,
                    newh.public_id AS new_public_id,
                    newh.first_name AS new_first_name,
                    newh.middle_name AS new_middle_name,
                    newh.last_name AS new_last_name,
                    a.full_name AS processed_by
                 FROM homeowner_ownership_transfers t
                 LEFT JOIN homeowners oldh ON oldh.id=t.previous_homeowner_id
                 LEFT JOIN homeowners newh ON newh.id=t.new_homeowner_id
                 LEFT JOIN admins a ON a.id=t.completed_by_admin_id
                 WHERE t.status='completed'
                   AND t.completed_at >= ?
                   AND t.completed_at < ?
                 ORDER BY t.completed_at DESC, t.id DESC"
            );
            $transferArchiveStmt->bind_param('ss', $start, $end);
        } else {
            $transferArchiveStmt = $conn->prepare(
                "SELECT
                    t.id,
                    t.phase,
                    t.block,
                    t.lot,
                    t.verification_method,
                    t.admin_notes,
                    t.completed_at,
                    t.previous_homeowner_id,
                    t.new_homeowner_id,
                    oldh.public_id AS old_public_id,
                    oldh.first_name AS old_first_name,
                    oldh.middle_name AS old_middle_name,
                    oldh.last_name AS old_last_name,
                    newh.public_id AS new_public_id,
                    newh.first_name AS new_first_name,
                    newh.middle_name AS new_middle_name,
                    newh.last_name AS new_last_name,
                    a.full_name AS processed_by
                 FROM homeowner_ownership_transfers t
                 LEFT JOIN homeowners oldh ON oldh.id=t.previous_homeowner_id
                 LEFT JOIN homeowners newh ON newh.id=t.new_homeowner_id
                 LEFT JOIN admins a ON a.id=t.completed_by_admin_id
                 WHERE t.status='completed'
                   AND t.phase=?
                   AND t.completed_at >= ?
                   AND t.completed_at < ?
                 ORDER BY t.completed_at DESC, t.id DESC"
            );
            $transferArchiveStmt->bind_param('sss', $adminPhase, $start, $end);
        }
        $transferArchiveStmt->execute();
        $ownershipTransfers = $transferArchiveStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $transferArchiveStmt->close();
    }
} catch (Throwable $e) {
    error_log('Ownership transfer archive query failed: ' . $e->getMessage());
}
$totalToday = count($logs);
$totalArchivedDuplicates = count($archivedDuplicates);
$totalOwnershipTransfers = count($ownershipTransfers);
$previousDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
$isToday = $date === date('Y-m-d');
?>
<!DOCTYPE html>
<html>
<head>
    <?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>
    <meta charset="utf-8">
    <title>Homeowner Management Archive</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
    <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        :root { --brand:#077f46; }
        .page-title-wrap { display:flex; justify-content:center; text-align:center; margin-bottom:14px; }
        .card-box { border-radius:14px; }
        .stat-card { border-radius:14px; border:1px solid rgba(148,163,184,.25); }
        .activity-badge { display:inline-block; padding:.35rem .65rem; border-radius:999px; background:#e8f5ee; color:#077f46; font-weight:700; font-size:.78rem; }
        .archive-filter { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; align-items:end; }
        .archive-detail { min-width:260px; white-space:normal; }
        .archive-admin { min-width:170px; }
        .duplicate-archive-table td { vertical-align:middle; }
        .duplicate-archive-location { min-width:180px; }
        @media (max-width: 992px) { .archive-filter { grid-template-columns:1fr 1fr; } }
        @media (max-width: 576px) { .archive-filter { grid-template-columns:1fr; } }
        /* =========================================================
           ARCHIVE DARK MODE
           Keep these rules aligned with the other admin pages.
           ========================================================= */
        html.dark .card-box,
        html.dark .stat-card {
            background: var(--admin-surface) !important;
            color: var(--admin-text) !important;
            border-color: var(--admin-border) !important;
        }
        html.dark .page-title-wrap h2,
        html.dark .card-box h5,
        html.dark .stat-card .h5,
        html.dark .form-label,
        html.dark .archive-admin .fw-semibold,
        html.dark .archive-detail,
        html.dark #archiveTable td {
            color: var(--admin-text) !important;
        }
        html.dark .text-muted,
        html.dark small.text-muted {
            color: var(--admin-muted) !important;
        }
        html.dark .form-control,
        html.dark .form-select {
            background: var(--admin-input) !important;
            color: var(--admin-text) !important;
            border-color: var(--admin-border) !important;
        }
        html.dark .form-control::placeholder {
            color: var(--admin-muted) !important;
            opacity: 1;
        }
        html.dark .btn-outline-secondary {
            color: var(--admin-text) !important;
            border-color: var(--admin-border) !important;
        }
        html.dark .btn-outline-secondary:hover {
            background: var(--admin-hover) !important;
            color: #fff !important;
        }
        html.dark .table {
            --bs-table-color: var(--admin-text);
            --bs-table-bg: var(--admin-surface);
            --bs-table-border-color: var(--admin-border);
            --bs-table-striped-color: var(--admin-text);
            --bs-table-striped-bg: rgba(148,163,184,.055);
            --bs-table-active-color: var(--admin-text);
            --bs-table-active-bg: var(--admin-surface-3);
            --bs-table-hover-color: #fff;
            --bs-table-hover-bg: var(--admin-hover);
            color: var(--admin-text) !important;
            border-color: var(--admin-border) !important;
        }
        html.dark .table > :not(caption) > * > * {
            color: var(--admin-text) !important;
            border-color: var(--admin-border) !important;
            box-shadow:
                inset 0 0 0 9999px
                var(--bs-table-bg-state,
                    var(--bs-table-bg-type,
                        var(--bs-table-accent-bg,
                            var(--bs-table-bg)
                        )
                    )
                ) !important;
        }
        html.dark table.dataTable tbody tr,
        html.dark table.dataTable tbody td {
            color: var(--admin-text) !important;
            border-color: var(--admin-border) !important;
        }
        html.dark table.dataTable tbody tr.even > *,
        html.dark table.dataTable tbody tr:nth-child(even) > * {
            background-color: var(--admin-surface) !important;
            color: var(--admin-text) !important;
        }
        html.dark table.dataTable tbody tr.odd > *,
        html.dark table.dataTable.table-striped tbody tr:nth-of-type(odd) > * {
            background-color: var(--admin-surface-2) !important;
            color: var(--admin-text) !important;
        }
        html.dark table.dataTable thead th,
        html.dark table.dataTable thead td,
        html.dark .table thead th {
            background: var(--admin-surface-2) !important;
            color: #f8fafc !important;
            border-color: var(--admin-border) !important;
        }
        html.dark table.dataTable tbody tr:hover > *,
        html.dark .table-hover tbody tr:hover > * {
            background: var(--admin-hover) !important;
            color: #fff !important;
        }
        html.dark .dataTables_wrapper .dataTables_length,
        html.dark .dataTables_wrapper .dataTables_filter,
        html.dark .dataTables_wrapper .dataTables_info,
        html.dark .dataTables_wrapper .dataTables_paginate {
            color: var(--admin-muted) !important;
        }
        html.dark .dataTables_wrapper .dataTables_filter input,
        html.dark .dataTables_wrapper .dataTables_length select {
            background: var(--admin-input) !important;
            color: var(--admin-text) !important;
            border: 1px solid var(--admin-border) !important;
        }
        html.dark .dataTables_wrapper .dataTables_paginate .paginate_button {
            color: var(--admin-text) !important;
        }
        html.dark .activity-badge {
            background: rgba(34,197,94,.14);
            color: #86efac;
        }
        html.dark .footer-wrap {
            background: var(--admin-surface) !important;
            color: var(--admin-muted) !important;
            border-color: var(--admin-border) !important;
        }

        /* Direct admin logout button */
        .admin-header-logout { display:flex; align-items:center; margin-left:8px; }
        .admin-logout-btn {
            display:inline-flex; align-items:center; justify-content:center; gap:7px;
            min-height:38px; padding:8px 13px; border:1px solid #fecaca; border-radius:10px;
            background:#fef2f2; color:#dc2626 !important; font-size:12px; font-weight:700;
            text-decoration:none !important; transition:background .18s ease,border-color .18s ease,color .18s ease,transform .18s ease;
        }
        .admin-logout-btn:hover { background:#dc2626; border-color:#dc2626; color:#fff !important; transform:translateY(-1px); }
        .admin-logout-btn i { font-size:18px; }
        html.dark .admin-logout-btn { border-color:rgba(248,113,113,.28); background:rgba(220,38,38,.10); color:#fca5a5 !important; }
        html.dark .admin-logout-btn:hover { border-color:#ef4444; background:#dc2626; color:#fff !important; }
        html.dark .alert-light,
        html.dark .alert-secondary { background:var(--admin-surface-2) !important; color:var(--admin-text) !important; border-color:var(--admin-border) !important; }
        html.dark .alert-success { background:rgba(34,197,94,.12) !important; color:#bbf7d0 !important; border-color:rgba(34,197,94,.28) !important; }
        @media (max-width:575.98px) {
            .admin-logout-btn { width:38px; height:38px; min-height:38px; padding:0; border-radius:10px; }
            .admin-logout-text { display:none; }
        }
    </style>
    <!-- Shared admin theme CSS -->
    <link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">
    <!--
      Apply the SAME saved theme before the body is painted.
      This uses the exact same localStorage key as the other admin pages,
      so dark/light mode stays synchronized while navigating between pages.
    -->
    <script>
    (function () {
        try {
            const savedTheme = localStorage.getItem('hoa-theme');
            const dark =
                savedTheme === 'dark' ||
                (
                    !savedTheme &&
                    window.matchMedia &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches
                );
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
            <button type="button" id="themeToggle" class="admin-theme-toggle" aria-label="Switch theme" title="Switch theme">
                <span id="themeIcon">☾</span>
            </button>
        </div>
        <div class="admin-header-logout">
            <a href="logout.php" class="admin-logout-btn" title="Log Out" aria-label="Log Out">
                <i class="dw dw-logout"></i>
                <span class="admin-logout-text">Log Out</span>
            </a>
        </div>
    </div>
</div>
<?php include 'sidebar.php'; ?>
<div class="mobile-menu-overlay"></div>
<div class="main-container">
    <div class="pd-ltr-20">
        <div class="page-title-wrap">
            <div>
                <h2 class="h4 mb-1">Homeowner Management Archive</h2>
                <div class="text-muted fw-semibold">Daily history of Homeowner Management actions</div>
            </div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-3 col-sm-6">
                <div class="card-box stat-card p-3 h-100">
                    <div class="text-muted small fw-semibold">Selected Date</div>
                    <div class="h5 mb-0"><?= esc(date('F j, Y', strtotime($date))) ?></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card-box stat-card p-3 h-100">
                    <div class="text-muted small fw-semibold">Recorded Actions</div>
                    <div class="h5 mb-0"><?= (int)$totalToday ?></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card-box stat-card p-3 h-100">
                    <div class="text-muted small fw-semibold">Ownership Transfers</div>
                    <div class="h5 mb-0"><?= (int)$totalOwnershipTransfers ?></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="card-box stat-card p-3 h-100">
                    <div class="text-muted small fw-semibold">Scope</div>
                    <div class="h5 mb-0"><?= esc($adminRole === 'superadmin' ? 'All Phases' : $adminPhase) ?></div>
                </div>
            </div>
        </div>
        <div class="card-box p-3 mb-3">
            <form method="get" class="archive-filter">
                <div>
                    <label class="form-label fw-semibold">Date</label>
                    <input type="date" name="date" class="form-control" value="<?= esc($date) ?>">
                </div>
                <div>
                    <label class="form-label fw-semibold">Action</label>
                    <select name="action" class="form-select">
                        <option value="">All actions</option>
                        <?php foreach ($actions as $actionRow): ?>
                            <?php $actionName = (string)($actionRow['action'] ?? ''); ?>
                            <option value="<?= esc($actionName) ?>" <?= $actionFilter === $actionName ? 'selected' : '' ?>>
                                <?= esc($actionName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-semibold">Search</label>
                    <input type="text" name="q" class="form-control" value="<?= esc($search) ?>" placeholder="Name, email, action...">
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success flex-fill">Apply</button>
                    <a href="ho_archive.php" class="btn btn-outline-secondary">Today</a>
                </div>
            </form>
            <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="?date=<?= esc($previousDate) ?>">← Previous Day</a>
                <span class="text-muted small"><?= $isToday ? 'Showing today' : 'Viewing historical activity' ?></span>
                <a class="btn btn-sm btn-outline-secondary <?= $isToday ? 'disabled' : '' ?>" href="?date=<?= esc($nextDate) ?>">Next Day →</a>
            </div>
        </div>
        <div class="card-box p-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="mb-1">Activity History</h5>
                    <small class="text-muted">Only Homeowner Management actions are shown here.</small>
                </div>
            </div>
            <div class="table-responsive">
                <table id="archiveTable" class="table table-bordered table-striped align-middle" style="width:100%">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Admin / Officer</th>
                            <th>Phase</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td data-order="<?= esc($log['created_at']) ?>">
                                <div class="fw-semibold"><?= esc(date('h:i:s A', strtotime((string)$log['created_at']))) ?></div>
                                <small class="text-muted"><?= esc(date('M d, Y', strtotime((string)$log['created_at']))) ?></small>
                            </td>
                            <td class="archive-admin">
                                <div class="fw-semibold"><?= esc(
                                    $log['full_name']
                                        ?: ((int)($log['admin_id'] ?? 0) > 0
                                            ? ('Admin #' . (int)$log['admin_id'])
                                            : 'Homeowner / System')
                                ) ?></div>
                                <?php if (!empty($log['position'])): ?>
                                    <small class="text-muted"><?= esc($log['position']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= esc($log['phase'] ?? '—') ?></td>
                            <td><span class="activity-badge"><?= esc($log['action']) ?></span></td>
                            <td class="archive-detail"><?= nl2br(esc($log['details'] ?? '')) ?></td>
                            <td><small><?= esc($log['ip_address'] ?: '—') ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-box p-3 mt-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="mb-1">Ownership Transfer History</h5>
                    <small class="text-muted">Completed property ownership transfers on the selected date.</small>
                </div>
                <span class="badge" style="background:#077f46; color:#fff;">
                    <?= (int)$totalOwnershipTransfers ?> transfer<?= $totalOwnershipTransfers === 1 ? '' : 's' ?>
                </span>
            </div>
            <?php if ($ownershipTransfers): ?>
                <div class="alert alert-success py-2 mb-3">
                    Previous homeowner records are preserved as <strong>Former Owner</strong>. Historical dues, complaints, rentals, and other records remain attached to the person who originally created them.
                </div>
                <div class="table-responsive">
                    <table id="ownershipTransferArchiveTable" class="table table-bordered table-striped align-middle" style="width:100%">
                        <thead>
                            <tr>
                                <th>Completed</th>
                                <th>Property</th>
                                <th>Previous Homeowner</th>
                                <th>New Homeowner</th>
                                <th>Verification</th>
                                <th>Processed By</th>
                                <th>Admin Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ownershipTransfers as $transferRow): ?>
                            <?php
                            $oldOwnerName = trim(
                                (string)($transferRow['old_first_name'] ?? '') . ' ' .
                                (string)($transferRow['old_middle_name'] ?? '') . ' ' .
                                (string)($transferRow['old_last_name'] ?? '')
                            );
                            $newOwnerName = trim(
                                (string)($transferRow['new_first_name'] ?? '') . ' ' .
                                (string)($transferRow['new_middle_name'] ?? '') . ' ' .
                                (string)($transferRow['new_last_name'] ?? '')
                            );
                            ?>
                            <tr>
                                <td data-order="<?= esc($transferRow['completed_at'] ?? '') ?>">
                                    <div class="fw-semibold"><?= esc(date('h:i:s A', strtotime((string)$transferRow['completed_at']))) ?></div>
                                    <small class="text-muted"><?= esc(date('M d, Y', strtotime((string)$transferRow['completed_at']))) ?></small>
                                </td>
                                <td>
                                    <?= esc(
                                        (string)$transferRow['phase'] .
                                        ' / Block ' . (string)$transferRow['block'] .
                                        ' / Lot ' . (string)$transferRow['lot']
                                    ) ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= esc($oldOwnerName !== '' ? $oldOwnerName : ('Homeowner #' . (int)$transferRow['previous_homeowner_id'])) ?></div>
                                    <small class="text-muted"><?= esc($transferRow['old_public_id'] ?? '') ?> · Former Owner</small>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= esc($newOwnerName !== '' ? $newOwnerName : ('Homeowner #' . (int)$transferRow['new_homeowner_id'])) ?></div>
                                    <small class="text-muted"><?= esc($transferRow['new_public_id'] ?? '') ?> · Current Owner</small>
                                </td>
                                <td><?= esc(ucwords(str_replace('_', ' ', (string)($transferRow['verification_method'] ?? '')))) ?></td>
                                <td><?= esc($transferRow['processed_by'] ?? 'Admin') ?></td>
                                <td class="archive-detail"><?= nl2br(esc($transferRow['admin_notes'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-light border mb-0">
                    No ownership transfers were completed on <?= esc(date('F j, Y', strtotime($date))) ?>.
                </div>
            <?php endif; ?>
        </div>
        <div class="card-box p-3 mt-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="mb-1">Archived Duplicate Records</h5>
                    <small class="text-muted">Duplicate imports archived on the selected date are preserved here for review and audit history.</small>
                </div>
                <span class="badge" style="background:#64748b; color:#fff;">
                    <?= (int)$totalArchivedDuplicates ?> record<?= $totalArchivedDuplicates === 1 ? '' : 's' ?>
                </span>
            </div>
            <?php if ($archivedDuplicates): ?>
                <div class="alert alert-secondary py-2 mb-3">
                    The imported duplicate record and its linked existing homeowner are preserved. Archiving does not delete either history record.
                </div>
                <div class="table-responsive">
                    <table id="duplicateArchiveTable" class="table table-bordered table-striped align-middle duplicate-archive-table" style="width:100%">
                        <thead>
                            <tr>
                                <th>Archived</th>
                                <th>Imported Resident</th>
                                <th>Email</th>
                                <th>Phase / Block / Lot</th>
                                <th>Existing Homeowner ID</th>
                                <th>Existing Email</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($archivedDuplicates as $arch): ?>
                            <?php
                            $archivedName = trim(
                                (string)($arch['first_name'] ?? '') . ' ' .
                                (string)($arch['middle_name'] ?? '') . ' ' .
                                (string)($arch['last_name'] ?? '')
                            );
                            ?>
                            <tr>
                                <td data-order="<?= esc($arch['archived_at'] ?? '') ?>">
                                    <div class="fw-semibold">
                                        <?= esc(date('h:i:s A', strtotime((string)($arch['archived_at'] ?? 'now')))) ?>
                                    </div>
                                    <small class="text-muted">
                                        <?= esc(date('M d, Y', strtotime((string)($arch['archived_at'] ?? 'now')))) ?>
                                    </small>
                                </td>
                                <td><?= esc($archivedName !== '' ? $archivedName : 'Unnamed Resident') ?></td>
                                <td><?= esc($arch['email'] ?? '—') ?></td>
                                <td class="duplicate-archive-location">
                                    <?= esc(
                                        (string)($arch['phase'] ?? '') .
                                        ' / Block ' . (int)($arch['block'] ?? 0) .
                                        ' / Lot ' . (int)($arch['lot'] ?? 0)
                                    ) ?>
                                </td>
                                <td><?= esc($arch['existing_homeowner_id'] ?? 'Not found') ?></td>
                                <td><?= esc($arch['existing_email'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-light border mb-0">
                    No duplicate records were archived on <?= esc(date('F j, Y', strtotime($date))) ?>.
                </div>
            <?php endif; ?>
        </div>
        <footer class="footer-wrap pd-20 mb-20 card-box mt-3 text-center">
            © Copyright South Meridian Homes All Rights Reserved
        </footer>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="vendors/scripts/admin_theme.js"></script>
<script>
/*
 * Keep this page synchronized when another admin tab/window changes theme.
 * localStorage already synchronizes future navigation; the storage event
 * also updates Archive immediately when another open window changes it.
 */
window.addEventListener('storage', function (event) {
    if (event.key !== 'hoa-theme') return;
    const isDark = event.newValue === 'dark';
    document.documentElement.classList.toggle('dark', isDark);
    const icon = document.getElementById('themeIcon');
    if (icon) {
        icon.textContent = isDark ? '☀' : '☾';
    }
});
$(function () {
    if ($.fn.DataTable && $('#archiveTable').length) {
        $('#archiveTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[0, 'desc']],
            columnDefs: [{ orderable: false, targets: [4, 5] }]
        });
    }
    if ($.fn.DataTable && $('#ownershipTransferArchiveTable').length) {
        $('#ownershipTransferArchiveTable').DataTable({
            responsive: true,
            pageLength: 10,
            order: [[0, 'desc']]
        });
    }
    if ($.fn.DataTable && $('#duplicateArchiveTable').length) {
        $('#duplicateArchiveTable').DataTable({
            responsive: true,
            pageLength: 10,
            order: [[0, 'desc']]
        });
    }
});
</script>
</body>
</html>
