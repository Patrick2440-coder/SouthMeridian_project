<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
    header("Location: ../index.php");
    exit;
}

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$homeowner_id = (int)$_SESSION['homeowner_id'];

$stmt = $conn->prepare("SELECT * FROM homeowners WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $homeowner_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || ($user['status'] ?? '') !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
}

$phase        = (string)$user['phase'];
$fullName     = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$initials     = strtoupper(substr($user['first_name'] ?? 'H', 0, 1) . substr($user['last_name'] ?? 'O', 0, 1));
$pageTitle    = "My Tenants • South Meridian Homes";
$activePage   = 'homeowner_tenant_register.php';

$parkingPages = [
    'homeowner_parking.php',
    'homeowner_parking_permit.php',
    'homeowner_parking_violations.php'
];
$parkingOpen = in_array($activePage, $parkingPages, true);
$tenantOpen  = true;

$accessOpts = [
    'can_pay_dues'      => ['Pay Monthly Dues', 'cash-coin', 'Allow tenant to pay monthly dues online.'],
    'can_rent'          => ['Facility Rentals', 'calendar2-week', 'Allow tenant to book courts, clubhouse, etc.'],
    'can_parking'       => ['Parking Permits', 'car-front', 'Allow tenant to apply for a parking permit.'],
    'can_announcements' => ['Announcement Feed', 'megaphone', 'Allow tenant to view community announcements.'],
];

/* =========================
   ACTIVE TENANT COUNT
   ========================= */
$tenantCount = 0;
$tcnt = $conn->prepare("SELECT COUNT(*) FROM tenants WHERE homeowner_id = ? AND status = 'active'");
$tcnt->bind_param("i", $homeowner_id);
$tcnt->execute();
$tcnt->bind_result($tenantCount);
$tcnt->fetch();
$tcnt->close();

