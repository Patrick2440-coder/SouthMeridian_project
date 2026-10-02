<?php
session_start();

if (
    !isset($_SESSION['role']) ||
    !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)
) {
    header("Location: ../index.php");
    exit;
}

date_default_timezone_set('Asia/Manila');

require_once '../config/database.php';
require_once 'tenant_module_guard.php';


function esc($v)
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES,
        'UTF-8'
    );
}


function facility_label(string $facility): string
{
    return match ($facility) {
        'tables_chairs' => 'Tables & Chairs',
        'court'         => 'Court',
        'clubhouse'     => 'Clubhouse',
        default         => 'Facility'
    };
}


function request_status_classes(string $status): string
{
    return match ($status) {
        'approved' =>
            'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',

        'denied' =>
            'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',

        'cancelled' =>
            'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',

        default =>
            'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900'
    };
}


function request_status_icon(string $status): string
{
    return match ($status) {
        'approved'  => 'bi-check-circle-fill',
        'denied'    => 'bi-x-circle-fill',
        'cancelled' => 'bi-slash-circle-fill',
        default     => 'bi-clock-fill'
    };
}


/*
|--------------------------------------------------------------------------
| Account / session lookup
|--------------------------------------------------------------------------
*/

$isTenant = ($_SESSION['role'] === 'tenant');
$tenant   = null;
$user     = null;
$hid      = 0;


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
        ($user['status'] ?? '') !== 'approved'
    ) {
        session_destroy();
        header("Location: ../index.php");
        exit;
    }


    tenant_guard(
        'rentals',
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
        ($user['status'] ?? '') !== 'approved'
    ) {
        session_destroy();
        header("Location: ../index.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Account details
|--------------------------------------------------------------------------
*/

$phase =
    (string)$user['phase'];

$houseLot =
    (string)(
        $user['house_lot_number']
        ?? ''
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

    $mustChange = false;

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

    $mustChange =
        (
            (int)(
                $user['must_change_password']
                ?? 0
            ) === 1
        );
}


if ($mustChange) {
    header(
        "Location: homeowner_dashboard.php"
    );
    exit;
}


$pageTitle =
    "Facility Rentals • " .
    $phase;

$activePage =
    basename(
        $_SERVER['PHP_SELF']
        ?? 'homeowner_rentals.php'
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
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_rent_req'])) {

    $_SESSION['csrf_rent_req'] =
        bin2hex(
            random_bytes(16)
        );
}

$csrf =
    (string)$_SESSION['csrf_rent_req'];


/*
|--------------------------------------------------------------------------
| Facility selection
|--------------------------------------------------------------------------
*/

$allowedFacilities = [
    'tables_chairs',
    'court',
    'clubhouse'
];

$facility =
    (string)(
        $_GET['facility']
        ?? 'tables_chairs'
    );

if (
    !in_array(
        $facility,
        $allowedFacilities,
        true
    )
) {
    $facility =
        'tables_chairs';
}


/*
|--------------------------------------------------------------------------
| Flash message
|--------------------------------------------------------------------------
*/

$msg =
    trim(
        (string)(
            $_GET['msg']
            ?? ''
        )
    );


/*
|--------------------------------------------------------------------------
| My rental requests
|--------------------------------------------------------------------------
*/

$myReqs = [];

$stmt = $conn->prepare("
    SELECT
        id,
        facility,
        start_dt,
        end_dt,
        purpose,
        status,
        admin_remarks,
        created_at
    FROM facility_rental_requests
    WHERE homeowner_id = ?
      AND TRIM(phase) = TRIM(?)
    ORDER BY created_at DESC
    LIMIT 30
");

$stmt->bind_param(
    "is",
    $hid,
    $phase
);

$stmt->execute();

$myReqs =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();


/*
|--------------------------------------------------------------------------
| Request counters
|--------------------------------------------------------------------------
*/

$requestCounts = [
    'pending'   => 0,
    'approved'  => 0,
    'denied'    => 0,
    'cancelled' => 0
];

foreach ($myReqs as $request) {

    $status =
        (string)(
            $request['status']
            ?? 'pending'
        );

    if (
        array_key_exists(
            $status,
            $requestCounts
        )
    ) {
        $requestCounts[$status]++;
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
<?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>
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


<!-- FullCalendar -->
<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css"
>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>


<!-- FullCalendar integration -->
<style>
    #rentCalendar {
        --fc-border-color: #e2e8f0;
        --fc-page-bg-color: transparent;
        --fc-neutral-bg-color: #f8fafc;
        --fc-list-event-hover-bg-color: #f1f5f9;
        --fc-today-bg-color: rgba(16, 185, 129, 0.08);
        --fc-button-bg-color: #047857;
        --fc-button-border-color: #047857;
        --fc-button-hover-bg-color: #065f46;
        --fc-button-hover-border-color: #065f46;
        --fc-button-active-bg-color: #065f46;
        --fc-button-active-border-color: #065f46;
    }

    .dark #rentCalendar {
        --fc-border-color: #334155;
        --fc-page-bg-color: transparent;
        --fc-neutral-bg-color: #1e293b;
        --fc-list-event-hover-bg-color: #1e293b;
        --fc-today-bg-color: rgba(16, 185, 129, 0.10);
    }

    #rentCalendar .fc {
        font-family: inherit;
    }

    #rentCalendar .fc-toolbar-title {
        font-size: 1rem;
        font-weight: 800;
    }

    #rentCalendar .fc-button {
        border-radius: 0.75rem;
        box-shadow: none;
        font-size: 0.875rem;
        font-weight: 700;
        min-height: 40px;
        padding-left: 0.8rem;
        padding-right: 0.8rem;
    }

    #rentCalendar .fc-daygrid-day-number,
    #rentCalendar .fc-col-header-cell-cushion {
        color: #475569;
        font-weight: 700;
        text-decoration: none;
    }

    .dark #rentCalendar .fc-daygrid-day-number,
    .dark #rentCalendar .fc-col-header-cell-cushion,
    .dark #rentCalendar .fc-toolbar-title {
        color: #e2e8f0;
    }

    #rentCalendar .fc-event {
        border-radius: 0.5rem;
        border: 0;
        cursor: pointer;
        padding: 2px 4px;
        font-size: 0.75rem;
        font-weight: 700;
    }

    @media (max-width: 767px) {
        #rentCalendar .fc-toolbar {
            align-items: stretch;
            flex-direction: column;
            gap: 10px;
        }

        #rentCalendar .fc-toolbar-chunk {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        #rentCalendar .fc-toolbar-title {
            font-size: 0.95rem;
        }

        #rentCalendar .fc-button {
            min-height: 38px;
            font-size: 0.8rem;
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
         PAGE CONTENT
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
                href="homeowner_dashboard.php"
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
                Dashboard
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
                        Community Facilities
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
                        Facility Rentals
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
                        Check approved reservations and request a schedule
                        for community facilities.
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
             MESSAGE
             ================================================= -->

        <?php if ($msg !== ''): ?>

            <div
                class="
                    page-flash
                    mb-5

                    flex
                    items-start
                    gap-3

                    rounded-2xl

                    border
                    border-blue-200

                    bg-blue-50

                    p-4

                    dark:border-blue-900
                    dark:bg-blue-950/40
                "
            >

                <div
                    class="
                        flex
                        h-10 w-10
                        shrink-0
                        items-center
                        justify-center

                        rounded-xl

                        bg-blue-100

                        text-lg
                        text-blue-700

                        dark:bg-blue-950
                        dark:text-blue-300
                    "
                >
                    <i class="bi bi-info-circle-fill"></i>
                </div>

                <p
                    class="
                        min-w-0
                        flex-1

                        pt-1.5

                        text-sm
                        font-semibold
                        leading-6
                        text-blue-900

                        dark:text-blue-200
                    "
                >
                    <?= esc($msg) ?>
                </p>

                <button
                    type="button"
                    class="
                        btn-close-flash

                        flex
                        h-9 w-9
                        shrink-0
                        items-center
                        justify-center

                        rounded-lg

                        text-blue-700

                        hover:bg-blue-100

                        dark:text-blue-300
                        dark:hover:bg-blue-950
                    "
                    aria-label="Close message"
                >
                    <i class="bi bi-x-lg"></i>
                </button>

            </div>

        <?php endif; ?>


        <!-- =================================================
             SUMMARY CARDS
             ================================================= -->

        <section
            class="
                mb-6

                grid
                gap-4

                sm:grid-cols-2
                xl:grid-cols-4
            "
        >

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
                    <i class="bi bi-clock-fill"></i>
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
                    Pending
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
                    <?= (int)$requestCounts['pending'] ?>
                </p>

            </div>


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
                    <i class="bi bi-check-circle-fill"></i>
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
                    Approved
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
                    <?= (int)$requestCounts['approved'] ?>
                </p>

            </div>


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
                    Denied
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
                    <?= (int)$requestCounts['denied'] ?>
                </p>

            </div>


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

                        bg-violet-100

                        text-xl
                        text-violet-700

                        dark:bg-violet-950/60
                        dark:text-violet-300
                    "
                >
                    <i class="bi bi-calendar2-week-fill"></i>
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
                    Total Requests
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
                    <?= count($myReqs) ?>
                </p>

            </div>

        </section>


        <!-- =================================================
             MAIN GRID
             ================================================= -->

        <div
            class="
                grid
                gap-6

                xl:grid-cols-[360px_minmax(0,1fr)]
            "
        >


            <!-- =============================================
                 LEFT COLUMN
                 ============================================= -->

            <div class="space-y-6">


                <!-- Facility selector -->
                <section
                    class="
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
                            border-b
                            border-slate-200

                            px-5
                            py-4

                            dark:border-slate-800
                        "
                    >

                        <h2
                            class="
                                flex
                                items-center
                                gap-2

                                font-bold
                                text-slate-900

                                dark:text-slate-100
                            "
                        >
                            <i
                                class="
                                    bi
                                    bi-building-fill
                                    text-emerald-700

                                    dark:text-emerald-400
                                "
                            ></i>

                            Choose Facility
                        </h2>

                        <p
                            class="
                                mt-1

                                text-sm
                                text-slate-500

                                dark:text-slate-400
                            "
                        >
                            Select which facility schedule you want to view.
                        </p>

                    </div>


                    <div class="p-5">

                        <div class="grid gap-2">

                            <a
                                href="?facility=tables_chairs"
                                class="
                                    flex
                                    min-h-12
                                    items-center
                                    gap-3

                                    rounded-xl

                                    border

                                    px-4

                                    text-sm
                                    font-semibold

                                    transition

                                    <?= $facility === 'tables_chairs'
                                        ? '
                                            border-emerald-200
                                            bg-emerald-50
                                            text-emerald-800

                                            dark:border-emerald-900
                                            dark:bg-emerald-950/50
                                            dark:text-emerald-300
                                          '
                                        : '
                                            border-slate-200
                                            bg-white
                                            text-slate-700

                                            hover:border-emerald-200
                                            hover:bg-emerald-50

                                            dark:border-slate-700
                                            dark:bg-slate-800
                                            dark:text-slate-300
                                            dark:hover:border-emerald-900
                                            dark:hover:bg-emerald-950/30
                                          '
                                    ?>
                                "
                            >

                                <i
                                    class="
                                        bi
                                        bi-table
                                        text-lg
                                    "
                                ></i>

                                <span>
                                    Tables & Chairs
                                </span>

                            </a>


                            <a
                                href="?facility=court"
                                class="
                                    flex
                                    min-h-12
                                    items-center
                                    gap-3

                                    rounded-xl

                                    border

                                    px-4

                                    text-sm
                                    font-semibold

                                    transition

                                    <?= $facility === 'court'
                                        ? '
                                            border-emerald-200
                                            bg-emerald-50
                                            text-emerald-800

                                            dark:border-emerald-900
                                            dark:bg-emerald-950/50
                                            dark:text-emerald-300
                                          '
                                        : '
                                            border-slate-200
                                            bg-white
                                            text-slate-700

                                            hover:border-emerald-200
                                            hover:bg-emerald-50

                                            dark:border-slate-700
                                            dark:bg-slate-800
                                            dark:text-slate-300
                                            dark:hover:border-emerald-900
                                            dark:hover:bg-emerald-950/30
                                          '
                                    ?>
                                "
                            >

                                <i
                                    class="
                                        bi
                                        bi-trophy-fill
                                        text-lg
                                    "
                                ></i>

                                <span>
                                    Court
                                </span>

                            </a>


                            <a
                                href="?facility=clubhouse"
                                class="
                                    flex
                                    min-h-12
                                    items-center
                                    gap-3

                                    rounded-xl

                                    border

                                    px-4

                                    text-sm
                                    font-semibold

                                    transition

                                    <?= $facility === 'clubhouse'
                                        ? '
                                            border-emerald-200
                                            bg-emerald-50
                                            text-emerald-800

                                            dark:border-emerald-900
                                            dark:bg-emerald-950/50
                                            dark:text-emerald-300
                                          '
                                        : '
                                            border-slate-200
                                            bg-white
                                            text-slate-700

                                            hover:border-emerald-200
                                            hover:bg-emerald-50

                                            dark:border-slate-700
                                            dark:bg-slate-800
                                            dark:text-slate-300
                                            dark:hover:border-emerald-900
                                            dark:hover:bg-emerald-950/30
                                          '
                                    ?>
                                "
                            >

                                <i
                                    class="
                                        bi
                                        bi-house-heart-fill
                                        text-lg
                                    "
                                ></i>

                                <span>
                                    Clubhouse
                                </span>

                            </a>

                        </div>


                        <div
                            class="
                                mt-4

                                flex
                                items-start
                                gap-3

                                rounded-xl

                                border
                                border-amber-200

                                bg-amber-50

                                p-3

                                dark:border-amber-900
                                dark:bg-amber-950/40
                            "
                        >

                            <i
                                class="
                                    bi
                                    bi-info-circle-fill

                                    mt-0.5

                                    shrink-0

                                    text-amber-700

                                    dark:text-amber-300
                                "
                            ></i>

                            <div>

                                <p
                                    class="
                                        text-sm
                                        font-bold
                                        text-amber-900

                                        dark:text-amber-200
                                    "
                                >
                                    Approved bookings only
                                </p>

                                <p
                                    class="
                                        mt-1

                                        text-xs
                                        leading-5
                                        text-amber-800

                                        dark:text-amber-300
                                    "
                                >
                                    Pending requests do not appear as reserved
                                    until the HOA approves them.
                                </p>

                            </div>

                        </div>


                        <button
                            type="button"
                            id="openRequestModal"
                            class="
                                mt-4

                                flex
                                min-h-12
                                w-full
                                items-center
                                justify-center
                                gap-2

                                rounded-xl

                                bg-emerald-700

                                px-4

                                text-base
                                font-semibold
                                text-white

                                shadow-sm
                                transition

                                hover:bg-emerald-800

                                focus:outline-none
                                focus:ring-4
                                focus:ring-emerald-100

                                dark:bg-emerald-600
                                dark:hover:bg-emerald-500
                                dark:focus:ring-emerald-950
                            "
                        >
                            <i class="bi bi-calendar-plus-fill"></i>

                            Request Booking
                        </button>

                    </div>

                </section>


                <!-- My Requests -->
                <section
                    class="
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
                            items-center
                            justify-between
                            gap-3

                            border-b
                            border-slate-200

                            px-5
                            py-4

                            dark:border-slate-800
                        "
                    >

                        <div>

                            <h2
                                class="
                                    flex
                                    items-center
                                    gap-2

                                    font-bold
                                    text-slate-900

                                    dark:text-slate-100
                                "
                            >
                                <i
                                    class="
                                        bi
                                        bi-receipt-cutoff
                                        text-blue-700

                                        dark:text-blue-300
                                    "
                                ></i>

                                My Requests
                            </h2>

                            <p
                                class="
                                    mt-1

                                    text-xs
                                    text-slate-500

                                    dark:text-slate-400
                                "
                            >
                                Latest 30 requests
                            </p>

                        </div>


                        <span
                            class="
                                inline-flex
                                min-w-8
                                items-center
                                justify-center

                                rounded-full

                                bg-slate-100

                                px-2
                                py-1

                                text-xs
                                font-bold
                                text-slate-700

                                dark:bg-slate-800
                                dark:text-slate-300
                            "
                        >
                            <?= count($myReqs) ?>
                        </span>

                    </div>


                    <div class="p-4">

                        <?php if (empty($myReqs)): ?>

                            <div
                                class="
                                    flex
                                    flex-col
                                    items-center
                                    justify-center

                                    px-4
                                    py-8

                                    text-center
                                "
                            >

                                <div
                                    class="
                                        flex
                                        h-12 w-12
                                        items-center
                                        justify-center

                                        rounded-xl

                                        bg-slate-100

                                        text-xl
                                        text-slate-400

                                        dark:bg-slate-800
                                        dark:text-slate-500
                                    "
                                >
                                    <i class="bi bi-calendar-x"></i>
                                </div>

                                <p
                                    class="
                                        mt-3

                                        text-sm
                                        font-semibold
                                        text-slate-600

                                        dark:text-slate-400
                                    "
                                >
                                    No rental requests yet.
                                </p>

                            </div>

                        <?php else: ?>

                            <div
                                class="
                                    max-h-[560px]
                                    space-y-3
                                    overflow-y-auto
                                    pr-1
                                "
                            >

                                <?php foreach ($myReqs as $r): ?>

                                    <?php

                                    $status =
                                        (string)(
                                            $r['status']
                                            ?? 'pending'
                                        );

                                    $statusClasses =
                                        request_status_classes(
                                            $status
                                        );

                                    $statusIcon =
                                        request_status_icon(
                                            $status
                                        );

                                    $startLabel =
                                        date(
                                            'M d, Y h:i A',
                                            strtotime(
                                                $r['start_dt']
                                            )
                                        );

                                    $endLabel =
                                        date(
                                            'M d, Y h:i A',
                                            strtotime(
                                                $r['end_dt']
                                            )
                                        );

                                    ?>

                                    <article
                                        class="
                                            rounded-xl

                                            border
                                            border-slate-200

                                            bg-slate-50/70

                                            p-4

                                            dark:border-slate-800
                                            dark:bg-slate-800/50
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

                                            <div class="min-w-0">

                                                <h3
                                                    class="
                                                        font-bold
                                                        text-slate-900

                                                        dark:text-slate-100
                                                    "
                                                >
                                                    <?= esc(
                                                        facility_label(
                                                            (string)$r['facility']
                                                        )
                                                    ) ?>
                                                </h3>

                                                <?php if (!empty($r['purpose'])): ?>

                                                    <p
                                                        class="
                                                            mt-1
                                                            break-words

                                                            text-sm
                                                            text-slate-600

                                                            dark:text-slate-400
                                                        "
                                                    >
                                                        <?= esc(
                                                            $r['purpose']
                                                        ) ?>
                                                    </p>

                                                <?php endif; ?>

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

                                                    <?= $statusClasses ?>
                                                "
                                            >
                                                <i
                                                    class="
                                                        bi
                                                        <?= esc($statusIcon) ?>
                                                    "
                                                ></i>

                                                <?= esc(
                                                    strtoupper(
                                                        $status
                                                    )
                                                ) ?>
                                            </span>

                                        </div>


                                        <div
                                            class="
                                                mt-3

                                                space-y-2

                                                rounded-xl

                                                bg-white

                                                p-3

                                                text-xs

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
                                                    gap-2
                                                "
                                            >

                                                <i
                                                    class="
                                                        bi
                                                        bi-calendar-event

                                                        mt-0.5

                                                        text-slate-400

                                                        dark:text-slate-500
                                                    "
                                                ></i>

                                                <div>

                                                    <p
                                                        class="
                                                            font-bold
                                                            text-slate-700

                                                            dark:text-slate-300
                                                        "
                                                    >
                                                        Start
                                                    </p>

                                                    <p
                                                        class="
                                                            mt-0.5
                                                            text-slate-500

                                                            dark:text-slate-400
                                                        "
                                                    >
                                                        <?= esc(
                                                            $startLabel
                                                        ) ?>
                                                    </p>

                                                </div>

                                            </div>


                                            <div
                                                class="
                                                    flex
                                                    items-start
                                                    gap-2
                                                "
                                            >

                                                <i
                                                    class="
                                                        bi
                                                        bi-calendar-check

                                                        mt-0.5

                                                        text-slate-400

                                                        dark:text-slate-500
                                                    "
                                                ></i>

                                                <div>

                                                    <p
                                                        class="
                                                            font-bold
                                                            text-slate-700

                                                            dark:text-slate-300
                                                        "
                                                    >
                                                        End
                                                    </p>

                                                    <p
                                                        class="
                                                            mt-0.5
                                                            text-slate-500

                                                            dark:text-slate-400
                                                        "
                                                    >
                                                        <?= esc(
                                                            $endLabel
                                                        ) ?>
                                                    </p>

                                                </div>

                                            </div>

                                        </div>


                                        <?php if (!empty($r['admin_remarks'])): ?>

                                            <div
                                                class="
                                                    mt-3

                                                    rounded-xl

                                                    bg-blue-50

                                                    p-3

                                                    text-xs
                                                    leading-5
                                                    text-blue-800

                                                    dark:bg-blue-950/40
                                                    dark:text-blue-300
                                                "
                                            >
                                                <span class="font-bold">
                                                    Admin remarks:
                                                </span>

                                                <?= esc(
                                                    $r['admin_remarks']
                                                ) ?>
                                            </div>

                                        <?php endif; ?>

                                    </article>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                </section>

            </div>


            <!-- =============================================
                 CALENDAR
                 ============================================= -->

            <section
                class="
                    min-w-0
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
                        gap-3

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
                                    bi-calendar3
                                    text-emerald-700

                                    dark:text-emerald-400
                                "
                            ></i>

                            <?= esc(
                                facility_label(
                                    $facility
                                )
                            ) ?>
                            Schedule
                        </h2>

                        <p
                            class="
                                mt-1

                                text-sm
                                text-slate-500

                                dark:text-slate-400
                            "
                        >
                            Click an approved reservation to view details.
                        </p>

                    </div>


                    <span
                        class="
                            inline-flex
                            w-fit
                            items-center
                            gap-2

                            rounded-xl

                            bg-emerald-50

                            px-3
                            py-2

                            text-xs
                            font-bold
                            text-emerald-700

                            ring-1
                            ring-emerald-200

                            dark:bg-emerald-950/40
                            dark:text-emerald-300
                            dark:ring-emerald-900
                        "
                    >
                        <span
                            class="
                                h-2
                                w-2
                                rounded-full
                                bg-emerald-500
                            "
                        ></span>

                        Approved = Reserved
                    </span>

                </div>


                <div class="p-3 sm:p-5">

                    <div
                        class="
                            overflow-x-auto

                            rounded-xl

                            border
                            border-slate-200

                            bg-white

                            p-2

                            dark:border-slate-700
                            dark:bg-slate-900
                        "
                    >
                        <div
                            id="rentCalendar"
                            class="min-w-[680px] md:min-w-0"
                        ></div>
                    </div>

                </div>

            </section>

        </div>


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
            © Copyright South Meridian Homes All Rights Reserved
        </footer>

    </main>

