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




/*
|--------------------------------------------------------------------------
| CCTV CLIP RECORDING / SAVED CLIPS
|--------------------------------------------------------------------------
|
| Current prototype source:
| YouTube iframe feeds.
|
| Because browsers cannot directly record the bytes of a cross-origin
| YouTube iframe, the current system saves the exact start/end timestamps
| of the selected camera feed. The saved segment can then be reopened later.
|
| When the system is connected to direct CCTV HLS/WebRTC feeds, the same UI
| can be upgraded to save actual video files without changing the workflow.
|
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

        $requiredClipColumns = [
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

        $existingClipColumns = [];

        $columnResult = $conn->query(
            "SHOW COLUMNS FROM cctv_saved_clips"
        );

        if ($columnResult) {

            while ($column = $columnResult->fetch_assoc()) {
                $existingClipColumns[] =
                    (string)($column['Field'] ?? '');
            }

            $columnResult->free();
        }

        $missingClipColumns = array_values(
            array_diff(
                $requiredClipColumns,
                $existingClipColumns
            )
        );

        if (!$missingClipColumns) {

            $clipsTableReady = true;

        } else {

            $clipsTableProblem =
                'Missing database column(s): '
                . implode(', ', $missingClipColumns);
        }

    } else {

        $clipsTableProblem =
            'The cctv_saved_clips table does not exist.';
    }

} else {

    $clipsTableProblem =
        'The CCTV clip table could not be checked.';
}

function cctv_json_response(bool $ok, string $message, array $extra = []): void
{
    /*
     * AJAX responses must contain JSON only.
     * Clear any buffered warning/notice output first.
     */
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

    if ($payload === false) {
        $payload = json_encode([
            'ok' => false,
            'message' => 'Unable to create the CCTV server response.'
        ]);
    }

    echo $payload;
    exit;
}

function cctv_find_camera(array $cameras, string $cameraId): ?array
{
    foreach ($cameras as $camera) {
        if ((string)($camera['id'] ?? '') === $cameraId) {
            return $camera;
        }
    }

    return null;
}

