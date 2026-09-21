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


function permit_status_label(
    string $status,
    string $paymentStatus = ''
): string {

    $status =
        strtolower(
            trim($status)
        );

    $paymentStatus =
        strtolower(
            trim($paymentStatus)
        );

    if (
        $status === 'pending' &&
        $paymentStatus === 'for payment'
    ) {
        return 'For Payment';
    }

    return $status !== ''
        ? ucfirst($status)
        : 'Unknown';
}


function permit_status_classes(
    string $status,
    string $paymentStatus = ''
): string {

    $status =
        strtolower(
            trim($status)
        );

    $paymentStatus =
        strtolower(
            trim($paymentStatus)
        );

    if ($status === 'active') {
        return
            'bg-emerald-50 text-emerald-700 ring-emerald-200 ' .
            'dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900';
    }

    if (
        $status === 'pending' &&
        $paymentStatus === 'for payment'
    ) {
        return
            'bg-blue-50 text-blue-700 ring-blue-200 ' .
            'dark:bg-blue-950/40 dark:text-blue-300 dark:ring-blue-900';
    }

    if ($status === 'pending') {
        return
            'bg-amber-50 text-amber-700 ring-amber-200 ' .
            'dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900';
    }

    if (
        in_array(
            $status,
            ['rejected', 'revoked'],
            true
        )
    ) {
        return
            'bg-red-50 text-red-700 ring-red-200 ' .
            'dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900';
    }

    if ($status === 'expired') {
        return
            'bg-amber-50 text-amber-700 ring-amber-200 ' .
            'dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900';
    }

    return
        'bg-slate-100 text-slate-600 ring-slate-200 ' .
        'dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700';
}


function permit_status_icon(
    string $status,
    string $paymentStatus = ''
): string {

    $status =
        strtolower(
            trim($status)
        );

    $paymentStatus =
        strtolower(
            trim($paymentStatus)
        );

    if ($status === 'active') {
        return 'bi-check-circle-fill';
    }

    if (
        $status === 'pending' &&
        $paymentStatus === 'for payment'
    ) {
        return 'bi-credit-card-fill';
    }

    if ($status === 'pending') {
        return 'bi-clock-fill';
    }

    if ($status === 'rejected') {
        return 'bi-x-circle-fill';
    }

    if ($status === 'revoked') {
        return 'bi-slash-circle-fill';
    }

    if ($status === 'expired') {
        return 'bi-calendar-x-fill';
    }

    return 'bi-info-circle-fill';
}


function duration_label(
    string $duration
): string {

    $map = [
        '1_month'  => '1 Month',
        '3_months' => '3 Months',
        '6_months' => '6 Months',
        '1_year'   => '1 Year',
    ];

    return $map[$duration]
        ?? $duration;
}


function payment_label(
    string $payment
): string {

    $map = [
        'online' => 'Online Payment',
        'cash'   => 'Cash / Physical Payment',
    ];

    return $map[$payment]
        ?? $payment;
}


function payment_status_label(
    string $status
): string {

    $status =
        strtolower(
            trim($status)
        );

    if (
        $status === 'unpaid' ||
        $status === 'not paid'
    ) {
        return 'Not Paid';
    }

    if ($status === 'paid') {
        return 'Paid';
    }

    if ($status === 'for payment') {
        return 'For Payment';
    }

    if ($status === 'pending') {
        return 'Pending';
    }

    return $status !== ''
        ? ucfirst($status)
        : 'Unknown';
}


function payment_status_classes(
    string $status
): string {

    $status =
        strtolower(
            trim($status)
        );

    if ($status === 'paid') {
        return
            'bg-emerald-50 text-emerald-700 ring-emerald-200 ' .
            'dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900';
    }

    if ($status === 'for payment') {
        return
            'bg-blue-50 text-blue-700 ring-blue-200 ' .
            'dark:bg-blue-950/40 dark:text-blue-300 dark:ring-blue-900';
    }

    if ($status === 'pending') {
        return
            'bg-amber-50 text-amber-700 ring-amber-200 ' .
            'dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900';
    }

    return
        'bg-red-50 text-red-700 ring-red-200 ' .
        'dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900';
}


function vehicle_type_label(
    string $type
): string {

    $map = [
        'car'        => 'Car',
        'motorcycle' => 'Motorcycle',
        'ebike'      => 'E-Bike',
    ];

    return $map[$type]
        ?? ucfirst($type);
}


function days_until_expiry(
    ?string $validUntil
): ?int {

    if (empty($validUntil)) {
        return null;
    }

    try {

        $today =
            new DateTime('today');

        $expiry =
            new DateTime($validUntil);

        if ($expiry < $today) {
            return null;
        }

        return (int)$today
            ->diff($expiry)
            ->format('%a');

    } catch (Exception $e) {

        return null;
    }
}


function can_renew_now(
    ?string $validUntil,
    int $daysBeforeExpiry = 30
): bool {

    $days =
        days_until_expiry(
            $validUntil
        );

    return
        $days !== null &&
        $days <= $daysBeforeExpiry;
}


/*
|--------------------------------------------------------------------------
| Session / account
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
}


/*
|--------------------------------------------------------------------------
| Basic page values
|--------------------------------------------------------------------------
*/

if (
    (int)$user['must_change_password'] === 1 &&
    !$isTenant
) {
    header(
        "Location: homeowner_dashboard.php"
    );
    exit;
}


$phase =
    (string)$user['phase'];

$houseLot =
    (string)(
        $user['house_lot_number']
        ?? ''
    );

$pageTitle =
    "Parking • " .
    $phase;

$yearNow =
    (int)date('Y');

$renewalWindowDays =
    30;


/*
|--------------------------------------------------------------------------
| Keep this homeowner's permit lifecycle current
|--------------------------------------------------------------------------
|
| Admin pages already auto-expire permits. Doing the same here ensures
| an expired permit is reflected correctly even if no admin has opened
| the parking module today.
|
*/

