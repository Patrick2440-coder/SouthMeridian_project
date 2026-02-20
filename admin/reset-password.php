<?php
session_start();
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

$token = trim($_GET['token'] ?? '');
if ($token === '') die("Invalid or missing token.");

$stmt = $conn->prepare("SELECT id FROM homeowners WHERE reset_token=? AND reset_expires > NOW() LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$res  = $stmt->get_result();
$user = $res->fetch_assoc();
$stmt->close();

if (!$user) die("Invalid or expired token.");

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (strlen($pass) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($pass !== $pass2) {
        $error = "Passwords do not match.";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            UPDATE homeowners
            SET password=?,
                must_change_password=0,
                reset_token=NULL,
                reset_expires=NULL
            WHERE id=?
        ");
        $stmt->bind_param("si", $hash, $user['id']);
        $stmt->execute();
        $stmt->close();

        echo "Password set successfully. You may now login.";
        exit;
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Set Password</title>
</head>
<body>
  <h2>Set Your Password</h2>

  <?php if ($error): ?>
    <p style="color:red;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>

  <form method="POST">
    <label>New Password</label><br>
    <input type="password" name="password" required minlength="8"><br><br>

    <label>Confirm Password</label><br>
    <input type="password" name="password2" required minlength="8"><br><br>

    <button type="submit">Set Password</button>
  </form>
</body>
</html>
