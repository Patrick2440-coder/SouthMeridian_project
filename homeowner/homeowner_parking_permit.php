<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
  header("Location: ../index.php"); exit;
}

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$hid = (int)$_SESSION['homeowner_id'];

$stmt = $conn->prepare("SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number
                        FROM homeowners WHERE id=? LIMIT 1");
$stmt->bind_param("i", $hid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user['status'] !== 'approved') { session_destroy(); header("Location: ../index.php"); exit; }
if ((int)$user['must_change_password'] === 1) { header("Location: homeowner_dashboard.php"); exit; }

$phase      = (string)$user['phase'];
$fullName   = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));
$initials   = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));

$pageTitle = "Apply / Renew Permit • ".$phase;
$yearNow = (int)date('Y');

$activePage = basename($_SERVER['PHP_SELF']);
$parkingOpen = in_array($activePage, ['homeowner_parking.php','homeowner_parking_permit.php','homeowner_parking_violations.php'], true);

function safe_ext(string $name): string {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  return preg_replace('/[^a-z0-9]+/','', $ext);
}

function save_upload(string $field, string $baseDir): ?string {
  if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
  if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;

  $tmp  = $_FILES[$field]['tmp_name'];
  $orig = (string)$_FILES[$field]['name'];
  $ext  = safe_ext($orig);

  $allowed = ['pdf','jpg','jpeg','png'];
  if (!in_array($ext, $allowed, true)) return null;

  if (!is_dir($baseDir)) @mkdir($baseDir, 0777, true);

  $newName = time().'_'.bin2hex(random_bytes(6)).'.'.$ext;
  $destFs  = rtrim($baseDir,'/').'/'.$newName;

  if (!move_uploaded_file($tmp, $destFs)) return null;

  // Return web path (same as FS if under project root)
  return $destFs;
}

// Fetch active permit (prefill)
$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE homeowner_id=? AND phase=? AND status='active'
  ORDER BY valid_until DESC, id DESC
  LIMIT 1
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$activePermit = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch latest request for this year
$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE homeowner_id=? AND phase=? AND sticker_year=?
  ORDER BY updated_at DESC, id DESC
  LIMIT 1
");
$stmt->bind_param("isi", $hid, $phase, $yearNow);
$stmt->execute();
$latestThisYear = $stmt->get_result()->fetch_assoc();
$stmt->close();

