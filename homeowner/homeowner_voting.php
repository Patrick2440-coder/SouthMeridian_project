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
  WHERE id=? LIMIT 1
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
$fullName   = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$mustChange = ((int)$user['must_change_password'] === 1);
$initials   = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));
$pageTitle  = "South Meridian Homes Salitran • Voting • ".$phase;
$activePage = basename($_SERVER['PHP_SELF'] ?? 'homeowner_voting.php');

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

if ($mustChange) {
  header("Location: homeowner_dashboard.php");
  exit;
}

$lat = $user['latitude'];
$lng = $user['longitude'];
$houseLot = (string)($user['house_lot_number'] ?? '');

$positions = ['President','Vice President','Secretary','Treasurer','Auditor','Board of Director'];

date_default_timezone_set('Asia/Manila');
$yearNow = (int)date('Y');

$currentElection = null;
$electionState = 'not_started'; // not_started | draft | active | finished
$activeElectionId = 0;
$nomineesByPosition = [];
$votedMap = [];          // for single-vote positions
$votedBoardIds = [];     // for Board of Director multi-vote
$votedCounts = [];       // count per position
$successMsg = '';
$errorMsg = '';
$resultsByPosition = [];

/* =========================
   FIND CURRENT ELECTION STATE
   ========================= */
