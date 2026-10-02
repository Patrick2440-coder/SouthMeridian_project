<?php
session_start();
require_once '../config/database.php';
require_once 'admin_access.php';
requireAccess('community');
/* =========================
   AUTH / CURRENT ADMIN
   ========================= */
/*
 * admin_access.php + requireAccess('community') remains the primary
 * authentication and module-permission guard. Validate the current admin
 * from the database instead of depending on a second admin_role session check.
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
if ($adminRole !== 'admin') {
    header('Location: ../index.php');
    exit;
}
function esc($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
$adminEmail = (string)($me['email'] ?? '');
$adminName = trim((string)($me['full_name'] ?? ''));
$myPhase = (string)($me['phase'] ?? 'Phase 1');
$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';
/* =========================
   PRICING (dynamic)
   ========================= */
function ensure_pricing(mysqli $conn, string $phase): array {
  $stmt = $conn->prepare("SELECT * FROM facility_rental_pricing WHERE phase=? LIMIT 1");
  $stmt->bind_param("s", $phase);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) {
    $stmt = $conn->prepare("
      INSERT INTO facility_rental_pricing
        (phase, court_rate_per_hour, court_rate_per_30min, tables_chairs_flat, clubhouse_flat, clubhouse_max_person)
      VALUES (?, 100, 50, 2500, 2500, 50)
    ");
    $stmt->bind_param("s", $phase);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM facility_rental_pricing WHERE phase=? LIMIT 1");
    $stmt->bind_param("s", $phase);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
  }

  return $row ?: [
    'court_rate_per_hour' => 100,
    'court_rate_per_30min' => 50,
    'tables_chairs_flat' => 2500,
    'clubhouse_flat' => 2500,
    'clubhouse_max_person' => 50,
  ];
}

$pricing = ensure_pricing($conn, $phase);

/* =========================
   CSRF
   ========================= */
