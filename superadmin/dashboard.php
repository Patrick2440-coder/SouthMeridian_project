<?php
session_start();


mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../config/database.php';

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function nfmt($value): string
{
    return number_format((float)$value, 0);
}

function money($value): string
{
    return '₱' . number_format((float)$value, 2);
}

function nice_date($value, bool $withTime = true): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }

    return date($withTime ? 'M d, Y h:i A' : 'M d, Y', $ts);
}

function table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();

    return $exists;
}

function scalar_value(mysqli $conn, string $sql, string $column = 'value')
{
    $result = $conn->query($sql);
    $row = $result ? $result->fetch_assoc() : null;

    if ($result) {
        $result->close();
    }

    return $row[$column] ?? 0;
}

/*
|--------------------------------------------------------------------------
| SUPERADMIN GUARD
|--------------------------------------------------------------------------
*/
$adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
$adminRole = (string)($_SESSION['admin_role'] ?? $_SESSION['role'] ?? '');

if ($adminId <= 0 || $adminRole !== 'superadmin') {
    header('Location: ../index.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT id, email, full_name, phase, role, position
    FROM admins
    WHERE id = ?
      AND role = 'superadmin'
    LIMIT 1
");
$stmt->bind_param('i', $adminId);
$stmt->execute();
$superadmin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$superadmin) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$adminDisplayName = trim((string)($superadmin['full_name'] ?? ''));
if ($adminDisplayName === '') {
    $adminDisplayName = 'Superadmin';
}

/*
|--------------------------------------------------------------------------
| HOMEOWNER OVERVIEW
|--------------------------------------------------------------------------
*/
$phases = ['Phase 1', 'Phase 2', 'Phase 3'];

$totalHomeowners = (int)scalar_value(
    $conn,
    "SELECT COUNT(*) AS value FROM homeowners"
);

$approvedHomeowners = (int)scalar_value(
    $conn,
    "SELECT COUNT(*) AS value FROM homeowners WHERE status='approved'"
);

$pendingHomeowners = (int)scalar_value(
    $conn,
    "SELECT COUNT(*) AS value FROM homeowners WHERE status='pending'"
);

$rejectedHomeowners = (int)scalar_value(
    $conn,
    "SELECT COUNT(*) AS value FROM homeowners WHERE status='rejected'"
);

$accountSetupPending = (int)scalar_value(
    $conn,
    "SELECT COUNT(*) AS value
     FROM homeowners
     WHERE status='approved'
       AND IFNULL(must_change_password, 1)=1"
);

$newHomeowners30d = (int)scalar_value(
    $conn,
    "SELECT COUNT(*) AS value
     FROM homeowners
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
);

$ownerCount = (int)scalar_value(
    $conn,
    "SELECT COUNT(*) AS value
     FROM homeowners
     WHERE residential_type='Owner'"
);

$renterCount = (int)scalar_value(
    $conn,
    "SELECT COUNT(*) AS value
     FROM homeowners
     WHERE residential_type='Renter/Tenant'"
);

$approvedRate = $totalHomeowners > 0
    ? round(($approvedHomeowners / $totalHomeowners) * 100)
    : 0;

/*
|--------------------------------------------------------------------------
| PHASE HOMEOWNER BREAKDOWN
|--------------------------------------------------------------------------
*/
$phaseStats = [];
foreach ($phases as $phase) {
    $phaseStats[$phase] = [
        'total' => 0,
        'approved' => 0,
        'pending' => 0,
        'rejected' => 0,
    ];
}

$res = $conn->query("
    SELECT phase, status, COUNT(*) AS c
    FROM homeowners
    GROUP BY phase, status
");

while ($row = $res->fetch_assoc()) {
    $phase = (string)$row['phase'];
    $status = (string)$row['status'];
    $count = (int)$row['c'];

    if (!isset($phaseStats[$phase])) {
        continue;
    }

    $phaseStats[$phase]['total'] += $count;

    if (array_key_exists($status, $phaseStats[$phase])) {
        $phaseStats[$phase][$status] = $count;
    }
}
$res->close();

/*
|--------------------------------------------------------------------------
| OFFICERS
|--------------------------------------------------------------------------
*/
$activeOfficers = 0;

if (table_exists($conn, 'hoa_officers')) {
    $activeOfficers = (int)scalar_value(
        $conn,
        "SELECT COUNT(*) AS value
         FROM hoa_officers
         WHERE is_active=1"
    );
} else {
    $activeOfficers = (int)scalar_value(
        $conn,
        "SELECT COUNT(*) AS value
         FROM admins
         WHERE role='admin'"
    );
}

/*
|--------------------------------------------------------------------------
| CURRENT OPERATION COUNTS
|--------------------------------------------------------------------------
*/
$collectionsMonth = 0.0;
$collections30d = 0.0;
$paidParticipants30d = 0;
$expensesMonth = 0.0;
$openComplaints = 0;
$pendingRentals = 0;
$pendingPermits = 0;
$openViolations = 0;
$announcements30d = 0;

if (table_exists($conn, 'finance_payments')) {
    $collectionsMonth = (float)scalar_value(
        $conn,
        "SELECT COALESCE(SUM(amount),0) AS value
         FROM finance_payments
         WHERE status='paid'
           AND YEAR(paid_at)=YEAR(CURDATE())
           AND MONTH(paid_at)=MONTH(CURDATE())"
    );

    $collections30d = (float)scalar_value(
        $conn,
        "SELECT COALESCE(SUM(amount),0) AS value
         FROM finance_payments
         WHERE status='paid'
           AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );

    $paidParticipants30d = (int)scalar_value(
        $conn,
        "SELECT COUNT(DISTINCT homeowner_id) AS value
         FROM finance_payments
         WHERE status='paid'
           AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
}

if (table_exists($conn, 'finance_expenses')) {
    $expensesMonth = (float)scalar_value(
        $conn,
        "SELECT COALESCE(SUM(amount),0) AS value
         FROM finance_expenses
         WHERE YEAR(expense_date)=YEAR(CURDATE())
           AND MONTH(expense_date)=MONTH(CURDATE())"
    );
}

if (table_exists($conn, 'complaints')) {
    $openComplaints = (int)scalar_value(
        $conn,
        "SELECT COUNT(*) AS value
         FROM complaints
         WHERE status IN ('open','in_progress')"
    );
}

if (table_exists($conn, 'facility_rental_requests')) {
    $pendingRentals = (int)scalar_value(
        $conn,
        "SELECT COUNT(*) AS value
         FROM facility_rental_requests
         WHERE status='pending'"
    );
}

if (table_exists($conn, 'parking_permits')) {
    $pendingPermits = (int)scalar_value(
        $conn,
        "SELECT COUNT(*) AS value
         FROM parking_permits
         WHERE status='pending'"
    );
}

if (table_exists($conn, 'parking_violations')) {
    $openViolations = (int)scalar_value(
        $conn,
        "SELECT COUNT(*) AS value
         FROM parking_violations
         WHERE status='open'"
    );
}

if (table_exists($conn, 'announcements')) {
    $announcements30d = (int)scalar_value(
        $conn,
        "SELECT COUNT(*) AS value
         FROM announcements
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
}

$totalAttention =
    $pendingHomeowners +
    $pendingPermits +
    $pendingRentals +
    $openComplaints +
    $openViolations;

/*
|--------------------------------------------------------------------------
| SIX-MONTH FINANCE CHART
|--------------------------------------------------------------------------
*/
$labels = [];
$monthKeys = [];

for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime(date('Y-m-01') . " -{$i} months");
    $labels[] = date('M Y', $ts);
    $monthKeys[] = date('Y-m', $ts);
}

$collectionsByMonth = array_fill_keys($monthKeys, 0.0);
$expensesByMonth = array_fill_keys($monthKeys, 0.0);
$newHomeownersByMonth = array_fill_keys($monthKeys, 0);

$fromDate = date(
    'Y-m-01',
    strtotime(date('Y-m-01') . ' -5 months')
);

if (table_exists($conn, 'finance_payments')) {
    $stmt = $conn->prepare("
        SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym,
               COALESCE(SUM(amount),0) AS total
        FROM finance_payments
        WHERE status='paid'
          AND paid_at >= ?
        GROUP BY ym
    ");
    $stmt->bind_param('s', $fromDate);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $ym = (string)$row['ym'];
        if (array_key_exists($ym, $collectionsByMonth)) {
            $collectionsByMonth[$ym] = (float)$row['total'];
        }
    }
    $stmt->close();
}

if (table_exists($conn, 'finance_expenses')) {
    $stmt = $conn->prepare("
        SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym,
               COALESCE(SUM(amount),0) AS total
        FROM finance_expenses
        WHERE expense_date >= ?
        GROUP BY ym
    ");
    $stmt->bind_param('s', $fromDate);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $ym = (string)$row['ym'];
        if (array_key_exists($ym, $expensesByMonth)) {
            $expensesByMonth[$ym] = (float)$row['total'];
        }
    }
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym,
           COUNT(*) AS total
    FROM homeowners
    WHERE created_at >= ?
    GROUP BY ym
");
$stmt->bind_param('s', $fromDate);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $ym = (string)$row['ym'];
    if (array_key_exists($ym, $newHomeownersByMonth)) {
        $newHomeownersByMonth[$ym] = (int)$row['total'];
    }
}
$stmt->close();

$chartCollections = array_values($collectionsByMonth);
$chartExpenses = array_values($expensesByMonth);
$chartNewHomeowners = array_values($newHomeownersByMonth);

/*
|--------------------------------------------------------------------------
| COMPLETE HOMEOWNER DIRECTORY
|--------------------------------------------------------------------------
*/
$homeowners = [];

$stmt = $conn->prepare("
    SELECT
        id,
        public_id,
        first_name,
        middle_name,
        last_name,
        contact_number,
        email,
        phase,
        house_lot_number,
        block,
        lot,
        street,
        residential_type,
        length_of_residency,
        emergency_contact_person,
        emergency_contact_number,
        status,
        IFNULL(must_change_password,1) AS must_change_password,
        created_at
    FROM homeowners
    ORDER BY created_at DESC
");
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $homeowners[] = $row;
}
$stmt->close();

/*
|--------------------------------------------------------------------------
| OFFICER DIRECTORY
|--------------------------------------------------------------------------
*/
$officers = [];

if (table_exists($conn, 'hoa_officers')) {
    $res = $conn->query("
        SELECT
            ho.id,
            ho.officer_name AS full_name,
            ho.officer_email AS email,
            ho.phase,
            ho.position,
            ho.is_active
        FROM hoa_officers ho
        ORDER BY ho.phase,
                 FIELD(
                    ho.position,
                    'President',
                    'Vice President',
                    'Secretary',
                    'Treasurer',
                    'Auditor',
                    'Board of Director'
                 ),
                 ho.officer_name
    ");

    while ($row = $res->fetch_assoc()) {
        $officers[] = $row;
    }
    $res->close();
} else {
    $res = $conn->query("
        SELECT
            id,
            full_name,
            email,
            phase,
            position,
            1 AS is_active
        FROM admins
        WHERE role='admin'
        ORDER BY phase, position, full_name
    ");

    while ($row = $res->fetch_assoc()) {
        $officers[] = $row;
    }
    $res->close();
}

/*
|--------------------------------------------------------------------------
| RECENT HOMEOWNER / COMMUNITY ACTIVITY
|--------------------------------------------------------------------------
*/
$communityActivities = [];

/* New homeowner records */
$res = $conn->query("
    SELECT
        id,
        first_name,
        middle_name,
        last_name,
        phase,
        status,
        created_at
    FROM homeowners
    ORDER BY created_at DESC
    LIMIT 20
");

while ($row = $res->fetch_assoc()) {
    $fullName = trim(
        (string)$row['first_name'] . ' ' .
        (string)$row['middle_name'] . ' ' .
        (string)$row['last_name']
    );

    $communityActivities[] = [
        'activity_at' => (string)$row['created_at'],
        'type' => 'Homeowner Record',
        'homeowner' => $fullName,
        'phase' => (string)$row['phase'],
        'details' => 'Homeowner record added · Status: ' . ucfirst((string)$row['status']),
        'status' => (string)$row['status'],
    ];
}
$res->close();

/* Dues payments */
if (table_exists($conn, 'finance_payments')) {
    $res = $conn->query("
        SELECT
            fp.amount,
            fp.status,
            fp.phase,
            fp.paid_at,
            fp.reference_no,
            h.first_name,
            h.middle_name,
            h.last_name
        FROM finance_payments fp
        LEFT JOIN homeowners h ON h.id = fp.homeowner_id
        ORDER BY fp.paid_at DESC
        LIMIT 20
    ");

    while ($row = $res->fetch_assoc()) {
        $fullName = trim(
            (string)$row['first_name'] . ' ' .
            (string)$row['middle_name'] . ' ' .
            (string)$row['last_name']
        );

        $details = 'Monthly dues · ' . money((float)$row['amount']);
        if (!empty($row['reference_no'])) {
            $details .= ' · Ref: ' . (string)$row['reference_no'];
        }

        $communityActivities[] = [
            'activity_at' => (string)$row['paid_at'],
            'type' => 'Dues Payment',
            'homeowner' => $fullName !== '' ? $fullName : 'Homeowner',
            'phase' => (string)$row['phase'],
            'details' => $details,
            'status' => (string)$row['status'],
        ];
    }
    $res->close();
}

/* Complaints */
if (table_exists($conn, 'complaints')) {
    $res = $conn->query("
        SELECT
            c.subject,
            c.status,
            c.priority,
            c.phase,
            c.created_at,
            h.first_name,
            h.middle_name,
            h.last_name
        FROM complaints c
        LEFT JOIN homeowners h ON h.id = c.homeowner_id
        ORDER BY c.created_at DESC
        LIMIT 20
    ");

    while ($row = $res->fetch_assoc()) {
        $fullName = trim(
            (string)$row['first_name'] . ' ' .
            (string)$row['middle_name'] . ' ' .
            (string)$row['last_name']
        );

        $communityActivities[] = [
            'activity_at' => (string)$row['created_at'],
            'type' => 'Complaint',
            'homeowner' => $fullName !== '' ? $fullName : 'Homeowner',
            'phase' => (string)$row['phase'],
            'details' =>
                (string)$row['subject'] .
                ' · Priority: ' .
                ucfirst((string)$row['priority']),
            'status' => (string)$row['status'],
        ];
    }
    $res->close();
}

/* Facility rentals */
if (table_exists($conn, 'facility_rental_requests')) {
    $res = $conn->query("
        SELECT
            f.facility,
            f.status,
            f.phase,
            f.start_dt,
            f.created_at,
            h.first_name,
            h.middle_name,
            h.last_name
        FROM facility_rental_requests f
        LEFT JOIN homeowners h ON h.id = f.homeowner_id
        ORDER BY f.created_at DESC
        LIMIT 20
    ");

    while ($row = $res->fetch_assoc()) {
        $fullName = trim(
            (string)$row['first_name'] . ' ' .
            (string)$row['middle_name'] . ' ' .
            (string)$row['last_name']
        );

        $facilityLabel = ucwords(
            str_replace('_', ' ', (string)$row['facility'])
        );

        $communityActivities[] = [
            'activity_at' => (string)$row['created_at'],
            'type' => 'Facility Rental',
            'homeowner' => $fullName !== '' ? $fullName : 'Homeowner',
            'phase' => (string)$row['phase'],
            'details' =>
                $facilityLabel .
                ' · Schedule: ' .
                nice_date((string)$row['start_dt']),
            'status' => (string)$row['status'],
        ];
    }
    $res->close();
}

/* Parking permit requests */
if (table_exists($conn, 'parking_permits')) {
    $res = $conn->query("
        SELECT
            p.plate_no,
            p.request_type,
            p.status,
            p.phase,
            p.requested_at,
            h.first_name,
            h.middle_name,
            h.last_name
        FROM parking_permits p
        LEFT JOIN homeowners h ON h.id = p.homeowner_id
        ORDER BY p.requested_at DESC
        LIMIT 20
    ");

    while ($row = $res->fetch_assoc()) {
        $fullName = trim(
            (string)$row['first_name'] . ' ' .
            (string)$row['middle_name'] . ' ' .
            (string)$row['last_name']
        );

        $communityActivities[] = [
            'activity_at' => (string)$row['requested_at'],
            'type' => 'Parking Permit',
            'homeowner' => $fullName !== '' ? $fullName : 'Homeowner',
            'phase' => (string)$row['phase'],
            'details' =>
                strtoupper((string)$row['plate_no']) .
                ' · ' .
                ucfirst((string)$row['request_type']) .
                ' request',
            'status' => (string)$row['status'],
        ];
    }
    $res->close();
}

usort(
    $communityActivities,
    static function (array $a, array $b): int {
        return strtotime((string)$b['activity_at']) <=> strtotime((string)$a['activity_at']);
    }
);

$communityActivities = array_slice($communityActivities, 0, 60);

/*
|--------------------------------------------------------------------------
| SYSTEM ACTIVITY LOG
|--------------------------------------------------------------------------
*/
$systemActivities = [];

if (table_exists($conn, 'activity_logs')) {
    $res = $conn->query("
        SELECT
            al.id,
            al.phase,
            al.action,
            al.module_key,
            al.details,
            al.ip_address,
            al.created_at,
            COALESCE(NULLIF(a.full_name,''), a.email, 'System') AS actor
        FROM activity_logs al
        LEFT JOIN admins a ON a.id = al.admin_id
        ORDER BY al.created_at DESC
        LIMIT 100
    ");

    while ($row = $res->fetch_assoc()) {
        $systemActivities[] = $row;
    }
    $res->close();
}

/*
|--------------------------------------------------------------------------
| RECENT ANNOUNCEMENTS
|--------------------------------------------------------------------------
*/
$recentAnnouncements = [];

if (table_exists($conn, 'announcements')) {
    $res = $conn->query("
        SELECT
            id,
            title,
            phase,
            category,
            priority,
            start_date,
            end_date,
            created_at
        FROM announcements
        ORDER BY created_at DESC
        LIMIT 10
    ");

    while ($row = $res->fetch_assoc()) {
        $recentAnnouncements[] = $row;
    }
    $res->close();
}
?>
<!DOCTYPE html>
<html>
<head>
  <?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>
  <meta charset="utf-8">
  <title>Superadmin Dashboard</title>



  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="../admin/src/plugins/datatables/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="../admin/src/plugins/datatables/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/style.css">

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    :root {
      --smh-green: #077f46;
      --smh-border: #e5e7eb;
      --smh-muted: #64748b;
      --smh-soft: #f8fafc;
    }

    .dashboard-hero {
      overflow: hidden;
      position: relative;
      border-radius: 14px;
    }

    .dashboard-hero .hero-copy {
      position: relative;
      z-index: 2;
    }

    .hero-eyebrow {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .08em;
      font-weight: 800;
      color: var(--smh-green);
      margin-bottom: 5px;
    }

    .dashboard-section-title {
      font-size: 18px;
      font-weight: 800;
      margin: 0;
    }

    .dashboard-section-subtitle {
      margin-top: 3px;
      font-size: 13px;
      color: var(--smh-muted);
    }

    .stat-card {
      height: 100%;
      padding: 18px;
      border: 1px solid var(--smh-border);
      border-radius: 14px;
      background: #fff;
      transition: transform .15s ease, box-shadow .15s ease;
    }

    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 24px rgba(15, 23, 42, .06);
    }

    .stat-icon {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 21px;
      background: #f1f5f9;
    }

    .stat-value {
      font-size: 27px;
      line-height: 1.1;
      font-weight: 800;
      margin-top: 13px;
    }

    .stat-label {
      margin-top: 4px;
      font-weight: 700;
      color: #334155;
    }

    .stat-note {
      margin-top: 5px;
      color: var(--smh-muted);
      font-size: 12px;
      line-height: 1.4;
    }

    .badge-soft {
      display: inline-block;
      padding: .32rem .58rem;
      border-radius: 999px;
      font-weight: 700;
      font-size: 11px;
      white-space: nowrap;
    }

    .badge-soft-success {
      background: #ecfdf5;
      border: 1px solid #bbf7d0;
      color: #166534;
    }

    .badge-soft-warning {
      background: #fff7ed;
      border: 1px solid #fed7aa;
      color: #9a3412;
    }

    .badge-soft-danger {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #991b1b;
    }

    .badge-soft-info {
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      color: #1d4ed8;
    }

    .badge-soft-secondary {
      background: #f1f5f9;
      border: 1px solid #cbd5e1;
      color: #475569;
    }

    .phase-overview {
      border: 1px solid var(--smh-border);
      border-radius: 12px;
      padding: 14px;
      height: 100%;
      background: #fff;
    }

    .phase-overview h6 {
      font-weight: 800;
      margin-bottom: 12px;
    }

    .phase-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 8px;
      font-size: 13px;
    }

    .phase-row:last-child {
      margin-bottom: 0;
    }

    .quick-link {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 11px 12px;
      border: 1px solid var(--smh-border);
      border-radius: 10px;
      color: #334155;
      font-weight: 700;
      margin-bottom: 8px;
      transition: background .15s ease, border-color .15s ease;
    }

    .quick-link:hover {
      color: var(--smh-green);
      background: #f8fafc;
      border-color: #cbd5e1;
      text-decoration: none;
    }

    .quick-link:last-child {
      margin-bottom: 0;
    }

    .section-card {
      border-radius: 14px;
      overflow: hidden;
    }

    .activity-table td,
    .activity-table th,
    .directory-table td,
    .directory-table th {
      vertical-align: middle !important;
    }

    .activity-type {
      font-weight: 700;
      color: #0f172a;
    }

    .activity-details {
      max-width: 420px;
      white-space: normal;
      color: #475569;
      font-size: 13px;
    }

    .person-main {
      font-weight: 700;
      color: #0f172a;
    }

    .person-sub {
      color: var(--smh-muted);
      font-size: 12px;
      margin-top: 2px;
    }

    .table thead th {
      white-space: nowrap;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .02em;
    }

    .directory-table td {
      white-space: nowrap;
    }

    .directory-table .wrap-cell {
      white-space: normal;
      min-width: 180px;
    }

    .chart-box {
      position: relative;
      min-height: 320px;
    }

    .mini-note {
      color: var(--smh-muted);
      font-size: 12px;
    }

    .empty-state {
      padding: 35px 15px;
      text-align: center;
      color: var(--smh-muted);
    }

    .summary-strip {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
    }

    .summary-strip-item {
      padding: 11px 12px;
      background: var(--smh-soft);
      border: 1px solid var(--smh-border);
      border-radius: 10px;
    }

    .summary-strip-item strong {
      display: block;
      font-size: 18px;
      color: #0f172a;
    }

    .summary-strip-item span {
      display: block;
      margin-top: 2px;
      color: var(--smh-muted);
      font-size: 11px;
      font-weight: 700;
    }

    @media (max-width: 991.98px) {
      .summary-strip {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 575.98px) {
      .summary-strip {
        grid-template-columns: 1fr;
      }

      .stat-value {
        font-size: 24px;
      }
    }
  </style>
</head>

<body>
  <div class="header">
    <div class="header-left">
      <div class="menu-icon dw dw-menu"></div>
    </div>

    <div class="header-right">
      <div class="user-notification">
        <div class="dropdown">
          <a class="dropdown-toggle no-arrow" href="#" role="button" data-toggle="dropdown">
            <i class="icon-copy dw dw-notification"></i>
            <?php if ($totalAttention > 0): ?>
              <span class="badge notification-active"></span>
            <?php endif; ?>
          </a>

          <div class="dropdown-menu dropdown-menu-right">
            <div class="notification-list mx-h-350 customscroll">
              <ul>
                <li>
                  <a href="user_management.php">
                    <h3>Homeowner Records</h3>
                    <p><?= nfmt($pendingHomeowners) ?> pending review</p>
                  </a>
                </li>

                <li>
                  <a href="#">
                    <h3>Complaints</h3>
                    <p><?= nfmt($openComplaints) ?> open or in progress</p>
                  </a>
                </li>

                <li>
                  <a href="#">
                    <h3>Facility Rentals</h3>
                    <p><?= nfmt($pendingRentals) ?> pending request(s)</p>
                  </a>
                </li>

                <li>
                  <a href="#">
                    <h3>Parking</h3>
                    <p><?= nfmt($pendingPermits) ?> pending permit(s), <?= nfmt($openViolations) ?> open violation(s)</p>
                  </a>
                </li>

                <?php if ($totalAttention === 0): ?>
                  <li>
                    <a href="#">
                      <h3>No Pending Items</h3>
                      <p>There are no items needing immediate attention.</p>
                    </a>
                  </li>
                <?php endif; ?>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="user-info-dropdown">
        <div class="dropdown">
          <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
            <span class="user-icon">
              <img src="../admin/vendors/images/photo1.jpg" alt="">
            </span>
            <span class="user-name"><?= esc($adminDisplayName) ?></span>
          </a>

          <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
            <a class="dropdown-item" href="profile.html">
              <i class="dw dw-user1"></i> Profile
            </a>
            <a class="dropdown-item" href="logs.php">
              <i class="dw dw-list3"></i> Activity Logs
            </a>
            <a class="dropdown-item" href="../index.php">
              <i class="dw dw-logout"></i> Log Out
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php include 'superadmin_sidebar.php'; ?>

  <div class="mobile-menu-overlay"></div>

  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="page-header mb-20">
        <div class="row">
          <div class="col-md-12 col-sm-12">
            <div class="title">
              <h4>Superadmin Dashboard</h4>
            </div>
            <div class="text-secondary">
              System-wide homeowner information, community activity, and HOA operations across all phases.
            </div>
          </div>
        </div>
      </div>

      <!-- HERO -->
      <div class="card-box pd-20 mb-30 dashboard-hero">
        <div class="row align-items-center">
          <div class="col-lg-8 col-md-7 hero-copy">
            <div class="hero-eyebrow">South Meridian Homes</div>
            <h3 class="mb-2">Community Overview</h3>
            <p class="mb-3 text-secondary">
              View homeowner records, phase distribution, officer assignments, finances, requests, and recent system activity in one place.
            </p>

            <div class="summary-strip">
              <div class="summary-strip-item">
                <strong><?= nfmt($totalHomeowners) ?></strong>
                <span>Total Homeowners</span>
              </div>

              <div class="summary-strip-item">
                <strong><?= nfmt($activeOfficers) ?></strong>
                <span>Active Officers</span>
              </div>

              <div class="summary-strip-item">
                <strong><?= nfmt($newHomeowners30d) ?></strong>
                <span>New Records · 30 Days</span>
              </div>

              <div class="summary-strip-item">
                <strong><?= nfmt($totalAttention) ?></strong>
                <span>Items Needing Attention</span>
              </div>
            </div>
          </div>

          <div class="col-lg-4 col-md-5 text-center mt-3 mt-md-0">
            <img
              src="../admin/vendors/images/banner-img.png"
              alt="South Meridian dashboard"
              style="max-height:210px;max-width:100%;"
            >
          </div>
        </div>
      </div>

      <!-- HOMEOWNER INFORMATION -->
      <div class="d-flex justify-content-between align-items-end mb-15 flex-wrap">
        <div>
          <h5 class="dashboard-section-title">Homeowner Information</h5>
          <div class="dashboard-section-subtitle">
            Current homeowner population and account status across all phases.
          </div>
        </div>

        <a href="user_management.php" class="btn btn-sm btn-outline-primary mt-2 mt-md-0">
          Open Homeowner Management
        </a>
      </div>

      <div class="row">
        <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-primary"><i class="dw dw-group"></i></div>
            <div class="stat-value"><?= nfmt($totalHomeowners) ?></div>
            <div class="stat-label">Total Homeowners</div>
            <div class="stat-note">All homeowner records in the system.</div>
          </div>
        </div>

        <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-success"><i class="dw dw-check"></i></div>
            <div class="stat-value"><?= nfmt($approvedHomeowners) ?></div>
            <div class="stat-label">Active / Approved</div>
            <div class="stat-note"><?= (int)$approvedRate ?>% of all homeowner records.</div>
          </div>
        </div>

        <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-warning"><i class="dw dw-hourglass"></i></div>
            <div class="stat-value"><?= nfmt($pendingHomeowners) ?></div>
            <div class="stat-label">Pending Review</div>
            <div class="stat-note">Records not yet finalized or approved.</div>
          </div>
        </div>

        <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-danger"><i class="dw dw-cancel"></i></div>
            <div class="stat-value"><?= nfmt($rejectedHomeowners) ?></div>
            <div class="stat-label">Rejected</div>
            <div class="stat-note">Homeowner records marked rejected.</div>
          </div>
        </div>

        <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-info"><i class="dw dw-key"></i></div>
            <div class="stat-value"><?= nfmt($accountSetupPending) ?></div>
            <div class="stat-label">Account Setup</div>
            <div class="stat-note">Approved homeowners who still need to set a password.</div>
          </div>
        </div>

        <div class="col-xl-2 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-success"><i class="dw dw-home"></i></div>
            <div class="stat-value"><?= nfmt($ownerCount) ?></div>
            <div class="stat-label">Owners</div>
            <div class="stat-note"><?= nfmt($renterCount) ?> renter/tenant record(s).</div>
          </div>
        </div>
      </div>

      <!-- PHASES + QUICK LINKS -->
      <div class="row">
        <div class="col-xl-8 col-lg-7 mb-30">
          <div class="card-box pd-20 height-100-p section-card">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
              <div>
                <h5 class="dashboard-section-title">Homeowners by Phase</h5>
                <div class="dashboard-section-subtitle">
                  Total, active, pending, and rejected homeowner records.
                </div>
              </div>
            </div>

            <div class="row">
              <?php foreach ($phases as $phase): ?>
                <div class="col-lg-4 col-md-6 mb-3 mb-lg-0">
                  <div class="phase-overview">
                    <h6><?= esc($phase) ?></h6>

                    <div class="phase-row">
                      <span>Total</span>
                      <strong><?= nfmt($phaseStats[$phase]['total']) ?></strong>
                    </div>

                    <div class="phase-row">
                      <span>Active / Approved</span>
                      <span class="badge-soft badge-soft-success">
                        <?= nfmt($phaseStats[$phase]['approved']) ?>
                      </span>
                    </div>

                    <div class="phase-row">
                      <span>Pending</span>
                      <span class="badge-soft badge-soft-warning">
                        <?= nfmt($phaseStats[$phase]['pending']) ?>
                      </span>
                    </div>

                    <div class="phase-row">
                      <span>Rejected</span>
                      <span class="badge-soft badge-soft-danger">
                        <?= nfmt($phaseStats[$phase]['rejected']) ?>
                      </span>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="col-xl-4 col-lg-5 mb-30">
          <div class="card-box pd-20 height-100-p section-card">
            <h5 class="dashboard-section-title mb-3">Quick Access</h5>

            <a href="user_management.php" class="quick-link">
              <i class="dw dw-user-2"></i>
              <span>Homeowner Management</span>
            </a>

            <a href="phase_management.php" class="quick-link">
              <i class="dw dw-group"></i>
              <span>Officer Management</span>
            </a>

            <a href="announcements.php" class="quick-link">
              <i class="dw dw-megaphone"></i>
              <span>Announcements</span>
            </a>

            <a href="access_control.php" class="quick-link">
              <i class="dw dw-lock"></i>
              <span>Access Control Overview</span>
            </a>
          </div>
        </div>
      </div>

      <!-- OPERATIONS / ACTIVITY KPIs -->
      <div class="mb-15">
        <h5 class="dashboard-section-title">Community Activity</h5>
        <div class="dashboard-section-subtitle">
          Current activity from payments, complaints, rentals, parking, and announcements.
        </div>
      </div>

      <div class="row">
        <div class="col-xl-3 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-success"><i class="dw dw-money-1"></i></div>
            <div class="stat-value"><?= money($collectionsMonth) ?></div>
            <div class="stat-label">Collections This Month</div>
            <div class="stat-note"><?= money($collections30d) ?> collected in the last 30 days.</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-danger"><i class="dw dw-wallet"></i></div>
            <div class="stat-value"><?= money($expensesMonth) ?></div>
            <div class="stat-label">Expenses This Month</div>
            <div class="stat-note">Recorded HOA expenses for the current month.</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-info"><i class="dw dw-user1"></i></div>
            <div class="stat-value"><?= nfmt($paidParticipants30d) ?></div>
            <div class="stat-label">Dues Payers · 30 Days</div>
            <div class="stat-note">Distinct homeowners with a paid dues record.</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-warning"><i class="dw dw-chat3"></i></div>
            <div class="stat-value"><?= nfmt($openComplaints) ?></div>
            <div class="stat-label">Open Complaints</div>
            <div class="stat-note">Open and in-progress complaints across all phases.</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-primary"><i class="dw dw-calendar1"></i></div>
            <div class="stat-value"><?= nfmt($pendingRentals) ?></div>
            <div class="stat-label">Pending Rentals</div>
            <div class="stat-note">Facility booking requests waiting for action.</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-info"><i class="dw dw-car"></i></div>
            <div class="stat-value"><?= nfmt($pendingPermits) ?></div>
            <div class="stat-label">Pending Parking</div>
            <div class="stat-note">Parking permit requests waiting for review.</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-danger"><i class="dw dw-warning"></i></div>
            <div class="stat-value"><?= nfmt($openViolations) ?></div>
            <div class="stat-label">Open Violations</div>
            <div class="stat-note">Parking violations that are still open.</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 mb-30">
          <div class="stat-card">
            <div class="stat-icon text-success"><i class="dw dw-megaphone"></i></div>
            <div class="stat-value"><?= nfmt($announcements30d) ?></div>
            <div class="stat-label">Announcements · 30 Days</div>
            <div class="stat-note">Announcements posted across the system.</div>
          </div>
        </div>
      </div>

      <!-- CHARTS -->
      <div class="row">
        <div class="col-xl-7 col-lg-12 mb-30">
          <div class="card-box pd-20 height-100-p section-card">
            <div class="mb-3">
              <h5 class="dashboard-section-title">Financial Activity</h5>
              <div class="dashboard-section-subtitle">
                Collections and expenses during the last six months.
              </div>
            </div>

            <div class="chart-box">
              <canvas id="financeChart"></canvas>
            </div>
          </div>
        </div>

        <div class="col-xl-5 col-lg-12 mb-30">
          <div class="card-box pd-20 height-100-p section-card">
            <div class="mb-3">
              <h5 class="dashboard-section-title">Homeowner Growth</h5>
              <div class="dashboard-section-subtitle">
                Number of homeowner records added during the last six months.
              </div>
            </div>

            <div class="chart-box">
              <canvas id="homeownerGrowthChart"></canvas>
            </div>
          </div>
        </div>
      </div>

      <!-- RECENT HOMEOWNER ACTIVITY -->
      <div class="card-box mb-30 p-3 section-card">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
          <div>
            <h5 class="dashboard-section-title">Recent Homeowner Activity</h5>
            <div class="dashboard-section-subtitle">
              Combined activity from homeowner records, dues, complaints, facility rentals, and parking permits.
            </div>
          </div>

          <span class="badge-soft badge-soft-info mt-2 mt-md-0">
            Latest <?= count($communityActivities) ?> activities
          </span>
        </div>

        <div class="table-responsive">
          <table id="communityActivityTable" class="table table-hover activity-table mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th>Activity</th>
                <th>Homeowner</th>
                <th>Phase</th>
                <th>Details</th>
                <th>Status</th>
              </tr>
            </thead>

            <tbody>
              <?php foreach ($communityActivities as $activity): ?>
                <?php
                  $activityStatus = strtolower((string)$activity['status']);
                  $activityBadge = 'badge-soft-secondary';

                  if (in_array($activityStatus, ['approved','paid','resolved','closed'], true)) {
                      $activityBadge = 'badge-soft-success';
                  } elseif (in_array($activityStatus, ['pending','in_progress'], true)) {
                      $activityBadge = 'badge-soft-warning';
                  } elseif (in_array($activityStatus, ['rejected','denied','cancelled','failed'], true)) {
                      $activityBadge = 'badge-soft-danger';
                  } elseif (in_array($activityStatus, ['open'], true)) {
                      $activityBadge = 'badge-soft-info';
                  }
                ?>
                <tr>
                  <td data-order="<?= esc($activity['activity_at']) ?>">
                    <?= esc(nice_date($activity['activity_at'])) ?>
                  </td>
                  <td class="activity-type"><?= esc($activity['type']) ?></td>
                  <td><?= esc($activity['homeowner']) ?></td>
                  <td><?= esc($activity['phase']) ?></td>
                  <td class="activity-details"><?= esc($activity['details']) ?></td>
                  <td>
                    <span class="badge-soft <?= esc($activityBadge) ?>">
                      <?= esc(ucwords(str_replace('_', ' ', $activityStatus))) ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$communityActivities): ?>
                <tr>
                  <td colspan="6" class="empty-state">No recent homeowner activity found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- COMPLETE HOMEOWNER DIRECTORY -->
      <div class="card-box mb-30 p-3 section-card">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
          <div>
            <h5 class="dashboard-section-title">Homeowner Directory</h5>
            <div class="dashboard-section-subtitle">
              Complete homeowner contact, property, residency, account, and emergency-contact information.
            </div>
          </div>

          <a class="btn btn-sm btn-outline-primary mt-2 mt-md-0" href="user_management.php">
            Open Full Management
          </a>
        </div>

        <div class="table-responsive">
          <table id="homeownersTable" class="table table-striped table-hover directory-table mb-0">
            <thead>
              <tr>
                <th>Public ID</th>
                <th>Homeowner</th>
                <th>Contact</th>
                <th>Email</th>
                <th>Phase</th>
                <th>Property</th>
                <th>Street</th>
                <th>Residence</th>
                <th>Residency</th>
                <th>Emergency Contact</th>
                <th>Account</th>
                <th>Status</th>
                <th>Added</th>
              </tr>
            </thead>

            <tbody>
              <?php foreach ($homeowners as $homeowner): ?>
                <?php
                  $fullName = trim(
                      (string)$homeowner['first_name'] . ' ' .
                      (string)$homeowner['middle_name'] . ' ' .
                      (string)$homeowner['last_name']
                  );

                  $property = '';
                  if (!empty($homeowner['block']) || !empty($homeowner['lot'])) {
                      $property =
                          'Block ' . (string)($homeowner['block'] ?? '—') .
                          ', Lot ' . (string)($homeowner['lot'] ?? '—');
                  } else {
                      $property = (string)$homeowner['house_lot_number'];
                  }

                  $status = strtolower((string)$homeowner['status']);
                  $statusBadge = 'badge-soft-secondary';

                  if ($status === 'approved') {
                      $statusBadge = 'badge-soft-success';
                  } elseif ($status === 'pending') {
                      $statusBadge = 'badge-soft-warning';
                  } elseif ($status === 'rejected') {
                      $statusBadge = 'badge-soft-danger';
                  }

                  $accountReady =
                      $status === 'approved' &&
                      (int)$homeowner['must_change_password'] === 0;
                ?>
                <tr>
                  <td><?= esc((string)($homeowner['public_id'] ?: $homeowner['id'])) ?></td>

                  <td>
                    <div class="person-main"><?= esc($fullName) ?></div>
                    <div class="person-sub">Record #<?= (int)$homeowner['id'] ?></div>
                  </td>

                  <td><?= esc((string)$homeowner['contact_number']) ?></td>
                  <td><?= esc((string)$homeowner['email']) ?></td>
                  <td><?= esc((string)$homeowner['phase']) ?></td>
                  <td><?= esc($property) ?></td>
                  <td><?= esc((string)($homeowner['street'] ?: '—')) ?></td>
                  <td><?= esc((string)$homeowner['residential_type']) ?></td>
                  <td><?= esc((string)($homeowner['length_of_residency'] ?: '—')) ?></td>

                  <td class="wrap-cell">
                    <div class="person-main">
                      <?= esc((string)($homeowner['emergency_contact_person'] ?: '—')) ?>
                    </div>
                    <div class="person-sub">
                      <?= esc((string)($homeowner['emergency_contact_number'] ?: '')) ?>
                    </div>
                  </td>

                  <td>
                    <?php if ($accountReady): ?>
                      <span class="badge-soft badge-soft-success">Ready</span>
                    <?php elseif ($status === 'approved'): ?>
                      <span class="badge-soft badge-soft-warning">Setup Pending</span>
                    <?php else: ?>
                      <span class="badge-soft badge-soft-secondary">Not Active</span>
                    <?php endif; ?>
                  </td>

                  <td>
                    <span class="badge-soft <?= esc($statusBadge) ?>">
                      <?= esc(ucfirst($status)) ?>
                    </span>
                  </td>

                  <td data-order="<?= esc((string)$homeowner['created_at']) ?>">
                    <?= esc(nice_date((string)$homeowner['created_at'])) ?>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$homeowners): ?>
                <tr>
                  <td colspan="13" class="empty-state">No homeowner records found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- OFFICERS + ANNOUNCEMENTS -->
      <div class="row">
        <div class="col-xl-7 col-lg-12 mb-30">
          <div class="card-box p-3 height-100-p section-card">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
              <div>
                <h5 class="dashboard-section-title">HOA Officers</h5>
                <div class="dashboard-section-subtitle">
                  Current officer assignments across all phases.
                </div>
              </div>

              <a href="phase_management.php" class="btn btn-sm btn-outline-success mt-2 mt-md-0">
                Open Officer Management
              </a>
            </div>

            <div class="table-responsive">
              <table id="officersTable" class="table table-hover mb-0">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Phase</th>
                    <th>Position</th>
                    <th>Email</th>
                    <th>Status</th>
                  </tr>
                </thead>

                <tbody>
                  <?php foreach ($officers as $officer): ?>
                    <tr>
                      <td><?= esc((string)$officer['full_name']) ?></td>
                      <td><?= esc((string)$officer['phase']) ?></td>
                      <td><?= esc((string)$officer['position']) ?></td>
                      <td><?= esc((string)$officer['email']) ?></td>
                      <td>
                        <?php if ((int)$officer['is_active'] === 1): ?>
                          <span class="badge-soft badge-soft-success">Active</span>
                        <?php else: ?>
                          <span class="badge-soft badge-soft-secondary">Inactive</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>

                  <?php if (!$officers): ?>
                    <tr>
                      <td colspan="5" class="empty-state">No HOA officers assigned yet.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="col-xl-5 col-lg-12 mb-30">
          <div class="card-box p-3 height-100-p section-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <h5 class="dashboard-section-title">Recent Announcements</h5>
                <div class="dashboard-section-subtitle">Latest HOA announcements.</div>
              </div>

              <a href="announcements.php" class="btn btn-sm btn-outline-primary">
                Open
              </a>
            </div>

            <?php if ($recentAnnouncements): ?>
              <?php foreach ($recentAnnouncements as $announcement): ?>
                <?php
                  $priority = strtolower((string)$announcement['priority']);
                  $priorityBadge = 'badge-soft-info';

                  if ($priority === 'urgent') {
                      $priorityBadge = 'badge-soft-danger';
                  } elseif ($priority === 'important') {
                      $priorityBadge = 'badge-soft-warning';
                  }

                  $target =
                      $announcement['phase'] === 'Superadmin'
                          ? 'All Phases'
                          : (string)$announcement['phase'];
                ?>
                <div class="border-bottom pb-2 mb-2">
                  <div class="d-flex justify-content-between align-items-start">
                    <strong><?= esc((string)$announcement['title']) ?></strong>
                    <span class="badge-soft <?= esc($priorityBadge) ?>">
                      <?= esc(ucfirst($priority)) ?>
                    </span>
                  </div>

                  <div class="mini-note mt-1">
                    <?= esc($target) ?>
                    · <?= esc(ucfirst((string)$announcement['category'])) ?>
                    · <?= esc(nice_date((string)$announcement['created_at'])) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-state">No announcements found.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- SYSTEM ACTIVITY LOG -->
      <div class="card-box mb-30 p-3 section-card">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
          <div>
            <h5 class="dashboard-section-title">System Activity Log</h5>
            <div class="dashboard-section-subtitle">
              Administrative and system actions recorded across all phases.
            </div>
          </div>

          <span class="badge-soft badge-soft-secondary mt-2 mt-md-0">
            <?= count($systemActivities) ?> recent log(s)
          </span>
        </div>

        <div class="table-responsive">
          <table id="systemActivityTable" class="table table-hover activity-table mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th>Actor</th>
                <th>Phase</th>
                <th>Module</th>
                <th>Action</th>
                <th>Details</th>
                <th>IP</th>
              </tr>
            </thead>

            <tbody>
              <?php foreach ($systemActivities as $log): ?>
                <tr>
                  <td data-order="<?= esc((string)$log['created_at']) ?>">
                    <?= esc(nice_date((string)$log['created_at'])) ?>
                  </td>
                  <td><?= esc((string)$log['actor']) ?></td>
                  <td><?= esc((string)($log['phase'] ?: 'System')) ?></td>
                  <td>
                    <?= esc(
                        ucwords(
                            str_replace('_', ' ', (string)($log['module_key'] ?: 'general'))
                        )
                    ) ?>
                  </td>
                  <td class="activity-type"><?= esc((string)$log['action']) ?></td>
                  <td class="activity-details"><?= esc((string)($log['details'] ?: '—')) ?></td>
                  <td><?= esc((string)($log['ip_address'] ?: '—')) ?></td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$systemActivities): ?>
                <tr>
                  <td colspan="7" class="empty-state">No system activity logs found.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="footer-wrap pd-20 mb-20 card-box">
        © Copyright South Meridian Homes All Rights Reserved
      </div>
    </div>
  </div>

  <script src="../admin/vendors/scripts/core.js"></script>
  <script src="../admin/vendors/scripts/script.min.js"></script>
  <script src="../admin/vendors/scripts/process.js"></script>
  <script src="../admin/vendors/scripts/layout-settings.js"></script>

  <script src="../admin/src/plugins/datatables/js/jquery.dataTables.min.js"></script>
  <script src="../admin/src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
  <script src="../admin/src/plugins/datatables/js/dataTables.responsive.min.js"></script>
  <script src="../admin/src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

  <script>
    $(document).ready(function () {
      $('#communityActivityTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[0, 'desc']]
      });

      $('#homeownersTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[12, 'desc']],
        scrollX: true
      });

      $('#officersTable').DataTable({
        responsive: true,
        pageLength: 8,
        order: [[1, 'asc'], [2, 'asc']]
      });

      $('#systemActivityTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[0, 'desc']]
      });
    });

    const chartLabels = <?= json_encode($labels) ?>;
    const collectionData = <?= json_encode($chartCollections) ?>;
    const expenseData = <?= json_encode($chartExpenses) ?>;
    const newHomeownerData = <?= json_encode($chartNewHomeowners) ?>;

    const financeCanvas = document.getElementById('financeChart');

    if (financeCanvas) {
      new Chart(financeCanvas.getContext('2d'), {
        type: 'bar',
        data: {
          labels: chartLabels,
          datasets: [
            {
              label: 'Collections',
              data: collectionData,
              borderWidth: 1
            },
            {
              label: 'Expenses',
              data: expenseData,
              borderWidth: 1
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: 'index',
            intersect: false
          },
          plugins: {
            legend: {
              display: true
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              title: {
                display: true,
                text: 'Amount (₱)'
              }
            }
          }
        }
      });
    }

    const growthCanvas = document.getElementById('homeownerGrowthChart');

    if (growthCanvas) {
      new Chart(growthCanvas.getContext('2d'), {
        type: 'line',
        data: {
          labels: chartLabels,
          datasets: [
            {
              label: 'New Homeowner Records',
              data: newHomeownerData,
              borderWidth: 2,
              tension: 0.25,
              fill: false
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: true
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                precision: 0
              },
              title: {
                display: true,
                text: 'Homeowner Records'
              }
            }
          }
        }
      });
    }
  </script>
</body>
</html>
