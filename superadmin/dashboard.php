<?php
session_start();

// OPTIONAL: enforce superadmin
// if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') { header("Location: authentication-login.html"); exit; }

// ===================== DB =====================
$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nfmt($n){ return number_format((float)$n, 0); }
function money($n){ return '₱' . number_format((float)$n, 2); }

// ===================== DATE RANGES =====================
$now = new DateTime("now");
$today = $now->format("Y-m-d");

$start7 = (new DateTime("now"))->modify("-6 days")->format("Y-m-d 00:00:00");
$startPrev7 = (new DateTime("now"))->modify("-13 days")->format("Y-m-d 00:00:00");
$endPrev7 = (new DateTime("now"))->modify("-7 days")->format("Y-m-d 23:59:59");
$start30 = (new DateTime("now"))->modify("-29 days")->format("Y-m-d 00:00:00");

// ===================== KPIs =====================

// Total approved homeowners
$approved_homeowners = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM homeowners WHERE status='approved'");
$stmt->execute();
$approved_homeowners = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// Total homeowners
$total_homeowners = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM homeowners");
$stmt->execute();
$total_homeowners = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$active_homeowners_pct = ($total_homeowners > 0) ? round(($approved_homeowners / $total_homeowners) * 100) : 0;

// Active participants last 30 days
$active_participants_30d = 0;
$stmt = $conn->prepare("
  SELECT COUNT(DISTINCT homeowner_id) AS c
  FROM finance_payments
  WHERE status='paid' AND paid_at >= ?
");
$stmt->bind_param("s", $start30);
$stmt->execute();
$active_participants_30d = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// Weekly dues
$dues_week = 0.0;
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) AS s
  FROM finance_payments
  WHERE status='paid' AND paid_at >= ?
");
$stmt->bind_param("s", $start7);
$stmt->execute();
$dues_week = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
$stmt->close();

// Previous weekly dues
$dues_prev_week = 0.0;
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) AS s
  FROM finance_payments
  WHERE status='paid' AND paid_at BETWEEN ? AND ?
");
$stmt->bind_param("ss", $startPrev7, $endPrev7);
$stmt->execute();
$dues_prev_week = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
$stmt->close();

$dues_change_pct = 0;
if ($dues_prev_week > 0) {
  $dues_change_pct = round((($dues_week - $dues_prev_week) / $dues_prev_week) * 100);
} elseif ($dues_week > 0) {
  $dues_change_pct = 100;
}

