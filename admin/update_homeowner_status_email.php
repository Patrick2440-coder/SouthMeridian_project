<?php
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ================= SECURITY CHECK =================
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','superadmin'], true)) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

// ================= INPUT =================
$id     = (int)($_POST['id'] ?? 0);
$status = (string)($_POST['status'] ?? '');
$reason = trim((string)($_POST['reason'] ?? ''));

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

if (!$user) {
    echo json_encode(['success'=>false,'message'=>'Homeowner not found']);
    exit;
}

// ================= UPDATE STATUS + RESET TOKEN (IF APPROVED) =================
$ok = false;

$resetToken  = null;
$resetExpiry = null;

if ($status === 'approved') {
    // Create reset token + expiry (1 hour)
    $resetToken  = bin2hex(random_bytes(32)); // 64 chars
    $resetExpiry = date('Y-m-d H:i:s', time() + 3600);

    // Optional: block login until they set password by forcing must_change_password=1
    // Also set a random password so nobody can guess any default
    $randomPasswordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        UPDATE homeowners
        SET status=?,
            password=?,
            must_change_password=1,
            reset_token=?,
            reset_expires=?
        WHERE id=?
    ");
    $stmt->bind_param("ssssi", $status, $randomPasswordHash, $resetToken, $resetExpiry, $id);
    $ok = $stmt->execute();
    $stmt->close();

} else {
    // rejected: clear any reset token (cleanup)
    $stmt = $conn->prepare("
        UPDATE homeowners
        SET status=?,
            reset_token=NULL,
            reset_expires=NULL
        WHERE id=?
    ");
    $stmt->bind_param("si", $status, $id);
    $ok = $stmt->execute();
    $stmt->close();
}

if (!$ok) {
    echo json_encode(['success'=>false,'message'=>'Database update failed']);
    exit;
}

// ================= BUILD RESET LINK =================

$resetPath = "/soutmeridian/admin/reset-password.php"; 
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

$resetLink = $scheme . "://" . $host . $resetPath . "?token=" . urlencode($resetToken);

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

    $first = htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8');
    $last  = htmlspecialchars($user['last_name'], ENT_QUOTES, 'UTF-8');

    if ($status === 'approved') {
        $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

        $mail->Subject = 'Your HOA Account Has Been Approved - Set Your Password';
        $mail->Body = "
            <h3>Hello {$first} {$last},</h3>
            <p>Your homeowner registration has been <strong>approved</strong>.</p>

            <p>Please set your password using the link below:</p>
            <p>
              <a href=\"{$safeLink}\" style=\"display:inline-block;padding:10px 14px;text-decoration:none;border-radius:6px;border:1px solid #333;\">
                Set My Password
              </a>
            </p>

            <p>If the button doesn’t work, copy and paste this link into your browser:</p>
            <p>{$safeLink}</p>

            <p><strong>Note:</strong> This link will expire in <strong>1 hour</strong>.</p>
            <br>
            <p>— South Meridian HOA</p>
        ";
    } else {
        $safeReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');

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
    echo json_encode([
        'success'=>true,
        'message'=>"Homeowner {$status}, but email failed"
    ]);
    exit;
}

echo json_encode(['success'=>true,'message'=>"Homeowner successfully {$status}"]);
exit;
