<?php
require_once __DIR__ . "/finance_helpers.php";
require_once 'admin_access.php';
requireAccess('finance');
require_admin();
$conn = db_conn();

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);
$adminId = admin_id();

/* Shared officer/admin account setup */
$canApproveReports = true;

/* =========================
   HELPER FOR REDIRECT QUERY
   ========================= */
function buildReportRedirect(string $base, bool $canPickPhase, string $phase): string {
  $qs = [];

  if ($canPickPhase) {
    $qs[] = "phase=" . urlencode($phase);
  }

  if (isset($_GET['filter_status']) && $_GET['filter_status'] !== '') {
    $qs[] = "filter_status=" . urlencode((string)$_GET['filter_status']);
  }
  if (isset($_GET['filter_year']) && $_GET['filter_year'] !== '') {
    $qs[] = "filter_year=" . urlencode((string)$_GET['filter_year']);
  }
  if (isset($_GET['filter_month']) && $_GET['filter_month'] !== '') {
    $qs[] = "filter_month=" . urlencode((string)$_GET['filter_month']);
  }

  return $base . ($qs ? ("?" . implode("&", $qs)) : "");
}

/* =========================
   REQUEST REPORT
   ========================= */
if (isset($_POST['request_report'])) {
  $year  = (int)($_POST['report_year'] ?? (int)date('Y'));
  $month = (int)($_POST['report_month'] ?? (int)date('n'));

  if ($month < 1 || $month > 12) {
    $month = (int)date('n');
  }

  $stmt = $conn->prepare("
    INSERT INTO finance_report_requests (phase, report_year, report_month, status, requested_by_admin_id)
    VALUES (?,?,?,'pending',?)
    ON DUPLICATE KEY UPDATE
      status='pending',
      requested_by_admin_id=VALUES(requested_by_admin_id),
      requested_at=CURRENT_TIMESTAMP
  ");
  $stmt->bind_param("siii", $phase, $year, $month, $adminId);
  $stmt->execute();
  $stmt->close();

  header("Location: " . buildReportRedirect("finance_reports.php", $canPickPhase, $phase));
  exit;
}

/* =========================
   APPROVE / REJECT ACTION
   ========================= */
if ($canApproveReports && isset($_POST['report_action'])) {
  $id = (int)($_POST['request_id'] ?? 0);
  $action = ($_POST['report_action'] === 'approve') ? 'approved' : 'rejected';
  $remarks = trim((string)($_POST['remarks'] ?? ''));

  $stmt = $conn->prepare("
    UPDATE finance_report_requests
    SET status=?, president_action_at=NOW(), president_remarks=?
    WHERE id=? AND phase=?
  ");
  $stmt->bind_param("ssis", $action, $remarks, $id, $phase);
  $stmt->execute();
  $stmt->close();

  header("Location: " . buildReportRedirect("finance_reports.php", $canPickPhase, $phase));
  exit;
}

/* =========================
   FILTERS
   ========================= */
$filterStatus = trim((string)($_GET['filter_status'] ?? ''));
$filterYear   = (int)($_GET['filter_year'] ?? 0);
$filterMonth  = (int)($_GET['filter_month'] ?? 0);

$allowedStatuses = ['pending', 'approved', 'rejected'];
if (!in_array($filterStatus, $allowedStatuses, true)) {
  $filterStatus = '';
}

if ($filterMonth < 1 || $filterMonth > 12) {
  $filterMonth = 0;
}
if ($filterYear < 2000 || $filterYear > 2100) {
  $filterYear = 0;
}

/* Default view: pending only */
if ($filterStatus === '' && $filterYear === 0 && $filterMonth === 0) {
  $filterStatus = 'pending';
}

/* =========================
   FETCH CURRENT/FILTERED REQUESTS
   ========================= */
$sql = "
  SELECT r.*, a.email AS requested_by_email
  FROM finance_report_requests r
  LEFT JOIN admins a ON a.id = r.requested_by_admin_id
  WHERE r.phase = ?
";
$types = "s";
$params = [$phase];

if ($filterStatus !== '') {
  $sql .= " AND r.status = ?";
  $types .= "s";
  $params[] = $filterStatus;
}
if ($filterYear > 0) {
  $sql .= " AND r.report_year = ?";
  $types .= "i";
  $params[] = $filterYear;
}
if ($filterMonth > 0) {
  $sql .= " AND r.report_month = ?";
  $types .= "i";
  $params[] = $filterMonth;
}

$sql .= " ORDER BY r.requested_at DESC LIMIT 200";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* =========================
   FETCH HISTORY (recent + past)
   ========================= */
$historyStmt = $conn->prepare("
  SELECT r.*, a.email AS requested_by_email
  FROM finance_report_requests r
  LEFT JOIN admins a ON a.id = r.requested_by_admin_id
  WHERE r.phase = ?
  ORDER BY
    COALESCE(r.president_action_at, r.requested_at) DESC,
    r.id DESC
  LIMIT 100
");
$historyStmt->bind_param("s", $phase);
$historyStmt->execute();
$historyRows = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$historyStmt->close();

$defYear  = (int)date('Y');
$defMonth = (int)date('n');
?>
<!DOCTYPE html>
<html>
<head>
  <!-- Basic Page Info -->
  <meta charset="utf-8">
  <title>HOA-ADMIN</title>

  <!-- Site favicon -->
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">

  <!-- Mobile Specific Metas -->
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

  <!-- Google Font -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- CSS -->
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

  <script async src="https://www.googletagmanager.com/gtag/js?id=UA-119386393-1"></script>
  <style>
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
  transform: translateY(-10px);
  transition: all .3s ease;
}

.access-toast.show {
  opacity: 1;
  transform: translateY(0);
}
  </style>
</head>
<body>

  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
      <div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>
    </div>

    <div class="header-right">

      <div class="user-notification">
        <div class="dropdown">
          <a class="dropdown-toggle no-arrow" href="#" role="button" data-toggle="dropdown">
            <i class="icon-copy dw dw-notification"></i>
            <span class="badge notification-active"></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right">
            <div class="notification-list mx-h-350 customscroll">
              <ul>
                <li><a href="#"><img src="vendors/images/img.jpg" alt=""><h3>John Doe</h3><p>Lorem ipsum dolor sit amet...</p></a></li>
                <li><a href="#"><img src="vendors/images/photo1.jpg" alt=""><h3>Lea R. Frith</h3><p>Lorem ipsum dolor sit amet...</p></a></li>
                <li><a href="#"><img src="vendors/images/photo2.jpg" alt=""><h3>Erik L. Richards</h3><p>Lorem ipsum dolor sit amet...</p></a></li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="user-info-dropdown">
        <div class="dropdown">
          <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
            <span class="user-icon">
              <img src="vendors/images/photo1.jpg" alt="">
            </span>
          </a>
          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="profile.html"><i class="dw dw-user1"></i> Profile</a>
            <a class="dropdown-item" href="profile.html"><i class="dw dw-settings2"></i> Setting</a>
            <a class="dropdown-item" href="logout.php"><i class="dw dw-logout"></i> Log Out</a>
          </div>
        </div>
      </div>

    </div>
  </div>

  <div class="right-sidebar">
    <div class="sidebar-title">
      <h3 class="weight-600 font-16 text-blue">
        Layout Settings
        <span class="btn-block font-weight-400 font-12">User Interface Settings</span>
      </h3>
      <div class="close-sidebar" data-toggle="right-sidebar-close">
        <i class="icon-copy ion-close-round"></i>
      </div>
    </div>
    <div class="right-sidebar-body customscroll">
      <div class="right-sidebar-body-content">
        <h4 class="weight-600 font-18 pb-10">Header Background</h4>
        <div class="sidebar-btn-group pb-30 mb-10">
          <a href="javascript:void(0);" class="btn btn-outline-primary header-white active">White</a>
          <a href="javascript:void(0);" class="btn btn-outline-primary header-dark">Dark</a>
        </div>

        <h4 class="weight-600 font-18 pb-10">Sidebar Background</h4>
        <div class="sidebar-btn-group pb-30 mb-10">
          <a href="javascript:void(0);" class="btn btn-outline-primary sidebar-light">White</a>
          <a href="javascript:void(0);" class="btn btn-outline-primary sidebar-dark active">Dark</a>
        </div>

        <div class="reset-options pt-30 text-center">
          <button class="btn btn-danger" id="reset-settings">Reset Settings</button>
        </div>
      </div>
    </div>
  </div>

  <!-- SIDEBAR -->
<?php include 'sidebar.php'; ?>
  <div class="mobile-menu-overlay"></div>

  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header mb-20">
        <div class="row">
          <div class="col-md-6 col-sm-12">
            <div class="title"><h4>Financial Reports</h4></div>
            <div class="text-secondary">
              Phase: <b><?= htmlspecialchars($phase) ?></b>
            </div>
          </div>

          <div class="col-md-6 col-sm-12 text-right">
            <?php if ($canPickPhase): ?>
              <form method="get" class="d-inline-block">
                <select name="phase" class="form-control d-inline-block" style="width:200px" onchange="this.form.submit()">
                  <?php foreach (['Phase 1','Phase 2','Phase 3'] as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $p === $phase ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                  <?php endforeach; ?>
                </select>

                <?php if ($filterStatus !== ''): ?>
                  <input type="hidden" name="filter_status" value="<?= htmlspecialchars($filterStatus) ?>">
                <?php endif; ?>
                <?php if ($filterYear > 0): ?>
                  <input type="hidden" name="filter_year" value="<?= (int)$filterYear ?>">
                <?php endif; ?>
                <?php if ($filterMonth > 0): ?>
                  <input type="hidden" name="filter_month" value="<?= (int)$filterMonth ?>">
                <?php endif; ?>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card-box mb-20 p-3">
        <h5 class="mb-3">Request Monthly Report</h5>
        <form method="post" class="form-inline">
          <label class="mr-2">Year</label>
          <input class="form-control mr-3" type="number" name="report_year" value="<?= $defYear ?>" required>

          <label class="mr-2">Month</label>
          <select class="form-control mr-3" name="report_month" required>
            <?php for ($m = 1; $m <= 12; $m++): ?>
              <option value="<?= $m ?>" <?= $m === $defMonth ? 'selected' : '' ?>>
                <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
              </option>
            <?php endfor; ?>
          </select>

          <button class="btn btn-primary" name="request_report">Send Request</button>
        </form>

        <small class="text-secondary d-block mt-2">
          Once approved, the report can be exported to PDF or Excel.
        </small>
      </div>

      <div class="card-box mb-20 p-3">
        <h5 class="mb-3">Filter Report Requests</h5>

        <form method="get" class="row">
          <?php if ($canPickPhase): ?>
            <input type="hidden" name="phase" value="<?= htmlspecialchars($phase) ?>">
          <?php endif; ?>

          <div class="col-md-3 mb-2">
            <label>Status</label>
            <select name="filter_status" class="form-control">
              <option value="">All Status</option>
              <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
              <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>Approved</option>
              <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
          </div>

          <div class="col-md-3 mb-2">
            <label>Year</label>
            <input type="number" name="filter_year" class="form-control" value="<?= $filterYear > 0 ? (int)$filterYear : '' ?>" placeholder="All Years">
          </div>

          <div class="col-md-3 mb-2">
            <label>Month</label>
            <select name="filter_month" class="form-control">
              <option value="">All Months</option>
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $filterMonth === $m ? 'selected' : '' ?>>
                  <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                </option>
              <?php endfor; ?>
            </select>
          </div>

          <div class="col-md-3 mb-2 d-flex align-items-end">
            <button type="submit" class="btn btn-info mr-2">Apply Filter</button>
            <a href="finance_reports.php<?= $canPickPhase ? ('?phase=' . urlencode($phase)) : '' ?>" class="btn btn-secondary">Reset</a>
          </div>
        </form>
      </div>

      <div class="card-box mb-20 p-3">
        <h5 class="mb-3">Report Requests</h5>

        <div class="table-responsive">
          <table class="table table-striped table-hover">
            <thead>
              <tr>
                <th>Requested At</th>
                <th>Period</th>
                <th>Status</th>
                <th>Requested By</th>
                <th>Action Date</th>
                <th>Remarks</th>
                <th>Export</th>
                <th>Action</th>
              </tr>
            </thead>

            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r['requested_at']) ?></td>
                  <td><?= htmlspecialchars($r['report_year'] . "-" . str_pad((string)$r['report_month'], 2, '0', STR_PAD_LEFT)) ?></td>

                  <td>
                    <?php
                      $st = $r['status'] ?? 'pending';
                      $badge = $st === 'approved' ? 'badge-success' : ($st === 'rejected' ? 'badge-danger' : 'badge-warning');
                    ?>
                    <span class="badge <?= $badge ?>"><?= htmlspecialchars($st) ?></span>
                  </td>

                  <td><?= htmlspecialchars($r['requested_by_email'] ?? '') ?></td>
                  <td><?= htmlspecialchars($r['president_action_at'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($r['president_remarks'] ?? '') ?></td>

                  <td style="min-width:220px">
                    <?php if (($r['status'] ?? '') === 'approved'): ?>
                      <?php
                        $qs = "phase=" . urlencode($phase)
                            . "&year=" . (int)$r['report_year']
                            . "&month=" . (int)$r['report_month'];
                      ?>
                      <a class="btn btn-sm btn-outline-success" target="_blank" href="finance_reports_export.php?format=pdf&<?= $qs ?>">PDF</a>
                      <a class="btn btn-sm btn-outline-primary" target="_blank" href="finance_reports_export.php?format=excel&<?= $qs ?>">Excel</a>
                    <?php else: ?>
                      <span class="text-secondary">—</span>
                    <?php endif; ?>
                  </td>

                  <td style="min-width:220px">
                    <?php if (($r['status'] ?? '') === 'pending'): ?>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="report_action" value="approve">
                        <input type="text" name="remarks" class="form-control mb-1" placeholder="Remarks (optional)">
                        <button class="btn btn-sm btn-success">Approve</button>
                      </form>

                      <form method="post" class="d-inline">
                        <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="report_action" value="reject">
                        <input type="hidden" name="remarks" value="Rejected">
                        <button class="btn btn-sm btn-danger">Reject</button>
                      </form>
                    <?php else: ?>
                      <span class="text-secondary">-</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$rows): ?>
                <tr>
                  <td colspan="8" class="text-center text-secondary">
                    No report requests found for the selected filter.
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- HISTORY SECTION -->
      <div class="card-box mb-20 p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="mb-0">History</h5>
          <small class="text-secondary">Recent and past requests, including approved reports</small>
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-hover">
            <thead>
              <tr>
                <th>#</th>
                <th>Requested At</th>
                <th>Period</th>
                <th>Status</th>
                <th>Requested By</th>
                <th>Action Date</th>
                <th>Remarks</th>
                <th>Available Export</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($historyRows as $i => $h): ?>
                <tr>
                  <td><?= (int)($i + 1) ?></td>
                  <td><?= htmlspecialchars($h['requested_at']) ?></td>
                  <td><?= htmlspecialchars($h['report_year'] . "-" . str_pad((string)$h['report_month'], 2, '0', STR_PAD_LEFT)) ?></td>
                  <td>
                    <?php
                      $hst = $h['status'] ?? 'pending';
                      $hBadge = $hst === 'approved' ? 'badge-success' : ($hst === 'rejected' ? 'badge-danger' : 'badge-warning');
                    ?>
                    <span class="badge <?= $hBadge ?>"><?= htmlspecialchars($hst) ?></span>
                  </td>
                  <td><?= htmlspecialchars($h['requested_by_email'] ?? '') ?></td>
                  <td><?= htmlspecialchars($h['president_action_at'] ?? '-') ?></td>
                  <td><?= htmlspecialchars($h['president_remarks'] ?? '') ?></td>
                  <td>
                    <?php if (($h['status'] ?? '') === 'approved'): ?>
                      <?php
                        $hqs = "phase=" . urlencode($phase)
                             . "&year=" . (int)$h['report_year']
                             . "&month=" . (int)$h['report_month'];
                      ?>
                      <a class="btn btn-sm btn-outline-success" target="_blank" href="finance_reports_export.php?format=pdf&<?= $hqs ?>">PDF</a>
                      <a class="btn btn-sm btn-outline-primary" target="_blank" href="finance_reports_export.php?format=excel&<?= $hqs ?>">Excel</a>
                    <?php else: ?>
                      <span class="text-secondary">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$historyRows): ?>
                <tr>
                  <td colspan="8" class="text-center text-secondary">
                    No history found yet.
                  </td>
                </tr>
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

  <!-- js -->
  <script src="vendors/scripts/core.js"></script>
  <script src="vendors/scripts/script.min.js"></script>
  <script src="vendors/scripts/process.js"></script>
  <script src="vendors/scripts/layout-settings.js"></script>
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