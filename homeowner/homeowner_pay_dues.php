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
           can_pay_dues, can_rent, can_parking, can_announcements, registered_at
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
    SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number, created_at
    FROM homeowners
    WHERE id = ?
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

  tenant_guard('pay_dues', $tenant);
} else {
  if (empty($_SESSION['homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $hid = (int)$_SESSION['homeowner_id'];

  $stmt = $conn->prepare("
    SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number, created_at
    FROM homeowners
    WHERE id = ?
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

$phase = (string)$user['phase'];
$houseLot = (string)($user['house_lot_number'] ?? '');
$mustChange = !$isTenant && ((int)$user['must_change_password'] === 1);

$accountStartRaw = $isTenant
  ? (string)($tenant['registered_at'] ?? '')
  : (string)($user['created_at'] ?? '');

$accountStartTs = strtotime($accountStartRaw);
if (!$accountStartTs) {
  $accountStartTs = time();
}

$duesStartYear  = (int)date('Y', $accountStartTs);
$duesStartMonth = (int)date('n', $accountStartTs);
$duesStartLabel = date('F Y', $accountStartTs);

if ($isTenant) {
  $fullName = trim(($tenant['first_name'] ?? '') . ' ' . ($tenant['last_name'] ?? ''));
  $initials = strtoupper(substr($tenant['first_name'] ?? 'T',0,1) . substr($tenant['last_name'] ?? 'N',0,1));
} else {
  $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
  $initials = strtoupper(substr($user['first_name'] ?? 'H',0,1) . substr($user['last_name'] ?? 'O',0,1));
}

if ($mustChange) {
  header("Location: homeowner_dashboard.php");
  exit;
}

$activePage  = basename($_SERVER['PHP_SELF'] ?? 'homeowner_pay_dues.php');
$parkingOpen = in_array($activePage, [
  'homeowner_parking.php',
  'homeowner_parking_permit.php',
  'homeowner_parking_violations.php'
], true);

$selYear = (int)($_GET['year'] ?? (int)date('Y'));
$currentYear = (int)date('Y');

if ($selYear < $duesStartYear) $selYear = $duesStartYear;
if ($selYear > ($currentYear + 1)) $selYear = $currentYear;

$stmt = $conn->prepare("SELECT monthly_dues FROM finance_dues_settings WHERE phase=? LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$monthlyDues = (float)($stmt->get_result()->fetch_assoc()['monthly_dues'] ?? 0);
$stmt->close();

function paymongo_get_checkout(string $csId, string $secretKey): ?array {
  $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/" . rawurlencode($csId));
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      "Accept: application/json",
      "Authorization: Basic " . base64_encode($secretKey . ":")
    ],
    CURLOPT_TIMEOUT => 30
  ]);
  $resp = curl_exec($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false || $http < 200 || $http >= 300) return null;

  $data = json_decode($resp, true);
  return is_array($data) ? $data : null;
}

function checkout_is_paid(array $pm): bool {
  $cs = $pm['data'] ?? null;
  if (!is_array($cs)) return false;
  $attr = $cs['attributes'] ?? [];
  $payments = $attr['payments'] ?? [];
  if (is_array($payments) && !empty($payments)) return true;
  $status = (string)($attr['status'] ?? '');
  return in_array($status, ['paid','succeeded','complete','completed'], true);
}

function extract_payment(array $pm): array {
  $cs = $pm['data'] ?? [];
  $attr = $cs['attributes'] ?? [];
  $payments = $attr['payments'] ?? [];
  $pid = '';
  $amountCentavos = 0;
  if (is_array($payments) && !empty($payments[0]['id'])) {
    $pid = (string)$payments[0]['id'];
    $amountCentavos = (int)($payments[0]['attributes']['amount'] ?? 0);
  }
  return [$pid, $amountCentavos];
}

$doSync = true;
if (!empty($_SESSION['last_paymongo_sync'])) {
  if (time() - (int)$_SESSION['last_paymongo_sync'] < 20) $doSync = false;
}

if ($doSync) {
  $_SESSION['last_paymongo_sync'] = time();

  $PAYMONGO_SECRET = getenv('PAYMONGO_SECRET_KEY') ?: 'sk_test_Rxb7X283U4N6dTvWTP4oE81y';

  $stmt = $conn->prepare("
    SELECT id, checkout_session_id, pay_month, amount, phase, status
    FROM finance_paymongo_checkouts
    WHERE homeowner_id=? AND pay_year=? AND status='pending'
    ORDER BY created_at DESC
  ");
  $stmt->bind_param("ii", $hid, $selYear);
  $stmt->execute();
  $pendingRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  foreach ($pendingRows as $pr) {
    $csId = (string)$pr['checkout_session_id'];
    if ($csId === '') continue;

    $pm = paymongo_get_checkout($csId, $PAYMONGO_SECRET);
    if (!$pm) continue;
    if (!checkout_is_paid($pm)) continue;

    [$paymentId, $amountCentavos] = extract_payment($pm);

    $pMonth  = (int)$pr['pay_month'];
    $pAmount = (float)$pr['amount'];
    if ($amountCentavos > 0) $pAmount = $amountCentavos / 100.0;

    $pPhase = $phase;

    $conn->begin_transaction();
    try {
      $stmt = $conn->prepare("
        UPDATE finance_paymongo_checkouts
        SET status='paid', payment_id=?, paid_at=NOW(), phase=?
        WHERE checkout_session_id=?
      ");
      $stmt->bind_param("sss", $paymentId, $pPhase, $csId);
      $stmt->execute();
      $stmt->close();

      $ref   = $paymentId !== '' ? $paymentId : $csId;
      $notes = "PayMongo (fallback sync)";

      $stmt = $conn->prepare("
        INSERT INTO finance_payments
          (homeowner_id, phase, pay_year, pay_month, amount, status, paid_at, reference_no, notes, created_by_admin_id)
        VALUES (?,?,?,?,?,'paid',NOW(),?,?,NULL)
        ON DUPLICATE KEY UPDATE
          amount=VALUES(amount),
          status='paid',
          paid_at=NOW(),
          reference_no=VALUES(reference_no),
          notes=VALUES(notes),
          created_by_admin_id=NULL
      ");
      $stmt->bind_param("isiidss", $hid, $pPhase, $selYear, $pMonth, $pAmount, $ref, $notes);
      $stmt->execute();
      $stmt->close();

      $conn->commit();
    } catch (Throwable $e) {
      $conn->rollback();
    }
  }
}

$stmt = $conn->prepare("
  SELECT pay_month, amount, paid_at, reference_no, notes
  FROM finance_payments
  WHERE homeowner_id=? AND pay_year=? AND status='paid'
  ORDER BY pay_month ASC
");
$stmt->bind_param("ii", $hid, $selYear);
$stmt->execute();
$res = $stmt->get_result();

$paidMonths = [];
$paidRows = [];
while($r = $res->fetch_assoc()){
  $m = (int)$r['pay_month'];
  $paidMonths[$m] = true;
  $paidRows[] = $r;
}
$stmt->close();

$flashPaid   = isset($_GET['paid']) ? 1 : 0;
$flashCancel = isset($_GET['cancel']) ? 1 : 0;
$flashErr    = trim((string)($_GET['err'] ?? ''));

if (empty($_SESSION['csrf_pay_dues'])) {
  $_SESSION['csrf_pay_dues'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_pay_dues'];

$months = [
  1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
  7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'
];

$pageTitle = "Pay Monthly Dues • ".$phase;
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
  html, body {
    max-width: 100%;
    overflow-x: hidden;
  }

  .app-shell{
    position: relative;
  }

  .sidebar-overlay{
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, .45);
    z-index: 1040;
    opacity: 0;
    visibility: hidden;
    transition: .25s ease;
  }
  .sidebar-overlay.show{
    opacity: 1;
    visibility: visible;
  }

  .dues-card{
    border:1px solid #eef2f7;
    border-radius:16px;
    background:#fff;
    box-shadow:0 10px 30px rgba(16,24,40,.06);
  }

  .dues-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:14px 16px;
    border-top:1px solid #f1f5f9;
  }
  .dues-row:first-child{ border-top:none; }

  .badge-soft-success{
    background:rgba(25,135,84,.12);
    color:#198754;
    border:1px solid rgba(25,135,84,.22);
  }
  .badge-soft-danger{
    background:rgba(220,53,69,.10);
    color:#dc3545;
    border:1px solid rgba(220,53,69,.20);
  }
  .badge-soft-warning{
    background:rgba(255,193,7,.14);
    color:#a06b00;
    border:1px solid rgba(255,193,7,.28);
  }
  .small-muted{
    color:#6b7280;
    font-weight:600;
  }

  .sb-dd { display:flex; flex-direction:column; gap:6px; }
  .sb-dd-toggle{ display:flex; align-items:center; justify-content:space-between; gap:10px; width:100%; }
  .sb-dd-menu{ display:none; padding-left:12px; margin-top:2px; border-left:2px solid rgba(255,255,255,.08); }
  .sb-dd.open .sb-dd-menu{ display:block; }
  .sb-dd-caret{ transition: transform .15s ease; }
  .sb-dd.open .sb-dd-caret{ transform: rotate(180deg); }

  .pillx{
    display:inline-flex;
    gap:8px;
    align-items:center;
    padding:8px 12px;
    border-radius:999px;
    background:#f1f5f9;
    font-weight:700;
    flex-wrap: wrap;
  }

  .req-list li{ margin-bottom: 6px; }

  .topbar-mobile-btn{
    border: 1px solid #dbe3ea;
    background: #fff;
    color: #0f5132;
    border-radius: 10px;
    width: 42px;
    height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .mobile-user-strip{
    display:none;
  }

  .dues-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    flex-wrap:wrap;
  }

  .dues-year-form{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
  }

  .dues-row-left,
  .dues-row-right{
    min-width:0;
  }

  .dues-row-left{
    flex:1;
  }

  .dues-row-right{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
    justify-content:flex-end;
  }

  .ref-text{
    word-break: break-word;
    overflow-wrap: anywhere;
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

    .sidebar.show{
      left: 0;
    }

    .main-area{
      width: 100% !important;
      margin-left: 0 !important;
    }

    .container-xl{
      padding-left: 14px;
      padding-right: 14px;
    }

    .desktop-user-text{
      display:none !important;
    }

    .mobile-user-strip{
      display:block;
      margin-bottom: 14px;
    }
  }

  @media (max-width: 767.98px){
    body{
      font-size:14px;
    }

    .navbar .container-xl{
      gap: 10px;
    }

    .navbar-brand{
      font-size: 1rem;
      max-width: 170px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .dues-card{
      border-radius:14px;
    }

    .dues-row{
      flex-direction:column;
      align-items:flex-start;
      padding:12px 14px;
    }

    .dues-row-right{
      width:100%;
      justify-content:flex-start;
    }

    .dues-year-form{
      width:100%;
    }

    .dues-year-form input{
      width:100% !important;
      min-width:0;
    }

    .dues-year-form button{
      width:100%;
    }

    .dues-head{
      flex-direction:column;
      align-items:stretch;
    }

    .badge{
      white-space: normal;
      text-align:center;
    }

    .footer-wrap{
      font-size:13px;
      text-align:center;
    }
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
          <div class="small text-muted"><?= esc($phase) ?> • <?= esc($houseLot) ?><?= $isTenant ? ' • Tenant' : '' ?></div>
        </div>
      </div>

      <?php if ($flashPaid): ?>
        <div class="alert alert-success fw-semibold">
          <i class="bi bi-check-circle-fill me-1"></i>
          Payment completed! This page auto-syncs PayMongo to update your status.
        </div>
      <?php endif; ?>

      <?php if ($flashCancel): ?>
        <div class="alert alert-warning fw-semibold">
          <i class="bi bi-exclamation-triangle-fill me-1"></i>
          Payment was cancelled.
        </div>
      <?php endif; ?>

      <?php if ($flashErr !== ''): ?>
        <div class="alert alert-danger fw-semibold">
          <i class="bi bi-x-circle-fill me-1"></i>
          <?= esc($flashErr) ?>
        </div>
      <?php endif; ?>

      <div class="row g-4">
        <div class="col-lg-8">
          <div class="dues-card p-3">
            <div class="dues-head">
              <div>
                <h5 class="mb-1">Monthly Dues</h5>
                <div class="small-muted">Phase: <?= esc($phase) ?> • Blk/Lot: <?= esc($houseLot) ?></div>
              </div>

              <form method="get" class="dues-year-form">
                <label class="fw-semibold text-muted small">Year</label>
                <input type="number" name="year" class="form-control" value="<?= (int)$selYear ?>" style="width:120px" min="<?= (int)$duesStartYear ?>" max="<?= (int)date('Y')+1 ?>">
                <button class="btn btn-outline-success fw-semibold">Go</button>
              </form>
            </div>

            <hr>

            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
              <div class="fw-semibold">
                Current Monthly Dues:
                <span class="text-success">₱ <?= number_format($monthlyDues, 2) ?></span>
              </div>
              <div class="text-muted fw-semibold small">
                Dues start from your account creation month: <?= esc($duesStartLabel) ?>.
              </div>
            </div>

            <div class="mt-3">
              <?php foreach($months as $m => $label): ?>
                <?php
                  $isBeforeStart = (
                    $selYear < $duesStartYear ||
                    ($selYear === $duesStartYear && $m < $duesStartMonth)
                  );

                  $isPaid = !$isBeforeStart && !empty($paidMonths[$m]);
                ?>
                <div class="dues-row">
                  <div class="dues-row-left">
                    <div class="fw-bold"><?= esc($label) ?> <?= (int)$selYear ?></div>
                    <div class="small-muted">
                      <?php if ($isBeforeStart): ?>
                        Not applicable — account started in <?= esc($duesStartLabel) ?>
                      <?php else: ?>
                        <?= $isPaid ? 'Payment recorded' : 'Not paid yet' ?>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="dues-row-right">
                    <?php if ($isBeforeStart): ?>
                      <span class="badge rounded-pill badge-soft-warning px-3 py-2 fw-semibold">
                        <i class="bi bi-dash-circle me-1"></i> N/A
                      </span>

                    <?php elseif ($isPaid): ?>
                      <span class="badge rounded-pill badge-soft-success px-3 py-2 fw-semibold">
                        <i class="bi bi-check2-circle me-1"></i> PAID
                      </span>

                    <?php else: ?>
                      <span class="badge rounded-pill badge-soft-danger px-3 py-2 fw-semibold">
                        <i class="bi bi-x-circle me-1"></i> UNPAID
                      </span>

                      <?php if ($monthlyDues > 0): ?>
                        <form method="post" action="paymongo_create_checkout.php" class="m-0">
                          <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                          <input type="hidden" name="year" value="<?= (int)$selYear ?>">
                          <input type="hidden" name="month" value="<?= (int)$m ?>">
                          <button class="btn btn-sm btn-success fw-semibold">
                            <i class="bi bi-credit-card-2-front me-1"></i> Pay Now
                          </button>
                        </form>
                      <?php else: ?>
                        <span class="text-muted fw-semibold small">Dues not set</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

          </div>
        </div>

        <div class="col-lg-4">
          <div class="dues-card p-3 mb-4">
            <h6 class="mb-2">Your Paid History (<?= (int)$selYear ?>)</h6>
            <?php if (!$paidRows): ?>
              <div class="text-muted fw-semibold">No paid records yet.</div>
            <?php else: ?>
              <div class="d-flex flex-column gap-2">
                <?php foreach($paidRows as $p): ?>
                  <?php
                    $mm = (int)$p['pay_month'];
                    $paidAt = $p['paid_at'] ? date('M d, Y h:i A', strtotime($p['paid_at'])) : '';
                  ?>
                  <div class="p-2 rounded-3" style="border:1px solid #eef2f7;">
                    <div class="fw-bold"><?= esc($months[$mm] ?? ('Month '.$mm)) ?></div>
                    <div class="small-muted">₱ <?= number_format((float)$p['amount'],2) ?></div>
                    <div class="text-muted small fw-semibold"><?= esc($paidAt) ?></div>
                    <?php if (!empty($p['reference_no'])): ?>
                      <div class="text-muted small ref-text">Ref: <?= esc($p['reference_no']) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="dues-card p-3">
            <h6 class="mb-2">How PayMongo works</h6>
            <div class="text-muted fw-semibold">
              When you click <b>Pay Now</b>, you’ll be redirected to PayMongo Checkout (GCash).
            </div>
          </div>
        </div>
      </div>

      <div class="footer-wrap pd-20 mb-20 card-box mt-4">
        © Copyright South Meridian Homes All Rights Reserved
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
    if (window.innerWidth >= 992) {
      closeSidebar();
    }
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