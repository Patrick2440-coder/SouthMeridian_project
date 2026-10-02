<?php

require_once '../config/database.php';

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

/*

|--------------------------------------------------------------------------

| ACCESS CONTROL

|--------------------------------------------------------------------------

| This page is controlled by HOA position, not by the editable permission

| matrix. President, Vice President, and Secretary always keep access to the

| Access Control page while they hold one of these positions.

*/

$showAccessControl =

    $adminRole === 'admin' &&

    in_array(

        $position,

        ['President', 'Vice President', 'Secretary'],

        true

    );

$showHomeownerManagement  = canAccess('homeowner_management', $allowedModules);

$showUserManagement       = canAccess('user_management', $allowedModules);

$showAnnouncements        = canAccess('announcements', $allowedModules);

$showVotingRequest        = ($adminRole === 'admin' && strcasecmp($position, 'President') === 0);

$showComplaints           = canAccess('complaints', $allowedModules);

$showFinance              = canAccess('finance', $allowedModules);

$showParking              = canAccess('parking', $allowedModules);

/*

|--------------------------------------------------------------------------

| CCTV MONITORING

|--------------------------------------------------------------------------

|

| CCTV is currently a prototype admin module and does not yet have its own

| access_modules/access_permissions row. Show it to normal phase admins for

| now. When a dedicated "cctv" permission is added later, change this to:

|

| $showCctv = canAccess('cctv', $allowedModules);

|

*/

$showCctv                 = ($adminRole === 'admin');

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

        <?php if ($showAccessControl): ?>

          <li>

            <a href="access_control.php"

               class="dropdown-toggle no-arrow <?= $currentPage === 'access_control.php' ? 'active' : '' ?>">

              <span class="micon dw dw-lock"></span>

              <span class="mtext">Access Control</span>

            </a>

          </li>

        <?php endif; ?>

        <?php if ($showHomeownerManagement): ?>

          <li class="dropdown">

            <a href="javascript:;"

               class="dropdown-toggle <?= isMenuActive(['ho_approval.php', 'ho_register.php', 'ho_approved.php', 'ho_archive.php']) ? 'active' : '' ?>">

              <span class="micon dw dw-user"></span>

              <span class="mtext">Homeowner Management</span>

            </a>

            <ul class="submenu" style="<?= isMenuActive(['ho_approval.php', 'ho_register.php', 'ho_approved.php', 'ho_archive.php']) ? 'display:block;' : '' ?>">

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

              <li>

                <a href="ho_archive.php"

                   class="menu-access-link <?= $currentPage === 'ho_archive.php' ? 'active' : '' ?>"

                   data-module="homeowner_management">

                  Archive

                </a>

              </li>

            </ul>

          </li>

        <?php endif; ?>

        <?php if ($showUserManagement): ?>

          <li class="dropdown">

            <a href="javascript:;"

               class="dropdown-toggle <?= isMenuActive(    ['users-management.php', 'staff_management.php', 'login_security.php'],

    ['homeowners', 'officers']) ? 'active' : '' ?>">

              <span class="micon dw dw-user"></span>

              <span class="mtext">User Management</span>

            </a>

            <ul class="submenu" style="<?= isMenuActive(    ['users-management.php', 'staff_management.php', 'login_security.php'],

    ['homeowners', 'officers']) ? 'display:block;' : '' ?>">

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

              <li>

  <a

    href="login_security.php"

    class="menu-access-link <?= $currentPage === 'login_security.php' ? 'active' : '' ?>"

    data-module="user_management"

  >

    Login Security

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

        <?php if ($showVotingRequest): ?>
          <li>
            <a href="voting_request.php"
               class="dropdown-toggle no-arrow <?= $currentPage === 'voting_request.php' ? 'active' : '' ?>">
              <span class="micon dw dw-check"></span>
              <span class="mtext">Voting Request</span>
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

        <?php if ($showCctv): ?>

          <li>

            <a href="cctv_monitoring.php"

               class="dropdown-toggle no-arrow <?= $currentPage === 'cctv_monitoring.php' ? 'active' : '' ?>">

              <span class="micon dw dw-video-camera"></span>

              <span class="mtext">CCTV Monitoring</span>

            </a>

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



        <?php endif; ?>

        <?php if ($showSettings): ?>



        <?php endif; ?>

      </ul>

    </div>

  </div>

</div>

<script src="realtime.php?client=5" defer></script>

<script src="vendors/scripts/global_realtime_notifications.js?v=1" defer></script>
