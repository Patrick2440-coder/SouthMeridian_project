<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Optional: if you track login
// if (!isset($_SESSION['admin_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'DB connect failed']); exit; }
$conn->set_charset("utf8mb4");

$phase    = trim($_POST['phase'] ?? '');
$position = trim($_POST['position'] ?? '');
$homeowner_id = (int)($_POST['homeowner_id'] ?? 0);

$allowedPhases = ['Phase 1','Phase 2','Phase 3'];
$allowedPos = ['President','Vice President','Secretary','Treasurer','Auditor','Board of Director'];

if (!in_array($phase, $allowedPhases, true)) {
  echo json_encode(['success'=>false,'message'=>'Invalid phase']); exit;
}
if (!in_array($position, $allowedPos, true)) {
  echo json_encode(['success'=>false,'message'=>'Invalid position']); exit;
}
if ($homeowner_id <= 0) {
  echo json_encode(['success'=>false,'message'=>'Invalid homeowner']); exit;
}

$created_by = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;

/**
 * Extra safety:
 * ensure homeowner exists, approved, and same phase
 */
$chk = $conn->prepare("SELECT id FROM homeowners WHERE id=? AND status='approved' AND phase=? LIMIT 1");
$chk->bind_param("is", $homeowner_id, $phase);
$chk->execute();
$ok = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$ok) {
  echo json_encode(['success'=>false,'message'=>'Homeowner not approved or not in selected phase']); exit;
}

$sql = "INSERT INTO election_nominations (phase, position, homeowner_id, created_by_admin_id)
        VALUES (?, ?, ?, ?)";
$stmt = $conn->prepare($sql);

// bind null correctly
if ($created_by === null) {
  $null = null;
  $stmt->bind_param("ssii", $phase, $position, $homeowner_id, $null);
} else {
  $stmt->bind_param("ssii", $phase, $position, $homeowner_id, $created_by);
}

try {
  $stmt->execute();
  $newId = $stmt->insert_id;
  $stmt->close();

  echo json_encode(['success'=>true,'message'=>'Nominee added','id'=>$newId]);
} catch (mysqli_sql_exception $e) {
  // Duplicate unique key
  if ((int)$conn->errno === 1062) {
    echo json_encode(['success'=>false,'message'=>'Already nominated for that position']);
  } else {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Insert failed']);
  }
}
$conn->close();
