<?php
session_start();

// OPTIONAL guard
// if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') { header("Location: authentication-login.html"); exit; }

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$admin_id = (int)($_SESSION['user_id'] ?? 1); // adjust if your superadmin session key is different

$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title    = trim($_POST['title'] ?? '');
  $category = $_POST['category'] ?? 'general';
  $priority = $_POST['priority'] ?? 'normal';
  $target   = $_POST['target_phase'] ?? 'ALL';
  $message  = trim($_POST['message'] ?? '');
  $start    = $_POST['start_date'] ?? date('Y-m-d');
  $end      = trim($_POST['end_date'] ?? '');

  $allowedCat = ['general','maintenance','meeting','emergency'];
  $allowedPri = ['normal','important','urgent'];
  $allowedTarget = ['ALL','Phase 1','Phase 2','Phase 3'];

  if ($title === '' || $message === '') {
    $err = "Title and message are required.";
  } elseif (!in_array($category, $allowedCat, true)) {
    $err = "Invalid category.";
  } elseif (!in_array($priority, $allowedPri, true)) {
    $err = "Invalid priority.";
  } elseif (!in_array($target, $allowedTarget, true)) {
    $err = "Invalid target phase.";
  } else {
    // You currently store all superadmin announcements as phase='Superadmin'
    $phase = 'Superadmin';
    $audience = 'all';
    $audience_value = null;
    $endOrNull = ($end === '') ? null : $end;

    $stmt = $conn->prepare("
      INSERT INTO announcements (admin_id, phase, title, category, audience, audience_value, message, start_date, end_date, priority)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
      "isssssssss",
      $admin_id,
      $phase,
      $title,
      $category,
      $audience,
      $audience_value,
      $message,
      $start,
      $endOrNull,
      $priority
    );

    if ($stmt->execute()) {
      $ok = "Announcement posted successfully!";
    } else {
      $err = "Failed to save announcement: " . $stmt->error;
    }
    $stmt->close();
  }
}

// List latest posted announcements
$rows = [];
$stmt = $conn->prepare("
  SELECT id, phase, title, category, priority, start_date, end_date, created_at
  FROM announcements
  WHERE admin_id=?
  ORDER BY created_at DESC
  LIMIT 20
");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Superadmin - Announcements</title>

  <link rel="apple-touch-icon" sizes="180x180" href="../admin/vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="../admin/vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="../admin/vendors/images/favicon-16x16.png">

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/style.css">

  <style>
    .summary-card {
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      padding: 16px;
      background: #fff;
      height: 100%;
    }

    .summary-number {
      font-size: 28px;
      font-weight: 800;
      color: #077f46;
      line-height: 1;
    }

    .summary-label {
      font-size: 13px;
      color: #64748b;
      margin-top: 6px;
      font-weight: 600;
    }

    .badge-soft {
      padding: .35rem .6rem;
      border-radius: 999px;
      font-weight: 800;
      font-size: 12px;
      display: inline-block;
    }

    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }
    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-danger  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .badge-soft-secondary { background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; }

    .mini-note {
      color: #64748b;
      font-size: 13px;
    }

    textarea.form-control {
      min-height: 160px;
      resize: vertical;
    }
  </style>
</head>

