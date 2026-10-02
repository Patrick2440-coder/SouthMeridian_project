<?php

session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once '../config/database.php';

/* =========================================================
   SUPERADMIN GUARD
   ========================================================= */

if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    $_SESSION['admin_role'] !== 'superadmin'
) {
    header('Location: ../index.php');
    exit;
}

$superadminId = (int)$_SESSION['admin_id'];

$POSITIONS = [
    'President',
    'Vice President',
    'Secretary',
    'Treasurer',
    'Auditor',
    'Board of Director',
];

$SINGLE_POSITIONS = [
    'President',
    'Vice President',
    'Secretary',
    'Treasurer',
    'Auditor',
];

$PHASES = ['Phase 1', 'Phase 2', 'Phase 3'];
$MAX_BOARD_DIRECTORS = 6;

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function officer_full_name(array $row): string
{
    return trim(
        (string)($row['first_name'] ?? '') . ' ' .
        (!empty($row['middle_name']) ? (string)$row['middle_name'] . ' ' : '') .
        (string)($row['last_name'] ?? '')
    );
}

function json_response(bool $success, string $message = '', array $extra = []): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message,
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS c
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return $count > 0;
}

function table_exists(mysqli $conn, string $table): bool
{
    $sql = "
        SELECT COUNT(*) AS c
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return $count > 0;
}

