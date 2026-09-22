<?php
session_start();

require_once 'config/database.php';
require_once 'login_security_helper.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function appeal_esc($v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES,
        'UTF-8'
    );
}

$token =
    trim(
        (string)($_GET['token'] ?? $_POST['token'] ?? '')
    );

$appeal = null;
$error = '';
$success = '';

if ($token === '') {
    $error =
        'This appeal link is invalid.';
} else {
    $tokenHash =
        hash(
            'sha256',
            $token
        );

    $stmt = $conn->prepare("
        SELECT
            a.id AS appeal_id,
            a.status AS appeal_status,
            a.appeal_message,
            a.requested_at,
            a.reviewed_at,
            a.admin_remarks,

            s.id AS security_state_id,
            s.account_type,
            s.account_id,
            s.email,
            s.phase,
            s.hard_locked,
            s.hard_locked_at

        FROM login_security_appeals a
        JOIN login_security_state s
          ON s.id = a.security_state_id

        WHERE a.token_hash = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        's',
        $tokenHash
    );

    $stmt->execute();

    $appeal =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if (!$appeal) {
        $error =
            'This appeal link is invalid or no longer available.';
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $appeal &&
    $error === ''
) {
    if (
        (string)$appeal['appeal_status'] !== 'available'
    ) {
        $error =
            'This appeal has already been submitted or reviewed.';
    } elseif (
        (int)$appeal['hard_locked'] !== 1
    ) {
        $error =
            'This account is no longer locked.';
    } else {
        $message =
            trim(
                (string)($_POST['appeal_message'] ?? '')
            );

        if (mb_strlen($message) > 1200) {
            $error =
                'Appeal message must not exceed 1200 characters.';
        } else {
            $stmt = $conn->prepare("
                UPDATE login_security_appeals
                SET
                    status = 'pending',
                    appeal_message = ?,
                    requested_at = NOW()
                WHERE id = ?
                  AND status = 'available'
            ");

            $appealId =
                (int)$appeal['appeal_id'];

            $stmt->bind_param(
                'si',
                $message,
                $appealId
            );

            $stmt->execute();

            $changed =
                $stmt->affected_rows === 1;

            $stmt->close();

            if ($changed) {
                $success =
                    'Your unlock appeal was submitted. An administrator must review it before your account can be unlocked.';

                /*
                |--------------------------------------------------------------------------
                | Notify phase admin(s) + superadmin that an appeal is waiting.
                |--------------------------------------------------------------------------
                */
                $phase =
                    trim(
                        (string)($appeal['phase'] ?? '')
                    );

                if ($phase !== '') {
                    $stmt = $conn->prepare("
                        SELECT
                            email,
                            full_name
                        FROM admins
                        WHERE
                            role = 'superadmin'
                            OR phase = ?
                    ");

                    $stmt->bind_param(
                        's',
                        $phase
                    );
                } else {
                    $stmt = $conn->prepare("
                        SELECT
                            email,
                            full_name
                        FROM admins
                        WHERE role = 'superadmin'
                    ");
                }

                $stmt->execute();

                $adminResult =
                    $stmt->get_result();

                $adminUrl =
                    security_base_url() .
                    '/admin/login_security.php';

                $sentTo = [];

                while (
                    $adminRow =
                    $adminResult->fetch_assoc()
                ) {
                    $adminEmail =
                        strtolower(
                            trim(
                                (string)($adminRow['email'] ?? '')
                            )
                        );

                    if (
                        !filter_var(
                            $adminEmail,
                            FILTER_VALIDATE_EMAIL
                        ) ||
                        isset($sentTo[$adminEmail])
                    ) {
                        continue;
                    }

                    $sentTo[$adminEmail] =
                        true;

                    $safeUserEmail =
                        htmlspecialchars(
                            (string)$appeal['email'],
                            ENT_QUOTES,
                            'UTF-8'
                        );

                    $safePhase =
                        htmlspecialchars(
                            $phase !== ''
                                ? $phase
                                : 'N/A',
                            ENT_QUOTES,
                            'UTF-8'
                        );

                    $safeAdminUrl =
                        htmlspecialchars(
                            $adminUrl,
                            ENT_QUOTES,
                            'UTF-8'
                        );

                    $body = '
                    <div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#1f2937;">
                      <h2>Login Unlock Appeal</h2>
                      <p>A locked South Meridian account submitted an appeal.</p>
                      <p><strong>Email:</strong> ' . $safeUserEmail . '</p>
                      <p><strong>Phase:</strong> ' . $safePhase . '</p>
                      <p>
                        <a href="' . $safeAdminUrl . '"
                           style="display:inline-block;background:#077f46;color:#fff;text-decoration:none;padding:11px 18px;border-radius:8px;font-weight:700;">
                          Review Login Security
                        </a>
                      </p>
                    </div>';

                    security_send_email(
                        $adminEmail,
                        (string)($adminRow['full_name'] ?? 'Administrator'),
                        'Login unlock appeal waiting for review',
                        $body,
                        "A login unlock appeal is waiting for review: " .
                        $adminUrl
                    );
                }

                $stmt->close();

                /*
                 * Reload appeal status after submission.
                 */
                $appeal['appeal_status'] =
                    'pending';

                $appeal['appeal_message'] =
                    $message;

            } else {
                $error =
                    'Unable to submit this appeal. Please refresh and try again.';
            }
        }
    }
}

$status =
    (string)($appeal['appeal_status'] ?? '');

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Account Unlock Appeal • South Meridian Homes</title>

  <link href="assets/img/sm_logo.png" rel="icon">
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">

  <script>
  (function () {
    try {
      const saved = localStorage.getItem('hoa-theme');
      const dark =
        saved === 'dark' ||
        (!saved &&
         window.matchMedia &&
         window.matchMedia('(prefers-color-scheme: dark)').matches);

      document.documentElement.classList.toggle('dark', dark);
    } catch (e) {}
  })();
  </script>

  <style>
    :root{
      --bg:#f4f8f5;
      --card:#fff;
      --text:#173d2b;
      --muted:#66766d;
      --line:#dfe9e3;
      --green:#077f46;
      color-scheme:light;
    }
    html.dark{
      --bg:#0b1210;
      --card:#111a16;
      --text:#e8f4ed;
      --muted:#9caea4;
      --line:#2b4035;
      --green:#2fc27b;
      color-scheme:dark;
    }
    body{
      min-height:100vh;
      margin:0;
      display:grid;
      place-items:center;
      padding:24px;
      background:var(--bg);
      color:var(--text);
      font-family:Arial,sans-serif;
    }
    .appeal-card{
      width:min(100%,620px);
      background:var(--card);
      border:1px solid var(--line);
      border-radius:22px;
      overflow:hidden;
      box-shadow:0 24px 60px rgba(15,23,42,.10);
    }
    .appeal-head{
      padding:24px;
      background:#077f46;
      color:#fff;
    }
    .appeal-body{padding:26px;}
    .appeal-logo{
      width:54px;
      height:54px;
      object-fit:contain;
      background:#fff;
      border-radius:14px;
      padding:5px;
    }
    .form-control{
      background:var(--card);
      color:var(--text);
      border-color:var(--line);
      border-radius:12px;
    }
    html.dark .form-control:focus{
      background:#0c1410;
      color:#fff;
      border-color:#2fc27b;
      box-shadow:0 0 0 .2rem rgba(47,194,123,.12);
    }
    .meta{
      padding:14px;
      border:1px solid var(--line);
      border-radius:14px;
      background:rgba(127,127,127,.04);
    }
  </style>
</head>
<body>

<div class="appeal-card">
  <div class="appeal-head d-flex align-items-center gap-3">
    <img src="assets/img/sm_logo.png" class="appeal-logo" alt="SMH">
    <div>
      <h4 class="mb-1 text-white">Account Unlock Appeal</h4>
      <div class="small opacity-75">South Meridian Homes</div>
    </div>
  </div>

  <div class="appeal-body">

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger">
        <?= appeal_esc($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
      <div class="alert alert-success">
        <?= appeal_esc($success) ?>
      </div>
    <?php endif; ?>

    <?php if ($appeal): ?>
      <div class="meta mb-4">
        <div><strong>Account:</strong> <?= appeal_esc($appeal['email']) ?></div>
        <div class="mt-1"><strong>Type:</strong> <?= appeal_esc(ucfirst((string)$appeal['account_type'])) ?></div>
        <div class="mt-1"><strong>Phase:</strong> <?= appeal_esc($appeal['phase'] ?: 'N/A') ?></div>
        <div class="mt-1">
          <strong>Status:</strong>
          <?= appeal_esc(ucfirst($status ?: 'available')) ?>
        </div>
      </div>

      <?php if ($status === 'available' && (int)$appeal['hard_locked'] === 1): ?>
        <p class="text-muted">
          Explain briefly that you are requesting the account to be unlocked.
          An administrator will review the appeal manually.
        </p>

        <form method="POST">
          <input type="hidden" name="token" value="<?= appeal_esc($token) ?>">

          <label class="form-label fw-bold" for="appeal_message">
            Appeal message
          </label>

          <textarea
            class="form-control"
            id="appeal_message"
            name="appeal_message"
            rows="5"
            maxlength="1200"
            placeholder="Example: I accidentally entered an old password several times. Please review and unlock my account."
          ></textarea>

          <button class="btn btn-success w-100 mt-3 py-2 fw-bold" type="submit">
            <i class="bi bi-send-check me-1"></i>
            Submit Appeal
          </button>
        </form>

      <?php elseif ($status === 'pending'): ?>
        <div class="alert alert-warning mb-0">
          <strong>Appeal pending.</strong>
          Your account remains locked until an administrator approves the unlock.
        </div>

      <?php elseif ($status === 'approved' || (int)$appeal['hard_locked'] === 0): ?>
        <div class="alert alert-success">
          Your account has been unlocked.
        </div>

        <a href="index.php" class="btn btn-success w-100">
          Return to Login
        </a>

      <?php elseif ($status === 'rejected'): ?>
        <div class="alert alert-danger mb-0">
          This appeal was not approved.
          <?= !empty($appeal['admin_remarks'])
                ? ' Admin remarks: ' . appeal_esc($appeal['admin_remarks'])
                : '' ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>

    <div class="text-center mt-4">
      <a href="index.php" class="text-success text-decoration-none fw-semibold">
        <i class="bi bi-arrow-left"></i>
        Back to South Meridian Homes
      </a>
    </div>

  </div>
</div>

</body>
</html>
