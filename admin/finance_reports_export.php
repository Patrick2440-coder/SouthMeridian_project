<?php
require_once __DIR__ . "/finance_helpers.php";
require_once 'admin_access.php';

requireAccess('finance');
require_admin();

$conn = db_conn();

date_default_timezone_set('Asia/Manila');

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);

function exportFail(int $status, string $message): void {
  http_response_code($status);
  header('Content-Type: text/plain; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo $message;
  exit;
}

function safeCsvCell($value): string {
  $value = (string)$value;

  if (
    $value !== '' &&
    preg_match('/^[=\+\-@]/', $value)
  ) {
    $value = "'" . $value;
  }

  return $value;
}

/* =========================
   VALIDATE INPUT
   ========================= */
$format =
  strtolower(
    trim(
      (string)(
        $_GET['format']
        ?? 'pdf'
      )
    )
  );

if (!in_array($format, ['pdf', 'excel'], true)) {
  exportFail(400, 'Invalid export format.');
}

$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

$year =
  (int)(
    $_GET['year']
    ?? $currentYear
  );

$month =
  (int)(
    $_GET['month']
    ?? $currentMonth
  );

if (
  $year < 2000 ||
  $year > $currentYear ||
  $month < 1 ||
  $month > 12
) {
  exportFail(400, 'Invalid report period.');
}

if (
  (($year * 100) + $month) >
  (($currentYear * 100) + $currentMonth)
) {
  exportFail(
    400,
    'Future financial reports cannot be exported.'
  );
}

/* =========================
   LOAD APPROVED SNAPSHOT
   ========================= */
$stmt = $conn->prepare("
  SELECT
    r.id AS request_id,
    r.status,
    s.snapshot_json,
    s.created_at AS snapshot_created_at
  FROM finance_report_requests r
  LEFT JOIN finance_report_snapshots s
    ON s.report_request_id = r.id
  WHERE r.phase = ?
    AND r.report_year = ?
    AND r.report_month = ?
  LIMIT 1
");
$stmt->bind_param(
  "sii",
  $phase,
  $year,
  $month
);
$stmt->execute();
$row =
  $stmt
    ->get_result()
    ->fetch_assoc();
$stmt->close();

if (
  !$row ||
  (string)($row['status'] ?? '') !== 'approved'
) {
  exportFail(
    403,
    'Report not approved. Only approved reports can be exported.'
  );
}

if (
  empty($row['snapshot_json'])
) {
  exportFail(
    409,
    'This is a legacy approved report without a frozen snapshot. Approve reports with the snapshot-enabled workflow before exporting.'
  );
}

$snapshot =
  json_decode(
    (string)$row['snapshot_json'],
    true
  );

if (
  !is_array($snapshot) ||
  !isset($snapshot['summary']) ||
  !is_array($snapshot['summary'])
) {
  exportFail(
    500,
    'The saved financial report snapshot is invalid.'
  );
}

/* =========================
   SNAPSHOT VALUES
   ========================= */
$periodLabel =
  (string)(
    $snapshot['period_label']
    ?? sprintf(
      '%04d-%02d',
      $year,
      $month
    )
  );

$periodCode =
  sprintf(
    '%04d-%02d',
    $year,
    $month
  );

$requester =
  is_array(
    $snapshot['requester']
    ?? null
  )
    ? $snapshot['requester']
    : [];

$approval =
  is_array(
    $snapshot['approval']
    ?? null
  )
    ? $snapshot['approval']
    : [];

$summary =
  $snapshot['summary'];

$expenseBreakdown =
  is_array(
    $snapshot['expense_breakdown']
    ?? null
  )
    ? $snapshot['expense_breakdown']
    : [];

$topExpenses =
  is_array(
    $snapshot['top_expenses']
    ?? null
  )
    ? $snapshot['top_expenses']
    : [];

$requestedBy =
  trim(
    (string)(
      $requester['name']
      ?? ''
    )
  );

if ($requestedBy === '') {
  $requestedBy =
    trim(
      (string)(
        $requester['email']
        ?? ''
      )
    );
}

$approvedByEmail =
  trim(
    (string)(
      $approval['approved_by_email']
      ?? ''
    )
  );

$approvedAt =
  trim(
    (string)(
      $approval['approved_at']
      ?? ''
    )
  );

$approvalRemarks =
  trim(
    (string)(
      $approval['remarks']
      ?? ''
    )
  );

$snapshotCreatedAt =
  trim(
    (string)(
      $snapshot['snapshot_created_at']
      ?? (
        $row['snapshot_created_at']
        ?? ''
      )
    )
  );

$monthlyDues =
  (float)(
    $summary['monthly_dues']
    ?? 0
  );

$eligibleCount =
  (int)(
    $summary['eligible_homeowners']
    ?? 0
  );

$paidCount =
  (int)(
    $summary['paid_homeowners']
    ?? 0
  );

$unpaidCount =
  (int)(
    $summary['unpaid_homeowners']
    ?? 0
  );

$expectedDues =
  (float)(
    $summary['expected_dues']
    ?? 0
  );

$pendingDues =
  (float)(
    $summary['pending_dues']
    ?? 0
  );

$totalDues =
  (float)(
    $summary['dues_collected']
    ?? 0
  );

$totalDonations =
  (float)(
    $summary['donations']
    ?? 0
  );

$totalIncome =
  (float)(
    $summary['total_income']
    ?? 0
  );

$totalExpenses =
  (float)(
    $summary['expenses']
    ?? 0
  );

$net =
  (float)(
    $summary['net']
    ?? 0
  );

/* =========================
   EXCEL / CSV
   ========================= */
if ($format === 'excel') {
  $safePhaseForFile =
    preg_replace(
      '/[^A-Za-z0-9_-]+/',
      '_',
      $phase
    );

  $filename =
    'financial_report_' .
    $safePhaseForFile .
    '_' .
    $periodCode .
    '.csv';

  header(
    'Content-Type: text/csv; charset=utf-8'
  );

  header(
    'Content-Disposition: attachment; filename="' .
    $filename .
    '"'
  );

  header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
  );

  echo "\xEF\xBB\xBF";

  $out =
    fopen(
      'php://output',
      'w'
    );

  fputcsv($out, ['Financial Report']);
  fputcsv($out, ['Phase', safeCsvCell($phase)]);
  fputcsv($out, ['Period', safeCsvCell($periodLabel)]);
  fputcsv($out, ['Status', 'APPROVED / FROZEN']);
  fputcsv($out, ['Requested By', safeCsvCell($requestedBy)]);
  fputcsv($out, ['Approved By', safeCsvCell($approvedByEmail)]);
  fputcsv($out, ['Approved At', safeCsvCell($approvedAt)]);
  fputcsv($out, ['Snapshot Created At', safeCsvCell($snapshotCreatedAt)]);
  fputcsv($out, ['President Remarks', safeCsvCell($approvalRemarks)]);

  fputcsv($out, []);
  fputcsv($out, ['Summary']);
  fputcsv($out, ['Monthly Dues Setting', number_format($monthlyDues, 2, '.', '')]);
  fputcsv($out, ['Eligible Homeowners', $eligibleCount]);
  fputcsv($out, ['Paid Homeowners', $paidCount]);
  fputcsv($out, ['Unpaid Homeowners', $unpaidCount]);
  fputcsv($out, ['Expected Monthly Dues', number_format($expectedDues, 2, '.', '')]);
  fputcsv($out, ['Pending Dues', number_format($pendingDues, 2, '.', '')]);
  fputcsv($out, ['Dues Collected', number_format($totalDues, 2, '.', '')]);
  fputcsv($out, ['Donations', number_format($totalDonations, 2, '.', '')]);
  fputcsv($out, ['Total Income', number_format($totalIncome, 2, '.', '')]);
  fputcsv($out, ['Expenses', number_format($totalExpenses, 2, '.', '')]);
  fputcsv($out, ['Net (Income - Expenses)', number_format($net, 2, '.', '')]);

  fputcsv($out, []);
  fputcsv($out, ['Expenses Breakdown']);
  fputcsv($out, ['Category', 'Total']);

  foreach ($expenseBreakdown as $item) {
    fputcsv(
      $out,
      [
        safeCsvCell(
          ucfirst(
            (string)(
              $item['category']
              ?? ''
            )
          )
        ),
        number_format(
          (float)(
            $item['total']
            ?? 0
          ),
          2,
          '.',
          ''
        )
      ]
    );
  }

  fputcsv($out, []);
  fputcsv($out, ['Top Expenses (up to 20)']);
  fputcsv($out, ['Date', 'Category', 'Description', 'Amount']);

  foreach ($topExpenses as $expense) {
    fputcsv(
      $out,
      [
        safeCsvCell($expense['expense_date'] ?? ''),
        safeCsvCell(ucfirst((string)($expense['category'] ?? ''))),
        safeCsvCell($expense['description'] ?? ''),
        number_format(
          (float)(
            $expense['amount']
            ?? 0
          ),
          2,
          '.',
          ''
        )
      ]
    );
  }

  fclose($out);
  exit;
}

/* =========================
   PDF / PRINTABLE HTML
   ========================= */
$approvalBlock = '
  <h3>Approval Details</h3>
  <table>
    <tr>
      <th>Approved By</th>
      <td>' . esc($approvedByEmail !== '' ? $approvedByEmail : '-') . '</td>
    </tr>
    <tr>
      <th>Approved At</th>
      <td>' . esc($approvedAt !== '' ? $approvedAt : '-') . '</td>
    </tr>
    <tr>
      <th>Snapshot Created At</th>
      <td>' . esc($snapshotCreatedAt !== '' ? $snapshotCreatedAt : '-') . '</td>
    </tr>
    <tr>
      <th>President Remarks</th>
      <td>' . esc($approvalRemarks !== '' ? $approvalRemarks : '-') . '</td>
    </tr>
  </table>';

$html = '
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Financial Report</title>
<style>
  body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 12px;
    color: #222;
  }
  h2, h3 {
    margin: 0 0 8px 0;
  }
  h3 {
    margin-top: 16px;
  }
  .meta {
    margin-bottom: 12px;
    line-height: 1.6;
  }
  table {
    border-collapse: collapse;
    width: 100%;
    margin: 10px 0;
  }
  th, td {
    border: 1px solid #ccc;
    padding: 6px;
    text-align: left;
    vertical-align: top;
  }
  th {
    background: #f3f3f3;
  }
  .right {
    text-align: right;
  }
  .badge {
    display: inline-block;
    padding: 2px 6px;
    border: 1px solid #777;
    border-radius: 4px;
    font-weight: bold;
  }
  .muted {
    color: #666;
    font-size: 11px;
  }
