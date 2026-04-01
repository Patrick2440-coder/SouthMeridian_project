<?php
session_start();
require_once 'admin_access.php';
requireAccess('homeowner_management');

if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin','superadmin'], true)) {
  echo "<script>alert('Access denied. Please login as admin.'); window.location='index.php';</script>";
  exit();
}

$host="localhost"; 
$db="u972459197_south_meridian"; 
$user="u972459197_patrick"; 
$pass="Idle2440";

$conn = new mysqli($host,$user,$pass,$db);
if ($conn->connect_error) die("Connection failed: ".$conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function phase_prefix(string $phase): string {
  $n = (int) filter_var($phase, FILTER_SANITIZE_NUMBER_INT);
  return $n > 0 ? ('P'.$n) : 'P';
}

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

if (!isset($permissions) || !is_array($permissions)) {
  $permissions = [];
}

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
	<meta charset="utf-8">
	<title>HOA-ADMIN</title>

	<link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
	<link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
	<link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">

	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
	<link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
	<link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
	<link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">
	<link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">
	<link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
	<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

	<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

	<script async src="https://www.googletagmanager.com/gtag/js?id=UA-119386393-1"></script>

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
		
		#viewHomeownerModal #coverMap{
			min-height:320px;
			background:#e9eef6;
		}

		#viewHomeownerModal{
			z-index: 1055;
		}
		#actionConfirmModal{
			z-index: 1080;
		}
		.modal-backdrop.show{
			opacity: .5;
		}
		.modal-backdrop + .modal-backdrop{
			z-index: 1070 !important;
		}
		#actionConfirmModal + .modal-backdrop,
		.modal-backdrop.confirm-top{
			z-index: 1070 !important;
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

	<?php include 'sidebar.php'; ?>

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

									$displayId = trim((string)($row['public_id'] ?? ''));
									if ($displayId === '') {
										$rowPhase = (string)($row['phase'] ?? $admin_phase);
										$prefix = phase_prefix($rowPhase);
										$displayId = $prefix . (int)$row['id'];
									}
								?>
								<tr>
									<td><?= esc($displayId) ?></td>
									<td><?= esc(trim(($row['first_name'] ?? '').' '.($row['middle_name'] ?? '').' '.($row['last_name'] ?? ''))) ?></td>
									<td><?= esc(trim(($row['phase'] ?? '').', '.($row['house_lot_number'] ?? ''))) ?></td>
									<td><span class="badge <?= $badgeClass ?>"><?= esc(ucfirst($status)) ?></span></td>
									<td>
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

	<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 2000;">
		<div id="appToast" class="toast align-items-center" role="alert" aria-live="assertive" aria-atomic="true">
			<div class="d-flex">
				<div class="toast-body" id="appToastMsg">...</div>
				<button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
			</div>
		</div>
	</div>

	<div id="accessToast" class="access-toast">
	  🚫 You do not have access to that part.
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
		if ($.fn.DataTable && $('#approvalTable').length && !$.fn.DataTable.isDataTable('#approvalTable')) {
			$('#approvalTable').DataTable({
				responsive: true,
				columnDefs: [{ orderable: false, targets: 4 }]
			});
		}

		const confirmModalEl = document.getElementById('actionConfirmModal');
		const confirmTitleEl = document.getElementById('actionConfirmTitle');
		const confirmTextEl  = document.getElementById('actionConfirmText');
		const confirmBtnEl   = document.getElementById('actionConfirmBtn');
		const reasonWrapEl   = document.getElementById('rejectReasonWrap');
		const reasonInputEl  = document.getElementById('rejectReasonInput');
		const reasonErrorEl  = document.getElementById('rejectReasonError');

		let pendingAction = { id: null, status: null };

		const confirmModal = new bootstrap.Modal(confirmModalEl, {
			backdrop: true,
			keyboard: false,
			focus: true
		});

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
			}

			confirmModal.show();

			setTimeout(function () {
				const backdrops = document.querySelectorAll('.modal-backdrop');
				if (backdrops.length > 1) {
					backdrops[backdrops.length - 1].classList.add('confirm-top');
				}
				if (status === 'rejected') {
					reasonInputEl.focus();
				} else {
					confirmBtnEl.focus();
				}
			}, 120);
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
					showToast((res && res.message) ? res.message : 'Action failed.', 'error');
					confirmBtnEl.disabled = false;
					confirmBtnEl.textContent = oldText;
					return;
				}

				showToast(res.message || 'Updated successfully.', 'success');
				confirmModal.hide();

				const viewModalEl = document.getElementById('viewHomeownerModal');
				const viewModalInstance = bootstrap.Modal.getInstance(viewModalEl);
				if (viewModalInstance) {
					viewModalInstance.hide();
				}

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

			document.querySelectorAll('.modal-backdrop.confirm-top').forEach(function(el){
				el.classList.remove('confirm-top');
			});
		});

		const modalEl = document.getElementById('viewHomeownerModal');
		const content = document.getElementById('viewHomeownerContent');
		const modal = new bootstrap.Modal(modalEl, {
			backdrop: 'static',
			keyboard: true
		});

		let coverMapInstance = null;
		let pendingProfileHtml = '';

		function destroyCoverMap() {
			if (coverMapInstance) {
				coverMapInstance.remove();
				coverMapInstance = null;
			}
		}

