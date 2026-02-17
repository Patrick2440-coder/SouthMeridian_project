<?php
session_start();

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
  exit();
}

$host="localhost"; $db="south_meridian_hoa"; $user="root"; $pass="";
$conn = new mysqli($host,$user,$pass,$db);
if ($conn->connect_error) die("Connection failed: ".$conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ✅ prevent undefined $view in sidebar
$view = $_GET['view'] ?? '';

function phase_prefix(string $phase): string {
  $n = (int) filter_var($phase, FILTER_SANITIZE_NUMBER_INT);
  return $n > 0 ? ('P'.$n) : 'P';
}

$admin_id = (int)$_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT phase, role FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

$admin_phase = $admin['phase'] ?? '';
$admin_role  = $admin['role'] ?? '';

if ($admin_role === 'superadmin') {
  $sqlApproved = $conn->prepare("SELECT * FROM homeowners WHERE status='approved' ORDER BY created_at DESC");
} else {
  $sqlApproved = $conn->prepare("SELECT * FROM homeowners WHERE status='approved' AND phase=? ORDER BY created_at DESC");
  $sqlApproved->bind_param("s", $admin_phase);
}
$sqlApproved->execute();
$resultApproved = $sqlApproved->get_result();
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

	<style>
		:root{--brand:#077f46;}
		.badge{padding:.25em .55em;border-radius:.45rem;color:#fff;font-size:.82rem;font-weight:700}
		.badge-success{background:#22c55e}
		.page-title-wrap{display:flex;align-items:center;justify-content:center;text-align:center;margin-bottom:14px}
		.page-title-wrap .subtitle{font-size:14px}
		.card-box{border-radius:14px}
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
								<li>
									<a href="#">
										<img src="vendors/images/img.jpg" alt="">
										<h3>System</h3>
										<p>Notifications appear here.</p>
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
						<span class="user-name"><?= esc(strtoupper($admin_role)) ?></span>
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

  <div class="left-side-bar" style="background-color: #077f46;">
    <div class="brand-logo">
      <a href="dashboard.php">
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

					<li class="dropdown show">
						<a href="javascript:;" class="dropdown-toggle active ">
							<span class="micon dw dw-user"></span>
							<span class="mtext">Homeowner Management</span>
						</a>
						<ul class="submenu">
							<li><a href="ho_approval.php">Household Approval</a></li>
							<li><a href="ho_register.php">Register Household</a></li>
							<li><a href="ho_approved.php">Approved Households</a></li>
						</ul>
					</li>

					<li class="dropdown">
						<a href="javascript:;" class="dropdown-toggle <?= ($view==='homeowners' || $view==='officers') ? 'active' : '' ?>">
							<span class="micon dw dw-user"></span>
							<span class="mtext">User Management</span>
						</a>
						<ul class="submenu">
							<li><a href="users-management.php?view=homeowners" class="<?= $view==='homeowners' ? 'active' : '' ?>">Homeowners</a></li>
							<li><a href="users-management.php?view=officers" class="<?= $view==='officers' ? 'active' : '' ?>">Officers</a></li>
						</ul>
					</li>

          <li><a href="announcements.php" class="dropdown-toggle no-arrow"><span class="micon dw dw-megaphone"></span><span class="mtext">Announcement</span></a></li>

          <li class="dropdown">
            <a href="javascript:;" class="dropdown-toggle"><span class="micon dw dw-money-1"></span><span class="mtext">Finance</span></a>
            <ul class="submenu">
              <li><a href="finance.php">Overview</a></li>
              <li><a href="finance_dues.php">Monthly Dues</a></li>
              <li><a href="finance_donations.php">Donations</a></li>
              <li><a href="finance_expenses.php">Expenses</a></li>
              <li><a href="finance_reports.php">Financial Reports</a></li>
              <li><a href="finance_cashflow.php">Cash Flow Dashboard</a></li>
            </ul>
          </li>

          <li><a href="#" class="dropdown-toggle no-arrow"><span class="micon dw dw-settings2"></span><span class="mtext">Settings</span></a></li>
        </ul>
      </div>
    </div>
  </div>

	<div class="mobile-menu-overlay"></div>

	<div class="main-container">
		<div class="pd-ltr-20">

			<div class="page-title-wrap">
				<div>
					<h2 class="h4 mb-1">Home Owner Management</h2>
					<div class="text-muted fw-semibold subtitle">Approved Households</div>
				</div>
			</div>

			<div class="card-box p-3">
				<div class="table-responsive">
					<table id="approvedTable" class="display table table-striped table-bordered nowrap" style="width:100%">
						<thead>
							<tr>
								<th>ID</th>
								<th>Name</th>
								<th>Address</th>
								<th>Status</th>
								<th style="width:170px;">Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php while($row = $resultApproved->fetch_assoc()): ?>
								<?php
									$rowPhase = (string)($row['phase'] ?? $admin_phase);
									$prefix = phase_prefix($rowPhase);
									$displayId = $prefix . (int)$row['id']; // ✅ SAME FORMAT AS USER MANAGEMENT
								?>
								<tr>
									<td><span class="badge badge-success"><?= esc($displayId) ?></span></td>
									<td><?= esc(trim(($row['first_name'] ?? '').' '.($row['middle_name'] ?? '').' '.($row['last_name'] ?? ''))) ?></td>
									<td><?= esc(trim(($row['phase'] ?? '').', '.($row['house_lot_number'] ?? ''))) ?></td>
									<td><span class="badge badge-success">Approved</span></td>
									<td>
										<button type="button" class="btn btn-sm btn-info viewHomeownerBtn" data-id="<?= (int)$row['id'] ?>" title="View">
											<i class="dw dw-eye"></i>
										</button>
										<button class="btn btn-sm btn-warning editHomeowner" data-id="<?= (int)$row['id'] ?>" title="Edit">
											<i class="dw dw-edit-1"></i>
										</button>
									</td>
								</tr>
							<?php endwhile; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="footer-wrap pd-20 mb-20 card-box">
				© Copyright South Meridian Homes All Rights Reserved
			</div>
		</div>
	</div>

	<!-- VIEW MODAL -->
	<div class="modal fade" id="viewHomeownerModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-xl modal-dialog-scrollable">
			<div class="modal-content" style="border-radius: 14px; overflow: hidden;">
				<div class="modal-header">
					<h5 class="modal-title fw-bold">Homeowner Profile</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<div class="modal-body p-0 position-relative" style="background:#f4f6fb; min-height: 80vh;">
					<div id="viewHomeownerContent"></div>
				</div>
			</div>
		</div>
	</div>

	<!-- EDIT MODAL -->
	<div class="modal fade" id="editHomeownerModal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-xl modal-dialog-scrollable">
			<div class="modal-content" style="border-radius:14px; overflow:hidden;">
				<div class="modal-header">
					<h5 class="modal-title fw-bold">Edit Homeowner</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>

				<div class="modal-body" style="background:#f4f6fb;">
					<div id="editHomeownerContent" class="p-2"></div>
				</div>

				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
					<button type="button" class="btn btn-success" id="saveEditHomeownerBtn">Save Changes</button>
				</div>
			</div>
		</div>
	</div>

	<!-- TOAST -->
	<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 2000;">
		<div id="appToast" class="toast align-items-center" role="alert" aria-live="assertive" aria-atomic="true">
			<div class="d-flex">
				<div class="toast-body" id="appToastMsg">...</div>
				<button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
			</div>
		</div>
	</div>

	<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

	<script src="vendors/scripts/core.js"></script>
	<script src="vendors/scripts/script.min.js"></script>
	<script src="vendors/scripts/process.js"></script>
	<script src="vendors/scripts/layout-settings.js"></script>

	<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
	<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
	<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
	<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

	<script>
		function showToast(message, type='success'){
			const toastEl = document.getElementById('appToast');
			const msgEl   = document.getElementById('appToastMsg');
			msgEl.textContent = message;

			toastEl.classList.remove('text-bg-success','text-bg-danger');
			toastEl.classList.add(type==='success' ? 'text-bg-success' : 'text-bg-danger');

			bootstrap.Toast.getOrCreateInstance(toastEl,{delay:2800}).show();
		}

		$(function(){
			if ($.fn.DataTable && $('#approvedTable').length && !$.fn.DataTable.isDataTable('#approvedTable')) {
				$('#approvedTable').DataTable({ responsive:true, columnDefs:[{orderable:false, targets:4}] });
			}

			// VIEW modal
			const modalEl = document.getElementById('viewHomeownerModal');
			const content = document.getElementById('viewHomeownerContent');
			const modal = new bootstrap.Modal(modalEl, { backdrop:'static', keyboard:true });

			let coverMapInstance = null;

			function initCoverMapIfAny(){
				const mapEl = document.getElementById('coverMap');
				if (!mapEl) return;

				const lat = parseFloat(mapEl.dataset.lat||'');
				const lng = parseFloat(mapEl.dataset.lng||'');
				if (!isFinite(lat) || !isFinite(lng)) return;

				if (coverMapInstance) { coverMapInstance.remove(); coverMapInstance = null; }

				coverMapInstance = L.map('coverMap', { zoomControl:false }).setView([lat,lng],18);
				L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution:'&copy; OpenStreetMap contributors' }).addTo(coverMapInstance);
				L.marker([lat,lng]).addTo(coverMapInstance);
				setTimeout(()=>coverMapInstance.invalidateSize(), 250);
			}

			$(document).on('click','.viewHomeownerBtn', function(e){
				e.preventDefault();
				const id = $(this).data('id');
				if (!id) return;

				content.innerHTML = `<div class="p-4 text-muted fw-semibold">Loading...</div>`;
				modal.show();

				$.get('HO-management.php', { ajax:'homeowner_profile', id:id, _:Date.now() })
					.done(function(html){ content.innerHTML = html; initCoverMapIfAny(); })
					.fail(function(xhr){
						content.innerHTML = `<div class="p-4"><div class="alert alert-danger mb-0">Failed to load profile. HTTP ${xhr.status}</div></div>`;
					});
			});

			modalEl.addEventListener('hidden.bs.modal', function(){
				if (coverMapInstance) { coverMapInstance.remove(); coverMapInstance = null; }
				content.innerHTML = '';
			});

			// EDIT modal load + map init (kept your working version)
			const editModalEl = document.getElementById('editHomeownerModal');
			const editContent = document.getElementById('editHomeownerContent');
			const editModal = new bootstrap.Modal(editModalEl, { backdrop:'static', keyboard:true });

			let editMapInstance = null;
			let editMarker = null;
			let pendingInit = false;

			function destroyEditMap(){
				if (editMapInstance) {
					editMapInstance.remove();
					editMapInstance = null;
					editMarker = null;
				}
			}

			function initEditMap(){
				const mapEl = document.getElementById('editMap');
				if (!mapEl) return;

				let lat = parseFloat(mapEl.dataset.lat || '');
				let lng = parseFloat(mapEl.dataset.lng || '');
				if (!isFinite(lat) || !isFinite(lng)) { lat = 14.5995; lng = 120.9842; }

				destroyEditMap();

				editMapInstance = L.map(mapEl, { zoomControl:true }).setView([lat, lng], 18);
				L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
					attribution: '&copy; OpenStreetMap contributors'
				}).addTo(editMapInstance);

				editMarker = L.marker([lat, lng], { draggable:true }).addTo(editMapInstance);

				function syncInputs(p){
					$('#edit_lat').val(p.lat.toFixed(6));
					$('#edit_lng').val(p.lng.toFixed(6));
				}

				syncInputs({lat, lng});
				editMarker.on('dragend', function(){ syncInputs(editMarker.getLatLng()); });

				$(document).off('click', '#btnCenterMarker').on('click', '#btnCenterMarker', function(){
					if (!editMapInstance || !editMarker) return;
					editMapInstance.setView(editMarker.getLatLng(), editMapInstance.getZoom());
				});

				$(document).off('click', '#btnUseCurrentMarker').on('click', '#btnUseCurrentMarker', function(){
					if (!editMarker) return;
					syncInputs(editMarker.getLatLng());
				});

				setTimeout(() => { if (editMapInstance) editMapInstance.invalidateSize(true); }, 250);
			}

			$(document).on('click','.editHomeowner', function(e){
				e.preventDefault();
				const id = $(this).data('id');
				if (!id) return;

				pendingInit = true;
				editContent.innerHTML = `<div class="p-3 text-muted fw-semibold">Loading edit form...</div>`;
				editModal.show();

				$.get('edit_homeowner_modal.php', { ajax:'edit_homeowner', id:id, _:Date.now() })
					.done(function(html){
						editContent.innerHTML = html;
						if (editModalEl.classList.contains('show')) {
							initEditMap();
							pendingInit = false;
						}
					})
					.fail(function(xhr){
						pendingInit = false;
						editContent.innerHTML = `<div class="alert alert-danger">Failed to load. HTTP ${xhr.status}</div>`;
					});
			});

			editModalEl.addEventListener('shown.bs.modal', function(){
				if (pendingInit) {
					initEditMap();
					pendingInit = false;
				}
			});

			editModalEl.addEventListener('hidden.bs.modal', function(){
				destroyEditMap();
				editContent.innerHTML = '';
				pendingInit = false;
			});

			// ✅ SAVE CHANGES
			document.addEventListener('click', async function(e){
				if (!e.target.closest('#saveEditHomeownerBtn')) return;

				const btn = e.target.closest('#saveEditHomeownerBtn');
				const form = document.getElementById('editHomeownerForm');
				if (!form) { showToast("Edit form not found.", "error"); return; }

				const fd = new FormData(form);

				btn.disabled = true;
				const oldHTML = btn.innerHTML;
				btn.innerHTML = 'Saving...';

				try {
					const resp = await fetch('edit_homeowner_modal.php', { method: 'POST', body: fd });
					const text = await resp.text();

					let data;
					try { data = JSON.parse(text); }
					catch(err){
						console.error("Not JSON response:", text);
						showToast("Save failed. Server returned non-JSON.", "error");
						return;
					}

					if (!data.success) { showToast(data.message || "Update failed.", "error"); return; }

					showToast(data.message || "Updated!", "success");
					editModal.hide();
					setTimeout(()=>location.reload(), 400);

				} catch (err) {
					console.error(err);
					showToast("Request failed.", "error");
				} finally {
					btn.disabled = false;
					btn.innerHTML = oldHTML;
				}
			});
		});
	</script>

</body>
</html>
