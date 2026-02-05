<?php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'homeowner' || empty($_SESSION['homeowner_id'])) {
  header("Location: ../index.php");
  exit;
}

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

$hid = (int)$_SESSION['homeowner_id'];

$stmt = $conn->prepare("SELECT status, must_change_password FROM homeowners WHERE id=? LIMIT 1");
$stmt->bind_param("i", $hid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || $row['status'] !== 'approved') {
  session_destroy();
  header("Location: ../index.php");
  exit;
}

if ((int)$row['must_change_password'] === 0) {
  header("Location: homeowner_dashboard.php");
  exit;
}

$err = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $p1 = $_POST['password'] ?? '';
  $p2 = $_POST['password2'] ?? '';

  if (strlen($p1) < 8) $err = "Password must be at least 8 characters.";
  else if ($p1 !== $p2) $err = "Passwords do not match.";
  else {
    $hash = password_hash($p1, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("UPDATE homeowners SET password=?, must_change_password=0 WHERE id=?");
    $stmt->bind_param("si", $hash, $hid);
    $stmt->execute();
    $stmt->close();

    header("Location: homeowner_dashboard.php");
    exit;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Change Password</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-md-6">
        <div class="card shadow-sm">
          <div class="card-body p-4">
            <h4 class="mb-2">Change your password</h4>
            <p class="text-muted mb-4">This is required before you can continue.</p>

            <?php if ($err): ?>
              <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
            <?php endif; ?>

            <form method="POST">
              <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" name="password" class="form-control" minlength="8" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="password2" class="form-control" minlength="8" required>
              </div>
              <button class="btn btn-success w-100">Save Password</button>
            </form>

          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
