<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  echo json_encode([]); exit;
}

if (($_SESSION['admin_role'] ?? '') === 'superadmin') {
  echo json_encode([]); exit;
}

$db_host = "localhost";
$db_user = "u972459197_patrick";
$db_pass = "Idle2440";
$db_name = "u972459197_south_meridian";

try {
  $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
  $conn->set_charset("utf8mb4");

  $adminId = (int)($_SESSION['admin_id'] ?? 0);

  $stmt = $conn->prepare("SELECT phase FROM admins WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $adminId);
  $stmt->execute();
  $me = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $phase = (string)($me['phase'] ?? 'Phase 1');

  $stmt = $conn->prepare("
    SELECT
      r.id, r.phase, r.homeowner_id,
      r.facility, r.start_dt, r.end_dt,
      r.purpose, r.notes, r.status,
      r.admin_id, r.admin_remarks,
      r.created_at, r.updated_at,
      h.first_name, h.last_name, h.house_lot_number, h.email
    FROM facility_rental_requests r
    LEFT JOIN homeowners h ON h.id = r.homeowner_id
    WHERE r.phase=? AND r.status='approved'
    ORDER BY r.start_dt ASC
  ");
  $stmt->bind_param("s", $phase);
  $stmt->execute();
  $res = $stmt->get_result();

  $rows = [];
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  $stmt->close();

  function facility_label(string $f): string {
    return match($f){
      'tables_chairs' => 'Tables & Chairs',
      'court' => 'Court',
      'clubhouse' => 'Clubhouse',
      default => $f
    };
  }

  $events = [];
  foreach ($rows as $r) {
    $facilityKey   = (string)($r['facility'] ?? '');
    $facilityLabel = facility_label($facilityKey);

    $title = $facilityLabel . " • Reserved";

    $hoName = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
    if ($hoName === '') $hoName = 'Homeowner';

    $events[] = [
      'id'    => (string)$r['id'],
      'title' => $title,
      'start' => (string)$r['start_dt'],
      'end'   => (string)$r['end_dt'],
      'allDay' => false,
      'classNames' => ['rent-approved'],
      'extendedProps' => [
        'status'       => (string)($r['status'] ?? 'approved'),
        'facilityKey'  => $facilityKey,
        'facility'     => $facilityLabel,
        'homeownerId'  => (int)($r['homeowner_id'] ?? 0),
        'homeownerName'=> $hoName,
        'houseLot'     => (string)($r['house_lot_number'] ?? ''),
        'email'        => (string)($r['email'] ?? ''),
        'purpose'      => (string)($r['purpose'] ?? ''),
        'notes'        => (string)($r['notes'] ?? ''),
        'adminRemarks' => (string)($r['admin_remarks'] ?? ''),
        'createdAt'    => (string)($r['created_at'] ?? ''),
        'updatedAt'    => (string)($r['updated_at'] ?? ''),
      ]
    ];
  }

  echo json_encode($events);
  exit;

} catch (Throwable $e) {
  error_log("admin_facility_events.php error: " . $e->getMessage());
  echo json_encode([]);
  exit;
}