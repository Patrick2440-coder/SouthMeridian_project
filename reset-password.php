<?php
$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");

$token = $_GET['token'] ?? '';

$stmt = $conn->prepare("
    SELECT id 
    FROM homeowners 
    WHERE reset_token=? AND reset_expires > NOW()
");
$stmt->bind_param("s", $token);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();

if (!$user) {
    die("Invalid or expired token.");
}
?>

<form method="POST">
    <input type="hidden" name="id" value="<?= $user['id'] ?>">
    <input type="password" name="password" required minlength="8">
    <button type="submit">Set Password</button>
</form>
