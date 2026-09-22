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


mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!function_exists('esc')) {
    function esc($v): string {
        return htmlspecialchars(
            (string)$v,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
// prevent undefined $view in sidebar
$view = $_GET['view'] ?? '';

function phase_prefix(string $phase): string {
  $n = (int) filter_var($phase, FILTER_SANITIZE_NUMBER_INT);
  return $n > 0 ? ('P'.$n) : 'P';
}

function subdivision_block_lot(array $record): array
{
    $block = (int)($record['block'] ?? 0);
    $lot   = (int)($record['lot'] ?? 0);

    /*
     * Fallback for older records that only stored
     * "Block 1 Lot 2" or "Blk 1 Lot 2".
     */
    if ($block <= 0 || $lot <= 0) {

        $legacy = trim(
            (string)($record['house_lot_number'] ?? '')
        );

        if (
            preg_match(
                '/(?:block|blk|b)\s*[-:]?\s*(\d+)\D+(?:lot|l)\s*[-:]?\s*(\d+)/i',
                $legacy,
                $match
            )
        ) {
            $block = (int)$match[1];
            $lot   = (int)$match[2];
        }
    }

    return [$block, $lot];
}

/*
|--------------------------------------------------------------------------
| Official South Meridian Block / Lot Locations
|--------------------------------------------------------------------------
*/

function south_meridian_locations(): array
{
    static $locations = null;

    if ($locations !== null) {
        return $locations;
    }

    $locations = [];

    $mappingPath =
        __DIR__
        . DIRECTORY_SEPARATOR
        . 'southmeri_block_lot_mapping.json';


    if (!is_readable($mappingPath)) {
        return $locations;
    }


    $decoded =
        json_decode(
            (string)file_get_contents(
                $mappingPath
            ),
            true
        );


    if (!is_array($decoded)) {
        return $locations;
    }


    /*
     * Coordinate conversion used by the
     * official South Meridian map.
     */
    $pageWidthEmu =
        8.5 * 914400;

    $pageHeightEmu =
        11 * 914400;

    $pageMarginEmu =
        914400;

    $markerCenterOffsetEmu =
        90000;


    foreach ($decoded as $row) {

        $block =
            (int)($row['block'] ?? 0);

        $lot =
            (int)($row['lot'] ?? 0);

        $xEmu =
            (float)($row['x_emu'] ?? -1);

        $yEmu =
            (float)($row['y_emu'] ?? -1);


        if (
            $block < 1 ||
            $lot < 1 ||
            $xEmu < -$pageMarginEmu ||
            $yEmu < -$pageMarginEmu
        ) {
            continue;
        }


        $locations[
            $block . ':' . $lot
        ] = [

            'block' =>
                $block,

            'lot' =>
                $lot,

            'street' =>
                trim(
                    (string)(
                        $row['street'] ?? ''
                    )
                ),

            'x' =>
                round(
                    (
                        (
                            $pageMarginEmu +
                            $xEmu +
                            $markerCenterOffsetEmu
                        )
                        /
                        $pageWidthEmu
                    )
                    * 2550,
                    2
                ),

            'y' =>
                round(
                    (
                        (
                            $pageMarginEmu +
                            $yEmu +
                            $markerCenterOffsetEmu
                        )
                        /
                        $pageHeightEmu
                    )
                    * 3300,
                    2
                )
        ];
    }


    return $locations;
}


$southMeridianLocations =
    south_meridian_locations();

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
      AND status='approved'
    LIMIT 1
");
    $stmt->bind_param("i", $id);
  } else {
$stmt = $conn->prepare("
    SELECT *
    FROM homeowners
    WHERE id=?
      AND phase=?
      AND status='approved'
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

  $displayValue = static function($value, string $fallback = 'Not provided'): string {
    $value = trim((string)($value ?? ''));
    return $value !== '' ? $value : $fallback;
  };

  $isImportPlaceholder = static function(string $path): bool {
    $path = trim($path);
    return $path === '' || str_starts_with($path, 'imports/');
  };

  $displayId = trim((string)($homeowner['public_id'] ?? ''));
  if ($displayId === '') {
    $prefix = phase_prefix((string)($homeowner['phase'] ?? ''));
    $displayId = $prefix . (int)$homeowner['id'];
  }

/*
|--------------------------------------------------------------------------
| Structured Property Location
|--------------------------------------------------------------------------
*/

[
    $homeownerBlock,
    $homeownerLot
] = subdivision_block_lot($homeowner);


$homeownerStreet = trim(
    (string)($homeowner['street'] ?? '')
);


/*
|--------------------------------------------------------------------------
| South Meridian Default Address Values
|--------------------------------------------------------------------------
| These defaults apply to every homeowner profile modal only when the saved
| database value is blank. Existing homeowner values are kept.
*/
$homeownerRegion =
    trim(
        (string)(
            $homeowner['region']
            ?? ''
        )
    );

if ($homeownerRegion === '') {
    $homeownerRegion =
        'CALABARZON';
}


$homeownerZipCode =
    trim(
        (string)(
            $homeowner['zip_code']
            ?? ''
        )
    );

if ($homeownerZipCode === '') {
    $homeownerZipCode =
        '4114';
}


$homeownerCountry =
    trim(
        (string)(
            $homeowner['country']
            ?? ''
        )
    );

if ($homeownerCountry === '') {
    $homeownerCountry =
        'Philippines';
}


/*
|--------------------------------------------------------------------------
| Find exact Block / Lot on official subdivision map
|--------------------------------------------------------------------------
*/

$mapLocation = null;


if (
    $homeownerBlock > 0 &&
    $homeownerLot > 0
) {

    $mapKey =
        $homeownerBlock
        . ':'
        . $homeownerLot;


    $mapLocation =
        $southMeridianLocations[
            $mapKey
        ] ?? null;
}


/*
 * Prefer official mapping coordinates.
 *
 * Older records that already have map_x / map_y
 * can still use them as a fallback.
 */
$mapX =
    $mapLocation['x']
    ??
    $homeowner['map_x']
    ??
    null;


$mapY =
    $mapLocation['y']
    ??
    $homeowner['map_y']
    ??
    null;


/*
 * If Street is missing from the homeowner record,
 * use the official map street.
 */
if (
    $homeownerStreet === '' &&
    !empty($mapLocation['street'])
) {

    $homeownerStreet =
        trim(
            (string)$mapLocation['street']
        );
}


$hasSubdivisionMap =
    $homeownerBlock > 0 &&
    $homeownerLot > 0 &&
    $mapX !== null &&
    $mapY !== null &&
    is_numeric($mapX) &&
    is_numeric($mapY);


/*
|--------------------------------------------------------------------------
| Static Image Map Pin Position
|--------------------------------------------------------------------------
|
| The official subdivision map image is 2550 x 3300.
| Convert the exact map coordinate to responsive percentages.
|
*/

$markerLeft =
    $hasSubdivisionMap
        ? ((float)$mapX / 2550) * 100
        : 0;

$markerTop =
    $hasSubdivisionMap
        ? ((float)$mapY / 3300) * 100
        : 0;


/*
 * Older homeowner records may still only
 * have latitude / longitude.
 */
$lat = $homeowner['latitude'] ?? '';
$lng = $homeowner['longitude'] ?? '';


$hasLegacyGps =
    $lat !== '' &&
    $lng !== '' &&
    is_numeric($lat) &&
    is_numeric($lng);


$propertyAddress =
    ($homeownerBlock > 0 && $homeownerLot > 0)
        ? "Block {$homeownerBlock}, Lot {$homeownerLot}"
        : trim(
            (string)($homeowner['house_lot_number'] ?? '')
        );


$fullAddress = trim(
    implode(
        ', ',
        array_filter(
            [
                $propertyAddress,
                $homeownerStreet,
                $homeowner['other_location_info'] ?? '',
                $homeowner['barangay'] ?? '',
                $homeowner['city_municipality'] ?? '',
                $homeowner['province'] ?? '',
                $homeownerRegion,
                $homeownerZipCode,
                $homeownerCountry
            ],
            static fn($value) =>
                trim((string)$value) !== ''
        )
    )
);


$createdAt =
    !empty($homeowner['created_at'])
        ? date(
            'F d, Y h:i A',
            strtotime($homeowner['created_at'])
        )
        : '-';
  ?>
  <div class="container-fluid p-4">
    <div class="row g-4">
      <div class="col-lg-4">
        <div class="card shadow-sm border-0 profile-summary-card">
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
<div class="mb-2">
    <strong>Phase:</strong>
    <?= esc($displayValue($homeowner['phase'] ?? null)) ?>
</div>

<div class="mb-2">
    <strong>Block:</strong>
    <?= esc(
        $homeownerBlock > 0
            ? $homeownerBlock
            : 'Not provided'
    ) ?>
</div>

<div class="mb-2">
    <strong>Lot:</strong>
    <?= esc(
        $homeownerLot > 0
            ? $homeownerLot
            : 'Not provided'
    ) ?>
</div>

<div class="mb-2">
    <strong>Street:</strong>
    <?= esc(
        $displayValue($homeownerStreet)
    ) ?>
</div>

<div class="mb-2">
    <strong>Residential Type:</strong>
    <?= esc(
        $displayValue(
            $homeowner['residential_type'] ?? null
        )
    ) ?>
</div>
              <div class="mb-2"><strong>Email:</strong> <?= esc($displayValue($homeowner['email'] ?? null)) ?></div>
              <div class="mb-2"><strong>Contact:</strong> <?= esc($displayValue($homeowner['contact_number'] ?? null)) ?></div>
              <div class="mb-2"><strong>Address:</strong> <?= esc($fullAddress !== '' ? $fullAddress : 'Not provided') ?></div>
              <div class="mb-2"><strong>Registered:</strong> <?= esc($createdAt) ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div class="card shadow-sm border-0 mb-4">
          <div class="card-header bg-white">
            <h6 class="mb-0 fw-bold">Personal Information</h6>
          </div>

          <div class="card-body">
            <div class="row g-3">

              <div class="col-md-4">
                <label class="form-label text-muted small mb-1">First Name</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['first_name'] ?? null)) ?></div>
              </div>

              <div class="col-md-4">
                <label class="form-label text-muted small mb-1">Middle Name</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['middle_name'] ?? null)) ?></div>
              </div>

              <div class="col-md-4">
                <label class="form-label text-muted small mb-1">Last Name</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['last_name'] ?? null)) ?></div>
              </div>

              <div class="col-md-6">
                <label class="form-label text-muted small mb-1">Contact Number</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['contact_number'] ?? null)) ?></div>
              </div>

              <div class="col-md-6">
                <label class="form-label text-muted small mb-1">Email</label>
                <div class="fw-semibold text-break"><?= esc($displayValue($homeowner['email'] ?? null)) ?></div>
              </div>

            </div>
          </div>
        </div>


        <div class="card shadow-sm border-0 mb-4">
          <div class="card-header bg-white">
            <h6 class="mb-0 fw-bold">Address &amp; Residency Information</h6>
          </div>

          <div class="card-body">
            <div class="row g-3">

              <div class="col-md-4">
                <label class="form-label text-muted small mb-1">Phase</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['phase'] ?? null)) ?></div>
              </div>

