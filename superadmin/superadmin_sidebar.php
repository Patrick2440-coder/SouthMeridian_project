<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<aside>
  <div class="left-side-bar">
    <div class="brand-logo" style="display:flex; justify-content:center; align-items:center; position:relative;">
      <a href="dashboard.php" style="display:flex; justify-content:center; align-items:center; width:100%;">
        <img src="../assets/img/sm_logo.png" class="dark-logo" style="height:70px; width:auto; display:block; margin:0 auto;">
      </a>
      <div class="close-sidebar" data-toggle="left-sidebar-close" style="position:absolute; right:10px; top:50%; transform:translateY(-50%);">
        <i class="ion-close-round"></i>
      </div>
    </div>

    <div class="menu-block customscroll">
      <div class="sidebar-menu">
        <ul id="accordion-menu">
          <li>
            <a href="dashboard.php" class="dropdown-toggle no-arrow <?= ($currentPage === 'dashboard.php') ? 'active' : '' ?>">
              <span class="micon dw dw-house-1"></span>
              <span class="mtext">Dashboard</span>
            </a>
          </li>

          <li class="dropdown <?= in_array($currentPage, ['user_management.php', 'phase_management.php']) ? 'show' : '' ?>">
            <a href="javascript:;" class="dropdown-toggle">
              <span class="micon dw dw-user1"></span>
              <span class="mtext">User Management</span>
            </a>
            <ul class="submenu" style="<?= in_array($currentPage, ['user_management.php', 'phase_management.php']) ? 'display:block;' : '' ?>">
              <li>
                <a href="user_management.php" class="<?= ($currentPage === 'user_management.php') ? 'active' : '' ?>">
                  Homeowners
                </a>
              </li>
              <li>
                <a href="phase_management.php" class="<?= ($currentPage === 'phase_management.php') ? 'active' : '' ?>">
                  Officers
                </a>
              </li>
            </ul>
          </li>

          <li>
            <a href="access_control.php" class="dropdown-toggle no-arrow <?= ($currentPage === 'access_control.php') ? 'active' : '' ?>">
              <span class="micon dw dw-padlock1"></span>
              <span class="mtext">Access Control</span>
            </a>
          </li>

          <li>
            <a href="announcements.php" class="dropdown-toggle no-arrow <?= ($currentPage === 'announcements.php') ? 'active' : '' ?>">
              <span class="micon dw dw-notification"></span>
              <span class="mtext">Announcements</span>
            </a>
          </li>

          <li>
            <a href="voting.php" class="dropdown-toggle no-arrow <?= ($currentPage === 'voting.php') ? 'active' : '' ?>">
              <span class="micon dw dw-check"></span>
              <span class="mtext">Voting Management</span>
            </a>
          </li>
        </ul>
      </div>
    </div>
  </div>
</aside>