<?php
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
  header("Location: ../index.php");
  exit;
}

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

require_once 'tenant_module_guard.php';

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function badge($status, $paymentStatus = ''){
  $status = strtolower(trim((string)$status));
  $paymentStatus = strtolower(trim((string)$paymentStatus));

  if ($status === 'active') {
    return '<span class="badge bg-success">Active</span>';
  }

  if ($status === 'pending' && $paymentStatus === 'for payment') {
    return '<span class="badge bg-info text-dark">For Payment</span>';
  }

  if ($status === 'pending') {
    return '<span class="badge bg-warning text-dark">Pending</span>';
  }

  if (in_array($status, ['rejected','revoked','expired'], true)) {
    return '<span class="badge bg-danger">'.htmlspecialchars(ucfirst($status)).'</span>';
  }

  return '<span class="badge bg-secondary">'.htmlspecialchars(ucfirst($status)).'</span>';
}

function duration_label(string $duration): string {
  $map = [
    '1_month'   => '1 Month',
    '3_months'  => '3 Months',
    '6_months'  => '6 Months',
    '1_year'    => '1 Year',
  ];
  return $map[$duration] ?? $duration;
}

function payment_label(string $payment): string {
  $map = [
    'online' => 'Online Payment',
    'cash'   => 'Cash / Physical Payment',
  ];
  return $map[$payment] ?? $payment;
}

function payment_status_label(string $status): string {
  $status = strtolower(trim((string)$status));
  if ($status === 'unpaid' || $status === 'not paid') return 'Not Paid';
  if ($status === 'paid') return 'Paid';
  if ($status === 'for payment') return 'For Payment';
  if ($status === 'pending') return 'Pending';
  return ucfirst($status);
}

function vehicle_type_label(string $type): string {
  $map = [
    'car' => 'Car',
    'motorcycle' => 'Motorcycle',
    'ebike' => 'E-Bike',
  ];
  return $map[$type] ?? ucfirst($type);
}

function days_until_expiry(?string $validUntil): ?int {
  if (empty($validUntil)) return null;
  try {
    $today = new DateTime('today');
    $expiry = new DateTime($validUntil);
    if ($expiry < $today) return null;
    return (int)$today->diff($expiry)->format('%a');
  } catch (Exception $e) {
    return null;
  }
}

function can_renew_now(?string $validUntil, int $daysBeforeExpiry = 30): bool {
  $days = days_until_expiry($validUntil);
  return $days !== null && $days <= $daysBeforeExpiry;
}

$isTenant = ($_SESSION['role'] === 'tenant');
$tenant = null;
$user = null;
$hid = 0;

