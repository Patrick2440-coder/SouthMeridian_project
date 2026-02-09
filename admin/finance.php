<?php
require_once __DIR__ . "/finance_helpers.php";
require_admin();
$conn = db_conn();

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);

// totals
// dues payments
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) AS total_paid
  FROM finance_payments
  WHERE phase=? AND status='paid'
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$total_paid = (float)($stmt->get_result()->fetch_assoc()['total_paid'] ?? 0);

// donations
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) AS total_don
  FROM finance_donations
  WHERE phase=?
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$total_don = (float)($stmt->get_result()->fetch_assoc()['total_don'] ?? 0);

// expenses
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) AS total_exp
  FROM finance_expenses
  WHERE phase=?
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$total_exp = (float)($stmt->get_result()->fetch_assoc()['total_exp'] ?? 0);

// opening balance
$stmt = $conn->prepare("
  SELECT opening_balance
  FROM finance_opening_balance
  WHERE phase=?
  LIMIT 1
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$opening = (float)($stmt->get_result()->fetch_assoc()['opening_balance'] ?? 0);

$current_balance = $opening + $total_paid + $total_don - $total_exp;
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
			<a href="index.html">
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

        <li>
            <a href="HO-management.php" class="dropdown-toggle no-arrow">
                <span class="micon dw dw-user1"></span>
                <span class="mtext">Homeowner Management</span>
            </a>
        </li>

        <!-- NEW: User Management -->
        <li>
            <a href="users-management.php" class="dropdown-toggle no-arrow">
                <span class="micon dw dw-user"></span>
                <span class="mtext">User Management</span>
            </a>
        </li>

        <!-- NEW: Announcement -->
        <li>
            <a href="announcements.php" class="dropdown-toggle no-arrow">
                <span class="micon dw dw-megaphone"></span>
                <span class="mtext">Announcement</span>
            </a>
        </li>
		<!-- FINANCE (Dropdown) -->
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
        <!-- Settings (now pushed down) -->
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
  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header">
        <div class="row">
          <div class="col-md-6 col-sm-12">
            <div class="title"><h4>Finance Overview</h4></div>
            <nav aria-label="breadcrumb" role="navigation">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                <li class="breadcrumb-item active" aria-current="page">Finance</li>
              </ol>
            </nav>
          </div>
          <div class="col-md-6 col-sm-12 text-right">
            <?php if ($canPickPhase): ?>
              <form method="get" class="d-inline-block">
                <label class="mr-2">Phase</label>
                <select name="phase" class="form-control d-inline-block" style="width:200px" onchange="this.form.submit()">
                  <?php foreach(['Phase 1','Phase 2','Phase 3'] as $p): ?>
                    <option value="<?=esc($p)?>" <?= $p===$phase?'selected':'' ?>><?=esc($p)?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php else: ?>
              <span class="badge badge-primary p-2"><?= esc($phase) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-xl-3 col-lg-6 col-md-6 mb-20">
          <div class="card-box height-100-p widget-style3">
            <div class="d-flex flex-wrap">
              <div class="widget-data">
                <div class="weight-700 font-24">₱ <?= number_format($current_balance,2) ?></div>
                <div class="font-14 text-secondary weight-500">Current Balance</div>
              </div>
              <div class="widget-icon"><div class="icon" data-color="#00eccf"><i class="dw dw-money"></i></div></div>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-20">
          <div class="card-box height-100-p widget-style3">
            <div class="d-flex flex-wrap">
              <div class="widget-data">
                <div class="weight-700 font-24">₱ <?= number_format($total_paid,2) ?></div>
                <div class="font-14 text-secondary weight-500">Dues Collected</div>
              </div>
              <div class="widget-icon"><div class="icon" data-color="#ffd000"><i class="dw dw-wallet"></i></div></div>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-20">
          <div class="card-box height-100-p widget-style3">
            <div class="d-flex flex-wrap">
              <div class="widget-data">
                <div class="weight-700 font-24">₱ <?= number_format($total_don,2) ?></div>
                <div class="font-14 text-secondary weight-500">Donations</div>
              </div>
              <div class="widget-icon"><div class="icon" data-color="#6f42c1"><i class="dw dw-heart"></i></div></div>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-20">
          <div class="card-box height-100-p widget-style3">
            <div class="d-flex flex-wrap">
              <div class="widget-data">
                <div class="weight-700 font-24">₱ <?= number_format($total_exp,2) ?></div>
                <div class="font-14 text-secondary weight-500">Expenses</div>
              </div>
              <div class="widget-icon"><div class="icon" data-color="#dc3545"><i class="dw dw-file"></i></div></div>
            </div>
          </div>
        </div>
      </div>

      <div class="card-box mb-20 p-3">
        <h5 class="mb-3">Treasurer Modules</h5>
        <div class="row">
          <div class="col-md-4 mb-2"><a class="btn btn-outline-primary btn-block" href="finance_dues.php<?= $canPickPhase?('?phase='.urlencode($phase)):'' ?>">Monthly Dues Management</a></div>
          <div class="col-md-4 mb-2"><a class="btn btn-outline-primary btn-block" href="finance_donations.php<?= $canPickPhase?('?phase='.urlencode($phase)):'' ?>">Donations & Contributions</a></div>
          <div class="col-md-4 mb-2"><a class="btn btn-outline-primary btn-block" href="finance_expenses.php<?= $canPickPhase?('?phase='.urlencode($phase)):'' ?>">Expense Tracking</a></div>
          <div class="col-md-6 mb-2"><a class="btn btn-outline-success btn-block" href="finance_reports.php<?= $canPickPhase?('?phase='.urlencode($phase)):'' ?>">Financial Reports (President Approval)</a></div>
          <div class="col-md-6 mb-2"><a class="btn btn-outline-dark btn-block" href="finance_cashflow.php<?= $canPickPhase?('?phase='.urlencode($phase)):'' ?>">Cash Flow Dashboard</a></div>
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
</body>
</html>
