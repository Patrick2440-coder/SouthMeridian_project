<?php
require_once __DIR__ . "/finance_helpers.php";
require_once 'admin_access.php';

requireAccess('finance');
require_admin();

$conn = db_conn();

date_default_timezone_set('Asia/Manila');

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);

/* =========================
   CURRENT DUES SETTING
   ========================= */
$stmt = $conn->prepare("
  SELECT monthly_dues
  FROM finance_dues_settings
  WHERE phase = ?
  LIMIT 1
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$monthly_dues = (float)(
  $stmt->get_result()->fetch_assoc()['monthly_dues']
  ?? 0
);
$stmt->close();

/* =========================
   OPENING BALANCE
   ========================= */
$stmt = $conn->prepare("
  SELECT opening_balance, as_of
  FROM finance_opening_balance
  WHERE phase = ?
  LIMIT 1
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$openingRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$hasOpeningBalance = !empty($openingRow);

$opening = (float)(
  $openingRow['opening_balance']
  ?? 0
);

$openingAsOf = trim(
  (string)(
    $openingRow['as_of']
    ?? ''
  )
);

/*
|--------------------------------------------------------------------------
| Balance basis
|--------------------------------------------------------------------------
| If an opening balance exists, it is treated as the HOA balance at the end
| of its "as_of" date. Only transactions AFTER that date are applied.
|
| This prevents dues/donations/expenses that were already included in the
| opening balance from being counted a second time.
*/
if ($hasOpeningBalance && $openingAsOf !== '') {
  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_paid
    FROM finance_payments
    WHERE phase = ?
      AND status = 'paid'
      AND DATE(COALESCE(paid_at, created_at)) > ?
  ");
  $stmt->bind_param(
    "ss",
    $phase,
    $openingAsOf
  );
} else {
  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_paid
    FROM finance_payments
    WHERE phase = ?
      AND status = 'paid'
  ");
  $stmt->bind_param("s", $phase);
}

$stmt->execute();
$total_paid = (float)(
  $stmt->get_result()->fetch_assoc()['total_paid']
  ?? 0
);
$stmt->close();

if ($hasOpeningBalance && $openingAsOf !== '') {
  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_don
    FROM finance_donations
    WHERE phase = ?
      AND donation_date > ?
  ");
  $stmt->bind_param(
    "ss",
    $phase,
    $openingAsOf
  );
} else {
  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_don
    FROM finance_donations
    WHERE phase = ?
  ");
  $stmt->bind_param("s", $phase);
}

$stmt->execute();
$total_don = (float)(
  $stmt->get_result()->fetch_assoc()['total_don']
  ?? 0
);
$stmt->close();

if ($hasOpeningBalance && $openingAsOf !== '') {
  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_exp
    FROM finance_expenses
    WHERE phase = ?
      AND expense_date > ?
  ");
  $stmt->bind_param(
    "ss",
    $phase,
    $openingAsOf
  );
} else {
  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_exp
    FROM finance_expenses
    WHERE phase = ?
  ");
  $stmt->bind_param("s", $phase);
}

$stmt->execute();
$total_exp = (float)(
  $stmt->get_result()->fetch_assoc()['total_exp']
  ?? 0
);
$stmt->close();

$current_balance =
  $opening +
  $total_paid +
  $total_don -
  $total_exp;

/* =========================
   CURRENT MONTH COLLECTIONS
   ========================= */
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

/*
|--------------------------------------------------------------------------
| Eligible homeowners for this month's dues
|--------------------------------------------------------------------------
| A homeowner is due this month only when:
| - same phase
| - approved
| - account already existed by the end of the current month
|
| This matches the homeowner-side dues start rule based on created_at month.
*/
$stmt = $conn->prepare("
  SELECT COUNT(*) AS eligible_count
  FROM homeowners
  WHERE phase = ?
    AND status = 'approved'
    AND DATE(created_at) <= ?
");
$stmt->bind_param(
  "ss",
  $phase,
  $monthEnd
);
$stmt->execute();
$eligibleHomeownerCount = (int)(
  $stmt->get_result()->fetch_assoc()['eligible_count']
  ?? 0
);
$stmt->close();

/*
|--------------------------------------------------------------------------
| Paid homeowners for the current due period
|--------------------------------------------------------------------------
| Count the due period (pay_year/pay_month), not the date the payment row was
| inserted. This keeps collection status tied to the month being paid.
*/
$stmt = $conn->prepare("
  SELECT
    COUNT(DISTINCT p.homeowner_id) AS paid_count,
    COALESCE(SUM(p.amount), 0) AS paid_amount
  FROM finance_payments p
  JOIN homeowners h
    ON h.id = p.homeowner_id
   AND h.phase = p.phase
  WHERE p.phase = ?
    AND p.pay_year = ?
    AND p.pay_month = ?
    AND p.status = 'paid'
    AND h.status = 'approved'
    AND DATE(h.created_at) <= ?
");
$stmt->bind_param(
  "siis",
  $phase,
  $currentYear,
  $currentMonth,
  $monthEnd
);
$stmt->execute();
$currentPaymentRow =
  $stmt
    ->get_result()
    ->fetch_assoc();
$stmt->close();

$paidHomeownerCount = (int)(
  $currentPaymentRow['paid_count']
  ?? 0
);

$paidThisMonth = (float)(
  $currentPaymentRow['paid_amount']
  ?? 0
);

$unpaidHomeownerCount = max(
  0,
  $eligibleHomeownerCount -
  $paidHomeownerCount
);

$expectedThisMonth =
  $eligibleHomeownerCount *
  $monthly_dues;

/*
|--------------------------------------------------------------------------
| Pending collection estimate
|--------------------------------------------------------------------------
| Use the number of homeowners without a PAID row for this due period.
| This is more reliable than simply subtracting arbitrary historical payment
| amounts from expected collections.
*/
$pendingCollections =
  $unpaidHomeownerCount *
  $monthly_dues;

/* =========================
   CURRENT MONTH CASH FLOW
   ========================= */
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount), 0) AS total
  FROM finance_donations
  WHERE phase = ?
    AND donation_date BETWEEN ? AND ?
");
$stmt->bind_param(
  "sss",
  $phase,
  $monthStart,
  $monthEnd
);
$stmt->execute();
$monthDonations = (float)(
  $stmt->get_result()->fetch_assoc()['total']
  ?? 0
);
$stmt->close();

$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount), 0) AS total
  FROM finance_expenses
  WHERE phase = ?
    AND expense_date BETWEEN ? AND ?
