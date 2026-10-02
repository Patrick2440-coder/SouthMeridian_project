<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once '../config/database.php';

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function requestBadgeClass(string $status): string
{
    return match ($status) {
        'processed' => 'badge badge-success',
        'rejected' => 'badge badge-danger',
        default => 'badge badge-warning',
    };
}

function requestStatusLabel(string $status): string
{
    return match ($status) {
        'processed' => 'Processed',
        'rejected' => 'Rejected',
        default => 'Pending',
    };
}

function getPublishedSessionIds(mysqli $conn): array
{
    $published = [];
    $res = $conn->query("
        SELECT details
        FROM activity_logs
        WHERE module_key='voting_management'
          AND action='publish_results'
    ");

    while ($row = $res->fetch_assoc()) {
        if (preg_match('/session_id=(\d+)/', (string)($row['details'] ?? ''), $match)) {
            $published[(int)$match[1]] = true;
        }
    }
    $res->close();
    return $published;
}

function homeownerFullName(array $row): string
{
    return trim(
        (string)($row['first_name'] ?? '') . ' ' .
        (!empty($row['middle_name']) ? (string)$row['middle_name'] . ' ' : '') .
        (string)($row['last_name'] ?? '')
    );
}

$positions = [
    'President',
    'Vice President',
    'Secretary',
    'Treasurer',
    'Auditor',
    'Board of Director',
];

$adminId = (int)($_SESSION['admin_id'] ?? ($_SESSION['user_id'] ?? 0));

if ($adminId <= 0) {
    header('Location: ../index.php');
    exit();
}

$stmt = $conn->prepare("
    SELECT id, full_name, email, phase, role, position
    FROM admins
    WHERE id=?
    LIMIT 1
");
$stmt->bind_param('i', $adminId);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (
    !$admin ||
    (string)($admin['role'] ?? '') !== 'admin' ||
    strcasecmp(trim((string)($admin['position'] ?? '')), 'President') !== 0
) {
    http_response_code(403);
    exit('Only the current HOA President can access Voting Request.');
}

$phase = trim((string)($admin['phase'] ?? ''));
$presidentName = trim((string)($admin['full_name'] ?? 'President'));

if (empty($_SESSION['voting_request_csrf'])) {
    $_SESSION['voting_request_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['voting_request_csrf'];

$tableReady = false;
$requestTableReady = false;
$candidateTableReady = false;

$tableCheck = $conn->query("SHOW TABLES LIKE 'voting_requests'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $requestTableReady = true;
}
if ($tableCheck) {
    $tableCheck->close();
}

$tableCheck = $conn->query("SHOW TABLES LIKE 'voting_request_candidates'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $candidateTableReady = true;
}
if ($tableCheck) {
    $tableCheck->close();
}

$tableReady = $requestTableReady && $candidateTableReady;

/*
|--------------------------------------------------------------------------
| Approved homeowners available as proposed candidates
|--------------------------------------------------------------------------
*/
$approvedHomeowners = [];

$stmt = $conn->prepare("
    SELECT
        id,
        public_id,
        first_name,
        middle_name,
        last_name,
        house_lot_number
    FROM homeowners
    WHERE phase=?
      AND status='approved'
    ORDER BY first_name ASC, last_name ASC, id ASC
");
$stmt->bind_param('s', $phase);
$stmt->execute();
$approvedHomeowners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$approvedHomeownerIds = [];
foreach ($approvedHomeowners as $homeowner) {
    $approvedHomeownerIds[(int)$homeowner['id']] = true;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_voting_request'])) {
    if (!$tableReady) {
        $error = 'Voting Request setup is not installed yet. Please contact the system administrator.';
    } elseif (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } else {
        $title = trim((string)($_POST['election_title'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        $submittedCandidates = $_POST['candidates'] ?? [];
        $candidateSelections = [];
        $candidateError = '';

        foreach ($positions as $candidatePosition) {
            $rawIds = $submittedCandidates[$candidatePosition] ?? [];
            if (!is_array($rawIds)) {
                $rawIds = [$rawIds];
            }

            $ids = [];
            foreach ($rawIds as $rawId) {
                $candidateId = (int)$rawId;
                if ($candidateId <= 0) {
                    continue;
                }

                if (!isset($approvedHomeownerIds[$candidateId])) {
                    $candidateError = 'One of the selected candidates is no longer an approved homeowner from your phase.';
                    break 2;
                }

                $ids[$candidateId] = $candidateId;
            }

            $candidateSelections[$candidatePosition] = array_values($ids);

            if (!$candidateSelections[$candidatePosition]) {
                $candidateError = 'Please select at least one candidate for ' . $candidatePosition . '.';
                break;
            }
        }

        if ($title === '') {
            $error = 'Please enter the election title.';
        } elseif (mb_strlen($title) > 255) {
            $error = 'Election title is too long.';
        } elseif ($reason === '') {
            $error = 'Please explain why the voting is being requested.';
        } elseif (mb_strlen($reason) > 500) {
            $error = 'Reason is too long. Please keep it within 500 characters.';
        } elseif ($candidateError !== '') {
            $error = $candidateError;
        } else {
            $stmt = $conn->prepare("
                SELECT id
                FROM voting_requests
                WHERE phase=? AND status='pending'
                LIMIT 1
            ");
            $stmt->bind_param('s', $phase);
            $stmt->execute();
            $pendingRequest = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($pendingRequest) {
                $error = 'Your phase already has a pending voting request. Please wait for the Super Administrator to process it.';
            } else {
                $publishedIds = getPublishedSessionIds($conn);
                $openElection = null;

                $stmt = $conn->prepare("
                    SELECT id, title, status
                    FROM election_sessions
                    WHERE phase=?
                    ORDER BY id DESC
                ");
                $stmt->bind_param('s', $phase);
                $stmt->execute();
                $sessions = $stmt->get_result();
                while ($session = $sessions->fetch_assoc()) {
                    $sessionId = (int)$session['id'];
                    if (!isset($publishedIds[$sessionId])) {
                        $openElection = $session;
                        break;
                    }
                }
                $stmt->close();

                if ($openElection) {
                    $error = 'Your phase still has an election that must be completed and published before another voting request can be submitted.';
                } else {
                    $conn->begin_transaction();

                    try {
                        $stmt = $conn->prepare("
                            INSERT INTO voting_requests
                                (phase, requested_by_admin_id, election_title, reason, status)
                            VALUES (?, ?, ?, ?, 'pending')
                        ");
                        $stmt->bind_param('siss', $phase, $adminId, $title, $reason);
                        $stmt->execute();
                        $newRequestId = (int)$conn->insert_id;
                        $stmt->close();

                        $candidateStmt = $conn->prepare("
                            INSERT INTO voting_request_candidates
                                (voting_request_id, phase, position, homeowner_id)
                            VALUES (?, ?, ?, ?)
                        ");

                        foreach ($candidateSelections as $candidatePosition => $candidateIds) {
                            foreach ($candidateIds as $candidateId) {
                                $candidateStmt->bind_param(
                                    'issi',
                                    $newRequestId,
                                    $phase,
                                    $candidatePosition,
                                    $candidateId
                                );
                                $candidateStmt->execute();
                            }
                        }

                        $candidateStmt->close();
                        $conn->commit();

                        $success = 'Voting request and proposed candidates were submitted. The Super Administrator can now review and process them.';
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error = 'The voting request could not be submitted. Please try again.';
                    }
                }
            }
        }
    }
}

$requests = [];
if ($tableReady) {
    $stmt = $conn->prepare("
        SELECT
            vr.id,
            vr.election_title,
            vr.reason,
            vr.status,
            vr.superadmin_remarks,
            vr.election_session_id,
            vr.created_at,
            vr.processed_at,
            es.status AS election_status
        FROM voting_requests vr
        LEFT JOIN election_sessions es ON es.id = vr.election_session_id
        WHERE vr.phase=?
        ORDER BY vr.created_at DESC, vr.id DESC
        LIMIT 50
    ");
    $stmt->bind_param('s', $phase);
    $stmt->execute();
    $requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$requestCandidates = [];

if ($candidateTableReady && $requests) {
    $stmt = $conn->prepare("
        SELECT
            vrc.voting_request_id,
            vrc.position,
            vrc.homeowner_id,
            h.public_id,
            h.first_name,
            h.middle_name,
            h.last_name,
            h.house_lot_number
        FROM voting_request_candidates vrc
        INNER JOIN homeowners h
            ON h.id = vrc.homeowner_id
        INNER JOIN voting_requests vr
            ON vr.id = vrc.voting_request_id
        WHERE vr.phase=?
        ORDER BY
            vrc.voting_request_id DESC,
            FIELD(
                vrc.position,
                'President',
                'Vice President',
                'Secretary',
                'Treasurer',
                'Auditor',
                'Board of Director'
            ),
            h.first_name ASC,
            h.last_name ASC
    ");
    $stmt->bind_param('s', $phase);
    $stmt->execute();
    $candidateResult = $stmt->get_result();

    while ($candidate = $candidateResult->fetch_assoc()) {
        $requestId = (int)$candidate['voting_request_id'];
        $candidatePosition = (string)$candidate['position'];

        if (!isset($requestCandidates[$requestId])) {
            $requestCandidates[$requestId] = [];
        }

        if (!isset($requestCandidates[$requestId][$candidatePosition])) {
            $requestCandidates[$requestId][$candidatePosition] = [];
        }

        $requestCandidates[$requestId][$candidatePosition][] = $candidate;
    }

    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Voting Request - South Meridian Homes</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

<?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
    <link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">

    <script>
    (function () {
        try {
            const savedTheme = localStorage.getItem('hoa-theme');
            const dark = savedTheme === 'dark' || (
                !savedTheme &&
                window.matchMedia &&
                window.matchMedia('(prefers-color-scheme: dark)').matches
            );
            document.documentElement.classList.toggle('dark', dark);
        } catch (e) {}
    })();
    </script>

    <style>
        .page-title-wrap {
            text-align: center;
            margin-bottom: 20px;
        }

        .request-card,
        .history-card {
            border-radius: 16px;
        }

        .request-note {
            padding: 14px 16px;
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            background: #f0fdf4;
            color: #166534;
            line-height: 1.55;
        }

        .request-status-note {
            font-size: 12px;
            color: #64748b;
            line-height: 1.45;
        }

        .candidate-position-card {
            height: 100%;
            padding: 15px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fff;
        }

        .candidate-position-title {
            margin-bottom: 10px;
            font-weight: 800;
            color: #1f2937;
        }

        .candidate-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .candidate-row .form-control {
            flex: 1 1 auto;
        }

        .candidate-summary {
            min-width: 300px;
            white-space: normal;
            line-height: 1.45;
        }

        .candidate-summary strong {
            display: inline-block;
            min-width: 125px;
        }

        html.dark .candidate-position-card {
            border-color: var(--admin-border) !important;
            background: var(--admin-surface-2) !important;
        }

        html.dark .candidate-position-title {
            color: var(--admin-text) !important;
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
            font-size: 12px;
            font-weight: 800;
            text-decoration: none !important;
        }

        html.dark .request-card,
        html.dark .history-card {
            background: var(--admin-surface) !important;
            color: var(--admin-text) !important;
        }

        html.dark .request-note {
            border-color: rgba(74, 222, 128, .25);
            background: rgba(22, 101, 52, .18);
            color: #bbf7d0;
        }

        html.dark .request-status-note {
            color: var(--admin-muted) !important;
        }

        html.dark .admin-page-logout-btn {
            border-color: rgba(248, 113, 113, .30);
            background: rgba(127, 29, 29, .16);
            color: #fca5a5 !important;
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
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <div class="menu-icon dw dw-menu"></div>
        </div>

        <div class="header-right">
            <div class="admin-theme-switch">
                <button type="button" id="themeToggle" class="admin-theme-toggle" aria-label="Switch theme" title="Switch theme">
                    <span id="themeIcon">☾</span>
                </button>
            </div>

            <a href="logout.php" class="admin-page-logout-btn" title="Log out" aria-label="Log out">
                <i class="dw dw-logout" aria-hidden="true"></i>
                <span>Log Out</span>
            </a>
        </div>
    </div>

    <?php include 'sidebar.php'; ?>
    <div class="mobile-menu-overlay"></div>

    <div class="main-container">
        <div class="pd-ltr-20">
            <div class="page-title-wrap">
                <h2 class="h4 mb-1">Voting Request</h2>
                <div class="text-muted">
                    <?= esc($phase) ?> • President: <?= esc($presidentName) ?>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= esc($success) ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= esc($error) ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!$tableReady): ?>
                <div class="alert alert-warning">
                    Voting Request is not ready yet. The system administrator must run <strong>voting_requests.sql</strong> once.
                </div>
            <?php endif; ?>

            <div class="card-box pd-20 mb-30 request-card">
                <div class="d-flex justify-content-between align-items-start flex-wrap mb-3">
                    <div>
                        <h5 class="mb-1">Request an Election</h5>
                        <div class="text-muted">The Super Administrator will review this request and prepare the voting session.</div>
                    </div>
                </div>

                <div class="request-note mb-4">
                    You are requesting voting for <strong><?= esc($phase) ?></strong>. You do not need to select the phase manually.
                    Select the proposed candidates below. After the Super Administrator processes the request, those candidates will be added to the draft election automatically. The Super Administrator can still review the nominees before voting starts.
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">

                    <div class="form-group">
                        <label><strong>Election Title</strong></label>
                        <input
                            type="text"
                            name="election_title"
                            class="form-control"
                            maxlength="255"
                            placeholder="Example: Phase 1 HOA Election 2026"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label><strong>Reason for Request</strong></label>
                        <textarea
                            name="reason"
                            class="form-control"
                            rows="4"
                            maxlength="500"
                            placeholder="Briefly explain why the voting should be conducted."
                            required
                        ></textarea>
                        <small class="form-text text-muted">Keep the explanation short and clear.</small>
                    </div>

                    <hr class="my-4">

                    <div class="mb-3">
                        <h5 class="mb-1">Proposed Candidates</h5>
                        <div class="text-muted">
                            Select at least one approved homeowner for every position.
                            Use <strong>Add Another Candidate</strong> when more than one person is running for the same position.
                        </div>
                    </div>

                    <div class="row">
                        <?php foreach ($positions as $candidatePosition): ?>
                            <div class="col-lg-6 mb-3">
                                <div class="candidate-position-card">
                                    <div class="candidate-position-title">
                                        <?= esc($candidatePosition) ?>
                                    </div>

                                    <div
                                        class="candidate-list"
                                        data-position="<?= esc($candidatePosition) ?>"
                                    >
                                        <div class="candidate-row">
                                            <select
                                                name="candidates[<?= esc($candidatePosition) ?>][]"
                                                class="form-control candidate-select"
                                                required
                                            >
                                                <option value="">-- Select Candidate --</option>
                                                <?php foreach ($approvedHomeowners as $homeowner): ?>
                                                    <option value="<?= (int)$homeowner['id'] ?>">
                                                        <?= esc(homeownerFullName($homeowner)) ?>
                                                        <?php if (!empty($homeowner['public_id'])): ?>
                                                            - <?= esc($homeowner['public_id']) ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($homeowner['house_lot_number'])): ?>
                                                            - <?= esc($homeowner['house_lot_number']) ?>
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-success add-candidate-btn"
                                        data-position="<?= esc($candidatePosition) ?>"
                                    >
                                        + Add Another Candidate
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" name="submit_voting_request" class="btn btn-success">
                        Submit Voting Request with Candidates
                    </button>
                </form>
            </div>

            <div class="card-box pd-20 mb-30 history-card">
                <h5 class="mb-3">Request Status</h5>

                <?php if (!$tableReady): ?>
                    <div class="text-muted">No request status is available until Voting Request setup is installed.</div>
                <?php elseif (!$requests): ?>
                    <div class="alert alert-info mb-0">No voting request has been submitted for <?= esc($phase) ?> yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Requested</th>
                                    <th>Election</th>
                                    <th>Reason</th>
                                    <th>Candidates Sent</th>
                                    <th>Status</th>
                                    <th>Superadmin Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $request): ?>
                                    <tr>
                                        <td><?= esc($request['created_at'] ?? '-') ?></td>
                                        <td><strong><?= esc($request['election_title'] ?? '-') ?></strong></td>
                                        <td style="min-width:250px;white-space:normal;"><?= nl2br(esc($request['reason'] ?? '')) ?></td>
                                        <td class="candidate-summary">
                                            <?php
                                            $sentCandidates = $requestCandidates[(int)$request['id']] ?? [];
                                            ?>
                                            <?php if (!$sentCandidates): ?>
                                                <span class="text-muted">No candidate list recorded.</span>
                                            <?php else: ?>
                                                <?php foreach ($positions as $candidatePosition): ?>
                                                    <?php $candidateRows = $sentCandidates[$candidatePosition] ?? []; ?>
                                                    <?php if ($candidateRows): ?>
                                                        <div class="mb-1">
                                                            <strong><?= esc($candidatePosition) ?>:</strong>
                                                            <?php
                                                            $names = [];
                                                            foreach ($candidateRows as $candidateRow) {
                                                                $names[] = homeownerFullName($candidateRow);
                                                            }
                                                            ?>
                                                            <?= esc(implode(', ', $names)) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?= requestBadgeClass((string)($request['status'] ?? 'pending')) ?>">
                                                <?= esc(requestStatusLabel((string)($request['status'] ?? 'pending'))) ?>
                                            </span>

                                            <?php if (($request['status'] ?? '') === 'pending'): ?>
                                                <div class="request-status-note mt-1">Waiting for Superadmin review.</div>
                                            <?php elseif (($request['status'] ?? '') === 'processed'): ?>
                                                <div class="request-status-note mt-1">
                                                    The request was accepted. The Super Administrator is preparing the election.
                                                </div>
                                            <?php else: ?>
                                                <div class="request-status-note mt-1">The request was not approved.</div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="min-width:240px;white-space:normal;">
                                            <?= esc($request['superadmin_remarks'] ?: 'No remarks yet.') ?>
                                            <?php if (!empty($request['processed_at'])): ?>
                                                <div class="request-status-note mt-1">Updated: <?= esc($request['processed_at']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="footer-wrap pd-20 mb-20 card-box">
                © Copyright South Meridian Homes All Rights Reserved
            </div>
        </div>
    </div>

    <script src="vendors/scripts/core.js"></script>
    <script src="vendors/scripts/script.min.js"></script>
    <script src="vendors/scripts/process.js"></script>
    <script src="vendors/scripts/layout-settings.js"></script>
    <script src="vendors/scripts/admin_theme.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.add-candidate-btn').forEach(function (button) {
            button.addEventListener('click', function () {
                const position = button.getAttribute('data-position');
                const list = document.querySelector(
                    '.candidate-list[data-position="' +
                    CSS.escape(position) +
                    '"]'
                );

                if (!list) {
                    return;
                }

                const firstRow = list.querySelector('.candidate-row');
                if (!firstRow) {
                    return;
                }

                const newRow = firstRow.cloneNode(true);
                const select = newRow.querySelector('select');

                if (select) {
                    select.value = '';
                    select.removeAttribute('required');
                }

                if (!newRow.querySelector('.remove-candidate-btn')) {
                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'btn btn-sm btn-outline-danger remove-candidate-btn';
                    removeButton.textContent = 'Remove';

                    removeButton.addEventListener('click', function () {
                        newRow.remove();
                    });

                    newRow.appendChild(removeButton);
                }

                list.appendChild(newRow);
            });
        });
    });
    </script>

</body>
</html>
