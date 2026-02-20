<?php
session_start();

// ===================== AUTH GUARD =====================
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
  exit;
}
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

$stmt = $conn->prepare("SELECT email, full_name, phase, role FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc() ?: ['email'=>'','full_name'=>'','phase'=>'Phase 1','role'=>$adminRole];
$stmt->close();

$adminEmail = (string)($me['email'] ?? '');
$adminName  = trim((string)($me['full_name'] ?? ''));
$myPhase    = (string)($me['phase'] ?? 'Phase 1');

$allowedPhases = ['Phase 1','Phase 2','Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';

// Active permits
$stmt = $conn->prepare("SELECT COUNT(*) c FROM parking_permits WHERE phase=? AND status='active'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$activePermits = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// Pending permit requests
$stmt = $conn->prepare("SELECT COUNT(*) c FROM parking_permits WHERE phase=? AND status='pending'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$pendingPermits = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// Expired/Revoked permits
$stmt = $conn->prepare("SELECT COUNT(*) c FROM parking_permits WHERE phase=? AND status IN ('expired','revoked')");
$stmt->bind_param("s", $phase);
$stmt->execute();
$expiredRevoked = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// Open violations
$stmt = $conn->prepare("SELECT COUNT(*) c FROM parking_violations WHERE phase=? AND status='open'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$openViolations = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

// Recent permit requests
$stmt = $conn->prepare("
  SELECT p.id, p.plate_no, p.vehicle_make, p.vehicle_model, p.status, p.requested_at,
         h.first_name, h.middle_name, h.last_name, h.house_lot_number
  FROM parking_permits p
  JOIN homeowners h ON h.id=p.homeowner_id
  WHERE p.phase=?
  ORDER BY p.requested_at DESC
  LIMIT 25
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$recentPermits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Recent violations
$stmt = $conn->prepare("
  SELECT v.id, v.issued_at, v.plate_no, v.violation_type, v.location, v.fine_amount, v.status,
         h.first_name, h.middle_name, h.last_name, h.house_lot_number
  FROM parking_violations v
  LEFT JOIN homeowners h ON h.id=v.homeowner_id
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

  <style>
    .kpi-card .icon { font-size: 28px; opacity: .9; }
    .kpi-value { font-size: 28px; font-weight: 800; }
    .kpi-label { color: #64748b; font-weight: 700; }
    .badge-soft { padding: .35rem .6rem; border-radius: 999px; font-weight: 800; font-size: 12px; }
    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-danger  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .badge-soft-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }
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

  <!-- LEFT SIDEBAR (same style) -->
  <div class="left-side-bar" style="background-color:#077f46;">
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
            <a href="javascript:;" class="dropdown-toggle">
              <span class="micon dw dw-user"></span>
              <span class="mtext">Homeowner Management</span>
            </a>
            <ul class="submenu">
              <li><a href="ho_approval.php">Household Approval</a></li>
              <li><a href="ho_register.php">Register Household</a></li>
              <li><a href="ho_approved.php">Approved Households</a></li>
            </ul>
          </li>

          <li><a href="announcements.php" class="dropdown-toggle no-arrow"><span class="micon dw dw-megaphone"></span><span class="mtext">Announcement</span></a></li>

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
            <a href="javascript:;" class="dropdown-toggle active">
              <span class="micon dw dw-car"></span>
              <span class="mtext">Parking</span>
            </a>
            <ul class="submenu">
              <li><a class="active" href="parking.php">Parking Overview</a></li>
              <li><a href="parking_permits.php">Manage Permits</a></li>
              <li><a href="parking_violations.php">View Violations</a></li>
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
            <div class="title"><h4>Parking Overview</h4></div>
            <div class="text-secondary">Phase: <b><?= esc($phase) ?></b></div>
          </div>
        </div>
      </div>

      <!-- KPI -->
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
            <div class="mt-2 text-secondary"><a href="parking_permits.php" class="text-primary font-weight-bold">Open Permits →</a></div>
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
            <div class="mt-2 text-secondary"><a href="parking_violations.php" class="text-primary font-weight-bold">Open Violations →</a></div>
          </div>
        </div>
      </div>

      <!-- Recent permit requests -->
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
                <th>Vehicle</th>
                <th>Status</th>
                <th>Requested</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($recentPermits as $p): ?>
                <?php
                  $name = trim(($p['first_name'] ?? '').' '.($p['middle_name'] ?? '').' '.($p['last_name'] ?? ''));
                  $st = (string)($p['status'] ?? 'pending');
                  $badge = 'badge-soft-info';
                  if ($st==='pending') $badge='badge-soft-warning';
                  if ($st==='active') $badge='badge-soft-success';
                  if (in_array($st,['revoked','expired','rejected'],true)) $badge='badge-soft-danger';
                ?>
                <tr>
                  <td><?= (int)$p['id'] ?></td>
                  <td><?= esc($name) ?></td>
                  <td><?= esc($p['house_lot_number'] ?? '') ?></td>
                  <td><?= esc($p['plate_no'] ?? '') ?></td>
                  <td><?= esc(trim(($p['vehicle_make'] ?? '').' '.($p['vehicle_model'] ?? ''))) ?></td>
                  <td><span class="badge-soft <?=esc($badge)?>"><?= esc($st) ?></span></td>
                  <td><?= esc($p['requested_at'] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$recentPermits): ?>
                <tr><td colspan="7" class="text-center text-secondary">No permit records yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Recent violations -->
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
              <?php foreach($recentViolations as $v): ?>
                <?php
                  $name = trim(($v['first_name'] ?? '').' '.($v['middle_name'] ?? '').' '.($v['last_name'] ?? ''));
                  if ($name==='') $name='—';
                  $st = (string)($v['status'] ?? 'open');
                  $badge = 'badge-soft-warning';
                  if ($st==='open') $badge='badge-soft-warning';
                  if (in_array($st,['paid','cleared'],true)) $badge='badge-soft-success';
                  if ($st==='void') $badge='badge-soft-danger';
                ?>
                <tr>
                  <td><?= (int)$v['id'] ?></td>
                  <td><?= esc($v['issued_at'] ?? '') ?></td>
                  <td><?= esc($v['plate_no'] ?? '') ?></td>
                  <td><?= esc($name) ?></td>
                  <td><?= esc($v['violation_type'] ?? '') ?></td>
                  <td><?= esc($v['location'] ?? '') ?></td>
                  <td><?= number_format((float)($v['fine_amount'] ?? 0), 2) ?></td>
                  <td><span class="badge-soft <?=esc($badge)?>"><?= esc($st) ?></span></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$recentViolations): ?>
                <tr><td colspan="8" class="text-center text-secondary">No violations recorded yet.</td></tr>
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

  <script src="vendors/scripts/core.js"></script>
  <script src="vendors/scripts/script.min.js"></script>
  <script src="vendors/scripts/process.js"></script>
  <script src="vendors/scripts/layout-settings.js"></script>

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

</body>
</html>