</div>


<!-- =========================================================
     REQUEST BOOKING MODAL
     ========================================================= -->

<div
    id="requestModal"
    class="
        fixed
        inset-0
        z-[200]

        hidden
        items-center
        justify-center

        bg-slate-950/60

        p-4

        backdrop-blur-sm
    "
    aria-hidden="true"
>

    <div
        class="
            flex
            max-h-[92vh]
            w-full
            max-w-3xl
            flex-col

            overflow-hidden

            rounded-2xl

            border
            border-slate-200

            bg-white

            shadow-2xl

            dark:border-slate-700
            dark:bg-slate-900
        "
    >

        <div
            class="
                flex
                items-center
                justify-between
                gap-3

                border-b
                border-slate-200

                px-5
                py-4

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
                            bi-calendar-plus-fill
                            text-emerald-700

                            dark:text-emerald-400
                        "
                    ></i>

                    Request Booking
                </h2>

                <p
                    class="
                        mt-1

                        text-xs
                        text-slate-500

                        dark:text-slate-400
                    "
                >
                    Submit your preferred facility and schedule.
                </p>

            </div>


            <button
                type="button"
                class="
                    btn-close-request-modal

                    flex
                    h-10 w-10
                    shrink-0
                    items-center
                    justify-center

                    rounded-xl

                    text-lg
                    text-slate-500

                    transition

                    hover:bg-slate-100
                    hover:text-slate-800

                    dark:text-slate-400
                    dark:hover:bg-slate-800
                    dark:hover:text-slate-100
                "
                aria-label="Close booking form"
            >
                <i class="bi bi-x-lg"></i>
            </button>

        </div>


        <form
            method="POST"
            action="homeowner_rental_request.php"
            autocomplete="off"
            class="
                min-h-0
                flex-1
                overflow-y-auto
            "
        >

            <div class="p-5">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= esc($csrf) ?>"
                >


                <div
                    class="
                        grid
                        gap-4

                        md:grid-cols-2
                    "
                >

                    <!-- Facility -->
                    <div>

                        <label
                            for="facilitySelect"
                            class="
                                mb-2
                                block

                                text-sm
                                font-bold
                                text-slate-700

                                dark:text-slate-300
                            "
                        >
                            Facility
                        </label>

                        <select
                            id="facilitySelect"
                            name="facility"
                            required
                            class="
                                min-h-12
                                w-full

                                rounded-xl

                                border
                                border-slate-300

                                bg-white

                                px-3

                                text-base
                                text-slate-800

                                outline-none
                                transition

                                focus:border-emerald-500
                                focus:ring-4
                                focus:ring-emerald-100

                                dark:border-slate-700
                                dark:bg-slate-800
                                dark:text-slate-100
                                dark:focus:ring-emerald-950
                            "
                        >

                            <option
                                value="tables_chairs"
                                <?= $facility === 'tables_chairs'
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                Tables & Chairs
                            </option>

                            <option
                                value="court"
                                <?= $facility === 'court'
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                Court
                            </option>

                            <option
                                value="clubhouse"
                                <?= $facility === 'clubhouse'
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                Clubhouse
                            </option>

                        </select>

                    </div>


                    <!-- Guest count -->
                    <div
                        id="guestCountWrap"
                        class="hidden"
                    >

                        <label
                            for="guestCountInput"
                            class="
                                mb-2
                                block

                                text-sm
                                font-bold
                                text-slate-700

                                dark:text-slate-300
                            "
                        >
                            Guest Count
                        </label>

                        <input
                            type="number"
                            id="guestCountInput"
                            name="guest_count"
                            min="1"
                            placeholder="e.g. 30"
                            class="
                                min-h-12
                                w-full

                                rounded-xl

                                border
                                border-slate-300

                                bg-white

                                px-3

                                text-base
                                text-slate-800

                                outline-none
                                transition

                                placeholder:text-slate-400

                                focus:border-emerald-500
                                focus:ring-4
                                focus:ring-emerald-100

                                dark:border-slate-700
                                dark:bg-slate-800
                                dark:text-slate-100
                                dark:placeholder:text-slate-500
                                dark:focus:ring-emerald-950
                            "
                        >

                        <p
                            class="
                                mt-1.5

                                text-xs
                                text-slate-500

                                dark:text-slate-400
                            "
                        >
                            Required for Clubhouse bookings only.
                        </p>

                    </div>


                    <!-- Start -->
                    <div>

                        <label
                            for="startDt"
                            class="
                                mb-2
                                block

                                text-sm
                                font-bold
                                text-slate-700

                                dark:text-slate-300
                            "
                        >
                            Start
                        </label>

                        <input
                            type="datetime-local"
                            id="startDt"
                            name="start_dt"
                            required
                            class="
                                min-h-12
                                w-full

                                rounded-xl

                                border
                                border-slate-300

                                bg-white

                                px-3

                                text-base
                                text-slate-800

                                outline-none
                                transition

                                focus:border-emerald-500
                                focus:ring-4
                                focus:ring-emerald-100

                                dark:border-slate-700
                                dark:bg-slate-800
                                dark:text-slate-100
                                dark:focus:ring-emerald-950
                            "
                        >

                    </div>


                    <!-- End -->
                    <div>

                        <label
                            for="endDt"
                            class="
                                mb-2
                                block

                                text-sm
                                font-bold
                                text-slate-700

                                dark:text-slate-300
                            "
                        >
                            End
                        </label>

                        <input
                            type="datetime-local"
                            id="endDt"
                            name="end_dt"
                            required
                            class="
                                min-h-12
                                w-full

                                rounded-xl

                                border
                                border-slate-300

                                bg-white

                                px-3

                                text-base
                                text-slate-800

                                outline-none
                                transition

                                focus:border-emerald-500
                                focus:ring-4
                                focus:ring-emerald-100

                                dark:border-slate-700
                                dark:bg-slate-800
                                dark:text-slate-100
                                dark:focus:ring-emerald-950
                            "
                        >

                    </div>


                    <!-- Purpose -->
                    <div class="md:col-span-2">

                        <label
                            for="purpose"
                            class="
                                mb-2
                                block

                                text-sm
                                font-bold
                                text-slate-700

                                dark:text-slate-300
                            "
                        >
                            Purpose
                        </label>

                        <input
                            type="text"
                            id="purpose"
                            name="purpose"
                            maxlength="255"
                            placeholder="e.g. Birthday, meeting, family event"
                            class="
                                min-h-12
                                w-full

                                rounded-xl

                                border
                                border-slate-300

                                bg-white

                                px-3

                                text-base
                                text-slate-800

                                outline-none
                                transition

                                placeholder:text-slate-400

                                focus:border-emerald-500
                                focus:ring-4
                                focus:ring-emerald-100

                                dark:border-slate-700
                                dark:bg-slate-800
                                dark:text-slate-100
                                dark:placeholder:text-slate-500
                                dark:focus:ring-emerald-950
                            "
                        >

                    </div>


                    <!-- Notes -->
                    <div class="md:col-span-2">

                        <label
                            for="notes"
                            class="
                                mb-2
                                block

                                text-sm
                                font-bold
                                text-slate-700

                                dark:text-slate-300
                            "
                        >
                            Notes
                            <span
                                class="
                                    font-medium
                                    text-slate-400

                                    dark:text-slate-500
                                "
                            >
                                (optional)
                            </span>
                        </label>

                        <textarea
                            id="notes"
                            name="notes"
                            maxlength="255"
                            rows="3"
                            placeholder="Add any extra details for the HOA."
                            class="
                                w-full

                                resize-none

                                rounded-xl

                                border
                                border-slate-300

                                bg-white

                                px-3
                                py-3

                                text-base
                                text-slate-800

                                outline-none
                                transition

                                placeholder:text-slate-400

                                focus:border-emerald-500
                                focus:ring-4
                                focus:ring-emerald-100

                                dark:border-slate-700
                                dark:bg-slate-800
                                dark:text-slate-100
                                dark:placeholder:text-slate-500
                                dark:focus:ring-emerald-950
                            "
                        ></textarea>

                    </div>

                </div>


                <div
                    class="
                        mt-5

                        flex
                        items-start
                        gap-3

                        rounded-xl

                        border
                        border-blue-200

                        bg-blue-50

                        p-3

                        text-sm
                        leading-6
                        text-blue-800

                        dark:border-blue-900
                        dark:bg-blue-950/40
                        dark:text-blue-300
                    "
                >
                    <i
                        class="
                            bi
                            bi-info-circle-fill

                            mt-0.5

                            shrink-0
                        "
                    ></i>

                    <span>
                        Only approved bookings appear on the calendar
                        as reserved.
                    </span>
                </div>

            </div>


            <div
                class="
                    flex
                    flex-col-reverse
                    gap-2

                    border-t
                    border-slate-200

                    bg-slate-50

                    px-5
                    py-4

                    sm:flex-row
                    sm:justify-end

                    dark:border-slate-800
                    dark:bg-slate-950/40
                "
            >

                <button
                    type="button"
                    class="
                        btn-close-request-modal

                        min-h-11

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
                        dark:bg-slate-800
                        dark:text-slate-200
                        dark:hover:bg-slate-700
                    "
                >
                    Cancel
                </button>


                <button
                    type="submit"
                    class="
                        min-h-11

                        rounded-xl

                        bg-emerald-700

                        px-5

                        text-sm
                        font-semibold
                        text-white

                        transition

                        hover:bg-emerald-800

                        focus:outline-none
                        focus:ring-4
                        focus:ring-emerald-100

                        dark:bg-emerald-600
                        dark:hover:bg-emerald-500
                        dark:focus:ring-emerald-950
                    "
                >
                    <i class="bi bi-send-fill mr-1"></i>
                    Submit Request
                </button>

            </div>

        </form>

    </div>