/*
 * AJAX actions:
 * - save_clip
 * - delete_clip
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['cctv_clip_action'])
) {
    /*
     * Keep warnings/notices out of the JSON response.
     */
    if (ob_get_level() === 0) {
        ob_start();
    }

    if (!$clipsTableReady) {
        cctv_json_response(
            false,
            $clipsTableProblem !== ''
                ? 'CCTV clip database needs an update. ' . $clipsTableProblem
                : 'CCTV clip storage is not installed yet.'
        );
    }

    $csrf = (string)($_POST['csrf'] ?? '');

    if (
        $csrf === '' ||
        !hash_equals($cctvClipCsrf, $csrf)
    ) {
        cctv_json_response(
            false,
            'Your session token has expired. Refresh the page and try again.'
        );
    }

    $action = trim((string)($_POST['cctv_clip_action'] ?? ''));

    if ($action === 'save_clip') {
        $cameraId = trim((string)($_POST['camera_id'] ?? ''));
        $camera = cctv_find_camera($cameras, $cameraId);

        if (!$camera) {
            cctv_json_response(false, 'The selected camera could not be found.');
        }

        $videoId = trim((string)($camera['video_id'] ?? ''));

        if (
            !($camera['enabled'] ?? false) ||
            !valid_youtube_id($videoId)
        ) {
            cctv_json_response(false, 'This camera is not available for clipping.');
        }

        $clipTitle = mb_substr(
            trim((string)($_POST['clip_title'] ?? '')),
            0,
            180
        );

        $notes = mb_substr(
            trim((string)($_POST['notes'] ?? '')),
            0,
            1000
        );

        $startSeconds = (float)($_POST['start_seconds'] ?? -1);
        $endSeconds = (float)($_POST['end_seconds'] ?? -1);

        if ($clipTitle === '') {
            cctv_json_response(false, 'Please enter a clip title.');
        }

        if (
            !is_finite($startSeconds) ||
            !is_finite($endSeconds) ||
            $startSeconds < 0 ||
            $endSeconds <= $startSeconds
        ) {
            cctv_json_response(false, 'The selected clip time range is invalid.');
        }

        $durationSeconds = round($endSeconds - $startSeconds, 3);

        if ($durationSeconds < 1) {
            cctv_json_response(false, 'Record at least 1 second before saving the clip.');
        }

        /*
         * Keep demo clips practical. A normal CCTV system can support
         * much longer server-side recordings later.
         */
        if ($durationSeconds > 1800) {
            cctv_json_response(
                false,
                'For the current prototype, one saved clip can be up to 30 minutes.'
            );
        }

        $cameraName = mb_substr(
            (string)($camera['name'] ?? $cameraId),
            0,
            150
        );

        $cameraLocation = mb_substr(
            (string)($camera['location'] ?? ''),
            0,
            180
        );

        try {

            $stmt = $conn->prepare("
                INSERT INTO cctv_saved_clips
                (
                    phase,
                    camera_id,
                    camera_name,
                    camera_location,
                    source_type,
                    source_video_id,
                    clip_title,
                    notes,
                    start_seconds,
                    end_seconds,
                    duration_seconds,
                    recorded_by_admin_id
                )
                VALUES (
                    ?, ?, ?, ?,
                    'youtube_reference',
                    ?, ?, ?,
                    ?, ?, ?, ?
                )
            ");

            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to prepare CCTV clip save query: '
                    . $conn->error
                );
            }

            $stmt->bind_param(
                'sssssssdddi',
                $phase,
                $cameraId,
                $cameraName,
                $cameraLocation,
                $videoId,
                $clipTitle,
                $notes,
                $startSeconds,
                $endSeconds,
                $durationSeconds,
                $adminId
            );

            if (!$stmt->execute()) {
                throw new RuntimeException(
                    'Unable to save CCTV clip: '
                    . $stmt->error
                );
            }

            $clipId = (int)$conn->insert_id;
            $stmt->close();

        } catch (Throwable $e) {

            error_log(
                'CCTV clip save failed: '
                . $e->getMessage()
            );

            cctv_json_response(
                false,
                'The CCTV clip could not be saved to the database. Run cctv_saved_clips_repair.sql, refresh the page, and try again.'
            );
        }

        cctv_json_response(
            true,
            'CCTV clip saved successfully.',
            ['clip_id' => $clipId]
        );
    }

    if ($action === 'delete_clip') {
        $clipId = (int)($_POST['clip_id'] ?? 0);

        if ($clipId <= 0) {
            cctv_json_response(false, 'Invalid clip selected.');
        }

        try {

            $stmt = $conn->prepare("
                DELETE FROM cctv_saved_clips
                WHERE id = ?
                  AND phase = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to prepare CCTV clip delete query: '
                    . $conn->error
                );
            }

            $stmt->bind_param(
                'is',
                $clipId,
                $phase
            );

            if (!$stmt->execute()) {
                throw new RuntimeException(
                    'Unable to delete CCTV clip: '
                    . $stmt->error
                );
            }

            $deleted =
                $stmt->affected_rows > 0;

            $stmt->close();

            if (!$deleted) {
                cctv_json_response(
                    false,
                    'The saved clip could not be found.'
                );
            }

            cctv_json_response(
                true,
                'Saved CCTV clip deleted.'
            );

        } catch (Throwable $e) {

            error_log(
                'CCTV clip delete failed: '
                . $e->getMessage()
            );

            cctv_json_response(
                false,
                'The saved CCTV clip could not be deleted because of a database error.'
            );
        }
    }

    cctv_json_response(false, 'Unsupported CCTV clip action.');
}

$totalCameras = count($cameras);



$configuredCameras = 0;

/*
 * YouTube IFrame API works more reliably when the exact page origin
 * is supplied. This automatically works for localhost, LAN, ngrok,
 * HTTPS hosting, and the future deployed domain.
 */
$youtubeOriginScheme =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : 'http';

