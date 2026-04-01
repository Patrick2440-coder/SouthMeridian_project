<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function go(string $msg, string $status = 'pending', string $facility = 'all'){
  $qs = http_build_query([
    'msg' => $msg,
    'status' => $status,
    'facility' => $facility
  ]);
  header("Location: admin_facility_rentals.php?$qs");
  exit;
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);

$returnStatus   = (string)($_POST['return_status'] ?? 'pending');
$returnFacility = (string)($_POST['return_facility'] ?? 'all');

$allowedStatus = ['pending','approved','denied','cancelled'];
if (!in_array($returnStatus, $allowedStatus, true)) $returnStatus = 'pending';

$allowedFacility = ['all','tables_chairs','court','clubhouse'];
if (!in_array($returnFacility, $allowedFacility, true)) $returnFacility = 'all';

$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_facility_rent']) || !hash_equals($_SESSION['csrf_facility_rent'], $csrf)) {
  go("Invalid request (CSRF).", $returnStatus, $returnFacility);
}

$id = (int)($_POST['id'] ?? 0);
$newStatus = (string)($_POST['new_status'] ?? '');
$remarks = trim((string)($_POST['remarks'] ?? ''));

// Optional: cap to prevent DB truncation warnings/errors (adjust if your column is bigger)
if (strlen($remarks) > 255) $remarks = substr($remarks, 0, 255);

if ($id <= 0) go("Invalid request id.", $returnStatus, $returnFacility);
if (!in_array($newStatus, ['approved','denied'], true)) go("Invalid status.", $returnStatus, $returnFacility);

// Optional rule: require remarks when denied
if ($newStatus === 'denied' && $remarks === '') {
  go("Remarks are required when denying a request.", $returnStatus, $returnFacility);
}

/* Admin phase scope (locked to admin phase) */
$stmt = $conn->prepare("SELECT phase FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$adm = $stmt->get_result()->fetch_assoc();
$stmt->close();

$phase = (string)($adm['phase'] ?? 'Phase 1');

/* Load request */
$stmt = $conn->prepare("
  SELECT id, phase, facility, start_dt, end_dt, status
  FROM facility_rental_requests
  WHERE id=? LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$r) go("Request not found.", $returnStatus, $returnFacility);
if (($r['status'] ?? '') !== 'pending') go("Only pending requests can be updated.", $returnStatus, $returnFacility);
if ((string)$r['phase'] !== $phase) go("Phase mismatch. Cannot update this request.", $returnStatus, $returnFacility);

/* Overlap check if approving */
if ($newStatus === 'approved') {
  $facility = (string)$r['facility'];
  $start = (string)$r['start_dt'];
  $end   = (string)$r['end_dt'];

  $stmt = $conn->prepare("
    SELECT COUNT(*) c
    FROM facility_rental_requests
    WHERE phase=? AND facility=? AND status='approved'
      AND id <> ?
      AND (? < end_dt) AND (? > start_dt)
  ");
  // phase(s), facility(s), id(i), start(s), end(s)
  $stmt->bind_param("ssiss", $phase, $facility, $id, $start, $end);
  $stmt->execute();
  $overlap = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
  $stmt->close();

  if ($overlap > 0) go("Cannot approve: overlaps an already approved booking.", $returnStatus, $returnFacility);
}

/* ✅ FIXED bind types: s i s i s */
$stmt = $conn->prepare("
  UPDATE facility_rental_requests
  SET status=?, admin_id=?, admin_remarks=?
  WHERE id=? AND status='pending' AND phase=?
");
$stmt->bind_param("sisis", $newStatus, $adminId, $remarks, $id, $phase);
$ok = $stmt->execute();
$stmt->close();

go($ok ? "Updated successfully." : "Failed to update.", $returnStatus, $returnFacility);