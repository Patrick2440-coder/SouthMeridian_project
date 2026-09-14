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


function esc($v) {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* =========================
   4) HELPER FUNCTIONS
   ========================= */

/* Student note:
*/
function admin_can_access_homeowner(mysqli $conn, string $admin_role, string $admin_phase, int $homeowner_id): bool {
  if ($admin_role === 'superadmin') return true;

  $stmt = $conn->prepare("SELECT id FROM homeowners WHERE id=? AND phase=? LIMIT 1");
  $stmt->bind_param("is", $homeowner_id, $admin_phase);
  $stmt->execute();
  $ok = (bool)$stmt->get_result()->fetch_assoc();
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

  $lat = (string)($home['latitude'] ?? '');
  $lng = (string)($home['longitude'] ?? '');
  $validId = (string)($home['valid_id_path'] ?? '');
  $proof   = (string)($home['proof_of_billing_path'] ?? '');

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

      <div class="col-md-6">
        <label class="form-label fw-semibold">House / Lot Number</label>
        <input type="text" class="form-control" name="house_lot_number" value="<?= esc($home['house_lot_number'] ?? '') ?>" required>
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
               value="Salitran 4"
               readonly>
        <div class="form-text">Fixed community location.</div>
      </div>

      <div class="col-md-4">
        <label class="form-label fw-semibold">City / Municipality</label>
        <input type="text"
               class="form-control bg-light"
               name="city_municipality"
               value="Dasmariñas City"
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
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div>
            <div class="fw-bold">Pin Location</div>
            <div class="text-muted" style="font-size:13px;">Drag the marker to update location.</div>
          </div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCenterMarker">Center Marker</button>
            <button type="button" class="btn btn-outline-success btn-sm" id="btnUseCurrentMarker">Use Marker Position</button>
          </div>
        </div>

        <input type="hidden" name="latitude" id="edit_lat" value="<?= esc($lat) ?>">
        <input type="hidden" name="longitude" id="edit_lng" value="<?= esc($lng) ?>">

        <div id="editMap"
             data-lat="<?= esc($lat) ?>"
             data-lng="<?= esc($lng) ?>"
             style="height:420px; border:2px solid #077f46; border-radius:14px; overflow:hidden; margin-top:10px;"></div>
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
            <input type="file" class="form-control" name="valid_id">
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Proof of Billing (replace optional)</label>
            <?php if ($proof): ?>
              <div class="small mb-1">Current: <a href="<?= esc($proof) ?>" target="_blank">Open</a></div>
            <?php endif; ?>
            <input type="file" class="form-control" name="proof_of_billing">
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

  if (!admin_can_access_homeowner($conn, $admin_role, $admin_phase, $id)) {
    json_out(['success' => false, 'message' => 'Not allowed']);
  }

  // Read fields
  $first_name      = trim((string)($_POST['first_name'] ?? ''));
  $middle_name     = trim((string)($_POST['middle_name'] ?? ''));
  $last_name       = trim((string)($_POST['last_name'] ?? ''));
  $contact_number  = trim((string)($_POST['contact_number'] ?? ''));
  $email           = trim((string)($_POST['email'] ?? ''));
  $phase_in                 = trim((string)($_POST['phase'] ?? ''));
  $house_lot_number         = trim((string)($_POST['house_lot_number'] ?? ''));
  // Fixed South Meridian community location.
  // Do not trust browser-submitted values for these fields.
  $barangay                 = 'Salitran 4';
  $city_municipality        = 'Dasmariñas City';
  $province                 = 'Cavite';
  $other_location_info      = trim((string)($_POST['other_location_info'] ?? ''));
  $length_of_residency      = trim((string)($_POST['length_of_residency'] ?? ''));
  $residential_type         = trim((string)($_POST['residential_type'] ?? ''));
  $emergency_contact_person = trim((string)($_POST['emergency_contact_person'] ?? ''));
  $emergency_contact_number = trim((string)($_POST['emergency_contact_number'] ?? ''));
  $lat                      = trim((string)($_POST['latitude'] ?? ''));
  $lng                      = trim((string)($_POST['longitude'] ?? ''));

  if (
    $first_name === '' ||
    $last_name === '' ||
    $contact_number === '' ||
    $email === '' ||
    $phase_in === '' ||
    $house_lot_number === '' ||
    $barangay === '' ||
    $city_municipality === '' ||
    $province === '' ||
    $length_of_residency === '' ||
    $residential_type === '' ||
    $emergency_contact_person === '' ||
    $emergency_contact_number === ''
  ) {
    json_out(['success' => false, 'message' => 'Please fill in all required fields.']);
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

  // Check duplicate email (other homeowner)
  $stmt = $conn->prepare("SELECT id FROM homeowners WHERE email=? AND id<>? LIMIT 1");
  $stmt->bind_param("si", $email, $id);
  $stmt->execute();
  $dup = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($dup) {
    json_out(['success' => false, 'message' => 'Email already exists for another homeowner.']);
  }

  // Get current file paths
  $stmt = $conn->prepare("SELECT valid_id_path, proof_of_billing_path FROM homeowners WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $cur = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $valid_id_path = (string)($cur['valid_id_path'] ?? '');
  $proof_path    = (string)($cur['proof_of_billing_path'] ?? '');

  // Upload folder
  $fsUploadDir = __DIR__ . "/../uploads/";
  $dbUploadDir = "uploads/";

  if (!is_dir($fsUploadDir)) {
    @mkdir($fsUploadDir, 0755, true);
  }

  // Upload valid_id (optional)
  if (!empty($_FILES['valid_id']['name']) && is_uploaded_file($_FILES['valid_id']['tmp_name'])) {
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string)$_FILES['valid_id']['name']));
    $dbPath = $dbUploadDir . time() . "_id_" . $safeName;
    $fsPath = $fsUploadDir . basename($dbPath);

    if (move_uploaded_file($_FILES['valid_id']['tmp_name'], $fsPath)) {
      $valid_id_path = $dbPath;
    }
  }

  // Upload proof_of_billing (optional)
  if (!empty($_FILES['proof_of_billing']['name']) && is_uploaded_file($_FILES['proof_of_billing']['tmp_name'])) {
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string)$_FILES['proof_of_billing']['name']));
    $dbPath = $dbUploadDir . time() . "_proof_" . $safeName;
    $fsPath = $fsUploadDir . basename($dbPath);

    if (move_uploaded_file($_FILES['proof_of_billing']['tmp_name'], $fsPath)) {
      $proof_path = $dbPath;
    }
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
      barangay=?,
      city_municipality=?,
      province=?,
      other_location_info=?,
      length_of_residency=?,
      residential_type=?,
      emergency_contact_person=?,
      emergency_contact_number=?,
      latitude=?,
      longitude=?,
      valid_id_path=?,
      proof_of_billing_path=?
    WHERE id=?
    LIMIT 1
  ");

  $stmt->bind_param(
    "sssssssssssssssssssi",
    $first_name,
    $middle_name,
    $last_name,
    $contact_number,
    $email,
    $phase,
    $house_lot_number,
    $barangay,
    $city_municipality,
    $province,
    $other_location_info,
    $length_of_residency,
    $residential_type,
    $emergency_contact_person,
    $emergency_contact_number,
    $lat,
    $lng,
    $valid_id_path,
    $proof_path,
    $id
  );

  $ok = $stmt->execute();
  $stmt->close();

  if (!$ok) {
    json_out(['success' => false, 'message' => 'Failed to update homeowner.']);
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