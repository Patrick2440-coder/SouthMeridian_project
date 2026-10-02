<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../config/database.php';

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| Superadmin guard
|--------------------------------------------------------------------------
| Superadmin can VIEW the current officer permission matrix only.
| Permission changes are handled on the Admin side by:
| President, Vice President, and Secretary.
*/
$sessionAdminId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
$sessionRole = (string)($_SESSION['admin_role'] ?? $_SESSION['role'] ?? '');

if ($sessionAdminId <= 0 || $sessionRole !== 'superadmin') {
    header('Location: ../index.php');
    exit;
}

/* Confirm the logged-in account is still the Superadmin account. */
$adminStmt = $conn->prepare("
    SELECT id, full_name, email, role, phase
    FROM admins
    WHERE id = ?
      AND role = 'superadmin'
    LIMIT 1
");
$adminStmt->bind_param('i', $sessionAdminId);
$adminStmt->execute();
$superadmin = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();

if (!$superadmin) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$positions = [
    'President',
    'Vice President',
    'Secretary',
    'Treasurer',
    'Auditor',
    'Board of Director'
];

function get_modules(mysqli $conn): array
{
    $modules = [];

    $res = $conn->query("
        SELECT module_key, module_name
        FROM access_modules
        ORDER BY sort_order ASC, module_name ASC
    ");

    while ($row = $res->fetch_assoc()) {
        $modules[] = $row;
    }

    return $modules;
}

function load_matrix(mysqli $conn): array
{
    $matrix = [];

    $res = $conn->query("
        SELECT position, module_key, is_allowed
        FROM access_permissions
    ");

    while ($row = $res->fetch_assoc()) {
        $matrix[(string)$row['position']][(string)$row['module_key']] =
            (int)$row['is_allowed'];
    }

    return $matrix;
}

$modules = get_modules($conn);
$matrix = load_matrix($conn);

/* Read-only summary counts. */
$summaryCounts = [];

foreach ($positions as $position) {
    $summaryCounts[$position] = 0;

    foreach ($modules as $module) {
        $moduleKey = (string)$module['module_key'];

        if (!empty($matrix[$position][$moduleKey])) {
            $summaryCounts[$position]++;
        }
    }
}

$adminDisplayName = trim(
    (string)($superadmin['full_name'] ?? '')
);

if ($adminDisplayName === '') {
    $adminDisplayName = 'Superadmin';
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>
  <meta charset="utf-8">
  <title>Superadmin - Access Control Overview</title>



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

    .badge-soft {
      padding: .35rem .6rem;
      border-radius: 999px;
      font-weight: 800;
      font-size: 12px;
      display: inline-block;
      white-space: nowrap;
    }

    .badge-soft-success {
      background:#ecfdf5;
      border:1px solid #bbf7d0;
      color:#166534;
    }

    .badge-soft-info {
      background:#eff6ff;
      border:1px solid #bfdbfe;
      color:#1d4ed8;
    }

    .badge-soft-warning {
      background:#fff7ed;
      border:1px solid #fed7aa;
      color:#9a3412;
    }

    .badge-soft-secondary {
      background:#f1f5f9;
      border:1px solid #cbd5e1;
      color:#475569;
    }

    .mini-note {
      color: #64748b;
      font-size: 13px;
    }

    .view-only-note {
      border-left: 4px solid #077f46;
      background: #f8fafc;
      padding: 12px 14px;
      border-radius: 8px;
      color: #475569;
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
            <span class="user-name"><?= esc($adminDisplayName) ?></span>
          </a>

          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="profile.html">
              <i class="dw dw-user1"></i> Profile
            </a>
            <a class="dropdown-item" href="logs.php">
              <i class="dw dw-list3"></i> Activity Logs
            </a>
            <a class="dropdown-item" href="../index.php">
              <i class="dw dw-logout"></i> Log Out
            </a>
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
            <div class="title">
              <h4>Officer Access Control</h4>
            </div>
            <div class="text-secondary">
              View the current module permissions assigned to HOA officer positions.
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
                  <div class="weight-600 font-30 text-blue">
                    Access Control Overview
                  </div>
                </h4>

                <p class="font-18 max-width-600 mb-2">
                  Superadmin can monitor officer access but cannot change permission settings here.
                </p>

                <div class="view-only-note">
                  Permission changes are managed by the President, Vice President, and Secretary on the Admin side.
                </div>
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
              <span class="text-secondary">Access Mode</span>
              <span class="badge-soft badge-soft-warning">View Only</span>
            </div>

            <div class="mt-3 mini-note">
              Changes are made by authorized HOA officers, not by Superadmin.
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <?php foreach ($positions as $position): ?>
          <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
            <div class="summary-card">
              <div class="summary-number">
                <?= (int)$summaryCounts[$position] ?>
              </div>
              <div class="summary-label">
                <?= esc($position) ?> allowed modules
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
          <div>
            <h5 class="mb-1">Permission Matrix</h5>
            <div class="mini-note">
              Current access settings are shown below. This page is view-only.
            </div>
          </div>

          <span class="badge-soft badge-soft-warning mt-2 mt-md-0">
            View Only
          </span>
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
              <?php if (!$modules): ?>
                <tr>
                  <td colspan="<?= count($positions) + 1 ?>" class="text-center text-secondary">
                    No access modules are configured.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($modules as $module): ?>
                  <tr>
                    <td class="module-col text-left">
                      <?= esc($module['module_name']) ?>
                    </td>

                    <?php foreach ($positions as $position): ?>
                      <?php
                        $moduleKey = (string)$module['module_key'];
                        $allowed = !empty($matrix[$position][$moduleKey]);
                      ?>
                      <td>
                        <?php if ($allowed): ?>
                          <span class="badge-soft badge-soft-success">
                            <i class="dw dw-check"></i> Allowed
                          </span>
                        <?php else: ?>
                          <span class="badge-soft badge-soft-secondary">
                            No Access
                          </span>
                        <?php endif; ?>
                      </td>
                    <?php endforeach; ?>
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
