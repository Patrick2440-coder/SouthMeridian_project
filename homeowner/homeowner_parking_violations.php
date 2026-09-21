<?php
session_start();

require_once '../config/database.php';

if (
    !isset($_SESSION['role']) ||
    !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)
) {
    header("Location: ../index.php");
    exit;
}

require_once 'tenant_module_guard.php';


function esc($v)
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES,
        'UTF-8'
    );
}


function duration_label(string $duration): string
{
    $map = [
        '1_month'  => '1 Month',
        '3_months' => '3 Months',
        '6_months' => '6 Months',
        '1_year'   => '1 Year',
    ];

    return $map[$duration] ?? $duration;
}


function payment_label(string $payment): string
{
    $map = [
        'online' => 'Online Payment',
        'cash'   => 'Cash / Physical Payment',
    ];

    return $map[$payment] ?? $payment;
}


function vehicle_type_label(string $type): string
{
    $map = [
        'car'        => 'Car',
        'motorcycle' => 'Motorcycle',
        'ebike'      => 'E-Bike',
    ];

    return $map[$type] ?? ucfirst($type);
}


function violation_status_label(string $status): string
{
    $status =
        strtolower(
            trim($status)
        );

    return match ($status) {
        'open'    => 'Open',
        'paid'    => 'Paid',
        'cleared' => 'Cleared',
        'void'    => 'Void',
        default   => $status !== '' ? ucfirst($status) : 'Unknown',
    };
}


function violation_status_classes(string $status): string
{
    $status =
        strtolower(
            trim($status)
        );

    return match ($status) {
        'open' =>
            'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',

        'paid' =>
            'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',

        'cleared' =>
            'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900',

        'void' =>
            'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',

        default =>
            'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
    };
}


function violation_status_icon(string $status): string
{
    $status =
        strtolower(
            trim($status)
        );

    return match ($status) {
        'open'    => 'bi-exclamation-circle-fill',
        'paid'    => 'bi-check-circle-fill',
        'cleared' => 'bi-shield-check',
        'void'    => 'bi-x-circle-fill',
        default   => 'bi-info-circle-fill',
    };
}


function display_datetime(?string $value): string
{
    if (
        $value === null ||
        trim($value) === ''
    ) {
        return '—';
    }

    $ts =
        strtotime($value);

    if (!$ts) {
        return $value;
    }

    return date(
        'M d, Y h:i A',
        $ts
    );
}


/*
|--------------------------------------------------------------------------
| Account / session
|--------------------------------------------------------------------------
*/

$isTenant =
    ($_SESSION['role'] === 'tenant');

$tenant = null;
$user   = null;
$hid    = 0;


