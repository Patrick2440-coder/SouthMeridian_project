<?php
session_start();

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
  exit();
}

$host="localhost"; $db="south_meridian_hoa"; $user="root"; $pass="";
$conn = new mysqli($host,$user,$pass,$db);
if ($conn->connect_error) die("Connection failed: ".$conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// admin info from DB
$admin_id = (int)$_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT phase, role FROM admins WHERE id=?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();
$admin_phase = $admin['phase'] ?? '';
$admin_role  = $admin['role'] ?? '';

$isHOSection = true;

// ---- STEP 2: Final submission with pinned map ----
if (isset($_POST['submit_location'])) {

  $first_name       = trim($_POST['first_name'] ?? '');
  $middle_name      = trim($_POST['middle_name'] ?? '');
  $last_name        = trim($_POST['last_name'] ?? '');
  $contact_number   = trim($_POST['contact_number'] ?? '');
  $email            = trim($_POST['email'] ?? '');
  $password_raw     = (string)($_POST['password'] ?? '');
  $phase            = trim($_POST['phase'] ?? '');
  $house_lot_number = trim($_POST['house_lot_number'] ?? '');
  $latitude         = (string)($_POST['latitude'] ?? '');
  $longitude        = (string)($_POST['longitude'] ?? '');

  if ($first_name==='' || $last_name==='' || $email==='' || $password_raw==='' || $phase==='' || $house_lot_number==='') {
    echo "<script>alert('Missing required fields.'); history.back();</script>";
    exit;
  }

  // assign admin based on phase
  $stmtAdmin = $conn->prepare("SELECT id FROM admins WHERE phase=? LIMIT 1");
  $stmtAdmin->bind_param("s", $phase);
  $stmtAdmin->execute();
  $resAdmin = $stmtAdmin->get_result()->fetch_assoc();
  $assigned_admin_id = $resAdmin['id'] ?? null;
  $stmtAdmin->close();

  // carried from step1
  $valid_id_path = (string)($_POST['valid_id_tmp'] ?? '');
  $proof_path    = (string)($_POST['proof_tmp'] ?? '');

  if ($valid_id_path==='' || $proof_path==='') {
    echo "<script>alert('Missing uploaded documents. Please re-submit registration.'); window.location='ho_register.php';</script>";
    exit;
  }

  $password = password_hash($password_raw, PASSWORD_DEFAULT);
  $status = 'pending';

  $stmtHome = $conn->prepare("
    INSERT INTO homeowners
      (first_name,middle_name,last_name,contact_number,email,password,phase,house_lot_number,valid_id_path,proof_of_billing_path,latitude,longitude,admin_id,status)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");

  $stmtHome->bind_param(
    "ssssssssssssis",
    $first_name, $middle_name, $last_name, $contact_number, $email, $password, $phase, $house_lot_number,
    $valid_id_path, $proof_path, $latitude, $longitude, $assigned_admin_id, $status
  );

  if (!$stmtHome->execute()) {
    echo "<script>alert('Insert failed: ".esc($stmtHome->error)."'); history.back();</script>";
    exit;
  }
  $homeowner_id = $stmtHome->insert_id;
  $stmtHome->close();

  if (isset($_POST['member_first_name']) && is_array($_POST['member_first_name'])) {
    foreach ($_POST['member_first_name'] as $i => $mfname) {
      $mfname = trim((string)$mfname);
      $mmname = trim((string)($_POST['member_middle_name'][$i] ?? ''));
      $mlname = trim((string)($_POST['member_last_name'][$i] ?? ''));
      $relation= trim((string)($_POST['member_relation'][$i] ?? ''));

      if ($mfname==='' || $mlname==='' || $relation==='') continue;

      $stmtMember = $conn->prepare("
        INSERT INTO household_members (homeowner_id, first_name, middle_name, last_name, relation)
        VALUES (?,?,?,?,?)
      ");
      $stmtMember->bind_param("issss", $homeowner_id, $mfname, $mmname, $mlname, $relation);
      $stmtMember->execute();
      $stmtMember->close();
    }
  }

  echo "<script>alert('Done Registering.'); location.href='ho_approval.php';</script>";
  exit;
}

// ---- STEP 1: Registration submit (upload documents, then show map) ----
$showMap = isset($_POST['registration_submit']) && !isset($_POST['submit_location']);

$valid_id_tmp = '';
$proof_tmp = '';

if ($showMap) {
  $uploadDir = "uploads/";
  if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

  if (empty($_FILES['valid_id']['tmp_name']) || empty($_FILES['proof_of_billing']['tmp_name'])) {
    echo "<script>alert('Please upload Valid ID and Proof of Billing.'); history.back();</script>";
    exit;
  }

  $valid_id_tmp = $uploadDir . time() . "_id_" . basename($_FILES['valid_id']['name']);
  $proof_tmp    = $uploadDir . time() . "_proof_" . basename($_FILES['proof_of_billing']['name']);

  if (!move_uploaded_file($_FILES['valid_id']['tmp_name'], $valid_id_tmp)) {
    echo "<script>alert('Failed to upload Valid ID.'); history.back();</script>";
    exit;
  }
  if (!move_uploaded_file($_FILES['proof_of_billing']['tmp_name'], $proof_tmp)) {
    echo "<script>alert('Failed to upload Proof of Billing.'); history.back();</script>";
    exit;
  }
}
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
	  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

	<!-- Global site tag (gtag.js) - Google Analytics -->
	 <!-- Include CSS for DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

	<script async src="https://www.googletagmanager.com/gtag/js?id=UA-119386393-1"></script>

    <!-- ================= MAPS ================= -->

<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css">
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

	<style>
		:root{--brand:#077f46;}
		.card-box{border-radius:14px}
		#map{height:520px;width:100%}
		.page-title-wrap{display:flex;align-items:center;justify-content:center;text-align:center;margin-bottom:14px}
		.page-title-wrap .subtitle{font-size:14px}
		.step-pill{display:inline-flex;gap:8px;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:800;font-size:12px}
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
						<span class="user-icon">
							<img src="vendors/images/photo1.jpg" alt="">
						</span>
						<span class="user-name"><?= esc(strtoupper($admin_role)) ?></span>
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

			<div class="page-title-wrap">
				<div>
					<h2 class="h4 mb-1">Home Owner Management</h2>
					<div class="text-muted fw-semibold subtitle">Register Household</div>
				</div>
			</div>

			<div class="card-box p-3">

				<?php if (!$showMap): ?>
					<div class="d-flex justify-content-end mb-2">
						<span class="step-pill">✅ Step 1: Details</span>
					</div>

					<form method="POST" enctype="multipart/form-data" id="registrationForm">
						<h5 class="mb-3 border-bottom pb-2">Homeowner Information</h5>

						<div class="row g-3 mb-4">
							<div class="col-md-4">
								<label class="form-label fw-semibold">First Name</label>
								<input type="text" name="first_name" class="form-control" required>
							</div>
							<div class="col-md-4">
								<label class="form-label fw-semibold">Middle Name</label>
								<input type="text" name="middle_name" class="form-control">
							</div>
							<div class="col-md-4">
								<label class="form-label fw-semibold">Last Name</label>
								<input type="text" name="last_name" class="form-control" required>
							</div>
						</div>

						<div class="row g-3 mb-4">
							<div class="col-md-6">
								<label class="form-label fw-semibold">Contact Number</label>
								<input type="tel" name="contact_number" class="form-control" required>
							</div>
							<div class="col-md-6">
								<label class="form-label fw-semibold">Email Address</label>
								<input type="email" name="email" class="form-control" required>
							</div>
						</div>

						<div class="row g-3 mb-4">
							<div class="col-md-6">
								<label class="form-label fw-semibold">Password</label>
								<input type="password" name="password" class="form-control" minlength="8" required>
							</div>
							<div class="col-md-6">
								<label class="form-label fw-semibold">Confirm Password</label>
								<input type="password" name="confirm_password" class="form-control" minlength="8" required>
							</div>
						</div>

						<div class="row g-3 mb-4">
							<div class="col-md-6">
								<label class="form-label fw-semibold">Phase</label>
								<select name="phase" class="form-control" required>
									<option disabled selected>Select Phase</option>
									<option>Phase 1</option>
									<option>Phase 2</option>
									<option>Phase 3</option>
								</select>
							</div>
							<div class="col-md-6">
								<label class="form-label fw-semibold">House / Lot Number</label>
								<input type="text" name="house_lot_number" class="form-control" required>
							</div>
						</div>

						<h5 class="mt-4 mb-3 border-bottom pb-2">Required Documents</h5>
						<div class="row g-4 mb-4">
							<div class="col-md-6">
								<label class="form-label fw-semibold">Valid ID</label>
								<input type="file" name="valid_id" class="form-control" required>
							</div>
							<div class="col-md-6">
								<label class="form-label fw-semibold">Proof of Billing</label>
								<input type="file" name="proof_of_billing" class="form-control" required>
							</div>
						</div>

						<h5 class="mt-4 mb-3 border-bottom pb-2">Household Members</h5>
						<div id="members">
							<div class="member border rounded-3 p-3 mb-3 bg-white">
								<div class="row g-3 align-items-end">
									<div class="col-md-4">
										<input type="text" name="member_first_name[]" class="form-control" placeholder="First Name" required>
									</div>
									<div class="col-md-3">
										<input type="text" name="member_middle_name[]" class="form-control" placeholder="Middle Name">
									</div>
									<div class="col-md-3">
										<input type="text" name="member_last_name[]" class="form-control" placeholder="Last Name" required>
									</div>
									<div class="col-md-2">
										<select name="member_relation[]" class="form-control" required>
											<option disabled selected>Relation</option>
											<option>Homeowner</option>
											<option>Spouse</option>
											<option>Child</option>
											<option>Parent</option>
											<option>Relative</option>
											<option>Tenant</option>
											<option>Caretaker</option>
										</select>
									</div>
								</div>
							</div>
						</div>

						<button type="button" class="btn btn-outline-success mb-3" onclick="addMember()">+ Add Member</button>

						<div class="d-flex justify-content-end">
							<button type="submit" name="registration_submit" class="btn btn-success px-4">
								Next: Pin Location
							</button>
						</div>
					</form>

				<?php else: ?>
					<div class="d-flex justify-content-between align-items-center mb-2">
						<span class="step-pill">✅ Step 1: Details</span>
						<span class="step-pill">📍 Step 2: Pin Location</span>
					</div>

					<form method="POST">
						<h5 class="mb-3 border-bottom pb-2 text-success">Pin Homeowner Location</h5>

						<?php
						foreach($_POST as $key => $value){
							if (is_array($value)) {
								foreach ($value as $v) {
									echo '<input type="hidden" name="'.esc($key).'[]" value="'.esc($v).'">';
								}
							} else {
								echo '<input type="hidden" name="'.esc($key).'" value="'.esc($value).'">';
							}
						}
						?>

						<input type="hidden" name="valid_id_tmp" value="<?= esc($valid_id_tmp) ?>">
						<input type="hidden" name="proof_tmp" value="<?= esc($proof_tmp) ?>">

						<input type="hidden" name="latitude" id="latitude">
						<input type="hidden" name="longitude" id="longitude">

						<div id="map" style="border:2px solid var(--brand); border-radius:14px;"></div>

						<button type="submit" name="submit_location" class="btn btn-success w-100 mt-3">
							Submit Registration
						</button>
					</form>

					<script>
						let map, marker;

						document.addEventListener('DOMContentLoaded', function(){
							map = L.map('map').setView([14.3545, 120.946], 16);

							L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
								attribution: '&copy; OpenStreetMap contributors'
							}).addTo(map);

							const allowedArea = L.polygon([
								[14.357391, 120.943993],
								[14.351903, 120.944937],
								[14.352257, 120.948118],
								[14.357828, 120.947329]
							], { color: 'green' }).addTo(map);

							map.fitBounds(allowedArea.getBounds());
							const center = allowedArea.getBounds().getCenter();

							marker = L.marker(center, { draggable:true }).addTo(map);

							function setHidden(pos){
								document.getElementById('latitude').value = pos.lat;
								document.getElementById('longitude').value = pos.lng;
							}

							marker.on('dragend', e => setHidden(e.target.getLatLng()));
							setHidden(center);

							setTimeout(()=>map.invalidateSize(), 250);
						});
					</script>
				<?php endif; ?>

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
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

	<script>
		function addMember() {
			const members = document.getElementById('members');
			const member = members.firstElementChild.cloneNode(true);
			member.querySelectorAll('input').forEach(input => input.value = '');
			member.querySelector('select').selectedIndex = 0;
			members.appendChild(member);
		}
	</script>

</body>
</html>
