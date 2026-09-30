<?php

require_once __DIR__ . "/finance_helpers.php";
require_once __DIR__ . "/finance_report_builder.php";

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



function buildReportRedirect(

  string $base,

  bool $canPickPhase,

  string $phase

): string {

  $qs = [];



  if ($canPickPhase) {

    $qs[] =

      'phase=' .

      urlencode($phase);

  }



  if (

    isset($_GET['filter_status']) &&

    $_GET['filter_status'] !== ''

  ) {

    $qs[] =

      'filter_status=' .

      urlencode(

        (string)$_GET['filter_status']

      );

  }



  if (

    isset($_GET['filter_year']) &&

    $_GET['filter_year'] !== ''

  ) {

    $qs[] =

      'filter_year=' .

      urlencode(

        (string)$_GET['filter_year']

      );

  }



  if (

    isset($_GET['filter_month']) &&

    $_GET['filter_month'] !== ''

  ) {

    $qs[] =

      'filter_month=' .

      urlencode(

        (string)$_GET['filter_month']

      );

  }

  if (
    isset($_GET['filter_report_type']) &&
    $_GET['filter_report_type'] !== ''
  ) {
    $qs[] =
      'filter_report_type=' .
      urlencode((string)$_GET['filter_report_type']);
  }



  return

    $base .

    (

      $qs

        ? '?' . implode('&', $qs)

        : ''

    );

}



function reportFlash(

  string $type,

  string $message

): void {

  $_SESSION['finance_report_flash'] = [

    'type' => $type,

    'message' => $message

  ];

}





/* =========================

   CURRENT ADMIN / ROLE

   ========================= */

$stmt = $conn->prepare("

  SELECT

    id,

    email,

    full_name,

    role,

    position,

    phase

  FROM admins

  WHERE id = ?

  LIMIT 1

");

$stmt->bind_param("i", $adminId);

$stmt->execute();

$currentAdmin =

  $stmt

    ->get_result()

    ->fetch_assoc();

$stmt->close();



$adminEmail =

  trim(

    (string)(

      $currentAdmin['email']

      ?? ''

    )

  );



$adminName =

  trim(

    (string)(

      $currentAdmin['full_name']

      ?? ''

    )

  );



$adminRole =

  trim(

    (string)(

      $currentAdmin['role']

      ?? ''

    )

  );



$adminPosition =

  trim(

    (string)(

      $currentAdmin['position']

      ?? ''

    )

  );



$canRequestReports =

  ($adminPosition === 'Treasurer');



$canApproveReports =

  ($adminPosition === 'President');



/* =========================

   CSRF

   ========================= */

if (

  empty(

    $_SESSION[

      'csrf_finance_reports'

    ]

  )

) {

  $_SESSION[

    'csrf_finance_reports'

  ] = bin2hex(random_bytes(32));

}



$csrfToken =

  (string)

  $_SESSION[

    'csrf_finance_reports'

  ];



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $postedCsrf =

    (string)(

      $_POST['csrf']

      ?? ''

    );



  if (

    $postedCsrf === '' ||

    !hash_equals(

      $csrfToken,

      $postedCsrf

    )

  ) {

    reportFlash(

      'danger',

      'Your session token is no longer valid. Please refresh the page and try again.'

    );



    header(

      'Location: ' .

      buildReportRedirect(

        'finance_reports.php',

        $canPickPhase,

        $phase

      )

    );

    exit;

  }

}



$currentYear =

  (int)date('Y');



$currentMonth =

  (int)date('n');



$currentPeriodKey =

  ($currentYear * 100) +

  $currentMonth;



/* =========================

   REPORT SUPPORT DATA

   ========================= */

$reportTypeLabels = finance_report_type_labels();

