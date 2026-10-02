<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'admin_access.php';
require_once '../config/database.php';

requireAccess('activity_log');

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

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

function validDate(string $date): bool
{
    if ($date === '') {
        return true;
    }

    $parsed =
        DateTime::createFromFormat(
            'Y-m-d',
            $date
        );

    return
        $parsed !== false &&
        $parsed->format('Y-m-d') ===
            $date;
}

function bindDynamicParams(
    mysqli_stmt $stmt,
    string $types,
    array &$params
): void {
    if (
        $types === '' ||
        !$params
    ) {
        return;
    }

    $refs = [
        &$types
    ];

    foreach (
        $params as $key => &$value
    ) {
        $refs[] =
            &$value;
    }

    call_user_func_array(
        [
            $stmt,
            'bind_param'
        ],
        $refs
    );
}

function readableModule(
    ?string $module
): string {
    $module =
        trim(
            (string)$module
        );

    if ($module === '') {
        return 'General';
    }

    $labels = [
        'homeowner_management' =>
            'Homeowner Management',
        'user_management' =>
            'User Management',
        'announcements' =>
            'Announcements',
        'complaints' =>
            'Complaints',
        'finance' =>
            'Finance',
        'parking' =>
            'Parking',
        'community' =>
            'Community',
        'activity_log' =>
            'Activity Log',
        'access_control' =>
            'Access Control',
        'cctv' =>
            'CCTV Monitoring',
        'login_security' =>
            'Login Security',
        'staff_management' =>
            'Staff Management',
        'settings' =>
            'Settings',
    ];

    return
        $labels[$module] ??
        ucwords(
            str_replace(
                '_',
                ' ',
                $module
            )
        );
}

/**
 * Turn technical key=value audit details into a cleaner description.
 * Internal-only values are intentionally hidden from the Activity Log UI.
 */
