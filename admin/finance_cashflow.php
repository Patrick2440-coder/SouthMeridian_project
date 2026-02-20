<?php
require_once __DIR__ . "/finance_helpers.php";
require_admin();
$conn = db_conn();

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);

// dues setting
$stmt = $conn->prepare("SELECT monthly_dues FROM finance_dues_settings WHERE phase=? LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$monthly_dues = (float)($stmt->get_result()->fetch_assoc()['monthly_dues'] ?? 0);

// opening
$stmt = $conn->prepare("SELECT opening_balance FROM finance_opening_balance WHERE phase=? LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$opening = (float)($stmt->get_result()->fetch_assoc()['opening_balance'] ?? 0);

// totals
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) total_paid FROM finance_payments WHERE phase=? AND status='paid'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$total_paid = (float)($stmt->get_result()->fetch_assoc()['total_paid'] ?? 0);

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) total_don FROM finance_donations WHERE phase=?");
$stmt->bind_param("s", $phase);
$stmt->execute();
$total_don = (float)($stmt->get_result()->fetch_assoc()['total_don'] ?? 0);

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) total_exp FROM finance_expenses WHERE phase=?");
$stmt->bind_param("s", $phase);
$stmt->execute();
$total_exp = (float)($stmt->get_result()->fetch_assoc()['total_exp'] ?? 0);

$current_balance = $opening + $total_paid + $total_don - $total_exp;

// pending collections estimate: approved homeowners * dues - paid this month
$y = (int)date('Y');
$m = (int)date('n');

$stmt = $conn->prepare("SELECT COUNT(*) c FROM homeowners WHERE phase=? AND status='approved'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$homeowner_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) s FROM finance_payments WHERE phase=? AND pay_year=? AND pay_month=? AND status='paid'");
$stmt->bind_param("sii", $phase, $y, $m);
$stmt->execute();
$paid_this_month = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);

$expected_this_month = $homeowner_count * $monthly_dues;
$pending_collections = max(0, $expected_this_month - $paid_this_month);

$low_fund = $current_balance < 5000; // you can change threshold
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


					<li class="dropdown">
						<a href="javascript:;" class="dropdown-toggle">
							<span class="micon dw dw-user"></span>
							<span class="mtext">Homeowner Management</span>
						</a>
						<ul class="submenu" >
							<li><a  href="ho_approval.php">Household Approval</a></li>
							<li><a href="ho_register.php">Register Household</a></li>
							<li><a href="ho_approved.php">Approved Households</a></li>
						</ul>
					</li>

					<!-- ✅ USER MANAGEMENT DROPDOWN -->
					<li class="dropdown <?= in_array($view, ['homeowners','officers'], true) ? 'active' : '' ?>">
						<a href="javascript:;" class="dropdown-toggle">
							<span class="micon dw dw-user"></span>
							<span class="mtext">User Management</span>
						</a>
						<ul class="submenu" style="<?= in_array($view, ['homeowners','officers'], true) ? 'display:block;' : '' ?>">
							<li>
								<a href="users-management.php?view=homeowners" class="<?= $view==='homeowners' ? 'active' : '' ?>">
									<i class="dw dw-user-2 mr-2"></i> Homeowners
								</a>
							</li>
							<li>
								<a href="users-management.php?view=officers" class="<?= $view==='officers' ? 'active' : '' ?>">
									<i class="dw dw-shield1 mr-2"></i> Officers
								</a>
							</li>
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

    <div class="page-header mb-20">
      <div class="row">
        <div class="col-md-6 col-sm-12">
          <div class="title"><h4>Cash Flow Dashboard</h4></div>
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

    <?php if ($low_fund): ?>
      <div class="alert alert-danger">⚠ Low funds alert! Current balance is below ₱5,000.</div>
    <?php endif; ?>

    <div class="row">
      <div class="col-md-4 mb-20">
        <div class="card-box p-3">
          <h6>Current Balance</h6>
          <h3>₱ <?=number_format($current_balance,2)?></h3>
          <small class="text-secondary">Opening: ₱ <?=number_format($opening,2)?></small>
        </div>
      </div>

      <div class="col-md-4 mb-20">
        <div class="card-box p-3">
          <h6>Pending Collections (This Month)</h6>
          <h3>₱ <?=number_format($pending_collections,2)?></h3>
          <small class="text-secondary">Expected: ₱ <?=number_format($expected_this_month,2)?> | Paid: ₱ <?=number_format($paid_this_month,2)?></small>
        </div>
      </div>

      <div class="col-md-4 mb-20">
        <div class="card-box p-3">
          <h6>Totals</h6>
          <div>Dues Collected: <b>₱ <?=number_format($total_paid,2)?></b></div>
          <div>Donations: <b>₱ <?=number_format($total_don,2)?></b></div>
          <div>Expenses: <b>₱ <?=number_format($total_exp,2)?></b></div>
        </div>
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
