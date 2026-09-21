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
    return ['success' => false, 'message' => 'Failed to upload file.'];
  }

  if (!is_uploaded_file($file['tmp_name'])) {
    return ['success' => false, 'message' => 'Invalid uploaded file.'];
  }

  $maxSize = 10 * 1024 * 1024; // 10MB
  if ((int)$file['size'] > $maxSize) {
    return ['success' => false, 'message' => 'File must not exceed 10MB.'];
  }

  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);

  $allowed = [
    'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain'
  ];

  if (!in_array(strtolower((string)$mime), $allowed, true)) {
    return ['success' => false, 'message' => 'Only JPG, PNG, GIF, WEBP, PDF, DOC, DOCX, XLS, XLSX, and TXT files are allowed.'];
  }

  $uploadDirFs = __DIR__ . '/uploads/chat_files/';
  $uploadDirWeb = '../homeowner/uploads/chat_files/';

  if (!is_dir($uploadDirFs)) {
    if (!mkdir($uploadDirFs, 0755, true) && !is_dir($uploadDirFs)) {
      return ['success' => false, 'message' => 'Upload folder could not be created.'];
    }
  }

  $originalName = basename((string)$file['name']);

  $extensionMap = [
    'image/jpeg' => 'jpg',
    'image/jpg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/plain' => 'txt'
  ];

  $safeExt = $extensionMap[strtolower((string)$mime)] ?? '';
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
    'attachment_type' => strtolower((string)$mime)
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
$seenPositions = [];

