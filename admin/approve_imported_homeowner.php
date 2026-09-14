<?php

ob_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);


function respond(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Official subdivision mapping
|--------------------------------------------------------------------------
*/

function southMeridianLocations(): array
{
    $path =
        __DIR__ .
        '/southmeri_block_lot_mapping.json';

    if (!is_readable($path)) {
        throw new RuntimeException(
            'southmeri_block_lot_mapping.json was not found.'
        );
    }

    $decoded = json_decode(
        (string)file_get_contents($path),
        true
    );

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'South Meridian mapping JSON is invalid.'
        );
    }

    $pageWidthEmu =
        8.5 * 914400;

    $pageHeightEmu =
        11 * 914400;

    $pageMarginEmu =
        914400;

    $markerCenterOffsetEmu =
        90000;

    $locations = [];

    foreach ($decoded as $item) {

        $block =
            (int)($item['block'] ?? 0);

        $lot =
            (int)($item['lot'] ?? 0);

        if ($block <= 0 || $lot <= 0) {
            continue;
        }

        $xEmu =
            (float)($item['x_emu'] ?? 0);

        $yEmu =
            (float)($item['y_emu'] ?? 0);

        $mapX =
            (int)round(
                (
                    (
                        $pageMarginEmu +
                        $xEmu +
                        $markerCenterOffsetEmu
                    )
                    / $pageWidthEmu
                )
                * 2550
            );

        $mapY =
            (int)round(
                (
                    (
                        $pageMarginEmu +
                        $yEmu +
                        $markerCenterOffsetEmu
                    )
                    / $pageHeightEmu
                )
                * 3300
            );

        $locations[
            $block . ':' . $lot
        ] = [
            'street' =>
                trim(
                    (string)(
                        $item['street'] ?? ''
                    )
                ),

            'map_x' =>
                $mapX,

            'map_y' =>
                $mapY
        ];
    }

    return $locations;
}


