<?php
// NOTE: $conn, $activePage, $initials, $fullName, $phase, $user must already be set
// and in pages with tenant support, $isTenant and $tenant should also be available.

$parkingPages = ['homeowner_parking.php', 'homeowner_parking_permit.php', 'homeowner_parking_violations.php'];
$parkingOpen  = in_array($activePage, $parkingPages, true);

$tenantPages = ['homeowner_tenant.php', 'homeowner_tenant_register.php'];
$tenantOpen  = in_array($activePage, $tenantPages, true);

$isTenant = isset($isTenant) ? (bool)$isTenant : false;
$tenant   = isset($tenant) ? $tenant : null;

function sb_tenant_can_access(string $module, ?array $tenant, bool $isTenant): bool {
    if (!$isTenant) return true;
    if (!$tenant) return false;

    $map = [
        'dashboard'     => true,
        'announcements' => !empty($tenant['can_announcements']),
        'pay_dues'      => !empty($tenant['can_pay_dues']),
        'rentals'       => !empty($tenant['can_rent']),
        'parking'       => !empty($tenant['can_parking']),
        'complaints'    => true,
        'public_chat'   => true,
        'voting'        => false,
        'tenant_mgmt'   => false,
    ];

    return $map[$module] ?? false;
}

$tenantCount = 0;
if (!$isTenant && isset($conn) && isset($_SESSION['homeowner_id'])) {
    $hid  = (int)$_SESSION['homeowner_id'];
    $tcnt = $conn->prepare("SELECT COUNT(*) FROM tenants WHERE homeowner_id = ? AND status = 'active'");
    $tcnt->bind_param("i", $hid);
    $tcnt->execute();
    $tcnt->bind_result($tenantCount);
    $tcnt->fetch();
    $tcnt->close();
}
?>

