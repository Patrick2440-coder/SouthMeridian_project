<?php
session_start();

/* =========================
   SUPERADMIN GUARD
   ========================= */
if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    $_SESSION['admin_role'] !== 'superadmin'
) {
    echo "<script>alert('Access denied. Superadmin only.'); window.location='../index.php';</script>";
    exit;
}

/* =========================
   DB
   ========================= */
$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$positions = [
    'President',
    'Vice President',
    'Secretary',
    'Treasurer',
    'Auditor',
    'Board of Director'
];

$defaultPermissions = [
    'President' => [
        'dashboard' => 1,
        'homeowner_management' => 1,
        'user_management' => 1,
        'announcements' => 1,
        'complaints' => 1,
        'finance' => 1,
        'parking' => 1,
        'community' => 1,
        'voting_management' => 1,
        'settings' => 1,
    ],
    'Vice President' => [
        'dashboard' => 1,
        'homeowner_management' => 1,
        'user_management' => 0,
        'announcements' => 1,
        'complaints' => 1,
        'finance' => 1,
        'parking' => 1,
        'community' => 1,
        'voting_management' => 0,
        'settings' => 0,
    ],
    'Secretary' => [
        'dashboard' => 1,
        'homeowner_management' => 1,
        'user_management' => 0,
        'announcements' => 1,
        'complaints' => 1,
        'finance' => 0,
        'parking' => 0,
        'community' => 0,
        'voting_management' => 0,
        'settings' => 0,
    ],
    'Treasurer' => [
        'dashboard' => 1,
        'homeowner_management' => 0,
        'user_management' => 0,
        'announcements' => 0,
        'complaints' => 0,
        'finance' => 1,
        'parking' => 0,
        'community' => 0,
        'voting_management' => 0,
        'settings' => 0,
    ],
    'Auditor' => [
        'dashboard' => 1,
        'homeowner_management' => 0,
        'user_management' => 0,
        'announcements' => 0,
        'complaints' => 0,
        'finance' => 1,
        'parking' => 0,
        'community' => 0,
        'voting_management' => 0,
        'settings' => 0,
    ],
    'Board of Director' => [
        'dashboard' => 1,
        'homeowner_management' => 0,
        'user_management' => 0,
        'announcements' => 1,
        'complaints' => 1,
        'finance' => 1,
        'parking' => 1,
        'community' => 1,
        'voting_management' => 0,
        'settings' => 0,
    ],
];

function get_modules(mysqli $conn): array {
    $modules = [];
    $res = $conn->query("SELECT module_key, module_name FROM access_modules ORDER BY sort_order ASC, module_name ASC");
    while ($row = $res->fetch_assoc()) {
        $modules[] = $row;
    }
    return $modules;
}

