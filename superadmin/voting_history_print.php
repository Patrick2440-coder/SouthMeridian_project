<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

function esc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function fullName(array $r): string {
    return trim(
        ($r['first_name'] ?? '') . ' ' .
        (!empty($r['middle_name']) ? $r['middle_name'] . ' ' : '') .
        ($r['last_name'] ?? '')
    );
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

$positions = ['President', 'Vice President', 'Secretary', 'Treasurer', 'Auditor', 'Board of Director'];

$sessionId = (int)($_GET['session_id'] ?? 0);
if ($sessionId <= 0) {
    die('Invalid session.');
}

$publishedMeta = getPublishedSessionMeta($conn);

$stmt = $conn->prepare("
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
    WHERE es.id = ?
    GROUP BY es.id, es.phase, es.title, es.status, es.started_at, es.ended_at, es.created_at
    LIMIT 1
");
$stmt->bind_param("i", $sessionId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    die('Voting session not found.');
}

if (!isset($publishedMeta[$sessionId])) {
    die('This voting session is not yet in Voting History.');
}

$publishedAt = $publishedMeta[$sessionId]['published_at'] ?? null;
$rankings = getSessionRankings($conn, $sessionId);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Voting History Report</title>
    <style>
        body{
            font-family: Arial, Helvetica, sans-serif;
            color:#111;
            margin:0;
            background:#f5f5f5;
        }
        .page{
            width: 980px;
            margin: 20px auto;
            background:#fff;
            padding: 30px 40px;
            box-shadow:0 0 10px rgba(0,0,0,.08);
        }
        .top-actions{
            width: 980px;
            margin: 20px auto 0;
            display:flex;
            gap:10px;
            justify-content:flex-end;
        }
        .btn{
            display:inline-block;
            padding:10px 16px;
            text-decoration:none;
            border-radius:6px;
            border:1px solid #333;
            color:#111;
            background:#fff;
            cursor:pointer;
            font-size:14px;
        }
        .btn-primary{
            background:#0b7a43;
            color:#fff;
            border-color:#0b7a43;
        }
        .header{
            text-align:center;
            margin-bottom:25px;
        }
        .header h1{
            font-size:24px;
            margin:0 0 6px;
        }
        .header h2{
            font-size:18px;
            margin:0 0 4px;
            font-weight:normal;
        }
        .header p{
            margin:3px 0;
            font-size:14px;
        }
        .meta{
            margin: 20px 0 25px;
            border:1px solid #ccc;
            padding:15px;
        }
        .meta-row{
            margin-bottom:8px;
            font-size:14px;
        }
        .section{
            margin-top:25px;
        }
        .section h3{
            margin:0 0 10px;
            padding-bottom:6px;
            border-bottom:2px solid #222;
            font-size:18px;
        }
        table{
            width:100%;
            border-collapse:collapse;
            margin-top:10px;
        }
        th, td{
            border:1px solid #777;
            padding:8px 10px;
            font-size:13px;
            text-align:left;
        }
        th{
            background:#efefef;
        }
        .winner{
            background:#eef9f0;
            font-weight:bold;
        }
        .footer-sign{
            margin-top:50px;
            display:flex;
            justify-content:space-between;
            gap:40px;
        }
        .sign-box{
            width:45%;
            text-align:center;
            margin-top:40px;
        }
        .sign-line{
            border-top:1px solid #111;
            margin-top:50px;
            padding-top:8px;
            font-size:14px;
        }

        @media print{
            body{
                background:#fff;
            }
            .top-actions{
                display:none;
            }
            .page{
                width:auto;
                margin:0;
                box-shadow:none;
                padding:0;
            }
            @page{
                size: A4 portrait;
                margin: 16mm;
            }
        }
    </style>
</head>
<body>

<div class="top-actions">
    <button class="btn btn-primary" onclick="window.print()">Print Report</button>
    <a href="voting.php?view=history&session_id=<?= (int)$sessionId ?>" class="btn">Back</a>
</div>

<div class="page">
    <div class="header">
        <h1>South Meridian Homes</h1>
        <h2>Voting History Report</h2>
        <p>Official Election Result Report</p>
    </div>

    <div class="meta">
        <div class="meta-row"><strong>Election Session:</strong> <?= esc($session['title']) ?></div>
        <div class="meta-row"><strong>Phase:</strong> <?= esc($session['phase']) ?></div>
        <div class="meta-row"><strong>Status:</strong> <?= esc($session['status']) ?></div>
        <div class="meta-row"><strong>Started At:</strong> <?= esc($session['started_at'] ?: '-') ?></div>
        <div class="meta-row"><strong>Ended At:</strong> <?= esc($session['ended_at'] ?: '-') ?></div>
        <div class="meta-row"><strong>Published At:</strong> <?= esc($publishedAt ?: '-') ?></div>
        <div class="meta-row"><strong>Total Nominees:</strong> <?= (int)$session['nominee_count'] ?></div>
        <div class="meta-row"><strong>Total Votes:</strong> <?= (int)$session['vote_count'] ?></div>
    </div>

    <?php foreach ($positions as $position): ?>
        <div class="section">
            <h3><?= esc($position) ?></h3>

            <table>
                <thead>
                    <tr>
                        <th style="width:70px;">Rank</th>
                        <th>Nominee</th>
                        <th>House/Lot</th>
                        <th>Email</th>
                        <th style="width:110px;">Votes</th>
                        <th style="width:130px;">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($rankings[$position])): ?>
                        <?php $rank = 1; ?>
                        <?php foreach ($rankings[$position] as $row): ?>
                            <?php
                                $isWinner = $position === 'Board of Director' ? ($rank <= 6) : ($rank === 1);
                            ?>
                            <tr class="<?= $isWinner ? 'winner' : '' ?>">
                                <td><?= $rank ?></td>
                                <td><?= esc(fullName($row)) ?></td>
                                <td><?= esc($row['house_lot_number'] ?: '-') ?></td>
                                <td><?= esc($row['email'] ?: '-') ?></td>
                                <td><?= (int)$row['total_votes'] ?></td>
                                <td><?= $isWinner ? ($position === 'Board of Director' ? 'Top 6 Winner' : 'Winner') : '' ?></td>
                            </tr>
                            <?php $rank++; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align:center;">No nominees found for this position.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>

    <div class="footer-sign">
        <div class="sign-box">
            <div class="sign-line">Prepared by</div>
        </div>
        <div class="sign-box">
            <div class="sign-line">Approved by</div>
        </div>
    </div>
</div>

<script>
window.addEventListener('load', function () {
    // Optional auto open print dialog:
    // window.print();
});
</script>

</body>
</html>