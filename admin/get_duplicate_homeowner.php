<?php

session_start();

require_once 'admin_access.php';
require_once '../config/database.php';

requireAccess('homeowner_management');

header('Content-Type: application/json; charset=utf-8');


function respond(array $data, int $status = 200): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    !in_array(
        $_SESSION['admin_role'],
        ['admin', 'superadmin'],
        true
    )
) {
    respond(
        [
            'success' => false,
            'message' => 'Unauthorized.'
        ],
        401
    );
}


$queueId =
    (int)($_GET['id'] ?? 0);

if ($queueId <= 0) {
    respond(
        [
            'success' => false,
            'message' => 'Invalid queue ID.'
        ],
        400
    );
}


/*
|--------------------------------------------------------------------------
| Current admin
|--------------------------------------------------------------------------
*/

$adminId =
    (int)$_SESSION['admin_id'];

$stmt =
    $conn->prepare(
        "SELECT phase, role
         FROM admins
         WHERE id=?
         LIMIT 1"
    );

$stmt->bind_param(
    'i',
    $adminId
);

$stmt->execute();

$admin =
    $stmt->get_result()->fetch_assoc();

$stmt->close();


if (!$admin) {
    respond(
        [
            'success' => false,
            'message' => 'Admin account not found.'
        ],
        403
    );
}


/*
|--------------------------------------------------------------------------
| Get duplicate queue record
|--------------------------------------------------------------------------
*/

if ($admin['role'] === 'superadmin') {

    $stmt =
        $conn->prepare(
            "SELECT *
             FROM homeowner_import_queue
             WHERE id=?
               AND status='duplicate'
             LIMIT 1"
        );

    $stmt->bind_param(
        'i',
        $queueId
    );

} else {

    $stmt =
        $conn->prepare(
            "SELECT *
             FROM homeowner_import_queue
             WHERE id=?
               AND status='duplicate'
               AND phase=?
             LIMIT 1"
        );

    $stmt->bind_param(
        'is',
        $queueId,
        $admin['phase']
    );
}


$stmt->execute();

$queue =
    $stmt->get_result()->fetch_assoc();

$stmt->close();


if (!$queue) {
    respond(
        [
            'success' => false,
            'message' =>
                'Duplicate queue record was not found.'
        ],
        404
    );
}


/*
|--------------------------------------------------------------------------
| Existing homeowner
|--------------------------------------------------------------------------
*/

$existingId =
    (int)($queue['duplicate_homeowner_id'] ?? 0);


if ($existingId <= 0) {
    respond(
        [
            'success' => false,
            'message' =>
                'The duplicate homeowner reference is missing.'
        ],
        422
    );
}


$stmt =
    $conn->prepare(
        "SELECT
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
            barangay,
            city_municipality,
            province,
            region,
            zip_code,
            country,
            residential_type,
            status
         FROM homeowners
         WHERE id=?
         LIMIT 1"
    );

$stmt->bind_param(
    'i',
    $existingId
);

$stmt->execute();

$existing =
    $stmt->get_result()->fetch_assoc();

$stmt->close();


if (!$existing) {
    respond(
        [
            'success' => false,
            'message' =>
                'The existing homeowner record could not be found.'
        ],
        404
    );
}


respond([
    'success' => true,

    'queue' => [
        'id' =>
            (int)$queue['id'],

        'name' =>
            trim(
                ($queue['first_name'] ?? '')
                . ' '
                . ($queue['middle_name'] ?? '')
                . ' '
                . ($queue['last_name'] ?? '')
            ),

        'email' =>
            $queue['email'] ?? '',

        'contact_number' =>
            $queue['contact_number'] ?? '',

        'phase' =>
            $queue['phase'] ?? '',

        'block' =>
            $queue['block'] ?? '',

        'lot' =>
            $queue['lot'] ?? '',

        'street' =>
            $queue['street'] ?? '',

        'residential_type' =>
            $queue['residential_type'] ?? ''
    ],

    'existing' => [
        'id' =>
            (int)$existing['id'],

        'public_id' =>
            $existing['public_id'] ?? '',

        'name' =>
            trim(
                ($existing['first_name'] ?? '')
                . ' '
                . ($existing['middle_name'] ?? '')
                . ' '
                . ($existing['last_name'] ?? '')
            ),

        'email' =>
            $existing['email'] ?? '',

        'contact_number' =>
            $existing['contact_number'] ?? '',

        'phase' =>
            $existing['phase'] ?? '',

        'block' =>
            $existing['block'] ?? '',

        'lot' =>
            $existing['lot'] ?? '',

        'street' =>
            $existing['street'] ?? '',

        'residential_type' =>
            $existing['residential_type'] ?? '',

        'status' =>
            $existing['status'] ?? ''
    ]
]);