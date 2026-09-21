<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
    header("Location: ../index.php");
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);




function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fullNameSimple(array $r): string {
    return trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
}

$hid = (int)$_SESSION['homeowner_id'];

$stmt = $conn->prepare("
    SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number, latitude, longitude
    FROM homeowners
    WHERE id=? LIMIT 1
");
$stmt->bind_param("i", $hid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user['status'] !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
}

$phase      = (string)$user['phase'];
$fullName   = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
$mustChange = ((int)$user['must_change_password'] === 1);
$initials   = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));
$pageTitle  = "South Meridian Homes Salitran • Voting • ".$phase;
$activePage = basename($_SERVER['PHP_SELF'] ?? 'homeowner_voting.php');

if ($mustChange) {
    header("Location: homeowner_dashboard.php");
    exit;
}

$lat = $user['latitude'];
$lng = $user['longitude'];

$positions = ['President','Vice President','Secretary','Treasurer','Auditor','Board of Director'];

date_default_timezone_set('Asia/Manila');

$currentElection = null;
$electionState = 'not_started'; // not_started | draft | active | finished
$activeElectionId = 0;
$nomineesByPosition = [];
$votedMap = [];
$votedBoardIds = [];
$votedCounts = [];
$resultsByPosition = [];
$publishedOfficersByPosition = [];
$hasPublishedWinners = false;
$successMsg = '';
$errorMsg = '';

foreach ($positions as $p) {
    $nomineesByPosition[$p] = [];
    $resultsByPosition[$p] = [];
    $publishedOfficersByPosition[$p] = [];
}

/* =========================
   GET CURRENT SESSION FOR PHASE
   priority: active > draft > finished
   ========================= */
