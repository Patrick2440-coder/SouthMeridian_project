<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('esc')) {
    function esc($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/
$conn = $conn ?? new mysqli(
    "localhost",
    "u972459197_patrick",
    "Idle2440",
    "u972459197_south_meridian"
);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

/*
|--------------------------------------------------------------------------
| GET LOGGED-IN ADMIN INFO
|--------------------------------------------------------------------------
| Priority:
| 1. Use session position if already available
| 2. If missing, fetch from admins table using admin_id
|--------------------------------------------------------------------------
*/
$adminId    = (int)($_SESSION['admin_id'] ?? 0);
$adminRole  = $_SESSION['admin_role'] ?? ($_SESSION['role'] ?? '');
$position   = trim((string)($_SESSION['position'] ?? ''));
$adminPhase = trim((string)($_SESSION['admin_phase'] ?? ($_SESSION['phase'] ?? '')));

if ($adminId > 0 && ($position === '' || $adminPhase === '')) {
    $stmt = $conn->prepare("SELECT position, phase, role FROM admins WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        if ($position === '') {
            $position = (string)($row['position'] ?? '');
            $_SESSION['position'] = $position;
        }
        if ($adminPhase === '') {
            $adminPhase = (string)($row['phase'] ?? '');
            $_SESSION['phase'] = $adminPhase;
            $_SESSION['admin_phase'] = $adminPhase;
        }
        if ($adminRole === '') {
            $adminRole = (string)($row['role'] ?? '');
            $_SESSION['admin_role'] = $adminRole;
        }
    }
    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| ACCESS HELPERS
|--------------------------------------------------------------------------
*/
$allowedModules = [];

// Superadmin can see everything
if ($adminRole === 'superadmin' || $position === 'Superadmin') {
    $modRes = $conn->query("SELECT module_key FROM access_modules");
    while ($modRes && $row = $modRes->fetch_assoc()) {
        $allowedModules[] = $row['module_key'];
    }
} elseif ($position !== '') {
    $stmt = $conn->prepare("
        SELECT module_key
        FROM access_permissions
        WHERE position = ? AND is_allowed = 1
    ");
    $stmt->bind_param("s", $position);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $allowedModules[] = $row['module_key'];
    }
    $stmt->close();
}

if (!function_exists('canAccess')) {
    function canAccess($moduleKey, array $allowedModules): bool {
        return in_array($moduleKey, $allowedModules, true);
    }
}

if (!function_exists('isMenuActive')) {
    function isMenuActive(array $pages = [], array $views = []): bool {
        $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
        $currentView = $_GET['view'] ?? '';

        if (in_array($currentPage, $pages, true)) {
            return true;
        }
        if (in_array($currentView, $views, true)) {
            return true;
        }
        return false;
    }
}

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$view = $_GET['view'] ?? '';

$showDashboard            = canAccess('dashboard', $allowedModules);
$showHomeownerManagement  = canAccess('homeowner_management', $allowedModules);
$showUserManagement       = canAccess('user_management', $allowedModules);
$showAnnouncements        = canAccess('announcements', $allowedModules);
$showComplaints           = canAccess('complaints', $allowedModules);
$showFinance              = canAccess('finance', $allowedModules);
$showParking              = canAccess('parking', $allowedModules);
$showCommunity            = canAccess('community', $allowedModules);
$showActivityLog          = canAccess('activity_log', $allowedModules);
$showSettings             = canAccess('settings', $allowedModules);
?>

<!-- SIDEBAR -->
<div class="left-side-bar" style="background-color:#077f46;">
  <div class="brand-logo">

    <a href="dashboard.php" class="logo-container" style="display:flex; align-items:center; gap:12px; justify-content:flex-start; width:100%;">
      <img src="vendors/images/sm_logo.png" alt="Logo" class="logo-img" style="height:45px; width:auto;">
      <span class="logo-text" style="font-size:13px; font-weight:500; color:#ffffff; line-height:1.2;">South Meridian Homes</span>
    </a>

    <div class="close-sidebar" data-toggle="left-sidebar-close">
      <i class="ion-close-round"></i>
    </div>
  </div>

  <div class="menu-block customscroll">
    <div class="sidebar-menu">
      <ul id="accordion-menu">

        <?php if ($showDashboard): ?>
          <li>
            <a href="dashboard.php"
               class="dropdown-toggle no-arrow menu-access-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
               data-module="dashboard">
              <span class="micon dw dw-house-1"></span>
              <span class="mtext">Dashboard</span>
            </a>
          </li>
        <?php endif; ?>

        <?php if ($showHomeownerManagement): ?>
          <li class="dropdown">
            <a href="javascript:;"
               class="dropdown-toggle <?= isMenuActive(['ho_approval.php', 'ho_register.php', 'ho_approved.php']) ? 'active' : '' ?>">
              <span class="micon dw dw-user"></span>
              <span class="mtext">Homeowner Management</span>
            </a>
            <ul class="submenu" style="<?= isMenuActive(['ho_approval.php', 'ho_register.php', 'ho_approved.php']) ? 'display:block;' : '' ?>">
              <li>
                <a href="ho_approval.php"
                   class="menu-access-link <?= $currentPage === 'ho_approval.php' ? 'active' : '' ?>"
                   data-module="homeowner_management">
                  Household Approval
                </a>
              </li>
              <li>
                <a href="ho_register.php"
                   class="menu-access-link <?= $currentPage === 'ho_register.php' ? 'active' : '' ?>"
                   data-module="homeowner_management">
                  Register Household
                </a>
              </li>
              <li>
                <a href="ho_approved.php"
                   class="menu-access-link <?= $currentPage === 'ho_approved.php' ? 'active' : '' ?>"
                   data-module="homeowner_management">
                  Approved Households
                </a>
              </li>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($showUserManagement): ?>
          <li class="dropdown">
            <a href="javascript:;"
               class="dropdown-toggle <?= isMenuActive(['users-management.php', 'staff_management.php'], ['homeowners', 'officers']) ? 'active' : '' ?>">
              <span class="micon dw dw-user"></span>
              <span class="mtext">User Management</span>
            </a>
            <ul class="submenu" style="<?= isMenuActive(['users-management.php', 'staff_management.php'], ['homeowners', 'officers']) ? 'display:block;' : '' ?>">
              <li>
                <a href="users-management.php?view=homeowners"
                   class="menu-access-link <?= ($currentPage === 'users-management.php' && $view === 'homeowners') ? 'active' : '' ?>"
                   data-module="user_management">
                  Homeowners
                </a>
              </li>
              <li>
                <a href="users-management.php?view=officers"
                   class="menu-access-link <?= ($currentPage === 'users-management.php' && $view === 'officers') ? 'active' : '' ?>"
                   data-module="user_management">
                  Officers
                </a>
              </li>
              <li>
                <a href="staff_management.php"
                   class="menu-access-link <?= $currentPage === 'staff_management.php' ? 'active' : '' ?>"
                   data-module="user_management">
                  Staff
                </a>
              </li>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($showAnnouncements): ?>
          <li>
            <a href="announcements.php"
               class="dropdown-toggle no-arrow menu-access-link <?= $currentPage === 'announcements.php' ? 'active' : '' ?>"
               data-module="announcements">
              <span class="micon dw dw-megaphone"></span>
              <span class="mtext">Announcement</span>
            </a>
          </li>
        <?php endif; ?>

        <?php if ($showComplaints): ?>
          <li class="dropdown">
            <a href="javascript:;"
               class="dropdown-toggle <?= isMenuActive(['admin_complaints.php']) ? 'active' : '' ?>">
              <span class="micon dw dw-chat3"></span>
              <span class="mtext">Complaints</span>
            </a>
            <ul class="submenu" style="<?= isMenuActive(['admin_complaints.php']) ? 'display:block;' : '' ?>">
              <li>
                <a href="admin_complaints.php"
                   class="menu-access-link <?= ($currentPage === 'admin_complaints.php' && !isset($_GET['filter'])) ? 'active' : '' ?>"
                   data-module="complaints">
                  Complaints Overview
                </a>
              </li>
              <li>
                <a href="admin_complaints.php?filter=open"
                   class="menu-access-link <?= ($currentPage === 'admin_complaints.php' && (($_GET['filter'] ?? '') === 'open')) ? 'active' : '' ?>"
                   data-module="complaints">
                  Open Complaints
                </a>
              </li>
              <li>
                <a href="admin_complaints.php?filter=in_progress"
                   class="menu-access-link <?= ($currentPage === 'admin_complaints.php' && (($_GET['filter'] ?? '') === 'in_progress')) ? 'active' : '' ?>"
                   data-module="complaints">
                  In Progress
                </a>
              </li>
              <li>
                <a href="admin_complaints.php?filter=resolved"
                   class="menu-access-link <?= ($currentPage === 'admin_complaints.php' && (($_GET['filter'] ?? '') === 'resolved')) ? 'active' : '' ?>"
                   data-module="complaints">
                  Resolved / Closed
                </a>
              </li>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($showFinance): ?>
          <li class="dropdown">
            <a href="javascript:;"
               class="dropdown-toggle <?= isMenuActive(['finance.php', 'finance_dues.php', 'finance_donations.php', 'finance_expenses.php', 'finance_reports.php', 'finance_cashflow.php']) ? 'active' : '' ?>">
              <span class="micon dw dw-money-1"></span>
              <span class="mtext">Finance</span>
            </a>
            <ul class="submenu" style="<?= isMenuActive(['finance.php', 'finance_dues.php', 'finance_donations.php', 'finance_expenses.php', 'finance_reports.php', 'finance_cashflow.php']) ? 'display:block;' : '' ?>">
              <li>
                <a href="finance.php"
                   class="menu-access-link <?= $currentPage === 'finance.php' ? 'active' : '' ?>"
                   data-module="finance">
                  Overview
                </a>
              </li>
              <li>
                <a href="finance_dues.php"
                   class="menu-access-link <?= $currentPage === 'finance_dues.php' ? 'active' : '' ?>"
                   data-module="finance">
                  Monthly Dues
                </a>
              </li>
              <li>
                <a href="finance_donations.php"
                   class="menu-access-link <?= $currentPage === 'finance_donations.php' ? 'active' : '' ?>"
                   data-module="finance">
                  Donations
                </a>
              </li>
              <li>
                <a href="finance_expenses.php"
                   class="menu-access-link <?= $currentPage === 'finance_expenses.php' ? 'active' : '' ?>"
                   data-module="finance">
                  Expenses
                </a>
              </li>
              <li>
                <a href="finance_reports.php"
                   class="menu-access-link <?= $currentPage === 'finance_reports.php' ? 'active' : '' ?>"
                   data-module="finance">
                  Financial Reports
                </a>
              </li>
              <li>
                <a href="finance_cashflow.php"
                   class="menu-access-link <?= $currentPage === 'finance_cashflow.php' ? 'active' : '' ?>"
                   data-module="finance">
                  Cash Flow Dashboard
                </a>
              </li>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($showParking): ?>
          <li class="dropdown">
            <a href="javascript:;"
               class="dropdown-toggle <?= isMenuActive(['parking.php', 'parking_permits.php', 'parking_violations.php']) ? 'active' : '' ?>">
              <span class="micon dw dw-car"></span>
              <span class="mtext">Parking</span>
            </a>
            <ul class="submenu" style="<?= isMenuActive(['parking.php', 'parking_permits.php', 'parking_violations.php']) ? 'display:block;' : '' ?>">
              <li>
                <a href="parking.php"
                   class="menu-access-link <?= $currentPage === 'parking.php' ? 'active' : '' ?>"
                   data-module="parking">
                  Parking Overview
                </a>
              </li>
              <li>
                <a href="parking_permits.php"
                   class="menu-access-link <?= $currentPage === 'parking_permits.php' ? 'active' : '' ?>"
                   data-module="parking">
                  Manage Permits
                </a>
              </li>
              <li>
                <a href="parking_violations.php"
                   class="menu-access-link <?= $currentPage === 'parking_violations.php' ? 'active' : '' ?>"
                   data-module="parking">
                  View Violations
                </a>
              </li>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($showCommunity): ?>
          <li class="dropdown">
            <a href="javascript:;"
               class="dropdown-toggle <?= isMenuActive(['admin_facility_rentals.php', 'admin_facility_calendar.php', 'admin_public_chat.php']) ? 'active' : '' ?>">
              <span class="micon dw dw-calendar1"></span>
              <span class="mtext">Community</span>
            </a>
            <ul class="submenu" style="<?= isMenuActive(['admin_facility_rentals.php', 'admin_facility_calendar.php', 'admin_public_chat.php']) ? 'display:block;' : '' ?>">
              <li>
                <a href="admin_facility_rentals.php"
                   class="menu-access-link <?= $currentPage === 'admin_facility_rentals.php' ? 'active' : '' ?>"
                   data-module="community">
                  Facility Rentals
                </a>
              </li>
              <li>
                <a href="admin_facility_calendar.php"
                   class="menu-access-link <?= $currentPage === 'admin_facility_calendar.php' ? 'active' : '' ?>"
                   data-module="community">
                  Rentals Calendar
                </a>
              </li>
              <li>
                <a href="admin_public_chat.php"
                   class="menu-access-link <?= $currentPage === 'admin_public_chat.php' ? 'active' : '' ?>"
                   data-module="community">
                  Public Chat Monitor
                </a>
              </li>
            </ul>
          </li>
        <?php endif; ?>

        <?php if ($showActivityLog): ?>
          <li>
            <a href="activity_log.php"
               class="dropdown-toggle no-arrow menu-access-link <?= $currentPage === 'activity_log.php' ? 'active' : '' ?>"
               data-module="activity_log">
              <span class="micon dw dw-list3"></span>
              <span class="mtext">Activity Log</span>
            </a>
          </li>
        <?php endif; ?>

        <?php if ($showSettings): ?>
          <li>
            <a href="#"
               class="dropdown-toggle no-arrow menu-access-link"
               data-module="settings">
              <span class="micon dw dw-settings2"></span>
              <span class="mtext">Settings</span>
            </a>
          </li>
        <?php endif; ?>

      </ul>
    </div>
  </div>
</div>