");
$stmt->bind_param(
  "sss",
  $phase,
  $monthStart,
  $monthEnd
);
$stmt->execute();
$monthExpenses = (float)(
  $stmt->get_result()->fetch_assoc()['total']
  ?? 0
);
$stmt->close();

$monthIncome =
  $paidThisMonth +
  $monthDonations;

$monthNet =
  $monthIncome -
  $monthExpenses;

/* =========================
   LOW-FUND INDICATOR
   ========================= */
$lowFundThreshold = 5000.00;
$lowFund =
  $current_balance <
  $lowFundThreshold;

$balanceBasisLabel =
  ($hasOpeningBalance && $openingAsOf !== '')
    ? 'Opening balance as of ' .
      date(
        'M d, Y',
        strtotime($openingAsOf)
      )
    : 'No opening balance configured';
?>
<!DOCTYPE html>
<html>
<head>
  <!-- Basic Page Info -->
  <meta charset="utf-8">
  <title>HOA-ADMIN</title>

  <!-- Site favicon -->
<?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>

  <!-- Mobile Specific Metas -->
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

  <!-- Google Font -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <!-- CSS -->
  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- Global site tag (gtag.js) - Google Analytics -->
   <!-- Include CSS for DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

  <script async src="https://www.googletagmanager.com/gtag/js?id=UA-119386393-1"></script>
<style>
    /* ACCESS TOAST */
.access-toast {
  position: fixed;
  top: 20px;
  right: 20px;
  background: #ef4444;
  color: #fff;
  padding: 12px 18px;
  border-radius: 8px;
  font-weight: 600;
  box-shadow: 0 6px 18px rgba(0,0,0,0.2);
  z-index: 99999;
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transform: translateY(-10px);
  transition:
    opacity .3s ease,
    transform .3s ease,
    visibility .3s ease;
}

