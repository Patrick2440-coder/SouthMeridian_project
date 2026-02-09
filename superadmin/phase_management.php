<?php
session_start();

// ===================== DB CONNECTION =====================
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$POSITIONS = ["President", "Vice President", "Secretary", "Treasurer", "Auditor", "Board of Director"];

// Ensure DB has a row for each phase+position
function ensure_phase_rows(mysqli $conn, string $phase, array $POSITIONS): void {
  $sql = "INSERT IGNORE INTO hoa_officers (phase, position, officer_name, officer_email, is_active)
          VALUES (?, ?, NULL, NULL, 1)";
  $stmt = $conn->prepare($sql);
  foreach ($POSITIONS as $pos) {
    $stmt->bind_param("ss", $phase, $pos);
    $stmt->execute();
  }
  $stmt->close();
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

  ensure_phase_rows($conn, $phase, $POSITIONS);

  if ($action === 'fetch') {
    $stmt = $conn->prepare("SELECT position, officer_name, officer_email, is_active FROM hoa_officers WHERE phase=?");
    $stmt->bind_param("s", $phase);
    $stmt->execute();
    $res = $stmt->get_result();

    $map = [];
    while ($row = $res->fetch_assoc()) {
      $map[$row['position']] = [
        'name' => $row['officer_name'] ?? '',
        'email' => $row['officer_email'] ?? '',
        'active' => (int)$row['is_active']
      ];
    }
    $stmt->close();

    $rows = [];
    foreach ($POSITIONS as $pos) {
      $rows[] = [
        'position' => $pos,
        'name' => $map[$pos]['name'] ?? '',
        'email' => $map[$pos]['email'] ?? '',
        'active' => $map[$pos]['active'] ?? 1
      ];
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

    // 1) Update officer assignment
    $stmt = $conn->prepare("
      INSERT INTO hoa_officers (phase, position, officer_name, officer_email, is_active)
      VALUES (?, ?, ?, ?, 1)
      ON DUPLICATE KEY UPDATE
        officer_name=VALUES(officer_name),
        officer_email=VALUES(officer_email),
        is_active=1
    ");
    $stmt->bind_param("ssss", $phase, $position, $name, $email);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
      echo json_encode(['success' => false, 'message' => 'Assign failed']);
      exit;
    }

    // 2) IMPORTANT: If assigning President, also sync phase admin's full_name
    // This makes dashboard top-right name readable + consistent with assigned President
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
    if (!in_array($position, $POSITIONS, true)) {
      echo json_encode(['success' => false, 'message' => 'Invalid position']);
      exit;
    }

    $stmt = $conn->prepare("
      UPDATE hoa_officers
      SET is_active = IF(is_active=1, 0, 1)
      WHERE phase=? AND position=?
    ");
    $stmt->bind_param("ss", $phase, $position);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => $ok, 'message' => $ok ? 'Status updated' : 'Update failed']);
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Unknown action']);
  exit;
}

// ===================== PAGE LOAD =====================
$selectedPhase = $_GET['phase'] ?? 'Phase 1';
if (!in_array($selectedPhase, ['Phase 1','Phase 2','Phase 3'], true)) $selectedPhase = 'Phase 1';
ensure_phase_rows($conn, $selectedPhase, $POSITIONS);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Super Admin | Phase Management</title>
  <link rel="shortcut icon" type="image/png" href="../assets/images/logos/favicon.png" />
  <link rel="stylesheet" href="../superadmin/assets/css/styles.min.css" />
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
</head>

<body>
  <div class="page-wrapper" id="main-wrapper" data-layout="vertical" data-navbarbg="skin6" data-sidebartype="full"
    data-sidebar-position="fixed" data-header-position="fixed">

    <div class="app-topstrip py-6 px-3 w-100 d-lg-flex align-items-center justify-content-between"
      style="background-color: #077f46;">
      <div class="d-flex align-items-center justify-content-center gap-5 mb-2 mb-lg-0">
        <a class="d-flex justify-content-center" href="#">
          <img src="assets/images/logos/logo-wrappixel.svg" alt="" width="150">
        </a>
      </div>
    </div>

    <!-- Sidebar -->
    <aside class="left-sidebar">
      <div>
        <div class="brand-logo d-flex align-items-center justify-content-between">
          <a href="./dashboard.html" class="text-nowrap logo-img">
            <img src="assets/images/logos/logo.svg" alt="" />
          </a>
          <div class="close-btn d-xl-none d-block sidebartoggler cursor-pointer" id="sidebarCollapse">
            <i class="ti ti-x fs-6"></i>
          </div>
        </div>

        <nav class="sidebar-nav scroll-sidebar" data-simplebar="">
          <ul id="sidebarnav">
            <li class="nav-small-cap">
              <iconify-icon icon="solar:menu-dots-linear" class="nav-small-cap-icon fs-4"></iconify-icon>
              <span class="hide-menu">Home</span>
            </li>

            <li class="sidebar-item">
              <a class="sidebar-link" href="./dashboard.html" aria-expanded="false">
                <i class="ti ti-layout-dashboard"></i>
                <span class="hide-menu">Dashboard</span>
              </a>
            </li>

            <li class="sidebar-item">
              <a class="sidebar-link" href="./user_management.html" aria-expanded="false">
                <i class="ti ti-layout-dashboard"></i>
                <span class="hide-menu">User Management</span>
              </a>
            </li>

            <li class="sidebar-item">
              <a class="sidebar-link" href="./voting.html" aria-expanded="false">
                <i class="ti ti-checkbox"></i>
                <span class="hide-menu">Voting Management</span>
              </a>
            </li>

            <li class="sidebar-item">
              <a class="sidebar-link" href="./phase_management.php" aria-expanded="false">
                <i class="ti ti-settings"></i>
                <span class="hide-menu">Phase Management</span>
              </a>
            </li>

          </ul>
        </nav>
      </div>
    </aside>

    <!-- Main -->
    <div class="body-wrapper">
      <header class="app-header">
        <nav class="navbar navbar-expand-lg navbar-light">
          <ul class="navbar-nav">
            <li class="nav-item d-block d-xl-none">
              <a class="nav-link sidebartoggler " id="headerCollapse" href="javascript:void(0)">
                <i class="ti ti-menu-2"></i>
              </a>
            </li>
          </ul>
          <div class="navbar-collapse justify-content-end px-0" id="navbarNav">
            <ul class="navbar-nav flex-row ms-auto align-items-center justify-content-end">
              <li class="nav-item dropdown">
                <a class="nav-link " href="javascript:void(0)" id="drop2" data-bs-toggle="dropdown"
                  aria-expanded="false">
                  <img src="./assets/images/profile/user-1.jpg" alt="" width="35" height="35" class="rounded-circle">
                </a>
                <div class="dropdown-menu dropdown-menu-end dropdown-menu-animate-up" aria-labelledby="drop2">
                  <div class="message-body">
                    <a href="./profile.html" class="d-flex align-items-center gap-2 dropdown-item">
                      <i class="ti ti-user fs-6"></i>
                      <p class="mb-0 fs-3">My Profile</p>
                    </a>
                    <a href="./logs.html" class="d-flex align-items-center gap-2 dropdown-item">
                      <i class="ti ti-list-check fs-6"></i>
                      <p class="mb-0 fs-3">Activity Logs</p>
                    </a>
                    <a href="./authentication-login.html" class="btn btn-outline-primary mx-3 mt-2 d-block">Logout</a>
                  </div>
                </div>
              </li>
            </ul>
          </div>
        </nav>
      </header>

      <div class="body-wrapper-inner">
        <div class="container-fluid">

          <div class="row mt-4">
            <div class="col-12">
              <div class="card bg-white shadow-sm">
                <div class="card-body">
                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                      <h5 class="card-title mb-1">Phase Management</h5>
                      <p class="text-muted mb-0">Current HOA officers per phase</p>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                      <label class="mb-0 small text-muted">Select Phase:</label>
                      <select id="phaseSelect" class="form-select form-select-sm" style="width: 140px;">
                        <option value="Phase 1" <?= $selectedPhase==='Phase 1'?'selected':''; ?>>Phase 1</option>
                        <option value="Phase 2" <?= $selectedPhase==='Phase 2'?'selected':''; ?>>Phase 2</option>
                        <option value="Phase 3" <?= $selectedPhase==='Phase 3'?'selected':''; ?>>Phase 3</option>
                      </select>
                    </div>
                  </div>

                  <hr class="my-3">

                  <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                      <thead class="table-light">
                        <tr>
                          <th style="width: 20%;">Position</th>
                          <th>Assigned Officer</th>
                          <th>Email</th>
                          <th style="width: 12%;">Status</th>
                          <th style="width: 22%;">Actions</th>
                        </tr>
                      </thead>
                      <tbody id="rolesTbody"></tbody>
                    </table>
                  </div>

                  <div id="msgBox" class="mt-3"></div>

                </div>
              </div>
            </div>
          </div>

          <div class="py-6 px-6 text-center">
            <p>
              © <span>Copyright</span>
              <strong class="px-1 sitename">South Meridian Homes</strong>
              <span>All Rights Reserved</span>
            </p>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- Assign Modal -->
  <div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="assignModalLabel">Assign Officer</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="assignForm">
            <input type="hidden" id="modalPositionKey" />
            <div class="mb-3">
              <label class="form-label">Selected Phase</label>
              <input type="text" class="form-control" id="modalPhase" readonly>
            </div>
            <div class="mb-3">
              <label class="form-label">Position</label>
              <input type="text" class="form-control" id="modalPosition" readonly>
            </div>
            <div class="mb-3">
              <label class="form-label">Officer Name</label>
              <input type="text" class="form-control" id="modalOfficerName" placeholder="Type name..." required>
            </div>
            <div class="mb-3">
              <label class="form-label">Officer Email</label>
              <input type="email" class="form-control" id="modalOfficerEmail" placeholder="name@gmail.com" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Save Assignment</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Scripts -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script src="./assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/sidebarmenu.js"></script>
  <script src="./assets/js/app.min.js"></script>
  <script src="./assets/libs/apexcharts/dist/apexcharts.min.js"></script>
  <script src="./assets/libs/simplebar/dist/simplebar.js"></script>
  <script src="./assets/js/dashboard.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

  <script>
    const msgBox = document.getElementById('msgBox');

    function showMsg(type, text) {
      msgBox.innerHTML = `<div class="alert alert-${type} py-2 mb-0" role="alert">${text}</div>`;
      setTimeout(() => { msgBox.innerHTML = ''; }, 2500);
    }

    function statusBadge(isActive) {
      return isActive
        ? `<span class="badge bg-success">Active</span>`
        : `<span class="badge bg-secondary">Not Active</span>`;
    }

    function renderRows(rows, phase) {
      const tbody = document.getElementById("rolesTbody");
      tbody.innerHTML = "";

      rows.forEach(r => {
        const name = (r.name || '').trim();
        const email = (r.email || '').trim();
        const active = parseInt(r.active, 10) === 1;

        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td class="fw-semibold">${r.position}</td>
          <td>${name ? name : `<span class="text-muted">Not assigned</span>`}</td>
          <td>${email ? email : `<span class="text-muted">N/A</span>`}</td>
          <td>${statusBadge(active)}</td>
          <td>
            <button type="button"
              class="btn btn-sm btn-primary me-1"
              data-bs-toggle="modal"
              data-bs-target="#assignModal"
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
      assignModal.addEventListener("show.bs.modal", function (event) {
        const btn = event.relatedTarget;
        const phase = phaseSelect.value;
        const position = btn.getAttribute("data-position");

        document.getElementById("modalPhase").value = phase;
        document.getElementById("modalPosition").value = position;
        document.getElementById("modalPositionKey").value = position;

        document.getElementById("modalOfficerName").value = btn.getAttribute("data-current-name") || "";
        document.getElementById("modalOfficerEmail").value = btn.getAttribute("data-current-email") || "";
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

          const modalInstance = bootstrap.Modal.getInstance(document.getElementById("assignModal"));
          modalInstance.hide();
        }, 'json');
      });
    });
  </script>
</body>
</html>