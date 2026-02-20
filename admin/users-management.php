<?php
session_start();

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin','superadmin'], true) || empty($_SESSION['user_id'])) {
	header("Location: index.php");
	exit;
}

$conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
$conn->set_charset("utf8mb4");

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$admin_id = (int)$_SESSION['user_id'];

// Get admin phase
$stmt = $conn->prepare("SELECT phase, role, full_name, email FROM admins WHERE id=? LIMIT 1");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin) {
	session_destroy();
	header("Location: index.php");
	exit;
}

$admin_phase = $admin['phase']; // Phase 1|Phase 2|Phase 3|Superadmin

if ($admin_phase === 'Superadmin') {
	die("Superadmin phase is not tied to a specific HOA phase. Please login as Phase Admin (Phase 1/2/3) to manage users here.");
}

// view switcher (sidebar dropdown)
$view = $_GET['view'] ?? 'homeowners';
if (!in_array($view, ['homeowners','officers'], true)) $view = 'homeowners';

// Phase code prefix: Phase 1 => P1, Phase 2 => P2, Phase 3 => P3
$phase_no = (int) filter_var($admin_phase, FILTER_SANITIZE_NUMBER_INT);
$phase_prefix = "P".$phase_no;

// Load homeowners for this phase (approved only), with editable position from homeowner_positions
$sqlHomeowners = "
	SELECT
		h.id,
		h.first_name, h.middle_name, h.last_name,
		h.contact_number, h.email,
		h.house_lot_number,
		h.phase,
		COALESCE(hp.position, 'Homeowner') AS position
	FROM homeowners h
	LEFT JOIN homeowner_positions hp
		ON hp.homeowner_id = h.id AND hp.phase = h.phase
	WHERE h.status='approved' AND h.phase=?
	ORDER BY h.id DESC
";
$stmt = $conn->prepare($sqlHomeowners);
$stmt->bind_param("s", $admin_phase);
$stmt->execute();
$homeowners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Load officers for this phase (not editable here)
$sqlOfficers = "
	SELECT id, position, officer_name, officer_email, phase
	FROM hoa_officers
	WHERE phase=? AND is_active=1
	ORDER BY FIELD(position,'President','Vice President','Secretary','Treasurer','Auditor','Board of Director'), position
