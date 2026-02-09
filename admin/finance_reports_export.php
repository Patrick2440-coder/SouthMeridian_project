<?php
require_once __DIR__ . "/finance_helpers.php";
require_admin();
$conn = db_conn();

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);

// Inputs
$format = $_GET['format'] ?? 'pdf';
$year   = (int)($_GET['year'] ?? (int)date('Y'));
$month  = (int)($_GET['month'] ?? (int)date('n'));
if ($month < 1 || $month > 12) $month = (int)date('n');

// SECURITY: only export approved requests
$stmt = $conn->prepare("
  SELECT status
  FROM finance_report_requests
  WHERE phase=? AND report_year=? AND report_month=?
  LIMIT 1
");
$stmt->bind_param("sii", $phase, $year, $month);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row || $row['status'] !== 'approved') {
  http_response_code(403);
  die("Report not approved. Only approved reports can be exported.");
}

// Get dues setting
$stmt = $conn->prepare("SELECT monthly_dues FROM finance_dues_settings WHERE phase=? LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$monthly_dues = (float)($stmt->get_result()->fetch_assoc()['monthly_dues'] ?? 0);

// Totals for the month
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) total_dues
  FROM finance_payments
  WHERE phase=? AND status='paid' AND pay_year=? AND pay_month=?
");
$stmt->bind_param("sii", $phase, $year, $month);
$stmt->execute();
$total_dues = (float)($stmt->get_result()->fetch_assoc()['total_dues'] ?? 0);

$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) total_don
  FROM finance_donations
  WHERE phase=? AND YEAR(donation_date)=? AND MONTH(donation_date)=?
");
$stmt->bind_param("sii", $phase, $year, $month);
$stmt->execute();
$total_don = (float)($stmt->get_result()->fetch_assoc()['total_don'] ?? 0);

$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) total_exp
  FROM finance_expenses
  WHERE phase=? AND YEAR(expense_date)=? AND MONTH(expense_date)=?
");
$stmt->bind_param("sii", $phase, $year, $month);
$stmt->execute();
$total_exp = (float)($stmt->get_result()->fetch_assoc()['total_exp'] ?? 0);

$net = ($total_dues + $total_don) - $total_exp;

