<?php
session_start();

require_once 'admin_access.php';
require_once '../config/database.php';
require_once '../login_security_helper.php';

requireAccess('user_management');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function ls_esc($v): string
{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES,
        'UTF-8'
    );
}

$adminId =
    (int)($_SESSION['admin_id'] ?? 0);

$stmt = $conn->prepare("
    SELECT
        id,
        full_name,
        email,
        phase,
        role,
        position
    FROM admins
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param(
    'i',
    $adminId
);

$stmt->execute();

$admin =
    $stmt
        ->get_result()
        ->fetch_assoc();

$stmt->close();

if (!$admin) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$adminName =
    trim(
        (string)($admin['full_name'] ?? '')
    ) ?: (string)$admin['email'];

$adminRole =
    (string)($admin['role'] ?? '');

$adminPhase =
    trim(
        (string)($admin['phase'] ?? '')
    );

$isSuperadmin =
    $adminRole === 'superadmin' ||
    $adminPhase === 'Superadmin';

if (empty($_SESSION['csrf_login_security'])) {
    $_SESSION['csrf_login_security'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrf =
    (string)$_SESSION['csrf_login_security'];

$flash = $_SESSION['login_security_flash'] ?? null;
unset($_SESSION['login_security_flash']);

function ls_can_manage(
    array $state,
    bool $isSuperadmin,
    string $adminPhase
): bool {
    if ($isSuperadmin) {
        return true;
    }

    if (
        !in_array(
            (string)$state['account_type'],
            ['homeowner', 'tenant'],
            true
        )
    ) {
        return false;
    }

    return
        (string)$state['phase'] ===
        $adminPhase;
}

function ls_display_name(array $row): string
{
    $name =
        trim(
            (string)($row['display_name'] ?? '')
        );

    return
        $name !== ''
            ? $name
            : (string)$row['email'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf =
        (string)($_POST['csrf'] ?? '');

    if (
        $postedCsrf === '' ||
        !hash_equals(
            $csrf,
            $postedCsrf
        )
    ) {
        $_SESSION['login_security_flash'] = [
            'type' => 'danger',
            'message' => 'Invalid security token. Please try again.'
        ];

        header('Location: login_security.php');
        exit;
    }

    $action =
        trim(
            (string)($_POST['action'] ?? '')
        );

    $stateId =
        (int)($_POST['state_id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT *
        FROM login_security_state
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'i',
        $stateId
    );

    $stmt->execute();

    $state =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    if (
        !$state ||
        !ls_can_manage(
            $state,
            $isSuperadmin,
            $adminPhase
        )
    ) {
        $_SESSION['login_security_flash'] = [
            'type' => 'danger',
            'message' => 'You are not allowed to manage this locked account.'
        ];

        header('Location: login_security.php');
        exit;
    }

    if ($action === 'unlock') {
        $remarks =
            trim(
                (string)($_POST['admin_remarks'] ?? '')
            );

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                UPDATE login_security_state
                SET
                    failed_attempts = 0,
                    cooldown_stage = 0,
                    cooldown_until = NULL,
                    hard_locked = 0,
                    hard_locked_at = NULL,
                    unlocked_at = NOW(),
                    unlocked_by_admin_id = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                'ii',
                $adminId,
                $stateId
            );

            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("
                UPDATE login_security_appeals
                SET
                    status = 'approved',
                    reviewed_at = NOW(),
                    reviewed_by_admin_id = ?,
                    admin_remarks = ?
                WHERE security_state_id = ?
                  AND status IN ('available','pending')
            ");

            $stmt->bind_param(
                'isi',
                $adminId,
                $remarks,
                $stateId
            );

            $stmt->execute();
            $stmt->close();

            $conn->commit();

            /*
             * Resolve display name for email.
             */
            $displayName =
                (string)$state['email'];

            if ($state['account_type'] === 'homeowner') {
                $stmt = $conn->prepare("
                    SELECT
                        CONCAT(first_name,' ',last_name) AS n
                    FROM homeowners
                    WHERE id = ?
                    LIMIT 1
                ");
            } elseif ($state['account_type'] === 'tenant') {
                $stmt = $conn->prepare("
                    SELECT
                        CONCAT(first_name,' ',last_name) AS n
                    FROM tenants
                    WHERE id = ?
                    LIMIT 1
                ");
            } else {
                $stmt = $conn->prepare("
                    SELECT full_name AS n
                    FROM admins
                    WHERE id = ?
                    LIMIT 1
                ");
            }

            $accountId =
                (int)$state['account_id'];

            $stmt->bind_param(
                'i',
                $accountId
            );

            $stmt->execute();

            $nameRow =
                $stmt
                    ->get_result()
                    ->fetch_assoc();

            $stmt->close();

            if (!empty($nameRow['n'])) {
                $displayName =
                    trim(
                        (string)$nameRow['n']
                    );
            }

            $mailSent =
                security_send_unlock_email(
                    (string)$state['email'],
                    $displayName
                );

            $_SESSION['login_security_flash'] = [
                'type' => 'success',
                'message' =>
                    'Account unlocked successfully.' .
                    (
                        $mailSent
                            ? ' The user was notified by email.'
                            : ' The account was unlocked, but the email notification could not be sent.'
                    )
            ];

        } catch (\Throwable $e) {
            $conn->rollback();

            error_log(
                'Login security unlock error: ' .
                $e->getMessage()
            );

            $_SESSION['login_security_flash'] = [
                'type' => 'danger',
                'message' => 'Unable to unlock the account.'
            ];
        }

        header('Location: login_security.php');
        exit;
    }

    if ($action === 'reject_appeal') {
        $remarks =
            trim(
                (string)($_POST['admin_remarks'] ?? '')
            );

        if ($remarks === '') {
            $_SESSION['login_security_flash'] = [
                'type' => 'warning',
                'message' => 'Enter a reason before rejecting an appeal.'
            ];

            header('Location: login_security.php');
            exit;
        }

        $stmt = $conn->prepare("
            UPDATE login_security_appeals
            SET
                status = 'rejected',
                reviewed_at = NOW(),
                reviewed_by_admin_id = ?,
                admin_remarks = ?
            WHERE security_state_id = ?
              AND status = 'pending'
        ");

        $stmt->bind_param(
            'isi',
            $adminId,
            $remarks,
            $stateId
        );

        $stmt->execute();

        $changed =
            $stmt->affected_rows > 0;

        $stmt->close();

        $_SESSION['login_security_flash'] = [
            'type' =>
                $changed
                    ? 'success'
                    : 'warning',
            'message' =>
                $changed
                    ? 'Appeal rejected. The account remains locked.'
                    : 'No pending appeal was found.'
        ];

        header('Location: login_security.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Locked accounts
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        s.*,

        COALESCE(
            NULLIF(
                TRIM(
                    CONCAT(
                        h.first_name,
                        ' ',
                        h.last_name
                    )
                ),
                ''
            ),
            NULLIF(
                TRIM(
                    CONCAT(
                        t.first_name,
                        ' ',
                        t.last_name
                    )
                ),
                ''
            ),
            NULLIF(
                TRIM(ad.full_name),
                ''
            ),
            s.email
        ) AS display_name,

        ap.id AS appeal_id,
        ap.status AS appeal_status,
        ap.appeal_message,
        ap.requested_at,
        ap.admin_remarks AS appeal_admin_remarks

    FROM login_security_state s

    LEFT JOIN homeowners h
      ON s.account_type = 'homeowner'
     AND h.id = s.account_id

    LEFT JOIN tenants t
      ON s.account_type = 'tenant'
     AND t.id = s.account_id

    LEFT JOIN admins ad
      ON s.account_type = 'admin'
     AND ad.id = s.account_id

    LEFT JOIN login_security_appeals ap
      ON ap.id = (
        SELECT MAX(ap2.id)
        FROM login_security_appeals ap2
        WHERE ap2.security_state_id = s.id
      )

    WHERE s.hard_locked = 1
";

if (!$isSuperadmin) {
    $sql .= "
      AND s.account_type IN ('homeowner','tenant')
      AND s.phase = ?
    ";
}

$sql .= "
    ORDER BY
      CASE
        WHEN ap.status = 'pending' THEN 0
        ELSE 1
      END,
      s.hard_locked_at DESC,
      s.id DESC
";

$stmt = $conn->prepare($sql);

if (!$isSuperadmin) {
    $stmt->bind_param(
        's',
        $adminPhase
    );
}

$stmt->execute();

$lockedAccounts =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();

$pendingCount = 0;

foreach ($lockedAccounts as $row) {
    if (
        (string)($row['appeal_status'] ?? '') ===
        'pending'
    ) {
        $pendingCount++;
    }
}

$pageTitle =
    'Login Security • South Meridian Homes';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title><?= ls_esc($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">

  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">

  <script>
  (function () {
    try {
      const savedTheme =
        localStorage.getItem('hoa-theme');

      const dark =
        savedTheme === 'dark' ||
        (
          !savedTheme &&
          window.matchMedia &&
          window.matchMedia(
            '(prefers-color-scheme: dark)'
          ).matches
        );

      document.documentElement
        .classList
        .toggle(
          'dark',
          dark
        );
    } catch (e) {}
  })();
  </script>

  <style>
    .security-stat{
      border:1px solid #e5e7eb;
      border-radius:14px;
      padding:16px;
      height:100%;
      background:#fff;
    }
    .security-stat .num{
      font-size:28px;
      font-weight:800;
      line-height:1;
      margin-bottom:7px;
    }
    .appeal-box{
      max-width:390px;
      white-space:normal;
      line-height:1.45;
    }
    .account-type{
      text-transform:capitalize;
    }
    html.dark .security-stat,
    html.dark .card-box,
    html.dark .page-header,
    html.dark .footer-wrap{
      background:var(--admin-surface)!important;
      color:var(--admin-text)!important;
      border-color:var(--admin-border)!important;
    }
    html.dark .security-stat{
      border-color:var(--admin-border)!important;
    }
    html.dark .table{
      color:var(--admin-text)!important;
      background:var(--admin-surface)!important;
    }
    html.dark .table th{
      color:#f8fafc!important;
      background:var(--admin-surface-2)!important;
      border-color:var(--admin-border)!important;
    }
    html.dark .table td{
      color:var(--admin-text)!important;
      border-color:var(--admin-border)!important;
    }
    html.dark .text-muted,
    html.dark .text-secondary{
      color:var(--admin-muted)!important;
    }
    html.dark .modal-content{
      background:var(--admin-surface)!important;
      color:var(--admin-text)!important;
      border-color:var(--admin-border)!important;
    }
    html.dark .modal-header,
    html.dark .modal-footer{
      border-color:var(--admin-border)!important;
    }
    html.dark .form-control{
      background:var(--admin-input)!important;
      color:var(--admin-text)!important;
      border-color:var(--admin-border)!important;
    }
  </style>
</head>

<body>

<div class="header">
  <div class="header-left">
    <div class="menu-icon dw dw-menu"></div>
  </div>

  <div class="header-right">

    <div class="admin-theme-switch">
      <button
        type="button"
        id="themeToggle"
        class="admin-theme-toggle"
        aria-label="Switch theme"
        title="Switch theme"
      >
        <span id="themeIcon">☾</span>
      </button>
    </div>

    <div class="user-info-dropdown">
      <div class="dropdown">
        <a
          class="dropdown-toggle"
          href="#"
          role="button"
          data-toggle="dropdown"
        >
          <span class="user-name">
            <?= ls_esc($adminName) ?>
          </span>
        </a>
      </div>
    </div>

  </div>
</div>

<?php include 'sidebar.php'; ?>

<div class="mobile-menu-overlay"></div>

<div class="main-container">
  <div class="pd-ltr-20">

    <div class="page-header mb-20">
      <div class="row align-items-center">
        <div class="col-md-8">
          <div class="title">
            <h4>Login Security</h4>
          </div>
          <p class="mb-0 text-secondary">
            Review accounts locked after repeated incorrect password attempts and process unlock appeals.
          </p>
        </div>

        <div class="col-md-4 text-md-right mt-3 mt-md-0">
          <span class="badge badge-danger p-2">
            <?= (int)$pendingCount ?> Pending Appeal<?= $pendingCount === 1 ? '' : 's' ?>
          </span>
        </div>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= ls_esc($flash['type'] ?? 'info') ?>">
        <?= ls_esc($flash['message'] ?? '') ?>
      </div>
    <?php endif; ?>

    <div class="row mb-20">
      <div class="col-md-4 mb-3">
        <div class="security-stat">
          <div class="num">
            <?= count($lockedAccounts) ?>
          </div>
          <div class="text-secondary">
            Currently Locked
          </div>
        </div>
      </div>

      <div class="col-md-4 mb-3">
        <div class="security-stat">
          <div class="num text-warning">
            <?= (int)$pendingCount ?>
          </div>
          <div class="text-secondary">
            Appeals Waiting
          </div>
        </div>
      </div>

      <div class="col-md-4 mb-3">
        <div class="security-stat">
          <div class="num text-success">
            3 + 3
          </div>
          <div class="text-secondary">
            Lock Rule
          </div>
          <div class="small text-muted mt-1">
            3 fails → 10s cooldown → 3 more fails → admin unlock
          </div>
        </div>
      </div>
    </div>

    <div class="card-box mb-20">
      <div class="pd-20">
        <h5 class="mb-1">Locked Accounts</h5>
        <div class="text-secondary small">
          <?= $isSuperadmin
                ? 'Superadmin view: all phases and account types.'
                : 'Phase scope: ' . ls_esc($adminPhase) . '. Admin accounts require Superadmin review.' ?>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0">
          <thead>
            <tr>
              <th>Account</th>
              <th>Type</th>
              <th>Phase</th>
              <th>Locked At</th>
              <th>Appeal</th>
              <th>Last Attempt</th>
              <th style="min-width:170px;">Action</th>
            </tr>
          </thead>

          <tbody>
          <?php if (!$lockedAccounts): ?>
            <tr>
              <td colspan="7" class="text-center text-muted py-4">
                No locked accounts.
              </td>
            </tr>
          <?php else: ?>

            <?php foreach ($lockedAccounts as $row): ?>
              <?php
                $appealStatus =
                    (string)($row['appeal_status'] ?? '');

                $badge =
                    $appealStatus === 'pending'
                        ? 'warning'
                        : (
                            $appealStatus === 'approved'
                                ? 'success'
                                : (
                                    $appealStatus === 'rejected'
                                        ? 'danger'
                                        : 'secondary'
                                )
                          );
              ?>

              <tr>
                <td>
                  <strong>
                    <?= ls_esc(ls_display_name($row)) ?>
                  </strong>
                  <div class="small text-muted">
                    <?= ls_esc($row['email']) ?>
                  </div>
                </td>

                <td>
                  <span class="account-type badge badge-light border">
                    <?= ls_esc($row['account_type']) ?>
                  </span>
                </td>

                <td>
                  <?= ls_esc($row['phase'] ?: 'N/A') ?>
                </td>

                <td>
                  <?= ls_esc($row['hard_locked_at'] ?: '-') ?>
                </td>

                <td>
                  <span class="badge badge-<?= $badge ?>">
                    <?= ls_esc($appealStatus !== '' ? ucfirst($appealStatus) : 'No appeal') ?>
                  </span>

                  <?php if (!empty($row['appeal_message'])): ?>
                    <div class="appeal-box small mt-2">
                      <?= nl2br(ls_esc($row['appeal_message'])) ?>
                    </div>
                  <?php endif; ?>

                  <?php if (!empty($row['requested_at'])): ?>
                    <div class="small text-muted mt-1">
                      Submitted: <?= ls_esc($row['requested_at']) ?>
                    </div>
                  <?php endif; ?>
                </td>

                <td>
                  <div class="small">
                    <?= ls_esc($row['last_failed_at'] ?: '-') ?>
                  </div>
                  <?php if (!empty($row['last_failed_ip'])): ?>
                    <div class="small text-muted">
                      IP: <?= ls_esc($row['last_failed_ip']) ?>
                    </div>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if (ls_can_manage($row, $isSuperadmin, $adminPhase)): ?>
                    <button
                      type="button"
                      class="btn btn-sm btn-success btn-unlock"
                      data-id="<?= (int)$row['id'] ?>"
                      data-name="<?= ls_esc(ls_display_name($row)) ?>"
                    >
                      <i class="dw dw-unlock"></i>
                      Unlock
                    </button>

                    <?php if ($appealStatus === 'pending'): ?>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-danger mt-1 btn-reject"
                        data-id="<?= (int)$row['id'] ?>"
                        data-name="<?= ls_esc(ls_display_name($row)) ?>"
                      >
                        Reject Appeal
                      </button>
                    <?php endif; ?>

                  <?php else: ?>
                    <span class="small text-muted">
                      Superadmin required
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>

          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="footer-wrap pd-20 mb-20 card-box">
      © Copyright South Meridian Homes All Rights Reserved
    </div>

  </div>
</div>

<!-- Unlock modal -->
<div class="modal fade" id="unlockModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="POST">
      <div class="modal-header">
        <h5 class="modal-title">Unlock Account</h5>
        <button type="button" class="close" data-dismiss="modal">
          <span>&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= ls_esc($csrf) ?>">
        <input type="hidden" name="action" value="unlock">
        <input type="hidden" name="state_id" id="unlockStateId">

        <p>
          Unlock <strong id="unlockAccountName"></strong>?
        </p>

        <label class="font-weight-bold">Admin remarks</label>
        <textarea
          class="form-control"
          name="admin_remarks"
          rows="3"
          maxlength="1000"
          placeholder="Optional review note"
        ></textarea>

        <div class="alert alert-info mt-3 mb-0">
          Unlocking resets the failed-attempt cycle. The user may sign in immediately.
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">
          Cancel
        </button>
        <button type="submit" class="btn btn-success">
          Confirm Unlock
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Reject appeal modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="POST">
      <div class="modal-header">
        <h5 class="modal-title">Reject Unlock Appeal</h5>
        <button type="button" class="close" data-dismiss="modal">
          <span>&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= ls_esc($csrf) ?>">
        <input type="hidden" name="action" value="reject_appeal">
        <input type="hidden" name="state_id" id="rejectStateId">

        <p>
          Keep <strong id="rejectAccountName"></strong> locked?
        </p>

        <label class="font-weight-bold">Reason</label>
        <textarea
          class="form-control"
          name="admin_remarks"
          rows="3"
          maxlength="1000"
          required
        ></textarea>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">
          Cancel
        </button>
        <button type="submit" class="btn btn-danger">
          Reject Appeal
        </button>
      </div>
    </form>
  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
<script src="vendors/scripts/admin_theme.js"></script>

<script>
document.addEventListener(
  'DOMContentLoaded',
  function () {
    document
      .querySelectorAll('.btn-unlock')
      .forEach(
        function (button) {
          button.addEventListener(
            'click',
            function () {
              document.getElementById('unlockStateId').value =
                this.dataset.id || '';

              document.getElementById('unlockAccountName').textContent =
                this.dataset.name || 'this account';

              $('#unlockModal').modal('show');
            }
          );
        }
      );

    document
      .querySelectorAll('.btn-reject')
      .forEach(
        function (button) {
          button.addEventListener(
            'click',
            function () {
              document.getElementById('rejectStateId').value =
                this.dataset.id || '';

              document.getElementById('rejectAccountName').textContent =
                this.dataset.name || 'this account';

              $('#rejectModal').modal('show');
            }
          );
        }
      );
  }
);
</script>

</body>
</html>
