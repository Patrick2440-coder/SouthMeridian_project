<?php
session_start();

require_once '../config/database.php';

if (($_SESSION['role'] ?? '') !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
    if (($_SESSION['role'] ?? '') === 'tenant') {
        $_SESSION['access_denied'] = 'Only the homeowner account can manage homeowner documents.';
        header('Location: homeowner_dashboard.php');
        exit;
    }

    header('Location: ../index.php');
    exit;
}

$homeownerId = (int) $_SESSION['homeowner_id'];

if (!function_exists('esc')) {
    function esc($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

function documents_redirect(string $type, string $message): never
{
    $_SESSION['homeowner_documents_flash'] = [
        'type' => $type,
        'message' => $message,
    ];

    header('Location: homeowner_documents.php');
    exit;
}

function is_real_document_path(string $path): bool
{
    $path = trim($path);

    return $path !== '' && !str_starts_with($path, 'imports/');
}

function document_url(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));

    if (!is_real_document_path($path)) {
        return '';
    }

    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    if (str_starts_with($path, 'uploads/')) {
        return '../' . $path;
    }

    return $path;
}

function is_image_document(string $path): bool
{
    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION));

    return in_array($ext, ['jpg', 'jpeg', 'png'], true);
}

function is_pdf_document(string $path): bool
{
    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION));

    return $ext === 'pdf';
}

function save_homeowner_document(array $file, string $kind, int $homeownerId): string
{
    if (!isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The selected file could not be uploaded.');
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException('Invalid upload request.');
    }

    $size = (int) ($file['size'] ?? 0);
    $maxBytes = 5 * 1024 * 1024;

    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('Each document must be 5 MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file((string) $file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, and PDF files are allowed.');
    }

    $safeKind = $kind === 'valid_id' ? 'valid_id' : 'proof_of_billing';
    $extension = $allowed[$mime];

    $uploadDirFs = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'homeowner_documents' . DIRECTORY_SEPARATOR;
    $uploadDirDb = 'uploads/homeowner_documents/';

    if (!is_dir($uploadDirFs) && !mkdir($uploadDirFs, 0755, true) && !is_dir($uploadDirFs)) {
        throw new RuntimeException('Unable to create the document upload folder.');
    }

    $fileName =
        'homeowner_' .
        $homeownerId .
        '_' .
        $safeKind .
        '_' .
        date('Ymd_His') .
        '_' .
        bin2hex(random_bytes(5)) .
        '.' .
        $extension;

    $filePathFs = $uploadDirFs . $fileName;

    if (!move_uploaded_file((string) $file['tmp_name'], $filePathFs)) {
        throw new RuntimeException('Failed to save the uploaded document.');
    }

    return $uploadDirDb . $fileName;
}