<div class="col-md-4">
    <label class="form-label text-muted small mb-1">
        Block
    </label>

    <div class="fw-semibold">
        <?= esc(
            $homeownerBlock > 0
                ? $homeownerBlock
                : 'Not provided'
        ) ?>
    </div>
</div>

<div class="col-md-4">
    <label class="form-label text-muted small mb-1">
        Lot
    </label>

    <div class="fw-semibold">
        <?= esc(
            $homeownerLot > 0
                ? $homeownerLot
                : 'Not provided'
        ) ?>
    </div>
</div>

<div class="col-md-4">
    <label class="form-label text-muted small mb-1">
        Street
    </label>

    <div class="fw-semibold">
        <?= esc(
            $displayValue($homeownerStreet)
        ) ?>
    </div>
</div>

              <div class="col-md-4">
                <label class="form-label text-muted small mb-1">Residential Type</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['residential_type'] ?? null)) ?></div>
              </div>

              <div class="col-md-4">
                <label class="form-label text-muted small mb-1">Barangay</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['barangay'] ?? null)) ?></div>
              </div>

              <div class="col-md-4">
                <label class="form-label text-muted small mb-1">City/Municipality</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['city_municipality'] ?? null)) ?></div>
              </div>

              <div class="col-md-4">
                <label class="form-label text-muted small mb-1">Province</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['province'] ?? null)) ?></div>
              </div>

			  <div class="col-md-4">
    <label class="form-label text-muted small mb-1">
        Region
    </label>

    <div class="fw-semibold">
        <?= esc($homeownerRegion) ?>
    </div>
