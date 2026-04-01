<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = "localhost";
$db   = "u972459197_south_meridian";
$user = "u972459197_patrick";
$pass = "Idle2440";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

function fail_and_redirect(string $message, string $location = 'register.html'): void
{
    $safeMessage = json_encode($message);
    $safeLocation = json_encode($location);

    echo "
    <html>
    <head>
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    </head>
    <body>
    <script>
        Swal.fire({
            icon: 'error',
            title: 'Registration Error',
            text: $safeMessage,
            confirmButtonColor: '#d33'
        }).then(() => {
            window.location.href = $safeLocation;
        });
    </script>
    </body>
    </html>
    ";
    exit;
}

function generatePublicId(mysqli $conn, string $phase): string
{
    $phaseNumber = (int) filter_var($phase, FILTER_SANITIZE_NUMBER_INT);
    if ($phaseNumber <= 0) {
        $phaseNumber = 1;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM homeowners WHERE phase = ?");
    $stmt->bind_param("s", $phase);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $sequence = (int)($result['total'] ?? 0) + 1;

    do {
        $candidate = 'P' . $phaseNumber . $sequence;

        $stmt = $conn->prepare("SELECT id FROM homeowners WHERE public_id = ? LIMIT 1");
        $stmt->bind_param("s", $candidate);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($exists) {
            $sequence++;
        }
    } while ($exists);

    return $candidate;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: register.html");
    exit;
}

// ===================== GET FORM DATA =====================
$first_name          = trim($_POST['first_name'] ?? '');
$middle_name         = trim($_POST['middle_name'] ?? '');
$last_name           = trim($_POST['last_name'] ?? '');
$contact_number      = trim($_POST['contact_number'] ?? '');
$email               = trim($_POST['email'] ?? '');
$raw_password        = (string)($_POST['password'] ?? '');
$phase               = trim($_POST['phase'] ?? '');
$house_lot_number    = trim($_POST['house_lot_number'] ?? '');

$barangay            = trim($_POST['barangay'] ?? '');
$city_municipality   = trim($_POST['city_municipality'] ?? '');
$province            = trim($_POST['province'] ?? '');
$region              = trim($_POST['region'] ?? '');
$zip_code            = trim($_POST['zip_code'] ?? '');
$country             = trim($_POST['country'] ?? '');
$other_location_info = trim($_POST['other_location_info'] ?? '');

$latitude            = trim((string)($_POST['latitude'] ?? ''));
$longitude           = trim((string)($_POST['longitude'] ?? ''));

// ===================== VALIDATION =====================
if (
    $first_name === '' ||
    $last_name === '' ||
    $contact_number === '' ||
    $email === '' ||
    $raw_password === '' ||
    $phase === '' ||
    $house_lot_number === ''
) {
    fail_and_redirect("Missing required fields.");
}

if (strlen($raw_password) < 8) {
    fail_and_redirect("Password must be at least 8 characters.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail_and_redirect("Invalid email address.");
}

if ($latitude === '' || $longitude === '' || !is_numeric($latitude) || !is_numeric($longitude)) {
    fail_and_redirect("Please pin your location on the map.");
}

$latitudeF  = (float)$latitude;
$longitudeF = (float)$longitude;

// ===================== CHECK EMAIL FIRST =====================
try {
    $checkEmail = $conn->prepare("SELECT id FROM homeowners WHERE email = ? LIMIT 1");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $existing = $checkEmail->get_result()->fetch_assoc();
    $checkEmail->close();

    if ($existing) {
        fail_and_redirect("This email address is already registered. Please use another email.");
    }
} catch (Throwable $e) {
    fail_and_redirect("Failed to validate email. Please try again.");
}

// ===================== ASSIGN ADMIN BY PHASE =====================
$admin_id = null;

try {
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
        $admin_id = (int)$resAdmin['id'];
    }
} catch (Throwable $e) {
    fail_and_redirect("Failed to assign admin for this phase.");
}

// ===================== FILE UPLOADS =====================
$uploadDirFs = __DIR__ . "/uploads/";
$uploadDirDb = "uploads/";

if (!is_dir($uploadDirFs) && !mkdir($uploadDirFs, 0755, true)) {
    fail_and_redirect("Failed to create upload directory.");
}

if (
    empty($_FILES['valid_id']['name']) ||
    empty($_FILES['proof_of_billing']['name']) ||
    !is_uploaded_file($_FILES['valid_id']['tmp_name']) ||
    !is_uploaded_file($_FILES['proof_of_billing']['tmp_name'])
) {
    fail_and_redirect("Please upload Valid ID and Proof of Billing.");
}

$validDbPath = '';
$proofDbPath = '';
$validFsPath = '';
$proofFsPath = '';

try {
    $stamp = time() . '_' . bin2hex(random_bytes(4));

    $validName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['valid_id']['name']));
    $proofName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['proof_of_billing']['name']));

    $validDbPath = $uploadDirDb . $stamp . "_id_" . $validName;
    $proofDbPath = $uploadDirDb . $stamp . "_proof_" . $proofName;

    $validFsPath = $uploadDirFs . $stamp . "_id_" . $validName;
    $proofFsPath = $uploadDirFs . $stamp . "_proof_" . $proofName;

    if (!move_uploaded_file($_FILES['valid_id']['tmp_name'], $validFsPath)) {
        fail_and_redirect("Failed to upload Valid ID.");
    }

    if (!move_uploaded_file($_FILES['proof_of_billing']['tmp_name'], $proofFsPath)) {
        if (is_file($validFsPath)) {
            @unlink($validFsPath);
        }
        fail_and_redirect("Failed to upload Proof of Billing.");
    }
} catch (Throwable $e) {
    if ($validFsPath !== '' && is_file($validFsPath)) {
        @unlink($validFsPath);
    }
    if ($proofFsPath !== '' && is_file($proofFsPath)) {
        @unlink($proofFsPath);
    }
    fail_and_redirect("File upload failed.");
}

