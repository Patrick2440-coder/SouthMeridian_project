<?php

session_start();

require_once 'admin_access.php';

require_once '../config/database.php';

require_once 'ownership_transfer_common.php';

require_once 'homeowner_activity_logger.php';

requireAccess('homeowner_management');

if (!function_exists('esc')) {

    function esc($v): string {

        return htmlspecialchars(

            (string)$v,

            ENT_QUOTES,

            'UTF-8'

        );

    }

}

function redirect_with_message(string $type, string $message, string $location = 'ho_register.php'){

  $_SESSION['flash_type'] = $type;

  $_SESSION['flash_message'] = $message;

  header("Location: " . $location);

  exit;

}

function normalizePhase($phase){

    $phase = trim((string)$phase);

    $allowed = [

        'Phase 1',

        'Phase 2',

        'Phase 3'

    ];

    return in_array(

        $phase,

        $allowed,

        true

    )

        ? $phase

        : '';

}

/*

|--------------------------------------------------------------------------

| Official South Meridian Block / Lot Mapping

|--------------------------------------------------------------------------

*/

function loadSouthMeridianLocations(): array

{

    $path =

        __DIR__ .

        '/southmeri_block_lot_mapping.json';

    if (!is_readable($path)) {

        throw new RuntimeException(

            'South Meridian Block/Lot mapping file was not found.'

        );

    }

    $decoded =

        json_decode(

            (string) file_get_contents($path),

            true

        );

    if (!is_array($decoded)) {

        throw new RuntimeException(

            'South Meridian Block/Lot mapping file is invalid.'

        );

    }

    $pageWidthEmu =

        8.5 * 914400;

    $pageHeightEmu =

        11 * 914400;

    $pageMarginEmu =

        914400;

    $markerCenterOffsetEmu =

        90000;

    $locations = [];

    foreach ($decoded as $item) {

        $block =

            (int)($item['block'] ?? 0);

        $lot =

            (int)($item['lot'] ?? 0);

        if (

            $block <= 0 ||

            $lot <= 0

        ) {

            continue;

        }

        $xEmu =

            (float)($item['x_emu'] ?? 0);

        $yEmu =

            (float)($item['y_emu'] ?? 0);

        $mapX =

            (int) round(

                (

                    (

                        $pageMarginEmu +

                        $xEmu +

                        $markerCenterOffsetEmu

                    )

                    /

                    $pageWidthEmu

                )

                * 2550

            );

        $mapY =

            (int) round(

                (

                    (

                        $pageMarginEmu +

                        $yEmu +

                        $markerCenterOffsetEmu

                    )

                    /

                    $pageHeightEmu

                )

                * 3300

            );

        $locations[

            $block . ':' . $lot

        ] = [

            'block' =>

                $block,

            'lot' =>

                $lot,

            'street' =>

                trim(

                    (string)(

                        $item['street'] ?? ''

                    )

                ),

            'map_x' =>

                $mapX,

            'map_y' =>

                $mapY

        ];

    }

    return $locations;

}

function phase_prefix(string $phase): string {

  $n = (int) filter_var($phase, FILTER_SANITIZE_NUMBER_INT);

  return $n > 0 ? ('P'.$n) : 'P';

}

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||

    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {

  $_SESSION['flash_type'] = 'danger';

  $_SESSION['flash_message'] = 'Access denied. Please login as admin.';

  header("Location: index.php");

  exit();

}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// admin info from DB

$admin_id = (int)$_SESSION['admin_id'];

$stmt = $conn->prepare("SELECT phase, role FROM admins WHERE id=? LIMIT 1");

$stmt->bind_param("i", $admin_id);

$stmt->execute();

$admin = $stmt->get_result()->fetch_assoc();

$stmt->close();

$admin_phase = $admin['phase'] ?? '';

$admin_role  = $admin['role'] ?? '';

$isHOSection = true;

if (!isset($permissions) || !is_array($permissions)) {

  $permissions = [];

}

/*

 * Load official subdivision locations once.

 */

try {

    $southMeridianLocations =

        loadSouthMeridianLocations();

} catch (Throwable $e) {

    redirect_with_message(

        'danger',

        $e->getMessage()

    );

}

// ---- STEP 2: Final submission with confirmed Block/Lot location ----

