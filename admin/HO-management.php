<?php
session_start();

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
    echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
    exit();
}
// ===================== DB CONNECTION =====================
$host = "localhost";
$db   = "south_meridian_hoa";
$user = "root";
$pass = "";
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Renders the same UI that used to be in view_homeowner.php
 * but returned as HTML for AJAX.
 */
function render_homeowner_profile_html(mysqli $conn, string $admin_role, string $admin_phase, int $id): string {

    // Fetch homeowner (respect role/phase)
    if ($admin_role === 'superadmin') {
        $stmt = $conn->prepare("SELECT * FROM homeowners WHERE id=?");
        $stmt->bind_param("i", $id);
    } else {
        $stmt = $conn->prepare("SELECT * FROM homeowners WHERE id=? AND phase=?");
        $stmt->bind_param("is", $id, $admin_phase);
    }
    $stmt->execute();
    $homeowner = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$homeowner) {
        return '<div class="p-4"><div class="alert alert-danger mb-0">Homeowner not found or not allowed.</div></div>';
    }

    // Members
    $stmt = $conn->prepare("SELECT * FROM household_members WHERE homeowner_id=? ORDER BY id ASC");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $members = $stmt->get_result();
    $stmt->close();

    $lat = $homeowner['latitude'] ?? '';
    $lng = $homeowner['longitude'] ?? '';
    $validId = $homeowner['valid_id_path'] ?? '';
    $proof   = $homeowner['proof_of_billing_path'] ?? '';

    $fn = trim((string)($homeowner['first_name'] ?? ''));
    $ln = trim((string)($homeowner['last_name'] ?? ''));
    $initials = strtoupper(($fn ? $fn[0] : 'H') . ($ln ? $ln[0] : 'O'));

    $status = strtolower((string)($homeowner['status'] ?? 'pending'));
    $badgeClass = 'badge-soft-warning';
    if ($status === 'approved') $badgeClass = 'badge-soft-success';
    if ($status === 'rejected') $badgeClass = 'badge-soft-danger';
    

    ob_start();
    ?>
    <style>
      :root{--brand:#077f46;--ink:#0f172a;--muted:#64748b;--bg:#f4f6fb;--card:#fff;--border:#e5e7eb;}
      .profile-shell{border-radius:16px;overflow:hidden;box-shadow:0 12px 32px rgba(15,23,42,.08);background:var(--card);}
      .cover{position:relative;height:320px;background:#e9eef6;}
      #coverMap{height:100%;width:100%;}
      .leaflet-container,.leaflet-pane,.leaflet-top,.leaflet-bottom{z-index:1!important;}
      .cover-overlay{position:absolute;inset:0;background:linear-gradient(to bottom,rgba(0,0,0,.15),rgba(0,0,0,.45));z-index:2;pointer-events:none;}
      .profile-header{position:relative;padding:0 24px 18px;}
      .avatar{width:130px;height:130px;border-radius:50%;background:linear-gradient(135deg,var(--brand),#0bbf6a);
        border:6px solid #fff;display:grid;place-items:center;color:#fff;font-weight:800;font-size:40px;
        position:absolute;top:-65px;left:24px;z-index:4;box-shadow:0 10px 25px rgba(15,23,42,.18);}
      .profile-meta{padding-top:16px;padding-left:160px;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;}
      .name{font-size:26px;font-weight:800;margin:0;line-height:1.15;}
      .subline{margin:6px 0 0;color:var(--muted);font-weight:600;}
      .pill{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:#f1f5f9;color:#0f172a;
        font-weight:700;font-size:13px;border:1px solid var(--border);}
      .badge-soft-warning{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;}
      .badge-soft-success{background:#ecfdf5;border:1px solid #bbf7d0;color:#166534;}
      .badge-soft-danger{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;}
      .cardx{background:var(--card);border:1px solid var(--border);border-radius:16px;box-shadow:0 10px 24px rgba(15,23,42,.06);}
      .cardx-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:12px;}
      .cardx-title{margin:0;font-weight:800;font-size:16px;}
      .cardx-body{padding:16px 18px;}
      .kv{display:grid;grid-template-columns:160px 1fr;gap:10px 14px;}
      .k{color:var(--muted);font-weight:700;font-size:13px;}
      .v{font-weight:700;color:var(--ink);}
      .doc-card{border:1px solid var(--border);border-radius:14px;overflow:hidden;background:#fff;}
      .doc-thumb{width:100%;height:180px;object-fit:cover;background:#f1f5f9;display:block;}
      .doc-meta{padding:12px 12px 14px;}
      .doc-title{font-weight:800;margin:0 0 8px;}
      .table thead th{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;}
      @media (max-width:768px){
        .cover{height:260px;}
        .avatar{left:50%;transform:translateX(-50%);}
        .profile-meta{padding-left:0;padding-top:84px;text-align:center;justify-content:center;}
      }
    </style>

    <div class="p-3 p-lg-4" style="background:var(--bg);">
      <div class="profile-shell mb-4">
        <div class="cover">
          <?php if (!empty($lat) && !empty($lng)): ?>
            <div id="coverMap" data-lat="<?= esc($lat) ?>" data-lng="<?= esc($lng) ?>"></div>
            <div class="cover-overlay"></div>
          <?php else: ?>
            <div class="h-100 w-100 d-flex align-items-center justify-content-center bg-light">
              <div class="text-muted fw-semibold">No location saved for this homeowner.</div>
            </div>
          <?php endif; ?>
        </div>

        <div class="profile-header">
          <div class="avatar"><?= esc($initials) ?></div>

          <div class="profile-meta">
            <div>
              <p class="name"><?= esc(($homeowner['first_name'] ?? '').' '.($homeowner['middle_name'] ?? '').' '.($homeowner['last_name'] ?? '')) ?></p>
              <p class="subline">
                <?= esc($homeowner['phase'] ?? '') ?> • <?= esc($homeowner['house_lot_number'] ?? '') ?> •
                <span class="pill <?= esc($badgeClass) ?>"><?= esc(ucfirst($status)) ?></span>
              </p>
            </div>

            <div class="d-flex gap-2 flex-wrap">
              <span class="pill">📧 <?= esc($homeowner['email'] ?? '') ?></span>
              <span class="pill">📞 <?= esc($homeowner['contact_number'] ?? '') ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-lg-5">
          <div class="cardx mb-4">
            <div class="cardx-head">
              <h5 class="cardx-title">Homeowner Information</h5>
              <span class="pill">🕒 <small><?= esc($homeowner['created_at'] ?? '') ?></small></span>
            </div>
            <div class="cardx-body">
              <div class="kv">
                <div class="k">Full Name</div>
                <div class="v"><?= esc(($homeowner['first_name'] ?? '').' '.($homeowner['middle_name'] ?? '').' '.($homeowner['last_name'] ?? '')) ?></div>

                <div class="k">Email</div>
                <div class="v"><?= esc($homeowner['email'] ?? '') ?></div>

                <div class="k">Contact</div>
                <div class="v"><?= esc($homeowner['contact_number'] ?? '') ?></div>

                <div class="k">Phase</div>
                <div class="v"><?= esc($homeowner['phase'] ?? '') ?></div>

                <div class="k">House / Lot</div>
                <div class="v"><?= esc($homeowner['house_lot_number'] ?? '') ?></div>

                <div class="k">Status</div>
                <div class="v text-capitalize"><?= esc($status) ?></div>
              </div>
            </div>
          </div>

          <div class="cardx">
            <div class="cardx-head"><h5 class="cardx-title">Uploaded Documents</h5></div>
            <div class="cardx-body">
              <div class="row g-3">

                <div class="col-12">
                  <div class="doc-card">
                    <?php if ($validId): ?>
                      <img class="doc-thumb" src="<?= esc($validId) ?>" alt="Valid ID" onerror="this.style.display='none'">
                    <?php else: ?>
                      <div class="doc-thumb d-flex align-items-center justify-content-center text-muted fw-semibold">
                        No Valid ID uploaded
                      </div>
                    <?php endif; ?>
                    <div class="doc-meta">
                      <p class="doc-title mb-1">Valid ID</p>
                      <?php if ($validId): ?>
                        <a class="btn btn-sm btn-success" target="_blank" href="<?= esc($validId) ?>">Open File</a>
                      <?php else: ?>
                        <span class="text-muted fw-semibold">—</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="col-12">
                  <div class="doc-card">
                    <?php if ($proof): ?>
                      <img class="doc-thumb" src="<?= esc($proof) ?>" alt="Proof of Billing" onerror="this.style.display='none'">
                    <?php else: ?>
                      <div class="doc-thumb d-flex align-items-center justify-content-center text-muted fw-semibold">
                        No Proof of Billing uploaded
                      </div>
                    <?php endif; ?>
                    <div class="doc-meta">
                      <p class="doc-title mb-1">Proof of Billing</p>
                      <?php if ($proof): ?>
                        <a class="btn btn-sm btn-success" target="_blank" href="<?= esc($proof) ?>">Open File</a>
                      <?php else: ?>
                        <span class="text-muted fw-semibold">—</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="cardx">
            <div class="cardx-head">
              <h5 class="cardx-title">Household Members</h5>
              <span class="pill">👥 <?= (int)$members->num_rows ?> member(s)</span>
            </div>
            <div class="cardx-body">
              <?php if ($members->num_rows > 0): ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0">
                    <thead>
                      <tr>
                        <th style="width:60px;">#</th>
                        <th>Full Name</th>
                        <th style="width:180px;">Relation</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php $i=1; while($m = $members->fetch_assoc()): ?>
                        <tr>
                          <td class="fw-bold"><?= $i++ ?></td>
                          <td class="fw-semibold">
                            <?= esc($m['first_name'] ?? '') ?>
                            <?= esc($m['middle_name'] ?? '') ?>
                            <?= esc($m['last_name'] ?? '') ?>
                          </td>
                          <td><span class="pill"><?= esc($m['relation'] ?? '') ?></span></td>
                        </tr>
                      <?php endwhile; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="text-muted fw-semibold">No household members found.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div>
    </div>
    <?php
    return ob_get_clean();
}

// ===================== AJAX ENDPOINT (same file) =====================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'homeowner_profile') {

    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','superadmin'])) {
        http_response_code(401);
        echo '<div class="p-4"><div class="alert alert-danger mb-0">Unauthorized</div></div>';
        exit;
    }

    $idAjax = (int)($_GET['id'] ?? 0);
    if ($idAjax <= 0) {
        http_response_code(400);
        echo '<div class="p-4"><div class="alert alert-warning mb-0">Invalid ID</div></div>';
        exit;
    }

    // Get admin phase/role
    $admin_id_sess = (int)($_SESSION['admin_id'] ?? 0);
    $admin_phase = (string)$_SESSION['admin_phase'];
    $admin_role  = (string)$_SESSION['admin_role'];

    if ($admin_id_sess > 0) {
        $stmt = $conn->prepare("SELECT phase, role FROM admins WHERE id=?");
        $stmt->bind_param("i", $admin_id_sess);
        $stmt->execute();
        $a = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $admin_phase = $a['phase'] ?? '';
        $admin_role  = $a['role'] ?? '';
    }

    echo render_homeowner_profile_html($conn, (string)$admin_role, (string)$admin_phase, $idAjax);
    exit;
}

// ===================== REGISTER SUBMISSION (MAP STEP) =====================

// Handle final submission including latitude & longitude
if(isset($_POST['submit_location'])) {

    $first_name = $_POST['first_name'];
    $middle_name = $_POST['middle_name'];
    $last_name = $_POST['last_name'];
    $contact_number = $_POST['contact_number'];
    $email = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $phase = $_POST['phase'];
    $house_lot_number = $_POST['house_lot_number'];
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];

    // Assign admin based on phase
    $stmtAdmin = $conn->prepare("SELECT id FROM admins WHERE phase=? LIMIT 1");
    $stmtAdmin->bind_param("s", $phase);
    $stmtAdmin->execute();
    $resAdmin = $stmtAdmin->get_result()->fetch_assoc();
    $admin_id = $resAdmin['id'] ?? NULL;

    // Handle file uploads
    $uploadDir = "uploads/";
    if(!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $valid_id_path = $uploadDir . time() . "_id_" . basename($_FILES['valid_id']['name']);
    $proof_path = $uploadDir . time() . "_proof_" . basename($_FILES['proof_of_billing']['name']);

    move_uploaded_file($_FILES['valid_id']['tmp_name'], $valid_id_path);
    move_uploaded_file($_FILES['proof_of_billing']['tmp_name'], $proof_path);

    // Insert homeowner
    $stmtHome = $conn->prepare("INSERT INTO homeowners
        (first_name,middle_name,last_name,contact_number,email,password,phase,house_lot_number,valid_id_path,proof_of_billing_path,latitude,longitude,admin_id,status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $status = 'pending';

    // NOTE: your bind types were wrong in your current code, fixed to match 14 params:
    // 12 strings + 2 ints? (latitude/longitude are best as doubles; admin_id is int)
    // We'll bind lat/lng as "dd" and admin_id as "i" => types: ssssssssssddis (13?) not correct.
    // To keep safe, bind lat/lng as strings (since your DB might be VARCHAR/DECIMAL anyway)
    $latS = (string)$latitude;
    $lngS = (string)$longitude;

    $stmtHome->bind_param(
        "ssssssssssssis",
        $first_name, $middle_name, $last_name, $contact_number, $email, $password, $phase, $house_lot_number,
        $valid_id_path, $proof_path, $latS, $lngS, $admin_id, $status
    );

    $stmtHome->execute();
    $homeowner_id = $stmtHome->insert_id;

    // Insert household members
    if(isset($_POST['member_first_name'])) {
        foreach($_POST['member_first_name'] as $i => $mfname) {
            $mmname = $_POST['member_middle_name'][$i];
            $mlname = $_POST['member_last_name'][$i];
            $relation = $_POST['member_relation'][$i];

            $stmtMember = $conn->prepare("INSERT INTO household_members
                (homeowner_id, first_name, middle_name, last_name, relation)
                VALUES (?,?,?,?,?)");
            $stmtMember->bind_param("issss", $homeowner_id, $mfname, $mmname, $mlname, $relation);
            $stmtMember->execute();
        }
    }

    echo "<script>alert('Done Registering.'); location.href='HO-management.php';</script>";
    exit;
}

// Determine if we need to show map
$showMap = isset($_POST['registration_submit']) && empty($_POST['submit_location']);
$view = $_GET['view'] ?? 'approval';
$allowedViews = ['approval','registration','approved'];
if (!in_array($view, $allowedViews, true)) $view = 'approval';
// If map step is showing, force registration view
if ($showMap) $view = 'registration';
$isHOSection = in_array($view, ['approval','registration','approved'], true);

// ===================== GET ADMIN INFO =====================
$admin_id = $_SESSION['user_id'];
$sqlAdmin = $conn->prepare("SELECT phase, role FROM admins WHERE id = ?");
$sqlAdmin->bind_param("i", $admin_id);
$sqlAdmin->execute();
$resultAdmin = $sqlAdmin->get_result();
$admin = $resultAdmin->fetch_assoc();
$admin_phase = $admin['phase'];
$admin_role = $admin['role'];

// ===================== FETCH PENDING HOMEOWNERS =====================
if ($admin_role == 'superadmin') {
    $sqlHO = $conn->prepare("SELECT * FROM homeowners WHERE status='pending'");
} else {
    $sqlHO = $conn->prepare("SELECT * FROM homeowners WHERE status='pending' AND phase=?");
    $sqlHO->bind_param("s", $admin_phase);
}
$sqlHO->execute();
$resultHO = $sqlHO->get_result();
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
	  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

	<!-- Global site tag (gtag.js) - Google Analytics -->
	 <!-- Include CSS for DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

	<script async src="https://www.googletagmanager.com/gtag/js?id=UA-119386393-1"></script>

    <!-- ================= MAPS ================= -->

<link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css">
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

</head>
<style>
	.badge {
    padding: 0.25em 0.5em;
    border-radius: 0.25rem;
    color: #fff;
    font-size: 0.875rem;
}

.badge-warning { background-color: #f0ad4e; }  /* Pending */
.badge-success { background-color: #5cb85c; }  /* Approved */
.badge-danger  { background-color: #d9534f; }  /* Rejected */

#map {
  height: 500px;
  width: 100%;
}


</style>
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
								<li>
									<a href="#">
										<img src="vendors/images/img.jpg" alt="">
										<h3>John Doe</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/photo1.jpg" alt="">
										<h3>Lea R. Frith</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/photo2.jpg" alt="">
										<h3>Erik L. Richards</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/photo3.jpg" alt="">
										<h3>John Doe</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/photo4.jpg" alt="">
										<h3>Renee I. Hansen</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
								<li>
									<a href="#">
										<img src="vendors/images/img.jpg" alt="">
										<h3>Vicki M. Coleman</h3>
										<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed...</p>
									</a>
								</li>
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
						<span class="user-name">JOHNFALL SANCHEZ</span>
					</a>
					<div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
						<a class="dropdown-item" href="profile.html"><i class="dw dw-user1"></i> Profile</a>
						<a class="dropdown-item" href="profile.html"><i class="dw dw-settings2"></i> Setting</a>
						<a class="dropdown-item" href="index.php"><i class="dw dw-logout"></i> Log Out</a>
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

	<div class="left-side-bar" style="background-color: #077f46;">
		<div class="brand-logo">
			<a href="index.html">
				<img src="vendors/images/deskapp-logo.svg" alt="" class="dark-logo">
				<img src="vendors/images/deskapp-logo-white.svg" alt="" class="light-logo">
			</a>
			<div class="close-sidebar" data-toggle="left-sidebar-close">
				<i class="ion-close-round"></i>
			</div>
		</div>
	<div class="menu-block customscroll">
    <div class="sidebar-menu">
    <ul id="accordion-menu">
        <li>
            <a href="dashboard.php" class="dropdown-toggle no-arrow">
                <span class="micon dw dw-house-1"></span>
                <span class="mtext">Dashboard</span>
            </a>
        </li>
            <li class="dropdown <?= $isHOSection ? 'show' : '' ?>">
            <a href="javascript:;" class="dropdown-toggle <?= $isHOSection ? 'active' : '' ?>">
                <span class="micon dw dw-user"></span>
                <span class="mtext">Homeowner Management</span>
            </a>

            <ul class="submenu" style="<?= $isHOSection ? 'display:block;' : '' ?>">
                <li>
                <a class="<?= $view==='approval' ? 'active' : '' ?>" href="HO-management.php?view=approval">
                    Household Approval
                </a>
                </li>
                <li>
                <a class="<?= $view==='registration' ? 'active' : '' ?>" href="HO-management.php?view=registration">
                    Register Household
                </a>
                </li>
                <li>
                <a class="<?= $view==='approved' ? 'active' : '' ?>" href="HO-management.php?view=approved">
                    Approved Households
                </a>
                </li>
            </ul>
            </li>


        <!-- NEW: User Management -->
        <li>
            <a href="users-management.php" class="dropdown-toggle no-arrow">
                <span class="micon dw dw-user"></span>
                <span class="mtext">User Management</span>
            </a>
        </li>

        <!-- NEW: Announcement -->
        <li>
            <a href="announcements.php" class="dropdown-toggle no-arrow">
                <span class="micon dw dw-megaphone"></span>
                <span class="mtext">Announcement</span>
            </a>
        </li>
                        <!-- NEW: Treasurer -->
        <li>
          <a href="treasurer.php" class="dropdown-toggle no-arrow">
            <span class="micon dw dw-money-1"></span>
            <span class="mtext">Treasurer</span>
          </a>
        </li>
        <!-- Settings (now pushed down) -->
        <li>
            <a href="settings.php" class="dropdown-toggle no-arrow">
                <span class="micon dw dw-settings2"></span>
                <span class="mtext">Settings</span>
            </a>
        </li>
    </ul>
    </div>
</div>

	</div>
	<div class="mobile-menu-overlay"></div>

	<div class="main-container">
			<div class="row">
             <!-- Tabs -->
                <?php
                $viewTitleMap = [
                'approval'      => 'Household Approval',
                'registration'  => 'Register Household',
                'approved'      => 'Approved Households',
                ];
                $currentTitle = $viewTitleMap[$view] ?? 'Household Approval';
                ?>
                <div class="d-flex align-items-center justify-content-center mb-3 text-center">
                <div>
                    <h2 class="h4 mb-1">Home Owner Management</h2>
                    <div class="text-muted fw-semibold" style="font-size:14px;">
                    <?= esc($currentTitle) ?>
                    </div>
                </div>
                </div>


 <!-- Tab Contents -->
	<div class="tab-content" id="mainTabsContent">
    <!-- 1️⃣ Household Approval Table -->
    <div id="approval" style="<?= $view==='approval' ? '' : 'display:none;' ?>">
        <table id="approvalTable" class="display table table-striped table-bordered nowrap" style="width:100%">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $resultHO->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= $row['first_name'] . ' ' . ($row['middle_name'] ?: '') . ' ' . $row['last_name'] ?></td>
                        <td><?= $row['phase'] . ', ' . $row['house_lot_number'] ?></td>
                        <td>
                            <?php
                                $status = $row['status'];
                                $badgeClass = '';
                                if($status == 'pending') $badgeClass = 'badge-warning';
                                elseif($status == 'approved') $badgeClass = 'badge-success';
                                elseif($status == 'rejected') $badgeClass = 'badge-danger';
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= ucfirst($status) ?></span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-success approveHomeowner" data-id="<?= $row['id'] ?>" title="Approve">
                                <i class="dw dw-checked"></i>
                            </button>
                            <button class="btn btn-sm btn-danger rejectHomeowner" data-id="<?= $row['id'] ?>" title="Reject">
                                <i class="dw dw-cancel-1"></i> Reject
                            </button>
											<button type="button" class="btn btn-sm btn-info viewHomeownerBtn" data-id="<?= (int)$row['id'] ?>" title="View">
												<i class="dw dw-eye"></i>
											</button>                                               
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <!-- Registration Form -->
<div id="registration" style="<?= $view==='registration' ? '' : 'display:none;' ?>">
<?php if(!$showMap): ?>
<form id="registrationForm" method="POST" enctype="multipart/form-data">
    <!-- HOMEOWNER INFO FORM -->
    <h5 class="mb-3 border-bottom pb-2">Homeowner Information</h5>
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label class="form-label">First Name</label>
        <input type="text" name="first_name" class="form-control" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Middle Name</label>
        <input type="text" name="middle_name" class="form-control">
      </div>
      <div class="col-md-4">
        <label class="form-label">Last Name</label>
        <input type="text" name="last_name" class="form-control" required>
      </div>
    </div>

    <!-- CONTACT, EMAIL, PASSWORD, PHASE, LOT NUMBER -->
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">Contact Number</label>
        <input type="tel" name="contact_number" class="form-control" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Email Address</label>
        <input type="email" name="email" class="form-control" required>
      </div>
    </div>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" minlength="8" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Confirm Password</label>
        <input type="password" name="confirm_password" class="form-control" minlength="8" required>
      </div>
    </div>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">Phase</label>
        <select name="phase" class="form-control" required>
          <option disabled selected>Select Phase</option>
          <option>Phase 1</option>
          <option>Phase 2</option>
          <option>Phase 3</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">House / Lot Number</label>
        <input type="text" name="house_lot_number" class="form-control" required>
      </div>
    </div>

    <!-- FILE UPLOADS -->
    <h5 class="mt-5 mb-3 border-bottom pb-2">Required Documents</h5>
    <div class="row g-4 mb-4">
      <div class="col-md-6">
        <label class="form-label">Valid ID</label>
        <input type="file" name="valid_id" class="form-control" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Proof of Billing</label>
        <input type="file" name="proof_of_billing" class="form-control" required>
      </div>
    </div>

    <!-- HOUSEHOLD MEMBERS -->
    <h5 class="mt-5 mb-3 border-bottom pb-2">Household Members</h5>
    <div id="members">
      <div class="member border rounded p-3 mb-4">
        <div class="row g-3 align-items-end">
          <div class="col-md-4"><input type="text" name="member_first_name[]" class="form-control" placeholder="First Name" required></div>
          <div class="col-md-3"><input type="text" name="member_middle_name[]" class="form-control" placeholder="Middle Name"></div>
          <div class="col-md-3"><input type="text" name="member_last_name[]" class="form-control" placeholder="Last Name" required></div>
          <div class="col-md-2">
            <select name="member_relation[]" class="form-control" required>
              <option disabled selected>Relation</option>
              <option>Homeowner</option>
              <option>Spouse</option>
              <option>Child</option>
              <option>Parent</option>
              <option>Relative</option>
              <option>Tenant</option>
              <option>Caretaker</option>
            </select>
          </div>
        </div>
      </div>
    </div>
    <button type="button" class="btn btn-outline-success mb-4" onclick="addMember()">+ Add Member</button>

    <div class="d-flex justify-content-end">
      <button type="submit" name="registration_submit" class="btn btn-success px-4">Next: Pin Location</button>
    </div>
</form>
<script src="./leaflet/dist/leaflet.js"></script>
<?php else: ?>
<!-- SHOW MAP AFTER FORM SUBMITTED -->
<form method="POST" enctype="multipart/form-data">
  <h5 class="mb-3 border-bottom pb-2 text-success">Pin Homeowner Location</h5>

  <?php
  // carry over all previous inputs as hidden
  foreach($_POST as $key => $value){
      if(is_array($value)){
          foreach($value as $v){
              echo '<input type="hidden" name="'.$key.'[]" value="'.$v.'">';
          }
      } else {
          echo '<input type="hidden" name="'.$key.'" value="'.$value.'">';
      }
  }
  ?>
  <input type="hidden" name="latitude" id="latitude">
  <input type="hidden" name="longitude" id="longitude">

  <div id="map" style="height:500px; border:2px solid #077f46; border-radius:12px;"></div>
  <button type="submit" name="submit_location" class="btn btn-success w-100 mt-3">Submit Registration</button>
</form>

<script>
let map, marker;

function initRegistrationMapOnce() {
  // Only run if the map exists in DOM (map step)
  const mapDiv = document.getElementById('map');
  if (!mapDiv) return;

  if (map) {
    map.invalidateSize();
    return;
  }

  map = L.map('map').setView([14.3545, 120.946], 16);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  const allowedArea = L.polygon([
    [14.357391, 120.943993],
    [14.351903, 120.944937],
    [14.352257, 120.948118],
    [14.357828, 120.947329]
  ], { color: 'green' }).addTo(map);

  map.fitBounds(allowedArea.getBounds());

  const center = allowedArea.getBounds().getCenter();
  marker = L.marker(center, { draggable: true }).addTo(map);

  marker.on('dragend', function (e) {
    const pos = e.target.getLatLng();
    document.getElementById('latitude').value = pos.lat;
    document.getElementById('longitude').value = pos.lng;
  });

  document.getElementById('latitude').value = center.lat;
  document.getElementById('longitude').value = center.lng;

  setTimeout(() => map.invalidateSize(), 200);
}

// init on load
document.addEventListener('DOMContentLoaded', initRegistrationMapOnce);
</script>


<?php endif; ?>
</div>

 <!-- 3️⃣ Approved Households Table -->
    <div id="approved" style="<?= $view==='approved' ? '' : 'display:none;' ?>">
        <?php
        // Fetch approved homeowners
        if ($admin_role == 'superadmin') {
            $sqlApproved = $conn->prepare("SELECT * FROM homeowners WHERE status='approved'");
        } else {
            $sqlApproved = $conn->prepare("SELECT * FROM homeowners WHERE status='approved' AND phase=?");
            $sqlApproved->bind_param("s", $admin_phase);
        }
        $sqlApproved->execute();
        $resultApproved = $sqlApproved->get_result();
        ?>
        <table id="approvedTable" class="display table table-striped table-bordered nowrap" style="width:100%">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $resultApproved->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= $row['first_name'] . ' ' . ($row['middle_name'] ?: '') . ' ' . $row['last_name'] ?></td>
                        <td><?= $row['phase'] . ', ' . $row['house_lot_number'] ?></td>
                        <td>
                            <span class="badge badge-success"><?= ucfirst($row['status']) ?></span>
                        </td>
                        <td>
							<button type="button" class="btn btn-sm btn-info viewHomeownerBtn" data-id="<?= (int)$row['id'] ?>" title="View">
								<i class="dw dw-eye"></i>
							</button>
                            <button class="btn btn-sm btn-warning editHomeowner" data-id="<?= $row['id'] ?>" title="Edit">
                                <i class="dw dw-edit-1"></i> Edit
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
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

<!-- ================= ACTION CONFIRM MODAL ================= -->
<div class="modal fade" id="actionConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:14px; overflow:hidden;">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="actionConfirmTitle">Confirm Action</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <p class="mb-3" id="actionConfirmText">Are you sure?</p>

        <!-- Only shown for rejection -->
        <div id="rejectReasonWrap" style="display:none;">
          <label class="form-label fw-semibold">Rejection reason</label>
          <textarea id="rejectReasonInput" class="form-control" rows="3" placeholder="Type the reason..."></textarea>
          <div class="form-text text-danger mt-1" id="rejectReasonError" style="display:none;">
            Rejection reason is required.
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="actionConfirmBtn">
          Confirm
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ================= EDIT HOMEOWNER MODAL (AJAX) ================= -->
<div class="modal fade" id="editHomeownerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:14px; overflow:hidden;">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Edit Homeowner</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body" style="background:#f4f6fb;">
        <div id="editHomeownerContent" class="p-2"></div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-success" id="saveEditHomeownerBtn">
          Save Changes
        </button>
      </div>
    </div>
  </div>
</div>


<!-- ================= TOAST NOTIFICATION ================= -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 2000;">
  <div id="appToast" class="toast align-items-center" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="appToastMsg">...</div>
      <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>
	<!-- ================= VIEW HOMEOWNER MODAL (AJAX) ================= -->
	<div class="modal fade" id="viewHomeownerModal" tabindex="-1" aria-hidden="true">
	  <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content" style="border-radius: 14px; overflow: hidden;">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Homeowner Profile</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                            <div class="modal-body p-0 position-relative" style="background:#f4f6fb; min-height: 80vh;">
                            <div id="viewHomeownerContent">
                                
                        </div>
                </div>
            </div>
	  </div>
	</div>


	<!-- ================= CORE DEPENDENCIES ================= -->
	<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

	<!-- Vendor scripts -->
	<script src="vendors/scripts/core.js"></script>
	<script src="vendors/scripts/script.min.js"></script>
	<script src="vendors/scripts/process.js"></script>
	<script src="vendors/scripts/layout-settings.js"></script>

	<!-- Charts -->
	<script src="src/plugins/apexcharts/apexcharts.min.js"></script>

	<!-- DataTables -->
	<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
	<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
	<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
	<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

	<!-- Page -->
	<script src="vendors/scripts/dashboard.js"></script>

	<!-- Bootstrap bundle -->
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

	<script>
	// Add household member
	function addMember() {
	  const members = document.getElementById('members');
	  const member = members.firstElementChild.cloneNode(true);
	  member.querySelectorAll('input').forEach(input => input.value = '');
	  member.querySelector('select').selectedIndex = 0;
	  members.appendChild(member);
	}
	window.addMember = addMember;

	$(function () {

// ---------- Professional toast helper ----------
function showToast(message, type = 'success') {
  const toastEl = document.getElementById('appToast');
  const msgEl   = document.getElementById('appToastMsg');

  msgEl.textContent = message;

  // reset classes
  toastEl.classList.remove('text-bg-success','text-bg-danger','text-bg-warning','text-bg-info','text-bg-dark');
  if (type === 'success') toastEl.classList.add('text-bg-success');
  else if (type === 'error') toastEl.classList.add('text-bg-danger');
  else if (type === 'warning') toastEl.classList.add('text-bg-warning');
  else toastEl.classList.add('text-bg-dark');

  bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 2800 }).show();
}

// ---------- Approve/Reject using modal ----------
const confirmModalEl = document.getElementById('actionConfirmModal');
const confirmTitleEl = document.getElementById('actionConfirmTitle');
const confirmTextEl  = document.getElementById('actionConfirmText');
const confirmBtnEl   = document.getElementById('actionConfirmBtn');
const reasonWrapEl   = document.getElementById('rejectReasonWrap');
const reasonInputEl  = document.getElementById('rejectReasonInput');
const reasonErrorEl  = document.getElementById('rejectReasonError');

let pendingAction = { id: null, status: null };

if (confirmModalEl) {
  const confirmModal = new bootstrap.Modal(confirmModalEl, { backdrop: 'static', keyboard: false });

  // open modal on click
  $(document).on('click', '.approveHomeowner, .rejectHomeowner', function (e) {
    e.preventDefault();

    const id = $(this).data('id');
    const status = $(this).hasClass('approveHomeowner') ? 'approved' : 'rejected';
    if (!id) return;

    pendingAction = { id, status };

    // configure UI per action
    if (status === 'approved') {
      confirmTitleEl.textContent = 'Approve Homeowner';
      confirmTextEl.textContent  = 'This will approve the homeowner and notify them via email. Continue?';
      confirmBtnEl.classList.remove('btn-danger');
      confirmBtnEl.classList.add('btn-success');
      confirmBtnEl.textContent = 'Approve';
      reasonWrapEl.style.display = 'none';
      reasonErrorEl.style.display = 'none';
      reasonInputEl.value = '';
    } else {
      confirmTitleEl.textContent = 'Reject Homeowner';
      confirmTextEl.textContent  = 'Please provide a rejection reason. This will be sent to the homeowner.';
      confirmBtnEl.classList.remove('btn-success');
      confirmBtnEl.classList.add('btn-danger');
      confirmBtnEl.textContent = 'Reject';
      reasonWrapEl.style.display = 'block';
      reasonErrorEl.style.display = 'none';
      reasonInputEl.value = '';
      setTimeout(() => reasonInputEl.focus(), 250);
    }

    confirmModal.show();
  });

  // confirm action
  confirmBtnEl.addEventListener('click', function () {
    const { id, status } = pendingAction;
    if (!id || !status) return;

    let reason = '';
    if (status === 'rejected') {
      reason = (reasonInputEl.value || '').trim();
      if (!reason) {
        reasonErrorEl.style.display = 'block';
        reasonInputEl.focus();
        return;
      }
    }

    // disable button while sending
    confirmBtnEl.disabled = true;
    confirmBtnEl.textContent = (status === 'approved') ? 'Approving...' : 'Rejecting...';

    $.post('update_homeowner_status_email.php', { id, status, reason }, function (res) {
      if (!res || !res.success) {
        showToast(res?.message || 'Action failed.', 'error');
        confirmBtnEl.disabled = false;
        confirmBtnEl.textContent = (status === 'approved') ? 'Approve' : 'Reject';
        return;
      }

      showToast(res.message || 'Updated successfully.', 'success');
      confirmModal.hide();

      // reload to refresh tables/status
      setTimeout(() => location.reload(), 600);
    }, 'json')
    .fail(function (xhr) {
      console.error(xhr.responseText);
      showToast('Request failed. Please try again.', 'error');
      confirmBtnEl.disabled = false;
      confirmBtnEl.textContent = (status === 'approved') ? 'Approve' : 'Reject';
    });
  });

  // reset modal state after close
  confirmModalEl.addEventListener('hidden.bs.modal', function () {
    pendingAction = { id: null, status: null };
    confirmBtnEl.disabled = false;
    confirmBtnEl.textContent = 'Confirm';
    reasonErrorEl.style.display = 'none';
    reasonInputEl.value = '';
  });
}
	  // DataTables init safe
            if ($.fn.DataTable) {
            if ($('#approvalTable').length && !$.fn.DataTable.isDataTable('#approvalTable')) {
                $('#approvalTable').DataTable({ responsive: true, columnDefs: [{ orderable:false, targets:4 }] });
            }
            if ($('#approvedTable').length && !$.fn.DataTable.isDataTable('#approvedTable')) {
                $('#approvedTable').DataTable({ responsive: true, columnDefs: [{ orderable:false, targets:4 }] });
            }
            }

// Modal View Homeowner (AJAX)
const modalEl = document.getElementById('viewHomeownerModal');
const content = document.getElementById('viewHomeownerContent');

if (modalEl && content) {
  const modal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: true });
  let coverMapInstance = null;

  function initCoverMapIfAny() {
    const mapEl = document.getElementById('coverMap');
    if (!mapEl) return;

    const lat = parseFloat(mapEl.dataset.lat || '');
    const lng = parseFloat(mapEl.dataset.lng || '');
    if (!isFinite(lat) || !isFinite(lng)) return;

    if (coverMapInstance) { coverMapInstance.remove(); coverMapInstance = null; }

    coverMapInstance = L.map('coverMap', {
      zoomControl: false,
      dragging: true,
      scrollWheelZoom: true,
      doubleClickZoom: true,
      boxZoom: false,
      keyboard: false,
      tap: false
    }).setView([lat, lng], 18);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(coverMapInstance);

    L.marker([lat, lng]).addTo(coverMapInstance);
    setTimeout(() => coverMapInstance.invalidateSize(), 250);
  }

  $(document).on('click', '.viewHomeownerBtn', function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    if (!id) return;

    // Optional: show a very small inline "loading" inside content (no overlay)
    content.innerHTML = `<div class="p-4 text-muted fw-semibold">Loading...</div>`;
    modal.show();

    $.get('HO-management.php', { ajax: 'homeowner_profile', id: id, _: Date.now() })
      .done(function (html) {
        content.innerHTML = html;
        initCoverMapIfAny();
      })
      .fail(function (xhr) {
        content.innerHTML =
          `<div class="p-4">
             <div class="alert alert-danger mb-0">
               Failed to load profile. HTTP ${xhr.status}
             </div>
           </div>`;
      });
  });
// ================= EDIT HOMEOWNER (AJAX MODAL + MAP) =================
const editModalEl = document.getElementById('editHomeownerModal');
const editContent = document.getElementById('editHomeownerContent');
const saveEditBtn = document.getElementById('saveEditHomeownerBtn');

let editModal = null;
let editMapInstance = null;
let editMarker = null;

function destroyEditMap(){
  if (editMapInstance) { editMapInstance.remove(); editMapInstance = null; }
  editMarker = null;
}

function initEditMap(){
  const mapEl = document.getElementById('editMap');
  if (!mapEl) return;

  let lat = parseFloat(mapEl.dataset.lat || '');
  let lng = parseFloat(mapEl.dataset.lng || '');

  // fallback if empty
  if (!isFinite(lat) || !isFinite(lng)) {
    lat = 14.3545;
    lng = 120.946;
  }

  destroyEditMap();

  editMapInstance = L.map('editMap').setView([lat, lng], 17);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(editMapInstance);

  // allowed area (same polygon you use)
  const allowedArea = L.polygon([
    [14.357391, 120.943993],
    [14.351903, 120.944937],
    [14.352257, 120.948118],
    [14.357828, 120.947329]
  ], { color: 'green' }).addTo(editMapInstance);

  editMapInstance.fitBounds(allowedArea.getBounds());

  // marker start
  editMarker = L.marker([lat, lng], { draggable:true }).addTo(editMapInstance);

  function setHidden(pos){
    document.getElementById('edit_lat').value = pos.lat;
    document.getElementById('edit_lng').value = pos.lng;
  }
  setHidden(editMarker.getLatLng());

  editMarker.on('dragend', function(e){
    setHidden(e.target.getLatLng());
  });

  // buttons inside modal
  const btnCenter = document.getElementById('btnCenterMarker');
  const btnUsePos = document.getElementById('btnUseCurrentMarker');

  if (btnCenter) {
    btnCenter.addEventListener('click', function(){
      if (!allowedArea) return;
      const c = allowedArea.getBounds().getCenter();
      editMarker.setLatLng(c);
      editMapInstance.panTo(c);
      setHidden(c);
    });
  }
  if (btnUsePos) {
    btnUsePos.addEventListener('click', function(){
      setHidden(editMarker.getLatLng());
    });
  }

  setTimeout(() => editMapInstance.invalidateSize(), 250);
}

// add/remove member rows in edit modal
function bindEditMemberUI(){
  const addBtn = document.getElementById('addEditMemberBtn');
  const wrap = document.getElementById('editMembersWrap');
  const tpl = document.getElementById('editMemberTpl');

  if (addBtn && wrap && tpl) {
    addBtn.addEventListener('click', function(){
      wrap.insertAdjacentHTML('beforeend', tpl.innerHTML);
    });
  }

  // remove
  $(document).off('click', '.removeMemberBtn').on('click', '.removeMemberBtn', function(){
    $(this).closest('.memberRow').remove();
  });
}

if (editModalEl && editContent) {
  editModal = new bootstrap.Modal(editModalEl, { backdrop:'static', keyboard:true });

  // open edit modal
  $(document).on('click', '.editHomeowner', function(e){
    e.preventDefault();
    const id = $(this).data('id');
    if (!id) return;

    editContent.innerHTML = `<div class="p-3 text-muted fw-semibold">Loading edit form...</div>`;
    editModal.show();

    $.get('edit_homeowner_modal.php', { ajax:'edit_homeowner', id:id, _:Date.now() })
      .done(function(html){
        editContent.innerHTML = html;
        bindEditMemberUI();
        initEditMap();
      })
      .fail(function(xhr){
        editContent.innerHTML = `<div class="alert alert-danger">Failed to load. HTTP ${xhr.status}</div>`;
      });
  });

  // save
  if (saveEditBtn) {
    saveEditBtn.addEventListener('click', function(){
      const form = document.getElementById('editHomeownerForm');
      if (!form) return;

      // make sure lat/lng are captured
      if (editMarker) {
        const pos = editMarker.getLatLng();
        document.getElementById('edit_lat').value = pos.lat;
        document.getElementById('edit_lng').value = pos.lng;
      }

      const fd = new FormData(form);

      saveEditBtn.disabled = true;
      const old = saveEditBtn.textContent;
      saveEditBtn.textContent = "Saving...";

      fetch('edit_homeowner_modal.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(res => {
          if (!res.success) {
            showToast(res.message || "Update failed.", "error");
            return;
          }
          showToast(res.message || "Updated.", "success");
          editModal.hide();
          setTimeout(() => location.reload(), 400);
        })
        .catch(err => {
          console.error(err);
          showToast("Request failed. Try again.", "error");
        })
        .finally(() => {
          saveEditBtn.disabled = false;
          saveEditBtn.textContent = old;
        });
    });
  }

  // cleanup
  editModalEl.addEventListener('hidden.bs.modal', function(){
    destroyEditMap();
    editContent.innerHTML = '';
  });
}


  modalEl.addEventListener('hidden.bs.modal', function () {
    if (coverMapInstance) { coverMapInstance.remove(); coverMapInstance = null; }
    content.innerHTML = '';
  });
}

	});
	</script>

</body>
</html>
