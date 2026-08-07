<?php
session_start();
require_once 'admin_access.php';
requireAccess('complaints');
/* =========================
   1) AUTH GUARD
   ========================= */
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)) {
  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
  exit;
}

/* superadmin not allowed here */
if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
  echo "<script>alert('Superadmin cannot access President Dashboard.'); window.location='index.php';</script>";
  exit;
}

/* =========================
   2) DB
   ========================= */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db_host = "localhost";
$db_user = "u972459197_patrick";
$db_pass = "Idle2440";
$db_name = "u972459197_south_meridian";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function complaintStatusBadgeClass($s){
  $s = (string)$s;
  if ($s === 'open') return 'badge-soft-warning';
  if ($s === 'in_progress') return 'badge-soft-info';
  if ($s === 'resolved') return 'badge-soft-success';
  if ($s === 'closed') return 'badge-soft-secondary';
  return 'badge-soft-warning';
}
function complaintPriorityClass($p){
  $p = (string)$p;
  if ($p === 'urgent') return 'ann-badge urgent';
  if ($p === 'high') return 'ann-badge important';
  if ($p === 'normal') return 'ann-badge normal';
  return 'ann-badge';
}

/* =========================
   3) ADMIN INFO
   ========================= */
$adminId = (int)($_SESSION['admin_id'] ?? 0);

$stmt = $conn->prepare("SELECT id, email, full_name, phase, role FROM admins WHERE id=? LIMIT 1");
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

$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];
if (!in_array($phase, $allowedPhases, true)) {
  $phase = 'Phase 1';
}