$stmt = $conn->prepare("
  SELECT *
  FROM election_sessions
  WHERE phase=? AND YEAR(created_at)=? AND status='active'
  ORDER BY id DESC
  LIMIT 1
");
$stmt->bind_param("si", $phase, $yearNow);
$stmt->execute();
$currentElection = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($currentElection) {
  $electionState = 'active';
} else {
  $stmt = $conn->prepare("
    SELECT *
    FROM election_sessions
    WHERE phase=? AND YEAR(created_at)=? AND status='finished'
    ORDER BY ended_at DESC, id DESC
    LIMIT 1
  ");
  $stmt->bind_param("si", $phase, $yearNow);
  $stmt->execute();
  $currentElection = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($currentElection) {
    $electionState = 'finished';
  } else {
    $stmt = $conn->prepare("
      SELECT *
      FROM election_sessions
      WHERE phase=? AND YEAR(created_at)=? AND status='draft'
      ORDER BY id DESC
      LIMIT 1
    ");
    $stmt->bind_param("si", $phase, $yearNow);
    $stmt->execute();
    $currentElection = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($currentElection) {
      $electionState = 'draft';
    }
  }
}

/* =========================
   HANDLE VOTE SUBMIT
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vote'])) {
  $electionId = (int)($_POST['election_id'] ?? 0);
  $position = trim((string)($_POST['position'] ?? ''));

  if ($electionId <= 0 || $position === '') {
    $errorMsg = "Invalid vote submission.";
  } else {
    $stmt = $conn->prepare("
      SELECT id, phase, status
      FROM election_sessions
      WHERE id=? LIMIT 1
    ");
    $stmt->bind_param("i", $electionId);
    $stmt->execute();
    $sessionRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sessionRow || $sessionRow['status'] !== 'active' || $sessionRow['phase'] !== $phase) {
      $errorMsg = "Voting is not active.";
    } else {

      if ($position === 'Board of Director') {
        $nomineeIds = $_POST['nominee_homeowner_id'] ?? [];

        if (!is_array($nomineeIds)) {
          $nomineeIds = [];
        }

        $nomineeIds = array_values(array_unique(array_map('intval', $nomineeIds)));
        $nomineeIds = array_filter($nomineeIds, fn($v) => $v > 0);

        if (count($nomineeIds) < 1) {
          $errorMsg = "Please select at least 1 Board of Director nominee.";
        } elseif (count($nomineeIds) > 6) {
          $errorMsg = "You can only vote for up to 6 Board of Directors.";
        } else {
          $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM election_votes
            WHERE election_id=? AND voter_homeowner_id=? AND position='Board of Director'
          ");
          $stmt->bind_param("ii", $electionId, $hid);
          $stmt->execute();
          $alreadyCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
          $stmt->close();

          if ($alreadyCount >= 6) {
            $errorMsg = "You already voted the maximum number for Board of Director.";
          } else {
            // verify all selected nominees are valid for this election/position
            $placeholders = implode(',', array_fill(0, count($nomineeIds), '?'));
            $types = 'is' . str_repeat('i', count($nomineeIds));
            $sql = "
              SELECT homeowner_id
              FROM election_nominations
              WHERE election_id=? AND position=? AND homeowner_id IN ($placeholders)
            ";
            $stmt = $conn->prepare($sql);
            $bindValues = array_merge([$electionId, $position], $nomineeIds);
            $stmt->bind_param($types, ...$bindValues);
            $stmt->execute();
            $validRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $validIds = array_map(fn($r) => (int)$r['homeowner_id'], $validRows);
            sort($validIds);
            $checkIds = $nomineeIds;
            sort($checkIds);

            if ($validIds !== $checkIds) {
              $errorMsg = "One or more selected nominees are invalid.";
            } else {
              $conn->begin_transaction();
              try {
                $stmtCheck = $conn->prepare("
                  SELECT nominee_homeowner_id
                  FROM election_votes
                  WHERE election_id=? AND voter_homeowner_id=? AND position='Board of Director'
                ");
                $stmtCheck->bind_param("ii", $electionId, $hid);
                $stmtCheck->execute();
                $existingRows = $stmtCheck->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmtCheck->close();

                $existingIds = array_map(fn($r) => (int)$r['nominee_homeowner_id'], $existingRows);

                foreach ($nomineeIds as $nomineeId) {
                  if (in_array($nomineeId, $existingIds, true)) {
                    throw new Exception("You already selected one of these Board of Director nominees.");
                  }
                }

                $stmtInsert = $conn->prepare("
                  INSERT INTO election_votes (election_id, phase, position, voter_homeowner_id, nominee_homeowner_id)
                  VALUES (?, ?, 'Board of Director', ?, ?)
                ");

                foreach ($nomineeIds as $nomineeId) {
                  $stmtInsert->bind_param("isii", $electionId, $phase, $hid, $nomineeId);
                  $stmtInsert->execute();
                }
                $stmtInsert->close();

                $conn->commit();
                $successMsg = "Your Board of Director votes have been submitted successfully.";
              } catch (Throwable $e) {
                $conn->rollback();
                $errorMsg = $e->getMessage();
              }
            }
          }
        }

      } else {
        $nomineeId = (int)($_POST['nominee_homeowner_id'] ?? 0);

        if ($nomineeId <= 0) {
          $errorMsg = "Please select a nominee.";
        } else {
          $stmt = $conn->prepare("
            SELECT id
            FROM election_nominations
            WHERE election_id=? AND position=? AND homeowner_id=?
            LIMIT 1
          ");
          $stmt->bind_param("isi", $electionId, $position, $nomineeId);
          $stmt->execute();
          $validNominee = $stmt->get_result()->fetch_assoc();
          $stmt->close();

          if (!$validNominee) {
            $errorMsg = "Invalid nominee selected.";
          } else {
            $stmt = $conn->prepare("
              SELECT COUNT(*) AS total
              FROM election_votes
              WHERE election_id=? AND voter_homeowner_id=? AND position=?
            ");
            $stmt->bind_param("iis", $electionId, $hid, $position);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ((int)$row['total'] >= 1) {
              $errorMsg = "You already voted for this position.";
            } else {
              $stmt = $conn->prepare("
                INSERT INTO election_votes (election_id, phase, position, voter_homeowner_id, nominee_homeowner_id)
                VALUES (?, ?, ?, ?, ?)
              ");
              $stmt->bind_param("issii", $electionId, $phase, $position, $hid, $nomineeId);
              $stmt->execute();
              $stmt->close();

              $successMsg = "Your vote for {$position} has been submitted successfully.";
            }
          }
        }
      }
    }
  }
}

/* =========================
   LOAD ACTIVE ELECTION NOMINEES + USER VOTES
   ========================= */
if ($electionState === 'active' && $currentElection) {
  $activeElectionId = (int)$currentElection['id'];

  $stmt = $conn->prepare("
    SELECT
      n.position,
      n.homeowner_id,
      h.first_name,
      h.last_name,
      h.house_lot_number
    FROM election_nominations n
    INNER JOIN homeowners h ON h.id = n.homeowner_id
    WHERE n.election_id=?
    ORDER BY FIELD(n.position,'President','Vice President','Secretary','Treasurer','Auditor','Board of Director'),
             h.first_name, h.last_name
  ");
  $stmt->bind_param("i", $activeElectionId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  foreach ($rows as $r) {
    $pos = (string)$r['position'];
    if (!isset($nomineesByPosition[$pos])) $nomineesByPosition[$pos] = [];
    $nomineesByPosition[$pos][] = $r;
  }

  $stmt = $conn->prepare("
    SELECT position, nominee_homeowner_id
    FROM election_votes
    WHERE election_id=? AND voter_homeowner_id=?
  ");
  $stmt->bind_param("ii", $activeElectionId, $hid);
  $stmt->execute();
  $votedRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  foreach ($votedRows as $v) {
    $pos = (string)$v['position'];
    $nomId = (int)$v['nominee_homeowner_id'];

    if (!isset($votedCounts[$pos])) $votedCounts[$pos] = 0;
    $votedCounts[$pos]++;

    if ($pos === 'Board of Director') {
      $votedBoardIds[] = $nomId;
    } else {
      $votedMap[$pos] = $nomId;
    }
  }
}

/* =========================
   LOAD FINISHED ELECTION RESULTS
   ========================= */
if ($electionState === 'finished' && $currentElection) {
  $finishedElectionId = (int)$currentElection['id'];

  foreach ($positions as $position) {
    $stmt = $conn->prepare("
      SELECT
        h.id AS nominee_homeowner_id,
        CONCAT(h.first_name, ' ', h.last_name) AS nominee_name,
        h.house_lot_number,
        COUNT(v.id) AS total_votes
      FROM election_nominations n
      INNER JOIN homeowners h ON h.id = n.homeowner_id
      LEFT JOIN election_votes v
        ON v.election_id = n.election_id
       AND v.nominee_homeowner_id = n.homeowner_id
       AND v.position = n.position
      WHERE n.election_id=? AND n.position=?
      GROUP BY h.id, h.first_name, h.last_name, h.house_lot_number
      ORDER BY total_votes DESC, nominee_name ASC
    ");
    $stmt->bind_param("is", $finishedElectionId, $position);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($rows) {
      $resultsByPosition[$position] = $rows;
    }
  }
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
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="assets/css/homeowner_dashboard.css">

<style>
  html, body {
    max-width: 100%;
    overflow-x: hidden;
  }

  .app-shell{
    position: relative;
  }

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

  .pillx{
    display:inline-flex;
    gap:8px;
    align-items:center;
    padding:8px 12px;
    border-radius:999px;
    background:#f1f5f9;
    font-weight:700;
    flex-wrap: wrap;
  }

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

  #coverMap{
    width:100%;
    min-height:260px;
  }

  .fb-cover{
    overflow:hidden;
  }

  .mobile-user-strip{
    display:none;
  }

  .vote-card{
    background:#fff;
    border:1px solid #eef2f7;
    border-radius:18px;
    box-shadow:0 10px 28px rgba(15,23,42,.05);
    overflow:hidden;
  }

  .vote-card-head{
    padding:18px 20px;
    border-bottom:1px solid #eef2f7;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
  }

  .vote-card-body{
    padding:20px;
  }

  .vote-position{
    border:1px solid #e8edf3;
    border-radius:16px;
    overflow:hidden;
    background:#fff;
  }

  .vote-position-head{
    padding:14px 18px;
    background:#f8fafc;
    border-bottom:1px solid #eef2f7;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
  }

  .vote-position-body{
    padding:16px 18px;
  }

  .vote-option{
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:12px 14px;
    margin-bottom:10px;
    background:#fff;
    transition:.18s ease;
    cursor:pointer;
  }

  .vote-option:hover{
    border-color:#198754;
    background:#f8fff9;
  }

  .vote-state{
    border-radius:16px;
    padding:18px;
    font-weight:600;
  }

  .result-rank{
    width:36px;
    height:36px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-weight:800;
    background:#e8f5ee;
    color:#198754;
  }

  .board-counter{
    font-size: 13px;
    font-weight: 700;
    color: #198754;
    background: #e8f5ee;
    border: 1px solid #cfead8;
    padding: 6px 10px;
    border-radius: 999px;
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

    .sidebar.show{
      left: 0;
    }

    .main-area{
      width: 100% !important;
      margin-left: 0 !important;
    }

    .container-xl{
      padding-left: 14px;
      padding-right: 14px;
    }

    .fb-profile-card{
      flex-direction: column;
      align-items: flex-start !important;
      gap: 14px;
    }

    .fb-actions{
      width: 100%;
      display:flex;
      flex-wrap: wrap;
      gap:10px;
    }

    .mobile-user-strip{
      display:block;
      margin-bottom: 14px;
    }

    .desktop-user-text{
      display:none !important;
    }

    #coverMap{
      min-height:190px;
    }
  }

  @media (max-width: 767.98px){
    body{
      font-size: 14px;
    }

    .navbar .container-xl{
      gap: 10px;
    }

    .navbar-brand{
      font-size: 1rem;
      max-width: 140px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .fb-name{
      font-size: 1.4rem;
    }

    .fb-avatar{
      width: 64px !important;
      height: 64px !important;
      font-size: 1.1rem !important;
    }

    .vote-card-head,
    .vote-card-body,
    .vote-position-head,
    .vote-position-body,
    .fb-card-h,
    .fb-card-b{
      padding-left:14px !important;
      padding-right:14px !important;
    }

    .lock-modal{
      width: calc(100% - 20px) !important;
      margin: 10px auto;
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
            <div class="fb-sub"><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?></div>
            <div class="mt-2 d-flex gap-2 flex-wrap">
              <span class="pill">📍 South Meridian Homes Salitran</span>
              <span class="pill">🏠 <?= esc($user['house_lot_number'] ?? '') ?></span>
            </div>
          </div>

          <div class="fb-actions">
            <span class="pill"><i class="bi bi-check2-square"></i> HOA Voting</span>
            <a class="btn btn-hoa" href="#votingSection"><i class="bi bi-person-check-fill me-1"></i> View Voting</a>
          </div>
        </div>
      </div>

      <div class="row g-4 mt-2" id="votingSection">

        <div class="col-lg-4">
          <div class="fb-card mb-4">
            <div class="fb-card-h">
              <h6>🗳️ Election Info</h6>
              <span class="pill"><?= esc($phase) ?></span>
            </div>
            <div class="fb-card-b">
              <div class="d-flex flex-column gap-2">
                <div class="pill">Year: <?= (int)$yearNow ?></div>
                <div class="pill">Phase: <?= esc($phase) ?></div>
                <div class="pill">Homeowner: <?= esc($fullName) ?></div>
              </div>
            </div>
          </div>

          <div class="fb-card">
            <div class="fb-card-h"><h6>ℹ️ Voting Guide</h6></div>
            <div class="fb-card-b">
              <div class="text-muted fw-semibold">
                President, Vice President, Secretary, Treasurer, and Auditor allow only 1 vote each. Board of Director allows up to 6 votes.
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-8">

          <div class="vote-card">
            <div class="vote-card-head">
              <div>
                <h5 class="mb-1">HOA Voting</h5>
                <div class="text-muted">Phase: <b><?= esc($phase) ?></b> • Year: <b><?= (int)$yearNow ?></b></div>
              </div>

              <?php if ($electionState === 'active'): ?>
                <span class="badge bg-success fs-6">Voting Active</span>
              <?php elseif ($electionState === 'finished'): ?>
                <span class="badge bg-danger fs-6">Voting Finished</span>
              <?php elseif ($electionState === 'draft'): ?>
                <span class="badge bg-warning text-dark fs-6">Voting Not Started Yet</span>
              <?php else: ?>
                <span class="badge bg-secondary fs-6">No Voting Session</span>
              <?php endif; ?>
            </div>

            <div class="vote-card-body">

              <?php if ($successMsg !== ''): ?>
                <div class="alert alert-success"><?= esc($successMsg) ?></div>
              <?php endif; ?>

              <?php if ($errorMsg !== ''): ?>
                <div class="alert alert-danger"><?= esc($errorMsg) ?></div>
              <?php endif; ?>

              <?php if ($electionState === 'active' && $currentElection): ?>

                <div class="alert alert-info">
                  <div><strong>Election:</strong> <?= esc($currentElection['title'] ?? ('HOA Election '.$yearNow)) ?></div>
                  <div><strong>Started At:</strong> <?= esc($currentElection['started_at'] ?? '-') ?></div>
                  <div class="mt-2">Please vote once for each position. For Board of Director, you may select up to 6 nominees.</div>
                </div>

                <?php foreach ($positions as $position): ?>
                  <?php
                    $boardAlreadyCount = (int)($votedCounts['Board of Director'] ?? 0);
                    $singleAlreadyVoted = isset($votedMap[$position]);
                    $isBoard = ($position === 'Board of Director');
                    $boardDone = $isBoard && $boardAlreadyCount >= 6;
                  ?>
                  <div class="vote-position mb-3">
                    <div class="vote-position-head">
                      <div class="fw-bold"><?= esc($position) ?></div>

                      <?php if ($isBoard): ?>
                        <?php if ($boardDone): ?>
                          <span class="badge bg-success">Completed (6/6)</span>
                        <?php else: ?>
                          <span class="badge bg-warning text-dark">Selected <?= $boardAlreadyCount ?>/6</span>
                        <?php endif; ?>
                      <?php else: ?>
                        <?php if ($singleAlreadyVoted): ?>
                          <span class="badge bg-success">Already Voted</span>
                        <?php else: ?>
                          <span class="badge bg-warning text-dark">Not Yet Voted</span>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>

                    <div class="vote-position-body">
                      <?php if (empty($nomineesByPosition[$position])): ?>
                        <div class="text-muted">No nominees available for this position.</div>

                      <?php elseif ($isBoard && $boardDone): ?>
                        <div class="alert alert-success mb-0">
                          You already completed your <b>6 Board of Director votes</b>.
                        </div>

                      <?php elseif (!$isBoard && $singleAlreadyVoted): ?>
                        <?php
                          $chosenName = '';
                          foreach ($nomineesByPosition[$position] as $n) {
                            if ((int)$n['homeowner_id'] === (int)$votedMap[$position]) {
                              $chosenName = trim(($n['first_name'] ?? '') . ' ' . ($n['last_name'] ?? ''));
                              break;
                            }
                          }
                        ?>
                        <div class="alert alert-success mb-0">
                          You already voted for <b><?= esc($position) ?></b>
                          <?php if ($chosenName !== ''): ?>
                            — <b><?= esc($chosenName) ?></b>
                          <?php endif; ?>.
                        </div>

                      <?php else: ?>

                        <form method="post" class="<?= $isBoard ? 'board-form' : '' ?>">
                          <input type="hidden" name="submit_vote" value="1">
                          <input type="hidden" name="election_id" value="<?= (int)$activeElectionId ?>">
                          <input type="hidden" name="position" value="<?= esc($position) ?>">

                          <?php if ($isBoard): ?>
                            <div class="board-counter mb-3" data-board-counter>
                              <?= $boardAlreadyCount ?> of 6 selected
                            </div>
                          <?php endif; ?>

                          <?php foreach ($nomineesByPosition[$position] as $n): ?>
                            <?php
                              $nid = (int)$n['homeowner_id'];
                              $checked = ($isBoard && in_array($nid, $votedBoardIds, true));
                            ?>
                            <label class="vote-option d-block">
                              <div class="form-check m-0">
                                <input
                                  class="form-check-input <?= $isBoard ? 'board-checkbox' : '' ?>"
                                  type="<?= $isBoard ? 'checkbox' : 'radio' ?>"
                                  name="<?= $isBoard ? 'nominee_homeowner_id[]' : 'nominee_homeowner_id' ?>"
                                  value="<?= $nid ?>"
                                  <?= $checked ? 'checked disabled' : '' ?>
                                  <?= !$isBoard ? 'required' : '' ?>
                                >
                                <span class="form-check-label ms-2">
                                  <b><?= esc(trim(($n['first_name'] ?? '') . ' ' . ($n['last_name'] ?? ''))) ?></b>
                                  <span class="text-muted">— <?= esc($n['house_lot_number'] ?? '') ?></span>
                                </span>
                              </div>
                            </label>
                          <?php endforeach; ?>

                          <button type="submit" class="btn btn-success mt-2">
                            <i class="bi bi-check-circle me-1"></i>
                            <?= $isBoard ? 'Submit Board of Director Votes' : 'Submit Vote for '.esc($position) ?>
                          </button>
                        </form>

                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>

              <?php elseif ($electionState === 'finished' && $currentElection): ?>

                <div class="vote-state alert alert-danger mb-3">
                  <div class="h5 mb-2"><i class="bi bi-x-circle-fill me-2"></i>Voting is already finished.</div>
                  <div>Election: <b><?= esc($currentElection['title'] ?? ('HOA Election '.$yearNow)) ?></b></div>
                  <div>Ended At: <b><?= esc($currentElection['ended_at'] ?? '-') ?></b></div>
                </div>

                <?php if (!$resultsByPosition): ?>
                  <div class="alert alert-secondary mb-0">No results available yet.</div>
                <?php else: ?>
                  <?php foreach ($resultsByPosition as $position => $rows): ?>
                    <div class="vote-position mb-3">
                      <div class="vote-position-head">
                        <div class="fw-bold"><?= esc($position) ?> Results</div>
                        <span class="badge bg-dark">Ranking</span>
                      </div>

                      <div class="vote-position-body">
                        <div class="table-responsive">
                          <table class="table table-bordered align-middle mb-0">
                            <thead class="table-light">
                              <tr>
                                <th style="width:90px;">Rank</th>
                                <th>Nominee</th>
                                <th>House / Lot</th>
                                <th style="width:120px;">Votes</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php foreach ($rows as $idx => $r): ?>
                                <tr>
                                  <td>
                                    <span class="result-rank"><?= (int)($idx + 1) ?></span>
                                  </td>
                                  <td><b><?= esc($r['nominee_name']) ?></b></td>
                                  <td><?= esc($r['house_lot_number']) ?></td>
                                  <td><span class="badge bg-success"><?= (int)$r['total_votes'] ?></span></td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>

              <?php elseif ($electionState === 'draft' && $currentElection): ?>

                <div class="vote-state alert alert-warning mb-0">
                  <div class="h5 mb-2"><i class="bi bi-hourglass-split me-2"></i>Voting for this year is not started yet.</div>
                  <div>An election session already exists for your phase, but voting has not been opened yet by the admin.</div>
                </div>

              <?php else: ?>

                <div class="vote-state alert alert-secondary mb-0">
                  <div class="h5 mb-2"><i class="bi bi-info-circle-fill me-2"></i>Voting for this year is not started yet.</div>
                  <div>No active voting session is available for your phase right now.</div>
                </div>

              <?php endif; ?>

            </div>
          </div>

        </div>
      </div>

    </div>
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
    if (window.innerWidth >= 992) {
      closeSidebar();
    }
  });

  sidebar.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', function(){
      if (window.innerWidth < 992) closeSidebar();
    });
  });
})();

/* Board of Director selection limit */
document.querySelectorAll('.board-form').forEach(form => {
  const boxes = form.querySelectorAll('.board-checkbox:not(:disabled)');
  const counter = form.querySelector('[data-board-counter]');

  function refreshCounter() {
    const checked = form.querySelectorAll('.board-checkbox:checked').length;
    if (counter) {
      counter.textContent = checked + ' of 6 selected';
    }
  }

  boxes.forEach(box => {
    box.addEventListener('change', function(){
      const checked = form.querySelectorAll('.board-checkbox:checked').length;
      if (checked > 6) {
        this.checked = false;
        alert('You can only vote for 6 Board of Directors.');
      }
      refreshCounter();
    });
  });

  form.addEventListener('submit', function(e){
    const checked = form.querySelectorAll('.board-checkbox:checked').length;
    if (checked < 1) {
      e.preventDefault();
      alert('Please select at least 1 Board of Director nominee.');
      return;
    }
    if (checked > 6) {
      e.preventDefault();
      alert('You can only vote for 6 Board of Directors.');
      return;
    }
  });

  refreshCounter();
});
</script>
</body>
</html>