function delete_replaced_document(string $path): void
{
    $path = trim(str_replace('\\', '/', $path));

    if ($path === '' || !str_starts_with($path, 'uploads/homeowner_documents/')) {
        return;
    }

    $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
    $fullPath = $root . str_replace('/', DIRECTORY_SEPARATOR, $path);

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

$stmt = $conn->prepare("
    SELECT
        id,
        public_id,
        first_name,
        middle_name,
        last_name,
        email,
        phase,
        house_lot_number,
        status,
        profile_picture_path,
        valid_id_path,
        proof_of_billing_path
    FROM homeowners
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param('i', $homeownerId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || ($user['status'] ?? '') !== 'approved') {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$fullName = trim(
    (string) ($user['first_name'] ?? '') . ' ' .
    (string) ($user['middle_name'] ?? '') . ' ' .
    (string) ($user['last_name'] ?? '')
);
$fullName = preg_replace('/\s+/', ' ', $fullName);

$phase = (string) ($user['phase'] ?? '');
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
$activePage = 'homeowner_documents.php';
$pageTitle = 'My Documents • South Meridian Homes';

if (empty($_SESSION['csrf_homeowner_documents'])) {
    $_SESSION['csrf_homeowner_documents'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_homeowner_documents'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');

    if ($submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
        documents_redirect('error', 'Your session token expired. Please refresh the page and try again.');
    }

    $oldValidId = trim((string) ($user['valid_id_path'] ?? ''));
    $oldProof = trim((string) ($user['proof_of_billing_path'] ?? ''));

    $newValidId = '';
    $newProof = '';

    $hasValidUpload =
        isset($_FILES['valid_id']) &&
        (int) ($_FILES['valid_id']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    $hasProofUpload =
        isset($_FILES['proof_of_billing']) &&
        (int) ($_FILES['proof_of_billing']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (!$hasValidUpload && !$hasProofUpload) {
        documents_redirect('error', 'Choose at least one document to upload.');
    }

    try {
        if ($hasValidUpload) {
            $newValidId = save_homeowner_document(
                $_FILES['valid_id'],
                'valid_id',
                $homeownerId
            );
        }

        if ($hasProofUpload) {
            $newProof = save_homeowner_document(
                $_FILES['proof_of_billing'],
                'proof_of_billing',
                $homeownerId
            );
        }

        $finalValidId = $newValidId !== '' ? $newValidId : $oldValidId;
        $finalProof = $newProof !== '' ? $newProof : $oldProof;

        $update = $conn->prepare("
            UPDATE homeowners
            SET
                valid_id_path = ?,
                proof_of_billing_path = ?
            WHERE id = ?
              AND status = 'approved'
            LIMIT 1
        ");
        $update->bind_param(
            'ssi',
            $finalValidId,
            $finalProof,
            $homeownerId
        );
        $update->execute();
        $affected = $update->affected_rows;
        $update->close();

        if ($affected < 0) {
            throw new RuntimeException('The document record could not be updated.');
        }

        if ($newValidId !== '' && $oldValidId !== $newValidId) {
            delete_replaced_document($oldValidId);
        }

        if ($newProof !== '' && $oldProof !== $newProof) {
            delete_replaced_document($oldProof);
        }

        documents_redirect(
            'success',
            'Documents updated successfully. Your HOA administrator can now view the uploaded files.'
        );
    } catch (Throwable $e) {
        if ($newValidId !== '') {
            delete_replaced_document($newValidId);
        }

        if ($newProof !== '') {
            delete_replaced_document($newProof);
        }

        documents_redirect('error', $e->getMessage());
    }
}

$flash = $_SESSION['homeowner_documents_flash'] ?? null;
unset($_SESSION['homeowner_documents_flash']);

$validIdPath = trim((string) ($user['valid_id_path'] ?? ''));
$proofPath = trim((string) ($user['proof_of_billing_path'] ?? ''));

$validIdUrl = document_url($validIdPath);
$proofUrl = document_url($proofPath);

$validIdUploaded = $validIdUrl !== '';
$proofUploaded = $proofUrl !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>
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
                    My Documents
                </h1>
                <p class="hidden text-xs font-medium text-slate-500 dark:text-slate-400 sm:block">
                    Upload the homeowner documents required by the HOA
                </p>
            </div>

            <div class="ml-auto">
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
            <div class="mb-5 rounded-2xl border px-4 py-3 text-sm font-semibold <?= ($flash['type'] ?? '') === 'success'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300'
                : 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300' ?>">
                <?= esc($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <section class="mb-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl font-extrabold text-slate-900 dark:text-white">Homeowner Documents</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500 dark:text-slate-400">
                        Upload your Valid ID and Proof of Billing. Once saved, the HOA administrator can view the same files from your homeowner record.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-bold <?= $validIdUploaded
                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                        : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' ?>">
                        <i class="bi <?= $validIdUploaded ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>"></i>
                        Valid ID
                    </span>

                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-bold <?= $proofUploaded
                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                        : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' ?>">
                        <i class="bi <?= $proofUploaded ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>"></i>
                        Proof of Billing
                    </span>
                </div>
            </div>
        </section>

        <form
            method="post"
            enctype="multipart/form-data"
            class="grid gap-6 lg:grid-cols-2"
        >
            <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-xl text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                            <i class="bi bi-person-vcard-fill"></i>
                        </div>
                        <div>
                            <h3 class="font-extrabold">Valid ID</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Government-issued or accepted identification</p>
                        </div>
                    </div>
                </div>

                <div class="p-5">
                    <?php if ($validIdUploaded): ?>
                        <div class="mb-4 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-950">
                            <div class="flex min-h-[240px] items-center justify-center p-3">
                                <?php if (is_image_document($validIdPath)): ?>
                                    <img
                                        src="<?= esc($validIdUrl) ?>"
                                        alt="Current Valid ID"
                                        class="max-h-[230px] max-w-full rounded-lg object-contain"
                                    >
                                <?php elseif (is_pdf_document($validIdPath)): ?>
                                    <iframe
                                        src="<?= esc($validIdUrl) ?>"
                                        title="Current Valid ID"
                                        class="h-[240px] w-full rounded-lg border-0"
                                    ></iframe>
                                <?php else: ?>
                                    <div class="text-sm text-slate-500">Preview not available.</div>
                                <?php endif; ?>
                            </div>

                            <div class="border-t border-slate-200 bg-white p-3 text-center dark:border-slate-700 dark:bg-slate-900">
                                <a
                                    href="<?= esc($validIdUrl) ?>"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center gap-2 rounded-xl border border-emerald-200 px-3 py-2 text-sm font-bold text-emerald-700 transition hover:bg-emerald-50 dark:border-emerald-900 dark:text-emerald-300 dark:hover:bg-emerald-950"
                                >
                                    <i class="bi bi-box-arrow-up-right"></i>
                                    Open Current File
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="mb-4 flex min-h-[180px] items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center dark:border-slate-700 dark:bg-slate-950">
                            <div>
                                <i class="bi bi-cloud-arrow-up text-4xl text-slate-400"></i>
                                <p class="mt-2 text-sm font-bold text-slate-600 dark:text-slate-300">No Valid ID uploaded yet</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <label class="mb-2 block text-sm font-bold">Upload / Replace Valid ID</label>
                    <input
                        type="file"
                        name="valid_id"
                        accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                        class="block w-full rounded-xl border border-slate-300 bg-white p-3 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-100 file:px-3 file:py-2 file:font-bold file:text-emerald-800 dark:border-slate-700 dark:bg-slate-950 dark:file:bg-emerald-950 dark:file:text-emerald-300"
                    >
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">JPG, PNG, or PDF • Maximum 5 MB</p>
                </div>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-xl text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                            <i class="bi bi-receipt-cutoff"></i>
                        </div>
                        <div>
                            <h3 class="font-extrabold">Proof of Billing</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Document showing the homeowner/property billing address</p>
                        </div>
                    </div>
                </div>

                <div class="p-5">
                    <?php if ($proofUploaded): ?>
                        <div class="mb-4 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-950">
                            <div class="flex min-h-[240px] items-center justify-center p-3">
                                <?php if (is_image_document($proofPath)): ?>
                                    <img
                                        src="<?= esc($proofUrl) ?>"
                                        alt="Current Proof of Billing"
                                        class="max-h-[230px] max-w-full rounded-lg object-contain"
                                    >
                                <?php elseif (is_pdf_document($proofPath)): ?>
                                    <iframe
                                        src="<?= esc($proofUrl) ?>"
                                        title="Current Proof of Billing"
                                        class="h-[240px] w-full rounded-lg border-0"
                                    ></iframe>
                                <?php else: ?>
                                    <div class="text-sm text-slate-500">Preview not available.</div>
                                <?php endif; ?>
                            </div>

                            <div class="border-t border-slate-200 bg-white p-3 text-center dark:border-slate-700 dark:bg-slate-900">
                                <a
                                    href="<?= esc($proofUrl) ?>"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center gap-2 rounded-xl border border-emerald-200 px-3 py-2 text-sm font-bold text-emerald-700 transition hover:bg-emerald-50 dark:border-emerald-900 dark:text-emerald-300 dark:hover:bg-emerald-950"
                                >
                                    <i class="bi bi-box-arrow-up-right"></i>
                                    Open Current File
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="mb-4 flex min-h-[180px] items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center dark:border-slate-700 dark:bg-slate-950">
                            <div>
                                <i class="bi bi-cloud-arrow-up text-4xl text-slate-400"></i>
                                <p class="mt-2 text-sm font-bold text-slate-600 dark:text-slate-300">No Proof of Billing uploaded yet</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <label class="mb-2 block text-sm font-bold">Upload / Replace Proof of Billing</label>
                    <input
                        type="file"
                        name="proof_of_billing"
                        accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                        class="block w-full rounded-xl border border-slate-300 bg-white p-3 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-100 file:px-3 file:py-2 file:font-bold file:text-emerald-800 dark:border-slate-700 dark:bg-slate-950 dark:file:bg-emerald-950 dark:file:text-emerald-300"
                    >
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">JPG, PNG, or PDF • Maximum 5 MB</p>
                </div>
            </section>

            <div class="lg:col-span-2">
                <div class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Upload only the document you want to add or replace. Existing files remain unchanged if no new file is selected.
                    </p>

                    <button
                        type="submit"
                        class="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-emerald-700 px-5 py-3 text-sm font-extrabold text-white shadow-sm transition hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-200 dark:bg-emerald-600 dark:hover:bg-emerald-500 dark:focus:ring-emerald-950"
                    >
                        <i class="bi bi-cloud-check-fill"></i>
                        Save Documents
                    </button>
                </div>
            </div>
        </form>
    </main>
</div>

<script>
(function () {
    const root = document.documentElement;
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarClose = document.getElementById('sidebarClose');

    function updateThemeIcon() {
        if (!themeIcon) return;
        const dark = root.classList.contains('dark');
        themeIcon.className = dark ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
    }

    function closeSidebar() {
        if (!sidebar || !sidebarOverlay) return;
        sidebar.classList.add('-translate-x-full');
        sidebarOverlay.classList.add('hidden');
    }

    function openSidebar() {
        if (!sidebar || !sidebarOverlay) return;
        sidebar.classList.remove('-translate-x-full');
        sidebarOverlay.classList.remove('hidden');
    }

    themeToggle?.addEventListener('click', function () {
        const dark = !root.classList.contains('dark');
        root.classList.toggle('dark', dark);
        localStorage.setItem('hoa-theme', dark ? 'dark' : 'light');
        updateThemeIcon();
    });

    sidebarToggle?.addEventListener('click', openSidebar);
    sidebarClose?.addEventListener('click', closeSidebar);
    sidebarOverlay?.addEventListener('click', closeSidebar);

    updateThemeIcon();
})();
</script>

</body>
</html>
