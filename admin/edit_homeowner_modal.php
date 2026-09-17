<?php
session_start();
ob_start(); // 
require_once '../config/database.php';

/* =========================
   1) SESSION COMPATIBILITY
   ========================= */
if (empty($_SESSION['admin_id']) && !empty($_SESSION['user_id'])) {
  $_SESSION['admin_id'] = $_SESSION['user_id'];
}
if (empty($_SESSION['admin_role']) && !empty($_SESSION['role'])) {
  $_SESSION['admin_role'] = $_SESSION['role'];
}
if (empty($_SESSION['admin_phase']) && !empty($_SESSION['phase'])) {
  $_SESSION['admin_phase'] = $_SESSION['phase'];
}

/* =========================
   2) ADMIN GUARD
   ========================= */
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)) {
  http_response_code(401);
  exit('Unauthorized');
}


if (!function_exists('esc')) {
    function esc($v): string {
        return htmlspecialchars(
            (string)$v,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
/*
|--------------------------------------------------------------------------
| Secure optional document upload
|--------------------------------------------------------------------------
*/
function saveOptionalHomeownerDocument(
    array $file,
    string $documentType
): ?string {

    if (
        !in_array(
            $documentType,
            ['id', 'proof'],
            true
        )
    ) {
        throw new RuntimeException(
            'Invalid document type.'
        );
    }


    /*
     * No replacement selected.
     */
    if (
        empty($file) ||
        (
            isset($file['error']) &&
            $file['error'] === UPLOAD_ERR_NO_FILE
        )
    ) {
        return null;
    }


    if (
        !isset($file['error']) ||
        is_array($file['error'])
    ) {
        throw new RuntimeException(
            'Invalid uploaded file.'
        );
    }


    switch ($file['error']) {

        case UPLOAD_ERR_OK:
            break;

        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:

            throw new RuntimeException(
                'The uploaded document is too large.'
            );

        default:

            throw new RuntimeException(
                'The document upload failed.'
            );
    }


    /*
     * Maximum size: 5 MB
     */
    $maxSize =
        5 * 1024 * 1024;


    $fileSize =
        (int)($file['size'] ?? 0);


    if (
        $fileSize <= 0 ||
        $fileSize > $maxSize
    ) {

        throw new RuntimeException(
            'Documents must be 5 MB or smaller.'
        );
    }


    $tmpName =
        (string)($file['tmp_name'] ?? '');


    if (
        $tmpName === '' ||
        !is_uploaded_file($tmpName)
    ) {

        throw new RuntimeException(
            'Invalid uploaded document.'
        );
    }


    /*
     * Detect actual MIME type.
     */
    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );


    $mimeType =
        $finfo->file(
            $tmpName
        );


    $allowedTypes = [

        'image/jpeg' =>
            'jpg',

        'image/png' =>
            'png',

        'application/pdf' =>
            'pdf'
    ];


    if (
        !isset(
            $allowedTypes[$mimeType]
        )
    ) {

        throw new RuntimeException(
            'Only JPG, PNG, and PDF documents are allowed.'
        );
    }


    $extension =
        $allowedTypes[$mimeType];


    /*
     * Project uploads folder
     */
    $uploadDir =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR;


    if (
        !is_dir($uploadDir) &&
        !mkdir(
            $uploadDir,
            0755,
            true
        )
    ) {

        throw new RuntimeException(
            'Unable to create upload directory.'
        );
    }


    /*
     * Random server-generated filename
     */
    $fileName =
        bin2hex(
            random_bytes(16)
        ) .
        '_' .
        $documentType .
        '.' .
        $extension;


    $fullPath =
        $uploadDir .
        $fileName;


    if (
        !move_uploaded_file(
            $tmpName,
            $fullPath
        )
    ) {

        throw new RuntimeException(
            'Failed to save uploaded document.'
        );
    }


    return
        'uploads/' .
        $fileName;
}


/*
|--------------------------------------------------------------------------
| Safely remove homeowner document
|--------------------------------------------------------------------------
*/
function removeHomeownerDocument(
    string $dbPath
): void {

    $dbPath =
        str_replace(
            '\\',
            '/',
            trim($dbPath)
        );


    /*
     * Only delete files inside uploads/
     */
    if (
        !preg_match(
            '~^uploads/[A-Za-z0-9._-]+$~D',
            $dbPath
        )
    ) {
        return;
    }


    $fullPath =
        dirname(__DIR__) .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $dbPath
        );


    if (
        is_file($fullPath)
    ) {

        @unlink(
            $fullPath
        );
    }
}
/*
|--------------------------------------------------------------------------
| Official South Meridian Block / Lot Mapping
|--------------------------------------------------------------------------
*/
function loadSouthMeridianLocations(): array
{
    $path =
        __DIR__ .
        '/southmeri_block_lot_mapping.json';

    if (!is_readable($path)) {
        throw new RuntimeException(
            'South Meridian Block/Lot mapping file was not found.'
        );
    }

    $decoded =
        json_decode(
            (string)file_get_contents($path),
            true
        );

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'South Meridian Block/Lot mapping file is invalid.'
        );
    }

    $pageWidthEmu =
        8.5 * 914400;

    $pageHeightEmu =
        11 * 914400;

    $pageMarginEmu =
        914400;

    $markerCenterOffsetEmu =
        90000;

    $locations = [];

    foreach ($decoded as $item) {

        $block =
            (int)($item['block'] ?? 0);

        $lot =
            (int)($item['lot'] ?? 0);

        if (
            $block <= 0 ||
            $lot <= 0
        ) {
            continue;
        }

        $xEmu =
            (float)($item['x_emu'] ?? 0);

        $yEmu =
            (float)($item['y_emu'] ?? 0);

        $mapX =
            (int)round(
                (
                    (
                        $pageMarginEmu +
                        $xEmu +
                        $markerCenterOffsetEmu
                    )
                    /
                    $pageWidthEmu
                )
                * 2550
            );

        $mapY =
            (int)round(
                (
                    (
                        $pageMarginEmu +
                        $yEmu +
                        $markerCenterOffsetEmu
                    )
                    /
                    $pageHeightEmu
                )
                * 3300
            );

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
                        $item['street'] ?? ''
                    )
                ),

            'map_x' =>
                $mapX,

            'map_y' =>
                $mapY
        ];
    }

    return $locations;
}