function readableDetails(
    ?string $details,
    ?string $action = null
): string {
    $details =
        trim(
            (string)$details
        );

    $action =
        trim(
            (string)$action
        );

    if ($details === '') {
        return
            'This activity was completed. No extra information was recorded.';
    }

    /*
    |--------------------------------------------------------------------------
    | Common activities explained in plain language
    |--------------------------------------------------------------------------
    */

    if (
        strcasecmp(
            $action,
            'Excel import processed'
        ) === 0
    ) {
        $added = 0;
        $duplicates = 0;
        $transfers = 0;
        $skipped = 0;

        if (
            preg_match(
                '/(\d+)\s+resident\(s\)\s+added\s+for\s+review/i',
                $details,
                $match
            )
        ) {
            $added =
                (int)$match[1];
        }

        if (
            preg_match(
                '/(\d+)\s+duplicate-account\s+record\(s\)/i',
                $details,
                $match
            )
        ) {
            $duplicates =
                (int)$match[1];
        }

        if (
            preg_match(
                '/(\d+)\s+possible\s+ownership\s+transfer\(s\)/i',
                $details,
                $match
            )
        ) {
            $transfers =
                (int)$match[1];
        }

        if (
            preg_match(
                '/(\d+)\s+row\(s\)\s+skipped/i',
                $details,
                $match
            )
        ) {
            $skipped =
                (int)$match[1];
        }

        $sentences = [
            'The officer imported homeowner records.'
        ];

        if ($added > 0) {
            $sentences[] =
                $added .
                ' resident' .
                ($added === 1 ? '' : 's') .
                ' ' .
                ($added === 1 ? 'was' : 'were') .
                ' added to the review list.';
        } else {
            $sentences[] =
                'No new resident was added to the review list.';
        }

        if ($duplicates > 0) {
            $sentences[] =
                $duplicates .
                ' possible duplicate account' .
                ($duplicates === 1 ? ' was' : 's were') .
                ' found and should be checked.';
        }

        if ($transfers > 0) {
            $sentences[] =
                $transfers .
                ' possible ownership transfer' .
                ($transfers === 1 ? ' needs' : 's need') .
                ' verification.';
        }

        if ($skipped > 0) {
            $sentences[] =
                $skipped .
                ' row' .
                ($skipped === 1 ? ' was' : 's were') .
                ' not imported because the information could not be accepted.';
        }

        return
            implode(
                ' ',
                $sentences
            );
    }

    if (
        strcasecmp(
            $action,
            'Ownership transfer verification started'
        ) === 0
    ) {
        $phase = '';
        $block = '';
        $lot = '';
        $currentHomeowner = '';
        $incomingHomeowner = '';

        if (
            preg_match(
                '/Transfer\s+#\d+:\s*([^,]+),\s*Block\s*([^,]+),\s*Lot\s*([^\.]+)\./i',
                $details,
                $match
            )
        ) {
            $phase =
                trim(
                    $match[1]
                );

            $block =
                trim(
                    $match[2]
                );

            $lot =
                trim(
                    $match[3]
                );
        }

        if (
            preg_match(
                '/Current homeowner:\s*([^\.]+)\./i',
                $details,
                $match
            )
        ) {
            $currentHomeowner =
                trim(
                    $match[1]
                );
        }

        if (
            preg_match(
                '/Incoming homeowner:\s*([^\.]+)\./i',
                $details,
                $match
            )
        ) {
            $incomingHomeowner =
                trim(
                    $match[1]
                );
        }

        $message =
            'The officer started an ownership transfer verification';

        $locationParts = [];

        if ($phase !== '') {
            $locationParts[] =
                $phase;
        }

        if ($block !== '') {
            $locationParts[] =
                'Block ' .
                $block;
        }

        if ($lot !== '') {
            $locationParts[] =
                'Lot ' .
                $lot;
        }

        if ($locationParts) {
            $message .=
                ' for ' .
                implode(
                    ', ',
                    $locationParts
                );
        }

        $message .= '.';

        if ($currentHomeowner !== '') {
            $message .=
                ' The current homeowner is ' .
                $currentHomeowner .
                '.';
        }

        if ($incomingHomeowner !== '') {
            $message .=
                ' The incoming homeowner is ' .
                $incomingHomeowner .
                '.';
        }

        if (
            stripos(
                $details,
                'Verification email'
            ) !== false
        ) {
            $message .=
                ' Verification emails were sent to the people involved.';
        }

        return
            $message;
    }

    if (
        strcasecmp(
            $action,
            'Officer account issued'
        ) === 0
    ) {
        $position = '';
        $email = '';

        if (
            preg_match(
                '/position=([^;]+)/i',
                $details,
                $match
            )
        ) {
            $position =
                trim(
                    $match[1]
                );
        }

        if (
            preg_match(
                '/shared_login_email=([^;]+)/i',
                $details,
                $match
            )
        ) {
            $email =
                trim(
                    $match[1]
                );
        }

        $message =
            'An HOA officer account was created';

        if ($position !== '') {
            $message .=
                ' for the ' .
                $position;
        }

        $message .= '.';

        if ($email !== '') {
            $message .=
                ' The login email is ' .
                $email .
                '.';
        }

        return
            $message;
    }

    /*
    |--------------------------------------------------------------------------
    | General details
    |--------------------------------------------------------------------------
    | Hide technical/security values, then explain the remaining information
    | as simple sentences.
    */

    $hiddenKeys = [
        'admin_id',
        'homeowner_id',
        'user_id',
        'session_id',
        'csrf',
        'csrf_token',
        'token',
        'reset_token',
        'password',
        'password_hash',
        'hash',
        'secret',
        'ip',
        'ip_address',
    ];

    $labels = [
        'position' =>
            'position',
        'shared_login_email' =>
            'login email',
        'email' =>
            'email',
        'phase' =>
            'phase',
        'block' =>
            'block',
        'lot' =>
            'lot',
        'status' =>
            'status',
        'reference_no' =>
            'reference number',
        'amount' =>
            'amount',
        'reason' =>
            'reason',
        'remarks' =>
            'remarks',
        'category' =>
            'category',
        'title' =>
            'title',
        'property' =>
            'property',
        'batch' =>
            'batch',
        'batch_id' =>
            'batch',
    ];

    $parts =
        preg_split(
            '/\s*;\s*/',
            $details
        );

    if (
        !is_array($parts) ||
        count($parts) <= 1
    ) {
        return
            $details;
    }

    $sentences = [];

    foreach ($parts as $part) {
        $part =
            trim(
                $part
            );

        if ($part === '') {
            continue;
        }

        if (
            !str_contains(
                $part,
                '='
            )
        ) {
            $sentences[] =
                rtrim(
                    $part,
                    '.'
                ) .
                '.';
            continue;
        }

        [
            $key,
            $value
        ] =
            array_pad(
                explode(
                    '=',
                    $part,
                    2
                ),
                2,
                ''
            );

        $key =
            strtolower(
                trim(
                    $key
                )
            );

        $value =
            trim(
                $value
            );

        if (
            $key === '' ||
            $value === '' ||
            in_array(
                $key,
                $hiddenKeys,
                true
            )
        ) {
            continue;
        }

        $label =
            $labels[$key] ??
            strtolower(
                str_replace(
                    '_',
                    ' ',
                    $key
                )
            );

        $sentences[] =
            'The ' .
            $label .
            ' is ' .
            $value .
            '.';
    }

    if (!$sentences) {
        return
            'The activity was completed. No other information needs to be shown.';
    }

    return
        implode(
            ' ',
            $sentences
        );
}

