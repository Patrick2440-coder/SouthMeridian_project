<?php
session_start();

// ===================== DB =====================
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ===================== ADMIN CONTEXT =====================
$admin_id    = $_SESSION['user_id'] ?? null;
$admin_name  = $_SESSION['full_name'] ?? "HOA Admin";
$admin_phase = $_SESSION['phase'] ?? "Superadmin";

if ($admin_id) {
  $stmt = $conn->prepare("SELECT phase FROM admins WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $admin_id);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) {
    if (!empty($row['phase'])) $admin_phase = $row['phase'];
  }
  $stmt->close();
}

$is_superadmin  = ($admin_phase === 'Superadmin');
$allowed_phases = ['Phase 1','Phase 2','Phase 3'];

// ✅ Determine fixed/selectable phase
$ui_phase = 'Phase 1';
if ($is_superadmin) {
  $ui_phase = $_GET['phase'] ?? $_POST['phase_pick'] ?? 'Phase 1';
  if (!in_array($ui_phase, $allowed_phases, true)) $ui_phase = 'Phase 1';
} else {
  $ui_phase = in_array($admin_phase, $allowed_phases, true) ? $admin_phase : 'Phase 1';
}

// ===================== AJAX: approved homeowners by phase =====================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'homeowners') {
  header('Content-Type: application/json; charset=utf-8');

  $phase = $is_superadmin ? ($_GET['phase'] ?? $ui_phase) : $ui_phase;
  if (!in_array($phase, $allowed_phases, true)) $phase = 'Phase 1';

  $stmt = $conn->prepare("
    SELECT id, first_name, middle_name, last_name, email, house_lot_number
    FROM homeowners
    WHERE status='approved' AND phase=?
    ORDER BY last_name ASC, first_name ASC
  ");
  $stmt->bind_param("s", $phase);
  $stmt->execute();
  $r = $stmt->get_result();

  $out = [];
  while($h = $r->fetch_assoc()){
    $full = trim($h['first_name'].' '.($h['middle_name'] ?? '').' '.$h['last_name']);
    $out[] = [
      'id' => (int)$h['id'],
      'name' => $full,
      'email' => $h['email'],
      'lot' => $h['house_lot_number']
    ];
  }
  $stmt->close();

  echo json_encode(['success'=>true,'items'=>$out, 'phase'=>$phase]);
  exit;
}

// ===================== AJAX: active officers by phase =====================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'officers') {
  header('Content-Type: application/json; charset=utf-8');

  $phase = $is_superadmin ? ($_GET['phase'] ?? $ui_phase) : $ui_phase;
  if (!in_array($phase, $allowed_phases, true)) $phase = 'Phase 1';

  $stmt = $conn->prepare("
    SELECT id, position, officer_name, officer_email, is_active
    FROM hoa_officers
    WHERE phase=? AND is_active=1
    ORDER BY FIELD(position,'President','Vice President','Secretary','Treasurer','Auditor','Board of Director')
  ");
  $stmt->bind_param("s", $phase);
  $stmt->execute();
  $r = $stmt->get_result();

  $out = [];
  while($o = $r->fetch_assoc()){
    $out[] = [
      'id' => (int)$o['id'],
      'position' => $o['position'],
      'name' => $o['officer_name'] ?? '',
      'email' => $o['officer_email'] ?? ''
    ];
  }
  $stmt->close();

  echo json_encode(['success'=>true,'items'=>$out, 'phase'=>$phase]);
  exit;
}