</div>

<div class="col-md-4">
    <label class="form-label text-muted small mb-1">
        ZIP Code
    </label>

    <div class="fw-semibold">
        <?= esc($homeownerZipCode) ?>
    </div>
</div>

<div class="col-md-4">
    <label class="form-label text-muted small mb-1">
        Country
    </label>

    <div class="fw-semibold">
        <?= esc($homeownerCountry) ?>
    </div>
</div>

              <div class="col-md-6">
                <label class="form-label text-muted small mb-1">Other Location Info</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['other_location_info'] ?? null)) ?></div>
              </div>

              <div class="col-md-6">
                <label class="form-label text-muted small mb-1">Length of Residency in the Barangay</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['length_of_residency'] ?? null)) ?></div>
              </div>

              <div class="col-md-12">
                <label class="form-label text-muted small mb-1">Complete Address</label>
                <div class="fw-semibold"><?= esc($fullAddress !== '' ? $fullAddress : 'Not provided') ?></div>
              </div>

            </div>
          </div>
        </div>


        <div class="card shadow-sm border-0 mb-4">
          <div class="card-header bg-white">
            <h6 class="mb-0 fw-bold">Emergency Contact</h6>
          </div>

          <div class="card-body">
            <div class="row g-3">

              <div class="col-md-6">
                <label class="form-label text-muted small mb-1">Emergency Contact Person</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['emergency_contact_person'] ?? null)) ?></div>
              </div>

              <div class="col-md-6">
                <label class="form-label text-muted small mb-1">Emergency Contact Number</label>
                <div class="fw-semibold"><?= esc($displayValue($homeowner['emergency_contact_number'] ?? null)) ?></div>
              </div>

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
<?php

