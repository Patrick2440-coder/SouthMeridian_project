<?php
session_start();
require_once 'admin_access.php';
require_once '../config/database.php';
require_once 'ownership_transfer_common.php';
requireAccess('homeowner_management');

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  $_SESSION['flash_type'] = 'danger';
  $_SESSION['flash_message'] = 'Access denied. Please login as admin.';
  header('Location: index.php');
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

/*
 * Duplicate imports are never deleted.
 * A separate archive record is used so the original import queue record and
 * the existing homeowner record remain intact for audit/history purposes.
 */
$conn->query("CREATE TABLE IF NOT EXISTS homeowner_import_archive (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_queue_id INT NOT NULL,
  existing_homeowner_id INT NULL,
  first_name VARCHAR(100) NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  contact_number VARCHAR(50) NULL,
  email VARCHAR(190) NULL,
  phase VARCHAR(50) NULL,
  block INT NULL,
  lot INT NULL,
  street VARCHAR(190) NULL,
  residential_type VARCHAR(100) NULL,
  existing_email VARCHAR(190) NULL,
  archived_by_admin_id INT NULL,
  archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_homeowner_import_archive_source (source_queue_id),
  KEY idx_homeowner_import_archive_phase (phase),
  KEY idx_homeowner_import_archive_archived_at (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
  /*
|--------------------------------------------------------------------------
| South Meridian Map Cache Version
|--------------------------------------------------------------------------
*/

$mapImageFile =
    dirname(__DIR__) .
    '/assets/img/south_meridian_block_lot_map.png';

$mapImageVersion =
    is_file($mapImageFile)
        ? (int)filemtime($mapImageFile)
        : time();
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
                   data-map-image="../assets/img/south_meridian_block_lot_map.png?v=<?= $mapImageVersion ?>"
                   class="rounded"></div>
            </div>
          </div>
        <?php else: ?>
          <div class="alert alert-secondary shadow-sm"><strong>Map location:</strong><br>No matching Block and Lot was found on the finalized subdivision map.</div>
        <?php endif; ?>

        <?php if ($status === 'pending'): ?>
          <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
              <h6 class="fw-bold mb-3">Import Review</h6>
              <div class="d-grid gap-2">
                <button class="btn btn-success finalizeImportedHomeowner" data-id="<?= (int)$homeownerId ?>">Push to Homeowner Data</button>
                <div class="small text-muted mt-2">Double-check the resident information first. This is not an approval step; it only confirms that the imported record is ready to become active homeowner data.</div>
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
                q.duplicate_homeowner_id,

                q.created_at

            FROM homeowner_import_queue q

            WHERE q.status='duplicate'
              AND NOT EXISTS (SELECT 1 FROM homeowner_import_archive a WHERE a.source_queue_id=q.id)


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
                NULL AS duplicate_homeowner_id,

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
                q.duplicate_homeowner_id,

                q.created_at

            FROM homeowner_import_queue q

            WHERE q.status='duplicate'
              AND q.phase=?
              AND NOT EXISTS (SELECT 1 FROM homeowner_import_archive a WHERE a.source_queue_id=q.id)


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
                NULL AS duplicate_homeowner_id,

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

/*
 * Count valid imported homeowners that are still waiting to be pushed.
 * Duplicate imports are stored in homeowner_import_queue and are not included.
 */
if ($admin_role === 'superadmin') {
  $pendingImportedCountStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM homeowners
     WHERE status='pending'
       AND valid_id_path LIKE 'imports/%'"
  );
} else {
  $pendingImportedCountStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM homeowners
     WHERE status='pending'
       AND valid_id_path LIKE 'imports/%'
       AND phase=?"
  );
  $pendingImportedCountStmt->bind_param('s', $admin_phase);
}

$pendingImportedCountStmt->execute();
$pendingImportedCountRow = $pendingImportedCountStmt->get_result()->fetch_assoc();
$pendingImportedCount = (int)($pendingImportedCountRow['total'] ?? 0);
$pendingImportedCountStmt->close();

/*
 * Manual / walk-in pending registrations that use a property already assigned
 * to another APPROVED homeowner are possible ownership transfers. They are
 * intentionally kept Pending until both parties are verified and an admin
 * completes the transfer.
 */
$manualTransferCandidates = [];
try {
    if ($admin_role === 'superadmin') {
        $manualPendingStmt = $conn->prepare(
            "SELECT * FROM homeowners
             WHERE status='pending'
               AND valid_id_path NOT LIKE 'imports/%'
             ORDER BY created_at DESC"
        );
        $approvedOwnersStmt = $conn->prepare(
            "SELECT id, public_id, first_name, middle_name, last_name, email,
                    phase, block, lot, street, house_lot_number, status
             FROM homeowners
             WHERE status='approved'"
        );
    } else {
        $manualPendingStmt = $conn->prepare(
            "SELECT * FROM homeowners
             WHERE status='pending'
               AND valid_id_path NOT LIKE 'imports/%'
               AND phase=?
             ORDER BY created_at DESC"
        );
        $manualPendingStmt->bind_param('s', $admin_phase);

        $approvedOwnersStmt = $conn->prepare(
            "SELECT id, public_id, first_name, middle_name, last_name, email,
                    phase, block, lot, street, house_lot_number, status
             FROM homeowners
             WHERE status='approved' AND phase=?"
        );
        $approvedOwnersStmt->bind_param('s', $admin_phase);
    }

    $approvedOwnersStmt->execute();
    $approvedOwners = $approvedOwnersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $approvedOwnersStmt->close();

    $ownerByProperty = [];
    foreach ($approvedOwners as $ownerRow) {
        [$ownerBlock, $ownerLot] = subdivision_block_lot($ownerRow);
        if ($ownerBlock <= 0 || $ownerLot <= 0) continue;
        $ownerKey = (string)$ownerRow['phase'] . '|' . $ownerBlock . '|' . $ownerLot;
        if (!isset($ownerByProperty[$ownerKey])) {
            $ownerByProperty[$ownerKey] = $ownerRow;
        }
    }

    $manualPendingStmt->execute();
    $manualPendingRows = $manualPendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $manualPendingStmt->close();

    foreach ($manualPendingRows as $pendingRow) {
        [$pendingBlock, $pendingLot] = subdivision_block_lot($pendingRow);
        if ($pendingBlock <= 0 || $pendingLot <= 0) continue;
        $key = (string)$pendingRow['phase'] . '|' . $pendingBlock . '|' . $pendingLot;
        if (!isset($ownerByProperty[$key])) continue;

        $currentOwner = $ownerByProperty[$key];
        $incomingEmail = strtolower(trim((string)($pendingRow['email'] ?? '')));
        $currentEmail = strtolower(trim((string)($currentOwner['email'] ?? '')));
        if ($incomingEmail === '' || $currentEmail === '' || $incomingEmail === $currentEmail) continue;

        $manualTransferCandidates[] = [
            'incoming' => $pendingRow,
            'current_owner' => $currentOwner,
            'block' => $pendingBlock,
            'lot' => $pendingLot,
        ];
    }
} catch (Throwable $e) {
    error_log('Manual ownership-transfer candidate query failed: ' . $e->getMessage());
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

		/* =========================================================
		   PAGE / FOOTER LAYOUT
		   Keeps the footer below the content and at the bottom
		   of the viewport when the page content is short.
		   ========================================================= */
		.main-container {
			min-height: calc(100vh - 70px);
		}

		.ho-page-shell {
			min-height: calc(100vh - 70px);
			display: flex;
			flex-direction: column;
		}

		.ho-page-content {
			flex: 1 0 auto;
		}

		.ho-footer {
			flex-shrink: 0;
			margin-top: 24px;
			text-align: center;
		}
		
/* ACCESS TOAST */
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
    visibility: hidden;
    pointer-events: none;

    transform: translateY(-10px);

    transition:
        opacity .3s ease,
        transform .3s ease,
        visibility .3s ease;
}

