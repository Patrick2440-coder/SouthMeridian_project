<?php

require_once __DIR__ . "/finance_helpers.php";

require_once 'admin_access.php';
require_once __DIR__ . '/finance_dues_migration_common.php';

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

function financeDuesRedirect(bool $canPickPhase, string $phase): void {

  $url = 'finance_dues.php';

  if ($canPickPhase) {

    $url .= '?phase=' . urlencode($phase);

  }

  header('Location: ' . $url);

  exit;

}

function financeDuesFlash(string $type, string $message): void {

  $_SESSION['finance_dues_flash'] = [

    'type' => $type,

    'message' => $message

  ];

}

function financeDuesMigrationStatusMeta(string $status): array {
  $map = [
    'ready' => ['Ready', 'success'],
    'duplicate' => ['Duplicate', 'secondary'],
    'conflict' => ['Payment Conflict', 'danger'],
    'unmatched' => ['No Match', 'warning'],
    'needs_review' => ['Needs Review', 'info'],
    'finalized' => ['Migrated', 'primary'],
    'skipped' => ['Skipped', 'dark'],
  ];
  return $map[$status] ?? [ucwords(str_replace('_', ' ', $status)), 'secondary'];
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

|--------------------------------------------------------------------------

| Finance write ownership

|--------------------------------------------------------------------------

| Treasurer manages dues and manual/cash payments.

| Other officers with Finance permission can still view the page.

| President approval is handled later in finance_reports.php.

*/

$canManageDues = ($adminPosition === 'Treasurer');

/* =========================

   CSRF

   ========================= */

if (empty($_SESSION['csrf_finance_dues'])) {

  $_SESSION['csrf_finance_dues'] = bin2hex(random_bytes(32));

}

$csrfToken = (string)$_SESSION['csrf_finance_dues'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $postedCsrf = (string)($_POST['csrf'] ?? '');

  if (

    $postedCsrf === '' ||

    !hash_equals($csrfToken, $postedCsrf)

  ) {

    financeDuesFlash(

      'danger',

      'Your session token is no longer valid. Please refresh the page and try again.'

    );

    financeDuesRedirect($canPickPhase, $phase);

  }

}

/* =========================

   CURRENT DUES SETTING

   ========================= */

$stmt = $conn->prepare("

  SELECT monthly_dues

  FROM finance_dues_settings

  WHERE phase = ?

  LIMIT 1

");

$stmt->bind_param("s", $phase);

$stmt->execute();

$monthly_dues = (float)(

  $stmt->get_result()->fetch_assoc()['monthly_dues']

  ?? 0

);

$stmt->close();

$currentYear = (int)date('Y');

$currentMonth = (int)date('n');

$currentPeriodKey = ($currentYear * 100) + $currentMonth;

/* =========================

   SAVE MONTHLY DUES SETTING

   ========================= */

if (isset($_POST['save_dues'])) {

  if (!$canManageDues) {

    financeDuesFlash(

      'danger',

      'Only the Treasurer can change the monthly dues amount.'

    );

    financeDuesRedirect($canPickPhase, $phase);

  }

  $dues = (float)($_POST['monthly_dues'] ?? 0);

  if ($dues < 0 || $dues > 1000000) {

    financeDuesFlash(

      'danger',

      'Please enter a valid monthly dues amount.'

    );

    financeDuesRedirect($canPickPhase, $phase);

  }

  $stmt = $conn->prepare("

    INSERT INTO finance_dues_settings

      (phase, monthly_dues, updated_by_admin_id)

    VALUES (?,?,?)

    ON DUPLICATE KEY UPDATE

      monthly_dues = VALUES(monthly_dues),

      updated_by_admin_id = VALUES(updated_by_admin_id)

  ");

  $stmt->bind_param(

    "sdi",

    $phase,

    $dues,

    $adminId

  );

  $stmt->execute();

  $stmt->close();

  if (function_exists('finance_audit_log')) {
    finance_audit_log(
      $conn,
      'Monthly dues setting changed',
      'finance_dues_setting',
      null,
      sprintf('Monthly dues for %s changed from %.2f to %.2f.', $phase, $monthly_dues, $dues),
      ['monthly_dues'=>$monthly_dues],
      ['monthly_dues'=>$dues],
      null,
      $adminId,
      $phase
    );
  }

  financeDuesFlash(

    'success',

    'Monthly dues amount updated successfully.'

  );

  financeDuesRedirect($canPickPhase, $phase);

}

/* =========================

   RECORD MANUAL / CASH PAYMENT

   ========================= */

if (isset($_POST['record_payment'])) {

  if (!$canManageDues) {

    financeDuesFlash(

      'danger',

      'Only the Treasurer can record manual monthly dues payments.'

    );

    financeDuesRedirect($canPickPhase, $phase);

  }

  $homeownerId = (int)($_POST['homeowner_id'] ?? 0);

  $year = (int)($_POST['pay_year'] ?? $currentYear);

  $month = (int)($_POST['pay_month'] ?? $currentMonth);

  $referenceNo = mb_substr(

    trim((string)($_POST['reference_no'] ?? '')),

    0,

    100

  );

  $notes = mb_substr(

    trim((string)($_POST['notes'] ?? '')),

    0,

    255

  );

  if ($monthly_dues <= 0) {

    financeDuesFlash(

      'danger',

      'Set the monthly dues amount first before recording a payment.'

    );

    financeDuesRedirect($canPickPhase, $phase);

  }

  if (

    $homeownerId <= 0 ||

    $year < 2000 ||

    $year > $currentYear ||

    $month < 1 ||

    $month > 12

  ) {

    financeDuesFlash(

      'danger',

      'Please select a valid homeowner and payment period.'

    );

    financeDuesRedirect($canPickPhase, $phase);

  }

  $paymentPeriodKey = ($year * 100) + $month;

  if ($paymentPeriodKey > $currentPeriodKey) {

    financeDuesFlash(

      'danger',

      'Future monthly dues cannot be marked as paid.'

    );

    financeDuesRedirect($canPickPhase, $phase);

  }

  $stmt = $conn->prepare("

    SELECT

      id,

      first_name,

      last_name,

      created_at

    FROM homeowners

    WHERE id = ?

      AND phase = ?

      AND status = 'approved'

    LIMIT 1

  ");

  $stmt->bind_param(

    "is",

    $homeownerId,

    $phase

  );

  $stmt->execute();

  $homeowner = $stmt->get_result()->fetch_assoc();

  $stmt->close();

  if (!$homeowner) {

    financeDuesFlash(

      'danger',

      'The selected homeowner is not an approved homeowner in this phase.'

    );

    financeDuesRedirect($canPickPhase, $phase);

  }

  $accountStartTs = strtotime(

    (string)($homeowner['created_at'] ?? '')

  );

  if (!$accountStartTs) {

    financeDuesFlash(

      'danger',

      'The homeowner account start date could not be verified.'

    );

    financeDuesRedirect($canPickPhase, $phase);

  }

  $accountStartKey =

    ((int)date('Y', $accountStartTs) * 100) +

    (int)date('n', $accountStartTs);

  if ($paymentPeriodKey < $accountStartKey) {

    financeDuesFlash(

      'danger',

      'This homeowner was not yet subject to monthly dues for the selected period.'

    );

    financeDuesRedirect($canPickPhase, $phase);

  }

  try {

    $conn->begin_transaction();

    $stmt = $conn->prepare("

      SELECT id, homeowner_id, phase, pay_year, pay_month, amount, status, paid_at, reference_no, notes, created_by_admin_id, created_at

      FROM finance_payments

      WHERE homeowner_id = ?

        AND pay_year = ?

        AND pay_month = ?

      LIMIT 1

      FOR UPDATE

    ");

    $stmt->bind_param(

      "iii",

      $homeownerId,

      $year,

      $month

    );

    $stmt->execute();

    $existingPayment = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (

      $existingPayment &&

      (string)$existingPayment['status'] === 'paid'

    ) {

      $conn->rollback();

      financeDuesFlash(

        'warning',

        'This homeowner already has a paid record for the selected month. No existing payment was changed.'

      );

      financeDuesRedirect($canPickPhase, $phase);

    }

    $amount = $monthly_dues;

    if ($existingPayment) {

      $paymentId = (int)$existingPayment['id'];

      $stmt = $conn->prepare("

        UPDATE finance_payments

        SET

          phase = ?,

          amount = ?,

          status = 'paid',

          paid_at = NOW(),

          reference_no = ?,

          notes = ?,

          created_by_admin_id = ?

        WHERE id = ?

          AND homeowner_id = ?

      ");

      $stmt->bind_param(

        "sdssiii",

        $phase,

        $amount,

        $referenceNo,

        $notes,

        $adminId,

        $paymentId,

        $homeownerId

      );

      $stmt->execute();

      $stmt->close();

    } else {

      $stmt = $conn->prepare("

        INSERT INTO finance_payments

        (

          homeowner_id,

          phase,

          pay_year,

          pay_month,

          amount,

          status,

          paid_at,

          reference_no,

          notes,

          created_by_admin_id

        )

        VALUES (

          ?,?,?,?,?,

          'paid',

          NOW(),

          ?,?,?

        )

      ");

      $stmt->bind_param(

        "isiidssi",

        $homeownerId,

        $phase,

        $year,

        $month,

        $amount,

        $referenceNo,

        $notes,

        $adminId

      );

      $stmt->execute();

      $paymentId = (int)$conn->insert_id;

      $stmt->close();

    }

    $conn->commit();

    $stmt = $conn->prepare("
      SELECT id, homeowner_id, phase, pay_year, pay_month, amount, status, paid_at, reference_no, notes, created_by_admin_id, created_at
      FROM finance_payments
      WHERE id=?
      LIMIT 1
    ");
    $stmt->bind_param("i", $paymentId);
    $stmt->execute();
    $afterPayment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (function_exists('finance_audit_log')) {
      finance_audit_log(
        $conn,
        $existingPayment ? 'Manual monthly dues payment updated' : 'Manual monthly dues payment recorded',
        'finance_payment',
        $paymentId,
        sprintf('Manual/cash payment recorded for homeowner #%d, %04d-%02d, amount %.2f.', $homeownerId, $year, $month, $amount),
        $existingPayment ?: null,
        $afterPayment ?: null,
        null,
        $adminId,
        $phase
      );
    }

    $homeownerName = trim(

      (string)($homeowner['first_name'] ?? '') .

      ' ' .

      (string)($homeowner['last_name'] ?? '')

    );

    financeDuesFlash(

      'success',

      'Payment recorded for ' .

      $homeownerName .

      ' — ' .

      date('F', mktime(0, 0, 0, $month, 1)) .

      ' ' .

      $year .

      '.'

    );

  } catch (Throwable $e) {

    try {

      $conn->rollback();

    } catch (Throwable $ignored) {

    }

    error_log(

      'Finance dues manual payment failed: ' .

      $e->getMessage()

    );

    financeDuesFlash(

      'danger',

      'The payment could not be recorded. Please try again.'

    );

  }

  financeDuesRedirect($canPickPhase, $phase);

}

/* =========================

   UNPAID LIST FILTER

   ========================= */

$selYear = (int)($_GET['year'] ?? $currentYear);

$selMonth = (int)($_GET['month'] ?? $currentMonth);

if (

  $selYear < 2000 ||

  $selYear > $currentYear

) {

  $selYear = $currentYear;

}

if (

  $selMonth < 1 ||

  $selMonth > 12

) {

  $selMonth = $currentMonth;

}

$selectedPeriodKey =

  ($selYear * 100) +

  $selMonth;

if ($selectedPeriodKey > $currentPeriodKey) {

  $selYear = $currentYear;

  $selMonth = $currentMonth;

}

$periodEnd = date(

  'Y-m-t',

  strtotime(

    sprintf(

      '%04d-%02d-01',

      $selYear,

      $selMonth

    )

  )

);

$stmt = $conn->prepare("

  SELECT

    h.id,

    h.first_name,

    h.last_name,

    h.house_lot_number,

    h.email,

    h.created_at

  FROM homeowners h

  LEFT JOIN finance_payments p

    ON p.homeowner_id = h.id

   AND p.phase = h.phase

   AND p.pay_year = ?

   AND p.pay_month = ?

   AND p.status = 'paid'

  WHERE h.phase = ?

    AND h.status = 'approved'

    AND DATE(h.created_at) <= ?

    AND p.id IS NULL

  ORDER BY

    h.last_name,

    h.first_name

");

$stmt->bind_param(

  "iiss",

  $selYear,

  $selMonth,

  $phase,

  $periodEnd

);

$stmt->execute();

$unpaid = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/* =========================

   HOMEOWNER LIST

   ========================= */

$stmt = $conn->prepare("

  SELECT

    id,

    first_name,

    last_name,

    house_lot_number,

    status,

    email,

    created_at

  FROM homeowners

  WHERE phase = ?

    AND status = 'approved'

  ORDER BY

    last_name,

    first_name

");

$stmt->bind_param("s", $phase);

$stmt->execute();

$homeowners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

$defYear = $currentYear;

$defMonth = $currentMonth;

/* =========================

   PAYMENT HISTORY

   ========================= */

$stmt = $conn->prepare("

  SELECT

    p.*,

    h.first_name,

    h.last_name,

    h.house_lot_number

  FROM finance_payments p

  JOIN homeowners h

    ON h.id = p.homeowner_id

  WHERE p.phase = ?

    AND h.phase = ?

  ORDER BY

    COALESCE(p.paid_at, p.created_at) DESC,

    p.id DESC

  LIMIT 200

");

$stmt->bind_param(

  "ss",

  $phase,

  $phase

);

$stmt->execute();

$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

/* =========================
   HISTORICAL DUES MIGRATION
   ========================= */
$migrationSchemaReady = fdm_table_exists($conn, 'finance_dues_import_queue');
$migrationArchiveReady = $migrationSchemaReady && fdm_column_exists($conn, 'finance_dues_import_queue', 'is_archived');
$migrationRows = [];
$migrationArchivedRows = [];
$migrationHomeowners = [];
$migrationCounts = [
  'ready' => 0,
  'duplicate' => 0,
  'conflict' => 0,
  'unmatched' => 0,
  'needs_review' => 0,
  'finalized' => 0,
  'skipped' => 0,
];

if ($migrationSchemaReady) {
  $archiveWhere = $migrationArchiveReady ? "AND COALESCE(q.is_archived,0)=0" : "";

  $stmt = $conn->prepare("
    SELECT
      q.*,
      h.public_id AS matched_public_id,
      h.first_name AS matched_first_name,
      h.middle_name AS matched_middle_name,
      h.last_name AS matched_last_name,
      h.contact_number AS matched_contact_number,
      h.email AS matched_email,
      h.status AS matched_homeowner_status,
      h.house_lot_number AS matched_house_lot_number,
      ep.amount AS existing_amount,
      ep.status AS existing_payment_status,
      ep.paid_at AS existing_paid_at,
      ep.reference_no AS existing_reference_no,
      ep.notes AS existing_payment_notes
    FROM finance_dues_import_queue q
    LEFT JOIN homeowners h ON h.id = q.matched_homeowner_id
    LEFT JOIN finance_payments ep ON ep.id = q.existing_payment_id
    WHERE q.phase = ?
      {$archiveWhere}
    ORDER BY q.id DESC
    LIMIT 200
  ");
  $stmt->bind_param('s', $phase);
  $stmt->execute();
  $migrationRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $countSql = "
    SELECT status, COUNT(*) AS total
    FROM finance_dues_import_queue
    WHERE phase=?
  ";
  if ($migrationArchiveReady) {
    $countSql .= " AND COALESCE(is_archived,0)=0";
  }
  $countSql .= " GROUP BY status";
  $stmt = $conn->prepare($countSql);
  $stmt->bind_param('s', $phase);
  $stmt->execute();
  $countRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  foreach ($countRows as $cr) {
    $st = (string)($cr['status'] ?? '');
    if (isset($migrationCounts[$st])) {
      $migrationCounts[$st] = (int)($cr['total'] ?? 0);
    }
  }

  if ($migrationArchiveReady) {
    $stmt = $conn->prepare("
      SELECT
        q.*,
        h.public_id AS matched_public_id,
        h.first_name AS matched_first_name,
        h.middle_name AS matched_middle_name,
        h.last_name AS matched_last_name,
        h.house_lot_number AS matched_house_lot_number,
        a.full_name AS archived_by_name
      FROM finance_dues_import_queue q
      LEFT JOIN homeowners h ON h.id=q.matched_homeowner_id
      LEFT JOIN admins a ON a.id=q.archived_by_admin_id
      WHERE q.phase=?
        AND COALESCE(q.is_archived,0)=1
      ORDER BY q.archived_at DESC, q.id DESC
      LIMIT 100
    ");
    $stmt->bind_param('s', $phase);
    $stmt->execute();
    $migrationArchivedRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
  }

  $stmt = $conn->prepare("
    SELECT id, public_id, first_name, middle_name, last_name, contact_number, email,
           block, lot, street, house_lot_number, status
    FROM homeowners
    WHERE phase = ? AND status IN ('approved','former')
    ORDER BY last_name, first_name, id
  ");
  $stmt->bind_param('s', $phase);
  $stmt->execute();
  $migrationHomeowners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* =========================

   MODAL TRANSACTION DATA

   ========================= */

$txData = [];

foreach ($unpaid as $u) {

  $hid = (int)$u['id'];

  $email = trim((string)($u['email'] ?? ''));

  $name = trim(

    (string)($u['first_name'] ?? '') .

    ' ' .

    (string)($u['last_name'] ?? '')

  );

  $txData[$hid] = [

    'id' => $hid,

    'name' => $name,

    'email' => $email,

    'house_lot_number' =>

      (string)($u['house_lot_number'] ?? ''),

    'dues' => [],

    'donations' => []

  ];

}

$stmt = $conn->prepare("

  SELECT

    p.homeowner_id,

    p.pay_year,

    p.pay_month,

    p.amount,

    p.status,

    p.paid_at,

    p.reference_no,

    p.notes

  FROM finance_payments p

  JOIN homeowners h

    ON h.id = p.homeowner_id

  WHERE h.phase = ?

    AND p.phase = ?

  ORDER BY

    p.pay_year DESC,

    p.pay_month DESC,

    p.paid_at DESC

");

$stmt->bind_param(

  "ss",

  $phase,

  $phase

);

$stmt->execute();

$res = $stmt->get_result();

while ($r = $res->fetch_assoc()) {

  $hid = (int)$r['homeowner_id'];

  if (isset($txData[$hid])) {

    $txData[$hid]['dues'][] = $r;

  }

}

$stmt->close();

/*

|--------------------------------------------------------------------------

| Existing donation matching kept unchanged for now

|--------------------------------------------------------------------------

| We will normalize donations separately when finance_donations.php is fixed.

*/

$stmt = $conn->prepare("

  SELECT

    donor_name,

    donor_email,

    amount,

    donation_date,

    receipt_no,

    message,

    created_at

  FROM finance_donations

  WHERE phase = ?

  ORDER BY

    donation_date DESC,

    created_at DESC

");

$stmt->bind_param("s", $phase);

$stmt->execute();

$donRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt->close();

foreach ($donRows as $d) {

  $dEmail = strtolower(

    trim((string)($d['donor_email'] ?? ''))

  );

  $dName = strtolower(

    trim((string)($d['donor_name'] ?? ''))

  );

  foreach ($txData as $hid => $info) {

    $hEmail = strtolower(

      trim((string)$info['email'])

    );

    $hName = strtolower(

      trim((string)$info['name'])

    );

    if (

      (

        $dEmail !== '' &&

        $hEmail !== '' &&

        $dEmail === $hEmail

      ) ||

      (

        $dName !== '' &&

        $dName === $hName

      )

    ) {

      $txData[$hid]['donations'][] = $d;

    }

  }

}

$financeFlash =

  $_SESSION['finance_dues_flash']

  ?? null;

unset($_SESSION['finance_dues_flash']);

?>

<!DOCTYPE html>

<html>

<head>

  <meta charset="utf-8">

  <title>HOA-ADMIN</title>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">

  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">

  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">

  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">

  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

  <style>

  .modal-history-table th,

  .modal-history-table td{

    vertical-align: middle !important;

    font-size: 13px;

  }

  .modal-empty{

    padding: 12px;

    border: 1px dashed #d0d7de;

    border-radius: 8px;

    color: #6c757d;

    background: #fafbfc;

  }

  .custom-modal{

    position: fixed;

    inset: 0;

    z-index: 99999;

  }

  .custom-modal-backdrop{

    position: absolute;

    inset: 0;

    background: rgba(0,0,0,.5);

  }

  .custom-modal-dialog{

    position: relative;

    width: 95%;

    max-width: 1100px;

    margin: 40px auto;

    z-index: 2;

  }

  .custom-modal-content{

    background: #fff;

    border-radius: 10px;

    overflow: hidden;

    box-shadow: 0 10px 35px rgba(0,0,0,.25);

  }

  .custom-modal-header,

  .custom-modal-footer{

    padding: 15px 20px;

    border-bottom: 1px solid #eee;

  }

  .custom-modal-footer{

    border-top: 1px solid #eee;

    border-bottom: 0;

    text-align: right;

  }

  .custom-modal-body{

    padding: 20px;

    max-height: 70vh;

    overflow-y: auto;

  }

  body.modal-open-manual{

    overflow: hidden;

  }

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

.migration-toast {
  position: fixed;
  top: 76px;
  right: 20px;
  min-width: 280px;
  max-width: 460px;
  padding: 12px 16px;
  border-radius: 9px;
  color: #fff;
  font-weight: 600;
  z-index: 100500;
  box-shadow: 0 8px 24px rgba(0,0,0,.24);
  opacity: 0;
  visibility: hidden;
  transform: translateY(-10px);
  transition: .22s ease;
}
.migration-toast.show { opacity: 1; visibility: visible; transform: translateY(0); }
.migration-toast.success { background: #198754; }
.migration-toast.danger { background: #dc3545; }
.migration-toast.warning { background: #d97706; }
.migration-toast.info { background: #0d6efd; }
.migration-match-meta { font-size: 12px; line-height: 1.45; }
.migration-note { max-width: 260px; white-space: normal; }
</style>

  <!-- SHARED ADMIN LIGHT / DARK THEME -->

  <link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">

  <style>

  /* =========================================================

     FINANCE DUES - DARK MODE EXTENSIONS

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

  html.dark .card-box h6,

  html.dark .card-box label,

  html.dark .card-box strong,

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

  html.dark .form-control[readonly],

  html.dark input[readonly].form-control {

    background: var(--admin-surface-3) !important;

    color: var(--admin-text) !important;

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

  /* Tables */

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

  /* Custom transaction-history modal */

  html.dark .custom-modal-backdrop {

    background: rgba(2,6,23,.72) !important;

  }

  html.dark .custom-modal-content {

    background: var(--admin-surface) !important;

    color: var(--admin-text) !important;

    border: 1px solid var(--admin-border) !important;

    box-shadow: 0 20px 60px rgba(0,0,0,.48) !important;

  }

  html.dark .custom-modal-header,

  html.dark .custom-modal-footer {

    background: var(--admin-surface) !important;

    border-color: var(--admin-border) !important;

  }

  html.dark .custom-modal-header h5,

  html.dark .custom-modal-body h6,

  html.dark .custom-modal-body strong,

  html.dark .custom-modal-body {

    color: var(--admin-text) !important;

  }

  html.dark .custom-modal-header .close {

    color: #e5e7eb !important;

    text-shadow: none !important;

    opacity: .9 !important;

  }

  html.dark .modal-empty {

    background: var(--admin-surface-2) !important;

    color: var(--admin-muted) !important;

    border-color: var(--admin-border) !important;

  }

  html.dark .modal-history-table {

    background: var(--admin-surface-2) !important;

  }

  /* Buttons used on this page */

  html.dark .btn-outline-primary {

    color: #93c5fd !important;

    border-color: #3b82f6 !important;

  }

  html.dark .btn-outline-primary:hover {

    color: #fff !important;

    background: #2563eb !important;

    border-color: #2563eb !important;

  }

  /* Direct header logout button */
  .dashboard-logout-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-width: 98px;
    min-height: 40px;
    margin: 0 18px 0 10px;
    padding: 8px 14px;
    border: 1px solid #fecaca;
    border-radius: 11px;
    background: #fff;
    color: #b91c1c !important;
    box-shadow: 0 4px 14px rgba(15,23,42,.06);
    font-size: 12px;
    line-height: 1;
    font-weight: 800;
    text-decoration: none !important;
    white-space: nowrap;
    transition: background .18s ease,color .18s ease,border-color .18s ease,transform .18s ease,box-shadow .18s ease;
  }
  .dashboard-logout-btn i { font-size: 17px; line-height: 1; }
  .dashboard-logout-btn:hover,
  .dashboard-logout-btn:focus {
    border-color: #ef4444;
    background: #fef2f2;
    color: #991b1b !important;
    box-shadow: 0 7px 18px rgba(220,38,38,.12);
    transform: translateY(-1px);
    outline: none;
  }
  html.dark .dashboard-logout-btn {
    border-color: rgba(248,113,113,.30);
    background: rgba(127,29,29,.16);
    color: #fca5a5 !important;
    box-shadow: none;
  }
  html.dark .dashboard-logout-btn:hover,
  html.dark .dashboard-logout-btn:focus {
    border-color: rgba(248,113,113,.55);
    background: rgba(127,29,29,.28);
    color: #fecaca !important;
  }

  /* Remaining dark-mode surfaces used by migration/review sections */
  html.dark .card-box .border,
  html.dark .custom-modal-body .border {
    border-color: var(--admin-border) !important;
  }
  html.dark .card-box .rounded.border,
  html.dark .custom-modal-body .rounded.border {
    background: var(--admin-surface-2) !important;
    color: var(--admin-text) !important;
  }
  html.dark .btn-outline-dark {
    color: #cbd5e1 !important;
    border-color: #64748b !important;
  }
  html.dark .btn-outline-dark:hover {
    color: #fff !important;
    background: #475569 !important;
    border-color: #475569 !important;
  }

  @media (max-width:575.98px) {
    .dashboard-logout-btn {
      width: 40px;
      min-width: 40px;
      height: 40px;
      min-height: 40px;
      margin: 0 10px 0 6px;
      padding: 0;
      border-radius: 10px;
    }
    .dashboard-logout-btn span { display: none; }
    .dashboard-logout-btn i { font-size: 18px; }
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



      <a href="logout.php" class="dashboard-logout-btn" title="Log out" aria-label="Log out">
        <i class="dw dw-logout" aria-hidden="true"></i>
        <span>Log Out</span>
      </a>

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

  <?php include 'sidebar.php'; ?>

<div class="main-container">

  <div class="pd-ltr-20">

    <div class="page-header mb-20">

      <div class="row">

        <div class="col-md-6 col-sm-12">

          <div class="title"><h4>Monthly Dues Management</h4></div>

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

          <h5 class="mb-1">Finance Access</h5>

          <div class="text-secondary">

            Signed in as:

            <b><?= esc($adminPosition !== '' ? $adminPosition : $adminRole) ?></b>

          </div>

        </div>

        <?php if ($canManageDues): ?>

          <span class="badge badge-success p-2">Treasurer • Manage</span>

        <?php else: ?>

          <span class="badge badge-secondary p-2">View Only</span>

        <?php endif; ?>

      </div>

      <?php if (!$canManageDues): ?>

        <div class="alert alert-info mt-3 mb-0">

          Monthly dues settings and manual payment recording are managed by the Treasurer.

          You can still review payment history and unpaid homeowners for your permitted phase.

        </div>

      <?php endif; ?>

    </div>

    <div class="card-box mb-20 p-3">

      <h5 class="mb-3">Set Monthly Dues</h5>

      <?php if ($canManageDues): ?>

        <form method="post" class="form-inline">

          <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">

          <label class="mr-2">Monthly Dues (₱)</label>

          <input

            type="number"

            step="0.01"

            min="0"

            max="1000000"

            name="monthly_dues"

            class="form-control mr-2"

            value="<?= esc(number_format($monthly_dues, 2, '.', '')) ?>"

            required

          >

          <button class="btn btn-primary" name="save_dues">

            Save

          </button>

        </form>

      <?php else: ?>

        <div class="d-flex align-items-center">

          <span class="badge badge-primary p-2 mr-2">

            ₱ <?= number_format($monthly_dues, 2) ?> / month

          </span>

          <span class="text-secondary">Read-only</span>

        </div>

      <?php endif; ?>

    </div>

    <div class="card-box mb-20 p-3">

      <h5 class="mb-3">Record Manual / Cash Payment</h5>

      <?php if ($canManageDues): ?>

        <?php if ($monthly_dues <= 0): ?>

          <div class="alert alert-warning mb-0">

            Set the monthly dues amount first before recording payments.

          </div>

        <?php else: ?>

          <form method="post" id="recordPaymentForm">

            <input type="hidden" name="csrf" value="<?= esc($csrfToken) ?>">

            <div class="row">

              <div class="col-md-4">

                <label>Homeowner</label>

                <select

                  name="homeowner_id"

                  id="paymentHomeowner"

                  class="form-control"

                  required

                >

                  <option value="">-- Select Homeowner --</option>

                  <?php foreach ($homeowners as $h): ?>

                    <?php

                      $createdTs = strtotime((string)($h['created_at'] ?? ''));

                      $startLabel = $createdTs

                        ? date('F Y', $createdTs)

                        : 'Unknown';

                    ?>

                    <option value="<?= (int)$h['id'] ?>">

                      <?= esc(

                        ($h['last_name'] ?? '') .

                        ', ' .

                        ($h['first_name'] ?? '') .

                        ' (' .

                        ($h['house_lot_number'] ?? '') .

                        ') — starts ' .

                        $startLabel

                      ) ?>

                    </option>

                  <?php endforeach; ?>

                </select>

              </div>

              <div class="col-md-2">

                <label>Year</label>

                <input

                  type="number"

                  name="pay_year"

                  id="paymentYear"

                  class="form-control"

                  min="2000"

                  max="<?= (int)$currentYear ?>"

                  value="<?= (int)$defYear ?>"

                  required

                >

              </div>

              <div class="col-md-2">

                <label>Month</label>

                <select

                  name="pay_month"

                  id="paymentMonth"

                  class="form-control"

                  required

                >

                  <?php for ($m = 1; $m <= 12; $m++): ?>

                    <option

                      value="<?= $m ?>"

                      <?= $m === $defMonth ? 'selected' : '' ?>

                    >

                      <?= esc(date('F', mktime(0, 0, 0, $m, 1))) ?>

                    </option>

                  <?php endfor; ?>

                </select>

              </div>

              <div class="col-md-2">

                <label>Amount</label>

                <input

                  type="text"

                  class="form-control"

                  value="₱ <?= esc(number_format($monthly_dues, 2)) ?>"

                  readonly

                >

                <small class="text-secondary">

                  Uses the configured monthly dues amount.

                </small>

              </div>

              <div class="col-md-2">

                <label>Reference #</label>

                <input

                  type="text"

                  name="reference_no"

                  maxlength="100"

                  class="form-control"

                  placeholder="OR / Receipt #"

                >

              </div>

              <div class="col-md-12 mt-2">

                <label>Notes</label>

                <input

                  type="text"

                  name="notes"

                  maxlength="255"

                  class="form-control"

                  placeholder="Optional notes, e.g. Cash payment"

                >

              </div>

            </div>

            <button

              class="btn btn-success mt-3"

              name="record_payment"

              value="1"

            >

              Save Payment

            </button>

            <small class="text-secondary d-block mt-2">

              Existing paid records are protected and will not be overwritten.

              Future months and months before the homeowner account start date are rejected.

            </small>

          </form>

        <?php endif; ?>

      <?php else: ?>

        <div class="alert alert-light border mb-0">

          Manual payment recording is available to the Treasurer only.

        </div>

      <?php endif; ?>

    </div>

    <div class="card-box mb-20 p-3" id="historicalDuesMigrationCard">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-1">Historical Monthly Dues Migration</h5>
            <div class="text-secondary">Import existing HOA paid-dues records. Imported rows are reviewed first and keep their original historical paid date.</div>
          </div>
          <div class="mt-2 mt-md-0">
            <a href="download_finance_dues_template.php" class="btn btn-outline-primary btn-sm">Download Excel Template</a>
          </div>
        </div>

        <?php if (!$migrationSchemaReady): ?>
          <div class="alert alert-warning mb-0">
            Historical dues migration is not installed yet. Run <b>finance_dues_migration_setup.sql</b> in phpMyAdmin first.
          </div>
        <?php else: ?>
          <?php if ($canManageDues): ?>
            <div class="border rounded p-3 mb-3">
              <div class="row align-items-end">
                <div class="col-md-8">
                  <label class="font-weight-bold">Historical Dues Excel File</label>
                  <input type="file" id="historicalDuesFile" class="form-control" accept=".xlsx,.xls,.csv">
                  <small class="text-secondary">Matching uses Homeowner Name + Phase + Block + Lot, with mobile number, email and address as supporting references.</small>
                </div>
                <div class="col-md-4 mt-2 mt-md-0">
                  <button type="button" id="importHistoricalDuesBtn" class="btn btn-primary btn-block">Import for Review</button>
                </div>
              </div>
            </div>
          <?php else: ?>
            <div class="alert alert-light border">Historical dues migration can be performed by the Treasurer only. You can still review imported migration records.</div>
          <?php endif; ?>

          <div class="d-flex flex-wrap mb-3" style="gap:8px;">
            <span class="badge badge-success p-2">Ready: <?= (int)$migrationCounts['ready'] ?></span>
            <span class="badge badge-info p-2">Needs Review: <?= (int)$migrationCounts['needs_review'] ?></span>
            <span class="badge badge-warning p-2">No Match: <?= (int)$migrationCounts['unmatched'] ?></span>
            <span class="badge badge-secondary p-2">Duplicate: <?= (int)$migrationCounts['duplicate'] ?></span>
            <span class="badge badge-danger p-2">Conflict: <?= (int)$migrationCounts['conflict'] ?></span>
            <span class="badge badge-primary p-2">Migrated: <?= (int)$migrationCounts['finalized'] ?></span>
          </div>

          <?php if ($canManageDues && $migrationCounts['ready'] > 0): ?>
            <div class="text-right mb-3"><button type="button" id="finalizeAllHistoricalBtn" class="btn btn-success btn-sm">Finalize All Ready Records</button></div>
          <?php endif; ?>

          <div class="table-responsive">
            <table id="historicalDuesTable" class="table table-striped table-hover">
              <thead><tr>
                <th>Excel Row</th><th>Imported Homeowner</th><th>Address</th><th>Period</th><th>Amount</th><th>Matched System Homeowner</th><th>Status</th><th>Action</th>
              </tr></thead>
              <tbody>
                <?php if (!$migrationRows): ?>
                  <tr><td colspan="8" class="text-center text-secondary">No historical dues migration rows yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($migrationRows as $mr): ?>
                    <?php
                      [$migrationLabel, $migrationBadge] = financeDuesMigrationStatusMeta((string)$mr['status']);
                      $matchedName = trim((string)($mr['matched_first_name'] ?? '') . ' ' . (string)($mr['matched_middle_name'] ?? '') . ' ' . (string)($mr['matched_last_name'] ?? ''));
                    ?>
                    <tr>
                      <td>#<?= (int)$mr['source_row'] ?></td>
                      <td>
                        <strong><?= esc($mr['homeowner_name']) ?></strong>
                        <?php if (!empty($mr['mobile_number'])): ?><div class="migration-match-meta text-secondary">Mobile: <?= esc($mr['mobile_number']) ?></div><?php endif; ?>
                        <?php if (!empty($mr['email'])): ?><div class="migration-match-meta text-secondary"><?= esc($mr['email']) ?></div><?php endif; ?>
                      </td>
                      <td>
                        <?= esc($mr['phase']) ?> • Block <?= esc($mr['block']) ?> • Lot <?= esc($mr['lot']) ?>
                        <?php if (!empty($mr['street_address'])): ?><div class="migration-match-meta text-secondary"><?= esc($mr['street_address']) ?></div><?php endif; ?>
                      </td>
                      <td><?= esc(date('F', mktime(0,0,0,(int)$mr['pay_month'],1))) ?> <?= (int)$mr['pay_year'] ?><div class="migration-match-meta text-secondary">Paid: <?= esc(substr((string)$mr['paid_at'],0,10)) ?></div></td>
                      <td>₱ <?= number_format((float)$mr['amount'],2) ?></td>
                      <td>
                        <?php if ((int)($mr['matched_homeowner_id'] ?? 0) > 0): ?>
                          <strong><?= esc($mr['matched_public_id'] ?: ('#' . (int)$mr['matched_homeowner_id'])) ?></strong>
                          <div><?= esc($matchedName) ?></div>
                          <div class="migration-match-meta text-secondary"><?= esc($mr['matched_house_lot_number'] ?? '') ?> • <?= esc(ucfirst((string)($mr['matched_homeowner_status'] ?? ''))) ?></div>
                        <?php else: ?><span class="text-secondary">Not matched</span><?php endif; ?>
                      </td>
                      <td class="migration-note">
                        <span class="badge badge-<?= esc($migrationBadge) ?>"><?= esc($migrationLabel) ?></span>
                        <?php if (!empty($mr['match_note'])): ?><div class="migration-match-meta mt-1 text-secondary"><?= esc($mr['match_note']) ?></div><?php endif; ?>
                      </td>
                      <td>
                        <?php if ($canManageDues): ?>
                          <?php if ((string)$mr['status'] === 'ready'): ?>
                            <button type="button" class="btn btn-sm btn-success mb-1" onclick="finalizeHistoricalRow(<?= (int)$mr['id'] ?>)">Finalize</button>
                          <?php endif; ?>
                          <?php if (!in_array((string)$mr['status'], ['finalized','skipped'], true)): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary mb-1" onclick="openHistoricalReview(<?= (int)$mr['id'] ?>, <?= (int)($mr['matched_homeowner_id'] ?? 0) ?>)">Review</button>
                          <?php elseif ($migrationArchiveReady): ?>
                            <button type="button" class="btn btn-sm btn-outline-dark mb-1" onclick="archiveHistoricalRow(<?= (int)$mr['id'] ?>)">Archive</button>
                          <?php else: ?>
                            <span class="text-secondary">—</span>
                          <?php endif; ?>
                        <?php else: ?><span class="text-secondary">View only</span><?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($migrationArchiveReady): ?>
      <div class="card-box mb-20 p-3" id="historicalDuesArchiveCard">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-1">Historical Dues Migration Archive</h5>
            <div class="text-secondary">Closed migration rows remain preserved here. Archiving never deletes the original imported data or the finalized finance payment.</div>
          </div>
          <span class="badge badge-dark p-2">Archived: <?= count($migrationArchivedRows) ?></span>
        </div>

        <div class="table-responsive">
          <table id="historicalDuesArchiveTable" class="table table-striped table-hover">
            <thead>
              <tr>
                <th>Archived</th>
                <th>Imported Homeowner</th>
                <th>Period</th>
                <th>Amount</th>
                <th>Final Status</th>
                <th>Archive Details</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$migrationArchivedRows): ?>
                <tr><td colspan="7" class="text-center text-secondary">No archived historical dues migration rows yet.</td></tr>
              <?php else: ?>
                <?php foreach ($migrationArchivedRows as $ar): ?>
                  <?php [$archiveLabel, $archiveBadge] = financeDuesMigrationStatusMeta((string)$ar['status']); ?>
                  <tr>
                    <td><?= esc($ar['archived_at'] ?? '') ?></td>
                    <td>
                      <strong><?= esc($ar['homeowner_name'] ?? '') ?></strong>
                      <div class="migration-match-meta text-secondary"><?= esc($ar['phase'] ?? '') ?> • Block <?= esc($ar['block'] ?? '') ?> • Lot <?= esc($ar['lot'] ?? '') ?></div>
                      <?php if (!empty($ar['source_filename'])): ?><div class="migration-match-meta text-secondary">File: <?= esc($ar['source_filename']) ?></div><?php endif; ?>
                    </td>
                    <td><?= esc(date('F', mktime(0,0,0,(int)$ar['pay_month'],1))) ?> <?= (int)$ar['pay_year'] ?></td>
                    <td>₱ <?= number_format((float)$ar['amount'],2) ?></td>
                    <td><span class="badge badge-<?= esc($archiveBadge) ?>"><?= esc($archiveLabel) ?></span></td>
                    <td>
                      <div><?= esc($ar['archive_reason'] ?: 'No archive reason entered.') ?></div>
                      <div class="migration-match-meta text-secondary">By: <?= esc($ar['archived_by_name'] ?: ('Admin #' . (int)($ar['archived_by_admin_id'] ?? 0))) ?></div>
                    </td>
                    <td>
                      <?php if ($canManageDues): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="restoreHistoricalRow(<?= (int)$ar['id'] ?>)">Restore</button>
                      <?php else: ?><span class="text-secondary">View only</span><?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <div class="card-box mb-20 p-3">

      <h5 class="mb-3">Payment History (Latest 200)</h5>

      <div class="table-responsive">

        <table id="paymentsTable" class="table table-striped table-hover">

          <thead>

            <tr>

              <th>Date</th>

              <th>Homeowner</th>

              <th>Blk/Lot</th>

              <th>Period</th>

              <th>Amount</th>

              <th>Status</th>

              <th>Ref</th>

              <th>Notes</th>

            </tr>

          </thead>

          <tbody>

            <?php foreach($payments as $p): ?>

              <tr>

                <td><?=esc($p['paid_at'] ?? '')?></td>

                <td><?=esc(($p['last_name']??'').", ".($p['first_name']??''))?></td>

                <td><?=esc($p['house_lot_number'] ?? '')?></td>

                <td><?=esc($p['pay_year']."-".str_pad((string)$p['pay_month'],2,'0',STR_PAD_LEFT))?></td>

                <td>₱ <?=number_format((float)$p['amount'],2)?></td>

                <td>

                  <?php

                    $paymentStatus = (string)($p['status'] ?? 'unpaid');

                    $statusBadge = $paymentStatus === 'paid'

                      ? 'badge-success'

                      : 'badge-danger';

                  ?>

                  <span class="badge <?= $statusBadge ?>">

                    <?= esc(strtoupper($paymentStatus)) ?>

                  </span>

                </td>

                <td><?=esc($p['reference_no'] ?? '')?></td>

                <td><?=esc($p['notes'] ?? '')?></td>

              </tr>

            <?php endforeach; ?>

          </tbody>

        </table>

      </div>

    </div>

    <div class="card-box mb-20 p-3">

      <div class="d-flex justify-content-between align-items-center">

        <h5 class="mb-0">Unpaid Homeowners</h5>

        <form method="get" class="form-inline">

          <?php if ($canPickPhase): ?>

            <input type="hidden" name="phase" value="<?=esc($phase)?>">

          <?php endif; ?>

          <label class="mr-2">Year</label>

          <input

            type="number"

            name="year"

            min="2000"

            max="<?= (int)$currentYear ?>"

            class="form-control mr-3"

            value="<?= (int)$selYear ?>"

            style="width:120px"

          >

          <label class="mr-2">Month</label>

          <select name="month" class="form-control mr-3" style="width:120px">

            <?php for($m=1;$m<=12;$m++): ?>

              <option value="<?=$m?>" <?= $m===$selMonth?'selected':'' ?>>

                <?= esc(date('F', mktime(0,0,0,$m,1))) ?>

              </option>

            <?php endfor; ?>

          </select>

          <button class="btn btn-outline-primary">Filter</button>

        </form>

      </div>

      <div class="mt-3">

        <span class="badge badge-danger">

          Unpaid count: <?= count($unpaid) ?>

        </span>

        <span class="badge badge-light border ml-2">

          <?= esc(date('F', mktime(0, 0, 0, $selMonth, 1))) ?>

          <?= (int)$selYear ?>

        </span>

      </div>

      <div class="table-responsive mt-2">

        <table class="table table-striped table-hover">

          <thead>

            <tr>

              <th>Name</th>

              <th>Blk/Lot</th>

              <th>Dues Start</th>

              <th>Action</th>

            </tr>

          </thead>

          <tbody>

            <?php if (!$unpaid): ?>

              <tr>

                <td colspan="4" class="text-center text-secondary">

                  No unpaid homeowners for this period.

                </td>

              </tr>

            <?php else: ?>

              <?php foreach($unpaid as $u): ?>

                <?php $hid = (int)$u['id']; ?>

                <tr>

                  <td><?=esc($u['last_name'].", ".$u['first_name'])?></td>

                  <td><?=esc($u['house_lot_number'])?></td>

                  <td>

                    <?php

                      $uStart = strtotime((string)($u['created_at'] ?? ''));

                    ?>

                    <?= $uStart ? esc(date('F Y', $uStart)) : '-' ?>

                  </td>

                  <td>

                    <button

                      type="button"

                      class="btn btn-sm btn-primary"

                      onclick="openHistoryModal(<?= $hid ?>)"

                    >

                      View

                    </button>

                  </td>

                </tr>

              <?php endforeach; ?>

            <?php endif; ?>

          </tbody>

        </table>

      </div>

    </div>

    <!-- Hidden rendered transaction data -->

    <div id="historyDataStore" style="display:none;">

      <?php foreach($unpaid as $u): ?>

        <?php

          $hid = (int)$u['id'];

          $dues = $txData[$hid]['dues'] ?? [];

          $donations = $txData[$hid]['donations'] ?? [];

        ?>

        <div id="history-data-<?= $hid ?>">

          <div class="hd-name"><?= esc($txData[$hid]['name'] ?? '') ?></div>

          <div class="hd-email"><?= esc($txData[$hid]['email'] ?? '') ?></div>

          <div class="hd-lot"><?= esc($txData[$hid]['house_lot_number'] ?? '') ?></div>

          <div class="hd-dues">

            <?php if (!$dues): ?>

              <div class="modal-empty">No monthly dues payments found.</div>

            <?php else: ?>

              <div class="table-responsive">

                <table class="table table-bordered table-sm modal-history-table">

                  <thead>

                    <tr>

                      <th>Period</th>

                      <th>Amount</th>

                      <th>Status</th>

                      <th>Paid At</th>

                      <th>Ref #</th>

                      <th>Notes</th>

                    </tr>

                  </thead>

                  <tbody>

                    <?php foreach($dues as $r): ?>

                      <tr>

                        <td><?= esc(date('F', mktime(0,0,0,(int)$r['pay_month'],1)) . ' ' . (int)$r['pay_year']) ?></td>

                        <td>₱ <?= number_format((float)$r['amount'], 2) ?></td>

                        <td><?= esc($r['status'] ?? '-') ?></td>

                        <td><?= esc($r['paid_at'] ?? '-') ?></td>

                        <td><?= esc($r['reference_no'] ?? '-') ?></td>

                        <td><?= esc($r['notes'] ?? '-') ?></td>

                      </tr>

                    <?php endforeach; ?>

                  </tbody>

                </table>

              </div>

            <?php endif; ?>

          </div>

          <div class="hd-donations">

            <?php if (!$donations): ?>

              <div class="modal-empty">No donation records found.</div>

            <?php else: ?>

              <div class="table-responsive">

                <table class="table table-bordered table-sm modal-history-table">

                  <thead>

                    <tr>

                      <th>Date</th>

                      <th>Amount</th>

                      <th>Receipt #</th>

                      <th>Email</th>

                      <th>Message</th>

                    </tr>

                  </thead>

                  <tbody>

                    <?php foreach($donations as $r): ?>

                      <tr>

                        <td><?= esc($r['donation_date'] ?? '-') ?></td>

                        <td>₱ <?= number_format((float)$r['amount'], 2) ?></td>

                        <td><?= esc($r['receipt_no'] ?? '-') ?></td>

                        <td><?= esc($r['donor_email'] ?? '-') ?></td>

                        <td><?= esc($r['message'] ?? '-') ?></td>

                      </tr>

                    <?php endforeach; ?>

                  </tbody>

                </table>

              </div>

            <?php endif; ?>

          </div>

        </div>

      <?php endforeach; ?>

    </div>

    <!-- Historical dues migration review modal -->
      <div id="historicalReviewModal" class="custom-modal" style="display:none;">
        <div class="custom-modal-backdrop" onclick="closeHistoricalReview()"></div>
        <div class="custom-modal-dialog" style="max-width:760px;">
          <div class="custom-modal-content">
            <div class="custom-modal-header d-flex justify-content-between align-items-center">
              <h5 class="mb-0">Review Historical Dues Match</h5>
              <button type="button" class="close" onclick="closeHistoricalReview()"><span>&times;</span></button>
            </div>
            <div class="custom-modal-body">
              <input type="hidden" id="historicalReviewId" value="0">

              <div class="row">
                <div class="col-md-6 mb-3">
                  <div class="border rounded p-3 h-100">
                    <div class="font-weight-bold mb-2">Imported Historical Record</div>
                    <div id="historicalImportedSummary" class="small">-</div>
                  </div>
                </div>
                <div class="col-md-6 mb-3">
                  <div class="border rounded p-3 h-100">
                    <div class="font-weight-bold mb-2">Current System Match</div>
                    <div id="historicalMatchedSummary" class="small">-</div>
                  </div>
                </div>
              </div>

              <div id="historicalExistingPaymentBox" class="alert alert-warning" style="display:none;">
                <div class="font-weight-bold mb-1">Existing Payment Found</div>
                <div id="historicalExistingPaymentSummary" class="small"></div>
              </div>

              <div class="alert alert-info">
                Confirm or change the homeowner only after checking the imported name, property, mobile number, email, ownership history, and any existing payment shown above.
              </div>

              <label class="font-weight-bold">Existing Homeowner</label>
              <select id="historicalReviewHomeowner" class="form-control">
                <option value="">-- Select Existing Homeowner --</option>
                <?php foreach ($migrationHomeowners as $mh): ?>
                  <?php $mhName = trim(($mh['first_name'] ?? '') . ' ' . ($mh['middle_name'] ?? '') . ' ' . ($mh['last_name'] ?? '')); ?>
                  <option value="<?= (int)$mh['id'] ?>"><?= esc(($mh['public_id'] ?: ('#' . (int)$mh['id'])) . ' — ' . $mhName . ' — Block ' . ($mh['block'] ?? '') . ' Lot ' . ($mh['lot'] ?? '') . ' — ' . ucfirst((string)$mh['status'])) ?></option>
                <?php endforeach; ?>
              </select>
              <small class="text-secondary d-block mt-2">Manual matching is an explicit Treasurer decision. Existing paid records are never silently overwritten by migration.</small>
            </div>
            <div class="custom-modal-footer d-flex justify-content-between">
              <button type="button" class="btn btn-outline-danger" onclick="skipHistoricalRow()">Skip Row</button>
              <div>
                <button type="button" class="btn btn-outline-secondary" onclick="recheckHistoricalRow()">Recheck Automatically</button>
                <button type="button" class="btn btn-primary" onclick="saveHistoricalMatch()">Save Match</button>
                <button type="button" class="btn btn-secondary" onclick="closeHistoricalReview()">Close</button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Manual Modal -->

    <div id="historyModal" class="custom-modal" style="display:none;">

      <div class="custom-modal-backdrop" onclick="closeHistoryModal()"></div>

      <div class="custom-modal-dialog">

        <div class="custom-modal-content">

          <div class="custom-modal-header d-flex justify-content-between align-items-center">

            <h5 class="mb-0">Homeowner Transaction History</h5>

            <button type="button" class="close" onclick="closeHistoryModal()" aria-label="Close">

              <span aria-hidden="true">&times;</span>

            </button>

          </div>

          <div class="custom-modal-body">

            <div class="mb-3">

              <div><strong>Name:</strong> <span id="hmName">-</span></div>

              <div><strong>Email:</strong> <span id="hmEmail">-</span></div>

              <div><strong>Blk/Lot:</strong> <span id="hmLot">-</span></div>

            </div>

            <hr>

            <h6>Monthly Dues Paid</h6>

            <div id="hmDuesWrap"></div>

            <hr>

            <h6>Donations</h6>

            <div id="hmDonationWrap"></div>

          </div>

          <div class="custom-modal-footer">

            <button type="button" class="btn btn-secondary" onclick="closeHistoryModal()">Close</button>

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
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>

$(function(){

  $('#paymentsTable').DataTable({ pageLength: 25, order: [[0,'desc']] });
  if ($('#historicalDuesTable').length && $('#historicalDuesTable tbody tr').length > 1) {
    $('#historicalDuesTable').DataTable({ pageLength: 25, order: [[0,'desc']] });
  }
  if ($('#historicalDuesArchiveTable').length && $('#historicalDuesArchiveTable tbody tr').length > 1) {
    $('#historicalDuesArchiveTable').DataTable({ pageLength: 25, order: [[0,'desc']] });
  }

});

function openHistoryModal(homeownerId) {

  var box = document.getElementById('history-data-' + homeownerId);

  if (!box) {

    document.getElementById('hmName').textContent = '-';

    document.getElementById('hmEmail').textContent = '-';

    document.getElementById('hmLot').textContent = '-';

    document.getElementById('hmDuesWrap').innerHTML =

      '<div class="modal-empty">No monthly dues payments found.</div>';

    document.getElementById('hmDonationWrap').innerHTML =

      '<div class="modal-empty">No donation records found.</div>';

    document.getElementById('historyModal').style.display = 'block';

    document.body.classList.add('modal-open-manual');

    return;

  }

  var nameEl = box.querySelector('.hd-name');

  var emailEl = box.querySelector('.hd-email');

  var lotEl = box.querySelector('.hd-lot');

  var duesEl = box.querySelector('.hd-dues');

  var donationsEl = box.querySelector('.hd-donations');

  document.getElementById('hmName').textContent = nameEl ? nameEl.textContent : '-';

  document.getElementById('hmEmail').textContent = emailEl ? emailEl.textContent : '-';

  document.getElementById('hmLot').textContent = lotEl ? lotEl.textContent : '-';

  document.getElementById('hmDuesWrap').innerHTML = duesEl ? duesEl.innerHTML : '<div class="modal-empty">No monthly dues payments found.</div>';

  document.getElementById('hmDonationWrap').innerHTML = donationsEl ? donationsEl.innerHTML : '<div class="modal-empty">No donation records found.</div>';

  document.getElementById('historyModal').style.display = 'block';

  document.body.classList.add('modal-open-manual');

}

function closeHistoryModal() {

  document.getElementById('historyModal').style.display = 'none';

  document.body.classList.remove('modal-open-manual');

}

document.addEventListener('keydown', function(e){

  if (e.key === 'Escape') {

    closeHistoryModal();
    closeHistoricalReview();

  }

});

(function () {

  const yearInput = document.getElementById('paymentYear');

  const monthSelect = document.getElementById('paymentMonth');

  if (!yearInput || !monthSelect) return;

  const currentYear = <?= (int)$currentYear ?>;

  const currentMonth = <?= (int)$currentMonth ?>;

  function syncPaymentMonths() {

    const selectedYear = Number(yearInput.value || currentYear);

    Array.from(monthSelect.options).forEach(function (option) {

      const month = Number(option.value || 0);

      option.disabled =

        selectedYear > currentYear ||

        (

          selectedYear === currentYear &&

          month > currentMonth

        );

    });

    const selectedOption = monthSelect.selectedOptions[0];

    if (selectedOption && selectedOption.disabled) {

      monthSelect.value = String(currentMonth);

    }

  }

  yearInput.addEventListener('input', syncPaymentMonths);

  yearInput.addEventListener('change', syncPaymentMonths);

  syncPaymentMonths();

})();

const historicalMigrationCsrf = <?= json_encode($csrfToken) ?>;
const historicalMigrationPhase = <?= json_encode($phase) ?>;
const historicalMigrationReviewRows = <?= json_encode($migrationRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function showMigrationToast(message, type) {
  const toast = document.getElementById('migrationToast');
  if (!toast) return;
  toast.textContent = message || '';
  toast.className = 'migration-toast ' + (type || 'info') + ' show';
  clearTimeout(window.__migrationToastTimer);
  window.__migrationToastTimer = setTimeout(function(){ toast.classList.remove('show'); }, 4500);
}

function excelDateForMigration(value) {
  if (!value) return '';
  if (value instanceof Date && !isNaN(value.getTime())) {
    return value.getFullYear() + '-' + String(value.getMonth()+1).padStart(2,'0') + '-' + String(value.getDate()).padStart(2,'0');
  }
  if (typeof value === 'number' && window.XLSX && XLSX.SSF) {
    const p = XLSX.SSF.parse_date_code(value);
    if (p) return String(p.y).padStart(4,'0') + '-' + String(p.m).padStart(2,'0') + '-' + String(p.d).padStart(2,'0');
  }
  return String(value).trim();
}

async function importHistoricalDuesFile() {
  const input = document.getElementById('historicalDuesFile');
  const btn = document.getElementById('importHistoricalDuesBtn');
  if (!input || !input.files || !input.files[0]) { showMigrationToast('Select the historical dues Excel file first.', 'warning'); return; }
  if (!window.XLSX) { showMigrationToast('Excel reader failed to load. Refresh the page and try again.', 'danger'); return; }
  const oldText = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Importing...'; }
  try {
    const buffer = await input.files[0].arrayBuffer();
    const wb = XLSX.read(buffer, { type:'array', cellDates:true });
    const ws = wb.Sheets[wb.SheetNames[0]];
    const rawRows = XLSX.utils.sheet_to_json(ws, { range:2, defval:'', raw:true });
    const rows = rawRows.map(function(r,index){ return {
      _source_row:index+4,
      homeowner_name:r['Homeowner Name'] ?? '', phase:r['Phase'] ?? '', block:r['Block'] ?? '', lot:r['Lot'] ?? '',
      street_address:r['Street / Address'] ?? '', mobile_number:r['Mobile Number (Optional)'] ?? '', email:r['Email (Optional)'] ?? '',
      pay_year:r['Payment Year'] ?? '', pay_month:r['Payment Month'] ?? '', amount:r['Amount Paid'] ?? '', paid_at:excelDateForMigration(r['Paid Date']),
      reference_no:r['Reference / OR No.'] ?? '', notes:r['Notes'] ?? '', source_reference:r['Source / Old Record Ref.'] ?? ''
    }; });
    const response = await fetch('finance_dues_import.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        csrf:historicalMigrationCsrf,
        phase:historicalMigrationPhase,
        filename:input.files[0].name || '',
        rows:rows
      })
    });
    const data = await response.json();
    if (!response.ok || !data.success) {
      const extra = Array.isArray(data.errors) && data.errors.length ? ' ' + data.errors.slice(0,3).join(' ') : '';
      throw new Error((data.message || 'Import failed.') + extra);
    }
    showMigrationToast(data.message || 'Historical dues imported for review.', 'success');
    setTimeout(function(){ window.location.reload(); }, 900);
  } catch (err) {
    showMigrationToast(err && err.message ? err.message : 'Historical dues import failed.', 'danger');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = oldText || 'Import for Review'; }
  }
}

async function postHistoricalAction(url, params) {
  const body = new URLSearchParams();
  body.set('csrf', historicalMigrationCsrf);
  Object.keys(params || {}).forEach(function(k){ body.set(k, String(params[k])); });
  const response = await fetch(url, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
    body:body.toString()
  });
  const data = await response.json();
  if (!response.ok || !data.success) throw new Error(data.message || 'The migration action failed.');
  return data;
}

async function finalizeHistoricalRow(id) {
  try {
    const data = await postHistoricalAction('finance_dues_finalize.php', {id:id});
    showMigrationToast(data.message, 'success');
    setTimeout(function(){ location.reload(); }, 700);
  } catch(err) { showMigrationToast(err.message, 'danger'); }
}

async function finalizeAllHistoricalRows() {
  const btn = document.getElementById('finalizeAllHistoricalBtn');
  if (!btn) return;
  const old = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Finalizing...';
  try {
    const data = await postHistoricalAction('finance_dues_finalize_all.php', {});
    showMigrationToast(data.message, 'success');
    setTimeout(function(){ location.reload(); }, 800);
  } catch(err) {
    showMigrationToast(err.message, 'danger');
    btn.disabled = false;
    btn.textContent = old;
  }
}

function escapeHistoricalHtml(value) {
  return String(value ?? '')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#039;');
}

function openHistoricalReview(id, homeownerId) {
  const modal = document.getElementById('historicalReviewModal');
  const idInput = document.getElementById('historicalReviewId');
  const select = document.getElementById('historicalReviewHomeowner');
  if (!modal || !idInput || !select) return;

  idInput.value = String(id || 0);
  select.value = homeownerId ? String(homeownerId) : '';

  const row = (historicalMigrationReviewRows || []).find(function(item){
    return Number(item.id || 0) === Number(id || 0);
  }) || {};

  const imported = document.getElementById('historicalImportedSummary');
  if (imported) {
    imported.innerHTML =
      '<strong>' + escapeHistoricalHtml(row.homeowner_name || '-') + '</strong><br>' +
      escapeHistoricalHtml((row.phase || '') + ' • Block ' + (row.block || '-') + ' • Lot ' + (row.lot || '-')) + '<br>' +
      'Mobile: ' + escapeHistoricalHtml(row.mobile_number || '-') + '<br>' +
      'Email: ' + escapeHistoricalHtml(row.email || '-') + '<br>' +
      'Period: ' + escapeHistoricalHtml(String(row.pay_year || '') + '-' + String(row.pay_month || '').padStart(2,'0')) + '<br>' +
      'Amount: ₱' + Number(row.amount || 0).toFixed(2) + '<br>' +
      'Paid Date: ' + escapeHistoricalHtml(String(row.paid_at || '').slice(0,10));
  }

  const matched = document.getElementById('historicalMatchedSummary');
  if (matched) {
    const matchedName = [row.matched_first_name, row.matched_middle_name, row.matched_last_name].filter(Boolean).join(' ');
    matched.innerHTML = row.matched_homeowner_id
      ? '<strong>' + escapeHistoricalHtml(row.matched_public_id || ('#' + row.matched_homeowner_id)) + '</strong><br>' +
        escapeHistoricalHtml(matchedName || '-') + '<br>' +
        escapeHistoricalHtml(row.matched_house_lot_number || '-') + '<br>' +
        'Status: ' + escapeHistoricalHtml(row.matched_homeowner_status || '-') + '<br>' +
        'Match: ' + escapeHistoricalHtml(row.match_method || '-') + ' (' + Number(row.match_score || 0) + ')'
      : '<span class="text-secondary">No homeowner matched yet.</span>';
  }

  const paymentBox = document.getElementById('historicalExistingPaymentBox');
  const paymentSummary = document.getElementById('historicalExistingPaymentSummary');
  if (paymentBox && paymentSummary) {
    if (row.existing_payment_id) {
      paymentBox.style.display = 'block';
      paymentSummary.innerHTML =
        'Payment #' + Number(row.existing_payment_id) + '<br>' +
        'Status: ' + escapeHistoricalHtml(row.existing_payment_status || '-') + '<br>' +
        'Amount: ₱' + Number(row.existing_amount || 0).toFixed(2) + '<br>' +
        'Paid Date: ' + escapeHistoricalHtml(String(row.existing_paid_at || '').slice(0,10) || '-') + '<br>' +
        'Reference: ' + escapeHistoricalHtml(row.existing_reference_no || '-');
    } else {
      paymentBox.style.display = 'none';
      paymentSummary.textContent = '';
    }
  }

  modal.style.display = 'block';
  document.body.classList.add('modal-open-manual');
}
function closeHistoricalReview() {
  const modal = document.getElementById('historicalReviewModal');
  if (modal) modal.style.display = 'none';
  document.body.classList.remove('modal-open-manual');
}
async function saveHistoricalMatch() {
  const id = Number(document.getElementById('historicalReviewId')?.value || 0);
  const homeownerId = Number(document.getElementById('historicalReviewHomeowner')?.value || 0);
  if (!id || !homeownerId) { showMigrationToast('Select an existing homeowner first.', 'warning'); return; }
  try {
    const data = await postHistoricalAction('finance_dues_migration_action.php', {action:'match', id:id, homeowner_id:homeownerId});
    showMigrationToast(data.message, 'success');
    setTimeout(function(){ location.reload(); }, 700);
  } catch(err) { showMigrationToast(err.message, 'danger'); }
}
async function recheckHistoricalRow() {
  const id = Number(document.getElementById('historicalReviewId')?.value || 0);
  if (!id) return;
  try {
    const data = await postHistoricalAction('finance_dues_migration_action.php', {action:'recheck', id:id});
    showMigrationToast(data.message, 'success');
    setTimeout(function(){ location.reload(); }, 700);
  } catch(err) { showMigrationToast(err.message, 'danger'); }
}
async function skipHistoricalRow() {
  const id = Number(document.getElementById('historicalReviewId')?.value || 0);
  if (!id) return;
  try {
    const data = await postHistoricalAction('finance_dues_migration_action.php', {action:'skip', id:id});
    showMigrationToast(data.message, 'success');
    setTimeout(function(){ location.reload(); }, 700);
  } catch(err) { showMigrationToast(err.message, 'danger'); }
}

async function archiveHistoricalRow(id) {
  const reason = window.prompt('Optional archive reason:', '') ?? null;
  if (reason === null) return;
  try {
    const data = await postHistoricalAction('finance_dues_archive_action.php', {
      action:'archive',
      id:id,
      reason:reason
    });
    showMigrationToast(data.message, 'success');
    setTimeout(function(){ location.reload(); }, 700);
  } catch(err) {
    showMigrationToast(err.message, 'danger');
  }
}

async function restoreHistoricalRow(id) {
  try {
    const data = await postHistoricalAction('finance_dues_archive_action.php', {
      action:'restore',
      id:id
    });
    showMigrationToast(data.message, 'success');
    setTimeout(function(){ location.reload(); }, 700);
  } catch(err) {
    showMigrationToast(err.message, 'danger');
  }
}

document.getElementById('importHistoricalDuesBtn')?.addEventListener('click', importHistoricalDuesFile);
document.getElementById('finalizeAllHistoricalBtn')?.addEventListener('click', finalizeAllHistoricalRows);

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
<div id="migrationToast" class="migration-toast info"></div>

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
