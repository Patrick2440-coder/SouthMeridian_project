<?php
session_start();
require_once '../config/database.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
    header("Location: ../index.php");
    exit;
}




function esc($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$homeowner_id = (int)$_SESSION['homeowner_id'];

$stmt = $conn->prepare("SELECT * FROM homeowners WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $homeowner_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || ($user['status'] ?? '') !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
}

if ((int)($user['must_change_password'] ?? 0) === 1) {
    header("Location: homeowner_dashboard.php");
    exit;
}

$phase        = (string)$user['phase'];
$fullName     = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$initials     = strtoupper(substr($user['first_name'] ?? 'H', 0, 1) . substr($user['last_name'] ?? 'O', 0, 1));
$pageTitle    = "My Tenants • South Meridian Homes";
$activePage   = 'homeowner_tenant_register.php';

$parkingPages = [
    'homeowner_parking.php',
    'homeowner_parking_permit.php',
    'homeowner_parking_violations.php'
];
$parkingOpen = in_array($activePage, $parkingPages, true);
$tenantOpen  = true;
$chatOpen    = false;

$accessOpts = [
    'can_pay_dues'      => ['Pay Monthly Dues', 'cash-coin', 'Allow tenant to pay monthly dues online.'],
    'can_rent'          => ['Facility Rentals', 'calendar2-week', 'Allow tenant to book courts, clubhouse, etc.'],
    'can_parking'       => ['Parking Permits', 'car-front', 'Allow tenant to apply for a parking permit.'],
    'can_announcements' => ['Announcement Feed', 'megaphone', 'Allow tenant to view community announcements.'],
];

/* =========================
   ACTIVE TENANT COUNT
   ========================= */
$tenantCount = 0;
$tcnt = $conn->prepare("SELECT COUNT(*) FROM tenants WHERE homeowner_id = ? AND status = 'active'");
$tcnt->bind_param("i", $homeowner_id);
$tcnt->execute();
$tcnt->bind_result($tenantCount);
$tcnt->fetch();
$tcnt->close();

/* =========================
   CSRF
   ========================= */
if (empty($_SESSION['csrf_tenant_register'])) {
    $_SESSION['csrf_tenant_register'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_tenant_register'];

/* =========================
   POST / AJAX ACTIONS
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $postedCsrf = (string)($_POST['csrf'] ?? '');
    if ($postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'msg' => 'Your session token is no longer valid. Please refresh the page and try again.'
        ]);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'register_tenant') {
        $first_name     = trim($_POST['first_name'] ?? '');
        $middle_name    = trim($_POST['middle_name'] ?? '');
        $last_name      = trim($_POST['last_name'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $lease_start    = trim($_POST['lease_start'] ?? '');
        $lease_end      = trim($_POST['lease_end'] ?? '');
        $password_raw   = trim($_POST['password'] ?? '');

        $can_pay_dues = (int)($_POST['can_pay_dues'] ?? 0);
        $can_rent     = (int)($_POST['can_rent'] ?? 0);
        $can_parking  = (int)($_POST['can_parking'] ?? 0);
        $can_ann      = (int)($_POST['can_announcements'] ?? 0);

        if (
            $first_name === '' ||
            $last_name === '' ||
            $email === '' ||
            $contact_number === '' ||
            $password_raw === '' ||
            $lease_start === '' ||
            $lease_end === ''
        ) {
            echo json_encode(['ok' => false, 'msg' => 'All fields are required except middle name.']);
            exit;
        }

        if (!preg_match('/^[0-9]{11}$/', $contact_number)) {
            echo json_encode(['ok' => false, 'msg' => 'Contact number must be exactly 11 digits.']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid email address.']);
            exit;
        }

        if (strlen($password_raw) < 6) {
            echo json_encode(['ok' => false, 'msg' => 'Password must be at least 6 characters.']);
            exit;
        }

        if ($lease_end < $lease_start) {
            echo json_encode(['ok' => false, 'msg' => 'Lease end date must be later than lease start date.']);
            exit;
        }

        $chk = $conn->prepare("
            SELECT id FROM homeowners WHERE email = ?
            UNION
            SELECT id FROM tenants WHERE email = ?
        ");
        $chk->bind_param("ss", $email, $email);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();

        if ($exists) {
            echo json_encode(['ok' => false, 'msg' => 'Email is already in use by another account.']);
            exit;
        }

        $valid_id_path = null;

        if (!isset($_FILES['valid_id']) || empty($_FILES['valid_id']['name'])) {
            echo json_encode(['ok' => false, 'msg' => 'Valid ID is required.']);
            exit;
        }

        if ($_FILES['valid_id']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'msg' => 'Valid ID upload failed.']);
            exit;
        }

        if (!is_uploaded_file($_FILES['valid_id']['tmp_name'])) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid uploaded file.']);
            exit;
        }

        if ((int)$_FILES['valid_id']['size'] > 5 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'msg' => 'Valid ID must not exceed 5MB.']);
            exit;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $_FILES['valid_id']['tmp_name']) : '';
        if ($finfo) { finfo_close($finfo); }

        $allowedMime = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf'
        ];
        $mime = strtolower((string)$mime);

        if (!isset($allowedMime[$mime])) {
            echo json_encode(['ok' => false, 'msg' => 'Valid ID must be a JPG, PNG, or PDF file.']);
            exit;
        }

        $dir = dirname(__DIR__) . "/uploads/tenants/{$homeowner_id}/";
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            echo json_encode(['ok' => false, 'msg' => 'Failed to create upload folder.']);
            exit;
        }

        $fname = bin2hex(random_bytes(16)) . '_tenant_id.' . $allowedMime[$mime];
        $dest  = $dir . $fname;

        if (!move_uploaded_file($_FILES['valid_id']['tmp_name'], $dest)) {
            echo json_encode(['ok' => false, 'msg' => 'Failed to save uploaded file.']);
            exit;
        }

        $valid_id_path = "uploads/tenants/{$homeowner_id}/{$fname}";

        $hashed = password_hash($password_raw, PASSWORD_DEFAULT);
        $ls = $lease_start;
        $le = $lease_end;

        $ins = $conn->prepare("
            INSERT INTO tenants (
                homeowner_id, phase, house_lot_number,
                first_name, middle_name, last_name,
                email, password, contact_number, valid_id_path,
                can_pay_dues, can_rent, can_parking, can_announcements,
                lease_start, lease_end, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");

        $ins->bind_param(
            "isssssssssiiiiss",
            $homeowner_id,
            $user['phase'],
            $user['house_lot_number'],
            $first_name,
            $middle_name,
            $last_name,
            $email,
            $hashed,
            $contact_number,
            $valid_id_path,
            $can_pay_dues,
            $can_rent,
            $can_parking,
            $can_ann,
            $ls,
            $le
        );

        if ($ins->execute()) {
            echo json_encode(['ok' => true, 'msg' => 'Tenant registered successfully.']);
        } else {
            if (is_file($dest)) { @unlink($dest); }
            echo json_encode(['ok' => false, 'msg' => 'Failed to register tenant.']);
        }
        $ins->close();
        exit;
    }

    if ($action === 'delete_tenant') {
        $tid = (int)($_POST['tenant_id'] ?? 0);

        if ($tid <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid tenant selected.']);
            exit;
        }

        $getFile = $conn->prepare("SELECT valid_id_path FROM tenants WHERE id = ? AND homeowner_id = ? LIMIT 1");
        $getFile->bind_param("ii", $tid, $homeowner_id);
        $getFile->execute();
        $tenantRow = $getFile->get_result()->fetch_assoc();
        $getFile->close();

        if (!$tenantRow) {
            echo json_encode(['ok' => false, 'msg' => 'Tenant not found.']);
            exit;
        }

        $del = $conn->prepare("DELETE FROM tenants WHERE id = ? AND homeowner_id = ?");
        $del->bind_param("ii", $tid, $homeowner_id);
        $ok = $del->execute();
        $affected = $del->affected_rows;
        $del->close();

        if ($ok && $affected > 0) {
            if (!empty($tenantRow['valid_id_path'])) {
                $filePath = dirname(__DIR__) . '/' . ltrim($tenantRow['valid_id_path'], '/');
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
            }

            echo json_encode(['ok' => true, 'msg' => 'Tenant deleted successfully.']);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Failed to delete tenant.']);
        }
        exit;
    }

    if ($action === 'update_access') {
        $tid      = (int)($_POST['tenant_id'] ?? 0);
        $can_pay  = (int)($_POST['can_pay_dues'] ?? 0);
        $can_rent = (int)($_POST['can_rent'] ?? 0);
        $can_park = (int)($_POST['can_parking'] ?? 0);
        $can_ann  = (int)($_POST['can_announcements'] ?? 0);

        $upd = $conn->prepare("
            UPDATE tenants
            SET can_pay_dues = ?, can_rent = ?, can_parking = ?, can_announcements = ?
            WHERE id = ? AND homeowner_id = ?
        ");
        $upd->bind_param("iiiiii", $can_pay, $can_rent, $can_park, $can_ann, $tid, $homeowner_id);
        $ok = $upd->execute() && $upd->affected_rows >= 0;
        $upd->close();

        echo json_encode([
            'ok' => $ok,
            'msg' => $ok ? 'Tenant access updated successfully.' : 'Failed to update tenant access.'
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    exit;
}

/* =========================
   LOAD TENANTS
   ========================= */
$tenants = [];
$tq = $conn->prepare("SELECT * FROM tenants WHERE homeowner_id = ? ORDER BY registered_at DESC");
$tq->bind_param("i", $homeowner_id);
$tq->execute();
$tenants = $tq->get_result()->fetch_all(MYSQLI_ASSOC);
$tq->close();

$lat = $user['latitude'] ?? null;
$lng = $user['longitude'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= esc($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<script>
(function(){
    const saved = localStorage.getItem('hoa-theme');
    const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.classList.toggle('dark', saved === 'dark' || (!saved && systemDark));
})();
</script>

<style type="text/tailwindcss">
    @custom-variant dark (&:where(.dark, .dark *));
</style>
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
#coverMap{width:100%;height:100%;min-height:220px}.leaflet-container{font-family:inherit}
</style>
</head>

<body class="bg-slate-50 text-slate-900 antialiased transition-colors dark:bg-slate-950 dark:text-slate-100">
<div id="sidebarOverlay" class="fixed inset-0 z-50 hidden bg-slate-950/50 backdrop-blur-[1px] lg:hidden"></div>
<?php include 'homeowner_sidebar.php'; ?>

<div class="min-h-screen lg:ml-[280px]">
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
    <div class="mx-auto flex min-h-[72px] max-w-7xl items-center gap-3 px-4 sm:px-6">
        <button type="button" id="sidebarToggle" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-2xl text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 dark:focus:ring-emerald-950 lg:hidden" aria-label="Open menu"><i class="bi bi-list"></i></button>
        <a href="homeowner_dashboard.php" class="min-w-0">
            <div class="truncate text-base font-bold text-emerald-800 sm:text-lg dark:text-emerald-300">HOA Community</div>
            <div class="hidden text-xs font-medium text-slate-500 sm:block dark:text-slate-400">South Meridian Homes Salitran</div>
        </a>
        <div class="ml-auto flex items-center gap-2 sm:gap-3">
            <button type="button" id="themeToggle" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-xl text-slate-700 shadow-sm transition hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-emerald-300 dark:focus:ring-emerald-950" aria-label="Switch to dark mode" title="Switch to dark mode"><i id="themeIcon" class="bi bi-moon-stars-fill"></i></button>
            <div class="hidden text-right md:block">
                <div class="max-w-[220px] truncate text-sm font-bold text-slate-800 dark:text-slate-200"><?= esc($fullName) ?></div>
                <div class="text-xs font-medium text-slate-500 dark:text-slate-400"><?= esc($phase) ?></div>
            </div>
            <a href="logout.php" class="flex min-h-12 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700 sm:px-4 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-red-900 dark:hover:bg-red-950/40 dark:hover:text-red-300"><i class="bi bi-box-arrow-right text-lg"></i><span class="hidden sm:inline">Logout</span></a>
        </div>
    </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8">
    <section class="mb-6">
        <a href="homeowner_dashboard.php" class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-700 hover:text-emerald-800 dark:text-emerald-400 dark:hover:text-emerald-300"><i class="bi bi-arrow-left"></i>Dashboard</a>
        <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">Household Access</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-slate-100">My Tenants</h1>
                <p class="mt-2 max-w-2xl text-[15px] leading-6 text-slate-600 dark:text-slate-400">Register tenant accounts and control which HOA modules each tenant may access.</p>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <div class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-white px-3 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700"><i class="bi bi-house-door-fill text-emerald-700 dark:text-emerald-400"></i><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?></div>
                <button type="button" class="btn-open-register inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500"><i class="bi bi-person-plus-fill"></i>Register Tenant</button>
            </div>
        </div>
    </section>

    <section class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="relative min-h-[220px] overflow-hidden bg-slate-100 dark:bg-slate-800">
            <?php if (!empty($lat) && !empty($lng)): ?>
                <div id="coverMap" data-lat="<?= esc($lat) ?>" data-lng="<?= esc($lng) ?>"></div>
            <?php else: ?>
                <div class="flex min-h-[220px] items-center justify-center px-4 text-center"><div><i class="bi bi-geo-alt text-3xl text-slate-400 dark:text-slate-500"></i><p class="mt-2 text-sm font-semibold text-slate-500 dark:text-slate-400">No saved home location yet.</p></div></div>
            <?php endif; ?>
            <div class="absolute left-4 top-4 z-[500] rounded-xl bg-slate-950/75 px-3 py-2 text-xs font-bold text-white shadow-lg backdrop-blur">South Meridian Homes Salitran • <?= esc($phase) ?></div>
        </div>
    </section>

    <section class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-xl text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"><i class="bi bi-person-check-fill"></i></div>
            <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">Active Tenants</p><p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100"><?= (int)$tenantCount ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-100 text-xl text-blue-700 dark:bg-blue-950/60 dark:text-blue-300"><i class="bi bi-people-fill"></i></div>
            <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">Total Registered</p><p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100"><?= count($tenants) ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:col-span-2 xl:col-span-1 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-100 text-xl text-violet-700 dark:bg-violet-950/60 dark:text-violet-300"><i class="bi bi-house-door-fill"></i></div>
            <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">Registered Unit</p><p class="mt-1 break-words text-lg font-bold text-slate-900 dark:text-slate-100"><?= esc($user['house_lot_number'] ?? '') ?></p>
        </div>
    </section>

    <section class="mb-6 rounded-2xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
        <div class="flex items-start gap-3"><div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-lg text-blue-700 dark:bg-blue-950 dark:text-blue-300"><i class="bi bi-shield-check"></i></div><div><h2 class="font-bold text-blue-950 dark:text-blue-200">How tenant access works</h2><p class="mt-1 text-sm leading-6 text-blue-800 dark:text-blue-300">Each tenant gets a separate login. You decide which HOA modules the tenant can use, and you can change those permissions later.</p></div></div>
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
            <div><h2 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-slate-100"><i class="bi bi-people-fill text-emerald-700 dark:text-emerald-400"></i>Tenant Management</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">View tenant information, manage access, or remove an account.</p></div>
            <button type="button" class="btn-open-register inline-flex min-h-11 w-fit items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60"><i class="bi bi-person-plus-fill"></i>Add New</button>
        </div>
        <div class="p-4 sm:p-5">
            <?php if (empty($tenants)): ?>
                <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 px-5 py-12 text-center dark:border-slate-700">
                    <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-100 text-3xl text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"><i class="bi bi-people"></i></div>
                    <h3 class="mt-4 text-lg font-bold text-slate-900 dark:text-slate-100">No tenants registered yet</h3>
                    <p class="mt-2 max-w-md text-sm leading-6 text-slate-500 dark:text-slate-400">Register a tenant account for this unit and choose the HOA modules they may access.</p>
                    <button type="button" class="btn-open-register mt-5 inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-emerald-700 px-5 text-sm font-semibold text-white transition hover:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500"><i class="bi bi-person-plus-fill"></i>Register Tenant</button>
                </div>
            <?php else: ?>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <?php foreach ($tenants as $t): ?>
                        <?php
                        $tName = trim(($t['first_name'] ?? '') . ' ' . ($t['middle_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
                        $tInitials = strtoupper(substr($t['first_name'] ?? 'T',0,1) . substr($t['last_name'] ?? 'N',0,1));
                        $tenantJson = esc(json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $status = (string)($t['status'] ?? 'active');
                        ?>
                        <article class="flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/60 transition hover:border-slate-300 hover:shadow-md dark:border-slate-700 dark:bg-slate-800/40 dark:hover:border-slate-600">
                            <div class="p-4">
                                <div class="flex items-start gap-3">
                                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"><?= esc($tInitials) ?></div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-2"><div class="min-w-0"><h3 class="truncate font-bold text-slate-900 dark:text-slate-100"><?= esc($tName) ?></h3><p class="mt-1 truncate text-xs text-slate-500 dark:text-slate-400"><?= esc($t['email'] ?? '') ?></p></div><span class="inline-flex shrink-0 items-center rounded-lg px-2 py-1 text-[10px] font-bold ring-1 <?= tenant_status_classes($status) ?>"><?= esc(strtoupper($status)) ?></span></div>
                                    </div>
                                </div>
                                <?php if (!empty($t['contact_number'])): ?><div class="mt-4 flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400"><i class="bi bi-telephone-fill"></i><?= esc($t['contact_number']) ?></div><?php endif; ?>
                                <div class="mt-3 flex items-start gap-2 text-sm text-slate-600 dark:text-slate-400"><i class="bi bi-calendar3 mt-0.5"></i><span><?= esc(display_date($t['lease_start'] ?? null)) ?> → <?= esc(!empty($t['lease_end']) ? display_date($t['lease_end']) : 'Open-ended') ?></span></div>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <?php foreach ($accessOpts as $col => [$label,$icon,$desc]): ?><?php $on = !empty($t[$col]); ?>
                                        <span class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-[11px] font-bold ring-1 <?= $on ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900' : 'bg-slate-100 text-slate-500 ring-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700' ?>"><i class="bi bi-<?= esc($icon) ?>"></i><?= esc($label) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="mt-auto grid grid-cols-1 gap-2 border-t border-slate-200 bg-white p-3 sm:grid-cols-3 dark:border-slate-700 dark:bg-slate-900/70">
                                <button type="button" class="btn-view-tenant inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" data-tenant="<?= $tenantJson ?>"><i class="bi bi-eye"></i>View</button>
                                <button type="button" class="btn-edit-access inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-blue-200 px-3 text-xs font-semibold text-blue-700 transition hover:bg-blue-50 dark:border-blue-900 dark:text-blue-300 dark:hover:bg-blue-950/40" data-tenant="<?= $tenantJson ?>"><i class="bi bi-sliders"></i>Access</button>
                                <button type="button" class="btn-delete-tenant inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-red-200 px-3 text-xs font-semibold text-red-700 transition hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/40" data-tenant-id="<?= (int)$t['id'] ?>" data-tenant-name="<?= esc($tName) ?>"><i class="bi bi-trash"></i>Delete</button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <footer class="mt-8 border-t border-slate-200 py-6 text-center text-sm text-slate-500 dark:border-slate-800 dark:text-slate-500">© South Meridian Homes Salitran</footer>
</main>
</div>

<!-- Register tenant modal -->
<div id="registerModal" class="fixed inset-0 z-[200] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" aria-hidden="true">
    <div class="flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <div><h2 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-slate-100"><i class="bi bi-person-plus-fill text-emerald-700 dark:text-emerald-400"></i>Register Tenant</h2><p class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">Create a separate tenant login and choose which modules the tenant can access.</p></div>
            <button type="button" class="btn-close-register flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100" aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto p-5">
            <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"><i class="bi bi-shield-lock-fill mt-0.5 shrink-0"></i><span>The tenant will use their own email and password. They can only access modules you enable below.</span></div>
            <form id="tenantForm" enctype="multipart/form-data" autocomplete="off" class="mt-5">
                <input type="hidden" name="action" value="register_tenant"><input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                <h3 class="text-sm font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Personal Information</h3>
                <div class="mt-3 grid gap-4 md:grid-cols-3">
                    <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">First Name <span class="text-red-500">*</span></span><input type="text" name="first_name" maxlength="100" required class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-base text-slate-800 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-emerald-950"></label>
                    <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Middle Name</span><input type="text" name="middle_name" maxlength="100" class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-base text-slate-800 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-emerald-950"></label>
                    <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Last Name <span class="text-red-500">*</span></span><input type="text" name="last_name" maxlength="100" required class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-base text-slate-800 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-emerald-950"></label>
                    <label class="block md:col-span-2"><span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Email Address <span class="text-red-500">*</span></span><input type="email" name="email" required class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-base text-slate-800 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-emerald-950"></label>
                    <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Contact Number <span class="text-red-500">*</span></span><input type="text" id="tenantContact" name="contact_number" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" minlength="11" placeholder="09XXXXXXXXX" required class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-base text-slate-800 outline-none placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:ring-emerald-950"></label>
                    <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Password <span class="text-red-500">*</span></span><div class="relative"><input type="password" id="tenantPassword" name="password" minlength="6" required autocomplete="new-password" placeholder="Minimum 6 characters" class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 pr-12 text-base text-slate-800 outline-none placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:ring-emerald-950"><button type="button" id="toggleTenantPassword" class="absolute right-1 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-700" aria-label="Show password"><i id="tenantPasswordIcon" class="bi bi-eye"></i></button></div></label>
                    <label class="block md:col-span-2"><span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Valid ID <span class="text-red-500">*</span></span><input type="file" name="valid_id" accept=".jpg,.jpeg,.png,.pdf" required class="block min-h-12 w-full rounded-xl border border-slate-300 bg-white text-sm text-slate-600 file:mr-3 file:border-0 file:border-r file:border-slate-200 file:bg-slate-50 file:px-4 file:py-3 file:font-semibold file:text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:file:border-slate-700 dark:file:bg-slate-900 dark:file:text-slate-200"><span class="mt-1.5 block text-xs text-slate-500 dark:text-slate-400">JPG, PNG, or PDF. Maximum 5MB.</span></label>
                </div>

                <h3 class="mt-6 text-sm font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Lease Period</h3>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Lease Start <span class="text-red-500">*</span></span><input type="date" name="lease_start" required class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-base text-slate-800 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-emerald-950"></label>
                    <label class="block"><span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Lease End <span class="text-red-500">*</span></span><input type="date" name="lease_end" required class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-base text-slate-800 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-emerald-950"></label>
                </div>

                <h3 class="mt-6 text-sm font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Module Access</h3>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Choose which HOA features this tenant can use.</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <?php foreach ($accessOpts as $name => [$label,$icon,$desc]): ?>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-4 transition hover:border-emerald-200 hover:bg-emerald-50 dark:border-slate-700 dark:bg-slate-800/50 dark:hover:border-emerald-900 dark:hover:bg-emerald-950/30">
                            <input type="checkbox" name="<?= esc($name) ?>" checked class="mt-1 h-5 w-5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 dark:border-slate-600 dark:bg-slate-800">
                            <span class="min-w-0"><span class="flex items-center gap-2 font-bold text-slate-800 dark:text-slate-200"><i class="bi bi-<?= esc($icon) ?> text-emerald-700 dark:text-emerald-400"></i><?= esc($label) ?></span><span class="mt-1 block text-xs leading-5 text-slate-500 dark:text-slate-400"><?= esc($desc) ?></span></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div id="registerError" class="mt-4 hidden rounded-xl border border-red-200 bg-red-50 p-3 text-sm leading-6 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300"></div>
            </form>
        </div>
        <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end dark:border-slate-800 dark:bg-slate-950/40">
            <button type="button" class="btn-close-register min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">Cancel</button>
            <button type="button" id="registerBtn" class="min-h-11 rounded-xl bg-emerald-700 px-5 text-sm font-semibold text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-emerald-600 dark:hover:bg-emerald-500"><i class="bi bi-person-plus-fill mr-1"></i>Register Tenant</button>
        </div>
    </div>
</div>

<!-- Tenant info modal -->
<div id="tenantInfoModal" class="fixed inset-0 z-[210] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" aria-hidden="true">
    <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800"><h2 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-slate-100"><i class="bi bi-person-lines-fill text-emerald-700 dark:text-emerald-400"></i>Tenant Information</h2><button type="button" class="btn-close-tenant-info flex h-10 w-10 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"><i class="bi bi-x-lg"></i></button></div>
        <div class="min-h-0 flex-1 overflow-y-auto p-5">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ([['First Name','ti_first_name'],['Middle Name','ti_middle_name'],['Last Name','ti_last_name'],['Email Address','ti_email'],['Contact Number','ti_contact_number'],['Lease Start','ti_lease_start'],['Lease End','ti_lease_end'],['Status','ti_status'],['Module Access','ti_access']] as [$label,$id]): ?>
                    <div class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200 dark:bg-slate-800/60 dark:ring-slate-700"><p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500"><?= esc($label) ?></p><p id="<?= esc($id) ?>" class="mt-1 break-words text-sm font-semibold text-slate-800 dark:text-slate-200">—</p></div>
                <?php endforeach; ?>
            </div>
            <div class="mt-5"><h3 class="text-sm font-bold text-slate-800 dark:text-slate-200">Uploaded Valid ID</h3><div class="mt-2 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50"><div id="ti_valid_id_wrap" class="flex min-h-[180px] items-center justify-center overflow-hidden rounded-xl bg-white dark:bg-slate-900"></div><a href="#" target="_blank" rel="noopener noreferrer" id="ti_valid_id_link" class="mt-3 hidden min-h-11 items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 text-sm font-semibold text-emerald-700 hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60"><i class="bi bi-box-arrow-up-right"></i>Open File</a></div></div>
        </div>
        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 dark:border-slate-800 dark:bg-slate-950/40"><button type="button" class="btn-close-tenant-info min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">Close</button></div>
    </div>
</div>

<!-- Access modal -->
<div id="accessModal" class="fixed inset-0 z-[220] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" aria-hidden="true">
    <div class="w-full max-w-xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800"><div><h2 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-slate-100"><i class="bi bi-sliders text-blue-700 dark:text-blue-300"></i>Edit Tenant Access</h2><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Updating access for <strong id="accessTenantName"></strong></p></div><button type="button" class="btn-close-access flex h-10 w-10 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"><i class="bi bi-x-lg"></i></button></div>
        <div class="p-5"><input type="hidden" id="accessTenantId"><div class="space-y-3">
            <?php foreach ($accessOpts as $name => [$label,$icon,$desc]): ?>
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-4 hover:border-blue-200 hover:bg-blue-50 dark:border-slate-700 dark:bg-slate-800/50 dark:hover:border-blue-900 dark:hover:bg-blue-950/30"><input type="checkbox" id="acc_<?= esc($name) ?>" class="mt-1 h-5 w-5 rounded border-slate-300 text-blue-600 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800"><span><span class="flex items-center gap-2 font-bold text-slate-800 dark:text-slate-200"><i class="bi bi-<?= esc($icon) ?> text-blue-700 dark:text-blue-300"></i><?= esc($label) ?></span><span class="mt-1 block text-xs leading-5 text-slate-500 dark:text-slate-400"><?= esc($desc) ?></span></span></label>
            <?php endforeach; ?>
        </div></div>
        <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end dark:border-slate-800 dark:bg-slate-950/40"><button type="button" class="btn-close-access min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">Cancel</button><button type="button" id="saveAccessBtn" class="min-h-11 rounded-xl bg-blue-700 px-5 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-blue-600 dark:hover:bg-blue-500"><i class="bi bi-check-lg mr-1"></i>Save Access</button></div>
    </div>
</div>

<!-- Delete modal -->
<div id="deleteModal" class="fixed inset-0 z-[230] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" aria-hidden="true">
    <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <div class="p-5"><div class="flex h-12 w-12 items-center justify-center rounded-xl bg-red-100 text-xl text-red-700 dark:bg-red-950/60 dark:text-red-300"><i class="bi bi-trash-fill"></i></div><h2 class="mt-4 text-lg font-bold text-slate-900 dark:text-slate-100">Delete Tenant?</h2><p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">You are about to permanently delete <strong id="deleteTenantName" class="text-slate-900 dark:text-slate-100"></strong>. This action cannot be undone.</p></div>
        <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end dark:border-slate-800 dark:bg-slate-950/40"><button type="button" class="btn-close-delete min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">Cancel</button><button type="button" id="confirmDeleteBtn" class="min-h-11 rounded-xl bg-red-600 px-5 text-sm font-semibold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-red-600 dark:hover:bg-red-500"><i class="bi bi-trash-fill mr-1"></i>Delete Tenant</button></div>
    </div>
</div>

<!-- Notice modal -->
<div id="noticeModal" class="fixed inset-0 z-[240] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" aria-hidden="true">
    <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <div class="p-5"><div id="noticeIconWrap" class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-100 text-xl text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"><i id="noticeIcon" class="bi bi-check-circle-fill"></i></div><h2 id="noticeTitle" class="mt-4 text-lg font-bold text-slate-900 dark:text-slate-100">Success</h2><p id="noticeMessage" class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400"></p></div>
        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 dark:border-slate-800 dark:bg-slate-950/40"><button type="button" id="noticeOkBtn" class="min-h-11 rounded-xl bg-emerald-700 px-5 text-sm font-semibold text-white hover:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500">OK</button></div>
    </div>
</div>

<script>
const SELF = 'homeowner_tenant_register.php';
const CSRF_TOKEN = <?= json_encode($csrf) ?>;

function parseTenantData(el){try{return JSON.parse(el.dataset.tenant||'{}')}catch(e){return {}}}
function formatDateValue(v){if(!v)return '—';const p=String(v).split('-');if(p.length===3){const d=new Date(Number(p[0]),Number(p[1])-1,Number(p[2]));if(!Number.isNaN(d.getTime()))return d.toLocaleDateString('en-US',{year:'numeric',month:'short',day:'2-digit'});}return v;}
function resolveTenantFileUrl(path){if(!path)return '';if(/^https?:\/\//i.test(path))return path;return '../'+String(path).replace(/^\/+/, '');}
function openModal(m){if(!m)return;m.classList.remove('hidden');m.classList.add('flex');m.setAttribute('aria-hidden','false');document.body.classList.add('overflow-hidden');}
function closeModal(m){if(!m)return;m.classList.add('hidden');m.classList.remove('flex');m.setAttribute('aria-hidden','true');if(!document.querySelector('#registerModal.flex,#tenantInfoModal.flex,#accessModal.flex,#deleteModal.flex,#noticeModal.flex'))document.body.classList.remove('overflow-hidden');}
async function postFormData(fd){if(!fd.has('csrf'))fd.append('csrf',CSRF_TOKEN);const r=await fetch(SELF,{method:'POST',body:fd,credentials:'same-origin'});const t=await r.text();let d;try{d=JSON.parse(t)}catch(e){throw new Error('The server returned an invalid response.')}if(!r.ok)throw new Error(d.msg||'Request failed.');return d;}

const noticeModal=document.getElementById('noticeModal'),noticeTitle=document.getElementById('noticeTitle'),noticeMessage=document.getElementById('noticeMessage'),noticeIcon=document.getElementById('noticeIcon'),noticeIconWrap=document.getElementById('noticeIconWrap'),noticeOkBtn=document.getElementById('noticeOkBtn');let noticeCallback=null;
function showNotice(title,message,type='success',callback=null){noticeTitle.textContent=title||'Notice';noticeMessage.textContent=message||'';noticeCallback=typeof callback==='function'?callback:null;if(type==='error'){noticeIcon.className='bi bi-x-circle-fill';noticeIconWrap.className='flex h-12 w-12 items-center justify-center rounded-xl bg-red-100 text-xl text-red-700 dark:bg-red-950/60 dark:text-red-300';noticeOkBtn.className='min-h-11 rounded-xl bg-red-600 px-5 text-sm font-semibold text-white hover:bg-red-700 dark:bg-red-600 dark:hover:bg-red-500';}else{noticeIcon.className='bi bi-check-circle-fill';noticeIconWrap.className='flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-100 text-xl text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300';noticeOkBtn.className='min-h-11 rounded-xl bg-emerald-700 px-5 text-sm font-semibold text-white hover:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500';}openModal(noticeModal);}
noticeOkBtn?.addEventListener('click',()=>{closeModal(noticeModal);const cb=noticeCallback;noticeCallback=null;if(cb)cb();});noticeModal?.addEventListener('click',e=>{if(e.target===noticeModal)closeModal(noticeModal);});

const registerModal=document.getElementById('registerModal'),tenantForm=document.getElementById('tenantForm'),registerBtn=document.getElementById('registerBtn'),registerError=document.getElementById('registerError');
function openRegisterModal(){if(registerError){registerError.classList.add('hidden');registerError.textContent='';}openModal(registerModal);}
document.querySelectorAll('.btn-open-register').forEach(b=>b.addEventListener('click',openRegisterModal));document.querySelectorAll('.btn-close-register').forEach(b=>b.addEventListener('click',()=>closeModal(registerModal)));registerModal?.addEventListener('click',e=>{if(e.target===registerModal)closeModal(registerModal);});
document.getElementById('tenantContact')?.addEventListener('input',function(){this.value=this.value.replace(/[^0-9]/g,'').slice(0,11);});
(function(){const i=document.getElementById('tenantPassword'),b=document.getElementById('toggleTenantPassword'),ic=document.getElementById('tenantPasswordIcon');if(!i||!b||!ic)return;b.addEventListener('click',()=>{const show=i.type==='text';i.type=show?'password':'text';ic.className=show?'bi bi-eye':'bi bi-eye-slash';b.setAttribute('aria-label',show?'Show password':'Hide password');});})();
registerBtn?.addEventListener('click',async()=>{if(!tenantForm)return;if(!tenantForm.checkValidity()){tenantForm.reportValidity();return;}if(registerError){registerError.classList.add('hidden');registerError.textContent='';}registerBtn.disabled=true;const old=registerBtn.innerHTML;registerBtn.innerHTML='<i class="bi bi-arrow-repeat animate-spin mr-1"></i> Registering...';try{const fd=new FormData(tenantForm);['can_pay_dues','can_rent','can_parking','can_announcements'].forEach(k=>fd.set(k,tenantForm.elements[k]&&tenantForm.elements[k].checked?'1':'0'));const d=await postFormData(fd);if(!d.ok){registerError.textContent=d.msg||'Failed to register tenant.';registerError.classList.remove('hidden');return;}closeModal(registerModal);showNotice('Tenant Registered',d.msg||'Tenant registered successfully.','success',()=>location.reload());}catch(e){registerError.textContent=e.message||'An error occurred. Please try again.';registerError.classList.remove('hidden');}finally{registerBtn.disabled=false;registerBtn.innerHTML=old;}});

const tenantInfoModal=document.getElementById('tenantInfoModal');
document.querySelectorAll('.btn-view-tenant').forEach(button=>button.addEventListener('click',()=>{const t=parseTenantData(button);document.getElementById('ti_first_name').textContent=t.first_name||'—';document.getElementById('ti_middle_name').textContent=t.middle_name||'—';document.getElementById('ti_last_name').textContent=t.last_name||'—';document.getElementById('ti_email').textContent=t.email||'—';document.getElementById('ti_contact_number').textContent=t.contact_number||'—';document.getElementById('ti_lease_start').textContent=formatDateValue(t.lease_start);document.getElementById('ti_lease_end').textContent=t.lease_end?formatDateValue(t.lease_end):'Open-ended';document.getElementById('ti_status').textContent=t.status||'—';const a=[];if(Number(t.can_pay_dues||0)===1)a.push('Pay Monthly Dues');if(Number(t.can_rent||0)===1)a.push('Facility Rentals');if(Number(t.can_parking||0)===1)a.push('Parking Permits');if(Number(t.can_announcements||0)===1)a.push('Announcement Feed');document.getElementById('ti_access').textContent=a.length?a.join(', '):'No enabled access';const wrap=document.getElementById('ti_valid_id_wrap'),link=document.getElementById('ti_valid_id_link');wrap.innerHTML='';link.classList.add('hidden');link.classList.remove('inline-flex');link.href='#';if(t.valid_id_path){const url=resolveTenantFileUrl(t.valid_id_path),ext=String(t.valid_id_path).split('.').pop().toLowerCase();if(['jpg','jpeg','png','gif','webp'].includes(ext)){const img=document.createElement('img');img.src=url;img.alt='Valid ID Preview';img.className='max-h-[480px] w-full object-contain';wrap.appendChild(img);}else if(ext==='pdf'){const f=document.createElement('iframe');f.src=url;f.title='Valid ID Preview';f.className='h-[420px] w-full border-0';wrap.appendChild(f);}else{const x=document.createElement('div');x.className='px-4 py-10 text-center text-sm text-slate-500';x.textContent='Preview is not available for this file type.';wrap.appendChild(x);}link.href=url;link.classList.remove('hidden');link.classList.add('inline-flex');}else{const x=document.createElement('div');x.className='px-4 py-10 text-center text-sm text-slate-500';x.textContent='No uploaded valid ID found.';wrap.appendChild(x);}openModal(tenantInfoModal);}));
document.querySelectorAll('.btn-close-tenant-info').forEach(b=>b.addEventListener('click',()=>closeModal(tenantInfoModal)));tenantInfoModal?.addEventListener('click',e=>{if(e.target===tenantInfoModal)closeModal(tenantInfoModal);});

const accessModal=document.getElementById('accessModal'),saveAccessBtn=document.getElementById('saveAccessBtn');
document.querySelectorAll('.btn-edit-access').forEach(button=>button.addEventListener('click',()=>{const t=parseTenantData(button);document.getElementById('accessTenantId').value=t.id||'';document.getElementById('accessTenantName').textContent=(String(t.first_name||'')+' '+String(t.last_name||'')).trim();['can_pay_dues','can_rent','can_parking','can_announcements'].forEach(k=>{const cb=document.getElementById('acc_'+k);if(cb)cb.checked=Number(t[k]||0)===1;});openModal(accessModal);}));document.querySelectorAll('.btn-close-access').forEach(b=>b.addEventListener('click',()=>closeModal(accessModal)));accessModal?.addEventListener('click',e=>{if(e.target===accessModal)closeModal(accessModal);});
saveAccessBtn?.addEventListener('click',async()=>{const id=document.getElementById('accessTenantId').value;if(!id){showNotice('Unable to Save','Invalid tenant selected.','error');return;}saveAccessBtn.disabled=true;const old=saveAccessBtn.innerHTML;saveAccessBtn.innerHTML='<i class="bi bi-arrow-repeat animate-spin mr-1"></i> Saving...';try{const fd=new FormData();fd.append('action','update_access');fd.append('tenant_id',id);['can_pay_dues','can_rent','can_parking','can_announcements'].forEach(k=>fd.append(k,document.getElementById('acc_'+k).checked?'1':'0'));const d=await postFormData(fd);if(!d.ok){showNotice('Update Failed',d.msg||'Failed to update tenant access.','error');return;}closeModal(accessModal);showNotice('Access Updated',d.msg||'Tenant access updated successfully.','success',()=>location.reload());}catch(e){showNotice('Update Failed',e.message||'An error occurred while updating tenant access.','error');}finally{saveAccessBtn.disabled=false;saveAccessBtn.innerHTML=old;}});

const deleteModal=document.getElementById('deleteModal'),deleteTenantName=document.getElementById('deleteTenantName'),confirmDeleteBtn=document.getElementById('confirmDeleteBtn');let pendingDeleteTenantId=0;
document.querySelectorAll('.btn-delete-tenant').forEach(button=>button.addEventListener('click',()=>{pendingDeleteTenantId=Number(button.dataset.tenantId||0);deleteTenantName.textContent=button.dataset.tenantName||'this tenant';openModal(deleteModal);}));function closeDeleteModal(){pendingDeleteTenantId=0;closeModal(deleteModal);}document.querySelectorAll('.btn-close-delete').forEach(b=>b.addEventListener('click',closeDeleteModal));deleteModal?.addEventListener('click',e=>{if(e.target===deleteModal)closeDeleteModal();});
confirmDeleteBtn?.addEventListener('click',async()=>{if(pendingDeleteTenantId<=0)return;confirmDeleteBtn.disabled=true;const old=confirmDeleteBtn.innerHTML;confirmDeleteBtn.innerHTML='<i class="bi bi-arrow-repeat animate-spin mr-1"></i> Deleting...';try{const fd=new FormData();fd.append('action','delete_tenant');fd.append('tenant_id',pendingDeleteTenantId);const d=await postFormData(fd);if(!d.ok){showNotice('Delete Failed',d.msg||'Failed to delete tenant.','error');return;}closeDeleteModal();showNotice('Tenant Deleted',d.msg||'Tenant deleted successfully.','success',()=>location.reload());}catch(e){showNotice('Delete Failed',e.message||'An error occurred while deleting the tenant.','error');}finally{confirmDeleteBtn.disabled=false;confirmDeleteBtn.innerHTML=old;}});

document.addEventListener('keydown',e=>{if(e.key!=='Escape')return;for(const m of [noticeModal,deleteModal,accessModal,tenantInfoModal,registerModal]){if(m&&!m.classList.contains('hidden')){closeModal(m);break;}}});

(function(){const el=document.getElementById('coverMap');if(!el)return;const lat=parseFloat(el.dataset.lat||''),lng=parseFloat(el.dataset.lng||'');if(!Number.isFinite(lat)||!Number.isFinite(lng))return;const map=L.map('coverMap',{zoomControl:false,attributionControl:false,dragging:false,touchZoom:false,scrollWheelZoom:false,doubleClickZoom:false,boxZoom:false,keyboard:false}).setView([lat,lng],18);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:20}).addTo(map);L.marker([lat,lng]).addTo(map);setTimeout(()=>map.invalidateSize(),250);window.addEventListener('resize',()=>setTimeout(()=>map.invalidateSize(),200));})();

function initSidebarDropdown(buttonId,menuId,caretId){const b=document.getElementById(buttonId),m=document.getElementById(menuId),c=document.getElementById(caretId);if(!b||!m)return;b.addEventListener('click',()=>{const willOpen=m.classList.contains('hidden');m.classList.toggle('hidden');b.setAttribute('aria-expanded',willOpen?'true':'false');if(c)c.classList.toggle('rotate-180',willOpen);});}
initSidebarDropdown('sbParkingToggle','sbParkingMenu','sbParkingCaret');initSidebarDropdown('sbTenantToggle','sbTenantMenu','sbTenantCaret');

(function(){const sidebar=document.getElementById('sidebar'),overlay=document.getElementById('sidebarOverlay'),openButton=document.getElementById('sidebarToggle'),closeButton=document.getElementById('sidebarClose');if(!sidebar||!overlay||!openButton)return;function openSidebar(){sidebar.classList.remove('-translate-x-full');overlay.classList.remove('hidden');document.body.classList.add('overflow-hidden');}function closeSidebar(){sidebar.classList.add('-translate-x-full');overlay.classList.add('hidden');document.body.classList.remove('overflow-hidden');}openButton.addEventListener('click',openSidebar);closeButton?.addEventListener('click',closeSidebar);overlay.addEventListener('click',closeSidebar);sidebar.querySelectorAll('a').forEach(link=>link.addEventListener('click',()=>{if(window.innerWidth<1024)closeSidebar();}));window.addEventListener('resize',()=>{if(window.innerWidth>=1024){overlay.classList.add('hidden');document.body.classList.remove('overflow-hidden');}});})();

(function(){const toggle=document.getElementById('themeToggle'),icon=document.getElementById('themeIcon');if(!toggle||!icon)return;function update(){const dark=document.documentElement.classList.contains('dark');icon.className=dark?'bi bi-sun-fill':'bi bi-moon-stars-fill';toggle.setAttribute('aria-label',dark?'Switch to light mode':'Switch to dark mode');toggle.setAttribute('title',dark?'Switch to light mode':'Switch to dark mode');}toggle.addEventListener('click',()=>{const dark=document.documentElement.classList.toggle('dark');localStorage.setItem('hoa-theme',dark?'dark':'light');update();});update();})();
</script>
</body>
</html>