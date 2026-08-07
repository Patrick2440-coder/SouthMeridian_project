<?php
session_start();

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function phase_code(string $phase): string
{
    return $phase === 'Phase 1' ? 'P1' : ($phase === 'Phase 2' ? 'P2' : ($phase === 'Phase 3' ? 'P3' : 'PX'));
}

function next_permit_no(mysqli $conn, string $phase): string
{
    $prefix = phase_code($phase) . "-";

    $stmt = $conn->prepare("
        SELECT permit_no
        FROM parking_permits
        WHERE phase = ?
          AND permit_no IS NOT NULL
          AND permit_no LIKE CONCAT(?, '%')
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("ss", $phase, $prefix);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $n = 0;
    if ($row && !empty($row['permit_no'])) {
        $parts = explode("-", (string)$row['permit_no']);
        $last = end($parts);
        if (ctype_digit((string)$last)) {
            $n = (int)$last;
        }
    }

    $n++;
    return $prefix . str_pad((string)$n, 3, "0", STR_PAD_LEFT);
}

function redirect_parking(string $query = ''): void
{
    $url = "https://southmeridianhomes.online/homeowner/homeowner_parking.php";
    if ($query !== '') {
        $url .= '?' . ltrim($query, '?');
    }
    header("Location: " . $url);
    exit;
}

$permitId = (int)($_GET['permit_id'] ?? 0);

if ($permitId <= 0) {
    die("Invalid permit ID.");
}

$stmt = $conn->prepare("
    SELECT *
    FROM parking_permits
    WHERE id = ?
      AND payment_method = 'online'
    LIMIT 1
");
$stmt->bind_param("i", $permitId);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$permit) {
    die("Permit not found.");
}

$hid           = (int)$permit['homeowner_id'];
$permitStatus  = strtolower(trim((string)($permit['status'] ?? 'pending')));
$paymentStatus = strtolower(trim((string)($permit['payment_status'] ?? 'unpaid')));
$phase         = (string)($permit['phase'] ?? 'Phase 1');

// Rejected / revoked / expired checks
if ($permitStatus === 'rejected') {
    redirect_parking('rejected=1');
}

if ($permitStatus === 'revoked') {
    redirect_parking('revoked=1');
}

if ($permitStatus === 'expired') {
    redirect_parking('expired=1');
}

// If already activated before, just redirect when session is still valid
$hasValidSession =
    (
        isset($_SESSION['role']) &&
        $_SESSION['role'] === 'homeowner' &&
        !empty($_SESSION['homeowner_id']) &&
        (int)$_SESSION['homeowner_id'] === $hid
    )
    ||
    (
        isset($_SESSION['role']) &&
        $_SESSION['role'] === 'tenant' &&
        !empty($_SESSION['tenant_homeowner_id']) &&
        (int)$_SESSION['tenant_homeowner_id'] === $hid
    );

if ($paymentStatus === 'paid' && $permitStatus === 'active') {
    if ($hasValidSession) {
        redirect_parking('paid=1&active=1');
    }
}

// Must be pending + for payment before activation
if ($permitStatus === 'pending' && $paymentStatus === 'for payment') {
    $validFrom  = !empty($permit['valid_from']) ? (string)$permit['valid_from'] : date('Y-m-d');
    $validUntil = !empty($permit['valid_until']) ? (string)$permit['valid_until'] : date('Y-m-d');

    $permitNo = !empty($permit['permit_no']) ? (string)$permit['permit_no'] : next_permit_no($conn, $phase);

    $stmt = $conn->prepare("
        UPDATE parking_permits
        SET payment_status = 'paid',
            status = 'active',
            permit_no = ?,
            valid_from = ?,
            valid_until = ?,
            updated_at = NOW()
        WHERE id = ?
          AND payment_method = 'online'
          AND status = 'pending'
          AND payment_status = 'for payment'
        LIMIT 1
    ");
    $stmt->bind_param("sssi", $permitNo, $validFrom, $validUntil, $permitId);
    $stmt->execute();
    $stmt->close();

    // refresh session check after update
    $hasValidSession =
        (
            isset($_SESSION['role']) &&
            $_SESSION['role'] === 'homeowner' &&
            !empty($_SESSION['homeowner_id']) &&
            (int)$_SESSION['homeowner_id'] === $hid
        )
        ||
        (
            isset($_SESSION['role']) &&
            $_SESSION['role'] === 'tenant' &&
            !empty($_SESSION['tenant_homeowner_id']) &&
            (int)$_SESSION['tenant_homeowner_id'] === $hid
        );

    if ($hasValidSession) {
        redirect_parking('paid=1&active=1');
    }
}

// No valid session? Do NOT send them to homepage automatically.
// Show success page instead.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="mx-auto bg-white shadow rounded-4 p-4 p-md-5" style="max-width: 680px;">
            <div class="text-center">
                <h2 class="text-success fw-bold mb-3">Payment Successful</h2>
                <p class="mb-2">Your parking permit payment has been recorded successfully.</p>
                <p class="text-muted mb-4">
                    Your permit is already marked as <strong>paid</strong> and <strong>active</strong>.
                </p>

                <div class="alert alert-info text-start">
                    <strong>Why am I not redirected automatically to Parking Overview?</strong><br>
                    Your payment may have returned in a different browser or app, so your login session is not available there.
                </div>

                <div class="d-grid gap-2">
                    <a href="https://southmeridianhomes.online/homeowner/homeowner_parking.php?paid=1&active=1" class="btn btn-success btn-lg">
                        Go to Parking Overview
                    </a>
                    <a href="https://southmeridianhomes.online/index.php" class="btn btn-outline-secondary">
                        Login Again
                    </a>
                </div>

                <div class="mt-4 small text-muted">
                    After logging in again, open the Parking Overview and your permit should already appear as active and paid.
                </div>
            </div>
        </div>
    </div>
</body>
</html>