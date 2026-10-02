<?php
session_start();
require_once '../config/database.php';

if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    $_SESSION['admin_role'] !== 'superadmin'
) {
    header('Location: ../index.php');
    exit;
}

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function nfmt($value): string
{
    return number_format((float)$value, 0);
}

if (empty($_SESSION['superadmin_homeowner_import_csrf'])) {
    $_SESSION['superadmin_homeowner_import_csrf'] = bin2hex(random_bytes(32));
}

$homeownerImportCsrf = (string)$_SESSION['superadmin_homeowner_import_csrf'];
$allowedPhases = ['All', 'Phase 1', 'Phase 2', 'Phase 3'];
$selectedPhase = trim((string)($_GET['phase'] ?? 'All'));

if (!in_array($selectedPhase, $allowedPhases, true)) {
    $selectedPhase = 'All';
}

// Approved homeowner directory.
if ($selectedPhase === 'All') {
    $stmt = $conn->prepare(
        "SELECT
            h.id,
            h.public_id,
            h.first_name,
            h.middle_name,
            h.last_name,
            h.contact_number,
            h.email,
            h.house_lot_number,
            h.block,
            h.lot,
            h.street,
            h.phase,
            h.status,
            COALESCE(hp.position, 'Homeowner') AS position
         FROM homeowners h
         LEFT JOIN homeowner_positions hp
           ON hp.homeowner_id = h.id
          AND hp.phase = h.phase
         WHERE h.status='approved'
         ORDER BY h.phase ASC, h.last_name ASC, h.first_name ASC"
    );
} else {
    $stmt = $conn->prepare(
        "SELECT
            h.id,
            h.public_id,
            h.first_name,
            h.middle_name,
            h.last_name,
            h.contact_number,
            h.email,
            h.house_lot_number,
            h.block,
            h.lot,
            h.street,
            h.phase,
            h.status,
            COALESCE(hp.position, 'Homeowner') AS position
         FROM homeowners h
         LEFT JOIN homeowner_positions hp
           ON hp.homeowner_id = h.id
          AND hp.phase = h.phase
         WHERE h.status='approved'
           AND h.phase=?
         ORDER BY h.phase ASC, h.last_name ASC, h.first_name ASC"
    );
    $stmt->bind_param('s', $selectedPhase);
}

$stmt->execute();
$approvedRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// All-phase counts should stay meaningful even when directory is filtered.
$countRows = $conn->query(
    "SELECT phase, COUNT(*) AS total
     FROM homeowners
     WHERE status='approved'
     GROUP BY phase"
)->fetch_all(MYSQLI_ASSOC);

$phaseCounts = [
    'Phase 1' => 0,
    'Phase 2' => 0,
    'Phase 3' => 0,
];

foreach ($countRows as $countRow) {
    $phase = (string)($countRow['phase'] ?? '');
    if (array_key_exists($phase, $phaseCounts)) {
        $phaseCounts[$phase] = (int)($countRow['total'] ?? 0);
    }
}

$totalHomeowners = array_sum($phaseCounts);

// Imported records waiting for review/finalization.
$pendingImports = [];
$duplicateImports = [];
$importQueueAvailable = true;

