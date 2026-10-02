<?php
session_start();
require_once '../config/database.php';
require_once 'admin_access.php';
requireAccess('parking');

// ===================== AUTH GUARD =====================
if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)
) {
    echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
    exit;
}
if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
    echo "<script>alert('Superadmin cannot access this module.'); window.location='index.php';</script>";
    exit;
}

// ===================== CSRF =====================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===================== DB =====================

function esc($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function get_count(mysqli $conn, string $sql, string $phase): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    $stmt->bind_param("s", $phase);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

// ===================== ADMIN INFO =====================
$adminId   = (int)$_SESSION['admin_id'];
$adminRole = (string)$_SESSION['admin_role'];

$stmt = $conn->prepare("SELECT email, full_name, phase, role FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc() ?: [
    'email' => '',
    'full_name' => '',
    'phase' => 'Phase 1',
    'role' => $adminRole
];
$stmt->close();

$adminEmail = (string)($me['email'] ?? '');
$adminName  = trim((string)($me['full_name'] ?? ''));
$myPhase    = (string)($me['phase'] ?? 'Phase 1');

$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';

// ===================== AUTO-EXPIRE =====================
$stmt = $conn->prepare("
    UPDATE parking_permits
    SET status='expired'
    WHERE phase=?
      AND status='active'
      AND valid_until IS NOT NULL
      AND valid_until < CURDATE()
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$stmt->close();

// ===================== KPI COUNTS =====================
$activePermits = get_count(
    $conn,
    "SELECT COUNT(*) c
     FROM parking_permits
     WHERE phase=?
       AND status='active'
       AND LOWER(COALESCE(payment_status,'paid'))='paid'
       AND valid_from IS NOT NULL
       AND valid_from <= CURDATE()
       AND valid_until >= CURDATE()",
    $phase
);

$pendingPermits = get_count(
    $conn,
    "SELECT COUNT(*) c FROM parking_permits WHERE phase=? AND status='pending'",
    $phase
);

$expiredRevoked = get_count(
    $conn,
    "SELECT COUNT(*) c FROM parking_permits WHERE phase=? AND status IN ('expired','revoked')",
    $phase
);

$openViolations = get_count(
    $conn,
    "SELECT COUNT(*) c FROM parking_violations WHERE phase=? AND status='open'",
    $phase
);

$paidPendingPermits = get_count(
    $conn,
    "SELECT COUNT(*) c
     FROM parking_permits
     WHERE phase=? AND status='pending' AND COALESCE(payment_status,'unpaid')='paid'",
    $phase
);

// ===================== RECENT PERMIT REQUESTS =====================
$stmt = $conn->prepare("
    SELECT
        p.id,
        p.plate_no,
        p.vehicle_type,
        p.vehicle_make,
        p.vehicle_model,
        p.status,
        p.requested_at,
        p.payment_method,
        p.payment_status,
        p.valid_from,
        p.valid_until,
        h.first_name,
        h.middle_name,
        h.last_name,
        h.house_lot_number
    FROM parking_permits p
    JOIN homeowners h ON h.id = p.homeowner_id
    WHERE p.phase=?
    ORDER BY p.requested_at DESC
    LIMIT 25
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$recentPermits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ===================== RECENT VIOLATIONS =====================
$stmt = $conn->prepare("
    SELECT
        v.id,
        v.issued_at,
        v.plate_no,
        v.violation_type,
        v.location,
        v.fine_amount,
        v.status,
        h.first_name,
        h.middle_name,
        h.last_name,
        h.house_lot_number
    FROM parking_violations v
    LEFT JOIN homeowners h ON h.id = v.homeowner_id
    WHERE v.phase=?
    ORDER BY v.issued_at DESC
    LIMIT 25
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$recentViolations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>HOA-ADMIN | Parking Overview</title>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>

    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
    <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">

    <style>
        .kpi-card .icon { font-size: 28px; opacity: .9; }
        .kpi-value { font-size: 28px; font-weight: 800; }
        .kpi-label { color: #64748b; font-weight: 700; }

        .badge-soft { padding: .35rem .6rem; border-radius: 999px; font-weight: 800; font-size: 12px; display:inline-block; }
        .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
        .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
        .badge-soft-danger  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
        .badge-soft-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }
        .badge-soft-dark    { background:#f8fafc; border:1px solid #cbd5e1; color:#334155; }

        .access-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #ef4444;
            color: #fff;
            padding: 12px 18px;
            border-radius: 8px;
            font-weight: 600;
            box-shadow: 0 6px 18px rgba(0,0,0,0.2);
            z-index: 99999;
            opacity: 0;
            transform: translateY(-10px);
            transition: all .3s ease;
        }

        .access-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .admin-theme-switch {
            display: flex;
            align-items: center;
            padding: 0 8px;
        }
        .admin-theme-toggle {
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: inherit;
            font-size: 22px;
            cursor: pointer;
            transition: background .18s ease, color .18s ease;
        }
        .admin-theme-toggle:hover,
        .admin-theme-toggle:focus {
            background: rgba(15, 23, 42, .06);
            outline: none;
        }
        .admin-page-logout-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 98px;
            min-height: 40px;
            margin: 0 18px 0 6px;
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
        html.dark body,
        html.dark .main-container {
            background: var(--admin-bg, #0f172a) !important;
            color: var(--admin-text, #e5e7eb) !important;
        }
        html.dark .page-header,
        html.dark .card-box,
        html.dark .footer-wrap {
            background: var(--admin-surface, #1f2937) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border-color: var(--admin-border, #374151) !important;
        }
        html.dark .page-header .title h4,
        html.dark .card-box h5,
        html.dark .card-box h6,
        html.dark .card-box b,
        html.dark .kpi-value,
        html.dark .footer-wrap {
            color: var(--admin-text, #e5e7eb) !important;
        }
        html.dark .text-secondary,
        html.dark .text-muted,
        html.dark .kpi-label,
        html.dark small.text-secondary {
            color: var(--admin-muted, #9ca3af) !important;
        }
        html.dark .text-primary { color: #93c5fd !important; }
        html.dark .text-success { color: #86efac !important; }
        html.dark .text-warning { color: #fcd34d !important; }
        html.dark .text-danger { color: #fca5a5 !important; }
        html.dark .text-info { color: #7dd3fc !important; }
        html.dark .btn-outline-primary {
            color: #93c5fd !important;
            border-color: #3b82f6 !important;
        }
        html.dark .btn-outline-primary:hover,
        html.dark .btn-outline-primary:focus {
            color: #fff !important;
            background: #2563eb !important;
            border-color: #2563eb !important;
        }
        html.dark .table,
        html.dark table.dataTable {
            background: var(--admin-surface, #1f2937) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border-color: var(--admin-border, #374151) !important;
        }
        html.dark .table thead th,
        html.dark .table-light th,
        html.dark table.dataTable thead th,
        html.dark table.dataTable thead td {
            background: var(--admin-surface-2, #253244) !important;
            color: #f8fafc !important;
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
            color: #fff !important;
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
            border: 1px solid var(--admin-border, #4b5563) !important;
        }
        html.dark .dataTables_wrapper .dataTables_paginate .paginate_button {
            color: var(--admin-text, #e5e7eb) !important;
        }
        html.dark .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        html.dark .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover,
        html.dark .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            color: #fff !important;
            border-color: #2563eb !important;
            background: #2563eb !important;
        }
        html.dark .badge-soft-warning {
            background: rgba(217, 119, 6, .14) !important;
            border-color: rgba(245, 158, 11, .35) !important;
            color: #fde68a !important;
        }
        html.dark .badge-soft-success {
            background: rgba(22, 163, 74, .14) !important;
            border-color: rgba(34, 197, 94, .35) !important;
            color: #bbf7d0 !important;
        }
        html.dark .badge-soft-danger {
            background: rgba(220, 38, 38, .14) !important;
            border-color: rgba(239, 68, 68, .35) !important;
            color: #fecaca !important;
        }
        html.dark .badge-soft-info {
            background: rgba(37, 99, 235, .14) !important;
            border-color: rgba(59, 130, 246, .35) !important;
            color: #bfdbfe !important;
        }
        html.dark .badge-soft-dark {
            background: var(--admin-surface-2, #253244) !important;
            border-color: var(--admin-border, #374151) !important;
            color: #cbd5e1 !important;
        }
        html.dark .admin-theme-toggle {
            color: #f8fafc !important;
        }
        html.dark .admin-theme-toggle:hover,
        html.dark .admin-theme-toggle:focus {
            background: rgba(255, 255, 255, .08);
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
                margin: 0 10px 0 4px;
                padding: 0;
                border-radius: 10px;
            }
            .admin-page-logout-btn span {
                display: none;
            }
            .admin-page-logout-btn i {
                font-size: 18px;
            }
            .admin-theme-switch {
                padding: 0 2px;
            }
        }
</style>

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
            <button
                type="button"
                id="themeToggle"
                class="admin-theme-toggle"
                aria-label="Switch theme"
                title="Switch theme"
            >
                <span id="themeIcon">☾</span>
            </button>
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

<div class="mobile-menu-overlay"></div>

<div class="main-container">
    <div class="pd-ltr-20">

        <div class="page-header mb-20">
            <div class="row">
                <div class="col-md-12 col-sm-12">
                    <div class="title"><h4>Parking Overview</h4></div>
                    <div class="text-secondary">Phase: <b><?= esc($phase) ?></b></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="kpi-label">Active Permits</div>
                            <div class="kpi-value"><?= number_format($activePermits) ?></div>
                        </div>
                        <div class="icon text-success"><i class="dw dw-car"></i></div>
                    </div>
                    <div class="mt-2 text-secondary">
                        <a href="parking_permits.php" class="text-primary font-weight-bold">Open Permits →</a>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="kpi-label">Pending Requests</div>
                            <div class="kpi-value"><?= number_format($pendingPermits) ?></div>
                        </div>
                        <div class="icon text-warning"><i class="dw dw-clock"></i></div>
                    </div>
                    <div class="mt-2 text-secondary">Waiting for approval</div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="kpi-label">Paid Pending</div>
                            <div class="kpi-value"><?= number_format($paidPendingPermits) ?></div>
                        </div>
                        <div class="icon text-info"><i class="dw dw-money-2"></i></div>
                    </div>
                    <div class="mt-2 text-secondary">Ready for checking</div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="kpi-label">Expired/Revoked</div>
                            <div class="kpi-value"><?= number_format($expiredRevoked) ?></div>
                        </div>
                        <div class="icon text-danger"><i class="dw dw-ban"></i></div>
                    </div>
                    <div class="mt-2 text-secondary">Non-active permits</div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="kpi-label">Open Violations</div>
                            <div class="kpi-value"><?= number_format($openViolations) ?></div>
                        </div>
                        <div class="icon text-danger"><i class="dw dw-warning"></i></div>
                    </div>
                    <div class="mt-2 text-secondary">
                        <a href="parking_violations.php" class="text-primary font-weight-bold">Open Violations →</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-box mb-30 p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Recent Permit Requests</h5>
                <a class="btn btn-sm btn-outline-primary" href="parking_permits.php">Manage Permits</a>
            </div>

            <div class="table-responsive">
                <table id="permitsTable" class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Homeowner</th>
                        <th>Blk/Lot</th>
                        <th>Plate</th>
                        <th>Vehicle Type</th>
                        <th>Vehicle</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Requested</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentPermits as $p): ?>
                        <?php
                        $name = trim(($p['first_name'] ?? '') . ' ' . ($p['middle_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                        $st = (string)($p['status'] ?? 'pending');
                        $pay = strtolower((string)($p['payment_status'] ?? 'unpaid'));
                        $validFrom = (string)($p['valid_from'] ?? '');
                        $isUpcoming =
                            $st === 'active' &&
                            $pay === 'paid' &&
                            $validFrom !== '' &&
                            $validFrom > date('Y-m-d');

                        $displayStatus = $isUpcoming ? 'upcoming' : $st;

                        $badge = 'badge-soft-info';
                        if ($displayStatus === 'pending') $badge = 'badge-soft-warning';
                        if ($displayStatus === 'active') $badge = 'badge-soft-success';
                        if ($displayStatus === 'upcoming') $badge = 'badge-soft-info';
                        if (in_array($displayStatus, ['revoked', 'expired', 'rejected'], true)) $badge = 'badge-soft-danger';

                        $payBadge = 'badge-soft-warning';
                        if ($pay === 'paid') $payBadge = 'badge-soft-success';
                        elseif ($pay === 'failed') $payBadge = 'badge-soft-danger';
                        elseif ($pay === 'waived') $payBadge = 'badge-soft-info';
                        ?>
                        <tr>
                            <td><?= (int)$p['id'] ?></td>
                            <td><?= esc($name) ?></td>
                            <td><?= esc($p['house_lot_number'] ?? '') ?></td>
                            <td><?= esc($p['plate_no'] ?? '') ?></td>
                            <td><?= esc(ucfirst((string)($p['vehicle_type'] ?? ''))) ?></td>
                            <td><?= esc(trim(($p['vehicle_make'] ?? '') . ' ' . ($p['vehicle_model'] ?? ''))) ?></td>
                            <td>
                                <span class="badge-soft <?= esc($payBadge) ?>"><?= esc($pay ?: 'unpaid') ?></span>
                                <div class="text-secondary" style="font-size:12px;"><?= esc($p['payment_method'] ?? '—') ?></div>
                            </td>
                            <td>
                                <span class="badge-soft <?= esc($badge) ?>"><?= esc($displayStatus) ?></span>
                                <?php if ($isUpcoming): ?>
                                    <div class="text-secondary" style="font-size:12px;">
                                        Starts <?= esc($p['valid_from'] ?? '—') ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= esc($p['requested_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentPermits): ?>
                        <tr><td colspan="9" class="text-center text-secondary">No permit records yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-box mb-30 p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="mb-0">Recent Violations</h5>
                <a class="btn btn-sm btn-outline-primary" href="parking_violations.php">View Violations</a>
            </div>

            <div class="table-responsive">
                <table id="violationsTable" class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Issued</th>
                        <th>Plate</th>
                        <th>Homeowner</th>
                        <th>Violation</th>
                        <th>Location</th>
                        <th>Fine</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentViolations as $v): ?>
                        <?php
                        $name = trim(($v['first_name'] ?? '') . ' ' . ($v['middle_name'] ?? '') . ' ' . ($v['last_name'] ?? ''));
                        if ($name === '') $name = '—';
                        $st = (string)($v['status'] ?? 'open');
                        $badge = 'badge-soft-warning';
                        if (in_array($st, ['paid', 'cleared'], true)) $badge = 'badge-soft-success';
                        if ($st === 'void') $badge = 'badge-soft-danger';
                        ?>
                        <tr>
                            <td><?= (int)$v['id'] ?></td>
                            <td><?= esc($v['issued_at'] ?? '') ?></td>
                            <td><?= esc($v['plate_no'] ?? '') ?></td>
                            <td><?= esc($name) ?></td>
                            <td><?= esc($v['violation_type'] ?? '') ?></td>
                            <td><?= esc($v['location'] ?? '') ?></td>
                            <td><?= number_format((float)($v['fine_amount'] ?? 0), 2) ?></td>
                            <td><span class="badge-soft <?= esc($badge) ?>"><?= esc($st) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentViolations): ?>
                        <tr><td colspan="8" class="text-center text-secondary">No violations recorded yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-box mb-30 p-3">
            <h5 class="mb-2">Requirements for Yearly Parking Stickers/Permits</h5>
            <ul class="mb-2">
                <li><b>Picture of Vehicle (Front)</b></li>
                <li><b>Picture of Vehicle (Back)</b></li>
            </ul>
            <div class="text-secondary" style="font-size:12px;">
                Admin approval requires complete requirements and completed payment.
            </div>
        </div>

        <div class="footer-wrap pd-20 mb-20 card-box">
            © Copyright South Meridian Homes All Rights Reserved
        </div>

    </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="vendors/scripts/admin_theme.js"></script>

<script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

<script>
    $(function(){
        $('#permitsTable').DataTable({ responsive:true, pageLength:10, order:[] });
        $('#violationsTable').DataTable({ responsive:true, pageLength:10, order:[] });
    });
</script>

<div id="accessToast" class="access-toast">
    🚫 You do not have access to that part.
</div>

<script>
window.userPermissions = <?= json_encode($permissions) ?>;

document.addEventListener('DOMContentLoaded', function () {
    const toast = document.getElementById('accessToast');

    function showAccessToast() {
        toast.classList.add('show');
        setTimeout(() => {
            toast.classList.remove('show');
        }, 2500);
    }

    document.querySelectorAll('.menu-access-link').forEach(function(link){
        link.addEventListener('click', function(e){
            const moduleKey = this.dataset.module || '';
            const allowed = !!window.userPermissions[moduleKey];
            if(!allowed){
                e.preventDefault();
                showAccessToast();
            }
        });
    });
});
</script>
</body>
</html>