$mapImageFile =
    dirname(__DIR__)
    . DIRECTORY_SEPARATOR
    . 'assets'
    . DIRECTORY_SEPARATOR
    . 'img'
    . DIRECTORY_SEPARATOR
    . 'south_meridian_block_lot_map.png';


$mapImageVersion =
    is_file($mapImageFile)
        ? (int)filemtime(
            $mapImageFile
        )
        : time();

?>
<div class="card shadow-sm border-0 mb-4">

    <div class="card-header bg-white">

        <h6 class="mb-0 fw-bold">
            Property Map
        </h6>

        <?php if ($homeownerBlock > 0 && $homeownerLot > 0): ?>

            <small class="text-muted">
                Block <?= (int)$homeownerBlock ?>,
                Lot <?= (int)$homeownerLot ?>

                <?php if ($homeownerStreet !== ''): ?>
                    · <?= esc($homeownerStreet) ?>
                <?php endif; ?>
            </small>

        <?php endif; ?>

    </div>


    <div class="card-body">

 <?php if ($hasSubdivisionMap): ?>

    <div
        class="property-image-map"
    >

        <img
            src="../assets/img/south_meridian_block_lot_map.png?v=<?= $mapImageVersion ?>"
            alt="South Meridian Block and Lot Map"
        >


        <!-- Exact homeowner location pin -->
        <div
            class="property-image-pin"
            title="Block <?= (int)$homeownerBlock ?>, Lot <?= (int)$homeownerLot ?>"
            style="
                left:<?= esc(number_format($markerLeft, 4, '.', '')) ?>%;
                top:<?= esc(number_format($markerTop, 4, '.', '')) ?>%;
            "
            aria-hidden="true"
        >
            📍
        </div>

    </div>


    <div class="text-center mt-2 text-muted small">

        Block <?= (int)$homeownerBlock ?>,
        Lot <?= (int)$homeownerLot ?>

        <?php if ($homeownerStreet !== ''): ?>

            · <?= esc($homeownerStreet) ?>

        <?php endif; ?>

    </div>


<?php elseif ($hasLegacyGps): ?>

    <div class="alert alert-warning mb-0">
        This is an older homeowner record.
        The official Block/Lot image-map location has not yet been assigned.
    </div>

<?php else: ?>

            <div class="text-muted">
                No property map location is available.
            </div>

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
                <?php if (!empty($homeowner['valid_id_path']) && !$isImportPlaceholder((string)$homeowner['valid_id_path'])): ?>
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
                  <div class="text-muted">Not provided by Excel import.</div>
                <?php endif; ?>
              </div>

              <div class="col-md-6">
                <label class="form-label text-muted small mb-2">Proof of Billing</label>
                <?php if (!empty($homeowner['proof_of_billing_path']) && !$isImportPlaceholder((string)$homeowner['proof_of_billing_path'])): ?>
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
                  <div class="text-muted">Not provided by Excel import.</div>
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
  AND status='approved'
