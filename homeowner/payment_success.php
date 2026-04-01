<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
  header("Location: ../index.php");
  exit;
}

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function computePermitDates(string $duration): array {
  $start = new DateTime('today');
  $end   = new DateTime('today');

  switch ($duration) {
    case '1_month':
      $end->modify('+1 month')->modify('-1 day');
      break;
    case '3_months':
      $end->modify('+3 months')->modify('-1 day');
      break;
    case '6_months':
      $end->modify('+6 months')->modify('-1 day');
      break;
    case '1_year':
      $end->modify('+1 year')->modify('-1 day');
      break;
    default:
      $end = clone $start;
      break;
  }

  return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

$hid = (int)$_SESSION['homeowner_id'];
$permitId = (int)($_GET['permit_id'] ?? 0);

if ($permitId <= 0) {
  die("Invalid permit ID.");
}

$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE id=? AND homeowner_id=? AND payment_method='online'
  LIMIT 1
");
$stmt->bind_param("ii", $permitId, $hid);
$stmt->execute();
$permit = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$permit) {
  die("Permit not found or access denied.");
}

$permitStatus  = strtolower((string)($permit['status'] ?? 'pending'));
$paymentStatus = strtolower((string)($permit['payment_status'] ?? 'unpaid'));

if ($paymentStatus === 'paid' && $permitStatus === 'active') {
  header("Location: homeowner_parking_permit.php?paid=1");
  exit;
}

// Must already be admin-approved before payment can activate it
if ($permitStatus !== 'approved') {
  header("Location: homeowner_parking_permit.php?waiting_approval=1");
  exit;
}

// Use existing validity if already set, otherwise compute fallback
$validFrom  = !empty($permit['valid_from']) ? (string)$permit['valid_from'] : null;
$validUntil = !empty($permit['valid_until']) ? (string)$permit['valid_until'] : null;

if (empty($validFrom) || empty($validUntil)) {
  [$computedFrom, $computedUntil] = computePermitDates((string)$permit['permit_duration']);
  $validFrom  = $computedFrom;
  $validUntil = $computedUntil;
}

$stmt = $conn->prepare("
  UPDATE parking_permits
  SET payment_status='paid',
      valid_from=?,
      valid_until=?,
      validity_start=?,
      validity_end=?,
      status='active',
      updated_at=NOW()
  WHERE id=? 
    AND homeowner_id=? 
    AND payment_method='online'
    AND status='approved'
  LIMIT 1
");
$stmt->bind_param("ssssii", $validFrom, $validUntil, $validFrom, $validUntil, $permitId, $hid);
$stmt->execute();

$affected = $stmt->affected_rows;
$stmt->close();

if ($affected <= 0) {
  die("Payment was received, but the permit could not be activated.");
}

header("Location: homeowner_parking_permit.php?paid=1");
exit;