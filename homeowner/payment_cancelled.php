<?php
session_start();

$hasSession =
    (
        isset($_SESSION['role']) &&
        $_SESSION['role'] === 'homeowner' &&
        !empty($_SESSION['homeowner_id'])
    )
    ||
    (
        isset($_SESSION['role']) &&
        $_SESSION['role'] === 'tenant' &&
        !empty($_SESSION['tenant_id']) &&
        !empty($_SESSION['tenant_homeowner_id'])
    );

if ($hasSession) {
    header("Location: https://southmeridianhomes.online/homeowner/homeowner_parking.php?cancelled=1");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="mx-auto bg-white shadow rounded-4 p-4 p-md-5" style="max-width: 680px;">
            <div class="text-center">
                <h2 class="text-warning fw-bold mb-3">Payment Cancelled</h2>
                <p class="mb-4">Your online payment was cancelled or not completed.</p>

                <div class="d-grid gap-2">
                    <a href="https://southmeridianhomes.online/homeowner/homeowner_parking.php?cancelled=1" class="btn btn-warning btn-lg">
                        Go to Parking Overview
                    </a>
                    <a href="https://southmeridianhomes.online/index.php" class="btn btn-outline-secondary">
                        Login Again
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>