.access-toast.show {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
  transform: translateY(0);
}
</style>

<!-- SHARED ADMIN LIGHT / DARK THEME -->
<link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">

<style>
  /* =========================================================
     FINANCE CASH FLOW - DARK MODE EXTENSIONS
     ========================================================= */

  html.dark .page-header,
  html.dark .card-box,
  html.dark .footer-wrap {
    background: var(--admin-surface) !important;
    color: var(--admin-text) !important;
    border-color: var(--admin-border) !important;
  }

  html.dark .page-header .title h4,
  html.dark .card-box h3,
  html.dark .card-box h5,
  html.dark .card-box h6,
  html.dark .card-box b,
  html.dark .footer-wrap {
    color: var(--admin-text) !important;
  }

  html.dark .text-secondary,
  html.dark .text-muted,
  html.dark small.text-secondary {
    color: var(--admin-muted) !important;
  }

  html.dark hr {
    border-top-color: var(--admin-border) !important;
  }

  /* Phase selector */
  html.dark .form-control,
  html.dark select.form-control {
    background: var(--admin-input) !important;
    color: var(--admin-text) !important;
    border-color: var(--admin-border) !important;
  }

  html.dark .form-control:focus,
  html.dark select.form-control:focus {
    background: var(--admin-input) !important;
    color: var(--admin-text) !important;
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 .2rem rgba(59,130,246,.16) !important;
  }

  /* Low-funds alert */
  html.dark .alert-danger {
    background: rgba(220,38,38,.12) !important;
    color: #fecaca !important;
    border-color: rgba(239,68,68,.28) !important;
  }

  /* Cash-flow summary tiles */
  html.dark .card-box .border.rounded {
    background: var(--admin-surface-2) !important;
    border-color: var(--admin-border) !important;
  }

  html.dark .card-box .border.rounded .h5,
  html.dark .card-box .border.rounded b {
    color: var(--admin-text) !important;
  }

  /* Keep positive/negative finance colors readable */
  html.dark .text-success {
    color: #86efac !important;
  }

  html.dark .text-danger {
    color: #fca5a5 !important;
  }

  /* Optional visual polish for the three main KPI cards */
  html.dark .row > [class*="col-"] > .card-box.h-100 {
    box-shadow: none !important;
  }

  /* Direct header logout */
  .admin-page-logout-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-width: 98px;
    min-height: 40px;
    margin: 0 18px 0 10px;
    padding: 8px 14px;
    border: 1px solid #fecaca;
    border-radius: 11px;
    background: #fff;
    color: #b91c1c !important;
    box-shadow: 0 4px 14px rgba(15, 23, 42, .06);
    font-size: 12px;
    line-height: 1;
    font-weight: 800;
    text-decoration: none !important;
    white-space: nowrap;
    transition: background .18s ease, color .18s ease, border-color .18s ease,
                transform .18s ease, box-shadow .18s ease;
  }
  .admin-page-logout-btn i {
    font-size: 17px;
    line-height: 1;
  }
  .admin-page-logout-btn:hover,
  .admin-page-logout-btn:focus {
    border-color: #ef4444;
    background: #fef2f2;
    color: #991b1b !important;
    box-shadow: 0 7px 18px rgba(220, 38, 38, .12);
    transform: translateY(-1px);
    outline: none;
  }
  html.dark .admin-page-logout-btn {
    border-color: rgba(248, 113, 113, .30);
    background: rgba(127, 29, 29, .16);
    color: #fca5a5 !important;
    box-shadow: none;
  }
  html.dark .admin-page-logout-btn:hover,
  html.dark .admin-page-logout-btn:focus {
    border-color: rgba(248, 113, 113, .55);
    background: rgba(127, 29, 29, .28);
    color: #fecaca !important;
  }
  @media (max-width: 575.98px) {
    .admin-page-logout-btn {
      width: 40px;
      min-width: 40px;
      height: 40px;
      min-height: 40px;
      margin: 0 10px 0 6px;
      padding: 0;
      border-radius: 10px;
    }
    .admin-page-logout-btn span {
      display: none;
    }
    .admin-page-logout-btn i {
      font-size: 18px;
    }
  }
