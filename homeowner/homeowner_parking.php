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

if (!$user || $user['status'] !== 'approved') {
  session_destroy();
  header("Location: ../index.php"); exit;
}

if ((int)$user['must_change_password'] === 1) {
  header("Location: homeowner_dashboard.php"); exit;
}

$phase      = (string)$user['phase'];
$fullName   = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));
$initials   = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));
$pageTitle  = "Parking • ".$phase;

// Permit quick status (latest per year)
$yearNow = (int)date('Y');
$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE homeowner_id=? AND phase=? AND sticker_year=?
  ORDER BY FIELD(status,'active','pending','expired','revoked','rejected'), updated_at DESC, id DESC
  LIMIT 1
");
$stmt->bind_param("isi", $hid, $phase, $yearNow);
$stmt->execute();
$permitThisYear = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Active permit (any year)
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

// Violation counts
$stmt = $conn->prepare("SELECT COUNT(*) c FROM parking_violations WHERE homeowner_id=? AND phase=? AND status='open'");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$unpaidCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$activePage = basename($_SERVER['PHP_SELF']);
$parkingOpen = in_array($activePage, ['homeowner_parking.php','homeowner_parking_permit.php','homeowner_parking_violations.php'], true);

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
/* Sidebar dropdown */
.sb-dd { display:flex; flex-direction:column; gap:6px; }
.sb-dd-toggle{ display:flex; align-items:center; justify-content:space-between; gap:10px; width:100%; }
.sb-dd-menu{ display:none; padding-left:12px; margin-top:2px; border-left:2px solid rgba(255,255,255,.08); }
.sb-dd.open .sb-dd-menu{ display:block; }
.sb-dd-caret{ transition: transform .15s ease; }
.sb-dd.open .sb-dd-caret{ transform: rotate(180deg); }

.pillx{ display:inline-flex; gap:8px; align-items:center; padding:8px 12px; border-radius:999px; background:#f1f5f9; font-weight:700; }
.req-list li{ margin-bottom: 6px; }
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
        <a class="sb-link sb-dd-toggle <?= $activePage==='homeowner_parking.php' ? 'active' : '' ?>" href="javascript:void(0)" id="sbParkingToggle">
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

      <div class="fb-card mb-4">
        <div class="fb-card-h">
          <h6>🚗 Parking Overview</h6>
          <span class="pill"><?= esc($phase) ?></span>
        </div>
        <div class="fb-card-b">
          <div class="d-flex flex-wrap gap-2">
            <span class="pillx"><i class="bi bi-calendar-check"></i> Sticker Year: <b><?= (int)$yearNow ?></b></span>
            <span class="pillx"><i class="bi bi-exclamation-circle"></i> Unpaid Violations: <b><?= (int)$unpaidCount ?></b></span>
          </div>

          <hr>

          <h6 class="mb-2">Your Permit Status</h6>

          <?php if ($activePermit): ?>
            <div class="alert alert-success">
              <div class="fw-bold mb-1">Active Permit</div>
              <div>Permit No: <b><?= esc($activePermit['permit_no'] ?? '—') ?></b> • Plate: <b><?= esc($activePermit['plate_no'] ?? '') ?></b></div>
              <div>Valid: <b><?= esc($activePermit['valid_from'] ?? '') ?></b> → <b><?= esc($activePermit['valid_until'] ?? '') ?></b></div>
              <div class="mt-2">
                <a class="btn btn-sm btn-outline-success" href="homeowner_parking_permit.php">Renew / Apply</a>
                <a class="btn btn-sm btn-outline-dark" href="homeowner_parking_violations.php">View Violations</a>
              </div>
            </div>
          <?php elseif ($permitThisYear): ?>
            <div class="alert alert-warning">
              <div class="fw-bold mb-1">This Year Request</div>
              <div>Status: <?= badge($permitThisYear['status'] ?? 'pending') ?></div>
              <div>Plate: <b><?= esc($permitThisYear['plate_no'] ?? '') ?></b> • Type: <b><?= esc($permitThisYear['sticker_type'] ?? '') ?></b></div>
              <?php if (!empty($permitThisYear['rejected_reason'])): ?>
                <div class="text-danger mt-1"><b>Reason:</b> <?= esc($permitThisYear['rejected_reason']) ?></div>
              <?php endif; ?>
              <div class="mt-2">
                <a class="btn btn-sm btn-outline-success" href="homeowner_parking_permit.php">Open Permit Page</a>
              </div>
            </div>
          <?php else: ?>
            <div class="alert alert-secondary">
              No permit request found for <?= (int)$yearNow ?> yet.
              <div class="mt-2">
                <a class="btn btn-sm btn-success" href="homeowner_parking_permit.php"><i class="bi bi-plus-circle"></i> Apply for Permit</a>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="fb-card">
        <div class="fb-card-h">
          <h6>📌 Requirements for Yearly Parking Stickers/Permits</h6>
          <span class="pill">Upload on Permit page</span>
        </div>
        <div class="fb-card-b">
          <ul class="req-list mb-2">
            <li><b>Accomplished Application Form</b> (from barangay hall/security office)</li>
            <li><b>Proof of Residency</b> (Barangay Clearance / Certificate of Residency)</li>
            <li><b>Vehicle Documents</b> (clear photocopy of <b>OR</b> and <b>CR</b>)</li>
            <li><b>Proof of Parking Space</b> (photo of garage / affidavit; no street parking)</li>
            <li><b>Proof of Payment</b> (latest association dues / Cedula)</li>
            <li><b>Driver’s License</b> (photocopy)</li>
            <li><b>Deed of Sale</b> (required if OR/CR not yet updated to current owner)</li>
          </ul>
          <div class="text-muted fw-semibold small">
            Tip: To avoid delays, upload clear photos or PDF copies.
          </div>

          <div class="mt-3">
            <a class="btn btn-success" href="homeowner_parking_permit.php">
              <i class="bi bi-card-checklist me-1"></i> Apply / Renew Permit
            </a>
            <a class="btn btn-outline-dark" href="homeowner_parking_violations.php">
              <i class="bi bi-receipt-cutoff me-1"></i> My Violations
            </a>
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
