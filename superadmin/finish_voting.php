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

$phase = trim((string)($_POST['phase'] ?? ''));
$allowed = ['Phase 1','Phase 2','Phase 3'];

if (!in_array($phase, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid phase']);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM election_sessions WHERE phase=? AND status='active' ORDER BY id DESC LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'No active voting found for this phase.']);
    exit;
}

$electionId = (int)$row['id'];

$stmt = $conn->prepare("UPDATE election_sessions SET status='finished', ended_at=NOW() WHERE id=?");
$stmt->bind_param("i", $electionId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Voting has been finished.']);