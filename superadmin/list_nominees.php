<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$phase = trim($_GET['phase'] ?? '');
$allowed = ['Phase 1','Phase 2','Phase 3'];

if (!in_array($phase, $allowed, true)) {
  echo json_encode(['success'=>false,'message'=>'Invalid phase','data'=>[]]);
  exit;
}

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'DB connect failed']); exit; }
$conn->set_charset("utf8mb4");

$sql = "
  SELECT
    n.id AS nomination_id,
    n.phase,
    n.position,
    h.id AS homeowner_id,
    h.first_name, h.middle_name, h.last_name,
    h.house_lot_number
  FROM election_nominations n
  JOIN homeowners h ON h.id = n.homeowner_id
  WHERE n.phase = ?
  ORDER BY n.position, h.last_name, h.first_name
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
    'nomination_id' => (int)$row['nomination_id'],
    'homeowner_id'  => (int)$row['homeowner_id'],
    'name'          => $full,
    'phase'         => $row['phase'],
    'position'      => $row['position'],
    'house_lot_number' => $row['house_lot_number'],
  ];
}

$stmt->close();
$conn->close();

echo json_encode(['success'=>true,'data'=>$data]);
