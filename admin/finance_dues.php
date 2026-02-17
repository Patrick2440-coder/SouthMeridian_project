<?php
require_once __DIR__ . "/finance_helpers.php";
require_admin();
$conn = db_conn();

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);
$adminId = admin_id();

// Save monthly dues setting
if (isset($_POST['save_dues'])) {
  $dues = (float)($_POST['monthly_dues'] ?? 0);
  if ($dues < 0) $dues = 0;

  $stmt = $conn->prepare("
    INSERT INTO finance_dues_settings (phase, monthly_dues, updated_by_admin_id)
    VALUES (?,?,?)
    ON DUPLICATE KEY UPDATE monthly_dues=VALUES(monthly_dues), updated_by_admin_id=VALUES(updated_by_admin_id)
  ");
  $stmt->bind_param("sdi", $phase, $dues, $adminId);
  $stmt->execute();
  header("Location: finance_dues.php" . ($canPickPhase?("?phase=".urlencode($phase)):"") );
  exit;
}

// Record payment
if (isset($_POST['record_payment'])) {
  $homeowner_id = (int)($_POST['homeowner_id'] ?? 0);
  $year  = (int)($_POST['pay_year'] ?? (int)date('Y'));
  $month = (int)($_POST['pay_month'] ?? (int)date('n'));
  $amount = (float)($_POST['amount'] ?? 0);
  $ref = trim($_POST['reference_no'] ?? '');
  $notes = trim($_POST['notes'] ?? '');

  if ($homeowner_id > 0 && $month >= 1 && $month <= 12 && $year >= 2000 && $amount > 0) {
    $stmt = $conn->prepare("
      INSERT INTO finance_payments (homeowner_id, phase, pay_year, pay_month, amount, status, reference_no, notes, created_by_admin_id)
      VALUES (?,?,?,?,?,'paid',?,?,?)
      ON DUPLICATE KEY UPDATE amount=VALUES(amount), status='paid', reference_no=VALUES(reference_no), notes=VALUES(notes)
    ");
    $stmt->bind_param("isiidssi", $homeowner_id, $phase, $year, $month, $amount, $ref, $notes, $adminId);
    $stmt->execute();
  }

  header("Location: finance_dues.php" . ($canPickPhase?("?phase=".urlencode($phase)):"") );
  exit;
}
// ---- Unpaid list filter ----
$selYear  = (int)($_GET['year'] ?? (int)date('Y'));
$selMonth = (int)($_GET['month'] ?? (int)date('n'));
if ($selMonth < 1 || $selMonth > 12) $selMonth = (int)date('n');

// Unpaid = approved homeowners in phase with NO payment record for selected year/month
$stmt = $conn->prepare("
  SELECT h.id, h.first_name, h.last_name, h.house_lot_number
  FROM homeowners h
  LEFT JOIN finance_payments p
    ON p.homeowner_id = h.id
   AND p.pay_year = ?
   AND p.pay_month = ?
  WHERE h.phase = ?
    AND h.status = 'approved'
    AND p.id IS NULL
  ORDER BY h.last_name, h.first_name
");
$stmt->bind_param("iis", $selYear, $selMonth, $phase);
$stmt->execute();
$unpaid = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch dues
$stmt = $conn->prepare("SELECT monthly_dues FROM finance_dues_settings WHERE phase=? LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$monthly_dues = (float)($stmt->get_result()->fetch_assoc()['monthly_dues'] ?? 0);

// homeowners list in this phase
$stmt = $conn->prepare("
  SELECT id, first_name, last_name, house_lot_number, status
  FROM homeowners
  WHERE phase=? AND status='approved'
  ORDER BY last_name, first_name
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$homeowners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// current month default
$defYear = (int)date('Y');
$defMonth = (int)date('n');

// payment history (recent)
$stmt = $conn->prepare("
  SELECT p.*, h.first_name, h.last_name, h.house_lot_number
  FROM finance_payments p
  JOIN homeowners h ON h.id = p.homeowner_id
  WHERE p.phase=?
  ORDER BY p.paid_at DESC
  LIMIT 200
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

	<!-- Global site tag (gtag.js) - Google Analytics -->
	 <!-- Include CSS for DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

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
								<li>
									<a href="#">
										<img src="vendors/images/img.jpg" alt="">
										<h3>John Doe</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/photo1.jpg" alt="">
										<h3>Lea R. Frith</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/photo2.jpg" alt="">
										<h3>Erik L. Richards</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/photo3.jpg" alt="">
										<h3>John Doe</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/photo4.jpg" alt="">
										<h3>Renee I. Hansen</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/img.jpg" alt="">
										<h3>Vicki M. Coleman</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
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
					<a href="javascript:void(0);" class="btn btn-outline-primary sidebar-light ">White</a>
					<a href="javascript:void(0);" class="btn btn-outline-primary sidebar-dark active">Dark</a>
				</div>

				<h4 class="weight-600 font-18 pb-10">Menu Dropdown Icon</h4>
				<div class="sidebar-radio-group pb-10 mb-10">
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebaricon-1" name="menu-dropdown-icon" class="custom-control-input" value="icon-style-1" checked="">
						<label class="custom-control-label" for="sidebaricon-1"><i class="fa fa-angle-down"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebaricon-2" name="menu-dropdown-icon" class="custom-control-input" value="icon-style-2">
						<label class="custom-control-label" for="sidebaricon-2"><i class="ion-plus-round"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebaricon-3" name="menu-dropdown-icon" class="custom-control-input" value="icon-style-3">
						<label class="custom-control-label" for="sidebaricon-3"><i class="fa fa-angle-double-right"></i></label>
					</div>
				</div>

				<h4 class="weight-600 font-18 pb-10">Menu List Icon</h4>
				<div class="sidebar-radio-group pb-30 mb-10">
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebariconlist-1" name="menu-list-icon" class="custom-control-input" value="icon-list-style-1" checked="">
						<label class="custom-control-label" for="sidebariconlist-1"><i class="ion-minus-round"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebariconlist-2" name="menu-list-icon" class="custom-control-input" value="icon-list-style-2">
						<label class="custom-control-label" for="sidebariconlist-2"><i class="fa fa-circle-o" aria-hidden="true"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebariconlist-3" name="menu-list-icon" class="custom-control-input" value="icon-list-style-3">
						<label class="custom-control-label" for="sidebariconlist-3"><i class="dw dw-check"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebariconlist-4" name="menu-list-icon" class="custom-control-input" value="icon-list-style-4" checked="">
						<label class="custom-control-label" for="sidebariconlist-4"><i class="icon-copy dw dw-next-2"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebariconlist-5" name="menu-list-icon" class="custom-control-input" value="icon-list-style-5">
						<label class="custom-control-label" for="sidebariconlist-5"><i class="dw dw-fast-forward-1"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebariconlist-6" name="menu-list-icon" class="custom-control-input" value="icon-list-style-6">
						<label class="custom-control-label" for="sidebariconlist-6"><i class="dw dw-next"></i></label>
					</div>
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
<div class="main-container">
  <div class="pd-ltr-20">

    <div class="page-header mb-20">
      <div class="row">
        <div class="col-md-6 col-sm-12">
          <div class="title"><h4>Monthly Dues Management</h4></div>
          <div class="text-secondary">Phase: <b><?=esc($phase)?></b></div>
        </div>
        <div class="col-md-6 col-sm-12 text-right">
          <?php if ($canPickPhase): ?>
            <form method="get" class="d-inline-block">
              <select name="phase" class="form-control d-inline-block" style="width:200px" onchange="this.form.submit()">
                <?php foreach(['Phase 1','Phase 2','Phase 3'] as $p): ?>
                  <option value="<?=esc($p)?>" <?= $p===$phase?'selected':'' ?>><?=esc($p)?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">Set Monthly Dues</h5>
      <form method="post" class="form-inline">
        <label class="mr-2">Monthly Dues (₱)</label>
        <input type="number" step="0.01" min="0" name="monthly_dues" class="form-control mr-2" value="<?=esc(number_format($monthly_dues,2,'.',''))?>" required>
        <button class="btn btn-primary" name="save_dues">Save</button>
      </form>
    </div>

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">Record Payment</h5>
      <form method="post">
        <div class="row">
          <div class="col-md-4">
            <label>Homeowner</label>
            <select name="homeowner_id" class="form-control" required>
              <option value="">-- Select Homeowner --</option>
              <?php foreach($homeowners as $h): ?>
                <option value="<?=$h['id']?>"><?=esc($h['last_name'].", ".$h['first_name']." (".$h['house_lot_number'].")")?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label>Year</label>
            <input type="number" name="pay_year" class="form-control" value="<?=$defYear?>" required>
          </div>
          <div class="col-md-2">
            <label>Month</label>
            <select name="pay_month" class="form-control" required>
              <?php for($m=1;$m<=12;$m++): ?>
                <option value="<?=$m?>" <?= $m===$defMonth?'selected':'' ?>><?=$m?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label>Amount</label>
            <input type="number" step="0.01" min="0" name="amount" class="form-control" value="<?=esc(number_format($monthly_dues,2,'.',''))?>" required>
          </div>
          <div class="col-md-2">
            <label>Reference #</label>
            <input type="text" name="reference_no" class="form-control" placeholder="OR/Ref #">
          </div>
          <div class="col-md-12 mt-2">
            <label>Notes</label>
            <input type="text" name="notes" class="form-control" placeholder="Optional notes">
          </div>
        </div>
        <button class="btn btn-success mt-3" name="record_payment">Save Payment</button>
      </form>
    </div>

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">Payment History (Latest 200)</h5>
      <div class="table-responsive">
        <table id="paymentsTable" class="table table-striped table-hover">
          <thead>
            <tr>
              <th>Date</th>
              <th>Homeowner</th>
              <th>Blk/Lot</th>
              <th>Period</th>
              <th>Amount</th>
              <th>Ref</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($payments as $p): ?>
              <tr>
                <td><?=esc($p['paid_at'] ?? '')?></td>
                <td><?=esc(($p['last_name']??'').", ".($p['first_name']??''))?></td>
                <td><?=esc($p['house_lot_number'] ?? '')?></td>
                <td><?=esc($p['pay_year']."-".str_pad((string)$p['pay_month'],2,'0',STR_PAD_LEFT))?></td>
                <td>₱ <?=number_format((float)$p['amount'],2)?></td>
                <td><?=esc($p['reference_no'] ?? '')?></td>
                <td><?=esc($p['notes'] ?? '')?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card-box mb-20 p-3">
  <div class="d-flex justify-content-between align-items-center">
    <h5 class="mb-0">Unpaid Homeowners</h5>

    <form method="get" class="form-inline">
      <?php if ($canPickPhase): ?>
        <input type="hidden" name="phase" value="<?=esc($phase)?>">
      <?php endif; ?>
      <label class="mr-2">Year</label>
      <input type="number" name="year" class="form-control mr-3" value="<?= (int)$selYear ?>" style="width:120px">
      <label class="mr-2">Month</label>
      <select name="month" class="form-control mr-3" style="width:120px">
        <?php for($m=1;$m<=12;$m++): ?>
          <option value="<?=$m?>" <?= $m===$selMonth?'selected':'' ?>><?=$m?></option>
        <?php endfor; ?>
      </select>
      <button class="btn btn-outline-primary">Filter</button>
    </form>
  </div>

  <div class="mt-3">
    <span class="badge badge-danger">Unpaid count: <?=count($unpaid)?></span>
  </div>

  <div class="table-responsive mt-2">
    <table class="table table-striped table-hover">
      <thead>
        <tr>
          <th>Name</th>
          <th>Blk/Lot</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$unpaid): ?>
          <tr><td colspan="2" class="text-center text-secondary">No unpaid homeowners for this period.</td></tr>
        <?php else: ?>
          <?php foreach($unpaid as $u): ?>
            <tr>
              <td><?=esc($u['last_name'].", ".$u['first_name'])?></td>
              <td><?=esc($u['house_lot_number'])?></td>
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

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function(){
  $('#paymentsTable').DataTable({ pageLength: 25, order: [[0,'desc']] });
});
</script>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
</body>
</html>