$stmt = $conn->prepare("
    UPDATE parking_permits
    SET status = 'expired'
    WHERE homeowner_id = ?
      AND phase = ?
      AND status = 'active'
      AND LOWER(COALESCE(payment_status, 'paid')) = 'paid'
      AND valid_until IS NOT NULL
      AND valid_until < CURDATE()
");

$stmt->bind_param(
    "is",
    $hid,
    $phase
);

$stmt->execute();
$stmt->close();


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


/*
|--------------------------------------------------------------------------
| Page message
|--------------------------------------------------------------------------
*/

$msg =
    '';

$msgType =
    'success';


if (
    isset($_GET['paid']) &&
    isset($_GET['active'])
) {

    $msgType =
        'success';

    $msg =
        'Online payment recorded successfully. Your parking permit is now active.';

} elseif (isset($_GET['paid'])) {

    $msgType =
        'success';

    $msg =
        'Online payment recorded successfully.';

} elseif (isset($_GET['cancelled'])) {

    $msgType =
        'warning';

    $msg =
        'Online payment was cancelled or not completed.';

} elseif (isset($_GET['waiting_approval'])) {

    $msgType =
        'warning';

    $msg =
        'Your permit is not yet open for online payment. Please wait for admin approval first.';

} elseif (isset($_GET['rejected'])) {

    $msgType =
        'danger';

    $msg =
        'This permit request was rejected.';

} elseif (isset($_GET['revoked'])) {

    $msgType =
        'danger';

    $msg =
        'This permit has been revoked.';

} elseif (isset($_GET['expired'])) {

    $msgType =
        'warning';

    $msg =
        'This permit has already expired.';
}


/*
|--------------------------------------------------------------------------
| Latest non-active request
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT *
    FROM parking_permits
    WHERE homeowner_id = ?
      AND phase = ?
      AND status <> 'active'
    ORDER BY id DESC
    LIMIT 1
");

$stmt->bind_param(
    "is",
    $hid,
    $phase
);

$stmt->execute();

$latestRequest =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();


/*
|--------------------------------------------------------------------------
| Active permits
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT *
    FROM parking_permits
    WHERE homeowner_id = ?
      AND phase = ?
      AND status = 'active'
      AND LOWER(
            COALESCE(
                payment_status,
                'paid'
            )
          ) = 'paid'
      AND valid_from IS NOT NULL
      AND valid_from <= CURDATE()
      AND valid_until >= CURDATE()
    ORDER BY
        valid_until DESC,
        id DESC
");

$stmt->bind_param(
    "is",
    $hid,
    $phase
);

$stmt->execute();

$activePermits =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();


/*
|--------------------------------------------------------------------------
| Upcoming paid renewals / permits
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT *
    FROM parking_permits
    WHERE homeowner_id = ?
      AND phase = ?
      AND status = 'active'
      AND LOWER(
            COALESCE(
                payment_status,
                'paid'
            )
          ) = 'paid'
      AND valid_from IS NOT NULL
      AND valid_from > CURDATE()
      AND valid_until >= valid_from
    ORDER BY
        valid_from ASC,
        id ASC
");

$stmt->bind_param(
    "is",
    $hid,
    $phase
);

$stmt->execute();

$upcomingPermits =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();

$upcomingRenewalBySource = [];

foreach ($upcomingPermits as $upcomingPermitRow) {
    $sourceId =
        (int)(
            $upcomingPermitRow['renew_of_id']
            ?? 0
        );

    if ($sourceId > 0) {
        $upcomingRenewalBySource[$sourceId] =
            $upcomingPermitRow;
    }
}


/*
|--------------------------------------------------------------------------
| Permit history
|--------------------------------------------------------------------------
|
| Terminal permit records stay visible to the homeowner/tenant so an
| admin rejection/revocation reason is never lost from the UI.
|
*/

$stmt = $conn->prepare("
    SELECT *
    FROM parking_permits
    WHERE homeowner_id = ?
      AND phase = ?
      AND status IN ('rejected', 'revoked', 'expired')
    ORDER BY
        COALESCE(updated_at, approved_at, requested_at) DESC,
        id DESC
    LIMIT 50
");

$stmt->bind_param(
    "is",
    $hid,
    $phase
);

$stmt->execute();

$permitHistory =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();

$historyCount =
    count($permitHistory);


/*
|--------------------------------------------------------------------------
| Open violation count
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT COUNT(*) c
    FROM parking_violations
    WHERE homeowner_id = ?
      AND phase = ?
      AND status = 'open'
");

$stmt->bind_param(
    "is",
    $hid,
    $phase
);

$stmt->execute();

$unpaidCount =
    (int)(
        $stmt
            ->get_result()
            ->fetch_assoc()['c']
        ?? 0
    );

$stmt->close();


/*
|--------------------------------------------------------------------------
| Per-permit violation counts
|--------------------------------------------------------------------------
*/

$violationCountsByPermit = [];

if (!empty($activePermits)) {

    $permitIds =
        array_map(
            fn($p) => (int)$p['id'],
            $activePermits
        );

    $permitIds =
        array_values(
            array_filter(
                $permitIds,
                fn($v) => $v > 0
            )
        );


    if ($permitIds) {

        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($permitIds),
                    '?'
                )
            );

        $types =
            str_repeat(
                'i',
                count($permitIds)
            );


        $sql = "
            SELECT
                permit_id,
                COUNT(*) AS cnt
            FROM parking_violations
            WHERE homeowner_id = ?
              AND phase = ?
              AND status = 'open'
              AND permit_id IN ($placeholders)
            GROUP BY permit_id
        ";


        $stmt =
            $conn->prepare(
                $sql
            );


        if ($stmt) {

            $bindTypes =
                "is" .
                $types;

            $bindValues =
                array_merge(
                    [
                        $hid,
                        $phase
                    ],
                    $permitIds
                );


            $refs = [];
            $refs[] = &$bindTypes;

            foreach (
                $bindValues as $k => $v
            ) {
                $refs[] =
                    &$bindValues[$k];
            }


            call_user_func_array(
                [
                    $stmt,
                    'bind_param'
                ],
                $refs
            );


            $stmt->execute();

            $res =
                $stmt->get_result();


            while (
                $row =
                $res->fetch_assoc()
            ) {

                $violationCountsByPermit[
                    (int)$row['permit_id']
                ] =
                    (int)$row['cnt'];
            }


            $stmt->close();
        }
    }
}


/*
|--------------------------------------------------------------------------
| Sidebar state
|--------------------------------------------------------------------------
*/