</div>


<!-- =========================================================
     EVENT DETAILS MODAL
     ========================================================= -->

<div
    id="eventModal"
    class="
        fixed
        inset-0
        z-[210]

        hidden
        items-center
        justify-center

        bg-slate-950/60

        p-4

        backdrop-blur-sm
    "
    aria-hidden="true"
>

    <div
        class="
            w-full
            max-w-2xl

            overflow-hidden

            rounded-2xl

            border
            border-slate-200

            bg-white

            shadow-2xl

            dark:border-slate-700
            dark:bg-slate-900
        "
    >

        <div
            class="
                flex
                items-center
                justify-between
                gap-3

                border-b
                border-slate-200

                px-5
                py-4

                dark:border-slate-800
            "
        >

            <div>

                <h2
                    id="eventModalTitle"
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
                            bi-calendar-event-fill
                            text-emerald-700

                            dark:text-emerald-400
                        "
                    ></i>

                    Reserved
                </h2>

                <p
                    class="
                        mt-1

                        text-xs
                        text-slate-500

                        dark:text-slate-400
                    "
                >
                    Approved facility reservation
                </p>

            </div>


            <button
                type="button"
                class="
                    btn-close-event-modal

                    flex
                    h-10 w-10
                    items-center
                    justify-center

                    rounded-xl

                    text-lg
                    text-slate-500

                    hover:bg-slate-100
                    hover:text-slate-800

                    dark:text-slate-400
                    dark:hover:bg-slate-800
                    dark:hover:text-slate-100
                "
                aria-label="Close reservation details"
            >
                <i class="bi bi-x-lg"></i>
            </button>

        </div>


        <div
            class="
                max-h-[75vh]
                overflow-y-auto

                p-5
            "
        >

            <div
                class="
                    grid
                    gap-4

                    sm:grid-cols-2
                "
            >

                <div
                    class="
                        rounded-xl

                        bg-slate-50

                        p-4

                        dark:bg-slate-800/60
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
                        Start
                    </p>

                    <p
                        id="eventModalStart"
                        class="
                            mt-1

                            font-semibold
                            text-slate-800

                            dark:text-slate-200
                        "
                    >
                        —
                    </p>
                </div>


                <div
                    class="
                        rounded-xl

                        bg-slate-50

                        p-4

                        dark:bg-slate-800/60
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
                        End
                    </p>

                    <p
                        id="eventModalEnd"
                        class="
                            mt-1

                            font-semibold
                            text-slate-800

                            dark:text-slate-200
                        "
                    >
                        —
                    </p>
                </div>


                <div
                    class="
                        rounded-xl

                        bg-slate-50

                        p-4

                        dark:bg-slate-800/60
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
                        Facility
                    </p>

                    <p
                        id="eventModalFacility"
                        class="
                            mt-1

                            font-semibold
                            text-slate-800

                            dark:text-slate-200
                        "
                    >
                        —
                    </p>
                </div>


                <div
                    class="
                        rounded-xl

                        bg-slate-50

                        p-4

                        dark:bg-slate-800/60
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
                        Status
                    </p>

                    <span
                        id="eventModalStatus"
                        class="
                            mt-2

                            inline-flex
                            items-center
                            gap-1.5

                            rounded-lg

                            bg-emerald-50

                            px-2.5
                            py-1.5

                            text-xs
                            font-bold
                            text-emerald-700

                            ring-1
                            ring-emerald-200

                            dark:bg-emerald-950/40
                            dark:text-emerald-300
                            dark:ring-emerald-900
                        "
                    >
                        <i class="bi bi-check-circle-fill"></i>
                        APPROVED
                    </span>
                </div>


                <div
                    class="
                        rounded-xl

                        bg-slate-50

                        p-4

                        dark:bg-slate-800/60
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
                        Estimated Amount
                    </p>

                    <p
                        id="eventModalAmount"
                        class="
                            mt-1

                            font-semibold
                            text-slate-800

                            dark:text-slate-200
                        "
                    >
                        —
                    </p>
                </div>


                <div
                    class="
                        rounded-xl

                        bg-slate-50

                        p-4

                        dark:bg-slate-800/60
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
                        Guest Count
                    </p>

                    <p
                        id="eventModalGuests"
                        class="
                            mt-1

                            font-semibold
                            text-slate-800

                            dark:text-slate-200
                        "
                    >
                        —
                    </p>
                </div>


                <div
                    class="
                        rounded-xl

                        bg-slate-50

                        p-4

                        sm:col-span-2

                        dark:bg-slate-800/60
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
                        Purpose
                    </p>

                    <p
                        id="eventModalPurpose"
                        class="
                            mt-1
                            break-words

                            font-semibold
                            text-slate-800

                            dark:text-slate-200
                        "
                    >
                        —
                    </p>
                </div>


                <div
                    class="
                        rounded-xl

                        bg-slate-50

                        p-4

                        sm:col-span-2

                        dark:bg-slate-800/60
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
                        Notes
                    </p>

                    <p
                        id="eventModalNotes"
                        class="
                            mt-1
                            whitespace-pre-wrap
                            break-words

                            text-sm
                            leading-6
                            text-slate-700

                            dark:text-slate-300
                        "
                    >
                        —
                    </p>
                </div>

            </div>


            <div
                class="
                    mt-4

                    flex
                    items-start
                    gap-3

                    rounded-xl

                    border
                    border-amber-200

                    bg-amber-50

                    p-3

                    text-sm
                    leading-6
                    text-amber-800

                    dark:border-amber-900
                    dark:bg-amber-950/40
                    dark:text-amber-300
                "
            >
                <i
                    class="
                        bi
                        bi-exclamation-triangle-fill

                        mt-0.5

                        shrink-0
                    "
                ></i>

                <span>
                    This date and time is already reserved.
                    Please choose another schedule.
                </span>
            </div>

        </div>


        <div
            class="
                flex
                justify-end

                border-t
                border-slate-200

                bg-slate-50

                px-5
                py-4

                dark:border-slate-800
                dark:bg-slate-950/40
            "
        >

            <button
                type="button"
                class="
                    btn-close-event-modal

                    min-h-11

                    rounded-xl

                    border
                    border-slate-300

                    bg-white

                    px-4

                    text-sm
                    font-semibold
                    text-slate-700

                    hover:bg-slate-100

                    dark:border-slate-700
                    dark:bg-slate-800
                    dark:text-slate-200
                    dark:hover:bg-slate-700
                "
            >
                Close
            </button>

        </div>

    </div>

