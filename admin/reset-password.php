<?php
session_start();

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ================= TOKEN =================
$tokenRaw = (string)($_GET['token'] ?? '');
$tokenRaw = trim($tokenRaw);

// keep only hex characters (email clients sometimes add punctuation)
$token = strtolower(preg_replace('/[^a-f0-9]/', '', $tokenRaw));

if ($token === '' || strlen($token) !== 64) {
  $pageError = "Invalid or missing token.";
} else {
  // ================= LOOKUP TOKEN =================
  $stmt = $conn->prepare("
    SELECT id, reset_expires
    FROM homeowners
    WHERE reset_token = ?
    LIMIT 1
  ");
  $stmt->bind_param("s", $token);
  $stmt->execute();
  $res  = $stmt->get_result();
  $user = $res->fetch_assoc();
  $stmt->close();

  if (!$user) {
    $pageError = "Invalid or expired token.";
  } else {
    // ================= EXPIRY CHECK (PHP-side) =================
    $expires = $user['reset_expires'] ?? null;
    if (!$expires || strtotime((string)$expires) <= time()) {
      $pageError = "Invalid or expired token.";
    }
  }
}

$successMsg = '';
$errorMsg   = '';

if (empty($pageError) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $pass  = (string)($_POST['password'] ?? '');
  $pass2 = (string)($_POST['password2'] ?? '');

  if (strlen($pass) < 8) {
    $errorMsg = "Password must be at least 8 characters.";
  } elseif ($pass !== $pass2) {
    $errorMsg = "Passwords do not match.";
  } else {
    $hash = password_hash($pass, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
      UPDATE homeowners
      SET password=?,
          must_change_password=0,
          reset_token=NULL,
          reset_expires=NULL
      WHERE id=?
      LIMIT 1
    ");
    $stmt->bind_param("si", $hash, $user['id']);
    $stmt->execute();
    $stmt->close();

    $successMsg = "Password set successfully. You may now login.";
  }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Set Password • South Meridian HOA</title>

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Match your dashboard theme -->
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

  <style>
    body { background:#f4f6f8; }
    .auth-wrap {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .auth-card {
      width: min(560px, 96vw);
      border-radius: 16px;
      overflow: hidden;
    }
    .auth-head {
      background: #077f46;
      color: #fff;
      padding: 18px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .auth-title {
      margin: 0;
      font-weight: 900;
      font-size: 18px;
      letter-spacing: .2px;
    }
    .auth-sub {
      margin: 2px 0 0;
      opacity: .9;
      font-size: 12px;
      font-weight: 600;
    }
    .auth-body { padding: 20px; }
    .form-group label { font-weight: 800; }
    .btn-green {
      background:#077f46;
      border-color:#077f46;
      color:#fff;
      font-weight: 800;
      border-radius: 10px;
      padding: 10px 14px;
    }
    .btn-green:hover { filter: brightness(.95); color:#fff; }
    .mini {
      font-size: 12px;
      color: #64748b;
      font-weight: 600;
    }
    .divider {
      height: 1px;
      background: #e5e7eb;
      margin: 16px 0;
    }
  </style>
</head>

<body>

<div class="auth-wrap">
  <div class="card-box auth-card">
    <div class="auth-head">
      <div>
        <h1 class="auth-title">Set Your Password</h1>
        <div class="auth-sub">South Meridian HOA • Homeowner Account</div>
      </div>
      <div style="font-size:22px; opacity:.95;">
        <i class="dw dw-lock"></i>
      </div>
    </div>

    <div class="auth-body">

      <?php if (!empty($pageError)): ?>
        <div class="alert alert-danger" role="alert" style="border-radius:12px;">
          <b>Oops!</b> <?= esc($pageError) ?>
          <div class="mt-2 mini">
            Tip: If you clicked an old email, request approval again or ask admin to resend the link.
          </div>
        </div>

        <div class="divider"></div>
        <a href="../index.php" class="btn btn-outline-secondary btn-block" style="border-radius:10px;font-weight:800;">
          Back to Login
        </a>

      <?php elseif ($successMsg): ?>
        <div class="alert alert-success" role="alert" style="border-radius:12px;">
          <b>Success!</b> <?= esc($successMsg) ?>
        </div>

        <div class="divider"></div>
        <a href="../index.php" class="btn btn-green btn-block">
          Go to Login
        </a>

      <?php else: ?>
        <?php if ($errorMsg): ?>
          <div class="alert alert-danger" role="alert" style="border-radius:12px;">
            <?= esc($errorMsg) ?>
          </div>
        <?php endif; ?>

        <div class="mini mb-2">
          Create a strong password (min. 8 characters). This link expires automatically.
        </div>

        <form method="POST" autocomplete="off">
          <div class="form-group">
            <label>New Password</label>
            <div class="input-group">
              <input type="password" class="form-control" name="password" required minlength="8" placeholder="Enter new password">
              <div class="input-group-append">
                <span class="input-group-text"><i class="dw dw-padlock"></i></span>
              </div>
            </div>
          </div>

          <div class="form-group">
            <label>Confirm Password</label>
            <div class="input-group">
              <input type="password" class="form-control" name="password2" required minlength="8" placeholder="Confirm new password">
              <div class="input-group-append">
                <span class="input-group-text"><i class="dw dw-padlock1"></i></span>
              </div>
            </div>
          </div>

          <button type="submit" class="btn btn-green btn-block">
            Set Password
          </button>

          <div class="text-center mt-3 mini">
            Having trouble? Contact your HOA admin for a new link.
          </div>
        </form>
      <?php endif; ?>

    </div>
  </div>
</div>

</body>
</html>