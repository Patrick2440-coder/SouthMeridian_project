<?php
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
  header("Location: ../index.php");
  exit;
}

date_default_timezone_set('Asia/Manila');

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
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || ($user['status'] ?? '') !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }

  tenant_guard('rentals', $tenant);
} else {
  if (empty($_SESSION['homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $hid = (int)$_SESSION['homeowner_id'];

  $stmt = $conn->prepare("
    SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number
    FROM homeowners
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || ($user['status'] ?? '') !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }
}

$phase    = (string)$user['phase'];
$houseLot = (string)($user['house_lot_number'] ?? '');

if ($isTenant) {
  $fullName = trim(($tenant['first_name'] ?? '') . ' ' . ($tenant['last_name'] ?? ''));
  $initials = strtoupper(substr($tenant['first_name'] ?? 'T',0,1).substr($tenant['last_name'] ?? 'N',0,1));
  $mustChange = false;
} else {
  $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
  $initials = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));
  $mustChange = ((int)($user['must_change_password'] ?? 0) === 1);
}

if ($mustChange) {
  header("Location: homeowner_dashboard.php");
  exit;
}

$pageTitle = "Facility Rentals • ".$phase;

$activePage = basename($_SERVER['PHP_SELF'] ?? 'homeowner_rentals.php');
$parkingOpen = in_array($activePage, ['homeowner_parking.php','homeowner_parking_permit.php','homeowner_parking_violations.php'], true);

if (empty($_SESSION['csrf_rent_req'])) {
  $_SESSION['csrf_rent_req'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_rent_req'];

$facility = (string)($_GET['facility'] ?? 'tables_chairs');
$allowedFacilities = ['tables_chairs','court','clubhouse'];
if (!in_array($facility, $allowedFacilities, true)) $facility = 'tables_chairs';

function facility_label($f){
  return $f === 'tables_chairs' ? 'Tables & Chairs' : ($f === 'court' ? 'Court' : 'Clubhouse');
}

$msg = (string)($_GET['msg'] ?? '');

$myReqs = [];
$stmt = $conn->prepare("
  SELECT id, facility, start_dt, end_dt, purpose, status, admin_remarks, created_at
  FROM facility_rental_requests
  WHERE homeowner_id=? AND TRIM(phase)=TRIM(?)
  ORDER BY created_at DESC
  LIMIT 30
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$myReqs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

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

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

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

.topbar-mobile-btn{
  border:1px solid #dbe3ea; background:#fff; color:#0f5132; border-radius:10px;
  width:42px; height:42px; display:inline-flex; align-items:center; justify-content:center;
}

.mobile-user-strip{ display:none; }

.cal-card{
  background:#fff;border:1px solid var(--border);
  border-radius:18px; box-shadow:0 10px 24px rgba(0,0,0,.06);
  overflow:hidden;
}
.cal-head{ padding:14px 16px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
.cal-head h6{ margin:0; font-weight:900; color:var(--hoa-green); }
.cal-body{ padding:12px; }
#rentCalendar{ background:#fff; border-radius:14px; padding:10px; border:1px solid var(--border); }

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
  .fc .fc-toolbar{
    flex-direction:column;
    align-items:flex-start !important;
    gap:10px;
  }
  .fc .fc-toolbar-chunk{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
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
          <a class="navbar-brand fw-bold text-success m-0" href="homeowner_dashboard.php">HOA Community</a>
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

      <?php if ($msg): ?>
        <div class="alert alert-info fw-semibold"><?= esc($msg) ?></div>
      <?php endif; ?>

      <div class="row g-4">
        <div class="col-lg-4">
          <div class="fb-card mb-4">
            <div class="fb-card-h">
              <h6>🏟 Facility Rentals</h6>
              <span class="pill"><?= esc($phase) ?></span>
            </div>
            <div class="fb-card-b">
              <div class="mb-2 fw-semibold text-muted">Select facility</div>
              <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-sm <?= $facility==='tables_chairs'?'btn-success':'btn-outline-success' ?>" href="?facility=tables_chairs">Tables & Chairs</a>
                <a class="btn btn-sm <?= $facility==='court'?'btn-success':'btn-outline-success' ?>" href="?facility=court">Court</a>
                <a class="btn btn-sm <?= $facility==='clubhouse'?'btn-success':'btn-outline-success' ?>" href="?facility=clubhouse">Clubhouse</a>
              </div>

              <div class="alert alert-warning mt-3 mb-0">
                <div class="fw-bold">Calendar shows APPROVED only</div>
                <div class="small fw-semibold">Pending requests won’t block until approved.</div>
              </div>

              <button class="btn btn-hoa w-100 mt-3" data-bs-toggle="modal" data-bs-target="#reqModal">
                <i class="bi bi-calendar-plus me-1"></i> Request Booking
              </button>
            </div>
          </div>

          <div class="fb-card">
            <div class="fb-card-h">
              <h6>🧾 My Requests</h6>
              <span class="pill"><?= count($myReqs) ?></span>
            </div>
            <div class="fb-card-b">
              <?php if (empty($myReqs)): ?>
                <div class="text-muted fw-semibold">No requests yet.</div>
              <?php else: ?>
                <div class="table-responsive d-none d-md-block">
                  <table class="table table-sm align-middle mb-0">
                    <thead class="text-muted">
                      <tr>
                        <th>Facility</th>
                        <th>Start</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach($myReqs as $r): ?>
                        <?php
                          $st = (string)$r['status'];
                          $badge = $st==='approved' ? 'bg-success' : ($st==='denied' ? 'bg-danger' : ($st==='cancelled'?'bg-secondary':'bg-warning text-dark'));
                        ?>
                        <tr>
                          <td class="fw-semibold"><?= esc(facility_label($r['facility'])) ?></td>
                          <td class="small fw-semibold"><?= esc(date('M d, Y h:i A', strtotime($r['start_dt']))) ?></td>
                          <td><span class="badge <?= $badge ?>"><?= esc(strtoupper($st)) ?></span></td>
                        </tr>
                        <?php if (!empty($r['admin_remarks'])): ?>
                          <tr>
                            <td colspan="3" class="small text-muted fw-semibold">
                              Admin remarks: <?= esc($r['admin_remarks']) ?>
                            </td>
                          </tr>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="d-md-none d-flex flex-column gap-3">
                  <?php foreach($myReqs as $r): ?>
                    <?php
                      $st = (string)$r['status'];
                      $badge = $st==='approved' ? 'bg-success' : ($st==='denied' ? 'bg-danger' : ($st==='cancelled'?'bg-secondary':'bg-warning text-dark'));
                    ?>
                    <div class="border rounded-4 p-3">
                      <div class="d-flex justify-content-between gap-2 mb-2">
                        <div class="fw-bold"><?= esc(facility_label($r['facility'])) ?></div>
                        <span class="badge <?= $badge ?>"><?= esc(strtoupper($st)) ?></span>
                      </div>
                      <div class="small fw-semibold text-muted"><?= esc(date('M d, Y h:i A', strtotime($r['start_dt']))) ?></div>
                      <?php if (!empty($r['admin_remarks'])): ?>
                        <div class="small text-muted mt-2">Admin remarks: <?= esc($r['admin_remarks']) ?></div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-lg-8">
          <div class="cal-card">
            <div class="cal-head">
              <h6>📅 Schedule — <?= esc(facility_label($facility)) ?></h6>
              <span class="pill">Approved = Rented</span>
            </div>
            <div class="cal-body">
              <div id="rentCalendar"></div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="reqModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content" method="POST" action="homeowner_rental_request.php" autocomplete="off">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Request Booking</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Facility</label>
            <select class="form-select" name="facility" id="facilitySelect" required>
              <option value="tables_chairs" <?= $facility==='tables_chairs'?'selected':'' ?>>Tables & Chairs</option>
              <option value="court" <?= $facility==='court'?'selected':'' ?>>Court</option>
              <option value="clubhouse" <?= $facility==='clubhouse'?'selected':'' ?>>Clubhouse</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label fw-semibold">Start</label>
            <input type="datetime-local" class="form-control" name="start_dt" required>
          </div>

          <div class="col-md-4">
            <label class="form-label fw-semibold">End</label>
            <input type="datetime-local" class="form-control" name="end_dt" required>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Purpose</label>
            <input type="text" class="form-control" name="purpose" maxlength="255" placeholder="e.g., Birthday, Meeting">
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Notes</label>
            <input type="text" class="form-control" name="notes" maxlength="255" placeholder="Optional notes">
          </div>

          <div class="col-md-4" id="guestCountWrap" style="display:none;">
            <label class="form-label fw-semibold">Guest Count (Clubhouse only)</label>
            <input type="number" class="form-control" id="guestCountInput" name="guest_count" min="1" placeholder="e.g., 30">
            <div class="form-text">Required only if facility is Clubhouse.</div>
          </div>
        </div>

        <div class="alert alert-info mt-3 mb-0">
          <b>Note:</b> Only approved bookings appear as “Rented”.
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success fw-semibold"><i class="bi bi-send me-1"></i>Submit</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="eventModalTitle">
          <i class="bi bi-calendar-event me-2"></i>Reserved
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="fw-semibold text-muted small mb-1">Start</div>
            <div class="fw-bold" id="eventModalStart">—</div>
          </div>

          <div class="col-md-6">
            <div class="fw-semibold text-muted small mb-1">End</div>
            <div class="fw-bold" id="eventModalEnd">—</div>
          </div>

          <div class="col-md-6">
            <div class="fw-semibold text-muted small mb-1">Facility</div>
            <div class="fw-bold" id="eventModalFacility">—</div>
          </div>

          <div class="col-md-6">
            <div class="fw-semibold text-muted small mb-1">Status</div>
            <div class="fw-bold">
              <span class="badge bg-success" id="eventModalStatus">APPROVED</span>
            </div>
          </div>

          <div class="col-md-6">
            <div class="fw-semibold text-muted small mb-1">Estimated Amount</div>
            <div class="fw-bold" id="eventModalAmount">—</div>
          </div>

          <div class="col-md-6">
            <div class="fw-semibold text-muted small mb-1">Purpose</div>
            <div class="fw-bold" id="eventModalPurpose">—</div>
          </div>

          <div class="col-md-6">
            <div class="fw-semibold text-muted small mb-1">Guest Count</div>
            <div class="fw-bold" id="eventModalGuests">—</div>
          </div>

          <div class="col-12">
            <div class="fw-semibold text-muted small mb-1">Notes</div>
            <div class="border rounded-3 p-2 bg-light fw-semibold" id="eventModalNotes">—</div>
          </div>
        </div>

        <div class="alert alert-warning mt-3 mb-0">
          <b>Reminder:</b> This date/time is already reserved. Please pick another schedule.
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const facilitySelect = document.getElementById('facilitySelect');
  const guestWrap = document.getElementById('guestCountWrap');
  const guestInput = document.getElementById('guestCountInput');

  function toggleGuest(){
    if (!facilitySelect || !guestWrap || !guestInput) return;
    const isClub = facilitySelect.value === 'clubhouse';
    guestWrap.style.display = isClub ? '' : 'none';
    guestInput.required = isClub;
    if (!isClub) guestInput.value = '';
  }
  if (facilitySelect) {
    facilitySelect.addEventListener('change', toggleGuest);
    toggleGuest();
  }

  const el = document.getElementById('rentCalendar');
  const modalEl = document.getElementById('eventModal');
  const eventModal = new bootstrap.Modal(modalEl);

  const tEl = document.getElementById('eventModalTitle');
  const sEl = document.getElementById('eventModalStart');
  const eEl = document.getElementById('eventModalEnd');
  const fEl = document.getElementById('eventModalFacility');
  const stEl= document.getElementById('eventModalStatus');
  const nEl = document.getElementById('eventModalNotes');
  const aEl = document.getElementById('eventModalAmount');
  const pEl = document.getElementById('eventModalPurpose');
  const gEl = document.getElementById('eventModalGuests');

  const isMobile = window.innerWidth < 768;

  const calendar = new FullCalendar.Calendar(el, {
    initialView: 'dayGridMonth',
    height: 'auto',
    headerToolbar: isMobile
      ? { left:'prev,next', center:'title', right:'today' }
      : { left:'prev,next today', center:'title', right:'dayGridMonth,timeGridWeek,timeGridDay' },

    events: 'homeowner_rental_events.php?facility=<?= esc($facility) ?>',

    eventClick: function(info){
      info.jsEvent.preventDefault();

      const ev = info.event;
      const ep = ev.extendedProps || {};

      tEl.textContent = ev.title || 'Reserved';
      sEl.textContent = ev.start ? ev.start.toLocaleString() : '—';
      eEl.textContent = ev.end ? ev.end.toLocaleString() : '—';
      fEl.textContent = ep.facilityLabel ? ep.facilityLabel : (ep.facility ? ep.facility : "<?= esc(facility_label($facility)) ?>");

      const status = (ep.status || 'approved').toString().toUpperCase();
      stEl.textContent = status;
      stEl.className = 'badge ' + (status === 'APPROVED' ? 'bg-success' : 'bg-secondary');

      nEl.textContent = ep.notes ? ep.notes : '—';
      aEl.textContent = (ep.amount != null && ep.amount !== '') ? ('₱' + Number(ep.amount).toFixed(2)) : '—';
      pEl.textContent = ep.purpose ? ep.purpose : '—';
      gEl.textContent = (ep.guest_count != null && ep.guest_count !== '') ? ep.guest_count : '—';

      eventModal.show();
    }
  });

  calendar.render();

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
});
</script>
</body>
</html>