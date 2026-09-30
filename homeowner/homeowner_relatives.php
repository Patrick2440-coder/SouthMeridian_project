<?php
session_start();

require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner') {
    if (($_SESSION['role'] ?? '') === 'tenant') {
        $_SESSION['access_denied'] = 'Only the homeowner account can manage relatives and family information.';
        header('Location: homeowner_dashboard.php');
        exit;
    }

    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['homeowner_id'])) {
    header('Location: ../index.php');
    exit;
}

$hid = (int) $_SESSION['homeowner_id'];

if (!function_exists('esc')) {
    function esc($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/* -------------------------------------------------------------
   Current homeowner
   ------------------------------------------------------------- */
$stmt = $conn->prepare("\n    SELECT\n        id, public_id, first_name, middle_name, last_name,\n        contact_number, email, phase, house_lot_number,\n        status, profile_picture_path\n    FROM homeowners\n    WHERE id = ?\n    LIMIT 1\n");
$stmt->bind_param('i', $hid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || ($user['status'] ?? '') !== 'approved') {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$phase = (string) ($user['phase'] ?? '');
$fullName = trim(
    ($user['first_name'] ?? '') . ' ' .
    ($user['middle_name'] ?? '') . ' ' .
    ($user['last_name'] ?? '')
);
$fullName = preg_replace('/\s+/', ' ', $fullName);

$initials = strtoupper(
    substr((string) ($user['first_name'] ?? 'H'), 0, 1) .
    substr((string) ($user['last_name'] ?? 'O'), 0, 1)
);

$profilePicturePath = trim((string) ($user['profile_picture_path'] ?? ''));
$profilePictureUrl = $profilePicturePath !== ''
    ? '../' . ltrim($profilePicturePath, '/')
    : '';

$isTenant = false;
$tenant = null;
$activePage = 'homeowner_relatives.php';
$pageTitle = 'My Relatives • South Meridian Homes';

/* -------------------------------------------------------------
   CSRF
   ------------------------------------------------------------- */
if (empty($_SESSION['csrf_homeowner_relatives'])) {
    $_SESSION['csrf_homeowner_relatives'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_homeowner_relatives'];

/* -------------------------------------------------------------
   Check database migration
   ------------------------------------------------------------- */
$requiredColumns = [
    'relationship_detail',
    'birth_date',
    'contact_number',
    'email',
    'address',
    'notes',
    'created_at',
    'updated_at',
];

$columnResult = $conn->query('SHOW COLUMNS FROM household_members');
$existingColumns = [];
while ($column = $columnResult->fetch_assoc()) {
    $existingColumns[] = (string) $column['Field'];
}

$missingColumns = array_values(array_diff($requiredColumns, $existingColumns));
$schemaReady = empty($missingColumns);

/* -------------------------------------------------------------
   Flash helper
   ------------------------------------------------------------- */
function relatives_redirect(string $type, string $message): never
{
    $_SESSION['relatives_flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    header('Location: homeowner_relatives.php');
    exit;
}

/* -------------------------------------------------------------
   CRUD actions
   ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if ($submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
        relatives_redirect('error', 'Your session token expired. Please refresh the page and try again.');
    }

    if (!$schemaReady) {
        relatives_redirect('error', 'The relatives database update has not been installed yet.');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $relativeId = (int) ($_POST['relative_id'] ?? 0);

        if ($relativeId <= 0) {
            relatives_redirect('error', 'Invalid relative record.');
        }

        $deleteStmt = $conn->prepare("\n            DELETE FROM household_members\n            WHERE id = ?\n              AND homeowner_id = ?\n              AND relation = 'Relative'\n            LIMIT 1\n        ");
        $deleteStmt->bind_param('ii', $relativeId, $hid);
        $deleteStmt->execute();
        $affected = $deleteStmt->affected_rows;
        $deleteStmt->close();

        if ($affected < 1) {
            relatives_redirect('error', 'Relative record was not found or cannot be removed.');
        }

        relatives_redirect('success', 'Relative information removed successfully.');
    }

    if ($action === 'save') {
        $relativeId = (int) ($_POST['relative_id'] ?? 0);
        $firstName = trim((string) ($_POST['first_name'] ?? ''));
        $middleName = trim((string) ($_POST['middle_name'] ?? ''));
        $lastName = trim((string) ($_POST['last_name'] ?? ''));
        $relationshipDetail = trim((string) ($_POST['relationship_detail'] ?? ''));
        $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
        $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($firstName === '' || $lastName === '' || $relationshipDetail === '') {
            relatives_redirect('error', 'First name, last name, and relationship are required.');
        }

        if (mb_strlen($firstName) > 100 || mb_strlen($middleName) > 100 || mb_strlen($lastName) > 100) {
            relatives_redirect('error', 'Name fields are too long.');
        }

        if (mb_strlen($relationshipDetail) > 50) {
            relatives_redirect('error', 'Relationship must be 50 characters or fewer.');
        }

        if (mb_strlen($contactNumber) > 20) {
            relatives_redirect('error', 'Contact number must be 20 characters or fewer.');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            relatives_redirect('error', 'Please enter a valid email address.');
        }

        if (mb_strlen($email) > 255 || mb_strlen($address) > 255) {
            relatives_redirect('error', 'Email or address is too long.');
        }

        if ($birthDate !== '') {
            $birthTs = strtotime($birthDate);
            if (!$birthTs || date('Y-m-d', $birthTs) !== $birthDate) {
                relatives_redirect('error', 'Please enter a valid birth date.');
            }
            if ($birthDate > date('Y-m-d')) {
                relatives_redirect('error', 'Birth date cannot be in the future.');
            }
        }

        $birthDateDb = $birthDate !== '' ? $birthDate : null;
        $middleNameDb = $middleName !== '' ? $middleName : null;
        $contactDb = $contactNumber !== '' ? $contactNumber : null;
        $emailDb = $email !== '' ? $email : null;
        $addressDb = $address !== '' ? $address : null;
        $notesDb = $notes !== '' ? $notes : null;

        if ($relativeId > 0) {
            $updateStmt = $conn->prepare("\n                UPDATE household_members\n                SET\n                    first_name = ?,\n                    middle_name = ?,\n                    last_name = ?,\n                    relationship_detail = ?,\n                    birth_date = ?,\n                    contact_number = ?,\n                    email = ?,\n                    address = ?,\n                    notes = ?\n                WHERE id = ?\n                  AND homeowner_id = ?\n                  AND relation = 'Relative'\n                LIMIT 1\n            ");
            $updateStmt->bind_param(
                'sssssssssii',
                $firstName,
                $middleNameDb,
                $lastName,
                $relationshipDetail,
                $birthDateDb,
                $contactDb,
                $emailDb,
                $addressDb,
                $notesDb,
                $relativeId,
                $hid
            );
            $updateStmt->execute();
            $matched = $updateStmt->affected_rows;
            $updateStmt->close();

            if ($matched < 0) {
                relatives_redirect('error', 'Unable to update relative information.');
            }

            relatives_redirect('success', 'Relative information updated successfully.');
        }

        $relation = 'Relative';
        $insertStmt = $conn->prepare("\n            INSERT INTO household_members\n            (\n                homeowner_id, first_name, middle_name, last_name, relation,\n                relationship_detail, birth_date, contact_number, email, address, notes\n            )\n            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n        ");
        $insertStmt->bind_param(
            'issssssssss',
            $hid,
            $firstName,
            $middleNameDb,
            $lastName,
            $relation,
            $relationshipDetail,
            $birthDateDb,
            $contactDb,
            $emailDb,
            $addressDb,
            $notesDb
        );
        $insertStmt->execute();
        $insertStmt->close();

        relatives_redirect('success', 'Relative information added successfully.');
    }

    relatives_redirect('error', 'Unknown action.');
}

/* -------------------------------------------------------------
   Relative records
   ------------------------------------------------------------- */
$relatives = [];

if ($schemaReady) {
    $relStmt = $conn->prepare("\n        SELECT\n            id, first_name, middle_name, last_name,\n            relationship_detail, birth_date, contact_number,\n            email, address, notes, created_at, updated_at\n        FROM household_members\n        WHERE homeowner_id = ?\n          AND relation = 'Relative'\n        ORDER BY first_name ASC, last_name ASC, id ASC\n    ");
    $relStmt->bind_param('i', $hid);
    $relStmt->execute();
    $relatives = $relStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $relStmt->close();
}

$flash = $_SESSION['relatives_flash'] ?? null;
unset($_SESSION['relatives_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($pageTitle) ?></title>

<script>
(function () {
    const savedTheme = localStorage.getItem('hoa-theme');
    const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const useDark = savedTheme === 'dark' || (!savedTheme && systemDark);
    document.documentElement.classList.toggle('dark', useDark);
})();
</script>

<style type="text/tailwindcss">
    @custom-variant dark (&:where(.dark, .dark *));
</style>

<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">
</head>

<body class="bg-slate-50 text-slate-900 antialiased transition-colors duration-200 dark:bg-slate-950 dark:text-slate-100">

<div id="sidebarOverlay" class="fixed inset-0 z-50 hidden bg-slate-950/50 backdrop-blur-[1px] lg:hidden"></div>

<?php include 'homeowner_sidebar.php'; ?>

<div class="min-h-screen lg:ml-[280px]">

    <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
        <div class="mx-auto flex min-h-[72px] max-w-7xl items-center gap-3 px-4 sm:px-6">
            <button
                type="button"
                id="sidebarToggle"
                class="flex h-12 w-12 items-center justify-center rounded-xl border border-slate-200 bg-white text-2xl text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:focus:ring-emerald-950 lg:hidden"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="min-w-0">
                <h1 class="truncate text-lg font-extrabold text-emerald-800 dark:text-emerald-300 sm:text-xl">
                    My Relatives
                </h1>
                <p class="hidden text-xs font-medium text-slate-500 dark:text-slate-400 sm:block">
                    Personal family information connected to your homeowner record
                </p>
            </div>

            <div class="ml-auto flex items-center gap-2">
                <button
                    type="button"
                    id="themeToggle"
                    class="flex h-12 w-12 items-center justify-center rounded-xl border border-slate-200 bg-white text-xl text-slate-700 shadow-sm transition hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-emerald-300 dark:focus:ring-emerald-950"
                    aria-label="Toggle theme"
                >
                    <i id="themeIcon" class="bi bi-moon-stars-fill"></i>
                </button>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:py-8">

        <?php if ($flash): ?>
            <?php
                $isSuccess = ($flash['type'] ?? '') === 'success';
                $flashClass = $isSuccess
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300'
                    : 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-300';
            ?>
            <div class="mb-5 flex items-start gap-3 rounded-2xl border p-4 <?= $flashClass ?>">
                <i class="bi <?= $isSuccess ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> mt-0.5"></i>
                <div class="text-sm font-semibold"><?= esc($flash['message'] ?? '') ?></div>
            </div>
        <?php endif; ?>

        <?php if (!$schemaReady): ?>
            <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                <div class="flex items-start gap-3">
                    <i class="bi bi-database-exclamation text-xl"></i>
                    <div>
                        <h2 class="font-extrabold">Database update required</h2>
                        <p class="mt-1 text-sm leading-6">
                            The relatives page is installed, but the extra personal-information columns have not been added to
                            <code class="font-bold">household_members</code> yet.
                        </p>
                        <p class="mt-2 text-xs opacity-80">
                            Missing: <?= esc(implode(', ', $missingColumns)) ?>
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <section class="mb-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                <div>
                    <div class="flex items-center gap-3">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 text-xl text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-extrabold text-slate-900 dark:text-slate-100">Relatives / Family Information</h2>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                These records are for personal information only and do not create system accounts.
                            </p>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    id="addRelativeBtn"
                    <?= !$schemaReady ? 'disabled' : '' ?>
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-emerald-600 dark:hover:bg-emerald-500 dark:focus:ring-emerald-950"
                >
                    <i class="bi bi-person-plus-fill"></i>
                    Add Relative
                </button>
            </div>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="border-b border-slate-200 p-5 dark:border-slate-800 sm:p-6">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="font-extrabold text-slate-900 dark:text-slate-100">Saved Relatives</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            <?= count($relatives) ?> relative<?= count($relatives) === 1 ? '' : 's' ?> recorded
                        </p>
                    </div>
                </div>
            </div>

            <?php if (!$schemaReady): ?>
                <div class="p-8 text-center text-sm text-slate-500 dark:text-slate-400">
                    Install the database update first to manage relatives.
                </div>

            <?php elseif (!$relatives): ?>
                <div class="px-6 py-14 text-center">
                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 text-2xl text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                        <i class="bi bi-people"></i>
                    </div>
                    <h3 class="mt-4 font-extrabold text-slate-900 dark:text-slate-100">No relatives added yet</h3>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500 dark:text-slate-400">
                        Add a relative if you want their basic family information attached to your homeowner record.
                    </p>
                </div>

            <?php else: ?>
                <div class="grid gap-4 p-4 sm:p-6 lg:grid-cols-2">
                    <?php foreach ($relatives as $relative): ?>
                        <?php
                            $relativeName = trim(
                                ($relative['first_name'] ?? '') . ' ' .
                                ($relative['middle_name'] ?? '') . ' ' .
                                ($relative['last_name'] ?? '')
                            );
                            $relativeName = preg_replace('/\s+/', ' ', $relativeName);
                            $relativeJson = esc(json_encode([
                                'id' => (int) $relative['id'],
                                'first_name' => (string) ($relative['first_name'] ?? ''),
                                'middle_name' => (string) ($relative['middle_name'] ?? ''),
                                'last_name' => (string) ($relative['last_name'] ?? ''),
                                'relationship_detail' => (string) ($relative['relationship_detail'] ?? ''),
                                'birth_date' => (string) ($relative['birth_date'] ?? ''),
                                'contact_number' => (string) ($relative['contact_number'] ?? ''),
                                'email' => (string) ($relative['email'] ?? ''),
                                'address' => (string) ($relative['address'] ?? ''),
                                'notes' => (string) ($relative['notes'] ?? ''),
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        ?>

                        <article class="rounded-2xl border border-slate-200 p-5 transition hover:border-emerald-200 hover:shadow-sm dark:border-slate-800 dark:hover:border-emerald-900">
                            <div class="flex items-start gap-4">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-100 text-lg font-extrabold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                    <?= esc(strtoupper(substr((string) ($relative['first_name'] ?? 'R'), 0, 1) . substr((string) ($relative['last_name'] ?? 'L'), 0, 1))) ?>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            <h3 class="truncate font-extrabold text-slate-900 dark:text-slate-100">
                                                <?= esc($relativeName) ?>
                                            </h3>
                                            <span class="mt-1 inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-300 dark:ring-emerald-900">
                                                <?= esc($relative['relationship_detail'] ?? 'Relative') ?>
                                            </span>
                                        </div>

                                        <div class="flex shrink-0 gap-2">
                                            <button
                                                type="button"
                                                class="editRelativeBtn flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600 transition hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-emerald-950/50 dark:hover:text-emerald-300"
                                                data-relative="<?= $relativeJson ?>"
                                                title="Edit relative"
                                                aria-label="Edit <?= esc($relativeName) ?>"
                                            >
                                                <i class="bi bi-pencil-square"></i>
                                            </button>

                                            <button
                                                type="button"
                                                class="deleteRelativeBtn flex h-9 w-9 items-center justify-center rounded-lg border border-red-200 text-red-600 transition hover:bg-red-50 dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/50"
                                                data-id="<?= (int) $relative['id'] ?>"
                                                data-name="<?= esc($relativeName) ?>"
                                                title="Remove relative"
                                                aria-label="Remove <?= esc($relativeName) ?>"
                                            >
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                        <div>
                                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Birth Date</dt>
                                            <dd class="mt-1 text-slate-700 dark:text-slate-300">
                                                <?= !empty($relative['birth_date']) ? esc(date('F j, Y', strtotime($relative['birth_date']))) : 'Not provided' ?>
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Contact</dt>
                                            <dd class="mt-1 break-words text-slate-700 dark:text-slate-300">
                                                <?= esc($relative['contact_number'] ?: 'Not provided') ?>
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Email</dt>
                                            <dd class="mt-1 break-all text-slate-700 dark:text-slate-300">
                                                <?= esc($relative['email'] ?: 'Not provided') ?>
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">Address</dt>
                                            <dd class="mt-1 break-words text-slate-700 dark:text-slate-300">
                                                <?= esc($relative['address'] ?: 'Not provided') ?>
                                            </dd>
                                        </div>
                                    </dl>

                                    <?php if (!empty($relative['notes'])): ?>
                                        <div class="mt-4 rounded-xl bg-slate-50 p-3 text-sm leading-6 text-slate-600 dark:bg-slate-800/70 dark:text-slate-300">
                                            <span class="font-bold">Notes:</span>
                                            <?= nl2br(esc($relative['notes'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </main>
</div>

<!-- Add / Edit Relative Modal -->
<div id="relativeModal" class="fixed inset-0 z-[90] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
    <div class="max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-3xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800 sm:px-6">
            <div>
                <h2 id="relativeModalTitle" class="text-lg font-extrabold text-slate-900 dark:text-slate-100">Add Relative</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Add personal family information to your homeowner record.</p>
            </div>
            <button type="button" id="closeRelativeModal" class="flex h-10 w-10 items-center justify-center rounded-xl text-slate-500 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" aria-label="Close">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <form method="post" id="relativeForm" class="p-5 sm:p-6">
            <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="relative_id" id="relativeId" value="0">

            <div class="grid gap-5 md:grid-cols-3">
                <label class="block">
                    <span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">First Name *</span>
                    <input required maxlength="100" type="text" name="first_name" id="firstName" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-emerald-950">
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Middle Name</span>
                    <input maxlength="100" type="text" name="middle_name" id="middleName" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-emerald-950">
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Last Name *</span>
                    <input required maxlength="100" type="text" name="last_name" id="lastName" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-emerald-950">
                </label>
            </div>

            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <label class="block">
                    <span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Relationship *</span>
                    <input required maxlength="50" list="relationshipOptions" type="text" name="relationship_detail" id="relationshipDetail" placeholder="e.g. Mother" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-emerald-950">
                    <datalist id="relationshipOptions">
                        <option value="Mother"><option value="Father"><option value="Spouse"><option value="Partner">
                        <option value="Son"><option value="Daughter"><option value="Brother"><option value="Sister">
                        <option value="Grandmother"><option value="Grandfather"><option value="Grandson"><option value="Granddaughter">
                        <option value="Aunt"><option value="Uncle"><option value="Cousin"><option value="Niece"><option value="Nephew"><option value="Guardian"><option value="Other">
                    </datalist>
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Birth Date</span>
                    <input max="<?= date('Y-m-d') ?>" type="date" name="birth_date" id="birthDate" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-emerald-950">
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Contact Number</span>
                    <input maxlength="20" type="text" name="contact_number" id="contactNumber" placeholder="09XXXXXXXXX" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-emerald-950">
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Email</span>
                    <input maxlength="255" type="email" name="email" id="email" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-emerald-950">
                </label>
            </div>

            <label class="mt-5 block">
                <span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Address</span>
                <input maxlength="255" type="text" name="address" id="address" class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-emerald-950">
            </label>

            <label class="mt-5 block">
                <span class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">Notes</span>
                <textarea rows="4" name="notes" id="notes" class="w-full resize-y rounded-xl border border-slate-300 bg-white px-3.5 py-3 text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-emerald-950"></textarea>
            </label>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="button" id="cancelRelativeModal" class="min-h-11 rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                    Cancel
                </button>
                <button type="submit" class="min-h-11 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-100 dark:bg-emerald-600 dark:hover:bg-emerald-500 dark:focus:ring-emerald-950">
                    <i class="bi bi-check2-circle mr-1"></i>
                    Save Relative
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm">
    <div class="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-red-100 text-2xl text-red-600 dark:bg-red-950/60 dark:text-red-400">
            <i class="bi bi-trash3-fill"></i>
        </div>
        <h2 class="mt-4 text-lg font-extrabold text-slate-900 dark:text-slate-100">Remove Relative?</h2>
        <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">
            This will remove <strong id="deleteRelativeName" class="text-slate-700 dark:text-slate-200"></strong> from your relatives information.
        </p>

        <form method="post" class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="relative_id" id="deleteRelativeId" value="0">

            <button type="button" id="cancelDelete" class="min-h-11 rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                Cancel
            </button>
            <button type="submit" class="min-h-11 rounded-xl bg-red-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-100 dark:focus:ring-red-950">
                Remove
            </button>
        </form>
    </div>
</div>

<script>
(function () {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const openButton = document.getElementById('sidebarToggle');
    const closeButton = document.getElementById('sidebarClose');

    function openSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
    }

    function closeSidebar() {
        if (!sidebar || !overlay) return;
        if (window.innerWidth < 1024) {
            sidebar.classList.add('-translate-x-full');
        }
        overlay.classList.add('hidden');
    }

    openButton?.addEventListener('click', openSidebar);
    closeButton?.addEventListener('click', closeSidebar);
    overlay?.addEventListener('click', closeSidebar);
})();

(function () {
    const toggle = document.getElementById('themeToggle');
    const icon = document.getElementById('themeIcon');

    function updateIcon() {
        if (!icon) return;
        const dark = document.documentElement.classList.contains('dark');
        icon.className = dark ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    }

    toggle?.addEventListener('click', function () {
        const dark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('hoa-theme', dark ? 'dark' : 'light');
        updateIcon();
    });

    updateIcon();
})();

(function () {
    const tenantToggle = document.getElementById('sbTenantToggle');
    const tenantMenu = document.getElementById('sbTenantMenu');
    const tenantCaret = document.getElementById('sbTenantCaret');

    tenantToggle?.addEventListener('click', function () {
        if (!tenantMenu) return;
        tenantMenu.classList.toggle('hidden');
        const open = !tenantMenu.classList.contains('hidden');
        tenantToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        tenantCaret?.classList.toggle('rotate-180', open);
    });
})();

(function () {
    const parkingToggle = document.getElementById('sbParkingToggle');
    const parkingMenu = document.getElementById('sbParkingMenu');
    const parkingCaret = document.getElementById('sbParkingCaret');

    parkingToggle?.addEventListener('click', function () {
        if (!parkingMenu) return;
        parkingMenu.classList.toggle('hidden');
        const open = !parkingMenu.classList.contains('hidden');
        parkingToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        parkingCaret?.classList.toggle('rotate-180', open);
    });
})();

(function () {
    const modal = document.getElementById('relativeModal');
    const form = document.getElementById('relativeForm');
    const title = document.getElementById('relativeModalTitle');
    const addButton = document.getElementById('addRelativeBtn');
    const closeButton = document.getElementById('closeRelativeModal');
    const cancelButton = document.getElementById('cancelRelativeModal');

    const fields = {
        id: document.getElementById('relativeId'),
        first_name: document.getElementById('firstName'),
        middle_name: document.getElementById('middleName'),
        last_name: document.getElementById('lastName'),
        relationship_detail: document.getElementById('relationshipDetail'),
        birth_date: document.getElementById('birthDate'),
        contact_number: document.getElementById('contactNumber'),
        email: document.getElementById('email'),
        address: document.getElementById('address'),
        notes: document.getElementById('notes'),
    };

    function showModal() {
        modal?.classList.remove('hidden');
        modal?.classList.add('flex');
        document.body.classList.add('overflow-hidden');
    }

    function hideModal() {
        modal?.classList.add('hidden');
        modal?.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
    }

    function resetForm() {
        form?.reset();
        if (fields.id) fields.id.value = '0';
        if (title) title.textContent = 'Add Relative';
    }

    addButton?.addEventListener('click', function () {
        resetForm();
        showModal();
    });

    document.querySelectorAll('.editRelativeBtn').forEach(function (button) {
        button.addEventListener('click', function () {
            let data = {};
            try {
                data = JSON.parse(button.dataset.relative || '{}');
            } catch (error) {
                return;
            }

            if (fields.id) fields.id.value = data.id || 0;
            if (fields.first_name) fields.first_name.value = data.first_name || '';
            if (fields.middle_name) fields.middle_name.value = data.middle_name || '';
            if (fields.last_name) fields.last_name.value = data.last_name || '';
            if (fields.relationship_detail) fields.relationship_detail.value = data.relationship_detail || '';
            if (fields.birth_date) fields.birth_date.value = data.birth_date || '';
            if (fields.contact_number) fields.contact_number.value = data.contact_number || '';
            if (fields.email) fields.email.value = data.email || '';
            if (fields.address) fields.address.value = data.address || '';
            if (fields.notes) fields.notes.value = data.notes || '';
            if (title) title.textContent = 'Edit Relative';

            showModal();
        });
    });

    closeButton?.addEventListener('click', hideModal);
    cancelButton?.addEventListener('click', hideModal);
    modal?.addEventListener('click', function (event) {
        if (event.target === modal) hideModal();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') hideModal();
    });
})();

(function () {
    const modal = document.getElementById('deleteModal');
    const idInput = document.getElementById('deleteRelativeId');
    const nameLabel = document.getElementById('deleteRelativeName');
    const cancelButton = document.getElementById('cancelDelete');

    function hideModal() {
        modal?.classList.add('hidden');
        modal?.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
    }

    document.querySelectorAll('.deleteRelativeBtn').forEach(function (button) {
        button.addEventListener('click', function () {
            if (idInput) idInput.value = button.dataset.id || '0';
            if (nameLabel) nameLabel.textContent = button.dataset.name || 'this relative';
            modal?.classList.remove('hidden');
            modal?.classList.add('flex');
            document.body.classList.add('overflow-hidden');
        });
    });

    cancelButton?.addEventListener('click', hideModal);
    modal?.addEventListener('click', function (event) {
        if (event.target === modal) hideModal();
    });
})();
</script>

</body>
</html>
