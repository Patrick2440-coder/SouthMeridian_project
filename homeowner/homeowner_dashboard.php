<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
  header("Location: ../index.php");
  exit;
}

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$hid = (int)$_SESSION['homeowner_id'];

$stmt = $conn->prepare("SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number, latitude, longitude FROM homeowners WHERE id=? LIMIT 1");
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
$fullName   = trim($user['first_name'].' '.$user['last_name']);
$mustChange = ((int)$user['must_change_password'] === 1);
$initials   = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));
$pageTitle  = "South Meridian Homes Salitran • ".$phase;

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
    header("Location: homeowner_dashboard.php");
    exit;
  }
}

/**
 * Ensure feed_state exists (notifications)
 */
$stmt = $conn->prepare("INSERT IGNORE INTO homeowner_feed_state (homeowner_id) VALUES (?)");
$stmt->bind_param("i", $hid);
$stmt->execute();
$stmt->close();

/**
 * Handle AJAX actions
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json; charset=utf-8');

  if ($mustChange) {
    echo json_encode(['success'=>false,'message'=>'Please change your password first.']);
    exit;
  }

  $action = (string)$_POST['action'];

  if ($action === 'create_post') {
    $content = trim((string)($_POST['content'] ?? ''));
    if ($content === '') {
      echo json_encode(['success'=>false,'message'=>'Post cannot be empty.']);
      exit;
    }
    $stmt = $conn->prepare("INSERT INTO hoa_posts (homeowner_id, phase, content) VALUES (?,?,?)");
    $stmt->bind_param("iss", $hid, $phase, $content);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>$ok,'message'=>$ok?'Posted!':'Failed to post.']);
    exit;
  }

  if ($action === 'toggle_like') {
    $post_id = (int)($_POST['post_id'] ?? 0);
    if ($post_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid post.']); exit; }

    $stmt = $conn->prepare("SELECT id FROM hoa_post_likes WHERE post_id=? AND homeowner_id=? LIMIT 1");
    $stmt->bind_param("ii", $post_id, $hid);
    $stmt->execute();
    $liked = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($liked) {
      $stmt = $conn->prepare("DELETE FROM hoa_post_likes WHERE post_id=? AND homeowner_id=?");
      $stmt->bind_param("ii", $post_id, $hid);
      $ok = $stmt->execute();
      $stmt->close();
      $state = false;
    } else {
      $stmt = $conn->prepare("INSERT IGNORE INTO hoa_post_likes (post_id, homeowner_id) VALUES (?,?)");
      $stmt->bind_param("ii", $post_id, $hid);
      $ok = $stmt->execute();
      $stmt->close();
      $state = true;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM hoa_post_likes WHERE post_id=?");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    $cnt = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    echo json_encode(['success'=>$ok,'liked'=>$state,'like_count'=>$cnt]);
    exit;
  }

  if ($action === 'add_comment') {
    $post_id = (int)($_POST['post_id'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));
    if ($post_id <= 0 || $comment === '') {
      echo json_encode(['success'=>false,'message'=>'Comment cannot be empty.']);
      exit;
    }

    $stmt = $conn->prepare("INSERT INTO hoa_post_comments (post_id, homeowner_id, comment) VALUES (?,?,?)");
    $stmt->bind_param("iis", $post_id, $hid, $comment);
    $ok = $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM hoa_post_comments WHERE post_id=?");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    $cc = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    echo json_encode([
      'success'=>$ok,
      'message'=>$ok?'Comment added.':'Failed to comment.',
      'comment_count'=>$cc,
      'comment_html'=>$ok ? '
        <div class="fb-comment">
          <div class="fb-comment-avatar">'.esc(strtoupper(substr($user['first_name'] ?? 'H',0,1))).'</div>
          <div class="fb-comment-bubble">
            <div class="fb-comment-name">'.esc($fullName).'</div>
            <div class="fb-comment-text">'.esc($comment).'</div>
          </div>
        </div>' : ''
    ]);
    exit;
  }

  /**
   * SHARE (Facebook style)
   */
  if ($action === 'share_post_create') {
    $post_id = (int)($_POST['post_id'] ?? 0);
    $share_message = trim((string)($_POST['share_message'] ?? ''));

    if ($post_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid post.']); exit; }

    $stmt = $conn->prepare("SELECT id FROM hoa_posts WHERE id=? AND phase=? LIMIT 1");
    $stmt->bind_param("is", $post_id, $phase);
    $stmt->execute();
    $okPost = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$okPost) { echo json_encode(['success'=>false,'message'=>'Post not found.']); exit; }

    $stmt = $conn->prepare("INSERT INTO hoa_posts (homeowner_id, phase, content, shared_post_id) VALUES (?,?,?,?)");
    $stmt->bind_param("issi", $hid, $phase, $share_message, $post_id);
    $ok = $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM hoa_posts WHERE shared_post_id=?");
    $stmt->bind_param("i", $post_id);
    $stmt->execute();
    $sc = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    echo json_encode(['success'=>$ok,'message'=>$ok?'Shared!':'Failed to share.','share_count'=>$sc]);
    exit;
  }

  /**
   * Notifications: mark as seen
   */
  if ($action === 'mark_seen') {
    $target = (string)($_POST['target'] ?? 'all');

    if ($target === 'ann') {
      $stmt = $conn->prepare("UPDATE homeowner_feed_state SET last_ann_seen = NOW() WHERE homeowner_id=?");
      $stmt->bind_param("i", $hid);
      $ok = $stmt->execute();
      $stmt->close();
      echo json_encode(['success'=>$ok]);
      exit;
    }

    if ($target === 'comments') {
      $stmt = $conn->prepare("UPDATE homeowner_feed_state SET last_comment_seen = NOW() WHERE homeowner_id=?");
      $stmt->bind_param("i", $hid);
      $ok = $stmt->execute();
      $stmt->close();
      echo json_encode(['success'=>$ok]);
      exit;
    }

    $stmt = $conn->prepare("UPDATE homeowner_feed_state SET last_ann_seen = NOW(), last_comment_seen = NOW() WHERE homeowner_id=?");
    $stmt->bind_param("i", $hid);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>$ok]);
    exit;
  }

  echo json_encode(['success'=>false,'message'=>'Unknown action.']);
  exit;
}

