<?php
session_start();

// OPTIONAL: enforce superadmin
// if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
//     header("Location: ../index.php");
//     exit;
// }

// ===================== DB =====================
$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nfmt($n){ return number_format((float)$n, 0); }

function statusBadgeClass($status){
    switch ($status) {
        case 'active':   return 'badge-soft badge-soft-success';
        case 'finished': return 'badge-soft badge-soft-secondary';
        default:         return 'badge-soft badge-soft-warning';
    }
}

$positions = [
    'President',
    'Vice President',
    'Secretary',
    'Treasurer',
    'Auditor',
    'Board of Director'
];

$phaseOptions = ['Phase 1', 'Phase 2', 'Phase 3'];

$success = '';
$error   = '';

$adminId = (int)($_SESSION['admin_id'] ?? 1);

/*
|--------------------------------------------------------------------------
| CREATE SESSION
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_session'])) {
    $phase = trim($_POST['phase'] ?? '');
    $title = trim($_POST['title'] ?? '');

    if (!in_array($phase, $phaseOptions, true)) {
        $error = 'Invalid phase selected.';
    } elseif ($title === '') {
        $error = 'Election title is required.';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO election_sessions (phase, title, status, created_by_admin_id)
            VALUES (?, ?, 'draft', ?)
        ");
        $stmt->bind_param("ssi", $phase, $title, $adminId);

        if ($stmt->execute()) {
            $success = 'Election session created successfully.';
        } else {
            $error = 'Failed to create election session.';
        }
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| CHANGE STATUS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'])) {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $newStatus = trim($_POST['new_status'] ?? '');

    if ($sessionId <= 0 || !in_array($newStatus, ['draft', 'active', 'finished'], true)) {
        $error = 'Invalid session status update.';
    } else {
        if ($newStatus === 'active') {
            $stmt = $conn->prepare("
                UPDATE election_sessions
                SET status = 'active',
                    started_at = IF(started_at IS NULL, NOW(), started_at),
                    ended_at = NULL
                WHERE id = ?
            ");
            $stmt->bind_param("i", $sessionId);
        } elseif ($newStatus === 'finished') {
            $stmt = $conn->prepare("
                UPDATE election_sessions
                SET status = 'finished',
                    ended_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param("i", $sessionId);
        } else {
            $stmt = $conn->prepare("
                UPDATE election_sessions
                SET status = 'draft'
                WHERE id = ?
            ");
            $stmt->bind_param("i", $sessionId);
        }

        if ($stmt->execute()) {
            $success = 'Election session status updated successfully.';
        } else {
            $error = 'Failed to update election session status.';
        }
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| DELETE SESSION
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_session'])) {
    $sessionId = (int)($_POST['session_id'] ?? 0);

    if ($sessionId <= 0) {
        $error = 'Invalid election session.';
    } else {
        $stmt = $conn->prepare("DELETE FROM election_sessions WHERE id = ?");
        $stmt->bind_param("i", $sessionId);

        if ($stmt->execute()) {
            $success = 'Election session deleted successfully.';
        } else {
            $error = 'Failed to delete election session.';
        }
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| ADD NOMINEE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_nominee'])) {
    $sessionId   = (int)($_POST['session_id'] ?? 0);
    $position    = trim($_POST['position'] ?? '');
    $homeownerId = (int)($_POST['homeowner_id'] ?? 0);

    if ($sessionId <= 0 || $homeownerId <= 0 || !in_array($position, $positions, true)) {
        $error = 'Please select valid nominee details.';
    } else {
        $stmt = $conn->prepare("SELECT phase FROM election_sessions WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $sessionRow = $res->fetch_assoc();
        $stmt->close();

        if (!$sessionRow) {
            $error = 'Election session not found.';
        } else {
            $sessionPhase = $sessionRow['phase'];

            $stmt = $conn->prepare("
                SELECT id
                FROM homeowners
                WHERE id = ?
                  AND phase = ?
                  AND status = 'approved'
                LIMIT 1
            ");
            $stmt->bind_param("is", $homeownerId, $sessionPhase);
            $stmt->execute();
            $res = $stmt->get_result();
            $validHomeowner = $res->fetch_assoc();
            $stmt->close();

            if (!$validHomeowner) {
                $error = 'Selected homeowner must be approved and from the same phase.';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO election_nominations
                    (election_id, phase, position, homeowner_id, created_by_admin_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("issii", $sessionId, $sessionPhase, $position, $homeownerId, $adminId);

                if ($stmt->execute()) {
                    $success = 'Nominee added successfully.';
                } else {
                    if ($conn->errno == 1062) {
                        $error = 'This homeowner is already nominated for that position in this phase.';
                    } else {
                        $error = 'Failed to add nominee.';
                    }
                }
                $stmt->close();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| DELETE NOMINEE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_nominee'])) {
    $nominationId = (int)($_POST['nomination_id'] ?? 0);

    if ($nominationId <= 0) {
        $error = 'Invalid nominee.';
    } else {
        $stmt = $conn->prepare("DELETE FROM election_nominations WHERE id = ?");
        $stmt->bind_param("i", $nominationId);

        if ($stmt->execute()) {
            $success = 'Nominee removed successfully.';
        } else {
            $error = 'Failed to remove nominee.';
        }
        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| FETCH COUNTS
|--------------------------------------------------------------------------
*/
$totalSessions = 0;
$totalNomineesAll = 0;
$totalVotesAll = 0;

