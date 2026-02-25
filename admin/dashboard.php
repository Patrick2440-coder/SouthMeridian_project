<?php
session_start();

/*
  President Dashboard (Student style comments 😅)
  - This page is only for PHASE admin / president (not superadmin)
  - Shows KPIs + charts + tables
  - Shows announcements:
      1) Superadmin announcements (phase='Superadmin')
      2) HOA Officers announcements for your phase (audience='all_officers')
  - Calendar shows those announcements too
*/

/* =========================
   1) AUTH GUARD (who can access)
   ========================= */
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)) {
  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
  exit;
}

/* Superadmin is not allowed here (separate dashboard) */
if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
  echo "<script>alert('Superadmin cannot access President Dashboard.'); window.location='index.php';</script>";
  exit;
}

/* =========================
   2) LOCAL DB CONNECTION
   - Import your DB file: south_meridian_hoa.sql
   - Database name: south_meridian_hoa
   ========================= */
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "south_meridian_hoa";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

function esc($v) {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function nfmt($n) {
  return number_format((float)$n, 0);
}

function money($n) {
  return number_format((float)$n, 2);
}

/* =========================
   3) ADMIN INFO (from session)
   ========================= */
$adminId   = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = (string)($_SESSION['admin_role'] ?? 'admin');

/* Get admin info (email / name / phase) */
$stmt = $conn->prepare("SELECT email, full_name, phase, role FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$me) {
  // if admin not found, kick out
  session_destroy();
  echo "<script>alert('Session error. Please login again.'); window.location='index.php';</script>";
  exit;
}

$adminEmail = (string)($me['email'] ?? '');
$adminName  = trim((string)($me['full_name'] ?? ''));
$myPhase    = (string)($me['phase'] ?? 'Phase 1');

/* =========================
   4) PHASE (LOCKED)
   - This dashboard is for 1 phase only
   ========================= */
$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';

/* =========================
   5) PRESIDENT NAME (DYNAMIC)
   - pulled from hoa_officers table
   ========================= */
$presidentName  = 'Not assigned';
$presidentEmail = '';

$stmt = $conn->prepare("
  SELECT officer_name, officer_email
  FROM hoa_officers
  WHERE phase=? AND position='President' AND is_active=1
  LIMIT 1
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$pres = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($pres && trim((string)$pres['officer_name']) !== '') {
  $presidentName  = trim((string)$pres['officer_name']);
  $presidentEmail = trim((string)($pres['officer_email'] ?? ''));
} else {
  // fallback: show logged-in admin name
  if ($adminName !== '') $presidentName = $adminName;
}

/* Your workflow: admin is president of their phase */
$isPresident = true;

/* =========================
   6) KPI QUERIES
   ========================= */

/* Approved homeowners */
$stmt = $conn->prepare("SELECT COUNT(*) c FROM homeowners WHERE phase=? AND status='approved'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$approvedCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* Pending homeowners */
$stmt = $conn->prepare("SELECT COUNT(*) c FROM homeowners WHERE phase=? AND status='pending'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$pendingCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* Pending finance report requests */
$stmt = $conn->prepare("SELECT COUNT(*) c FROM finance_report_requests WHERE phase=? AND status='pending'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$pendingReportCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* This month collections */
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) s
  FROM finance_payments
  WHERE phase=? AND status='paid'
    AND YEAR(paid_at)=YEAR(CURDATE())
    AND MONTH(paid_at)=MONTH(CURDATE())
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$thisMonthCollections = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
$stmt->close();

/* This month expenses */
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) s
  FROM finance_expenses
  WHERE phase=?
    AND YEAR(expense_date)=YEAR(CURDATE())
    AND MONTH(expense_date)=MONTH(CURDATE())
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$thisMonthExpenses = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
$stmt->close();

/* =========================
   7) CHART DATA (LAST 6 MONTHS)
   ========================= */
$labels = [];
$keys   = []; // YYYY-MM

for ($i = 5; $i >= 0; $i--) {
  $ts = strtotime(date('Y-m-01') . " -$i months");
  $labels[] = date('M Y', $ts);
  $keys[]   = date('Y-m', $ts);
}

$fromDate = date('Y-m-01', strtotime(date('Y-m-01') . " -5 months"));
$toDate   = date('Y-m-t'); // end of current month

/* Monthly collections */
$collectionsByKey = array_fill_keys($keys, 0.0);
$stmt = $conn->prepare("
  SELECT DATE_FORMAT(paid_at,'%Y-%m') ym, COALESCE(SUM(amount),0) total
  FROM finance_payments
  WHERE phase=? AND status='paid'
    AND paid_at >= ?
    AND paid_at < DATE_ADD(?, INTERVAL 1 DAY)
  GROUP BY ym
");
$stmt->bind_param("sss", $phase, $fromDate, $toDate);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
  $ym = (string)$r['ym'];
  if (isset($collectionsByKey[$ym])) $collectionsByKey[$ym] = (float)$r['total'];
}
$stmt->close();

/* Monthly expenses */
$expensesByKey = array_fill_keys($keys, 0.0);
$stmt = $conn->prepare("
  SELECT DATE_FORMAT(expense_date,'%Y-%m') ym, COALESCE(SUM(amount),0) total
  FROM finance_expenses
  WHERE phase=?
    AND expense_date >= ?
    AND expense_date <= ?
  GROUP BY ym
");
$stmt->bind_param("sss", $phase, $fromDate, $toDate);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
  $ym = (string)$r['ym'];
  if (isset($expensesByKey[$ym])) $expensesByKey[$ym] = (float)$r['total'];
}
$stmt->close();

/* Monthly new homeowners */
$newHOByKey = array_fill_keys($keys, 0);
$stmt = $conn->prepare("
  SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c
  FROM homeowners
  WHERE phase=?
    AND created_at >= ?
    AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
  GROUP BY ym
");
$stmt->bind_param("sss", $phase, $fromDate, $toDate);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
  $ym = (string)$r['ym'];
  if (isset($newHOByKey[$ym])) $newHOByKey[$ym] = (int)$r['c'];
}
$stmt->close();

$chartCollections = array_values($collectionsByKey);
$chartExpenses    = array_values($expensesByKey);
$chartNewHO       = array_values($newHOByKey);

/* =========================
   8) TABLE: HOMEOWNERS (APPROVED + PENDING)
   ========================= */
$stmt = $conn->prepare("
  SELECT id, first_name, middle_name, last_name, house_lot_number, status, created_at
  FROM homeowners
  WHERE phase=? AND status IN ('approved','pending')
  ORDER BY FIELD(status,'pending','approved'), created_at DESC
  LIMIT 200
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$homeownersRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* =========================
   9) TABLE: PENDING FINANCE REPORT REQUESTS
   ========================= */
$stmt = $conn->prepare("
  SELECT r.*, a.email AS requested_by_email, a.full_name AS requested_by_name
  FROM finance_report_requests r
  LEFT JOIN admins a ON a.id=r.requested_by_admin_id
  WHERE r.phase=? AND r.status='pending'
  ORDER BY r.requested_at DESC
  LIMIT 50
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$pendingReports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ============================================================
   10) ANNOUNCEMENTS (for President dashboard)
   - Superadmin posts: phase='Superadmin'
   - HOA officer posts: phase=$phase AND audience='all_officers'
   ============================================================ */

/* ACTIVE (visible today) */
$annActive = [];
$stmt = $conn->prepare("
  SELECT a.id, a.phase, a.audience, a.title, a.category, a.message, a.start_date, a.end_date, a.priority, a.created_at,
         ad.full_name AS posted_by_name, ad.email AS posted_by_email, ad.role AS posted_by_role
  FROM announcements a
  LEFT JOIN admins ad ON ad.id = a.admin_id
  WHERE (
      (a.phase='Superadmin' AND (ad.role='superadmin' OR ad.role IS NULL))
      OR
      (a.phase=? AND a.audience='all_officers')
  )
    AND a.start_date <= CURDATE()
    AND (a.end_date IS NULL OR a.end_date >= CURDATE())
  ORDER BY FIELD(a.priority,'urgent','important','normal'), a.created_at DESC
  LIMIT 10
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $annActive[] = $r;
$stmt->close();

/* ENDED (already finished) */
$annEnded = [];
$stmt = $conn->prepare("
  SELECT a.id, a.phase, a.audience, a.title, a.category, a.message, a.start_date, a.end_date, a.priority, a.created_at,
         ad.full_name AS posted_by_name, ad.email AS posted_by_email, ad.role AS posted_by_role
  FROM announcements a
  LEFT JOIN admins ad ON ad.id = a.admin_id
  WHERE (
      (a.phase='Superadmin' AND (ad.role='superadmin' OR ad.role IS NULL))
      OR
      (a.phase=? AND a.audience='all_officers')
  )
    AND a.end_date IS NOT NULL
    AND a.end_date < CURDATE()
  ORDER BY a.end_date DESC, a.created_at DESC
  LIMIT 10
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $annEnded[] = $r;
$stmt->close();

/* Calendar range: last 60 days to next 30 days */
$calFrom = date('Y-m-d', strtotime('-60 days'));
$calTo   = date('Y-m-d', strtotime('+30 days'));

$stmt = $conn->prepare("
  SELECT a.id, a.phase, a.audience, a.title, a.category, a.message, a.start_date, a.end_date, a.priority, a.created_at,
         ad.full_name AS posted_by_name, ad.email AS posted_by_email, ad.role AS posted_by_role
  FROM announcements a
  LEFT JOIN admins ad ON ad.id = a.admin_id
  WHERE (
      (a.phase='Superadmin' AND (ad.role='superadmin' OR ad.role IS NULL))
      OR
      (a.phase=? AND a.audience='all_officers')
  )
    AND a.start_date <= ?
    AND (a.end_date IS NULL OR a.end_date >= ?)
  ORDER BY a.start_date DESC
  LIMIT 200
");
$stmt->bind_param("sss", $phase, $calTo, $calFrom);
$stmt->execute();
$annForCalendar = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Build FullCalendar events */
$calEvents = [];

foreach ($annForCalendar as $a) {
  $start = (string)$a['start_date'];
  $endInclusive = $a['end_date'] ?? null;

  $endExclusive = !empty($endInclusive)
    ? date('Y-m-d', strtotime($endInclusive . ' +1 day'))
    : date('Y-m-d', strtotime($start . ' +1 day'));

  $by = trim((string)($a['posted_by_name'] ?? ''));
  if ($by === '') $by = (string)($a['posted_by_email'] ?? 'Admin');

  $prio = (string)($a['priority'] ?? 'normal');
  $cat  = (string)($a['category'] ?? 'general');

  $endedFlag = (!empty($endInclusive) && strtotime($endInclusive) < strtotime(date('Y-m-d'))) ? 1 : 0;

  $classNames = ['cal-ann', 'prio-' . $prio, 'cat-' . $cat];
  if ($endedFlag === 1) $classNames[] = 'cal-ended';

  $calEvents[] = [
    'id'     => (string)$a['id'],
    'title'  => (string)$a['title'],
    'start'  => $start,
    'end'    => $endExclusive,
    'allDay' => true,
    'classNames' => $classNames,
    'extendedProps' => [
      'message'  => (string)($a['message'] ?? ''),
      'priority' => $prio,
      'category' => $cat,
      'postedBy' => $by,
      'phase'    => (string)($a['phase'] ?? ''),
      'audience' => (string)($a['audience'] ?? ''),
      'range'    => $start . (!empty($endInclusive) ? ' to ' . (string)$endInclusive : ''),
      'ended'    => $endedFlag
    ]
  ];
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>HOA-ADMIN</title>

  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- FullCalendar -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

  <style>
    .kpi-card .icon { font-size: 28px; opacity: .9; }
    .kpi-value { font-size: 28px; font-weight: 800; }
    .kpi-label { color: #64748b; font-weight: 700; }

    .badge-soft { padding: .35rem .6rem; border-radius: 999px; font-weight: 800; font-size: 12px; }
    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }

    .modalx {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.45);
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 16px;
    }

    .modalx .box {
      width: min(1100px, 96vw);
      max-height: 92vh;
      background: #fff;
      border-radius: 16px;
      overflow: auto;
      box-shadow: 0 20px 60px rgba(0,0,0,.25);
    }

    .modalx .boxhead {
      padding: 14px 16px;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .modalx .closebtn {
      border: none;
      background: transparent;
      font-size: 22px;
      cursor: pointer;
    }

    /* Announcements UI */
    .ann-wrap { display: flex; flex-direction: column; gap: 10px; }
    .ann-tabs { display: flex; gap: 8px; flex-wrap: wrap; }

    .ann-tab {
      border: 1px solid #e5e7eb;
      background: #fff;
      padding: 6px 10px;
      border-radius: 999px;
      font-weight: 800;
      font-size: 12px;
      cursor: pointer;
    }

    .ann-tab.active {
      background: #077f46;
      border-color: #077f46;
      color: #fff;
    }

    .ann-item {
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      padding: 12px;
      background: #fff;
    }

    .ann-item .top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 10px;
    }

    .ann-title { font-weight: 900; margin: 0; font-size: 15px; }
    .ann-meta { font-size: 12px; color: #64748b; margin-top: 2px; }
    .ann-msg  { margin-top: 8px; color: #0f172a; font-size: 13px; line-height: 1.35; white-space: pre-wrap; }

    .ann-badges { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }

    .ann-badge {
      font-size: 11px;
      font-weight: 900;
      padding: 3px 8px;
      border-radius: 999px;
      border: 1px solid #e5e7eb;
      background: #f8fafc;
      color: #0f172a;
    }

    .ann-badge.urgent { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
    .ann-badge.important { background:#fffbeb; border-color:#fed7aa; color:#9a3412; }
    .ann-badge.normal { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
    .ann-badge.ended { background:#f1f5f9; border-color:#e2e8f0; color:#475569; }
    .ann-badge.phase { background:#ecfeff; border-color:#a5f3fc; color:#155e75; }

    .ann-ended { opacity: .78; }
    .ann-ended .ann-title { text-decoration: line-through; }

    .ann-list { display: none; }
    .ann-list.show { display: block; }

    /* Calendar container */
    #annCalendar {
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      padding: 10px;
      background: #fff;
      overflow: hidden;
    }

    .fc .fc-daygrid-event {
      border-radius: 999px;
      padding: 2px 8px;
      font-weight: 800;
      border-width: 1px;
    }

    /* Priority via classNames */
    .fc .prio-urgent { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
    .fc .prio-important { background:#fffbeb; border-color:#fed7aa; color:#9a3412; }
    .fc .prio-normal { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }

    /* Ended events muted */
    .fc .cal-ended { opacity: .75; }

    .mini-muted { color: #64748b; font-size: 12px; }
    .kv { display: flex; flex-wrap: wrap; gap: 10px; }
    .kv > div { min-width: 160px; }
    .kv b { font-weight: 900; }
  </style>
</head>

<body>

  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
      <div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>
    </div>

    <div class="header-right">
      <div class="user-info-dropdown">
        <div class="dropdown">
          <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
            <span class="user-icon"><img src="vendors/images/photo1.jpg" alt=""></span>
            <span class="user-name"><?= esc(strtoupper($adminName !== '' ? $adminName : ($adminEmail ?: 'ADMIN'))) ?></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="logout.php"><i class="dw dw-logout"></i> Log Out</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="left-side-bar" style="background-color: #077f46;">
    <div class="brand-logo">
      <a href="dashboard.php">
        <img src="vendors/images/deskapp-logo.svg" alt="" class="dark-logo">
        <img src="vendors/images/deskapp-logo-white.svg" alt="" class="light-logo">
      </a>
      <div class="close-sidebar" data-toggle="left-sidebar-close">
        <i class="ion-close-round"></i>
      </div>
    </div>

    <div class="menu-block customscroll">
      <div class="sidebar-menu">
        <ul id="accordion-menu">
          <li>
            <a href="dashboard.php" class="dropdown-toggle no-arrow">
              <span class="micon dw dw-house-1"></span>
              <span class="mtext">Dashboard</span>
            </a>
          </li>

          <li class="dropdown">
            <a href="javascript:;" class="dropdown-toggle ">
              <span class="micon dw dw-user"></span>
              <span class="mtext">Homeowner Management</span>
            </a>
            <ul class="submenu">
              <li><a href="ho_approval.php">Household Approval</a></li>
              <li><a href="ho_register.php">Register Household</a></li>
              <li><a href="ho_approved.php">Approved Households</a></li>
            </ul>
          </li>

          <!-- USER MANAGEMENT -->
          <?php $view = $_GET['view'] ?? ''; ?>
          <li class="dropdown">
            <a href="javascript:;" class="dropdown-toggle <?= ($view === 'homeowners' || $view === 'officers') ? 'active' : '' ?>">
              <span class="micon dw dw-user"></span>
              <span class="mtext">User Management</span>
            </a>
            <ul class="submenu">
              <li><a href="users-management.php?view=homeowners" class="<?= $view === 'homeowners' ? 'active' : '' ?>">Homeowners</a></li>
              <li><a href="users-management.php?view=officers" class="<?= $view === 'officers' ? 'active' : '' ?>">Officers</a></li>
            </ul>
          </li>

          <li>
            <a href="announcements.php" class="dropdown-toggle no-arrow">
              <span class="micon dw dw-megaphone"></span>
              <span class="mtext">Announcement</span>
            </a>
          </li>

          <li class="dropdown">
            <a href="javascript:;" class="dropdown-toggle">
              <span class="micon dw dw-money-1"></span>
              <span class="mtext">Finance</span>
            </a>
            <ul class="submenu">
              <li><a href="finance.php">Overview</a></li>
              <li><a href="finance_dues.php">Monthly Dues</a></li>
              <li><a href="finance_donations.php">Donations</a></li>
              <li><a href="finance_expenses.php">Expenses</a></li>
              <li><a href="finance_reports.php">Financial Reports</a></li>
              <li><a href="finance_cashflow.php">Cash Flow Dashboard</a></li>
            </ul>
          </li>

          <li class="dropdown">
            <a href="javascript:;" class="dropdown-toggle">
              <span class="micon dw dw-car"></span>
              <span class="mtext">Parking</span>
            </a>
            <ul class="submenu">
              <li><a href="parking.php">Parking Overview</a></li>
              <li><a href="parking_permits.php">Manage Permits</a></li>
              <li><a href="parking_violations.php">View Violations</a></li>
            </ul>
          </li>

          <li>
            <a href="#" class="dropdown-toggle no-arrow">
              <span class="micon dw dw-settings2"></span>
              <span class="mtext">Settings</span>
            </a>
          </li>

        </ul>
      </div>
    </div>
  </div>

  <div class="mobile-menu-overlay"></div>

  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header mb-20">
        <div class="row">
          <div class="col-md-12 col-sm-12">
            <div class="title"><h4>President Dashboard</h4></div>
            <div class="text-secondary">
              Phase: <b><?= esc($phase) ?></b> |
              President: <b><?= esc($presidentName) ?></b>
              <?php if ($presidentEmail !== ''): ?>
                <span class="text-muted">(<?= esc($presidentEmail) ?>)</span>
              <?php endif; ?>
              | <span class="badge-soft badge-soft-success">YOU</span>
            </div>
          </div>
        </div>
      </div>

      <!-- TOP ROW -->
      <div class="row">
        <!-- LEFT -->
        <div class="col-lg-7 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p mb-20">
            <div class="row align-items-center">
              <div class="col-md-4"><img src="vendors/images/banner-img.png" alt=""></div>
              <div class="col-md-8">
                <h4 class="font-20 weight-500 mb-10 text-capitalize">
                  <div class="weight-600 font-30 text-blue">Welcome, President!</div>
                </h4>
                <p class="font-18 max-width-600">
                  Live view of key HOA operations for <b><?= esc($phase) ?></b> — registrations, finance health, and items waiting for approval.
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- RIGHT (Announcements) -->
        <div class="col-lg-5 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p">
            <div class="d-flex justify-content-between align-items-center mb-10">
              <h4 class="h5 mb-0">Announcements</h4>
            </div>

            <div class="ann-wrap">
              <div class="ann-tabs">
                <button type="button" class="ann-tab active" id="tabActive">
                  Active (<?= count($annActive) ?>)
                </button>
                <button type="button" class="ann-tab" id="tabEnded">
                  Ended (<?= count($annEnded) ?>)
                </button>
                <button type="button" class="ann-tab" id="tabCalendar">
                  Calendar
                </button>
              </div>

              <div class="mini-muted">
                Shows Superadmin announcements + your phase HOA Officers announcements.
              </div>

              <!-- ACTIVE -->
              <div class="ann-list show" id="listActive">
                <?php if (empty($annActive)): ?>
                  <div class="text-secondary">No active announcements.</div>
                <?php else: ?>
                  <?php foreach ($annActive as $a): ?>
                    <?php
                      $prio = (string)($a['priority'] ?? 'normal');
                      $prioClass = ($prio === 'urgent') ? 'urgent' : (($prio === 'important') ? 'important' : 'normal');

                      $range = date("M d, Y", strtotime((string)$a['start_date']));
                      if (!empty($a['end_date'])) $range .= " - " . date("M d, Y", strtotime((string)$a['end_date']));

                      $by = trim((string)($a['posted_by_name'] ?? ''));
                      if ($by === '') $by = (string)($a['posted_by_email'] ?? 'Admin');

                      $srcPhase = (string)($a['phase'] ?? '');
                      $isOfficerPost = ($srcPhase === $phase && (string)($a['audience'] ?? '') === 'all_officers');
                      $sourceLabel = $isOfficerPost ? 'HOA OFFICERS' : 'SUPERADMIN';
                    ?>
                    <div class="ann-item">
                      <div class="top">
                        <div>
                          <p class="ann-title"><?= esc($a['title']) ?></p>
                          <div class="ann-meta">
                            <?= esc($range) ?> • Posted by <?= esc($by) ?>
                          </div>
                        </div>
                        <div class="ann-badges">
                          <span class="ann-badge phase"><?= esc($sourceLabel) ?></span>
                          <span class="ann-badge <?= esc($prioClass) ?>"><?= esc(strtoupper($prio)) ?></span>
                          <span class="ann-badge"><?= esc(strtoupper((string)($a['category'] ?? 'general'))) ?></span>
                        </div>
                      </div>
                      <div class="ann-msg"><?= esc((string)($a['message'] ?? '')) ?></div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <!-- ENDED -->
              <div class="ann-list" id="listEnded">
                <?php if (empty($annEnded)): ?>
                  <div class="text-secondary">No ended announcements.</div>
                <?php else: ?>
                  <?php foreach ($annEnded as $a): ?>
                    <?php
                      $prio = (string)($a['priority'] ?? 'normal');
                      $prioClass = ($prio === 'urgent') ? 'urgent' : (($prio === 'important') ? 'important' : 'normal');

                      $range = date("M d, Y", strtotime((string)$a['start_date']));
                      if (!empty($a['end_date'])) $range .= " - " . date("M d, Y", strtotime((string)$a['end_date']));

                      $by = trim((string)($a['posted_by_name'] ?? ''));
                      if ($by === '') $by = (string)($a['posted_by_email'] ?? 'Admin');

                      $endedOn = !empty($a['end_date']) ? date("M d, Y", strtotime((string)$a['end_date'])) : '';

                      $srcPhase = (string)($a['phase'] ?? '');
                      $isOfficerPost = ($srcPhase === $phase && (string)($a['audience'] ?? '') === 'all_officers');
                      $sourceLabel = $isOfficerPost ? 'HOA OFFICERS' : 'SUPERADMIN';
                    ?>
                    <div class="ann-item ann-ended">
                      <div class="top">
                        <div>
                          <p class="ann-title"><?= esc($a['title']) ?></p>
                          <div class="ann-meta">
                            <?= esc($range) ?> • Ended: <b><?= esc($endedOn) ?></b> • Posted by <?= esc($by) ?>
                          </div>
                        </div>
                        <div class="ann-badges">
                          <span class="ann-badge ended">ENDED</span>
                          <span class="ann-badge phase"><?= esc($sourceLabel) ?></span>
                          <span class="ann-badge <?= esc($prioClass) ?>"><?= esc(strtoupper($prio)) ?></span>
                          <span class="ann-badge"><?= esc(strtoupper((string)($a['category'] ?? 'general'))) ?></span>
                        </div>
                      </div>
                      <div class="ann-msg"><?= esc((string)($a['message'] ?? '')) ?></div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <!-- CALENDAR -->
              <div class="ann-list" id="listCalendar">
                <div id="annCalendar"></div>
              </div>

            </div>
          </div>
        </div>
      </div>

      <!-- KPI CARDS -->
      <div class="row">
        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Approved Homeowners</div>
                <div class="kpi-value"><?= nfmt($approvedCount) ?></div>
              </div>
              <div class="icon text-success"><i class="dw dw-user"></i></div>
            </div>
            <div class="mt-2 text-secondary">Active members this phase</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Pending Homeowners</div>
                <div class="kpi-value"><?= nfmt($pendingCount) ?></div>
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
                <div class="kpi-label">Report Approvals</div>
                <div class="kpi-value"><?= nfmt($pendingReportCount) ?></div>
              </div>
              <div class="icon text-danger"><i class="dw dw-file-3"></i></div>
            </div>
            <div class="mt-2">
              <a href="finance_reports.php" class="text-primary font-weight-bold">Review pending reports →</a>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">This Month Net</div>
                <?php $net = $thisMonthCollections - $thisMonthExpenses; ?>
                <div class="kpi-value"><?= money($net) ?></div>
              </div>
              <div class="icon text-info"><i class="dw dw-money"></i></div>
            </div>
            <div class="mt-2 text-secondary">
              Collections: <?= money($thisMonthCollections) ?> • Expenses: <?= money($thisMonthExpenses) ?>
            </div>
          </div>
        </div>
      </div>

      <!-- CHART -->
      <div class="row">
        <div class="col-xl-12 mb-30">
          <div class="card-box height-100-p pd-20">
            <div class="d-flex justify-content-between align-items-center mb-10">
              <h2 class="h4 mb-0">Operations Overview (Last 6 Months)</h2>
              <span class="badge-soft badge-soft-info">Collections vs Expenses + New Homeowners</span>
            </div>
            <canvas id="activityChart" height="95"></canvas>
          </div>
        </div>
      </div>

      <!-- PENDING REPORTS TABLE -->
      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">To Be Approved Reports</h5>
          <a class="btn btn-sm btn-outline-primary" href="finance_reports.php">Open Finance Reports</a>
        </div>

        <div class="table-responsive">
          <table class="table table-striped table-hover mb-0">
            <thead>
              <tr>
                <th>Requested At</th>
                <th>Period</th>
                <th>Requested By</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($pendingReports)): ?>
                <?php foreach ($pendingReports as $r): ?>
                  <?php
                    $who = trim((string)($r['requested_by_name'] ?? ''));
                    if ($who === '') $who = (string)($r['requested_by_email'] ?? '');
                    $period = (string)($r['report_year'] ?? '') . '-' . str_pad((string)($r['report_month'] ?? ''), 2, '0', STR_PAD_LEFT);
                  ?>
                  <tr>
                    <td><?= esc((string)($r['requested_at'] ?? '')) ?></td>
                    <td><?= esc($period) ?></td>
                    <td><?= esc($who) ?></td>
                    <td><span class="badge-soft badge-soft-warning">pending</span></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="4" class="text-center text-secondary">No pending report approvals.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- HOMEOWNERS TABLE -->
      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Homeowners List (Approved + Pending)</h5>
          <span class="text-secondary">Phase: <b><?= esc($phase) ?></b></span>
        </div>

        <div class="table-responsive">
          <table id="homeownersTable" class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Blk/Lot</th>
                <th>Status</th>
                <th>Registered</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($homeownersRows)): ?>
                <?php foreach ($homeownersRows as $h): ?>
                  <?php
                    $full = trim((string)($h['first_name'] ?? '') . ' ' . (string)($h['middle_name'] ?? '') . ' ' . (string)($h['last_name'] ?? ''));
                    $st = (string)($h['status'] ?? 'pending');
                    $badge = $st === 'approved' ? 'badge-soft-success' : 'badge-soft-warning';
                  ?>
                  <tr>
                    <td><?= (int)$h['id'] ?></td>
                    <td><?= esc($full) ?></td>
                    <td><?= esc((string)($h['house_lot_number'] ?? '')) ?></td>
                    <td><span class="badge-soft <?= esc($badge) ?>"><?= esc($st) ?></span></td>
                    <td><?= esc((string)($h['created_at'] ?? '')) ?></td>
                    <td class="text-center">
                      <button type="button" class="btn btn-sm btn-outline-primary viewHomeowner" data-id="<?= (int)$h['id'] ?>" title="View">
                        <i class="dw dw-eye"></i>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="6" class="text-center text-secondary">No homeowners found for this phase.</td></tr>
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

  <!-- VIEW MODAL -->
  <div class="modalx" id="viewModal">
    <div class="box">
      <div class="boxhead">
        <div class="font-weight-bold">Homeowner Profile</div>
        <button class="closebtn" type="button" id="closeViewModal">&times;</button>
      </div>
      <div id="viewModalBody" style="min-height:120px;">
        <div class="p-3 text-secondary">Loading...</div>
      </div>
    </div>
  </div>

  <!-- ANNOUNCEMENT MODAL -->
  <div class="modalx" id="annModal">
    <div class="box" style="width:min(780px, 96vw);">
      <div class="boxhead">
        <div class="font-weight-bold">Announcement</div>
        <button class="closebtn" type="button" id="closeAnnModal">&times;</button>
      </div>
      <div id="annModalBody" class="p-3">
        <div class="text-secondary">Loading...</div>
      </div>
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

  <script>
    // DataTables init
    $(document).ready(function () {
      $('#homeownersTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [],
        columnDefs: [{ orderable: false, targets: 5 }]
      });
    });

    // View modal (loads profile from HO-management.php)
    const viewModal = document.getElementById('viewModal');
    const viewBody  = document.getElementById('viewModalBody');

    function openViewModal() {
      viewModal.style.display = 'flex';
    }

    function closeViewModal() {
      viewModal.style.display = 'none';
      viewBody.innerHTML = '<div class="p-3 text-secondary">Loading...</div>';
    }

    document.getElementById('closeViewModal').addEventListener('click', closeViewModal);
    viewModal.addEventListener('click', (e) => { if (e.target === viewModal) closeViewModal(); });

    $(document).on('click', '.viewHomeowner', function () {
      const id = $(this).data('id');

      openViewModal();
      viewBody.innerHTML = '<div class="p-3 text-secondary">Loading profile...</div>';

      $.get('HO-management.php', { ajax: 'homeowner_profile', id: id, _: Date.now() }, function (html) {
        viewBody.innerHTML = html;
      }).fail(function () {
        viewBody.innerHTML = '<div class="p-3"><div class="alert alert-danger mb-0">Failed to load profile.</div></div>';
      });
    });

    // Announcements tabs + calendar init
    (function () {
      const tabA = document.getElementById('tabActive');
      const tabE = document.getElementById('tabEnded');
      const tabC = document.getElementById('tabCalendar');

      const listA = document.getElementById('listActive');
      const listE = document.getElementById('listEnded');
      const listC = document.getElementById('listCalendar');

      let calInited = false;
      let calendar = null;

      function setActive(btn) {
        [tabA, tabE, tabC].forEach(t => t && t.classList.remove('active'));
        if (btn) btn.classList.add('active');
      }

      function showOnly(which) {
        [listA, listE, listC].forEach(l => l && l.classList.remove('show'));
        if (which) which.classList.add('show');
      }

      function showActive() {
        setActive(tabA);
        showOnly(listA);
      }

      function showEnded() {
        setActive(tabE);
        showOnly(listE);
      }

      function showCalendar() {
        setActive(tabC);
        showOnly(listC);

        if (!calInited) {
          const el = document.getElementById('annCalendar');
          if (!el) return;

          const events = <?= json_encode($calEvents) ?>;

          calendar = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            height: 'auto',
            headerToolbar: {
              left: 'prev,next today',
              center: 'title',
              right: 'dayGridMonth,listWeek'
            },
            events: events,
            eventDidMount: function (info) {
              const ep = info.event.extendedProps || {};

              const source = (ep.audience === 'all_officers' && ep.phase && ep.phase !== 'Superadmin')
                ? ('HOA OFFICERS • ' + ep.phase)
                : 'SUPERADMIN';

              const tip = [
                info.event.title,
                (ep.range ? ('Dates: ' + ep.range) : ''),
                ('Source: ' + source),
                ('Priority: ' + (ep.priority || 'normal')),
                ('Category: ' + (ep.category || 'general'))
              ].filter(Boolean).join('\n');

              info.el.setAttribute('title', tip);
            },
            eventClick: function (info) {
              info.jsEvent.preventDefault();
              openAnnModal(info.event);
            }
          });

          calendar.render();
          calInited = true;
          setTimeout(() => calendar.updateSize(), 80);
        } else {
          setTimeout(() => calendar && calendar.updateSize(), 50);
        }
      }

      if (tabA) tabA.addEventListener('click', showActive);
      if (tabE) tabE.addEventListener('click', showEnded);
      if (tabC) tabC.addEventListener('click', showCalendar);
    })();

    // Announcement modal
    const annModal = document.getElementById('annModal');
    const annBody  = document.getElementById('annModalBody');

    function openAnnModal(event) {
      const ep = event.extendedProps || {};

      const title = escapeHtml(event.title || '');
      const msg   = escapeHtml(ep.message || '');
      const pri   = escapeHtml(ep.priority || 'normal');
      const cat   = escapeHtml(ep.category || 'general');
      const by    = escapeHtml(ep.postedBy || 'Admin');
      const range = escapeHtml(ep.range || '');

      const source = (ep.audience === 'all_officers' && ep.phase && ep.phase !== 'Superadmin')
        ? ('HOA OFFICERS • ' + ep.phase)
        : 'SUPERADMIN';

      annBody.innerHTML = `
        <div class="mb-2" style="font-weight:900;font-size:16px;">${title}</div>
        <div class="kv mb-2">
          <div><span class="mini-muted">Dates</span><br><b>${range || '—'}</b></div>
          <div><span class="mini-muted">Source</span><br><b>${escapeHtml(source)}</b></div>
          <div><span class="mini-muted">Priority</span><br><b>${pri.toUpperCase()}</b></div>
          <div><span class="mini-muted">Category</span><br><b>${cat.toUpperCase()}</b></div>
          <div><span class="mini-muted">Posted By</span><br><b>${by}</b></div>
        </div>
        <div class="mini-muted mb-1">Message</div>
        <div style="white-space:pre-wrap;line-height:1.4;">${msg || '—'}</div>
      `;

      annModal.style.display = 'flex';
    }

    function closeAnnModal() {
      annModal.style.display = 'none';
      annBody.innerHTML = '<div class="text-secondary">Loading...</div>';
    }

    document.getElementById('closeAnnModal').addEventListener('click', closeAnnModal);
    annModal.addEventListener('click', (e) => { if (e.target === annModal) closeAnnModal(); });

    function escapeHtml(str) {
      return String(str ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    // Chart.js
    const labels = <?= json_encode($labels) ?>;
    const collections = <?= json_encode($chartCollections) ?>;
    const expenses = <?= json_encode($chartExpenses) ?>;
    const newHO = <?= json_encode($chartNewHO) ?>;

    const ctx = document.getElementById('activityChart').getContext('2d');

    new Chart(ctx, {
      data: {
        labels,
        datasets: [
          { type: 'bar',  label: 'Collections (Paid)', data: collections, borderWidth: 1 },
          { type: 'bar',  label: 'Expenses',           data: expenses,    borderWidth: 1 },
          { type: 'line', label: 'New Homeowners (Registrations)', data: newHO, borderWidth: 2, tension: 0.25, yAxisID: 'y2' }
        ]
      },
      options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: true } },
        scales: {
          y:  { beginAtZero: true, title: { display: true, text: 'Amount (₱)' } },
          y2: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Count' } }
        }
      }
    });
  </script>
</body>
</html>