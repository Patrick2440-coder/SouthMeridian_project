<?php
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
    header("Location: ../index.php");
    exit;
}

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) {
    die("DB Error: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

require_once 'tenant_module_guard.php';

$isTenant = ($_SESSION['role'] === 'tenant');
$hid = 0;
$payer = null;

if ($isTenant) {
    if (empty($_SESSION['tenant_id']) || empty($_SESSION['tenant_homeowner_id'])) {
        header("Location: ../index.php");
        exit;
    }

    $tenantId = (int)$_SESSION['tenant_id'];
    $hid = (int)$_SESSION['tenant_homeowner_id'];

    $stmtTenant = $conn->prepare("
        SELECT id, homeowner_id, first_name, middle_name, last_name, email, contact_number, status, can_parking
        FROM tenants
        WHERE id = ?
        LIMIT 1
    ");
    $stmtTenant->bind_param("i", $tenantId);
    $stmtTenant->execute();
    $tenant = $stmtTenant->get_result()->fetch_assoc();
    $stmtTenant->close();

    if (!$tenant || $tenant['status'] !== 'active') {
        session_destroy();
        header("Location: ../index.php");
        exit;
    }

    tenant_guard('parking', $tenant);

    $payer = [
        'first_name'     => (string)($tenant['first_name'] ?? ''),
        'middle_name'    => (string)($tenant['middle_name'] ?? ''),
        'last_name'      => (string)($tenant['last_name'] ?? ''),
        'email'          => (string)($tenant['email'] ?? ''),
        'contact_number' => (string)($tenant['contact_number'] ?? ''),
    ];
} else {
    if (empty($_SESSION['homeowner_id'])) {
        header("Location: ../index.php");
        exit;
    }

    $hid = (int)($_SESSION['homeowner_id'] ?? 0);

    $stmtUser = $conn->prepare("
        SELECT first_name, middle_name, last_name, email, contact_number, status
        FROM homeowners
        WHERE id = ?
        LIMIT 1
    ");
    $stmtUser->bind_param("i", $hid);
    $stmtUser->execute();
    $user = $stmtUser->get_result()->fetch_assoc();
    $stmtUser->close();

    if (!$user || ($user['status'] ?? '') !== 'approved') {
        die("Homeowner not found or not approved.");
    }

    $payer = [
        'first_name'     => (string)($user['first_name'] ?? ''),
        'middle_name'    => (string)($user['middle_name'] ?? ''),
        'last_name'      => (string)($user['last_name'] ?? ''),
        'email'          => (string)($user['email'] ?? ''),
        'contact_number' => (string)($user['contact_number'] ?? ''),
    ];
}

$permitId = (int)($_GET['permit_id'] ?? 0);

if ($permitId <= 0) {
    die("Invalid permit ID.");
}

$fullName = trim(
    ($payer['first_name'] ?? '') . ' ' .
    (!empty($payer['middle_name']) ? $payer['middle_name'] . ' ' : '') .
    ($payer['last_name'] ?? '')
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

$permitStatus  = strtolower(trim((string)($permit['status'] ?? 'pending')));
$paymentStatus = strtolower(trim((string)($permit['payment_status'] ?? 'unpaid')));

// Already paid
if ($paymentStatus === 'paid') {
    header("Location: https://southmeridianhomes.online/homeowner/payment_success.php?permit_id=" . $permitId);
    exit;
}

// Rejected / revoked / expired
if ($permitStatus === 'rejected') {
    header("Location: https://southmeridianhomes.online/homeowner/homeowner_parking.php?rejected=1");
    exit;
}

if ($permitStatus === 'revoked') {
    header("Location: https://southmeridianhomes.online/homeowner/homeowner_parking.php?revoked=1");
    exit;
}

if ($permitStatus === 'expired') {
    header("Location: https://southmeridianhomes.online/homeowner/homeowner_parking.php?expired=1");
    exit;
}

// Waiting for admin approval
if ($permitStatus === 'pending' && $paymentStatus !== 'for payment') {
    header("Location: https://southmeridianhomes.online/homeowner/homeowner_parking.php?waiting_approval=1");
    exit;
}

// Allowed only when admin opened payment
if (!($permitStatus === 'pending' && $paymentStatus === 'for payment')) {
    die("This permit is not eligible for payment.");
}

// ===================== AMOUNT =====================
$amountMap = [
    '1_month'  => 50000,
    '3_months' => 120000,
    '6_months' => 200000,
    '1_year'   => 350000,
];

$duration = (string)($permit['permit_duration'] ?? '');
$amount   = $amountMap[$duration] ?? 50000;

// ===================== PAYMONGO =====================
$secret = "sk_test_Rxb7X283U4N6dTvWTP4oE81y";
$baseUrl = "https://southmeridianhomes.online";

$successUrl = $baseUrl . "/homeowner/payment_success.php?permit_id=" . $permitId;
$cancelUrl  = $baseUrl . "/homeowner/payment_cancelled.php?permit_id=" . $permitId;

$data = [
    "data" => [
        "attributes" => [
            "billing" => [
                "name"  => $fullName,
                "email" => (string)($payer['email'] ?? ''),
                "phone" => (string)($payer['contact_number'] ?? '')
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
            "description" => "Parking permit payment for Permit ID #" . $permitId,
            "metadata" => [
                "permit_id" => (string)$permitId,
                "homeowner_id" => (string)$hid,
                "module" => "parking_permit"
            ]
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