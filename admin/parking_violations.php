<?php
session_start();
require_once '../config/database.php';
require_once 'admin_access.php';
requireAccess('parking');

// ===================== CURRENT ADMIN =====================
/*
 * admin_access.php + requireAccess('parking') is the primary authentication
 * and module-permission guard. Do not run a second redirect based only on
 * $_SESSION['admin_role'], because that session value can be stale/missing
 * while the authenticated admin session is still valid.
 */
$adminId = (int)($_SESSION['admin_id'] ?? 0);

if ($adminId <= 0) {
    header('Location: ../index.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT email, full_name, phase, role
    FROM admins
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$me) {
    header('Location: ../index.php');
    exit;
}

$adminRole = strtolower(trim((string)($me['role'] ?? '')));

if ($adminRole === 'superadmin') {
    http_response_code(403);
    exit('Superadmin cannot access this module.');
}

$adminEmail = (string)($me['email'] ?? '');
$adminName = trim((string)($me['full_name'] ?? ''));
$myPhase = (string)($me['phase'] ?? 'Phase 1');

// ===================== CSRF =====================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$allowedPhases = ['Phase 1','Phase 2','Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';

$flash = "";
$flashType = "success";

function fail_flash(&$flash, &$flashType, string $msg){
    $flash = $msg;
    $flashType = "danger";
}

// ===================== ACTIONS =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        fail_flash($flash, $flashType, "Invalid request token.");
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add_violation') {
            $plate = strtoupper(trim((string)($_POST['plate_no'] ?? '')));
            $type  = trim((string)($_POST['violation_type'] ?? ''));
            $loc   = trim((string)($_POST['location'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $fine  = (float)($_POST['fine_amount'] ?? 0);

            if ($plate === '' || $type === '') {
                fail_flash($flash, $flashType, "Plate number and violation type are required.");
            } else {
                $permit_id = null;
                $homeowner_id = null;

                $stmt = $conn->prepare("
                    SELECT id, homeowner_id
                    FROM parking_permits
                    WHERE phase=?
                      AND status='active'
                      AND LOWER(COALESCE(payment_status,'paid'))='paid'
                      AND plate_no=?
                      AND valid_from IS NOT NULL
                      AND valid_from <= CURDATE()
                      AND valid_until >= CURDATE()
                    ORDER BY valid_from DESC, id DESC
                    LIMIT 1
                ");
                $stmt->bind_param("ss", $phase, $plate);
                $stmt->execute();
                $m = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($m) {
                    $permit_id = (int)$m['id'];
                    $homeowner_id = (int)$m['homeowner_id'];
                }

                $stmt = $conn->prepare("
                    INSERT INTO parking_violations
                        (phase, permit_id, homeowner_id, plate_no, violation_type, location, notes, fine_amount, status, issued_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, 'open', NOW())
                ");
                $stmt->bind_param("siissssd", $phase, $permit_id, $homeowner_id, $plate, $type, $loc, $notes, $fine);
                $stmt->execute();
                $stmt->close();

                $flash = "Violation recorded. " . ($permit_id
                    ? "Matched to the currently valid permit."
                    : "No currently valid permit matched this plate.");
                $flashType = "success";
            }
        }

        if (in_array($action, ['mark_paid','mark_cleared','mark_void'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                fail_flash($flash, $flashType, "Invalid violation ID.");
            } else {
                $newStatus = 'open';
                if ($action === 'mark_paid') $newStatus = 'paid';
                if ($action === 'mark_cleared') $newStatus = 'cleared';
                if ($action === 'mark_void') $newStatus = 'void';

                $stmt = $conn->prepare("
                    UPDATE parking_violations
                    SET status=?, resolved_at=NOW(), resolved_by_admin_id=?
                    WHERE id=? AND phase=?
                ");
                $stmt->bind_param("siis", $newStatus, $adminId, $id, $phase);
                $stmt->execute();
                if ($stmt->affected_rows <= 0) {
                    fail_flash($flash, $flashType, "Violation not found.");
                } else {
                    $flash = "Violation updated to {$newStatus}.";
                    $flashType = "success";
                }
                $stmt->close();
            }
        }
    }
}

// ===================== FILTERS =====================
$status = trim((string)($_GET['status'] ?? ''));
$q      = trim((string)($_GET['q'] ?? ''));

$where = "v.phase=?";
$params = [$phase];
$types  = "s";

if ($status !== '' && in_array($status, ['open','paid','cleared','void'], true)) {
    $where .= " AND v.status=?";
    $params[] = $status;
    $types .= "s";
}
if ($q !== '') {
    $where .= " AND (v.plate_no LIKE CONCAT('%', ?, '%')
                  OR v.violation_type LIKE CONCAT('%', ?, '%')
                  OR h.first_name LIKE CONCAT('%', ?, '%')
                  OR h.last_name LIKE CONCAT('%', ?, '%')
                  OR h.house_lot_number LIKE CONCAT('%', ?, '%'))";
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
    $params[] = $q;
    $types .= "sssss";
}

$sql = "
    SELECT v.*, p.permit_no,
           h.first_name, h.middle_name, h.last_name, h.house_lot_number
    FROM parking_violations v
    LEFT JOIN parking_permits p ON p.id = v.permit_id
    LEFT JOIN homeowners h ON h.id = v.homeowner_id
    WHERE {$where}
    ORDER BY v.issued_at DESC
    LIMIT 700
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>HOA-ADMIN | Parking Violations</title>
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
        .badge-soft { padding: .35rem .6rem; border-radius: 999px; font-weight: 800; font-size: 12px; display:inline-block; }
        .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
        .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
        .badge-soft-danger  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
        .badge-soft-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }

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
        /* Parking Violations dark-mode extensions */
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
        html.dark .card-box label,
        html.dark .card-box b,
        html.dark .card-box strong,
        html.dark .footer-wrap {
            color: var(--admin-text, #e5e7eb) !important;
        }
        html.dark .text-secondary,
        html.dark .text-muted,
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
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 .2rem rgba(59, 130, 246, .16) !important;
        }
        html.dark .form-control::placeholder {
            color: #64748b !important;
            opacity: 1;
        }
        html.dark select.form-control option {
            background: #111827 !important;
            color: #e5e7eb !important;
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
        html.dark .btn-outline-primary {
            color: #93c5fd !important;
            border-color: #3b82f6 !important;
        }
        html.dark .btn-outline-primary:hover,
        html.dark .btn-outline-primary:focus {
            background: #2563eb !important;
            border-color: #2563eb !important;
            color: #fff !important;
        }
        html.dark .btn-outline-danger {
            color: #fca5a5 !important;
            border-color: #ef4444 !important;
        }
        html.dark .btn-outline-danger:hover,
        html.dark .btn-outline-danger:focus {
            background: #b91c1c !important;
            border-color: #b91c1c !important;
            color: #fff !important;
        }
        html.dark .btn-light {
            background: var(--admin-surface-2, #253244) !important;
            color: #e5e7eb !important;
            border-color: var(--admin-border, #4b5563) !important;
        }
        html.dark .btn-light:hover,
        html.dark .btn-light:focus {
            background: var(--admin-hover, #334155) !important;
            color: #fff !important;
        }
        html.dark .alert-info {
            background: rgba(14, 165, 233, .12) !important;
            color: #bae6fd !important;
            border-color: rgba(14, 165, 233, .28) !important;
        }
        html.dark .alert-success {
            background: rgba(22, 163, 74, .12) !important;
            color: #bbf7d0 !important;
            border-color: rgba(34, 197, 94, .28) !important;
        }
        html.dark .alert-danger {
            background: rgba(220, 38, 38, .12) !important;
            color: #fecaca !important;
            border-color: rgba(239, 68, 68, .28) !important;
        }
        html.dark .modal-content,
        html.dark .modal-header,
        html.dark .modal-footer {
            background: var(--admin-surface, #1f2937) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border-color: var(--admin-border, #374151) !important;
        }
        html.dark .modal-title,
        html.dark .modal label,
        html.dark .modal b,
        html.dark .modal strong {
            color: var(--admin-text, #e5e7eb) !important;
        }
        html.dark .modal .close {
            color: #fff !important;
            text-shadow: none !important;
            opacity: .9;
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
            .form-inline {
                width: 100%;
            }
            .form-inline .form-control {
                width: 100%;
                margin-right: 0 !important;
                margin-bottom: 8px;
            }
            .form-inline .btn {
                width: 100%;
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
                    <div class="title"><h4>Parking Violations</h4></div>
                    <div class="text-secondary">Phase: <b><?= esc($phase) ?></b></div>
                </div>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flash) ?></div>
        <?php endif; ?>

        <div class="card-box mb-30 p-3">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap" style="gap:10px;">
                <form class="form-inline" method="GET">
                    <label class="mr-2">Status</label>
                    <select name="status" class="form-control mr-2">
                        <option value="">All</option>
                        <?php foreach(['open','paid','cleared','void'] as $s): ?>
                            <option value="<?= esc($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= esc($s) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" name="q" value="<?= esc($q) ?>" class="form-control mr-2" placeholder="Search plate/homeowner/type...">
                    <button class="btn btn-outline-primary" type="submit"><i class="dw dw-search"></i> Filter</button>
                </form>

                <button class="btn btn-primary" data-toggle="modal" data-target="#modalAddViolation">
                    <i class="dw dw-add"></i> Add Violation
                </button>
            </div>

            <div class="table-responsive">
                <table id="tblViolations" class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Issued</th>
                        <th>Plate</th>
                        <th>Permit</th>
                        <th>Homeowner</th>
                        <th>Blk/Lot</th>
                        <th>Type</th>
                        <th>Location</th>
                        <th>Fine</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach($rows as $r): ?>
                        <?php
                        $name = trim(($r['first_name'] ?? '').' '.($r['middle_name'] ?? '').' '.($r['last_name'] ?? ''));
                        if ($name === '') $name = '—';
                        $blk = (string)($r['house_lot_number'] ?? '');
                        if ($blk === '') $blk = '—';
                        $st = (string)($r['status'] ?? 'open');
                        $badge = 'badge-soft-warning';
                        if (in_array($st, ['paid','cleared'], true)) $badge = 'badge-soft-success';
                        if ($st === 'void') $badge = 'badge-soft-danger';
                        ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><?= esc($r['issued_at'] ?? '') ?></td>
                            <td><?= esc($r['plate_no'] ?? '') ?></td>
                            <td><?= esc($r['permit_no'] ?? '—') ?></td>
                            <td><?= esc($name) ?></td>
                            <td><?= esc($blk) ?></td>
                            <td><?= esc($r['violation_type'] ?? '') ?></td>
                            <td><?= esc($r['location'] ?? '') ?></td>
                            <td><?= number_format((float)($r['fine_amount'] ?? 0), 2) ?></td>
                            <td><span class="badge-soft <?= esc($badge) ?>"><?= esc($st) ?></span></td>
                            <td class="text-center">
                                <?php if ($st === 'open'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="mark_paid">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-success" type="submit"><i class="dw dw-money"></i> Paid</button>
                                    </form>

                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="mark_cleared">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-outline-primary" type="submit"><i class="dw dw-check"></i> Cleared</button>
                                    </form>

                                    <form method="POST" class="d-inline" onsubmit="return confirm('Void this violation?');">
                                        <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="mark_void">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit"><i class="dw dw-delete-3"></i> Void</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-secondary">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                        <tr><td colspan="11" class="text-center text-secondary">No violations found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="footer-wrap pd-20 mb-20 card-box">
            © Copyright South Meridian Homes All Rights Reserved
        </div>

    </div>
</div>

<div class="modal fade" id="modalAddViolation" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="add_violation">
            <div class="modal-header">
                <h5 class="modal-title">Add Parking Violation</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Plate Number</label>
                    <input type="text" name="plate_no" class="form-control" required maxlength="30" placeholder="ABC-1234">
                </div>
                <div class="form-group">
                    <label>Violation Type</label>
                    <select name="violation_type" class="form-control" required>
                        <option value="">Select...</option>
                        <option>Blocking Driveway</option>
                        <option>No Permit</option>
                        <option>Wrong Parking Slot</option>
                        <option>Fire Lane</option>
                        <option>Obstruction</option>
                        <option>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Location (optional)</label>
                    <input type="text" name="location" class="form-control" maxlength="120" placeholder="Blk 2 Lot 5 / Main Gate / etc.">
                </div>
                <div class="form-group">
                    <label>Fine Amount (₱)</label>
                    <input type="number" step="0.01" name="fine_amount" class="form-control" value="0.00" min="0">
                </div>
                <div class="form-group">
                    <label>Notes (optional)</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Extra details..."></textarea>
                </div>
                <div class="alert alert-info mb-0">
                    The system will auto-match the plate only to a <b>currently valid, paid permit</b> in this phase.
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Save Violation</button>
                <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            </div>
        </form>
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
        $('#tblViolations').DataTable({
            responsive:true,
            pageLength:10,
            order:[],
            columnDefs:[{orderable:false, targets:10}]
        });
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
