<?php
session_start();

// ===================== SESSION CHECK =====================
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin')) {
    echo "<script>alert('Access denied.'); window.location='index.html';</script>";
    exit();
}

// ===================== DB CONNECTION =====================
$host = "localhost";
$db   = "south_meridian_hoa"; // replace with your DB name
$user = "root";
$pass = ""; // replace with your DB password
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// Handle final submission including latitude & longitude
if(isset($_POST['submit_location'])) {
    // Homeowner basic info
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
    $stmtHome->bind_param("sssssssssssdis", 
        $first_name, $middle_name, $last_name, $contact_number, $email, $password, $phase, $house_lot_number,
        $valid_id_path, $proof_path, $latitude, $longitude, $admin_id, $status
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

        <li>
         
              <a href="HO-management.php" class="dropdown-toggle no-arrow">
                <span class="micon dw dw-user"></span>
                <span class="mtext">Homeowner Management</span>
            </a>
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
    <!-- Activity Graph -->
    <div class="col-xl-12 mb-30">
        <div class="card-box height-100-p pd-20">
            <h2 class="h4 mb-20">Home Owner Management</h2>
             <!-- Tabs -->
				<ul class="nav nav-tabs mb-4" id="mainTabs" role="tablist">
					<li class="nav-item" role="presentation">
						<button class="nav-link active" id="approval-tab" data-bs-toggle="tab" data-bs-target="#approval" type="button" role="tab">
							Household Approval
						</button>
					</li>
					<li class="nav-item" role="presentation">
						<button class="nav-link" id="registration-tab" data-bs-toggle="tab" data-bs-target="#registration" type="button" role="tab">
							Register Household
						</button>
					</li>
					<li class="nav-item" role="presentation">
						<button class="nav-link" id="approved-tab" data-bs-toggle="tab" data-bs-target="#approved" type="button" role="tab">
							Approved Households
						</button>
					</li>
				</ul>

 <!-- Tab Contents -->
					<div class="tab-content" id="mainTabsContent">
    <!-- 1️⃣ Household Approval Table -->
    <div class="tab-pane fade show active" id="approval" role="tabpanel">
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
                            
                            <a class="btn btn-sm btn-info" href="view_homeowner.php?id=<?= $row['id'] ?>" title="View">
                            <i class="dw dw-eye"></i>
                            </a>
                           
                            
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <!-- Registration Form -->
<div class="tab-pane fade" id="registration" role="tabpanel">
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

document.getElementById('registration-tab')
  .addEventListener('shown.bs.tab', function () {

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
});
</script>

<?php endif; ?>
</div>

 <!-- 3️⃣ Approved Households Table -->
    <div class="tab-pane fade" id="approved" role="tabpanel">
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
                            <a class="btn btn-sm btn-info" href="view_homeowner.php?id=<?= $row['id'] ?>" title="View">
                            <i class="dw dw-eye"></i>
                            </a>
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
<!-- ================= CORE DEPENDENCIES ================= -->

<!-- jQuery (MUST be first) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>


<!-- ================= VENDOR / TEMPLATE SCRIPTS ================= -->

<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>

<!-- ================= CHARTS ================= -->

<script src="src/plugins/apexcharts/apexcharts.min.js"></script>

<!-- ================= DATATABLES ================= -->

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>


<!-- ================= PAGE-SPECIFIC ================= -->

<script src="vendors/scripts/dashboard.js"></script>
<script>
$(function () {
    // Single handler for Approve and Reject buttons
$(document).on('click', '.approveHomeowner, .rejectHomeowner', function (e) {
    e.preventDefault();

    const id = $(this).data('id');
    const status = $(this).hasClass('approveHomeowner') ? 'approved' : 'rejected';
    let reason = '';

    if (status === 'rejected') {
        reason = prompt("Enter rejection reason:");
        if (reason === null) return;
        reason = reason.trim();
        if (!reason) {
            alert("Rejection reason is required.");
            return;
        }
    }

    if (!confirm(`Are you sure you want to ${status} this homeowner?`)) return;

    $.post('update_homeowner_status_email.php', {
        id: id,
        status: status,
        reason: reason
    }, function (res) {
        if (!res.success) {
            alert(res.message);
            return;
        }
        alert(res.message);
        location.reload();
    }, 'json').fail(function (xhr) {
        console.error(xhr.responseText);
        alert('Request failed');
    });
});
});




    // Optional: initialize DataTables
    $('#approvalTable, #approvedTable').DataTable({
        responsive: true,
        columnDefs: [{ orderable: false, targets: 4 }]
    });

    // Add household member
    function addMember() {
        const members = document.getElementById('members');
        const member = members.firstElementChild.cloneNode(true);
        member.querySelectorAll('input').forEach(input => input.value = '');
        member.querySelector('select').selectedIndex = 0;
        member.querySelector('.removeMember').classList.remove('d-none');
        members.appendChild(member);
    }

    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('removeMember')) {
            e.target.closest('.member').remove();
        }
    });

    // expose addMember globally
    window.addMember = addMember;



</script>
  </div>
</div>

<?php if ($showMap): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const registrationTab = document.querySelector('#registration-tab');
    if (registrationTab) {
        const tab = new bootstrap.Tab(registrationTab);
        tab.show();
    }
});
</script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

	
</body>
</html>