// Maintenance expenses this week
$maintenance_expenses_week = 0;
$stmt = $conn->prepare("
  SELECT COUNT(*) AS c
  FROM finance_expenses
  WHERE category='maintenance' AND expense_date >= DATE(?)
");
$stmt->bind_param("s", $start7);
$stmt->execute();
$maintenance_expenses_week = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// Parking violations this week
$concerns_week = 0;
$stmt = $conn->prepare("
  SELECT COUNT(*) AS c
  FROM parking_violations
  WHERE issued_at >= ?
");
$stmt->bind_param("s", $start7);
$stmt->execute();
$concerns_week = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// Pending items
$pending_homeowners = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM homeowners WHERE status='pending'");
$stmt->execute();
$pending_homeowners = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$pending_permits = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM parking_permits WHERE status='pending'");
$stmt->execute();
$pending_permits = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$pending_reports = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM finance_report_requests WHERE status='pending'");
$stmt->execute();
$pending_reports = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$notif_total = $pending_homeowners + $pending_permits + $pending_reports;

// ===================== CHART DATA =====================
$phases = ['Phase 1','Phase 2','Phase 3'];
$approved_by_phase = array_fill_keys($phases, 0);
$active_by_phase = array_fill_keys($phases, 0);

// Approved homeowners by phase
$stmt = $conn->prepare("
  SELECT phase, COUNT(*) AS c
  FROM homeowners
  WHERE status='approved'
  GROUP BY phase
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $p = (string)$row['phase'];
  if (isset($approved_by_phase[$p])) $approved_by_phase[$p] = (int)$row['c'];
}
$stmt->close();

// Active participants by phase
$stmt = $conn->prepare("
  SELECT phase, COUNT(DISTINCT homeowner_id) AS c
  FROM finance_payments
  WHERE status='paid' AND paid_at >= ?
  GROUP BY phase
");
$stmt->bind_param("s", $start30);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $p = (string)$row['phase'];
  if (isset($active_by_phase[$p])) $active_by_phase[$p] = (int)$row['c'];
}
$stmt->close();

// Last 6 months collections/expenses/homeowners for chart
$labels = [];
$keys   = [];

for ($i = 5; $i >= 0; $i--) {
  $ts = strtotime(date('Y-m-01') . " -$i months");
  $labels[] = date('M Y', $ts);
  $keys[]   = date('Y-m', $ts);
}

$fromDate = date('Y-m-01', strtotime(date('Y-m-01') . " -5 months"));
$toDate   = date('Y-m-t');

$collectionsByKey = array_fill_keys($keys, 0.0);
$stmt = $conn->prepare("
  SELECT DATE_FORMAT(paid_at,'%Y-%m') ym, COALESCE(SUM(amount),0) total
  FROM finance_payments
  WHERE status='paid'
    AND paid_at >= ?
    AND paid_at < DATE_ADD(?, INTERVAL 1 DAY)
  GROUP BY ym
");
$stmt->bind_param("ss", $fromDate, $toDate);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
  $ym = (string)$r['ym'];
  if (isset($collectionsByKey[$ym])) $collectionsByKey[$ym] = (float)$r['total'];
}
$stmt->close();

$expensesByKey = array_fill_keys($keys, 0.0);
$stmt = $conn->prepare("
  SELECT DATE_FORMAT(expense_date,'%Y-%m') ym, COALESCE(SUM(amount),0) total
  FROM finance_expenses
  WHERE expense_date >= ?
    AND expense_date <= ?
  GROUP BY ym
");
$stmt->bind_param("ss", $fromDate, $toDate);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
  $ym = (string)$r['ym'];
  if (isset($expensesByKey[$ym])) $expensesByKey[$ym] = (float)$r['total'];
}
$stmt->close();

$newHOByKey = array_fill_keys($keys, 0);
$stmt = $conn->prepare("
  SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c
  FROM homeowners
  WHERE created_at >= ?
    AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
  GROUP BY ym
");
$stmt->bind_param("ss", $fromDate, $toDate);
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

// ===================== TABLES =====================

// Latest homeowners
$latest_homeowners = [];
$stmt = $conn->prepare("
  SELECT id, first_name, middle_name, last_name, phase, house_lot_number, status, created_at
  FROM homeowners
  ORDER BY created_at DESC
  LIMIT 100
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $latest_homeowners[] = $row;
$stmt->close();

// Recent admins/officers
$latest_officers = [];
if ($result = $conn->query("
  SELECT id, full_name, email, role, phase, position
  FROM admins
  ORDER BY id DESC
  LIMIT 20
")) {
  while ($row = $result->fetch_assoc()) $latest_officers[] = $row;
  $result->close();
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Superadmin Dashboard</title>

  <link rel="apple-touch-icon" sizes="180x180" href="../admin/vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="../admin/vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="../admin/vendors/images/favicon-16x16.png">

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="../admin/src/plugins/datatables/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="../admin/src/plugins/datatables/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/style.css">

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    .kpi-card .icon { font-size: 28px; opacity: .9; }
    .kpi-value { font-size: 28px; font-weight: 800; }
    .kpi-label { color: #64748b; font-weight: 700; }

    .badge-soft {
      padding: .35rem .6rem;
      border-radius: 999px;
      font-weight: 800;
      font-size: 12px;
      display: inline-block;
    }

    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }
    .badge-soft-secondary { background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; }
    .badge-soft-danger { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }

    .sidebar-menu .dropdown-toggle:hover,
    .sidebar-menu .show > .dropdown-toggle {
      background: #077f46;
      color: #fff !important;
    }

    .sidebar-menu .submenu li a:hover,
    .sidebar-menu .submenu li a.active {
      background: rgba(7,127,70,.10);
      color: #077f46 !important;
      font-weight: 700;
    }

    .table-action-btn {
      min-width: 38px;
    }
  </style>
</head>

<body>
  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
    </div>

    <div class="header-right">
      <div class="user-notification">
        <div class="dropdown">
          <a class="dropdown-toggle no-arrow" href="#" role="button" data-toggle="dropdown">
            <i class="icon-copy dw dw-notification"></i>
            <?php if ($notif_total > 0): ?>
              <span class="badge notification-active"></span>
            <?php endif; ?>
          </a>
          <div class="dropdown-menu dropdown-menu-right">
            <div class="notification-list mx-h-350 customscroll">
              <ul>
                <li>
                  <a href="user_management.php">
                    <h3>Pending Homeowners</h3>
                    <p><?= (int)$pending_homeowners ?> waiting for approval</p>
                  </a>
                </li>
                <li>
                  <a href="#">
                    <h3>Pending Parking Permits</h3>
                    <p><?= (int)$pending_permits ?> waiting for review</p>
                  </a>
                </li>
                <li>
                  <a href="#">
                    <h3>Pending Finance Reports</h3>
                    <p><?= (int)$pending_reports ?> pending requests</p>
                  </a>
                </li>
                <?php if ($notif_total === 0): ?>
                  <li>
                    <a href="#">
                      <h3>No Pending Items</h3>
                      <p>Everything is updated.</p>
                    </a>
                  </li>
                <?php endif; ?>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="user-info-dropdown">
        <div class="dropdown">
          <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
            <span class="user-icon">
              <img src="../admin/vendors/images/photo1.jpg" alt="">
            </span>
            <span class="user-name">Superadmin</span>
          </a>
          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="profile.html"><i class="dw dw-user1"></i> Profile</a>
            <a class="dropdown-item" href="logs.html"><i class="dw dw-list3"></i> Activity Logs</a>
            <a class="dropdown-item" href="../index.php"><i class="dw dw-logout"></i> Log Out</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php include 'superadmin_sidebar.php'; ?>
  
  <div class="mobile-menu-overlay"></div>

  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header mb-20">
        <div class="row">
          <div class="col-md-12 col-sm-12">
            <div class="title"><h4>Superadmin Dashboard</h4></div>
            <div class="text-secondary">
              Logged in as: <b>Superadmin</b> |
              Scope: <b>All Phases</b>
            </div>
          </div>
        </div>
      </div>

      <!-- TOP ROW -->
      <div class="row">
        <div class="col-lg-7 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p mb-20">
            <div class="row align-items-center">
              <div class="col-md-4">
                <img src="../admin/vendors/images/banner-img.png" alt="">
              </div>
              <div class="col-md-8">
                <h4 class="font-20 weight-500 mb-10 text-capitalize">
                  <div class="weight-600 font-30 text-blue">Welcome, Superadmin!</div>
                </h4>
                <p class="font-18 max-width-600">
                  Live overview of all phases — homeowners, approvals, collections, expenses, active participants, and system-wide pending items.
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-5 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p">
            <div class="d-flex justify-content-between align-items-center mb-10">
              <h4 class="h5 mb-0">Quick Summary</h4>
            </div>

            <div class="mb-15">
              <div class="d-flex justify-content-between mb-2">
                <span class="text-secondary">Pending Homeowners</span>
                <span class="badge-soft badge-soft-warning"><?= nfmt($pending_homeowners) ?></span>
              </div>
              <div class="d-flex justify-content-between mb-2">
                <span class="text-secondary">Pending Parking Permits</span>
                <span class="badge-soft badge-soft-warning"><?= nfmt($pending_permits) ?></span>
              </div>
              <div class="d-flex justify-content-between mb-2">
                <span class="text-secondary">Pending Finance Reports</span>
                <span class="badge-soft badge-soft-warning"><?= nfmt($pending_reports) ?></span>
              </div>
              <div class="d-flex justify-content-between">
                <span class="font-weight-bold">Total Pending Items</span>
                <span class="badge-soft badge-soft-danger"><?= nfmt($notif_total) ?></span>
              </div>
            </div>

            <hr>

            <div class="mb-2 text-secondary">Phase Approvals</div>
            <?php foreach ($phases as $p): ?>
              <div class="d-flex justify-content-between mb-2">
                <span><?= esc($p) ?></span>
                <span class="badge-soft badge-soft-success"><?= nfmt($approved_by_phase[$p]) ?> approved</span>
              </div>
            <?php endforeach; ?>

            <div class="mt-3">
              <a href="user_management.php" class="text-primary font-weight-bold">Open User Management →</a>
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
                <div class="kpi-value"><?= nfmt($approved_homeowners) ?></div>
              </div>
              <div class="icon text-success"><i class="dw dw-user"></i></div>
            </div>
            <div class="mt-2 text-secondary">System-wide approved records</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Total Homeowners</div>
                <div class="kpi-value"><?= nfmt($total_homeowners) ?></div>
              </div>
              <div class="icon text-primary"><i class="dw dw-group"></i></div>
            </div>
            <div class="mt-2 text-secondary">All statuses included</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Active Participants</div>
                <div class="kpi-value"><?= nfmt($active_participants_30d) ?></div>
              </div>
              <div class="icon text-info"><i class="dw dw-analytics-21"></i></div>
            </div>
            <div class="mt-2 text-secondary">Distinct payers in last 30 days</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Pending Items</div>
                <div class="kpi-value"><?= nfmt($notif_total) ?></div>
              </div>
              <div class="icon text-danger"><i class="dw dw-bell"></i></div>
            </div>
            <div class="mt-2 text-secondary">Homeowners, permits, reports</div>
          </div>
        </div>
      </div>

      <!-- SECOND KPI ROW -->
      <div class="row">
        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Weekly Dues</div>
                <div class="kpi-value"><?= money($dues_week) ?></div>
              </div>
              <div class="icon text-success"><i class="dw dw-money-1"></i></div>
            </div>
            <div class="mt-2 text-secondary">
              Change vs previous week:
              <b class="<?= $dues_change_pct >= 0 ? 'text-success' : 'text-danger' ?>">
                <?= $dues_change_pct >= 0 ? '+' : '' ?><?= (int)$dues_change_pct ?>%
              </b>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Approved Rate</div>
                <div class="kpi-value"><?= (int)$active_homeowners_pct ?>%</div>
              </div>
              <div class="icon text-primary"><i class="dw dw-check"></i></div>
            </div>
            <div class="mt-2 text-secondary"><?= nfmt($approved_homeowners) ?> of <?= nfmt($total_homeowners) ?> homeowners</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Maintenance Logs</div>
                <div class="kpi-value"><?= nfmt($maintenance_expenses_week) ?></div>
              </div>
              <div class="icon text-warning"><i class="dw dw-tools"></i></div>
            </div>
            <div class="mt-2 text-secondary">Recorded in the last 7 days</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Parking Violations</div>
                <div class="kpi-value"><?= nfmt($concerns_week) ?></div>
              </div>
              <div class="icon text-danger"><i class="dw dw-warning"></i></div>
            </div>
            <div class="mt-2 text-secondary">Issued in the last 7 days</div>
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

      <!-- PHASE COMPARISON -->
      <div class="row">
        <div class="col-xl-12 mb-30">
          <div class="card-box height-100-p pd-20">
            <div class="d-flex justify-content-between align-items-center mb-10">
              <h2 class="h4 mb-0">Phase Comparison</h2>
              <span class="badge-soft badge-soft-secondary">Approved Homeowners vs Active Participants</span>
            </div>
            <canvas id="phaseChart" height="90"></canvas>
          </div>
        </div>
      </div>

      <!-- HOMEOWNERS TABLE -->
      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Latest Homeowners</h5>
          <a class="btn btn-sm btn-outline-primary" href="user_management.php">Open User Management</a>
        </div>

        <div class="table-responsive">
          <table id="homeownersTable" class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Phase</th>
                <th>Blk/Lot</th>
                <th>Status</th>
                <th>Registered</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($latest_homeowners)): ?>
                <?php foreach ($latest_homeowners as $h): ?>
                  <?php
                    $full = trim((string)$h['first_name'].' '.(string)$h['middle_name'].' '.(string)$h['last_name']);
                    $status = (string)$h['status'];
                    $badge = 'badge-soft-secondary';
                    if ($status === 'approved') $badge = 'badge-soft-success';
                    elseif ($status === 'pending') $badge = 'badge-soft-warning';
                    elseif ($status === 'rejected') $badge = 'badge-soft-danger';
                  ?>
                  <tr>
                    <td><?= (int)$h['id'] ?></td>
                    <td><?= esc($full) ?></td>
                    <td><?= esc((string)$h['phase']) ?></td>
                    <td><?= esc((string)$h['house_lot_number']) ?></td>
                    <td><span class="badge-soft <?= esc($badge) ?>"><?= esc(ucfirst($status)) ?></span></td>
                    <td><?= esc((string)$h['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="6" class="text-center text-secondary">No homeowners found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- OFFICERS TABLE -->
      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Latest Officers / Admins</h5>
          <a class="btn btn-sm btn-outline-success" href="phase_management.php">Open Officers Module</a>
        </div>

        <div class="table-responsive">
          <table id="officersTable" class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Phase</th>
                <th>Position</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($latest_officers)): ?>
                <?php foreach ($latest_officers as $a): ?>
                  <tr>
                    <td><?= (int)$a['id'] ?></td>
                    <td><?= esc((string)$a['full_name']) ?></td>
                    <td><?= esc((string)$a['email']) ?></td>
                    <td><?= esc((string)$a['role']) ?></td>
                    <td><?= esc((string)$a['phase']) ?></td>
                    <td><?= esc((string)$a['position']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="6" class="text-center text-secondary">No officer/admin records found.</td></tr>
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

  <script src="../admin/vendors/scripts/core.js"></script>
  <script src="../admin/vendors/scripts/script.min.js"></script>
  <script src="../admin/vendors/scripts/process.js"></script>
  <script src="../admin/vendors/scripts/layout-settings.js"></script>

  <script src="../admin/src/plugins/datatables/js/jquery.dataTables.min.js"></script>
  <script src="../admin/src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
  <script src="../admin/src/plugins/datatables/js/dataTables.responsive.min.js"></script>
  <script src="../admin/src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

  <script>
    $(document).ready(function () {
      $('#homeownersTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[5, 'desc']]
      });

      $('#officersTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[0, 'desc']]
      });
    });

    // Operations chart
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
          { type: 'bar',  label: 'Expenses', data: expenses, borderWidth: 1 },
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

    // Phase comparison chart
    const phaseLabels = <?= json_encode($phases) ?>;
    const approvedByPhase = <?= json_encode(array_values($approved_by_phase)) ?>;
    const activeByPhase = <?= json_encode(array_values($active_by_phase)) ?>;

    const ctx2 = document.getElementById('phaseChart').getContext('2d');
    new Chart(ctx2, {
      type: 'bar',
      data: {
        labels: phaseLabels,
        datasets: [
          {
            label: 'Approved Homeowners',
            data: approvedByPhase,
            borderWidth: 1
          },
          {
            label: 'Active Participants (30 days)',
            data: activeByPhase,
            borderWidth: 1
          }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: true }
        },
        scales: {
          y: {
            beginAtZero: true,
            title: { display: true, text: 'Count' }
          }
        }
      }
    });
  </script>
</body>
</html>