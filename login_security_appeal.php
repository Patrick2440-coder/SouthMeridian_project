<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';

mysqli_report(
    MYSQLI_REPORT_ERROR
    | MYSQLI_REPORT_STRICT
);

function appeal_esc($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function appeal_mask_email(string $email): string
{
    if (!str_contains($email, '@')) {
        return $email;
    }

    [$local, $domain] = explode('@', $email, 2);

    if (mb_strlen($local) <= 2) {
        $maskedLocal =
            mb_substr($local, 0, 1) .
            '*';
    } else {
        $maskedLocal =
            mb_substr($local, 0, 1) .
            str_repeat(
                '*',
                max(2, mb_strlen($local) - 2)
            ) .
            mb_substr($local, -1);
    }

    return $maskedLocal . '@' . $domain;
}

if (empty($_SESSION['csrf_login_appeal'])) {
    $_SESSION['csrf_login_appeal'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrf =
    (string)$_SESSION['csrf_login_appeal'];

$token =
    trim(
        (string)(
            $_GET['token']
            ?? $_POST['token']
            ?? ''
        )
    );

$error = '';
$success = '';

if (
    $token === ''
    || !preg_match('/^[a-f0-9]{64}$/i', $token)
) {
    $error =
        'This appeal link is invalid.';
}

$appeal = null;

if ($error === '') {
    $tokenHash =
        hash(
            'sha256',
            $token
        );

    $stmt = $conn->prepare("
        SELECT
            ap.id,
            ap.security_state_id,
            ap.status,
            ap.appeal_message,
            ap.created_at,
            ap.requested_at,
            ap.reviewed_at,
            ap.admin_remarks,
            s.account_type,
            s.account_id,
            s.email,
            s.phase,
            s.hard_locked
        FROM login_security_appeals ap
        INNER JOIN login_security_state s
            ON s.id = ap.security_state_id
        WHERE ap.token_hash = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        's',
        $tokenHash
    );

    $stmt->execute();

    $appeal = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if (!$appeal) {
        $error =
            'This appeal link is invalid or no longer available.';
    }
}

if (
    $error === ''
    && $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    $postedCsrf =
        (string)($_POST['csrf'] ?? '');

    if (
        $postedCsrf === ''
        || !hash_equals(
            $csrf,
            $postedCsrf
        )
    ) {
        $error =
            'The security token expired. Reload the page and try again.';
    } elseif (
        (int)$appeal['hard_locked'] !== 1
    ) {
        $error =
            'This account is no longer locked.';
    } elseif (
        (string)$appeal['status'] === 'approved'
    ) {
        $error =
            'This appeal has already been approved.';
    } elseif (
        (string)$appeal['status'] === 'rejected'
    ) {
        $error =
            'This appeal was rejected. Please contact the HOA office if you still need assistance.';
    } elseif (
        (string)$appeal['status'] === 'pending'
    ) {
        $success =
            'Your appeal is already waiting for administrator review.';
    } else {
        $message =
            trim(
                (string)($_POST['appeal_message'] ?? '')
            );

        if (mb_strlen($message) < 10) {
            $error =
                'Please briefly explain why you need the account unlocked.';
        } elseif (mb_strlen($message) > 1000) {
            $error =
                'The appeal message must not exceed 1,000 characters.';
        } else {
            $stmt = $conn->prepare("
                UPDATE login_security_appeals
                SET
                    status = 'pending',
                    appeal_message = ?,
                    requested_at = NOW()
                WHERE id = ?
                  AND status = 'available'
                LIMIT 1
            ");

            $appealId =
                (int)$appeal['id'];

            $stmt->bind_param(
                'si',
                $message,
                $appealId
            );

            $stmt->execute();

            $changed =
                $stmt->affected_rows > 0;

            $stmt->close();

            if ($changed) {
                $appeal['status'] = 'pending';
                $appeal['appeal_message'] = $message;
                $appeal['requested_at'] =
                    date('Y-m-d H:i:s');

                $success =
                    'Your unlock appeal was submitted. An authorized administrator will review it.';
            } else {
                $success =
                    'Your appeal has already been submitted.';
            }
        }
    }
}

$status =
    strtolower(
        (string)($appeal['status'] ?? '')
    );

$email =
    (string)($appeal['email'] ?? '');

$phase =
    trim(
        (string)($appeal['phase'] ?? '')
    );
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>Unlock Appeal | South Meridian Homes</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #f1f5f9;
            color: #0f172a;
            font-family: Arial, sans-serif;
        }

        .appeal-card {
            width: 100%;
            max-width: 560px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            background: #ffffff;
            box-shadow: 0 20px 50px rgba(15, 23, 42, .10);
        }

        .appeal-header {
            padding: 24px 26px;
            background: #0f5132;
            color: #ffffff;
        }

        .appeal-header h1 {
            margin: 0 0 6px;
            font-size: 24px;
        }

        .appeal-header p {
            margin: 0;
            opacity: .9;
            font-size: 14px;
            line-height: 1.6;
        }

        .appeal-body {
            padding: 26px;
        }

        .notice {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 14px;
            line-height: 1.5;
        }

        .notice-error {
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #991b1b;
        }

        .notice-success {
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #166534;
        }

        .account-box {
            margin-bottom: 20px;
            padding: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
        }

        .account-box div {
            margin: 5px 0;
            font-size: 14px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 700;
        }

        textarea {
            width: 100%;
            min-height: 130px;
            resize: vertical;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            color: #0f172a;
            font: inherit;
            outline: none;
        }

        textarea:focus {
            border-color: #16a34a;
            box-shadow: 0 0 0 3px rgba(22, 163, 74, .12);
        }

        .help {
            margin: 7px 0 16px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 44px;
            padding: 10px 16px;
            border: 0;
            border-radius: 10px;
            background: #15803d;
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
        }

        .btn:hover {
            background: #166534;
        }

        .btn-secondary {
            margin-top: 10px;
            background: #e2e8f0;
            color: #334155;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .status {
            display: inline-block;
            margin-bottom: 16px;
            padding: 5px 9px;
            border-radius: 999px;
            background: #e2e8f0;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
        }
    </style>
</head>
<body>
    <main class="appeal-card">
        <div class="appeal-header">
            <h1>Unlock Appeal</h1>
            <p>
                South Meridian Homes HOA Account Security
            </p>
        </div>

        <div class="appeal-body">
            <?php if ($error !== ''): ?>
                <div class="notice notice-error">
                    <?= appeal_esc($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="notice notice-success">
                    <?= appeal_esc($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($appeal): ?>
                <span class="status">
                    <?= appeal_esc($status ?: 'available') ?>
                </span>

                <div class="account-box">
                    <div>
                        <strong>Account:</strong>
                        <?= appeal_esc(appeal_mask_email($email)) ?>
                    </div>

                    <div>
                        <strong>Type:</strong>
                        <?= appeal_esc(ucfirst((string)$appeal['account_type'])) ?>
                    </div>

                    <div>
                        <strong>Phase:</strong>
                        <?= appeal_esc($phase !== '' ? $phase : 'N/A') ?>
                    </div>
                </div>

                <?php if (
                    $status === 'available'
                    && (int)$appeal['hard_locked'] === 1
                ): ?>
                    <form method="POST">
                        <input
                            type="hidden"
                            name="csrf"
                            value="<?= appeal_esc($csrf) ?>"
                        >

                        <input
                            type="hidden"
                            name="token"
                            value="<?= appeal_esc($token) ?>"
                        >

                        <label for="appealMessage">
                            Why should your account be unlocked?
                        </label>

                        <textarea
                            id="appealMessage"
                            name="appeal_message"
                            maxlength="1000"
                            required
                            placeholder="Briefly explain what happened and request account access restoration."
                        ><?= appeal_esc((string)($_POST['appeal_message'] ?? '')) ?></textarea>

                        <div class="help">
                            Your appeal will be sent to an authorized HOA administrator for review.
                        </div>

                        <button
                            type="submit"
                            class="btn"
                        >
                            Submit Unlock Appeal
                        </button>
                    </form>
                <?php elseif ($status === 'pending'): ?>
                    <div class="notice notice-success">
                        Your appeal is waiting for administrator review.
                    </div>
                <?php elseif ($status === 'approved'): ?>
                    <div class="notice notice-success">
                        Your account has been unlocked. You may sign in again.
                    </div>
                <?php elseif ($status === 'rejected'): ?>
                    <div class="notice notice-error">
                        This appeal was rejected. Please contact the HOA office if you need further assistance.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <a
                href="index.php"
                class="btn btn-secondary"
            >
                Back to Login
            </a>
        </div>
    </main>
</body>
</html>
