<?php
session_start();
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1); // show errors for debugging

/* ================= SESSION CHECK ================= */
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

/* ================= DB CONNECTION ================= */
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

/* ================= VALIDATE INPUT ================= */
$id     = intval($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

if ($id <= 0 || !in_array($status, ['approved','rejected'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

/* ================= FETCH HOMEOWNER ================= */
$stmt = $conn->prepare("SELECT first_name, last_name FROM homeowners WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$homeowner = $res->fetch_assoc();
$stmt->close();

if (!$homeowner) {
    echo json_encode(['success' => false, 'message' => 'Homeowner not found']);
    exit;
}

/* ================= UPDATE STATUS ================= */
$stmt = $conn->prepare("UPDATE homeowners SET status=? WHERE id=?");
$stmt->bind_param("si", $status, $id);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Update failed']);
    exit;
}

$stmt->close();

/* ================= SUCCESS ================= */
echo json_encode([
    'success' => true,
    'message' => "Homeowner successfully {$status}."
]);
exit;
