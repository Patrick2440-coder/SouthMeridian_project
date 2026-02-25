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

$flash = "";
$flashType = "success";
function fail_flash(&$flash, &$flashType, string $msg){ $flash=$msg; $flashType="danger"; }

// ===================== ACTIONS =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
      // Try to match active permit in this phase by plate
      $permit_id = null;
      $homeowner_id = null;

      $stmt = $conn->prepare("
        SELECT id, homeowner_id
        FROM parking_permits
        WHERE phase=? AND status='active' AND plate_no=?
        ORDER BY id DESC
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
      // bind with nulls safely
      $pid = $permit_id ? $permit_id : null;
      $hid = $homeowner_id ? $homeowner_id : null;
      $stmt->bind_param("siissssd", $phase, $pid, $hid, $plate, $type, $loc, $notes, $fine);
      $stmt->execute();
      $stmt->close();

      $flash = "Violation recorded. " . ($permit_id ? "Matched to active permit." : "No active permit match.");
    }
  }

  if (in_array($action, ['mark_paid','mark_cleared','mark_void'], true)) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      fail_flash($flash, $flashType, "Invalid violation ID.");
    } else {
      $newStatus = 'open';
      if ($action==='mark_paid') $newStatus='paid';
      if ($action==='mark_cleared') $newStatus='cleared';
      if ($action==='mark_void') $newStatus='void';

      $stmt = $conn->prepare("
        UPDATE parking_violations
        SET status=?, resolved_at=NOW(), resolved_by_admin_id=?
        WHERE id=? AND phase=?
      ");
      $stmt->bind_param("siis", $newStatus, $adminId, $id, $phase);
      $stmt->execute();
      if ($stmt->affected_rows <= 0) fail_flash($flash, $flashType, "Violation not found.");
      $stmt->close();

      if ($flashType !== "danger") $flash = "Violation updated to {$newStatus}.";
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
  $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q;
  $types .= "sssss";
}

$sql = "
  SELECT v.*, p.permit_no,
         h.first_name, h.middle_name, h.last_name, h.house_lot_number
  FROM parking_violations v
  LEFT JOIN parking_permits p ON p.id=v.permit_id
  LEFT JOIN homeowners h ON h.id=v.homeowner_id
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
    .badge-soft { padding: .35rem .6rem; border-radius: 999px; font-weight: 800; font-size: 12px; display:inline-block; }
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

          <!-- ✅ USER MANAGEMENT DROPDOWN -->
          <?php $view = $_GET['view'] ?? ''; ?>
          <li class="dropdown">
            <a href="javascript:;" class="dropdown-toggle <?= ($view==='homeowners' || $view==='officers') ? 'active' : '' ?>">
              <span class="micon dw dw-user"></span>
              <span class="mtext">User Management</span>
            </a>
            <ul class="submenu">
              <li><a href="users-management.php?view=homeowners" class="<?= $view==='homeowners' ? 'active' : '' ?>">Homeowners</a></li>
              <li><a href="users-management.php?view=officers" class="<?= $view==='officers' ? 'active' : '' ?>">Officers</a></li>
            </ul>
          </li>

          <li>
            <a href="announcements.php" class="dropdown-toggle no-arrow">
              <span class="micon dw dw-megaphone"></span>
              <span class="mtext">Announcement</span>
            </a>
          </li>

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

          <li class="dropdown">
            <a href="javascript:;" class="dropdown-toggle"><span class="micon dw dw-car"></span><span class="mtext">Parking</span></a>
            <ul class="submenu">
              <li><a href="parking.php">Parking Overview</a></li>
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
                <option value="<?=esc($s)?>" <?= $status===$s ? 'selected' : '' ?>><?=esc($s)?></option>
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
                  if ($st==='open') $badge='badge-soft-warning';
                  if (in_array($st,['paid','cleared'],true)) $badge='badge-soft-success';
                  if ($st==='void') $badge='badge-soft-danger';
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
                  <td><span class="badge-soft <?=esc($badge)?>"><?= esc($st) ?></span></td>
                  <td class="text-center">
                    <?php if ($st === 'open'): ?>
                      <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="mark_paid">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-success" type="submit"><i class="dw dw-money"></i> Paid</button>
                      </form>
                      <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="mark_cleared">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-outline-primary" type="submit"><i class="dw dw-check"></i> Cleared</button>
                      </form>
                      <form method="POST" class="d-inline" onsubmit="return confirm('Void this violation?');">
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

  <!-- ADD VIOLATION MODAL -->
  <div class="modal fade" id="modalAddViolation" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form method="POST" class="modal-content">
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
            The system will auto-match the plate to an <b>active permit</b> in this phase (if found).
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

</body>
</html>
