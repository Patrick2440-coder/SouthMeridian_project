<?php
session_start();

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  header("Location: index.php"); exit;
}
if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
  header("Location: index.php"); exit;
}

$db_host = "localhost";
$db_user = "u972459197_patrick";
$db_pass = "Idle2440";
$db_name = "u972459197_south_meridian";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
  http_response_code(500);
  exit("DB error");
}
$conn->set_charset("utf8mb4");

function back(string $msg, string $returnStatus, string $returnFacility): void {
  header("Location: admin_facility_rentals.php?status=" . urlencode($returnStatus) .
         "&facility=" . urlencode($returnFacility) .
         "&msg=" . urlencode($msg));
  exit;
}

$returnStatus   = (string)($_POST['return_status'] ?? 'pending');
$returnFacility = (string)($_POST['return_facility'] ?? 'all');

$allowedStatus = ['pending','approved','denied','cancelled'];
if (!in_array($returnStatus, $allowedStatus, true)) $returnStatus = 'pending';

$allowedFacility = ['all','tables_chairs','court','clubhouse'];
if (!in_array($returnFacility, $allowedFacility, true)) $returnFacility = 'all';

$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_facility_rent']) || !hash_equals($_SESSION['csrf_facility_rent'], $csrf)) {
  back("Invalid request (CSRF). Please try again.", $returnStatus, $returnFacility);
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);

$stmt = $conn->prepare("SELECT phase FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

$phase = (string)($me['phase'] ?? 'Phase 1');

$court_hour = (int)($_POST['court_rate_per_hour'] ?? 100);
$court_30   = (int)($_POST['court_rate_per_30min'] ?? 50);
$tables     = (int)($_POST['tables_chairs_flat'] ?? 2500);
$club_flat  = (int)($_POST['clubhouse_flat'] ?? 2500);
$club_max   = (int)($_POST['clubhouse_max_person'] ?? 50);

if ($court_hour < 0 || $court_30 < 0 || $tables < 0 || $club_flat < 0) {
  back("Prices must be 0 or higher.", $returnStatus, $returnFacility);
}
if ($club_max < 1 || $club_max > 500) {
  back("Clubhouse max person must be between 1 and 500.", $returnStatus, $returnFacility);
}

$stmt = $conn->prepare("
  INSERT INTO facility_rental_pricing
    (phase, court_rate_per_hour, court_rate_per_30min, tables_chairs_flat, clubhouse_flat, clubhouse_max_person)
  VALUES
    (?, ?, ?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE
    court_rate_per_hour=VALUES(court_rate_per_hour),
    court_rate_per_30min=VALUES(court_rate_per_30min),
    tables_chairs_flat=VALUES(tables_chairs_flat),
    clubhouse_flat=VALUES(clubhouse_flat),
    clubhouse_max_person=VALUES(clubhouse_max_person)
");
$stmt->bind_param("siiiii", $phase, $court_hour, $court_30, $tables, $club_flat, $club_max);
$ok = $stmt->execute();
$stmt->close();

back($ok ? "Pricing updated for $phase." : "Failed to update pricing.", $returnStatus, $returnFacility);