$filter = (string)($_GET['filter'] ?? 'all');
$allowedFilters = ['all','open','in_progress','resolved','closed'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'all';

$selectedComplaintId = (int)($_GET['complaint_id'] ?? 0);

$ok = '';
$err = '';

/* =========================
   4) POST ACTIONS
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  /* send reply */
  if (isset($_POST['send_reply_submit'])) {
    $complaintId = (int)($_POST['complaint_id'] ?? 0);
    $message = trim((string)($_POST['message'] ?? ''));

    if ($complaintId <= 0) {
      $err = "Invalid complaint.";
    } elseif ($message === '') {
      $err = "Message cannot be empty.";
    } else {
      $stmt = $conn->prepare("SELECT id, status FROM complaints WHERE id=? AND phase=? LIMIT 1");
      $stmt->bind_param("is", $complaintId, $phase);
      $stmt->execute();
      $chk = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (!$chk) {
        $err = "Complaint not found.";
      } else {
        $stmt = $conn->prepare("
          INSERT INTO complaint_messages (complaint_id, sender_type, sender_admin_id, message)
          VALUES (?, 'admin', ?, ?)
        ");
        $stmt->bind_param("iis", $complaintId, $adminId, $message);
        $stmt->execute();
        $stmt->close();

        if (in_array((string)$chk['status'], ['open','resolved','closed'], true)) {
          $stmt = $conn->prepare("UPDATE complaints SET admin_id=?, status='in_progress', updated_at=NOW() WHERE id=?");
          $stmt->bind_param("ii", $adminId, $complaintId);
        } else {
          $stmt = $conn->prepare("UPDATE complaints SET admin_id=?, updated_at=NOW() WHERE id=?");
          $stmt->bind_param("ii", $adminId, $complaintId);
        }
        $stmt->execute();
        $stmt->close();

        header("Location: admin_complaints.php?filter=" . urlencode($filter) . "&complaint_id=" . $complaintId);
        exit;
      }
    }
  }

  /* update status */
  if (isset($_POST['update_status_submit'])) {
    $complaintId = (int)($_POST['complaint_id'] ?? 0);
    $newStatus   = trim((string)($_POST['status'] ?? ''));

    $allowedStatuses = ['open','in_progress','resolved','closed'];

    if ($complaintId <= 0) {
      $err = "Invalid complaint.";
    } elseif (!in_array($newStatus, $allowedStatuses, true)) {
      $err = "Invalid status.";
    } else {
      $stmt = $conn->prepare("SELECT id FROM complaints WHERE id=? AND phase=? LIMIT 1");
      $stmt->bind_param("is", $complaintId, $phase);
      $stmt->execute();
      $chk = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (!$chk) {
        $err = "Complaint not found.";
      } else {
        $stmt = $conn->prepare("UPDATE complaints SET status=?, admin_id=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param("sii", $newStatus, $adminId, $complaintId);
        $stmt->execute();
        $stmt->close();

        $statusLabel = strtoupper(str_replace('_', ' ', $newStatus));
        $systemMsg = "Complaint status updated to " . $statusLabel . ".";

        $stmt = $conn->prepare("
          INSERT INTO complaint_messages (complaint_id, sender_type, sender_admin_id, message)
          VALUES (?, 'admin', ?, ?)
        ");
        $stmt->bind_param("iis", $complaintId, $adminId, $systemMsg);
        $stmt->execute();
        $stmt->close();

        header("Location: admin_complaints.php?filter=" . urlencode($filter) . "&complaint_id=" . $complaintId);
        exit;
      }
    }
  }
}

/* =========================
   5) COUNTS
   ========================= */
$counts = [
  'all' => 0,
  'open' => 0,
  'in_progress' => 0,
  'resolved' => 0,
  'closed' => 0
];

$stmt = $conn->prepare("
  SELECT status, COUNT(*) c
  FROM complaints
  WHERE phase=?
  GROUP BY status
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
  $st = (string)$r['status'];
  $counts[$st] = (int)$r['c'];
  $counts['all'] += (int)$r['c'];
}
$stmt->close();

/* =========================
   6) COMPLAINT LIST
   ========================= */
$sql = "
  SELECT c.*,
         h.first_name, h.middle_name, h.last_name, h.email AS homeowner_email, h.contact_number, h.house_lot_number,
         a.full_name AS assigned_admin_name
  FROM complaints c
  LEFT JOIN homeowners h ON h.id = c.homeowner_id
  LEFT JOIN admins a ON a.id = c.admin_id
  WHERE c.phase=?
";
$types = "s";
$params = [$phase];

if ($filter !== 'all') {
  $sql .= " AND c.status=?";
  $types .= "s";
  $params[] = $filter;
}

$sql .= " ORDER BY c.updated_at DESC, c.id DESC LIMIT 200";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$complaints = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if ($selectedComplaintId <= 0 && !empty($complaints)) {
  $selectedComplaintId = (int)$complaints[0]['id'];
}

/* =========================
   7) SELECTED COMPLAINT
   ========================= */
$selectedComplaint = null;
if ($selectedComplaintId > 0) {
  $stmt = $conn->prepare("
    SELECT c.*,
           h.first_name, h.middle_name, h.last_name, h.email AS homeowner_email, h.contact_number, h.house_lot_number,
           a.full_name AS assigned_admin_name
    FROM complaints c
    LEFT JOIN homeowners h ON h.id = c.homeowner_id
    LEFT JOIN admins a ON a.id = c.admin_id
    WHERE c.id=? AND c.phase=?
    LIMIT 1
  ");
  $stmt->bind_param("is", $selectedComplaintId, $phase);
  $stmt->execute();
  $selectedComplaint = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$selectedComplaint) $selectedComplaintId = 0;
}

/* =========================
   8) MESSAGES
   ========================= */
$messages = [];
if ($selectedComplaintId > 0) {
  $stmt = $conn->prepare("
    SELECT cm.*,
           h.first_name, h.middle_name, h.last_name,
           a.full_name AS admin_name
    FROM complaint_messages cm
    LEFT JOIN homeowners h ON h.id = cm.sender_homeowner_id
    LEFT JOIN admins a ON a.id = cm.sender_admin_id
    WHERE cm.complaint_id=?
    ORDER BY cm.created_at ASC, cm.id ASC
  ");
  $stmt->bind_param("i", $selectedComplaintId);
  $stmt->execute();
  $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>HOA-ADMIN • Complaints</title>

  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

  <style>
    .badge-soft { padding: .35rem .6rem; border-radius: 999px; font-weight: 800; font-size: 12px; }
    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }
    .badge-soft-secondary { background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; }

    .ann-badge {
      font-size: 11px;
      font-weight: 900;
      padding: 3px 8px;
      border-radius: 999px;
      border: 1px solid #e5e7eb;
      background: #f8fafc;
      color: #0f172a;
      display: inline-block;
    }
    .ann-badge.urgent { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
    .ann-badge.important { background:#fffbeb; border-color:#fed7aa; color:#9a3412; }
    .ann-badge.normal { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }

    .complaint-layout{
      display:grid;
      grid-template-columns: 350px 1fr;
      gap:20px;
    }
    .complaint-list-card, .complaint-chat-card, .complaint-info-card{
      background:#fff;
      border-radius:14px;
      box-shadow:0 6px 18px rgba(0,0,0,.06);
      border:1px solid #eef2f7;
    }
    .complaint-item{
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:12px;
      margin-bottom:10px;
      color:#0f172a;
      text-decoration:none;
      display:block;
    }
    .complaint-item.active{
      border-color:#077f46;
      background:#f0fff7;
    }
    .complaint-item:hover{
      text-decoration:none;
      color:#0f172a;
      box-shadow:0 4px 12px rgba(0,0,0,.05);
    }
    .msg-area{
      height:430px;
      overflow:auto;
      background:#f8fafc;
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:15px;
    }
    .msg-row{
      display:flex;
      margin-bottom:12px;
    }
    .msg-row.mine{
      justify-content:flex-end;
    }
    .msg-bubble{
      max-width:76%;
      padding:12px 14px;
      border-radius:16px;
      box-shadow:0 4px 12px rgba(0,0,0,.05);
    }
    .msg-row.mine .msg-bubble{
      background:#077f46;
      color:#fff;
      border-bottom-right-radius:6px;
    }
    .msg-row.theirs .msg-bubble{
      background:#fff;
      border:1px solid #e5e7eb;
      color:#0f172a;
      border-bottom-left-radius:6px;
    }
    .msg-meta{
      font-size:12px;
      opacity:.8;
      margin-top:6px;
    }
    .filter-pills{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }
    .filter-pill{
      padding:7px 12px;
      border-radius:999px;
      border:1px solid #e5e7eb;
      font-weight:800;
      font-size:12px;
      color:#0f172a;
      background:#fff;
      text-decoration:none;
    }
    .filter-pill.active{
      background:#077f46;
      border-color:#077f46;
      color:#fff;
    }
    .mini-label{
      font-size:12px;
      color:#64748b;
      font-weight:700;
    }
    @media (max-width: 991px){
      .complaint-layout{
        grid-template-columns:1fr;
      }
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

<?php include 'sidebar.php'; ?>

  <div class="mobile-menu-overlay"></div>

  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header mb-20">
        <div class="row">
          <div class="col-md-12 col-sm-12">
            <div class="title"><h4>Complaints Management</h4></div>
            <div class="text-secondary">
              Phase: <b><?= esc($phase) ?></b> |
              Logged in as <b><?= esc($adminName !== '' ? $adminName : $adminEmail) ?></b>
            </div>
          </div>
        </div>
      </div>

      <?php if ($ok): ?>
        <div class="alert alert-success"><?= esc($ok) ?></div>
      <?php endif; ?>
      <?php if ($err): ?>
        <div class="alert alert-danger"><?= esc($err) ?></div>
      <?php endif; ?>

      <div class="row mb-20">
        <div class="col-xl-3 col-lg-6 col-md-6 mb-20">
          <div class="card-box pd-20">
            <div class="font-14 text-secondary">All Complaints</div>
            <div class="font-30 weight-700"><?= (int)$counts['all'] ?></div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6 mb-20">
          <div class="card-box pd-20">
            <div class="font-14 text-secondary">Open</div>
            <div class="font-30 weight-700 text-warning"><?= (int)$counts['open'] ?></div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6 mb-20">
          <div class="card-box pd-20">
            <div class="font-14 text-secondary">In Progress</div>
            <div class="font-30 weight-700 text-primary"><?= (int)$counts['in_progress'] ?></div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6 mb-20">
          <div class="card-box pd-20">
            <div class="font-14 text-secondary">Resolved / Closed</div>
            <div class="font-30 weight-700 text-success"><?= (int)($counts['resolved'] + $counts['closed']) ?></div>
          </div>
        </div>
      </div>

      <div class="card-box pd-20 mb-20">
        <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap:10px;">
          <h5 class="mb-0">Complaint Filters</h5>
          <div class="filter-pills">
            <a class="filter-pill <?= $filter==='all' ? 'active' : '' ?>" href="admin_complaints.php?filter=all">All (<?= (int)$counts['all'] ?>)</a>
            <a class="filter-pill <?= $filter==='open' ? 'active' : '' ?>" href="admin_complaints.php?filter=open">Open (<?= (int)$counts['open'] ?>)</a>
            <a class="filter-pill <?= $filter==='in_progress' ? 'active' : '' ?>" href="admin_complaints.php?filter=in_progress">In Progress (<?= (int)$counts['in_progress'] ?>)</a>
            <a class="filter-pill <?= $filter==='resolved' ? 'active' : '' ?>" href="admin_complaints.php?filter=resolved">Resolved (<?= (int)$counts['resolved'] ?>)</a>
            <a class="filter-pill <?= $filter==='closed' ? 'active' : '' ?>" href="admin_complaints.php?filter=closed">Closed (<?= (int)$counts['closed'] ?>)</a>
          </div>
        </div>
      </div>

      <div class="complaint-layout">
        <!-- LEFT LIST -->
        <div class="complaint-list-card pd-20">
          <div class="d-flex justify-content-between align-items-center mb-15">
            <h5 class="mb-0">Complaints</h5>
            <span class="badge-soft badge-soft-info"><?= count($complaints) ?> result(s)</span>
          </div>

          <?php if (empty($complaints)): ?>
            <div class="text-secondary">No complaints found for this filter.</div>
          <?php else: ?>
            <?php foreach ($complaints as $c): ?>
              <?php
                $name = trim((string)($c['first_name'] ?? '') . ' ' . (string)($c['middle_name'] ?? '') . ' ' . (string)($c['last_name'] ?? ''));
                $isActive = ((int)$c['id'] === (int)$selectedComplaintId);
              ?>
              <a class="complaint-item <?= $isActive ? 'active' : '' ?>"
                 href="admin_complaints.php?filter=<?= urlencode($filter) ?>&complaint_id=<?= (int)$c['id'] ?>">
                <div class="d-flex justify-content-between align-items-start" style="gap:8px;">
                  <div>
                    <div class="font-weight-bold"><?= esc($c['subject']) ?></div>
                    <div class="text-secondary font-12"><?= esc($name !== '' ? $name : 'Unknown Homeowner') ?></div>
                    <div class="text-secondary font-12"><?= esc((string)($c['house_lot_number'] ?? '')) ?></div>
                  </div>
                  <div class="text-right">
                    <span class="badge-soft <?= esc(complaintStatusBadgeClass((string)$c['status'])) ?>">
                      <?= esc(strtoupper(str_replace('_',' ', (string)$c['status']))) ?>
                    </span>
                  </div>
                </div>

                <div class="mt-10 d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
                  <span class="<?= esc(complaintPriorityClass((string)$c['priority'])) ?>">
                    <?= esc(strtoupper((string)$c['priority'])) ?>
                  </span>
                  <span class="font-12 text-secondary"><?= esc(date('M d, Y h:i A', strtotime((string)$c['updated_at']))) ?></span>
                </div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- RIGHT -->
        <div>
          <?php if (!$selectedComplaint): ?>
            <div class="complaint-chat-card pd-30 text-center text-secondary">
              Select a complaint from the left panel.
            </div>
          <?php else: ?>
            <?php
              $selName = trim(
                (string)($selectedComplaint['first_name'] ?? '') . ' ' .
                (string)($selectedComplaint['middle_name'] ?? '') . ' ' .
                (string)($selectedComplaint['last_name'] ?? '')
              );
            ?>

            <!-- complaint info -->
            <div class="complaint-info-card pd-20 mb-20">
              <div class="d-flex justify-content-between align-items-start flex-wrap" style="gap:12px;">
                <div>
                  <h4 class="mb-5"><?= esc($selectedComplaint['subject']) ?></h4>
                  <div class="text-secondary">
                    Homeowner: <b><?= esc($selName !== '' ? $selName : 'Unknown') ?></b>
                  </div>
                  <div class="text-secondary">
                    Blk/Lot: <b><?= esc((string)($selectedComplaint['house_lot_number'] ?? '')) ?></b>
                  </div>
                  <div class="text-secondary">
                    Email: <b><?= esc((string)($selectedComplaint['homeowner_email'] ?? '')) ?></b>
                  </div>
                  <div class="text-secondary">
                    Contact: <b><?= esc((string)($selectedComplaint['contact_number'] ?? '')) ?></b>
                  </div>
                  <div class="text-secondary">
                    Assigned Admin: <b><?= esc((string)($selectedComplaint['assigned_admin_name'] ?: ($adminName ?: $adminEmail))) ?></b>
                  </div>
                </div>

                <div class="text-right">
                  <div class="mb-2">
                    <span class="badge-soft <?= esc(complaintStatusBadgeClass((string)$selectedComplaint['status'])) ?>">
                      <?= esc(strtoupper(str_replace('_',' ', (string)$selectedComplaint['status']))) ?>
                    </span>
                  </div>
                  <div>
                    <span class="<?= esc(complaintPriorityClass((string)$selectedComplaint['priority'])) ?>">
                      <?= esc(strtoupper((string)$selectedComplaint['priority'])) ?>
                    </span>
                  </div>
                </div>
              </div>

              <div class="row mt-15">
                <div class="col-md-3 mb-2">
                  <div class="mini-label">Category</div>
                  <div><b><?= esc(ucwords(str_replace('_',' ', (string)$selectedComplaint['category']))) ?></b></div>
                </div>
                <div class="col-md-3 mb-2">
                  <div class="mini-label">Created</div>
                  <div><b><?= esc(date('M d, Y h:i A', strtotime((string)$selectedComplaint['created_at']))) ?></b></div>
                </div>
                <div class="col-md-3 mb-2">
                  <div class="mini-label">Last Updated</div>
                  <div><b><?= esc(date('M d, Y h:i A', strtotime((string)$selectedComplaint['updated_at']))) ?></b></div>
                </div>
                <div class="col-md-3 mb-2">
                  <div class="mini-label">Complaint ID</div>
                  <div><b>#<?= (int)$selectedComplaint['id'] ?></b></div>
                </div>
              </div>

              <div class="mt-15">
                <div class="mini-label mb-1">Initial Complaint</div>
                <div style="white-space:pre-wrap; line-height:1.45;"><?= esc((string)$selectedComplaint['description']) ?></div>
              </div>

              <div class="mt-20">
                <form method="POST" class="form-inline">
                  <input type="hidden" name="update_status_submit" value="1">
                  <input type="hidden" name="complaint_id" value="<?= (int)$selectedComplaint['id'] ?>">

                  <label class="mr-2 font-weight-bold">Update Status:</label>
                  <select name="status" class="form-control mr-2">
                    <option value="open" <?= ((string)$selectedComplaint['status']==='open' ? 'selected' : '') ?>>Open</option>
                    <option value="in_progress" <?= ((string)$selectedComplaint['status']==='in_progress' ? 'selected' : '') ?>>In Progress</option>
                    <option value="resolved" <?= ((string)$selectedComplaint['status']==='resolved' ? 'selected' : '') ?>>Resolved</option>
                    <option value="closed" <?= ((string)$selectedComplaint['status']==='closed' ? 'selected' : '') ?>>Closed</option>
                  </select>
                  <button type="submit" class="btn btn-primary">Save Status</button>
                </form>
              </div>
            </div>

            <!-- chat -->
            <div class="complaint-chat-card pd-20">
              <div class="d-flex justify-content-between align-items-center mb-15">
                <h5 class="mb-0">Conversation</h5>
                <span class="text-secondary font-12"><?= count($messages) ?> message(s)</span>
              </div>

              <div class="msg-area" id="msgArea">
                <?php if (empty($messages)): ?>
                  <div class="text-secondary">No messages yet.</div>
                <?php else: ?>
                  <?php foreach ($messages as $m): ?>
                    <?php
                      $isMine = ((string)$m['sender_type'] === 'admin');
                      $senderName = $isMine
                        ? ((string)($m['admin_name'] ?: ($adminName ?: 'Admin')))
                        : trim((string)($m['first_name'] ?? '') . ' ' . (string)($m['middle_name'] ?? '') . ' ' . (string)($m['last_name'] ?? ''));
                      if ($senderName === '') $senderName = $isMine ? 'Admin' : 'Homeowner';
                    ?>
                    <div class="msg-row <?= $isMine ? 'mine' : 'theirs' ?>">
                      <div class="msg-bubble">
                        <div class="font-weight-bold font-12 mb-1"><?= esc($senderName) ?></div>
                        <div style="white-space:pre-wrap; line-height:1.45;"><?= esc((string)$m['message']) ?></div>
                        <div class="msg-meta"><?= esc(date('M d, Y h:i A', strtotime((string)$m['created_at']))) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <form method="POST" class="mt-15">
                <input type="hidden" name="send_reply_submit" value="1">
                <input type="hidden" name="complaint_id" value="<?= (int)$selectedComplaint['id'] ?>">
                <div class="form-group">
                  <label class="font-weight-bold">Reply to Homeowner</label>
                  <textarea name="message" class="form-control" rows="4" maxlength="3000" placeholder="Type your reply here..." required></textarea>
                </div>
                <button type="submit" class="btn btn-success">
                  <i class="dw dw-paper-plane1"></i> Send Reply
                </button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="footer-wrap pd-20 mb-20 card-box mt-20">
        © Copyright South Meridian Homes All Rights Reserved
      </div>
    </div>
  </div>

  <script src="vendors/scripts/core.js"></script>
  <script src="vendors/scripts/script.min.js"></script>
  <script src="vendors/scripts/process.js"></script>
  <script src="vendors/scripts/layout-settings.js"></script>

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