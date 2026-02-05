<?php
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ================= SECURITY CHECK =================
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

// ================= INPUT =================
$id     = intval($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';
$reason = trim($_POST['reason'] ?? '');

if ($id <= 0 || !in_array($status, ['approved','rejected'], true)) {
    echo json_encode(['success'=>false,'message'=>'Invalid request']);
    exit;
}

if ($status === 'rejected' && $reason === '') {
    echo json_encode(['success'=>false,'message'=>'Rejection reason required']);
    exit;
}

// ================= DB =================
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) {
    echo json_encode(['success'=>false,'message'=>'DB error']);
    exit;
}
$conn->set_charset("utf8mb4");

// ================= FETCH HOMEOWNER =================
$stmt = $conn->prepare("SELECT first_name, last_name, email FROM homeowners WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ✅ If approved: set temporary password = last name and force change password
if ($status === 'approved' && $user) {
    $tempPass = $user['last_name']; // homeowner will type this on first login
    $hash = password_hash($tempPass, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("UPDATE homeowners SET password=?, must_change_password=1 WHERE id=?");
    $stmt->bind_param("si", $hash, $id);
    $stmt->execute();
    $stmt->close();
}

if (!$user) {
    echo json_encode(['success'=>false,'message'=>'Homeowner not found']);
    exit;
}

// ================= UPDATE STATUS (+ PASSWORD IF APPROVED) =================
if ($status === 'approved') {
    // temp password = lastname (trim + no spaces)
    $tempPassPlain = preg_replace('/\s+/', '', trim($user['last_name'] ?? ''));
    if ($tempPassPlain === '') $tempPassPlain = 'SouthMeridian123'; // fallback (rare)

    $tempPassHash = password_hash($tempPassPlain, PASSWORD_DEFAULT);

    // Also clear any reset token fields (optional cleanup)
    $stmt = $conn->prepare("UPDATE homeowners SET status=?, password=?, reset_token=NULL, reset_expires=NULL WHERE id=?");
    $stmt->bind_param("ssi", $status, $tempPassHash, $id);
    $ok = $stmt->execute();
    $stmt->close();
} else {
    $stmt = $conn->prepare("UPDATE homeowners SET status=? WHERE id=?");
    $stmt->bind_param("si", $status, $id);
    $ok = $stmt->execute();
    $stmt->close();
}

if (!$ok) {
    echo json_encode(['success'=>false,'message'=>'Database update failed']);
    exit;
}

// ================= EMAIL =================
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'baculpopatrick2440@gmail.com';
    $mail->Password   = 'vxsx lmtv livx hgtl'; // Gmail App Password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('baculpopatrick2440@gmail.com', 'South Meridian HOA');
    $mail->addAddress($user['email']);
    $mail->isHTML(true);

    if ($status === 'approved') {
        $first = htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8');
        $last  = htmlspecialchars($user['last_name'], ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8');

        // IMPORTANT: This is the plain temp password sent to the user
        $tempPassPlain = preg_replace('/\s+/', '', trim($user['last_name'] ?? ''));
        if ($tempPassPlain === '') $tempPassPlain = 'SouthMeridian123';

        $mail->Subject = 'Your HOA Account Has Been Approved';
        $mail->Body = "
            <h3>Hello {$first} {$last},</h3>
            <p>Your homeowner registration has been <strong>approved</strong>.</p>

            <p>Here are your login credentials:</p>
            <ul>
              <li><strong>Email:</strong> {$email}</li>
              <li><strong>Password:</strong> {$tempPassPlain}</li>
            </ul>

            <p><strong>Important:</strong> Please change your password after logging in.</p>
            <br>
            <p>— South Meridian HOA</p>
        ";
    } else {
        $safeReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
        $first = htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8');
        $last  = htmlspecialchars($user['last_name'], ENT_QUOTES, 'UTF-8');

        $mail->Subject = 'HOA Registration Rejected';
        $mail->Body = "
            <h3>Hello {$first} {$last},</h3>
            <p>Your homeowner registration has been <strong>rejected</strong>.</p>
            <p><strong>Reason:</strong> {$safeReason}</p>
            <p>Please contact the HOA office if you wish to clarify.</p>
            <br>
            <p>— South Meridian HOA</p>
        ";
    }

    $mail->send();

} catch (Exception $e) {
    // Don't fail the approval just because email failed
    echo json_encode([
        'success'=>true,
        'message'=>"Homeowner {$status}, but email failed"
    ]);
    exit;
}

echo json_encode(['success'=>true,'message'=>"Homeowner successfully {$status}"]);
exit;
