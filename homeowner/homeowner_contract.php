<?php
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
    header("Location: ../index.php");
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
$conn->set_charset("utf8mb4");

require_once 'tenant_module_guard.php';

function esc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDateValue($date) {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return 'N/A';
    }

    $ts = strtotime($date);
    return $ts ? date('F d, Y', $ts) : 'N/A';
}

function durationLabel($duration) {
    $map = [
        '1_month'  => '1 Month',
        '3_months' => '3 Months',
        '6_months' => '6 Months',
        '1_year'   => '1 Year',
    ];
    return $map[$duration] ?? $duration;
}

function paymentMethodLabel($method) {
    $map = [
        'online' => 'Online Payment',
        'cash'   => 'Cash / Physical Payment',
    ];
    return $map[$method] ?? ucfirst((string)$method);
}

function paymentStatusLabel($status) {
    $status = strtolower(trim((string)$status));
    if ($status === 'unpaid' || $status === 'not paid') return 'Not Paid';
    if ($status === 'paid') return 'Paid';
    if ($status === 'pending') return 'Pending';
    if ($status === 'for payment') return 'For Payment';
    return ucfirst($status);
}

function vehicleTypeLabel($type) {
    $map = [
        'car'        => 'Car',
        'motorcycle' => 'Motorcycle',
        'ebike'      => 'E-Bike',
    ];
    return $map[strtolower((string)$type)] ?? ucfirst((string)$type);
}

$isTenant = ($_SESSION['role'] === 'tenant');
$tenant = null;
$user = null;
$homeownerId = 0;

if ($isTenant) {
    if (empty($_SESSION['tenant_id']) || empty($_SESSION['tenant_homeowner_id'])) {
        header("Location: ../index.php");
        exit;
    }

    $tenantId = (int)$_SESSION['tenant_id'];
    $homeownerId = (int)$_SESSION['tenant_homeowner_id'];

    $stmt = $conn->prepare("
        SELECT id, homeowner_id, first_name, last_name, email, status, phase,
               can_pay_dues, can_rent, can_parking, can_announcements
        FROM tenants
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $tenantId);
    $stmt->execute();
    $tenant = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tenant || $tenant['status'] !== 'active') {
        session_destroy();
        header("Location: ../index.php");
        exit;
    }

    $stmt = $conn->prepare("
        SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number
        FROM homeowners
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $homeownerId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || $user['status'] !== 'approved') {
        session_destroy();
        header("Location: ../index.php");
        exit;
    }

    tenant_guard('parking', $tenant);
} else {
    if (empty($_SESSION['homeowner_id'])) {
        header("Location: ../index.php");
        exit;
    }

    $homeownerId = (int)$_SESSION['homeowner_id'];

    $stmt = $conn->prepare("
        SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number
        FROM homeowners
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $homeownerId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || $user['status'] !== 'approved') {
        session_destroy();
        header("Location: ../index.php");
        exit;
    }
}

$permitId    = (int)($_GET['permit_id'] ?? $_GET['id'] ?? 0);
$downloadPdf = (isset($_GET['download']) && $_GET['download'] === 'pdf');

if ($permitId <= 0) {
    die("Invalid permit ID.");
}

$stmt = $conn->prepare("
    SELECT 
        pp.*,
        h.first_name,
        h.middle_name,
        h.last_name,
        h.contact_number,
        h.house_lot_number,
        h.barangay,
        h.city_municipality,
        h.province,
        h.region,
        h.zip_code,
        h.country,
        h.other_location_info,
        h.exact_location
    FROM parking_permits pp
    INNER JOIN homeowners h ON h.id = pp.homeowner_id
    WHERE pp.id = ?
      AND pp.homeowner_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $permitId, $homeownerId);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$permit) {
    die("Permit not found or access denied.");
}

$homeownerName = trim(
    ($permit['first_name'] ?? '') . ' ' .
    ($permit['middle_name'] ?? '') . ' ' .
    ($permit['last_name'] ?? '')
);

$addressParts = array_filter([
    $permit['house_lot_number'] ?? '',
    $permit['other_location_info'] ?? '',
    $permit['barangay'] ?? '',
    $permit['city_municipality'] ?? '',
    $permit['province'] ?? '',
    $permit['region'] ?? '',
    $permit['zip_code'] ?? '',
    $permit['country'] ?? ''
], function($v) {
    return trim((string)$v) !== '';
});

$fullAddress = !empty($addressParts) ? implode(', ', $addressParts) : 'N/A';