/**
 * Load notification state timestamps
 */
$stmt = $conn->prepare("SELECT last_ann_seen, last_comment_seen FROM homeowner_feed_state WHERE homeowner_id=? LIMIT 1");
$stmt->bind_param("i", $hid);
$stmt->execute();
$state = $stmt->get_result()->fetch_assoc() ?: ['last_ann_seen'=>date('Y-m-d H:i:s'), 'last_comment_seen'=>date('Y-m-d H:i:s')];
$stmt->close();

$lastAnnSeen = (string)$state['last_ann_seen'];
$lastComSeen = (string)$state['last_comment_seen'];

/**
 * Announcements list (phase + superadmin, active)
 */
$ann = [];
$stmt = $conn->prepare("
  SELECT a.id, a.title, a.message, a.category, a.priority, a.start_date, a.end_date, a.created_at
  FROM announcements a
  WHERE (a.phase = ? OR a.phase = 'Superadmin')
    AND a.start_date <= CURDATE()
    AND (a.end_date IS NULL OR a.end_date >= CURDATE())
  ORDER BY
    FIELD(a.priority,'urgent','important','normal'),
    a.start_date DESC,
    a.created_at DESC
  LIMIT 20
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $ann[] = $r;
$stmt->close();

/**
 * Notification counts
 */
$stmt = $conn->prepare("
  SELECT COUNT(*) c
  FROM announcements a
  WHERE (a.phase = ? OR a.phase='Superadmin')
    AND a.created_at > ?
    AND a.start_date <= CURDATE()
    AND (a.end_date IS NULL OR a.end_date >= CURDATE())
");
$stmt->bind_param("ss", $phase, $lastAnnSeen);
$stmt->execute();
$newAnnCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("
  SELECT COUNT(*) c
  FROM hoa_post_comments c
  JOIN hoa_posts p ON p.id = c.post_id
  WHERE p.homeowner_id = ?
    AND p.phase = ?
    AND c.homeowner_id <> ?
    AND c.created_at > ?
");
$stmt->bind_param("isis", $hid, $phase, $hid, $lastComSeen);
$stmt->execute();
$newComCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$notifCount = $newAnnCount + $newComCount;

/**
 * Notification dropdown items
 */
$notifItems = [];

// latest announcements since last seen
$stmt = $conn->prepare("
  SELECT id, title, created_at, 'announcement' AS kind
  FROM announcements
  WHERE (phase = ? OR phase='Superadmin')
    AND created_at > ?
    AND start_date <= CURDATE()
    AND (end_date IS NULL OR end_date >= CURDATE())
  ORDER BY created_at DESC
  LIMIT 5
");
$stmt->bind_param("ss", $phase, $lastAnnSeen);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $notifItems[] = $r;
$stmt->close();

// latest comments on your posts since last seen
$stmt = $conn->prepare("
  SELECT c.id, c.created_at, 'comment' AS kind,
         CONCAT(h.first_name,' ',h.last_name) AS actor_name,
         LEFT(c.comment, 90) AS snippet
  FROM hoa_post_comments c
  JOIN hoa_posts p ON p.id = c.post_id
  JOIN homeowners h ON h.id = c.homeowner_id
  WHERE p.homeowner_id = ?
    AND p.phase = ?
    AND c.homeowner_id <> ?
    AND c.created_at > ?
  ORDER BY c.created_at DESC
  LIMIT 5
");
$stmt->bind_param("isis", $hid, $phase, $hid, $lastComSeen);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $notifItems[] = $r;
$stmt->close();

usort($notifItems, function($a,$b){
  return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});
$notifItems = array_slice($notifItems, 0, 8);

/**
 * FEED POSTS (same phase) with SHARE support
 */
$posts = [];
$stmt = $conn->prepare("
  SELECT
    p.id, p.homeowner_id, p.content, p.created_at, p.shared_post_id,
    h.first_name, h.last_name, h.house_lot_number,

    op.id AS orig_id, op.content AS orig_content, op.created_at AS orig_created_at,
    oh.first_name AS orig_first_name, oh.last_name AS orig_last_name, oh.house_lot_number AS orig_house_lot,

    (SELECT COUNT(*) FROM hoa_post_likes l WHERE l.post_id=p.id) AS like_count,
    (SELECT COUNT(*) FROM hoa_post_comments c WHERE c.post_id=p.id) AS comment_count,
    (SELECT COUNT(*) FROM hoa_posts sp WHERE sp.shared_post_id=p.id) AS share_count,
    (SELECT COUNT(*) FROM hoa_post_likes l2 WHERE l2.post_id=p.id AND l2.homeowner_id=?) AS i_liked
  FROM hoa_posts p
  JOIN homeowners h ON h.id = p.homeowner_id
  LEFT JOIN hoa_posts op ON op.id = p.shared_post_id
  LEFT JOIN homeowners oh ON oh.id = op.homeowner_id
  WHERE p.phase = ?
  ORDER BY p.created_at DESC
  LIMIT 25
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $posts[] = $r;
$stmt->close();

/**
 * COMMENTS for shown posts (batch)
 */
$commentsByPost = [];
if (!empty($posts)) {
  $ids = array_map(fn($p)=>(int)$p['id'], $posts);
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));

  $sql = "
    SELECT c.id, c.post_id, c.homeowner_id, c.comment, c.created_at,
           h.first_name, h.last_name
    FROM hoa_post_comments c
    JOIN homeowners h ON h.id=c.homeowner_id
    WHERE c.post_id IN ($in)
    ORDER BY c.created_at ASC
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();
  while($r = $res->fetch_assoc()){
    $pid = (int)$r['post_id'];
    if (!isset($commentsByPost[$pid])) $commentsByPost[$pid] = [];
    $commentsByPost[$pid][] = $r;
  }
  $stmt->close();
}

$lat = $user['latitude'];
$lng = $user['longitude'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= esc($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
:root{
  --hoa-green:#2e8b57;
  --hoa-green-dark:#256f46;
  --fb-bg:#f0f2f5;
  --card:#ffffff;
  --border:#e5e7eb;
  --muted:#6b7280;
}
body{ background:var(--fb-bg); }

/* Navbar */
.navbar{ border-bottom:4px solid var(--hoa-green); }
.navbar-brand{ letter-spacing:.2px; }
.container-xl{ max-width:1180px; }

/* Cover Map */
.fb-cover{
  position:relative;
  height:320px;
  border-radius:18px;
  overflow:hidden;
  border:1px solid var(--border);
  box-shadow:0 12px 28px rgba(0,0,0,.08);
  background:#e9eef6;
  isolation:isolate;
}
#coverMap{ position:absolute; inset:0; z-index:0; }
.fb-cover::after{
  content:"";
  position:absolute; inset:0;
  background:linear-gradient(to bottom, rgba(0,0,0,.10), rgba(0,0,0,.45));
  pointer-events:none;
  z-index:2;
}
.cover-badge{
  position:absolute;
  left:16px; top:16px;
  z-index:3;
  display:flex; gap:10px; align-items:center;
  background:rgba(255,255,255,.92);
  border:1px solid rgba(255,255,255,.65);
  backdrop-filter: blur(8px);
  border-radius:999px;
  padding:8px 12px;
  font-weight:900;
  color:#0f172a;
}
.cover-badge small{ font-weight:800; color:var(--muted); }

/* Profile overlap */
.fb-profile-row{ position:relative; margin-top:-64px; padding:0 10px; }
.fb-profile-card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:18px;
  box-shadow:0 10px 24px rgba(0,0,0,.06);
  padding:16px 18px;
  display:flex;
  gap:16px;
  align-items:flex-end;
}
.fb-avatar{
  width:128px;height:128px;border-radius:50%;
  border:6px solid #fff;
  background: linear-gradient(135deg, var(--hoa-green), #0bbf6a);
  display:grid; place-items:center;
  color:#fff;font-weight:900;font-size:40px;
  box-shadow:0 10px 25px rgba(0,0,0,.18);
  flex:0 0 auto;
}
.fb-name{ margin:0; font-size:24px; font-weight:900; line-height:1.1; }
.fb-sub{ color:var(--muted); font-weight:800; margin:6px 0 0; }
.fb-actions{ margin-left:auto; display:flex; gap:10px; flex-wrap:wrap; }
.pill{
  display:inline-flex; align-items:center; gap:8px;
  padding:8px 12px;
  border-radius:999px;
  background:#f1f5f9;
  border:1px solid var(--border);
  font-weight:900;
  font-size:13px;
  color:#0f172a;
}
.btn-hoa{ background:var(--hoa-green); border:none; color:#fff; font-weight:900; }
.btn-hoa:hover{ background:var(--hoa-green-dark); color:#fff; }

/* Cards */
.fb-card{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:18px;
  box-shadow:0 10px 24px rgba(0,0,0,.06);
  overflow:hidden;
}
.fb-card-h{ padding:14px 16px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; gap:10px; }
.fb-card-h h6{ margin:0; font-weight:900; color:var(--hoa-green); }
.fb-card-b{ padding:14px 16px; }

/* Post */
.post{
  background:var(--card);
  border:1px solid var(--border);
  border-radius:18px;
  box-shadow:0 10px 24px rgba(0,0,0,.06);
  overflow:hidden;
}
.post-h{ padding:14px 16px; display:flex; gap:12px; align-items:center; }
.post-avatar{
  width:44px;height:44px;border-radius:50%;
  background: linear-gradient(135deg, var(--hoa-green), #0bbf6a);
  color:#fff;font-weight:900;
  display:grid;place-items:center;
}
.post-name{ font-weight:900; margin:0; }
.post-meta{ font-size:12px; color:var(--muted); font-weight:800; }
.post-b{ padding:0 16px 12px; }
.post-content{ white-space:pre-wrap; font-weight:800; color:#0f172a; }

/* Shared card */
.shared-box{
  border:1px solid var(--border);
  border-radius:14px;
  background:#f8fafc;
  padding:12px;
  margin-top:10px;
}
.shared-title{ font-weight:900; font-size:13px; color:#0f172a; margin-bottom:6px; }
.shared-meta{ font-size:12px; color:var(--muted); font-weight:800; margin-bottom:6px; }
.shared-content{ white-space:pre-wrap; font-weight:800; color:#0f172a; }

.post-stats{
  padding:10px 16px;
  border-top:1px solid var(--border);
  border-bottom:1px solid var(--border);
  display:flex; justify-content:space-between; align-items:center;
  font-size:13px; color:var(--muted); font-weight:900;
}
.post-actions{ padding:8px; display:flex; gap:6px; }
.action-btn{
  flex:1; border:none; background:transparent;
  padding:10px 8px; border-radius:12px;
  font-weight:900; color:#334155;
}
.action-btn:hover{ background:#f1f5f9; }
.action-btn.liked{ color:var(--hoa-green); }

.comments{ padding:12px 16px 16px; }
.fb-comment{ display:flex; gap:10px; margin-top:10px; }
.fb-comment-avatar{
  width:34px;height:34px;border-radius:50%;
  background:#e2e8f0;
  display:grid;place-items:center;
  font-weight:900;color:#0f172a;
}
.fb-comment-bubble{
  background:#f1f5f9;
  border:1px solid var(--border);
  border-radius:14px;
  padding:10px 12px;
  width:100%;
}
.fb-comment-name{ font-weight:900; font-size:13px; margin-bottom:2px; }
.fb-comment-text{ font-weight:800; color:#0f172a; white-space:pre-wrap; }
.comment-form{ display:flex; gap:10px; margin-top:12px; }
.comment-input{
  flex:1;
  border:1px solid var(--border);
  border-radius:999px;
  padding:10px 14px;
  outline:none;
  font-weight:800;
}
.comment-input:focus{ border-color: rgba(46,139,87,.45); box-shadow:0 0 0 .2rem rgba(46,139,87,.12); }

/* Composer */
.composer-top{ display:flex; gap:12px; align-items:flex-start; }
.mini-avatar{
  width:44px;height:44px;border-radius:50%;
  background: linear-gradient(135deg, var(--hoa-green), #0bbf6a);
  color:#fff;font-weight:900;display:grid;place-items:center;
}
.composer-textarea{
  width:100%;
  border:1px solid var(--border);
  border-radius:14px;
  padding:12px;
  resize:none;
  outline:none;
  font-weight:800;
}
.composer-textarea:focus{ border-color: rgba(46,139,87,.45); box-shadow:0 0 0 .2rem rgba(46,139,87,.15); }
.composer-actions{ display:flex; align-items:center; justify-content:space-between; margin-top:10px; }

/* Notification bell */
.notif-btn{
  width:42px;height:42px;border-radius:999px;
  display:inline-flex;align-items:center;justify-content:center;
  border:1px solid var(--border);
  background:#fff;
}
.notif-badge{
  position:absolute;
  top:2px; right:2px;
  background:#dc2626;
  color:#fff;
  font-weight:900;
  font-size:11px;
  padding:3px 6px;
  border-radius:999px;
  border:2px solid #fff;
}

/* Password overlay */
.lock-overlay{
  position:fixed; inset:0;
  background: rgba(0,0,0,.35);
  z-index: 2000;
  display:flex;
  align-items:center;
  justify-content:center;
  padding: 16px;
}
.lock-modal{
  width:100%;
  max-width:520px;
  border-radius: 18px;
  overflow:hidden;
  box-shadow: 0 20px 55px rgba(0,0,0,.35);
}
.lock-modal .head{
  background: var(--hoa-green);
  color:#fff;
  padding: 14px 18px;
  display:flex;
  align-items:center;
  gap:10px;
}
.lock-modal .body{ background:#fff; padding: 18px; }
.lock-note{
  background:#f1f8f4;
  border: 1px solid #d7efe1;
  border-radius: 12px;
  padding: 12px;
  color:#185c3a;
}
.blur-wrap{
  filter: blur(6px);
  transform: scale(1.01);
  pointer-events: none;
  user-select: none;
}

@media (max-width:768px){
  .fb-cover{ height:250px; border-radius:14px; }
  .fb-profile-card{ flex-direction:column; align-items:center; text-align:center; }
  .fb-actions{ margin-left:0; justify-content:center; }
}

/* ===== Sidebar Layout (Fixed UI) ===== */
.app-shell{
  display:flex;
  min-height: 100vh;
}

/* Sidebar */
.sidebar{
  width: 270px;
  background: #ffffff;
  border-right: 1px solid var(--border);
  position: sticky;
  top: 0;
  height: 100vh;
  overflow-y: auto;
  transition: width .2s ease, transform .2s ease;
  z-index: 1200;
}

.sidebar .sb-head{
  padding: 14px 16px;
  border-bottom: 1px solid var(--border);
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}

.sidebar .sb-brand{
  font-weight: 900;
  color: var(--hoa-green);
  letter-spacing: .2px;
  display:flex;
  align-items:center;
  gap:10px;
}

.sidebar .sb-user{
  padding: 14px 16px;
  border-bottom: 1px solid var(--border);
  display:flex;
  gap:12px;
  align-items:center;
}

.sidebar .sb-user .sb-avatar{
  width:44px;height:44px;border-radius:50%;
  background: linear-gradient(135deg, var(--hoa-green), #0bbf6a);
  color:#fff; font-weight:900;
  display:grid; place-items:center;
  flex:0 0 auto;
}

.sidebar .sb-user .sb-name{
  font-weight: 900;
  margin:0;
  line-height:1.1;
}
.sidebar .sb-user .sb-meta{
  margin:2px 0 0;
  font-size:12px;
  color: var(--muted);
  font-weight: 800;
}

.sidebar .sb-nav{
  padding: 12px;
  display:flex;
  flex-direction:column;
  gap:8px;
}

.sb-link{
  display:flex;
  align-items:center;
  gap:10px;
  padding: 10px 12px;
  border-radius: 14px;
  border: 1px solid var(--border);
  background:#fff;
  text-decoration:none;
  color:#0f172a;
  font-weight: 900;
  transition: background .15s ease, transform .05s ease;
}
.sb-link:hover{ background:#f8fafc; }
.sb-link:active{ transform: scale(.99); }
.sb-link i{ font-size: 18px; color: var(--hoa-green); }

/* Main content */
.main-area{
  flex:1;
  min-width: 0;
  padding: 0;
}

/* Collapsed state (desktop) */
body.sb-collapsed .sidebar{ width: 78px; }
body.sb-collapsed .sidebar .sb-brand-text,
body.sb-collapsed .sidebar .sb-user-text,
body.sb-collapsed .sidebar .sb-link span{ display:none; }
body.sb-collapsed .sidebar .sb-link{ justify-content:center; padding: 12px; }

/* Backdrop for mobile */
.sb-backdrop{
  display:none;
  position: fixed;
  inset:0;
  background: rgba(0,0,0,.35);
  z-index: 1100;
}

/* Mobile off-canvas */
@media (max-width: 991px){
  .sidebar{
    position: fixed;
    left:0; top:0;
    transform: translateX(-100%);
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
  }
  body.sb-open .sidebar{ transform: translateX(0); }
  body.sb-open .sb-backdrop{ display:block; }
}
</style>
</head>

<body>

<div class="sb-backdrop" id="sbBackdrop"></div>

<div class="app-shell">

  <!-- SIDEBAR -->
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
      <a class="sb-link" href="homeowner_dashboard.php">
        <i class="bi bi-house-door-fill"></i> <span>Dashboard</span>
      </a>

      <a class="sb-link" href="#composer">
        <i class="bi bi-pencil-square"></i> <span>Create Post</span>
      </a>

      <a class="sb-link" href="#announcements">
        <i class="bi bi-megaphone-fill"></i> <span>Announcements</span>
      </a>

      <a class="sb-link" href="homeowner_pay_dues.php">
        <i class="bi bi-cash-coin"></i> <span>Pay Monthly Dues</span>
      </a>

      <a class="sb-link" href="logout.php">
        <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
      </a>
    </nav>
  </aside>

  <!-- MAIN -->
  <div class="main-area">

    <div class="<?= $mustChange ? 'blur-wrap' : '' ?>">

      <!-- NAVBAR (FIXED UI) -->
      <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container-xl">

          <!-- Mobile sidebar open -->
          <button class="btn btn-sm btn-outline-success me-2 d-lg-none" id="btnSidebar" type="button" title="Menu">
            <i class="bi bi-list fs-5"></i>
          </button>

          <!-- Desktop collapse -->
          <button class="btn btn-sm btn-outline-success me-2 d-none d-lg-inline" id="btnCollapse" type="button" title="Collapse sidebar">
            <i class="bi bi-layout-sidebar-inset"></i>
          </button>

          <a class="navbar-brand fw-bold text-success" href="homeowner_dashboard.php">🏘 HOA Community</a>

          <div class="ms-auto d-flex align-items-center gap-3">

            <!-- Notifications -->
            <div class="dropdown position-relative">
              <button class="notif-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                <i class="bi bi-bell fs-5"></i>
              </button>
              <?php if ($notifCount > 0): ?>
                <span class="notif-badge"><?= (int)$notifCount ?></span>
              <?php endif; ?>

              <div class="dropdown-menu dropdown-menu-end p-0" style="width:360px; border-radius:14px; overflow:hidden;">
                <div class="p-3 border-bottom d-flex align-items-center justify-content-between">
                  <div class="fw-bold">Notifications</div>
                  <button class="btn btn-sm btn-outline-success" id="btnMarkAllSeen">Mark all as seen</button>
                </div>

                <div class="p-2" style="max-height:360px; overflow:auto;">
                  <?php if (empty($notifItems)): ?>
                    <div class="p-3 text-muted fw-semibold">No new notifications.</div>
                  <?php else: ?>
                    <?php foreach($notifItems as $n): ?>
                      <?php if ($n['kind'] === 'announcement'): ?>
                        <div class="p-2 rounded-3" style="border:1px solid #eef2f7; background:#fff; margin:6px;">
                          <div class="fw-bold"><i class="bi bi-megaphone-fill text-success me-1"></i> New announcement</div>
                          <div class="fw-semibold"><?= esc($n['title']) ?></div>
                          <div class="text-muted small fw-semibold"><?= esc(date('M d, Y h:i A', strtotime($n['created_at']))) ?></div>
                        </div>
                      <?php else: ?>
                        <div class="p-2 rounded-3" style="border:1px solid #eef2f7; background:#fff; margin:6px;">
                          <div class="fw-bold"><i class="bi bi-chat-left-dots-fill me-1"></i> New comment on your post</div>
                          <div class="fw-semibold"><?= esc($n['actor_name'] ?? 'Someone') ?>:</div>
                          <div class="text-muted fw-semibold"><?= esc($n['snippet'] ?? '') ?><?= strlen($n['snippet'] ?? '')>=90 ? '…' : '' ?></div>
                          <div class="text-muted small fw-semibold"><?= esc(date('M d, Y h:i A', strtotime($n['created_at']))) ?></div>
                        </div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>

                <div class="p-2 border-top d-flex gap-2">
                  <button class="btn btn-sm btn-outline-success flex-fill" id="btnSeenAnn">Seen announcements</button>
                  <button class="btn btn-sm btn-outline-success flex-fill" id="btnSeenCom">Seen comments</button>
                </div>
              </div>
            </div>

            <div class="small text-muted d-none d-md-block">
              Logged in as <b><?= esc($fullName) ?></b> (<?= esc($phase) ?>)
            </div>

            <a href="logout.php" class="btn btn-sm btn-outline-success">Logout</a>
          </div>
        </div>
      </nav>

      <div class="container-xl my-4">

        <!-- Cover Map -->
        <div class="fb-cover">
          <div class="cover-badge">
            <span>South Meridian Homes Salitran</span>
            <small>• <?= esc($phase) ?></small>
          </div>

          <?php if (!empty($lat) && !empty($lng)): ?>
            <div id="coverMap" data-lat="<?= esc($lat) ?>" data-lng="<?= esc($lng) ?>"></div>
          <?php else: ?>
            <div class="h-100 w-100 d-flex align-items-center justify-content-center">
              <div class="text-muted fw-semibold">No location saved yet.</div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Profile overlap -->
        <div class="fb-profile-row">
          <div class="fb-profile-card">
            <div class="fb-avatar"><?= esc($initials) ?></div>

            <div>
              <h2 class="fb-name"><?= esc($fullName) ?></h2>
              <div class="fb-sub"><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?></div>
              <div class="mt-2 d-flex gap-2 flex-wrap">
                <span class="pill">📍 South Meridian Homes Salitran</span>
                <span class="pill">🏠 <?= esc($user['house_lot_number'] ?? '') ?></span>
              </div>
            </div>

            <div class="fb-actions">
              <span class="pill"><i class="bi bi-geo-alt-fill"></i> Cover = Map</span>
              <a class="btn btn-hoa" href="#composer"><i class="bi bi-pencil-square me-1"></i> Post</a>
            </div>
          </div>
        </div>

        <div class="row g-4 mt-2">

          <!-- LEFT -->
          <div class="col-lg-4">
            <div class="fb-card mb-4" id="announcements">
              <div class="fb-card-h">
                <h6>📢 Announcements & Events</h6>
                <span class="pill"><?= count($ann) ?> active</span>
              </div>
              <div class="fb-card-b">
                <?php if (empty($ann)): ?>
                  <div class="text-muted fw-semibold">No announcements right now.</div>
                <?php else: ?>
                  <div class="d-flex flex-column gap-3">
                    <?php foreach($ann as $a): ?>
                      <?php
                        $prio = (string)$a['priority'];
                        $prioIcon = $prio==='urgent' ? 'bi-exclamation-octagon-fill' : ($prio==='important' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill');
                        $prioColor = $prio==='urgent' ? 'text-danger' : ($prio==='important' ? 'text-warning' : 'text-success');
                      ?>
                      <div class="p-3 rounded-4" style="background:#f8fafc;border:1px solid var(--border);">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                          <div class="fw-black" style="font-weight:900;">
                            <i class="bi <?= esc($prioIcon) ?> <?= esc($prioColor) ?> me-1"></i>
                            <?= esc($a['title']) ?>
                          </div>
                          <span class="pill"><?= esc(ucfirst($a['category'])) ?></span>
                        </div>
                        <div class="text-muted fw-semibold mt-1" style="font-size:13px;">
                          <?= esc(date('M d, Y', strtotime($a['start_date']))) ?>
                          <?php if (!empty($a['end_date'])): ?>
                            – <?= esc(date('M d, Y', strtotime($a['end_date']))) ?>
                          <?php endif; ?>
                        </div>
                        <div class="mt-2" style="white-space:pre-wrap;font-weight:800;">
                          <?= esc($a['message']) ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="fb-card">
              <div class="fb-card-h"><h6>🏠 Community</h6></div>
              <div class="fb-card-b">
                <div class="d-flex flex-column gap-2">
                  <div class="pill">Phase: <?= esc($phase) ?></div>
                  <div class="pill">Subdivision: South Meridian Homes Salitran</div>
                </div>
              </div>
            </div>
          </div>

          <!-- RIGHT FEED -->
          <div class="col-lg-8">

            <!-- Composer -->
            <div class="post mb-4" id="composer">
              <div class="post-h">
                <div class="post-avatar"><?= esc($initials) ?></div>
                <div>
                  <div class="post-name"><?= esc($fullName) ?></div>
                  <div class="post-meta">Post something to <?= esc($phase) ?> neighbors</div>
                </div>
              </div>
              <div class="post-b">
                <div class="composer-top">
                  <div class="mini-avatar"><?= esc(substr($user['first_name'] ?? 'H',0,1)) ?></div>
                  <textarea id="postContent" class="composer-textarea" rows="3" placeholder="What's happening in the neighborhood?"></textarea>
                </div>
                <div class="composer-actions">
                  <div class="text-muted fw-semibold" style="font-size:13px;">
                    Be respectful. Posts are visible to your phase only.
                  </div>
                  <button class="btn btn-hoa px-4" id="btnPost">
                    <i class="bi bi-send me-1"></i> Post
                  </button>
                </div>
              </div>
            </div>

            <div class="d-flex flex-column gap-4" id="feed">
              <?php if (empty($posts)): ?>
                <div class="fb-card"><div class="fb-card-b">
                  <div class="text-muted fw-semibold">No posts yet. Be the first to post in <?= esc($phase) ?>.</div>
                </div></div>
              <?php else: ?>
                <?php foreach($posts as $p): ?>
                  <?php
                    $pid = (int)$p['id'];
                    $author = trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''));
                    $authorInitial = strtoupper(substr((string)($p['first_name'] ?? 'H'),0,1));
                    $iLiked = ((int)$p['i_liked'] > 0);
                    $postLot = (string)($p['house_lot_number'] ?? '');

                    $isShare = !empty($p['shared_post_id']) && !empty($p['orig_id']);
                    $origAuthor = trim(($p['orig_first_name'] ?? '').' '.($p['orig_last_name'] ?? ''));
                    $origLot    = (string)($p['orig_house_lot'] ?? '');
                  ?>
                  <div class="post" data-post-id="<?= $pid ?>">
                    <div class="post-h">
                      <div class="post-avatar"><?= esc($authorInitial) ?></div>
                      <div>
                        <div class="post-name"><?= esc($author) ?></div>
                        <div class="post-meta">
                          <?= esc($phase) ?> • <?= esc($postLot) ?> • <?= esc(date('M d, Y h:i A', strtotime($p['created_at']))) ?>
                          <?php if ($isShare): ?> • <span class="text-success">shared a post</span><?php endif; ?>
                        </div>
                      </div>
                    </div>

                    <div class="post-b">
                      <?php if (trim((string)$p['content']) !== ''): ?>
                        <div class="post-content"><?= esc($p['content']) ?></div>
                      <?php endif; ?>

                      <?php if ($isShare): ?>
                        <div class="shared-box">
                          <div class="shared-title"><i class="bi bi-reply-fill me-1"></i>Shared post</div>
                          <div class="shared-meta"><?= esc($origAuthor) ?> • <?= esc($origLot) ?> • <?= esc(date('M d, Y h:i A', strtotime($p['orig_created_at']))) ?></div>
                          <div class="shared-content"><?= esc($p['orig_content'] ?? '') ?></div>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="post-stats">
                      <div>
                        <span class="me-3"><i class="bi bi-hand-thumbs-up-fill me-1 text-success"></i><span class="like-count"><?= (int)$p['like_count'] ?></span></span>
                        <span class="me-3"><i class="bi bi-chat-left-text-fill me-1"></i><span class="comment-count"><?= (int)$p['comment_count'] ?></span></span>
                        <span><i class="bi bi-reply-fill me-1"></i><span class="share-count"><?= (int)$p['share_count'] ?></span></span>
                      </div>
                      <div class="text-muted fw-semibold">Neighbors</div>
                    </div>

                    <div class="post-actions">
                      <button class="action-btn btn-like <?= $iLiked ? 'liked' : '' ?>">
                        <i class="bi bi-hand-thumbs-up<?= $iLiked ? '-fill' : '' ?> me-1"></i> Like
                      </button>
                      <button class="action-btn btn-focus-comment">
                        <i class="bi bi-chat-left-text me-1"></i> Comment
                      </button>
                      <button class="action-btn btn-share">
                        <i class="bi bi-reply me-1"></i> Share
                      </button>
                    </div>

                    <div class="comments">
                      <?php
                        $clist = $commentsByPost[$pid] ?? [];
                        foreach($clist as $c):
                          $cName = trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? ''));
                          $cInit = strtoupper(substr((string)($c['first_name'] ?? 'H'),0,1));
                      ?>
                        <div class="fb-comment">
                          <div class="fb-comment-avatar"><?= esc($cInit) ?></div>
                          <div class="fb-comment-bubble">
                            <div class="fb-comment-name"><?= esc($cName) ?></div>
                            <div class="fb-comment-text"><?= esc($c['comment']) ?></div>
                          </div>
                        </div>
                      <?php endforeach; ?>

                      <div class="comment-form">
                        <input class="comment-input" type="text" placeholder="Write a comment..." maxlength="500">
                        <button class="btn btn-hoa btn-comment-send"><i class="bi bi-send"></i></button>
                      </div>
                    </div>

                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

          </div>
        </div>

      </div><!-- /container-xl -->
    </div><!-- /blur-wrap -->

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

  </div><!-- /main-area -->
</div><!-- /app-shell -->

<!-- Share Modal -->
<div class="modal fade" id="shareModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px; overflow:hidden;">
      <div class="modal-header">
        <h5 class="modal-title fw-bold"><i class="bi bi-reply-fill me-1"></i> Share Post</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="text-muted fw-semibold mb-2">Add a message (optional):</div>
        <textarea id="shareMessage" class="form-control" rows="3" placeholder="Say something about this..."></textarea>
        <input type="hidden" id="sharePostId" value="0">
      </div>
      <div class="modal-footer">
        <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success" id="btnConfirmShare">Share</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Leaflet cover map
(function initCoverMap(){
  const mapEl = document.getElementById('coverMap');
  if (!mapEl) return;

  const lat = parseFloat(mapEl.dataset.lat || '');
  const lng = parseFloat(mapEl.dataset.lng || '');
  if (!isFinite(lat) || !isFinite(lng)) return;

  const map = L.map('coverMap', { zoomControl:false, attributionControl:false }).setView([lat, lng], 18);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:20 }).addTo(map);
  L.marker([lat, lng]).addTo(map);
  setTimeout(() => map.invalidateSize(), 250);
})();

async function postJSON(action, payload){
  const fd = new FormData();
  fd.append('action', action);
  for (const [k,v] of Object.entries(payload || {})) fd.append(k, v);

  const res = await fetch('homeowner_dashboard.php', { method:'POST', body: fd });
  return await res.json();
}

// Create post
document.getElementById('btnPost')?.addEventListener('click', async () => {
  const ta = document.getElementById('postContent');
  const content = (ta?.value || '').trim();
  if (!content) return alert('Post cannot be empty.');

  const r = await postJSON('create_post', { content });
  if (!r.success) return alert(r.message || 'Failed to post.');
  location.reload();
});

// Notification mark seen buttons
document.getElementById('btnMarkAllSeen')?.addEventListener('click', async () => {
  const r = await postJSON('mark_seen', { target:'all' });
  if (r.success) location.reload();
});
document.getElementById('btnSeenAnn')?.addEventListener('click', async () => {
  const r = await postJSON('mark_seen', { target:'ann' });
  if (r.success) location.reload();
});
document.getElementById('btnSeenCom')?.addEventListener('click', async () => {
  const r = await postJSON('mark_seen', { target:'comments' });
  if (r.success) location.reload();
});

// Share modal control
const shareModalEl = document.getElementById('shareModal');
const shareModal = shareModalEl ? new bootstrap.Modal(shareModalEl) : null;

document.getElementById('btnConfirmShare')?.addEventListener('click', async () => {
  const postId = parseInt(document.getElementById('sharePostId').value || '0', 10);
  const msg = (document.getElementById('shareMessage').value || '').trim();
  if (!postId) return;

  const btn = document.getElementById('btnConfirmShare');
  btn.disabled = true;
  btn.textContent = 'Sharing...';

  const r = await postJSON('share_post_create', { post_id: postId, share_message: msg });

  btn.disabled = false;
  btn.textContent = 'Share';

  if (!r.success) return alert(r.message || 'Share failed.');
  shareModal?.hide();
  location.reload();
});

// Feed actions
document.getElementById('feed')?.addEventListener('click', async (e) => {
  const postEl = e.target.closest('.post');
  if (!postEl) return;

  const postId = postEl.getAttribute('data-post-id');

  // Like
  if (e.target.closest('.btn-like')) {
    const r = await postJSON('toggle_like', { post_id: postId });
    if (!r.success) return alert(r.message || 'Failed.');

    const btn = postEl.querySelector('.btn-like');
    const icon = btn.querySelector('i');
    btn.classList.toggle('liked', !!r.liked);
    if (icon) icon.className = 'bi ' + (r.liked ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up') + ' me-1';
    postEl.querySelector('.like-count').textContent = r.like_count ?? 0;
    return;
  }

  // Focus comment
  if (e.target.closest('.btn-focus-comment')) {
    postEl.querySelector('.comment-input')?.focus();
    return;
  }

  // Open share modal
  if (e.target.closest('.btn-share')) {
    document.getElementById('sharePostId').value = postId;
    document.getElementById('shareMessage').value = '';
    shareModal?.show();
    return;
  }

  // Send comment
  if (e.target.closest('.btn-comment-send')) {
    const input = postEl.querySelector('.comment-input');
    const text = (input?.value || '').trim();
    if (!text) return;

    const r = await postJSON('add_comment', { post_id: postId, comment: text });
    if (!r.success) return alert(r.message || 'Failed to comment.');

    const form = postEl.querySelector('.comment-form');
    form.insertAdjacentHTML('beforebegin', r.comment_html || '');
    input.value = '';
    postEl.querySelector('.comment-count').textContent = r.comment_count ?? 0;
    return;
  }
});
</script>

<script>
(function sidebarInit(){
  const btnSidebar  = document.getElementById('btnSidebar');   // mobile open
  const btnCollapse = document.getElementById('btnCollapse');  // desktop collapse
  const backdrop    = document.getElementById('sbBackdrop');

  // restore collapsed state (desktop)
  const collapsed = localStorage.getItem('sb_collapsed') === '1';
  if (collapsed) document.body.classList.add('sb-collapsed');

  function closeMobile(){ document.body.classList.remove('sb-open'); }
  function toggleMobile(){ document.body.classList.toggle('sb-open'); }

  // Mobile open/close
  btnSidebar?.addEventListener('click', toggleMobile);
  backdrop?.addEventListener('click', closeMobile);

  // Desktop collapse toggle
  btnCollapse?.addEventListener('click', () => {
    document.body.classList.toggle('sb-collapsed');
    localStorage.setItem('sb_collapsed', document.body.classList.contains('sb-collapsed') ? '1' : '0');
  });

  // Close mobile sidebar when clicking a link
  document.querySelectorAll('.sidebar a.sb-link').forEach(a=>{
    a.addEventListener('click', ()=> closeMobile());
  });

  // ESC closes mobile sidebar
  document.addEventListener('keydown', (e)=>{
    if (e.key === 'Escape') closeMobile();
  });

  // If screen resized to desktop, ensure mobile offcanvas is closed
  window.addEventListener('resize', ()=>{
    if (window.innerWidth >= 992) closeMobile();
  });
})();
</script>

</body>
</html>
