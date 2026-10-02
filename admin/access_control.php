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
| ACCESS CONTROL OWNERS
|--------------------------------------------------------------------------
| Per HOA policy, only these officer positions can manage officer/module
| permissions from the Admin side.
*/
$accessControllers = [
    'President',
    'Vice President',
    'Secretary'
];

if (
    empty($_SESSION['admin_id']) ||
    ($_SESSION['admin_role'] ?? '') !== 'admin'
) {
    header('Location: ../index.php');
    exit;
}

$adminId = (int)$_SESSION['admin_id'];

$stmt = $conn->prepare("
    SELECT
        id,
        email,
        full_name,
        phase,
        role,
        position
    FROM admins
    WHERE id = ?
      AND role = 'admin'
    LIMIT 1
");
$stmt->bind_param('i', $adminId);
$stmt->execute();
$currentAdmin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$currentAdmin) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$currentPosition = trim((string)($currentAdmin['position'] ?? ''));
$currentPhase = trim((string)($currentAdmin['phase'] ?? ''));

$_SESSION['position'] = $currentPosition;
$_SESSION['admin_phase'] = $currentPhase;
$_SESSION['phase'] = $currentPhase;

if (!in_array($currentPosition, $accessControllers, true)) {
    $_SESSION['admin_access_notice'] =
        'Access Control is available only to the President, Vice President, and Secretary.';
    header('Location: dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['access_control_csrf'])) {
    $_SESSION['access_control_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['access_control_csrf'];

/*
|--------------------------------------------------------------------------
| POSITION + DEFAULT PERMISSIONS
|--------------------------------------------------------------------------
*/
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

function getModules(mysqli $conn): array
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

function ensurePermissionsExist(
    mysqli $conn,
    array $positions,
    array $defaultPermissions
): void {
    $modules = getModules($conn);

    $stmt = $conn->prepare("
        INSERT INTO access_permissions
            (position, module_key, is_allowed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            is_allowed = is_allowed
    ");

    foreach ($positions as $position) {
        foreach ($modules as $module) {
            $moduleKey = (string)$module['module_key'];
            $allowed = (int)($defaultPermissions[$position][$moduleKey] ?? 0);

            $stmt->bind_param('ssi', $position, $moduleKey, $allowed);
            $stmt->execute();
        }
    }

    $stmt->close();
}

function loadMatrix(mysqli $conn): array
{
    $matrix = [];

    $res = $conn->query("
        SELECT position, module_key, is_allowed
        FROM access_permissions
    ");

    while ($row = $res->fetch_assoc()) {
        $matrix[$row['position']][$row['module_key']] =
            (int)$row['is_allowed'];
    }

    return $matrix;
}

function savePermissions(
    mysqli $conn,
    array $positions,
    array $modules
): void {
    $stmt = $conn->prepare("
        INSERT INTO access_permissions
            (position, module_key, is_allowed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            is_allowed = VALUES(is_allowed)
    ");

    foreach ($positions as $position) {
        foreach ($modules as $module) {
            $moduleKey = (string)$module['module_key'];
            $field = 'perm_' . md5($position . '|' . $moduleKey);

            /*
             * Dashboard access is kept enabled for all officer positions.
             * It avoids creating an account that can log in but has nowhere
             * to land after authentication.
             */
            if ($moduleKey === 'dashboard') {
                $allowed = 1;
            } else {
                $allowed = isset($_POST[$field]) ? 1 : 0;
            }

            $stmt->bind_param('ssi', $position, $moduleKey, $allowed);
            $stmt->execute();
        }
    }

    $stmt->close();
}

function resetPermissionsToDefaults(
    mysqli $conn,
    array $positions,
    array $modules,
    array $defaultPermissions
): void {
    $stmt = $conn->prepare("
        INSERT INTO access_permissions
            (position, module_key, is_allowed)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            is_allowed = VALUES(is_allowed)
    ");

    foreach ($positions as $position) {
        foreach ($modules as $module) {
            $moduleKey = (string)$module['module_key'];
            $allowed = (int)($defaultPermissions[$position][$moduleKey] ?? 0);

            if ($moduleKey === 'dashboard') {
                $allowed = 1;
            }

            $stmt->bind_param('ssi', $position, $moduleKey, $allowed);
            $stmt->execute();
        }
    }

    $stmt->close();
}

ensurePermissionsExist($conn, $positions, $defaultPermissions);

$modules = getModules($conn);
$success = '';
$error = '';

if (!empty($_SESSION['access_control_success'])) {
    $success = (string)$_SESSION['access_control_success'];
    unset($_SESSION['access_control_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf_token'] ?? '');

    if (
        $postedCsrf === '' ||
        !hash_equals($csrfToken, $postedCsrf)
    ) {
        $error = 'Your session token changed. Reload the page and try again.';
    } else {
        $conn->begin_transaction();

        try {
            if (isset($_POST['save_access_control'])) {
                savePermissions($conn, $positions, $modules);
                $message = 'Access permissions updated.';
            } elseif (isset($_POST['reset_defaults'])) {
                resetPermissionsToDefaults(
                    $conn,
                    $positions,
                    $modules,
                    $defaultPermissions
                );
                $message = 'Default permissions restored.';
            } else {
                throw new RuntimeException('No valid action was selected.');
            }

            $conn->commit();

            $_SESSION['access_control_success'] = $message;
            header('Location: access_control.php');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'Unable to update access permissions.';
            error_log(
                'Access control update failed for admin ID ' .
                $adminId . ': ' . $e->getMessage()
            );
        }
    }
}

$matrix = loadMatrix($conn);

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

$displayName = trim((string)($currentAdmin['full_name'] ?? ''));
if ($displayName === '') {
    $displayName = (string)($currentAdmin['email'] ?? 'Officer');
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
    <title>Access Control • South Meridian HOA</title>


    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

    <style>
        .summary-card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 16px;
            background: #fff;
            height: 100%;
        }

        .summary-number {
            font-size: 26px;
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

        .matrix-table .module-col {
            min-width: 220px;
            position: sticky;
            left: 0;
            z-index: 3;
            background: #fff;
            text-align: left;
            font-weight: 700;
        }

        .matrix-table thead .module-col {
            z-index: 5;
            background: #f8fafc;
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

        .perm-switch:disabled {
            cursor: not-allowed;
            opacity: .7;
        }

        .badge-soft {
            display: inline-block;
            padding: .35rem .6rem;
            border-radius: 999px;
            font-weight: 800;
            font-size: 12px;
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

        .badge-soft-secondary {
            background:#f1f5f9;
            border:1px solid #cbd5e1;
            color:#475569;
        }

        .mini-note {
            color: #64748b;
            font-size: 13px;
        }

        .top-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .dashboard-logout-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 98px;
            min-height: 40px;
            margin: 0 18px 0 10px;
            padding: 8px 14px;
            border: 1px solid #fecaca;
            border-radius: 11px;
            background: #fff;
            color: #b91c1c !important;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .06);
            font-size: 12px;
            line-height: 1;
            font-weight: 800;
            text-decoration: none !important;
            white-space: nowrap;
        }

        .dashboard-logout-btn:hover,
        .dashboard-logout-btn:focus {
            border-color: #ef4444;
            background: #fef2f2;
            color: #991b1b !important;
            text-decoration: none !important;
        }

        html.dark .dashboard-logout-btn {
            border-color: rgba(248, 113, 113, .30);
            background: rgba(127, 29, 29, .16);
            color: #fca5a5 !important;
            box-shadow: none;
        }

        html.dark .summary-card,
        html.dark .matrix-wrap,
        html.dark .matrix-table .module-col {
            background: var(--admin-surface, #1f2937) !important;
            border-color: var(--admin-border, #334155) !important;
            color: var(--admin-text, #f8fafc) !important;
        }

        html.dark .matrix-table thead th,
        html.dark .matrix-table thead .module-col {
            background: var(--admin-surface-2, #273449) !important;
            color: var(--admin-text, #f8fafc) !important;
            border-color: var(--admin-border, #334155) !important;
        }

        html.dark .matrix-table tbody tr:hover td,
        html.dark .matrix-table tbody tr:hover td.module-col {
            background: var(--admin-surface-2, #273449) !important;
        }

        html.dark .summary-label,
        html.dark .mini-note {
            color: var(--admin-muted, #94a3b8) !important;
        }

        @media (max-width: 575.98px) {
            .dashboard-logout-btn {
                width: 40px;
                min-width: 40px;
                height: 40px;
                min-height: 40px;
                margin: 0 10px 0 6px;
                padding: 0;
                border-radius: 10px;
            }

            .dashboard-logout-btn span {
                display: none;
            }
        }
    </style>

    <link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">

    <script>
    (function () {
        try {
            const savedTheme = localStorage.getItem('hoa-theme');
            const dark =
                savedTheme === 'dark' ||
                (
                    !savedTheme &&
                    window.matchMedia &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches
                );

            document.documentElement.classList.toggle('dark', dark);
        } catch (e) {}
    })();
    </script>
</head>

<body>

<div class="header">
    <div class="header-left">
        <div class="menu-icon dw dw-menu"></div>
    </div>

    <div class="header-right">
        <div class="admin-theme-switch">
            <button
                type="button"
                id="themeToggle"
                class="admin-theme-toggle"
                aria-label="Switch theme"
                title="Switch theme"
            >
                <span id="themeIcon">☾</span>
            </button>
        </div>

 

        <a
            href="logout.php"
            class="dashboard-logout-btn"
            title="Log out"
            aria-label="Log out"
        >
            <i class="dw dw-logout" aria-hidden="true"></i>
            <span>Log Out</span>
        </a>
    </div>
</div>

<?php include 'sidebar.php'; ?>

<div class="mobile-menu-overlay"></div>

<div class="main-container">
    <div class="pd-ltr-20">

        <div class="page-header mb-20">
            <div class="row">
                <div class="col-md-12 col-sm-12">
                    <div class="title">
                        <h4>Access Control</h4>
                    </div>
                    <div class="text-secondary">
                        Manage officer module permissions.
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8 col-md-12 mb-30">
                <div class="card-box pd-20 height-100-p mb-20">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <img src="vendors/images/banner-img.png" alt="">
                        </div>

                        <div class="col-md-8">
                            <h4 class="font-20 weight-500 mb-10 text-capitalize">
                                <div class="weight-600 font-30 text-blue">
                                    Officer Permissions
                                </div>
                            </h4>

                            <p class="font-18 max-width-600 mb-0">
                                President, Vice President, and Secretary can manage
                                which modules each HOA officer position can access.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-12 mb-30">
                <div class="card-box pd-20 height-100-p">
                    <h4 class="h5 mb-3">Current Controller</h4>

                    <div class="mb-2 d-flex justify-content-between">
                        <span class="text-secondary">Position</span>
                        <span class="badge-soft badge-soft-success">
                            <?= esc($currentPosition) ?>
                        </span>
                    </div>

                    <div class="mb-2 d-flex justify-content-between">
                        <span class="text-secondary">Phase</span>
                        <span class="badge-soft badge-soft-info">
                            <?= esc($currentPhase) ?>
                        </span>
                    </div>

                    <div class="mini-note mt-3">
                        Permission profiles are currently shared by officer position.
                    </div>
                </div>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= esc($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= esc($error) ?>
            </div>
        <?php endif; ?>

        <div class="card-box mb-30 pd-20">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                <div>
                    <h5 class="mb-1">Officer Module Access</h5>
                    <div class="mini-note">
                        Dashboard access stays enabled so every officer account has a valid landing page.
                    </div>
                </div>

                <div class="top-actions mt-2 mt-md-0">
                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-toggle="modal"
                        data-target="#resetPermissionsModal"
                    >
                        Reset Defaults
                    </button>

                    <button
                        type="submit"
                        form="accessControlForm"
                        name="save_access_control"
                        value="1"
                        class="btn btn-primary"
                    >
                        Save Changes
                    </button>
                </div>
            </div>

            <form method="POST" id="accessControlForm">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= esc($csrfToken) ?>"
                >

                <div class="matrix-wrap">
                    <table class="table table-hover matrix-table">
                        <thead>
                            <tr>
                                <th class="module-col">Module</th>

                                <?php foreach ($positions as $position): ?>
                                    <th><?= esc($position) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($modules as $module): ?>
                                <?php
                                    $moduleKey = (string)$module['module_key'];
                                    $moduleName = (string)$module['module_name'];
                                ?>
                                <tr>
                                    <td class="module-col">
                                        <?= esc($moduleName) ?>
                                    </td>

                                    <?php foreach ($positions as $position): ?>
                                        <?php
                                            $field =
                                                'perm_' .
                                                md5($position . '|' . $moduleKey);

                                            $checked =
                                                !empty(
                                                    $matrix[$position][$moduleKey]
                                                );

                                            $lockedDashboard =
                                                $moduleKey === 'dashboard';
                                        ?>
                                        <td>
                                            <input
                                                type="checkbox"
                                                class="perm-switch"
                                                name="<?= esc($field) ?>"
                                                value="1"
                                                <?= $checked ? 'checked' : '' ?>
                                                <?= $lockedDashboard ? 'disabled' : '' ?>
                                                aria-label="<?= esc($moduleName . ' - ' . $position) ?>"
                                            >

                                            <?php if ($lockedDashboard): ?>
                                                <div class="mini-note mt-1">
                                                    Required
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>

        <div class="row mb-30">
            <?php foreach ($positions as $position): ?>
                <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
                    <div class="summary-card">
                        <div class="summary-number">
                            <?= (int)($summaryCounts[$position] ?? 0) ?>
                        </div>

                        <div class="summary-label">
                            <?= esc($position) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="footer-wrap pd-20 mb-20 card-box">
            © Copyright South Meridian Homes All Rights Reserved
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="resetPermissionsModal"
    tabindex="-1"
    role="dialog"
    aria-labelledby="resetPermissionsModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="resetPermissionsModalLabel">
                    Reset Access Permissions
                </h5>

                <button
                    type="button"
                    class="close"
                    data-dismiss="modal"
                    aria-label="Close"
                >
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">
                Restore the default module permissions for all officer positions?
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-light"
                    data-dismiss="modal"
                >
                    Cancel
                </button>

                <button
                    type="button"
                    class="btn btn-danger"
                    id="confirmResetPermissions"
                >
                    Reset Defaults
                </button>
            </div>
        </div>
    </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="vendors/scripts/admin_theme.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const resetButton = document.getElementById('confirmResetPermissions');
    const form = document.getElementById('accessControlForm');

    if (resetButton && form) {
        resetButton.addEventListener('click', function () {
            let input = form.querySelector('input[name="reset_defaults"]');

            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'reset_defaults';
                input.value = '1';
                form.appendChild(input);
            }

            form.submit();
        });
    }
});
</script>

</body>
</html>