$permitNo       = !empty($permit['permit_no']) ? $permit['permit_no'] : ('P' . $permit['id']);
$plateNo        = $permit['plate_no'] ?? 'N/A';
$vehicleType    = vehicleTypeLabel((string)($permit['vehicle_type'] ?? 'car'));
$vehicleMake    = $permit['vehicle_make'] ?? 'N/A';
$vehicleModel   = $permit['vehicle_model'] ?? 'N/A';
$vehicleColor   = $permit['vehicle_color'] ?? 'N/A';
$permitDuration = durationLabel((string)($permit['permit_duration'] ?? ''));
$paymentMethod  = paymentMethodLabel((string)($permit['payment_method'] ?? ''));
$paymentStatus  = paymentStatusLabel((string)($permit['payment_status'] ?? 'Pending'));
$status         = ucfirst((string)($permit['status'] ?? 'Pending'));
$stickerYear    = $permit['sticker_year'] ?? 'N/A';

$validFrom   = $permit['valid_from'] ?? $permit['validity_start'] ?? null;
$validUntil  = $permit['valid_until'] ?? $permit['validity_end'] ?? null;
$requestedAt = $permit['requested_at'] ?? null;

$issuedDate          = formatDateValue($requestedAt);
$validFromFormatted  = formatDateValue($validFrom);
$validUntilFormatted = formatDateValue($validUntil);

