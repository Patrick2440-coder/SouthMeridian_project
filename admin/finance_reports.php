<?php
require_once __DIR__ . "/finance_helpers.php";
require_admin();
$conn = db_conn();

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);
$adminId = admin_id();

$isPresident = is_president($conn, $phase);
$adminEmail = admin_email($conn);

// Request report
if (isset($_POST['request_report'])) {
  $year  = (int)($_POST['report_year'] ?? (int)date('Y'));
  $month = (int)($_POST['report_month'] ?? (int)date('n'));
  if ($month < 1 || $month > 12) $month = (int)date('n');

  $stmt = $conn->prepare("
    INSERT INTO finance_report_requests (phase, report_year, report_month, status, requested_by_admin_id)
    VALUES (?,?,?,'pending',?)
    ON DUPLICATE KEY UPDATE status='pending', requested_by_admin_id=VALUES(requested_by_admin_id), requested_at=CURRENT_TIMESTAMP
  ");
  $stmt->bind_param("siii", $phase, $year, $month, $adminId);
  $stmt->execute();

  header("Location: finance_reports.php" . ($canPickPhase ? ("?phase=" . urlencode($phase)) : ""));
  exit;
}

// President action
if ($isPresident && isset($_POST['pres_action'])) {
  $id = (int)($_POST['request_id'] ?? 0);
  $action = ($_POST['pres_action'] === 'approve') ? 'approved' : 'rejected';
  $remarks = trim($_POST['remarks'] ?? '');

  $stmt = $conn->prepare("
    UPDATE finance_report_requests
    SET status=?, president_approved_by_email=?, president_action_at=NOW(), president_remarks=?
    WHERE id=? AND phase=?
  ");
  $stmt->bind_param("sssis", $action, $adminEmail, $remarks, $id, $phase);
  $stmt->execute();

  header("Location: finance_reports.php" . ($canPickPhase ? ("?phase=" . urlencode($phase)) : ""));
  exit;
}

// Fetch requests
$stmt = $conn->prepare("
  SELECT r.*, a.email AS requested_by_email
  FROM finance_report_requests r
  LEFT JOIN admins a ON a.id=r.requested_by_admin_id
  WHERE r.phase=?
  ORDER BY r.requested_at DESC
  LIMIT 200
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$defYear = (int)date('Y');
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
            <span class="user-name">JOHNFALL SANCHEZ</span>
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

					<li class="dropdown show">
						<a href="javascript:;" class="dropdown-toggle active ">
							<span class="micon dw dw-user"></span>
							<span class="mtext">Homeowner Management</span>
						</a>
						<ul class="submenu">
							<li><a  href="ho_approval.php">Household Approval</a></li>
							<li><a href="ho_register.php">Register Household</a></li>
							<li><a href="ho_approved.php">Approved Households</a></li>
						</ul>
					</li>
					<!-- ✅ USER MANAGEMENT DROPDOWN -->
					<li class="dropdown">
						<a href="javascript:;" class="dropdown-toggle <?= ($view==='homeowners' || $view==='officers') ? 'active' : '' ?>">
							<span class="micon dw dw-user"></span>
							<span class="mtext">User Management</span>
						</a>
						<ul class="submenu">
							<li>
								<a href="users-management.php?view=homeowners" class="<?= $view==='homeowners' ? 'active' : '' ?>">
									Homeowners
								</a>
							</li>
							<li>
								<a href="users-management.php?view=officers" class="<?= $view==='officers' ? 'active' : '' ?>">
									Officers
								</a>
							</li>
						</ul>
					</li>
          
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
	</div>
  <div class="mobile-menu-overlay"></div>

  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header mb-20">
        <div class="row">
          <div class="col-md-6 col-sm-12">
            <div class="title"><h4>Financial Reports</h4></div>
            <div class="text-secondary">
              Phase: <b><?=esc($phase)?></b> |
            </div>
          </div>

          <div class="col-md-6 col-sm-12 text-right">
            <?php if ($canPickPhase): ?>
              <form method="get" class="d-inline-block">
                <select name="phase" class="form-control d-inline-block" style="width:200px" onchange="this.form.submit()">
                  <?php foreach(['Phase 1','Phase 2','Phase 3'] as $p): ?>
                    <option value="<?=esc($p)?>" <?= $p===$phase ? 'selected' : '' ?>><?=esc($p)?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card-box mb-20 p-3">
        <h5 class="mb-3">Request Monthly Report (requires President approval)</h5>
        <form method="post" class="form-inline">
          <label class="mr-2">Year</label>
          <input class="form-control mr-3" type="number" name="report_year" value="<?=$defYear?>" required>

          <label class="mr-2">Month</label>
          <select class="form-control mr-3" name="report_month" required>
            <?php for($m=1;$m<=12;$m++): ?>
              <option value="<?=$m?>" <?=$m===$defMonth ? 'selected' : ''?>><?=$m?></option>
            <?php endfor; ?>
          </select>

          <button class="btn btn-primary" name="request_report">Send for Approval</button>
        </form>

        <small class="text-secondary d-block mt-2">
          After approval, you can export the report to PDF/Excel.
        </small>
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
                <th>President Action</th>
                <th>Remarks</th>
                <th>Export</th>
                <?php if ($isPresident): ?><th>Action</th><?php endif; ?>
              </tr>
            </thead>

            <tbody>
              <?php foreach($rows as $r): ?>
                <tr>
                  <td><?=esc($r['requested_at'])?></td>
                  <td><?=esc($r['report_year']."-".str_pad((string)$r['report_month'],2,'0',STR_PAD_LEFT))?></td>

                  <td>
                    <?php
                      $st = $r['status'] ?? 'pending';
                      $badge = $st==='approved' ? 'badge-success' : ($st==='rejected' ? 'badge-danger' : 'badge-warning');
                    ?>
                    <span class="badge <?=$badge?>"><?=esc($st)?></span>
                  </td>

                  <td><?=esc($r['requested_by_email'] ?? '')?></td>
                  <td><?=esc($r['president_action_at'] ?? '-')?></td>
                  <td><?=esc($r['president_remarks'] ?? '')?></td>

                  <!-- EXPORT -->
                  <td style="min-width:220px">
                    <?php if (($r['status'] ?? '') === 'approved'): ?>
                      <?php
                        $qs = "phase=".urlencode($phase)
                            ."&year=".(int)$r['report_year']
                            ."&month=".(int)$r['report_month'];
                      ?>
                      <a class="btn btn-sm btn-outline-success" target="_blank"
                         href="finance_reports_export.php?format=pdf&<?=$qs?>">PDF</a>
                      <a class="btn btn-sm btn-outline-primary" target="_blank"
                         href="finance_reports_export.php?format=excel&<?=$qs?>">Excel</a>
                    <?php else: ?>
                      <span class="text-secondary">—</span>
                    <?php endif; ?>
                  </td>

                  <!-- ACTION (PRESIDENT ONLY) -->
                  <?php if ($isPresident): ?>
                    <td style="min-width:220px">
                      <?php if (($r['status'] ?? '') === 'pending'): ?>
                        <form method="post" class="d-inline">
                          <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                          <input type="hidden" name="pres_action" value="approve">
                          <input type="text" name="remarks" class="form-control mb-1" placeholder="Remarks (optional)">
                          <button class="btn btn-sm btn-success">Approve</button>
                        </form>

                        <form method="post" class="d-inline">
                          <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                          <input type="hidden" name="pres_action" value="reject">
                          <input type="hidden" name="remarks" value="Rejected">
                          <button class="btn btn-sm btn-danger">Reject</button>
                        </form>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>

                </tr>
              <?php endforeach; ?>

              <?php if (!$rows): ?>
                <tr>
                  <td colspan="<?= $isPresident ? 8 : 7 ?>" class="text-center text-secondary">
                    No report requests yet.
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
</body>
</html>
