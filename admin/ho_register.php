<?php
session_start();
require_once 'admin_access.php';
requireAccess('homeowner_management');

function esc($v){
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function redirect_with_message(string $type, string $message, string $location = 'ho_register.php'){
  $_SESSION['flash_type'] = $type;
  $_SESSION['flash_message'] = $message;
  header("Location: " . $location);
  exit;
}

function normalizePhase($phase){
  $phase = trim((string)$phase);
  $allowed = ['Phase 1','Phase 2','Phase 3'];
  return in_array($phase, $allowed, true) ? $phase : '';
}

function phase_prefix(string $phase): string {
  $n = (int) filter_var($phase, FILTER_SANITIZE_NUMBER_INT);
  return $n > 0 ? ('P'.$n) : 'P';
}

/*
 * Generate public ID like:
 * Phase 1 + sequence 37 = P137
 * Phase 2 + sequence 5  = P25
 * Phase 3 + sequence 12 = P312
 */
function generatePublicId(mysqli $conn, string $phase): string {
  $phaseNumber = (int) filter_var($phase, FILTER_SANITIZE_NUMBER_INT);
  if ($phaseNumber <= 0) {
    $phaseNumber = 1;
  }

  // Count homeowners in the same phase, then add 1
  $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM homeowners WHERE phase = ?");
  $stmt->bind_param("s", $phase);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $sequence = (int)($result['total'] ?? 0) + 1;

  do {
    $candidate = 'P' . $phaseNumber . $sequence;

    $stmt = $conn->prepare("SELECT id FROM homeowners WHERE public_id = ? LIMIT 1");
    $stmt->bind_param("s", $candidate);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($exists) {
      $sequence++;
    }
  } while ($exists);

  return $candidate;
}

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  $_SESSION['flash_type'] = 'danger';
  $_SESSION['flash_message'] = 'Access denied. Please login as admin.';
  header("Location: index.php");
  exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = "localhost";
$db   = "u972459197_south_meridian";
$user = "u972459197_patrick";
$pass = "Idle2440";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// admin info from DB
$admin_id = (int)$_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT phase, role FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

$admin_phase = $admin['phase'] ?? '';
$admin_role  = $admin['role'] ?? '';
$isHOSection = true;

if (!isset($permissions) || !is_array($permissions)) {
  $permissions = [];
}

// ---- STEP 2: Final submission with pinned map ----
if (isset($_POST['submit_location'])) {
  try {
    $first_name         = trim($_POST['first_name'] ?? '');
    $middle_name        = trim($_POST['middle_name'] ?? '');
    $last_name          = trim($_POST['last_name'] ?? '');
    $contact_number     = trim($_POST['contact_number'] ?? '');
    $email              = trim($_POST['email'] ?? '');
    $password_raw       = (string)($_POST['password'] ?? '');
    $confirm_password   = (string)($_POST['confirm_password'] ?? '');
    $phase              = normalizePhase($_POST['phase'] ?? '');
    $house_lot_number   = trim($_POST['house_lot_number'] ?? '');
    $latitude           = trim((string)($_POST['latitude'] ?? ''));
    $longitude          = trim((string)($_POST['longitude'] ?? ''));

    if ($first_name === '' || $last_name === '' || $contact_number === '' || $email === '' ||
        $password_raw === '' || $phase === '' || $house_lot_number === '') {
      redirect_with_message('danger', 'Missing required fields.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      redirect_with_message('danger', 'Invalid email address.');
    }

    if (strlen($password_raw) < 8) {
      redirect_with_message('danger', 'Password must be at least 8 characters.');
    }

    if ($password_raw !== $confirm_password) {
      redirect_with_message('danger', 'Password and Confirm Password do not match.');
    }

    if ($latitude === '' || $longitude === '' || !is_numeric($latitude) || !is_numeric($longitude)) {
      redirect_with_message('danger', 'Please pin the homeowner location on the map.');
    }

    $latitudeF  = (float)$latitude;
    $longitudeF = (float)$longitude;

    // carried from step1
    $valid_id_path = (string)($_POST['valid_id_tmp'] ?? '');
    $proof_path    = (string)($_POST['proof_tmp'] ?? '');

    if ($valid_id_path === '' || $proof_path === '') {
      redirect_with_message('danger', 'Missing uploaded documents. Please re-submit registration.');
    }

    // check duplicate email first
    $stmtCheck = $conn->prepare("SELECT id FROM homeowners WHERE email = ? LIMIT 1");
    $stmtCheck->bind_param("s", $email);
    $stmtCheck->execute();
    $exists = $stmtCheck->get_result()->fetch_assoc();
    $stmtCheck->close();

    if ($exists) {
      redirect_with_message('danger', 'Email already exists.');
    }

    // assign admin based on phase
    $assigned_admin_id = null;
    $stmtAdmin = $conn->prepare("
      SELECT id
      FROM admins
      WHERE phase = ?
      ORDER BY CASE WHEN position = 'President' THEN 0 ELSE 1 END, id ASC
      LIMIT 1
    ");
    $stmtAdmin->bind_param("s", $phase);
    $stmtAdmin->execute();
    $resAdmin = $stmtAdmin->get_result()->fetch_assoc();
    $stmtAdmin->close();

    if ($resAdmin && isset($resAdmin['id'])) {
      $assigned_admin_id = (int)$resAdmin['id'];
    }

    $password  = password_hash($password_raw, PASSWORD_DEFAULT);
    $status    = 'pending';
    $public_id = generatePublicId($conn, $phase);

    $conn->begin_transaction();

    $stmtHome = $conn->prepare("
      INSERT INTO homeowners
      (
        public_id,
        first_name,
        middle_name,
        last_name,
        contact_number,
        email,
        password,
        phase,
        house_lot_number,
        valid_id_path,
        proof_of_billing_path,
        latitude,
        longitude,
        admin_id,
        status
      )
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $stmtHome->bind_param(
      "sssssssssssddis",
      $public_id,
      $first_name,
      $middle_name,
      $last_name,
      $contact_number,
      $email,
      $password,
      $phase,
      $house_lot_number,
      $valid_id_path,
      $proof_path,
      $latitudeF,
      $longitudeF,
      $assigned_admin_id,
      $status
    );

    $stmtHome->execute();
    $homeowner_id = $stmtHome->insert_id;
    $stmtHome->close();

    if (isset($_POST['member_first_name']) && is_array($_POST['member_first_name'])) {
      $stmtMember = $conn->prepare("
        INSERT INTO household_members
        (homeowner_id, first_name, middle_name, last_name, relation)
        VALUES (?,?,?,?,?)
      ");

      foreach ($_POST['member_first_name'] as $i => $mfname) {
        $mfname   = trim((string)$mfname);
        $mmname   = trim((string)($_POST['member_middle_name'][$i] ?? ''));
        $mlname   = trim((string)($_POST['member_last_name'][$i] ?? ''));
        $relation = trim((string)($_POST['member_relation'][$i] ?? ''));

        $allowedRelations = ['Homeowner','Spouse','Child','Parent','Relative','Tenant','Caretaker'];

        if ($mfname === '' && $mlname === '' && $relation === '') {
          continue;
        }

        if ($mfname === '' || $mlname === '' || !in_array($relation, $allowedRelations, true)) {
          throw new Exception('One or more household members have incomplete or invalid details.');
        }

        $stmtMember->bind_param("issss", $homeowner_id, $mfname, $mmname, $mlname, $relation);
        $stmtMember->execute();
      }

      $stmtMember->close();
    }

    $conn->commit();

    $_SESSION['flash_type'] = 'success';
    $_SESSION['flash_message'] = 'Done Registering.';
    header("Location: ho_approval.php");
    exit;

  } catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}

    $msg = $e->getMessage();

    if (stripos($msg, 'Duplicate entry') !== false && stripos($msg, 'uniq_homeowner_email') !== false) {
      $msg = 'Email already exists.';
    }

    redirect_with_message('danger', $msg);
  }
}

// ---- STEP 1: Registration submit (upload documents, then show map) ----
$showMap = isset($_POST['registration_submit']) && !isset($_POST['submit_location']);

$valid_id_tmp = '';
$proof_tmp = '';

if ($showMap) {
  try {
    $password_raw     = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if ($password_raw !== $confirm_password) {
      redirect_with_message('danger', 'Password and Confirm Password do not match.');
    }

    if (empty($_FILES['valid_id']['tmp_name']) || empty($_FILES['proof_of_billing']['tmp_name'])) {
      redirect_with_message('danger', 'Please upload Valid ID and Proof of Billing.');
    }

    // Save files to project-root /uploads and store DB path as uploads/...
    $uploadDirFs = dirname(__DIR__) . "/uploads/";
    $uploadDirDb = "uploads/";

    if (!is_dir($uploadDirFs) && !mkdir($uploadDirFs, 0755, true)) {
      redirect_with_message('danger', 'Failed to create upload directory.');
    }

    $validName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['valid_id']['name']));
    $proofName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['proof_of_billing']['name']));

    $stamp = time() . '_' . bin2hex(random_bytes(4));

    $validDbPath = $uploadDirDb . $stamp . "_id_" . $validName;
    $proofDbPath = $uploadDirDb . $stamp . "_proof_" . $proofName;

    $validFsPath = $uploadDirFs . $stamp . "_id_" . $validName;
    $proofFsPath = $uploadDirFs . $stamp . "_proof_" . $proofName;

    if (!move_uploaded_file($_FILES['valid_id']['tmp_name'], $validFsPath)) {
      redirect_with_message('danger', 'Failed to upload Valid ID.');
    }

    if (!move_uploaded_file($_FILES['proof_of_billing']['tmp_name'], $proofFsPath)) {
      @unlink($validFsPath);
      redirect_with_message('danger', 'Failed to upload Proof of Billing.');
    }

    $valid_id_tmp = $validDbPath;
    $proof_tmp    = $proofDbPath;

  } catch (Throwable $e) {
    redirect_with_message('danger', $e->getMessage());
  }
}
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title>HOA-ADMIN</title>

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
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

	<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
	<script async src="https://www.googletagmanager.com/gtag/js?id=UA-119386393-1"></script>

	<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css">
	<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

	<style>
		:root{--brand:#077f46;}
		.card-box{border-radius:14px}
		#map{height:520px;width:100%}
		.page-title-wrap{display:flex;align-items:center;justify-content:center;text-align:center;margin-bottom:14px}
		.page-title-wrap .subtitle{font-size:14px}
		.step-pill{display:inline-flex;gap:8px;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:800;font-size:12px}

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
			<div class="user-info-dropdown">
				<div class="dropdown">
					<a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
						<span class="user-icon">
							<img src="vendors/images/photo1.jpg" alt="">
						</span>
					</a>
					<div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
						<a class="dropdown-item" href="logout.php"><i class="dw dw-logout"></i> Log Out</a>
					</div>
				</div>
			</div>
		</div>
	</div>

	<?php include 'sidebar.php'; ?>
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

				<?php if (!empty($_SESSION['flash_message'])): ?>
					<div class="alert alert-<?= esc($_SESSION['flash_type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
						<?= esc($_SESSION['flash_message']) ?>
						<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
					</div>
					<?php unset($_SESSION['flash_type'], $_SESSION['flash_message']); ?>
				<?php endif; ?>

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
									<option value="" disabled selected>Select Phase</option>
									<option value="Phase 1">Phase 1</option>
									<option value="Phase 2">Phase 2</option>
									<option value="Phase 3">Phase 3</option>
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
											<option value="" disabled selected>Relation</option>
											<option value="Homeowner">Homeowner</option>
											<option value="Spouse">Spouse</option>
											<option value="Child">Child</option>
											<option value="Parent">Parent</option>
											<option value="Relative">Relative</option>
											<option value="Tenant">Tenant</option>
											<option value="Caretaker">Caretaker</option>
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
						$skipHiddenFields = ['registration_submit'];
						foreach ($_POST as $key => $value) {
							if (in_array($key, $skipHiddenFields, true)) {
								continue;
							}

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

							marker.on('dragend', function(e){
								setHidden(e.target.getLatLng());
							});

							map.on('click', function(e){
								marker.setLatLng(e.latlng);
								setHidden(e.latlng);
							});

							setHidden(center);
							setTimeout(function(){ map.invalidateSize(); }, 250);
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
			member.querySelectorAll('select').forEach(select => select.selectedIndex = 0);
			members.appendChild(member);
		}
	</script>

	<div id="accessToast" class="access-toast">
	  🚫 You do not have access to that part.
	</div>

	<script>
	window.userPermissions = <?= json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

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