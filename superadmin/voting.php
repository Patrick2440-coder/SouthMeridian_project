<?php
session_start();

// OPTIONAL: enforce superadmin
// if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
//     header("Location: ../index.php");
//     exit;
// }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

function esc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function nfmt($value): string {
    return number_format((float)$value, 0);
}
function badgeClass(string $status): string {
    return match ($status) {
        'active'   => 'badge-soft badge-soft-success',
        'finished' => 'badge-soft badge-soft-secondary',
        default    => 'badge-soft badge-soft-warning',
    };
}
function statusLabel(string $status): string {
    return match ($status) {
        'active'   => 'Live',
        'finished' => 'Done',
        default    => 'Not Started',
    };
}
function fullName(array $r): string {
    return trim(
        ($r['first_name'] ?? '') . ' ' .
        (!empty($r['middle_name']) ? $r['middle_name'] . ' ' : '') .
        ($r['last_name'] ?? '')
    );
}
function getWinnerLabel(string $position): string {
    return $position === 'Board of Director' ? 'Top 6' : 'Winner';
}
function logActivity(mysqli $conn, ?int $adminId, ?string $phase, string $action, ?string $moduleKey = null, ?string $details = null): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (admin_id, phase, action, module_key, details, ip_address)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("isssss", $adminId, $phase, $action, $moduleKey, $details, $ip);
    $stmt->execute();
    $stmt->close();
}
function parsePublishedSessionId(?string $details): int {
    if (!$details) return 0;
    if (preg_match('/session_id=(\d+)/', $details, $m)) {
        return (int)$m[1];
    }
    return 0;
}
function getPublishedSessionMeta(mysqli $conn): array {
    $publishedMap = [];

    $sql = "
        SELECT id, phase, action, details, created_at
        FROM activity_logs
        WHERE module_key = 'voting_management'
          AND action = 'publish_results'
        ORDER BY id DESC
    ";
    $res = $conn->query($sql);

    while ($row = $res->fetch_assoc()) {
        $sessionId = parsePublishedSessionId($row['details'] ?? '');
        if ($sessionId > 0 && !isset($publishedMap[$sessionId])) {
            $publishedMap[$sessionId] = [
                'published_at' => $row['created_at'] ?? null,
                'log_id'       => (int)$row['id'],
                'phase'        => $row['phase'] ?? null,
                'details'      => $row['details'] ?? '',
            ];
        }
    }
    $res->close();

    return $publishedMap;
}
function isSessionPublished(int $sessionId, array $publishedMeta): bool {
    return isset($publishedMeta[$sessionId]);
}
function getSessionRankings(mysqli $conn, int $sessionId): array {
    $positions = ['President', 'Vice President', 'Secretary', 'Treasurer', 'Auditor', 'Board of Director'];
    $rankings = [];
    foreach ($positions as $position) {
        $rankings[$position] = [];
    }

    $stmt = $conn->prepare("
        SELECT
            en.id AS nomination_id,
            en.position,
            en.homeowner_id,
            h.first_name,
            h.middle_name,
            h.last_name,
            h.email,
            h.house_lot_number,
            COUNT(ev.id) AS total_votes
        FROM election_nominations en
        INNER JOIN homeowners h ON h.id = en.homeowner_id
        LEFT JOIN election_votes ev
            ON ev.election_id = en.election_id
           AND ev.position = en.position
           AND ev.nominee_homeowner_id = en.homeowner_id
        WHERE en.election_id = ?
        GROUP BY en.id, en.position, en.homeowner_id, h.first_name, h.middle_name, h.last_name, h.email, h.house_lot_number
        ORDER BY
            FIELD(en.position,'President','Vice President','Secretary','Treasurer','Auditor','Board of Director'),
            total_votes DESC,
            h.first_name ASC,
            h.last_name ASC,
            h.id ASC
    ");
    $stmt->bind_param("i", $sessionId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $rankings[$row['position']][] = $row;
    }
    $stmt->close();

    return $rankings;
}
function getPublishWinners(array $rankings): array {
    $positions = ['President', 'Vice President', 'Secretary', 'Treasurer', 'Auditor', 'Board of Director'];
    $winners = [];

    foreach ($positions as $position) {
        $rows = $rankings[$position] ?? [];

        if ($position === 'Board of Director') {
            $winners[$position] = array_slice($rows, 0, 6);
        } else {
            $winners[$position] = !empty($rows) ? [$rows[0]] : [];
        }
    }

    return $winners;
}
function sendOfficerWinnerEmail(string $recipientEmail, string $winnerName, string $position, string $phase, string $sessionTitle): bool {
    $reportDate = date('F j, Y', strtotime('+1 day'));

    $safeName        = htmlspecialchars($winnerName, ENT_QUOTES, 'UTF-8');
    $safePosition    = htmlspecialchars($position, ENT_QUOTES, 'UTF-8');
    $safePhase       = htmlspecialchars($phase, ENT_QUOTES, 'UTF-8');
    $safeSession     = htmlspecialchars($sessionTitle, ENT_QUOTES, 'UTF-8');
    $safeReportDate  = htmlspecialchars($reportDate, ENT_QUOTES, 'UTF-8');

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'baculpopatrick2440@gmail.com';
        $mail->Password   = 'vxsx lmtv livx hgtl';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('baculpopatrick2440@gmail.com', 'South Meridian HOA');
        $mail->addAddress($recipientEmail);
        $mail->isHTML(true);

        $mail->Subject = 'Election Result Notice - You Won the Election';

        $mail->Body = "
            <div style='font-family:Arial,sans-serif;color:#222;line-height:1.6;'>
                <h3 style='margin-bottom:10px;'>Congratulations, {$safeName}!</h3>

                <p>We are pleased to inform you that you have <strong>won the election</strong> for the position of
                <strong>{$safePosition}</strong> in <strong>{$safePhase}</strong>.</p>

                <p><strong>Election Session:</strong> {$safeSession}</p>

                <p>You are hereby required to go to the HOA office on <strong>{$safeReportDate}</strong>,
                which is the day after this email was sent, for further instructions and confirmation of your appointment.</p>

                <p><strong>Details:</strong></p>
                <ul>
                    <li><strong>Name:</strong> {$safeName}</li>
                    <li><strong>Position Won:</strong> {$safePosition}</li>
                    <li><strong>Phase:</strong> {$safePhase}</li>
                    <li><strong>Report Date:</strong> {$safeReportDate}</li>
                </ul>

                <p>Please make sure to report on time.</p>

                <br>
                <p>— South Meridian HOA</p>
            </div>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

$positions = ['President', 'Vice President', 'Secretary', 'Treasurer', 'Auditor', 'Board of Director'];
$phaseOptions = ['Phase 1', 'Phase 2', 'Phase 3'];

$success = '';
$error = '';
$adminId = (int)($_SESSION['admin_id'] ?? 1);

/* =========================
   PAGE MODE
   ========================= */
$viewMode = $_GET['view'] ?? 'management';
if (!in_array($viewMode, ['management', 'results', 'history'], true)) {
    $viewMode = 'management';
}

/* =========================
   PUBLISHED META
   ========================= */
$publishedMeta = getPublishedSessionMeta($conn);

/* =========================
   POST ACTIONS
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['create_session'])) {
        $phase = trim($_POST['phase'] ?? '');
        $title = trim($_POST['title'] ?? '');

        if (!in_array($phase, $phaseOptions, true)) {
            $error = 'Invalid phase selected.';
        } elseif ($title === '') {
            $error = 'Election title is required.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO election_sessions (phase, title, status, created_by_admin_id)
                VALUES (?, ?, 'draft', ?)
            ");
            $stmt->bind_param("ssi", $phase, $title, $adminId);
            $stmt->execute();
            $stmt->close();
            $success = 'Election session created successfully.';
        }
    }

    if (isset($_POST['change_status'])) {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? '');

        if ($sessionId <= 0 || !in_array($newStatus, ['draft', 'active', 'finished'], true)) {
            $error = 'Invalid session status update.';
        } else {
            $stmt = $conn->prepare("SELECT id, phase, status FROM election_sessions WHERE id=? LIMIT 1");
            $stmt->bind_param("i", $sessionId);
            $stmt->execute();
            $sessionRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$sessionRow) {
                $error = 'Election session not found.';
            } elseif (isSessionPublished($sessionId, $publishedMeta)) {
                $error = 'Published sessions can no longer be changed.';
            } else {
                $phase = $sessionRow['phase'];

                $conn->begin_transaction();
                try {
                    if ($newStatus === 'active') {
                        $stmt = $conn->prepare("
                            UPDATE election_sessions
                            SET status='draft', ended_at=NULL
                            WHERE phase=? AND status='active' AND id<>?
                        ");
                        $stmt->bind_param("si", $phase, $sessionId);
                        $stmt->execute();
                        $stmt->close();

                        $stmt = $conn->prepare("
                            UPDATE election_sessions
                            SET status='active',
                                started_at = IF(started_at IS NULL, NOW(), started_at),
                                ended_at = NULL
                            WHERE id=?
                        ");
                        $stmt->bind_param("i", $sessionId);
                        $stmt->execute();
                        $stmt->close();
                    } elseif ($newStatus === 'finished') {
                        $stmt = $conn->prepare("
                            UPDATE election_sessions
                            SET status='finished', ended_at=NOW()
                            WHERE id=?
                        ");
                        $stmt->bind_param("i", $sessionId);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE election_sessions
                            SET status='draft', ended_at=NULL
                            WHERE id=?
                        ");
                        $stmt->bind_param("i", $sessionId);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $conn->commit();
                    $success = 'Election session status updated successfully.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = 'Failed to update session status.';
                }
            }
        }
    }

    if (isset($_POST['delete_session'])) {
        $sessionId = (int)($_POST['session_id'] ?? 0);

        if ($sessionId <= 0) {
            $error = 'Invalid election session.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM election_sessions WHERE id=? LIMIT 1");
            $stmt->bind_param("i", $sessionId);
            $stmt->execute();
            $sessionRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$sessionRow) {
                $error = 'Election session not found.';
            } elseif (isSessionPublished($sessionId, $publishedMeta)) {
                $error = 'Published voting sessions cannot be deleted from here because they are already in Voting History.';
            } else {
                $stmt = $conn->prepare("DELETE FROM election_sessions WHERE id=?");
                $stmt->bind_param("i", $sessionId);
                $stmt->execute();
                $stmt->close();
                $success = 'Election session deleted successfully.';
            }
        }
    }

    if (isset($_POST['add_nominee'])) {
        $sessionId   = (int)($_POST['session_id'] ?? 0);
        $position    = trim($_POST['position'] ?? '');
        $homeownerId = (int)($_POST['homeowner_id'] ?? 0);

        if ($sessionId <= 0 || $homeownerId <= 0 || !in_array($position, $positions, true)) {
            $error = 'Please select valid nominee details.';
        } else {
            $stmt = $conn->prepare("
                SELECT id, phase, status
                FROM election_sessions
                WHERE id=?
                LIMIT 1
            ");
            $stmt->bind_param("i", $sessionId);
            $stmt->execute();
            $sessionRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$sessionRow) {
                $error = 'Election session not found.';
            } elseif (isSessionPublished($sessionId, $publishedMeta)) {
                $error = 'You cannot add nominees to a published session.';
            } else {
                $sessionPhase = $sessionRow['phase'];

                $stmt = $conn->prepare("
                    SELECT id, first_name, middle_name, last_name, status, phase
                    FROM homeowners
                    WHERE id=? AND phase=? AND status='approved'
                    LIMIT 1
                ");
                $stmt->bind_param("is", $homeownerId, $sessionPhase);
                $stmt->execute();
                $homeownerRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$homeownerRow) {
                    $error = 'Selected homeowner must be approved and from the same phase.';
                } else {
                    $stmt = $conn->prepare("
                        SELECT id
                        FROM election_nominations
                        WHERE election_id=? AND position=? AND homeowner_id=?
                        LIMIT 1
                    ");
                    $stmt->bind_param("isi", $sessionId, $position, $homeownerId);
                    $stmt->execute();
                    $exists = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($exists) {
                        $error = 'This homeowner is already nominated for that position in this session.';
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO election_nominations
                            (election_id, phase, position, homeowner_id, created_by_admin_id)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->bind_param("issii", $sessionId, $sessionPhase, $position, $homeownerId, $adminId);
                        $stmt->execute();
                        $stmt->close();
                        $success = 'Nominee added successfully.';
                    }
                }
            }
        }
    }

    if (isset($_POST['delete_nominee'])) {
        $nominationId = (int)($_POST['nomination_id'] ?? 0);

        if ($nominationId <= 0) {
            $error = 'Invalid nominee.';
        } else {
            $stmt = $conn->prepare("
                SELECT en.election_id
                FROM election_nominations en
                WHERE en.id=?
                LIMIT 1
            ");
            $stmt->bind_param("i", $nominationId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                $error = 'Nominee not found.';
            } elseif (isSessionPublished((int)$row['election_id'], $publishedMeta)) {
                $error = 'You cannot remove nominees from a published session.';
            } else {
                $stmt = $conn->prepare("DELETE FROM election_nominations WHERE id=?");
                $stmt->bind_param("i", $nominationId);
                $stmt->execute();
                $stmt->close();
                $success = 'Nominee removed successfully.';
            }
        }
    }

    if (isset($_POST['publish_results'])) {
        $sessionId = (int)($_POST['session_id'] ?? 0);

        if ($sessionId <= 0) {
            $error = 'Invalid election session.';
        } else {
            $stmt = $conn->prepare("
                SELECT id, phase, title, status
                FROM election_sessions
                WHERE id=?
                LIMIT 1
            ");
            $stmt->bind_param("i", $sessionId);
            $stmt->execute();
            $publishSession = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$publishSession) {
                $error = 'Election session not found.';
            } elseif (($publishSession['status'] ?? '') !== 'finished') {
                $error = 'Only finished sessions can be published.';
            } elseif (isSessionPublished($sessionId, $publishedMeta)) {
                $error = 'This voting session has already been published.';
            } else {
                $rankings = getSessionRankings($conn, $sessionId);
                $publishWinners = getPublishWinners($rankings);

                $hasPublishableWinner = false;
                foreach ($publishWinners as $winnerRows) {
                    if (!empty($winnerRows)) {
                        $hasPublishableWinner = true;
                        break;
                    }
                }

                if (!$hasPublishableWinner) {
                    $error = 'No winners found to publish.';
                } else {
                    $emailQueue = [];

                    $conn->begin_transaction();
                    try {
                        $stmt = $conn->prepare("DELETE FROM hoa_officers WHERE phase=?");
                        $stmt->bind_param("s", $publishSession['phase']);
                        $stmt->execute();
                        $stmt->close();

                        $stmt = $conn->prepare("
                            INSERT INTO hoa_officers
                            (phase, position, officer_name, officer_email, is_active, updated_at)
                            VALUES (?, ?, ?, ?, 1, NOW())
                        ");

                        foreach ($positions as $position) {
                            $winnerRows = $publishWinners[$position] ?? [];
                            foreach ($winnerRows as $winner) {
                                $phase = $publishSession['phase'];
                                $officerName = fullName($winner);
                                $officerEmail = trim((string)($winner['email'] ?? ''));

                                $stmt->bind_param(
                                    "ssss",
                                    $phase,
                                    $position,
                                    $officerName,
                                    $officerEmail
                                );
                                $stmt->execute();

                                if ($officerEmail !== '') {
                                    $emailQueue[] = [
                                        'email'    => $officerEmail,
                                        'name'     => $officerName,
                                        'position' => $position,
                                        'phase'    => $publishSession['phase'],
                                        'title'    => $publishSession['title'] ?? ''
                                    ];
                                }
                            }
                        }
                        $stmt->close();

                        $details = 'session_id=' . $sessionId .
                                   ';title=' . ($publishSession['title'] ?? '') .
                                   ';phase=' . ($publishSession['phase'] ?? '');

                        logActivity(
                            $conn,
                            $adminId,
                            $publishSession['phase'],
                            'publish_results',
                            'voting_management',
                            $details
                        );

                        $conn->commit();

                        $emailSentCount = 0;
                        $emailFailedCount = 0;

                        foreach ($emailQueue as $emailItem) {
                            $sent = sendOfficerWinnerEmail(
                                $emailItem['email'],
                                $emailItem['name'],
                                $emailItem['position'],
                                $emailItem['phase'],
                                $emailItem['title']
                            );

                            if ($sent) {
                                $emailSentCount++;
                            } else {
                                $emailFailedCount++;
                            }
                        }

                        if ($emailFailedCount > 0) {
                            $success = 'Voting results published successfully. Current officers were updated and moved to Voting History. '
                                     . $emailSentCount . ' winner email(s) sent, '
                                     . $emailFailedCount . ' email(s) failed.';
                        } else {
                            $success = 'Voting results published successfully. Current officers were updated, moved to Voting History, and winner email notifications were sent.';
                        }

                        $publishedMeta = getPublishedSessionMeta($conn);
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $error = 'Failed to publish results to hoa_officers.';
                    }
                }
            }
        }
    }
}

/* =========================
   COUNTS
   ========================= */
$totalSessions = 0;
$totalNomineesAll = 0;
$totalVotesAll = 0;
$totalFinishedSessions = 0;
$totalHistorySessions = 0;

$r = $conn->query("SELECT COUNT(*) c FROM election_sessions WHERE status IN ('draft','active')");
$totalSessions = (int)($r->fetch_assoc()['c'] ?? 0);
$r->close();

$r = $conn->query("SELECT COUNT(*) c FROM election_sessions WHERE status='finished'");
$allFinishedSessions = (int)($r->fetch_assoc()['c'] ?? 0);
$r->close();

$totalHistorySessions = count($publishedMeta);
$totalFinishedSessions = max(0, $allFinishedSessions - $totalHistorySessions);

$r = $conn->query("SELECT COUNT(*) c FROM election_nominations");
$totalNomineesAll = (int)($r->fetch_assoc()['c'] ?? 0);
$r->close();

$r = $conn->query("SELECT COUNT(*) c FROM election_votes");
$totalVotesAll = (int)($r->fetch_assoc()['c'] ?? 0);
$r->close();

/* =========================
   FETCH SESSIONS
   ========================= */
$allSessions = [];
$sqlSessions = "
    SELECT
        es.id,
        es.phase,
        es.title,
        es.status,
        es.started_at,
        es.ended_at,
        es.created_at,
        COUNT(DISTINCT en.id) AS nominee_count,
        COUNT(DISTINCT ev.id) AS vote_count
    FROM election_sessions es
    LEFT JOIN election_nominations en ON en.election_id = es.id
    LEFT JOIN election_votes ev ON ev.election_id = es.id
    GROUP BY es.id, es.phase, es.title, es.status, es.started_at, es.ended_at, es.created_at
    ORDER BY es.id DESC
";
$resSessions = $conn->query($sqlSessions);
while ($row = $resSessions->fetch_assoc()) {
    $row['is_published'] = isSessionPublished((int)$row['id'], $publishedMeta) ? 1 : 0;
    $row['published_at'] = $publishedMeta[(int)$row['id']]['published_at'] ?? null;
    $allSessions[] = $row;
}
$resSessions->close();

$electionSessions = array_values(array_filter($allSessions, function ($row) {
    return (int)($row['is_published'] ?? 0) === 0;
}));

$finishedSessions = array_values(array_filter($allSessions, function ($row) {
    return ($row['status'] ?? '') === 'finished' && (int)($row['is_published'] ?? 0) === 0;
}));

$historySessions = array_values(array_filter($allSessions, function ($row) {
    return (int)($row['is_published'] ?? 0) === 1;
}));

usort($historySessions, function ($a, $b) {
    $aTime = strtotime((string)($a['published_at'] ?? ''));
    $bTime = strtotime((string)($b['published_at'] ?? ''));
    return $bTime <=> $aTime;
});

/* =========================
   SELECTED SESSION
   ========================= */
$selectedSessionId = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;

if ($viewMode === 'results') {
    if ($selectedSessionId <= 0 && !empty($finishedSessions)) {
        $selectedSessionId = (int)$finishedSessions[0]['id'];
    }
} elseif ($viewMode === 'history') {
    if ($selectedSessionId <= 0 && !empty($historySessions)) {
        $selectedSessionId = (int)$historySessions[0]['id'];
    }
} else {
    if ($selectedSessionId <= 0 && !empty($electionSessions)) {
        $selectedSessionId = (int)$electionSessions[0]['id'];
    }
}

$selectedSession = null;

if ($viewMode === 'results') {
    $sourceSessions = $finishedSessions;
} elseif ($viewMode === 'history') {
    $sourceSessions = $historySessions;
} else {
    $sourceSessions = $electionSessions;
}

foreach ($sourceSessions as $session) {
    if ((int)$session['id'] === $selectedSessionId) {
        $selectedSession = $session;
        break;
    }
}

if (!$selectedSession && !empty($sourceSessions)) {
    $selectedSession = $sourceSessions[0];
    $selectedSessionId = (int)$selectedSession['id'];
}

/* =========================
   APPROVED HOMEOWNERS OF SELECTED PHASE
   ========================= */
$approvedHomeowners = [];
if ($selectedSession && $viewMode === 'management') {
    $stmt = $conn->prepare("
        SELECT id, first_name, middle_name, last_name, house_lot_number
        FROM homeowners
        WHERE phase=? AND status='approved'
        ORDER BY first_name ASC, last_name ASC
    ");
    $stmt->bind_param("s", $selectedSession['phase']);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $approvedHomeowners[] = $row;
    }
    $stmt->close();
}

/* =========================
   NOMINEES + RESULTS
   ========================= */
$nomineesByPosition = [];
foreach ($positions as $position) {
    $nomineesByPosition[$position] = [];
}

if ($selectedSessionId > 0) {
    $rankings = getSessionRankings($conn, $selectedSessionId);
    foreach ($positions as $position) {
        $nomineesByPosition[$position] = $rankings[$position] ?? [];
    }
}

$selectedNominees = 0;
$selectedVotes = 0;
if ($selectedSession) {
    foreach ($nomineesByPosition as $rows) {
        $selectedNominees += count($rows);
    }
    $selectedVotes = (int)$selectedSession['vote_count'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Voting Management</title>

    <link rel="apple-touch-icon" sizes="180x180" href="../admin/vendors/images/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="../admin/vendors/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../admin/vendors/images/favicon-16x16.png">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/core.css">
    <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/icon-font.min.css">
    <link rel="stylesheet" type="text/css" href="../admin/src/plugins/datatables/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="../admin/src/plugins/datatables/css/responsive.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/style.css">

    <style>
        body{
            background:#f6f8fb;
        }
        .page-header,
        .card-box{
            border-radius:18px;
            box-shadow:0 8px 24px rgba(15,23,42,.06);
            border:1px solid #eef2f7;
        }
        .page-header{
            background:linear-gradient(135deg,#ffffff 0%,#f8fafc 100%);
        }
        .title h4{
            font-weight:800;
            color:#12284c;
            margin-bottom:6px;
        }

        .badge-soft{
            padding:.42rem .75rem;
            border-radius:999px;
            font-weight:800;
            font-size:12px;
            display:inline-block;
        }
        .badge-soft-warning{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}
        .badge-soft-success{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534}
        .badge-soft-info{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8}
        .badge-soft-secondary{background:#f1f5f9;border:1px solid #cbd5e1;color:#475569}
        .badge-soft-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}

        .kpi-card{
            border-radius:18px;
            overflow:hidden;
            background:#fff;
            position:relative;
        }
        .kpi-card:before{
            content:"";
            position:absolute;
            left:0;
            top:0;
            width:100%;
            height:4px;
            background:#077f46;
        }
        .kpi-card .icon{
            font-size:28px;
            opacity:.95;
            width:52px;
            height:52px;
            border-radius:14px;
            display:flex;
            align-items:center;
            justify-content:center;
            background:#f8fafc;
        }
        .kpi-value{
            font-size:30px;
            font-weight:800;
            color:#0f172a;
            line-height:1.1;
        }
        .kpi-label{
            color:#64748b;
            font-weight:700;
            margin-bottom:6px;
        }

        .position-card{
            border:1px solid #edf2f9;
            border-radius:16px;
            padding:18px;
            margin-bottom:20px;
            background:#fff;
            box-shadow:0 4px 16px rgba(15,23,42,.04);
        }
        .position-title{
            font-size:17px;
            font-weight:800;
            color:#12284c;
            margin-bottom:14px;
            display:flex;
            align-items:center;
            justify-content:space-between;
        }
        .mini-note{
            font-size:12px;
            color:#64748b;
        }
        .nominee-name{
            font-weight:700;
            color:#12284c;
        }

        .sidebar-menu .dropdown-toggle:hover,
        .sidebar-menu .show>.dropdown-toggle{
            background:#077f46;
            color:#fff!important
        }
        .sidebar-menu .submenu li a:hover,
        .sidebar-menu .submenu li a.active{
            background:rgba(7,127,70,.10);
            color:#077f46!important;
            font-weight:700
        }

        .view-switch{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:22px;
        }
        .view-switch a{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:11px 18px;
            border-radius:12px;
            font-weight:800;
            text-decoration:none;
            border:1px solid #dbe2ea;
            color:#12284c;
            background:#fff;
            transition:.2s ease;
            min-width:170px;
        }
        .view-switch a:hover{
            transform:translateY(-1px);
            box-shadow:0 6px 14px rgba(15,23,42,.06);
        }
        .view-switch a.active{
            background:#077f46;
            border-color:#077f46;
            color:#fff;
            box-shadow:0 8px 18px rgba(7,127,70,.25);
        }

        .winner-row{
            background:#f0fdf4 !important;
        }
        .winner-badge{
            display:inline-block;
            margin-left:8px;
            padding:4px 9px;
            border-radius:999px;
            font-size:11px;
            font-weight:800;
            background:#dcfce7;
            color:#166534;
            border:1px solid #bbf7d0;
        }

        .preview-card{
            border:1px solid #e5e7eb;
            border-radius:14px;
            padding:16px;
            margin-bottom:14px;
            background:#fff;
            box-shadow:0 3px 10px rgba(15,23,42,.03);
        }
        .preview-title{
            font-size:15px;
            font-weight:800;
            color:#12284c;
            margin-bottom:10px;
        }

        .table{
            margin-bottom:0;
        }
        .table thead th{
            background:#f8fafc !important;
            color:#334155;
            font-weight:800;
            border-bottom:1px solid #e2e8f0 !important;
            white-space:nowrap;
        }
        .table td{
            vertical-align:middle;
        }

        .form-control{
            border-radius:12px;
            min-height:44px;
            border:1px solid #dbe2ea;
            box-shadow:none!important;
        }
        .form-control:focus{
            border-color:#077f46;
        }

        .btn{
            border-radius:12px;
            font-weight:700;
        }
        .btn-sm{
            border-radius:10px;
            padding:.42rem .8rem;
        }

        .section-title{
            font-size:18px;
            font-weight:800;
            color:#12284c;
            margin-bottom:14px;
        }

        .table-actions{
            display:flex;
            flex-wrap:wrap;
            gap:6px;
        }

        .card-box h5{
            font-weight:800;
            color:#12284c;
        }

        .modal-content{
            border:none;
            border-radius:18px;
            overflow:hidden;
            box-shadow:0 18px 44px rgba(15,23,42,.18);
        }
        .modal-header{
            background:linear-gradient(135deg,#077f46 0%,#0b9b57 100%);
            color:#fff;
            border-bottom:none;
            padding:18px 22px;
        }
        .modal-header .modal-title{
            font-weight:800;
            color:#fff;
        }
        .modal-header .close{
            color:#fff;
            opacity:1;
            text-shadow:none;
        }
        .modal-body{
            background:#f8fafc;
            padding:20px 22px;
        }
        .modal-footer{
            border-top:1px solid #e5e7eb;
            padding:16px 22px;
            background:#fff;
        }

        .alert{
            border-radius:14px;
            border:none;
        }

        .top-note{
            background:#f8fafc;
            border:1px dashed #cbd5e1;
            border-radius:14px;
            padding:12px 14px;
        }

        .subtle-label{
            font-size:12px;
            font-weight:700;
            color:#64748b;
            text-transform:uppercase;
            letter-spacing:.04em;
        }

        @media (max-width: 767px){
            .view-switch a{
                width:100%;
            }
            .table-actions{
                flex-direction:column;
                align-items:stretch;
            }
            .table-actions .btn,
            .table-actions form{
                width:100%;
            }
            .table-actions form button{
                width:100%;
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
        <div class="user-info-dropdown">
            <div class="dropdown">
                <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
                    <span class="user-icon"><img src="../admin/vendors/images/photo1.jpg" alt=""></span>
                    <span class="user-name">Superadmin</span>
                </a>
                <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
                    <a class="dropdown-item" href="../index.php"><i class="dw dw-logout"></i> Log Out</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'superadmin_sidebar.php'; ?>
<div class="mobile-menu-overlay"></div>

<div class="main-container">
    <div class="pd-ltr-20">

        <div class="page-header mb-20 pd-20">
            <div class="row">
                <div class="col-md-12 col-sm-12">
                    <div class="title"><h4>Voting Management</h4></div>
                    <div class="text-secondary">
                        <?php if ($viewMode === 'results'): ?>
                            View completed voting results and publish winners to current officers.
                        <?php elseif ($viewMode === 'history'): ?>
                            View previously published voting sessions.
                        <?php else: ?>
                            Manage election sessions, nominees, and results.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="view-switch">
            <a href="voting.php?view=management" class="<?= $viewMode === 'management' ? 'active' : '' ?>">Voting Management</a>
            <a href="voting.php?view=results" class="<?= $viewMode === 'results' ? 'active' : '' ?>">Voting Results</a>
            <a href="voting.php?view=history" class="<?= $viewMode === 'history' ? 'active' : '' ?>">Voting History</a>
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

        <div class="row">
            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="kpi-label">Open Sessions</div>
                            <div class="kpi-value"><?= nfmt($totalSessions) ?></div>
                        </div>
                        <div class="icon text-primary"><i class="dw dw-list3"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="kpi-label">Ready to Publish</div>
                            <div class="kpi-value"><?= nfmt($totalFinishedSessions) ?></div>
                        </div>
                        <div class="icon text-secondary"><i class="dw dw-check"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="kpi-label">Voting History</div>
                            <div class="kpi-value"><?= nfmt($totalHistorySessions) ?></div>
                        </div>
                        <div class="icon text-success"><i class="dw dw-archive1"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
                <div class="card-box pd-20 kpi-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="kpi-label">
                                <?=
                                    $viewMode === 'history'
                                    ? 'History Votes'
                                    : ($viewMode === 'results' ? 'Selected Result Votes' : 'Selected Session Votes')
                                ?>
                            </div>
                            <div class="kpi-value"><?= nfmt($selectedVotes) ?></div>
                        </div>
                        <div class="icon text-info"><i class="dw dw-analytics-21"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($viewMode === 'management'): ?>
            <div class="row">
                <div class="col-md-5 mb-30">
                    <div class="card-box pd-20">
                        <div class="section-title">Create Election Session</div>
                        <form method="POST">
                            <div class="form-group">
                                <label>Phase</label>
                                <select name="phase" class="form-control" required>
                                    <option value="">-- Select Phase --</option>
                                    <?php foreach ($phaseOptions as $phase): ?>
                                        <option value="<?= esc($phase) ?>"><?= esc($phase) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Election Title</label>
                                <input type="text" name="title" class="form-control" placeholder="Example: Phase 1 HOA Election 2026" required>
                            </div>

                            <button type="submit" name="create_session" class="btn btn-primary">Create Session</button>
                        </form>
                    </div>
                </div>

                <div class="col-md-7 mb-30">
                    <div class="card-box pd-20">
                        <div class="section-title">Add Nominee</div>

                        <?php if ($selectedSession): ?>
                            <form method="POST">
                                <div class="row">
                                    <div class="col-md-4 form-group">
                                        <label>Session</label>
                                        <select name="session_id" class="form-control" required>
                                            <?php foreach ($electionSessions as $session): ?>
                                                <option value="<?= (int)$session['id'] ?>" <?= ((int)$session['id'] === $selectedSessionId) ? 'selected' : '' ?>>
                                                    <?= esc($session['title']) ?> (<?= esc($session['phase']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Position</label>
                                        <select name="position" class="form-control" required>
                                            <option value="">-- Select Position --</option>
                                            <?php foreach ($positions as $position): ?>
                                                <option value="<?= esc($position) ?>"><?= esc($position) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Homeowner</label>
                                        <select name="homeowner_id" class="form-control" required>
                                            <option value="">-- Select Homeowner --</option>
                                            <?php foreach ($approvedHomeowners as $homeowner): ?>
                                                <option value="<?= (int)$homeowner['id'] ?>">
                                                    <?= esc(fullName($homeowner)) ?><?= !empty($homeowner['house_lot_number']) ? ' - ' . esc($homeowner['house_lot_number']) : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <button type="submit" name="add_nominee" class="btn btn-success">Add Nominee</button>
                                <div class="mini-note mt-2">
                                    Only approved homeowners from <strong><?= esc($selectedSession['phase']) ?></strong> are shown.
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">Create or select an election session first.</div>
                        <?php endif; ?>

                        <hr>

                        <div class="section-title mb-3">Select Session to View</div>
                        <form method="GET">
                            <input type="hidden" name="view" value="management">
                            <div class="row">
                                <div class="col-md-10 form-group">
                                    <select name="session_id" class="form-control">
                                        <?php foreach ($electionSessions as $session): ?>
                                            <option value="<?= (int)$session['id'] ?>" <?= ((int)$session['id'] === $selectedSessionId) ? 'selected' : '' ?>>
                                                <?= esc($session['title']) ?> (<?= esc($session['phase']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 form-group">
                                    <button type="submit" class="btn btn-info btn-block">View</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php elseif ($viewMode === 'results'): ?>
            <div class="card-box pd-20 mb-30">
                <div class="section-title">Select Finished Session</div>
                <?php if (!empty($finishedSessions)): ?>
                    <form method="GET">
                        <input type="hidden" name="view" value="results">
                        <div class="row">
                            <div class="col-md-10 form-group mb-0">
                                <select name="session_id" class="form-control">
                                    <?php foreach ($finishedSessions as $session): ?>
                                        <option value="<?= (int)$session['id'] ?>" <?= ((int)$session['id'] === $selectedSessionId) ? 'selected' : '' ?>>
                                            <?= esc($session['title']) ?> (<?= esc($session['phase']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 form-group mb-0">
                                <button type="submit" class="btn btn-primary btn-block">Show</button>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info mb-0">No finished voting sessions waiting to be published.</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card-box pd-20 mb-30">
                <div class="section-title d-flex justify-content-between align-items-center flex-wrap">
                    <span>Select Published Session</span>
                    <?php if ($selectedSession): ?>
                        <a href="voting_history_print.php?session_id=<?= (int)$selectedSession['id'] ?>" target="_blank" class="btn btn-success">
                            <i class="dw dw-print"></i> Print Report
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!empty($historySessions)): ?>
                    <form method="GET">
                        <input type="hidden" name="view" value="history">
                        <div class="row">
                            <div class="col-md-10 form-group mb-0">
                                <select name="session_id" class="form-control">
                                    <?php foreach ($historySessions as $session): ?>
                                        <option value="<?= (int)$session['id'] ?>" <?= ((int)$session['id'] === $selectedSessionId) ? 'selected' : '' ?>>
                                            <?= esc($session['title']) ?> (<?= esc($session['phase']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 form-group mb-0">
                                <button type="submit" class="btn btn-primary btn-block">Show</button>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info mb-0">No published voting history yet.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card-box mb-30 p-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                <h5 class="mb-0">
                    <?php if ($viewMode === 'results'): ?>
                        Election Sessions for Publishing
                    <?php elseif ($viewMode === 'history'): ?>
                        Voting History
                    <?php else: ?>
                        Election Sessions
                    <?php endif; ?>
                </h5>
            </div>

            <div class="table-responsive">
                <table id="sessionsTable" class="table table-striped table-hover">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Phase</th>
                        <th>Title</th>
                        <th>Voting Status</th>
                        <th>Nominees</th>
                        <th>Votes</th>
                        <th>Started</th>
                        <th>Ended</th>
                        <?php if ($viewMode === 'history'): ?>
                            <th>Published At</th>
                        <?php endif; ?>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    if ($viewMode === 'results') {
                        $tableSessions = $finishedSessions;
                    } elseif ($viewMode === 'history') {
                        $tableSessions = $historySessions;
                    } else {
                        $tableSessions = $electionSessions;
                    }
                    ?>
                    <?php if (!empty($tableSessions)): ?>
                        <?php foreach ($tableSessions as $session): ?>
                            <?php
                            $sessionIdModal = (int)$session['id'];
                            $modalRankings = [];
                            $modalWinners = [];

                            if ($viewMode === 'results') {
                                $modalRankings = getSessionRankings($conn, $sessionIdModal);
                                $modalWinners  = getPublishWinners($modalRankings);
                            }
                            ?>
                            <tr>
                                <td><?= (int)$session['id'] ?></td>
                                <td><?= esc($session['phase']) ?></td>
                                <td><?= esc($session['title']) ?></td>
                                <td>
                                    <span class="<?= badgeClass($session['status']) ?>">
                                        <?= esc(statusLabel($session['status'])) ?>
                                    </span>
                                </td>
                                <td><?= (int)$session['nominee_count'] ?></td>
                                <td><?= (int)$session['vote_count'] ?></td>
                                <td><?= esc($session['started_at'] ?: '-') ?></td>
                                <td><?= esc($session['ended_at'] ?: '-') ?></td>
                                <?php if ($viewMode === 'history'): ?>
                                    <td><?= esc($session['published_at'] ?: '-') ?></td>
                                <?php endif; ?>
                                <td>
                                    <div class="table-actions">
                                        <?php if ($viewMode === 'results'): ?>
                                            <a href="voting.php?view=results&session_id=<?= (int)$session['id'] ?>" class="btn btn-sm btn-info">View</a>
                                            <button type="button"
                                                    class="btn btn-sm btn-success"
                                                    data-toggle="modal"
                                                    data-target="#publishModal<?= $sessionIdModal ?>">
                                                Publish
                                            </button>
                                        <?php elseif ($viewMode === 'history'): ?>
                                            <a href="voting.php?view=history&session_id=<?= (int)$session['id'] ?>" class="btn btn-sm btn-info">View</a>
                                            <a href="voting_history_print.php?session_id=<?= (int)$session['id'] ?>" target="_blank" class="btn btn-sm btn-success">Print</a>
                                        <?php else: ?>
                                            <a href="voting.php?view=management&session_id=<?= (int)$session['id'] ?>" class="btn btn-sm btn-info">View</a>

                                            <?php if ($session['status'] === 'draft'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                                                    <input type="hidden" name="new_status" value="active">
                                                    <button type="submit" name="change_status" class="btn btn-sm btn-success">Start</button>
                                                </form>
                                            <?php elseif ($session['status'] === 'active'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                                                    <input type="hidden" name="new_status" value="draft">
                                                    <button type="submit" name="change_status" class="btn btn-sm btn-warning">Set Draft</button>
                                                </form>

                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                                                    <input type="hidden" name="new_status" value="finished">
                                                    <button type="submit" name="change_status" class="btn btn-sm btn-secondary">Finish</button>
                                                </form>
                                            <?php elseif ($session['status'] === 'finished'): ?>
                                                <a href="voting.php?view=results&session_id=<?= (int)$session['id'] ?>" class="btn btn-sm btn-secondary">Results</a>
                                            <?php endif; ?>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this election session? All nominations and votes under it will also be deleted.');">
                                                <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                                                <button type="submit" name="delete_session" class="btn btn-sm btn-danger">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <?php if ($viewMode === 'results'): ?>
                                <div class="modal fade" id="publishModal<?= $sessionIdModal ?>" tabindex="-1" role="dialog" aria-labelledby="publishModalLabel<?= $sessionIdModal ?>" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title" id="publishModalLabel<?= $sessionIdModal ?>">
                                                    Publish Voting Results Preview
                                                </h5>
                                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                    <span aria-hidden="true">&times;</span>
                                                </button>
                                            </div>

                                            <div class="modal-body">
                                                <div class="alert alert-info">
                                                    You are about to publish the winners for
                                                    <strong><?= esc($session['title']) ?></strong>
                                                    (<?= esc($session['phase']) ?>).
                                                    This will replace the current officers in <strong>hoa_officers</strong> for that phase,
                                                    move this session to <strong>Voting History</strong>,
                                                    and send winner email notifications.
                                                </div>

                                                <?php foreach ($positions as $position): ?>
                                                    <div class="preview-card">
                                                        <div class="preview-title"><?= esc($position) ?></div>

                                                        <?php $winnerRows = $modalWinners[$position] ?? []; ?>
                                                        <?php if (!empty($winnerRows)): ?>
                                                            <div class="table-responsive">
                                                                <table class="table table-sm table-bordered mb-0">
                                                                    <thead>
                                                                        <tr>
                                                                            <th width="70">Rank</th>
                                                                            <th>Name</th>
                                                                            <th>House/Lot</th>
                                                                            <th>Email</th>
                                                                            <th width="100">Votes</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php $wRank = 1; ?>
                                                                        <?php foreach ($winnerRows as $winner): ?>
                                                                            <tr>
                                                                                <td><?= $wRank++ ?></td>
                                                                                <td><?= esc(fullName($winner)) ?></td>
                                                                                <td><?= esc($winner['house_lot_number'] ?: '-') ?></td>
                                                                                <td><?= esc($winner['email'] ?: '-') ?></td>
                                                                                <td><?= (int)$winner['total_votes'] ?></td>
                                                                            </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="text-secondary">No winner available for this position.</div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>

                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-light border" data-dismiss="modal">Cancel</button>
                                                <form method="POST" class="mb-0" onsubmit="return confirmPublishResults();">
                                                    <input type="hidden" name="session_id" value="<?= $sessionIdModal ?>">
                                                    <button type="submit" name="publish_results" class="btn btn-success">Confirm Publish</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?= $viewMode === 'history' ? 10 : 9 ?>" class="text-center text-secondary">
                                No election sessions found.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card-box mb-30 p-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                <h5 class="mb-0">
                    <?php if ($selectedSession): ?>
                        <?php if ($viewMode === 'history'): ?>
                            Voting History Details - <?= esc($selectedSession['title']) ?> (<?= esc($selectedSession['phase']) ?>)
                        <?php else: ?>
                            <?= $viewMode === 'results' ? 'Voting Results' : 'Nominees and Results' ?> - <?= esc($selectedSession['title']) ?> (<?= esc($selectedSession['phase']) ?>)
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($viewMode === 'history'): ?>
                            Voting History Details
                        <?php else: ?>
                            <?= $viewMode === 'results' ? 'Voting Results' : 'Nominees and Results' ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </h5>

                <?php if ($viewMode === 'history' && $selectedSession): ?>
                    <a href="voting_history_print.php?session_id=<?= (int)$selectedSession['id'] ?>" target="_blank" class="btn btn-success">
                        <i class="dw dw-print"></i> Print This Report
                    </a>
                <?php endif; ?>
            </div>

            <div class="top-note mini-note mb-3">
                President, Vice President, Secretary, Treasurer, and Auditor = 1 vote each.
                Board of Director = up to 6 votes on homeowner side.
            </div>

            <?php if ($selectedSession): ?>
                <?php foreach ($positions as $position): ?>
                    <div class="position-card">
                        <div class="position-title">
                            <span><?= esc($position) ?></span>
                            <?php if ($viewMode === 'results' || $viewMode === 'history'): ?>
                                <span class="subtle-label"><?= $position === 'Board of Director' ? 'Top 6 Winners' : 'Top Winner' ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                <tr>
                                    <th width="70">Rank</th>
                                    <th>Nominee</th>
                                    <th>House/Lot</th>
                                    <th>Email</th>
                                    <th width="120">Votes</th>
                                    <?php if ($viewMode === 'management'): ?>
                                        <th width="120">Action</th>
                                    <?php endif; ?>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (!empty($nomineesByPosition[$position])): ?>
                                    <?php $rank = 1; ?>
                                    <?php foreach ($nomineesByPosition[$position] as $nominee): ?>
                                        <?php
                                        $isWinner = $position === 'Board of Director' ? ($rank <= 6) : ($rank === 1);
                                        $displayRank = $rank;
                                        $rank++;
                                        ?>
                                        <tr class="<?= (($viewMode === 'results' || $viewMode === 'history') && $isWinner) ? 'winner-row' : '' ?>">
                                            <td><?= $displayRank ?></td>
                                            <td class="nominee-name">
                                                <?= esc(fullName($nominee)) ?>
                                                <?php if (($viewMode === 'results' || $viewMode === 'history') && $isWinner): ?>
                                                    <span class="winner-badge"><?= esc(getWinnerLabel($position)) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= esc($nominee['house_lot_number'] ?: '-') ?></td>
                                            <td><?= esc($nominee['email'] ?: '-') ?></td>
                                            <td><?= (int)$nominee['total_votes'] ?></td>
                                            <?php if ($viewMode === 'management'): ?>
                                                <td>
                                                    <form method="POST" onsubmit="return confirm('Remove this nominee?');">
                                                        <input type="hidden" name="nomination_id" value="<?= (int)$nominee['nomination_id'] ?>">
                                                        <button type="submit" name="delete_nominee" class="btn btn-sm btn-danger">Remove</button>
                                                    </form>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="<?= $viewMode === 'management' ? 6 : 5 ?>" class="text-center text-secondary">
                                            No nominees yet for this position.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-info mb-0">
                    <?php if ($viewMode === 'results'): ?>
                        No finished election session selected yet.
                    <?php elseif ($viewMode === 'history'): ?>
                        No published voting history selected yet.
                    <?php else: ?>
                        No election session selected yet.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="footer-wrap pd-20 mb-20 card-box">
            © Copyright South Meridian Homes All Rights Reserved
        </div>
    </div>
</div>

<script src="../admin/vendors/scripts/core.js"></script>
<script src="../admin/vendors/scripts/script.min.js"></script>
<script src="../admin/vendors/scripts/process.js"></script>
<script src="../admin/vendors/scripts/layout-settings.js"></script>
<script src="../admin/src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="../admin/src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="../admin/src/plugins/datatables/js/dataTables.responsive.min.js"></script>
<script src="../admin/src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

<script>
$(document).ready(function () {
    $('#sessionsTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[0, 'desc']]
    });
});

function confirmPublishResults() {
    return confirm(
        'Are you sure you want to publish these voting results?\n\n' +
        'This will replace the current officers for this phase, move this session to Voting History, and send winner emails.'
    );
}
</script>
</body>
</html>