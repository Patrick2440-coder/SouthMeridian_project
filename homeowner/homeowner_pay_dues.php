<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
  header("Location: ../index.php");
  exit;
}

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$hid = (int)$_SESSION['homeowner_id'];

$stmt = $conn->prepare("SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number FROM homeowners WHERE id=? LIMIT 1");
$stmt->bind_param("i", $hid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user['status'] !== 'approved') {
  session_destroy();
  header("Location: ../index.php");
  exit;
}

$phase      = (string)$user['phase'];
$fullName   = trim($user['first_name'].' '.$user['last_name']);
$initials   = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));
$houseLot   = (string)($user['house_lot_number'] ?? '');
$mustChange = ((int)$user['must_change_password'] === 1);

if ($mustChange) {
  header("Location: homeowner_dashboard.php");
  exit;
}

// Year selection
$selYear = (int)($_GET['year'] ?? (int)date('Y'));
if ($selYear < 2000 || $selYear > ((int)date('Y') + 1)) $selYear = (int)date('Y');

// Get dues for this phase
$stmt = $conn->prepare("SELECT monthly_dues FROM finance_dues_settings WHERE phase=? LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$monthlyDues = (float)($stmt->get_result()->fetch_assoc()['monthly_dues'] ?? 0);
$stmt->close();

/**
 * ============================
 * ✅ FALLBACK SYNC (NO WEBHOOK NEEDED)
 * ============================
 * Checks PayMongo checkout_session status for pending rows.
 * If paid -> mark paid + insert finance_payments.
 */
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
  // PayMongo checkout session often has payments list when paid
  $payments = $attr['payments'] ?? [];
  if (is_array($payments) && !empty($payments)) return true;
  // Some versions: status field
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

// Only sync max once per 20 seconds per session to avoid hammering PayMongo
$doSync = true;
if (!empty($_SESSION['last_paymongo_sync'])) {
  if (time() - (int)$_SESSION['last_paymongo_sync'] < 20) $doSync = false;
}

if ($doSync) {
  $_SESSION['last_paymongo_sync'] = time();

  $PAYMONGO_SECRET = getenv('PAYMONGO_PUBLIC_KEY') ?: 'pk_test_XPpTJrGNHoL8HBvx7eAWMH3C';

  // Get all pending sessions for this homeowner + year
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

    $pMonth = (int)$pr['pay_month'];
    $pAmount = (float)$pr['amount'];
    if ($amountCentavos > 0) $pAmount = $amountCentavos / 100.0;

    // Use correct phase: from homeowners (safe), because old row might be ''.
    $pPhase = $phase;

    $conn->begin_transaction();
    try {
      // Mark checkout as paid + store payment id
      $stmt = $conn->prepare("
        UPDATE finance_paymongo_checkouts
        SET status='paid', payment_id=?, paid_at=NOW(), phase=?
        WHERE checkout_session_id=?
      ");
      $stmt->bind_param("sss", $paymentId, $pPhase, $csId);
      $stmt->execute();
      $stmt->close();

      // Insert or update finance_payments (unique homeowner_id+year+month)
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
      // swallow to avoid breaking the page
    }
  }
}

// Paid months (finance_payments)
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

// Any pending PayMongo checkouts (display)
$stmt = $conn->prepare("
  SELECT pay_month, checkout_session_id, status, created_at
  FROM finance_paymongo_checkouts
  WHERE homeowner_id=? AND pay_year=? AND status='pending'
");
$stmt->bind_param("ii", $hid, $selYear);
$stmt->execute();
$res = $stmt->get_result();
$pendingByMonth = [];
while($r = $res->fetch_assoc()){
  $pendingByMonth[(int)$r['pay_month']] = $r;
}
$stmt->close();

$flashPaid   = isset($_GET['paid']) ? 1 : 0;
$flashCancel = isset($_GET['cancel']) ? 1 : 0;
$flashErr    = trim((string)($_GET['err'] ?? ''));

// CSRF token
if (empty($_SESSION['csrf_pay_dues'])) {
  $_SESSION['csrf_pay_dues'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_pay_dues'];

$months = [
  1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
  7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'
];

$pageTitle = "Pay Monthly Dues • ".$phase;
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
.dues-card{ border:1px solid #eef2f7; border-radius:16px; background:#fff; box-shadow:0 10px 30px rgba(16,24,40,.06); }
.dues-row{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; border-top:1px solid #f1f5f9; }
.dues-row:first-child{ border-top:none; }
.badge-soft-success{ background:rgba(25,135,84,.12); color:#198754; border:1px solid rgba(25,135,84,.22); }
.badge-soft-danger{ background:rgba(220,53,69,.10); color:#dc3545; border:1px solid rgba(220,53,69,.20); }
.badge-soft-warning{ background:rgba(255,193,7,.14); color:#a06b00; border:1px solid rgba(255,193,7,.28); }
.small-muted{ color:#6b7280; font-weight:600; }
</style>
</head>

<body>
<div class="app-shell">

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
        <p class="sb-meta"><?= esc($phase) ?> • <?= esc($houseLot) ?></p>
      </div>
    </div>

    <nav class="sb-nav">
      <a class="sb-link" href="homeowner_dashboard.php">
        <i class="bi bi-house-door-fill"></i> <span>Dashboard</span>
      </a>

      <a class="sb-link" href="homeowner_dashboard.php#feed">
        <i class="bi bi-megaphone-fill"></i> <span>Announcement Feed</span>
      </a>

      <a class="sb-link active" href="homeowner_pay_dues.php">
        <i class="bi bi-cash-coin"></i> <span>Pay Monthly Dues</span>
      </a>

      <a class="sb-link" href="logout.php">
        <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
      </a>
    </nav>
  </aside>

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
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
              <div>
                <h5 class="mb-1">Monthly Dues</h5>
                <div class="small-muted">Phase: <?= esc($phase) ?> • Blk/Lot: <?= esc($houseLot) ?></div>
              </div>

              <form method="get" class="d-flex gap-2 align-items-center">
                <label class="fw-semibold text-muted small">Year</label>
                <input type="number" name="year" class="form-control" value="<?= (int)$selYear ?>" style="width:120px" min="2000" max="<?= (int)date('Y')+1 ?>">
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
                Tip: Pay month-by-month to avoid duplicates.
              </div>
            </div>

            <div class="mt-3">
              <?php foreach($months as $m => $label): ?>
                <?php
                  $isPaid = !empty($paidMonths[$m]);
                  $pending = $pendingByMonth[$m] ?? null;
                ?>
                <div class="dues-row">
                  <div>
                    <div class="fw-bold"><?= esc($label) ?> <?= (int)$selYear ?></div>
                    <div class="small-muted">
                      <?= $isPaid ? 'Payment recorded' : ($pending ? 'Pending PayMongo checkout' : 'Not paid yet') ?>
                    </div>
                  </div>

                  <div class="d-flex align-items-center gap-2">
                    <?php if ($isPaid): ?>
                      <span class="badge rounded-pill badge-soft-success px-3 py-2 fw-semibold">
                        <i class="bi bi-check2-circle me-1"></i> PAID
                      </span>
                    <?php elseif ($pending): ?>
                      <span class="badge rounded-pill badge-soft-warning px-3 py-2 fw-semibold">
                        <i class="bi bi-hourglass-split me-1"></i> PENDING
                      </span>
                      <button class="btn btn-sm btn-outline-success fw-semibold" onclick="location.reload()">
                        Refresh
                      </button>
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
                      <div class="text-muted small">Ref: <?= esc($p['reference_no']) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="dues-card p-3">
            <h6 class="mb-2">How PayMongo works</h6>
            <div class="text-muted fw-semibold">
              When you click <b>Pay Now</b>, you’ll be redirected to PayMongo Checkout (GCash/Card/etc).
              This page can auto-sync PayMongo payment results even if webhook is not reachable.
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
</body>
</html>
