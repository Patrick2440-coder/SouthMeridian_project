<?php
session_start();

// OPTIONAL: enforce superadmin
// if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') { header("Location: authentication-login.html"); exit; }

// ===================== DB =====================
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nfmt($n){ return number_format((float)$n, 0); }
function money($n){ return number_format((float)$n, 2); }

// ===================== DATE RANGES =====================
$now = new DateTime("now");
$today = $now->format("Y-m-d");

$start7 = (new DateTime("now"))->modify("-6 days")->format("Y-m-d 00:00:00");
$startPrev7 = (new DateTime("now"))->modify("-13 days")->format("Y-m-d 00:00:00");
$endPrev7 = (new DateTime("now"))->modify("-7 days")->format("Y-m-d 23:59:59");

$start30 = (new DateTime("now"))->modify("-29 days")->format("Y-m-d 00:00:00");

// ===================== KPIs =====================

// Total approved homeowners
$approved_homeowners = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM homeowners WHERE status='approved'");
$stmt->execute();
$approved_homeowners = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Total homeowners (all statuses)
$total_homeowners = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM homeowners");
$stmt->execute();
$total_homeowners = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$active_homeowners_pct = ($total_homeowners > 0) ? round(($approved_homeowners / $total_homeowners) * 100) : 0;

// “Active Voters” (no voting table in schema) -> using “active participants” as distinct payers in last 30 days
$active_participants_30d = 0;
$stmt = $conn->prepare("
  SELECT COUNT(DISTINCT homeowner_id) AS c
  FROM finance_payments
  WHERE status='paid' AND paid_at >= ?
");
$stmt->bind_param("s", $start30);
$stmt->execute();
$active_participants_30d = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Weekly dues collected (last 7 days) and comparison (previous 7 days)
$dues_week = 0.0;
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) AS s
  FROM finance_payments
  WHERE status='paid' AND paid_at >= ?
");
$stmt->bind_param("s", $start7);
$stmt->execute();
$dues_week = (float)$stmt->get_result()->fetch_assoc()['s'];
$stmt->close();

$dues_prev_week = 0.0;
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) AS s
  FROM finance_payments
  WHERE status='paid' AND paid_at BETWEEN ? AND ?
");
$stmt->bind_param("ss", $startPrev7, $endPrev7);
$stmt->execute();
$dues_prev_week = (float)$stmt->get_result()->fetch_assoc()['s'];
$stmt->close();

$dues_change_pct = 0;
if ($dues_prev_week > 0) {
  $dues_change_pct = round((($dues_week - $dues_prev_week) / $dues_prev_week) * 100);
} elseif ($dues_week > 0) {
  $dues_change_pct = 100;
}