$youtubeOriginHost =
    (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

$youtubeOrigin =
    $youtubeOriginScheme . '://' . $youtubeOriginHost;




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

\>

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

    

        /* =========================================================
           CCTV CLIP RECORDING
           ========================================================= */

        .clip-record-btn.is-recording {
            color: #fff !important;
            background: #dc2626 !important;
            border-color: #dc2626 !important;
            box-shadow: 0 0 0 3px rgba(220,38,38,.12);
        }

        .clip-record-btn.is-recording .clip-record-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            margin-right: 5px;
            border-radius: 50%;
            background: #fff;
            animation: livePulse 1s infinite;
        }

        .clip-recording-indicator {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 11;
            display: none;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            color: #fff;
            background: rgba(185, 28, 28, .95);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .04em;
            box-shadow: 0 4px 12px rgba(0,0,0,.18);
        }

        .clip-recording-indicator.show {
            display: inline-flex;
        }

        .clip-recording-indicator span {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #fff;
            animation: livePulse 1s infinite;
        }

        .clip-archive-card {
            overflow: hidden;
        }

        .clip-time-code {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 8px;
            background: #f1f5f9;
            color: #334155;
            font-family: Consolas, "Courier New", monospace;
            font-size: 12px;
            font-weight: 700;
        }

        .clip-note {
            max-width: 330px;
            white-space: normal;
            word-break: break-word;
        }

        .clip-empty-state {
            padding: 34px 20px;
            text-align: center;
            color: #64748b;
        }

        .clip-empty-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            color: #2563eb;
            background: #eff6ff;
            font-size: 24px;
        }

        .clip-source-note {
            border-left: 4px solid #3b82f6;
        }

        .clip-playback-frame {
            width: 100%;
            aspect-ratio: 16 / 9;
            border: 0;
            background: #020617;
        }

        html.dark .clip-time-code {
            color: #cbd5e1 !important;
            background: rgba(148,163,184,.12) !important;
        }

        html.dark .clip-empty-state {
            color: var(--admin-muted) !important;
        }

        html.dark .clip-empty-icon {
            color: #93c5fd !important;
            background: rgba(37,99,235,.15) !important;
        }

        html.dark #saveClipModal .modal-content,
        html.dark #playClipModal .modal-content {
            background: var(--admin-surface) !important;
            color: var(--admin-text) !important;
            border-color: var(--admin-border) !important;
        }

        html.dark #saveClipModal .modal-header,
        html.dark #playClipModal .modal-header,
        html.dark #saveClipModal .modal-footer,
        html.dark #playClipModal .modal-footer {
            border-color: var(--admin-border) !important;
        }

        html.dark #saveClipModal .close,
        html.dark #playClipModal .close {
            color: #fff !important;
            text-shadow: none !important;
        }

        @media (max-width: 767.98px) {
            .camera-actions {
                width: 100%;
            }

            .camera-actions .camera-action-btn {
                flex: 1 1 auto;
            }

            .clip-archive-actions {
                min-width: 155px;
            }
        }



        /* =========================================================
           CCTV CUSTOM FEEDBACK
           No browser "localhost says" popups.
           ========================================================= */

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

        .clip-inline-error {
            display: none;
            margin-top: 6px;
            color: #dc2626;
            font-size: 12px;
            font-weight: 600;
        }

        .clip-inline-error.show {
            display: block;
        }

        html.dark #deleteClipModal .modal-content {
            background: var(--admin-surface) !important;
            color: var(--admin-text) !important;
            border-color: var(--admin-border) !important;
        }

        html.dark #deleteClipModal .modal-header,
        html.dark #deleteClipModal .modal-footer {
            border-color: var(--admin-border) !important;
        }

        html.dark #deleteClipModal .close {
            color: #fff !important;
            text-shadow: none !important;
        }

        @media (max-width: 575.98px) {
            .cctv-ui-toast {
                top: 72px;
                left: 16px;
                right: 16px;
                width: auto;
            }
        }



        .clip-record-btn.player-loading {
            opacity: .72;
            cursor: wait;
        }

        .clip-record-btn.player-ready {
            opacity: 1;
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

                        Live security monitoring • Save important moments as clips

                    </div>

                </div>



                <div class="col-md-4 col-sm-12 text-md-right mt-3 mt-md-0">

                    <a
                        href="cctv_archive.php"
                        class="btn btn-sm btn-outline-primary mr-2"
                    >
                        <i class="dw dw-folder mr-1"></i>
                        CCTV Archive
                    </a>

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



                                <div
                                    class="clip-recording-indicator"
                                    id="<?= esc($iframeId) ?>_recording"
                                >
                                    <span></span>
                                    RECORDING CLIP
                                </div>

                                <iframe

                                    class="cctv-youtube-frame"
                                    data-camera-id="<?= esc($camera['id'] ?? '') ?>"
                                    data-camera-name="<?= esc($camera['name'] ?? '') ?>"
                                    data-video-id="<?= esc($videoId) ?>"
                                    id="<?= esc($iframeId) ?>"

                                    src="https://www.youtube.com/embed/<?= esc($videoId) ?>?autoplay=1&mute=1&controls=1&rel=0&playsinline=1&enablejsapi=1&origin=<?= rawurlencode($youtubeOrigin) ?>"

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

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger camera-action-btn clip-record-btn"
                                            data-camera-id="<?= esc($camera['id'] ?? '') ?>"
                                            data-camera-name="<?= esc($camera['name'] ?? '') ?>"
                                            data-frame-id="<?= esc($iframeId) ?>"
                                            onclick="toggleCameraClip(this)"
                                            <?= !$clipsTableReady ? 'disabled' : '' ?>
                                            title="<?= !$clipsTableReady ? 'Install CCTV clip storage first' : 'Start or stop a saved CCTV clip' ?>"
                                        >
                                            <span class="clip-record-dot"></span>
                                            <span class="clip-record-label">Record Clip</span>
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








        <!-- SAVE CCTV CLIP MODAL -->
        <div class="modal fade" id="saveClipModal" tabindex="-1" role="dialog" aria-hidden="true">

            <div class="modal-dialog modal-dialog-centered" role="document">

                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title">Save CCTV Clip</h5>

                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body">

                        <div class="alert alert-light border">
                            <div class="weight-600" id="clipCameraSummary">Camera</div>
                            <div class="small text-secondary" id="clipTimeSummary">00:00 → 00:00</div>
                        </div>

                        <div class="form-group">
                            <label>Clip Title <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                id="clipTitle"
                                class="form-control"
                                maxlength="180"
                                placeholder="Example: Suspicious activity near Main Gate"
                            >

                            <div id="clipTitleError" class="clip-inline-error">
                                Please enter a clip title.
                            </div>
                        </div>

                        <div class="form-group mb-0">
                            <label>Notes</label>
                            <textarea
                                id="clipNotes"
                                class="form-control"
                                rows="3"
                                maxlength="1000"
                                placeholder="Optional incident details, people involved, reason for saving, etc."
                            ></textarea>
                        </div>

                    </div>

                    <div class="modal-footer">

                        <button type="button" class="btn btn-secondary" data-dismiss="modal">
                            Cancel
                        </button>

                        <button type="button" class="btn btn-danger" id="saveClipButton" onclick="savePendingClip()">
                            Save Clip
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
            <span id="cctvUiToastTitle" class="cctv-ui-toast-title">
                CCTV Monitoring
            </span>

            <div id="cctvUiToastMessage" class="cctv-ui-toast-message"></div>
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

const cctvClipCsrf = <?= json_encode($cctvClipCsrf) ?>;
const cctvClipStorageReady = <?= $clipsTableReady ? 'true' : 'false' ?>;

const cctvPlayers = {};
let activeCctvClip = null;
let pendingCctvClip = null;
let cctvToastTimer = null;


function showCctvToast(message, type = 'info', title = 'CCTV Monitoring') {

    const toast =
        document.getElementById('cctvUiToast');

    const toastTitle =
        document.getElementById('cctvUiToastTitle');

    const toastMessage =
        document.getElementById('cctvUiToastMessage');

    if (!toast || !toastTitle || !toastMessage) {
        return;
    }

    const allowedTypes = [
        'success',
        'warning',
        'danger',
        'info'
    ];

    const finalType =
        allowedTypes.includes(type)
            ? type
            : 'info';

    toast.classList.remove(
        'success',
        'warning',
        'danger',
        'info'
    );

    toast.classList.add(finalType);

    toastTitle.textContent =
        title;

    toastMessage.textContent =
        String(message || '');

    toast.classList.add('show');

    if (cctvToastTimer) {
        window.clearTimeout(cctvToastTimer);
    }

    cctvToastTimer =
        window.setTimeout(
            function () {
                toast.classList.remove('show');
            },
            3500
        );
}


function showClipTitleError(show) {

    const input =
        document.getElementById('clipTitle');

    const error =
        document.getElementById('clipTitleError');

    if (input) {
        input.classList.toggle(
            'is-invalid',
            !!show
        );
    }

    if (error) {
        error.classList.toggle(
            'show',
            !!show
        );
    }
}


/* =========================================================
   YOUTUBE CAMERA PLAYER API
   Robust initialization:
   - callback is registered BEFORE loading iframe_api
   - also initializes immediately if API was already cached/loaded
   - prevents duplicate Player instances
   ========================================================= */

let cctvYouTubeApiRequested = false;


function setCctvRecordButtonState(frameId, state) {

    const button =
        document.querySelector(
            '.clip-record-btn[data-frame-id="' +
            CSS.escape(frameId) +
            '"]'
        );

    if (!button) {
        return;
    }

    button.classList.remove(
        'player-loading',
        'player-ready'
    );

    const label =
        button.querySelector(
            '.clip-record-label'
        );

    if (state === 'loading') {

        button.classList.add(
            'player-loading'
        );

        /*
         * Only temporarily disable when clip storage itself is ready.
         * Buttons disabled by PHP because SQL is missing stay disabled.
         */
        if (cctvClipStorageReady) {
            button.disabled = true;
        }

        if (label) {
            label.textContent =
                'Loading Camera...';
        }

        return;
    }

    if (state === 'ready') {

        button.classList.add(
            'player-ready'
        );

        if (cctvClipStorageReady) {
            button.disabled = false;
        }

        if (label) {
            label.textContent =
                'Record Clip';
        }

        return;
    }

    if (state === 'error') {

        if (cctvClipStorageReady) {
            button.disabled = false;
        }

        if (label) {
            label.textContent =
                'Retry Record';
        }
    }
}


function initializeCctvPlayers() {

    if (
        !window.YT ||
        typeof window.YT.Player !== 'function'
    ) {
        return false;
    }

    document
        .querySelectorAll(
            '.cctv-youtube-frame'
        )
        .forEach(function (frame) {

            const frameId =
                String(frame.id || '');

            if (!frameId) {
                return;
            }

            /*
             * Do not create the same YouTube Player twice.
             */
            if (
                cctvPlayers[frameId] &&
                typeof cctvPlayers[frameId].getCurrentTime === 'function'
            ) {
                setCctvRecordButtonState(
                    frameId,
                    'ready'
                );
                return;
            }

            if (
                frame.dataset.playerInitializing === '1'
            ) {
                return;
            }

            frame.dataset.playerInitializing = '1';

            setCctvRecordButtonState(
                frameId,
                'loading'
            );

            try {

                const player =
                    new YT.Player(
                        frameId,
                        {
                            events: {

                                onReady: function (event) {

                                    cctvPlayers[frameId] =
                                        event.target;

                                    const liveFrame =
                                        document.getElementById(
                                            frameId
                                        );

                                    if (liveFrame) {
                                        liveFrame.dataset.playerReady =
                                            '1';

                                        liveFrame.dataset.playerInitializing =
                                            '0';
                                    }

                                    setCctvRecordButtonState(
                                        frameId,
                                        'ready'
                                    );
                                },

                                onError: function (event) {

                                    console.warn(
                                        'CCTV YouTube player error:',
                                        frameId,
                                        event.data
                                    );

                                    const liveFrame =
                                        document.getElementById(
                                            frameId
                                        );

                                    if (liveFrame) {
                                        liveFrame.dataset.playerInitializing =
                                            '0';
                                    }

                                    delete cctvPlayers[frameId];

                                    setCctvRecordButtonState(
                                        frameId,
                                        'error'
                                    );
                                }
                            }
                        }
                    );

                /*
                 * Store immediately too. getCurrentTime may not be usable
                 * until onReady, so getCctvPlayer() still validates readiness.
                 */
                cctvPlayers[frameId] =
                    player;

            } catch (error) {

                console.warn(
                    'Unable to initialize CCTV player:',
                    frameId,
                    error
                );

                frame.dataset.playerInitializing =
                    '0';

                delete cctvPlayers[frameId];

                setCctvRecordButtonState(
                    frameId,
                    'error'
                );
            }
        });

    return true;
}


/*
 * YouTube calls this global function when iframe_api becomes ready.
 * It is deliberately defined BEFORE the API script is added.
 */
window.onYouTubeIframeAPIReady = function () {
    initializeCctvPlayers();
};


function loadCctvYouTubeApi() {

    /*
     * If another page script already loaded the API, initialize now.
     */
    if (
        window.YT &&
        typeof window.YT.Player === 'function'
    ) {
        initializeCctvPlayers();
        return;
    }

    if (cctvYouTubeApiRequested) {
        return;
    }

    cctvYouTubeApiRequested = true;

    document
        .querySelectorAll(
            '.cctv-youtube-frame'
        )
        .forEach(function (frame) {

            setCctvRecordButtonState(
                frame.id,
                'loading'
            );
        });

    const apiScript =
        document.createElement(
            'script'
        );

    apiScript.src =
        'https://www.youtube.com/iframe_api';

    apiScript.async =
        true;

    apiScript.onerror =
        function () {

            cctvYouTubeApiRequested =
                false;

            document
                .querySelectorAll(
                    '.cctv-youtube-frame'
                )
                .forEach(function (frame) {

                    setCctvRecordButtonState(
                        frame.id,
                        'error'
                    );
                });

            showCctvToast(
                'The camera control service could not load. Check the internet connection and try again.',
                'danger',
                'Camera Controls Unavailable'
            );
        };

    document.head.appendChild(
        apiScript
    );
}


/*
 * Start after the page DOM exists.
 * A second delayed attempt handles very fast browser caching,
 * extensions, and slower mobile WebViews more reliably.
 */
if (document.readyState === 'loading') {

    document.addEventListener(
        'DOMContentLoaded',
        function () {
            loadCctvYouTubeApi();

            window.setTimeout(
                initializeCctvPlayers,
                1200
            );
        },
        { once: true }
    );

} else {

    loadCctvYouTubeApi();

    window.setTimeout(
        initializeCctvPlayers,
        1200
    );
}


/* =========================================================
   PLAYER READY RETRY
   ========================================================= */

async function waitForCctvPlayer(
    frameId,
    timeoutMs = 6000
) {

    const startedAt =
        Date.now();

    /*
     * Ensure API/player initialization has at least been requested.
     */
    loadCctvYouTubeApi();
    initializeCctvPlayers();

    while (
        (Date.now() - startedAt) <
        timeoutMs
    ) {

        const player =
            getCctvPlayer(
                frameId
            );

        if (player) {
            return player;
        }

        await new Promise(
            function (resolve) {
                window.setTimeout(
                    resolve,
                    150
                );
            }
        );

        initializeCctvPlayers();
    }

    return null;
}


/* =========================================================
   HELPERS
   ========================================================= */

function cctvFormatSeconds(value) {

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

    const remainingSeconds =
        seconds % 60;

    return [
        hours,
        minutes,
        remainingSeconds
    ]
        .map(function (part) {
            return String(part).padStart(2, '0');
        })
        .join(':');
}


function getCctvPlayer(frameId) {

    const player =
        cctvPlayers[frameId];

    if (
        !player ||
        typeof player.getCurrentTime !== 'function'
    ) {
        return null;
    }

    return player;
}


function resetActiveClipUi() {

    if (!activeCctvClip) {
        return;
    }

    const button =
        document.querySelector(
            '.clip-record-btn[data-frame-id="' +
            CSS.escape(activeCctvClip.frameId) +
            '"]'
        );

    if (button) {

        button.classList.remove(
            'is-recording'
        );

        const label =
            button.querySelector(
                '.clip-record-label'
            );

        if (label) {
            label.textContent =
                'Record Clip';
        }
    }

    const indicator =
        document.getElementById(
            activeCctvClip.frameId +
            '_recording'
        );

    if (indicator) {
        indicator.classList.remove(
            'show'
        );
    }
}


/* =========================================================
   START / STOP CLIP
   ========================================================= */

async function toggleCameraClip(button) {

    if (!cctvClipStorageReady) {
        showCctvToast(
            'Clip storage is not installed yet. Run cctv_saved_clips_setup.sql first.',
            'warning',
            'Clip Storage'
        );
        return;
    }

    const frameId =
        String(
            button.dataset.frameId || ''
        );

    const cameraId =
        String(
            button.dataset.cameraId || ''
        );

    const cameraName =
        String(
            button.dataset.cameraName || cameraId
        );

    let player =
        getCctvPlayer(frameId);

    if (!player) {

        setCctvRecordButtonState(
            frameId,
            'loading'
        );

        player =
            await waitForCctvPlayer(
                frameId,
                6000
            );
    }

    if (!player) {

        setCctvRecordButtonState(
            frameId,
            'error'
        );

        showCctvToast(
            'The camera video is visible, but its playback controls did not connect. Press Retry Record once the video is playing.',
            'warning',
            'Camera Control Not Ready'
        );

        return;
    }

    /*
     * START RECORDING
     */
    if (!activeCctvClip) {

        let startSeconds = 0;

        try {
            startSeconds =
                Number(
                    player.getCurrentTime() || 0
                );
        } catch (error) {

            showCctvToast(
                'The video controls are not ready yet. Let the camera play for a moment, then try Record Clip again.',
                'warning',
                'Camera Control Not Ready'
            );

            setCctvRecordButtonState(
                frameId,
                'error'
            );

            return;
        }

        activeCctvClip = {
            frameId: frameId,
            cameraId: cameraId,
            cameraName: cameraName,
            startSeconds: startSeconds,
            wallStartedAt: Date.now()
        };

        button.classList.add(
            'is-recording'
        );

        const label =
            button.querySelector(
                '.clip-record-label'
            );

        if (label) {
            label.textContent =
                'Stop & Save';
        }

        const indicator =
            document.getElementById(
                frameId +
                '_recording'
            );

        if (indicator) {
            indicator.classList.add(
                'show'
            );
        }

        return;
    }

    /*
     * Only one clip can be active at a time.
     */
    if (
        activeCctvClip.frameId !==
        frameId
    ) {
        showCctvToast(
            'A clip is already recording from ' +
            activeCctvClip.cameraName +
            '. Stop that clip first.',
            'warning',
            'Recording In Progress'
        );
        return;
    }

    /*
     * STOP RECORDING
     */
    let endSeconds = 0;

    try {
        endSeconds =
            Number(
                player.getCurrentTime() || 0
            );
    } catch (error) {

        /*
         * If controls briefly disconnect after recording already started,
         * preserve the clip using elapsed wall-clock time.
         */
        endSeconds =
            activeCctvClip.startSeconds +
            Math.max(
                1,
                (
                    Date.now() -
                    activeCctvClip.wallStartedAt
                ) / 1000
            );
    }

    /*
     * If the user pauses/seeks and the source time does not move,
     * use the wall-clock duration as a safe fallback.
     */
    if (
        endSeconds <=
        activeCctvClip.startSeconds
    ) {
        const elapsed =
            Math.max(
                1,
                (
                    Date.now() -
                    activeCctvClip.wallStartedAt
                ) / 1000
            );

        endSeconds =
            activeCctvClip.startSeconds +
            elapsed;
    }

    pendingCctvClip = {
        cameraId:
            activeCctvClip.cameraId,
        cameraName:
            activeCctvClip.cameraName,
        startSeconds:
            activeCctvClip.startSeconds,
        endSeconds:
            endSeconds
    };

    resetActiveClipUi();
    activeCctvClip = null;

    const duration =
        pendingCctvClip.endSeconds -
        pendingCctvClip.startSeconds;

    document.getElementById(
        'clipCameraSummary'
    ).textContent =
        pendingCctvClip.cameraName;

    document.getElementById(
        'clipTimeSummary'
    ).textContent =
        cctvFormatSeconds(
            pendingCctvClip.startSeconds
        ) +
        ' → ' +
        cctvFormatSeconds(
            pendingCctvClip.endSeconds
        ) +
        ' • ' +
        cctvFormatSeconds(
            duration
        );

    const now =
        new Date();

    const defaultTitle =
        pendingCctvClip.cameraName +
        ' Clip - ' +
        now.toLocaleString();

    document.getElementById(
        'clipTitle'
    ).value =
        defaultTitle;

    showClipTitleError(false);

    document.getElementById(
        'clipNotes'
    ).value = '';

    $('#saveClipModal')
        .modal('show');
}


/* =========================================================
   SAVE CLIP
   ========================================================= */

async function savePendingClip() {

    if (!pendingCctvClip) {
        return;
    }

    const title =
        String(
            document.getElementById(
                'clipTitle'
            ).value || ''
        ).trim();

    const notes =
        String(
            document.getElementById(
                'clipNotes'
            ).value || ''
        ).trim();

    if (!title) {
        showClipTitleError(true);

        const titleInput =
            document.getElementById('clipTitle');

        if (titleInput) {
            titleInput.focus();
        }

        showCctvToast(
            'Please enter a clip title before saving.',
            'warning',
            'Clip Title Required'
        );
        return;
    }

    const button =
        document.getElementById(
            'saveClipButton'
        );

    button.disabled = true;
    button.textContent =
        'Saving...';

    const formData =
        new FormData();

    formData.append(
        'cctv_clip_action',
        'save_clip'
    );

    formData.append(
        'csrf',
        cctvClipCsrf
    );

    formData.append(
        'camera_id',
        pendingCctvClip.cameraId
    );

    formData.append(
        'clip_title',
        title
    );

    formData.append(
        'notes',
        notes
    );

    formData.append(
        'start_seconds',
        String(
            pendingCctvClip.startSeconds
        )
    );

    formData.append(
        'end_seconds',
        String(
            pendingCctvClip.endSeconds
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
                'CCTV save returned a non-JSON response:',
                responseText
            );

            throw new Error(
                'The server returned an unexpected response while saving the clip. Check the CCTV clip database setup.'
            );
        }

        if (!result.ok) {
            throw new Error(
                result.message ||
                'Unable to save CCTV clip.'
            );
        }

        pendingCctvClip = null;

        $('#saveClipModal')
            .modal('hide');

        showCctvToast(
            'CCTV clip saved successfully.',
            'success',
            'Clip Saved'
        );

        window.setTimeout(
            function () {
                window.location.reload();
            },
            700
        );

    } catch (error) {

        showCctvToast(
            error.message ||
            'Unable to save CCTV clip.',
            'danger',
            'Save Failed'
        );

    } finally {

        button.disabled = false;
        button.textContent =
            'Save Clip';
    }
}


document
    .getElementById(
        'clipTitle'
    )
    .addEventListener(
        'input',
        function () {
            if (this.value.trim() !== '') {
                showClipTitleError(false);
            }
        }
    );


/*
 * If the Save modal is dismissed, the just-recorded clip has
 * not been committed to the database.
 */
$('#saveClipModal')
    .on(
        'hidden.bs.modal',
        function () {

            pendingCctvClip = null;
        }
    );

</script>


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

\>

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