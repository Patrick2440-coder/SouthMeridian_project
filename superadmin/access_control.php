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
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Superadmin - Access Control</title>

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

    .module-col {
      min-width: 220px;
      font-weight: 700;
      position: sticky;
      left: 0;
      background: #fff;
      z-index: 3;
    }

    .matrix-wrap {
      overflow: auto;
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      background: #fff;
      max-height: 72vh;
    }

    .matrix-table {
      min-width: 1100px;
      margin-bottom: 0;
    }

    .matrix-table thead th {
      position: sticky;
      top: 0;
      background: #f8fafc;
      z-index: 4;
      box-shadow: inset 0 -1px 0 #e5e7eb;
      text-align: center;
      white-space: nowrap;
    }

    .matrix-table tbody td {
      vertical-align: middle;
      text-align: center;
    }

    .matrix-table tbody tr:hover td {
      background: #fafafa;
    }

    .matrix-table tbody tr:hover td.module-col {
      background: #fafafa;
    }

    .perm-switch {
      transform: scale(1.15);
      cursor: pointer;
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

    .top-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .mini-note {
      color: #64748b;
      font-size: 13px;
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
            <div class="title"><h4>Officers Access Control</h4></div>
            <div class="text-secondary">
              Manage module permissions for each HOA officer position.
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-lg-8 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p mb-20">
            <div class="row align-items-center">
              <div class="col-md-4">
                <img src="../admin/vendors/images/banner-img.png" alt="">
              </div>
              <div class="col-md-8">
                <h4 class="font-20 weight-500 mb-10 text-capitalize">
                  <div class="weight-600 font-30 text-blue">Access Control Panel</div>
                </h4>
                <p class="font-18 max-width-600">
                  Turn access on or off for each position and control which modules officers can use inside the system.
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
              <span class="text-secondary">Officer Positions</span>
              <span class="badge-soft badge-soft-info"><?= count($positions) ?></span>
            </div>

            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">System Modules</span>
              <span class="badge-soft badge-soft-success"><?= count($modules) ?></span>
            </div>

            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">Default Profiles</span>
              <span class="badge-soft badge-soft-warning">Ready</span>
            </div>

            <div class="mt-3 mini-note">
              Use the matrix below to update access, then save your changes.
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

      <div class="row">
        <?php foreach ($positions as $position): ?>
          <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
            <div class="summary-card">
              <div class="summary-number"><?= (int)$summaryCounts[$position] ?></div>
              <div class="summary-label"><?= esc($position) ?> allowed modules</div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="card-box mb-30 p-3">
        <form method="post" id="accessControlForm">
          <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
            <div>
              <h5 class="mb-1">Permission Matrix</h5>
              <div class="mini-note">Enable or disable modules per officer position.</div>
            </div>

            <div class="top-actions">
              <button type="button" class="btn btn-outline-warning" id="resetDefaultsBtn">
                Reset to Defaults
              </button>
              <button type="submit" name="save_access_control" class="btn btn-success">
                Save Access Control
              </button>
            </div>
          </div>

          <div class="matrix-wrap">
            <table class="table table-bordered table-hover matrix-table">
              <thead>
                <tr>
                  <th class="module-col text-left">Module</th>
                  <?php foreach ($positions as $position): ?>
                    <th><?= esc($position) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($modules as $module): ?>
                  <tr>
                    <td class="module-col text-left"><?= esc($module['module_name']) ?></td>
                    <?php foreach ($positions as $position): ?>
                      <?php
                        $moduleKey = (string)$module['module_key'];
                        $checked = !empty($matrix[$position][$moduleKey]);
                        $field = 'perm_' . md5($position . '|' . $moduleKey);
                      ?>
                      <td>
                        <div class="custom-control custom-switch d-flex justify-content-center">
                          <input
                            type="checkbox"
                            class="custom-control-input perm-switch"
                            name="<?= esc($field) ?>"
                            id="<?= esc($field) ?>"
                            <?= $checked ? 'checked' : '' ?>
                          >
                          <label class="custom-control-label" for="<?= esc($field) ?>"></label>
                        </div>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="mt-4 d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-warning" id="resetDefaultsBtnBottom">
              Reset to Defaults
            </button>
            <button type="submit" name="save_access_control" class="btn btn-success">
              Save Access Control
            </button>
          </div>
        </form>
      </div>

      <div class="footer-wrap pd-20 mb-20 card-box">
        © Copyright South Meridian Homes All Rights Reserved
      </div>
    </div>
  </div>

  <!-- Reset Confirmation Modal -->
  <div class="modal fade" id="resetConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reset Permissions</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          Are you sure you want to reset all officer permissions to default settings?
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger" id="confirmReset">Reset Permissions</button>
        </div>
      </div>
    </div>
  </div>

  <script src="../admin/vendors/scripts/core.js"></script>
  <script src="../admin/vendors/scripts/script.min.js"></script>
  <script src="../admin/vendors/scripts/process.js"></script>
  <script src="../admin/vendors/scripts/layout-settings.js"></script>

  <script>
    function openResetModal() {
      $('#resetConfirmModal').modal('show');
    }

    $('#resetDefaultsBtn, #resetDefaultsBtnBottom').on('click', function () {
      openResetModal();
    });

    $('#confirmReset').on('click', function () {
      const form = document.getElementById('accessControlForm');

      const existing = form.querySelector('input[name="reset_defaults"]');
      if (existing) existing.remove();

      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'reset_defaults';
      input.value = '1';
      form.appendChild(input);

      form.submit();
    });
  </script>
</body>
</html>