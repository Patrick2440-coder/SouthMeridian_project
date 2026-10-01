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
    http_response_code(403);
    exit('Access denied.');
}

if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
    http_response_code(403);
    exit('Access denied.');
}

$adminId = (int)$_SESSION['admin_id'];

$stmt = $conn->prepare("
    SELECT phase
    FROM admins
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param('i', $adminId);
$stmt->execute();

$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

$phase = (string)($admin['phase'] ?? '');

$allowedPhases = [
    'Phase 1',
    'Phase 2',
    'Phase 3',
];

if (!in_array($phase, $allowedPhases, true)) {
    http_response_code(403);
    exit('Invalid phase.');
}


/*
|--------------------------------------------------------------------------
| LOAD CLIP
|--------------------------------------------------------------------------
*/

$clipId = (int)($_GET['id'] ?? 0);

if ($clipId <= 0) {
    http_response_code(400);
    exit('Invalid clip.');
}

$stmt = $conn->prepare("
    SELECT
        id,
        phase,
        clip_title,
        source_type,
        file_path
    FROM cctv_saved_clips
    WHERE id = ?
      AND phase = ?
    LIMIT 1
");

$stmt->bind_param(
    'is',
    $clipId,
    $phase
);

$stmt->execute();

$clip =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();

if (!$clip) {
    http_response_code(404);
    exit('CCTV clip not found.');
}

if (
    (string)($clip['source_type'] ?? '') !==
    'video_file'
) {
    http_response_code(409);
    exit(
        'This saved clip is a timestamp reference and has no downloadable video file.'
    );
}

$filePath =
    trim(
        (string)(
            $clip['file_path']
            ?? ''
        )
    );

if ($filePath === '') {
    http_response_code(404);
    exit('CCTV clip file is missing.');
}


/*
|--------------------------------------------------------------------------
| RESOLVE FILE SAFELY
|--------------------------------------------------------------------------
|
| Preferred database value:
| uploads/cctv_clips/example.mp4
|
| The file must remain inside the admin project directory.
|
*/

$adminBase =
    realpath(__DIR__);

if ($adminBase === false) {
    http_response_code(500);
    exit('Unable to resolve application directory.');
}

/*
 * Accept project-relative file paths.
 * Strip leading slash so paths cannot become absolute.
 */
$relativePath =
    ltrim(
        str_replace(
            ['\\', "\0"],
            ['/', ''],
            $filePath
        ),
        '/'
    );

$absolutePath =
    realpath(
        $adminBase .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath
        )
    );

if (
    $absolutePath === false ||
    !is_file($absolutePath)
) {
    http_response_code(404);
    exit('CCTV clip video file was not found.');
}

/*
 * Prevent directory traversal / downloads outside admin/.
 */
$normalizedAdminBase =
    rtrim(
        str_replace('\\', '/', $adminBase),
        '/'
    ) . '/';

$normalizedAbsolute =
    str_replace(
        '\\',
        '/',
        $absolutePath
    );

if (
    strpos(
        $normalizedAbsolute,
        $normalizedAdminBase
    ) !== 0
) {
    http_response_code(403);
    exit('Invalid CCTV clip file path.');
}


/*
|--------------------------------------------------------------------------
| DOWNLOAD RESPONSE
|--------------------------------------------------------------------------
*/

$extension =
    strtolower(
        pathinfo(
            $absolutePath,
            PATHINFO_EXTENSION
        )
    );

$allowedExtensions = [
    'mp4',
    'webm',
    'mov',
    'm4v',
];

if (
    !in_array(
        $extension,
        $allowedExtensions,
        true
    )
) {
    http_response_code(403);
    exit('Unsupported CCTV clip file format.');
}

$mimeMap = [
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mov'  => 'video/quicktime',
    'm4v'  => 'video/x-m4v',
];

$mimeType =
    $mimeMap[$extension]
    ?? 'application/octet-stream';

$title =
    trim(
        (string)(
            $clip['clip_title']
            ?? 'CCTV Clip'
        )
    );

$safeTitle =
    preg_replace(
        '/[^A-Za-z0-9 _.-]+/',
        '',
        $title
    );

$safeTitle =
    trim(
        (string)$safeTitle
    );

if ($safeTitle === '') {
    $safeTitle =
        'CCTV Clip';
}

$downloadName =
    $safeTitle .
    '.' .
    $extension;

$fileSize =
    filesize(
        $absolutePath
    );

if ($fileSize === false) {
    http_response_code(500);
    exit('Unable to read CCTV clip file.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header(
    'Content-Type: ' .
    $mimeType
);

header(
    'Content-Disposition: attachment; filename="' .
    addslashes(
        $downloadName
    ) .
    '"'
);

header(
    'Content-Length: ' .
    (string)$fileSize
);

header(
    'Cache-Control: private, no-store, no-cache, must-revalidate'
);

header(
    'Pragma: no-cache'
);

header(
    'X-Content-Type-Options: nosniff'
);

readfile(
    $absolutePath
);

exit;