</style>
</head>
<body>
  <h2>South Meridian Homes HOA</h2>
  <h3>Financial Report</h3>

  <div class="meta">
    <div><b>Phase:</b> ' . esc($phase) . '</div>
    <div><b>Period:</b> ' . esc($periodLabel) . '</div>
    <div><b>Status:</b> <span class="badge">APPROVED / FROZEN</span></div>
    <div><b>Requested By:</b> ' . esc($requestedBy !== '' ? $requestedBy : '-') . '</div>
  </div>

  <h3>Summary</h3>

  <table>
    <tr><th>Monthly Dues Setting</th><td class="right">₱ ' . number_format($monthlyDues, 2) . '</td></tr>
    <tr><th>Eligible Homeowners</th><td class="right">' . $eligibleCount . '</td></tr>
    <tr><th>Paid Homeowners</th><td class="right">' . $paidCount . '</td></tr>
    <tr><th>Unpaid Homeowners</th><td class="right">' . $unpaidCount . '</td></tr>
    <tr><th>Expected Monthly Dues</th><td class="right">₱ ' . number_format($expectedDues, 2) . '</td></tr>
    <tr><th>Pending Dues</th><td class="right">₱ ' . number_format($pendingDues, 2) . '</td></tr>
    <tr><th>Dues Collected</th><td class="right">₱ ' . number_format($totalDues, 2) . '</td></tr>
    <tr><th>Donations</th><td class="right">₱ ' . number_format($totalDonations, 2) . '</td></tr>
    <tr><th>Total Income</th><td class="right">₱ ' . number_format($totalIncome, 2) . '</td></tr>
    <tr><th>Expenses</th><td class="right">₱ ' . number_format($totalExpenses, 2) . '</td></tr>
    <tr><th><b>Net (Income - Expenses)</b></th><td class="right"><b>₱ ' . number_format($net, 2) . '</b></td></tr>
  </table>

  <h3>Expenses Breakdown</h3>

  <table>
    <tr>
      <th>Category</th>
      <th class="right">Total</th>
    </tr>';