$stmt = $conn->prepare("
  SELECT
    id,
    expense_date,
    description,
    amount,
    vendor_payee,
    requested_by,
    project_name
  FROM finance_expenses
  WHERE phase = ?
  ORDER BY expense_date DESC, id DESC
  LIMIT 500
");
$stmt->bind_param('s', $phase);
$stmt->execute();
$expenseOptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
  SELECT DISTINCT project_name
  FROM finance_expenses
  WHERE phase = ?
    AND project_name IS NOT NULL
    AND TRIM(project_name) <> ''
  ORDER BY project_name
  LIMIT 300
");
$stmt->bind_param('s', $phase);
$stmt->execute();
$projectOptions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$today = date('Y-m-d');
$defaultDateFrom = date('Y-m-01');
$defaultDateTo = $today;


/* =========================

   REQUEST REPORT

   ========================= */

if (isset($_POST['request_report'])) {

  if (!$canRequestReports) {
    reportFlash('danger', 'Only the Treasurer can submit financial report requests for President approval.');
    header('Location: ' . buildReportRedirect('finance_reports.php', $canPickPhase, $phase));
    exit;
  }

  $reportType = trim((string)($_POST['report_type'] ?? 'full_summary'));

  if (!finance_report_is_valid_type($reportType)) {
    reportFlash('danger', 'Please select a valid financial report type.');
    header('Location: ' . buildReportRedirect('finance_reports.php', $canPickPhase, $phase));
    exit;
  }

  $reportTitle = mb_substr(trim((string)($_POST['report_title'] ?? '')), 0, 180);
  $requestPurpose = mb_substr(trim((string)($_POST['request_purpose'] ?? '')), 0, 500);

  $year = (int)($_POST['report_year'] ?? $currentYear);
  $month = (int)($_POST['report_month'] ?? $currentMonth);
  $dateFrom = trim((string)($_POST['date_from'] ?? ''));
  $dateTo = trim((string)($_POST['date_to'] ?? ''));

  $targetType = null;
  $targetId = null;
  $projectName = null;

  if (in_array($reportType, ['full_summary', 'monthly_dues'], true)) {

    if ($year < 2000 || $year > $currentYear || $month < 1 || $month > 12) {
      reportFlash('danger', 'Please select a valid monthly report period.');
      header('Location: ' . buildReportRedirect('finance_reports.php', $canPickPhase, $phase));
      exit;
    }

    if ((($year * 100) + $month) > $currentPeriodKey) {
      reportFlash('danger', 'A report cannot be requested for a future month.');
      header('Location: ' . buildReportRedirect('finance_reports.php', $canPickPhase, $phase));
      exit;
    }

    [$dateFrom, $dateTo] = finance_report_month_dates($year, $month);

  } elseif ($reportType === 'specific_expense') {

    $targetId = (int)($_POST['target_id'] ?? 0);
    $targetType = 'expense';

    $stmt = $conn->prepare("
      SELECT id, expense_date, description
      FROM finance_expenses
      WHERE id = ?
        AND phase = ?
      LIMIT 1
    ");
    $stmt->bind_param('is', $targetId, $phase);
    $stmt->execute();
    $targetExpense = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$targetExpense) {
      reportFlash('danger', 'Please select a valid expense from this phase.');
      header('Location: ' . buildReportRedirect('finance_reports.php', $canPickPhase, $phase));
      exit;
    }

    $dateFrom = (string)$targetExpense['expense_date'];
    $dateTo = $dateFrom;
    $year = (int)date('Y', strtotime($dateFrom));
    $month = (int)date('n', strtotime($dateFrom));

    if ($reportTitle === '') {
      $reportTitle = 'Specific Expense - ' . (string)$targetExpense['description'];
    }

  } else {

    $validFrom = DateTime::createFromFormat('Y-m-d', $dateFrom);
    $validTo = DateTime::createFromFormat('Y-m-d', $dateTo);

    if (
      !$validFrom ||
      !$validTo ||
      $validFrom->format('Y-m-d') !== $dateFrom ||
      $validTo->format('Y-m-d') !== $dateTo
    ) {
      reportFlash('danger', 'Please select a valid report date range.');
      header('Location: ' . buildReportRedirect('finance_reports.php', $canPickPhase, $phase));
      exit;
    }

    if ($dateFrom > $dateTo) {
      reportFlash('danger', 'Report start date cannot be after the end date.');
      header('Location: ' . buildReportRedirect('finance_reports.php', $canPickPhase, $phase));
      exit;
    }

    if ($dateTo > $today) {
      reportFlash('danger', 'A report cannot include a future date.');
      header('Location: ' . buildReportRedirect('finance_reports.php', $canPickPhase, $phase));
      exit;
    }

    $year = (int)date('Y', strtotime($dateFrom));
    $month = (int)date('n', strtotime($dateFrom));

    if ($reportType === 'project') {
      $projectName = mb_substr(trim((string)($_POST['project_name'] ?? '')), 0, 180);

      if ($projectName === '') {
        reportFlash('danger', 'Please select or enter a Project / Activity Name.');
        header('Location: ' . buildReportRedirect('finance_reports.php', $canPickPhase, $phase));
        exit;
      }

      $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM finance_expenses
        WHERE phase = ?
          AND project_name = ?
          AND expense_date BETWEEN ? AND ?
      ");
      $stmt->bind_param('ssss', $phase, $projectName, $dateFrom, $dateTo);
      $stmt->execute();
      $projectExpenseCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
      $stmt->close();

      if ($projectExpenseCount <= 0) {
        reportFlash('warning', 'No documented expenses were found for that project within the selected date range.');
        header('Location: ' . buildReportRedirect('finance_reports.php', $canPickPhase, $phase));
        exit;
      }
    }
  }

  if ($reportTitle === '') {
    $reportTitle = finance_report_type_label($reportType);

    if (in_array($reportType, ['full_summary', 'monthly_dues'], true)) {
      $reportTitle .= ' - ' . date('F Y', strtotime($dateFrom));
    } elseif ($reportType !== 'specific_expense') {
      $reportTitle .= ' - ' . date('M d, Y', strtotime($dateFrom))
        . ' to ' . date('M d, Y', strtotime($dateTo));
    }

    if ($reportType === 'project' && $projectName) {
      $reportTitle = $projectName . ' - Project Financial Report';
    }
  }

  $scopePayload = [
    'phase' => $phase,
    'report_type' => $reportType,
    'report_year' => $year,
    'report_month' => $month,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'target_type' => $targetType,
    'target_id' => $targetId,
    'project_name' => $projectName,
  ];

  $scopeHash = hash(
    'sha256',
    json_encode($scopePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
  );

  try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("
      SELECT id
      FROM finance_report_requests
      WHERE phase = ?
        AND scope_hash = ?
        AND status = 'pending'
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->bind_param('ss', $phase, $scopeHash);
    $stmt->execute();
    $duplicatePending = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicatePending) {
      $conn->rollback();
      reportFlash('warning', 'An identical pending report request already exists. Review the existing request first.');
      header('Location: ' . buildReportRedirect('finance_reports.php', $canPickPhase, $phase));
      exit;
    }

    $stmt = $conn->prepare("
      INSERT INTO finance_report_requests
      (
        phase,
        report_type,
        report_title,
        report_year,
        report_month,
        date_from,
        date_to,
        target_type,
        target_id,
        project_name,
        request_purpose,
        scope_hash,
        status,
        requested_by_admin_id
      )
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'pending', ?)
    ");

    $stmt->bind_param(
      'sssiisssisssi',
      $phase,
      $reportType,
      $reportTitle,
      $year,
      $month,
      $dateFrom,
      $dateTo,
      $targetType,
      $targetId,
      $projectName,
      $requestPurpose,
      $scopeHash,
      $adminId
    );

    $stmt->execute();
    $requestId = (int)$conn->insert_id;
    $stmt->close();

    $conn->commit();

    if (function_exists('finance_audit_log')) {
      finance_audit_log(
        $conn,
        'Financial report requested',
        'finance_report',
        $requestId,
        $reportTitle,
        null,
        [
          'report_type' => $reportType,
          'date_from' => $dateFrom,
          'date_to' => $dateTo,
          'target_id' => $targetId,
          'project_name' => $projectName,
          'request_purpose' => $requestPurpose,
        ],
        null,
        $adminId,
        $phase
      );
    }

    reportFlash('success', 'Financial report request sent to the President for review.');

  } catch (Throwable $e) {
    try {
      $conn->rollback();
    } catch (Throwable $ignored) {
    }

    error_log('Finance report request failed: ' . $e->getMessage());

    reportFlash(
      'danger',
      'The report request could not be submitted. Make sure the flexible report database update has been installed.'
    );
  }

  header('Location: ' . buildReportRedirect('finance_reports.php', $canPickPhase, $phase));
  exit;
}



/* =========================

   FREEZE LEGACY APPROVED REPORT

   ========================= */

if (isset($_POST['freeze_legacy_report'])) {

  if (!$canApproveReports) {

    reportFlash(

      'danger',

      'Only the President can freeze a legacy approved financial report.'

    );



    header(

      'Location: ' .

      buildReportRedirect(

        'finance_reports.php',

        $canPickPhase,

        $phase

      )

    );

    exit;

  }



  $requestId =

    (int)(

      $_POST['request_id']

      ?? 0

    );



  try {

    $conn->begin_transaction();



    $stmt = $conn->prepare("

      SELECT

        r.id,

        r.status,

        r.report_type,

        r.report_title,

        r.report_year,

        r.report_month,

        r.date_from,

        r.date_to,

        r.target_type,

        r.target_id,

        r.project_name,

        r.request_purpose,

        r.requested_by_admin_id,

        r.president_approved_by_email,

        r.president_action_at,

        r.president_remarks,

        s.id AS snapshot_id

      FROM finance_report_requests r

      LEFT JOIN finance_report_snapshots s

        ON s.report_request_id = r.id

      WHERE r.id = ?

        AND r.phase = ?

      LIMIT 1

      FOR UPDATE

    ");

    $stmt->bind_param(

      "is",

      $requestId,

      $phase

    );

    $stmt->execute();

    $legacyRow =

      $stmt

        ->get_result()

        ->fetch_assoc();

    $stmt->close();



    if (

      !$legacyRow ||

      (string)$legacyRow['status'] !== 'approved'

    ) {

      $conn->rollback();



      reportFlash(

        'danger',

        'Only an existing approved report can be frozen as a legacy snapshot.'

      );



      header(

        'Location: ' .

        buildReportRedirect(

          'finance_reports.php',

          $canPickPhase,

          $phase

        )

      );

      exit;

    }



    if (!empty($legacyRow['snapshot_id'])) {

      $conn->rollback();



      reportFlash(

        'info',

        'This approved report already has a frozen snapshot.'

      );



      header(

        'Location: ' .

        buildReportRedirect(

          'finance_reports.php',

          $canPickPhase,

          $phase

        )

      );

      exit;

    }



    $legacyApprovedAt =

      trim(

        (string)(

          $legacyRow['president_action_at']

          ?? ''

        )

      );



    if ($legacyApprovedAt === '') {

      $legacyApprovedAt =

        date('Y-m-d H:i:s');

    }



    $legacyApprovedBy =

      trim(

        (string)(

          $legacyRow['president_approved_by_email']

          ?? ''

        )

      );



    if ($legacyApprovedBy === '') {

      $legacyApprovedBy =

        $adminEmail;

    }



    $legacyRequest = $legacyRow;
    $legacyRequest['phase'] = $phase;
    $legacyRequest['report_type'] =
      trim((string)($legacyRow['report_type'] ?? '')) !== ''
        ? (string)$legacyRow['report_type']
        : 'full_summary';

    $snapshot =
      buildFinanceReportSnapshotV2(
        $conn,
        $legacyRequest,
        isset($legacyRow['requested_by_admin_id'])
          ? (int)$legacyRow['requested_by_admin_id']
          : null,
        $legacyApprovedBy,
        (string)($legacyRow['president_remarks'] ?? ''),
        $legacyApprovedAt
      );



    /*

    |--------------------------------------------------------------------------

    | Legacy note

    |--------------------------------------------------------------------------

    | Historical live data may already have changed since the original approval.

    | The snapshot is therefore frozen from the data available NOW.

    */

    $snapshot['legacy_backfill'] = true;

    $snapshot['legacy_backfill_created_at'] =

      date('Y-m-d H:i:s');



    saveFinanceReportSnapshotV2(

      $conn,

      $requestId,

      $phase,

      (int)$legacyRow['report_year'],

      (int)$legacyRow['report_month'],

      $snapshot,

      $legacyApprovedBy,

      $legacyApprovedAt

    );



    $conn->commit();



    reportFlash(

      'success',

      'Legacy approved report frozen successfully using the financial data currently available.'

    );



  } catch (Throwable $e) {

    try {

      $conn->rollback();

    } catch (Throwable $ignored) {

    }



    error_log(

      'Legacy finance report snapshot failed: ' .

      $e->getMessage()

    );



    reportFlash(

      'danger',

      'The legacy report could not be frozen. Please try again.'

    );

  }



  header(

    'Location: ' .

    buildReportRedirect(

      'finance_reports.php',

      $canPickPhase,

      $phase

    )

  );

  exit;

}



/* =========================

   PRESIDENT APPROVE / REJECT

   ========================= */

if (isset($_POST['report_action'])) {

  if (!$canApproveReports) {

    reportFlash(

      'danger',

      'Only the President can approve or reject financial reports.'

    );



    header(

      'Location: ' .

      buildReportRedirect(

        'finance_reports.php',

        $canPickPhase,

        $phase

      )

    );

    exit;

  }



  $requestId =

    (int)(

      $_POST['request_id']

      ?? 0

    );



  $requestedAction =

    (string)(

      $_POST['report_action']

      ?? ''

    );



  if (

    !in_array(

      $requestedAction,

      [

        'approve',

        'reject'

      ],

      true

    )

  ) {

    reportFlash(

      'danger',

      'Invalid report action.'

    );



    header(

      'Location: ' .

      buildReportRedirect(

        'finance_reports.php',

        $canPickPhase,

        $phase

      )

    );

    exit;

  }



  $remarks =

    mb_substr(

      trim(

        (string)(

          $_POST['remarks']

          ?? ''

        )

      ),

      0,

      255

    );



  if (

    $requestedAction === 'reject' &&

    $remarks === ''

  ) {

    reportFlash(

      'danger',

      'Please provide a reason when rejecting a financial report.'

    );



    header(

      'Location: ' .

      buildReportRedirect(

        'finance_reports.php',

        $canPickPhase,

        $phase

      )

    );

    exit;

  }



  $newStatus =

    $requestedAction === 'approve'

      ? 'approved'

      : 'rejected';



  try {

    $conn->begin_transaction();



    $stmt = $conn->prepare("

      SELECT

        id,

        status,

        report_type,

        report_title,

        report_year,

        report_month,

        date_from,

        date_to,

        target_type,

        target_id,

        project_name,

        request_purpose,

        requested_by_admin_id

      FROM finance_report_requests

      WHERE id = ?

        AND phase = ?

      LIMIT 1

      FOR UPDATE

    ");

    $stmt->bind_param(

      "is",

      $requestId,

      $phase

    );

    $stmt->execute();

    $requestRow =

      $stmt

        ->get_result()

        ->fetch_assoc();

    $stmt->close();



    if (!$requestRow) {

      $conn->rollback();



      reportFlash(

        'danger',

        'The report request could not be found for this phase.'

      );



      header(

        'Location: ' .

        buildReportRedirect(

          'finance_reports.php',

          $canPickPhase,

          $phase

        )

      );

      exit;

    }



    if (

      (string)$requestRow['status']

      !== 'pending'

    ) {

      $conn->rollback();



      reportFlash(

        'warning',

        'This report has already been reviewed. No changes were made.'

      );



      header(

        'Location: ' .

        buildReportRedirect(

          'finance_reports.php',

          $canPickPhase,

          $phase

        )

      );

      exit;

    }



    $approvalAt =

      date('Y-m-d H:i:s');



    if ($newStatus === 'approved') {

      $requestRow['phase'] = $phase;

      $snapshot =
        buildFinanceReportSnapshotV2(
          $conn,
          $requestRow,
          isset($requestRow['requested_by_admin_id'])
            ? (int)$requestRow['requested_by_admin_id']
            : null,
          $adminEmail,
          $remarks,
          $approvalAt
        );



      saveFinanceReportSnapshotV2(

        $conn,

        $requestId,

        $phase,

        (int)$requestRow['report_year'],

        (int)$requestRow['report_month'],

        $snapshot,

        $adminEmail,

        $approvalAt

      );

    }



    $stmt = $conn->prepare("

      UPDATE finance_report_requests

      SET

        status = ?,

        president_approved_by_email = ?,

        president_action_at = ?,

        president_remarks = ?

      WHERE id = ?

        AND phase = ?

        AND status = 'pending'

    ");

    $stmt->bind_param(

      "ssssis",

      $newStatus,

      $adminEmail,

      $approvalAt,

      $remarks,

      $requestId,

      $phase

    );

    $stmt->execute();



    $changed =

      $stmt->affected_rows === 1;



    $stmt->close();



    if (!$changed) {

      $conn->rollback();



      reportFlash(

        'warning',

        'The report status changed before your action was saved. Please refresh and review it again.'

      );



      header(

        'Location: ' .

        buildReportRedirect(

          'finance_reports.php',

          $canPickPhase,

          $phase

        )

      );

      exit;

    }



    $conn->commit();

    if (function_exists('finance_audit_log')) {
      finance_audit_log(
        $conn,
        $newStatus === 'approved'
          ? 'Financial report approved'
          : 'Financial report rejected',
        'finance_report',
        $requestId,
        (string)(
          $requestRow['report_title']
          ?? finance_report_type_label(
            (string)($requestRow['report_type'] ?? 'full_summary')
          )
        ),
        ['status' => 'pending'],
        [
          'status' => $newStatus,
          'remarks' => $remarks,
          'acted_by' => $adminEmail,
        ],
        null,
        $adminId,
        $phase
      );
    }




    reportFlash(

      'success',

      $newStatus === 'approved'

        ? 'Financial report approved successfully.'

        : 'Financial report rejected and returned to the Treasurer.'

    );



  } catch (Throwable $e) {

    try {

      $conn->rollback();

    } catch (Throwable $ignored) {

    }



    error_log(

      'Finance report action failed: ' .

      $e->getMessage()

    );



    reportFlash(

      'danger',

      'The report action could not be completed. Please try again.'

    );

  }



  header(

    'Location: ' .

    buildReportRedirect(

      'finance_reports.php',

      $canPickPhase,

      $phase

    )

  );

  exit;

}



/* =========================

   FILTERS

   ========================= */

$filterStatus =

  trim(

    (string)(

      $_GET['filter_status']

      ?? ''

    )

  );



$filterYear =

  (int)(

    $_GET['filter_year']

    ?? 0

  );



$filterMonth =

  (int)(

    $_GET['filter_month']

    ?? 0

  );

$filterReportType =
  trim(
    (string)(
      $_GET['filter_report_type']
      ?? ''
    )
  );

if (
  $filterReportType !== '' &&
  !finance_report_is_valid_type($filterReportType)
) {
  $filterReportType = '';
}



$allowedStatuses = [

  'pending',

  'approved',

  'rejected'

];



if (

  !in_array(

    $filterStatus,

    $allowedStatuses,

    true

  )

) {

  $filterStatus = '';

}



if (

  $filterMonth < 1 ||

  $filterMonth > 12

) {

  $filterMonth = 0;

}



if (

  $filterYear < 2000 ||

  $filterYear > $currentYear

) {

  $filterYear = 0;

}



/* Default page view: pending requests */

if (

  $filterStatus === '' &&
  $filterYear === 0 &&
  $filterMonth === 0 &&
  $filterReportType === ''

) {

  $filterStatus = 'pending';

}



/* =========================

   STATUS COUNTS

   ========================= */

$stmt = $conn->prepare("

  SELECT

    SUM(status = 'pending') AS pending_count,

    SUM(status = 'approved') AS approved_count,

    SUM(status = 'rejected') AS rejected_count

  FROM finance_report_requests

  WHERE phase = ?

");

$stmt->bind_param("s", $phase);

$stmt->execute();

$countRow =

  $stmt

    ->get_result()

    ->fetch_assoc();

$stmt->close();



$pendingCount =

  (int)(

    $countRow['pending_count']

    ?? 0

  );



$approvedCount =

  (int)(

    $countRow['approved_count']

    ?? 0

  );



$rejectedCount =

  (int)(

    $countRow['rejected_count']

    ?? 0

  );



/* =========================

   FETCH FILTERED REQUESTS

   ========================= */

$sql = "

  SELECT

    r.*,

    a.email AS requested_by_email,

    a.full_name AS requested_by_name,

    a.position AS requested_by_position,

    s.created_at AS snapshot_created_at

  FROM finance_report_requests r

  LEFT JOIN admins a

    ON a.id = r.requested_by_admin_id

  LEFT JOIN finance_report_snapshots s

    ON s.report_request_id = r.id

  WHERE r.phase = ?

";



$types = "s";

$params = [$phase];



if ($filterStatus !== '') {

  $sql .=

    " AND r.status = ?";

  $types .= "s";

  $params[] =

    $filterStatus;

}



if ($filterYear > 0) {

  $sql .=

    " AND r.report_year = ?";

  $types .= "i";

  $params[] =

    $filterYear;

}



if ($filterMonth > 0) {

  $sql .=

    " AND r.report_month = ?";

  $types .= "i";

  $params[] =

    $filterMonth;

}

if ($filterReportType !== '') {
  $sql .=
    " AND r.report_type = ?";
  $types .= "s";
  $params[] = $filterReportType;
}



$sql .= "

  ORDER BY

    r.requested_at DESC,

    r.id DESC

  LIMIT 200

";



$stmt =

  $conn->prepare($sql);



$stmt->bind_param(

  $types,

  ...$params

);



$stmt->execute();



$rows =

  $stmt

    ->get_result()

    ->fetch_all(MYSQLI_ASSOC);



$stmt->close();



/* =========================

   HISTORY

   ========================= */

$historyStmt =

  $conn->prepare("

    SELECT

      r.*,

      a.email AS requested_by_email,

      a.full_name AS requested_by_name,

      a.position AS requested_by_position,

      s.created_at AS snapshot_created_at

    FROM finance_report_requests r

    LEFT JOIN admins a

      ON a.id = r.requested_by_admin_id

    LEFT JOIN finance_report_snapshots s

      ON s.report_request_id = r.id

    WHERE r.phase = ?

    ORDER BY

      COALESCE(

        r.president_action_at,

        r.requested_at

      ) DESC,

      r.id DESC

    LIMIT 100

  ");



$historyStmt->bind_param(

  "s",

  $phase

);



$historyStmt->execute();



$historyRows =

  $historyStmt

    ->get_result()

    ->fetch_all(MYSQLI_ASSOC);



$historyStmt->close();



$defYear =

  $currentYear;



$defMonth =

  $currentMonth;



$financeFlash =

  $_SESSION[

    'finance_report_flash'

  ]

  ?? null;



unset(

  $_SESSION[

    'finance_report_flash'

  ]

);

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

     FINANCE REPORTS - DARK MODE EXTENSIONS

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

  html.dark .card-box strong,

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



  html.dark option {

    background: var(--admin-input);

    color: var(--admin-text);

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



  /* Badges */

  html.dark .badge-secondary {

    background: var(--admin-surface-3) !important;

    color: #cbd5e1 !important;

  }



  html.dark .badge-warning {

    background: #d97706 !important;

    color: #fff !important;

  }



  html.dark .badge-success {

    background: #15803d !important;

    color: #fff !important;

  }



  html.dark .badge-danger {

    background: #b91c1c !important;

    color: #fff !important;

  }



  html.dark .badge-primary {

    background: #2563eb !important;

    color: #fff !important;

  }



  /* Report / History tables */

  html.dark .table {

    color: var(--admin-text) !important;

    background: var(--admin-surface) !important;

    border-color: var(--admin-border) !important;

  }



  html.dark .table thead th {

    background: var(--admin-surface-2) !important;

    color: #f8fafc !important;

    border-color: var(--admin-border) !important;

  }



  html.dark .table tbody td,

  html.dark .table tbody th {

    color: var(--admin-text) !important;

    border-color: var(--admin-border) !important;

  }



  html.dark .table-bordered,

  html.dark .table-bordered td,

  html.dark .table-bordered th {

    border-color: var(--admin-border) !important;

  }



  html.dark .table-striped tbody tr:nth-of-type(odd),

  html.dark .table-striped tbody tr:nth-of-type(odd) > * {

    background: var(--admin-surface-2) !important;

    color: var(--admin-text) !important;

  }



  html.dark .table-striped tbody tr:nth-of-type(even),

  html.dark .table-striped tbody tr:nth-of-type(even) > * {

    background: var(--admin-surface) !important;

    color: var(--admin-text) !important;

  }



  html.dark .table-hover tbody tr:hover,

  html.dark .table-hover tbody tr:hover > * {

    background: var(--admin-hover) !important;

    color: #fff !important;

  }



  /* Export / action buttons */

  html.dark .btn-outline-primary {

    color: #93c5fd !important;

    border-color: #3b82f6 !important;

  }



  html.dark .btn-outline-primary:hover {

    color: #fff !important;

    background: #2563eb !important;

    border-color: #2563eb !important;

  }



  html.dark .btn-outline-success {

    color: #86efac !important;

    border-color: #22c55e !important;

  }



  html.dark .btn-outline-success:hover {

    color: #fff !important;

    background: #16a34a !important;

    border-color: #16a34a !important;

  }



  html.dark .btn-outline-warning {

    color: #fcd34d !important;

    border-color: #f59e0b !important;

  }



  html.dark .btn-outline-warning:hover {

    color: #111827 !important;

    background: #f59e0b !important;

    border-color: #f59e0b !important;

  }



  html.dark .btn-secondary {

    background: var(--admin-surface-3) !important;

    border-color: var(--admin-border) !important;

    color: #e5e7eb !important;

  }



  html.dark .btn-secondary:hover {

    background: var(--admin-hover) !important;

    border-color: var(--admin-border) !important;

    color: #fff !important;

  }



  /* Keep form-inline labels readable */

  html.dark .form-inline label {

    color: var(--admin-text) !important;

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

  <div class="mobile-menu-overlay"></div>



  <div class="main-container">

    <div class="pd-ltr-20">



      <div class="page-header mb-20">

        <div class="row">

          <div class="col-md-6 col-sm-12">

            <div class="title"><h4>Financial Reports</h4></div>

            <div class="text-secondary">

              Phase: <b><?= htmlspecialchars($phase) ?></b>

            </div>

          </div>



          <div class="col-md-6 col-sm-12 text-right">

            <?php if ($canPickPhase): ?>

              <form method="get" class="d-inline-block">

                <select name="phase" class="form-control d-inline-block" style="width:200px" onchange="this.form.submit()">

                  <?php foreach (['Phase 1','Phase 2','Phase 3'] as $p): ?>

                    <option value="<?= htmlspecialchars($p) ?>" <?= $p === $phase ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>

                  <?php endforeach; ?>

                </select>



                <?php if ($filterStatus !== ''): ?>

                  <input type="hidden" name="filter_status" value="<?= htmlspecialchars($filterStatus) ?>">

                <?php endif; ?>

                <?php if ($filterYear > 0): ?>

                  <input type="hidden" name="filter_year" value="<?= (int)$filterYear ?>">

                <?php endif; ?>

                <?php if ($filterMonth > 0): ?>

                  <input type="hidden" name="filter_month" value="<?= (int)$filterMonth ?>">

                <?php endif; ?>

              
                <?php if ($filterReportType !== ''): ?>
                  <input type="hidden" name="filter_report_type" value="<?= htmlspecialchars($filterReportType) ?>">
                <?php endif; ?>
</form>

            <?php endif; ?>

          </div>

        </div>

      </div>



      <?php if ($financeFlash): ?>

        <div

          class="alert alert-<?= htmlspecialchars($financeFlash['type'] ?? 'info') ?> mb-20"

          role="alert"

        >

          <?= htmlspecialchars($financeFlash['message'] ?? '') ?>

        </div>

      <?php endif; ?>



      <div class="card-box mb-20 p-3">

        <div class="row">

          <div class="col-md-6 mb-3 mb-md-0">

            <h5 class="mb-1">Report Workflow</h5>

            <div class="text-secondary">

              Treasurer submits → President reviews → Approved reports become exportable.

            </div>

          </div>



          <div class="col-md-6">

            <div class="d-flex flex-wrap justify-content-md-end">

              <span class="badge badge-warning p-2 mr-2 mb-2">

                Pending: <?= $pendingCount ?>

              </span>



              <span class="badge badge-success p-2 mr-2 mb-2">

                Approved: <?= $approvedCount ?>

              </span>



              <span class="badge badge-danger p-2 mb-2">

                Rejected: <?= $rejectedCount ?>

              </span>

            </div>

          </div>

        </div>



        <hr>



        <div class="d-flex flex-wrap justify-content-between align-items-center">

          <div class="text-secondary">

            Signed in as:

            <b><?= htmlspecialchars($adminPosition !== '' ? $adminPosition : $adminRole) ?></b>

            <?php if ($adminName !== ''): ?>

              — <?= htmlspecialchars($adminName) ?>

            <?php endif; ?>

          </div>



          <?php if ($canRequestReports): ?>

            <span class="badge badge-primary p-2">

              Treasurer • Submit Reports

            </span>

          <?php elseif ($canApproveReports): ?>

            <span class="badge badge-success p-2">

              President • Review Reports

            </span>

          <?php else: ?>

            <span class="badge badge-secondary p-2">

              View Only

            </span>

          <?php endif; ?>

        </div>

      </div>



      <div class="card-box mb-20 p-4">

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-1">Request Financial Report</h5>
            <div class="text-secondary">
              Request a complete report or a focused report for a specific Finance area.
            </div>
          </div>
          <?php if ($canRequestReports): ?>
            <span class="badge badge-primary p-2 mt-2 mt-md-0">Treasurer • Request</span>
          <?php endif; ?>
        </div>

        <?php if ($canRequestReports): ?>

          <form method="post" id="financeReportRequestForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">

            <div class="row">

              <div class="col-lg-4 col-md-6 mb-3">
                <label>Report Type</label>
                <select name="report_type" id="reportType" class="form-control" required>
                  <?php foreach ($reportTypeLabels as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>">
                      <?= htmlspecialchars($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-lg-4 col-md-6 mb-3">
                <label>Report Title</label>
                <input type="text" name="report_title" class="form-control" maxlength="180" placeholder="Optional custom title">
              </div>

              <div class="col-lg-4 col-md-12 mb-3">
                <label>Request Purpose / Notes</label>
                <input type="text" name="request_purpose" class="form-control" maxlength="500" placeholder="Why is this report being requested?">
              </div>

            </div>

            <div id="monthlyScope" class="report-scope-panel">
              <div class="row">
                <div class="col-md-3 mb-3">
                  <label>Year</label>
                  <input class="form-control" type="number" name="report_year" id="reportYear" min="2000" max="<?= (int)$currentYear ?>" value="<?= (int)$defYear ?>">
                </div>

                <div class="col-md-3 mb-3">
                  <label>Month</label>
                  <select class="form-control" name="report_month" id="reportMonth">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                      <option value="<?= $m ?>" <?= $m === $defMonth ? 'selected' : '' ?> <?= ($defYear === $currentYear && $m > $currentMonth) ? 'disabled' : '' ?>>
                        <?= htmlspecialchars(date('F', mktime(0, 0, 0, $m, 1))) ?>
                      </option>
                    <?php endfor; ?>
                  </select>
                </div>
              </div>
            </div>

            <div id="dateRangeScope" class="report-scope-panel" style="display:none;">
              <div class="row">
                <div class="col-md-3 mb-3">
                  <label>Date From</label>
                  <input type="date" name="date_from" id="reportDateFrom" class="form-control" value="<?= htmlspecialchars($defaultDateFrom) ?>" max="<?= htmlspecialchars($today) ?>">
                </div>

                <div class="col-md-3 mb-3">
                  <label>Date To</label>
                  <input type="date" name="date_to" id="reportDateTo" class="form-control" value="<?= htmlspecialchars($defaultDateTo) ?>" max="<?= htmlspecialchars($today) ?>">
                </div>
              </div>
            </div>

            <div id="specificExpenseScope" class="report-scope-panel" style="display:none;">
              <div class="row">
                <div class="col-lg-8 mb-3">
                  <label>Select Expense</label>
                  <select name="target_id" id="reportTargetId" class="form-control">
                    <option value="">Choose documented expense...</option>
                    <?php foreach ($expenseOptions as $expenseOption): ?>
                      <option value="<?= (int)$expenseOption['id'] ?>">
                        #<?= (int)$expenseOption['id'] ?>
                        — <?= htmlspecialchars($expenseOption['expense_date']) ?>
                        — <?= htmlspecialchars($expenseOption['description']) ?>
                        — ₱<?= number_format((float)$expenseOption['amount'], 2) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>

            <div id="projectScope" class="report-scope-panel" style="display:none;">
              <div class="row">
                <div class="col-lg-6 mb-3">
                  <label>Project / Activity Name</label>
                  <input type="text" name="project_name" id="reportProjectName" class="form-control" list="projectNameOptions" maxlength="180" placeholder="Example: Clubhouse Roof Repair">
                  <datalist id="projectNameOptions">
                    <?php foreach ($projectOptions as $projectOption): ?>
                      <option value="<?= htmlspecialchars((string)$projectOption['project_name']) ?>">
                    <?php endforeach; ?>
                  </datalist>
                  <small class="text-secondary">
                    Related expenses must use the same Project / Activity Name in Expense Tracking.
                  </small>
                </div>
              </div>
            </div>

            <button class="btn btn-primary" name="request_report" value="1" type="submit">
              Send to President
            </button>
          </form>

          <small class="text-secondary d-block mt-3">
            Approved reports are frozen as snapshots, so later Finance changes do not alter previously approved exports.
          </small>

        <?php else: ?>

          <div class="alert alert-light border mb-0">
            <?php if ($canApproveReports): ?>
              The Treasurer submits financial reports. You can review pending requests below.
            <?php else: ?>
              Financial report submission is available to the Treasurer only.
            <?php endif; ?>
          </div>

        <?php endif; ?>

      </div>



<div class="card-box mb-20 p-3">

        <h5 class="mb-3">Filter Report Requests</h5>



        <form method="get" class="row">

          <?php if ($canPickPhase): ?>

            <input type="hidden" name="phase" value="<?= htmlspecialchars($phase) ?>">

          <?php endif; ?>



          <div class="col-md-3 mb-2">

            <label>Status</label>

            <select name="filter_status" class="form-control">

              <option value="">All Status</option>

              <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>

              <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>Approved</option>

              <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>

            </select>

          </div>



          <div class="col-md-3 mb-2">
            <label>Report Type</label>
            <select name="filter_report_type" class="form-control">
              <option value="">All Types</option>
              <?php foreach ($reportTypeLabels as $typeValue => $typeLabel): ?>
                <option
                  value="<?= htmlspecialchars($typeValue) ?>"
                  <?= $filterReportType === $typeValue ? 'selected' : '' ?>
                >
                  <?= htmlspecialchars($typeLabel) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>



          <div class="col-md-2 mb-2">

            <label>Year</label>

            <input type="number" name="filter_year" class="form-control" value="<?= $filterYear > 0 ? (int)$filterYear : '' ?>" placeholder="All Years">

          </div>



          <div class="col-md-2 mb-2">

            <label>Month</label>

            <select name="filter_month" class="form-control">

              <option value="">All Months</option>

              <?php for ($m = 1; $m <= 12; $m++): ?>

                <option value="<?= $m ?>" <?= $filterMonth === $m ? 'selected' : '' ?>>

                  <?= date('F', mktime(0, 0, 0, $m, 1)) ?>

                </option>

              <?php endfor; ?>

            </select>

          </div>



          <div class="col-md-2 mb-2 d-flex align-items-end">

            <button type="submit" class="btn btn-info mr-2">Apply Filter</button>

            <a href="finance_reports.php<?= $canPickPhase ? ('?phase=' . urlencode($phase)) : '' ?>" class="btn btn-secondary">Reset</a>

          </div>

        </form>

      </div>



      <div class="card-box mb-20 p-3">

        <h5 class="mb-3">Report Requests</h5>



        <div class="table-responsive">

          <table class="table table-striped table-hover">

            <thead>

              <tr>

                <th>Requested At</th>

                <th>Report</th>

                <th>Scope</th>

                <th>Status</th>

                <th>Requested By</th>

                <th>Action Date</th>

                <th>Remarks</th>

                <th>Export</th>

                <th>Action</th>

              </tr>

            </thead>



            <tbody>

              <?php foreach ($rows as $r): ?>

                <tr>

                  <td><?= htmlspecialchars($r['requested_at']) ?></td>

                  <td>
                    <div class="weight-600">
                      <?= htmlspecialchars($r['report_title'] ?: finance_report_type_label((string)($r['report_type'] ?? 'full_summary'))) ?>
                    </div>
                    <div class="small text-secondary">
                      <?= htmlspecialchars(finance_report_type_label((string)($r['report_type'] ?? 'full_summary'))) ?>
                    </div>
                  </td>

                  <td>
                    <?php
                      $scopeFrom = trim((string)($r['date_from'] ?? ''));
                      $scopeTo = trim((string)($r['date_to'] ?? ''));
                    ?>

                    <?php if (($r['report_type'] ?? 'full_summary') === 'specific_expense'): ?>
                      Expense #<?= (int)($r['target_id'] ?? 0) ?>
                    <?php elseif (($r['report_type'] ?? '') === 'project'): ?>
                      <div><?= htmlspecialchars($r['project_name'] ?? '-') ?></div>
                      <div class="small text-secondary">
                        <?= htmlspecialchars($scopeFrom ?: '-') ?> to <?= htmlspecialchars($scopeTo ?: '-') ?>
                      </div>
                    <?php elseif ($scopeFrom !== '' && $scopeTo !== ''): ?>
                      <?= htmlspecialchars($scopeFrom) ?> to <?= htmlspecialchars($scopeTo) ?>
                    <?php else: ?>
                      <?= htmlspecialchars($r['report_year'] . "-" . str_pad((string)$r['report_month'], 2, '0', STR_PAD_LEFT)) ?>
                    <?php endif; ?>
                  </td>



                  <td>

                    <?php

                      $st = $r['status'] ?? 'pending';

                      $badge = $st === 'approved' ? 'badge-success' : ($st === 'rejected' ? 'badge-danger' : 'badge-warning');

                    ?>

                    <span class="badge <?= $badge ?>"><?= htmlspecialchars($st) ?></span>

                  </td>



                  <td>

                    <?php if (!empty($r['requested_by_name'])): ?>

                      <?= htmlspecialchars($r['requested_by_name']) ?>

                      <div class="small text-secondary">

                        <?= htmlspecialchars($r['requested_by_position'] ?? '') ?>

                      </div>

                      <div class="small text-secondary">

                        <?= htmlspecialchars($r['requested_by_email'] ?? '') ?>

                      </div>

                    <?php else: ?>

                      <?= htmlspecialchars($r['requested_by_email'] ?? '-') ?>

                    <?php endif; ?>

                  </td>

                  <td>

                    <?= htmlspecialchars($r['president_action_at'] ?? '-') ?>



                    <?php if (!empty($r['president_approved_by_email'])): ?>

                      <div class="small text-secondary">

                        <?= htmlspecialchars($r['president_approved_by_email']) ?>

                      </div>

                    <?php endif; ?>

                  </td>



                  <td><?= htmlspecialchars($r['president_remarks'] ?? '') ?></td>



                  <td style="min-width:220px">

                    <?php if (

                      ($r['status'] ?? '') === 'approved' &&

                      !empty($r['snapshot_created_at'])

                    ): ?>

                      <?php

                        $qs = "request_id=" . (int)$r['id'];

                      ?>

                      <a class="btn btn-sm btn-outline-success" target="_blank" rel="noopener noreferrer" href="finance_reports_export.php?format=pdf&<?= $qs ?>">PDF</a>

                      <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer" href="finance_reports_export.php?format=excel&<?= $qs ?>">Excel</a>

                    <?php elseif (($r['status'] ?? '') === 'approved'): ?>

                      <span class="badge badge-warning d-block mb-1">

                        Legacy / Not Frozen

                      </span>



                      <?php if ($canApproveReports): ?>

                        <form method="post" class="mt-1">

                          <input

                            type="hidden"

                            name="csrf"

                            value="<?= htmlspecialchars($csrfToken) ?>"

                          >

                          <input

                            type="hidden"

                            name="request_id"

                            value="<?= (int)$r['id'] ?>"

                          >

                          <button

                            class="btn btn-sm btn-outline-warning"

                            type="submit"

                            name="freeze_legacy_report"

                            value="1"

                          >

                            Freeze Current Data

                          </button>

                        </form>

                      <?php endif; ?>

                    <?php else: ?>

                      <span class="text-secondary">—</span>

                    <?php endif; ?>

                  </td>



                  <td style="min-width:220px">

                    <?php if (($r['status'] ?? '') === 'pending' && $canApproveReports): ?>

                      <form method="post">

                        <input

                          type="hidden"

                          name="csrf"

                          value="<?= htmlspecialchars($csrfToken) ?>"

                        >



                        <input

                          type="hidden"

                          name="request_id"

                          value="<?= (int)$r['id'] ?>"

                        >



                        <input

                          type="text"

                          name="remarks"

                          maxlength="255"

                          class="form-control mb-2"

                          placeholder="Remarks / rejection reason"

                        >



                        <button

                          class="btn btn-sm btn-success mr-1"

                          type="submit"

                          name="report_action"

                          value="approve"

                        >

                          Approve

                        </button>



                        <button

                          class="btn btn-sm btn-danger"

                          type="submit"

                          name="report_action"

                          value="reject"

                        >

                          Reject

                        </button>

                      </form>



                      <small class="text-secondary d-block mt-1">

                        Rejection requires a reason.

                      </small>



                    <?php elseif (($r['status'] ?? '') === 'pending'): ?>

                      <span class="text-secondary">

                        Waiting for President

                      </span>

                    <?php else: ?>

                      <span class="text-secondary">-</span>

                    <?php endif; ?>

                  </td>

                </tr>

              <?php endforeach; ?>



              <?php if (!$rows): ?>

                <tr>

                  <td colspan="9" class="text-center text-secondary">

                    No report requests found for the selected filter.

                  </td>

                </tr>

              <?php endif; ?>

            </tbody>

          </table>

        </div>

      </div>



      <!-- HISTORY SECTION -->

      <div class="card-box mb-20 p-3">

        <div class="d-flex justify-content-between align-items-center mb-3">

          <h5 class="mb-0">History</h5>

          <small class="text-secondary">Recent and past requests, including approved reports</small>

        </div>



        <div class="table-responsive">

          <table class="table table-bordered table-hover">

            <thead>

              <tr>

                <th>#</th>

                <th>Requested At</th>

                <th>Report</th>

                <th>Scope</th>

                <th>Status</th>

                <th>Requested By</th>

                <th>Action Date</th>

                <th>Remarks</th>

                <th>Available Export</th>

              </tr>

            </thead>

            <tbody>

              <?php foreach ($historyRows as $i => $h): ?>

                <tr>

                  <td><?= (int)($i + 1) ?></td>

                  <td><?= htmlspecialchars($h['requested_at']) ?></td>

                  <td>
                    <div class="weight-600">
                      <?= htmlspecialchars($h['report_title'] ?: finance_report_type_label((string)($h['report_type'] ?? 'full_summary'))) ?>
                    </div>
                    <div class="small text-secondary">
                      <?= htmlspecialchars(finance_report_type_label((string)($h['report_type'] ?? 'full_summary'))) ?>
                    </div>
                  </td>

                  <td>
                    <?php
                      $historyFrom = trim((string)($h['date_from'] ?? ''));
                      $historyTo = trim((string)($h['date_to'] ?? ''));
                    ?>

                    <?php if (($h['report_type'] ?? 'full_summary') === 'specific_expense'): ?>
                      Expense #<?= (int)($h['target_id'] ?? 0) ?>
                    <?php elseif (($h['report_type'] ?? '') === 'project'): ?>
                      <div><?= htmlspecialchars($h['project_name'] ?? '-') ?></div>
                      <div class="small text-secondary">
                        <?= htmlspecialchars($historyFrom ?: '-') ?> to <?= htmlspecialchars($historyTo ?: '-') ?>
                      </div>
                    <?php elseif ($historyFrom !== '' && $historyTo !== ''): ?>
                      <?= htmlspecialchars($historyFrom) ?> to <?= htmlspecialchars($historyTo) ?>
                    <?php else: ?>
                      <?= htmlspecialchars($h['report_year'] . "-" . str_pad((string)$h['report_month'], 2, '0', STR_PAD_LEFT)) ?>
                    <?php endif; ?>
                  </td>

                  <td>

                    <?php

                      $hst = $h['status'] ?? 'pending';

                      $hBadge = $hst === 'approved' ? 'badge-success' : ($hst === 'rejected' ? 'badge-danger' : 'badge-warning');

                    ?>

                    <span class="badge <?= $hBadge ?>"><?= htmlspecialchars($hst) ?></span>

                  </td>

                  <td>

                    <?php if (!empty($h['requested_by_name'])): ?>

                      <?= htmlspecialchars($h['requested_by_name']) ?>

                      <div class="small text-secondary">

                        <?= htmlspecialchars($h['requested_by_position'] ?? '') ?>

                      </div>

                      <div class="small text-secondary">

                        <?= htmlspecialchars($h['requested_by_email'] ?? '') ?>

                      </div>

                    <?php else: ?>

                      <?= htmlspecialchars($h['requested_by_email'] ?? '-') ?>

                    <?php endif; ?>

                  </td>

                  <td>

                    <?= htmlspecialchars($h['president_action_at'] ?? '-') ?>



                    <?php if (!empty($h['president_approved_by_email'])): ?>

                      <div class="small text-secondary">

                        <?= htmlspecialchars($h['president_approved_by_email']) ?>

                      </div>

                    <?php endif; ?>

                  </td>



                  <td><?= htmlspecialchars($h['president_remarks'] ?? '') ?></td>

                  <td>

                    <?php if (

                      ($h['status'] ?? '') === 'approved' &&

                      !empty($h['snapshot_created_at'])

                    ): ?>

                      <?php

                        $hqs = "request_id=" . (int)$h['id'];

                      ?>

                      <a class="btn btn-sm btn-outline-success" target="_blank" rel="noopener noreferrer" href="finance_reports_export.php?format=pdf&<?= $hqs ?>">PDF</a>

                      <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer" href="finance_reports_export.php?format=excel&<?= $hqs ?>">Excel</a>

                    <?php elseif (($h['status'] ?? '') === 'approved'): ?>

                      <span class="badge badge-warning d-block mb-1">

                        Legacy / Not Frozen

                      </span>



                      <?php if ($canApproveReports): ?>

                        <form method="post" class="mt-1">

                          <input

                            type="hidden"

                            name="csrf"

                            value="<?= htmlspecialchars($csrfToken) ?>"

                          >

                          <input

                            type="hidden"

                            name="request_id"

                            value="<?= (int)$h['id'] ?>"

                          >

                          <button

                            class="btn btn-sm btn-outline-warning"

                            type="submit"

                            name="freeze_legacy_report"

                            value="1"

                          >

                            Freeze Current Data

                          </button>

                        </form>

                      <?php endif; ?>

                    <?php else: ?>

                      <span class="text-secondary">—</span>

                    <?php endif; ?>

                  </td>

                </tr>

              <?php endforeach; ?>



              <?php if (!$historyRows): ?>

                <tr>

                  <td colspan="9" class="text-center text-secondary">

                    No history found yet.

                  </td>

                </tr>

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



  <!-- js -->

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
document.addEventListener('DOMContentLoaded', function () {

  const reportType = document.getElementById('reportType');

  if (!reportType) {
    return;
  }

  const monthlyScope = document.getElementById('monthlyScope');
  const dateRangeScope = document.getElementById('dateRangeScope');
  const specificExpenseScope = document.getElementById('specificExpenseScope');
  const projectScope = document.getElementById('projectScope');

  const yearInput = document.getElementById('reportYear');
  const monthInput = document.getElementById('reportMonth');
  const dateFrom = document.getElementById('reportDateFrom');
  const dateTo = document.getElementById('reportDateTo');
  const targetId = document.getElementById('reportTargetId');
  const projectName = document.getElementById('reportProjectName');

  function setRequired(element, required) {
    if (element) {
      element.required = required;
    }
  }

  function syncReportScope() {
    const type = reportType.value;

    const isMonthly =
      type === 'full_summary' ||
      type === 'monthly_dues';

    const isDateRange =
      type === 'expenses' ||
      type === 'project' ||
      type === 'donations' ||
      type === 'cash_flow';

    const isSpecificExpense =
      type === 'specific_expense';

    monthlyScope.style.display = isMonthly ? '' : 'none';
    dateRangeScope.style.display = isDateRange ? '' : 'none';
    specificExpenseScope.style.display = isSpecificExpense ? '' : 'none';
    projectScope.style.display = type === 'project' ? '' : 'none';

    setRequired(yearInput, isMonthly);
    setRequired(monthInput, isMonthly);
    setRequired(dateFrom, isDateRange);
    setRequired(dateTo, isDateRange);
    setRequired(targetId, isSpecificExpense);
    setRequired(projectName, type === 'project');
  }

  reportType.addEventListener('change', syncReportScope);
  syncReportScope();
});
</script>

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

</html

  .report-scope-panel {
    border: 1px solid rgba(108,117,125,.22);
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 16px;
  }

  html.dark .report-scope-panel {
    border-color: var(--admin-border) !important;
    background: rgba(15,23,42,.18);
  }

>