$adminId =
    (int)(
        $_SESSION[
            'admin_id'
        ] ??
        0
    );

if ($adminId <= 0) {
    header(
        'Location: index.php'
    );
    exit();
}

$adminStmt =
    $conn->prepare(
        "SELECT
            id,
            full_name,
            email,
            phase,
            role,
            position
         FROM admins
         WHERE id=?
         LIMIT 1"
    );

$adminStmt->bind_param(
    'i',
    $adminId
);

$adminStmt->execute();

$admin =
    $adminStmt
        ->get_result()
        ->fetch_assoc();

$adminStmt->close();

if (
    !$admin ||
    (string)(
        $admin['role'] ??
        ''
    ) !== 'admin'
) {
    session_destroy();

    header(
        'Location: index.php'
    );
    exit();
}

$adminRole =
    (string)(
        $admin['role'] ??
        ''
    );

$adminPhase =
    trim(
        (string)(
            $admin['phase'] ??
            ''
        )
    );

$adminDisplayName =
    trim(
        (string)(
            $admin[
                'full_name'
            ] ??
            ''
        )
    );

if ($adminDisplayName === '') {
    $adminDisplayName =
        'HOA Officer';
}

$view =
    $_GET['view'] ??
    '';

/*
|--------------------------------------------------------------------------
| Verify central activity log table
|--------------------------------------------------------------------------
*/

$activityLogReady =
    false;

$tableResult =
    $conn->query(
        "SHOW TABLES LIKE 'activity_logs'"
    );