LIMIT 1
    ");
    $stmt->bind_param("i", $deleteId);
  } else {
    $stmt = $conn->prepare("
SELECT id, first_name, middle_name, last_name, phase, house_lot_number, valid_id_path, proof_of_billing_path
FROM homeowners
WHERE id=?
  AND phase=?
  AND status='approved'
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

	<!-- Bootstrap 5 utilities/modal support used by this page -->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

	<!-- DataTables base CSS -->
	<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

	<!-- IMPORTANT: DeskApp theme must load AFTER Bootstrap 5/DataTables
	     so Bootstrap does not replace the Admin typography and link styles. -->
	<link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

	<script async src="https://www.googletagmanager.com/gtag/js?id=UA-119386393-1"></script>


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

/* Bootstrap app toast must not block header controls while hidden */
#appToast {
    pointer-events: none;
}

#appToast.show,
#appToast.showing {
    pointer-events: auto;
}

		#viewHomeownerModal .card{
		  border-radius:14px;
		}

		#viewHomeownerModal .form-label.text-muted.small{
		  font-size:12px;
		}

		#viewHomeownerModal .fw-semibold{
		  word-break:break-word;
		}

        /* View Homeowner: keep the left profile card at its natural height. */
        #viewHomeownerModal .profile-summary-card{
          height:auto !important;
        }

        /*
         * Static South Meridian image map.
         * The source image is exactly 2550 x 3300 = 17:22.
         * Keeping the wrapper at the same ratio preserves exact pin alignment.
         */
        .property-image-map{
          position:relative;
          width:min(100%, 460px);
          aspect-ratio:17 / 22;
          margin:0 auto;
          overflow:hidden;
          border:1px solid rgba(148,163,184,.35);
          border-radius:12px;
          background:#e9eef6;
          box-shadow:0 4px 14px rgba(15,23,42,.10);
        }

        .property-image-map img{
          display:block;
          width:100%;
          height:100%;
          object-fit:fill;
          user-select:none;
          -webkit-user-drag:none;
        }

        html.dark .property-image-map{
          background:#0f172a;
          border-color:rgba(148,163,184,.22);
          box-shadow:0 6px 18px rgba(0,0,0,.28);
        }

        .property-image-pin{
          position:absolute;
          transform:translate(-50%, -100%);
          font-size:30px;
          line-height:1;
          color:#dc3545;
          text-shadow:0 1px 4px rgba(0,0,0,.55);
          z-index:5;
          pointer-events:none;
          filter:drop-shadow(0 2px 2px rgba(255,255,255,.35));
        }

        @media (max-width: 575.98px){
          .property-image-map{
            width:100%;
          }
        }
</style>

<!-- ADMIN DARK MODE -->
<link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">

<style>
/* =========================================================
   DESKAPP TYPOGRAPHY / LINK RESTORE
   Bootstrap 5 is used for modal/utilities on this page, but
   DeskApp remains the visual design system for the Admin UI.
   ========================================================= */

html,
body,
button,
input,
select,
textarea,
.table,
.dataTables_wrapper {
    font-family: 'Inter', sans-serif !important;
}

/* Bootstrap 5 underlines anchors by default.
   DeskApp navigation links should not be underlined. */
.brand-logo a,
.header a,
.left-side-bar a,
.sidebar-menu a,
.dropdown-menu a {
    text-decoration: none !important;
}

/* Restore DeskApp sidebar typography. */
.sidebar-menu .dropdown-toggle {
    font-family: 'Inter', sans-serif !important;
    font-size: 16px !important;
    font-weight: 400 !important;
    letter-spacing: .03em !important;
    line-height: 1.5 !important;
}

.sidebar-menu .submenu li a {
    font-family: 'Inter', sans-serif !important;
    font-size: 14px !important;
    font-weight: 400 !important;
    line-height: 1.45 !important;
    text-decoration: none !important;
}

/* Page/title typography. */
.page-title-wrap h2,
.page-title-wrap .h4 {
    font-family: 'Inter', sans-serif !important;
    font-weight: 600 !important;
    letter-spacing: 0 !important;
}

