<?php
session_start();
require_once '../config/database.php';
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
  header("Location: ../index.php");
  exit;
}




require_once 'tenant_module_guard.php';

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function normalize_web_path(string $path): string {
  return str_replace('\\', '/', $path);
}

function cleanup_created_files(array $paths): void {
  foreach (array_unique($paths) as $path) {
    $path = trim((string)$path);
    if ($path !== '' && is_file($path)) {
      @unlink($path);
    }
  }
}

function save_upload(
  string $field,
  string $fsBaseDir,
  string $dbBaseDir,
  int $maxBytes = 5242880
): array {
  $result = [
    'db_path' => null,
    'fs_path' => null,
    'error'   => '',
  ];

  if (
    empty($_FILES[$field]) ||
    (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
  ) {
    $result['error'] = 'No file was selected.';
    return $result;
  }

  $file = $_FILES[$field];
  $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

  if ($uploadError !== UPLOAD_ERR_OK) {
    $result['error'] = 'The upload did not complete successfully.';
    return $result;
  }

  $tmp = (string)($file['tmp_name'] ?? '');

  if ($tmp === '' || !is_uploaded_file($tmp)) {
    $result['error'] = 'The uploaded file could not be verified.';
    return $result;
  }

  $size = (int)($file['size'] ?? 0);

  if ($size <= 0) {
    $result['error'] = 'The uploaded file is empty.';
    return $result;
  }

  if ($size > $maxBytes) {
    $result['error'] = 'The file is larger than 5 MB.';
    return $result;
  }

  if (!function_exists('finfo_open')) {
    $result['error'] = 'The server cannot verify this file type.';
    return $result;
  }

  $finfo = finfo_open(FILEINFO_MIME_TYPE);

  if ($finfo === false) {
    $result['error'] = 'The server cannot verify this file type.';
    return $result;
  }

  $mime = strtolower((string)finfo_file($finfo, $tmp));
  finfo_close($finfo);

  $allowedMime = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'application/pdf' => 'pdf',
  ];

  if (!isset($allowedMime[$mime])) {
    $result['error'] = 'Only JPG, PNG, or PDF files are allowed.';
    return $result;
  }

  if (!is_dir($fsBaseDir)) {
    if (!mkdir($fsBaseDir, 0775, true) && !is_dir($fsBaseDir)) {
      $result['error'] = 'The upload folder could not be created.';
      return $result;
    }
  }

  $newName = bin2hex(random_bytes(16)) . '.' . $allowedMime[$mime];

  $destFs =
    rtrim($fsBaseDir, '/\\') .
    DIRECTORY_SEPARATOR .
    $newName;

  $destDbRel =
    normalize_web_path(
      rtrim($dbBaseDir, '/\\') .
      '/' .
      $newName
    );

  if (!move_uploaded_file($tmp, $destFs)) {
    $result['error'] = 'The uploaded file could not be saved.';
    return $result;
  }

  @chmod($destFs, 0644);

  $result['db_path'] = $destDbRel;
  $result['fs_path'] = $destFs;

  return $result;
}

function write_contract_file(string $html, string $fsBaseDir, string $dbBaseDir): array {
  $result = [
    'db_path' => null,
    'fs_path' => null,
    'error'   => '',
  ];

  if (!is_dir($fsBaseDir)) {
    if (!mkdir($fsBaseDir, 0775, true) && !is_dir($fsBaseDir)) {
      $result['error'] = 'The contract folder could not be created.';
      return $result;
    }
  }

  $fileName = 'parking_contract_' . bin2hex(random_bytes(16)) . '.html';

  $destFs =
    rtrim($fsBaseDir, '/\\') .
    DIRECTORY_SEPARATOR .
    $fileName;

  $destDb =
    normalize_web_path(
      rtrim($dbBaseDir, '/\\') .
      '/' .
      $fileName
    );

  if (file_put_contents($destFs, $html, LOCK_EX) === false) {
    $result['error'] = 'The parking contract could not be generated.';
    return $result;
  }

  @chmod($destFs, 0644);

  $result['db_path'] = $destDb;
  $result['fs_path'] = $destFs;

  return $result;
}

