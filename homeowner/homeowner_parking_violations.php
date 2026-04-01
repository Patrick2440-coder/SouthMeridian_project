<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
  header("Location: ../index.php"); exit;
}

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function badge($status){
  $status = (string)$status;
  $cls = "secondary";
  if ($status === 'paid') $cls = "success";
  if ($status === 'open') $cls = "danger";
  if ($status === 'cleared') $cls = "warning";
  if ($status === 'void') $cls = "secondary";
  return '<span class="badge bg-'.$cls.'">'.htmlspecialchars($status).'</span>';
}

$hid = (int)$_SESSION['homeowner_id'];
$permitId = (int)($_GET['permit_id'] ?? 0);

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

$selectedPermit = null;

/*
  Get selected permit info if permit_id is provided.
  This helps show a filtered permit summary above the list.
*/
if ($permitId > 0) {
  $stmt = $conn->prepare("
    SELECT id, permit_no, plate_no, vehicle_color, permit_duration, payment_method, status, valid_from, valid_until, sticker_year
    FROM parking_permits
    WHERE id=? AND homeowner_id=? AND phase=?
    LIMIT 1
  ");
  $stmt->bind_param("iis", $permitId, $hid, $phase);
  $stmt->execute();
  $selectedPermit = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  // If invalid permit_id or not owned by current homeowner, ignore filter safely
  if (!$selectedPermit) {
    $permitId = 0;
  }
}

/*
  Fetch violations.
  If permit_id is provided, filter by permit_id.
  Assumes parking_violations has permit_id column.
*/
if ($permitId > 0) {
  $stmt = $conn->prepare("
    SELECT id, permit_id, plate_no, violation_type, location, notes, fine_amount, status, issued_at, resolved_at
    FROM parking_violations
    WHERE homeowner_id=? AND phase=? AND permit_id=?
    ORDER BY FIELD(status,'open','paid','cleared','void'), issued_at DESC, id DESC
    LIMIT 300
  ");
  $stmt->bind_param("isi", $hid, $phase, $permitId);
} else {
  $stmt = $conn->prepare("
    SELECT id, permit_id, plate_no, violation_type, location, notes, fine_amount, status, issued_at, resolved_at
    FROM parking_violations
    WHERE homeowner_id=? AND phase=?
    ORDER BY FIELD(status,'open','paid','cleared','void'), issued_at DESC, id DESC
    LIMIT 300
  ");
  $stmt->bind_param("is", $hid, $phase);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$chatPages = [
  'homeowner_public_chat.php'
];
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
html, body { max-width:100%; overflow-x:hidden; }
.app-shell{ position:relative; }

.sidebar-overlay{
  position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:1040;
  opacity:0; visibility:hidden; transition:.25s ease;
}
.sidebar-overlay.show{ opacity:1; visibility:visible; }

.sb-dd{display:flex;flex-direction:column;gap:6px;}
.sb-dd-toggle{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;}
.sb-dd-menu{display:none;padding-left:12px;margin-top:2px;border-left:2px solid rgba(255,255,255,.08);}
.sb-dd.open .sb-dd-menu{display:block;}
.sb-dd-caret{transition:transform .15s ease;}
.sb-dd.open .sb-dd-caret{transform:rotate(180deg);}

.kv{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.kv .pillx{display:inline-flex;gap:8px;align-items:center;padding:8px 12px;border-radius:999px;background:#f1f5f9;font-weight:700;flex-wrap:wrap;}

.topbar-mobile-btn{
  border:1px solid #dbe3ea; background:#fff; color:#0f5132; border-radius:10px;
  width:42px; height:42px; display:inline-flex; align-items:center; justify-content:center;
}

.mobile-user-strip{ display:none; }
.viol-card{
  border:1px solid #eef2f7; border-radius:16px; background:#fff; padding:14px;
  box-shadow:0 10px 24px rgba(15,23,42,.05);
}

.permit-filter-box{
  border:1px solid #dbeafe;
  background:#eff6ff;
  border-radius:16px;
  padding:14px;
  margin-bottom:16px;
}

.permit-filter-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:8px 16px;
}

.permit-filter-grid div{
  min-width:0;
  overflow-wrap:anywhere;
}

@media (max-width: 991.98px){
  .sidebar{
    position:fixed !important; top:0; left:-290px; width:280px !important; max-width:85vw;
    height:100vh; z-index:1050; transition:left .25s ease; overflow-y:auto;
  }
  .sidebar.show{ left:0; }
  .main-area{ width:100% !important; margin-left:0 !important; }
  .container-xl{ padding-left:14px; padding-right:14px; }
  .desktop-user-text{ display:none !important; }
  .mobile-user-strip{ display:block; margin-bottom:14px; }
}

@media (max-width: 767.98px){
  .navbar-brand{
    font-size:1rem; max-width:170px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
  }

  .permit-filter-grid{
    grid-template-columns:1fr;
  }
}
</style>
</head>

<body>
<div class="app-shell">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

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
      <a class="sb-link <?= $activePage==='homeowner_dashboard.php' ? 'active' : '' ?>" href="homeowner_dashboard.php">
        <i class="bi bi-house-door-fill"></i> <span>Dashboard</span>
      </a>

      <a class="sb-link" href="homeowner_dashboard.php#feed">
        <i class="bi bi-megaphone-fill"></i> <span>Announcement Feed</span>
      </a>
      <a class="sb-link <?= $activePage==='homeowner_public_chat.php' ? 'active' : '' ?>" href="homeowner_public_chat.php">
        <i class="bi bi-people-fill"></i> <span>Public Chat</span>
      </a>
      <a class="sb-link <?= $activePage==='homeowner_pay_dues.php' ? 'active' : '' ?>" href="homeowner_pay_dues.php">
        <i class="bi bi-cash-coin"></i> <span>Pay Monthly Dues</span>
      </a>

      <div class="sb-dd <?= $parkingOpen ? 'open' : '' ?>" id="sbParking">
        <a class="sb-link sb-dd-toggle <?= $parkingOpen ? 'active' : '' ?>" href="javascript:void(0)" id="sbParkingToggle">
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

      <a class="sb-link <?= $activePage==='homeowner_rentals.php' ? 'active' : '' ?>" href="homeowner_rentals.php">
        <i class="bi bi-calendar2-week-fill"></i> <span>Facility Rentals</span>
      </a>

      <a class="sb-link <?= $activePage==='homeowner_complaints.php' ? 'active' : '' ?>" href="homeowner_complaints.php">
        <i class="bi bi-chat-left-text-fill"></i> <span>File a Complaint</span>
      </a>
      <a class="sb-link <?= $activePage==='homeowner_voting.php' ? 'active' : '' ?>" href="homeowner_voting.php">
        <i class="bi bi-check2-square"></i> <span>Voting</span>
      </a>
      <a class="sb-link" href="logout.php">
        <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
      </a>
    </nav>
  </aside>

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
            Logged in as <b><?= esc($fullName) ?></b> (<?= esc($phase) ?>)
          </div>
          <a href="logout.php" class="btn btn-sm btn-outline-success">Logout</a>
        </div>
      </div>
    </nav>

    <div class="container-xl my-4">

      <div class="mobile-user-strip">
        <div class="alert alert-light border shadow-sm mb-3">
          <div class="fw-bold"><?= esc($fullName) ?></div>
          <div class="small text-muted"><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?></div>
        </div>
      </div>

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

          <?php if ($selectedPermit): ?>
            <div class="permit-filter-box">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <div class="fw-bold">Showing violations for selected permit</div>
                <a href="homeowner_parking_violations.php" class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-list-ul me-1"></i> Show All Violations
                </a>
              </div>

              <div class="permit-filter-grid">
                <div><b>Permit No:</b> <?= esc($selectedPermit['permit_no'] ?? '—') ?></div>
                <div><b>Status:</b> <?= esc($selectedPermit['status'] ?? '—') ?></div>
                <div><b>Plate No:</b> <?= esc($selectedPermit['plate_no'] ?? '—') ?></div>
                <div><b>Vehicle Color:</b> <?= esc($selectedPermit['vehicle_color'] ?? '—') ?></div>
                <div><b>Permit Duration:</b> <?= esc($selectedPermit['permit_duration'] ?? '—') ?></div>
                <div><b>Payment Method:</b> <?= esc($selectedPermit['payment_method'] ?? '—') ?></div>
                <div><b>Sticker Year:</b> <?= esc($selectedPermit['sticker_year'] ?? '—') ?></div>
                <div><b>Validity:</b> <?= esc(($selectedPermit['valid_from'] ?? '—').' → '.($selectedPermit['valid_until'] ?? '—')) ?></div>
              </div>
            </div>
          <?php endif; ?>

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
            <div class="d-none d-md-block table-responsive">
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

            <div class="d-md-none d-flex flex-column gap-3">
              <?php foreach($rows as $r): ?>
                <div class="viol-card">
                  <div class="d-flex justify-content-between gap-2 mb-2">
                    <div class="fw-bold"><?= esc($r['plate_no'] ?? '') ?></div>
                    <div><?= badge($r['status'] ?? 'open') ?></div>
                  </div>
                  <div class="small mb-1"><b>Violation:</b> <?= esc($r['violation_type'] ?? '') ?></div>
                  <div class="small mb-1"><b>Location:</b> <?= esc($r['location'] ?? '—') ?></div>
                  <div class="small mb-1"><b>Notes:</b> <?= esc($r['notes'] ?? '—') ?></div>
                  <div class="small mb-1"><b>Fine:</b> ₱<?= number_format((float)($r['fine_amount'] ?? 0), 2) ?></div>
                  <div class="small mb-1"><b>Issued:</b> <?= esc($r['issued_at'] ?? '') ?></div>
                  <div class="small"><b>Resolved:</b> <?= esc($r['resolved_at'] ?? '—') ?></div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="text-muted small fw-semibold mt-2">
              Note: next you can add payment button, appeal upload, and notifications here.
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