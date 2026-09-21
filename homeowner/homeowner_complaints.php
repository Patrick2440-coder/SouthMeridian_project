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

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

require_once 'tenant_module_guard.php';


function esc($v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES,
        'UTF-8'
    );
}


function complaint_status_label(string $status): string
{
    return match ($status) {
        'open'        => 'Open',
        'in_progress' => 'In Progress',
        'resolved'    => 'Resolved',
        'closed'      => 'Closed',
        default       => ucwords(
            str_replace(
                '_',
                ' ',
                $status
            )
        ),
    };
}


function complaint_status_classes(string $status): string
{
    return match ($status) {
        'open' =>
            'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',

        'in_progress' =>
            'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900',

        'resolved' =>
            'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',

        'closed' =>
            'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',

        default =>
            'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
    };
}


function complaint_status_icon(string $status): string
{
    return match ($status) {
        'open'        => 'bi-exclamation-circle-fill',
        'in_progress' => 'bi-hourglass-split',
        'resolved'    => 'bi-check-circle-fill',
        'closed'      => 'bi-lock-fill',
        default       => 'bi-info-circle-fill',
    };
}


function complaint_priority_classes(string $priority): string
{
    return match ($priority) {
        'urgent' =>
            'bg-red-600 text-white ring-red-600 dark:bg-red-500 dark:ring-red-500',

        'high' =>
            'bg-orange-100 text-orange-800 ring-orange-200 dark:bg-orange-950/50 dark:text-orange-300 dark:ring-orange-900',

        'normal' =>
            'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:ring-blue-900',

        'low' =>
            'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',

        default =>
            'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
    };
}


