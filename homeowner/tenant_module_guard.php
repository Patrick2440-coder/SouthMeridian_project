<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function tenant_guard(string $module, ?array $tenant = null): void
{
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'tenant') {
        return; // homeowners are allowed
    }

    if (!$tenant || empty($_SESSION['tenant_id'])) {
        $_SESSION['access_denied'] = 'Session expired. Please log in again.';
        header("Location: homeowner_dashboard.php");
        exit;
    }

    $allowed = [
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

    if (empty($allowed[$module])) {
        $_SESSION['access_denied'] = 'You do not have access to that module.';
        header("Location: homeowner_dashboard.php");
        exit;
    }
}