/* =========================
   POST / AJAX ACTIONS
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'register_tenant') {
        $first_name     = trim($_POST['first_name'] ?? '');
        $middle_name    = trim($_POST['middle_name'] ?? '');
        $last_name      = trim($_POST['last_name'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $lease_start    = trim($_POST['lease_start'] ?? '');
        $lease_end      = trim($_POST['lease_end'] ?? '');
        $password_raw   = trim($_POST['password'] ?? '');

        $can_pay_dues = (int)($_POST['can_pay_dues'] ?? 0);
        $can_rent     = (int)($_POST['can_rent'] ?? 0);
        $can_parking  = (int)($_POST['can_parking'] ?? 0);
        $can_ann      = (int)($_POST['can_announcements'] ?? 0);

        if ($first_name === '' || $last_name === '' || $email === '' || $password_raw === '') {
            echo json_encode(['ok' => false, 'msg' => 'First name, last name, email, and password are required.']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid email address.']);
            exit;
        }

        if (strlen($password_raw) < 6) {
            echo json_encode(['ok' => false, 'msg' => 'Password must be at least 6 characters.']);
            exit;
        }

        if ($lease_start !== '' && $lease_end !== '' && $lease_end < $lease_start) {
            echo json_encode(['ok' => false, 'msg' => 'Lease end date must be later than lease start date.']);
            exit;
        }

        $chk = $conn->prepare("
            SELECT id FROM homeowners WHERE email = ?
            UNION
            SELECT id FROM tenants WHERE email = ?
        ");
        $chk->bind_param("ss", $email, $email);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();

        if ($exists) {
            echo json_encode(['ok' => false, 'msg' => 'Email is already in use by another account.']);
            exit;
        }

        $valid_id_path = null;
        if (!empty($_FILES['valid_id']['name'])) {
            if (!isset($_FILES['valid_id']) || $_FILES['valid_id']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['ok' => false, 'msg' => 'Valid ID upload failed.']);
                exit;
            }

            $ext = strtolower(pathinfo($_FILES['valid_id']['name'], PATHINFO_EXTENSION));
            $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
            if (!in_array($ext, $allowedExt, true)) {
                echo json_encode(['ok' => false, 'msg' => 'Valid ID must be JPG, JPEG, PNG, or PDF only.']);
                exit;
            }

            if ($_FILES['valid_id']['size'] > 5 * 1024 * 1024) {
                echo json_encode(['ok' => false, 'msg' => 'Valid ID must not exceed 5MB.']);
                exit;
            }

            $dir = "../uploads/tenants/{$homeowner_id}/";
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
                echo json_encode(['ok' => false, 'msg' => 'Failed to create upload folder.']);
                exit;
            }

            $fname = time() . '_' . bin2hex(random_bytes(4)) . '_tenant_id.' . $ext;
            $dest  = $dir . $fname;

            if (!move_uploaded_file($_FILES['valid_id']['tmp_name'], $dest)) {
                echo json_encode(['ok' => false, 'msg' => 'Failed to save uploaded file.']);
                exit;
            }

            $valid_id_path = "uploads/tenants/{$homeowner_id}/{$fname}";
        }

        $hashed = password_hash($password_raw, PASSWORD_DEFAULT);
        $ls = $lease_start !== '' ? $lease_start : null;
        $le = $lease_end   !== '' ? $lease_end   : null;

        $ins = $conn->prepare("
            INSERT INTO tenants (
                homeowner_id, phase, house_lot_number,
                first_name, middle_name, last_name,
                email, password, contact_number, valid_id_path,
                can_pay_dues, can_rent, can_parking, can_announcements,
                lease_start, lease_end, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");

        $ins->bind_param(
            "isssssssssiiiiss",
            $homeowner_id,
            $user['phase'],
            $user['house_lot_number'],
            $first_name,
            $middle_name,
            $last_name,
            $email,
            $hashed,
            $contact_number,
            $valid_id_path,
            $can_pay_dues,
            $can_rent,
            $can_parking,
            $can_ann,
            $ls,
            $le
        );

        if ($ins->execute()) {
            echo json_encode(['ok' => true, 'msg' => 'Tenant registered successfully.']);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Database error: ' . $ins->error]);
        }
        $ins->close();
        exit;
    }

    if ($action === 'toggle_status') {
        $tid   = (int)($_POST['tenant_id'] ?? 0);
        $newst = ($_POST['new_status'] ?? 'inactive') === 'active' ? 'active' : 'inactive';

        $upd = $conn->prepare("UPDATE tenants SET status = ? WHERE id = ? AND homeowner_id = ?");
        $upd->bind_param("sii", $newst, $tid, $homeowner_id);
        $ok = $upd->execute() && $upd->affected_rows >= 0;
        $upd->close();

        echo json_encode([
            'ok' => $ok,
            'msg' => $ok ? 'Tenant status updated successfully.' : 'Failed to update tenant status.'
        ]);
        exit;
    }

    if ($action === 'update_access') {
        $tid      = (int)($_POST['tenant_id'] ?? 0);
        $can_pay  = (int)($_POST['can_pay_dues'] ?? 0);
        $can_rent = (int)($_POST['can_rent'] ?? 0);
        $can_park = (int)($_POST['can_parking'] ?? 0);
        $can_ann  = (int)($_POST['can_announcements'] ?? 0);

        $upd = $conn->prepare("
            UPDATE tenants
            SET can_pay_dues = ?, can_rent = ?, can_parking = ?, can_announcements = ?
            WHERE id = ? AND homeowner_id = ?
        ");
        $upd->bind_param("iiiiii", $can_pay, $can_rent, $can_park, $can_ann, $tid, $homeowner_id);
        $ok = $upd->execute() && $upd->affected_rows >= 0;
        $upd->close();

        echo json_encode([
            'ok' => $ok,
            'msg' => $ok ? 'Tenant access updated successfully.' : 'Failed to update tenant access.'
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    exit;
}

/* =========================
   LOAD TENANTS
   ========================= */
$tenants = [];
$tq = $conn->prepare("SELECT * FROM tenants WHERE homeowner_id = ? ORDER BY registered_at DESC");
$tq->bind_param("i", $homeowner_id);
$tq->execute();
$tenants = $tq->get_result()->fetch_all(MYSQLI_ASSOC);
$tq->close();

