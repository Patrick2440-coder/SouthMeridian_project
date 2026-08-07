<?php
session_start();

// OPTIONAL: if you already have superadmin auth session, enforce it here
// if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') { header("Location: authentication-login.html"); exit; }

// DB
$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nfmt($n){ return number_format((float)$n, 0); }

// Phase filter
$selected_phase = trim($_GET['phase'] ?? 'All');
$allowed_phases = ['All','Phase 1','Phase 2','Phase 3'];

if (!in_array($selected_phase, $allowed_phases, true)) {
  $selected_phase = 'All';
}

// Fetch approved homeowners
if ($selected_phase === 'All') {
  $stmt = $conn->prepare("
    SELECT
      h.id,
      h.first_name,
      h.middle_name,
      h.last_name,
      h.house_lot_number,
      h.phase,
      h.status,
      COALESCE(hp.position, 'Homeowner') AS position
    FROM homeowners h
    LEFT JOIN homeowner_positions hp
      ON hp.homeowner_id = h.id AND hp.phase = h.phase
    WHERE h.status = 'approved'
    ORDER BY h.phase ASC, h.last_name ASC, h.first_name ASC
  ");
} else {
  $stmt = $conn->prepare("
    SELECT
      h.id,
      h.first_name,
      h.middle_name,
      h.last_name,
      h.house_lot_number,
      h.phase,
      h.status,
      COALESCE(hp.position, 'Homeowner') AS position
    FROM homeowners h
    LEFT JOIN homeowner_positions hp
      ON hp.homeowner_id = h.id AND hp.phase = h.phase
    WHERE h.status = 'approved' AND h.phase = ?
    ORDER BY h.phase ASC, h.last_name ASC, h.first_name ASC
  ");
  $stmt->bind_param("s", $selected_phase);
}

$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Summary counts
$totalHomeowners = count($rows);
$phase1Count = 0;
$phase2Count = 0;
$phase3Count = 0;

foreach ($rows as $r) {
  if ($r['phase'] === 'Phase 1') $phase1Count++;
  if ($r['phase'] === 'Phase 2') $phase2Count++;
  if ($r['phase'] === 'Phase 3') $phase3Count++;
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Superadmin - User Management</title>

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
    .summary-card {
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      padding: 16px;
      background: #fff;
      height: 100%;
    }

    .summary-number {
      font-size: 28px;
      font-weight: 800;
      color: #077f46;
      line-height: 1;
    }

    .summary-label {
      font-size: 13px;
      color: #64748b;
      margin-top: 6px;
      font-weight: 600;
    }

    .badge-soft {
      padding: .35rem .6rem;
      border-radius: 999px;
      font-weight: 800;
      font-size: 12px;
      display: inline-block;
    }

    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }
    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-secondary { background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; }

    .mini-note {
      color: #64748b;
      font-size: 13px;
    }

    .phase-tools {
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
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
            <a class="dropdown-item" href="profile.html"><i class="dw dw-user1"></i> Profile</a>
            <a class="dropdown-item" href="logs.html"><i class="dw dw-list3"></i> Activity Logs</a>
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
            <div class="title"><h4>Homeowner Management</h4></div>
            <div class="text-secondary">
              Approved homeowners
              <?= ($selected_phase === 'All') ? "across all phases" : "for " . esc($selected_phase) ?>.
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-lg-8 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p mb-20">
            <div class="row align-items-center">
              <div class="col-md-4">
                <img src="../admin/vendors/images/banner-img.png" alt="">
              </div>
              <div class="col-md-8">
                <h4 class="font-20 weight-500 mb-10 text-capitalize">
                  <div class="weight-600 font-30 text-blue">Homeowner Directory</div>
                </h4>
                <p class="font-18 max-width-600">
                  View approved homeowner records, phase assignment, lot details, and mapped community positions from all phases.
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-4 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p">
            <div class="d-flex justify-content-between align-items-center mb-10">
              <h4 class="h5 mb-0">Quick Summary</h4>
            </div>

            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">Selected Phase</span>
              <span class="badge-soft badge-soft-secondary"><?= esc($selected_phase) ?></span>
            </div>
            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">Approved Homeowners</span>
              <span class="badge-soft badge-soft-success"><?= nfmt($totalHomeowners) ?></span>
            </div>
            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">Phase 1</span>
              <span class="badge-soft badge-soft-info"><?= nfmt($phase1Count) ?></span>
            </div>
            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">Phase 2</span>
              <span class="badge-soft badge-soft-info"><?= nfmt($phase2Count) ?></span>
            </div>
            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">Phase 3</span>
              <span class="badge-soft badge-soft-info"><?= nfmt($phase3Count) ?></span>
            </div>

            <div class="mt-3 mini-note">
              Change the phase filter below to narrow the homeowner list.
            </div>
          </div>
        </div>
      </div>

      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
          <div>
            <h5 class="mb-1">Approved Homeowners</h5>
            <div class="mini-note">Showing active approved homeowner records.</div>
          </div>

          <form method="GET" class="phase-tools">
            <label class="mb-0 text-secondary">Phase:</label>
            <select name="phase" class="form-control" style="width: 160px;" onchange="this.form.submit()">
              <?php foreach ($allowed_phases as $ph): ?>
                <option value="<?= esc($ph) ?>" <?= ($selected_phase === $ph) ? 'selected' : '' ?>>
                  <?= esc($ph) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <noscript>
              <button class="btn btn-sm btn-primary" type="submit">Apply</button>
            </noscript>
          </form>
        </div>

        <div class="table-responsive">
          <table id="homeownersTable" class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Lot/Unit</th>
                <th>Phase</th>
                <th>Position</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $r): ?>
                <?php
                  $full = trim($r['first_name'].' '.$r['middle_name'].' '.$r['last_name']);
                ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><?= esc($full) ?></td>
                  <td><?= esc($r['house_lot_number']) ?></td>
                  <td><?= esc($r['phase']) ?></td>
                  <td><?= esc($r['position']) ?></td>
                  <td><span class="badge-soft badge-soft-success">Approved</span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
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
      $('#homeownersTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[3,'asc'], [1,'asc']]
      });
    });
  </script>
</body>
</html>