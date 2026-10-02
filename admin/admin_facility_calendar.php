<?php
session_start();
require_once '../config/database.php';
require_once 'admin_access.php';
requireAccess('community');
/* =========================
   AUTH / CURRENT ADMIN
   ========================= */
/*
 * admin_access.php + requireAccess('community') remains the primary
 * authentication and module-permission guard. Validate the current admin
 * from the database instead of depending on a second admin_role session check.
 */
$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) {
    header('Location: ../index.php');
    exit;
}
$stmt = $conn->prepare("
    SELECT email, full_name, phase, role
    FROM admins
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$me) {
    header('Location: ../index.php');
    exit;
}
$adminRole = strtolower(trim((string)($me['role'] ?? '')));
if ($adminRole === 'superadmin') {
    http_response_code(403);
    exit('Superadmin cannot access this module.');
}
if ($adminRole !== 'admin') {
    header('Location: ../index.php');
    exit;
}
function esc($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
$adminEmail = (string)($me['email'] ?? '');
$adminName = trim((string)($me['full_name'] ?? ''));
$myPhase = (string)($me['phase'] ?? 'Phase 1');
$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>HOA-ADMIN • Rentals Calendar</title>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

  <style>
    #rentCalWrap{
      border:1px solid #e5e7eb; border-radius:14px; padding:10px; background:#fff;
      box-shadow:0 10px 24px rgba(0,0,0,.06);
      overflow:hidden;
    }
    .fc .fc-daygrid-event,
    .fc .fc-timegrid-event{
      border-radius:999px;
      padding:2px 8px;
      font-weight:800;
      border-width:1px;
      cursor:pointer;
    }
    .fc .rent-approved{
      background:#ecfdf5 !important;
      border-color:#bbf7d0 !important;
      color:#166534 !important;
    }
    .modalx{
      display:none;
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.45);
      align-items:center;
      justify-content:center;
      z-index:9999;
      padding:16px;
    }
    .modalx .box{
      width:min(820px, 96vw);
      max-height:92vh;
      background:#fff;
      border-radius:16px;
      overflow:auto;
      box-shadow:0 20px 60px rgba(0,0,0,.25);
    }
    .modalx .boxhead{
      padding:14px 16px;
      border-bottom:1px solid #e5e7eb;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
    }
    .modalx .closebtn{
      border:none;
      background:transparent;
      font-size:22px;
      cursor:pointer;
      line-height:1;
    }
    .kv{
      display:flex;
      flex-wrap:wrap;
      gap:12px;
      margin-bottom:12px;
    }
    .kv > div{
      min-width:180px;
      background:#f8fafc;
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:10px 12px;
    }
    .mini-muted{
      color:#64748b;
      font-size:12px;
      font-weight:800;
      margin-bottom:3px;
    }
    .kv b{ font-weight:900; }
    .msgbox{
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:12px;
      background:#fff;
      white-space:pre-wrap;
      line-height:1.45;
    }
    .badge-soft{
      padding:.35rem .6rem;
      border-radius:999px;
      font-weight:900;
      font-size:12px;
      border:1px solid #e5e7eb;
      background:#f8fafc;
      color:#0f172a;
    }
    .badge-approved{ background:#ecfdf5; border-color:#bbf7d0; color:#166534; }
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

    .admin-theme-switch {
      display: flex;
      align-items: center;
      padding: 0 8px;
    }
    .admin-theme-toggle {
      width: 40px;
      height: 40px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 0;
      border-radius: 10px;
      background: transparent;
      color: inherit;
      font-size: 22px;
      cursor: pointer;
      transition: background .18s ease, color .18s ease;
    }
    .admin-theme-toggle:hover,
    .admin-theme-toggle:focus {
      background: rgba(15, 23, 42, .06);
      outline: none;
    }
    .admin-page-logout-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      min-width: 98px;
      min-height: 40px;
      margin: 0 18px 0 6px;
      padding: 8px 14px;
      border: 1px solid #fecaca;
      border-radius: 11px;
      background: #fff;
      color: #b91c1c !important;
      box-shadow: 0 4px 14px rgba(15, 23, 42, .06);
      font-size: 12px;
      line-height: 1;
      font-weight: 800;
      text-decoration: none !important;
      white-space: nowrap;
      transition:
        background .18s ease,
        color .18s ease,
        border-color .18s ease,
        transform .18s ease,
        box-shadow .18s ease;
    }
    .admin-page-logout-btn i {
      font-size: 17px;
      line-height: 1;
    }
    .admin-page-logout-btn:hover,
    .admin-page-logout-btn:focus {
      border-color: #ef4444;
      background: #fef2f2;
      color: #991b1b !important;
      box-shadow: 0 7px 18px rgba(220, 38, 38, .12);
      transform: translateY(-1px);
      outline: none;
    }
    html.dark body,
    html.dark .main-container {
      background: var(--admin-bg, #0f172a) !important;
      color: var(--admin-text, #e5e7eb) !important;
    }
    html.dark .page-header,
    html.dark .card-box,
    html.dark .footer-wrap {
      background: var(--admin-surface, #1f2937) !important;
      color: var(--admin-text, #e5e7eb) !important;
      border-color: var(--admin-border, #374151) !important;
    }
    html.dark .page-header h4,
    html.dark .card-box h4,
    html.dark .card-box h5,
    html.dark .card-box h6,
    html.dark .card-box b,
    html.dark .footer-wrap {
      color: var(--admin-text, #e5e7eb) !important;
    }
    html.dark .text-secondary,
    html.dark .text-muted,
    html.dark .mini-muted {
      color: var(--admin-muted, #9ca3af) !important;
    }
    html.dark #rentCalWrap {
      background: var(--admin-surface, #1f2937) !important;
      border-color: var(--admin-border, #374151) !important;
      box-shadow: none !important;
    }
    html.dark .fc {
      color: var(--admin-text, #e5e7eb) !important;
    }
    html.dark .fc .fc-toolbar-title {
      color: #f8fafc !important;
    }
    html.dark .fc .fc-button {
      background: #334155 !important;
      border-color: #475569 !important;
      color: #f8fafc !important;
      box-shadow: none !important;
    }
    html.dark .fc .fc-button:hover,
    html.dark .fc .fc-button:focus {
      background: #475569 !important;
      border-color: #64748b !important;
      color: #fff !important;
    }
    html.dark .fc .fc-button-primary:not(:disabled).fc-button-active,
    html.dark .fc .fc-button-primary:not(:disabled):active {
      background: #2563eb !important;
      border-color: #2563eb !important;
      color: #fff !important;
    }
    html.dark .fc-theme-standard td,
    html.dark .fc-theme-standard th,
    html.dark .fc-theme-standard .fc-scrollgrid {
      border-color: var(--admin-border, #374151) !important;
    }
    html.dark .fc .fc-col-header-cell {
      background: var(--admin-surface-2, #253244) !important;
    }
    html.dark .fc .fc-col-header-cell-cushion,
    html.dark .fc .fc-daygrid-day-number,
    html.dark .fc .fc-list-day-text,
    html.dark .fc .fc-list-day-side-text {
      color: #e5e7eb !important;
    }
    html.dark .fc .fc-daygrid-day,
    html.dark .fc .fc-timegrid-col,
    html.dark .fc .fc-list-table td {
      background: var(--admin-surface, #1f2937) !important;
    }
    html.dark .fc .fc-day-today {
      background: rgba(37, 99, 235, .13) !important;
    }
    html.dark .fc .fc-day-other .fc-daygrid-day-number {
      color: #64748b !important;
    }
    html.dark .fc .fc-timegrid-slot-label,
    html.dark .fc .fc-timegrid-axis-cushion,
    html.dark .fc .fc-list-event-time,
    html.dark .fc .fc-list-event-title {
      color: #cbd5e1 !important;
    }
    html.dark .fc .fc-list-day-cushion {
      background: var(--admin-surface-2, #253244) !important;
    }
    html.dark .fc .fc-list-event:hover td {
      background: var(--admin-hover, #334155) !important;
    }
    html.dark .fc .rent-approved {
      background: rgba(22, 163, 74, .18) !important;
      border-color: rgba(34, 197, 94, .45) !important;
      color: #bbf7d0 !important;
    }
    html.dark .modalx .box {
      background: var(--admin-surface, #1f2937) !important;
      color: var(--admin-text, #e5e7eb) !important;
      border: 1px solid var(--admin-border, #374151) !important;
    }
    html.dark .modalx .boxhead {
      background: var(--admin-surface-2, #253244) !important;
      color: var(--admin-text, #e5e7eb) !important;
      border-color: var(--admin-border, #374151) !important;
    }
    html.dark .modalx .closebtn {
      color: #f8fafc !important;
    }
    html.dark .kv > div {
      background: var(--admin-surface-2, #253244) !important;
      border-color: var(--admin-border, #374151) !important;
      color: var(--admin-text, #e5e7eb) !important;
    }
    html.dark .msgbox {
      background: var(--admin-input, #111827) !important;
      border-color: var(--admin-border, #374151) !important;
      color: var(--admin-text, #e5e7eb) !important;
    }
    html.dark .badge-soft {
      background: var(--admin-surface-2, #253244) !important;
      border-color: var(--admin-border, #374151) !important;
      color: #e5e7eb !important;
    }
    html.dark .badge-approved {
      background: rgba(22, 163, 74, .16) !important;
      border-color: rgba(34, 197, 94, .36) !important;
      color: #bbf7d0 !important;
    }
    html.dark .admin-theme-toggle {
      color: #f8fafc !important;
    }
    html.dark .admin-theme-toggle:hover,
    html.dark .admin-theme-toggle:focus {
      background: rgba(255,255,255,.08);
    }
    html.dark .admin-page-logout-btn {
      border-color: rgba(248, 113, 113, .30);
      background: rgba(127, 29, 29, .16);
      color: #fca5a5 !important;
      box-shadow: none;
    }
    html.dark .admin-page-logout-btn:hover,
    html.dark .admin-page-logout-btn:focus {
      border-color: rgba(248, 113, 113, .55);
      background: rgba(127, 29, 29, .28);
      color: #fecaca !important;
    }
    .access-toast {
      visibility: hidden;
      pointer-events: none;
    }
    .access-toast.show {
      visibility: visible;
      pointer-events: auto;
    }
    @media (max-width: 575.98px) {
      .admin-page-logout-btn {
        width: 40px;
        min-width: 40px;
        height: 40px;
        min-height: 40px;
        margin: 0 10px 0 4px;
        padding: 0;
        border-radius: 10px;
      }
      .admin-page-logout-btn span {
        display: none;
      }
      .admin-page-logout-btn i {
        font-size: 18px;
      }
      .admin-theme-switch {
        padding: 0 2px;
      }
      .fc .fc-toolbar.fc-header-toolbar {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
      }
      .fc .fc-toolbar-chunk {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
      }
      .modalx .box {
        width: 100%;
      }
    }
</style>

  <script>
  (function () {
    try {
      const savedTheme = localStorage.getItem('hoa-theme');
      const dark =
        savedTheme === 'dark' ||
        (
          !savedTheme &&
          window.matchMedia &&
          window.matchMedia('(prefers-color-scheme: dark)').matches
        );
      document.documentElement.classList.toggle('dark', dark);
    } catch (e) {}
  })();
  </script>
</head>

<body>

  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
      <div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>
    </div>

    <div class="header-right">
      <div class="admin-theme-switch">
        <button
          type="button"
          id="themeToggle"
          class="admin-theme-toggle"
          aria-label="Switch theme"
          title="Switch theme"
        >
          <span id="themeIcon">☾</span>
        </button>
      </div>
      <a href="logout.php"
         class="admin-page-logout-btn"
         title="Log out"
         aria-label="Log out">
        <i class="dw dw-logout" aria-hidden="true"></i>
        <span>Log Out</span>
      </a>
    </div>
  </div>

   <!-- SIDEBAR -->
<?php include 'sidebar.php'; ?>

  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header mb-20">
        <div class="row">
          <div class="col-md-12 col-sm-12">
            <div class="title"><h4>Approved Rentals Calendar</h4></div>
            <div class="text-secondary">
              Phase: <b><?= esc($phase) ?></b> • Approved bookings only
            </div>
          </div>
        </div>
      </div>

      <div class="card-box pd-20 mb-30">
        <div id="rentCalWrap">
          <div id="rentCal"></div>
        </div>
      </div>

      <div class="footer-wrap pd-20 mb-20 card-box">
        © Copyright South Meridian Homes All Rights Reserved
      </div>
    </div>
  </div>

  <div class="modalx" id="rentModal">
    <div class="box">
      <div class="boxhead">
        <div class="font-weight-bold">Rental Details</div>
        <button class="closebtn" type="button" id="closeRentModal">&times;</button>
      </div>

      <div class="p-3" id="rentModalBody">
        <div class="text-secondary">Loading...</div>
      </div>
    </div>
  </div>

  <script src="vendors/scripts/core.js"></script>
  <script src="vendors/scripts/script.min.js"></script>
  <script src="vendors/scripts/process.js"></script>
  <script src="vendors/scripts/layout-settings.js"></script>
  <script src="vendors/scripts/admin_theme.js"></script>

  <script>
    const rentModal = document.getElementById('rentModal');
    const rentBody  = document.getElementById('rentModalBody');

    function escHtml(str){
      return String(str ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
    }

    function openRentModal(event){
      const ep = event.extendedProps || {};

      const title    = escHtml(event.title || 'Reserved');
      const facility = escHtml(ep.facility || '—');
      const status   = escHtml((ep.status || 'approved').toUpperCase());

      const start = event.start ? event.start.toLocaleString() : '—';
      const end   = event.end ? event.end.toLocaleString() : '—';

      const hoName  = escHtml(ep.homeownerName || '—');
      const houseLot= escHtml(ep.houseLot || '—');
      const email   = escHtml(ep.email || '—');

      const purpose = escHtml(ep.purpose || '—');
      const notes   = escHtml(ep.notes || '—');
      const adminRm = escHtml(ep.adminRemarks || '—');

      rentBody.innerHTML = `
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
          <div style="font-weight:900;font-size:16px;">${title}</div>
          <span class="badge-soft badge-approved">${status}</span>
        </div>

        <div class="kv">
          <div><div class="mini-muted">Facility</div><b>${facility}</b></div>
          <div><div class="mini-muted">Start</div><b>${escHtml(start)}</b></div>
          <div><div class="mini-muted">End</div><b>${escHtml(end)}</b></div>
          <div><div class="mini-muted">Homeowner</div><b>${hoName}</b></div>
          <div><div class="mini-muted">House/Lot</div><b>${houseLot}</b></div>
          <div><div class="mini-muted">Email</div><b>${email}</b></div>
        </div>

        <div class="mini-muted">Purpose</div>
        <div class="msgbox mb-3">${purpose}</div>

        <div class="mini-muted">Notes</div>
        <div class="msgbox mb-3">${notes}</div>

        <div class="mini-muted">Admin Remarks</div>
        <div class="msgbox">${adminRm}</div>
      `;

      rentModal.style.display = 'flex';
    }

    function closeRentModal(){
      rentModal.style.display = 'none';
      rentBody.innerHTML = '<div class="text-secondary">Loading...</div>';
    }

    document.getElementById('closeRentModal')?.addEventListener('click', closeRentModal);
    rentModal?.addEventListener('click', (e) => { if (e.target === rentModal) closeRentModal(); });

    document.addEventListener('DOMContentLoaded', function(){
      const el = document.getElementById('rentCal');

      const cal = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        height: 'auto',
        headerToolbar: {
          left:'prev,next today',
          center:'title',
          right:'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },

        events: {
          url: 'admin_facility_events.php',
          method: 'GET',
          failure: function() {
            alert("Failed to load rental events. Open admin_facility_events.php directly to verify it returns JSON.");
          }
        },

        eventClick: function(info){
          info.jsEvent.preventDefault();
          openRentModal(info.event);
        }
      });

      cal.render();
    });
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