try {
    $pendingStmt = $conn->prepare(
        "SELECT
            id,
            public_id,
            first_name,
            middle_name,
            last_name,
            contact_number,
            email,
            phase,
            block,
            lot,
            street,
            house_lot_number,
            length_of_residency,
            residential_type,
            emergency_contact_person,
            emergency_contact_number,
            created_at
         FROM homeowners
         WHERE status='pending'
           AND valid_id_path LIKE 'imports/%'
         ORDER BY created_at DESC, id DESC"
    );
    $pendingStmt->execute();
    $pendingImports = $pendingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pendingStmt->close();

    $queueCheck = $conn->query("SHOW TABLES LIKE 'homeowner_import_queue'");
    $importQueueAvailable = $queueCheck && $queueCheck->num_rows > 0;

    if ($importQueueAvailable) {
        $duplicateStmt = $conn->prepare(
            "SELECT
                q.id,
                q.source_row,
                q.first_name,
                q.middle_name,
                q.last_name,
                q.contact_number,
                q.email,
                q.phase,
                q.block,
                q.lot,
                q.street,
                q.house_lot_number,
                q.residential_type,
                q.duplicate_homeowner_id,
                q.created_at,
                h.email AS existing_email,
                h.first_name AS existing_first_name,
                h.middle_name AS existing_middle_name,
                h.last_name AS existing_last_name,
                h.phase AS existing_phase,
                h.block AS existing_block,
                h.lot AS existing_lot,
                h.house_lot_number AS existing_house_lot_number
             FROM homeowner_import_queue q
             LEFT JOIN homeowners h ON h.id=q.duplicate_homeowner_id
             WHERE q.status='duplicate'
             ORDER BY q.created_at DESC, q.id DESC"
        );
        $duplicateStmt->execute();
        $duplicateImports = $duplicateStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $duplicateStmt->close();
    }
} catch (Throwable $e) {
    error_log('Superadmin homeowner migration queue query failed: ' . $e->getMessage());
    $importQueueAvailable = false;
}