$contractHtml = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Parking Permit Contract - ' . esc($permitNo) . '</title>
    <style>
        body{
            font-family: DejaVu Sans, Arial, sans-serif;
            color:#222;
            font-size:12px;
            line-height:1.6;
            margin:32px;
        }
        .header{text-align:center;margin-bottom:20px;}
        .header h1{margin:0;font-size:22px;}
        .header h2{margin:5px 0 0;font-size:14px;font-weight:normal;color:#555;}
        .section-title{
            margin-top:18px;
            margin-bottom:8px;
            font-size:13px;
            font-weight:bold;
            border-bottom:1px solid #ccc;
            padding-bottom:4px;
        }
        table.details{
            width:100%;
            border-collapse:collapse;
            margin-bottom:14px;
        }
        table.details td{
            border:1px solid #d9d9d9;
            padding:8px 10px;
            vertical-align:top;
        }
        table.details td.label{
            width:30%;
            background:#f4f6f8;
            font-weight:bold;
        }
        .terms ol{
            margin:8px 0 0 18px;
            padding:0;
        }
        .terms li{
            margin-bottom:8px;
        }
        .signatures{
            width:100%;
            margin-top:50px;
        }
        .signatures td{
            width:50%;
            text-align:center;
            padding-top:36px;
        }
        .sign-line{
            width:75%;
            margin:0 auto 6px auto;
            border-top:1px solid #222;
            height:1px;
        }
        .footer-note{
            margin-top:24px;
            font-size:10px;
            color:#555;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>South Meridian Homes</h1>
        <h2>Parking Permit / Sticker Contract</h2>
    </div>

    <div><strong>Permit No.:</strong> ' . esc($permitNo) . '</div>
    <div><strong>Issued Date:</strong> ' . esc($issuedDate) . '</div>

    <div class="section-title">Homeowner Information</div>
    <table class="details">
        <tr>
            <td class="label">Homeowner Name</td>
            <td>' . esc($homeownerName) . '</td>
        </tr>
        <tr>
            <td class="label">Contact Number</td>
            <td>' . esc($permit['contact_number'] ?? 'N/A') . '</td>
        </tr>
        <tr>
            <td class="label">Address</td>
            <td>' . esc($fullAddress) . '</td>
        </tr>
    </table>

    <div class="section-title">Permit Information</div>
    <table class="details">
        <tr><td class="label">Plate No.</td><td>' . esc($plateNo) . '</td></tr>
        <tr><td class="label">Vehicle Type</td><td>' . esc($vehicleType) . '</td></tr>
        <tr><td class="label">Vehicle Make</td><td>' . esc($vehicleMake) . '</td></tr>
        <tr><td class="label">Vehicle Model</td><td>' . esc($vehicleModel) . '</td></tr>
        <tr><td class="label">Vehicle Color</td><td>' . esc($vehicleColor) . '</td></tr>
        <tr><td class="label">Permit Duration</td><td>' . esc($permitDuration) . '</td></tr>
        <tr><td class="label">Payment Method</td><td>' . esc($paymentMethod) . '</td></tr>
        <tr><td class="label">Payment Status</td><td>' . esc($paymentStatus) . '</td></tr>
        <tr><td class="label">Sticker Year</td><td>' . esc($stickerYear) . '</td></tr>
        <tr><td class="label">Validity Start</td><td>' . esc($validFromFormatted) . '</td></tr>
        <tr><td class="label">Validity End</td><td>' . esc($validUntilFormatted) . '</td></tr>
        <tr><td class="label">Status</td><td>' . esc($status) . '</td></tr>
    </table>

    <div class="section-title">Terms and Conditions</div>
    <div class="terms">
        <ol>
            <li>This parking permit/sticker is issued only for the approved homeowner and vehicle listed in this contract.</li>
            <li>The permit/sticker is strictly non-transferable and may not be used for any other vehicle unless formally approved by management.</li>
            <li>The homeowner agrees to comply with all subdivision parking, traffic, and security rules at all times.</li>
            <li>The permit/sticker must be presented or displayed whenever required by subdivision management or security personnel.</li>
            <li>Any misuse, falsification, unauthorized transfer, or violation of community parking rules may result in penalties, suspension, or revocation of the permit.</li>
            <li>Approval or issuance of the permit does not exempt the homeowner from penalties related to parking violations or other subdivision rule violations.</li>
            <li>Renewal remains subject to management approval, complete requirements, and payment of applicable fees or penalties.</li>
            <li>South Meridian Homes reserves the right to update parking policies when necessary for safety, security, and community order.</li>
        </ol>
    </div>

    <div class="section-title">Agreement</div>
    <p>
        By accepting and using this parking permit/sticker, the homeowner confirms that all submitted information is true and correct,
        and agrees to follow the terms and conditions of the South Meridian Homes parking policy.
    </p>

    <table class="signatures">
        <tr>
            <td>
                <div class="sign-line"></div>
                Homeowner Signature
            </td>
            <td>
                <div class="sign-line"></div>
                Authorized Representative
            </td>
        </tr>
    </table>

    <div class="footer-note">
        This contract was generated electronically by the South Meridian Homes Parking Permit System.
    </div>

</body>
</html>
';

if ($downloadPdf) {
    $autoloadCandidates = [
        __DIR__ . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php'
    ];

    $autoloadPath = null;
    foreach ($autoloadCandidates as $candidate) {
        if (file_exists($candidate)) {
            $autoloadPath = $candidate;
            break;
        }
    }

    if (!$autoloadPath) {
        die("PDF download is not available yet because Dompdf is not installed.");
    }

    require_once $autoloadPath;

    if (!class_exists('Dompdf\Dompdf')) {
        die("Dompdf library not found.");
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($contractHtml);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $fileName = 'Parking_Contract_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $permitNo) . '.pdf';
    $dompdf->stream($fileName, ['Attachment' => true]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Parking Permit Contract</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{
            margin:0;
            background:#eef2f7;
            font-family:Arial, sans-serif;
            color:#1f2937;
        }
        .page-wrap{
            max-width:1000px;
            margin:30px auto;
            padding:0 16px;
        }
        .toolbar{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            justify-content:space-between;
            align-items:center;
            margin-bottom:18px;
        }
        .contract-box{
            background:#fff;
            border-radius:14px;
            box-shadow:0 10px 30px rgba(0,0,0,0.08);
            padding:38px;
        }
        .header{text-align:center;margin-bottom:24px;}
        .header h1{margin:0 0 4px 0;font-size:30px;}
        .header h2{margin:0;font-size:18px;font-weight:normal;color:#6b7280;}
        .section-title{
            font-size:17px;
            font-weight:700;
            margin:24px 0 10px;
            padding-bottom:8px;
            border-bottom:2px solid #e5e7eb;
        }
        table.details{
            width:100%;
            border-collapse:collapse;
        }
        table.details td{
            border:1px solid #d1d5db;
            padding:12px;
            vertical-align:top;
            font-size:14px;
        }
        table.details td.label{
            width:30%;
            background:#f9fafb;
            font-weight:700;
        }
        .terms{
            font-size:14px;
            line-height:1.8;
            text-align:justify;
        }
        .terms ol{
            padding-left:22px;
            margin:0;
        }
        .terms li{ margin-bottom:10px; }
        .signatures{
            width:100%;
            margin-top:60px;
        }
        .signatures td{
            width:50%;
            text-align:center;
            padding-top:40px;
            font-size:14px;
        }
        .sign-line{
            width:80%;
            margin:0 auto 8px;
            border-top:1px solid #111827;
            height:1px;
        }
        @media print{
            .toolbar{ display:none !important; }
            .page-wrap{ max-width:none; margin:0; padding:0; }
            .contract-box{ box-shadow:none; border-radius:0; padding:0; }
            body{ background:#fff; }
        }
    </style>
</head>
<body>
<div class="page-wrap">
    <div class="toolbar">
        <div>
            Parking Permit Contract for <strong><?= esc($permitNo) ?></strong>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="homeowner_parking.php" class="btn btn-secondary btn-sm">Back</a>
            <a href="homeowner_contract.php?permit_id=<?= (int)$permitId ?>&download=pdf" class="btn btn-success btn-sm">Download PDF</a>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print</button>
        </div>
    </div>

    <div class="contract-box">
        <div class="header">
            <h1>South Meridian Homes</h1>
            <h2>Parking Permit / Sticker Contract</h2>
        </div>

        <div><strong>Permit No.:</strong> <?= esc($permitNo) ?></div>
        <div><strong>Issued Date:</strong> <?= esc($issuedDate) ?></div>

        <div class="section-title">Homeowner Information</div>
        <table class="details">
            <tr>
                <td class="label">Homeowner Name</td>
                <td><?= esc($homeownerName) ?></td>
            </tr>
            <tr>
                <td class="label">Contact Number</td>
                <td><?= esc($permit['contact_number'] ?? 'N/A') ?></td>
            </tr>
            <tr>
                <td class="label">Address</td>
                <td><?= esc($fullAddress) ?></td>
            </tr>
        </table>

        <div class="section-title">Permit Information</div>
        <table class="details">
            <tr><td class="label">Plate No.</td><td><?= esc($plateNo) ?></td></tr>
            <tr><td class="label">Vehicle Type</td><td><?= esc($vehicleType) ?></td></tr>
            <tr><td class="label">Vehicle Make</td><td><?= esc($vehicleMake) ?></td></tr>
            <tr><td class="label">Vehicle Model</td><td><?= esc($vehicleModel) ?></td></tr>
            <tr><td class="label">Vehicle Color</td><td><?= esc($vehicleColor) ?></td></tr>
            <tr><td class="label">Permit Duration</td><td><?= esc($permitDuration) ?></td></tr>
            <tr><td class="label">Payment Method</td><td><?= esc($paymentMethod) ?></td></tr>
            <tr><td class="label">Payment Status</td><td><?= esc($paymentStatus) ?></td></tr>
            <tr><td class="label">Sticker Year</td><td><?= esc($stickerYear) ?></td></tr>
            <tr><td class="label">Validity Start</td><td><?= esc($validFromFormatted) ?></td></tr>
            <tr><td class="label">Validity End</td><td><?= esc($validUntilFormatted) ?></td></tr>
            <tr><td class="label">Status</td><td><?= esc($status) ?></td></tr>
        </table>

        <div class="section-title">Terms and Conditions</div>
        <div class="terms">
            <ol>
                <li>This parking permit/sticker is issued only for the approved homeowner and vehicle listed in this contract.</li>
                <li>The permit/sticker is strictly non-transferable and may not be used for any other vehicle unless formally approved by management.</li>
                <li>The homeowner agrees to comply with all subdivision parking, traffic, and security rules at all times.</li>
                <li>The permit/sticker must be presented or displayed whenever required by subdivision management or security personnel.</li>
                <li>Any misuse, falsification, unauthorized transfer, or violation of community parking rules may result in penalties, suspension, or revocation of the permit.</li>
                <li>Approval or issuance of the permit does not exempt the homeowner from penalties related to parking violations or other subdivision rule violations.</li>
                <li>Renewal remains subject to management approval, complete requirements, and payment of applicable fees or penalties.</li>
                <li>South Meridian Homes reserves the right to update parking policies when necessary for safety, security, and community order.</li>
            </ol>
        </div>

        <div class="section-title">Agreement</div>
        <p>
            By accepting and using this parking permit/sticker, the homeowner confirms that all submitted information is true and correct,
            and agrees to follow the terms and conditions of the South Meridian Homes parking policy.
        </p>

        <table class="signatures">
            <tr>
                <td>
                    <div class="sign-line"></div>
                    Homeowner Signature
                </td>
                <td>
                    <div class="sign-line"></div>
                    Authorized Representative
                </td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>