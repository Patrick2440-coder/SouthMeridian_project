<?php
session_start();

// ---------- Admin guard ----------
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
    http_response_code(401);
    exit('Unauthorized');
}

// ---------- DB ----------
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) { http_response_code(500); exit("DB error"); }
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---------- Helpers ----------
function admin_can_access_homeowner(mysqli $conn, string $admin_role, string $admin_phase, int $homeowner_id): bool {
    if ($admin_role === 'superadmin') return true;
    $stmt = $conn->prepare("SELECT id FROM homeowners WHERE id=? AND phase=? LIMIT 1");
    $stmt->bind_param("is", $homeowner_id, $admin_phase);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ok;
}

function get_admin_phase_role(mysqli $conn): array {
    $admin_id = (int)($_SESSION['admin_id'] ?? 0);
    $role = (string)($_SESSION['admin_role'] ?? '');
    $phase = (string)($_SESSION['admin_phase'] ?? '');

    // If session doesn't have phase/role for some reason, try DB
    if ($admin_id > 0 && ($role === '' || $phase === '')) {
        $stmt = $conn->prepare("SELECT role, phase FROM admins WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $a = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $role  = $a['role'] ?? $role;
        $phase = $a['phase'] ?? $phase;
    }
    return [$role, $phase];
}

function json_out($arr){
    header('Content-Type: application/json');
    echo json_encode($arr);
    exit;
}

// ======================================================================
// A) AJAX: Render modal HTML
// ======================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'edit_homeowner') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) exit('<div class="p-4"><div class="alert alert-warning mb-0">Invalid ID.</div></div>');

    [$admin_role, $admin_phase] = get_admin_phase_role($conn);

    if (!admin_can_access_homeowner($conn, $admin_role, $admin_phase, $id)) {
        http_response_code(403);
        exit('<div class="p-4"><div class="alert alert-danger mb-0">Not allowed.</div></div>');
    }

    // homeowner
    $stmt = $conn->prepare("SELECT * FROM homeowners WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $home = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$home) exit('<div class="p-4"><div class="alert alert-danger mb-0">Homeowner not found.</div></div>');

    // members
    $stmt = $conn->prepare("SELECT * FROM household_members WHERE homeowner_id=? ORDER BY id ASC");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $members = $stmt->get_result();
    $stmt->close();

    $lat = $home['latitude'] ?? '';
    $lng = $home['longitude'] ?? '';
    $validId = $home['valid_id_path'] ?? '';
    $proof   = $home['proof_of_billing_path'] ?? '';

    ob_start();
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
              <?php foreach(['Phase 1','Phase 2','Phase 3'] as $p): ?>
                <option value="<?= esc($p) ?>" <?= (($home['phase'] ?? '') === $p) ? 'selected' : '' ?>><?= esc($p) ?></option>
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
                <div class="small mb-1">
                  Current: <a href="<?= esc($validId) ?>" target="_blank">Open</a>
                </div>
              <?php endif; ?>
              <input type="file" class="form-control" name="valid_id">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Proof of Billing (replace optional)</label>
              <?php if ($proof): ?>
                <div class="small mb-1">
                  Current: <a href="<?= esc($proof) ?>" target="_blank">Open</a>
                </div>
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
            <?php if ($members->num_rows > 0): ?>
              <?php while($m = $members->fetch_assoc()): ?>
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
                          $rels = ['Homeowner','Spouse','Child','Parent','Relative','Tenant','Caretaker'];
                          $cur = (string)($m['relation'] ?? '');
                          foreach($rels as $r){
                            $sel = ($cur === $r) ? 'selected' : '';
                            echo '<option '.$sel.' value="'.esc($r).'">'.esc($r).'</option>';
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

          <!-- Template row -->
          <template id="editMemberTpl">
            <div class="border rounded-3 p-3 mb-2 memberRow" style="background:#fff;">
              <input type="hidden" name="member_id[]" value="0">
              <div class="row g-2 align-items-end">
                <div class="col-md-3">
                  <label class="form-label small text-muted">First</label>
                  <input type="text" class="form-control" name="member_first_name[]" value="" required>
                </div>
                <div class="col-md-3">
                  <label class="form-label small text-muted">Middle</label>
                  <input type="text" class="form-control" name="member_middle_name[]" value="">
                </div>
                <div class="col-md-3">
                  <label class="form-label small text-muted">Last</label>
                  <input type="text" class="form-control" name="member_last_name[]" value="" required>
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
    </form>
    <?php
    echo ob_get_clean();
    exit;
}

// ======================================================================
// B) POST: Save edits (JSON response)
// ======================================================================
if (isset($_POST['action']) && $_POST['action'] === 'save_homeowner') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_out(['success'=>false,'message'=>'Invalid ID']);

    [$admin_role, $admin_phase] = get_admin_phase_role($conn);
    if (!admin_can_access_homeowner($conn, $admin_role, $admin_phase, $id)) {
        json_out(['success'=>false,'message'=>'Not allowed']);
    }

    // Read fields
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phase = trim($_POST['phase'] ?? '');
    $house_lot_number = trim($_POST['house_lot_number'] ?? '');
    $lat = trim($_POST['latitude'] ?? '');
    $lng = trim($_POST['longitude'] ?? '');

    if ($first_name==='' || $last_name==='' || $contact_number==='' || $email==='' || $phase==='' || $house_lot_number==='') {
        json_out(['success'=>false,'message'=>'Please fill in all required fields.']);
    }

    // If not superadmin, force phase unchanged
    if ($admin_role !== 'superadmin') {
        $stmt = $conn->prepare("SELECT phase FROM homeowners WHERE id=? LIMIT 1");
        $stmt->bind_param("i",$id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $phase = $row['phase'] ?? $phase;
    }

    // Email uniqueness check (homeowners)
    $stmt = $conn->prepare("SELECT id FROM homeowners WHERE email=? AND id<>? LIMIT 1");
    $stmt->bind_param("si", $email, $id);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($dup) json_out(['success'=>false,'message'=>'Email already exists for another homeowner.']);

    // Existing file paths
    $stmt = $conn->prepare("SELECT valid_id_path, proof_of_billing_path FROM homeowners WHERE id=? LIMIT 1");
    $stmt->bind_param("i",$id);
    $stmt->execute();
    $cur = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $valid_id_path = $cur['valid_id_path'] ?? '';
    $proof_path = $cur['proof_of_billing_path'] ?? '';

    // Uploads optional
    $uploadDir = "uploads/";
    if(!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if (!empty($_FILES['valid_id']['name']) && is_uploaded_file($_FILES['valid_id']['tmp_name'])) {
        $valid_id_path = $uploadDir . time() . "_id_" . preg_replace('/[^A-Za-z0-9._-]/','_', basename($_FILES['valid_id']['name']));
        move_uploaded_file($_FILES['valid_id']['tmp_name'], $valid_id_path);
    }
    if (!empty($_FILES['proof_of_billing']['name']) && is_uploaded_file($_FILES['proof_of_billing']['tmp_name'])) {
        $proof_path = $uploadDir . time() . "_proof_" . preg_replace('/[^A-Za-z0-9._-]/','_', basename($_FILES['proof_of_billing']['name']));
        move_uploaded_file($_FILES['proof_of_billing']['tmp_name'], $proof_path);
    }

    // Update homeowner
    $stmt = $conn->prepare("
        UPDATE homeowners SET
          first_name=?,
          middle_name=?,
          last_name=?,
          contact_number=?,
          email=?,
          phase=?,
          house_lot_number=?,
          latitude=?,
          longitude=?,
          valid_id_path=?,
          proof_of_billing_path=?
        WHERE id=?
        LIMIT 1
    ");
    $stmt->bind_param(
        "sssssssssssi",
        $first_name, $middle_name, $last_name,
        $contact_number, $email, $phase, $house_lot_number,
        $lat, $lng, $valid_id_path, $proof_path, $id
    );
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) json_out(['success'=>false,'message'=>'Failed to update homeowner.']);

    // Members upsert
    $member_id = $_POST['member_id'] ?? [];
    $mf = $_POST['member_first_name'] ?? [];
    $mm = $_POST['member_middle_name'] ?? [];
    $ml = $_POST['member_last_name'] ?? [];
    $rel= $_POST['member_relation'] ?? [];

    // Build set of keep IDs
    $keepIds = [];
    for ($i=0; $i<count($member_id); $i++) {
        $mid = (int)$member_id[$i];
        $fn = trim((string)($mf[$i] ?? ''));
        $mn = trim((string)($mm[$i] ?? ''));
        $ln = trim((string)($ml[$i] ?? ''));
        $re = trim((string)($rel[$i] ?? ''));

        if ($fn==='' || $ln==='' || $re==='') continue;

        if ($mid > 0) {
            // update existing (ensure belongs to homeowner)
            $stmt = $conn->prepare("
                UPDATE household_members
                SET first_name=?, middle_name=?, last_name=?, relation=?
                WHERE id=? AND homeowner_id=?
                LIMIT 1
            ");
            $stmt->bind_param("ssssii", $fn,$mn,$ln,$re,$mid,$id);
            $stmt->execute();
            $stmt->close();
            $keepIds[] = $mid;
        } else {
            // insert new
            $stmt = $conn->prepare("
                INSERT INTO household_members(homeowner_id, first_name, middle_name, last_name, relation)
                VALUES(?,?,?,?,?)
            ");
            $stmt->bind_param("issss", $id,$fn,$mn,$ln,$re);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            if ($newId) $keepIds[] = (int)$newId;
        }
    }

    // delete removed members (only those that belong to this homeowner)
    if (count($keepIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $types = str_repeat('i', count($keepIds) + 1);
        $sql = "DELETE FROM household_members WHERE homeowner_id=? AND id NOT IN ($placeholders)";
        $stmt = $conn->prepare($sql);

        $params = array_merge([$id], $keepIds);
        $bind_names[] = $types;
        foreach ($params as $k => $v) { $bind_names[] = &$params[$k]; }
        call_user_func_array([$stmt, 'bind_param'], $bind_names);

        $stmt->execute();
        $stmt->close();
    } else {
        // if no rows submitted, delete all members
        $stmt = $conn->prepare("DELETE FROM household_members WHERE homeowner_id=?");
        $stmt->bind_param("i",$id);
        $stmt->execute();
        $stmt->close();
    }

    json_out(['success'=>true,'message'=>'Homeowner updated successfully.']);
}

http_response_code(400);
echo "Bad request";