// Maintenance expenses logged this week (closest match in your DB)
$maintenance_expenses_week = 0;
$stmt = $conn->prepare("
  SELECT COUNT(*) AS c
  FROM finance_expenses
  WHERE category='maintenance' AND expense_date >= DATE(?)
");
$stmt->bind_param("s", $start7);
$stmt->execute();
$maintenance_expenses_week = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Community concerns this week -> parking violations issued this week
$concerns_week = 0;
$stmt = $conn->prepare("
  SELECT COUNT(*) AS c
  FROM parking_violations
  WHERE issued_at >= ?
");
$stmt->bind_param("s", $start7);
$stmt->execute();
$concerns_week = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// Pending approvals notifications
$pending_homeowners = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM homeowners WHERE status='pending'");
$stmt->execute();
$pending_homeowners = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$pending_permits = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM parking_permits WHERE status='pending'");
$stmt->execute();
$pending_permits = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$pending_reports = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM finance_report_requests WHERE status='pending'");
$stmt->execute();
$pending_reports = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$notif_total = $pending_homeowners + $pending_permits + $pending_reports;

// Chart data: approved homeowners per phase
$phases = ['Phase 1','Phase 2','Phase 3'];
$approved_by_phase = array_fill_keys($phases, 0);

$stmt = $conn->prepare("
  SELECT phase, COUNT(*) AS c
  FROM homeowners
  WHERE status='approved'
  GROUP BY phase
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $p = (string)$row['phase'];
  if (isset($approved_by_phase[$p])) $approved_by_phase[$p] = (int)$row['c'];
}
$stmt->close();

// Chart data: “active participants” per phase (distinct payers last 30 days)
$active_by_phase = array_fill_keys($phases, 0);
$stmt = $conn->prepare("
  SELECT phase, COUNT(DISTINCT homeowner_id) AS c
  FROM finance_payments
  WHERE status='paid' AND paid_at >= ?
  GROUP BY phase
");
$stmt->bind_param("s", $start30);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
  $p = (string)$row['phase'];
  if (isset($active_by_phase[$p])) $active_by_phase[$p] = (int)$row['c'];
}
$stmt->close();

// Table: latest homeowners (from DB)
$latest_homeowners = [];
$stmt = $conn->prepare("
  SELECT first_name, middle_name, last_name, phase, house_lot_number, status, created_at
  FROM homeowners
  ORDER BY created_at DESC
  LIMIT 10
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $latest_homeowners[] = $row;
$stmt->close();
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Super Admin</title>
  <link rel="shortcut icon" type="image/png" href="../assets/images/logos/favicon.png" />
  <link rel="stylesheet" href="../superadmin/assets/css/styles.min.css" />
</head>

<body>
  <div class="page-wrapper" id="main-wrapper" data-layout="vertical" data-navbarbg="skin6" data-sidebartype="full"
    data-sidebar-position="fixed" data-header-position="fixed">

    <!-- Topstrip -->
    <div class="app-topstrip py-6 px-3 w-100 d-lg-flex align-items-center justify-content-between" style="background-color: #077f46;">
      <div class="d-flex align-items-center justify-content-center gap-5 mb-2 mb-lg-0">
        <a class="d-flex justify-content-center" href="#">
          <img src="assets/images/logos/logo-wrappixel.svg" alt="" width="150">
        </a>
      </div>
    </div>

    <!-- Sidebar Start -->
    <aside class="left-sidebar">
      <div>
        <div class="brand-logo d-flex align-items-center justify-content-between">
          <a href="./dashboard.php" class="text-nowrap logo-img">
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
              <a class="sidebar-link" href="./dashboard.php" aria-expanded="false">
                <i class="ti ti-layout-dashboard"></i>
                <span class="hide-menu">Dashboard</span>
              </a>
            </li>

            <!-- User Management Dropdown -->
            <li class="sidebar-item">
              <a class="sidebar-link has-arrow collapsed"
                href="#userMgmtMenu"
                data-bs-toggle="collapse"
                role="button"
                aria-expanded="false"
                aria-controls="userMgmtMenu">
                <i class="ti ti-users"></i>
                <span class="hide-menu">User Management</span>
              </a>

              <ul id="userMgmtMenu" class="collapse first-level">
                <li class="sidebar-item">
                  <a href="./user_management.php" class="sidebar-link">
                    <i class="ti ti-home"></i>
                    <span class="hide-menu">Homeowners</span>
                  </a>
                </li>

                <li class="sidebar-item">
                  <a href="./phase_management.php" class="sidebar-link">
                    <i class="ti ti-shield-check"></i>
                    <span class="hide-menu">Officers</span>
                  </a>
                </li>
              </ul>
            </li>

            <li class="sidebar-item">
              <a class="sidebar-link" href="./voting.html" aria-expanded="false">
                <i class="ti ti-checkbox"></i>
                <span class="hide-menu">Voting Management</span>
              </a>
            </li>

          </ul>
        </nav>
      </div>
    </aside>
    <!-- Sidebar End -->

    <!-- Main wrapper -->
    <div class="body-wrapper">

      <!-- Header Start -->
      <header class="app-header">
        <nav class="navbar navbar-expand-lg navbar-light">
          <ul class="navbar-nav">
            <li class="nav-item d-block d-xl-none">
              <a class="nav-link sidebartoggler" id="headerCollapse" href="javascript:void(0)">
                <i class="ti ti-menu-2"></i>
              </a>
            </li>

            <!-- Notifications (DB-based) -->
            <li class="nav-item dropdown">
              <a class="nav-link" href="javascript:void(0)" id="drop1" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="ti ti-bell"></i>
                <?php if ($notif_total > 0): ?>
                  <div class="notification bg-primary rounded-circle"></div>
                <?php endif; ?>
              </a>
              <div class="dropdown-menu dropdown-menu-animate-up" aria-labelledby="drop1">
                <div class="px-3 py-2 border-bottom">
                  <strong class="fs-3">Notifications</strong>
                  <div class="text-muted fs-2">Pending items</div>
                </div>
                <div class="message-body">
                  <a href="./user_management.php" class="dropdown-item d-flex justify-content-between align-items-center">
                    <span>Pending homeowners</span>
                    <span class="badge bg-warning text-dark"><?= (int)$pending_homeowners ?></span>
                  </a>
                  <a href="#" class="dropdown-item d-flex justify-content-between align-items-center">
                    <span>Pending parking permits</span>
                    <span class="badge bg-warning text-dark"><?= (int)$pending_permits ?></span>
                  </a>
                  <a href="#" class="dropdown-item d-flex justify-content-between align-items-center">
                    <span>Pending finance reports</span>
                    <span class="badge bg-warning text-dark"><?= (int)$pending_reports ?></span>
                  </a>
                  <?php if ($notif_total === 0): ?>
                    <div class="dropdown-item text-muted">No pending items.</div>
                  <?php endif; ?>
                </div>
              </div>
            </li>
          </ul>

          <div class="navbar-collapse justify-content-end px-0" id="navbarNav">
            <ul class="navbar-nav flex-row ms-auto align-items-center justify-content-end">

              <li class="nav-item dropdown">
                <a class="nav-link" href="javascript:void(0)" id="drop2" data-bs-toggle="dropdown" aria-expanded="false">
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
                    <a href="../index.php" class="btn btn-outline-primary mx-3 mt-2 d-block">Logout</a>
                  </div>
                </div>
              </li>

            </ul>
          </div>
        </nav>
      </header>
      <!-- Header End -->

      <div class="body-wrapper-inner">
        <div class="container-fluid">

          <!-- Row 1 -->
          <div class="row">
            <div class="col-lg-8">
              <div class="card w-100">
                <div class="card-body">
                  <div class="d-md-flex align-items-center">
                    <div>
                      <h4 class="card-title">Overview</h4>
                      <p class="text-muted mb-0">Approved homeowners and active participants (last 30 days)</p>
                    </div>
                    <div class="ms-auto">
                      <ul class="list-unstyled mb-0">
                        <li class="list-inline-item text-primary">
                          <span class="round-8 text-bg-primary rounded-circle me-1 d-inline-block"></span>
                          Approved Homeowners: <strong><?= nfmt($approved_homeowners) ?></strong>
                        </li>
                        <li class="list-inline-item text-info">
                          <span class="round-8 text-bg-info rounded-circle me-1 d-inline-block"></span>
                          Active Participants: <strong><?= nfmt($active_participants_30d) ?></strong>
                        </li>
                      </ul>
                    </div>
                  </div>

                  <!-- Chart (DB-based) -->
                  <div id="sales-overview" class="mt-4 mx-n6"></div>
                </div>
              </div>
            </div>

            <div class="col-lg-4">
              <div class="card overflow-hidden">
                <div class="card-body pb-0">
                  <div class="d-flex align-items-start">
                    <div>
                      <h4 class="card-title">Weekly HOA Stats</h4>
                      <p class="card-subtitle">Computed from your database</p>
                    </div>
                    <div class="ms-auto">
                      <div class="dropdown">
                        <a href="javascript:void(0)" class="text-muted" id="hoa-weekly-dropdown" data-bs-toggle="dropdown" aria-expanded="false">
                          <i class="ti ti-dots fs-7"></i>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="hoa-weekly-dropdown">
                          <li><a class="dropdown-item" href="./user_management.php">View Homeowners</a></li>
                          <li><a class="dropdown-item" href="#">Finance</a></li>
                          <li><a class="dropdown-item" href="#">Settings</a></li>
                        </ul>
                      </div>
                    </div>
                  </div>

                  <!-- Weekly Dues Collected -->
                  <div class="mt-4 pb-3 d-flex align-items-center">
                    <span class="btn btn-primary rounded-circle round-48 hstack justify-content-center">
                      <i class="ti ti-cash fs-6"></i>
                    </span>
                    <div class="ms-3">
                      <h5 class="mb-0 fw-bolder fs-4">₱ <?= money($dues_week) ?></h5>
                      <span class="text-muted fs-3">Dues collected (last 7 days)</span>
                    </div>
                    <div class="ms-auto">
                      <?php
                        $badgeClass = ($dues_change_pct >= 0) ? "bg-success-subtle text-success" : "bg-danger-subtle text-danger";
                        $badgeText  = ($dues_change_pct >= 0) ? ("+" . $dues_change_pct . "%") : ($dues_change_pct . "%");
                      ?>
                      <span class="badge <?= $badgeClass ?>"><?= esc($badgeText) ?></span>
                    </div>
                  </div>

                  <!-- Active Homeowners -->
                  <div class="py-3 d-flex align-items-center">
                    <span class="btn btn-warning rounded-circle round-48 hstack justify-content-center">
                      <i class="ti ti-users fs-6"></i>
                    </span>
                    <div class="ms-3">
                      <h5 class="mb-0 fw-bolder fs-4"><?= (int)$active_homeowners_pct ?>%</h5>
                      <span class="text-muted fs-3">Approved homeowners (of total)</span>
                    </div>
                    <div class="ms-auto">
                      <span class="badge bg-secondary-subtle text-muted"><?= nfmt($approved_homeowners) ?>/<?= nfmt($total_homeowners) ?></span>
                    </div>
                  </div>

                  <!-- Maintenance (from finance_expenses category=maintenance) -->
                  <div class="py-3 d-flex align-items-center">
                    <span class="btn btn-success rounded-circle round-48 hstack justify-content-center">
                      <i class="ti ti-tool fs-6"></i>
                    </span>
                    <div class="ms-3">
                      <h5 class="mb-0 fw-bolder fs-4"><?= nfmt($maintenance_expenses_week) ?></h5>
                      <span class="text-muted fs-3">Maintenance expenses logged (7 days)</span>
                    </div>
                    <div class="ms-auto">
                      <span class="badge bg-success-subtle text-success">Updated</span>
                    </div>
                  </div>

                  <!-- Community Concerns (parking violations) -->
                  <div class="pt-3 mb-7 d-flex align-items-center">
                    <span class="btn btn-secondary rounded-circle round-48 hstack justify-content-center">
                      <i class="ti ti-message-report fs-6"></i>
                    </span>
                    <div class="ms-3">
                      <h5 class="mb-0 fw-bolder fs-4"><?= nfmt($concerns_week) ?></h5>
                      <span class="text-muted fs-3">Parking violations issued (7 days)</span>
                    </div>
                    <div class="ms-auto">
                      <span class="badge bg-warning-subtle text-warning">Monitor</span>
                    </div>
                  </div>

                </div>
              </div>
            </div>

            <!-- Latest Homeowners Table (DB-based) -->
            <div class="col-12">
              <div class="card">
                <div class="card-body">
                  <div class="d-md-flex align-items-center">
                    <div>
                      <h4 class="card-title">Latest Homeowners</h4>
                      <p class="text-muted mb-0">Newest registrations from your homeowners table</p>
                    </div>
                  </div>

                  <div class="table-responsive mt-4">
                    <table class="table mb-0 text-nowrap varient-table align-middle fs-3">
                      <thead>
                        <tr>
                          <th scope="col" class="px-0 text-muted">Name</th>
                          <th scope="col" class="px-0 text-muted">Phase / Lot</th>
                          <th scope="col" class="px-0 text-muted">Status</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($latest_homeowners)): ?>
                          <tr>
                            <td colspan="3" class="text-muted">No homeowners found.</td>
                          </tr>
                        <?php else: ?>
                          <?php foreach ($latest_homeowners as $h): ?>
                            <?php
                              $full = trim($h['first_name'].' '.$h['middle_name'].' '.$h['last_name']);
                              $phaseLot = trim($h['phase'].' • '.$h['house_lot_number']);

                              $status = (string)$h['status'];
                              $badge = 'bg-secondary';
                              if ($status === 'approved') $badge = 'bg-success';
                              elseif ($status === 'pending') $badge = 'bg-warning text-dark';
                              elseif ($status === 'rejected') $badge = 'bg-danger';
                            ?>
                            <tr>
                              <td class="px-0">
                                <div class="d-flex align-items-center">
                                  <img src="./assets/images/profile/user-1.jpg" class="rounded-circle" width="40" alt="user" />
                                  <div class="ms-3">
                                    <h6 class="mb-0 fw-bolder"><?= esc($full) ?></h6>
                                    <span class="text-muted"><?= esc(date("M d, Y", strtotime($h['created_at']))) ?></span>
                                  </div>
                                </div>
                              </td>
                              <td class="px-0"><?= esc($phaseLot) ?></td>
                              <td class="px-0">
                                <span class="badge <?= $badge ?>"><?= esc(ucfirst($status)) ?></span>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>

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

  <script src="./assets/libs/jquery/dist/jquery.min.js"></script>
  <script src="./assets/libs/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/sidebarmenu.js"></script>
  <script src="./assets/js/app.min.js"></script>
  <script src="./assets/libs/apexcharts/dist/apexcharts.min.js"></script>
  <script src="./assets/libs/simplebar/dist/simplebar.js"></script>
  <!-- solar icons -->
  <script src="https://cdn.jsdelivr.net/npm/iconify-icon@1.0.8/dist/iconify-icon.min.js"></script>

  <script>
    // DB-based chart: Approved homeowners per phase + Active participants per phase (last 30 days)
    const phases = <?= json_encode($phases) ?>;
    const approvedByPhase = <?= json_encode(array_values($approved_by_phase)) ?>;
    const activeByPhase = <?= json_encode(array_values($active_by_phase)) ?>;

    const options = {
      chart: { type: 'bar', height: 320, toolbar: { show: false } },
      series: [
        { name: 'Approved Homeowners', data: approvedByPhase },
        { name: 'Active Participants (30 days)', data: activeByPhase }
      ],
      xaxis: { categories: phases },
      plotOptions: { bar: { columnWidth: '45%', borderRadius: 6 } },
      dataLabels: { enabled: false },
      legend: { position: 'top' }
    };

    const el = document.querySelector("#sales-overview");
    if (el) new ApexCharts(el, options).render();
  </script>
</body>
</html>