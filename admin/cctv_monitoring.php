<?php
session_start();

require_once '../config/database.php';
require_once 'admin_access.php';

/*
|--------------------------------------------------------------------------
| AUTH GUARD
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)
) {
    header('Location: ../index.php');
    exit;
}

if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
    header('Location: index.php');
    exit;
}

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function valid_youtube_id(string $videoId): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId);
}

/*
|--------------------------------------------------------------------------
| ADMIN INFO
|--------------------------------------------------------------------------
*/

$adminId = (int)$_SESSION['admin_id'];

$stmt = $conn->prepare("
    SELECT email, full_name, phase, role
    FROM admins
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param('i', $adminId);
$stmt->execute();

$me = $stmt->get_result()->fetch_assoc() ?: [
    'email' => '',
    'full_name' => '',
    'phase' => 'Phase 1',
    'role' => 'admin'
];

$stmt->close();

$adminName = trim((string)($me['full_name'] ?? ''));
$myPhase   = (string)($me['phase'] ?? 'Phase 1');

$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true)
    ? $myPhase
    : 'Phase 1';

/*
|--------------------------------------------------------------------------
| CCTV CAMERAS
|--------------------------------------------------------------------------
*/

$cameras = [
    [
        'id'        => 'CAM-01',
        'name'      => 'Main Gate',
        'location'  => 'South Meridian Entrance',
        'phase'     => 'Common Area',
        'video_id'  => 'FWvIPfxK5Jo',
        'enabled'   => true,
    ],
    [
        'id'        => 'CAM-02',
        'name'      => 'Phase Entrance',
        'location'  => $phase . ' Entrance',
        'phase'     => $phase,
        'video_id'  => 'Far_aDIwAyw',
        'enabled'   => true,
    ],
    [
        'id'        => 'CAM-03',
        'name'      => 'Basketball Court',
        'location'  => 'Community Court',
        'phase'     => 'Common Area',
        'video_id'  => '2iENQ0dDmqI',
        'enabled'   => true,
    ],
    [
        'id'        => 'CAM-04',
        'name'      => 'Clubhouse',
        'location'  => 'Community Clubhouse',
        'phase'     => 'Common Area',
        'video_id'  => '8ALC939509U',
        'enabled'   => true,
    ],
];

$totalCameras = count($cameras);

$configuredCameras = 0;

foreach ($cameras as $camera) {
    $videoId = trim((string)($camera['video_id'] ?? ''));

    if (
        ($camera['enabled'] ?? false) &&
        valid_youtube_id($videoId)
    ) {
        $configuredCameras++;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">

    <title>HOA-ADMIN | CCTV Monitoring</title>

    <link
        rel="apple-touch-icon"
        sizes="180x180"
        href="vendors/images/apple-touch-icon.png"
    >

    <link
        rel="icon"
        type="image/png"
        sizes="32x32"
        href="vendors/images/favicon-32x32.png"
    >

    <link
        rel="icon"
        type="image/png"
        sizes="16x16"
        href="vendors/images/favicon-16x16.png"
    >

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1, maximum-scale=1"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    
    <link
        rel="stylesheet"
        type="text/css"
        href="vendors/styles/core.css"
    >

    <link
        rel="stylesheet"
        type="text/css"
        href="vendors/styles/icon-font.min.css"
    >

    <link
        rel="stylesheet"
        type="text/css"
        href="vendors/styles/style.css"
    >
<!-- SHARED ADMIN LIGHT / DARK THEME -->
<link
    rel="stylesheet"
    type="text/css"
    href="vendors/styles/admin_theme.css"
>
    <style>
        .cctv-kpi-card {
            height: 100%;
        }

        .cctv-kpi-label {
            color: #64748b;
            font-weight: 700;
        }

        .cctv-kpi-value {
            font-size: 28px;
            font-weight: 800;
            line-height: 1.1;
        }

        .camera-card {
            overflow: hidden;
            height: 100%;
        }

        .camera-screen {
            position: relative;
            width: 100%;
            background: #0f172a;
            aspect-ratio: 16 / 9;
            overflow: hidden;
        }

        .camera-screen iframe {
            display: block;
            width: 100%;
            height: 100%;
            border: 0;
        }

        .camera-placeholder {
            min-height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
            text-align: center;
            color: #cbd5e1;
            background:
                radial-gradient(
                    circle at center,
                    rgba(59, 130, 246, .13),
                    transparent 45%
                ),
                #0f172a;
        }

        .camera-placeholder-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 14px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,.08);
            font-size: 28px;
            color: #ffffff;
        }

        .live-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            z-index: 10;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #dc2626;
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .04em;
            box-shadow: 0 4px 12px rgba(0,0,0,.18);
        }

        .live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #fff;
            animation: livePulse 1.2s infinite;
        }

        @keyframes livePulse {
            0%, 100% {
                opacity: 1;
            }

            50% {
                opacity: .35;
            }
        }

        .camera-id {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            font-size: 11px;
            font-weight: 800;
        }

        .camera-online {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 999px;
            background: #ecfdf5;
            color: #166534;
            border: 1px solid #bbf7d0;
            font-size: 11px;
            font-weight: 800;
        }

        .camera-meta {
            color: #64748b;
            font-size: 13px;
        }

        .camera-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .camera-action-btn {
            min-height: 38px;
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

    /*
     * IMPORTANT:
     * Invisible toast must not block
     * the dark-mode button.
     */
    pointer-events: none;

    transform: translateY(-10px);

    transition:
        opacity .3s ease,
        transform .3s ease,
        visibility .3s ease;
}