try {

    session_start();

    require_once 'admin_access.php';
    require_once '../config/database.php';

    requireAccess(
        'homeowner_management'
    );


    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

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


    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $csrf =
        (string)(
            $_POST['csrf'] ?? ''
        );

    $sessionCsrf =
        (string)(
            $_SESSION[
                'homeowner_import_csrf'
            ] ?? ''
        );

    if (
        $csrf === '' ||
        $sessionCsrf === '' ||
        !hash_equals(
            $sessionCsrf,
            $csrf
        )
    ) {
        respond(
            [
                'success' => false,
                'message' =>
                    'Security token expired. Reload the page.'
            ],
            403
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Queue record
    |--------------------------------------------------------------------------
    */

    $queueId =
        (int)(
            $_POST['id'] ?? 0
        );

    if ($queueId <= 0) {
        respond(
            [
                'success' => false,
                'message' =>
                    'Invalid queue ID.'
            ],
            400
        );
    }


    $conn->begin_transaction();


    /*
    |--------------------------------------------------------------------------
    | Administrator
    |--------------------------------------------------------------------------
    */

    $adminId =
        (int)$_SESSION['admin_id'];

    $adminStmt =
        $conn->prepare(
            "SELECT phase, role
             FROM admins
             WHERE id=?
             LIMIT 1"
        );

    $adminStmt->bind_param(
        'i',
        $adminId
    );

    $adminStmt->execute();

    $admin =
        $adminStmt
            ->get_result()
            ->fetch_assoc();

    $adminStmt->close();

    if (!$admin) {
        throw new RuntimeException(
            'Admin account was not found.'
        );
    }

    $adminRole =
        (string)$admin['role'];

    $adminPhase =
        (string)$admin['phase'];


    /*
    |--------------------------------------------------------------------------
    | Lock queue row
    |--------------------------------------------------------------------------
    */

    if ($adminRole === 'superadmin') {

        $queueStmt =
            $conn->prepare(
                "SELECT *
                 FROM homeowner_import_queue
                 WHERE id=?
                   AND status IN ('pending','duplicate')
                 LIMIT 1
                 FOR UPDATE"
            );

        $queueStmt->bind_param(
            'i',
            $queueId
        );

    } else {

        $queueStmt =
            $conn->prepare(
                "SELECT *
                 FROM homeowner_import_queue
                 WHERE id=?
                   AND phase=?
                   AND status IN ('pending','duplicate')
                 LIMIT 1
                 FOR UPDATE"
            );

        $queueStmt->bind_param(
            'is',
            $queueId,
            $adminPhase
        );
    }

    $queueStmt->execute();

    $row =
        $queueStmt
            ->get_result()
            ->fetch_assoc();

    $queueStmt->close();


    if (!$row) {

        $conn->rollback();

        respond(
            [
                'success' => false,
                'message' =>
                    'Queued resident was not found, was already processed, or is outside your phase.'
            ],
            404
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Revalidate official Block / Lot
    |--------------------------------------------------------------------------
    */

    $blockNumber =
        (int)(
            $row['block'] ?? 0
        );

    $lotNumber =
        (int)(
            $row['lot'] ?? 0
        );

    $locationKey =
        $blockNumber .
        ':' .
        $lotNumber;

    $locations =
        southMeridianLocations();

    if (
        $blockNumber <= 0 ||
        $lotNumber <= 0 ||
        !isset(
            $locations[$locationKey]
        )
    ) {

        $conn->rollback();

        respond(
            [
                'success' => false,
                'message' =>
                    "Cannot approve: Block {$blockNumber}, Lot {$lotNumber} is not on the official subdivision map."
            ],
            422
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Canonical location
    |--------------------------------------------------------------------------
    */

    $block =
        (string)$blockNumber;

    $lot =
        (string)$lotNumber;

    $street =
        (string)$locations[
            $locationKey
        ]['street'];

    $mapX =
        (int)$locations[
            $locationKey
        ]['map_x'];

    $mapY =
        (int)$locations[
            $locationKey
        ]['map_y'];

    $houseLot =
        "Block {$blockNumber} Lot {$lotNumber}";


    /*
    |--------------------------------------------------------------------------
    | Duplicate email
    |--------------------------------------------------------------------------
    */

    $duplicateStmt =
        $conn->prepare(
            "SELECT
                id,
                public_id,
                first_name,
                last_name
             FROM homeowners
             WHERE email=?
             LIMIT 1"
        );

    $duplicateStmt->bind_param(
        's',
        $row['email']
    );

    $duplicateStmt->execute();

    $existing =
        $duplicateStmt
            ->get_result()
            ->fetch_assoc();

    $duplicateStmt->close();


   if ($existing) {

    $existingId =
        (int)$existing['id'];

    $updateDuplicate =
        $conn->prepare(
            "UPDATE homeowner_import_queue
             SET
                status='duplicate',
                duplicate_homeowner_id=?
             WHERE id=?"
        );

    $updateDuplicate->bind_param(
        'ii',
        $existingId,
        $queueId
    );

    $updateDuplicate->execute();
    $updateDuplicate->close();

    $conn->commit();

    respond(
        [
            'success' => false,
            'duplicate' => true,

            'message' =>
                'This email is already registered. Review the existing homeowner before removing the duplicate import.',

            'duplicate_homeowner_id' =>
                $existingId,

            'queue_id' =>
                $queueId
        ],
        409
    );
}


    /*
    |--------------------------------------------------------------------------
    | Validate homeowner contact size
    |--------------------------------------------------------------------------
    */

    $contact =
        trim(
            (string)(
                $row['contact_number'] ?? ''
            )
        );

    if (mb_strlen($contact) > 15) {

        $conn->rollback();

        respond(
            [
                'success' => false,
                'message' =>
                    'Contact number exceeds the homeowners table limit of 15 characters.'
            ],
            422
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Imported account credentials
    |--------------------------------------------------------------------------
    */

    $passwordHash =
        password_hash(
            bin2hex(
                random_bytes(32)
            ),
            PASSWORD_DEFAULT
        );

    /*
     * Imported residents have no uploaded
     * documents at this stage.
     */
    $validIdPath =
        'imports/not_provided';

    $proofBillingPath =
        'imports/not_provided';


    /*
    |--------------------------------------------------------------------------
    | Safe defaults
    |--------------------------------------------------------------------------
    */

    $barangay =
        trim(
            (string)(
                $row['barangay'] ??
                'Salitran IV'
            )
        );

    $city =
        trim(
            (string)(
                $row[
                    'city_municipality'
                ] ??
                'Dasmarinas City'
            )
        );

    $province =
        trim(
            (string)(
                $row['province'] ??
                'Cavite'
            )
        );

    $region =
        trim(
            (string)(
                $row['region'] ??
                'CALABARZON'
            )
        );

    $zipCode =
        trim(
            (string)(
                $row['zip_code'] ??
                '4114'
            )
        );

    $country =
        trim(
            (string)(
                $row['country'] ??
                'Philippines'
            )
        );

    $otherLocation =
        trim(
            (string)(
                $row[
                    'other_location_info'
                ] ?? ''
            )
        );

    $exactLocation =
        trim(
            (string)(
                $row[
                    'exact_location'
                ] ?? ''
            )
        );

    if ($barangay === '') {
        $barangay =
            'Salitran IV';
    }

    if ($city === '') {
        $city =
            'Dasmarinas City';
    }

    if ($province === '') {
        $province =
            'Cavite';
    }

    if ($region === '') {
        $region =
            'CALABARZON';
    }

    if ($zipCode === '') {
        $zipCode =
            '4114';
    }

    if ($country === '') {
        $country =
            'Philippines';
    }


    /*
    |--------------------------------------------------------------------------
    | Insert homeowner
    |--------------------------------------------------------------------------
    */

    $insert =
        $conn->prepare(
            "INSERT INTO homeowners
            (
                public_id,

                first_name,
                middle_name,
                last_name,
                contact_number,
                email,
                password,

                must_change_password,

                phase,
                house_lot_number,
                block,
                lot,
                street,
                map_x,
                map_y,

                barangay,
                city_municipality,
                province,
                region,
                zip_code,
                country,
                other_location_info,
                exact_location,

                valid_id_path,
                proof_of_billing_path,

                status,
                admin_id,

                length_of_residency,
                residential_type,
                emergency_contact_person,
                emergency_contact_number
            )
            VALUES
            (
                NULL,

                ?, ?, ?, ?, ?, ?,

                1,

                ?, ?, ?, ?, ?, ?, ?,

                ?, ?, ?, ?, ?, ?, ?, ?,

                ?, ?,

                'pending',
                ?,

                ?, ?, ?, ?
            )"
        );


    $insert->bind_param(
        'sssssssssssiissssssssssissss',

        $row['first_name'],
        $row['middle_name'],
        $row['last_name'],
        $contact,
        $row['email'],
        $passwordHash,

        $row['phase'],
        $houseLot,
        $block,
        $lot,
        $street,

        $mapX,
        $mapY,

        $barangay,
        $city,
        $province,
        $region,
        $zipCode,
        $country,
        $otherLocation,
        $exactLocation,

        $validIdPath,
        $proofBillingPath,

        $adminId,

        $row[
            'length_of_residency'
        ],

        $row[
            'residential_type'
        ],

        $row[
            'emergency_contact_person'
        ],

        $row[
            'emergency_contact_number'
        ]
    );

    $insert->execute();

    $newId =
        (int)$insert->insert_id;

    $insert->close();


    /*
    |--------------------------------------------------------------------------
    | Public ID
    |--------------------------------------------------------------------------
    */

    $phaseNumber =
        (int)filter_var(
            $row['phase'],
            FILTER_SANITIZE_NUMBER_INT
        );

    if ($phaseNumber < 1) {
        throw new RuntimeException(
            'Invalid homeowner phase.'
        );
    }

    $publicId =
        'P' .
        $phaseNumber .
        $newId;

    $publicIdStmt =
        $conn->prepare(
            "UPDATE homeowners
             SET public_id=?
             WHERE id=?"
        );

    $publicIdStmt->bind_param(
        'si',
        $publicId,
        $newId
    );

    $publicIdStmt->execute();
    $publicIdStmt->close();


    /*
    |--------------------------------------------------------------------------
    | Mark queue approved
    |--------------------------------------------------------------------------
    */

    $queueUpdate =
        $conn->prepare(
            "UPDATE homeowner_import_queue
             SET
                status='approved',
                approved_homeowner_id=?,
                approved_at=NOW(),
                duplicate_homeowner_id=NULL,

                block=?,
                lot=?,
                street=?,
                map_x=?,
                map_y=?,
                house_lot_number=?

             WHERE id=?"
        );

    $queueUpdate->bind_param(
        'isssiisi',

        $newId,
        $block,
        $lot,
        $street,
        $mapX,
        $mapY,
        $houseLot,
        $queueId
    );

    $queueUpdate->execute();
    $queueUpdate->close();


    /*
    |--------------------------------------------------------------------------
    | Finish transaction
    |--------------------------------------------------------------------------
    */

    $conn->commit();


    respond([
        'success' =>
            true,

        'message' =>
            'Imported resident was transferred to homeowners and is now awaiting final approval.',

        'homeowner_id' =>
            $newId,

        'public_id' =>
            $publicId,

        'address' => [
            'phase' =>
                $row['phase'],

            'block' =>
                $block,

            'lot' =>
                $lot,

            'street' =>
                $street,

            'barangay' =>
                $barangay,

            'city_municipality' =>
                $city,

            'province' =>
                $province,

            'region' =>
                $region,

            'zip_code' =>
                $zipCode,

            'country' =>
                $country,

            'map_x' =>
                $mapX,

            'map_y' =>
                $mapY
        ]
    ]);


} catch (Throwable $e) {

    if (
        isset($conn) &&
        $conn instanceof mysqli
    ) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }

    respond(
        [
            'success' => false,
            'message' =>
                'Server error: ' .
                $e->getMessage()
        ],
        500
    );
}