<aside class="sidebar" id="sidebar">
  <div class="sb-head">
    <div class="sb-brand">
      <i class="bi bi-grid-fill"></i>
      <span class="sb-brand-text">HOA Menu</span>
    </div>
  </div>

  <div class="sb-user">
    <div class="sb-avatar"><?= esc($initials) ?></div>
    <div class="sb-user-text">
      <p class="sb-name"><?= esc($fullName) ?></p>
      <p class="sb-meta">
        <?= esc($phase) ?> • <?= esc($user['house_lot_number'] ?? '') ?>
        <?= $isTenant ? ' • Tenant' : '' ?>
      </p>
    </div>
  </div>

  <nav class="sb-nav">

    <!-- Dashboard -->
    <a class="sb-link <?= $activePage === 'homeowner_dashboard.php' ? 'active' : '' ?>" href="homeowner_dashboard.php">
      <i class="bi bi-house-door-fill"></i> <span>Dashboard</span>
    </a>

    <!-- Announcements -->
    <?php if (sb_tenant_can_access('announcements', $tenant, $isTenant)): ?>
      <a class="sb-link" href="homeowner_dashboard.php#feed">
        <i class="bi bi-megaphone-fill"></i> <span>Announcement Feed</span>
      </a>
    <?php endif; ?>

    <!-- Public Chat -->
    <?php if (sb_tenant_can_access('public_chat', $tenant, $isTenant)): ?>
      <a class="sb-link <?= $activePage === 'homeowner_public_chat.php' ? 'active' : '' ?>" href="homeowner_public_chat.php">
        <i class="bi bi-people-fill"></i> <span>Public Chat</span>
      </a>
    <?php endif; ?>

    <!-- Pay Monthly Dues -->
    <?php if (sb_tenant_can_access('pay_dues', $tenant, $isTenant)): ?>
      <a class="sb-link <?= $activePage === 'homeowner_pay_dues.php' ? 'active' : '' ?>" href="homeowner_pay_dues.php">
        <i class="bi bi-cash-coin"></i> <span>Pay Monthly Dues</span>
      </a>
    <?php endif; ?>

    <!-- Parking -->
    <?php if (sb_tenant_can_access('parking', $tenant, $isTenant)): ?>
      <div class="sb-dd <?= $parkingOpen ? 'open' : '' ?>" id="sbParking">
        <a class="sb-link sb-dd-toggle <?= $parkingOpen ? 'active' : '' ?>"
           href="javascript:void(0)" id="sbParkingToggle">
          <span><i class="bi bi-car-front-fill"></i> <span>Parking</span></span>
          <i class="bi bi-chevron-down sb-dd-caret"></i>
        </a>
        <div class="sb-dd-menu">
          <a class="sb-link <?= $activePage === 'homeowner_parking.php' ? 'active' : '' ?>" href="homeowner_parking.php">
            <i class="bi bi-info-circle-fill"></i> <span>Parking Overview</span>
          </a>
          <a class="sb-link <?= $activePage === 'homeowner_parking_permit.php' ? 'active' : '' ?>" href="homeowner_parking_permit.php">
            <i class="bi bi-card-checklist"></i> <span>Apply / Renew Permit</span>
          </a>
          <a class="sb-link <?= $activePage === 'homeowner_parking_violations.php' ? 'active' : '' ?>" href="homeowner_parking_violations.php">
            <i class="bi bi-receipt-cutoff"></i> <span>My Violations</span>
          </a>
        </div>
      </div>
    <?php endif; ?>

    <!-- Facility Rentals -->
    <?php if (sb_tenant_can_access('rentals', $tenant, $isTenant)): ?>
      <a class="sb-link <?= $activePage === 'homeowner_rentals.php' ? 'active' : '' ?>" href="homeowner_rentals.php">
        <i class="bi bi-calendar2-week-fill"></i> <span>Facility Rentals</span>
      </a>
    <?php endif; ?>

    <!-- Complaints -->
    <?php if (sb_tenant_can_access('complaints', $tenant, $isTenant)): ?>
      <a class="sb-link <?= $activePage === 'homeowner_complaints.php' ? 'active' : '' ?>" href="homeowner_complaints.php">
        <i class="bi bi-chat-left-text-fill"></i> <span>File a Complaint</span>
      </a>
    <?php endif; ?>

    <!-- Voting -->
    <?php if (sb_tenant_can_access('voting', $tenant, $isTenant)): ?>
      <a class="sb-link <?= $activePage === 'homeowner_voting.php' ? 'active' : '' ?>" href="homeowner_voting.php">
        <i class="bi bi-check2-square"></i> <span>Voting</span>
      </a>
    <?php endif; ?>

    <!-- Tenant management: homeowner only -->
    <?php if (!$isTenant): ?>
      <div class="sb-section-label" style="
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: var(--sb-muted, #9ca3af);
        padding: 14px 18px 4px;
        margin-top: 6px;
      ">Unit</div>

      <div class="sb-dd <?= $tenantOpen ? 'open' : '' ?>" id="sbTenant">
        <a class="sb-link sb-dd-toggle <?= $tenantOpen ? 'active' : '' ?>"
           href="javascript:void(0)" id="sbTenantToggle">
          <span>
            <i class="bi bi-person-badge-fill"></i>
            <span>My Tenant</span>
          </span>
          <?php if ($tenantCount > 0): ?>
            <span class="badge rounded-pill bg-success ms-auto" style="font-size:.65rem;">
              <?= $tenantCount ?>
            </span>
          <?php endif; ?>
          <i class="bi bi-chevron-down sb-dd-caret"></i>
        </a>

        <div class="sb-dd-menu">
          <a class="sb-link <?= $activePage === 'homeowner_tenant.php' ? 'active' : '' ?>"
             href="homeowner_tenant.php">
            <i class="bi bi-list-ul"></i>
            <span>Manage Tenants</span>
          </a>

          <a class="sb-link <?= $activePage === 'homeowner_tenant_register.php' ? 'active' : '' ?>"
             href="homeowner_tenant_register.php">
            <i class="bi bi-person-plus-fill"></i>
            <span>Register Tenant</span>
          </a>
        </div>
      </div>
    <?php endif; ?>

    <!-- Logout -->
    <a class="sb-link" href="logout.php" style="margin-top: 10px;">
      <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
    </a>

  </nav>
</aside>