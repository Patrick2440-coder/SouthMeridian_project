<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
    header("Location: ../index.php");
    exit;
}



require_once 'tenant_module_guard.php';

/*
|--------------------------------------------------------------------------
| Trusted payment/application configuration
|--------------------------------------------------------------------------
*/

$secretsFile =
    dirname(__DIR__) .
    '/admin/private/hoa_secrets.php';

if (!is_file($secretsFile)) {
    http_response_code(503);
    die("Payment configuration is unavailable.");
}

$secrets =
    require $secretsFile;

$secret =
    trim(
        (string)(
            $secrets['paymongo_secret_key']
            ?? ''
        )
    );

$baseUrl =
    rtrim(
        trim(
            (string)(
                $secrets['app_base_url']
                ?? ''
            )
        ),
        '/'
    );

if ($secret === '') {
    http_response_code(503);
    die("Payment gateway is not configured.");
}

if (
    $baseUrl === '' ||
    !filter_var(
        $baseUrl,
        FILTER_VALIDATE_URL
    )
) {
    http_response_code(503);
    die("Application URL is not configured correctly.");
}

function app_url(
    string $baseUrl,
    string $path
): string {
    return
        rtrim($baseUrl, '/') .
        '/' .
        ltrim($path, '/');
}

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

    $stmtHomeowner = $conn->prepare("
        SELECT status, phase
        FROM homeowners
        WHERE id = ?
        LIMIT 1
    ");
    $stmtHomeowner->bind_param("i", $hid);
    $stmtHomeowner->execute();
    $associatedHomeowner =
        $stmtHomeowner
            ->get_result()
            ->fetch_assoc();
    $stmtHomeowner->close();

    if (
        !$associatedHomeowner ||
        ($associatedHomeowner['status'] ?? '') !== 'approved'
    ) {
        session_destroy();
        header("Location: ../index.php");
        exit;
    }

    $accountPhase =
        (string)(
            $associatedHomeowner['phase']
            ?? ''
        );

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
        SELECT
            first_name,
            middle_name,
            last_name,
            email,
            contact_number,
            status,
            phase,
            must_change_password
        FROM homeowners
        WHERE id = ?
        LIMIT 1
    ");
    $stmtUser->bind_param("i", $hid);
    $stmtUser->execute();
    $user = $stmtUser->get_result()->fetch_assoc();
    $stmtUser->close();

    if (!$user || ($user['status'] ?? '') !== 'approved') {
        session_destroy();
        header("Location: ../index.php");
        exit;
    }

    if ((int)($user['must_change_password'] ?? 0) === 1) {
        header("Location: homeowner_dashboard.php");
        exit;
    }

    $accountPhase =
        (string)(
            $user['phase']
            ?? ''
        );

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

if (
    !in_array(
        $accountPhase,
        ['Phase 1', 'Phase 2', 'Phase 3'],
        true
    )
) {
    http_response_code(403);
    die("Invalid account phase.");
}

// ===================== PERMIT =====================
$stmt = $conn->prepare("
    SELECT *
    FROM parking_permits
    WHERE id = ?
      AND homeowner_id = ?
      AND phase = ?
      AND payment_method = 'online'
    LIMIT 1
");
$stmt->bind_param("iis", $permitId, $hid, $accountPhase);
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
    header("Location: " . app_url($baseUrl, "/homeowner/payment_success.php?permit_id=" . $permitId));
    exit;
}

// Rejected / revoked / expired
if ($permitStatus === 'rejected') {
    header("Location: " . app_url($baseUrl, "/homeowner/homeowner_parking.php?rejected=1"));
    exit;
}

if ($permitStatus === 'revoked') {
    header("Location: " . app_url($baseUrl, "/homeowner/homeowner_parking.php?revoked=1"));
    exit;
}

if ($permitStatus === 'expired') {
    header("Location: " . app_url($baseUrl, "/homeowner/homeowner_parking.php?expired=1"));
    exit;
}

// Waiting for admin approval
if ($permitStatus === 'pending' && $paymentStatus !== 'for payment') {
    header("Location: " . app_url($baseUrl, "/homeowner/homeowner_parking.php?waiting_approval=1"));
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

$duration =
    (string)(
        $permit['permit_duration']
        ?? ''
    );

if (!array_key_exists($duration, $amountMap)) {
    error_log(
        'Parking checkout rejected invalid permit duration. ' .
        'permit_id=' .
        $permitId .
        ', duration=' .
        $duration
    );

    http_response_code(422);
    die("Invalid permit duration. Please contact the HOA office.");
}

$amount =
    $amountMap[$duration];

/*
|--------------------------------------------------------------------------
| Amount values sent to PayMongo are in centavos.
| Database amount is stored in pesos.
|--------------------------------------------------------------------------
*/

$amountPesos = $amount / 100;

$phase =
    (string)(
        $permit['phase']
        ?? ''
    );

if (
    $phase !== $accountPhase ||
    !in_array(
        $phase,
        ['Phase 1', 'Phase 2', 'Phase 3'],
        true
    )
) {
    http_response_code(403);
    die("Invalid permit phase.");
}

// ===================== PAYMONGO =====================

$successUrl =
    app_url(
        $baseUrl,
        "/homeowner/payment_success.php?permit_id=" .
        $permitId
    );

$cancelUrl =
    app_url(
        $baseUrl,
        "/homeowner/payment_cancelled.php?permit_id=" .
        $permitId
    );

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
curl_setopt(
    $ch,
    CURLOPT_POSTFIELDS,
    json_encode(
        $data,
        JSON_UNESCAPED_SLASHES
    )
);

curl_setopt(
    $ch,
    CURLOPT_CONNECTTIMEOUT,
    10
);

curl_setopt(
    $ch,
    CURLOPT_TIMEOUT,
    30
);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false || $curlErr) {
    error_log(
        'PayMongo parking checkout connection failure. ' .
        'permit_id=' .
        $permitId .
        ', error=' .
        $curlErr
    );

    http_response_code(502);
    die("Payment gateway connection failed. Please try again.");
}

$result = json_decode($response, true);

if (
    $httpCode < 200 ||
    $httpCode >= 300 ||
    empty($result['data']['id']) ||
    empty($result['data']['attributes']['checkout_url']) ||
    !filter_var(
        (string)$result['data']['attributes']['checkout_url'],
        FILTER_VALIDATE_URL
    )
) {
    error_log(
        "PayMongo parking checkout creation failed. HTTP: " .
        $httpCode
    );

    http_response_code(502);
    die("Unable to create payment checkout. Please try again.");
}


/*
|--------------------------------------------------------------------------
| PayMongo checkout information
|--------------------------------------------------------------------------
*/

$checkoutSessionId =
    (string)$result['data']['id'];

$checkoutUrl =
    (string)$result['data']['attributes']['checkout_url'];


/*
|--------------------------------------------------------------------------
| Save checkout session BEFORE redirecting to PayMongo
|--------------------------------------------------------------------------
|
| This creates a trusted relationship:
|
| PayMongo checkout_session_id
|            ↓
| parking permit
|            ↓
| homeowner
|
*/

try {

    $conn->begin_transaction();


    /*
    |--------------------------------------------------------------------------
    | Expire older pending checkout attempts for this permit
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        UPDATE parking_paymongo_checkouts
        SET status = 'expired'
        WHERE permit_id = ?
          AND homeowner_id = ?
          AND status = 'pending'
    ");

    $stmt->bind_param(
        "ii",
        $permitId,
        $hid
    );

    $stmt->execute();
    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | Save newly-created PayMongo checkout
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        INSERT INTO parking_paymongo_checkouts
        (
            checkout_session_id,
            checkout_url,
            permit_id,
            homeowner_id,
            phase,
            amount,
            status
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'pending'
        )
    ");

    $stmt->bind_param(
        "ssiisd",
        $checkoutSessionId,
        $checkoutUrl,
        $permitId,
        $hid,
        $phase,
        $amountPesos
    );

    $stmt->execute();
    $stmt->close();


    $conn->commit();

} catch (Throwable $e) {

    $conn->rollback();

    error_log(
        "Parking PayMongo checkout database error: " .
        $e->getMessage()
    );

    die(
        "The payment checkout was created, but the system " .
        "could not securely record it. Please do not continue " .
        "with this payment. Try again later."
    );
}


/*
|--------------------------------------------------------------------------
| Redirect only AFTER the checkout has been recorded
|--------------------------------------------------------------------------
*/

header(
    "Location: " .
    $checkoutUrl
);

exit;