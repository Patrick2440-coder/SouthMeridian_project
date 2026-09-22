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


function buildFinanceReportSnapshot(
  mysqli $conn,
  string $phase,
  int $year,
  int $month,
  ?int $requestedByAdminId,
  string $approvedByEmail,
  string $approvalRemarks,
  string $approvedAt
): array {
  $periodStart =
    sprintf(
      '%04d-%02d-01',
      $year,
      $month
    );

  $periodEnd =
    date(
      'Y-m-t',
      strtotime($periodStart)
    );

  $stmt = $conn->prepare("
    SELECT monthly_dues
    FROM finance_dues_settings
    WHERE phase = ?
    LIMIT 1
  ");
  $stmt->bind_param("s", $phase);
  $stmt->execute();
  $monthlyDues = (float)(
    $stmt->get_result()->fetch_assoc()['monthly_dues']
    ?? 0
  );
  $stmt->close();

  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_dues
    FROM finance_payments
    WHERE phase = ?
      AND status = 'paid'
      AND pay_year = ?
      AND pay_month = ?
  ");
  $stmt->bind_param(
    "sii",
    $phase,
    $year,
    $month
  );
  $stmt->execute();
  $totalDues = (float)(
    $stmt->get_result()->fetch_assoc()['total_dues']
    ?? 0
  );
  $stmt->close();

  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_donations
    FROM finance_donations
    WHERE phase = ?
      AND donation_date BETWEEN ? AND ?
  ");
  $stmt->bind_param(
    "sss",
    $phase,
    $periodStart,
    $periodEnd
  );
  $stmt->execute();
  $totalDonations = (float)(
    $stmt->get_result()->fetch_assoc()['total_donations']
    ?? 0
  );
  $stmt->close();

  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_expenses
    FROM finance_expenses
    WHERE phase = ?
      AND expense_date BETWEEN ? AND ?
  ");
  $stmt->bind_param(
    "sss",
    $phase,
    $periodStart,
    $periodEnd
  );
  $stmt->execute();
  $totalExpenses = (float)(
    $stmt->get_result()->fetch_assoc()['total_expenses']
    ?? 0
  );
  $stmt->close();

  $stmt = $conn->prepare("
    SELECT COUNT(*) AS eligible_count
    FROM homeowners
    WHERE phase = ?
      AND status = 'approved'
      AND DATE(created_at) <= ?
  ");
  $stmt->bind_param(
    "ss",
    $phase,
    $periodEnd
  );
  $stmt->execute();
  $eligibleCount = (int)(
    $stmt->get_result()->fetch_assoc()['eligible_count']
    ?? 0
  );
  $stmt->close();

  $stmt = $conn->prepare("
    SELECT COUNT(DISTINCT p.homeowner_id) AS paid_count
    FROM finance_payments p
    JOIN homeowners h
      ON h.id = p.homeowner_id
     AND h.phase = p.phase
    WHERE p.phase = ?
      AND p.pay_year = ?
      AND p.pay_month = ?
      AND p.status = 'paid'
      AND h.status = 'approved'
      AND DATE(h.created_at) <= ?
  ");
  $stmt->bind_param(
    "siis",
    $phase,
    $year,
    $month,
    $periodEnd
  );
  $stmt->execute();
  $paidCount = (int)(
    $stmt->get_result()->fetch_assoc()['paid_count']
    ?? 0
  );
  $stmt->close();

  $unpaidCount =
    max(
      0,
      $eligibleCount -
      $paidCount
    );

  $expectedDues =
    $eligibleCount *
    $monthlyDues;

  $pendingDues =
    $unpaidCount *
    $monthlyDues;

  $totalIncome =
    $totalDues +
    $totalDonations;

  $net =
    $totalIncome -
    $totalExpenses;

  $stmt = $conn->prepare("
    SELECT
      category,
      COALESCE(SUM(amount), 0) AS total
    FROM finance_expenses
    WHERE phase = ?
      AND expense_date BETWEEN ? AND ?
    GROUP BY category
    ORDER BY total DESC
  ");
  $stmt->bind_param(
    "sss",
    $phase,
    $periodStart,
    $periodEnd
  );
  $stmt->execute();
  $expenseBreakdown =
    $stmt
      ->get_result()
      ->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $stmt = $conn->prepare("
    SELECT
      expense_date,
      category,
      description,
      amount
    FROM finance_expenses
    WHERE phase = ?
      AND expense_date BETWEEN ? AND ?
    ORDER BY
      amount DESC,
      expense_date DESC,
      id DESC
    LIMIT 20
  ");
  $stmt->bind_param(
    "sss",
    $phase,
    $periodStart,
    $periodEnd
  );
  $stmt->execute();
  $topExpenses =
    $stmt
      ->get_result()
      ->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $requester = [
    'id' => $requestedByAdminId,
    'name' => '',
    'email' => '',
    'position' => ''
  ];

  if ($requestedByAdminId) {
    $stmt = $conn->prepare("
      SELECT
        full_name,
        email,
        position
      FROM admins
      WHERE id = ?
      LIMIT 1
    ");
    $stmt->bind_param(
      "i",
      $requestedByAdminId
    );
    $stmt->execute();
    $requesterRow =
      $stmt
        ->get_result()
        ->fetch_assoc();
    $stmt->close();

    if ($requesterRow) {
      $requester = [
        'id' => $requestedByAdminId,
        'name' =>
          (string)(
            $requesterRow['full_name']
            ?? ''
          ),
        'email' =>
          (string)(
            $requesterRow['email']
            ?? ''
          ),
        'position' =>
          (string)(
            $requesterRow['position']
            ?? ''
          )
      ];
    }
  }

  return [
    'version' => 1,
    'phase' => $phase,
    'report_year' => $year,
    'report_month' => $month,
    'period_start' => $periodStart,
    'period_end' => $periodEnd,
    'period_label' =>
      date(
        'F Y',
        strtotime($periodStart)
      ),
    'requester' => $requester,
    'approval' => [
      'approved_by_email' =>
        $approvedByEmail,
      'approved_at' =>
        $approvedAt,
      'remarks' =>
        $approvalRemarks
    ],
    'summary' => [
      'monthly_dues' => $monthlyDues,
      'eligible_homeowners' =>
        $eligibleCount,
      'paid_homeowners' =>
        $paidCount,
      'unpaid_homeowners' =>
        $unpaidCount,
      'expected_dues' =>
        $expectedDues,
      'pending_dues' =>
        $pendingDues,
      'dues_collected' =>
        $totalDues,
      'donations' =>
        $totalDonations,
      'total_income' =>
        $totalIncome,
      'expenses' =>
        $totalExpenses,
      'net' =>
        $net
    ],
    'expense_breakdown' =>
      $expenseBreakdown,
    'top_expenses' =>
      $topExpenses,
    'snapshot_created_at' =>
      $approvedAt
  ];
}

function saveFinanceReportSnapshot(
  mysqli $conn,
  int $requestId,
  string $phase,
  int $year,
  int $month,
  array $snapshot,
  string $approvedByEmail,
  string $approvedAt
): void {
  $snapshotJson =
    json_encode(
      $snapshot,
      JSON_UNESCAPED_UNICODE |
      JSON_UNESCAPED_SLASHES
    );

  if ($snapshotJson === false) {
    throw new RuntimeException(
      'Unable to encode financial report snapshot.'
    );
  }

  $stmt = $conn->prepare("
    INSERT INTO finance_report_snapshots
    (
      report_request_id,
      phase,
      report_year,
      report_month,
      snapshot_json,
      approved_by_email,
      approved_at
    )
    VALUES (?,?,?,?,?,?,?)
  ");

  $stmt->bind_param(
    "isiisss",
    $requestId,
    $phase,
    $year,
    $month,
    $snapshotJson,
    $approvedByEmail,
    $approvedAt
  );

  $stmt->execute();
  $stmt->close();
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
   REQUEST REPORT
   ========================= */
if (isset($_POST['request_report'])) {
  if (!$canRequestReports) {
    reportFlash(
      'danger',
      'Only the Treasurer can submit a monthly financial report for President approval.'
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

  $year =
    (int)(
      $_POST['report_year']
      ?? $currentYear
    );

  $month =
    (int)(
      $_POST['report_month']
      ?? $currentMonth
    );

  if (
    $year < 2000 ||
    $year > $currentYear ||
    $month < 1 ||
    $month > 12
  ) {
    reportFlash(
      'danger',
      'Please select a valid report period.'
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

  $periodKey =
    ($year * 100) +
    $month;

  if ($periodKey > $currentPeriodKey) {
    reportFlash(
      'danger',
      'A report cannot be requested for a future month.'
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

  try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("
      SELECT
        id,
        status
      FROM finance_report_requests
      WHERE phase = ?
        AND report_year = ?
        AND report_month = ?
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->bind_param(
      "sii",
      $phase,
      $year,
      $month
    );
    $stmt->execute();
    $existing =
      $stmt
        ->get_result()
        ->fetch_assoc();
    $stmt->close();

    if ($existing) {
      $existingStatus =
        (string)(
          $existing['status']
          ?? 'pending'
        );

      if ($existingStatus === 'approved') {
        $conn->rollback();

        reportFlash(
          'warning',
          'This monthly report is already approved and cannot be submitted again.'
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

      if ($existingStatus === 'pending') {
        $conn->rollback();

        reportFlash(
          'warning',
          'A pending report request already exists for that month.'
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

      /*
      |--------------------------------------------------------------------------
      | Re-submit a rejected report
      |--------------------------------------------------------------------------
      | The same unique monthly request is reused and returned to pending.
      | Previous President action data is cleared for the new review cycle.
      */
      $requestId =
        (int)$existing['id'];

      $stmt = $conn->prepare("
        UPDATE finance_report_requests
        SET
          status = 'pending',
          requested_by_admin_id = ?,
          requested_at = CURRENT_TIMESTAMP,
          president_approved_by_email = NULL,
          president_action_at = NULL,
          president_remarks = NULL
        WHERE id = ?
          AND phase = ?
          AND status = 'rejected'
      ");
      $stmt->bind_param(
        "iis",
        $adminId,
        $requestId,
        $phase
      );
      $stmt->execute();
      $stmt->close();

      $stmt = $conn->prepare("
        DELETE FROM finance_report_snapshots
        WHERE report_request_id = ?
      ");
      $stmt->bind_param(
        "i",
        $requestId
      );
      $stmt->execute();
      $stmt->close();

    } else {
      $stmt = $conn->prepare("
        INSERT INTO finance_report_requests
        (
          phase,
          report_year,
          report_month,
          status,
          requested_by_admin_id
        )
        VALUES (
          ?,?,?,
          'pending',
          ?
        )
      ");
      $stmt->bind_param(
        "siii",
        $phase,
        $year,
        $month,
        $adminId
      );
      $stmt->execute();
      $stmt->close();
    }

    $conn->commit();

    reportFlash(
      'success',
      'Financial report request sent to the President for review.'
    );

  } catch (Throwable $e) {
    try {
      $conn->rollback();
    } catch (Throwable $ignored) {
    }

    error_log(
      'Finance report request failed: ' .
      $e->getMessage()
    );

    reportFlash(
      'danger',
      'The report request could not be submitted. Please try again.'
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
        r.report_year,
        r.report_month,
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

    $snapshot =
      buildFinanceReportSnapshot(
        $conn,
        $phase,
        (int)$legacyRow['report_year'],
        (int)$legacyRow['report_month'],
        isset($legacyRow['requested_by_admin_id'])
          ? (int)$legacyRow['requested_by_admin_id']
          : null,
        $legacyApprovedBy,
        (string)(
          $legacyRow['president_remarks']
          ?? ''
        ),
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

    saveFinanceReportSnapshot(
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
        report_year,
        report_month,
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
      $snapshot =
        buildFinanceReportSnapshot(
          $conn,
          $phase,
          (int)$requestRow['report_year'],
          (int)$requestRow['report_month'],
          isset($requestRow['requested_by_admin_id'])
            ? (int)$requestRow['requested_by_admin_id']
            : null,
          $adminEmail,
          $remarks,
          $approvalAt
        );

      saveFinanceReportSnapshot(
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
  $filterMonth === 0
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

      <div class="card-box mb-20 p-3">
        <h5 class="mb-3">Request Monthly Report</h5>
        <?php if ($canRequestReports): ?>
          <form method="post" class="form-inline">
            <input
              type="hidden"
              name="csrf"
              value="<?= htmlspecialchars($csrfToken) ?>"
            >

            <label class="mr-2">Year</label>

            <input
              class="form-control mr-3"
              type="number"
              name="report_year"
              min="2000"
              max="<?= (int)$currentYear ?>"
              value="<?= (int)$defYear ?>"
              required
            >

            <label class="mr-2">Month</label>

            <select
              class="form-control mr-3"
              name="report_month"
              required
            >
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <option
                  value="<?= $m ?>"
                  <?= $m === $defMonth ? 'selected' : '' ?>
                  <?= ($defYear === $currentYear && $m > $currentMonth) ? 'disabled' : '' ?>
                >
                  <?= htmlspecialchars(date('F', mktime(0, 0, 0, $m, 1))) ?>
                </option>
              <?php endfor; ?>
            </select>

            <button
              class="btn btn-primary"
              name="request_report"
              value="1"
            >
              Send to President
            </button>
          </form>

          <small class="text-secondary d-block mt-2">
            Future periods cannot be requested. Rejected reports may be re-submitted after corrections.
            Approved or already-pending periods cannot be submitted again.
          </small>
        <?php else: ?>
          <div class="alert alert-light border mb-0">
            <?php if ($canApproveReports): ?>
              The Treasurer submits monthly reports. You can review pending requests below.
            <?php else: ?>
              Monthly report submission is available to the Treasurer only.
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <small class="text-secondary d-block mt-2">
          Once approved, the report can be exported to PDF or Excel.
        </small>
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
            <label>Year</label>
            <input type="number" name="filter_year" class="form-control" value="<?= $filterYear > 0 ? (int)$filterYear : '' ?>" placeholder="All Years">
          </div>

          <div class="col-md-3 mb-2">
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

          <div class="col-md-3 mb-2 d-flex align-items-end">
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
                <th>Period</th>
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
                  <td><?= htmlspecialchars($r['report_year'] . "-" . str_pad((string)$r['report_month'], 2, '0', STR_PAD_LEFT)) ?></td>

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
                        $qs = "phase=" . urlencode($phase)
                            . "&year=" . (int)$r['report_year']
                            . "&month=" . (int)$r['report_month'];
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
                  <td colspan="8" class="text-center text-secondary">
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
                <th>Period</th>
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
                  <td><?= htmlspecialchars($h['report_year'] . "-" . str_pad((string)$h['report_month'], 2, '0', STR_PAD_LEFT)) ?></td>
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
                        $hqs = "phase=" . urlencode($phase)
                             . "&year=" . (int)$h['report_year']
                             . "&month=" . (int)$h['report_month'];
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
                  <td colspan="8" class="text-center text-secondary">
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