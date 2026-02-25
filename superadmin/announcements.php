<?php
session_start();

// OPTIONAL guard
// if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') { header("Location: authentication-login.html"); exit; }

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
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
    // If target is ALL phases -> store as phase='Superadmin' (visible to all phases)
    $phase = 'Superadmin';

    // Keep audience as 'all' for dashboard visibility
    $audience = 'all';
    $audience_value = null;

    // end_date: allow null
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

// List latest posted announcements (superadmin & phase-specific made by superadmin)
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
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Super Admin - Announcements</title>
  <link rel="shortcut icon" type="image/png" href="../assets/images/logos/favicon.png" />
  <link rel="stylesheet" href="../superadmin/assets/css/styles.min.css" />
</head>
<body>
  <div class="page-wrapper" id="main-wrapper" data-layout="vertical" data-navbarbg="skin6" data-sidebartype="full"
    data-sidebar-position="fixed" data-header-position="fixed">

    <!-- Topstrip -->
    <div class="app-topstrip py-6 px-3 w-100 d-lg-flex align-items-center justify-content-between" style="background-color: #077f46;">
      <div class="d-flex align-items-center justify-content-center gap-5 mb-2 mb-lg-0">
        <a class="d-flex justify-content-center" href="./dashboard.php">
          <img src="assets/images/logos/logo-wrappixel.svg" alt="" width="150">
        </a>
      </div>
    </div>

  <!-- Sidebar Start -->
  <aside class="left-sidebar">
    <div>
      <div class="brand-logo d-flex align-items-center justify-content-between">
        <a href="./dashboard.html" class="text-nowrap logo-img">
          <img src="assets/images/logos/logo.svg" alt="" />
        </a>
        <div class="close-btn d-xl-none d-block sidebartoggler cursor-pointer" id="sidebarCollapse">
          <i class="ti ti-x fs-6"></i>
        </div>
      </div>

      <nav class="sidebar-nav scroll-sidebar" data-simplebar="">
        <ul id="sidebarnav">
          <li class="nav-small-cap">
            <iconify-icon icon="solar:menu-dots-linear" class="nav-small-cap-icon fs-4"></iconify-icon>
            <span class="hide-menu">Home</span>
          </li>

          <li class="sidebar-item">
            <a class="sidebar-link" href="./dashboard.php" aria-expanded="false">
              <i class="ti ti-layout-dashboard"></i>
              <span class="hide-menu">Dashboard</span>
            </a>
          </li>

          <!-- ✅ User Management Dropdown -->
          <li class="sidebar-item">
            <a class="sidebar-link has-arrow collapsed"
              href="#userMgmtMenu"
              data-bs-toggle="collapse"
              role="button"
              aria-expanded="false"
              aria-controls="userMgmtMenu">
              <i class="ti ti-users"></i>
              <span class="hide-menu">User Management</span>
            </a>

            <ul id="userMgmtMenu" class="collapse first-level">
              <li class="sidebar-item">
                <a href="./user_management.php" class="sidebar-link">
                  <i class="ti ti-home"></i>
                  <span class="hide-menu">Homeowners</span>
                </a>
              </li>

              <li class="sidebar-item">
                <a href="./phase_management.php" class="sidebar-link">
                  <i class="ti ti-shield-check"></i>
                  <span class="hide-menu">Officers</span>
                </a>
              </li>
            </ul>
          </li>
                      <li class="sidebar-item">
              <a class="sidebar-link" href="./announcements.php" aria-expanded="false">
                <i class="ti ti-bell"></i>
                <span class="hide-menu">Announcements</span>
              </a>
            </li>

          <li class="sidebar-item">
            <a class="sidebar-link" href="./voting.html" aria-expanded="false">
              <i class="ti ti-checkbox"></i>
              <span class="hide-menu">Voting Management</span>
            </a>
          </li>
        <!-- End Sidebar navigation -->
      </nav>
    </div>
  </aside>
    <div class="body-wrapper">
      <header class="app-header">
        <nav class="navbar navbar-expand-lg navbar-light">
          <div class="navbar-collapse justify-content-end px-0">
            <ul class="navbar-nav flex-row ms-auto align-items-center justify-content-end">
              <li class="nav-item">
                <a href="../index.php" class="btn btn-outline-primary">Logout</a>
              </li>
            </ul>
          </div>
        </nav>
      </header>

      <div class="body-wrapper-inner">
        <div class="container-fluid">

          <div class="card">
            <div class="card-body">
              <h4 class="card-title">Post Announcement</h4>
              <p class="text-muted mb-4">Announcements posted as <b>ALL phases</b> will be visible in every phase dashboard.</p>

              <?php if ($err): ?>
                <div class="alert alert-danger"><?= esc($err) ?></div>
              <?php endif; ?>
              <?php if ($ok): ?>
                <div class="alert alert-success"><?= esc($ok) ?></div>
              <?php endif; ?>

              <form method="POST" class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Title</label>
                  <input type="text" name="title" class="form-control" required maxlength="255">
                </div>

                <div class="col-md-3">
                  <label class="form-label">Category</label>
                  <select name="category" class="form-select">
                    <option value="general">General</option>
                    <option value="maintenance">Maintenance</option>
                    <option value="meeting">Meeting</option>
                    <option value="emergency">Emergency</option>
                  </select>
                </div>

                <div class="col-md-3">
                  <label class="form-label">Priority</label>
                  <select name="priority" class="form-select">
                    <option value="normal">Normal</option>
                    <option value="important">Important</option>
                    <option value="urgent">Urgent</option>
                  </select>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Target</label>
                  <select name="target_phase" class="form-select">
                    <option value="ALL">All Phases (Recommended)</option>
                    <option value="Phase 1">Phase 1 only</option>
                    <option value="Phase 2">Phase 2 only</option>
                    <option value="Phase 3">Phase 3 only</option>
                  </select>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Start Date</label>
                  <input type="date" name="start_date" class="form-control" value="<?= esc(date('Y-m-d')) ?>" required>
                </div>

                <div class="col-md-4">
                  <label class="form-label">End Date (optional)</label>
                  <input type="date" name="end_date" class="form-control">
                </div>

                <div class="col-12">
                  <label class="form-label">Message</label>
                  <textarea name="message" class="form-control" rows="5" required></textarea>
                </div>

                <div class="col-12">
                  <button class="btn btn-primary">
                    <i class="ti ti-send"></i> Publish
                  </button>
                </div>
              </form>
            </div>
          </div>

          <div class="card mt-4">
            <div class="card-body">
              <h4 class="card-title">Your Latest Posts</h4>
              <div class="table-responsive mt-3">
                <table class="table align-middle text-nowrap">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Phase</th>
                      <th>Title</th>
                      <th>Priority</th>
                      <th>Dates</th>
                      <th>Created</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$rows): ?>
                      <tr><td colspan="6" class="text-muted">No announcements yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($rows as $r): ?>
                        <tr>
                          <td><?= (int)$r['id'] ?></td>
                          <td><?= esc($r['phase']) ?></td>
                          <td><?= esc($r['title']) ?></td>
                          <td><?= esc($r['priority']) ?></td>
                          <td>
                            <?= esc($r['start_date']) ?>
                            <?php if (!empty($r['end_date'])): ?> → <?= esc($r['end_date']) ?><?php endif; ?>
                          </td>
                          <td><?= esc($r['created_at']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

            </div>
          </div>

        </div>
      </div>

    </div>
  </div>

  <script src="./assets/libs/jquery/dist/jquery.min.js"></script>
  <script src="./assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/sidebarmenu.js"></script>
  <script src="./assets/js/app.min.js"></script>
  <script src="./assets/libs/simplebar/dist/simplebar.js"></script>
</body>
</html>