<?php
session_start();
require_once 'admin_access.php';
requireAccess('community');

/* =========================
   1) AUTH GUARD
   ========================= */
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)) {
  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
  exit;
}

/* Superadmin is not allowed here */
if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
  echo "<script>alert('Superadmin cannot access President Dashboard.'); window.location='index.php';</script>";
  exit;
}

/* =========================
   2) DB
   ========================= */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db_host = "localhost";
$db_user = "u972459197_patrick";
$db_pass = "Idle2440";
$db_name = "u972459197_south_meridian";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

function esc($v){
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* =========================
   3) ADMIN INFO
   ========================= */
$adminId = (int)($_SESSION['admin_id'] ?? 0);

$stmt = $conn->prepare("SELECT id, email, full_name, phase, role, position FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$me) {
  session_destroy();
  echo "<script>alert('Session error. Please login again.'); window.location='index.php';</script>";
  exit;
}

$adminEmail = (string)($me['email'] ?? '');
$adminName  = trim((string)($me['full_name'] ?? ''));
$myPhase    = (string)($me['phase'] ?? 'Phase 1');
$myPosition = trim((string)($me['position'] ?? 'Officer'));

$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';

/* permissions fallback for sidebar script */
$permissions = $permissions ?? [];

/* =========================
   4) AJAX ACTIONS
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json; charset=utf-8');

  $action = (string)($_POST['action'] ?? '');

  if ($action === 'fetch_messages') {
    $lastId = (int)($_POST['last_id'] ?? 0);

    if ($lastId > 0) {
      $stmt = $conn->prepare("
        SELECT
          pcm.id,
          pcm.phase,
          pcm.homeowner_id,
          pcm.message,
          pcm.created_at,
          h.first_name,
          h.middle_name,
          h.last_name,
          h.house_lot_number,
          h.email,
          COALESCE(pm.is_muted, 0) AS is_muted,
          COALESCE(pm.reason, '') AS mute_reason
        FROM public_chat_messages pcm
        JOIN homeowners h ON h.id = pcm.homeowner_id
        LEFT JOIN public_chat_mutes pm
          ON pm.homeowner_id = pcm.homeowner_id
         AND pm.phase = pcm.phase
        WHERE pcm.phase=? AND pcm.id > ?
        ORDER BY pcm.id ASC
      ");
      $stmt->bind_param("si", $phase, $lastId);
    } else {
      $stmt = $conn->prepare("
        SELECT * FROM (
          SELECT
            pcm.id,
            pcm.phase,
            pcm.homeowner_id,
            pcm.message,
            pcm.created_at,
            h.first_name,
            h.middle_name,
            h.last_name,
            h.house_lot_number,
            h.email,
            COALESCE(pm.is_muted, 0) AS is_muted,
            COALESCE(pm.reason, '') AS mute_reason
          FROM public_chat_messages pcm
          JOIN homeowners h ON h.id = pcm.homeowner_id
          LEFT JOIN public_chat_mutes pm
            ON pm.homeowner_id = pcm.homeowner_id
           AND pm.phase = pcm.phase
          WHERE pcm.phase=?
          ORDER BY pcm.id DESC
          LIMIT 100
        ) x
        ORDER BY x.id ASC
      ");
      $stmt->bind_param("s", $phase);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $messages = [];
    while ($r = $res->fetch_assoc()) {
      $full = trim(
        (string)($r['first_name'] ?? '') . ' ' .
        (string)($r['middle_name'] ?? '') . ' ' .
        (string)($r['last_name'] ?? '')
      );

      $messages[] = [
        'id' => (int)$r['id'],
        'homeowner_id' => (int)$r['homeowner_id'],
        'name' => $full,
        'email' => (string)($r['email'] ?? ''),
        'lot' => (string)($r['house_lot_number'] ?? ''),
        'message' => (string)($r['message'] ?? ''),
        'created_at' => date('M d, Y h:i A', strtotime((string)$r['created_at'])),
        'is_muted' => ((int)$r['is_muted'] === 1),
        'mute_reason' => (string)($r['mute_reason'] ?? '')
      ];
    }
    $stmt->close();

    echo json_encode([
      'success' => true,
      'messages' => $messages
    ]);
    exit;
  }

  if ($action === 'delete_message') {
    $messageId = (int)($_POST['message_id'] ?? 0);
    if ($messageId <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid message ID.']);
      exit;
    }

    $stmt = $conn->prepare("DELETE FROM public_chat_messages WHERE id=? AND phase=? LIMIT 1");
    $stmt->bind_param("is", $messageId, $phase);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    echo json_encode([
      'success' => ($ok && $affected > 0),
      'message' => ($ok && $affected > 0) ? 'Message deleted.' : 'Message not found or already deleted.'
    ]);
    exit;
  }

  if ($action === 'mute_homeowner') {
    $homeownerId = (int)($_POST['homeowner_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));

    if ($homeownerId <= 0) {
      echo json_encode(['success'=>false,'message'=>'Invalid homeowner.']);
      exit;
    }

    $stmt = $conn->prepare("
      SELECT id
      FROM homeowners
      WHERE id=? AND phase=? AND status='approved'
      LIMIT 1
    ");
    $stmt->bind_param("is", $homeownerId, $phase);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$exists) {
      echo json_encode(['success'=>false,'message'=>'Homeowner not found in this phase.']);
      exit;
    }

    $stmt = $conn->prepare("
      INSERT INTO public_chat_mutes (homeowner_id, phase, is_muted, reason, muted_by_admin_id, muted_at)
      VALUES (?, ?, 1, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        is_muted = 1,
        reason = VALUES(reason),
        muted_by_admin_id = VALUES(muted_by_admin_id),
        muted_at = NOW()
    ");
    $stmt->bind_param("issi", $homeownerId, $phase, $reason, $adminId);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode([
      'success' => $ok,
      'message' => $ok ? 'Homeowner muted from public chat.' : 'Failed to mute homeowner.'
    ]);
    exit;
  }

  if ($action === 'unmute_homeowner') {
    $homeownerId = (int)($_POST['homeowner_id'] ?? 0);

    if ($homeownerId <= 0) {
      echo json_encode(['success'=>false,'message'=>'Invalid homeowner.']);
      exit;
    }

    $stmt = $conn->prepare("
      UPDATE public_chat_mutes
      SET is_muted=0, reason=NULL, muted_by_admin_id=?, muted_at=NOW()
      WHERE homeowner_id=? AND phase=?
    ");
    $stmt->bind_param("iis", $adminId, $homeownerId, $phase);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode([
      'success' => $ok,
      'message' => $ok ? 'Homeowner unmuted.' : 'Failed to unmute homeowner.'
    ]);
    exit;
  }

  /* =========================
     OFFICER PRIVATE CHAT
     ========================= */

  if ($action === 'fetch_officer_threads') {
    $stmt = $conn->prepare("
      SELECT
        h.id AS homeowner_id,
        h.first_name,
        h.middle_name,
        h.last_name,
        h.house_lot_number,
        h.email,
        MAX(hom.created_at) AS last_message_at,
        SUM(CASE WHEN hom.sender_type='homeowner' AND hom.is_read_by_admin=0 THEN 1 ELSE 0 END) AS unread_count,
        (
          SELECT hom2.message
          FROM homeowner_officer_messages hom2
          WHERE hom2.phase = ?
            AND hom2.admin_id = ?
            AND hom2.homeowner_id = h.id
          ORDER BY hom2.id DESC
          LIMIT 1
        ) AS last_message
      FROM homeowner_officer_messages hom
      JOIN homeowners h ON h.id = hom.homeowner_id
      WHERE hom.phase = ?
        AND hom.admin_id = ?
      GROUP BY h.id, h.first_name, h.middle_name, h.last_name, h.house_lot_number, h.email
      ORDER BY last_message_at DESC, h.last_name ASC, h.first_name ASC
    ");
    $stmt->bind_param("sisi", $phase, $adminId, $phase, $adminId);
    $stmt->execute();
    $res = $stmt->get_result();

    $threads = [];
    while ($r = $res->fetch_assoc()) {
      $full = trim(
        (string)($r['first_name'] ?? '') . ' ' .
        (string)($r['middle_name'] ?? '') . ' ' .
        (string)($r['last_name'] ?? '')
      );

      $threads[] = [
        'homeowner_id'   => (int)$r['homeowner_id'],
        'name'           => $full,
        'email'          => (string)($r['email'] ?? ''),
        'lot'            => (string)($r['house_lot_number'] ?? ''),
        'last_message'   => (string)($r['last_message'] ?? ''),
        'last_message_at'=> $r['last_message_at'] ? date('M d, Y h:i A', strtotime((string)$r['last_message_at'])) : '',
        'unread_count'   => (int)($r['unread_count'] ?? 0)
      ];
    }
    $stmt->close();

    echo json_encode([
      'success' => true,
      'threads' => $threads
    ]);
    exit;
  }

  if ($action === 'fetch_officer_conversation') {
    $homeownerId = (int)($_POST['homeowner_id'] ?? 0);
    $lastId = (int)($_POST['last_id'] ?? 0);

    if ($homeownerId <= 0) {
      echo json_encode(['success'=>false,'message'=>'Invalid homeowner.']);
      exit;
    }

    $stmt = $conn->prepare("
      SELECT id, first_name, middle_name, last_name, house_lot_number, email
      FROM homeowners
      WHERE id=? AND phase=? AND status='approved'
      LIMIT 1
    ");
    $stmt->bind_param("is", $homeownerId, $phase);
    $stmt->execute();
    $homeowner = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$homeowner) {
      echo json_encode(['success'=>false,'message'=>'Homeowner not found.']);
      exit;
    }

    if ($lastId > 0) {
      $stmt = $conn->prepare("
        SELECT id, sender_type, message, created_at
        FROM homeowner_officer_messages
        WHERE phase=?
          AND admin_id=?
          AND homeowner_id=?
          AND id > ?
        ORDER BY id ASC
      ");
      $stmt->bind_param("siii", $phase, $adminId, $homeownerId, $lastId);
    } else {
      $stmt = $conn->prepare("
        SELECT * FROM (
          SELECT id, sender_type, message, created_at
          FROM homeowner_officer_messages
          WHERE phase=?
            AND admin_id=?
            AND homeowner_id=?
          ORDER BY id DESC
          LIMIT 80
        ) x
        ORDER BY x.id ASC
      ");
      $stmt->bind_param("sii", $phase, $adminId, $homeownerId);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $messages = [];
    while ($r = $res->fetch_assoc()) {
      $messages[] = [
        'id' => (int)$r['id'],
        'mine' => ((string)$r['sender_type'] === 'admin'),
        'sender_type' => (string)$r['sender_type'],
        'message' => (string)$r['message'],
        'created_at' => date('M d, Y h:i A', strtotime((string)$r['created_at']))
      ];
    }
    $stmt->close();

    $stmt = $conn->prepare("
      UPDATE homeowner_officer_messages
      SET is_read_by_admin = 1
      WHERE phase=?
        AND admin_id=?
        AND homeowner_id=?
        AND sender_type='homeowner'
        AND is_read_by_admin=0
    ");
    $stmt->bind_param("sii", $phase, $adminId, $homeownerId);
    $stmt->execute();
    $stmt->close();

    $full = trim(
      (string)($homeowner['first_name'] ?? '') . ' ' .
      (string)($homeowner['middle_name'] ?? '') . ' ' .
      (string)($homeowner['last_name'] ?? '')
    );

    echo json_encode([
      'success' => true,
      'homeowner' => [
        'id' => (int)$homeowner['id'],
        'name' => $full,
        'lot' => (string)($homeowner['house_lot_number'] ?? ''),
        'email' => (string)($homeowner['email'] ?? '')
      ],
      'messages' => $messages
    ]);
    exit;
  }

  if ($action === 'send_officer_reply') {
    $homeownerId = (int)($_POST['homeowner_id'] ?? 0);
    $message = trim((string)($_POST['message'] ?? ''));
    $message = preg_replace('/\s+/', ' ', $message);

    if ($homeownerId <= 0) {
      echo json_encode(['success'=>false,'message'=>'Invalid homeowner.']);
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
      SELECT id
      FROM homeowners
      WHERE id=? AND phase=? AND status='approved'
      LIMIT 1
    ");
    $stmt->bind_param("is", $homeownerId, $phase);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$exists) {
      echo json_encode(['success'=>false,'message'=>'Homeowner not found in this phase.']);
      exit;
    }

    $stmt = $conn->prepare("
      INSERT INTO homeowner_officer_messages
      (phase, homeowner_id, admin_id, sender_type, message, is_read_by_homeowner, is_read_by_admin)
      VALUES (?, ?, ?, 'admin', ?, 0, 1)
    ");
    $stmt->bind_param("siis", $phase, $homeownerId, $adminId, $message);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode([
      'success' => $ok,
      'message' => $ok ? 'Reply sent.' : 'Failed to send reply.'
    ]);
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action.']);
  exit;
}

/* =========================
   5) PAGE COUNTS
   ========================= */
$stmt = $conn->prepare("SELECT COUNT(*) c FROM public_chat_messages WHERE phase=?");
$stmt->bind_param("s", $phase);
$stmt->execute();
$totalMessages = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("
  SELECT COUNT(DISTINCT homeowner_id) c
  FROM public_chat_messages
  WHERE phase=?
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$activeChatters = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("
  SELECT COUNT(*) c
  FROM homeowners
  WHERE phase=? AND status='approved'
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$approvedHomeowners = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("
  SELECT COUNT(*) c
  FROM public_chat_mutes
  WHERE phase=? AND is_muted=1
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$mutedCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("
  SELECT COUNT(DISTINCT homeowner_id) c
  FROM homeowner_officer_messages
  WHERE phase=? AND admin_id=?
");
$stmt->bind_param("si", $phase, $adminId);
$stmt->execute();
$privateThreadsCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("
  SELECT COUNT(*) c
  FROM homeowner_officer_messages
  WHERE phase=?
    AND admin_id=?
    AND sender_type='homeowner'
    AND is_read_by_admin=0
");
$stmt->bind_param("si", $phase, $adminId);
$stmt->execute();
$privateUnreadCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Community Chat Monitor</title>

  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

  <style>
    .chat-stats .card-box { min-height: 130px; }
    .stat-value { font-size: 30px; font-weight: 800; line-height: 1; }
    .stat-label { color: #64748b; font-weight: 700; }

    .mode-switch{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      margin-bottom:20px;
    }
    .mode-btn{
      border:1px solid #dbe3ea;
      background:#fff;
      color:#0f172a;
      border-radius:12px;
      padding:10px 16px;
      font-weight:800;
      cursor:pointer;
    }
    .mode-btn.active{
      background:#16a34a;
      color:#fff;
      border-color:#16a34a;
    }

    .chat-board {
      border: 1px solid #e5e7eb;
      border-radius: 18px;
      background: #fff;
      overflow: hidden;
    }

    .chat-board-head {
      padding: 16px 18px;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .chat-board-body {
      background: #f8fafc;
      min-height: 65vh;
      max-height: 70vh;
      overflow-y: auto;
      padding: 16px;
    }

    .chat-empty {
      text-align: center;
      color: #64748b;
      font-weight: 700;
      padding: 60px 20px;
    }

    .msg-item {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 14px;
      margin-bottom: 12px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, .04);
    }

    .msg-top {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 12px;
      flex-wrap: wrap;
    }

    .msg-name {
      font-weight: 800;
      font-size: 15px;
      color: #0f172a;
    }

    .msg-meta {
      color: #64748b;
      font-size: 12px;
      font-weight: 600;
    }

    .msg-text {
      margin-top: 10px;
      color: #0f172a;
      line-height: 1.45;
      white-space: pre-wrap;
      word-break: break-word;
    }

    .msg-actions {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
    }

    .pill-phase {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 7px 12px;
      border-radius: 999px;
      background: #ecfdf5;
      color: #166534;
      border: 1px solid #bbf7d0;
      font-weight: 800;
      font-size: 12px;
    }

    .pill-private {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 7px 12px;
      border-radius: 999px;
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
      font-weight: 800;
      font-size: 12px;
    }

    .refresh-note {
      color: #64748b;
      font-size: 12px;
      font-weight: 600;
    }

    .mute-badge{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:4px 10px;
      border-radius:999px;
      background:#fef2f2;
      color:#991b1b;
      border:1px solid #fecaca;
      font-weight:800;
      font-size:11px;
      margin-top:8px;
    }

    .private-shell{
      display:grid;
      grid-template-columns: 320px minmax(0,1fr);
      gap:20px;
    }

    .thread-list-card,
    .conversation-card{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:18px;
      overflow:hidden;
    }

    .thread-list-head,
    .conversation-head{
      padding:16px 18px;
      border-bottom:1px solid #e5e7eb;
      background:#fff;
    }

    .thread-list-body{
      max-height:72vh;
      overflow-y:auto;
      padding:12px;
      background:#f8fafc;
    }

    .thread-item{
      background:#fff;
      border:1px solid #e2e8f0;
      border-radius:14px;
      padding:12px;
      margin-bottom:10px;
      cursor:pointer;
      transition:.15s ease;
    }

    .thread-item:hover{
      border-color:#93c5fd;
      background:#f8fbff;
    }

    .thread-item.active{
      border-color:#2563eb;
      background:#eff6ff;
    }

    .thread-top{
      display:flex;
      justify-content:space-between;
      gap:10px;
      align-items:flex-start;
    }

    .thread-name{
      font-weight:800;
      color:#0f172a;
      font-size:14px;
    }

    .thread-sub{
      color:#64748b;
      font-size:12px;
      font-weight:600;
      margin-top:2px;
    }

    .thread-preview{
      color:#334155;
      font-size:13px;
      margin-top:8px;
      line-height:1.35;
      word-break:break-word;
    }

    .unread-badge{
      min-width:24px;
      height:24px;
      padding:0 8px;
      border-radius:999px;
      background:#ef4444;
      color:#fff;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-size:12px;
      font-weight:800;
    }

    .conversation-body{
      background:#f8fafc;
      min-height:55vh;
      max-height:55vh;
      overflow-y:auto;
      padding:16px;
    }

    .conv-row{
      display:flex;
      gap:10px;
      margin-bottom:14px;
      align-items:flex-end;
    }

    .conv-row.mine{
      flex-direction:row-reverse;
    }

    .conv-avatar{
      width:40px;
      height:40px;
      border-radius:50%;
      background:linear-gradient(135deg,#2563eb,#1d4ed8);
      color:#fff;
      display:flex;
      align-items:center;
      justify-content:center;
      font-weight:800;
      flex:0 0 40px;
    }

    .conv-row.mine .conv-avatar{
      background:linear-gradient(135deg,#16a34a,#15803d);
    }

    .conv-wrap{
      max-width:min(78%, 680px);
      min-width:0;
    }

    .conv-meta{
      font-size:12px;
      color:#64748b;
      font-weight:700;
      display:flex;
      gap:8px;
      flex-wrap:wrap;
      margin-bottom:4px;
    }

    .conv-row.mine .conv-meta{
      justify-content:flex-end;
    }

    .conv-name{
      font-weight:800;
      color:#0f172a;
    }

    .conv-bubble{
      background:#fff;
      border:1px solid #dbe3ea;
      border-radius:16px 16px 16px 6px;
      padding:12px 14px;
      color:#111827;
      font-weight:600;
      line-height:1.45;
      white-space:pre-wrap;
      word-break:break-word;
    }

    .conv-row.mine .conv-bubble{
      background:#16a34a;
      color:#fff;
      border-color:#16a34a;
      border-radius:16px 16px 6px 16px;
    }

    .conversation-foot{
      padding:14px 16px;
      border-top:1px solid #e5e7eb;
      background:#fff;
    }

    .reply-form{
      display:flex;
      gap:10px;
      align-items:flex-end;
    }

    .reply-input{
      flex:1;
      min-height:52px;
      max-height:140px;
      resize:vertical;
      border-radius:14px;
      border:1px solid #dbe3ea;
      padding:12px 14px;
      font-weight:600;
      outline:none;
    }

    .reply-send{
      height:52px;
      min-width:56px;
      border:0;
      border-radius:14px;
      background:linear-gradient(135deg,#2563eb,#1d4ed8);
      color:#fff;
      font-weight:800;
      padding:0 18px;
    }

    .reply-send[disabled]{
      background:#94a3b8;
      cursor:not-allowed;
    }

    .modalx {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.45);
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 16px;
    }

    .modalx .box {
      width: min(520px, 96vw);
      max-height: 92vh;
      background: #fff;
      border-radius: 16px;
      overflow: auto;
      box-shadow: 0 20px 60px rgba(0,0,0,.25);
    }

    .modalx .boxhead {
      padding: 14px 16px;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .modalx .closebtn {
      border: none;
      background: transparent;
      font-size: 22px;
      cursor: pointer;
      line-height: 1;
    }

    .modalx .boxbody {
      padding: 16px;
    }

    .modalx .form-label {
      font-weight: 700;
      margin-bottom: 6px;
      display: block;
    }

    .modalx textarea.form-control {
      min-height: 110px;
      resize: vertical;
    }

    .modalx .boxfoot {
      padding: 14px 16px;
      border-top: 1px solid #e5e7eb;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      flex-wrap: wrap;
    }

    .notice-text {
      font-size: 14px;
      line-height: 1.45;
      color: #334155;
    }

    .access-toast {
      position: fixed;
      top: 20px;
      right: 20px;
      background: #ef4444;
      color: #fff;
      padding: 12px 18px;
      border-radius: 8px;
      font-weight: 600;
      box-shadow: 0 6px 18px rgba(0,0,0,0.2);
      z-index: 99999;
      opacity: 0;
      transform: translateY(-10px);
      transition: all .3s ease;
    }

    .access-toast.show {
      opacity: 1;
      transform: translateY(0);
    }

    @media (max-width: 991.98px){
      .private-shell{
        grid-template-columns:1fr;
      }
    }

    @media (max-width: 767.98px){
      .chat-board-body {
        min-height: 60vh;
        max-height: 65vh;
        padding: 12px;
      }

      .conversation-body{
        min-height:50vh;
        max-height:50vh;
        padding:12px;
      }

      .msg-item { padding: 12px; }

      .msg-top {
        flex-direction: column;
        align-items: flex-start;
      }

      .msg-actions {
        width: 100%;
      }

      .msg-actions .btn {
        width: 100%;
      }

      .reply-form{
        gap:8px;
      }

      .reply-send{
        min-width:50px;
        padding:0 14px;
      }
    }
  </style>
</head>
<body>

  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
      <div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>
    </div>

    <div class="header-right">
      <div class="user-info-dropdown">
        <div class="dropdown">
          <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
            <span class="user-icon"><img src="vendors/images/photo1.jpg" alt=""></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="logout.php"><i class="dw dw-logout"></i> Log Out</a>
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
        <div class="row">
          <div class="col-md-12 col-sm-12">
            <div class="title"><h4>Community Chat Monitor</h4></div>
            <div class="text-secondary">
              Phase: <b><?= esc($phase) ?></b> |
              Admin: <b><?= esc($adminName !== '' ? $adminName : $adminEmail) ?></b> |
              Position: <b><?= esc($myPosition !== '' ? $myPosition : 'Officer') ?></b>
            </div>
          </div>
        </div>
      </div>

      <div class="row chat-stats">
        <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
          <div class="card-box pd-20">
            <div class="stat-label">Public Messages</div>
            <div class="stat-value"><?= (int)$totalMessages ?></div>
            <div class="text-secondary mt-2">All saved public chat</div>
          </div>
        </div>

        <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
          <div class="card-box pd-20">
            <div class="stat-label">Active Chatters</div>
            <div class="stat-value"><?= (int)$activeChatters ?></div>
            <div class="text-secondary mt-2">Distinct public chat users</div>
          </div>
        </div>

        <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
          <div class="card-box pd-20">
            <div class="stat-label">Approved Homes</div>
            <div class="stat-value"><?= (int)$approvedHomeowners ?></div>
            <div class="text-secondary mt-2">Possible public members</div>
          </div>
        </div>

        <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
          <div class="card-box pd-20">
            <div class="stat-label">Muted Users</div>
            <div class="stat-value"><?= (int)$mutedCount ?></div>
            <div class="text-secondary mt-2">Blocked from public chat</div>
          </div>
        </div>

        <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
          <div class="card-box pd-20">
            <div class="stat-label">Private Threads</div>
            <div class="stat-value"><?= (int)$privateThreadsCount ?></div>
            <div class="text-secondary mt-2">Officer inbox conversations</div>
          </div>
        </div>

        <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
          <div class="card-box pd-20">
            <div class="stat-label">Unread Private</div>
            <div class="stat-value"><?= (int)$privateUnreadCount ?></div>
            <div class="text-secondary mt-2">Unread homeowner messages</div>
          </div>
        </div>
      </div>

      <div class="mode-switch">
        <button type="button" class="mode-btn active" id="modePublicBtn">
          <i class="dw dw-chat3"></i> Public Chat Monitor
        </button>
        <button type="button" class="mode-btn" id="modeOfficerBtn">
          <i class="dw dw-message"></i> Officer Private Chat
        </button>
      </div>

      <div id="publicModeWrap">
        <div class="card-box pd-20 mb-30">
          <div class="chat-board">
            <div class="chat-board-head">
              <div>
                <h5 class="mb-1">Phase Public Chat</h5>
                <div class="refresh-note">Live refresh every 4 seconds</div>
              </div>
              <div class="d-flex align-items-center" style="gap:10px; flex-wrap:wrap;">
                <span class="pill-phase"><i class="dw dw-chat3"></i> <?= esc($phase) ?></span>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnRefreshNow">Refresh Now</button>
              </div>
            </div>

            <div class="chat-board-body" id="chatBoardBody">
              <div class="chat-empty" id="chatEmpty">No public chat messages yet.</div>
            </div>
          </div>
        </div>
      </div>

      <div id="officerModeWrap" style="display:none;">
        <div class="private-shell mb-30">
          <div class="thread-list-card">
            <div class="thread-list-head">
              <h5 class="mb-1">Private Chat Inbox</h5>
              <div class="refresh-note">Homeowners messaging <?= esc($myPosition) ?></div>
            </div>
            <div class="thread-list-body" id="threadListBody">
              <div class="chat-empty" id="threadEmpty">No private conversations yet.</div>
            </div>
          </div>

          <div class="conversation-card">
            <div class="conversation-head">
              <div class="d-flex justify-content-between align-items-start flex-wrap" style="gap:10px;">
                <div>
                  <h5 class="mb-1" id="convTitle">Officer Private Chat</h5>
                  <div class="refresh-note" id="convSub">Select a homeowner to open the conversation.</div>
                </div>
                <span class="pill-private"><i class="dw dw-user1"></i> <?= esc($myPosition) ?></span>
              </div>
            </div>

            <div class="conversation-body" id="conversationBody">
              <div class="chat-empty" id="conversationEmpty">Select a homeowner from the left to view messages.</div>
            </div>

            <div class="conversation-foot">
              <form id="replyForm" class="reply-form">
                <textarea
                  id="replyMessage"
                  class="reply-input"
                  placeholder="Select a homeowner first..."
                  maxlength="1000"
                  disabled
                  required
                ></textarea>
                <button type="submit" class="reply-send" id="replySendBtn" disabled>
                  <i class="dw dw-paper-plane1"></i>
                </button>
              </form>
              <div class="refresh-note mt-2">Max 1000 characters. Only you and that homeowner can see this conversation.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="footer-wrap pd-20 mb-20 card-box">
        © Copyright South Meridian Homes All Rights Reserved
      </div>
    </div>
  </div>

  <!-- MUTE MODAL -->
  <div class="modalx" id="muteModal">
    <div class="box">
      <div class="boxhead">
        <div class="font-weight-bold">Mute Homeowner</div>
        <button class="closebtn" type="button" id="closeMuteModal">&times;</button>
      </div>

      <div class="boxbody">
        <div class="mb-2 text-secondary">
          You are muting: <b id="muteHomeownerName">Homeowner</b>
        </div>

        <input type="hidden" id="muteHomeownerId">

        <div class="form-group mb-0">
          <label class="form-label" for="muteReason">Reason</label>
          <textarea
            id="muteReason"
            class="form-control"
            maxlength="255"
            placeholder="Enter reason for muting..."
          ></textarea>
          <small class="text-muted">This reason will be shown to the homeowner when they try to send a message.</small>
        </div>
      </div>

      <div class="boxfoot">
        <button type="button" class="btn btn-secondary" id="cancelMuteBtn">Cancel</button>
        <button type="button" class="btn btn-warning" id="confirmMuteBtn">Confirm Mute</button>
      </div>
    </div>
  </div>

  <!-- CONFIRM MODAL -->
  <div class="modalx" id="confirmModal">
    <div class="box">
      <div class="boxhead">
        <div class="font-weight-bold" id="confirmModalTitle">Confirm Action</div>
        <button class="closebtn" type="button" id="closeConfirmModal">&times;</button>
      </div>

      <div class="boxbody">
        <div class="notice-text" id="confirmModalMessage">Are you sure?</div>
      </div>

      <div class="boxfoot">
        <button type="button" class="btn btn-secondary" id="cancelConfirmBtn">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmActionBtn">Confirm</button>
      </div>
    </div>
  </div>

  <!-- NOTICE MODAL -->
  <div class="modalx" id="noticeModal">
    <div class="box">
      <div class="boxhead">
        <div class="font-weight-bold" id="noticeModalTitle">Notice</div>
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

  <script src="vendors/scripts/core.js"></script>
  <script src="vendors/scripts/script.min.js"></script>
  <script src="vendors/scripts/process.js"></script>
  <script src="vendors/scripts/layout-settings.js"></script>

  <script>
    const chatBoardBody = document.getElementById('chatBoardBody');
    const btnRefreshNow = document.getElementById('btnRefreshNow');

    const modePublicBtn = document.getElementById('modePublicBtn');
    const modeOfficerBtn = document.getElementById('modeOfficerBtn');
    const publicModeWrap = document.getElementById('publicModeWrap');
    const officerModeWrap = document.getElementById('officerModeWrap');

    const threadListBody = document.getElementById('threadListBody');
    const conversationBody = document.getElementById('conversationBody');
    const convTitle = document.getElementById('convTitle');
    const convSub = document.getElementById('convSub');
    const replyForm = document.getElementById('replyForm');
    const replyMessage = document.getElementById('replyMessage');
    const replySendBtn = document.getElementById('replySendBtn');

    let lastId = 0;
    let isFetching = false;

    let currentMode = 'public';

    let selectedHomeownerId = 0;
    let officerLastId = 0;
    let isOfficerFetching = false;

    function escapeHtml(str) {
      return String(str ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    async function postJSON(action, payload = {}) {
      const fd = new FormData();
      fd.append('action', action);
      Object.entries(payload).forEach(([k, v]) => fd.append(k, v));

      const res = await fetch('admin_public_chat.php', {
        method: 'POST',
        body: fd
      });
      return await res.json();
    }

    function renderMessageRow(m) {
      const wrap = document.createElement('div');
      wrap.className = 'msg-item';
      wrap.setAttribute('data-id', m.id);

      wrap.innerHTML = `
        <div class="msg-top">
          <div>
            <div class="msg-name">${escapeHtml(m.name || 'Unknown Homeowner')}</div>
            <div class="msg-meta">
              Lot: ${escapeHtml(m.lot || '—')} •
              ${escapeHtml(m.email || '—')} •
              ${escapeHtml(m.created_at || '')}
            </div>
          </div>
          <div class="msg-actions">
            <button type="button" class="btn btn-sm btn-outline-danger btnDeleteMsg" data-id="${Number(m.id)}">
              <i class="dw dw-delete-3"></i> Delete
            </button>
            ${
              m.is_muted
                ? `<button type="button" class="btn btn-sm btn-outline-success btnUnmuteUser" data-homeowner-id="${Number(m.homeowner_id)}" data-homeowner-name="${escapeHtml(m.name || '')}">
                     Unmute
                   </button>`
                : `<button type="button" class="btn btn-sm btn-outline-warning btnMuteUser" data-homeowner-id="${Number(m.homeowner_id)}" data-homeowner-name="${escapeHtml(m.name || '')}">
                     Mute
                   </button>`
            }
          </div>
        </div>
        <div class="msg-text">${escapeHtml(m.message || '')}</div>
        ${
          m.is_muted
            ? `<div class="mute-badge">
                 MUTED ${m.mute_reason ? '• Reason: ' + escapeHtml(m.mute_reason) : ''}
               </div>`
            : ''
        }
      `;

      return wrap;
    }

    async function loadMessages(initial = false) {
      if (isFetching) return;
      isFetching = true;

      try {
        const res = await postJSON('fetch_messages', { last_id: initial ? 0 : lastId });
        if (!res.success) return;

        const messages = Array.isArray(res.messages) ? res.messages : [];

        if (initial) {
          chatBoardBody.innerHTML = '';
          lastId = 0;
        }

        if (messages.length === 0) {
          if (!chatBoardBody.querySelector('.msg-item')) {
            chatBoardBody.innerHTML = '<div class="chat-empty" id="chatEmpty">No public chat messages yet.</div>';
          }
          return;
        }

        const frag = document.createDocumentFragment();

        messages.forEach(m => {
          const exists = chatBoardBody.querySelector('.msg-item[data-id="' + Number(m.id) + '"]');
          if (!exists) {
            frag.appendChild(renderMessageRow(m));
          }
          if (Number(m.id) > lastId) lastId = Number(m.id);
        });

        if (!chatBoardBody.querySelector('.msg-item')) {
          chatBoardBody.innerHTML = '';
        }

        chatBoardBody.appendChild(frag);
        if (initial) chatBoardBody.scrollTop = chatBoardBody.scrollHeight;
      } catch (err) {
        console.error(err);
      } finally {
        isFetching = false;
      }
    }

    function switchMode(mode){
      currentMode = mode;

      if (mode === 'public') {
        modePublicBtn.classList.add('active');
        modeOfficerBtn.classList.remove('active');
        publicModeWrap.style.display = '';
        officerModeWrap.style.display = 'none';
        loadMessages(false);
      } else {
        modeOfficerBtn.classList.add('active');
        modePublicBtn.classList.remove('active');
        officerModeWrap.style.display = '';
        publicModeWrap.style.display = 'none';
        loadThreads();
      }
    }

    modePublicBtn?.addEventListener('click', function(){ switchMode('public'); });
    modeOfficerBtn?.addEventListener('click', function(){ switchMode('officer'); });

    btnRefreshNow?.addEventListener('click', function(){
      loadMessages(true);
    });

    function renderThreadItem(t){
      const el = document.createElement('div');
      el.className = 'thread-item' + (Number(t.homeowner_id) === Number(selectedHomeownerId) ? ' active' : '');
      el.setAttribute('data-homeowner-id', Number(t.homeowner_id));

      el.innerHTML = `
        <div class="thread-top">
          <div>
            <div class="thread-name">${escapeHtml(t.name || 'Homeowner')}</div>
            <div class="thread-sub">${escapeHtml(t.lot || '—')} • ${escapeHtml(t.email || '—')}</div>
          </div>
          ${Number(t.unread_count) > 0 ? `<span class="unread-badge">${Number(t.unread_count)}</span>` : ''}
        </div>
        <div class="thread-preview">${escapeHtml((t.last_message || '').slice(0, 120) || 'No message')}</div>
        <div class="thread-sub mt-1">${escapeHtml(t.last_message_at || '')}</div>
      `;
      return el;
    }

    async function loadThreads(){
      try {
        const res = await postJSON('fetch_officer_threads');
        if (!res.success) return;

        const threads = Array.isArray(res.threads) ? res.threads : [];
        threadListBody.innerHTML = '';

        if (threads.length === 0) {
          threadListBody.innerHTML = '<div class="chat-empty" id="threadEmpty">No private conversations yet.</div>';
          return;
        }

        const frag = document.createDocumentFragment();
        threads.forEach(t => frag.appendChild(renderThreadItem(t)));
        threadListBody.appendChild(frag);

        if (!selectedHomeownerId && threads.length > 0) {
          selectedHomeownerId = Number(threads[0].homeowner_id);
          officerLastId = 0;
          loadConversation(true);
          refreshThreadActiveState();
        }
      } catch (err) {
        console.error(err);
      }
    }

    function refreshThreadActiveState(){
      threadListBody.querySelectorAll('.thread-item').forEach(item => {
        item.classList.toggle('active', Number(item.getAttribute('data-homeowner-id')) === Number(selectedHomeownerId));
      });
    }

    function renderConversationMessage(m){
      const row = document.createElement('div');
      row.className = 'conv-row' + (m.mine ? ' mine' : '');
      row.setAttribute('data-id', m.id);

      row.innerHTML = `
        <div class="conv-avatar">${m.mine ? 'ME' : 'HO'}</div>
        <div class="conv-wrap">
          <div class="conv-meta">
            <span class="conv-name">${m.mine ? 'You' : 'Homeowner'}</span>
            <span>•</span>
            <span>${escapeHtml(m.created_at || '')}</span>
          </div>
          <div class="conv-bubble">${escapeHtml(m.message || '').replace(/\n/g, '<br>')}</div>
        </div>
      `;
      return row;
    }

    function isNearBottom(el){
      return (el.scrollHeight - el.scrollTop - el.clientHeight) < 120;
    }

    function scrollConversationBottom(){
      conversationBody.scrollTop = conversationBody.scrollHeight;
    }

    async function loadConversation(initial = false){
      if (!selectedHomeownerId) {
        conversationBody.innerHTML = '<div class="chat-empty" id="conversationEmpty">Select a homeowner from the left to view messages.</div>';
        replyMessage.disabled = true;
        replySendBtn.disabled = true;
        replyMessage.placeholder = 'Select a homeowner first...';
        convTitle.textContent = 'Officer Private Chat';
        convSub.textContent = 'Select a homeowner to open the conversation.';
        return;
      }

      if (isOfficerFetching) return;
      isOfficerFetching = true;

      const shouldStickBottom = isNearBottom(conversationBody) || initial;

      try {
        const res = await postJSON('fetch_officer_conversation', {
          homeowner_id: selectedHomeownerId,
          last_id: initial ? 0 : officerLastId
        });

        if (!res.success) {
          if (initial) {
            conversationBody.innerHTML = '<div class="chat-empty">Unable to load conversation.</div>';
          }
          return;
        }

        const homeowner = res.homeowner || {};
        const messages = Array.isArray(res.messages) ? res.messages : [];

        convTitle.textContent = homeowner.name || 'Officer Private Chat';
        convSub.textContent = `${homeowner.lot || '—'} • ${homeowner.email || '—'}`;

        replyMessage.disabled = false;
        replySendBtn.disabled = false;
        replyMessage.placeholder = 'Type your reply here...';

        if (initial) {
          conversationBody.innerHTML = '';
          officerLastId = 0;
        }

        if (messages.length === 0) {
          if (!conversationBody.querySelector('.conv-row')) {
            conversationBody.innerHTML = '<div class="chat-empty">No messages yet. Start replying to this homeowner.</div>';
          }
          return;
        }

        const oldEmpty = conversationBody.querySelector('.chat-empty');
        if (oldEmpty) oldEmpty.remove();

        messages.forEach(m => {
          const exists = conversationBody.querySelector('.conv-row[data-id="' + Number(m.id) + '"]');
          if (!exists) {
            conversationBody.appendChild(renderConversationMessage(m));
          }
          if (Number(m.id) > officerLastId) officerLastId = Number(m.id);
        });

        if (shouldStickBottom) scrollConversationBottom();
      } catch (err) {
        console.error(err);
      } finally {
        isOfficerFetching = false;
      }
    }

    threadListBody?.addEventListener('click', function(e){
      const item = e.target.closest('.thread-item');
      if (!item) return;

      selectedHomeownerId = Number(item.getAttribute('data-homeowner-id') || 0);
      officerLastId = 0;
      refreshThreadActiveState();
      loadConversation(true);
    });

    replyForm?.addEventListener('submit', async function(e){
      e.preventDefault();

      const message = (replyMessage.value || '').trim();
      if (!selectedHomeownerId || !message) return;

      replySendBtn.disabled = true;

      try {
        const res = await postJSON('send_officer_reply', {
          homeowner_id: selectedHomeownerId,
          message
        });

        if (!res.success) {
          openNoticeModal('Reply Failed', res.message || 'Failed to send reply.');
          return;
        }

        replyMessage.value = '';
        await loadConversation(false);
        scrollConversationBottom();
        await loadThreads();
      } catch (err) {
        openNoticeModal('Reply Failed', 'Failed to send reply.');
      } finally {
        replySendBtn.disabled = false;
        replyMessage.focus();
      }
    });

    replyMessage?.addEventListener('keydown', function(e){
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        replyForm.requestSubmit();
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
       MUTE MODAL
       ========================= */
    const muteModal = document.getElementById('muteModal');
    const closeMuteModalBtn = document.getElementById('closeMuteModal');
    const cancelMuteBtn = document.getElementById('cancelMuteBtn');
    const confirmMuteBtn = document.getElementById('confirmMuteBtn');
    const muteHomeownerIdInput = document.getElementById('muteHomeownerId');
    const muteHomeownerNameEl = document.getElementById('muteHomeownerName');
    const muteReasonInput = document.getElementById('muteReason');

    function openMuteModal(homeownerId, homeownerName) {
      muteHomeownerIdInput.value = homeownerId || '';
      muteHomeownerNameEl.textContent = homeownerName || 'Homeowner';
      muteReasonInput.value = 'Inappropriate messages';
      muteModal.style.display = 'flex';
      setTimeout(() => muteReasonInput.focus(), 50);
    }

    function closeMuteDialog() {
      muteModal.style.display = 'none';
      muteHomeownerIdInput.value = '';
      muteHomeownerNameEl.textContent = 'Homeowner';
      muteReasonInput.value = '';
    }

    closeMuteModalBtn?.addEventListener('click', closeMuteDialog);
    cancelMuteBtn?.addEventListener('click', closeMuteDialog);
    muteModal?.addEventListener('click', function(e) {
      if (e.target === muteModal) closeMuteDialog();
    });

    confirmMuteBtn?.addEventListener('click', async function() {
      const homeownerId = Number(muteHomeownerIdInput.value || 0);
      const reason = (muteReasonInput.value || '').trim();

      if (!homeownerId) return;

      confirmMuteBtn.disabled = true;

      try {
        const res = await postJSON('mute_homeowner', {
          homeowner_id: homeownerId,
          reason: reason
        });

        if (!res.success) {
          openNoticeModal('Mute Failed', res.message || 'Failed to mute homeowner.');
          return;
        }

        closeMuteDialog();
        openNoticeModal('Success', res.message || 'Homeowner muted from public chat.');
        loadMessages(true);
      } catch (err) {
        openNoticeModal('Mute Failed', 'Failed to mute homeowner.');
      } finally {
        confirmMuteBtn.disabled = false;
      }
    });

    /* =========================
       CONFIRM MODAL
       ========================= */
    const confirmModal = document.getElementById('confirmModal');
    const confirmModalTitle = document.getElementById('confirmModalTitle');
    const confirmModalMessage = document.getElementById('confirmModalMessage');
    const closeConfirmModal = document.getElementById('closeConfirmModal');
    const cancelConfirmBtn = document.getElementById('cancelConfirmBtn');
    const confirmActionBtn = document.getElementById('confirmActionBtn');

    let confirmActionCallback = null;

    function openConfirmModal(title, message, buttonClass, callback) {
      confirmModalTitle.textContent = title || 'Confirm Action';
      confirmModalMessage.textContent = message || 'Are you sure?';
      confirmActionBtn.className = 'btn ' + (buttonClass || 'btn-danger');
      confirmActionBtn.textContent = 'Confirm';
      confirmActionCallback = callback || null;
      confirmModal.style.display = 'flex';
    }

    function closeConfirmDialog() {
      confirmModal.style.display = 'none';
      confirmActionCallback = null;
    }

    closeConfirmModal?.addEventListener('click', closeConfirmDialog);
    cancelConfirmBtn?.addEventListener('click', closeConfirmDialog);
    confirmModal?.addEventListener('click', function(e) {
      if (e.target === confirmModal) closeConfirmDialog();
    });

    confirmActionBtn?.addEventListener('click', async function() {
      if (typeof confirmActionCallback === 'function') {
        confirmActionBtn.disabled = true;
        try {
          await confirmActionCallback();
        } finally {
          confirmActionBtn.disabled = false;
        }
      }
    });

    document.addEventListener('click', async function(e){
      const deleteBtn = e.target.closest('.btnDeleteMsg');
      if (deleteBtn) {
        const id = Number(deleteBtn.getAttribute('data-id') || 0);
        if (!id) return;

        openConfirmModal(
          'Delete Message',
          'Are you sure you want to delete this public chat message?',
          'btn-danger',
          async function() {
            try {
              const res = await postJSON('delete_message', { message_id: id });
              if (!res.success) {
                closeConfirmDialog();
                openNoticeModal('Delete Failed', res.message || 'Failed to delete message.');
                return;
              }

              closeConfirmDialog();

              const row = document.querySelector('.msg-item[data-id="' + id + '"]');
              if (row) row.remove();

              if (!document.querySelector('.msg-item')) {
                chatBoardBody.innerHTML = '<div class="chat-empty">No public chat messages yet.</div>';
              }

              openNoticeModal('Success', res.message || 'Message deleted.');
            } catch (err) {
              closeConfirmDialog();
              openNoticeModal('Delete Failed', 'Failed to delete message.');
            }
          }
        );
        return;
      }

      const muteBtn = e.target.closest('.btnMuteUser');
      if (muteBtn) {
        const homeownerId = Number(muteBtn.getAttribute('data-homeowner-id') || 0);
        const homeownerName = muteBtn.getAttribute('data-homeowner-name') || 'this homeowner';

        if (!homeownerId) return;

        openMuteModal(homeownerId, homeownerName);
        return;
      }

      const unmuteBtn = e.target.closest('.btnUnmuteUser');
      if (unmuteBtn) {
        const homeownerId = Number(unmuteBtn.getAttribute('data-homeowner-id') || 0);
        const homeownerName = unmuteBtn.getAttribute('data-homeowner-name') || 'this homeowner';
        if (!homeownerId) return;

        openConfirmModal(
          'Unmute Homeowner',
          'Are you sure you want to unmute ' + homeownerName + '?',
          'btn-success',
          async function() {
            try {
              const res = await postJSON('unmute_homeowner', {
                homeowner_id: homeownerId
              });

              if (!res.success) {
                closeConfirmDialog();
                openNoticeModal('Unmute Failed', res.message || 'Failed to unmute homeowner.');
                return;
              }

              closeConfirmDialog();
              openNoticeModal('Success', res.message || 'Homeowner unmuted.');
              loadMessages(true);
            } catch (err) {
              closeConfirmDialog();
              openNoticeModal('Unmute Failed', 'Failed to unmute homeowner.');
            }
          }
        );
        return;
      }
    });

    loadMessages(true);
    loadThreads();

    setInterval(() => {
      if (currentMode === 'public') {
        loadMessages(false);
      } else {
        loadThreads();
        loadConversation(false);
      }
    }, 4000);
  </script>

  <div id="accessToast" class="access-toast">
    🚫 You do not have access to that part.
  </div>

  <script>
    window.userPermissions = <?= json_encode($permissions) ?>;

    document.addEventListener('DOMContentLoaded', function () {
      const toast = document.getElementById('accessToast');

      function showAccessToast() {
        toast.classList.add('show');
        setTimeout(() => {
          toast.classList.remove('show');
        }, 2500);
      }

      document.querySelectorAll('.menu-access-link').forEach(function(link){
        link.addEventListener('click', function(e){
          const moduleKey = this.dataset.module || '';
          const allowed = !!window.userPermissions[moduleKey];

          if(!allowed){
            e.preventDefault();
            showAccessToast();
          }
        });
      });
    });
  </script>
</body>
</html>