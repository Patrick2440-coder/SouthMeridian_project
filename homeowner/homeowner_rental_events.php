<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
  http_response_code(401);
  echo json_encode([]); exit;
}

try {
  $conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
  $conn->set_charset("utf8mb4");

  $hid = (int)$_SESSION['homeowner_id'];

  $stmt = $conn->prepare("SELECT phase, status FROM homeowners WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $h = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$h || ($h['status'] ?? '') !== 'approved') {
    http_response_code(403);
    echo json_encode([]); exit;
  }

  $phase = (string)$h['phase'];

  $facility = (string)($_GET['facility'] ?? 'tables_chairs');
  $allowedFacilities = ['tables_chairs','court','clubhouse'];
  if (!in_array($facility, $allowedFacilities, true)) $facility = 'tables_chairs';

  function facility_label($f){
    return $f === 'tables_chairs' ? 'Tables & Chairs' : ($f === 'court' ? 'Court' : 'Clubhouse');
  }

  $start = (string)($_GET['start'] ?? '');
  $end   = (string)($_GET['end'] ?? '');

  // ✅ FIXED DEFAULTS + KEYS (match your DB columns)
  $pricing = [
    'court_rate_per_hour'   => 100.00,
    'court_rate_per_30min'  => 50.00,
    'tables_chairs_flat'    => 2500.00,
    'clubhouse_flat'        => 2500.00,
    'clubhouse_max_person'  => 50,
  ];

  // ✅ FIXED SELECT COLUMN NAMES
  try {
    $ps = $conn->prepare("
      SELECT court_rate_per_hour, court_rate_per_30min, tables_chairs_flat, clubhouse_flat, clubhouse_max_person
      FROM facility_rental_pricing
      WHERE phase=? LIMIT 1
    ");
    $ps->bind_param("s", $phase);
    $ps->execute();
    $row = $ps->get_result()->fetch_assoc();
    $ps->close();

    if ($row) {
      $pricing['court_rate_per_hour']   = (float)$row['court_rate_per_hour'];
      $pricing['court_rate_per_30min']  = (float)$row['court_rate_per_30min'];
      $pricing['tables_chairs_flat']    = (float)$row['tables_chairs_flat'];
      $pricing['clubhouse_flat']        = (float)$row['clubhouse_flat'];
      $pricing['clubhouse_max_person']  = (int)$row['clubhouse_max_person'];
    }
  } catch (Throwable $e) {
    // keep defaults
  }

  function compute_amount(string $facility, string $start_dt, string $end_dt, array $pricing): float {
    $s = strtotime($start_dt);
    $e = strtotime($end_dt);
    if (!$s || !$e || $e <= $s) return 0.0;

    $mins = (int)ceil(($e - $s) / 60);
    if ($mins < 1) $mins = 1;

    if ($facility === 'court') {
      $blocks = (int)ceil($mins / 30);
      return $blocks * (float)$pricing['court_rate_per_30min'];
    }

    if ($facility === 'tables_chairs') {
      return (float)$pricing['tables_chairs_flat'];
    }

    if ($facility === 'clubhouse') {
      return (float)$pricing['clubhouse_flat'];
    }

    return 0.0;
  }

  $events = [];

  if ($start && $end) {
    $stmt = $conn->prepare("
      SELECT id, facility, start_dt, end_dt, purpose, notes, status, guest_count
      FROM facility_rental_requests
      WHERE phase=? AND facility=? AND status='approved'
        AND (start_dt < ?) AND (end_dt > ?)
      ORDER BY start_dt ASC
    ");
    $stmt->bind_param("ssss", $phase, $facility, $end, $start);
  } else {
    $stmt = $conn->prepare("
      SELECT id, facility, start_dt, end_dt, purpose, notes, status, guest_count
      FROM facility_rental_requests
      WHERE phase=? AND facility=? AND status='approved'
      ORDER BY start_dt ASC
      LIMIT 400
    ");
    $stmt->bind_param("ss", $phase, $facility);
  }

  $stmt->execute();
  $res = $stmt->get_result();

  while ($r = $res->fetch_assoc()) {
    $fac = (string)$r['facility'];
    $amount = compute_amount($fac, (string)$r['start_dt'], (string)$r['end_dt'], $pricing);

    $events[] = [
      'id'    => (string)$r['id'],
      'title' => 'Rented — ' . facility_label($fac),
      'start' => (string)$r['start_dt'],
      'end'   => (string)$r['end_dt'],
      'allDay'=> false,

      'extendedProps' => [
        'status'        => (string)($r['status'] ?? 'approved'),
        'facility'      => $fac,
        'facilityLabel' => facility_label($fac),

        'purpose'       => (string)($r['purpose'] ?? ''),
        'notes'         => (string)($r['notes'] ?? ''),
        'guest_count'   => ($r['guest_count'] !== null ? (int)$r['guest_count'] : null),

        'amount'        => $amount,
        'max_persons'   => (int)$pricing['clubhouse_max_person'],
      ]
    ];
  }

  $stmt->close();

  echo json_encode($events);
  exit;

} catch (Throwable $e) {
  error_log("homeowner_rental_events.php error: ".$e->getMessage());
  http_response_code(500);
  echo json_encode([]);
  exit;
}