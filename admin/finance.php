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
| Balance logic
|--------------------------------------------------------------------------
| If an opening balance exists, it is treated as the balance "as of" that
| date. Only money movements AFTER that date are added/subtracted.
|
| If there is no opening balance record, all recorded finance transactions
| are included from the beginning.
*/
if ($hasOpeningBalance && $openingAsOf !== '') {
  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_paid
    FROM finance_payments
    WHERE phase = ?
      AND status = 'paid'
      AND DATE(COALESCE(paid_at, created_at)) > ?
  ");
  $stmt->bind_param("ss", $phase, $openingAsOf);
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
  $stmt->bind_param("ss", $phase, $openingAsOf);
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
  $stmt->bind_param("ss", $phase, $openingAsOf);
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
   CURRENT MONTH SUMMARY
   ========================= */
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount), 0) AS total
  FROM finance_payments
  WHERE phase = ?
    AND status = 'paid'
    AND DATE(COALESCE(paid_at, created_at))
      BETWEEN ? AND ?
");
$stmt->bind_param(
  "sss",
  $phase,
  $monthStart,
  $monthEnd
);
$stmt->execute();
$monthDues = (float)(
  $stmt->get_result()->fetch_assoc()['total']
  ?? 0
);
$stmt->close();
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount), 0) AS total
  FROM finance_donations
  WHERE phase = ?
    AND donation_date
      BETWEEN ? AND ?
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
    AND expense_date
      BETWEEN ? AND ?
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
  $monthDues +
  $monthDonations;
$monthNet =
  $monthIncome -
  $monthExpenses;
/* =========================
   LIFETIME REFERENCE TOTALS
   ========================= */
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount), 0) AS total
  FROM finance_payments
  WHERE phase = ?
    AND status = 'paid'
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$lifetimeDues = (float)(
  $stmt->get_result()->fetch_assoc()['total']
  ?? 0
);
$stmt->close();
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount), 0) AS total
  FROM finance_donations
  WHERE phase = ?
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$lifetimeDonations = (float)(
  $stmt->get_result()->fetch_assoc()['total']
  ?? 0
);
$stmt->close();
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount), 0) AS total
  FROM finance_expenses
  WHERE phase = ?
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$lifetimeExpenses = (float)(
  $stmt->get_result()->fetch_assoc()['total']
  ?? 0
);
$stmt->close();
$balancePeriodLabel =
  ($hasOpeningBalance && $openingAsOf !== '')
    ? 'After ' . date(
        'M d, Y',
        strtotime($openingAsOf)
      )
    : 'All recorded history';
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
<!-- ADMIN DARK MODE -->
<link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">
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
    transform: translateY(0);
}
/* =========================================================
   FINANCE OVERVIEW - SPACIOUS UI
   Page-specific only; does not affect other admin pages.
   ========================================================= */
