<?php
session_start();
header('Content-Type: application/json');

if (
    !isset($_SESSION['admin_id'], $_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)
) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
$conn->set_charset("utf8mb4");

$phase = trim((string)($_GET['phase'] ?? ''));
$allowed = ['Phase 1','Phase 2','Phase 3'];

if (!in_array($phase, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid phase']);
    exit;
}

$stmt = $conn->prepare("
    SELECT id
    FROM election_sessions
    WHERE phase=? AND status='finished'
    ORDER BY ended_at DESC, id DESC
    LIMIT 1
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$election = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$election) {
    echo json_encode(['success' => false, 'message' => 'No finished election yet.']);
    exit;
}

$electionId = (int)$election['id'];

$positions = ['President','Vice President','Secretary','Treasurer','Auditor','Board of Director'];
$out = [];

foreach ($positions as $position) {
    $stmt = $conn->prepare("
        SELECT
            CONCAT(h.first_name, ' ', h.last_name) AS nominee_name,
            COUNT(v.id) AS total_votes
        FROM election_nominations n
        INNER JOIN homeowners h ON h.id = n.homeowner_id
        LEFT JOIN election_votes v
            ON v.election_id = n.election_id
           AND v.nominee_homeowner_id = n.homeowner_id
           AND v.position = n.position
        WHERE n.election_id=? AND n.position=?
        GROUP BY n.homeowner_id, h.first_name, h.last_name
        ORDER BY total_votes DESC, nominee_name ASC
    ");
    $stmt->bind_param("is", $electionId, $position);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($rows) {
        $out[] = [
            'position' => $position,
            'rows' => $rows
        ];
    }
}

echo json_encode(['success' => true, 'data' => $out]);