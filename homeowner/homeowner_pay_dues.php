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

$isTenant = ($_SESSION['role'] === 'tenant');
$tenant   = null;
$user     = null;
$hid      = 0;


/*
|--------------------------------------------------------------------------
| Account / session lookup
|--------------------------------------------------------------------------
*/

if ($isTenant) {

    if (
        empty($_SESSION['tenant_id']) ||
        empty($_SESSION['tenant_homeowner_id'])
    ) {
        header("Location: ../index.php");
        exit;
    }

    $tenant_id = (int)$_SESSION['tenant_id'];
    $hid       = (int)$_SESSION['tenant_homeowner_id'];

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
            can_announcements,
            registered_at
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
            house_lot_number,
            created_at
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
        'pay_dues',
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
            house_lot_number,
            created_at
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
| Account details
|--------------------------------------------------------------------------
*/

$phase =
    (string)$user['phase'];

$houseLot =
    (string)($user['house_lot_number'] ?? '');

$mustChange =
    !$isTenant &&
    ((int)$user['must_change_password'] === 1);


$accountStartRaw =
    $isTenant
        ? (string)($tenant['registered_at'] ?? '')
        : (string)($user['created_at'] ?? '');

$accountStartTs =
    strtotime($accountStartRaw);

if (!$accountStartTs) {
    $accountStartTs = time();
}

$duesStartYear =
    (int)date(
        'Y',
        $accountStartTs
    );

$duesStartMonth =
    (int)date(
        'n',
        $accountStartTs
    );

$duesStartLabel =
    date(
        'F Y',
        $accountStartTs
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


if ($mustChange) {
    header(
        "Location: homeowner_dashboard.php"
    );
    exit;
}


$activePage =
    basename(
        $_SERVER['PHP_SELF']
        ?? 'homeowner_pay_dues.php'
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
| Selected year
|--------------------------------------------------------------------------
*/

$selYear =
    (int)(
        $_GET['year']
        ?? (int)date('Y')
    );

$currentYear =
    (int)date('Y');

if ($selYear < $duesStartYear) {
    $selYear = $duesStartYear;
}

if ($selYear > ($currentYear + 1)) {
    $selYear = $currentYear;
}


/*
|--------------------------------------------------------------------------
| Monthly dues setting
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT monthly_dues
    FROM finance_dues_settings
    WHERE phase = ?
    LIMIT 1
");

$stmt->bind_param(
    "s",
    $phase
);

$stmt->execute();

$monthlyDues =
    (float)(
        $stmt
            ->get_result()
            ->fetch_assoc()['monthly_dues']
        ?? 0
    );

$stmt->close();


/*
|--------------------------------------------------------------------------
| PayMongo helper functions
|--------------------------------------------------------------------------
*/

function paymongo_get_checkout(
    string $csId,
    string $secretKey
): ?array {

    if ($csId === '' || $secretKey === '') {
        return null;
    }

    $ch =
        curl_init(
            "https://api.paymongo.com/v1/checkout_sessions/" .
            rawurlencode($csId)
        );

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Accept: application/json",
                "Authorization: Basic " .
                base64_encode(
                    $secretKey . ":"
                )
            ],
            CURLOPT_TIMEOUT => 30
        ]
    );

    $resp =
        curl_exec($ch);

    $http =
        (int)curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);

    if (
        $resp === false ||
        $http < 200 ||
        $http >= 300
    ) {
        return null;
    }

    $data =
        json_decode(
            $resp,
            true
        );

    return is_array($data)
        ? $data
        : null;
}


function checkout_is_paid(
    array $pm
): bool {

    $cs =
        $pm['data']
        ?? null;

    if (!is_array($cs)) {
        return false;
    }

    $attr =
        $cs['attributes']
        ?? [];

    $payments =
        $attr['payments']
        ?? [];

    if (
        is_array($payments) &&
        !empty($payments)
    ) {
        return true;
    }

    $status =
        (string)(
            $attr['status']
            ?? ''
        );

    return in_array(
        $status,
        [
            'paid',
            'succeeded',
            'complete',
            'completed'
        ],
        true
    );
}


