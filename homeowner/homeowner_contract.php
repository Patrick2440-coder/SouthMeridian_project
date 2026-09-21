<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
    header("Location: ../index.php");
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);



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

    if ((int)($user['must_change_password'] ?? 0) === 1) {
        header("Location: homeowner_dashboard.php");
        exit;
    }
}

$permitId    = (int)($_GET['permit_id'] ?? $_GET['id'] ?? 0);
$downloadPdf = (isset($_GET['download']) && $_GET['download'] === 'pdf');

if ($permitId <= 0) {
    http_response_code(400);
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
    http_response_code(404);
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
        http_response_code(503);
        die("PDF download is not available yet because Dompdf is not installed.");
    }

    require_once $autoloadPath;

    if (!class_exists('Dompdf\Dompdf')) {
        die("Dompdf library not found.");
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
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

/* =========================
   BROWSER VIEW VARIABLES
   ========================= */
$phase =
    (string)($user['phase'] ?? '');

$houseLot =
    (string)($user['house_lot_number'] ?? '');

$fullName =
    $isTenant
        ? trim(
            ($tenant['first_name'] ?? '') .
            ' ' .
            ($tenant['last_name'] ?? '')
        )
        : trim(
            ($user['first_name'] ?? '') .
            ' ' .
            ($user['last_name'] ?? '')
        );

$pageTitle =
    "Parking Contract • South Meridian Homes";

$activePage =
    'homeowner_parking_permit.php';

$parkingPages = [
    'homeowner_parking.php',
    'homeowner_parking_permit.php',
    'homeowner_parking_violations.php'
];

$parkingOpen = true;
$tenantOpen  = false;
$chatOpen    = false;

$permitStatusKey =
    strtolower(
        trim(
            (string)($permit['status'] ?? 'pending')
        )
    );

$permitStatusClass =
    match ($permitStatusKey) {
        'active', 'approved' =>
            'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',

        'rejected', 'revoked', 'denied' =>
            'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',

        'expired' =>
            'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',

        default =>
            'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900',
    };

$paymentStatusKey =
    strtolower(
        trim(
            (string)($permit['payment_status'] ?? 'pending')
        )
    );

$paymentStatusClass =
    match ($paymentStatusKey) {
        'paid' =>
            'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',

        'unpaid', 'not paid' =>
            'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',

        default =>
            'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900',
    };

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= esc($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<script>
(function () {
    const savedTheme = localStorage.getItem('hoa-theme');
    const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.classList.toggle(
        'dark',
        savedTheme === 'dark' || (!savedTheme && systemDark)
    );
})();
</script>

<style type="text/tailwindcss">
    @custom-variant dark (&:where(.dark, .dark *));
</style>

<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css"
>

<style>
@media print {
    #sidebar,
    #sidebarOverlay,
    .no-print,
    header,
    footer {
        display: none !important;
    }

    html,
    body {
        background: #fff !important;
    }

    .main-shell {
        margin-left: 0 !important;
    }

    .print-page {
        max-width: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    #printContract {
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
        color: #111827 !important;
        padding: 0 !important;
    }

    #printContract * {
        color: #111827 !important;
    }

    #printContract .print-muted {
        color: #4b5563 !important;
    }

    #printContract .print-label {
        background: #f3f4f6 !important;
    }

    #printContract table,
    #printContract tr,
    #printContract td {
        page-break-inside: avoid;
    }

    #printContract .signature-area {
        page-break-inside: avoid;
    }
}
</style>
</head>

<body
    class="
        bg-slate-50
        text-slate-900
        antialiased
        transition-colors
        duration-200

        dark:bg-slate-950
        dark:text-slate-100
    "
>

<div
    id="sidebarOverlay"
    class="
        fixed inset-0 z-50
        hidden
        bg-slate-950/50
        backdrop-blur-[1px]
        lg:hidden
    "
></div>

<?php include 'homeowner_sidebar.php'; ?>