</div>


<script>
/*
|--------------------------------------------------------------------------
| Reusable modal helpers
|--------------------------------------------------------------------------
*/

function openModal(modal) {

    if (!modal) {
        return;
    }

    modal.classList.remove(
        'hidden'
    );

    modal.classList.add(
        'flex'
    );

    modal.setAttribute(
        'aria-hidden',
        'false'
    );

    document.body.classList.add(
        'overflow-hidden'
    );
}


function closeModal(modal) {

    if (!modal) {
        return;
    }

    modal.classList.add(
        'hidden'
    );

    modal.classList.remove(
        'flex'
    );

    modal.setAttribute(
        'aria-hidden',
        'true'
    );

    if (
        !document.querySelector(
            '#requestModal.flex, #eventModal.flex'
        )
    ) {
        document.body.classList.remove(
            'overflow-hidden'
        );
    }
}


/*
|--------------------------------------------------------------------------
| Request Booking Modal
|--------------------------------------------------------------------------
*/

(function () {

    const modal =
        document.getElementById(
            'requestModal'
        );

    const openButton =
        document.getElementById(
            'openRequestModal'
        );

    const closeButtons =
        document.querySelectorAll(
            '.btn-close-request-modal'
        );


    openButton?.addEventListener(
        'click',
        function () {

            openModal(modal);
        }
    );


    closeButtons.forEach(
        function (button) {

            button.addEventListener(
                'click',
                function () {

                    closeModal(modal);
                }
            );
        }
    );


    modal?.addEventListener(
        'click',
        function (event) {

            if (event.target === modal) {
                closeModal(modal);
            }
        }
    );

})();


