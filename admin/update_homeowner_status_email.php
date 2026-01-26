<?php
session_start();

// Force JSON content-type
header('Content-Type: application/json');

// Show errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check for autoload
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    echo json_encode(['success'=>false, 'message'=>'Autoload not found!']);
    exit;
}

require $autoload;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ================= SECURITY CHECK =================
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ================= INPUT VALIDATION =================
$id = intval($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

if ($id <= 0 || !in_array($status, ['approved','rejected'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// ================= DB CONNECTION =================
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// ================= UPDATE STATUS =================
$stmt = $conn->prepare("UPDATE homeowners SET status=? WHERE id=?");
$stmt->bind_param("si", $status, $id);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Update failed: ' . $stmt->error]);
    exit;
}

// ================= SEND EMAIL IF APPROVED =================
if ($status === 'approved') {
    // Fetch homeowner info
    $stmt = $conn->prepare("SELECT first_name, last_name, email FROM homeowners WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $stmt = $conn->prepare("UPDATE homeowners SET reset_token=?, reset_expires=? WHERE id=?");
        $stmt->bind_param("ssi", $token, $expires, $id);
        $stmt->execute();
        $stmt->close();

        // Send email via PHPMailer
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

            $resetLink = "http://localhost/southmeridian/reset-password.php?token=$token";

            $mail->isHTML(true);
            $mail->Subject = 'Your HOA Account Has Been Approved';
            $mail->Body = "
                <h3>Hello {$user['first_name']} {$user['last_name']},</h3>
                <p>Your homeowner account has been <strong>approved</strong>.</p>
                <p><strong>Username:</strong> {$user['email']}</p>
                <p>Please set your password by clicking the link below (expires in 1 hour):</p>
                <p><a href='$resetLink'>Set My Password</a></p>
                <br>
                <p>— South Meridian HOA</p>
            ";

            $mail->send();
        } catch (Exception $e) {
            // Email failure shouldn't block the status update
            echo json_encode(['success'=>true, 'message'=>"Homeowner {$status}, but email failed: ".$mail->ErrorInfo]);
            exit;
        }
    }
}

// ================= SUCCESS =================
echo json_encode(['success' => true, 'message' => "Homeowner successfully {$status}"]);
exit;
