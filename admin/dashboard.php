<?php
session_start();
// ===================== AUTH GUARD =====================
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
  exit;
}

// OPTIONAL (recommended): this dashboard is for phase president/admin only
if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
  echo "<script>alert('Superadmin cannot access President Dashboard.'); window.location='index.php';</script>";
  exit;
}

// ===================== DB =====================
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$adminId   = (int)$_SESSION['admin_id'];
$adminRole = (string)$_SESSION['admin_role'];

// Fetch admin info (email/phase/full_name)
$stmt = $conn->prepare("SELECT email, full_name, phase, role FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc() ?: ['email'=>'','full_name'=>'','phase'=>'Phase 1','role'=>$adminRole];
$stmt->close();

$adminEmail = (string)($me['email'] ?? '');
$adminName  = trim((string)($me['full_name'] ?? ''));
$myPhase    = (string)($me['phase'] ?? 'Phase 1');

// ✅ STATIC PHASE (LOCKED)
$allowedPhases = ['Phase 1','Phase 2','Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';

// ===================== PRESIDENT NAME (DYNAMIC) =====================
// Source: hoa_officers (assigned via phase_management.php)
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
  // fallback: show logged-in admin name if available
  if ($adminName !== '') $presidentName = $adminName;
}

// Your workflow: admin is president of their phase
$isPresident = true;

// ===================== KPI QUERIES =====================
// Approved homeowners
$stmt = $conn->prepare("SELECT COUNT(*) c FROM homeowners WHERE phase=? AND status='approved'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$approvedCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// Pending homeowners
$stmt = $conn->prepare("SELECT COUNT(*) c FROM homeowners WHERE phase=? AND status='pending'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$pendingCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// Pending finance report requests
$stmt = $conn->prepare("SELECT COUNT(*) c FROM finance_report_requests WHERE phase=? AND status='pending'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$pendingReportCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// This month collections (paid)
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

// This month expenses
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

// ===================== CHART DATA (last 6 months) =====================
$labels = [];
$keys   = []; // YYYY-MM
for ($i = 5; $i >= 0; $i--) {
  $ts = strtotime(date('Y-m-01') . " -$i months");
  $labels[] = date('M Y', $ts);
  $keys[]   = date('Y-m', $ts);
}
$fromDate = date('Y-m-01', strtotime(date('Y-m-01') . " -5 months"));
$toDate   = date('Y-m-t'); // end of current month

// Monthly collections
$collectionsByKey = array_fill_keys($keys, 0.0);
$stmt = $conn->prepare("
  SELECT DATE_FORMAT(paid_at,'%Y-%m') ym, COALESCE(SUM(amount),0) total
  FROM finance_payments
  WHERE phase=? AND status='paid'
    AND paid_at >= ? AND paid_at < DATE_ADD(?, INTERVAL 1 DAY)
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

// Monthly expenses
$expensesByKey = array_fill_keys($keys, 0.0);
$stmt = $conn->prepare("
  SELECT DATE_FORMAT(expense_date,'%Y-%m') ym, COALESCE(SUM(amount),0) total
  FROM finance_expenses
  WHERE phase=?
    AND expense_date >= ? AND expense_date <= ?
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

// Monthly new homeowners
$newHOByKey = array_fill_keys($keys, 0);
$stmt = $conn->prepare("
  SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c
  FROM homeowners
  WHERE phase=?
    AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
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

// ===================== TABLE: Homeowners (approved + pending) =====================
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

// ===================== TABLE: Pending finance report requests =====================
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

  <style>
    .kpi-card .icon { font-size: 28px; opacity: .9; }
    .kpi-value { font-size: 28px; font-weight: 800; }
    .kpi-label { color: #64748b; font-weight: 700; }
    .badge-soft { padding: .35rem .6rem; border-radius: 999px; font-weight: 800; font-size: 12px; }
    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }
    .modalx {
      display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
      align-items:center; justify-content:center; z-index:9999; padding:16px;
    }
    .modalx .box {
      width: min(1100px, 96vw);
      max-height: 92vh;
      background:#fff; border-radius: 16px;
      overflow:auto; box-shadow: 0 20px 60px rgba(0,0,0,.25);
    }
    .modalx .boxhead{
      padding: 14px 16px; border-bottom:1px solid #e5e7eb;
      display:flex; align-items:center; justify-content:space-between; gap:12px;
    }
    .modalx .closebtn{ border:none; background:transparent; font-size:22px; cursor:pointer; }
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

          <li><a href="HO-management.php" class="dropdown-toggle no-arrow"><span class="micon dw dw-user1"></span><span class="mtext">Homeowner Management</span></a></li>
          <li><a href="users-management.php" class="dropdown-toggle no-arrow"><span class="micon dw dw-user"></span><span class="mtext">User Management</span></a></li>
          <li><a href="announcements.php" class="dropdown-toggle no-arrow"><span class="micon dw dw-megaphone"></span><span class="mtext">Announcement</span></a></li>

          <li class="dropdown">
            <a href="javascript:;" class="dropdown-toggle"><span class="micon dw dw-money-1"></span><span class="mtext">Finance</span></a>
            <ul class="submenu">
              <li><a href="finance.php">Overview</a></li>
              <li><a href="finance_dues.php">Monthly Dues</a></li>
              <li><a href="finance_donations.php">Donations</a></li>
              <li><a href="finance_expenses.php">Expenses</a></li>
              <li><a href="finance_reports.php">Financial Reports</a></li>
              <li><a href="finance_cashflow.php">Cash Flow Dashboard</a></li>
            </ul>
          </li>

          <li><a href="#" class="dropdown-toggle no-arrow"><span class="micon dw dw-settings2"></span><span class="mtext">Settings</span></a></li>
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

      <!-- WELCOME CARD -->
      <div class="card-box pd-20 height-100-p mb-30">
        <div class="row align-items-center">
          <div class="col-md-4"><img src="vendors/images/banner-img.png" alt=""></div>
          <div class="col-md-8">
            <h4 class="font-20 weight-500 mb-10 text-capitalize">
              <div class="weight-600 font-30 text-blue">Welcome, President!</div>
            </h4>
            <p class="font-18 max-width-600">
              Live view of key HOA operations for <b><?=esc($phase)?></b> — registrations, finance health, and items waiting for approval.
            </p>
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
                <div class="kpi-value"><?= number_format($approvedCount) ?></div>
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
                <div class="kpi-value"><?= number_format($pendingCount) ?></div>
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
                <div class="kpi-value"><?= number_format($pendingReportCount) ?></div>
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
                <div class="kpi-value"><?= number_format($net, 2) ?></div>
              </div>
              <div class="icon text-info"><i class="dw dw-money"></i></div>
            </div>
            <div class="mt-2 text-secondary">
              Collections: <?= number_format($thisMonthCollections,2) ?> • Expenses: <?= number_format($thisMonthExpenses,2) ?>
            </div>
          </div>
        </div>
      </div>

      <!-- ACTIVITY CHART -->
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

      <!-- PENDING REPORT REQUESTS -->
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
              <?php if ($pendingReports): ?>
                <?php foreach($pendingReports as $r): ?>
                  <?php
                    $who = trim((string)($r['requested_by_name'] ?? ''));
                    if ($who === '') $who = (string)($r['requested_by_email'] ?? '');
                  ?>
                  <tr>
                    <td><?=esc($r['requested_at'] ?? '')?></td>
                    <td><?=esc(($r['report_year'] ?? '').'-'.str_pad((string)($r['report_month'] ?? ''),2,'0',STR_PAD_LEFT))?></td>
                    <td><?=esc($who)?></td>
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

      <!-- HOMEOWNERS LIST -->
      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Homeowners List (Approved + Pending)</h5>
          <span class="text-secondary">Phase: <b><?=esc($phase)?></b></span>
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
              <?php foreach($homeownersRows as $h): ?>
                <?php
                  $full = trim(($h['first_name'] ?? '').' '.($h['middle_name'] ?? '').' '.($h['last_name'] ?? ''));
                  $st = (string)($h['status'] ?? 'pending');
                  $badge = $st === 'approved' ? 'badge-soft-success' : 'badge-soft-warning';
                ?>
                <tr>
                  <td><?= (int)$h['id'] ?></td>
                  <td><?= esc($full) ?></td>
                  <td><?= esc($h['house_lot_number'] ?? '') ?></td>
                  <td><span class="badge-soft <?=esc($badge)?>"><?= esc($st) ?></span></td>
                  <td><?= esc($h['created_at'] ?? '') ?></td>
                  <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-primary viewHomeowner" data-id="<?= (int)$h['id'] ?>" title="View">
                      <i class="dw dw-eye"></i>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$homeownersRows): ?>
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

  <script src="vendors/scripts/core.js"></script>
  <script src="vendors/scripts/script.min.js"></script>
  <script src="vendors/scripts/process.js"></script>
  <script src="vendors/scripts/layout-settings.js"></script>

  <script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
  <script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
  <script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
  <script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

  <script>
    $(document).ready(function() {
      $('#homeownersTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [],
        columnDefs: [{ orderable: false, targets: 5 }]
      });
    });

    // View modal -> load profile html from HO-management.php ajax endpoint
    const viewModal = document.getElementById('viewModal');
    const viewBody  = document.getElementById('viewModalBody');

    function openModal(){ viewModal.style.display='flex'; }
    function closeModal(){ viewModal.style.display='none'; viewBody.innerHTML='<div class="p-3 text-secondary">Loading...</div>'; }

    document.getElementById('closeViewModal').addEventListener('click', closeModal);
    viewModal.addEventListener('click', (e)=>{ if(e.target === viewModal) closeModal(); });

    $(document).on('click', '.viewHomeowner', function(){
      const id = $(this).data('id');
      openModal();
      viewBody.innerHTML = '<div class="p-3 text-secondary">Loading profile...</div>';

      $.get('HO-management.php', { ajax:'homeowner_profile', id:id, _:Date.now() }, function(html){
        viewBody.innerHTML = html;
      }).fail(function(){
        viewBody.innerHTML = '<div class="p-3"><div class="alert alert-danger mb-0">Failed to load profile.</div></div>';
      });
    });

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
