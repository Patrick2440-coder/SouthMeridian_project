<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================
   AUTH GUARD
   ========================= */
if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)
) {
    echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
    exit;
}

/* =========================
   DB CONNECTION
   ========================= */
if (!isset($conn) || !($conn instanceof mysqli)) {
    $db_host = "localhost";
    $db_user = "u972459197_patrick";
    $db_pass = "Idle2440";
    $db_name = "u972459197_south_meridian";

    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
}

/* =========================
   HELPERS
   ========================= */
if (!function_exists('esc')) {
    function esc($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('nfmt')) {
    function nfmt($n) {
        return number_format((float)$n, 0);
    }
}

if (!function_exists('money')) {
    function money($n) {
        return number_format((float)$n, 2);
    }
}

/* =========================
   ADMIN INFO
   ========================= */
$adminId   = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = (string)($_SESSION['admin_role'] ?? '');

$stmt = $conn->prepare("
    SELECT id, email, full_name, phase, role, position
    FROM admins
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$me) {
    session_destroy();
    echo "<script>alert('Session error. Please login again.'); window.location='index.php';</script>";
    exit;
}

$adminId       = (int)($me['id'] ?? 0);
$adminEmail    = (string)($me['email'] ?? '');
$adminName     = trim((string)($me['full_name'] ?? ''));
$myPhase       = (string)($me['phase'] ?? 'Phase 1');
$adminRole     = (string)($me['role'] ?? $adminRole);
$adminPosition = trim((string)($me['position'] ?? ''));

/* keep session fresh */
$_SESSION['admin_id']       = $adminId;
$_SESSION['admin_role']     = $adminRole;
$_SESSION['admin_email']    = $adminEmail;
$_SESSION['admin_name']     = $adminName;
$_SESSION['admin_phase']    = $myPhase;
$_SESSION['admin_position'] = $adminPosition;
$_SESSION['phase']          = $myPhase;
$_SESSION['position']       = $adminPosition;

/* =========================
   MODULES
   ========================= */
$allModules = [];
$moduleLabels = [];

$modRes = $conn->query("
    SELECT module_key, module_name
    FROM access_modules
    ORDER BY sort_order ASC, module_name ASC
");

if ($modRes) {
    while ($row = $modRes->fetch_assoc()) {
        $key = (string)($row['module_key'] ?? '');
        $name = (string)($row['module_name'] ?? $key);

        if ($key !== '') {
            $allModules[] = $key;
            $moduleLabels[$key] = $name;
        }
    }
}

/* fallback in case table has missing rows */
$fallbackModules = [
    'dashboard',
    'homeowner_management',
    'user_management',
    'announcements',
    'complaints',
    'finance',
    'parking',
    'community',
    'activity_log',
    'settings',
    'voting_management',
];

foreach ($fallbackModules as $fallbackKey) {
    if (!in_array($fallbackKey, $allModules, true)) {
        $allModules[] = $fallbackKey;
        if (!isset($moduleLabels[$fallbackKey])) {
            $moduleLabels[$fallbackKey] = ucwords(str_replace('_', ' ', $fallbackKey));
        }
    }
}

/* =========================
   PERMISSIONS
   ========================= */
$permissions = [];

/* default all modules = false */
foreach ($allModules as $moduleKey) {
    $permissions[$moduleKey] = false;
}

/* superadmin = full access */
if ($adminRole === 'superadmin' || $adminPosition === 'Superadmin') {
    foreach ($allModules as $moduleKey) {
        $permissions[$moduleKey] = true;
    }
} else {
    $stmt = $conn->prepare("
        SELECT module_key, is_allowed
        FROM access_permissions
        WHERE position = ?
    ");
    $stmt->bind_param("s", $adminPosition);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $moduleKey = (string)($row['module_key'] ?? '');
        $isAllowed = ((int)($row['is_allowed'] ?? 0) === 1);

        if ($moduleKey !== '') {
            $permissions[$moduleKey] = $isAllowed;
        }
    }
    $stmt->close();
}

/* =========================
   ACCESS HELPERS
   ========================= */
if (!function_exists('canAccess')) {
    function canAccess($key) {
        global $permissions, $adminRole, $adminPosition;

        if ($adminRole === 'superadmin' || $adminPosition === 'Superadmin') {
            return true;
        }

        return !empty($permissions[$key]);
    }
}

if (!function_exists('requireAccess')) {
    function requireAccess($key, $redirect = 'dashboard.php') {
        if (!canAccess($key)) {
            echo "<script>alert('Access denied.'); window.location='" . esc($redirect) . "';</script>";
            exit;
        }
    }
}

if (!function_exists('getAllowedModules')) {
    function getAllowedModules() {
        global $permissions, $adminRole, $adminPosition;

        if ($adminRole === 'superadmin' || $adminPosition === 'Superadmin') {
            return array_keys($permissions);
        }

        $allowed = [];
        foreach ($permissions as $key => $allowedFlag) {
            if ($allowedFlag) {
                $allowed[] = $key;
            }
        }
        return $allowed;
    }
}

if (!function_exists('getModuleLabel')) {
    function getModuleLabel($key) {
        global $moduleLabels;
        return $moduleLabels[$key] ?? ucwords(str_replace('_', ' ', (string)$key));
    }
}
?>