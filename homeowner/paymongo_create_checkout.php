<?php
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
  header("Location: ../index.php");
  exit;
}

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

require_once 'tenant_module_guard.php';

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function back_err($msg, $year = null){
  $url = "homeowner_pay_dues.php?err=" . urlencode($msg);
  if ($year !== null) {
    $url .= "&year=" . urlencode((string)$year);
  }
  header("Location: " . $url);
  exit;
}

$isTenant = ($_SESSION['role'] === 'tenant');
$tenant = null;
$user = null;
$hid = 0;

// CSRF
$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_pay_dues']) || !hash_equals($_SESSION['csrf_pay_dues'], $csrf)) {
  back_err("Invalid request. Please try again.");
}

$year  = (int)($_POST['year'] ?? 0);
$month = (int)($_POST['month'] ?? 0);

if ($month < 1 || $month > 12) back_err("Invalid month.");
if ($year < 2000 || $year > ((int)date('Y') + 1)) back_err("Invalid year.");

if ($isTenant) {
  if (empty($_SESSION['tenant_id']) || empty($_SESSION['tenant_homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $tenant_id = (int)$_SESSION['tenant_id'];
  $hid = (int)$_SESSION['tenant_homeowner_id'];

  $stmt = $conn->prepare("
    SELECT id, homeowner_id, first_name, last_name, email, status, phase,
           can_pay_dues, can_rent, can_parking, can_announcements, registered_at
    FROM tenants
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $tenant_id);
  $stmt->execute();
  $tenant = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$tenant || $tenant['status'] !== 'active') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }

  tenant_guard('pay_dues', $tenant);

  $stmt = $conn->prepare("
    SELECT id, status, first_name, last_name, email, contact_number, phase, house_lot_number, created_at
    FROM homeowners
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || $user['status'] !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }

} else {
  if (empty($_SESSION['homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $hid = (int)$_SESSION['homeowner_id'];

  $stmt = $conn->prepare("
    SELECT id, status, first_name, last_name, email, contact_number, phase, house_lot_number, created_at
    FROM homeowners
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || $user['status'] !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }
}

$phase    = (string)$user['phase'];
$houseLot = (string)($user['house_lot_number'] ?? '');

if ($isTenant) {
  $fullName = trim((string)($tenant['first_name'] ?? '') . ' ' . (string)($tenant['last_name'] ?? ''));
  $email    = (string)($tenant['email'] ?? '');
  $phone    = (string)($user['contact_number'] ?? '');
  $accountStartRaw = (string)($tenant['registered_at'] ?? '');
} else {
  $fullName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
  $email    = (string)($user['email'] ?? '');
  $phone    = (string)($user['contact_number'] ?? '');
  $accountStartRaw = (string)($user['created_at'] ?? '');
}

$accountStartTs = strtotime($accountStartRaw);
if (!$accountStartTs) {
  $accountStartTs = time();
}

$duesStartYear  = (int)date('Y', $accountStartTs);
$duesStartMonth = (int)date('n', $accountStartTs);

if (
  $year < $duesStartYear ||
  ($year === $duesStartYear && $month < $duesStartMonth)
) {
  back_err("You can only pay starting from your account creation month.", $duesStartYear);
}

// If already paid, block
$stmt = $conn->prepare("
  SELECT id
  FROM finance_payments
  WHERE homeowner_id=? AND pay_year=? AND pay_month=? AND status='paid'
  LIMIT 1
");
$stmt->bind_param("iii", $hid, $year, $month);
$stmt->execute();
$already = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($already) {
  back_err("This month is already marked as PAID.", $year);
}

// Get dues
$stmt = $conn->prepare("
  SELECT monthly_dues
  FROM finance_dues_settings
  WHERE phase=?
  LIMIT 1
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$monthlyDues = (float)($stmt->get_result()->fetch_assoc()['monthly_dues'] ?? 0);
$stmt->close();

if ($monthlyDues <= 0) {
  back_err("Monthly dues is not set yet. Please contact HOA.", $year);
}

// Mark old pending checkouts for same month as cancelled
$stmt = $conn->prepare("
  UPDATE finance_paymongo_checkouts
  SET status='expired'
  WHERE homeowner_id=? AND pay_year=? AND pay_month=? AND status='pending'
");
$stmt->bind_param("iii", $hid, $year, $month);
$stmt->execute();
$stmt->close();

// ---- PayMongo keys (SERVER SIDE ONLY) ----
$PAYMONGO_SECRET = getenv('PAYMONGO_SECRET_KEY') ?: 'sk_test_Rxb7X283U4N6dTvWTP4oE81y';

// Build absolute URLs
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$base    = $scheme . '://' . $host . $baseDir;

$successUrl = $base . '/homeowner_pay_dues.php?paid=1&year=' . urlencode((string)$year);
$cancelUrl  = $base . '/homeowner_pay_dues.php?cancel=1&year=' . urlencode((string)$year);

$desc = "South Meridian HOA Monthly Dues - {$phase} - {$houseLot} - {$year}-" . str_pad((string)$month, 2, '0', STR_PAD_LEFT);
$amountCentavos = (int)round($monthlyDues * 100);

$paymentMethodTypes = ["gcash"];

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
          "description" => "{$year}-" . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . " dues"
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
  back_err("PayMongo error: " . $err, $year);
}

$body = json_decode($response, true);

if ($http < 200 || $http >= 300 || empty($body['data']['id'])) {
  $msg = $body['errors'][0]['detail'] ?? ($body['errors'][0]['code'] ?? 'Unable to create checkout session.');
  back_err("PayMongo: " . $msg, $year);
}

$csId = (string)$body['data']['id'];
$checkoutUrl = (string)($body['data']['attributes']['checkout_url'] ?? '');

if ($checkoutUrl === '') {
  back_err("PayMongo did not return checkout_url.", $year);
}

// Save new checkout as pending
$stmt = $conn->prepare("
  INSERT INTO finance_paymongo_checkouts
    (checkout_session_id, checkout_url, homeowner_id, phase, pay_year, pay_month, amount, status)
  VALUES (?,?,?,?,?,?,?,'pending')
");
$stmt->bind_param("ssisiid", $csId, $checkoutUrl, $hid, $phase, $year, $month, $monthlyDues);
$stmt->execute();
$stmt->close();

header("Location: " . $checkoutUrl);
exit;