.access-toast.show {
    opacity: 1;
    visibility: visible;

    pointer-events: auto;

    transform: translateY(0);
}
/* =========================================================
   CCTV-SPECIFIC DARK MODE
   Base theme comes from admin_theme.css
   ========================================================= */

html.dark .cctv-kpi-label,
html.dark .camera-meta {
    color: var(--admin-muted) !important;
}

html.dark .cctv-kpi-value,
html.dark .camera-card h5 {
    color: var(--admin-text) !important;
}


/* Camera ID badge */
html.dark .camera-id {
    background: rgba(37, 99, 235, .15) !important;
    color: #93c5fd !important;
    border-color: rgba(59, 130, 246, .35) !important;
}


/* Online badge */
html.dark .camera-online {
    background: rgba(22, 163, 74, .14) !important;
    color: #86efac !important;
    border-color: rgba(34, 197, 94, .30) !important;
}


/* Camera video area */
html.dark .camera-screen {
    background: #020617 !important;
}
    </style>    
<script>
(function () {
    try {

        const savedTheme =
            localStorage.getItem(
                'hoa-theme'
            );

        const dark =
            savedTheme === 'dark' ||
            (
                !savedTheme &&
                window.matchMedia &&
                window
                    .matchMedia(
                        '(prefers-color-scheme: dark)'
                    )
                    .matches
            );

        document
            .documentElement
            .classList
            .toggle(
                'dark',
                dark
            );

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


    <div class="user-info-dropdown">
            <div class="dropdown">
                <a
                    class="dropdown-toggle"
                    href="#"
                    role="button"
                    data-toggle="dropdown"
                >
                    <span class="user-icon">
                        <img
                            src="vendors/images/photo1.jpg"
                            alt=""
                        >
                    </span>
                </a>

                <div
                    class="
                        dropdown-menu
                        dropdown-menu-right
                        dropdown-menu-icon-list
                    "
                >
                    <a
                        class="dropdown-item"
                        href="logout.php"
                    >
                        <i class="dw dw-logout"></i>
                        Log Out
                    </a>
                </div>
            </div>
        </div>
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
                        <h4>CCTV Monitoring</h4>
                    </div>

                    <div class="text-secondary">
                        Phase:
                        <b><?= esc($phase) ?></b>
                        •
                        Live security monitoring
                    </div>
                </div>

                <div class="col-md-4 col-sm-12 text-md-right mt-3 mt-md-0">
                    <span class="badge badge-success" style="font-size:12px; padding:8px 12px;">
                        <i class="dw dw-check mr-1"></i>
                        MONITORING ACTIVE
                    </span>
                </div>
            </div>
        </div>


        
        <div class="row">

            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 cctv-kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="cctv-kpi-label">
                                Camera Slots
                            </div>

                            <div class="cctv-kpi-value">
                                <?= number_format($totalCameras) ?>
                            </div>
                        </div>

                        <div class="text-primary" style="font-size:28px;">
                            <i class="dw dw-video-camera"></i>
                        </div>
                    </div>

                    <div class="mt-2 text-secondary">
                        Configured monitoring positions
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 cctv-kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="cctv-kpi-label">
                                Connected Cameras
                            </div>

                            <div class="cctv-kpi-value">
                                <?= number_format($configuredCameras) ?>
                            </div>
                        </div>

                        <div class="text-success" style="font-size:28px;">
                            <i class="dw dw-check"></i>
                        </div>
                    </div>

                    <div class="mt-2 text-secondary">
                        Active camera connections
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 cctv-kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="cctv-kpi-label">
                                Monitoring Area
                            </div>

                            <div class="cctv-kpi-value" style="font-size:20px;">
                                <?= esc($phase) ?>
                            </div>
                        </div>

                        <div class="text-info" style="font-size:28px;">
                            <i class="dw dw-map"></i>
                        </div>
                    </div>

                    <div class="mt-2 text-secondary">
                        Admin-assigned community phase
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 cctv-kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="cctv-kpi-label">
                                System Status
                            </div>

                            <div class="cctv-kpi-value" style="font-size:20px;">
                                live
                            </div>
                        </div>

                        <div class="text-warning" style="font-size:28px;">
                            <i class="dw dw-settings2"></i>
                        </div>
                    </div>

                    <div class="mt-2 text-secondary">
                        Security monitoring service
                    </div>
                </div>
            </div>

        </div>


        
                <div class="row">

            <?php foreach ($cameras as $camera): ?>
                <?php
                $videoId =
                    trim(
                        (string)(
                            $camera['video_id']
                            ?? ''
                        )
                    );

                $hasFeed =
                    ($camera['enabled'] ?? false) &&
                    valid_youtube_id($videoId);

                $iframeId =
                    'cameraFrame_' .
                    preg_replace(
                        '/[^A-Za-z0-9_]/',
                        '_',
                        (string)(
                            $camera['id']
                            ?? uniqid()
                        )
                    );
                ?>

                <div class="col-xl-6 col-lg-6 col-md-12 mb-30">

                    <div class="card-box camera-card">

                        <div class="camera-screen">

                            <?php if ($hasFeed): ?>

                                <div class="live-badge">
                                    <span class="live-dot"></span>
                                    LIVE
                                </div>

                                <iframe
                                    id="<?= esc($iframeId) ?>"
                                    src="https://www.youtube.com/embed/<?= esc($videoId) ?>?autoplay=1&mute=1&controls=1&rel=0&playsinline=1"
                                    title="<?= esc($camera['name'] ?? 'CCTV Camera') ?>"
                                    allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                                    allowfullscreen
                                    loading="lazy"
                                ></iframe>

                            <?php else: ?>

                                <div class="camera-placeholder">
                                    <div>
                                        <div class="camera-placeholder-icon">
                                            <i class="dw dw-video-camera"></i>
                                        </div>

                                        <h5 class="text-white mb-2">
                                            Camera Offline
                                        </h5>

                                        <div>
                                            Live feed is currently unavailable.
                                        </div>
                                    </div>
                                </div>

                            <?php endif; ?>

                        </div>


                        <div class="pd-20">

                            <div
                                class="
                                    d-flex
                                    flex-column
                                    flex-md-row
                                    justify-content-between
                                    align-items-md-start
                                "
                            >
                                <div class="mb-3 mb-md-0">

                                    <div class="d-flex flex-wrap align-items-center mb-2">

                                        <span class="camera-id mr-2">
                                            <?= esc($camera['id'] ?? 'CAM') ?>
                                        </span>

                                        <?php if ($hasFeed): ?>
                                            <span class="camera-online">
                                                <span
                                                    style="
                                                        width:7px;
                                                        height:7px;
                                                        border-radius:50%;
                                                        background:#16a34a;
                                                        display:inline-block;
                                                    "
                                                ></span>
                                                ONLINE
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary" style="font-size:11px; padding:6px 9px;">
                                                OFFLINE
                                            </span>
                                        <?php endif; ?>

                                    </div>

                                    <h5 class="mb-1">
                                        <?= esc($camera['name'] ?? 'Camera') ?>
                                    </h5>

                                    <div class="camera-meta">
                                        <i class="dw dw-pin mr-1"></i>
                                        <?= esc($camera['location'] ?? '—') ?>
                                    </div>

                                    <div class="camera-meta mt-1">
                                        Assigned:
                                        <b><?= esc($camera['phase'] ?? 'Common Area') ?></b>
                                    </div>

                                </div>


                                <div class="camera-actions">

                                    <?php if ($hasFeed): ?>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary camera-action-btn"
                                            onclick="openCameraFullscreen('<?= esc($iframeId) ?>')"
                                        >
                                            Fullscreen
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-secondary camera-action-btn"
                                            onclick="reloadCamera('<?= esc($iframeId) ?>')"
                                        >
                                            Refresh
                                        </button>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-secondary camera-action-btn"
                                            disabled
                                        >
                                            Camera Offline
                                        </button>

                                    <?php endif; ?>

                                </div>
                            </div>

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



<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<!-- SHARED ADMIN DARK MODE -->
<script src="vendors/scripts/admin_theme.js"></script>

<script>
function reloadCamera(frameId) {
    const frame =
        document.getElementById(frameId);

    if (!frame) {
        return;
    }

    const currentSrc =
        frame.src;

    frame.src = '';

    window.setTimeout(function () {
        frame.src = currentSrc;
    }, 200);
}


function openCameraFullscreen(frameId) {
    const frame =
        document.getElementById(frameId);

    if (!frame) {
        return;
    }

    if (frame.requestFullscreen) {
        frame.requestFullscreen();
    } else if (frame.webkitRequestFullscreen) {
        frame.webkitRequestFullscreen();
    }
}
</script>


<div
    id="accessToast"
    class="access-toast"
>
    🚫 You do not have access to that part.
</div>

<script>
window.userPermissions =
    <?= json_encode($permissions ?? []) ?>;

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const toast =
            document.getElementById(
                'accessToast'
            );

        function showAccessToast() {

            if (!toast) {
                return;
            }

            toast.classList.add('show');

            setTimeout(
                function () {
                    toast.classList.remove('show');
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
                                !!window
                                    .userPermissions[
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