.access-toast.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

/*
 * Bootstrap application toast is also in the top-right.
 * Don't let an invisible toast block header buttons.
 */
#appToast {
    pointer-events: none;
}

#appToast.show,
#appToast.showing {
    pointer-events: auto;
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
        /* =========================================================
   BOOTSTRAP 5 + DATATABLES DARK TABLE FIX
   ========================================================= */

html.dark .table {
    --bs-table-color: var(--admin-text);
    --bs-table-bg: var(--admin-surface);

    --bs-table-border-color:
        var(--admin-border);

    --bs-table-striped-color:
        var(--admin-text);

    --bs-table-striped-bg:
        rgba(148, 163, 184, .055);

    --bs-table-active-color:
        var(--admin-text);

    --bs-table-active-bg:
        var(--admin-surface-3);

    --bs-table-hover-color:
        #ffffff;

    --bs-table-hover-bg:
        var(--admin-hover);

    color:
        var(--admin-text) !important;

    border-color:
        var(--admin-border) !important;
}


/*
 * Bootstrap 5 paints the actual TD/TH cells,
 * not only the TR.
 */
html.dark .table > :not(caption) > * > * {
    color:
        var(--admin-text) !important;

    border-color:
        var(--admin-border) !important;

    box-shadow:
        inset 0 0 0 9999px
        var(--bs-table-bg-state,
            var(--bs-table-bg-type,
                var(--bs-table-accent-bg,
                    var(--bs-table-bg)
                )
            )
        ) !important;
}


/* Normal DataTables rows */
html.dark table.dataTable tbody tr,
html.dark table.dataTable tbody td {
    color:
        var(--admin-text) !important;

    border-color:
        var(--admin-border) !important;
}


/* Even rows */
html.dark table.dataTable tbody tr.even > *,
html.dark table.dataTable tbody tr:nth-child(even) > * {
    background-color:
        var(--admin-surface) !important;

    color:
        var(--admin-text) !important;
}


/* Odd / striped rows */
html.dark table.dataTable tbody tr.odd > *,
html.dark table.dataTable.table-striped
    tbody
    tr:nth-of-type(odd) > * {

    background-color:
        var(--admin-surface-2) !important;

    color:
        var(--admin-text) !important;
}


/* Header */
html.dark table.dataTable thead th,
html.dark table.dataTable thead td,
html.dark .table thead th {
    background:
        var(--admin-surface-2) !important;

    color:
        #f8fafc !important;

    border-color:
        var(--admin-border) !important;
}


/* Hover */
html.dark table.dataTable tbody tr:hover > *,
html.dark .table-hover tbody tr:hover > * {
    background:
        var(--admin-hover) !important;

    color:
        #ffffff !important;
}


/* DataTables controls */
html.dark .dataTables_wrapper
    .dataTables_length,
html.dark .dataTables_wrapper
    .dataTables_filter,
html.dark .dataTables_wrapper
    .dataTables_info {
    color:
        var(--admin-muted) !important;
}


html.dark .dataTables_wrapper
    .dataTables_filter input,
html.dark .dataTables_wrapper
    .dataTables_length select {

    background:
        var(--admin-input) !important;

    color:
        var(--admin-text) !important;

    border:
        1px solid
        var(--admin-border) !important;
}

/* =========================================================
   HOA CUSTOM CONFIRMATION MODAL
   Replaces native browser confirm() / "localhost says".
   ========================================================= */
#hoaConfirmModal {
    z-index: 1100 !important;
}

.modal-backdrop.hoa-confirm-backdrop {
    z-index: 1090 !important;
}

#hoaConfirmModal .modal-content {
    border-radius: 16px;
    overflow: hidden;
}

