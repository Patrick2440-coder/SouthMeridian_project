<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
  header("Location: ../index.php");
  exit;
}

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

$hid = (int)($_SESSION['homeowner_id'] ?? 0);
$permitId = (int)($_GET['permit_id'] ?? 0);

if ($permitId <= 0) {
  die("Invalid permit ID.");
}

// ===================== HOMEOWNER INFO =====================
$stmtUser = $conn->prepare("
  SELECT first_name, middle_name, last_name, email, contact_number
  FROM homeowners
  WHERE id = ?
  LIMIT 1
");
$stmtUser->bind_param("i", $hid);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

if (!$user) {
  die("Homeowner not found.");
}

$fullName = trim(
  ($user['first_name'] ?? '') . ' ' .
  (!empty($user['middle_name']) ? $user['middle_name'] . ' ' : '') .
  ($user['last_name'] ?? '')
);

// ===================== PERMIT =====================
$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE id = ?
    AND homeowner_id = ?
    AND payment_method = 'online'
  LIMIT 1
");
$stmt->bind_param("ii", $permitId, $hid);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$permit) {
  die("Permit not found or access denied.");
}

$permitStatus  = strtolower((string)($permit['status'] ?? 'pending'));
$paymentStatus = strtolower((string)($permit['payment_status'] ?? 'unpaid'));

if ($paymentStatus === 'paid') {
  header("Location: homeowner_parking_permit.php?paid=1");
  exit;
}

// New workflow guard:
// homeowner can only pay AFTER admin approval
if ($permitStatus === 'pending') {
  header("Location: homeowner_parking_permit.php?waiting_approval=1");
  exit;
}

if ($permitStatus === 'rejected') {
  header("Location: homeowner_parking_permit.php?rejected=1");
  exit;
}

if ($permitStatus === 'revoked') {
  header("Location: homeowner_parking_permit.php?revoked=1");
  exit;
}

if ($permitStatus === 'expired') {
  header("Location: homeowner_parking_permit.php?expired=1");
  exit;
}

// Only approved permit should proceed to payment
if (!in_array($permitStatus, ['approved'], true)) {
  die("This permit is not eligible for payment.");
}

// ===================== AMOUNT =====================
$amountMap = [
  '1_month'  => 50000,   // 500.00 PHP
  '3_months' => 120000,  // 1200.00 PHP
  '6_months' => 200000,  // 2000.00 PHP
  '1_year'   => 350000,  // 3500.00 PHP
];

$duration = (string)($permit['permit_duration'] ?? '');
$amount   = $amountMap[$duration] ?? 50000;

// ===================== PAYMONGO =====================
$secret = "sk_test_Rxb7X283U4N6dTvWTP4oE81y";
$baseUrl = "https://southmeridianhomes.online";

$successUrl = $baseUrl . "/homeowner/payment_success.php?permit_id=" . $permitId;
$cancelUrl  = $baseUrl . "/homeowner/homeowner_parking_permit.php?cancelled=1";

$data = [
  "data" => [
    "attributes" => [
      "billing" => [
        "name"  => $fullName,
        "email" => (string)($user['email'] ?? ''),
        "phone" => (string)($user['contact_number'] ?? '')
      ],
      "line_items" => [[
        "currency"    => "PHP",
        "amount"      => $amount,
        "name"        => "Parking Permit - " . ucfirst(str_replace('_', ' ', $duration)),
        "quantity"    => 1,
        "description" => "South Meridian Homes Parking Permit"
      ]],
      "payment_method_types" => ["gcash"],
      "success_url" => $successUrl,
      "cancel_url"  => $cancelUrl,
      "description" => "Parking permit payment for Permit ID #" . $permitId
    ]
  ]
];

$ch = curl_init("https://api.paymongo.com/v1/checkout_sessions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  "Content-Type: application/json",
  "Accept: application/json",
  "Authorization: Basic " . base64_encode($secret . ":")
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false || $curlErr) {
  die("Payment gateway connection failed: " . $curlErr);
}

$result = json_decode($response, true);

if ($httpCode < 200 || $httpCode >= 300 || empty($result['data']['attributes']['checkout_url'])) {
  echo "<pre>";
  echo "Unable to create checkout session.\n\n";
  echo "HTTP Code: " . $httpCode . "\n\n";
  print_r($result);
  echo "</pre>";
  exit;
}

header("Location: " . $result['data']['attributes']['checkout_url']);
exit;