.finance-overview-page .pd-ltr-20 {
    padding-left: 28px;
    padding-right: 28px;
}
.finance-overview-page .page-header {
    border-radius: 16px;
    padding: 24px 26px;
    margin-bottom: 28px;
}
.finance-overview-page .finance-panel {
    padding: 24px 26px !important;
    margin-bottom: 26px !important;
    border-radius: 16px;
}
.finance-overview-page .finance-balance-card {
    min-height: 112px;
    display: flex;
    align-items: center;
}
.finance-overview-page .finance-balance-card > .d-flex {
    width: 100%;
    gap: 20px;
}
.finance-overview-page .finance-balance-card h5,
.finance-overview-page .finance-month-card h5,
.finance-overview-page .finance-recorded-card h5,
.finance-overview-page .finance-modules-card h5 {
    font-size: 20px;
    line-height: 1.3;
}
.finance-overview-page .finance-stat-col {
    margin-bottom: 26px;
}
.finance-overview-page .finance-stat-card {
    min-height: 138px;
    padding: 24px 24px !important;
    border-radius: 16px;
    overflow: hidden;
}
.finance-overview-page .finance-stat-card > .d-flex {
    min-height: 90px;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
}
.finance-overview-page .finance-stat-card .widget-data {
    flex: 1 1 auto;
    min-width: 0;
}
.finance-overview-page .finance-stat-card .widget-data .font-24 {
    font-size: 28px !important;
    line-height: 1.15;
    margin-bottom: 8px;
}
.finance-overview-page .finance-stat-card .widget-data .font-14 {
    font-size: 15px !important;
    margin-bottom: 4px;
}
.finance-overview-page .finance-stat-card .widget-data small {
    display: block;
    margin-top: 4px;
    line-height: 1.45;
}
.finance-overview-page .finance-stat-card .widget-icon {
    flex: 0 0 58px;
    width: 58px;
    height: 58px;
    margin-left: 14px;
}
.finance-overview-page .finance-stat-card .widget-icon .icon {
    width: 58px;
    height: 58px;
    font-size: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.finance-overview-page .finance-month-card,
.finance-overview-page .finance-recorded-card {
    padding-top: 26px !important;
    padding-bottom: 26px !important;
}
.finance-overview-page .finance-month-card > .d-flex {
    margin-bottom: 20px !important;
}
.finance-overview-page .finance-mini-col {
    margin-bottom: 18px;
}
.finance-overview-page .finance-mini-stat {
    min-height: 106px;
    border: 1px solid rgba(108, 117, 125, .28);
    border-radius: 14px;
    padding: 20px 20px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.finance-overview-page .finance-mini-stat .text-secondary {
    font-size: 15px;
    margin-bottom: 8px;
}
.finance-overview-page .finance-mini-stat .h5 {
    font-size: 21px;
    line-height: 1.2;
}
.finance-overview-page .finance-recorded-card small {
    line-height: 1.55;
}
.finance-overview-page .finance-module-col {
    margin-bottom: 16px;
}
.finance-overview-page .finance-module-btn {
    min-height: 50px;
    padding: 12px 18px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    white-space: normal;
}
.finance-overview-page .footer-wrap {
    border-radius: 16px;
}
/* Light mode subtle separation */
html:not(.dark) .finance-overview-page .finance-stat-card,
html:not(.dark) .finance-overview-page .finance-panel {
    box-shadow: 0 8px 24px rgba(15, 23, 42, .05);
}
html:not(.dark) .finance-overview-page .finance-mini-stat {
    background: rgba(248, 250, 252, .72);
}
/* Dark mode: keep cards distinct without making them brighter */
html.dark .finance-overview-page .finance-stat-card,
html.dark .finance-overview-page .finance-panel {
    box-shadow: 0 8px 24px rgba(0, 0, 0, .16);
}
html.dark .finance-overview-page .finance-mini-stat {
    border-color: rgba(148, 163, 184, .24);
    background: rgba(15, 23, 42, .22);
}
@media (max-width: 991.98px) {
    .finance-overview-page .pd-ltr-20 {
        padding-left: 20px;
        padding-right: 20px;
    }
    .finance-overview-page .finance-stat-card {
        min-height: 126px;
    }
}
@media (max-width: 575.98px) {
    .finance-overview-page .pd-ltr-20 {
        padding-left: 14px;
        padding-right: 14px;
    }
    .finance-overview-page .page-header,
    .finance-overview-page .finance-panel {
        padding: 20px 18px !important;
        border-radius: 14px;
    }
    .finance-overview-page .finance-stat-card {
        min-height: 118px;
        padding: 20px !important;
    }
    .finance-overview-page .finance-stat-card .widget-data .font-24 {
        font-size: 24px !important;
    }
    .finance-overview-page .finance-mini-stat {
        min-height: 96px;
        padding: 18px;
    }
}

/* HEADER LOGOUT */
.admin-logout-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 40px;
    margin: 0 18px 0 10px;
    padding: 8px 14px;
    border: 1px solid #fecaca;
    border-radius: 11px;
    background: #fff;
    color: #b91c1c !important;
    font-size: 12px;
    font-weight: 800;
    line-height: 1;
    text-decoration: none !important;
    white-space: nowrap;
    transition: .18s ease;
}
.admin-logout-btn i {
    font-size: 17px;
    line-height: 1;
}
.admin-logout-btn:hover,
.admin-logout-btn:focus {
    background: #fef2f2;
    border-color: #ef4444;
    color: #991b1b !important;
    transform: translateY(-1px);
    outline: none;
}
html.dark .admin-logout-btn {
    background: rgba(127,29,29,.16);
    border-color: rgba(248,113,113,.30);
    color: #fca5a5 !important;
}
html.dark .admin-logout-btn:hover,
html.dark .admin-logout-btn:focus {
    background: rgba(127,29,29,.28);
    border-color: rgba(248,113,113,.55);
    color: #fecaca !important;
}
html.dark .finance-overview-page .page-header,
html.dark .finance-overview-page .card-box,
html.dark .finance-overview-page .footer-wrap {
    background: var(--admin-surface) !important;
    color: var(--admin-text) !important;
    border-color: var(--admin-border) !important;
}
html.dark .finance-overview-page .breadcrumb,
html.dark .finance-overview-page .breadcrumb-item,
html.dark .finance-overview-page .breadcrumb-item a {
    color: var(--admin-muted) !important;
}
html.dark .finance-overview-page .form-control {
    background: var(--admin-input) !important;
    color: var(--admin-text) !important;
    border-color: var(--admin-border) !important;
}
@media (max-width: 575.98px) {
    .admin-logout-btn {
        width: 40px;
        min-width: 40px;
        height: 40px;
        min-height: 40px;
        margin: 0 10px 0 6px;
        padding: 0;
        border-radius: 10px;
    }
    .admin-logout-text { display: none; }
    .admin-logout-btn i { font-size: 18px; }
}

</style>
</head>
<body>
  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
      <div class="search-toggle-icon dw dw-search2" data-toggle="header_search"></div>
    </div>
    <div class="header-right">
    <!-- DARK MODE TOGGLE -->
    <div class="admin-theme-switch">
        <button type="button"
                id="themeToggle"
                class="admin-theme-toggle"
                aria-label="Switch theme"
                title="Switch theme">
            <span id="themeIcon">☾</span>
        </button>
    </div>

      <a href="logout.php" class="admin-logout-btn" title="Log Out" aria-label="Log Out">
        <i class="dw dw-logout" aria-hidden="true"></i>
        <span class="admin-logout-text">Log Out</span>
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
  <div class="main-container finance-overview-page">
    <div class="pd-ltr-20">
      <div class="page-header">
        <div class="row">
          <div class="col-md-6 col-sm-12">
            <div class="title"><h4>Finance Overview</h4></div>
            <div class="text-secondary mb-2">
              Phase: <b><?= esc($phase) ?></b>
            </div>
            <nav aria-label="breadcrumb" role="navigation">
              <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                <li class="breadcrumb-item active" aria-current="page">Finance</li>
              </ol>
            </nav>
          </div>
          <div class="col-md-6 col-sm-12 text-right">
            <?php if ($canPickPhase): ?>
              <form method="get" class="d-inline-block">
                <label class="mr-2">Phase</label>
                <select name="phase" class="form-control d-inline-block" style="width:200px" onchange="this.form.submit()">
                  <?php foreach(['Phase 1','Phase 2','Phase 3'] as $p): ?>
                    <option value="<?=esc($p)?>" <?= $p===$phase?'selected':'' ?>><?=esc($p)?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php else: ?>
              <span class="badge badge-primary p-2"><?= esc($phase) ?></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="card-box finance-panel finance-balance-card">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
          <div>
            <h5 class="mb-1">Balance Basis</h5>
            <?php if ($hasOpeningBalance): ?>
              <div class="text-secondary">
                Opening balance:
                <b>₱ <?= number_format($opening, 2) ?></b>
                as of
                <b><?= esc(date('F d, Y', strtotime($openingAsOf))) ?></b>
              </div>
              <small class="text-secondary">
                Current Balance adds/subtracts transactions recorded after the opening-balance date.
              </small>
            <?php else: ?>
              <div class="text-secondary">
                No opening balance is configured.
                Current Balance is calculated from all recorded transactions.
              </div>
            <?php endif; ?>
          </div>
          <span class="badge badge-primary p-2 mt-2 mt-md-0">
            <?= esc($balancePeriodLabel) ?>
          </span>
        </div>
      </div>
      <div class="row">
        <div class="col-xl-3 col-lg-6 col-md-6 finance-stat-col">
          <div class="card-box height-100-p widget-style3 finance-stat-card">
            <div class="d-flex flex-wrap">
              <div class="widget-data">
                <div class="weight-700 font-24">
                  ₱ <?= number_format($current_balance, 2) ?>
                </div>
                <div class="font-14 text-secondary weight-500">
                  Current Balance
                </div>
                <small class="text-secondary">
                  Opening + income − expenses
                </small>
              </div>
              <div class="widget-icon">
                <div class="icon" data-color="#00eccf">
                  <i class="dw dw-money"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6 finance-stat-col">
          <div class="card-box height-100-p widget-style3 finance-stat-card">
            <div class="d-flex flex-wrap">
              <div class="widget-data">
                <div class="weight-700 font-24">
                  ₱ <?= number_format($total_paid, 2) ?>
                </div>
                <div class="font-14 text-secondary weight-500">
                  Dues Collected
                </div>
                <small class="text-secondary">
                  <?= esc($balancePeriodLabel) ?>
                </small>
              </div>
              <div class="widget-icon">
                <div class="icon" data-color="#ffd000">
                  <i class="dw dw-wallet"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6 finance-stat-col">
          <div class="card-box height-100-p widget-style3 finance-stat-card">
            <div class="d-flex flex-wrap">
              <div class="widget-data">
                <div class="weight-700 font-24">
                  ₱ <?= number_format($total_don, 2) ?>
                </div>
                <div class="font-14 text-secondary weight-500">
                  Donations
                </div>
                <small class="text-secondary">
                  <?= esc($balancePeriodLabel) ?>
                </small>
              </div>
              <div class="widget-icon">
                <div class="icon" data-color="#6f42c1">
                  <i class="dw dw-heart"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-lg-6 col-md-6 finance-stat-col">
          <div class="card-box height-100-p widget-style3 finance-stat-card">
            <div class="d-flex flex-wrap">
              <div class="widget-data">
                <div class="weight-700 font-24">
                  ₱ <?= number_format($total_exp, 2) ?>
                </div>
                <div class="font-14 text-secondary weight-500">
                  Expenses
                </div>
                <small class="text-secondary">
                  <?= esc($balancePeriodLabel) ?>
                </small>
              </div>
              <div class="widget-icon">
                <div class="icon" data-color="#dc3545">
                  <i class="dw dw-file"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="card-box finance-panel finance-month-card">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-1">This Month</h5>
            <div class="text-secondary">
              <?= esc(date('F Y')) ?>
            </div>
          </div>
          <span class="badge <?= $monthNet >= 0 ? 'badge-success' : 'badge-danger' ?> p-2">
            Net:
            ₱ <?= number_format($monthNet, 2) ?>
          </span>
        </div>
        <div class="row">
          <div class="col-md-6 col-xl-3 finance-mini-col">
            <div class="finance-mini-stat h-100">
              <div class="text-secondary">Dues</div>
              <div class="h5 mb-0">
                ₱ <?= number_format($monthDues, 2) ?>
              </div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3 finance-mini-col">
            <div class="finance-mini-stat h-100">
              <div class="text-secondary">Donations</div>
              <div class="h5 mb-0">
                ₱ <?= number_format($monthDonations, 2) ?>
              </div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3 finance-mini-col">
            <div class="finance-mini-stat h-100">
              <div class="text-secondary">Total Income</div>
              <div class="h5 mb-0 text-success">
                ₱ <?= number_format($monthIncome, 2) ?>
              </div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3 finance-mini-col">
            <div class="finance-mini-stat h-100">
              <div class="text-secondary">Expenses</div>
              <div class="h5 mb-0 text-danger">
                ₱ <?= number_format($monthExpenses, 2) ?>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="card-box finance-panel finance-recorded-card">
        <h5 class="mb-3">Recorded Totals</h5>
        <div class="row">
          <div class="col-md-4 finance-mini-col">
            <div class="finance-mini-stat h-100">
              <div class="text-secondary">All Paid Dues</div>
              <div class="h5 mb-0">
                ₱ <?= number_format($lifetimeDues, 2) ?>
              </div>
            </div>
          </div>
          <div class="col-md-4 finance-mini-col">
            <div class="finance-mini-stat h-100">
              <div class="text-secondary">All Donations</div>
              <div class="h5 mb-0">
                ₱ <?= number_format($lifetimeDonations, 2) ?>
              </div>
            </div>
          </div>
          <div class="col-md-4 finance-mini-col">
            <div class="finance-mini-stat h-100">
              <div class="text-secondary">All Expenses</div>
              <div class="h5 mb-0">
                ₱ <?= number_format($lifetimeExpenses, 2) ?>
              </div>
            </div>
          </div>
        </div>
        <small class="text-secondary d-block mt-2">
          These are reference totals from all recorded transactions and are not added again to the opening balance.
        </small>
      </div>
      <div class="card-box finance-panel finance-modules-card">
        <h5 class="mb-3">Finance Modules</h5>
        <div class="row">
          <div class="col-md-4 finance-module-col"><a class="btn btn-outline-primary btn-block finance-module-btn" href="finance_dues.php<?= $canPickPhase?('?phase='.urlencode($phase)):'' ?>">Monthly Dues Management</a></div>
          <div class="col-md-4 finance-module-col"><a class="btn btn-outline-primary btn-block finance-module-btn" href="finance_donations.php<?= $canPickPhase?('?phase='.urlencode($phase)):'' ?>">Donations & Contributions</a></div>
          <div class="col-md-4 finance-module-col"><a class="btn btn-outline-primary btn-block finance-module-btn" href="finance_expenses.php<?= $canPickPhase?('?phase='.urlencode($phase)):'' ?>">Expense Tracking</a></div>
          <div class="col-md-6 finance-module-col"><a class="btn btn-outline-success btn-block finance-module-btn" href="finance_reports.php<?= $canPickPhase?('?phase='.urlencode($phase)):'' ?>">Financial Reports (President Approval)</a></div>
          <div class="col-md-6 finance-module-col"><a class="btn btn-outline-dark btn-block finance-module-btn" href="finance_cashflow.php<?= $canPickPhase?('?phase='.urlencode($phase)):'' ?>">Cash Flow Dashboard</a></div>
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
<!-- ADMIN DARK MODE -->
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