html.dark #hoaConfirmModal .modal-content {
    background: var(--admin-surface, #1f2937) !important;
    color: var(--admin-text, #f8fafc) !important;
    border: 1px solid var(--admin-border, #334155) !important;
}

html.dark #hoaConfirmModal .modal-header,
html.dark #hoaConfirmModal .modal-footer {
    border-color: var(--admin-border, #334155) !important;
}

html.dark #hoaConfirmModal .btn-light {
    background: var(--admin-surface-2, #334155) !important;
    color: var(--admin-text, #f8fafc) !important;
    border-color: var(--admin-border, #475569) !important;
}

</style>

<!-- ADMIN DARK MODE - keep this after all page CSS -->
<link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">

<script>
(function () {
    try {
        const savedTheme = localStorage.getItem('hoa-theme');

        const dark =
            savedTheme === 'dark' ||
            (
                !savedTheme &&
                window.matchMedia &&
                window.matchMedia('(prefers-color-scheme: dark)').matches
            );

        document.documentElement.classList.toggle('dark', dark);
    } catch (e) {}
})();
</script>

</head>

<body>

	<div class="header">
		<div class="header-left">
			<div class="menu-icon dw dw-menu"></div>
			<div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>
		</div>

<div class="header-right">

    <!-- DARK MODE TOGGLE -->
    <div class="admin-theme-switch">
        <button type="button"
                id="themeToggle"
                class="admin-theme-toggle"
                aria-label="Switch theme"
                title="Switch theme">
            <span id="themeIcon">☾</span>
        </button>
    </div>

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
		<div class="pd-ltr-20 ho-page-shell">

			<div class="ho-page-content">
				<div class="page-title-wrap">
				<div>
						<h2 class="h4 mb-1">Home Owner Management</h2>
						<div class="text-muted fw-semibold subtitle">Import Review & Duplicate Checking</div>
					</div>
				</div>

				<div class="card-box p-3">
				<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
					<div>
						<h5 class="mb-1">Homeowner Import</h5>
						<small class="text-muted">Upload .xlsx, .xls, or .csv. Imported residents are double-checked before they are pushed to active homeowner data.</small>
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
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
              <h6 class="fw-bold mb-0">Imported Residents for Review</h6>

              <?php if ($pendingImportedCount > 0): ?>
                <button
                  type="button"
                  class="btn btn-success"
                  id="pushAllImportedBtn"
                  data-count="<?= (int)$pendingImportedCount ?>"
                >
                  <i class="dw dw-upload1 me-1"></i>
                  Push All to Homeowner Data
                  <span class="badge bg-light text-success ms-1"><?= (int)$pendingImportedCount ?></span>
                </button>
              <?php endif; ?>
            </div>

            <div class="alert alert-info py-2 mb-3">
              <strong>Review flow:</strong>
              Double-check the imported residents. You may push them one by one, or use
              <strong>Push All to Homeowner Data</strong> to activate all valid pending imports at once.
              Duplicate records are excluded and remain available for separate review and archiving.
            </div>
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
                    <?php
                    [$queueBlock, $queueLot] = subdivision_block_lot($qrow);
                    $isPossibleOwnershipTransfer = false;

                    if (($qrow['source_type'] ?? '') === 'duplicate' && !empty($qrow['duplicate_homeowner_id'])) {
                        $linkedOwnerId = (int)$qrow['duplicate_homeowner_id'];
                        $linkedStmt = $conn->prepare(
                            "SELECT id, email, phase, block, lot, house_lot_number, status
                             FROM homeowners WHERE id=? LIMIT 1"
                        );
                        $linkedStmt->bind_param('i', $linkedOwnerId);
                        $linkedStmt->execute();
                        $linkedOwner = $linkedStmt->get_result()->fetch_assoc();
                        $linkedStmt->close();

                        if ($linkedOwner && (string)($linkedOwner['status'] ?? '') === 'approved') {
                            [$linkedBlock, $linkedLot] = subdivision_block_lot($linkedOwner);
                            $incomingEmail = strtolower(trim((string)($qrow['email'] ?? '')));
                            $linkedEmail = strtolower(trim((string)($linkedOwner['email'] ?? '')));
                            $isPossibleOwnershipTransfer =
                                $queueBlock > 0 && $queueLot > 0 &&
                                $queueBlock === $linkedBlock &&
                                $queueLot === $linkedLot &&
                                (string)($qrow['phase'] ?? '') === (string)($linkedOwner['phase'] ?? '') &&
                                $incomingEmail !== '' && $linkedEmail !== '' &&
                                $incomingEmail !== $linkedEmail;
                        }
                    }
                    ?>
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

    <?php if ($isPossibleOwnershipTransfer): ?>
        <span class="badge badge-warning">
            Possible Ownership Transfer
        </span>
    <?php else: ?>
        <span class="badge badge-danger">
            Duplicate Account
        </span>
    <?php endif; ?>

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
                    : 'Ready for Double Check'
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
        data-source="import_queue"
    >
        Review Conflict
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

        <?php if (!empty($manualTransferCandidates)): ?>
          <div class="mt-4 mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
              <div>
                <h6 class="fw-bold mb-1">Possible Ownership Transfers — Registered Households</h6>
                <small class="text-muted">Pending walk-in/manual registrations using a Block and Lot that already has an active homeowner.</small>
              </div>
              <span class="badge bg-warning text-dark"><?= count($manualTransferCandidates) ?> pending</span>
            </div>

            <div class="alert alert-warning py-2 mb-3">
              These records must <strong>not</strong> be normally approved. Review the current and incoming homeowner, send the two verification emails, verify ownership documents, then use <strong>Confirm Ownership Transfer</strong>.
            </div>

            <div class="table-responsive">
              <table id="manualTransferTable" class="table table-bordered table-striped align-middle" style="width:100%">
                <thead>
                  <tr>
                    <th>Incoming ID</th>
                    <th>Incoming Homeowner</th>
                    <th>Email</th>
                    <th>Property</th>
                    <th>Current Homeowner</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($manualTransferCandidates as $candidate): ?>
                  <?php
                    $incoming = $candidate['incoming'];
                    $currentOwner = $candidate['current_owner'];
                    $incomingName = trim(($incoming['first_name'] ?? '') . ' ' . ($incoming['middle_name'] ?? '') . ' ' . ($incoming['last_name'] ?? ''));
                    $currentName = trim(($currentOwner['first_name'] ?? '') . ' ' . ($currentOwner['middle_name'] ?? '') . ' ' . ($currentOwner['last_name'] ?? ''));
                    $incomingDisplayId = trim((string)($incoming['public_id'] ?? '')) ?: (phase_prefix((string)$incoming['phase']) . (int)$incoming['id']);
                  ?>
                  <tr>
                    <td><?= esc($incomingDisplayId) ?></td>
                    <td><?= esc($incomingName) ?></td>
                    <td><?= esc($incoming['email'] ?? '') ?></td>
                    <td><?= esc(($incoming['phase'] ?? '') . ' / Block ' . (int)$candidate['block'] . ' / Lot ' . (int)$candidate['lot']) ?></td>
                    <td>
                      <div class="fw-semibold"><?= esc($currentName) ?></div>
                      <small class="text-muted"><?= esc($currentOwner['public_id'] ?? ('#' . (int)$currentOwner['id'])) ?></small>
                    </td>
                    <td><span class="badge badge-warning">Possible Ownership Transfer</span></td>
                    <td>
                      <button
                        type="button"
                        class="btn btn-sm btn-warning reviewOwnershipTransferBtn"
                        data-id="<?= (int)$incoming['id'] ?>"
                        data-source="pending_homeowner"
                      >
                        Review Transfer
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>


				</div>
			</div>

			<footer class="footer-wrap pd-20 mb-20 card-box ho-footer">
				© Copyright South Meridian Homes All Rights Reserved
			</footer>
		</div>
	</div>

	<div class="modal fade" id="actionConfirmModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content" style="border-radius:14px; overflow:hidden;">
				<div class="modal-header">
					<h5 class="modal-title fw-bold" id="actionConfirmTitle">Push to Homeowner Data</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>

				<div class="modal-body">
					<p class="mb-3" id="actionConfirmText">Double-check this imported resident before pushing it to active homeowner data.</p>

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


  <div class="modal fade" id="pushAllImportedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="border-radius:14px; overflow:hidden;">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Push All Imported Homeowners</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <p class="mb-2">
            Push all valid imported homeowners currently waiting for review to active homeowner data and send each homeowner an account setup email?
          </p>
          <div class="alert alert-warning mb-0">
            <strong>Note:</strong>
            Duplicate imports will not be pushed. Each valid homeowner must receive the account setup email before that record is finalized.
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-success" id="confirmPushAllImportedBtn">
            Push All
          </button>
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
                    Resident Conflict Review
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


            <div class="modal-footer flex-wrap gap-2">

                <button
                    type="button"
                    class="btn btn-light"
                    data-bs-dismiss="modal">
                    Close
                </button>

                <button
                    type="button"
                    class="btn btn-outline-danger"
                    id="notifyDeleteDuplicateBtn"
                    data-id="">
                    Notify &amp; Archive as Duplicate
                </button>

                <button
                    type="button"
                    class="btn btn-success d-none"
                    id="startOwnershipTransferBtn"
                    data-id="">
                    Start Ownership Transfer Verification
                </button>

                <button
                    type="button"
                    class="btn btn-warning d-none"
                    id="manualVerifyOldOwnerBtn">
                    Verify Current Owner at HOA Office
                </button>

                <button
                    type="button"
                    class="btn btn-outline-danger d-none"
                    id="cancelOwnershipTransferBtn">
                    Cancel Transfer Verification
                </button>

                <button
                    type="button"
                    class="btn btn-success d-none"
                    id="finalizeOwnershipTransferBtn">
                    Confirm Ownership Transfer
                </button>

            </div>


        </div>

    </div>

</div>

<!-- =====================================================
     OWNERSHIP TRANSFER - MANUAL CURRENT OWNER VERIFICATION
     ===================================================== -->
<div class="modal fade" id="manualOwnershipVerifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:14px; overflow:hidden;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Verify Current Homeowner Manually</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    Use this only when the current homeowner cannot complete email verification. Verify the person at the HOA office or through valid ownership documents.
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Verification Method</label>
                    <select id="manualOwnershipVerifyMethod" class="form-select">
                        <option value="">Select method</option>
                        <option value="office">Verified at HOA Office</option>
                        <option value="documents">Verified Through Documents</option>
                        <option value="mixed">Office + Documents</option>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-semibold">Verification Notes</label>
                    <textarea id="manualOwnershipVerifyNotes" class="form-control" rows="4" placeholder="Example: Previous homeowner presented valid ID and signed the transfer acknowledgement at the HOA office."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="saveManualOwnershipVerifyBtn">Save Verification</button>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================
     OWNERSHIP TRANSFER - FINAL ADMIN CONFIRMATION
     ===================================================== -->
<div class="modal fade" id="finalizeOwnershipTransferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius:14px; overflow:hidden;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Final Ownership Transfer Confirmation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    Both required homeowner confirmations must be complete. Finalizing will preserve the previous homeowner as <strong>Former Owner</strong> and create/activate a new homeowner account for the incoming owner.
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" value="1" id="ownershipDocumentsVerified">
                    <label class="form-check-label fw-semibold" for="ownershipDocumentsVerified">
                        I verified the ownership-transfer documents (e.g. deed of sale/transfer documents, IDs, and relevant HOA proof).
                    </label>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Overall Verification Method</label>
                    <select id="finalOwnershipVerifyMethod" class="form-select">
                        <option value="">Select method</option>
                        <option value="email">Email Confirmations</option>
                        <option value="office">HOA Office Verification</option>
                        <option value="documents">Documents</option>
                        <option value="mixed">Mixed Verification</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Admin Notes</label>
                    <textarea id="finalOwnershipTransferNotes" class="form-control" rows="4" placeholder="Record what documents were checked and any important transfer details."></textarea>
                </div>
                <div class="alert alert-warning mb-0">
                    <strong>Important:</strong> Historical dues, complaints, rentals, parking records, and other transactions remain attached to the previous homeowner. They are not moved to the new homeowner.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmFinalizeOwnershipTransferBtn">Confirm Transfer</button>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================
     DUPLICATE NOTIFY / ARCHIVE CONFIRMATION MODAL
     ===================================================== -->
<div
    class="modal fade"
    id="duplicateDeleteConfirmModal"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered">

        <div
            class="modal-content"
            style="border-radius:14px; overflow:hidden;"
        >

            <div class="modal-header border-0 pb-0">

                <h5 class="modal-title fw-bold">
                    Confirm Duplicate Archive
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    id="duplicateDeleteCloseBtn"
                ></button>

            </div>


            <div class="modal-body text-center px-4 py-4">

                <div
                    class="mx-auto mb-3 d-flex align-items-center justify-content-center"
                    style="
                        width:70px;
                        height:70px;
                        border-radius:50%;
                        background:#fff3cd;
                        color:#dc3545;
                        font-size:32px;
                    "
                >
                    <i class="dw dw-warning"></i>
                </div>


                <h5 class="fw-bold mb-2">
                    Notify both parties and archive duplicate?
                </h5>


                <p class="text-muted mb-0">
                    Email notifications will be sent to both the imported resident and the existing registered homeowner before the duplicate import record is archived.
                </p>


                <div class="alert alert-warning mt-3 mb-0 text-start">

                    <strong>Important:</strong>

                    No homeowner or imported record will be deleted. The duplicate import will only be moved to the archive for record-keeping and audit history.

                </div>

            </div>


            <div
                class="modal-footer border-0 justify-content-center pb-4"
            >

                <button
                    type="button"
                    class="btn btn-light px-4"
                    id="duplicateDeleteCancelBtn"
                >
                    Cancel
                </button>


                <button
                    type="button"
                    class="btn btn-danger px-4"
                    id="duplicateDeleteConfirmBtn"
                >
                    Notify Both &amp; Archive
                </button>

            </div>

        </div>

    </div>
</div>



<!-- =====================================================
     SHARED HOA CONFIRMATION MODAL (THIS PAGE ONLY)
     ===================================================== -->
<div class="modal fade" id="hoaConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="hoaConfirmTitle">Confirm Action</h5>
                <button type="button" class="btn-close" id="hoaConfirmCloseBtn" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="d-flex gap-3 align-items-start">
                    <div class="flex-shrink-0 d-flex align-items-center justify-content-center"
                         style="width:46px;height:46px;border-radius:50%;background:#fff3cd;color:#b45309;font-size:22px;">
                        <i class="dw dw-warning"></i>
                    </div>
                    <div class="flex-grow-1">
                        <p class="mb-0" id="hoaConfirmMessage">Are you sure?</p>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" id="hoaConfirmCancelBtn">Cancel</button>
                <button type="button" class="btn btn-success" id="hoaConfirmOkBtn">Confirm</button>
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
    <script src="vendors/scripts/admin_theme.js"></script>

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


/**
 * Custom promise-based confirmation dialog used by ownership-transfer actions.
 * This intentionally avoids the browser-native confirm() popup that displays
 * "localhost says" while testing locally.
 */
function hoaConfirm(message, options = {}) {
    return new Promise(function (resolve) {
        const modalEl = document.getElementById('hoaConfirmModal');
        const titleEl = document.getElementById('hoaConfirmTitle');
        const messageEl = document.getElementById('hoaConfirmMessage');
        const okBtn = document.getElementById('hoaConfirmOkBtn');
        const cancelBtn = document.getElementById('hoaConfirmCancelBtn');
        const closeBtn = document.getElementById('hoaConfirmCloseBtn');

        if (
            !modalEl ||
            !titleEl ||
            !messageEl ||
            !okBtn ||
            !cancelBtn ||
            !closeBtn ||
            typeof bootstrap === 'undefined'
        ) {
            console.error('HOA confirmation modal is unavailable.');
            showToast('Unable to open the confirmation dialog. Refresh the page and try again.', 'error');
            resolve(false);
            return;
        }

        const title = options.title || 'Confirm Action';
        const confirmText = options.confirmText || 'Confirm';
        const cancelText = options.cancelText || 'Cancel';
        const danger = options.danger === true;

        titleEl.textContent = title;
        messageEl.textContent = String(message || 'Are you sure?');
        okBtn.textContent = confirmText;
        cancelBtn.textContent = cancelText;

        okBtn.classList.remove('btn-success', 'btn-danger', 'btn-warning', 'btn-primary');
        okBtn.classList.add(danger ? 'btn-danger' : 'btn-success');

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl, {
            backdrop: 'static',
            keyboard: false,
            focus: true
        });

        let settled = false;

        const clearHandlers = function () {
            okBtn.onclick = null;
            cancelBtn.onclick = null;
            closeBtn.onclick = null;
        };

        const finish = function (result) {
            if (settled) return;
            settled = true;
            clearHandlers();
            resolve(result);
            modal.hide();
        };

        okBtn.onclick = function () {
            finish(true);
        };

        cancelBtn.onclick = function () {
            finish(false);
        };

        closeBtn.onclick = function () {
            finish(false);
        };

        const hiddenHandler = function () {
            modalEl.removeEventListener('hidden.bs.modal', hiddenHandler);
            document
                .querySelectorAll('.modal-backdrop.hoa-confirm-backdrop')
                .forEach(function (backdrop) {
                    backdrop.classList.remove('hoa-confirm-backdrop');
                });

            if (!settled) {
                settled = true;
                clearHandlers();
                resolve(false);
            }
        };

        modalEl.addEventListener('hidden.bs.modal', hiddenHandler);

        modal.show();

        // When another Bootstrap modal is already open (Resident Conflict Review),
        // keep this confirmation dialog and its backdrop above it.
        setTimeout(function () {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length) {
                backdrops[backdrops.length - 1].classList.add('hoa-confirm-backdrop');
            }
            okBtn.focus();
        }, 80);
    });
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
            let possibleTransfers = 0;
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

                possibleTransfers +=
                    Number(
                        data.possible_transfers || 0
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
                `Import finished: ${imported} added for double-checking`;

            if (duplicates > 0) {

                summary +=
                    `, ${duplicates} duplicate(s) detected`;
            }

            if (possibleTransfers > 0) {

                summary +=
                    `, ${possibleTransfers} possible ownership transfer(s)`;
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

            if (possibleTransfers > 0) {

                setImportStatus(
                    summary +
                    ' Property conflicts are listed below. Review possible ownership transfers before archiving anything as a duplicate.',
                    'warning'
                );

                showToast(
                    `${possibleTransfers} possible ownership transfer(s) need review.`,
                    'warning'
                );

            } else if (duplicates > 0) {

                setImportStatus(
                    summary +
                    ' Duplicate residents are listed below for review and archiving.',
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
             * - valid imports remain in the review list
             *   until they are pushed to active homeowner data
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

        /*
         * Push every valid pending Excel import in one action.
         * Duplicate imports are intentionally excluded by the backend.
         */
        const pushAllBtn = document.getElementById('pushAllImportedBtn');
        const pushAllModalEl = document.getElementById('pushAllImportedModal');
        const confirmPushAllBtn = document.getElementById('confirmPushAllImportedBtn');

        const pushAllModal = pushAllModalEl
            ? new bootstrap.Modal(pushAllModalEl, {
                backdrop: 'static',
                keyboard: false
            })
            : null;

        pushAllBtn?.addEventListener('click', function () {
            pushAllModal?.show();
        });

        confirmPushAllBtn?.addEventListener('click', async function () {
            const button = this;
            const originalText = button.textContent;

            button.disabled = true;
            button.textContent = 'Pushing all...';

            try {
                const body = new URLSearchParams();
                body.set('csrf', homeownerImportCsrf);

                const response = await fetch(
                    'push_all_imported_homeowners.php',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded;charset=UTF-8',
                            'Accept': 'application/json'
                        },
                        body: body.toString()
                    }
                );

                const responseText = await response.text();
                let data = null;

                try {
                    data = JSON.parse(responseText);
                } catch (error) {
                    console.error('Raw bulk push response:', responseText);
                    throw new Error('Server returned an invalid response.');
                }

                if (!response.ok || !data || !data.success) {
                    throw new Error(
                        data && data.message
                            ? data.message
                            : 'Unable to push all imported homeowners.'
                    );
                }

                pushAllModal?.hide();

                showToast(
                    data.message ||
                    `${Number(data.pushed || 0)} imported homeowner(s) pushed successfully.`,
                    Number(data.failed || 0) > 0
                        ? 'warning'
                        : 'success'
                );

                setTimeout(function () {
                    location.reload();
                }, 900);

            } catch (error) {
                console.error('Bulk push error:', error);

                showToast(
                    error.message ||
                    'Unable to push all imported homeowners.',
                    'error'
                );

                button.disabled = false;
                button.textContent = originalText;
            }
        });

        pushAllModalEl?.addEventListener('hidden.bs.modal', function () {
            if (confirmPushAllBtn) {
                confirmPushAllBtn.disabled = false;
                confirmPushAllBtn.textContent = 'Push All';
            }
        });

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

        if (
            $.fn.DataTable &&
            $('#manualTransferTable').length &&
            !$.fn.DataTable.isDataTable('#manualTransferTable')
        ) {
            $('#manualTransferTable').DataTable({
                responsive: true,
                pageLength: 10,
                order: [],
                columnDefs: [
                    { orderable: false, targets: 6 }
                ]
            });
        }

		if ($.fn.DataTable && $('#archiveTable').length && !$.fn.DataTable.isDataTable('#archiveTable')) {
			$('#archiveTable').DataTable({
				responsive: true,
				pageLength: 10,
				order: [[0, 'desc']]
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

		$(document).on('click', '.finalizeImportedHomeowner', function (e) {
			e.preventDefault();

			const id = $(this).data('id');
			if (!id) return;

			pendingAction = { id, status: 'approved' };

			confirmTitleEl.textContent = 'Push to Homeowner Data';
			confirmTextEl.textContent  = 'You have double-checked this imported resident. Push this record to active homeowner data and send the homeowner an account setup email?';
			confirmBtnEl.classList.remove('btn-danger');
			confirmBtnEl.classList.add('btn-success');
			confirmBtnEl.textContent = 'Push to Homeowner Data';
			reasonWrapEl.style.display = 'none';
			reasonErrorEl.style.display = 'none';
			reasonInputEl.value = '';

			confirmModal.show();

			setTimeout(function () {
				const backdrops = document.querySelectorAll('.modal-backdrop');
				if (backdrops.length > 1) {
					backdrops[backdrops.length - 1].classList.add('confirm-top');
				}
				confirmBtnEl.focus();
			}, 120);
		});

		confirmBtnEl.addEventListener('click', function () {

    const { id, status } = pendingAction;

    if (!id || status !== 'approved') {
        return;
    }

    confirmBtnEl.disabled = true;

    const oldText = confirmBtnEl.textContent;
    confirmBtnEl.textContent = 'Pushing...';

    /*
     * IMPORTANT:
     * The database still uses status="approved" for compatibility with the
     * rest of the existing South Meridian system. In the UI this is no longer
     * an approval workflow. It simply means the imported record has passed
     * double-checking and is now pushed to active homeowner data.
     */
    $.ajax({

        url: 'finalize_migrated_homeowner.php',
        type: 'POST',
        dataType: 'json',

        data: {
            id: id,
            csrf: homeownerImportCsrf
        },

        success: function (res) {

            if (!res || !res.success) {
                showToast(
                    res && res.message
                        ? res.message
                        : 'Unable to push the imported resident.',
                    'error'
                );

                confirmBtnEl.disabled = false;
                confirmBtnEl.textContent = oldText;
                return;
            }

            showToast(
                res.message || 'Imported resident was pushed to homeowner data and the account setup email was sent successfully.',
                'success'
            );

            confirmModal.hide();

            const viewModalEl = document.getElementById('viewHomeownerModal');
            const viewModalInstance = bootstrap.Modal.getInstance(viewModalEl);

            if (viewModalInstance) {
                viewModalInstance.hide();
            }

            setTimeout(function () {
                location.reload();
            }, 800);
        },

        error: function (xhr) {

            console.error('Push to homeowner data request failed.');
            console.error('HTTP Status:', xhr.status);
            console.error('Server Response:', xhr.responseText);

            let message = 'Unable to push the imported resident. Please try again.';

            if (xhr.responseText) {
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data && data.message) {
                        message = data.message;
                    }
                } catch (error) {
                    if (xhr.status === 401) {
                        message = 'Your admin session expired. Please login again.';
                    } else if (xhr.status === 403) {
                        message = 'Access denied or security token expired. Refresh the page and try again.';
                    } else if (xhr.status === 404) {
                        message = 'The homeowner update endpoint was not found.';
                    } else if (xhr.status === 500) {
                        message = 'Server error occurred while pushing the imported resident. Check the PHP error log or email configuration.';
                    }
                }
            }

            showToast(message, 'error');
            confirmBtnEl.disabled = false;
            confirmBtnEl.textContent = oldText;
        }
    });

});

		confirmModalEl.addEventListener('hidden.bs.modal', function () {
			pendingAction = { id: null, status: null };
			confirmBtnEl.disabled = false;
			confirmBtnEl.textContent = 'Push to Homeowner Data';
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
		
const duplicateResidentModalEl = document.getElementById('duplicateResidentModal');
const duplicateResidentModal = duplicateResidentModalEl ? new bootstrap.Modal(duplicateResidentModalEl) : null;
const duplicateResidentContent = document.getElementById('duplicateResidentContent');
const notifyDeleteDuplicateBtn = document.getElementById('notifyDeleteDuplicateBtn');
const startOwnershipTransferBtn = document.getElementById('startOwnershipTransferBtn');
const manualVerifyOldOwnerBtn = document.getElementById('manualVerifyOldOwnerBtn');
const cancelOwnershipTransferBtn = document.getElementById('cancelOwnershipTransferBtn');
const finalizeOwnershipTransferBtn = document.getElementById('finalizeOwnershipTransferBtn');

const manualOwnershipVerifyModalEl = document.getElementById('manualOwnershipVerifyModal');
const manualOwnershipVerifyModal = manualOwnershipVerifyModalEl ? new bootstrap.Modal(manualOwnershipVerifyModalEl, {backdrop:'static'}) : null;
const finalizeOwnershipTransferModalEl = document.getElementById('finalizeOwnershipTransferModal');
const finalizeOwnershipTransferModal = finalizeOwnershipTransferModalEl ? new bootstrap.Modal(finalizeOwnershipTransferModalEl, {backdrop:'static'}) : null;

let currentConflictQueueId = 0;
let currentConflictSource = 'import_queue';
let currentOwnershipTransfer = null;
let currentConflictMatchType = 'duplicate_account';

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function setConflictButton(button, visible) {
    if (!button) return;
    button.classList.toggle('d-none', !visible);
}

function ownershipStatusBadge(label, rawStatus) {
    const cls = rawStatus === 'confirmed' || rawStatus === 'manual_verified'
        ? 'bg-success'
        : rawStatus === 'denied'
            ? 'bg-danger'
            : 'bg-warning text-dark';
    return `<span class="badge ${cls}">${escapeHtml(label || rawStatus || 'Pending')}</span>`;
}

function resetConflictActions(queueId, sourceType = 'import_queue') {
    currentConflictQueueId = Number(queueId || 0);
    currentConflictSource = sourceType === 'pending_homeowner' ? 'pending_homeowner' : 'import_queue';
    currentOwnershipTransfer = null;
    currentConflictMatchType = 'duplicate_account';

    if (notifyDeleteDuplicateBtn) {
        notifyDeleteDuplicateBtn.dataset.id = String(currentConflictQueueId);
        notifyDeleteDuplicateBtn.disabled = true;
        notifyDeleteDuplicateBtn.textContent = 'Notify & Archive as Duplicate';
    }
    if (startOwnershipTransferBtn) {
        startOwnershipTransferBtn.dataset.id = String(currentConflictQueueId);
        startOwnershipTransferBtn.disabled = false;
        startOwnershipTransferBtn.textContent = 'Start Ownership Transfer Verification';
    }

    setConflictButton(notifyDeleteDuplicateBtn, false);
    setConflictButton(startOwnershipTransferBtn, false);
    setConflictButton(manualVerifyOldOwnerBtn, false);
    setConflictButton(cancelOwnershipTransferBtn, false);
    setConflictButton(finalizeOwnershipTransferBtn, false);
}

function renderConflictActions(data) {
    const transfer = data.transfer || null;
    currentOwnershipTransfer = transfer;
    currentConflictMatchType = data.match_type || 'duplicate_account';

    if (currentConflictMatchType !== 'possible_ownership_transfer') {
        setConflictButton(notifyDeleteDuplicateBtn, currentConflictSource === 'import_queue');
        if (notifyDeleteDuplicateBtn) notifyDeleteDuplicateBtn.disabled = false;
        return;
    }

    if (!data.schema_ready) {
        setConflictButton(startOwnershipTransferBtn, true);
        startOwnershipTransferBtn.disabled = true;
        startOwnershipTransferBtn.textContent = 'Run Ownership Transfer SQL Setup First';
        setConflictButton(notifyDeleteDuplicateBtn, currentConflictSource === 'import_queue');
        if (notifyDeleteDuplicateBtn) notifyDeleteDuplicateBtn.disabled = false;
        return;
    }

    if (!transfer) {
        setConflictButton(startOwnershipTransferBtn, true);
        setConflictButton(notifyDeleteDuplicateBtn, currentConflictSource === 'import_queue');
        if (notifyDeleteDuplicateBtn) notifyDeleteDuplicateBtn.disabled = false;
        return;
    }

    const status = String(transfer.status || '');
    const active = status === 'awaiting_confirmation' || status === 'ready_for_admin';

    if (status === 'awaiting_confirmation') {
        setConflictButton(startOwnershipTransferBtn, true);
        startOwnershipTransferBtn.textContent = 'Resend Pending Verification Email(s)';
        setConflictButton(manualVerifyOldOwnerBtn, transfer.old_confirmation === 'pending');
        setConflictButton(cancelOwnershipTransferBtn, true);
        setConflictButton(notifyDeleteDuplicateBtn, false);
    } else if (status === 'ready_for_admin' || transfer.ready_for_admin) {
        setConflictButton(finalizeOwnershipTransferBtn, true);
        setConflictButton(cancelOwnershipTransferBtn, true);
        setConflictButton(notifyDeleteDuplicateBtn, false);
    } else if (status === 'denied' || status === 'cancelled') {
        setConflictButton(startOwnershipTransferBtn, true);
        startOwnershipTransferBtn.textContent = 'Restart Ownership Transfer Verification';
        setConflictButton(notifyDeleteDuplicateBtn, currentConflictSource === 'import_queue');
        if (notifyDeleteDuplicateBtn) notifyDeleteDuplicateBtn.disabled = false;
    } else if (status === 'completed') {
        setConflictButton(notifyDeleteDuplicateBtn, false);
    } else if (!active) {
        setConflictButton(notifyDeleteDuplicateBtn, currentConflictSource === 'import_queue');
        if (notifyDeleteDuplicateBtn) notifyDeleteDuplicateBtn.disabled = false;
    }
}

function transferStatusHtml(transfer) {
    if (!transfer) {
        return `
            <div class="alert alert-light border mt-3 mb-0">
                Ownership-transfer verification has not started yet.
            </div>`;
    }

    const expires = transfer.token_expires_at
        ? escapeHtml(transfer.token_expires_at)
        : '—';

    return `
        <div class="card border-success mt-3">
            <div class="card-header fw-bold">Ownership Transfer Verification</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <small class="text-muted d-block">Current Homeowner</small>
                        ${ownershipStatusBadge(transfer.old_confirmation_label, transfer.old_confirmation)}
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Incoming Homeowner</small>
                        ${ownershipStatusBadge(transfer.new_confirmation_label, transfer.new_confirmation)}
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Transfer Status</small>
                        <span class="fw-semibold">${escapeHtml(String(transfer.status || '').replaceAll('_',' '))}</span>
                    </div>
                </div>
                <div class="small text-muted mt-3">Email verification expires: ${expires}</div>
                ${transfer.admin_notes ? `<div class="mt-2"><small class="text-muted">Admin Notes</small><div>${escapeHtml(transfer.admin_notes)}</div></div>` : ''}
            </div>
        </div>`;
}

$(document).on('click', '.viewDuplicateResidentBtn, .reviewOwnershipTransferBtn', async function () {
    const id = Number(this.dataset.id || 0);
    const sourceType = this.dataset.source === 'pending_homeowner' ? 'pending_homeowner' : 'import_queue';
    if (!id) return;

    resetConflictActions(id, sourceType);
    duplicateResidentContent.innerHTML = '<div class="text-muted">Loading resident conflict information...</div>';
    duplicateResidentModal?.show();

    try {
        const response = await fetch('get_duplicate_homeowner.php?source=' + encodeURIComponent(sourceType) + '&id=' + encodeURIComponent(id), {
            headers: {'Accept': 'application/json'}
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Unable to load resident conflict information.');
        }

        const q = data.queue;
        const existing = data.existing;
        const isTransfer = data.match_type === 'possible_ownership_transfer';

        const topAlert = isTransfer
            ? `<div class="alert alert-info">
                 <strong>Possible Ownership Transfer:</strong>
                 this is a different resident/email using the same active Phase, Block, and Lot as the current registered homeowner.
                 Do not replace the current homeowner directly. Verify both parties first.
               </div>`
            : `<div class="alert alert-warning">
                 <strong>Duplicate Account:</strong>
                 the incoming record matches an existing homeowner account. Review both records before archiving the duplicate.
               </div>`;

        duplicateResidentContent.innerHTML = `
            ${topAlert}
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card h-100 ${isTransfer ? 'border-info' : 'border-warning'}">
                        <div class="card-header fw-bold">${escapeHtml(q.source_label || 'Incoming Homeowner')}</div>
                        <div class="card-body">
                            <div class="mb-2"><small class="text-muted">Name</small><div class="fw-semibold">${escapeHtml(q.name)}</div></div>
                            <div class="mb-2"><small class="text-muted">Email</small><div class="fw-semibold text-break">${escapeHtml(q.email)}</div></div>
                            <div class="mb-2"><small class="text-muted">Contact</small><div class="fw-semibold">${escapeHtml(q.contact_number)}</div></div>
                            <div class="mb-2"><small class="text-muted">Phase</small><div class="fw-semibold">${escapeHtml(q.phase)}</div></div>
                            <div class="mb-2"><small class="text-muted">Address</small><div class="fw-semibold">Block ${escapeHtml(q.block)}, Lot ${escapeHtml(q.lot)}${q.street ? ' · ' + escapeHtml(q.street) : ''}</div></div>
                            <div><small class="text-muted">Residential Type</small><div class="fw-semibold">${escapeHtml(q.residential_type)}</div></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 border-success">
                        <div class="card-header fw-bold">Current Registered Homeowner</div>
                        <div class="card-body">
                            <div class="mb-2"><small class="text-muted">Homeowner ID</small><div class="fw-semibold">${escapeHtml(existing.public_id || existing.id)}</div></div>
                            <div class="mb-2"><small class="text-muted">Name</small><div class="fw-semibold">${escapeHtml(existing.name)}</div></div>
                            <div class="mb-2"><small class="text-muted">Email</small><div class="fw-semibold text-break">${escapeHtml(existing.email)}</div></div>
                            <div class="mb-2"><small class="text-muted">Contact</small><div class="fw-semibold">${escapeHtml(existing.contact_number)}</div></div>
                            <div class="mb-2"><small class="text-muted">Phase</small><div class="fw-semibold">${escapeHtml(existing.phase)}</div></div>
                            <div class="mb-2"><small class="text-muted">Address</small><div class="fw-semibold">Block ${escapeHtml(existing.block)}, Lot ${escapeHtml(existing.lot)}${existing.street ? ' · ' + escapeHtml(existing.street) : ''}</div></div>
                            <div><small class="text-muted">Status</small><div class="fw-semibold">${escapeHtml(existing.status)}</div></div>
                        </div>
                    </div>
                </div>
            </div>
            ${isTransfer ? transferStatusHtml(data.transfer) : ''}
        `;

        renderConflictActions(data);

    } catch (err) {
        duplicateResidentContent.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml(err.message || 'Unable to load resident conflict information.') + '</div>';
    }
});

async function postOwnershipTransferAction(payload) {
    const body = new URLSearchParams();
    Object.entries(payload).forEach(([key, value]) => body.set(key, String(value ?? '')));
    body.set('csrf', homeownerImportCsrf);

    const response = await fetch('ownership_transfer_admin_action.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body: body.toString()
    });
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); }
    catch (_) { throw new Error('Server returned an invalid response.'); }
    if (!response.ok || !data.success) throw new Error(data.message || 'Ownership-transfer action failed.');
    return data;
}

startOwnershipTransferBtn?.addEventListener('click', async function () {
    const id = Number(this.dataset.id || currentConflictQueueId || 0);
    if (!id) return;
    const sendVerification = await hoaConfirm(
        'Send ownership-transfer verification email(s) to the current and incoming homeowner?',
        {
            title: 'Start Ownership Transfer Verification',
            confirmText: 'Send Verification',
            cancelText: 'Cancel'
        }
    );
    if (!sendVerification) return;

    const original = this.textContent;
    this.disabled = true;
    this.textContent = 'Sending verification...';
    try {
        const body = new URLSearchParams({id:String(id), source:currentConflictSource, csrf:homeownerImportCsrf});
        const response = await fetch('start_ownership_transfer.php', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
            body:body.toString()
        });
        const text = await response.text();
        let data;
        try { data = JSON.parse(text); }
        catch (_) { throw new Error('Server returned an invalid response.'); }
        if (!response.ok || !data.success) throw new Error(data.message || 'Unable to start ownership transfer verification.');
        showToast(data.message, 'success');
        duplicateResidentModal?.hide();
        setTimeout(() => location.reload(), 900);
    } catch (error) {
        showToast(error.message || 'Unable to start ownership transfer verification.', 'error');
        this.disabled = false;
        this.textContent = original;
    }
});

manualVerifyOldOwnerBtn?.addEventListener('click', function () {
    document.getElementById('manualOwnershipVerifyMethod').value = '';
    document.getElementById('manualOwnershipVerifyNotes').value = '';
    duplicateResidentModal?.hide();
    manualOwnershipVerifyModal?.show();
});

document.getElementById('saveManualOwnershipVerifyBtn')?.addEventListener('click', async function () {
    const transferId = Number(currentOwnershipTransfer?.id || 0);
    const method = document.getElementById('manualOwnershipVerifyMethod').value;
    const notes = document.getElementById('manualOwnershipVerifyNotes').value.trim();
    if (!transferId) return showToast('No ownership transfer selected.', 'error');
    if (!method || !notes) return showToast('Select a verification method and enter verification notes.', 'warning');

    const original = this.textContent;
    this.disabled = true;
    this.textContent = 'Saving...';
    try {
        const data = await postOwnershipTransferAction({
            action:'manual_verify_old', transfer_id:transferId,
            verification_method:method, notes:notes
        });
        manualOwnershipVerifyModal?.hide();
        showToast(data.message, 'success');
        setTimeout(() => location.reload(), 800);
    } catch (error) {
        showToast(error.message, 'error');
        this.disabled = false;
        this.textContent = original;
    }
});

cancelOwnershipTransferBtn?.addEventListener('click', async function () {
    const transferId = Number(currentOwnershipTransfer?.id || 0);
    if (!transferId) return;
    const cancelTransfer = await hoaConfirm(
        'Cancel this ownership-transfer verification? No homeowner record will be deleted.',
        {
            title: 'Cancel Ownership Transfer',
            confirmText: 'Cancel Verification',
            cancelText: 'Keep Verification',
            danger: true
        }
    );
    if (!cancelTransfer) return;
    this.disabled = true;
    try {
        const data = await postOwnershipTransferAction({action:'cancel', transfer_id:transferId, notes:'Cancelled from Resident Conflict Review.'});
        showToast(data.message, 'success');
        duplicateResidentModal?.hide();
        setTimeout(() => location.reload(), 800);
    } catch (error) {
        showToast(error.message, 'error');
        this.disabled = false;
    }
});

finalizeOwnershipTransferBtn?.addEventListener('click', function () {
    document.getElementById('ownershipDocumentsVerified').checked = false;
    document.getElementById('finalOwnershipVerifyMethod').value = currentOwnershipTransfer?.verification_method || '';
    document.getElementById('finalOwnershipTransferNotes').value = currentOwnershipTransfer?.admin_notes || '';
    duplicateResidentModal?.hide();
    finalizeOwnershipTransferModal?.show();
});

document.getElementById('confirmFinalizeOwnershipTransferBtn')?.addEventListener('click', async function () {
    const transferId = Number(currentOwnershipTransfer?.id || 0);
    const documentsVerified = document.getElementById('ownershipDocumentsVerified').checked;
    const method = document.getElementById('finalOwnershipVerifyMethod').value;
    const notes = document.getElementById('finalOwnershipTransferNotes').value.trim();

    if (!transferId) return showToast('No ownership transfer selected.', 'error');
    if (!documentsVerified) return showToast('Confirm that you verified the ownership-transfer documents.', 'warning');
    if (!method || !notes) return showToast('Select the verification method and enter admin notes.', 'warning');
    const finalizeTransfer = await hoaConfirm(
        'Finalize this ownership transfer? The previous homeowner will become Former Owner and the incoming homeowner will become the active homeowner for this property.',
        {
            title: 'Confirm Ownership Transfer',
            confirmText: 'Finalize Transfer',
            cancelText: 'Go Back',
            danger: false
        }
    );
    if (!finalizeTransfer) return;

    const original = this.textContent;
    this.disabled = true;
    this.textContent = 'Finalizing transfer...';
    try {
        const data = await postOwnershipTransferAction({
            action:'finalize', transfer_id:transferId,
            documents_verified:1, verification_method:method, notes:notes
        });
        finalizeOwnershipTransferModal?.hide();
        showToast(data.message, 'success');
        setTimeout(() => location.reload(), 1000);
    } catch (error) {
        showToast(error.message, 'error');
        this.disabled = false;
        this.textContent = original;
    }
});

/* =========================================================
   DUPLICATE ARCHIVE CONFIRMATION MODAL
   ========================================================= */

const duplicateDeleteConfirmModalEl =
    document.getElementById(
        'duplicateDeleteConfirmModal'
    );

const duplicateDeleteConfirmModal =
    duplicateDeleteConfirmModalEl
        ? new bootstrap.Modal(
            duplicateDeleteConfirmModalEl,
            {
                backdrop: 'static',
                keyboard: false
            }
        )
        : null;


const duplicateDeleteConfirmBtn =
    document.getElementById(
        'duplicateDeleteConfirmBtn'
    );

const duplicateDeleteCancelBtn =
    document.getElementById(
        'duplicateDeleteCancelBtn'
    );

const duplicateDeleteCloseBtn =
    document.getElementById(
        'duplicateDeleteCloseBtn'
    );


let pendingDuplicateDeleteId = 0;


/*
|--------------------------------------------------------------------------
| Click Notify Both & Archive from duplicate profile
|--------------------------------------------------------------------------
*/

notifyDeleteDuplicateBtn?.addEventListener(
    'click',
    function () {

        if (currentConflictSource !== 'import_queue') {
            showToast('Manual registrations are handled through ownership-transfer verification, not duplicate import archiving.', 'warning');
            return;
        }

        const id =
            Number(
                this.dataset.id || 0
            );

        if (!id) {
            return;
        }


        pendingDuplicateDeleteId =
            id;


        /*
         * Close the duplicate review first,
         * then show our custom confirmation modal.
         */
        if (duplicateResidentModalEl) {

            duplicateResidentModalEl
                .addEventListener(
                    'hidden.bs.modal',
                    function showDeleteConfirmation() {

                        duplicateDeleteConfirmModal
                            ?.show();

                    },
                    {
                        once: true
                    }
                );


            duplicateResidentModal
                ?.hide();

        } else {

            duplicateDeleteConfirmModal
                ?.show();
        }
    }
);


/*
|--------------------------------------------------------------------------
| Cancel archive confirmation
|--------------------------------------------------------------------------
*/

function cancelDuplicateDelete() {

    pendingDuplicateDeleteId =
        0;


    if (
        duplicateDeleteConfirmModalEl
    ) {

        duplicateDeleteConfirmModalEl
            .addEventListener(
                'hidden.bs.modal',
                function reopenDuplicateReview() {

                    duplicateResidentModal
                        ?.show();

                },
                {
                    once: true
                }
            );
    }


    duplicateDeleteConfirmModal
        ?.hide();
}


duplicateDeleteCancelBtn
    ?.addEventListener(
        'click',
        cancelDuplicateDelete
    );


duplicateDeleteCloseBtn
    ?.addEventListener(
        'click',
        cancelDuplicateDelete
    );


/*
|--------------------------------------------------------------------------
| Confirm Notify Both & Archive
|--------------------------------------------------------------------------
*/

duplicateDeleteConfirmBtn
    ?.addEventListener(
        'click',
        async function () {

            const id =
                Number(
                    pendingDuplicateDeleteId || 0
                );

            if (!id) {

                showToast(
                    'Invalid duplicate record.',
                    'error'
                );

                return;
            }


            const button =
                this;

            const originalText =
                button.textContent;


            button.disabled =
                true;

            button.textContent =
                'Sending notification...';


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
                        'notify_archive_duplicate_homeowner.php',
                        {
                            method:
                                'POST',

                            headers: {
                                'Content-Type':
                                    'application/x-www-form-urlencoded;charset=UTF-8'
                            },

                            body:
                                body.toString()
                        }
                    );


                /*
                |--------------------------------------------------------------------------
                | Read response
                |--------------------------------------------------------------------------
                */

                const responseText =
                    await response.text();


                let data;


                try {

                    data =
                        JSON.parse(
                            responseText
                        );

                } catch (error) {

                    console.error(
                        'Raw duplicate server response:',
                        responseText
                    );


                    throw new Error(
                        'Server returned an invalid response.'
                    );
                }


                if (
                    !response.ok ||
                    !data.success
                ) {

                    throw new Error(
                        data.message ||
                        'Failed to archive duplicate.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | Success
                |--------------------------------------------------------------------------
                */

                pendingDuplicateDeleteId =
                    0;


                duplicateDeleteConfirmModal
                    ?.hide();


                if (
                    data.email_sent === false
                ) {

                    console.error(
                        'Email error:',
                        data.email_error || ''
                    );


                    showToast(
                        data.message ||
                        'Duplicate archived, but one or both email notifications could not be sent.',
                        'warning'
                    );

                } else {

                    showToast(
                        data.message ||
                        'Both parties were notified and the duplicate record was archived successfully.',
                        'success'
                    );
                }


                /*
                 * Reload page so the archived duplicate
                 * leaves the active duplicate review list.
                 */
                setTimeout(
                    function () {

                        location.reload();

                    },
                    1200
                );


            } catch (error) {

                console.error(
                    'Duplicate archive error:',
                    error
                );


                showToast(
                    error.message ||
                    'Unable to archive duplicate.',
                    'error'
                );


                button.disabled =
                    false;

                button.textContent =
                    originalText;
            }
        }
    );


/*
|--------------------------------------------------------------------------
| Reset confirmation button when modal closes
|--------------------------------------------------------------------------
*/

duplicateDeleteConfirmModalEl
    ?.addEventListener(
        'hidden.bs.modal',
        function () {

            if (
                duplicateDeleteConfirmBtn
            ) {

                duplicateDeleteConfirmBtn.disabled =
                    false;

                duplicateDeleteConfirmBtn.textContent =
                    'Notify & Archive as Duplicate';
            }
        }
    );

		</script>
</body>
</html>