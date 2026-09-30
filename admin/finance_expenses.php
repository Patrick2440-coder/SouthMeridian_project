<?php

require_once __DIR__ . "/finance_helpers.php";

$financeAuditLogger = __DIR__ . "/finance_audit_logger.php";
if (is_file($financeAuditLogger)) {
  require_once $financeAuditLogger;
}

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
      'ok' => false,
      'message' => 'Proof of expense is required. Please upload a receipt, invoice, or other valid proof.'
    ];
  }

  if ((int)$file['error'] !== UPLOAD_ERR_OK) {
    return [
      'ok' => false,
      'message' => 'Proof upload failed. Please select the file again.'
    ];
  }

  $tmpName = (string)($file['tmp_name'] ?? '');
  $fileSize = (int)($file['size'] ?? 0);
  $originalName = trim((string)($file['name'] ?? ''));

  if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    return [
      'ok' => false,
      'message' => 'The uploaded proof could not be verified.'
    ];
  }

  if ($fileSize <= 0 || $fileSize > (5 * 1024 * 1024)) {
    return [
      'ok' => false,
      'message' => 'Proof must be 5MB or smaller.'
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
      'message' => 'Invalid proof file type. Use JPG, PNG, or PDF only.'
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
      'message' => 'Proof storage is unavailable.'
    ];
  }

  $safeName = bin2hex(random_bytes(16)) . '.' . $allowedMime[$mime];
  $absolutePath = $uploadDir . $safeName;

  if (!move_uploaded_file($tmpName, $absolutePath)) {
    return [
      'ok' => false,
      'message' => 'The proof could not be saved.'
    ];
  }

  @chmod($absolutePath, 0644);

  return [
    'ok' => true,
    'path' => $uploadUrl . $safeName,
    'absolute_path' => $absolutePath,
    'original_name' => mb_substr($originalName, 0, 255),
    'mime' => mb_substr($mime, 0, 100),
    'size_bytes' => $fileSize
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
    financeExpenseFlash('danger', 'Only the Treasurer can record expenses.');
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $category = trim((string)($_POST['category'] ?? ''));
  $allowedCategories = ['maintenance', 'security', 'utilities', 'other'];

  if (!in_array($category, $allowedCategories, true)) {
    financeExpenseFlash('danger', 'Please select a valid expense category.');
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $detailType = trim((string)($_POST['detail_type'] ?? 'general'));
  if (!in_array($detailType, ['general', 'tools_materials'], true)) {
    financeExpenseFlash('danger', 'Please select a valid expense detail type.');
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $vendorPayee = mb_substr(trim((string)($_POST['vendor_payee'] ?? '')), 0, 150);
  if ($vendorPayee === '') {
    financeExpenseFlash('danger', 'Supplier / payee is required for proper expense documentation.');
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $requestedBy = mb_substr(
    trim((string)($_POST['requested_by'] ?? '')),
    0,
    150
  );

  $projectName = mb_substr(
    trim((string)($_POST['project_name'] ?? '')),
    0,
    180
  );

  if ($requestedBy === '') {
    financeExpenseFlash(
      'danger',
      'Please indicate who requested the project, purchase, or item.'
    );
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $referenceNo = mb_substr(trim((string)($_POST['reference_no'] ?? '')), 0, 100);

  $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
  $allowedPaymentMethods = ['cash', 'gcash', 'bank_transfer', 'check', 'other'];

  if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
    financeExpenseFlash('danger', 'Please select a valid payment method.');
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255);
  if ($description === '') {
    financeExpenseFlash('danger', 'Expense purpose / description is required.');
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 2000);
  $expenseDate = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));

  $dateObj = DateTime::createFromFormat('Y-m-d', $expenseDate);
  $validDate = $dateObj && $dateObj->format('Y-m-d') === $expenseDate;

  if (!$validDate) {
    financeExpenseFlash('danger', 'Please enter a valid expense date.');
    financeExpenseRedirect($canPickPhase, $phase);
  }

  if ($expenseDate > date('Y-m-d')) {
    financeExpenseFlash('danger', 'An expense cannot be recorded with a future date.');
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $items = [];
  $amount = 0.0;

  if ($detailType === 'tools_materials') {
    $itemNames = is_array($_POST['item_name'] ?? null) ? $_POST['item_name'] : [];
    $itemQtys = is_array($_POST['item_qty'] ?? null) ? $_POST['item_qty'] : [];
    $itemUnits = is_array($_POST['item_unit'] ?? null) ? $_POST['item_unit'] : [];
    $itemUnitCosts = is_array($_POST['item_unit_cost'] ?? null) ? $_POST['item_unit_cost'] : [];
    $itemNotes = is_array($_POST['item_notes'] ?? null) ? $_POST['item_notes'] : [];

    $maxItems = min(count($itemNames), 100);

    for ($i = 0; $i < $maxItems; $i++) {
      $itemName = mb_substr(trim((string)($itemNames[$i] ?? '')), 0, 150);
      $qty = (float)($itemQtys[$i] ?? 0);
      $unit = mb_substr(trim((string)($itemUnits[$i] ?? 'pc')), 0, 30);
      $unitCost = (float)($itemUnitCosts[$i] ?? 0);
      $itemNote = mb_substr(trim((string)($itemNotes[$i] ?? '')), 0, 255);

      if ($itemName === '' && $qty <= 0 && $unitCost <= 0) {
        continue;
      }

      if ($itemName === '') {
        financeExpenseFlash('danger', 'Every Tools & Materials row must include the material or tool name.');
        financeExpenseRedirect($canPickPhase, $phase);
      }

      if (!is_finite($qty) || $qty <= 0 || $qty > 1000000) {
        financeExpenseFlash('danger', 'Every Tools & Materials row must have a valid quantity.');
        financeExpenseRedirect($canPickPhase, $phase);
      }

      if ($unit === '') {
        $unit = 'pc';
      }

      if (!is_finite($unitCost) || $unitCost < 0 || $unitCost > 10000000) {
        financeExpenseFlash('danger', 'Every Tools & Materials row must have a valid unit cost.');
        financeExpenseRedirect($canPickPhase, $phase);
      }

      $lineTotal = round($qty * $unitCost, 2);

      $items[] = [
        'item_name' => $itemName,
        'quantity' => $qty,
        'unit' => $unit,
        'unit_cost' => $unitCost,
        'line_total' => $lineTotal,
        'notes' => $itemNote
      ];

      $amount += $lineTotal;
    }

    $amount = round($amount, 2);

    if (!$items) {
      financeExpenseFlash('danger', 'Tools & Materials expenses must include at least one item.');
      financeExpenseRedirect($canPickPhase, $phase);
    }

    if ($amount <= 0) {
      financeExpenseFlash('danger', 'The total Tools & Materials amount must be greater than zero.');
      financeExpenseRedirect($canPickPhase, $phase);
    }

  } else {
    $amount = round((float)($_POST['amount'] ?? 0), 2);

    if (!is_finite($amount) || $amount <= 0 || $amount > 10000000) {
      financeExpenseFlash('danger', 'Please enter a valid expense amount.');
      financeExpenseRedirect($canPickPhase, $phase);
    }
  }

  // Proof is mandatory for every NEW expense.
  $upload = uploadExpenseReceipt($_FILES['receipt'] ?? []);

  if (!$upload['ok']) {
    financeExpenseFlash(
      'danger',
      (string)($upload['message'] ?? 'Proof upload failed.')
    );
    financeExpenseRedirect($canPickPhase, $phase);
  }

  $receiptPath = (string)($upload['path'] ?? '');
  $absoluteReceiptPath = $upload['absolute_path'] ?? null;
  $proofOriginalName = (string)($upload['original_name'] ?? '');
  $proofMime = (string)($upload['mime'] ?? '');
  $proofSizeBytes = (int)($upload['size_bytes'] ?? 0);

  try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("
      INSERT INTO finance_expenses
      (
        phase,
        category,
        detail_type,
        vendor_payee,
        requested_by,
        project_name,
        reference_no,
        payment_method,
        description,
        notes,
        amount,
        expense_date,
        receipt_path,
        proof_original_name,
        proof_mime,
        proof_size_bytes,
        created_by_admin_id
      )
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
      "ssssssssssdssssii",
      $phase,
      $category,
      $detailType,
      $vendorPayee,
      $requestedBy,
      $projectName,
      $referenceNo,
      $paymentMethod,
      $description,
      $notes,
      $amount,
      $expenseDate,
      $receiptPath,
      $proofOriginalName,
      $proofMime,
      $proofSizeBytes,
      $adminId
    );

    $stmt->execute();
    $expenseId = (int)$conn->insert_id;
    $stmt->close();

    if ($detailType === 'tools_materials') {
      $itemStmt = $conn->prepare("
        INSERT INTO finance_expense_items
        (
          expense_id,
          item_name,
          quantity,
          unit,
          unit_cost,
          line_total,
          notes
        )
        VALUES (?,?,?,?,?,?,?)
      ");

      foreach ($items as $item) {
        $itemName = (string)$item['item_name'];
        $qty = (float)$item['quantity'];
        $unit = (string)$item['unit'];
        $unitCost = (float)$item['unit_cost'];
        $lineTotal = (float)$item['line_total'];
        $itemNote = (string)$item['notes'];

        $itemStmt->bind_param(
          "isdsdds",
          $expenseId,
          $itemName,
          $qty,
          $unit,
          $unitCost,
          $lineTotal,
          $itemNote
        );
        $itemStmt->execute();
      }

      $itemStmt->close();
    }

    $conn->commit();

    if (function_exists('finance_audit_log')) {
      finance_audit_log(
        $conn,
        'Expense recorded',
        'expense',
        $expenseId,
        'Documented finance expense recorded with required proof.',
        null,
        [
          'phase' => $phase,
          'category' => $category,
          'detail_type' => $detailType,
          'vendor_payee' => $vendorPayee,
          'requested_by' => $requestedBy,
          'project_name' => $projectName,
          'reference_no' => $referenceNo,
          'payment_method' => $paymentMethod,
          'description' => $description,
          'notes' => $notes,
          'amount' => $amount,
          'expense_date' => $expenseDate,
          'proof_path' => $receiptPath,
          'proof_original_name' => $proofOriginalName,
          'items' => $items
        ],
        null,
        $adminId,
        $phase
      );
    }

    financeExpenseFlash('success', 'Expense documented successfully with proof.');

  } catch (Throwable $e) {
    $conn->rollback();

    if ($absoluteReceiptPath && is_file($absoluteReceiptPath)) {
      @unlink($absoluteReceiptPath);
    }

    error_log('Finance expense insert failed: ' . $e->getMessage());

    financeExpenseFlash(
      'danger',
      'The expense could not be recorded. Make sure the expense database update has been installed, then try again.'
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

$itemsByExpense = [];

if ($rows) {
  $expenseIds = [];

  foreach ($rows as $row) {
    $expenseId = (int)($row['id'] ?? 0);
    if ($expenseId > 0) {
      $expenseIds[] = $expenseId;
    }
  }

  if ($expenseIds) {
    $idList = implode(',', array_map('intval', $expenseIds));

    $itemResult = $conn->query("
      SELECT
        id,
        expense_id,
        item_name,
        quantity,
        unit,
        unit_cost,
        line_total,
        notes
      FROM finance_expense_items
      WHERE expense_id IN ($idList)
      ORDER BY expense_id, id
    ");

    if ($itemResult) {
      while ($itemRow = $itemResult->fetch_assoc()) {
        $expenseId = (int)($itemRow['expense_id'] ?? 0);

        if (!isset($itemsByExpense[$expenseId])) {
          $itemsByExpense[$expenseId] = [];
        }

        $itemsByExpense[$expenseId][] = $itemRow;
      }

      $itemResult->free();
    }
  }
}

$totalExpenses = 0.0;

$categoryTotals = [
  'maintenance' => 0.0,
  'security' => 0.0,
  'utilities' => 0.0,
  'other' => 0.0
];

$categoryLabels = [
  'maintenance' => 'Maintenance',
  'security' => 'Security',
  'utilities' => 'Utilities',
  'other' => 'Other'
];

$paymentMethodLabels = [
  'cash' => 'Cash',
  'gcash' => 'GCash',
  'bank_transfer' => 'Bank Transfer',
  'check' => 'Check',
  'other' => 'Other'
];

$expenseDetailsPayload = [];

foreach ($rows as $row) {
  $rowAmount = (float)($row['amount'] ?? 0);
  $totalExpenses += $rowAmount;

  $rowCategory = (string)($row['category'] ?? 'other');

  if (isset($categoryTotals[$rowCategory])) {
    $categoryTotals[$rowCategory] += $rowAmount;
  }

  $expenseId = (int)($row['id'] ?? 0);
  $paymentMethodKey = (string)($row['payment_method'] ?? '');

  $expenseDetailsPayload[$expenseId] = [
    'id' => $expenseId,
    'date' => (string)($row['expense_date'] ?? ''),
    'category' => $categoryLabels[$rowCategory] ?? ucfirst($rowCategory),
    'detail_type' => (($row['detail_type'] ?? 'general') === 'tools_materials')
      ? 'Tools & Materials'
      : 'General Expense',
    'vendor_payee' => (string)($row['vendor_payee'] ?? ''),
    'requested_by' => (string)($row['requested_by'] ?? ''),
    'project_name' => (string)($row['project_name'] ?? ''),
    'reference_no' => (string)($row['reference_no'] ?? ''),
    'payment_method' => $paymentMethodLabels[$paymentMethodKey] ?? 'Not recorded',
    'description' => (string)($row['description'] ?? ''),
    'notes' => (string)($row['notes'] ?? ''),
    'amount' => $rowAmount,
    'receipt_path' => (string)($row['receipt_path'] ?? ''),
    'proof_original_name' => (string)($row['proof_original_name'] ?? ''),
    'proof_mime' => (string)($row['proof_mime'] ?? ''),
    'proof_size_bytes' => (int)($row['proof_size_bytes'] ?? 0),
    'recorded_by' => (string)($row['recorded_by_name'] ?? 'Legacy / Unknown'),
    'recorded_by_position' => (string)($row['recorded_by_position'] ?? ''),
    'items' => $itemsByExpense[$expenseId] ?? []
  ];
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



  /* =========================================================
     DOCUMENTED EXPENSE RECORDING
     ========================================================= */

  .expense-doc-card {
    border-radius: 14px;
  }

  .expense-section-title {
    font-weight: 700;
    font-size: 15px;
    margin-bottom: 14px;
  }

  .expense-field-help {
    display: block;
    margin-top: 5px;
    line-height: 1.35;
  }

  .required-mark {
    color: #dc3545;
    font-weight: 700;
  }

  .proof-required-box {
    border: 1px dashed #dc3545;
    border-radius: 10px;
    padding: 14px;
    background: rgba(220, 53, 69, .04);
  }

  .tools-materials-wrap {
    display: none;
    border: 1px solid rgba(108, 117, 125, .25);
    border-radius: 12px;
    padding: 16px;
    margin-top: 16px;
  }

  .tools-materials-wrap.is-visible {
    display: block;
  }

  .expense-item-row {
    padding: 12px 0;
    border-bottom: 1px solid rgba(108, 117, 125, .18);
  }

  .expense-item-row:last-child {
    border-bottom: 0;
  }

  .expense-item-total {
    min-height: 38px;
    display: flex;
    align-items: center;
    font-weight: 700;
  }

  .expense-total-box {
    border-radius: 10px;
    padding: 12px 14px;
    background: rgba(13, 110, 253, .06);
  }

  .expense-proof-badge {
    font-size: 11px;
    padding: 5px 7px;
  }

  .expense-details-modal .modal-dialog {
    max-width: 920px;
  }

  .expense-detail-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .03em;
    font-weight: 700;
    color: #6c757d;
    margin-bottom: 3px;
  }

  .expense-detail-value {
    margin-bottom: 16px;
    word-break: break-word;
  }

  .expense-details-items th,
  .expense-details-items td {
    vertical-align: middle;
  }

  html.dark .tools-materials-wrap {
    border-color: var(--admin-border) !important;
    background: rgba(15, 23, 42, .2);
  }

  html.dark .expense-item-row {
    border-bottom-color: var(--admin-border) !important;
  }

  html.dark .expense-total-box {
    background: rgba(37, 99, 235, .12);
    color: var(--admin-text);
  }

  html.dark .proof-required-box {
    background: rgba(220, 38, 38, .09);
    border-color: rgba(248, 113, 113, .55);
  }

  html.dark .expense-details-modal .modal-content,
  html.dark .expense-details-modal .modal-header,
  html.dark .expense-details-modal .modal-footer {
    background: var(--admin-surface) !important;
    color: var(--admin-text) !important;
    border-color: var(--admin-border) !important;
  }

  html.dark .expense-detail-label {
    color: var(--admin-muted) !important;
  }

  html.dark .expense-details-modal .close {
    color: #fff !important;
    text-shadow: none !important;
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



    <div class="card-box mb-20 p-4 expense-doc-card">

      <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
          <h5 class="mb-1">Record Documented Expense</h5>
          <div class="text-secondary">
            Every new expense must include complete details and proof.
          </div>
        </div>

        <span class="badge badge-danger p-2 mt-2 mt-md-0">
          Proof Required
        </span>
      </div>

      <?php if ($canManageExpenses): ?>

      <form method="post" enctype="multipart/form-data" id="expenseForm">

        <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">

        <div class="expense-section-title">1. Expense Information</div>

        <div class="row">

          <div class="col-lg-3 col-md-6 mb-3">
            <label>Category <span class="required-mark">*</span></label>
            <select name="category" class="form-control" required>
              <option value="maintenance">Maintenance</option>
              <option value="security">Security</option>
              <option value="utilities">Utilities</option>
              <option value="other" selected>Other</option>
            </select>
          </div>

          <div class="col-lg-3 col-md-6 mb-3">
            <label>Expense Detail Type <span class="required-mark">*</span></label>
            <select name="detail_type" id="detailType" class="form-control" required>
              <option value="general" selected>General Expense</option>
              <option value="tools_materials">Tools & Materials</option>
            </select>
            <small class="text-secondary expense-field-help">
              Use Tools & Materials for purchases that need an itemized list.
            </small>
          </div>

          <div class="col-lg-3 col-md-6 mb-3">
            <label>Expense Date <span class="required-mark">*</span></label>
            <input
              name="expense_date"
              class="form-control"
              type="date"
              max="<?= esc(date('Y-m-d')) ?>"
              value="<?= esc(date('Y-m-d')) ?>"
              required
            >
          </div>

          <div class="col-lg-3 col-md-6 mb-3">
            <label>Payment Method <span class="required-mark">*</span></label>
            <select name="payment_method" class="form-control" required>
              <option value="">Select method</option>
              <option value="cash">Cash</option>
              <option value="gcash">GCash</option>
              <option value="bank_transfer">Bank Transfer</option>
              <option value="check">Check</option>
              <option value="other">Other</option>
            </select>
          </div>

        </div>

        <hr>

        <div class="expense-section-title">2. Supplier / Payment Documentation</div>

        <div class="row">

          <div class="col-lg-3 col-md-6 mb-3">
            <label>Supplier / Payee <span class="required-mark">*</span></label>
            <input
              name="vendor_payee"
              class="form-control"
              maxlength="150"
              placeholder="Store, supplier, company, or person paid"
              required
            >
          </div>

          <div class="col-lg-3 col-md-6 mb-3">
            <label>Requested By <span class="required-mark">*</span></label>
            <input
              name="requested_by"
              class="form-control"
              maxlength="150"
              placeholder="Person / officer / committee who requested it"
              required
            >
            <small class="text-secondary expense-field-help">
              Who requested the project, purchase, service, tool, or material?
            </small>
          </div>

          <div class="col-lg-3 col-md-6 mb-3">
            <label>Project / Activity Name</label>
            <input
              name="project_name"
              class="form-control"
              maxlength="180"
              placeholder="Example: Clubhouse Roof Repair"
            >
            <small class="text-secondary expense-field-help">
              Optional. Use the same project name on related expenses so they can be reported together.
            </small>
          </div>

          <div class="col-lg-3 col-md-6 mb-3">
            <label>OR / Invoice / Reference No.</label>
            <input
              name="reference_no"
              class="form-control"
              maxlength="100"
              placeholder="Example: OR-001245"
            >
          </div>

          <div class="col-lg-12 col-md-12 mb-3">
            <label>Purpose / Description <span class="required-mark">*</span></label>
            <input
              name="description"
              class="form-control"
              maxlength="255"
              placeholder="Why was this expense necessary?"
              required
            >
          </div>

        </div>

        <div id="toolsMaterialsWrap" class="tools-materials-wrap">

          <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
            <div>
              <h6 class="mb-1">Tools & Materials Itemization</h6>
              <small class="text-secondary">
                Enter each material/tool, quantity, unit, and actual unit cost.
              </small>
            </div>

            <button type="button" class="btn btn-sm btn-outline-primary mt-2 mt-md-0" id="addExpenseItem">
              + Add Item
            </button>
          </div>

          <div id="expenseItems"></div>

          <div class="expense-total-box mt-3 d-flex justify-content-between align-items-center">
            <span class="weight-600">Calculated Tools / Materials Total</span>
            <span class="h5 mb-0" id="materialsGrandTotal">₱ 0.00</span>
          </div>

        </div>

        <div class="row mt-3">

          <div class="col-lg-4 col-md-6 mb-3">
            <label>Total Amount <span class="required-mark">*</span></label>
            <input
              name="amount"
              id="expenseAmount"
              class="form-control"
              type="number"
              step="0.01"
              min="0.01"
              max="10000000"
              required
            >
            <small class="text-secondary expense-field-help" id="amountHelp">
              Enter the total amount actually paid.
            </small>
          </div>

          <div class="col-lg-8 col-md-6 mb-3">
            <label>Additional Notes</label>
            <textarea
              name="notes"
              class="form-control"
              rows="3"
              maxlength="2000"
              placeholder="Optional approval context, project/location, specifications, or remarks."
            ></textarea>
          </div>

        </div>

        <hr>

        <div class="expense-section-title">3. Required Proof</div>

        <div class="proof-required-box">

          <label class="mb-2">
            Receipt / Invoice / Proof of Payment
            <span class="required-mark">*</span>
          </label>

          <input
            type="file"
            name="receipt"
            class="form-control"
            accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
            required
          >

          <small class="text-secondary expense-field-help">
            Required for every expense. JPG, PNG, or PDF only; maximum 5MB.
            For Tools & Materials, the proof should show the purchased items and their amounts whenever possible.
          </small>

        </div>

        <button class="btn btn-danger mt-4" name="add_expense" value="1">
          Save Documented Expense
        </button>

      </form>

      <?php else: ?>

        <div class="alert alert-light border mb-0">
          Expense recording is available to the Treasurer only.
        </div>

      <?php endif; ?>

    </div>



    <div class="card-box mb-20 p-3">

      <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
          <h5 class="mb-1">Expenses List</h5>
          <div class="text-secondary">
            Documented expense history for <?= esc($phase) ?>.
          </div>
        </div>
      </div>

      <div class="table-responsive">

        <table id="expTable" class="table table-striped table-hover">

          <thead>
            <tr>
              <th>Date</th>
              <th>Category</th>
              <th>Purpose / Supplier</th>
              <th>Type</th>
              <th>Amount</th>
              <th>Recorded By</th>
              <th>Proof</th>
              <th>Details</th>
            </tr>
          </thead>

          <tbody>

            <?php foreach($rows as $r): ?>

              <?php
                $expenseId = (int)($r['id'] ?? 0);
                $rowCategory = (string)($r['category'] ?? 'other');
                $detailType = (string)($r['detail_type'] ?? 'general');
              ?>

              <tr>

                <td><?= esc($r['expense_date']) ?></td>

                <td><?= esc($categoryLabels[$rowCategory] ?? ucfirst($rowCategory)) ?></td>

                <td>
                  <div class="weight-600"><?= esc($r['description']) ?></div>

                  <?php if (!empty($r['vendor_payee'])): ?>
                    <div class="small text-secondary">
                      Supplier / Payee: <?= esc($r['vendor_payee']) ?>
                    </div>
                  <?php endif; ?>

                  <?php if (!empty($r['requested_by'])): ?>
                    <div class="small text-secondary">
                      Requested By: <?= esc($r['requested_by']) ?>
                    </div>
                  <?php endif; ?>

                  <?php if (!empty($r['project_name'])): ?>
                    <div class="small text-secondary">
                      Project: <?= esc($r['project_name']) ?>
                    </div>
                  <?php endif; ?>

                  <?php if (!empty($r['reference_no'])): ?>
                    <div class="small text-secondary">
                      Ref: <?= esc($r['reference_no']) ?>
                    </div>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if ($detailType === 'tools_materials'): ?>
                    <span class="badge badge-warning p-2">Tools & Materials</span>
                    <div class="small text-secondary mt-1">
                      <?= count($itemsByExpense[$expenseId] ?? []) ?> item(s)
                    </div>
                  <?php else: ?>
                    <span class="badge badge-light border p-2">General</span>
                  <?php endif; ?>
                </td>

                <td class="weight-600">
                  ₱ <?= number_format((float)$r['amount'], 2) ?>
                </td>

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
                      View Proof
                    </a>

                    <div class="mt-1">
                      <span class="badge badge-success expense-proof-badge">Attached</span>
                    </div>

                  <?php else: ?>

                    <span class="badge badge-secondary expense-proof-badge">
                      Legacy: No Proof
                    </span>

                  <?php endif; ?>
                </td>

                <td>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary view-expense-details"
                    data-expense-id="<?= $expenseId ?>"
                  >
                    View Details
                  </button>
                </td>

              </tr>

            <?php endforeach; ?>

          </tbody>

        </table>

      </div>

    </div>



    <div
      class="modal fade expense-details-modal"
      id="expenseDetailsModal"
      tabindex="-1"
      role="dialog"
      aria-hidden="true"
    >
      <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">

          <div class="modal-header">
            <h5 class="modal-title">Expense Documentation</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>

          <div class="modal-body">

            <div class="row">
              <div class="col-md-4">
                <div class="expense-detail-label">Date</div>
                <div class="expense-detail-value" id="detailDate">-</div>
              </div>

              <div class="col-md-4">
                <div class="expense-detail-label">Category</div>
                <div class="expense-detail-value" id="detailCategory">-</div>
              </div>

              <div class="col-md-4">
                <div class="expense-detail-label">Type</div>
                <div class="expense-detail-value" id="detailTypeValue">-</div>
              </div>

              <div class="col-md-4">
                <div class="expense-detail-label">Supplier / Payee</div>
                <div class="expense-detail-value" id="detailVendor">-</div>
              </div>

              <div class="col-md-4">
                <div class="expense-detail-label">Requested By</div>
                <div class="expense-detail-value" id="detailRequestedBy">-</div>
              </div>

              <div class="col-md-4">
                <div class="expense-detail-label">Project / Activity</div>
                <div class="expense-detail-value" id="detailProject">-</div>
              </div>

              <div class="col-md-2">
                <div class="expense-detail-label">Payment Method</div>
                <div class="expense-detail-value" id="detailPaymentMethod">-</div>
              </div>

              <div class="col-md-2">
                <div class="expense-detail-label">Reference No.</div>
                <div class="expense-detail-value" id="detailReference">-</div>
              </div>

              <div class="col-md-12">
                <div class="expense-detail-label">Purpose / Description</div>
                <div class="expense-detail-value" id="detailDescription">-</div>
              </div>

              <div class="col-md-12">
                <div class="expense-detail-label">Additional Notes</div>
                <div class="expense-detail-value" id="detailNotes">-</div>
              </div>

              <div class="col-md-4">
                <div class="expense-detail-label">Total Amount</div>
                <div class="expense-detail-value h5" id="detailAmount">-</div>
              </div>

              <div class="col-md-4">
                <div class="expense-detail-label">Recorded By</div>
                <div class="expense-detail-value" id="detailRecordedBy">-</div>
              </div>

              <div class="col-md-4">
                <div class="expense-detail-label">Proof</div>
                <div class="expense-detail-value" id="detailProof">-</div>
              </div>
            </div>

            <div id="detailItemsSection" style="display:none;">
              <hr>
              <h6 class="mb-3">Tools & Materials Breakdown</h6>

              <div class="table-responsive">
                <table class="table table-sm table-bordered expense-details-items">
                  <thead>
                    <tr>
                      <th>Material / Tool</th>
                      <th>Qty</th>
                      <th>Unit</th>
                      <th>Unit Cost</th>
                      <th>Total</th>
                      <th>Notes</th>
                    </tr>
                  </thead>
                  <tbody id="detailItemsBody"></tbody>
                </table>
              </div>
            </div>

          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">
              Close
            </button>
          </div>

        </div>
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

const expenseDetails = <?= json_encode(
  $expenseDetailsPayload,
  JSON_UNESCAPED_UNICODE |
  JSON_UNESCAPED_SLASHES |
  JSON_HEX_TAG |
  JSON_HEX_AMP |
  JSON_HEX_APOS |
  JSON_HEX_QUOT
) ?>;

function moneyFormat(value) {
  const number = Number(value || 0);
  return '₱ ' + number.toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

function escapeHtml(value) {
  return $('<div>').text(value == null ? '' : String(value)).html();
}

function makeItemRow() {
  return `
    <div class="expense-item-row">
      <div class="row align-items-end">

        <div class="col-lg-3 col-md-6 form-group">
          <label>Material / Tool <span class="required-mark">*</span></label>
          <input type="text" name="item_name[]" class="form-control item-name" maxlength="150" placeholder="Example: PVC pipe">
        </div>

        <div class="col-lg-2 col-md-3 form-group">
          <label>Quantity <span class="required-mark">*</span></label>
          <input type="number" name="item_qty[]" class="form-control item-qty" min="0.01" step="0.01" value="1">
        </div>

        <div class="col-lg-1 col-md-3 form-group">
          <label>Unit</label>
          <input type="text" name="item_unit[]" class="form-control item-unit" maxlength="30" value="pc" placeholder="pc">
        </div>

        <div class="col-lg-2 col-md-4 form-group">
          <label>Unit Cost <span class="required-mark">*</span></label>
          <input type="number" name="item_unit_cost[]" class="form-control item-unit-cost" min="0" step="0.01" value="0.00">
        </div>

        <div class="col-lg-2 col-md-4 form-group">
          <label>Line Total</label>
          <div class="expense-item-total item-line-total">₱ 0.00</div>
        </div>

        <div class="col-lg-2 col-md-4 form-group">
          <label>&nbsp;</label>
          <button type="button" class="btn btn-outline-danger btn-block remove-expense-item">
            Remove
          </button>
        </div>

        <div class="col-md-12 form-group mb-0">
          <label>Item Notes</label>
          <input
            type="text"
            name="item_notes[]"
            class="form-control"
            maxlength="255"
            placeholder="Optional brand, size, specification, or purpose"
          >
        </div>

      </div>
    </div>
  `;
}

function recalculateExpenseItems() {
  let total = 0;

  $('#expenseItems .expense-item-row').each(function () {
    const qty = Number($(this).find('.item-qty').val() || 0);
    const unitCost = Number($(this).find('.item-unit-cost').val() || 0);
    const lineTotal = Math.round((qty * unitCost + Number.EPSILON) * 100) / 100;

    $(this).find('.item-line-total').text(moneyFormat(lineTotal));
    total += lineTotal;
  });

  total = Math.round((total + Number.EPSILON) * 100) / 100;

  $('#materialsGrandTotal').text(moneyFormat(total));
  $('#expenseAmount').val(total.toFixed(2));
}

function syncDetailType() {
  const toolsMaterials = $('#detailType').val() === 'tools_materials';

  $('#toolsMaterialsWrap').toggleClass('is-visible', toolsMaterials);
  $('#expenseAmount').prop('readonly', toolsMaterials);

  if (toolsMaterials) {
    $('#amountHelp').text('Calculated automatically from the Tools & Materials itemization.');

    if ($('#expenseItems .expense-item-row').length === 0) {
      $('#expenseItems').append(makeItemRow());
    }

    $('#expenseItems .item-name, #expenseItems .item-qty, #expenseItems .item-unit-cost')
      .prop('required', true);

    recalculateExpenseItems();
  } else {
    $('#amountHelp').text('Enter the total amount actually paid.');

    $('#expenseItems .item-name, #expenseItems .item-qty, #expenseItems .item-unit-cost')
      .prop('required', false);
  }
}


/* =========================================================
   EXPENSE VIEW DETAILS
   Native delegated handling - intentionally outside
   jQuery ready / DataTables so table redraws or plugin
   errors cannot disable the Details button.
   ========================================================= */

function setExpenseDetailText(id, value) {
  const element = document.getElementById(id);

  if (!element) {
    return;
  }

  const normalized =
    value === null ||
    typeof value === 'undefined' ||
    String(value).trim() === ''
      ? '-'
      : String(value);

  element.textContent = normalized;
}

function closeExpenseDetailsFallback() {

  const modalElement =
    document.getElementById('expenseDetailsModal');

  if (!modalElement) {
    return;
  }

  modalElement.style.display = 'none';
  modalElement.classList.remove('show');
  modalElement.setAttribute('aria-hidden', 'true');
  modalElement.removeAttribute('aria-modal');

  document.body.classList.remove('modal-open');

  document
    .querySelectorAll('.expense-manual-backdrop')
    .forEach(function (backdrop) {
      backdrop.remove();
    });
}

function openExpenseDetails(button) {

  const expenseId =
    String(
      button.getAttribute('data-expense-id') || ''
    );

  if (expenseId === '') {
    console.warn('The expense ID is missing from the Details button.');
    return;
  }

  const data =
    expenseDetails[expenseId] ||
    expenseDetails[Number(expenseId)];

  if (!data) {
    console.warn(
      'Expense details were not found for ID:',
      expenseId
    );
    return;
  }

  setExpenseDetailText('detailDate', data.date);
  setExpenseDetailText('detailCategory', data.category);
  setExpenseDetailText(
    'detailTypeValue',
    data.detail_type
  );
  setExpenseDetailText(
    'detailVendor',
    data.vendor_payee
  );
  setExpenseDetailText(
    'detailRequestedBy',
    data.requested_by
  );
  setExpenseDetailText(
    'detailProject',
    data.project_name
  );
  setExpenseDetailText(
    'detailPaymentMethod',
    data.payment_method
  );
  setExpenseDetailText(
    'detailReference',
    data.reference_no
  );
  setExpenseDetailText(
    'detailDescription',
    data.description
  );
  setExpenseDetailText(
    'detailNotes',
    data.notes
  );
  setExpenseDetailText(
    'detailAmount',
    moneyFormat(data.amount)
  );

  const recorder = [
    data.recorded_by || 'Legacy / Unknown',
    data.recorded_by_position || ''
  ]
    .filter(Boolean)
    .join(' • ');

  setExpenseDetailText(
    'detailRecordedBy',
    recorder
  );

  /* ---------- Proof ---------- */

  const proofContainer =
    document.getElementById('detailProof');

  if (proofContainer) {

    proofContainer.innerHTML = '';

    if (data.receipt_path) {

      const link =
        document.createElement('a');

      link.className =
        'btn btn-sm btn-outline-primary';

      link.href =
        String(data.receipt_path);

      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = 'View Proof';

      proofContainer.appendChild(link);

      if (data.proof_original_name) {

        const fileName =
          document.createElement('div');

        fileName.className =
          'small text-secondary mt-1';

        fileName.textContent =
          String(data.proof_original_name);

        proofContainer.appendChild(fileName);
      }

    } else {

      const legacyBadge =
        document.createElement('span');

      legacyBadge.className =
        'badge badge-secondary';

      legacyBadge.textContent =
        'Legacy: No Proof';

      proofContainer.appendChild(legacyBadge);
    }
  }

  /* ---------- Tools / Materials ---------- */

  const items =
    Array.isArray(data.items)
      ? data.items
      : [];

  const itemsBody =
    document.getElementById(
      'detailItemsBody'
    );

  const itemsSection =
    document.getElementById(
      'detailItemsSection'
    );

  if (itemsBody && itemsSection) {

    itemsBody.innerHTML = '';

    if (items.length > 0) {

      items.forEach(function (item) {

        const tr =
          document.createElement('tr');

        const cells = [
          item.item_name || '',
          item.quantity || '',
          item.unit || '',
          moneyFormat(item.unit_cost),
          moneyFormat(item.line_total),
          item.notes || ''
        ];

        cells.forEach(function (value) {

          const td =
            document.createElement('td');

          td.textContent =
            String(value);

          tr.appendChild(td);
        });

        itemsBody.appendChild(tr);
      });

      itemsSection.style.display = '';

    } else {

      itemsSection.style.display = 'none';
    }
  }

  /* ---------- Open Modal ---------- */

  const modalElement =
    document.getElementById(
      'expenseDetailsModal'
    );

  if (!modalElement) {
    console.error(
      'Expense Details modal element is missing.'
    );
    return;
  }

  /*
   * Prefer Bootstrap modal when DeskApp has finished
   * loading it. This lookup happens at CLICK TIME.
   */
  const jq = window.jQuery;

  if (
    jq &&
    jq.fn &&
    typeof jq.fn.modal === 'function'
  ) {
    jq(modalElement).modal('show');
    return;
  }

  /*
   * Guaranteed fallback if Bootstrap modal is unavailable.
   */
  modalElement.style.display = 'block';
  modalElement.classList.add('show');
  modalElement.setAttribute(
    'aria-modal',
    'true'
  );
  modalElement.removeAttribute(
    'aria-hidden'
  );

  document.body.classList.add(
    'modal-open'
  );

  const oldBackdrop =
    document.querySelector(
      '.expense-manual-backdrop'
    );

  if (oldBackdrop) {
    oldBackdrop.remove();
  }

  const backdrop =
    document.createElement('div');

  backdrop.className =
    'modal-backdrop fade show expense-manual-backdrop';

  document.body.appendChild(
    backdrop
  );
}


/*
 * Register this listener immediately.
 * It survives DataTables redraws because it listens on document.
 */
document.addEventListener(
  'click',
  function (event) {

    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    const detailsButton =
      target.closest(
        '.view-expense-details'
      );

    if (detailsButton) {

      event.preventDefault();
      event.stopPropagation();

      openExpenseDetails(
        detailsButton
      );

      return;
    }

    const closeButton =
      target.closest(
        '#expenseDetailsModal [data-dismiss="modal"]'
      );

    const manualBackdrop =
      target.closest(
        '.expense-manual-backdrop'
      );

    if (
      !closeButton &&
      !manualBackdrop
    ) {
      return;
    }

    /*
     * Let Bootstrap close its own modal when available.
     * Otherwise use our manual fallback.
     */
    const jq = window.jQuery;

    if (
      closeButton &&
      jq &&
      jq.fn &&
      typeof jq.fn.modal === 'function'
    ) {
      return;
    }

    event.preventDefault();

    closeExpenseDetailsFallback();
  },
  false
);


$(function(){

  // DataTables must never block the rest of the Expense page JavaScript.
  try {
    if (
      window.jQuery &&
      jQuery.fn &&
      typeof jQuery.fn.DataTable === 'function'
    ) {
      if (!jQuery.fn.DataTable.isDataTable('#expTable')) {
        jQuery('#expTable').DataTable({
          pageLength: 25,
          order: [[0, 'desc']]
        });
      }
    }
  } catch (dataTableError) {
    console.warn(
      'Expense table enhancement could not be initialized:',
      dataTableError
    );
  }

  $('#detailType').on('change', syncDetailType);

  $('#addExpenseItem').on('click', function () {
    $('#expenseItems').append(makeItemRow());
    syncDetailType();
  });

  $('#expenseItems').on(
    'input change',
    '.item-qty, .item-unit-cost',
    recalculateExpenseItems
  );

  $('#expenseItems').on('click', '.remove-expense-item', function () {
    $(this).closest('.expense-item-row').remove();

    if (
      $('#detailType').val() === 'tools_materials' &&
      $('#expenseItems .expense-item-row').length === 0
    ) {
      $('#expenseItems').append(makeItemRow());
    }

    syncDetailType();
  });

  $('#expenseForm').on('submit', function (event) {
    if ($('#detailType').val() === 'tools_materials') {
      recalculateExpenseItems();

      const amount = Number($('#expenseAmount').val() || 0);

      if (amount <= 0) {
        event.preventDefault();
        alert('Please enter valid Tools & Materials items before saving the expense.');
        return false;
      }
    }
  });

  syncDetailType();

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