if (empty($_SESSION['csrf_facility_rent'])) {
  $_SESSION['csrf_facility_rent'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_facility_rent'];

/* =========================
   Filters
   ========================= */
$status = (string)($_GET['status'] ?? 'pending');
$allowedStatus = ['pending','approved','denied','cancelled'];
if (!in_array($status, $allowedStatus, true)) $status = 'pending';

$facility = (string)($_GET['facility'] ?? 'all');
$allowedFacility = ['all','tables_chairs','court','clubhouse'];
if (!in_array($facility, $allowedFacility, true)) $facility = 'all';

function facility_label($f){
  return $f === 'tables_chairs' ? 'Tables & Chairs' : ($f === 'court' ? 'Court' : 'Clubhouse');
}

$msg = (string)($_GET['msg'] ?? '');

/* =========================
   Load requests
   ========================= */
if ($facility === 'all') {
  $stmt = $conn->prepare("
    SELECT r.*,
           CONCAT(h.first_name,' ',h.last_name) AS homeowner_name,
           h.house_lot_number
    FROM facility_rental_requests r
    JOIN homeowners h ON h.id = r.homeowner_id
    WHERE r.phase=? AND r.status=?
    ORDER BY r.created_at DESC
    LIMIT 400
  ");
  $stmt->bind_param("ss", $phase, $status);
} else {
  $stmt = $conn->prepare("
    SELECT r.*,
           CONCAT(h.first_name,' ',h.last_name) AS homeowner_name,
           h.house_lot_number
    FROM facility_rental_requests r
    JOIN homeowners h ON h.id = r.homeowner_id
    WHERE r.phase=? AND r.status=? AND r.facility=?
    ORDER BY r.created_at DESC
    LIMIT 400
  ");
  $stmt->bind_param("sss", $phase, $status, $facility);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$view = $_GET['view'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>HOA-ADMIN • Facility Rentals</title>

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
    .badge-soft { padding:.35rem .6rem; border-radius:999px; font-weight:800; font-size:12px; }
    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-danger  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .badge-soft-muted   { background:#f1f5f9; border:1px solid #e2e8f0; color:#475569; }

    .modalx {
      display:none; position:fixed; inset:0;
      background:rgba(0,0,0,.45);
      align-items:center; justify-content:center;
      z-index:9999; padding:16px;
    }
    .modalx .box {
      width:min(720px, 96vw);
      max-height:92vh;
      background:#fff;
      border-radius:16px;
      overflow:auto;
      box-shadow:0 20px 60px rgba(0,0,0,.25);
    }
    .modalx .boxhead {
      padding:14px 16px;
      border-bottom:1px solid #e5e7eb;
      display:flex; align-items:center; justify-content:space-between; gap:12px;
    }
    .modalx .closebtn { border:none; background:transparent; font-size:22px; cursor:pointer; }
    .pill {
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      background:#f1f5f9; border:1px solid #e2e8f0;
      font-weight:900; font-size:12px;
    }
    /* ACCESS TOAST */
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
  visibility: hidden;
  pointer-events: none;
  transform: translateY(-10px);
  transition: all .3s ease;
}

.access-toast.show {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
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
      transition:
        background .18s ease,
        color .18s ease,
        border-color .18s ease,
        transform .18s ease,
        box-shadow .18s ease;
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
    html.dark .page-header h4,
    html.dark .card-box h4,
    html.dark .card-box h5,
    html.dark .card-box label,
    html.dark .card-box b,
    html.dark .card-box strong,
    html.dark .footer-wrap {
      color: var(--admin-text, #e5e7eb) !important;
    }
    html.dark .text-secondary,
    html.dark .text-muted {
      color: var(--admin-muted, #9ca3af) !important;
    }
    html.dark .pill {
      background: var(--admin-surface-2, #253244) !important;
      border-color: var(--admin-border, #374151) !important;
      color: #cbd5e1 !important;
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
    html.dark table.dataTable tbody tr,
    html.dark table.dataTable tbody td {
      background: var(--admin-surface, #1f2937) !important;
      color: var(--admin-text, #e5e7eb) !important;
      border-color: var(--admin-border, #374151) !important;
    }
    html.dark .table-striped tbody tr:nth-of-type(odd),
    html.dark .table-striped tbody tr:nth-of-type(odd) > * {
      background: var(--admin-surface-2, #253244) !important;
      color: var(--admin-text, #e5e7eb) !important;
    }
    html.dark .table-hover tbody tr:hover,
    html.dark .table-hover tbody tr:hover > * {
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
    html.dark .badge-soft-muted {
      background: rgba(71, 85, 105, .25) !important;
      border-color: rgba(100, 116, 139, .4) !important;
      color: #cbd5e1 !important;
    }
    html.dark .modalx .box {
      background: var(--admin-surface, #1f2937) !important;
      color: var(--admin-text, #e5e7eb) !important;
      border: 1px solid var(--admin-border, #374151) !important;
    }
    html.dark .modalx .boxhead {
      background: var(--admin-surface-2, #253244) !important;
      color: var(--admin-text, #e5e7eb) !important;
      border-color: var(--admin-border, #374151) !important;
    }
    html.dark .modalx .closebtn {
      color: #f8fafc !important;
    }
    html.dark .alert-info {
      background: rgba(14, 165, 233, .12) !important;
      color: #bae6fd !important;
      border-color: rgba(14, 165, 233, .28) !important;
    }
    html.dark .alert-warning {
      background: rgba(217, 119, 6, .12) !important;
      color: #fde68a !important;
      border-color: rgba(245, 158, 11, .28) !important;
    }
    html.dark .btn-outline-success {
      color: #86efac !important;
      border-color: #22c55e !important;
    }
    html.dark .btn-outline-success:hover,
    html.dark .btn-outline-success:focus {
      background: #15803d !important;
      border-color: #15803d !important;
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
    html.dark .btn-outline-secondary {
      color: #cbd5e1 !important;
      border-color: #64748b !important;
    }
    html.dark .btn-outline-secondary:hover,
    html.dark .btn-outline-secondary:focus {
      background: #475569 !important;
      border-color: #64748b !important;
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
    html.dark .admin-theme-toggle {
      color: #f8fafc !important;
    }
    html.dark .admin-theme-toggle:hover,
    html.dark .admin-theme-toggle:focus {
      background: rgba(255,255,255,.08);
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
      .modalx .box {
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
  <!-- HEADER -->
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

  <!-- SIDEBAR -->
<?php include 'sidebar.php'; ?>

  <div class="mobile-menu-overlay"></div>

  <!-- MAIN -->
  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header mb-20">
        <div class="row">
          <div class="col-md-12 col-sm-12">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
              <div class="title"><h4>Facility Rentals</h4></div>
              <button class="btn btn-outline-success" type="button" id="openPricing">
                <i class="dw dw-settings2"></i> Edit Rental Prices
              </button>
            </div>
            <div class="text-secondary">
              Phase: <b><?= esc($phase) ?></b>
              <span class="pill">Status: <?= esc(strtoupper($status)) ?></span>
              <span class="pill">Facility: <?= esc($facility==='all'?'ALL':strtoupper(str_replace('_',' ',$facility))) ?></span>
            </div>
          </div>
        </div>
      </div>

      <?php if ($msg): ?>
        <div class="alert alert-info"><?= esc($msg) ?></div>
      <?php endif; ?>

      <div class="card-box pd-20 mb-20">
        <form class="row" method="GET" style="gap:12px; align-items:end;">
          <div class="col-md-3">
            <label class="font-weight-bold">Status</label>
            <select class="form-control" name="status">
              <?php foreach($allowedStatus as $st): ?>
                <option value="<?= esc($st) ?>" <?= $status===$st?'selected':'' ?>><?= esc(strtoupper($st)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="font-weight-bold">Facility</label>
            <select class="form-control" name="facility">
              <option value="all" <?= $facility==='all'?'selected':'' ?>>ALL</option>
              <option value="tables_chairs" <?= $facility==='tables_chairs'?'selected':'' ?>>Tables & Chairs</option>
              <option value="court" <?= $facility==='court'?'selected':'' ?>>Court</option>
              <option value="clubhouse" <?= $facility==='clubhouse'?'selected':'' ?>>Clubhouse</option>
            </select>
          </div>
          <div class="col-md-3">
            <button class="btn btn-success">Filter</button>
            <a class="btn btn-outline-success" href="admin_facility_calendar.php">Open Calendar</a>
          </div>
        </form>
      </div>

      <div class="card-box pd-20 mb-30">
        <div class="table-responsive">
          <table id="rentTable" class="table table-striped table-hover">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Homeowner</th>
                <th>Facility</th>
                <th>Schedule</th>
                <th>Purpose</th>
                <th>Status</th>
                <th style="width:210px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="text-center text-secondary">No records.</td></tr>
              <?php else: ?>
                <?php foreach($rows as $r): ?>
                  <?php
                    $rid = (int)$r['id'];
                    $st = (string)$r['status'];
                    $badge = $st==='approved' ? 'badge-soft-success' : ($st==='denied' ? 'badge-soft-danger' : ($st==='cancelled'?'badge-soft-muted':'badge-soft-warning'));
                  ?>
                  <tr>
                    <td>#<?= $rid ?></td>
                    <td>
                      <div style="font-weight:800;"><?= esc($r['homeowner_name'] ?? '') ?></div>
                      <div class="text-muted" style="font-weight:700;font-size:12px;"><?= esc($r['house_lot_number'] ?? '') ?></div>
                    </td>
                    <td style="font-weight:800;"><?= esc(facility_label($r['facility'])) ?></td>
                    <td style="font-weight:800;">
                      <?= esc(date('M d, Y h:i A', strtotime($r['start_dt']))) ?><br>
                      <span class="text-muted" style="font-weight:700;font-size:12px;">to <?= esc(date('M d, Y h:i A', strtotime($r['end_dt']))) ?></span>
                    </td>
                    <td style="font-weight:800;"><?= esc($r['purpose'] ?? '') ?></td>
                    <td><span class="badge-soft <?= esc($badge) ?>"><?= esc(strtoupper($st)) ?></span></td>
                    <td>
                      <?php if ($st === 'pending'): ?>
                        <button class="btn btn-sm btn-success actBtn"
                          data-id="<?= $rid ?>" data-status="approved" data-title="Approve Request #<?= $rid ?>">
                          Approve
                        </button>
                        <button class="btn btn-sm btn-outline-danger actBtn"
                          data-id="<?= $rid ?>" data-status="denied" data-title="Deny Request #<?= $rid ?>">
                          Deny
                        </button>
                      <?php else: ?>
                        <span class="text-muted" style="font-weight:800;">No action</span>
                        <?php if (!empty($r['admin_remarks'])): ?>
                          <div class="text-muted" style="font-weight:700;font-size:12px;">Remarks: <?= esc($r['admin_remarks']) ?></div>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
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

  <!-- ACTION MODAL -->
  <div class="modalx" id="actModal">
    <div class="box">
      <div class="boxhead">
        <div id="actModalTitle" style="font-weight:900;">Action</div>
        <button class="closebtn" type="button" id="closeActModal">&times;</button>
      </div>
      <div class="p-3">
        <!-- ✅ FIX: correct filename + keep filters -->
        <form method="POST" action="admin_facility_rentals_actions.php">
          <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
          <input type="hidden" name="id" id="actId" value="">
          <input type="hidden" name="new_status" id="actStatus" value="">
          <input type="hidden" name="return_status" value="<?= esc($status) ?>">
          <input type="hidden" name="return_facility" value="<?= esc($facility) ?>">

          <div class="form-group">
            <label style="font-weight:900;">Admin remarks (optional)</label>
            <input type="text" name="remarks" class="form-control" maxlength="255" placeholder="e.g., Approved. Claim key at office.">
          </div>

          <div class="alert alert-warning mb-0">
            <b>Overlap protection:</b> approving will fail if it overlaps an already approved booking.
          </div>

          <div class="mt-3 d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" id="cancelAct">Cancel</button>
            <button class="btn btn-success" id="confirmAct">Confirm</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ✅ PRICING MODAL -->
  <div class="modalx" id="pricingModal">
    <div class="box">
      <div class="boxhead">
        <div style="font-weight:900;">Rental Pricing Settings • <?= esc($phase) ?></div>
        <button class="closebtn" type="button" id="closePricing">&times;</button>
      </div>

      <div class="p-3">
        <form method="POST" action="admin_facility_pricing_save.php" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
          <input type="hidden" name="return_status" value="<?= esc($status) ?>">
          <input type="hidden" name="return_facility" value="<?= esc($facility) ?>">

          <div class="alert alert-info mb-3">
            <b>Court pricing rule:</b> every <b>1 hour</b> = ₱<?= esc($pricing['court_rate_per_hour']) ?>, every <b>30 minutes</b> = ₱<?= esc($pricing['court_rate_per_30min']) ?>.
            <div class="small mt-1">Example: 1hr 30mins = ₱(1×hour + 1×30min).</div>
          </div>

          <div class="row" style="gap:12px;">
            <div class="col-md-6">
              <label style="font-weight:900;">Court rate per 1 hour (₱)</label>
              <input type="number" min="0" class="form-control" name="court_rate_per_hour"
                     value="<?= esc($pricing['court_rate_per_hour']) ?>" required>
            </div>

            <div class="col-md-6">
              <label style="font-weight:900;">Court rate per 30 mins (₱)</label>
              <input type="number" min="0" class="form-control" name="court_rate_per_30min"
                     value="<?= esc($pricing['court_rate_per_30min']) ?>" required>
            </div>

            <div class="col-md-6">
              <label style="font-weight:900;">Tables & Chairs flat price (₱)</label>
              <input type="number" min="0" class="form-control" name="tables_chairs_flat"
                     value="<?= esc($pricing['tables_chairs_flat']) ?>" required>
            </div>

            <div class="col-md-6">
              <label style="font-weight:900;">Clubhouse flat price (₱)</label>
              <input type="number" min="0" class="form-control" name="clubhouse_flat"
                     value="<?= esc($pricing['clubhouse_flat']) ?>" required>
            </div>

            <div class="col-md-6">
              <label style="font-weight:900;">Clubhouse max persons</label>
              <input type="number" min="1" max="500" class="form-control" name="clubhouse_max_person"
                     value="<?= esc($pricing['clubhouse_max_person']) ?>" required>
              <div class="text-muted" style="font-weight:700;font-size:12px;">Your rule: max persons (editable)</div>
            </div>
          </div>

          <div class="mt-3 d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" id="cancelPricing">Cancel</button>
            <button class="btn btn-success">Save Pricing</button>
          </div>

        </form>
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
      $('#rentTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [],
        columnDefs: [{ orderable: false, targets: 6 }]
      });
    });

    const actModal = document.getElementById('actModal');
    const actTitle = document.getElementById('actModalTitle');
    const actId    = document.getElementById('actId');
    const actStatus= document.getElementById('actStatus');
    const confirmBtn = document.getElementById('confirmAct');

    function openModal(){ actModal.style.display='flex'; }
    function closeModal(){ actModal.style.display='none'; }

    document.getElementById('closeActModal').addEventListener('click', closeModal);
    document.getElementById('cancelAct').addEventListener('click', closeModal);
    actModal.addEventListener('click', (e)=>{ if(e.target===actModal) closeModal(); });

    document.querySelectorAll('.actBtn').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        actTitle.textContent = btn.dataset.title || 'Action';
        actId.value = btn.dataset.id || '';
        actStatus.value = btn.dataset.status || '';
        confirmBtn.className = 'btn ' + (actStatus.value==='approved' ? 'btn-success' : 'btn-danger');
        openModal();
      });
    });

    const pricingModal = document.getElementById('pricingModal');
    function openPricing(){ pricingModal.style.display = 'flex'; }
    function closePricing(){ pricingModal.style.display = 'none'; }

    document.getElementById('openPricing')?.addEventListener('click', openPricing);
    document.getElementById('closePricing')?.addEventListener('click', closePricing);
    document.getElementById('cancelPricing')?.addEventListener('click', closePricing);
    pricingModal?.addEventListener('click', (e)=>{ if(e.target === pricingModal) closePricing(); });
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