function ensure_permissions_exist(mysqli $conn, array $positions, array $defaultPermissions): void {
    $modules = get_modules($conn);

    $stmt = $conn->prepare("
        INSERT INTO access_permissions (position, module_key, is_allowed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE is_allowed = is_allowed
    ");

    foreach ($positions as $position) {
        foreach ($modules as $module) {
            $moduleKey = (string)$module['module_key'];
            $allowed = (int)($defaultPermissions[$position][$moduleKey] ?? 0);
            $stmt->bind_param("ssi", $position, $moduleKey, $allowed);
            $stmt->execute();
        }
    }

    $stmt->close();
}

function load_matrix(mysqli $conn): array {
    $matrix = [];
    $res = $conn->query("SELECT position, module_key, is_allowed FROM access_permissions");
    while ($row = $res->fetch_assoc()) {
        $matrix[$row['position']][$row['module_key']] = (int)$row['is_allowed'];
    }
    return $matrix;
}

function save_permissions(mysqli $conn, array $positions, array $modules): void {
    $stmt = $conn->prepare("
        INSERT INTO access_permissions (position, module_key, is_allowed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed)
    ");

    foreach ($positions as $position) {
        foreach ($modules as $module) {
            $moduleKey = (string)$module['module_key'];
            $field = 'perm_' . md5($position . '|' . $moduleKey);
            $allowed = isset($_POST[$field]) ? 1 : 0;
            $stmt->bind_param("ssi", $position, $moduleKey, $allowed);
            $stmt->execute();
        }
    }

    $stmt->close();
}

function reset_permissions_to_defaults(mysqli $conn, array $positions, array $modules, array $defaultPermissions): void {
    $stmt = $conn->prepare("
        INSERT INTO access_permissions (position, module_key, is_allowed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed)
    ");

    foreach ($positions as $position) {
        foreach ($modules as $module) {
            $moduleKey = (string)$module['module_key'];
            $allowed = (int)($defaultPermissions[$position][$moduleKey] ?? 0);
            $stmt->bind_param("ssi", $position, $moduleKey, $allowed);
            $stmt->execute();
        }
    }

    $stmt->close();
}

ensure_permissions_exist($conn, $positions, $defaultPermissions);

$success = '';
$error = '';

$modules = get_modules($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();

    try {
        if (isset($_POST['save_access_control'])) {
            save_permissions($conn, $positions, $modules);
            $success = 'Access control updated successfully.';
        } elseif (isset($_POST['reset_defaults'])) {
            reset_permissions_to_defaults($conn, $positions, $modules, $defaultPermissions);
            $success = 'Permissions reset to default successfully.';
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $error = 'Failed to process request: ' . $e->getMessage();
    }
}

$matrix = load_matrix($conn);

/* counts for quick summary */
$summaryCounts = [];
foreach ($positions as $position) {
    $summaryCounts[$position] = 0;
    foreach ($modules as $module) {
        $moduleKey = $module['module_key'];
        if (!empty($matrix[$position][$moduleKey])) {
            $summaryCounts[$position]++;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Access Control</title>
  <link rel="shortcut icon" type="image/png" href="../assets/images/logos/favicon.png" />
  <link rel="stylesheet" href="../superadmin/assets/css/styles.min.css" />
  <style>
    .perm-switch {
      transform: scale(1.12);
      cursor: pointer;
    }
    .module-col {
      min-width: 200px;
      font-weight: 700;
    }
    .sticky-header th {
      position: sticky;
      top: 0;
      background: #fff;
      z-index: 2;
      box-shadow: 0 1px 0 rgba(0,0,0,.06);
    }
    .summary-card {
      border: 1px solid #e9ecef;
      border-radius: 12px;
      padding: 14px 16px;
      background: #fff;
      height: 100%;
    }
    .summary-number {
      font-size: 24px;
      font-weight: 700;
      color: #077f46;
      line-height: 1;
    }
    .summary-label {
      font-size: 13px;
      color: #6c757d;
      margin-top: 4px;
    }
    .table-wrap {
      max-height: 70vh;
      overflow: auto;
      border: 1px solid #e9ecef;
      border-radius: 12px;
    }
    .page-note {
      font-size: 14px;
      color: #6c757d;
    }
    
  </style>
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

    <aside class="left-sidebar">
      <div>
        <div class="brand-logo d-flex align-items-center justify-content-between">
          <a href="./dashboard.php" class="text-nowrap logo-img">
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
              <a class="sidebar-link active" href="./access_control.php" aria-expanded="false">
                <i class="ti ti-lock-access"></i>
                <span class="hide-menu">Access Control</span>
              </a>
            </li>

            <li class="sidebar-item">
              <a class="sidebar-link" href="./announcements.php" aria-expanded="false">
                <i class="ti ti-bell"></i>
                <span class="hide-menu">Announcements</span>
              </a>
            </li>

            <li class="sidebar-item">
              <a class="sidebar-link" href="./voting.php" aria-expanded="false">
                <i class="ti ti-checkbox"></i>
                <span class="hide-menu">Voting Management</span>
              </a>
            </li>
          </ul>
        </nav>
      </div>
    </aside>

    <div class="body-wrapper">
      <header class="app-header">
        <nav class="navbar navbar-expand-lg navbar-light">
          <ul class="navbar-nav">
            <li class="nav-item d-block d-xl-none">
              <a class="nav-link sidebartoggler" id="headerCollapse" href="javascript:void(0)">
                <i class="ti ti-menu-2"></i>
              </a>
            </li>
          </ul>

          <div class="navbar-collapse justify-content-end px-0" id="navbarNav">
            <ul class="navbar-nav flex-row ms-auto align-items-center justify-content-end">
              <li class="nav-item dropdown">
                <a class="nav-link" href="javascript:void(0)" id="drop2" data-bs-toggle="dropdown" aria-expanded="false">
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

          <div class="card mb-4">
            <div class="card-body">
              <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                  <h1 class="card-title mb-1">Officers Access Control</h1>
                </div>
                <div class="text-end">
                  <div class="fw-semibold">Positions: <?= count($positions) ?></div>
                  <div class="text-muted small">Modules: <?= count($modules) ?></div>
                </div>
              </div>
            </div>
          </div>

          <?php if ($success): ?>
            <div class="alert alert-success"><?= esc($success) ?></div>
          <?php endif; ?>

          <?php if ($error): ?>
            <div class="alert alert-danger"><?= esc($error) ?></div>
          <?php endif; ?>

          <div class="row mb-4">
            <?php foreach ($positions as $position): ?>
              <div class="col-xl-2 col-lg-4 col-md-6 col-sm-6 mb-3">
                <div class="summary-card">
                  <div class="summary-number"><?= (int)$summaryCounts[$position] ?></div>
                  <div class="summary-label"><?= esc($position) ?> allowed modules</div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="card">
            <div class="card-body">
              <form method="post" id="accessControlForm">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                  <div>
                    <h5 class="mb-1">Permission Matrix</h5>
                    <div class="text-muted small">Turn module access on or off for each officer position.</div>
                  </div>

                  <div class="d-flex gap-2">
<button type="button" class="btn btn-outline-warning" id="resetDefaultsBtn">
Reset to Defaults
</button>
                    <button type="submit" name="save_access_control" class="btn btn-success">
                      Save Access Control
                    </button>
                  </div>
                </div>

                <div class="table-wrap">
                  <table class="table table-bordered align-middle text-center mb-0">
                    <thead class="sticky-header">
                      <tr>
                        <th class="module-col text-start">Module</th>
                        <?php foreach ($positions as $position): ?>
                          <th><?= esc($position) ?></th>
                        <?php endforeach; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($modules as $module): ?>
                        <tr>
                          <td class="text-start fw-semibold"><?= esc($module['module_name']) ?></td>
                          <?php foreach ($positions as $position): ?>
                            <?php
                              $moduleKey = (string)$module['module_key'];
                              $checked = !empty($matrix[$position][$moduleKey]);
                              $field = 'perm_' . md5($position . '|' . $moduleKey);
                            ?>
                            <td>
                              <div class="form-check form-switch d-flex justify-content-center">
                                <input
                                  class="form-check-input perm-switch"
                                  type="checkbox"
                                  name="<?= esc($field) ?>"
                                  id="<?= esc($field) ?>"
                                  <?= $checked ? 'checked' : '' ?>
                                >
                              </div>
                            </td>
                          <?php endforeach; ?>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="mt-4 d-flex justify-content-end gap-2">
                  <button type="submit" name="reset_defaults" class="btn btn-outline-warning"
                          onclick="return confirm('Reset all permissions to default settings?');">
                    Reset to Defaults
                  </button>
                  <button type="submit" name="save_access_control" class="btn btn-success">
                    Save Access Control
                  </button>
                </div>
              </form>
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

<!-- Reset Confirmation Modal -->
<div class="modal fade" id="resetConfirmModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Reset Permissions</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        Are you sure you want to reset all officer permissions to default settings?
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">
          Cancel
        </button>

        <button class="btn btn-danger" id="confirmReset">
          Reset Permissions
        </button>
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
  
  <script>

document.getElementById("resetDefaultsBtn").addEventListener("click", function(){
  const modal = new bootstrap.Modal(document.getElementById("resetConfirmModal"));
  modal.show();
});

document.getElementById("confirmReset").addEventListener("click", function(){

  const form = document.getElementById("accessControlForm");

  const input = document.createElement("input");
  input.type = "hidden";
  input.name = "reset_defaults";
  input.value = "1";

  form.appendChild(input);

  form.submit();

});

</script>
</body>
</html>