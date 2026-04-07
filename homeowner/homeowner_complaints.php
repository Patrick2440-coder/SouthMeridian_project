<?php
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
  header("Location: ../index.php");
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
    WHERE id=?
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
    SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number, latitude, longitude
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

  tenant_guard('complaints', $tenant);
} else {
  if (empty($_SESSION['homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $hid = (int)$_SESSION['homeowner_id'];

  $stmt = $conn->prepare("
    SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number, latitude, longitude
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
}

$phase      = (string)$user['phase'];
$mustChange = !$isTenant && ((int)$user['must_change_password'] === 1);

if ($isTenant) {
  $fullName = trim(($tenant['first_name'] ?? '').' '.($tenant['last_name'] ?? ''));
  $initials = strtoupper(substr($tenant['first_name'] ?? 'T',0,1).substr($tenant['last_name'] ?? 'N',0,1));
} else {
  $fullName = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));
  $initials = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));
}

$pageTitle  = "Complaints • ".$phase;
$activePage = basename($_SERVER['PHP_SELF'] ?? 'homeowner_complaints.php');

$parkingPages   = ['homeowner_parking.php','homeowner_parking_permit.php','homeowner_parking_violations.php'];
$complaintPages = ['homeowner_complaints.php','homeowner_complaint_chat.php'];

$parkingOpen    = in_array($activePage, $parkingPages, true);
$complaintsOpen = in_array($activePage, $complaintPages, true);

$err = "";
$okMsg = "";

$stmt = $conn->prepare("SELECT id, full_name, email FROM admins WHERE phase=? AND role='admin' LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$phaseAdmin = $stmt->get_result()->fetch_assoc();
$stmt->close();
$phaseAdminId = (int)($phaseAdmin['id'] ?? 0);

if (!$isTenant && $mustChange && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password_submit'])) {
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
    header("Location: homeowner_complaints.php");
    exit;
  }
}