<body>
  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
    </div>

    <div class="header-right">
      <div class="user-info-dropdown">
        <div class="dropdown">
          <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
            <span class="user-icon">
              <img src="../admin/vendors/images/photo1.jpg" alt="">
            </span>
            <span class="user-name">Superadmin</span>
          </a>
          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="profile.html"><i class="dw dw-user1"></i> Profile</a>
            <a class="dropdown-item" href="logs.html"><i class="dw dw-list3"></i> Activity Logs</a>
            <a class="dropdown-item" href="../index.php"><i class="dw dw-logout"></i> Log Out</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php include 'superadmin_sidebar.php'; ?>

  <div class="mobile-menu-overlay"></div>

  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header mb-20">
        <div class="row">
          <div class="col-md-12 col-sm-12">
            <div class="title"><h4>Announcements</h4></div>
            <div class="text-secondary">
              Create and manage superadmin announcements for all phase dashboards.
            </div>
          </div>
        </div>
      </div>

      <!-- TOP ROW -->
      <div class="row">
        <div class="col-lg-8 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p mb-20">
            <div class="row align-items-center">
              <div class="col-md-4">
                <img src="../admin/vendors/images/banner-img.png" alt="">
              </div>
              <div class="col-md-8">
                <h4 class="font-20 weight-500 mb-10 text-capitalize">
                  <div class="weight-600 font-30 text-blue">Announcement Center</div>
                </h4>
                <p class="font-18 max-width-600">
                  Publish important updates, maintenance notices, meetings, and emergency alerts visible across the community system.
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-4 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p">
            <div class="d-flex justify-content-between align-items-center mb-10">
              <h4 class="h5 mb-0">Quick Summary</h4>
            </div>

            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">Latest Posts Loaded</span>
              <span class="badge-soft badge-soft-info"><?= count($rows) ?></span>
            </div>

            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">Posting Scope</span>
              <span class="badge-soft badge-soft-success">Superadmin</span>
            </div>

            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">Recommended Target</span>
              <span class="badge-soft badge-soft-warning">All Phases</span>
            </div>

            <div class="mt-3 mini-note">
              Announcements posted here are intended for broad visibility across dashboards.
            </div>
          </div>
        </div>
      </div>

      <?php if ($err): ?>
        <div class="alert alert-danger"><?= esc($err) ?></div>
      <?php endif; ?>

      <?php if ($ok): ?>
        <div class="alert alert-success"><?= esc($ok) ?></div>
      <?php endif; ?>

      <!-- POST FORM -->
      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
          <div>
            <h5 class="mb-1">Post Announcement</h5>
            <div class="mini-note">Create a new announcement and publish it to the dashboard.</div>
          </div>
        </div>

        <form method="POST">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Title</label>
              <input
                type="text"
                name="title"
                class="form-control"
                required
                maxlength="255"
                value="<?= esc($_POST['title'] ?? '') ?>"
              >
            </div>

            <div class="col-md-3 mb-3">
              <label class="form-label">Category</label>
              <select name="category" class="form-control">
                <option value="general" <?= (($_POST['category'] ?? '') === 'general') ? 'selected' : '' ?>>General</option>
                <option value="maintenance" <?= (($_POST['category'] ?? '') === 'maintenance') ? 'selected' : '' ?>>Maintenance</option>
                <option value="meeting" <?= (($_POST['category'] ?? '') === 'meeting') ? 'selected' : '' ?>>Meeting</option>
                <option value="emergency" <?= (($_POST['category'] ?? '') === 'emergency') ? 'selected' : '' ?>>Emergency</option>
              </select>
            </div>

            <div class="col-md-3 mb-3">
              <label class="form-label">Priority</label>
              <select name="priority" class="form-control">
                <option value="normal" <?= (($_POST['priority'] ?? '') === 'normal') ? 'selected' : '' ?>>Normal</option>
                <option value="important" <?= (($_POST['priority'] ?? '') === 'important') ? 'selected' : '' ?>>Important</option>
                <option value="urgent" <?= (($_POST['priority'] ?? '') === 'urgent') ? 'selected' : '' ?>>Urgent</option>
              </select>
            </div>

            <div class="col-md-4 mb-3">
              <label class="form-label">Target</label>
              <select name="target_phase" class="form-control">
                <option value="ALL" <?= (($_POST['target_phase'] ?? 'ALL') === 'ALL') ? 'selected' : '' ?>>All Phases (Recommended)</option>
                <option value="Phase 1" <?= (($_POST['target_phase'] ?? '') === 'Phase 1') ? 'selected' : '' ?>>Phase 1 only</option>
                <option value="Phase 2" <?= (($_POST['target_phase'] ?? '') === 'Phase 2') ? 'selected' : '' ?>>Phase 2 only</option>
                <option value="Phase 3" <?= (($_POST['target_phase'] ?? '') === 'Phase 3') ? 'selected' : '' ?>>Phase 3 only</option>
              </select>
            </div>

            <div class="col-md-4 mb-3">
              <label class="form-label">Start Date</label>
              <input
                type="date"
                name="start_date"
                class="form-control"
                value="<?= esc($_POST['start_date'] ?? date('Y-m-d')) ?>"
                required
              >
            </div>

            <div class="col-md-4 mb-3">
              <label class="form-label">End Date (optional)</label>
              <input
                type="date"
                name="end_date"
                class="form-control"
                value="<?= esc($_POST['end_date'] ?? '') ?>"
              >
            </div>

            <div class="col-12 mb-3">
              <label class="form-label">Message</label>
              <textarea name="message" class="form-control" rows="6" required><?= esc($_POST['message'] ?? '') ?></textarea>
            </div>

            <div class="col-12">
              <button class="btn btn-primary">
                <i class="dw dw-paper-plane1"></i> Publish Announcement
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- LATEST POSTS -->
      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Your Latest Posts</h5>
          <span class="text-secondary">Showing last 20 records</span>
        </div>

        <div class="table-responsive">
          <table class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Phase</th>
                <th>Title</th>
                <th>Category</th>
                <th>Priority</th>
                <th>Dates</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr>
                  <td colspan="7" class="text-center text-secondary">No announcements yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($rows as $r): ?>
                  <?php
                    $priorityClass = 'badge-soft-secondary';
                    if ($r['priority'] === 'urgent') $priorityClass = 'badge-soft-danger';
                    elseif ($r['priority'] === 'important') $priorityClass = 'badge-soft-warning';
                    elseif ($r['priority'] === 'normal') $priorityClass = 'badge-soft-info';
                  ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= esc($r['phase']) ?></td>
                    <td><?= esc($r['title']) ?></td>
                    <td><?= esc(ucfirst($r['category'])) ?></td>
                    <td><span class="badge-soft <?= esc($priorityClass) ?>"><?= esc(ucfirst($r['priority'])) ?></span></td>
                    <td>
                      <?= esc($r['start_date']) ?>
                      <?php if (!empty($r['end_date'])): ?>
                        → <?= esc($r['end_date']) ?>
                      <?php endif; ?>
                    </td>
                    <td><?= esc($r['created_at']) ?></td>
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

  <script src="../admin/vendors/scripts/core.js"></script>
  <script src="../admin/vendors/scripts/script.min.js"></script>
  <script src="../admin/vendors/scripts/process.js"></script>
  <script src="../admin/vendors/scripts/layout-settings.js"></script>
</body>
</html>