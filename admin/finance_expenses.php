<?php
require_once __DIR__ . "/finance_helpers.php";
require_admin();
$conn = db_conn();

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);
$adminId = admin_id();

$uploadDir = __DIR__ . "/uploads/finance/receipts/";
$uploadUrl = "uploads/finance/receipts/";

if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }

if (isset($_POST['add_expense'])) {
  $category = $_POST['category'] ?? 'other';
  if (!in_array($category, ['maintenance','security','utilities','other'], true)) $category = 'other';

  $description = trim($_POST['description'] ?? '');
  $amount = (float)($_POST['amount'] ?? 0);
  $expense_date = $_POST['expense_date'] ?? date('Y-m-d');

  $receipt_path = null;
  if (!empty($_FILES['receipt']['name'])) {
    $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','pdf'], true)) {
      die("Invalid receipt file type. Use JPG/PNG/PDF only.");
    }
    $safeName = time() . "_" . bin2hex(random_bytes(6)) . "." . $ext;
    $dest = $uploadDir . $safeName;
    if (move_uploaded_file($_FILES['receipt']['tmp_name'], $dest)) {
      $receipt_path = $uploadUrl . $safeName;
    }
  }

  if ($description !== '' && $amount > 0) {
    $stmt = $conn->prepare("
      INSERT INTO finance_expenses (phase, category, description, amount, expense_date, receipt_path, created_by_admin_id)
      VALUES (?,?,?,?,?,?,?)
    ");
    $stmt->bind_param("sssdssi", $phase, $category, $description, $amount, $expense_date, $receipt_path, $adminId);
    $stmt->execute();
  }

  header("Location: finance_expenses.php" . ($canPickPhase?("?phase=".urlencode($phase)):"") );
  exit;
}

$stmt = $conn->prepare("
  SELECT *
  FROM finance_expenses
  WHERE phase=?
  ORDER BY expense_date DESC, id DESC
  LIMIT 300
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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

    <div class="page-header mb-20">
      <div class="row">
        <div class="col-md-6 col-sm-12">
          <div class="title"><h4>Expense Tracking</h4></div>
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
      <h5 class="mb-3">Record Expense</h5>
      <form method="post" enctype="multipart/form-data">
        <div class="row">
          <div class="col-md-3">
            <label>Category</label>
            <select name="category" class="form-control" required>
              <option value="maintenance">maintenance</option>
              <option value="security">security</option>
              <option value="utilities">utilities</option>
              <option value="other" selected>other</option>
            </select>
          </div>
          <div class="col-md-5">
            <label>Description</label>
            <input name="description" class="form-control" required>
          </div>
          <div class="col-md-2">
            <label>Amount</label>
            <input name="amount" class="form-control" type="number" step="0.01" min="0" required>
          </div>
          <div class="col-md-2">
            <label>Date</label>
            <input name="expense_date" class="form-control" type="date" value="<?=date('Y-m-d')?>" required>
          </div>
          <div class="col-md-12 mt-2">
            <label>Receipt (JPG/PNG/PDF)</label>
            <input type="file" name="receipt" class="form-control">
          </div>
        </div>
        <button class="btn btn-danger mt-3" name="add_expense">Save Expense</button>
      </form>
    </div>

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">Expenses List</h5>
      <div class="table-responsive">
        <table id="expTable" class="table table-striped table-hover">
          <thead>
            <tr>
              <th>Date</th>
              <th>Category</th>
              <th>Description</th>
              <th>Amount</th>
              <th>Receipt</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?=esc($r['expense_date'])?></td>
                <td><?=esc($r['category'])?></td>
                <td><?=esc($r['description'])?></td>
                <td>₱ <?=number_format((float)$r['amount'],2)?></td>
                <td>
                  <?php if (!empty($r['receipt_path'])): ?>
                    <a href="<?=esc($r['receipt_path'])?>" target="_blank">View</a>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
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
  $('#expTable').DataTable({ pageLength: 25, order: [[0,'desc']] });
});
</script>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
</body>
</html>
