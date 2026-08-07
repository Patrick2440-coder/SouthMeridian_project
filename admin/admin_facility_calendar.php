<?php
session_start();
require_once 'admin_access.php';
requireAccess('community');
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  echo "<script>alert('Access denied.'); window.location='index.php';</script>";
  exit;
}

if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
  echo "<script>alert('Superadmin cannot access President Dashboard.'); window.location='index.php';</script>";
  exit;
}
$db_host = "localhost";
$db_user = "u972459197_patrick";
$db_pass = "Idle2440";
$db_name = "u972459197_south_meridian";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$adminId = (int)($_SESSION['admin_id'] ?? 0);

$stmt = $conn->prepare("SELECT email, full_name, phase FROM admins WHERE id=? LIMIT 1");
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
$phase      = (string)($me['phase'] ?? 'Phase 1');
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>HOA-ADMIN • Rentals Calendar</title>

  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

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
  </style>
</head>

<body>

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