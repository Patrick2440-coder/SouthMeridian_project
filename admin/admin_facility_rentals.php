<?php
session_start();

require_once 'admin_access.php';
requireAccess('community');
/* =========================
   1) AUTH GUARD
   ========================= */
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)) {
  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
  exit;
}

/* Superadmin not allowed here */
if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
  echo "<script>alert('Superadmin cannot access President Dashboard.'); window.location='index.php';</script>";
  exit;
}

/* =========================
   2) DB
   ========================= */
$db_host = "localhost";
$db_user = "u972459197_patrick";
$db_pass = "Idle2440";
$db_name = "u972459197_south_meridian";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* =========================
   3) ADMIN INFO
   ========================= */
$adminId   = (int)($_SESSION['admin_id'] ?? 0);

$stmt = $conn->prepare("SELECT email, full_name, phase, role FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$me) {
  session_destroy();
  echo "<script>alert('Session error. Please login again.'); window.location='index.php';</script>";
  exit;
}

$adminEmail = (string)($me['email'] ?? '');
$adminName  = trim((string)($me['full_name'] ?? ''));
$myPhase    = (string)($me['phase'] ?? 'Phase 1');

$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';

/* =========================
   PRICING (dynamic)
   ========================= */
function ensure_pricing(mysqli $conn, string $phase): array {
  $stmt = $conn->prepare("SELECT * FROM facility_rental_pricing WHERE phase=? LIMIT 1");
  $stmt->bind_param("s", $phase);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) {
    $stmt = $conn->prepare("
      INSERT INTO facility_rental_pricing
        (phase, court_rate_per_hour, court_rate_per_30min, tables_chairs_flat, clubhouse_flat, clubhouse_max_person)
      VALUES (?, 100, 50, 2500, 2500, 50)
    ");
    $stmt->bind_param("s", $phase);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM facility_rental_pricing WHERE phase=? LIMIT 1");
    $stmt->bind_param("s", $phase);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
  }

  return $row ?: [
    'court_rate_per_hour' => 100,
    'court_rate_per_30min' => 50,
    'tables_chairs_flat' => 2500,
    'clubhouse_flat' => 2500,
    'clubhouse_max_person' => 50,
  ];
}

$pricing = ensure_pricing($conn, $phase);

/* =========================
   CSRF
   ========================= */