</style>

<!-- Apply saved theme before body paint -->
<script>
(function () {
  try {
    const savedTheme = localStorage.getItem('hoa-theme');

    const dark =
      savedTheme === 'dark' ||
      (
        !savedTheme &&
        window.matchMedia &&
        window.matchMedia('(prefers-color-scheme: dark)').matches
      );

    document.documentElement.classList.toggle('dark', dark);
  } catch (e) {}
})();
</script>
</head>
<body>

  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
      <div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>

    </div>
    <div class="header-right">

      <!-- SHARED ADMIN DARK MODE TOGGLE -->
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


      <!-- DIRECT LOGOUT BUTTON -->
      <a href="logout.php"
         class="admin-page-logout-btn"
         title="Log out"
         aria-label="Log out">
        <i class="dw dw-logout" aria-hidden="true"></i>
        <span>Log Out</span>
      </a>

    </div>
  </div>

  <div class="right-sidebar">
    <div class="sidebar-title">
      <h3 class="weight-600 font-16 text-blue">
        Layout Settings
        <span class="btn-block font-weight-400 font-12">User Interface Settings</span>
      </h3>
      <div class="close-sidebar" data-toggle="right-sidebar-close">
        <i class="icon-copy ion-close-round"></i>
      </div>
    </div>
    <div class="right-sidebar-body customscroll">
      <div class="right-sidebar-body-content">
        <h4 class="weight-600 font-18 pb-10">Header Background</h4>
        <div class="sidebar-btn-group pb-30 mb-10">
          <a href="javascript:void(0);" class="btn btn-outline-primary header-white active">White</a>
          <a href="javascript:void(0);" class="btn btn-outline-primary header-dark">Dark</a>
        </div>

        <h4 class="weight-600 font-18 pb-10">Sidebar Background</h4>
        <div class="sidebar-btn-group pb-30 mb-10">
          <a href="javascript:void(0);" class="btn btn-outline-primary sidebar-light ">White</a>
          <a href="javascript:void(0);" class="btn btn-outline-primary sidebar-dark active">Dark</a>
        </div>

        <h4 class="weight-600 font-18 pb-10">Menu Dropdown Icon</h4>
        <div class="sidebar-radio-group pb-10 mb-10">
          <div class="custom-control custom-radio custom-control-inline">
            <input type="radio" id="sidebaricon-1" name="menu-dropdown-icon" class="custom-control-input" value="icon-style-1" checked="">
            <label class="custom-control-label" for="sidebaricon-1"><i class="fa fa-angle-down"></i></label>
          </div>
          <div class="custom-control custom-radio custom-control-inline">
            <input type="radio" id="sidebaricon-2" name="menu-dropdown-icon" class="custom-control-input" value="icon-style-2">
            <label class="custom-control-label" for="sidebaricon-2"><i class="ion-plus-round"></i></label>
          </div>
          <div class="custom-control custom-radio custom-control-inline">
            <input type="radio" id="sidebaricon-3" name="menu-dropdown-icon" class="custom-control-input" value="icon-style-3">
            <label class="custom-control-label" for="sidebaricon-3"><i class="fa fa-angle-double-right"></i></label>
          </div>
        </div>

        <h4 class="weight-600 font-18 pb-10">Menu List Icon</h4>
        <div class="sidebar-radio-group pb-30 mb-10">
          <div class="custom-control custom-radio custom-control-inline">
            <input type="radio" id="sidebariconlist-1" name="menu-list-icon" class="custom-control-input" value="icon-list-style-1" checked="">
            <label class="custom-control-label" for="sidebariconlist-1"><i class="ion-minus-round"></i></label>
          </div>
          <div class="custom-control custom-radio custom-control-inline">
            <input type="radio" id="sidebariconlist-2" name="menu-list-icon" class="custom-control-input" value="icon-list-style-2">
            <label class="custom-control-label" for="sidebariconlist-2"><i class="fa fa-circle-o" aria-hidden="true"></i></label>
          </div>
          <div class="custom-control custom-radio custom-control-inline">
            <input type="radio" id="sidebariconlist-3" name="menu-list-icon" class="custom-control-input" value="icon-list-style-3">
            <label class="custom-control-label" for="sidebariconlist-3"><i class="dw dw-check"></i></label>
          </div>
          <div class="custom-control custom-radio custom-control-inline">
            <input type="radio" id="sidebariconlist-4" name="menu-list-icon" class="custom-control-input" value="icon-list-style-4" checked="">
            <label class="custom-control-label" for="sidebariconlist-4"><i class="icon-copy dw dw-next-2"></i></label>
          </div>
          <div class="custom-control custom-radio custom-control-inline">
            <input type="radio" id="sidebariconlist-5" name="menu-list-icon" class="custom-control-input" value="icon-list-style-5">
            <label class="custom-control-label" for="sidebariconlist-5"><i class="dw dw-fast-forward-1"></i></label>
          </div>
          <div class="custom-control custom-radio custom-control-inline">
            <input type="radio" id="sidebariconlist-6" name="menu-list-icon" class="custom-control-input" value="icon-list-style-6">
            <label class="custom-control-label" for="sidebariconlist-6"><i class="dw dw-next"></i></label>
          </div>
        </div>

        <div class="reset-options pt-30 text-center">
          <button class="btn btn-danger" id="reset-settings">Reset Settings</button>
        </div>
      </div>
    </div>
  </div>

  <!-- SIDEBAR -->
   <?php include 'sidebar.php'; ?>
