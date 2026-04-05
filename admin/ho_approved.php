<?php
session_start();
require_once 'admin_access.php';
requireAccess('homeowner_management');

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
  exit();
}

$host="localhost";
$db="u972459197_south_meridian";
$user="u972459197_patrick";
$pass="Idle2440";

$conn = new mysqli($host,$user,$pass,$db);
if ($conn->connect_error) die("Connection failed: ".$conn->connect_error);
$conn->set_charset("utf8mb4");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// prevent undefined $view in sidebar
$view = $_GET['view'] ?? '';

function phase_prefix(string $phase): string {
  $n = (int) filter_var($phase, FILTER_SANITIZE_NUMBER_INT);
  return $n > 0 ? ('P'.$n) : 'P';
}

function file_ext(string $path): string {
  return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

function is_image_file(string $path): bool {
  return in_array(file_ext($path), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
}

function is_pdf_file(string $path): bool {
  return file_ext($path) === 'pdf';
}

function document_url(string $path): string {
  $path = trim($path);
  if ($path === '') return '';

  $path = str_replace('\\', '/', $path);

  if (preg_match('~^https?://~i', $path)) {
    return esc($path);
  }

  if (strpos($path, 'uploads/') === 0) {
    return esc('../' . $path);
  }

  return esc($path);
}

if (empty($_SESSION['csrf_delete_homeowner'])) {
  $_SESSION['csrf_delete_homeowner'] = bin2hex(random_bytes(32));
}
$csrfDelete = $_SESSION['csrf_delete_homeowner'];

$admin_id = (int)$_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT phase, role FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

$admin_phase = $admin['phase'] ?? '';
$admin_role  = $admin['role'] ?? '';

if (!isset($permissions) || !is_array($permissions)) {
  $permissions = [];
}

/* =========================
   AJAX: VIEW HOMEOWNER PROFILE
   ========================= */
if (($_GET['ajax'] ?? '') === 'homeowner_profile') {
  $id = (int)($_GET['id'] ?? 0);

  if ($id <= 0) {
    http_response_code(400);
    echo '<div class="p-4"><div class="alert alert-danger mb-0">Invalid homeowner ID.</div></div>';
    exit;
  }

  if ($admin_role === 'superadmin') {
    $stmt = $conn->prepare("
      SELECT *
      FROM homeowners
      WHERE id=?
      LIMIT 1
    ");
    $stmt->bind_param("i", $id);
  } else {
    $stmt = $conn->prepare("
      SELECT *
      FROM homeowners
      WHERE id=? AND phase=?
      LIMIT 1
    ");
    $stmt->bind_param("is", $id, $admin_phase);
  }

  $stmt->execute();
  $homeowner = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$homeowner) {
    http_response_code(404);
    echo '<div class="p-4"><div class="alert alert-danger mb-0">Homeowner not found or access denied.</div></div>';
    exit;
  }

  $memberStmt = $conn->prepare("
    SELECT id, first_name, middle_name, last_name, relation
    FROM household_members
    WHERE homeowner_id = ?
    ORDER BY id ASC
  ");
  $memberStmt->bind_param("i", $id);
  $memberStmt->execute();
  $memberResult = $memberStmt->get_result();
  $householdMembers = [];
  while ($member = $memberResult->fetch_assoc()) {
    $householdMembers[] = $member;
  }
  $memberStmt->close();

  $tenantStmt = $conn->prepare("
    SELECT id, first_name, middle_name, last_name, email, contact_number,
           house_lot_number, lease_start, lease_end, status, registered_at
    FROM tenants
    WHERE homeowner_id = ?
    ORDER BY registered_at DESC, id DESC
  ");
  $tenantStmt->bind_param("i", $id);
  $tenantStmt->execute();
  $tenantResult = $tenantStmt->get_result();
  $tenants = [];
  while ($tenant = $tenantResult->fetch_assoc()) {
    $tenants[] = $tenant;
  }
  $tenantStmt->close();

  $fullName = trim(
    ($homeowner['first_name'] ?? '') . ' ' .
    ($homeowner['middle_name'] ?? '') . ' ' .
    ($homeowner['last_name'] ?? '')
  );

  $displayId = trim((string)($homeowner['public_id'] ?? ''));
  if ($displayId === '') {
    $prefix = phase_prefix((string)($homeowner['phase'] ?? ''));
    $displayId = $prefix . (int)$homeowner['id'];
  }

  $fullAddress = trim(implode(', ', array_filter([
    $homeowner['phase'] ?? '',
    $homeowner['house_lot_number'] ?? ''
  ], fn($v) => trim((string)$v) !== '')));

  $createdAt = !empty($homeowner['created_at']) ? date('F d, Y h:i A', strtotime($homeowner['created_at'])) : '-';
  $lat = $homeowner['latitude'] ?? '';
  $lng = $homeowner['longitude'] ?? '';
  ?>
  <div class="container-fluid p-4">
    <div class="row g-4">
      <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
          <div class="card-body text-center">
            <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                 style="width:90px;height:90px;background:#077f46;color:#fff;font-size:32px;font-weight:700;">
              <?= esc(strtoupper(substr((string)($homeowner['first_name'] ?? 'H'), 0, 1))) ?>
            </div>
            <h4 class="mb-1"><?= esc($fullName) ?></h4>
            <div class="text-muted mb-2"><?= esc($displayId) ?></div>
            <span class="badge bg-success"><?= esc(ucfirst((string)($homeowner['status'] ?? 'approved'))) ?></span>

            <hr>

            <div class="text-start small">
              <div class="mb-2"><strong>Phase:</strong> <?= esc($homeowner['phase'] ?? '-') ?></div>
              <div class="mb-2"><strong>House/Lot:</strong> <?= esc($homeowner['house_lot_number'] ?? '-') ?></div>
              <div class="mb-2"><strong>Email:</strong> <?= esc($homeowner['email'] ?? '-') ?></div>
              <div class="mb-2"><strong>Contact:</strong> <?= esc($homeowner['contact_number'] ?? '-') ?></div>
              <div class="mb-2"><strong>Registered:</strong> <?= esc($createdAt) ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="card shadow-sm border-0 mb-4">
          <div class="card-header bg-white">
            <h6 class="mb-0 fw-bold">Homeowner Details</h6>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label text-muted small mb-1">First Name</label>
                <div class="fw-semibold"><?= esc($homeowner['first_name'] ?? '-') ?></div>
              </div>
              <div class="col-md-4">
                <label class="form-label text-muted small mb-1">Middle Name</label>
                <div class="fw-semibold"><?= esc($homeowner['middle_name'] ?? '-') ?></div>
              </div>
              <div class="col-md-4">
                <label class="form-label text-muted small mb-1">Last Name</label>
                <div class="fw-semibold"><?= esc($homeowner['last_name'] ?? '-') ?></div>
              </div>

              <div class="col-md-6">
                <label class="form-label text-muted small mb-1">Email</label>
                <div class="fw-semibold"><?= esc($homeowner['email'] ?? '-') ?></div>
              </div>
              <div class="col-md-6">
                <label class="form-label text-muted small mb-1">Contact Number</label>
                <div class="fw-semibold"><?= esc($homeowner['contact_number'] ?? '-') ?></div>
              </div>

              <div class="col-md-12">
                <label class="form-label text-muted small mb-1">Address</label>
                <div class="fw-semibold"><?= esc($fullAddress !== '' ? $fullAddress : '-') ?></div>
              </div>

              <?php if (!empty($homeowner['barangay']) || !empty($homeowner['city_municipality']) || !empty($homeowner['province']) || !empty($homeowner['region']) || !empty($homeowner['zip_code']) || !empty($homeowner['country']) || !empty($homeowner['other_location_info']) || !empty($homeowner['exact_location'])): ?>
              <div class="col-md-12">
                <label class="form-label text-muted small mb-1">Complete Address</label>
                <div class="fw-semibold">
                  <?= esc(trim(implode(', ', array_filter([
                    $homeowner['house_lot_number'] ?? '',
                    $homeowner['barangay'] ?? '',
                    $homeowner['city_municipality'] ?? '',
                    $homeowner['province'] ?? '',
                    $homeowner['region'] ?? '',
                    $homeowner['zip_code'] ?? '',
                    $homeowner['country'] ?? '',
                    $homeowner['other_location_info'] ?? '',
                    $homeowner['exact_location'] ?? ''
                  ], fn($v) => trim((string)$v) !== '')))) ?>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
          <div class="card-header bg-white">
            <h6 class="mb-0 fw-bold">Household Members</h6>
          </div>
          <div class="card-body">
            <?php if (!empty($householdMembers)): ?>
              <div class="table-responsive">
                <table class="table table-bordered table-striped align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="width:70px;">#</th>
                      <th>Name</th>
                      <th style="width:180px;">Relation</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($householdMembers as $index => $member): ?>
                      <?php
                        $memberName = trim(
                          ($member['first_name'] ?? '') . ' ' .
                          ($member['middle_name'] ?? '') . ' ' .
                          ($member['last_name'] ?? '')
                        );
                      ?>
                      <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= esc($memberName) ?></td>
                        <td><?= esc($member['relation'] ?? '-') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="text-muted">No household members found.</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
          <div class="card-header bg-white">
            <h6 class="mb-0 fw-bold">Registered Tenants</h6>
            <small class="text-muted">List of tenants registered by this homeowner</small>
          </div>
          <div class="card-body">
            <?php if (!empty($tenants)): ?>
              <div class="table-responsive">
                <table class="table table-bordered table-striped align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="width:70px;">#</th>
                      <th>Name</th>
                      <th>Email</th>
                      <th>Contact No.</th>
                      <th>House/Lot</th>
                      <th>Lease Period</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($tenants as $index => $tenant): ?>
                      <?php
                        $tenantName = trim(
                          ($tenant['first_name'] ?? '') . ' ' .
                          ($tenant['middle_name'] ?? '') . ' ' .
                          ($tenant['last_name'] ?? '')
                        );
                        $leaseStart = !empty($tenant['lease_start']) ? date('M d, Y', strtotime($tenant['lease_start'])) : '—';
                        $leaseEnd   = !empty($tenant['lease_end']) ? date('M d, Y', strtotime($tenant['lease_end'])) : '—';
                        $tenantStatus = ucfirst((string)($tenant['status'] ?? 'inactive'));
                        $statusClass = strtolower((string)($tenant['status'] ?? 'inactive')) === 'active' ? 'success' : 'secondary';
                      ?>
                      <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= esc($tenantName) ?></td>
                        <td><?= esc($tenant['email'] ?? '-') ?></td>
                        <td><?= esc($tenant['contact_number'] ?? '-') ?></td>
                        <td><?= esc($tenant['house_lot_number'] ?? '-') ?></td>
                        <td><?= esc($leaseStart . ' to ' . $leaseEnd) ?></td>
                        <td><span class="badge bg-<?= $statusClass ?>"><?= esc($tenantStatus) ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="text-muted">No registered tenants found for this homeowner.</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
          <div class="card-header bg-white">
            <h6 class="mb-0 fw-bold">Map Location</h6>
          </div>
          <div class="card-body">
            <?php if ($lat !== '' && $lng !== ''): ?>
              <div id="coverMap"
                   data-lat="<?= esc($lat) ?>"
                   data-lng="<?= esc($lng) ?>"
                   style="height: 360px; border-radius: 12px; overflow: hidden;"></div>
            <?php else: ?>
              <div class="text-muted">No map location available.</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card shadow-sm border-0">
          <div class="card-header bg-white">
            <h6 class="mb-0 fw-bold">Uploaded Documents</h6>
          </div>
          <div class="card-body">
            <div class="row g-4">
              <div class="col-md-6">
                <label class="form-label text-muted small mb-2">Valid ID</label>
                <?php if (!empty($homeowner['valid_id_path'])): ?>
                  <?php $validIdPath = document_url($homeowner['valid_id_path']); ?>
                  <div class="border rounded-3 overflow-hidden bg-light">
                    <div class="p-2 text-center" style="min-height:220px; display:flex; align-items:center; justify-content:center; background:#f8f9fa;">
                      <?php if (is_image_file($homeowner['valid_id_path'])): ?>
                        <img src="<?= $validIdPath ?>" alt="Valid ID"
                             style="max-width:100%; max-height:210px; object-fit:contain; cursor:pointer;"
                             onclick="window.open('<?= $validIdPath ?>','_blank')">
                      <?php elseif (is_pdf_file($homeowner['valid_id_path'])): ?>
                        <iframe src="<?= $validIdPath ?>" style="width:100%; height:210px; border:0;"></iframe>
                      <?php else: ?>
                        <div class="text-muted">Preview not available for this file type.</div>
                      <?php endif; ?>
                    </div>
                    <div class="p-2 border-top bg-white text-center">
                      <a href="<?= $validIdPath ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                        Open Valid ID
                      </a>
                    </div>
                  </div>
                <?php else: ?>
                  <div class="text-muted">No file uploaded.</div>
                <?php endif; ?>
              </div>

              <div class="col-md-6">
                <label class="form-label text-muted small mb-2">Proof of Billing</label>
                <?php if (!empty($homeowner['proof_of_billing_path'])): ?>
                  <?php $proofPath = document_url($homeowner['proof_of_billing_path']); ?>
                  <div class="border rounded-3 overflow-hidden bg-light">
                    <div class="p-2 text-center" style="min-height:220px; display:flex; align-items:center; justify-content:center; background:#f8f9fa;">
                      <?php if (is_image_file($homeowner['proof_of_billing_path'])): ?>
                        <img src="<?= $proofPath ?>" alt="Proof of Billing"
                             style="max-width:100%; max-height:210px; object-fit:contain; cursor:pointer;"
                             onclick="window.open('<?= $proofPath ?>','_blank')">
                      <?php elseif (is_pdf_file($homeowner['proof_of_billing_path'])): ?>
                        <iframe src="<?= $proofPath ?>" style="width:100%; height:210px; border:0;"></iframe>
                      <?php else: ?>
                        <div class="text-muted">Preview not available for this file type.</div>
                      <?php endif; ?>
                    </div>
                    <div class="p-2 border-top bg-white text-center">
                      <a href="<?= $proofPath ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                        Open Proof of Billing
                      </a>
                    </div>
                  </div>
                <?php else: ?>
                  <div class="text-muted">No file uploaded.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
  <?php
  exit;
}

/* =========================
   DELETE HOMEOWNER ACTION
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_homeowner') {
  header('Content-Type: application/json; charset=utf-8');

  $token = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals($_SESSION['csrf_delete_homeowner'] ?? '', $token)) {
    echo json_encode(['success'=>false, 'message'=>'Invalid request token. Please refresh and try again.']);
    exit;
  }

  $deleteId = (int)($_POST['homeowner_id'] ?? 0);
  if ($deleteId <= 0) {
    echo json_encode(['success'=>false, 'message'=>'Invalid homeowner ID.']);
    exit;
  }

  if ($admin_role === 'superadmin') {
    $stmt = $conn->prepare("
      SELECT id, first_name, middle_name, last_name, phase, house_lot_number, valid_id_path, proof_of_billing_path
      FROM homeowners
      WHERE id=?
      LIMIT 1
    ");
    $stmt->bind_param("i", $deleteId);
  } else {
    $stmt = $conn->prepare("
      SELECT id, first_name, middle_name, last_name, phase, house_lot_number, valid_id_path, proof_of_billing_path
      FROM homeowners
      WHERE id=? AND phase=?
      LIMIT 1
    ");
    $stmt->bind_param("is", $deleteId, $admin_phase);
  }

  $stmt->execute();
  $target = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$target) {
    echo json_encode(['success'=>false, 'message'=>'Homeowner not found or not allowed for your account.']);
    exit;
  }

  $fullName = trim(
    (string)($target['first_name'] ?? '') . ' ' .
    (string)($target['middle_name'] ?? '') . ' ' .
    (string)($target['last_name'] ?? '')
  );

  try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("DELETE FROM household_members WHERE homeowner_id=?");
    $stmt->bind_param("i", $deleteId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM homeowners WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $deleteId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected < 1) {
      throw new Exception('Homeowner was not deleted.');
    }

    $conn->commit();

    $rootPath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    foreach (['valid_id_path', 'proof_of_billing_path'] as $fileField) {
      $dbPath = trim((string)($target[$fileField] ?? ''));
      if ($dbPath !== '' && strpos($dbPath, 'uploads/') === 0) {
        $fullPath = $rootPath . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dbPath);
        if (is_file($fullPath)) {
          @unlink($fullPath);
        }
      }
    }

    echo json_encode([
      'success' => true,
      'message' => 'Homeowner deleted successfully: ' . ($fullName !== '' ? $fullName : ('ID '.$deleteId))
    ]);
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    echo json_encode([
      'success' => false,
      'message' => 'Delete failed. ' . $e->getMessage()
    ]);
    exit;
  }
}

if ($admin_role === 'superadmin') {
  $sqlApproved = $conn->prepare("SELECT * FROM homeowners WHERE status='approved' ORDER BY created_at DESC");
} else {
  $sqlApproved = $conn->prepare("SELECT * FROM homeowners WHERE status='approved' AND phase=? ORDER BY created_at DESC");
  $sqlApproved->bind_param("s", $admin_phase);
}
$sqlApproved->execute();
$resultApproved = $sqlApproved->get_result();
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
		.badge{padding:.25em .55em;border-radius:.45rem;color:#fff;font-size:.82rem;font-weight:700}
		.badge-success{background:#22c55e}
		.page-title-wrap{display:flex;align-items:center;justify-content:center;text-align:center;margin-bottom:14px}
		.page-title-wrap .subtitle{font-size:14px}
		.card-box{border-radius:14px}
		.btn-action-wrap{display:flex;gap:6px;flex-wrap:wrap;}
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
								<li>
									<a href="#">
										<img src="vendors/images/img.jpg" alt="">
										<h3>System</h3>
										<p>Notifications appear here.</p>
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

	<?php include 'sidebar.php'; ?>

	<div class="mobile-menu-overlay"></div>

	<div class="main-container">
		<div class="pd-ltr-20">

			<div class="page-title-wrap">
				<div>
					<h2 class="h4 mb-1">Home Owner Management</h2>
					<div class="text-muted fw-semibold subtitle">Approved Households</div>
				</div>
			</div>

			<div class="card-box p-3">
				<div class="table-responsive">
					<table id="approvedTable" class="display table table-striped table-bordered nowrap" style="width:100%">
						<thead>
							<tr>
								<th>ID</th>
								<th>Name</th>
								<th>Address</th>
								<th>Status</th>
								<th style="width:170px;">Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php while($row = $resultApproved->fetch_assoc()): ?>
								<?php
									$rowPhase = (string)($row['phase'] ?? $admin_phase);
									$displayId = trim((string)($row['public_id'] ?? ''));
									if ($displayId === '') {
										$prefix = phase_prefix($rowPhase);
										$displayId = $prefix . (int)$row['id'];
									}
									$rowName = trim(($row['first_name'] ?? '').' '.($row['middle_name'] ?? '').' '.($row['last_name'] ?? ''));
									$rowAddress = trim(($row['phase'] ?? '').', '.($row['house_lot_number'] ?? ''));
								?>
								<tr id="homeownerRow<?= (int)$row['id'] ?>">
									<td><span class="badge badge-success"><?= esc($displayId) ?></span></td>
									<td><?= esc($rowName) ?></td>
									<td><?= esc($rowAddress) ?></td>
									<td><span class="badge badge-success">Approved</span></td>
									<td>
                    					<div class="btn-action-wrap">
                      						<button type="button" class="btn btn-sm btn-info viewHomeownerBtn" data-id="<?= (int)$row['id'] ?>" title="View">
                        						<i class="dw dw-eye"></i>
                      						</button>
                      						<button class="btn btn-sm btn-warning editHomeowner" data-id="<?= (int)$row['id'] ?>" title="Edit">
                        						<i class="dw dw-edit-1"></i>
                      						</button>
                      						<button
                        						type="button"
                        						class="btn btn-sm btn-danger deleteHomeownerBtn"
                        						data-id="<?= (int)$row['id'] ?>"
                        						data-name="<?= esc($rowName) ?>"
                        						data-address="<?= esc($rowAddress) ?>"
                        						title="Delete"
                      						>
                        						<i class="dw dw-delete-3"></i>
                      						</button>
                    					</div>
									</td>
								</tr>
							<?php endwhile; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="footer-wrap pd-20 mb-20 card-box">
				© Copyright South Meridian Homes All Rights Reserved
			</div>
		</div>
	</div>

	<div class="modal fade" id="viewHomeownerModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-xl modal-dialog-scrollable">
			<div class="modal-content" style="border-radius: 14px; overflow: hidden;">
				<div class="modal-header">
					<h5 class="modal-title fw-bold">Homeowner Profile</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<div class="modal-body p-0 position-relative" style="background:#f4f6fb; min-height: 80vh;">
					<div id="viewHomeownerContent"></div>
				</div>
			</div>
		</div>
	</div>

	<div class="modal fade" id="editHomeownerModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-xl modal-dialog-scrollable">
			<div class="modal-content" style="border-radius:14px; overflow:hidden;">
				<div class="modal-header">
					<h5 class="modal-title fw-bold">Edit Homeowner</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>

				<div class="modal-body" style="background:#f4f6fb;">
					<div id="editHomeownerContent" class="p-2"></div>
				</div>

				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
					<button type="button" class="btn btn-success" id="saveEditHomeownerBtn">Save Changes</button>
				</div>
			</div>
		</div>
	</div>
	
	<div class="modal fade" id="deleteHomeownerModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog">
			<div class="modal-content" style="border-radius:14px; overflow:hidden;">
				<div class="modal-header bg-danger text-white">
					<h5 class="modal-title fw-bold">Delete Homeowner</h5>
					<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
				</div>
				<div class="modal-body">
					<div class="alert alert-warning mb-3">
						This action will permanently delete the homeowner record.
					</div>

					<div class="mb-2"><b>Name:</b> <span id="deleteHomeownerName">-</span></div>
					<div class="mb-2"><b>Address:</b> <span id="deleteHomeownerAddress">-</span></div>

					<input type="hidden" id="deleteHomeownerId" value="">
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
					<button type="button" class="btn btn-danger" id="confirmDeleteHomeownerBtn">
						Delete Permanently
					</button>
				</div>
			</div>
		</div>
	</div>

	<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 2000;">
		<div id="appToast" class="toast align-items-center" role="alert" aria-live="assertive" aria-atomic="true">
			<div class="d-flex">
				<div class="toast-body" id="appToastMsg">...</div>
				<button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
			</div>
		</div>
	</div>

	<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

	<script src="vendors/scripts/core.js"></script>
	<script src="vendors/scripts/script.min.js"></script>
	<script src="vendors/scripts/process.js"></script>
	<script src="vendors/scripts/layout-settings.js"></script>

	<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
	<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
	<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
	<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

	<script>
    const DELETE_CSRF = <?= json_encode($csrfDelete) ?>;

		function showToast(message, type='success'){
			const toastEl = document.getElementById('appToast');
			const msgEl   = document.getElementById('appToastMsg');
			msgEl.textContent = message;

			toastEl.classList.remove('text-bg-success','text-bg-danger');
			toastEl.classList.add(type==='success' ? 'text-bg-success' : 'text-bg-danger');

			bootstrap.Toast.getOrCreateInstance(toastEl,{delay:2800}).show();
		}

		$(function(){
      		let approvedDt = null;

			if ($.fn.DataTable && $('#approvedTable').length && !$.fn.DataTable.isDataTable('#approvedTable')) {
				approvedDt = $('#approvedTable').DataTable({
          			responsive:true,
          			columnDefs:[{orderable:false, targets:4}]
        		});
			} else if ($.fn.DataTable.isDataTable('#approvedTable')) {
        		approvedDt = $('#approvedTable').DataTable();
      		}

			const modalEl = document.getElementById('viewHomeownerModal');
			const content = document.getElementById('viewHomeownerContent');
			const modal = new bootstrap.Modal(modalEl, { backdrop:'static', keyboard:true });

			let coverMapInstance = null;

			function destroyCoverMap() {
				if (coverMapInstance) {
					coverMapInstance.remove();
					coverMapInstance = null;
				}
			}

			function initCoverMapIfAny() {
				const mapEl = document.getElementById('coverMap');
				if (!mapEl || typeof L === 'undefined') return;

				const lat = parseFloat(mapEl.getAttribute('data-lat') || '');
				const lng = parseFloat(mapEl.getAttribute('data-lng') || '');
				if (!isFinite(lat) || !isFinite(lng)) return;

				destroyCoverMap();

				coverMapInstance = L.map(mapEl, {
					center: [lat, lng],
					zoom: 18,
					zoomControl: true,
					attributionControl: true
				});

				L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
					maxZoom: 19,
					subdomains: ['a', 'b', 'c'],
					attribution: '&copy; OpenStreetMap contributors'
				}).addTo(coverMapInstance);

				L.marker([lat, lng]).addTo(coverMapInstance);

				setTimeout(function () {
					if (coverMapInstance) {
						coverMapInstance.invalidateSize(true);
						coverMapInstance.setView([lat, lng], 18);
					}
				}, 300);

				setTimeout(function () {
					if (coverMapInstance) {
						coverMapInstance.invalidateSize(true);
					}
				}, 800);
			}

			$(document).on('click','.viewHomeownerBtn', function(e){
				e.preventDefault();
				const id = $(this).data('id');
				if (!id) return;

				content.innerHTML = `<div class="p-4 text-muted fw-semibold">Loading...</div>`;
				modal.show();

				$.get('ho_approved.php', { ajax:'homeowner_profile', id:id, _:Date.now() })
					.done(function(html){ content.innerHTML = html; initCoverMapIfAny(); })
					.fail(function(xhr){
						content.innerHTML = `<div class="p-4"><div class="alert alert-danger mb-0">Failed to load profile. HTTP ${xhr.status}</div></div>`;
					});
			});

			modalEl.addEventListener('hidden.bs.modal', function(){
				if (coverMapInstance) { coverMapInstance.remove(); coverMapInstance = null; }
				content.innerHTML = '';
			});

			const editModalEl = document.getElementById('editHomeownerModal');
			const editContent = document.getElementById('editHomeownerContent');
			const editModal = new bootstrap.Modal(editModalEl, { backdrop:'static', keyboard:true });

			let editMapInstance = null;
			let editMarker = null;
			let pendingInit = false;

			function destroyEditMap(){
				if (editMapInstance) {
					editMapInstance.remove();
					editMapInstance = null;
					editMarker = null;
				}
			}

			function initEditMap(){
				const mapEl = document.getElementById('editMap');
				if (!mapEl) return;

				let lat = parseFloat(mapEl.dataset.lat || '');
				let lng = parseFloat(mapEl.dataset.lng || '');
				if (!isFinite(lat) || !isFinite(lng)) { lat = 14.5995; lng = 120.9842; }

				destroyEditMap();

				editMapInstance = L.map(mapEl, { zoomControl:true }).setView([lat, lng], 18);
				L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
					attribution: '&copy; OpenStreetMap contributors'
				}).addTo(editMapInstance);

				editMarker = L.marker([lat, lng], { draggable:true }).addTo(editMapInstance);

				function syncInputs(p){
					$('#edit_lat').val(p.lat.toFixed(6));
					$('#edit_lng').val(p.lng.toFixed(6));
				}

				syncInputs({lat, lng});
				editMarker.on('dragend', function(){ syncInputs(editMarker.getLatLng()); });

				$(document).off('click', '#btnCenterMarker').on('click', '#btnCenterMarker', function(){
					if (!editMapInstance || !editMarker) return;
					editMapInstance.setView(editMarker.getLatLng(), editMapInstance.getZoom());
				});

				$(document).off('click', '#btnUseCurrentMarker').on('click', '#btnUseCurrentMarker', function(){
					if (!editMarker) return;
					syncInputs(editMarker.getLatLng());
				});

				setTimeout(() => { if (editMapInstance) editMapInstance.invalidateSize(true); }, 250);
			}

			$(document).on('click','.editHomeowner', function(e){
				e.preventDefault();
				const id = $(this).data('id');
				if (!id) return;

				pendingInit = true;
				editContent.innerHTML = `<div class="p-3 text-muted fw-semibold">Loading edit form...</div>`;
				editModal.show();

				$.get('edit_homeowner_modal.php', { ajax:'edit_homeowner', id:id, _:Date.now() })
					.done(function(html){
						editContent.innerHTML = html;
						if (editModalEl.classList.contains('show')) {
							initEditMap();
							pendingInit = false;
						}
					})
					.fail(function(xhr){
						pendingInit = false;
						editContent.innerHTML = `<div class="alert alert-danger">Failed to load. HTTP ${xhr.status}</div>`;
					});
			});

			editModalEl.addEventListener('shown.bs.modal', function(){
				if (pendingInit) {
					initEditMap();
					pendingInit = false;
				}
			});

			editModalEl.addEventListener('hidden.bs.modal', function(){
				destroyEditMap();
				editContent.innerHTML = '';
				pendingInit = false;
			});

			document.addEventListener('click', async function(e){
				if (!e.target.closest('#saveEditHomeownerBtn')) return;

				const btn = e.target.closest('#saveEditHomeownerBtn');
				const form = document.getElementById('editHomeownerForm');
				if (!form) { showToast("Edit form not found.", "error"); return; }

				const fd = new FormData(form);

				btn.disabled = true;
				const oldHTML = btn.innerHTML;
				btn.innerHTML = 'Saving...';

				try {
					const resp = await fetch('edit_homeowner_modal.php', { method: 'POST', body: fd });
					const text = await resp.text();

					let data;
					try { data = JSON.parse(text); }
					catch(err){
						console.error("Not JSON response:", text);
						showToast("Save failed. Server returned non-JSON.", "error");
						return;
					}

					if (!data.success) { showToast(data.message || "Update failed.", "error"); return; }

					showToast(data.message || "Updated!", "success");
					editModal.hide();
					setTimeout(()=>location.reload(), 400);

				} catch (err) {
					console.error(err);
					showToast("Request failed.", "error");
				} finally {
					btn.disabled = false;
					btn.innerHTML = oldHTML;
				}
			});

      		const deleteModalEl = document.getElementById('deleteHomeownerModal');
      		const deleteModal = new bootstrap.Modal(deleteModalEl, { backdrop:'static', keyboard:true });
      		const deleteIdEl = document.getElementById('deleteHomeownerId');
      		const deleteNameEl = document.getElementById('deleteHomeownerName');
      		const deleteAddressEl = document.getElementById('deleteHomeownerAddress');
      		const confirmDeleteBtn = document.getElementById('confirmDeleteHomeownerBtn');

      		$(document).on('click', '.deleteHomeownerBtn', function(e){
        		e.preventDefault();

        		const id = $(this).data('id');
        		const name = $(this).data('name') || '-';
        		const address = $(this).data('address') || '-';

        		deleteIdEl.value = id || '';
        		deleteNameEl.textContent = name;
        		deleteAddressEl.textContent = address;

        		deleteModal.show();
      		});

      		confirmDeleteBtn?.addEventListener('click', async function(){
        		const homeownerId = parseInt(deleteIdEl.value || '0', 10);
        		if (!homeownerId) {
          			showToast('Invalid homeowner selected.', 'error');
          			return;
        		}

        		const oldHtml = confirmDeleteBtn.innerHTML;
        		confirmDeleteBtn.disabled = true;
        		confirmDeleteBtn.innerHTML = 'Deleting...';

        		try {
          			const fd = new FormData();
          			fd.append('action', 'delete_homeowner');
          			fd.append('homeowner_id', homeownerId);
          			fd.append('csrf_token', DELETE_CSRF);

          			const resp = await fetch(window.location.href, {
            			method: 'POST',
            			body: fd
          			});

          			const text = await resp.text();

          			let data;
          			try {
            			data = JSON.parse(text);
          			} catch (err) {
            			console.error('Non-JSON delete response:', text);
            			showToast('Delete failed. Server returned invalid response.', 'error');
            			return;
          			}

          			if (!data.success) {
            			showToast(data.message || 'Delete failed.', 'error');
            			return;
          			}

          			showToast(data.message || 'Homeowner deleted.', 'success');
          			deleteModal.hide();

          			const rowNode = document.getElementById('homeownerRow' + homeownerId);
          			if (rowNode) {
            			if (approvedDt) {
              				approvedDt.row($(rowNode)).remove().draw(false);
            			} else {
              				rowNode.remove();
            			}
          			} else {
            			setTimeout(() => location.reload(), 350);
          			}

        		} catch (err) {
          			console.error(err);
          			showToast('Request failed while deleting homeowner.', 'error');
        		} finally {
          			confirmDeleteBtn.disabled = false;
          			confirmDeleteBtn.innerHTML = oldHtml;
        		}
      		});

      		deleteModalEl?.addEventListener('hidden.bs.modal', function(){
        		deleteIdEl.value = '';
        		deleteNameEl.textContent = '-';
        		deleteAddressEl.textContent = '-';
      		});
		});
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