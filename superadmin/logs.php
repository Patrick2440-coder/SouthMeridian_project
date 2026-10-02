<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../config/database.php';

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bindDynamicParams(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || !$params) {
        return;
    }

    $refs = [$types];

    foreach ($params as $key => &$value) {
        $refs[] = &$value;
    }

    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function validDate(string $date): bool
{
    if ($date === '') {
        return true;
    }

    $d = DateTime::createFromFormat('Y-m-d', $date);

    return $d && $d->format('Y-m-d') === $date;
}

function readableModule(?string $module): string
{
    $module = trim((string)$module);

    if ($module === '') {
        return 'General';
    }

    return ucwords(str_replace('_', ' ', $module));
}

/*
|--------------------------------------------------------------------------
| Superadmin only
|--------------------------------------------------------------------------
*/
$adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
$adminRole = (string)($_SESSION['admin_role'] ?? $_SESSION['role'] ?? '');

if ($adminId <= 0 || $adminRole !== 'superadmin') {
    header('Location: ../index.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT id, full_name, email, role, phase
    FROM admins
    WHERE id = ?
      AND role = 'superadmin'
    LIMIT 1
");
$stmt->bind_param('i', $adminId);
$stmt->execute();
$superadmin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$superadmin) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$adminDisplayName = trim((string)($superadmin['full_name'] ?? ''));

if ($adminDisplayName === '') {
    $adminDisplayName = 'Superadmin';
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$allowedPhases = ['All', 'Phase 1', 'Phase 2', 'Phase 3', 'Superadmin'];

$phaseFilter = trim((string)($_GET['phase'] ?? 'All'));

if (!in_array($phaseFilter, $allowedPhases, true)) {
    $phaseFilter = 'All';
}

$moduleFilter = trim((string)($_GET['module'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

if (!validDate($dateFrom)) {
    $dateFrom = '';
}

if (!validDate($dateTo)) {
    $dateTo = '';
}

if (
    $dateFrom !== '' &&
    $dateTo !== '' &&
    strtotime($dateTo) < strtotime($dateFrom)
) {
    $dateTo = '';
}

/*
|--------------------------------------------------------------------------
| Module choices
|--------------------------------------------------------------------------
*/
$moduleOptions = [];

$moduleResult = $conn->query("
    SELECT DISTINCT module_key
    FROM activity_logs
    WHERE module_key IS NOT NULL
      AND TRIM(module_key) <> ''
    ORDER BY module_key ASC
");

while ($row = $moduleResult->fetch_assoc()) {
    $moduleOptions[] = (string)$row['module_key'];
}

/*
|--------------------------------------------------------------------------
| Activity log query
|--------------------------------------------------------------------------
*/
$where = ['1=1'];
$params = [];
$types = '';

if ($phaseFilter !== 'All') {
    $where[] = 'l.phase = ?';
    $params[] = $phaseFilter;
    $types .= 's';
}

if ($moduleFilter !== '') {
    $where[] = 'l.module_key = ?';
    $params[] = $moduleFilter;
    $types .= 's';
}

if ($dateFrom !== '') {
    $where[] = 'l.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}

if ($dateTo !== '') {
    $where[] = 'l.created_at < ?';
    $params[] = date('Y-m-d H:i:s', strtotime($dateTo . ' +1 day'));
    $types .= 's';
}

if ($search !== '') {
    $where[] = "(
        l.action LIKE ?
        OR l.details LIKE ?
        OR l.module_key LIKE ?
        OR l.phase LIKE ?
        OR a.full_name LIKE ?
        OR a.email LIKE ?
        OR a.position LIKE ?
    )";

    $like = '%' . $search . '%';

    for ($i = 0; $i < 7; $i++) {
        $params[] = $like;
        $types .= 's';
    }
}

$sql = "
    SELECT
        l.id,
        l.admin_id,
        l.phase,
        l.action,
        l.module_key,
        l.details,
        l.ip_address,
        l.created_at,
        a.full_name,
        a.email,
        a.position,
        a.role
    FROM activity_logs l
    LEFT JOIN admins a
        ON a.id = l.admin_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT 500
";

$logStmt = $conn->prepare($sql);
bindDynamicParams($logStmt, $types, $params);
$logStmt->execute();
$logs = $logStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$logStmt->close();

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/
$totalLoaded = count($logs);

$todayResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM activity_logs
    WHERE created_at >= CURDATE()
      AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
");
$todayCount = (int)($todayResult->fetch_assoc()['total'] ?? 0);

$phaseCountResult = $conn->query("
    SELECT COUNT(DISTINCT phase) AS total
    FROM activity_logs
    WHERE phase IS NOT NULL
      AND TRIM(phase) <> ''
");
$phaseCount = (int)($phaseCountResult->fetch_assoc()['total'] ?? 0);

$moduleCountResult = $conn->query("
    SELECT COUNT(DISTINCT module_key) AS total
    FROM activity_logs
    WHERE module_key IS NOT NULL
      AND TRIM(module_key) <> ''
");
$moduleCount = (int)($moduleCountResult->fetch_assoc()['total'] ?? 0);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Superadmin - Activity Logs</title>

  <link rel="apple-touch-icon" sizes="180x180" href="../admin/vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="../admin/vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="../admin/vendors/images/favicon-16x16.png">

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/style.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/admin_theme.css">

  <style>
    .summary-card {
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      padding: 16px;
      background: #fff;
      height: 100%;
    }

    .summary-number {
      font-size: 27px;
      font-weight: 800;
      color: #077f46;
      line-height: 1;
    }

    .summary-label {
      font-size: 13px;
      color: #64748b;
      margin-top: 7px;
      font-weight: 600;
    }

    .filter-card {
      border: 1px solid #e5e7eb;
      border-radius: 14px;
    }

    .table-wrap {
      overflow-x: auto;
    }

    .logs-table {
      min-width: 1200px;
    }

    .logs-table td,
    .logs-table th {
      vertical-align: top;
    }

    .log-details {
      min-width: 280px;
      max-width: 440px;
      white-space: normal;
      word-break: break-word;
    }

    .badge-soft {
      padding: .35rem .6rem;
      border-radius: 999px;
      font-weight: 700;
      font-size: 12px;
      display: inline-block;
      white-space: nowrap;
    }

    .badge-soft-info {
      background:#eff6ff;
      border:1px solid #bfdbfe;
      color:#1d4ed8;
    }

    .badge-soft-success {
      background:#ecfdf5;
      border:1px solid #bbf7d0;
      color:#166534;
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

    .dashboard-logout-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      margin: 12px 20px 0 0;
      padding: 8px 13px;
      border: 1px solid #dc3545;
      border-radius: 8px;
      color: #dc3545 !important;
      background: #fff;
      font-weight: 600;
      text-decoration: none !important;
      transition: .2s ease;
    }

    .dashboard-logout-btn:hover {
      background: #dc3545;
      color: #fff !important;
      transform: translateY(-1px);
    }

    @media (max-width: 767.98px) {
      .filter-actions .btn {
        width: 100%;
        margin-bottom: 8px;
      }
    }

    @media (max-width: 575.98px) {
      .dashboard-logout-btn {
        width: 40px;
        height: 40px;
        padding: 0;
        margin-right: 12px;
      }

      .dashboard-logout-btn span {
        display: none;
      }
    }

    body.dark .summary-card,
    body.dark .filter-card {
      background: #1e293b;
      border-color: #334155;
    }

    body.dark .summary-label,
    body.dark .mini-note {
      color: #94a3b8;
    }

    body.dark .dashboard-logout-btn {
      background: rgba(220, 53, 69, .08);
      border-color: rgba(248, 113, 113, .65);
      color: #f87171 !important;
    }
  </style>
</head>

<body>
  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
    </div>

    <div class="header-right">
      <a
        href="../admin/logout.php"
        class="dashboard-logout-btn"
        title="Log out"
        aria-label="Log out"
      >
        <i class="dw dw-logout" aria-hidden="true"></i>
        <span>Log Out</span>
      </a>
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
              <h4>Activity Logs</h4>
            </div>
            <div class="text-secondary">
              View recorded system activities across all phases.
            </div>
          </div>
        </div>
      </div>

      <div class="row mb-30">
        <div class="col-xl-3 col-md-6 mb-3">
          <div class="summary-card">
            <div class="summary-number"><?= number_format($totalLoaded) ?></div>
            <div class="summary-label">Logs Loaded</div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
          <div class="summary-card">
            <div class="summary-number"><?= number_format($todayCount) ?></div>
            <div class="summary-label">Activities Today</div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
          <div class="summary-card">
            <div class="summary-number"><?= number_format($phaseCount) ?></div>
            <div class="summary-label">Phases with Logs</div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
          <div class="summary-card">
            <div class="summary-number"><?= number_format($moduleCount) ?></div>
            <div class="summary-label">Modules Recorded</div>
          </div>
        </div>
      </div>

      <div class="card-box pd-20 mb-30 filter-card">
        <div class="mb-3">
          <h5 class="mb-1">Filter Logs</h5>
          <div class="mini-note">
            Search by officer, action, module, phase, or activity details.
          </div>
        </div>

        <form method="GET" action="logs.php">
          <div class="row">
            <div class="col-lg-3 col-md-6 mb-3">
              <label>Search</label>
              <input
                type="text"
                name="q"
                class="form-control"
                value="<?= esc($search) ?>"
                placeholder="Name, action, details..."
              >
            </div>

            <div class="col-lg-2 col-md-6 mb-3">
              <label>Phase</label>
              <select name="phase" class="form-control">
                <?php foreach ($allowedPhases as $phase): ?>
                  <option
                    value="<?= esc($phase) ?>"
                    <?= $phaseFilter === $phase ? 'selected' : '' ?>
                  >
                    <?= esc($phase) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-lg-3 col-md-6 mb-3">
              <label>Module</label>
              <select name="module" class="form-control">
                <option value="">All Modules</option>

                <?php foreach ($moduleOptions as $module): ?>
                  <option
                    value="<?= esc($module) ?>"
                    <?= $moduleFilter === $module ? 'selected' : '' ?>
                  >
                    <?= esc(readableModule($module)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-lg-2 col-md-6 mb-3">
              <label>From</label>
              <input
                type="date"
                name="date_from"
                class="form-control"
                value="<?= esc($dateFrom) ?>"
              >
            </div>

            <div class="col-lg-2 col-md-6 mb-3">
              <label>To</label>
              <input
                type="date"
                name="date_to"
                class="form-control"
                value="<?= esc($dateTo) ?>"
              >
            </div>
          </div>

          <div class="filter-actions">
            <button type="submit" class="btn btn-primary">
              <i class="dw dw-search2"></i> Apply Filters
            </button>

            <a href="logs.php" class="btn btn-outline-secondary">
              Clear
            </a>
          </div>
        </form>
      </div>

      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
          <div>
            <h5 class="mb-1">System Activity</h5>
            <div class="mini-note">
              Showing up to 500 latest matching records.
            </div>
          </div>

          <span class="badge-soft badge-soft-info mt-2 mt-md-0">
            <?= number_format($totalLoaded) ?>
            result<?= $totalLoaded === 1 ? '' : 's' ?>
          </span>
        </div>

        <div class="table-wrap">
          <table class="table table-striped table-hover logs-table mb-0">
            <thead class="table-light">
              <tr>
                <th>Date & Time</th>
                <th>User</th>
                <th>Position</th>
                <th>Phase</th>
                <th>Module</th>
                <th>Action</th>
                <th>Details</th>
                <th>IP Address</th>
              </tr>
            </thead>

            <tbody>
              <?php if (!$logs): ?>
                <tr>
                  <td colspan="8" class="text-center text-secondary py-4">
                    No activity logs match the selected filters.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($logs as $log): ?>
                  <?php
                    $userName = trim((string)($log['full_name'] ?? ''));

                    if ($userName === '') {
                        $userName = !empty($log['admin_id'])
                            ? 'Admin #' . (int)$log['admin_id']
                            : 'System';
                    }

                    $position = trim((string)($log['position'] ?? ''));

                    if ($position === '') {
                        $position = (($log['role'] ?? '') === 'superadmin')
                            ? 'Superadmin'
                            : '—';
                    }
                  ?>
                  <tr>
                    <td>
                      <?= esc(date('M d, Y h:i A', strtotime((string)$log['created_at']))) ?>
                    </td>

                    <td>
                      <strong><?= esc($userName) ?></strong>

                      <?php if (!empty($log['email'])): ?>
                        <div class="mini-note"><?= esc($log['email']) ?></div>
                      <?php endif; ?>
                    </td>

                    <td>
                      <span class="badge-soft badge-soft-secondary">
                        <?= esc($position) ?>
                      </span>
                    </td>

                    <td>
                      <span class="badge-soft badge-soft-info">
                        <?= esc($log['phase'] ?: '—') ?>
                      </span>
                    </td>

                    <td>
                      <?= esc(readableModule($log['module_key'] ?? '')) ?>
                    </td>

                    <td>
                      <strong><?= esc($log['action']) ?></strong>
                    </td>

                    <td class="log-details">
                      <?= nl2br(esc($log['details'] ?? '')) ?>
                    </td>

                    <td>
                      <?= esc($log['ip_address'] ?: '—') ?>
                    </td>
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
  <script src="../admin/vendors/scripts/admin_theme.js"></script>
</body>
</html>
