<?php
require_once __DIR__ . "/finance_helpers.php";
require_admin();
$conn = db_conn();

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die("Invalid donation id.");

$stmt = $conn->prepare("SELECT * FROM finance_donations WHERE id=? AND phase=? LIMIT 1");
$stmt->bind_param("is", $id, $phase);
$stmt->execute();
$d = $stmt->get_result()->fetch_assoc();
if (!$d) die("Donation not found for this phase.");

$receipt_no = $d['receipt_no'] ?: ("DON-" . str_pad((string)$d['id'], 6, "0", STR_PAD_LEFT));

$html = '
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Donation Receipt</title>
<style>
  body{ font-family: Arial, sans-serif; font-size: 12px; }
  .box{ border:1px solid #333; padding:16px; max-width:700px; margin: 0 auto; }
  h2{ margin:0 0 10px 0; }
  table{ width:100%; border-collapse: collapse; margin-top:10px; }
  td{ padding:6px; }
  .right{ text-align:right; }
  .muted{ color:#666; font-size:11px; }
</style>
</head>
<body>
  <div class="box">
    <h2>Donation Acknowledgment Receipt</h2>
    <div><b>Phase:</b> '.esc($d['phase']).'</div>
    <div><b>Receipt No:</b> '.esc($receipt_no).'</div>

    <table>
      <tr><td><b>Donor Name</b></td><td class="right">'.esc($d['donor_name']).'</td></tr>
      <tr><td><b>Donor Email</b></td><td class="right">'.esc($d['donor_email'] ?? '-').'</td></tr>
      <tr><td><b>Date</b></td><td class="right">'.esc($d['donation_date']).'</td></tr>
      <tr><td><b>Amount</b></td><td class="right">₱ '.number_format((float)$d['amount'],2).'</td></tr>
      <tr><td><b>Message/Notes</b></td><td class="right">'.esc($d['message'] ?? '-').'</td></tr>
    </table>

    <div style="margin-top:18px;">
      <div><b>Thank you</b> for supporting the community.</div>
      <div class="muted">Generated on: '.date('Y-m-d H:i:s').'</div>
    </div>
  </div>
</body>
</html>';

// Dompdf if installed
$autoload = __DIR__ . "/vendor/autoload.php";
if (file_exists($autoload)) {
  require_once $autoload;
  if (class_exists("\\Dompdf\\Dompdf")) {
    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper("A4", "portrait");
    $dompdf->render();
    $dompdf->stream("donation_receipt_{$receipt_no}.pdf", ["Attachment" => true]);
    exit;
  }
}

// fallback printable
header("Content-Type: text/html; charset=utf-8");
echo $html;
