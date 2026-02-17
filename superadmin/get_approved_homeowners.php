<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$phase = trim($_GET['phase'] ?? '');
$allowed = ['Phase 1', 'Phase 2', 'Phase 3'];

if (!in_array($phase, $allowed, true)) {
  echo json_encode(['success' => false, 'message' => 'Invalid phase', 'data' => []]);
  exit;
}

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'DB connection failed']);
  exit;
}
$conn->set_charset("utf8mb4");

$sql = "
  SELECT id, first_name, middle_name, last_name, house_lot_number, phase, email
  FROM homeowners
  WHERE status='approved' AND phase=?
  ORDER BY last_name, first_name
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $phase);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
  $mid = trim((string)$row['middle_name']);
  $full = trim($row['first_name'] . ' ' . ($mid !== '' ? ($mid . ' ') : '') . $row['last_name']);

  $data[] = [
    'id' => (int)$row['id'],
    'name' => $full,
    'phase' => $row['phase'],
    'house_lot_number' => $row['house_lot_number'],
    'email' => $row['email'],
  ];
}

$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'data' => $data]);