// ===================== helper: bulk insert recipients =====================
function insert_homeowner_recipients(mysqli $conn, int $announcement_id, string $phase): void {
  $sel = $conn->prepare("
    SELECT id, first_name, middle_name, last_name, email
    FROM homeowners
    WHERE status='approved' AND phase=?
  ");
  $sel->bind_param("s", $phase);
  $sel->execute();
  $res = $sel->get_result();

  $ins = $conn->prepare("
    INSERT INTO announcement_recipients (announcement_id, recipient_type, homeowner_id, recipient_name, recipient_email)
    VALUES (?, 'homeowner', ?, ?, ?)
  ");

  while ($h = $res->fetch_assoc()) {
    $hid = (int)$h['id'];
    $full = trim($h['first_name'].' '.($h['middle_name'] ?? '').' '.$h['last_name']);
    $email = $h['email'] ?? '';
    $ins->bind_param("iiss", $announcement_id, $hid, $full, $email);
    $ins->execute();
  }

  $ins->close();
  $sel->close();
}

function insert_officer_recipients(mysqli $conn, int $announcement_id, string $phase): void {
  $sel = $conn->prepare("
    SELECT id, officer_name, officer_email
    FROM hoa_officers
    WHERE phase=? AND is_active=1
  ");
  $sel->bind_param("s", $phase);
  $sel->execute();
  $res = $sel->get_result();

  $ins = $conn->prepare("
    INSERT INTO announcement_recipients (announcement_id, recipient_type, officer_id, recipient_name, recipient_email)
    VALUES (?, 'officer', ?, ?, ?)
  ");

  while ($o = $res->fetch_assoc()) {
    $oid = (int)$o['id'];
    $nm = $o['officer_name'] ?? '';
    $em = $o['officer_email'] ?? '';
    $ins->bind_param("iiss", $announcement_id, $oid, $nm, $em);
    $ins->execute();
  }

  $ins->close();
  $sel->close();
}

// ===================== FILE UPLOAD HELPERS =====================
function ensure_dir(string $path): bool {
  if (is_dir($path)) return true;
  return @mkdir($path, 0775, true);
}

function sanitize_filename(string $name): string {
  $name = basename($name);
  $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
  return trim($name, '_');
}

function guess_mime(string $tmpPath): string {
  if (function_exists('finfo_open')) {
    $f = finfo_open(FILEINFO_MIME_TYPE);
    if ($f) {
      $m = finfo_file($f, $tmpPath);
      finfo_close($f);
      if ($m) return $m;
    }
  }
  return 'application/octet-stream';
}

// ===================== SAVE ANNOUNCEMENT =====================
$save_ok = null;
$save_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_announcement'])) {
  $title     = trim($_POST['title'] ?? '');
  $category  = $_POST['category'] ?? '';
  $audience  = $_POST['audience'] ?? '';
  $message   = trim($_POST['message'] ?? '');
  $startDate = $_POST['startDate'] ?? '';
  $endDate   = $_POST['endDate'] ?? null;
  $priority  = $_POST['priority'] ?? 'normal';

  // ✅ phase is STATIC to logged-in admin; only superadmin can pick
  $phase_for_announcement = $ui_phase;

  $audience_value = null;
  if ($audience === 'block') {
    $audience_value = trim($_POST['block_value'] ?? '');
  }

  $valid_categories = ['general','maintenance','meeting','emergency'];
  $valid_audience   = ['all','block','selected','selected_officer','all_officers'];
  $valid_priority   = ['normal','important','urgent'];

  if ($title === '' || $message === '') $save_err = "Title and message are required.";
  else if (!in_array($category, $valid_categories, true)) $save_err = "Invalid category.";
  else if (!in_array($audience, $valid_audience, true)) $save_err = "Invalid audience.";
  else if (!in_array($priority, $valid_priority, true)) $save_err = "Invalid priority.";
  else if (!$startDate) $save_err = "Start date is required.";

  $selected_homeowners = $_POST['selected_homeowners'] ?? [];
  $selected_officers   = $_POST['selected_officers'] ?? [];

  if ($save_err === '') {
    if ($audience === 'selected' && (!is_array($selected_homeowners) || count($selected_homeowners) === 0)) {
      $save_err = "Please select at least 1 homeowner.";
    }
    if ($audience === 'selected_officer' && (!is_array($selected_officers) || count($selected_officers) === 0)) {
      $save_err = "Please select at least 1 officer.";
    }
  }

  if ($save_err === '') {
    $endDate = ($endDate === '') ? null : $endDate;

    // ✅ insert announcement
    $stmt = $conn->prepare("
      INSERT INTO announcements (admin_id, phase, title, category, audience, audience_value, message, start_date, end_date, priority)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
      "isssssssss",
      $admin_id,
      $phase_for_announcement,
      $title,
      $category,
      $audience,
      $audience_value,
      $message,
      $startDate,
      $endDate,
      $priority
    );

    $ok = $stmt->execute();
    $announcement_id = $stmt->insert_id;
    $stmt->close();

    if (!$ok) {
      $save_ok = false;
      $save_err = "Failed to save announcement.";
    } else {

      // ✅ recipients
      if ($audience === 'selected') {
        $ins = $conn->prepare("
          INSERT INTO announcement_recipients (announcement_id, recipient_type, homeowner_id, recipient_name, recipient_email)
          VALUES (?, 'homeowner', ?, ?, ?)
        ");

        foreach ($selected_homeowners as $hid) {
          $hid = (int)$hid;
          $q = $conn->prepare("SELECT first_name,middle_name,last_name,email FROM homeowners WHERE id=? LIMIT 1");
          $q->bind_param("i", $hid);
          $q->execute();
          $rr = $q->get_result();
          if ($h = $rr->fetch_assoc()) {
            $full = trim($h['first_name'].' '.($h['middle_name'] ?? '').' '.$h['last_name']);
            $email = $h['email'] ?? '';
            $ins->bind_param("iiss", $announcement_id, $hid, $full, $email);
            $ins->execute();
          }
          $q->close();
        }
        $ins->close();
      }

      if ($audience === 'selected_officer') {
        $ins = $conn->prepare("
          INSERT INTO announcement_recipients (announcement_id, recipient_type, officer_id, recipient_name, recipient_email)
          VALUES (?, 'officer', ?, ?, ?)
        ");
        foreach ($selected_officers as $oid) {
          $oid = (int)$oid;
          $q = $conn->prepare("SELECT officer_name, officer_email FROM hoa_officers WHERE id=? LIMIT 1");
          $q->bind_param("i", $oid);
          $q->execute();
          $rr = $q->get_result();
          if ($o = $rr->fetch_assoc()) {
            $nm = $o['officer_name'] ?? '';
            $em = $o['officer_email'] ?? '';
            $ins->bind_param("iiss", $announcement_id, $oid, $nm, $em);
            $ins->execute();
          }
          $q->close();
        }
        $ins->close();
      }

      if ($audience === 'all') {
        insert_homeowner_recipients($conn, $announcement_id, $phase_for_announcement);
      }

      if ($audience === 'all_officers') {
        insert_officer_recipients($conn, $announcement_id, $phase_for_announcement);
      }

      // ✅ attachments (multiple)
      if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        $upload_dir = __DIR__ . "/uploads/announcements";
        if (!ensure_dir($upload_dir)) {
          $save_ok = false;
          $save_err = "Announcement saved, but failed to create upload folder.";
        } else {
          $max_size = 10 * 1024 * 1024; // 10MB per file
          $allowed_ext = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','ppt','pptx','txt','zip','rar'];

          $insA = $conn->prepare("
            INSERT INTO announcement_attachments (announcement_id, original_name, stored_name, file_path, mime_type, file_size)
            VALUES (?, ?, ?, ?, ?, ?)
          ");

          $count = count($_FILES['attachments']['name']);
          for ($i=0; $i<$count; $i++) {
            $orig = $_FILES['attachments']['name'][$i] ?? '';
            $tmp  = $_FILES['attachments']['tmp_name'][$i] ?? '';
            $err  = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            $size = (int)($_FILES['attachments']['size'][$i] ?? 0);

            if ($err === UPLOAD_ERR_NO_FILE || $orig === '') continue;
            if ($err !== UPLOAD_ERR_OK) continue;
            if ($size <= 0 || $size > $max_size) continue;

            $safeOrig = sanitize_filename($orig);
            $ext = strtolower(pathinfo($safeOrig, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext, true)) continue;

            $stored = time() . "_" . bin2hex(random_bytes(6)) . "_" . $safeOrig;
            $dest = $upload_dir . "/" . $stored;

            if (@move_uploaded_file($tmp, $dest)) {
              $mime = guess_mime($dest);
              $relPath = "uploads/announcements/" . $stored;

              $insA->bind_param("issssi", $announcement_id, $safeOrig, $stored, $relPath, $mime, $size);
              $insA->execute();
            }
          }
          $insA->close();
        }
      }
      // ======================================================
// ✅ SEND ANNOUNCEMENT EMAILS (PHPMailer)
// ======================================================
require_once __DIR__ . "/send_announcement_mail.php";

$smtp = [
  'host'       => 'smtp.gmail.com',
  'username'   => 'baculpopatrick2440@gmail.com',
  'password'   => 'vxsx lmtv livx hgtl', // Gmail App Password
  'port'       => 587,
  'encryption' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
  'from_email' => 'baculpopatrick2440@gmail.com',
  'from_name'  => 'South Meridian HOA'
];

// send emails to ALL recipients already saved in DB
$mailRes = send_announcement_mail(
  $conn,
  (int)$announcement_id,
  $smtp
);

// do NOT rollback announcement if email fails
if (!$mailRes['success']) {
  $save_ok = false;
  $save_err = "Announcement saved, but email sending failed: "
            . implode(" | ", $mailRes['errors']);
}


      if ($save_ok !== false) $save_ok = true;
    }
  } else {
    $save_ok = false;
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>HOA-ADMIN | Announcements</title>

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
    .picker-box { display:none; border:1px solid #e5e7eb; border-radius:10px; padding:12px; background:#fff; }
    .picker-list { max-height:260px; overflow:auto; border:1px solid #e5e7eb; border-radius:10px; padding:10px; }
    .picker-item { display:flex; gap:10px; align-items:flex-start; padding:8px 6px; border-bottom:1px solid #f1f5f9; }
    .picker-item:last-child{ border-bottom:none; }
    .small-muted { font-size:12px; color:#64748b; }
    .searchbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .searchbar .form-control { height: 38px; }
  </style>
</head>
<body>

  <!-- ================= HEADER ================= -->
  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
      <div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>
    </div>

    <div class="header-right">
      <div class="user-notification">
        <div class="dropdown">
          <a class="dropdown-toggle no-arrow" href="#" role="button" data-toggle="dropdown">
            <i class="icon-copy dw dw-notification"></i>
            <span class="badge notification-active"></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right">
            <div class="notification-list mx-h-350 customscroll">
              <ul>
                <li><a href="#"><img src="vendors/images/img.jpg" alt=""><h3>System</h3><p>Announcement module ready.</p></a></li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="user-info-dropdown">
        <div class="dropdown">
          <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
            <span class="user-icon"><img src="vendors/images/photo1.jpg" alt=""></span>
            <span class="user-name"><?= esc($admin_name) ?></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="profile.html"><i class="dw dw-user1"></i> Profile</a>
            <a class="dropdown-item" href="profile.html"><i class="dw dw-settings2"></i> Setting</a>
            <a class="dropdown-item" href="index.php"><i class="dw dw-logout"></i> Log Out</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ================= RIGHT SIDEBAR ================= -->
  <div class="right-sidebar">
    <div class="sidebar-title">
      <h3 class="weight-600 font-16 text-blue">
        Layout Settings
        <span class="btn-block font-weight-400 font-12">User Interface Settings</span>
      </h3>
      <div class="close-sidebar" data-toggle="right-sidebar-close">
        <i class="icon-copy ion-close-round"></i>
      </div>
    </div>
    <div class="right-sidebar-body customscroll">
      <div class="right-sidebar-body-content">
        <div class="reset-options pt-30 text-center">
          <button class="btn btn-danger" id="reset-settings">Reset Settings</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ================= LEFT SIDEBAR ================= -->
  <div class="left-side-bar" style="background-color: #077f46;">
    <div class="brand-logo">
      <a href="dashboard.php">
        <img src="vendors/images/deskapp-logo.svg" alt="" class="dark-logo">
        <img src="vendors/images/deskapp-logo-white.svg" alt="" class="light-logo">
      </a>
      <div class="close-sidebar" data-toggle="left-sidebar-close">
        <i class="ion-close-round"></i>
      </div>
    </div>

    <div class="menu-block customscroll">
      <div class="sidebar-menu">
        <ul id="accordion-menu">
          <li>
            <a href="dashboard.php" class="dropdown-toggle no-arrow">
              <span class="micon dw dw-house-1"></span>
              <span class="mtext">Dashboard</span>
            </a>
          </li>

          <li>
            <a href="HO-management.php" class="dropdown-toggle no-arrow">
              <span class="micon dw dw-user1"></span>
              <span class="mtext">Homeowner Management</span>
            </a>
          </li>

          <li>
            <a href="users-management.php" class="dropdown-toggle no-arrow">
              <span class="micon dw dw-user"></span>
              <span class="mtext">User Management</span>
            </a>
          </li>

          <li>
            <a href="announcements.php" class="dropdown-toggle no-arrow active">
              <span class="micon dw dw-megaphone"></span>
              <span class="mtext">Announcement</span>
            </a>
          </li>
<!-- FINANCE (Dropdown) -->
<li class="dropdown">
  <a href="javascript:;" class="dropdown-toggle">
    <span class="micon dw dw-money-1"></span>
    <span class="mtext">Finance</span>
  </a>
  <ul class="submenu">
    <li><a href="finance.php">Overview</a></li>
    <li><a href="finance_dues.php">Monthly Dues</a></li>
    <li><a href="finance_donations.php">Donations</a></li>
    <li><a href="finance_expenses.php">Expenses</a></li>
    <li><a href="finance_reports.php">Financial Reports</a></li>
    <li><a href="finance_cashflow.php">Cash Flow Dashboard</a></li>
  </ul>
</li>
            <a href="#" class="dropdown-toggle no-arrow">
              <span class="micon dw dw-settings2"></span>
              <span class="mtext">Settings</span>
            </a>
          </li>
        </ul>
      </div>
    </div>
  </div>

  <div class="mobile-menu-overlay"></div>

  <!-- ================= MAIN ================= -->
  <div class="main-container">
    <div class="pd-ltr-20">

      <?php if ($save_ok === true): ?>
        <div class="alert alert-success">Announcement saved successfully. (Recipients + attachments saved too)</div>
      <?php elseif ($save_ok === false): ?>
        <div class="alert alert-danger"><?= esc($save_err) ?></div>
      <?php endif; ?>

      <div class="container mt-4">
        <div class="card shadow-sm border-0">
          <div class="col-xl-12 mb-30">
            <div class="card-box height-100-p pd-20">

              <div class="card-header text-white">
                <h5 class="mb-0 d-flex align-items-center">
                  <i class="dw dw-megaphone me-2"></i>
                  Create Announcement
                </h5>
              </div>

              <div class="card-body">
                <!-- ✅ enctype needed for file upload -->
                <form id="announcementForm" method="POST" enctype="multipart/form-data">
                  <input type="hidden" name="save_announcement" value="1">

                  <!-- ✅ STATIC PHASE -->
                  <div class="mb-3">
                    <label class="form-label fw-semibold">Phase</label>

                    <?php if ($is_superadmin): ?>
                      <select id="phase_pick" name="phase_pick" class="form-control">
                        <?php foreach($allowed_phases as $p): ?>
                          <option value="<?= esc($p) ?>" <?= ($ui_phase === $p ? 'selected' : '') ?>><?= esc($p) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <div class="small-muted mt-1">Superadmin can choose phase.</div>
                    <?php else: ?>
                      <input type="hidden" id="phase_pick" name="phase_pick" value="<?= esc($ui_phase) ?>">
                      <input type="text" class="form-control" value="<?= esc($ui_phase) ?>" readonly>

                    <?php endif; ?>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-semibold">Announcement Title</label>
                    <input type="text" name="title" class="form-control" placeholder="Enter announcement title" required>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-semibold">Category</label>
                    <select name="category" class="form-control" required>
                      <option value="">Select category</option>
                      <option value="general">General Notice</option>
                      <option value="maintenance">Maintenance</option>
                      <option value="meeting">Meeting</option>
                      <option value="emergency">Emergency</option>
                    </select>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-semibold">Target Audience</label>
                    <select id="audience" name="audience" class="form-control" required>
                      <option value="">Select audience</option>
                      <option value="all">All Homeowners</option>
                      <option value="selected">Selected Homeowners</option>
                      <option value="all_officers">All Officers</option>
                      <option value="selected_officer">Selected Officers</option>
                    </select>
                  </div>

                  <div id="blockBox" class="mb-3 picker-box">
                    <label class="form-label fw-semibold">Block</label>
                    <input type="text" class="form-control" name="block_value" placeholder="e.g. Block 7">
                    <div class="small-muted mt-1">Note: block filtering is not implemented in DB yet.</div>
                  </div>

                  <div id="selectedHomeownersBox" class="mb-3 picker-box">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                      <label class="form-label fw-semibold mb-0">Select Approved Homeowners</label>
                      <div class="searchbar">
                        <input type="text" id="homeownerSearch" class="form-control" style="width:240px;" placeholder="Search name/email...">
                        <button type="button" id="selectAllHomeowners" class="btn btn-sm btn-outline-primary">Select All</button>
                        <button type="button" id="clearHomeowners" class="btn btn-sm btn-outline-secondary">Clear</button>
                      </div>
                    </div>
                    <div class="picker-list" id="homeownersList"></div>
                  </div>

                  <div id="selectedOfficersBox" class="mb-3 picker-box">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                      <label class="form-label fw-semibold mb-0">Select Active Officers</label>
                      <div class="searchbar">
                        <input type="text" id="officerSearch" class="form-control" style="width:240px;" placeholder="Search name/email...">
                        <button type="button" id="selectAllOfficers" class="btn btn-sm btn-outline-primary">Select All</button>
                        <button type="button" id="clearOfficers" class="btn btn-sm btn-outline-secondary">Clear</button>
                      </div>
                    </div>
                    <div class="picker-list" id="officersList"></div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-semibold">Announcement Message</label>
                    <textarea name="message" class="form-control" rows="5" placeholder="Write the announcement details here..." required></textarea>
                  </div>

                  <!-- ✅ NEW: Attachments -->
                  <div class="mb-3">
                    <label class="form-label fw-semibold">Attachments (files / pictures)</label>
                    <input type="file" name="attachments[]" class="form-control" multiple
                      accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar">
                    <div class="small-muted mt-1">
                      You can upload multiple files. Max 10MB each (you can change this in PHP code).
                    </div>
                  </div>

                  <div class="row">
                    <div class="col-md-6 mb-3">
                      <label class="form-label fw-semibold">Start Date</label>
                      <input type="date" name="startDate" class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
                      <label class="form-label fw-semibold">End Date</label>
                      <input type="date" name="endDate" class="form-control">
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-semibold">Priority Level</label>
                    <div class="d-flex gap-4">
                      <div class="form-check">
                        <input class="form-check-input" type="radio" name="priority" value="normal" checked>
                        <label class="form-check-label">Normal</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="radio" name="priority" value="important">
                        <label class="form-check-label text-warning fw-semibold">Important</label>
                      </div>
                      <div class="form-check">
                        <input class="form-check-input" type="radio" name="priority" value="urgent">
                        <label class="form-check-label text-danger fw-semibold">Urgent</label>
                      </div>
                    </div>
                  </div>

                  <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">
                      <i class="dw dw-paper-plane me-1"></i>
                      Publish Announcement
                    </button>
                  </div>

                </form>
              </div>


            </div>
          </div>
        </div>
      </div>

      <br>
      <div class="footer-wrap pd-20 mb-20 card-box">
        © Copyright South Meridian Homes All Rights Reserved
      </div>

    </div>
  </div>

  <!-- ================= JS ================= -->
  <script src="vendors/scripts/core.js"></script>
  <script src="vendors/scripts/script.min.js"></script>
  <script src="vendors/scripts/process.js"></script>
  <script src="vendors/scripts/layout-settings.js"></script>

  <script>
    const audience = document.getElementById('audience');
    const phasePick = document.getElementById('phase_pick');

    const blockBox = document.getElementById('blockBox');
    const selectedHomeownersBox = document.getElementById('selectedHomeownersBox');
    const selectedOfficersBox = document.getElementById('selectedOfficersBox');

    const homeownersList = document.getElementById('homeownersList');
    const officersList = document.getElementById('officersList');

    const homeownerSearch = document.getElementById('homeownerSearch');
    const officerSearch = document.getElementById('officerSearch');

    function showBox(el, on) { el.style.display = on ? 'block' : 'none'; }

    function updateAudienceUI() {
      const v = audience.value;

      showBox(blockBox, v === 'block');
      showBox(selectedHomeownersBox, v === 'selected');
      showBox(selectedOfficersBox, v === 'selected_officer');

      if (v === 'selected') loadHomeowners();
      if (v === 'selected_officer') loadOfficers();
    }

    async function loadHomeowners() {
      homeownersList.innerHTML = '<div class="small-muted">Loading approved homeowners...</div>';
      const phase = phasePick.value;

      const res = await fetch(`announcements.php?ajax=homeowners&phase=${encodeURIComponent(phase)}`);
      const data = await res.json();

      if (!data.success) { homeownersList.innerHTML = '<div class="small-muted">Failed to load homeowners.</div>'; return; }
      if (data.items.length === 0) { homeownersList.innerHTML = '<div class="small-muted">No approved homeowners found for this phase.</div>'; return; }

      homeownersList.innerHTML = data.items.map(h => `
        <label class="picker-item">
          <input type="checkbox" name="selected_homeowners[]" value="${h.id}">
          <div>
            <div><b>${escapeHtml(h.name)}</b> <span class="small-muted">(${escapeHtml(h.lot || 'No lot')})</span></div>
            <div class="small-muted">${escapeHtml(h.email)}</div>
          </div>
        </label>
      `).join('');
    }

    async function loadOfficers() {
      officersList.innerHTML = '<div class="small-muted">Loading active officers...</div>';
      const phase = phasePick.value;

      const res = await fetch(`announcements.php?ajax=officers&phase=${encodeURIComponent(phase)}`);
      const data = await res.json();

      if (!data.success) { officersList.innerHTML = '<div class="small-muted">Failed to load officers.</div>'; return; }
      if (data.items.length === 0) { officersList.innerHTML = '<div class="small-muted">No active officers found for this phase.</div>'; return; }

      officersList.innerHTML = data.items.map(o => `
        <label class="picker-item">
          <input type="checkbox" name="selected_officers[]" value="${o.id}">
          <div>
            <div><b>${escapeHtml(o.position)}:</b> ${escapeHtml(o.name || 'N/A')}</div>
            <div class="small-muted">${escapeHtml(o.email || 'No email')}</div>
          </div>
        </label>
      `).join('');
    }

    function filterList(listEl, query) {
      const q = query.trim().toLowerCase();
      listEl.querySelectorAll('.picker-item').forEach(it => {
        const text = it.innerText.toLowerCase();
        it.style.display = text.includes(q) ? 'flex' : 'none';
      });
    }

    homeownerSearch?.addEventListener('input', () => filterList(homeownersList, homeownerSearch.value));
    officerSearch?.addEventListener('input', () => filterList(officersList, officerSearch.value));

    document.getElementById('selectAllHomeowners')?.addEventListener('click', () => {
      homeownersList.querySelectorAll('input[type="checkbox"]').forEach(c => c.checked = true);
    });
    document.getElementById('clearHomeowners')?.addEventListener('click', () => {
      homeownersList.querySelectorAll('input[type="checkbox"]').forEach(c => c.checked = false);
    });

    document.getElementById('selectAllOfficers')?.addEventListener('click', () => {
      officersList.querySelectorAll('input[type="checkbox"]').forEach(c => c.checked = true);
    });
    document.getElementById('clearOfficers')?.addEventListener('click', () => {
      officersList.querySelectorAll('input[type="checkbox"]').forEach(c => c.checked = false);
    });

    phasePick?.addEventListener('change', () => {
      if (audience.value === 'selected') loadHomeowners();
      if (audience.value === 'selected_officer') loadOfficers();
    });

    audience.addEventListener('change', updateAudienceUI);

    function escapeHtml(str){
      return String(str ?? '')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'","&#039;");
    }

    updateAudienceUI();
  </script>
</body>
</html>