$msg = ""; $msgType = "success";
function set_msg(&$msg,&$msgType,$t,$m){ $msgType=$t; $msg=$m; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_permit'])) {
  $plate = strtoupper(trim((string)($_POST['plate_no'] ?? '')));
  $make  = trim((string)($_POST['vehicle_make'] ?? ''));
  $model = trim((string)($_POST['vehicle_model'] ?? ''));
  $color = trim((string)($_POST['vehicle_color'] ?? ''));
  $type  = (string)($_POST['sticker_type'] ?? 'resident');

  if ($plate === '' || strlen($plate) < 4) {
    set_msg($msg,$msgType,"danger","Please enter a valid plate number.");
  } else {
    $requestType = $activePermit ? 'renew' : 'new';
    $renewOfId   = $activePermit ? (int)$activePermit['id'] : null;

    // Uploads directory (relative path inside your project)
    $dir = "uploads/parking_permits/".$hid;
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $application_form_path     = save_upload('application_form', $dir);
    $proof_of_residency_path   = save_upload('proof_of_residency', $dir);
    $or_cr_path                = save_upload('or_cr', $dir);
    $proof_parking_space_path  = save_upload('proof_parking_space', $dir);
    $proof_of_payment_path     = save_upload('proof_of_payment', $dir);
    $drivers_license_path      = save_upload('drivers_license', $dir);
    $deed_of_sale_path         = save_upload('deed_of_sale', $dir);

    // Requirements validation (deed_of_sale optional)
    $missing = [];
    if (!$application_form_path)    $missing[] = "Application Form";
    if (!$proof_of_residency_path)  $missing[] = "Proof of Residency";
    if (!$or_cr_path)               $missing[] = "Vehicle OR/CR";
    if (!$proof_parking_space_path) $missing[] = "Proof of Parking Space";
    if (!$proof_of_payment_path)    $missing[] = "Proof of Payment (Dues/Cedula)";
    if (!$drivers_license_path)     $missing[] = "Driver’s License";

    // Prevent multiple pending requests for same year
    $stmt = $conn->prepare("
      SELECT COUNT(*) c
      FROM parking_permits
      WHERE homeowner_id=? AND phase=? AND sticker_year=? AND status='pending'
    ");
    $stmt->bind_param("isi", $hid, $phase, $yearNow);
    $stmt->execute();
    $pendingExists = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    if ($pendingExists > 0) {
      set_msg($msg,$msgType,"warning","You already have a pending permit request for $yearNow. Please wait for approval.");
    } elseif ($missing) {
      set_msg($msg,$msgType,"danger","Missing required uploads: ".implode(", ", $missing));
    } else {
      $stmt = $conn->prepare("
        INSERT INTO parking_permits
          (homeowner_id, phase, request_type, renew_of_id, sticker_year, sticker_type,
           plate_no, vehicle_make, vehicle_model, vehicle_color, status,
           application_form_path, proof_of_residency_path, or_cr_path, proof_parking_space_path,
           proof_of_payment_path, drivers_license_path, deed_of_sale_path)
        VALUES (?,?,?,?,?,?,?,?,?,'pending',?,?,?,?,?,?,?)
      ");
      // bind types: i s s i i s s s s  then 7 strings
      $renewIdVal = $renewOfId ? $renewOfId : null;

      $stmt->bind_param(
        "issiiissssssssss",
        $hid, $phase, $requestType, $renewIdVal, $yearNow, $type,
        $plate, $make, $model, $color,
        $application_form_path, $proof_of_residency_path, $or_cr_path, $proof_parking_space_path,
        $proof_of_payment_path, $drivers_license_path, $deed_of_sale_path
      );

      $ok = $stmt->execute();
      $stmt->close();

      if ($ok) {
        set_msg($msg,$msgType,"success","Permit request submitted successfully. Status: pending.");
        header("Location: homeowner_parking_permit.php?ok=1"); exit;
      } else {
        set_msg($msg,$msgType,"danger","Failed to submit request. Please try again.");
      }
    }
  }
}

if (isset($_GET['ok'])) {
  $msgType = "success";
  $msg = "Permit request submitted successfully. Status: pending.";
}

// Reload latest request
$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE homeowner_id=? AND phase=? AND sticker_year=?
  ORDER BY updated_at DESC, id DESC
  LIMIT 1
");
$stmt->bind_param("isi", $hid, $phase, $yearNow);
$stmt->execute();
$latestThisYear = $stmt->get_result()->fetch_assoc();
$stmt->close();

function badge($status){
  $status = (string)$status;
  $cls = "secondary";
  if ($status === 'active') $cls = "success";
  if ($status === 'pending') $cls = "warning";
  if (in_array($status, ['rejected','revoked','expired'], true)) $cls = "danger";
  return '<span class="badge bg-'.$cls.'">'.htmlspecialchars($status).'</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= esc($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/homeowner_dashboard.css">

<style>
.sb-dd{display:flex;flex-direction:column;gap:6px;}
.sb-dd-toggle{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;}
.sb-dd-menu{display:none;padding-left:12px;margin-top:2px;border-left:2px solid rgba(255,255,255,.08);}
.sb-dd.open .sb-dd-menu{display:block;}
.sb-dd-caret{transition:transform .15s ease;}
.sb-dd.open .sb-dd-caret{transform:rotate(180deg);}
.req-list li{ margin-bottom:6px; }
</style>
</head>

<body>
<div class="app-shell">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sb-head">
      <div class="sb-brand">
        <i class="bi bi-grid-fill"></i>
        <span class="sb-brand-text">HOA Menu</span>
      </div>
    </div>

    <div class="sb-user">
      <div class="sb-avatar"><?= esc($initials) ?></div>
      <div class="sb-user-text">
        <p class="sb-name"><?= esc($fullName) ?></p>
        <p class="sb-meta"><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?></p>
      </div>
    </div>

    <nav class="sb-nav">
      <a class="sb-link" href="homeowner_dashboard.php">
        <i class="bi bi-house-door-fill"></i> <span>Dashboard</span>
      </a>
      <a class="sb-link" href="homeowner_dashboard.php#feed">
        <i class="bi bi-megaphone-fill"></i> <span>Announcement Feed</span>
      </a>
      <a class="sb-link" href="homeowner_pay_dues.php">
        <i class="bi bi-cash-coin"></i> <span>Pay Monthly Dues</span>
      </a>

      <!-- PARKING DROPDOWN -->
      <div class="sb-dd <?= $parkingOpen ? 'open' : '' ?>" id="sbParking">
        <a class="sb-link sb-dd-toggle <?= $activePage==='homeowner_parking_permit.php' ? 'active' : '' ?>" href="javascript:void(0)" id="sbParkingToggle">
          <span><i class="bi bi-car-front-fill"></i> <span>Parking</span></span>
          <i class="bi bi-chevron-down sb-dd-caret"></i>
        </a>
        <div class="sb-dd-menu">
          <a class="sb-link <?= $activePage==='homeowner_parking.php' ? 'active' : '' ?>" href="homeowner_parking.php">
            <i class="bi bi-info-circle-fill"></i> <span>Parking Overview</span>
          </a>
          <a class="sb-link <?= $activePage==='homeowner_parking_permit.php' ? 'active' : '' ?>" href="homeowner_parking_permit.php">
            <i class="bi bi-card-checklist"></i> <span>Apply / Renew Permit</span>
          </a>
          <a class="sb-link <?= $activePage==='homeowner_parking_violations.php' ? 'active' : '' ?>" href="homeowner_parking_violations.php">
            <i class="bi bi-receipt-cutoff"></i> <span>My Violations</span>
          </a>
        </div>
      </div>

      <a class="sb-link" href="logout.php">
        <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
      </a>
    </nav>
  </aside>

  <!-- MAIN -->
  <div class="main-area">
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
      <div class="container-xl">
        <a class="navbar-brand fw-bold text-success" href="homeowner_dashboard.php">🏘 HOA Community</a>
        <div class="ms-auto d-flex align-items-center gap-3">
          <div class="small text-muted d-none d-md-block">
            Logged in as <b><?= esc($fullName) ?></b> (<?= esc($phase) ?>)
          </div>
          <a href="logout.php" class="btn btn-sm btn-outline-success">Logout</a>
        </div>
      </div>
    </nav>

    <div class="container-xl my-4">

      <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= esc($msgType) ?>"><?= esc($msg) ?></div>
      <?php endif; ?>

      <div class="fb-card mb-4">
        <div class="fb-card-h">
          <h6>📝 Apply / Renew Parking Permit (<?= (int)$yearNow ?>)</h6>
          <span class="pill"><?= esc($phase) ?></span>
        </div>
        <div class="fb-card-b">
          <div class="row g-3">
            <div class="col-lg-6">
              <div class="p-3 rounded-4" style="border:1px solid #eef2f7; background:#fff;">
                <div class="fw-bold mb-2">Current Status</div>
                <?php if ($activePermit): ?>
                  <div class="alert alert-success mb-0">
                    You have an <b>active</b> permit: <b><?= esc($activePermit['permit_no'] ?? '—') ?></b><br>
                    Plate: <b><?= esc($activePermit['plate_no'] ?? '') ?></b><br>
                    Valid: <b><?= esc($activePermit['valid_from'] ?? '') ?></b> → <b><?= esc($activePermit['valid_until'] ?? '') ?></b><br>
                    Request type will be <b>renew</b>.
                  </div>
                <?php elseif ($latestThisYear): ?>
                  <div class="alert alert-warning mb-0">
                    Latest request this year: <?= badge($latestThisYear['status'] ?? 'pending') ?><br>
                    Plate: <b><?= esc($latestThisYear['plate_no'] ?? '') ?></b>
                    <?php if (!empty($latestThisYear['rejected_reason'])): ?>
                      <div class="text-danger mt-1"><b>Reason:</b> <?= esc($latestThisYear['rejected_reason']) ?></div>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="alert alert-secondary mb-0">
                    No request found yet for <?= (int)$yearNow ?>. You can apply now.
                  </div>
                <?php endif; ?>
              </div>

              <div class="p-3 rounded-4 mt-3" style="border:1px solid #eef2f7; background:#fff;">
                <div class="fw-bold mb-2">Requirements</div>
                <ul class="req-list mb-0">
                  <li><b>Application Form</b></li>
                  <li><b>Proof of Residency</b></li>
                  <li><b>Vehicle OR/CR</b></li>
                  <li><b>Proof of Parking Space</b></li>
                  <li><b>Proof of Payment</b> (Dues/Cedula)</li>
                  <li><b>Driver’s License</b></li>
                  <li><b>Deed of Sale</b> (only if OR/CR not updated)</li>
                </ul>
              </div>
            </div>

            <div class="col-lg-6">
              <form method="POST" enctype="multipart/form-data" class="p-3 rounded-4" style="border:1px solid #eef2f7; background:#fff;">
                <input type="hidden" name="submit_permit" value="1">

                <div class="fw-bold mb-2">Vehicle Details</div>
                <div class="mb-2">
                  <label class="form-label fw-semibold">Plate Number</label>
                  <input type="text" name="plate_no" class="form-control" required maxlength="30"
                         value="<?= esc($activePermit['plate_no'] ?? ($latestThisYear['plate_no'] ?? '')) ?>">
                </div>

                <div class="row g-2">
                  <div class="col-md-4">
                    <label class="form-label fw-semibold">Brand</label>
                    <input type="text" name="vehicle_make" class="form-control" maxlength="80"
                           value="<?= esc($activePermit['vehicle_make'] ?? ($latestThisYear['vehicle_make'] ?? '')) ?>">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label fw-semibold">Model</label>
                    <input type="text" name="vehicle_model" class="form-control" maxlength="80"
                           value="<?= esc($activePermit['vehicle_model'] ?? ($latestThisYear['vehicle_model'] ?? '')) ?>">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label fw-semibold">Color</label>
                    <input type="text" name="vehicle_color" class="form-control" maxlength="50"
                           value="<?= esc($activePermit['vehicle_color'] ?? ($latestThisYear['vehicle_color'] ?? '')) ?>">
                  </div>
                </div>

                <div class="mt-2 mb-3">
                  <label class="form-label fw-semibold">Sticker Type</label>
                  <select name="sticker_type" class="form-select">
                    <option value="resident">Resident</option>
                    <option value="visitor">Visitor</option>
                  </select>
                </div>

                <hr>

                <div class="fw-bold mb-2">Upload Requirements</div>
                <div class="mb-2">
                  <label class="form-label fw-semibold">Application Form (PDF/JPG/PNG)</label>
                  <input type="file" name="application_form" class="form-control" required accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div class="mb-2">
                  <label class="form-label fw-semibold">Proof of Residency</label>
                  <input type="file" name="proof_of_residency" class="form-control" required accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div class="mb-2">
                  <label class="form-label fw-semibold">Vehicle OR/CR</label>
                  <input type="file" name="or_cr" class="form-control" required accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div class="mb-2">
                  <label class="form-label fw-semibold">Proof of Parking Space</label>
                  <input type="file" name="proof_parking_space" class="form-control" required accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div class="mb-2">
                  <label class="form-label fw-semibold">Proof of Payment (Dues/Cedula)</label>
                  <input type="file" name="proof_of_payment" class="form-control" required accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div class="mb-2">
                  <label class="form-label fw-semibold">Driver’s License</label>
                  <input type="file" name="drivers_license" class="form-control" required accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div class="mb-3">
                  <label class="form-label fw-semibold">Deed of Sale (optional)</label>
                  <input type="file" name="deed_of_sale" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                  <div class="text-muted small fw-semibold mt-1">
                    Upload only if OR/CR is not updated to the current owner.
                  </div>
                </div>

                <button class="btn btn-success w-100 fw-bold py-2">
                  <i class="bi bi-send me-1"></i> Submit Permit Request
                </button>

                <div class="text-muted small fw-semibold mt-2">
                  You can submit only one pending request per year.
                </div>
              </form>
            </div>
          </div>

        </div>
      </div>

      <div class="mt-4 text-center text-muted small fw-semibold">
        © South Meridian Homes Salitran
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const wrap = document.getElementById('sbParking');
  const btn  = document.getElementById('sbParkingToggle');
  if(!wrap || !btn) return;
  btn.addEventListener('click', () => wrap.classList.toggle('open'));
})();
</script>
</body>
</html>
