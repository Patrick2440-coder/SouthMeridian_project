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

function financeExpenseRedirect(bool $canPickPhase, string $phase): void {
  $url = 'finance_expenses.php';

  if ($canPickPhase) {
    $url .= '?phase=' . urlencode($phase);
  }

  header('Location: ' . $url);
  exit;
}

function financeExpenseFlash(string $type, string $message): void {
  $_SESSION['finance_expense_flash'] = [
    'type' => $type,
    'message' => $message
  ];
}

function uploadExpenseReceipt(array $file): array {
  if (
    empty($file) ||
    !isset($file['error']) ||
    (int)$file['error'] === UPLOAD_ERR_NO_FILE
  ) {
    return [
      'ok' => true,
      'path' => null,
      'absolute_path' => null
    ];
  }

  if ((int)$file['error'] !== UPLOAD_ERR_OK) {
    return [
      'ok' => false,
      'message' => 'Receipt upload failed. Please select the file again.'
    ];
  }

  $tmpName = (string)($file['tmp_name'] ?? '');
  $fileSize = (int)($file['size'] ?? 0);

  if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    return [
      'ok' => false,
      'message' => 'The uploaded receipt could not be verified.'
    ];
  }

  if ($fileSize <= 0 || $fileSize > (5 * 1024 * 1024)) {
    return [
      'ok' => false,
      'message' => 'Receipt must be 5MB or smaller.'
    ];
  }

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string)$finfo->file($tmpName);

  $allowedMime = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'application/pdf' => 'pdf'
  ];

  if (!isset($allowedMime[$mime])) {
    return [
      'ok' => false,
      'message' => 'Invalid receipt file type. Use JPG, PNG, or PDF only.'
    ];
  }

  $uploadDir = __DIR__ . '/uploads/finance/receipts/';
  $uploadUrl = 'uploads/finance/receipts/';

  if (
    !is_dir($uploadDir) &&
    !mkdir($uploadDir, 0775, true) &&
    !is_dir($uploadDir)
  ) {
    return [
      'ok' => false,
      'message' => 'Receipt storage is unavailable.'
    ];
  }

  $safeName = bin2hex(random_bytes(16)) . '.' . $allowedMime[$mime];
  $absolutePath = $uploadDir . $safeName;

  if (!move_uploaded_file($tmpName, $absolutePath)) {
    return [
      'ok' => false,
      'message' => 'The receipt could not be saved.'
    ];
  }

  @chmod($absolutePath, 0644);

  return [
    'ok' => true,
    'path' => $uploadUrl . $safeName,
    'absolute_path' => $absolutePath
  ];
}

/* =========================
   ADMIN POSITION / WRITE ACCESS
   ========================= */
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

/*
 * Treasurer manages expense records.
 * Other officers who have Finance permission can review only.
 */
$canManageExpenses = ($adminPosition === 'Treasurer');

/* =========================
   CSRF
   ========================= */