if ($r = $conn->query("SELECT COUNT(*) c FROM election_sessions")) {
    $totalSessions = (int)($r->fetch_assoc()['c'] ?? 0);
    $r->close();
}
if ($r = $conn->query("SELECT COUNT(*) c FROM election_nominations")) {
    $totalNomineesAll = (int)($r->fetch_assoc()['c'] ?? 0);
    $r->close();
}
if ($r = $conn->query("SELECT COUNT(*) c FROM election_votes")) {
    $totalVotesAll = (int)($r->fetch_assoc()['c'] ?? 0);
    $r->close();
}

/*
|--------------------------------------------------------------------------
| FETCH SESSIONS
|--------------------------------------------------------------------------
*/
$electionSessions = [];
$sqlSessions = "
    SELECT
        es.id,
        es.phase,
        es.title,
        es.status,
        es.started_at,
        es.ended_at,
        es.created_at,
        COUNT(DISTINCT en.id) AS nominee_count,
        COUNT(DISTINCT ev.id) AS vote_count
    FROM election_sessions es
    LEFT JOIN election_nominations en ON en.election_id = es.id
    LEFT JOIN election_votes ev ON ev.election_id = es.id
    GROUP BY es.id
    ORDER BY es.id DESC
";
$resSessions = $conn->query($sqlSessions);
if ($resSessions) {
    while ($row = $resSessions->fetch_assoc()) {
        $electionSessions[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| SELECTED SESSION
|--------------------------------------------------------------------------
*/
$selectedSessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($selectedSessionId <= 0 && !empty($electionSessions)) {
    $selectedSessionId = (int)$electionSessions[0]['id'];
}

$selectedSession = null;
foreach ($electionSessions as $session) {
    if ((int)$session['id'] === (int)$selectedSessionId) {
        $selectedSession = $session;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| APPROVED HOMEOWNERS OF SELECTED SESSION PHASE
|--------------------------------------------------------------------------
*/
$approvedHomeowners = [];
if ($selectedSession) {
    $stmt = $conn->prepare("
        SELECT id, first_name, middle_name, last_name, house_lot_number
        FROM homeowners
        WHERE phase = ?
          AND status = 'approved'
        ORDER BY first_name ASC, last_name ASC
    ");
    $stmt->bind_param("s", $selectedSession['phase']);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $approvedHomeowners[] = $row;
    }
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| NOMINEES + RESULTS
|--------------------------------------------------------------------------
*/
$nomineesByPosition = [];
foreach ($positions as $position) {
    $nomineesByPosition[$position] = [];
}

if ($selectedSessionId > 0) {
    $stmt = $conn->prepare("
        SELECT
            en.id AS nomination_id,
            en.position,
            en.homeowner_id,
            h.first_name,
            h.middle_name,
            h.last_name,
            h.house_lot_number,
            COUNT(ev.id) AS total_votes
        FROM election_nominations en
        INNER JOIN homeowners h
            ON h.id = en.homeowner_id
        LEFT JOIN election_votes ev
            ON ev.election_id = en.election_id
           AND ev.position = en.position
           AND ev.nominee_homeowner_id = en.homeowner_id
        WHERE en.election_id = ?
        GROUP BY en.id, en.position, en.homeowner_id, h.first_name, h.middle_name, h.last_name, h.house_lot_number
        ORDER BY en.position ASC, total_votes DESC, h.first_name ASC, h.last_name ASC
    ");
    $stmt->bind_param("i", $selectedSessionId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $nomineesByPosition[$row['position']][] = $row;
    }
    $stmt->close();
}

$selectedNominees = 0;
$selectedVotes = 0;
if ($selectedSession) {
    foreach ($nomineesByPosition as $rows) {
        $selectedNominees += count($rows);
    }
    $selectedVotes = (int)$selectedSession['vote_count'];
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Voting Management</title>

  <link rel="apple-touch-icon" sizes="180x180" href="../admin/vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="../admin/vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="../admin/vendors/images/favicon-16x16.png">

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="../admin/src/plugins/datatables/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="../admin/src/plugins/datatables/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/style.css">

  <style>
    .badge-soft {
      padding: .35rem .6rem;
      border-radius: 999px;
      font-weight: 800;
      font-size: 12px;
      display: inline-block;
    }
    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-info { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }
    .badge-soft-secondary { background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; }
    .badge-soft-danger { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }

    .kpi-card .icon { font-size: 28px; opacity: .9; }
    .kpi-value { font-size: 28px; font-weight: 800; }
    .kpi-label { color: #64748b; font-weight: 700; }

    .position-card {
      border: 1px solid #edf2f9;
      border-radius: 12px;
      padding: 18px;
      margin-bottom: 20px;
      background: #fff;
    }
    .position-title {
      font-size: 17px;
      font-weight: 700;
      color: #12284c;
      margin-bottom: 12px;
    }
    .mini-note {
      font-size: 12px;
      color: #6c757d;
    }
    .nominee-name {
      font-weight: 600;
      color: #12284c;
    }
    .sidebar-menu .dropdown-toggle:hover,
    .sidebar-menu .show > .dropdown-toggle {
      background: #077f46;
      color: #fff !important;
    }
    .sidebar-menu .submenu li a:hover,
    .sidebar-menu .submenu li a.active {
      background: rgba(7,127,70,.10);
      color: #077f46 !important;
      font-weight: 700;
    }
  </style>
</head>

<body>
  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
    </div>

    <div class="header-right">
      <div class="user-info-dropdown">
        <div class="dropdown">
          <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
            <span class="user-icon">
              <img src="../admin/vendors/images/photo1.jpg" alt="">
            </span>
            <span class="user-name">Superadmin</span>
          </a>
          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="../index.php"><i class="dw dw-logout"></i> Log Out</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php include 'superadmin_sidebar.php'; ?>

  <div class="mobile-menu-overlay"></div>

  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header mb-20">
        <div class="row">
          <div class="col-md-12 col-sm-12">
            <div class="title"><h4>Voting Management</h4></div>
            <div class="text-secondary">
              Logged in as: <b>Superadmin</b> |
              Scope: <b>All Phases</b>
            </div>
          </div>
        </div>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <?= esc($success) ?>
          <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <?= esc($error) ?>
          <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
      <?php endif; ?>

      <div class="row">
        <div class="col-lg-7 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p mb-20">
            <div class="row align-items-center">
              <div class="col-md-4">
                <img src="../admin/vendors/images/banner-img.png" alt="">
              </div>
              <div class="col-md-8">
                <h4 class="font-20 weight-500 mb-10 text-capitalize">
                  <div class="weight-600 font-30 text-blue">Voting Dashboard</div>
                </h4>
                <p class="font-18 max-width-600">
                  Manage election sessions, manually add homeowner nominees per phase and position, and monitor live vote totals.
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-5 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p">
            <div class="d-flex justify-content-between align-items-center mb-10">
              <h4 class="h5 mb-0">Quick Summary</h4>
            </div>

            <div class="mb-15">
              <div class="d-flex justify-content-between mb-2">
                <span class="text-secondary">Election Sessions</span>
                <span class="badge-soft badge-soft-info"><?= nfmt($totalSessions) ?></span>
              </div>
              <div class="d-flex justify-content-between mb-2">
                <span class="text-secondary">All Nominees</span>
                <span class="badge-soft badge-soft-success"><?= nfmt($totalNomineesAll) ?></span>
              </div>
              <div class="d-flex justify-content-between mb-2">
                <span class="text-secondary">All Votes</span>
                <span class="badge-soft badge-soft-secondary"><?= nfmt($totalVotesAll) ?></span>
              </div>
              <div class="d-flex justify-content-between">
                <span class="font-weight-bold">Selected Session Votes</span>
                <span class="badge-soft badge-soft-danger"><?= nfmt($selectedVotes) ?></span>
              </div>
            </div>

            <hr>

            <?php if ($selectedSession): ?>
              <div class="mb-2 text-secondary">Selected Session</div>
              <div class="d-flex justify-content-between mb-2">
                <span>Title</span>
                <span class="font-weight-bold"><?= esc($selectedSession['title']) ?></span>
              </div>
              <div class="d-flex justify-content-between mb-2">
                <span>Phase</span>
                <span class="badge-soft badge-soft-info"><?= esc($selectedSession['phase']) ?></span>
              </div>
              <div class="d-flex justify-content-between mb-2">
                <span>Status</span>
                <span class="<?= statusBadgeClass($selectedSession['status']) ?>">
                  <?= esc(ucfirst($selectedSession['status'])) ?>
                </span>
              </div>
            <?php else: ?>
              <div class="text-secondary">No selected session.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Total Sessions</div>
                <div class="kpi-value"><?= nfmt($totalSessions) ?></div>
              </div>
              <div class="icon text-primary"><i class="dw dw-list3"></i></div>
            </div>
            <div class="mt-2 text-secondary">All election sessions</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Selected Nominees</div>
                <div class="kpi-value"><?= nfmt($selectedNominees) ?></div>
              </div>
              <div class="icon text-success"><i class="dw dw-user-13"></i></div>
            </div>
            <div class="mt-2 text-secondary">Nominees in selected session</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Selected Votes</div>
                <div class="kpi-value"><?= nfmt($selectedVotes) ?></div>
              </div>
              <div class="icon text-info"><i class="dw dw-analytics-21"></i></div>
            </div>
            <div class="mt-2 text-secondary">Live votes in selected session</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">All Votes</div>
                <div class="kpi-value"><?= nfmt($totalVotesAll) ?></div>
              </div>
              <div class="icon text-danger"><i class="dw dw-check"></i></div>
            </div>
            <div class="mt-2 text-secondary">All sessions combined</div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-md-5 mb-30">
          <div class="card-box pd-20">
            <div class="d-flex justify-content-between align-items-center mb-10">
              <h5 class="mb-0">Create Election Session</h5>
            </div>

            <form method="POST">
              <div class="form-group">
                <label>Phase</label>
                <select name="phase" class="form-control" required>
                  <option value="">-- Select Phase --</option>
                  <?php foreach ($phaseOptions as $phase): ?>
                    <option value="<?= esc($phase) ?>"><?= esc($phase) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group">
                <label>Election Title</label>
                <input type="text" name="title" class="form-control" placeholder="Example: Phase 1 HOA Election 2026" required>
              </div>

              <button type="submit" name="create_session" class="btn btn-primary">Create Session</button>
            </form>
          </div>
        </div>

        <div class="col-md-7 mb-30">
          <div class="card-box pd-20">
            <div class="d-flex justify-content-between align-items-center mb-10">
              <h5 class="mb-0">Add Nominee</h5>
            </div>

            <?php if ($selectedSession): ?>
              <form method="POST">
                <div class="row">
                  <div class="col-md-4 form-group">
                    <label>Election Session</label>
                    <select name="session_id" class="form-control" required>
                      <?php foreach ($electionSessions as $session): ?>
                        <option value="<?= (int)$session['id'] ?>" <?= ((int)$session['id'] === (int)$selectedSessionId) ? 'selected' : '' ?>>
                          <?= esc($session['title']) ?> (<?= esc($session['phase']) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-md-4 form-group">
                    <label>Position</label>
                    <select name="position" class="form-control" required>
                      <option value="">-- Select Position --</option>
                      <?php foreach ($positions as $position): ?>
                        <option value="<?= esc($position) ?>"><?= esc($position) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-md-4 form-group">
                    <label>Homeowner Nominee</label>
                    <select name="homeowner_id" class="form-control" required>
                      <option value="">-- Select Homeowner --</option>
                      <?php foreach ($approvedHomeowners as $homeowner): ?>
                        <?php
                          $fullName = trim(
                              $homeowner['first_name'] . ' ' .
                              ($homeowner['middle_name'] ? $homeowner['middle_name'] . ' ' : '') .
                              $homeowner['last_name']
                          );
                        ?>
                        <option value="<?= (int)$homeowner['id'] ?>">
                          <?= esc($fullName) ?><?= $homeowner['house_lot_number'] ? ' - ' . esc($homeowner['house_lot_number']) : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <button type="submit" name="add_nominee" class="btn btn-success">Add Nominee</button>

                <div class="mini-note mt-2">
                  Only approved homeowners from <strong><?= esc($selectedSession['phase']) ?></strong> are shown.
                </div>
              </form>
            <?php else: ?>
              <div class="alert alert-info mb-0">
                Create an election session first before adding nominees.
              </div>
            <?php endif; ?>

            <hr>

            <div class="d-flex justify-content-between align-items-center mb-10">
              <h5 class="mb-0">Select Session to View</h5>
            </div>

            <form method="GET">
              <div class="row">
                <div class="col-md-10 form-group">
                  <select name="session_id" class="form-control">
                    <?php foreach ($electionSessions as $session): ?>
                      <option value="<?= (int)$session['id'] ?>" <?= ((int)$session['id'] === (int)$selectedSessionId) ? 'selected' : '' ?>>
                        <?= esc($session['title']) ?> (<?= esc($session['phase']) ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-2 form-group">
                  <button type="submit" class="btn btn-info btn-block">View</button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Election Sessions</h5>
        </div>

        <div class="table-responsive">
          <table id="sessionsTable" class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Phase</th>
                <th>Title</th>
                <th>Status</th>
                <th>Nominees</th>
                <th>Votes</th>
                <th>Started</th>
                <th>Ended</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($electionSessions)): ?>
                <?php foreach ($electionSessions as $session): ?>
                  <tr>
                    <td><?= (int)$session['id'] ?></td>
                    <td><?= esc($session['phase']) ?></td>
                    <td><?= esc($session['title']) ?></td>
                    <td>
                      <span class="<?= statusBadgeClass($session['status']) ?>">
                        <?= esc(ucfirst($session['status'])) ?>
                      </span>
                    </td>
                    <td><?= (int)$session['nominee_count'] ?></td>
                    <td><?= (int)$session['vote_count'] ?></td>
                    <td><?= esc($session['started_at'] ?: '-') ?></td>
                    <td><?= esc($session['ended_at'] ?: '-') ?></td>
                    <td>
                      <div class="d-flex flex-wrap" style="gap:6px;">
                        <a href="voting.php?session_id=<?= (int)$session['id'] ?>" class="btn btn-sm btn-info">View</a>

                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                          <input type="hidden" name="new_status" value="draft">
                          <button type="submit" name="change_status" class="btn btn-sm btn-warning">Draft</button>
                        </form>

                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                          <input type="hidden" name="new_status" value="active">
                          <button type="submit" name="change_status" class="btn btn-sm btn-success">Open</button>
                        </form>

                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                          <input type="hidden" name="new_status" value="finished">
                          <button type="submit" name="change_status" class="btn btn-sm btn-secondary">Finish</button>
                        </form>

                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this election session and all nominations/votes under it?');">
                          <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                          <button type="submit" name="delete_session" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="9" class="text-center text-secondary">No election sessions found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">
            <?php if ($selectedSession): ?>
              Nominees and Results - <?= esc($selectedSession['title']) ?> (<?= esc($selectedSession['phase']) ?>)
            <?php else: ?>
              Nominees and Results
            <?php endif; ?>
          </h5>
        </div>

        <div class="mini-note mb-3">
          Option B rule: President, Vice President, Secretary, Treasurer, and Auditor = 1 vote each.
          Board of Director = multiple votes allowed on homeowner side.
        </div>

        <?php if ($selectedSession): ?>
          <?php foreach ($positions as $position): ?>
            <div class="position-card">
              <div class="position-title"><?= esc($position) ?></div>

              <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                  <thead class="table-light">
                    <tr>
                      <th width="70">Rank</th>
                      <th>Nominee</th>
                      <th>House/Lot</th>
                      <th width="120">Votes</th>
                      <th width="120">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!empty($nomineesByPosition[$position])): ?>
                      <?php $rank = 1; ?>
                      <?php foreach ($nomineesByPosition[$position] as $nominee): ?>
                        <?php
                          $fullName = trim(
                              $nominee['first_name'] . ' ' .
                              ($nominee['middle_name'] ? $nominee['middle_name'] . ' ' : '') .
                              $nominee['last_name']
                          );
                        ?>
                        <tr>
                          <td><?= $rank++ ?></td>
                          <td class="nominee-name"><?= esc($fullName) ?></td>
                          <td><?= esc($nominee['house_lot_number'] ?: '-') ?></td>
                          <td><?= (int)$nominee['total_votes'] ?></td>
                          <td>
                            <form method="POST" onsubmit="return confirm('Remove this nominee?');">
                              <input type="hidden" name="nomination_id" value="<?= (int)$nominee['nomination_id'] ?>">
                              <button type="submit" name="delete_nominee" class="btn btn-sm btn-danger">Remove</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr><td colspan="5" class="text-center text-secondary">No nominees yet for this position.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="alert alert-info mb-0">No election session selected yet.</div>
        <?php endif; ?>
      </div>

      <div class="footer-wrap pd-20 mb-20 card-box">
        © Copyright South Meridian Homes All Rights Reserved
      </div>
    </div>
  </div>

  <script src="../admin/vendors/scripts/core.js"></script>
  <script src="../admin/vendors/scripts/script.min.js"></script>
  <script src="../admin/vendors/scripts/process.js"></script>
  <script src="../admin/vendors/scripts/layout-settings.js"></script>

  <script src="../admin/src/plugins/datatables/js/jquery.dataTables.min.js"></script>
  <script src="../admin/src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
  <script src="../admin/src/plugins/datatables/js/dataTables.responsive.min.js"></script>
  <script src="../admin/src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

  <script>
    $(document).ready(function () {
      $('#sessionsTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[0, 'desc']]
      });
    });
  </script>
</body>
</html>