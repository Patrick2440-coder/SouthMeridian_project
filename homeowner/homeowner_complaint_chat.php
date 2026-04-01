<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
  header("Location: ../index.php");
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$hid = (int)$_SESSION['homeowner_id'];

$stmt = $conn->prepare("
  SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number
  FROM homeowners
  WHERE id=? LIMIT 1
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

$phase      = (string)$user['phase'];
$fullName   = trim($user['first_name'].' '.$user['last_name']);
$mustChange = ((int)$user['must_change_password'] === 1);
$initials   = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));
$pageTitle  = "Complaint Chat • ".$phase;
$activePage = basename($_SERVER['PHP_SELF'] ?? 'homeowner_complaint_chat.php');

$parkingPages = [
  'homeowner_parking.php',
  'homeowner_parking_permit.php',
  'homeowner_parking_violations.php'
];
$complaintPages = [
  'homeowner_complaints.php',
  'homeowner_complaint_chat.php'
];

$parkingOpen    = in_array($activePage, $parkingPages, true);
$complaintsOpen = in_array($activePage, $complaintPages, true);

$err = "";
$okMsg = "";

/* password change */
if ($mustChange && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password_submit'])) {
  $p1 = $_POST['password'] ?? '';
  $p2 = $_POST['password2'] ?? '';

  if (strlen($p1) < 8) $err = "Password must be at least 8 characters.";
  else if ($p1 !== $p2) $err = "Passwords do not match.";
  else {
    $hash = password_hash($p1, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("UPDATE homeowners SET password=?, must_change_password=0 WHERE id=?");
    $stmt->bind_param("si", $hash, $hid);
    $stmt->execute();
    $stmt->close();
    header("Location: homeowner_complaint_chat.php");
    exit;
  }
}

/* complaint list */
$complaints = [];
$stmt = $conn->prepare("
  SELECT c.*,
         a.full_name AS admin_name
  FROM complaints c
  LEFT JOIN admins a ON a.id = c.admin_id
  WHERE c.homeowner_id=?
  ORDER BY c.updated_at DESC, c.id DESC
");
$stmt->bind_param("i", $hid);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $complaints[] = $r;
$stmt->close();

$selectedComplaintId = (int)($_GET['complaint_id'] ?? 0);
if ($selectedComplaintId <= 0 && !empty($complaints)) {
  $selectedComplaintId = (int)$complaints[0]['id'];
}

$selectedComplaint = null;
if ($selectedComplaintId > 0) {
  $stmt = $conn->prepare("
    SELECT c.*, a.full_name AS admin_name, a.email AS admin_email
    FROM complaints c
    LEFT JOIN admins a ON a.id = c.admin_id
    WHERE c.id=? AND c.homeowner_id=? LIMIT 1
  ");
  $stmt->bind_param("ii", $selectedComplaintId, $hid);
  $stmt->execute();
  $selectedComplaint = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$selectedComplaint) $selectedComplaintId = 0;
}

/* send message */
if (!$mustChange && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_chat_submit'])) {
  $complaintId = (int)($_POST['complaint_id'] ?? 0);
  $message     = trim((string)($_POST['message'] ?? ''));

  if ($complaintId <= 0) {
    $err = "Invalid complaint selected.";
  } elseif ($message === '') {
    $err = "Message cannot be empty.";
  } else {
    $stmt = $conn->prepare("SELECT id, status FROM complaints WHERE id=? AND homeowner_id=? LIMIT 1");
    $stmt->bind_param("ii", $complaintId, $hid);
    $stmt->execute();
    $cCheck = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$cCheck) {
      $err = "Complaint not found.";
    } else {
      $stmt = $conn->prepare("
        INSERT INTO complaint_messages (complaint_id, sender_type, sender_homeowner_id, message)
        VALUES (?, 'homeowner', ?, ?)
      ");
      $stmt->bind_param("iis", $complaintId, $hid, $message);
      $stmt->execute();
      $stmt->close();

      if (in_array($cCheck['status'], ['resolved','closed'], true)) {
        $stmt = $conn->prepare("UPDATE complaints SET status='in_progress', updated_at=NOW() WHERE id=?");
        $stmt->bind_param("i", $complaintId);
      } else {
        $stmt = $conn->prepare("UPDATE complaints SET updated_at=NOW() WHERE id=?");
        $stmt->bind_param("i", $complaintId);
      }
      $stmt->execute();
      $stmt->close();

      header("Location: homeowner_complaint_chat.php?complaint_id=".$complaintId);
      exit;
    }
  }
}

/* messages */
$messages = [];
if ($selectedComplaintId > 0) {
  $stmt = $conn->prepare("
    SELECT cm.*,
           h.first_name, h.last_name,
           a.full_name AS admin_name
    FROM complaint_messages cm
    LEFT JOIN homeowners h ON h.id = cm.sender_homeowner_id
    LEFT JOIN admins a ON a.id = cm.sender_admin_id
    WHERE cm.complaint_id=?
    ORDER BY cm.created_at ASC, cm.id ASC
  ");
  $stmt->bind_param("i", $selectedComplaintId);
  $stmt->execute();
  $res = $stmt->get_result();
  while($r = $res->fetch_assoc()) $messages[] = $r;
  $stmt->close();
}

function status_badge_class($s){
  switch($s){
    case 'open': return 'bg-danger-subtle text-danger-emphasis border border-danger-subtle';
    case 'in_progress': return 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    case 'resolved': return 'bg-success-subtle text-success-emphasis border border-success-subtle';
    case 'closed': return 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle';
    default: return 'bg-light text-dark border';
  }
}

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
:root{
  --sidebar-width: 280px;
  --topbar-height: 64px;
}

html, body{
  overflow-x: hidden;
}

body{
  background:#f5f7fb;
}

/* Sidebar dropdown */
.sb-dd { display:flex; flex-direction:column; gap:6px; }
.sb-dd-toggle{ display:flex; align-items:center; justify-content:space-between; gap:10px; width:100%; }
.sb-dd-menu{ display:none; padding-left:12px; margin-top:2px; border-left:2px solid rgba(255,255,255,.08); }
.sb-dd.open .sb-dd-menu{ display:block; }
.sb-dd-caret{ transition: transform .15s ease; }
.sb-dd.open .sb-dd-caret{ transform: rotate(180deg); }

/* Layout */
.app-shell{
  min-height:100vh;
  display:flex;
}

.sidebar{
  width:var(--sidebar-width);
  flex:0 0 var(--sidebar-width);
}

.main-area{
  flex:1;
  min-width:0;
}

/* Mobile topbar */
.mobile-topbar{
  display:none;
  position:sticky;
  top:0;
  z-index:1040;
  height:var(--topbar-height);
  background:#ffffff;
  border-bottom:1px solid #e9ecef;
  padding:0 14px;
  align-items:center;
  justify-content:space-between;
}

.mobile-topbar .brand{
  font-weight:700;
  color:#198754;
  font-size:1rem;
}

.mobile-icon-btn{
  width:42px;
  height:42px;
  border:none;
  border-radius:12px;
  background:#f1f5f9;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  font-size:1.2rem;
}

/* Overlay */
.sidebar-overlay{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,.45);
  z-index:1045;
  opacity:0;
  visibility:hidden;
  transition:.2s ease;
}

.sidebar-overlay.show{
  opacity:1;
  visibility:visible;
}

/* Profile card */
.fb-profile-row{
  margin-bottom:20px;
}
.fb-profile-card{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  background:#fff;
  border:1px solid #eef2f7;
  border-radius:20px;
  padding:18px;
  box-shadow:0 10px 30px rgba(15,23,42,.06);
}
.fb-avatar{
  width:64px;
  height:64px;
  border-radius:50%;
  background:#198754;
  color:#fff;
  display:flex;
  align-items:center;
  justify-content:center;
  font-weight:700;
  font-size:1.15rem;
  flex-shrink:0;
}
.fb-name{
  margin:0 0 4px;
  font-size:1.35rem;
  font-weight:700;
}
.fb-sub{
  color:#64748b;
  font-size:.95rem;
}
.fb-actions{
  margin-left:auto;
}
.pill{
  display:inline-flex;
  align-items:center;
  padding:6px 12px;
  border-radius:999px;
  background:#f1f5f9;
  color:#0f172a;
  font-size:.85rem;
  font-weight:600;
}
.btn-hoa{
  background:#198754;
  color:#fff;
  border:none;
}
.btn-hoa:hover{
  background:#157347;
  color:#fff;
}

/* Chat area */
.chat-wrap{
  display:grid;
  grid-template-columns: 330px 1fr;
  gap:20px;
  align-items:start;
}
.chat-list, .chat-panel{
  background:#fff;
  border-radius:18px;
  border:1px solid #eef2f7;
  box-shadow:0 10px 30px rgba(15,23,42,.06);
}
.chat-item{
  display:block;
  text-decoration:none;
  color:inherit;
  border:1px solid #eef2f7;
  border-radius:14px;
  padding:14px;
  transition:.15s ease;
}
.chat-item:hover{
  background:#f8fafc;
}
.chat-item.active{
  border-color:#198754;
  background:#f4fff8;
}

.msg-area{
  height:480px;
  overflow:auto;
  background:#f8fafc;
  border-radius:14px;
  padding:18px;
}
.msg-row{
  display:flex;
  margin-bottom:12px;
}
.msg-row.mine{ justify-content:flex-end; }
.msg-bubble{
  max-width:72%;
  padding:12px 14px;
  border-radius:16px;
  box-shadow:0 6px 18px rgba(15,23,42,.06);
  word-wrap:break-word;
  overflow-wrap:break-word;
}
.msg-row.mine .msg-bubble{
  background:#198754;
  color:#fff;
  border-bottom-right-radius:6px;
}
.msg-row.theirs .msg-bubble{
  background:#fff;
  color:#0f172a;
  border:1px solid #e5e7eb;
  border-bottom-left-radius:6px;
}
.msg-meta{
  font-size:.78rem;
  opacity:.85;
  margin-top:6px;
}

.chat-form .form-control{
  resize:none;
  min-height:58px;
}
.chat-form .btn{
  min-width:110px;
}

/* Lock modal helpers */
.blur-wrap{
  filter:blur(4px);
  pointer-events:none;
  user-select:none;
}
.lock-overlay{
  position:fixed;
  inset:0;
  background:rgba(15,23,42,.45);
  z-index:2000;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:20px;
}
.lock-modal{
  width:100%;
  max-width:420px;
  background:#fff;
  border-radius:18px;
  overflow:hidden;
  box-shadow:0 20px 50px rgba(0,0,0,.18);
}
.lock-modal .head{
  display:flex;
  align-items:center;
  gap:12px;
  padding:16px 18px;
  background:#198754;
  color:#fff;
}
.lock-modal .body{
  padding:18px;
}

/* Tablet */
@media (max-width: 991px){
  .chat-wrap{
    grid-template-columns: 1fr;
  }

  .msg-area{
    height:420px;
  }

  .navbar .container-xl{
    padding-left:14px;
    padding-right:14px;
  }
}

/* Mobile */
@media (max-width: 767.98px){
  .mobile-topbar{
    display:flex;
  }

  .sidebar{
    position:fixed;
    top:0;
    left:-100%;
    width:var(--sidebar-width);
    max-width:86vw;
    height:100vh;
    z-index:1050;
    transition:left .25s ease;
    overflow-y:auto;
  }

  .sidebar.show{
    left:0;
  }

  .main-area{
    width:100%;
    min-width:0;
  }

  .navbar{
    display:none;
  }

  .container-xl{
    padding-left:12px;
    padding-right:12px;
  }

  .fb-profile-card{
    flex-direction:column;
    align-items:flex-start;
    padding:16px;
  }

  .fb-avatar{
    width:56px;
    height:56px;
    font-size:1rem;
  }

  .fb-name{
    font-size:1.1rem;
  }

  .fb-actions{
    width:100%;
    margin-left:0;
  }

  .fb-actions .btn{
    width:100%;
  }

  .chat-list,
  .chat-panel{
    border-radius:16px;
  }

  .chat-list{
    padding:12px !important;
  }

  .chat-panel{
    padding:12px !important;
  }

  .chat-item{
    padding:12px;
  }

  .msg-area{
    height:52vh;
    min-height:320px;
    max-height:70vh;
    padding:12px;
  }

  .msg-bubble{
    max-width:88%;
    font-size:.95rem;
    padding:10px 12px;
  }

  .chat-form .input-group{
    flex-direction:column;
    gap:10px;
  }

  .chat-form .form-control{
    width:100% !important;
    border-radius:12px !important;
  }

  .chat-form .btn{
    width:100%;
    border-radius:12px !important;
  }

  .small-hide-mobile{
    display:none !important;
  }
}

/* Very small screens */
@media (max-width: 420px){
  .fb-name{
    font-size:1rem;
  }

  .pill{
    font-size:.78rem;
    padding:5px 10px;
  }

  .msg-bubble{
    max-width:92%;
  }

  .mobile-topbar .brand{
    font-size:.95rem;
  }
}
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="mobile-topbar">
  <button class="mobile-icon-btn" id="mobileMenuBtn" type="button" aria-label="Open menu">
    <i class="bi bi-list"></i>
  </button>
  <div class="brand">HOA Community</div>
  <a href="logout.php" class="mobile-icon-btn text-decoration-none text-dark" aria-label="Logout">
    <i class="bi bi-box-arrow-right"></i>
  </a>
</div>

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

      <div class="sb-dd <?= $complaintsOpen ? 'open' : '' ?>" id="sbComplaints">
        <a class="sb-link sb-dd-toggle <?= $complaintsOpen ? 'active' : '' ?>" href="javascript:void(0)" id="sbComplaintsToggle">
          <span><i class="bi bi-chat-dots-fill"></i> <span>Complaints</span></span>
          <i class="bi bi-chevron-down sb-dd-caret"></i>
        </a>
        <div class="sb-dd-menu">
          <a class="sb-link <?= $activePage==='homeowner_complaints.php' ? 'active' : '' ?>" href="homeowner_complaints.php">
            <i class="bi bi-pencil-square"></i> <span>File a Complaint</span>
          </a>
          <a class="sb-link <?= $activePage==='homeowner_complaint_chat.php' ? 'active' : '' ?>" href="homeowner_complaint_chat.php">
            <i class="bi bi-chat-left-text-fill"></i> <span>Complaint Chat</span>
          </a>
        </div>
      </div>
            <a class="sb-link <?= $activePage==='homeowner_voting.php' ? 'active' : '' ?>" href="homeowner_voting.php">
  <i class="bi bi-check2-square"></i> <span>Voting</span>
</a>

      <a class="sb-link" href="logout.php">
        <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
      </a>
    </nav>
  </aside>

  <div class="main-area">
    <div class="<?= $mustChange ? 'blur-wrap' : '' ?>">

      <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container-xl">
          <a class="navbar-brand fw-bold text-success" href="homeowner_dashboard.php">HOA Community</a>
          <div class="ms-auto d-flex align-items-center gap-3">
            <div class="small text-muted d-none d-md-block">
              Logged in as <b><?= esc($fullName) ?></b> (<?= esc($phase) ?>)
            </div>
            <a href="logout.php" class="btn btn-sm btn-outline-success">Logout</a>
          </div>
        </div>
      </nav>

      <div class="container-xl my-4">
        <?php if ($err): ?>
          <div class="alert alert-danger"><?= esc($err) ?></div>
        <?php endif; ?>
<br>
<br>
<br>
        <div class="fb-profile-row">
          <div class="fb-profile-card">
            <div class="d-flex align-items-start gap-3 w-100">
              <div class="fb-avatar"><?= esc($initials) ?></div>
              <div class="flex-grow-1">
                <h2 class="fb-name">Talk to Phase Admin</h2>
                <div class="fb-sub"><?= esc($phase) ?> complaint conversations</div>
                <div class="mt-2 d-flex gap-2 flex-wrap">
                  <span class="pill">💬 Complaint chat</span>
                  <span class="pill">📌 Complaint updates</span>
                </div>
              </div>
            </div>
            <div class="fb-actions">
              <a class="btn btn-hoa" href="homeowner_complaints.php">
                <i class="bi bi-plus-circle-fill me-1"></i> New Complaint
              </a>
            </div>
          </div>
        </div>

        <div class="chat-wrap">
          <div class="chat-list p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h5 class="mb-0">My Complaints</h5>
            </div>

            <?php if (empty($complaints)): ?>
              <div class="text-muted fw-semibold">No complaints yet.</div>
            <?php else: ?>
              <div class="d-flex flex-column gap-2">
                <?php foreach($complaints as $c): ?>
                  <a class="chat-item <?= ((int)$c['id'] === (int)$selectedComplaintId) ? 'active' : '' ?>"
                     href="homeowner_complaint_chat.php?complaint_id=<?= (int)$c['id'] ?>">
                    <div class="d-flex justify-content-between gap-2 align-items-start">
                      <div class="fw-bold"><?= esc($c['subject']) ?></div>
                      <span class="badge <?= esc(status_badge_class($c['status'])) ?>">
                        <?= esc(strtoupper(str_replace('_',' ', $c['status']))) ?>
                      </span>
                    </div>
                    <div class="text-muted small mt-1">
                      <?= esc(ucfirst($c['category'])) ?> • <?= esc(date('M d, Y', strtotime($c['created_at']))) ?>
                    </div>
                    <div class="text-muted small mt-1">
                      Admin: <?= esc($c['admin_name'] ?: 'Phase Admin') ?>
                    </div>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="chat-panel p-3">
            <?php if (!$selectedComplaint): ?>
              <div class="d-flex align-items-center justify-content-center text-center text-muted fw-semibold" style="min-height:300px;">
                Select a complaint conversation.
              </div>
            <?php else: ?>
              <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                  <h5 class="mb-1"><?= esc($selectedComplaint['subject']) ?></h5>
                  <div class="text-muted small">
                    <?= esc(ucfirst($selectedComplaint['category'])) ?> •
                    Assigned admin: <?= esc($selectedComplaint['admin_name'] ?: 'Phase Admin') ?>
                  </div>
                </div>
                <span class="badge <?= esc(status_badge_class($selectedComplaint['status'])) ?>">
                  <?= esc(strtoupper(str_replace('_',' ', $selectedComplaint['status']))) ?>
                </span>
              </div>

              <div class="msg-area mb-3" id="msgArea">
                <?php if (empty($messages)): ?>
                  <div class="text-muted fw-semibold">No messages yet.</div>
                <?php else: ?>
                  <?php foreach($messages as $m): ?>
                    <?php
                      $isMine = ($m['sender_type'] === 'homeowner');
                      $senderName = $isMine
                        ? $fullName
                        : ((string)($m['admin_name'] ?? 'Phase Admin'));
                    ?>
                    <div class="msg-row <?= $isMine ? 'mine' : 'theirs' ?>">
                      <div class="msg-bubble">
                        <div class="fw-bold small mb-1"><?= esc($senderName) ?></div>
                        <div><?= nl2br(esc($m['message'])) ?></div>
                        <div class="msg-meta"><?= esc(date('M d, Y h:i A', strtotime($m['created_at']))) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <form method="POST" autocomplete="off" class="chat-form">
                <input type="hidden" name="send_chat_submit" value="1">
                <input type="hidden" name="complaint_id" value="<?= (int)$selectedComplaintId ?>">
                <div class="input-group">
                  <textarea name="message" class="form-control" rows="2" placeholder="Type your message here..." maxlength="2000" required></textarea>
                  <button class="btn btn-success fw-semibold" type="submit">
                    <i class="bi bi-send-fill me-1"></i> Send
                  </button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>

    <?php if ($mustChange): ?>
      <div class="lock-overlay">
        <div class="lock-modal">
          <div class="head">
            <i class="bi bi-shield-lock-fill fs-5"></i>
            <div>
              <div class="fw-bold">Change Password Required</div>
              <div class="small opacity-75">You must change your password before continuing.</div>
            </div>
          </div>
          <div class="body">
            <?php if ($err): ?>
              <div class="alert alert-danger"><?= esc($err) ?></div>
            <?php endif; ?>
            <form method="POST" autocomplete="off">
              <input type="hidden" name="change_password_submit" value="1">
              <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" name="password" class="form-control" minlength="8" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="password2" class="form-control" minlength="8" required>
              </div>
              <button class="btn btn-success w-100 py-2 fw-semibold">Save Password</button>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<script>
(function(){
  const wrap = document.getElementById('sbParking');
  const btn  = document.getElementById('sbParkingToggle');
  if (wrap && btn) {
    btn.addEventListener('click', () => wrap.classList.toggle('open'));
  }
})();

(function(){
  const wrap = document.getElementById('sbComplaints');
  const btn  = document.getElementById('sbComplaintsToggle');
  if (wrap && btn) {
    btn.addEventListener('click', () => wrap.classList.toggle('open'));
  }
})();

(function(){
  const el = document.getElementById('msgArea');
  if (el) el.scrollTop = el.scrollHeight;
})();

(function(){
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const menuBtn = document.getElementById('mobileMenuBtn');

  function openSidebar(){
    if(sidebar) sidebar.classList.add('show');
    if(overlay) overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar(){
    if(sidebar) sidebar.classList.remove('show');
    if(overlay) overlay.classList.remove('show');
    document.body.style.overflow = '';
  }

  if(menuBtn){
    menuBtn.addEventListener('click', openSidebar);
  }

  if(overlay){
    overlay.addEventListener('click', closeSidebar);
  }

  if(sidebar){
    sidebar.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', function(){
        if(window.innerWidth <= 767){
          closeSidebar();
        }
      });
    });
  }

  window.addEventListener('resize', function(){
    if(window.innerWidth > 767){
      closeSidebar();
    }
  });
})();
</script>

</body>
</html>