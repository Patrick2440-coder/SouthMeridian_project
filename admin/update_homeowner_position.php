<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','superadmin'], true) || empty($_SESSION['user_id'])) {
	echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
	exit;
}

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) {
	echo json_encode(['success' => false, 'message' => 'DB connection failed.']);
	exit;
}
$conn->set_charset("utf8mb4");

$admin_id = (int)$_SESSION['user_id'];

$homeowner_id = isset($_POST['homeowner_id']) ? (int)$_POST['homeowner_id'] : 0;
$position = trim($_POST['position'] ?? '');

if ($homeowner_id <= 0) {
	echo json_encode(['success' => false, 'message' => 'Invalid homeowner id.']);
	exit;
}
if ($position === '') $position = 'Homeowner';
if (mb_strlen($position) > 80) {
	echo json_encode(['success' => false, 'message' => 'Position is too long (max 80 chars).']);
	exit;
}

// Get admin phase
$stmt = $conn->prepare("SELECT phase FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin || $admin['phase'] === 'Superadmin') {
	echo json_encode(['success' => false, 'message' => 'Your account is not tied to a specific phase.']);
	exit;
}

$admin_phase = $admin['phase'];

// Ensure homeowner belongs to this phase and is approved
$stmt = $conn->prepare("SELECT id, phase, status FROM homeowners WHERE id=? LIMIT 1");
$stmt->bind_param("i", $homeowner_id);
$stmt->execute();
$h = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$h) {
	echo json_encode(['success' => false, 'message' => 'Homeowner not found.']);
	exit;
}
if ($h['phase'] !== $admin_phase) {
	echo json_encode(['success' => false, 'message' => 'You cannot edit homeowners outside your phase.']);
	exit;
}
if ($h['status'] !== 'approved') {
	echo json_encode(['success' => false, 'message' => 'Only approved homeowners can be updated here.']);
	exit;
}

// Upsert into homeowner_positions
$stmt = $conn->prepare("
	INSERT INTO homeowner_positions (homeowner_id, phase, position, updated_by_admin_id)
	VALUES (?, ?, ?, ?)
	ON DUPLICATE KEY UPDATE
		position=VALUES(position),
		updated_by_admin_id=VALUES(updated_by_admin_id),
		updated_at=CURRENT_TIMESTAMP
");
$stmt->bind_param("issi", $homeowner_id, $admin_phase, $position, $admin_id);

if (!$stmt->execute()) {
	$stmt->close();
	echo json_encode(['success' => false, 'message' => 'Failed to update position.']);
	exit;
}
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Position updated.']);