<div class="main-shell min-h-screen lg:ml-[280px]">

    <!-- =====================================================
         TOP BAR
         ===================================================== -->

    <header
        class="
            sticky top-0 z-40

            border-b
            border-slate-200

            bg-white/95
            backdrop-blur

            dark:border-slate-800
            dark:bg-slate-900/95
        "
    >
        <div
            class="
                mx-auto
                flex
                min-h-[72px]
                max-w-7xl
                items-center
                gap-3

                px-4
                sm:px-6
            "
        >

            <button
                type="button"
                id="sidebarToggle"
                class="
                    flex
                    h-12 w-12
                    shrink-0
                    items-center
                    justify-center

                    rounded-xl
                    border
                    border-slate-200
                    bg-white

                    text-2xl
                    text-slate-700

                    shadow-sm
                    transition

                    hover:bg-slate-50

                    focus:outline-none
                    focus:ring-4
                    focus:ring-emerald-100

                    dark:border-slate-700
                    dark:bg-slate-800
                    dark:text-slate-200
                    dark:hover:bg-slate-700
                    dark:focus:ring-emerald-950

                    lg:hidden
                "
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <a
                href="homeowner_dashboard.php"
                class="min-w-0"
            >
                <div
                    class="
                        truncate
                        text-base
                        font-bold
                        text-emerald-800

                        sm:text-lg

                        dark:text-emerald-300
                    "
                >
                    HOA Community
                </div>

                <div
                    class="
                        hidden
                        text-xs
                        font-medium
                        text-slate-500

                        sm:block

                        dark:text-slate-400
                    "
                >
                    South Meridian Homes Salitran
                </div>
            </a>

            <div
                class="
                    ml-auto
                    flex
                    items-center
                    gap-2
                    sm:gap-3
                "
            >

                <button
                    type="button"
                    id="themeToggle"
                    class="
                        flex
                        h-12 w-12
                        shrink-0
                        items-center
                        justify-center

                        rounded-xl
                        border
                        border-slate-200
                        bg-white

                        text-xl
                        text-slate-700

                        shadow-sm
                        transition

                        hover:border-emerald-200
                        hover:bg-emerald-50
                        hover:text-emerald-800

                        focus:outline-none
                        focus:ring-4
                        focus:ring-emerald-100

                        dark:border-slate-700
                        dark:bg-slate-900
                        dark:text-slate-200
                        dark:hover:bg-slate-800
                        dark:hover:text-emerald-300
                        dark:focus:ring-emerald-950
                    "
                    aria-label="Switch to dark mode"
                    title="Switch to dark mode"
                >
                    <i
                        id="themeIcon"
                        class="bi bi-moon-stars-fill"
                    ></i>
                </button>

                <div
                    class="
                        hidden
                        text-right
                        md:block
                    "
                >
                    <div
                        class="
                            max-w-[220px]
                            truncate
                            text-sm
                            font-bold
                            text-slate-800
                            dark:text-slate-200
                        "
                    >
                        <?= esc($fullName) ?>
                    </div>

                    <div
                        class="
                            text-xs
                            font-medium
                            text-slate-500
                            dark:text-slate-400
                        "
                    >
                        <?= esc($phase) ?>
                        <?= $isTenant ? ' • Tenant' : '' ?>
                    </div>
                </div>

                <a
                    href="logout.php"
                    class="
                        flex
                        min-h-12
                        items-center
                        justify-center
                        gap-2

                        rounded-xl
                        border
                        border-slate-200
                        bg-white

                        px-3

                        text-sm
                        font-semibold
                        text-slate-700

                        transition

                        hover:border-red-200
                        hover:bg-red-50
                        hover:text-red-700

                        sm:px-4

                        dark:border-slate-700
                        dark:bg-slate-900
                        dark:text-slate-300
                        dark:hover:border-red-900
                        dark:hover:bg-red-950/40
                        dark:hover:text-red-300
                    "
                >
                    <i class="bi bi-box-arrow-right text-lg"></i>
                    <span class="hidden sm:inline">Logout</span>
                </a>

            </div>
        </div>
    </header>


    <!-- =====================================================
         PAGE
         ===================================================== -->

    <main
        class="
            print-page
            mx-auto
            max-w-6xl

            px-4
            py-6

            sm:px-6
            sm:py-8
        "
    >

        <!-- Toolbar / title -->
        <section
            class="
                no-print
                mb-6
            "
        >

            <a
                href="homeowner_parking.php"
                class="
                    inline-flex
                    items-center
                    gap-2

                    text-sm
                    font-semibold
                    text-emerald-700

                    hover:text-emerald-800

                    dark:text-emerald-400
                    dark:hover:text-emerald-300
                "
            >
                <i class="bi bi-arrow-left"></i>
                Parking
            </a>

            <div
                class="
                    mt-3
                    flex
                    flex-col
                    gap-4

                    lg:flex-row
                    lg:items-end
                    lg:justify-between
                "
            >

                <div>

                    <p
                        class="
                            text-sm
                            font-semibold
                            text-emerald-700
                            dark:text-emerald-400
                        "
                    >
                        Parking Permit
                    </p>

                    <h1
                        class="
                            mt-1
                            text-2xl
                            font-bold
                            tracking-tight
                            text-slate-900

                            sm:text-3xl

                            dark:text-slate-100
                        "
                    >
                        Parking Contract
                    </h1>

                    <p
                        class="
                            mt-2
                            text-[15px]
                            leading-6
                            text-slate-600
                            dark:text-slate-400
                        "
                    >
                        Permit
                        <strong class="text-slate-800 dark:text-slate-200">
                            <?= esc($permitNo) ?>
                        </strong>
                    </p>

                </div>


                <div
                    class="
                        grid
                        grid-cols-1
                        gap-2

                        sm:grid-cols-3
                    "
                >

                    <a
                        href="homeowner_parking.php"
                        class="
                            inline-flex
                            min-h-11
                            items-center
                            justify-center
                            gap-2

                            rounded-xl
                            border
                            border-slate-300
                            bg-white
                            px-4

                            text-sm
                            font-semibold
                            text-slate-700

                            transition

                            hover:bg-slate-100

                            dark:border-slate-700
                            dark:bg-slate-900
                            dark:text-slate-200
                            dark:hover:bg-slate-800
                        "
                    >
                        <i class="bi bi-arrow-left"></i>
                        Back
                    </a>

                    <a
                        href="homeowner_contract.php?permit_id=<?= (int)$permitId ?>&download=pdf"
                        class="
                            inline-flex
                            min-h-11
                            items-center
                            justify-center
                            gap-2

                            rounded-xl
                            bg-emerald-700
                            px-4

                            text-sm
                            font-semibold
                            text-white

                            shadow-sm
                            transition

                            hover:bg-emerald-800

                            dark:bg-emerald-600
                            dark:hover:bg-emerald-500
                        "
                    >
                        <i class="bi bi-file-earmark-pdf-fill"></i>
                        Download PDF
                    </a>

                    <button
                        type="button"
                        id="printContractBtn"
                        class="
                            inline-flex
                            min-h-11
                            items-center
                            justify-center
                            gap-2

                            rounded-xl
                            bg-blue-700
                            px-4

                            text-sm
                            font-semibold
                            text-white

                            shadow-sm
                            transition

                            hover:bg-blue-800

                            dark:bg-blue-600
                            dark:hover:bg-blue-500
                        "
                    >
                        <i class="bi bi-printer-fill"></i>
                        Print
                    </button>

                </div>

            </div>

        </section>


        <!-- Status summary -->
        <section
            class="
                no-print
                mb-6
                grid
                gap-4
                sm:grid-cols-2
                lg:grid-cols-4
            "
        >

            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4
                    shadow-sm

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <p
                    class="
                        text-xs
                        font-bold
                        uppercase
                        tracking-wide
                        text-slate-400
                        dark:text-slate-500
                    "
                >
                    Permit Number
                </p>

                <p
                    class="
                        mt-2
                        break-words
                        font-bold
                        text-slate-900
                        dark:text-slate-100
                    "
                >
                    <?= esc($permitNo) ?>
                </p>
            </div>


            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4
                    shadow-sm

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <p
                    class="
                        text-xs
                        font-bold
                        uppercase
                        tracking-wide
                        text-slate-400
                        dark:text-slate-500
                    "
                >
                    Permit Status
                </p>

                <span
                    class="
                        mt-2
                        inline-flex
                        items-center
                        rounded-lg
                        px-2.5
                        py-1.5
                        text-xs
                        font-bold
                        ring-1

                        <?= $permitStatusClass ?>
                    "
                >
                    <?= esc($status) ?>
                </span>
            </div>


            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4
                    shadow-sm

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <p
                    class="
                        text-xs
                        font-bold
                        uppercase
                        tracking-wide
                        text-slate-400
                        dark:text-slate-500
                    "
                >
                    Payment
                </p>

                <span
                    class="
                        mt-2
                        inline-flex
                        items-center
                        rounded-lg
                        px-2.5
                        py-1.5
                        text-xs
                        font-bold
                        ring-1

                        <?= $paymentStatusClass ?>
                    "
                >
                    <?= esc($paymentStatus) ?>
                </span>
            </div>


            <div
                class="
                    rounded-2xl
                    border
                    border-slate-200
                    bg-white
                    p-4
                    shadow-sm

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <p
                    class="
                        text-xs
                        font-bold
                        uppercase
                        tracking-wide
                        text-slate-400
                        dark:text-slate-500
                    "
                >
                    Valid Until
                </p>

                <p
                    class="
                        mt-2
                        font-bold
                        text-slate-900
                        dark:text-slate-100
                    "
                >
                    <?= esc($validUntilFormatted) ?>
                </p>
            </div>

        </section>


        <!-- =================================================
             PRINTABLE CONTRACT
             ================================================= -->

        <article
            id="printContract"
            class="
                rounded-2xl
                border
                border-slate-200
                bg-white

                p-5
                shadow-sm

                sm:p-8
                lg:p-10

                dark:border-slate-800
                dark:bg-slate-900
            "
        >

            <header
                class="
                    border-b
                    border-slate-200
                    pb-6
                    text-center

                    dark:border-slate-700
                "
            >
                <div
                    class="
                        mx-auto
                        flex
                        h-14 w-14
                        items-center
                        justify-center

                        rounded-2xl
                        bg-emerald-100

                        text-2xl
                        text-emerald-700

                        dark:bg-emerald-950/60
                        dark:text-emerald-300
                    "
                >
                    <i class="bi bi-car-front-fill"></i>
                </div>

                <h1
                    class="
                        mt-4
                        text-2xl
                        font-bold
                        text-slate-900

                        sm:text-3xl

                        dark:text-slate-100
                    "
                >
                    South Meridian Homes
                </h1>

                <p
                    class="
                        print-muted
                        mt-1
                        text-sm
                        font-medium
                        text-slate-500

                        dark:text-slate-400
                    "
                >
                    Parking Permit / Sticker Contract
                </p>
            </header>


            <div
                class="
                    mt-6
                    grid
                    gap-3
                    sm:grid-cols-2
                "
            >
                <div
                    class="
                        rounded-xl
                        bg-slate-50
                        p-4
                        ring-1
                        ring-slate-200

                        dark:bg-slate-800/50
                        dark:ring-slate-700
                    "
                >
                    <p
                        class="
                            print-muted
                            text-xs
                            font-bold
                            uppercase
                            tracking-wide
                            text-slate-400

                            dark:text-slate-500
                        "
                    >
                        Permit No.
                    </p>

                    <p
                        class="
                            mt-1
                            break-words
                            font-bold
                            text-slate-900

                            dark:text-slate-100
                        "
                    >
                        <?= esc($permitNo) ?>
                    </p>
                </div>

                <div
                    class="
                        rounded-xl
                        bg-slate-50
                        p-4
                        ring-1
                        ring-slate-200

                        dark:bg-slate-800/50
                        dark:ring-slate-700
                    "
                >
                    <p
                        class="
                            print-muted
                            text-xs
                            font-bold
                            uppercase
                            tracking-wide
                            text-slate-400

                            dark:text-slate-500
                        "
                    >
                        Issued Date
                    </p>

                    <p
                        class="
                            mt-1
                            font-bold
                            text-slate-900

                            dark:text-slate-100
                        "
                    >
                        <?= esc($issuedDate) ?>
                    </p>
                </div>
            </div>


            <!-- Homeowner -->
            <section class="mt-8">

                <h2
                    class="
                        border-b
                        border-slate-200
                        pb-2

                        text-base
                        font-bold
                        text-slate-900

                        dark:border-slate-700
                        dark:text-slate-100
                    "
                >
                    Homeowner Information
                </h2>

                <div
                    class="
                        mt-3
                        overflow-hidden
                        rounded-xl
                        border
                        border-slate-200

                        dark:border-slate-700
                    "
                >
                    <table class="w-full border-collapse text-sm">
                        <tbody
                            class="
                                divide-y
                                divide-slate-200
                                dark:divide-slate-700
                            "
                        >
                            <tr>
                                <td
                                    class="
                                        print-label
                                        w-[34%]
                                        bg-slate-50
                                        px-4
                                        py-3
                                        font-bold
                                        text-slate-700

                                        dark:bg-slate-800
                                        dark:text-slate-300
                                    "
                                >
                                    Homeowner Name
                                </td>

                                <td
                                    class="
                                        px-4
                                        py-3
                                        text-slate-800
                                        dark:text-slate-200
                                    "
                                >
                                    <?= esc($homeownerName) ?>
                                </td>
                            </tr>

                            <tr>
                                <td
                                    class="
                                        print-label
                                        bg-slate-50
                                        px-4
                                        py-3
                                        font-bold
                                        text-slate-700

                                        dark:bg-slate-800
                                        dark:text-slate-300
                                    "
                                >
                                    Contact Number
                                </td>

                                <td
                                    class="
                                        px-4
                                        py-3
                                        text-slate-800
                                        dark:text-slate-200
                                    "
                                >
                                    <?= esc($permit['contact_number'] ?? 'N/A') ?>
                                </td>
                            </tr>

                            <tr>
                                <td
                                    class="
                                        print-label
                                        bg-slate-50
                                        px-4
                                        py-3
                                        font-bold
                                        text-slate-700

                                        dark:bg-slate-800
                                        dark:text-slate-300
                                    "
                                >
                                    Address
                                </td>

                                <td
                                    class="
                                        break-words
                                        px-4
                                        py-3
                                        text-slate-800
                                        dark:text-slate-200
                                    "
                                >
                                    <?= esc($fullAddress) ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </section>


            <!-- Permit -->
            <section class="mt-8">

                <h2
                    class="
                        border-b
                        border-slate-200
                        pb-2

                        text-base
                        font-bold
                        text-slate-900

                        dark:border-slate-700
                        dark:text-slate-100
                    "
                >
                    Permit Information
                </h2>

                <?php
                $permitDetails = [
                    ['Plate No.', $plateNo],
                    ['Vehicle Type', $vehicleType],
                    ['Vehicle Make', $vehicleMake],
                    ['Vehicle Model', $vehicleModel],
                    ['Vehicle Color', $vehicleColor],
                    ['Permit Duration', $permitDuration],
                    ['Payment Method', $paymentMethod],
                    ['Payment Status', $paymentStatus],
                    ['Sticker Year', $stickerYear],
                    ['Validity Start', $validFromFormatted],
                    ['Validity End', $validUntilFormatted],
                    ['Status', $status],
                ];
                ?>

                <div
                    class="
                        mt-3
                        overflow-hidden
                        rounded-xl
                        border
                        border-slate-200

                        dark:border-slate-700
                    "
                >
                    <table class="w-full border-collapse text-sm">
                        <tbody
                            class="
                                divide-y
                                divide-slate-200
                                dark:divide-slate-700
                            "
                        >
                            <?php foreach ($permitDetails as [$label, $value]): ?>

                                <tr>
                                    <td
                                        class="
                                            print-label
                                            w-[34%]
                                            bg-slate-50
                                            px-4
                                            py-3
                                            font-bold
                                            text-slate-700

                                            dark:bg-slate-800
                                            dark:text-slate-300
                                        "
                                    >
                                        <?= esc($label) ?>
                                    </td>

                                    <td
                                        class="
                                            break-words
                                            px-4
                                            py-3
                                            text-slate-800
                                            dark:text-slate-200
                                        "
                                    >
                                        <?= esc($value) ?>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </section>


            <!-- Terms -->
            <section class="mt-8">

                <h2
                    class="
                        border-b
                        border-slate-200
                        pb-2

                        text-base
                        font-bold
                        text-slate-900

                        dark:border-slate-700
                        dark:text-slate-100
                    "
                >
                    Terms and Conditions
                </h2>

                <ol
                    class="
                        mt-4
                        list-decimal
                        space-y-3
                        pl-5

                        text-sm
                        leading-6
                        text-slate-700

                        dark:text-slate-300
                    "
                >
                    <li>
                        This parking permit/sticker is issued only for the
                        approved homeowner and vehicle listed in this contract.
                    </li>

                    <li>
                        The permit/sticker is strictly non-transferable and may
                        not be used for any other vehicle unless formally
                        approved by management.
                    </li>

                    <li>
                        The homeowner agrees to comply with all subdivision
                        parking, traffic, and security rules at all times.
                    </li>

                    <li>
                        The permit/sticker must be presented or displayed
                        whenever required by subdivision management or security
                        personnel.
                    </li>

                    <li>
                        Any misuse, falsification, unauthorized transfer, or
                        violation of community parking rules may result in
                        penalties, suspension, or revocation of the permit.
                    </li>

                    <li>
                        Approval or issuance of the permit does not exempt the
                        homeowner from penalties related to parking violations
                        or other subdivision rule violations.
                    </li>

                    <li>
                        Renewal remains subject to management approval, complete
                        requirements, and payment of applicable fees or
                        penalties.
                    </li>

                    <li>
                        South Meridian Homes reserves the right to update
                        parking policies when necessary for safety, security,
                        and community order.
                    </li>
                </ol>

            </section>


            <!-- Agreement -->
            <section class="mt-8">

                <h2
                    class="
                        border-b
                        border-slate-200
                        pb-2

                        text-base
                        font-bold
                        text-slate-900

                        dark:border-slate-700
                        dark:text-slate-100
                    "
                >
                    Agreement
                </h2>

                <p
                    class="
                        mt-4
                        text-sm
                        leading-7
                        text-slate-700

                        dark:text-slate-300
                    "
                >
                    By accepting and using this parking permit/sticker, the
                    homeowner confirms that all submitted information is true
                    and correct, and agrees to follow the terms and conditions
                    of the South Meridian Homes parking policy.
                </p>

            </section>


            <!-- Signatures -->
            <section
                class="
                    signature-area
                    mt-14

                    grid
                    gap-12

                    sm:grid-cols-2
                    sm:gap-10
                "
            >
                <div class="pt-8 text-center">
                    <div
                        class="
                            mx-auto
                            w-4/5
                            border-t
                            border-slate-700

                            dark:border-slate-300
                        "
                    ></div>

                    <p
                        class="
                            mt-2
                            text-sm
                            font-medium
                            text-slate-700

                            dark:text-slate-300
                        "
                    >
                        Homeowner Signature
                    </p>
                </div>

                <div class="pt-8 text-center">
                    <div
                        class="
                            mx-auto
                            w-4/5
                            border-t
                            border-slate-700

                            dark:border-slate-300
                        "
                    ></div>

                    <p
                        class="
                            mt-2
                            text-sm
                            font-medium
                            text-slate-700

                            dark:text-slate-300
                        "
                    >
                        Authorized Representative
                    </p>
                </div>
            </section>


            <p
                class="
                    print-muted
                    mt-10
                    border-t
                    border-slate-200
                    pt-4

                    text-center
                    text-xs
                    leading-5
                    text-slate-400

                    dark:border-slate-700
                    dark:text-slate-500
                "
            >
                This contract was generated electronically by the
                South Meridian Homes Parking Permit System.
            </p>

        </article>


        <footer
            class="
                no-print
                mt-8
                border-t
                border-slate-200
                py-6

                text-center
                text-sm
                text-slate-500

                dark:border-slate-800
                dark:text-slate-500
            "
        >
            © South Meridian Homes Salitran
        </footer>

    </main>

