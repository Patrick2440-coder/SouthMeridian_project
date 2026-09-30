<?php

require_once __DIR__ . "/finance_helpers.php";
require_once 'admin_access.php';

requireAccess('finance');
require_admin();

$conn = db_conn();
date_default_timezone_set('Asia/Manila');

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);

function exportFail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo $message;
    exit;
}

function safeCsvCell($value): string
{
    $value = (string)$value;

    if ($value !== '' && preg_match('/^[=\+\-@]/', $value)) {
        $value = "'" . $value;
    }

    return $value;
}

function reportMoney($value): string
{
    return number_format((float)$value, 2, '.', '');
}

function reportTypeLabelFromSnapshot(array $snapshot): string
{
    $label = trim((string)($snapshot['report_type_label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    $type = (string)($snapshot['report_type'] ?? 'full_summary');

    $labels = [
        'full_summary' => 'Complete Financial Summary',
        'monthly_dues' => 'Monthly Dues Report',
        'expenses' => 'Expenses Report',
        'specific_expense' => 'Specific Expense Report',
        'project' => 'Project / Purchase Report',
        'donations' => 'Donations Report',
        'cash_flow' => 'Cash Flow Report',
    ];

    return $labels[$type] ?? 'Financial Report';
}

$format = strtolower(trim((string)($_GET['format'] ?? 'pdf')));

if (!in_array($format, ['pdf', 'excel'], true)) {
    exportFail(400, 'Invalid export format.');
}

$requestId = (int)($_GET['request_id'] ?? 0);

if ($requestId <= 0) {
    exportFail(400, 'Missing financial report request ID.');
}

$stmt = $conn->prepare("
    SELECT
        r.id AS request_id,
        r.phase,
        r.report_type,
        r.report_title,
        r.status,
        r.report_year,
        r.report_month,
        r.date_from,
        r.date_to,
        r.target_type,
        r.target_id,
        r.project_name,
        r.request_purpose,
        s.snapshot_json,
        s.created_at AS snapshot_created_at
    FROM finance_report_requests r
    LEFT JOIN finance_report_snapshots s
      ON s.report_request_id = r.id
    WHERE r.id = ?
      AND r.phase = ?
    LIMIT 1
");
$stmt->bind_param('is', $requestId, $phase);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || (string)($row['status'] ?? '') !== 'approved') {
    exportFail(403, 'Report not approved. Only approved reports can be exported.');
}

if (empty($row['snapshot_json'])) {
    exportFail(409, 'This approved report does not have a frozen snapshot yet.');
}

$snapshot = json_decode((string)$row['snapshot_json'], true);

if (!is_array($snapshot)) {
    exportFail(500, 'The saved financial report snapshot is invalid.');
}

$version = (int)($snapshot['version'] ?? 1);
$reportType = (string)($snapshot['report_type'] ?? ($row['report_type'] ?? 'full_summary'));
$reportTypeLabel = reportTypeLabelFromSnapshot($snapshot);

$reportTitle = trim((string)($snapshot['report_title'] ?? ($row['report_title'] ?? '')));
if ($reportTitle === '') {
    $reportTitle = $reportTypeLabel;
}

$periodLabel = trim((string)($snapshot['period_label'] ?? ''));
if ($periodLabel === '') {
    $periodLabel = sprintf(
        '%04d-%02d',
        (int)($row['report_year'] ?? date('Y')),
        (int)($row['report_month'] ?? date('n'))
    );
}

$requester = is_array($snapshot['requester'] ?? null) ? $snapshot['requester'] : [];
$approval = is_array($snapshot['approval'] ?? null) ? $snapshot['approval'] : [];
$summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
$sections = is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [];

$requestedBy = trim((string)($requester['name'] ?? ''));
if ($requestedBy === '') {
    $requestedBy = trim((string)($requester['email'] ?? ''));
}

$approvedByEmail = trim((string)($approval['approved_by_email'] ?? ''));
$approvedAt = trim((string)($approval['approved_at'] ?? ''));
$approvalRemarks = trim((string)($approval['remarks'] ?? ''));
$requestPurpose = trim((string)($snapshot['request_purpose'] ?? ($row['request_purpose'] ?? '')));

$snapshotCreatedAt = trim(
    (string)(
        $snapshot['snapshot_created_at']
        ?? ($row['snapshot_created_at'] ?? '')
    )
);

$duesSection = is_array($sections['dues'] ?? null) ? $sections['dues'] : [];
$donationsSection = is_array($sections['donations'] ?? null) ? $sections['donations'] : [];
$expensesSection = is_array($sections['expenses'] ?? null) ? $sections['expenses'] : [];
$cashFlowSection = is_array($sections['cash_flow'] ?? null) ? $sections['cash_flow'] : [];

/* Legacy version-1 compatibility */
if ($version < 2) {
    $legacyExpenseBreakdown = is_array($snapshot['expense_breakdown'] ?? null)
        ? $snapshot['expense_breakdown']
        : [];

    $legacyTopExpenses = is_array($snapshot['top_expenses'] ?? null)
        ? $snapshot['top_expenses']
        : [];

    if (!$expensesSection && ($legacyExpenseBreakdown || $legacyTopExpenses)) {
        $expensesSection = [
            'summary' => [
                'total_expenses' => (float)($summary['expenses'] ?? 0),
                'expense_count' => count($legacyTopExpenses),
            ],
            'breakdown' => $legacyExpenseBreakdown,
            'records' => $legacyTopExpenses,
        ];
    }

    if (!$duesSection) {
        $duesSection = [
            'summary' => [
                'monthly_dues' => (float)($summary['monthly_dues'] ?? 0),
                'eligible_homeowners' => (int)($summary['eligible_homeowners'] ?? 0),
                'paid_homeowners' => (int)($summary['paid_homeowners'] ?? 0),
                'unpaid_homeowners' => (int)($summary['unpaid_homeowners'] ?? 0),
                'expected_dues' => (float)($summary['expected_dues'] ?? 0),
                'pending_dues' => (float)($summary['pending_dues'] ?? 0),
                'dues_collected' => (float)($summary['dues_collected'] ?? 0),
            ],
            'records' => [],
        ];
    }
}

$safePhaseForFile = preg_replace('/[^A-Za-z0-9_-]+/', '_', $phase);
$safeTypeForFile = preg_replace('/[^A-Za-z0-9_-]+/', '_', $reportType);
$fileBase = 'financial_report_'
    . $safePhaseForFile
    . '_'
    . $safeTypeForFile
    . '_request_'
    . $requestId;

if ($format === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileBase . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    fputcsv($out, [$reportTitle]);
    fputcsv($out, ['Report Type', safeCsvCell($reportTypeLabel)]);
    fputcsv($out, ['Phase', safeCsvCell($phase)]);
    fputcsv($out, ['Scope / Period', safeCsvCell($periodLabel)]);
    fputcsv($out, ['Status', 'APPROVED / FROZEN']);
    fputcsv($out, ['Requested By', safeCsvCell($requestedBy)]);
    fputcsv($out, ['Request Purpose', safeCsvCell($requestPurpose)]);
    fputcsv($out, ['Approved By', safeCsvCell($approvedByEmail)]);
    fputcsv($out, ['Approved At', safeCsvCell($approvedAt)]);
    fputcsv($out, ['Snapshot Created At', safeCsvCell($snapshotCreatedAt)]);
    fputcsv($out, ['President Remarks', safeCsvCell($approvalRemarks)]);

    if ($reportType === 'project') {
        fputcsv($out, [
            'Project / Activity',
            safeCsvCell($snapshot['project_name'] ?? $row['project_name'] ?? '')
        ]);
    }

    if ($reportType === 'specific_expense') {
        fputcsv($out, [
            'Target Expense ID',
            (int)($snapshot['target_id'] ?? $row['target_id'] ?? 0)
        ]);
    }

    if ($summary) {
        fputcsv($out, []);
        fputcsv($out, ['Summary']);

        $summaryLabels = [
            'monthly_dues' => 'Monthly Dues Setting',
            'eligible_homeowners' => 'Eligible Homeowners',
            'paid_homeowners' => 'Paid Homeowners',
            'unpaid_homeowners' => 'Unpaid Homeowners',
            'expected_dues' => 'Expected Monthly Dues',
            'pending_dues' => 'Pending Dues',
            'dues_collected' => 'Dues Collected',
            'donations' => 'Donations',
            'donation_count' => 'Donation Count',
            'expenses' => 'Expenses',
            'expense_count' => 'Expense Count',
            'total_income' => 'Total Income',
            'net' => 'Net',
        ];

        $moneyKeys = [
            'monthly_dues',
            'expected_dues',
            'pending_dues',
            'dues_collected',
            'donations',
            'expenses',
            'total_income',
            'net',
        ];

        foreach ($summaryLabels as $key => $label) {
            if (!array_key_exists($key, $summary)) {
                continue;
            }

            $value = $summary[$key];
            if (in_array($key, $moneyKeys, true)) {
                $value = reportMoney($value);
            }

            fputcsv($out, [$label, $value]);
        }
    }

    if ($duesSection) {
        fputcsv($out, []);
        fputcsv($out, ['Monthly Dues Payments']);
        fputcsv($out, [
            'Public ID',
            'Homeowner',
            'Block',
            'Lot',
            'Amount',
            'Paid At',
            'Reference No.',
            'Notes',
            'Homeowner Status',
        ]);

        foreach (($duesSection['records'] ?? []) as $record) {
            fputcsv($out, [
                safeCsvCell($record['public_id'] ?? ''),
                safeCsvCell($record['homeowner_name'] ?? ''),
                safeCsvCell($record['block'] ?? ''),
                safeCsvCell($record['lot'] ?? ''),
                reportMoney($record['amount'] ?? 0),
                safeCsvCell($record['paid_at'] ?? ''),
                safeCsvCell($record['reference_no'] ?? ''),
                safeCsvCell($record['notes'] ?? ''),
                safeCsvCell($record['homeowner_status'] ?? ''),
            ]);
        }
    }

    if ($donationsSection) {
        fputcsv($out, []);
        fputcsv($out, ['Donations']);
        fputcsv($out, ['Date', 'Donor', 'Email', 'Amount', 'Receipt No.', 'Message']);

        foreach (($donationsSection['records'] ?? []) as $record) {
            fputcsv($out, [
                safeCsvCell($record['donation_date'] ?? ''),
                safeCsvCell($record['donor_name'] ?? ''),
                safeCsvCell($record['donor_email'] ?? ''),
                reportMoney($record['amount'] ?? 0),
                safeCsvCell($record['receipt_no'] ?? ''),
                safeCsvCell($record['message'] ?? ''),
            ]);
        }
    }

    if ($expensesSection) {
        fputcsv($out, []);
        fputcsv($out, ['Expenses']);
        fputcsv($out, [
            'ID',
            'Date',
            'Category',
            'Project / Activity',
            'Purpose',
            'Supplier / Payee',
            'Requested By',
            'Payment Method',
            'Reference No.',
            'Amount',
            'Proof',
        ]);

        foreach (($expensesSection['records'] ?? []) as $record) {
            fputcsv($out, [
                (int)($record['id'] ?? 0),
                safeCsvCell($record['expense_date'] ?? ''),
                safeCsvCell($record['category'] ?? ''),
                safeCsvCell($record['project_name'] ?? ''),
                safeCsvCell($record['description'] ?? ''),
                safeCsvCell($record['vendor_payee'] ?? ''),
                safeCsvCell($record['requested_by'] ?? ''),
                safeCsvCell($record['payment_method'] ?? ''),
                safeCsvCell($record['reference_no'] ?? ''),
                reportMoney($record['amount'] ?? 0),
                !empty($record['receipt_path']) ? 'Attached' : 'No proof / legacy',
            ]);

            $items = is_array($record['items'] ?? null) ? $record['items'] : [];

            if ($items) {
                fputcsv($out, ['  Tools / Materials']);
                fputcsv($out, ['  Item', 'Quantity', 'Unit', 'Unit Cost', 'Line Total', 'Notes']);

                foreach ($items as $item) {
                    fputcsv($out, [
                        '  ' . safeCsvCell($item['item_name'] ?? ''),
                        $item['quantity'] ?? '',
                        safeCsvCell($item['unit'] ?? ''),
                        reportMoney($item['unit_cost'] ?? 0),
                        reportMoney($item['line_total'] ?? 0),
                        safeCsvCell($item['notes'] ?? ''),
                    ]);
                }
            }
        }
    }

    if ($cashFlowSection) {
        fputcsv($out, []);
        fputcsv($out, ['Cash Flow']);

        fputcsv($out, [
            'Opening Balance',
            !empty($cashFlowSection['opening_balance_known'])
                ? reportMoney($cashFlowSection['opening_balance'] ?? 0)
                : 'Unavailable for selected start date'
        ]);
        fputcsv($out, ['Dues Inflow', reportMoney($cashFlowSection['dues_inflow'] ?? 0)]);
        fputcsv($out, ['Donations Inflow', reportMoney($cashFlowSection['donations_inflow'] ?? 0)]);
        fputcsv($out, ['Total Inflow', reportMoney($cashFlowSection['total_inflow'] ?? 0)]);
        fputcsv($out, ['Expenses Outflow', reportMoney($cashFlowSection['expenses_outflow'] ?? 0)]);
        fputcsv($out, ['Net Movement', reportMoney($cashFlowSection['net_movement'] ?? 0)]);
        fputcsv($out, [
            'Closing Balance',
            array_key_exists('closing_balance', $cashFlowSection)
            && $cashFlowSection['closing_balance'] !== null
                ? reportMoney($cashFlowSection['closing_balance'])
                : 'Unavailable'
        ]);
        fputcsv($out, ['Opening Balance Basis', safeCsvCell($cashFlowSection['opening_basis'] ?? '')]);
    }

    fclose($out);
    exit;
}

function htmlMoney($value): string
{
    return '₱ ' . number_format((float)$value, 2);
}

$metaHtml = '
<div class="meta">
  <div><b>Report:</b> ' . esc($reportTitle) . '</div>
  <div><b>Report Type:</b> ' . esc($reportTypeLabel) . '</div>
  <div><b>Phase:</b> ' . esc($phase) . '</div>
  <div><b>Scope / Period:</b> ' . esc($periodLabel) . '</div>
  <div><b>Status:</b> <span class="badge">APPROVED / FROZEN</span></div>
  <div><b>Requested By:</b> ' . esc($requestedBy !== '' ? $requestedBy : '-') . '</div>
  <div><b>Request Purpose:</b> ' . esc($requestPurpose !== '' ? $requestPurpose : '-') . '</div>
</div>';

if ($reportType === 'project') {
    $metaHtml .= '
    <div class="meta"><b>Project / Activity:</b> '
        . esc((string)($snapshot['project_name'] ?? $row['project_name'] ?? '-'))
        . '</div>';
}

$approvalBlock = '
<h3>Approval Details</h3>
<table>
  <tr><th>Approved By</th><td>' . esc($approvedByEmail !== '' ? $approvedByEmail : '-') . '</td></tr>
  <tr><th>Approved At</th><td>' . esc($approvedAt !== '' ? $approvedAt : '-') . '</td></tr>
  <tr><th>Snapshot Created At</th><td>' . esc($snapshotCreatedAt !== '' ? $snapshotCreatedAt : '-') . '</td></tr>
  <tr><th>President Remarks</th><td>' . esc($approvalRemarks !== '' ? $approvalRemarks : '-') . '</td></tr>
</table>';

$html = '
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>' . esc($reportTitle) . '</title>
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; }
  h2, h3 { margin: 0 0 8px 0; }
  h3 { margin-top: 16px; }
  .meta { margin-bottom: 10px; line-height: 1.55; }
  table { border-collapse: collapse; width: 100%; margin: 9px 0; }
  th, td { border: 1px solid #ccc; padding: 5px; text-align: left; vertical-align: top; }
  th { background: #f3f3f3; }
  .right { text-align: right; }
  .badge { display: inline-block; padding: 2px 6px; border: 1px solid #777; border-radius: 4px; font-weight: bold; }
  .muted { color: #666; font-size: 10px; }
  .subtable { margin-left: 12px; width: calc(100% - 12px); }
  .proof { font-size: 10px; }
</style>
</head>
<body>
  <h2>South Meridian Homes HOA</h2>
  <h3>' . esc($reportTitle) . '</h3>
  ' . $metaHtml;

if ($summary) {
    $html .= '<h3>Summary</h3><table>';

    $summaryLabels = [
        'monthly_dues' => 'Monthly Dues Setting',
        'eligible_homeowners' => 'Eligible Homeowners',
        'paid_homeowners' => 'Paid Homeowners',
        'unpaid_homeowners' => 'Unpaid Homeowners',
        'expected_dues' => 'Expected Monthly Dues',
        'pending_dues' => 'Pending Dues',
        'dues_collected' => 'Dues Collected',
        'donations' => 'Donations',
        'donation_count' => 'Donation Count',
        'expenses' => 'Expenses',
        'expense_count' => 'Expense Count',
        'total_income' => 'Total Income',
        'net' => 'Net',
    ];

    $moneyKeys = [
        'monthly_dues',
        'expected_dues',
        'pending_dues',
        'dues_collected',
        'donations',
        'expenses',
        'total_income',
        'net',
    ];

    foreach ($summaryLabels as $key => $label) {
        if (!array_key_exists($key, $summary)) {
            continue;
        }

        $display = in_array($key, $moneyKeys, true)
            ? htmlMoney($summary[$key])
            : esc((string)$summary[$key]);

        $html .= '<tr><th>' . esc($label) . '</th><td class="right">' . $display . '</td></tr>';
    }

    $html .= '</table>';
}

if ($duesSection && !empty($duesSection['records'])) {
    $html .= '
    <h3>Monthly Dues Payments</h3>
    <table>
      <tr><th>Homeowner</th><th>Property</th><th>Amount</th><th>Paid At</th><th>Reference</th></tr>';

    foreach ($duesSection['records'] as $record) {
        $property = trim(
            'Block ' . (string)($record['block'] ?? '')
            . ' Lot ' . (string)($record['lot'] ?? '')
        );

        $html .= '
        <tr>
          <td>' . esc((string)($record['public_id'] ?? '')) . '<br>' . esc((string)($record['homeowner_name'] ?? '')) . '</td>
          <td>' . esc($property) . '</td>
          <td class="right">' . htmlMoney($record['amount'] ?? 0) . '</td>
          <td>' . esc((string)($record['paid_at'] ?? '')) . '</td>
          <td>' . esc((string)($record['reference_no'] ?? '')) . '</td>
        </tr>';
    }

    $html .= '</table>';
}

if ($donationsSection && !empty($donationsSection['records'])) {
    $html .= '
    <h3>Donations</h3>
    <table>
      <tr><th>Date</th><th>Donor</th><th>Receipt No.</th><th>Amount</th></tr>';

    foreach ($donationsSection['records'] as $record) {
        $html .= '
        <tr>
          <td>' . esc((string)($record['donation_date'] ?? '')) . '</td>
          <td>' . esc((string)($record['donor_name'] ?? '')) . '<br><span class="muted">' . esc((string)($record['donor_email'] ?? '')) . '</span></td>
          <td>' . esc((string)($record['receipt_no'] ?? '')) . '</td>
          <td class="right">' . htmlMoney($record['amount'] ?? 0) . '</td>
        </tr>';
    }

    $html .= '</table>';
}

if ($expensesSection) {
    $expenseRecords = is_array($expensesSection['records'] ?? null) ? $expensesSection['records'] : [];
    $breakdown = is_array($expensesSection['breakdown'] ?? null) ? $expensesSection['breakdown'] : [];

    if ($breakdown) {
        $html .= '<h3>Expense Breakdown</h3><table><tr><th>Category</th><th class="right">Total</th></tr>';

        foreach ($breakdown as $item) {
            $html .= '<tr><td>' . esc(ucfirst((string)($item['category'] ?? ''))) . '</td><td class="right">' . htmlMoney($item['total'] ?? 0) . '</td></tr>';
        }

        $html .= '</table>';
    }

    if ($expenseRecords) {
        $html .= '<h3>Expense Documentation</h3>';

        foreach ($expenseRecords as $expense) {
            $proof = !empty($expense['receipt_path'])
                ? '<a href="' . esc((string)$expense['receipt_path']) . '">View Attached Proof</a>'
                : 'Legacy / No proof attached';

            $html .= '
            <table>
              <tr><th style="width:25%">Expense ID</th><td>#' . (int)($expense['id'] ?? 0) . '</td></tr>
              <tr><th>Date</th><td>' . esc((string)($expense['expense_date'] ?? '')) . '</td></tr>
              <tr><th>Category</th><td>' . esc(ucfirst((string)($expense['category'] ?? ''))) . '</td></tr>
              <tr><th>Project / Activity</th><td>' . esc((string)($expense['project_name'] ?? '-')) . '</td></tr>
              <tr><th>Purpose</th><td>' . esc((string)($expense['description'] ?? '')) . '</td></tr>
              <tr><th>Supplier / Payee</th><td>' . esc((string)($expense['vendor_payee'] ?? '-')) . '</td></tr>
              <tr><th>Requested By</th><td>' . esc((string)($expense['requested_by'] ?? '-')) . '</td></tr>
              <tr><th>Payment Method</th><td>' . esc((string)($expense['payment_method'] ?? '-')) . '</td></tr>
              <tr><th>Reference No.</th><td>' . esc((string)($expense['reference_no'] ?? '-')) . '</td></tr>
              <tr><th>Amount</th><td class="right"><b>' . htmlMoney($expense['amount'] ?? 0) . '</b></td></tr>
              <tr><th>Recorded By</th><td>' . esc((string)($expense['recorded_by_name'] ?? 'Legacy / Unknown')) . ' ' . esc((string)($expense['recorded_by_position'] ?? '')) . '</td></tr>
              <tr><th>Proof</th><td class="proof">' . $proof . '</td></tr>
              <tr><th>Notes</th><td>' . esc((string)($expense['notes'] ?? '')) . '</td></tr>
            </table>';

            $items = is_array($expense['items'] ?? null) ? $expense['items'] : [];

            if ($items) {
                $html .= '
                <table class="subtable">
                  <tr>
                    <th>Material / Tool</th>
                    <th>Qty</th>
                    <th>Unit</th>
                    <th class="right">Unit Cost</th>
                    <th class="right">Line Total</th>
                    <th>Notes</th>
                  </tr>';

                foreach ($items as $item) {
                    $html .= '
                    <tr>
                      <td>' . esc((string)($item['item_name'] ?? '')) . '</td>
                      <td>' . esc((string)($item['quantity'] ?? '')) . '</td>
                      <td>' . esc((string)($item['unit'] ?? '')) . '</td>
                      <td class="right">' . htmlMoney($item['unit_cost'] ?? 0) . '</td>
                      <td class="right">' . htmlMoney($item['line_total'] ?? 0) . '</td>
                      <td>' . esc((string)($item['notes'] ?? '')) . '</td>
                    </tr>';
                }

                $html .= '</table>';
            }
        }
    }
}

if ($cashFlowSection) {
    $openingDisplay = !empty($cashFlowSection['opening_balance_known'])
        ? htmlMoney($cashFlowSection['opening_balance'] ?? 0)
        : 'Unavailable for selected start date';

    $closingDisplay = array_key_exists('closing_balance', $cashFlowSection)
        && $cashFlowSection['closing_balance'] !== null
        ? htmlMoney($cashFlowSection['closing_balance'])
        : 'Unavailable';

    $html .= '
    <h3>Cash Flow</h3>
    <table>
      <tr><th>Opening Balance</th><td class="right">' . $openingDisplay . '</td></tr>
      <tr><th>Dues Inflow</th><td class="right">' . htmlMoney($cashFlowSection['dues_inflow'] ?? 0) . '</td></tr>
      <tr><th>Donations Inflow</th><td class="right">' . htmlMoney($cashFlowSection['donations_inflow'] ?? 0) . '</td></tr>
      <tr><th>Total Inflow</th><td class="right">' . htmlMoney($cashFlowSection['total_inflow'] ?? 0) . '</td></tr>
      <tr><th>Expenses Outflow</th><td class="right">' . htmlMoney($cashFlowSection['expenses_outflow'] ?? 0) . '</td></tr>
      <tr><th>Net Movement</th><td class="right">' . htmlMoney($cashFlowSection['net_movement'] ?? 0) . '</td></tr>
      <tr><th>Closing Balance</th><td class="right">' . $closingDisplay . '</td></tr>
      <tr><th>Opening Balance Basis</th><td>' . esc((string)($cashFlowSection['opening_basis'] ?? '')) . '</td></tr>
    </table>';
}

$html .= '
  ' . $approvalBlock . '

  <div class="muted" style="margin-top:18px;">
    Financial data in this document was frozen when the report was approved.
    Re-exporting this request does not recalculate from live Finance data.
  </div>

  <div class="muted" style="margin-top:6px;">
    Exported on: ' . esc(date('Y-m-d H:i:s')) . '
  </div>
</body>
</html>';

$dompdfAutoload = __DIR__ . '/vendor/autoload.php';

if (file_exists($dompdfAutoload)) {
    require_once $dompdfAutoload;

    if (class_exists('\\Dompdf\\Dompdf')) {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dompdf->stream(
            $fileBase . '.pdf',
            ['Attachment' => true]
        );

        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

echo $html;