if (empty($_SESSION['csrf_finance_expenses'])) {
  $_SESSION['csrf_finance_expenses'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_finance_expenses'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedCsrf = (string)($_POST['csrf'] ?? '');

  if ($postedCsrf === '' || !hash_equals($csrfToken, $postedCsrf)) {
    financeExpenseFlash(
      'danger',
      'Your session token is no longer valid. Please refresh the page and try again.'
    );
    financeExpenseRedirect($canPickPhase, $phase);
  }
}

/* =========================
   RECORD EXPENSE
   ========================= */
if (isset($_POST['add_expense'])) {
  if (!$canManageExpenses) {
    financeExpenseFlash(
      'danger',
      'Only the Treasurer can record expenses.'
    );
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $category = trim((string)($_POST['category'] ?? ''));
  $allowedCategories = [
    'maintenance',
    'security',
    'utilities',
    'other'
  ];

  if (!in_array($category, $allowedCategories, true)) {
    financeExpenseFlash(
      'danger',
      'Please select a valid expense category.'
    );
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $description = mb_substr(
    trim((string)($_POST['description'] ?? '')),
    0,
    255
  );

  $amount = (float)($_POST['amount'] ?? 0);
  $expenseDate = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));

  if ($description === '') {
    financeExpenseFlash(
      'danger',
      'Expense description is required.'
    );
    financeExpenseRedirect($canPickPhase, $phase);
  }

  if (!is_finite($amount) || $amount <= 0 || $amount > 10000000) {
    financeExpenseFlash(
      'danger',
      'Please enter a valid expense amount.'
    );
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $dateObj = DateTime::createFromFormat('Y-m-d', $expenseDate);
  $validDate =
    $dateObj &&
    $dateObj->format('Y-m-d') === $expenseDate;

  if (!$validDate) {
    financeExpenseFlash(
      'danger',
      'Please enter a valid expense date.'
    );
    financeExpenseRedirect($canPickPhase, $phase);
  }

  if ($expenseDate > date('Y-m-d')) {
    financeExpenseFlash(
      'danger',
      'An expense cannot be recorded with a future date.'
    );
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $upload = uploadExpenseReceipt($_FILES['receipt'] ?? []);

  if (!$upload['ok']) {
    financeExpenseFlash(
      'danger',
      (string)($upload['message'] ?? 'Receipt upload failed.')
    );
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $receiptPath = $upload['path'] ?? null;
  $absoluteReceiptPath = $upload['absolute_path'] ?? null;

  try {
    $stmt = $conn->prepare("
      INSERT INTO finance_expenses
      (
        phase,
        category,
        description,
        amount,
        expense_date,
        receipt_path,
        created_by_admin_id
      )
      VALUES (?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
      "sssdssi",
      $phase,
      $category,
      $description,
      $amount,
      $expenseDate,
      $receiptPath,
      $adminId
    );

    $stmt->execute();
    $stmt->close();

    financeExpenseFlash(
      'success',
      'Expense recorded successfully.'
    );

  } catch (Throwable $e) {
    if ($absoluteReceiptPath && is_file($absoluteReceiptPath)) {
      @unlink($absoluteReceiptPath);
    }

    error_log(
      'Finance expense insert failed: ' .
      $e->getMessage()
    );

    financeExpenseFlash(
      'danger',
      'The expense could not be recorded. Please try again.'
    );
  }

  financeExpenseRedirect($canPickPhase, $phase);
}

/* =========================
   EXPENSE LIST
   ========================= */
$stmt = $conn->prepare("
  SELECT
    e.*,
    a.full_name AS recorded_by_name,
    a.position AS recorded_by_position
  FROM finance_expenses e
  LEFT JOIN admins a
    ON a.id = e.created_by_admin_id
  WHERE e.phase = ?
  ORDER BY
    e.expense_date DESC,
    e.id DESC
  LIMIT 300
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalExpenses = 0.0;

$categoryTotals = [
  'maintenance' => 0.0,
  'security' => 0.0,
  'utilities' => 0.0,
  'other' => 0.0
];

foreach ($rows as $row) {
  $rowAmount = (float)($row['amount'] ?? 0);
  $totalExpenses += $rowAmount;

  $rowCategory = (string)($row['category'] ?? 'other');

  if (isset($categoryTotals[$rowCategory])) {
    $categoryTotals[$rowCategory] += $rowAmount;
  }
}

$financeFlash = $_SESSION['finance_expense_flash'] ?? null;
unset($_SESSION['finance_expense_flash']);
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
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

	<!-- Global site tag (gtag.js) - Google Analytics -->
	 <!-- Include CSS for DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

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
     FINANCE EXPENSES - DARK MODE EXTENSIONS
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
  html.dark .card-box .h4,
  html.dark .card-box label,
  html.dark .card-box b,
  html.dark .footer-wrap {
    color: var(--admin-text) !important;
  }

  html.dark .text-secondary,
  html.dark .text-muted,
  html.dark small.text-secondary {
    color: var(--admin-muted) !important;
  }

  html.dark hr {
    border-top-color: var(--admin-border) !important;
  }

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
    box-shadow: 0 0 0 .2rem rgba(59, 130, 246, .16) !important;
  }

  html.dark .form-control::placeholder {
    color: #64748b !important;
  }

  html.dark input[type="date"] {
    color-scheme: dark;
  }

  html.dark .alert-light {
    background: var(--admin-surface-2) !important;
    color: var(--admin-text) !important;
    border-color: var(--admin-border) !important;
  }

  html.dark .alert-info {
    background: rgba(14, 165, 233, .12) !important;
    color: #bae6fd !important;
    border-color: rgba(14, 165, 233, .28) !important;
  }

  html.dark .alert-success {
    background: rgba(22, 163, 74, .12) !important;
    color: #bbf7d0 !important;
    border-color: rgba(34, 197, 94, .28) !important;
  }

  html.dark .alert-danger {
    background: rgba(220, 38, 38, .12) !important;
    color: #fecaca !important;
    border-color: rgba(239, 68, 68, .28) !important;
  }

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
    color: #ffffff !important;
  }

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
    color: #ffffff !important;
    border-color: #2563eb !important;
    background: #2563eb !important;
  }

  html.dark .dataTables_wrapper .dataTables_paginate .paginate_button.disabled,
  html.dark .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
    color: #64748b !important;
    background: transparent !important;
    border-color: transparent !important;
  }

  html.dark .btn-outline-primary {
    color: #93c5fd !important;
    border-color: #3b82f6 !important;
  }

  html.dark .btn-outline-primary:hover {
    color: #ffffff !important;
    background: #2563eb !important;
    border-color: #2563eb !important;
  }
</style>

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
								<li>
									<a href="#">
										<img src="vendors/images/img.jpg" alt="">
										<h3>John Doe</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/photo1.jpg" alt="">
										<h3>Lea R. Frith</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/photo2.jpg" alt="">
										<h3>Erik L. Richards</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/photo3.jpg" alt="">
										<h3>John Doe</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/photo4.jpg" alt="">
										<h3>Renee I. Hansen</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/img.jpg" alt="">
										<h3>Vicki M. Coleman</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
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
					<a href="javascript:void(0);" class="btn btn-outline-primary sidebar-light ">White</a>
					<a href="javascript:void(0);" class="btn btn-outline-primary sidebar-dark active">Dark</a>
				</div>

				<h4 class="weight-600 font-18 pb-10">Menu Dropdown Icon</h4>
				<div class="sidebar-radio-group pb-10 mb-10">
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebaricon-1" name="menu-dropdown-icon" class="custom-control-input" value="icon-style-1" checked="">
						<label class="custom-control-label" for="sidebaricon-1"><i class="fa fa-angle-down"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebaricon-2" name="menu-dropdown-icon" class="custom-control-input" value="icon-style-2">
						<label class="custom-control-label" for="sidebaricon-2"><i class="ion-plus-round"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebaricon-3" name="menu-dropdown-icon" class="custom-control-input" value="icon-style-3">
						<label class="custom-control-label" for="sidebaricon-3"><i class="fa fa-angle-double-right"></i></label>
					</div>
				</div>

				<h4 class="weight-600 font-18 pb-10">Menu List Icon</h4>
				<div class="sidebar-radio-group pb-30 mb-10">
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebariconlist-1" name="menu-list-icon" class="custom-control-input" value="icon-list-style-1" checked="">
						<label class="custom-control-label" for="sidebariconlist-1"><i class="ion-minus-round"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebariconlist-2" name="menu-list-icon" class="custom-control-input" value="icon-list-style-2">
						<label class="custom-control-label" for="sidebariconlist-2"><i class="fa fa-circle-o" aria-hidden="true"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebariconlist-3" name="menu-list-icon" class="custom-control-input" value="icon-list-style-3">
						<label class="custom-control-label" for="sidebariconlist-3"><i class="dw dw-check"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebariconlist-4" name="menu-list-icon" class="custom-control-input" value="icon-list-style-4" checked="">
						<label class="custom-control-label" for="sidebariconlist-4"><i class="icon-copy dw dw-next-2"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebariconlist-5" name="menu-list-icon" class="custom-control-input" value="icon-list-style-5">
						<label class="custom-control-label" for="sidebariconlist-5"><i class="dw dw-fast-forward-1"></i></label>
					</div>
					<div class="custom-control custom-radio custom-control-inline">
						<input type="radio" id="sidebariconlist-6" name="menu-list-icon" class="custom-control-input" value="icon-list-style-6">
						<label class="custom-control-label" for="sidebariconlist-6"><i class="dw dw-next"></i></label>
					</div>
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
          <div class="title"><h4>Expense Tracking</h4></div>
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
      <div class="row">
        <div class="col-md-4 mb-3 mb-md-0">
          <div class="text-secondary">Total Expenses</div>
          <div class="h4 mb-0">
            ₱ <?= number_format($totalExpenses, 2) ?>
          </div>
        </div>

        <div class="col-md-8">
          <div class="d-flex flex-wrap justify-content-md-end">
            <span class="badge badge-light border p-2 mr-2 mb-2">
              Maintenance: ₱ <?= number_format($categoryTotals['maintenance'], 2) ?>
            </span>

            <span class="badge badge-light border p-2 mr-2 mb-2">
              Security: ₱ <?= number_format($categoryTotals['security'], 2) ?>
            </span>

            <span class="badge badge-light border p-2 mr-2 mb-2">
              Utilities: ₱ <?= number_format($categoryTotals['utilities'], 2) ?>
            </span>

            <span class="badge badge-light border p-2 mb-2">
              Other: ₱ <?= number_format($categoryTotals['other'], 2) ?>
            </span>
          </div>
        </div>
      </div>

      <hr>

      <div class="d-flex flex-wrap justify-content-between align-items-center">
        <div class="text-secondary">
          Signed in as:
          <b><?= esc($adminPosition !== '' ? $adminPosition : $adminRole) ?></b>
        </div>

        <?php if ($canManageExpenses): ?>
          <span class="badge badge-success p-2">Treasurer • Manage</span>
        <?php else: ?>
          <span class="badge badge-secondary p-2">View Only</span>
        <?php endif; ?>
      </div>

      <?php if (!$canManageExpenses): ?>
        <div class="alert alert-info mt-3 mb-0">
          Expense recording is managed by the Treasurer.
          You can still review expense history for your permitted phase.
        </div>
      <?php endif; ?>
    </div>

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">Record Expense</h5>
      <?php if ($canManageExpenses): ?>
      <form method="post" enctype="multipart/form-data">
        <input
          type="hidden"
          name="csrf"
          value="<?= esc($csrfToken) ?>"
        >

        <div class="row">
          <div class="col-md-3">
            <label>Category</label>
            <select name="category" class="form-control" required>
              <option value="maintenance">Maintenance</option>
              <option value="security">Security</option>
              <option value="utilities">Utilities</option>
              <option value="other" selected>Other</option>
            </select>
          </div>

          <div class="col-md-5">
            <label>Description</label>
            <input
              name="description"
              class="form-control"
              maxlength="255"
              placeholder="What was the expense for?"
              required
            >
          </div>

          <div class="col-md-2">
            <label>Amount</label>
            <input
              name="amount"
              class="form-control"
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
              name="expense_date"
              class="form-control"
              type="date"
              max="<?= esc(date('Y-m-d')) ?>"
              value="<?= esc(date('Y-m-d')) ?>"
              required
            >
          </div>

          <div class="col-md-12 mt-2">
            <label>Receipt (JPG / PNG / PDF)</label>
            <input
              type="file"
              name="receipt"
              class="form-control"
              accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
            >
            <small class="text-secondary">
              Optional. Maximum file size: 5MB.
            </small>
          </div>
        </div>

        <button
          class="btn btn-danger mt-3"
          name="add_expense"
          value="1"
        >
          Save Expense
        </button>
      </form>
    <?php else: ?>
      <div class="alert alert-light border mb-0">
        Expense recording is available to the Treasurer only.
      </div>
    <?php endif; ?>
    </div>

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">Expenses List</h5>
      <div class="table-responsive">
        <table id="expTable" class="table table-striped table-hover">
          <thead>
            <tr>
              <th>Date</th>
              <th>Category</th>
              <th>Description</th>
              <th>Amount</th>
              <th>Recorded By</th>
              <th>Receipt</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?=esc($r['expense_date'])?></td>
                <td><?=esc($r['category'])?></td>
                <td><?=esc($r['description'])?></td>
                <td>₱ <?=number_format((float)$r['amount'],2)?></td>

                <td>
                  <?php if (!empty($r['recorded_by_name'])): ?>
                    <?= esc($r['recorded_by_name']) ?>

                    <?php if (!empty($r['recorded_by_position'])): ?>
                      <div class="small text-secondary">
                        <?= esc($r['recorded_by_position']) ?>
                      </div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-secondary">Legacy / Unknown</span>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if (!empty($r['receipt_path'])): ?>
                    <a
                      class="btn btn-sm btn-outline-primary"
                      href="<?= esc($r['receipt_path']) ?>"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      View
                    </a>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
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

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function(){
  $('#expTable').DataTable({ pageLength: 25, order: [[0,'desc']] });
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