function computePermitDates(string $duration, ?string $baseStart = null): array {
  $start = new DateTime($baseStart ?: 'today');
  $end   = clone $start;

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

function duration_label(string $duration): string {
  $map = [
    '1_month'   => '1 Month',
    '3_months'  => '3 Months',
    '6_months'  => '6 Months',
    '1_year'    => '1 Year',
  ];
  return $map[$duration] ?? $duration;
}

function payment_label(string $payment): string {
  $map = [
    'online' => 'Online Payment',
    'cash'   => 'Cash / Physical Payment',
  ];
  return $map[$payment] ?? $payment;
}

function vehicle_type_label(string $type): string {
  $map = [
    'car' => 'Car',
    'motorcycle' => 'Motorcycle',
    'ebike' => 'E-Bike',
  ];
  return $map[$type] ?? ucfirst($type);
}

function badge($status, $paymentStatus = ''){
  $status = strtolower(trim((string)$status));
  $paymentStatus = strtolower(trim((string)$paymentStatus));

  if ($status === 'active') {
    return '<span class="badge bg-success">Active</span>';
  } elseif ($status === 'pending' && $paymentStatus === 'for payment') {
    return '<span class="badge bg-info text-dark">For Payment</span>';
  } elseif ($status === 'pending') {
    return '<span class="badge bg-warning text-dark">Pending</span>';
  } elseif (in_array($status, ['rejected','revoked','expired'], true)) {
    return '<span class="badge bg-danger">'.htmlspecialchars(ucfirst($status)).'</span>';
  }

  return '<span class="badge bg-secondary">'.htmlspecialchars(ucfirst($status)).'</span>';
}

function payment_status_label(string $status): string {
  $status = strtolower(trim((string)$status));
  if ($status === 'unpaid' || $status === 'not paid') return 'Not Paid';
  if ($status === 'paid') return 'Paid';
  if ($status === 'for payment') return 'For Payment';
  if ($status === 'pending') return 'Pending';
  return ucfirst($status);
}

function days_until_expiry(?string $validUntil): ?int {
  if (empty($validUntil)) return null;
  try {
    $today = new DateTime('today');
    $expiry = new DateTime($validUntil);
    if ($expiry < $today) return null;
    return (int)$today->diff($expiry)->format('%a');
  } catch (Exception $e) {
    return null;
  }
}

function can_renew_now(?string $validUntil, int $daysBeforeExpiry = 30): bool {
  $days = days_until_expiry($validUntil);
  return $days !== null && $days <= $daysBeforeExpiry;
}

function build_contract_html(array $data): string {
  $today = date('F d, Y');

  $hoaName     = esc($data['hoa_name'] ?? 'South Meridian Homes Salitran');
  $fullName    = esc($data['full_name'] ?? '');
  $phase       = esc($data['phase'] ?? '');
  $houseLot    = esc($data['house_lot'] ?? '');
  $plate       = esc($data['plate_no'] ?? '');
  $vehicleType = esc($data['vehicle_type_label'] ?? '');
  $make        = esc($data['vehicle_make'] ?? '');
  $model       = esc($data['vehicle_model'] ?? '');
  $color       = esc($data['vehicle_color'] ?? '');
  $duration    = esc($data['permit_duration_label'] ?? '');
  $payment     = esc($data['payment_method_label'] ?? '');
  $validFrom   = esc($data['valid_from'] ?? '');
  $validTo     = esc($data['valid_until'] ?? '');
  $request     = esc($data['request_type'] ?? '');
  $year        = esc($data['sticker_year'] ?? '');

  return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Parking Permit Contract</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;color:#222;line-height:1.5;margin:30px;}
.wrap{max-width:850px;margin:0 auto;border:1px solid #dcdcdc;padding:32px;border-radius:10px;}
h1,h2,h3,p{margin:0 0 12px;}
h1{font-size:26px;text-align:center;}
h2{font-size:17px;text-align:center;font-weight:normal;color:#444;margin-bottom:24px;}
.tbl{width:100%;border-collapse:collapse;margin:18px 0;}
.tbl td{border:1px solid #ccc;padding:10px;vertical-align:top;}
.label{width:220px;font-weight:bold;background:#f7f7f7;}
.note{margin-top:20px;}
.signatures{margin-top:50px;display:flex;justify-content:space-between;gap:40px;}
.sign-box{width:45%;text-align:center;}
.sign-line{margin-top:55px;border-top:1px solid #333;padding-top:8px;}
.small{font-size:12px;color:#666;}
</style>
</head>
<body>
  <div class="wrap">
    <h1>'.$hoaName.'</h1>
    <h2>Parking Permit Contract / Agreement</h2>

    <p>Date Generated: <strong>'.$today.'</strong></p>
    <p>This document serves as the homeowner\'s parking permit contract copy for online and physical reference.</p>

    <table class="tbl">
      <tr><td class="label">Homeowner Name</td><td>'.$fullName.'</td></tr>
      <tr><td class="label">Phase</td><td>'.$phase.'</td></tr>
      <tr><td class="label">House / Lot</td><td>'.$houseLot.'</td></tr>
      <tr><td class="label">Request Type</td><td>'.ucfirst($request).'</td></tr>
      <tr><td class="label">Permit Year</td><td>'.$year.'</td></tr>
      <tr><td class="label">Plate Number</td><td>'.$plate.'</td></tr>
      <tr><td class="label">Vehicle Type</td><td>'.$vehicleType.'</td></tr>
      <tr><td class="label">Vehicle Brand</td><td>'.$make.'</td></tr>
      <tr><td class="label">Vehicle Model</td><td>'.$model.'</td></tr>
      <tr><td class="label">Vehicle Color</td><td>'.$color.'</td></tr>
      <tr><td class="label">Permit Duration</td><td>'.$duration.'</td></tr>
      <tr><td class="label">Payment Method</td><td>'.$payment.'</td></tr>
      <tr><td class="label">Validity Period</td><td>'.$validFrom.' to '.$validTo.'</td></tr>
    </table>

    <div class="note">
      <p><strong>Agreement:</strong></p>
      <p>By submitting this parking permit request, the homeowner agrees to follow all HOA parking rules, regulations, and policies. The issued permit remains subject to approval and verification by the HOA administration. Any false information, invalid documents, or policy violations may result in rejection, revocation, or disciplinary action.</p>
      <p>This contract copy may be kept online by the homeowner and may also be printed for physical submission or HOA file keeping.</p>
    </div>

    <div class="signatures">
      <div class="sign-box">
        <div class="sign-line">Homeowner Signature</div>
      </div>
      <div class="sign-box">
        <div class="sign-line">Authorized HOA Officer</div>
      </div>
    </div>

    <p class="small" style="margin-top:35px;">System-generated document from '.$hoaName.'.</p>
  </div>
</body>
</html>';
}

function set_msg(&$msg,&$msgType,$t,$m){
  $msgType = $t;
  $msg = $m;
}

$isTenant = ($_SESSION['role'] === 'tenant');
$tenant = null;
$user = null;
$hid = 0;

if ($isTenant) {
  if (empty($_SESSION['tenant_id']) || empty($_SESSION['tenant_homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $tenant_id = (int)$_SESSION['tenant_id'];
  $hid = (int)$_SESSION['tenant_homeowner_id'];

  $stmt = $conn->prepare("
    SELECT id, homeowner_id, first_name, last_name, email, status, phase,
           can_pay_dues, can_rent, can_parking, can_announcements
    FROM tenants
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $tenant_id);
  $stmt->execute();
  $tenant = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$tenant || $tenant['status'] !== 'active') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }

  $stmt = $conn->prepare("
    SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number
    FROM homeowners
    WHERE id=? LIMIT 1
  ");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || $user['status'] !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }

  tenant_guard('parking', $tenant);
} else {
  if (empty($_SESSION['homeowner_id'])) {
    header("Location: ../index.php");
    exit;
  }

  $hid = (int)$_SESSION['homeowner_id'];

  $stmt = $conn->prepare("SELECT id, status, must_change_password, first_name, last_name, phase, house_lot_number
                          FROM homeowners WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $hid);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$user || $user['status'] !== 'approved') {
    session_destroy();
    header("Location: ../index.php");
    exit;
  }

  if ((int)$user['must_change_password'] === 1) {
    header("Location: homeowner_dashboard.php");
    exit;
  }
}

$phase = (string)$user['phase'];
$_SESSION['phase'] = $phase;

if ($isTenant) {
  $fullName = trim(($tenant['first_name'] ?? '') . ' ' . ($tenant['last_name'] ?? ''));
  $initials = strtoupper(substr($tenant['first_name'] ?? 'T',0,1).substr($tenant['last_name'] ?? 'N',0,1));
} else {
  $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
  $initials = strtoupper(substr($user['first_name'] ?? 'H',0,1).substr($user['last_name'] ?? 'O',0,1));
}

$pageTitle = "Apply / Renew Permit • ".$phase;
$yearNow   = (int)date('Y');
$renewalWindowDays = 30;

$activePage = basename($_SERVER['PHP_SELF']);
$parkingOpen = in_array($activePage, ['homeowner_parking.php','homeowner_parking_permit.php'], true);

/*
  Use the real project root instead of assuming the project folder is named "project".
*/
$projectRootFs = dirname(__DIR__);
$adminRootFs   = $projectRootFs . DIRECTORY_SEPARATOR . 'admin';

$parkingUploadFsRoot =
  $adminRootFs .
  DIRECTORY_SEPARATOR .
  'uploads' .
  DIRECTORY_SEPARATOR .
  'parking_permits';

$contractUploadFsRoot =
  $adminRootFs .
  DIRECTORY_SEPARATOR .
  'uploads' .
  DIRECTORY_SEPARATOR .
  'parking_contracts';

/*
  Keep this homeowner's permit lifecycle current.
*/
$stmt = $conn->prepare("
  UPDATE parking_permits
  SET status='expired'
  WHERE homeowner_id=? AND phase=?
    AND status='active'
    AND LOWER(COALESCE(payment_status, 'paid'))='paid'
    AND valid_until IS NOT NULL
    AND valid_until < CURDATE()
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$stmt->close();

/*
  Active permit
*/
$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE homeowner_id=? AND phase=? AND status='active'
    AND LOWER(COALESCE(payment_status, 'paid'))='paid'
    AND valid_from IS NOT NULL
    AND valid_from <= CURDATE()
    AND valid_until >= CURDATE()
  ORDER BY valid_until DESC, id DESC
  LIMIT 1
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$activePermit = $stmt->get_result()->fetch_assoc();
$stmt->close();

/*
  Paid renewal / permit that is scheduled to start in the future.
  It remains status='active' in the database, but it is NOT treated
  as the currently effective permit until valid_from arrives.
*/
$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE homeowner_id=? AND phase=? AND status='active'
    AND LOWER(COALESCE(payment_status, 'paid'))='paid'
    AND valid_from IS NOT NULL
    AND valid_from > CURDATE()
    AND valid_until >= valid_from
  ORDER BY valid_from ASC, id ASC
  LIMIT 1
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$upcomingPermit = $stmt->get_result()->fetch_assoc();
$stmt->close();

/*
  Current status card
*/
$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE homeowner_id=? AND phase=?
    AND (
      (status='pending' AND LOWER(COALESCE(payment_status, 'unpaid')) IN ('unpaid', 'not paid', 'pending'))
      OR
      (status='pending' AND LOWER(COALESCE(payment_status, 'unpaid'))='for payment')
    )
  ORDER BY id DESC
  LIMIT 1
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$currentStatusPermit = $stmt->get_result()->fetch_assoc();
$stmt->close();

$hasOpenRequest = !empty($currentStatusPermit);

/*
  Latest rejected / revoked / expired permit record.
  This is informational only and does not block a new application.
*/
$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE homeowner_id=? AND phase=?
    AND status IN ('rejected','revoked','expired')
  ORDER BY COALESCE(updated_at, approved_at, requested_at) DESC, id DESC
  LIMIT 1
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$lastClosedPermit = $stmt->get_result()->fetch_assoc();
$stmt->close();

$renewPermit = null;
$renewPermitId = (int)($_GET['renew_id'] ?? 0);
$renewAllowed = false;
$renewDaysRemaining = null;
$scheduledRenewalForSelected = null;

if ($renewPermitId > 0) {
  $stmt = $conn->prepare("
    SELECT *
    FROM parking_permits
    WHERE id=? AND homeowner_id=? AND phase=? AND status='active'
      AND LOWER(COALESCE(payment_status, 'paid'))='paid'
      AND valid_from IS NOT NULL
      AND valid_from <= CURDATE()
      AND valid_until >= CURDATE()
    LIMIT 1
  ");
  $stmt->bind_param("iis", $renewPermitId, $hid, $phase);
  $stmt->execute();
  $renewPermit = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($renewPermit) {
    $renewDaysRemaining = days_until_expiry($renewPermit['valid_until'] ?? null);
    $renewAllowed = can_renew_now($renewPermit['valid_until'] ?? null, $renewalWindowDays);

    if ($renewAllowed) {
      $stmt = $conn->prepare("
        SELECT id, permit_no, valid_from, valid_until
        FROM parking_permits
        WHERE homeowner_id=? AND phase=?
          AND request_type='renew'
          AND renew_of_id=?
          AND status='active'
          AND LOWER(COALESCE(payment_status, 'paid'))='paid'
          AND valid_from > CURDATE()
        ORDER BY valid_from ASC, id ASC
        LIMIT 1
      ");
      $stmt->bind_param("isi", $hid, $phase, $renewPermitId);
      $stmt->execute();
      $scheduledRenewalForSelected = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if ($scheduledRenewalForSelected) {
        $renewAllowed = false;
      }
    }
  }
}

$msg = "";
$msgType = "success";

/* CSRF protection for permit applications and renewals. */
if (empty($_SESSION['csrf_parking_permit'])) {
  $_SESSION['csrf_parking_permit'] = bin2hex(random_bytes(32));
}
$csrfParkingPermit = (string)$_SESSION['csrf_parking_permit'];

if ($renewPermitId > 0) {
  if (!$renewPermit) {
    set_msg($msg, $msgType, "danger", "Invalid renewal request.");
  } elseif ($scheduledRenewalForSelected) {
    set_msg(
      $msg,
      $msgType,
      "warning",
      "This permit already has a paid renewal scheduled to start on " .
      ($scheduledRenewalForSelected['valid_from'] ?? 'the scheduled date') .
      "."
    );
  } elseif (!$renewAllowed) {
    set_msg($msg, $msgType, "warning", "Renewal is only allowed within {$renewalWindowDays} days before permit expiration.");
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_permit'])) {
  $postedCsrf = (string)($_POST['csrf_token'] ?? '');

  if ($postedCsrf === '' || !hash_equals($csrfParkingPermit, $postedCsrf)) {
    set_msg(
      $msg,
      $msgType,
      "danger",
      "Your session token is no longer valid. Please refresh the page and try again."
    );
  } else {
    $stmt = $conn->prepare("
      SELECT *
      FROM parking_permits
      WHERE homeowner_id=? AND phase=?
        AND (
          (status='pending' AND LOWER(COALESCE(payment_status, 'unpaid')) IN ('unpaid', 'not paid', 'pending'))
          OR
          (status='pending' AND LOWER(COALESCE(payment_status, 'unpaid'))='for payment')
        )
      ORDER BY id DESC
      LIMIT 1
    ");
    $stmt->bind_param("is", $hid, $phase);
    $stmt->execute();
    $latestOpenCheck = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $requestRenewId = (int)($_POST['renew_of_id'] ?? 0);
    $renewBasePermit = null;
    $isRenewalRequest = false;

    if ($requestRenewId > 0) {
      $stmt = $conn->prepare("
        SELECT *
        FROM parking_permits
        WHERE id=? AND homeowner_id=? AND phase=? AND status='active'
          AND LOWER(COALESCE(payment_status, 'paid'))='paid'
          AND valid_from IS NOT NULL
          AND valid_from <= CURDATE()
          AND valid_until >= CURDATE()
        LIMIT 1
      ");
      $stmt->bind_param("iis", $requestRenewId, $hid, $phase);
      $stmt->execute();
      $renewBasePermit = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (
        $renewBasePermit &&
        can_renew_now($renewBasePermit['valid_until'] ?? null, $renewalWindowDays)
      ) {
        $stmt = $conn->prepare("
          SELECT id, valid_from
          FROM parking_permits
          WHERE homeowner_id=? AND phase=?
            AND request_type='renew'
            AND renew_of_id=?
            AND status='active'
            AND LOWER(COALESCE(payment_status, 'paid'))='paid'
            AND valid_from > CURDATE()
          ORDER BY valid_from ASC, id ASC
          LIMIT 1
        ");
        $stmt->bind_param("isi", $hid, $phase, $requestRenewId);
        $stmt->execute();
        $existingScheduledRenewal = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existingScheduledRenewal) {
          set_msg(
            $msg,
            $msgType,
            "warning",
            "This permit already has a paid renewal scheduled to start on " .
            ($existingScheduledRenewal['valid_from'] ?? 'the scheduled date') .
            "."
          );
        } else {
          $isRenewalRequest = true;
        }
      } else {
        set_msg(
          $msg,
          $msgType,
          "warning",
          "Renewal is only allowed within {$renewalWindowDays} days before permit expiration."
        );
      }
    }

    if ($latestOpenCheck) {
      $latestPaymentStatus = strtolower(trim((string)($latestOpenCheck['payment_status'] ?? 'unpaid')));

      if ($latestPaymentStatus === 'for payment') {
        set_msg(
          $msg,
          $msgType,
          "warning",
          "Your previous parking permit request is already approved and waiting for payment. Please finish that first."
        );
      } else {
        set_msg(
          $msg,
          $msgType,
          "warning",
          "Please wait for your previous parking permit application to be approved first."
        );
      }

      $currentStatusPermit = $latestOpenCheck;
      $hasOpenRequest = true;

    } elseif ($requestRenewId > 0 && !$isRenewalRequest) {
      // Renewal validation already produced the page message above.

    } else {
      $plate       = strtoupper(trim((string)($_POST['plate_no'] ?? '')));
      $vehicleType = strtolower(trim((string)($_POST['vehicle_type'] ?? '')));
      $make        = trim((string)($_POST['vehicle_make'] ?? ''));
      $model       = trim((string)($_POST['vehicle_model'] ?? ''));
      $color       = trim((string)($_POST['vehicle_color'] ?? ''));
      $duration    = (string)($_POST['permit_duration'] ?? '');
      $payment     = (string)($_POST['payment_method'] ?? '');

      if ($plate === '' || strlen($plate) < 4) {
        set_msg($msg, $msgType, "danger", "Please enter a valid plate number.");

      } elseif (!in_array($vehicleType, ['car','motorcycle','ebike'], true)) {
        set_msg($msg, $msgType, "danger", "Please select a valid vehicle type.");

      } elseif (!in_array($duration, ['1_month','3_months','6_months','1_year'], true)) {
        set_msg($msg, $msgType, "danger", "Please select a valid permit duration.");

      } elseif (!in_array($payment, ['online','cash'], true)) {
        set_msg($msg, $msgType, "danger", "Please select a valid payment method.");

      } else {
        $requestType = $isRenewalRequest ? 'renew' : 'new';
        $renewOfId   = $isRenewalRequest ? (int)$renewBasePermit['id'] : null;

        /*
          These dates are provisional application/contract preview dates only.
          The final validity period will be recalculated when payment is completed
          and the permit is activated by the admin or verified PayMongo webhook.
        */
        if ($isRenewalRequest && !empty($renewBasePermit['valid_until'])) {
          $baseStart =
            (new DateTime($renewBasePermit['valid_until']))
              ->modify('+1 day')
              ->format('Y-m-d');
        } else {
          $baseStart = date('Y-m-d');
        }

        [$previewFrom, $previewUntil] = computePermitDates($duration, $baseStart);

        $parkingFsDir = $parkingUploadFsRoot;
        $parkingDbDir = 'uploads/parking_permits';
        $createdFiles = [];

        $frontUpload = save_upload('vehicle_front', $parkingFsDir, $parkingDbDir);
        if (!empty($frontUpload['fs_path'])) {
          $createdFiles[] = $frontUpload['fs_path'];
        }

        $backUpload = save_upload('vehicle_back', $parkingFsDir, $parkingDbDir);
        if (!empty($backUpload['fs_path'])) {
          $createdFiles[] = $backUpload['fs_path'];
        }

        $uploadErrors = [];

        if (empty($frontUpload['db_path'])) {
          $uploadErrors[] =
            "Vehicle Front Picture: " .
            ($frontUpload['error'] ?: 'Invalid upload.');
        }

        if (empty($backUpload['db_path'])) {
          $uploadErrors[] =
            "Vehicle Back Picture: " .
            ($backUpload['error'] ?: 'Invalid upload.');
        }

        if ($uploadErrors) {
          cleanup_created_files($createdFiles);
          set_msg($msg, $msgType, "danger", implode(' ', $uploadErrors));

        } else {
          $vehicle_front_path = (string)$frontUpload['db_path'];
          $vehicle_back_path  = (string)$backUpload['db_path'];

          $contractFsDir = $contractUploadFsRoot;
          $contractDbDir = 'uploads/parking_contracts';

          $contractHtml = build_contract_html([
            'hoa_name'               => 'South Meridian Homes Salitran',
            'full_name'              => $fullName,
            'phase'                  => $phase,
            'house_lot'              => $user['house_lot_number'] ?? '',
            'request_type'           => $requestType,
            'sticker_year'           => $yearNow,
            'plate_no'               => $plate,
            'vehicle_type_label'     => vehicle_type_label($vehicleType),
            'vehicle_make'           => $make,
            'vehicle_model'          => $model,
            'vehicle_color'          => $color,
            'permit_duration_label'  => duration_label($duration),
            'payment_method_label'   => payment_label($payment),
            'valid_from'             => $previewFrom,
            'valid_until'            => $previewUntil,
          ]);

          $contractUpload = write_contract_file($contractHtml, $contractFsDir, $contractDbDir);

          if (!empty($contractUpload['fs_path'])) {
            $createdFiles[] = $contractUpload['fs_path'];
          }

          $contractPath = (string)($contractUpload['db_path'] ?? '');

          if ($contractPath === '') {
            cleanup_created_files($createdFiles);
            set_msg(
              $msg,
              $msgType,
              "danger",
              "Failed to generate parking permit contract."
            );

          } else {
            $validFrom     = $previewFrom;
            $validUntil    = $previewUntil;
            $paymentStatus = 'unpaid';

            $stmt = $conn->prepare("
              INSERT INTO parking_permits
              (
                homeowner_id,
                request_type,
                renew_of_id,
                phase,
                plate_no,
                vehicle_type,
                vehicle_make,
                vehicle_model,
                vehicle_color,
                sticker_year,
                permit_duration,
                payment_method,
                valid_from,
                valid_until,
                payment_status,
                status,
                vehicle_front_path,
                vehicle_back_path,
                contract_path,
                requested_at
              )
              VALUES
              (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW()
              )
            ");

            if (!$stmt) {
              cleanup_created_files($createdFiles);
              error_log('Parking permit insert prepare failed: ' . $conn->error);
              set_msg(
                $msg,
                $msgType,
                "danger",
                "Failed to submit the parking permit request. Please try again."
              );

            } else {
              /* Correct type mapping for all 18 bound values. */
              $stmt->bind_param(
                "isissssssissssssss",
                $hid,
                $requestType,
                $renewOfId,
                $phase,
                $plate,
                $vehicleType,
                $make,
                $model,
                $color,
                $yearNow,
                $duration,
                $payment,
                $validFrom,
                $validUntil,
                $paymentStatus,
                $vehicle_front_path,
                $vehicle_back_path,
                $contractPath
              );

              $ok = $stmt->execute();
              $dbError = $stmt->error;
              $stmt->close();

              if (!$ok) {
                cleanup_created_files($createdFiles);
                error_log('Parking permit insert failed: ' . $dbError);
                set_msg(
                  $msg,
                  $msgType,
                  "danger",
                  "Failed to submit the parking permit request. Please try again."
                );
              } else {
                header("Location: homeowner_parking_permit.php?ok=1");
                exit;
              }
            }
          }
        }
      }
    }
  }
}

if (isset($_GET['ok'])) {
  $msgType = "success";
  $msg = "Permit request submitted successfully. Please wait for HOA admin approval before payment.";
}

if (isset($_GET['paid'])) {
  $msgType = "success";
  $msg = "Online payment recorded successfully.";
}

if (isset($_GET['cancelled'])) {
  $msgType = "warning";
  $msg = "Online payment was cancelled or not completed. You may continue payment after admin approval.";
}

if (isset($_GET['waiting_approval'])) {
  $msgType = "warning";
  $msg = "Your permit is not yet open for online payment. Please wait for admin approval first.";
}

if (isset($_GET['rejected'])) {
  $msgType = "danger";
  $msg = "This permit request was rejected.";
}

if (isset($_GET['revoked'])) {
  $msgType = "danger";
  $msg = "This permit has been revoked.";
}

if (isset($_GET['expired'])) {
  $msgType = "warning";
  $msg = "This permit has already expired.";
}

$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE homeowner_id=? AND phase=?
    AND (
      (status='pending' AND LOWER(COALESCE(payment_status, 'unpaid')) IN ('unpaid', 'not paid', 'pending'))
      OR
      (status='pending' AND LOWER(COALESCE(payment_status, 'unpaid'))='for payment')
    )
  ORDER BY id DESC
  LIMIT 1
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$currentStatusPermit = $stmt->get_result()->fetch_assoc();
$stmt->close();

$hasOpenRequest = !empty($currentStatusPermit);

$chatPages = ['homeowner_public_chat.php'];
$chatOpen = in_array($activePage, $chatPages, true);


function permit_status_tailwind(string $status, string $paymentStatus = ''): string {
  $status = strtolower(trim($status));
  $paymentStatus = strtolower(trim($paymentStatus));

  if ($status === 'active') {
    return 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900';
  }
  if ($status === 'pending' && $paymentStatus === 'for payment') {
    return 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:ring-blue-900';
  }
  if ($status === 'pending') {
    return 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900';
  }
  if (in_array($status, ['rejected','revoked','expired'], true)) {
    return 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900';
  }
  return 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700';
}

function permit_status_text(string $status, string $paymentStatus = ''): string {
  $status = strtolower(trim($status));
  $paymentStatus = strtolower(trim($paymentStatus));

  if ($status === 'pending' && $paymentStatus === 'for payment') return 'For Payment';
  return $status !== '' ? ucfirst($status) : 'Unknown';
}

function permit_status_icon(string $status, string $paymentStatus = ''): string {
  $status = strtolower(trim($status));
  $paymentStatus = strtolower(trim($paymentStatus));

  if ($status === 'active') return 'bi-check-circle-fill';
  if ($status === 'pending' && $paymentStatus === 'for payment') return 'bi-credit-card-fill';
  if ($status === 'pending') return 'bi-clock-fill';
  if ($status === 'rejected') return 'bi-x-circle-fill';
  if ($status === 'revoked') return 'bi-slash-circle-fill';
  if ($status === 'expired') return 'bi-calendar-x-fill';
  return 'bi-info-circle-fill';
}

function payment_status_tailwind(string $status): string {
  $status = strtolower(trim($status));

  if ($status === 'paid') {
    return 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900';
  }
  if ($status === 'for payment') {
    return 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:ring-blue-900';
  }
  if ($status === 'pending') {
    return 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900';
  }
  return 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900';
}

$houseLot = (string)($user['house_lot_number'] ?? '');

$currStatus = strtolower(trim((string)($currentStatusPermit['status'] ?? '')));
$currPaymentStatus = strtolower(trim((string)($currentStatusPermit['payment_status'] ?? '')));

$prefillPlate = $renewPermit['plate_no'] ?? '';
$prefillMake = $renewPermit['vehicle_make'] ?? '';
$prefillModel = $renewPermit['vehicle_model'] ?? '';
$prefillColor = $renewPermit['vehicle_color'] ?? '';
$prefillVehicleType = $renewPermit['vehicle_type'] ?? '';
$nextStartDate = '';

if ($renewPermit && !empty($renewPermit['valid_until'])) {
  $nextStartDate = (new DateTime($renewPermit['valid_until']))->modify('+1 day')->format('Y-m-d');
}

$activePermitDaysRemaining = $activePermit
  ? days_until_expiry($activePermit['valid_until'] ?? null)
  : null;

$activePermitHasScheduledRenewal =
  $activePermit &&
  $upcomingPermit &&
  (int)($upcomingPermit['renew_of_id'] ?? 0) === (int)($activePermit['id'] ?? 0);

$msgUi = [
  'success' => [
    'wrap' => 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40',
    'iconWrap' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    'text' => 'text-emerald-800 dark:text-emerald-300',
    'title' => 'text-emerald-900 dark:text-emerald-200',
    'icon' => 'bi-check-circle-fill',
  ],
  'danger' => [
    'wrap' => 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40',
    'iconWrap' => 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
    'text' => 'text-red-800 dark:text-red-300',
    'title' => 'text-red-900 dark:text-red-200',
    'icon' => 'bi-x-circle-fill',
  ],
  'warning' => [
    'wrap' => 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40',
    'iconWrap' => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    'text' => 'text-amber-800 dark:text-amber-300',
    'title' => 'text-amber-900 dark:text-amber-200',
    'icon' => 'bi-exclamation-triangle-fill',
  ],
];

$currentMsgUi = $msgUi[$msgType] ?? $msgUi['warning'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>
<meta charset="UTF-8">
<title><?= esc($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<script>
(function () {
    const savedTheme = localStorage.getItem('hoa-theme');
    const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const useDark = savedTheme === 'dark' || (!savedTheme && systemDark);
    document.documentElement.classList.toggle('dark', useDark);
})();
</script>

<style type="text/tailwindcss">
    @custom-variant dark (&:where(.dark, .dark *));
</style>

<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css"
>
</head>

<body
    class="
        bg-slate-50
        text-slate-900
        antialiased
        transition-colors
        duration-200
        dark:bg-slate-950
        dark:text-slate-100
    "
>

<div
    id="sidebarOverlay"
    class="
        fixed inset-0 z-50
        hidden
        bg-slate-950/50
        backdrop-blur-[1px]
        lg:hidden
    "
></div>

<?php include 'homeowner_sidebar.php'; ?>

<div class="min-h-screen lg:ml-[280px]">

    <header
        class="
            sticky top-0 z-40
            border-b border-slate-200
            bg-white/95
            backdrop-blur
            transition-colors
            dark:border-slate-800
            dark:bg-slate-900/95
        "
    >
        <div
            class="
                mx-auto
                flex min-h-[72px] max-w-7xl
                items-center gap-3
                px-4 sm:px-6
            "
        >
            <button
                type="button"
                id="sidebarToggle"
                class="
                    flex h-12 w-12 shrink-0
                    items-center justify-center
                    rounded-xl
                    border border-slate-200
                    bg-white
                    text-2xl text-slate-700
                    shadow-sm transition
                    hover:bg-slate-50
                    focus:outline-none focus:ring-4 focus:ring-emerald-100
                    dark:border-slate-700
                    dark:bg-slate-800
                    dark:text-slate-200
                    dark:hover:bg-slate-700
                    dark:focus:ring-emerald-950
                    lg:hidden
                "
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <a
                href="homeowner_dashboard.php"
                class="min-w-0"
            >
                <div
                    class="
                        truncate
                        text-base font-bold text-emerald-800
                        sm:text-lg
                        dark:text-emerald-300
                    "
                >
                    HOA Community
                </div>

                <div
                    class="
                        hidden
                        text-xs font-medium text-slate-500
                        sm:block
                        dark:text-slate-400
                    "
                >
                    South Meridian Homes Salitran
                </div>
            </a>

            <div class="ml-auto flex items-center gap-2 sm:gap-3">

                <button
                    type="button"
                    id="themeToggle"
                    class="
                        flex h-12 w-12 shrink-0
                        items-center justify-center
                        rounded-xl
                        border border-slate-200
                        bg-white
                        text-xl text-slate-700
                        shadow-sm transition
                        hover:border-emerald-200
                        hover:bg-emerald-50
                        hover:text-emerald-800
                        focus:outline-none focus:ring-4 focus:ring-emerald-100
                        dark:border-slate-700
                        dark:bg-slate-900
                        dark:text-slate-200
                        dark:hover:border-slate-600
                        dark:hover:bg-slate-800
                        dark:hover:text-emerald-300
                        dark:focus:ring-emerald-950
                    "
                    aria-label="Switch to dark mode"
                    title="Switch to dark mode"
                >
                    <i id="themeIcon" class="bi bi-moon-stars-fill"></i>
                </button>

                <div class="hidden text-right md:block">
                    <div
                        class="
                            max-w-[220px] truncate
                            text-sm font-bold text-slate-800
                            dark:text-slate-200
                        "
                    >
                        <?= esc($fullName) ?>
                    </div>

                    <div
                        class="
                            text-xs font-medium text-slate-500
                            dark:text-slate-400
                        "
                    >
                        <?= esc($phase) ?>
                        <?= $isTenant ? ' • Tenant' : '' ?>
                    </div>
                </div>

                <a
                    href="logout.php"
                    class="
                        flex min-h-12
                        items-center justify-center gap-2
                        rounded-xl
                        border border-slate-200
                        bg-white
                        px-3
                        text-sm font-semibold text-slate-700
                        transition
                        hover:border-red-200
                        hover:bg-red-50
                        hover:text-red-700
                        sm:px-4
                        dark:border-slate-700
                        dark:bg-slate-900
                        dark:text-slate-300
                        dark:hover:border-red-900
                        dark:hover:bg-red-950/40
                        dark:hover:text-red-300
                    "
                >
                    <i class="bi bi-box-arrow-right text-lg"></i>
                    <span class="hidden sm:inline">Logout</span>
                </a>
            </div>
        </div>
    </header>

    <main
        class="
            mx-auto max-w-7xl
            px-4 py-6
            sm:px-6 sm:py-8
        "
    >

        <section class="mb-6">
            <a
                href="homeowner_parking.php"
                class="
                    inline-flex items-center gap-2
                    text-sm font-semibold text-emerald-700
                    hover:text-emerald-800
                    dark:text-emerald-400
                    dark:hover:text-emerald-300
                "
            >
                <i class="bi bi-arrow-left"></i>
                Parking Overview
            </a>

            <div
                class="
                    mt-3
                    flex flex-col gap-3
                    sm:flex-row
                    sm:items-end
                    sm:justify-between
                "
            >
                <div>
                    <p
                        class="
                            text-sm font-semibold text-emerald-700
                            dark:text-emerald-400
                        "
                    >
                        Vehicle & Permit Management
                    </p>

                    <h1
                        class="
                            mt-1
                            text-2xl font-bold tracking-tight text-slate-900
                            sm:text-3xl
                            dark:text-slate-100
                        "
                    >
                        Apply / Renew Parking Permit
                    </h1>

                    <p
                        class="
                            mt-2 max-w-2xl
                            text-[15px] leading-6 text-slate-600
                            dark:text-slate-400
                        "
                    >
                        Submit a new parking permit application or renew
                        an eligible active permit.
                    </p>
                </div>

                <div
                    class="
                        inline-flex w-fit items-center gap-2
                        rounded-xl
                        bg-white
                        px-3 py-2
                        text-sm font-semibold text-slate-600
                        ring-1 ring-slate-200
                        dark:bg-slate-900
                        dark:text-slate-300
                        dark:ring-slate-700
                    "
                >
                    <i class="bi bi-geo-alt-fill text-emerald-700 dark:text-emerald-400"></i>
                    <?= esc($phase) ?> • <?= esc($houseLot) ?>
                </div>
            </div>
        </section>

        <?php if ($msg !== ''): ?>
            <div
                class="
                    page-flash
                    mb-5
                    flex items-start gap-3
                    rounded-2xl
                    border
                    p-4
                    <?= $currentMsgUi['wrap'] ?>
                "
            >
                <div
                    class="
                        flex h-10 w-10 shrink-0
                        items-center justify-center
                        rounded-xl
                        text-lg
                        <?= $currentMsgUi['iconWrap'] ?>
                    "
                >
                    <i class="bi <?= esc($currentMsgUi['icon']) ?>"></i>
                </div>

                <div class="min-w-0 flex-1">
                    <p class="font-bold <?= $currentMsgUi['title'] ?>">
                        Parking Permit Update
                    </p>
                    <p class="mt-1 text-sm leading-6 <?= $currentMsgUi['text'] ?>">
                        <?= esc($msg) ?>
                    </p>
                </div>

                <button
                    type="button"
                    class="
                        btn-close-flash
                        flex h-9 w-9 shrink-0
                        items-center justify-center
                        rounded-lg
                        text-slate-500
                        transition
                        hover:bg-black/5
                        dark:text-slate-400
                        dark:hover:bg-white/10
                    "
                    aria-label="Close message"
                >
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        <?php endif; ?>

        <section
            class="
                mb-6
                grid gap-4
                sm:grid-cols-2
                xl:grid-cols-4
            "
        >
            <div
                class="
                    rounded-2xl border border-slate-200
                    bg-white p-5 shadow-sm
                    dark:border-slate-800 dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex h-11 w-11 items-center justify-center
                        rounded-xl bg-emerald-100
                        text-xl text-emerald-700
                        dark:bg-emerald-950/60 dark:text-emerald-300
                    "
                >
                    <i class="bi bi-calendar-check-fill"></i>
                </div>
                <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">
                    Sticker Year
                </p>
                <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100">
                    <?= (int)$yearNow ?>
                </p>
            </div>

            <div
                class="
                    rounded-2xl border border-slate-200
                    bg-white p-5 shadow-sm
                    dark:border-slate-800 dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex h-11 w-11 items-center justify-center
                        rounded-xl bg-blue-100
                        text-xl text-blue-700
                        dark:bg-blue-950/60 dark:text-blue-300
                    "
                >
                    <i class="bi bi-card-checklist"></i>
                </div>
                <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">
                    Active Permit
                </p>
                <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100">
                    <?= $activePermit ? 'Yes' : 'None' ?>
                </p>
            </div>

            <div
                class="
                    rounded-2xl border border-slate-200
                    bg-white p-5 shadow-sm
                    dark:border-slate-800 dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex h-11 w-11 items-center justify-center
                        rounded-xl bg-amber-100
                        text-xl text-amber-700
                        dark:bg-amber-950/60 dark:text-amber-300
                    "
                >
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">
                    Open Request
                </p>
                <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100">
                    <?= $hasOpenRequest ? 'Yes' : 'None' ?>
                </p>
            </div>

            <div
                class="
                    rounded-2xl border border-slate-200
                    bg-white p-5 shadow-sm
                    dark:border-slate-800 dark:bg-slate-900
                "
            >
                <div
                    class="
                        flex h-11 w-11 items-center justify-center
                        rounded-xl bg-violet-100
                        text-xl text-violet-700
                        dark:bg-violet-950/60 dark:text-violet-300
                    "
                >
                    <i class="bi bi-arrow-repeat"></i>
                </div>
                <p class="mt-4 text-sm font-semibold text-slate-500 dark:text-slate-400">
                    Renewal Window
                </p>
                <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100">
                    <?= (int)$renewalWindowDays ?>
                    <span class="text-base font-semibold text-slate-400 dark:text-slate-500">days</span>
                </p>
            </div>
        </section>

        <div
            class="
                grid gap-6
                xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]
            "
        >
            <div class="space-y-6">

                <section
                    class="
                        overflow-hidden
                        rounded-2xl
                        border border-slate-200
                        bg-white
                        shadow-sm
                        dark:border-slate-800
                        dark:bg-slate-900
                    "
                >
                    <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                        <h2 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-slate-100">
                            <i class="bi bi-activity text-emerald-700 dark:text-emerald-400"></i>
                            Current Status
                        </h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Track the latest parking permit request for this account.
                        </p>
                    </div>

                    <div class="p-5">

                        <?php if ($currentStatusPermit && $currStatus === 'pending' && $currPaymentStatus !== 'for payment'): ?>

                            <div
                                class="
                                    rounded-2xl
                                    border border-amber-200
                                    bg-amber-50
                                    p-4
                                    dark:border-amber-900
                                    dark:bg-amber-950/40
                                "
                            >
                                <div class="flex items-start gap-3">
                                    <div
                                        class="
                                            flex h-11 w-11 shrink-0
                                            items-center justify-center
                                            rounded-xl
                                            bg-amber-100
                                            text-lg text-amber-700
                                            dark:bg-amber-950
                                            dark:text-amber-300
                                        "
                                    >
                                        <i class="bi bi-clock-fill"></i>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-bold text-amber-900 dark:text-amber-200">
                                            Application Submitted
                                        </h3>
                                        <p class="mt-1 text-sm leading-6 text-amber-800 dark:text-amber-300">
                                            Your request is waiting for HOA admin approval.
                                        </p>
                                    </div>
                                </div>

                                <div
                                    class="
                                        mt-4 grid gap-3
                                        rounded-xl bg-white/80 p-4
                                        text-sm
                                        sm:grid-cols-2
                                        dark:bg-slate-900/60
                                    "
                                >
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Plate</p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($currentStatusPermit['plate_no'] ?? '—') ?>
                                        </p>
                                    </div>

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Vehicle Type</p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc(vehicle_type_label((string)($currentStatusPermit['vehicle_type'] ?? 'car'))) ?>
                                        </p>
                                    </div>

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Status</p>
                                        <span
                                            class="
                                                mt-1 inline-flex items-center gap-1.5
                                                rounded-lg px-2.5 py-1
                                                text-xs font-bold ring-1
                                                <?= permit_status_tailwind(
                                                    (string)($currentStatusPermit['status'] ?? 'pending'),
                                                    (string)($currentStatusPermit['payment_status'] ?? 'unpaid')
                                                ) ?>
                                            "
                                        >
                                            <i class="bi <?= esc(permit_status_icon(
                                                (string)($currentStatusPermit['status'] ?? 'pending'),
                                                (string)($currentStatusPermit['payment_status'] ?? 'unpaid')
                                            )) ?>"></i>
                                            <?= esc(permit_status_text(
                                                (string)($currentStatusPermit['status'] ?? 'pending'),
                                                (string)($currentStatusPermit['payment_status'] ?? 'unpaid')
                                            )) ?>
                                        </span>
                                    </div>

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Payment</p>
                                        <span
                                            class="
                                                mt-1 inline-flex items-center
                                                rounded-lg px-2.5 py-1
                                                text-xs font-bold ring-1
                                                <?= payment_status_tailwind(
                                                    (string)($currentStatusPermit['payment_status'] ?? 'unpaid')
                                                ) ?>
                                            "
                                        >
                                            <?= esc(payment_status_label(
                                                (string)($currentStatusPermit['payment_status'] ?? 'unpaid')
                                            )) ?>
                                        </span>
                                    </div>

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Requested At</p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($currentStatusPermit['requested_at'] ?? '—') ?>
                                        </p>
                                    </div>

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Sticker Year</p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($currentStatusPermit['sticker_year'] ?? '—') ?>
                                        </p>
                                    </div>
                                </div>

                                <?php if (!empty($currentStatusPermit['contract_path'])): ?>
                                    <a
                                        href="homeowner_contract.php?permit_id=<?= (int)$currentStatusPermit['id'] ?>"
                                        class="
                                            mt-4 inline-flex min-h-11
                                            items-center justify-center gap-2
                                            rounded-xl
                                            border border-emerald-200
                                            bg-white px-4
                                            text-sm font-semibold text-emerald-700
                                            transition
                                            hover:bg-emerald-50
                                            dark:border-emerald-900
                                            dark:bg-slate-900
                                            dark:text-emerald-300
                                            dark:hover:bg-emerald-950/40
                                        "
                                    >
                                        <i class="bi bi-file-earmark-text-fill"></i>
                                        Contract Copy
                                    </a>
                                <?php endif; ?>
                            </div>

                        <?php elseif ($currentStatusPermit && $currStatus === 'pending' && $currPaymentStatus === 'for payment'): ?>

                            <div
                                class="
                                    rounded-2xl
                                    border border-blue-200
                                    bg-blue-50
                                    p-4
                                    dark:border-blue-900
                                    dark:bg-blue-950/40
                                "
                            >
                                <div class="flex items-start gap-3">
                                    <div
                                        class="
                                            flex h-11 w-11 shrink-0
                                            items-center justify-center
                                            rounded-xl
                                            bg-blue-100
                                            text-lg text-blue-700
                                            dark:bg-blue-950
                                            dark:text-blue-300
                                        "
                                    >
                                        <i class="bi bi-credit-card-fill"></i>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-bold text-blue-900 dark:text-blue-200">
                                            Approved — Payment Required
                                        </h3>
                                        <p class="mt-1 text-sm leading-6 text-blue-800 dark:text-blue-300">
                                            Your permit request was approved. Complete payment to activate it.
                                        </p>
                                    </div>
                                </div>

                                <div
                                    class="
                                        mt-4 grid gap-3
                                        rounded-xl bg-white/80 p-4
                                        text-sm
                                        sm:grid-cols-2
                                        dark:bg-slate-900/60
                                    "
                                >
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Plate</p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($currentStatusPermit['plate_no'] ?? '—') ?>
                                        </p>
                                    </div>

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Vehicle Type</p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc(vehicle_type_label((string)($currentStatusPermit['vehicle_type'] ?? 'car'))) ?>
                                        </p>
                                    </div>

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Duration</p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc(duration_label((string)($currentStatusPermit['permit_duration'] ?? ''))) ?>
                                        </p>
                                    </div>

                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Payment Method</p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc(payment_label((string)($currentStatusPermit['payment_method'] ?? ''))) ?>
                                        </p>
                                    </div>

                                    <div class="sm:col-span-2">
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Validity</p>
                                        <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                            <?= esc($currentStatusPermit['valid_from'] ?? '—') ?>
                                            →
                                            <?= esc($currentStatusPermit['valid_until'] ?? '—') ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                                    <?php if (!empty($currentStatusPermit['contract_path'])): ?>
                                        <a
                                            href="homeowner_contract.php?permit_id=<?= (int)$currentStatusPermit['id'] ?>"
                                            class="
                                                inline-flex min-h-11
                                                items-center justify-center gap-2
                                                rounded-xl
                                                border border-emerald-200
                                                bg-white px-4
                                                text-sm font-semibold text-emerald-700
                                                transition
                                                hover:bg-emerald-50
                                                dark:border-emerald-900
                                                dark:bg-slate-900
                                                dark:text-emerald-300
                                                dark:hover:bg-emerald-950/40
                                            "
                                        >
                                            <i class="bi bi-file-earmark-text-fill"></i>
                                            Contract Copy
                                        </a>
                                    <?php endif; ?>

                                    <?php if (strtolower(trim((string)($currentStatusPermit['payment_method'] ?? ''))) === 'online'): ?>
                                        <a
                                            href="paymongo_parking_checkout.php?permit_id=<?= (int)$currentStatusPermit['id'] ?>"
                                            class="
                                                inline-flex min-h-11
                                                items-center justify-center gap-2
                                                rounded-xl
                                                bg-blue-700 px-4
                                                text-sm font-semibold text-white
                                                transition
                                                hover:bg-blue-800
                                                dark:bg-blue-600
                                                dark:hover:bg-blue-500
                                            "
                                        >
                                            <i class="bi bi-credit-card-fill"></i>
                                            Pay Online Now
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <?php if (strtolower(trim((string)($currentStatusPermit['payment_method'] ?? ''))) === 'cash'): ?>
                                    <div
                                        class="
                                            mt-4 flex items-start gap-3
                                            rounded-xl
                                            border border-blue-200
                                            bg-white/70
                                            p-3
                                            text-sm leading-6 text-blue-800
                                            dark:border-blue-900
                                            dark:bg-slate-900/50
                                            dark:text-blue-300
                                        "
                                    >
                                        <i class="bi bi-building-fill mt-0.5 shrink-0"></i>
                                        <span>
                                            Please complete your cash / physical payment at the HOA office.
                                            Your permit becomes active after the payment is recorded.
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                        <?php else: ?>

                            <div
                                class="
                                    flex flex-col items-center justify-center
                                    px-4 py-8
                                    text-center
                                "
                            >
                                <div
                                    class="
                                        flex h-14 w-14 items-center justify-center
                                        rounded-2xl
                                        bg-slate-100
                                        text-2xl text-slate-400
                                        dark:bg-slate-800
                                        dark:text-slate-500
                                    "
                                >
                                    <i class="bi bi-inbox"></i>
                                </div>

                                <h3 class="mt-4 font-bold text-slate-900 dark:text-slate-100">
                                    No open permit request
                                </h3>

                                <p class="mt-2 max-w-sm text-sm leading-6 text-slate-500 dark:text-slate-400">
                                    You may submit a new permit application, or renew an eligible active permit.
                                </p>

                                <?php if ($lastClosedPermit): ?>
                                    <?php
                                    $lastClosedStatus =
                                      strtolower(
                                        trim(
                                          (string)(
                                            $lastClosedPermit['status']
                                            ?? ''
                                          )
                                        )
                                      );

                                    $lastClosedPaymentStatus =
                                      strtolower(
                                        trim(
                                          (string)(
                                            $lastClosedPermit['payment_status']
                                            ?? ''
                                          )
                                        )
                                      );

                                    $lastClosedReason = '';

                                    if ($lastClosedStatus === 'rejected') {
                                      $lastClosedReason =
                                        trim(
                                          (string)(
                                            $lastClosedPermit['rejected_reason']
                                            ?? ''
                                          )
                                        );
                                    } elseif ($lastClosedStatus === 'revoked') {
                                      $lastClosedReason =
                                        trim(
                                          (string)(
                                            $lastClosedPermit['revoked_reason']
                                            ?? ''
                                          )
                                        );
                                    }
                                    ?>

                                    <div
                                        class="
                                            mt-5 w-full max-w-lg
                                            rounded-2xl
                                            border border-slate-200
                                            bg-white p-4
                                            text-left
                                            dark:border-slate-700
                                            dark:bg-slate-900
                                        "
                                    >
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                                    Latest Closed Record
                                                </p>

                                                <p class="mt-1 font-bold text-slate-900 dark:text-slate-100">
                                                    <?= esc($lastClosedPermit['plate_no'] ?? 'Vehicle') ?>
                                                </p>
                                            </div>

                                            <span
                                                class="
                                                    inline-flex items-center gap-1.5
                                                    rounded-lg px-2.5 py-1
                                                    text-xs font-bold ring-1
                                                    <?= permit_status_tailwind(
                                                      $lastClosedStatus,
                                                      $lastClosedPaymentStatus
                                                    ) ?>
                                                "
                                            >
                                                <i class="bi <?= esc(
                                                  permit_status_icon(
                                                    $lastClosedStatus,
                                                    $lastClosedPaymentStatus
                                                  )
                                                ) ?>"></i>

                                                <?= esc(
                                                  permit_status_text(
                                                    $lastClosedStatus,
                                                    $lastClosedPaymentStatus
                                                  )
                                                ) ?>
                                            </span>
                                        </div>

                                        <?php if ($lastClosedReason !== ''): ?>
                                            <div
                                                class="
                                                    mt-3 rounded-xl
                                                    border border-red-200
                                                    bg-red-50 p-3
                                                    text-sm leading-6 text-red-800
                                                    dark:border-red-900
                                                    dark:bg-red-950/40
                                                    dark:text-red-300
                                                "
                                            >
                                                <span class="font-bold">
                                                    <?= $lastClosedStatus === 'rejected'
                                                      ? 'Rejection reason:'
                                                      : 'Revocation reason:' ?>
                                                </span>

                                                <?= esc($lastClosedReason) ?>
                                            </div>
                                        <?php elseif ($lastClosedStatus === 'expired'): ?>
                                            <p class="mt-3 text-sm leading-6 text-amber-700 dark:text-amber-300">
                                                This permit reached the end of its validity period.
                                            </p>
                                        <?php endif; ?>

                                        <a
                                            href="homeowner_parking.php#permitHistory"
                                            class="
                                                mt-3 inline-flex min-h-10
                                                items-center justify-center gap-2
                                                rounded-xl
                                                border border-slate-200
                                                bg-white px-3
                                                text-sm font-semibold text-slate-700
                                                transition hover:bg-slate-50
                                                dark:border-slate-700
                                                dark:bg-slate-800
                                                dark:text-slate-200
                                                dark:hover:bg-slate-700
                                            "
                                        >
                                            <i class="bi bi-clock-history"></i>
                                            View Permit History
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>

                        <?php endif; ?>

                    </div>
                </section>

                <?php if ($activePermit): ?>
                    <section
                        class="
                            overflow-hidden
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            shadow-sm
                            dark:border-slate-800
                            dark:bg-slate-900
                        "
                    >
                        <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                            <h2 class="flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <i class="bi bi-card-checklist text-blue-700 dark:text-blue-300"></i>
                                Active Permit
                            </h2>
                        </div>

                        <div class="p-5">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Permit No.</p>
                                    <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                        <?= esc($activePermit['permit_no'] ?? '—') ?>
                                    </p>
                                </div>

                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Plate</p>
                                    <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                        <?= esc($activePermit['plate_no'] ?? '—') ?>
                                    </p>
                                </div>

                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Valid Until</p>
                                    <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                        <?= esc($activePermit['valid_until'] ?? '—') ?>
                                    </p>
                                </div>

                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Days Remaining</p>
                                    <p
                                        class="
                                            mt-1 font-bold
                                            <?= $activePermitDaysRemaining !== null && $activePermitDaysRemaining <= $renewalWindowDays
                                                ? 'text-amber-700 dark:text-amber-300'
                                                : 'text-slate-800 dark:text-slate-200'
                                            ?>
                                        "
                                    >
                                        <?= $activePermitDaysRemaining !== null
                                            ? (int)$activePermitDaysRemaining . ' day' . ($activePermitDaysRemaining === 1 ? '' : 's')
                                            : 'Expired'
                                        ?>
                                    </p>
                                </div>
                            </div>

                            <?php if ($activePermitHasScheduledRenewal): ?>
                                <div
                                    class="
                                        mt-4 rounded-xl
                                        border border-violet-200
                                        bg-violet-50 p-3
                                        text-sm leading-6 text-violet-800
                                        dark:border-violet-900
                                        dark:bg-violet-950/40
                                        dark:text-violet-300
                                    "
                                >
                                    <i class="bi bi-calendar-check-fill mr-1"></i>
                                    A paid renewal is already scheduled to start on
                                    <strong><?= esc($upcomingPermit['valid_from'] ?? '—') ?></strong>.
                                </div>
                            <?php elseif (can_renew_now($activePermit['valid_until'] ?? null, $renewalWindowDays)): ?>
                                <a
                                    href="homeowner_parking_permit.php?renew_id=<?= (int)$activePermit['id'] ?>"
                                    class="
                                        mt-4 inline-flex min-h-11
                                        items-center justify-center gap-2
                                        rounded-xl
                                        bg-emerald-700 px-4
                                        text-sm font-semibold text-white
                                        transition hover:bg-emerald-800
                                        dark:bg-emerald-600
                                        dark:hover:bg-emerald-500
                                    "
                                >
                                    <i class="bi bi-arrow-repeat"></i>
                                    Renew This Permit
                                </a>
                            <?php else: ?>
                                <div
                                    class="
                                        mt-4 rounded-xl
                                        bg-slate-50 p-3
                                        text-xs leading-5 text-slate-500
                                        dark:bg-slate-800/60
                                        dark:text-slate-400
                                    "
                                >
                                    Renewal becomes available within
                                    <?= (int)$renewalWindowDays ?> days before expiration.
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($upcomingPermit): ?>
                    <section
                        class="
                            overflow-hidden
                            rounded-2xl
                            border border-violet-200
                            bg-white
                            shadow-sm
                            dark:border-violet-900
                            dark:bg-slate-900
                        "
                    >
                        <div class="border-b border-violet-200 px-5 py-4 dark:border-violet-900">
                            <h2 class="flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <i class="bi bi-calendar-check-fill text-violet-700 dark:text-violet-300"></i>
                                Upcoming Renewal
                            </h2>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                Paid and scheduled. It becomes the current permit automatically on its valid-from date.
                            </p>
                        </div>

                        <div class="p-5">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Permit No.</p>
                                    <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                        <?= esc($upcomingPermit['permit_no'] ?? '—') ?>
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Plate</p>
                                    <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                        <?= esc($upcomingPermit['plate_no'] ?? '—') ?>
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Starts On</p>
                                    <p class="mt-1 font-semibold text-violet-700 dark:text-violet-300">
                                        <?= esc($upcomingPermit['valid_from'] ?? '—') ?>
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Valid Until</p>
                                    <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                        <?= esc($upcomingPermit['valid_until'] ?? '—') ?>
                                    </p>
                                </div>
                            </div>

                            <?php if (!empty($upcomingPermit['contract_path'])): ?>
                                <a
                                    href="homeowner_contract.php?permit_id=<?= (int)$upcomingPermit['id'] ?>"
                                    class="
                                        mt-4 inline-flex min-h-11
                                        items-center justify-center gap-2
                                        rounded-xl
                                        border border-violet-200
                                        bg-violet-50 px-4
                                        text-sm font-semibold text-violet-700
                                        transition hover:bg-violet-100
                                        dark:border-violet-900
                                        dark:bg-violet-950/40
                                        dark:text-violet-300
                                        dark:hover:bg-violet-950/60
                                    "
                                >
                                    <i class="bi bi-file-earmark-text-fill"></i>
                                    Contract Copy
                                </a>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <section
                    class="
                        overflow-hidden
                        rounded-2xl
                        border border-slate-200
                        bg-white
                        shadow-sm
                        dark:border-slate-800
                        dark:bg-slate-900
                    "
                >
                    <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                        <h2 class="flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                            <i class="bi bi-clipboard-check-fill text-emerald-700 dark:text-emerald-400"></i>
                            Requirements
                        </h2>
                    </div>

                    <div class="p-5">
                        <div class="space-y-3">
                            <div class="flex items-start gap-3">
                                <span
                                    class="
                                        flex h-9 w-9 shrink-0 items-center justify-center
                                        rounded-xl bg-blue-100
                                        text-blue-700
                                        dark:bg-blue-950/60
                                        dark:text-blue-300
                                    "
                                >
                                    <i class="bi bi-camera-fill"></i>
                                </span>
                                <div>
                                    <p class="font-semibold text-slate-800 dark:text-slate-200">
                                        Vehicle front photo
                                    </p>
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                        Clear JPG, JPEG, PNG, or PDF • Maximum 5 MB.
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3">
                                <span
                                    class="
                                        flex h-9 w-9 shrink-0 items-center justify-center
                                        rounded-xl bg-violet-100
                                        text-violet-700
                                        dark:bg-violet-950/60
                                        dark:text-violet-300
                                    "
                                >
                                    <i class="bi bi-camera-reels-fill"></i>
                                </span>
                                <div>
                                    <p class="font-semibold text-slate-800 dark:text-slate-200">
                                        Vehicle back photo
                                    </p>
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                        Clear JPG, JPEG, PNG, or PDF • Maximum 5 MB.
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-start gap-3">
                                <span
                                    class="
                                        flex h-9 w-9 shrink-0 items-center justify-center
                                        rounded-xl bg-emerald-100
                                        text-emerald-700
                                        dark:bg-emerald-950/60
                                        dark:text-emerald-300
                                    "
                                >
                                    <i class="bi bi-car-front-fill"></i>
                                </span>
                                <div>
                                    <p class="font-semibold text-slate-800 dark:text-slate-200">
                                        Vehicle and payment details
                                    </p>
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                        Select vehicle type, permit duration, and payment method.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div
                            class="
                                mt-4 flex items-start gap-3
                                rounded-xl bg-blue-50 p-3
                                text-sm leading-6 text-blue-800
                                dark:bg-blue-950/40
                                dark:text-blue-300
                            "
                        >
                            <i class="bi bi-lightbulb-fill mt-0.5 shrink-0"></i>
                            <span>
                                Use clear uploads to avoid delays during HOA review.
                            </span>
                        </div>
                    </div>
                </section>

                <?php if ($renewPermit): ?>
                    <section
                        class="
                            overflow-hidden
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            shadow-sm
                            dark:border-slate-800
                            dark:bg-slate-900
                        "
                    >
                        <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                            <h2 class="flex items-center gap-2 font-bold text-slate-900 dark:text-slate-100">
                                <i class="bi bi-arrow-repeat text-violet-700 dark:text-violet-300"></i>
                                Renewal Reference
                            </h2>
                        </div>

                        <div class="p-5">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Permit No.</p>
                                    <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                        <?= esc($renewPermit['permit_no'] ?? '—') ?>
                                    </p>
                                </div>

                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Plate</p>
                                    <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                        <?= esc($renewPermit['plate_no'] ?? '—') ?>
                                    </p>
                                </div>

                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Vehicle Type</p>
                                    <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                        <?= esc(vehicle_type_label((string)($renewPermit['vehicle_type'] ?? 'car'))) ?>
                                    </p>
                                </div>

                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400 dark:text-slate-500">Valid Until</p>
                                    <p class="mt-1 font-semibold text-slate-800 dark:text-slate-200">
                                        <?= esc($renewPermit['valid_until'] ?? '—') ?>
                                    </p>
                                </div>
                            </div>

                            <div
                                class="
                                    mt-4 rounded-xl
                                    bg-violet-50 p-3
                                    text-sm leading-6 text-violet-800
                                    dark:bg-violet-950/40
                                    dark:text-violet-300
                                "
                            >
                                Renewal is available only within
                                <?= (int)$renewalWindowDays ?> days before expiration.
                                <?php if ($renewDaysRemaining !== null): ?>
                                    This permit has
                                    <strong><?= (int)$renewDaysRemaining ?></strong>
                                    day<?= $renewDaysRemaining === 1 ? '' : 's' ?> remaining.
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

            </div>

            <div>

                <?php if ($hasOpenRequest): ?>
                    <?php $lockPaymentStatus = strtolower(trim((string)($currentStatusPermit['payment_status'] ?? ''))); ?>

                    <section
                        class="
                            overflow-hidden
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            shadow-sm
                            dark:border-slate-800
                            dark:bg-slate-900
                        "
                    >
                        <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                            <h2 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-slate-100">
                                <i class="bi bi-lock-fill text-amber-700 dark:text-amber-300"></i>
                                Application Temporarily Locked
                            </h2>
                        </div>

                        <div class="p-5">
                            <div
                                class="
                                    flex items-start gap-3
                                    rounded-xl
                                    border border-amber-200
                                    bg-amber-50
                                    p-4
                                    text-sm leading-6 text-amber-800
                                    dark:border-amber-900
                                    dark:bg-amber-950/40
                                    dark:text-amber-300
                                "
                            >
                                <i class="bi bi-exclamation-triangle-fill mt-0.5 shrink-0"></i>

                                <span>
                                    <?php if ($lockPaymentStatus === 'for payment'): ?>
                                        Your previous request is already approved and waiting for payment.
                                        Finish that request before submitting another one.
                                    <?php else: ?>
                                        Your previous request is still waiting for admin approval.
                                        Please wait before submitting another application.
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">
                                        Plate Number
                                    </label>
                                    <div class="min-h-12 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                        <?= esc($currentStatusPermit['plate_no'] ?? '—') ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">
                                        Vehicle Type
                                    </label>
                                    <div class="min-h-12 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                        <?= esc(vehicle_type_label((string)($currentStatusPermit['vehicle_type'] ?? 'car'))) ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">
                                        Brand
                                    </label>
                                    <div class="min-h-12 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                        <?= esc($currentStatusPermit['vehicle_make'] ?? '—') ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">
                                        Model
                                    </label>
                                    <div class="min-h-12 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                        <?= esc($currentStatusPermit['vehicle_model'] ?? '—') ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">
                                        Color
                                    </label>
                                    <div class="min-h-12 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                        <?= esc($currentStatusPermit['vehicle_color'] ?? '—') ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">
                                        Permit Duration
                                    </label>
                                    <div class="min-h-12 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                        <?= esc(duration_label((string)($currentStatusPermit['permit_duration'] ?? ''))) ?>
                                    </div>
                                </div>

                                <div class="sm:col-span-2">
                                    <label class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300">
                                        Payment Method
                                    </label>
                                    <div class="min-h-12 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                                        <?= esc(payment_label((string)($currentStatusPermit['payment_method'] ?? ''))) ?>
                                    </div>
                                </div>
                            </div>

                            <button
                                type="button"
                                disabled
                                class="
                                    mt-5 flex min-h-12 w-full
                                    cursor-not-allowed
                                    items-center justify-center gap-2
                                    rounded-xl
                                    bg-slate-100
                                    px-5
                                    text-sm font-bold text-slate-400
                                    dark:bg-slate-800
                                    dark:text-slate-500
                                "
                            >
                                <i class="bi bi-lock-fill"></i>
                                <?= $lockPaymentStatus === 'for payment'
                                    ? 'Finish Payment First'
                                    : 'Wait for Admin Approval'
                                ?>
                            </button>
                        </div>
                    </section>

                <?php elseif ($renewPermitId > 0 && (!$renewPermit || !$renewAllowed)): ?>

                    <section
                        class="
                            overflow-hidden
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            shadow-sm
                            dark:border-slate-800
                            dark:bg-slate-900
                        "
                    >
                        <div class="p-6 text-center">
                            <div
                                class="
                                    mx-auto flex h-16 w-16
                                    items-center justify-center
                                    rounded-2xl
                                    bg-slate-100
                                    text-2xl text-slate-400
                                    dark:bg-slate-800
                                    dark:text-slate-500
                                "
                            >
                                <i class="bi bi-lock-fill"></i>
                            </div>

                            <h2 class="mt-4 text-lg font-bold text-slate-900 dark:text-slate-100">
                                Renewal Not Yet Available
                            </h2>

                            <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500 dark:text-slate-400">
                                Renewal requests are allowed only within
                                <?= (int)$renewalWindowDays ?> days before an active permit expires.
                            </p>

                            <a
                                href="homeowner_parking.php"
                                class="
                                    mt-5 inline-flex min-h-11
                                    items-center justify-center gap-2
                                    rounded-xl
                                    border border-slate-200
                                    bg-white px-4
                                    text-sm font-semibold text-slate-700
                                    transition hover:bg-slate-50
                                    dark:border-slate-700
                                    dark:bg-slate-800
                                    dark:text-slate-200
                                    dark:hover:bg-slate-700
                                "
                            >
                                <i class="bi bi-arrow-left"></i>
                                Back to Parking Overview
                            </a>
                        </div>
                    </section>

                <?php else: ?>

                    <section
                        class="
                            overflow-hidden
                            rounded-2xl
                            border border-slate-200
                            bg-white
                            shadow-sm
                            dark:border-slate-800
                            dark:bg-slate-900
                        "
                    >
                        <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                            <h2 class="flex items-center gap-2 text-lg font-bold text-slate-900 dark:text-slate-100">
                                <i class="bi bi-card-checklist text-emerald-700 dark:text-emerald-400"></i>
                                <?= $renewAllowed && $renewPermit
                                    ? 'Renew Permit'
                                    : 'New Permit Application'
                                ?>
                            </h2>

                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                Complete the vehicle details and upload the required files.
                            </p>
                        </div>

                        <form
                            method="POST"
                            enctype="multipart/form-data"
                            class="p-5"
                        >
                            <input type="hidden" name="submit_permit" value="1">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= esc($csrfParkingPermit) ?>"
                            >
                            <input
                                type="hidden"
                                name="renew_of_id"
                                value="<?= $renewAllowed && $renewPermit ? (int)$renewPermit['id'] : 0 ?>"
                            >

                            <?php if ($renewAllowed && $renewPermit): ?>
                                <div
                                    class="
                                        mb-5 flex items-start gap-3
                                        rounded-xl
                                        border border-violet-200
                                        bg-violet-50
                                        p-4
                                        text-sm leading-6 text-violet-800
                                        dark:border-violet-900
                                        dark:bg-violet-950/40
                                        dark:text-violet-300
                                    "
                                >
                                    <i class="bi bi-arrow-repeat mt-0.5 shrink-0"></i>
                                    <span>
                                        You are renewing permit
                                        <strong><?= esc($renewPermit['permit_no'] ?? '—') ?></strong>.
                                        <?php if ($nextStartDate !== ''): ?>
                                            The proposed new validity starts on
                                            <strong><?= esc($nextStartDate) ?></strong>,
                                            the day after the current permit expires.
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <div
                                    class="
                                        mb-5 flex items-start gap-3
                                        rounded-xl
                                        border border-blue-200
                                        bg-blue-50
                                        p-4
                                        text-sm leading-6 text-blue-800
                                        dark:border-blue-900
                                        dark:bg-blue-950/40
                                        dark:text-blue-300
                                    "
                                >
                                    <i class="bi bi-info-circle-fill mt-0.5 shrink-0"></i>
                                    <span>
                                        Your proposed validity period is computed automatically
                                        from the selected duration. Payment becomes available
                                        only after HOA admin approval.
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="grid gap-4 md:grid-cols-2">

                                <div class="md:col-span-2">
                                    <label
                                        for="plate_no"
                                        class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300"
                                    >
                                        Plate Number
                                    </label>

                                    <input
                                        type="text"
                                        id="plate_no"
                                        name="plate_no"
                                        required
                                        maxlength="30"
                                        value="<?= esc($prefillPlate) ?>"
                                        placeholder="e.g. ABC 1234"
                                        class="
                                            min-h-12 w-full
                                            rounded-xl
                                            border border-slate-300
                                            bg-white
                                            px-3
                                            text-base text-slate-800
                                            outline-none transition
                                            placeholder:text-slate-400
                                            focus:border-emerald-500
                                            focus:ring-4 focus:ring-emerald-100
                                            dark:border-slate-700
                                            dark:bg-slate-800
                                            dark:text-slate-100
                                            dark:placeholder:text-slate-500
                                            dark:focus:ring-emerald-950
                                        "
                                    >
                                </div>

                                <div>
                                    <label
                                        for="vehicle_type"
                                        class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300"
                                    >
                                        Vehicle Type
                                    </label>

                                    <select
                                        id="vehicle_type"
                                        name="vehicle_type"
                                        required
                                        class="
                                            min-h-12 w-full
                                            rounded-xl
                                            border border-slate-300
                                            bg-white
                                            px-3
                                            text-base text-slate-800
                                            outline-none transition
                                            focus:border-emerald-500
                                            focus:ring-4 focus:ring-emerald-100
                                            dark:border-slate-700
                                            dark:bg-slate-800
                                            dark:text-slate-100
                                            dark:focus:ring-emerald-950
                                        "
                                    >
                                        <option value="">Select Vehicle Type</option>
                                        <option value="car" <?= $prefillVehicleType === 'car' ? 'selected' : '' ?>>Car</option>
                                        <option value="motorcycle" <?= $prefillVehicleType === 'motorcycle' ? 'selected' : '' ?>>Motorcycle</option>
                                        <option value="ebike" <?= $prefillVehicleType === 'ebike' ? 'selected' : '' ?>>E-Bike</option>
                                    </select>
                                </div>

                                <div>
                                    <label
                                        for="vehicle_make"
                                        class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300"
                                    >
                                        Brand
                                    </label>

                                    <input
                                        type="text"
                                        id="vehicle_make"
                                        name="vehicle_make"
                                        maxlength="80"
                                        value="<?= esc($prefillMake) ?>"
                                        placeholder="e.g. Toyota"
                                        class="
                                            min-h-12 w-full
                                            rounded-xl
                                            border border-slate-300
                                            bg-white
                                            px-3
                                            text-base text-slate-800
                                            outline-none transition
                                            placeholder:text-slate-400
                                            focus:border-emerald-500
                                            focus:ring-4 focus:ring-emerald-100
                                            dark:border-slate-700
                                            dark:bg-slate-800
                                            dark:text-slate-100
                                            dark:placeholder:text-slate-500
                                            dark:focus:ring-emerald-950
                                        "
                                    >
                                </div>

                                <div>
                                    <label
                                        for="vehicle_model"
                                        class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300"
                                    >
                                        Model
                                    </label>

                                    <input
                                        type="text"
                                        id="vehicle_model"
                                        name="vehicle_model"
                                        maxlength="80"
                                        value="<?= esc($prefillModel) ?>"
                                        placeholder="e.g. Vios"
                                        class="
                                            min-h-12 w-full
                                            rounded-xl
                                            border border-slate-300
                                            bg-white
                                            px-3
                                            text-base text-slate-800
                                            outline-none transition
                                            placeholder:text-slate-400
                                            focus:border-emerald-500
                                            focus:ring-4 focus:ring-emerald-100
                                            dark:border-slate-700
                                            dark:bg-slate-800
                                            dark:text-slate-100
                                            dark:placeholder:text-slate-500
                                            dark:focus:ring-emerald-950
                                        "
                                    >
                                </div>

                                <div>
                                    <label
                                        for="vehicle_color"
                                        class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300"
                                    >
                                        Color
                                    </label>

                                    <input
                                        type="text"
                                        id="vehicle_color"
                                        name="vehicle_color"
                                        maxlength="50"
                                        value="<?= esc($prefillColor) ?>"
                                        placeholder="e.g. White"
                                        class="
                                            min-h-12 w-full
                                            rounded-xl
                                            border border-slate-300
                                            bg-white
                                            px-3
                                            text-base text-slate-800
                                            outline-none transition
                                            placeholder:text-slate-400
                                            focus:border-emerald-500
                                            focus:ring-4 focus:ring-emerald-100
                                            dark:border-slate-700
                                            dark:bg-slate-800
                                            dark:text-slate-100
                                            dark:placeholder:text-slate-500
                                            dark:focus:ring-emerald-950
                                        "
                                    >
                                </div>

                                <div>
                                    <label
                                        for="permit_duration"
                                        class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300"
                                    >
                                        Permit Duration
                                    </label>

                                    <select
                                        id="permit_duration"
                                        name="permit_duration"
                                        required
                                        class="
                                            min-h-12 w-full
                                            rounded-xl
                                            border border-slate-300
                                            bg-white
                                            px-3
                                            text-base text-slate-800
                                            outline-none transition
                                            focus:border-emerald-500
                                            focus:ring-4 focus:ring-emerald-100
                                            dark:border-slate-700
                                            dark:bg-slate-800
                                            dark:text-slate-100
                                            dark:focus:ring-emerald-950
                                        "
                                    >
                                        <option value="">Select Duration</option>
                                        <option value="1_month">1 Month</option>
                                        <option value="3_months">3 Months</option>
                                        <option value="6_months">6 Months</option>
                                        <option value="1_year">1 Year</option>
                                    </select>
                                </div>

                                <div>
                                    <label
                                        for="payment_method"
                                        class="mb-2 block text-sm font-bold text-slate-700 dark:text-slate-300"
                                    >
                                        Payment Method
                                    </label>

                                    <select
                                        id="payment_method"
                                        name="payment_method"
                                        required
                                        class="
                                            min-h-12 w-full
                                            rounded-xl
                                            border border-slate-300
                                            bg-white
                                            px-3
                                            text-base text-slate-800
                                            outline-none transition
                                            focus:border-emerald-500
                                            focus:ring-4 focus:ring-emerald-100
                                            dark:border-slate-700
                                            dark:bg-slate-800
                                            dark:text-slate-100
                                            dark:focus:ring-emerald-950
                                        "
                                    >
                                        <option value="">Select Payment</option>
                                        <option value="online">Online Payment</option>
                                        <option value="cash">Cash / Physical Payment</option>
                                    </select>
                                </div>

                            </div>

                            <div class="my-6 border-t border-slate-200 dark:border-slate-800"></div>

                            <div>
                                <h3 class="font-bold text-slate-900 dark:text-slate-100">
                                    Upload Requirements
                                </h3>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    Upload the front and back of the vehicle.
                                </p>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-2">

                                <label
                                    class="
                                        block cursor-pointer
                                        rounded-2xl
                                        border border-dashed border-slate-300
                                        bg-slate-50
                                        p-4
                                        transition
                                        hover:border-emerald-400
                                        hover:bg-emerald-50/50
                                        dark:border-slate-700
                                        dark:bg-slate-800/50
                                        dark:hover:border-emerald-700
                                        dark:hover:bg-emerald-950/20
                                    "
                                >
                                    <span
                                        class="
                                            flex h-11 w-11 items-center justify-center
                                            rounded-xl
                                            bg-blue-100
                                            text-lg text-blue-700
                                            dark:bg-blue-950/60
                                            dark:text-blue-300
                                        "
                                    >
                                        <i class="bi bi-camera-fill"></i>
                                    </span>

                                    <span class="mt-3 block font-bold text-slate-900 dark:text-slate-100">
                                        Vehicle Front
                                    </span>

                                    <span class="mt-1 block text-xs leading-5 text-slate-500 dark:text-slate-400">
                                        JPG, JPEG, PNG, or PDF
                                    </span>

                                    <span
                                        id="vehicleFrontName"
                                        class="
                                            mt-3 block
                                            truncate
                                            rounded-lg
                                            bg-white
                                            px-3 py-2
                                            text-xs font-medium text-slate-500
                                            ring-1 ring-slate-200
                                            dark:bg-slate-900
                                            dark:text-slate-400
                                            dark:ring-slate-700
                                        "
                                    >
                                        No file selected
                                    </span>

                                    <input
                                        type="file"
                                        name="vehicle_front"
                                        id="vehicle_front"
                                        required
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        class="sr-only"
                                    >
                                </label>

                                <label
                                    class="
                                        block cursor-pointer
                                        rounded-2xl
                                        border border-dashed border-slate-300
                                        bg-slate-50
                                        p-4
                                        transition
                                        hover:border-emerald-400
                                        hover:bg-emerald-50/50
                                        dark:border-slate-700
                                        dark:bg-slate-800/50
                                        dark:hover:border-emerald-700
                                        dark:hover:bg-emerald-950/20
                                    "
                                >
                                    <span
                                        class="
                                            flex h-11 w-11 items-center justify-center
                                            rounded-xl
                                            bg-violet-100
                                            text-lg text-violet-700
                                            dark:bg-violet-950/60
                                            dark:text-violet-300
                                        "
                                    >
                                        <i class="bi bi-camera-reels-fill"></i>
                                    </span>

                                    <span class="mt-3 block font-bold text-slate-900 dark:text-slate-100">
                                        Vehicle Back
                                    </span>

                                    <span class="mt-1 block text-xs leading-5 text-slate-500 dark:text-slate-400">
                                        JPG, JPEG, PNG, or PDF
                                    </span>

                                    <span
                                        id="vehicleBackName"
                                        class="
                                            mt-3 block
                                            truncate
                                            rounded-lg
                                            bg-white
                                            px-3 py-2
                                            text-xs font-medium text-slate-500
                                            ring-1 ring-slate-200
                                            dark:bg-slate-900
                                            dark:text-slate-400
                                            dark:ring-slate-700
                                        "
                                    >
                                        No file selected
                                    </span>

                                    <input
                                        type="file"
                                        name="vehicle_back"
                                        id="vehicle_back"
                                        required
                                        accept=".pdf,.jpg,.jpeg,.png"
                                        class="sr-only"
                                    >
                                </label>

                            </div>

                            <button
                                type="submit"
                                class="
                                    mt-6
                                    flex min-h-12 w-full
                                    items-center justify-center gap-2
                                    rounded-xl
                                    bg-emerald-700
                                    px-5
                                    text-base font-semibold text-white
                                    shadow-sm transition
                                    hover:bg-emerald-800
                                    focus:outline-none
                                    focus:ring-4 focus:ring-emerald-100
                                    dark:bg-emerald-600
                                    dark:hover:bg-emerald-500
                                    dark:focus:ring-emerald-950
                                "
                            >
                                <i class="bi bi-send-fill"></i>
                                <?= $renewAllowed && $renewPermit
                                    ? 'Submit Renewal Request'
                                    : 'Submit Permit Request'
                                ?>
                            </button>

                            <p class="mt-3 text-center text-xs leading-5 text-slate-500 dark:text-slate-400">
                                Previous application details remain visible in the Current Status section.
                            </p>
                        </form>
                    </section>

                <?php endif; ?>

            </div>
        </div>

        <footer
            class="
                mt-8
                border-t border-slate-200
                py-6
                text-center text-sm text-slate-500
                dark:border-slate-800
                dark:text-slate-500
            "
        >
            © South Meridian Homes Salitran
        </footer>

    </main>
</div>

<script>
function initSidebarDropdown(buttonId, menuId, caretId) {
    const button = document.getElementById(buttonId);
    const menu = document.getElementById(menuId);
    const caret = document.getElementById(caretId);

    if (!button || !menu) return;

    button.addEventListener('click', function () {
        const willOpen = menu.classList.contains('hidden');

        menu.classList.toggle('hidden');
        button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

        if (caret) {
            caret.classList.toggle('rotate-180', willOpen);
        }
    });
}

initSidebarDropdown('sbParkingToggle', 'sbParkingMenu', 'sbParkingCaret');
initSidebarDropdown('sbTenantToggle', 'sbTenantMenu', 'sbTenantCaret');

(function () {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const openButton = document.getElementById('sidebarToggle');
    const closeButton = document.getElementById('sidebarClose');

    if (!sidebar || !overlay || !openButton) return;

    function openSidebar() {
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function closeSidebar() {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    openButton.addEventListener('click', openSidebar);
    closeButton?.addEventListener('click', closeSidebar);
    overlay.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth < 1024) {
                closeSidebar();
            }
        });
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth >= 1024) {
            overlay.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
    });
})();

(function () {
    const toggle = document.getElementById('themeToggle');
    const icon = document.getElementById('themeIcon');

    if (!toggle || !icon) return;

    function updateThemeIcon() {
        const dark = document.documentElement.classList.contains('dark');

        icon.className = dark
            ? 'bi bi-sun-fill'
            : 'bi bi-moon-stars-fill';

        toggle.setAttribute(
            'aria-label',
            dark ? 'Switch to light mode' : 'Switch to dark mode'
        );

        toggle.setAttribute(
            'title',
            dark ? 'Switch to light mode' : 'Switch to dark mode'
        );
    }

    toggle.addEventListener('click', function () {
        const dark = document.documentElement.classList.toggle('dark');

        localStorage.setItem(
            'hoa-theme',
            dark ? 'dark' : 'light'
        );

        updateThemeIcon();
    });

    updateThemeIcon();
})();

document
    .querySelectorAll('.btn-close-flash')
    .forEach(function (button) {
        button.addEventListener('click', function () {
            button.closest('.page-flash')?.remove();
        });
    });

function bindFileName(inputId, labelId) {
    const input = document.getElementById(inputId);
    const label = document.getElementById(labelId);

    if (!input || !label) return;

    input.addEventListener('change', function () {
        const file = input.files && input.files[0];

        label.textContent = file
            ? file.name
            : 'No file selected';
    });
}

bindFileName('vehicle_front', 'vehicleFrontName');
bindFileName('vehicle_back', 'vehicleBackName');
</script>

</body>
</html>