function extract_payment(
    array $pm
): array {

    $cs =
        $pm['data']
        ?? [];

    $attr =
        $cs['attributes']
        ?? [];

    $payments =
        $attr['payments']
        ?? [];

    $pid =
        '';

    $amountCentavos =
        0;

    if (
        is_array($payments) &&
        !empty($payments[0]['id'])
    ) {

        $pid =
            (string)$payments[0]['id'];

        $amountCentavos =
            (int)(
                $payments[0]['attributes']['amount']
                ?? 0
            );
    }

    return [
        $pid,
        $amountCentavos
    ];
}


/*
|--------------------------------------------------------------------------
| Fallback PayMongo sync
|--------------------------------------------------------------------------
|
| The secret is read only from the environment.
| No secret key is stored in this page.
|--------------------------------------------------------------------------
*/

$doSync = true;

if (!empty($_SESSION['last_paymongo_sync'])) {

    if (
        time() -
        (int)$_SESSION['last_paymongo_sync']
        < 20
    ) {
        $doSync = false;
    }
}


if ($doSync) {

    $_SESSION['last_paymongo_sync'] =
        time();

    $PAYMONGO_SECRET =
        getenv(
            'PAYMONGO_SECRET_KEY'
        ) ?: '';

    /*
     * If the server environment does not expose the secret,
     * skip fallback sync. Your normal PayMongo webhook can still
     * update payments.
     */
    if ($PAYMONGO_SECRET !== '') {

        $stmt = $conn->prepare("
            SELECT
                id,
                checkout_session_id,
                pay_month,
                amount,
                phase,
                status
            FROM finance_paymongo_checkouts
            WHERE homeowner_id = ?
              AND pay_year = ?
              AND status = 'pending'
            ORDER BY created_at DESC
        ");

        $stmt->bind_param(
            "ii",
            $hid,
            $selYear
        );

        $stmt->execute();

        $pendingRows =
            $stmt
                ->get_result()
                ->fetch_all(
                    MYSQLI_ASSOC
                );

        $stmt->close();


        foreach ($pendingRows as $pr) {

            $csId =
                (string)$pr['checkout_session_id'];

            if ($csId === '') {
                continue;
            }


            $pm =
                paymongo_get_checkout(
                    $csId,
                    $PAYMONGO_SECRET
                );

            if (!$pm) {
                continue;
            }

            if (!checkout_is_paid($pm)) {
                continue;
            }


            [
                $paymentId,
                $amountCentavos
            ] = extract_payment($pm);


            $pMonth =
                (int)$pr['pay_month'];

            $pAmount =
                (float)$pr['amount'];

            if ($amountCentavos > 0) {

                $pAmount =
                    $amountCentavos / 100.0;
            }

            $pPhase =
                $phase;


            $conn->begin_transaction();

            try {

                $stmt = $conn->prepare("
                    UPDATE finance_paymongo_checkouts
                    SET
                        status = 'paid',
                        payment_id = ?,
                        paid_at = NOW(),
                        phase = ?
                    WHERE checkout_session_id = ?
                ");

                $stmt->bind_param(
                    "sss",
                    $paymentId,
                    $pPhase,
                    $csId
                );

                $stmt->execute();
                $stmt->close();


                $ref =
                    $paymentId !== ''
                        ? $paymentId
                        : $csId;

                $notes =
                    "PayMongo (fallback sync)";


                $stmt = $conn->prepare("
                    INSERT INTO finance_payments
                    (
                        homeowner_id,
                        phase,
                        pay_year,
                        pay_month,
                        amount,
                        status,
                        paid_at,
                        reference_no,
                        notes,
                        created_by_admin_id
                    )
                    VALUES
                    (
                        ?, ?, ?, ?, ?,
                        'paid',
                        NOW(),
                        ?, ?,
                        NULL
                    )
                    ON DUPLICATE KEY UPDATE
                        amount = VALUES(amount),
                        status = 'paid',
                        paid_at = NOW(),
                        reference_no = VALUES(reference_no),
                        notes = VALUES(notes),
                        created_by_admin_id = NULL
                ");

                $stmt->bind_param(
                    "isiidss",
                    $hid,
                    $pPhase,
                    $selYear,
                    $pMonth,
                    $pAmount,
                    $ref,
                    $notes
                );

                $stmt->execute();
                $stmt->close();

                $conn->commit();

            } catch (Throwable $e) {

                $conn->rollback();
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Paid records
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        pay_month,
        amount,
        paid_at,
        reference_no,
        notes
    FROM finance_payments
    WHERE homeowner_id = ?
      AND pay_year = ?
      AND status = 'paid'
    ORDER BY pay_month ASC
");

$stmt->bind_param(
    "ii",
    $hid,
    $selYear
);

$stmt->execute();

$res =
    $stmt->get_result();

$paidMonths = [];
$paidRows   = [];

while (
    $r =
    $res->fetch_assoc()
) {

    $m =
        (int)$r['pay_month'];

    $paidMonths[$m] =
        true;

    $paidRows[] =
        $r;
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| Flash state + CSRF
|--------------------------------------------------------------------------
*/

$flashPaid =
    isset($_GET['paid'])
        ? 1
        : 0;

$flashCancel =
    isset($_GET['cancel'])
        ? 1
        : 0;

$flashErr =
    trim(
        (string)(
            $_GET['err']
            ?? ''
        )
    );


if (empty($_SESSION['csrf_pay_dues'])) {

    $_SESSION['csrf_pay_dues'] =
        bin2hex(
            random_bytes(16)
        );
}

$csrf =
    (string)$_SESSION['csrf_pay_dues'];


$months = [
    1  => 'January',
    2  => 'February',
    3  => 'March',
    4  => 'April',
    5  => 'May',
    6  => 'June',
    7  => 'July',
    8  => 'August',
    9  => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];


$paidCount =
    count($paidMonths);

$unpaidCount =
    0;

foreach ($months as $m => $label) {

    $isBeforeStart =
        (
            $selYear < $duesStartYear ||
            (
                $selYear === $duesStartYear &&
                $m < $duesStartMonth
            )
        );

    if (
        !$isBeforeStart &&
        empty($paidMonths[$m])
    ) {
        $unpaidCount++;
    }
}


$pageTitle =
    "Pay Monthly Dues • " .
    $phase;

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
                        Finance
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
                        Pay Monthly Dues
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
                        Review your monthly HOA dues, payment status,
                        and paid history.
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
             FLASH MESSAGES
             ================================================= -->

        <?php if ($flashPaid): ?>

            <div
                class="
                    page-flash
                    mb-4

                    flex
                    items-start
                    gap-3

                    rounded-2xl

                    border
                    border-emerald-200

                    bg-emerald-50

                    p-4

                    dark:border-emerald-900
                    dark:bg-emerald-950/40
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

                        bg-emerald-100

                        text-lg
                        text-emerald-700

                        dark:bg-emerald-950
                        dark:text-emerald-300
                    "
                >
                    <i class="bi bi-check-circle-fill"></i>
                </div>

                <div class="min-w-0 flex-1">

                    <p
                        class="
                            font-bold
                            text-emerald-900

                            dark:text-emerald-200
                        "
                    >
                        Payment completed
                    </p>

                    <p
                        class="
                            mt-1
                            text-sm
                            leading-6
                            text-emerald-800

                            dark:text-emerald-300
                        "
                    >
                        Your payment was completed. This page will
                        automatically sync your PayMongo payment status.
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

                        text-emerald-700

                        hover:bg-emerald-100

                        dark:text-emerald-300
                        dark:hover:bg-emerald-950
                    "
                    aria-label="Close"
                >
                    <i class="bi bi-x-lg"></i>
                </button>

            </div>

        <?php endif; ?>


        <?php if ($flashCancel): ?>

            <div
                class="
                    page-flash
                    mb-4

                    flex
                    items-start
                    gap-3

                    rounded-2xl

                    border
                    border-amber-200

                    bg-amber-50

                    p-4

                    dark:border-amber-900
                    dark:bg-amber-950/40
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

                        bg-amber-100

                        text-lg
                        text-amber-700

                        dark:bg-amber-950
                        dark:text-amber-300
                    "
                >
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>

                <div class="min-w-0 flex-1">

                    <p
                        class="
                            font-bold
                            text-amber-900

                            dark:text-amber-200
                        "
                    >
                        Payment cancelled
                    </p>

                    <p
                        class="
                            mt-1
                            text-sm
                            leading-6
                            text-amber-800

                            dark:text-amber-300
                        "
                    >
                        No payment was recorded.
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

                        text-amber-700

                        hover:bg-amber-100

                        dark:text-amber-300
                        dark:hover:bg-amber-950
                    "
                    aria-label="Close"
                >
                    <i class="bi bi-x-lg"></i>
                </button>

            </div>

        <?php endif; ?>


        <?php if ($flashErr !== ''): ?>

            <div
                class="
                    page-flash
                    mb-4

                    flex
                    items-start
                    gap-3

                    rounded-2xl

                    border
                    border-red-200

                    bg-red-50

                    p-4

                    dark:border-red-900
                    dark:bg-red-950/40
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

                        bg-red-100

                        text-lg
                        text-red-700

                        dark:bg-red-950
                        dark:text-red-300
                    "
                >
                    <i class="bi bi-x-circle-fill"></i>
                </div>

                <div class="min-w-0 flex-1">

                    <p
                        class="
                            font-bold
                            text-red-900

                            dark:text-red-200
                        "
                    >
                        Payment error
                    </p>

                    <p
                        class="
                            mt-1
                            break-words

                            text-sm
                            leading-6
                            text-red-800

                            dark:text-red-300
                        "
                    >
                        <?= esc($flashErr) ?>
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

                        text-red-700

                        hover:bg-red-100

                        dark:text-red-300
                        dark:hover:bg-red-950
                    "
                    aria-label="Close"
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
                xl:grid-cols-3
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

                        bg-emerald-100

                        text-xl
                        text-emerald-700

                        dark:bg-emerald-950/60
                        dark:text-emerald-300
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
                    Monthly dues
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
                    ₱<?= number_format($monthlyDues, 2) ?>
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

                        bg-blue-100

                        text-xl
                        text-blue-700

                        dark:bg-blue-950/60
                        dark:text-blue-300
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
                    Paid in <?= (int)$selYear ?>
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
                    <span
                        class="
                            text-base
                            font-semibold
                            text-slate-400

                            dark:text-slate-500
                        "
                    >
                        month<?= $paidCount === 1 ? '' : 's' ?>
                    </span>
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
                    <i class="bi bi-calendar-event-fill"></i>
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
                    Dues start
                </p>

                <p
                    class="
                        mt-1
                        text-lg
                        font-bold
                        text-slate-900

                        dark:text-slate-100
                    "
                >
                    <?= esc($duesStartLabel) ?>
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

                xl:grid-cols-[minmax(0,1fr)_360px]
            "
        >


            <!-- =============================================
                 DUES LIST
                 ============================================= -->

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

                <!-- Header -->
                <div
                    class="
                        flex
                        flex-col
                        gap-4

                        border-b
                        border-slate-200

                        p-5

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
                                    bi-calendar2-check-fill
                                    text-emerald-700

                                    dark:text-emerald-400
                                "
                            ></i>

                            Monthly Dues
                        </h2>

                        <p
                            class="
                                mt-1
                                text-sm
                                text-slate-500

                                dark:text-slate-400
                            "
                        >
                            View your payment status for each month.
                        </p>

                    </div>


                    <!-- Year filter -->
                    <form
                        method="get"
                        class="
                            flex
                            w-full
                            items-end
                            gap-2

                            sm:w-auto
                        "
                    >

                        <div class="min-w-0 flex-1 sm:w-[130px]">

                            <label
                                for="year"
                                class="
                                    mb-1
                                    block

                                    text-xs
                                    font-bold
                                    uppercase
                                    tracking-wide
                                    text-slate-500

                                    dark:text-slate-400
                                "
                            >
                                Year
                            </label>

                            <input
                                type="number"
                                id="year"
                                name="year"
                                value="<?= (int)$selYear ?>"
                                min="<?= (int)$duesStartYear ?>"
                                max="<?= (int)date('Y') + 1 ?>"
                                class="
                                    min-h-11
                                    w-full

                                    rounded-xl

                                    border
                                    border-slate-300

                                    bg-white

                                    px-3

                                    text-sm
                                    font-semibold
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

                        <button
                            type="submit"
                            class="
                                min-h-11

                                rounded-xl

                                border
                                border-emerald-200

                                bg-emerald-50

                                px-4

                                text-sm
                                font-bold
                                text-emerald-800

                                transition

                                hover:bg-emerald-100

                                dark:border-emerald-900
                                dark:bg-emerald-950/50
                                dark:text-emerald-300
                                dark:hover:bg-emerald-950
                            "
                        >
                            Go
                        </button>

                    </form>

                </div>


                <!-- Current amount -->
                <div
                    class="
                        flex
                        flex-col
                        gap-3

                        border-b
                        border-slate-100

                        bg-slate-50/70

                        px-5
                        py-4

                        sm:flex-row
                        sm:items-center
                        sm:justify-between

                        dark:border-slate-800
                        dark:bg-slate-950/30
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
                            Current monthly dues
                        </p>

                        <p
                            class="
                                mt-1
                                text-xl
                                font-bold
                                text-emerald-700

                                dark:text-emerald-400
                            "
                        >
                            ₱<?= number_format($monthlyDues, 2) ?>
                        </p>

                    </div>


                    <p
                        class="
                            max-w-md

                            text-sm
                            leading-6
                            text-slate-500

                            sm:text-right

                            dark:text-slate-400
                        "
                    >
                        Dues start from your account creation month:
                        <strong
                            class="
                                text-slate-700
                                dark:text-slate-200
                            "
                        >
                            <?= esc($duesStartLabel) ?>
                        </strong>.
                    </p>

                </div>


                <!-- Months -->
                <div class="divide-y divide-slate-100 dark:divide-slate-800">

                    <?php foreach ($months as $m => $label): ?>

                        <?php

                        $isBeforeStart =
                            (
                                $selYear < $duesStartYear ||
                                (
                                    $selYear === $duesStartYear &&
                                    $m < $duesStartMonth
                                )
                            );

                        $isPaid =
                            !$isBeforeStart &&
                            !empty($paidMonths[$m]);

                        ?>

                        <div
                            class="
                                flex
                                flex-col
                                gap-3

                                px-5
                                py-4

                                sm:flex-row
                                sm:items-center
                                sm:justify-between
                            "
                        >

                            <div class="min-w-0">

                                <div
                                    class="
                                        font-bold
                                        text-slate-900

                                        dark:text-slate-100
                                    "
                                >
                                    <?= esc($label) ?>
                                    <?= (int)$selYear ?>
                                </div>

                                <div
                                    class="
                                        mt-1
                                        text-sm
                                        text-slate-500

                                        dark:text-slate-400
                                    "
                                >

                                    <?php if ($isBeforeStart): ?>

                                        Not applicable — account started in
                                        <?= esc($duesStartLabel) ?>

                                    <?php elseif ($isPaid): ?>

                                        Payment recorded

                                    <?php else: ?>

                                        Not paid yet

                                    <?php endif; ?>

                                </div>

                            </div>


                            <div
                                class="
                                    flex
                                    flex-wrap
                                    items-center
                                    gap-2

                                    sm:justify-end
                                "
                            >

                                <?php if ($isBeforeStart): ?>

                                    <span
                                        class="
                                            inline-flex
                                            min-h-9
                                            items-center
                                            gap-2

                                            rounded-xl

                                            bg-amber-50

                                            px-3

                                            text-xs
                                            font-bold
                                            text-amber-700

                                            ring-1
                                            ring-amber-200

                                            dark:bg-amber-950/40
                                            dark:text-amber-300
                                            dark:ring-amber-900
                                        "
                                    >
                                        <i class="bi bi-dash-circle"></i>
                                        N/A
                                    </span>


                                <?php elseif ($isPaid): ?>

                                    <span
                                        class="
                                            inline-flex
                                            min-h-9
                                            items-center
                                            gap-2

                                            rounded-xl

                                            bg-emerald-50

                                            px-3

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
                                        <i class="bi bi-check2-circle"></i>
                                        PAID
                                    </span>


                                <?php else: ?>

                                    <span
                                        class="
                                            inline-flex
                                            min-h-9
                                            items-center
                                            gap-2

                                            rounded-xl

                                            bg-red-50

                                            px-3

                                            text-xs
                                            font-bold
                                            text-red-700

                                            ring-1
                                            ring-red-200

                                            dark:bg-red-950/40
                                            dark:text-red-300
                                            dark:ring-red-900
                                        "
                                    >
                                        <i class="bi bi-x-circle"></i>
                                        UNPAID
                                    </span>


                                    <?php if ($monthlyDues > 0): ?>

                                        <form
                                            method="post"
                                            action="paymongo_create_checkout.php"
                                            class="m-0"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= esc($csrf) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="year"
                                                value="<?= (int)$selYear ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="month"
                                                value="<?= (int)$m ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="
                                                    inline-flex
                                                    min-h-10
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

                                                    focus:outline-none
                                                    focus:ring-4
                                                    focus:ring-emerald-100

                                                    dark:bg-emerald-600
                                                    dark:hover:bg-emerald-500
                                                    dark:focus:ring-emerald-950
                                                "
                                            >
                                                <i class="bi bi-credit-card-2-front"></i>
                                                Pay Now
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span
                                            class="
                                                text-sm
                                                font-semibold
                                                text-slate-400

                                                dark:text-slate-500
                                            "
                                        >
                                            Dues not set
                                        </span>

                                    <?php endif; ?>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            </section>


            <!-- =============================================
                 RIGHT COLUMN
                 ============================================= -->

            <aside class="space-y-6">


                <!-- Paid history -->
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
                                    bi-clock-history
                                    text-blue-700

                                    dark:text-blue-300
                                "
                            ></i>

                            Paid History
                        </h2>

                        <p
                            class="
                                mt-1
                                text-xs
                                text-slate-500

                                dark:text-slate-400
                            "
                        >
                            <?= (int)$selYear ?>
                        </p>

                    </div>


                    <div class="p-4">

                        <?php if (!$paidRows): ?>

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
                                    <i class="bi bi-receipt"></i>
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
                                    No paid records yet.
                                </p>

                            </div>

                        <?php else: ?>

                            <div class="space-y-3">

                                <?php foreach ($paidRows as $p): ?>

                                    <?php

                                    $mm =
                                        (int)$p['pay_month'];

                                    $paidAt =
                                        $p['paid_at']
                                            ? date(
                                                'M d, Y h:i A',
                                                strtotime(
                                                    $p['paid_at']
                                                )
                                            )
                                            : '';

                                    ?>

                                    <div
                                        class="
                                            rounded-xl

                                            border
                                            border-slate-200

                                            bg-slate-50/70

                                            p-3

                                            dark:border-slate-800
                                            dark:bg-slate-800/50
                                        "
                                    >

                                        <div
                                            class="
                                                flex
                                                items-center
                                                justify-between
                                                gap-3
                                            "
                                        >

                                            <div
                                                class="
                                                    font-bold
                                                    text-slate-900

                                                    dark:text-slate-100
                                                "
                                            >
                                                <?= esc(
                                                    $months[$mm]
                                                    ?? ('Month ' . $mm)
                                                ) ?>
                                            </div>

                                            <span
                                                class="
                                                    rounded-lg

                                                    bg-emerald-100

                                                    px-2
                                                    py-1

                                                    text-[10px]
                                                    font-bold
                                                    text-emerald-700

                                                    dark:bg-emerald-950/60
                                                    dark:text-emerald-300
                                                "
                                            >
                                                PAID
                                            </span>

                                        </div>


                                        <div
                                            class="
                                                mt-2

                                                text-base
                                                font-bold
                                                text-slate-800

                                                dark:text-slate-200
                                            "
                                        >
                                            ₱<?= number_format(
                                                (float)$p['amount'],
                                                2
                                            ) ?>
                                        </div>


                                        <?php if ($paidAt !== ''): ?>

                                            <div
                                                class="
                                                    mt-1

                                                    text-xs
                                                    font-medium
                                                    text-slate-500

                                                    dark:text-slate-400
                                                "
                                            >
                                                <?= esc($paidAt) ?>
                                            </div>

                                        <?php endif; ?>


                                        <?php if (!empty($p['reference_no'])): ?>

                                            <div
                                                class="
                                                    mt-2
                                                    break-all

                                                    rounded-lg

                                                    bg-white

                                                    px-2.5
                                                    py-2

                                                    text-[11px]
                                                    leading-5
                                                    text-slate-500

                                                    ring-1
                                                    ring-slate-200

                                                    dark:bg-slate-900
                                                    dark:text-slate-400
                                                    dark:ring-slate-700
                                                "
                                            >
                                                <span
                                                    class="
                                                        font-bold
                                                        text-slate-600

                                                        dark:text-slate-300
                                                    "
                                                >
                                                    Ref:
                                                </span>

                                                <?= esc(
                                                    $p['reference_no']
                                                ) ?>
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                </section>


                <!-- How payment works -->
                <section
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
                        <i class="bi bi-shield-check"></i>
                    </div>


                    <h2
                        class="
                            mt-4

                            font-bold
                            text-slate-900

                            dark:text-slate-100
                        "
                    >
                        How payment works
                    </h2>


                    <p
                        class="
                            mt-2

                            text-sm
                            leading-6
                            text-slate-600

                            dark:text-slate-400
                        "
                    >
                        Tap
                        <strong
                            class="
                                text-slate-800
                                dark:text-slate-200
                            "
                        >
                            Pay Now
                        </strong>
                        beside an unpaid month. You will be redirected
                        to PayMongo Checkout to complete your payment.
                    </p>


                    <div
                        class="
                            mt-4

                            flex
                            items-start
                            gap-3

                            rounded-xl

                            bg-emerald-50

                            p-3

                            text-sm
                            leading-6
                            text-emerald-800

                            dark:bg-emerald-950/40
                            dark:text-emerald-300
                        "
                    >
                        <i
                            class="
                                bi
                                bi-lock-fill
                                mt-0.5
                                shrink-0
                            "
                        ></i>

                        <span>
                            Payment status is recorded after PayMongo
                            confirms the transaction.
                        </span>
                    </div>

                </section>

            </aside>

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
| Close inline flash messages
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