.page-title-wrap .subtitle {
    font-family: 'Inter', sans-serif !important;
    font-weight: 500 !important;
}

/* Table typography. */
#approvedTable,
#approvedTable th,
#approvedTable td,
.dataTables_wrapper label,
.dataTables_wrapper input,
.dataTables_wrapper select,
.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_paginate {
    font-family: 'Inter', sans-serif !important;
}

#approvedTable thead th {
    font-weight: 600 !important;
}

#approvedTable tbody td {
    font-weight: 400 !important;
}

/* Keep normal text inside profile/edit modals consistent too. */
#viewHomeownerModal,
#editHomeownerModal,
#deleteHomeownerModal {
    font-family: 'Inter', sans-serif !important;
}

/* =========================================================
   HO APPROVED - DARK TABLE SAFETY
   ========================================================= */
/* =========================================================
   HO APPROVED - DARK TABLE SAFETY
   ========================================================= */

html.dark #approvedTable {
    --bs-table-color: var(--admin-text);
    --bs-table-bg: var(--admin-surface);

    --bs-table-border-color:
        var(--admin-border);

    --bs-table-striped-color:
        var(--admin-text);

    --bs-table-striped-bg:
        rgba(148, 163, 184, .055);

    --bs-table-hover-color:
        #ffffff;

    --bs-table-hover-bg:
        var(--admin-hover);

    color:
        var(--admin-text) !important;

    background:
        var(--admin-surface) !important;
}


/* Main Approved Households table cells */
html.dark #approvedTable tbody td {
    color:
        var(--admin-text) !important;

    border-color:
        var(--admin-border) !important;
}


/* Normal/even rows */
html.dark #approvedTable tbody tr:nth-child(even) > * {
    background:
        var(--admin-surface) !important;

    color:
        var(--admin-text) !important;
}


/* Striped/odd rows */
html.dark #approvedTable tbody tr:nth-child(odd) > * {
    background:
        var(--admin-surface-2) !important;

    color:
        var(--admin-text) !important;
}


/* Header */
html.dark #approvedTable thead th {
    background:
        var(--admin-surface-2) !important;

    color:
        #f8fafc !important;

    border-color:
        var(--admin-border) !important;
}


/* Row hover */
html.dark #approvedTable tbody tr:hover > * {
    background:
        var(--admin-hover) !important;

    color:
        #ffffff !important;
}


/* =========================================================
   TABLES INSIDE VIEW HOMEOWNER MODAL
   Household Members + Registered Tenants
   ========================================================= */

html.dark #viewHomeownerModal .table {
    --bs-table-color:
        var(--admin-text);

    --bs-table-bg:
        var(--admin-surface);

    --bs-table-striped-color:
        var(--admin-text);

    --bs-table-striped-bg:
        rgba(148, 163, 184, .055);

    --bs-table-border-color:
        var(--admin-border);
}


html.dark #viewHomeownerModal
.table > :not(caption) > * > * {

    color:
        var(--admin-text) !important;

    border-color:
        var(--admin-border) !important;
}


html.dark #viewHomeownerModal
.table tbody tr:nth-child(even) > * {

    background:
        var(--admin-surface) !important;
}


html.dark #viewHomeownerModal
.table tbody tr:nth-child(odd) > * {

    background:
        var(--admin-surface-2) !important;
}


html.dark #viewHomeownerModal
.table thead th {

    background:
        var(--admin-surface-2) !important;

    color:
        #f8fafc !important;

    border-color:
        var(--admin-border) !important;
}


html.dark #viewHomeownerModal .modal-body,
html.dark #editHomeownerModal .modal-body {
    background:
        var(--admin-bg) !important;
}
</style>
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
									[
    $rowBlock,
    $rowLot
] = subdivision_block_lot($row);


$rowStreet =
    trim(
        (string)(
            $row['street'] ?? ''
        )
    );


$rowProperty =
    ($rowBlock > 0 && $rowLot > 0)
        ? "Block {$rowBlock}, Lot {$rowLot}"
        : trim(
            (string)(
                $row['house_lot_number'] ?? ''
            )
        );


$rowAddress =
    trim(
        implode(
            ', ',
            array_filter(
                [
                    $row['phase'] ?? '',
                    $rowProperty,
                    $rowStreet
                ],
                static fn($value) =>
                    trim((string)$value) !== ''
            )
        )
    );
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
    <!-- ADMIN DARK MODE -->
