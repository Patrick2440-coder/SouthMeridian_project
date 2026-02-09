<?php
// finance_helpers.php
if (session_status() === PHP_SESSION_NONE) session_start();

function db_conn(): mysqli {
  $conn = new mysqli("localhost", "root", "", "south_meridian_hoa");
  if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);
  $conn->set_charset("utf8mb4");
  return $conn;
}

function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function require_admin() {
  // Adjust if your project uses different session keys
  $role = $_SESSION['admin_role'] ?? $_SESSION['role'] ?? null;
  if (!in_array($role, ['admin','superadmin'], true)) {
    echo "<script>alert('Access denied'); window.location='index.php';</script>";
    exit;
  }
}

function admin_id(): int {
  return (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
}

function admin_phase(mysqli $conn): string {
  $aid = admin_id();
  $stmt = $conn->prepare("SELECT phase FROM admins WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $aid);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row['phase'] ?? 'Phase 1';
}

function admin_email(mysqli $conn): string {
  $aid = admin_id();
  $stmt = $conn->prepare("SELECT email FROM admins WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $aid);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row['email'] ?? '';
}

/**
 * NEW: admin real name (full_name) to show in UI.
 * Fallback to email if full_name is empty.
 */
function admin_name(mysqli $conn): string {
  $aid = admin_id();
  $stmt = $conn->prepare("SELECT full_name, email FROM admins WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $aid);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: [];
  $stmt->close();

  $name = trim((string)($row['full_name'] ?? ''));
  if ($name !== '') return $name;

  return (string)($row['email'] ?? 'ADMIN');
}

/**
 * NEW: get assigned President for the phase (active).
 */
function president_info(mysqli $conn, string $phase): array {
  $stmt = $conn->prepare("
    SELECT officer_name, officer_email
    FROM hoa_officers
    WHERE phase=? AND position='President' AND is_active=1
    LIMIT 1
  ");
  $stmt->bind_param("s", $phase);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: [];
  $stmt->close();

  return [
    'name'  => trim((string)($row['officer_name'] ?? '')),
    'email' => trim((string)($row['officer_email'] ?? '')),
  ];
}

/**
 * UPDATED President approval rule (STRICT):
 * - superadmin is NOT automatically president
 * - must match hoa_officers President email for that phase (and active)
 */
function is_president(mysqli $conn, string $phase): bool {
  $email = admin_email($conn);
  if ($email === '') return false;

  $stmt = $conn->prepare("
    SELECT 1
    FROM hoa_officers
    WHERE phase=? AND position='President' AND officer_email=? AND is_active=1
    LIMIT 1
  ");
  $stmt->bind_param("ss", $phase, $email);
  $stmt->execute();
  $ok = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $ok;
}

function phase_scope_clause(string $phase): array {
  // Superadmin can choose phase via GET, else locked to admin phase
  $role = $_SESSION['admin_role'] ?? $_SESSION['role'] ?? '';
  if ($role === 'superadmin') {
    $p = $_GET['phase'] ?? 'Phase 1';
    if (!in_array($p, ['Phase 1','Phase 2','Phase 3'], true)) $p = 'Phase 1';
    return [$p, true];
  }
  // normal admin locked
  return [$phase, false];
}