if ($isTenant) {

    if (
        empty($_SESSION['tenant_id']) ||
        empty($_SESSION['tenant_homeowner_id'])
    ) {
        header("Location: ../index.php");
        exit;
    }

    $tenant_id =
        (int)$_SESSION['tenant_id'];

    $hid =
        (int)$_SESSION['tenant_homeowner_id'];


    $stmt = $conn->prepare("
        SELECT
            id,
            homeowner_id,
            first_name,
            last_name,
            email,
            status,
            phase,
            can_pay_dues,
            can_rent,
            can_parking,
            can_announcements
        FROM tenants
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $tenant_id
    );

    $stmt->execute();

    $tenant =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();


    if (
        !$tenant ||
        $tenant['status'] !== 'active'
    ) {
        session_destroy();
        header("Location: ../index.php");
        exit;
    }


    $stmt = $conn->prepare("
        SELECT
            id,
            status,
            must_change_password,
            first_name,
            last_name,
            phase,
            house_lot_number
        FROM homeowners
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $hid
    );

    $stmt->execute();

    $user =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();


    if (
        !$user ||
        $user['status'] !== 'approved'
    ) {
        session_destroy();
        header("Location: ../index.php");
        exit;
    }


    tenant_guard(
        'parking',
        $tenant
    );

} else {

    if (empty($_SESSION['homeowner_id'])) {
        header("Location: ../index.php");
        exit;
    }

    $hid =
        (int)$_SESSION['homeowner_id'];


    $stmt = $conn->prepare("
        SELECT
            id,
            status,
            must_change_password,
            first_name,
            last_name,
            phase,
            house_lot_number
        FROM homeowners
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $hid
    );

    $stmt->execute();

    $user =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();


    if (
        !$user ||
        $user['status'] !== 'approved'
    ) {
        session_destroy();
        header("Location: ../index.php");
        exit;
    }


    if (
        (int)$user['must_change_password'] === 1
    ) {
        header(
            "Location: homeowner_dashboard.php"
        );
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Basic page values
|--------------------------------------------------------------------------
*/

$phase =
    (string)$user['phase'];

$houseLot =
    (string)(
        $user['house_lot_number']
        ?? ''
    );

$permitId =
    (int)(
        $_GET['permit_id']
        ?? 0
    );


if ($isTenant) {

    $fullName =
        trim(
            ($tenant['first_name'] ?? '') .
            ' ' .
            ($tenant['last_name'] ?? '')
        );

    $initials =
        strtoupper(
            substr(
                $tenant['first_name'] ?? 'T',
                0,
                1
            ) .
            substr(
                $tenant['last_name'] ?? 'N',
                0,
                1
            )
        );

} else {

    $fullName =
        trim(
            ($user['first_name'] ?? '') .
            ' ' .
            ($user['last_name'] ?? '')
        );

    $initials =
        strtoupper(
            substr(
                $user['first_name'] ?? 'H',
                0,
                1
            ) .
            substr(
                $user['last_name'] ?? 'O',
                0,
                1
            )
        );
}


$pageTitle =
    "My Parking Violations • " .
    $phase;


$activePage =
    basename(
        $_SERVER['PHP_SELF']
        ?? 'homeowner_parking_violations.php'
    );

$parkingOpen =
    in_array(
        $activePage,
        [
            'homeowner_parking.php',
            'homeowner_parking_permit.php',
            'homeowner_parking_violations.php'
        ],
        true
    );


/*
|--------------------------------------------------------------------------
| Selected permit
|--------------------------------------------------------------------------
*/

$selectedPermit =
    null;


if ($permitId > 0) {

    $stmt = $conn->prepare("
        SELECT
            id,
            permit_no,
            plate_no,
            vehicle_type,
            vehicle_color,
            permit_duration,
            payment_method,
            status,
            valid_from,
            valid_until,
            sticker_year
        FROM parking_permits
        WHERE id = ?
          AND homeowner_id = ?
          AND phase = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "iis",
        $permitId,
        $hid,
        $phase
    );

    $stmt->execute();

    $selectedPermit =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();


    if (!$selectedPermit) {
        $permitId = 0;
    }
}


/*
|--------------------------------------------------------------------------
| Violations
|--------------------------------------------------------------------------
*/

if ($permitId > 0) {

    $stmt = $conn->prepare("
        SELECT
            id,
            permit_id,
            plate_no,
            violation_type,
            location,
            notes,
            fine_amount,
            status,
            issued_at,
            resolved_at
        FROM parking_violations
        WHERE homeowner_id = ?
          AND phase = ?
          AND permit_id = ?
        ORDER BY
            FIELD(
                status,
                'open',
                'paid',
                'cleared',
                'void'
            ),
            issued_at DESC,
            id DESC
        LIMIT 300
    ");

    $stmt->bind_param(
        "isi",
        $hid,
        $phase,
        $permitId
    );

} else {

    $stmt = $conn->prepare("
        SELECT
            id,
            permit_id,
            plate_no,
            violation_type,
            location,
            notes,
            fine_amount,
            status,
            issued_at,
            resolved_at
        FROM parking_violations
        WHERE homeowner_id = ?
          AND phase = ?
        ORDER BY
            FIELD(
                status,
                'open',
                'paid',
                'cleared',
                'void'
            ),
            issued_at DESC,
            id DESC
        LIMIT 300
    ");

    $stmt->bind_param(
        "is",
        $hid,
        $phase
    );
}


$stmt->execute();

$rows =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();


/*
|--------------------------------------------------------------------------
| Summary counts
|--------------------------------------------------------------------------
*/

$openCount =
    0;

$paidCount =
    0;

$clearedCount =
    0;

$voidCount =
    0;

$openFineTotal =
    0.0;


foreach ($rows as $row) {

    $status =
        strtolower(
            trim(
                (string)(
                    $row['status']
                    ?? ''
                )
            )
        );


    if ($status === 'open') {

        $openCount++;

        $openFineTotal +=
            (float)(
                $row['fine_amount']
                ?? 0
            );

    } elseif ($status === 'paid') {

        $paidCount++;

    } elseif ($status === 'cleared') {

        $clearedCount++;

    } elseif ($status === 'void') {

        $voidCount++;
    }
}


$chatPages = [
    'homeowner_public_chat.php'
];

$chatOpen =
    in_array(
        $activePage,
        $chatPages,
        true
    );
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">

<title>
    <?= esc($pageTitle) ?>
</title>

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>


<!-- Dark mode initialization -->
<script>
(function () {

    const savedTheme =
        localStorage.getItem(
            'hoa-theme'
        );

    const systemDark =
        window.matchMedia(
            '(prefers-color-scheme: dark)'
        ).matches;

    const useDark =
        savedTheme === 'dark' ||
        (!savedTheme && systemDark);

    document.documentElement
        .classList
        .toggle(
            'dark',
            useDark
        );

})();
</script>


<!-- Tailwind dark mode -->
<style type="text/tailwindcss">
    @custom-variant dark (&:where(.dark, .dark *));
</style>


<!-- Tailwind CSS -->
<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>


<!-- Bootstrap Icons only -->
<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css"
>

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


<!-- =========================================================
     MOBILE SIDEBAR OVERLAY
     ========================================================= -->

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


<!-- =========================================================
     MAIN AREA
     ========================================================= -->

<div class="min-h-screen lg:ml-[280px]">


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

            transition-colors

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

            <!-- Mobile menu -->
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


            <!-- Brand -->
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

                <!-- Theme toggle -->
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
                        dark:hover:border-slate-600
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


                <!-- Account -->
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

                        <?= $isTenant
                            ? ' • Tenant'
                            : ''
                        ?>
                    </div>

                </div>


                <!-- Logout -->
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

                    <i
                        class="
                            bi
                            bi-box-arrow-right
                            text-lg
                        "
                    ></i>

                    <span class="hidden sm:inline">
                        Logout
                    </span>

                </a>

            </div>

        </div>

    </header>


    <!-- =====================================================
         CONTENT
         ===================================================== -->

    <main
        class="
            mx-auto
            max-w-7xl

            px-4
            py-6

            sm:px-6
            sm:py-8
        "
    >


        <!-- =================================================
             PAGE HEADER
             ================================================= -->

        <section class="mb-6">

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
                Parking Overview
            </a>


            <div
                class="
                    mt-3

                    flex
                    flex-col
                    gap-3

                    sm:flex-row
                    sm:items-end
                    sm:justify-between
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
                        Parking Records
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
                        My Parking Violations
                    </h1>

                    <p
                        class="
                            mt-2
                            max-w-2xl

                            text-[15px]
                            leading-6
                            text-slate-600

                            dark:text-slate-400
                        "
                    >
                        Review your parking violation history, fines,
                        status, and resolution details.
                    </p>

                </div>


                <div
                    class="
                        inline-flex
                        w-fit
                        items-center
                        gap-2

                        rounded-xl

                        bg-white

                        px-3
                        py-2

                        text-sm
                        font-semibold
                        text-slate-600

                        ring-1
                        ring-slate-200

                        dark:bg-slate-900
                        dark:text-slate-300
                        dark:ring-slate-700
                    "
                >
                    <i
                        class="
                            bi
                            bi-geo-alt-fill
                            text-emerald-700

                            dark:text-emerald-400
                        "
                    ></i>

                    <?= esc($phase) ?>
                    •
                    <?= esc($houseLot) ?>
                </div>

            </div>

        </section>


        <!-- =================================================
             SUMMARY CARDS
             ================================================= -->

        <section
            class="
                mb-6

                grid
                gap-4

                sm:grid-cols-2
                xl:grid-cols-5
            "
        >

            <!-- Open -->
            <div
                class="
                    rounded-2xl

                    border
                    border-slate-200

                    bg-white

                    p-5

                    shadow-sm

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex
                        h-11 w-11
                        items-center
                        justify-center

                        rounded-xl

                        bg-red-100

                        text-xl
                        text-red-700

                        dark:bg-red-950/60
                        dark:text-red-300
                    "
                >
                    <i class="bi bi-exclamation-circle-fill"></i>
                </div>

                <p
                    class="
                        mt-4

                        text-sm
                        font-semibold
                        text-slate-500

                        dark:text-slate-400
                    "
                >
                    Open
                </p>

                <p
                    class="
                        mt-1

                        text-2xl
                        font-bold
                        text-slate-900

                        dark:text-slate-100
                    "
                >
                    <?= (int)$openCount ?>
                </p>
            </div>


            <!-- Paid -->
            <div
                class="
                    rounded-2xl

                    border
                    border-slate-200

                    bg-white

                    p-5

                    shadow-sm

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex
                        h-11 w-11
                        items-center
                        justify-center

                        rounded-xl

                        bg-emerald-100

                        text-xl
                        text-emerald-700

                        dark:bg-emerald-950/60
                        dark:text-emerald-300
                    "
                >
                    <i class="bi bi-check2-circle"></i>
                </div>

                <p
                    class="
                        mt-4

                        text-sm
                        font-semibold
                        text-slate-500

                        dark:text-slate-400
                    "
                >
                    Paid
                </p>

                <p
                    class="
                        mt-1

                        text-2xl
                        font-bold
                        text-slate-900

                        dark:text-slate-100
                    "
                >
                    <?= (int)$paidCount ?>
                </p>
            </div>


            <!-- Cleared -->
            <div
                class="
                    rounded-2xl

                    border
                    border-slate-200

                    bg-white

                    p-5

                    shadow-sm

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex
                        h-11 w-11
                        items-center
                        justify-center

                        rounded-xl

                        bg-amber-100

                        text-xl
                        text-amber-700

                        dark:bg-amber-950/60
                        dark:text-amber-300
                    "
                >
                    <i class="bi bi-shield-check"></i>
                </div>

                <p
                    class="
                        mt-4

                        text-sm
                        font-semibold
                        text-slate-500

                        dark:text-slate-400
                    "
                >
                    Cleared
                </p>

                <p
                    class="
                        mt-1

                        text-2xl
                        font-bold
                        text-slate-900

                        dark:text-slate-100
                    "
                >
                    <?= (int)$clearedCount ?>
                </p>
            </div>


            <!-- Void -->
            <div
                class="
                    rounded-2xl

                    border
                    border-slate-200

                    bg-white

                    p-5

                    shadow-sm

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex
                        h-11 w-11
                        items-center
                        justify-center

                        rounded-xl

                        bg-slate-100

                        text-xl
                        text-slate-600

                        dark:bg-slate-800
                        dark:text-slate-300
                    "
                >
                    <i class="bi bi-x-circle-fill"></i>
                </div>

                <p
                    class="
                        mt-4

                        text-sm
                        font-semibold
                        text-slate-500

                        dark:text-slate-400
                    "
                >
                    Void
                </p>

                <p
                    class="
                        mt-1

                        text-2xl
                        font-bold
                        text-slate-900

                        dark:text-slate-100
                    "
                >
                    <?= (int)$voidCount ?>
                </p>
            </div>


            <!-- Open fines -->
            <div
                class="
                    rounded-2xl

                    border
                    border-slate-200

                    bg-white

                    p-5

                    shadow-sm

                    sm:col-span-2
                    xl:col-span-1

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex
                        h-11 w-11
                        items-center
                        justify-center

                        rounded-xl

                        bg-violet-100

                        text-xl
                        text-violet-700

                        dark:bg-violet-950/60
                        dark:text-violet-300
                    "
                >
                    <i class="bi bi-cash-stack"></i>
                </div>

                <p
                    class="
                        mt-4

                        text-sm
                        font-semibold
                        text-slate-500

                        dark:text-slate-400
                    "
                >
                    Open Fines
                </p>

                <p
                    class="
                        mt-1

                        text-xl
                        font-bold
                        text-slate-900

                        dark:text-slate-100
                    "
                >
                    ₱<?= number_format($openFineTotal, 2) ?>
                </p>
            </div>

        </section>


        <!-- =================================================
             SELECTED PERMIT
             ================================================= -->

        <?php if ($selectedPermit): ?>

            <section
                class="
                    mb-6

                    overflow-hidden

                    rounded-2xl

                    border
                    border-blue-200

                    bg-blue-50/60

                    shadow-sm

                    dark:border-blue-900
                    dark:bg-blue-950/20
                "
            >

                <div
                    class="
                        flex
                        flex-col
                        gap-3

                        border-b
                        border-blue-200

                        px-5
                        py-4

                        sm:flex-row
                        sm:items-center
                        sm:justify-between

                        dark:border-blue-900
                    "
                >

                    <div>

                        <p
                            class="
                                text-xs
                                font-bold
                                uppercase
                                tracking-wide
                                text-blue-600

                                dark:text-blue-400
                            "
                        >
                            Active Filter
                        </p>

                        <h2
                            class="
                                mt-1

                                text-lg
                                font-bold
                                text-slate-900

                                dark:text-slate-100
                            "
                        >
                            Showing violations for selected permit
                        </h2>

                    </div>


                    <a
                        href="homeowner_parking_violations.php"
                        class="
                            inline-flex
                            min-h-11
                            w-fit
                            items-center
                            justify-center
                            gap-2

                            rounded-xl

                            border
                            border-blue-200

                            bg-white

                            px-4

                            text-sm
                            font-semibold
                            text-blue-700

                            transition

                            hover:bg-blue-100

                            dark:border-blue-800
                            dark:bg-slate-900
                            dark:text-blue-300
                            dark:hover:bg-blue-950/50
                        "
                    >
                        <i class="bi bi-list-ul"></i>
                        Show All Violations
                    </a>

                </div>


                <div
                    class="
                        grid
                        gap-4

                        p-5

                        sm:grid-cols-2
                        lg:grid-cols-3
                    "
                >

                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                            Permit No.
                        </p>
                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                            <?= esc($selectedPermit['permit_no'] ?? '—') ?>
                        </p>
                    </div>


                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                            Status
                        </p>
                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                            <?= esc(
                                ucfirst(
                                    (string)(
                                        $selectedPermit['status']
                                        ?? '—'
                                    )
                                )
                            ) ?>
                        </p>
                    </div>


                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                            Plate No.
                        </p>
                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                            <?= esc($selectedPermit['plate_no'] ?? '—') ?>
                        </p>
                    </div>


                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                            Vehicle Type
                        </p>
                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                            <?= esc(
                                vehicle_type_label(
                                    (string)(
                                        $selectedPermit['vehicle_type']
                                        ?? 'car'
                                    )
                                )
                            ) ?>
                        </p>
                    </div>


                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                            Vehicle Color
                        </p>
                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                            <?= esc($selectedPermit['vehicle_color'] ?? '—') ?>
                        </p>
                    </div>


                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                            Permit Duration
                        </p>
                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                            <?= esc(
                                duration_label(
                                    (string)(
                                        $selectedPermit['permit_duration']
                                        ?? ''
                                    )
                                )
                            ) ?>
                        </p>
                    </div>


                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                            Payment Method
                        </p>
                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                            <?= esc(
                                payment_label(
                                    (string)(
                                        $selectedPermit['payment_method']
                                        ?? ''
                                    )
                                )
                            ) ?>
                        </p>
                    </div>


                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                            Sticker Year
                        </p>
                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                            <?= esc($selectedPermit['sticker_year'] ?? '—') ?>
                        </p>
                    </div>


                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                            Validity
                        </p>
                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                            <?= esc(
                                ($selectedPermit['valid_from'] ?? '—') .
                                ' → ' .
                                ($selectedPermit['valid_until'] ?? '—')
                            ) ?>
                        </p>
                    </div>

                </div>

            </section>

        <?php endif; ?>


        <!-- =================================================
             VIOLATIONS LIST
             ================================================= -->

        <section
            class="
                overflow-hidden

                rounded-2xl

                border
                border-slate-200

                bg-white

                shadow-sm

                dark:border-slate-800
                dark:bg-slate-900
            "
        >

            <div
                class="
                    flex
                    flex-col
                    gap-2

                    border-b
                    border-slate-200

                    px-5
                    py-4

                    sm:flex-row
                    sm:items-center
                    sm:justify-between

                    dark:border-slate-800
                "
            >

                <div>

                    <h2
                        class="
                            flex
                            items-center
                            gap-2

                            text-lg
                            font-bold
                            text-slate-900

                            dark:text-slate-100
                        "
                    >
                        <i
                            class="
                                bi
                                bi-receipt-cutoff
                                text-emerald-700

                                dark:text-emerald-400
                            "
                        ></i>

                        Violation Records
                    </h2>

                    <p
                        class="
                            mt-1

                            text-sm
                            text-slate-500

                            dark:text-slate-400
                        "
                    >
                        <?= count($rows) ?>
                        record<?= count($rows) === 1 ? '' : 's' ?>
                        shown
                    </p>

                </div>


                <a
                    href="homeowner_parking.php"
                    class="
                        inline-flex
                        min-h-11
                        w-fit
                        items-center
                        justify-center
                        gap-2

                        rounded-xl

                        border
                        border-slate-200

                        bg-white

                        px-4

                        text-sm
                        font-semibold
                        text-slate-700

                        transition

                        hover:bg-slate-50

                        dark:border-slate-700
                        dark:bg-slate-800
                        dark:text-slate-300
                        dark:hover:bg-slate-700
                    "
                >
                    <i class="bi bi-car-front-fill"></i>
                    Parking Overview
                </a>

            </div>


            <?php if (!$rows): ?>

                <!-- Empty state -->
                <div
                    class="
                        flex
                        flex-col
                        items-center
                        justify-center

                        px-5
                        py-14

                        text-center
                    "
                >

                    <div
                        class="
                            flex
                            h-16 w-16
                            items-center
                            justify-center

                            rounded-2xl

                            bg-emerald-100

                            text-3xl
                            text-emerald-700

                            dark:bg-emerald-950/60
                            dark:text-emerald-300
                        "
                    >
                        <i class="bi bi-shield-check"></i>
                    </div>

                    <h3
                        class="
                            mt-4

                            text-lg
                            font-bold
                            text-slate-900

                            dark:text-slate-100
                        "
                    >
                        No violations found
                    </h3>

                    <p
                        class="
                            mt-2
                            max-w-md

                            text-sm
                            leading-6
                            text-slate-500

                            dark:text-slate-400
                        "
                    >
                        There are currently no parking violation records
                        for the selected view.
                    </p>

                </div>


            <?php else: ?>

                <!-- =================================================
                     DESKTOP / TABLET TABLE
                     ================================================= -->

                <div
                    class="
                        hidden
                        overflow-x-auto

                        md:block
                    "
                >

                    <table
                        class="
                            min-w-[1050px]
                            w-full

                            text-left
                            text-sm
                        "
                    >

                        <thead
                            class="
                                bg-slate-50

                                text-xs
                                font-bold
                                uppercase
                                tracking-wide
                                text-slate-500

                                dark:bg-slate-800/60
                                dark:text-slate-400
                            "
                        >
                            <tr>

                                <th class="px-4 py-3">
                                    #
                                </th>

                                <th class="px-4 py-3">
                                    Plate
                                </th>

                                <th class="px-4 py-3">
                                    Violation
                                </th>

                                <th class="px-4 py-3">
                                    Location
                                </th>

                                <th class="px-4 py-3">
                                    Notes
                                </th>

                                <th class="px-4 py-3">
                                    Fine
                                </th>

                                <th class="px-4 py-3">
                                    Status
                                </th>

                                <th class="px-4 py-3">
                                    Issued
                                </th>

                                <th class="px-4 py-3">
                                    Resolved
                                </th>

                            </tr>
                        </thead>


                        <tbody
                            class="
                                divide-y
                                divide-slate-100

                                dark:divide-slate-800
                            "
                        >

                            <?php foreach ($rows as $r): ?>

                                <?php

                                $status =
                                    (string)(
                                        $r['status']
                                        ?? 'open'
                                    );

                                ?>

                                <tr
                                    class="
                                        align-top
                                        transition

                                        hover:bg-slate-50/80

                                        dark:hover:bg-slate-800/40
                                    "
                                >

                                    <td
                                        class="
                                            whitespace-nowrap
                                            px-4
                                            py-4

                                            font-semibold
                                            text-slate-500

                                            dark:text-slate-400
                                        "
                                    >
                                        <?= (int)$r['id'] ?>
                                    </td>


                                    <td
                                        class="
                                            whitespace-nowrap
                                            px-4
                                            py-4

                                            font-bold
                                            text-slate-900

                                            dark:text-slate-100
                                        "
                                    >
                                        <?= esc($r['plate_no'] ?? '—') ?>
                                    </td>


                                    <td
                                        class="
                                            px-4
                                            py-4

                                            font-semibold
                                            text-slate-800

                                            dark:text-slate-200
                                        "
                                    >
                                        <div class="max-w-[180px] break-words">
                                            <?= esc($r['violation_type'] ?? '—') ?>
                                        </div>
                                    </td>


                                    <td
                                        class="
                                            px-4
                                            py-4

                                            text-slate-600

                                            dark:text-slate-400
                                        "
                                    >
                                        <div class="max-w-[160px] break-words">
                                            <?= esc($r['location'] ?? '—') ?>
                                        </div>
                                    </td>


                                    <td
                                        class="
                                            px-4
                                            py-4

                                            text-slate-600

                                            dark:text-slate-400
                                        "
                                    >
                                        <div class="max-w-[220px] break-words">
                                            <?= esc($r['notes'] ?? '—') ?>
                                        </div>
                                    </td>


                                    <td
                                        class="
                                            whitespace-nowrap
                                            px-4
                                            py-4

                                            font-bold
                                            text-slate-900

                                            dark:text-slate-100
                                        "
                                    >
                                        ₱<?= number_format(
                                            (float)(
                                                $r['fine_amount']
                                                ?? 0
                                            ),
                                            2
                                        ) ?>
                                    </td>


                                    <td
                                        class="
                                            whitespace-nowrap
                                            px-4
                                            py-4
                                        "
                                    >

                                        <span
                                            class="
                                                inline-flex
                                                items-center
                                                gap-1.5

                                                rounded-lg

                                                px-2.5
                                                py-1.5

                                                text-xs
                                                font-bold

                                                ring-1

                                                <?= violation_status_classes(
                                                    $status
                                                ) ?>
                                            "
                                        >
                                            <i
                                                class="
                                                    bi
                                                    <?= esc(
                                                        violation_status_icon(
                                                            $status
                                                        )
                                                    ) ?>
                                                "
                                            ></i>

                                            <?= esc(
                                                violation_status_label(
                                                    $status
                                                )
                                            ) ?>
                                        </span>

                                    </td>


                                    <td
                                        class="
                                            whitespace-nowrap
                                            px-4
                                            py-4

                                            text-xs
                                            font-medium
                                            text-slate-500

                                            dark:text-slate-400
                                        "
                                    >
                                        <?= esc(
                                            display_datetime(
                                                $r['issued_at']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>


                                    <td
                                        class="
                                            whitespace-nowrap
                                            px-4
                                            py-4

                                            text-xs
                                            font-medium
                                            text-slate-500

                                            dark:text-slate-400
                                        "
                                    >
                                        <?= esc(
                                            display_datetime(
                                                $r['resolved_at']
                                                ?? null
                                            )
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <!-- =================================================
                     MOBILE CARDS
                     ================================================= -->

                <div
                    class="
                        space-y-3
                        p-4

                        md:hidden
                    "
                >

                    <?php foreach ($rows as $r): ?>

                        <?php

                        $status =
                            (string)(
                                $r['status']
                                ?? 'open'
                            );

                        ?>

                        <article
                            class="
                                overflow-hidden

                                rounded-2xl

                                border
                                border-slate-200

                                bg-slate-50/60

                                dark:border-slate-700
                                dark:bg-slate-800/40
                            "
                        >

                            <div
                                class="
                                    flex
                                    items-start
                                    justify-between
                                    gap-3

                                    border-b
                                    border-slate-200

                                    px-4
                                    py-3

                                    dark:border-slate-700
                                "
                            >

                                <div class="min-w-0">

                                    <p
                                        class="
                                            text-[10px]
                                            font-bold
                                            uppercase
                                            tracking-wide
                                            text-slate-400

                                            dark:text-slate-500
                                        "
                                    >
                                        Plate Number
                                    </p>

                                    <h3
                                        class="
                                            mt-1
                                            truncate

                                            text-base
                                            font-bold
                                            text-slate-900

                                            dark:text-slate-100
                                        "
                                    >
                                        <?= esc($r['plate_no'] ?? '—') ?>
                                    </h3>

                                </div>


                                <span
                                    class="
                                        inline-flex
                                        shrink-0
                                        items-center
                                        gap-1.5

                                        rounded-lg

                                        px-2.5
                                        py-1.5

                                        text-[10px]
                                        font-bold

                                        ring-1

                                        <?= violation_status_classes(
                                            $status
                                        ) ?>
                                    "
                                >
                                    <i
                                        class="
                                            bi
                                            <?= esc(
                                                violation_status_icon(
                                                    $status
                                                )
                                            ) ?>
                                        "
                                    ></i>

                                    <?= esc(
                                        violation_status_label(
                                            $status
                                        )
                                    ) ?>
                                </span>

                            </div>


                            <div
                                class="
                                    grid
                                    gap-4

                                    p-4
                                "
                            >

                                <div>

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
                                        Violation
                                    </p>

                                    <p
                                        class="
                                            mt-1
                                            break-words

                                            font-semibold
                                            text-slate-800

                                            dark:text-slate-200
                                        "
                                    >
                                        <?= esc($r['violation_type'] ?? '—') ?>
                                    </p>

                                </div>


                                <div
                                    class="
                                        grid
                                        gap-4

                                        sm:grid-cols-2
                                    "
                                >

                                    <div>

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
                                            Location
                                        </p>

                                        <p
                                            class="
                                                mt-1
                                                break-words

                                                text-sm
                                                text-slate-600

                                                dark:text-slate-400
                                            "
                                        >
                                            <?= esc($r['location'] ?? '—') ?>
                                        </p>

                                    </div>


                                    <div>

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
                                            Fine
                                        </p>

                                        <p
                                            class="
                                                mt-1

                                                font-bold
                                                text-slate-900

                                                dark:text-slate-100
                                            "
                                        >
                                            ₱<?= number_format(
                                                (float)(
                                                    $r['fine_amount']
                                                    ?? 0
                                                ),
                                                2
                                            ) ?>
                                        </p>

                                    </div>

                                </div>


                                <div>

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
                                        Notes
                                    </p>

                                    <p
                                        class="
                                            mt-1
                                            whitespace-pre-wrap
                                            break-words

                                            text-sm
                                            leading-6
                                            text-slate-600

                                            dark:text-slate-400
                                        "
                                    >
                                        <?= esc($r['notes'] ?? '—') ?>
                                    </p>

                                </div>


                                <div
                                    class="
                                        grid
                                        gap-3

                                        rounded-xl

                                        bg-white

                                        p-3

                                        ring-1
                                        ring-slate-200

                                        dark:bg-slate-900
                                        dark:ring-slate-700
                                    "
                                >

                                    <div
                                        class="
                                            flex
                                            items-start
                                            justify-between
                                            gap-3
                                        "
                                    >

                                        <span
                                            class="
                                                text-xs
                                                font-semibold
                                                text-slate-500

                                                dark:text-slate-400
                                            "
                                        >
                                            Issued
                                        </span>

                                        <span
                                            class="
                                                text-right
                                                text-xs
                                                font-semibold
                                                text-slate-700

                                                dark:text-slate-300
                                            "
                                        >
                                            <?= esc(
                                                display_datetime(
                                                    $r['issued_at']
                                                    ?? null
                                                )
                                            ) ?>
                                        </span>

                                    </div>


                                    <div
                                        class="
                                            flex
                                            items-start
                                            justify-between
                                            gap-3
                                        "
                                    >

                                        <span
                                            class="
                                                text-xs
                                                font-semibold
                                                text-slate-500

                                                dark:text-slate-400
                                            "
                                        >
                                            Resolved
                                        </span>

                                        <span
                                            class="
                                                text-right
                                                text-xs
                                                font-semibold
                                                text-slate-700

                                                dark:text-slate-300
                                            "
                                        >
                                            <?= esc(
                                                display_datetime(
                                                    $r['resolved_at']
                                                    ?? null
                                                )
                                            ) ?>
                                        </span>

                                    </div>

                                </div>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>


                <!-- Information note -->
                <div
                    class="
                        border-t
                        border-slate-200

                        bg-slate-50/70

                        px-5
                        py-4

                        dark:border-slate-800
                        dark:bg-slate-950/30
                    "
                >

                    <div
                        class="
                            flex
                            items-start
                            gap-3

                            text-sm
                            leading-6
                            text-slate-600

                            dark:text-slate-400
                        "
                    >

                        <i
                            class="
                                bi
                                bi-info-circle-fill

                                mt-0.5
                                shrink-0

                                text-blue-600

                                dark:text-blue-400
                            "
                        ></i>

                        <p>
                            Violation status is maintained by the HOA administration.
                            Open records remain visible until they are updated,
                            paid, cleared, or voided.
                        </p>

                    </div>

                </div>

            <?php endif; ?>

        </section>


        <!-- =================================================
             FOOTER
             ================================================= -->

        <footer
            class="
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
| Light / Dark Theme
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