/*
|--------------------------------------------------------------------------
| Event Details Modal
|--------------------------------------------------------------------------
*/

const eventModal =
    document.getElementById(
        'eventModal'
    );

document
    .querySelectorAll(
        '.btn-close-event-modal'
    )
    .forEach(
        function (button) {

            button.addEventListener(
                'click',
                function () {

                    closeModal(
                        eventModal
                    );
                }
            );
        }
    );


eventModal?.addEventListener(
    'click',
    function (event) {

        if (event.target === eventModal) {

            closeModal(
                eventModal
            );
        }
    }
);


/*
|--------------------------------------------------------------------------
| Escape closes open modal
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'keydown',
    function (event) {

        if (event.key !== 'Escape') {
            return;
        }

        const requestModal =
            document.getElementById(
                'requestModal'
            );

        if (
            requestModal &&
            !requestModal
                .classList
                .contains('hidden')
        ) {

            closeModal(
                requestModal
            );

            return;
        }


        if (
            eventModal &&
            !eventModal
                .classList
                .contains('hidden')
        ) {

            closeModal(
                eventModal
            );
        }
    }
);


/*
|--------------------------------------------------------------------------
| Clubhouse guest count
|--------------------------------------------------------------------------
*/

(function () {

    const facilitySelect =
        document.getElementById(
            'facilitySelect'
        );

    const guestWrap =
        document.getElementById(
            'guestCountWrap'
        );

    const guestInput =
        document.getElementById(
            'guestCountInput'
        );


    function toggleGuest() {

        if (
            !facilitySelect ||
            !guestWrap ||
            !guestInput
        ) {
            return;
        }

        const isClub =
            facilitySelect.value ===
            'clubhouse';

        guestWrap.classList.toggle(
            'hidden',
            !isClub
        );

        guestInput.required =
            isClub;

        if (!isClub) {
            guestInput.value = '';
        }
    }


    facilitySelect
        ?.addEventListener(
            'change',
            toggleGuest
        );


    toggleGuest();

})();


