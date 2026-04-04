<?php
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['homeowner', 'tenant'], true)) {
  header("Location: ../index.php");
  exit;
}

$conn = new mysqli("localhost", "u972459197_patrick", "Idle2440", "u972459197_south_meridian");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

require_once 'tenant_module_guard.php';

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function safe_ext(string $name): string {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  return preg_replace('/[^a-z0-9]+/','', $ext);
}

function save_upload(string $field, string $baseDir): ?string {
  if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
  if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;

  $tmp  = $_FILES[$field]['tmp_name'];
  $orig = (string)$_FILES[$field]['name'];
  $ext  = safe_ext($orig);

  $allowed = ['pdf','jpg','jpeg','png'];
  if (!in_array($ext, $allowed, true)) return null;

  if (!is_dir($baseDir)) {
    if (!mkdir($baseDir, 0777, true) && !is_dir($baseDir)) return null;
  }

  $newName = time().'_'.bin2hex(random_bytes(6)).'.'.$ext;
  $destFs  = rtrim($baseDir,'/').'/'.$newName;

  if (!move_uploaded_file($tmp, $destFs)) return null;
  return $destFs;
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
  Active permit
*/
$stmt = $conn->prepare("
  SELECT *
  FROM parking_permits
  WHERE homeowner_id=? AND phase=? AND status='active'
    AND LOWER(COALESCE(payment_status, 'paid'))='paid'
    AND valid_until >= CURDATE()
  ORDER BY valid_until DESC, id DESC
  LIMIT 1
");
$stmt->bind_param("is", $hid, $phase);
$stmt->execute();
$activePermit = $stmt->get_result()->fetch_assoc();
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

$renewPermit = null;
$renewPermitId = (int)($_GET['renew_id'] ?? 0);
$renewAllowed = false;
$renewDaysRemaining = null;

if ($renewPermitId > 0) {
  $stmt = $conn->prepare("
    SELECT *
    FROM parking_permits
    WHERE id=? AND homeowner_id=? AND phase=? AND status='active'
      AND LOWER(COALESCE(payment_status, 'paid'))='paid'
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
  }
}

$msg = "";
$msgType = "success";

if ($renewPermitId > 0) {
  if (!$renewPermit) {
    set_msg($msg, $msgType, "danger", "Invalid renewal request.");
  } elseif (!$renewAllowed) {
    set_msg($msg, $msgType, "warning", "Renewal is only allowed within {$renewalWindowDays} days before permit expiration.");
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_permit'])) {
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
        AND valid_until >= CURDATE()
      LIMIT 1
    ");
    $stmt->bind_param("iis", $requestRenewId, $hid, $phase);
    $stmt->execute();
    $renewBasePermit = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($renewBasePermit && can_renew_now($renewBasePermit['valid_until'] ?? null, $renewalWindowDays)) {
      $isRenewalRequest = true;
    } elseif ($requestRenewId > 0) {
      set_msg($msg, $msgType, "warning", "Renewal is only allowed within {$renewalWindowDays} days before permit expiration.");
    }
  }

  if ($latestOpenCheck) {
    $latestPaymentStatus = strtolower(trim((string)($latestOpenCheck['payment_status'] ?? 'unpaid')));

    if ($latestPaymentStatus === 'for payment') {
      set_msg($msg,$msgType,"warning","Your previous parking permit request is already approved and waiting for payment. Please finish that first.");
    } else {
      set_msg($msg,$msgType,"warning","Please wait for your previous parking permit application to be approved first.");
    }

    $currentStatusPermit = $latestOpenCheck;
    $hasOpenRequest = true;
  } elseif ($requestRenewId > 0 && !$isRenewalRequest) {
    // message already set above
  } else {
    $plate       = strtoupper(trim((string)($_POST['plate_no'] ?? '')));
    $vehicleType = strtolower(trim((string)($_POST['vehicle_type'] ?? '')));
    $make        = trim((string)($_POST['vehicle_make'] ?? ''));
    $model       = trim((string)($_POST['vehicle_model'] ?? ''));
    $color       = trim((string)($_POST['vehicle_color'] ?? ''));
    $duration    = (string)($_POST['permit_duration'] ?? '');
    $payment     = (string)($_POST['payment_method'] ?? '');

    if ($plate === '' || strlen($plate) < 4) {
      set_msg($msg,$msgType,"danger","Please enter a valid plate number.");
    } elseif (!in_array($vehicleType, ['car','motorcycle','ebike'], true)) {
      set_msg($msg,$msgType,"danger","Please select a valid vehicle type.");
    } elseif (!in_array($duration, ['1_month','3_months','6_months','1_year'], true)) {
      set_msg($msg,$msgType,"danger","Please select a valid permit duration.");
    } elseif (!in_array($payment, ['online','cash'], true)) {
      set_msg($msg,$msgType,"danger","Please select a valid payment method.");
    } else {
      $requestType = $isRenewalRequest ? 'renew' : 'new';
      $renewOfId   = $isRenewalRequest ? (int)$renewBasePermit['id'] : null;

      if ($isRenewalRequest && !empty($renewBasePermit['valid_until'])) {
        $baseStart = (new DateTime($renewBasePermit['valid_until']))->modify('+1 day')->format('Y-m-d');
      } else {
        $baseStart = date('Y-m-d');
      }

      [$previewFrom, $previewUntil] = computePermitDates($duration, $baseStart);

      $dir = "uploads/parking_permits/".$hid;
      if (!is_dir($dir)) @mkdir($dir, 0777, true);

      $vehicle_front_path = save_upload('vehicle_front', $dir);
      $vehicle_back_path  = save_upload('vehicle_back', $dir);

      $missing = [];
      if (!$vehicle_front_path) $missing[] = "Vehicle Front Picture";
      if (!$vehicle_back_path)  $missing[] = "Vehicle Back Picture";

      if ($missing) {
        set_msg($msg,$msgType,"danger","Missing or invalid required uploads: ".implode(", ", $missing));
      } else {
        $contractDir = "uploads/parking_contracts/".$hid;
        if (!is_dir($contractDir)) @mkdir($contractDir, 0777, true);

        $contractFileName = 'parking_contract_'.time().'_'.bin2hex(random_bytes(4)).'.html';
        $contractPath = rtrim($contractDir, '/').'/'.$contractFileName;

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

        if (file_put_contents($contractPath, $contractHtml) === false) {
          set_msg($msg,$msgType,"danger","Failed to generate parking permit contract.");
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
            set_msg($msg,$msgType,"danger","SQL prepare failed: ".$conn->error);
          } else {
            $stmt->bind_param(
              "isisssssisssssssss",
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

            $ok  = $stmt->execute();
            $err = $stmt->error;
            $stmt->close();

            if (!$ok) {
              set_msg($msg,$msgType,"danger","Failed to submit request. ".$err);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= esc($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/homeowner_dashboard.css">

<style>
html, body { max-width:100%; overflow-x:hidden; }
.app-shell{ position:relative; }

.sidebar-overlay{
  position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:1040;
  opacity:0; visibility:hidden; transition:.25s ease;
}
.sidebar-overlay.show{ opacity:1; visibility:visible; }

.sb-dd{display:flex;flex-direction:column;gap:6px;}
.sb-dd-toggle{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;}
.sb-dd-menu{display:none;padding-left:12px;margin-top:2px;border-left:2px solid rgba(255,255,255,.08);}
.sb-dd.open .sb-dd-menu{display:block;}
.sb-dd-caret{transition:transform .15s ease;}
.sb-dd.open .sb-dd-caret{transform:rotate(180deg);}
.req-list li{ margin-bottom:6px; }

.topbar-mobile-btn{
  border:1px solid #dbe3ea; background:#fff; color:#0f5132; border-radius:10px;
  width:42px; height:42px; display:inline-flex; align-items:center; justify-content:center;
}

.mobile-user-strip{ display:none; }
.permit-box{ border:1px solid #eef2f7; background:#fff; border-radius:18px; }
.file-label{ font-size:.92rem; font-weight:700; }

.form-disabled {
  opacity: .65;
  pointer-events: none;
}

.info-mini{
  font-size:.88rem;
  color:#6c757d;
}

@media (max-width: 991.98px){
  .sidebar{
    position:fixed !important; top:0; left:-290px; width:280px !important; max-width:85vw;
    height:100vh; z-index:1050; transition:left .25s ease; overflow-y:auto;
  }
  .sidebar.show{ left:0; }
  .main-area{ width:100% !important; margin-left:0 !important; }
  .container-xl{ padding-left:14px; padding-right:14px; }
  .desktop-user-text{ display:none !important; }
  .mobile-user-strip{ display:block; margin-bottom:14px; }
}

@media (max-width: 767.98px){
  .navbar-brand{
    font-size:1rem; max-width:170px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
  }
  .fb-card-h, .fb-card-b{ padding-left:14px !important; padding-right:14px !important; }
  .permit-box{ padding:14px !important; border-radius:14px; }
  .form-label{ font-size:.92rem; }
  .btn, .form-control, .form-select{ font-size:.95rem; }
}
</style>
</head>

<body>
<div class="app-shell">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <?php include 'homeowner_sidebar.php'; ?>

  <div class="main-area">
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
      <div class="container-xl">
        <div class="d-flex align-items-center gap-2">
          <button type="button" class="topbar-mobile-btn d-inline-flex d-lg-none" id="sidebarToggle" aria-label="Open menu">
            <i class="bi bi-list fs-4"></i>
          </button>
          <a class="navbar-brand fw-bold text-success m-0" href="homeowner_dashboard.php">🏘 HOA Community</a>
        </div>

        <div class="ms-auto d-flex align-items-center gap-3">
          <div class="small text-muted desktop-user-text">
            Logged in as <b><?= esc($fullName) ?></b> (<?= esc($phase) ?><?= $isTenant ? ' • Tenant' : '' ?>)
          </div>
          <a href="logout.php" class="btn btn-sm btn-outline-success">Logout</a>
        </div>
      </div>
    </nav>

    <div class="container-xl my-4">

      <div class="mobile-user-strip">
        <div class="alert alert-light border shadow-sm mb-3">
          <div class="fw-bold"><?= esc($fullName) ?></div>
          <div class="small text-muted"><?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?><?= $isTenant ? ' • Tenant' : '' ?></div>
        </div>
      </div>

      <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= esc($msgType) ?>"><?= esc($msg) ?></div>
      <?php endif; ?>

      <div class="fb-card mb-4">
        <div class="fb-card-h">
          <h6>📝 Apply / Renew Parking Permit (<?= (int)$yearNow ?>)</h6>
          <span class="pill"><?= esc($phase) ?></span>
        </div>
        <div class="fb-card-b">
          <div class="row g-3">
            <div class="col-lg-6">
              <div class="permit-box p-3">
                <div class="fw-bold mb-2">Current Status</div>

                <?php
                  $currStatus = strtolower(trim((string)($currentStatusPermit['status'] ?? '')));
                  $currPaymentStatus = strtolower(trim((string)($currentStatusPermit['payment_status'] ?? '')));
                ?>

                <?php if ($currentStatusPermit && $currStatus === 'pending' && $currPaymentStatus !== 'for payment'): ?>
                  <div class="alert alert-warning mb-0">
                    <div class="fw-bold mb-1">Application Submitted</div>
                    Your parking permit request is now waiting for <b>admin approval</b>.<br><br>
                    Plate: <b><?= esc($currentStatusPermit['plate_no'] ?? '') ?></b><br>
                    Vehicle Type: <b><?= esc(vehicle_type_label((string)($currentStatusPermit['vehicle_type'] ?? 'car'))) ?></b><br>
                    Status: <?= badge($currentStatusPermit['status'] ?? 'pending', $currentStatusPermit['payment_status'] ?? 'unpaid') ?><br>
                    Payment Status: <b><?= esc(payment_status_label((string)($currentStatusPermit['payment_status'] ?? 'unpaid'))) ?></b><br>
                    Requested At: <b><?= esc($currentStatusPermit['requested_at'] ?? '') ?></b><br>
                    Sticker Year: <b><?= esc($currentStatusPermit['sticker_year'] ?? '') ?></b>

                    <?php if (!empty($currentStatusPermit['contract_path'])): ?>
                      <div class="mt-2">
                        <a href="homeowner_contract.php?permit_id=<?= (int)$currentStatusPermit['id'] ?>" class="btn btn-sm btn-outline-success">
                          <i class="bi bi-download me-1"></i> Download Contract Copy
                        </a>
                      </div>
                    <?php endif; ?>
                  </div>

                <?php elseif ($currentStatusPermit && $currStatus === 'pending' && $currPaymentStatus === 'for payment'): ?>
                  <div class="alert alert-info mb-0">
                    <div class="fw-bold mb-1">Approved — Payment Required</div>
                    Your parking permit request has been approved by admin. You may now complete payment.<br><br>
                    Plate: <b><?= esc($currentStatusPermit['plate_no'] ?? '') ?></b><br>
                    Vehicle Type: <b><?= esc(vehicle_type_label((string)($currentStatusPermit['vehicle_type'] ?? 'car'))) ?></b><br>
                    Status: <?= badge($currentStatusPermit['status'] ?? 'pending', $currentStatusPermit['payment_status'] ?? 'for payment') ?><br>
                    Payment Status: <b><?= esc(payment_status_label((string)($currentStatusPermit['payment_status'] ?? 'for payment'))) ?></b><br>
                    Duration: <b><?= esc(duration_label((string)($currentStatusPermit['permit_duration'] ?? ''))) ?></b><br>
                    Payment: <b><?= esc(payment_label((string)($currentStatusPermit['payment_method'] ?? ''))) ?></b><br>
                    Validity: <b><?= esc($currentStatusPermit['valid_from'] ?? '') ?></b> → <b><?= esc($currentStatusPermit['valid_until'] ?? '') ?></b>

                    <?php if (!empty($currentStatusPermit['contract_path'])): ?>
                      <div class="mt-2">
                        <a href="homeowner_contract.php?permit_id=<?= (int)$currentStatusPermit['id'] ?>" class="btn btn-sm btn-outline-success">
                          <i class="bi bi-download me-1"></i> Download Contract Copy
                        </a>
                      </div>
                    <?php endif; ?>

                    <?php if (strtolower(trim((string)($currentStatusPermit['payment_method'] ?? ''))) === 'online'): ?>
                      <div class="mt-2">
                        <a href="paymongo_parking_checkout.php?permit_id=<?= (int)$currentStatusPermit['id'] ?>" class="btn btn-sm btn-primary">
                          <i class="bi bi-credit-card me-1"></i> Pay Online Now
                        </a>
                      </div>
                    <?php elseif (strtolower(trim((string)($currentStatusPermit['payment_method'] ?? ''))) === 'cash'): ?>
                      <div class="mt-2 alert alert-light border mb-0 small">
                        Please proceed with your cash / physical payment to the HOA office. Your permit will become active after payment is recorded.
                      </div>
                    <?php endif; ?>
                  </div>

                <?php else: ?>
                  <div class="alert alert-secondary mb-0">
                    No pending or unpaid permit request found. You may apply for a new permit or submit a renewal when eligible.
                  </div>
                <?php endif; ?>
              </div>

              <div class="permit-box p-3 mt-3">
                <div class="fw-bold mb-2">Requirements</div>
                <ul class="req-list mb-0">
                  <li><b>Picture of Vehicle (Front)</b></li>
                  <li><b>Picture of Vehicle (Back)</b></li>
                  <li><b>Select vehicle type</b> (Car, Motorcycle, or E-Bike)</li>
                  <li><b>Choose permit duration</b> (1 month, 3 months, 6 months, or 1 year)</li>
                  <li><b>Choose payment method</b> (Online or Cash/Physical)</li>
                </ul>
              </div>

              <?php if ($renewPermit): ?>
                <div class="permit-box p-3 mt-3">
                  <div class="fw-bold mb-2">Renewal Reference Permit</div>
                  <div><b>Permit No:</b> <?= esc($renewPermit['permit_no'] ?? '—') ?></div>
                  <div><b>Plate No:</b> <?= esc($renewPermit['plate_no'] ?? '') ?></div>
                  <div><b>Vehicle Type:</b> <?= esc(vehicle_type_label((string)($renewPermit['vehicle_type'] ?? 'car'))) ?></div>
                  <div><b>Valid Until:</b> <?= esc($renewPermit['valid_until'] ?? '') ?></div>
                  <div><b>Days Remaining:</b> <?= $renewDaysRemaining !== null ? (int)$renewDaysRemaining : 'Expired' ?></div>
                  <div class="info-mini mt-2">
                    Renewal is only available within <?= (int)$renewalWindowDays ?> days before expiration.
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <div class="col-lg-6">
              <?php if ($hasOpenRequest): ?>
                <?php
                  $lockPaymentStatus = strtolower(trim((string)($currentStatusPermit['payment_status'] ?? '')));
                ?>
                <div class="alert alert-warning mb-3">
                  <div class="fw-bold mb-1">Application Temporarily Locked</div>
                  <?php if ($lockPaymentStatus === 'for payment'): ?>
                    You cannot submit a new parking permit request yet because your previous request is already approved and waiting for payment.
                  <?php else: ?>
                    You cannot submit a new parking permit request yet because your previous request is still waiting for admin approval.
                  <?php endif; ?>
                </div>

                <form class="form-disabled">
                  <div class="fw-bold mb-2">Vehicle Details</div>
                  <div class="mb-2">
                    <label class="form-label fw-semibold">Plate Number</label>
                    <input type="text" class="form-control" disabled value="<?= esc($currentStatusPermit['plate_no'] ?? '') ?>">
                  </div>

                  <div class="mb-2">
                    <label class="form-label fw-semibold">Vehicle Type</label>
                    <input type="text" class="form-control" disabled value="<?= esc(vehicle_type_label((string)($currentStatusPermit['vehicle_type'] ?? 'car'))) ?>">
                  </div>

                  <div class="row g-2">
                    <div class="col-md-4">
                      <label class="form-label fw-semibold">Brand</label>
                      <input type="text" class="form-control" disabled value="<?= esc($currentStatusPermit['vehicle_make'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label fw-semibold">Model</label>
                      <input type="text" class="form-control" disabled value="<?= esc($currentStatusPermit['vehicle_model'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label fw-semibold">Color</label>
                      <input type="text" class="form-control" disabled value="<?= esc($currentStatusPermit['vehicle_color'] ?? '') ?>">
                    </div>
                  </div>

                  <div class="mt-2">
                    <label class="form-label fw-semibold">Permit Duration</label>
                    <input type="text" class="form-control" disabled value="<?= esc(duration_label((string)($currentStatusPermit['permit_duration'] ?? ''))) ?>">
                  </div>

                  <div class="mt-2 mb-3">
                    <label class="form-label fw-semibold">Payment Method</label>
                    <input type="text" class="form-control" disabled value="<?= esc(payment_label((string)($currentStatusPermit['payment_method'] ?? ''))) ?>">
                  </div>

                  <button type="button" class="btn btn-secondary w-100 fw-bold py-2" disabled>
                    <i class="bi bi-lock me-1"></i>
                    <?php if ($lockPaymentStatus === 'for payment'): ?>
                      Finish Payment First
                    <?php else: ?>
                      Wait for Admin Approval
                    <?php endif; ?>
                  </button>
                </form>

              <?php elseif ($renewPermitId > 0 && (!$renewPermit || !$renewAllowed)): ?>
                <div class="alert alert-secondary">
                  <div class="fw-bold mb-1">Renewal Not Yet Available</div>
                  Renewal requests are only allowed within <?= (int)$renewalWindowDays ?> days before the active permit expires.
                </div>

              <?php else: ?>
                <?php
                  $prefillPlate = $renewPermit['plate_no'] ?? '';
                  $prefillMake  = $renewPermit['vehicle_make'] ?? '';
                  $prefillModel = $renewPermit['vehicle_model'] ?? '';
                  $prefillColor = $renewPermit['vehicle_color'] ?? '';
                  $prefillVehicleType = $renewPermit['vehicle_type'] ?? '';
                  $nextStartDate = '';

                  if ($renewPermit && !empty($renewPermit['valid_until'])) {
                    $nextStartDate = (new DateTime($renewPermit['valid_until']))->modify('+1 day')->format('Y-m-d');
                  }
                ?>
                <form method="POST" enctype="multipart/form-data" class="permit-box p-3">
                  <input type="hidden" name="submit_permit" value="1">
                  <input type="hidden" name="renew_of_id" value="<?= $renewAllowed && $renewPermit ? (int)$renewPermit['id'] : 0 ?>">

                  <div class="fw-bold mb-2">
                    <?= $renewAllowed && $renewPermit ? 'Renew Permit' : 'New Permit Application' ?>
                  </div>

                  <?php if ($renewAllowed && $renewPermit): ?>
                    <div class="alert alert-light border small">
                      You are renewing permit <b><?= esc($renewPermit['permit_no'] ?? '—') ?></b>.
                      <?php if ($nextStartDate !== ''): ?>
                        The proposed new validity will start on <b><?= esc($nextStartDate) ?></b>, which is the day after your current permit expires.
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <div class="alert alert-light border small">
                      The system will automatically compute your proposed validity period based on your selected duration.
                      Your request will first go to admin approval. Payment will only be available after approval.
                    </div>
                  <?php endif; ?>

                  <div class="mb-2">
                    <label class="form-label fw-semibold">Plate Number</label>
                    <input type="text" name="plate_no" class="form-control" required maxlength="30" value="<?= esc($prefillPlate) ?>">
                  </div>

                  <div class="mb-2">
                    <label class="form-label fw-semibold">Vehicle Type</label>
                    <select name="vehicle_type" class="form-select" required>
                      <option value="">Select Vehicle Type</option>
                      <option value="car" <?= $prefillVehicleType === 'car' ? 'selected' : '' ?>>Car</option>
                      <option value="motorcycle" <?= $prefillVehicleType === 'motorcycle' ? 'selected' : '' ?>>Motorcycle</option>
                      <option value="ebike" <?= $prefillVehicleType === 'ebike' ? 'selected' : '' ?>>E-Bike</option>
                    </select>
                  </div>

                  <div class="row g-2">
                    <div class="col-md-4">
                      <label class="form-label fw-semibold">Brand</label>
                      <input type="text" name="vehicle_make" class="form-control" maxlength="80" value="<?= esc($prefillMake) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label fw-semibold">Model</label>
                      <input type="text" name="vehicle_model" class="form-control" maxlength="80" value="<?= esc($prefillModel) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label fw-semibold">Color</label>
                      <input type="text" name="vehicle_color" class="form-control" maxlength="50" value="<?= esc($prefillColor) ?>">
                    </div>
                  </div>

                  <div class="mt-2">
                    <label class="form-label fw-semibold">Permit Duration</label>
                    <select name="permit_duration" class="form-select" required>
                      <option value="" selected>Select Duration</option>
                      <option value="1_month">1 Month</option>
                      <option value="3_months">3 Months</option>
                      <option value="6_months">6 Months</option>
                      <option value="1_year">1 Year</option>
                    </select>
                  </div>

                  <div class="mt-2 mb-3">
                    <label class="form-label fw-semibold">Payment Method</label>
                    <select name="payment_method" class="form-select" required>
                      <option value="" selected>Select Payment</option>
                      <option value="online">Online Payment</option>
                      <option value="cash">Cash / Physical Payment</option>
                    </select>
                  </div>

                  <hr>

                  <div class="fw-bold mb-2">Upload Requirements</div>

                  <div class="mb-2">
                    <label class="file-label">Picture of Vehicle (Front)</label>
                    <input type="file" name="vehicle_front" class="form-control" required accept=".pdf,.jpg,.jpeg,.png">
                  </div>

                  <div class="mb-3">
                    <label class="file-label">Picture of Vehicle (Back)</label>
                    <input type="file" name="vehicle_back" class="form-control" required accept=".pdf,.jpg,.jpeg,.png">
                  </div>

                  <button class="btn btn-success w-100 fw-bold py-2">
                    <i class="bi bi-send me-1"></i>
                    <?= $renewAllowed && $renewPermit ? 'Submit Renewal Request' : 'Submit Permit Request' ?>
                  </button>

                  <div class="text-muted small fw-semibold mt-2">
                    The form resets on reload. Previous application details are shown only in the Current Status section.
                  </div>
                </form>
              <?php endif; ?>
            </div>
          </div>

        </div>
      </div>

      <div class="mt-4 text-center text-muted small fw-semibold">
        © South Meridian Homes Salitran
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const wrap = document.getElementById('sbParking');
  const btn  = document.getElementById('sbParkingToggle');
  if(!wrap || !btn) return;
  btn.addEventListener('click', () => wrap.classList.toggle('open'));
})();

(function(){
  const tenantWrap = document.getElementById('sbTenant');
  const tenantBtn  = document.getElementById('sbTenantToggle');
  if(!tenantWrap || !tenantBtn) return;
  tenantBtn.addEventListener('click', () => tenantWrap.classList.toggle('open'));
})();

(function(){
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const toggle  = document.getElementById('sidebarToggle');

  if (!sidebar || !overlay || !toggle) return;

  function openSidebar(){
    sidebar.classList.add('show');
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar(){
    sidebar.classList.remove('show');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
  }

  toggle.addEventListener('click', openSidebar);
  overlay.addEventListener('click', closeSidebar);

  window.addEventListener('resize', function(){
    if (window.innerWidth >= 992) closeSidebar();
  });

  sidebar.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', function(){
      if (window.innerWidth < 992) closeSidebar();
    });
  });
})();
</script>
</body>
</html>