</div>


<script>
/*
|--------------------------------------------------------------------------
| Print
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'printContractBtn'
    )
    ?.addEventListener(
        'click',
        function () {
            window.print();
        }
    );


/*
|--------------------------------------------------------------------------
| Sidebar dropdowns
|--------------------------------------------------------------------------
*/

function initSidebarDropdown(
    buttonId,
    menuId,
    caretId
) {
    const button =
        document.getElementById(
            buttonId
        );

    const menu =
        document.getElementById(
            menuId
        );

    const caret =
        document.getElementById(
            caretId
        );

    if (!button || !menu) {
        return;
    }

    button.addEventListener(
        'click',
        function () {

            const willOpen =
                menu.classList.contains(
                    'hidden'
                );

            menu.classList.toggle(
                'hidden'
            );

            button.setAttribute(
                'aria-expanded',
                willOpen
                    ? 'true'
                    : 'false'
            );

            if (caret) {
                caret.classList.toggle(
                    'rotate-180',
                    willOpen
                );
            }
        }
    );
}


initSidebarDropdown(
    'sbParkingToggle',
    'sbParkingMenu',
    'sbParkingCaret'
);

initSidebarDropdown(
    'sbTenantToggle',
    'sbTenantMenu',
    'sbTenantCaret'
);