<div class="main-container">
  <div class="pd-ltr-20">

    <div class="page-header mb-20">
      <div class="row">
        <div class="col-md-6 col-sm-12">
          <div class="title"><h4>Cash Flow Dashboard</h4></div>
          <div class="text-secondary">Phase: <b><?=esc($phase)?></b></div>
        </div>
        <div class="col-md-6 col-sm-12 text-right">
          <?php if ($canPickPhase): ?>
            <form method="get" class="d-inline-block">
              <select name="phase" class="form-control d-inline-block" style="width:200px" onchange="this.form.submit()">
                <?php foreach(['Phase 1','Phase 2','Phase 3'] as $p): ?>
                  <option value="<?=esc($p)?>" <?= $p===$phase?'selected':'' ?>><?=esc($p)?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($lowFund): ?>
      <div class="alert alert-danger">
        ⚠ Low funds alert! Current balance is below
        ₱ <?= number_format($lowFundThreshold, 2) ?>.
      </div>
    <?php endif; ?>

    <div class="card-box mb-20 p-3">
      <div class="d-flex flex-wrap justify-content-between align-items-center">
        <div>
          <h5 class="mb-1">Cash Flow Basis</h5>
          <div class="text-secondary">
            <?= esc($balanceBasisLabel) ?>
          </div>

          <?php if ($hasOpeningBalance): ?>
            <small class="text-secondary">
              Opening:
              <b>₱ <?= number_format($opening, 2) ?></b>.
              Only transactions after the opening-balance date are applied to Current Balance.
            </small>
          <?php else: ?>
            <small class="text-secondary">
              Current Balance uses all recorded paid dues, donations, and expenses.
            </small>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col-md-4 mb-20">
        <div class="card-box p-3 h-100">
          <h6>Current Balance</h6>

          <h3>
            ₱ <?= number_format($current_balance, 2) ?>
          </h3>

          <small class="text-secondary">
            Opening
            + dues
            + donations
            − expenses
          </small>
        </div>
      </div>

      <div class="col-md-4 mb-20">
        <div class="card-box p-3 h-100">
          <h6>Pending Collections (This Month)</h6>

          <h3>
            ₱ <?= number_format($pendingCollections, 2) ?>
          </h3>

          <small class="text-secondary d-block">
            Eligible homeowners:
            <b><?= (int)$eligibleHomeownerCount ?></b>
          </small>

          <small class="text-secondary d-block">
            Paid:
            <b><?= (int)$paidHomeownerCount ?></b>
            |
            Unpaid:
            <b><?= (int)$unpaidHomeownerCount ?></b>
          </small>

          <small class="text-secondary d-block">
            Expected:
            ₱ <?= number_format($expectedThisMonth, 2) ?>
          </small>
        </div>
      </div>

      <div class="col-md-4 mb-20">
        <div class="card-box p-3 h-100">
          <h6>This Month Net</h6>

          <h3 class="<?= $monthNet >= 0 ? 'text-success' : 'text-danger' ?>">
            ₱ <?= number_format($monthNet, 2) ?>
          </h3>

          <small class="text-secondary d-block">
            Income:
            ₱ <?= number_format($monthIncome, 2) ?>
          </small>

          <small class="text-secondary d-block">
            Expenses:
            ₱ <?= number_format($monthExpenses, 2) ?>
          </small>
        </div>
      </div>
    </div>

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">
        <?= esc(date('F Y')) ?> Cash Flow
      </h5>

      <div class="row">
        <div class="col-md-3 mb-2">
          <div class="border rounded p-3 h-100">
            <div class="text-secondary">
              Dues Paid
            </div>

            <div class="h5 mb-0">
              ₱ <?= number_format($paidThisMonth, 2) ?>
            </div>
          </div>
        </div>

        <div class="col-md-3 mb-2">
          <div class="border rounded p-3 h-100">
            <div class="text-secondary">
              Donations
            </div>

            <div class="h5 mb-0">
              ₱ <?= number_format($monthDonations, 2) ?>
            </div>
          </div>
        </div>

        <div class="col-md-3 mb-2">
          <div class="border rounded p-3 h-100">
            <div class="text-secondary">
              Total Income
            </div>

            <div class="h5 mb-0 text-success">
              ₱ <?= number_format($monthIncome, 2) ?>
            </div>
          </div>
        </div>

        <div class="col-md-3 mb-2">
          <div class="border rounded p-3 h-100">
            <div class="text-secondary">
              Expenses
            </div>

            <div class="h5 mb-0 text-danger">
              ₱ <?= number_format($monthExpenses, 2) ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">Balance Components</h5>

      <div class="row">
        <div class="col-md-4 mb-2">
          <div class="border rounded p-3 h-100">
            <div class="text-secondary">
              Dues Applied to Balance
            </div>

            <div class="h5 mb-0">
              ₱ <?= number_format($total_paid, 2) ?>
            </div>
          </div>
        </div>

        <div class="col-md-4 mb-2">
          <div class="border rounded p-3 h-100">
            <div class="text-secondary">
              Donations Applied to Balance
            </div>

            <div class="h5 mb-0">
              ₱ <?= number_format($total_don, 2) ?>
            </div>
          </div>
        </div>

        <div class="col-md-4 mb-2">
          <div class="border rounded p-3 h-100">
            <div class="text-secondary">
              Expenses Applied to Balance
            </div>

            <div class="h5 mb-0">
              ₱ <?= number_format($total_exp, 2) ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="footer-wrap pd-20 mb-20 card-box">
      © Copyright South Meridian Homes All Rights Reserved
    </div>

  </div>
</div>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>

<!-- SHARED ADMIN DARK MODE -->
<script src="vendors/scripts/admin_theme.js"></script>
<div id="accessToast" class="access-toast">
  🚫 You do not have access to that part.
</div>
<script>
window.userPermissions = <?= json_encode($permissions) ?>;

document.addEventListener('DOMContentLoaded', function () {

  const toast = document.getElementById('accessToast');

  function showAccessToast() {
    toast.classList.add('show');

    setTimeout(() => {
      toast.classList.remove('show');
    }, 2500);
  }

  document.querySelectorAll('.menu-access-link').forEach(function(link){

    link.addEventListener('click', function(e){

      const moduleKey = this.dataset.module || '';
      const allowed = !!window.userPermissions[moduleKey];

      if(!allowed){
        e.preventDefault();
        showAccessToast();
      }

    });

  });

});
</script>
</body>
</html>
