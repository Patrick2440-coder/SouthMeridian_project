<?php
require_once __DIR__ . "/finance_helpers.php";
require_admin();
$conn = db_conn();

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);
$adminId = admin_id();


if (isset($_POST['add_donation'])) {
  $donor_name    = trim($_POST['donor_name'] ?? '');
  $donor_email   = trim($_POST['donor_email'] ?? '');
  $amount        = (float)($_POST['amount'] ?? 0);
  $donation_date = $_POST['donation_date'] ?? date('Y-m-d');
  $receipt_no    = trim($_POST['receipt_no'] ?? '');
  $message       = trim($_POST['message'] ?? '');

  if ($donor_name !== '' && $amount > 0) {
    $stmt = $conn->prepare("
      INSERT INTO finance_donations
        (phase, donor_name, donor_email, amount, donation_date, receipt_no, message, created_by_admin_id)
      VALUES (?,?,?,?,?,?,?,?)
    ");
    if (!$stmt) {
      die("Prepare failed: " . $conn->error);
    }

    // ✅ Correct: 8 placeholders = 8 types, no spaces, bind only once
    $stmt->bind_param(
      "sssdsssi",
      $phase,
      $donor_name,
      $donor_email,
      $amount,
      $donation_date,
      $receipt_no,
      $message,
      $adminId
    );

    $stmt->execute();
    $stmt->close();
  }

  header("Location: finance_donations.php" . ($canPickPhase ? ("?phase=" . urlencode($phase)) : ""));
  exit;
}

$stmt = $conn->prepare("
  SELECT *
  FROM finance_donations
  WHERE phase=?
  ORDER BY donation_date DESC, id DESC
  LIMIT 300
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
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

<div class="main-container">
  <div class="pd-ltr-20">

    <div class="page-header mb-20">
      <div class="row">
        <div class="col-md-6 col-sm-12">
          <div class="title"><h4>Donations & Contributions</h4></div>
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
      <h5 class="mb-3">Record Donation</h5>
      <form method="post">
        <div class="row">
          <div class="col-md-4">
            <label>Donor Name</label>
            <input class="form-control" name="donor_name" required>
          </div>
          <div class="col-md-4">
            <label>Donor Email (optional)</label>
            <input class="form-control" name="donor_email" type="email">
          </div>
          <div class="col-md-2">
            <label>Amount</label>
            <input class="form-control" name="amount" type="number" step="0.01" min="0" required>
          </div>
          <div class="col-md-2">
            <label>Date</label>
            <input class="form-control" name="donation_date" type="date" value="<?=date('Y-m-d')?>" required>
          </div>
          <div class="col-md-3 mt-2">
            <label>Receipt #</label>
            <input class="form-control" name="receipt_no" placeholder="Show on acknowledgment">
          </div>
          <div class="col-md-9 mt-2">
            <label>Message / Notes</label>
            <input class="form-control" name="message" placeholder="Optional">
          </div>
        </div>
        <button class="btn btn-success mt-3" name="add_donation">Save Donation</button>
      </form>
    </div>

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">Donor List</h5>
      <div class="table-responsive">
        <table id="donTable" class="table table-striped table-hover">
          <thead>
            <tr>
              <th>Date</th>
              <th>Donor</th>
              <th>Email</th>
              <th>Amount</th>
              <th>Receipt #</th>
              <th>Notes</th>
              <th>Receipt</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?=esc($r['donation_date'])?></td>
                <td><?=esc($r['donor_name'])?></td>
                <td><?=esc($r['donor_email'] ?? '')?></td>
                <td>₱ <?=number_format((float)$r['amount'],2)?></td>
                <td><?=esc($r['receipt_no'] ?? '')?></td>
                <td><?=esc($r['message'] ?? '')?></td>
                <td>
                  <a class="btn btn-sm btn-outline-primary" target="_blank"
                     href="finance_donation_receipt.php?id=<?=(int)$r['id']?><?= $canPickPhase?('&phase='.urlencode($phase)) : '' ?>">
                    Receipt
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <small class="text-secondary">Acknowledgment receipt printing can be added next (PDF generator).</small>
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
  $('#donTable').DataTable({ pageLength: 25, order: [[0,'desc']] });
});
</script>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
</body>
</html>
