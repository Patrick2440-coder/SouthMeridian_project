<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
  header("Location: ../index.php");
  exit;
}

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function back_err($msg){
  header("Location: homeowner_pay_dues.php?err=" . urlencode($msg));
  exit;
}

$hid = (int)$_SESSION['homeowner_id'];

// CSRF
$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_pay_dues']) || !hash_equals($_SESSION['csrf_pay_dues'], $csrf)) {
  back_err("Invalid request. Please try again.");
}

$year  = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);
if ($year < 2000 || $year > ((int)date('Y') + 1)) back_err("Invalid year.");
if ($month < 1 || $month > 12) back_err("Invalid month.");

// Load homeowner
$stmt = $conn->prepare("SELECT id, status, first_name, last_name, email, contact_number, phase, house_lot_number FROM homeowners WHERE id=? LIMIT 1");
$stmt->bind_param("i", $hid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user['status'] !== 'approved') {
  session_destroy();
  header("Location: ../index.php");
  exit;
}

$phase = (string)$user['phase'];
$fullName = trim($user['first_name'].' '.$user['last_name']);
$email = (string)($user['email'] ?? '');
$phone = (string)($user['contact_number'] ?? '');
$houseLot = (string)($user['house_lot_number'] ?? '');

// If already paid, block
$stmt = $conn->prepare("SELECT id FROM finance_payments WHERE homeowner_id=? AND pay_year=? AND pay_month=? AND status='paid' LIMIT 1");
$stmt->bind_param("iii", $hid, $year, $month);
$stmt->execute();
$already = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($already) back_err("This month is already marked as PAID.");

// Optional: block if there is already pending checkout for same month
$stmt = $conn->prepare("SELECT id FROM finance_paymongo_checkouts WHERE homeowner_id=? AND pay_year=? AND pay_month=? AND status='pending' LIMIT 1");
$stmt->bind_param("iii", $hid, $year, $month);
$stmt->execute();
$pendingExists = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($pendingExists) back_err("You already have a PENDING checkout for this month. Please wait or refresh.");

// Get dues
$stmt = $conn->prepare("SELECT monthly_dues FROM finance_dues_settings WHERE phase=? LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$monthlyDues = (float)($stmt->get_result()->fetch_assoc()['monthly_dues'] ?? 0);
$stmt->close();

if ($monthlyDues <= 0) back_err("Monthly dues is not set yet. Please contact HOA.");

// ---- PayMongo keys (SERVER SIDE ONLY) ----
$PAYMONGO_SECRET = getenv('PAYMONGO_PUBLIC_KEY') ?: 'pk_test_XPpTJrGNHoL8HBvx7eAWMH3C';

// Build absolute URLs (IMPORTANT: for live webhook + success redirect, use public URL not localhost)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseDir = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$base = $scheme . '://' . $host . $baseDir;

$successUrl = $base . '/homeowner_pay_dues.php?paid=1&year=' . urlencode((string)$year);
$cancelUrl  = $base . '/homeowner_pay_dues.php?cancel=1&year=' . urlencode((string)$year);

$desc = "South Meridian HOA Monthly Dues - {$phase} - {$houseLot} - {$year}-" . str_pad((string)$month,2,'0',STR_PAD_LEFT);
$amountCentavos = (int)round($monthlyDues * 100);

$paymentMethodTypes = ["gcash","card","paymaya","grab_pay"];

$payload = [
  "data" => [
    "attributes" => [
      "description" => $desc,
      "line_items" => [
        [
          "name" => "HOA Monthly Dues ({$phase})",
          "quantity" => 1,
          "amount" => $amountCentavos,
          "currency" => "PHP",
          "description" => "{$year}-" . str_pad((string)$month,2,'0',STR_PAD_LEFT) . " dues"
        ]
      ],
      "payment_method_types" => $paymentMethodTypes,
      "success_url" => $successUrl,
      "cancel_url" => $cancelUrl,
      "send_email_receipt" => true,
      "billing" => [
        "name" => $fullName,
        "email" => $email,
        "phone" => $phone
      ]
    ]
  ]
];

$ch = curl_init("https://api.paymongo.com/v1/checkout_sessions");
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    "Content-Type: application/json",
    "Authorization: Basic " . base64_encode($PAYMONGO_SECRET . ":")
  ],
  CURLOPT_POSTFIELDS => json_encode($payload),
  CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($response === false) {
  back_err("PayMongo error: " . $err);
}

$body = json_decode($response, true);
if ($http < 200 || $http >= 300 || empty($body['data']['id'])) {
  $msg = $body['errors'][0]['detail'] ?? ($body['errors'][0]['code'] ?? 'Unable to create checkout session.');
  back_err("PayMongo: " . $msg);
}

$csId = (string)$body['data']['id'];
$checkoutUrl = (string)($body['data']['attributes']['checkout_url'] ?? '');

if ($checkoutUrl === '') {
  back_err("PayMongo did not return checkout_url.");
}

// ✅ FIXED: correct bind_param types so phase is stored properly
$stmt = $conn->prepare("
  INSERT INTO finance_paymongo_checkouts
    (checkout_session_id, checkout_url, homeowner_id, phase, pay_year, pay_month, amount, status)
  VALUES (?,?,?,?,?,?,?,'pending')
  ON DUPLICATE KEY UPDATE
    checkout_url=VALUES(checkout_url),
    status='pending',
    amount=VALUES(amount),
    phase=VALUES(phase),
    pay_year=VALUES(pay_year),
    pay_month=VALUES(pay_month),
    updated_at=CURRENT_TIMESTAMP
");

// checkout_session_id (s), checkout_url (s), homeowner_id (i), phase (s), year (i), month (i), amount (d)
$stmt->bind_param("ssisiid", $csId, $checkoutUrl, $hid, $phase, $year, $month, $monthlyDues);

$stmt->execute();
$stmt->close();

header("Location: " . $checkoutUrl);
exit;
