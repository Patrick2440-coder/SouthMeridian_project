<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Optional auth:
// if (!isset($_SESSION['admin_id'])) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$id = (int)($_POST['nomination_id'] ?? 0);
if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid nomination id']); exit; }

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'DB connect failed']); exit; }
$conn->set_charset("utf8mb4");

$stmt = $conn->prepare("DELETE FROM election_nominations WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();

$deleted = ($stmt->affected_rows > 0);
$stmt->close();
$conn->close();

echo json_encode(['success'=>$deleted, 'message'=>$deleted ? 'Deleted' : 'Not found']);
