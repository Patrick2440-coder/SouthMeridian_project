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

$pageTitle = "My Parking Violations • ".$phase;

$activePage = basename($_SERVER['PHP_SELF']);
$parkingOpen = in_array($activePage, ['homeowner_parking.php','homeowner_parking_permit.php','homeowner_parking_violations.php'], true);

// Load violations (DB statuses: open, paid, cleared, void)
$stmt = $conn->prepare("
  SELECT id, plate_no, violation_type, location, notes, fine_amount, status, issued_at, resolved_at
  FROM parking_violations
  WHERE homeowner_id=? AND phase=?
  ORDER BY FIELD(status,'open','paid','cleared','void'), issued_at DESC, id DESC
  LIMIT 300
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function badge($status){
  $status = (string)$status;
  $cls = "secondary";
  if ($status === 'paid') $cls = "success";
  if ($status === 'open') $cls = "danger";
  if ($status === 'cleared') $cls = "warning";
  if ($status === 'void') $cls = "secondary";
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

.kv{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.kv .pillx{display:inline-flex;gap:8px;align-items:center;padding:8px 12px;border-radius:999px;background:#f1f5f9;font-weight:700;}
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
        <a class="sb-link sb-dd-toggle <?= $activePage==='homeowner_parking_violations.php' ? 'active' : '' ?>" href="javascript:void(0)" id="sbParkingToggle">
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

      <div class="fb-card">
        <div class="fb-card-h">
          <h6>🧾 My Parking Violations</h6>
          <span class="pill"><?= esc($phase) ?></span>
        </div>

        <div class="fb-card-b">

          <?php
            $openCount = 0; $paidCount = 0; $clearedCount = 0; $voidCount = 0;
            foreach($rows as $r){
              if (($r['status'] ?? '') === 'open') $openCount++;
              else if (($r['status'] ?? '') === 'paid') $paidCount++;
              else if (($r['status'] ?? '') === 'cleared') $clearedCount++;
              else if (($r['status'] ?? '') === 'void') $voidCount++;
            }
          ?>

          <div class="kv mb-3">
            <span class="pillx"><i class="bi bi-exclamation-circle"></i> Open: <b><?= (int)$openCount ?></b></span>
            <span class="pillx"><i class="bi bi-check2-circle"></i> Paid: <b><?= (int)$paidCount ?></b></span>
            <span class="pillx"><i class="bi bi-shield-check"></i> Cleared: <b><?= (int)$clearedCount ?></b></span>
            <span class="pillx"><i class="bi bi-x-circle"></i> Void: <b><?= (int)$voidCount ?></b></span>
          </div>

          <?php if (!$rows): ?>
            <div class="alert alert-success mb-0">
              No violations found 🎉
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th>#</th>
                    <th>Plate</th>
                    <th>Violation</th>
                    <th>Location</th>
                    <th>Notes</th>
                    <th>Fine</th>
                    <th>Status</th>
                    <th>Issued</th>
                    <th>Resolved</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($rows as $r): ?>
                    <tr>
                      <td><?= (int)$r['id'] ?></td>
                      <td><b><?= esc($r['plate_no'] ?? '') ?></b></td>
                      <td><?= esc($r['violation_type'] ?? '') ?></td>
                      <td><?= esc($r['location'] ?? '—') ?></td>
                      <td><?= esc($r['notes'] ?? '—') ?></td>
                      <td>₱<?= number_format((float)($r['fine_amount'] ?? 0), 2) ?></td>
                      <td><?= badge($r['status'] ?? 'open') ?></td>
                      <td><?= esc($r['issued_at'] ?? '') ?></td>
                      <td><?= esc($r['resolved_at'] ?? '—') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="text-muted small fw-semibold mt-2">
              Note: If you want, next we can add: payment button (PayMongo), upload appeal letter, and email/SMS notifications.
            </div>
          <?php endif; ?>

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
