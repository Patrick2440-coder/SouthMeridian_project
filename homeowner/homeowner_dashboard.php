<?php
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
  header("Location: ../index.php");
  exit;
}

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function tenant_can_access(string $module, ?array $tenant): bool {
  if (!$tenant) return false;

  $map = [
    'dashboard'     => true,
    'announcements' => !empty($tenant['can_announcements']),
    'pay_dues'      => !empty($tenant['can_pay_dues']),
    'rentals'       => !empty($tenant['can_rent']),
    'parking'       => !empty($tenant['can_parking']),
    'complaints'    => true,
    'public_chat'   => true,
    'voting'        => false,
    'tenant_mgmt'   => false
  ];

  return $map[$module] ?? false;
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
           can_pay_dues, can_rent, can_parking, can_announcements, registered_at
    FROM tenants
    WHERE id = ?
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
    SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number, latitude, longitude, created_at
    FROM homeowners
    WHERE id = ?
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
} else {
  if (empty($_SESSION['homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $hid = (int)$_SESSION['homeowner_id'];

  $stmt = $conn->prepare("
    SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number, latitude, longitude, created_at
    FROM homeowners
    WHERE id = ?
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

if ($isTenant) {
  $fullName = trim(($tenant['first_name'] ?? '') . ' ' . ($tenant['last_name'] ?? ''));
  $mustChange = false;
  $initials = strtoupper(substr($tenant['first_name'] ?? 'T', 0, 1) . substr($tenant['last_name'] ?? 'N', 0, 1));
  $accountStartRaw = (string)($tenant['registered_at'] ?? '');
} else {
  $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
  $mustChange = ((int)$user['must_change_password'] === 1);
  $initials = strtoupper(substr($user['first_name'] ?? 'H', 0, 1) . substr($user['last_name'] ?? 'O', 0, 1));
  $accountStartRaw = (string)($user['created_at'] ?? '');
}

$accountStartTs = strtotime($accountStartRaw);
if (!$accountStartTs) {
  $accountStartTs = time();
}

$accountStartYear  = (int)date('Y', $accountStartTs);
$accountStartMonth = (int)date('n', $accountStartTs);
$accountStartLabel = date('F Y', $accountStartTs);

$pageTitle  = "South Meridian Homes Salitran • " . $phase;

$activePage = basename($_SERVER['PHP_SELF'] ?? 'homeowner_dashboard.php');

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
if (!$isTenant && $mustChange && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password_submit'])) {
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

$stmt = $conn->prepare("INSERT IGNORE INTO homeowner_feed_state (homeowner_id) VALUES (?)");
$stmt->bind_param("i", $hid);
$stmt->execute();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json; charset=utf-8');

  if ($mustChange) {
    echo json_encode(['success'=>false,'message'=>'Please change your password first.']);
    exit;
  }

  $action = (string)$_POST['action'];

  if ($isTenant && in_array($action, ['toggle_like_ann', 'add_comment_ann'], true)) {
    echo json_encode(['success'=>false,'message'=>'You do not have access to that action.']);
    exit;
  }

  if ($action === 'toggle_like_ann') {
    $ann_id = (int)($_POST['announcement_id'] ?? 0);
    if ($ann_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid announcement.']); exit; }

    $stmt = $conn->prepare("SELECT id FROM announcement_likes WHERE announcement_id=? AND homeowner_id=? LIMIT 1");
    $stmt->bind_param("ii", $ann_id, $hid);
    $stmt->execute();
    $liked = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($liked) {
      $stmt = $conn->prepare("DELETE FROM announcement_likes WHERE announcement_id=? AND homeowner_id=?");
      $stmt->bind_param("ii", $ann_id, $hid);
      $ok = $stmt->execute();
      $stmt->close();
      $state = false;
    } else {
      $stmt = $conn->prepare("INSERT IGNORE INTO announcement_likes (announcement_id, homeowner_id) VALUES (?,?)");
      $stmt->bind_param("ii", $ann_id, $hid);
      $ok = $stmt->execute();
      $stmt->close();
      $state = true;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM announcement_likes WHERE announcement_id=?");
    $stmt->bind_param("i", $ann_id);
    $stmt->execute();
    $cnt = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    echo json_encode(['success'=>$ok,'liked'=>$state,'like_count'=>$cnt]);
    exit;
  }

  if ($action === 'add_comment_ann') {
    $ann_id = (int)($_POST['announcement_id'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));
    if ($ann_id <= 0 || $comment === '') {
      echo json_encode(['success'=>false,'message'=>'Comment cannot be empty.']);
      exit;
    }

    $stmt = $conn->prepare("INSERT INTO announcement_comments (announcement_id, homeowner_id, comment) VALUES (?,?,?)");
    $stmt->bind_param("iis", $ann_id, $hid, $comment);
    $ok = $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM announcement_comments WHERE announcement_id=?");
    $stmt->bind_param("i", $ann_id);
    $stmt->execute();
    $cc = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $avatarInitial = strtoupper(substr($user['first_name'] ?? 'H', 0, 1));

    echo json_encode([
      'success'=>$ok,
      'message'=>$ok?'Comment added.':'Failed to comment.',
      'comment_count'=>$cc,
      'comment_html'=>$ok ? '
        <div class="fb-comment">
          <div class="fb-comment-avatar">'.esc($avatarInitial).'</div>
          <div class="fb-comment-bubble">
            <div class="fb-comment-name">'.esc($fullName).'</div>
            <div class="fb-comment-text">'.esc($comment).'</div>
          </div>
        </div>' : ''
    ]);
    exit;
  }

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

$stmt = $conn->prepare("SELECT last_ann_seen, last_comment_seen FROM homeowner_feed_state WHERE homeowner_id=? LIMIT 1");
$stmt->bind_param("i", $hid);
$stmt->execute();
$state = $stmt->get_result()->fetch_assoc() ?: ['last_ann_seen'=>date('Y-m-d H:i:s'), 'last_comment_seen'=>date('Y-m-d H:i:s')];
$stmt->close();

$lastAnnSeen = (string)$state['last_ann_seen'];
$lastComSeen = (string)$state['last_comment_seen'];
$houseLot = (string)($user['house_lot_number'] ?? '');

date_default_timezone_set('Asia/Manila');
$now  = new DateTime('now');
$curYear  = (int)$now->format('Y');
$curMonth = (int)$now->format('n');

$monthlyDues = 0.00;
$stmt = $conn->prepare("SELECT monthly_dues FROM finance_dues_settings WHERE phase=? LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$monthlyDues = (float)(($stmt->get_result()->fetch_assoc()['monthly_dues'] ?? 0) ?: 0);
$stmt->close();

$paidMonths = [];
$paidRowsByMonth = [];
$stmt = $conn->prepare("
  SELECT pay_month, status, amount, paid_at, reference_no
  FROM finance_payments
  WHERE homeowner_id=? AND phase=? AND pay_year=? AND pay_month BETWEEN 1 AND ?
");
$stmt->bind_param("isii", $hid, $phase, $curYear, $curMonth);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()){
  $m = (int)$r['pay_month'];
  $paidRowsByMonth[$m] = $r;
  if (($r['status'] ?? 'paid') === 'paid') $paidMonths[$m] = true;
}
$stmt->close();

$duesStartMonthThisYear = 1;
if ($accountStartYear === $curYear) {
  $duesStartMonthThisYear = $accountStartMonth;
}

$dueMonths = [];
if ($accountStartYear <= $curYear) {
  for ($m = $duesStartMonthThisYear; $m <= $curMonth; $m++) {
    $dueMonths[] = $m;
  }
}

$unpaidMonths = [];
foreach ($dueMonths as $m) {
  if (empty($paidMonths[$m])) $unpaidMonths[] = $m;
}

$curMonthIsApplicable = in_array($curMonth, $dueMonths, true);
$curMonthPaid = !$curMonthIsApplicable ? true : !in_array($curMonth, $unpaidMonths, true);
$nextDueMonth = !empty($unpaidMonths) ? (int)$unpaidMonths[0] : 0;

function month_name($m){
  return date('F', mktime(0,0,0,(int)$m,1));
}

$annFeed = [];
$stmt = $conn->prepare("
  SELECT
    a.id, a.title, a.message, a.category, a.priority, a.start_date, a.end_date, a.created_at,
    a.audience, a.audience_value,
    (SELECT COUNT(*) FROM announcement_likes al WHERE al.announcement_id=a.id) AS like_count,
    (SELECT COUNT(*) FROM announcement_comments ac WHERE ac.announcement_id=a.id) AS comment_count,
    (SELECT COUNT(*) FROM announcement_likes al2 WHERE al2.announcement_id=a.id AND al2.homeowner_id=?) AS i_liked
  FROM announcements a
  LEFT JOIN announcement_recipients ar
    ON ar.announcement_id = a.id
   AND ar.recipient_type = 'homeowner'
   AND ar.homeowner_id = ?
  WHERE
    (a.phase = ? OR a.phase = 'Superadmin')
    AND a.start_date <= CURDATE()
    AND (a.end_date IS NULL OR a.end_date >= CURDATE())
    AND (
      a.audience = 'all'
      OR (a.audience = 'selected' AND ar.id IS NOT NULL)
      OR (a.audience = 'block' AND a.audience_value IS NOT NULL AND a.audience_value <> '' AND LOWER(?) LIKE CONCAT('%', LOWER(a.audience_value), '%'))
    )
  GROUP BY a.id
  ORDER BY FIELD(a.priority,'urgent','important','normal'), a.start_date DESC, a.created_at DESC
  LIMIT 25
");
$stmt->bind_param("iiss", $hid, $hid, $phase, $houseLot);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $annFeed[] = $r;
$stmt->close();

$stmt = $conn->prepare("
  SELECT COUNT(*) c
  FROM announcements a
  LEFT JOIN announcement_recipients ar
    ON ar.announcement_id = a.id
   AND ar.recipient_type='homeowner'
   AND ar.homeowner_id=?
  WHERE
    (a.phase = ? OR a.phase='Superadmin')
    AND a.created_at > ?
    AND a.start_date <= CURDATE()
    AND (a.end_date IS NULL OR a.end_date >= CURDATE())
    AND (
      a.audience='all'
      OR (a.audience='selected' AND ar.id IS NOT NULL)
      OR (a.audience='block' AND a.audience_value IS NOT NULL AND a.audience_value <> '' AND LOWER(?) LIKE CONCAT('%', LOWER(a.audience_value), '%'))
    )
");
$stmt->bind_param("isss", $hid, $phase, $lastAnnSeen, $houseLot);
$stmt->execute();
$newAnnCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("
  SELECT COUNT(*) c
  FROM announcement_comments ac
  JOIN announcements a ON a.id = ac.announcement_id
  LEFT JOIN announcement_recipients ar
    ON ar.announcement_id=a.id
   AND ar.recipient_type='homeowner'
   AND ar.homeowner_id=?
  WHERE
    (a.phase = ? OR a.phase='Superadmin')
    AND ac.created_at > ?
    AND (
      a.audience='all'
      OR (a.audience='selected' AND ar.id IS NOT NULL)
      OR (a.audience='block' AND a.audience_value IS NOT NULL AND a.audience_value <> '' AND LOWER(?) LIKE CONCAT('%', LOWER(a.audience_value), '%'))
    )
    AND ac.homeowner_id <> ?
");
$stmt->bind_param("isssi", $hid, $phase, $lastComSeen, $houseLot, $hid);
$stmt->execute();
$newComCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$notifCount = $newAnnCount + $newComCount;
$notifItems = [];

$stmt = $conn->prepare("
  SELECT a.id, a.title, a.created_at, 'announcement' AS kind
  FROM announcements a
  LEFT JOIN announcement_recipients ar
    ON ar.announcement_id=a.id
   AND ar.recipient_type='homeowner'
   AND ar.homeowner_id=?
  WHERE
    (a.phase = ? OR a.phase='Superadmin')
    AND a.created_at > ?
    AND a.start_date <= CURDATE()
    AND (a.end_date IS NULL OR a.end_date >= CURDATE())
    AND (
      a.audience='all'
      OR (a.audience='selected' AND ar.id IS NOT NULL)
      OR (a.audience='block' AND a.audience_value IS NOT NULL AND a.audience_value <> '' AND LOWER(?) LIKE CONCAT('%', LOWER(a.audience_value), '%'))
    )
  GROUP BY a.id
  ORDER BY a.created_at DESC
  LIMIT 6
");
$stmt->bind_param("isss", $hid, $phase, $lastAnnSeen, $houseLot);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $notifItems[] = $r;
$stmt->close();

$stmt = $conn->prepare("
  SELECT ac.id, ac.created_at, 'comment' AS kind,
         CONCAT(h.first_name,' ',h.last_name) AS actor_name,
         LEFT(ac.comment, 90) AS snippet
  FROM announcement_comments ac
  JOIN announcements a ON a.id=ac.announcement_id
  JOIN homeowners h ON h.id=ac.homeowner_id
  LEFT JOIN announcement_recipients ar
    ON ar.announcement_id=a.id
   AND ar.recipient_type='homeowner'
   AND ar.homeowner_id=?
  WHERE
    (a.phase = ? OR a.phase='Superadmin')
    AND ac.created_at > ?
    AND ac.homeowner_id <> ?
    AND (
      a.audience='all'
      OR (a.audience='selected' AND ar.id IS NOT NULL)
      OR (a.audience='block' AND a.audience_value IS NOT NULL AND a.audience_value <> ''
          AND LOWER(?) LIKE CONCAT('%', LOWER(a.audience_value), '%'))
    )
  ORDER BY ac.created_at DESC
  LIMIT 6
");
$stmt->bind_param("issis", $hid, $phase, $lastComSeen, $hid, $houseLot);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $notifItems[] = $r;
$stmt->close();

usort($notifItems, function($a,$b){
  return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});
$notifItems = array_slice($notifItems, 0, 8);

$commentsByAnn = [];
if (!empty($annFeed)) {
  $ids = array_map(fn($a)=>(int)$a['id'], $annFeed);
  $in  = implode(',', array_fill(0, count($ids), '?'));
  $types = str_repeat('i', count($ids));

  $sql = "
    SELECT ac.id, ac.announcement_id, ac.homeowner_id, ac.comment, ac.created_at,
           h.first_name, h.last_name
    FROM announcement_comments ac
    JOIN homeowners h ON h.id=ac.homeowner_id
    WHERE ac.announcement_id IN ($in)
    ORDER BY ac.created_at ASC
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();
  while($r = $res->fetch_assoc()){
    $aid = (int)$r['announcement_id'];
    if (!isset($commentsByAnn[$aid])) $commentsByAnn[$aid] = [];
    $commentsByAnn[$aid][] = $r;
  }
  $stmt->close();
}

$lat = $user['latitude'];
$lng = $user['longitude'];
$chatPages = ['homeowner_public_chat.php'];
$chatOpen = in_array($activePage, $chatPages, true);

$accessDeniedMsg = $_SESSION['access_denied'] ?? '';
unset($_SESSION['access_denied']);
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
<link rel="stylesheet" href="assets/css/homeowner_dashboard.css">
<style>
  html, body { max-width: 100%; overflow-x: hidden; }
  .app-shell{ position: relative; }
  .sidebar-overlay{ position: fixed; inset: 0; background: rgba(15, 23, 42, .45); z-index: 1040; opacity: 0; visibility: hidden; transition: .25s ease; }
  .sidebar-overlay.show{ opacity: 1; visibility: visible; }
  .sb-dd { display:flex; flex-direction:column; gap:6px; }
  .sb-dd-toggle{ display:flex; align-items:center; justify-content:space-between; gap:10px; width:100%; }
  .sb-dd-menu{ display:none; padding-left:12px; margin-top:2px; border-left:2px solid rgba(255,255,255,.08); }
  .sb-dd.open .sb-dd-menu{ display:block; }
  .sb-dd-caret{ transition: transform .15s ease; }
  .sb-dd.open .sb-dd-caret{ transform: rotate(180deg); }
  .pillx{ display:inline-flex; gap:8px; align-items:center; padding:8px 12px; border-radius:999px; background:#f1f5f9; font-weight:700; flex-wrap: wrap; }
  .req-list li{ margin-bottom: 6px; }
  .topbar-mobile-btn{ border: 1px solid #dbe3ea; background: #fff; color: #0f5132; border-radius: 10px; width: 42px; height: 42px; display: inline-flex; align-items: center; justify-content: center; }
  .notif-btn{ position: relative; }
  .notif-badge{ position:absolute; top:-5px; right:-5px; min-width:18px; height:18px; border-radius:999px; font-size:11px; font-weight:700; display:flex; align-items:center; justify-content:center; background:#dc3545; color:#fff; padding:0 5px; line-height:1; }
  .notif-menu{ width:min(360px, 95vw); border-radius:14px; overflow:hidden; }
  #coverMap{ width:100%; min-height:260px; }
  .fb-cover{ overflow:hidden; }
  .fb-profile-card, .fb-actions, .post-h, .post-stats, .comment-form{ min-width:0; }
  .comment-form{ display:flex; gap:8px; align-items:center; }
  .comment-input{ flex:1; min-width:0; }
  .post-content, .fb-comment-text, .fb-sub, .sb-name, .sb-meta{ word-wrap: break-word; overflow-wrap: anywhere; }
  .mobile-user-strip{ display:none; }
  @media (max-width: 991.98px){
    .sidebar{ position: fixed !important; top: 0; left: -290px; width: 280px !important; max-width: 85vw; height: 100vh; z-index: 1050; transition: left .25s ease; overflow-y: auto; }
    .sidebar.show{ left: 0; }
    .main-area{ width: 100% !important; margin-left: 0 !important; }
    .container-xl{ padding-left: 14px; padding-right: 14px; }
    .fb-profile-card{ flex-direction: column; align-items: flex-start !important; gap: 14px; }
    .fb-actions{ width: 100%; display:flex; flex-wrap: wrap; gap:10px; }
    .post-h, .post-stats{ flex-wrap: wrap; gap: 10px; }
    .pill, .pillx{ max-width: 100%; }
    .mobile-user-strip{ display:block; margin-bottom: 14px; }
    .desktop-user-text{ display:none !important; }
    #coverMap{ min-height:190px; }
  }
  @media (max-width: 767.98px){
    body{ font-size: 14px; }
    .navbar .container-xl{ gap: 10px; }
    .navbar-brand{ font-size: 1rem; max-width: 140px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .fb-name{ font-size: 1.4rem; }
    .fb-avatar{ width: 64px !important; height: 64px !important; font-size: 1.1rem !important; }
    .post-avatar, .fb-comment-avatar{ flex: 0 0 auto; }
    .comment-form{ align-items: stretch; }
    .btn-comment-send{ flex: 0 0 auto; }
    .notif-menu{ width:min(340px, 94vw); }
    .fb-card-h, .fb-card-b{ padding-left: 14px !important; padding-right: 14px !important; }
    .alert, .pill, .pillx{ font-size: 13px; }
    .lock-modal{ width: calc(100% - 20px) !important; margin: 10px auto; }
  }

  /* Desktop fixed sidebar */
@media (min-width: 992px){
  .sidebar{
    position: fixed !important;
    top: 0;
    left: 0;
    width: 280px;
    height: 100vh;
    overflow-y: auto;
    z-index: 1030;
  }

  .main-area{
    margin-left: 280px;
    width: calc(100% - 280px);
  }
}
.sidebar{
  overflow-y: auto;
  overflow-x: hidden;
}
</style>
</head>
<body>
<?php if ($accessDeniedMsg !== ''): ?>
<div class="position-fixed top-0 end-0 p-3" style="z-index:9999">
  <div id="accessDeniedToast" class="toast border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-header bg-danger text-white border-0">
      <strong class="me-auto">
        <i class="bi bi-shield-lock-fill me-2"></i>Access Denied
      </strong>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
    </div>
    <div class="toast-body bg-white text-dark">
      <?= esc($accessDeniedMsg) ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="app-shell">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>
  <?php include 'homeowner_sidebar.php'; ?>
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
            <div class="dropdown position-relative">
              <button class="notif-btn topbar-mobile-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                <i class="bi bi-bell fs-5"></i>
              </button>
              <?php if ($notifCount > 0): ?>
                <span class="notif-badge"><?= (int)$notifCount ?></span>
              <?php endif; ?>
              <div class="dropdown-menu dropdown-menu-end p-0 notif-menu">
                <div class="p-3 border-bottom d-flex align-items-center justify-content-between gap-2">
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
                          <div class="fw-bold"><i class="bi bi-chat-left-dots-fill me-1"></i> New comment</div>
                          <div class="fw-semibold"><?= esc($n['actor_name'] ?? 'Someone') ?>:</div>
                          <div class="text-muted fw-semibold"><?= esc($n['snippet'] ?? '') ?><?= strlen($n['snippet'] ?? '')>=90 ? '…' : '' ?></div>
                          <div class="text-muted small fw-semibold"><?= esc(date('M d, Y h:i A', strtotime($n['created_at']))) ?></div>
                        </div>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <div class="p-2 border-top d-flex gap-2 flex-wrap">
                  <button class="btn btn-sm btn-outline-success flex-fill" id="btnSeenAnn">Seen announcements</button>
                  <button class="btn btn-sm btn-outline-success flex-fill" id="btnSeenCom">Seen comments</button>
                </div>
              </div>
            </div>
            <div class="small text-muted desktop-user-text">
              Logged in as <b><?= esc($fullName) ?></b> (<?= esc($phase) ?><?= $isTenant ? ' • Tenant' : '' ?>)
            </div>
            <a href="logout.php" class="btn btn-sm btn-outline-success">Logout</a>
          </div>
        </div>
      </nav>

      <div class="container-xl my-4">
        <div class="mobile-user-strip">
          <div class="alert alert-light border shadow-sm mb-3">
            <div class="fw-bold"><?= esc($fullName) ?></div>
            <div class="small text-muted"><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?><?= $isTenant ? ' • Tenant' : '' ?></div>
          </div>
        </div>

        <div class="fb-cover">
          <div class="cover-badge">
            <span>South Meridian Homes Salitran</span>
            <small>• <?= esc($phase) ?></small>
          </div>
          <?php if (!empty($lat) && !empty($lng)): ?>
            <div id="coverMap" data-lat="<?= esc($lat) ?>" data-lng="<?= esc($lng) ?>"></div>
          <?php else: ?>
            <div class="h-100 w-100 d-flex align-items-center justify-content-center" style="min-height:190px;">
              <div class="text-muted fw-semibold">No location saved yet.</div>
            </div>
          <?php endif; ?>
        </div>

        <div class="fb-profile-row">
          <div class="fb-profile-card">
            <div class="fb-avatar"><?= esc($initials) ?></div>
            <div>
              <h2 class="fb-name"><?= esc($fullName) ?></h2>
              <div class="fb-sub"><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?><?= $isTenant ? ' • Tenant Account' : '' ?></div>
              <div class="mt-2 d-flex gap-2 flex-wrap">
                <span class="pill">📍 South Meridian Homes Salitran</span>
                <span class="pill">🏠 <?= esc($user['house_lot_number'] ?? '') ?></span>
              </div>
            </div>
            <div class="fb-actions">
              <span class="pill"><i class="bi bi-geo-alt-fill"></i> Cover = Map</span>
              <?php if (!$isTenant || tenant_can_access('announcements', $tenant)): ?>
                <a class="btn btn-hoa" href="#feed"><i class="bi bi-megaphone-fill me-1"></i> Feed</a>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="row g-4 mt-2">
          <div class="col-lg-4">
            <div class="fb-card mb-4">
              <div class="fb-card-h">
                <h6>🏠 Community</h6>
                <span class="pill"><?= count($annFeed) ?> posts</span>
              </div>
              <div class="fb-card-b">
                <div class="d-flex flex-column gap-2">
                  <div class="pill">Phase: <?= esc($phase) ?></div>
                  <div class="pill">Subdivision: South Meridian Homes Salitran</div>
                  <div class="pill">Lot: <?= esc($houseLot) ?></div>
                  <?php if ($isTenant): ?>
                    <div class="pill">Account Type: Tenant</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="fb-card">
              <div class="fb-card-h"><h6>ℹ️ Tip</h6></div>
              <div class="fb-card-b">
                <div class="text-muted fw-semibold">
                  This feed shows official announcements available to your account.
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-8">
            <?php if (!$isTenant || !empty($tenant['can_pay_dues'])): ?>
            <div class="fb-card mb-4">
              <div class="fb-card-h">
                <h6>💳 Monthly Dues Reminder</h6>
                <span class="pill">₱<?= number_format((float)$monthlyDues, 2) ?>/month</span>
              </div>
              <div class="fb-card-b">
                <?php if ($accountStartYear > $curYear): ?>
                  <div class="alert alert-info mb-0">
                    Your dues will start in <b><?= esc($accountStartLabel) ?></b>.
                  </div>
                <?php elseif (empty($unpaidMonths)): ?>
                  <div class="alert alert-success mb-0">
                    ✅ You are fully paid for <?= esc($curYear) ?> (<?= esc(month_name($duesStartMonthThisYear)) ?>–<?= esc(month_name($curMonth)) ?>). Thank you!
                  </div>
                  <div class="mt-2 text-muted small fw-semibold">
                    Dues started from your account creation month: <?= esc($accountStartLabel) ?>.
                  </div>
                <?php else: ?>
                  <div class="alert alert-danger">
                    <div class="fw-bold mb-1">⚠️ You have unpaid monthly dues.</div>
                    <div class="fw-semibold mb-2">
                      Dues start from: <b><?= esc($accountStartLabel) ?></b>
                    </div>
                    <div class="fw-semibold">
                      Unpaid months (<?= esc($curYear) ?>):
                      <?php foreach($unpaidMonths as $m): ?>
                        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle me-1">
                          <?= esc(month_name($m)) ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                    <div class="mt-2 fw-semibold">
                      Next due: <b><?= esc(month_name($nextDueMonth)) ?> <?= esc($curYear) ?></b>
                    </div>
                  </div>
                  <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-success fw-semibold" href="homeowner_pay_dues.php">
                      <i class="bi bi-cash-coin me-1"></i> Pay Monthly Dues
                    </a>
                    <span class="pillx">
                      Current month: <?= esc(month_name($curMonth)) ?> —
                      <?php if (!$curMonthIsApplicable): ?>
                        <span class="text-muted">NOT YET APPLICABLE</span>
                      <?php elseif ($curMonthPaid): ?>
                        <span class="text-success">PAID ✅</span>
                      <?php else: ?>
                        <span class="text-danger">NOT PAID ❌</span>
                      <?php endif; ?>
                    </span>
                  </div>
                  <div class="mt-2 text-muted small fw-semibold">
                    Only the months starting from your account creation month are included.
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <?php if (!$isTenant || tenant_can_access('announcements', $tenant)): ?>
            <div class="d-flex flex-column gap-4" id="feed">
              <?php if (empty($annFeed)): ?>
                <div class="fb-card"><div class="fb-card-b">
                  <div class="text-muted fw-semibold">No announcements visible to you right now.</div>
                </div></div>
              <?php else: ?>
                <?php foreach($annFeed as $a): ?>
                  <?php
                    $aid = (int)$a['id'];
                    $iLiked = ((int)$a['i_liked'] > 0);
                    $prio = (string)$a['priority'];
                    $prioIcon = $prio==='urgent' ? 'bi-exclamation-octagon-fill' : ($prio==='important' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill');
                    $prioColor = $prio==='urgent' ? 'text-danger' : ($prio==='important' ? 'text-warning' : 'text-success');
                  ?>
                  <div class="post" data-ann-id="<?= $aid ?>">
                    <div class="post-h">
                      <div class="post-avatar">A</div>
                      <div style="flex:1; min-width:0;">
                        <div class="post-name">
                          <i class="bi <?= esc($prioIcon) ?> <?= esc($prioColor) ?> me-1"></i>
                          <?= esc($a['title']) ?>
                        </div>
                        <div class="post-meta">
                          <?= esc($a['category']) ?> • <?= esc($phase) ?> • <?= esc(date('M d, Y h:i A', strtotime($a['created_at']))) ?>
                        </div>
                      </div>
                      <span class="badge-soft"><?= esc(strtoupper($a['audience'])) ?></span>
                    </div>
                    <div class="post-b">
                      <div class="post-content"><?= esc($a['message']) ?></div>
                    </div>
                    <div class="post-stats">
                      <div>
                        <span class="me-3"><i class="bi bi-hand-thumbs-up-fill me-1 text-success"></i><span class="like-count"><?= (int)$a['like_count'] ?></span></span>
                        <span class="me-3"><i class="bi bi-chat-left-text-fill me-1"></i><span class="comment-count"><?= (int)$a['comment_count'] ?></span></span>
                      </div>
                      <div class="text-muted fw-semibold">Official</div>
                    </div>

                    <?php if (!$isTenant): ?>
                    <div class="post-actions">
                      <button class="action-btn btn-like <?= $iLiked ? 'liked' : '' ?>">
                        <i class="bi bi-hand-thumbs-up<?= $iLiked ? '-fill' : '' ?> me-1"></i> Like
                      </button>
                      <button class="action-btn btn-focus-comment">
                        <i class="bi bi-chat-left-text me-1"></i> Comment
                      </button>
                    </div>
                    <?php endif; ?>

                    <div class="comments">
                      <?php
                        $clist = $commentsByAnn[$aid] ?? [];
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

                      <?php if (!$isTenant): ?>
                      <div class="comment-form">
                        <input class="comment-input" type="text" placeholder="Write a comment..." maxlength="500">
                        <button class="btn btn-hoa btn-comment-send" type="button"><i class="bi bi-send"></i></button>
                      </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <?php else: ?>
              <div class="fb-card">
                <div class="fb-card-b">
                  <div class="text-muted fw-semibold">You do not have access to announcements.</div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?php if (!$isTenant && $mustChange): ?>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
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
  window.addEventListener('resize', () => setTimeout(() => map.invalidateSize(), 200));
})();

async function postJSON(action, payload){
  const fd = new FormData();
  fd.append('action', action);
  for (const [k,v] of Object.entries(payload || {})) fd.append(k, v);
  const res = await fetch('homeowner_dashboard.php', { method:'POST', body: fd });
  return await res.json();
}

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

document.getElementById('feed')?.addEventListener('click', async (e) => {
  const postEl = e.target.closest('.post');
  if (!postEl) return;
  const annId = postEl.getAttribute('data-ann-id');

  if (e.target.closest('.btn-like')) {
    const r = await postJSON('toggle_like_ann', { announcement_id: annId });
    if (!r.success) return alert(r.message || 'Failed.');
    const btn = postEl.querySelector('.btn-like');
    const icon = btn.querySelector('i');
    btn.classList.toggle('liked', !!r.liked);
    if (icon) icon.className = 'bi ' + (r.liked ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up') + ' me-1';
    postEl.querySelector('.like-count').textContent = r.like_count ?? 0;
    return;
  }

  if (e.target.closest('.btn-focus-comment')) {
    postEl.querySelector('.comment-input')?.focus();
    return;
  }

  if (e.target.closest('.btn-comment-send')) {
    const input = postEl.querySelector('.comment-input');
    const text = (input?.value || '').trim();
    if (!text) return;
    const r = await postJSON('add_comment_ann', { announcement_id: annId, comment: text });
    if (!r.success) return alert(r.message || 'Failed to comment.');
    const form = postEl.querySelector('.comment-form');
    form.insertAdjacentHTML('beforebegin', r.comment_html || '');
    input.value = '';
    postEl.querySelector('.comment-count').textContent = r.comment_count ?? 0;
    return;
  }
});

(function(){
  const wrap = document.getElementById('sbParking');
  const btn  = document.getElementById('sbParkingToggle');
  if(!wrap || !btn) return;
  btn.addEventListener('click', () => wrap.classList.toggle('open'));
})();

(function(){
  const tenantWrap = document.getElementById('sbTenant');
  const tenantBtn  = document.getElementById('sbTenantToggle');
  if(!tenantWrap || !tenantBtn) return;
  tenantBtn.addEventListener('click', () => tenantWrap.classList.toggle('open'));
})();

(function(){
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const toggle  = document.getElementById('sidebarToggle');
  if (!sidebar || !overlay || !toggle) return;
  function openSidebar(){ sidebar.classList.add('show'); overlay.classList.add('show'); document.body.style.overflow = 'hidden'; }
  function closeSidebar(){ sidebar.classList.remove('show'); overlay.classList.remove('show'); document.body.style.overflow = ''; }
  toggle.addEventListener('click', openSidebar);
  overlay.addEventListener('click', closeSidebar);
  window.addEventListener('resize', function(){ if (window.innerWidth >= 992) closeSidebar(); });
  sidebar.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', function(){ if (window.innerWidth < 992) closeSidebar(); });
  });
})();

document.addEventListener('DOMContentLoaded', function () {
  const deniedToast = document.getElementById('accessDeniedToast');
  if (deniedToast) {
    new bootstrap.Toast(deniedToast, { delay: 3500 }).show();
  }
});
</script>
</body>
</html>