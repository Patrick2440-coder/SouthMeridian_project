<?php
// Make logs appear even if PHP errors are hidden
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Where to write logs (same folder as this webhook)
$logErrFile = __DIR__ . '/paymongo_webhook_errors.log';
$logPayload = __DIR__ . '/paymongo_webhook_payload.log';

// If file cannot be created, PHP will fail silently. Ensure this folder is writable.
ini_set('error_log', $logErrFile);

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) { http_response_code(500); exit("DB error"); }
$conn->set_charset("utf8mb4");

function log_err($msg) {
  error_log("[" . date('c') . "] " . $msg);
}

$payload = file_get_contents("php://input");
file_put_contents($logPayload, date('c')."\n".$payload."\n\n", FILE_APPEND);

$event = json_decode($payload, true);
if (!is_array($event)) {
  http_response_code(400);
  exit("Invalid JSON");
}

// ---- Signature verification (optional) ----
$webhookSecret = getenv('PAYMONGO_WEBHOOK_SECRET') ?: '';
if ($webhookSecret !== '') {
  $sigHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';
  if ($sigHeader === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $sigHeader = $headers['Paymongo-Signature'] ?? ($headers['paymongo-signature'] ?? '');
  }

  if ($sigHeader === '') { http_response_code(400); exit("Missing signature"); }

  $parts = array_map('trim', explode(',', $sigHeader));
  $kv = [];
  foreach ($parts as $p) {
    $x = explode('=', $p, 2);
    if (count($x) === 2) $kv[$x[0]] = $x[1];
  }

  $t  = $kv['t']  ?? '';
  $te = $kv['te'] ?? '';
  $li = $kv['li'] ?? '';
  if ($t === '') { http_response_code(400); exit("Bad signature"); }

  $signedPayload = $t . "." . $payload;
  $computed = hash_hmac('sha256', $signedPayload, $webhookSecret);

  $livemode = (bool)($event['data']['attributes']['livemode'] ?? false);
  $expected = $livemode ? $li : $te;

  if ($expected === '' || !hash_equals($expected, $computed)) {
    http_response_code(400);
    exit("Invalid signature");
  }
}

// Extract event type
$eventId   = (string)($event['data']['id'] ?? '');
$eventType = (string)($event['data']['attributes']['type'] ?? '');

// Only handle paid
if ($eventType !== 'checkout_session.payment.paid') {
  http_response_code(200);
  exit("OK");
}

// Checkout session object
$cs  = $event['data']['attributes']['data'] ?? [];
$csId = (string)($cs['id'] ?? '');
if ($csId === '') {
  http_response_code(400);
  exit("Missing checkout session id");
}

// Best-effort payment info
$payments = $cs['attributes']['payments'] ?? [];
$paymentId = '';
$amountCentavos = 0;
if (is_array($payments) && !empty($payments[0]['id'])) {
  $paymentId = (string)$payments[0]['id'];
  $amountCentavos = (int)($payments[0]['attributes']['amount'] ?? 0);
}

// Lookup our pending record
$stmt = $conn->prepare("
  SELECT homeowner_id, phase, pay_year, pay_month, amount
  FROM finance_paymongo_checkouts
  WHERE checkout_session_id=? LIMIT 1
");
if (!$stmt) { log_err("Prepare failed SELECT checkouts: ".$conn->error); http_response_code(500); exit("DB error"); }

$stmt->bind_param("s", $csId);
if (!$stmt->execute()) { log_err("Execute failed SELECT checkouts: ".$stmt->error); http_response_code(500); exit("DB error"); }

$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
  // If we don't find it, still return OK to avoid retries forever
  http_response_code(200);
  exit("OK");
}

$hid   = (int)$row['homeowner_id'];
$phase = (string)$row['phase'];
$year  = (int)$row['pay_year'];
$month = (int)$row['pay_month'];

// Extra safety: phase must be valid
if ($phase === '') {
  // Try to recover phase from homeowners table
  $s2 = $conn->prepare("SELECT phase FROM homeowners WHERE id=? LIMIT 1");
  if ($s2) {
    $s2->bind_param("i", $hid);
    $s2->execute();
    $tmp = $s2->get_result()->fetch_assoc();
    $s2->close();
    if (!empty($tmp['phase'])) $phase = (string)$tmp['phase'];
  }
}

if ($phase === '') {
  log_err("Webhook: phase empty for checkout_session_id={$csId}. Cannot insert finance_payments.");
  http_response_code(500);
  exit("Bad data");
}

$conn->begin_transaction();

try {
  // Mark checkout as paid
  $stmt = $conn->prepare("
    UPDATE finance_paymongo_checkouts
    SET status='paid', payment_id=?, paid_at=NOW(), last_event_type=?, last_event_id=?
    WHERE checkout_session_id=?
  ");
  if (!$stmt) throw new Exception("Prepare failed UPDATE checkouts: " . $conn->error);

  $stmt->bind_param("ssss", $paymentId, $eventType, $eventId, $csId);
  if (!$stmt->execute()) throw new Exception("Execute failed UPDATE checkouts: " . $stmt->error);
  $stmt->close();

  // Insert into finance_payments
  $amount = (float)$row['amount'];
  if ($amountCentavos > 0) $amount = $amountCentavos / 100.0;

  $ref   = $paymentId !== '' ? $paymentId : $csId;
  $notes = "PayMongo";

  $stmt = $conn->prepare("
    INSERT INTO finance_payments
      (homeowner_id, phase, pay_year, pay_month, amount, status, paid_at, reference_no, notes, created_by_admin_id)
    VALUES (?,?,?,?,?,'paid',NOW(),?,?,NULL)
    ON DUPLICATE KEY UPDATE
      amount=VALUES(amount),
      status='paid',
      paid_at=NOW(),
      reference_no=VALUES(reference_no),
      notes=VALUES(notes),
      created_by_admin_id=NULL
  ");
  if (!$stmt) throw new Exception("Prepare failed INSERT payments: " . $conn->error);

  // i s i i d s s
  $stmt->bind_param("isiidss", $hid, $phase, $year, $month, $amount, $ref, $notes);
  if (!$stmt->execute()) throw new Exception("Execute failed INSERT payments: " . $stmt->error);
  $stmt->close();

  $conn->commit();

  http_response_code(200);
  echo "OK";
} catch (Exception $e) {
  $conn->rollback();
  log_err("Webhook failed: " . $e->getMessage());
  http_response_code(500);
  echo "Webhook failed";
}
