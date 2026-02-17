<?php
session_start();

// ✅ Only superadmin can access
if (!isset($_SESSION['admin_role']) || $_SESSION['admin_role'] !== 'superadmin' || empty($_SESSION['admin_id'])) {
  header("Location: ../index.php");
  exit;
}

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$admin_id = (int)$_SESSION['admin_id'];
$success = "";
$error = "";

// ===================== CREATE ANNOUNCEMENT =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_announcement'])) {
  $title    = trim($_POST['title'] ?? '');
  $category = $_POST['category'] ?? 'general';
  $message  = trim($_POST['message'] ?? '');
  $start    = $_POST['start_date'] ?? '';
  $end      = $_POST['end_date'] ?? '';
  $priority = $_POST['priority'] ?? 'normal';

  if ($title === '' || $message === '' || $start === '') {
    $error = "Title, message, and start date are required.";
  } else {
    // end date optional
    $endVal = ($end === '') ? null : $end;

    $stmt = $conn->prepare("
      INSERT INTO announcements
        (admin_id, phase, title, category, audience, audience_value, message, start_date, end_date, priority)
      VALUES
        (?, 'Superadmin', ?, ?, 'all', NULL, ?, ?, ?, ?)
    ");
    $stmt->bind_param("issssss", $admin_id, $title, $category, $message, $start, $endVal, $priority);

    if ($stmt->execute()) {
      $success = "Announcement posted successfully.";
    } else {
      $error = "Failed to post announcement: " . $stmt->error;
    }
    $stmt->close();
  }
}

// ===================== FETCH SUPERADMIN ANNOUNCEMENTS =====================
$list = [];
$stmt = $conn->prepare("
  SELECT id, title, category, message, start_date, end_date, priority, created_at
  FROM announcements
  WHERE phase='Superadmin'
  ORDER BY created_at DESC
  LIMIT 50
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $list[] = $row;
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

    <div class="app-topstrip py-6 px-3 w-100 d-lg-flex align-items-center justify-content-between" style="background-color: #077f46;">
      <div class="d-flex align-items-center justify-content-center gap-5 mb-2 mb-lg-0">
        <a class="d-flex justify-content-center" href="./dashboard.html">
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
              <a class="sidebar-link" href="./voting.html" aria-expanded="false">
                <i class="ti ti-checkbox"></i>
                <span class="hide-menu">Voting Management</span>
              </a>
            </li>

            <!-- ✅ ACTIVE: Announcements -->
            <li class="sidebar-item">
              <a class="sidebar-link " href="./announcements.php" aria-expanded="false">
                <i class="ti ti-bell"></i>
                <span class="hide-menu">Announcements</span>
              </a>
            </li>


        <!-- End Sidebar navigation -->
      </div>
      <!-- End Sidebar scroll-->
    </aside>
    <!-- Sidebar End -->

    <div class="body-wrapper">
      <header class="app-header">
        <nav class="navbar navbar-expand-lg navbar-light">
          <ul class="navbar-nav">
            <li class="nav-item d-block d-xl-none">
              <a class="nav-link sidebartoggler " id="headerCollapse" href="javascript:void(0)">
                <i class="ti ti-menu-2"></i>
              </a>
            </li>
          </ul>

          <div class="navbar-collapse justify-content-end px-0" id="navbarNav">
            <ul class="navbar-nav flex-row ms-auto align-items-center justify-content-end">
              <li class="nav-item dropdown">
                <a class="nav-link " href="javascript:void(0)" id="drop2" data-bs-toggle="dropdown" aria-expanded="false">
                  <img src="./assets/images/profile/user-1.jpg" alt="" width="35" height="35" class="rounded-circle">
                </a>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-animate-up" aria-labelledby="drop2">
                  <div class="message-body">
                    <a href="./profile.html" class="d-flex align-items-center gap-2 dropdown-item">
                      <i class="ti ti-user fs-6"></i>
                      <p class="mb-0 fs-3">My Profile</p>
                    </a>
                    <a href="./logs.html" class="d-flex align-items-center gap-2 dropdown-item">
                      <i class="ti ti-list-check fs-6"></i>
                      <p class="mb-0 fs-3">Activity Logs</p>
                    </a>
                    <a href="../index.php" class="btn btn-outline-primary mx-3 mt-2 d-block">Logout</a>
                  </div>
                </div>
              </li>
            </ul>
          </div>

        </nav>
      </header>

      <div class="body-wrapper-inner">
        <div class="container-fluid">

          <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
              <h4 class="mb-0">Announcements</h4>
              <p class="text-muted mb-0">Posts here will appear on the public homepage (index.php).</p>
            </div>
          </div>

          <?php if ($success): ?>
            <div class="alert alert-success"><?= esc($success) ?></div>
          <?php endif; ?>
          <?php if ($error): ?>
            <div class="alert alert-danger"><?= esc($error) ?></div>
          <?php endif; ?>

          <!-- Create Announcement -->
          <div class="card mb-4">
            <div class="card-body">
              <h5 class="card-title mb-3">Create New Announcement</h5>

              <form method="POST">
                <input type="hidden" name="create_announcement" value="1">

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-control" required>
                  </div>

                  <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select" required>
                      <option value="general">General</option>
                      <option value="maintenance">Maintenance</option>
                      <option value="meeting">Meeting</option>
                      <option value="emergency">Emergency</option>
                    </select>
                  </div>

                  <div class="col-md-3">
                    <label class="form-label">Priority</label>
                    <select name="priority" class="form-select" required>
                      <option value="normal">Normal</option>
                      <option value="important">Important</option>
                      <option value="urgent">Urgent</option>
                    </select>
                  </div>

                  <div class="col-12">
                    <label class="form-label">Message</label>
                    <textarea name="message" class="form-control" rows="4" required></textarea>
                  </div>

                  <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" required>
                  </div>

                  <div class="col-md-3">
                    <label class="form-label">End Date (optional)</label>
                    <input type="date" name="end_date" class="form-control">
                  </div>

                  <div class="col-12">
                    <button class="btn btn-success" type="submit">Post Announcement</button>
                  </div>
                </div>

              </form>
            </div>
          </div>

          <!-- List -->
          <div class="card">
            <div class="card-body">
              <h5 class="card-title mb-3">Posted Announcements</h5>

              <?php if (!$list): ?>
                <div class="text-muted">No announcements yet.</div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table align-middle">
                    <thead>
                      <tr>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Priority</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Created</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($list as $a): ?>
                        <tr>
                          <td>
                            <div class="fw-semibold"><?= esc($a['title']) ?></div>
                            <div class="text-muted small"><?= esc(mb_strimwidth($a['message'], 0, 80, '...')) ?></div>
                          </td>
                          <td><?= esc($a['category']) ?></td>
                          <td>
                            <span class="badge bg-<?=
                              $a['priority']==='urgent' ? 'danger' : ($a['priority']==='important' ? 'warning' : 'secondary')
                            ?>">
                              <?= esc($a['priority']) ?>
                            </span>
                          </td>
                          <td><?= esc($a['start_date']) ?></td>
                          <td><?= esc($a['end_date'] ?? '-') ?></td>
                          <td><?= esc($a['created_at']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>

            </div>
          </div>

          <div class="py-6 px-6 text-center">
            <p>© <strong class="px-1 sitename">South Meridian Homes</strong> <span>All Rights Reserved</span></p>
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
  <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>
</body>
</html>
