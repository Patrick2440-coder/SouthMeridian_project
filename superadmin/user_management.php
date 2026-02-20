<?php
session_start();

// OPTIONAL: if you already have superadmin auth session, enforce it here
// if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') { header("Location: authentication-login.html"); exit; }

// DB
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ✅ Phase filter (Phase 1/2/3 or All)
$selected_phase = trim($_GET['phase'] ?? 'All');
$allowed_phases = ['All','Phase 1','Phase 2','Phase 3'];

if (!in_array($selected_phase, $allowed_phases, true)) {
  $selected_phase = 'All';
}

// Fetch approved homeowners (optionally filtered by phase)
if ($selected_phase === 'All') {
  $stmt = $conn->prepare("
    SELECT
      h.id,
      h.first_name,
      h.middle_name,
      h.last_name,
      h.house_lot_number,
      h.phase,
      h.status,
      COALESCE(hp.position, 'Homeowner') AS position
    FROM homeowners h
    LEFT JOIN homeowner_positions hp
      ON hp.homeowner_id = h.id AND hp.phase = h.phase
    WHERE h.status = 'approved'
    ORDER BY h.phase ASC, h.last_name ASC, h.first_name ASC
  ");
} else {
  $stmt = $conn->prepare("
    SELECT
      h.id,
      h.first_name,
      h.middle_name,
      h.last_name,
      h.house_lot_number,
      h.phase,
      h.status,
      COALESCE(hp.position, 'Homeowner') AS position
    FROM homeowners h
    LEFT JOIN homeowner_positions hp
      ON hp.homeowner_id = h.id AND hp.phase = h.phase
    WHERE h.status = 'approved' AND h.phase = ?
    ORDER BY h.phase ASC, h.last_name ASC, h.first_name ASC
  ");
  $stmt->bind_param("s", $selected_phase);
}

$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Super Admin | User Management</title>
  <link rel="shortcut icon" type="image/png" href="../assets/images/logos/favicon.png" />
  <link rel="stylesheet" href="../superadmin/assets/css/styles.min.css" />
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
</head>

<body>
<div class="page-wrapper" id="main-wrapper" data-layout="vertical" data-navbarbg="skin6" data-sidebartype="full"
     data-sidebar-position="fixed" data-header-position="fixed">

  <div class="app-topstrip py-6 px-3 w-100 d-lg-flex align-items-center justify-content-between" style="background-color: #077f46;">
    <div class="d-flex align-items-center justify-content-center gap-5 mb-2 mb-lg-0">
      <a class="d-flex justify-content-center" href="#">
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

        <!-- End Sidebar navigation -->
      </nav>
    </div>
  </aside>

  <!-- Main -->
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
                  <a href="./authentication-login.html" class="btn btn-outline-primary mx-3 mt-2 d-block">Logout</a>
                </div>
              </div>
            </li>
          </ul>
        </div>
      </nav>
    </header>

    <div class="body-wrapper-inner">
      <div class="container-fluid">

        <div class="row mt-4">
          <div class="col-12">
            <div class="card bg-white shadow-sm">
              <div class="card-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                  <div>
                    <h5 class="card-title mb-1">Homeowners</h5>
                    <p class="text-muted mb-0">
                      Approved homeowners
                      <?= ($selected_phase === 'All') ? "across Phase 1, 2, and 3" : "in " . esc($selected_phase) ?>
                    </p>
                  </div>

                  <!-- ✅ Phase Filter -->
                  <form method="GET" class="d-flex align-items-center gap-2">
                    <label class="mb-0 small text-muted">Phase:</label>
                    <select name="phase" class="form-select form-select-sm" style="min-width: 160px;" onchange="this.form.submit()">
                      <?php foreach ($allowed_phases as $ph): ?>
                        <option value="<?= esc($ph) ?>" <?= ($selected_phase === $ph) ? 'selected' : '' ?>>
                          <?= esc($ph) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <noscript>
                      <button class="btn btn-sm btn-primary" type="submit">Apply</button>
                    </noscript>
                  </form>
                </div>

                <hr class="my-3">

                <div class="table-responsive">
                  <table id="homeownersTable" class="table table-bordered align-middle">
                    <thead class="table-light">
                      <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Lot/Unit</th>
                        <th>Phase</th>
                        <th>Position</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach($rows as $r): ?>
                        <?php
                          $full = trim($r['first_name'].' '.$r['middle_name'].' '.$r['last_name']);
                          $statusBadge = '<span class="badge bg-success">Approved</span>';
                        ?>
                        <tr>
                          <td><?= (int)$r['id'] ?></td>
                          <td><?= esc($full) ?></td>
                          <td><?= esc($r['house_lot_number']) ?></td>
                          <td><?= esc($r['phase']) ?></td>
                          <td><?= esc($r['position']) ?></td>
                          <td><?= $statusBadge ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>
          </div>
        </div>

        <div class="py-6 px-6 text-center">
          <p>
            © <span>Copyright</span>
            <strong class="px-1 sitename">South Meridian Homes</strong>
            <span>All Rights Reserved</span>
          </p>
        </div>

      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="./assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/sidebarmenu.js"></script>
<script src="./assets/js/app.min.js"></script>
<script src="./assets/libs/simplebar/dist/simplebar.js"></script>
<script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

<script>
$(document).ready(function () {
  $('#homeownersTable').DataTable({
    pageLength: 10,
    order: [[3,'asc'], [1,'asc']]
  });
});
</script>

</body>
</html>