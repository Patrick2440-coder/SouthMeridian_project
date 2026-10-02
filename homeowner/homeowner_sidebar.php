<?php
/*
|--------------------------------------------------------------------------
| Homeowner Sidebar Safety Helpers
|--------------------------------------------------------------------------
*/
if (!function_exists('esc')) {
    function esc($value): string
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
/*
|--------------------------------------------------------------------------
| Safe sidebar variables
|--------------------------------------------------------------------------
*/
$activePage = isset($activePage)
    ? (string)$activePage
    : basename($_SERVER['PHP_SELF'] ?? '');
$initials = isset($initials)
    ? (string)$initials
    : '';
$fullName = isset($fullName)
    ? (string)$fullName
    : 'Homeowner';
$phase = isset($phase)
    ? (string)$phase
    : '';
$user = (
    isset($user) &&
    is_array($user)
)
    ? $user
    : [];
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
$tenantPages = [
    'homeowner_tenant.php',
    'homeowner_tenant_register.php'
];
$tenantOpen =
    in_array(
        $activePage,
        $tenantPages,
        true
    );
$isTenant =
    isset($isTenant)
        ? (bool)$isTenant
        : false;
$tenant =
    isset($tenant)
        ? $tenant
        : null;
/*
|--------------------------------------------------------------------------
| Sidebar Profile Picture
|--------------------------------------------------------------------------
*/
if (!isset($profilePictureUrl)) {
    $profilePictureUrl = '';
    if (
        !$isTenant &&
        isset($conn) &&
        !empty($_SESSION['homeowner_id'])
    ) {
        $sidebarHomeownerId =
            (int)$_SESSION['homeowner_id'];
        $profileStmt =
            $conn->prepare("
                SELECT profile_picture_path
                FROM homeowners
                WHERE id = ?
                LIMIT 1
            ");
        $profileStmt->bind_param(
            'i',
            $sidebarHomeownerId
        );
        $profileStmt->execute();
        $profileRow =
            $profileStmt
                ->get_result()
                ->fetch_assoc();
        $profileStmt->close();
        $sidebarProfilePath =
            trim(
                (string)(
                    $profileRow['profile_picture_path']
                    ?? ''
                )
            );
        if ($sidebarProfilePath !== '') {
            $profilePictureUrl =
                '../' .
                ltrim(
                    $sidebarProfilePath,
                    '/'
                );
        }
    }
}
/*
|--------------------------------------------------------------------------
| Tenant permissions
|--------------------------------------------------------------------------
*/
if (!function_exists('sb_tenant_can_access')) {
    function sb_tenant_can_access(
        string $module,
        ?array $tenant,
        bool $isTenant
    ): bool {
        if (!$isTenant) {
            return true;
        }
        if (!$tenant) {
            return false;
        }
        $map = [
            'dashboard'     => true,
            'announcements' => !empty($tenant['can_announcements']),
            'pay_dues'      => !empty($tenant['can_pay_dues']),
            'rentals'       => !empty($tenant['can_rent']),
            'parking'       => !empty($tenant['can_parking']),
            'complaints'    => true,
            'public_chat'   => true,
            'voting'        => false,
            'tenant_mgmt'   => false,
        ];
        return $map[$module] ?? false;
    }
}
/*
|--------------------------------------------------------------------------
| Active Tenant Count
|--------------------------------------------------------------------------
*/
$tenantCount = 0;
if (
    !$isTenant &&
    isset($conn) &&
    isset($_SESSION['homeowner_id'])
) {
    $hid =
        (int)$_SESSION['homeowner_id'];
    $tcnt =
        $conn->prepare("
            SELECT COUNT(*)
            FROM tenants
            WHERE homeowner_id = ?
              AND status = 'active'
        ");
    $tcnt->bind_param(
        "i",
        $hid
    );
    $tcnt->execute();
    $tcnt->bind_result(
        $tenantCount
    );
    $tcnt->fetch();
    $tcnt->close();
}
/*
|--------------------------------------------------------------------------
| Reusable Tailwind Classes
|--------------------------------------------------------------------------
*/
$navBase =
    'group flex min-h-12 w-full items-center gap-3 ' .
    'rounded-xl px-3 py-2.5 ' .
    'text-left text-[15px] font-semibold ' .
    'transition duration-150 ' .
    'focus:outline-none focus:ring-4 ' .
    'focus:ring-emerald-100 ' .
    'dark:focus:ring-emerald-950';
$navNormal =
    'text-slate-700 ' .
    'hover:bg-slate-100 hover:text-emerald-800 ' .
    'dark:text-slate-300 ' .
    'dark:hover:bg-slate-800 dark:hover:text-emerald-300';
$navActive =
    'bg-emerald-50 text-emerald-800 ' .
    'ring-1 ring-emerald-100 ' .
    'dark:bg-emerald-950/50 ' .
    'dark:text-emerald-300 ' .
    'dark:ring-emerald-900';
$iconBase =
    'flex h-9 w-9 shrink-0 ' .
    'items-center justify-center ' .
    'rounded-lg ' .
    'bg-slate-100 ' .
    'text-[18px] text-slate-600 ' .
    'transition ' .
    'group-hover:bg-emerald-100 ' .
    'group-hover:text-emerald-700 ' .
    'dark:bg-slate-800 ' .
    'dark:text-slate-400 ' .
    'dark:group-hover:bg-emerald-950/70 ' .
    'dark:group-hover:text-emerald-300';
$iconActive =
    'bg-emerald-100 text-emerald-700 ' .
    'dark:bg-emerald-950 ' .
    'dark:text-emerald-300';
?>
<aside
    id="sidebar"
    class="
        fixed inset-y-0 left-0 z-[60]
        flex
        w-[280px]
        max-w-[88vw]
        -translate-x-full
        flex-col
        border-r
        border-slate-200
        bg-white
        shadow-2xl
        transition-transform
        duration-300
        ease-out
        dark:border-slate-800
        dark:bg-slate-900
        lg:translate-x-0
        lg:shadow-none
    "
    aria-label="Homeowner navigation"
>
    <!-- =========================================================
         BRAND
         ========================================================= -->
    <div
        class="
            flex
            min-h-[76px]
            items-center
            justify-between
            border-b
            border-slate-200
            px-4
            dark:border-slate-800
        "
    >
        <a
            href="homeowner_dashboard.php"
            class="
                flex
                min-w-0
                items-center
                gap-3
            "
        >
            <div
                class="
                    flex
                    h-11
                    w-11
                    shrink-0
                    items-center
                    justify-center
                    rounded-xl
                    bg-emerald-700
                    text-xl
                    text-white
                    shadow-sm
                    dark:bg-emerald-600
                "
            >
                <i
                    class="bi bi-houses-fill"
                    aria-hidden="true"
                ></i>
            </div>
            <div class="min-w-0">
                <div
                    class="
                        truncate
                        text-[15px]
                        font-bold
                        text-slate-900
                        dark:text-slate-100
                    "
                >
                    South Meridian
                </div>
                <div
                    class="
                        text-xs
                        font-medium
                        text-slate-500
                        dark:text-slate-400
                    "
                >
                    Homeowner Portal
                </div>
            </div>
        </a>
        <!-- Mobile Close -->
        <button
            type="button"
            id="sidebarClose"
            class="
                flex
                h-11
                w-11
                items-center
                justify-center
                rounded-xl
                text-xl
                text-slate-600
                transition
                hover:bg-slate-100
                focus:outline-none
                focus:ring-4
                focus:ring-emerald-100
                dark:text-slate-300
                dark:hover:bg-slate-800
                dark:focus:ring-emerald-950
                lg:hidden
            "
            aria-label="Close menu"
        >
            <i
                class="bi bi-x-lg"
                aria-hidden="true"
            ></i>
        </button>
    </div>
    <!-- =========================================================
         USER CARD
         ========================================================= -->
    <div
        class="
            border-b
            border-slate-200
            p-4
            dark:border-slate-800
        "
    >
        <div
            class="
                flex
                items-center
                gap-3
                rounded-2xl
                bg-emerald-50
                p-3
                ring-1
                ring-emerald-100
                dark:bg-emerald-950/40
                dark:ring-emerald-900
            "
        >
<div
    class="
        flex
        h-12
        w-12
        shrink-0
        items-center
        justify-center
        overflow-hidden
        rounded-xl
        bg-emerald-700
        text-base
        font-bold
        text-white
        dark:bg-emerald-600
    "
>
    <?php if (!empty($profilePictureUrl)): ?>
        <img
            src="<?= esc($profilePictureUrl) ?>"
            alt="<?= esc($fullName) ?>"
            class="h-full w-full object-cover"
            loading="lazy"
        >
    <?php else: ?>
        <?= esc($initials) ?>
    <?php endif; ?>
</div>
            <!-- User Details -->
            <div class="min-w-0">
                <p
                    class="
                        truncate
                        text-[15px]
                        font-bold
                        text-slate-900
                        dark:text-slate-100
                    "
                >
                    <?= esc($fullName) ?>
                </p>
                <p
                    class="
                        mt-0.5
                        truncate
                        text-xs
                        font-medium
                        text-slate-600
                        dark:text-slate-400
                    "
                >
                    <?= esc($phase) ?>
                    <?php if (!empty($user['house_lot_number'])): ?>
                        • <?= esc($user['house_lot_number']) ?>
                    <?php endif; ?>
                    <?php if ($isTenant): ?>
                        • Tenant
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
    <!-- =========================================================
         NAVIGATION
         ========================================================= -->
    <nav
        class="
            flex-1
            overflow-y-auto
            px-3
            py-4
        "
    >
        <!-- MAIN MENU LABEL -->
        <div
            class="
                mb-2
                px-3
                text-[11px]
                font-bold
                uppercase
                tracking-[0.12em]
                text-slate-400
                dark:text-slate-500
            "
        >
            Main Menu
        </div>
        <div class="space-y-1">
            <!-- =================================================
                 DASHBOARD
                 ================================================= -->
            <a
                href="homeowner_dashboard.php"
                class="
                    <?= $navBase ?>
                    <?= $activePage === 'homeowner_dashboard.php'
                        ? $navActive
                        : $navNormal
                    ?>
                "
                <?= $activePage === 'homeowner_dashboard.php'
                    ? 'aria-current="page"'
                    : ''
                ?>
            >
                <span
                    class="
                        <?= $iconBase ?>
                        <?= $activePage === 'homeowner_dashboard.php'
                            ? $iconActive
                            : ''
                        ?>
                    "
                >
                    <i
                        class="bi bi-house-door-fill"
                        aria-hidden="true"
                    ></i>
                </span>
                <span>
                    Dashboard
                </span>
            </a>
            <!-- =================================================
                 MY DOCUMENTS
                 ================================================= -->
            <?php if (!$isTenant): ?>
                <a
                    href="homeowner_documents.php"
                    class="
                        <?= $navBase ?>
                        <?= $activePage === 'homeowner_documents.php'
                            ? $navActive
                            : $navNormal
                        ?>
                    "
                    <?= $activePage === 'homeowner_documents.php'
                        ? 'aria-current="page"'
                        : ''
                    ?>
                >
                    <span
                        class="
                            <?= $iconBase ?>
                            <?= $activePage === 'homeowner_documents.php'
                                ? $iconActive
                                : ''
                            ?>
                        "
                    >
                        <i
                            class="bi bi-folder-fill"
                            aria-hidden="true"
                        ></i>
                    </span>
                    <span>
                        My Documents
                    </span>
                </a>
            <?php endif; ?>

            <!-- =================================================
                 ANNOUNCEMENTS
                 ================================================= -->
            <?php if (
                sb_tenant_can_access(
                    'announcements',
                    $tenant,
                    $isTenant
                )
            ): ?>
                <a
                    href="homeowner_dashboard.php#feed"
                    class="
                        <?= $navBase ?>
                        <?= $navNormal ?>
                    "
                >
                    <span class="<?= $iconBase ?>">
                        <i
                            class="bi bi-megaphone-fill"
                            aria-hidden="true"
                        ></i>
                    </span>
                    <span>
                        Announcements
                    </span>
                </a>
            <?php endif; ?>
            <!-- =================================================
                 COMMUNITY CHAT
                 ================================================= -->
            <?php if (
                sb_tenant_can_access(
                    'public_chat',
                    $tenant,
                    $isTenant
                )
            ): ?>
                <a
                    href="homeowner_public_chat.php"
                    class="
                        <?= $navBase ?>
                        <?= $activePage === 'homeowner_public_chat.php'
                            ? $navActive
                            : $navNormal
                        ?>
                    "
                    <?= $activePage === 'homeowner_public_chat.php'
                        ? 'aria-current="page"'
                        : ''
                    ?>
                >
                    <span
                        class="
                            <?= $iconBase ?>
                            <?= $activePage === 'homeowner_public_chat.php'
                                ? $iconActive
                                : ''
                            ?>
                        "
                    >
                        <i
                            class="bi bi-people-fill"
                            aria-hidden="true"
                        ></i>
                    </span>
                    <span>
                        Community Chat
                    </span>
                </a>
            <?php endif; ?>
            <!-- =================================================
                 MONTHLY DUES
                 ================================================= -->
            <?php if (
                sb_tenant_can_access(
                    'pay_dues',
                    $tenant,
                    $isTenant
                )
            ): ?>
                <a
                    href="homeowner_pay_dues.php"
                    class="
                        <?= $navBase ?>
                        <?= $activePage === 'homeowner_pay_dues.php'
                            ? $navActive
                            : $navNormal
                        ?>
                    "
                    <?= $activePage === 'homeowner_pay_dues.php'
                        ? 'aria-current="page"'
                        : ''
                    ?>
                >
                    <span
                        class="
                            <?= $iconBase ?>
                            <?= $activePage === 'homeowner_pay_dues.php'
                                ? $iconActive
                                : ''
                            ?>
                        "
                    >
                        <i
                            class="bi bi-wallet2"
                            aria-hidden="true"
                        ></i>
                    </span>
                    <span>
                        Pay Monthly Dues
                    </span>
                </a>
            <?php endif; ?>
            <!-- =================================================
                 FACILITY RENTALS
                 ================================================= -->
            <?php if (
                sb_tenant_can_access(
                    'rentals',
                    $tenant,
                    $isTenant
                )
            ): ?>
                <a
                    href="homeowner_rentals.php"
                    class="
                        <?= $navBase ?>
                        <?= $activePage === 'homeowner_rentals.php'
                            ? $navActive
                            : $navNormal
                        ?>
                    "
                    <?= $activePage === 'homeowner_rentals.php'
                        ? 'aria-current="page"'
                        : ''
                    ?>
                >
                    <span
                        class="
                            <?= $iconBase ?>
                            <?= $activePage === 'homeowner_rentals.php'
                                ? $iconActive
                                : ''
                            ?>
                        "
                    >
                        <i
                            class="bi bi-calendar2-week-fill"
                            aria-hidden="true"
                        ></i>
                    </span>
                    <span>
                        Facility Rentals
                    </span>
                </a>
            <?php endif; ?>
            <!-- =================================================
                 PARKING
                 ================================================= -->
            <?php if (
                sb_tenant_can_access(
                    'parking',
                    $tenant,
                    $isTenant
                )
            ): ?>
                <div id="sbParking">
                    <button
                        type="button"
                        id="sbParkingToggle"
                        class="
                            <?= $navBase ?>
                            <?= $parkingOpen
                                ? $navActive
                                : $navNormal
                            ?>
                        "
                        aria-expanded="<?= $parkingOpen ? 'true' : 'false' ?>"
                        aria-controls="sbParkingMenu"
                    >
                        <span
                            class="
                                <?= $iconBase ?>
                                <?= $parkingOpen
                                    ? $iconActive
                                    : ''
                                ?>
                            "
                        >
                            <i
                                class="bi bi-car-front-fill"
                                aria-hidden="true"
                            ></i>
                        </span>
                        <span class="flex-1">
                            Parking
                        </span>
                        <i
                            id="sbParkingCaret"
                            class="
                                bi
                                bi-chevron-down
                                text-sm
                                transition-transform
                                duration-200
                                <?= $parkingOpen
                                    ? 'rotate-180'
                                    : ''
                                ?>
                            "
                            aria-hidden="true"
                        ></i>
                    </button>
                    <div
                        id="sbParkingMenu"
                        class="
                            mt-1
                            space-y-1
                            border-l-2
                            border-emerald-100
                            pl-3
                            dark:border-emerald-900
                            <?= $parkingOpen
                                ? ''
                                : 'hidden'
                            ?>
                        "
                    >
                        <!-- Parking Overview -->
                        <a
                            href="homeowner_parking.php"
                            class="
                                flex
                                min-h-11
                                items-center
                                gap-3
                                rounded-xl
                                px-3
                                py-2
                                text-sm
                                font-semibold
                                transition
                                <?= $activePage === 'homeowner_parking.php'
                                    ? '
                                        bg-emerald-50
                                        text-emerald-800
                                        dark:bg-emerald-950/50
                                        dark:text-emerald-300
                                      '
                                    : '
                                        text-slate-600
                                        hover:bg-slate-100
                                        hover:text-emerald-800
                                        dark:text-slate-400
                                        dark:hover:bg-slate-800
                                        dark:hover:text-emerald-300
                                      '
                                ?>
                            "
                        >
                            <i
                                class="bi bi-info-circle-fill"
                                aria-hidden="true"
                            ></i>
                            <span>
                                Parking Overview
                            </span>
                        </a>
                        <!-- Permit -->
                        <a
                            href="homeowner_parking_permit.php"
                            class="
                                flex
                                min-h-11
                                items-center
                                gap-3
                                rounded-xl
                                px-3
                                py-2
                                text-sm
                                font-semibold
                                transition
                                <?= $activePage === 'homeowner_parking_permit.php'
                                    ? '
                                        bg-emerald-50
                                        text-emerald-800
                                        dark:bg-emerald-950/50
                                        dark:text-emerald-300
                                      '
                                    : '
                                        text-slate-600
                                        hover:bg-slate-100
                                        hover:text-emerald-800
                                        dark:text-slate-400
                                        dark:hover:bg-slate-800
                                        dark:hover:text-emerald-300
                                      '
                                ?>
                            "
                        >
                            <i
                                class="bi bi-card-checklist"
                                aria-hidden="true"
                            ></i>
                            <span>
                                Apply / Renew Permit
                            </span>
                        </a>
                        <!-- Violations -->
                        <a
                            href="homeowner_parking_violations.php"
                            class="
                                flex
                                min-h-11
                                items-center
                                gap-3
                                rounded-xl
                                px-3
                                py-2
                                text-sm
                                font-semibold
                                transition
                                <?= $activePage === 'homeowner_parking_violations.php'
                                    ? '
                                        bg-emerald-50
                                        text-emerald-800
                                        dark:bg-emerald-950/50
                                        dark:text-emerald-300
                                      '
                                    : '
                                        text-slate-600
                                        hover:bg-slate-100
                                        hover:text-emerald-800
                                        dark:text-slate-400
                                        dark:hover:bg-slate-800
                                        dark:hover:text-emerald-300
                                      '
                                ?>
                            "
                        >
                            <i
                                class="bi bi-exclamation-triangle-fill"
                                aria-hidden="true"
                            ></i>
                            <span>
                                My Violations
                            </span>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            <!-- =================================================
                 COMPLAINTS
                 ================================================= -->
            <?php if (
                sb_tenant_can_access(
                    'complaints',
                    $tenant,
                    $isTenant
                )
            ): ?>
                <a
                    href="homeowner_complaints.php"
                    class="
                        <?= $navBase ?>
                        <?= $activePage === 'homeowner_complaints.php'
                            ? $navActive
                            : $navNormal
                        ?>
                    "
                    <?= $activePage === 'homeowner_complaints.php'
                        ? 'aria-current="page"'
                        : ''
                    ?>
                >
                    <span
                        class="
                            <?= $iconBase ?>
                            <?= $activePage === 'homeowner_complaints.php'
                                ? $iconActive
                                : ''
                            ?>
                        "
                    >
                        <i
                            class="bi bi-chat-left-text-fill"
                            aria-hidden="true"
                        ></i>
                    </span>
                    <span>
                        Complaints
                    </span>
                </a>
            <?php endif; ?>
            <!-- =================================================
                 VOTING
                 ================================================= -->
            <?php if (
                sb_tenant_can_access(
                    'voting',
                    $tenant,
                    $isTenant
                )
            ): ?>
                <a
                    href="homeowner_voting.php"
                    class="
                        <?= $navBase ?>
                        <?= $activePage === 'homeowner_voting.php'
                            ? $navActive
                            : $navNormal
                        ?>
                    "
                    <?= $activePage === 'homeowner_voting.php'
                        ? 'aria-current="page"'
                        : ''
                    ?>
                >
                    <span
                        class="
                            <?= $iconBase ?>
                            <?= $activePage === 'homeowner_voting.php'
                                ? $iconActive
                                : ''
                            ?>
                        "
                    >
                        <i
                            class="bi bi-check2-square"
                            aria-hidden="true"
                        ></i>
                    </span>
                    <span>
                        Voting
                    </span>
                </a>
            <?php endif; ?>
        </div>
        <!-- =====================================================
             HOUSEHOLD SECTION
             ===================================================== -->
        <?php if (!$isTenant): ?>
            <div
                class="
                    mb-2
                    mt-6
                    px-3
                    text-[11px]
                    font-bold
                    uppercase
                    tracking-[0.12em]
                    text-slate-400
                    dark:text-slate-500
                "
            >
                Household
            </div>
            <!-- =================================================
                 MY RELATIVES
                 ================================================= -->
            <a
                href="homeowner_relatives.php"
                class="
                    <?= $navBase ?>
                    <?= $activePage === 'homeowner_relatives.php'
                        ? $navActive
                        : $navNormal
                    ?>
                "
                <?= $activePage === 'homeowner_relatives.php'
                    ? 'aria-current="page"'
                    : ''
                ?>
            >
                <span
                    class="
                        <?= $iconBase ?>
                        <?= $activePage === 'homeowner_relatives.php'
                            ? $iconActive
                            : ''
                        ?>
                    "
                >
                    <i
                        class="bi bi-people-fill"
                        aria-hidden="true"
                    ></i>
                </span>
                <span>
                    My Relatives
                </span>
            </a>
            <div id="sbTenant">
                <button
                    type="button"
                    id="sbTenantToggle"
                    class="
                        <?= $navBase ?>
                        <?= $tenantOpen
                            ? $navActive
                            : $navNormal
                        ?>
                    "
                    aria-expanded="<?= $tenantOpen ? 'true' : 'false' ?>"
                    aria-controls="sbTenantMenu"
                >
                    <span
                        class="
                            <?= $iconBase ?>
                            <?= $tenantOpen
                                ? $iconActive
                                : ''
                            ?>
                        "
                    >
                        <i
                            class="bi bi-person-badge-fill"
                            aria-hidden="true"
                        ></i>
                    </span>
                    <span class="flex-1">
                        My Tenant
                    </span>
                    <?php if ($tenantCount > 0): ?>
                        <span
                            class="
                                inline-flex
                                min-w-6
                                items-center
                                justify-center
                                rounded-full
                                bg-emerald-700
                                px-2
                                py-0.5
                                text-xs
                                font-bold
                                text-white
                                dark:bg-emerald-600
                            "
                        >
                            <?= (int)$tenantCount ?>
                        </span>
                    <?php endif; ?>
                    <i
                        id="sbTenantCaret"
                        class="
                            bi
                            bi-chevron-down
                            text-sm
                            transition-transform
                            duration-200
                            <?= $tenantOpen
                                ? 'rotate-180'
                                : ''
                            ?>
                        "
                        aria-hidden="true"
                    ></i>
                </button>
                <div
                    id="sbTenantMenu"
                    class="
                        mt-1
                        space-y-1
                        border-l-2
                        border-emerald-100
                        pl-3
                        dark:border-emerald-900
                        <?= $tenantOpen
                            ? ''
                            : 'hidden'
                        ?>
                    "
                >
                    <a
                        href="homeowner_tenant_register.php"
                        class="
                            flex
                            min-h-11
                            items-center
                            gap-3
                            rounded-xl
                            px-3
                            py-2
                            text-sm
                            font-semibold
                            transition
                            <?= $activePage === 'homeowner_tenant_register.php'
                                ? '
                                    bg-emerald-50
                                    text-emerald-800
                                    dark:bg-emerald-950/50
                                    dark:text-emerald-300
                                  '
                                : '
                                    text-slate-600
                                    hover:bg-slate-100
                                    hover:text-emerald-800
                                    dark:text-slate-400
                                    dark:hover:bg-slate-800
                                    dark:hover:text-emerald-300
                                  '
                            ?>
                        "
                    >
                        <i
                            class="bi bi-person-plus-fill"
                            aria-hidden="true"
                        ></i>
                        <span>
                            Register Tenant
                        </span>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </nav>
    <!-- =========================================================
         FOOTER / HELP
         ========================================================= -->
    <div
        class="
            border-t
            border-slate-200
            p-3
            dark:border-slate-800
        "
    >
        <div
            class="
                rounded-xl
                bg-slate-50
                p-3
                dark:bg-slate-800/60
            "
        >
            <div
                class="
                    flex
                    items-center
                    gap-2
                    text-sm
                    font-bold
                    text-slate-800
                    dark:text-slate-200
                "
            >
                <i
                    class="
                        bi
                        bi-question-circle-fill
                        text-emerald-700
                        dark:text-emerald-400
                    "
                    aria-hidden="true"
                ></i>
                Need help?
            </div>
            <p
                class="
                    mt-1
                    text-xs
                    leading-5
                    text-slate-500
                    dark:text-slate-400
                "
            >
                Contact your HOA officer if you need help
                using the portal.
            </p>
        </div>
    </div>
</aside>
