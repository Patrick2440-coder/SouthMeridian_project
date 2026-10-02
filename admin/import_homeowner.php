<?php

ob_start();

ini_set('display_errors', '0');

error_reporting(E_ALL);

mysqli_report(

    MYSQLI_REPORT_ERROR |

    MYSQLI_REPORT_STRICT

);

/*

|--------------------------------------------------------------------------

| JSON response

|--------------------------------------------------------------------------

*/

function respond(array $data, int $status = 200): void

{

    while (ob_get_level() > 0) {

        ob_end_clean();

    }

    http_response_code($status);

    header(

        'Content-Type: application/json; charset=utf-8'

    );

    echo json_encode(

        $data,

        JSON_UNESCAPED_UNICODE |

        JSON_UNESCAPED_SLASHES

    );

    exit;

}

/*

|--------------------------------------------------------------------------

| Load official South Meridian Block/Lot map

|--------------------------------------------------------------------------

*/

function loadSouthMeridianLocations(): array

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

        if (

            $block <= 0 ||

            $lot <= 0

        ) {

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

                    /

                    $pageWidthEmu

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

                    /

                    $pageHeightEmu

                )

                * 3300

            );

        $locations[

            $block . ':' . $lot

        ] = [

            'block' =>

                $block,

            'lot' =>

                $lot,

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

/*

|--------------------------------------------------------------------------

| Normalize phase

|--------------------------------------------------------------------------

*/

function normalizePhase(string $phase): string

{

    $phase =

        trim($phase);

    if (

        preg_match(

            '/^phase\s*([123])$/i',

            $phase,

            $match

        )

    ) {

        return

            'Phase ' .

            $match[1];

    }

    if (

        preg_match(

            '/^([123])$/',

            $phase,

            $match

        )

    ) {

        return

            'Phase ' .

            $match[1];

    }

    return $phase;

}

/*

|--------------------------------------------------------------------------

| Main

|--------------------------------------------------------------------------

*/

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

            [

                'admin',

                'superadmin'

            ],

            true

        )

    ) {

        respond(

            [

                'success' => false,

                'message' =>

                    'Unauthorized.'

            ],

            401

        );

    }

    /*

    |--------------------------------------------------------------------------

    | Read JSON

    |--------------------------------------------------------------------------

    */

    $rawInput =

        file_get_contents(

            'php://input'

        );

    $payload =

        json_decode(

            (string)$rawInput,

            true

        );

    if (!is_array($payload)) {

        respond(

            [

                'success' => false,

                'message' =>

                    'Invalid import request.'

            ],

            400

        );

    }

    /*

    |--------------------------------------------------------------------------

    | CSRF

    |--------------------------------------------------------------------------

    */

    $csrf =

        (string)(

            $payload['csrf'] ?? ''

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

    | Excel rows

    |--------------------------------------------------------------------------

    */

    $rows =

        $payload['rows'] ?? [];

    if (

        !is_array($rows) ||

        count($rows) === 0

    ) {

        respond(

            [

                'success' => false,

                'message' =>

                    'No homeowner rows were received.'

            ],

            400

        );

    }

    $rowOffset =

        (int)(

            $payload[

                'row_offset'

            ] ?? 0

        );

    /*

    |--------------------------------------------------------------------------

    | Admin information

    |--------------------------------------------------------------------------

    */

    $adminId =

        (int)$_SESSION['admin_id'];

    $adminStmt =

        $conn->prepare(

            "SELECT

                phase,

                role

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

        respond(

            [

                'success' => false,

                'message' =>

                    'Admin account was not found.'

            ],

            403

        );

    }

    $adminRole =

        (string)$admin['role'];

    $adminPhase =

        normalizePhase(

            (string)$admin['phase']

        );

    /*

    |--------------------------------------------------------------------------

    | Load subdivision map

    |--------------------------------------------------------------------------

    */

    $locations =

        loadSouthMeridianLocations();

    /*

    |--------------------------------------------------------------------------

    | Counters

    |--------------------------------------------------------------------------

    */

    $imported = 0;

    $duplicates = 0;

    $skipped = 0;

    $errors = [];

    /*

    |--------------------------------------------------------------------------

    | Process EVERY Excel row

    |--------------------------------------------------------------------------

    */

    foreach (

        $rows as

        $index => $row

    ) {

        $sourceRow =

            $rowOffset +

            $index +

            1;

        try {

            if (!is_array($row)) {

                continue;

            }

            /*

            |--------------------------------------------------------------------------

            | Ignore completely blank rows only

            |--------------------------------------------------------------------------

            */

            $hasData = false;

            foreach ($row as $value) {

                if (

                    trim(

                        (string)$value

                    ) !== ''

                ) {

                    $hasData = true;

                    break;

                }

            }

            if (!$hasData) {

                /*

                 * Completely blank Excel row.

                 *

                 * Do not import it.

                 * Do not count it as skipped.

                 */

                continue;

            }

            /*

            |--------------------------------------------------------------------------

            | Personal information

            |--------------------------------------------------------------------------

            */

            $firstName =

                trim(

                    (string)(

                        $row[

                            'first_name'

                        ] ?? ''

                    )

                );

            $middleName =

                trim(

                    (string)(

                        $row[

                            'middle_name'

                        ] ?? ''

                    )

                );

            $lastName =

                trim(

                    (string)(

                        $row[

                            'last_name'

                        ] ?? ''

                    )

                );

            $contact =

                trim(

                    (string)(

                        $row[

                            'contact_number'

                        ] ?? ''

                    )

                );

            $email =

                strtolower(

                    trim(

                        (string)(

                            $row[

                                'email'

                            ] ?? ''

                        )

                    )

                );

            $phase =

                normalizePhase(

                    (string)(

                        $row[

                            'phase'

                        ] ?? ''

                    )

                );

            /*

            |--------------------------------------------------------------------------

            | Required information

            |--------------------------------------------------------------------------

            */

            if ($firstName === '') {

                throw new RuntimeException(

                    'First name is required.'

                );

            }

            if ($lastName === '') {

                throw new RuntimeException(

                    'Last name is required.'

                );

            }

            if ($contact === '') {

                throw new RuntimeException(

                    'Contact number is required.'

                );

            }

            if (

                $email === '' ||

                !filter_var(

                    $email,

                    FILTER_VALIDATE_EMAIL

                )

            ) {

                throw new RuntimeException(

                    'A valid email address is required.'

                );

            }

            if (

                !in_array(

                    $phase,

                    [

                        'Phase 1',

                        'Phase 2',

                        'Phase 3'

                    ],

                    true

                )

            ) {

                throw new RuntimeException(

                    'Invalid phase.'

                );

            }

            /*

            |--------------------------------------------------------------------------

            | Admin phase restriction

            |--------------------------------------------------------------------------

            */

            if (

                $adminRole !==

                    'superadmin' &&

                $phase !==

                    $adminPhase

            ) {

                throw new RuntimeException(

                    "You can only import homeowners for {$adminPhase}."

                );

            }

            /*

            |--------------------------------------------------------------------------

            | Block / Lot

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

            if (

                $blockNumber <= 0 ||

                $lotNumber <= 0 ||

                !isset(

                    $locations[

                        $locationKey

                    ]

                )

            ) {

                throw new RuntimeException(

                    "Block {$blockNumber}, Lot {$lotNumber} does not exist on the official South Meridian map."

                );

            }

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

            | Address information

            |--------------------------------------------------------------------------

            */

            $barangay =

                trim(

                    (string)(

                        $row[

                            'barangay'

                        ]

                        ?? 'Salitran IV'

                    )

                );

            $city =

                trim(

                    (string)(

                        $row[

                            'city_municipality'

                        ]

                        ?? 'Dasmarinas City'

                    )

                );

            $province =

                trim(

                    (string)(

                        $row[

                            'province'

                        ]

                        ?? 'Cavite'

                    )

                );

            $region =

                trim(

                    (string)(

                        $row[

                            'region'

                        ]

                        ?? 'CALABARZON'

                    )

                );

            $zipCode =

                trim(

                    (string)(

                        $row[

                            'zip_code'

                        ]

                        ?? '4114'

                    )

                );

            $country =

                trim(

                    (string)(

                        $row[

                            'country'

                        ]

                        ?? 'Philippines'

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

            /*

            |--------------------------------------------------------------------------

            | Residency

            |--------------------------------------------------------------------------

            */

            $lengthOfResidency =

                trim(

                    (string)(

                        $row[

                            'length_of_residency'

                        ] ?? ''

                    )

                );

            $residentialType =

                trim(

                    (string)(

                        $row[

                            'residential_type'

                        ] ?? ''

                    )

                );

            /*

            |--------------------------------------------------------------------------

            | Emergency contact

            |--------------------------------------------------------------------------

            */

            $emergencyPerson =

                trim(

                    (string)(

                        $row[

                            'emergency_contact_person'

                        ] ?? ''

                    )

                );

            $emergencyNumber =

                trim(

                    (string)(

                        $row[

                            'emergency_contact_number'

                        ] ?? ''

                    )

                );

            /*

            |--------------------------------------------------------------------------

            | Defaults

            |--------------------------------------------------------------------------

            */

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

            if ($exactLocation === '') {

                $exactLocation =

                    "Block {$blockNumber}, Lot {$lotNumber}";

                if ($street !== '') {

                    $exactLocation .=

                        ", {$street}";

                }

            }

            /*

            |--------------------------------------------------------------------------

            | Required Excel values

            |--------------------------------------------------------------------------

            */

            if (

                $lengthOfResidency === ''

            ) {

                throw new RuntimeException(

                    'Length of residency is required.'

                );

            }

            if (

                $residentialType === ''

            ) {

                throw new RuntimeException(

                    'Residential type is required.'

                );

            }

            if (

                $emergencyPerson === ''

            ) {

                throw new RuntimeException(

                    'Emergency contact person is required.'

                );

            }

            if (

                $emergencyNumber === ''

            ) {

                throw new RuntimeException(

                    'Emergency contact number is required.'

                );

            }

            /*

            |--------------------------------------------------------------------------

            | Find existing homeowner by email

            |--------------------------------------------------------------------------

            */

            $duplicateStmt =

                $conn->prepare(

                    "SELECT

                        id,

                        public_id,

                        first_name,

                        middle_name,

                        last_name,

                        email

                     FROM homeowners

                     WHERE LOWER(

                        TRIM(email)

                     ) = ?

                     LIMIT 1"

                );

            $duplicateStmt

                ->bind_param(

                    's',

                    $email

                );

            $duplicateStmt

                ->execute();

            $existingHomeowner =

                $duplicateStmt

                    ->get_result()

                    ->fetch_assoc();

            $duplicateStmt

                ->close();

            /*

            |--------------------------------------------------------------------------

            | DUPLICATE

            |--------------------------------------------------------------------------

            |

            | IMPORTANT:

            |

            | Every duplicate Excel row gets its OWN queue row.

            |

            | We no longer:

            |

            | SELECT homeowner_import_queue by email

            | UPDATE an old queue record

            |

            | Therefore 5 duplicate Excel rows = 5 queue rows.

            |

            */

            if ($existingHomeowner) {

                $existingHomeownerId =

                    (int)$existingHomeowner[

                        'id'

                    ];

                $queueStmt =

                    $conn->prepare(

                        "INSERT INTO homeowner_import_queue

                        (

                            source_row,

                            first_name,

                            middle_name,

                            last_name,

                            contact_number,

                            email,

                            phase,

                            block,

                            lot,

                            street,

                            map_x,

                            map_y,

                            house_lot_number,

                            barangay,

                            city_municipality,

                            province,

                            region,

                            zip_code,

                            country,

                            other_location_info,

                            exact_location,

                            length_of_residency,

                            residential_type,

                            emergency_contact_person,

                            emergency_contact_number,

                            status,

                            duplicate_homeowner_id,

                            imported_by

                        )

                        VALUES

                        (

                            ?,

                            ?, ?, ?,

                            ?, ?,

                            ?,

                            ?, ?, ?,

                            ?, ?,

                            ?,

                            ?, ?, ?, ?, ?, ?,

                            ?, ?,

                            ?, ?,

                            ?, ?,

                            'duplicate',

                            ?,

                            ?

                        )"

                    );

                $queueStmt

                    ->bind_param(

                        'isssssssssiisssssssssssssii',

                        $sourceRow,

                        $firstName,

                        $middleName,

                        $lastName,

                        $contact,

                        $email,

                        $phase,

                        $block,

                        $lot,

                        $street,

                        $mapX,

                        $mapY,

                        $houseLot,

                        $barangay,

                        $city,

                        $province,

                        $region,

                        $zipCode,

                        $country,

                        $otherLocation,

                        $exactLocation,

                        $lengthOfResidency,

                        $residentialType,

                        $emergencyPerson,

                        $emergencyNumber,

                        $existingHomeownerId,

                        $adminId

                    );

                $queueStmt

                    ->execute();

                $queueStmt

                    ->close();

                $duplicates++;

                /*

                 * Do not insert this duplicate

                 * into homeowners.

                 */

                continue;

            }

            /*

            |--------------------------------------------------------------------------

            | Contact-number limitation

            |--------------------------------------------------------------------------

            */

            if (

                strlen(

                    $contact

                ) > 15

            ) {

                throw new RuntimeException(

                    'Contact number exceeds the 15-character homeowner limit.'

                );

            }

            /*

            |--------------------------------------------------------------------------

            | Temporary imported account password

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

             * These also let ho_approval.php

             * identify the record as coming

             * from Excel.

             */

            $validIdPath =

                'imports/not_provided';

            $proofBillingPath =

                'imports/not_provided';

            /*

            |--------------------------------------------------------------------------

            | Insert valid homeowner directly

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

                        ?, ?, ?,

                        ?, ?, ?,

                        1,

                        ?,

                        ?,

                        ?, ?, ?,

                        ?, ?,

                        ?, ?, ?, ?, ?, ?,

                        ?, ?,

                        ?, ?,

                        'pending',

                        ?,

                        ?, ?,

                        ?, ?

                    )"

                );

            $insert

                ->bind_param(

                    'sssssssssssiissssssssssissss',

                    $firstName,

                    $middleName,

                    $lastName,

                    $contact,

                    $email,

                    $passwordHash,

                    $phase,

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

                    $lengthOfResidency,

                    $residentialType,

                    $emergencyPerson,

                    $emergencyNumber

                );

            $insert

                ->execute();

            $newHomeownerId =

                (int)$insert

                    ->insert_id;

            $insert

                ->close();

            /*

            |--------------------------------------------------------------------------

            | Generate public ID

            |--------------------------------------------------------------------------

            */

            $phaseNumber =

                (int)filter_var(

                    $phase,

                    FILTER_SANITIZE_NUMBER_INT

                );

            if (

                $phaseNumber < 1 ||

                $phaseNumber > 3

            ) {

                throw new RuntimeException(

                    'Unable to generate homeowner public ID.'

                );

            }

            $publicId =
                'P' .
                $phaseNumber .
                'H' .
                str_pad(
                    (string)$newHomeownerId,
                    3,
                    '0',
                    STR_PAD_LEFT
                );

            $publicIdStmt =

                $conn->prepare(

                    "UPDATE homeowners

                     SET public_id=?

                     WHERE id=?"

                );

            $publicIdStmt

                ->bind_param(

                    'si',

                    $publicId,

                    $newHomeownerId

                );

            $publicIdStmt

                ->execute();

            $publicIdStmt

                ->close();

            /*

            |--------------------------------------------------------------------------

            | Successful valid import

            |--------------------------------------------------------------------------

            */

            $imported++;

        } catch (Throwable $rowError) {

            /*

             * One bad row must NOT stop the

             * remaining Excel rows.

             */

            $skipped++;

            $errors[] =

                "Excel row {$sourceRow}: " .

                $rowError->getMessage();

        }

    }

    /*

    |--------------------------------------------------------------------------

    | Final import response

    |--------------------------------------------------------------------------

    */

    $totalProcessed =

        $imported +

        $duplicates;

    respond([

        'success' =>

            true,

        /*

         * Successfully inserted into homeowners.

         */

        'imported' =>

            $imported,

        /*

         * Successfully inserted into

         * homeowner_import_queue.

         */

        'duplicates' =>

            $duplicates,

        /*

         * Invalid/incomplete rows.

         */

        'skipped' =>

            $skipped,

        /*

         * Imported + duplicates.

         *

         * This should match the number of

         * usable/nonblank Excel rows unless

         * some rows have validation errors.

         */

        'processed' =>

            $totalProcessed,

        'errors' =>

            $errors,

        'message' =>

            "Import completed. " .

            "{$imported} added for review, " .

            "{$duplicates} duplicate(s), " .

            "{$skipped} skipped."

    ]);

} catch (Throwable $e) {

    respond(

        [

            'success' =>

                false,

            'message' =>

                'Import server error: ' .

                $e->getMessage()

        ],

        500

    );

}