/*
|--------------------------------------------------------------------------
| Mobile sidebar
|--------------------------------------------------------------------------
*/

(function () {

    const sidebar =
        document.getElementById(
            'sidebar'
        );

    const overlay =
        document.getElementById(
            'sidebarOverlay'
        );

    const openButton =
        document.getElementById(
            'sidebarToggle'
        );

    const closeButton =
        document.getElementById(
            'sidebarClose'
        );

    if (
        !sidebar ||
        !overlay ||
        !openButton
    ) {
        return;
    }

    function openSidebar() {
        sidebar.classList.remove(
            '-translate-x-full'
        );

        overlay.classList.remove(
            'hidden'
        );

        document.body.classList.add(
            'overflow-hidden'
        );
    }

    function closeSidebar() {
        sidebar.classList.add(
            '-translate-x-full'
        );

        overlay.classList.add(
            'hidden'
        );

        document.body.classList.remove(
            'overflow-hidden'
        );
    }

    openButton.addEventListener(
        'click',
        openSidebar
    );

    closeButton?.addEventListener(
        'click',
        closeSidebar
    );

    overlay.addEventListener(
        'click',
        closeSidebar
    );

    sidebar
        .querySelectorAll('a')
        .forEach(
            function (link) {

                link.addEventListener(
                    'click',
                    function () {

                        if (
                            window.innerWidth <
                            1024
                        ) {
                            closeSidebar();
                        }
                    }
                );
            }
        );

    window.addEventListener(
        'resize',
        function () {

            if (
                window.innerWidth >=
                1024
            ) {
                overlay.classList.add(
                    'hidden'
                );

                document.body.classList.remove(
                    'overflow-hidden'
                );
            }
        }
    );

})();


/*
|--------------------------------------------------------------------------
| Theme
|--------------------------------------------------------------------------
*/

(function () {

    const toggle =
        document.getElementById(
            'themeToggle'
        );

    const icon =
        document.getElementById(
            'themeIcon'
        );

    if (!toggle || !icon) {
        return;
    }

    function updateThemeIcon() {

        const dark =
            document.documentElement
                .classList
                .contains('dark');

        icon.className =
            dark
                ? 'bi bi-sun-fill'
                : 'bi bi-moon-stars-fill';

        toggle.setAttribute(
            'aria-label',
            dark
                ? 'Switch to light mode'
                : 'Switch to dark mode'
        );

        toggle.setAttribute(
            'title',
            dark
                ? 'Switch to light mode'
                : 'Switch to dark mode'
        );
    }

    toggle.addEventListener(
        'click',
        function () {

            const dark =
                document.documentElement
                    .classList
                    .toggle('dark');

            localStorage.setItem(
                'hoa-theme',
                dark
                    ? 'dark'
                    : 'light'
            );

            updateThemeIcon();
        }
    );

    updateThemeIcon();

})();
</script>

</body>
</html>