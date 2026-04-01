<?php
session_start();
require_once 'admin_access.php';
requireAccess('user_management');

/* =========================
   DB
   ========================= */
$host = "localhost";
$db   = "u972459197_south_meridian";
$user = "u972459197_patrick";
$pass = "Idle2440";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

/* =========================
   HELPERS
   ========================= */
function esc($v){
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function redirect_with_message(string $type, string $message, string $location = 'staff_management.php'){
  $_SESSION['flash_type'] = $type;
  $_SESSION['flash_message'] = $message;
  header("Location: " . $location);
  exit;
}

function uploaded_file_path(string $field, string $prefix): ?string {
  if (empty($_FILES[$field]['name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
    return null;
  }

  $dirAbs = __DIR__ . '/uploads/staff/';
  $dirRel = 'uploads/staff/';

  if (!is_dir($dirAbs)) {
    if (!mkdir($dirAbs, 0777, true) && !is_dir($dirAbs)) {
      return null;
    }
  }

  $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
  $ext = preg_replace('/[^a-z0-9]/i', '', $ext);
  if ($ext === '') $ext = 'dat';

  $name = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $abs  = $dirAbs . $name;

  if (move_uploaded_file($_FILES[$field]['tmp_name'], $abs)) {
    return $dirRel . $name;
  }

  return null;
}

/* =========================
   ADMIN INFO
   ========================= */
$adminId = (int)($_SESSION['admin_id'] ?? 0);

$stmt = $conn->prepare("SELECT full_name, email, phase, position FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin) {
  session_destroy();
  echo "<script>alert('Session expired. Please login again.'); window.location='index.php';</script>";
  exit;
}

$adminName     = trim((string)($admin['full_name'] ?? ''));
$adminEmail    = trim((string)($admin['email'] ?? ''));
$myPhase       = trim((string)($admin['phase'] ?? 'Phase 1'));
$adminPosition = trim((string)($admin['position'] ?? ''));
$isPresident   = ($adminPosition === 'President');

/* =========================
   POST ACTIONS
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['action'] ?? ''));

  if ($action === 'apply_staff') {
    $phase         = $myPhase;
    $staffType     = trim((string)($_POST['staff_type'] ?? 'Guard'));
    $sourceType    = trim((string)($_POST['source_type'] ?? 'homeowner'));
    $homeownerId   = !empty($_POST['homeowner_id']) ? (int)$_POST['homeowner_id'] : null;
    $fullName      = trim((string)($_POST['full_name'] ?? ''));
    $email         = trim((string)($_POST['email'] ?? ''));
    $contactNumber = trim((string)($_POST['contact_number'] ?? ''));
    $address       = trim((string)($_POST['address'] ?? ''));
    $positionTitle = trim((string)($_POST['position_title'] ?? ''));
    $notes         = trim((string)($_POST['notes'] ?? ''));

    $allowedStaffTypes  = ['Guard', 'Volunteer', 'Other'];
    $allowedSourceTypes = ['homeowner', 'non_resident'];

    if (!in_array($staffType, $allowedStaffTypes, true)) $staffType = 'Guard';
    if (!in_array($sourceType, $allowedSourceTypes, true)) $sourceType = 'homeowner';

    if ($sourceType === 'homeowner') {
      if (!$homeownerId) {
        redirect_with_message('danger', 'Please select a homeowner.');
      }

      $stmt = $conn->prepare("
        SELECT id, first_name, middle_name, last_name, email, contact_number, house_lot_number
        FROM homeowners
        WHERE id=? AND phase=? AND status='approved'
        LIMIT 1
      ");
      $stmt->bind_param("is", $homeownerId, $phase);
      $stmt->execute();
      $ho = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (!$ho) {
        redirect_with_message('danger', 'Selected homeowner was not found or not approved.');
      }

      $fullName = trim(
        (string)($ho['first_name'] ?? '') . ' ' .
        (string)($ho['middle_name'] ?? '') . ' ' .
        (string)($ho['last_name'] ?? '')
      );

      if ($email === '') $email = (string)($ho['email'] ?? '');
      if ($contactNumber === '') $contactNumber = (string)($ho['contact_number'] ?? '');
      if ($address === '') $address = (string)($ho['house_lot_number'] ?? '');
    } else {
      $homeownerId = null;
      if ($fullName === '') {
        redirect_with_message('danger', 'Full name is required for non-resident staff.');
      }
    }

    $validIdPath = uploaded_file_path('valid_id', 'staff_id');
    $resumePath  = uploaded_file_path('resume_file', 'staff_resume');
    $photoPath   = uploaded_file_path('photo_file', 'staff_photo');

    $stmt = $conn->prepare("
      INSERT INTO staff_applications
      (
        phase, staff_type, source_type, homeowner_id,
        full_name, email, contact_number, address,
        position_title, notes, valid_id_path, resume_path, photo_path,
        status, applied_by_admin_id
      )
      VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");
    $stmt->bind_param(
      "sssisssssssssi",
      $phase,
      $staffType,
      $sourceType,
      $homeownerId,
      $fullName,
      $email,
      $contactNumber,
      $address,
      $positionTitle,
      $notes,
      $validIdPath,
      $resumePath,
      $photoPath,
      $adminId
    );

    if (!$stmt->execute()) {
      $stmt->close();
      redirect_with_message('danger', 'Failed to submit staff application.');
    }
    $stmt->close();

    redirect_with_message('success', 'Staff application submitted for president approval.');
  }

  if ($action === 'approve_staff') {
    if (!$isPresident) {
      redirect_with_message('danger', 'Only the President can approve staff applications.');
    }

    $applicationId = (int)($_POST['application_id'] ?? 0);
    $remarks       = trim((string)($_POST['remarks'] ?? ''));

    $stmt = $conn->prepare("
      SELECT *
      FROM staff_applications
      WHERE id=? AND phase=? AND status='pending'
      LIMIT 1
    ");
    $stmt->bind_param("is", $applicationId, $myPhase);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$app) {
      redirect_with_message('danger', 'Application not found or already processed.');
    }

    $conn->begin_transaction();

    try {
      $stmt = $conn->prepare("
        UPDATE staff_applications
        SET status='approved',
            president_admin_id=?,
            president_action_at=NOW(),
            president_remarks=?
        WHERE id=?
        LIMIT 1
      ");
      $stmt->bind_param("isi", $adminId, $remarks, $applicationId);
      $stmt->execute();
      $stmt->close();

      $stmt = $conn->prepare("
        INSERT INTO staff_members
        (
          application_id, phase, staff_type, source_type, homeowner_id,
          full_name, email, contact_number, address, position_title,
          approved_by_admin_id, approved_at, is_active
        )
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
      ");
      $stmt->bind_param(
        "isssisssssi",
        $applicationId,
        $app['phase'],
        $app['staff_type'],
        $app['source_type'],
        $app['homeowner_id'],
        $app['full_name'],
        $app['email'],
        $app['contact_number'],
        $app['address'],
        $app['position_title'],
        $adminId
      );
      $stmt->execute();
      $stmt->close();

      $conn->commit();
      redirect_with_message('success', 'Staff application approved successfully.');
    } catch (Throwable $e) {
      $conn->rollback();
      redirect_with_message('danger', 'Failed to approve staff application.');
    }
  }

  if ($action === 'reject_staff') {
    if (!$isPresident) {
      redirect_with_message('danger', 'Only the President can reject staff applications.');
    }

    $applicationId = (int)($_POST['application_id'] ?? 0);
    $remarks       = trim((string)($_POST['remarks'] ?? ''));

    $stmt = $conn->prepare("
      UPDATE staff_applications
      SET status='rejected',
          president_admin_id=?,
          president_action_at=NOW(),
          president_remarks=?
      WHERE id=? AND phase=? AND status='pending'
      LIMIT 1
    ");
    $stmt->bind_param("isis", $adminId, $remarks, $applicationId, $myPhase);

    if (!$stmt->execute()) {
      $stmt->close();
      redirect_with_message('danger', 'Failed to reject application.');
    }
    $stmt->close();

    redirect_with_message('success', 'Staff application rejected.');
  }

  if ($action === 'toggle_staff_active') {
    if (!$isPresident) {
      redirect_with_message('danger', 'Only the President can update staff status.');
    }

    $staffId   = (int)($_POST['staff_id'] ?? 0);
    $newActive = (int)($_POST['new_active'] ?? 0) ? 1 : 0;

    $stmt = $conn->prepare("
      UPDATE staff_members
      SET is_active=?
      WHERE id=? AND phase=?
      LIMIT 1
    ");
    $stmt->bind_param("iis", $newActive, $staffId, $myPhase);

    if (!$stmt->execute()) {
      $stmt->close();
      redirect_with_message('danger', 'Failed to update staff status.');
    }
    $stmt->close();

    redirect_with_message('success', 'Staff status updated.');
  }
}

/* =========================
   FLASH
   ========================= */
$flashType = $_SESSION['flash_type'] ?? '';
$flashMsg  = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_type'], $_SESSION['flash_message']);

/* =========================
   HOMEOWNER OPTIONS
   ========================= */
$homeownerOptions = [];
$stmt = $conn->prepare("
  SELECT id, public_id, first_name, middle_name, last_name, email, contact_number, house_lot_number
  FROM homeowners
  WHERE phase=? AND status='approved'
  ORDER BY first_name ASC, last_name ASC
");
$stmt->bind_param("s", $myPhase);
$stmt->execute();
$homeownerOptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* =========================
   KPI
   ========================= */
$stmt = $conn->prepare("SELECT COUNT(*) c FROM staff_applications WHERE phase=? AND status='pending'");
$stmt->bind_param("s", $myPhase);
$stmt->execute();
$pendingCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM staff_members WHERE phase=? AND is_active=1");
$stmt->bind_param("s", $myPhase);
$stmt->execute();
$activeCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM staff_members WHERE phase=? AND is_active=1 AND staff_type='Guard'");
$stmt->bind_param("s", $myPhase);
$stmt->execute();
$guardCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM staff_members WHERE phase=? AND is_active=1 AND staff_type='Volunteer'");
$stmt->bind_param("s", $myPhase);
$stmt->execute();
$volunteerCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* =========================
   TABLE DATA
   ========================= */
$pendingApps = [];
$stmt = $conn->prepare("
  SELECT sa.*, a.full_name AS applied_by_name, a.email AS applied_by_email
  FROM staff_applications sa
  LEFT JOIN admins a ON a.id = sa.applied_by_admin_id
  WHERE sa.phase=? AND sa.status='pending'
  ORDER BY sa.created_at DESC, sa.id DESC
");
$stmt->bind_param("s", $myPhase);
$stmt->execute();
$pendingApps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$approvedStaff = [];
$stmt = $conn->prepare("
  SELECT sm.*, sa.notes, sa.valid_id_path, sa.resume_path, sa.photo_path,
         a.full_name AS approved_by_name, a.email AS approved_by_email
  FROM staff_members sm
  LEFT JOIN staff_applications sa ON sa.id = sm.application_id
  LEFT JOIN admins a ON a.id = sm.approved_by_admin_id
  WHERE sm.phase=?
  ORDER BY sm.is_active DESC, sm.created_at DESC, sm.id DESC
");
$stmt->bind_param("s", $myPhase);
$stmt->execute();
$approvedStaff = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$rejectedApps = [];
$stmt = $conn->prepare("
  SELECT sa.*, a.full_name AS applied_by_name, a.email AS applied_by_email,
         p.full_name AS president_name, p.email AS president_email
  FROM staff_applications sa
  LEFT JOIN admins a ON a.id = sa.applied_by_admin_id
  LEFT JOIN admins p ON p.id = sa.president_admin_id
  WHERE sa.phase=? AND sa.status='rejected'
  ORDER BY sa.president_action_at DESC, sa.id DESC
");
$stmt->bind_param("s", $myPhase);
$stmt->execute();
$rejectedApps = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>HOA-ADMIN | Staff Management</title>

  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

  <style>
    .kpi-card .icon { font-size: 28px; opacity: .9; }
    .kpi-value { font-size: 28px; font-weight: 800; }
    .kpi-label { color: #64748b; font-weight: 700; }

    .badge-soft { padding: .35rem .6rem; border-radius: 999px; font-weight: 800; font-size: 12px; display:inline-block; }
    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }
    .badge-soft-secondary { background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; }
    .badge-soft-danger { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }

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
      width: min(980px, 96vw);
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
      position: sticky;
      top: 0;
      background: #fff;
      z-index: 2;
    }

    .modalx .closebtn {
      border: none;
      background: transparent;
      font-size: 22px;
      cursor: pointer;
      line-height: 1;
    }

    .staff-card {
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      padding: 14px;
      background: #fff;
    }

    .staff-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0,1fr));
      gap: 12px;
    }

    .mini-muted {
      color: #64748b;
      font-size: 12px;
    }

    .photo-preview {
      width: 100%;
      max-width: 230px;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      object-fit: cover;
    }

    .doc-link {
      display: inline-block;
      margin: 2px 8px 2px 0;
      font-weight: 700;
      word-break: break-word;
    }

    .action-group {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 6px;
    }

    .table-responsive {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }

    .table td, .table th {
      vertical-align: middle;
    }

    .wrap-cell {
      white-space: normal !important;
      word-break: break-word;
    }

    .top-action-bar {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .mobile-full-btn {
      min-width: 180px;
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

    @media (max-width: 991.98px) {
      .staff-grid {
        grid-template-columns: 1fr;
      }

      .top-action-bar {
        justify-content: stretch;
      }

      .mobile-full-btn {
        width: 100%;
      }
    }

    @media (max-width: 767.98px) {
      .page-header .title h4 {
        font-size: 22px;
      }

      .card-box {
        padding: 15px !important;
      }

      .kpi-value {
        font-size: 24px;
      }

      .modalx {
        padding: 10px;
      }

      .modalx .box {
        width: 100%;
        max-height: 95vh;
        border-radius: 12px;
      }

      .modalx .boxhead {
        padding: 12px 14px;
      }

      .table td, .table th {
        font-size: 12px;
        white-space: nowrap;
      }

      .action-group {
        flex-direction: column;
        align-items: stretch;
      }

      .action-group .btn,
      .action-group form,
      .action-group form .btn {
        width: 100%;
      }

      .text-right {
        text-align: left !important;
      }

      .text-right .btn {
        width: 100%;
        margin-top: 8px;
      }

      .photo-preview {
        max-width: 100%;
      }

      .mini-muted {
        font-size: 11px;
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
            <div class="title"><h4>Staff Management</h4></div>
            <div class="text-secondary">
              Phase: <b><?= esc($myPhase) ?></b> |
              Logged in as: <b><?= esc($adminName !== '' ? $adminName : $adminEmail) ?></b>
              <?php if ($adminPosition !== ''): ?>
                <span class="text-muted">(<?= esc($adminPosition) ?>)</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?= esc($flashType === 'success' ? 'success' : 'danger') ?> alert-dismissible fade show" role="alert">
          <?= esc($flashMsg) ?>
          <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      <?php endif; ?>

      <div class="row">
        <div class="col-lg-8 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p">
            <div class="row align-items-center">
              <div class="col-md-4"><img src="vendors/images/banner-img.png" alt="" style="max-width:100%;height:auto;"></div>
              <div class="col-md-8">
                <h4 class="font-20 weight-500 mb-10 text-capitalize">
                  <div class="weight-600 font-30 text-blue">South Meridian Staff</div>
                </h4>
                <p class="font-18 max-width-600">
                  Add guards, volunteers, and other staff under <b><?= esc($myPhase) ?></b>.
                  Applications are submitted by admins and must be approved by the <b>President</b> before becoming official staff.
                </p>
                <div class="top-action-bar">
                  <button type="button" class="btn btn-success mobile-full-btn" id="openApplyModal">
                    <i class="dw dw-add-user"></i> Apply Staff
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-4 col-md-12 mb-30">
          <div class="card-box pd-20 height-100-p">
            <h4 class="h5 mb-15">Approval Rule</h4>
            <div class="mb-2"><span class="badge-soft badge-soft-warning">Pending</span> admin submitted application</div>
            <div class="mb-2"><span class="badge-soft badge-soft-success">Approved</span> official staff member</div>
            <div><span class="badge-soft badge-soft-danger">Rejected</span> declined by president</div>
            <hr>
            <div class="mini-muted">
              Only the <b>President</b> of the phase can approve, reject, activate, or deactivate staff.
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Pending Applications</div>
                <div class="kpi-value"><?= (int)$pendingCount ?></div>
              </div>
              <div class="icon text-warning"><i class="dw dw-clock"></i></div>
            </div>
            <div class="mt-2 text-secondary">Waiting for president approval</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Active Staff</div>
                <div class="kpi-value"><?= (int)$activeCount ?></div>
              </div>
              <div class="icon text-success"><i class="dw dw-user-13"></i></div>
            </div>
            <div class="mt-2 text-secondary">Official active staff members</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Active Guards</div>
                <div class="kpi-value"><?= (int)$guardCount ?></div>
              </div>
              <div class="icon text-info"><i class="dw dw-shield"></i></div>
            </div>
            <div class="mt-2 text-secondary">Approved guard staff</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Active Volunteers</div>
                <div class="kpi-value"><?= (int)$volunteerCount ?></div>
              </div>
              <div class="icon text-primary"><i class="dw dw-heart"></i></div>
            </div>
            <div class="mt-2 text-secondary">Approved volunteers</div>
          </div>
        </div>
      </div>

      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
          <h5 class="mb-0">Pending Staff Applications</h5>
          <span class="text-secondary">Phase: <b><?= esc($myPhase) ?></b></span>
        </div>

        <div class="table-responsive">
          <table id="pendingTable" class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Type</th>
                <th>Source</th>
                <th>Contact</th>
                <th>Applied By</th>
                <th>Created</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($pendingApps)): ?>
                <?php foreach ($pendingApps as $r): ?>
                  <?php
                    $appliedBy = trim((string)($r['applied_by_name'] ?? ''));
                    if ($appliedBy === '') $appliedBy = (string)($r['applied_by_email'] ?? 'Unknown');
                    $sourceLabel = ((string)$r['source_type'] === 'non_resident') ? 'NON-RESIDENT' : 'HOMEOWNER';
                  ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td class="wrap-cell"><?= esc((string)$r['full_name']) ?></td>
                    <td><span class="badge-soft badge-soft-info"><?= esc((string)$r['staff_type']) ?></span></td>
                    <td><span class="badge-soft badge-soft-secondary"><?= esc($sourceLabel) ?></span></td>
                    <td><?= esc((string)($r['contact_number'] ?? '')) ?></td>
                    <td class="wrap-cell"><?= esc($appliedBy) ?></td>
                    <td><?= esc((string)$r['created_at']) ?></td>
                    <td class="text-center">
                      <div class="action-group">
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-primary viewStaffBtn"
                          data-payload='<?= esc(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)) ?>'>
                          <i class="dw dw-eye"></i>
                        </button>

                        <?php if ($isPresident): ?>
                          <button
                            type="button"
                            class="btn btn-sm btn-success approveBtn"
                            data-id="<?= (int)$r['id'] ?>"
                            data-name="<?= esc((string)$r['full_name']) ?>">
                            Approve
                          </button>
                          <button
                            type="button"
                            class="btn btn-sm btn-danger rejectBtn"
                            data-id="<?= (int)$r['id'] ?>"
                            data-name="<?= esc((string)$r['full_name']) ?>">
                            Reject
                          </button>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="8" class="text-center text-secondary">No pending staff applications.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
          <h5 class="mb-0">Official Staff Members</h5>
          <span class="text-secondary">Approved list</span>
        </div>

        <div class="table-responsive">
          <table id="approvedTable" class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Type</th>
                <th>Source</th>
                <th>Position</th>
                <th>Contact</th>
                <th>Status</th>
                <th>Approved At</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($approvedStaff)): ?>
                <?php foreach ($approvedStaff as $r): ?>
                  <?php
                    $statusBadge = ((int)$r['is_active'] === 1) ? 'badge-soft-success' : 'badge-soft-secondary';
                    $statusText  = ((int)$r['is_active'] === 1) ? 'active' : 'inactive';
                    $sourceLabel = ((string)$r['source_type'] === 'non_resident') ? 'NON-RESIDENT' : 'HOMEOWNER';
                  ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td class="wrap-cell"><?= esc((string)$r['full_name']) ?></td>
                    <td><span class="badge-soft badge-soft-info"><?= esc((string)$r['staff_type']) ?></span></td>
                    <td><span class="badge-soft badge-soft-secondary"><?= esc($sourceLabel) ?></span></td>
                    <td class="wrap-cell"><?= esc((string)($r['position_title'] ?? '')) ?></td>
                    <td><?= esc((string)($r['contact_number'] ?? '')) ?></td>
                    <td><span class="badge-soft <?= esc($statusBadge) ?>"><?= esc($statusText) ?></span></td>
                    <td><?= esc((string)($r['approved_at'] ?? '')) ?></td>
                    <td class="text-center">
                      <div class="action-group">
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-primary viewOfficialStaffBtn"
                          data-payload='<?= esc(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)) ?>'>
                          <i class="dw dw-eye"></i>
                        </button>

                        <?php if ($isPresident): ?>
                          <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="toggle_staff_active">
                            <input type="hidden" name="staff_id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="new_active" value="<?= ((int)$r['is_active'] === 1 ? 0 : 1) ?>">
                            <button type="submit" class="btn btn-sm <?= ((int)$r['is_active'] === 1 ? 'btn-warning' : 'btn-success') ?>">
                              <?= ((int)$r['is_active'] === 1 ? 'Deactivate' : 'Activate') ?>
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="9" class="text-center text-secondary">No approved staff members yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card-box mb-30 p-3">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
          <h5 class="mb-0">Rejected Staff Applications</h5>
          <span class="text-secondary">History</span>
        </div>

        <div class="table-responsive">
          <table id="rejectedTable" class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Type</th>
                <th>Source</th>
                <th>Applied By</th>
                <th>Rejected At</th>
                <th>Remarks</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($rejectedApps)): ?>
                <?php foreach ($rejectedApps as $r): ?>
                  <?php
                    $appliedBy = trim((string)($r['applied_by_name'] ?? ''));
                    if ($appliedBy === '') $appliedBy = (string)($r['applied_by_email'] ?? 'Unknown');
                    $sourceLabel = ((string)$r['source_type'] === 'non_resident') ? 'NON-RESIDENT' : 'HOMEOWNER';
                  ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td class="wrap-cell"><?= esc((string)$r['full_name']) ?></td>
                    <td><span class="badge-soft badge-soft-info"><?= esc((string)$r['staff_type']) ?></span></td>
                    <td><span class="badge-soft badge-soft-secondary"><?= esc($sourceLabel) ?></span></td>
                    <td class="wrap-cell"><?= esc($appliedBy) ?></td>
                    <td><?= esc((string)($r['president_action_at'] ?? '')) ?></td>
                    <td class="wrap-cell"><?= esc((string)($r['president_remarks'] ?? '')) ?></td>
                    <td class="text-center">
                      <div class="action-group">
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-primary viewStaffBtn"
                          data-payload='<?= esc(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)) ?>'>
                          <i class="dw dw-eye"></i>
                        </button>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="8" class="text-center text-secondary">No rejected staff applications.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="footer-wrap pd-20 mb-20 card-box">
        © Copyright South Meridian Homes All Rights Reserved
      </div>
    </div>
  </div>

  <div class="modalx" id="applyModal">
    <div class="box">
      <div class="boxhead">
        <div class="font-weight-bold">Apply Staff</div>
        <button class="closebtn" type="button" id="closeApplyModal">&times;</button>
      </div>

      <div class="p-3">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="apply_staff">

          <div class="staff-grid">
            <div class="form-group">
              <label><b>Staff Type</b></label>
              <select name="staff_type" id="staff_type" class="form-control" required>
                <option value="Guard">Guard</option>
                <option value="Volunteer">Volunteer</option>
                <option value="Other">Other</option>
              </select>
            </div>

            <div class="form-group">
              <label><b>Source Type</b></label>
              <select name="source_type" id="source_type" class="form-control" required>
                <option value="homeowner">Homeowner</option>
                <option value="non_resident">Non-Resident</option>
              </select>
            </div>

            <div class="form-group" id="homeownerWrap">
              <label><b>Select Homeowner</b></label>
              <select name="homeowner_id" id="homeowner_id" class="form-control">
                <option value="">-- Select homeowner --</option>
                <?php foreach ($homeownerOptions as $h): ?>
                  <?php
                    $full = trim(
                      (string)($h['first_name'] ?? '') . ' ' .
                      (string)($h['middle_name'] ?? '') . ' ' .
                      (string)($h['last_name'] ?? '')
                    );
                  ?>
                  <option
                    value="<?= (int)$h['id'] ?>"
                    data-name="<?= esc($full) ?>"
                    data-email="<?= esc((string)($h['email'] ?? '')) ?>"
                    data-contact="<?= esc((string)($h['contact_number'] ?? '')) ?>"
                    data-address="<?= esc((string)($h['house_lot_number'] ?? '')) ?>">
                    <?= esc(((string)($h['public_id'] ?? 'ID#'.$h['id'])) . ' - ' . $full) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="mini-muted mt-1">Choose an approved homeowner from this phase.</div>
            </div>

            <div class="form-group">
              <label><b>Full Name</b></label>
              <input type="text" name="full_name" id="full_name" class="form-control" placeholder="Enter full name">
            </div>

            <div class="form-group">
              <label><b>Email</b></label>
              <input type="email" name="email" id="email" class="form-control" placeholder="Enter email">
            </div>

            <div class="form-group">
              <label><b>Contact Number</b></label>
              <input type="text" name="contact_number" id="contact_number" class="form-control" placeholder="Enter contact number">
            </div>

            <div class="form-group">
              <label><b>Address / House-Lot</b></label>
              <input type="text" name="address" id="address" class="form-control" placeholder="Enter address">
            </div>

            <div class="form-group">
              <label><b>Position Title</b></label>
              <input type="text" name="position_title" class="form-control" placeholder="e.g. Main Gate Guard">
            </div>

            <div class="form-group">
              <label><b>Valid ID</b></label>
              <input type="file" name="valid_id" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
            </div>

            <div class="form-group">
              <label><b>Resume</b></label>
              <input type="file" name="resume_file" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
            </div>

            <div class="form-group">
              <label><b>Photo</b></label>
              <input type="file" name="photo_file" class="form-control" accept=".jpg,.jpeg,.png">
            </div>
          </div>

          <div class="form-group">
            <label><b>Notes</b></label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes"></textarea>
          </div>

          <div class="text-right">
            <button type="button" class="btn btn-secondary" id="cancelApplyBtn">Cancel</button>
            <button type="submit" class="btn btn-success">Submit Application</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modalx" id="viewModal">
    <div class="box" style="width:min(900px, 96vw);">
      <div class="boxhead">
        <div class="font-weight-bold">Staff Details</div>
        <button class="closebtn" type="button" id="closeViewModal">&times;</button>
      </div>
      <div id="viewModalBody" class="p-3">
        <div class="text-secondary">Loading...</div>
      </div>
    </div>
  </div>

  <div class="modalx" id="approveModal">
    <div class="box" style="width:min(520px, 96vw);">
      <div class="boxhead">
        <div class="font-weight-bold">Approve Staff Application</div>
        <button class="closebtn" type="button" id="closeApproveModal">&times;</button>
      </div>
      <div class="p-3">
        <form method="post">
          <input type="hidden" name="action" value="approve_staff">
          <input type="hidden" name="application_id" id="approve_application_id" value="">
          <div class="mb-2">
            Approve application for <b id="approve_staff_name">—</b>?
          </div>
          <div class="form-group">
            <label><b>Remarks</b></label>
            <textarea name="remarks" class="form-control" rows="3" placeholder="Optional remarks"></textarea>
          </div>
          <div class="text-right">
            <button type="button" class="btn btn-secondary" id="cancelApproveBtn">Cancel</button>
            <button type="submit" class="btn btn-success">Approve</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modalx" id="rejectModal">
    <div class="box" style="width:min(520px, 96vw);">
      <div class="boxhead">
        <div class="font-weight-bold">Reject Staff Application</div>
        <button class="closebtn" type="button" id="closeRejectModal">&times;</button>
      </div>
      <div class="p-3">
        <form method="post">
          <input type="hidden" name="action" value="reject_staff">
          <input type="hidden" name="application_id" id="reject_application_id" value="">
          <div class="mb-2">
            Reject application for <b id="reject_staff_name">—</b>?
          </div>
          <div class="form-group">
            <label><b>Remarks</b></label>
            <textarea name="remarks" class="form-control" rows="3" placeholder="Reason for rejection"></textarea>
          </div>
          <div class="text-right">
            <button type="button" class="btn btn-secondary" id="cancelRejectBtn">Cancel</button>
            <button type="submit" class="btn btn-danger">Reject</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="vendors/scripts/core.js"></script>
  <script src="vendors/scripts/script.min.js"></script>
  <script src="vendors/scripts/process.js"></script>
  <script src="vendors/scripts/layout-settings.js"></script>

  <script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
  <script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
  <script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
  <script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

  <script>
    $(document).ready(function () {
      $('#pendingTable').DataTable({
        responsive: false,
        pageLength: 10,
        order: [[0, 'desc']]
      });

      $('#approvedTable').DataTable({
        responsive: false,
        pageLength: 10,
        order: [[0, 'desc']]
      });

      $('#rejectedTable').DataTable({
        responsive: false,
        pageLength: 10,
        order: [[0, 'desc']]
      });
    });

    function escapeHtml(str) {
      return String(str ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    function safeFileLink(path, label) {
      path = String(path || '').trim();
      if (!path) return '';
      const safePath = escapeHtml(path);
      const safeLabel = escapeHtml(label);
      return `<a class="doc-link text-primary" href="${safePath}" target="_blank">${safeLabel}</a>`;
    }

    const applyModal = document.getElementById('applyModal');
    const openApplyModal = document.getElementById('openApplyModal');
    const closeApplyModal = document.getElementById('closeApplyModal');
    const cancelApplyBtn = document.getElementById('cancelApplyBtn');

    function showApplyModal(){ applyModal.style.display = 'flex'; }
    function hideApplyModal(){ applyModal.style.display = 'none'; }

    if (openApplyModal) openApplyModal.addEventListener('click', showApplyModal);
    if (closeApplyModal) closeApplyModal.addEventListener('click', hideApplyModal);
    if (cancelApplyBtn) cancelApplyBtn.addEventListener('click', hideApplyModal);
    if (applyModal) applyModal.addEventListener('click', function(e){ if (e.target === applyModal) hideApplyModal(); });

    const sourceType = document.getElementById('source_type');
    const homeownerWrap = document.getElementById('homeownerWrap');
    const homeownerSelect = document.getElementById('homeowner_id');
    const fullNameInput = document.getElementById('full_name');
    const emailInput = document.getElementById('email');
    const contactInput = document.getElementById('contact_number');
    const addressInput = document.getElementById('address');

    function updateSourceFields() {
      const isHomeowner = sourceType.value === 'homeowner';
      homeownerWrap.style.display = isHomeowner ? '' : 'none';
      homeownerSelect.required = isHomeowner;
      fullNameInput.readOnly = isHomeowner;
      if (!isHomeowner) homeownerSelect.value = '';
    }

    function fillFromHomeowner() {
      const opt = homeownerSelect.options[homeownerSelect.selectedIndex];
      if (!opt || !opt.value) {
        fullNameInput.value = '';
        emailInput.value = '';
        contactInput.value = '';
        addressInput.value = '';
        return;
      }

      fullNameInput.value = opt.dataset.name || '';
      emailInput.value = opt.dataset.email || '';
      contactInput.value = opt.dataset.contact || '';
      addressInput.value = opt.dataset.address || '';
    }

    if (sourceType) {
      sourceType.addEventListener('change', updateSourceFields);
      updateSourceFields();
    }

    if (homeownerSelect) {
      homeownerSelect.addEventListener('change', fillFromHomeowner);
    }

    const viewModal = document.getElementById('viewModal');
    const viewModalBody = document.getElementById('viewModalBody');
    const closeViewModal = document.getElementById('closeViewModal');

    function openView(data) {
      const photo = data.photo_path
        ? `<div class="mb-3"><img src="${escapeHtml(data.photo_path)}" class="photo-preview" alt="Photo"></div>`
        : '';

      const docs = [
        safeFileLink(data.valid_id_path, 'Open Valid ID'),
        safeFileLink(data.resume_path, 'Open Resume'),
        safeFileLink(data.photo_path, 'Open Photo')
      ].join('');

      const sourceLabel = (data.source_type || '') === 'non_resident' ? 'NON-RESIDENT' : 'HOMEOWNER';

      viewModalBody.innerHTML = `
        <div class="staff-card">
          ${photo}
          <div class="row">
            <div class="col-md-6 mb-2"><span class="mini-muted">Full Name</span><br><b>${escapeHtml(data.full_name || '')}</b></div>
            <div class="col-md-6 mb-2"><span class="mini-muted">Staff Type</span><br><b>${escapeHtml(data.staff_type || '')}</b></div>
            <div class="col-md-6 mb-2"><span class="mini-muted">Source Type</span><br><b>${escapeHtml(sourceLabel)}</b></div>
            <div class="col-md-6 mb-2"><span class="mini-muted">Email</span><br><b>${escapeHtml(data.email || '')}</b></div>
            <div class="col-md-6 mb-2"><span class="mini-muted">Contact Number</span><br><b>${escapeHtml(data.contact_number || '')}</b></div>
            <div class="col-md-6 mb-2"><span class="mini-muted">Address</span><br><b>${escapeHtml(data.address || '')}</b></div>
            <div class="col-md-6 mb-2"><span class="mini-muted">Position Title</span><br><b>${escapeHtml(data.position_title || '')}</b></div>
            <div class="col-md-6 mb-2"><span class="mini-muted">Status</span><br><b>${escapeHtml(data.status || (data.is_active == 1 ? 'active' : 'inactive'))}</b></div>
            <div class="col-md-6 mb-2"><span class="mini-muted">Created / Applied At</span><br><b>${escapeHtml(data.created_at || '')}</b></div>
            <div class="col-md-6 mb-2"><span class="mini-muted">President Action At</span><br><b>${escapeHtml(data.president_action_at || data.approved_at || '')}</b></div>
          </div>
          <hr>
          <div class="mb-2"><span class="mini-muted">Notes</span><br><div style="white-space:pre-wrap;word-break:break-word;">${escapeHtml(data.notes || '—')}</div></div>
          <div class="mb-2"><span class="mini-muted">Files</span><br>${docs || '—'}</div>
          <div class="mb-2"><span class="mini-muted">President Remarks</span><br><div style="white-space:pre-wrap;word-break:break-word;">${escapeHtml(data.president_remarks || '—')}</div></div>
        </div>
      `;
      viewModal.style.display = 'flex';
    }

    if (closeViewModal) {
      closeViewModal.addEventListener('click', function(){
        viewModal.style.display = 'none';
      });
    }

    if (viewModal) {
      viewModal.addEventListener('click', function(e){
        if (e.target === viewModal) viewModal.style.display = 'none';
      });
    }

    document.querySelectorAll('.viewStaffBtn, .viewOfficialStaffBtn').forEach(btn => {
      btn.addEventListener('click', function(){
        let payload = {};
        try {
          payload = JSON.parse(this.dataset.payload || '{}');
        } catch (e) {}
        openView(payload);
      });
    });

    const approveModal = document.getElementById('approveModal');
    const closeApproveModal = document.getElementById('closeApproveModal');
    const cancelApproveBtn = document.getElementById('cancelApproveBtn');
    const approveApplicationId = document.getElementById('approve_application_id');
    const approveStaffName = document.getElementById('approve_staff_name');

    document.querySelectorAll('.approveBtn').forEach(btn => {
      btn.addEventListener('click', function(){
        approveApplicationId.value = this.dataset.id || '';
        approveStaffName.textContent = this.dataset.name || '—';
        approveModal.style.display = 'flex';
      });
    });

    function hideApproveModal(){ approveModal.style.display = 'none'; }
    if (closeApproveModal) closeApproveModal.addEventListener('click', hideApproveModal);
    if (cancelApproveBtn) cancelApproveBtn.addEventListener('click', hideApproveModal);
    if (approveModal) approveModal.addEventListener('click', function(e){ if (e.target === approveModal) hideApproveModal(); });

    const rejectModal = document.getElementById('rejectModal');
    const closeRejectModal = document.getElementById('closeRejectModal');
    const cancelRejectBtn = document.getElementById('cancelRejectBtn');
    const rejectApplicationId = document.getElementById('reject_application_id');
    const rejectStaffName = document.getElementById('reject_staff_name');

    document.querySelectorAll('.rejectBtn').forEach(btn => {
      btn.addEventListener('click', function(){
        rejectApplicationId.value = this.dataset.id || '';
        rejectStaffName.textContent = this.dataset.name || '—';
        rejectModal.style.display = 'flex';
      });
    });

    function hideRejectModal(){ rejectModal.style.display = 'none'; }
    if (closeRejectModal) closeRejectModal.addEventListener('click', hideRejectModal);
    if (cancelRejectBtn) cancelRejectBtn.addEventListener('click', hideRejectModal);
    if (rejectModal) rejectModal.addEventListener('click', function(e){ if (e.target === rejectModal) hideRejectModal(); });
  </script>

  <div id="accessToast" class="access-toast">
    🚫 You do not have access to that part.
  </div>

  <script>
    window.userPermissions = <?= json_encode($permissions ?? []) ?>;

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