<script src="vendors/scripts/admin_theme.js"></script>

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



/*
|--------------------------------------------------------------------------
| View Approved Homeowner
|--------------------------------------------------------------------------
|
| The profile HTML already contains the static subdivision image and
| exact pin position. No Leaflet initialization is needed.
|
*/

$(document).on(
    'click',
    '.viewHomeownerBtn',
    function (e) {

        e.preventDefault();

        const id =
            $(this).data('id');

        if (!id) {
            return;
        }

        content.innerHTML =
            `
            <div class="p-4 text-muted fw-semibold">
                Loading...
            </div>
            `;

        modal.show();

        $.get(
            'ho_approved.php',
            {
                ajax:
                    'homeowner_profile',

                id:
                    id,

                _:
                    Date.now()
            }
        )
        .done(
            function (html) {

                /*
                 * The returned HTML already includes:
                 *
                 * - South Meridian subdivision image
                 * - responsive pin position
                 * - Block / Lot / Street information
                 */
                content.innerHTML =
                    html;
            }
        )
        .fail(
            function (xhr) {

                content.innerHTML =
                    `
                    <div class="p-4">
                        <div class="alert alert-danger mb-0">
                            Failed to load profile.
                            HTTP ${xhr.status}
                        </div>
                    </div>
                    `;
            }
        );
    }
);


modalEl.addEventListener(
    'hidden.bs.modal',
    function () {

        content.innerHTML =
            '';
    }
);


			const editModalEl = document.getElementById('editHomeownerModal');
			const editContent = document.getElementById('editHomeownerContent');
			const editModal = new bootstrap.Modal(editModalEl, { backdrop:'static', keyboard:true });

let pendingInit = false;


/*
|--------------------------------------------------------------------------
| Initialize Official South Meridian Edit Image Map
|--------------------------------------------------------------------------
|
| This uses the same 2550 x 3300 static subdivision image as the View modal.
| No Leaflet library is required.
|
*/