// Expenses breakdown
$stmt = $conn->prepare("
  SELECT category, COALESCE(SUM(amount),0) total
  FROM finance_expenses
  WHERE phase=? AND YEAR(expense_date)=? AND MONTH(expense_date)=?
  GROUP BY category
  ORDER BY total DESC
");
$stmt->bind_param("sii", $phase, $year, $month);
$stmt->execute();
$exp_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Top expenses list
$stmt = $conn->prepare("
  SELECT expense_date, category, description, amount
  FROM finance_expenses
  WHERE phase=? AND YEAR(expense_date)=? AND MONTH(expense_date)=?
  ORDER BY amount DESC
  LIMIT 20
");
$stmt->bind_param("sii", $phase, $year, $month);
$stmt->execute();
$top_exp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Unpaid count estimate for the month (approved homeowners without payment record)
$stmt = $conn->prepare("
  SELECT COUNT(*) c
  FROM homeowners h
  LEFT JOIN finance_payments p
    ON p.homeowner_id=h.id AND p.pay_year=? AND p.pay_month=?
  WHERE h.phase=? AND h.status='approved' AND p.id IS NULL
");
$stmt->bind_param("iis", $year, $month, $phase);
$stmt->execute();
$unpaid_count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

// --- Export Excel (CSV) ---
if ($format === 'excel') {
  $filename = "financial_report_{$phase}_{$year}_".str_pad((string)$month,2,'0',STR_PAD_LEFT).".csv";
  header("Content-Type: text/csv; charset=utf-8");
  header("Content-Disposition: attachment; filename=\"$filename\"");

  $out = fopen("php://output", "w");

  fputcsv($out, ["Financial Report"]);
  fputcsv($out, ["Phase", $phase]);
  fputcsv($out, ["Period", "{$year}-".str_pad((string)$month,2,'0',STR_PAD_LEFT)]);
  fputcsv($out, []);
  fputcsv($out, ["Summary"]);
  fputcsv($out, ["Monthly Dues Setting", number_format($monthly_dues,2,'.','')]);
  fputcsv($out, ["Dues Collected", number_format($total_dues,2,'.','')]);
  fputcsv($out, ["Donations", number_format($total_don,2,'.','')]);
  fputcsv($out, ["Expenses", number_format($total_exp,2,'.','')]);
  fputcsv($out, ["Net (Income - Expenses)", number_format($net,2,'.','')]);
  fputcsv($out, ["Unpaid Homeowners Count", $unpaid_count]);

  fputcsv($out, []);
  fputcsv($out, ["Expenses Breakdown"]);
  fputcsv($out, ["Category", "Total"]);
  foreach ($exp_breakdown as $b) {
    fputcsv($out, [$b['category'], number_format((float)$b['total'],2,'.','')]);
  }

  fputcsv($out, []);
  fputcsv($out, ["Top Expenses (up to 20)"]);
  fputcsv($out, ["Date", "Category", "Description", "Amount"]);
  foreach ($top_exp as $e) {
    fputcsv($out, [$e['expense_date'], $e['category'], $e['description'], number_format((float)$e['amount'],2,'.','')]);
  }

  fclose($out);
  exit;
}

// --- PDF export using Dompdf if installed; else printable HTML fallback ---
$period = "{$year}-".str_pad((string)$month,2,'0',STR_PAD_LEFT);

$html = '
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Financial Report</title>
<style>
  body{ font-family: Arial, sans-serif; font-size: 12px; }
  h2,h3{ margin: 0 0 8px 0; }
  .meta{ margin-bottom: 12px; }
  table{ border-collapse: collapse; width:100%; margin: 10px 0; }
  th,td{ border:1px solid #ccc; padding:6px; text-align:left; }
  .right{ text-align:right; }
  .badge{ display:inline-block; padding:2px 6px; border:1px solid #999; border-radius:4px; }
</style>
</head>
<body>
  <h2>Financial Report</h2>
  <div class="meta">
    <div><b>Phase:</b> '.esc($phase).'</div>
    <div><b>Period:</b> '.esc($period).'</div>
    <div><b>Status:</b> <span class="badge">APPROVED</span></div>
  </div>

  <h3>Summary</h3>
  <table>
    <tr><th>Monthly Dues Setting</th><td class="right">₱ '.number_format($monthly_dues,2).'</td></tr>
    <tr><th>Dues Collected</th><td class="right">₱ '.number_format($total_dues,2).'</td></tr>
    <tr><th>Donations</th><td class="right">₱ '.number_format($total_don,2).'</td></tr>
    <tr><th>Expenses</th><td class="right">₱ '.number_format($total_exp,2).'</td></tr>
    <tr><th><b>Net (Income - Expenses)</b></th><td class="right"><b>₱ '.number_format($net,2).'</b></td></tr>
    <tr><th>Unpaid Homeowners Count</th><td class="right">'.(int)$unpaid_count.'</td></tr>
  </table>

  <h3>Expenses Breakdown</h3>
  <table>
    <tr><th>Category</th><th class="right">Total</th></tr>';

foreach ($exp_breakdown as $b) {
  $html .= '<tr><td>'.esc($b['category']).'</td><td class="right">₱ '.number_format((float)$b['total'],2).'</td></tr>';
}
if (!$exp_breakdown) $html .= '<tr><td colspan="2">No expenses recorded.</td></tr>';

$html .= '</table>

  <h3>Top Expenses (up to 20)</h3>
  <table>
    <tr><th>Date</th><th>Category</th><th>Description</th><th class="right">Amount</th></tr>';

foreach ($top_exp as $e) {
  $html .= '<tr>
    <td>'.esc($e['expense_date']).'</td>
    <td>'.esc($e['category']).'</td>
    <td>'.esc($e['description']).'</td>
    <td class="right">₱ '.number_format((float)$e['amount'],2).'</td>
  </tr>';
}
if (!$top_exp) $html .= '<tr><td colspan="4">No expenses recorded.</td></tr>';

$html .= '</table>
  <div style="margin-top:18px; font-size:11px; color:#666;">
    Generated on: '.date('Y-m-d H:i:s').'
  </div>
</body>
</html>';

// Try Dompdf
$dompdf_autoload = __DIR__ . "/vendor/autoload.php";
if (file_exists($dompdf_autoload)) {
  require_once $dompdf_autoload;
  if (class_exists("\\Dompdf\\Dompdf")) {
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper("A4", "portrait");
    $dompdf->render();
    $dompdf->stream("financial_report_{$phase}_{$period}.pdf", ["Attachment" => true]);
    exit;
  }
}

// Fallback: printable HTML (user can print to PDF)
header("Content-Type: text/html; charset=utf-8");
echo $html;