$activePage =
    basename(
        $_SERVER['PHP_SELF']
        ?? 'homeowner_parking.php'
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

$chatPages = [
    'homeowner_public_chat.php'
];

$chatOpen =
    in_array(
        $activePage,
        $chatPages,
        true
    );


/*
|--------------------------------------------------------------------------
| Message styles
|--------------------------------------------------------------------------
*/

$msgClasses =
    match ($msgType) {

        'success' =>
            [
                'wrap' =>
                    'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40',
                'iconWrap' =>
                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
                'title' =>
                    'text-emerald-900 dark:text-emerald-200',
                'text' =>
                    'text-emerald-800 dark:text-emerald-300',
                'icon' =>
                    'bi-check-circle-fill'
            ],

        'danger' =>
            [
                'wrap' =>
                    'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40',
                'iconWrap' =>
                    'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
                'title' =>
                    'text-red-900 dark:text-red-200',
                'text' =>
                    'text-red-800 dark:text-red-300',
                'icon' =>
                    'bi-x-circle-fill'
            ],

        default =>
            [
                'wrap' =>
                    'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40',
                'iconWrap' =>
                    'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
                'title' =>
                    'text-amber-900 dark:text-amber-200',
                'text' =>
                    'text-amber-800 dark:text-amber-300',
                'icon' =>
                    'bi-exclamation-triangle-fill'
            ]
    };
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


<!-- Tailwind manual dark mode -->
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

                <!-- Theme -->
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
                        Vehicle & Permit Management
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
                        Parking Overview
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
                        View your active parking permits, renewal status,
                        and open parking violations.
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
             PAGE MESSAGE
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

                    p-4

                    <?= $msgClasses['wrap'] ?>
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

                        text-lg

                        <?= $msgClasses['iconWrap'] ?>
                    "
                >
                    <i
                        class="
                            bi
                            <?= esc($msgClasses['icon']) ?>
                        "
                    ></i>
                </div>


                <div class="min-w-0 flex-1">

                    <p
                        class="
                            font-bold

                            <?= $msgClasses['title'] ?>
                        "
                    >
                        Parking Update
                    </p>

                    <p
                        class="
                            mt-1

                            text-sm
                            leading-6

                            <?= $msgClasses['text'] ?>
                        "
                    >
                        <?= esc($msg) ?>
                    </p>

                </div>


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

                        text-slate-500

                        transition

                        hover:bg-black/5

                        dark:text-slate-400
                        dark:hover:bg-white/10
                    "
                    aria-label="Close message"
                >
                    <i class="bi bi-x-lg"></i>
                </button>

            </div>

        <?php endif; ?>


        <!-- =================================================
             SUMMARY
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

            <!-- Active permits -->
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
                    <i class="bi bi-card-checklist"></i>
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
                    Active Permits
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
                    <?= count($activePermits) ?>
                </p>

            </div>


            <!-- Open violations -->
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
                    <i class="bi bi-exclamation-triangle-fill"></i>
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
                    Open Violations
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
                    <?= (int)$unpaidCount ?>
                </p>

            </div>


            <!-- Sticker year -->
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

                        bg-blue-100

                        text-xl
                        text-blue-700

                        dark:bg-blue-950/60
                        dark:text-blue-300
                    "
                >
                    <i class="bi bi-calendar-check-fill"></i>
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
                    Sticker Year
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
                    <?= (int)$yearNow ?>
                </p>

            </div>


            <!-- Renewal window -->
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
                    <i class="bi bi-arrow-repeat"></i>
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
                    Renewal Window
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
                    <?= (int)$renewalWindowDays ?>

                    <span
                        class="
                            text-base
                            font-semibold
                            text-slate-400

                            dark:text-slate-500
                        "
                    >
                        days
                    </span>
                </p>

            </div>

        </section>


        <!-- =================================================
             QUICK ACTIONS
             ================================================= -->

        <section
            class="
                mb-6

                grid
                gap-3

                sm:grid-cols-2
                xl:grid-cols-3
            "
        >

            <a
                href="homeowner_parking_permit.php"
                class="
                    group

                    flex
                    min-h-16
                    items-center
                    gap-3

                    rounded-2xl

                    border
                    border-slate-200

                    bg-white

                    px-4
                    py-3

                    shadow-sm
                    transition

                    hover:-translate-y-0.5
                    hover:border-emerald-200
                    hover:shadow-md

                    dark:border-slate-800
                    dark:bg-slate-900
                    dark:hover:border-emerald-800
                "
            >

                <span
                    class="
                        flex
                        h-11 w-11
                        shrink-0
                        items-center
                        justify-center

                        rounded-xl

                        bg-emerald-100

                        text-lg
                        text-emerald-700

                        dark:bg-emerald-950/60
                        dark:text-emerald-300
                    "
                >
                    <i class="bi bi-card-checklist"></i>
                </span>

                <span class="min-w-0">

                    <span
                        class="
                            block

                            font-bold
                            text-slate-900

                            dark:text-slate-100
                        "
                    >
                        Apply / Renew Permit
                    </span>

                    <span
                        class="
                            mt-0.5
                            block

                            text-xs
                            text-slate-500

                            dark:text-slate-400
                        "
                    >
                        Submit permit details and requirements.
                    </span>

                </span>

            </a>


            <a
                href="homeowner_parking_violations.php"
                class="
                    group

                    flex
                    min-h-16
                    items-center
                    gap-3

                    rounded-2xl

                    border
                    border-slate-200

                    bg-white

                    px-4
                    py-3

                    shadow-sm
                    transition

                    hover:-translate-y-0.5
                    hover:border-red-200
                    hover:shadow-md

                    dark:border-slate-800
                    dark:bg-slate-900
                    dark:hover:border-red-900
                "
            >

                <span
                    class="
                        flex
                        h-11 w-11
                        shrink-0
                        items-center
                        justify-center

                        rounded-xl

                        bg-red-100

                        text-lg
                        text-red-700

                        dark:bg-red-950/60
                        dark:text-red-300
                    "
                >
                    <i class="bi bi-receipt-cutoff"></i>
                </span>

                <span class="min-w-0">

                    <span
                        class="
                            block

                            font-bold
                            text-slate-900

                            dark:text-slate-100
                        "
                    >
                        My Violations
                    </span>

                    <span
                        class="
                            mt-0.5
                            block

                            text-xs
                            text-slate-500

                            dark:text-slate-400
                        "
                    >
                        Review your open parking violations.
                    </span>

                </span>

            </a>


            <div
                class="
                    flex
                    min-h-16
                    items-center
                    gap-3

                    rounded-2xl

                    border
                    border-slate-200

                    bg-white

                    px-4
                    py-3

                    shadow-sm

                    sm:col-span-2
                    xl:col-span-1

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >

                <span
                    class="
                        flex
                        h-11 w-11
                        shrink-0
                        items-center
                        justify-center

                        rounded-xl

                        bg-slate-100

                        text-lg
                        text-slate-600

                        dark:bg-slate-800
                        dark:text-slate-300
                    "
                >
                    <i class="bi bi-house-door-fill"></i>
                </span>

                <span class="min-w-0">

                    <span
                        class="
                            block

                            font-bold
                            text-slate-900

                            dark:text-slate-100
                        "
                    >
                        <?= esc($houseLot) ?>
                    </span>

                    <span
                        class="
                            mt-0.5
                            block

                            text-xs
                            text-slate-500

                            dark:text-slate-400
                        "
                    >
                        Registered property
                    </span>

                </span>

            </div>

        </section>


        <!-- =================================================
             PERMIT STATUS
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
                                bi-car-front-fill
                                text-emerald-700

                                dark:text-emerald-400
                            "
                        ></i>

                        Your Permit Status
                    </h2>

                    <p
                        class="
                            mt-1

                            text-sm
                            text-slate-500

                            dark:text-slate-400
                        "
                    >
                        Current permits, upcoming paid renewals, and your latest permit request.
                    </p>

                </div>


                <div class="flex flex-wrap items-center gap-2">
                    <?php if (!empty($activePermits)): ?>
                        <span
                            class="
                                inline-flex w-fit items-center gap-2
                                rounded-xl bg-emerald-50 px-3 py-2
                                text-xs font-bold text-emerald-700
                                ring-1 ring-emerald-200
                                dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900
                            "
                        >
                            <i class="bi bi-check-circle-fill"></i>
                            <?= count($activePermits) ?> Current
                        </span>
                    <?php endif; ?>

                    <?php if (!empty($upcomingPermits)): ?>
                        <span
                            class="
                                inline-flex w-fit items-center gap-2
                                rounded-xl bg-violet-50 px-3 py-2
                                text-xs font-bold text-violet-700
                                ring-1 ring-violet-200
                                dark:bg-violet-950/40 dark:text-violet-300 dark:ring-violet-900
                            "
                        >
                            <i class="bi bi-calendar-check-fill"></i>
                            <?= count($upcomingPermits) ?> Upcoming
                        </span>
                    <?php endif; ?>
                </div>

            </div>


            <div class="p-5">

                <?php if (!empty($activePermits) || !empty($upcomingPermits)): ?>

                    <div class="space-y-4">

                        <?php foreach ($activePermits as $permit): ?>

                            <?php

                            $permitId =
                                (int)$permit['id'];

                            $permitViolationCount =
                                (int)(
                                    $violationCountsByPermit[
                                        $permitId
                                    ]
                                    ?? 0
                                );

                            $daysRemaining =
                                days_until_expiry(
                                    $permit['valid_until']
                                    ?? null
                                );

                            $scheduledRenewal =
                                $upcomingRenewalBySource[
                                    $permitId
                                ]
                                ?? null;

                            $canRenew =
                                !$scheduledRenewal &&
                                can_renew_now(
                                    $permit['valid_until']
                                    ?? null,
                                    $renewalWindowDays
                                );

                            $permitStatus =
                                (string)(
                                    $permit['status']
                                    ?? ''
                                );

                            $permitPaymentStatus =
                                (string)(
                                    $permit['payment_status']
                                    ?? ''
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

                                <!-- Permit header -->
                                <div
                                    class="
                                        flex
                                        flex-col
                                        gap-3

                                        border-b
                                        border-slate-200

                                        px-4
                                        py-4

                                        sm:flex-row
                                        sm:items-center
                                        sm:justify-between

                                        dark:border-slate-700
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
                                            Parking Permit
                                        </p>

                                        <h3
                                            class="
                                                mt-1

                                                text-lg
                                                font-bold
                                                text-slate-900

                                                dark:text-slate-100
                                            "
                                        >
                                            Permit #
                                            <?= esc(
                                                $permit['permit_no']
                                                ?? '—'
                                            ) ?>
                                        </h3>

                                    </div>


                                    <div
                                        class="
                                            flex
                                            flex-wrap
                                            items-center
                                            gap-2
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

                                                <?= permit_status_classes(
                                                    $permitStatus,
                                                    $permitPaymentStatus
                                                ) ?>
                                            "
                                        >
                                            <i
                                                class="
                                                    bi
                                                    <?= esc(
                                                        permit_status_icon(
                                                            $permitStatus,
                                                            $permitPaymentStatus
                                                        )
                                                    ) ?>
                                                "
                                            ></i>

                                            <?= esc(
                                                permit_status_label(
                                                    $permitStatus,
                                                    $permitPaymentStatus
                                                )
                                            ) ?>
                                        </span>


                                        <span
                                            class="
                                                inline-flex
                                                items-center
                                                gap-1.5

                                                rounded-lg

                                                bg-orange-50

                                                px-2.5
                                                py-1.5

                                                text-xs
                                                font-bold
                                                text-orange-700

                                                ring-1
                                                ring-orange-200

                                                dark:bg-orange-950/40
                                                dark:text-orange-300
                                                dark:ring-orange-900
                                            "
                                        >
                                            <i class="bi bi-exclamation-triangle-fill"></i>

                                            <?= $permitViolationCount ?>
                                            Open Violation<?= $permitViolationCount === 1 ? '' : 's' ?>
                                        </span>

                                    </div>

                                </div>


                                <!-- Vehicle + permit information -->
                                <div
                                    class="
                                        grid
                                        gap-4

                                        p-4

                                        sm:grid-cols-2
                                        lg:grid-cols-3
                                    "
                                >

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Plate Number
                                        </p>
                                        <p class="mt-1 break-words font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($permit['plate_no'] ?? '—') ?>
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
                                                        $permit['vehicle_type']
                                                        ?? 'car'
                                                    )
                                                )
                                            ) ?>
                                        </p>
                                    </div>


                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Vehicle Brand
                                        </p>
                                        <p class="mt-1 break-words font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($permit['vehicle_make'] ?? '—') ?>
                                        </p>
                                    </div>


                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Vehicle Model
                                        </p>
                                        <p class="mt-1 break-words font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($permit['vehicle_model'] ?? '—') ?>
                                        </p>
                                    </div>


                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Vehicle Color
                                        </p>
                                        <p class="mt-1 break-words font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($permit['vehicle_color'] ?? '—') ?>
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
                                                        $permit['permit_duration']
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
                                                        $permit['payment_method']
                                                        ?? ''
                                                    )
                                                )
                                            ) ?>
                                        </p>
                                    </div>


                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Payment Status
                                        </p>

                                        <span
                                            class="
                                                mt-1

                                                inline-flex
                                                items-center

                                                rounded-lg

                                                px-2.5
                                                py-1

                                                text-xs
                                                font-bold

                                                ring-1

                                                <?= payment_status_classes(
                                                    $permitPaymentStatus
                                                ) ?>
                                            "
                                        >
                                            <?= esc(
                                                payment_status_label(
                                                    $permitPaymentStatus
                                                )
                                            ) ?>
                                        </span>
                                    </div>


                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Sticker Year
                                        </p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($permit['sticker_year'] ?? '—') ?>
                                        </p>
                                    </div>


                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Valid From
                                        </p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc(
                                                $permit['valid_from']
                                                ?? (
                                                    $permit['validity_start']
                                                    ?? '—'
                                                )
                                            ) ?>
                                        </p>
                                    </div>


                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Valid Until
                                        </p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc(
                                                $permit['valid_until']
                                                ?? (
                                                    $permit['validity_end']
                                                    ?? '—'
                                                )
                                            ) ?>
                                        </p>
                                    </div>


                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Days Remaining
                                        </p>

                                        <p
                                            class="
                                                mt-1

                                                font-bold

                                                <?= $daysRemaining !== null && $daysRemaining <= $renewalWindowDays
                                                    ? 'text-amber-700 dark:text-amber-300'
                                                    : 'text-slate-800 dark:text-slate-200'
                                                ?>
                                            "
                                        >
                                            <?= $daysRemaining !== null
                                                ? (int)$daysRemaining . ' day' . ($daysRemaining === 1 ? '' : 's')
                                                : 'Expired'
                                            ?>
                                        </p>
                                    </div>

                                </div>


                                <!-- Actions -->
                                <div
                                    class="
                                        flex
                                        flex-col
                                        gap-2

                                        border-t
                                        border-slate-200

                                        bg-white

                                        p-4

                                        sm:flex-row
                                        sm:flex-wrap

                                        dark:border-slate-700
                                        dark:bg-slate-900/70
                                    "
                                >

                                    <?php if ($canRenew): ?>

                                        <a
                                            href="homeowner_parking_permit.php?renew_id=<?= $permitId ?>"
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

                                                transition

                                                hover:bg-emerald-800

                                                dark:bg-emerald-600
                                                dark:hover:bg-emerald-500
                                            "
                                        >
                                            <i class="bi bi-arrow-repeat"></i>
                                            Renew Permit
                                        </a>

                                    <?php elseif ($scheduledRenewal): ?>

                                        <button
                                            type="button"
                                            disabled
                                            class="
                                                inline-flex min-h-11 cursor-not-allowed
                                                items-center justify-center gap-2
                                                rounded-xl bg-violet-50 px-4
                                                text-sm font-semibold text-violet-700
                                                ring-1 ring-violet-200
                                                dark:bg-violet-950/40 dark:text-violet-300 dark:ring-violet-900
                                            "
                                        >
                                            <i class="bi bi-calendar-check-fill"></i>
                                            Renewal Scheduled
                                        </button>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            disabled
                                            class="
                                                inline-flex
                                                min-h-11
                                                cursor-not-allowed
                                                items-center
                                                justify-center
                                                gap-2
                                                rounded-xl
                                                bg-slate-100
                                                px-4
                                                text-sm
                                                font-semibold
                                                text-slate-400
                                                dark:bg-slate-800
                                                dark:text-slate-500
                                            "
                                        >
                                            <i class="bi bi-lock-fill"></i>
                                            Renewal Not Yet Available
                                        </button>

                                    <?php endif; ?>


                                    <a
                                        href="homeowner_parking_violations.php?permit_id=<?= $permitId ?>"
                                        class="
                                            inline-flex
                                            min-h-11
                                            items-center
                                            justify-center
                                            gap-2

                                            rounded-xl

                                            border
                                            border-red-200

                                            bg-white

                                            px-4

                                            text-sm
                                            font-semibold
                                            text-red-700

                                            transition

                                            hover:bg-red-50

                                            dark:border-red-900
                                            dark:bg-slate-900
                                            dark:text-red-300
                                            dark:hover:bg-red-950/40
                                        "
                                    >
                                        <i class="bi bi-receipt-cutoff"></i>
                                        View Violations
                                    </a>


                                    <a
                                        href="homeowner_contract.php?permit_id=<?= $permitId ?>"
                                        class="
                                            inline-flex
                                            min-h-11
                                            items-center
                                            justify-center
                                            gap-2

                                            rounded-xl

                                            border
                                            border-blue-200

                                            bg-blue-50

                                            px-4

                                            text-sm
                                            font-semibold
                                            text-blue-700

                                            transition

                                            hover:bg-blue-100

                                            dark:border-blue-900
                                            dark:bg-blue-950/40
                                            dark:text-blue-300
                                            dark:hover:bg-blue-950/60
                                        "
                                    >
                                        <i class="bi bi-file-earmark-text-fill"></i>
                                        Contract
                                    </a>

                                </div>


                                <?php if (
                                    !$canRenew &&
                                    !$scheduledRenewal &&
                                    $daysRemaining !== null
                                ): ?>

                                    <div
                                        class="
                                            border-t
                                            border-slate-200

                                            bg-slate-50

                                            px-4
                                            py-3

                                            text-xs
                                            leading-5
                                            text-slate-500

                                            dark:border-slate-700
                                            dark:bg-slate-800/60
                                            dark:text-slate-400
                                        "
                                    >
                                        <i
                                            class="
                                                bi
                                                bi-info-circle
                                                mr-1
                                            "
                                        ></i>

                                        Renewal becomes available only within
                                        <?= (int)$renewalWindowDays ?>
                                        days before expiration.
                                    </div>

                                <?php endif; ?>

                            </article>

                        <?php endforeach; ?>

                        <?php foreach ($upcomingPermits as $permit): ?>
                            <article
                                class="
                                    overflow-hidden rounded-2xl
                                    border border-violet-200
                                    bg-violet-50/60
                                    dark:border-violet-900
                                    dark:bg-violet-950/20
                                "
                            >
                                <div
                                    class="
                                        flex flex-col gap-3
                                        border-b border-violet-200
                                        px-4 py-4
                                        sm:flex-row sm:items-center sm:justify-between
                                        dark:border-violet-900
                                    "
                                >
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-violet-700 dark:text-violet-400">
                                            Upcoming Renewal
                                        </p>
                                        <h3 class="mt-1 text-lg font-bold text-slate-900 dark:text-slate-100">
                                            Permit #<?= esc($permit['permit_no'] ?? '—') ?>
                                        </h3>
                                    </div>
                                    <span
                                        class="
                                            inline-flex w-fit items-center gap-1.5
                                            rounded-lg bg-violet-100 px-2.5 py-1.5
                                            text-xs font-bold text-violet-700
                                            ring-1 ring-violet-200
                                            dark:bg-violet-950/60 dark:text-violet-300 dark:ring-violet-900
                                        "
                                    >
                                        <i class="bi bi-calendar-check-fill"></i>
                                        Paid & Scheduled
                                    </span>
                                </div>

                                <div class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Plate</p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($permit['plate_no'] ?? '—') ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Starts On</p>
                                        <p class="mt-1 font-bold text-violet-700 dark:text-violet-300">
                                            <?= esc($permit['valid_from'] ?? '—') ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Valid Until</p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($permit['valid_until'] ?? '—') ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Duration</p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc(duration_label((string)($permit['permit_duration'] ?? ''))) ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="border-t border-violet-200 bg-white p-4 dark:border-violet-900 dark:bg-slate-900/70">
                                    <a
                                        href="homeowner_contract.php?permit_id=<?= (int)$permit['id'] ?>"
                                        class="
                                            inline-flex min-h-11 items-center justify-center gap-2
                                            rounded-xl border border-violet-200 bg-violet-50 px-4
                                            text-sm font-semibold text-violet-700 transition hover:bg-violet-100
                                            dark:border-violet-900 dark:bg-violet-950/40
                                            dark:text-violet-300 dark:hover:bg-violet-950/60
                                        "
                                    >
                                        <i class="bi bi-file-earmark-text-fill"></i>
                                        Contract
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>

                    </div>


                <?php elseif ($latestRequest): ?>

                    <?php

                    $latestStatus =
                        strtolower(
                            trim(
                                (string)(
                                    $latestRequest['status']
                                    ?? ''
                                )
                            )
                        );

                    $latestPayStatus =
                        strtolower(
                            trim(
                                (string)(
                                    $latestRequest['payment_status']
                                    ?? ''
                                )
                            )
                        );

                    ?>

                    <article
                        class="
                            rounded-2xl

                            border
                            border-amber-200

                            bg-amber-50/70

                            p-5

                            dark:border-amber-900
                            dark:bg-amber-950/30
                        "
                    >

                        <div
                            class="
                                flex
                                flex-col
                                gap-3

                                sm:flex-row
                                sm:items-start
                                sm:justify-between
                            "
                        >

                            <div>

                                <p
                                    class="
                                        text-xs
                                        font-bold
                                        uppercase
                                        tracking-wide
                                        text-amber-700

                                        dark:text-amber-400
                                    "
                                >
                                    Latest Permit Request
                                </p>

                                <h3
                                    class="
                                        mt-1

                                        text-lg
                                        font-bold
                                        text-slate-900

                                        dark:text-slate-100
                                    "
                                >
                                    <?= esc(
                                        $latestRequest['plate_no']
                                        ?? 'Vehicle'
                                    ) ?>
                                </h3>

                                <p
                                    class="
                                        mt-1

                                        text-sm
                                        text-slate-600

                                        dark:text-slate-400
                                    "
                                >
                                    <?= esc(
                                        vehicle_type_label(
                                            (string)(
                                                $latestRequest['vehicle_type']
                                                ?? 'car'
                                            )
                                        )
                                    ) ?>
                                </p>

                            </div>


                            <span
                                class="
                                    inline-flex
                                    w-fit
                                    items-center
                                    gap-1.5

                                    rounded-lg

                                    px-2.5
                                    py-1.5

                                    text-xs
                                    font-bold

                                    ring-1

                                    <?= permit_status_classes(
                                        $latestStatus,
                                        $latestPayStatus
                                    ) ?>
                                "
                            >
                                <i
                                    class="
                                        bi
                                        <?= esc(
                                            permit_status_icon(
                                                $latestStatus,
                                                $latestPayStatus
                                            )
                                        ) ?>
                                    "
                                ></i>

                                <?= esc(
                                    permit_status_label(
                                        $latestStatus,
                                        $latestPayStatus
                                    )
                                ) ?>
                            </span>

                        </div>


                        <div
                            class="
                                mt-4

                                grid
                                gap-3

                                rounded-xl

                                bg-white/80

                                p-4

                                sm:grid-cols-2

                                dark:bg-slate-900/60
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
                                    Payment Status
                                </p>

                                <span
                                    class="
                                        mt-1

                                        inline-flex
                                        items-center

                                        rounded-lg

                                        px-2.5
                                        py-1

                                        text-xs
                                        font-bold

                                        ring-1

                                        <?= payment_status_classes(
                                            $latestPayStatus
                                        ) ?>
                                    "
                                >
                                    <?= esc(
                                        payment_status_label(
                                            $latestPayStatus
                                        )
                                    ) ?>
                                </span>

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
                                    Vehicle Type
                                </p>

                                <p
                                    class="
                                        mt-1

                                        font-semibold
                                        text-slate-800

                                        dark:text-slate-200
                                    "
                                >
                                    <?= esc(
                                        vehicle_type_label(
                                            (string)(
                                                $latestRequest['vehicle_type']
                                                ?? 'car'
                                            )
                                        )
                                    ) ?>
                                </p>

                            </div>

                        </div>


                        <div
                            class="
                                mt-4

                                text-sm
                                leading-6
                                text-slate-700

                                dark:text-slate-300
                            "
                        >

                            <?php if (
                                $latestStatus === 'pending' &&
                                $latestPayStatus === 'for payment'
                            ): ?>

                                Your request is approved and waiting for payment.

                            <?php elseif ($latestStatus === 'pending'): ?>

                                Your request is still waiting for admin approval.

                            <?php elseif ($latestStatus === 'rejected'): ?>

                                Your request was rejected.

                            <?php elseif ($latestStatus === 'expired'): ?>

                                Your previous permit has already expired.

                            <?php elseif ($latestStatus === 'revoked'): ?>

                                Your permit was revoked.

                            <?php endif; ?>

                        </div>


                        <?php if (!empty($latestRequest['rejected_reason'])): ?>

                            <div
                                class="
                                    mt-3

                                    rounded-xl

                                    border
                                    border-red-200

                                    bg-red-50

                                    p-3

                                    text-sm
                                    leading-6
                                    text-red-800

                                    dark:border-red-900
                                    dark:bg-red-950/40
                                    dark:text-red-300
                                "
                            >
                                <span class="font-bold">
                                    Reason:
                                </span>

                                <?= esc(
                                    $latestRequest['rejected_reason']
                                ) ?>
                            </div>

                        <?php endif; ?>

                        <?php if (!empty($latestRequest['revoked_reason'])): ?>

                            <div
                                class="
                                    mt-3
                                    rounded-xl
                                    border border-red-200
                                    bg-red-50
                                    p-3
                                    text-sm leading-6 text-red-800
                                    dark:border-red-900
                                    dark:bg-red-950/40
                                    dark:text-red-300
                                "
                            >
                                <span class="font-bold">
                                    Revocation reason:
                                </span>

                                <?= esc(
                                    $latestRequest['revoked_reason']
                                ) ?>
                            </div>

                        <?php endif; ?>


                        <div
                            class="
                                mt-4

                                flex
                                flex-col
                                gap-2

                                sm:flex-row
                                sm:flex-wrap
                            "
                        >

                            <?php if (
                                $latestStatus === 'pending' &&
                                $latestPayStatus === 'for payment' &&
                                strtolower(
                                    (string)(
                                        $latestRequest['payment_method']
                                        ?? ''
                                    )
                                ) === 'online'
                            ): ?>

                                <a
                                    href="paymongo_parking_checkout.php?permit_id=<?= (int)$latestRequest['id'] ?>"
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

                                        transition

                                        hover:bg-blue-800

                                        dark:bg-blue-600
                                        dark:hover:bg-blue-500
                                    "
                                >
                                    <i class="bi bi-credit-card-fill"></i>
                                    Pay Online Now
                                </a>

                            <?php endif; ?>


                            <a
                                href="homeowner_parking_permit.php"
                                class="
                                    inline-flex
                                    min-h-11
                                    items-center
                                    justify-center
                                    gap-2

                                    rounded-xl

                                    border
                                    border-emerald-200

                                    bg-white

                                    px-4

                                    text-sm
                                    font-semibold
                                    text-emerald-700

                                    transition

                                    hover:bg-emerald-50

                                    dark:border-emerald-900
                                    dark:bg-slate-900
                                    dark:text-emerald-300
                                    dark:hover:bg-emerald-950/40
                                "
                            >
                                <i class="bi bi-card-checklist"></i>
                                Open Permit Page
                            </a>

                        </div>

                    </article>


                <?php else: ?>

                    <div
                        class="
                            flex
                            flex-col
                            items-center
                            justify-center

                            px-4
                            py-10

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

                                bg-slate-100

                                text-3xl
                                text-slate-400

                                dark:bg-slate-800
                                dark:text-slate-500
                            "
                        >
                            <i class="bi bi-car-front"></i>
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
                            No active parking permit
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
                            Apply for a parking permit to register
                            your vehicle with the HOA.
                        </p>

                        <a
                            href="homeowner_parking_permit.php"
                            class="
                                mt-5

                                inline-flex
                                min-h-12
                                items-center
                                justify-center
                                gap-2

                                rounded-xl

                                bg-emerald-700

                                px-5

                                text-sm
                                font-semibold
                                text-white

                                transition

                                hover:bg-emerald-800

                                dark:bg-emerald-600
                                dark:hover:bg-emerald-500
                            "
                        >
                            <i class="bi bi-plus-circle-fill"></i>
                            Apply for Permit
                        </a>

                    </div>

                <?php endif; ?>

            </div>

        </section>


        <!-- =================================================
             PERMIT HISTORY
             ================================================= -->

        <section
            id="permitHistory"
            class="
                mt-6
                overflow-hidden
                rounded-2xl
                border border-slate-200
                bg-white
                shadow-sm
                dark:border-slate-800
                dark:bg-slate-900
            "
        >
            <div
                class="
                    flex flex-col gap-3
                    border-b border-slate-200
                    px-5 py-4
                    sm:flex-row sm:items-center sm:justify-between
                    dark:border-slate-800
                "
            >
                <div>
                    <h2 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-slate-100">
                        <i class="bi bi-clock-history text-slate-600 dark:text-slate-300"></i>
                        Permit History
                    </h2>

                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Rejected, revoked, and expired permit records remain here for reference.
                    </p>
                </div>

                <?php if ($historyCount > 0): ?>
                    <span
                        class="
                            inline-flex w-fit items-center gap-2
                            rounded-xl bg-slate-100 px-3 py-2
                            text-xs font-bold text-slate-700
                            ring-1 ring-slate-200
                            dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700
                        "
                    >
                        <i class="bi bi-archive-fill"></i>
                        <?= (int)$historyCount ?> Record<?= $historyCount === 1 ? '' : 's' ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="p-5">
                <?php if ($permitHistory): ?>

                    <div class="space-y-4">
                        <?php foreach ($permitHistory as $historyPermit): ?>
                            <?php
                            $historyStatus =
                                strtolower(
                                    trim(
                                        (string)(
                                            $historyPermit['status']
                                            ?? ''
                                        )
                                    )
                                );

                            $historyPaymentStatus =
                                strtolower(
                                    trim(
                                        (string)(
                                            $historyPermit['payment_status']
                                            ?? ''
                                        )
                                    )
                                );

                            $historyReason = '';

                            if ($historyStatus === 'rejected') {
                                $historyReason =
                                    trim(
                                        (string)(
                                            $historyPermit['rejected_reason']
                                            ?? ''
                                        )
                                    );
                            } elseif ($historyStatus === 'revoked') {
                                $historyReason =
                                    trim(
                                        (string)(
                                            $historyPermit['revoked_reason']
                                            ?? ''
                                        )
                                    );
                            }

                            $historyRequestType =
                                strtolower(
                                    trim(
                                        (string)(
                                            $historyPermit['request_type']
                                            ?? 'new'
                                        )
                                    )
                                );
                            ?>

                            <article
                                class="
                                    rounded-2xl
                                    border border-slate-200
                                    bg-slate-50/70
                                    p-4
                                    dark:border-slate-700
                                    dark:bg-slate-800/40
                                "
                            >
                                <div
                                    class="
                                        flex flex-col gap-3
                                        sm:flex-row sm:items-start sm:justify-between
                                    "
                                >
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            <?= $historyRequestType === 'renew' ? 'Renewal' : 'Permit' ?>
                                            Record
                                        </p>

                                        <h3 class="mt-1 truncate text-lg font-bold text-slate-900 dark:text-slate-100">
                                            <?= esc($historyPermit['plate_no'] ?? 'Vehicle') ?>
                                        </h3>

                                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                            Permit #<?= esc($historyPermit['permit_no'] ?? '—') ?>
                                            •
                                            <?= esc(
                                                vehicle_type_label(
                                                    (string)(
                                                        $historyPermit['vehicle_type']
                                                        ?? 'car'
                                                    )
                                                )
                                            ) ?>
                                        </p>
                                    </div>

                                    <span
                                        class="
                                            inline-flex w-fit items-center gap-1.5
                                            rounded-lg px-2.5 py-1.5
                                            text-xs font-bold ring-1
                                            <?= permit_status_classes(
                                                $historyStatus,
                                                $historyPaymentStatus
                                            ) ?>
                                        "
                                    >
                                        <i
                                            class="
                                                bi
                                                <?= esc(
                                                    permit_status_icon(
                                                        $historyStatus,
                                                        $historyPaymentStatus
                                                    )
                                                ) ?>
                                            "
                                        ></i>

                                        <?= esc(
                                            permit_status_label(
                                                $historyStatus,
                                                $historyPaymentStatus
                                            )
                                        ) ?>
                                    </span>
                                </div>

                                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Payment
                                        </p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc(
                                                payment_status_label(
                                                    $historyPaymentStatus
                                                )
                                            ) ?>
                                            •
                                            <?= esc(
                                                payment_label(
                                                    (string)(
                                                        $historyPermit['payment_method']
                                                        ?? ''
                                                    )
                                                )
                                            ) ?>
                                        </p>
                                    </div>

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Duration
                                        </p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc(
                                                duration_label(
                                                    (string)(
                                                        $historyPermit['permit_duration']
                                                        ?? ''
                                                    )
                                                )
                                            ) ?>
                                        </p>
                                    </div>

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Validity
                                        </p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($historyPermit['valid_from'] ?? '—') ?>
                                            →
                                            <?= esc($historyPermit['valid_until'] ?? '—') ?>
                                        </p>
                                    </div>

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                            Requested
                                        </p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($historyPermit['requested_at'] ?? '—') ?>
                                        </p>
                                    </div>
                                </div>

                                <?php if ($historyReason !== ''): ?>
                                    <div
                                        class="
                                            mt-4 flex items-start gap-3
                                            rounded-xl
                                            border border-red-200
                                            bg-red-50
                                            p-3
                                            text-sm leading-6 text-red-800
                                            dark:border-red-900
                                            dark:bg-red-950/40
                                            dark:text-red-300
                                        "
                                    >
                                        <i class="bi bi-info-circle-fill mt-0.5 shrink-0"></i>

                                        <div>
                                            <span class="font-bold">
                                                <?= $historyStatus === 'rejected'
                                                    ? 'Rejection reason:'
                                                    : 'Revocation reason:' ?>
                                            </span>

                                            <?= esc($historyReason) ?>
                                        </div>
                                    </div>
                                <?php elseif ($historyStatus === 'expired'): ?>
                                    <div
                                        class="
                                            mt-4 flex items-start gap-3
                                            rounded-xl
                                            border border-amber-200
                                            bg-amber-50
                                            p-3
                                            text-sm leading-6 text-amber-800
                                            dark:border-amber-900
                                            dark:bg-amber-950/40
                                            dark:text-amber-300
                                        "
                                    >
                                        <i class="bi bi-calendar-x-fill mt-0.5 shrink-0"></i>
                                        This permit reached the end of its validity period.
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($historyPermit['contract_path'])): ?>
                                    <div class="mt-4">
                                        <a
                                            href="homeowner_contract.php?permit_id=<?= (int)$historyPermit['id'] ?>"
                                            class="
                                                inline-flex min-h-11
                                                items-center justify-center gap-2
                                                rounded-xl
                                                border border-slate-200
                                                bg-white px-4
                                                text-sm font-semibold text-slate-700
                                                transition hover:bg-slate-50
                                                dark:border-slate-700
                                                dark:bg-slate-900
                                                dark:text-slate-200
                                                dark:hover:bg-slate-800
                                            "
                                        >
                                            <i class="bi bi-file-earmark-text-fill"></i>
                                            View Contract
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>

                <?php else: ?>

                    <div class="flex flex-col items-center justify-center px-4 py-8 text-center">
                        <div
                            class="
                                flex h-14 w-14 items-center justify-center
                                rounded-2xl bg-slate-100
                                text-2xl text-slate-400
                                dark:bg-slate-800 dark:text-slate-500
                            "
                        >
                            <i class="bi bi-archive"></i>
                        </div>

                        <h3 class="mt-4 font-bold text-slate-900 dark:text-slate-100">
                            No permit history yet
                        </h3>

                        <p class="mt-2 max-w-md text-sm leading-6 text-slate-500 dark:text-slate-400">
                            Rejected, revoked, and expired permits will remain visible here.
                        </p>
                    </div>

                <?php endif; ?>
            </div>
        </section>


        <!-- =================================================
             REQUIREMENTS
             ================================================= -->

        <section
            class="
                mt-6

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

                        text-lg
                        font-bold
                        text-slate-900

                        dark:text-slate-100
                    "
                >
                    <i
                        class="
                            bi
                            bi-clipboard-check-fill
                            text-emerald-700

                            dark:text-emerald-400
                        "
                    ></i>

                    Parking Permit Requirements
                </h2>

                <p
                    class="
                        mt-1

                        text-sm
                        text-slate-500

                        dark:text-slate-400
                    "
                >
                    Prepare these details before opening the permit application page.
                </p>

            </div>


            <div class="p-5">

                <div
                    class="
                        grid
                        gap-3

                        md:grid-cols-3
                    "
                >

                    <div
                        class="
                            rounded-xl

                            bg-slate-50

                            p-4

                            ring-1
                            ring-slate-200

                            dark:bg-slate-800/60
                            dark:ring-slate-700
                        "
                    >

                        <div
                            class="
                                flex
                                h-10 w-10
                                items-center
                                justify-center

                                rounded-xl

                                bg-blue-100

                                text-lg
                                text-blue-700

                                dark:bg-blue-950/60
                                dark:text-blue-300
                            "
                        >
                            <i class="bi bi-camera-fill"></i>
                        </div>

                        <h3
                            class="
                                mt-3

                                font-bold
                                text-slate-900

                                dark:text-slate-100
                            "
                        >
                            Vehicle Front
                        </h3>

                        <p
                            class="
                                mt-1

                                text-sm
                                leading-6
                                text-slate-500

                                dark:text-slate-400
                            "
                        >
                            Upload a clear front photo of the vehicle.
                        </p>

                    </div>


                    <div
                        class="
                            rounded-xl

                            bg-slate-50

                            p-4

                            ring-1
                            ring-slate-200

                            dark:bg-slate-800/60
                            dark:ring-slate-700
                        "
                    >

                        <div
                            class="
                                flex
                                h-10 w-10
                                items-center
                                justify-center

                                rounded-xl

                                bg-violet-100

                                text-lg
                                text-violet-700

                                dark:bg-violet-950/60
                                dark:text-violet-300
                            "
                        >
                            <i class="bi bi-camera-reels-fill"></i>
                        </div>

                        <h3
                            class="
                                mt-3

                                font-bold
                                text-slate-900

                                dark:text-slate-100
                            "
                        >
                            Vehicle Back
                        </h3>

                        <p
                            class="
                                mt-1

                                text-sm
                                leading-6
                                text-slate-500

                                dark:text-slate-400
                            "
                        >
                            Upload a clear rear photo of the vehicle.
                        </p>

                    </div>


                    <div
                        class="
                            rounded-xl

                            bg-slate-50

                            p-4

                            ring-1
                            ring-slate-200

                            dark:bg-slate-800/60
                            dark:ring-slate-700
                        "
                    >

                        <div
                            class="
                                flex
                                h-10 w-10
                                items-center
                                justify-center

                                rounded-xl

                                bg-emerald-100

                                text-lg
                                text-emerald-700

                                dark:bg-emerald-950/60
                                dark:text-emerald-300
                            "
                        >
                            <i class="bi bi-car-front-fill"></i>
                        </div>

                        <h3
                            class="
                                mt-3

                                font-bold
                                text-slate-900

                                dark:text-slate-100
                            "
                        >
                            Vehicle Type
                        </h3>

                        <p
                            class="
                                mt-1

                                text-sm
                                leading-6
                                text-slate-500

                                dark:text-slate-400
                            "
                        >
                            Select Car, Motorcycle, or E-Bike.
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

                        bg-blue-50

                        p-4

                        text-sm
                        leading-6
                        text-blue-800

                        dark:bg-blue-950/40
                        dark:text-blue-300
                    "
                >
                    <i
                        class="
                            bi
                            bi-lightbulb-fill

                            mt-0.5
                            shrink-0
                        "
                    ></i>

                    <span>
                        Use clear and readable uploads to help avoid delays
                        during permit review.
                    </span>
                </div>


                <a
                    href="homeowner_parking_permit.php"
                    class="
                        mt-5

                        inline-flex
                        min-h-12
                        w-full
                        items-center
                        justify-center
                        gap-2

                        rounded-xl

                        bg-emerald-700

                        px-5

                        text-base
                        font-semibold
                        text-white

                        transition

                        hover:bg-emerald-800

                        focus:outline-none
                        focus:ring-4
                        focus:ring-emerald-100

                        sm:w-auto

                        dark:bg-emerald-600
                        dark:hover:bg-emerald-500
                        dark:focus:ring-emerald-950
                    "
                >
                    <i class="bi bi-card-checklist"></i>
                    Apply / Renew Permit
                </a>

            </div>

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