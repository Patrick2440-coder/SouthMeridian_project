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

if ($id <= 0 || !in_array($status, ['approved','rejected'])) {
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

// ================= UPDATE STATUS =================
$stmt = $conn->prepare("UPDATE homeowners SET status=? WHERE id=?");
$stmt->bind_param("si", $status, $id);
$stmt->execute();
$stmt->close();

// ================= FETCH HOMEOWNER =================
$stmt = $conn->prepare("SELECT first_name, last_name, email FROM homeowners WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success'=>true,'message'=>"Homeowner {$status} (email skipped)"]);
    exit;
}

// ================= EMAIL =================
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'baculpopatrick2440@gmail.com';
    $mail->Password   = 'vxsx lmtv livx hgtl';
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom('baculpopatrick2440@gmail.com', 'South Meridian HOA');
    $mail->addAddress($user['email']);
    $mail->isHTML(true);

    if ($status === 'approved') {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $stmt = $conn->prepare("UPDATE homeowners SET reset_token=?, reset_expires=? WHERE id=?");
        $stmt->bind_param("ssi", $token, $expires, $id);
        $stmt->execute();
        $stmt->close();

        $resetLink = "http://localhost/southmeridian/reset-password.php?token=$token";

        $mail->Subject = 'Your HOA Account Has Been Approved';
        $mail->Body = "
            <h3>Hello {$user['first_name']} {$user['last_name']},</h3>
            <p>Your homeowner registration has been <strong>approved</strong>.</p>
            <p><a href='$resetLink'>Set My Password</a></p>
            <p>This link expires in 1 hour.</p>
            <br>
            <p>— South Meridian HOA</p>
        ";
    } else {
        $safeReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');

        $mail->Subject = 'HOA Registration Rejected';
        $mail->Body = "
            <h3>Hello {$user['first_name']} {$user['last_name']},</h3>
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
