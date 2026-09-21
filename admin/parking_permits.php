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
    header("Location: ../index.php");
    exit;
}
if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
    http_response_code(403);
    exit('Superadmin cannot access this module.');
}

// ===================== CSRF =====================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ===================== DB =====================
require_once '../config/database.php';

function esc($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function normalize_asset_url(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') return '';

    $path = str_replace('\\', '/', $path);

    /*
     * Parking requirement files are expected to be local files below uploads/.
     * Reject arbitrary schemes/paths instead of reflecting them into href/src.
     */
    $marker = '/uploads/';
    $pos = stripos($path, $marker);

    if ($pos !== false) {
        return substr($path, $pos + 1);
    }

    if (stripos($path, 'uploads/') === 0) {
        return $path;
    }

    return '';
}

function fail_flash(&$flash, &$flashType, string $msg): void
{
    $flash = $msg;
    $flashType = "danger";
}

function next_permit_no(mysqli $conn, string $phase): string
{
    $prefixMap = [
        'Phase 1' => 'P1-',
        'Phase 2' => 'P2-',
        'Phase 3' => 'P3-',
    ];

    $prefix = $prefixMap[$phase] ?? 'PX-';

    $stmt = $conn->prepare("
        SELECT permit_no
        FROM parking_permits
        WHERE phase = ?
          AND permit_no IS NOT NULL
          AND permit_no <> ''
          AND permit_no LIKE CONCAT(?, '%')
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("ss", $phase, $prefix);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $nextNumber = 1;

    if ($row && !empty($row['permit_no']) && preg_match('/(\d+)$/', $row['permit_no'], $m)) {
        $nextNumber = ((int)$m[1]) + 1;
    }

    return $prefix . str_pad((string)$nextNumber, 3, '0', STR_PAD_LEFT);
}

function compute_permit_dates(string $duration, string $startDate): array
{
    $start = new DateTime($startDate);
    $end = clone $start;

    switch ($duration) {
        case '1_month':
            $end->modify('+1 month')->modify('-1 day');
            break;
        case '3_months':
            $end->modify('+3 months')->modify('-1 day');
            break;
        case '6_months':
            $end->modify('+6 months')->modify('-1 day');
            break;
        case '1_year':
            $end->modify('+1 year')->modify('-1 day');
            break;
        default:
            throw new InvalidArgumentException('Invalid permit duration.');
    }

    return [
        $start->format('Y-m-d'),
        $end->format('Y-m-d'),
    ];
}

function activation_dates_for_permit(mysqli $conn, array $permit): array
{
    $today = new DateTime('today');
    $start = clone $today;

    $requestType = strtolower(trim((string)($permit['request_type'] ?? 'new')));
    $renewOfId = (int)($permit['renew_of_id'] ?? 0);
    $homeownerId = (int)($permit['homeowner_id'] ?? 0);
    $phase = (string)($permit['phase'] ?? '');

    if ($requestType === 'renew' && $renewOfId > 0) {
        $stmt = $conn->prepare("
            SELECT valid_until
            FROM parking_permits
            WHERE id = ?
              AND homeowner_id = ?
              AND phase = ?
            LIMIT 1
        ");
        $stmt->bind_param("iis", $renewOfId, $homeownerId, $phase);
        $stmt->execute();
        $previousPermit = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($previousPermit['valid_until'])) {
            $afterPrevious = new DateTime((string)$previousPermit['valid_until']);
            $afterPrevious->modify('+1 day');

            if ($afterPrevious > $start) {
                $start = $afterPrevious;
            }
        }
    }

    return compute_permit_dates(
        (string)($permit['permit_duration'] ?? ''),
        $start->format('Y-m-d')
    );
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

$myPhase = (string)($me['phase'] ?? 'Phase 1');

$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';

// ===================== ACTIONS =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (
        $postedToken === '' ||
        !hash_equals((string)$_SESSION['csrf_token'], $postedToken)
    ) {
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
                        status,
                        payment_status,
                        permit_no,
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
                    $currentPaymentStatus = strtolower(trim((string)($p['payment_status'] ?? 'unpaid')));

                    if (!in_array($currentPaymentStatus, ['unpaid', 'failed', ''], true)) {
                        fail_flash($flash, $flashType, "This request is already approved for payment or already paid.");
                    } else {
                        $missing = [];

                        if (normalize_asset_url($p['vehicle_front_path'] ?? '') === '') {
                            $missing[] = "Vehicle Front Picture";
                        }

                        if (normalize_asset_url($p['vehicle_back_path'] ?? '') === '') {
                            $missing[] = "Vehicle Back Picture";
                        }

                        if ($missing) {
                            fail_flash(
                                $flash,
                                $flashType,
                                "Cannot approve. Missing requirements: " . implode(", ", $missing)
                            );
                        } else {
                            $permitNo = !empty($p['permit_no'])
                                ? (string)$p['permit_no']
                                : next_permit_no($conn, $phase);

                            $stmt = $conn->prepare("
                                UPDATE parking_permits
                                SET permit_no=?,
                                    payment_status='for payment',
                                    approved_by_admin_id=?,
                                    approved_at=NOW(),
                                    rejected_reason=NULL
                                WHERE id=? AND phase=? AND status='pending'
                                  AND LOWER(COALESCE(payment_status,'unpaid')) IN ('unpaid','failed','')
                            ");
                            $stmt->bind_param("siis", $permitNo, $adminId, $id, $phase);
                            $stmt->execute();

                            if ($stmt->affected_rows <= 0) {
                                fail_flash($flash, $flashType, "Approval failed or the request state changed.");
                            } else {
                                $flash = "Requirements approved. Permit no. {$permitNo} created. The homeowner/tenant may now proceed to payment.";
                                $flashType = "success";
                            }

                            $stmt->close();
                        }
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
                    SELECT payment_status
                    FROM parking_permits
                    WHERE id=? AND phase=? AND status='pending'
                    LIMIT 1
                ");
                $stmt->bind_param("is", $id, $phase);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) {
                    fail_flash($flash, $flashType, "Permit request not found or already processed.");
                } else {
                    $currentPaymentStatus = strtolower(trim((string)($row['payment_status'] ?? 'unpaid')));

                    if (!in_array($currentPaymentStatus, ['unpaid', 'failed', ''], true)) {
                        fail_flash($flash, $flashType, "Only fresh requests can be rejected before payment approval.");
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE parking_permits
                            SET status='rejected',
                                rejected_reason=?,
                                approved_by_admin_id=?,
                                approved_at=NOW()
                            WHERE id=? AND phase=? AND status='pending'
                              AND LOWER(COALESCE(payment_status,'unpaid')) IN ('unpaid','failed','')
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
            }
        }

        /*
         * CASH PAYMENT ACTIVATION
         *
         * Cash permits are activated only after:
         * - requirements were approved,
         * - payment_status is "for payment",
         * - payment_method is "cash".
         *
         * Final validity is calculated at activation time so waiting for
         * admin review/payment does not consume permit days.
         */
        if ($action === 'activate_cash') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                fail_flash($flash, $flashType, "Invalid cash payment request.");
            } else {
                try {
                    $conn->begin_transaction();

                    $stmt = $conn->prepare("
                        SELECT
                            id,
                            homeowner_id,
                            request_type,
                            renew_of_id,
                            phase,
                            permit_no,
                            permit_duration,
                            payment_method,
                            payment_status,
                            status
                        FROM parking_permits
                        WHERE id=? AND phase=?
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $stmt->bind_param("is", $id, $phase);
                    $stmt->execute();
                    $permit = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$permit) {
                        throw new DomainException("Permit request not found.");
                    }

                    $permitStatus = strtolower(trim((string)($permit['status'] ?? '')));
                    $paymentStatus = strtolower(trim((string)($permit['payment_status'] ?? '')));
                    $paymentMethod = strtolower(trim((string)($permit['payment_method'] ?? '')));

                    if ($permitStatus !== 'pending') {
                        throw new DomainException("Only pending permits can be activated.");
                    }

                    if ($paymentStatus !== 'for payment') {
                        throw new DomainException("This permit is not waiting for payment.");
                    }

                    if ($paymentMethod !== 'cash') {
                        throw new DomainException("This action is only for cash / physical payments.");
                    }

                    if (empty($permit['permit_no'])) {
                        throw new DomainException("Permit number is missing. Approve the requirements first.");
                    }

                    [$validFrom, $validUntil] = activation_dates_for_permit($conn, $permit);
                    $stickerYear = (int)substr($validFrom, 0, 4);

                    $stmt = $conn->prepare("
                        UPDATE parking_permits
                        SET payment_status='paid',
                            status='active',
                            valid_from=?,
                            valid_until=?,
                            sticker_year=?,
                            approved_by_admin_id=?,
                            approved_at=COALESCE(approved_at, NOW())
                        WHERE id=? AND phase=?
                          AND status='pending'
                          AND LOWER(COALESCE(payment_status,''))='for payment'
                          AND LOWER(COALESCE(payment_method,''))='cash'
                    ");
                    $stmt->bind_param(
                        "ssiiis",
                        $validFrom,
                        $validUntil,
                        $stickerYear,
                        $adminId,
                        $id,
                        $phase
                    );
                    $stmt->execute();

                    if ($stmt->affected_rows <= 0) {
                        $stmt->close();
                        throw new DomainException("Cash payment activation failed or the permit state changed.");
                    }

                    $stmt->close();
                    $conn->commit();

                    $flash = "Cash payment recorded. Permit {$permit['permit_no']} is now active from {$validFrom} to {$validUntil}.";
                    $flashType = "success";

                } catch (Throwable $e) {
                    try {
                        $conn->rollback();
                    } catch (Throwable $ignored) {
                    }

                    error_log(
                        "Parking cash activation failed. Permit={$id}, Phase={$phase}, Error=" .
                        $e->getMessage()
                    );

                    if ($e instanceof InvalidArgumentException) {
                        $userError = "Invalid permit duration. Please review the permit before activation.";
                    } elseif ($e instanceof DomainException) {
                        $userError = $e->getMessage();
                    } else {
                        $userError = "Cash payment activation failed. Please try again or check the server log.";
                    }

                    fail_flash(
                        $flash,
                        $flashType,
                        $userError
                    );
                }
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
    WHERE p.phase=?
      AND p.status='active'
      AND LOWER(COALESCE(p.payment_status,'paid'))='paid'
      AND p.valid_from IS NOT NULL
      AND p.valid_from <= CURDATE()
      AND p.valid_until >= CURDATE()
    ORDER BY p.valid_until ASC, p.approved_at DESC, p.requested_at DESC
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
                Note: Admin checks requirements first. If approved, the request stays pending while waiting for payment.
                Cash payments are activated here after payment is physically received. Normal renewal requests must start from the homeowner/tenant portal.
            </div>
        </div>

        <div class="card-box mb-30 p-3">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tabPending" role="tab">Pending Requests (<?= count($pendingRows) ?>)</a></li>
                <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabActive" role="tab">Current Permits (<?= count($activeRows) ?>)</a></li>
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
                                elseif (in_array($pay, ['waived', 'for payment'], true)) $payBadge = 'badge-soft-info';

                                $paymentMethod = strtolower(trim((string)($r['payment_method'] ?? '')));
                                $isFreshRequest = in_array($pay, ['unpaid', 'failed', ''], true);
                                $canRecordCashPayment = ($pay === 'for payment' && $paymentMethod === 'cash');
                                $waitingOnlinePayment = ($pay === 'for payment' && $paymentMethod === 'online');
                                $paidPendingReview = ($pay === 'paid');
                                $canApprove = ($missingCount === 0);

                                $detailsPayload = [
                                    'id' => $r['id'] ?? '',
                                    'permit_no' => !empty($r['permit_no']) ? $r['permit_no'] : '—',
                                    'homeowner' => $name,
                                    'email' => $r['ho_email'] ?? '',
                                    'house_lot_number' => $r['house_lot_number'] ?? '',
                                    'plate_no' => $r['plate_no'] ?? '',
                                    'vehicle_type' => $r['vehicle_type'] ?? '',
                                    'vehicle_make' => $r['vehicle_make'] ?? '',
                                    'vehicle_model' => $r['vehicle_model'] ?? '',
                                    'vehicle_color' => $r['vehicle_color'] ?? '',
                                    'request_type' => $r['request_type'] ?? 'new',
                                    'renew_of_id' => $r['renew_of_id'] ?? '',
                                    'permit_duration' => $r['permit_duration'] ?? '',
                                    'payment_status' => $r['payment_status'] ?? 'unpaid',
                                    'payment_method' => $r['payment_method'] ?? '',
                                    'sticker_year' => $r['sticker_year'] ?? '',
                                    'status' => $r['status'] ?? '',
                                    'valid_from' => $r['valid_from'] ?? '',
                                    'valid_until' => $r['valid_until'] ?? '',
                                    'requested_at' => $r['requested_at'] ?? '',
                                    'approved_at' => $r['approved_at'] ?? '',
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
                                                    "Picture of Vehicle (Front)" => normalize_asset_url($r['vehicle_front_path'] ?? ""),
                                                    "Picture of Vehicle (Back)" => normalize_asset_url($r['vehicle_back_path'] ?? ""),
                                                ], JSON_UNESCAPED_SLASHES)) ?>'
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-name="<?= esc($name) ?>"
                                                data-plate="<?= esc($r['plate_no'] ?? '') ?>"
                                                data-payment="<?= esc($r['payment_status'] ?? 'unpaid') ?>"
                                                data-method="<?= esc($r['payment_method'] ?? '') ?>"
                                                data-can-approve="<?= $canApprove ? '1' : '0' ?>"
                                                data-is-fresh="<?= $isFreshRequest ? '1' : '0' ?>"
                                                data-can-record-cash="<?= $canRecordCashPayment ? '1' : '0' ?>"
                                                data-waiting-online="<?= $waitingOnlinePayment ? '1' : '0' ?>"
                                                data-paid-review="<?= $paidPendingReview ? '1' : '0' ?>">
                                            <i class="dw dw-file"></i> Requirements
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
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
                                    'permit_no' => !empty($r['permit_no']) ? $r['permit_no'] : '—',
                                    'homeowner' => $name,
                                    'email' => $r['ho_email'] ?? '',
                                    'house_lot_number' => $r['house_lot_number'] ?? '',
                                    'plate_no' => $r['plate_no'] ?? '',
                                    'vehicle_type' => $r['vehicle_type'] ?? '',
                                    'vehicle_make' => $r['vehicle_make'] ?? '',
                                    'vehicle_model' => $r['vehicle_model'] ?? '',
                                    'vehicle_color' => $r['vehicle_color'] ?? '',
                                    'request_type' => $r['request_type'] ?? 'new',
                                    'renew_of_id' => $r['renew_of_id'] ?? '',
                                    'permit_duration' => $r['permit_duration'] ?? '',
                                    'payment_status' => $r['payment_status'] ?? 'unpaid',
                                    'payment_method' => $r['payment_method'] ?? '',
                                    'sticker_year' => $r['sticker_year'] ?? '',
                                    'status' => $r['status'] ?? '',
                                    'valid_from' => $r['valid_from'] ?? '',
                                    'valid_until' => $r['valid_until'] ?? '',
                                    'requested_at' => $r['requested_at'] ?? '',
                                    'approved_at' => $r['approved_at'] ?? '',
                                    'updated_at' => $r['updated_at'] ?? '',
                                    'rejected_reason' => $r['rejected_reason'] ?? '',
                                    'revoked_reason' => $r['revoked_reason'] ?? '',
                                ];
                                ?>
                                <tr>
                                    <td><?= (int)$r['id'] ?></td>
                                    <td><span class="badge-soft badge-soft-info"><?= esc(!empty($r['permit_no']) ? $r['permit_no'] : '—') ?></span></td>
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

                                        <button class="btn btn-sm btn-outline-danger btnRevoke"
                                                data-id="<?= (int)$r['id'] ?>"
                                                data-permit="<?= esc($r['permit_no'] ?? '') ?>"
                                                data-plate="<?= esc($r['plate_no'] ?? '') ?>">
                                            <i class="dw dw-ban"></i> Revoke
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
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
                                $pay = strtolower((string)($r['payment_status'] ?? 'unpaid'));
                                $validFrom = (string)($r['valid_from'] ?? '');
                                $isUpcoming =
                                    $st === 'active' &&
                                    $pay === 'paid' &&
                                    $validFrom !== '' &&
                                    $validFrom > date('Y-m-d');

                                $displayStatus = $isUpcoming ? 'upcoming' : $st;

                                $badge = 'badge-soft-info';
                                if ($displayStatus === 'pending') $badge = 'badge-soft-warning';
                                if ($displayStatus === 'active') $badge = 'badge-soft-success';
                                if ($displayStatus === 'upcoming') $badge = 'badge-soft-info';
                                if (in_array($displayStatus, ['expired', 'revoked', 'rejected'], true)) $badge = 'badge-soft-danger';
                                $payBadge = 'badge-soft-warning';
                                if ($pay === 'paid') $payBadge = 'badge-soft-success';
                                elseif ($pay === 'failed') $payBadge = 'badge-soft-danger';
                                elseif (in_array($pay, ['waived', 'for payment'], true)) $payBadge = 'badge-soft-info';

                                $valid = trim((string)($r['valid_from'] ?? '')) . ' → ' . trim((string)($r['valid_until'] ?? ''));

                                $detailsPayload = [
                                    'id' => $r['id'] ?? '',
                                    'permit_no' => !empty($r['permit_no']) ? $r['permit_no'] : '—',
                                    'homeowner' => $name,
                                    'email' => $r['ho_email'] ?? '',
                                    'house_lot_number' => $r['house_lot_number'] ?? '',
                                    'plate_no' => $r['plate_no'] ?? '',
                                    'vehicle_type' => $r['vehicle_type'] ?? '',
                                    'vehicle_make' => $r['vehicle_make'] ?? '',
                                    'vehicle_model' => $r['vehicle_model'] ?? '',
                                    'vehicle_color' => $r['vehicle_color'] ?? '',
                                    'request_type' => $r['request_type'] ?? 'new',
                                    'renew_of_id' => $r['renew_of_id'] ?? '',
                                    'permit_duration' => $r['permit_duration'] ?? '',
                                    'payment_status' => $r['payment_status'] ?? 'unpaid',
                                    'payment_method' => $r['payment_method'] ?? '',
                                    'sticker_year' => $r['sticker_year'] ?? '',
                                    'status' => $r['status'] ?? '',
                                    'valid_from' => $r['valid_from'] ?? '',
                                    'valid_until' => $r['valid_until'] ?? '',
                                    'requested_at' => $r['requested_at'] ?? '',
                                    'approved_at' => $r['approved_at'] ?? '',
                                    'updated_at' => $r['updated_at'] ?? '',
                                    'rejected_reason' => $r['rejected_reason'] ?? '',
                                    'revoked_reason' => $r['revoked_reason'] ?? '',
                                ];
                                ?>
                                <tr>
                                    <td><?= (int)$r['id'] ?></td>
                                    <td><?= esc(!empty($r['permit_no']) ? $r['permit_no'] : '—') ?></td>
                                    <td><?= esc($name) ?></td>
                                    <td><?= esc($r['plate_no'] ?? '') ?></td>
                                    <td><?= esc(ucfirst((string)($r['vehicle_type'] ?? ''))) ?></td>
                                    <td>
                                        <span class="badge-soft <?= esc($payBadge) ?>"><?= esc($pay ?: 'unpaid') ?></span>
                                        <div class="text-secondary" style="font-size:12px;"><?= esc($r['payment_method'] ?? '—') ?></div>
                                    </td>
                                    <td>
                                        <span class="badge-soft <?= esc($badge) ?>"><?= esc($displayStatus) ?></span>
                                        <?php if ($isUpcoming): ?>
                                            <div class="text-secondary" style="font-size:12px;">
                                                Starts <?= esc($r['valid_from'] ?? '—') ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
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
                <div class="mt-3" id="reqActionArea"></div>
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
                    This approves the submitted requirements, generates the permit number, and sends the homeowner/tenant to payment while the request remains pending.
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-success">Approve</button>
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

<div class="modal fade" id="modalCashActivate" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="activate_cash">
            <input type="hidden" name="id" id="cashActivateId">

            <div class="modal-header">
                <h5 class="modal-title">Record Cash Payment & Activate</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span>&times;</span>
                </button>
            </div>

            <div class="modal-body">
                <div class="text-secondary mb-3" id="cashActivateInfo"></div>

                <div class="alert alert-info mb-0">
                    Confirm this only after the HOA office has actually received the cash payment.
                    The permit's final validity period will begin from the activation date, or after
                    the previous permit ends for an eligible renewal.
                </div>
            </div>

            <div class="modal-footer">
                <button type="submit" class="btn btn-success">
                    <i class="dw dw-money-2"></i>
                    Record Payment & Activate
                </button>
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

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function detailRow(label, value) {
    return `<tr><td>${escapeHtml(label)}</td><td>${escapeHtml(value || '—')}</td></tr>`;
}

function openDetailsModal(data) {
    let html = '';

    html += `<div class="section-label">Permit Information</div>`;
    html += `<table class="table table-bordered detail-table">`;
    html += detailRow('Permit ID', data.id);
    html += detailRow('Permit No.', data.permit_no);
    html += detailRow('Status', data.status);
    html += detailRow('Sticker Year', data.sticker_year);
    html += detailRow('Request Type', data.request_type);
    html += detailRow('Renewal Of Permit ID', data.renew_of_id);
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
    html += detailRow('Approved At', data.approved_at);
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
            let dtPending, dtActive, dtAll;

            if (!$.fn.DataTable.isDataTable('#tblPending')) {
                dtPending = $('#tblPending').DataTable({
                    responsive: true,
                    pageLength: 10,
                    order: [],
                    columnDefs: [{ orderable: false, targets: 8 }],
                    language: {
                        emptyTable: "No pending permit requests."
                    }
                });
            } else {
                dtPending = $('#tblPending').DataTable();
            }

            if (!$.fn.DataTable.isDataTable('#tblActive')) {
                dtActive = $('#tblActive').DataTable({
                    responsive: true,
                    pageLength: 10,
                    order: [],
                    columnDefs: [{ orderable: false, targets: 7 }],
                    language: {
                        emptyTable: "No permits found."
                    }
                });
            } else {
                dtActive = $('#tblActive').DataTable();
            }

            if (!$.fn.DataTable.isDataTable('#tblAll')) {
                dtAll = $('#tblAll').DataTable({
                    responsive: true,
                    pageLength: 10,
                    order: [],
                    columnDefs: [{ orderable: false, targets: 9 }],
                    language: {
                        emptyTable: "No permits found."
                    }
                });
            } else {
                dtAll = $('#tblAll').DataTable();
            }

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
            simpleTableFilter('tblPending', 'filterPendingName', 'filterPendingPlate');
            simpleTableFilter('tblActive', 'filterActiveName', 'filterActivePlate');
            simpleTableFilter('tblAll', 'filterAllName', 'filterAllPlate');
        }
    } catch (e) {
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
            data = {};
        }
        openDetailsModal(data);
    });

    $(document).on('click', '.btnReq', function() {
        const id = $(this).data('id');
        const name = String($(this).data('name') || '');
        const plate = String($(this).data('plate') || '');
        const payment = String($(this).data('payment') || 'unpaid').toLowerCase();
        const method = String($(this).data('method') || '—').toLowerCase();
        const canApprove = String($(this).data('can-approve')) === '1';
        const isFresh = String($(this).data('is-fresh')) === '1';
        const canRecordCash = String($(this).data('can-record-cash')) === '1';
        const waitingOnline = String($(this).data('waiting-online')) === '1';
        const paidReview = String($(this).data('paid-review')) === '1';

        $('#reqInfo').text(`Homeowner: ${name} • Plate: ${plate}`);
        $('#reqPaymentInfo').text(`Payment Status: ${payment} • Method: ${method}`);

        let data = {};
        try {
            data = JSON.parse($(this).attr('data-json'));
        } catch (e) {
            data = {};
        }

        let html = '<div class="table-responsive"><table class="table table-sm table-bordered">';
        html += '<thead><tr><th>Requirement</th><th>Status</th><th>Preview / File</th></tr></thead><tbody>';

        Object.keys(data).forEach(function(k) {
            const p = String(data[k] || '');
            const status = p
                ? '<span class="badge badge-success">Submitted</span>'
                : '<span class="badge badge-danger">Missing</span>';

            let fileHtml = '—';

            if (p && /^uploads\/[a-z0-9_./-]+$/i.test(p)) {
                const safePath = encodeURI(p);

                if (isImagePath(p)) {
                    fileHtml = `
                        <a href="${safePath}" target="_blank" rel="noopener noreferrer">
                            <img src="${safePath}" class="proof-thumb" alt="file preview">
                        </a>
                        <div>
                            <a href="${safePath}" target="_blank" rel="noopener noreferrer">Open file</a>
                        </div>
                    `;
                } else {
                    fileHtml = `
                        <a href="${safePath}" target="_blank" rel="noopener noreferrer">Open file</a>
                    `;
                }
            }

            html += `<tr><td>${escapeHtml(k)}</td><td>${status}</td><td>${fileHtml}</td></tr>`;
        });

        html += '</tbody></table></div>';
        $('#reqList').html(html);

        let actionHtml = '';

        if (isFresh) {
            if (canApprove) {
                actionHtml += `
                    <button type="button"
                            class="btn btn-success btnOpenApproveFromReq mr-2"
                            data-id="${Number(id)}">
                        <i class="dw dw-check"></i> Approve
                    </button>
                `;
            } else {
                actionHtml += `
                    <button type="button" class="btn btn-success mr-2" disabled>
                        <i class="dw dw-check"></i> Approve
                    </button>
                `;
            }

            actionHtml += `
                <button type="button"
                        class="btn btn-danger btnOpenRejectFromReq"
                        data-id="${Number(id)}">
                    <i class="dw dw-delete-3"></i> Reject
                </button>
            `;

        } else if (canRecordCash) {
            actionHtml += `
                <button type="button"
                        class="btn btn-success btnOpenCashActivate"
                        data-id="${Number(id)}">
                    <i class="dw dw-money-2"></i> Record Cash Payment & Activate
                </button>
            `;

        } else if (waitingOnline) {
            actionHtml += `
                <div class="alert alert-info mb-0">
                    This permit is waiting for the homeowner/tenant to complete the verified online payment.
                </div>
            `;

        } else if (paidReview) {
            actionHtml += `
                <div class="alert alert-warning mb-0">
                    Payment is marked paid while the permit is still pending. Review the payment record before making any manual database changes.
                </div>
            `;

        } else {
            actionHtml += `
                <div class="alert alert-info mb-0">
                    No administrative action is available for the current payment state.
                </div>
            `;
        }

        $('#reqActionArea').html(actionHtml);
        $('#modalReq').modal('show');
    });

    $(document).on('click', '.btnOpenApproveFromReq', function() {
        const info = $('#reqInfo').text();
        $('#modalReq').modal('hide');
        $('#approveId').val($(this).data('id'));
        $('#approveInfo').text(info);
        $('#modalApprove').modal('show');
    });

    $(document).on('click', '.btnOpenRejectFromReq', function() {
        const info = $('#reqInfo').text();
        $('#modalReq').modal('hide');
        $('#rejectId').val($(this).data('id'));
        $('#rejectInfo').text(info);
        $('#modalReject').modal('show');
    });

    $(document).on('click', '.btnOpenCashActivate', function() {
        const info = $('#reqInfo').text();
        $('#modalReq').modal('hide');
        $('#cashActivateId').val($(this).data('id'));
        $('#cashActivateInfo').text(info);
        $('#modalCashActivate').modal('show');
    });

    $(document).on('click', '.btnRevoke', function() {
        $('#revokeId').val($(this).data('id'));
        $('#revokeInfo').text(`Permit: ${$(this).data('permit')} • Plate: ${$(this).data('plate')}`);
        $('#modalRevoke').modal('show');
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