$lat = $user['latitude'] ?? null;
$lng = $user['longitude'] ?? null;
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

  .app-shell { position: relative; }

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

  .tenant-stat{
    display:flex;
    align-items:center;
    gap:10px;
    padding:12px 14px;
    border-radius:16px;
    background:#f8fafc;
    border:1px solid #e9eef5;
    font-weight:700;
  }

  .tenant-card {
    border: 1px solid #eef2f7;
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(15,23,42,.05);
    height: 100%;
  }

  .tenant-card.inactive {
    opacity: .82;
    border-color: #d7dde6;
  }

  .tenant-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: linear-gradient(135deg, #198754, #157347);
    color: #fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight: 800;
    font-size: 1rem;
    flex-shrink: 0;
  }

  .access-badge {
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:6px 10px;
    border-radius:999px;
    font-size:.78rem;
    font-weight:700;
  }

  .access-badge.on {
    background:#198754;
    color:#fff;
  }

  .access-badge.off {
    background:#f8fafc;
    color:#6b7280;
    border:1px solid #e5e7eb;
  }

  .info-soft {
    background: #eff6ff;
    border: 1px solid #dbeafe;
    color: #1e3a8a;
    border-radius: 18px;
    padding: 14px 16px;
  }

  .register-box .form-label {
    font-weight: 700;
    font-size: .92rem;
  }

  .register-box .form-control,
  .register-box .form-check {
    border-radius: 14px;
  }

  .tenant-empty{
    border:1px dashed #cfd8e3;
    background:#fff;
    border-radius:20px;
    padding:42px 18px;
    text-align:center;
  }

  .mini-muted{
    color:#64748b;
    font-size:.9rem;
    font-weight:600;
  }

  #coverMap{
    width:100%;
    min-height:220px;
  }

  .mobile-user-strip{
    display:none;
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

    .sidebar.show{ left:0; }

    .main-area{
      width:100% !important;
      margin-left:0 !important;
    }

    .mobile-user-strip{ display:block; }
    .desktop-user-text{ display:none !important; }
  }
</style>
</head>
<body>

