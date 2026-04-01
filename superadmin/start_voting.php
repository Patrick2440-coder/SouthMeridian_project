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
$adminId = (int)$_SESSION['admin_id'];

$allowed = ['Phase 1','Phase 2','Phase 3'];
if (!in_array($phase, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid phase']);
    exit;
}

/* block if active election already exists */
$stmt = $conn->prepare("SELECT id FROM election_sessions WHERE phase=? AND status='active' LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    echo json_encode(['success' => false, 'message' => 'Voting is already active for this phase.']);
    exit;
}

/* get draft session or create one */
$stmt = $conn->prepare("SELECT id FROM election_sessions WHERE phase=? AND status='draft' ORDER BY id DESC LIMIT 1");
$stmt->bind_param("s", $phase);
$stmt->execute();
$draft = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($draft) {
    $electionId = (int)$draft['id'];
} else {
    $title = $phase . ' HOA Election ' . date('Y');
    $stmt = $conn->prepare("INSERT INTO election_sessions (phase, title, status, created_by_admin_id) VALUES (?, ?, 'draft', ?)");
    $stmt->bind_param("ssi", $phase, $title, $adminId);
    $stmt->execute();
    $electionId = (int)$stmt->insert_id;
    $stmt->close();
}

/* attach current unassigned nominees */
$stmt = $conn->prepare("UPDATE election_nominations SET election_id=? WHERE phase=? AND election_id IS NULL");
$stmt->bind_param("is", $electionId, $phase);
$stmt->execute();
$stmt->close();

/* make sure nominees exist */
$stmt = $conn->prepare("SELECT COUNT(*) c FROM election_nominations WHERE election_id=?");
$stmt->bind_param("i", $electionId);
$stmt->execute();
$count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

if ($count <= 0) {
    echo json_encode(['success' => false, 'message' => 'No nominees found. Add nominees first.']);
    exit;
}

/* activate */
$stmt = $conn->prepare("UPDATE election_sessions SET status='active', started_at=NOW() WHERE id=?");
$stmt->bind_param("i", $electionId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Voting has started.']);