$password_hash = password_hash($raw_password, PASSWORD_DEFAULT);
$status = 'pending';
$homeowner_id = 0;
$public_id = '';

// ===================== SAVE TO DATABASE =====================
try {
    $conn->begin_transaction();

    // second duplicate check inside transaction for safety
    $checkEmail2 = $conn->prepare("SELECT id FROM homeowners WHERE email = ? LIMIT 1");
    $checkEmail2->bind_param("s", $email);
    $checkEmail2->execute();
    $existing2 = $checkEmail2->get_result()->fetch_assoc();
    $checkEmail2->close();

    if ($existing2) {
        throw new Exception("This email address is already registered. Please use another email.");
    }

    $stmtHome = $conn->prepare("
        INSERT INTO homeowners
        (
            first_name,
            middle_name,
            last_name,
            contact_number,
            email,
            password,
            phase,
            house_lot_number,
            barangay,
            city_municipality,
            province,
            region,
            zip_code,
            country,
            other_location_info,
            valid_id_path,
            proof_of_billing_path,
            latitude,
            longitude,
            admin_id,
            status
        )
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $stmtHome->bind_param(
        "sssssssssssssssssddis",
        $first_name,
        $middle_name,
        $last_name,
        $contact_number,
        $email,
        $password_hash,
        $phase,
        $house_lot_number,
        $barangay,
        $city_municipality,
        $province,
        $region,
        $zip_code,
        $country,
        $other_location_info,
        $validDbPath,
        $proofDbPath,
        $latitudeF,
        $longitudeF,
        $admin_id,
        $status
    );

    $stmtHome->execute();
    $homeowner_id = (int)$stmtHome->insert_id;
    $stmtHome->close();

    $public_id = generatePublicId($conn, $phase);

    $stmtPub = $conn->prepare("UPDATE homeowners SET public_id = ? WHERE id = ?");
    $stmtPub->bind_param("si", $public_id, $homeowner_id);
    $stmtPub->execute();
    $stmtPub->close();

    // ===================== INSERT HOUSEHOLD MEMBERS =====================
    $member_first_name  = $_POST['member_first_name'] ?? [];
    $member_middle_name = $_POST['member_middle_name'] ?? [];
    $member_last_name   = $_POST['member_last_name'] ?? [];
    $member_relation    = $_POST['member_relation'] ?? [];

    if (!empty($member_first_name) && is_array($member_first_name)) {
        $stmtMember = $conn->prepare("
            INSERT INTO household_members
            (homeowner_id, first_name, middle_name, last_name, relation)
            VALUES (?,?,?,?,?)
        ");

        foreach ($member_first_name as $i => $mfname) {
            $mfname   = trim((string)$mfname);
            $mmname   = trim((string)($member_middle_name[$i] ?? ''));
            $mlname   = trim((string)($member_last_name[$i] ?? ''));
            $relation = trim((string)($member_relation[$i] ?? ''));

            if ($mfname === '' && $mmname === '' && $mlname === '' && $relation === '') {
                continue;
            }

            $stmtMember->bind_param("issss", $homeowner_id, $mfname, $mmname, $mlname, $relation);
            $stmtMember->execute();
        }

        $stmtMember->close();
    }

    $conn->commit();
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    if ($validFsPath !== '' && is_file($validFsPath)) {
        @unlink($validFsPath);
    }
    if ($proofFsPath !== '' && is_file($proofFsPath)) {
        @unlink($proofFsPath);
    }

    $msg = $e->getMessage();

    if (stripos($msg, 'Duplicate entry') !== false && stripos($msg, 'email') !== false) {
        $msg = "This email address is already registered. Please use another email.";
    }

    fail_and_redirect($msg);
}

// ===================== SEND PENDING APPROVAL EMAIL =====================
$emailSent = false;
$emailError = '';

try {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'baculpopatrick2440@gmail.com';
    $mail->Password   = 'vxsx lmtv livx hgtl';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('baculpopatrick2440@gmail.com', 'South Meridian HOA');
    $mail->addAddress($email, $first_name . ' ' . $last_name);
    $mail->isHTML(true);

    $safeFirst = htmlspecialchars($first_name, ENT_QUOTES, 'UTF-8');
    $safeLast  = htmlspecialchars($last_name, ENT_QUOTES, 'UTF-8');
    $safePhase = htmlspecialchars($phase, ENT_QUOTES, 'UTF-8');
    $safeLot   = htmlspecialchars($house_lot_number, ENT_QUOTES, 'UTF-8');
    $safeId    = htmlspecialchars($public_id, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

    $mail->Subject = 'South Meridian HOA Registration Received';
    $mail->Body = "
        <h3>Hello {$safeFirst} {$safeLast},</h3>

        <p>Thank you for registering with <strong>South Meridian HOA</strong>.</p>

        <p>Your account has been successfully submitted and is now <strong>under review / ongoing approval</strong>.</p>

        <p><strong>Registration Details:</strong></p>
        <ul>
            <li><strong>Reference ID:</strong> {$safeId}</li>
            <li><strong>Phase:</strong> {$safePhase}</li>
            <li><strong>House / Lot Number:</strong> {$safeLot}</li>
            <li><strong>Email:</strong> {$safeEmail}</li>
        </ul>

        <p>Please wait for the HOA admin to review and verify your submitted information and documents.</p>
        <p>You will receive another email once your account has been approved or if further action is needed.</p>

        <br>
        <p>Thank you,</p>
        <p><strong>South Meridian HOA</strong></p>
    ";

    $mail->AltBody =
        "Hello {$first_name} {$last_name},\n\n" .
        "Thank you for registering with South Meridian HOA.\n" .
        "Your account has been successfully submitted and is now under review / ongoing approval.\n\n" .
        "Reference ID: {$public_id}\n" .
        "Phase: {$phase}\n" .
        "House / Lot Number: {$house_lot_number}\n" .
        "Email: {$email}\n\n" .
        "Please wait for the HOA admin to review your submission.\n" .
        "You will receive another email once your account has been approved or if further action is needed.\n\n" .
        "South Meridian HOA";

    $mail->send();
    $emailSent = true;
} catch (Exception $e) {
    $emailError = $mail->ErrorInfo ?? $e->getMessage();
}

// ===================== REDIRECT =====================
if ($emailSent) {
    $_SESSION['success_message'] = "Registration complete! Your account is now pending approval. A confirmation email has been sent to your email address.";
} else {
    $_SESSION['success_message'] = "Registration complete! Your account is now pending approval. Email sending failed, but your registration was saved successfully.";
    $_SESSION['email_error'] = $emailError;
}

header("Location: index.php");
exit;
?>