if (!$mustChange && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file_complaint_submit'])) {
  $subject     = trim((string)($_POST['subject'] ?? ''));
  $category    = trim((string)($_POST['category'] ?? 'general'));
  $priority    = trim((string)($_POST['priority'] ?? 'normal'));
  $description = trim((string)($_POST['description'] ?? ''));

  $allowedCategories = ['general','security','maintenance','noise','parking','neighbor','billing','other'];
  $allowedPriorities = ['low','normal','high','urgent'];

  if ($subject === '' || mb_strlen($subject) < 3) {
    $err = "Subject must be at least 3 characters.";
  } elseif (!in_array($category, $allowedCategories, true)) {
    $err = "Invalid category selected.";
  } elseif (!in_array($priority, $allowedPriorities, true)) {
    $err = "Invalid priority selected.";
  } elseif ($description === '' || mb_strlen($description) < 10) {
    $err = "Complaint description must be at least 10 characters.";
  } else {
    $stmt = $conn->prepare("
      INSERT INTO complaints (homeowner_id, phase, admin_id, subject, category, description, status, priority)
      VALUES (?,?,?,?,?,?, 'open', ?)
    ");
    $stmt->bind_param("isissss", $hid, $phase, $phaseAdminId, $subject, $category, $description, $priority);
    $stmt->execute();
    $complaintId = (int)$stmt->insert_id;
    $stmt->close();

    $stmt = $conn->prepare("
      INSERT INTO complaint_messages (complaint_id, sender_type, sender_homeowner_id, message)
      VALUES (?, 'homeowner', ?, ?)
    ");
    $stmt->bind_param("iis", $complaintId, $hid, $description);
    $stmt->execute();
    $stmt->close();

    $okMsg = "Complaint filed successfully.";
  }
}

$stats = ['all'=>0,'open'=>0,'in_progress'=>0,'resolved'=>0,'closed'=>0];

$stmt = $conn->prepare("
  SELECT status, COUNT(*) c
  FROM complaints
  WHERE homeowner_id=?
  GROUP BY status
");
$stmt->bind_param("i", $hid);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
  $stats[(string)$r['status']] = (int)$r['c'];
  $stats['all'] += (int)$r['c'];
}
$stmt->close();

$complaints = [];
$stmt = $conn->prepare("
  SELECT c.*,
         a.full_name AS admin_name,
         (
           SELECT MAX(cm.created_at)
           FROM complaint_messages cm
           WHERE cm.complaint_id = c.id
         ) AS last_message_at
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

function status_badge_class($s){
  switch($s){
    case 'open': return 'bg-danger-subtle text-danger-emphasis border border-danger-subtle';
    case 'in_progress': return 'bg-warning-subtle text-warning-emphasis border border-warning-subtle';
    case 'resolved': return 'bg-success-subtle text-success-emphasis border border-success-subtle';
    case 'closed': return 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle';
    default: return 'bg-light text-dark border';
  }
}

function priority_badge_class($p){
  switch($p){
    case 'urgent': return 'bg-danger text-white';
    case 'high': return 'bg-warning text-dark';
    case 'normal': return 'bg-primary text-white';
    case 'low': return 'bg-secondary text-white';
    default: return 'bg-light text-dark';
  }
}
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

.complaint-card{
  background:#fff;
  border-radius:18px;
  border:1px solid #eef2f7;
  box-shadow:0 10px 30px rgba(15,23,42,.06);
}
.quick-stat{
  border-radius:16px;
  padding:16px;
  background:#fff;
  border:1px solid #eef2f7;
  box-shadow:0 8px 24px rgba(15,23,42,.05);
}
.quick-stat .num{
  font-size:1.5rem;
  font-weight:800;
}
.form-soft{
  border-radius:14px !important;
  padding:.85rem 1rem;
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

  .mobile-topbar .brand{
    font-size:.95rem;
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

<?php include 'homeowner_sidebar.php'; ?>
  <div class="main-area">
    <div class="<?= $mustChange ? 'blur-wrap' : '' ?>">

      <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container-xl">
          <a class="navbar-brand fw-bold text-success" href="homeowner_dashboard.php">HOA Community</a>
          <div class="ms-auto d-flex align-items-center gap-3">
            <div class="small text-muted d-none d-md-block">
              Logged in as <b><?= esc($fullName) ?></b> (<?= esc($phase) ?><?= $isTenant ? ' • Tenant' : '' ?>)
            </div>
            <a href="logout.php" class="btn btn-sm btn-outline-success">Logout</a>
          </div>
        </div>
      </nav>
<br>
<br>
<br>
      <div class="container-xl my-4">

        <div class="fb-profile-row">
          <div class="fb-profile-card">
            <div class="d-flex align-items-start gap-3 w-100">
              <div class="fb-avatar"><?= esc($initials) ?></div>
              <div class="flex-grow-1">
                <h2 class="fb-name">Complaints Center</h2>
                <div class="fb-sub"><?= esc($phase) ?> • <?= esc($fullName) ?><?= $isTenant ? ' • Tenant' : '' ?></div>
                <div class="mt-2 d-flex gap-2 flex-wrap">
                  <span class="pill">📝 File concerns</span>
                  <span class="pill">💬 Talk to your phase admin</span>
                </div>
              </div>
            </div>
            <div class="fb-actions">
              <a class="btn btn-hoa" href="homeowner_complaint_chat.php">
                <i class="bi bi-chat-dots-fill me-1"></i> Open Chat
              </a>
            </div>
          </div>
        </div>

        <?php if ($okMsg): ?>
          <div class="alert alert-success"><?= esc($okMsg) ?></div>
        <?php endif; ?>

        <?php if ($err): ?>
          <div class="alert alert-danger"><?= esc($err) ?></div>
        <?php endif; ?>

        <div class="row g-4">
          <div class="col-lg-4">
            <div class="quick-stat"><div class="text-muted small">All Complaints</div><div class="num"><?= (int)$stats['all'] ?></div></div>
          </div>
          <div class="col-lg-2">
            <div class="quick-stat"><div class="text-muted small">Open</div><div class="num"><?= (int)$stats['open'] ?></div></div>
          </div>
          <div class="col-lg-2">
            <div class="quick-stat"><div class="text-muted small">In Progress</div><div class="num"><?= (int)$stats['in_progress'] ?></div></div>
          </div>
          <div class="col-lg-2">
            <div class="quick-stat"><div class="text-muted small">Resolved</div><div class="num"><?= (int)$stats['resolved'] ?></div></div>
          </div>
          <div class="col-lg-2">
            <div class="quick-stat"><div class="text-muted small">Closed</div><div class="num"><?= (int)$stats['closed'] ?></div></div>
          </div>
        </div>

        <div class="row g-4 mt-1">
          <div class="col-lg-5">
            <div class="complaint-card p-4">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">File a Complaint</h5>
                <span class="badge <?= $isTenant ? 'bg-info-subtle text-info-emphasis border border-info-subtle' : 'bg-success-subtle text-success-emphasis border border-success-subtle' ?>">
                  <?= $isTenant ? 'Tenant' : 'Homeowner' ?>
                </span>
              </div>

              <form method="POST" autocomplete="off">
                <input type="hidden" name="file_complaint_submit" value="1">

                <div class="mb-3">
                  <label class="form-label fw-semibold">Subject</label>
                  <input type="text" name="subject" class="form-control form-soft" maxlength="255" required>
                </div>

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label fw-semibold">Category</label>
                    <select name="category" class="form-select form-soft" required>
                      <option value="general">General</option>
                      <option value="security">Security</option>
                      <option value="maintenance">Maintenance</option>
                      <option value="noise">Noise</option>
                      <option value="parking">Parking</option>
                      <option value="neighbor">Neighbor</option>
                      <option value="billing">Billing</option>
                      <option value="other">Other</option>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label class="form-label fw-semibold">Priority</label>
                    <select name="priority" class="form-select form-soft" required>
                      <option value="low">Low</option>
                      <option value="normal" selected>Normal</option>
                      <option value="high">High</option>
                      <option value="urgent">Urgent</option>
                    </select>
                  </div>
                </div>

                <div class="mt-3">
                  <label class="form-label fw-semibold">Complaint Details</label>
                  <textarea name="description" rows="6" class="form-control form-soft" maxlength="3000" required></textarea>
                </div>

                <div class="mt-3 d-grid">
                  <button class="btn btn-success btn-lg fw-semibold">
                    <i class="bi bi-send-fill me-1"></i> Submit Complaint
                  </button>
                </div>
              </form>
            </div>
          </div>

          <div class="col-lg-7">
            <div class="complaint-card p-4">
              <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h5 class="mb-0">My Complaint Records</h5>
                <a href="homeowner_complaint_chat.php" class="btn btn-outline-success btn-sm">
                  <i class="bi bi-chat-dots-fill me-1"></i> Go to Chat
                </a>
              </div>

              <?php if (empty($complaints)): ?>
                <div class="text-muted fw-semibold">No complaints filed yet.</div>
              <?php else: ?>
                <div class="d-flex flex-column gap-3">
                  <?php foreach($complaints as $c): ?>
                    <div class="border rounded-4 p-3">
                      <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                        <div>
                          <div class="fw-bold"><?= esc($c['subject']) ?></div>
                          <div class="text-muted small">
                            <?= esc(ucwords(str_replace('_',' ', $c['category']))) ?> •
                            Filed on <?= esc(date('M d, Y h:i A', strtotime($c['created_at']))) ?>
                          </div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                          <span class="badge <?= esc(status_badge_class($c['status'])) ?>">
                            <?= esc(strtoupper(str_replace('_',' ', $c['status']))) ?>
                          </span>
                          <span class="badge <?= esc(priority_badge_class($c['priority'])) ?>">
                            <?= esc(strtoupper($c['priority'])) ?>
                          </span>
                        </div>
                      </div>

                      <div class="text-muted mb-2"><?= nl2br(esc($c['description'])) ?></div>

                      <div class="small text-muted d-flex justify-content-between flex-wrap gap-2">
                        <span>Assigned admin: <b><?= esc($c['admin_name'] ?: 'Phase Admin') ?></b></span>
                        <span>Last update: <b><?= esc(date('M d, Y h:i A', strtotime($c['updated_at']))) ?></b></span>
                      </div>

                      <div class="mt-3">
                        <a href="homeowner_complaint_chat.php?complaint_id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-success">
                          <i class="bi bi-chat-left-text-fill me-1"></i> Open Conversation
                        </a>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div>

    <?php if (!$isTenant && $mustChange): ?>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
  const tenantWrap = document.getElementById('sbTenant');
  const tenantBtn  = document.getElementById('sbTenantToggle');
  if (tenantWrap && tenantBtn) {
    tenantBtn.addEventListener('click', () => tenantWrap.classList.toggle('open'));
  }
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