<?php
session_start();
require_once 'admin_access.php';
require_once '../config/database.php';
requireAccess('homeowner_management');

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
  exit();
}


if (!function_exists('esc')) {
  function esc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

if (empty($_SESSION['homeowner_import_csrf'])) {
  $_SESSION['homeowner_import_csrf'] = bin2hex(random_bytes(32));
}
$homeownerImportCsrf = $_SESSION['homeowner_import_csrf'];

function phase_prefix(string $phase): string {
  $n = (int) filter_var($phase, FILTER_SANITIZE_NUMBER_INT);
  return $n > 0 ? ('P'.$n) : 'P';
}

/**
 * Read Block and Lot from the new database columns when available, while
 * remaining compatible with existing records saved as "Block 1 Lot 2" in
 * house_lot_number.
 */
function subdivision_block_lot(array $record): array {
  $block = (int)($record['block'] ?? 0);
  $lot = (int)($record['lot'] ?? 0);

  if ($block <= 0 || $lot <= 0) {
    $legacy = trim((string)($record['house_lot_number'] ?? ''));
    if (preg_match('/(?:block|b)\s*[-:]?\s*(\d+)\D+(?:lot|l)\s*[-:]?\s*(\d+)/i', $legacy, $match)) {
      $block = (int)$match[1];
      $lot = (int)$match[2];
    }
  }

  return [$block, $lot];
}

/**
 * Load the exact 335-house Block/Lot positions from the corrected map.
 * The Word map uses EMU coordinates on a US Letter page. They are converted
 * here to pixel positions on the supplied 2550 x 3300 map image.
 */
function south_meridian_locations(): array {
  static $locations = null;
  if ($locations !== null) {
    return $locations;
  }

  $locations = [];
  $mappingPath = __DIR__ . '/southmeri_block_lot_mapping.json';
  if (!is_readable($mappingPath)) {
    return $locations;
  }

  $decoded = json_decode((string)file_get_contents($mappingPath), true);
  if (!is_array($decoded)) {
    return $locations;
  }

  $pageWidthEmu = 8.5 * 914400;
  $pageHeightEmu = 11 * 914400;
  $pageMarginEmu = 914400;
  $markerCenterOffsetEmu = 90000;

  foreach ($decoded as $row) {
    $block = (int)($row['block'] ?? 0);
    $lot = (int)($row['lot'] ?? 0);
    $xEmu = (float)($row['x_emu'] ?? -1);
    $yEmu = (float)($row['y_emu'] ?? -1);

    if ($block < 1 || $lot < 1 || $xEmu < -$pageMarginEmu || $yEmu < -$pageMarginEmu) {
      continue;
    }

    $locations[$block . ':' . $lot] = [
      'block' => $block,
      'lot' => $lot,
      'street' => trim((string)($row['street'] ?? '')),
      'x' => round((($pageMarginEmu + $xEmu + $markerCenterOffsetEmu) / $pageWidthEmu) * 2550, 2),
      'y' => round((($pageMarginEmu + $yEmu + $markerCenterOffsetEmu) / $pageHeightEmu) * 3300, 2),
    ];
  }

  return $locations;
}

$southMeridianLocations = south_meridian_locations();

// admin info (always read from DB)
$admin_id = (int)$_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT phase, role FROM admins WHERE id=?");
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

// AJAX homeowner profile used by the View button on this page.
if (($_GET['ajax'] ?? '') === 'homeowner_profile') {

    $homeownerId = (int)($_GET['id'] ?? 0);

    if ($homeownerId <= 0) {
        http_response_code(400);
        echo '<div class="p-4"><div class="alert alert-danger mb-0">Invalid homeowner ID.</div></div>';
        exit();
    }

    if ($admin_role === 'superadmin') {

        $profileStmt = $conn->prepare(
            "SELECT *
             FROM homeowners
             WHERE id=?
             LIMIT 1"
        );

        $profileStmt->bind_param(
            'i',
            $homeownerId
        );

    } else {

        $profileStmt = $conn->prepare(
            "SELECT *
             FROM homeowners
             WHERE id=?
               AND phase=?
             LIMIT 1"
        );

        $profileStmt->bind_param(
            'is',
            $homeownerId,
            $admin_phase
        );
    }

    $profileStmt->execute();

    $homeowner =
        $profileStmt
            ->get_result()
            ->fetch_assoc();

    $profileStmt->close();

    if (!$homeowner) {

        http_response_code(404);

        echo '<div class="p-4">
                <div class="alert alert-warning mb-0">
                    Homeowner not found or outside your assigned phase.
                </div>
              </div>';

        exit();
    }

  $memberStmt = $conn->prepare("SELECT first_name, middle_name, last_name, relation FROM household_members WHERE homeowner_id=? ORDER BY id ASC");
  $memberStmt->bind_param('i', $homeownerId);
  $memberStmt->execute();
  $members = $memberStmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $memberStmt->close();

  $fullName = trim(($homeowner['first_name'] ?? '').' '.($homeowner['middle_name'] ?? '').' '.($homeowner['last_name'] ?? ''));

  $displayValue = static function($value, string $fallback = 'Not provided'): string {
    $value = trim((string)($value ?? ''));
    return $value !== '' ? $value : $fallback;
  };

  [$homeownerBlock, $homeownerLot] = subdivision_block_lot($homeowner);
  $mapLocation = $southMeridianLocations[$homeownerBlock . ':' . $homeownerLot] ?? null;
  $blockLotAddress = ($homeownerBlock > 0 && $homeownerLot > 0)
    ? ('Block ' . $homeownerBlock . ', Lot ' . $homeownerLot)
    : '';

  $addressParts = array_filter([
    $blockLotAddress,
    $mapLocation['street'] ?? '',
    $homeowner['other_location_info'] ?? '',
    $homeowner['barangay'] ?? '',
    $homeowner['city_municipality'] ?? '',
    $homeowner['province'] ?? ''
  ], static fn($v) => trim((string)$v) !== '');

  $address = implode(', ', $addressParts);
  $status = (string)($homeowner['status'] ?? 'pending');
  $validId = trim((string)($homeowner['valid_id_path'] ?? ''));
  $billing = trim((string)($homeowner['proof_of_billing_path'] ?? ''));

  $isImportPlaceholder = static function(string $path): bool {
    return $path === '' || str_starts_with($path, 'imports/');
  };
  ?>
  <div class="p-4">
    <div class="row g-4">

      <div class="col-lg-8">

        <div class="card border-0 shadow-sm mb-3">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
              <div>
                <div class="text-muted small fw-semibold text-uppercase mb-1">Homeowner Profile</div>
                <h4 class="mb-1"><?= esc($fullName ?: 'Unnamed Resident') ?></h4>
                <div class="text-muted">
                  <?= esc($homeowner['public_id'] ?: (phase_prefix((string)$homeowner['phase']).$homeowner['id'])) ?>
                  · <?= esc($displayValue($homeowner['phase'] ?? null)) ?>
                </div>
              </div>
              <span class="badge <?= $status === 'approved' ? 'badge-success' : ($status === 'rejected' ? 'badge-danger' : 'badge-warning') ?>">
                <?= esc(ucfirst($status)) ?>
              </span>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
          <div class="card-body p-4">
            <h6 class="fw-bold mb-3">Personal Information</h6>
            <div class="row g-3">
              <div class="col-md-4"><div class="text-muted small">First Name</div><div class="fw-semibold"><?= esc($displayValue($homeowner['first_name'] ?? null)) ?></div></div>
              <div class="col-md-4"><div class="text-muted small">Middle Name</div><div class="fw-semibold"><?= esc($displayValue($homeowner['middle_name'] ?? null)) ?></div></div>
              <div class="col-md-4"><div class="text-muted small">Last Name</div><div class="fw-semibold"><?= esc($displayValue($homeowner['last_name'] ?? null)) ?></div></div>
              <div class="col-md-6"><div class="text-muted small">Contact Number</div><div class="fw-semibold"><?= esc($displayValue($homeowner['contact_number'] ?? null)) ?></div></div>
              <div class="col-md-6"><div class="text-muted small">Email</div><div class="fw-semibold text-break"><?= esc($displayValue($homeowner['email'] ?? null)) ?></div></div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
          <div class="card-body p-4">
            <h6 class="fw-bold mb-3">Address &amp; Residency Information</h6>
            <div class="row g-3">
              <div class="col-md-4"><div class="text-muted small">Phase</div><div class="fw-semibold"><?= esc($displayValue($homeowner['phase'] ?? null)) ?></div></div>
              <div class="col-md-4"><div class="text-muted small">Block</div><div class="fw-semibold"><?= esc($homeownerBlock > 0 ? $homeownerBlock : 'Not provided') ?></div></div>
              <div class="col-md-4"><div class="text-muted small">Lot</div><div class="fw-semibold"><?= esc($homeownerLot > 0 ? $homeownerLot : 'Not provided') ?></div></div>
              <div class="col-md-4"><div class="text-muted small">Street</div><div class="fw-semibold"><?= esc($displayValue($mapLocation['street'] ?? null)) ?></div></div>
              <div class="col-md-4"><div class="text-muted small">Residential Type</div><div class="fw-semibold"><?= esc($displayValue($homeowner['residential_type'] ?? null)) ?></div></div>
              <div class="col-md-4"><div class="text-muted small">Barangay</div><div class="fw-semibold"><?= esc($displayValue($homeowner['barangay'] ?? null)) ?></div></div>
              <div class="col-md-4"><div class="text-muted small">City/Municipality</div><div class="fw-semibold"><?= esc($displayValue($homeowner['city_municipality'] ?? null)) ?></div></div>
              <div class="col-md-4"><div class="text-muted small">Province</div><div class="fw-semibold"><?= esc($displayValue($homeowner['province'] ?? null)) ?></div></div>
              <div class="col-md-6"><div class="text-muted small">Other Location Info</div><div class="fw-semibold"><?= esc($displayValue($homeowner['other_location_info'] ?? null)) ?></div></div>
              <div class="col-md-6"><div class="text-muted small">Length of Residency in the Barangay</div><div class="fw-semibold"><?= esc($displayValue($homeowner['length_of_residency'] ?? null)) ?></div></div>
              <div class="col-12"><div class="text-muted small">Complete Address</div><div class="fw-semibold"><?= esc($address ?: 'Not provided') ?></div></div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
          <div class="card-body p-4">
            <h6 class="fw-bold mb-3">Emergency Contact</h6>
            <div class="row g-3">
              <div class="col-md-6"><div class="text-muted small">Emergency Contact Person</div><div class="fw-semibold"><?= esc($displayValue($homeowner['emergency_contact_person'] ?? null)) ?></div></div>
              <div class="col-md-6"><div class="text-muted small">Emergency Contact Number</div><div class="fw-semibold"><?= esc($displayValue($homeowner['emergency_contact_number'] ?? null)) ?></div></div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
          <div class="card-body p-4">
            <h6 class="fw-bold mb-3">Supporting Documents</h6>
            <div class="row g-3">
              <div class="col-md-6">
                <div class="text-muted small">Valid ID</div>
                <div><?php if (!$isImportPlaceholder($validId)): ?><a href="<?= esc($validId) ?>" target="_blank" class="fw-semibold">Open file</a><?php else: ?><span class="text-muted">Not provided by Excel import</span><?php endif; ?></div>
              </div>
              <div class="col-md-6">
                <div class="text-muted small">Proof of Billing</div>
                <div><?php if (!$isImportPlaceholder($billing)): ?><a href="<?= esc($billing) ?>" target="_blank" class="fw-semibold">Open file</a><?php else: ?><span class="text-muted">Not provided by Excel import</span><?php endif; ?></div>
              </div>
            </div>
          </div>
        </div>

        <div class="card border-0 shadow-sm">
          <div class="card-body p-4">
            <h6 class="fw-bold mb-3">Household Members</h6>
            <?php if (!$members): ?>
              <div class="text-muted">No household members recorded.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead><tr><th>Name</th><th>Relation</th></tr></thead>
                  <tbody>
                    <?php foreach ($members as $m): ?>
                      <tr><td><?= esc(trim($m['first_name'].' '.($m['middle_name'] ?? '').' '.$m['last_name'])) ?></td><td><?= esc($m['relation']) ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>

      <div class="col-lg-4">

        <div class="card border-0 shadow-sm mb-3">
          <div class="card-body p-4">
            <h6 class="fw-bold mb-3">Record Summary</h6>
            <div class="mb-3"><div class="text-muted small">Homeowner ID</div><div class="fw-semibold"><?= esc($homeowner['public_id'] ?: (phase_prefix((string)$homeowner['phase']).$homeowner['id'])) ?></div></div>
            <div class="mb-3"><div class="text-muted small">Status</div><div class="fw-semibold"><?= esc(ucfirst($status)) ?></div></div>
            <div><div class="text-muted small">Residential Type</div><div class="fw-semibold"><?= esc($displayValue($homeowner['residential_type'] ?? null)) ?></div></div>
          </div>
        </div>

        <?php if ($mapLocation): ?>
          <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3">
              <h6 class="fw-bold mb-1">Mapped Location</h6>
              <div class="text-muted small mb-3">
                Block <?= (int)$homeownerBlock ?>, Lot <?= (int)$homeownerLot ?><?= !empty($mapLocation['street']) ? ' · ' . esc($mapLocation['street']) : '' ?>
              </div>
              <div id="coverMap"
                   data-map-x="<?= esc($mapLocation['x']) ?>"
                   data-map-y="<?= esc($mapLocation['y']) ?>"
                   data-block="<?= (int)$homeownerBlock ?>"
                   data-lot="<?= (int)$homeownerLot ?>"
                   data-street="<?= esc($mapLocation['street'] ?? '') ?>"
                   data-map-image="../assets/img/south_meridian_block_lot_map.png"
                   class="rounded"></div>
            </div>
          </div>
        <?php else: ?>
          <div class="alert alert-secondary shadow-sm"><strong>Map location:</strong><br>No matching Block and Lot was found on the finalized subdivision map.</div>
        <?php endif; ?>

        <?php if ($status === 'pending'): ?>
          <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
              <h6 class="fw-bold mb-3">Approval Action</h6>
              <div class="d-grid gap-2">
                <button class="btn btn-success approveHomeowner" data-id="<?= (int)$homeownerId ?>">Approve Homeowner</button>
                <button class="btn btn-danger rejectHomeowner" data-id="<?= (int)$homeownerId ?>">Reject Homeowner</button>
              </div>
            </div>
          </div>
        <?php endif; ?>

      </div>

    </div>
  </div>
  <?php
  exit();
}
// All residents imported from Excel:
// - valid imports from homeowners
// - duplicate imports from homeowner_import_queue

$importQueueAvailable = true;
$resultImportQueue = false;

try {

    if ($admin_role === 'superadmin') {

        $queueStmt = $conn->prepare(
            "
            SELECT
                'duplicate' AS source_type,
                q.id AS source_id,
                NULL AS homeowner_id,
                NULL AS public_id,

                q.first_name,
                q.middle_name,
                q.last_name,
                q.contact_number,
                q.email,

                q.phase,
                q.block,
                q.lot,
                q.street,
                q.house_lot_number,

                q.residential_type,

                'duplicate' AS status,

                q.created_at

            FROM homeowner_import_queue q

            WHERE q.status='duplicate'


            UNION ALL


            SELECT
                'homeowner' AS source_type,
                h.id AS source_id,
                h.id AS homeowner_id,
                h.public_id,

                h.first_name,
                h.middle_name,
                h.last_name,
                h.contact_number,
                h.email,

                h.phase,
                h.block,
                h.lot,
                h.street,
                h.house_lot_number,

                h.residential_type,

                h.status,

                h.created_at

FROM homeowners h

WHERE h.status = 'pending'
  AND h.valid_id_path LIKE 'imports/%'

ORDER BY created_at DESC
            "
        );

    } else {

        $queueStmt = $conn->prepare(
            "
            SELECT
                'duplicate' AS source_type,
                q.id AS source_id,
                NULL AS homeowner_id,
                NULL AS public_id,

                q.first_name,
                q.middle_name,
                q.last_name,
                q.contact_number,
                q.email,

                q.phase,
                q.block,
                q.lot,
                q.street,
                q.house_lot_number,

                q.residential_type,

                'duplicate' AS status,

                q.created_at

            FROM homeowner_import_queue q

            WHERE q.status='duplicate'
              AND q.phase=?


            UNION ALL


            SELECT
                'homeowner' AS source_type,
                h.id AS source_id,
                h.id AS homeowner_id,
                h.public_id,

                h.first_name,
                h.middle_name,
                h.last_name,
                h.contact_number,
                h.email,

                h.phase,
                h.block,
                h.lot,
                h.street,
                h.house_lot_number,

                h.residential_type,

                h.status,

                h.created_at

FROM homeowners h

WHERE h.status = 'pending'
  AND h.valid_id_path LIKE 'imports/%'
  AND h.phase=?

ORDER BY created_at DESC
            "
        );

        $queueStmt->bind_param(
            'ss',
            $admin_phase,
            $admin_phase
        );
    }

    $queueStmt->execute();

    $resultImportQueue =
        $queueStmt->get_result();

    $queueStmt->close();

} catch (Throwable $e) {

    $importQueueAvailable = false;

    error_log(
        'Imported residents query error: ' .
        $e->getMessage()
    );
}

// pending homeowners
if ($admin_role === 'superadmin') {
  $sqlHO = $conn->prepare("SELECT * FROM homeowners WHERE status='pending' ORDER BY created_at DESC");
} else {
  $sqlHO = $conn->prepare("SELECT * FROM homeowners WHERE status='pending' AND phase=? ORDER BY created_at DESC");
  $sqlHO->bind_param("s", $admin_phase);
}
$sqlHO->execute();
$resultHO = $sqlHO->get_result();
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
		.badge-warning{background:#f0ad4e}
		.badge-success{background:#22c55e}
		.badge-danger{background:#ef4444}
		.page-title-wrap{display:flex;align-items:center;justify-content:center;text-align:center;margin-bottom:14px}
		.page-title-wrap .subtitle{font-size:14px}
		.card-box{border-radius:14px}
		
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
		
		#viewHomeownerModal #coverMap{
			min-height:320px;
			background:#e9eef6;
		}

		#viewHomeownerModal .card{
			border-radius:14px;
		}

		#viewHomeownerModal .text-muted.small{
			font-size:12px;
			margin-bottom:3px;
		}

		#viewHomeownerModal .fw-semibold{
			color:#263238;
			word-break:break-word;
		}

		#viewHomeownerModal{
			z-index: 1055;
		}
		#actionConfirmModal{
			z-index: 1080;
		}
		.modal-backdrop.show{
			opacity: .5;
		}
		.modal-backdrop + .modal-backdrop{
			z-index: 1070 !important;
		}
		#actionConfirmModal + .modal-backdrop,
		.modal-backdrop.confirm-top{
			z-index: 1070 !important;
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

				<div class="reset-options pt-30 text-center">
					<button class="btn btn-danger" id="reset-settings">Reset Settings</button>
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
					<div class="text-muted fw-semibold subtitle">Household Approval</div>
				</div>
			</div>

			<div class="card-box p-3">
				<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
					<div>
						<h5 class="mb-1">Homeowner Import</h5>
						<small class="text-muted">Upload .xlsx, .xls, or .csv.</small>
					</div>
					<div class="d-flex gap-2">
						<a href="download_homeowner_template.php" class="btn btn-outline-success">Download Excel Template</a>
						<button type="button" class="btn btn-success" id="importExcelBtn">Import Excel</button>
						<input type="file" id="excelFileInput" accept=".xlsx,.xls,.csv" hidden>
					</div>
				</div>
				<div id="importStatus" class="alert d-none mb-3" role="alert"></div>

        <?php if (!$importQueueAvailable): ?>
          <div class="alert alert-warning mb-3">
            Temporary import queue is not available yet. Import <strong>create_homeowner_import_queue.sql</strong> in phpMyAdmin first.
          </div>
        <?php elseif ($resultImportQueue && $resultImportQueue->num_rows > 0): ?>
          <div class="mb-4">
            <h6 class="fw-bold mb-2">Excel Imported Residents</h6>
            <small class="text-muted d-block mb-3">
              All nonblank Excel imports are listed here. Duplicates stay in the duplicate queue; valid imports come from homeowner records.
            </small>
            <div class="table-responsive">
              <table id="importQueueTable" class="table table-bordered table-striped align-middle" style="width:100%">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phase / Block / Lot</th>
                    <th>Residential Type</th>
                    <th>Import Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php while ($qrow = $resultImportQueue->fetch_assoc()): ?>
                    <?php [$queueBlock, $queueLot] = subdivision_block_lot($qrow); ?>
                    <tr>
                      <td>
<?php if (($qrow['source_type'] ?? '') === 'duplicate'): ?>

    DUP-<?= (int)$qrow['source_id'] ?>

<?php else: ?>

    <?php
    $importDisplayId =
        trim(
            (string)(
                $qrow['public_id'] ?? ''
            )
        );

    if ($importDisplayId === '') {

        $importDisplayId =
            phase_prefix(
                (string)$qrow['phase']
            )
            .
            (int)$qrow['source_id'];
    }
    ?>

    <?= esc($importDisplayId) ?>

<?php endif; ?>
</td>
                      <td><?= esc(trim(($qrow['first_name'] ?? '').' '.($qrow['middle_name'] ?? '').' '.($qrow['last_name'] ?? ''))) ?></td>
                      <td><?= esc($qrow['email'] ?? '') ?></td>
                      <td><?= esc(($qrow['phase'] ?? '').' / Block '.$queueBlock.' / Lot '.$queueLot) ?></td>
                      <td><?= esc($qrow['residential_type'] ?? '') ?></td>
<td>

<?php if (($qrow['source_type'] ?? '') === 'duplicate'): ?>

    <span class="badge badge-danger">
        Duplicate
    </span>

<?php else: ?>

    <?php
    $importStatus = strtolower(trim((string)($qrow['status'] ?? 'pending')));

    $importBadgeClass =
        $importStatus === 'approved'
            ? 'badge-success'
            : (
                $importStatus === 'rejected'
                    ? 'badge-danger'
                    : 'badge-warning'
            );

    $importStatusLabel =
        $importStatus === 'approved'
            ? 'Approved'
            : (
                $importStatus === 'rejected'
                    ? 'Rejected'
                    : 'Awaiting Final Approval'
            );
    ?>

    <span class="badge <?= esc($importBadgeClass) ?>">
        <?= esc($importStatusLabel) ?>
    </span>

<?php endif; ?>

</td>
<td>

<?php if (($qrow['source_type'] ?? '') === 'duplicate'): ?>

    <button
        type="button"
        class="btn btn-sm btn-danger viewDuplicateResidentBtn"
        data-id="<?= (int)$qrow['source_id'] ?>"
    >
        View Duplicate
    </button>

<?php else: ?>

    <button
        type="button"
        class="btn btn-sm btn-info viewHomeownerBtn"
        data-id="<?= (int)$qrow['homeowner_id'] ?>"
        title="View Homeowner"
    >
        <i class="dw dw-eye"></i>
        View
    </button>

<?php endif; ?>

</td>
                    </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          </div>
          <hr class="mb-4">
        <?php else: ?>
          <div class="alert alert-light border mb-4">
            No Excel imported residents found.
          </div>
        <?php endif; ?>

        <h6 class="fw-bold mb-2">Homeowners Awaiting Final Approval</h6>

				<div class="table-responsive">
					<table id="approvalTable" class="display table table-striped table-bordered nowrap" style="width:100%">
						<thead>
							<tr>
								<th>ID</th>
								<th>Name</th>
								<th>Address</th>
								<th>Status</th>
								<th style="width:180px;">Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php while($row = $resultHO->fetch_assoc()): ?>
								<?php
									$status = (string)($row['status'] ?? 'pending');
									$badgeClass = ($status==='pending') ? 'badge-warning' : (($status==='approved') ? 'badge-success' : 'badge-danger');
									[$rowBlock, $rowLot] = subdivision_block_lot($row);

									$displayId = trim((string)($row['public_id'] ?? ''));
									if ($displayId === '') {
										$rowPhase = (string)($row['phase'] ?? $admin_phase);
										$prefix = phase_prefix($rowPhase);
										$displayId = $prefix . (int)$row['id'];
									}
								?>
								<tr>
									<td><?= esc($displayId) ?></td>
									<td><?= esc(trim(($row['first_name'] ?? '').' '.($row['middle_name'] ?? '').' '.($row['last_name'] ?? ''))) ?></td>
									<td><?= esc(trim(($row['phase'] ?? '').', Block '.$rowBlock.', Lot '.$rowLot)) ?></td>
									<td><span class="badge <?= $badgeClass ?>"><?= esc(ucfirst($status)) ?></span></td>
									<td>
										<button type="button" class="btn btn-sm btn-info viewHomeownerBtn" data-id="<?= (int)$row['id'] ?>" title="View">
											<i class="dw dw-eye"></i>
										</button>
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

	<div class="modal fade" id="actionConfirmModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content" style="border-radius:14px; overflow:hidden;">
				<div class="modal-header">
					<h5 class="modal-title fw-bold" id="actionConfirmTitle">Confirm Action</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>

				<div class="modal-body">
					<p class="mb-3" id="actionConfirmText">Are you sure?</p>

					<div id="rejectReasonWrap" style="display:none;">
						<label class="form-label fw-semibold">Rejection reason</label>
						<textarea id="rejectReasonInput" class="form-control" rows="3" placeholder="Type the reason..."></textarea>
						<div class="form-text text-danger mt-1" id="rejectReasonError" style="display:none;">
							Rejection reason is required.
						</div>
					</div>
				</div>

				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
					<button type="button" class="btn btn-success" id="actionConfirmBtn">Confirm</button>
				</div>
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
	<div class="modal fade" id="duplicateResidentModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-scrollable">

        <div class="modal-content" style="border-radius:14px; overflow:hidden;">

            <div class="modal-header">

                <h5 class="modal-title fw-bold">
                    Duplicate Resident Review
                </h5>

                <button 
                    type="button" 
                    class="btn-close" 
                    data-bs-dismiss="modal">
                </button>

            </div>


            <div class="modal-body">

                <div id="duplicateResidentContent">

                    <div class="text-muted">
                        Loading duplicate information...
                    </div>

                </div>

            </div>


            <div class="modal-footer">

                <button 
                    type="button" 
                    class="btn btn-light"
                    data-bs-dismiss="modal">

                    Close

                </button>


                <button
                    type="button"
                    class="btn btn-danger"
                    id="notifyDeleteDuplicateBtn"
                    data-id="">

                    Notify & Delete Duplicate

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

	<div id="accessToast" class="access-toast">
	  🚫 You do not have access to that part.
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
	<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
function showToast(
    message,
    type = 'success'
) {

    const toastEl =
        document.getElementById(
            'appToast'
        );

    const msgEl =
        document.getElementById(
            'appToastMsg'
        );

    msgEl.textContent =
        message;


    toastEl.classList.remove(
        'text-bg-success',
        'text-bg-danger',
        'text-bg-warning',
        'text-bg-info',
        'text-bg-dark'
    );


    if (type === 'success') {

        toastEl.classList.add(
            'text-bg-success'
        );

    } else if (type === 'warning') {

        toastEl.classList.add(
            'text-bg-warning'
        );

    } else if (type === 'info') {

        toastEl.classList.add(
            'text-bg-info'
        );

    } else {

        toastEl.classList.add(
            'text-bg-danger'
        );
    }


    bootstrap.Toast
        .getOrCreateInstance(
            toastEl,
            {
                delay: 4000
            }
        )
        .show();
}

	const homeownerImportCsrf = <?= json_encode($homeownerImportCsrf) ?>;
	const southMeridianLocations = <?= json_encode(array_values($southMeridianLocations), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
	const southMeridianLocationIndex = new Map(
		southMeridianLocations.map(location => [`${Number(location.block)}:${Number(location.lot)}`, location])
	);

	function normalizeExcelKey(key) {
	return String(key || '')
		.trim()
		.toLowerCase()
		.replace(/&/g, ' and ')
		.replace(/[^a-z0-9]+/g, '_')
		.replace(/^_+|_+$/g, '');
}

function normalizeExcelRow(row) {
	const aliases = {
		firstname: 'first_name',
		first: 'first_name',

		middlename: 'middle_name',
		middle: 'middle_name',

		lastname: 'last_name',
		last: 'last_name',

		contact: 'contact_number',
		contact_no: 'contact_number',
		phone: 'contact_number',
		mobile: 'contact_number',

		block_number: 'block',
		block_no: 'block',

		lot_number: 'lot',
		lot_no: 'lot',

		address: 'other_location_info',

		city: 'city_municipality',
		municipality: 'city_municipality',

		zipcode: 'zip_code',
		zip: 'zip_code',
		postal_code: 'zip_code',
		postalcode: 'zip_code',

		residentialtype: 'residential_type',

		emergency_contact: 'emergency_contact_person',
		emergency_person: 'emergency_contact_person',
		emergency_number: 'emergency_contact_number',

		length_of_residency_in_the_barangay:
			'length_of_residency'
	};

	const clean = {};

	Object.entries(row || {}).forEach(([key, value]) => {
		let normalized = normalizeExcelKey(key);
		normalized = aliases[normalized] || normalized;

		clean[normalized] =
			typeof value === 'string'
				? value.trim()
				: value;
	});

	return clean;
}
	function setImportStatus(message, kind='info') {
		const el = document.getElementById('importStatus');
		el.className = 'alert mb-3 alert-' + kind;
		el.textContent = message;
	}

document.addEventListener('DOMContentLoaded', function () {

    const importBtn =
        document.getElementById('importExcelBtn');

    const fileInput =
        document.getElementById('excelFileInput');


    importBtn?.addEventListener(
        'click',
        function () {

            fileInput.click();

        }
    );


    fileInput?.addEventListener(
        'change',
        async function () {

            const file =
                this.files &&
                this.files[0];

            if (!file) {
                return;
            }

        try {

            if (
                typeof XLSX ===
                'undefined'
            ) {
                throw new Error(
                    'Excel library failed to load.'
                );
            }

            importBtn.disabled = true;

            setImportStatus(
                'Reading ' +
                file.name +
                '...',
                'info'
            );


            /*
            |--------------------------------------------------------------------------
            | Read Excel
            |--------------------------------------------------------------------------
            */

            const buffer =
                await file.arrayBuffer();

            const workbook =
                XLSX.read(
                    buffer,
                    {
                        type: 'array'
                    }
                );

            const firstSheet =
                workbook.Sheets[
                    workbook.SheetNames[0]
                ];


            /*
            |--------------------------------------------------------------------------
            | Find column headings
            |--------------------------------------------------------------------------
            */

            const matrix =
                XLSX.utils.sheet_to_json(
                    firstSheet,
                    {
                        header: 1,
                        defval: '',
                        raw: false
                    }
                );

            const headerIndex =
                matrix.findIndex(
                    row => {

                        const headers =
                            row.map(
                                value =>
                                    normalizeExcelKey(
                                        value
                                    )
                            );

                        return (
                            headers.includes(
                                'first_name'
                            ) &&

                            headers.includes(
                                'last_name'
                            ) &&

                            headers.includes(
                                'contact_number'
                            ) &&

                            headers.includes(
                                'email'
                            ) &&

                            headers.includes(
                                'block'
                            ) &&

                            headers.includes(
                                'lot'
                            )
                        );
                    }
                );

            if (headerIndex === -1) {

                throw new Error(
                    'Could not find the Excel column headings. Please use the revised South Meridian Block and Lot template.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Convert worksheet into rows
            |--------------------------------------------------------------------------
            */

            let rows =
                XLSX.utils.sheet_to_json(
                    firstSheet,
                    {
                        range:
                            headerIndex,

                        defval: '',

                        raw: false
                    }
                );

rows =
    rows
        .map(normalizeExcelRow)
        .filter(row => {

            /*
             * IMPORTANT:
             *
             * Do NOT use Object.values(row), because the Excel
             * template automatically fills Barangay, City,
             * Province, Region, ZIP and Country down to row 500.
             *
             * We only consider a row a real resident row when
             * one of the actual homeowner-input fields contains
             * information.
             */

            const residentFields = [
                row.first_name,
                row.middle_name,
                row.last_name,
                row.contact_number,
                row.email,

                row.block,
                row.lot,

                row.other_location_info,
                row.length_of_residency,
                row.residential_type,

                row.emergency_contact_person,
                row.emergency_contact_number
            ];

            return residentFields.some(
                value =>
                    String(value ?? '')
                        .trim() !== ''
            );
        });

            if (!rows.length) {

                throw new Error(
                    'No resident data rows were found in the Excel file.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Required columns
            |--------------------------------------------------------------------------
            */

            const requiredHeaders = [
                'first_name',
                'last_name',
                'contact_number',
                'email',

                'phase',
                'block',
                'lot',

                'barangay',
                'city_municipality',
                'province',
                'region',
                'zip_code',
                'country',

                'length_of_residency',
                'residential_type',

                'emergency_contact_person',
                'emergency_contact_number'
            ];

            const missing =
                requiredHeaders.filter(
                    header =>
                        !(header in rows[0])
                );

            if (missing.length) {

                throw new Error(
                    'Missing required column(s): ' +
                    missing.join(', ')
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Map required
            |--------------------------------------------------------------------------
            */

            if (
                !southMeridianLocationIndex.size
            ) {

                throw new Error(
                    'The subdivision Block/Lot mapping file is missing. Upload southmeri_block_lot_mapping.json beside this PHP file.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Block / Lot and attach map data
            |--------------------------------------------------------------------------
            */

            rows =
                rows.map(
                    (row, index) => {

 const rawBlock =
    String(row.block ?? '').trim();

const rawLot =
    String(row.lot ?? '').trim();

const excelRow =
    headerIndex +
    index +
    2;


/*
|--------------------------------------------------------------------------
| Block / Lot required
|--------------------------------------------------------------------------
*/

if (rawBlock === '' && rawLot === '') {

    throw new Error(
        `Excel row ${excelRow}: Block and Lot are required.`
    );
}

if (rawBlock === '') {

    throw new Error(
        `Excel row ${excelRow}: Block is required.`
    );
}

if (rawLot === '') {

    throw new Error(
        `Excel row ${excelRow}: Lot is required.`
    );
}


/*
|--------------------------------------------------------------------------
| Convert to numbers
|--------------------------------------------------------------------------
*/

const block =
    Number.parseInt(
        rawBlock,
        10
    );

const lot =
    Number.parseInt(
        rawLot,
        10
    );


if (
    !Number.isInteger(block) ||
    block <= 0
) {

    throw new Error(
        `Excel row ${excelRow}: "${rawBlock}" is not a valid Block number.`
    );
}


if (
    !Number.isInteger(lot) ||
    lot <= 0
) {

    throw new Error(
        `Excel row ${excelRow}: "${rawLot}" is not a valid Lot number.`
    );
}


/*
|--------------------------------------------------------------------------
| Check official South Meridian map
|--------------------------------------------------------------------------
*/

const location =
    southMeridianLocationIndex.get(
        `${block}:${lot}`
    );


if (!location) {

    throw new Error(
        `Excel row ${excelRow}: Block ${block}, Lot ${lot} is not a valid South Meridian address. Please select a Block and Lot from the Excel dropdowns.`
    );
}

                        return {
                            ...row,

                            block:
                                String(block),

                            lot:
                                String(lot),

                            house_lot_number:
                                `Block ${block} Lot ${lot}`,

                            street:
                                location.street,

                            map_x:
                                location.x,

                            map_y:
                                location.y,

                            barangay:
                                String(
                                    row.barangay ||
                                    'Salitran IV'
                                ).trim(),

                            city_municipality:
                                String(
                                    row.city_municipality ||
                                    'Dasmarinas City'
                                ).trim(),

                            province:
                                String(
                                    row.province ||
                                    'Cavite'
                                ).trim(),

                            region:
                                String(
                                    row.region ||
                                    'CALABARZON'
                                ).trim(),

                            zip_code:
                                String(
                                    row.zip_code ||
                                    '4114'
                                ).trim(),

                            country:
                                String(
                                    row.country ||
                                    'Philippines'
                                ).trim()
                        };
                    }
                );


            /*
            |--------------------------------------------------------------------------
            | Counters
            |--------------------------------------------------------------------------
            */

            let imported = 0;
            let duplicates = 0;
            let skipped = 0;

            const errors = [];

            /*
             * THIS WAS MISSING
             * FROM YOUR CURRENT FILE.
             */
            const chunkSize = 100;


            /*
            |--------------------------------------------------------------------------
            | Upload chunks
            |--------------------------------------------------------------------------
            */

            for (
                let i = 0;
                i < rows.length;
                i += chunkSize
            ) {

                const chunk =
                    rows.slice(
                        i,
                        i + chunkSize
                    );

                setImportStatus(
                    `Importing rows ${i + 1}-${Math.min(
                        i + chunk.length,
                        rows.length
                    )} of ${rows.length}...`,
                    'info'
                );


                const response =
                    await fetch(
                        'import_homeowner.php',
                        {
                            method:
                                'POST',

                            headers: {
                                'Content-Type':
                                    'application/json'
                            },

                            body:
                                JSON.stringify({
                                    csrf:
                                        homeownerImportCsrf,

                                    rows:
                                        chunk,

                                    row_offset:
                                        i +
                                        headerIndex +
                                        1
                                })
                        }
                    );


                /*
                |--------------------------------------------------------------------------
                | Read JSON response
                |--------------------------------------------------------------------------
                */

                const responseText =
                    await response.text();

                let data = null;

                try {

                    data =
                        JSON.parse(
                            responseText
                        );

                } catch (parseError) {

                    console.error(
                        'Raw import server response:',
                        responseText
                    );

                    const readableResponse =
                        String(
                            responseText || ''
                        )
                            .replace(
                                /<[^>]*>/g,
                                ' '
                            )
                            .replace(
                                /\s+/g,
                                ' '
                            )
                            .trim()
                            .slice(
                                0,
                                500
                            );

                    throw new Error(
                        'Import server returned an invalid response (HTTP ' +
                        response.status +
                        '). ' +
                        (
                            readableResponse
                                ? 'Server says: ' +
                                  readableResponse
                                : 'The response was empty.'
                        )
                    );
                }


                if (
                    !response.ok ||
                    !data ||
                    !data.success
                ) {

                    throw new Error(
                        data &&
                        data.message

                            ? data.message

                            : 'Import request failed.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | Totals
                |--------------------------------------------------------------------------
                */

                imported +=
                    Number(
                        data.imported || 0
                    );

                duplicates +=
                    Number(
                        data.duplicates || 0
                    );

                skipped +=
                    Number(
                        data.skipped || 0
                    );

                (
                    data.errors || []
                ).forEach(
                    error =>
                        errors.push(
                            error
                        )
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Summary
            |--------------------------------------------------------------------------
            */

            let summary =
                `Import finished: ${imported} added to final approval`;

            if (duplicates > 0) {

                summary +=
                    `, ${duplicates} duplicate(s) detected`;
            }

            if (skipped > 0) {

                summary +=
                    `, ${skipped} skipped`;
            }

            if (errors.length > 0) {

                summary +=
                    `, ${errors.length} row error(s)`;
            }

            summary += '.';


            /*
            |--------------------------------------------------------------------------
            | Display result
            |--------------------------------------------------------------------------
            */

            if (duplicates > 0) {

                setImportStatus(
                    summary +
                    ' Duplicate residents are listed below for review.',
                    'warning'
                );

                showToast(
                    `${duplicates} duplicate resident(s) detected.`,
                    'warning'
                );

            } else if (
                errors.length > 0
            ) {

                setImportStatus(
                    summary +
                    ' First error: ' +
                    errors[0],
                    'warning'
                );

                showToast(
                    summary,
                    'error'
                );

            } else {

                setImportStatus(
                    summary,
                    'success'
                );

                showToast(
                    summary,
                    'success'
                );
            }


            /*
             * Reload so:
             *
             * - duplicates appear in the
             *   duplicate table
             *
             * - valid imports appear in
             *   final approval
             */

            setTimeout(
                () => {
                    location.reload();
                },
                1800
            );


        } catch (err) {

            console.error(err);

            setImportStatus(
                err.message ||
                'Import failed.',
                'danger'
            );

            showToast(
                err.message ||
                'Import failed.',
                'error'
            );

        } finally {

            importBtn.disabled =
                false;

            fileInput.value =
                '';
        }
    });

});


	$(function () {
        if (
            $.fn.DataTable &&
            $('#importQueueTable').length &&
            !$.fn.DataTable.isDataTable('#importQueueTable')
        ) {
            $('#importQueueTable').DataTable({
                responsive: true,
                pageLength: 25,
                order: [],
                columnDefs: [
                    { orderable: false, targets: 6 }
                ]
            });
        }

		if ($.fn.DataTable && $('#approvalTable').length && !$.fn.DataTable.isDataTable('#approvalTable')) {
			$('#approvalTable').DataTable({
				responsive: true,
				columnDefs: [{ orderable: false, targets: 4 }]
			});
		}

		const confirmModalEl = document.getElementById('actionConfirmModal');
		const confirmTitleEl = document.getElementById('actionConfirmTitle');
		const confirmTextEl  = document.getElementById('actionConfirmText');
		const confirmBtnEl   = document.getElementById('actionConfirmBtn');
		const reasonWrapEl   = document.getElementById('rejectReasonWrap');
		const reasonInputEl  = document.getElementById('rejectReasonInput');
		const reasonErrorEl  = document.getElementById('rejectReasonError');

		let pendingAction = { id: null, status: null };

		const confirmModal = new bootstrap.Modal(confirmModalEl, {
			backdrop: true,
			keyboard: false,
			focus: true
		});

		$(document).on('click', '.approveHomeowner, .rejectHomeowner', function (e) {
			e.preventDefault();

			const id = $(this).data('id');
			const status = $(this).hasClass('approveHomeowner') ? 'approved' : 'rejected';
			if (!id) return;

			pendingAction = { id, status };

			if (status === 'approved') {
				confirmTitleEl.textContent = 'Approve Homeowner';
				confirmTextEl.textContent  = 'This will approve the homeowner. Continue?';
				confirmBtnEl.classList.remove('btn-danger');
				confirmBtnEl.classList.add('btn-success');
				confirmBtnEl.textContent = 'Approve';
				reasonWrapEl.style.display = 'none';
				reasonErrorEl.style.display = 'none';
				reasonInputEl.value = '';
			} else {
				confirmTitleEl.textContent = 'Reject Homeowner';
				confirmTextEl.textContent  = 'Please provide a rejection reason. This will be saved and sent.';
				confirmBtnEl.classList.remove('btn-success');
				confirmBtnEl.classList.add('btn-danger');
				confirmBtnEl.textContent = 'Reject';
				reasonWrapEl.style.display = 'block';
				reasonErrorEl.style.display = 'none';
				reasonInputEl.value = '';
			}

			confirmModal.show();

			setTimeout(function () {
				const backdrops = document.querySelectorAll('.modal-backdrop');
				if (backdrops.length > 1) {
					backdrops[backdrops.length - 1].classList.add('confirm-top');
				}
				if (status === 'rejected') {
					reasonInputEl.focus();
				} else {
					confirmBtnEl.focus();
				}
			}, 120);
		});

		confirmBtnEl.addEventListener('click', function () {

    const { id, status } = pendingAction;

    if (!id || !status) {
        return;
    }

    let reason = '';

    if (status === 'rejected') {

        reason =
            (reasonInputEl.value || '')
                .trim();

        if (!reason) {

            reasonErrorEl.style.display =
                'block';

            reasonInputEl.focus();

            return;
        }
    }

    confirmBtnEl.disabled = true;

    const oldText =
        confirmBtnEl.textContent;

    confirmBtnEl.textContent =
        status === 'approved'
            ? 'Approving...'
            : 'Rejecting...';


    $.ajax({

        url:
            'update_homeowner_status_email.php',

        type:
            'POST',

        dataType:
            'json',

        data: {
            id: id,
            status: status,
            reason: reason,
            csrf: homeownerImportCsrf
        },


        success: function (res) {

            if (!res || !res.success) {

                showToast(
                    res && res.message
                        ? res.message
                        : 'Action failed.',
                    'error'
                );

                confirmBtnEl.disabled =
                    false;

                confirmBtnEl.textContent =
                    oldText;

                return;
            }


            showToast(
                res.message ||
                'Updated successfully.',
                'success'
            );


            confirmModal.hide();


            const viewModalEl =
                document.getElementById(
                    'viewHomeownerModal'
                );


            const viewModalInstance =
                bootstrap.Modal.getInstance(
                    viewModalEl
                );


            if (viewModalInstance) {
                viewModalInstance.hide();
            }


            setTimeout(
                function () {
                    location.reload();
                },
                800
            );
        },


        error: function (xhr) {

            console.error(
                'Approval request failed.'
            );

            console.error(
                'HTTP Status:',
                xhr.status
            );

            console.error(
                'Server Response:',
                xhr.responseText
            );


            let message =
                'Request failed. Please try again.';


            /*
             * Try to read JSON returned by PHP.
             */
            if (xhr.responseText) {

                try {

                    const data =
                        JSON.parse(
                            xhr.responseText
                        );


                    if (
                        data &&
                        data.message
                    ) {

                        message =
                            data.message;
                    }

                } catch (error) {

                    /*
                     * PHP returned HTML/text
                     * instead of JSON.
                     */

                    if (xhr.status === 401) {

                        message =
                            'Your admin session expired. Please login again.';

                    } else if (xhr.status === 403) {

                        message =
                            'Access denied or security token expired. Refresh the page and try again.';

                    } else if (xhr.status === 404) {

                        message =
                            'Homeowner or approval endpoint was not found.';

                    } else if (xhr.status === 500) {

                        message =
                            'Server error occurred while approving the homeowner. Check the PHP error log or email configuration.';

                    } else {

                        const cleanResponse =
                            String(
                                xhr.responseText
                            )
                            .replace(
                                /<[^>]*>/g,
                                ' '
                            )
                            .replace(
                                /\s+/g,
                                ' '
                            )
                            .trim();


                        if (cleanResponse) {

                            message =
                                cleanResponse.substring(
                                    0,
                                    300
                                );
                        }
                    }
                }
            }


            showToast(
                message,
                'error'
            );


            confirmBtnEl.disabled =
                false;

            confirmBtnEl.textContent =
                oldText;
        }

    });

});

		confirmModalEl.addEventListener('hidden.bs.modal', function () {
			pendingAction = { id: null, status: null };
			confirmBtnEl.disabled = false;
			confirmBtnEl.textContent = 'Confirm';
			reasonErrorEl.style.display = 'none';
			reasonInputEl.value = '';

			document.querySelectorAll('.modal-backdrop.confirm-top').forEach(function(el){
				el.classList.remove('confirm-top');
			});
		});

		const modalEl = document.getElementById('viewHomeownerModal');
		const content = document.getElementById('viewHomeownerContent');
		const modal = new bootstrap.Modal(modalEl, {
			backdrop: 'static',
			keyboard: true
		});

		let coverMapInstance = null;
		let pendingProfileHtml = '';

		function destroyCoverMap() {
			if (coverMapInstance) {
				coverMapInstance.remove();
				coverMapInstance = null;
			}
		}

function initCoverMapIfAny() {
	const mapEl = document.getElementById('coverMap');
	if (!mapEl || typeof L === 'undefined') return;

	const x = parseFloat(mapEl.getAttribute('data-map-x') || '');
	const y = parseFloat(mapEl.getAttribute('data-map-y') || '');
	const block = mapEl.getAttribute('data-block') || '';
	const lot = mapEl.getAttribute('data-lot') || '';
	const street = mapEl.getAttribute('data-street') || '';
	const imageUrl = mapEl.getAttribute('data-map-image') || '';
	if (!isFinite(x) || !isFinite(y) || !imageUrl) return;
	const leafletY = 3300 - y;

	destroyCoverMap();

	coverMapInstance = L.map(mapEl, {
		crs: L.CRS.Simple,
		center: [leafletY, x],
		zoom: -1,
		minZoom: -3,
		maxZoom: 3,
		zoomSnap: 0.25,
		zoomControl: true,
		attributionControl: false
	});

	const mapBounds = [[0, 0], [3300, 2550]];
	L.imageOverlay(imageUrl, mapBounds).addTo(coverMapInstance);
	L.marker([leafletY, x])
		.addTo(coverMapInstance)
		.bindPopup(`<strong>Block ${block}, Lot ${lot}</strong>${street ? `<br>${street}` : ''}`)
		.openPopup();

	setTimeout(function () {
		if (coverMapInstance) {
			coverMapInstance.invalidateSize(true);
			coverMapInstance.setView([leafletY, x], -1);
		}
	}, 300);

	setTimeout(function () {
		if (coverMapInstance) {
			coverMapInstance.invalidateSize(true);
		}
	}, 800);
}

		$(document).on('click', '.viewHomeownerBtn', function (e) {
			e.preventDefault();
			const id = $(this).data('id');
			if (!id) return;

			pendingProfileHtml = '';
			destroyCoverMap();
			content.innerHTML = '<div class="p-4 text-muted fw-semibold">Loading...</div>';
			modal.show();

			$.get('ho_approval.php', { ajax: 'homeowner_profile', id: id, _: Date.now() })
				.done(function (html) {
					pendingProfileHtml = html;

					if (modalEl.classList.contains('show')) {
						content.innerHTML = pendingProfileHtml;
						initCoverMapIfAny();
					}
				})
				.fail(function (xhr) {
					content.innerHTML = '<div class="p-4"><div class="alert alert-danger mb-0">Failed to load profile. HTTP ' + xhr.status + '</div></div>';
				});
		});

		modalEl.addEventListener('shown.bs.modal', function () {
			if (pendingProfileHtml) {
				content.innerHTML = pendingProfileHtml;
				initCoverMapIfAny();
			} else {
				setTimeout(function () {
					initCoverMapIfAny();
				}, 200);
			}
		});

		modalEl.addEventListener('hidden.bs.modal', function () {
			destroyCoverMap();
			pendingProfileHtml = '';
			content.innerHTML = '';
		});
	});
</script>

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
	<script>
		
const duplicateResidentModalEl =
    document.getElementById(
        'duplicateResidentModal'
    );

const duplicateResidentModal =
    duplicateResidentModalEl
        ? new bootstrap.Modal(
            duplicateResidentModalEl
        )
        : null;

const duplicateResidentContent =
    document.getElementById(
        'duplicateResidentContent'
    );

const notifyDeleteDuplicateBtn =
    document.getElementById(
        'notifyDeleteDuplicateBtn'
    );


function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


$(document).on(
    'click',
    '.viewDuplicateResidentBtn',
    async function () {

        const id =
            Number(
                this.dataset.id || 0
            );

        if (!id) {
            return;
        }

        duplicateResidentContent.innerHTML =
            '<div class="text-muted">Loading duplicate information...</div>';

        notifyDeleteDuplicateBtn.dataset.id =
            String(id);

        notifyDeleteDuplicateBtn.disabled =
            true;

        duplicateResidentModal.show();


        try {

            const response =
                await fetch(
                    'get_duplicate_homeowner.php?id='
                    +
                    encodeURIComponent(id),
                    {
                        headers: {
                            'Accept':
                                'application/json'
                        }
                    }
                );


            const data =
                await response.json();


            if (
                !response.ok ||
                !data.success
            ) {
                throw new Error(
                    data.message ||
                    'Unable to load duplicate information.'
                );
            }


            const q =
                data.queue;

            const existing =
                data.existing;


            duplicateResidentContent.innerHTML = `

                <div class="alert alert-warning">
                    The imported email address is already registered in the system.
                    Review both records before removing the duplicate import.
                </div>

                <div class="row g-3">

                    <div class="col-md-6">

                        <div class="card h-100 border-warning">

                            <div class="card-header fw-bold">
                                Imported Resident
                            </div>

                            <div class="card-body">

                                <div class="mb-2">
                                    <small class="text-muted">Name</small>
                                    <div class="fw-semibold">
                                        ${escapeHtml(q.name)}
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <small class="text-muted">Email</small>
                                    <div class="fw-semibold">
                                        ${escapeHtml(q.email)}
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <small class="text-muted">Contact</small>
                                    <div class="fw-semibold">
                                        ${escapeHtml(q.contact_number)}
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <small class="text-muted">Phase</small>
                                    <div class="fw-semibold">
                                        ${escapeHtml(q.phase)}
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <small class="text-muted">Address</small>
                                    <div class="fw-semibold">
                                        Block ${escapeHtml(q.block)},
                                        Lot ${escapeHtml(q.lot)}
                                        ${q.street ? ' · ' + escapeHtml(q.street) : ''}
                                    </div>
                                </div>

                                <div>
                                    <small class="text-muted">Residential Type</small>
                                    <div class="fw-semibold">
                                        ${escapeHtml(q.residential_type)}
                                    </div>
                                </div>

                            </div>

                        </div>

                    </div>


                    <div class="col-md-6">

                        <div class="card h-100 border-success">

                            <div class="card-header fw-bold">
                                Existing Registered Homeowner
                            </div>

                            <div class="card-body">

                                <div class="mb-2">
                                    <small class="text-muted">Homeowner ID</small>
                                    <div class="fw-semibold">
                                        ${escapeHtml(existing.public_id || existing.id)}
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <small class="text-muted">Name</small>
                                    <div class="fw-semibold">
                                        ${escapeHtml(existing.name)}
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <small class="text-muted">Email</small>
                                    <div class="fw-semibold">
                                        ${escapeHtml(existing.email)}
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <small class="text-muted">Contact</small>
                                    <div class="fw-semibold">
                                        ${escapeHtml(existing.contact_number)}
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <small class="text-muted">Phase</small>
                                    <div class="fw-semibold">
                                        ${escapeHtml(existing.phase)}
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <small class="text-muted">Address</small>
                                    <div class="fw-semibold">
                                        Block ${escapeHtml(existing.block)},
                                        Lot ${escapeHtml(existing.lot)}
                                        ${existing.street ? ' · ' + escapeHtml(existing.street) : ''}
                                    </div>
                                </div>

                                <div>
                                    <small class="text-muted">Status</small>
                                    <div class="fw-semibold">
                                        ${escapeHtml(existing.status)}
                                    </div>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>
            `;


            notifyDeleteDuplicateBtn.disabled =
                false;


        } catch (err) {

            duplicateResidentContent.innerHTML =
                '<div class="alert alert-danger mb-0">'
                +
                escapeHtml(
                    err.message ||
                    'Unable to load duplicate information.'
                )
                +
                '</div>';
        }
    }
);
notifyDeleteDuplicateBtn?.addEventListener(
    'click',
    async function () {

        const id =
            Number(
                this.dataset.id || 0
            );

        if (!id) {
            return;
        }


        const confirmed =
            window.confirm(
                'Send duplicate email notification and remove this duplicate import?'
            );


        if (!confirmed) {
            return;
        }


        this.disabled = true;
        this.textContent = "Sending...";


        try {

            const body =
                new URLSearchParams();

            body.set(
                'id',
                String(id)
            );

            body.set(
                'csrf',
                homeownerImportCsrf
            );


            const response =
                await fetch(
                    'notify_delete_duplicate_homeowner.php',
                    {
                        method:'POST',
                        headers:{
                            'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8'
                        },
                        body:body.toString()
                    }
                );


 const responseText =
    await response.text();
let data;

try {
    data = JSON.parse(responseText);
} catch (e) {

    console.error(
        'Raw server response:',
        responseText
    );

    throw new Error(
        'Server returned invalid JSON: ' +
        String(responseText || '')
            .replace(/<[^>]*>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 300)
    );
}


if (!response.ok || !data.success) {

    throw new Error(
        data.message ||
        'Failed to process duplicate.'
    );

}


            if (data.email_sent === false) {

                console.error(
                    'Email error:',
                    data.email_error || ''
                );

                showToast(
                    data.message +
                    (
                        data.email_error
                            ? ' Error: ' + data.email_error
                            : ''
                    ),
                    'error'
                );

            } else {

                showToast(
                    data.message,
                    'success'
                );
            }


            duplicateResidentModal.hide();


            setTimeout(
                function(){
                    location.reload();
                },
                1000
            );


        } catch(error) {


            showToast(
                error.message,
                'error'
            );


            this.disabled = false;
            this.textContent =
                "Notify & Delete Duplicate";

        }

    }
);

		</script>
</body>
</html>