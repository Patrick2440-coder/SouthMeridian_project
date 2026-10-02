<?php
session_start();
require_once '../config/database.php';
require_once 'admin_access.php';
/*
|--------------------------------------------------------------------------
| AUTH / CURRENT ADMIN
|--------------------------------------------------------------------------
|
| Validate the authenticated admin using admin_id + the admins table.
| Do not depend on $_SESSION['admin_role'] for a second redirect because
| that session value can become stale while the authenticated session
| is still valid.
|
*/
$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) {
    header('Location: ../index.php');
    exit;
}
$stmt = $conn->prepare("
    SELECT email, full_name, phase, role
    FROM admins
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param('i', $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$me) {
    header('Location: ../index.php');
    exit;
}
$adminRole = strtolower(trim((string)($me['role'] ?? '')));
if ($adminRole === 'superadmin') {
    http_response_code(403);
    exit('Superadmin cannot access this module.');
}
if ($adminRole !== 'admin') {
    header('Location: ../index.php');
    exit;
}
function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
$adminName = trim((string)($me['full_name'] ?? ''));
$myPhase = (string)($me['phase'] ?? 'Phase 1');
$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true)
    ? $myPhase
    : 'Phase 1';

/*
|--------------------------------------------------------------------------
| CCTV ARCHIVE STORAGE
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['cctv_clip_csrf'])) {
    $_SESSION['cctv_clip_csrf'] = bin2hex(random_bytes(32));
}
$cctvClipCsrf = (string)$_SESSION['cctv_clip_csrf'];
$clipsTableReady = false;
$clipsTableProblem = '';
$tableCheck = $conn->query("SHOW TABLES LIKE 'cctv_saved_clips'");
if ($tableCheck) {
    $tableExists = $tableCheck->num_rows > 0;
    $tableCheck->free();
    if ($tableExists) {
        $requiredColumns = [
            'id',
            'phase',
            'camera_id',
            'camera_name',
            'camera_location',
            'source_type',
            'source_video_id',
            'clip_title',
            'notes',
            'start_seconds',
            'end_seconds',
            'duration_seconds',
            'file_path',
            'recorded_by_admin_id',
            'created_at',
        ];
        $existingColumns = [];
        $columnResult = $conn->query(
            "SHOW COLUMNS FROM cctv_saved_clips"
        );
        if ($columnResult) {
            while ($column = $columnResult->fetch_assoc()) {
                $existingColumns[] =
                    (string)($column['Field'] ?? '');
            }
            $columnResult->free();
        }
        $missingColumns = array_values(
            array_diff($requiredColumns, $existingColumns)
        );
        if (!$missingColumns) {
            $clipsTableReady = true;
        } else {
            $clipsTableProblem =
                'Missing database column(s): ' .
                implode(', ', $missingColumns);
        }
    } else {
        $clipsTableProblem =
            'The cctv_saved_clips table does not exist.';
    }
} else {
    $clipsTableProblem =
        'The CCTV clip table could not be checked.';
}

function cctv_archive_json(
    bool $ok,
    string $message,
    array $extra = []
): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    $payload = json_encode(
        array_merge(
            [
                'ok' => $ok,
                'message' => $message,
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    echo $payload !== false
        ? $payload
        : '{"ok":false,"message":"Unable to create server response."}';
    exit;
}

/*
|--------------------------------------------------------------------------
| DELETE CLIP
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['cctv_archive_action'])
) {
    if (ob_get_level() === 0) {
        ob_start();
    }
    if (!$clipsTableReady) {
        cctv_archive_json(
            false,
            $clipsTableProblem !== ''
                ? $clipsTableProblem
                : 'CCTV clip storage is unavailable.'
        );
    }
    $csrf = (string)($_POST['csrf'] ?? '');
    if (
        $csrf === '' ||
        !hash_equals($cctvClipCsrf, $csrf)
    ) {
        cctv_archive_json(
            false,
            'Your session has expired. Refresh the page and try again.'
        );
    }
    $action = trim((string)($_POST['cctv_archive_action'] ?? ''));
    if ($action === 'delete_clip') {
        $clipId = (int)($_POST['clip_id'] ?? 0);
        if ($clipId <= 0) {
            cctv_archive_json(false, 'Invalid saved CCTV clip.');
        }
        try {
            $stmt = $conn->prepare("
                DELETE FROM cctv_saved_clips
                WHERE id = ?
                  AND phase = ?
                LIMIT 1
            ");
            if (!$stmt) {
                throw new RuntimeException($conn->error);
            }
            $stmt->bind_param('is', $clipId, $phase);
            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->error);
            }
            $deleted = $stmt->affected_rows > 0;
            $stmt->close();
            if (!$deleted) {
                cctv_archive_json(
                    false,
                    'The saved CCTV clip could not be found.'
                );
            }
            cctv_archive_json(
                true,
                'Saved CCTV clip deleted.'
            );
        } catch (Throwable $e) {
            error_log(
                'CCTV archive delete failed: ' .
                $e->getMessage()
            );
            cctv_archive_json(
                false,
                'The CCTV clip could not be deleted because of a database error.'
            );
        }
    }
    cctv_archive_json(false, 'Unsupported CCTV archive action.');
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$search = trim((string)($_GET['q'] ?? ''));
$cameraFilter = trim((string)($_GET['camera'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$cameraOptions = [];
$savedClips = [];
$totalClips = 0;
if ($clipsTableReady) {
    $stmt = $conn->prepare("
        SELECT DISTINCT camera_id, camera_name
        FROM cctv_saved_clips
        WHERE phase = ?
        ORDER BY camera_name, camera_id
    ");
    $stmt->bind_param('s', $phase);
    $stmt->execute();
    $cameraOptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $where = ['c.phase = ?'];
    $types = 's';
    $params = [$phase];
    if ($search !== '') {
        $where[] = "(
            c.clip_title LIKE ?
            OR c.notes LIKE ?
            OR c.camera_name LIKE ?
            OR c.camera_location LIKE ?
        )";
        $like = '%' . $search . '%';
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }
    if ($cameraFilter !== '') {
        $where[] = 'c.camera_id = ?';
        $types .= 's';
        $params[] = $cameraFilter;
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(c.created_at) >= ?';
        $types .= 's';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(c.created_at) <= ?';
        $types .= 's';
        $params[] = $dateTo;
    }
    $sql = "
        SELECT
            c.id,
            c.phase,
            c.camera_id,
            c.camera_name,
            c.camera_location,
            c.source_type,
            c.source_video_id,
            c.clip_title,
            c.notes,
            c.start_seconds,
            c.end_seconds,
            c.duration_seconds,
            c.file_path,
            c.recorded_by_admin_id,
            c.created_at,
            a.full_name AS recorded_by_name
        FROM cctv_saved_clips c
        LEFT JOIN admins a
          ON a.id = c.recorded_by_admin_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.created_at DESC, c.id DESC
        LIMIT 500
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $savedClips = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM cctv_saved_clips
        WHERE phase = ?
    ");
    $stmt->bind_param('s', $phase);
    $stmt->execute();
    $totalClips = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>HOA-ADMIN | CCTV Archive</title>
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1, maximum-scale=1"
    >
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet"
    >
    <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">
    <style>
        .archive-summary-card {
            height: 100%;
        }
        .archive-summary-label {
            color: #64748b;
            font-weight: 700;
        }
        .archive-summary-value {
            margin-top: 4px;
            font-size: 28px;
            line-height: 1.1;
            font-weight: 800;
        }
        .clip-time-code {
            display: inline-flex;
            align-items: center;
            padding: 5px 8px;
            border-radius: 8px;
            background: #f1f5f9;
            color: #334155;
            font-family: Consolas, "Courier New", monospace;
            font-size: 12px;
            font-weight: 700;
        }
        .clip-note {
            max-width: 340px;
            white-space: normal;
            word-break: break-word;
        }
        .archive-filter-card {
            border-radius: 12px;
        }
        .clip-playback-stage {
            min-height: 300px;
            background: #020617;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .clip-playback-stage iframe,
        .clip-playback-stage video {
            display: block;
            width: 100%;
            aspect-ratio: 16 / 9;
            border: 0;
            background: #020617;
        }
        .clip-loading-message {
            color: #cbd5e1;
            font-size: 13px;
        }
        .playback-meta {
            padding: 12px 16px;
            border-top: 1px solid #e5e7eb;
            font-size: 13px;
        }
        .archive-empty {
            padding: 44px 20px;
            text-align: center;
            color: #64748b;
        }
        .archive-empty-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            background: #eff6ff;
            color: #2563eb;
            font-size: 28px;
        }
        .cctv-ui-toast {
            position: fixed;
            top: 82px;
            right: 22px;
            z-index: 10050;
            width: min(360px, calc(100vw - 32px));
            padding: 14px 16px;
            border-radius: 12px;
            color: #fff;
            box-shadow: 0 14px 34px rgba(15,23,42,.22);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transform: translateY(-10px);
            transition:
                opacity .22s ease,
                visibility .22s ease,
                transform .22s ease;
        }
        .cctv-ui-toast.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            transform: translateY(0);
        }
        .cctv-ui-toast.success { background: #15803d; }
        .cctv-ui-toast.warning { background: #b45309; }
        .cctv-ui-toast.danger  { background: #b91c1c; }
        .cctv-ui-toast.info    { background: #1d4ed8; }
        .cctv-ui-toast-title {
            display: block;
            margin-bottom: 2px;
            font-size: 13px;
            font-weight: 800;
        }
        .cctv-ui-toast-message {
            font-size: 13px;
            line-height: 1.4;
        }
        .access-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 18px;
            border-radius: 8px;
            font-weight: 600;
            z-index: 99999;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transform: translateY(-10px);
            transition: opacity .3s ease, transform .3s ease, visibility .3s ease;
        }
        .access-toast.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
            transform: translateY(0);
        }
        html.dark .archive-summary-label,
        html.dark .archive-empty,
        html.dark .text-secondary {
            color: var(--admin-muted) !important;
        }
        html.dark .archive-summary-value,
        html.dark .card-box h4,
        html.dark .card-box h5,
        html.dark .card-box h6 {
            color: var(--admin-text) !important;
        }
        html.dark .clip-time-code {
            color: #cbd5e1 !important;
            background: rgba(148,163,184,.12) !important;
        }
        html.dark .archive-empty-icon {
            color: #93c5fd !important;
            background: rgba(37,99,235,.15) !important;
        }
        html.dark .playback-meta {
            border-color: var(--admin-border) !important;
        }
        html.dark #playClipModal .modal-content,
        html.dark #deleteClipModal .modal-content {
            background: var(--admin-surface) !important;
            color: var(--admin-text) !important;
            border-color: var(--admin-border) !important;
        }
        html.dark #playClipModal .modal-header,
        html.dark #playClipModal .modal-footer,
        html.dark #deleteClipModal .modal-header,
        html.dark #deleteClipModal .modal-footer {
            border-color: var(--admin-border) !important;
        }
        html.dark #playClipModal .close,
        html.dark #deleteClipModal .close {
            color: #fff !important;
            text-shadow: none !important;
        }
        @media (max-width: 767.98px) {
            .archive-actions {
                min-width: 150px;
            }
            .cctv-ui-toast {
                top: 72px;
                left: 16px;
                right: 16px;
                width: auto;
            }
        }

        /* =========================================================
           CLIP-ONLY CCTV PLAYER
           ========================================================= */
        .clip-only-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            background: #ffffff;
            border-top: 1px solid #e5e7eb;
        }
        .clip-progress-wrap {
            flex: 1 1 auto;
            min-width: 0;
        }
        .clip-time-display {
            display: flex;
            justify-content: flex-end;
            gap: 4px;
            margin-bottom: 4px;
            color: #64748b;
            font-family: Consolas, "Courier New", monospace;
            font-size: 12px;
            font-weight: 700;
        }
        .clip-progress-bar {
            width: 100%;
            cursor: pointer;
            accent-color: #2563eb;
        }
        .clip-progress-bar:disabled {
            cursor: wait;
            opacity: .55;
        }
        .clip-player-cover {
            width: 100%;
            height: 100%;
            min-height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #020617;
            color: #cbd5e1;
        }
        .clip-player-inner {
            width: 100%;
            aspect-ratio: 16 / 9;
            background: #020617;
        }
        .clip-player-inner iframe,
        .clip-player-inner video {
            width: 100%;
            height: 100%;
            border: 0;
            display: block;
            background: #020617;
        }
        html.dark .clip-only-controls {
            background: var(--admin-surface) !important;
            border-color: var(--admin-border) !important;
        }
        html.dark .clip-time-display {
            color: var(--admin-muted) !important;
        }
        @media (max-width: 575.98px) {
            .clip-only-controls {
                flex-wrap: wrap;
            }
            .clip-progress-wrap {
                flex-basis: 100%;
            }
            .clip-only-controls .btn {
                flex: 1 1 auto;
            }
        }

        /* Direct header logout */
        .admin-page-logout-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 98px;
            min-height: 40px;
            margin: 0 18px 0 6px;
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
            transition:
                background .18s ease,
                color .18s ease,
                border-color .18s ease,
                transform .18s ease,
                box-shadow .18s ease;
        }
        .admin-page-logout-btn i {
            font-size: 17px;
            line-height: 1;
        }
        .admin-page-logout-btn:hover,
        .admin-page-logout-btn:focus {
            border-color: #ef4444;
            background: #fef2f2;
            color: #991b1b !important;
            box-shadow: 0 7px 18px rgba(220, 38, 38, .12);
            transform: translateY(-1px);
            outline: none;
        }
        html.dark .admin-page-logout-btn {
            border-color: rgba(248, 113, 113, .30);
            background: rgba(127, 29, 29, .16);
            color: #fca5a5 !important;
            box-shadow: none;
        }
        html.dark .admin-page-logout-btn:hover,
        html.dark .admin-page-logout-btn:focus {
            border-color: rgba(248, 113, 113, .55);
            background: rgba(127, 29, 29, .28);
            color: #fecaca !important;
        }
        @media (max-width: 575.98px) {
            .admin-page-logout-btn {
                width: 40px;
                min-width: 40px;
                height: 40px;
                min-height: 40px;
                margin: 0 10px 0 4px;
                padding: 0;
                border-radius: 10px;
            }
            .admin-page-logout-btn span {
                display: none;
            }
            .admin-page-logout-btn i {
                font-size: 18px;
            }
        }

        html.dark .archive-filter-card label,
        html.dark .table,
        html.dark .table th,
        html.dark .table td,
        html.dark .modal label {
            color: var(--admin-text) !important;
        }
        html.dark .form-control,
        html.dark select.form-control,
        html.dark input.form-control {
            background: var(--admin-input, #111827) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border-color: var(--admin-border, #4b5563) !important;
        }
        html.dark .form-control:focus,
        html.dark select.form-control:focus,
        html.dark input.form-control:focus {
            background: var(--admin-input, #111827) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 .2rem rgba(59, 130, 246, .16) !important;
        }
        html.dark select.form-control option {
            background: #111827 !important;
            color: #e5e7eb !important;
        }
        html.dark .table thead th {
            background: var(--admin-surface-2, #253244) !important;
            color: #f8fafc !important;
            border-color: var(--admin-border, #374151) !important;
        }
        html.dark .table tbody td {
            background: var(--admin-surface, #1f2937) !important;
            color: var(--admin-text, #e5e7eb) !important;
            border-color: var(--admin-border, #374151) !important;
        }
        html.dark .table-hover tbody tr:hover,
        html.dark .table-hover tbody tr:hover > * {
            background: var(--admin-hover, #334155) !important;
            color: #fff !important;
        }
        html.dark .btn-outline-secondary {
            color: #cbd5e1 !important;
            border-color: #64748b !important;
        }
        html.dark .btn-outline-secondary:hover,
        html.dark .btn-outline-secondary:focus {
            color: #fff !important;
            background: #475569 !important;
            border-color: #64748b !important;
        }
        html.dark .btn-outline-success {
            color: #86efac !important;
            border-color: #22c55e !important;
        }
        html.dark .btn-outline-success:hover,
        html.dark .btn-outline-success:focus {
            color: #fff !important;
            background: #15803d !important;
            border-color: #15803d !important;
        }
        html.dark .btn-outline-danger {
            color: #fca5a5 !important;
            border-color: #ef4444 !important;
        }
        html.dark .btn-outline-danger:hover,
        html.dark .btn-outline-danger:focus {
            color: #fff !important;
            background: #b91c1c !important;
            border-color: #b91c1c !important;
        }
        html.dark .alert-warning {
            background: rgba(217, 119, 6, .12) !important;
            color: #fde68a !important;
            border-color: rgba(245, 158, 11, .28) !important;
        }
</style>
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
        <div
            class="search-toggle-icon dw dw-search2"
            data-toggle="header_search"
        ></div>
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
        <a href="logout.php"
           class="admin-page-logout-btn"
           title="Log out"
           aria-label="Log out">
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
            <div class="row align-items-center">
                <div class="col-md-8 col-sm-12">
                    <div class="title">
                        <h4>CCTV Archive</h4>
                    </div>
                    <div class="text-secondary">
                        Phase:
                        <b><?= esc($phase) ?></b>
                        • Saved security clips
                    </div>
                </div>
                <div class="col-md-4 col-sm-12 text-md-right mt-3 mt-md-0">
                    <a
                        href="cctv_monitoring.php"
                        class="btn btn-sm btn-primary"
                    >
                        <i class="dw dw-video-camera mr-1"></i>
                        Live Monitoring
                    </a>
                </div>
            </div>
        </div>

        <?php if (!$clipsTableReady): ?>
            <div class="alert alert-warning">
                <b>CCTV Archive database is not ready.</b><br>
                <?= esc($clipsTableProblem) ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-xl-3 col-lg-4 col-md-6 mb-20">
                <div class="card-box pd-20 archive-summary-card">
                    <div class="archive-summary-label">
                        Total Saved Clips
                    </div>
                    <div class="archive-summary-value">
                        <?= number_format($totalClips) ?>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-4 col-md-6 mb-20">
                <div class="card-box pd-20 archive-summary-card">
                    <div class="archive-summary-label">
                        Current Results
                    </div>
                    <div class="archive-summary-value">
                        <?= number_format(count($savedClips)) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-box pd-20 mb-20 archive-filter-card">
            <h5 class="mb-3">Find CCTV Clip</h5>
            <form method="get">
                <div class="row">
                    <div class="col-lg-4 col-md-6 mb-2">
                        <label>Search</label>
                        <input
                            type="text"
                            name="q"
                            class="form-control"
                            value="<?= esc($search) ?>"
                            placeholder="Clip title, notes, camera..."
                        >
                    </div>
                    <div class="col-lg-3 col-md-6 mb-2">
                        <label>Camera</label>
                        <select name="camera" class="form-control">
                            <option value="">All Cameras</option>
                            <?php foreach ($cameraOptions as $camera): ?>
                                <option
                                    value="<?= esc($camera['camera_id']) ?>"
                                    <?= $cameraFilter === (string)$camera['camera_id'] ? 'selected' : '' ?>
                                >
                                    <?= esc($camera['camera_name']) ?>
                                    (<?= esc($camera['camera_id']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6 mb-2">
                        <label>Date From</label>
                        <input
                            type="date"
                            name="date_from"
                            class="form-control"
                            value="<?= esc($dateFrom) ?>"
                        >
                    </div>
                    <div class="col-lg-2 col-md-6 mb-2">
                        <label>Date To</label>
                        <input
                            type="date"
                            name="date_to"
                            class="form-control"
                            value="<?= esc($dateTo) ?>"
                        >
                    </div>
                    <div class="col-lg-1 col-md-12 mb-2 d-flex align-items-end">
                        <button class="btn btn-primary btn-block">
                            Filter
                        </button>
                    </div>
                </div>
                <a
                    href="cctv_archive.php"
                    class="btn btn-sm btn-outline-secondary mt-2"
                >
                    Clear Filters
                </a>
            </form>
        </div>

        <div class="card-box mb-30">
            <div class="pd-20 border-bottom">
                <div class="d-flex flex-wrap justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-1">Recorded CCTV Clips</h5>
                        <div class="text-secondary">
                            Reopen the exact saved segment when it is needed for review.
                        </div>
                    </div>
                    <span
                        class="badge badge-primary mt-2 mt-md-0"
                        style="padding:8px 12px;"
                    >
                        <?= number_format(count($savedClips)) ?>
                        Result<?= count($savedClips) === 1 ? '' : 's' ?>
                    </span>
                </div>
            </div>

            <?php if (!$clipsTableReady || !$savedClips): ?>
                <div class="archive-empty">
                    <div class="archive-empty-icon">
                        <i class="dw dw-folder"></i>
                    </div>
                    <h6 class="mb-1">
                        <?= $clipsTableReady
                            ? 'No saved clips found'
                            : 'Archive unavailable' ?>
                    </h6>
                    <div>
                        <?= $clipsTableReady
                            ? 'Record an important clip from Live Monitoring or adjust the filters.'
                            : 'Install or repair the CCTV saved-clips database first.' ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Saved</th>
                                <th>Camera</th>
                                <th>Clip</th>
                                <th>Segment</th>
                                <th>Duration</th>
                                <th>Recorded By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($savedClips as $clip): ?>
                                <?php
                                    $clipStart =
                                        (float)($clip['start_seconds'] ?? 0);
                                    $clipEnd =
                                        (float)($clip['end_seconds'] ?? 0);
                                    $clipDuration =
                                        (float)($clip['duration_seconds'] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <?= esc(
                                            date(
                                                'M d, Y',
                                                strtotime((string)$clip['created_at'])
                                            )
                                        ) ?>
                                        <div class="small text-secondary">
                                            <?= esc(
                                                date(
                                                    'h:i A',
                                                    strtotime((string)$clip['created_at'])
                                                )
                                            ) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="weight-600">
                                            <?= esc(
                                                $clip['camera_name']
                                                ?? $clip['camera_id']
                                            ) ?>
                                        </div>
                                        <div class="small text-secondary">
                                            <?= esc($clip['camera_id'] ?? '') ?>
                                            <?php if (!empty($clip['camera_location'])): ?>
                                                • <?= esc($clip['camera_location']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="weight-600">
                                            <?= esc(
                                                $clip['clip_title']
                                                ?? 'Saved CCTV Clip'
                                            ) ?>
                                        </div>
                                        <?php if (!empty($clip['notes'])): ?>
                                            <div class="small text-secondary clip-note">
                                                <?= esc($clip['notes']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="clip-time-code">
                                            <?= esc(
                                                gmdate(
                                                    'H:i:s',
                                                    (int)$clipStart
                                                )
                                            ) ?>
                                            →
                                            <?= esc(
                                                gmdate(
                                                    'H:i:s',
                                                    (int)$clipEnd
                                                )
                                            ) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= esc(
                                            gmdate(
                                                'H:i:s',
                                                (int)round($clipDuration)
                                            )
                                        ) ?>
                                    </td>
                                    <td>
                                        <?= esc(
                                            $clip['recorded_by_name']
                                            ?: 'Admin'
                                        ) ?>
                                    </td>
                                    <td class="archive-actions">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-primary mb-1 js-play-cctv-clip"
                                            data-clip-id="<?= (int)$clip['id'] ?>"
                                            data-source-type="<?= esc($clip['source_type'] ?? 'youtube_reference') ?>"
                                            data-video-id="<?= esc($clip['source_video_id'] ?? '') ?>"
                                            data-file-path="<?= esc($clip['file_path'] ?? '') ?>"
                                            data-start="<?= esc($clipStart) ?>"
                                            data-end="<?= esc($clipEnd) ?>"
                                            data-title="<?= esc($clip['clip_title'] ?? 'Saved CCTV Clip') ?>"
                                            data-camera="<?= esc($clip['camera_name'] ?? $clip['camera_id']) ?>"
                                        >
                                            <i class="dw dw-play-button mr-1"></i>
                                            Play Clip
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-success mb-1 js-download-cctv-clip"
                                            data-clip-id="<?= (int)$clip['id'] ?>"
                                            data-source-type="<?= esc($clip['source_type'] ?? 'youtube_reference') ?>"
                                            data-file-path="<?= esc($clip['file_path'] ?? '') ?>"
                                            data-title="<?= esc($clip['clip_title'] ?? 'Saved CCTV Clip') ?>"
                                        >
                                            <i class="dw dw-download mr-1"></i>
                                            Download
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger mb-1 js-delete-cctv-clip"
                                            data-clip-id="<?= (int)$clip['id'] ?>"
                                            data-title="<?= esc($clip['clip_title'] ?? 'Saved CCTV Clip') ?>"
                                        >
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- PLAY CLIP MODAL -->
        <div
            class="modal fade"
            id="playClipModal"
            tabindex="-1"
            role="dialog"
            aria-hidden="true"
        >
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="playClipTitle">
                                Saved CCTV Clip
                            </h5>
                            <div
                                class="small text-secondary"
                                id="playClipCamera"
                            ></div>
                        </div>
                        <button
                            type="button"
                            class="close"
                            data-dismiss="modal"
                            aria-label="Close"
                        >
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body p-0">
                        <div
                            id="clipPlaybackStage"
                            class="clip-playback-stage"
                        >
                            <div class="clip-loading-message">
                                Preparing saved clip...
                            </div>
                        </div>
                        <div class="clip-only-controls">
                            <button
                                type="button"
                                id="clipPlayPauseButton"
                                class="btn btn-sm btn-primary"
                                disabled
                            >
                                <span id="clipPlayPauseIcon">▶</span>
                                <span id="clipPlayPauseText">Play</span>
                            </button>
                            <button
                                type="button"
                                id="clipRestartButton"
                                class="btn btn-sm btn-outline-secondary"
                                disabled
                            >
                                ↺ Restart
                            </button>
                            <div class="clip-progress-wrap">
                                <div class="clip-time-display">
                                    <span id="clipCurrentTime">00:00</span>
                                    <span>/</span>
                                    <span id="clipDurationTime">00:00</span>
                                </div>
                                <input
                                    type="range"
                                    id="clipProgressBar"
                                    class="clip-progress-bar"
                                    min="0"
                                    max="100"
                                    step="0.1"
                                    value="0"
                                    disabled
                                    aria-label="Saved clip progress"
                                >
                            </div>
                        </div>
                        <div class="playback-meta">
                            <span class="badge badge-primary mr-2">
                                SAVED CLIP ONLY
                            </span>
                            <span>
                                Clip length:
                                <b id="playClipLength">00:00</b>
                            </span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-secondary"
                            data-dismiss="modal"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- DELETE CLIP MODAL -->
        <div
            class="modal fade"
            id="deleteClipModal"
            tabindex="-1"
            role="dialog"
            aria-hidden="true"
        >
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            Delete Saved CCTV Clip
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
                        <p class="mb-2">
                            Delete
                            <b id="deleteClipTitle">
                                this saved CCTV clip
                            </b>?
                        </p>
                        <div class="alert alert-warning mb-0">
                            This removes the saved clip record from CCTV Archive.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-secondary"
                            data-dismiss="modal"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            id="confirmDeleteClipButton"
                            class="btn btn-danger"
                        >
                            Delete Clip
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- CUSTOM CCTV TOAST -->
        <div
            id="cctvUiToast"
            class="cctv-ui-toast info"
            role="status"
            aria-live="polite"
        >
            <span
                id="cctvUiToastTitle"
                class="cctv-ui-toast-title"
            >
                CCTV Archive
            </span>
            <div
                id="cctvUiToastMessage"
                class="cctv-ui-toast-message"
            ></div>
        </div>

        <div
            id="accessToast"
            class="access-toast"
        >
            🚫 You do not have access to that part.
        </div>

        <div class="footer-wrap pd-20 mb-20 card-box">
            © Copyright South Meridian Homes All Rights Reserved
        </div>
    </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="vendors/scripts/admin_theme.js"></script>

<script>
const cctvArchiveCsrf =
    <?= json_encode($cctvClipCsrf) ?>;
let pendingPlayback = null;
let pendingDeleteClipId = null;
let toastTimer = null;
let clipPlayer = null;
let clipHtmlVideo = null;
let clipProgressTimer = null;
let youtubeApiPromise = null;

function showCctvToast(
    message,
    type = 'info',
    title = 'CCTV Archive'
) {
    const toast =
        document.getElementById(
            'cctvUiToast'
        );
    const titleNode =
        document.getElementById(
            'cctvUiToastTitle'
        );
    const messageNode =
        document.getElementById(
            'cctvUiToastMessage'
        );
    if (!toast || !titleNode || !messageNode) {
        return;
    }
    const validTypes = [
        'success',
        'warning',
        'danger',
        'info'
    ];
    const finalType =
        validTypes.includes(type)
            ? type
            : 'info';
    toast.classList.remove(
        'success',
        'warning',
        'danger',
        'info'
    );
    toast.classList.add(finalType);
    titleNode.textContent = title;
    messageNode.textContent = String(message || '');
    toast.classList.add('show');
    if (toastTimer) {
        window.clearTimeout(toastTimer);
    }
    toastTimer =
        window.setTimeout(
            function () {
                toast.classList.remove('show');
            },
            3500
        );
}

function formatClipSeconds(value) {
    let seconds =
        Math.max(
            0,
            Math.floor(
                Number(value || 0)
            )
        );
    const hours =
        Math.floor(seconds / 3600);
    seconds %= 3600;
    const minutes =
        Math.floor(seconds / 60);
    const remaining =
        seconds % 60;
    if (hours > 0) {
        return [
            hours,
            minutes,
            remaining
        ]
            .map(function (part) {
                return String(part).padStart(2, '0');
            })
            .join(':');
    }
    return [
        minutes,
        remaining
    ]
        .map(function (part) {
            return String(part).padStart(2, '0');
        })
        .join(':');
}

function getClipDuration() {
    if (!pendingPlayback) {
        return 0;
    }
    return Math.max(
        1,
        Number(
            pendingPlayback.end -
            pendingPlayback.start
        )
    );
}

function setClipControlsReady(ready) {
    const playButton =
        document.getElementById(
            'clipPlayPauseButton'
        );
    const restartButton =
        document.getElementById(
            'clipRestartButton'
        );
    const progressBar =
        document.getElementById(
            'clipProgressBar'
        );
    if (playButton) {
        playButton.disabled = !ready;
    }
    if (restartButton) {
        restartButton.disabled = !ready;
    }
    if (progressBar) {
        progressBar.disabled = !ready;
    }
}

function setClipPlayState(isPlaying) {
    const icon =
        document.getElementById(
            'clipPlayPauseIcon'
        );
    const text =
        document.getElementById(
            'clipPlayPauseText'
        );
    if (icon) {
        icon.textContent =
            isPlaying
                ? '❚❚'
                : '▶';
    }
    if (text) {
        text.textContent =
            isPlaying
                ? 'Pause'
                : 'Play';
    }
}

function updateClipProgress(
    clipSeconds
) {
    const duration =
        getClipDuration();
    const safeSeconds =
        Math.max(
            0,
            Math.min(
                duration,
                Number(clipSeconds || 0)
            )
        );
    const progress =
        duration > 0
            ? (
                safeSeconds /
                duration
            ) * 100
            : 0;
    const progressBar =
        document.getElementById(
            'clipProgressBar'
        );
    const currentTime =
        document.getElementById(
            'clipCurrentTime'
        );
    if (progressBar) {
        progressBar.value =
            String(progress);
    }
    if (currentTime) {
        currentTime.textContent =
            formatClipSeconds(
                safeSeconds
            );
    }
}

function resetClipUi() {
    const duration =
        getClipDuration();
    updateClipProgress(0);
    const durationNode =
        document.getElementById(
            'clipDurationTime'
        );
    const lengthNode =
        document.getElementById(
            'playClipLength'
        );
    if (durationNode) {
        durationNode.textContent =
            formatClipSeconds(
                duration
            );
    }
    if (lengthNode) {
        lengthNode.textContent =
            formatClipSeconds(
                duration
            );
    }
    setClipPlayState(false);
    setClipControlsReady(false);
}

function destroyClipPlayer() {
    if (clipProgressTimer) {
        window.clearInterval(
            clipProgressTimer
        );
        clipProgressTimer = null;
    }
    if (clipPlayer) {
        try {
            if (
                typeof clipPlayer.destroy ===
                'function'
            ) {
                clipPlayer.destroy();
            }
        } catch (error) {
            console.warn(error);
        }
        clipPlayer = null;
    }
    if (clipHtmlVideo) {
        try {
            clipHtmlVideo.pause();
            clipHtmlVideo.removeAttribute(
                'src'
            );
            clipHtmlVideo.load();
        } catch (error) {
            console.warn(error);
        }
        clipHtmlVideo = null;
    }
    const stage =
        document.getElementById(
            'clipPlaybackStage'
        );
    if (stage) {
        stage.innerHTML =
            '<div class="clip-loading-message">Preparing saved clip...</div>';
    }
    setClipControlsReady(false);
    setClipPlayState(false);
    updateClipProgress(0);
}

function loadYouTubeIframeApi() {
    if (
        window.YT &&
        typeof window.YT.Player ===
        'function'
    ) {
        return Promise.resolve(
            window.YT
        );
    }
    if (youtubeApiPromise) {
        return youtubeApiPromise;
    }
    youtubeApiPromise =
        new Promise(
            function (
                resolve,
                reject
            ) {
                const previousCallback =
                    window.onYouTubeIframeAPIReady;
                window.onYouTubeIframeAPIReady =
                    function () {
                        if (
                            typeof previousCallback ===
                            'function'
                        ) {
                            try {
                                previousCallback();
                            } catch (error) {
                                console.warn(error);
                            }
                        }
                        resolve(
                            window.YT
                        );
                    };
                const existing =
                    document.querySelector(
                        'script[src="https://www.youtube.com/iframe_api"]'
                    );
                if (existing) {
                    const wait =
                        window.setInterval(
                            function () {
                                if (
                                    window.YT &&
                                    typeof window.YT.Player ===
                                    'function'
                                ) {
                                    window.clearInterval(
                                        wait
                                    );
                                    resolve(
                                        window.YT
                                    );
                                }
                            },
                            100
                        );
                    window.setTimeout(
                        function () {
                            window.clearInterval(
                                wait
                            );
                            if (
                                !window.YT ||
                                typeof window.YT.Player !==
                                'function'
                            ) {
                                reject(
                                    new Error(
                                        'YouTube player controls did not load.'
                                    )
                                );
                            }
                        },
                        8000
                    );
                    return;
                }
                const script =
                    document.createElement(
                        'script'
                    );
                script.src =
                    'https://www.youtube.com/iframe_api';
                script.async =
                    true;
                script.onerror =
                    function () {
                        youtubeApiPromise = null;
                        reject(
                            new Error(
                                'Unable to load YouTube player controls.'
                            )
                        );
                    };
                document.head.appendChild(
                    script
                );
            }
        );
    return youtubeApiPromise;
}

function startClipProgressLoop() {
    if (clipProgressTimer) {
        window.clearInterval(
            clipProgressTimer
        );
    }
    clipProgressTimer =
        window.setInterval(
            function () {
                if (!pendingPlayback) {
                    return;
                }
                const duration =
                    getClipDuration();
                let clipSeconds = 0;
                let isPlaying = false;
                if (clipPlayer) {
                    try {
                        const absoluteTime =
                            Number(
                                clipPlayer.getCurrentTime()
                                || 0
                            );
                        clipSeconds =
                            absoluteTime -
                            pendingPlayback.start;
                        const state =
                            clipPlayer.getPlayerState();
                        isPlaying =
                            window.YT &&
                            state ===
                            window.YT.PlayerState.PLAYING;
                        /*
                         * Hard stop at clip end.
                         * The viewer never continues into the original video.
                         */
                        if (
                            clipSeconds >=
                            duration
                        ) {
                            clipPlayer.pauseVideo();
                            clipPlayer.seekTo(
                                pendingPlayback.end,
                                true
                            );
                            clipSeconds =
                                duration;
                            isPlaying =
                                false;
                        }
                    } catch (error) {
                        return;
                    }
                } else if (clipHtmlVideo) {
                    clipSeconds =
                        Number(
                            clipHtmlVideo.currentTime
                            || 0
                        ) -
                        pendingPlayback.start;
                    isPlaying =
                        !clipHtmlVideo.paused;
                    if (
                        clipSeconds >=
                        duration
                    ) {
                        clipHtmlVideo.pause();
                        clipHtmlVideo.currentTime =
                            pendingPlayback.end;
                        clipSeconds =
                            duration;
                        isPlaying =
                            false;
                    }
                }
                updateClipProgress(
                    clipSeconds
                );
                setClipPlayState(
                    isPlaying
                );
            },
            200
        );
}

async function renderSavedClip() {
    if (!pendingPlayback) {
        return;
    }
    destroyClipPlayer();
    resetClipUi();
    const stage =
        document.getElementById(
            'clipPlaybackStage'
        );
    if (!stage) {
        return;
    }
    const config =
        pendingPlayback;
    if (
        config.sourceType ===
        'video_file' &&
        config.filePath !== ''
    ) {
        stage.innerHTML =
            '<div class="clip-player-inner" id="clipPlayerHost"></div>';
        const host =
            document.getElementById(
                'clipPlayerHost'
            );
        const video =
            document.createElement(
                'video'
            );
        video.playsInline =
            true;
        video.preload =
            'metadata';
        video.controls =
            false;
        video.src =
            config.filePath;
        host.appendChild(
            video
        );
        clipHtmlVideo =
            video;
        video.addEventListener(
            'loadedmetadata',
            function () {
                video.currentTime =
                    config.start;
                setClipControlsReady(
                    true
                );
                startClipProgressLoop();
            },
            {
                once: true
            }
        );
        return;
    }
    if (!config.videoId) {
        stage.innerHTML =
            '<div class="clip-player-cover">This saved clip has no playable video source.</div>';
        showCctvToast(
            'This saved clip has no playable source.',
            'warning',
            'Playback Unavailable'
        );
        return;
    }
    stage.innerHTML =
        '<div class="clip-player-inner"><div id="youtubeClipPlayer"></div></div>';
    try {
        await loadYouTubeIframeApi();
        if (!pendingPlayback) {
            return;
        }
        const duration =
            getClipDuration();
        clipPlayer =
            new YT.Player(
                'youtubeClipPlayer',
                {
                    videoId:
                        config.videoId,
                    playerVars: {
                        autoplay: 0,
                        mute: 1,
                        controls: 0,
                        disablekb: 1,
                        rel: 0,
                        playsinline: 1,
                        start:
                            Math.floor(
                                config.start
                            ),
                        end:
                            Math.ceil(
                                config.end
                            ),
                        origin:
                            window.location.origin
                    },
                    events: {
                        onReady:
                            function (
                                event
                            ) {
                                event.target.seekTo(
                                    config.start,
                                    true
                                );
                                event.target.pauseVideo();
                                setClipControlsReady(
                                    true
                                );
                                resetClipUi();
                                setClipControlsReady(
                                    true
                                );
                                startClipProgressLoop();
                            },
                        onStateChange:
                            function (
                                event
                            ) {
                                if (
                                    event.data ===
                                    YT.PlayerState.ENDED
                                ) {
                                    updateClipProgress(
                                        duration
                                    );
                                    setClipPlayState(
                                        false
                                    );
                                }
                            },
                        onError:
                            function () {
                                showCctvToast(
                                    'The saved clip could not be loaded from the demo camera source.',
                                    'danger',
                                    'Playback Failed'
                                );
                            }
                    }
                }
            );
    } catch (error) {
        console.error(
            error
        );
        stage.innerHTML =
            '<div class="clip-player-cover">Unable to load the saved clip.</div>';
        showCctvToast(
            error.message ||
            'Unable to load saved clip.',
            'danger',
            'Playback Failed'
        );
    }
}

function openSavedClip(button) {
    destroyClipPlayer();
    const start =
        Math.max(
            0,
            Number(
                button.dataset.start
                || 0
            )
        );
    const end =
        Math.max(
            start + 1,
            Number(
                button.dataset.end
                || 0
            )
        );
    pendingPlayback = {
        sourceType:
            String(
                button.dataset.sourceType
                || 'youtube_reference'
            ),
        videoId:
            String(
                button.dataset.videoId
                || ''
            ),
        filePath:
            String(
                button.dataset.filePath
                || ''
            ),
        start: start,
        end: end
    };
    document.getElementById(
        'playClipTitle'
    ).textContent =
        String(
            button.dataset.title
            || 'Saved CCTV Clip'
        );
    document.getElementById(
        'playClipCamera'
    ).textContent =
        String(
            button.dataset.camera
            || ''
        );
    resetClipUi();
    $('#playClipModal')
        .modal('show');
}

function playOrPauseSavedClip() {
    if (!pendingPlayback) {
        return;
    }
    const duration =
        getClipDuration();
    if (clipPlayer) {
        try {
            const current =
                Number(
                    clipPlayer.getCurrentTime()
                    || pendingPlayback.start
                );
            const clipPosition =
                current -
                pendingPlayback.start;
            /*
             * If the clip already ended, Play starts the clip
             * from its own 00:00 again.
             */
            if (
                clipPosition >=
                duration - .15
            ) {
                clipPlayer.seekTo(
                    pendingPlayback.start,
                    true
                );
            }
            const state =
                clipPlayer.getPlayerState();
            if (
                window.YT &&
                state ===
                YT.PlayerState.PLAYING
            ) {
                clipPlayer.pauseVideo();
            } else {
                clipPlayer.playVideo();
            }
        } catch (error) {
            console.warn(error);
        }
        return;
    }
    if (clipHtmlVideo) {
        const clipPosition =
            clipHtmlVideo.currentTime -
            pendingPlayback.start;
        if (
            clipPosition >=
            duration - .15
        ) {
            clipHtmlVideo.currentTime =
                pendingPlayback.start;
        }
        if (
            clipHtmlVideo.paused
        ) {
            clipHtmlVideo
                .play()
                .catch(
                    function () {}
                );
        } else {
            clipHtmlVideo.pause();
        }
    }
}

function restartSavedClip() {
    if (!pendingPlayback) {
        return;
    }
    if (clipPlayer) {
        try {
            clipPlayer.seekTo(
                pendingPlayback.start,
                true
            );
            clipPlayer.playVideo();
        } catch (error) {
            console.warn(error);
        }
        return;
    }
    if (clipHtmlVideo) {
        clipHtmlVideo.currentTime =
            pendingPlayback.start;
        clipHtmlVideo
            .play()
            .catch(
                function () {}
            );
    }
}

function seekSavedClip(
    percentage
) {
    if (!pendingPlayback) {
        return;
    }
    const duration =
        getClipDuration();
    const safePercent =
        Math.max(
            0,
            Math.min(
                100,
                Number(
                    percentage
                    || 0
                )
            )
        );
    const clipSeconds =
        (
            safePercent /
            100
        ) * duration;
    const absoluteTime =
        pendingPlayback.start +
        clipSeconds;
    if (clipPlayer) {
        try {
            clipPlayer.seekTo(
                absoluteTime,
                true
            );
        } catch (error) {
            console.warn(error);
        }
    } else if (clipHtmlVideo) {
        clipHtmlVideo.currentTime =
            absoluteTime;
    }
    updateClipProgress(
        clipSeconds
    );
}

document.addEventListener(
    'click',
    function (event) {
        const target =
            event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const playButton =
            target.closest(
                '.js-play-cctv-clip'
            );
        if (playButton) {
            event.preventDefault();
            openSavedClip(
                playButton
            );
            return;
        }
        const downloadButton =
            target.closest(
                '.js-download-cctv-clip'
            );
        if (downloadButton) {
            event.preventDefault();
            const clipId =
                Number(
                    downloadButton.dataset.clipId
                    || 0
                );
            const sourceType =
                String(
                    downloadButton.dataset.sourceType
                    || 'youtube_reference'
                );
            const filePath =
                String(
                    downloadButton.dataset.filePath
                    || ''
                );
            if (
                sourceType !== 'video_file' ||
                filePath === ''
            ) {
                showCctvToast(
                    'This clip is currently a timestamp-only reference from the demo camera feed, so there is no MP4 file to download yet.',
                    'info',
                    'Download Not Available Yet'
                );
                return;
            }
            if (clipId <= 0) {
                showCctvToast(
                    'The selected CCTV clip is invalid.',
                    'danger',
                    'Download Failed'
                );
                return;
            }
            window.location.href =
                'cctv_download_clip.php?id=' +
                encodeURIComponent(
                    String(clipId)
                );
            return;
        }

        const deleteButton =
            target.closest(
                '.js-delete-cctv-clip'
            );
        if (deleteButton) {
            event.preventDefault();
            pendingDeleteClipId =
                Number(
                    deleteButton.dataset.clipId
                    || 0
                );
            document.getElementById(
                'deleteClipTitle'
            ).textContent =
                String(
                    deleteButton.dataset.title
                    || 'this saved CCTV clip'
                );
            $('#deleteClipModal')
                .modal('show');
        }
    }
);

$('#playClipModal')
    .on(
        'shown.bs.modal',
        function () {
            renderSavedClip();
        }
    )
    .on(
        'hidden.bs.modal',
        function () {
            pendingPlayback = null;
            destroyClipPlayer();
        }
    );

document
    .getElementById(
        'clipPlayPauseButton'
    )
    .addEventListener(
        'click',
        playOrPauseSavedClip
    );

document
    .getElementById(
        'clipRestartButton'
    )
    .addEventListener(
        'click',
        restartSavedClip
    );

document
    .getElementById(
        'clipProgressBar'
    )
    .addEventListener(
        'input',
        function () {
            seekSavedClip(
                this.value
            );
        }
    );

$('#deleteClipModal')
    .on(
        'hidden.bs.modal',
        function () {
            pendingDeleteClipId = null;
        }
    );

document
    .getElementById(
        'confirmDeleteClipButton'
    )
    .addEventListener(
        'click',
        async function () {
            if (!pendingDeleteClipId) {
                return;
            }
            const button = this;
            button.disabled = true;
            button.textContent =
                'Deleting...';
            const formData =
                new FormData();
            formData.append(
                'cctv_archive_action',
                'delete_clip'
            );
            formData.append(
                'csrf',
                cctvArchiveCsrf
            );
            formData.append(
                'clip_id',
                String(
                    pendingDeleteClipId
                )
            );
            try {
                const response =
                    await fetch(
                        window.location.href,
                        {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        }
                    );
                const responseText =
                    await response.text();
                let result = null;
                try {
                    result =
                        JSON.parse(
                            responseText
                        );
                } catch (parseError) {
                    console.error(
                        'CCTV archive returned non-JSON:',
                        responseText
                    );
                    throw new Error(
                        'The server returned an unexpected response.'
                    );
                }
                if (!result.ok) {
                    throw new Error(
                        result.message ||
                        'Unable to delete saved CCTV clip.'
                    );
                }
                $('#deleteClipModal')
                    .modal('hide');
                showCctvToast(
                    'Saved CCTV clip deleted.',
                    'success',
                    'Clip Deleted'
                );
                window.setTimeout(
                    function () {
                        window.location.reload();
                    },
                    650
                );
            } catch (error) {
                showCctvToast(
                    error.message ||
                    'Unable to delete saved CCTV clip.',
                    'danger',
                    'Delete Failed'
                );
            } finally {
                button.disabled = false;
                button.textContent =
                    'Delete Clip';
            }
        }
    );
</script>

<script>
window.userPermissions =
    <?= json_encode($permissions ?? []) ?>;
document.addEventListener(
    'DOMContentLoaded',
    function () {
        const accessToast =
            document.getElementById(
                'accessToast'
            );
        function showAccessToast() {
            if (!accessToast) {
                return;
            }
            accessToast.classList.add(
                'show'
            );
            window.setTimeout(
                function () {
                    accessToast.classList.remove(
                        'show'
                    );
                },
                2500
            );
        }
        document
            .querySelectorAll(
                '.menu-access-link'
            )
            .forEach(
                function (link) {
                    link.addEventListener(
                        'click',
                        function (event) {
                            const moduleKey =
                                this.dataset.module
                                || '';
                            const allowed =
                                !!window.userPermissions[
                                    moduleKey
                                ];
                            if (!allowed) {
                                event.preventDefault();
                                showAccessToast();
                            }
                        }
                    );
                }
            );
    }
);
</script>
</body>
</html>
