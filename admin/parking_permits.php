<?php
session_start();
require_once 'admin_access.php';
requireAccess('parking');

// ===================== AUTH GUARD =====================
if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)
) {
    echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
    exit;
}
if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
    echo "<script>alert('Superadmin cannot access this module.'); window.location='index.php';</script>";
    exit;
}

// ===================== CSRF =====================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===================== DB =====================
$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function phase_code(string $phase): string
{
    return $phase === 'Phase 1' ? 'P1' : ($phase === 'Phase 2' ? 'P2' : ($phase === 'Phase 3' ? 'P3' : 'PX'));
}

function next_permit_no(mysqli $conn, string $phase): string
{
    $prefix = phase_code($phase) . "-";

    $stmt = $conn->prepare("
        SELECT permit_no
        FROM parking_permits
        WHERE phase = ?
          AND permit_no IS NOT NULL
          AND permit_no LIKE CONCAT(?, '%')
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
        if (ctype_digit((string)$last)) {
            $n = (int)$last;
        }
    }

    $n++;
    return $prefix . str_pad((string)$n, 3, "0", STR_PAD_LEFT);
}

function fail_flash(&$flash, &$flashType, string $msg): void
{
    $flash = $msg;
    $flashType = "danger";
}

function is_image_file(string $path): bool
{
    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
}

$flash = "";
$flashType = "success";

$adminId   = (int)$_SESSION['admin_id'];
$adminRole = (string)$_SESSION['admin_role'];