$stmt = $conn->prepare("
    SELECT *
    FROM election_sessions
    WHERE phase=? AND status='active'
    ORDER BY id DESC
    LIMIT 1
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$currentElection = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($currentElection) {
    $electionState = 'active';
} else {
    $stmt = $conn->prepare("
        SELECT *
        FROM election_sessions
        WHERE phase=? AND status='draft'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("s", $phase);
    $stmt->execute();
    $currentElection = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($currentElection) {
        $electionState = 'draft';
    } else {
        $stmt = $conn->prepare("
            SELECT *
            FROM election_sessions
            WHERE phase=? AND status='finished'
            ORDER BY ended_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->bind_param("s", $phase);
        $stmt->execute();
        $currentElection = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($currentElection) {
            $electionState = 'finished';
        }
    }
}


/* =========================
   CSRF
   ========================= */
if (empty($_SESSION['csrf_homeowner_voting'])) {
    $_SESSION['csrf_homeowner_voting'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['csrf_homeowner_voting'];

/* =========================
   HANDLE VOTE SUBMIT
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vote'])) {
    $postedCsrf = (string)($_POST['csrf'] ?? '');

    if ($postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
        $errorMsg = "Your session token is no longer valid. Please refresh the page and try again.";
    } else {
        $electionId = (int)($_POST['election_id'] ?? 0);
        $position = trim((string)($_POST['position'] ?? ''));

        if ($electionId <= 0 || $position === '' || !in_array($position, $positions, true)) {
            $errorMsg = "Invalid vote submission.";
        } else {
            $stmt = $conn->prepare("
                SELECT id, phase, status
                FROM election_sessions
                WHERE id=? LIMIT 1
            ");
            $stmt->bind_param("i", $electionId);
            $stmt->execute();
            $sessionRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$sessionRow || $sessionRow['status'] !== 'active' || $sessionRow['phase'] !== $phase) {
                $errorMsg = "Voting is not active.";
            } else {
                if ($position === 'Board of Director') {
                    $nomineeIds = $_POST['nominee_homeowner_id'] ?? [];
                    if (!is_array($nomineeIds)) $nomineeIds = [];

                    $nomineeIds = array_values(array_unique(array_map('intval', $nomineeIds)));
                    $nomineeIds = array_filter($nomineeIds, fn($v) => $v > 0);

                    if (count($nomineeIds) < 1) {
                        $errorMsg = "Please select at least 1 Board of Director nominee.";
                    } elseif (count($nomineeIds) > 6) {
                        $errorMsg = "You can only vote for up to 6 Board of Directors.";
                    } else {
                        $stmt = $conn->prepare("
                            SELECT nominee_homeowner_id
                            FROM election_votes
                            WHERE election_id=? AND voter_homeowner_id=? AND position='Board of Director'
                        ");
                        $stmt->bind_param("ii", $electionId, $hid);
                        $stmt->execute();
                        $existingRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();

                        $existingIds = array_map(fn($r) => (int)$r['nominee_homeowner_id'], $existingRows);

                        if (count($existingIds) >= 6) {
                            $errorMsg = "You already completed your Board of Director votes.";
                        } else {
                            $placeholders = implode(',', array_fill(0, count($nomineeIds), '?'));
                            $types = 'is' . str_repeat('i', count($nomineeIds));

                            $sql = "
                                SELECT homeowner_id
                                FROM election_nominations
                                WHERE election_id=? AND position=? AND homeowner_id IN ($placeholders)
                            ";
                            $stmt = $conn->prepare($sql);
                            $bindValues = array_merge([$electionId, $position], $nomineeIds);
                            $stmt->bind_param($types, ...$bindValues);
                            $stmt->execute();
                            $validRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                            $stmt->close();

                            $validIds = array_map(fn($r) => (int)$r['homeowner_id'], $validRows);
                            sort($validIds);
                            $submittedIds = $nomineeIds;
                            sort($submittedIds);

                            if ($validIds !== $submittedIds) {
                                $errorMsg = "One or more selected nominees are invalid.";
                            } else {
                                $duplicates = array_intersect($submittedIds, $existingIds);

                                if (!empty($duplicates)) {
                                    $errorMsg = "You already voted for one or more selected Board of Director nominees.";
                                } elseif ((count($existingIds) + count($submittedIds)) > 6) {
                                    $errorMsg = "You can only vote for a maximum of 6 Board of Directors.";
                                } else {
                                    $conn->begin_transaction();

                                    try {
                                        $stmtInsert = $conn->prepare("
                                            INSERT INTO election_votes
                                            (election_id, phase, position, voter_homeowner_id, nominee_homeowner_id)
                                            VALUES (?, ?, 'Board of Director', ?, ?)
                                        ");

                                        foreach ($submittedIds as $nomineeId) {
                                            $stmtInsert->bind_param("isii", $electionId, $phase, $hid, $nomineeId);
                                            $stmtInsert->execute();
                                        }

                                        $stmtInsert->close();
                                        $conn->commit();
                                        $successMsg = "Your Board of Director votes have been submitted successfully.";
                                    } catch (Throwable $e) {
                                        $conn->rollback();
                                        $errorMsg = "Failed to submit Board of Director votes.";
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $nomineeId = (int)($_POST['nominee_homeowner_id'] ?? 0);

                    if ($nomineeId <= 0) {
                        $errorMsg = "Please select a nominee.";
                    } else {
                        $stmt = $conn->prepare("
                            SELECT id
                            FROM election_nominations
                            WHERE election_id=? AND position=? AND homeowner_id=?
                            LIMIT 1
                        ");
                        $stmt->bind_param("isi", $electionId, $position, $nomineeId);
                        $stmt->execute();
                        $validNominee = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        if (!$validNominee) {
                            $errorMsg = "Invalid nominee selected.";
                        } else {
                            $stmt = $conn->prepare("
                                SELECT COUNT(*) AS total
                                FROM election_votes
                                WHERE election_id=? AND voter_homeowner_id=? AND position=?
                            ");
                            $stmt->bind_param("iis", $electionId, $hid, $position);
                            $stmt->execute();
                            $row = $stmt->get_result()->fetch_assoc();
                            $stmt->close();

                            if ((int)$row['total'] >= 1) {
                                $errorMsg = "You already voted for this position.";
                            } else {
                                $stmt = $conn->prepare("
                                    INSERT INTO election_votes
                                    (election_id, phase, position, voter_homeowner_id, nominee_homeowner_id)
                                    VALUES (?, ?, ?, ?, ?)
                                ");
                                $stmt->bind_param("issii", $electionId, $phase, $position, $hid, $nomineeId);
                                $stmt->execute();
                                $stmt->close();

                                $successMsg = "Your vote for {$position} has been submitted successfully.";
                            }
                        }
                    }
                }
            }
        }
    }
}

/* =========================
   LOAD ACTIVE SESSION NOMINEES + USER VOTES
   ========================= */
if ($electionState === 'active' && $currentElection) {
    $activeElectionId = (int)$currentElection['id'];

    $stmt = $conn->prepare("
        SELECT
            n.position,
            n.homeowner_id,
            h.first_name,
            h.last_name,
            h.house_lot_number
        FROM election_nominations n
        INNER JOIN homeowners h ON h.id = n.homeowner_id
        WHERE n.election_id=?
        ORDER BY FIELD(n.position,'President','Vice President','Secretary','Treasurer','Auditor','Board of Director'),
                 h.first_name, h.last_name
    ");
    $stmt->bind_param("i", $activeElectionId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $r) {
        $nomineesByPosition[$r['position']][] = $r;
    }

    $stmt = $conn->prepare("
        SELECT position, nominee_homeowner_id
        FROM election_votes
        WHERE election_id=? AND voter_homeowner_id=?
    ");
    $stmt->bind_param("ii", $activeElectionId, $hid);
    $stmt->execute();
    $votedRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($votedRows as $v) {
        $pos = (string)$v['position'];
        $nomId = (int)$v['nominee_homeowner_id'];

        if (!isset($votedCounts[$pos])) $votedCounts[$pos] = 0;
        $votedCounts[$pos]++;

        if ($pos === 'Board of Director') {
            $votedBoardIds[] = $nomId;
        } else {
            $votedMap[$pos] = $nomId;
        }
    }
}

/* =========================
   LOAD FINISHED RESULTS
   ========================= */
if ($electionState === 'finished' && $currentElection) {
    $finishedElectionId = (int)$currentElection['id'];

    foreach ($positions as $position) {
        $stmt = $conn->prepare("
            SELECT
                h.id AS nominee_homeowner_id,
                CONCAT(h.first_name, ' ', h.last_name) AS nominee_name,
                h.house_lot_number,
                COUNT(v.id) AS total_votes
            FROM election_nominations n
            INNER JOIN homeowners h ON h.id = n.homeowner_id
            LEFT JOIN election_votes v
                ON v.election_id = n.election_id
               AND v.nominee_homeowner_id = n.homeowner_id
               AND v.position = n.position
            WHERE n.election_id=? AND n.position=?
            GROUP BY h.id, h.first_name, h.last_name, h.house_lot_number
            ORDER BY total_votes DESC, nominee_name ASC
        ");
        $stmt->bind_param("is", $finishedElectionId, $position);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $resultsByPosition[$position] = $rows;
    }
}

/* =========================
   LOAD PUBLISHED WINNERS / CURRENT OFFICERS
   ========================= */
$stmt = $conn->prepare("
    SELECT id, position, officer_name, officer_email, updated_at
    FROM hoa_officers
    WHERE phase=? AND is_active=1
    ORDER BY FIELD(position,'President','Vice President','Secretary','Treasurer','Auditor','Board of Director'), id ASC
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$publishedRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($publishedRows as $row) {
    $publishedOfficersByPosition[$row['position']][] = $row;
    $hasPublishedWinners = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= esc($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<script>
(function(){
    const saved = localStorage.getItem('hoa-theme');
    const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.classList.toggle('dark', saved === 'dark' || (!saved && systemDark));
})();
</script>

<style type="text/tailwindcss">
@custom-variant dark (&:where(.dark, .dark *));
</style>

<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
#coverMap{width:100%;min-height:230px}
.leaflet-container{font-family:inherit}
</style>
</head>

<body class="bg-slate-50 text-slate-900 antialiased transition-colors dark:bg-slate-950 dark:text-slate-100">

<div id="sidebarOverlay" class="fixed inset-0 z-50 hidden bg-slate-950/50 backdrop-blur-[1px] lg:hidden"></div>

<?php include 'homeowner_sidebar.php'; ?>

<div class="min-h-screen lg:ml-[280px]">

<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
    <div class="mx-auto flex min-h-[72px] max-w-7xl items-center gap-3 px-4 sm:px-6">
        <button type="button" id="sidebarToggle"
            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-2xl text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 dark:focus:ring-emerald-950 lg:hidden"
            aria-label="Open menu">
            <i class="bi bi-list"></i>
        </button>

        <a href="homeowner_dashboard.php" class="min-w-0">
            <div class="truncate text-base font-bold text-emerald-800 sm:text-lg dark:text-emerald-300">HOA Community</div>
            <div class="hidden text-xs font-medium text-slate-500 sm:block dark:text-slate-400">South Meridian Homes Salitran</div>
        </a>

        <div class="ml-auto flex items-center gap-2 sm:gap-3">
            <button type="button" id="themeToggle"
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-xl text-slate-700 shadow-sm transition hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-emerald-300 dark:focus:ring-emerald-950"
                aria-label="Switch to dark mode" title="Switch to dark mode">
                <i id="themeIcon" class="bi bi-moon-stars-fill"></i>
            </button>

            <div class="hidden text-right md:block">
                <div class="max-w-[220px] truncate text-sm font-bold text-slate-800 dark:text-slate-200"><?= esc($fullName) ?></div>
                <div class="text-xs font-medium text-slate-500 dark:text-slate-400"><?= esc($phase) ?></div>
            </div>

            <a href="logout.php"
                class="flex min-h-12 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700 sm:px-4 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-red-900 dark:hover:bg-red-950/40 dark:hover:text-red-300">
                <i class="bi bi-box-arrow-right text-lg"></i>
                <span class="hidden sm:inline">Logout</span>
            </a>
        </div>
    </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8">

    <section class="mb-6">
        <a href="homeowner_dashboard.php"
            class="inline-flex items-center gap-2 text-sm font-semibold text-emerald-700 hover:text-emerald-800 dark:text-emerald-400 dark:hover:text-emerald-300">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>

        <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">Community Election</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-slate-100">HOA Voting</h1>
                <p class="mt-2 max-w-2xl text-[15px] leading-6 text-slate-600 dark:text-slate-400">
                    View the current election, submit your votes while voting is active,
                    and review published officers or completed results.
                </p>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row">
                <div class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-white px-3 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">
                    <i class="bi bi-house-door-fill text-emerald-700 dark:text-emerald-400"></i>
                    <?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?>
                </div>

                <?php if ($electionState === 'active'): ?>
                    <span class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-100 px-4 text-sm font-bold text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:ring-emerald-900">
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span> Voting Active
                    </span>
                <?php elseif ($electionState === 'finished'): ?>
                    <span class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-slate-100 px-4 text-sm font-bold text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700">
                        <i class="bi bi-check2-circle"></i> Voting Finished
                    </span>
                <?php elseif ($electionState === 'draft'): ?>
                    <span class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-amber-50 px-4 text-sm font-bold text-amber-700 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900">
                        <i class="bi bi-hourglass-split"></i> Not Started
                    </span>
                <?php else: ?>
                    <span class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-slate-100 px-4 text-sm font-bold text-slate-600 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700">
                        <i class="bi bi-info-circle-fill"></i> No Session
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="relative min-h-[230px] overflow-hidden bg-slate-100 dark:bg-slate-800">
            <?php if (!empty($lat) && !empty($lng)): ?>
                <div id="coverMap" data-lat="<?= esc($lat) ?>" data-lng="<?= esc($lng) ?>"></div>
            <?php else: ?>
                <div class="flex min-h-[230px] items-center justify-center px-4 text-center">
                    <div>
                        <i class="bi bi-geo-alt text-3xl text-slate-400 dark:text-slate-500"></i>
                        <p class="mt-2 text-sm font-semibold text-slate-500 dark:text-slate-400">No saved home location yet.</p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="absolute left-4 top-4 z-[500] rounded-xl bg-slate-950/75 px-3 py-2 text-xs font-bold text-white shadow-lg backdrop-blur">
                South Meridian Homes Salitran • <?= esc($phase) ?>
            </div>
        </div>
    </section>

    <section class="mb-6 grid gap-4 md:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-xl text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                <i class="bi bi-person-check-fill"></i>
            </div>
            <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">Homeowner</p>
            <p class="mt-1 break-words font-bold text-slate-900 dark:text-slate-100"><?= esc($fullName) ?></p>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-100 text-xl text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">
                <i class="bi bi-geo-alt-fill"></i>
            </div>
            <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">Phase</p>
            <p class="mt-1 font-bold text-slate-900 dark:text-slate-100"><?= esc($phase) ?></p>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-100 text-xl text-violet-700 dark:bg-violet-950/60 dark:text-violet-300">
                <i class="bi bi-house-door-fill"></i>
            </div>
            <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">House / Lot</p>
            <p class="mt-1 break-words font-bold text-slate-900 dark:text-slate-100"><?= esc($user['house_lot_number'] ?? '') ?></p>
        </div>
    </section>

    <section class="mb-6 rounded-2xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
        <div class="flex items-start gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-lg text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                <i class="bi bi-info-circle-fill"></i>
            </div>
            <div>
                <h2 class="font-bold text-blue-950 dark:text-blue-200">Voting Guide</h2>
                <p class="mt-1 text-sm leading-6 text-blue-800 dark:text-blue-300">
                    President, Vice President, Secretary, Treasurer, and Auditor allow one vote each.
                    Board of Director allows up to six votes in total.
                </p>
            </div>
        </div>
    </section>

    <?php if ($successMsg !== ''): ?>
        <div class="page-flash mb-5 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/40">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-lg text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="font-bold text-emerald-900 dark:text-emerald-200">Vote submitted</p>
                <p class="mt-1 text-sm leading-6 text-emerald-800 dark:text-emerald-300"><?= esc($successMsg) ?></p>
            </div>
            <button type="button" class="btn-close-flash flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-emerald-700 hover:bg-emerald-100 dark:text-emerald-300 dark:hover:bg-emerald-950" aria-label="Close message">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg !== ''): ?>
        <div class="page-flash mb-5 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/40">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-red-100 text-lg text-red-700 dark:bg-red-950 dark:text-red-300">
                <i class="bi bi-x-circle-fill"></i>
            </div>
            <div class="min-w-0 flex-1">
                <p class="font-bold text-red-900 dark:text-red-200">Vote not submitted</p>
                <p class="mt-1 text-sm leading-6 text-red-800 dark:text-red-300"><?= esc($errorMsg) ?></p>
            </div>
            <button type="button" class="btn-close-flash flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-red-700 hover:bg-red-100 dark:text-red-300 dark:hover:bg-red-950" aria-label="Close message">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    <?php endif; ?>

    <?php if ($hasPublishedWinners): ?>
        <section class="mb-6 overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-sm dark:border-emerald-900 dark:bg-slate-900">
            <div class="flex flex-col gap-3 border-b border-emerald-200 bg-emerald-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-emerald-900 dark:bg-emerald-950/30">
                <div>
                    <h2 class="flex items-center gap-2 text-lg font-bold text-emerald-950 dark:text-emerald-200">
                        <i class="bi bi-trophy-fill"></i> Published HOA Officers
                    </h2>
                    <p class="mt-1 text-sm text-emerald-800 dark:text-emerald-300">Current published officers for <?= esc($phase) ?>.</p>
                </div>
                <span class="inline-flex w-fit items-center gap-2 rounded-xl bg-emerald-700 px-3 py-2 text-xs font-bold text-white">
                    <i class="bi bi-patch-check-fill"></i> Published
                </span>
            </div>

            <div class="grid gap-4 p-4 sm:p-5 xl:grid-cols-2">
                <?php foreach ($positions as $position): ?>
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/70 dark:border-slate-700 dark:bg-slate-800/40">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                            <h3 class="font-bold text-slate-900 dark:text-slate-100"><?= esc($position) ?></h3>
                            <span class="rounded-lg bg-emerald-100 px-2.5 py-1.5 text-[11px] font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                <?= $position === 'Board of Director' ? 'Current Officers' : 'Current Officer' ?>
                            </span>
                        </div>

                        <div class="p-4">
                            <?php if (empty($publishedOfficersByPosition[$position])): ?>
                                <p class="text-sm text-slate-500 dark:text-slate-400">No published officer yet for this position.</p>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($publishedOfficersByPosition[$position] as $idx => $officer): ?>
                                        <div class="rounded-xl bg-white p-3 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                            <div class="flex items-start gap-3">
                                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                    <?= (int)($idx + 1) ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <p class="break-words font-bold text-slate-900 dark:text-slate-100"><?= esc($officer['officer_name'] ?: '-') ?></p>
                                                    <p class="mt-1 break-all text-xs text-slate-500 dark:text-slate-400"><?= esc($officer['officer_email'] ?: '-') ?></p>
                                                    <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">Published: <?= esc($officer['updated_at'] ?: '-') ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section id="votingSection" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
            <div>
                <h2 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-slate-100">
                    <i class="bi bi-check2-square text-emerald-700 dark:text-emerald-400"></i> HOA Voting
                </h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400"><?= esc($phase) ?></p>
            </div>

            <?php if ($currentElection): ?>
                <div class="text-left text-xs text-slate-500 sm:text-right dark:text-slate-400">
                    <div class="font-bold text-slate-700 dark:text-slate-300"><?= esc($currentElection['title'] ?? 'HOA Election') ?></div>
                    <?php if (!empty($currentElection['started_at'])): ?>
                        <div class="mt-1">Started: <?= esc($currentElection['started_at']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="p-4 sm:p-5">

            <?php if ($electionState === 'active' && $currentElection): ?>

                <div class="mb-5 flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-800 dark:border-blue-900 dark:bg-blue-950/30 dark:text-blue-300">
                    <i class="bi bi-info-circle-fill mt-0.5 shrink-0"></i>
                    <span>Submit one vote for each officer position. For Board of Director, you may submit selections until you reach six total votes.</span>
                </div>

                <div class="space-y-4">
                    <?php foreach ($positions as $position): ?>
                        <?php
                        $isBoard = ($position === 'Board of Director');
                        $boardAlreadyCount = (int)($votedCounts['Board of Director'] ?? 0);
                        $singleAlreadyVoted = isset($votedMap[$position]);
                        $boardDone = $isBoard && $boardAlreadyCount >= 6;
                        $remainingBoardSlots = max(0, 6 - $boardAlreadyCount);
                        ?>

                        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/60 dark:border-slate-700 dark:bg-slate-800/40">
                            <div class="flex flex-col gap-2 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-700">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Position</p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-900 dark:text-slate-100"><?= esc($position) ?></h3>
                                </div>

                                <?php if ($isBoard): ?>
                                    <?php if ($boardDone): ?>
                                        <span class="inline-flex w-fit items-center gap-1.5 rounded-lg bg-emerald-100 px-2.5 py-1.5 text-xs font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                            <i class="bi bi-check-circle-fill"></i> Completed (6/6)
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex w-fit items-center gap-1.5 rounded-lg bg-amber-100 px-2.5 py-1.5 text-xs font-bold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                                            <i class="bi bi-ui-checks"></i> <?= $boardAlreadyCount ?>/6 submitted
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($singleAlreadyVoted): ?>
                                        <span class="inline-flex w-fit items-center gap-1.5 rounded-lg bg-emerald-100 px-2.5 py-1.5 text-xs font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                            <i class="bi bi-check-circle-fill"></i> Already Voted
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex w-fit items-center gap-1.5 rounded-lg bg-amber-100 px-2.5 py-1.5 text-xs font-bold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                                            <i class="bi bi-circle"></i> Not Yet Voted
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <div class="p-4">
                                <?php if (empty($nomineesByPosition[$position])): ?>
                                    <div class="rounded-xl border border-dashed border-slate-300 p-5 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                        No nominees available for this position.
                                    </div>

                                <?php elseif ($isBoard && $boardDone): ?>
                                    <div class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300">
                                        <i class="bi bi-check-circle-fill mt-0.5 shrink-0"></i>
                                        <span>You already completed your <strong>6 Board of Director votes</strong>.</span>
                                    </div>

                                <?php elseif (!$isBoard && $singleAlreadyVoted): ?>
                                    <?php
                                    $chosenName = '';
                                    foreach ($nomineesByPosition[$position] as $n) {
                                        if ((int)$n['homeowner_id'] === (int)$votedMap[$position]) {
                                            $chosenName = fullNameSimple($n);
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-300">
                                        <i class="bi bi-check-circle-fill mt-0.5 shrink-0"></i>
                                        <span>
                                            You already voted for <strong><?= esc($position) ?></strong>
                                            <?php if ($chosenName !== ''): ?> — <strong><?= esc($chosenName) ?></strong><?php endif; ?>.
                                        </span>
                                    </div>

                                <?php else: ?>
                                    <form method="post"
                                        class="vote-submit-form <?= $isBoard ? 'board-form' : '' ?>"
                                        data-position="<?= esc($position) ?>"
                                        data-is-board="<?= $isBoard ? '1' : '0' ?>"
                                        data-existing-board-count="<?= $isBoard ? $boardAlreadyCount : 0 ?>"
                                        data-remaining-board-slots="<?= $isBoard ? $remainingBoardSlots : 0 ?>">

                                        <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                                        <input type="hidden" name="submit_vote" value="1">
                                        <input type="hidden" name="election_id" value="<?= (int)$activeElectionId ?>">
                                        <input type="hidden" name="position" value="<?= esc($position) ?>">

                                        <?php if ($isBoard): ?>
                                            <div class="mb-3 flex flex-col gap-2 rounded-xl bg-emerald-50 p-3 sm:flex-row sm:items-center sm:justify-between dark:bg-emerald-950/30">
                                                <div class="text-sm font-bold text-emerald-800 dark:text-emerald-300" data-board-counter><?= $boardAlreadyCount ?>/6 submitted</div>
                                                <div class="text-xs text-emerald-700 dark:text-emerald-400">You may select up to <?= $remainingBoardSlots ?> more.</div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="grid gap-3 xl:grid-cols-2">
                                            <?php foreach ($nomineesByPosition[$position] as $n): ?>
                                                <?php
                                                $nid = (int)$n['homeowner_id'];
                                                $checked = ($isBoard && in_array($nid, $votedBoardIds, true));
                                                ?>
                                                <label class="nominee-option flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 transition hover:border-emerald-300 hover:bg-emerald-50/60 dark:border-slate-700 dark:bg-slate-900 dark:hover:border-emerald-800 dark:hover:bg-emerald-950/20 <?= $checked ? 'opacity-70' : '' ?>">
                                                    <input
                                                        class="board-checkbox mt-1 h-5 w-5 shrink-0 border-slate-300 text-emerald-600 focus:ring-emerald-500 dark:border-slate-600 dark:bg-slate-800"
                                                        type="<?= $isBoard ? 'checkbox' : 'radio' ?>"
                                                        name="<?= $isBoard ? 'nominee_homeowner_id[]' : 'nominee_homeowner_id' ?>"
                                                        value="<?= $nid ?>"
                                                        <?= $checked ? 'checked disabled' : '' ?>
                                                        <?= !$isBoard ? 'required' : '' ?>
                                                    >
                                                    <span class="min-w-0">
                                                        <span class="nominee-name block break-words font-bold text-slate-900 dark:text-slate-100"><?= esc(fullNameSimple($n)) ?></span>
                                                        <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">
                                                            <i class="bi bi-house-door mr-1"></i><?= esc($n['house_lot_number'] ?? '') ?>
                                                        </span>
                                                        <?php if ($checked): ?>
                                                            <span class="mt-2 inline-flex items-center gap-1 rounded-lg bg-emerald-100 px-2 py-1 text-[10px] font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                                <i class="bi bi-check-circle-fill"></i> Already submitted
                                                            </span>
                                                        <?php endif; ?>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>

                                        <button type="submit"
                                            class="mt-4 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-700 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-100 sm:w-auto dark:bg-emerald-600 dark:hover:bg-emerald-500 dark:focus:ring-emerald-950">
                                            <i class="bi bi-check-circle-fill"></i>
                                            <?= $isBoard ? 'Submit Board of Director Votes' : 'Submit Vote for ' . esc($position) ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($electionState === 'finished' && $currentElection): ?>

                <div class="mb-5 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/40">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-200 text-lg text-slate-700 dark:bg-slate-700 dark:text-slate-200">
                            <i class="bi bi-flag-fill"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-900 dark:text-slate-100">Voting is finished</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-400">
                                Election: <strong><?= esc($currentElection['title'] ?? 'HOA Election') ?></strong>
                                <?php if (!empty($currentElection['ended_at'])): ?>
                                    • Ended: <strong><?= esc($currentElection['ended_at']) ?></strong>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <?php foreach ($positions as $position): ?>
                        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/60 dark:border-slate-700 dark:bg-slate-800/40">
                            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-4 dark:border-slate-700">
                                <h3 class="font-bold text-slate-900 dark:text-slate-100"><?= esc($position) ?> Results</h3>
                                <span class="rounded-lg bg-slate-200 px-2.5 py-1.5 text-xs font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-200">Final Tally</span>
                            </div>

                            <div class="p-4">
                                <?php if (empty($resultsByPosition[$position])): ?>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">No results available for this position.</p>
                                <?php else: ?>
                                    <div class="hidden overflow-x-auto md:block">
                                        <table class="min-w-full text-left text-sm">
                                            <thead class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                                <tr>
                                                    <th class="px-3 py-3">Rank</th>
                                                    <th class="px-3 py-3">Nominee</th>
                                                    <th class="px-3 py-3">House / Lot</th>
                                                    <th class="px-3 py-3 text-right">Votes</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                                                <?php foreach ($resultsByPosition[$position] as $idx => $r): ?>
                                                    <tr>
                                                        <td class="px-3 py-3">
                                                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-emerald-100 font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"><?= (int)($idx + 1) ?></span>
                                                        </td>
                                                        <td class="px-3 py-3 font-bold text-slate-900 dark:text-slate-100"><?= esc($r['nominee_name']) ?></td>
                                                        <td class="px-3 py-3 text-slate-600 dark:text-slate-400"><?= esc($r['house_lot_number']) ?></td>
                                                        <td class="px-3 py-3 text-right">
                                                            <span class="inline-flex min-w-10 items-center justify-center rounded-lg bg-emerald-100 px-2.5 py-1.5 font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"><?= (int)$r['total_votes'] ?></span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="space-y-3 md:hidden">
                                        <?php foreach ($resultsByPosition[$position] as $idx => $r): ?>
                                            <div class="flex items-center gap-3 rounded-xl bg-white p-3 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"><?= (int)($idx + 1) ?></span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="break-words font-bold text-slate-900 dark:text-slate-100"><?= esc($r['nominee_name']) ?></p>
                                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"><?= esc($r['house_lot_number']) ?></p>
                                                </div>
                                                <span class="shrink-0 rounded-lg bg-emerald-100 px-2.5 py-1.5 text-xs font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"><?= (int)$r['total_votes'] ?> votes</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($electionState === 'draft' && $currentElection): ?>

                <div class="flex min-h-[260px] flex-col items-center justify-center rounded-2xl border border-dashed border-amber-300 bg-amber-50 px-5 py-10 text-center dark:border-amber-900 dark:bg-amber-950/25">
                    <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-100 text-3xl text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                    <h3 class="mt-4 text-lg font-bold text-amber-950 dark:text-amber-200">Voting has not started yet</h3>
                    <p class="mt-2 max-w-xl text-sm leading-6 text-amber-800 dark:text-amber-300">
                        An election session exists for your phase, but the admin has not opened voting yet.
                    </p>
                    <p class="mt-3 text-xs font-semibold text-amber-700 dark:text-amber-400"><?= esc($currentElection['title'] ?? 'HOA Election') ?></p>
                </div>

            <?php else: ?>

                <div class="flex min-h-[260px] flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 px-5 py-10 text-center dark:border-slate-700">
                    <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 text-3xl text-slate-400 dark:bg-slate-800 dark:text-slate-500">
                        <i class="bi bi-inbox"></i>
                    </div>
                    <h3 class="mt-4 text-lg font-bold text-slate-900 dark:text-slate-100">No voting session available</h3>
                    <p class="mt-2 max-w-xl text-sm leading-6 text-slate-500 dark:text-slate-400">
                        Please wait for the admin to create and open a voting session for your phase.
                    </p>
                </div>

            <?php endif; ?>
        </div>
    </section>

    <footer class="mt-8 border-t border-slate-200 py-6 text-center text-sm text-slate-500 dark:border-slate-800 dark:text-slate-500">
        © South Meridian Homes Salitran
    </footer>
</main>
</div>


<!-- Vote confirmation modal -->
<div id="voteConfirmModal" class="fixed inset-0 z-[220] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" aria-hidden="true">
    <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <div class="p-5">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-100 text-xl text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                <i class="bi bi-check2-square"></i>
            </div>
            <h2 class="mt-4 text-lg font-bold text-slate-900 dark:text-slate-100">Confirm Your Vote</h2>
            <p id="voteConfirmText" class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">Please review your selection before submitting.</p>
            <div id="voteConfirmSelection" class="mt-4 rounded-xl bg-slate-50 p-3 text-sm font-semibold text-slate-800 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700"></div>
        </div>

        <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:flex-row sm:justify-end dark:border-slate-800 dark:bg-slate-950/40">
            <button type="button" id="cancelVoteBtn"
                class="min-h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                Cancel
            </button>

            <button type="button" id="confirmVoteBtn"
                class="min-h-11 rounded-xl bg-emerald-700 px-5 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-emerald-600 dark:hover:bg-emerald-500">
                <i class="bi bi-check-circle-fill mr-1"></i> Submit Vote
            </button>
        </div>
    </div>
</div>


<!-- Notice modal -->
<div id="noticeModal" class="fixed inset-0 z-[230] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" aria-hidden="true">
    <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
        <div class="p-5">
            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-red-100 text-xl text-red-700 dark:bg-red-950/60 dark:text-red-300">
                <i class="bi bi-exclamation-circle-fill"></i>
            </div>
            <h2 id="noticeTitle" class="mt-4 text-lg font-bold text-slate-900 dark:text-slate-100">Check Your Selection</h2>
            <p id="noticeMessage" class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400"></p>
        </div>

        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 dark:border-slate-800 dark:bg-slate-950/40">
            <button type="button" id="noticeOkBtn"
                class="min-h-11 rounded-xl bg-red-600 px-5 text-sm font-semibold text-white hover:bg-red-700 dark:bg-red-600 dark:hover:bg-red-500">
                OK
            </button>
        </div>
    </div>
</div>


<script>
function openModal(modal){
    if(!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.setAttribute('aria-hidden','false');
    document.body.classList.add('overflow-hidden');
}
function closeModal(modal){
    if(!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    modal.setAttribute('aria-hidden','true');
    if(!document.querySelector('#voteConfirmModal.flex, #noticeModal.flex')){
        document.body.classList.remove('overflow-hidden');
    }
}

const noticeModal = document.getElementById('noticeModal');
const noticeTitle = document.getElementById('noticeTitle');
const noticeMessage = document.getElementById('noticeMessage');
const noticeOkBtn = document.getElementById('noticeOkBtn');

function showNotice(title,message){
    if(!noticeModal) return;
    noticeTitle.textContent = title || 'Notice';
    noticeMessage.textContent = message || '';
    openModal(noticeModal);
}

noticeOkBtn?.addEventListener('click',()=>closeModal(noticeModal));
noticeModal?.addEventListener('click',e=>{ if(e.target===noticeModal) closeModal(noticeModal); });

const voteConfirmModal = document.getElementById('voteConfirmModal');
const voteConfirmText = document.getElementById('voteConfirmText');
const voteConfirmSelection = document.getElementById('voteConfirmSelection');
const cancelVoteBtn = document.getElementById('cancelVoteBtn');
const confirmVoteBtn = document.getElementById('confirmVoteBtn');
let pendingVoteForm = null;

document.querySelectorAll('.board-form').forEach(form=>{
    const existingCount = Number(form.dataset.existingBoardCount || 0);
    const remainingSlots = Number(form.dataset.remainingBoardSlots || 0);
    const counter = form.querySelector('[data-board-counter]');
    const boxes = form.querySelectorAll('.board-checkbox:not(:disabled)');

    function refreshCounter(){
        const selected = form.querySelectorAll('.board-checkbox:not(:disabled):checked').length;
        if(counter) counter.textContent = (existingCount + selected) + '/6 submitted or selected';
    }

    boxes.forEach(box=>{
        box.addEventListener('change',function(){
            const selected = form.querySelectorAll('.board-checkbox:not(:disabled):checked').length;
            if(selected > remainingSlots){
                this.checked = false;
                showNotice(
                    'Selection Limit Reached',
                    'You can only select ' + remainingSlots + ' more Board of Director nominee' + (remainingSlots===1?'':'s') + '.'
                );
            }
            refreshCounter();
        });
    });

    refreshCounter();
});

document.querySelectorAll('.vote-submit-form').forEach(form=>{
    form.addEventListener('submit',function(e){
        e.preventDefault();

        const position = form.dataset.position || 'this position';
        const isBoard = form.dataset.isBoard === '1';
        let names = [];

        if(isBoard){
            const selected = Array.from(form.querySelectorAll('.board-checkbox:not(:disabled):checked'));
            const remainingSlots = Number(form.dataset.remainingBoardSlots || 0);

            if(selected.length < 1){
                showNotice('Select a Nominee','Please select at least 1 Board of Director nominee before submitting.');
                return;
            }
            if(selected.length > remainingSlots){
                showNotice('Selection Limit Reached','You can only select up to ' + remainingSlots + ' more Board of Director nominee' + (remainingSlots===1?'':'s') + '.');
                return;
            }

            names = selected.map(input => input.closest('.nominee-option')?.querySelector('.nominee-name')?.textContent.trim() || 'Selected nominee');
            voteConfirmText.textContent =
                'You are submitting ' + selected.length + ' new Board of Director vote' + (selected.length===1?'':'s') + '. Submitted votes cannot be changed from this page.';
        } else {
            const selected = form.querySelector('input[name="nominee_homeowner_id"]:checked');

            if(!selected){
                showNotice('Select a Nominee','Please select a nominee for ' + position + ' before submitting.');
                return;
            }

            names = [selected.closest('.nominee-option')?.querySelector('.nominee-name')?.textContent.trim() || 'Selected nominee'];
            voteConfirmText.textContent =
                'You are submitting your vote for ' + position + '. Submitted votes cannot be changed from this page.';
        }

        voteConfirmSelection.textContent = names.join(', ');
        pendingVoteForm = form;
        openModal(voteConfirmModal);
    });
});

cancelVoteBtn?.addEventListener('click',()=>{
    pendingVoteForm = null;
    closeModal(voteConfirmModal);
});

voteConfirmModal?.addEventListener('click',e=>{
    if(e.target===voteConfirmModal){
        pendingVoteForm = null;
        closeModal(voteConfirmModal);
    }
});

confirmVoteBtn?.addEventListener('click',function(){
    if(!pendingVoteForm) return;

    const form = pendingVoteForm;
    pendingVoteForm = null;

    this.disabled = true;
    this.innerHTML = '<i class="bi bi-arrow-repeat animate-spin mr-1"></i> Submitting...';

    closeModal(voteConfirmModal);
    form.submit();
});

document.querySelectorAll('.btn-close-flash').forEach(button=>{
    button.addEventListener('click',()=>button.closest('.page-flash')?.remove());
});

(function(){
    const mapEl = document.getElementById('coverMap');
    if(!mapEl) return;

    const lat = parseFloat(mapEl.dataset.lat || '');
    const lng = parseFloat(mapEl.dataset.lng || '');
    if(!Number.isFinite(lat) || !Number.isFinite(lng)) return;

    const map = L.map('coverMap',{
        zoomControl:false,
        attributionControl:false,
        dragging:false,
        touchZoom:false,
        scrollWheelZoom:false,
        doubleClickZoom:false,
        boxZoom:false,
        keyboard:false
    }).setView([lat,lng],18);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:20}).addTo(map);
    L.marker([lat,lng]).addTo(map);

    setTimeout(()=>map.invalidateSize(),250);
    window.addEventListener('resize',()=>setTimeout(()=>map.invalidateSize(),200));
})();

function initSidebarDropdown(buttonId,menuId,caretId){
    const button = document.getElementById(buttonId);
    const menu = document.getElementById(menuId);
    const caret = document.getElementById(caretId);
    if(!button || !menu) return;

    button.addEventListener('click',()=>{
        const willOpen = menu.classList.contains('hidden');
        menu.classList.toggle('hidden');
        button.setAttribute('aria-expanded',willOpen?'true':'false');
        caret?.classList.toggle('rotate-180',willOpen);
    });
}

initSidebarDropdown('sbParkingToggle','sbParkingMenu','sbParkingCaret');
initSidebarDropdown('sbTenantToggle','sbTenantMenu','sbTenantCaret');

(function(){
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const openButton = document.getElementById('sidebarToggle');
    const closeButton = document.getElementById('sidebarClose');

    if(!sidebar || !overlay || !openButton) return;

    function openSidebar(){
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }
    function closeSidebar(){
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    openButton.addEventListener('click',openSidebar);
    closeButton?.addEventListener('click',closeSidebar);
    overlay.addEventListener('click',closeSidebar);

    sidebar.querySelectorAll('a').forEach(link=>{
        link.addEventListener('click',()=>{
            if(window.innerWidth < 1024) closeSidebar();
        });
    });

    window.addEventListener('resize',()=>{
        if(window.innerWidth >= 1024){
            overlay.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
    });
})();

(function(){
    const toggle = document.getElementById('themeToggle');
    const icon = document.getElementById('themeIcon');
    if(!toggle || !icon) return;

    function sync(){
        const dark = document.documentElement.classList.contains('dark');
        icon.className = dark ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
        toggle.setAttribute('aria-label',dark?'Switch to light mode':'Switch to dark mode');
        toggle.setAttribute('title',dark?'Switch to light mode':'Switch to dark mode');
    }

    toggle.addEventListener('click',()=>{
        const dark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('hoa-theme',dark?'dark':'light');
        sync();
    });

    sync();
})();

document.addEventListener('keydown',e=>{
    if(e.key !== 'Escape') return;

    if(voteConfirmModal && !voteConfirmModal.classList.contains('hidden')){
        pendingVoteForm = null;
        closeModal(voteConfirmModal);
        return;
    }

    if(noticeModal && !noticeModal.classList.contains('hidden')){
        closeModal(noticeModal);
    }
});
</script>

</body>
</html>