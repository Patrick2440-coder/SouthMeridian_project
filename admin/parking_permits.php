<?php
session_start();

// ===================== AUTH GUARD =====================
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
  exit;
}
if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
  echo "<script>alert('Superadmin cannot access President Dashboard.'); window.location='index.php';</script>";
  exit;
}

// ===================== DB =====================
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$adminId   = (int)$_SESSION['admin_id'];
$adminRole = (string)$_SESSION['admin_role'];

$stmt = $conn->prepare("SELECT email, full_name, phase, role FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc() ?: ['email'=>'','full_name'=>'','phase'=>'Phase 1','role'=>$adminRole];
$stmt->close();

$adminEmail = (string)($me['email'] ?? '');
$adminName  = trim((string)($me['full_name'] ?? ''));
$myPhase    = (string)($me['phase'] ?? 'Phase 1');

$allowedPhases = ['Phase 1','Phase 2','Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';

function phase_code(string $phase): string {
  return $phase === 'Phase 1' ? 'P1' : ($phase === 'Phase 2' ? 'P2' : ($phase === 'Phase 3' ? 'P3' : 'PX'));
}
function next_permit_no(mysqli $conn, string $phase): string {
  $prefix = phase_code($phase) . "-";
  $stmt = $conn->prepare("
    SELECT permit_no
    FROM parking_permits
    WHERE phase=? AND permit_no IS NOT NULL AND permit_no LIKE CONCAT(?, '%')
    ORDER BY id DESC
    LIMIT 1
  ");
  $stmt->bind_param("ss", $phase, $prefix);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $n = 0;
  if ($row && !empty($row['permit_no'])) {
    $parts = explode("-", (string)$row['permit_no']);
    $last = end($parts);
    if (ctype_digit($last)) $n = (int)$last;
  }
  $n++;
  return $prefix . str_pad((string)$n, 6, "0", STR_PAD_LEFT);
}

$flash = "";
$flashType = "success";
function fail_flash(&$flash, &$flashType, string $msg){ $flash=$msg; $flashType="danger"; }

// ===================== ACTIONS =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'approve') {
    $id = (int)($_POST['id'] ?? 0);
    $valid_from  = (string)($_POST['valid_from'] ?? '');
    $valid_until = (string)($_POST['valid_until'] ?? '');

    if ($id <= 0 || $valid_from === '' || $valid_until === '') {
      fail_flash($flash, $flashType, "Invalid approve request.");
    } else {
      // pull pending + requirements
      $stmt = $conn->prepare("
        SELECT id,
               application_form_path, proof_of_residency_path, or_cr_path,
               proof_parking_space_path, proof_of_payment_path,
               drivers_license_path, deed_of_sale_path
        FROM parking_permits
        WHERE id=? AND phase=? AND status='pending'
        LIMIT 1
      ");
      $stmt->bind_param("is", $id, $phase);
      $stmt->execute();
      $p = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (!$p) {
        fail_flash($flash, $flashType, "Permit request not found or already processed.");
      } else {
        // REQUIRED docs (Deed of Sale is optional — admin may require based on case)
        $missing = [];
        if (empty($p['application_form_path']))     $missing[] = "Application Form";
        if (empty($p['proof_of_residency_path']))   $missing[] = "Proof of Residency";
        if (empty($p['or_cr_path']))                $missing[] = "Vehicle OR/CR";
        if (empty($p['proof_parking_space_path']))  $missing[] = "Proof of Parking Space";
        if (empty($p['proof_of_payment_path']))     $missing[] = "Proof of Payment (Dues/Cedula)";
        if (empty($p['drivers_license_path']))      $missing[] = "Driver’s License";

        if ($missing) {
          fail_flash($flash, $flashType, "Cannot approve. Missing requirements: " . implode(", ", $missing));
        } else {
          $permitNo = next_permit_no($conn, $phase);
          $stmt = $conn->prepare("
            UPDATE parking_permits
            SET status='active',
                permit_no=?,
                valid_from=?,
                valid_until=?,
                approved_by_admin_id=?,
                approved_at=NOW(),
                rejected_reason=NULL,
                revoked_reason=NULL
            WHERE id=? AND phase=? AND status='pending'
          ");
          $stmt->bind_param("sssiis", $permitNo, $valid_from, $valid_until, $adminId, $id, $phase);
          $stmt->execute();
          $stmt->close();
          $flash = "Permit approved and issued (#{$permitNo}).";
        }
      }
    }
  }

  if ($action === 'reject') {
    $id = (int)($_POST['id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($id <= 0 || $reason === '') {
      fail_flash($flash, $flashType, "Reject reason is required.");
    } else {
      $stmt = $conn->prepare("
        UPDATE parking_permits
        SET status='rejected',
            rejected_reason=?,
            approved_by_admin_id=?,
            approved_at=NOW()
        WHERE id=? AND phase=? AND status='pending'
      ");
      $stmt->bind_param("siis", $reason, $adminId, $id, $phase);
      $stmt->execute();
      if ($stmt->affected_rows <= 0) fail_flash($flash, $flashType, "Permit request not found or already processed.");
      $stmt->close();
      if ($flashType !== "danger") $flash = "Permit request rejected.";
    }
  }

  if ($action === 'revoke') {
    $id = (int)($_POST['id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($id <= 0 || $reason === '') {
      fail_flash($flash, $flashType, "Revoke reason is required.");
    } else {
      $stmt = $conn->prepare("
        UPDATE parking_permits
        SET status='revoked',
            revoked_reason=?,
            approved_by_admin_id=?,
            approved_at=NOW()
        WHERE id=? AND phase=? AND status='active'
      ");
      $stmt->bind_param("siis", $reason, $adminId, $id, $phase);
      $stmt->execute();
      if ($stmt->affected_rows <= 0) fail_flash($flash, $flashType, "Active permit not found.");
      $stmt->close();
      if ($flashType !== "danger") $flash = "Permit revoked.";
    }
  }

  if ($action === 'renew') {
    $id = (int)($_POST['id'] ?? 0);
    $valid_until = (string)($_POST['valid_until'] ?? '');
    if ($id <= 0 || $valid_until === '') {
      fail_flash($flash, $flashType, "New valid-until date is required.");
    } else {
      $stmt = $conn->prepare("
        UPDATE parking_permits
        SET valid_until=?,
            approved_by_admin_id=?,
            approved_at=NOW()
        WHERE id=? AND phase=? AND status='active'
      ");
      $stmt->bind_param("siis", $valid_until, $adminId, $id, $phase);
      $stmt->execute();
      if ($stmt->affected_rows <= 0) fail_flash($flash, $flashType, "Active permit not found.");
      $stmt->close();
      if ($flashType !== "danger") $flash = "Permit renewed (valid until {$valid_until}).";
    }
  }
}

// Auto-expire (optional)
$conn->query("
  UPDATE parking_permits
  SET status='expired'
  WHERE phase='{$conn->real_escape_string($phase)}'
    AND status='active'
    AND valid_until IS NOT NULL
    AND valid_until < CURDATE()
");

// ===================== DATA LOAD =====================
$stmt = $conn->prepare("
  SELECT p.*,
         h.first_name, h.middle_name, h.last_name, h.house_lot_number, h.email AS ho_email
  FROM parking_permits p
  JOIN homeowners h ON h.id=p.homeowner_id
  WHERE p.phase=? AND p.status='pending'
  ORDER BY p.requested_at DESC
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$pendingRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
  SELECT p.*,
         h.first_name, h.middle_name, h.last_name, h.house_lot_number, h.email AS ho_email
  FROM parking_permits p
  JOIN homeowners h ON h.id=p.homeowner_id
  WHERE p.phase=? AND p.status='active'
  ORDER BY p.approved_at DESC, p.requested_at DESC
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$activeRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
  SELECT p.*,
         h.first_name, h.middle_name, h.last_name, h.house_lot_number, h.email AS ho_email
  FROM parking_permits p
  JOIN homeowners h ON h.id=p.homeowner_id
  WHERE p.phase=?
  ORDER BY FIELD(p.status,'pending','active','expired','revoked','rejected'), p.updated_at DESC
  LIMIT 500
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$allRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function file_badge($path){
  return $path ? '<span class="badge badge-success">Submitted</span>' : '<span class="badge badge-danger">Missing</span>';
}

?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>HOA-ADMIN | Parking Permits</title>

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
    .badge-soft { padding:.35rem .6rem; border-radius:999px; font-weight:800; font-size:12px; display:inline-block; }
    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-danger  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .badge-soft-info    { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }
    .req-list li{ margin-bottom:6px; }
    .req-note{ font-size:12px; color:#64748b; }
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
            <span class="user-name"><?= esc(strtoupper($adminName !== '' ? $adminName : ($adminEmail ?: 'ADMIN'))) ?></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="logout.php"><i class="dw dw-logout"></i> Log Out</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="left-side-bar" style="background-color:#077f46;">
    <div class="brand-logo">
      <a href="dashboard.php">
        <img src="vendors/images/deskapp-logo.svg" alt="" class="dark-logo">
        <img src="vendors/images/deskapp-logo-white.svg" alt="" class="light-logo">
      </a>
      <div class="close-sidebar" data-toggle="left-sidebar-close">
        <i class="ion-close-round"></i>
      </div>
    </div>
    <div class="menu-block customscroll">
      <div class="sidebar-menu">
        <ul id="accordion-menu">
          <li><a href="dashboard.php" class="dropdown-toggle no-arrow"><span class="micon dw dw-house-1"></span><span class="mtext">Dashboard</span></a></li>

          <li class="dropdown">
            <a href="javascript:;" class="dropdown-toggle active"><span class="micon dw dw-car"></span><span class="mtext">Parking</span></a>
            <ul class="submenu">
              <li><a href="parking.php">Parking Overview</a></li>
              <li><a class="active" href="parking_permits.php">Manage Permits</a></li>
              <li><a href="parking_violations.php">View Violations</a></li>
            </ul>
          </li>

          <li><a href="#" class="dropdown-toggle no-arrow"><span class="micon dw dw-settings2"></span><span class="mtext">Settings</span></a></li>
        </ul>
      </div>
    </div>
  </div>

  <div class="mobile-menu-overlay"></div>

  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header mb-20">
        <div class="row">
          <div class="col-md-12 col-sm-12">
            <div class="title"><h4>Parking Permits / Stickers</h4></div>
            <div class="text-secondary">Phase: <b><?= esc($phase) ?></b></div>
          </div>
        </div>
      </div>

      <?php if ($flash !== ''): ?>
        <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flash) ?></div>
      <?php endif; ?>

      <!-- REQUIREMENTS CARD -->
      <div class="card-box mb-30 p-3">
        <h5 class="mb-2">Requirements for Yearly Parking Stickers/Permits</h5>
        <ul class="req-list mb-2">
          <li><b>Accomplished Application Form</b> (from barangay hall/security office)</li>
          <li><b>Proof of Residency</b> (Barangay Clearance / Certificate of Residency)</li>
          <li><b>Vehicle Documents</b> (clear photocopy of <b>OR</b> and <b>CR</b>)</li>
          <li><b>Proof of Parking Space</b> (photo of garage / affidavit; no street parking)</li>
          <li><b>Proof of Payment</b> (latest association dues / Cedula)</li>
          <li><b>Driver’s License</b> (photocopy)</li>
          <li><b>Deed of Sale</b> (required if OR/CR not yet updated to current owner)</li>
        </ul>
        <div class="req-note">
          Note: Admin approval requires all required attachments above. Deed of Sale may be requested depending on the case.
        </div>
      </div>

      <div class="card-box mb-30 p-3">
        <ul class="nav nav-tabs" role="tablist">
          <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tabPending" role="tab">Pending Requests (<?= count($pendingRows) ?>)</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabActive" role="tab">Active Permits (<?= count($activeRows) ?>)</a></li>
          <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabAll" role="tab">All Permits</a></li>
        </ul>

        <div class="tab-content pt-3">
          <!-- PENDING -->
          <div class="tab-pane fade show active" id="tabPending" role="tabpanel">
            <div class="table-responsive">
              <table id="tblPending" class="table table-striped table-hover">
                <thead class="table-light">
                  <tr>
                    <th>ID</th>
                    <th>Homeowner</th>
                    <th>Blk/Lot</th>
                    <th>Plate</th>
                    <th>Status</th>
                    <th>Requested</th>
                    <th class="text-center">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($pendingRows as $r): ?>
                    <?php
                      $name = trim(($r['first_name'] ?? '').' '.($r['middle_name'] ?? '').' '.($r['last_name'] ?? ''));
                      $veh  = trim(($r['vehicle_make'] ?? '').' '.($r['vehicle_model'] ?? '').' '.($r['vehicle_color'] ?? ''));
                      $missingCount = 0;
                      foreach (['application_form_path','proof_of_residency_path','or_cr_path','proof_parking_space_path','proof_of_payment_path','drivers_license_path'] as $k){
                        if (empty($r[$k])) $missingCount++;
                      }
                    ?>
                    <tr>
                      <td><?= (int)$r['id'] ?></td>
                      <td>
                        <?= esc($name) ?>
                        <div class="text-secondary" style="font-size:12px;"><?= esc($veh) ?></div>
                      </td>
                      <td><?= esc($r['house_lot_number'] ?? '') ?></td>
                      <td><?= esc($r['plate_no'] ?? '') ?></td>
                      <td>
                        <span class="badge-soft badge-soft-warning">pending</span>
                        <?php if ($missingCount>0): ?>
                          <div class="text-danger" style="font-size:12px;">Missing: <?= (int)$missingCount ?></div>
                        <?php else: ?>
                          <div class="text-success" style="font-size:12px;">Complete</div>
                        <?php endif; ?>
                      </td>
                      <td><?= esc($r['requested_at'] ?? '') ?></td>
                      <td class="text-center">
                        <button class="btn btn-sm btn-outline-primary btnReq"
                          data-json='<?= esc(json_encode([
                            "Application Form" => $r['application_form_path'] ?? "",
                            "Proof of Residency" => $r['proof_of_residency_path'] ?? "",
                            "Vehicle OR/CR" => $r['or_cr_path'] ?? "",
                            "Proof of Parking Space" => $r['proof_parking_space_path'] ?? "",
                            "Proof of Payment (Dues/Cedula)" => $r['proof_of_payment_path'] ?? "",
                            "Driver’s License" => $r['drivers_license_path'] ?? "",
                            "Deed of Sale (if needed)" => $r['deed_of_sale_path'] ?? "",
                          ], JSON_UNESCAPED_SLASHES)) ?>'
                          data-name="<?= esc($name) ?>"
                          data-plate="<?= esc($r['plate_no'] ?? '') ?>"
                        ><i class="dw dw-file"></i> Requirements</button>

                        <button class="btn btn-sm btn-success btnApprove"
                          data-id="<?= (int)$r['id'] ?>"
                          data-name="<?= esc($name) ?>"
                          data-plate="<?= esc($r['plate_no'] ?? '') ?>"
                        ><i class="dw dw-check"></i> Approve</button>

                        <button class="btn btn-sm btn-danger btnReject"
                          data-id="<?= (int)$r['id'] ?>"
                          data-name="<?= esc($name) ?>"
                          data-plate="<?= esc($r['plate_no'] ?? '') ?>"
                        ><i class="dw dw-delete-3"></i> Reject</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$pendingRows): ?>
                    <tr><td colspan="7" class="text-center text-secondary">No pending permit requests.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- ACTIVE -->
          <div class="tab-pane fade" id="tabActive" role="tabpanel">
            <div class="table-responsive">
              <table id="tblActive" class="table table-striped table-hover">
                <thead class="table-light">
                  <tr>
                    <th>ID</th>
                    <th>Permit No</th>
                    <th>Homeowner</th>
                    <th>Plate</th>
                    <th>Validity</th>
                    <th class="text-center">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($activeRows as $r): ?>
                    <?php
                      $name = trim(($r['first_name'] ?? '').' '.($r['middle_name'] ?? '').' '.($r['last_name'] ?? ''));
                      $valid = trim((string)($r['valid_from'] ?? '')).' → '.trim((string)($r['valid_until'] ?? ''));
                    ?>
                    <tr>
                      <td><?= (int)$r['id'] ?></td>
                      <td><span class="badge-soft badge-soft-info"><?= esc($r['permit_no'] ?? '—') ?></span></td>
                      <td><?= esc($name) ?></td>
                      <td><?= esc($r['plate_no'] ?? '') ?></td>
                      <td><?= esc($valid) ?></td>
                      <td class="text-center">
                        <button class="btn btn-sm btn-outline-primary btnRenew"
                          data-id="<?= (int)$r['id'] ?>"
                          data-permit="<?= esc($r['permit_no'] ?? '') ?>"
                          data-until="<?= esc($r['valid_until'] ?? '') ?>"
                        ><i class="dw dw-refresh"></i> Renew</button>

                        <button class="btn btn-sm btn-outline-danger btnRevoke"
                          data-id="<?= (int)$r['id'] ?>"
                          data-permit="<?= esc($r['permit_no'] ?? '') ?>"
                          data-plate="<?= esc($r['plate_no'] ?? '') ?>"
                        ><i class="dw dw-ban"></i> Revoke</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$activeRows): ?>
                    <tr><td colspan="6" class="text-center text-secondary">No active permits.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- ALL -->
          <div class="tab-pane fade" id="tabAll" role="tabpanel">
            <div class="table-responsive">
              <table id="tblAll" class="table table-striped table-hover">
                <thead class="table-light">
                  <tr>
                    <th>ID</th>
                    <th>Permit No</th>
                    <th>Homeowner</th>
                    <th>Plate</th>
                    <th>Status</th>
                    <th>Validity</th>
                    <th>Updated</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($allRows as $r): ?>
                    <?php
                      $name = trim(($r['first_name'] ?? '').' '.($r['middle_name'] ?? '').' '.($r['last_name'] ?? ''));
                      $st = (string)($r['status'] ?? 'pending');
                      $badge = 'badge-soft-info';
                      if ($st==='pending') $badge='badge-soft-warning';
                      if ($st==='active') $badge='badge-soft-success';
                      if (in_array($st,['expired','revoked','rejected'],true)) $badge='badge-soft-danger';
                      $valid = trim((string)($r['valid_from'] ?? '')).' → '.trim((string)($r['valid_until'] ?? ''));
                    ?>
                    <tr>
                      <td><?= (int)$r['id'] ?></td>
                      <td><?= esc($r['permit_no'] ?? '—') ?></td>
                      <td><?= esc($name) ?></td>
                      <td><?= esc($r['plate_no'] ?? '') ?></td>
                      <td><span class="badge-soft <?= esc($badge) ?>"><?= esc($st) ?></span></td>
                      <td><?= esc($valid) ?></td>
                      <td><?= esc($r['updated_at'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (!$allRows): ?>
                    <tr><td colspan="7" class="text-center text-secondary">No permits found.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div>
      </div>

      <div class="footer-wrap pd-20 mb-20 card-box">
        © Copyright South Meridian Homes All Rights Reserved
      </div>

    </div>
  </div>

  <!-- REQUIREMENTS MODAL -->
  <div class="modal fade" id="modalReq" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Submitted Requirements</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="text-secondary mb-2" id="reqInfo"></div>
          <div id="reqList"></div>
          <div class="alert alert-warning mt-3 mb-0">
            Deed of Sale is only required if OR/CR is not yet updated to the current owner.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- APPROVE MODAL -->
  <div class="modal fade" id="modalApprove" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form method="POST" class="modal-content">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="id" id="approveId">
        <div class="modal-header">
          <h5 class="modal-title">Approve Permit</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="text-secondary mb-2" id="approveInfo"></div>
          <div class="form-group">
            <label>Valid From</label>
            <input type="date" name="valid_from" class="form-control" required>
          </div>
          <div class="form-group">
            <label>Valid Until</label>
            <input type="date" name="valid_until" class="form-control" required>
          </div>
          <div class="alert alert-info mb-0">Permit number will be auto-generated per phase.</div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">Approve & Issue</button>
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- REJECT MODAL -->
  <div class="modal fade" id="modalReject" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form method="POST" class="modal-content">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="id" id="rejectId">
        <div class="modal-header">
          <h5 class="modal-title">Reject Permit Request</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="text-secondary mb-2" id="rejectInfo"></div>
          <div class="form-group">
            <label>Reason</label>
            <input type="text" name="reason" class="form-control" required maxlength="255">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-danger">Reject</button>
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- REVOKE MODAL -->
  <div class="modal fade" id="modalRevoke" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form method="POST" class="modal-content">
        <input type="hidden" name="action" value="revoke">
        <input type="hidden" name="id" id="revokeId">
        <div class="modal-header">
          <h5 class="modal-title">Revoke Permit</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="text-secondary mb-2" id="revokeInfo"></div>
          <div class="form-group">
            <label>Reason</label>
            <input type="text" name="reason" class="form-control" required maxlength="255">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-outline-danger">Revoke</button>
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- RENEW MODAL -->
  <div class="modal fade" id="modalRenew" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <form method="POST" class="modal-content">
        <input type="hidden" name="action" value="renew">
        <input type="hidden" name="id" id="renewId">
        <div class="modal-header">
          <h5 class="modal-title">Renew Permit</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
        </div>
        <div class="modal-body">
          <div class="text-secondary mb-2" id="renewInfo"></div>
          <div class="form-group">
            <label>New Valid Until</label>
            <input type="date" name="valid_until" id="renewUntil" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-outline-primary">Renew</button>
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
        </div>
      </form>
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
    $(function(){
      $('#tblPending').DataTable({ responsive:true, pageLength:10, order:[], columnDefs:[{orderable:false, targets:6}] });
      $('#tblActive').DataTable({ responsive:true, pageLength:10, order:[], columnDefs:[{orderable:false, targets:5}] });
      $('#tblAll').DataTable({ responsive:true, pageLength:10, order:[] });

      $(document).on('click', '.btnReq', function(){
        const name = $(this).data('name');
        const plate = $(this).data('plate');
        $('#reqInfo').text(`Homeowner: ${name} • Plate: ${plate}`);

        let data = {};
        try { data = JSON.parse($(this).attr('data-json')); } catch(e){ data = {}; }

        let html = '<div class="table-responsive"><table class="table table-sm">';
        html += '<thead><tr><th>Requirement</th><th>Status</th><th>File</th></tr></thead><tbody>';

        Object.keys(data).forEach(k => {
          const p = data[k] || '';
          const status = p ? '<span class="badge badge-success">Submitted</span>' : '<span class="badge badge-danger">Missing</span>';
          const link = p ? `<a href="${p}" target="_blank">View</a>` : '—';
          html += `<tr><td>${k}</td><td>${status}</td><td>${link}</td></tr>`;
        });

        html += '</tbody></table></div>';
        $('#reqList').html(html);
        $('#modalReq').modal('show');
      });

      $(document).on('click', '.btnApprove', function(){
        $('#approveId').val($(this).data('id'));
        $('#approveInfo').text(`Homeowner: ${$(this).data('name')} • Plate: ${$(this).data('plate')}`);
        $('#modalApprove').modal('show');
      });

      $(document).on('click', '.btnReject', function(){
        $('#rejectId').val($(this).data('id'));
        $('#rejectInfo').text(`Homeowner: ${$(this).data('name')} • Plate: ${$(this).data('plate')}`);
        $('#modalReject').modal('show');
      });

      $(document).on('click', '.btnRevoke', function(){
        $('#revokeId').val($(this).data('id'));
        $('#revokeInfo').text(`Permit: ${$(this).data('permit')} • Plate: ${$(this).data('plate')}`);
        $('#modalRevoke').modal('show');
      });

      $(document).on('click', '.btnRenew', function(){
        $('#renewId').val($(this).data('id'));
        $('#renewInfo').text(`Permit: ${$(this).data('permit')}`);
        $('#renewUntil').val($(this).data('until'));
        $('#modalRenew').modal('show');
      });
    });
  </script>

</body>
</html>