foreach ($expenseBreakdown as $item) {
  $html .= '
    <tr>
      <td>' . esc(ucfirst((string)($item['category'] ?? ''))) . '</td>
      <td class="right">₱ ' . number_format((float)($item['total'] ?? 0), 2) . '</td>
    </tr>';
}

if (!$expenseBreakdown) {
  $html .= '
    <tr>
      <td colspan="2">No expenses recorded.</td>
    </tr>';
}

$html .= '
  </table>

  <h3>Top Expenses (up to 20)</h3>

  <table>
    <tr>
      <th>Date</th>
      <th>Category</th>
      <th>Description</th>
      <th class="right">Amount</th>
    </tr>';

foreach ($topExpenses as $expense) {
  $html .= '
    <tr>
      <td>' . esc($expense['expense_date'] ?? '') . '</td>
      <td>' . esc(ucfirst((string)($expense['category'] ?? ''))) . '</td>
      <td>' . esc($expense['description'] ?? '') . '</td>
      <td class="right">₱ ' . number_format((float)($expense['amount'] ?? 0), 2) . '</td>
    </tr>';
}

if (!$topExpenses) {
  $html .= '
    <tr>
      <td colspan="4">No expenses recorded.</td>
    </tr>';
}

$html .= '
  </table>

  ' . $approvalBlock . '

  <div class="muted" style="margin-top:18px;">
    Financial figures were frozen when this report was approved.
    Re-exporting does not recalculate from live Finance data.
  </div>

  <div class="muted" style="margin-top:6px;">
    Exported on: ' . esc(date('Y-m-d H:i:s')) . '
  </div>
</body>
</html>';

$dompdfAutoload =
  __DIR__ .
  '/vendor/autoload.php';

if (file_exists($dompdfAutoload)) {
  require_once $dompdfAutoload;

  if (class_exists('\\Dompdf\\Dompdf')) {
    $dompdf =
      new \Dompdf\Dompdf();

    $dompdf->loadHtml(
      $html,
      'UTF-8'
    );

    $dompdf->setPaper(
      'A4',
      'portrait'
    );

    $dompdf->render();

    $safePhaseForFile =
      preg_replace(
        '/[^A-Za-z0-9_-]+/',
        '_',
        $phase
      );

    $dompdf->stream(
      'financial_report_' .
      $safePhaseForFile .
      '_' .
      $periodCode .
      '.pdf',
      [
        'Attachment' => true
      ]
    );

    exit;
  }
}

header(
  'Content-Type: text/html; charset=utf-8'
);

header(
  'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);

echo $html;