$pendingImportCount = count($pendingImports);
$duplicateImportCount = count($duplicateImports);
?>
<!DOCTYPE html>
<html>
<head>
  <?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>
    <meta charset="utf-8">
    <title>Superadmin - Homeowner Management</title>

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
        .badge-soft-info { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }
        .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
        .badge-soft-danger { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; }
        .badge-soft-secondary { background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; }
        .mini-note { color:#64748b; font-size:13px; }
        .phase-tools { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .migration-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .migration-note {
            border-left: 4px solid #077f46;
            background: #f7fbf8;
            padding: 12px 14px;
            border-radius: 8px;
            color: #475569;
            font-size: 13px;
        }
        .table td, .table th { vertical-align: middle; }
        .review-grid {
            display:grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .review-item {
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:11px 12px;
            background:#fafafa;
        }
        .review-item .label {
            font-size:11px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:.04em;
            color:#94a3b8;
            margin-bottom:3px;
        }
        .review-item .value {
            color:#1f2937;
            font-weight:600;
            word-break:break-word;
        }
        #appToast {
            position: fixed;
            top: 18px;
            right: 18px;
            z-index: 99999;
            min-width: 280px;
            max-width: 420px;
            display: none;
            padding: 12px 14px;
            border-radius: 10px;
            box-shadow: 0 12px 30px rgba(15,23,42,.16);
            color: #fff;
            font-weight: 600;
            font-size: 13px;
        }
        #appToast.show { display:block; }
        #appToast.success { background:#077f46; }
        #appToast.warning { background:#b45309; }
        #appToast.error { background:#b91c1c; }
        @media (max-width: 767px) {
            .review-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div id="appToast" role="status" aria-live="polite"></div>

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
                    <a class="dropdown-item" href="logs.php"><i class="dw dw-list3"></i> Activity Logs</a>
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
                    <div class="text-secondary">Set up and review homeowner records across all phases.</div>
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
                                Import the existing HOA masterlist first, then use the directory to view finalized homeowners.
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
                        <span class="text-secondary">Approved Homeowners</span>
                        <span class="badge-soft badge-soft-success"><?= nfmt($totalHomeowners) ?></span>
                    </div>
                    <div class="mb-2 d-flex justify-content-between">
                        <span class="text-secondary">Waiting for Review</span>
                        <span class="badge-soft badge-soft-warning"><?= nfmt($pendingImportCount) ?></span>
                    </div>
                    <div class="mb-2 d-flex justify-content-between">
                        <span class="text-secondary">Import Conflicts</span>
                        <span class="badge-soft badge-soft-danger"><?= nfmt($duplicateImportCount) ?></span>
                    </div>
                    <hr>
                    <?php foreach ($phaseCounts as $phase => $count): ?>
                        <div class="mb-2 d-flex justify-content-between">
                            <span class="text-secondary"><?= esc($phase) ?></span>
                            <span class="badge-soft badge-soft-info"><?= nfmt($count) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card-box mb-30 p-3" id="homeownerMigration">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap" style="gap:10px;">
                <div>
                    <h5 class="mb-1">Initial Homeowner Migration</h5>
                    <div class="mini-note">Use the current South Meridian resident import template, review the records, then finalize them.</div>
                </div>

                <div class="migration-actions">
                    <a href="download_homeowner_template.php" class="btn btn-outline-success">
                        <i class="dw dw-download"></i> Download Template
                    </a>
                    <button type="button" class="btn btn-success" id="importExcelBtn">
                        <i class="dw dw-upload1"></i> Import Homeowners
                    </button>
                    <input type="file" id="excelFileInput" accept=".xlsx,.xls,.csv" hidden>
                </div>
            </div>

            <div class="migration-note mb-3">
                Imported residents are not treated as new applicants. They stay here for a quick check before becoming active homeowner records.
            </div>

            <div id="importStatus" class="alert d-none mb-3" role="alert"></div>

            <?php if (!$importQueueAvailable): ?>
                <div class="alert alert-warning mb-0">
                    The homeowner import queue is not ready. Run <strong>superadmin_homeowner_migration_setup.sql</strong> in phpMyAdmin first.
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-2" style="gap:8px;">
                    <h6 class="mb-0">Homeowners for Review</h6>
                    <?php if ($pendingImportCount > 0): ?>
                        <button type="button" class="btn btn-sm btn-success" id="finalizeAllBtn">
                            Finalize All
                            <span class="badge badge-light ml-1"><?= (int)$pendingImportCount ?></span>
                        </button>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table id="migrationReviewTable" class="table table-striped table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phase</th>
                                <th>Property</th>
                                <th>Residential Type</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pendingImports as $row): ?>
                            <?php
                                $fullName = trim(
                                    (string)$row['first_name'] . ' ' .
                                    (string)($row['middle_name'] ?? '') . ' ' .
                                    (string)$row['last_name']
                                );
                                $property = ((int)$row['block'] > 0 && (int)$row['lot'] > 0)
                                    ? 'Block ' . (int)$row['block'] . ', Lot ' . (int)$row['lot']
                                    : (string)$row['house_lot_number'];
                            ?>
                            <tr>
                                <td><?= esc($fullName) ?></td>
                                <td><?= esc($row['email']) ?></td>
                                <td><?= esc($row['phase']) ?></td>
                                <td><?= esc($property) ?></td>
                                <td><?= esc($row['residential_type']) ?></td>
                                <td class="text-center">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary reviewHomeownerBtn"
                                        data-id="<?= (int)$row['id'] ?>"
                                    >
                                        Review
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($pendingImportCount === 0): ?>
                    <div class="text-center py-4 text-muted">
                        No imported homeowners are waiting for review.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($importQueueAvailable && $duplicateImportCount > 0): ?>
            <div class="card-box mb-30 p-3">
                <div class="mb-3">
                    <h5 class="mb-1">Import Conflicts</h5>
                    <div class="mini-note">These rows were not added as active homeowner records. Check the existing resident or property before making changes.</div>
                </div>
                <div class="table-responsive">
                    <table id="conflictsTable" class="table table-striped table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Imported Resident</th>
                                <th>Email</th>
                                <th>Phase / Property</th>
                                <th>Reason</th>
                                <th>Existing Record</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($duplicateImports as $row): ?>
                            <?php
                                $importName = trim(
                                    (string)$row['first_name'] . ' ' .
                                    (string)($row['middle_name'] ?? '') . ' ' .
                                    (string)$row['last_name']
                                );
                                $existingName = trim(
                                    (string)($row['existing_first_name'] ?? '') . ' ' .
                                    (string)($row['existing_middle_name'] ?? '') . ' ' .
                                    (string)($row['existing_last_name'] ?? '')
                                );
                                $property = ((int)$row['block'] > 0 && (int)$row['lot'] > 0)
                                    ? 'Block ' . (int)$row['block'] . ', Lot ' . (int)$row['lot']
                                    : (string)$row['house_lot_number'];
                                $sameEmail = strtolower(trim((string)$row['email'])) === strtolower(trim((string)($row['existing_email'] ?? '')));
                                $reason = $sameEmail ? 'Duplicate email' : 'Possible ownership transfer';
                            ?>
                            <tr>
                                <td><?= esc($importName) ?></td>
                                <td><?= esc($row['email']) ?></td>
                                <td><?= esc($row['phase'] . ' · ' . $property) ?></td>
                                <td>
                                    <span class="badge-soft <?= $sameEmail ? 'badge-soft-warning' : 'badge-soft-danger' ?>">
                                        <?= esc($reason) ?>
                                    </span>
                                </td>
                                <td><?= esc($existingName !== '' ? $existingName : 'Existing homeowner #' . (int)$row['duplicate_homeowner_id']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="card-box mb-30 p-3">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap" style="gap:10px;">
                <div>
                    <h5 class="mb-1">Approved Homeowners</h5>
                    <div class="mini-note">Finalized homeowner records currently available in the system.</div>
                </div>

                <form method="GET" class="phase-tools">
                    <label class="mb-0 text-secondary">Phase:</label>
                    <select name="phase" class="form-control" style="width:160px;" onchange="this.form.submit()">
                        <?php foreach ($allowedPhases as $phase): ?>
                            <option value="<?= esc($phase) ?>" <?= $selectedPhase === $phase ? 'selected' : '' ?>>
                                <?= esc($phase) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button class="btn btn-sm btn-primary" type="submit">Apply</button></noscript>
                </form>
            </div>

            <div class="table-responsive">
                <table id="homeownersTable" class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Property</th>
                            <th>Phase</th>
                            <th>Position</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($approvedRows as $row): ?>
                        <?php
                            $fullName = trim(
                                (string)$row['first_name'] . ' ' .
                                (string)($row['middle_name'] ?? '') . ' ' .
                                (string)$row['last_name']
                            );
                            $displayId = trim((string)($row['public_id'] ?? ''));
                            if ($displayId === '') {
                                $phaseNo = (int)filter_var((string)$row['phase'], FILTER_SANITIZE_NUMBER_INT);
                                $displayId = 'P' . $phaseNo . (int)$row['id'];
                            }
                            $property = ((int)$row['block'] > 0 && (int)$row['lot'] > 0)
                                ? 'Block ' . (int)$row['block'] . ', Lot ' . (int)$row['lot']
                                : (string)$row['house_lot_number'];
                        ?>
                        <tr>
                            <td><?= esc($displayId) ?></td>
                            <td><?= esc($fullName) ?></td>
                            <td><?= esc($property) ?></td>
                            <td><?= esc($row['phase']) ?></td>
                            <td><?= esc($row['position']) ?></td>
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

<!-- Review Homeowner Modal -->
<div class="modal fade" id="reviewHomeownerModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-1">Review Homeowner</h5>
                    <div class="mini-note">Check the imported information before finalizing the record.</div>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="review-grid" id="reviewHomeownerDetails"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="reviewFinalizeBtn">Finalize Homeowner</button>
            </div>
        </div>
    </div>
</div>

<!-- Finalize Confirmation Modal -->
<div class="modal fade" id="finalizeConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="finalizeConfirmTitle">Finalize Homeowner</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="finalizeConfirmText">
                Finalize this homeowner record and send the account setup email?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="finalizeConfirmBtn">Finalize</button>
            </div>
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
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
const homeownerImportCsrf = <?= json_encode($homeownerImportCsrf) ?>;
const migrationRows = <?= json_encode(array_column($pendingImports, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let finalizeRequest = { all: false, id: 0 };
let toastTimer = null;

function showToast(message, kind = 'success') {
    const toast = document.getElementById('appToast');
    if (!toast) return;

    toast.textContent = message;
    toast.className = 'show ' + kind;

    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toast.className = '';
    }, 3600);
}

function setImportStatus(message, kind = 'info') {
    const status = document.getElementById('importStatus');
    if (!status) return;

    status.className = 'alert mb-3 alert-' + kind;
    status.textContent = message;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function normalizeExcelKey(key) {
    return String(key || '')
        .trim()
        .toLowerCase()
        .replace(/&/g, ' and ')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

function normalizeExcelRow(row) {
    const aliases = {
        firstname: 'first_name',
        first: 'first_name',
        middlename: 'middle_name',
        middle: 'middle_name',
        lastname: 'last_name',
        last: 'last_name',
        contact: 'contact_number',
        contact_no: 'contact_number',
        phone: 'contact_number',
        mobile: 'contact_number',
        block_number: 'block',
        block_no: 'block',
        lot_number: 'lot',
        lot_no: 'lot',
        city: 'city_municipality',
        municipality: 'city_municipality',
        zipcode: 'zip_code',
        zip: 'zip_code',
        postal_code: 'zip_code',
        postalcode: 'zip_code',
        residentialtype: 'residential_type',
        emergency_contact: 'emergency_contact_person',
        emergency_person: 'emergency_contact_person',
        emergency_number: 'emergency_contact_number',
        length_of_residency_in_the_barangay: 'length_of_residency'
    };

    const clean = {};

    Object.entries(row || {}).forEach(([key, value]) => {
        let normalized = normalizeExcelKey(key);
        normalized = aliases[normalized] || normalized;
        clean[normalized] = typeof value === 'string' ? value.trim() : value;
    });

    return clean;
}

function valueOrDash(value) {
    const text = String(value ?? '').trim();
    return text !== '' ? text : 'Not provided';
}

function openReviewModal(id) {
    const row = migrationRows[String(id)] || migrationRows[id];
    if (!row) {
        showToast('Homeowner record was not found. Reload the page and try again.', 'error');
        return;
    }

    const name = [row.first_name, row.middle_name, row.last_name]
        .filter(Boolean)
        .join(' ')
        .replace(/\s+/g, ' ')
        .trim();

    const property = Number(row.block) > 0 && Number(row.lot) > 0
        ? `Block ${Number(row.block)}, Lot ${Number(row.lot)}`
        : valueOrDash(row.house_lot_number);

    const items = [
        ['Name', name],
        ['Email', row.email],
        ['Contact Number', row.contact_number],
        ['Phase', row.phase],
        ['Property', property],
        ['Street', row.street],
        ['Length of Residency', row.length_of_residency],
        ['Residential Type', row.residential_type],
        ['Emergency Contact', row.emergency_contact_person],
        ['Emergency Number', row.emergency_contact_number]
    ];

    document.getElementById('reviewHomeownerDetails').innerHTML = items.map(([label, value]) => `
        <div class="review-item">
            <div class="label">${escapeHtml(label)}</div>
            <div class="value">${escapeHtml(valueOrDash(value))}</div>
        </div>
    `).join('');

    const btn = document.getElementById('reviewFinalizeBtn');
    btn.dataset.id = String(id);
    $('#reviewHomeownerModal').modal('show');
}

function openFinalizeConfirm(all, id = 0) {
    finalizeRequest = { all: Boolean(all), id: Number(id || 0) };

    const title = document.getElementById('finalizeConfirmTitle');
    const text = document.getElementById('finalizeConfirmText');
    const btn = document.getElementById('finalizeConfirmBtn');

    if (finalizeRequest.all) {
        title.textContent = 'Finalize All Homeowners';
        text.textContent = 'Finalize every valid homeowner currently waiting for review and send their account setup emails?';
        btn.textContent = 'Finalize All';
    } else {
        title.textContent = 'Finalize Homeowner';
        text.textContent = 'Finalize this homeowner record and send the account setup email?';
        btn.textContent = 'Finalize';
    }

    $('#finalizeConfirmModal').modal('show');
}

async function submitFinalize() {
    const btn = document.getElementById('finalizeConfirmBtn');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Saving...';

    try {
        const form = new FormData();
        form.append('csrf', homeownerImportCsrf);

        if (finalizeRequest.all) {
            form.append('all', '1');
        } else {
            form.append('id', String(finalizeRequest.id));
        }

        const response = await fetch('homeowner_migration_finalize.php', {
            method: 'POST',
            body: form
        });

        const raw = await response.text();
        let data = null;

        try {
            data = JSON.parse(raw);
        } catch (error) {
            throw new Error('The server returned an invalid response.');
        }

        if (!response.ok || !data || !data.success) {
            throw new Error(data && data.message ? data.message : 'Homeowner finalization failed.');
        }

        $('#finalizeConfirmModal').modal('hide');
        $('#reviewHomeownerModal').modal('hide');
        showToast(data.message || 'Homeowner record finalized.', data.failed > 0 ? 'warning' : 'success');

        setTimeout(() => location.reload(), 1100);

    } catch (error) {
        console.error(error);
        showToast(error.message || 'Homeowner finalization failed.', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

async function importWorkbook(file) {
    const importBtn = document.getElementById('importExcelBtn');
    importBtn.disabled = true;

    try {
        if (typeof XLSX === 'undefined') {
            throw new Error('Excel support did not load. Refresh the page and try again.');
        }

        setImportStatus('Reading ' + file.name + '...', 'info');

        const buffer = await file.arrayBuffer();
        const workbook = XLSX.read(buffer, { type: 'array' });
        const sheetName = workbook.SheetNames.includes('Resident Import')
            ? 'Resident Import'
            : workbook.SheetNames[0];
        const sheet = workbook.Sheets[sheetName];

        const matrix = XLSX.utils.sheet_to_json(sheet, {
            header: 1,
            defval: '',
            raw: false
        });

        const headerIndex = matrix.findIndex(row => {
            const headers = row.map(normalizeExcelKey);
            return headers.includes('first_name') &&
                   headers.includes('last_name') &&
                   headers.includes('contact_number') &&
                   headers.includes('email') &&
                   headers.includes('phase') &&
                   headers.includes('block') &&
                   headers.includes('lot');
        });

        if (headerIndex === -1) {
            throw new Error('The homeowner columns could not be found. Please use the current South Meridian resident import template.');
        }

        let rows = XLSX.utils.sheet_to_json(sheet, {
            range: headerIndex,
            defval: '',
            raw: false
        }).map(normalizeExcelRow);

        // The supplied template fills location defaults down to row 500.
        // Only rows with actual resident fields count as homeowner data.
        rows = rows.filter(row => [
            row.first_name,
            row.middle_name,
            row.last_name,
            row.contact_number,
            row.email,
            row.block,
            row.lot,
            row.other_location_info,
            row.length_of_residency,
            row.residential_type,
            row.emergency_contact_person,
            row.emergency_contact_number
        ].some(value => String(value ?? '').trim() !== ''));

        if (!rows.length) {
            throw new Error('No homeowner data rows were found in the file.');
        }

        const requiredHeaders = [
            'first_name',
            'middle_name',
            'last_name',
            'contact_number',
            'email',
            'phase',
            'block',
            'lot',
            'street',
            'barangay',
            'city_municipality',
            'province',
            'region',
            'zip_code',
            'country',
            'other_location_info',
            'length_of_residency',
            'residential_type',
            'emergency_contact_person',
            'emergency_contact_number'
        ];

        const firstRowKeys = Object.keys(normalizeExcelRow(
            XLSX.utils.sheet_to_json(sheet, { range: headerIndex, defval: '', raw: false })[0] || {}
        ));

        const missing = requiredHeaders.filter(header => !firstRowKeys.includes(header));

        if (missing.length) {
            throw new Error('The file is missing required column(s): ' + missing.join(', '));
        }

        rows = rows.map(row => ({
            ...row,
            barangay: String(row.barangay || 'Salitran IV').trim(),
            city_municipality: String(row.city_municipality || 'Dasmarinas City').trim(),
            province: String(row.province || 'Cavite').trim(),
            region: String(row.region || 'CALABARZON').trim(),
            zip_code: String(row.zip_code || '4114').trim(),
            country: String(row.country || 'Philippines').trim()
        }));

        let imported = 0;
        let duplicates = 0;
        let possibleTransfers = 0;
        let skipped = 0;
        const errors = [];
        const chunkSize = 100;

        for (let i = 0; i < rows.length; i += chunkSize) {
            const chunk = rows.slice(i, i + chunkSize);

            setImportStatus(
                `Importing ${i + 1}-${Math.min(i + chunk.length, rows.length)} of ${rows.length}...`,
                'info'
            );

            const response = await fetch('homeowner_migration_import.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf: homeownerImportCsrf,
                    rows: chunk,
                    row_offset: i + headerIndex + 1
                })
            });

            const raw = await response.text();
            let data = null;

            try {
                data = JSON.parse(raw);
            } catch (error) {
                console.error('Raw import response:', raw);
                throw new Error('The import server returned an invalid response.');
            }

            if (!response.ok || !data || !data.success) {
                throw new Error(data && data.message ? data.message : 'Import failed.');
            }

            imported += Number(data.imported || 0);
            duplicates += Number(data.duplicates || 0);
            possibleTransfers += Number(data.possible_transfers || 0);
            skipped += Number(data.skipped || 0);
            (data.errors || []).forEach(item => errors.push(item));
        }

        let summary = `${imported} homeowner${imported === 1 ? '' : 's'} added for review`;

        if (duplicates > 0) summary += `, ${duplicates} duplicate${duplicates === 1 ? '' : 's'}`;
        if (possibleTransfers > 0) summary += `, ${possibleTransfers} possible transfer${possibleTransfers === 1 ? '' : 's'}`;
        if (skipped > 0) summary += `, ${skipped} skipped`;
        summary += '.';

        const kind = duplicates > 0 || possibleTransfers > 0 || skipped > 0 ? 'warning' : 'success';
        setImportStatus(summary, kind);
        showToast(summary, kind);

        if (errors.length > 0) {
            console.warn('Homeowner import row errors:', errors);
        }

        setTimeout(() => location.reload(), 1400);

    } catch (error) {
        console.error(error);
        setImportStatus(error.message || 'Import failed.', 'danger');
        showToast(error.message || 'Import failed.', 'error');
    } finally {
        importBtn.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const importBtn = document.getElementById('importExcelBtn');
    const fileInput = document.getElementById('excelFileInput');

    importBtn?.addEventListener('click', () => fileInput?.click());

    fileInput?.addEventListener('change', async function () {
        const file = this.files && this.files[0];
        if (!file) return;
        await importWorkbook(file);
        this.value = '';
    });

    document.querySelectorAll('.reviewHomeownerBtn').forEach(button => {
        button.addEventListener('click', () => openReviewModal(button.dataset.id));
    });

    document.getElementById('reviewFinalizeBtn')?.addEventListener('click', function () {
        const id = Number(this.dataset.id || 0);
        if (id <= 0) {
            showToast('Select a homeowner record first.', 'error');
            return;
        }
        $('#reviewHomeownerModal').modal('hide');
        setTimeout(() => openFinalizeConfirm(false, id), 180);
    });

    document.getElementById('finalizeAllBtn')?.addEventListener('click', () => openFinalizeConfirm(true));
    document.getElementById('finalizeConfirmBtn')?.addEventListener('click', submitFinalize);

    if ($.fn.DataTable) {
        $('#homeownersTable').DataTable({
            responsive: true,
            pageLength: 10,
            order: [[3, 'asc'], [1, 'asc']]
        });

        if ($('#migrationReviewTable tbody tr').length > 0) {
            $('#migrationReviewTable').DataTable({
                responsive: true,
                pageLength: 10,
                order: [[2, 'asc'], [0, 'asc']]
            });
        }

        if ($('#conflictsTable tbody tr').length > 0) {
            $('#conflictsTable').DataTable({
                responsive: true,
                pageLength: 10,
                order: [[2, 'asc'], [0, 'asc']]
            });
        }
    }
});
</script>
</body>
</html>
