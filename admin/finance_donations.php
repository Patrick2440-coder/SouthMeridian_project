<?php
require_once __DIR__ . "/finance_helpers.php";
require_once 'admin_access.php';

requireAccess('finance');
require_admin();

$conn = db_conn();

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

date_default_timezone_set('Asia/Manila');

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);
$adminId = admin_id();

function financeDonationRedirect(bool $canPickPhase, string $phase): void {
  $url = 'finance_donations.php';
  if ($canPickPhase) {
    $url .= '?phase=' . urlencode($phase);
  }
  header('Location: ' . $url);
  exit;
}

function financeDonationFlash(string $type, string $message): void {
  $_SESSION['finance_donation_flash'] = [
    'type' => $type,
    'message' => $message
  ];
}

$stmt = $conn->prepare("
  SELECT role, position
  FROM admins
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$adminRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$adminRole = trim((string)($adminRow['role'] ?? ''));
$adminPosition = trim((string)($adminRow['position'] ?? ''));
$canManageDonations = ($adminPosition === 'Treasurer');

if (empty($_SESSION['csrf_finance_donations'])) {
  $_SESSION['csrf_finance_donations'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_finance_donations'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedCsrf = (string)($_POST['csrf'] ?? '');

  if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
    financeDonationFlash(
      'danger',
      'Your session token is no longer valid. Please refresh the page and try again.'
    );
    financeDonationRedirect($canPickPhase, $phase);
  }
}

$stmt = $conn->prepare("
  SELECT
    id,
    first_name,
    middle_name,
    last_name,
    email,
    house_lot_number
  FROM homeowners
  WHERE phase = ?
    AND status = 'approved'
  ORDER BY last_name, first_name, id
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$homeowners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (isset($_POST['add_donation'])) {
  if (!$canManageDonations) {
    financeDonationFlash(
      'danger',
      'Only the Treasurer can record donations and contributions.'
    );
    financeDonationRedirect($canPickPhase, $phase);
  }

  $donorType = (string)($_POST['donor_type'] ?? 'external');
  $homeownerId = (int)($_POST['homeowner_id'] ?? 0);

  $donorName = mb_substr(
    trim((string)($_POST['donor_name'] ?? '')),
    0,
    255
  );

  $donorEmail = mb_substr(
    trim((string)($_POST['donor_email'] ?? '')),
    0,
    255
  );

  $amount = (float)($_POST['amount'] ?? 0);
  $donationDate = trim((string)($_POST['donation_date'] ?? date('Y-m-d')));

  $receiptNo = mb_substr(
    trim((string)($_POST['receipt_no'] ?? '')),
    0,
    50
  );

  $message = mb_substr(
    trim((string)($_POST['message'] ?? '')),
    0,
    255
  );

  if (!in_array($donorType, ['homeowner', 'external'], true)) {
    $donorType = 'external';
  }

  if (!is_finite($amount) || $amount <= 0 || $amount > 10000000) {
    financeDonationFlash('danger', 'Please enter a valid donation amount.');
    financeDonationRedirect($canPickPhase, $phase);
  }

  $dateObj = DateTime::createFromFormat('Y-m-d', $donationDate);
  $dateIsValid =
    $dateObj &&
    $dateObj->format('Y-m-d') === $donationDate;

  if (!$dateIsValid) {
    financeDonationFlash('danger', 'Please enter a valid donation date.');
    financeDonationRedirect($canPickPhase, $phase);
  }

  if ($donationDate > date('Y-m-d')) {
    financeDonationFlash(
      'danger',
      'A donation cannot be recorded with a future date.'
    );
    financeDonationRedirect($canPickPhase, $phase);
  }

  $linkedHomeownerId = null;

  if ($donorType === 'homeowner') {
    if ($homeownerId <= 0) {
      financeDonationFlash(
        'danger',
        'Please select the homeowner who made the donation.'
      );
      financeDonationRedirect($canPickPhase, $phase);
    }

    $stmt = $conn->prepare("
      SELECT
        id,
        first_name,
        middle_name,
        last_name,
        email
      FROM homeowners
      WHERE id = ?
        AND phase = ?
        AND status = 'approved'
      LIMIT 1
    ");
    $stmt->bind_param("is", $homeownerId, $phase);
    $stmt->execute();
    $homeowner = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$homeowner) {
      financeDonationFlash(
        'danger',
        'The selected homeowner is not an approved homeowner in this phase.'
      );
      financeDonationRedirect($canPickPhase, $phase);
    }

    $donorName = trim(
      (string)($homeowner['first_name'] ?? '') . ' ' .
      (string)($homeowner['middle_name'] ?? '') . ' ' .
      (string)($homeowner['last_name'] ?? '')
    );
    $donorName = preg_replace('/\s+/', ' ', $donorName);
    $donorEmail = trim((string)($homeowner['email'] ?? ''));
    $linkedHomeownerId = (int)$homeowner['id'];

  } else {
    if ($donorName === '') {
      financeDonationFlash(
        'danger',
        'Donor name is required for an external donor.'
      );
      financeDonationRedirect($canPickPhase, $phase);
    }

    if (
      $donorEmail !== '' &&
      !filter_var($donorEmail, FILTER_VALIDATE_EMAIL)
    ) {
      financeDonationFlash(
        'danger',
        'Please enter a valid donor email address.'
      );
      financeDonationRedirect($canPickPhase, $phase);
    }
  }

  if ($receiptNo !== '') {
    $stmt = $conn->prepare("
      SELECT id
      FROM finance_donations
      WHERE phase = ?
        AND receipt_no = ?
      LIMIT 1
    ");
    $stmt->bind_param("ss", $phase, $receiptNo);
    $stmt->execute();
    $duplicateReceipt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicateReceipt) {
      financeDonationFlash(
        'warning',
        'That receipt number is already used by another donation in this phase.'
      );
      financeDonationRedirect($canPickPhase, $phase);
    }
  }

  try {
    $stmt = $conn->prepare("
      INSERT INTO finance_donations
      (
        phase,
        homeowner_id,
        donor_name,
        donor_email,
        amount,
        donation_date,
        receipt_no,
        message,
        created_by_admin_id
      )
      VALUES (?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
      "sissdsssi",
      $phase,
      $linkedHomeownerId,
      $donorName,
      $donorEmail,
      $amount,
      $donationDate,
      $receiptNo,
      $message,
      $adminId
    );

    $stmt->execute();
    $stmt->close();

    financeDonationFlash(
      'success',
      'Donation recorded successfully.'
    );

  } catch (Throwable $e) {
    error_log(
      'Finance donation insert failed: ' .
      $e->getMessage()
    );

    financeDonationFlash(
      'danger',
      'The donation could not be recorded. Please try again.'
    );
  }

  financeDonationRedirect($canPickPhase, $phase);
}

$stmt = $conn->prepare("
  SELECT
    d.*,
    h.house_lot_number AS linked_house_lot
  FROM finance_donations d
  LEFT JOIN homeowners h
    ON h.id = d.homeowner_id
   AND h.phase = d.phase
  WHERE d.phase = ?
  ORDER BY d.donation_date DESC, d.id DESC
  LIMIT 300
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalDonations = 0.0;
foreach ($rows as $row) {
  $totalDonations += (float)($row['amount'] ?? 0);
}

$financeFlash = $_SESSION['finance_donation_flash'] ?? null;
unset($_SESSION['finance_donation_flash']);
?>
<!DOCTYPE html>
<html>
<head>
  <!-- Basic Page Info -->
  <meta charset="utf-8">
  <title>HOA-ADMIN</title>

  <!-- Site favicon -->
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">

  <!-- Mobile Specific Metas -->
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

  <!-- Google Font -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- CSS -->
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

  <script async src="https://www.googletagmanager.com/gtag/js?id=UA-119386393-1"></script>
  <style>
      /* ACCESS TOAST */
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
  visibility: hidden;
  pointer-events: none;
  transform: translateY(-10px);
  transition:
    opacity .3s ease,
    transform .3s ease,
    visibility .3s ease;
}

.access-toast.show {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
  transform: translateY(0);
}
  </style>

  <!-- SHARED ADMIN LIGHT / DARK THEME -->
  <link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">

  <style>
  /* =========================================================
     FINANCE DONATIONS - DARK MODE EXTENSIONS
     ========================================================= */

  html.dark .page-header,
  html.dark .card-box,
  html.dark .footer-wrap {
    background: var(--admin-surface) !important;
    color: var(--admin-text) !important;
    border-color: var(--admin-border) !important;
  }

  html.dark .page-header .title h4,
  html.dark .card-box h5,
  html.dark .card-box label,
  html.dark .card-box b,
  html.dark .footer-wrap {
    color: var(--admin-text) !important;
  }

  html.dark .text-secondary,
  html.dark .text-muted,
  html.dark small.text-secondary,
  html.dark .small.text-secondary {
    color: var(--admin-muted) !important;
  }

  html.dark hr {
    border-top-color: var(--admin-border) !important;
  }

  /* Badges */
  html.dark .badge-light,
  html.dark .badge.badge-light {
    background: var(--admin-surface-2) !important;
    color: var(--admin-text) !important;
    border-color: var(--admin-border) !important;
  }

  html.dark .badge-secondary {
    background: var(--admin-surface-3) !important;
    color: #cbd5e1 !important;
  }

  /* Forms */
  html.dark .form-control,
  html.dark select.form-control,
  html.dark input.form-control,
  html.dark textarea.form-control {
    background: var(--admin-input) !important;
    color: var(--admin-text) !important;
    border-color: var(--admin-border) !important;
  }

  html.dark .form-control:focus,
  html.dark select.form-control:focus,
  html.dark input.form-control:focus,
  html.dark textarea.form-control:focus {
    background: var(--admin-input) !important;
    color: var(--admin-text) !important;
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 .2rem rgba(59,130,246,.16) !important;
  }

  html.dark .form-control::placeholder {
    color: #64748b !important;
  }

  html.dark input[type="date"] {
    color-scheme: dark;
  }

  /* Alerts */
  html.dark .alert-light {
    background: var(--admin-surface-2) !important;
    color: var(--admin-text) !important;
    border-color: var(--admin-border) !important;
  }

  html.dark .alert-info {
    background: rgba(14,165,233,.12) !important;
    color: #bae6fd !important;
    border-color: rgba(14,165,233,.28) !important;
  }

  html.dark .alert-success {
    background: rgba(22,163,74,.12) !important;
    color: #bbf7d0 !important;
    border-color: rgba(34,197,94,.28) !important;
  }

  html.dark .alert-warning {
    background: rgba(217,119,6,.12) !important;
    color: #fde68a !important;
    border-color: rgba(245,158,11,.28) !important;
  }

  html.dark .alert-danger {
    background: rgba(220,38,38,.12) !important;
    color: #fecaca !important;
    border-color: rgba(239,68,68,.28) !important;
  }

  /* Donation table */
  html.dark .table,
  html.dark table.dataTable {
    color: var(--admin-text) !important;
    background: var(--admin-surface) !important;
    border-color: var(--admin-border) !important;
  }

  html.dark .table thead th,
  html.dark table.dataTable thead th,
  html.dark table.dataTable thead td {
    background: var(--admin-surface-2) !important;
    color: #f8fafc !important;
    border-color: var(--admin-border) !important;
  }

  html.dark .table tbody td,
  html.dark .table tbody th,
  html.dark table.dataTable tbody td {
    color: var(--admin-text) !important;
    border-color: var(--admin-border) !important;
  }

  html.dark .table-striped tbody tr:nth-of-type(odd),
  html.dark .table-striped tbody tr:nth-of-type(odd) > *,
  html.dark table.dataTable.stripe tbody tr.odd,
  html.dark table.dataTable.display tbody tr.odd {
    background: var(--admin-surface-2) !important;
    color: var(--admin-text) !important;
  }

  html.dark .table-striped tbody tr:nth-of-type(even),
  html.dark .table-striped tbody tr:nth-of-type(even) > * {
    background: var(--admin-surface) !important;
    color: var(--admin-text) !important;
  }

  html.dark .table-hover tbody tr:hover,
  html.dark .table-hover tbody tr:hover > *,
  html.dark table.dataTable tbody tr:hover,
  html.dark table.dataTable tbody tr:hover > * {
    background: var(--admin-hover) !important;
    color: #fff !important;
  }

  /* DataTables controls */
  html.dark .dataTables_wrapper,
  html.dark .dataTables_wrapper .dataTables_length,
  html.dark .dataTables_wrapper .dataTables_filter,
  html.dark .dataTables_wrapper .dataTables_info,
  html.dark .dataTables_wrapper .dataTables_paginate {
    color: var(--admin-muted) !important;
  }

  html.dark .dataTables_wrapper .dataTables_filter input,
  html.dark .dataTables_wrapper .dataTables_length select {
    background: var(--admin-input) !important;
    color: var(--admin-text) !important;
    border: 1px solid var(--admin-border) !important;
  }

  html.dark .dataTables_wrapper .dataTables_paginate .paginate_button {
    color: var(--admin-text) !important;
  }

  html.dark .dataTables_wrapper .dataTables_paginate .paginate_button.current,
  html.dark .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover,
  html.dark .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
    color: #fff !important;
    border-color: #2563eb !important;
    background: #2563eb !important;
  }

  html.dark .dataTables_wrapper .dataTables_paginate .paginate_button.disabled,
  html.dark .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
    color: #64748b !important;
    background: transparent !important;
    border-color: transparent !important;
  }

  /* Receipt action */
  html.dark .btn-outline-primary {
    color: #93c5fd !important;
    border-color: #3b82f6 !important;
  }

  html.dark .btn-outline-primary:hover {
    color: #fff !important;
    background: #2563eb !important;
    border-color: #2563eb !important;
  }
  </style>

  <!-- Apply saved theme before body paint -->
  <script>
  (function () {
    try {
      const savedTheme = localStorage.getItem('hoa-theme');

      const dark =
        savedTheme === 'dark' ||
        (
          !savedTheme &&
          window.matchMedia &&
          window.matchMedia('(prefers-color-scheme: dark)').matches
        );

      document.documentElement.classList.toggle('dark', dark);
    } catch (e) {}
  })();
  </script>
</head>
<body>

  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
      <div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>
    </div>

    <div class="header-right">

      <!-- SHARED ADMIN DARK MODE TOGGLE -->
      <div class="admin-theme-switch">
        <button
          type="button"
          id="themeToggle"
          class="admin-theme-toggle"
          aria-label="Switch theme"
          title="Switch theme"
        >
          <span id="themeIcon">☾</span>
        </button>
      </div>

      <div class="user-notification">
        <div class="dropdown">
          <a class="dropdown-toggle no-arrow" href="#" role="button" data-toggle="dropdown">
            <i class="icon-copy dw dw-notification"></i>
            <span class="badge notification-active"></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right">
            <div class="notification-list mx-h-350 customscroll">
              <ul>
                <li><a href="#"><img src="vendors/images/img.jpg" alt=""><h3>John Doe</h3><p>Lorem ipsum dolor sit amet...</p></a></li>
                <li><a href="#"><img src="vendors/images/photo1.jpg" alt=""><h3>Lea R. Frith</h3><p>Lorem ipsum dolor sit amet...</p></a></li>
                <li><a href="#"><img src="vendors/images/photo2.jpg" alt=""><h3>Erik L. Richards</h3><p>Lorem ipsum dolor sit amet...</p></a></li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="user-info-dropdown">
        <div class="dropdown">
          <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
            <span class="user-icon">
              <img src="vendors/images/photo1.jpg" alt="">
            </span>
          </a>
          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="profile.html"><i class="dw dw-user1"></i> Profile</a>
            <a class="dropdown-item" href="profile.html"><i class="dw dw-settings2"></i> Setting</a>
            <a class="dropdown-item" href="logout.php"><i class="dw dw-logout"></i> Log Out</a>
          </div>
        </div>
      </div>

    </div>
  </div>

  <div class="right-sidebar">
    <div class="sidebar-title">
      <h3 class="weight-600 font-16 text-blue">
        Layout Settings
        <span class="btn-block font-weight-400 font-12">User Interface Settings</span>
      </h3>
      <div class="close-sidebar" data-toggle="right-sidebar-close">
        <i class="icon-copy ion-close-round"></i>
      </div>
    </div>
    <div class="right-sidebar-body customscroll">
      <div class="right-sidebar-body-content">
        <h4 class="weight-600 font-18 pb-10">Header Background</h4>
        <div class="sidebar-btn-group pb-30 mb-10">
          <a href="javascript:void(0);" class="btn btn-outline-primary header-white active">White</a>
          <a href="javascript:void(0);" class="btn btn-outline-primary header-dark">Dark</a>
        </div>

        <h4 class="weight-600 font-18 pb-10">Sidebar Background</h4>
        <div class="sidebar-btn-group pb-30 mb-10">
          <a href="javascript:void(0);" class="btn btn-outline-primary sidebar-light">White</a>
          <a href="javascript:void(0);" class="btn btn-outline-primary sidebar-dark active">Dark</a>
        </div>

        <div class="reset-options pt-30 text-center">
          <button class="btn btn-danger" id="reset-settings">Reset Settings</button>
        </div>
      </div>
    </div>
  </div>

  <!-- SIDEBAR -->
<?php include 'sidebar.php'; ?>
<div class="main-container">
  <div class="pd-ltr-20">

    <div class="page-header mb-20">
      <div class="row">
        <div class="col-md-6 col-sm-12">
          <div class="title"><h4>Donations & Contributions</h4></div>
          <div class="text-secondary">Phase: <b><?=esc($phase)?></b></div>
        </div>
        <div class="col-md-6 col-sm-12 text-right">
          <?php if ($canPickPhase): ?>
            <form method="get" class="d-inline-block">
              <select name="phase" class="form-control d-inline-block" style="width:200px" onchange="this.form.submit()">
                <?php foreach(['Phase 1','Phase 2','Phase 3'] as $p): ?>
                  <option value="<?=esc($p)?>" <?= $p===$phase?'selected':'' ?>><?=esc($p)?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($financeFlash): ?>
      <div class="alert alert-<?= esc($financeFlash['type'] ?? 'info') ?> mb-20" role="alert">
        <?= esc($financeFlash['message'] ?? '') ?>
      </div>
    <?php endif; ?>

    <div class="card-box mb-20 p-3">
      <div class="d-flex flex-wrap justify-content-between align-items-center">
        <div>
          <h5 class="mb-1">Donation Summary</h5>
          <div class="text-secondary">
            Recorded in <?= esc($phase) ?>:
            <b>₱ <?= number_format($totalDonations, 2) ?></b>
          </div>
        </div>

        <?php if ($canManageDonations): ?>
          <span class="badge badge-success p-2">Treasurer • Manage</span>
        <?php else: ?>
          <span class="badge badge-secondary p-2">View Only</span>
        <?php endif; ?>
      </div>

      <?php if (!$canManageDonations): ?>
        <div class="alert alert-info mt-3 mb-0">
          Donation recording is managed by the Treasurer.
          You can still review donation history for your permitted phase.
        </div>
      <?php endif; ?>
    </div>

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">Record Donation</h5>
      <?php if ($canManageDonations): ?>
        <form method="post" id="donationForm">
          <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">

          <div class="row">
            <div class="col-md-3">
              <label>Donor Type</label>
              <select class="form-control" name="donor_type" id="donorType" required>
                <option value="homeowner">Homeowner</option>
                <option value="external">External / Guest Donor</option>
              </select>
            </div>

            <div class="col-md-5" id="homeownerDonorWrap">
              <label>Homeowner</label>
              <select class="form-control" name="homeowner_id" id="homeownerDonor">
                <option value="">-- Select Homeowner --</option>

                <?php foreach ($homeowners as $h): ?>
                  <?php
                    $homeownerName = trim(
                      (string)($h['first_name'] ?? '') . ' ' .
                      (string)($h['middle_name'] ?? '') . ' ' .
                      (string)($h['last_name'] ?? '')
                    );
                    $homeownerName = preg_replace('/\s+/', ' ', $homeownerName);
                  ?>
                  <option value="<?= (int)$h['id'] ?>">
                    <?= esc(
                      $homeownerName .
                      ' (' .
                      ($h['house_lot_number'] ?? '') .
                      ')'
                    ) ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <small class="text-secondary">
                Only approved homeowners in this phase are shown.
              </small>
            </div>

            <div class="col-md-5" id="externalNameWrap" style="display:none;">
              <label>Donor Name</label>
              <input
                class="form-control"
                name="donor_name"
                id="donorName"
                maxlength="255"
                placeholder="Full name"
              >
            </div>

            <div class="col-md-4" id="externalEmailWrap" style="display:none;">
              <label>Donor Email (optional)</label>
              <input
                class="form-control"
                name="donor_email"
                id="donorEmail"
                type="email"
                maxlength="255"
                placeholder="name@example.com"
              >
            </div>

            <div class="col-md-2">
              <label>Amount</label>
              <input
                class="form-control"
                name="amount"
                type="number"
                step="0.01"
                min="0.01"
                max="10000000"
                required
              >
            </div>

            <div class="col-md-2">
              <label>Date</label>
              <input
                class="form-control"
                name="donation_date"
                type="date"
                max="<?= esc(date('Y-m-d')) ?>"
                value="<?= esc(date('Y-m-d')) ?>"
                required
              >
            </div>

            <div class="col-md-3 mt-2">
              <label>Receipt #</label>
              <input
                class="form-control"
                name="receipt_no"
                maxlength="50"
                placeholder="OR / Receipt #"
              >
              <small class="text-secondary">
                If supplied, it must be unique within this phase.
              </small>
            </div>

            <div class="col-md-9 mt-2">
              <label>Message / Notes</label>
              <input
                class="form-control"
                name="message"
                maxlength="255"
                placeholder="Optional"
              >
            </div>
          </div>

          <button
            class="btn btn-success mt-3"
            name="add_donation"
            value="1"
          >
            Save Donation
          </button>
        </form>
      <?php else: ?>
        <div class="alert alert-light border mb-0">
          Donation recording is available to the Treasurer only.
        </div>
      <?php endif; ?>
    </div>

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">Donor List</h5>
      <div class="table-responsive">
        <table id="donTable" class="table table-striped table-hover">
          <thead>
            <tr>
              <th>Date</th>
              <th>Donor</th>
              <th>Type</th>
              <th>Email</th>
              <th>Amount</th>
              <th>Receipt #</th>
              <th>Notes</th>
              <th>Receipt</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?=esc($r['donation_date'])?></td>
                <td>
                  <?= esc($r['donor_name']) ?>

                  <?php if (!empty($r['homeowner_id'])): ?>
                    <div class="small text-secondary">
                      <?= esc($r['linked_house_lot'] ?? '') ?>
                    </div>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if (!empty($r['homeowner_id'])): ?>
                    <span class="badge badge-primary">Homeowner</span>
                  <?php else: ?>
                    <span class="badge badge-secondary">External</span>
                  <?php endif; ?>
                </td>

                <td><?=esc($r['donor_email'] ?? '')?></td>
                <td>₱ <?=number_format((float)$r['amount'],2)?></td>
                <td><?=esc($r['receipt_no'] ?? '')?></td>
                <td><?=esc($r['message'] ?? '')?></td>
                <td>
                  <a class="btn btn-sm btn-outline-primary" target="_blank"
                     href="finance_donation_receipt.php?id=<?=(int)$r['id']?><?= $canPickPhase?('&phase='.urlencode($phase)) : '' ?>">
                    Receipt
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <small class="text-secondary">
        Donation receipts are generated from the saved donation record.
      </small>
    </div>

    <div class="footer-wrap pd-20 mb-20 card-box">
      © Copyright South Meridian Homes All Rights Reserved
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
(function () {
  const donorType = document.getElementById('donorType');
  const homeownerWrap = document.getElementById('homeownerDonorWrap');
  const homeownerSelect = document.getElementById('homeownerDonor');
  const externalNameWrap = document.getElementById('externalNameWrap');
  const externalEmailWrap = document.getElementById('externalEmailWrap');
  const donorName = document.getElementById('donorName');

  if (!donorType) {
    return;
  }

  function syncDonorFields() {
    const isHomeowner = donorType.value === 'homeowner';

    if (homeownerWrap) {
      homeownerWrap.style.display = isHomeowner ? '' : 'none';
    }

    if (externalNameWrap) {
      externalNameWrap.style.display = isHomeowner ? 'none' : '';
    }

    if (externalEmailWrap) {
      externalEmailWrap.style.display = isHomeowner ? 'none' : '';
    }

    if (homeownerSelect) {
      homeownerSelect.required = isHomeowner;
    }

    if (donorName) {
      donorName.required = !isHomeowner;
    }
  }

  donorType.addEventListener('change', syncDonorFields);
  syncDonorFields();
})();

$(function(){
  $('#donTable').DataTable({
    pageLength: 25,
    order: [[0,'desc']]
  });
});
</script>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>

<!-- SHARED ADMIN DARK MODE -->
<script src="vendors/scripts/admin_theme.js"></script>
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