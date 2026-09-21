<?php

session_start();

require_once '../config/database.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


/*
|--------------------------------------------------------------------------
| Security headers
|--------------------------------------------------------------------------
*/

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');


function esc($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| Get permit ID
|--------------------------------------------------------------------------
*/

$permitId = filter_input(
    INPUT_GET,
    'permit_id',
    FILTER_VALIDATE_INT
);

$permitId = ($permitId !== false && $permitId !== null)
    ? (int)$permitId
    : 0;


/*
|--------------------------------------------------------------------------
| Determine logged-in account
|--------------------------------------------------------------------------
*/

$role = (string)($_SESSION['role'] ?? '');

$homeownerId = 0;
$hasValidSession = false;


/*
|--------------------------------------------------------------------------
| Homeowner session
|--------------------------------------------------------------------------
*/

if (
    $role === 'homeowner' &&
    !empty($_SESSION['homeowner_id'])
) {

    $homeownerId = (int)$_SESSION['homeowner_id'];

    $stmt = $conn->prepare("
        SELECT id, status
        FROM homeowners
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $homeownerId);
    $stmt->execute();

    $homeowner = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if (
        $homeowner &&
        ($homeowner['status'] ?? '') === 'approved'
    ) {
        $hasValidSession = true;
    }
}


/*
|--------------------------------------------------------------------------
| Tenant session
|--------------------------------------------------------------------------
*/

elseif (
    $role === 'tenant' &&
    !empty($_SESSION['tenant_id']) &&
    !empty($_SESSION['tenant_homeowner_id'])
) {

    $tenantId = (int)$_SESSION['tenant_id'];
    $homeownerId = (int)$_SESSION['tenant_homeowner_id'];

    $stmt = $conn->prepare("
        SELECT
            id,
            homeowner_id,
            status,
            can_parking
        FROM tenants
        WHERE id = ?
          AND homeowner_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "ii",
        $tenantId,
        $homeownerId
    );

    $stmt->execute();

    $tenant = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if (
        $tenant &&
        ($tenant['status'] ?? '') === 'active' &&
        !empty($tenant['can_parking'])
    ) {

        $stmt = $conn->prepare("
            SELECT id, status
            FROM homeowners
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "i",
            $homeownerId
        );

        $stmt->execute();

        $homeowner = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if (
            $homeowner &&
            ($homeowner['status'] ?? '') === 'approved'
        ) {
            $hasValidSession = true;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Load permit
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| This page DOES NOT:
| - mark payments as paid
| - activate permits
| - assign permit numbers
|
| PayMongo verification/webhook must do that.
|
*/

$permit = null;

if (
    $hasValidSession &&
    $permitId > 0
) {

    $stmt = $conn->prepare("
        SELECT
            id,
            permit_no,
            homeowner_id,
            phase,
            status,
            payment_status,
            payment_method,
            permit_duration
        FROM parking_permits
        WHERE id = ?
          AND homeowner_id = ?
          AND payment_method = 'online'
        LIMIT 1
    ");

    $stmt->bind_param(
        "ii",
        $permitId,
        $homeownerId
    );

    $stmt->execute();

    $permit = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| Current verified database state
|--------------------------------------------------------------------------
*/

$permitStatus = strtolower(
    trim(
        (string)($permit['status'] ?? '')
    )
);

$paymentStatus = strtolower(
    trim(
        (string)($permit['payment_status'] ?? '')
    )
);


$paymentConfirmed =
    $permit !== null &&
    $paymentStatus === 'paid' &&
    $permitStatus === 'active';

$isRejected = $permitStatus === 'rejected';
$isRevoked  = $permitStatus === 'revoked';
$isExpired  = $permitStatus === 'expired';

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Payment Status</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>

<body class="bg-light">

<div class="container py-5">

    <div
        class="mx-auto bg-white shadow rounded-4 p-4 p-md-5"
        style="max-width:680px;"
    >

        <div class="text-center">

            <?php if (!$hasValidSession): ?>

                <h2 class="fw-bold text-primary mb-3">
                    Payment Return Received
                </h2>

                <p class="mb-3">
                    You have returned from the payment page.
                </p>

                <div class="alert alert-info text-start">
                    For security, your payment status cannot
                    be displayed because your login session
                    is not available in this browser.
                </div>

                <p class="text-muted">
                    Please log in again and check your
                    Parking Overview.
                </p>

                <div class="d-grid gap-2 mt-4">

                    <a
                        href="../index.php"
                        class="btn btn-success btn-lg"
                    >
                        Login Again
                    </a>

                </div>


            <?php elseif ($permitId <= 0 || !$permit): ?>

                <h2 class="fw-bold text-danger mb-3">
                    Unable to Verify Permit
                </h2>

                <div class="alert alert-danger">
                    This payment return could not be matched
                    to a parking permit belonging to your account.
                </div>

                <div class="d-grid gap-2 mt-4">

                    <a
                        href="homeowner_parking.php"
                        class="btn btn-success"
                    >
                        Go to Parking Overview
                    </a>

                </div>


            <?php elseif ($paymentConfirmed): ?>

                <h2 class="fw-bold text-success mb-3">
                    Payment Confirmed
                </h2>

                <p class="mb-3">
                    Your parking permit payment has been
                    verified and your permit is active.
                </p>

                <?php if (!empty($permit['permit_no'])): ?>

                    <div class="alert alert-success">

                        Permit No.:

                        <strong>
                            <?= esc($permit['permit_no']) ?>
                        </strong>

                    </div>

                <?php endif; ?>

                <div class="d-grid gap-2 mt-4">

                    <a
                        href="homeowner_parking.php"
                        class="btn btn-success btn-lg"
                    >
                        Go to Parking Overview
                    </a>

                </div>


            <?php elseif ($isRejected): ?>

                <h2 class="fw-bold text-danger mb-3">
                    Permit Rejected
                </h2>

                <p>
                    This parking permit request has been rejected.
                </p>

                <div class="d-grid gap-2 mt-4">

                    <a
                        href="homeowner_parking.php"
                        class="btn btn-outline-secondary"
                    >
                        Go to Parking Overview
                    </a>

                </div>


            <?php elseif ($isRevoked): ?>

                <h2 class="fw-bold text-danger mb-3">
                    Permit Revoked
                </h2>

                <p>
                    This parking permit has been revoked.
                </p>

                <div class="d-grid gap-2 mt-4">

                    <a
                        href="homeowner_parking.php"
                        class="btn btn-outline-secondary"
                    >
                        Go to Parking Overview
                    </a>

                </div>


            <?php elseif ($isExpired): ?>

                <h2 class="fw-bold text-warning mb-3">
                    Permit Expired
                </h2>

                <p>
                    This parking permit has already expired.
                </p>

                <div class="d-grid gap-2 mt-4">

                    <a
                        href="homeowner_parking.php"
                        class="btn btn-outline-secondary"
                    >
                        Go to Parking Overview
                    </a>

                </div>


            <?php else: ?>

                <h2 class="fw-bold text-warning mb-3">
                    Payment Verification Pending
                </h2>

                <p class="mb-3">
                    You returned from the payment page,
                    but the system has not yet received
                    verified payment confirmation.
                </p>

                <div class="alert alert-warning text-start">

                    <strong>
                        Do not submit another payment immediately.
                    </strong>

                    <br><br>

                    Your permit will only become paid and active
                    after PayMongo sends verified confirmation.

                </div>

                <div class="d-grid gap-2 mt-4">

                    <a
                        href="homeowner_parking.php"
                        class="btn btn-success"
                    >
                        Check Parking Overview
                    </a>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

</body>
</html>