if (isset($_POST['submit_location'])) {

  try {

$first_name =

    trim(

        (string)(

            $_POST['first_name'] ?? ''

        )

    );

$middle_name =

    trim(

        (string)(

            $_POST['middle_name'] ?? ''

        )

    );

$last_name =

    trim(

        (string)(

            $_POST['last_name'] ?? ''

        )

    );

$contact_number =

    trim(

        (string)(

            $_POST['contact_number'] ?? ''

        )

    );

$email =

    trim(

        (string)(

            $_POST['email'] ?? ''

        )

    );

$other_location_info =

    trim(

        (string)(

            $_POST['other_location_info'] ?? ''

        )

    );

$length_of_residency =

    trim(

        (string)(

            $_POST['length_of_residency'] ?? ''

        )

    );

$residential_type =

    trim(

        (string)(

            $_POST['residential_type'] ?? ''

        )

    );

$emergency_contact_person =

    trim(

        (string)(

            $_POST['emergency_contact_person'] ?? ''

        )

    );

$emergency_contact_number =

    trim(

        (string)(

            $_POST['emergency_contact_number'] ?? ''

        )

    );

/*

|--------------------------------------------------------------------------

| Phase

|--------------------------------------------------------------------------

|

| Normal admins are locked to their assigned phase.

| Superadmin may choose Phase 1, 2 or 3.

|

*/

if ($admin_role === 'superadmin') {

    $phase =

        normalizePhase(

            $_POST['phase'] ?? ''

        );

} else {

    $phase =

        normalizePhase(

            $admin_phase

        );

}

/*

|--------------------------------------------------------------------------

| Block / Lot

|--------------------------------------------------------------------------

*/

$block =

    (int)(

        $_POST['block'] ?? 0

    );

$lot =

    (int)(

        $_POST['lot'] ?? 0

    );

/*

|--------------------------------------------------------------------------

| Required Fields

|--------------------------------------------------------------------------

*/

if (

    $first_name === '' ||

    $last_name === '' ||

    $contact_number === '' ||

    $email === '' ||

    $phase === '' ||

    $block <= 0 ||

    $lot <= 0 ||

    $length_of_residency === '' ||

    $residential_type === '' ||

    $emergency_contact_person === '' ||

    $emergency_contact_number === ''

) {

    redirect_with_message(

        'danger',

        'Missing required fields.'

    );

}

/*

|--------------------------------------------------------------------------

| Verify Block / Lot against official South Meridian map

|--------------------------------------------------------------------------

*/

$locationKey =

    $block . ':' . $lot;

if (

    !isset(

        $southMeridianLocations[

            $locationKey

        ]

    )

) {

    redirect_with_message(

        'danger',

        "Block {$block}, Lot {$lot} is not a valid South Meridian property."

    );

}

$location =

    $southMeridianLocations[

        $locationKey

    ];

/*

|--------------------------------------------------------------------------

| Canonical Location Values

|--------------------------------------------------------------------------

|

| These values come from the official subdivision mapping.

| Do NOT accept them directly from the browser.

|

*/

$street =

    trim(

        (string)(

            $location['street'] ?? ''

        )

    );

$map_x =

    (int)(

        $location['map_x'] ?? 0

    );

$map_y =

    (int)(

        $location['map_y'] ?? 0

    );

$house_lot_number =

    "Block {$block} Lot {$lot}";

$barangay =

    'Salitran IV';

$city_municipality =

    'Dasmarinas City';

$province =

    'Cavite';

$region =

    'CALABARZON';

$zip_code =

    '4114';

$country =

    'Philippines';

$exact_location =

    "Block {$block}, Lot {$lot}" .

    (

        $street !== ''

            ? ", {$street}"

            : ''

    );

/*

|--------------------------------------------------------------------------

| Validate account fields

|--------------------------------------------------------------------------

*/

if (

    !filter_var(

        $email,

        FILTER_VALIDATE_EMAIL

    )

) {

    redirect_with_message(

        'danger',

        'Invalid email address.'

    );

}

if (

    strlen(

        $contact_number

    ) > 15

) {

    redirect_with_message(

        'danger',

        'Contact number must not exceed 15 characters.'

    );

}

if (

    !in_array(

        $residential_type,

        ['Owner', 'Renter/Tenant'],

        true

    )

) {

    redirect_with_message(

        'danger',

        'Residential type must be Owner or Renter/Tenant.'

    );

}

if (

    strlen(

        $emergency_contact_number

    ) > 20

) {

    redirect_with_message(

        'danger',

        'Emergency contact number must not exceed 20 characters.'

    );

}

/*

|--------------------------------------------------------------------------

| Homeowner documents

|--------------------------------------------------------------------------

|

| Valid ID and Proof of Billing are intentionally not collected during

| admin-side Register Household. The homeowner will upload them later

| from the homeowner side.

|

*/

$valid_id_path = '';

$proof_path = '';

    // check duplicate email first

    $stmtCheck = $conn->prepare("SELECT id FROM homeowners WHERE email = ? LIMIT 1");

    $stmtCheck->bind_param("s", $email);

    $stmtCheck->execute();

    $exists = $stmtCheck->get_result()->fetch_assoc();

    $stmtCheck->close();

    if ($exists) {

      redirect_with_message('danger', 'Email already exists.');

    }

    /*

     * A different person/email using a property that already has an

     * approved homeowner is NOT rejected here. Save the registration as

     * Pending, then require the ownership-transfer verification flow.

     */

    $possibleTransferOwner = otFindApprovedPropertyOwner(

        $conn,

        [

            'phase' => $phase,

            'block' => $block,

            'lot' => $lot,

            'house_lot_number' => $house_lot_number,

            'email' => $email

        ]

    );

    // assign admin based on phase

    $assigned_admin_id = null;

    $stmtAdmin = $conn->prepare("

      SELECT id

      FROM admins

      WHERE phase = ?

      ORDER BY CASE WHEN position = 'President' THEN 0 ELSE 1 END, id ASC

      LIMIT 1

    ");

    $stmtAdmin->bind_param("s", $phase);

    $stmtAdmin->execute();

    $resAdmin = $stmtAdmin->get_result()->fetch_assoc();

    $stmtAdmin->close();

    if ($resAdmin && isset($resAdmin['id'])) {

      $assigned_admin_id = (int)$resAdmin['id'];

    }

/*

|--------------------------------------------------------------------------

| Temporary Pending Account Password

|--------------------------------------------------------------------------

|

| The admin does not choose the homeowner password.

| A random password is stored while the account is pending.

| The homeowner will set the real password after the record is reviewed and pushed to active homeowner data.

|

*/

$temporaryPassword =

    bin2hex(

        random_bytes(32)

    );

$password =

    password_hash(

        $temporaryPassword,

        PASSWORD_DEFAULT

    );

unset(

    $temporaryPassword

);

$status = 'pending';

$conn->begin_transaction();

$stmtHome = $conn->prepare("

    INSERT INTO homeowners

    (

        public_id,

        first_name,

        middle_name,

        last_name,

        contact_number,

        email,

        password,

        must_change_password,

        phase,

        house_lot_number,

        block,

        lot,

        street,

        barangay,

        city_municipality,

        province,

        region,

        zip_code,

        country,

        other_location_info,

        length_of_residency,

        residential_type,

        emergency_contact_person,

        emergency_contact_number,

        exact_location,

        valid_id_path,

        proof_of_billing_path,

        map_x,

        map_y,

        admin_id,

        status

    )

    VALUES

    (

        NULL,

        ?,?,?,?,?,?,

        1,

        ?,?,?,?,

        ?,?,?,?,?,?,

        ?,?,?,?,?,?,

        ?,?,?,?,

        ?,?,?,

        ?

    )

");

$stmtHome->bind_param(

    "ssssssssii" .

    "sssssssssssssss" .

    "iiis",

    $first_name,

    $middle_name,

    $last_name,

    $contact_number,

    $email,

    $password,

    $phase,

    $house_lot_number,

    $block,

    $lot,

    $street,

    $barangay,

    $city_municipality,

    $province,

    $region,

    $zip_code,

    $country,

    $other_location_info,

    $length_of_residency,

    $residential_type,

    $emergency_contact_person,

    $emergency_contact_number,

    $exact_location,

    $valid_id_path,

    $proof_path,

    $map_x,

    $map_y,

    $assigned_admin_id,

    $status

);

$stmtHome->execute();

$homeowner_id =

    (int)$stmtHome->insert_id;

$stmtHome->close();

/*

|--------------------------------------------------------------------------

| Generate Public Homeowner ID

|--------------------------------------------------------------------------

|

| Public ID now uses the actual database homeowner ID.

|

| Examples:

|

| homeowner id 25 + Phase 1 = P1H025

| homeowner id 26 + Phase 2 = P2H026

| homeowner id 27 + Phase 3 = P3H027

|

*/

$phaseNumber =

    (int)filter_var(

        $phase,

        FILTER_SANITIZE_NUMBER_INT

    );

if (

    $phaseNumber < 1 ||

    $phaseNumber > 3

) {

    throw new RuntimeException(

        'Unable to generate homeowner public ID.'

    );

}

$public_id =
    'P' .
    $phaseNumber .
    'H' .
    str_pad(
        (string)$homeowner_id,
        3,
        '0',
        STR_PAD_LEFT
    );

$stmtPublicId =

    $conn->prepare("

        UPDATE homeowners

        SET public_id=?

        WHERE id=?

        LIMIT 1

    ");

$stmtPublicId->bind_param(

    "si",

    $public_id,

    $homeowner_id

);

$stmtPublicId->execute();

if ($stmtPublicId->affected_rows !== 1) {

    $stmtPublicId->close();

    throw new RuntimeException(

        'Failed to generate homeowner public ID.'

    );

}

$stmtPublicId->close();

if (

    isset($_POST['member_first_name']) &&

    is_array($_POST['member_first_name'])

) {

      $stmtMember = $conn->prepare("

        INSERT INTO household_members

        (homeowner_id, first_name, middle_name, last_name, relation)

        VALUES (?,?,?,?,?)

      ");

      foreach ($_POST['member_first_name'] as $i => $mfname) {

        $mfname   = trim((string)$mfname);

        $mmname   = trim((string)($_POST['member_middle_name'][$i] ?? ''));

        $mlname   = trim((string)($_POST['member_last_name'][$i] ?? ''));

        $relation = trim((string)($_POST['member_relation'][$i] ?? ''));

        $allowedRelations = ['Homeowner','Spouse','Child','Parent','Relative','Tenant','Caretaker'];if ($mfname === '' && $mlname === '' && $relation === '') {

          continue;

        }

        if ($mfname === '' || $mlname === '' || !in_array($relation, $allowedRelations, true)) {

          throw new Exception('One or more household members have incomplete or invalid details.');

        }

        $stmtMember->bind_param("issss", $homeowner_id, $mfname, $mmname, $mlname, $relation);

        $stmtMember->execute();

      }

      $stmtMember->close();

    }

    $conn->commit();

    if ($possibleTransferOwner && otEmailsDifferent(

        ['email' => $email],

        $possibleTransferOwner

    )) {

        $oldOwnerName = otFullName($possibleTransferOwner);

        logHomeownerActivity(

            $conn,

            'Possible ownership transfer detected',

            "Pending homeowner {$public_id} - {$first_name} {$last_name} was registered for {$phase}, Block {$block}, Lot {$lot}, which is currently assigned to {$oldOwnerName} (#" . (int)$possibleTransferOwner['id'] . "). Ownership-transfer verification is required before activation.",

            $admin_id,

            $phase

        );

        $_SESSION['flash_type'] = 'warning';

        $_SESSION['flash_message'] =

            'Registration saved as Pending. This Block and Lot already has an active homeowner, so the record was flagged as a Possible Ownership Transfer. Review both homeowners in Household Approval and complete transfer verification before activating the new owner.';

    } else {

        logHomeownerActivity(

            $conn,

            'Homeowner registered for review',

            "Pending homeowner {$public_id} - {$first_name} {$last_name} was registered for {$phase}, Block {$block}, Lot {$lot}.",

            $admin_id,

            $phase

        );

        $_SESSION['flash_type'] = 'success';

        $_SESSION['flash_message'] = 'Household registered successfully and sent to Household Approval for review.';

    }

    header("Location: ho_approval.php");

    exit;

  } catch (Throwable $e) {

    try { $conn->rollback(); } catch (Throwable $ignored) {}

    $msg = $e->getMessage();

    if (stripos($msg, 'Duplicate entry') !== false && stripos($msg, 'uniq_homeowner_email') !== false) {

      $msg = 'Email already exists.';

    }

    redirect_with_message('danger', $msg);

  }

}

// ---- STEP 1: Registration submit (upload documents, then show map) ----

$showMap = isset($_POST['registration_submit']) && !isset($_POST['submit_location']);

?>

<!DOCTYPE html>

<html>

<head>

    <meta charset="utf-8">

    <title>HOA-ADMIN</title>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">

    <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">

    <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">

    <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">

    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

    <script async src="https://www.googletagmanager.com/gtag/js?id=UA-119386393-1"></script>

    <style>

        :root{--brand:#077f46;}

        .card-box{border-radius:14px}

        .page-title-wrap{display:flex;align-items:center;justify-content:center;text-align:center;margin-bottom:14px}

        .page-title-wrap .subtitle{font-size:14px}

        .step-pill{display:inline-flex;gap:8px;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#f8fafc;font-weight:800;font-size:12px}

        .access-toast {

          position: fixed;

          top: 20px;

          right: 20px;

          background: #ef4444;

          color: #fff;

          padding: 12px 18px;

          border-radius: 8px;

          font-weight: 600;

          box-shadow: 0 6px 18px rgba(0,0,0,0.2);

          z-index: 99999;

          opacity: 0;

          transform: translateY(-10px);

          transition: all .3s ease;

        }

        .access-toast.show {

          opacity: 1;

          transform: translateY(0);

        }

        /* Direct header logout */

        .admin-direct-logout-btn {

          display: inline-flex;

          align-items: center;

          justify-content: center;

          gap: 8px;

          min-width: 98px;

          min-height: 40px;

          margin: 0 18px 0 10px;

          padding: 8px 14px;

          border: 1px solid #fecaca;

          border-radius: 11px;

          background: #fff;

          color: #b91c1c !important;

          box-shadow: 0 4px 14px rgba(15, 23, 42, .06);

          font-size: 12px;

          line-height: 1;

          font-weight: 800;

          text-decoration: none !important;

          white-space: nowrap;

          transition: background .18s ease, color .18s ease, border-color .18s ease,

                      transform .18s ease, box-shadow .18s ease;

        }

        .admin-direct-logout-btn i {

          font-size: 17px;

          line-height: 1;

        }

        .admin-direct-logout-btn:hover,

        .admin-direct-logout-btn:focus {

          border-color: #ef4444;

          background: #fef2f2;

          color: #991b1b !important;

          box-shadow: 0 7px 18px rgba(220, 38, 38, .12);

          transform: translateY(-1px);

          outline: none;

        }

        /* Register Household dark-mode extensions */

        html.dark .admin-direct-logout-btn {

          border-color: rgba(248, 113, 113, .30);

          background: rgba(127, 29, 29, .16);

          color: #fca5a5 !important;

          box-shadow: none;

        }

        html.dark .admin-direct-logout-btn:hover,

        html.dark .admin-direct-logout-btn:focus {

          border-color: rgba(248, 113, 113, .55);

          background: rgba(127, 29, 29, .28);

          color: #fecaca !important;

        }

        html.dark .step-pill,

        html.dark #selectedPropertyInfo,

        html.dark .member {

          border-color: var(--admin-border) !important;

          background: var(--admin-surface-2) !important;

          color: var(--admin-text) !important;

        }

        html.dark .form-label,

        html.dark .page-title-wrap h2,

        html.dark .card-box h5 {

          color: var(--admin-text) !important;

        }

        html.dark .text-muted {

          color: var(--admin-muted) !important;

        }

        html.dark .border-bottom {

          border-color: var(--admin-border) !important;

        }

        @media (max-width: 575.98px) {

          .admin-direct-logout-btn {

            width: 40px;

            min-width: 40px;

            height: 40px;

            min-height: 40px;

            margin: 0 10px 0 6px;

            padding: 0;

            border-radius: 10px;

          }

          .admin-direct-logout-btn span {

            display: none;

          }

          .admin-direct-logout-btn i {

            font-size: 18px;

          }

        }

    </style>

    <!-- SHARED ADMIN LIGHT / DARK THEME -->

    <link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">

    <!-- Apply saved theme before the page is painted -->

    <script>

    (function () {

        try {

            const savedTheme = localStorage.getItem('hoa-theme');

            const dark =

                savedTheme === 'dark' ||

                (

                    !savedTheme &&

                    window.matchMedia &&

                    window.matchMedia('(prefers-color-scheme: dark)').matches

                );

            document.documentElement.classList.toggle('dark', dark);

        } catch (e) {}

    })();

    </script>

</head>

<body>

    <div class="header">

        <div class="header-left">

            <div class="menu-icon dw dw-menu"></div>

            <div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>

        </div>

        <div class="header-right">

            <!-- DARK MODE TOGGLE -->

            <div class="admin-theme-switch">

                <button type="button"

                        id="themeToggle"

                        class="admin-theme-toggle"

                        aria-label="Switch theme"

                        title="Switch theme">

                    <span id="themeIcon">☾</span>

                </button>

            </div>

            <!-- DIRECT LOGOUT -->

            <a href="logout.php"

               class="admin-direct-logout-btn"

               title="Log out"

               aria-label="Log out">

                <i class="dw dw-logout" aria-hidden="true"></i>

                <span>Log Out</span>

            </a>

        </div>

    </div>

    <?php include 'sidebar.php'; ?>

    <div class="mobile-menu-overlay"></div>

    <div class="main-container">

        <div class="pd-ltr-20">

            <div class="page-title-wrap">

                <div>

                    <h2 class="h4 mb-1">Home Owner Management</h2>

                    <div class="text-muted fw-semibold subtitle">Register Household</div>

                </div>

            </div>

            <div class="card-box p-3">

                <?php if (!empty($_SESSION['flash_message'])): ?>

                    <div class="alert alert-<?= esc($_SESSION['flash_type'] ?? 'info') ?> alert-dismissible fade show" role="alert">

                        <?= esc($_SESSION['flash_message']) ?>

                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>

                    </div>

                    <?php unset($_SESSION['flash_type'], $_SESSION['flash_message']); ?>

                <?php endif; ?>

                <?php if (!$showMap): ?>

                    <div class="d-flex justify-content-end mb-2">

                        <span class="step-pill">✅ Step 1: Details</span>

                    </div>

                    <form method="POST" id="registrationForm">

                        <h5 class="mb-3 border-bottom pb-2">Homeowner Information</h5>

                        <div class="row g-3 mb-4">

                            <div class="col-md-4">

                                <label class="form-label fw-semibold">First Name</label>

                                <input type="text" name="first_name" class="form-control" required>

                            </div>

                            <div class="col-md-4">

                                <label class="form-label fw-semibold">Middle Name</label>

                                <input type="text" name="middle_name" class="form-control">

                            </div>

                            <div class="col-md-4">

                                <label class="form-label fw-semibold">Last Name</label>

                                <input type="text" name="last_name" class="form-control" required>

                            </div>

                        </div>

                        <div class="row g-3 mb-4">

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">Contact Number</label>

                                <input type="tel" name="contact_number" class="form-control" required>

                            </div>

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">Email Address</label>

                                <input type="email" name="email" class="form-control" required>

                            </div>

                        </div>

<div class="row g-3 mb-4">

    <div class="col-md-4">

        <label class="form-label fw-semibold">

            Phase

        </label>

        <?php if ($admin_role === 'superadmin'): ?>

            <select

                name="phase"

                class="form-select"

                required

            >

                <option

                    value=""

                    disabled

                    selected

                >

                    Select Phase

                </option>

                <option value="Phase 1">

                    Phase 1

                </option>

                <option value="Phase 2">

                    Phase 2

                </option>

                <option value="Phase 3">

                    Phase 3

                </option>

            </select>

        <?php else: ?>

            <input

                type="text"

                class="form-control"

                value="<?= esc($admin_phase) ?>"

                readonly

            >

            <input

                type="hidden"

                name="phase"

                value="<?= esc($admin_phase) ?>"

            >

        <?php endif; ?>

    </div>

    <div class="col-md-4">

        <label class="form-label fw-semibold">

            Block

        </label>

        <select

            name="block"

            id="registerBlock"

            class="form-select"

            required

        >

            <option value="">

                Select Block

            </option>

        </select>

    </div>

    <div class="col-md-4">

        <label class="form-label fw-semibold">

            Lot

        </label>

        <select

            name="lot"

            id="registerLot"

            class="form-select"

            required

            disabled

        >

            <option value="">

                Select Block First

            </option>

        </select>

    </div>

    <div class="col-12">

        <div

            id="selectedPropertyInfo"

            class="alert alert-light border mb-0"

        >

            Select a Block and Lot to view the

            official South Meridian property location.

        </div>

    </div>

</div>

                        <h5 class="mt-4 mb-3 border-bottom pb-2">

                            Resident Details

                        </h5>

                        <div class="row g-3 mb-3">

                            <div class="col-md-4">

                                <label class="form-label fw-semibold">

                                    Length of Residency

                                </label>

                                <input

                                    type="text"

                                    name="length_of_residency"

                                    class="form-control"

                                    placeholder="Example: 5 years"

                                    required

                                >

                            </div>

                            <div class="col-md-4">

                                <label class="form-label fw-semibold">

                                    Residential Type

                                </label>

                                <select

                                    name="residential_type"

                                    class="form-select"

                                    required

                                >

                                    <option value="" selected disabled>

                                        Select Type

                                    </option>

                                    <option value="Owner">

                                        Owner

                                    </option>

                                    <option value="Renter/Tenant">

                                        Renter/Tenant

                                    </option>

                                </select>

                            </div>

                            <div class="col-md-4">

                                <label class="form-label fw-semibold">

                                    Other Location Info

                                </label>

                                <input

                                    type="text"

                                    name="other_location_info"

                                    class="form-control"

                                    placeholder="Optional landmark or note"

                                >

                            </div>

                        </div>

                        <h5 class="mt-4 mb-3 border-bottom pb-2">

                            Emergency Contact

                        </h5>

                        <div class="row g-3 mb-4">

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">

                                    Emergency Contact Person

                                </label>

                                <input

                                    type="text"

                                    name="emergency_contact_person"

                                    class="form-control"

                                    required

                                >

                            </div>

                            <div class="col-md-6">

                                <label class="form-label fw-semibold">

                                    Emergency Contact Number

                                </label>

                                <input

                                    type="tel"

                                    name="emergency_contact_number"

                                    class="form-control"

                                    maxlength="20"

                                    required

                                >

                            </div>

                        </div>

                        <div class="alert alert-light border mb-4">

                            Street, Barangay, City, Province, Region, ZIP Code,

                            Country, and Exact Location are filled automatically

                            from the selected South Meridian Block and Lot.

                        </div>

                        <h5 class="mt-4 mb-3 border-bottom pb-2">Household Members</h5>

                        <div id="members">

                            <div class="member border rounded-3 p-3 mb-3 bg-white">

                                <div class="row g-3 align-items-end">

                                    <div class="col-md-4">

                                        <input type="text" name="member_first_name[]" class="form-control" placeholder="First Name" required>

                                    </div>

                                    <div class="col-md-3">

                                        <input type="text" name="member_middle_name[]" class="form-control" placeholder="Middle Name">

                                    </div>

                                    <div class="col-md-3">

                                        <input type="text" name="member_last_name[]" class="form-control" placeholder="Last Name" required>

                                    </div>

                                    <div class="col-md-2">

                                        <select name="member_relation[]" class="form-control" required>

                                            <option value="" disabled selected>Relation</option>

                                            <option value="Homeowner">Homeowner</option>

                                            <option value="Spouse">Spouse</option>

                                            <option value="Child">Child</option>

                                            <option value="Parent">Parent</option>

                                            <option value="Relative">Relative</option>

                                            <option value="Tenant">Tenant</option>

                                            <option value="Caretaker">Caretaker</option>

                                        </select>

                                    </div>

                                </div>

                            </div>

                        </div>

                        <button type="button" class="btn btn-outline-success mb-3" onclick="addMember()">+ Add Member</button>

                        <div class="d-flex justify-content-end">

<button

    type="submit"

    name="registration_submit"

    class="btn btn-success px-4"

>

    Next: Confirm Location

</button>

                        </div>

                    </form>

<?php else: ?>

    <?php

    $stepBlock =

        (int)(

            $_POST['block'] ?? 0

        );

    $stepLot =

        (int)(

            $_POST['lot'] ?? 0

        );

    $stepLocationKey =

        $stepBlock .

        ':' .

        $stepLot;

    $stepLocation =

        $southMeridianLocations[

            $stepLocationKey

        ]

        ?? null;

    if (!$stepLocation) {

        redirect_with_message(

            'danger',

            'The selected Block and Lot could not be found on the official South Meridian map.'

        );

    }

    $stepMapX =

        (int)$stepLocation['map_x'];

    $stepMapY =

        (int)$stepLocation['map_y'];

    $stepStreet =

        trim(

            (string)(

                $stepLocation['street'] ?? ''

            )

        );

    $markerLeft =

        ($stepMapX / 2550) * 100;

    $markerTop =

        ($stepMapY / 3300) * 100;

    ?>

    <div class="d-flex justify-content-between align-items-center mb-3">

        <span class="step-pill">

            ✅ Step 1: Details

        </span>

        <span class="step-pill">

            📍 Step 2: Confirm Location

        </span>

    </div>

    <form method="POST">

        <h5 class="mb-3 border-bottom pb-2 text-success">

            Confirm Property Location

        </h5>

        <?php

        /*

         * Carry Step 1 fields to final submission.

         */

        $skipHiddenFields = [

            'registration_submit'

        ];

        foreach (

            $_POST as

            $key =>

            $value

        ) {

            if (

                in_array(

                    $key,

                    $skipHiddenFields,

                    true

                )

            ) {

                continue;

            }

            if (is_array($value)) {

                foreach (

                    $value as

                    $v

                ) {

                    echo

                        '<input type="hidden" name="' .

                        esc($key) .

                        '[]" value="' .

                        esc($v) .

                        '">';

                }

            } else {

                echo

                    '<input type="hidden" name="' .

                    esc($key) .

                    '" value="' .

                    esc($value) .

                    '">';

            }

        }

        ?>

<div class="alert alert-success">

            <strong>

                Selected Property

            </strong>

            <br>

            Phase:

            <?= esc(

                $admin_role === 'superadmin'

                    ? ($_POST['phase'] ?? '')

                    : $admin_phase

            ) ?>

            <br>

            Block:

            <?= (int)$stepBlock ?>

            <br>

            Lot:

            <?= (int)$stepLot ?>

            <?php if ($stepStreet !== ''): ?>

                <br>

                Street:

                <?= esc($stepStreet) ?>

            <?php endif; ?>

        </div>

        <div

            style="

                position:relative;

                width:100%;

                max-width:850px;

                margin:0 auto;

                overflow:hidden;

                border:2px solid var(--brand);

                border-radius:14px;

                background:#f3f4f6;

            "

        >

            <img

                src="../assets/img/south_meridian_block_lot_map.png"

                alt="South Meridian Block and Lot Map"

                style="

                    display:block;

                    width:100%;

                    height:auto;

                "

            >

            <div

                title="Block <?= (int)$stepBlock ?>, Lot <?= (int)$stepLot ?>"

                style="

                    position:absolute;

                    left:

                        <?= esc(

                            number_format(

                                $markerLeft,

                                4,

                                '.',

                                ''

                            )

                        ) ?>%;

                    top:

                        <?= esc(

                            number_format(

                                $markerTop,

                                4,

                                '.',

                                ''

                            )

                        ) ?>%;

                    transform:

                        translate(-50%, -100%);

                    font-size:30px;

                    color:#dc3545;

                    text-shadow:

                        0 1px 4px rgba(0,0,0,.45);

                    z-index:5;

                "

            >

                📍

            </div>

        </div>

        <div class="text-center mt-2 text-muted">

            Block <?= (int)$stepBlock ?>,

            Lot <?= (int)$stepLot ?>

            <?php if ($stepStreet !== ''): ?>

                · <?= esc($stepStreet) ?>

            <?php endif; ?>

        </div>

        <button

            type="submit"

            name="submit_location"

            class="btn btn-success w-100 mt-3"

        >

            Confirm &amp; Submit Registration

        </button>

    </form>

<?php endif; ?>

            </div>

            <div class="footer-wrap pd-20 mb-20 card-box">

                © Copyright South Meridian Homes All Rights Reserved

            </div>

        </div>

    </div>

    <script src="vendors/scripts/core.js"></script>

    <script src="vendors/scripts/script.min.js"></script>

    <script src="vendors/scripts/process.js"></script>

    <script src="vendors/scripts/layout-settings.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- ADMIN DARK MODE -->

    <script src="vendors/scripts/admin_theme.js"></script>

    <script>

        function addMember() {

            const members = document.getElementById('members');

            const member = members.firstElementChild.cloneNode(true);

            member.querySelectorAll('input').forEach(input => input.value = '');

            member.querySelectorAll('select').forEach(select => select.selectedIndex = 0);

            members.appendChild(member);

        }

    </script>

    <div id="accessToast" class="access-toast">

      🚫 You do not have access to that part.

    </div>

    <script>

    window.userPermissions = <?= json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    document.addEventListener('DOMContentLoaded', function () {

      const toast = document.getElementById('accessToast');

      function showAccessToast() {

        toast.classList.add('show');

        setTimeout(() => {

          toast.classList.remove('show');

        }, 2500);

      }

      document.querySelectorAll('.menu-access-link').forEach(function(link){

        link.addEventListener('click', function(e){

          const moduleKey = this.dataset.module || '';

          const allowed = !!window.userPermissions[moduleKey];

          if(!allowed){

            e.preventDefault();

            showAccessToast();

          }

        });

      });

    });

    </script>

    <script>

const registerLocations =

    <?= json_encode(

        array_values($southMeridianLocations),

        JSON_UNESCAPED_UNICODE |

        JSON_UNESCAPED_SLASHES

    ) ?>;

document.addEventListener(

    'DOMContentLoaded',

    function () {

        const blockSelect =

            document.getElementById(

                'registerBlock'

            );

        const lotSelect =

            document.getElementById(

                'registerLot'

            );

        const propertyInfo =

            document.getElementById(

                'selectedPropertyInfo'

            );

        if (

            !blockSelect ||

            !lotSelect

        ) {

            return;

        }

        /*

        |--------------------------------------------------------------------------

        | Build Block list

        |--------------------------------------------------------------------------

        */

        const blocks =

            [

                ...new Set(

                    registerLocations.map(

                        item =>

                            Number(

                                item.block

                            )

                    )

                )

            ]

            .filter(

                Number.isFinite

            )

            .sort(

                (a, b) =>

                    a - b

            );

        blocks.forEach(

            block => {

                const option =

                    document.createElement(

                        'option'

                    );

                option.value =

                    block;

                option.textContent =

                    'Block ' + block;

                blockSelect.appendChild(

                    option

                );

            }

        );

        /*

        |--------------------------------------------------------------------------

        | Block changed

        |--------------------------------------------------------------------------

        */

        blockSelect.addEventListener(

            'change',

            function () {

                const block =

                    Number(

                        this.value

                    );

                lotSelect.innerHTML =

                    '<option value="">Select Lot</option>';

                propertyInfo.innerHTML =

                    'Select a Lot.';

                if (!block) {

                    lotSelect.disabled =

                        true;

                    return;

                }

                const lots =

                    registerLocations

                        .filter(

                            item =>

                                Number(

                                    item.block

                                ) === block

                        )

                        .sort(

                            (a, b) =>

                                Number(a.lot) -

                                Number(b.lot)

                        );

                lots.forEach(

                    item => {

                        const option =

                            document.createElement(

                                'option'

                            );

                        option.value =

                            item.lot;

                        option.textContent =

                            'Lot ' +

                            item.lot;

                        lotSelect.appendChild(

                            option

                        );

                    }

                );

                lotSelect.disabled =

                    false;

            }

        );

        /*

        |--------------------------------------------------------------------------

        | Lot changed

        |--------------------------------------------------------------------------

        */

        lotSelect.addEventListener(

            'change',

            function () {

                const block =

                    Number(

                        blockSelect.value

                    );

                const lot =

                    Number(

                        lotSelect.value

                    );

                const location =

                    registerLocations.find(

                        item =>

                            Number(

                                item.block

                            ) === block

                            &&

                            Number(

                                item.lot

                            ) === lot

                    );

                if (!location) {

                    propertyInfo.innerHTML =

                        'Select a valid property.';

                    return;

                }

                propertyInfo.innerHTML =

                    '<strong>Selected Property:</strong> ' +

                    'Block ' +

                    block +

                    ', Lot ' +

                    lot +

                    (

                        location.street

                            ? ' · ' +

                              location.street

                            : ''

                    );

            }

        );

    }

);

</script>

</body>

</html>
