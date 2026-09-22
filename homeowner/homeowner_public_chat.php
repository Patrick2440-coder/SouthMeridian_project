<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
  header("Location: ../index.php");
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);




require_once 'tenant_module_guard.php';

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function normalizeWebPath(string $path): string {
  $path = str_replace('\\', '/', $path);
  $path = preg_replace('#/+#', '/', $path);
  return $path;
}

function fixChatAttachmentPath(?string $path): string {
  $path = trim((string)$path);
  if ($path === '') return '';

  $path = str_replace('\\', '/', $path);
  $path = preg_replace('#/+#', '/', $path);

  if (strpos($path, '../homeowner/uploads/chat_files/') === 0) {
    return $path;
  }

  if (strpos($path, 'uploads/chat_files/') === 0) {
    return '../homeowner/' . $path;
  }

  if (strpos($path, 'homeowner/uploads/chat_files/') !== false) {
    $pos = strpos($path, 'homeowner/uploads/chat_files/');
    return '../' . substr($path, $pos);
  }

  return $path;
}

function isImageMime(?string $mime): bool {
  if (!$mime) return false;
  return in_array(strtolower($mime), [
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/gif',
    'image/webp'
  ], true);
}

function uploadChatAttachment(array $file): array {
  if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
    return ['success' => true, 'uploaded' => false];
  }

  if ($file['error'] !== UPLOAD_ERR_OK) {
    if (in_array((int)$file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
      return [
        'success' => false,
        'message' => 'The selected media is larger than the server upload limit.'
      ];
    }

    return ['success' => false, 'message' => 'Failed to upload file.'];
  }

  if (!is_uploaded_file($file['tmp_name'])) {
    return ['success' => false, 'message' => 'Invalid uploaded file.'];
  }

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = strtolower((string)finfo_file($finfo, $file['tmp_name']));
  finfo_close($finfo);

  $allowed = [
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/gif',
    'image/webp',

    'video/mp4',
    'video/webm',
    'video/quicktime',
    'video/x-m4v',
    'video/3gpp',

    'audio/webm',
    'audio/ogg',
    'audio/mpeg',
    'audio/mp4',
    'audio/x-m4a',
    'audio/aac',
    'audio/wav',
    'audio/x-wav',
    'audio/3gpp',

    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain'
  ];

  if (!in_array($mime, $allowed, true)) {
    return [
      'success' => false,
      'message' => 'Allowed files: photos, videos, voice messages, PDF, DOC/DOCX, XLS/XLSX, and TXT.'
    ];
  }

  $isVideo = str_starts_with($mime, 'video/');
  $isAudio = str_starts_with($mime, 'audio/');

  $maxSize =
    $isVideo
      ? (25 * 1024 * 1024)
      : (
          $isAudio
            ? (10 * 1024 * 1024)
            : (10 * 1024 * 1024)
        );

  if ((int)$file['size'] > $maxSize) {
    return [
      'success' => false,
      'message' =>
        $isVideo
          ? 'Video must not exceed 25MB.'
          : (
              $isAudio
                ? 'Voice message must not exceed 10MB.'
                : 'Photo or attachment must not exceed 10MB.'
            )
    ];
  }

  $uploadDirFs = __DIR__ . '/uploads/chat_files/';
  $uploadDirWeb = '../homeowner/uploads/chat_files/';

  if (!is_dir($uploadDirFs)) {
    if (!mkdir($uploadDirFs, 0755, true) && !is_dir($uploadDirFs)) {
      return ['success' => false, 'message' => 'Upload folder could not be created.'];
    }
  }

  $originalName = basename((string)$file['name']);

  $isRecordedVoice =
    preg_match('/^voice_\d+\./i', $originalName) === 1;

  $storedMime =
    $isRecordedVoice
      ? (
          $mime === 'video/webm'
            ? 'audio/webm'
            : (
                str_starts_with($mime, 'audio/')
                  ? $mime
                  : $mime
              )
        )
      : $mime;

  $extensionMap = [
    'image/jpeg' => 'jpg',
    'image/jpg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',

    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
    'video/quicktime' => 'mov',
    'video/x-m4v' => 'm4v',
    'video/3gpp' => '3gp',

    'audio/webm' => 'webm',
    'audio/ogg' => 'ogg',
    'audio/mpeg' => 'mp3',
    'audio/mp4' => 'm4a',
    'audio/x-m4a' => 'm4a',
    'audio/aac' => 'aac',
    'audio/wav' => 'wav',
    'audio/x-wav' => 'wav',
    'audio/3gpp' => '3gp',

    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/plain' => 'txt'
  ];

  $safeExt = $extensionMap[$mime] ?? '';

  if ($safeExt === '') {
    return ['success' => false, 'message' => 'Unsupported attachment type.'];
  }

  $newName = bin2hex(random_bytes(16)) . '.' . $safeExt;
  $destFs = $uploadDirFs . $newName;

  if (!move_uploaded_file($file['tmp_name'], $destFs)) {
    return ['success' => false, 'message' => 'Failed to save uploaded file.'];
  }

  $webPath = normalizeWebPath($uploadDirWeb . $newName);

  return [
    'success' => true,
    'uploaded' => true,
    'attachment_name' => $originalName,
    'attachment_path' => $webPath,
    'attachment_type' => $storedMime
  ];
}

/*
|--------------------------------------------------------------------------
| Public chat text moderation
|--------------------------------------------------------------------------
|
| Public messages use a local profanity filter first.
| A second free check is sent to PurgoMalum when internet access is
| available. No API key is required.
|
| If the external service is temporarily unavailable, the local filter
| still works and chat remains available.
|
*/


function deleteChatAttachmentFile(?string $path): void {
  $path = trim((string)$path);
  if ($path === '') return;

  $baseName = basename(str_replace('\\', '/', $path));
  if ($baseName === '' || $baseName === '.' || $baseName === '..') return;

  $uploadRoot = realpath(__DIR__ . '/uploads/chat_files');
  if ($uploadRoot === false) return;

  $candidate = $uploadRoot . DIRECTORY_SEPARATOR . $baseName;
  $realCandidate = realpath($candidate);

  if (
    $realCandidate === false ||
    !is_file($realCandidate) ||
    strncmp(
      $realCandidate,
      $uploadRoot . DIRECTORY_SEPARATOR,
      strlen($uploadRoot . DIRECTORY_SEPARATOR)
    ) !== 0
  ) {
    return;
  }

  @unlink($realCandidate);
}

function normalizeModerationText(string $text): string {
  $text = mb_strtolower($text, 'UTF-8');

  $text = strtr($text, [
    '0' => 'o',
    '1' => 'i',
    '3' => 'e',
    '4' => 'a',
    '5' => 's',
    '7' => 't',
    '@' => 'a',
    '$' => 's'
  ]);

  $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
  $text = preg_replace('/\s+/u', ' ', trim((string)$text));

  return $text;
}

function localPublicChatModeration(string $message): array {
  $normalized = normalizeModerationText($message);

  if ($normalized === '') {
    return [
      'flagged' => false,
      'source' => 'local',
      'reason' => ''
    ];
  }

  $blockedTerms = [
    'fuck',
    'fucking',
    'fucker',
    'motherfucker',
    'shit',
    'bullshit',
    'bitch',
    'asshole',
    'dumbass',
    'bastard',
    'puta',
    'putang ina',
    'putangina',
    'tang ina',
    'tangina',
    'gago',
    'gaga',
    'tanga',
    'bobo',
    'ulol',
    'punyeta',
    'leche',
    'yawa'
  ];

  foreach ($blockedTerms as $term) {
    $normalizedTerm = normalizeModerationText($term);

    $pattern =
      '/(?:^|\s)' .
      preg_quote($normalizedTerm, '/') .
      '(?:$|\s)/u';

    if (preg_match($pattern, $normalized)) {
      return [
        'flagged' => true,
        'source' => 'local',
        'reason' => 'inappropriate_language'
      ];
    }
  }

  return [
    'flagged' => false,
    'source' => 'local',
    'reason' => ''
  ];
}

function purgoMalumPublicChatModeration(string $message): array {
  $message = trim($message);

  if ($message === '') {
    return [
      'checked' => false,
      'flagged' => false
    ];
  }

  if (!function_exists('curl_init')) {
    error_log(
      'Public chat PurgoMalum check skipped: PHP cURL is unavailable.'
    );

    return [
      'checked' => false,
      'flagged' => false
    ];
  }

  $query = http_build_query([
    'text' => $message,

    // Extra Filipino terms for the external filter.
    // The local filter above remains the primary Filipino safeguard.
    'add' =>
      'puta,putangina,tangina,gago,bobo,tanga,ulol,punyeta,leche,yawa'
  ]);

  $url =
    'https://www.purgomalum.com/service/containsprofanity?' .
    $query;

  $ch = curl_init($url);

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => [
      'Accept: text/plain'
    ]
  ]);

  $response = curl_exec($ch);
  $curlError = curl_error($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

  curl_close($ch);

  if (
    $response === false ||
    $curlError !== '' ||
    $httpCode < 200 ||
    $httpCode >= 300
  ) {
    error_log(
      'Public chat PurgoMalum check unavailable. HTTP=' .
      $httpCode .
      ($curlError !== '' ? ', cURL=' . $curlError : '')
    );

    return [
      'checked' => false,
      'flagged' => false
    ];
  }

  $result =
    strtolower(
      trim((string)$response)
    );

  if ($result === 'true') {
    return [
      'checked' => true,
      'flagged' => true
    ];
  }

  if ($result === 'false') {
    return [
      'checked' => true,
      'flagged' => false
    ];
  }

  error_log(
    'Public chat PurgoMalum returned an unexpected response.'
  );

  return [
    'checked' => false,
    'flagged' => false
  ];
}

function moderateChatMessage(string $message): array {
  $local =
    localPublicChatModeration($message);

  if (!empty($local['flagged'])) {
    return [
      'flagged' => true,
      'source' => 'local'
    ];
  }

  $purgo =
    purgoMalumPublicChatModeration($message);

  if (!empty($purgo['flagged'])) {
    return [
      'flagged' => true,
      'source' => 'purgomalum'
    ];
  }

  return [
    'flagged' => false,
    'source' =>
      !empty($purgo['checked'])
        ? 'purgomalum'
        : 'local'
  ];
}

$isTenant = ($_SESSION['role'] === 'tenant');
$tenant = null;
$user = null;
$hid = 0;

