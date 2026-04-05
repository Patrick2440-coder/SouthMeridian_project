<?php
session_start();

// ===================== DB CONNECTION =====================
$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

if (!function_exists('esc')) {
  function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$POSITIONS = ["President", "Vice President", "Secretary", "Treasurer", "Auditor", "Board of Director"];
$SINGLE_POSITIONS = ["President", "Vice President", "Secretary", "Treasurer", "Auditor"];

// Ensure DB has a row for each single-seat phase+position
function ensure_phase_rows(mysqli $conn, string $phase, array $singlePositions): void {
  foreach ($singlePositions as $pos) {
    $stmt = $conn->prepare("
      SELECT id
      FROM hoa_officers
      WHERE phase=? AND position=?
      LIMIT 1
    ");
    $stmt->bind_param("ss", $phase, $pos);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      $stmt = $conn->prepare("
        INSERT INTO hoa_officers (phase, position, officer_name, officer_email, is_active)
        VALUES (?, ?, NULL, NULL, 1)
      ");
      $stmt->bind_param("ss", $phase, $pos);
      $stmt->execute();
      $stmt->close();
    }
  }
}

// ===================== AJAX ENDPOINTS =====================
if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
  header('Content-Type: application/json; charset=utf-8');

  $action = $_POST['action'] ?? '';
  $phase  = $_POST['phase'] ?? 'Phase 1';

  if (!in_array($phase, ['Phase 1','Phase 2','Phase 3'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid phase']);
    exit;
  }

  ensure_phase_rows($conn, $phase, $SINGLE_POSITIONS);

  if ($action === 'fetch') {
    $stmt = $conn->prepare("
      SELECT id, position, officer_name, officer_email, is_active
      FROM hoa_officers
      WHERE phase=?
      ORDER BY
        FIELD(position,'President','Vice President','Secretary','Treasurer','Auditor','Board of Director'),
        officer_name ASC,
        id ASC
    ");
    $stmt->bind_param("s", $phase);
    $stmt->execute();
    $res = $stmt->get_result();

    $grouped = [];
    while ($row = $res->fetch_assoc()) {
      $pos = (string)$row['position'];
      if (!isset($grouped[$pos])) $grouped[$pos] = [];
      $grouped[$pos][] = [
        'id' => (int)$row['id'],
        'name' => (string)($row['officer_name'] ?? ''),
        'email' => (string)($row['officer_email'] ?? ''),
        'active' => (int)$row['is_active']
      ];
    }
    $stmt->close();

    $rows = [];
    foreach ($POSITIONS as $pos) {
      if ($pos === 'Board of Director') {
        $officers = $grouped[$pos] ?? [];
        $rows[] = [
          'position' => $pos,
          'is_multi' => 1,
          'officers' => $officers
        ];
      } else {
        $officer = $grouped[$pos][0] ?? ['id'=>0,'name'=>'','email'=>'','active'=>1];
        $rows[] = [
          'position' => $pos,
          'is_multi' => 0,
          'id' => (int)$officer['id'],
          'name' => (string)$officer['name'],
          'email' => (string)$officer['email'],
          'active' => (int)$officer['active']
        ];
      }
    }

    echo json_encode(['success' => true, 'rows' => $rows]);
    exit;
  }

  if ($action === 'assign') {
    $position = $_POST['position'] ?? '';
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');

    if (!in_array($position, $POSITIONS, true)) {
      echo json_encode(['success' => false, 'message' => 'Invalid position']);
      exit;
    }
    if ($name === '') {
      echo json_encode(['success' => false, 'message' => 'Officer name is required']);
      exit;
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      echo json_encode(['success' => false, 'message' => 'Valid email is required']);
      exit;
    }

    if ($position === 'Board of Director') {
      $stmt = $conn->prepare("
        SELECT id
        FROM hoa_officers
        WHERE phase=? AND position='Board of Director' AND officer_name=? AND officer_email=?
        LIMIT 1
      ");
      $stmt->bind_param("sss", $phase, $name, $email);
      $stmt->execute();
      $exists = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if ($exists) {
        echo json_encode(['success' => false, 'message' => 'This Board of Director is already assigned']);
        exit;
      }

      $stmt = $conn->prepare("
        INSERT INTO hoa_officers (phase, position, officer_name, officer_email, is_active)
        VALUES (?, 'Board of Director', ?, ?, 1)
      ");
      $stmt->bind_param("sss", $phase, $name, $email);
      $ok = $stmt->execute();
      $stmt->close();

      echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'Board of Director added successfully' : 'Assign failed'
      ]);
      exit;
    }

    $stmt = $conn->prepare("
      UPDATE hoa_officers
      SET officer_name=?, officer_email=?, is_active=1
      WHERE phase=? AND position=?
      LIMIT 1
    ");
    $stmt->bind_param("ssss", $name, $email, $phase, $position);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
      echo json_encode(['success' => false, 'message' => 'Assign failed']);
      exit;
    }

    if ($position === 'President') {
      $stmt = $conn->prepare("
        UPDATE admins
        SET full_name=?
        WHERE phase=? AND role='admin'
        LIMIT 1
      ");
      $stmt->bind_param("ss", $name, $phase);
      $stmt->execute();
      $stmt->close();
    }

    echo json_encode(['success' => true, 'message' => 'Assigned successfully']);
    exit;
  }

  if ($action === 'toggle') {
    $position = $_POST['position'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if (!in_array($position, $POSITIONS, true)) {
      echo json_encode(['success' => false, 'message' => 'Invalid position']);
      exit;
    }

    if ($position === 'Board of Director') {
      if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Board of Director row']);
        exit;
      }

      $stmt = $conn->prepare("
        UPDATE hoa_officers
        SET is_active = IF(is_active=1, 0, 1)
        WHERE id=? AND phase=? AND position='Board of Director'
        LIMIT 1
      ");
      $stmt->bind_param("is", $id, $phase);
      $ok = $stmt->execute();
      $stmt->close();

      echo json_encode(['success' => $ok, 'message' => $ok ? 'Status updated' : 'Update failed']);
      exit;
    }

    $stmt = $conn->prepare("
      UPDATE hoa_officers
      SET is_active = IF(is_active=1, 0, 1)
      WHERE phase=? AND position=?
      LIMIT 1
    ");
    $stmt->bind_param("ss", $phase, $position);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $ok, 'message' => $ok ? 'Status updated' : 'Update failed']);
    exit;
  }

  if ($action === 'delete_board') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      echo json_encode(['success' => false, 'message' => 'Invalid Board of Director row']);
      exit;
    }

    $stmt = $conn->prepare("
      DELETE FROM hoa_officers
      WHERE id=? AND phase=? AND position='Board of Director'
      LIMIT 1
    ");
    $stmt->bind_param("is", $id, $phase);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $ok, 'message' => $ok ? 'Board of Director removed' : 'Delete failed']);
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action']);
  exit;
}

// ===================== PAGE LOAD =====================
$selectedPhase = $_GET['phase'] ?? 'Phase 1';
if (!in_array($selectedPhase, ['Phase 1','Phase 2','Phase 3'], true)) $selectedPhase = 'Phase 1';
ensure_phase_rows($conn, $selectedPhase, $SINGLE_POSITIONS);

// summary counts
$totalAssigned = 0;
$totalActive = 0;
$totalDirectors = 0;

$stmt = $conn->prepare("
  SELECT
    SUM(CASE WHEN officer_name IS NOT NULL AND officer_name <> '' THEN 1 ELSE 0 END) AS assigned_count,
    SUM(CASE WHEN is_active = 1 AND officer_name IS NOT NULL AND officer_name <> '' THEN 1 ELSE 0 END) AS active_count,
    SUM(CASE WHEN position = 'Board of Director' THEN 1 ELSE 0 END) AS director_count
  FROM hoa_officers
  WHERE phase=?
");
$stmt->bind_param("s", $selectedPhase);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalAssigned = (int)($summary['assigned_count'] ?? 0);
$totalActive = (int)($summary['active_count'] ?? 0);
$totalDirectors = (int)($summary['director_count'] ?? 0);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Superadmin - Phase Management</title>

  <link rel="apple-touch-icon" sizes="180x180" href="../admin/vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="../admin/vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="../admin/vendors/images/favicon-16x16.png">

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/style.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

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
    .badge-soft-secondary { background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; }
    .mini-note {
      color: #64748b;
      font-size: 13px;
    }
    .action-btns .btn {
      margin: 2px;
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
            <div class="title"><h4>Phase Management</h4></div>
            <div class="text-secondary">
              Manage HOA officers and board members for each phase.
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
                  <div class="weight-600 font-30 text-blue">Officer Assignment Panel</div>
                </h4>
                <p class="font-18 max-width-600">
                  Assign, activate, deactivate, and manage HOA officers per phase, including multiple Board of Directors entries.
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
              <span class="badge-soft badge-soft-secondary"><?= esc($selectedPhase) ?></span>
            </div>
            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">Assigned Officers</span>
              <span class="badge-soft badge-soft-success"><?= $totalAssigned ?></span>
            </div>
            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">Active Officers</span>
              <span class="badge-soft badge-soft-success"><?= $totalActive ?></span>
            </div>
            <div class="mb-2 d-flex justify-content-between">
              <span class="text-secondary">Board Directors</span>
              <span class="badge-soft badge-soft-secondary"><?= $totalDirectors ?></span>
            </div>

            <div class="mt-3 mini-note">
              Switch phase from the table section to load another set of officers.
            </div>
          </div>
        </div>
      </div>

      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
          <div>
            <h5 class="mb-1">Current HOA Officers</h5>
            <div class="mini-note">Manage assignments and status per selected phase.</div>
          </div>

          <div class="phase-tools">
            <label class="mb-0 text-secondary">Select Phase:</label>
            <select id="phaseSelect" class="form-control" style="width: 150px;">
              <option value="Phase 1" <?= $selectedPhase==='Phase 1'?'selected':''; ?>>Phase 1</option>
              <option value="Phase 2" <?= $selectedPhase==='Phase 2'?'selected':''; ?>>Phase 2</option>
              <option value="Phase 3" <?= $selectedPhase==='Phase 3'?'selected':''; ?>>Phase 3</option>
            </select>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th style="width: 18%;">Position</th>
                <th>Assigned Officer</th>
                <th>Email</th>
                <th style="width: 12%;">Status</th>
                <th style="width: 24%;">Actions</th>
              </tr>
            </thead>
            <tbody id="rolesTbody"></tbody>
          </table>
        </div>

        <div id="msgBox" class="mt-3"></div>
      </div>

      <div class="footer-wrap pd-20 mb-20 card-box">
        © Copyright South Meridian Homes All Rights Reserved
      </div>
    </div>
  </div>

  <!-- Assign Modal -->
  <div class="modal fade" id="assignModal" tabindex="-1" role="dialog" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="assignModalLabel">Assign Officer</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <form id="assignForm">
            <input type="hidden" id="modalPositionKey" />
            <div class="form-group">
              <label>Selected Phase</label>
              <input type="text" class="form-control" id="modalPhase" readonly>
            </div>
            <div class="form-group">
              <label>Position</label>
              <input type="text" class="form-control" id="modalPosition" readonly>
            </div>
            <div class="form-group">
              <label>Officer Name</label>
              <input type="text" class="form-control" id="modalOfficerName" placeholder="Type name..." required>
            </div>
            <div class="form-group">
              <label>Officer Email</label>
              <input type="email" class="form-control" id="modalOfficerEmail" placeholder="name@gmail.com" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Save Assignment</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <script src="../admin/vendors/scripts/core.js"></script>
  <script src="../admin/vendors/scripts/script.min.js"></script>
  <script src="../admin/vendors/scripts/process.js"></script>
  <script src="../admin/vendors/scripts/layout-settings.js"></script>

  <script>
    const msgBox = document.getElementById('msgBox');

    function showMsg(type, text) {
      msgBox.innerHTML = `<div class="alert alert-${type} py-2 mb-0" role="alert">${text}</div>`;
      setTimeout(() => { msgBox.innerHTML = ''; }, 2500);
    }

    function statusBadge(isActive) {
      return isActive
        ? `<span class="badge badge-success">Active</span>`
        : `<span class="badge badge-secondary">Not Active</span>`;
    }

    function renderRows(rows, phase) {
      const tbody = document.getElementById("rolesTbody");
      tbody.innerHTML = "";

      rows.forEach(r => {
        if (parseInt(r.is_multi, 10) === 1) {
          const officers = Array.isArray(r.officers) ? r.officers : [];

          if (officers.length === 0) {
            const tr = document.createElement("tr");
            tr.innerHTML = `
              <td class="font-weight-bold">${r.position}</td>
              <td><span class="text-muted">No assigned Board of Directors</span></td>
              <td><span class="text-muted">N/A</span></td>
              <td><span class="badge badge-secondary">N/A</span></td>
              <td class="action-btns">
                <button type="button"
                  class="btn btn-sm btn-primary"
                  data-toggle="modal"
                  data-target="#assignModal"
                  data-position="${r.position}"
                  data-current-name=""
                  data-current-email="">
                  Add Director
                </button>
              </td>
            `;
            tbody.appendChild(tr);
          } else {
            officers.forEach((officer, idx) => {
              const name = (officer.name || '').trim();
              const email = (officer.email || '').trim();
              const active = parseInt(officer.active, 10) === 1;

              const tr = document.createElement("tr");
              tr.innerHTML = `
                <td class="font-weight-bold">${idx === 0 ? r.position : ''}</td>
                <td>${name ? name : `<span class="text-muted">Not assigned</span>`}</td>
                <td>${email ? email : `<span class="text-muted">N/A</span>`}</td>
                <td>${statusBadge(active)}</td>
                <td class="action-btns">
                  <button type="button"
                    class="btn btn-sm btn-outline-secondary"
                    onclick="toggleBoardActive('${phase}', ${parseInt(officer.id,10)})">
                    ${active ? 'Set Not Active' : 'Set Active'}
                  </button>

                  <button type="button"
                    class="btn btn-sm btn-outline-danger ${officers.length <= 1 ? 'd-none' : ''}"
                    onclick="deleteBoard('${phase}', ${parseInt(officer.id,10)})">
                    Remove
                  </button>

                  ${idx === officers.length - 1 ? `
                    <button type="button"
                      class="btn btn-sm btn-primary"
                      data-toggle="modal"
                      data-target="#assignModal"
                      data-position="Board of Director"
                      data-current-name=""
                      data-current-email="">
                      Add Director
                    </button>
                  ` : ''}
                </td>
              `;
              tbody.appendChild(tr);
            });
          }
          return;
        }

        const name = (r.name || '').trim();
        const email = (r.email || '').trim();
        const active = parseInt(r.active, 10) === 1;

        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td class="font-weight-bold">${r.position}</td>
          <td>${name ? name : `<span class="text-muted">Not assigned</span>`}</td>
          <td>${email ? email : `<span class="text-muted">N/A</span>`}</td>
          <td>${statusBadge(active)}</td>
          <td class="action-btns">
            <button type="button"
              class="btn btn-sm btn-primary"
              data-toggle="modal"
              data-target="#assignModal"
              data-position="${r.position}"
              data-current-name="${name.replace(/"/g,'&quot;')}"
              data-current-email="${email.replace(/"/g,'&quot;')}">
              Assign
            </button>

            <button type="button"
              class="btn btn-sm ${active ? 'btn-outline-secondary' : 'btn-outline-success'}"
              onclick="toggleActive('${phase}', '${r.position.replace(/'/g, "\\'")}')">
              ${active ? 'Set Not Active' : 'Set Active'}
            </button>
          </td>
        `;
        tbody.appendChild(tr);
      });
    }

    function fetchPhase(phase) {
      $.post('phase_management.php', { ajax: '1', action: 'fetch', phase }, function(res) {
        if (!res.success) {
          showMsg('danger', res.message || 'Failed to load');
          return;
        }
        renderRows(res.rows, phase);
      }, 'json');
    }

    window.toggleActive = function(phase, position) {
      $.post('phase_management.php', { ajax: '1', action: 'toggle', phase, position }, function(res) {
        if (!res.success) {
          showMsg('danger', res.message || 'Failed to update');
          return;
        }
        showMsg('success', 'Status updated');
        fetchPhase(phase);
      }, 'json');
    };

    window.toggleBoardActive = function(phase, id) {
      $.post('phase_management.php', { ajax: '1', action: 'toggle', phase, position: 'Board of Director', id }, function(res) {
        if (!res.success) {
          showMsg('danger', res.message || 'Failed to update');
          return;
        }
        showMsg('success', 'Director status updated');
        fetchPhase(phase);
      }, 'json');
    };

    window.deleteBoard = function(phase, id) {
      if (!confirm('Remove this Board of Director from the phase officers list?')) return;

      $.post('phase_management.php', { ajax: '1', action: 'delete_board', phase, id }, function(res) {
        if (!res.success) {
          showMsg('danger', res.message || 'Delete failed');
          return;
        }
        showMsg('success', res.message || 'Removed successfully');
        fetchPhase(phase);
      }, 'json');
    };

    document.addEventListener("DOMContentLoaded", function () {
      const phaseSelect = document.getElementById("phaseSelect");
      fetchPhase(phaseSelect.value);

      phaseSelect.addEventListener("change", function () {
        const url = new URL(window.location.href);
        url.searchParams.set('phase', this.value);
        window.history.replaceState({}, '', url);
        fetchPhase(this.value);
      });

      const assignModal = document.getElementById("assignModal");
      $('#assignModal').on('show.bs.modal', function (event) {
        const btn = $(event.relatedTarget);
        const phase = phaseSelect.value;
        const position = btn.data("position");

        $("#modalPhase").val(phase);
        $("#modalPosition").val(position);
        $("#modalPositionKey").val(position);
        $("#modalOfficerName").val(btn.data("current-name") || "");
        $("#modalOfficerEmail").val(btn.data("current-email") || "");
      });

      document.getElementById("assignForm").addEventListener("submit", function (e) {
        e.preventDefault();

        const phase = document.getElementById("modalPhase").value;
        const position = document.getElementById("modalPositionKey").value;
        const name = document.getElementById("modalOfficerName").value.trim();
        const email = document.getElementById("modalOfficerEmail").value.trim();

        $.post('phase_management.php', { ajax: '1', action: 'assign', phase, position, name, email }, function(res) {
          if (!res.success) {
            showMsg('danger', res.message || 'Assign failed');
            return;
          }
          showMsg('success', res.message || 'Officer assigned');
          fetchPhase(phase);
          $('#assignModal').modal('hide');
        }, 'json');
      });
    });
  </script>
</body>
</html>