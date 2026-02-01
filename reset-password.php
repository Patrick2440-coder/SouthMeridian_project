<?php
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("DB error.");

$token = $_GET['token'] ?? '';
$token = trim($token);

if ($token === '') die("Missing token.");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $pass = $_POST['password'] ?? '';

    if ($id <= 0) die("Invalid request.");
    if (strlen($pass) < 8) die("Password must be at least 8 characters.");

    $hash = password_hash($pass, PASSWORD_DEFAULT);

    $upd = $conn->prepare("
        UPDATE homeowners
        SET password_hash=?, reset_token=NULL, reset_expires=NULL
        WHERE id=?
    ");
    $upd->bind_param("si", $hash, $id);
    $upd->execute();

    echo "Password updated. You can now login.";
    exit;
}

$stmt = $conn->prepare("
    SELECT id
    FROM homeowners
    WHERE reset_token=? AND reset_expires > NOW()
    LIMIT 1
");
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();

if (!$user) die("Invalid or expired token.");
?>

<form method="POST">
  <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
  <input type="password" name="password" required minlength="8" placeholder="New password">
  <button type="submit">Set Password</button>
</form>