function log_officer_activity(
    mysqli $conn,
    int $adminId,
    string $phase,
    string $action,
    string $details
): void {
    try {
        if (!table_exists($conn, 'activity_logs')) {
            return;
        }

        $moduleKey = 'user_management';
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $conn->prepare("
            INSERT INTO activity_logs
                (admin_id, phase, action, module_key, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            'isssss',
            $adminId,
            $phase,
            $action,
            $moduleKey,
            $details,
            $ip
        );

        $stmt->execute();
        $stmt->close();

    } catch (Throwable $e) {
        error_log('Officer assignment activity log failed: ' . $e->getMessage());
    }
}

function ensure_phase_rows(
    mysqli $conn,
    string $phase,
    array $singlePositions,
    bool $officerLinkReady
): void {
    foreach ($singlePositions as $position) {
        $stmt = $conn->prepare("
            SELECT id
            FROM hoa_officers
            WHERE phase = ?
              AND position = ?
            LIMIT 1
        ");
        $stmt->bind_param('ss', $phase, $position);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            continue;
        }

        if ($officerLinkReady) {
            $stmt = $conn->prepare("
                INSERT INTO hoa_officers
                    (homeowner_id, phase, position, officer_name, officer_email, is_active)
                VALUES (NULL, ?, ?, NULL, NULL, 1)
            ");
        } else {
            $stmt = $conn->prepare("
                INSERT INTO hoa_officers
                    (phase, position, officer_name, officer_email, is_active)
                VALUES (?, ?, NULL, NULL, 1)
            ");
        }

        $stmt->bind_param('ss', $phase, $position);
        $stmt->execute();
        $stmt->close();
    }
}

function upsert_homeowner_position(
    mysqli $conn,
    int $homeownerId,
    string $phase,
    string $position,
    int $adminId
): void {
    if ($homeownerId <= 0 || !table_exists($conn, 'homeowner_positions')) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO homeowner_positions
            (homeowner_id, phase, position, updated_by_admin_id)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            position = VALUES(position),
            updated_by_admin_id = VALUES(updated_by_admin_id),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->bind_param(
        'issi',
        $homeownerId,
        $phase,
        $position,
        $adminId
    );

    $stmt->execute();
    $stmt->close();
}

function set_homeowner_back_to_member(
    mysqli $conn,
    int $homeownerId,
    string $phase,
    int $adminId
): void {
    if ($homeownerId <= 0) {
        return;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM hoa_officers
        WHERE homeowner_id = ?
          AND phase = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param('is', $homeownerId, $phase);
    $stmt->execute();
    $stillOfficer = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($stillOfficer) {
        return;
    }

    upsert_homeowner_position(
        $conn,
        $homeownerId,
        $phase,
        'Homeowner',
        $adminId
    );
}

function sync_admin_account_link(
    mysqli $conn,
    int $homeownerId,
    string $phase,
    string $position,
    string $fullName
): void {
    if (
        $homeownerId <= 0 ||
        !column_exists($conn, 'admins', 'homeowner_id')
    ) {
        return;
    }

    if ($position !== 'Board of Director') {
        $stmt = $conn->prepare("
            UPDATE admins
            SET homeowner_id = ?,
                full_name = ?
            WHERE phase = ?
              AND role = 'admin'
              AND position = ?
            LIMIT 1
        ");
        $stmt->bind_param('isss', $homeownerId, $fullName, $phase, $position);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM admins
        WHERE phase = ?
          AND role = 'admin'
          AND position = 'Board of Director'
          AND homeowner_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('si', $phase, $homeownerId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare("
            UPDATE admins
            SET full_name = ?
            WHERE id = ?
            LIMIT 1
        ");
        $adminRowId = (int)$existing['id'];
        $stmt->bind_param('si', $fullName, $adminRowId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare("
        SELECT id
        FROM admins
        WHERE phase = ?
          AND role = 'admin'
          AND position = 'Board of Director'
          AND homeowner_id IS NULL
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->bind_param('s', $phase);
    $stmt->execute();
    $available = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$available) {
        return;
    }

    $adminRowId = (int)$available['id'];

    $stmt = $conn->prepare("
        UPDATE admins
        SET homeowner_id = ?,
            full_name = ?
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('isi', $homeownerId, $fullName, $adminRowId);
    $stmt->execute();
    $stmt->close();
}

function clear_admin_account_link(
    mysqli $conn,
    int $homeownerId,
    string $phase,
    string $position
): void {
    if (
        $homeownerId <= 0 ||
        !column_exists($conn, 'admins', 'homeowner_id')
    ) {
        return;
    }

    if ($position !== 'Board of Director') {
        $stmt = $conn->prepare("
            UPDATE admins
            SET homeowner_id = NULL
            WHERE phase = ?
              AND role = 'admin'
              AND position = ?
              AND homeowner_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ssi', $phase, $position, $homeownerId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare("
        UPDATE admins
        SET homeowner_id = NULL
        WHERE phase = ?
          AND role = 'admin'
          AND position = 'Board of Director'
          AND homeowner_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('si', $phase, $homeownerId);
    $stmt->execute();
    $stmt->close();
}


function revoke_officer_admin_account(
    mysqli $conn,
    int $homeownerId,
    string $phase,
    string $position
): void {
    if (
        $homeownerId <= 0 ||
        !column_exists($conn, 'admins', 'homeowner_id')
    ) {
        return;
    }

    /*
     * The current landing login does not yet check an enabled flag,
     * so replacing the password hash immediately invalidates the
     * previous officer credential.
     */
    $disabledPassword = password_hash(
        bin2hex(random_bytes(32)),
        PASSWORD_DEFAULT
    );

    $hasEnabled =
        column_exists($conn, 'admins', 'account_enabled');

    $hasIssuedAt =
        column_exists($conn, 'admins', 'account_issued_at');

    if ($hasEnabled && $hasIssuedAt) {
        $sql = "
            UPDATE admins
            SET password = ?,
                account_enabled = 0,
                account_issued_at = NULL
            WHERE homeowner_id = ?
              AND phase = ?
              AND role = 'admin'
              AND position = ?
        ";
    } elseif ($hasEnabled) {
        $sql = "
            UPDATE admins
            SET password = ?,
                account_enabled = 0
            WHERE homeowner_id = ?
              AND phase = ?
              AND role = 'admin'
              AND position = ?
        ";
    } else {
        $sql = "
            UPDATE admins
            SET password = ?
            WHERE homeowner_id = ?
              AND phase = ?
              AND role = 'admin'
              AND position = ?
        ";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'siss',
        $disabledPassword,
        $homeownerId,
        $phase,
        $position
    );
    $stmt->execute();
    $stmt->close();
}

$officerLinkReady = column_exists($conn, 'hoa_officers', 'homeowner_id');
$adminLinkReady = column_exists($conn, 'admins', 'homeowner_id');
$positionTableReady = table_exists($conn, 'homeowner_positions');

$accountProvisionReady =
    $adminLinkReady &&
    column_exists($conn, 'admins', 'account_enabled') &&
    column_exists($conn, 'admins', 'account_issued_at') &&
    column_exists($conn, 'admins', 'account_issued_by_admin_id');

/* =========================================================
   AJAX ENDPOINTS
   ========================================================= */

if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    if (ob_get_level() === 0) {
        ob_start();
    }

    try {
        $action = trim((string)($_POST['action'] ?? ''));
        $phase = trim((string)($_POST['phase'] ?? 'Phase 1'));

        if (!in_array($phase, $PHASES, true)) {
            json_response(false, 'Invalid phase selected.');
        }

        ensure_phase_rows($conn, $phase, $SINGLE_POSITIONS, $officerLinkReady);

        if ($action === 'existing_homeowners') {
            $stmt = $conn->prepare("
                SELECT
                    h.id,
                    h.public_id,
                    h.first_name,
                    h.middle_name,
                    h.last_name,
                    h.contact_number,
                    h.email,
                    h.house_lot_number,
                    h.phase,
                    h.status,
                    COALESCE(NULLIF(hp.position, ''), 'Homeowner') AS current_position
                FROM homeowners h
                LEFT JOIN homeowner_positions hp
                       ON hp.homeowner_id = h.id
                      AND hp.phase = h.phase
                WHERE h.phase = ?
                  AND h.status = 'approved'
                ORDER BY h.last_name ASC, h.first_name ASC, h.id ASC
            ");

            $stmt->bind_param('s', $phase);
            $stmt->execute();
            $result = $stmt->get_result();

            $homeowners = [];

            while ($row = $result->fetch_assoc()) {
                $homeowners[] = [
                    'id' => (int)$row['id'],
                    'public_id' => (string)($row['public_id'] ?? ''),
                    'name' => officer_full_name($row),
                    'contact' => (string)($row['contact_number'] ?? ''),
                    'email' => (string)($row['email'] ?? ''),
                    'house_lot' => (string)($row['house_lot_number'] ?? ''),
                    'phase' => (string)$row['phase'],
                    'status' => 'Existing Homeowner',
                    'current_position' => (string)($row['current_position'] ?? 'Homeowner'),
                ];
            }

            $stmt->close();

            json_response(
                true,
                '',
                ['homeowners' => $homeowners]
            );
        }

        if (!$officerLinkReady) {
            json_response(
                false,
                'Officer-homeowner linking is not installed yet. Run officer_assignment_existing_homeowners_setup.sql first.'
            );
        }

        if ($action === 'fetch') {
            $stmt = $conn->prepare("
                SELECT
                    o.id,
                    o.homeowner_id,
                    o.position,
                    o.officer_name,
                    o.officer_email,
                    o.is_active,
                    h.public_id,
                    h.house_lot_number,
                    h.contact_number,
                    h.status AS homeowner_status
                FROM hoa_officers o
                LEFT JOIN homeowners h
                       ON h.id = o.homeowner_id
                WHERE o.phase = ?
                ORDER BY
                    FIELD(
                        o.position,
                        'President',
                        'Vice President',
                        'Secretary',
                        'Treasurer',
                        'Auditor',
                        'Board of Director'
                    ),
                    o.id ASC
            ");

            $stmt->bind_param('s', $phase);
            $stmt->execute();
            $result = $stmt->get_result();

            $grouped = [];

            while ($row = $result->fetch_assoc()) {
                $position = (string)$row['position'];

                if (!isset($grouped[$position])) {
                    $grouped[$position] = [];
                }

                $grouped[$position][] = [
                    'id' => (int)$row['id'],
                    'homeowner_id' => (int)($row['homeowner_id'] ?? 0),
                    'name' => (string)($row['officer_name'] ?? ''),
                    'email' => (string)($row['officer_email'] ?? ''),
                    'public_id' => (string)($row['public_id'] ?? ''),
                    'house_lot' => (string)($row['house_lot_number'] ?? ''),
                    'contact' => (string)($row['contact_number'] ?? ''),
                    'homeowner_status' => (string)($row['homeowner_status'] ?? ''),
                    'active' => (int)$row['is_active'],
                ];
            }

            $stmt->close();

            $rows = [];

            foreach ($POSITIONS as $position) {
                if ($position === 'Board of Director') {
                    $rows[] = [
                        'position' => $position,
                        'is_multi' => 1,
                        'officers' => $grouped[$position] ?? [],
                        'max_directors' => $MAX_BOARD_DIRECTORS,
                    ];
                    continue;
                }

                $officer = $grouped[$position][0] ?? [
                    'id' => 0,
                    'homeowner_id' => 0,
                    'name' => '',
                    'email' => '',
                    'public_id' => '',
                    'house_lot' => '',
                    'contact' => '',
                    'homeowner_status' => '',
                    'active' => 1,
                ];

                $rows[] = [
                    'position' => $position,
                    'is_multi' => 0,
                    'id' => (int)$officer['id'],
                    'homeowner_id' => (int)$officer['homeowner_id'],
                    'name' => (string)$officer['name'],
                    'email' => (string)$officer['email'],
                    'public_id' => (string)$officer['public_id'],
                    'house_lot' => (string)$officer['house_lot'],
                    'contact' => (string)$officer['contact'],
                    'homeowner_status' => (string)$officer['homeowner_status'],
                    'active' => (int)$officer['active'],
                ];
            }

            json_response(true, '', ['rows' => $rows]);
        }

        if ($action === 'assign') {
            $position = trim((string)($_POST['position'] ?? ''));
            $homeownerId = (int)($_POST['homeowner_id'] ?? 0);

            if (!in_array($position, $POSITIONS, true)) {
                json_response(false, 'Invalid officer position.');
            }

            if ($homeownerId <= 0) {
                json_response(false, 'Select an existing homeowner first.');
            }

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
                    status
                FROM homeowners
                WHERE id = ?
                  AND phase = ?
                  AND status = 'approved'
                LIMIT 1
            ");

            $stmt->bind_param('is', $homeownerId, $phase);
            $stmt->execute();
            $homeowner = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$homeowner) {
                json_response(
                    false,
                    'The selected person must be an existing current homeowner from ' . $phase . '.'
                );
            }

            $fullName = officer_full_name($homeowner);
            $email = trim((string)($homeowner['email'] ?? ''));

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_response(false, 'The selected homeowner does not have a valid email address.');
            }

            $targetOfficerId = 0;
            $previousHomeownerId = 0;

            if ($position !== 'Board of Director') {
                $stmt = $conn->prepare("
                    SELECT id, homeowner_id
                    FROM hoa_officers
                    WHERE phase = ?
                      AND position = ?
                    LIMIT 1
                ");
                $stmt->bind_param('ss', $phase, $position);
                $stmt->execute();
                $targetRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$targetRow) {
                    json_response(false, 'Officer position row could not be found. Refresh the page and try again.');
                }

                $targetOfficerId = (int)$targetRow['id'];
                $previousHomeownerId = (int)($targetRow['homeowner_id'] ?? 0);
            }

            $stmt = $conn->prepare("
                SELECT id, position
                FROM hoa_officers
                WHERE phase = ?
                  AND homeowner_id = ?
                  AND is_active = 1
                  AND (? = 0 OR id <> ?)
                LIMIT 1
            ");
            $stmt->bind_param(
                'siii',
                $phase,
                $homeownerId,
                $targetOfficerId,
                $targetOfficerId
            );
            $stmt->execute();
            $conflict = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($conflict) {
                json_response(
                    false,
                    $fullName . ' is already assigned as ' . (string)$conflict['position'] . ' in ' . $phase . '.'
                );
            }

            if ($position === 'Board of Director') {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) AS c
                    FROM hoa_officers
                    WHERE phase = ?
                      AND position = 'Board of Director'
                      AND homeowner_id IS NOT NULL
                ");
                $stmt->bind_param('s', $phase);
                $stmt->execute();
                $boardCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                $stmt->close();

                if ($boardCount >= $MAX_BOARD_DIRECTORS) {
                    json_response(
                        false,
                        'This phase already has the maximum of ' . $MAX_BOARD_DIRECTORS . ' Board of Directors.'
                    );
                }
            }

            $conn->begin_transaction();

            try {
                if ($position === 'Board of Director') {
                    $stmt = $conn->prepare("
                        INSERT INTO hoa_officers
                            (homeowner_id, phase, position, officer_name, officer_email, is_active)
                        VALUES (?, ?, 'Board of Director', ?, ?, 1)
                    ");
                    $stmt->bind_param('isss', $homeownerId, $phase, $fullName, $email);
                    $stmt->execute();
                    $stmt->close();

                } else {
                    $stmt = $conn->prepare("
                        UPDATE hoa_officers
                        SET homeowner_id = ?,
                            officer_name = ?,
                            officer_email = ?,
                            is_active = 1
                        WHERE id = ?
                          AND phase = ?
                          AND position = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param(
                        'ississ',
                        $homeownerId,
                        $fullName,
                        $email,
                        $targetOfficerId,
                        $phase,
                        $position
                    );
                    $stmt->execute();
                    $stmt->close();

                    if (
                        $previousHomeownerId > 0 &&
                        $previousHomeownerId !== $homeownerId
                    ) {
                        revoke_officer_admin_account(
                            $conn,
                            $previousHomeownerId,
                            $phase,
                            $position
                        );

                        clear_admin_account_link(
                            $conn,
                            $previousHomeownerId,
                            $phase,
                            $position
                        );

                        set_homeowner_back_to_member(
                            $conn,
                            $previousHomeownerId,
                            $phase,
                            $superadminId
                        );
                    }
                }

                upsert_homeowner_position(
                    $conn,
                    $homeownerId,
                    $phase,
                    $position,
                    $superadminId
                );

                sync_admin_account_link(
                    $conn,
                    $homeownerId,
                    $phase,
                    $position,
                    $fullName
                );

                /*
                 * A newly assigned officer must explicitly receive an
                 * officer account from Superadmin. Do not let a legacy
                 * seed password become usable automatically.
                 */
                if (
                    $position === 'Board of Director' ||
                    $previousHomeownerId !== $homeownerId
                ) {
                    revoke_officer_admin_account(
                        $conn,
                        $homeownerId,
                        $phase,
                        $position
                    );
                }

                log_officer_activity(
                    $conn,
                    $superadminId,
                    $phase,
                    'Officer assigned',
                    'Assigned homeowner_id=' . $homeownerId .
                    '; name=' . $fullName .
                    '; position=' . $position .
                    '; phase=' . $phase
                );

                $conn->commit();

            } catch (Throwable $e) {
                $conn->rollback();
                error_log('Officer assignment failed: ' . $e->getMessage());
                json_response(false, 'Officer assignment could not be saved.');
            }

            json_response(
                true,
                $fullName . ' was assigned as ' . $position . ' for ' . $phase . '.',
                [
                    'homeowner_id' => $homeownerId,
                    'name' => $fullName,
                    'position' => $position,
                ]
            );
        }

        if ($action === 'toggle') {
            $position = trim((string)($_POST['position'] ?? ''));
            $id = (int)($_POST['id'] ?? 0);

            if (!in_array($position, $POSITIONS, true)) {
                json_response(false, 'Invalid officer position.');
            }

            if ($position === 'Board of Director') {
                if ($id <= 0) {
                    json_response(false, 'Invalid Board of Director row.');
                }

                $stmt = $conn->prepare("
                    SELECT id, homeowner_id, is_active
                    FROM hoa_officers
                    WHERE id = ?
                      AND phase = ?
                      AND position = 'Board of Director'
                    LIMIT 1
                ");
                $stmt->bind_param('is', $id, $phase);
            } else {
                $stmt = $conn->prepare("
                    SELECT id, homeowner_id, is_active
                    FROM hoa_officers
                    WHERE phase = ?
                      AND position = ?
                    LIMIT 1
                ");
                $stmt->bind_param('ss', $phase, $position);
            }

            $stmt->execute();
            $officer = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$officer) {
                json_response(false, 'Officer assignment could not be found.');
            }

            $homeownerId = (int)($officer['homeowner_id'] ?? 0);

            if ($homeownerId <= 0) {
                json_response(false, 'Assign an existing homeowner before changing officer status.');
            }

            $newActive = (int)$officer['is_active'] === 1 ? 0 : 1;

            if ($newActive === 1) {
                $stmt = $conn->prepare("
                    SELECT id, position
                    FROM hoa_officers
                    WHERE phase = ?
                      AND homeowner_id = ?
                      AND is_active = 1
                      AND id <> ?
                    LIMIT 1
                ");
                $officerRowId = (int)$officer['id'];
                $stmt->bind_param('sii', $phase, $homeownerId, $officerRowId);
                $stmt->execute();
                $conflict = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($conflict) {
                    json_response(
                        false,
                        'This homeowner already has an active officer position: ' . (string)$conflict['position'] . '.'
                    );
                }
            }

            $conn->begin_transaction();

            try {
                $officerRowId = (int)$officer['id'];

                $stmt = $conn->prepare("
                    UPDATE hoa_officers
                    SET is_active = ?
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->bind_param('ii', $newActive, $officerRowId);
                $stmt->execute();
                $stmt->close();

                if ($newActive === 1) {
                    upsert_homeowner_position(
                        $conn,
                        $homeownerId,
                        $phase,
                        $position,
                        $superadminId
                    );
                } else {
                    revoke_officer_admin_account(
                        $conn,
                        $homeownerId,
                        $phase,
                        $position
                    );

                    set_homeowner_back_to_member(
                        $conn,
                        $homeownerId,
                        $phase,
                        $superadminId
                    );
                }

                log_officer_activity(
                    $conn,
                    $superadminId,
                    $phase,
                    $newActive === 1 ? 'Officer activated' : 'Officer deactivated',
                    'homeowner_id=' . $homeownerId .
                    '; position=' . $position .
                    '; phase=' . $phase
                );

                $conn->commit();

            } catch (Throwable $e) {
                $conn->rollback();
                error_log('Officer status update failed: ' . $e->getMessage());
                json_response(false, 'Officer status could not be updated.');
            }

            json_response(
                true,
                $newActive === 1 ? 'Officer activated.' : 'Officer set to not active.',
                ['active' => $newActive]
            );
        }

        if ($action === 'remove_assignment') {
            $position = trim((string)($_POST['position'] ?? ''));
            $id = (int)($_POST['id'] ?? 0);

            if (!in_array($position, $POSITIONS, true)) {
                json_response(false, 'Invalid officer position.');
            }

            if ($position === 'Board of Director') {
                if ($id <= 0) {
                    json_response(false, 'Invalid Board of Director row.');
                }

                $stmt = $conn->prepare("
                    SELECT id, homeowner_id, officer_name
                    FROM hoa_officers
                    WHERE id = ?
                      AND phase = ?
                      AND position = 'Board of Director'
                    LIMIT 1
                ");
                $stmt->bind_param('is', $id, $phase);
            } else {
                $stmt = $conn->prepare("
                    SELECT id, homeowner_id, officer_name
                    FROM hoa_officers
                    WHERE phase = ?
                      AND position = ?
                    LIMIT 1
                ");
                $stmt->bind_param('ss', $phase, $position);
            }

            $stmt->execute();
            $officer = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$officer) {
                json_response(false, 'Officer assignment could not be found.');
            }

            $homeownerId = (int)($officer['homeowner_id'] ?? 0);
            $officerName = trim((string)($officer['officer_name'] ?? ''));
            $officerRowId = (int)$officer['id'];

            if ($homeownerId <= 0) {
                json_response(false, 'There is no existing homeowner assigned to this position.');
            }

            $conn->begin_transaction();

            try {
                if ($position === 'Board of Director') {
                    $stmt = $conn->prepare("
                        DELETE FROM hoa_officers
                        WHERE id = ?
                          AND phase = ?
                          AND position = 'Board of Director'
                        LIMIT 1
                    ");
                    $stmt->bind_param('is', $officerRowId, $phase);
                    $stmt->execute();
                    $stmt->close();

                } else {
                    $stmt = $conn->prepare("
                        UPDATE hoa_officers
                        SET homeowner_id = NULL,
                            officer_name = NULL,
                            officer_email = NULL,
                            is_active = 1
                        WHERE id = ?
                        LIMIT 1
                    ");
                    $stmt->bind_param('i', $officerRowId);
                    $stmt->execute();
                    $stmt->close();
                }

                revoke_officer_admin_account(
                    $conn,
                    $homeownerId,
                    $phase,
                    $position
                );

                clear_admin_account_link(
                    $conn,
                    $homeownerId,
                    $phase,
                    $position
                );

                set_homeowner_back_to_member(
                    $conn,
                    $homeownerId,
                    $phase,
                    $superadminId
                );

                log_officer_activity(
                    $conn,
                    $superadminId,
                    $phase,
                    'Officer assignment removed',
                    'homeowner_id=' . $homeownerId .
                    '; name=' . $officerName .
                    '; position=' . $position .
                    '; phase=' . $phase
                );

                $conn->commit();

            } catch (Throwable $e) {
                $conn->rollback();
                error_log('Officer removal failed: ' . $e->getMessage());
                json_response(false, 'Officer assignment could not be removed.');
            }

            json_response(
                true,
                ($officerName !== '' ? $officerName : 'The homeowner') .
                ' was removed from ' . $position . '.'
            );
        }

        json_response(false, 'Unknown officer-management action.');

    } catch (Throwable $e) {
        error_log('Phase management AJAX failed: ' . $e->getMessage());
        json_response(false, 'The officer-management request could not be completed.');
    }
}

/* =========================================================
   PAGE LOAD
   ========================================================= */

$selectedPhase = trim((string)($_GET['phase'] ?? 'Phase 1'));

if (!in_array($selectedPhase, $PHASES, true)) {
    $selectedPhase = 'Phase 1';
}

ensure_phase_rows(
    $conn,
    $selectedPhase,
    $SINGLE_POSITIONS,
    $officerLinkReady
);

$totalAssigned = 0;
$totalActive = 0;
$totalDirectors = 0;
$totalExistingHomeowners = 0;
$totalIssuedAccounts = 0;

if ($officerLinkReady) {
    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN homeowner_id IS NOT NULL THEN 1 ELSE 0 END) AS assigned_count,
            SUM(CASE WHEN is_active = 1 AND homeowner_id IS NOT NULL THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN position = 'Board of Director' AND homeowner_id IS NOT NULL THEN 1 ELSE 0 END) AS director_count
        FROM hoa_officers
        WHERE phase = ?
    ");
} else {
    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN officer_name IS NOT NULL AND officer_name <> '' THEN 1 ELSE 0 END) AS assigned_count,
            SUM(CASE WHEN is_active = 1 AND officer_name IS NOT NULL AND officer_name <> '' THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN position = 'Board of Director' AND officer_name IS NOT NULL AND officer_name <> '' THEN 1 ELSE 0 END) AS director_count
        FROM hoa_officers
        WHERE phase = ?
    ");
}

$stmt->bind_param('s', $selectedPhase);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalAssigned = (int)($summary['assigned_count'] ?? 0);
$totalActive = (int)($summary['active_count'] ?? 0);
$totalDirectors = (int)($summary['director_count'] ?? 0);

if ($accountProvisionReady) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM admins
        WHERE phase = ?
          AND role = 'admin'
          AND homeowner_id IS NOT NULL
          AND account_enabled = 1
    ");
    $stmt->bind_param('s', $selectedPhase);
    $stmt->execute();
    $totalIssuedAccounts =
        (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
}

$stmt = $conn->prepare("
    SELECT COUNT(*) AS c
    FROM homeowners
    WHERE phase = ?
      AND status = 'approved'
");
$stmt->bind_param('s', $selectedPhase);
$stmt->execute();
$totalExistingHomeowners = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

?>
<!DOCTYPE html>
<html>
<head>
  <?php
require_once $_SERVER['DOCUMENT_ROOT'] .
    '/SouthMeridian_project/includes/favicon.php';
?>
    <meta charset="utf-8">
    <title>Superadmin - Officers</title>



    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/core.css">
    <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/icon-font.min.css">
    <link rel="stylesheet" type="text/css" href="../admin/vendors/styles/style.css">

    <style>
        body {
            background: #f5f7fb;
        }

        .officer-hero {
            position: relative;
            overflow: hidden;
            border-radius: 20px;
            padding: 26px 28px;
            background: linear-gradient(135deg, #076b3d 0%, #0a8f50 62%, #16a366 100%);
            color: #fff;
            box-shadow: 0 14px 34px rgba(7, 127, 70, .18);
        }

        .officer-hero::after {
            content: "";
            position: absolute;
            width: 230px;
            height: 230px;
            right: -60px;
            top: -90px;
            border-radius: 50%;
            background: rgba(255,255,255,.09);
        }

        .officer-hero h3,
        .officer-hero p {
            color: #fff;
            position: relative;
            z-index: 1;
        }

        .officer-hero h3 {
            font-weight: 800;
            margin-bottom: 8px;
        }

        .officer-hero p {
            max-width: 800px;
            margin-bottom: 0;
            opacity: .94;
        }

        .officer-flow {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 16px;
            position: relative;
            z-index: 1;
        }

        .officer-flow span {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 11px;
            border-radius: 999px;
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.18);
            font-size: 12px;
            font-weight: 700;
        }

        .stat-card {
            height: 100%;
            border: 1px solid #e6ebf1;
            border-radius: 16px;
            background: #fff;
            padding: 17px 18px;
            box-shadow: 0 6px 20px rgba(15,23,42,.045);
        }

        .stat-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .035em;
        }

        .stat-value {
            margin-top: 7px;
            color: #0f172a;
            font-size: 29px;
            line-height: 1;
            font-weight: 800;
        }

        .stat-note {
            margin-top: 6px;
            color: #94a3b8;
            font-size: 11px;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            color: #077f46;
            background: #ecfdf5;
            font-size: 20px;
        }

        .officer-panel {
            border: 1px solid #e6ebf1;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(15,23,42,.05);
        }

        .officer-panel-head {
            padding: 18px 20px;
            border-bottom: 1px solid #edf1f5;
            background: #fff;
        }

        .phase-tools {
            display: flex;
            align-items: center;
            gap: 9px;
            flex-wrap: wrap;
        }

        .phase-tools .form-control {
            min-width: 150px;
            border-radius: 10px;
        }

        .officer-table thead th {
            white-space: nowrap;
            background: #f8fafc !important;
            color: #475569;
            border-bottom: 1px solid #e2e8f0 !important;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .025em;
        }

        .officer-table td {
            vertical-align: middle;
            border-color: #edf2f7;
        }

        .officer-person-name {
            color: #12284c;
            font-weight: 800;
        }

        .officer-person-meta {
            margin-top: 3px;
            color: #64748b;
            font-size: 11px;
            word-break: break-word;
        }

        .badge-soft {
            display: inline-flex;
            align-items: center;
            padding: .36rem .62rem;
            border-radius: 999px;
            font-weight: 800;
            font-size: 11px;
        }

        .badge-soft-success {
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .badge-soft-secondary {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #475569;
        }

        .badge-soft-info {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
        }

        .badge-soft-warning {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
        }

        .mini-note {
            color: #64748b;
            font-size: 12px;
        }

        .action-btns {
            min-width: 235px;
        }

        .action-btns .btn {
            margin: 2px;
            border-radius: 9px;
            font-weight: 700;
        }

        .existing-homeowner-preview,
        .account-officer-preview,
        .credentials-result {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px;
            background: #f8fafc;
        }

        .existing-homeowner-preview.empty {
            color: #64748b;
            text-align: center;
            padding: 22px 14px;
        }

        .homeowner-preview-name {
            font-size: 16px;
            font-weight: 800;
            color: #12284c;
        }

        .homeowner-meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 9px 14px;
            margin-top: 11px;
        }

        .homeowner-meta-label {
            font-size: 10px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .045em;
        }

        .homeowner-meta-value {
            font-size: 13px;
            color: #1e293b;
            word-break: break-word;
        }

        .setup-warning {
            border: 0;
            border-left: 4px solid #f59e0b;
            border-radius: 12px;
        }

        .modal-content {
            border: 0;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 18px 48px rgba(15,23,42,.2);
        }

        .modal-header {
            background: linear-gradient(135deg, #076b3d 0%, #0a8f50 100%);
            color: #fff;
            border-bottom: 0;
            padding: 17px 20px;
        }

        .modal-header .modal-title,
        .modal-header .close {
            color: #fff;
        }

        .modal-header .close {
            opacity: 1;
            text-shadow: none;
        }

        .form-control {
            border-radius: 10px;
        }

        .account-note {
            border-radius: 12px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e3a8a;
            padding: 11px 13px;
            font-size: 12px;
        }

        .credentials-result {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        .credential-value {
            padding: 9px 11px;
            border-radius: 9px;
            background: #fff;
            border: 1px solid #dbe5df;
            font-family: Consolas, "Courier New", monospace;
            font-size: 13px;
            word-break: break-all;
        }

        #msgBox {
            position: fixed;
            top: 82px;
            right: 22px;
            z-index: 10050;
            width: min(390px, calc(100vw - 32px));
            pointer-events: none;
        }

        #msgBox .alert {
            pointer-events: auto;
            border: 0;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(15,23,42,.18);
        }

        @media (max-width: 767.98px) {
            .officer-hero {
                padding: 22px;
            }

            .homeowner-meta-grid {
                grid-template-columns: 1fr;
            }

            .phase-tools {
                width: 100%;
                margin-top: 12px;
            }

            .phase-tools .form-control {
                width: 100%;
            }

            .action-btns {
                min-width: 175px;
            }

            .action-btns .btn {
                width: 100%;
                margin: 2px 0;
            }

            #msgBox {
                top: 72px;
                left: 16px;
                right: 16px;
                width: auto;
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
        <div class="user-info-dropdown">
            <div class="dropdown">
                <a class="dropdown-toggle" href="#" role="button" data-toggle="dropdown">
                    <span class="user-icon">
                        <img src="../admin/vendors/images/photo1.jpg" alt="">
                    </span>
                    <span class="user-name">Superadmin</span>
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
                    <div class="title"><h4>Officers</h4></div>
                    <div class="text-secondary">
                        Assign existing homeowners as HOA officers and issue their separate officer accounts.
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$officerLinkReady || !$adminLinkReady || !$positionTableReady): ?>
            <div class="alert alert-warning setup-warning mb-20">
                <b>Officer linking setup is not complete.</b><br>
                Run <code>officer_assignment_existing_homeowners_setup.sql</code> in phpMyAdmin,
                then refresh this page.
            </div>
        <?php endif; ?>

        <?php if (!$accountProvisionReady): ?>
            <div class="alert alert-warning setup-warning mb-20">
                <b>Officer account issuance is not installed yet.</b><br>
                Run <code>officer_account_provisioning_setup.sql</code> in phpMyAdmin.
                Officer assignment still works, but account issuance needs this setup.
            </div>
        <?php endif; ?>

        <?php if ($totalExistingHomeowners === 0): ?>
            <div class="alert alert-info mb-20" style="border-radius:14px;border-left:4px solid #0d6efd;">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <b>No existing homeowners are available in <?= esc($selectedPhase) ?> yet.</b><br>
                        This is expected in a fresh system. Import and finalize the HOA's existing homeowner masterlist first.
                    </div>
                    <a href="user_management.php#homeownerMigration" class="btn btn-primary flex-shrink-0">
                        Set Up Homeowners
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <div class="officer-hero mb-20">
            <h3>Officer Assignment & Account Access</h3>
            <p>
                Fresh-system flow: import and finalize the existing South Meridian homeowner masterlist first.
                Then choose a homeowner from the correct phase, assign the HOA position, and issue account access.
                The same email is used for both accounts: homeowner password opens the resident portal,
                officer password opens the administration portal.
            </p>

            <div class="officer-flow">
                <span><i class="dw dw-upload1"></i> 1. Set Up Homeowners</span>
                <span><i class="dw dw-user1"></i> 2. Assign Officer</span>
                <span><i class="dw dw-padlock1"></i> 3. Set Up Accounts</span>
                <span><i class="dw dw-check"></i> Access Follows Position Permissions</span>
            </div>
        </div>

        <div class="row mb-10">
            <div class="col-xl-3 col-lg-6 col-md-6 mb-20">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Existing Homeowners</div>
                            <div class="stat-value"><?= $totalExistingHomeowners ?></div>
                            <div class="stat-note"><?= esc($selectedPhase) ?></div>
                        </div>
                        <div class="stat-icon"><i class="dw dw-user1"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-20">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Assigned Officers</div>
                            <div class="stat-value"><?= $totalAssigned ?></div>
                            <div class="stat-note">Including board members</div>
                        </div>
                        <div class="stat-icon"><i class="dw dw-group"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-20">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Active Officers</div>
                            <div class="stat-value"><?= $totalActive ?></div>
                            <div class="stat-note">Eligible for officer access</div>
                        </div>
                        <div class="stat-icon"><i class="dw dw-check"></i></div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-lg-6 col-md-6 mb-20">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Accounts Issued</div>
                            <div class="stat-value"><?= $totalIssuedAccounts ?></div>
                            <div class="stat-note">Separate admin logins</div>
                        </div>
                        <div class="stat-icon"><i class="dw dw-padlock1"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-box officer-panel mb-30">
            <div class="officer-panel-head d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h5 class="mb-1">Current HOA Officers</h5>
                    <div class="mini-note">
                        Board of Directors supports up to <?= (int)$MAX_BOARD_DIRECTORS ?> members per phase.
                    </div>
                </div>

                <div class="phase-tools">
                    <label class="mb-0 text-secondary font-weight-bold">Phase</label>

                    <select id="phaseSelect" class="form-control">
                        <?php foreach ($PHASES as $phaseOption): ?>
                            <option
                                value="<?= esc($phaseOption) ?>"
                                <?= $selectedPhase === $phaseOption ? 'selected' : '' ?>
                            >
                                <?= esc($phaseOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0 officer-table">
                    <thead>
                        <tr>
                            <th>Position</th>
                            <th>Existing Homeowner</th>
                            <th>Property</th>
                            <th>Officer Status</th>
                            <th>Officer Account</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody id="rolesTbody">
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">
                                Loading officers...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="msgBox"></div>

        <div class="footer-wrap pd-20 mb-20 card-box">
            © Copyright South Meridian Homes All Rights Reserved
        </div>
    </div>
</div>

<!-- ASSIGN EXISTING HOMEOWNER MODAL -->
<div
    class="modal fade"
    id="assignModal"
    tabindex="-1"
    role="dialog"
    aria-labelledby="assignModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignModalLabel">
                    Assign Existing Homeowner
                </h5>

                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">
                <form id="assignForm">
                    <input type="hidden" id="modalPositionKey">
                    <input type="hidden" id="modalCurrentHomeownerId">

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Selected Phase</label>
                            <input type="text" class="form-control" id="modalPhase" readonly>
                        </div>

                        <div class="col-md-6 form-group">
                            <label>Position</label>
                            <input type="text" class="form-control" id="modalPosition" readonly>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Search Existing Homeowner</label>
                        <input
                            type="text"
                            class="form-control"
                            id="homeownerSearch"
                            placeholder="Search by name, Public ID, house/lot, email, or contact number..."
                            autocomplete="off"
                        >
                    </div>

                    <div class="form-group">
                        <label>Existing Homeowner <span class="text-danger">*</span></label>

                        <select
                            class="form-control"
                            id="existingHomeownerSelect"
                            required
                        >
                            <option value="">-- Select Existing Homeowner --</option>
                        </select>

                        <div class="mini-note mt-2">
                            Only current existing homeowners from the selected phase are shown.
                        </div>
                    </div>

                    <div
                        id="homeownerPreview"
                        class="existing-homeowner-preview empty mb-3"
                    >
                        Select an existing homeowner from the finalized HOA masterlist.
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary btn-block"
                        id="saveAssignmentButton"
                    >
                        Assign Officer
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- OFFICER ACCOUNT MODAL -->
<div
    class="modal fade"
    id="officerAccountModal"
    tabindex="-1"
    role="dialog"
    aria-labelledby="officerAccountModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="officerAccountModalLabel">
                    Officer Account
                </h5>

                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">
                <div class="account-note mb-3">
                    <b>One email, two passwords.</b>
                    The officer uses the same registered homeowner email.
                    The password decides which account opens:
                    homeowner password → Homeowner Dashboard,
                    officer password → Admin Dashboard.
                </div>

                <div class="account-officer-preview mb-3">
                    <div class="homeowner-preview-name" id="accountOfficerName">Officer</div>

                    <div class="homeowner-meta-grid">
                        <div>
                            <div class="homeowner-meta-label">Phase</div>
                            <div class="homeowner-meta-value" id="accountOfficerPhase">—</div>
                        </div>

                        <div>
                            <div class="homeowner-meta-label">Position</div>
                            <div class="homeowner-meta-value" id="accountOfficerPosition">—</div>
                        </div>

                        <div>
                            <div class="homeowner-meta-label">Login Email</div>
                            <div class="homeowner-meta-value" id="accountHomeownerEmail">—</div>
                        </div>

                        <div>
                            <div class="homeowner-meta-label">Account Status</div>
                            <div class="homeowner-meta-value" id="accountCurrentState">Checking...</div>
                        </div>
                    </div>
                </div>

                <div class="account-note mb-3">
                    <b>Important:</b>
                    The generated officer password will be different from the homeowner password.
                    If both passwords ever become the same, login will be rejected until the officer password is reset.
                </div>

                <div class="custom-control custom-checkbox mb-3">
                    <input
                        type="checkbox"
                        class="custom-control-input"
                        id="emailOfficerCredentials"
                        checked
                    >
                    <label class="custom-control-label" for="emailOfficerCredentials">
                        Email generated credentials to the homeowner's registered email
                    </label>
                </div>

                <div id="accountCredentialResult" class="credentials-result d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
                        <div>
                            <div class="font-weight-bold text-success">
                                Officer account is ready
                            </div>
                            <div class="mini-note" id="accountMailResult"></div>
                        </div>

                        <button
                            type="button"
                            class="btn btn-sm btn-outline-success mt-2 mt-md-0"
                            id="copyOfficerCredentialsButton"
                        >
                            Copy Credentials
                        </button>
                    </div>

                    <div class="mb-2">
                        <div class="homeowner-meta-label">Login Email</div>
                        <div class="credential-value" id="issuedOfficerLoginEmail"></div>
                    </div>

                    <div>
                        <div class="homeowner-meta-label">Temporary Password</div>
                        <div class="credential-value" id="issuedOfficerPassword"></div>
                    </div>

                    <div class="mini-note mt-2">
                        The temporary password is shown here after issuing or resetting the account.
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-dismiss="modal">
                    Close
                </button>

                <button
                    type="button"
                    class="btn btn-success"
                    id="issueOfficerAccountButton"
                >
                    Issue Account
                </button>
            </div>
        </div>
    </div>
</div>

<!-- REMOVE ASSIGNMENT CONFIRMATION MODAL -->
<div
    class="modal fade"
    id="removeOfficerModal"
    tabindex="-1"
    role="dialog"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Remove Officer Assignment</h5>

                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">
                <p class="mb-2">
                    Remove <b id="removeOfficerName">this homeowner</b> from
                    <b id="removeOfficerPosition">this officer position</b>?
                </p>

                <div class="alert alert-warning mb-0">
                    The homeowner remains in the HOA system. Only the officer assignment is removed.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    Cancel
                </button>

                <button type="button" class="btn btn-danger" id="confirmRemoveOfficerButton">
                    Remove Assignment
                </button>
            </div>
        </div>
    </div>
</div>

<script src="../admin/vendors/scripts/core.js"></script>
<script src="../admin/vendors/scripts/script.min.js"></script>
<script src="../admin/vendors/scripts/process.js"></script>
<script src="../admin/vendors/scripts/layout-settings.js"></script>

<script>

const msgBox = document.getElementById('msgBox');
const maxBoardDirectors = <?= (int)$MAX_BOARD_DIRECTORS ?>;
const officerLinkReady = <?= $officerLinkReady ? 'true' : 'false' ?>;
const accountProvisionReady = <?= $accountProvisionReady ? 'true' : 'false' ?>;

let currentExistingHomeowners = [];
let pendingRemoveOfficer = null;
let currentOfficerAccount = null;

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function showMsg(type, text) {
    msgBox.innerHTML = `
        <div class="alert alert-${type} py-2 mb-0" role="alert">
            ${escapeHtml(text)}
        </div>
    `;

    window.setTimeout(function () {
        msgBox.innerHTML = '';
    }, 3500);
}

function statusBadge(isActive) {
    return isActive
        ? '<span class="badge-soft badge-soft-success">Active</span>'
        : '<span class="badge-soft badge-soft-secondary">Not Active</span>';
}

function accountActionButton(officer, phase, position) {
    const homeownerId = parseInt(officer.homeowner_id || 0, 10);
    const active = parseInt(officer.active || 0, 10) === 1;

    if (homeownerId <= 0) {
        return '<span class="text-muted">—</span>';
    }

    if (!active) {
        return `
            <div>
                <span class="badge-soft badge-soft-secondary">Access Disabled</span>
                <div class="mini-note mt-1">Activate officer first</div>
            </div>
        `;
    }

    if (!accountProvisionReady) {
        return `
            <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                disabled
                title="Run officer_account_provisioning_setup.sql first"
            >
                Setup Required
            </button>
        `;
    }

    return `
        <button
            type="button"
            class="btn btn-sm btn-success js-officer-account"
            data-homeowner-id="${homeownerId}"
            data-phase="${escapeHtml(phase)}"
            data-position="${escapeHtml(position)}"
            data-name="${escapeHtml(officer.name || '')}"
            data-homeowner-email="${escapeHtml(officer.email || '')}"
        >
            Manage Account
        </button>
    `;
}

function assignmentButtonLabel(hasHomeowner) {
    return hasHomeowner ? 'Change' : 'Assign';
}

function renderRows(rows, phase) {
    const tbody = document.getElementById('rolesTbody');
    tbody.innerHTML = '';

    rows.forEach(function (row) {
        if (parseInt(row.is_multi, 10) === 1) {
            const officers = Array.isArray(row.officers) ? row.officers : [];

            if (officers.length === 0) {
                const tr = document.createElement('tr');

                tr.innerHTML = `
                    <td class="font-weight-bold">${escapeHtml(row.position)}</td>
                    <td><span class="text-muted">No Board of Director assigned</span></td>
                    <td><span class="text-muted">—</span></td>
                    <td><span class="badge-soft badge-soft-secondary">Not Assigned</span></td>
                    <td><span class="text-muted">—</span></td>
                    <td class="action-btns">
                        <button
                            type="button"
                            class="btn btn-sm btn-primary"
                            data-toggle="modal"
                            data-target="#assignModal"
                            data-position="Board of Director"
                            data-current-homeowner-id="0"
                        >
                            Add Director
                        </button>
                    </td>
                `;

                tbody.appendChild(tr);
                return;
            }

            officers.forEach(function (officer, index) {
                const homeownerId = parseInt(officer.homeowner_id || 0, 10);
                const active = parseInt(officer.active || 0, 10) === 1;
                const officerId = parseInt(officer.id || 0, 10);
                const name = String(officer.name || '').trim();
                const email = String(officer.email || '').trim();
                const houseLot = String(officer.house_lot || '').trim();

                const tr = document.createElement('tr');

                tr.innerHTML = `
                    <td class="font-weight-bold">${index === 0 ? escapeHtml(row.position) : ''}</td>

                    <td>
                        <div class="officer-person-name">${escapeHtml(name)}</div>
                        <div class="officer-person-meta">
                            ${officer.public_id ? escapeHtml(officer.public_id) : ''}
                            ${officer.public_id && email ? ' • ' : ''}
                            ${email ? escapeHtml(email) : ''}
                        </div>
                    </td>

                    <td>${houseLot ? escapeHtml(houseLot) : '<span class="text-muted">—</span>'}</td>

                    <td>${statusBadge(active)}</td>

                    <td>${accountActionButton(officer, phase, 'Board of Director')}</td>

                    <td class="action-btns">
                        <button
                            type="button"
                            class="btn btn-sm ${active ? 'btn-outline-secondary' : 'btn-outline-success'} js-toggle-officer"
                            data-phase="${escapeHtml(phase)}"
                            data-position="Board of Director"
                            data-officer-id="${officerId}"
                        >
                            ${active ? 'Deactivate' : 'Activate'}
                        </button>

                        <button
                            type="button"
                            class="btn btn-sm btn-outline-danger js-remove-officer"
                            data-phase="${escapeHtml(phase)}"
                            data-position="Board of Director"
                            data-officer-id="${officerId}"
                            data-officer-name="${escapeHtml(name)}"
                        >
                            Remove
                        </button>

                        ${index === officers.length - 1 && officers.length < maxBoardDirectors ? `
                            <button
                                type="button"
                                class="btn btn-sm btn-primary"
                                data-toggle="modal"
                                data-target="#assignModal"
                                data-position="Board of Director"
                                data-current-homeowner-id="0"
                            >
                                + Director
                            </button>
                        ` : ''}
                    </td>
                `;

                tbody.appendChild(tr);
            });

            return;
        }

        const homeownerId = parseInt(row.homeowner_id || 0, 10);
        const active = parseInt(row.active || 0, 10) === 1;
        const name = String(row.name || '').trim();
        const email = String(row.email || '').trim();
        const houseLot = String(row.house_lot || '').trim();

        const tr = document.createElement('tr');

        tr.innerHTML = `
            <td class="font-weight-bold">${escapeHtml(row.position)}</td>

            <td>
                <div class="officer-person-name">
                    ${name ? escapeHtml(name) : '<span class="text-muted">Not assigned</span>'}
                </div>

                <div class="officer-person-meta">
                    ${row.public_id ? escapeHtml(row.public_id) : ''}
                    ${row.public_id && email ? ' • ' : ''}
                    ${email ? escapeHtml(email) : ''}
                </div>
            </td>

            <td>${houseLot ? escapeHtml(houseLot) : '<span class="text-muted">—</span>'}</td>

            <td>
                ${homeownerId > 0
                    ? statusBadge(active)
                    : '<span class="badge-soft badge-soft-secondary">Not Assigned</span>'}
            </td>

            <td>${accountActionButton(row, phase, row.position)}</td>

            <td class="action-btns">
                <button
                    type="button"
                    class="btn btn-sm btn-primary"
                    data-toggle="modal"
                    data-target="#assignModal"
                    data-position="${escapeHtml(row.position)}"
                    data-current-homeowner-id="${homeownerId}"
                >
                    ${assignmentButtonLabel(homeownerId > 0)}
                </button>

                ${homeownerId > 0 ? `
                    <button
                        type="button"
                        class="btn btn-sm ${active ? 'btn-outline-secondary' : 'btn-outline-success'} js-toggle-officer"
                        data-phase="${escapeHtml(phase)}"
                        data-position="${escapeHtml(row.position)}"
                        data-officer-id="${parseInt(row.id || 0, 10)}"
                    >
                        ${active ? 'Deactivate' : 'Activate'}
                    </button>

                    <button
                        type="button"
                        class="btn btn-sm btn-outline-danger js-remove-officer"
                        data-phase="${escapeHtml(phase)}"
                        data-position="${escapeHtml(row.position)}"
                        data-officer-id="${parseInt(row.id || 0, 10)}"
                        data-officer-name="${escapeHtml(name)}"
                    >
                        Remove
                    </button>
                ` : ''}
            </td>
        `;

        tbody.appendChild(tr);
    });
}

function fetchPhase(phase) {
    if (!officerLinkReady) {
        document.getElementById('rolesTbody').innerHTML = `
            <tr>
                <td colspan="6" class="text-center text-danger py-4">
                    Run officer_assignment_existing_homeowners_setup.sql first.
                </td>
            </tr>
        `;
        return;
    }

    $.post(
        'phase_management.php',
        {
            ajax: '1',
            action: 'fetch',
            phase: phase
        },
        function (response) {
            if (!response.success) {
                showMsg('danger', response.message || 'Failed to load officers.');
                return;
            }

            renderRows(response.rows || [], phase);
        },
        'json'
    ).fail(function () {
        showMsg('danger', 'Unable to load officers from the server.');
    });
}

function loadExistingHomeowners(phase, currentHomeownerId) {
    const select = document.getElementById('existingHomeownerSelect');
    const search = document.getElementById('homeownerSearch');
    const preview = document.getElementById('homeownerPreview');

    currentExistingHomeowners = [];

    select.innerHTML = '<option value="">Loading existing homeowners...</option>';
    select.disabled = true;
    search.value = '';

    preview.className = 'existing-homeowner-preview empty mb-3';
    preview.textContent = 'Loading homeowner information...';

    $.post(
        'phase_management.php',
        {
            ajax: '1',
            action: 'existing_homeowners',
            phase: phase
        },
        function (response) {
            if (!response.success) {
                select.innerHTML = '<option value="">Unable to load homeowners</option>';
                preview.textContent = response.message || 'Unable to load homeowners.';
                return;
            }

            currentExistingHomeowners = Array.isArray(response.homeowners)
                ? response.homeowners
                : [];

            if (currentExistingHomeowners.length === 0) {
                select.innerHTML = '<option value="">No existing homeowners available</option>';
                select.disabled = true;

                preview.className = 'existing-homeowner-preview mb-3';
                preview.innerHTML = `
                    <div class="text-center py-2">
                        <div class="font-weight-bold mb-2">No existing homeowners found in ${escapeHtml(phase)}.</div>
                        <div class="mini-note mb-3">
                            For a fresh system, import and finalize the HOA homeowner masterlist first.
                        </div>
                        <a href="user_management.php#homeownerMigration" class="btn btn-sm btn-primary">
                            Set Up Homeowners
                        </a>
                    </div>
                `;
                return;
            }

            renderExistingHomeownerOptions('', currentHomeownerId);
            select.disabled = false;

            if (currentHomeownerId > 0) {
                select.value = String(currentHomeownerId);
                renderHomeownerPreview(currentHomeownerId);
            } else {
                renderHomeownerPreview(0);
            }
        },
        'json'
    ).fail(function () {
        select.innerHTML = '<option value="">Unable to load homeowners</option>';
        preview.textContent = 'Unable to load homeowners from the server.';
    });
}

function homeownerMatchesSearch(homeowner, searchText) {
    if (!searchText) {
        return true;
    }

    const haystack = [
        homeowner.name,
        homeowner.public_id,
        homeowner.house_lot,
        homeowner.email,
        homeowner.contact,
        homeowner.current_position
    ].join(' ').toLowerCase();

    return haystack.includes(searchText.toLowerCase());
}

function renderExistingHomeownerOptions(searchText, preferredHomeownerId = 0) {
    const select = document.getElementById('existingHomeownerSelect');
    const oldValue = select.value || (preferredHomeownerId > 0 ? String(preferredHomeownerId) : '');

    const matches = currentExistingHomeowners.filter(function (homeowner) {
        return homeownerMatchesSearch(homeowner, searchText.trim());
    });

    let html = '<option value="">-- Select Existing Homeowner --</option>';

    matches.forEach(function (homeowner) {
        const currentRole = String(homeowner.current_position || 'Homeowner');

        html += `
            <option value="${parseInt(homeowner.id, 10)}">
                ${escapeHtml(homeowner.name)}
                ${homeowner.public_id ? ' • ' + escapeHtml(homeowner.public_id) : ''}
                ${homeowner.house_lot ? ' • ' + escapeHtml(homeowner.house_lot) : ''}
                ${currentRole !== 'Homeowner' ? ' • Current: ' + escapeHtml(currentRole) : ''}
            </option>
        `;
    });

    select.innerHTML = html;

    if (oldValue && matches.some(function (homeowner) {
        return String(homeowner.id) === String(oldValue);
    })) {
        select.value = String(oldValue);
    }
}

function renderHomeownerPreview(homeownerId) {
    const preview = document.getElementById('homeownerPreview');

    const homeowner = currentExistingHomeowners.find(function (item) {
        return parseInt(item.id, 10) === parseInt(homeownerId || 0, 10);
    });

    if (!homeowner) {
        preview.className = 'existing-homeowner-preview empty mb-3';
        preview.textContent = 'Select an existing homeowner from the finalized HOA masterlist.';
        return;
    }

    preview.className = 'existing-homeowner-preview mb-3';

    preview.innerHTML = `
        <div class="d-flex justify-content-between align-items-start flex-wrap">
            <div>
                <div class="homeowner-preview-name">${escapeHtml(homeowner.name)}</div>
                <div class="mini-note">${escapeHtml(homeowner.public_id || 'No Public ID')}</div>
            </div>

            <span class="badge-soft badge-soft-success">
                Existing Homeowner
            </span>
        </div>

        <div class="homeowner-meta-grid">
            <div>
                <div class="homeowner-meta-label">Phase</div>
                <div class="homeowner-meta-value">${escapeHtml(homeowner.phase)}</div>
            </div>

            <div>
                <div class="homeowner-meta-label">House / Lot</div>
                <div class="homeowner-meta-value">${escapeHtml(homeowner.house_lot || '—')}</div>
            </div>

            <div>
                <div class="homeowner-meta-label">Email</div>
                <div class="homeowner-meta-value">${escapeHtml(homeowner.email || '—')}</div>
            </div>

            <div>
                <div class="homeowner-meta-label">Contact Number</div>
                <div class="homeowner-meta-value">${escapeHtml(homeowner.contact || '—')}</div>
            </div>

            <div>
                <div class="homeowner-meta-label">Current System Role</div>
                <div class="homeowner-meta-value">${escapeHtml(homeowner.current_position || 'Homeowner')}</div>
            </div>

            <div>
                <div class="homeowner-meta-label">Status</div>
                <div class="homeowner-meta-value">Existing Homeowner</div>
            </div>
        </div>
    `;
}

function openOfficerAccountModal(button) {
    currentOfficerAccount = {
        homeownerId: parseInt(button.dataset.homeownerId || 0, 10),
        phase: String(button.dataset.phase || ''),
        position: String(button.dataset.position || ''),
        name: String(button.dataset.name || ''),
        homeownerEmail: String(button.dataset.homeownerEmail || '')
    };

    document.getElementById('accountOfficerName').textContent =
        currentOfficerAccount.name || 'Officer';

    document.getElementById('accountOfficerPhase').textContent =
        currentOfficerAccount.phase || '—';

    document.getElementById('accountOfficerPosition').textContent =
        currentOfficerAccount.position || '—';

    document.getElementById('accountHomeownerEmail').textContent =
        currentOfficerAccount.homeownerEmail || 'No email';

    document.getElementById('accountCurrentState').textContent =
        'Checking account...';

    document.getElementById('emailOfficerCredentials').checked =
        currentOfficerAccount.homeownerEmail !== '';

    document.getElementById('accountCredentialResult')
        .classList.add('d-none');

    const issueButton =
        document.getElementById('issueOfficerAccountButton');

    issueButton.disabled = true;
    issueButton.textContent = 'Loading...';

    $('#officerAccountModal').modal('show');

    $.post(
        'officer_account.php',
        {
            ajax: '1',
            action: 'status',
            phase: currentOfficerAccount.phase,
            position: currentOfficerAccount.position,
            homeowner_id: currentOfficerAccount.homeownerId
        },
        function (response) {
            if (!response.success) {
                showMsg('danger', response.message || 'Unable to load officer account.');
                return;
            }

            document.getElementById('accountCurrentState').textContent =
                response.account_enabled
                    ? 'Account Issued'
                    : 'Not Issued';

            currentOfficerAccount.accountEnabled =
                !!response.account_enabled;

            issueButton.textContent =
                currentOfficerAccount.accountEnabled
                    ? 'Reset Account'
                    : 'Issue Account';
        },
        'json'
    ).fail(function () {
        showMsg('danger', 'Unable to load officer account information.');
    }).always(function () {
        issueButton.disabled = false;

        if (
            currentOfficerAccount &&
            issueButton.textContent === 'Loading...'
        ) {
            issueButton.textContent = 'Issue Account';
        }
    });
}

function copyOfficerCredentials() {
    const login =
        document.getElementById('issuedOfficerLoginEmail').textContent.trim();

    const password =
        document.getElementById('issuedOfficerPassword').textContent.trim();

    if (!login || !password) {
        showMsg('warning', 'Issue the officer account first.');
        return;
    }

    const content =
        'South Meridian HOA Officer Account\n'
        + 'Login Email: ' + login + '\n'
        + 'Temporary Password: ' + password;

    if (
        navigator.clipboard &&
        typeof navigator.clipboard.writeText === 'function'
    ) {
        navigator.clipboard.writeText(content)
            .then(function () {
                showMsg('success', 'Officer credentials copied.');
            })
            .catch(function () {
                fallbackCopyCredentials(content);
            });
        return;
    }

    fallbackCopyCredentials(content);
}

function fallbackCopyCredentials(content) {
    const textarea = document.createElement('textarea');
    textarea.value = content;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';

    document.body.appendChild(textarea);
    textarea.select();

    try {
        document.execCommand('copy');
        showMsg('success', 'Officer credentials copied.');
    } catch (error) {
        showMsg('warning', 'Copy the credentials manually from the account window.');
    }

    document.body.removeChild(textarea);
}

function toggleOfficerStatus(phase, position, id) {
    $.post(
        'phase_management.php',
        {
            ajax: '1',
            action: 'toggle',
            phase: phase,
            position: position,
            id: id
        },
        function (response) {
            if (!response.success) {
                showMsg('danger', response.message || 'Failed to update officer status.');
                return;
            }

            showMsg('success', response.message || 'Officer status updated.');
            fetchPhase(phase);
        },
        'json'
    ).fail(function () {
        showMsg('danger', 'Unable to update officer status.');
    });
}

function openRemoveOfficerModal(phase, position, id, name) {
    pendingRemoveOfficer = {
        phase: phase,
        position: position,
        id: parseInt(id || 0, 10),
        name: String(name || 'this homeowner')
    };

    document.getElementById('removeOfficerName').textContent = pendingRemoveOfficer.name;
    document.getElementById('removeOfficerPosition').textContent = pendingRemoveOfficer.position;

    $('#removeOfficerModal').modal('show');
}

document.addEventListener('DOMContentLoaded', function () {
    const phaseSelect = document.getElementById('phaseSelect');
    const homeownerSelect = document.getElementById('existingHomeownerSelect');
    const homeownerSearch = document.getElementById('homeownerSearch');
    const saveButton = document.getElementById('saveAssignmentButton');
    const removeButton = document.getElementById('confirmRemoveOfficerButton');
    const issueAccountButton = document.getElementById('issueOfficerAccountButton');
    const copyCredentialsButton = document.getElementById('copyOfficerCredentialsButton');

    fetchPhase(phaseSelect.value);

    phaseSelect.addEventListener('change', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('phase', this.value);
        window.history.replaceState({}, '', url);

        fetchPhase(this.value);
    });

    document.getElementById('rolesTbody').addEventListener('click', function (event) {
        const target = event.target instanceof Element ? event.target : null;

        if (!target) {
            return;
        }

        const accountButton = target.closest('.js-officer-account');

        if (accountButton) {
            openOfficerAccountModal(accountButton);
            return;
        }

        const toggleButton = target.closest('.js-toggle-officer');

        if (toggleButton) {
            toggleOfficerStatus(
                String(toggleButton.dataset.phase || ''),
                String(toggleButton.dataset.position || ''),
                parseInt(toggleButton.dataset.officerId || 0, 10)
            );
            return;
        }

        const removeOfficerButton = target.closest('.js-remove-officer');

        if (removeOfficerButton) {
            openRemoveOfficerModal(
                String(removeOfficerButton.dataset.phase || ''),
                String(removeOfficerButton.dataset.position || ''),
                parseInt(removeOfficerButton.dataset.officerId || 0, 10),
                String(removeOfficerButton.dataset.officerName || 'this homeowner')
            );
        }
    });

    $('#assignModal').on('show.bs.modal', function (event) {
        const button = $(event.relatedTarget);
        const phase = phaseSelect.value;
        const position = String(button.data('position') || '');
        const currentHomeownerId = parseInt(button.data('current-homeowner-id') || 0, 10);

        document.getElementById('modalPhase').value = phase;
        document.getElementById('modalPosition').value = position;
        document.getElementById('modalPositionKey').value = position;
        document.getElementById('modalCurrentHomeownerId').value = String(currentHomeownerId);
        document.getElementById('assignModalLabel').textContent =
            (currentHomeownerId > 0 ? 'Change ' : 'Assign ') + position;
        saveButton.textContent = currentHomeownerId > 0 ? 'Save New Officer' : 'Assign Officer';

        loadExistingHomeowners(phase, currentHomeownerId);
    });

    $('#assignModal').on('hidden.bs.modal', function () {
        currentExistingHomeowners = [];
        homeownerSearch.value = '';
        homeownerSelect.innerHTML = '<option value="">-- Select Existing Homeowner --</option>';
        renderHomeownerPreview(0);
    });

    homeownerSearch.addEventListener('input', function () {
        renderExistingHomeownerOptions(this.value);
        renderHomeownerPreview(homeownerSelect.value);
    });

    homeownerSelect.addEventListener('change', function () {
        renderHomeownerPreview(this.value);
    });

    document.getElementById('assignForm').addEventListener('submit', function (event) {
        event.preventDefault();

        const phase = document.getElementById('modalPhase').value;
        const position = document.getElementById('modalPositionKey').value;
        const homeownerId = parseInt(homeownerSelect.value || 0, 10);

        if (homeownerId <= 0) {
            showMsg('warning', 'Select an existing homeowner first.');
            return;
        }

        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';

        $.post(
            'phase_management.php',
            {
                ajax: '1',
                action: 'assign',
                phase: phase,
                position: position,
                homeowner_id: homeownerId
            },
            function (response) {
                if (!response.success) {
                    showMsg('danger', response.message || 'Officer assignment failed.');
                    return;
                }

                $('#assignModal').modal('hide');
                showMsg('success', response.message || 'Officer assigned.');
                fetchPhase(phase);
            },
            'json'
        ).fail(function () {
            showMsg('danger', 'Unable to save the officer assignment.');
        }).always(function () {
            saveButton.disabled = false;
            saveButton.textContent = 'Assign Officer';
        });
    });

    issueAccountButton.addEventListener('click', function () {
        if (!currentOfficerAccount) {
            return;
        }

        issueAccountButton.disabled = true;
        issueAccountButton.textContent =
            currentOfficerAccount.accountEnabled
                ? 'Resetting...'
                : 'Creating...';

        $.post(
            'officer_account.php',
            {
                ajax: '1',
                action: 'issue',
                phase: currentOfficerAccount.phase,
                position: currentOfficerAccount.position,
                homeowner_id: currentOfficerAccount.homeownerId,
                send_email: document.getElementById('emailOfficerCredentials').checked ? 1 : 0
            },
            function (response) {
                if (!response.success || !response.account) {
                    showMsg('danger', response.message || 'Officer account could not be created.');
                    return;
                }

                document.getElementById('issuedOfficerLoginEmail').textContent =
                    String(response.account.login_email || '');

                document.getElementById('issuedOfficerPassword').textContent =
                    String(response.account.temporary_password || '');

                document.getElementById('accountMailResult').textContent =
                    String(response.account.mail_message || '');

                document.getElementById('accountCredentialResult')
                    .classList.remove('d-none');

                document.getElementById('accountCurrentState').textContent =
                    'Account Issued';

                currentOfficerAccount.accountEnabled = true;

                showMsg(
                    'success',
                    response.account.mail_sent
                        ? 'Officer account created and credentials emailed.'
                        : 'Officer account created. Copy the credentials shown in the account window.'
                );
            },
            'json'
        ).fail(function (xhr) {
            const message =
                xhr &&
                xhr.responseJSON &&
                xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Unable to create the officer account.';

            showMsg('danger', message);
        }).always(function () {
            issueAccountButton.disabled = false;

            if (currentOfficerAccount) {
                issueAccountButton.textContent =
                    currentOfficerAccount.accountEnabled
                        ? 'Reset Account'
                        : 'Issue Account';
            }
        });
    });

    copyCredentialsButton.addEventListener(
        'click',
        copyOfficerCredentials
    );

    $('#officerAccountModal').on('hidden.bs.modal', function () {
        currentOfficerAccount = null;
        document.getElementById('accountCredentialResult')
            .classList.add('d-none');
    });

    removeButton.addEventListener('click', function () {
        if (!pendingRemoveOfficer) {
            return;
        }

        const request = pendingRemoveOfficer;

        removeButton.disabled = true;
        removeButton.textContent = 'Removing...';

        $.post(
            'phase_management.php',
            {
                ajax: '1',
                action: 'remove_assignment',
                phase: request.phase,
                position: request.position,
                id: request.id
            },
            function (response) {
                if (!response.success) {
                    showMsg('danger', response.message || 'Unable to remove officer assignment.');
                    return;
                }

                $('#removeOfficerModal').modal('hide');
                showMsg('success', response.message || 'Officer assignment removed.');
                fetchPhase(request.phase);
            },
            'json'
        ).fail(function () {
            showMsg('danger', 'Unable to remove officer assignment.');
        }).always(function () {
            removeButton.disabled = false;
            removeButton.textContent = 'Remove Assignment';
        });
    });

    $('#removeOfficerModal').on('hidden.bs.modal', function () {
        pendingRemoveOfficer = null;
    });
});

</script>

</body>
</html>