function initCoverMapIfAny() {
	const mapEl = document.getElementById('coverMap');
	if (!mapEl || typeof L === 'undefined') return;

	const lat = parseFloat(mapEl.getAttribute('data-lat') || '');
	const lng = parseFloat(mapEl.getAttribute('data-lng') || '');
	if (!isFinite(lat) || !isFinite(lng)) return;

	destroyCoverMap();

	coverMapInstance = L.map(mapEl, {
		center: [lat, lng],
		zoom: 18,
		zoomControl: true,
		attributionControl: true
	});

	L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
		maxZoom: 19,
		subdomains: ['a', 'b', 'c'],
		attribution: '&copy; OpenStreetMap contributors'
	}).addTo(coverMapInstance);

	L.marker([lat, lng]).addTo(coverMapInstance);

	setTimeout(function () {
		if (coverMapInstance) {
			coverMapInstance.invalidateSize(true);
			coverMapInstance.setView([lat, lng], 18);
		}
	}, 300);

	setTimeout(function () {
		if (coverMapInstance) {
			coverMapInstance.invalidateSize(true);
		}
	}, 800);
}

		$(document).on('click', '.viewHomeownerBtn', function (e) {
			e.preventDefault();
			const id = $(this).data('id');
			if (!id) return;

			pendingProfileHtml = '';
			destroyCoverMap();
			content.innerHTML = '<div class="p-4 text-muted fw-semibold">Loading...</div>';
			modal.show();

			$.get('HO-management.php', { ajax: 'homeowner_profile', id: id, _: Date.now() })
				.done(function (html) {
					pendingProfileHtml = html;

					if (modalEl.classList.contains('show')) {
						content.innerHTML = pendingProfileHtml;
						initCoverMapIfAny();
					}
				})
				.fail(function (xhr) {
					content.innerHTML = '<div class="p-4"><div class="alert alert-danger mb-0">Failed to load profile. HTTP ' + xhr.status + '</div></div>';
				});
		});

		modalEl.addEventListener('shown.bs.modal', function () {
			if (pendingProfileHtml) {
				content.innerHTML = pendingProfileHtml;
				initCoverMapIfAny();
			} else {
				setTimeout(function () {
					initCoverMapIfAny();
				}, 200);
			}
		});

		modalEl.addEventListener('hidden.bs.modal', function () {
			destroyCoverMap();
			pendingProfileHtml = '';
			content.innerHTML = '';
		});
	});
</script>

	<script>
	window.userPermissions = <?= json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

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