function initEditPropertyMap() {

    const mapEl =
        document.getElementById('editMap');

    const dataEl =
        document.getElementById('editLocationData');

    const blockSelect =
        document.getElementById('editBlock');

    const lotSelect =
        document.getElementById('editLot');

    const streetInput =
        document.getElementById('editStreet');

    const propertyInfo =
        document.getElementById('editPropertyInfo');


    if (
        !mapEl ||
        !dataEl ||
        !blockSelect ||
        !lotSelect
    ) {
        return;
    }


    let locations = [];

    try {

        locations =
            JSON.parse(
                dataEl.textContent || '[]'
            );

    } catch (error) {

        console.error(
            'Invalid South Meridian location data.',
            error
        );

        return;
    }


    const imageUrl =
        mapEl.dataset.mapImage ||
        '../assets/img/south_meridian_block_lot_map.png';


    /*
     * Rebuild the map container as a responsive image map.
     */
    mapEl.innerHTML = '';

    mapEl.style.position =
        'relative';

    mapEl.style.width =
        'min(100%, 460px)';

    mapEl.style.aspectRatio =
        '17 / 22';

    mapEl.style.height =
        'auto';

    mapEl.style.minHeight =
        '0';

    mapEl.style.margin =
        '0 auto';

    mapEl.style.overflow =
        'hidden';

    mapEl.style.borderRadius =
        '12px';

    mapEl.style.background =
        '#e9eef6';


    const mapImage =
        document.createElement('img');

    mapImage.src =
        imageUrl;

    mapImage.alt =
        'South Meridian Block and Lot Map';

    mapImage.style.display =
        'block';

    mapImage.style.width =
        '100%';

    mapImage.style.height =
        '100%';

    mapImage.style.objectFit =
        'fill';

    mapEl.appendChild(
        mapImage
    );


    const marker =
        document.createElement('div');

    marker.textContent =
        '📍';

    marker.setAttribute(
        'aria-hidden',
        'true'
    );

    Object.assign(
        marker.style,
        {
            position:
                'absolute',

            transform:
                'translate(-50%, -100%)',

            fontSize:
                '30px',

            lineHeight:
                '1',

            color:
                '#dc3545',

            textShadow:
                '0 1px 4px rgba(0,0,0,.45)',

            zIndex:
                '5',

            pointerEvents:
                'none',

            display:
                'none'
        }
    );

    mapEl.appendChild(
        marker
    );


    function findLocation(
        block,
        lot
    ) {

        return locations.find(
            function (item) {

                return (
                    Number(item.block) ===
                        Number(block)
                    &&
                    Number(item.lot) ===
                        Number(lot)
                );
            }
        );
    }


    function showSelectedProperty() {

        const block =
            Number(
                blockSelect.value
            );

        const lot =
            Number(
                lotSelect.value
            );


        const location =
            findLocation(
                block,
                lot
            );


        if (!location) {

            marker.style.display =
                'none';

            if (streetInput) {
                streetInput.value =
                    '';
            }

            if (propertyInfo) {
                propertyInfo.textContent =
                    'Select a valid Block and Lot.';
            }

            return;
        }


        const x =
            Number(
                location.map_x
            );

        const y =
            Number(
                location.map_y
            );

        const street =
            String(
                location.street || ''
            );


        if (
            !Number.isFinite(x) ||
            !Number.isFinite(y)
        ) {

            marker.style.display =
                'none';

            return;
        }


        const leftPercent =
            (x / 2550) * 100;

        const topPercent =
            (y / 3300) * 100;


        marker.style.left =
            leftPercent + '%';

        marker.style.top =
            topPercent + '%';

        marker.style.display =
            'block';

        marker.title =
            'Block ' +
            block +
            ', Lot ' +
            lot +
            (
                street
                    ? ' · ' + street
                    : ''
            );


        if (streetInput) {
            streetInput.value =
                street;
        }


        if (propertyInfo) {

            propertyInfo.textContent =
                'Selected Property: ' +
                'Block ' +
                block +
                ', Lot ' +
                lot +
                (
                    street
                        ? ' · ' + street
                        : ''
                );
        }
    }


    /*
     * Rebuild the Lot choices whenever Block changes.
     */
    blockSelect.addEventListener(
        'change',
        function () {

            const block =
                Number(
                    blockSelect.value
                );


            lotSelect.innerHTML =
                '<option value="">Select Lot</option>';


            if (!block) {

                lotSelect.disabled =
                    true;

                showSelectedProperty();

                return;
            }


            const lots =
                locations
                    .filter(
                        function (item) {

                            return (
                                Number(item.block) ===
                                block
                            );
                        }
                    )
                    .sort(
                        function (a, b) {

                            return (
                                Number(a.lot) -
                                Number(b.lot)
                            );
                        }
                    );


            lots.forEach(
                function (location) {

                    const option =
                        document.createElement(
                            'option'
                        );

                    option.value =
                        String(
                            location.lot
                        );

                    option.textContent =
                        'Lot ' +
                        location.lot;

                    lotSelect.appendChild(
                        option
                    );
                }
            );


            lotSelect.disabled =
                false;

            showSelectedProperty();
        }
    );


    lotSelect.addEventListener(
        'change',
        function () {

            showSelectedProperty();
        }
    );


    /*
     * Show the homeowner's current property immediately.
     */
    showSelectedProperty();
}


$(document).on(
    'click',
    '.editHomeowner',
    function (e) {

        e.preventDefault();

        const id =
            $(this).data('id');

        if (!id) {
            return;
        }

        pendingInit =
            true;

        editContent.innerHTML =
            `
            <div class="p-3 text-muted fw-semibold">
                Loading edit form...
            </div>
            `;

        editModal.show();

        $.get(
            'edit_homeowner_modal.php',
            {
                ajax:
                    'edit_homeowner',

                id:
                    id,

                _:
                    Date.now()
            }
        )
        .done(
            function (html) {

                editContent.innerHTML =
                    html;

                if (
                    editModalEl.classList.contains(
                        'show'
                    )
                ) {

                    initEditPropertyMap();

                    pendingInit =
                        false;
                }
            }
        )
        .fail(
            function (xhr) {

                pendingInit =
                    false;

                editContent.innerHTML =
                    `
                    <div class="alert alert-danger">
                        Failed to load.
                        HTTP ${xhr.status}
                    </div>
                    `;
            }
        );
    }
);


editModalEl.addEventListener(
    'shown.bs.modal',
    function () {

        if (pendingInit) {

            initEditPropertyMap();

            pendingInit =
                false;
        }
    }
);


editModalEl.addEventListener(
    'hidden.bs.modal',
    function () {

        editContent.innerHTML =
            '';

        pendingInit =
            false;
    }
);


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