";
$stmt = $conn->prepare($sqlOfficers);
$stmt->bind_param("s", $admin_phase);
$stmt->execute();
$officers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$adminDisplayName = $admin['full_name'] ?: $admin['email'];
$pageTitle = $view === 'officers' ? 'User Management • Officers' : 'User Management • Homeowners';
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title><?= esc($pageTitle) ?></title>

	<link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
	<link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
	<link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">

	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
	<link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
	<link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
	<link rel="stylesheet" type="text/css" href="vendors/styles/style.css">

	<!-- DataTables (CDN to avoid your local 404 issue) -->
	<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
	<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">

	<style>
		.table thead th { border-bottom: 1px solid #e9ecef !important; }
		.table td, .table th { vertical-align: middle !important; }
		.badge { border-radius: 999px; }
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
										<p>User Management loaded for <?= esc($admin_phase) ?>.</p>
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
						<span class="user-name"><?= esc($adminDisplayName) ?></span>
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

			<div class="col-xl-12 mb-30">
				<div class="card-box height-100-p pd-20">

					<div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
						<div>
							<h4 class="mb-0">User Management</h4>
							<small class="text-muted">
								Phase: <?= esc($admin_phase) ?> —
								<?= $view === 'officers' ? 'Officers (Read-only)' : 'Homeowners (Editable Position)' ?>
							</small>
						</div>
					</div>

					<?php if ($view === 'homeowners'): ?>
						<!-- ================= HOMEOWNERS VIEW ================= -->
						<div class="table-responsive">
							<table id="homeownersTable" class="table table-hover table-striped mb-0 align-middle">
								<thead class="table-light">
									<tr>
										<th width="12%">ID</th>
										<th width="25%">Full Name</th>
										<th width="23%">Address & Contacts</th>
										<th width="20%">Position</th>
										<th width="20%" class="text-center">Action</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($homeowners as $h): ?>
										<?php
											$fullName = trim($h['first_name'].' '.($h['middle_name'] ?? '').' '.$h['last_name']);
											$displayId = $phase_prefix . $h['id'];
											$position = $h['position'] ?: 'Homeowner';
											$addr = $h['house_lot_number'] ?: '-';
											$contact = $h['contact_number'] ?: '-';
											$email = $h['email'] ?: '-';
										?>
										<tr id="row-homeowner-<?= (int)$h['id'] ?>">
											<td><span class="badge badge-success"><?= esc($displayId) ?></span></td>
											<td>
												<div class="font-weight-600"><?= esc($fullName) ?></div>
											</td>
											<td>
												<div><i class="dw dw-home"></i> <?= esc($addr) ?></div>
												<div><i class="dw dw-phone-call"></i> <?= esc($contact) ?></div>
												<div><i class="dw dw-mail"></i> <?= esc($email) ?></div>
											</td>
											<td>
												<span class="badge badge-primary" id="pos-badge-<?= (int)$h['id'] ?>">
													<?= esc($position) ?>
												</span>
											</td>
											<td class="text-center">
												<button
													type="button"
													class="btn btn-sm btn-outline-primary editPositionBtn"
													data-id="<?= (int)$h['id'] ?>"
													data-name="<?= esc($fullName) ?>"
													data-position="<?= esc($position) ?>"
												>
													<i class="dw dw-edit2"></i> Edit Position
												</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>

					<?php else: ?>
						<!-- ================= OFFICERS VIEW ================= -->
						<div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
							<div>
								<h5 class="mb-0">
									<i class="dw dw-shield1 mr-1"></i> HOA Officers — <?= esc($admin_phase) ?>
								</h5>
								<small class="text-muted">Read-only list (no editing here)</small>
							</div>

							<div class="d-flex align-items-center mt-2 mt-md-0">
								<div class="card-box pd-10 mr-2" style="min-width: 180px;">
									<div class="d-flex align-items-center justify-content-between">
										<div>
											<small class="text-muted d-block">Active Officers</small>
											<div class="font-weight-700" style="font-size:18px; line-height:1;">
												<?= (int)count($officers) ?>
											</div>
										</div>
										<div class="text-primary" style="font-size:28px;">
											<i class="dw dw-user-12"></i>
										</div>
									</div>
								</div>
							</div>
						</div>

						<div class="card-box pb-10">
							<div class="table-responsive">
								<table id="officersTable" class="table table-hover table-striped nowrap mb-0">
									<thead style="background:#f6f8fb;">
										<tr>
											<th style="width: 18%;">Position</th>
											<th style="width: 28%;">Officer Name</th>
											<th style="width: 30%;">Email</th>
											<th style="width: 12%;">Phase</th>
											<th style="width: 12%;">Status</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($officers as $o): ?>
											<?php
												$pos = $o['position'] ?? '';
												$name = $o['officer_name'] ?: '-';
												$email = $o['officer_email'] ?: '';
												$phase = $o['phase'] ?? $admin_phase;

												$badgeClass = 'badge badge-secondary';
												$posLower = strtolower($pos);
												if (strpos($posLower, 'president') !== false) $badgeClass = 'badge badge-primary';
												if (strpos($posLower, 'vice') !== false) $badgeClass = 'badge badge-info';
												if (strpos($posLower, 'secretary') !== false) $badgeClass = 'badge badge-success';
												if (strpos($posLower, 'treasurer') !== false) $badgeClass = 'badge badge-warning';
												if (strpos($posLower, 'auditor') !== false) $badgeClass = 'badge badge-dark';
												if (strpos($posLower, 'board') !== false) $badgeClass = 'badge badge-secondary';
											?>
											<tr>
												<td>
													<span class="<?= esc($badgeClass) ?>" style="font-size:12px; padding:6px 10px;">
														<?= esc($pos ?: '-') ?>
													</span>
												</td>
												<td class="font-weight-600"><?= esc($name) ?></td>
												<td>
													<?php if ($email): ?>
														<i class="dw dw-mail mr-1"></i> <?= esc($email) ?>
													<?php else: ?>
														<span class="text-muted">-</span>
													<?php endif; ?>
												</td>
												<td>
													<span class="badge badge-light" style="border:1px solid #e5e7eb;">
														<?= esc($phase) ?>
													</span>
												</td>
												<td>
													<span class="badge badge-success" style="font-size:12px; padding:6px 10px;">
														Active
													</span>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>

							<?php if (empty($officers)): ?>
								<div class="alert alert-info mt-3 mb-0">
									No active officers found for <?= esc($admin_phase) ?>.
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>

				</div>
			</div>

			<div class="footer-wrap pd-20 mb-20 card-box">
				© Copyright South Meridian Homes All Rights Reserved
			</div>

		</div>
	</div>

	<!-- EDIT POSITION MODAL -->
	<div class="modal fade" id="editPositionModal" tabindex="-1" role="dialog" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered" role="document">
			<div class="modal-content">

				<div class="modal-header bg-primary text-white">
					<h5 class="modal-title">
						<i class="dw dw-edit2"></i> Edit Homeowner Position
					</h5>
					<button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">&times;</span>
					</button>
				</div>

				<div class="modal-body">
					<form id="editPositionForm">
						<input type="hidden" name="homeowner_id" id="ep_homeowner_id" value="">

						<div class="mb-2">
							<label class="form-label font-weight-600">Homeowner</label>
							<div class="form-control" style="background:#f7f7f7" id="ep_homeowner_name">-</div>
						</div>

						<div class="mb-2">
							<label class="form-label font-weight-600">Position</label>
							<select class="form-control" name="position" id="ep_position_select">
								<option value="Homeowner">Homeowner</option>
								<option value="Committee">Committee</option>
								<option value="Block Representative">Block Representative</option>
								<option value="Staff">Staff</option>
								<option value="Volunteer">Volunteer</option>
							</select>
							<small class="text-muted">You can also type a custom position below.</small>
						</div>

						<div class="mb-2">
							<label class="form-label font-weight-600">Custom Position (optional)</label>
							<input type="text" class="form-control" id="ep_position_custom" placeholder="e.g. Event Coordinator">
						</div>

						<div class="alert alert-danger d-none mt-3" id="ep_error"></div>
						<div class="alert alert-success d-none mt-3" id="ep_success"></div>
					</form>
				</div>

				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
					<button type="button" class="btn btn-primary" id="savePositionBtn">
						<i class="dw dw-save2"></i> Save
					</button>
				</div>

			</div>
		</div>
	</div>

	<!-- js -->
	<script src="vendors/scripts/core.js"></script>
	<script src="vendors/scripts/script.min.js"></script>
	<script src="vendors/scripts/process.js"></script>
	<script src="vendors/scripts/layout-settings.js"></script>

	<!-- Bootstrap 4 modal dependencies -->
	<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.min.js"></script>

	<!-- DataTables (CDN) -->
	<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
	<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
	<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
	<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap4.min.js"></script>

	<script>
	$(document).ready(function() {

		// Bind modal click first
		$(document).on('click', '.editPositionBtn', function() {
			const id = $(this).data('id');
			const name = $(this).data('name');
			const position = $(this).data('position') || 'Homeowner';

			$('#ep_homeowner_id').val(id);
			$('#ep_homeowner_name').text(name);

			$('#ep_position_custom').val('');
			const existsInSelect = $('#ep_position_select option').filter(function(){
				return $(this).val() === position;
			}).length > 0;

			if (existsInSelect) {
				$('#ep_position_select').val(position);
			} else {
				$('#ep_position_select').val('Homeowner');
				$('#ep_position_custom').val(position);
			}

			$('#ep_error').addClass('d-none').text('');
			$('#ep_success').addClass('d-none').text('');

			$('#editPositionModal').modal('show');
		});

		// DataTables init safely
		if ($.fn.DataTable) {
			if ($('#homeownersTable').length) {
				$('#homeownersTable').DataTable({
					responsive: true,
					columnDefs: [{ orderable: false, targets: 4 }]
				});
			}

			if ($('#officersTable').length) {
				$('#officersTable').DataTable({
					responsive: true,
					pageLength: 10,
					lengthChange: false,
					order: []
				});
			}
		} else {
			console.warn('DataTables not loaded. Tables will work but without search/paging.');
		}

		// Save position AJAX
		$('#savePositionBtn').on('click', function() {
			const homeownerId = $('#ep_homeowner_id').val();
			let position = ($('#ep_position_custom').val() || '').trim();
			if (!position) position = $('#ep_position_select').val();

			$('#ep_error').addClass('d-none').text('');
			$('#ep_success').addClass('d-none').text('');

			$.ajax({
				url: 'update_homeowner_position.php',
				type: 'POST',
				dataType: 'json',
				data: { homeowner_id: homeownerId, position: position },
				success: function(res) {
					if (!res || !res.success) {
						$('#ep_error').removeClass('d-none').text(res && res.message ? res.message : 'Failed to update position.');
						return;
					}

					$('#pos-badge-' + homeownerId).text(position);
					$('button.editPositionBtn[data-id="' + homeownerId + '"]').data('position', position);

					$('#ep_success').removeClass('d-none').text('Position updated successfully.');
					setTimeout(function(){ $('#editPositionModal').modal('hide'); }, 600);
				},
				error: function() {
					$('#ep_error').removeClass('d-none').text('Server error. Please try again.');
				}
			});
		});

	});
	</script>

</body>
</html>