if ($isTenant) {
  if (empty($_SESSION['tenant_id']) || empty($_SESSION['tenant_homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $tenant_id = (int)$_SESSION['tenant_id'];
  $hid = (int)$_SESSION['tenant_homeowner_id'];

  $stmt = $conn->prepare("
    SELECT id, homeowner_id, first_name, last_name, email, status, phase,
           can_pay_dues, can_rent, can_parking, can_announcements
    FROM tenants
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $tenant_id);
  $stmt->execute();
  $tenant = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$tenant || $tenant['status'] !== 'active') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }

  $stmt = $conn->prepare("
    SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number
    FROM homeowners
    WHERE id=?
    LIMIT 1
  ");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || $user['status'] !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }

  tenant_guard('parking', $tenant);
} else {
  if (empty($_SESSION['homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $hid = (int)$_SESSION['homeowner_id'];

  $stmt = $conn->prepare("
    SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number
    FROM homeowners
    WHERE id=?
    LIMIT 1
  ");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || $user['status'] !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }
}

if ((int)$user['must_change_password'] === 1 && !$isTenant) {
  header("Location: homeowner_dashboard.php");
  exit;
}

$phase      = (string)$user['phase'];
$pageTitle  = "Parking • ".$phase;
$yearNow    = (int)date('Y');
$renewalWindowDays = 30;

if ($isTenant) {
  $fullName = trim(($tenant['first_name'] ?? '') . ' ' . ($tenant['last_name'] ?? ''));
  $initials = strtoupper(substr($tenant['first_name'] ?? 'T',0,1).substr($tenant['last_name'] ?? 'N',0,1));
} else {
  $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
  $initials = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));
}

$msg = '';
$msgType = 'success';

if (isset($_GET['paid']) && isset($_GET['active'])) {
  $msgType = 'success';
  $msg = 'Online payment recorded successfully. Your parking permit is now active.';
} elseif (isset($_GET['paid'])) {
  $msgType = 'success';
  $msg = 'Online payment recorded successfully.';
} elseif (isset($_GET['cancelled'])) {
  $msgType = 'warning';
  $msg = 'Online payment was cancelled or not completed.';
} elseif (isset($_GET['waiting_approval'])) {
  $msgType = 'warning';
  $msg = 'Your permit is not yet open for online payment. Please wait for admin approval first.';
} elseif (isset($_GET['rejected'])) {
  $msgType = 'danger';
  $msg = 'This permit request was rejected.';
} elseif (isset($_GET['revoked'])) {
  $msgType = 'danger';
  $msg = 'This permit has been revoked.';
} elseif (isset($_GET['expired'])) {
  $msgType = 'warning';
  $msg = 'This permit has already expired.';
}

/*
  Latest non-active request
*/
$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE homeowner_id=? AND phase=? AND status <> 'active'
  ORDER BY id DESC
  LIMIT 1
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$latestRequest = $stmt->get_result()->fetch_assoc();
$stmt->close();

/*
  Show all active permits
*/
$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE homeowner_id=?
    AND phase=?
    AND status='active'
    AND LOWER(COALESCE(payment_status, 'paid'))='paid'
    AND valid_until >= CURDATE()
  ORDER BY valid_until DESC, id DESC
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$activePermits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/*
  Violation count
*/
$stmt = $conn->prepare("
  SELECT COUNT(*) c
  FROM parking_violations
  WHERE homeowner_id=? AND phase=? AND status='open'
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$unpaidCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/*
  Per-permit violation counts
*/
$violationCountsByPermit = [];
if (!empty($activePermits)) {
  $permitIds = array_map(fn($p) => (int)$p['id'], $activePermits);
  $permitIds = array_values(array_filter($permitIds, fn($v) => $v > 0));

  if ($permitIds) {
    $placeholders = implode(',', array_fill(0, count($permitIds), '?'));
    $types = str_repeat('i', count($permitIds));

    $sql = "
      SELECT permit_id, COUNT(*) AS cnt
      FROM parking_violations
      WHERE homeowner_id = ?
        AND phase = ?
        AND status = 'open'
        AND permit_id IN ($placeholders)
      GROUP BY permit_id
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
      $bindTypes = "is" . $types;
      $bindValues = array_merge([$hid, $phase], $permitIds);

      $refs = [];
      $refs[] = &$bindTypes;
      foreach ($bindValues as $k => $v) {
        $refs[] = &$bindValues[$k];
      }

      call_user_func_array([$stmt, 'bind_param'], $refs);

      $stmt->execute();
      $res = $stmt->get_result();
      while ($row = $res->fetch_assoc()) {
        $violationCountsByPermit[(int)$row['permit_id']] = (int)$row['cnt'];
      }
      $stmt->close();
    }
  }
}

$activePage = basename($_SERVER['PHP_SELF'] ?? 'homeowner_parking.php');
$parkingOpen = in_array($activePage, ['homeowner_parking.php','homeowner_parking_permit.php','homeowner_parking_violations.php'], true);
$chatPages = ['homeowner_public_chat.php'];
$chatOpen = in_array($activePage, $chatPages, true);
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
  html, body { max-width: 100%; overflow-x: hidden; }
  .app-shell{ position: relative; }

  .sidebar-overlay{
    position: fixed; inset: 0; background: rgba(15, 23, 42, .45); z-index: 1040;
    opacity: 0; visibility: hidden; transition: .25s ease;
  }
  .sidebar-overlay.show{ opacity: 1; visibility: visible; }

  .sb-dd { display:flex; flex-direction:column; gap:6px; }
  .sb-dd-toggle{ display:flex; align-items:center; justify-content:space-between; gap:10px; width:100%; }
  .sb-dd-menu{ display:none; padding-left:12px; margin-top:2px; border-left:2px solid rgba(255,255,255,.08); }
  .sb-dd.open .sb-dd-menu{ display:block; }
  .sb-dd-caret{ transition: transform .15s ease; }
  .sb-dd.open .sb-dd-caret{ transform: rotate(180deg); }

  .pillx{
    display:inline-flex; gap:8px; align-items:center; padding:8px 12px; border-radius:999px;
    background:#f1f5f9; font-weight:700; flex-wrap: wrap;
  }

  .req-list li{ margin-bottom: 6px; }

  .topbar-mobile-btn{
    border: 1px solid #dbe3ea; background: #fff; color: #0f5132; border-radius: 10px;
    width: 42px; height: 42px; display: inline-flex; align-items: center; justify-content: center;
  }

  .mobile-user-strip{ display:none; }

  .parking-actions{ display:flex; gap:10px; flex-wrap:wrap; }

  .parking-alert-content{
    min-width:0; word-wrap:break-word; overflow-wrap:anywhere;
  }

  .pill-wrap{ display:flex; flex-wrap:wrap; gap:8px; }

  .permit-card{
    border: 1px solid #dbe3ea;
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 12px;
    background: #fff;
    color: #212529;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
  }

  .permit-grid{
    display:grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap:8px 16px;
  }

  .permit-grid div{ min-width:0; overflow-wrap:anywhere; }

  .permit-top-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    margin-bottom:10px;
    flex-wrap:wrap;
  }

  .permit-violation-badge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-size:12px;
    font-weight:700;
    padding:6px 10px;
    border-radius:999px;
    background:#fff7ed;
    color:#9a3412;
    border:1px solid #fed7aa;
  }

  .renew-note{
    font-size:.85rem;
    color:#6c757d;
    margin-top:8px;
  }

  @media (max-width: 991.98px){
    .sidebar{
      position: fixed !important;
      top: 0;
      left: -290px;
      width: 280px !important;
      max-width: 85vw;
      height: 100vh;
      z-index: 1050;
      transition: left .25s ease;
      overflow-y: auto;
    }
    .sidebar.show{ left: 0; }
    .main-area{ width: 100% !important; margin-left: 0 !important; }
    .container-xl{ padding-left: 14px; padding-right: 14px; }
    .desktop-user-text{ display:none !important; }
    .mobile-user-strip{ display:block; margin-bottom: 14px; }
  }

  @media (max-width: 767.98px){
    body{ font-size:14px; }
    .navbar .container-xl{ gap: 10px; }
    .navbar-brand{
      font-size: 1rem;
      max-width: 170px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .fb-card-h, .fb-card-b{ padding-left: 14px !important; padding-right: 14px !important; }
    .parking-actions{ flex-direction: column; align-items: stretch; }
    .parking-actions .btn{ width: 100%; }
    .pillx{ width: 100%; justify-content: flex-start; }
    .alert .btn{ width: 100%; margin-top: 8px; }
    .req-list{ padding-left: 18px; }
    .permit-grid{ grid-template-columns: 1fr; }
  }
    /* Desktop fixed sidebar */
@media (min-width: 992px){
  .sidebar{
    position: fixed !important;
    top: 0;
    left: 0;
    width: 280px;
    height: 100vh;
    overflow-y: auto;
    z-index: 1030;
  }

  .main-area{
    margin-left: 280px;
    width: calc(100% - 280px);
  }
}
.sidebar{
  overflow-y: auto;
  overflow-x: hidden;
}
</style>
</head>

<body>
<div class="app-shell">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <?php include 'homeowner_sidebar.php'; ?>

  <div class="main-area">
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
      <div class="container-xl">
        <div class="d-flex align-items-center gap-2">
          <button type="button" class="topbar-mobile-btn d-inline-flex d-lg-none" id="sidebarToggle" aria-label="Open menu">
            <i class="bi bi-list fs-4"></i>
          </button>
          <a class="navbar-brand fw-bold text-success m-0" href="homeowner_dashboard.php">🏘 HOA Community</a>
        </div>

        <div class="ms-auto d-flex align-items-center gap-3">
          <div class="small text-muted desktop-user-text">
            Logged in as <b><?= esc($fullName) ?></b> (<?= esc($phase) ?><?= $isTenant ? ' • Tenant' : '' ?>)
          </div>
          <a href="logout.php" class="btn btn-sm btn-outline-success">Logout</a>
        </div>
      </div>
    </nav>

    <div class="container-xl my-4">

      <div class="mobile-user-strip">
        <div class="alert alert-light border shadow-sm mb-3">
          <div class="fw-bold"><?= esc($fullName) ?></div>
          <div class="small text-muted"><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?><?= $isTenant ? ' • Tenant' : '' ?></div>
        </div>
      </div>

      <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= esc($msgType) ?>"><?= esc($msg) ?></div>
      <?php endif; ?>

      <div class="fb-card mb-4">
        <div class="fb-card-h">
          <h6>🚗 Parking Overview</h6>
          <span class="pill"><?= esc($phase) ?></span>
        </div>
        <div class="fb-card-b">
          <div class="pill-wrap">
            <span class="pillx"><i class="bi bi-calendar-check"></i> Sticker Year: <b><?= (int)$yearNow ?></b></span>
            <span class="pillx"><i class="bi bi-exclamation-circle"></i> Unpaid Violations: <b><?= (int)$unpaidCount ?></b></span>
          </div>

          <hr>

          <h6 class="mb-2">Your Permit Status</h6>

          <?php if (!empty($activePermits)): ?>
            <div class="alert alert-success parking-alert-content">
              <div class="fw-bold mb-2">Active Permits</div>

              <?php foreach ($activePermits as $permit): ?>
                <?php
                  $permitViolationCount = (int)($violationCountsByPermit[(int)$permit['id']] ?? 0);
                  $daysRemaining = days_until_expiry($permit['valid_until'] ?? null);
                  $canRenew = can_renew_now($permit['valid_until'] ?? null, $renewalWindowDays);
                ?>
                <div class="permit-card">
                  <div class="permit-top-row">
                    <div class="fw-bold">
                      Permit #<?= esc($permit['permit_no'] ?? '—') ?>
                    </div>
                    <div class="permit-violation-badge">
                      <i class="bi bi-exclamation-triangle"></i>
                      Open Violations: <?= $permitViolationCount ?>
                    </div>
                  </div>

                  <div class="permit-grid">
                    <div><b>Permit No:</b> <?= esc($permit['permit_no'] ?? '—') ?></div>
                    <div><b>Status:</b> <?= badge($permit['status'] ?? '', $permit['payment_status'] ?? '') ?></div>

                    <div><b>Plate No:</b> <?= esc($permit['plate_no'] ?? '') ?></div>
                    <div><b>Vehicle Type:</b> <?= esc(vehicle_type_label((string)($permit['vehicle_type'] ?? 'car'))) ?></div>

                    <div><b>Vehicle Brand:</b> <?= esc($permit['vehicle_make'] ?? '') ?></div>
                    <div><b>Vehicle Model:</b> <?= esc($permit['vehicle_model'] ?? '') ?></div>

                    <div><b>Vehicle Color:</b> <?= esc($permit['vehicle_color'] ?? '') ?></div>
                    <div><b>Permit Duration:</b> <?= esc(duration_label((string)($permit['permit_duration'] ?? ''))) ?></div>

                    <div><b>Payment Method:</b> <?= esc(payment_label((string)($permit['payment_method'] ?? ''))) ?></div>
                    <div><b>Payment Status:</b> <?= esc(payment_status_label($permit['payment_status'] ?? '')) ?></div>

                    <div><b>Sticker Year:</b> <?= esc($permit['sticker_year'] ?? '') ?></div>
                    <div><b>Validity Start:</b> <?= esc($permit['valid_from'] ?? ($permit['validity_start'] ?? '')) ?></div>

                    <div><b>Validity End:</b> <?= esc($permit['valid_until'] ?? ($permit['validity_end'] ?? '')) ?></div>
                    <div><b>Days Remaining:</b> <?= $daysRemaining !== null ? (int)$daysRemaining : 'Expired' ?></div>
                  </div>

                  <div class="mt-2 parking-actions">
                    <?php if ($canRenew): ?>
                      <a href="homeowner_parking_permit.php?renew_id=<?= (int)$permit['id'] ?>" class="btn btn-sm btn-success">
                        <i class="bi bi-arrow-repeat me-1"></i> Renew
                      </a>
                    <?php else: ?>
                      <button type="button" class="btn btn-sm btn-secondary" disabled>
                        <i class="bi bi-lock me-1"></i> Renewal Not Yet Available
                      </button>
                    <?php endif; ?>

                    <a href="homeowner_parking_violations.php?permit_id=<?= (int)$permit['id'] ?>" class="btn btn-sm btn-outline-danger">
                      <i class="bi bi-receipt-cutoff me-1"></i> View Violations
                    </a>

                    <a href="homeowner_contract.php?permit_id=<?= (int)$permit['id'] ?>" class="btn btn-info btn-sm">
                      <i class="bi bi-file-earmark-text me-1"></i> Contract
                    </a>
                  </div>

                  <?php if (!$canRenew && $daysRemaining !== null): ?>
                    <div class="renew-note">
                      Renewal becomes available only within <?= (int)$renewalWindowDays ?> days before expiration.
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>

          <?php elseif ($latestRequest): ?>
            <?php
              $latestStatus = strtolower(trim((string)($latestRequest['status'] ?? '')));
              $latestPayStatus = strtolower(trim((string)($latestRequest['payment_status'] ?? '')));
            ?>
            <div class="alert alert-warning parking-alert-content">
              <div class="fw-bold mb-1">Latest Permit Request</div>
              <div>Status: <?= badge($latestRequest['status'] ?? '', $latestRequest['payment_status'] ?? '') ?></div>
              <div>Payment Status: <b><?= esc(payment_status_label($latestRequest['payment_status'] ?? 'unpaid')) ?></b></div>
              <div>Plate: <b><?= esc($latestRequest['plate_no'] ?? '') ?></b></div>
              <div>Vehicle Type: <b><?= esc(vehicle_type_label((string)($latestRequest['vehicle_type'] ?? 'car'))) ?></b></div>

              <?php if ($latestStatus === 'pending' && $latestPayStatus === 'for payment'): ?>
                <div class="mt-2">Your request is approved and waiting for payment.</div>
              <?php elseif ($latestStatus === 'pending'): ?>
                <div class="mt-2">Your request is still waiting for admin approval.</div>
              <?php elseif ($latestStatus === 'rejected'): ?>
                <div class="mt-2 text-danger">Your request was rejected.</div>
              <?php elseif ($latestStatus === 'expired'): ?>
                <div class="mt-2">Your previous permit has already expired.</div>
              <?php elseif ($latestStatus === 'revoked'): ?>
                <div class="mt-2 text-danger">Your permit was revoked.</div>
              <?php endif; ?>

              <?php if (!empty($latestRequest['rejected_reason'])): ?>
                <div class="text-danger mt-1"><b>Reason:</b> <?= esc($latestRequest['rejected_reason']) ?></div>
              <?php endif; ?>

              <div class="mt-2 parking-actions">
                <?php if ($latestStatus === 'pending' && $latestPayStatus === 'for payment' && strtolower((string)($latestRequest['payment_method'] ?? '')) === 'online'): ?>
                  <a class="btn btn-sm btn-primary" href="paymongo_parking_checkout.php?permit_id=<?= (int)$latestRequest['id'] ?>">
                    <i class="bi bi-credit-card me-1"></i> Pay Online Now
                  </a>
                <?php endif; ?>

                <a class="btn btn-sm btn-outline-success" href="homeowner_parking_permit.php">Open Permit Page</a>
              </div>
            </div>
          <?php else: ?>
            <div class="alert alert-secondary parking-alert-content">
              No active permit found yet.
              <div class="mt-2 parking-actions">
                <a class="btn btn-sm btn-success" href="homeowner_parking_permit.php"><i class="bi bi-plus-circle"></i> Apply for Permit</a>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="fb-card">
        <div class="fb-card-h">
          <h6>📌 Requirements for Parking Permit</h6>
          <span class="pill">Upload on Permit page</span>
        </div>
        <div class="fb-card-b">
          <ul class="req-list mb-2">
            <li><b>Picture of Vehicle (Front)</b></li>
            <li><b>Picture of Vehicle (Back)</b></li>
            <li><b>Select Vehicle Type</b> (Car, Motorcycle, or E-Bike)</li>
          </ul>
          <div class="text-muted fw-semibold small">
            Tip: Upload clear photos or PDF/image copies to avoid delays.
          </div>

          <div class="mt-3 parking-actions">
            <a class="btn btn-success" href="homeowner_parking_permit.php">
              <i class="bi bi-card-checklist me-1"></i> Apply / Renew Permit
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

(function(){
  const tenantWrap = document.getElementById('sbTenant');
  const tenantBtn  = document.getElementById('sbTenantToggle');
  if(!tenantWrap || !tenantBtn) return;
  tenantBtn.addEventListener('click', () => tenantWrap.classList.toggle('open'));
})();

(function(){
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const toggle  = document.getElementById('sidebarToggle');

  if (!sidebar || !overlay || !toggle) return;

  function openSidebar(){
    sidebar.classList.add('show');
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar(){
    sidebar.classList.remove('show');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
  }

  toggle.addEventListener('click', openSidebar);
  overlay.addEventListener('click', closeSidebar);

  window.addEventListener('resize', function(){
    if (window.innerWidth >= 992) closeSidebar();
  });

  sidebar.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', function(){
      if (window.innerWidth < 992) closeSidebar();
    });
  });
})();
</script>
</body>
</html>