if (
    $tableResult &&
    $tableResult->num_rows > 0
) {
    $activityLogReady =
        true;
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search =
    trim(
        (string)(
            $_GET['q'] ??
            ''
        )
    );

$moduleFilter =
    trim(
        (string)(
            $_GET['module'] ??
            ''
        )
    );

$dateFrom =
    trim(
        (string)(
            $_GET['date_from'] ??
            ''
        )
    );

$dateTo =
    trim(
        (string)(
            $_GET['date_to'] ??
            ''
        )
    );

if (!validDate($dateFrom)) {
    $dateFrom =
        '';
}

if (!validDate($dateTo)) {
    $dateTo =
        '';
}

if (
    $dateFrom !== '' &&
    $dateTo !== '' &&
    $dateFrom > $dateTo
) {
    [
        $dateFrom,
        $dateTo
    ] = [
        $dateTo,
        $dateFrom
    ];
}

$moduleOptions = [];
$logs = [];

$totalMatching = 0;
$todayCount = 0;
$moduleCount = 0;
$officerCount = 0;

if ($activityLogReady) {
    /*
    |--------------------------------------------------------------------------
    | Module choices
    |--------------------------------------------------------------------------
    */

    $moduleSql =
        "SELECT DISTINCT l.module_key
         FROM activity_logs l
         INNER JOIN admins a
           ON a.id = l.admin_id
         WHERE a.role = 'admin'
           AND l.module_key IS NOT NULL
           AND TRIM(l.module_key) <> ''
         ORDER BY l.module_key ASC";

    $moduleStmt =
        $conn->prepare(
            $moduleSql
        );

    $moduleStmt->execute();

    $moduleResult =
        $moduleStmt
            ->get_result();

    while (
        $moduleRow =
            $moduleResult
                ->fetch_assoc()
    ) {
        $moduleOptions[] =
            (string)(
                $moduleRow[
                    'module_key'
                ] ??
                ''
            );
    }

    $moduleStmt->close();

    if (
        $moduleFilter !== '' &&
        !in_array(
            $moduleFilter,
            $moduleOptions,
            true
        )
    ) {
        $moduleFilter =
            '';
    }

    /*
    |--------------------------------------------------------------------------
    | Central log query
    |--------------------------------------------------------------------------
    */

    $where = [];
    $params = [];
    $types = '';

    $where[] =
        "a.role = 'admin'";

    if ($moduleFilter !== '') {
        $where[] =
            'l.module_key = ?';

        $params[] =
            $moduleFilter;

        $types .=
            's';
    }

    if ($dateFrom !== '') {
        $where[] =
            'l.created_at >= ?';

        $params[] =
            $dateFrom .
            ' 00:00:00';

        $types .=
            's';
    }

    if ($dateTo !== '') {
        $where[] =
            'l.created_at < ?';

        $params[] =
            date(
                'Y-m-d H:i:s',
                strtotime(
                    $dateTo .
                    ' +1 day'
                )
            );

        $types .=
            's';
    }

    if ($search !== '') {
        $where[] =
            "(
                l.action LIKE ?
                OR l.details LIKE ?
                OR l.module_key LIKE ?
                OR l.phase LIKE ?
                OR a.full_name LIKE ?
                OR a.email LIKE ?
                OR a.position LIKE ?
            )";

        $like =
            '%' .
            $search .
            '%';

        for (
            $i = 0;
            $i < 7;
            $i++
        ) {
            $params[] =
                $like;

            $types .=
                's';
        }
    }

    if (!$where) {
        $where[] =
            '1=1';
    }

    $sql =
        "SELECT
            l.id,
            l.admin_id,
            l.phase,
            l.action,
            l.module_key,
            l.details,
            l.ip_address,
            l.created_at,
            a.full_name,
            a.email,
            a.position,
            a.role
         FROM activity_logs l
         INNER JOIN admins a
           ON a.id = l.admin_id
         WHERE " .
         implode(
             ' AND ',
             $where
         ) .
         " ORDER BY
            l.created_at DESC,
            l.id DESC
         LIMIT 500";

    $logStmt =
        $conn->prepare(
            $sql
        );

    bindDynamicParams(
        $logStmt,
        $types,
        $params
    );

    $logStmt->execute();

    $logs =
        $logStmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $logStmt->close();

    $totalMatching =
        count(
            $logs
        );

    /*
    |--------------------------------------------------------------------------
    | Summary cards
    |--------------------------------------------------------------------------
    */

    $todayStmt =
        $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM activity_logs l
             INNER JOIN admins a
               ON a.id = l.admin_id
             WHERE a.role = 'admin'
               AND l.created_at >= CURDATE()
               AND l.created_at < DATE_ADD(
                   CURDATE(),
                   INTERVAL 1 DAY
               )"
        );

    $moduleCountStmt =
        $conn->prepare(
            "SELECT
                COUNT(
                    DISTINCT l.module_key
                ) AS total
             FROM activity_logs l
             INNER JOIN admins a
               ON a.id = l.admin_id
             WHERE a.role = 'admin'
               AND l.module_key IS NOT NULL
               AND TRIM(l.module_key) <> ''"
        );

    $officerCountStmt =
        $conn->prepare(
            "SELECT
                COUNT(
                    DISTINCT l.admin_id
                ) AS total
             FROM activity_logs l
             INNER JOIN admins a
               ON a.id = l.admin_id
             WHERE a.role = 'admin'"
        );

    $todayStmt->execute();

    $todayCount =
        (int)(
            $todayStmt
                ->get_result()
                ->fetch_assoc()[
                    'total'
                ] ??
            0
        );

    $todayStmt->close();

    $moduleCountStmt
        ->execute();

    $moduleCount =
        (int)(
            $moduleCountStmt
                ->get_result()
                ->fetch_assoc()[
                    'total'
                ] ??
            0
        );

    $moduleCountStmt
        ->close();

    $officerCountStmt
        ->execute();

    $officerCount =
        (int)(
            $officerCountStmt
                ->get_result()
                ->fetch_assoc()[
                    'total'
                ] ??
            0
        );

    $officerCountStmt
        ->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Activity Log - South Meridian Homes</title>
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1, maximum-scale=1"
    >

    <link
        rel="apple-touch-icon"
        sizes="180x180"
        href="vendors/images/apple-touch-icon.png"
    >
    <link
        rel="icon"
        type="image/png"
        sizes="32x32"
        href="vendors/images/favicon-32x32.png"
    >
    <link
        rel="icon"
        type="image/png"
        sizes="16x16"
        href="vendors/images/favicon-16x16.png"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet"
    >
    <link
        rel="stylesheet"
        type="text/css"
        href="vendors/styles/core.css"
    >
    <link
        rel="stylesheet"
        type="text/css"
        href="vendors/styles/icon-font.min.css"
    >
    <link
        rel="stylesheet"
        type="text/css"
        href="src/plugins/datatables/css/dataTables.bootstrap4.min.css"
    >
    <link
        rel="stylesheet"
        type="text/css"
        href="src/plugins/datatables/css/responsive.bootstrap4.min.css"
    >
    <link
        rel="stylesheet"
        type="text/css"
        href="vendors/styles/style.css"
    >
    <link
        rel="stylesheet"
        type="text/css"
        href="vendors/styles/admin_theme.css"
    >

    <script>
    (function () {
        try {
            const savedTheme =
                localStorage.getItem(
                    'hoa-theme'
                );

            const dark =
                savedTheme === 'dark' ||
                (
                    !savedTheme &&
                    window.matchMedia &&
                    window.matchMedia(
                        '(prefers-color-scheme: dark)'
                    ).matches
                );

            document.documentElement
                .classList.toggle(
                    'dark',
                    dark
                );
        } catch (e) {}
    })();
    </script>

    <style>
        :root {
            --brand: #077f46;
        }

        .page-title-wrap {
            display: flex;
            justify-content: center;
            text-align: center;
            margin-bottom: 18px;
        }

        .page-title-wrap .subtitle {
            font-size: 14px;
        }

        .activity-summary-card {
            height: 100%;
            padding: 18px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
        }

        .activity-summary-value {
            margin-bottom: 7px;
            color: var(--brand);
            font-size: 27px;
            font-weight: 800;
            line-height: 1;
        }

        .activity-summary-label {
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
        }

        .activity-filter-card,
        .activity-table-card {
            border-radius: 14px;
        }

        .activity-table-wrap {
            overflow-x: auto;
        }

        #activityLogsTable {
            width: 100% !important;
            min-width: 1150px;
        }

        #activityLogsTable td,
        #activityLogsTable th {
            vertical-align: top;
        }

        .activity-details {
            min-width: 320px;
            max-width: 520px;
            white-space: normal;
            word-break: break-word;
        }

        .activity-mini-note {
            color: #64748b;
            font-size: 12px;
            line-height: 1.35;
        }

        .activity-badge {
            display: inline-block;
            padding: .32rem .58rem;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .activity-badge-module {
            border: 1px solid #bbf7d0;
            background: #ecfdf5;
            color: #166534;
        }

        .activity-badge-position {
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #475569;
        }

        .activity-filter-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .admin-page-logout-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 98px;
            min-height: 40px;
            margin: 0 18px 0 6px;
            padding: 8px 14px;
            border: 1px solid #fecaca;
            border-radius: 11px;
            background: #fff;
            color: #b91c1c !important;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .06);
            font-size: 12px;
            line-height: 1;
            font-weight: 800;
            text-decoration: none !important;
            white-space: nowrap;
            transition:
                background .18s ease,
                color .18s ease,
                border-color .18s ease,
                transform .18s ease,
                box-shadow .18s ease;
        }

        .admin-page-logout-btn i {
            font-size: 17px;
            line-height: 1;
        }

        .admin-page-logout-btn:hover,
        .admin-page-logout-btn:focus {
            border-color: #ef4444;
            background: #fef2f2;
            color: #991b1b !important;
            box-shadow: 0 7px 18px rgba(220, 38, 38, .12);
            transform: translateY(-1px);
            outline: none;
        }

        html.dark .activity-summary-card,
        html.dark .activity-filter-card,
        html.dark .activity-table-card {
            border-color: var(--admin-border) !important;
            background: var(--admin-surface) !important;
            color: var(--admin-text) !important;
        }

        html.dark .activity-summary-value {
            color: #86efac;
        }

        html.dark .activity-summary-label,
        html.dark .activity-mini-note {
            color: var(--admin-muted) !important;
        }

        html.dark .activity-badge-module {
            border-color: rgba(74, 222, 128, .24);
            background: rgba(22, 101, 52, .20);
            color: #bbf7d0;
        }

        html.dark .activity-badge-position {
            border-color: var(--admin-border);
            background: var(--admin-surface-2);
            color: var(--admin-text);
        }

        html.dark #activityLogsTable {
            --bs-table-color: var(--admin-text);
            --bs-table-bg: var(--admin-surface);
            --bs-table-border-color: var(--admin-border);
            --bs-table-striped-color: var(--admin-text);
            --bs-table-striped-bg: rgba(148, 163, 184, .055);
            color: var(--admin-text) !important;
            background: var(--admin-surface) !important;
        }

        html.dark #activityLogsTable thead th {
            border-color: var(--admin-border) !important;
            background: var(--admin-surface-2) !important;
            color: #f8fafc !important;
        }

        html.dark #activityLogsTable tbody td {
            border-color: var(--admin-border) !important;
            color: var(--admin-text) !important;
        }

        html.dark #activityLogsTable tbody tr:nth-child(odd) > * {
            background: var(--admin-surface-2) !important;
        }

        html.dark #activityLogsTable tbody tr:nth-child(even) > * {
            background: var(--admin-surface) !important;
        }

        html.dark .form-control,
        html.dark .custom-select {
            border-color: var(--admin-border) !important;
            background: var(--admin-input) !important;
            color: var(--admin-text) !important;
        }

        html.dark .admin-page-logout-btn {
            border-color: rgba(248, 113, 113, .30);
            background: rgba(127, 29, 29, .16);
            color: #fca5a5 !important;
            box-shadow: none;
        }

        html.dark .admin-page-logout-btn:hover,
        html.dark .admin-page-logout-btn:focus {
            border-color: rgba(248, 113, 113, .55);
            background: rgba(127, 29, 29, .28);
            color: #fecaca !important;
        }

        @media (max-width: 767.98px) {
            .activity-filter-actions .btn {
                flex: 1 1 auto;
            }
        }

        @media (max-width: 575.98px) {
            .admin-page-logout-btn {
                width: 40px;
                min-width: 40px;
                height: 40px;
                min-height: 40px;
                margin: 0 10px 0 4px;
                padding: 0;
                border-radius: 10px;
            }

            .admin-page-logout-btn span {
                display: none;
            }

            .admin-page-logout-btn i {
                font-size: 18px;
            }

            .admin-theme-switch {
                padding: 0 2px;
            }
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="header-left">
            <div class="menu-icon dw dw-menu"></div>
            <div
                class="search-toggle-icon dw dw-search2"
                data-toggle="header_search"
            ></div>
        </div>

        <div class="header-right">
            <div class="admin-theme-switch">
                <button
                    type="button"
                    id="themeToggle"
                    class="admin-theme-toggle"
                    aria-label="Switch theme"
                    title="Switch theme"
                >
                    <span id="themeIcon">☾</span>
                </button>
            </div>

            <a
                href="logout.php"
                class="admin-page-logout-btn"
                title="Log out"
                aria-label="Log out"
            >
                <i
                    class="dw dw-logout"
                    aria-hidden="true"
                ></i>
                <span>Log Out</span>
            </a>
        </div>
    </div>

    <?php include 'sidebar.php'; ?>

    <div class="mobile-menu-overlay"></div>

    <div class="main-container">
        <div class="pd-ltr-20">
            <div class="page-title-wrap">
                <div>
                    <h2 class="h4 mb-1">
                        Activity Log
                    </h2>
                    <div class="text-muted fw-semibold subtitle">
                        View recorded activities made by HOA officers across all phases.
                    </div>
                </div>
            </div>

            <?php if (!$activityLogReady): ?>
                <div class="alert alert-warning">
                    The central <strong>activity_logs</strong> table is not available yet.
                </div>
            <?php else: ?>
                <div class="row mb-20">
                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="activity-summary-card">
                            <div class="activity-summary-value">
                                <?= number_format($totalMatching) ?>
                            </div>
                            <div class="activity-summary-label">
                                Matching Records
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="activity-summary-card">
                            <div class="activity-summary-value">
                                <?= number_format($todayCount) ?>
                            </div>
                            <div class="activity-summary-label">
                                Activities Today
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="activity-summary-card">
                            <div class="activity-summary-value">
                                <?= number_format($moduleCount) ?>
                            </div>
                            <div class="activity-summary-label">
                                Modules Recorded
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-3">
                        <div class="activity-summary-card">
                            <div class="activity-summary-value">
                                <?= number_format($officerCount) ?>
                            </div>
                            <div class="activity-summary-label">
                                Officers Recorded
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-box p-3 mb-20 activity-filter-card">
                    <div class="mb-3">
                        <h5 class="mb-1">
                            Filter Activity
                        </h5>
                        <div class="activity-mini-note">
                            Use the filters below to find a specific officer activity.
                        </div>
                    </div>

                    <form method="get">
                        <div class="row">
                            <div class="col-lg-4 col-md-6 mb-3">
                                <label class="font-weight-bold">
                                    Search
                                </label>
                                <input
                                    type="text"
                                    name="q"
                                    class="form-control"
                                    value="<?= esc($search) ?>"
                                    placeholder="Officer, action, details..."
                                >
                            </div>

                            <div class="col-lg-3 col-md-6 mb-3">
                                <label class="font-weight-bold">
                                    Module
                                </label>
                                <select
                                    name="module"
                                    class="form-control"
                                >
                                    <option value="">
                                        All Modules
                                    </option>

                                    <?php foreach ($moduleOptions as $module): ?>
                                        <option
                                            value="<?= esc($module) ?>"
                                            <?= $moduleFilter === $module ? 'selected' : '' ?>
                                        >
                                            <?= esc(readableModule($module)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-lg-2 col-md-6 mb-3">
                                <label class="font-weight-bold">
                                    From
                                </label>
                                <input
                                    type="date"
                                    name="date_from"
                                    class="form-control"
                                    value="<?= esc($dateFrom) ?>"
                                >
                            </div>

                            <div class="col-lg-2 col-md-6 mb-3">
                                <label class="font-weight-bold">
                                    To
                                </label>
                                <input
                                    type="date"
                                    name="date_to"
                                    class="form-control"
                                    value="<?= esc($dateTo) ?>"
                                >
                            </div>
                        </div>

                        <div class="activity-filter-actions">
                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                <i class="dw dw-search2"></i>
                                Apply Filters
                            </button>

                            <a
                                href="activity_log.php"
                                class="btn btn-outline-secondary"
                            >
                                Clear
                            </a>
                        </div>
                    </form>
                </div>

                <div class="card-box p-3 mb-30 activity-table-card">
                    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                        <div>
                            <h5 class="mb-1">
                                Recorded Activities
                            </h5>
                            <div class="activity-mini-note">
                                Each entry explains what the HOA officer did and what happened afterward. Technical and security information is hidden.
                            </div>
                        </div>

                        <span class="activity-badge activity-badge-module mt-2 mt-md-0">
                            <?= number_format($totalMatching) ?>
                            result<?= $totalMatching === 1 ? '' : 's' ?>
                        </span>
                    </div>

                    <div class="activity-table-wrap">
                        <table
                            id="activityLogsTable"
                            class="table table-striped table-bordered nowrap"
                        >
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>Officer / Admin</th>
                                    <th>Position</th>
                                    <th>Phase</th>
                                    <th>Module</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (!$logs): ?>
                                    <tr>
                                        <td
                                            colspan="7"
                                            class="text-center text-muted py-4"
                                        >
                                            No activity logs match the selected filters.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log): ?>
                                        <?php
                                        $userName =
                                            trim(
                                                (string)(
                                                    $log[
                                                        'full_name'
                                                    ] ??
                                                    ''
                                                )
                                            );

                                        if ($userName === '') {
                                            $userName =
                                                !empty(
                                                    $log[
                                                        'admin_id'
                                                    ]
                                                )
                                                    ? 'Admin #' .
                                                        (int)$log[
                                                            'admin_id'
                                                        ]
                                                    : 'System';
                                        }

                                        $position =
                                            trim(
                                                (string)(
                                                    $log[
                                                        'position'
                                                    ] ??
                                                    ''
                                                )
                                            );

                                        if ($position === '') {
                                            $position =
                                                (
                                                    (
                                                        $log[
                                                            'role'
                                                        ] ??
                                                        ''
                                                    ) ===
                                                    'superadmin'
                                                )
                                                    ? 'Superadmin'
                                                    : '—';
                                        }

                                        $createdAt =
                                            !empty(
                                                $log[
                                                    'created_at'
                                                ]
                                            )
                                                ? date(
                                                    'M d, Y h:i A',
                                                    strtotime(
                                                        (string)$log[
                                                            'created_at'
                                                        ]
                                                    )
                                                )
                                                : '—';
                                        ?>
                                        <tr>
                                            <td>
                                                <?= esc($createdAt) ?>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= esc($userName) ?>
                                                </strong>

                                                <?php if (!empty($log['email'])): ?>
                                                    <div class="activity-mini-note">
                                                        <?= esc($log['email']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <span class="activity-badge activity-badge-position">
                                                    <?= esc($position) ?>
                                                </span>
                                            </td>

                                            <td>
                                                <?= esc($log['phase'] ?: '—') ?>
                                            </td>

                                            <td>
                                                <span class="activity-badge activity-badge-module">
                                                    <?= esc(
                                                        readableModule(
                                                            $log[
                                                                'module_key'
                                                            ] ??
                                                            ''
                                                        )
                                                    ) ?>
                                                </span>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= esc($log['action'] ?? '') ?>
                                                </strong>
                                            </td>

                                            <td class="activity-details">
                                                <?= nl2br(
                                                    esc(
                                                        readableDetails(
                                                            $log[
                                                                'details'
                                                            ] ??
                                                            '',
                                                            $log[
                                                                'action'
                                                            ] ??
                                                            ''
                                                        )
                                                    )
                                                ) ?>
                                            </td>

                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="footer-wrap pd-20 mb-20 card-box">
                © Copyright South Meridian Homes All Rights Reserved
            </div>
        </div>
    </div>

    <script src="vendors/scripts/core.js"></script>
    <script src="vendors/scripts/script.min.js"></script>
    <script src="vendors/scripts/process.js"></script>
    <script src="vendors/scripts/layout-settings.js"></script>

    <script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
    <script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
    <script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
    <script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

    <script src="vendors/scripts/admin_theme.js"></script>

    <script>
    $(function () {
        const table =
            $('#activityLogsTable');

        if (
            table.length &&
            $.fn.DataTable &&
            !$.fn.DataTable.isDataTable(
                '#activityLogsTable'
            )
        ) {
            table.DataTable({
                responsive: false,
                pageLength: 25,
                order: [],
                lengthMenu: [
                    [10, 25, 50, 100],
                    [10, 25, 50, 100]
                ],
                columnDefs: [
                    {
                        orderable: false,
                        targets: -1
                    }
                ]
            });
        }
    });
    </script>
</body>
</html>