$stmt = $conn->prepare("SELECT email, full_name, phase, role FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc() ?: ['email' => '', 'full_name' => '', 'phase' => 'Phase 1', 'role' => $adminRole];
$stmt->close();

$adminEmail = (string)($me['email'] ?? '');
$adminName  = trim((string)($me['full_name'] ?? ''));
$myPhase    = (string)($me['phase'] ?? 'Phase 1');

$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';

// ===================== ACTIONS =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        fail_flash($flash, $flashType, "Invalid request token.");
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'approve') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                fail_flash($flash, $flashType, "Invalid approve request.");
            } else {
                $stmt = $conn->prepare("
                    SELECT
                        id,
                        payment_status,
                        vehicle_front_path,
                        vehicle_back_path
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
                    $missing = [];
                    if (empty($p['vehicle_front_path'])) $missing[] = "Vehicle Front Picture";
                    if (empty($p['vehicle_back_path'])) $missing[] = "Vehicle Back Picture";

                    if ($missing) {
                        fail_flash($flash, $flashType, "Cannot approve. Missing requirements: " . implode(", ", $missing));
                    } else {
                        $currentPaymentStatus = strtolower((string)($p['payment_status'] ?? 'unpaid'));
                        $nextPaymentStatus = $currentPaymentStatus;

                        if (in_array($currentPaymentStatus, ['', 'unpaid', 'not paid'], true)) {
                            $nextPaymentStatus = 'for payment';
                        }

                        $stmt = $conn->prepare("
                            UPDATE parking_permits
                            SET payment_status=?,
                                approved_by_admin_id=?,
                                approved_at=NOW(),
                                rejected_reason=NULL
                            WHERE id=? AND phase=? AND status='pending'
                        ");
                        $stmt->bind_param("siis", $nextPaymentStatus, $adminId, $id, $phase);
                        $stmt->execute();

                        if ($stmt->affected_rows <= 0) {
                            fail_flash($flash, $flashType, "Approval failed.");
                        } else {
                            $flash = "Requirements approved. Homeowner/Tenant may now proceed to payment.";
                            $flashType = "success";
                        }
                        $stmt->close();
                    }
                }
            }
        }

        if ($action === 'activate') {
            $id = (int)($_POST['id'] ?? 0);
            $valid_from  = (string)($_POST['valid_from'] ?? '');
            $valid_until = (string)($_POST['valid_until'] ?? '');

            if ($id <= 0 || $valid_from === '' || $valid_until === '') {
                fail_flash($flash, $flashType, "Invalid activation request.");
            } elseif ($valid_until < $valid_from) {
                fail_flash($flash, $flashType, "Valid Until cannot be earlier than Valid From.");
            } else {
                $conn->begin_transaction();

                try {
                    $stmt = $conn->prepare("
                        SELECT
                            id,
                            payment_status,
                            payment_method,
                            permit_no
                        FROM parking_permits
                        WHERE id=? AND phase=? AND status='pending'
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $stmt->bind_param("is", $id, $phase);
                    $stmt->execute();
                    $p = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$p) {
                        throw new Exception("Permit request not found or already processed.");
                    }

                    if (strtolower((string)($p['payment_status'] ?? 'unpaid')) !== 'paid') {
                        throw new Exception("Cannot activate. Payment is not yet marked as paid.");
                    }

                    $permitNo = !empty($p['permit_no']) ? (string)$p['permit_no'] : next_permit_no($conn, $phase);

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

                    if ($stmt->affected_rows <= 0) {
                        $stmt->close();
                        throw new Exception("Activation failed.");
                    }
                    $stmt->close();

                    $conn->commit();
                    $flash = "Permit activated and issued (#{$permitNo}).";
                    $flashType = "success";
                } catch (Throwable $e) {
                    $conn->rollback();
                    fail_flash($flash, $flashType, $e->getMessage());
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

                if ($stmt->affected_rows <= 0) {
                    fail_flash($flash, $flashType, "Permit request not found or already processed.");
                } else {
                    $flash = "Permit request rejected.";
                    $flashType = "success";
                }
                $stmt->close();
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

                if ($stmt->affected_rows <= 0) {
                    fail_flash($flash, $flashType, "Active permit not found.");
                } else {
                    $flash = "Permit revoked.";
                    $flashType = "success";
                }
                $stmt->close();
            }
        }

        if ($action === 'renew') {
            $id = (int)($_POST['id'] ?? 0);
            $valid_until = (string)($_POST['valid_until'] ?? '');

            if ($id <= 0 || $valid_until === '') {
                fail_flash($flash, $flashType, "New valid-until date is required.");
            } else {
                $stmt = $conn->prepare("
                    SELECT valid_from
                    FROM parking_permits
                    WHERE id=? AND phase=? AND status='active'
                    LIMIT 1
                ");
                $stmt->bind_param("is", $id, $phase);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$existing) {
                    fail_flash($flash, $flashType, "Active permit not found.");
                } elseif (!empty($existing['valid_from']) && $valid_until < $existing['valid_from']) {
                    fail_flash($flash, $flashType, "New valid-until date cannot be earlier than valid-from date.");
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

                    if ($stmt->affected_rows <= 0) {
                        fail_flash($flash, $flashType, "Active permit not found or no changes made.");
                    } else {
                        $flash = "Permit renewed (valid until {$valid_until}).";
                        $flashType = "success";
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// ===================== AUTO-EXPIRE =====================
$stmt = $conn->prepare("
    UPDATE parking_permits
    SET status='expired'
    WHERE phase=?
      AND status='active'
      AND valid_until IS NOT NULL
      AND valid_until < CURDATE()
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$stmt->close();

// ===================== DATA LOAD =====================
$stmt = $conn->prepare("
    SELECT
        p.*,
        h.first_name, h.middle_name, h.last_name, h.house_lot_number, h.email AS ho_email
    FROM parking_permits p
    JOIN homeowners h ON h.id = p.homeowner_id
    WHERE p.phase=? AND p.status='pending'
    ORDER BY p.requested_at DESC
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$pendingRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
    SELECT
        p.*,
        h.first_name, h.middle_name, h.last_name, h.house_lot_number, h.email AS ho_email
    FROM parking_permits p
    JOIN homeowners h ON h.id = p.homeowner_id
    WHERE p.phase=? AND p.status='active'
    ORDER BY p.approved_at DESC, p.requested_at DESC
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$activeRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
    SELECT
        p.*,
        h.first_name, h.middle_name, h.last_name, h.house_lot_number, h.email AS ho_email
    FROM parking_permits p
    JOIN homeowners h ON h.id = p.homeowner_id
    WHERE p.phase=?
    ORDER BY FIELD(p.status,'pending','active','expired','revoked','rejected'), p.updated_at DESC
    LIMIT 500
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$allRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
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
    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

    <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">

    <style>
        .badge-soft { padding: .35rem .6rem; border-radius: 999px; font-weight: 800; font-size: 12px; display: inline-block; }
        .badge-soft-warning { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; }
        .badge-soft-success { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; }
        .badge-soft-danger  { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .badge-soft-info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8; }
        .badge-soft-dark    { background: #f8fafc; border: 1px solid #cbd5e1; color: #334155; }

        .req-list li { margin-bottom: 6px; }
        .req-note { font-size: 12px; color: #64748b; }

        .proof-thumb {
            width: 70px;
            height: 70px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
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

        .quick-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }

        .quick-filters input {
            min-width: 220px;
        }

        .detail-table td {
            vertical-align: top;
            padding: 8px 10px;
            border-top: 1px solid #edf2f7;
        }

        .detail-table td:first-child {
            width: 180px;
            font-weight: 700;
            color: #334155;
            background: #f8fafc;
        }

        .section-label {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            color: #64748b;
            margin: 18px 0 8px;
            letter-spacing: .04em;
        }

        .btn[disabled] {
            pointer-events: none;
            opacity: .6;
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
                    <div class="title"><h4>Parking Permits / Stickers</h4></div>
                    <div class="text-secondary">Phase: <b><?= esc($phase) ?></b></div>
                </div>
            </div>
        </div>

        <?php if ($flash !== ''): ?>
            <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flash) ?></div>
        <?php endif; ?>

        <div class="card-box mb-30 p-3">
            <h5 class="mb-2">Requirements for Yearly Parking Stickers/Permits</h5>
            <ul class="req-list mb-2">
                <li><b>Picture of Vehicle (Front)</b></li>
                <li><b>Picture of Vehicle (Back)</b></li>
            </ul>
            <div class="req-note">
                Note: Initial approval only checks the required vehicle photos. Online payments update the permit to paid automatically after successful checkout.
            </div>
        </div>

        <div class="card-box mb-30 p-3">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tabPending" role="tab">Pending Requests (<?= count($pendingRows) ?>)</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabActive" role="tab">Active Permits (<?= count($activeRows) ?>)</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabAll" role="tab">All Permits</a></li>
            </ul>

            <div class="tab-content pt-3">

                <div class="tab-pane fade show active" id="tabPending" role="tabpanel">
                    <div class="quick-filters">
                        <input type="text" id="filterPendingName" class="form-control form-control-sm" placeholder="Search homeowner...">
                        <input type="text" id="filterPendingPlate" class="form-control form-control-sm" placeholder="Search plate no...">
                    </div>

                    <div class="table-responsive">
                        <table id="tblPending" class="table table-striped table-hover">
                            <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Homeowner</th>
                                <th>Blk/Lot</th>
                                <th>Plate</th>
                                <th>Vehicle Type</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Requested</th>
                                <th class="text-center">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pendingRows as $r): ?>
                                <?php
                                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                                $veh  = trim(($r['vehicle_make'] ?? '') . ' ' . ($r['vehicle_model'] ?? '') . ' ' . ($r['vehicle_color'] ?? ''));

                                $missingCount = 0;
                                foreach (['vehicle_front_path', 'vehicle_back_path'] as $k) {
                                    if (empty($r[$k])) $missingCount++;
                                }

                                $pay = strtolower((string)($r['payment_status'] ?? 'unpaid'));
                                $payBadge = 'badge-soft-warning';
                                if ($pay === 'paid') $payBadge = 'badge-soft-success';
                                elseif ($pay === 'failed') $payBadge = 'badge-soft-danger';
                                elseif (in_array($pay, ['waived', 'for payment', 'for verification'], true)) $payBadge = 'badge-soft-info';

                                $canApprove = ($missingCount === 0);
                                $canActivate = ($pay === 'paid');

                                $detailsPayload = [
                                    'id' => $r['id'] ?? '',
                                    'permit_no' => $r['permit_no'] ?? '—',
                                    'homeowner' => $name,
                                    'email' => $r['ho_email'] ?? '',
                                    'house_lot_number' => $r['house_lot_number'] ?? '',
                                    'plate_no' => $r['plate_no'] ?? '',
                                    'vehicle_type' => $r['vehicle_type'] ?? '',
                                    'vehicle_make' => $r['vehicle_make'] ?? '',
                                    'vehicle_model' => $r['vehicle_model'] ?? '',
                                    'vehicle_color' => $r['vehicle_color'] ?? '',
                                    'permit_duration' => $r['permit_duration'] ?? '',
                                    'payment_status' => $r['payment_status'] ?? 'unpaid',
                                    'payment_method' => $r['payment_method'] ?? '',
                                    'sticker_year' => $r['sticker_year'] ?? '',
                                    'status' => $r['status'] ?? '',
                                    'valid_from' => $r['valid_from'] ?? '',
                                    'valid_until' => $r['valid_until'] ?? '',
                                    'requested_at' => $r['requested_at'] ?? '',
                                    'updated_at' => $r['updated_at'] ?? '',
                                    'rejected_reason' => $r['rejected_reason'] ?? '',
                                    'revoked_reason' => $r['revoked_reason'] ?? '',
                                ];
                                ?>
                                <tr>
                                    <td><?= (int)$r['id'] ?></td>
                                    <td>
                                        <?= esc($name) ?>
                                        <div class="text-secondary" style="font-size:12px;"><?= esc($veh) ?></div>
                                        <div class="text-secondary" style="font-size:12px;"><?= esc($r['ho_email'] ?? '') ?></div>
                                    </td>
                                    <td><?= esc($r['house_lot_number'] ?? '') ?></td>
                                    <td><?= esc($r['plate_no'] ?? '') ?></td>
                                    <td><?= esc(ucfirst((string)($r['vehicle_type'] ?? ''))) ?></td>
                                    <td>
                                        <span class="badge-soft <?= esc($payBadge) ?>"><?= esc($pay ?: 'unpaid') ?></span>
                                        <div class="text-secondary" style="font-size:12px;"><?= esc($r['payment_method'] ?? '—') ?></div>
                                    </td>
                                    <td>
                                        <span class="badge-soft badge-soft-warning">pending</span>
                                        <?php if ($missingCount > 0): ?>
                                            <div class="text-danger" style="font-size:12px;">Missing: <?= (int)$missingCount ?></div>
                                        <?php else: ?>
                                            <div class="text-success" style="font-size:12px;">Complete</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= esc($r['requested_at'] ?? '') ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-secondary btnDetails"
                                                data-json='<?= esc(json_encode($detailsPayload, JSON_UNESCAPED_SLASHES)) ?>'>
                                            <i class="dw dw-eye"></i> Details
                                        </button>

                                        <button class="btn btn-sm btn-outline-primary btnReq"
                                                data-json='<?= esc(json_encode([
                                                    "Picture of Vehicle (Front)" => $r['vehicle_front_path'] ?? "",
                                                    "Picture of Vehicle (Back)" => $r['vehicle_back_path'] ?? "",
                                                ], JSON_UNESCAPED_SLASHES)) ?>'
                                                data-name="<?= esc($name) ?>"
                                                data-plate="<?= esc($r['plate_no'] ?? '') ?>"
                                                data-payment="<?= esc($r['payment_status'] ?? 'unpaid') ?>"
                                                data-method="<?= esc($r['payment_method'] ?? '') ?>">
                                            <i class="dw dw-file"></i> Requirements
                                        </button>

                                        <button class="btn btn-sm btn-success btnApprove"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-name="<?= esc($name) ?>"
                                                data-plate="<?= esc($r['plate_no'] ?? '') ?>"
                                                data-payment="<?= esc($r['payment_status'] ?? 'unpaid') ?>"
                                                <?= $canApprove ? '' : 'disabled title="Complete required documents first."' ?>>
                                            <i class="dw dw-check"></i> Approve
                                        </button>

                                        <?php if ($canActivate): ?>
                                            <button class="btn btn-sm btn-primary btnActivate"
                                                    data-id="<?= (int)$r['id'] ?>"
                                                    data-name="<?= esc($name) ?>"
                                                    data-plate="<?= esc($r['plate_no'] ?? '') ?>"
                                                    data-payment="<?= esc($r['payment_status'] ?? 'unpaid') ?>">
                                                <i class="dw dw-shield"></i> Activate
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-primary" disabled title="Payment must be marked as paid before activation.">
                                                <i class="dw dw-shield"></i> Activate
                                            </button>
                                        <?php endif; ?>

                                        <button class="btn btn-sm btn-danger btnReject"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-name="<?= esc($name) ?>"
                                                data-plate="<?= esc($r['plate_no'] ?? '') ?>">
                                            <i class="dw dw-delete-3"></i> Reject
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$pendingRows): ?>
                                <tr><td colspan="9" class="text-center text-secondary">No pending permit requests.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="tabActive" role="tabpanel">
                    <div class="quick-filters">
                        <input type="text" id="filterActiveName" class="form-control form-control-sm" placeholder="Search homeowner...">
                        <input type="text" id="filterActivePlate" class="form-control form-control-sm" placeholder="Search plate no...">
                    </div>

                    <div class="table-responsive">
                        <table id="tblActive" class="table table-striped table-hover">
                            <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Permit No</th>
                                <th>Homeowner</th>
                                <th>Plate</th>
                                <th>Vehicle Type</th>
                                <th>Payment</th>
                                <th>Validity</th>
                                <th class="text-center">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($activeRows as $r): ?>
                                <?php
                                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                                $valid = trim((string)($r['valid_from'] ?? '')) . ' → ' . trim((string)($r['valid_until'] ?? ''));
                                $pay = strtolower((string)($r['payment_status'] ?? 'unpaid'));
                                $payBadge = 'badge-soft-warning';
                                if ($pay === 'paid') $payBadge = 'badge-soft-success';
                                elseif ($pay === 'failed') $payBadge = 'badge-soft-danger';
                                elseif ($pay === 'waived') $payBadge = 'badge-soft-info';

                                $detailsPayload = [
                                    'id' => $r['id'] ?? '',
                                    'permit_no' => $r['permit_no'] ?? '—',
                                    'homeowner' => $name,
                                    'email' => $r['ho_email'] ?? '',
                                    'house_lot_number' => $r['house_lot_number'] ?? '',
                                    'plate_no' => $r['plate_no'] ?? '',
                                    'vehicle_type' => $r['vehicle_type'] ?? '',
                                    'vehicle_make' => $r['vehicle_make'] ?? '',
                                    'vehicle_model' => $r['vehicle_model'] ?? '',
                                    'vehicle_color' => $r['vehicle_color'] ?? '',
                                    'permit_duration' => $r['permit_duration'] ?? '',
                                    'payment_status' => $r['payment_status'] ?? 'unpaid',
                                    'payment_method' => $r['payment_method'] ?? '',
                                    'sticker_year' => $r['sticker_year'] ?? '',
                                    'status' => $r['status'] ?? '',
                                    'valid_from' => $r['valid_from'] ?? '',
                                    'valid_until' => $r['valid_until'] ?? '',
                                    'requested_at' => $r['requested_at'] ?? '',
                                    'updated_at' => $r['updated_at'] ?? '',
                                    'rejected_reason' => $r['rejected_reason'] ?? '',
                                    'revoked_reason' => $r['revoked_reason'] ?? '',
                                ];
                                ?>
                                <tr>
                                    <td><?= (int)$r['id'] ?></td>
                                    <td><span class="badge-soft badge-soft-info"><?= esc($r['permit_no'] ?? '—') ?></span></td>
                                    <td><?= esc($name) ?></td>
                                    <td><?= esc($r['plate_no'] ?? '') ?></td>
                                    <td><?= esc(ucfirst((string)($r['vehicle_type'] ?? ''))) ?></td>
                                    <td>
                                        <span class="badge-soft <?= esc($payBadge) ?>"><?= esc($pay ?: 'unpaid') ?></span>
                                        <div class="text-secondary" style="font-size:12px;"><?= esc($r['payment_method'] ?? '—') ?></div>
                                    </td>
                                    <td><?= esc($valid) ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-secondary btnDetails"
                                                data-json='<?= esc(json_encode($detailsPayload, JSON_UNESCAPED_SLASHES)) ?>'>
                                            <i class="dw dw-eye"></i> Details
                                        </button>

                                        <button class="btn btn-sm btn-outline-primary btnRenew"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-permit="<?= esc($r['permit_no'] ?? '') ?>"
                                                data-until="<?= esc($r['valid_until'] ?? '') ?>">
                                            <i class="dw dw-refresh"></i> Renew
                                        </button>

                                        <button class="btn btn-sm btn-outline-danger btnRevoke"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-permit="<?= esc($r['permit_no'] ?? '') ?>"
                                                data-plate="<?= esc($r['plate_no'] ?? '') ?>">
                                            <i class="dw dw-ban"></i> Revoke
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$activeRows): ?>
                                <tr><td colspan="8" class="text-center text-secondary">No active permits.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="tabAll" role="tabpanel">
                    <div class="quick-filters">
                        <input type="text" id="filterAllName" class="form-control form-control-sm" placeholder="Search homeowner...">
                        <input type="text" id="filterAllPlate" class="form-control form-control-sm" placeholder="Search plate no...">
                    </div>

                    <div class="table-responsive">
                        <table id="tblAll" class="table table-striped table-hover">
                            <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Permit No</th>
                                <th>Homeowner</th>
                                <th>Plate</th>
                                <th>Vehicle Type</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Validity</th>
                                <th>Updated</th>
                                <th class="text-center">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($allRows as $r): ?>
                                <?php
                                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                                $st = (string)($r['status'] ?? 'pending');
                                $badge = 'badge-soft-info';
                                if ($st === 'pending') $badge = 'badge-soft-warning';
                                if ($st === 'active') $badge = 'badge-soft-success';
                                if (in_array($st, ['expired', 'revoked', 'rejected'], true)) $badge = 'badge-soft-danger';

                                $pay = strtolower((string)($r['payment_status'] ?? 'unpaid'));
                                $payBadge = 'badge-soft-warning';
                                if ($pay === 'paid') $payBadge = 'badge-soft-success';
                                elseif ($pay === 'failed') $payBadge = 'badge-soft-danger';
                                elseif (in_array($pay, ['waived', 'for payment', 'for verification'], true)) $payBadge = 'badge-soft-info';

                                $valid = trim((string)($r['valid_from'] ?? '')) . ' → ' . trim((string)($r['valid_until'] ?? ''));

                                $detailsPayload = [
                                    'id' => $r['id'] ?? '',
                                    'permit_no' => $r['permit_no'] ?? '—',
                                    'homeowner' => $name,
                                    'email' => $r['ho_email'] ?? '',
                                    'house_lot_number' => $r['house_lot_number'] ?? '',
                                    'plate_no' => $r['plate_no'] ?? '',
                                    'vehicle_type' => $r['vehicle_type'] ?? '',
                                    'vehicle_make' => $r['vehicle_make'] ?? '',
                                    'vehicle_model' => $r['vehicle_model'] ?? '',
                                    'vehicle_color' => $r['vehicle_color'] ?? '',
                                    'permit_duration' => $r['permit_duration'] ?? '',
                                    'payment_status' => $r['payment_status'] ?? 'unpaid',
                                    'payment_method' => $r['payment_method'] ?? '',
                                    'sticker_year' => $r['sticker_year'] ?? '',
                                    'status' => $r['status'] ?? '',
                                    'valid_from' => $r['valid_from'] ?? '',
                                    'valid_until' => $r['valid_until'] ?? '',
                                    'requested_at' => $r['requested_at'] ?? '',
                                    'updated_at' => $r['updated_at'] ?? '',
                                    'rejected_reason' => $r['rejected_reason'] ?? '',
                                    'revoked_reason' => $r['revoked_reason'] ?? '',
                                ];
                                ?>
                                <tr>
                                    <td><?= (int)$r['id'] ?></td>
                                    <td><?= esc($r['permit_no'] ?? '—') ?></td>
                                    <td><?= esc($name) ?></td>
                                    <td><?= esc($r['plate_no'] ?? '') ?></td>
                                    <td><?= esc(ucfirst((string)($r['vehicle_type'] ?? ''))) ?></td>
                                    <td>
                                        <span class="badge-soft <?= esc($payBadge) ?>"><?= esc($pay ?: 'unpaid') ?></span>
                                        <div class="text-secondary" style="font-size:12px;"><?= esc($r['payment_method'] ?? '—') ?></div>
                                    </td>
                                    <td><span class="badge-soft <?= esc($badge) ?>"><?= esc($st) ?></span></td>
                                    <td><?= esc($valid) ?></td>
                                    <td><?= esc($r['updated_at'] ?? '') ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-secondary btnDetails"
                                                data-json='<?= esc(json_encode($detailsPayload, JSON_UNESCAPED_SLASHES)) ?>'>
                                            <i class="dw dw-eye"></i> Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$allRows): ?>
                                <tr><td colspan="10" class="text-center text-secondary">No permits found.</td></tr>
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

<div class="modal fade" id="modalReq" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Submitted Requirements</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="text-secondary mb-2" id="reqInfo"></div>
                <div class="text-secondary mb-3" id="reqPaymentInfo"></div>
                <div id="reqList"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDetails" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Permit Full Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body" id="detailsBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalApprove" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="id" id="approveId">
            <div class="modal-header">
                <h5 class="modal-title">Approve Requirements</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="text-secondary mb-2" id="approveInfo"></div>
                <div class="alert alert-info mb-0">
                    This approves the submitted requirements only and allows the homeowner/tenant to proceed to payment.
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-success">Approve Requirements</button>
                <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalActivate" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="activate">
            <input type="hidden" name="id" id="activateId">
            <div class="modal-header">
                <h5 class="modal-title">Activate Permit</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="text-secondary mb-2" id="activateInfo"></div>
                <div class="form-group">
                    <label>Valid From</label>
                    <input type="date" name="valid_from" id="activateValidFrom" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Valid Until</label>
                    <input type="date" name="valid_until" id="activateValidUntil" class="form-control" required>
                </div>
                <div class="alert alert-info mb-0">Permit number will be auto-generated per phase in format like P1-001.</div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Activate & Issue</button>
                <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalReject" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
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

<div class="modal fade" id="modalRevoke" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
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

<div class="modal fade" id="modalRenew" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
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
function isImagePath(path) {
    return /\.(jpg|jpeg|png|gif|webp|bmp)$/i.test(path || '');
}

function loadScript(src) {
    return new Promise(function(resolve, reject) {
        var s = document.createElement('script');
        s.src = src;
        s.onload = resolve;
        s.onerror = reject;
        document.body.appendChild(s);
    });
}

function simpleTableFilter(tableId, nameInputId, plateInputId) {
    const table = document.getElementById(tableId);
    const nameInput = document.getElementById(nameInputId);
    const plateInput = document.getElementById(plateInputId);
    if (!table || !nameInput || !plateInput) return;

    function applyFilter() {
        const nameVal = (nameInput.value || '').toLowerCase();
        const plateVal = (plateInput.value || '').toLowerCase();
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach(function(row) {
            const tds = row.querySelectorAll('td');
            if (!tds.length) return;

            const rowText = row.innerText.toLowerCase();
            const homeownerText = tds[1] ? tds[1].innerText.toLowerCase() : rowText;
            const plateText = tds[3] ? tds[3].innerText.toLowerCase() : rowText;

            const okName = !nameVal || homeownerText.indexOf(nameVal) !== -1;
            const okPlate = !plateVal || plateText.indexOf(plateVal) !== -1;

            row.style.display = (okName && okPlate) ? '' : 'none';
        });
    }

    nameInput.addEventListener('input', applyFilter);
    plateInput.addEventListener('input', applyFilter);
}

function detailRow(label, value) {
    return `<tr><td>${label}</td><td>${value || '—'}</td></tr>`;
}

function openDetailsModal(data) {
    let html = '';

    html += `<div class="section-label">Permit Information</div>`;
    html += `<table class="table table-bordered detail-table">`;
    html += detailRow('Permit ID', data.id);
    html += detailRow('Permit No.', data.permit_no);
    html += detailRow('Status', data.status);
    html += detailRow('Sticker Year', data.sticker_year);
    html += detailRow('Permit Duration', data.permit_duration);
    html += detailRow('Validity Start', data.valid_from);
    html += detailRow('Validity End', data.valid_until);
    html += `</table>`;

    html += `<div class="section-label">Homeowner Information</div>`;
    html += `<table class="table table-bordered detail-table">`;
    html += detailRow('Homeowner', data.homeowner);
    html += detailRow('Email', data.email);
    html += detailRow('Blk/Lot', data.house_lot_number);
    html += `</table>`;

    html += `<div class="section-label">Vehicle Information</div>`;
    html += `<table class="table table-bordered detail-table">`;
    html += detailRow('Plate No.', data.plate_no);
    html += detailRow('Vehicle Type', data.vehicle_type);
    html += detailRow('Vehicle Make', data.vehicle_make);
    html += detailRow('Vehicle Model', data.vehicle_model);
    html += detailRow('Vehicle Color', data.vehicle_color);
    html += `</table>`;

    html += `<div class="section-label">Payment Information</div>`;
    html += `<table class="table table-bordered detail-table">`;
    html += detailRow('Payment Status', data.payment_status);
    html += detailRow('Payment Method', data.payment_method);
    html += `</table>`;

    if (data.rejected_reason || data.revoked_reason) {
        html += `<div class="section-label">Remarks</div>`;
        html += `<table class="table table-bordered detail-table">`;
        html += detailRow('Rejected Reason', data.rejected_reason);
        html += detailRow('Revoked Reason', data.revoked_reason);
        html += `</table>`;
    }

    html += `<div class="section-label">Timeline</div>`;
    html += `<table class="table table-bordered detail-table">`;
    html += detailRow('Requested At', data.requested_at);
    html += detailRow('Updated At', data.updated_at);
    html += `</table>`;

    $('#detailsBody').html(html);
    $('#modalDetails').modal('show');
}

async function ensureDataTablesThenInit() {
    try {
        if (typeof window.jQuery === 'undefined') {
            await loadScript('https://code.jquery.com/jquery-3.7.1.min.js');
        }

        if (!jQuery.fn.DataTable) {
            await loadScript('https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js');
            await loadScript('https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js');
            await loadScript('https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js');
            await loadScript('https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap4.min.js');
        }

        if (jQuery.fn.DataTable) {
            const dtPending = $('#tblPending').DataTable({
                responsive: true,
                pageLength: 10,
                order: [],
                columnDefs: [{ orderable: false, targets: 8 }]
            });

            const dtActive = $('#tblActive').DataTable({
                responsive: true,
                pageLength: 10,
                order: [],
                columnDefs: [{ orderable: false, targets: 7 }]
            });

            const dtAll = $('#tblAll').DataTable({
                responsive: true,
                pageLength: 10,
                order: [],
                columnDefs: [{ orderable: false, targets: 9 }]
            });

            $('#filterPendingName, #filterPendingPlate').on('keyup change', function() {
                dtPending.search(
                    ($('#filterPendingName').val() || '') + ' ' + ($('#filterPendingPlate').val() || '')
                ).draw();
            });

            $('#filterActiveName, #filterActivePlate').on('keyup change', function() {
                dtActive.search(
                    ($('#filterActiveName').val() || '') + ' ' + ($('#filterActivePlate').val() || '')
                ).draw();
            });

            $('#filterAllName, #filterAllPlate').on('keyup change', function() {
                dtAll.search(
                    ($('#filterAllName').val() || '') + ' ' + ($('#filterAllPlate').val() || '')
                ).draw();
            });
        } else {
            console.warn('DataTables plugin not loaded. Using simple fallback filter.');
            simpleTableFilter('tblPending', 'filterPendingName', 'filterPendingPlate');
            simpleTableFilter('tblActive', 'filterActiveName', 'filterActivePlate');
            simpleTableFilter('tblAll', 'filterAllName', 'filterAllPlate');
        }
    } catch (e) {
        console.warn('CDN fallback for DataTables failed. Using simple fallback filter.', e);
        simpleTableFilter('tblPending', 'filterPendingName', 'filterPendingPlate');
        simpleTableFilter('tblActive', 'filterActiveName', 'filterActivePlate');
        simpleTableFilter('tblAll', 'filterAllName', 'filterAllPlate');
    }
}

$(function() {
    ensureDataTablesThenInit();

    $(document).on('click', '.btnDetails', function() {
        let data = {};
        try {
            data = JSON.parse($(this).attr('data-json'));
        } catch (e) {
            console.error('Invalid details JSON:', e);
            data = {};
        }
        openDetailsModal(data);
    });

    $(document).on('click', '.btnReq', function() {
        const name = $(this).data('name');
        const plate = $(this).data('plate');
        const payment = $(this).data('payment') || 'unpaid';
        const method = $(this).data('method') || '—';

        $('#reqInfo').text(`Homeowner: ${name} • Plate: ${plate}`);
        $('#reqPaymentInfo').html(`<b>Payment Status:</b> ${payment} &nbsp;&nbsp; <b>Method:</b> ${method}`);

        let data = {};
        try {
            data = JSON.parse($(this).attr('data-json'));
        } catch (e) {
            console.error('Invalid requirements JSON:', e);
            data = {};
        }

        let html = '<div class="table-responsive"><table class="table table-sm table-bordered">';
        html += '<thead><tr><th>Requirement</th><th>Status</th><th>Preview / File</th></tr></thead><tbody>';

        Object.keys(data).forEach(function(k) {
            const p = data[k] || '';
            const status = p
                ? '<span class="badge badge-success">Submitted</span>'
                : '<span class="badge badge-danger">Missing</span>';

            let fileHtml = '—';
            if (p) {
                if (isImagePath(p)) {
                    fileHtml = `
                        <a href="${p}" target="_blank">
                            <img src="${p}" class="proof-thumb" alt="file preview">
                        </a>
                        <div><a href="${p}" target="_blank">Open file</a></div>
                    `;
                } else {
                    fileHtml = `<a href="${p}" target="_blank">Open file</a>`;
                }
            }

            html += `<tr><td>${k}</td><td>${status}</td><td>${fileHtml}</td></tr>`;
        });

        html += '</tbody></table></div>';
        $('#reqList').html(html);
        $('#modalReq').modal('show');
    });

    $(document).on('click', '.btnApprove:not([disabled])', function() {
        $('#approveId').val($(this).data('id'));
        $('#approveInfo').text(`Homeowner: ${$(this).data('name')} • Plate: ${$(this).data('plate')}`);
        $('#modalApprove').modal('show');
    });

    $(document).on('click', '.btnActivate', function() {
        const payment = String($(this).data('payment') || '').toLowerCase();

        if (payment !== 'paid') {
            alert('This permit cannot be activated yet because payment is not completed.');
            return;
        }

        const todayObj = new Date();
        const today = todayObj.toISOString().split('T')[0];

        const nextYearObj = new Date(todayObj);
        nextYearObj.setFullYear(nextYearObj.getFullYear() + 1);
        const nextYearDate = nextYearObj.toISOString().split('T')[0];

        $('#activateId').val($(this).data('id'));
        $('#activateInfo').text(`Homeowner: ${$(this).data('name')} • Plate: ${$(this).data('plate')} • Payment: ${payment}`);
        $('#activateValidFrom').val(today);
        $('#activateValidUntil').val(nextYearDate);
        $('#modalActivate').modal('show');
    });

    $(document).on('click', '.btnReject', function() {
        $('#rejectId').val($(this).data('id'));
        $('#rejectInfo').text(`Homeowner: ${$(this).data('name')} • Plate: ${$(this).data('plate')}`);
        $('#modalReject').modal('show');
    });

    $(document).on('click', '.btnRevoke', function() {
        $('#revokeId').val($(this).data('id'));
        $('#revokeInfo').text(`Permit: ${$(this).data('permit')} • Plate: ${$(this).data('plate')}`);
        $('#modalRevoke').modal('show');
    });

    $(document).on('click', '.btnRenew', function() {
        $('#renewId').val($(this).data('id'));
        $('#renewInfo').text(`Permit: ${$(this).data('permit')}`);
        $('#renewUntil').val($(this).data('until'));
        $('#modalRenew').modal('show');
    });
});
</script>

<div id="accessToast" class="access-toast">
    🚫 You do not have access to that part.
</div>

<script>
window.userPermissions = <?= json_encode($permissions) ?>;

document.addEventListener('DOMContentLoaded', function () {
    const toast = document.getElementById('accessToast');

    function showAccessToast() {
        toast.classList.add('show');
        setTimeout(() => {
            toast.classList.remove('show');
        }, 2500);
    }

    document.querySelectorAll('.menu-access-link').forEach(function(link) {
        link.addEventListener('click', function(e) {
            const moduleKey = this.dataset.module || '';
            const allowed = !!window.userPermissions[moduleKey];
            if (!allowed) {
                e.preventDefault();
                showAccessToast();
            }
        });
    });
});
</script>

</body>
</html>