function subdivision_block_lot(array $record): array
{
    $block =
        (int)($record['block'] ?? 0);

    $lot =
        (int)($record['lot'] ?? 0);

    if ($block <= 0 || $lot <= 0) {

        $legacy =
            trim(
                (string)(
                    $record[
                        'house_lot_number'
                    ] ?? ''
                )
            );

        if (
            preg_match(
                '/(?:block|blk|b)\s*[-:]?\s*(\d+)\D+(?:lot|l)\s*[-:]?\s*(\d+)/i',
                $legacy,
                $match
            )
        ) {
            $block =
                (int)$match[1];

            $lot =
                (int)$match[2];
        }
    }

    return [
        $block,
        $lot
    ];
}

/* =========================
   4) HELPER FUNCTIONS
   ========================= */

function admin_can_access_homeowner(
    mysqli $conn,
    string $admin_role,
    string $admin_phase,
    int $homeowner_id
): bool {

    if ($admin_role === 'superadmin') {

        $stmt = $conn->prepare("
            SELECT id
            FROM homeowners
            WHERE id=?
              AND status='approved'
            LIMIT 1
        ");

        $stmt->bind_param(
            "i",
            $homeowner_id
        );

    } else {

        $stmt = $conn->prepare("
            SELECT id
            FROM homeowners
            WHERE id=?
              AND phase=?
              AND status='approved'
            LIMIT 1
        ");

        $stmt->bind_param(
            "is",
            $homeowner_id,
            $admin_phase
        );
    }

    $stmt->execute();

    $ok =
        (bool)$stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return $ok;
}

/* Get admin role + phase (fallback to DB if session missing) */
function get_admin_phase_role(mysqli $conn): array {
  $admin_id = (int)($_SESSION['admin_id'] ?? 0);
  $role  = (string)($_SESSION['admin_role'] ?? '');
  $phase = (string)($_SESSION['admin_phase'] ?? '');

  if ($admin_id > 0 && ($role === '' || $phase === '')) {
    $stmt = $conn->prepare("SELECT role, phase FROM admins WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $a = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $role  = (string)($a['role'] ?? $role);
    $phase = (string)($a['phase'] ?? $phase);
  }

  return [$role, $phase];
}

/* JSON output helper (cleans buffer first so JSON is pure) */
function json_out(array $arr): void {
  if (ob_get_length()) ob_clean();
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr);
  exit;
}

/* Helper for dynamic IN() binding */
function stmt_bind(mysqli_stmt $stmt, string $types, array $values): void {
  $refs = [];
  $refs[] = $types;

  foreach ($values as $k => $v) {
    $refs[] = &$values[$k]; // important: pass by reference
  }

  call_user_func_array([$stmt, 'bind_param'], $refs);
}

/* ======================================================================
   A) AJAX: Render modal HTML
   ====================================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'edit_homeowner') {
  $id = (int)($_GET['id'] ?? 0);

  try {

    $southMeridianLocations =
        loadSouthMeridianLocations();

} catch (Throwable $e) {

    http_response_code(500);

    exit(
        '<div class="alert alert-danger">' .
        esc($e->getMessage()) .
        '</div>'
    );
}
  if ($id <= 0) {
    exit('<div class="p-4"><div class="alert alert-warning mb-0">Invalid ID.</div></div>');
  }

  [$admin_role, $admin_phase] = get_admin_phase_role($conn);

  if (!admin_can_access_homeowner($conn, $admin_role, $admin_phase, $id)) {
    http_response_code(403);
    exit('<div class="p-4"><div class="alert alert-danger mb-0">Not allowed.</div></div>');
  }

  $stmt = $conn->prepare("SELECT * FROM homeowners WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $home = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$home) {
    exit('<div class="p-4"><div class="alert alert-danger mb-0">Homeowner not found.</div></div>');
  }

  $stmt = $conn->prepare("SELECT * FROM household_members WHERE homeowner_id=? ORDER BY id ASC");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $members = $stmt->get_result(); // keep result to loop later
  $stmt->close();

 [
    $currentBlock,
    $currentLot
] = subdivision_block_lot($home);


$currentLocation =
    $southMeridianLocations[
        $currentBlock . ':' . $currentLot
    ]
    ?? null;


$currentStreet =
    $currentLocation
        ? trim(
            (string)(
                $currentLocation['street'] ?? ''
            )
        )
        : trim(
            (string)(
                $home['street'] ?? ''
            )
        );


/*
|--------------------------------------------------------------------------
| Available Blocks
|--------------------------------------------------------------------------
*/

$availableBlocks = [];

foreach ($southMeridianLocations as $location) {

    $availableBlocks[] =
        (int)$location['block'];
}

$availableBlocks =
    array_values(
        array_unique(
            $availableBlocks
        )
    );

sort(
    $availableBlocks,
    SORT_NUMERIC
);


/*
|--------------------------------------------------------------------------
| Lots for current Block
|--------------------------------------------------------------------------
*/

$currentLots = [];

if ($currentBlock > 0) {

    foreach ($southMeridianLocations as $location) {

        if (
            (int)$location['block'] ===
            $currentBlock
        ) {

            $currentLots[] =
                (int)$location['lot'];
        }
    }

    sort(
        $currentLots,
        SORT_NUMERIC
    );
}


$validId =
    (string)($home['valid_id_path'] ?? '');

$proof =
    (string)($home['proof_of_billing_path'] ?? '');

  // ✅ clear buffer so we output clean HTML only
  ob_clean();
  ?>
  <form id="editHomeownerForm" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save_homeowner">
    <input type="hidden" name="id" value="<?= (int)$home['id'] ?>">

    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label fw-semibold">First Name</label>
        <input type="text" class="form-control" name="first_name" value="<?= esc($home['first_name'] ?? '') ?>" required>
      </div>

      <div class="col-md-4">
        <label class="form-label fw-semibold">Middle Name</label>
        <input type="text" class="form-control" name="middle_name" value="<?= esc($home['middle_name'] ?? '') ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label fw-semibold">Last Name</label>
        <input type="text" class="form-control" name="last_name" value="<?= esc($home['last_name'] ?? '') ?>" required>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Contact Number</label>
        <input type="text" class="form-control" name="contact_number" value="<?= esc($home['contact_number'] ?? '') ?>" required>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Email</label>
        <input type="email" class="form-control" name="email" value="<?= esc($home['email'] ?? '') ?>" required>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Phase</label>

        <?php if ($admin_role === 'superadmin'): ?>
          <select class="form-select" name="phase" required>
            <?php foreach (['Phase 1', 'Phase 2', 'Phase 3'] as $p): ?>
              <option value="<?= esc($p) ?>" <?= (($home['phase'] ?? '') === $p) ? 'selected' : '' ?>>
                <?= esc($p) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" class="form-control" name="phase" value="<?= esc($home['phase'] ?? '') ?>" readonly>
          <div class="form-text">Only superadmin can change phase.</div>
        <?php endif; ?>
      </div>

<div class="col-md-3">

    <label class="form-label fw-semibold">
        Block
    </label>

    <select
        class="form-select"
        name="block"
        id="editBlock"
        required
    >

        <option value="">
            Select Block
        </option>

        <?php foreach ($availableBlocks as $block): ?>

            <option
                value="<?= (int)$block ?>"
                <?= $block === $currentBlock ? 'selected' : '' ?>
            >
                Block <?= (int)$block ?>
            </option>

        <?php endforeach; ?>

    </select>

</div>


<div class="col-md-3">

    <label class="form-label fw-semibold">
        Lot
    </label>

    <select
        class="form-select"
        name="lot"
        id="editLot"
        required
        <?= $currentBlock <= 0 ? 'disabled' : '' ?>
    >

        <option value="">
            Select Lot
        </option>

        <?php foreach ($currentLots as $lot): ?>

            <option
                value="<?= (int)$lot ?>"
                <?= $lot === $currentLot ? 'selected' : '' ?>
            >
                Lot <?= (int)$lot ?>
            </option>

        <?php endforeach; ?>

    </select>

</div>


<div class="col-md-6">

    <label class="form-label fw-semibold">
        Street
    </label>

    <input
        type="text"
        class="form-control bg-light"
        id="editStreet"
        value="<?= esc($currentStreet) ?>"
        readonly
    >

    <div class="form-text">
        Street is automatically determined by the selected Block and Lot.
    </div>

</div>

      <div class="col-12">
        <hr class="my-2">
        <div class="fw-bold mb-1">Address &amp; Residency Information</div>
        <div class="text-muted mb-2" style="font-size:13px;">
          These fields match the South Meridian Excel resident import template.
        </div>
      </div>

      <div class="col-md-4">
        <label class="form-label fw-semibold">Barangay</label>
        <input type="text"
               class="form-control bg-light"
               name="barangay"
               value="Salitran IV"
               readonly>
        <div class="form-text">Fixed community location.</div>
      </div>

<div class="col-md-4">
    <label class="form-label fw-semibold">City / Municipality</label>
    <input type="text"
           class="form-control bg-light"
           name="city_municipality"
           value="Dasmarinas City"
           readonly>
    <div class="form-text">Fixed community location.</div>
</div>

      <div class="col-md-4">
        <label class="form-label fw-semibold">Province</label>
        <input type="text"
               class="form-control bg-light"
               name="province"
               value="Cavite"
               readonly>
        <div class="form-text">Fixed community location.</div>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Other Location Info</label>
        <input type="text"
               class="form-control"
               name="other_location_info"
               value="<?= esc($home['other_location_info'] ?? '') ?>"
               placeholder="Optional landmark, street, block, etc.">
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Length of Residency in the Barangay</label>
        <input type="text"
               class="form-control"
               name="length_of_residency"
               value="<?= esc($home['length_of_residency'] ?? '') ?>"
               placeholder="Example: 5 years"
               required>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Residential Type</label>
        <?php $residentialType = trim((string)($home['residential_type'] ?? '')); ?>
        <select class="form-select" name="residential_type" required>
          <option value="">Select residential type</option>
          <option value="Owner" <?= $residentialType === 'Owner' ? 'selected' : '' ?>>Owner</option>
          <option value="Renter/Tenant" <?= $residentialType === 'Renter/Tenant' ? 'selected' : '' ?>>Renter/Tenant</option>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Emergency Contact Person</label>
        <input type="text"
               class="form-control"
               name="emergency_contact_person"
               value="<?= esc($home['emergency_contact_person'] ?? '') ?>"
               required>
      </div>

      <div class="col-md-6">
        <label class="form-label fw-semibold">Emergency Contact Number</label>
        <input type="text"
               class="form-control"
               name="emergency_contact_number"
               value="<?= esc($home['emergency_contact_number'] ?? '') ?>"
               required>
      </div>

<div class="col-12">

    <hr class="my-2">

    <div class="fw-bold mb-1">
        Official Property Location
    </div>

    <div
        class="text-muted mb-2"
        style="font-size:13px;"
    >
        The property marker is automatically determined
        from the selected Block and Lot.
    </div>


    <div
        id="editPropertyInfo"
        class="alert alert-light border mb-3"
    >

        <?php if (
            $currentBlock > 0 &&
            $currentLot > 0
        ): ?>

            <strong>Selected Property:</strong>

            Block <?= (int)$currentBlock ?>,
            Lot <?= (int)$currentLot ?>

            <?php if ($currentStreet !== ''): ?>

                · <?= esc($currentStreet) ?>

            <?php endif; ?>

        <?php else: ?>

            Select a Block and Lot.

        <?php endif; ?>

    </div>


    <div
        id="editMap"
        data-map-image="../assets/img/south_meridian_block_lot_map.png"
        style="
            height:420px;
            border:2px solid #077f46;
            border-radius:14px;
            overflow:hidden;
            background:#e9eef6;
        "
    ></div>


    <script
        type="application/json"
        id="editLocationData"
    ><?= json_encode(
        array_values($southMeridianLocations),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?></script>

</div>

      <div class="col-12">
        <hr class="my-2">
        <div class="fw-bold mb-2">Uploaded Documents</div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Valid ID (replace optional)</label>
            <?php if ($validId): ?>
              <div class="small mb-1">Current: <a href="<?= esc($validId) ?>" target="_blank">Open</a></div>
            <?php endif; ?>
            <input
    type="file"
    class="form-control"
    name="valid_id"
    accept=".jpg,.jpeg,.png,.pdf"
>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Proof of Billing (replace optional)</label>
            <?php if ($proof): ?>
              <div class="small mb-1">Current: <a href="<?= esc($proof) ?>" target="_blank">Open</a></div>
            <?php endif; ?>
            <input
    type="file"
    class="form-control"
    name="proof_of_billing"
    accept=".jpg,.jpeg,.png,.pdf"
>
          </div>
        </div>
      </div>

      <div class="col-12">
        <hr class="my-2">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div class="fw-bold">Household Members</div>
          <button type="button" class="btn btn-outline-success btn-sm" id="addEditMemberBtn">+ Add Member</button>
        </div>

        <div id="editMembersWrap" class="mt-3">
          <?php if ($members && $members->num_rows > 0): ?>
            <?php while ($m = $members->fetch_assoc()): ?>
              <div class="border rounded-3 p-3 mb-2 memberRow" style="background:#fff;">
                <input type="hidden" name="member_id[]" value="<?= (int)$m['id'] ?>">

                <div class="row g-2 align-items-end">
                  <div class="col-md-3">
                    <label class="form-label small text-muted">First</label>
                    <input type="text" class="form-control" name="member_first_name[]" value="<?= esc($m['first_name'] ?? '') ?>" required>
                  </div>

                  <div class="col-md-3">
                    <label class="form-label small text-muted">Middle</label>
                    <input type="text" class="form-control" name="member_middle_name[]" value="<?= esc($m['middle_name'] ?? '') ?>">
                  </div>

                  <div class="col-md-3">
                    <label class="form-label small text-muted">Last</label>
                    <input type="text" class="form-control" name="member_last_name[]" value="<?= esc($m['last_name'] ?? '') ?>" required>
                  </div>

                  <div class="col-md-2">
                    <label class="form-label small text-muted">Relation</label>
                    <select class="form-select" name="member_relation[]" required>
                      <?php
                        $rels = ['Homeowner', 'Spouse', 'Child', 'Parent', 'Relative', 'Tenant', 'Caretaker'];
                        $cur = (string)($m['relation'] ?? '');

                        foreach ($rels as $r) {
                          $sel = ($cur === $r) ? 'selected' : '';
                          echo '<option ' . $sel . ' value="' . esc($r) . '">' . esc($r) . '</option>';
                        }
                      ?>
                    </select>
                  </div>

                  <div class="col-md-1 d-grid">
                    <button type="button" class="btn btn-outline-danger btn-sm removeMemberBtn">Remove</button>
                  </div>
                </div>
              </div>
            <?php endwhile; ?>
          <?php else: ?>
            <div class="text-muted">No members yet. Click “Add Member”.</div>
          <?php endif; ?>
        </div>

        <template id="editMemberTpl">
          <div class="border rounded-3 p-3 mb-2 memberRow" style="background:#fff;">
            <input type="hidden" name="member_id[]" value="0">
            <div class="row g-2 align-items-end">
              <div class="col-md-3">
                <label class="form-label small text-muted">First</label>
                <input type="text" class="form-control" name="member_first_name[]" required>
              </div>

              <div class="col-md-3">
                <label class="form-label small text-muted">Middle</label>
                <input type="text" class="form-control" name="member_middle_name[]">
              </div>

              <div class="col-md-3">
                <label class="form-label small text-muted">Last</label>
                <input type="text" class="form-control" name="member_last_name[]" required>
              </div>

              <div class="col-md-2">
                <label class="form-label small text-muted">Relation</label>
                <select class="form-select" name="member_relation[]" required>
                  <option value="Homeowner">Homeowner</option>
                  <option value="Spouse">Spouse</option>
                  <option value="Child">Child</option>
                  <option value="Parent">Parent</option>
                  <option value="Relative">Relative</option>
                  <option value="Tenant">Tenant</option>
                  <option value="Caretaker">Caretaker</option>
                </select>
              </div>

              <div class="col-md-1 d-grid">
                <button type="button" class="btn btn-outline-danger btn-sm removeMemberBtn">Remove</button>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>

    <script>
      // student note: add/remove household members dynamically
      (function () {
        const wrap = document.getElementById('editMembersWrap');
        const tpl = document.getElementById('editMemberTpl');
        const addBtn = document.getElementById('addEditMemberBtn');

        if (addBtn && wrap && tpl) {
          addBtn.addEventListener('click', function () {
            wrap.insertAdjacentHTML('beforeend', tpl.innerHTML);
          });
        }

        document.addEventListener('click', function (e) {
          if (e.target && e.target.classList.contains('removeMemberBtn')) {
            const row = e.target.closest('.memberRow');
            if (row) row.remove();
          }
        });
      })();
    </script>
  </form>
  <?php
  exit;
}

/* ======================================================================
   B) POST: Save edits (JSON response)
   ====================================================================== */
if (isset($_POST['action']) && $_POST['action'] === 'save_homeowner') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) json_out(['success' => false, 'message' => 'Invalid ID']);

  [$admin_role, $admin_phase] = get_admin_phase_role($conn);
  try {

    $southMeridianLocations =
        loadSouthMeridianLocations();

} catch (Throwable $e) {

    json_out([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

  if (!admin_can_access_homeowner($conn, $admin_role, $admin_phase, $id)) {
    json_out(['success' => false, 'message' => 'Not allowed']);
  }

// Read homeowner fields
$first_name =
    trim(
        (string)(
            $_POST['first_name'] ?? ''
        )
    );

$middle_name =
    trim(
        (string)(
            $_POST['middle_name'] ?? ''
        )
    );

$last_name =
    trim(
        (string)(
            $_POST['last_name'] ?? ''
        )
    );

$contact_number =
    trim(
        (string)(
            $_POST['contact_number'] ?? ''
        )
    );

$email =
    trim(
        (string)(
            $_POST['email'] ?? ''
        )
    );

$phase_in =
    trim(
        (string)(
            $_POST['phase'] ?? ''
        )
    );


/*
|--------------------------------------------------------------------------
| Official Block / Lot
|--------------------------------------------------------------------------
*/

$block =
    (int)(
        $_POST['block'] ?? 0
    );

$lot =
    (int)(
        $_POST['lot'] ?? 0
    );


$locationKey =
    $block . ':' . $lot;


if (
    $block <= 0 ||
    $lot <= 0 ||
    !isset(
        $southMeridianLocations[
            $locationKey
        ]
    )
) {

    json_out([
        'success' => false,
        'message' =>
            'Please select a valid South Meridian Block and Lot.'
    ]);
}


$location =
    $southMeridianLocations[
        $locationKey
    ];


$street =
    trim(
        (string)(
            $location['street'] ?? ''
        )
    );

$map_x =
    (int)(
        $location['map_x'] ?? 0
    );

$map_y =
    (int)(
        $location['map_y'] ?? 0
    );


$house_lot_number =
    "Block {$block} Lot {$lot}";


/*
|--------------------------------------------------------------------------
| Fixed South Meridian Address
|--------------------------------------------------------------------------
*/

$barangay =
    'Salitran IV';

$city_municipality =
    'Dasmarinas City';

$province =
    'Cavite';

$region =
    'CALABARZON';

$zip_code =
    '4114';

$country =
    'Philippines';


/*
|--------------------------------------------------------------------------
| Other homeowner information
|--------------------------------------------------------------------------
*/

$other_location_info =
    trim(
        (string)(
            $_POST['other_location_info'] ?? ''
        )
    );

$length_of_residency =
    trim(
        (string)(
            $_POST['length_of_residency'] ?? ''
        )
    );

$residential_type =
    trim(
        (string)(
            $_POST['residential_type'] ?? ''
        )
    );

$emergency_contact_person =
    trim(
        (string)(
            $_POST['emergency_contact_person'] ?? ''
        )
    );

$emergency_contact_number =
    trim(
        (string)(
            $_POST['emergency_contact_number'] ?? ''
        )
    );
if (
    $first_name === '' ||
    $last_name === '' ||
    $contact_number === '' ||
    $email === '' ||
    $phase_in === '' ||
    $block <= 0 ||
    $lot <= 0 ||
    $length_of_residency === '' ||
    $residential_type === '' ||
    $emergency_contact_person === '' ||
    $emergency_contact_number === ''
) {

    json_out([
        'success' => false,
        'message' =>
            'Please fill in all required fields.'
    ]);
}
if (
    !filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    )
) {

    json_out([
        'success' => false,
        'message' =>
            'Invalid email address.'
    ]);
}


if (
    strlen($contact_number) > 15
) {

    json_out([
        'success' => false,
        'message' =>
            'Contact number must not exceed 15 characters.'
    ]);
}

  if (!in_array($residential_type, ['Owner', 'Renter/Tenant'], true)) {
    json_out(['success' => false, 'message' => 'Invalid residential type.']);
  }

  // Lock phase if not superadmin
  $phase = $phase_in;

  if ($admin_role !== 'superadmin') {
    $stmt = $conn->prepare("SELECT phase FROM homeowners WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!empty($row['phase'])) $phase = (string)$row['phase'];
  }
  $allowedPhases = [
    'Phase 1',
    'Phase 2',
    'Phase 3'
];


if (
    !in_array(
        $phase,
        $allowedPhases,
        true
    )
) {

    json_out([
        'success' => false,
        'message' =>
            'Invalid homeowner phase.'
    ]);
}

  // Check duplicate email (other homeowner)
  $stmt = $conn->prepare("SELECT id FROM homeowners WHERE email=? AND id<>? LIMIT 1");
  $stmt->bind_param("si", $email, $id);
  $stmt->execute();
  $dup = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($dup) {
    json_out(['success' => false, 'message' => 'Email already exists for another homeowner.']);
  }

/*
|--------------------------------------------------------------------------
| Current documents
|--------------------------------------------------------------------------
*/

$stmt =
    $conn->prepare("
        SELECT
            valid_id_path,
            proof_of_billing_path
        FROM homeowners
        WHERE id=?
        LIMIT 1
    ");

$stmt->bind_param(
    "i",
    $id
);

$stmt->execute();

$cur =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();


$old_valid_id_path =
    (string)(
        $cur['valid_id_path'] ?? ''
    );

$old_proof_path =
    (string)(
        $cur['proof_of_billing_path'] ?? ''
    );


$valid_id_path =
    $old_valid_id_path;

$proof_path =
    $old_proof_path;


/*
|--------------------------------------------------------------------------
| Secure optional replacement documents
|--------------------------------------------------------------------------
*/

$new_valid_id_path =
    null;

$new_proof_path =
    null;


try {

    $new_valid_id_path =
        saveOptionalHomeownerDocument(
            $_FILES['valid_id'] ?? [],
            'id'
        );


    $new_proof_path =
        saveOptionalHomeownerDocument(
            $_FILES['proof_of_billing'] ?? [],
            'proof'
        );


    if (
        $new_valid_id_path !== null
    ) {

        $valid_id_path =
            $new_valid_id_path;
    }


    if (
        $new_proof_path !== null
    ) {

        $proof_path =
            $new_proof_path;
    }


} catch (Throwable $uploadError) {

    /*
     * Clean up any new upload created
     * before another upload failed.
     */
    if (
        $new_valid_id_path !== null
    ) {

        removeHomeownerDocument(
            $new_valid_id_path
        );
    }


    if (
        $new_proof_path !== null
    ) {

        removeHomeownerDocument(
            $new_proof_path
        );
    }


    json_out([
        'success' =>
            false,

        'message' =>
            $uploadError->getMessage()
    ]);
}

 // Update homeowner row
$stmt = $conn->prepare("
    UPDATE homeowners SET

        first_name=?,
        middle_name=?,
        last_name=?,

        contact_number=?,
        email=?,

        phase=?,

        house_lot_number=?,
        block=?,
        lot=?,
        street=?,

        barangay=?,
        city_municipality=?,
        province=?,
        region=?,
        zip_code=?,
        country=?,

        other_location_info=?,
        length_of_residency=?,
        residential_type=?,

        emergency_contact_person=?,
        emergency_contact_number=?,

        valid_id_path=?,
        proof_of_billing_path=?,

        map_x=?,
        map_y=?,

        latitude=NULL,
        longitude=NULL

    WHERE id=?
    LIMIT 1
");

$stmt->bind_param(
    "sssssssiissssssssssssssiii",

    $first_name,
    $middle_name,
    $last_name,

    $contact_number,
    $email,

    $phase,

    $house_lot_number,
    $block,
    $lot,
    $street,

    $barangay,
    $city_municipality,
    $province,
    $region,
    $zip_code,
    $country,

    $other_location_info,
    $length_of_residency,
    $residential_type,

    $emergency_contact_person,
    $emergency_contact_number,

    $valid_id_path,
    $proof_path,

    $map_x,
    $map_y,

    $id
);

$ok =
    $stmt->execute();

$stmt->close();


if (!$ok) {

    /*
     * The database update failed,
     * so remove newly uploaded replacements.
     */
    if (
        $new_valid_id_path !== null
    ) {

        removeHomeownerDocument(
            $new_valid_id_path
        );
    }


    if (
        $new_proof_path !== null
    ) {

        removeHomeownerDocument(
            $new_proof_path
        );
    }


    json_out([
        'success' =>
            false,

        'message' =>
            'Failed to update homeowner.'
    ]);
}


/*
|--------------------------------------------------------------------------
| Remove replaced OLD documents
|--------------------------------------------------------------------------
|
| Only after the database successfully points
| to the new files.
|
*/

if (
    $new_valid_id_path !== null &&
    $old_valid_id_path !== ''
) {

    removeHomeownerDocument(
        $old_valid_id_path
    );
}


if (
    $new_proof_path !== null &&
    $old_proof_path !== ''
) {

    removeHomeownerDocument(
        $old_proof_path
    );
}

  /* =========================
     Household members save
     ========================= */

  $member_id = $_POST['member_id'] ?? [];
  $mf = $_POST['member_first_name'] ?? [];
  $mm = $_POST['member_middle_name'] ?? [];
  $ml = $_POST['member_last_name'] ?? [];
  $rel= $_POST['member_relation'] ?? [];

  $keepIds = [];
  $n = is_array($member_id) ? count($member_id) : 0;

  for ($i = 0; $i < $n; $i++) {
    $mid = (int)($member_id[$i] ?? 0);
    $fn  = trim((string)($mf[$i] ?? ''));
    $mn  = trim((string)($mm[$i] ?? ''));
    $ln  = trim((string)($ml[$i] ?? ''));
    $re  = trim((string)($rel[$i] ?? ''));

    // skip incomplete row
    if ($fn === '' || $ln === '' || $re === '') continue;

    if ($mid > 0) {
      $stmt = $conn->prepare("
        UPDATE household_members
        SET first_name=?, middle_name=?, last_name=?, relation=?
        WHERE id=? AND homeowner_id=?
        LIMIT 1
      ");
      $stmt->bind_param("ssssii", $fn, $mn, $ln, $re, $mid, $id);
      $stmt->execute();
      $stmt->close();

      $keepIds[] = $mid;
    } else {
      $stmt = $conn->prepare("
        INSERT INTO household_members (homeowner_id, first_name, middle_name, last_name, relation)
        VALUES (?, ?, ?, ?, ?)
      ");
      $stmt->bind_param("issss", $id, $fn, $mn, $ln, $re);
      $stmt->execute();
      $newId = (int)$stmt->insert_id;
      $stmt->close();

      if ($newId > 0) $keepIds[] = $newId;
    }
  }

  // Delete removed members
  if (count($keepIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
    $sql = "DELETE FROM household_members WHERE homeowner_id=? AND id NOT IN ($placeholders)";

    $stmt = $conn->prepare($sql);

    $types  = 'i' . str_repeat('i', count($keepIds));
    $values = array_merge([$id], $keepIds);

    stmt_bind($stmt, $types, $values);
    $stmt->execute();
    $stmt->close();
  } else {
    $stmt = $conn->prepare("DELETE FROM household_members WHERE homeowner_id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
  }

  json_out(['success' => true, 'message' => 'Homeowner updated successfully.']);
}

// If no valid route matched
http_response_code(400);
echo "Bad request";