<div class="app-shell">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <?php include 'homeowner_sidebar.php'; ?>

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
            <h2 class="fb-name">My Tenants</h2>
            <div class="fb-sub"><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?></div>
            <div class="mt-2 d-flex gap-2 flex-wrap">
              <span class="pill">🏠 <?= esc($user['house_lot_number'] ?? '') ?></span>
              <span class="pill">👥 <?= (int)$tenantCount ?> active tenant<?= $tenantCount == 1 ? '' : 's' ?></span>
            </div>
          </div>

          <div class="fb-actions">
            <button class="btn btn-hoa" data-bs-toggle="modal" data-bs-target="#registerModal">
              <i class="bi bi-person-plus-fill me-1"></i> Register Tenant
            </button>
          </div>
        </div>
      </div>

      <div class="row g-4 mt-2">
        <div class="col-lg-4">
          <div class="fb-card mb-4">
            <div class="fb-card-h">
              <h6>🏠 Unit Summary</h6>
              <span class="pill"><?= count($tenants) ?> record<?= count($tenants) == 1 ? '' : 's' ?></span>
            </div>
            <div class="fb-card-b">
              <div class="d-flex flex-column gap-2">
                <div class="tenant-stat">
                  <i class="bi bi-house-door-fill text-success"></i>
                  <span><?= esc($user['house_lot_number'] ?? '') ?></span>
                </div>
                <div class="tenant-stat">
                  <i class="bi bi-person-badge-fill text-success"></i>
                  <span><?= (int)$tenantCount ?> Active Tenant<?= $tenantCount == 1 ? '' : 's' ?></span>
                </div>
                <div class="tenant-stat">
                  <i class="bi bi-people-fill text-success"></i>
                  <span><?= count($tenants) ?> Total Registered</span>
                </div>
              </div>
            </div>
          </div>

          <div class="fb-card">
            <div class="fb-card-h"><h6>ℹ️ How access works</h6></div>
            <div class="fb-card-b">
              <div class="info-soft small fw-semibold">
                Each tenant gets their own login. They can only use the modules you allow. You can edit access anytime or deactivate the account whenever needed.
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-8">
          <div class="fb-card mb-4">
            <div class="fb-card-h">
              <h6>👤 Tenant Management</h6>
              <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#registerModal">
                <i class="bi bi-person-plus-fill me-1"></i> Add New
              </button>
            </div>
            <div class="fb-card-b">

              <?php if (empty($tenants)): ?>
                <div class="tenant-empty">
                  <i class="bi bi-people display-5 text-success d-block mb-2"></i>
                  <div class="fw-bold mb-1">No tenants registered yet</div>
                  <div class="mini-muted mb-3">Start by adding a tenant for this unit.</div>
                  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#registerModal">
                    <i class="bi bi-person-plus-fill me-1"></i> Register Tenant
                  </button>
                </div>
              <?php else: ?>
                <div class="row g-3">
                  <?php foreach ($tenants as $t): ?>
                    <?php
                      $tName = trim(($t['first_name'] ?? '') . ' ' . ($t['middle_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
                      $isActive = (($t['status'] ?? '') === 'active');
                      $tenantJson = htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="col-md-6">
                      <div class="tenant-card <?= $isActive ? '' : 'inactive' ?>">
                        <div class="p-3">
                          <div class="d-flex align-items-start gap-3 mb-3">
                            <div class="tenant-avatar">
                              <?= esc(strtoupper(substr($t['first_name'] ?? 'T', 0, 1) . substr($t['last_name'] ?? 'N', 0, 1))) ?>
                            </div>

                            <div class="flex-grow-1 min-w-0">
                              <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                                <div>
                                  <div class="fw-bold"><?= esc($tName) ?></div>
                                  <div class="mini-muted"><?= esc($t['email'] ?? '') ?></div>
                                </div>
                                <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?>">
                                  <?= $isActive ? 'Active' : 'Inactive' ?>
                                </span>
                              </div>
                            </div>
                          </div>

                          <?php if (!empty($t['contact_number'])): ?>
                            <div class="mini-muted mb-2">
                              <i class="bi bi-telephone-fill me-1"></i><?= esc($t['contact_number']) ?>
                            </div>
                          <?php endif; ?>

                          <?php if (!empty($t['lease_start']) || !empty($t['lease_end'])): ?>
                            <div class="mini-muted mb-3">
                              <i class="bi bi-calendar3 me-1"></i>
                              <?= !empty($t['lease_start']) ? esc(date('M d, Y', strtotime($t['lease_start']))) : '—' ?>
                              →
                              <?= !empty($t['lease_end']) ? esc(date('M d, Y', strtotime($t['lease_end']))) : 'Open-ended' ?>
                            </div>
                          <?php endif; ?>

                          <div class="d-flex flex-wrap gap-2 mb-3">
                            <?php foreach ($accessOpts as $col => [$label, $icon, $desc]): ?>
                              <?php $on = !empty($t[$col]); ?>
                              <span class="access-badge <?= $on ? 'on' : 'off' ?>">
                                <i class="bi bi-<?= esc($icon) ?>"></i>
                                <?= esc($label) ?>
                              </span>
                            <?php endforeach; ?>
                          </div>

                          <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-outline-primary btn-sm flex-fill"
                                    onclick="openAccessModal(<?= $tenantJson ?>)">
                              <i class="bi bi-sliders me-1"></i> Edit Access
                            </button>

                            <button class="btn btn-sm <?= $isActive ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                    onclick="toggleStatus(<?= (int)$t['id'] ?>, '<?= $isActive ? 'inactive' : 'active' ?>', this)">
                              <i class="bi bi-<?= $isActive ? 'person-dash' : 'person-check' ?>"></i>
                              <?= $isActive ? 'Deactivate' : 'Activate' ?>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- REGISTER TENANT MODAL -->
<div class="modal fade" id="registerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content rounded-4 shadow register-box">
      <div class="modal-header bg-success text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>Register Tenant</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body px-4 py-4">
        <div class="alert alert-warning small mb-4">
          <i class="bi bi-shield-lock me-1"></i>
          The tenant will use their own email and password. They will only be able to access the modules you enable below.
        </div>

        <form id="tenantForm" enctype="multipart/form-data">
          <input type="hidden" name="action" value="register_tenant">

          <h6 class="fw-semibold text-success mb-3">Personal Information</h6>
          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label class="form-label">First Name <span class="text-danger">*</span></label>
              <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-sm-4">
              <label class="form-label">Middle Name</label>
              <input type="text" name="middle_name" class="form-control">
            </div>
            <div class="col-sm-4">
              <label class="form-label">Last Name <span class="text-danger">*</span></label>
              <input type="text" name="last_name" class="form-control" required>
            </div>

            <div class="col-sm-6">
              <label class="form-label">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label">Contact Number</label>
              <input type="text" name="contact_number" class="form-control" placeholder="09XXXXXXXXX">
            </div>

            <div class="col-sm-6">
              <label class="form-label">Password <span class="text-danger">*</span></label>
              <input type="password" name="password" class="form-control" required minlength="6" placeholder="Minimum 6 characters">
            </div>
            <div class="col-sm-6">
              <label class="form-label">Valid ID <span class="text-muted">(optional)</span></label>
              <input type="file" name="valid_id" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
            </div>
          </div>

          <h6 class="fw-semibold text-success mb-3">Lease Period <span class="text-muted fw-normal">(optional)</span></h6>
          <div class="row g-3 mb-4">
            <div class="col-sm-6">
              <label class="form-label">Lease Start</label>
              <input type="date" name="lease_start" class="form-control">
            </div>
            <div class="col-sm-6">
              <label class="form-label">Lease End</label>
              <input type="date" name="lease_end" class="form-control">
            </div>
          </div>

          <h6 class="fw-semibold text-success mb-2">Module Access</h6>
          <p class="text-muted small mb-3">Choose which HOA features this tenant can use.</p>

          <div class="row g-2 mb-3">
            <?php foreach ($accessOpts as $name => [$label, $icon, $desc]): ?>
              <div class="col-sm-6">
                <div class="form-check border rounded-4 p-3 h-100">
                  <input class="form-check-input" type="checkbox" name="<?= esc($name) ?>" id="reg_<?= esc($name) ?>" checked>
                  <label class="form-check-label" for="reg_<?= esc($name) ?>">
                    <i class="bi bi-<?= esc($icon) ?> text-success me-1"></i>
                    <strong><?= esc($label) ?></strong>
                    <small class="d-block text-muted"><?= esc($desc) ?></small>
                  </label>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="error-msg text-danger small d-none"></div>
        </form>
      </div>

      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success px-4" id="registerBtn" onclick="submitRegister()">
          <i class="bi bi-person-plus-fill me-1"></i> Register Tenant
        </button>
      </div>
    </div>
  </div>
</div>

<!-- EDIT ACCESS MODAL -->
<div class="modal fade" id="accessModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4 shadow">
      <div class="modal-header bg-primary text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-sliders me-2"></i>Edit Tenant Access</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body px-4 py-3">
        <p class="text-muted small mb-3">Updating access for: <strong id="accessTenantName"></strong></p>
        <input type="hidden" id="accessTenantId">

        <?php foreach ($accessOpts as $name => [$label, $icon, $desc]): ?>
          <div class="form-check border rounded-4 p-3 mb-2">
            <input class="form-check-input" type="checkbox" id="acc_<?= esc($name) ?>" data-key="<?= esc($name) ?>">
            <label class="form-check-label" for="acc_<?= esc($name) ?>">
              <i class="bi bi-<?= esc($icon) ?> text-primary me-1"></i>
              <strong><?= esc($label) ?></strong>
              <small class="d-block text-muted"><?= esc($desc) ?></small>
            </label>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary px-4" onclick="submitAccess()">
          <i class="bi bi-check-lg me-1"></i> Save Access
        </button>
      </div>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:9999">
  <div id="sbToast" class="toast align-items-center border-0 shadow" role="alert">
    <div class="d-flex">
      <div class="toast-body" id="sbToastMsg"></div>
      <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const SELF = 'homeowner_tenant_register.php';

function showToast(msg, ok = true) {
  const el = document.getElementById('sbToast');
  document.getElementById('sbToastMsg').textContent = msg;
  el.classList.remove('bg-success', 'text-white', 'bg-danger');
  el.classList.add(ok ? 'bg-success' : 'bg-danger');
  if (ok) el.classList.add('text-white');
  new bootstrap.Toast(el, { delay: 3200 }).show();
}

async function submitRegister() {
  const btn  = document.getElementById('registerBtn');
  const form = document.getElementById('tenantForm');
  const err  = form.querySelector('.error-msg');

  btn.disabled = true;
  err.classList.add('d-none');
  err.textContent = '';

  const fd = new FormData(form);

  ['can_pay_dues', 'can_rent', 'can_parking', 'can_announcements'].forEach(k => {
    fd.set(k, form.elements[k] && form.elements[k].checked ? '1' : '0');
  });

  try {
    const res = await fetch(SELF, { method: 'POST', body: fd });
    const data = await res.json();

    if (data.ok) {
      showToast(data.msg, true);
      bootstrap.Modal.getInstance(document.getElementById('registerModal')).hide();
      setTimeout(() => location.reload(), 1000);
    } else {
      err.textContent = data.msg || 'Failed to register tenant.';
      err.classList.remove('d-none');
    }
  } catch (e) {
    err.textContent = 'An error occurred. Please try again.';
    err.classList.remove('d-none');
  } finally {
    btn.disabled = false;
  }
}

async function toggleStatus(tid, newStatus, btn) {
  if (!confirm(`Are you sure you want to ${newStatus === 'inactive' ? 'deactivate' : 'activate'} this tenant?`)) {
    return;
  }

  btn.disabled = true;

  const fd = new FormData();
  fd.append('action', 'toggle_status');
  fd.append('tenant_id', tid);
  fd.append('new_status', newStatus);

  try {
    const res = await fetch(SELF, { method: 'POST', body: fd });
    const data = await res.json();

    if (data.ok) {
      showToast(data.msg || 'Status updated.', true);
      setTimeout(() => location.reload(), 900);
    } else {
      showToast(data.msg || 'Failed to update status.', false);
      btn.disabled = false;
    }
  } catch (e) {
    showToast('An error occurred while updating status.', false);
    btn.disabled = false;
  }
}

function openAccessModal(tenant) {
  document.getElementById('accessTenantId').value = tenant.id;
  document.getElementById('accessTenantName').textContent =
    ((tenant.first_name || '') + ' ' + (tenant.last_name || '')).trim();

  ['can_pay_dues', 'can_rent', 'can_parking', 'can_announcements'].forEach(k => {
    const cb = document.getElementById('acc_' + k);
    if (cb) cb.checked = parseInt(tenant[k] || 0, 10) === 1;
  });

  new bootstrap.Modal(document.getElementById('accessModal')).show();
}

async function submitAccess() {
  const fd = new FormData();
  fd.append('action', 'update_access');
  fd.append('tenant_id', document.getElementById('accessTenantId').value);

  ['can_pay_dues', 'can_rent', 'can_parking', 'can_announcements'].forEach(k => {
    fd.append(k, document.getElementById('acc_' + k).checked ? '1' : '0');
  });

  try {
    const res = await fetch(SELF, { method: 'POST', body: fd });
    const data = await res.json();

    if (data.ok) {
      showToast(data.msg || 'Access updated successfully.', true);
      bootstrap.Modal.getInstance(document.getElementById('accessModal')).hide();
      setTimeout(() => location.reload(), 900);
    } else {
      showToast(data.msg || 'Failed to update access.', false);
    }
  } catch (e) {
    showToast('An error occurred while updating access.', false);
  }
}

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
  window.addEventListener('resize', () => setTimeout(() => map.invalidateSize(), 200));
})();

// Sidebar dropdowns
(function(){
  const parkingWrap = document.getElementById('sbParking');
  const parkingBtn  = document.getElementById('sbParkingToggle');
  if (parkingWrap && parkingBtn) {
    parkingBtn.addEventListener('click', () => parkingWrap.classList.toggle('open'));
  }

  const tenantWrap = document.getElementById('sbTenant');
  const tenantBtn  = document.getElementById('sbTenantToggle');
  if (tenantWrap && tenantBtn) {
    tenantBtn.addEventListener('click', () => tenantWrap.classList.toggle('open'));
  }
})();

// Mobile sidebar
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
</script>

<script>
// ── Generic dropdown toggle ────────────────────────────────────────────────────
function initSbDropdown(toggleId, containerId) {
  const toggle = document.getElementById(toggleId);
  const container = document.getElementById(containerId);
  if (!toggle || !container) return;
  toggle.addEventListener('click', () => {
    container.classList.toggle('open');
  });
}

document.addEventListener('DOMContentLoaded', function () {
  initSbDropdown('sbParkingToggle', 'sbParking');
  initSbDropdown('sbTenantToggle',  'sbTenant');
});
</script>
</body>
</html>