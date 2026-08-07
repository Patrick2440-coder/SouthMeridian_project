<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
  header("Location: ../index.php"); exit;
}

date_default_timezone_set('Asia/Manila');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function back($msg){
  header("Location: homeowner_rentals.php?msg=" . urlencode($msg));
  exit;
}

$hid = (int)$_SESSION['homeowner_id'];

/* CSRF */
$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf_rent_req']) || !hash_equals($_SESSION['csrf_rent_req'], $csrf)) {
  back("Invalid request. Please try again.");
}

/* homeowner phase */
$stmt = $conn->prepare("SELECT phase, status FROM homeowners WHERE id=? LIMIT 1");
$stmt->bind_param("i", $hid);
$stmt->execute();
$h = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$h || ($h['status'] ?? '') !== 'approved') {
  session_destroy();
  back("Account not approved.");
}

$phase = trim((string)($h['phase'] ?? ''));
if ($phase === '') back("Missing phase on homeowner record.");

/* input */
$facility = (string)($_POST['facility'] ?? '');
$allowedFacilities = ['tables_chairs','court','clubhouse'];
if (!in_array($facility, $allowedFacilities, true)) back("Invalid facility.");

$start_in = (string)($_POST['start_dt'] ?? '');
$end_in   = (string)($_POST['end_dt'] ?? '');

$purpose  = trim((string)($_POST['purpose'] ?? ''));
$notes    = trim((string)($_POST['notes'] ?? ''));

if (strlen($purpose) > 255) $purpose = substr($purpose, 0, 255);
if (strlen($notes) > 255)   $notes   = substr($notes, 0, 255);

/* Guest count (only for clubhouse) */
$guest_count = null;
if ($facility === 'clubhouse') {
  $gc = trim((string)($_POST['guest_count'] ?? ''));
  if ($gc === '') back("Please enter guest count for Clubhouse.");
  if (!ctype_digit($gc)) back("Guest count must be a whole number.");
  $guest_count = (int)$gc;
  if ($guest_count < 1) back("Guest count must be at least 1.");
}

/* Normalize datetime-local => MySQL DATETIME */
$start = str_replace('T',' ', $start_in);
$end   = str_replace('T',' ', $end_in);

$startTs = strtotime($start);
$endTs   = strtotime($end);

if (!$startTs || !$endTs) back("Invalid start/end date.");
if ($endTs <= $startTs) back("End must be after Start.");
if ($startTs < time() - 60) back("Start time must be in the future.");

/* OPTIONAL: enforce max horizon */
$maxAheadDays = 180;
if ($startTs > strtotime("+{$maxAheadDays} days")) {
  back("Booking too far in advance. Please choose a nearer date.");
}

/* OPTIONAL: court uses 30-minute increments */
$enforceCourt30MinSteps = true;
if ($facility === 'court' && $enforceCourt30MinSteps) {
  $mins = (int)round(($endTs - $startTs) / 60);
  if ($mins % 30 !== 0) {
    back("Court booking must be in 30-minute increments (e.g., 30, 60, 90 minutes).");
  }
}

/* Load dynamic pricing (per phase) — FIXED COLUMN NAME */
$pricing = [
  'clubhouse_max_person' => 50, // default
];

try {
  $ps = $conn->prepare("
    SELECT clubhouse_max_person
    FROM facility_rental_pricing
    WHERE phase=? LIMIT 1
  ");
  $ps->bind_param("s", $phase);
  $ps->execute();
  $prow = $ps->get_result()->fetch_assoc();
  $ps->close();

  if ($prow && $prow['clubhouse_max_person'] !== null) {
    $pricing['clubhouse_max_person'] = (int)$prow['clubhouse_max_person'];
  }
} catch (Throwable $e) {
  // keep default
}

/* Validate clubhouse max persons */
if ($facility === 'clubhouse') {
  $maxPersons = (int)$pricing['clubhouse_max_person'];
  if ($guest_count > $maxPersons) {
    back("Clubhouse max capacity is {$maxPersons} persons. Please lower guest count.");
  }
}

/* overlap with APPROVED */
$stmt = $conn->prepare("
  SELECT COUNT(*) c
  FROM facility_rental_requests
  WHERE phase=? AND facility=? AND status='approved'
    AND (? < end_dt) AND (? > start_dt)
");
$stmt->bind_param("ssss", $phase, $facility, $start, $end);
$stmt->execute();
$overlap = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

if ($overlap > 0) {
  back("Schedule conflict: already rented (approved booking).");
}

/* insert pending */
$stmt = $conn->prepare("
  INSERT INTO facility_rental_requests
    (phase, homeowner_id, facility, start_dt, end_dt, purpose, notes, guest_count, status)
  VALUES (?,?,?,?,?,?,?,?, 'pending')
");

/* make guest_count nullable for non-clubhouse */
$gc_for_db = ($facility === 'clubhouse') ? $guest_count : null;

$stmt->bind_param(
  "sisssssi",
  $phase,
  $hid,
  $facility,
  $start,
  $end,
  $purpose,
  $notes,
  $gc_for_db
);

$stmt->execute();
$newId = (int)$stmt->insert_id;
$stmt->close();

/* ✅ verify it exists (catches phase mismatch / wrong table / wrong DB instantly) */
$chk = $conn->prepare("SELECT id FROM facility_rental_requests WHERE id=? AND homeowner_id=? LIMIT 1");
$chk->bind_param("ii", $newId, $hid);
$chk->execute();
$okRow = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$okRow) {
  back("Submitted but not found after insert (DB/phase mismatch). Insert ID: {$newId}");
}

back("Request submitted! Wait for admin approval. Ref #{$newId}");