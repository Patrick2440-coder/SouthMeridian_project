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

// admin info (always read from DB)
$admin_id = (int)$_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT phase, role FROM admins WHERE id=?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();
$admin_phase = $admin['phase'] ?? '';
$admin_role  = $admin['role'] ?? '';

$isHOSection = true;

// pending homeowners
if ($admin_role === 'superadmin') {
  $sqlHO = $conn->prepare("SELECT * FROM homeowners WHERE status='pending' ORDER BY created_at DESC");
} else {
  $sqlHO = $conn->prepare("SELECT * FROM homeowners WHERE status='pending' AND phase=? ORDER BY created_at DESC");
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

	<style>
		:root{--brand:#077f46;}
		.badge{padding:.25em .55em;border-radius:.45rem;color:#fff;font-size:.82rem;font-weight:700}
		.badge-warning{background:#f0ad4e}
		.badge-success{background:#22c55e}
		.badge-danger{background:#ef4444}
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
					<a href="javascript:void(0);" class="btn btn-outline-primary sidebar-light ">White</a>
					<a href="javascript:void(0);" class="btn btn-outline-primary sidebar-dark active">Dark</a>
				</div>

				<div class="reset-options pt-30 text-center">
					<button class="btn btn-danger" id="reset-settings">Reset Settings</button>
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

					<li class="dropdown">
						<a href="javascript:;" class="dropdown-toggle">
							<span class="micon dw dw-user"></span>
							<span class="mtext">Homeowner Management</span>
						</a>
						<ul class="submenu">
							<li><a  href="ho_approval.php">Household Approval</a></li>
							<li><a href="ho_register.php">Register Household</a></li>
							<li><a href="ho_approved.php">Approved Households</a></li>
						</ul>
					</li>
					<!-- ✅ USER MANAGEMENT DROPDOWN -->
					<li class="dropdown">
						<a href="javascript:;" class="dropdown-toggle <?= ($view==='homeowners' || $view==='officers') ? 'active' : '' ?>">
							<span class="micon dw dw-user"></span>
							<span class="mtext">User Management</span>
						</a>
						<ul class="submenu">
							<li>
								<a href="users-management.php?view=homeowners" class="<?= $view==='homeowners' ? 'active' : '' ?>">
									Homeowners
								</a>
							</li>
							<li>
								<a href="users-management.php?view=officers" class="<?= $view==='officers' ? 'active' : '' ?>">
									Officers
								</a>
							</li>
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
					<div class="text-muted fw-semibold subtitle">Household Approval</div>
				</div>
			</div>

			<div class="card-box p-3">
				<div class="table-responsive">
					<table id="approvalTable" class="display table table-striped table-bordered nowrap" style="width:100%">
						<thead>
							<tr>
								<th>ID</th>
								<th>Name</th>
								<th>Address</th>
								<th>Status</th>
								<th style="width:180px;">Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php while($row = $resultHO->fetch_assoc()): ?>
								<?php
									$status = (string)($row['status'] ?? 'pending');
									$badgeClass = ($status==='pending') ? 'badge-warning' : (($status==='approved') ? 'badge-success' : 'badge-danger');
								?>
								<tr>
									<td><?= (int)$row['id'] ?></td>
									<td><?= esc(trim(($row['first_name'] ?? '').' '.($row['middle_name'] ?? '').' '.($row['last_name'] ?? ''))) ?></td>
									<td><?= esc(trim(($row['phase'] ?? '').', '.($row['house_lot_number'] ?? ''))) ?></td>
									<td><span class="badge <?= $badgeClass ?>"><?= esc(ucfirst($status)) ?></span></td>
									<td>
										<button class="btn btn-sm btn-success approveHomeowner" data-id="<?= (int)$row['id'] ?>" title="Approve">
											<i class="dw dw-checked"></i>
										</button>
										<button class="btn btn-sm btn-danger rejectHomeowner" data-id="<?= (int)$row['id'] ?>" title="Reject">
											<i class="dw dw-cancel-1"></i>
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
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>

				<div class="modal-body">
					<p class="mb-3" id="actionConfirmText">Are you sure?</p>

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
					<button type="button" class="btn btn-success" id="actionConfirmBtn">Confirm</button>
				</div>
			</div>
		</div>
	</div>

	<!-- ================= VIEW HOMEOWNER MODAL (AJAX) ================= -->
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

	<!-- ================= TOAST ================= -->
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
		function showToast(message, type='success') {
			const toastEl = document.getElementById('appToast');
			const msgEl = document.getElementById('appToastMsg');
			msgEl.textContent = message;

			toastEl.classList.remove('text-bg-success','text-bg-danger','text-bg-warning','text-bg-info','text-bg-dark');
			toastEl.classList.add(type === 'success' ? 'text-bg-success' : 'text-bg-danger');

			bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 2800 }).show();
		}

		$(function () {
			// DataTable safe init
			if ($.fn.DataTable && $('#approvalTable').length && !$.fn.DataTable.isDataTable('#approvalTable')) {
				$('#approvalTable').DataTable({ responsive: true, columnDefs: [{ orderable: false, targets: 4 }] });
			}

			// ---------- Approve/Reject confirm modal ----------
			const confirmModalEl = document.getElementById('actionConfirmModal');
			const confirmTitleEl = document.getElementById('actionConfirmTitle');
			const confirmTextEl  = document.getElementById('actionConfirmText');
			const confirmBtnEl   = document.getElementById('actionConfirmBtn');
			const reasonWrapEl   = document.getElementById('rejectReasonWrap');
			const reasonInputEl  = document.getElementById('rejectReasonInput');
			const reasonErrorEl  = document.getElementById('rejectReasonError');

			let pendingAction = { id: null, status: null };

			const confirmModal = new bootstrap.Modal(confirmModalEl, { backdrop:'static', keyboard:false });

			$(document).on('click', '.approveHomeowner, .rejectHomeowner', function (e) {
				e.preventDefault();

				const id = $(this).data('id');
				const status = $(this).hasClass('approveHomeowner') ? 'approved' : 'rejected';
				if (!id) return;

				pendingAction = { id, status };

				if (status === 'approved') {
					confirmTitleEl.textContent = 'Approve Homeowner';
					confirmTextEl.textContent  = 'This will approve the homeowner. Continue?';
					confirmBtnEl.classList.remove('btn-danger');
					confirmBtnEl.classList.add('btn-success');
					confirmBtnEl.textContent = 'Approve';
					reasonWrapEl.style.display = 'none';
					reasonErrorEl.style.display = 'none';
					reasonInputEl.value = '';
				} else {
					confirmTitleEl.textContent = 'Reject Homeowner';
					confirmTextEl.textContent  = 'Please provide a rejection reason. This will be saved and sent.';
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

				confirmBtnEl.disabled = true;
				const oldText = confirmBtnEl.textContent;
				confirmBtnEl.textContent = status === 'approved' ? 'Approving...' : 'Rejecting...';

				$.post('update_homeowner_status_email.php', { id, status, reason }, function (res) {
					if (!res || !res.success) {
						showToast(res?.message || 'Action failed.', 'error');
						confirmBtnEl.disabled = false;
						confirmBtnEl.textContent = oldText;
						return;
					}
					showToast(res.message || 'Updated successfully.', 'success');
					confirmModal.hide();
					setTimeout(() => location.reload(), 600);
				}, 'json').fail(function (xhr) {
					console.error(xhr.responseText);
					showToast('Request failed. Please try again.', 'error');
					confirmBtnEl.disabled = false;
					confirmBtnEl.textContent = oldText;
				});
			});

			confirmModalEl.addEventListener('hidden.bs.modal', function () {
				pendingAction = { id: null, status: null };
				confirmBtnEl.disabled = false;
				confirmBtnEl.textContent = 'Confirm';
				reasonErrorEl.style.display = 'none';
				reasonInputEl.value = '';
			});

			// ---------- View Homeowner (AJAX -> HO-management.php) ----------
			const modalEl = document.getElementById('viewHomeownerModal');
			const content = document.getElementById('viewHomeownerContent');
			const modal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: true });

			let coverMapInstance = null;

			function initCoverMapIfAny() {
				const mapEl = document.getElementById('coverMap');
				if (!mapEl) return;

				const lat = parseFloat(mapEl.dataset.lat || '');
				const lng = parseFloat(mapEl.dataset.lng || '');
				if (!isFinite(lat) || !isFinite(lng)) return;

				if (coverMapInstance) { coverMapInstance.remove(); coverMapInstance = null; }

				coverMapInstance = L.map('coverMap', { zoomControl: false }).setView([lat, lng], 18);
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

				content.innerHTML = `<div class="p-4 text-muted fw-semibold">Loading...</div>`;
				modal.show();

				$.get('HO-management.php', { ajax: 'homeowner_profile', id: id, _: Date.now() })
					.done(function (html) {
						content.innerHTML = html;
						initCoverMapIfAny();
					})
					.fail(function (xhr) {
						content.innerHTML = `<div class="p-4"><div class="alert alert-danger mb-0">Failed to load profile. HTTP ${xhr.status}</div></div>`;
					});
			});

			modalEl.addEventListener('hidden.bs.modal', function () {
				if (coverMapInstance) { coverMapInstance.remove(); coverMapInstance = null; }
				content.innerHTML = '';
			});
		});
	</script>

</body>
</html>