/*
|--------------------------------------------------------------------------
| FullCalendar
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const calendarEl =
            document.getElementById(
                'rentCalendar'
            );

        if (!calendarEl) {
            return;
        }


        const titleEl =
            document.getElementById(
                'eventModalTitle'
            );

        const startEl =
            document.getElementById(
                'eventModalStart'
            );

        const endEl =
            document.getElementById(
                'eventModalEnd'
            );

        const facilityEl =
            document.getElementById(
                'eventModalFacility'
            );

        const statusEl =
            document.getElementById(
                'eventModalStatus'
            );

        const notesEl =
            document.getElementById(
                'eventModalNotes'
            );

        const amountEl =
            document.getElementById(
                'eventModalAmount'
            );

        const purposeEl =
            document.getElementById(
                'eventModalPurpose'
            );

        const guestsEl =
            document.getElementById(
                'eventModalGuests'
            );


        const isMobile =
            window.innerWidth < 768;


        const calendar =
            new FullCalendar.Calendar(
                calendarEl,
                {
                    initialView:
                        'dayGridMonth',

                    height:
                        'auto',

                    headerToolbar:
                        isMobile
                            ? {
                                left: 'prev,next',
                                center: 'title',
                                right: 'today'
                            }
                            : {
                                left: 'prev,next today',
                                center: 'title',
                                right: 'dayGridMonth,timeGridWeek,timeGridDay'
                            },

                    buttonText: {
                        today: 'Today',
                        month: 'Month',
                        week: 'Week',
                        day: 'Day'
                    },

                    dayMaxEvents:
                        true,

                    events:
                        <?= json_encode(
                            'homeowner_rental_events.php?facility=' .
                            rawurlencode($facility)
                        ) ?>,

                    eventClick:
                        function (info) {

                            info.jsEvent.preventDefault();

                            const ev =
                                info.event;

                            const ep =
                                ev.extendedProps
                                || {};


                            if (titleEl) {

                                titleEl.innerHTML =
                                    `
                                        <i
                                            class="
                                                bi
                                                bi-calendar-event-fill
                                                text-emerald-700
                                                dark:text-emerald-400
                                            "
                                        ></i>

                                        ${escapeHtml(
                                            ev.title ||
                                            'Reserved'
                                        )}
                                    `;
                            }


                            if (startEl) {

                                startEl.textContent =
                                    ev.start
                                        ? ev.start.toLocaleString()
                                        : '—';
                            }


                            if (endEl) {

                                endEl.textContent =
                                    ev.end
                                        ? ev.end.toLocaleString()
                                        : '—';
                            }


                            if (facilityEl) {

                                facilityEl.textContent =
                                    ep.facilityLabel
                                        ? ep.facilityLabel
                                        : (
                                            ep.facility
                                                ? ep.facility
                                                : <?= json_encode(
                                                    facility_label(
                                                        $facility
                                                    )
                                                ) ?>
                                        );
                            }


                            if (statusEl) {

                                const status =
                                    (
                                        ep.status ||
                                        'approved'
                                    )
                                        .toString()
                                        .toUpperCase();

                                statusEl.innerHTML =
                                    `
                                        <i class="bi bi-check-circle-fill"></i>
                                        ${escapeHtml(status)}
                                    `;
                            }


                            if (notesEl) {

                                notesEl.textContent =
                                    ep.notes
                                        ? ep.notes
                                        : '—';
                            }


                            if (amountEl) {

                                amountEl.textContent =
                                    (
                                        ep.amount != null &&
                                        ep.amount !== ''
                                    )
                                        ? (
                                            '₱' +
                                            Number(
                                                ep.amount
                                            ).toFixed(2)
                                        )
                                        : '—';
                            }


                            if (purposeEl) {

                                purposeEl.textContent =
                                    ep.purpose
                                        ? ep.purpose
                                        : '—';
                            }


                            if (guestsEl) {

                                guestsEl.textContent =
                                    (
                                        ep.guest_count != null &&
                                        ep.guest_count !== ''
                                    )
                                        ? ep.guest_count
                                        : '—';
                            }


                            openModal(
                                eventModal
                            );
                        }
                }
            );


        calendar.render();

    }
);


/*
|--------------------------------------------------------------------------
| Tiny HTML escape helper for event title
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

    const div =
        document.createElement(
            'div'
        );

    div.textContent =
        String(
            value ?? ''
        );

    return div.innerHTML;
}


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
| Mobile Sidebar
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


/*
|--------------------------------------------------------------------------
| Close inline flash message
|--------------------------------------------------------------------------
*/

document
    .querySelectorAll(
        '.btn-close-flash'
    )
    .forEach(
        function (button) {

            button.addEventListener(
                'click',
                function () {

                    button
                        .closest(
                            '.page-flash'
                        )
                        ?.remove();
                }
            );
        }
    );
</script>

</body>
</html>