if ($isTenant) {
  if (empty($_SESSION['tenant_id']) || empty($_SESSION['tenant_homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $tenant_id = (int)$_SESSION['tenant_id'];
  $hid = (int)$_SESSION['tenant_homeowner_id'];

  $stmt = $conn->prepare("
    SELECT id, homeowner_id, first_name, last_name, email, status, phase,
           can_pay_dues, can_rent, can_parking, can_announcements
    FROM tenants
    WHERE id=?
    LIMIT 1
  ");
  $stmt->bind_param("i", $tenant_id);
  $stmt->execute();
  $tenant = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$tenant || $tenant['status'] !== 'active') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }

  $stmt = $conn->prepare("
SELECT
    id,
    status,
    must_change_password,
    first_name,
    last_name,
    phase,
    house_lot_number,
    latitude,
    longitude,
    profile_picture_path
FROM homeowners
    WHERE id=?
    LIMIT 1
  ");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || $user['status'] !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }

  tenant_guard('public_chat', $tenant);
} else {
  if (empty($_SESSION['homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $hid = (int)$_SESSION['homeowner_id'];

  $stmt = $conn->prepare("
SELECT
    id,
    status,
    must_change_password,
    first_name,
    last_name,
    phase,
    house_lot_number,
    latitude,
    longitude,
    profile_picture_path
FROM homeowners
    WHERE id=?
    LIMIT 1
  ");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || $user['status'] !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }
}

$phase = (string)$user['phase'];
$houseLot = (string)($user['house_lot_number'] ?? '');

if ($isTenant) {
  $fullName = trim(($tenant['first_name'] ?? '').' '.($tenant['last_name'] ?? ''));
  $mustChange = false;
  $initials = strtoupper(substr($tenant['first_name'] ?? 'T',0,1).substr($tenant['last_name'] ?? 'N',0,1));
} else {
  $fullName = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));
  $mustChange = ((int)$user['must_change_password'] === 1);
  $initials = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));
}

/*
|--------------------------------------------------------------------------
| Homeowner Profile Picture
|--------------------------------------------------------------------------
*/

$profilePicturePath =
    trim(
        (string)(
            $user['profile_picture_path']
            ?? ''
        )
    );

$profilePictureUrl =
    $profilePicturePath !== ''
        ? '../' . ltrim(
            $profilePicturePath,
            '/'
        )
        : '';
$pageTitle  = "Community Chat • South Meridian Homes Salitran • ".$phase;

$activePage = basename($_SERVER['PHP_SELF'] ?? 'homeowner_public_chat.php');

$parkingPages = [
  'homeowner_parking.php',
  'homeowner_parking_permit.php',
  'homeowner_parking_violations.php'
];

$complaintPages = [
  'homeowner_complaints.php',
  'homeowner_complaint_chat.php'
];

$parkingOpen    = in_array($activePage, $parkingPages, true);
$complaintsOpen = in_array($activePage, $complaintPages, true);

if (empty($_SESSION['csrf_public_chat'])) {
  $_SESSION['csrf_public_chat'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_public_chat'];

$err = "";
if (!$isTenant && $mustChange && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password_submit'])) {
  $postedCsrf = (string)($_POST['csrf'] ?? '');

  if ($postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
    $err = "Your session token is no longer valid. Please refresh the page and try again.";
  } else {
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');

    if (strlen($p1) < 8) {
      $err = "Password must be at least 8 characters.";
    } elseif ($p1 !== $p2) {
      $err = "Passwords do not match.";
    } else {
      $hash = password_hash($p1, PASSWORD_DEFAULT);

      $stmt = $conn->prepare("
        UPDATE homeowners
        SET password=?, must_change_password=0
        WHERE id=?
      ");
      $stmt->bind_param("si", $hash, $hid);
      $stmt->execute();
      $stmt->close();

      header("Location: homeowner_public_chat.php");
      exit;
    }
  }
}

/* =========================
   CURRENT MUTE STATE
   ========================= */
$stmt = $conn->prepare("
  SELECT is_muted, reason
  FROM public_chat_mutes
  WHERE homeowner_id=? AND phase=? AND is_muted=1
  LIMIT 1
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$muteRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$isMuted = !empty($muteRow);
$muteReason = trim((string)($muteRow['reason'] ?? ''));

/* =========================
   LOAD OFFICERS
   ========================= */
$officers = [];

$stmt = $conn->prepare("
  SELECT id, full_name, email, position
  FROM admins
  WHERE role='admin'
    AND phase=?
    AND position IS NOT NULL
  ORDER BY
    FIELD(
      position,
      'President',
      'Vice President',
      'Secretary',
      'Treasurer',
      'Auditor',
      'Board of Director'
    ),
    full_name ASC,
    id ASC
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$resOfficers = $stmt->get_result();

while ($r = $resOfficers->fetch_assoc()) {
  $position =
    trim(
      (string)(
        $r['position']
        ?? 'Officer'
      )
    );

  $name =
    trim(
      (string)(
        $r['full_name']
        ?? ''
      )
    );

  if ($position === '') {
    continue;
  }

  $officers[] = [
    'id' => (int)$r['id'],
    'full_name' =>
      $name !== ''
        ? $name
        : $position,
    'email' =>
      (string)($r['email'] ?? ''),
    'position' => $position,
    'initials' =>
      strtoupper(
        substr(
          $name !== ''
            ? $name
            : $position,
          0,
          1
        )
      )
  ];
}
$stmt->close();

$defaultOfficerId = !empty($officers) ? (int)$officers[0]['id'] : 0;

/* =========================
   LOAD SAME-PHASE HOMEOWNERS
   ========================= */
$phaseHomeowners = [];

if (!$isTenant) {
  $stmt = $conn->prepare("
    SELECT
      id,
      first_name,
      last_name,
      house_lot_number,
      profile_picture_path
    FROM homeowners
    WHERE phase = ?
      AND status = 'approved'
      AND id <> ?
    ORDER BY first_name ASC, last_name ASC, id ASC
  ");
  $stmt->bind_param("si", $phase, $hid);
  $stmt->execute();
  $resHomeowners = $stmt->get_result();

  while ($row = $resHomeowners->fetch_assoc()) {
    $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));

    $phaseHomeowners[] = [
      'id' => (int)$row['id'],
      'name' => $name,
      'lot' => (string)($row['house_lot_number'] ?? ''),
      'profile_picture_url' => !empty($row['profile_picture_path'])
        ? '../' . ltrim((string)$row['profile_picture_path'], '/')
        : ''
    ];
  }

  $stmt->close();
}

$defaultHomeownerId = !empty($phaseHomeowners)
  ? (int)$phaseHomeowners[0]['id']
  : 0;


/* =========================
   HOMEOWNER CALL HELPERS
   ========================= */
function getApprovedHomeownerForCall(mysqli $conn, int $homeownerId, string $phase): ?array {
  if ($homeownerId <= 0) return null;

  $stmt = $conn->prepare("
    SELECT id, first_name, last_name, house_lot_number
    FROM homeowners
    WHERE id = ?
      AND phase = ?
      AND status = 'approved'
    LIMIT 1
  ");
  $stmt->bind_param("is", $homeownerId, $phase);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  return $row ?: null;
}

function getHomeownerCallForParty(mysqli $conn, int $callId, int $homeownerId, string $phase): ?array {
  if ($callId <= 0 || $homeownerId <= 0) return null;

  $stmt = $conn->prepare("
    SELECT *
    FROM homeowner_calls
    WHERE id = ?
      AND phase = ?
      AND (caller_homeowner_id = ? OR receiver_homeowner_id = ?)
    LIMIT 1
  ");
  $stmt->bind_param("isii", $callId, $phase, $homeownerId, $homeownerId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  return $row ?: null;
}


/* =========================
   AJAX ACTIONS
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json; charset=utf-8');

  $postedCsrf = (string)($_POST['csrf'] ?? '');
  if ($postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
    http_response_code(403);
    echo json_encode([
      'success' => false,
      'message' => 'Your session token is no longer valid. Please refresh the page and try again.'
    ]);
    exit;
  }

  if ($mustChange) {
    echo json_encode(['success'=>false,'message'=>'Please change your password first.']);
    exit;
  }

  $action = (string)$_POST['action'];

  /* =========================================================
     HOMEOWNER-TO-HOMEOWNER AUDIO / VIDEO CALLING
     Public chat and Officer chat are intentionally excluded.
     ========================================================= */
  if (strpos($action, 'homeowner_call_') === 0) {
    if ($isTenant) {
      http_response_code(403);
      echo json_encode([
        'success' => false,
        'message' => 'Audio and video calling is available to homeowner accounts only.'
      ]);
      exit;
    }

    // Expire unanswered calls after 45 seconds.
    $stmt = $conn->prepare("
      UPDATE homeowner_calls
      SET status = 'missed', ended_at = NOW()
      WHERE phase = ?
        AND status = 'ringing'
        AND started_at < DATE_SUB(NOW(), INTERVAL 45 SECOND)
    ");
    $stmt->bind_param("s", $phase);
    $stmt->execute();
    $stmt->close();

    if ($action === 'homeowner_call_start') {
      $receiverId = (int)($_POST['homeowner_id'] ?? 0);
      $callType = strtolower(trim((string)($_POST['call_type'] ?? 'audio')));

      if (!in_array($callType, ['audio', 'video'], true)) {
        http_response_code(422);
        echo json_encode(['success'=>false,'message'=>'Invalid call type.']);
        exit;
      }

      if ($receiverId <= 0 || $receiverId === $hid) {
        http_response_code(422);
        echo json_encode(['success'=>false,'message'=>'Please select a valid homeowner.']);
        exit;
      }

      $receiver = getApprovedHomeownerForCall($conn, $receiverId, $phase);
      if (!$receiver) {
        http_response_code(404);
        echo json_encode(['success'=>false,'message'=>'Selected homeowner is not available.']);
        exit;
      }

      // Prevent either participant from entering two active calls at once.
      $stmt = $conn->prepare("
        SELECT id
        FROM homeowner_calls
        WHERE phase = ?
          AND status IN ('ringing','answered')
          AND (
            caller_homeowner_id IN (?,?)
            OR receiver_homeowner_id IN (?,?)
          )
        LIMIT 1
      ");
      $stmt->bind_param("siiii", $phase, $hid, $receiverId, $hid, $receiverId);
      $stmt->execute();
      $busy = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if ($busy) {
        http_response_code(409);
        echo json_encode([
          'success'=>false,
          'message'=>'You or the selected homeowner is already in another call.'
        ]);
        exit;
      }

      $stmt = $conn->prepare("
        INSERT INTO homeowner_calls
          (phase, caller_homeowner_id, receiver_homeowner_id, call_type, status)
        VALUES (?, ?, ?, ?, 'ringing')
      ");
      $stmt->bind_param("siis", $phase, $hid, $receiverId, $callType);
      $stmt->execute();
      $callId = (int)$stmt->insert_id;
      $stmt->close();

      echo json_encode([
        'success'=>true,
        'call'=>[
          'id'=>$callId,
          'call_type'=>$callType,
          'status'=>'ringing',
          'peer'=>[
            'id'=>(int)$receiver['id'],
            'name'=>trim((string)$receiver['first_name'].' '.(string)$receiver['last_name']),
            'lot'=>(string)($receiver['house_lot_number'] ?? '')
          ]
        ]
      ]);
      exit;
    }

    if ($action === 'homeowner_call_incoming') {
      $stmt = $conn->prepare("
        SELECT
          c.id,
          c.call_type,
          c.status,
          c.started_at,
          h.id AS caller_id,
          h.first_name,
          h.last_name,
          h.house_lot_number
        FROM homeowner_calls c
        JOIN homeowners h ON h.id = c.caller_homeowner_id
        WHERE c.phase = ?
          AND c.receiver_homeowner_id = ?
          AND c.status = 'ringing'
        ORDER BY c.id DESC
        LIMIT 1
      ");
      $stmt->bind_param("si", $phase, $hid);
      $stmt->execute();
      $incoming = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (!$incoming) {
        echo json_encode(['success'=>true,'call'=>null]);
        exit;
      }

      echo json_encode([
        'success'=>true,
        'call'=>[
          'id'=>(int)$incoming['id'],
          'call_type'=>(string)$incoming['call_type'],
          'status'=>(string)$incoming['status'],
          'started_at'=>(string)$incoming['started_at'],
          'peer'=>[
            'id'=>(int)$incoming['caller_id'],
            'name'=>trim((string)$incoming['first_name'].' '.(string)$incoming['last_name']),
            'lot'=>(string)($incoming['house_lot_number'] ?? '')
          ]
        ]
      ]);
      exit;
    }
    if ($action === 'homeowner_call_signal') {
      $callId = (int)($_POST['call_id'] ?? 0);
      $signalType = strtolower(trim((string)($_POST['signal_type'] ?? '')));
      $payload = trim((string)($_POST['payload'] ?? ''));

      if (!in_array($signalType, ['offer','answer','ice'], true)) {
        http_response_code(422);
        echo json_encode(['success'=>false,'message'=>'Invalid call signal.']);
        exit;
      }

      if ($payload === '' || strlen($payload) > 100000) {
        http_response_code(422);
        echo json_encode(['success'=>false,'message'=>'Invalid call signal payload.']);
        exit;
      }

      json_decode($payload, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(422);
        echo json_encode(['success'=>false,'message'=>'Malformed call signal payload.']);
        exit;
      }

      $call = getHomeownerCallForParty($conn, $callId, $hid, $phase);
      if (!$call || !in_array((string)$call['status'], ['ringing','answered'], true)) {
        http_response_code(404);
        echo json_encode(['success'=>false,'message'=>'Call is no longer available.']);
        exit;
      }

      $stmt = $conn->prepare("
        INSERT INTO homeowner_call_signals
          (call_id, sender_homeowner_id, signal_type, payload_json)
        VALUES (?, ?, ?, ?)
      ");
      $stmt->bind_param("iiss", $callId, $hid, $signalType, $payload);
      $stmt->execute();
      $signalId = (int)$stmt->insert_id;
      $stmt->close();

      echo json_encode(['success'=>true,'signal_id'=>$signalId]);
      exit;
    }

    if ($action === 'homeowner_call_accept') {
      $callId = (int)($_POST['call_id'] ?? 0);
      $call = getHomeownerCallForParty($conn, $callId, $hid, $phase);

      if (!$call || (int)$call['receiver_homeowner_id'] !== $hid || (string)$call['status'] !== 'ringing') {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'This call is no longer available.']);
        exit;
      }

      $stmt = $conn->prepare("
        UPDATE homeowner_calls
        SET status = 'answered', answered_at = NOW()
        WHERE id = ? AND receiver_homeowner_id = ? AND status = 'ringing'
      ");
      $stmt->bind_param("ii", $callId, $hid);
      $stmt->execute();
      $changed = $stmt->affected_rows === 1;
      $stmt->close();

      if (!$changed) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'This call was already handled.']);
        exit;
      }

      echo json_encode(['success'=>true]);
      exit;
    }

    if ($action === 'homeowner_call_decline') {
      $callId = (int)($_POST['call_id'] ?? 0);
      $call = getHomeownerCallForParty($conn, $callId, $hid, $phase);

      if (!$call || (int)$call['receiver_homeowner_id'] !== $hid || (string)$call['status'] !== 'ringing') {
        echo json_encode(['success'=>true]);
        exit;
      }

      $stmt = $conn->prepare("
        UPDATE homeowner_calls
        SET status = 'declined', ended_at = NOW()
        WHERE id = ? AND receiver_homeowner_id = ? AND status = 'ringing'
      ");
      $stmt->bind_param("ii", $callId, $hid);
      $stmt->execute();
      $stmt->close();

      $stmt = $conn->prepare("DELETE FROM homeowner_call_signals WHERE call_id = ?");
      $stmt->bind_param("i", $callId);
      $stmt->execute();
      $stmt->close();

      echo json_encode(['success'=>true]);
      exit;
    }

    if ($action === 'homeowner_call_end') {
      $callId = (int)($_POST['call_id'] ?? 0);
      $call = getHomeownerCallForParty($conn, $callId, $hid, $phase);

      if ($call && in_array((string)$call['status'], ['ringing','answered'], true)) {
        $stmt = $conn->prepare("
          UPDATE homeowner_calls
          SET status = 'ended', ended_at = NOW()
          WHERE id = ? AND status IN ('ringing','answered')
        ");
        $stmt->bind_param("i", $callId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM homeowner_call_signals WHERE call_id = ?");
        $stmt->bind_param("i", $callId);
        $stmt->execute();
        $stmt->close();
      }

      echo json_encode(['success'=>true]);
      exit;
    }

    if ($action === 'homeowner_call_poll') {
      $callId = (int)($_POST['call_id'] ?? 0);
      $lastSignalId = max(0, (int)($_POST['last_signal_id'] ?? 0));

      $call = getHomeownerCallForParty($conn, $callId, $hid, $phase);
      if (!$call) {
        http_response_code(404);
        echo json_encode(['success'=>false,'message'=>'Call not found.']);
        exit;
      }

      $peerId = ((int)$call['caller_homeowner_id'] === $hid)
        ? (int)$call['receiver_homeowner_id']
        : (int)$call['caller_homeowner_id'];

      $peer = getApprovedHomeownerForCall($conn, $peerId, $phase);

      $stmt = $conn->prepare("
        SELECT id, signal_type, payload_json, created_at
        FROM homeowner_call_signals
        WHERE call_id = ?
          AND id > ?
          AND sender_homeowner_id <> ?
        ORDER BY id ASC
        LIMIT 100
      ");
      $stmt->bind_param("iii", $callId, $lastSignalId, $hid);
      $stmt->execute();
      $res = $stmt->get_result();
      $signals = [];
      $newLastSignalId = $lastSignalId;

      while ($signal = $res->fetch_assoc()) {
        $signalId = (int)$signal['id'];
        $newLastSignalId = max($newLastSignalId, $signalId);
        $signals[] = [
          'id'=>$signalId,
          'type'=>(string)$signal['signal_type'],
          'payload'=>(string)$signal['payload_json']
        ];
      }
      $stmt->close();

      echo json_encode([
        'success'=>true,
        'call'=>[
          'id'=>(int)$call['id'],
          'call_type'=>(string)$call['call_type'],
          'status'=>(string)$call['status'],
          'started_at'=>(string)$call['started_at'],
          'answered_at'=>(string)($call['answered_at'] ?? ''),
          'ended_at'=>(string)($call['ended_at'] ?? ''),
          'peer'=>[
            'id'=>$peerId,
            'name'=>$peer ? trim((string)$peer['first_name'].' '.(string)$peer['last_name']) : 'Homeowner',
            'lot'=>$peer ? (string)($peer['house_lot_number'] ?? '') : ''
          ]
        ],
        'signals'=>$signals,
        'last_signal_id'=>$newLastSignalId
      ]);
      exit;
    }

    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Unknown call action.']);
    exit;
  }

  if ($action === 'send_message') {
    $message = trim((string)($_POST['message'] ?? ''));
    $message = preg_replace('/\s+/', ' ', $message);

    if (mb_strlen($message) > 500) {
      http_response_code(422);

      echo json_encode([
        'success' => false,
        'message' => 'Message must not exceed 500 characters.'
      ]);
      exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Re-check mute status
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
      SELECT is_muted, reason
      FROM public_chat_mutes
      WHERE homeowner_id=? AND phase=? AND is_muted=1
      LIMIT 1
    ");

    $stmt->bind_param("is", $hid, $phase);
    $stmt->execute();

    $muteRowAjax =
      $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if ($muteRowAjax) {
      http_response_code(403);

      echo json_encode([
        'success' => false,
        'message' =>
          'You are muted from public chat.' .
          (
            !empty($muteRowAjax['reason'])
              ? ' Reason: ' . $muteRowAjax['reason']
              : ''
          )
      ]);
      exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Moderate text before saving
    |--------------------------------------------------------------------------
    */

    if ($message !== '') {
      $moderation =
        moderateChatMessage($message);

      if (!empty($moderation['flagged'])) {
        http_response_code(422);

        echo json_encode([
          'success' => false,
          'message' =>
            'Your message was not sent because it contains inappropriate or harmful language.'
        ]);
        exit;
      }
    }

    /*
    |--------------------------------------------------------------------------
    | Upload attachment after text passes moderation
    |--------------------------------------------------------------------------
    */

    $upload =
      uploadChatAttachment(
        $_FILES['attachment']
        ?? []
      );

    if (!$upload['success']) {
      http_response_code(422);

      echo json_encode([
        'success' => false,
        'message' =>
          $upload['message']
      ]);
      exit;
    }

    $hasFile =
      !empty($upload['uploaded']);

    if ($message === '' && !$hasFile) {
      http_response_code(422);

      echo json_encode([
        'success' => false,
        'message' =>
          'Message or attachment is required.'
      ]);
      exit;
    }

    $attachmentName =
      $upload['attachment_name']
      ?? null;

    $attachmentPath =
      $upload['attachment_path']
      ?? null;

    $attachmentType =
      $upload['attachment_type']
      ?? null;

    $stmt = $conn->prepare("
      INSERT INTO public_chat_messages
      (
        phase,
        homeowner_id,
        message,
        attachment_name,
        attachment_path,
        attachment_type
      )
      VALUES (?,?,?,?,?,?)
    ");

    $stmt->bind_param(
      "sissss",
      $phase,
      $hid,
      $message,
      $attachmentName,
      $attachmentPath,
      $attachmentType
    );

    $ok =
      $stmt->execute();

    $stmt->close();

    echo json_encode([
      'success' => $ok,
      'message' =>
        $ok
          ? 'Message sent.'
          : 'Failed to send message.'
    ]);
    exit;
  }

  if ($action === 'fetch_messages') {
    $lastId = (int)($_POST['last_id'] ?? 0);

    if ($lastId > 0) {
      $stmt = $conn->prepare("
        SELECT
          pcm.id,
          pcm.message,
          pcm.attachment_name,
          pcm.attachment_path,
          pcm.attachment_type,
          pcm.created_at,
          pcm.homeowner_id,
h.first_name,
h.last_name,
h.house_lot_number,
h.profile_picture_path
        FROM public_chat_messages pcm
        JOIN homeowners h ON h.id = pcm.homeowner_id
        WHERE pcm.phase = ?
          AND pcm.id > ?
        ORDER BY pcm.id ASC
      ");
      $stmt->bind_param("si", $phase, $lastId);
    } else {
      $stmt = $conn->prepare("
        SELECT * FROM (
          SELECT
            pcm.id,
            pcm.message,
            pcm.attachment_name,
            pcm.attachment_path,
            pcm.attachment_type,
            pcm.created_at,
            pcm.homeowner_id,
h.first_name,
h.last_name,
h.house_lot_number,
h.profile_picture_path
          FROM public_chat_messages pcm
          JOIN homeowners h ON h.id = pcm.homeowner_id
          WHERE pcm.phase = ?
          ORDER BY pcm.id DESC
          LIMIT 60
        ) x
        ORDER BY x.id ASC
      ");
      $stmt->bind_param("s", $phase);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while($r = $res->fetch_assoc()){
      $name = trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''));
      $rows[] = [
        'id' => (int)$r['id'],
        'mine' => ((int)$r['homeowner_id'] === $hid),
        'name' => $name,
        'lot' => (string)($r['house_lot_number'] ?? ''),
        'initials' => strtoupper(substr($r['first_name'] ?? 'H',0,1).substr($r['last_name'] ?? 'O',0,1)),
        'profile_picture_url' =>
    !empty($r['profile_picture_path'])
        ? '../' . ltrim(
            (string)$r['profile_picture_path'],
            '/'
        )
        : '',
        'message' => (string)$r['message'],
        'attachment_name' => (string)($r['attachment_name'] ?? ''),
        'attachment_path' => fixChatAttachmentPath($r['attachment_path'] ?? ''),
        'attachment_type' => (string)($r['attachment_type'] ?? ''),
        'is_image' => isImageMime($r['attachment_type'] ?? ''),
        'created_at' => date('M d, Y h:i A', strtotime($r['created_at'])),
        'can_manage' => !$isTenant && ((int)$r['homeowner_id'] === $hid) && time() < (strtotime((string)$r['created_at']) + (15 * 60)),
        'editable_until' => strtotime((string)$r['created_at']) + (15 * 60)
      ];
    }
    $stmt->close();

    echo json_encode([
      'success' => true,
      'messages' => $rows
    ]);
    exit;
  }


  if ($action === 'fetch_homeowners') {
    echo json_encode([
      'success' => true,
      'homeowners' => $phaseHomeowners
    ]);
    exit;
  }

  if ($action === 'send_homeowner_message') {
    if ($isTenant) {
      http_response_code(403);
      echo json_encode([
        'success' => false,
        'message' => 'Private homeowner chat is available to homeowner accounts only.'
      ]);
      exit;
    }

    $receiverId = (int)($_POST['homeowner_id'] ?? 0);
    $message = preg_replace('/\\s+/', ' ', trim((string)($_POST['message'] ?? '')));

    if ($receiverId <= 0 || $receiverId === $hid) {
      http_response_code(422);
      echo json_encode(['success'=>false,'message'=>'Please select a valid homeowner.']);
      exit;
    }

    if (mb_strlen($message) > 1000) {
      http_response_code(422);
      echo json_encode(['success'=>false,'message'=>'Message must not exceed 1000 characters.']);
      exit;
    }

    $stmt = $conn->prepare("
      SELECT id
      FROM homeowners
      WHERE id = ? AND phase = ? AND status = 'approved'
      LIMIT 1
    ");
    $stmt->bind_param("is", $receiverId, $phase);
    $stmt->execute();
    $receiver = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$receiver) {
      http_response_code(404);
      echo json_encode(['success'=>false,'message'=>'Selected homeowner is not available.']);
      exit;
    }

    if ($message !== '') {
      $moderation = moderateChatMessage($message);
      if (!empty($moderation['flagged'])) {
        http_response_code(422);
        echo json_encode([
          'success'=>false,
          'message'=>'Your message was not sent because it contains inappropriate or harmful language.'
        ]);
        exit;
      }
    }

    $upload = uploadChatAttachment($_FILES['attachment'] ?? []);
    if (!$upload['success']) {
      http_response_code(422);
      echo json_encode(['success'=>false,'message'=>$upload['message']]);
      exit;
    }

    $hasFile = !empty($upload['uploaded']);
    if ($message === '' && !$hasFile) {
      http_response_code(422);
      echo json_encode(['success'=>false,'message'=>'Message or attachment is required.']);
      exit;
    }

    $attachmentName = $upload['attachment_name'] ?? null;
    $attachmentPath = $upload['attachment_path'] ?? null;
    $attachmentType = $upload['attachment_type'] ?? null;

    $stmt = $conn->prepare("
      INSERT INTO homeowner_private_messages
      (phase, sender_homeowner_id, receiver_homeowner_id, message, attachment_name, attachment_path, attachment_type)
      VALUES (?,?,?,?,?,?,?)
    ");
    $stmt->bind_param("siissss", $phase, $hid, $receiverId, $message, $attachmentName, $attachmentPath, $attachmentType);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode([
      'success'=>$ok,
      'message'=>$ok ? 'Message sent.' : 'Failed to send message.'
    ]);
    exit;
  }

  if ($action === 'fetch_homeowner_messages') {
    if ($isTenant) {
      http_response_code(403);
      echo json_encode(['success'=>false,'message'=>'Private homeowner chat is available to homeowner accounts only.']);
      exit;
    }

    $otherHomeownerId = (int)($_POST['homeowner_id'] ?? 0);
    $lastId = (int)($_POST['last_id'] ?? 0);

    if ($otherHomeownerId <= 0 || $otherHomeownerId === $hid) {
      http_response_code(422);
      echo json_encode(['success'=>false,'message'=>'Invalid homeowner selected.']);
      exit;
    }

    $stmt = $conn->prepare("
      SELECT id, first_name, last_name, house_lot_number, profile_picture_path
      FROM homeowners
      WHERE id = ? AND phase = ? AND status = 'approved'
      LIMIT 1
    ");
    $stmt->bind_param("is", $otherHomeownerId, $phase);
    $stmt->execute();
    $otherHomeowner = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$otherHomeowner) {
      http_response_code(404);
      echo json_encode(['success'=>false,'message'=>'Homeowner not found.']);
      exit;
    }

    if ($lastId > 0) {
      $stmt = $conn->prepare("
        SELECT hpm.*, h.first_name, h.last_name, h.house_lot_number, h.profile_picture_path
        FROM homeowner_private_messages hpm
        JOIN homeowners h ON h.id = hpm.sender_homeowner_id
        WHERE hpm.phase = ? AND hpm.id > ?
          AND ((hpm.sender_homeowner_id = ? AND hpm.receiver_homeowner_id = ?)
            OR (hpm.sender_homeowner_id = ? AND hpm.receiver_homeowner_id = ?))
        ORDER BY hpm.id ASC
      ");
      $stmt->bind_param("siiiii", $phase, $lastId, $hid, $otherHomeownerId, $otherHomeownerId, $hid);
    } else {
      $stmt = $conn->prepare("
        SELECT * FROM (
          SELECT hpm.*, h.first_name, h.last_name, h.house_lot_number, h.profile_picture_path
          FROM homeowner_private_messages hpm
          JOIN homeowners h ON h.id = hpm.sender_homeowner_id
          WHERE hpm.phase = ?
            AND ((hpm.sender_homeowner_id = ? AND hpm.receiver_homeowner_id = ?)
              OR (hpm.sender_homeowner_id = ? AND hpm.receiver_homeowner_id = ?))
          ORDER BY hpm.id DESC
          LIMIT 60
        ) x
        ORDER BY x.id ASC
      ");
      $stmt->bind_param("siiii", $phase, $hid, $otherHomeownerId, $otherHomeownerId, $hid);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];

    while ($r = $res->fetch_assoc()) {
      $mine = ((int)$r['sender_homeowner_id'] === $hid);
      $createdTs = strtotime((string)$r['created_at']);
      $editableUntil = $createdTs + (15 * 60);

      $rows[] = [
        'id'=>(int)$r['id'],
        'mine'=>$mine,
        'name'=>$mine ? 'You' : trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')),
        'lot'=>(string)($r['house_lot_number'] ?? ''),
        'initials'=>strtoupper(substr($r['first_name'] ?? 'H',0,1).substr($r['last_name'] ?? 'O',0,1)),
        'profile_picture_url'=>!empty($r['profile_picture_path']) ? '../'.ltrim((string)$r['profile_picture_path'],'/') : '',
        'message'=>(string)$r['message'],
        'attachment_name'=>(string)($r['attachment_name'] ?? ''),
        'attachment_path'=>fixChatAttachmentPath($r['attachment_path'] ?? ''),
        'attachment_type'=>(string)($r['attachment_type'] ?? ''),
        'is_image'=>isImageMime($r['attachment_type'] ?? ''),
        'created_at'=>date('M d, Y h:i A', $createdTs),
        'timestamp'=>$createdTs,
        'can_manage'=>$mine && time() < $editableUntil,
        'editable_until'=>$editableUntil
      ];
    }
    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Messenger-style call events for this private homeowner conversation
    |--------------------------------------------------------------------------
    | Calls are returned with the private messages so the browser can render
    | them inside the chat timeline instead of in a separate call-log panel.
    */
    $stmt = $conn->prepare("
      SELECT
        id,
        caller_homeowner_id,
        receiver_homeowner_id,
        call_type,
        status,
        started_at,
        answered_at,
        ended_at
      FROM homeowner_calls
      WHERE phase = ?
        AND (
          (
            caller_homeowner_id = ?
            AND receiver_homeowner_id = ?
          )
          OR
          (
            caller_homeowner_id = ?
            AND receiver_homeowner_id = ?
          )
        )
      ORDER BY id DESC
      LIMIT 40
    ");

    $stmt->bind_param(
      "siiii",
      $phase,
      $hid,
      $otherHomeownerId,
      $otherHomeownerId,
      $hid
    );

    $stmt->execute();
    $callRes = $stmt->get_result();
    $callRows = [];

    while ($call = $callRes->fetch_assoc()) {
      $isOutgoing =
        ((int)$call['caller_homeowner_id'] === $hid);

      $status =
        (string)(
          $call['status']
          ?? ''
        );

      $startedTs =
        strtotime(
          (string)$call['started_at']
        );

      $durationSeconds = 0;

      if (
        !empty($call['answered_at']) &&
        !empty($call['ended_at'])
      ) {
        $answeredTs =
          strtotime(
            (string)$call['answered_at']
          );

        $endedTs =
          strtotime(
            (string)$call['ended_at']
          );

        if (
          $answeredTs !== false &&
          $endedTs !== false
        ) {
          $durationSeconds =
            max(
              0,
              $endedTs - $answeredTs
            );
        }
      }

      $callRows[] = [
        'id' =>
          (int)$call['id'],

        'direction' =>
          $isOutgoing
            ? 'outgoing'
            : 'incoming',

        'call_type' =>
          (string)$call['call_type'],

        'status' =>
          $status,

        'is_missed' =>
          !$isOutgoing &&
          $status === 'missed',

        'duration_seconds' =>
          $durationSeconds,

        'timestamp' =>
          $startedTs !== false
            ? $startedTs
            : 0,

        'started_at' =>
          (string)$call['started_at'],

        'started_at_label' =>
          $startedTs !== false
            ? date(
                'M d, Y h:i A',
                $startedTs
              )
            : (string)$call['started_at']
      ];
    }

    $stmt->close();

    echo json_encode([
      'success'=>true,
      'homeowner'=>[
        'id'=>(int)$otherHomeowner['id'],
        'name'=>trim(($otherHomeowner['first_name'] ?? '').' '.($otherHomeowner['last_name'] ?? '')),
        'lot'=>(string)($otherHomeowner['house_lot_number'] ?? '')
      ],
      'messages'=>$rows,
      'calls'=>$callRows
    ]);
    exit;
  }

  if ($action === 'fetch_officers') {
    echo json_encode([
      'success' => true,
      'officers' => $officers
    ]);
    exit;
  }

  if ($action === 'send_officer_message') {
    $adminId  = (int)($_POST['admin_id'] ?? 0);
    $message  = trim((string)($_POST['message'] ?? ''));
    $message  = preg_replace('/\s+/', ' ', $message);

    if ($adminId <= 0) {
      http_response_code(422);

      echo json_encode([
        'success' => false,
        'message' => 'Please select an officer.'
      ]);
      exit;
    }

    if (mb_strlen($message) > 1000) {
      http_response_code(422);

      echo json_encode([
        'success' => false,
        'message' => 'Message must not exceed 1000 characters.'
      ]);
      exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Moderate private officer-chat text before saving
    |--------------------------------------------------------------------------
    */

    if ($message !== '') {
      $moderation =
        moderateChatMessage($message);

      if (!empty($moderation['flagged'])) {
        http_response_code(422);

        echo json_encode([
          'success' => false,
          'message' =>
            'Your message was not sent because it contains inappropriate or harmful language.'
        ]);
        exit;
      }
    }

    /*
    |--------------------------------------------------------------------------
    | Upload attachment after text passes moderation
    |--------------------------------------------------------------------------
    */

    $upload =
      uploadChatAttachment(
        $_FILES['attachment']
        ?? []
      );

    if (!$upload['success']) {
      http_response_code(422);

      echo json_encode([
        'success' => false,
        'message' => $upload['message']
      ]);
      exit;
    }

    $hasFile =
      !empty($upload['uploaded']);

    if ($message === '' && !$hasFile) {
      http_response_code(422);

      echo json_encode([
        'success' => false,
        'message' => 'Message or attachment is required.'
      ]);
      exit;
    }

    $stmt = $conn->prepare("
      SELECT id, full_name, position
      FROM admins
      WHERE id=? AND role='admin' AND phase=?
      LIMIT 1
    ");
    $stmt->bind_param("is", $adminId, $phase);
    $stmt->execute();
    $selectedOfficer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$selectedOfficer) {
      echo json_encode(['success'=>false,'message'=>'Selected officer is not available.']);
      exit;
    }

    $attachmentName = $upload['attachment_name'] ?? null;
    $attachmentPath = $upload['attachment_path'] ?? null;
    $attachmentType = $upload['attachment_type'] ?? null;

    $selectedPosition = trim((string)$selectedOfficer['position']);

    $stmt = $conn->prepare("
      INSERT INTO homeowner_officer_messages
      (phase, homeowner_id, admin_id, sender_type, message, attachment_name, attachment_path, attachment_type, is_read_by_homeowner, is_read_by_admin)
      VALUES (?, ?, ?, 'homeowner', ?, ?, ?, ?, 1, 0)
    ");
    $stmt->bind_param("siissss", $phase, $hid, $adminId, $message, $attachmentName, $attachmentPath, $attachmentType);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode([
      'success' => $ok,
      'message' =>
        $ok
          ? 'Message sent to ' .
            (
              trim((string)($selectedOfficer['full_name'] ?? '')) !== ''
                ? trim((string)$selectedOfficer['full_name'])
                : $selectedPosition
            ) .
            '.'
          : 'Failed to send message.'
    ]);
    exit;
  }

  if ($action === 'fetch_officer_messages') {
    $adminId = (int)($_POST['admin_id'] ?? 0);
    $lastId  = (int)($_POST['last_id'] ?? 0);

    if ($adminId <= 0) {
      echo json_encode(['success'=>false,'message'=>'Invalid officer selected.']);
      exit;
    }

    $stmt = $conn->prepare("
      SELECT id, full_name, position
      FROM admins
      WHERE id=? AND role='admin' AND phase=?
      LIMIT 1
    ");
    $stmt->bind_param("is", $adminId, $phase);
    $stmt->execute();
    $selectedOfficer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$selectedOfficer) {
      echo json_encode(['success'=>false,'message'=>'Officer not found.']);
      exit;
    }

    $selectedPosition = trim((string)$selectedOfficer['position']);
    $rows = [];

    if ($lastId > 0) {
      $stmt = $conn->prepare("
        SELECT hom.id, hom.message, hom.attachment_name, hom.attachment_path, hom.attachment_type, hom.created_at, hom.sender_type,
               a.full_name AS admin_name, a.position AS admin_position
        FROM homeowner_officer_messages hom
        LEFT JOIN admins a ON a.id = hom.admin_id
        WHERE hom.phase = ?
          AND hom.homeowner_id = ?
          AND hom.admin_id = ?
          AND hom.id > ?
        ORDER BY hom.id ASC
      ");
      $stmt->bind_param("siii", $phase, $hid, $adminId, $lastId);
    } else {
      $stmt = $conn->prepare("
        SELECT * FROM (
          SELECT hom.id, hom.message, hom.attachment_name, hom.attachment_path, hom.attachment_type, hom.created_at, hom.sender_type,
                 a.full_name AS admin_name, a.position AS admin_position
          FROM homeowner_officer_messages hom
          LEFT JOIN admins a ON a.id = hom.admin_id
          WHERE hom.phase = ?
            AND hom.homeowner_id = ?
            AND hom.admin_id = ?
          ORDER BY hom.id DESC
          LIMIT 60
        ) x
        ORDER BY x.id ASC
      ");
      $stmt->bind_param("sii", $phase, $hid, $adminId);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
      $mine = ((string)$r['sender_type'] === 'homeowner');
      $adminName = trim((string)($r['admin_name'] ?? 'Officer'));
      $rows[] = [
        'id' => (int)$r['id'],
        'mine' => $mine,
        'name' => $mine ? 'You' : $adminName,
        'role' => $mine ? ($isTenant ? 'Tenant' : 'Homeowner') : (string)($r['admin_position'] ?? 'Officer'),
        'initials' => $mine
          ? $initials
          : strtoupper(substr($adminName ?: 'O',0,1).substr(strrchr(' '.$adminName,' ') ?: 'F',1,1)),
        'profile_picture_url' =>
    $mine && $profilePictureUrl !== ''
        ? $profilePictureUrl
        : '',
        'message' => (string)$r['message'],
        'attachment_name' => (string)($r['attachment_name'] ?? ''),
        'attachment_path' => fixChatAttachmentPath($r['attachment_path'] ?? ''),
        'attachment_type' => (string)($r['attachment_type'] ?? ''),
        'is_image' => isImageMime($r['attachment_type'] ?? ''),
        'created_at' => date('M d, Y h:i A', strtotime($r['created_at'])),
        'can_manage' => !$isTenant && $mine && time() < (strtotime((string)$r['created_at']) + (15 * 60)),
        'editable_until' => strtotime((string)$r['created_at']) + (15 * 60)
      ];
    }
    $stmt->close();

    $stmt = $conn->prepare("
      UPDATE homeowner_officer_messages
      SET is_read_by_homeowner = 1
      WHERE phase = ?
        AND homeowner_id = ?
        AND admin_id = ?
        AND sender_type = 'admin'
        AND is_read_by_homeowner = 0
    ");
    $stmt->bind_param("sii", $phase, $hid, $adminId);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
      'success' => true,
      'officer' => [
        'id' => (int)$selectedOfficer['id'],
        'name' => (string)$selectedOfficer['full_name'],
        'position' => (string)$selectedOfficer['position']
      ],
      'messages' => $rows
    ]);
    exit;
  }


  if ($action === 'edit_chat_message') {
    if ($isTenant) {
      http_response_code(403);
      echo json_encode(['success'=>false,'message'=>'Message editing is available to homeowner accounts only.']);
      exit;
    }

    $scope = (string)($_POST['scope'] ?? '');
    $messageId = (int)($_POST['message_id'] ?? 0);
    $newMessage = preg_replace('/\\s+/', ' ', trim((string)($_POST['message'] ?? '')));
    $allowedScopes = ['public','homeowner','officer'];

    if (!in_array($scope, $allowedScopes, true) || $messageId <= 0) {
      http_response_code(422);
      echo json_encode(['success'=>false,'message'=>'Invalid message.']);
      exit;
    }

    $maxLength = $scope === 'public' ? 500 : 1000;
    if (mb_strlen($newMessage) > $maxLength) {
      http_response_code(422);
      echo json_encode(['success'=>false,'message'=>'Message must not exceed '.$maxLength.' characters.']);
      exit;
    }

    if ($newMessage !== '') {
      $moderation = moderateChatMessage($newMessage);
      if (!empty($moderation['flagged'])) {
        http_response_code(422);
        echo json_encode(['success'=>false,'message'=>'Your message was not updated because it contains inappropriate or harmful language.']);
        exit;
      }
    }

    if ($scope === 'public') {
      $stmt = $conn->prepare("SELECT id, attachment_path FROM public_chat_messages WHERE id=? AND homeowner_id=? AND phase=? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
      $stmt->bind_param("iis", $messageId, $hid, $phase);
    } elseif ($scope === 'homeowner') {
      $stmt = $conn->prepare("SELECT id, attachment_path FROM homeowner_private_messages WHERE id=? AND sender_homeowner_id=? AND phase=? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
      $stmt->bind_param("iis", $messageId, $hid, $phase);
    } else {
      $stmt = $conn->prepare("SELECT id, attachment_path FROM homeowner_officer_messages WHERE id=? AND homeowner_id=? AND phase=? AND sender_type='homeowner' AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
      $stmt->bind_param("iis", $messageId, $hid, $phase);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      http_response_code(403);
      echo json_encode(['success'=>false,'message'=>'This message can no longer be edited. Editing is available for 15 minutes after sending.']);
      exit;
    }

    if ($newMessage === '' && empty($row['attachment_path'])) {
      http_response_code(422);
      echo json_encode(['success'=>false,'message'=>'Message cannot be empty.']);
      exit;
    }

    if ($scope === 'public') {
      $stmt = $conn->prepare("UPDATE public_chat_messages SET message=? WHERE id=? AND homeowner_id=? AND phase=? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
      $stmt->bind_param("siis", $newMessage, $messageId, $hid, $phase);
    } elseif ($scope === 'homeowner') {
      $stmt = $conn->prepare("UPDATE homeowner_private_messages SET message=? WHERE id=? AND sender_homeowner_id=? AND phase=? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
      $stmt->bind_param("siis", $newMessage, $messageId, $hid, $phase);
    } else {
      $stmt = $conn->prepare("UPDATE homeowner_officer_messages SET message=? WHERE id=? AND homeowner_id=? AND phase=? AND sender_type='homeowner' AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
      $stmt->bind_param("siis", $newMessage, $messageId, $hid, $phase);
    }

    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>$ok,'message'=>$ok ? 'Message updated.' : 'Unable to update message.']);
    exit;
  }

  if ($action === 'delete_chat_message') {
    if ($isTenant) {
      http_response_code(403);
      echo json_encode(['success'=>false,'message'=>'Message deletion is available to homeowner accounts only.']);
      exit;
    }

    $scope = (string)($_POST['scope'] ?? '');
    $messageId = (int)($_POST['message_id'] ?? 0);
    $allowedScopes = ['public','homeowner','officer'];

    if (!in_array($scope, $allowedScopes, true) || $messageId <= 0) {
      http_response_code(422);
      echo json_encode(['success'=>false,'message'=>'Invalid message.']);
      exit;
    }

    if ($scope === 'public') {
      $stmt = $conn->prepare("SELECT attachment_path FROM public_chat_messages WHERE id=? AND homeowner_id=? AND phase=? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
      $stmt->bind_param("iis", $messageId, $hid, $phase);
    } elseif ($scope === 'homeowner') {
      $stmt = $conn->prepare("SELECT attachment_path FROM homeowner_private_messages WHERE id=? AND sender_homeowner_id=? AND phase=? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
      $stmt->bind_param("iis", $messageId, $hid, $phase);
    } else {
      $stmt = $conn->prepare("SELECT attachment_path FROM homeowner_officer_messages WHERE id=? AND homeowner_id=? AND phase=? AND sender_type='homeowner' AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1");
      $stmt->bind_param("iis", $messageId, $hid, $phase);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      http_response_code(403);
      echo json_encode(['success'=>false,'message'=>'This message can no longer be deleted. Deleting is available for 15 minutes after sending.']);
      exit;
    }

    if ($scope === 'public') {
      $stmt = $conn->prepare("DELETE FROM public_chat_messages WHERE id=? AND homeowner_id=? AND phase=? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
      $stmt->bind_param("iis", $messageId, $hid, $phase);
    } elseif ($scope === 'homeowner') {
      $stmt = $conn->prepare("DELETE FROM homeowner_private_messages WHERE id=? AND sender_homeowner_id=? AND phase=? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
      $stmt->bind_param("iis", $messageId, $hid, $phase);
    } else {
      $stmt = $conn->prepare("DELETE FROM homeowner_officer_messages WHERE id=? AND homeowner_id=? AND phase=? AND sender_type='homeowner' AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
      $stmt->bind_param("iis", $messageId, $hid, $phase);
    }

    $ok = $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    if ($ok && $deleted) deleteChatAttachmentFile($row['attachment_path'] ?? '');

    echo json_encode(['success'=>$ok && $deleted,'message'=>$ok && $deleted ? 'Message deleted.' : 'Unable to delete message.']);
    exit;
  }

  echo json_encode(['success'=>false,'message'=>'Unknown action.']);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= esc($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<script>
(function () {
  const savedTheme = localStorage.getItem('hoa-theme');
  const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const useDark = savedTheme === 'dark' || (!savedTheme && systemDark);
  document.documentElement.classList.toggle('dark', useDark);
})();
</script>

<style type="text/tailwindcss">
  @custom-variant dark (&:where(.dark, .dark *));
</style>

<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">
</head>

<body class="bg-slate-50 text-slate-900 antialiased transition-colors duration-200 dark:bg-slate-950 dark:text-slate-100">

<div id="sidebarOverlay" class="fixed inset-0 z-50 hidden bg-slate-950/50 backdrop-blur-[1px] lg:hidden"></div>

<?php include 'homeowner_sidebar.php'; ?>

<div class="min-h-screen lg:ml-[280px] <?= $mustChange ? 'pointer-events-none select-none blur-sm' : '' ?>">

  <!-- Topbar -->
  <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
    <div class="mx-auto flex min-h-[72px] max-w-7xl items-center gap-3 px-4 sm:px-6">
      <button
        type="button"
        id="sidebarToggle"
        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-2xl text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 dark:focus:ring-emerald-950 lg:hidden"
        aria-label="Open menu"
      >
        <i class="bi bi-list"></i>
      </button>

      <a href="homeowner_dashboard.php" class="min-w-0">
        <div class="truncate text-base font-bold text-emerald-800 sm:text-lg dark:text-emerald-300">HOA Community</div>
        <div class="hidden text-xs font-medium text-slate-500 sm:block dark:text-slate-400">South Meridian Homes Salitran</div>
      </a>

      <div class="ml-auto flex items-center gap-2 sm:gap-3">
        <button
          type="button"
          id="themeToggle"
          class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-xl text-slate-700 shadow-sm transition hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-slate-600 dark:hover:bg-slate-800 dark:hover:text-emerald-300 dark:focus:ring-emerald-950"
          aria-label="Switch to dark mode"
          title="Switch to dark mode"
        >
          <i id="themeIcon" class="bi bi-moon-stars-fill"></i>
        </button>

        <div class="hidden text-right md:block">
          <div class="max-w-[220px] truncate text-sm font-bold text-slate-800 dark:text-slate-200">
            <?= esc($fullName) ?>
          </div>
          <div class="text-xs font-medium text-slate-500 dark:text-slate-400">
            <?= esc($phase) ?><?= $isTenant ? ' • Tenant' : '' ?>
          </div>
        </div>

        <a
          href="logout.php"
          class="flex min-h-12 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700 sm:px-4 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-red-900 dark:hover:bg-red-950/40 dark:hover:text-red-300"
        >
          <i class="bi bi-box-arrow-right text-lg"></i>
          <span class="hidden sm:inline">Logout</span>
        </a>
      </div>
    </div>
  </header>

  <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8">

    <!-- Header -->
    <section class="mb-6">
      <a href="homeowner_dashboard.php" class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-700 hover:text-emerald-800 dark:text-emerald-400 dark:hover:text-emerald-300">
        <i class="bi bi-arrow-left"></i>
        Dashboard
      </a>

      <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">Community Communication</p>
          <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-slate-100">Community Chat</h1>
          <p class="mt-2 max-w-2xl text-[15px] leading-6 text-slate-600 dark:text-slate-400">
            Talk with homeowners in your phase or privately message a selected HOA officer.
          </p>
        </div>

        <div class="inline-flex w-fit items-center gap-2 rounded-xl bg-white px-3 py-2 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">
          <i class="bi bi-geo-alt-fill text-emerald-700 dark:text-emerald-400"></i>
          <?= esc($phase) ?> • <?= esc($houseLot) ?>
        </div>
      </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-[330px_minmax(0,1fr)]">

      <!-- Left column -->
      <aside class="space-y-4">

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <div class="flex items-center gap-3">
<div
    class="
        flex h-12 w-12
        shrink-0
        items-center
        justify-center
        overflow-hidden
        rounded-xl
        bg-emerald-700
        text-sm
        font-bold
        text-white
        dark:bg-emerald-600
    "
>

    <?php if ($profilePictureUrl !== ''): ?>

        <img
            src="<?= esc($profilePictureUrl) ?>"
            alt="<?= esc($fullName) ?>"
            class="h-full w-full object-cover"
        >

    <?php else: ?>

        <?= esc($initials) ?>

    <?php endif; ?>

</div>
            <div class="min-w-0">
              <div class="truncate font-bold text-slate-900 dark:text-slate-100"><?= esc($fullName) ?></div>
              <div class="mt-0.5 truncate text-xs font-medium text-slate-500 dark:text-slate-400">
                <?= esc($phase) ?> • <?= esc($houseLot) ?><?= $isTenant ? ' • Tenant' : '' ?>
              </div>
            </div>
          </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <div class="flex items-center gap-2 text-sm font-bold text-slate-900 dark:text-slate-100">
            <i class="bi bi-shield-check text-emerald-700 dark:text-emerald-400"></i>
            Chat Rules
          </div>
          <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">
            Be respectful, avoid spam, and keep conversations related to your community. Messages are automatically screened for inappropriate or harmful language.
          </p>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">Who can see messages?</h2>

          <div class="mt-3 space-y-3">
            <div class="flex items-start gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                <i class="bi bi-people-fill"></i>
              </div>
              <div>
                <div class="text-sm font-bold text-slate-800 dark:text-slate-200"><?= esc($phase) ?> Homeowners</div>
                <div class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">Public chat stays inside your phase.</div>
              </div>
            </div>

            <div class="flex items-start gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">
                <i class="bi bi-person-badge-fill"></i>
              </div>
              <div>
                <div class="text-sm font-bold text-slate-800 dark:text-slate-200">Officer Private Chat</div>
                <div class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">Only you and the selected officer can see that conversation.</div>
              </div>
            </div>
          </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
          <label for="officerSelect" class="block text-sm font-bold text-slate-800 dark:text-slate-200">Select Officer</label>

          <?php if (!empty($officers)): ?>
            <select
              id="officerSelect"
              class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 outline-none transition focus:border-blue-500 focus:ring-4 focus:ring-blue-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-blue-950"
            >
              <?php foreach ($officers as $i => $of): ?>
                <?php
                $officerLabel =
                  trim(
                    (string)(
                      $of['full_name']
                      ?? ''
                    )
                  );

                if ($officerLabel === '') {
                  $officerLabel =
                    (string)$of['position'];
                }
                ?>
                <option
                  value="<?= (int)$of['id'] ?>"
                  data-position="<?= esc($of['position']) ?>"
                  data-name="<?= esc($officerLabel) ?>"
                  <?= $i === 0 ? 'selected' : '' ?>
                >
                  <?= esc($of['position']) ?>
                  —
                  <?= esc($officerLabel) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">
              Choose the specific HOA officer you want to message privately. Board of Director members are listed individually.
            </p>
          <?php else: ?>
            <div class="mt-3 rounded-xl bg-slate-50 p-3 text-sm font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
              No officers are available in your phase yet.
            </div>
          <?php endif; ?>
        </section>

        <?php if (!$isTenant): ?>
          <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <label for="homeownerSelect" class="block text-sm font-bold text-slate-800 dark:text-slate-200">Chat with Homeowner</label>

            <?php if (!empty($phaseHomeowners)): ?>
              <select id="homeownerSelect" class="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-800 outline-none transition focus:border-violet-500 focus:ring-4 focus:ring-violet-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-violet-950">
                <?php foreach ($phaseHomeowners as $i => $ph): ?>
                  <option value="<?= (int)$ph['id'] ?>" data-name="<?= esc($ph['name']) ?>" data-lot="<?= esc($ph['lot']) ?>" <?= $i === 0 ? 'selected' : '' ?>>
                    <?= esc($ph['name']) ?><?= $ph['lot'] !== '' ? ' — ' . esc($ph['lot']) : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">Only approved homeowners in <?= esc($phase) ?> are shown.</p>
            <?php else: ?>
              <div class="mt-3 rounded-xl bg-slate-50 p-3 text-sm font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">No other approved homeowners are available in your phase.</div>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <?php if ($isMuted): ?>
          <section class="rounded-2xl border border-red-200 bg-red-50 p-4 shadow-sm dark:border-red-900 dark:bg-red-950/40">
            <div class="flex items-start gap-3">
              <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300">
                <i class="bi bi-slash-circle-fill"></i>
              </div>
              <div class="min-w-0">
                <h2 class="text-sm font-bold text-red-900 dark:text-red-200">Public Chat Restriction</h2>
                <p class="mt-1 text-xs leading-5 text-red-800 dark:text-red-300">
                  You can read public messages, but you cannot send new ones right now.
                </p>
                <?php if ($muteReason !== ''): ?>
                  <p class="mt-2 break-words text-xs text-red-700 dark:text-red-300">
                    <strong>Reason:</strong> <?= esc($muteReason) ?>
                  </p>
                <?php endif; ?>
              </div>
            </div>

            <button
              type="button"
              id="btnOpenMutedInfo"
              class="mt-3 inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-red-200 bg-white px-3 text-xs font-bold text-red-700 transition hover:bg-red-100 dark:border-red-900 dark:bg-slate-900 dark:text-red-300 dark:hover:bg-red-950/60"
            >
              <i class="bi bi-info-circle-fill"></i>
              View Details
            </button>
          </section>
        <?php endif; ?>

      </aside>

      <!-- Chat card -->
      <section class="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">

        <div class="border-b border-slate-200 px-4 py-4 sm:px-5 dark:border-slate-800">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
              <h2 id="chatRoomTitle" class="text-lg font-bold text-slate-900 dark:text-slate-100">Public Community Chat</h2>
              <p id="chatRoomSub" class="mt-1 text-sm text-slate-500 dark:text-slate-400">Talk with other homeowners in <?= esc($phase) ?></p>

              <div class="mt-4 inline-flex rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
                <button
                  type="button"
                  id="modePublicBtn"
                  class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-emerald-700 px-3 text-sm font-semibold text-white shadow-sm transition"
                >
                  <i class="bi bi-people-fill"></i>
                  Public Chat
                </button>

                <?php if (!$isTenant): ?>
                  <button
                    type="button"
                    id="modeHomeownerBtn"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg px-3 text-sm font-semibold text-slate-600 transition hover:bg-white hover:text-violet-700 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-violet-300"
                  >
                    <i class="bi bi-person-lines-fill"></i>
                    Homeowner Chat
                  </button>
                <?php endif; ?>

                <button
                  type="button"
                  id="modeOfficerBtn"
                  class="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg px-3 text-sm font-semibold text-slate-600 transition hover:bg-white hover:text-blue-700 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-blue-300"
                >
                  <i class="bi bi-person-badge-fill"></i>
                  Officer Chat
                </button>
              </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
              <?php if (!$isTenant): ?>
                <div id="homeownerCallActions" class="hidden items-center gap-2">
                  <button
                    type="button"
                    id="audioCallBtn"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-3 text-sm font-bold text-violet-700 transition hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-violet-900 dark:bg-violet-950/40 dark:text-violet-300 dark:hover:bg-violet-950/70"
                    title="Start audio call"
                  >
                    <i class="bi bi-telephone-fill"></i>
                    <span class="hidden xl:inline">Audio</span>
                  </button>

                  <button
                    type="button"
                    id="videoCallBtn"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-violet-200 bg-violet-700 px-3 text-sm font-bold text-white transition hover:bg-violet-800 disabled:cursor-not-allowed disabled:opacity-50 dark:border-violet-700 dark:bg-violet-600 dark:hover:bg-violet-500"
                    title="Start video call"
                  >
                    <i class="bi bi-camera-video-fill"></i>
                    <span class="hidden xl:inline">Video</span>
                  </button>
                </div>
              <?php endif; ?>

              <span id="phaseBadge" class="inline-flex w-fit items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900">
                <i class="bi bi-chat-dots-fill"></i>
                <?= esc($phase) ?>
              </span>
            </div>
          </div>
        </div>
        <div
          id="chatBody"
          class="h-[56vh] min-h-[420px] max-h-[720px] space-y-3 overflow-y-auto bg-slate-50 px-3 py-4 scroll-smooth sm:px-5 dark:bg-slate-950/50"
        >
          <div id="chatEmpty" class="flex h-full flex-col items-center justify-center px-4 text-center">
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-2xl text-slate-400 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-500 dark:ring-slate-800">
              <i class="bi bi-chat-square-dots"></i>
            </div>
            <p class="mt-3 text-sm font-bold text-slate-600 dark:text-slate-300">No messages yet</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Be the first to start the conversation.</p>
          </div>
        </div>

        <div class="border-t border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
          <form id="chatForm" enctype="multipart/form-data" autocomplete="off">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">

              <div class="min-w-0 flex-1">
                <div class="mb-1.5 flex items-center justify-between gap-3">
                  <label for="chatMessage" class="text-sm font-bold text-slate-700 dark:text-slate-300">Message</label>
                  <span id="messageCount" class="text-xs font-medium text-slate-400 dark:text-slate-500">0 / 500</span>
                </div>

                <textarea
                  id="chatMessage"
                  rows="2"
                  maxlength="500"
                  placeholder="<?= $isMuted ? 'You are muted from sending public messages.' : 'Type your public message here...' ?>"
                  class="min-h-[54px] max-h-40 w-full resize-y rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-base leading-6 text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:ring-emerald-950 dark:disabled:bg-slate-800/50 dark:disabled:text-slate-500"
                ></textarea>

                <div class="mt-2">
                  <!-- Selected media preview -->
                  <div
                    id="selectedFileBadge"
                    class="mb-2 hidden w-full items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-2.5 dark:border-slate-700 dark:bg-slate-800/70"
                  >
                    <div
                      class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white text-xl text-slate-400 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-500 dark:ring-slate-700"
                    >
                      <img
                        id="selectedMediaImage"
                        src=""
                        alt="Selected photo"
                        class="hidden h-full w-full object-cover"
                      >

                      <video
                        id="selectedMediaVideo"
                        class="hidden h-full w-full object-cover"
                        muted
                        playsinline
                        preload="metadata"
                      ></video>

                      <i id="selectedMediaIcon" class="bi bi-file-earmark"></i>
                    </div>

                    <div class="min-w-0 flex-1">
                      <div
                        id="selectedFileName"
                        class="truncate text-sm font-bold text-slate-700 dark:text-slate-200"
                      ></div>

                      <div
                        id="selectedFileMeta"
                        class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
                      ></div>
                    </div>

                    <button
                      type="button"
                      id="clearFileBtn"
                      class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-red-100 hover:text-red-600 dark:text-slate-500 dark:hover:bg-red-950/50 dark:hover:text-red-300"
                      aria-label="Remove selected media"
                      title="Remove"
                    >
                      <i class="bi bi-x-lg"></i>
                    </button>
                  </div>

                  <!-- Native mobile/app media inputs -->
                  <input
                    type="file"
                    id="chatPhoto"
                    hidden
                    accept="image/*"
                  >

                  <input
                    type="file"
                    id="chatVideo"
                    hidden
                    accept="video/*"
                  >

                  <input
                    type="file"
                    id="chatVoiceFallback"
                    hidden
                    accept="audio/*"
                    capture
                  >

                  <input
                    type="file"
                    id="chatAttachment"
                    hidden
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.txt"
                  >

                  <!-- Native camera fallback for phones/app WebView -->
                  <input
                    type="file"
                    id="chatCamera"
                    hidden
                    accept="image/*"
                    capture="environment"
                  >

                  <!-- Messenger-style quick actions -->
                  <div class="flex flex-wrap items-center gap-2">
                    <button
                      type="button"
                      id="photoBtn"
                      class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 text-xs font-bold text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/70"
                      title="Choose photo"
                    >
                      <i class="bi bi-image-fill text-base"></i>
                      <span>Photo</span>
                    </button>

                    <button
                      type="button"
                      id="videoBtn"
                      class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-3 text-xs font-bold text-violet-700 transition hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-violet-900 dark:bg-violet-950/40 dark:text-violet-300 dark:hover:bg-violet-950/70"
                      title="Choose video"
                    >
                      <i class="bi bi-play-btn-fill text-base"></i>
                      <span>Video</span>
                    </button>

                    <button
                      type="button"
                      id="cameraBtn"
                      class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-3 text-xs font-bold text-blue-700 transition hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-950/70"
                      title="Take photo"
                    >
                      <i class="bi bi-camera-fill text-base"></i>
                      <span>Camera</span>
                    </button>

                    <button
                      type="button"
                      id="voiceMessageBtn"
                      class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 text-xs font-bold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300 dark:hover:bg-rose-950/70"
                      title="Record voice message"
                    >
                      <i class="bi bi-mic-fill text-base"></i>
                      <span>Voice</span>
                    </button>

                    <button
                      type="button"
                      id="attachBtn"
                      class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                      title="Attach document"
                    >
                      <i class="bi bi-paperclip text-base"></i>
                      <span>File</span>
                    </button>
                  </div>

                  <!-- Messenger-style voice recorder -->
                  <div
                    id="voiceRecorderBar"
                    class="mt-2 hidden items-center gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-3 dark:border-rose-900 dark:bg-rose-950/30"
                  >
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rose-600 text-white">
                      <i id="voiceRecorderIcon" class="bi bi-mic-fill"></i>
                    </div>

                    <div class="min-w-0 flex-1">
                      <div class="flex items-center gap-2">
                        <span
                          id="voiceRecordingDot"
                          class="h-2.5 w-2.5 rounded-full bg-red-500"
                        ></span>
                        <span
                          id="voiceRecorderStatus"
                          class="text-sm font-bold text-rose-800 dark:text-rose-200"
                        >
                          Recording voice message…
                        </span>
                      </div>

                      <div
                        id="voiceRecorderTimer"
                        class="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-300"
                      >
                        0:00
                      </div>
                    </div>

                    <button
                      type="button"
                      id="cancelVoiceRecordingBtn"
                      class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-100 hover:text-red-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                      title="Cancel recording"
                      aria-label="Cancel recording"
                    >
                      <i class="bi bi-trash3-fill"></i>
                    </button>

                    <button
                      type="button"
                      id="stopVoiceRecordingBtn"
                      class="inline-flex h-10 items-center justify-center gap-2 rounded-full bg-rose-600 px-4 text-xs font-bold text-white transition hover:bg-rose-700"
                      title="Stop recording"
                    >
                      <i class="bi bi-stop-fill text-base"></i>
                      <span>Stop</span>
                    </button>
                  </div>
                </div>

                <p id="chatTip" class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">
                  Max 500 characters. Everyone in <?= esc($phase) ?> can see your message. Inappropriate or harmful language is blocked. Photos/files/voice messages up to 10MB and videos up to 25MB are allowed.
                </p>
              </div>

              <button
                type="submit"
                id="sendBtn"
                class="inline-flex min-h-[54px] items-center justify-center gap-2 rounded-xl bg-emerald-700 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-400 dark:bg-emerald-600 dark:hover:bg-emerald-500 dark:focus:ring-emerald-950 dark:disabled:bg-slate-700"
                title="Send"
              >
                <i class="bi bi-send-fill"></i>
                <span class="sm:hidden xl:inline">Send</span>
              </button>

            </div>
          </form>
        </div>

      </section>
    </div>

    <footer class="mt-8 border-t border-slate-200 py-6 text-center text-sm text-slate-500 dark:border-slate-800 dark:text-slate-500">
      © South Meridian Homes Salitran
    </footer>
  </main>
</div>


<!-- Required password modal -->
<?php if (!$isTenant && $mustChange): ?>
<div class="fixed inset-0 z-[300] flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
  <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
    <div class="flex items-start gap-3 bg-emerald-700 px-5 py-4 text-white dark:bg-emerald-600">
      <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/15 text-xl">
        <i class="bi bi-shield-lock-fill"></i>
      </div>
      <div>
        <h2 class="font-bold">Change Password Required</h2>
        <p class="mt-1 text-sm leading-5 text-emerald-50">You must change your password before continuing.</p>
      </div>
    </div>

    <form method="POST" autocomplete="off" class="p-5">
      <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
      <input type="hidden" name="change_password_submit" value="1">

      <?php if ($err !== ''): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm leading-6 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
          <i class="bi bi-exclamation-circle-fill mr-1"></i>
          <?= esc($err) ?>
        </div>
      <?php endif; ?>

      <div>
        <label for="newPassword" class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">New Password</label>
        <input
          type="password"
          id="newPassword"
          name="password"
          minlength="8"
          required
          autocomplete="new-password"
          class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-base text-slate-800 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-emerald-950"
        >
        <p class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">Use at least 8 characters.</p>
      </div>

      <div class="mt-4">
        <label for="confirmPassword" class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Confirm Password</label>
        <input
          type="password"
          id="confirmPassword"
          name="password2"
          minlength="8"
          required
          autocomplete="new-password"
          class="min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-base text-slate-800 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-emerald-950"
        >
      </div>

      <button
        type="submit"
        class="mt-5 flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-700 px-5 text-base font-semibold text-white transition hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-100 dark:bg-emerald-600 dark:hover:bg-emerald-500 dark:focus:ring-emerald-950"
      >
        <i class="bi bi-check-circle-fill"></i>
        Save Password
      </button>
    </form>
  </div>
</div>
<?php endif; ?>


<!-- =====================================================
     HOMEOWNER CALLING MODALS
     Homeowner Private Chat only
     ===================================================== -->
<div id="incomingCallModal" class="fixed inset-0 z-[520] hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
  <div class="w-full max-w-sm overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
    <div class="p-6 text-center">
      <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-violet-100 text-3xl text-violet-700 ring-8 ring-violet-50 dark:bg-violet-950/60 dark:text-violet-300 dark:ring-violet-950/30">
        <i id="incomingCallIcon" class="bi bi-telephone-fill"></i>
      </div>
      <p id="incomingCallKind" class="mt-5 text-xs font-bold uppercase tracking-[0.18em] text-violet-600 dark:text-violet-300">Incoming audio call</p>
      <h2 id="incomingCallerName" class="mt-2 text-xl font-bold text-slate-900 dark:text-slate-100">Homeowner</h2>
      <p id="incomingCallerLot" class="mt-1 text-sm text-slate-500 dark:text-slate-400"></p>
      <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">A homeowner from your phase is calling you.</p>

      <div class="mt-6 grid grid-cols-2 gap-3">
        <button type="button" id="declineCallBtn" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-red-600 px-4 text-sm font-bold text-white transition hover:bg-red-700">
          <i class="bi bi-telephone-x-fill"></i>
          Decline
        </button>
        <button type="button" id="answerCallBtn" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-4 text-sm font-bold text-white transition hover:bg-emerald-700">
          <i class="bi bi-telephone-fill"></i>
          Answer
        </button>
      </div>
    </div>
  </div>
</div>

<div id="callModal" class="fixed inset-0 z-[530] hidden items-center justify-center bg-slate-950/90 p-3 backdrop-blur-md sm:p-5">
  <div class="relative flex h-[min(760px,94vh)] w-full max-w-5xl flex-col overflow-hidden rounded-3xl border border-slate-700 bg-slate-950 shadow-2xl">
    <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3 sm:px-5">
      <div class="min-w-0">
        <h2 id="callPeerName" class="truncate text-base font-bold text-white sm:text-lg">Homeowner</h2>
        <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-400">
          <span id="callStatusText">Connecting...</span>
          <span class="text-slate-600">•</span>
          <span id="callTimer">00:00</span>
        </div>
      </div>
      <div id="callTypeBadge" class="rounded-full bg-white/10 px-3 py-1.5 text-xs font-bold text-slate-200">Audio Call</div>
    </div>

    <div class="relative min-h-0 flex-1 overflow-hidden bg-black">
      <div id="audioCallStage" class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-slate-950 via-violet-950 to-slate-950">
        <div class="text-center">
          <div class="mx-auto flex h-28 w-28 items-center justify-center rounded-full bg-white/10 text-4xl text-violet-200 ring-1 ring-white/10 sm:h-36 sm:w-36 sm:text-5xl">
            <i class="bi bi-person-fill"></i>
          </div>
          <p id="audioCallPeerName" class="mt-5 text-xl font-bold text-white sm:text-2xl">Homeowner</p>
          <p class="mt-1 text-sm text-slate-400">Audio call</p>
        </div>
      </div>

      <video id="remoteCallVideo" autoplay playsinline class="hidden h-full w-full bg-black object-cover"></video>
      <audio id="remoteCallAudio" autoplay></audio>

      <div id="localVideoWrap" class="absolute bottom-4 right-4 hidden h-32 w-24 overflow-hidden rounded-2xl border border-white/20 bg-slate-900 shadow-2xl sm:h-44 sm:w-32">
        <video id="localCallVideo" autoplay playsinline muted class="h-full w-full object-cover"></video>
        <div class="absolute bottom-1.5 left-1.5 rounded-md bg-black/55 px-2 py-1 text-[10px] font-bold text-white">You</div>
      </div>
    </div>

    <div class="border-t border-white/10 bg-slate-950 px-3 py-4 sm:px-5">
      <div class="flex flex-wrap items-center justify-center gap-3">
        <button type="button" id="callMuteBtn" class="inline-flex h-12 min-w-12 items-center justify-center gap-2 rounded-full bg-white/10 px-4 text-sm font-bold text-white transition hover:bg-white/20" title="Mute microphone">
          <i class="bi bi-mic-fill"></i>
          <span class="hidden sm:inline">Mute</span>
        </button>

        <button type="button" id="callCameraBtn" class="hidden h-12 min-w-12 items-center justify-center gap-2 rounded-full bg-white/10 px-4 text-sm font-bold text-white transition hover:bg-white/20" title="Turn camera off">
          <i class="bi bi-camera-video-fill"></i>
          <span class="hidden sm:inline">Camera</span>
        </button>

        <button type="button" id="endCallBtn" class="inline-flex h-12 min-w-14 items-center justify-center gap-2 rounded-full bg-red-600 px-5 text-sm font-bold text-white transition hover:bg-red-700" title="End call">
          <i class="bi bi-telephone-x-fill text-lg"></i>
          <span class="hidden sm:inline">End Call</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Muted information modal -->
<div id="mutedInfoModal" class="fixed inset-0 z-[310] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
  <div class="w-full max-w-lg overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
    <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
      <div>
        <h2 class="font-bold text-slate-900 dark:text-slate-100">Public Chat Restriction</h2>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><?= esc($phase) ?></p>
      </div>
      <button type="button" id="closeMutedInfoModal" class="flex h-10 w-10 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" aria-label="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <div class="p-5">
      <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/40">
        <i class="bi bi-slash-circle-fill mt-0.5 shrink-0 text-red-700 dark:text-red-300"></i>
        <div>
          <p class="text-sm font-bold text-red-900 dark:text-red-200">You are muted from public chat</p>
          <p class="mt-1 text-sm leading-6 text-red-800 dark:text-red-300">
            You can still read public messages. Sending, file attachments, and camera uploads remain disabled until an admin removes the restriction.
          </p>
        </div>
      </div>

      <?php if ($muteReason !== ''): ?>
        <div class="mt-4 rounded-xl bg-slate-50 p-4 dark:bg-slate-800/60">
          <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Reason</p>
          <p class="mt-1 break-words text-sm text-slate-700 dark:text-slate-300"><?= esc($muteReason) ?></p>
        </div>
      <?php endif; ?>
    </div>

    <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 dark:border-slate-800 dark:bg-slate-950/40">
      <button type="button" id="okMutedInfoBtn" class="min-h-11 rounded-xl bg-slate-800 px-5 text-sm font-semibold text-white hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600">OK</button>
    </div>
  </div>
</div>


<!-- Notice modal -->
<div id="noticeModal" class="fixed inset-0 z-[320] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
  <div class="w-full max-w-lg overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
    <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
      <h2 id="noticeModalTitle" class="font-bold text-slate-900 dark:text-slate-100">Notice</h2>
      <button type="button" id="closeNoticeModal" class="flex h-10 w-10 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" aria-label="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <div class="p-5">
      <p id="noticeModalMessage" class="whitespace-pre-wrap break-words text-sm leading-6 text-slate-700 dark:text-slate-300"></p>
    </div>

    <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 dark:border-slate-800 dark:bg-slate-950/40">
      <button type="button" id="okNoticeBtn" class="min-h-11 rounded-xl bg-emerald-700 px-5 text-sm font-semibold text-white hover:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500">OK</button>
    </div>
  </div>
</div>



<!-- Edit message modal -->
<div id="editMessageModal" class="fixed inset-0 z-[330] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
  <div class="w-full max-w-lg overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
      <div>
        <h2 class="font-bold text-slate-900 dark:text-slate-100">Edit Message</h2>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Messages can be edited within 15 minutes after sending.</p>
      </div>
      <button type="button" id="closeEditMessageModal" class="flex h-10 w-10 items-center justify-center rounded-xl text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="p-5">
      <textarea id="editMessageText" rows="4" class="min-h-[110px] w-full resize-y rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-800 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus:ring-emerald-950"></textarea>
      <div class="mt-4 flex justify-end gap-2">
        <button type="button" id="cancelEditMessageBtn" class="min-h-11 rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">Cancel</button>
        <button type="button" id="saveEditMessageBtn" class="min-h-11 rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white hover:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500">Save Changes</button>
      </div>
    </div>
  </div>
</div>

<!-- Delete message modal -->
<div id="deleteMessageModal" class="fixed inset-0 z-[335] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
  <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
    <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800"><h2 class="font-bold text-slate-900 dark:text-slate-100">Delete Message</h2></div>
    <div class="p-5">
      <p class="text-sm leading-6 text-slate-700 dark:text-slate-300">Delete this message? This action cannot be undone.</p>
      <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Messages can only be deleted within 15 minutes after sending.</p>
      <div class="mt-5 flex justify-end gap-2">
        <button type="button" id="cancelDeleteMessageBtn" class="min-h-11 rounded-xl border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">Cancel</button>
        <button type="button" id="confirmDeleteMessageBtn" class="min-h-11 rounded-xl bg-red-600 px-4 text-sm font-semibold text-white hover:bg-red-700">Delete</button>
      </div>
    </div>
  </div>
</div>

<!-- Image preview modal -->
<div id="imagePreviewModal" class="fixed inset-0 z-[400] hidden items-center justify-center bg-slate-950/85 p-4 backdrop-blur-sm">
  <div class="relative flex max-h-[92vh] max-w-[96vw] items-center justify-center">
    <button
      type="button"
      id="imagePreviewClose"
      class="absolute right-0 top-0 z-10 flex h-11 w-11 -translate-y-2 translate-x-2 items-center justify-center rounded-full bg-white text-xl text-slate-900 shadow-lg hover:bg-slate-100"
      aria-label="Close image preview"
    >
      <i class="bi bi-x-lg"></i>
    </button>
    <img id="imagePreviewFull" src="" alt="Preview" class="max-h-[90vh] max-w-full rounded-2xl bg-white object-contain shadow-2xl">
  </div>
</div>

<!-- =====================================================
     CAMERA MODAL
     ===================================================== -->

<div
    id="cameraModal"
    class="
        fixed inset-0 z-[450]
        hidden
        items-center justify-center
        bg-slate-950/85
        p-4
        backdrop-blur-sm
    "
>
    <div
        class="
            w-full max-w-2xl
            overflow-hidden
            rounded-2xl
            border border-slate-700
            bg-slate-900
            shadow-2xl
        "
    >

        <!-- Header -->
        <div
            class="
                flex items-center justify-between
                border-b border-slate-700
                px-4 py-3
            "
        >
            <div>
                <h2 class="font-bold text-white">
                    Take Photo
                </h2>

                <p class="mt-0.5 text-xs text-slate-400">
                    Choose a camera available on this device.
                </p>
            </div>

            <button
                type="button"
                id="cameraCloseBtn"
                class="
                    flex h-10 w-10
                    items-center justify-center
                    rounded-xl
                    text-slate-300
                    hover:bg-slate-800
                "
                aria-label="Close camera"
            >
                <i class="bi bi-x-lg"></i>
            </button>
        </div>


        <!-- Camera -->
        <div class="p-4">

            <!-- Camera selector -->
            <div class="mb-3">
                <label
                    for="cameraDeviceSelect"
                    class="
                        mb-1.5 block
                        text-xs font-bold
                        uppercase tracking-wide
                        text-slate-400
                    "
                >
                    Camera
                </label>

                <select
                    id="cameraDeviceSelect"
                    class="
                        min-h-11 w-full
                        rounded-xl
                        border border-slate-700
                        bg-slate-800
                        px-3
                        text-sm
                        text-white
                        outline-none
                    "
                >
                    <option value="">
                        Detecting camera...
                    </option>
                </select>
            </div>


            <!-- Preview -->
            <div
                class="
                    relative
                    overflow-hidden
                    rounded-2xl
                    bg-black
                "
            >

                <video
                    id="cameraVideo"
                    autoplay
                    playsinline
                    muted
                    class="
                        aspect-video
                        w-full
                        bg-black
                        object-cover
                    "
                ></video>


                <div
                    id="cameraLoading"
                    class="
                        absolute inset-0
                        flex
                        items-center justify-center
                        bg-black/70
                        text-sm font-semibold
                        text-white
                    "
                >
                    <div class="text-center">
                        <i
                            class="
                                bi bi-camera-video
                                mb-2 block
                                text-3xl
                            "
                        ></i>

                        Starting camera...
                    </div>
                </div>

            </div>


            <!-- Error -->
            <div
                id="cameraError"
                class="
                    mt-3 hidden
                    rounded-xl
                    border border-red-900
                    bg-red-950/50
                    p-3
                    text-sm
                    text-red-200
                "
            ></div>


            <!-- Controls -->
            <div
                class="
                    mt-4
                    flex flex-wrap
                    items-center justify-center
                    gap-2
                "
            >

                <button
                    type="button"
                    id="cameraRetryBtn"
                    class="
                        hidden min-h-11
                        items-center justify-center
                        gap-2
                        rounded-xl
                        border border-slate-600
                        bg-slate-800
                        px-4
                        text-sm font-semibold
                        text-white
                        hover:bg-slate-700
                    "
                >
                    <i class="bi bi-arrow-clockwise"></i>
                    Retry
                </button>


                <button
                    type="button"
                    id="cameraCaptureBtn"
                    class="
                        inline-flex min-h-12
                        items-center justify-center
                        gap-2
                        rounded-xl
                        bg-emerald-600
                        px-5
                        text-sm font-bold
                        text-white
                        shadow-sm
                        hover:bg-emerald-500
                        disabled:cursor-not-allowed
                        disabled:bg-slate-600
                    "
                    disabled
                >
                    <i class="bi bi-camera-fill"></i>
                    Take Photo
                </button>


                <button
                    type="button"
                    id="cameraFallbackBtn"
                    class="
                        inline-flex min-h-11
                        items-center justify-center
                        gap-2
                        rounded-xl
                        border border-slate-600
                        bg-slate-800
                        px-4
                        text-sm font-semibold
                        text-slate-200
                        hover:bg-slate-700
                    "
                >
                    <i class="bi bi-image"></i>
                    Device Camera
                </button>

            </div>


            <canvas
                id="cameraCanvas"
                class="hidden"
            ></canvas>

        </div>

    </div>
</div>
<script>
const CSRF_TOKEN = <?= json_encode($csrf) ?>;
const isPublicMuted = <?= $isMuted ? 'true' : 'false' ?>;
const muteReason = <?= json_encode($muteReason) ?>;
const phase = <?= json_encode($phase) ?>;
const defaultOfficerId = <?= (int)$defaultOfficerId ?>;
const defaultHomeownerId = <?= (int)$defaultHomeownerId ?>;
const isTenantAccount = <?= $isTenant ? 'true' : 'false' ?>;
const currentHomeownerId = <?= (int)$hid ?>;

const homeownerCallActions = document.getElementById('homeownerCallActions');
const audioCallBtn = document.getElementById('audioCallBtn');
const videoCallBtn = document.getElementById('videoCallBtn');
const incomingCallModal = document.getElementById('incomingCallModal');
const incomingCallIcon = document.getElementById('incomingCallIcon');
const incomingCallKind = document.getElementById('incomingCallKind');
const incomingCallerName = document.getElementById('incomingCallerName');
const incomingCallerLot = document.getElementById('incomingCallerLot');
const answerCallBtn = document.getElementById('answerCallBtn');
const declineCallBtn = document.getElementById('declineCallBtn');
const callModal = document.getElementById('callModal');
const callPeerName = document.getElementById('callPeerName');
const callStatusText = document.getElementById('callStatusText');
const callTimer = document.getElementById('callTimer');
const callTypeBadge = document.getElementById('callTypeBadge');
const audioCallStage = document.getElementById('audioCallStage');
const audioCallPeerName = document.getElementById('audioCallPeerName');
const remoteCallVideo = document.getElementById('remoteCallVideo');
const remoteCallAudio = document.getElementById('remoteCallAudio');
const localVideoWrap = document.getElementById('localVideoWrap');
const localCallVideo = document.getElementById('localCallVideo');
const callMuteBtn = document.getElementById('callMuteBtn');
const callCameraBtn = document.getElementById('callCameraBtn');
const endCallBtn = document.getElementById('endCallBtn');

const chatBody = document.getElementById('chatBody');
const chatForm = document.getElementById('chatForm');
const chatMessage = document.getElementById('chatMessage');
const sendBtn = document.getElementById('sendBtn');
const chatRoomTitle = document.getElementById('chatRoomTitle');
const chatRoomSub = document.getElementById('chatRoomSub');
const chatTip = document.getElementById('chatTip');
const messageCount = document.getElementById('messageCount');

const modePublicBtn = document.getElementById('modePublicBtn');
const modeHomeownerBtn = document.getElementById('modeHomeownerBtn');
const modeOfficerBtn = document.getElementById('modeOfficerBtn');
const officerSelect = document.getElementById('officerSelect');
const homeownerSelect = document.getElementById('homeownerSelect');

const photoBtn = document.getElementById('photoBtn');
const videoBtn = document.getElementById('videoBtn');
const cameraBtn = document.getElementById('cameraBtn');
const voiceMessageBtn = document.getElementById('voiceMessageBtn');
const attachBtn = document.getElementById('attachBtn');

const chatPhoto = document.getElementById('chatPhoto');
const chatVideo = document.getElementById('chatVideo');
const chatVoiceFallback = document.getElementById('chatVoiceFallback');
const chatAttachment = document.getElementById('chatAttachment');
const chatCamera = document.getElementById('chatCamera');

const voiceRecorderBar = document.getElementById('voiceRecorderBar');
const voiceRecorderStatus = document.getElementById('voiceRecorderStatus');
const voiceRecorderTimer = document.getElementById('voiceRecorderTimer');
const voiceRecordingDot = document.getElementById('voiceRecordingDot');
const stopVoiceRecordingBtn = document.getElementById('stopVoiceRecordingBtn');
const cancelVoiceRecordingBtn = document.getElementById('cancelVoiceRecordingBtn');
/*
|--------------------------------------------------------------------------
| Live Camera
|--------------------------------------------------------------------------
*/

const cameraModal =
    document.getElementById(
        'cameraModal'
    );

const cameraVideo =
    document.getElementById(
        'cameraVideo'
    );

const cameraCanvas =
    document.getElementById(
        'cameraCanvas'
    );

const cameraDeviceSelect =
    document.getElementById(
        'cameraDeviceSelect'
    );

const cameraCaptureBtn =
    document.getElementById(
        'cameraCaptureBtn'
    );

const cameraCloseBtn =
    document.getElementById(
        'cameraCloseBtn'
    );

const cameraRetryBtn =
    document.getElementById(
        'cameraRetryBtn'
    );

const cameraFallbackBtn =
    document.getElementById(
        'cameraFallbackBtn'
    );

const cameraLoading =
    document.getElementById(
        'cameraLoading'
    );

const cameraError =
    document.getElementById(
        'cameraError'
    );


let cameraStream = null;

let cameraCapturedFile = null;
const selectedFileBadge = document.getElementById('selectedFileBadge');
const selectedFileName = document.getElementById('selectedFileName');
const selectedFileMeta = document.getElementById('selectedFileMeta');
const selectedMediaImage = document.getElementById('selectedMediaImage');
const selectedMediaVideo = document.getElementById('selectedMediaVideo');
const selectedMediaIcon = document.getElementById('selectedMediaIcon');
const clearFileBtn = document.getElementById('clearFileBtn');

let selectedMediaObjectUrl = '';

let voiceRecorder = null;
let voiceRecorderStream = null;
let voiceRecorderChunks = [];
let voiceRecordedFile = null;
let voiceRecordingStartedAt = 0;
let voiceRecordingTimerId = null;
let voiceRecordingCancelled = false;

const imagePreviewModal = document.getElementById('imagePreviewModal');
const imagePreviewFull = document.getElementById('imagePreviewFull');
const imagePreviewClose = document.getElementById('imagePreviewClose');

const noticeModal = document.getElementById('noticeModal');
const noticeModalTitle = document.getElementById('noticeModalTitle');
const noticeModalMessage = document.getElementById('noticeModalMessage');
const closeNoticeModal = document.getElementById('closeNoticeModal');
const okNoticeBtn = document.getElementById('okNoticeBtn');

const mutedInfoModal = document.getElementById('mutedInfoModal');
const btnOpenMutedInfo = document.getElementById('btnOpenMutedInfo');
const closeMutedInfoModal = document.getElementById('closeMutedInfoModal');
const okMutedInfoBtn = document.getElementById('okMutedInfoBtn');

let currentMode = 'public';
let selectedOfficerId = defaultOfficerId;
let selectedHomeownerId = defaultHomeownerId;
let publicLastId = 0;
let homeownerLastId = 0;
let officerLastId = 0;
let isFetching = false;

let messageActionTarget = { id:0, scope:'', message:'' };
const editMessageModal = document.getElementById('editMessageModal');
const editMessageText = document.getElementById('editMessageText');
const closeEditMessageModal = document.getElementById('closeEditMessageModal');
const cancelEditMessageBtn = document.getElementById('cancelEditMessageBtn');
const saveEditMessageBtn = document.getElementById('saveEditMessageBtn');
const deleteMessageModal = document.getElementById('deleteMessageModal');
const cancelDeleteMessageBtn = document.getElementById('cancelDeleteMessageBtn');
const confirmDeleteMessageBtn = document.getElementById('confirmDeleteMessageBtn');

if (officerSelect && officerSelect.value) {
  selectedOfficerId = Number(officerSelect.value || 0);
}

if (homeownerSelect && homeownerSelect.value) {
  selectedHomeownerId = Number(homeownerSelect.value || 0);
}


function escapeHtml(value) {
  const div = document.createElement('div');
  div.textContent = String(value ?? '');
  return div.innerHTML;
}


function normalizePath(path) {
  return String(path || '').replace(/\\/g, '/');
}


function isImageAttachment(message) {
  const type = String(message.attachment_type || '').toLowerCase();

  return [
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/gif',
    'image/webp'
  ].includes(type);
}


function isVideoAttachment(message) {
  const type = String(message.attachment_type || '').toLowerCase();
  return type.startsWith('video/');
}


function isAudioAttachment(message) {
  const type = String(message.attachment_type || '').toLowerCase();
  return type.startsWith('audio/');
}


function isVoiceMessageAttachment(message) {
  const type =
    String(
      message.attachment_type || ''
    ).toLowerCase();

  const name =
    String(
      message.attachment_name || ''
    ).toLowerCase();

  return (
    type.startsWith('audio/') ||
    /^voice_\d+\./i.test(name)
  );
}


function isVoiceOnlyMessage(message) {
  return (
    isVoiceMessageAttachment(message) &&
    !String(message.message || '').trim()
  );
}


function setModalOpen(modal, open) {
  if (!modal) return;

  modal.classList.toggle('hidden', !open);
  modal.classList.toggle('flex', open);

  if (open) {
    document.body.classList.add('overflow-hidden');
  } else if (!document.querySelector('#noticeModal.flex, #mutedInfoModal.flex, #imagePreviewModal.flex, #editMessageModal.flex, #deleteMessageModal.flex, #incomingCallModal.flex, #callModal.flex')) {
    document.body.classList.remove('overflow-hidden');
  }
}


function openNoticeModal(title, message) {
  if (!noticeModal || !noticeModalTitle || !noticeModalMessage) return;

  noticeModalTitle.textContent = title || 'Notice';
  noticeModalMessage.textContent = message || '';
  setModalOpen(noticeModal, true);
}


function closeNoticeDialog() {
  setModalOpen(noticeModal, false);
}


function openMutedInfoModal() {
  setModalOpen(mutedInfoModal, true);
}


function closeMutedInfoDialog() {
  setModalOpen(mutedInfoModal, false);
}


function openImagePreview(src) {
  if (!src || !imagePreviewModal || !imagePreviewFull) return;

  imagePreviewFull.src = src;
  setModalOpen(imagePreviewModal, true);
}


function closeImagePreview() {
  if (imagePreviewFull) {
    imagePreviewFull.src = '';
  }

  setModalOpen(imagePreviewModal, false);
}


async function postFormData(formData) {
  if (!formData.has('csrf')) {
    formData.append('csrf', CSRF_TOKEN);
  }

  const response = await fetch('homeowner_public_chat.php', {
    method: 'POST',
    body: formData,
    credentials: 'same-origin'
  });

  let data = null;

  try {
    data = await response.json();
  } catch (error) {
    throw new Error('The server returned an invalid response.');
  }

  if (!response.ok && !data?.message) {
    throw new Error('Request failed.');
  }

  return data;
}


async function postJSON(action, payload = {}) {
  const fd = new FormData();
  fd.append('action', action);

  for (const [key, value] of Object.entries(payload)) {
    fd.append(key, value);
  }

  return await postFormData(fd);
}


function renderAttachmentHtml(message) {
  const path = normalizePath(message.attachment_path || '');
  const name = escapeHtml(message.attachment_name || 'Attachment');

  if (!path) return '';

  /*
   * Voice recordings are checked before video because some servers
   * identify audio-only WebM recordings as video/webm.
   */
  if (isVoiceMessageAttachment(message)) {
    const sourceType =
      String(message.attachment_type || '').toLowerCase() === 'video/webm'
        ? 'audio/webm'
        : (message.attachment_type || 'audio/webm');

    return `
      <audio
        controls
        preload="metadata"
        class="block h-10 w-[260px] max-w-[72vw]"
      >
        <source
          src="${escapeHtml(path)}"
          type="${escapeHtml(sourceType)}"
        >
        Your browser does not support audio playback.
      </audio>
    `;
  }

  if (isImageAttachment(message)) {
    return `
      <div class="mt-2">
        <img
          src="${escapeHtml(path)}"
          alt="${name}"
          class="previewable-image max-h-72 max-w-full cursor-pointer rounded-2xl border border-black/10 bg-white object-contain shadow-sm"
          data-src="${escapeHtml(path)}"
        >
      </div>
    `;
  }

  if (isVideoAttachment(message)) {
    return `
      <div class="mt-2 overflow-hidden rounded-2xl border border-black/10 bg-black shadow-sm">
        <video
          controls
          playsinline
          preload="metadata"
          class="max-h-[360px] w-full bg-black object-contain"
        >
          <source
            src="${escapeHtml(path)}"
            type="${escapeHtml(message.attachment_type || 'video/mp4')}"
          >
          Your browser does not support video playback.
        </video>
      </div>
    `;
  }

  return `
    <div class="mt-2">
      <a
        href="${escapeHtml(path)}"
        target="_blank"
        rel="noopener"
        class="inline-flex max-w-full items-center gap-2 rounded-xl border border-current/20 bg-black/5 px-3 py-2 text-xs font-semibold hover:bg-black/10 dark:bg-white/5 dark:hover:bg-white/10"
      >
        <i class="bi bi-file-earmark-arrow-down"></i>
        <span class="truncate">${name}</span>
      </a>
    </div>
  `;
}

function messageManageHtml(message, scope) {
  if (!message.mine || !message.can_manage) return '';

  return `
    <span
      class="message-manage-actions inline-flex items-center gap-1 transition sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100"
      data-editable-until="${Number(message.editable_until || 0)}"
    >
      <button
        type="button"
        class="edit-chat-message flex h-6 w-6 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-200 hover:text-blue-600 dark:text-slate-500 dark:hover:bg-slate-700 dark:hover:text-blue-300"
        data-id="${Number(message.id || 0)}"
        data-scope="${escapeHtml(scope)}"
        data-message="${escapeHtml(message.message || '')}"
        title="Edit message"
        aria-label="Edit message"
      >
        <i class="bi bi-pencil-square text-[11px]"></i>
      </button>

      <button
        type="button"
        class="delete-chat-message flex h-6 w-6 items-center justify-center rounded-full text-slate-400 transition hover:bg-red-100 hover:text-red-600 dark:text-slate-500 dark:hover:bg-red-950/50 dark:hover:text-red-300"
        data-id="${Number(message.id || 0)}"
        data-scope="${escapeHtml(scope)}"
        title="Delete message"
        aria-label="Delete message"
      >
        <i class="bi bi-trash3 text-[11px]"></i>
      </button>
    </span>
  `;
}


function messageAvatarHtml(message, fallback = 'U', toneClasses = '') {
  const avatarContent =
    message.profile_picture_url
      ? `
          <img
            src="${escapeHtml(message.profile_picture_url)}"
            alt="${escapeHtml(message.name || 'User')}"
            class="h-full w-full object-cover"
            loading="lazy"
          >
        `
      : escapeHtml(message.initials || fallback);

  return `
    <div
      class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-full text-xs font-bold ${toneClasses}"
      title="${escapeHtml(message.name || 'User')}"
    >
      ${avatarContent}
    </div>
  `;
}


function messageTimeHtml(message, scope) {
  return `
    <div
      class="mt-1 flex min-h-5 items-center gap-1.5 text-[10px] font-medium leading-none text-slate-400 dark:text-slate-500 ${
        message.mine ? 'justify-end pr-1' : 'justify-start pl-1'
      }"
    >
      <span>${escapeHtml(message.created_at || '')}</span>
      ${messageManageHtml(message, scope)}
    </div>
  `;
}


function createOwnMessageLayout(message, scope, bubbleClasses) {
  const avatar =
    messageAvatarHtml(
      {
        ...message,
        name: 'You'
      },
      'Y',
      'bg-emerald-700 text-white dark:bg-emerald-600'
    );

  const voiceOnly =
    isVoiceOnlyMessage(message);

  const contentHtml =
    voiceOnly
      ? `
          <div class="flex justify-end">
            ${renderAttachmentHtml(message)}
          </div>
        `
      : `
          <div class="flex justify-end">
            <div
              class="w-fit max-w-full break-words rounded-2xl rounded-tr-md px-3.5 py-2.5 text-sm leading-6 shadow-sm ${bubbleClasses}"
            >
              ${message.message ? escapeHtml(message.message).replace(/\n/g, '<br>') : ''}
              ${renderAttachmentHtml(message)}
            </div>
          </div>
        `;

  return `
    <div class="group flex w-full items-start justify-end gap-2.5">
      <div class="max-w-[82%] min-w-0 sm:max-w-[70%]">
        <div class="mb-1 pr-1 text-right text-[12px] font-bold leading-4 text-slate-800 dark:text-slate-100">
          You
        </div>

        ${contentHtml}

        ${messageTimeHtml(message, scope)}
      </div>

      ${avatar}
    </div>
  `;
}

function createPublicMessage(message) {
  const row = document.createElement('div');

  row.dataset.id = String(message.id || '');
  row.className = 'w-full';

  const voiceOnly =
    isVoiceOnlyMessage(message);

  if (message.mine) {
    row.innerHTML =
      createOwnMessageLayout(
        message,
        'public',
        'bg-emerald-700 text-white dark:bg-emerald-600'
      );

    return row;
  }

  const avatar =
    messageAvatarHtml(
      message,
      'H',
      'bg-emerald-700 text-white dark:bg-emerald-600'
    );

  row.innerHTML = `
    <div class="flex w-full items-start gap-2.5">
      ${avatar}

      <div class="max-w-[82%] min-w-0 sm:max-w-[70%]">
        <div class="mb-1 pl-1 text-[12px] font-bold leading-4 text-slate-800 dark:text-slate-100">
          ${escapeHtml(message.name || 'Homeowner')}
        </div>

        <div
          class="${voiceOnly
            ? 'w-fit max-w-full'
            : 'w-fit max-w-full break-words rounded-2xl rounded-tl-md bg-slate-100 px-3.5 py-2.5 text-sm leading-6 text-slate-800 shadow-sm dark:bg-slate-800 dark:text-slate-100'
          }"
        >
          ${message.message ? escapeHtml(message.message).replace(/\n/g, '<br>') : ''}
          ${renderAttachmentHtml(message)}
        </div>

        ${messageTimeHtml(message, 'public')}
      </div>
    </div>
  `;

  return row;
}


function createHomeownerMessage(message) {
  const row = document.createElement('div');

  row.dataset.id = String(message.id || '');
  row.dataset.homeownerTimelineItem = '1';
  row.dataset.time = String(Number(message.timestamp || 0));
  row.className = 'w-full';

  const voiceOnly =
    isVoiceOnlyMessage(message);

  if (message.mine) {
    row.innerHTML =
      createOwnMessageLayout(
        message,
        'homeowner',
        'bg-violet-700 text-white dark:bg-violet-600'
      );

    return row;
  }

  const avatar =
    messageAvatarHtml(
      message,
      'H',
      'bg-emerald-700 text-white dark:bg-emerald-600'
    );

  row.innerHTML = `
    <div class="flex w-full items-start gap-2.5">
      ${avatar}

      <div class="max-w-[82%] min-w-0 sm:max-w-[70%]">
        <div class="mb-1 pl-1 text-[12px] font-bold leading-4 text-slate-800 dark:text-slate-100">
          ${escapeHtml(message.name || 'Homeowner')}
        </div>

        <div
          class="${voiceOnly
            ? 'w-fit max-w-full'
            : 'w-fit max-w-full break-words rounded-2xl rounded-tl-md bg-slate-100 px-3.5 py-2.5 text-sm leading-6 text-slate-800 shadow-sm dark:bg-slate-800 dark:text-slate-100'
          }"
        >
          ${message.message ? escapeHtml(message.message).replace(/\n/g, '<br>') : ''}
          ${renderAttachmentHtml(message)}
        </div>

        ${messageTimeHtml(message, 'homeowner')}
      </div>
    </div>
  `;

  return row;
}


function createOfficerMessage(message) {
  const row = document.createElement('div');

  row.dataset.id = String(message.id || '');
  row.className = 'w-full';

  const voiceOnly =
    isVoiceOnlyMessage(message);

  if (message.mine) {
    row.innerHTML =
      createOwnMessageLayout(
        message,
        'officer',
        'bg-blue-700 text-white dark:bg-blue-600'
      );

    return row;
  }

  const avatar =
    messageAvatarHtml(
      message,
      'O',
      'bg-emerald-700 text-white dark:bg-emerald-600'
    );

  row.innerHTML = `
    <div class="flex w-full items-start gap-2.5">
      ${avatar}

      <div class="max-w-[82%] min-w-0 sm:max-w-[70%]">
        <div class="mb-1 pl-1 text-[12px] font-bold leading-4 text-slate-800 dark:text-slate-100">
          ${escapeHtml(message.name || 'Officer')}
        </div>

        <div
          class="${voiceOnly
            ? 'w-fit max-w-full'
            : 'w-fit max-w-full break-words rounded-2xl rounded-tl-md bg-slate-100 px-3.5 py-2.5 text-sm leading-6 text-slate-800 shadow-sm dark:bg-slate-800 dark:text-slate-100'
          }"
        >
          ${message.message ? escapeHtml(message.message).replace(/\n/g, '<br>') : ''}
          ${renderAttachmentHtml(message)}
        </div>

        ${messageTimeHtml(message, 'officer')}
      </div>
    </div>
  `;

  return row;
}


function emptyState(text) {
  return `
    <div id="chatEmpty" class="flex h-full flex-col items-center justify-center px-4 text-center">
      <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-2xl text-slate-400 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-500 dark:ring-slate-800">
        <i class="bi bi-chat-square-dots"></i>
      </div>
      <p class="mt-3 text-sm font-bold text-slate-600 dark:text-slate-300">No messages yet</p>
      <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">${escapeHtml(text)}</p>
    </div>
  `;
}


function publicMuteCard() {
  if (!isPublicMuted) return '';

  return `
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/40">
      <div class="flex items-start gap-3">
        <i class="bi bi-slash-circle-fill mt-0.5 shrink-0 text-red-700 dark:text-red-300"></i>
        <div>
          <p class="text-sm font-bold text-red-900 dark:text-red-200">You are muted from public chat</p>
          <p class="mt-1 text-xs leading-5 text-red-800 dark:text-red-300">You can still read messages, but you cannot send new ones right now.</p>
          ${muteReason ? `<p class="mt-2 break-words text-xs text-red-700 dark:text-red-300"><strong>Reason:</strong> ${escapeHtml(muteReason)}</p>` : ''}
        </div>
      </div>
    </div>
  `;
}


function isNearBottom(element) {
  return (element.scrollHeight - element.scrollTop - element.clientHeight) < 120;
}


function scrollToBottom() {
  if (!chatBody) return;
  chatBody.scrollTop = chatBody.scrollHeight;
}


function formatChatFileSize(bytes) {
  const size = Number(bytes || 0);

  if (size < 1024) {
    return `${size} B`;
  }

  if (size < 1024 * 1024) {
    return `${(size / 1024).toFixed(1)} KB`;
  }

  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}


function releaseSelectedMediaObjectUrl() {
  if (selectedMediaObjectUrl) {
    URL.revokeObjectURL(selectedMediaObjectUrl);
    selectedMediaObjectUrl = '';
  }
}


function updateSelectedFileUI(file) {
  if (!selectedFileBadge || !selectedFileName) return;

  releaseSelectedMediaObjectUrl();

  selectedMediaImage?.classList.add('hidden');
  selectedMediaVideo?.classList.add('hidden');

  if (selectedMediaIcon) {
    selectedMediaIcon.className =
      'bi bi-file-earmark text-xl';

    selectedMediaIcon.classList.remove(
      'hidden'
    );
  }

  if (selectedMediaImage) {
    selectedMediaImage.src = '';
  }

  if (selectedMediaVideo) {
    selectedMediaVideo.pause();
    selectedMediaVideo.removeAttribute('src');
    selectedMediaVideo.load();
  }

  if (!file) {
    selectedFileBadge.classList.add('hidden');
    selectedFileBadge.classList.remove('flex');
    selectedFileName.textContent = '';

    if (selectedFileMeta) {
      selectedFileMeta.textContent = '';
    }

    return;
  }

  const type =
    String(file.type || '')
      .toLowerCase();

  const isImage =
    type.startsWith('image/');

  const isVideo =
    type.startsWith('video/');

  const isAudio =
    type.startsWith('audio/');

  selectedFileName.textContent =
    isAudio
      ? 'Voice message'
      : (file.name || 'Selected media');

  if (selectedFileMeta) {
    selectedFileMeta.textContent =
      `${isImage ? 'Photo' : (isVideo ? 'Video' : (isAudio ? 'Voice message' : 'File'))} • ${formatChatFileSize(file.size)}`;
  }

  selectedFileBadge.classList.remove('hidden');
  selectedFileBadge.classList.add('flex');

  if (isImage && selectedMediaImage) {
    selectedMediaObjectUrl =
      URL.createObjectURL(file);

    selectedMediaImage.src =
      selectedMediaObjectUrl;

    selectedMediaImage.classList.remove('hidden');
    selectedMediaIcon?.classList.add('hidden');

  } else if (isVideo && selectedMediaVideo) {
    selectedMediaObjectUrl =
      URL.createObjectURL(file);

    selectedMediaVideo.src =
      selectedMediaObjectUrl;

    selectedMediaVideo.classList.remove('hidden');
    selectedMediaIcon?.classList.add('hidden');

  } else if (isAudio && selectedMediaIcon) {
    selectedMediaIcon.className =
      'bi bi-mic-fill text-xl text-rose-600 dark:text-rose-300';

  } else if (selectedMediaIcon) {
    selectedMediaIcon.className =
      'bi bi-file-earmark-text text-xl';
  }
}


function clearSelectedFiles() {
  if (chatPhoto) {
    chatPhoto.value = '';
  }

  if (chatVideo) {
    chatVideo.value = '';
  }

  if (chatVoiceFallback) {
    chatVoiceFallback.value = '';
  }

  if (chatAttachment) {
    chatAttachment.value = '';
  }

  if (chatCamera) {
    chatCamera.value = '';
  }

  cameraCapturedFile = null;
  voiceRecordedFile = null;
  updateSelectedFileUI(null);
}


function clearOtherFileChoices(except = '') {
  if (except !== 'photo' && chatPhoto) {
    chatPhoto.value = '';
  }

  if (except !== 'video' && chatVideo) {
    chatVideo.value = '';
  }

  if (except !== 'voiceFallback' && chatVoiceFallback) {
    chatVoiceFallback.value = '';
  }

  if (except !== 'attachment' && chatAttachment) {
    chatAttachment.value = '';
  }

  if (except !== 'camera' && chatCamera) {
    chatCamera.value = '';
  }

  if (except !== 'captured') {
    cameraCapturedFile = null;
  }

  if (except !== 'voiceRecorded') {
    voiceRecordedFile = null;
  }
}


function getSelectedFile() {
  if (voiceRecordedFile) {
    return voiceRecordedFile;
  }

  if (cameraCapturedFile) {
    return cameraCapturedFile;
  }

  if (chatPhoto?.files?.length) {
    return chatPhoto.files[0];
  }

  if (chatVideo?.files?.length) {
    return chatVideo.files[0];
  }

  if (chatVoiceFallback?.files?.length) {
    return chatVoiceFallback.files[0];
  }

  if (chatCamera?.files?.length) {
    return chatCamera.files[0];
  }

  if (chatAttachment?.files?.length) {
    return chatAttachment.files[0];
  }

  return null;
}


function validateSelectedFile(file) {
  if (!file) return true;

  const type =
    String(file.type || '')
      .toLowerCase();

  const isVideo =
    type.startsWith('video/');

  const isAudio =
    type.startsWith('audio/');

  const maxSize =
    isVideo
      ? (25 * 1024 * 1024)
      : (10 * 1024 * 1024);

  if (file.size > maxSize) {
    clearSelectedFiles();

    openNoticeModal(
      isVideo
        ? 'Video Too Large'
        : 'Attachment Too Large',
      isVideo
        ? 'The selected video must not exceed 25MB.'
        : (
            isAudio
              ? 'The voice message must not exceed 10MB.'
              : 'The selected photo or file must not exceed 10MB.'
          )
    );

    return false;
  }

  return true;
}

/*
|--------------------------------------------------------------------------
| Voice Messages
|--------------------------------------------------------------------------
*/

function formatVoiceRecordingTime(totalSeconds) {
  const total = Math.max(0, Number(totalSeconds || 0));
  const minutes = Math.floor(total / 60);
  const seconds = total % 60;

  return `${minutes}:${String(seconds).padStart(2, '0')}`;
}


function updateVoiceRecordingTimer() {
  if (!voiceRecorderTimer || !voiceRecordingStartedAt) return;

  const elapsed =
    Math.floor(
      (Date.now() - voiceRecordingStartedAt) / 1000
    );

  voiceRecorderTimer.textContent =
    formatVoiceRecordingTime(elapsed);
}


function showVoiceRecorderBar(show) {
  if (!voiceRecorderBar) return;

  voiceRecorderBar.classList.toggle('hidden', !show);
  voiceRecorderBar.classList.toggle('flex', show);
}


function stopVoiceRecorderStream() {
  if (voiceRecorderStream) {
    voiceRecorderStream
      .getTracks()
      .forEach((track) => track.stop());

    voiceRecorderStream = null;
  }
}


function resetVoiceRecorderUi() {
  if (voiceRecordingTimerId) {
    clearInterval(voiceRecordingTimerId);
    voiceRecordingTimerId = null;
  }

  voiceRecordingStartedAt = 0;

  if (voiceRecorderTimer) {
    voiceRecorderTimer.textContent = '0:00';
  }

  if (voiceRecorderStatus) {
    voiceRecorderStatus.textContent =
      'Recording voice message…';
  }

  showVoiceRecorderBar(false);
}


function chooseVoiceMimeType() {
  if (
    typeof MediaRecorder === 'undefined' ||
    typeof MediaRecorder.isTypeSupported !== 'function'
  ) {
    return '';
  }

  const preferred = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/mp4'
  ];

  return (
    preferred.find(
      (type) =>
        MediaRecorder.isTypeSupported(type)
    ) || ''
  );
}


async function startVoiceRecording() {
  if (
    voiceRecorder &&
    voiceRecorder.state === 'recording'
  ) {
    return;
  }

  if (
    !navigator.mediaDevices ||
    !navigator.mediaDevices.getUserMedia ||
    typeof MediaRecorder === 'undefined'
  ) {
    chatVoiceFallback?.click();
    return;
  }

  try {
    clearSelectedFiles();

    voiceRecordingCancelled = false;
    voiceRecorderChunks = [];

    voiceRecorderStream =
      await navigator.mediaDevices
        .getUserMedia({
          audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true
          },
          video: false
        });

    const mimeType =
      chooseVoiceMimeType();

    const options =
      mimeType
        ? { mimeType }
        : undefined;

    voiceRecorder =
      new MediaRecorder(
        voiceRecorderStream,
        options
      );

    voiceRecorder.addEventListener(
      'dataavailable',
      function (event) {
        if (
          event.data &&
          event.data.size > 0
        ) {
          voiceRecorderChunks.push(
            event.data
          );
        }
      }
    );

    voiceRecorder.addEventListener(
      'stop',
      function () {
        stopVoiceRecorderStream();

        if (voiceRecordingTimerId) {
          clearInterval(
            voiceRecordingTimerId
          );

          voiceRecordingTimerId = null;
        }

        const recorderMime =
          voiceRecorder?.mimeType ||
          mimeType ||
          'audio/webm';

        const chunks =
          voiceRecorderChunks.slice();

        voiceRecorderChunks = [];
        voiceRecorder = null;

        if (voiceRecordingCancelled) {
          voiceRecordingCancelled = false;
          resetVoiceRecorderUi();
          return;
        }

        const blob =
          new Blob(
            chunks,
            {
              type: recorderMime
            }
          );

        if (!blob.size) {
          resetVoiceRecorderUi();

          openNoticeModal(
            'Voice Message',
            'No audio was recorded. Please try again.'
          );

          return;
        }

        const simpleType =
          recorderMime
            .split(';')[0]
            .toLowerCase();

        let extension = 'webm';

        if (simpleType === 'audio/ogg') {
          extension = 'ogg';
        } else if (simpleType === 'audio/mp4') {
          extension = 'm4a';
        } else if (simpleType === 'audio/mpeg') {
          extension = 'mp3';
        }

        voiceRecordedFile =
          new File(
            [blob],
            `voice_${Date.now()}.${extension}`,
            {
              type: simpleType || 'audio/webm',
              lastModified: Date.now()
            }
          );

        clearOtherFileChoices(
          'voiceRecorded'
        );

        updateSelectedFileUI(
          voiceRecordedFile
        );

        resetVoiceRecorderUi();
      }
    );

    voiceRecorder.start(250);

    voiceRecordingStartedAt =
      Date.now();

    updateVoiceRecordingTimer();

    voiceRecordingTimerId =
      setInterval(
        updateVoiceRecordingTimer,
        500
      );

    showVoiceRecorderBar(true);

  } catch (error) {
    stopVoiceRecorderStream();
    resetVoiceRecorderUi();

    if (
      error?.name === 'NotAllowedError'
    ) {
      openNoticeModal(
        'Microphone Permission',
        'Microphone permission was denied. Please allow microphone access to record a voice message.'
      );
    } else {
      console.error(
        'Voice recording error:',
        error
      );

      /*
       * App/WebView fallback: let the device open
       * its native audio recorder/picker.
       */
      chatVoiceFallback?.click();
    }
  }
}


function stopVoiceRecording() {
  if (
    voiceRecorder &&
    voiceRecorder.state === 'recording'
  ) {
    voiceRecorder.stop();
  }
}


function cancelVoiceRecording() {
  voiceRecordingCancelled = true;

  if (
    voiceRecorder &&
    voiceRecorder.state === 'recording'
  ) {
    voiceRecorder.stop();
  } else {
    stopVoiceRecorderStream();
    resetVoiceRecorderUi();
  }
}


/*
|--------------------------------------------------------------------------
| Camera Helpers
|--------------------------------------------------------------------------
*/

function showCameraError(message) {

    if (!cameraError) {
        return;
    }

    cameraError.textContent =
        message || 'Unable to open camera.';

    cameraError.classList.remove(
        'hidden'
    );

    cameraRetryBtn
        ?.classList
        .remove('hidden');

    cameraRetryBtn
        ?.classList
        .add('inline-flex');
}


function hideCameraError() {

    if (cameraError) {

        cameraError.classList.add(
            'hidden'
        );

        cameraError.textContent = '';
    }

    cameraRetryBtn
        ?.classList
        .add('hidden');

    cameraRetryBtn
        ?.classList
        .remove('inline-flex');
}


function stopCamera() {

    if (cameraStream) {

        cameraStream
            .getTracks()
            .forEach(
                function (track) {
                    track.stop();
                }
            );

        cameraStream = null;
    }


    if (cameraVideo) {

        cameraVideo.srcObject =
            null;
    }


    if (cameraCaptureBtn) {

        cameraCaptureBtn.disabled =
            true;
    }
}


function closeCameraModal() {

    stopCamera();

    if (cameraModal) {

        cameraModal.classList.add(
            'hidden'
        );

        cameraModal.classList.remove(
            'flex'
        );
    }

    document.body.classList.remove(
        'overflow-hidden'
    );
}


/*
|--------------------------------------------------------------------------
| Detect Cameras
|--------------------------------------------------------------------------
*/

async function detectCameras() {

    if (
        !navigator.mediaDevices ||
        !navigator.mediaDevices.enumerateDevices
    ) {
        return [];
    }


    const devices =
        await navigator.mediaDevices
            .enumerateDevices();


    return devices.filter(
        function (device) {

            return (
                device.kind ===
                'videoinput'
            );
        }
    );
}


async function populateCameraList(
    selectedDeviceId = ''
) {

    if (!cameraDeviceSelect) {
        return;
    }


    const cameras =
        await detectCameras();


    cameraDeviceSelect.innerHTML = '';


    if (!cameras.length) {

        const option =
            document.createElement(
                'option'
            );

        option.value = '';

        option.textContent =
            'Default camera';

        cameraDeviceSelect
            .appendChild(option);

        return;
    }


    cameras.forEach(
        function (camera, index) {

            const option =
                document.createElement(
                    'option'
                );

            option.value =
                camera.deviceId;

            option.textContent =
                camera.label ||
                (
                    'Camera ' +
                    (index + 1)
                );


            if (
                selectedDeviceId &&
                selectedDeviceId ===
                    camera.deviceId
            ) {

                option.selected = true;
            }


            cameraDeviceSelect
                .appendChild(option);
        }
    );
}


/*
|--------------------------------------------------------------------------
| Start Selected Camera
|--------------------------------------------------------------------------
*/

async function startCamera(
    requestedDeviceId = ''
) {

    hideCameraError();

    stopCamera();


    if (cameraLoading) {

        cameraLoading.classList.remove(
            'hidden'
        );
    }


    if (
        !navigator.mediaDevices ||
        !navigator.mediaDevices.getUserMedia
    ) {

        if (cameraLoading) {
            cameraLoading.classList.add(
                'hidden'
            );
        }


        showCameraError(
            'Live camera is not supported by this browser. Use the Device Camera button instead.'
        );

        return;
    }


    try {

        let videoConstraints;


        if (requestedDeviceId) {

            videoConstraints = {

                deviceId: {
                    exact:
                        requestedDeviceId
                }
            };

        } else {

            /*
             * Prefer rear camera on phones.
             */
            videoConstraints = {

                facingMode: {
                    ideal:
                        'environment'
                }
            };
        }


        cameraStream =
            await navigator.mediaDevices
                .getUserMedia({
                    video:
                        videoConstraints,

                    audio:
                        false
                });


        if (cameraVideo) {

            cameraVideo.srcObject =
                cameraStream;

            await cameraVideo.play();
        }


        /*
         * After permission is granted,
         * browser usually exposes camera names.
         */
        const activeTrack =
            cameraStream
                .getVideoTracks()[0];

        const settings =
            activeTrack
                ?.getSettings?.() || {};

        await populateCameraList(
            settings.deviceId || ''
        );


        if (cameraLoading) {

            cameraLoading.classList.add(
                'hidden'
            );
        }


        if (cameraCaptureBtn) {

            cameraCaptureBtn.disabled =
                false;
        }


    } catch (error) {

        console.error(
            'Camera error:',
            error
        );


        if (cameraLoading) {

            cameraLoading.classList.add(
                'hidden'
            );
        }


        let message =
            'Unable to open the camera.';


        if (
            error.name ===
            'NotAllowedError'
        ) {

            message =
                'Camera permission was denied. Please allow camera access in your browser settings.';

        } else if (
            error.name ===
            'NotFoundError'
        ) {

            message =
                'No camera was detected on this device.';

        } else if (
            error.name ===
            'NotReadableError'
        ) {

            message =
                'The camera is currently being used by another application.';

        } else if (
            error.name ===
            'OverconstrainedError'
        ) {

            message =
                'The selected camera is not available.';
        }


        showCameraError(
            message
        );
    }
}


/*
|--------------------------------------------------------------------------
| Open Camera
|--------------------------------------------------------------------------
*/

async function openCameraModal() {

    if (!cameraModal) {
        return;
    }


    cameraModal.classList.remove(
        'hidden'
    );

    cameraModal.classList.add(
        'flex'
    );

    document.body.classList.add(
        'overflow-hidden'
    );


    await startCamera();
}


/*
|--------------------------------------------------------------------------
| Capture Photo
|--------------------------------------------------------------------------
*/

async function captureCameraPhoto() {

    if (
        !cameraVideo ||
        !cameraCanvas ||
        !cameraStream
    ) {
        return;
    }


    const width =
        cameraVideo.videoWidth;

    const height =
        cameraVideo.videoHeight;


    if (
        !width ||
        !height
    ) {

        showCameraError(
            'Camera image is not ready yet.'
        );

        return;
    }


    cameraCanvas.width =
        width;

    cameraCanvas.height =
        height;


    const context =
        cameraCanvas.getContext(
            '2d'
        );


    if (!context) {
        return;
    }


    context.drawImage(
        cameraVideo,
        0,
        0,
        width,
        height
    );


    cameraCanvas.toBlob(
        function (blob) {

            if (!blob) {

                showCameraError(
                    'Unable to capture photo.'
                );

                return;
            }


            const fileName =
                'camera_' +
                Date.now() +
                '.jpg';


            cameraCapturedFile =
                new File(
                    [
                        blob
                    ],
                    fileName,
                    {
                        type:
                            'image/jpeg',

                        lastModified:
                            Date.now()
                    }
                );


            /*
             * Clear other media choices so only the newly captured
             * photo is sent.
             */
            clearOtherFileChoices(
                'captured'
            );

            updateSelectedFileUI(
                cameraCapturedFile
            );


            closeCameraModal();

        },
        'image/jpeg',
        0.92
    );
}
/* =========================================================
   HOMEOWNER PRIVATE AUDIO / VIDEO CALLING (WebRTC)
   ========================================================= */
const CALL_RTC_CONFIG = {
  iceServers: [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' }
  ]
};

let incomingCallData = null;
let activeCall = null;
let callPeerConnection = null;
let callLocalStream = null;
let callRemoteStream = null;
let callLastSignalId = 0;
let callSignalPollTimer = null;
let incomingCallPollTimer = null;
let callDurationTimer = null;
let callConnectedAt = 0;
let queuedIceCandidates = [];
let callPollBusy = false;
let incomingPollBusy = false;
let endingCallLocally = false;

function callSupported() {
  return !!(
    window.RTCPeerConnection &&
    navigator.mediaDevices &&
    navigator.mediaDevices.getUserMedia
  );
}

function stopStream(stream) {
  stream?.getTracks?.().forEach((track) => track.stop());
}

function formatCallDuration(seconds) {
  const safe = Math.max(0, Number(seconds || 0));
  const minutes = Math.floor(safe / 60);
  const secs = safe % 60;
  return `${String(minutes).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;
}

function startCallTimer() {
  if (callConnectedAt) return;
  callConnectedAt = Date.now();
  if (callTimer) callTimer.textContent = '00:00';
  clearInterval(callDurationTimer);
  callDurationTimer = setInterval(() => {
    if (!callConnectedAt || !callTimer) return;
    callTimer.textContent = formatCallDuration(
      Math.floor((Date.now() - callConnectedAt) / 1000)
    );
  }, 1000);
}

function stopCallTimer() {
  clearInterval(callDurationTimer);
  callDurationTimer = null;
  callConnectedAt = 0;
  if (callTimer) callTimer.textContent = '00:00';
}

function setCallStatus(text) {
  if (callStatusText) callStatusText.textContent = text || '';
}

function updateHomeownerCallActions() {
  const visible =
    !isTenantAccount &&
    currentMode === 'homeowner' &&
    selectedHomeownerId > 0;

  if (homeownerCallActions) {
    homeownerCallActions.classList.toggle('hidden', !visible);
    homeownerCallActions.classList.toggle('inline-flex', visible);
  }

  const disabled =
    !visible ||
    !!activeCall ||
    !!incomingCallData;

  if (audioCallBtn) audioCallBtn.disabled = disabled;
  if (videoCallBtn) videoCallBtn.disabled = disabled;
}


function callEventDurationLabel(seconds) {
  const total = Math.max(0, Number(seconds || 0));

  if (!total) return '';

  const minutes = Math.floor(total / 60);
  const secs = total % 60;

  if (minutes <= 0) {
    return `${secs}s`;
  }

  return `${minutes}:${String(secs).padStart(2, '0')}`;
}

function createHomeownerCallEvent(call) {
  const row = document.createElement('div');

  const callId = Number(call.id || 0);
  const timestamp = Number(call.timestamp || 0);
  const direction = String(call.direction || '');
  const status = String(call.status || '');
  const callType = String(call.call_type || 'audio');
  const isVideo = callType === 'video';
  const incoming = direction === 'incoming';
  const missed = incoming && status === 'missed';
  const duration = callEventDurationLabel(call.duration_seconds);

  row.dataset.callId = String(callId);
  row.dataset.homeownerTimelineItem = '1';
  row.dataset.time = String(timestamp || 0);
  row.className = 'flex justify-center py-1.5';

  let iconClass = isVideo
    ? 'bi bi-camera-video-fill'
    : 'bi bi-telephone-fill';

  let title =
    `${incoming ? 'Incoming' : 'Outgoing'} ${isVideo ? 'video' : 'audio'} call`;

  let iconTone =
    'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300';

  let titleTone =
    'text-slate-600 dark:text-slate-300';

  if (missed) {
    iconClass = 'bi bi-telephone-x-fill';
    title = `Missed ${isVideo ? 'video' : 'audio'} call`;
    iconTone =
      'bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-300';
    titleTone =
      'text-red-600 dark:text-red-300';
  } else if (status === 'declined') {
    iconClass = 'bi bi-telephone-x-fill';
    title = incoming
      ? `Declined ${isVideo ? 'video' : 'audio'} call`
      : `${isVideo ? 'Video' : 'Audio'} call declined`;
    iconTone =
      'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300';
  } else if (status === 'ringing') {
    title = incoming
      ? `Incoming ${isVideo ? 'video' : 'audio'} call`
      : `Calling ${isVideo ? 'video' : 'audio'}…`;
    iconTone =
      'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300';
  } else if (status === 'answered') {
    title = `${incoming ? 'Incoming' : 'Outgoing'} ${isVideo ? 'video' : 'audio'} call`;
    iconTone =
      'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300';
  } else if (status === 'failed') {
    iconClass = 'bi bi-exclamation-triangle-fill';
    title = `${isVideo ? 'Video' : 'Audio'} call failed`;
    iconTone =
      'bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-300';
    titleTone =
      'text-red-600 dark:text-red-300';
  }

  let detail = '';

  if (status === 'ended' && duration) {
    detail = duration;
  } else if (status === 'missed') {
    detail = 'No answer';
  } else if (status === 'declined') {
    detail = 'Declined';
  } else if (status === 'ringing') {
    detail = 'Ringing';
  } else if (status === 'answered') {
    detail = 'In progress';
  } else if (status === 'failed') {
    detail = 'Failed';
  }

  row.innerHTML = `
    <div class="group flex max-w-[92%] items-center gap-2 rounded-2xl bg-slate-100 px-3 py-2 text-xs shadow-sm ring-1 ring-slate-200/70 sm:max-w-[78%] dark:bg-slate-800 dark:ring-slate-700">
      <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${iconTone}">
        <i class="${iconClass}"></i>
      </div>

      <div class="min-w-0">
        <div class="font-bold ${titleTone}">
          ${escapeHtml(title)}
        </div>

        <div class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-[11px] text-slate-400 dark:text-slate-500">
          <span>${escapeHtml(call.started_at_label || '')}</span>
          ${detail ? `<span>•</span><span>${escapeHtml(detail)}</span>` : ''}
        </div>
      </div>

      ${
        ['ended','missed','declined','failed'].includes(status)
          ? `
            <button
              type="button"
              class="homeowner-call-back ml-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-violet-600 transition hover:bg-violet-100 dark:text-violet-300 dark:hover:bg-violet-950/60"
              data-call-type="${isVideo ? 'video' : 'audio'}"
              title="Call back"
              aria-label="Call back"
            >
              <i class="${isVideo ? 'bi bi-camera-video-fill' : 'bi bi-telephone-fill'}"></i>
            </button>
          `
          : ''
      }
    </div>
  `;

  return row;
}

function sortHomeownerTimeline() {
  if (!chatBody || currentMode !== 'homeowner') return;

  const items = Array.from(
    chatBody.querySelectorAll(
      ':scope > [data-homeowner-timeline-item="1"]'
    )
  );

  items
    .sort(
      (a, b) =>
        Number(a.dataset.time || 0) -
        Number(b.dataset.time || 0)
    )
    .forEach((item) => chatBody.appendChild(item));
}

function upsertHomeownerCallEvent(call) {
  if (!chatBody) return;

  const callId = Number(call.id || 0);

  if (!callId) return;

  const existing =
    chatBody.querySelector(
      `[data-call-id="${CSS.escape(String(callId))}"]`
    );

  const eventRow =
    createHomeownerCallEvent(call);

  if (existing) {
    existing.replaceWith(eventRow);
  } else {
    document.getElementById('chatEmpty')?.remove();
    chatBody.appendChild(eventRow);
  }
}

function configureCallStage(callType, peerName) {
  const isVideo = callType === 'video';

  if (callPeerName) callPeerName.textContent = peerName || 'Homeowner';
  if (audioCallPeerName) audioCallPeerName.textContent = peerName || 'Homeowner';
  if (callTypeBadge) callTypeBadge.textContent = isVideo ? 'Video Call' : 'Audio Call';

  audioCallStage?.classList.toggle('hidden', isVideo);
  remoteCallVideo?.classList.toggle('hidden', !isVideo);
  localVideoWrap?.classList.toggle('hidden', !isVideo);

  if (callCameraBtn) {
    callCameraBtn.classList.toggle('hidden', !isVideo);
    callCameraBtn.classList.toggle('inline-flex', isVideo);
  }
}

function resetCallControlIcons() {
  if (callMuteBtn) {
    callMuteBtn.innerHTML = '<i class="bi bi-mic-fill"></i><span class="hidden sm:inline">Mute</span>';
    callMuteBtn.title = 'Mute microphone';
  }
  if (callCameraBtn) {
    callCameraBtn.innerHTML = '<i class="bi bi-camera-video-fill"></i><span class="hidden sm:inline">Camera</span>';
    callCameraBtn.title = 'Turn camera off';
  }
}

async function sendCallSignal(type, payload) {
  if (!activeCall?.id) return;

  const result = await postJSON('homeowner_call_signal', {
    call_id: activeCall.id,
    signal_type: type,
    payload: JSON.stringify(payload)
  });

  if (!result.success) {
    throw new Error(result.message || 'Unable to send call signal.');
  }
}

async function flushQueuedIceCandidates() {
  if (!callPeerConnection?.remoteDescription) return;

  const queue = queuedIceCandidates;
  queuedIceCandidates = [];

  for (const candidate of queue) {
    try {
      await callPeerConnection.addIceCandidate(new RTCIceCandidate(candidate));
    } catch (error) {
      console.warn('Queued ICE candidate failed:', error);
    }
  }
}

function createCallPeerConnection() {
  if (callPeerConnection) {
    try { callPeerConnection.close(); } catch (e) {}
  }

  callPeerConnection = new RTCPeerConnection(CALL_RTC_CONFIG);
  callRemoteStream = new MediaStream();
  queuedIceCandidates = [];

  callLocalStream?.getTracks().forEach((track) => {
    callPeerConnection.addTrack(track, callLocalStream);
  });

  callPeerConnection.onicecandidate = async (event) => {
    if (!event.candidate || !activeCall?.id) return;
    try {
      await sendCallSignal('ice', event.candidate.toJSON());
    } catch (error) {
      console.warn('ICE signal failed:', error);
    }
  };

  callPeerConnection.ontrack = (event) => {
    const stream = event.streams?.[0];
    if (stream) {
      callRemoteStream = stream;
    } else if (event.track) {
      callRemoteStream.addTrack(event.track);
    }

    if (activeCall?.call_type === 'video') {
      if (remoteCallVideo && remoteCallVideo.srcObject !== callRemoteStream) {
        remoteCallVideo.srcObject = callRemoteStream;
        remoteCallVideo.play().catch(() => {});
      }
    } else {
      if (remoteCallAudio && remoteCallAudio.srcObject !== callRemoteStream) {
        remoteCallAudio.srcObject = callRemoteStream;
        remoteCallAudio.play().catch(() => {});
      }
    }
  };

  callPeerConnection.onconnectionstatechange = () => {
    const state = callPeerConnection?.connectionState || '';
    if (state === 'connected') {
      setCallStatus('Connected');
      startCallTimer();
    } else if (state === 'connecting') {
      setCallStatus('Connecting...');
    } else if (state === 'failed') {
      setCallStatus('Connection failed');
    } else if (state === 'disconnected') {
      setCallStatus('Reconnecting...');
    }
  };
}

async function getCallMedia(callType) {
  const constraints = {
    audio: true,
    video: callType === 'video'
      ? { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } }
      : false
  };

  return await navigator.mediaDevices.getUserMedia(constraints);
}

function showActiveCallModal() {
  configureCallStage(activeCall?.call_type || 'audio', activeCall?.peer?.name || 'Homeowner');
  resetCallControlIcons();

  if (localCallVideo) {
    localCallVideo.srcObject = activeCall?.call_type === 'video' ? callLocalStream : null;
    if (activeCall?.call_type === 'video') {
      localCallVideo.play().catch(() => {});
    }
  }

  setModalOpen(callModal, true);
  updateHomeownerCallActions();
}

function hideIncomingCallModal() {
  setModalOpen(incomingCallModal, false);
  incomingCallData = null;
  updateHomeownerCallActions();
}

function showIncomingCall(call) {
  incomingCallData = call;
  const video = call?.call_type === 'video';

  if (incomingCallIcon) {
    incomingCallIcon.className = video
      ? 'bi bi-camera-video-fill'
      : 'bi bi-telephone-fill';
  }
  if (incomingCallKind) {
    incomingCallKind.textContent = video ? 'Incoming video call' : 'Incoming audio call';
  }
  if (incomingCallerName) incomingCallerName.textContent = call?.peer?.name || 'Homeowner';
  if (incomingCallerLot) incomingCallerLot.textContent = call?.peer?.lot || phase;

  setModalOpen(incomingCallModal, true);
  updateHomeownerCallActions();

  try {
    navigator.vibrate?.([180, 100, 180, 100, 260]);
  } catch (e) {}
}

function stopCallPolling() {
  clearInterval(callSignalPollTimer);
  callSignalPollTimer = null;
}

function cleanupActiveCallUi() {
  stopCallPolling();
  stopCallTimer();
  stopStream(callLocalStream);
  callLocalStream = null;

  if (callPeerConnection) {
    try {
      callPeerConnection.onicecandidate = null;
      callPeerConnection.ontrack = null;
      callPeerConnection.onconnectionstatechange = null;
      callPeerConnection.close();
    } catch (e) {}
  }

  callPeerConnection = null;
  callRemoteStream = null;
  queuedIceCandidates = [];
  callLastSignalId = 0;

  if (remoteCallVideo) remoteCallVideo.srcObject = null;
  if (remoteCallAudio) remoteCallAudio.srcObject = null;
  if (localCallVideo) localCallVideo.srcObject = null;

  setModalOpen(callModal, false);
  activeCall = null;
  endingCallLocally = false;
  updateHomeownerCallActions();
}

async function finishActiveCall(showMessage = '', notifyServer = false) {
  const callId = activeCall?.id || 0;

  if (notifyServer && callId) {
    try {
      await postJSON('homeowner_call_end', { call_id: callId });
    } catch (error) {
      console.warn('Unable to notify call end:', error);
    }
  }

  cleanupActiveCallUi();

  if (currentMode === 'homeowner') {
    loadHomeownerMessages(true);
  }

  if (showMessage) {
    openNoticeModal('Call Ended', showMessage);
  }
}

async function processCallSignal(signal) {
  if (!signal?.type || !signal?.payload || !callPeerConnection) return;

  let payload;
  try {
    payload = JSON.parse(signal.payload);
  } catch (error) {
    return;
  }

  if (signal.type === 'offer') {
    if (activeCall?.role !== 'receiver' || callPeerConnection.remoteDescription) return;

    await callPeerConnection.setRemoteDescription(new RTCSessionDescription(payload));
    await flushQueuedIceCandidates();

    const answer = await callPeerConnection.createAnswer();
    await callPeerConnection.setLocalDescription(answer);
    await sendCallSignal('answer', callPeerConnection.localDescription);
    setCallStatus('Connecting...');
    return;
  }

  if (signal.type === 'answer') {
    if (activeCall?.role !== 'caller' || callPeerConnection.remoteDescription) return;

    await callPeerConnection.setRemoteDescription(new RTCSessionDescription(payload));
    await flushQueuedIceCandidates();
    setCallStatus('Connecting...');
    return;
  }

  if (signal.type === 'ice') {
    if (callPeerConnection.remoteDescription) {
      try {
        await callPeerConnection.addIceCandidate(new RTCIceCandidate(payload));
      } catch (error) {
        console.warn('ICE candidate failed:', error);
      }
    } else {
      queuedIceCandidates.push(payload);
    }
  }
}

async function pollActiveCall() {
  if (!activeCall?.id || callPollBusy) return;
  callPollBusy = true;

  try {
    const result = await postJSON('homeowner_call_poll', {
      call_id: activeCall.id,
      last_signal_id: callLastSignalId
    });

    if (!result.success) return;

    if (Number(result.last_signal_id || 0) > callLastSignalId) {
      callLastSignalId = Number(result.last_signal_id || 0);
    }

    const status = String(result.call?.status || '');

    if (status === 'answered') {
      if (!callConnectedAt && activeCall.role === 'receiver') {
        setCallStatus('Connecting...');
      }
    } else if (status === 'declined') {
      await finishActiveCall('The homeowner declined your call.');
      return;
    } else if (status === 'missed') {
      await finishActiveCall('The homeowner did not answer the call.');
      return;
    } else if (status === 'ended') {
      const message = endingCallLocally ? '' : 'The other homeowner ended the call.';
      await finishActiveCall(message);
      return;
    }

    for (const signal of (result.signals || [])) {
      await processCallSignal(signal);
    }
  } catch (error) {
    console.warn('Call polling error:', error);
  } finally {
    callPollBusy = false;
  }
}

function startActiveCallPolling() {
  stopCallPolling();
  pollActiveCall();
  callSignalPollTimer = setInterval(pollActiveCall, 1000);
}

async function startHomeownerCall(callType) {
  if (isTenantAccount || currentMode !== 'homeowner' || !selectedHomeownerId) {
    openNoticeModal('Homeowner Call', 'Select a homeowner in Homeowner Chat first.');
    return;
  }

  if (activeCall || incomingCallData) {
    openNoticeModal('Homeowner Call', 'Finish the current call first.');
    return;
  }

  if (!callSupported()) {
    openNoticeModal(
      'Calling Not Supported',
      'This browser cannot start WebRTC calls. Use a modern browser and HTTPS (or localhost) with microphone/camera permission.'
    );
    return;
  }

  const option = homeownerSelect?.selectedOptions?.[0] || null;
  const peerName = option?.dataset.name || 'Homeowner';
  const peerLot = option?.dataset.lot || '';

  audioCallBtn && (audioCallBtn.disabled = true);
  videoCallBtn && (videoCallBtn.disabled = true);

  try {
    callLocalStream = await getCallMedia(callType);

    const result = await postJSON('homeowner_call_start', {
      homeowner_id: selectedHomeownerId,
      call_type: callType
    });

    if (!result.success) {
      throw new Error(result.message || 'Unable to start the call.');
    }

    activeCall = {
      id: Number(result.call.id),
      call_type: callType,
      role: 'caller',
      peer: result.call.peer || { id:selectedHomeownerId, name:peerName, lot:peerLot }
    };

    callLastSignalId = 0;
    createCallPeerConnection();
    showActiveCallModal();
    setCallStatus('Calling...');

    const offer = await callPeerConnection.createOffer();
    await callPeerConnection.setLocalDescription(offer);
    await sendCallSignal('offer', callPeerConnection.localDescription);

    startActiveCallPolling();
  } catch (error) {
    stopStream(callLocalStream);
    callLocalStream = null;
    activeCall = null;
    updateHomeownerCallActions();

    const permissionDenied = error?.name === 'NotAllowedError';
    openNoticeModal(
      permissionDenied ? 'Permission Required' : 'Unable to Start Call',
      permissionDenied
        ? 'Please allow microphone access' + (callType === 'video' ? ' and camera access' : '') + ' in your browser, then try again.'
        : (error.message || 'Unable to start the call.')
    );
  }
}

async function acceptIncomingHomeownerCall() {
  if (!incomingCallData || activeCall) return;

  if (!callSupported()) {
    openNoticeModal('Calling Not Supported', 'This browser cannot receive WebRTC calls.');
    return;
  }

  answerCallBtn && (answerCallBtn.disabled = true);
  declineCallBtn && (declineCallBtn.disabled = true);

  const call = incomingCallData;

  try {
    callLocalStream = await getCallMedia(call.call_type);

    const result = await postJSON('homeowner_call_accept', { call_id: call.id });
    if (!result.success) {
      throw new Error(result.message || 'This call is no longer available.');
    }

    setModalOpen(incomingCallModal, false);
    incomingCallData = null;

    activeCall = {
      id: Number(call.id),
      call_type: call.call_type,
      role: 'receiver',
      peer: call.peer || { name:'Homeowner' }
    };

    callLastSignalId = 0;
    createCallPeerConnection();
    showActiveCallModal();
    setCallStatus('Connecting...');
    startActiveCallPolling();
  } catch (error) {
    stopStream(callLocalStream);
    callLocalStream = null;

    const permissionDenied = error?.name === 'NotAllowedError';
    openNoticeModal(
      permissionDenied ? 'Permission Required' : 'Unable to Answer Call',
      permissionDenied
        ? 'Please allow microphone access' + (call.call_type === 'video' ? ' and camera access' : '') + ' to answer this call.'
        : (error.message || 'Unable to answer the call.')
    );
  } finally {
    if (answerCallBtn) answerCallBtn.disabled = false;
    if (declineCallBtn) declineCallBtn.disabled = false;
  }
}

async function declineIncomingHomeownerCall() {
  if (!incomingCallData?.id) return;
  const callId = incomingCallData.id;

  try {
    await postJSON('homeowner_call_decline', { call_id: callId });
  } catch (error) {
    console.warn('Unable to decline call:', error);
  }

  hideIncomingCallModal();

  if (currentMode === 'homeowner') {
    loadHomeownerMessages(true);
  }
}

async function endCurrentHomeownerCall() {
  if (!activeCall?.id) return;
  endingCallLocally = true;
  setCallStatus('Ending call...');
  await finishActiveCall('', true);
}

async function pollIncomingHomeownerCall() {
  if (isTenantAccount || activeCall || incomingCallData || incomingPollBusy) return;
  incomingPollBusy = true;

  try {
    const result = await postJSON('homeowner_call_incoming');
    if (result.success && result.call) {
      showIncomingCall(result.call);
    }
  } catch (error) {
    // Keep the communication page usable even if call polling fails.
  } finally {
    incomingPollBusy = false;
  }
}

function startIncomingCallPolling() {
  if (isTenantAccount) return;
  clearInterval(incomingCallPollTimer);
  pollIncomingHomeownerCall();
  incomingCallPollTimer = setInterval(pollIncomingHomeownerCall, 2000);
}

audioCallBtn?.addEventListener('click', () => startHomeownerCall('audio'));
videoCallBtn?.addEventListener('click', () => startHomeownerCall('video'));
answerCallBtn?.addEventListener('click', acceptIncomingHomeownerCall);
declineCallBtn?.addEventListener('click', declineIncomingHomeownerCall);
endCallBtn?.addEventListener('click', endCurrentHomeownerCall);

callMuteBtn?.addEventListener('click', function () {
  const track = callLocalStream?.getAudioTracks?.()[0];
  if (!track) return;

  track.enabled = !track.enabled;
  const muted = !track.enabled;
  this.innerHTML = muted
    ? '<i class="bi bi-mic-mute-fill"></i><span class="hidden sm:inline">Unmute</span>'
    : '<i class="bi bi-mic-fill"></i><span class="hidden sm:inline">Mute</span>';
  this.title = muted ? 'Unmute microphone' : 'Mute microphone';
});

callCameraBtn?.addEventListener('click', function () {
  const track = callLocalStream?.getVideoTracks?.()[0];
  if (!track) return;

  track.enabled = !track.enabled;
  const off = !track.enabled;
  this.innerHTML = off
    ? '<i class="bi bi-camera-video-off-fill"></i><span class="hidden sm:inline">Camera Off</span>'
    : '<i class="bi bi-camera-video-fill"></i><span class="hidden sm:inline">Camera</span>';
  this.title = off ? 'Turn camera on' : 'Turn camera off';
});

function setModeButtons() {
  const inactive = ['text-slate-600','dark:text-slate-300','dark:hover:bg-slate-700'];

  modePublicBtn?.classList.remove('bg-emerald-700','text-white','shadow-sm',...inactive);
  modeHomeownerBtn?.classList.remove('bg-violet-700','text-white','shadow-sm',...inactive);
  modeOfficerBtn?.classList.remove('bg-blue-700','text-white','shadow-sm',...inactive);

  if (currentMode === 'public') {
    modePublicBtn?.classList.add('bg-emerald-700','text-white','shadow-sm');
    modeHomeownerBtn?.classList.add(...inactive);
    modeOfficerBtn?.classList.add(...inactive);
  } else if (currentMode === 'homeowner') {
    modeHomeownerBtn?.classList.add('bg-violet-700','text-white','shadow-sm');
    modePublicBtn?.classList.add(...inactive);
    modeOfficerBtn?.classList.add(...inactive);
  } else {
    modeOfficerBtn?.classList.add('bg-blue-700','text-white','shadow-sm');
    modePublicBtn?.classList.add(...inactive);
    modeHomeownerBtn?.classList.add(...inactive);
  }
}

function updateMessageCounter() {
  if (!chatMessage || !messageCount) return;

  const max = Number(chatMessage.maxLength || 0);
  messageCount.textContent = `${chatMessage.value.length} / ${max}`;
}


function updateComposerState() {
  if (!chatMessage || !sendBtn) return;
  setModeButtons();
  updateHomeownerCallActions();

  sendBtn.classList.remove('bg-emerald-700','hover:bg-emerald-800','dark:bg-emerald-600','dark:hover:bg-emerald-500','bg-violet-700','hover:bg-violet-800','dark:bg-violet-600','dark:hover:bg-violet-500','bg-blue-700','hover:bg-blue-800','dark:bg-blue-600','dark:hover:bg-blue-500');

  if (currentMode === 'public') {
    chatRoomTitle.textContent = 'Public Community Chat';
    chatRoomSub.textContent = `Talk with other homeowners in ${phase}`;
    chatTip.textContent = `Max 500 characters. Everyone in ${phase} can see your message. Inappropriate or harmful language is blocked. You can edit or delete your own message for 15 minutes. Photos/files/voice messages up to 10MB and videos up to 25MB are allowed.`;
    chatMessage.maxLength = 500;
    sendBtn.classList.add('bg-emerald-700','hover:bg-emerald-800','dark:bg-emerald-600','dark:hover:bg-emerald-500');

    if (isPublicMuted) {
      chatMessage.placeholder = 'You are muted from sending public messages.';
      chatMessage.disabled = true; sendBtn.disabled = true;
      if (photoBtn) photoBtn.disabled = true;
      if (videoBtn) videoBtn.disabled = true;
      if (cameraBtn) cameraBtn.disabled = true;
      if (voiceMessageBtn) voiceMessageBtn.disabled = true;
      if (attachBtn) attachBtn.disabled = true;
    } else {
      chatMessage.placeholder = 'Type your public message here...';
      chatMessage.disabled = false; sendBtn.disabled = false;
      if (photoBtn) photoBtn.disabled = false;
      if (videoBtn) videoBtn.disabled = false;
      if (cameraBtn) cameraBtn.disabled = false;
      if (voiceMessageBtn) voiceMessageBtn.disabled = false;
      if (attachBtn) attachBtn.disabled = false;
    }
  } else if (currentMode === 'homeowner') {
    const option = homeownerSelect?.selectedOptions?.[0] || null;
    const homeownerName = option?.dataset.name || 'Homeowner';
    const homeownerLot = option?.dataset.lot || '';
    chatRoomTitle.textContent = 'Homeowner Private Chat';
    chatRoomSub.textContent = homeownerLot ? `Private conversation with ${homeownerName} — ${homeownerLot}` : `Private conversation with ${homeownerName}`;
    chatTip.textContent = 'Max 1000 characters. Only you and the selected homeowner can see this conversation. You can edit or delete your own message for 15 minutes. Inappropriate or harmful language is blocked. Photos/files/voice messages up to 10MB and videos up to 25MB are allowed.';
    chatMessage.maxLength = 1000;
    sendBtn.classList.add('bg-violet-700','hover:bg-violet-800','dark:bg-violet-600','dark:hover:bg-violet-500');

    if (!selectedHomeownerId || isTenantAccount) {
      chatMessage.placeholder = 'No homeowner is available.';
      chatMessage.disabled = true; sendBtn.disabled = true;
      if (photoBtn) photoBtn.disabled = true;
      if (videoBtn) videoBtn.disabled = true;
      if (cameraBtn) cameraBtn.disabled = true;
      if (voiceMessageBtn) voiceMessageBtn.disabled = true;
      if (attachBtn) attachBtn.disabled = true;
    } else {
      chatMessage.placeholder = 'Type your private message to the homeowner...';
      chatMessage.disabled = false; sendBtn.disabled = false;
      if (photoBtn) photoBtn.disabled = false;
      if (videoBtn) videoBtn.disabled = false;
      if (cameraBtn) cameraBtn.disabled = false;
      if (voiceMessageBtn) voiceMessageBtn.disabled = false;
      if (attachBtn) attachBtn.disabled = false;
    }
  } else {
    const option = officerSelect?.selectedOptions?.[0] || null;
    const officerPosition = option?.dataset.position || 'Officer';
    const officerName = option?.dataset.name || officerPosition;
    chatRoomTitle.textContent = 'Officer Private Chat';
    chatRoomSub.textContent = `Private conversation with ${officerPosition} — ${officerName}`;
    chatTip.textContent = 'Max 1000 characters. Only you and the selected officer can see this conversation. You can edit or delete your own message for 15 minutes. Inappropriate or harmful language is blocked. Photos/files/voice messages up to 10MB and videos up to 25MB are allowed.';
    chatMessage.maxLength = 1000;
    sendBtn.classList.add('bg-blue-700','hover:bg-blue-800','dark:bg-blue-600','dark:hover:bg-blue-500');

    if (!selectedOfficerId) {
      chatMessage.placeholder = 'No officer is available.';
      chatMessage.disabled = true; sendBtn.disabled = true;
      if (photoBtn) photoBtn.disabled = true;
      if (videoBtn) videoBtn.disabled = true;
      if (cameraBtn) cameraBtn.disabled = true;
      if (voiceMessageBtn) voiceMessageBtn.disabled = true;
      if (attachBtn) attachBtn.disabled = true;
    } else {
      chatMessage.placeholder = 'Type your private message to the officer...';
      chatMessage.disabled = false; sendBtn.disabled = false;
      if (photoBtn) photoBtn.disabled = false;
      if (videoBtn) videoBtn.disabled = false;
      if (cameraBtn) cameraBtn.disabled = false;
      if (voiceMessageBtn) voiceMessageBtn.disabled = false;
      if (attachBtn) attachBtn.disabled = false;
    }
  }

  updateMessageCounter();
}

async function loadPublicMessages(initial = false) {
  if (!chatBody || isFetching) return;

  isFetching = true;

  const shouldStickBottom = isNearBottom(chatBody) || initial;

  try {
    const result = await postJSON('fetch_messages', {
      last_id: initial ? 0 : publicLastId
    });

    if (!result.success) {
      if (initial) {
        chatBody.innerHTML = publicMuteCard() + emptyState(result.message || 'Unable to load messages.');
      }
      return;
    }

    const messages = Array.isArray(result.messages)
      ? result.messages
      : [];

    if (initial) {
      chatBody.innerHTML = publicMuteCard();
      publicLastId = 0;
    }

    if (messages.length > 0) {
      document.getElementById('chatEmpty')?.remove();

      messages.forEach((message) => {
        if (!chatBody.querySelector(`[data-id="${CSS.escape(String(message.id))}"]`)) {
          chatBody.appendChild(createPublicMessage(message));
        }

        if (Number(message.id) > publicLastId) {
          publicLastId = Number(message.id);
        }
      });

      if (shouldStickBottom) {
        scrollToBottom();
      }
    } else if (initial) {
      chatBody.insertAdjacentHTML(
        'beforeend',
        emptyState(`Be the first to start the conversation in ${phase}.`)
      );
    }
  } catch (error) {
    console.error(error);

    if (initial) {
      chatBody.innerHTML = publicMuteCard() + emptyState('Unable to load messages right now.');
    }
  } finally {
    isFetching = false;
  }
}



async function loadHomeownerMessages(initial = false) {
  if (!chatBody) return;

  if (!selectedHomeownerId) {
    chatBody.innerHTML =
      emptyState(
        'No homeowner is available in your phase.'
      );
    return;
  }

  if (isFetching) return;

  isFetching = true;

  const shouldStickBottom =
    isNearBottom(chatBody) ||
    initial;

  try {
    const result =
      await postJSON(
        'fetch_homeowner_messages',
        {
          homeowner_id:
            selectedHomeownerId,

          last_id:
            initial
              ? 0
              : homeownerLastId
        }
      );

    if (!result.success) {
      if (initial) {
        chatBody.innerHTML =
          emptyState(
            result.message ||
            'Unable to load homeowner conversation.'
          );
      }

      return;
    }

    const messages =
      Array.isArray(result.messages)
        ? result.messages
        : [];

    const calls =
      Array.isArray(result.calls)
        ? result.calls
        : [];

    if (initial) {
      chatBody.innerHTML = '';
      homeownerLastId = 0;
    }

    messages.forEach(
      (message) => {
        const messageId =
          Number(message.id || 0);

        if (
          messageId &&
          !chatBody.querySelector(
            `[data-id="${CSS.escape(String(messageId))}"]`
          )
        ) {
          chatBody.appendChild(
            createHomeownerMessage(message)
          );
        }

        if (
          messageId >
          homeownerLastId
        ) {
          homeownerLastId =
            messageId;
        }
      }
    );

    calls.forEach(
      (call) => {
        upsertHomeownerCallEvent(call);
      }
    );

    sortHomeownerTimeline();

    const hasTimelineItems =
      !!chatBody.querySelector(
        '[data-homeowner-timeline-item="1"]'
      );

    if (!hasTimelineItems && initial) {
      const option =
        homeownerSelect
          ?.selectedOptions?.[0]
        || null;

      chatBody.innerHTML =
        emptyState(
          `Start a private conversation with ${
            option?.dataset.name ||
            'this homeowner'
          }.`
        );
    } else {
      document
        .getElementById('chatEmpty')
        ?.remove();
    }

    if (
      shouldStickBottom &&
      hasTimelineItems
    ) {
      scrollToBottom();
    }

  } catch (error) {
    console.error(error);

    if (initial) {
      chatBody.innerHTML =
        emptyState(
          'Unable to load homeowner conversation right now.'
        );
    }

  } finally {
    isFetching = false;
  }
}

async function loadOfficerMessages(initial = false) {
  if (!chatBody) return;

  if (!selectedOfficerId) {
    chatBody.innerHTML = emptyState('No officer is available for your phase.');
    return;
  }

  if (isFetching) return;

  isFetching = true;

  const shouldStickBottom = isNearBottom(chatBody) || initial;

  try {
    const result = await postJSON('fetch_officer_messages', {
      admin_id: selectedOfficerId,
      last_id: initial ? 0 : officerLastId
    });

    if (!result.success) {
      if (initial) {
        chatBody.innerHTML = emptyState(result.message || 'Unable to load officer conversation.');
      }
      return;
    }

    const messages = Array.isArray(result.messages)
      ? result.messages
      : [];

    if (initial) {
      chatBody.innerHTML = '';
      officerLastId = 0;
    }

    if (messages.length > 0) {
      document.getElementById('chatEmpty')?.remove();

      messages.forEach((message) => {
        if (!chatBody.querySelector(`[data-id="${CSS.escape(String(message.id))}"]`)) {
          chatBody.appendChild(createOfficerMessage(message));
        }

        if (Number(message.id) > officerLastId) {
          officerLastId = Number(message.id);
        }
      });

      if (shouldStickBottom) {
        scrollToBottom();
      }
    } else if (initial) {
      const selectedOption =
        officerSelect?.selectedOptions?.[0]
        || null;

      const officerPosition =
        selectedOption?.dataset.position
        || 'officer';

      const officerName =
        selectedOption?.dataset.name
        || officerPosition;

      chatBody.innerHTML =
        emptyState(
          `Start a private conversation with ${officerPosition} — ${officerName}.`
        );
    }
  } catch (error) {
    console.error(error);

    if (initial) {
      chatBody.innerHTML = emptyState('Unable to load officer conversation right now.');
    }
  } finally {
    isFetching = false;
  }
}


async function switchMode(mode) {
  if (
    voiceRecorder &&
    voiceRecorder.state === 'recording'
  ) {
    cancelVoiceRecording();
  }

  currentMode = mode;
  clearSelectedFiles();
  updateComposerState();

  if (mode === 'public') {
    await loadPublicMessages(true);
  } else if (mode === 'homeowner') {
    await loadHomeownerMessages(true);
  } else {
    await loadOfficerMessages(true);
  }
}


modePublicBtn?.addEventListener('click', () => {
  switchMode('public');
});


modeHomeownerBtn?.addEventListener('click', () => {
  switchMode('homeowner');
});

modeOfficerBtn?.addEventListener('click', () => {
  switchMode('officer');
});


officerSelect?.addEventListener('change', async function () {
  selectedOfficerId = Number(this.value || 0);
  officerLastId = 0;

  if (currentMode === 'officer') {
    updateComposerState();
    await loadOfficerMessages(true);
  }
});

homeownerSelect?.addEventListener('change', async function () {
  selectedHomeownerId = Number(this.value || 0);
  homeownerLastId = 0;
  updateHomeownerCallActions();

  if (currentMode === 'homeowner') {
    updateComposerState();
    await loadHomeownerMessages(true);
  }
});


photoBtn?.addEventListener('click', () => {
  if (!photoBtn.disabled) {
    chatPhoto?.click();
  }
});


videoBtn?.addEventListener('click', () => {
  if (!videoBtn.disabled) {
    chatVideo?.click();
  }
});


voiceMessageBtn?.addEventListener(
  'click',
  async function () {
    if (voiceMessageBtn.disabled) {
      return;
    }

    await startVoiceRecording();
  }
);


stopVoiceRecordingBtn?.addEventListener(
  'click',
  stopVoiceRecording
);


cancelVoiceRecordingBtn?.addEventListener(
  'click',
  cancelVoiceRecording
);


attachBtn?.addEventListener('click', () => {
  if (!attachBtn.disabled) {
    chatAttachment?.click();
  }
});


cameraBtn?.addEventListener(
    'click',
    async function () {

        if (cameraBtn.disabled) {
            return;
        }

        await openCameraModal();
    }
);
/*
|--------------------------------------------------------------------------
| Camera Events
|--------------------------------------------------------------------------
*/

cameraCloseBtn?.addEventListener(
    'click',
    closeCameraModal
);


cameraCaptureBtn?.addEventListener(
    'click',
    captureCameraPhoto
);


cameraRetryBtn?.addEventListener(
    'click',
    async function () {

        const deviceId =
            cameraDeviceSelect?.value || '';

        await startCamera(
            deviceId
        );
    }
);


cameraDeviceSelect?.addEventListener(
    'change',
    async function () {

        if (!this.value) {
            return;
        }

        await startCamera(
            this.value
        );
    }
);


cameraFallbackBtn?.addEventListener(
    'click',
    function () {

        /*
         * Close the live-camera interface first.
         */
        closeCameraModal();

        /*
         * Then allow the device/browser to launch
         * its native camera capture interface.
         */
        setTimeout(
            function () {
                chatCamera?.click();
            },
            100
        );
    }
);


cameraModal?.addEventListener(
    'click',
    function (event) {

        if (
            event.target ===
            cameraModal
        ) {

            closeCameraModal();
        }
    }
);


/*
 * Stop webcam when user leaves the page.
 */
window.addEventListener(
    'beforeunload',
    function () {
        if (
          voiceRecorder &&
          voiceRecorder.state === 'recording'
        ) {
          voiceRecordingCancelled = true;

          try {
            voiceRecorder.stop();
          } catch (error) {}
        }

        stopVoiceRecorderStream();
        stopCamera();
        releaseSelectedMediaObjectUrl();
    }
);

window.addEventListener('pagehide', function () {
  if (!activeCall?.id) return;

  try {
    const fd = new FormData();
    fd.append('action', 'homeowner_call_end');
    fd.append('csrf', CSRF_TOKEN);
    fd.append('call_id', String(activeCall.id));
    navigator.sendBeacon?.('homeowner_public_chat.php', fd);
  } catch (e) {}

  stopStream(callLocalStream);
});

function handleChatMediaSelection(input, sourceKey) {
  const file =
    input?.files?.[0] ||
    null;

  if (
    file &&
    !validateSelectedFile(file)
  ) {
    return;
  }

  if (file) {
    clearOtherFileChoices(sourceKey);
  }

  updateSelectedFileUI(
    file ||
    getSelectedFile()
  );
}


chatPhoto?.addEventListener(
  'change',
  function () {
    handleChatMediaSelection(
      this,
      'photo'
    );
  }
);


chatVideo?.addEventListener(
  'change',
  function () {
    handleChatMediaSelection(
      this,
      'video'
    );
  }
);


chatVoiceFallback?.addEventListener(
  'change',
  function () {
    handleChatMediaSelection(
      this,
      'voiceFallback'
    );
  }
);


chatAttachment?.addEventListener(
  'change',
  function () {
    handleChatMediaSelection(
      this,
      'attachment'
    );
  }
);


chatCamera?.addEventListener(
  'change',
  function () {
    handleChatMediaSelection(
      this,
      'camera'
    );
  }
);


clearFileBtn?.addEventListener('click', clearSelectedFiles);


document.addEventListener('click', function (event) {
  const callBackButton =
    event.target.closest(
      '.homeowner-call-back'
    );

  if (callBackButton) {
    const callType =
      callBackButton.dataset.callType === 'video'
        ? 'video'
        : 'audio';

    if (
      currentMode === 'homeowner' &&
      selectedHomeownerId > 0
    ) {
      startHomeownerCall(callType);
    }

    return;
  }

  const image = event.target.closest('.previewable-image');

  if (!image) return;

  const src = image.getAttribute('data-src') || image.getAttribute('src');
  openImagePreview(src);
});


imagePreviewClose?.addEventListener('click', closeImagePreview);

imagePreviewModal?.addEventListener('click', function (event) {
  if (event.target === imagePreviewModal) {
    closeImagePreview();
  }
});


closeNoticeModal?.addEventListener('click', closeNoticeDialog);
okNoticeBtn?.addEventListener('click', closeNoticeDialog);

noticeModal?.addEventListener('click', function (event) {
  if (event.target === noticeModal) {
    closeNoticeDialog();
  }
});


btnOpenMutedInfo?.addEventListener('click', openMutedInfoModal);
closeMutedInfoModal?.addEventListener('click', closeMutedInfoDialog);
okMutedInfoBtn?.addEventListener('click', closeMutedInfoDialog);

mutedInfoModal?.addEventListener('click', function (event) {
  if (event.target === mutedInfoModal) {
    closeMutedInfoDialog();
  }
});


document.addEventListener(
    'keydown',
    function (event) {

        if (event.key !== 'Escape') {
            return;
        }

        if (
            cameraModal
                ?.classList
                .contains('flex')
        ) {
            closeCameraModal();
            return;
        }

        if (
            imagePreviewModal
                ?.classList
                .contains('flex')
        ) {
            closeImagePreview();
            return;
        }

        if (
            noticeModal
                ?.classList
                .contains('flex')
        ) {
            closeNoticeDialog();
            return;
        }

        if (
            mutedInfoModal
                ?.classList
                .contains('flex')
        ) {
            closeMutedInfoDialog();
        }
    }
);


chatMessage?.addEventListener('input', updateMessageCounter);



function closeEditMessageDialog() { setModalOpen(editMessageModal, false); }
function closeDeleteMessageDialog() { setModalOpen(deleteMessageModal, false); }

document.addEventListener('click', function (event) {
  const editButton = event.target.closest('.edit-chat-message');
  if (editButton) {
    messageActionTarget = {
      id: Number(editButton.dataset.id || 0),
      scope: editButton.dataset.scope || '',
      message: editButton.dataset.message || ''
    };
    if (editMessageText) editMessageText.value = messageActionTarget.message;
    setModalOpen(editMessageModal, true);
    editMessageText?.focus();
    return;
  }

  const deleteButton = event.target.closest('.delete-chat-message');
  if (deleteButton) {
    messageActionTarget = {
      id: Number(deleteButton.dataset.id || 0),
      scope: deleteButton.dataset.scope || '',
      message: ''
    };
    setModalOpen(deleteMessageModal, true);
  }
});

closeEditMessageModal?.addEventListener('click', closeEditMessageDialog);
cancelEditMessageBtn?.addEventListener('click', closeEditMessageDialog);
cancelDeleteMessageBtn?.addEventListener('click', closeDeleteMessageDialog);

saveEditMessageBtn?.addEventListener('click', async function () {
  const editedMessage = (editMessageText?.value || '').trim();
  if (!messageActionTarget.id || !messageActionTarget.scope) return;
  saveEditMessageBtn.disabled = true;
  try {
    const result = await postJSON('edit_chat_message', {
      scope: messageActionTarget.scope,
      message_id: messageActionTarget.id,
      message: editedMessage
    });
    if (!result.success) {
      openNoticeModal('Unable to Edit', result.message || 'Unable to edit message.');
      return;
    }
    closeEditMessageDialog();
    if (currentMode === 'public') await loadPublicMessages(true);
    else if (currentMode === 'homeowner') await loadHomeownerMessages(true);
    else await loadOfficerMessages(true);
  } catch (error) {
    openNoticeModal('Unable to Edit', error.message || 'Unable to edit message.');
  } finally {
    saveEditMessageBtn.disabled = false;
  }
});

confirmDeleteMessageBtn?.addEventListener('click', async function () {
  if (!messageActionTarget.id || !messageActionTarget.scope) return;
  confirmDeleteMessageBtn.disabled = true;
  try {
    const result = await postJSON('delete_chat_message', {
      scope: messageActionTarget.scope,
      message_id: messageActionTarget.id
    });
    if (!result.success) {
      openNoticeModal('Unable to Delete', result.message || 'Unable to delete message.');
      return;
    }
    closeDeleteMessageDialog();
    if (currentMode === 'public') await loadPublicMessages(true);
    else if (currentMode === 'homeowner') await loadHomeownerMessages(true);
    else await loadOfficerMessages(true);
  } catch (error) {
    openNoticeModal('Unable to Delete', error.message || 'Unable to delete message.');
  } finally {
    confirmDeleteMessageBtn.disabled = false;
  }
});

setInterval(function () {
  const now = Math.floor(Date.now() / 1000);
  document.querySelectorAll('.message-manage-actions').forEach(function (group) {
    const until = Number(group.dataset.editableUntil || 0);
    if (until > 0 && now >= until) group.remove();
  });
}, 5000);

chatForm?.addEventListener('submit', async function (event) {
  event.preventDefault();

  const message = (chatMessage?.value || '').trim();
  const selectedFile = getSelectedFile();

  if (!message && !selectedFile) {
    openNoticeModal('Nothing to Send', 'Type a message or choose an attachment first.');
    return;
  }

  if (selectedFile && !validateSelectedFile(selectedFile)) {
    return;
  }

  if (currentMode === 'public') {
    if (isPublicMuted || chatMessage.disabled) {
      openMutedInfoModal();
      return;
    }

    sendBtn.disabled = true;

    try {
      const fd = new FormData();
      fd.append('action', 'send_message');
      fd.append('message', message);

      if (selectedFile) {
        fd.append('attachment', selectedFile);
      }

      const result = await postFormData(fd);

      if (!result.success) {
        openNoticeModal('Unable to Send', result.message || 'Failed to send message.');
        return;
      }

      chatMessage.value = '';
      clearSelectedFiles();
      updateMessageCounter();

      await loadPublicMessages(false);
      scrollToBottom();

    } catch (error) {
      console.error(error);
      openNoticeModal('Unable to Send', error.message || 'Failed to send message.');
    } finally {
      if (!chatMessage.disabled) {
        sendBtn.disabled = false;
      }

      chatMessage.focus();
    }

    return;
  }

  if (currentMode === 'homeowner') {
    if (!selectedHomeownerId) {
      openNoticeModal('Homeowner Chat', 'Please select a homeowner first.');
      return;
    }

    sendBtn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('action', 'send_homeowner_message');
      fd.append('homeowner_id', selectedHomeownerId);
      fd.append('message', message);
      if (selectedFile) fd.append('attachment', selectedFile);

      const result = await postFormData(fd);
      if (!result.success) {
        openNoticeModal('Unable to Send', result.message || 'Failed to send message to the homeowner.');
        return;
      }

      chatMessage.value = '';
      clearSelectedFiles();
      updateMessageCounter();
      await loadHomeownerMessages(false);
      scrollToBottom();
    } catch (error) {
      console.error(error);
      openNoticeModal('Unable to Send', error.message || 'Failed to send message to the homeowner.');
    } finally {
      if (!chatMessage.disabled) sendBtn.disabled = false;
      chatMessage.focus();
    }
    return;
  }

  if (!selectedOfficerId) {
    openNoticeModal('Officer Chat', 'Please select an officer first.');
    return;
  }

  sendBtn.disabled = true;

  try {
    const fd = new FormData();
    fd.append('action', 'send_officer_message');
    fd.append('admin_id', selectedOfficerId);
    fd.append('message', message);

    if (selectedFile) {
      fd.append('attachment', selectedFile);
    }

    const result = await postFormData(fd);

    if (!result.success) {
      openNoticeModal('Unable to Send', result.message || 'Failed to send message to the officer.');
      return;
    }

    chatMessage.value = '';
    clearSelectedFiles();
    updateMessageCounter();

    await loadOfficerMessages(false);
    scrollToBottom();

  } catch (error) {
    console.error(error);
    openNoticeModal('Unable to Send', error.message || 'Failed to send message to the officer.');
  } finally {
    if (!chatMessage.disabled) {
      sendBtn.disabled = false;
    }

    chatMessage.focus();
  }
});


chatMessage?.addEventListener('keydown', function (event) {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault();
    chatForm?.requestSubmit();
  }
});


/* Sidebar dropdowns */
function initSidebarDropdown(buttonId, menuId, caretId) {
  const button = document.getElementById(buttonId);
  const menu = document.getElementById(menuId);
  const caret = document.getElementById(caretId);

  if (!button || !menu) return;

  button.addEventListener('click', function () {
    const willOpen = menu.classList.contains('hidden');

    menu.classList.toggle('hidden');
    button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

    if (caret) {
      caret.classList.toggle('rotate-180', willOpen);
    }
  });
}

initSidebarDropdown('sbParkingToggle', 'sbParkingMenu', 'sbParkingCaret');
initSidebarDropdown('sbTenantToggle', 'sbTenantMenu', 'sbTenantCaret');


/* Mobile sidebar */
(function () {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const openButton = document.getElementById('sidebarToggle');
  const closeButton = document.getElementById('sidebarClose');

  if (!sidebar || !overlay || !openButton) return;

  function openSidebar() {
    sidebar.classList.remove('-translate-x-full');
    overlay.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
  }

  function closeSidebar() {
    sidebar.classList.add('-translate-x-full');
    overlay.classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
  }

  openButton.addEventListener('click', openSidebar);
  closeButton?.addEventListener('click', closeSidebar);
  overlay.addEventListener('click', closeSidebar);

  sidebar.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () {
      if (window.innerWidth < 1024) {
        closeSidebar();
      }
    });
  });

  window.addEventListener('resize', function () {
    if (window.innerWidth >= 1024) {
      overlay.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }
  });
})();


/* Theme */
(function () {
  const toggle = document.getElementById('themeToggle');
  const icon = document.getElementById('themeIcon');

  if (!toggle || !icon) return;

  function updateThemeIcon() {
    const dark = document.documentElement.classList.contains('dark');

    icon.className = dark
      ? 'bi bi-sun-fill'
      : 'bi bi-moon-stars-fill';

    toggle.setAttribute(
      'aria-label',
      dark ? 'Switch to light mode' : 'Switch to dark mode'
    );

    toggle.setAttribute(
      'title',
      dark ? 'Switch to light mode' : 'Switch to dark mode'
    );
  }

  toggle.addEventListener('click', function () {
    const dark = document.documentElement.classList.toggle('dark');

    localStorage.setItem(
      'hoa-theme',
      dark ? 'dark' : 'light'
    );

    updateThemeIcon();
  });

  updateThemeIcon();
})();


updateComposerState();
loadPublicMessages(true);
startIncomingCallPolling();

setInterval(function () {
  if (currentMode === 'public') {
    loadPublicMessages(false);
  } else if (currentMode === 'homeowner') {
    loadHomeownerMessages(false);
  } else {
    loadOfficerMessages(false);
  }
}, 4000);
</script>

</body>
</html>