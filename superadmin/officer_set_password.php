<?php

session_start();

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

require_once '../config/database.php';

function osp_esc($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$message = '';
$success = false;

$rawToken =
    trim(
        (string)(
            $_GET['token']
            ?? $_POST['token']
            ?? ''
        )
    );

$account = null;

if ($rawToken !== '') {
    $tokenHash =
        hash(
            'sha256',
            $rawToken
        );

    $stmt = $conn->prepare("
        SELECT
            a.id,
            a.email,
            a.full_name,
            a.phase,
            a.position,
            a.homeowner_id,
            h.password AS homeowner_password,
            IFNULL(h.must_change_password, 1) AS homeowner_must_change_password
        FROM admins a
        LEFT JOIN homeowners h
               ON h.id = a.homeowner_id
        WHERE a.password_setup_token = ?
          AND a.account_enabled = 1
          AND a.role = 'admin'
        LIMIT 1
    ");

    $stmt->bind_param(
        's',
        $tokenHash
    );

    $stmt->execute();

    $account =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();
}

$homeownerSetupRequired =
    $account !== null &&
    (int)(
        $account['homeowner_must_change_password']
        ?? 1
    ) === 1;

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $account
) {
    $officerPassword =
        (string)(
            $_POST['officer_password']
            ?? ''
        );

    $officerPassword2 =
        (string)(
            $_POST['officer_password2']
            ?? ''
        );

    $homeownerPassword =
        (string)(
            $_POST['homeowner_password']
            ?? ''
        );

    $homeownerPassword2 =
        (string)(
            $_POST['homeowner_password2']
            ?? ''
        );

    if (
        $homeownerSetupRequired &&
        strlen($homeownerPassword) < 8
    ) {
        $message =
            'Homeowner password must be at least 8 characters.';

    } elseif (
        $homeownerSetupRequired &&
        $homeownerPassword !== $homeownerPassword2
    ) {
        $message =
            'The homeowner passwords do not match.';

    } elseif (strlen($officerPassword) < 8) {
        $message =
            'Officer password must be at least 8 characters.';

    } elseif ($officerPassword !== $officerPassword2) {
        $message =
            'The officer passwords do not match.';

    } elseif (
        $homeownerSetupRequired &&
        hash_equals(
            $homeownerPassword,
            $officerPassword
        )
    ) {
        $message =
            'Your homeowner and officer passwords must be different.';

    } elseif (
        !$homeownerSetupRequired &&
        !empty($account['homeowner_password']) &&
        password_verify(
            $officerPassword,
            (string)$account['homeowner_password']
        )
    ) {
        $message =
            'Your officer password must be different from your homeowner password.';

    } else {
        $officerHash =
            password_hash(
                $officerPassword,
                PASSWORD_DEFAULT
            );

        $conn->begin_transaction();

        try {
            if ($homeownerSetupRequired) {
                $homeownerHash =
                    password_hash(
                        $homeownerPassword,
                        PASSWORD_DEFAULT
                    );

                $homeownerId =
                    (int)(
                        $account['homeowner_id']
                        ?? 0
                    );

                if ($homeownerId <= 0) {
                    throw new RuntimeException(
                        'Linked homeowner account was not found.'
                    );
                }

                $stmt = $conn->prepare("
                    UPDATE homeowners
                    SET password = ?,
                        must_change_password = 0,
                        reset_token = NULL,
                        reset_expires = NULL
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->bind_param(
                    'si',
                    $homeownerHash,
                    $homeownerId
                );

                $stmt->execute();
                $stmt->close();
            }

            $adminId =
                (int)$account['id'];

            $stmt = $conn->prepare("
                UPDATE admins
                SET password = ?,
                    must_change_password = 0,
                    password_setup_token = NULL,
                    password_setup_expires = NULL
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'si',
                $officerHash,
                $adminId
            );

            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $success = true;

            $message =
                $homeownerSetupRequired
                    ? 'Your homeowner and officer passwords have been created successfully. You may now use the same email with either password on the South Meridian login page.'
                    : 'Your officer password has been created successfully. Your homeowner password was not changed.';

        } catch (Throwable $e) {
            $conn->rollback();

            error_log(
                'Officer/homeowner account setup failed: ' .
                $e->getMessage()
            );

            $message =
                'Your account passwords could not be saved. Please try again or contact the HOA office.';
        }
    }
}

$tokenValid =
    $account !== null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Set Up South Meridian Accounts</title>

    <link
        rel="stylesheet"
        href="vendors/styles/core.css"
    >
    <link
        rel="stylesheet"
        href="vendors/styles/style.css"
    >

    <style>
        body {
            min-height: 100vh;
            margin: 0;
            background:
                linear-gradient(
                    135deg,
                    #eef8f2 0%,
                    #f8fbff 100%
                );
            font-family:
                Inter,
                Arial,
                sans-serif;
        }

        .setup-shell {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .setup-card {
            width: 100%;
            max-width: 520px;
            background: #fff;
            border-radius: 20px;
            box-shadow:
                0 18px 55px
                rgba(15, 23, 42, .12);
            overflow: hidden;
        }

        .setup-head {
            padding: 24px 28px;
            background:
                linear-gradient(
                    135deg,
                    #076b3d 0%,
                    #0a8f50 100%
                );
            color: #fff;
        }

        .setup-head h2 {
            color: #fff;
            margin: 0 0 6px;
            font-weight: 800;
        }

        .setup-head p {
            margin: 0;
            opacity: .9;
        }

        .setup-body {
            padding: 28px;
        }

        .account-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 18px;
        }

        .account-box strong {
            color: #0f172a;
        }

        .form-control {
            border-radius: 10px;
            min-height: 46px;
        }

        .btn-success {
            border-radius: 10px;
            min-height: 46px;
            font-weight: 700;
        }

        .notice {
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 16px;
        }

        .notice-danger {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .notice-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .small-note {
            color: #64748b;
            font-size: 12px;
        }
    </style>
</head>

<body>

<div class="setup-shell">
    <div class="setup-card">

        <div class="setup-head">
            <h2><?= $homeownerSetupRequired ? 'Set Up Your Accounts' : 'Set Officer Password' ?></h2>
            <p>South Meridian Homes HOA</p>
        </div>

        <div class="setup-body">

            <?php if (!$tokenValid): ?>

                <div class="notice notice-danger">
                    This account setup link is no longer available.
                    Please ask the Superadmin to reset your officer account.
                </div>

                <a
                    href="../index.php"
                    class="btn btn-light border btn-block"
                >
                    Return to Login
                </a>

            <?php elseif ($success): ?>

                <div class="notice notice-success">
                    <?= osp_esc($message) ?>
                </div>

                <div class="account-box">
                    <div>
                        <strong>Login Email:</strong><br>
                        <?= osp_esc($account['email']) ?>
                    </div>

                    <div class="small-note mt-2">
                        Same login email for both accounts.<br>
                        Homeowner password → Homeowner Dashboard<br>
                        Officer password → Admin Dashboard
                    </div>
                </div>

                <a
                    href="../index.php"
                    class="btn btn-success btn-block"
                >
                    Go to Login
                </a>

            <?php else: ?>

                <?php if ($message !== ''): ?>
                    <div class="notice notice-danger">
                        <?= osp_esc($message) ?>
                    </div>
                <?php endif; ?>

                <div class="account-box">
                    <div class="mb-2">
                        <strong><?= osp_esc($account['full_name']) ?></strong>
                    </div>

                    <div class="small-note">
                        <?= osp_esc($account['position']) ?>
                        •
                        <?= osp_esc($account['phase']) ?>
                    </div>

                    <div class="mt-2">
                        <strong>Login Email:</strong><br>
                        <?= osp_esc($account['email']) ?>
                    </div>
                </div>

                <form method="post" autocomplete="off">
                    <input
                        type="hidden"
                        name="token"
                        value="<?= osp_esc($rawToken) ?>"
                    >

                    <?php if ($homeownerSetupRequired): ?>
                        <div class="account-box mb-3">
                            <div class="font-weight-bold text-success mb-1">Homeowner Account</div>
                            <div class="small-note">
                                Create the password you will use for the Homeowner Dashboard.
                            </div>
                        </div>

                        <div class="form-group">
                            <label>New Homeowner Password</label>
                            <input
                                type="password"
                                class="form-control"
                                name="homeowner_password"
                                minlength="8"
                                required
                                autocomplete="new-password"
                            >
                        </div>

                        <div class="form-group">
                            <label>Confirm Homeowner Password</label>
                            <input
                                type="password"
                                class="form-control"
                                name="homeowner_password2"
                                minlength="8"
                                required
                                autocomplete="new-password"
                            >
                        </div>
                    <?php endif; ?>

                    <div class="account-box mb-3">
                        <div class="font-weight-bold text-success mb-1">Officer Account</div>
                        <div class="small-note">
                            Create a different password for your <?= osp_esc($account['position']) ?> officer access.
                        </div>
                    </div>

                    <div class="form-group">
                        <label>New Officer Password</label>
                        <input
                            type="password"
                            class="form-control"
                            name="officer_password"
                            minlength="8"
                            required
                            autocomplete="new-password"
                        >
                    </div>

                    <div class="form-group">
                        <label>Confirm Officer Password</label>
                        <input
                            type="password"
                            class="form-control"
                            name="officer_password2"
                            minlength="8"
                            required
                            autocomplete="new-password"
                        >
                    </div>

                    <div class="small-note mb-3">
                        Use at least 8 characters.
                        <?php if ($homeownerSetupRequired): ?>
                            Your homeowner and officer passwords must be different.
                        <?php else: ?>
                            Your officer password must be different from your existing homeowner password.
                        <?php endif; ?>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-success btn-block"
                    >
                        <?= $homeownerSetupRequired ? 'Create Both Passwords' : 'Create Officer Password' ?>
                    </button>
                </form>

            <?php endif; ?>

        </div>
    </div>
</div>

</body>
</html>