if (empty($_SESSION['csrf_facility_rent'])) {
  $_SESSION['csrf_facility_rent'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_facility_rent'];

/* =========================
   Filters
   ========================= */
$status = (string)($_GET['status'] ?? 'pending');
$allowedStatus = ['pending','approved','denied','cancelled'];
if (!in_array($status, $allowedStatus, true)) $status = 'pending';

$facility = (string)($_GET['facility'] ?? 'all');
$allowedFacility = ['all','tables_chairs','court','clubhouse'];
if (!in_array($facility, $allowedFacility, true)) $facility = 'all';

function facility_label($f){
  return $f === 'tables_chairs' ? 'Tables & Chairs' : ($f === 'court' ? 'Court' : 'Clubhouse');
}

$msg = (string)($_GET['msg'] ?? '');

/* =========================
   Load requests
   ========================= */
if ($facility === 'all') {
  $stmt = $conn->prepare("
    SELECT r.*,
           CONCAT(h.first_name,' ',h.last_name) AS homeowner_name,
           h.house_lot_number
    FROM facility_rental_requests r
    JOIN homeowners h ON h.id = r.homeowner_id
    WHERE r.phase=? AND r.status=?
    ORDER BY r.created_at DESC
    LIMIT 400
  ");
  $stmt->bind_param("ss", $phase, $status);
} else {
  $stmt = $conn->prepare("
    SELECT r.*,
           CONCAT(h.first_name,' ',h.last_name) AS homeowner_name,
           h.house_lot_number
    FROM facility_rental_requests r
    JOIN homeowners h ON h.id = r.homeowner_id
    WHERE r.phase=? AND r.status=? AND r.facility=?
    ORDER BY r.created_at DESC
    LIMIT 400
  ");
  $stmt->bind_param("sss", $phase, $status, $facility);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$view = $_GET['view'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>HOA-ADMIN • Facility Rentals</title>

  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

  <style>
    .badge-soft { padding:.35rem .6rem; border-radius:999px; font-weight:800; font-size:12px; }
    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-danger  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .badge-soft-muted   { background:#f1f5f9; border:1px solid #e2e8f0; color:#475569; }

    .modalx {
      display:none; position:fixed; inset:0;
      background:rgba(0,0,0,.45);
      align-items:center; justify-content:center;
      z-index:9999; padding:16px;
    }
    .modalx .box {
      width:min(720px, 96vw);
      max-height:92vh;
      background:#fff;
      border-radius:16px;
      overflow:auto;
      box-shadow:0 20px 60px rgba(0,0,0,.25);
    }
    .modalx .boxhead {
      padding:14px 16px;
      border-bottom:1px solid #e5e7eb;
      display:flex; align-items:center; justify-content:space-between; gap:12px;
    }
    .modalx .closebtn { border:none; background:transparent; font-size:22px; cursor:pointer; }
    .pill {
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      background:#f1f5f9; border:1px solid #e2e8f0;
      font-weight:900; font-size:12px;
    }
    /* ACCESS TOAST */
.access-toast {
  position: fixed;
  top: 20px;
  right: 20px;
  background: #ef4444;
  color: #fff;
  padding: 12px 18px;
  border-radius: 8px;
  font-weight: 600;
  box-shadow: 0 6px 18px rgba(0,0,0,0.2);
  z-index: 99999;
  opacity: 0;
  transform: translateY(-10px);
  transition: all .3s ease;
}

.access-toast.show {
  opacity: 1;
  transform: translateY(0);
}
  </style>
</head>

<body>
  <!-- HEADER -->
  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
      <div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>
    </div>

    <div class="header-right">
      <div class="user-info-dropdown">
        <div class="dropdown">
          <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
            <span class="user-icon"><img src="vendors/images/photo1.jpg" alt=""></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="logout.php"><i class="dw dw-logout"></i> Log Out</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- SIDEBAR -->
<?php include 'sidebar.php'; ?>

  <div class="mobile-menu-overlay"></div>

  <!-- MAIN -->
  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header mb-20">
        <div class="row">
          <div class="col-md-12 col-sm-12">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
              <div class="title"><h4>Facility Rentals</h4></div>
              <button class="btn btn-outline-success" type="button" id="openPricing">
                <i class="dw dw-settings2"></i> Edit Rental Prices
              </button>
            </div>
            <div class="text-secondary">
              Phase: <b><?= esc($phase) ?></b>
              <span class="pill">Status: <?= esc(strtoupper($status)) ?></span>
              <span class="pill">Facility: <?= esc($facility==='all'?'ALL':strtoupper(str_replace('_',' ',$facility))) ?></span>
            </div>
          </div>
        </div>
      </div>

      <?php if ($msg): ?>
        <div class="alert alert-info"><?= esc($msg) ?></div>
      <?php endif; ?>

      <div class="card-box pd-20 mb-20">
        <form class="row" method="GET" style="gap:12px; align-items:end;">
          <div class="col-md-3">
            <label class="font-weight-bold">Status</label>
            <select class="form-control" name="status">
              <?php foreach($allowedStatus as $st): ?>
                <option value="<?= esc($st) ?>" <?= $status===$st?'selected':'' ?>><?= esc(strtoupper($st)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="font-weight-bold">Facility</label>
            <select class="form-control" name="facility">
              <option value="all" <?= $facility==='all'?'selected':'' ?>>ALL</option>
              <option value="tables_chairs" <?= $facility==='tables_chairs'?'selected':'' ?>>Tables & Chairs</option>
              <option value="court" <?= $facility==='court'?'selected':'' ?>>Court</option>
              <option value="clubhouse" <?= $facility==='clubhouse'?'selected':'' ?>>Clubhouse</option>
            </select>
          </div>
          <div class="col-md-3">
            <button class="btn btn-success">Filter</button>
            <a class="btn btn-outline-success" href="admin_facility_calendar.php">Open Calendar</a>
          </div>
        </form>
      </div>

      <div class="card-box pd-20 mb-30">
        <div class="table-responsive">
          <table id="rentTable" class="table table-striped table-hover">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Homeowner</th>
                <th>Facility</th>
                <th>Schedule</th>
                <th>Purpose</th>
                <th>Status</th>
                <th style="width:210px;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="text-center text-secondary">No records.</td></tr>
              <?php else: ?>
                <?php foreach($rows as $r): ?>
                  <?php
                    $rid = (int)$r['id'];
                    $st = (string)$r['status'];
                    $badge = $st==='approved' ? 'badge-soft-success' : ($st==='denied' ? 'badge-soft-danger' : ($st==='cancelled'?'badge-soft-muted':'badge-soft-warning'));
                  ?>
                  <tr>
                    <td>#<?= $rid ?></td>
                    <td>
                      <div style="font-weight:800;"><?= esc($r['homeowner_name'] ?? '') ?></div>
                      <div class="text-muted" style="font-weight:700;font-size:12px;"><?= esc($r['house_lot_number'] ?? '') ?></div>
                    </td>
                    <td style="font-weight:800;"><?= esc(facility_label($r['facility'])) ?></td>
                    <td style="font-weight:800;">
                      <?= esc(date('M d, Y h:i A', strtotime($r['start_dt']))) ?><br>
                      <span class="text-muted" style="font-weight:700;font-size:12px;">to <?= esc(date('M d, Y h:i A', strtotime($r['end_dt']))) ?></span>
                    </td>
                    <td style="font-weight:800;"><?= esc($r['purpose'] ?? '') ?></td>
                    <td><span class="badge-soft <?= esc($badge) ?>"><?= esc(strtoupper($st)) ?></span></td>
                    <td>
                      <?php if ($st === 'pending'): ?>
                        <button class="btn btn-sm btn-success actBtn"
                          data-id="<?= $rid ?>" data-status="approved" data-title="Approve Request #<?= $rid ?>">
                          Approve
                        </button>
                        <button class="btn btn-sm btn-outline-danger actBtn"
                          data-id="<?= $rid ?>" data-status="denied" data-title="Deny Request #<?= $rid ?>">
                          Deny
                        </button>
                      <?php else: ?>
                        <span class="text-muted" style="font-weight:800;">No action</span>
                        <?php if (!empty($r['admin_remarks'])): ?>
                          <div class="text-muted" style="font-weight:700;font-size:12px;">Remarks: <?= esc($r['admin_remarks']) ?></div>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="footer-wrap pd-20 mb-20 card-box">
        © Copyright South Meridian Homes All Rights Reserved
      </div>
    </div>
  </div>

  <!-- ACTION MODAL -->
  <div class="modalx" id="actModal">
    <div class="box">
      <div class="boxhead">
        <div id="actModalTitle" style="font-weight:900;">Action</div>
        <button class="closebtn" type="button" id="closeActModal">&times;</button>
      </div>
      <div class="p-3">
        <!-- ✅ FIX: correct filename + keep filters -->
        <form method="POST" action="admin_facility_rentals_actions.php">
          <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
          <input type="hidden" name="id" id="actId" value="">
          <input type="hidden" name="new_status" id="actStatus" value="">
          <input type="hidden" name="return_status" value="<?= esc($status) ?>">
          <input type="hidden" name="return_facility" value="<?= esc($facility) ?>">

          <div class="form-group">
            <label style="font-weight:900;">Admin remarks (optional)</label>
            <input type="text" name="remarks" class="form-control" maxlength="255" placeholder="e.g., Approved. Claim key at office.">
          </div>

          <div class="alert alert-warning mb-0">
            <b>Overlap protection:</b> approving will fail if it overlaps an already approved booking.
          </div>

          <div class="mt-3 d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" id="cancelAct">Cancel</button>
            <button class="btn btn-success" id="confirmAct">Confirm</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ✅ PRICING MODAL -->
  <div class="modalx" id="pricingModal">
    <div class="box">
      <div class="boxhead">
        <div style="font-weight:900;">Rental Pricing Settings • <?= esc($phase) ?></div>
        <button class="closebtn" type="button" id="closePricing">&times;</button>
      </div>

      <div class="p-3">
        <form method="POST" action="admin_facility_pricing_save.php" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
          <input type="hidden" name="return_status" value="<?= esc($status) ?>">
          <input type="hidden" name="return_facility" value="<?= esc($facility) ?>">

          <div class="alert alert-info mb-3">
            <b>Court pricing rule:</b> every <b>1 hour</b> = ₱<?= esc($pricing['court_rate_per_hour']) ?>, every <b>30 minutes</b> = ₱<?= esc($pricing['court_rate_per_30min']) ?>.
            <div class="small mt-1">Example: 1hr 30mins = ₱(1×hour + 1×30min).</div>
          </div>

          <div class="row" style="gap:12px;">
            <div class="col-md-6">
              <label style="font-weight:900;">Court rate per 1 hour (₱)</label>
              <input type="number" min="0" class="form-control" name="court_rate_per_hour"
                     value="<?= esc($pricing['court_rate_per_hour']) ?>" required>
            </div>

            <div class="col-md-6">
              <label style="font-weight:900;">Court rate per 30 mins (₱)</label>
              <input type="number" min="0" class="form-control" name="court_rate_per_30min"
                     value="<?= esc($pricing['court_rate_per_30min']) ?>" required>
            </div>

            <div class="col-md-6">
              <label style="font-weight:900;">Tables & Chairs flat price (₱)</label>
              <input type="number" min="0" class="form-control" name="tables_chairs_flat"
                     value="<?= esc($pricing['tables_chairs_flat']) ?>" required>
            </div>

            <div class="col-md-6">
              <label style="font-weight:900;">Clubhouse flat price (₱)</label>
              <input type="number" min="0" class="form-control" name="clubhouse_flat"
                     value="<?= esc($pricing['clubhouse_flat']) ?>" required>
            </div>

            <div class="col-md-6">
              <label style="font-weight:900;">Clubhouse max persons</label>
              <input type="number" min="1" max="500" class="form-control" name="clubhouse_max_person"
                     value="<?= esc($pricing['clubhouse_max_person']) ?>" required>
              <div class="text-muted" style="font-weight:700;font-size:12px;">Your rule: max persons (editable)</div>
            </div>
          </div>

          <div class="mt-3 d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" id="cancelPricing">Cancel</button>
            <button class="btn btn-success">Save Pricing</button>
          </div>

        </form>
      </div>
    </div>
  </div>

  <script src="vendors/scripts/core.js"></script>
  <script src="vendors/scripts/script.min.js"></script>
  <script src="vendors/scripts/process.js"></script>
  <script src="vendors/scripts/layout-settings.js"></script>

  <script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
  <script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
  <script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
  <script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

  <script>
    $(function(){
      $('#rentTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [],
        columnDefs: [{ orderable: false, targets: 6 }]
      });
    });

    const actModal = document.getElementById('actModal');
    const actTitle = document.getElementById('actModalTitle');
    const actId    = document.getElementById('actId');
    const actStatus= document.getElementById('actStatus');
    const confirmBtn = document.getElementById('confirmAct');

    function openModal(){ actModal.style.display='flex'; }
    function closeModal(){ actModal.style.display='none'; }

    document.getElementById('closeActModal').addEventListener('click', closeModal);
    document.getElementById('cancelAct').addEventListener('click', closeModal);
    actModal.addEventListener('click', (e)=>{ if(e.target===actModal) closeModal(); });

    document.querySelectorAll('.actBtn').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        actTitle.textContent = btn.dataset.title || 'Action';
        actId.value = btn.dataset.id || '';
        actStatus.value = btn.dataset.status || '';
        confirmBtn.className = 'btn ' + (actStatus.value==='approved' ? 'btn-success' : 'btn-danger');
        openModal();
      });
    });

    const pricingModal = document.getElementById('pricingModal');
    function openPricing(){ pricingModal.style.display = 'flex'; }
    function closePricing(){ pricingModal.style.display = 'none'; }

    document.getElementById('openPricing')?.addEventListener('click', openPricing);
    document.getElementById('closePricing')?.addEventListener('click', closePricing);
    document.getElementById('cancelPricing')?.addEventListener('click', closePricing);
    pricingModal?.addEventListener('click', (e)=>{ if(e.target === pricingModal) closePricing(); });
  </script>
<div id="accessToast" class="access-toast">
  🚫 You do not have access to that part.
</div>
<script>
window.userPermissions = <?= json_encode($permissions) ?>;

document.addEventListener('DOMContentLoaded', function () {

  const toast = document.getElementById('accessToast');

  function showAccessToast() {
    toast.classList.add('show');

    setTimeout(() => {
      toast.classList.remove('show');
    }, 2500);
  }

  document.querySelectorAll('.menu-access-link').forEach(function(link){

    link.addEventListener('click', function(e){

      const moduleKey = this.dataset.module || '';
      const allowed = !!window.userPermissions[moduleKey];

      if(!allowed){
        e.preventDefault();
        showAccessToast();
      }

    });

  });

});
</script>
</body>
</html>