<?php
require_once __DIR__ . "/finance_helpers.php";
require_once 'admin_access.php';
requireAccess('finance');
require_admin();
$conn = db_conn();

$myPhase = admin_phase($conn);
[$phase, $canPickPhase] = phase_scope_clause($myPhase);
$adminId = admin_id();


if (isset($_POST['add_donation'])) {
  $donor_name    = trim($_POST['donor_name'] ?? '');
  $donor_email   = trim($_POST['donor_email'] ?? '');
  $amount        = (float)($_POST['amount'] ?? 0);
  $donation_date = $_POST['donation_date'] ?? date('Y-m-d');
  $receipt_no    = trim($_POST['receipt_no'] ?? '');
  $message       = trim($_POST['message'] ?? '');

  if ($donor_name !== '' && $amount > 0) {
    $stmt = $conn->prepare("
      INSERT INTO finance_donations
        (phase, donor_name, donor_email, amount, donation_date, receipt_no, message, created_by_admin_id)
      VALUES (?,?,?,?,?,?,?,?)
    ");
    if (!$stmt) {
      die("Prepare failed: " . $conn->error);
    }

    // ✅ Correct: 8 placeholders = 8 types, no spaces, bind only once
    $stmt->bind_param(
      "sssdsssi",
      $phase,
      $donor_name,
      $donor_email,
      $amount,
      $donation_date,
      $receipt_no,
      $message,
      $adminId
    );

    $stmt->execute();
    $stmt->close();
  }

  header("Location: finance_donations.php" . ($canPickPhase ? ("?phase=" . urlencode($phase)) : ""));
  exit;
}

$stmt = $conn->prepare("
  SELECT *
  FROM finance_donations
  WHERE phase=?
  ORDER BY donation_date DESC, id DESC
  LIMIT 300
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
  <!-- Basic Page Info -->
  <meta charset="utf-8">
  <title>HOA-ADMIN</title>

  <!-- Site favicon -->
  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">

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
  transform: translateY(-10px);
  transition: all .3s ease;
}

.access-toast.show {
  opacity: 1;
  transform: translateY(0);
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

      <div class="user-notification">
        <div class="dropdown">
          <a class="dropdown-toggle no-arrow" href="#" role="button" data-toggle="dropdown">
            <i class="icon-copy dw dw-notification"></i>
            <span class="badge notification-active"></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right">
            <div class="notification-list mx-h-350 customscroll">
              <ul>
                <li><a href="#"><img src="vendors/images/img.jpg" alt=""><h3>John Doe</h3><p>Lorem ipsum dolor sit amet...</p></a></li>
                <li><a href="#"><img src="vendors/images/photo1.jpg" alt=""><h3>Lea R. Frith</h3><p>Lorem ipsum dolor sit amet...</p></a></li>
                <li><a href="#"><img src="vendors/images/photo2.jpg" alt=""><h3>Erik L. Richards</h3><p>Lorem ipsum dolor sit amet...</p></a></li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="user-info-dropdown">
        <div class="dropdown">
          <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
            <span class="user-icon">
              <img src="vendors/images/photo1.jpg" alt="">
            </span>
          </a>
          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="profile.html"><i class="dw dw-user1"></i> Profile</a>
            <a class="dropdown-item" href="profile.html"><i class="dw dw-settings2"></i> Setting</a>
            <a class="dropdown-item" href="logout.php"><i class="dw dw-logout"></i> Log Out</a>
          </div>
        </div>
      </div>

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
          <a href="javascript:void(0);" class="btn btn-outline-primary sidebar-light">White</a>
          <a href="javascript:void(0);" class="btn btn-outline-primary sidebar-dark active">Dark</a>
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
          <div class="title"><h4>Donations & Contributions</h4></div>
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

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">Record Donation</h5>
      <form method="post">
        <div class="row">
          <div class="col-md-4">
            <label>Donor Name</label>
            <input class="form-control" name="donor_name" required>
          </div>
          <div class="col-md-4">
            <label>Donor Email (optional)</label>
            <input class="form-control" name="donor_email" type="email">
          </div>
          <div class="col-md-2">
            <label>Amount</label>
            <input class="form-control" name="amount" type="number" step="0.01" min="0" required>
          </div>
          <div class="col-md-2">
            <label>Date</label>
            <input class="form-control" name="donation_date" type="date" value="<?=date('Y-m-d')?>" required>
          </div>
          <div class="col-md-3 mt-2">
            <label>Receipt #</label>
            <input class="form-control" name="receipt_no" placeholder="Show on acknowledgment">
          </div>
          <div class="col-md-9 mt-2">
            <label>Message / Notes</label>
            <input class="form-control" name="message" placeholder="Optional">
          </div>
        </div>
        <button class="btn btn-success mt-3" name="add_donation">Save Donation</button>
      </form>
    </div>

    <div class="card-box mb-20 p-3">
      <h5 class="mb-3">Donor List</h5>
      <div class="table-responsive">
        <table id="donTable" class="table table-striped table-hover">
          <thead>
            <tr>
              <th>Date</th>
              <th>Donor</th>
              <th>Email</th>
              <th>Amount</th>
              <th>Receipt #</th>
              <th>Notes</th>
              <th>Receipt</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?=esc($r['donation_date'])?></td>
                <td><?=esc($r['donor_name'])?></td>
                <td><?=esc($r['donor_email'] ?? '')?></td>
                <td>₱ <?=number_format((float)$r['amount'],2)?></td>
                <td><?=esc($r['receipt_no'] ?? '')?></td>
                <td><?=esc($r['message'] ?? '')?></td>
                <td>
                  <a class="btn btn-sm btn-outline-primary" target="_blank"
                     href="finance_donation_receipt.php?id=<?=(int)$r['id']?><?= $canPickPhase?('&phase='.urlencode($phase)) : '' ?>">
                    Receipt
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <small class="text-secondary">Acknowledgment receipt printing can be added next (PDF generator).</small>
    </div>

    <div class="footer-wrap pd-20 mb-20 card-box">
      © Copyright South Meridian Homes All Rights Reserved
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function(){
  $('#donTable').DataTable({ pageLength: 25, order: [[0,'desc']] });
});
</script>

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>
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
