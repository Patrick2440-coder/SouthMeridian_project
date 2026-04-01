<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
  header("Location: ../index.php");
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$hid = (int)$_SESSION['homeowner_id'];

$stmt = $conn->prepare("
  SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number, latitude, longitude
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

$phase      = (string)$user['phase'];
$fullName   = trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? ''));
$mustChange = ((int)$user['must_change_password'] === 1);
$initials   = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));
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

$err = "";
if ($mustChange && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password_submit'])) {
  $p1 = $_POST['password'] ?? '';
  $p2 = $_POST['password2'] ?? '';

  if (strlen($p1) < 8) $err = "Password must be at least 8 characters.";
  else if ($p1 !== $p2) $err = "Passwords do not match.";
  else {
    $hash = password_hash($p1, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("UPDATE homeowners SET password=?, must_change_password=0 WHERE id=?");
    $stmt->bind_param("si", $hash, $hid);
    $stmt->execute();
    $stmt->close();
    header("Location: homeowner_public_chat.php");
    exit;
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

  if ($mustChange) {
    echo json_encode(['success'=>false,'message'=>'Please change your password first.']);
    exit;
  }

  $action = (string)$_POST['action'];

  if ($action === 'send_message') {
    $message = trim((string)($_POST['message'] ?? ''));
    $message = preg_replace('/\s+/', ' ', $message);

    if ($message === '') {
      echo json_encode(['success'=>false,'message'=>'Message cannot be empty.']);
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

    $stmt = $conn->prepare("
      INSERT INTO public_chat_messages (phase, homeowner_id, message)
      VALUES (?,?,?)
    ");
    $stmt->bind_param("sis", $phase, $hid, $message);
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
          pcm.created_at,
          pcm.homeowner_id,
          h.first_name,
          h.last_name,
          h.house_lot_number
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
            pcm.created_at,
            pcm.homeowner_id,
            h.first_name,
            h.last_name,
            h.house_lot_number
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
        'message' => (string)$r['message'],
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

    if ($adminId <= 0) {
      echo json_encode(['success'=>false,'message'=>'Please select an officer.']);
      exit;
    }

    if ($message === '') {
      echo json_encode(['success'=>false,'message'=>'Message cannot be empty.']);
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
        (phase, homeowner_id, admin_id, sender_type, message, is_read_by_homeowner, is_read_by_admin)
        VALUES (?, ?, ?, 'homeowner', ?, 1, 0)
      ");

      foreach ($boardIds as $bid) {
        $stmt->bind_param("siis", $phase, $hid, $bid, $message);
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
      (phase, homeowner_id, admin_id, sender_type, message, is_read_by_homeowner, is_read_by_admin)
      VALUES (?, ?, ?, 'homeowner', ?, 1, 0)
    ");
    $stmt->bind_param("siis", $phase, $hid, $adminId, $message);
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
          SELECT hom.id, hom.message, hom.created_at, hom.sender_type,
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
            SELECT hom.id, hom.message, hom.created_at, hom.sender_type,
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
          'role' => $mine ? 'Homeowner' : 'Board of Director',
          'initials' => $mine ? $initials : 'BD',
          'message' => (string)$r['message'],
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
        SELECT hom.id, hom.message, hom.created_at, hom.sender_type,
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
          SELECT hom.id, hom.message, hom.created_at, hom.sender_type,
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
        'role' => $mine ? 'Homeowner' : (string)($r['admin_position'] ?? 'Officer'),
        'initials' => $mine
          ? $initials
          : strtoupper(substr($adminName ?: 'O',0,1).substr(strrchr(' '.$adminName,' ') ?: 'F',1,1)),
        'message' => (string)$r['message'],
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

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/homeowner_dashboard.css">

<style>
  html, body {
    max-width: 100%;
    overflow-x: hidden;
    background: #f6f8fb;
  }

  .app-shell{ position: relative; }

  .sidebar-overlay{
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, .45);
    z-index: 1040;
    opacity: 0;
    visibility: hidden;
    transition: .25s ease;
  }
  .sidebar-overlay.show{
    opacity: 1;
    visibility: visible;
  }

  .sb-dd { display:flex; flex-direction:column; gap:6px; }
  .sb-dd-toggle{ display:flex; align-items:center; justify-content:space-between; gap:10px; width:100%; }
  .sb-dd-menu{ display:none; padding-left:12px; margin-top:2px; border-left:2px solid rgba(255,255,255,.08); }
  .sb-dd.open .sb-dd-menu{ display:block; }
  .sb-dd-caret{ transition: transform .15s ease; }
  .sb-dd.open .sb-dd-caret{ transform: rotate(180deg); }

  .topbar-mobile-btn{
    border: 1px solid #dbe3ea;
    background: #fff;
    color: #0f5132;
    border-radius: 10px;
    width: 42px;
    height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .chat-shell{
    display: grid;
    grid-template-columns: 320px minmax(0,1fr);
    gap: 20px;
  }

  .chat-side-card,
  .chat-main-card{
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 8px 30px rgba(15, 23, 42, .06);
    border: 1px solid #edf2f7;
  }

  .chat-side-card{ padding: 18px; }

  .chat-main-card{
    display: flex;
    flex-direction: column;
    min-height: 74vh;
    overflow: hidden;
  }

  .chat-head{
    padding: 18px 20px;
    border-bottom: 1px solid #eef2f7;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
  }

  .chat-room-title{
    margin: 0;
    font-size: 1.1rem;
    font-weight: 800;
    color: #0f172a;
  }

  .chat-room-sub{
    color: #64748b;
    font-size: .92rem;
    font-weight: 600;
  }

  .phase-badge{
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ecfdf5;
    color: #166534;
    padding: 8px 12px;
    border-radius: 999px;
    font-weight: 800;
    font-size: .86rem;
    white-space: nowrap;
  }

  .chat-mode-switch{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:12px;
  }

  .chat-mode-btn{
    border:1px solid #dbe3ea;
    background:#fff;
    color:#0f172a;
    border-radius:12px;
    padding:10px 14px;
    font-weight:800;
    cursor:pointer;
  }

  .chat-mode-btn.active{
    background:#16a34a;
    color:#fff;
    border-color:#16a34a;
  }

  .chat-body{
    flex: 1;
    overflow-y: auto;
    padding: 18px;
    background:
      radial-gradient(circle at top left, rgba(34,197,94,.06), transparent 25%),
      linear-gradient(180deg, #fafcff 0%, #f8fafc 100%);
  }

  .chat-empty{
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: #64748b;
    font-weight: 700;
    padding: 30px;
  }

  .msg-row{
    display: flex;
    gap: 10px;
    margin-bottom: 14px;
    align-items: flex-end;
  }

  .msg-row.mine{
    flex-direction: row-reverse;
  }

  .msg-avatar{
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, #22c55e, #16a34a);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: .88rem;
    flex: 0 0 42px;
    box-shadow: 0 8px 18px rgba(34,197,94,.18);
  }

  .msg-avatar.officer{
    background: linear-gradient(135deg, #0ea5e9, #2563eb);
    box-shadow: 0 8px 18px rgba(37,99,235,.18);
  }

  .msg-bubble-wrap{
    max-width: min(76%, 680px);
    min-width: 0;
  }

  .msg-meta{
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 4px;
    flex-wrap: wrap;
    font-size: .82rem;
    color: #64748b;
    font-weight: 700;
  }

  .msg-row.mine .msg-meta{
    justify-content: flex-end;
  }

  .msg-name{
    color: #0f172a;
    font-weight: 800;
  }

  .msg-bubble{
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 18px 18px 18px 6px;
    padding: 12px 14px;
    color: #111827;
    font-weight: 600;
    word-wrap: break-word;
    overflow-wrap: anywhere;
    line-height: 1.45;
    box-shadow: 0 8px 18px rgba(15, 23, 42, .04);
  }

  .msg-row.mine .msg-bubble{
    background: #16a34a;
    color: #fff;
    border-color: #16a34a;
    border-radius: 18px 18px 6px 18px;
  }

  .msg-row.officer-row .msg-bubble{
    background:#eff6ff;
    border-color:#bfdbfe;
  }

  .chat-foot{
    padding: 14px 16px;
    border-top: 1px solid #eef2f7;
    background: #fff;
  }

  .chat-form{
    display: flex;
    gap: 10px;
    align-items: flex-end;
  }

  .chat-input{
    flex: 1;
    min-height: 52px;
    max-height: 140px;
    resize: vertical;
    border-radius: 14px;
    border: 1px solid #dbe3ea;
    padding: 12px 14px;
    font-weight: 600;
    outline: none;
  }

  .chat-input:focus{
    border-color: #22c55e;
    box-shadow: 0 0 0 0.2rem rgba(34,197,94,.15);
  }

  .chat-input[disabled]{
    background:#f1f5f9;
    cursor:not-allowed;
    opacity:1;
  }

  .btn-send-chat{
    height: 52px;
    min-width: 56px;
    border: 0;
    border-radius: 14px;
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #fff;
    font-weight: 800;
    padding: 0 18px;
  }

  .btn-send-chat.officer-mode{
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
  }

  .btn-send-chat[disabled]{
    background:#94a3b8;
    cursor:not-allowed;
  }

  .chat-tip{
    font-size: .85rem;
    color: #64748b;
    font-weight: 700;
    margin-top: 8px;
  }

  .member-box{
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 14px;
  }

  .member-box h6{
    margin-bottom: 10px;
    font-weight: 800;
  }

  .member-mini{
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px dashed #e2e8f0;
  }

  .member-mini:last-child{
    border-bottom: 0;
  }

  .member-mini-avatar{
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #dcfce7;
    color: #166534;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    flex: 0 0 36px;
  }

  .mobile-user-strip{ display:none; }

  .lock-modal{
    width: min(460px, calc(100% - 24px));
    background: #fff;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0,0,0,.18);
  }
  .lock-overlay{
    position: fixed;
    inset: 0;
    background: rgba(15,23,42,.55);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    padding: 12px;
  }
  .lock-modal .head{
    background: #14532d;
    color: #fff;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .lock-modal .body{ padding: 18px; }
  .lock-note{
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 12px;
  }

  .modalx{
    display:none;
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.50);
    align-items:center;
    justify-content:center;
    z-index:3000;
    padding:16px;
  }

  .modalx .box{
    width:min(520px, 96vw);
    background:#fff;
    border-radius:18px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,.25);
  }

  .modalx .boxhead{
    padding:16px 18px;
    border-bottom:1px solid #e5e7eb;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
  }

  .modalx .closebtn{
    border:none;
    background:transparent;
    font-size:24px;
    line-height:1;
    cursor:pointer;
  }

  .modalx .boxbody{
    padding:18px;
  }

  .modalx .boxfoot{
    padding:14px 18px;
    border-top:1px solid #e5e7eb;
    display:flex;
    justify-content:flex-end;
    gap:10px;
    flex-wrap:wrap;
  }

  .mute-state-card{
    border:1px solid #fecaca;
    background:#fef2f2;
    color:#991b1b;
    border-radius:16px;
    padding:14px;
    margin-bottom:14px;
  }

  .notice-text{
    color:#334155;
    line-height:1.5;
    font-weight:600;
  }

  @media (max-width: 991.98px){
    .sidebar{
      position: fixed !important;
      top: 0;
      left: -290px;
      width: 280px !important;
      max-width: 85vw;
      height: 100vh;
      z-index: 1050;
      transition: left .25s ease;
      overflow-y: auto;
    }

    .sidebar.show{ left: 0; }

    .main-area{
      width: 100% !important;
      margin-left: 0 !important;
    }

    .container-xl{
      padding-left: 14px;
      padding-right: 14px;
    }

    .mobile-user-strip{
      display:block;
      margin-bottom: 14px;
    }

    .desktop-user-text{
      display:none !important;
    }

    .chat-shell{
      grid-template-columns: 1fr;
    }

    .chat-main-card{
      min-height: calc(100vh - 190px);
    }
  }

  @media (max-width: 767.98px){
    body{ font-size: 14px; }

    .navbar-brand{
      font-size: 1rem;
      max-width: 140px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .chat-head{ padding: 14px; }
    .chat-body{ padding: 12px; }
    .chat-foot{ padding: 12px; }

    .msg-bubble-wrap{ max-width: 88%; }

    .msg-avatar{
      width: 36px;
      height: 36px;
      flex-basis: 36px;
      font-size: .8rem;
    }

    .chat-form{ gap: 8px; }

    .chat-input{
      min-height: 48px;
      font-size: 14px;
    }

    .btn-send-chat{
      height: 48px;
      min-width: 50px;
      padding: 0 14px;
    }
  }
</style>
</head>
<body>

<div class="app-shell">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <aside class="sidebar" id="sidebar">
    <div class="sb-head">
      <div class="sb-brand">
        <i class="bi bi-grid-fill"></i>
        <span class="sb-brand-text">HOA Menu</span>
      </div>
    </div>

    <div class="sb-user">
      <div class="sb-avatar"><?= esc($initials) ?></div>
      <div class="sb-user-text">
        <p class="sb-name"><?= esc($fullName) ?></p>
        <p class="sb-meta"><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?></p>
      </div>
    </div>

    <nav class="sb-nav">
      <a class="sb-link <?= $activePage==='homeowner_dashboard.php' ? 'active' : '' ?>" href="homeowner_dashboard.php">
        <i class="bi bi-house-door-fill"></i> <span>Dashboard</span>
      </a>

      <a class="sb-link" href="homeowner_dashboard.php#feed">
        <i class="bi bi-megaphone-fill"></i> <span>Announcement Feed</span>
      </a>

      <a class="sb-link <?= $activePage==='homeowner_public_chat.php' ? 'active' : '' ?>" href="homeowner_public_chat.php">
        <i class="bi bi-people-fill"></i> <span>Public Chat</span>
      </a>

      <a class="sb-link <?= $activePage==='homeowner_pay_dues.php' ? 'active' : '' ?>" href="homeowner_pay_dues.php">
        <i class="bi bi-cash-coin"></i> <span>Pay Monthly Dues</span>
      </a>

      <div class="sb-dd <?= $parkingOpen ? 'open' : '' ?>" id="sbParking">
        <a class="sb-link sb-dd-toggle <?= $parkingOpen ? 'active' : '' ?>" href="javascript:void(0)" id="sbParkingToggle">
          <span><i class="bi bi-car-front-fill"></i> <span>Parking</span></span>
          <i class="bi bi-chevron-down sb-dd-caret"></i>
        </a>
        <div class="sb-dd-menu">
          <a class="sb-link <?= $activePage==='homeowner_parking.php' ? 'active' : '' ?>" href="homeowner_parking.php">
            <i class="bi bi-info-circle-fill"></i> <span>Parking Overview</span>
          </a>
          <a class="sb-link <?= $activePage==='homeowner_parking_permit.php' ? 'active' : '' ?>" href="homeowner_parking_permit.php">
            <i class="bi bi-card-checklist"></i> <span>Apply / Renew Permit</span>
          </a>
          <a class="sb-link <?= $activePage==='homeowner_parking_violations.php' ? 'active' : '' ?>" href="homeowner_parking_violations.php">
            <i class="bi bi-receipt-cutoff"></i> <span>My Violations</span>
          </a>
        </div>
      </div>

      <a class="sb-link <?= $activePage==='homeowner_rentals.php' ? 'active' : '' ?>" href="homeowner_rentals.php">
        <i class="bi bi-calendar2-week-fill"></i> <span>Facility Rentals</span>
      </a>

      <a class="sb-link <?= $activePage==='homeowner_complaints.php' ? 'active' : '' ?>" href="homeowner_complaints.php">
        <i class="bi bi-chat-left-text-fill"></i> <span>File a Complaint</span>
      </a>

      <a class="sb-link <?= $activePage==='homeowner_voting.php' ? 'active' : '' ?>" href="homeowner_voting.php">
        <i class="bi bi-check2-square"></i> <span>Voting</span>
      </a>

      <a class="sb-link" href="logout.php">
        <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
      </a>
    </nav>
  </aside>

  <div class="main-area">
    <div class="<?= $mustChange ? 'blur-wrap' : '' ?>">

      <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container-xl">
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="topbar-mobile-btn d-inline-flex d-lg-none" id="sidebarToggle" aria-label="Open menu">
              <i class="bi bi-list fs-4"></i>
            </button>
            <a class="navbar-brand fw-bold text-success m-0" href="homeowner_dashboard.php">HOA Community</a>
          </div>

          <div class="ms-auto d-flex align-items-center gap-2 gap-md-3">
            <div class="small text-muted desktop-user-text">
              Logged in as <b><?= esc($fullName) ?></b> (<?= esc($phase) ?>)
            </div>
            <a href="logout.php" class="btn btn-sm btn-outline-success">Logout</a>
          </div>
        </div>
      </nav>

      <div class="container-xl my-4">

        <div class="mobile-user-strip">
          <div class="alert alert-light border shadow-sm mb-3">
            <div class="fw-bold"><?= esc($fullName) ?></div>
            <div class="small text-muted"><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?></div>
          </div>
        </div>

        <div class="chat-shell">
          <div class="chat-side-card">
            <div class="d-flex align-items-center gap-3 mb-3">
              <div class="sb-avatar" style="width:52px;height:52px;"><?= esc($initials) ?></div>
              <div>
                <div class="fw-bold"><?= esc($fullName) ?></div>
                <div class="text-muted small"><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?></div>
              </div>
            </div>

            <div class="member-box">
              <h6>Chat Rules</h6>
              <div class="small text-muted fw-semibold">
                Be respectful, avoid spam, and keep conversations related to your community.
              </div>
            </div>

            <div class="member-box mt-3">
              <h6>Visible to</h6>
              <div class="member-mini">
                <div class="member-mini-avatar"><i class="bi bi-house-door-fill"></i></div>
                <div>
                  <div class="fw-bold"><?= esc($phase) ?> Homeowners</div>
                  <div class="small text-muted">Public chat messages stay inside your phase only.</div>
                </div>
              </div>
              <div class="member-mini">
                <div class="member-mini-avatar"><i class="bi bi-person-badge-fill"></i></div>
                <div>
                  <div class="fw-bold">Homeowner Officers</div>
                  <div class="small text-muted">Private officer chat is only between you and the selected officer.</div>
                </div>
              </div>
            </div>

            <div class="member-box mt-3">
              <h6>Select Officer</h6>
              <?php if (!empty($officers)): ?>
                <select class="form-select" id="officerSelect">
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
                <div class="small text-muted mt-2">
                  Choose which officer position you want to message privately.
                </div>
              <?php else: ?>
                <div class="small text-muted fw-semibold">No officers available in your phase yet.</div>
              <?php endif; ?>
            </div>

            <?php if ($isMuted): ?>
              <div class="member-box mt-3 border-danger-subtle" style="background:#fef2f2;">
                <h6 class="text-danger mb-2">Public Chat Restriction</h6>
                <div class="small fw-semibold text-danger">
                  You are currently muted from sending messages in the public chat.
                </div>
                <?php if ($muteReason !== ''): ?>
                  <div class="small text-muted mt-2">
                    Reason: <?= esc($muteReason) ?>
                  </div>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-danger btn-sm mt-3" id="btnOpenMutedInfo">
                  View Details
                </button>
              </div>
            <?php endif; ?>
          </div>

          <div class="chat-main-card">
            <div class="chat-head">
              <div>
                <h1 class="chat-room-title mb-1" id="chatRoomTitle">Public Community Chat</h1>
                <div class="chat-room-sub" id="chatRoomSub">Talk with other homeowners in <?= esc($phase) ?></div>

                <div class="chat-mode-switch">
                  <button type="button" class="chat-mode-btn active" id="modePublicBtn">
                    <i class="bi bi-people-fill me-1"></i> Public Chat
                  </button>
                  <button type="button" class="chat-mode-btn" id="modeOfficerBtn">
                    <i class="bi bi-person-badge-fill me-1"></i> Officer Chat
                  </button>
                </div>
              </div>

              <div class="phase-badge" id="phaseBadge">
                <i class="bi bi-chat-dots-fill"></i>
                <?= esc($phase) ?>
              </div>
            </div>

            <div class="chat-body" id="chatBody">
              <?php if ($isMuted): ?>
                <div class="mute-state-card" id="publicMuteCard">
                  <div class="title"><i class="bi bi-slash-circle-fill me-1"></i> You are muted from public chat</div>
                  <div class="small mb-2">
                    You can still read messages, but you cannot send new ones right now.
                  </div>
                  <?php if ($muteReason !== ''): ?>
                    <div class="small"><b>Reason:</b> <?= esc($muteReason) ?></div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <div class="chat-empty" id="chatEmpty">
                <div>
                  <div class="fs-5 mb-2">No messages yet</div>
                  <div>Be the first to start the conversation.</div>
                </div>
              </div>
            </div>

            <div class="chat-foot">
              <form class="chat-form" id="chatForm">
                <textarea
                  class="chat-input"
                  id="chatMessage"
                  placeholder="<?= $isMuted ? 'You are muted from sending public messages.' : 'Type your message here...' ?>"
                  maxlength="1000"
                  required
                ></textarea>
                <button type="submit" class="btn-send-chat" id="sendBtn" title="Send">
                  <i class="bi bi-send-fill"></i>
                </button>
              </form>
              <div class="chat-tip" id="chatTip">
                Max 500 characters. Everyone in <?= esc($phase) ?> can see your message.
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <?php if ($mustChange): ?>
      <div class="lock-overlay">
        <div class="lock-modal">
          <div class="head">
            <i class="bi bi-shield-lock-fill fs-5"></i>
            <div>
              <div class="fw-bold">Change Password Required</div>
              <div class="small opacity-75">You must change your password before continuing.</div>
            </div>
          </div>

          <div class="body">
            <div class="lock-note mb-3">
              <div class="fw-semibold mb-1">Security check</div>
              <div class="small">This is your first login. Please set a new password (min 8 characters).</div>
            </div>

            <?php if ($err): ?>
              <div class="alert alert-danger"><?= esc($err) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
              <input type="hidden" name="change_password_submit" value="1">
              <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" name="password" class="form-control" minlength="8" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="password2" class="form-control" minlength="8" required>
              </div>
              <button class="btn btn-success w-100 py-2 fw-semibold">Save Password</button>
            </form>

            <div class="small text-muted mt-3">Tip: Use a strong password (letters + numbers).</div>
          </div>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<!-- MUTED INFO MODAL -->
<div class="modalx" id="mutedInfoModal">
  <div class="box">
    <div class="boxhead">
      <div class="fw-bold">Public Chat Restriction</div>
      <button class="closebtn" type="button" id="closeMutedInfoModal">&times;</button>
    </div>

    <div class="boxbody">
      <div class="notice-text mb-3">
        You are currently muted from sending messages in the public chat for <b><?= esc($phase) ?></b>.
      </div>

      <div class="member-box" style="background:#fef2f2;border-color:#fecaca;">
        <div class="small fw-bold text-danger mb-1">What this means</div>
        <div class="small text-muted">
          You can still open the public chat and read messages from other homeowners, but the message box and send button are disabled until an admin removes the mute.
        </div>
      </div>

      <?php if ($muteReason !== ''): ?>
        <div class="member-box mt-3">
          <div class="small fw-bold mb-1">Reason</div>
          <div class="small text-muted"><?= esc($muteReason) ?></div>
        </div>
      <?php endif; ?>
    </div>

    <div class="boxfoot">
      <button type="button" class="btn btn-primary" id="okMutedInfoBtn">OK</button>
    </div>
  </div>
</div>

<!-- NOTICE MODAL -->
<div class="modalx" id="noticeModal">
  <div class="box">
    <div class="boxhead">
      <div class="fw-bold" id="noticeModalTitle">Notice</div>
      <button class="closebtn" type="button" id="closeNoticeModal">&times;</button>
    </div>

    <div class="boxbody">
      <div class="notice-text" id="noticeModalMessage">Message goes here.</div>
    </div>

    <div class="boxfoot">
      <button type="button" class="btn btn-primary" id="okNoticeBtn">OK</button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
async function postJSON(action, payload){
  const fd = new FormData();
  fd.append('action', action);
  for (const [k,v] of Object.entries(payload || {})) fd.append(k, v);

  const res = await fetch('homeowner_public_chat.php', {
    method:'POST',
    body: fd
  });
  return await res.json();
}

const chatBody = document.getElementById('chatBody');
const chatForm = document.getElementById('chatForm');
const chatMessage = document.getElementById('chatMessage');
const sendBtn = document.getElementById('sendBtn');
const chatRoomTitle = document.getElementById('chatRoomTitle');
const chatRoomSub = document.getElementById('chatRoomSub');
const chatTip = document.getElementById('chatTip');
const modePublicBtn = document.getElementById('modePublicBtn');
const modeOfficerBtn = document.getElementById('modeOfficerBtn');
const officerSelect = document.getElementById('officerSelect');

const isPublicMuted = <?= $isMuted ? 'true' : 'false' ?>;
const muteReason = <?= json_encode($muteReason) ?>;
const phase = <?= json_encode($phase) ?>;
const defaultOfficerId = <?= (int)$defaultOfficerId ?>;

let currentMode = 'public';
let selectedOfficerId = defaultOfficerId;
let publicLastId = 0;
let officerLastId = 0;
let isFetching = false;

if (officerSelect && officerSelect.value) {
  selectedOfficerId = Number(officerSelect.value || 0);
}

function escapeHtml(str){
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

function isNearBottom(el){
  return (el.scrollHeight - el.scrollTop - el.clientHeight) < 120;
}

function scrollToBottom(){
  chatBody.scrollTop = chatBody.scrollHeight;
}

function clearChatBody(emptyText = 'No messages yet'){
  chatBody.innerHTML = `
    <div class="chat-empty" id="chatEmpty">
      <div>
        <div class="fs-5 mb-2">No messages yet</div>
        <div>${escapeHtml(emptyText)}</div>
      </div>
    </div>
  `;
}

function renderPublicMessage(m){
  const row = document.createElement('div');
  row.className = 'msg-row' + (m.mine ? ' mine' : '');
  row.setAttribute('data-id', m.id);

  row.innerHTML = `
    <div class="msg-avatar">${escapeHtml(m.initials || 'H')}</div>
    <div class="msg-bubble-wrap">
      <div class="msg-meta">
        <span class="msg-name">${escapeHtml(m.mine ? 'You' : m.name)}</span>
        <span>${escapeHtml(m.lot || '')}</span>
        <span>•</span>
        <span>${escapeHtml(m.created_at || '')}</span>
      </div>
      <div class="msg-bubble">${escapeHtml(m.message || '').replace(/\n/g, '<br>')}</div>
    </div>
  `;
  return row;
}

function renderOfficerMessage(m){
  const row = document.createElement('div');
  row.className = 'msg-row' + (m.mine ? ' mine' : ' officer-row');
  row.setAttribute('data-id', m.id);

  row.innerHTML = `
    <div class="msg-avatar ${m.mine ? '' : 'officer'}">${escapeHtml(m.initials || 'O')}</div>
    <div class="msg-bubble-wrap">
      <div class="msg-meta">
        <span class="msg-name">${escapeHtml(m.name || 'Officer')}</span>
        <span>${escapeHtml(m.role || '')}</span>
        <span>•</span>
        <span>${escapeHtml(m.created_at || '')}</span>
      </div>
      <div class="msg-bubble">${escapeHtml(m.message || '').replace(/\n/g, '<br>')}</div>
    </div>
  `;
  return row;
}

function updateComposerState(){
  if (currentMode === 'public') {
    modePublicBtn.classList.add('active');
    modeOfficerBtn.classList.remove('active');
    sendBtn.classList.remove('officer-mode');
    chatRoomTitle.textContent = 'Public Community Chat';
    chatRoomSub.textContent = `Talk with other homeowners in ${phase}`;
    chatTip.textContent = `Max 500 characters. Everyone in ${phase} can see your message.`;
    chatMessage.maxLength = 500;

    if (isPublicMuted) {
      chatMessage.placeholder = 'You are muted from sending public messages.';
      chatMessage.disabled = true;
      sendBtn.disabled = true;
    } else {
      chatMessage.placeholder = 'Type your public message here...';
      chatMessage.disabled = false;
      sendBtn.disabled = false;
    }
  } else {
    modeOfficerBtn.classList.add('active');
    modePublicBtn.classList.remove('active');
    sendBtn.classList.add('officer-mode');
    chatRoomTitle.textContent = 'Officer Private Chat';

    const selectedOption = officerSelect?.selectedOptions?.[0] || null;
    const officerPosition = selectedOption?.dataset.position || 'Officer';
    chatRoomSub.textContent = `Private conversation with ${officerPosition}`;

    chatTip.textContent = 'Max 1000 characters. Only you and the selected officer can see this conversation.';
    chatMessage.maxLength = 1000;

    if (!selectedOfficerId) {
      chatMessage.placeholder = 'No officer available.';
      chatMessage.disabled = true;
      sendBtn.disabled = true;
    } else {
      chatMessage.placeholder = 'Type your private message to the officer...';
      chatMessage.disabled = false;
      sendBtn.disabled = false;
    }
  }
}

async function loadPublicMessages(initial = false){
  if (isFetching) return;
  isFetching = true;

  const shouldStickBottom = isNearBottom(chatBody) || initial;

  try {
    const r = await postJSON('fetch_messages', { last_id: initial ? 0 : publicLastId });
    if (!r.success) return;

    const msgs = Array.isArray(r.messages) ? r.messages : [];

    if (initial) {
      chatBody.innerHTML = '';
      if (isPublicMuted) {
        const muteDiv = document.createElement('div');
        muteDiv.className = 'mute-state-card';
        muteDiv.innerHTML = `
          <div class="title"><i class="bi bi-slash-circle-fill me-1"></i> You are muted from public chat</div>
          <div class="small mb-2">You can still read messages, but you cannot send new ones right now.</div>
          ${muteReason ? `<div class="small"><b>Reason:</b> ${escapeHtml(muteReason)}</div>` : ''}
        `;
        chatBody.appendChild(muteDiv);
      }
    }

    if (msgs.length > 0) {
      const oldEmpty = document.getElementById('chatEmpty');
      if (oldEmpty) oldEmpty.remove();

      msgs.forEach(m => {
        if (!document.querySelector(`.msg-row[data-id="${m.id}"]`)) {
          chatBody.appendChild(renderPublicMessage(m));
        }
        if (Number(m.id) > publicLastId) publicLastId = Number(m.id);
      });

      if (shouldStickBottom) scrollToBottom();
    } else if (initial) {
      if (!document.querySelector('.msg-row')) {
        const empty = document.createElement('div');
        empty.className = 'chat-empty';
        empty.id = 'chatEmpty';
        empty.innerHTML = `
          <div>
            <div class="fs-5 mb-2">No messages yet</div>
            <div>Be the first to start the conversation in ${escapeHtml(phase)}.</div>
          </div>
        `;
        chatBody.appendChild(empty);
      }
      publicLastId = 0;
    }
  } catch (e) {
    console.error(e);
  } finally {
    isFetching = false;
  }
}

async function loadOfficerMessages(initial = false){
  if (!selectedOfficerId) {
    clearChatBody('No officer available for your phase.');
    return;
  }

  if (isFetching) return;
  isFetching = true;

  const shouldStickBottom = isNearBottom(chatBody) || initial;

  try {
    const r = await postJSON('fetch_officer_messages', {
      admin_id: selectedOfficerId,
      last_id: initial ? 0 : officerLastId
    });

    if (!r.success) {
      if (initial) clearChatBody(r.message || 'Unable to load officer conversation.');
      return;
    }

    const msgs = Array.isArray(r.messages) ? r.messages : [];

    if (initial) {
      chatBody.innerHTML = '';
    }

    if (msgs.length > 0) {
      const oldEmpty = document.getElementById('chatEmpty');
      if (oldEmpty) oldEmpty.remove();

      msgs.forEach(m => {
        if (!document.querySelector(`.msg-row[data-id="${m.id}"]`)) {
          chatBody.appendChild(renderOfficerMessage(m));
        }
        if (Number(m.id) > officerLastId) officerLastId = Number(m.id);
      });

      if (shouldStickBottom) scrollToBottom();
    } else if (initial) {
      const selectedOption = officerSelect?.selectedOptions?.[0] || null;
      const officerPosition = selectedOption?.dataset.position || 'officer';
      clearChatBody(`Start a private conversation with ${officerPosition}.`);
      officerLastId = 0;
    }
  } catch (e) {
    console.error(e);
  } finally {
    isFetching = false;
  }
}

async function switchMode(mode){
  currentMode = mode;
  officerLastId = 0;
  updateComposerState();

  if (mode === 'public') {
    await loadPublicMessages(true);
  } else {
    await loadOfficerMessages(true);
  }
}

modePublicBtn?.addEventListener('click', () => switchMode('public'));
modeOfficerBtn?.addEventListener('click', () => switchMode('officer'));

officerSelect?.addEventListener('change', async function(){
  selectedOfficerId = Number(this.value || 0);
  officerLastId = 0;

  if (currentMode === 'officer') {
    updateComposerState();
    await loadOfficerMessages(true);
  }
});

/* =========================
   NOTICE MODAL
   ========================= */
const noticeModal = document.getElementById('noticeModal');
const noticeModalTitle = document.getElementById('noticeModalTitle');
const noticeModalMessage = document.getElementById('noticeModalMessage');
const closeNoticeModal = document.getElementById('closeNoticeModal');
const okNoticeBtn = document.getElementById('okNoticeBtn');

function openNoticeModal(title, message) {
  noticeModalTitle.textContent = title || 'Notice';
  noticeModalMessage.textContent = message || '';
  noticeModal.style.display = 'flex';
}

function closeNoticeDialog() {
  noticeModal.style.display = 'none';
}

closeNoticeModal?.addEventListener('click', closeNoticeDialog);
okNoticeBtn?.addEventListener('click', closeNoticeDialog);
noticeModal?.addEventListener('click', function(e) {
  if (e.target === noticeModal) closeNoticeDialog();
});

/* =========================
   MUTED INFO MODAL
   ========================= */
const mutedInfoModal = document.getElementById('mutedInfoModal');
const btnOpenMutedInfo = document.getElementById('btnOpenMutedInfo');
const closeMutedInfoModal = document.getElementById('closeMutedInfoModal');
const okMutedInfoBtn = document.getElementById('okMutedInfoBtn');

function openMutedInfoModal() {
  if (!mutedInfoModal) return;
  mutedInfoModal.style.display = 'flex';
}

function closeMutedInfoDialog() {
  if (!mutedInfoModal) return;
  mutedInfoModal.style.display = 'none';
}

btnOpenMutedInfo?.addEventListener('click', openMutedInfoModal);
closeMutedInfoModal?.addEventListener('click', closeMutedInfoDialog);
okMutedInfoBtn?.addEventListener('click', closeMutedInfoDialog);
mutedInfoModal?.addEventListener('click', function(e) {
  if (e.target === mutedInfoModal) closeMutedInfoDialog();
});

chatForm?.addEventListener('submit', async function(e){
  e.preventDefault();

  const message = (chatMessage.value || '').trim();
  if (!message) return;

  if (currentMode === 'public') {
    if (chatMessage.disabled || sendBtn.disabled) {
      openMutedInfoModal();
      return;
    }

    sendBtn.disabled = true;
    try {
      const r = await postJSON('send_message', { message });
      if (!r.success) {
        openNoticeModal('Unable to Send', r.message || 'Failed to send message.');
        return;
      }

      chatMessage.value = '';
      await loadPublicMessages(false);
      scrollToBottom();
    } catch (e) {
      openNoticeModal('Unable to Send', 'Failed to send message.');
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
    const r = await postJSON('send_officer_message', {
      admin_id: selectedOfficerId,
      message
    });

    if (!r.success) {
      openNoticeModal('Unable to Send', r.message || 'Failed to send message to officer.');
      return;
    }

    chatMessage.value = '';
    await loadOfficerMessages(false);
    scrollToBottom();
  } catch (e) {
    openNoticeModal('Unable to Send', 'Failed to send message to officer.');
  } finally {
    if (!chatMessage.disabled) sendBtn.disabled = false;
    chatMessage.focus();
  }
});

chatMessage?.addEventListener('keydown', function(e){
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    chatForm.requestSubmit();
  }
});

(function(){
  const wrap = document.getElementById('sbParking');
  const btn  = document.getElementById('sbParkingToggle');
  if(!wrap || !btn) return;
  btn.addEventListener('click', () => wrap.classList.toggle('open'));
})();

(function(){
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const toggle  = document.getElementById('sidebarToggle');

  if (!sidebar || !overlay || !toggle) return;

  function openSidebar(){
    sidebar.classList.add('show');
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar(){
    sidebar.classList.remove('show');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
  }

  toggle.addEventListener('click', openSidebar);
  overlay.addEventListener('click', closeSidebar);

  window.addEventListener('resize', function(){
    if (window.innerWidth >= 992) closeSidebar();
  });

  sidebar.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', function(){
      if (window.innerWidth < 992) closeSidebar();
    });
  });
})();

updateComposerState();
loadPublicMessages(true);

setInterval(() => {
  if (currentMode === 'public') {
    loadPublicMessages(false);
  } else {
    loadOfficerMessages(false);
  }
}, 4000);
</script>
</body>
</html>