$stmt = $conn->prepare("
  SELECT id, full_name, email, position
  FROM admins
  WHERE role='admin'
    AND phase=?
    AND position IS NOT NULL
  ORDER BY FIELD(position,'President','Vice President','Secretary','Treasurer','Auditor','Board of Director'), id ASC
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$resOfficers = $stmt->get_result();

while ($r = $resOfficers->fetch_assoc()) {
  $position = trim((string)($r['position'] ?? 'Officer'));
  $name = trim((string)($r['full_name'] ?? ''));

  if ($position === '') continue;

  if ($position === 'Board of Director') {
    if (isset($seenPositions[$position])) continue;
    $seenPositions[$position] = true;
  }

  $officers[] = [
    'id' => (int)$r['id'],
    'full_name' => $name,
    'email' => (string)($r['email'] ?? ''),
    'position' => $position,
    'initials' => strtoupper(substr($position, 0, 1))
  ];
}
$stmt->close();

$defaultOfficerId = !empty($officers) ? (int)$officers[0]['id'] : 0;

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

  if ($action === 'send_message') {
    $message = trim((string)($_POST['message'] ?? ''));
    $message = preg_replace('/\s+/', ' ', $message);

    $upload = uploadChatAttachment($_FILES['attachment'] ?? []);
    if (!$upload['success']) {
      echo json_encode(['success'=>false,'message'=>$upload['message']]);
      exit;
    }

    $hasFile = !empty($upload['uploaded']);

    if ($message === '' && !$hasFile) {
      echo json_encode(['success'=>false,'message'=>'Message or attachment is required.']);
      exit;
    }

    if (mb_strlen($message) > 500) {
      echo json_encode(['success'=>false,'message'=>'Message must not exceed 500 characters.']);
      exit;
    }

    $stmt = $conn->prepare("
      SELECT is_muted, reason
      FROM public_chat_mutes
      WHERE homeowner_id=? AND phase=? AND is_muted=1
      LIMIT 1
    ");
    $stmt->bind_param("is", $hid, $phase);
    $stmt->execute();
    $muteRowAjax = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($muteRowAjax) {
      echo json_encode([
        'success' => false,
        'message' => 'You are muted from public chat.' . (!empty($muteRowAjax['reason']) ? ' Reason: ' . $muteRowAjax['reason'] : '')
      ]);
      exit;
    }

    $attachmentName = $upload['attachment_name'] ?? null;
    $attachmentPath = $upload['attachment_path'] ?? null;
    $attachmentType = $upload['attachment_type'] ?? null;

    $stmt = $conn->prepare("
      INSERT INTO public_chat_messages (phase, homeowner_id, message, attachment_name, attachment_path, attachment_type)
      VALUES (?,?,?,?,?,?)
    ");
    $stmt->bind_param("sissss", $phase, $hid, $message, $attachmentName, $attachmentPath, $attachmentType);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode([
      'success' => $ok,
      'message' => $ok ? 'Message sent.' : 'Failed to send message.'
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
        'created_at' => date('M d, Y h:i A', strtotime($r['created_at']))
      ];
    }
    $stmt->close();

    echo json_encode([
      'success' => true,
      'messages' => $rows
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

    $upload = uploadChatAttachment($_FILES['attachment'] ?? []);
    if (!$upload['success']) {
      echo json_encode(['success'=>false,'message'=>$upload['message']]);
      exit;
    }

    $hasFile = !empty($upload['uploaded']);

    if ($adminId <= 0) {
      echo json_encode(['success'=>false,'message'=>'Please select an officer.']);
      exit;
    }

    if ($message === '' && !$hasFile) {
      echo json_encode(['success'=>false,'message'=>'Message or attachment is required.']);
      exit;
    }

    if (mb_strlen($message) > 1000) {
      echo json_encode(['success'=>false,'message'=>'Message must not exceed 1000 characters.']);
      exit;
    }

    $stmt = $conn->prepare("
      SELECT id, position
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
    $insertOk = true;

    if ($selectedPosition === 'Board of Director') {
      $boardIds = [];

      $stmt = $conn->prepare("
        SELECT id
        FROM admins
        WHERE role='admin'
          AND phase=?
          AND position='Board of Director'
        ORDER BY id ASC
      ");
      $stmt->bind_param("s", $phase);
      $stmt->execute();
      $resBoard = $stmt->get_result();
      while ($row = $resBoard->fetch_assoc()) {
        $boardIds[] = (int)$row['id'];
      }
      $stmt->close();

      if (empty($boardIds)) {
        echo json_encode(['success'=>false,'message'=>'No Board of Director officers found.']);
        exit;
      }

      $stmt = $conn->prepare("
        INSERT INTO homeowner_officer_messages
        (phase, homeowner_id, admin_id, sender_type, message, attachment_name, attachment_path, attachment_type, is_read_by_homeowner, is_read_by_admin)
        VALUES (?, ?, ?, 'homeowner', ?, ?, ?, ?, 1, 0)
      ");

      foreach ($boardIds as $bid) {
        $stmt->bind_param("siissss", $phase, $hid, $bid, $message, $attachmentName, $attachmentPath, $attachmentType);
        if (!$stmt->execute()) {
          $insertOk = false;
        }
      }
      $stmt->close();

      echo json_encode([
        'success' => $insertOk,
        'message' => $insertOk ? 'Message sent to all Board of Directors.' : 'Failed to send message.'
      ]);
      exit;
    }

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
      'message' => $ok ? 'Message sent to officer.' : 'Failed to send message.'
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

    if ($selectedPosition === 'Board of Director') {
      $boardIds = [];

      $stmt = $conn->prepare("
        SELECT id
        FROM admins
        WHERE role='admin'
          AND phase=?
          AND position='Board of Director'
        ORDER BY id ASC
      ");
      $stmt->bind_param("s", $phase);
      $stmt->execute();
      $resBoard = $stmt->get_result();
      while ($row = $resBoard->fetch_assoc()) {
        $boardIds[] = (int)$row['id'];
      }
      $stmt->close();

      if (empty($boardIds)) {
        echo json_encode(['success'=>false,'message'=>'No Board of Director officers found.']);
        exit;
      }

      $placeholders = implode(',', array_fill(0, count($boardIds), '?'));

      if ($lastId > 0) {
        $types = 'sii' . str_repeat('i', count($boardIds));
        $sql = "
          SELECT hom.id, hom.message, hom.attachment_name, hom.attachment_path, hom.attachment_type, hom.created_at, hom.sender_type,
                 a.full_name AS admin_name, a.position AS admin_position
          FROM homeowner_officer_messages hom
          LEFT JOIN admins a ON a.id = hom.admin_id
          WHERE hom.phase = ?
            AND hom.homeowner_id = ?
            AND hom.id > ?
            AND hom.admin_id IN ($placeholders)
          ORDER BY hom.id ASC
        ";
        $stmt = $conn->prepare($sql);
        $params = array_merge([$phase, $hid, $lastId], $boardIds);
      } else {
        $types = 'si' . str_repeat('i', count($boardIds));
        $sql = "
          SELECT * FROM (
            SELECT hom.id, hom.message, hom.attachment_name, hom.attachment_path, hom.attachment_type, hom.created_at, hom.sender_type,
                   a.full_name AS admin_name, a.position AS admin_position
            FROM homeowner_officer_messages hom
            LEFT JOIN admins a ON a.id = hom.admin_id
            WHERE hom.phase = ?
              AND hom.homeowner_id = ?
              AND hom.admin_id IN ($placeholders)
            ORDER BY hom.id DESC
            LIMIT 60
          ) x
          ORDER BY x.id ASC
        ";
        $stmt = $conn->prepare($sql);
        $params = array_merge([$phase, $hid], $boardIds);
      }

      $bindValues = [];
      $bindValues[] = &$types;
      foreach ($params as $k => $v) {
        $bindValues[] = &$params[$k];
      }
      call_user_func_array([$stmt, 'bind_param'], $bindValues);

      $stmt->execute();
      $res = $stmt->get_result();

      while ($r = $res->fetch_assoc()) {
        $mine = ((string)$r['sender_type'] === 'homeowner');
        $adminName = trim((string)($r['admin_name'] ?? 'Board of Director'));
        $rows[] = [
          'id' => (int)$r['id'],
          'mine' => $mine,
          'name' => $mine ? 'You' : $adminName,
          'role' => $mine ? ($isTenant ? 'Tenant' : 'Homeowner') : 'Board of Director',
          'initials' => $mine ? $initials : 'BD',
          'profile_picture_url' =>
    $mine && $profilePictureUrl !== ''
        ? $profilePictureUrl
        : '',
          'message' => (string)$r['message'],
          'attachment_name' => (string)($r['attachment_name'] ?? ''),
          'attachment_path' => fixChatAttachmentPath($r['attachment_path'] ?? ''),
          'attachment_type' => (string)($r['attachment_type'] ?? ''),
          'is_image' => isImageMime($r['attachment_type'] ?? ''),
          'created_at' => date('M d, Y h:i A', strtotime($r['created_at']))
        ];
      }
      $stmt->close();

      $types = 'si' . str_repeat('i', count($boardIds));
      $sql = "
        UPDATE homeowner_officer_messages
        SET is_read_by_homeowner = 1
        WHERE phase = ?
          AND homeowner_id = ?
          AND sender_type = 'admin'
          AND admin_id IN ($placeholders)
          AND is_read_by_homeowner = 0
      ";
      $stmt = $conn->prepare($sql);
      $params = array_merge([$phase, $hid], $boardIds);

      $bindValues = [];
      $bindValues[] = &$types;
      foreach ($params as $k => $v) {
        $bindValues[] = &$params[$k];
      }
      call_user_func_array([$stmt, 'bind_param'], $bindValues);

      $stmt->execute();
      $stmt->close();

      echo json_encode([
        'success' => true,
        'officer' => [
          'id' => (int)$selectedOfficer['id'],
          'name' => 'Board of Director',
          'position' => 'Board of Director'
        ],
        'messages' => $rows
      ]);
      exit;
    }

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
        'created_at' => date('M d, Y h:i A', strtotime($r['created_at']))
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
            Be respectful, avoid spam, and keep conversations related to your community.
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
                <option
                  value="<?= (int)$of['id'] ?>"
                  data-position="<?= esc($of['position']) ?>"
                  <?= $i === 0 ? 'selected' : '' ?>
                >
                  <?= esc($of['position']) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">
              Choose the officer position you want to message privately.
            </p>
          <?php else: ?>
            <div class="mt-3 rounded-xl bg-slate-50 p-3 text-sm font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
              No officers are available in your phase yet.
            </div>
          <?php endif; ?>
        </section>

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

            <span id="phaseBadge" class="inline-flex w-fit items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900">
              <i class="bi bi-chat-dots-fill"></i>
              <?= esc($phase) ?>
            </span>
          </div>
        </div>

        <div
          id="chatBody"
          class="h-[56vh] min-h-[420px] max-h-[720px] overflow-y-auto bg-slate-50 px-3 py-4 scroll-smooth sm:px-5 dark:bg-slate-950/50"
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

                <div class="mt-2 flex flex-wrap items-center gap-2">
                  <input type="file" id="chatAttachment" hidden accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt">
                  <input type="file" id="chatCamera" hidden accept="image/*" capture="environment">

                  <button
                    type="button"
                    id="attachBtn"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                    title="Attach file"
                  >
                    <i class="bi bi-paperclip"></i>
                    Attach
                  </button>

                  <button
                    type="button"
                    id="cameraBtn"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700"
                    title="Open camera"
                  >
                    <i class="bi bi-camera-fill"></i>
                    Camera
                  </button>

                  <div
                    id="selectedFileBadge"
                    class="hidden max-w-full items-center gap-2 rounded-xl bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 ring-1 ring-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:ring-blue-900"
                  >
                    <i class="bi bi-file-earmark"></i>
                    <span id="selectedFileName" class="max-w-[220px] truncate"></span>
                    <button type="button" id="clearFileBtn" class="flex h-6 w-6 items-center justify-center rounded-md text-red-600 hover:bg-red-100 dark:text-red-300 dark:hover:bg-red-950" aria-label="Remove selected file">
                      <i class="bi bi-x-lg"></i>
                    </button>
                  </div>
                </div>

                <p id="chatTip" class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">
                  Max 500 characters. Everyone in <?= esc($phase) ?> can see your message. Attachments up to 10MB are allowed.
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


<script>
const CSRF_TOKEN = <?= json_encode($csrf) ?>;
const isPublicMuted = <?= $isMuted ? 'true' : 'false' ?>;
const muteReason = <?= json_encode($muteReason) ?>;
const phase = <?= json_encode($phase) ?>;
const defaultOfficerId = <?= (int)$defaultOfficerId ?>;

const chatBody = document.getElementById('chatBody');
const chatForm = document.getElementById('chatForm');
const chatMessage = document.getElementById('chatMessage');
const sendBtn = document.getElementById('sendBtn');
const chatRoomTitle = document.getElementById('chatRoomTitle');
const chatRoomSub = document.getElementById('chatRoomSub');
const chatTip = document.getElementById('chatTip');
const messageCount = document.getElementById('messageCount');

const modePublicBtn = document.getElementById('modePublicBtn');
const modeOfficerBtn = document.getElementById('modeOfficerBtn');
const officerSelect = document.getElementById('officerSelect');

const attachBtn = document.getElementById('attachBtn');
const cameraBtn = document.getElementById('cameraBtn');
const chatAttachment = document.getElementById('chatAttachment');
const chatCamera = document.getElementById('chatCamera');
const selectedFileBadge = document.getElementById('selectedFileBadge');
const selectedFileName = document.getElementById('selectedFileName');
const clearFileBtn = document.getElementById('clearFileBtn');

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
let publicLastId = 0;
let officerLastId = 0;
let isFetching = false;

if (officerSelect && officerSelect.value) {
  selectedOfficerId = Number(officerSelect.value || 0);
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


function setModalOpen(modal, open) {
  if (!modal) return;

  modal.classList.toggle('hidden', !open);
  modal.classList.toggle('flex', open);

  if (open) {
    document.body.classList.add('overflow-hidden');
  } else if (!document.querySelector('#noticeModal.flex, #mutedInfoModal.flex, #imagePreviewModal.flex')) {
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

  if (isImageAttachment(message)) {
    return `
      <div class="mt-2">
        <img
          src="${escapeHtml(path)}"
          alt="${name}"
          class="previewable-image max-h-64 max-w-full cursor-pointer rounded-xl border border-black/10 bg-white object-contain shadow-sm"
          data-src="${escapeHtml(path)}"
        >
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


function createPublicMessage(message) {
  const row = document.createElement('div');

  row.dataset.id = String(message.id || '');
  row.className = `flex items-end gap-2 ${message.mine ? 'justify-end' : 'justify-start'}`;

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
        : escapeHtml(
            message.initials || 'O'
        );


const avatar = `
    <div
        class="
            flex
            h-9 w-9
            shrink-0
            items-center
            justify-center
            overflow-hidden
            rounded-full

            ${
                message.mine
                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                    : 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
            }

            text-xs
            font-bold
        "
    >
        ${avatarContent}
    </div>
`;

  const bubble = `
    <div class="max-w-[86%] sm:max-w-[72%]">
      <div class="mb-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] font-medium text-slate-500 dark:text-slate-400 ${
        message.mine ? 'justify-end' : 'justify-start'
      }">
        <span class="font-bold text-slate-700 dark:text-slate-300">${escapeHtml(message.mine ? 'You' : message.name)}</span>
        ${message.lot ? `<span>${escapeHtml(message.lot)}</span>` : ''}
        <span>•</span>
        <span>${escapeHtml(message.created_at || '')}</span>
      </div>

      <div class="rounded-2xl px-3.5 py-2.5 text-sm leading-6 shadow-sm ${
        message.mine
          ? 'rounded-br-md bg-emerald-700 text-white dark:bg-emerald-600'
          : 'rounded-bl-md border border-slate-200 bg-white text-slate-800 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100'
      }">
        ${message.message ? escapeHtml(message.message).replace(/\n/g, '<br>') : ''}
        ${renderAttachmentHtml(message)}
      </div>
    </div>
  `;

  row.innerHTML = message.mine
    ? bubble + avatar
    : avatar + bubble;

  return row;
}


function createOfficerMessage(message) {
  const row = document.createElement('div');

  row.dataset.id = String(message.id || '');
  row.className = `flex items-end gap-2 ${message.mine ? 'justify-end' : 'justify-start'}`;

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
        : escapeHtml(
            message.initials || 'O'
        );


const avatar = `
    <div
        class="
            flex
            h-9 w-9
            shrink-0
            items-center
            justify-center
            overflow-hidden
            rounded-full

            ${
                message.mine
                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                    : 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
            }

            text-xs
            font-bold
        "
    >
        ${avatarContent}
    </div>
`;

  const bubble = `
    <div class="max-w-[86%] sm:max-w-[72%]">
      <div class="mb-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] font-medium text-slate-500 dark:text-slate-400 ${
        message.mine ? 'justify-end' : 'justify-start'
      }">
        <span class="font-bold text-slate-700 dark:text-slate-300">${escapeHtml(message.name || 'Officer')}</span>
        <span>${escapeHtml(message.role || '')}</span>
        <span>•</span>
        <span>${escapeHtml(message.created_at || '')}</span>
      </div>

      <div class="rounded-2xl px-3.5 py-2.5 text-sm leading-6 shadow-sm ${
        message.mine
          ? 'rounded-br-md bg-emerald-700 text-white dark:bg-emerald-600'
          : 'rounded-bl-md border border-blue-200 bg-blue-50 text-slate-800 dark:border-blue-900 dark:bg-blue-950/35 dark:text-slate-100'
      }">
        ${message.message ? escapeHtml(message.message).replace(/\n/g, '<br>') : ''}
        ${renderAttachmentHtml(message)}
      </div>
    </div>
  `;

  row.innerHTML = message.mine
    ? bubble + avatar
    : avatar + bubble;

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


function updateSelectedFileUI(file) {
  if (!selectedFileBadge || !selectedFileName) return;

  if (!file) {
    selectedFileBadge.classList.add('hidden');
    selectedFileBadge.classList.remove('inline-flex');
    selectedFileName.textContent = '';
    return;
  }

  selectedFileBadge.classList.remove('hidden');
  selectedFileBadge.classList.add('inline-flex');
  selectedFileName.textContent = file.name || 'Selected file';
}


function clearSelectedFiles() {
  if (chatAttachment) chatAttachment.value = '';
  if (chatCamera) chatCamera.value = '';
  updateSelectedFileUI(null);
}


function getSelectedFile() {
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

  const maxSize = 10 * 1024 * 1024;

  if (file.size > maxSize) {
    clearSelectedFiles();
    openNoticeModal('Attachment Too Large', 'The selected file must not exceed 10MB.');
    return false;
  }

  return true;
}


function setModeButtons() {
  const publicActive = [
    'bg-emerald-700',
    'text-white',
    'shadow-sm'
  ];

  const publicInactive = [
    'text-slate-600',
    'hover:bg-white',
    'hover:text-emerald-700',
    'dark:text-slate-300',
    'dark:hover:bg-slate-700',
    'dark:hover:text-emerald-300'
  ];

  const officerActive = [
    'bg-blue-700',
    'text-white',
    'shadow-sm'
  ];

  const officerInactive = [
    'text-slate-600',
    'hover:bg-white',
    'hover:text-blue-700',
    'dark:text-slate-300',
    'dark:hover:bg-slate-700',
    'dark:hover:text-blue-300'
  ];

  modePublicBtn?.classList.remove(...publicActive, ...publicInactive);
  modeOfficerBtn?.classList.remove(...officerActive, ...officerInactive);

  if (currentMode === 'public') {
    modePublicBtn?.classList.add(...publicActive);
    modeOfficerBtn?.classList.add(...officerInactive);
  } else {
    modePublicBtn?.classList.add(...publicInactive);
    modeOfficerBtn?.classList.add(...officerActive);
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

  if (currentMode === 'public') {
    chatRoomTitle.textContent = 'Public Community Chat';
    chatRoomSub.textContent = `Talk with other homeowners in ${phase}`;
    chatTip.textContent = `Max 500 characters. Everyone in ${phase} can see your message. Attachments up to 10MB are allowed.`;

    chatMessage.maxLength = 500;

    sendBtn.classList.remove('bg-blue-700', 'hover:bg-blue-800', 'dark:bg-blue-600', 'dark:hover:bg-blue-500');
    sendBtn.classList.add('bg-emerald-700', 'hover:bg-emerald-800', 'dark:bg-emerald-600', 'dark:hover:bg-emerald-500');

    if (isPublicMuted) {
      chatMessage.placeholder = 'You are muted from sending public messages.';
      chatMessage.disabled = true;
      sendBtn.disabled = true;
      if (attachBtn) attachBtn.disabled = true;
      if (cameraBtn) cameraBtn.disabled = true;
    } else {
      chatMessage.placeholder = 'Type your public message here...';
      chatMessage.disabled = false;
      sendBtn.disabled = false;
      if (attachBtn) attachBtn.disabled = false;
      if (cameraBtn) cameraBtn.disabled = false;
    }
  } else {
    const selectedOption = officerSelect?.selectedOptions?.[0] || null;
    const officerPosition = selectedOption?.dataset.position || 'Officer';

    chatRoomTitle.textContent = 'Officer Private Chat';
    chatRoomSub.textContent = `Private conversation with ${officerPosition}`;
    chatTip.textContent = 'Max 1000 characters. Only you and the selected officer can see this conversation. Attachments up to 10MB are allowed.';

    chatMessage.maxLength = 1000;

    sendBtn.classList.remove('bg-emerald-700', 'hover:bg-emerald-800', 'dark:bg-emerald-600', 'dark:hover:bg-emerald-500');
    sendBtn.classList.add('bg-blue-700', 'hover:bg-blue-800', 'dark:bg-blue-600', 'dark:hover:bg-blue-500');

    if (!selectedOfficerId) {
      chatMessage.placeholder = 'No officer is available.';
      chatMessage.disabled = true;
      sendBtn.disabled = true;
      if (attachBtn) attachBtn.disabled = true;
      if (cameraBtn) cameraBtn.disabled = true;
    } else {
      chatMessage.placeholder = 'Type your private message to the officer...';
      chatMessage.disabled = false;
      sendBtn.disabled = false;
      if (attachBtn) attachBtn.disabled = false;
      if (cameraBtn) cameraBtn.disabled = false;
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
      const selectedOption = officerSelect?.selectedOptions?.[0] || null;
      const officerPosition = selectedOption?.dataset.position || 'officer';

      chatBody.innerHTML = emptyState(`Start a private conversation with ${officerPosition}.`);
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
  currentMode = mode;
  clearSelectedFiles();
  updateComposerState();

  if (mode === 'public') {
    await loadPublicMessages(true);
  } else {
    await loadOfficerMessages(true);
  }
}


modePublicBtn?.addEventListener('click', () => {
  switchMode('public');
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


attachBtn?.addEventListener('click', () => {
  if (!attachBtn.disabled) {
    chatAttachment?.click();
  }
});


cameraBtn?.addEventListener('click', () => {
  if (!cameraBtn.disabled) {
    chatCamera?.click();
  }
});


chatAttachment?.addEventListener('change', function () {
  const file = this.files?.[0] || null;

  if (file && !validateSelectedFile(file)) {
    return;
  }

  if (file && chatCamera) {
    chatCamera.value = '';
  }

  updateSelectedFileUI(file || getSelectedFile());
});


chatCamera?.addEventListener('change', function () {
  const file = this.files?.[0] || null;

  if (file && !validateSelectedFile(file)) {
    return;
  }

  if (file && chatAttachment) {
    chatAttachment.value = '';
  }

  updateSelectedFileUI(file || getSelectedFile());
});


clearFileBtn?.addEventListener('click', clearSelectedFiles);


document.addEventListener('click', function (event) {
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


document.addEventListener('keydown', function (event) {
  if (event.key !== 'Escape') return;

  if (imagePreviewModal?.classList.contains('flex')) {
    closeImagePreview();
    return;
  }

  if (noticeModal?.classList.contains('flex')) {
    closeNoticeDialog();
    return;
  }

  if (mutedInfoModal?.classList.contains('flex')) {
    closeMutedInfoDialog();
  }
});


chatMessage?.addEventListener('input', updateMessageCounter);


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

setInterval(function () {
  if (currentMode === 'public') {
    loadPublicMessages(false);
  } else {
    loadOfficerMessages(false);
  }
}, 4000);
</script>

</body>
</html>