function complaint_priority_icon(string $priority): string
{
    return match ($priority) {
        'urgent' => 'bi-exclamation-octagon-fill',
        'high'   => 'bi-exclamation-triangle-fill',
        'normal' => 'bi-circle-fill',
        'low'    => 'bi-arrow-down-circle-fill',
        default  => 'bi-circle-fill',
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

    $ts = strtotime($value);

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
            house_lot_number,
            latitude,
            longitude
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
        'complaints',
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
            latitude,
            longitude
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

$phase =
    (string)$user['phase'];

$houseLot =
    (string)(
        $user['house_lot_number']
        ?? ''
    );

$mustChange =
    !$isTenant &&
    (
        (int)$user['must_change_password'] === 1
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
    "Complaints • " .
    $phase;

$activePage =
    basename(
        $_SERVER['PHP_SELF']
        ?? 'homeowner_complaints.php'
    );

$parkingPages = [
    'homeowner_parking.php',
    'homeowner_parking_permit.php',
    'homeowner_parking_violations.php'
];

$parkingOpen =
    in_array(
        $activePage,
        $parkingPages,
        true
    );


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_complaints'])) {

    $_SESSION['csrf_complaints'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrf =
    (string)$_SESSION['csrf_complaints'];


/*
|--------------------------------------------------------------------------
| Page state
|--------------------------------------------------------------------------
*/

$err =
    '';

$okMsg =
    '';

$formSubject =
    trim(
        (string)(
            $_POST['subject']
            ?? ''
        )
    );

$formCategory =
    trim(
        (string)(
            $_POST['category']
            ?? 'general'
        )
    );

$formPriority =
    trim(
        (string)(
            $_POST['priority']
            ?? 'normal'
        )
    );

$formDescription =
    trim(
        (string)(
            $_POST['description']
            ?? ''
        )
    );


/*
|--------------------------------------------------------------------------
| Phase admin
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        full_name,
        email
    FROM admins
    WHERE phase = ?
      AND role = 'admin'
    LIMIT 1
");

$stmt->bind_param(
    "s",
    $phase
);

$stmt->execute();

$phaseAdmin =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();

$phaseAdminId =
    (int)(
        $phaseAdmin['id']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| POST / CSRF validation
|--------------------------------------------------------------------------
*/

$isPost =
    ($_SERVER['REQUEST_METHOD'] === 'POST');

$csrfValid =
    !$isPost ||
    (
        isset($_POST['csrf']) &&
        is_string($_POST['csrf']) &&
        hash_equals(
            $csrf,
            $_POST['csrf']
        )
    );


if (
    $isPost &&
    !$csrfValid
) {

    $err =
        'Your session token is no longer valid. Please refresh the page and try again.';
}


/*
|--------------------------------------------------------------------------
| Required password change
|--------------------------------------------------------------------------
*/

if (
    $csrfValid &&
    !$isTenant &&
    $mustChange &&
    $isPost &&
    isset($_POST['change_password_submit'])
) {

    $p1 =
        (string)(
            $_POST['password']
            ?? ''
        );

    $p2 =
        (string)(
            $_POST['password2']
            ?? ''
        );


    if (strlen($p1) < 8) {

        $err =
            'Password must be at least 8 characters.';

    } elseif ($p1 !== $p2) {

        $err =
            'Passwords do not match.';

    } else {

        $hash =
            password_hash(
                $p1,
                PASSWORD_DEFAULT
            );

        $stmt = $conn->prepare("
            UPDATE homeowners
            SET
                password = ?,
                must_change_password = 0
            WHERE id = ?
        ");

        $stmt->bind_param(
            "si",
            $hash,
            $hid
        );

        $stmt->execute();
        $stmt->close();

        header(
            "Location: homeowner_complaints.php"
        );

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| File complaint
|--------------------------------------------------------------------------
*/

if (
    $csrfValid &&
    !$mustChange &&
    $isPost &&
    isset($_POST['file_complaint_submit'])
) {

    $subject =
        $formSubject;

    $category =
        $formCategory;

    $priority =
        $formPriority;

    $description =
        $formDescription;


    $allowedCategories = [
        'general',
        'security',
        'maintenance',
        'noise',
        'parking',
        'neighbor',
        'billing',
        'other'
    ];

    $allowedPriorities = [
        'low',
        'normal',
        'high',
        'urgent'
    ];


    if (
        $subject === '' ||
        mb_strlen($subject) < 3
    ) {

        $err =
            'Subject must be at least 3 characters.';

    } elseif (
        !in_array(
            $category,
            $allowedCategories,
            true
        )
    ) {

        $err =
            'Invalid category selected.';

    } elseif (
        !in_array(
            $priority,
            $allowedPriorities,
            true
        )
    ) {

        $err =
            'Invalid priority selected.';

    } elseif (
        $description === '' ||
        mb_strlen($description) < 10
    ) {

        $err =
            'Complaint description must be at least 10 characters.';

    } else {

        $stmt = $conn->prepare("
            INSERT INTO complaints
            (
                homeowner_id,
                phase,
                admin_id,
                subject,
                category,
                description,
                status,
                priority
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?,
                'open',
                ?
            )
        ");

        $stmt->bind_param(
            "isissss",
            $hid,
            $phase,
            $phaseAdminId,
            $subject,
            $category,
            $description,
            $priority
        );

        $stmt->execute();

        $complaintId =
            (int)$stmt->insert_id;

        $stmt->close();


        $stmt = $conn->prepare("
            INSERT INTO complaint_messages
            (
                complaint_id,
                sender_type,
                sender_homeowner_id,
                message
            )
            VALUES
            (
                ?,
                'homeowner',
                ?,
                ?
            )
        ");

        $stmt->bind_param(
            "iis",
            $complaintId,
            $hid,
            $description
        );

        $stmt->execute();
        $stmt->close();


        $okMsg =
            'Complaint filed successfully.';

        $formSubject =
            '';

        $formCategory =
            'general';

        $formPriority =
            'normal';

        $formDescription =
            '';
    }
}


/*
|--------------------------------------------------------------------------
| Complaint statistics
|--------------------------------------------------------------------------
*/

$stats = [
    'all'         => 0,
    'open'        => 0,
    'in_progress' => 0,
    'resolved'    => 0,
    'closed'      => 0
];


$stmt = $conn->prepare("
    SELECT
        status,
        COUNT(*) c
    FROM complaints
    WHERE homeowner_id = ?
    GROUP BY status
");

$stmt->bind_param(
    "i",
    $hid
);

$stmt->execute();

$res =
    $stmt->get_result();


while (
    $row =
    $res->fetch_assoc()
) {

    $status =
        (string)$row['status'];

    $count =
        (int)$row['c'];

    $stats[$status] =
        $count;

    $stats['all'] +=
        $count;
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| Complaint records
|--------------------------------------------------------------------------
*/

$complaints = [];

$stmt = $conn->prepare("
    SELECT
        c.*,
        a.full_name AS admin_name,
        (
            SELECT MAX(cm.created_at)
            FROM complaint_messages cm
            WHERE cm.complaint_id = c.id
        ) AS last_message_at
    FROM complaints c
    LEFT JOIN admins a
        ON a.id = c.admin_id
    WHERE c.homeowner_id = ?
    ORDER BY
        c.updated_at DESC,
        c.id DESC
");

$stmt->bind_param(
    "i",
    $hid
);

$stmt->execute();

$res =
    $stmt->get_result();


while (
    $row =
    $res->fetch_assoc()
) {
    $complaints[] =
        $row;
}

$stmt->close();


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


<style type="text/tailwindcss">
    @custom-variant dark (&:where(.dark, .dark *));
</style>


<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>


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


<div
    class="
        min-h-screen
        lg:ml-[280px]

        <?= $mustChange
            ? 'blur-sm pointer-events-none select-none'
            : ''
        ?>
    "
>


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


        <!-- Page header -->
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
                        Resident Support
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
                        Complaints Center
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
                        File a concern, track its status, and continue
                        the conversation with your phase admin.
                    </p>

                </div>


                <div
                    class="
                        flex
                        flex-col
                        gap-2

                        sm:flex-row
                    "
                >

                    <div
                        class="
                            inline-flex
                            min-h-11
                            items-center
                            gap-2

                            rounded-xl

                            bg-white

                            px-3

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


                    <a
                        href="homeowner_complaint_chat.php"
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
                        <i class="bi bi-chat-dots-fill"></i>
                        Open Complaint Chat
                    </a>

                </div>

            </div>

        </section>


        <!-- Success -->
        <?php if ($okMsg !== ''): ?>

            <div
                class="
                    page-flash
                    mb-5

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
                        Complaint submitted
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
                        <?= esc($okMsg) ?>
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
                    aria-label="Close message"
                >
                    <i class="bi bi-x-lg"></i>
                </button>

            </div>

        <?php endif; ?>


        <!-- Error -->
        <?php if (
            $err !== '' &&
            !$mustChange
        ): ?>

            <div
                class="
                    page-flash
                    mb-5

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
                        Please check your complaint
                    </p>

                    <p
                        class="
                            mt-1
                            text-sm
                            leading-6
                            text-red-800

                            dark:text-red-300
                        "
                    >
                        <?= esc($err) ?>
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
                    aria-label="Close message"
                >
                    <i class="bi bi-x-lg"></i>
                </button>

            </div>

        <?php endif; ?>


        <!-- Stats -->
        <section
            class="
                mb-6

                grid
                gap-4

                sm:grid-cols-2
                xl:grid-cols-5
            "
        >

            <div
                class="
                    rounded-2xl
                    border border-slate-200
                    bg-white
                    p-5
                    shadow-sm

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex h-11 w-11
                        items-center justify-center
                        rounded-xl
                        bg-violet-100
                        text-xl text-violet-700

                        dark:bg-violet-950/60
                        dark:text-violet-300
                    "
                >
                    <i class="bi bi-inboxes-fill"></i>
                </div>

                <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">
                    All Complaints
                </p>

                <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100">
                    <?= (int)$stats['all'] ?>
                </p>
            </div>


            <div
                class="
                    rounded-2xl
                    border border-slate-200
                    bg-white
                    p-5
                    shadow-sm

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex h-11 w-11
                        items-center justify-center
                        rounded-xl
                        bg-red-100
                        text-xl text-red-700

                        dark:bg-red-950/60
                        dark:text-red-300
                    "
                >
                    <i class="bi bi-exclamation-circle-fill"></i>
                </div>

                <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">
                    Open
                </p>

                <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100">
                    <?= (int)$stats['open'] ?>
                </p>
            </div>


            <div
                class="
                    rounded-2xl
                    border border-slate-200
                    bg-white
                    p-5
                    shadow-sm

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex h-11 w-11
                        items-center justify-center
                        rounded-xl
                        bg-amber-100
                        text-xl text-amber-700

                        dark:bg-amber-950/60
                        dark:text-amber-300
                    "
                >
                    <i class="bi bi-hourglass-split"></i>
                </div>

                <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">
                    In Progress
                </p>

                <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100">
                    <?= (int)$stats['in_progress'] ?>
                </p>
            </div>


            <div
                class="
                    rounded-2xl
                    border border-slate-200
                    bg-white
                    p-5
                    shadow-sm

                    dark:border-slate-800
                    dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex h-11 w-11
                        items-center justify-center
                        rounded-xl
                        bg-emerald-100
                        text-xl text-emerald-700

                        dark:bg-emerald-950/60
                        dark:text-emerald-300
                    "
                >
                    <i class="bi bi-check-circle-fill"></i>
                </div>

                <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">
                    Resolved
                </p>

                <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100">
                    <?= (int)$stats['resolved'] ?>
                </p>
            </div>


            <div
                class="
                    rounded-2xl
                    border border-slate-200
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
                        flex h-11 w-11
                        items-center justify-center
                        rounded-xl
                        bg-slate-100
                        text-xl text-slate-600

                        dark:bg-slate-800
                        dark:text-slate-300
                    "
                >
                    <i class="bi bi-lock-fill"></i>
                </div>

                <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">
                    Closed
                </p>

                <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100">
                    <?= (int)$stats['closed'] ?>
                </p>
            </div>

        </section>


        <!-- Main grid -->
        <div
            class="
                grid
                gap-6

                xl:grid-cols-[420px_minmax(0,1fr)]
            "
        >

            <!-- =================================================
                 FILE COMPLAINT
                 ================================================= -->

            <section
                class="
                    h-fit

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
                                    bi bi-pencil-square
                                    text-emerald-700

                                    dark:text-emerald-400
                                "
                            ></i>

                            File a Complaint
                        </h2>

                        <p
                            class="
                                mt-1
                                text-sm
                                text-slate-500

                                dark:text-slate-400
                            "
                        >
                            Provide clear details so the HOA can respond properly.
                        </p>

                    </div>


                    <span
                        class="
                            inline-flex
                            shrink-0
                            items-center

                            rounded-lg

                            px-2.5
                            py-1.5

                            text-xs
                            font-bold

                            ring-1

                            <?= $isTenant
                                ? 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:ring-blue-900'
                                : 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900'
                            ?>
                        "
                    >
                        <?= $isTenant
                            ? 'Tenant'
                            : 'Homeowner'
                        ?>
                    </span>

                </div>


                <form
                    method="POST"
                    autocomplete="off"
                    class="p-5"
                >

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= esc($csrf) ?>"
                    >

                    <input
                        type="hidden"
                        name="file_complaint_submit"
                        value="1"
                    >


                    <!-- Subject -->
                    <div>

                        <label
                            for="subject"
                            class="
                                mb-2
                                block

                                text-sm
                                font-bold
                                text-slate-700

                                dark:text-slate-300
                            "
                        >
                            Subject
                        </label>

                        <input
                            type="text"
                            id="subject"
                            name="subject"
                            maxlength="255"
                            minlength="3"
                            required
                            value="<?= esc($formSubject) ?>"
                            placeholder="Short title for your concern"
                            class="
                                min-h-12
                                w-full

                                rounded-xl

                                border border-slate-300
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


                    <!-- Category + Priority -->
                    <div
                        class="
                            mt-4
                            grid
                            gap-4
                            sm:grid-cols-2
                        "
                    >

                        <div>

                            <label
                                for="category"
                                class="
                                    mb-2
                                    block

                                    text-sm
                                    font-bold
                                    text-slate-700

                                    dark:text-slate-300
                                "
                            >
                                Category
                            </label>

                            <select
                                id="category"
                                name="category"
                                required
                                class="
                                    min-h-12
                                    w-full

                                    rounded-xl

                                    border border-slate-300
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
                                <?php
                                $categoryOptions = [
                                    'general'     => 'General',
                                    'security'    => 'Security',
                                    'maintenance' => 'Maintenance',
                                    'noise'       => 'Noise',
                                    'parking'     => 'Parking',
                                    'neighbor'    => 'Neighbor',
                                    'billing'     => 'Billing',
                                    'other'       => 'Other'
                                ];
                                ?>

                                <?php foreach ($categoryOptions as $value => $label): ?>

                                    <option
                                        value="<?= esc($value) ?>"
                                        <?= $formCategory === $value
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= esc($label) ?>
                                    </option>

                                <?php endforeach; ?>
                            </select>

                        </div>


                        <div>

                            <label
                                for="priority"
                                class="
                                    mb-2
                                    block

                                    text-sm
                                    font-bold
                                    text-slate-700

                                    dark:text-slate-300
                                "
                            >
                                Priority
                            </label>

                            <select
                                id="priority"
                                name="priority"
                                required
                                class="
                                    min-h-12
                                    w-full

                                    rounded-xl

                                    border border-slate-300
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
                                <?php
                                $priorityOptions = [
                                    'low'    => 'Low',
                                    'normal' => 'Normal',
                                    'high'   => 'High',
                                    'urgent' => 'Urgent'
                                ];
                                ?>

                                <?php foreach ($priorityOptions as $value => $label): ?>

                                    <option
                                        value="<?= esc($value) ?>"
                                        <?= $formPriority === $value
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= esc($label) ?>
                                    </option>

                                <?php endforeach; ?>
                            </select>

                        </div>

                    </div>


                    <!-- Description -->
                    <div class="mt-4">

                        <div
                            class="
                                mb-2
                                flex
                                items-center
                                justify-between
                                gap-3
                            "
                        >

                            <label
                                for="description"
                                class="
                                    text-sm
                                    font-bold
                                    text-slate-700

                                    dark:text-slate-300
                                "
                            >
                                Complaint Details
                            </label>

                            <span
                                id="descriptionCount"
                                class="
                                    text-xs
                                    font-medium
                                    text-slate-400

                                    dark:text-slate-500
                                "
                            >
                                0 / 3000
                            </span>

                        </div>


                        <textarea
                            id="description"
                            name="description"
                            rows="7"
                            maxlength="3000"
                            minlength="10"
                            required
                            placeholder="Describe what happened, where it happened, and any details the HOA should know."
                            class="
                                w-full
                                resize-y

                                rounded-xl

                                border border-slate-300
                                bg-white

                                px-3
                                py-3

                                text-base
                                leading-6
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
                        ><?= esc($formDescription) ?></textarea>

                    </div>


                    <div
                        class="
                            mt-4

                            flex
                            items-start
                            gap-3

                            rounded-xl

                            bg-blue-50

                            p-3

                            text-sm
                            leading-6
                            text-blue-800

                            dark:bg-blue-950/40
                            dark:text-blue-300
                        "
                    >
                        <i
                            class="
                                bi bi-info-circle-fill
                                mt-0.5 shrink-0
                            "
                        ></i>

                        <span>
                            Your complaint is sent to your phase admin.
                            You can continue the discussion in Complaint Chat
                            after submission.
                        </span>
                    </div>


                    <button
                        type="submit"
                        class="
                            mt-5

                            flex
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
                        <i class="bi bi-send-fill"></i>
                        Submit Complaint
                    </button>

                </form>

            </section>


            <!-- =================================================
                 RECORDS
                 ================================================= -->

            <section
                class="
                    min-w-0

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
                                    bi bi-journal-text
                                    text-blue-700

                                    dark:text-blue-300
                                "
                            ></i>

                            My Complaint Records
                        </h2>

                        <p
                            class="
                                mt-1
                                text-sm
                                text-slate-500

                                dark:text-slate-400
                            "
                        >
                            <?= count($complaints) ?>
                            complaint<?= count($complaints) === 1 ? '' : 's' ?>
                        </p>

                    </div>


                    <a
                        href="homeowner_complaint_chat.php"
                        class="
                            inline-flex
                            min-h-11
                            w-fit
                            items-center
                            justify-center
                            gap-2

                            rounded-xl

                            border border-emerald-200
                            bg-emerald-50

                            px-4

                            text-sm
                            font-semibold
                            text-emerald-700

                            transition

                            hover:bg-emerald-100

                            dark:border-emerald-900
                            dark:bg-emerald-950/40
                            dark:text-emerald-300
                            dark:hover:bg-emerald-950/60
                        "
                    >
                        <i class="bi bi-chat-dots-fill"></i>
                        Go to Chat
                    </a>

                </div>


                <?php if (empty($complaints)): ?>

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

                                bg-slate-100

                                text-3xl
                                text-slate-400

                                dark:bg-slate-800
                                dark:text-slate-500
                            "
                        >
                            <i class="bi bi-inbox"></i>
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
                            No complaints filed yet
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
                            When you submit a complaint, its status and
                            conversation will appear here.
                        </p>

                    </div>

                <?php else: ?>

                    <div
                        class="
                            max-h-[950px]
                            space-y-4
                            overflow-y-auto
                            p-4

                            sm:p-5
                        "
                    >

                        <?php foreach ($complaints as $c): ?>

                            <?php

                            $status =
                                (string)(
                                    $c['status']
                                    ?? 'open'
                                );

                            $priority =
                                (string)(
                                    $c['priority']
                                    ?? 'normal'
                                );

                            ?>

                            <article
                                class="
                                    overflow-hidden

                                    rounded-2xl

                                    border border-slate-200
                                    bg-slate-50/60

                                    transition

                                    hover:border-slate-300

                                    dark:border-slate-700
                                    dark:bg-slate-800/40
                                    dark:hover:border-slate-600
                                "
                            >

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
                                        sm:items-start
                                        sm:justify-between

                                        dark:border-slate-700
                                    "
                                >

                                    <div class="min-w-0">

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
                                            Complaint #<?= (int)$c['id'] ?>
                                        </p>

                                        <h3
                                            class="
                                                mt-1
                                                break-words

                                                text-base
                                                font-bold
                                                text-slate-900

                                                sm:text-lg

                                                dark:text-slate-100
                                            "
                                        >
                                            <?= esc($c['subject']) ?>
                                        </h3>

                                        <div
                                            class="
                                                mt-2

                                                flex
                                                flex-wrap
                                                items-center
                                                gap-x-2
                                                gap-y-1

                                                text-xs
                                                text-slate-500

                                                dark:text-slate-400
                                            "
                                        >
                                            <span>
                                                <?= esc(
                                                    ucwords(
                                                        str_replace(
                                                            '_',
                                                            ' ',
                                                            (string)$c['category']
                                                        )
                                                    )
                                                ) ?>
                                            </span>

                                            <span>•</span>

                                            <span>
                                                Filed
                                                <?= esc(
                                                    display_datetime(
                                                        $c['created_at']
                                                        ?? null
                                                    )
                                                ) ?>
                                            </span>
                                        </div>

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

                                                <?= complaint_status_classes(
                                                    $status
                                                ) ?>
                                            "
                                        >
                                            <i
                                                class="
                                                    bi
                                                    <?= esc(
                                                        complaint_status_icon(
                                                            $status
                                                        )
                                                    ) ?>
                                                "
                                            ></i>

                                            <?= esc(
                                                complaint_status_label(
                                                    $status
                                                )
                                            ) ?>
                                        </span>


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

                                                <?= complaint_priority_classes(
                                                    $priority
                                                ) ?>
                                            "
                                        >
                                            <i
                                                class="
                                                    bi
                                                    <?= esc(
                                                        complaint_priority_icon(
                                                            $priority
                                                        )
                                                    ) ?>
                                                "
                                            ></i>

                                            <?= esc(
                                                strtoupper(
                                                    $priority
                                                )
                                            ) ?>
                                        </span>

                                    </div>

                                </div>


                                <div class="p-4">

                                    <p
                                        class="
                                            whitespace-pre-wrap
                                            break-words

                                            text-sm
                                            leading-6
                                            text-slate-700

                                            dark:text-slate-300
                                        "
                                    ><?= esc($c['description']) ?></p>


                                    <div
                                        class="
                                            mt-4

                                            grid
                                            gap-3

                                            rounded-xl

                                            bg-white

                                            p-3

                                            text-xs

                                            ring-1
                                            ring-slate-200

                                            sm:grid-cols-2

                                            dark:bg-slate-900
                                            dark:ring-slate-700
                                        "
                                    >

                                        <div>

                                            <p
                                                class="
                                                    font-bold
                                                    uppercase
                                                    tracking-wide
                                                    text-slate-400

                                                    dark:text-slate-500
                                                "
                                            >
                                                Assigned Admin
                                            </p>

                                            <p
                                                class="
                                                    mt-1

                                                    font-semibold
                                                    text-slate-700

                                                    dark:text-slate-300
                                                "
                                            >
                                                <?= esc(
                                                    $c['admin_name']
                                                    ?: 'Phase Admin'
                                                ) ?>
                                            </p>

                                        </div>


                                        <div>

                                            <p
                                                class="
                                                    font-bold
                                                    uppercase
                                                    tracking-wide
                                                    text-slate-400

                                                    dark:text-slate-500
                                                "
                                            >
                                                Last Update
                                            </p>

                                            <p
                                                class="
                                                    mt-1

                                                    font-semibold
                                                    text-slate-700

                                                    dark:text-slate-300
                                                "
                                            >
                                                <?= esc(
                                                    display_datetime(
                                                        $c['updated_at']
                                                        ?? null
                                                    )
                                                ) ?>
                                            </p>

                                        </div>

                                    </div>


                                    <div
                                        class="
                                            mt-4

                                            flex
                                            flex-col
                                            gap-2

                                            sm:flex-row
                                            sm:items-center
                                            sm:justify-between
                                        "
                                    >

                                        <?php if (!empty($c['last_message_at'])): ?>

                                            <div
                                                class="
                                                    text-xs
                                                    text-slate-500

                                                    dark:text-slate-400
                                                "
                                            >
                                                <i class="bi bi-chat-left-text mr-1"></i>

                                                Last message:
                                                <?= esc(
                                                    display_datetime(
                                                        $c['last_message_at']
                                                    )
                                                ) ?>
                                            </div>

                                        <?php else: ?>

                                            <div></div>

                                        <?php endif; ?>


                                        <a
                                            href="homeowner_complaint_chat.php?complaint_id=<?= (int)$c['id'] ?>"
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
                                            <i class="bi bi-chat-left-text-fill"></i>
                                            Open Conversation
                                        </a>

                                    </div>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

        </div>


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


<!-- =========================================================
     REQUIRED PASSWORD CHANGE MODAL
     ========================================================= -->

<?php if (!$isTenant && $mustChange): ?>

    <div
        class="
            fixed inset-0 z-[300]

            flex
            items-center
            justify-center

            bg-slate-950/70

            p-4

            backdrop-blur-sm
        "
    >

        <div
            class="
                w-full
                max-w-md

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
                    items-start
                    gap-3

                    bg-emerald-700

                    px-5
                    py-4

                    text-white

                    dark:bg-emerald-600
                "
            >

                <div
                    class="
                        flex
                        h-11 w-11
                        shrink-0
                        items-center
                        justify-center

                        rounded-xl

                        bg-white/15

                        text-xl
                    "
                >
                    <i class="bi bi-shield-lock-fill"></i>
                </div>


                <div>

                    <h2 class="font-bold">
                        Change Password Required
                    </h2>

                    <p
                        class="
                            mt-1

                            text-sm
                            leading-5
                            text-emerald-50
                        "
                    >
                        You must change your password before continuing.
                    </p>

                </div>

            </div>


            <form
                method="POST"
                autocomplete="off"
                class="p-5"
            >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= esc($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="change_password_submit"
                    value="1"
                >


                <?php if ($err !== ''): ?>

                    <div
                        class="
                            mb-4

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
                        <i class="bi bi-exclamation-circle-fill mr-1"></i>
                        <?= esc($err) ?>
                    </div>

                <?php endif; ?>


                <div>

                    <label
                        for="newPassword"
                        class="
                            mb-2
                            block

                            text-sm
                            font-bold
                            text-slate-700

                            dark:text-slate-300
                        "
                    >
                        New Password
                    </label>

                    <input
                        type="password"
                        id="newPassword"
                        name="password"
                        minlength="8"
                        required
                        autocomplete="new-password"
                        class="
                            min-h-12
                            w-full

                            rounded-xl

                            border border-slate-300
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

                    <p
                        class="
                            mt-1.5

                            text-xs
                            text-slate-500

                            dark:text-slate-400
                        "
                    >
                        Use at least 8 characters.
                    </p>

                </div>


                <div class="mt-4">

                    <label
                        for="confirmPassword"
                        class="
                            mb-2
                            block

                            text-sm
                            font-bold
                            text-slate-700

                            dark:text-slate-300
                        "
                    >
                        Confirm Password
                    </label>

                    <input
                        type="password"
                        id="confirmPassword"
                        name="password2"
                        minlength="8"
                        required
                        autocomplete="new-password"
                        class="
                            min-h-12
                            w-full

                            rounded-xl

                            border border-slate-300
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


                <button
                    type="submit"
                    class="
                        mt-5

                        flex
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

                        dark:bg-emerald-600
                        dark:hover:bg-emerald-500
                        dark:focus:ring-emerald-950
                    "
                >
                    <i class="bi bi-check-circle-fill"></i>
                    Save Password
                </button>

            </form>

        </div>

    </div>

<?php endif; ?>


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


/*
|--------------------------------------------------------------------------
| Close inline messages
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


/*
|--------------------------------------------------------------------------
| Description counter
|--------------------------------------------------------------------------
*/

(function () {

    const textarea =
        document.getElementById(
            'description'
        );

    const counter =
        document.getElementById(
            'descriptionCount'
        );


    if (!textarea || !counter) {
        return;
    }


    function updateCount() {

        counter.textContent =
            textarea.value.length +
            ' / 3000';
    }


    textarea.addEventListener(
        'input',
        updateCount
    );


    updateCount();

})();
</script>

</body>
</html>