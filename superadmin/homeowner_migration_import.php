<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function respond(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizePhase(string $phase): string
{
    $phase = trim($phase);

    if (
        preg_match('/^phase\s*([123])$/i', $phase, $m) ||
        preg_match('/^([123])$/', $phase, $m)
    ) {
        return 'Phase ' . $m[1];
    }

    return $phase;
}

function loadSouthMeridianLocations(): array
{
    $path = dirname(__DIR__) . '/admin/southmeri_block_lot_mapping.json';

    if (!is_readable($path)) {
        throw new RuntimeException('The South Meridian Block/Lot map file was not found.');
    }

    $decoded = json_decode((string)file_get_contents($path), true);

    if (!is_array($decoded)) {
        throw new RuntimeException('The South Meridian Block/Lot map file is invalid.');
    }

    $pageWidthEmu = 8.5 * 914400;
    $pageHeightEmu = 11 * 914400;
    $pageMarginEmu = 914400;
    $markerCenterOffsetEmu = 90000;
    $locations = [];

    foreach ($decoded as $item) {
        $block = (int)($item['block'] ?? 0);
        $lot = (int)($item['lot'] ?? 0);

        if ($block <= 0 || $lot <= 0) {
            continue;
        }

        $xEmu = (float)($item['x_emu'] ?? 0);
        $yEmu = (float)($item['y_emu'] ?? 0);

        $locations[$block . ':' . $lot] = [
            'block' => $block,
            'lot' => $lot,
            'street' => trim((string)($item['street'] ?? '')),
            'map_x' => (int)round((($pageMarginEmu + $xEmu + $markerCenterOffsetEmu) / $pageWidthEmu) * 2550),
            'map_y' => (int)round((($pageMarginEmu + $yEmu + $markerCenterOffsetEmu) / $pageHeightEmu) * 3300),
        ];
    }

    return $locations;
}

function parseBlockLot(array $row): array
{
    $block = (int)($row['block'] ?? 0);
    $lot = (int)($row['lot'] ?? 0);

    if ($block > 0 && $lot > 0) {
        return [$block, $lot];
    }

    $legacy = trim((string)($row['house_lot_number'] ?? ''));

    if (
        $legacy !== '' &&
        preg_match('/(?:block|blk|b)\s*[-:#]?\s*(\d+)\D+(?:lot|l)\s*[-:#]?\s*(\d+)/i', $legacy, $m)
    ) {
        return [(int)$m[1], (int)$m[2]];
    }

    return [0, 0];
}

function queueConflict(
    mysqli $conn,
    int $sourceRow,
    string $firstName,
    string $middleName,
    string $lastName,
    string $contact,
    string $email,
    string $phase,
    string $block,
    string $lot,
    string $street,
    int $mapX,
    int $mapY,
    string $houseLot,
    string $barangay,
    string $city,
    string $province,
    string $region,
    string $zipCode,
    string $country,
    string $otherLocation,
    string $exactLocation,
    string $lengthOfResidency,
    string $residentialType,
    string $emergencyPerson,
    string $emergencyNumber,
    int $existingHomeownerId,
    int $adminId
): void {
    $stmt = $conn->prepare(
        "INSERT INTO homeowner_import_queue
        (source_row, first_name, middle_name, last_name, contact_number, email,
         phase, block, lot, street, map_x, map_y, house_lot_number,
         barangay, city_municipality, province, region, zip_code, country,
         other_location_info, exact_location, length_of_residency, residential_type,
         emergency_contact_person, emergency_contact_number,
         status, duplicate_homeowner_id, imported_by)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
         'duplicate', ?, ?)"
    );

    $stmt->bind_param(
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

    $stmt->execute();
    $stmt->close();
}

try {
    if (
        empty($_SESSION['admin_id']) ||
        empty($_SESSION['admin_role']) ||
        $_SESSION['admin_role'] !== 'superadmin'
    ) {
        respond([
            'success' => false,
            'message' => 'Superadmin access is required.'
        ], 401);
    }

    require_once '../config/database.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond([
            'success' => false,
            'message' => 'Invalid request method.'
        ], 405);
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);

    if (!is_array($payload)) {
        respond([
            'success' => false,
            'message' => 'Invalid import request.'
        ], 400);
    }

    $csrf = (string)($payload['csrf'] ?? '');
    $sessionCsrf = (string)($_SESSION['superadmin_homeowner_import_csrf'] ?? '');

    if (
        $csrf === '' ||
        $sessionCsrf === '' ||
        !hash_equals($sessionCsrf, $csrf)
    ) {
        respond([
            'success' => false,
            'message' => 'Security token expired. Reload the page and try again.'
        ], 403);
    }

    $rows = $payload['rows'] ?? [];

    if (!is_array($rows) || count($rows) === 0) {
        respond([
            'success' => false,
            'message' => 'No homeowner rows were received.'
        ], 400);
    }

    $adminId = (int)$_SESSION['admin_id'];
    $rowOffset = (int)($payload['row_offset'] ?? 0);
    $locations = loadSouthMeridianLocations();

    $activePropertyOwners = [];
    $ownerResult = $conn->query(
        "SELECT id, first_name, middle_name, last_name, email, phase,
                block, lot, house_lot_number, status
         FROM homeowners
         WHERE status='approved'"
    );

    while ($owner = $ownerResult->fetch_assoc()) {
        [$ownerBlock, $ownerLot] = parseBlockLot($owner);

        if ($ownerBlock <= 0 || $ownerLot <= 0) {
            continue;
        }

        $key = (string)$owner['phase'] . '|' . $ownerBlock . '|' . $ownerLot;

        if (!isset($activePropertyOwners[$key])) {
            $activePropertyOwners[$key] = $owner;
        }
    }

    $imported = 0;
    $duplicates = 0;
    $possibleTransfers = 0;
    $skipped = 0;
    $errors = [];

    foreach ($rows as $index => $row) {
        $sourceRow = $rowOffset + $index + 1;

        try {
            if (!is_array($row)) {
                continue;
            }

            $hasData = false;

            foreach ([
                'first_name', 'middle_name', 'last_name', 'contact_number', 'email',
                'block', 'lot', 'other_location_info', 'length_of_residency',
                'residential_type', 'emergency_contact_person', 'emergency_contact_number'
            ] as $field) {
                if (trim((string)($row[$field] ?? '')) !== '') {
                    $hasData = true;
                    break;
                }
            }

            if (!$hasData) {
                continue;
            }

            $firstName = trim((string)($row['first_name'] ?? ''));
            $middleName = trim((string)($row['middle_name'] ?? ''));
            $lastName = trim((string)($row['last_name'] ?? ''));
            $contact = trim((string)($row['contact_number'] ?? ''));
            $email = strtolower(trim((string)($row['email'] ?? '')));
            $phase = normalizePhase((string)($row['phase'] ?? ''));

            if ($firstName === '') {
                throw new RuntimeException('First name is required.');
            }

            if ($lastName === '') {
                throw new RuntimeException('Last name is required.');
            }

            if ($contact === '') {
                throw new RuntimeException('Contact number is required.');
            }

            if (mb_strlen($contact) > 15) {
                throw new RuntimeException('Contact number exceeds the 15-character limit.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('A valid email address is required.');
            }

            if (!in_array($phase, ['Phase 1', 'Phase 2', 'Phase 3'], true)) {
                throw new RuntimeException('Phase must be Phase 1, Phase 2, or Phase 3.');
            }

            $blockNumber = (int)($row['block'] ?? 0);
            $lotNumber = (int)($row['lot'] ?? 0);
            $locationKey = $blockNumber . ':' . $lotNumber;

            if (
                $blockNumber <= 0 ||
                $lotNumber <= 0 ||
                !isset($locations[$locationKey])
            ) {
                throw new RuntimeException(
                    "Block {$blockNumber}, Lot {$lotNumber} does not exist on the official South Meridian map."
                );
            }

            $block = (string)$blockNumber;
            $lot = (string)$lotNumber;
            $street = (string)$locations[$locationKey]['street'];
            $mapX = (int)$locations[$locationKey]['map_x'];
            $mapY = (int)$locations[$locationKey]['map_y'];
            $houseLot = "Block {$blockNumber} Lot {$lotNumber}";

            $barangay = trim((string)($row['barangay'] ?? 'Salitran IV')) ?: 'Salitran IV';
            $city = trim((string)($row['city_municipality'] ?? 'Dasmarinas City')) ?: 'Dasmarinas City';
            $province = trim((string)($row['province'] ?? 'Cavite')) ?: 'Cavite';
            $region = trim((string)($row['region'] ?? 'CALABARZON')) ?: 'CALABARZON';
            $zipCode = trim((string)($row['zip_code'] ?? '4114')) ?: '4114';
            $country = trim((string)($row['country'] ?? 'Philippines')) ?: 'Philippines';
            $otherLocation = trim((string)($row['other_location_info'] ?? ''));
            $exactLocation = trim((string)($row['exact_location'] ?? ''));

            if ($exactLocation === '') {
                $exactLocation = "Block {$blockNumber}, Lot {$lotNumber}" .
                    ($street !== '' ? ", {$street}" : '');
            }

            $lengthOfResidency = trim((string)($row['length_of_residency'] ?? ''));
            $residentialType = trim((string)($row['residential_type'] ?? ''));
            $emergencyPerson = trim((string)($row['emergency_contact_person'] ?? ''));
            $emergencyNumber = trim((string)($row['emergency_contact_number'] ?? ''));

            if ($lengthOfResidency === '') {
                throw new RuntimeException('Length of residency is required.');
            }

            if ($residentialType === '') {
                throw new RuntimeException('Residential type is required.');
            }

            if ($emergencyPerson === '') {
                throw new RuntimeException('Emergency contact person is required.');
            }

            if ($emergencyNumber === '') {
                throw new RuntimeException('Emergency contact number is required.');
            }

            $emailStmt = $conn->prepare(
                "SELECT id, first_name, middle_name, last_name, email, phase,
                        block, lot, house_lot_number, status
                 FROM homeowners
                 WHERE LOWER(TRIM(email))=?
                 LIMIT 1"
            );
            $emailStmt->bind_param('s', $email);
            $emailStmt->execute();
            $existingByEmail = $emailStmt->get_result()->fetch_assoc();
            $emailStmt->close();

            if ($existingByEmail) {
                queueConflict(
                    $conn,
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
                    (int)$existingByEmail['id'],
                    $adminId
                );

                $duplicates++;
                continue;
            }

            $propertyKey = $phase . '|' . $blockNumber . '|' . $lotNumber;
            $existingPropertyOwner = $activePropertyOwners[$propertyKey] ?? null;

            if ($existingPropertyOwner) {
                queueConflict(
                    $conn,
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
                    (int)$existingPropertyOwner['id'],
                    $adminId
                );

                $possibleTransfers++;
                continue;
            }

            $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $validIdPath = 'imports/not_provided';
            $proofBillingPath = 'imports/not_provided';

            $insert = $conn->prepare(
                "INSERT INTO homeowners
                (public_id, first_name, middle_name, last_name, contact_number, email, password,
                 must_change_password, phase, house_lot_number, block, lot, street, map_x, map_y,
                 barangay, city_municipality, province, region, zip_code, country,
                 other_location_info, exact_location, valid_id_path, proof_of_billing_path,
                 status, admin_id, length_of_residency, residential_type,
                 emergency_contact_person, emergency_contact_number)
                VALUES
                (NULL, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                 'pending', ?, ?, ?, ?, ?)"
            );

            $insert->bind_param(
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

            $insert->execute();
            $newHomeownerId = (int)$insert->insert_id;
            $insert->close();

            $phaseNumber = (int)filter_var($phase, FILTER_SANITIZE_NUMBER_INT);
            $publicId = 'P' . $phaseNumber . $newHomeownerId;

            $publicIdStmt = $conn->prepare(
                'UPDATE homeowners SET public_id=? WHERE id=? LIMIT 1'
            );
            $publicIdStmt->bind_param('si', $publicId, $newHomeownerId);
            $publicIdStmt->execute();
            $publicIdStmt->close();

            // Keep the property occupied for the remainder of this same import.
            $activePropertyOwners[$propertyKey] = [
                'id' => $newHomeownerId,
                'email' => $email,
                'phase' => $phase,
                'block' => $block,
                'lot' => $lot,
                'house_lot_number' => $houseLot,
                'status' => 'pending',
            ];

            $imported++;

        } catch (Throwable $rowError) {
            $skipped++;
            $errors[] = "Excel row {$sourceRow}: " . $rowError->getMessage();
        }
    }

    respond([
        'success' => true,
        'imported' => $imported,
        'duplicates' => $duplicates,
        'possible_transfers' => $possibleTransfers,
        'skipped' => $skipped,
        'processed' => $imported + $duplicates + $possibleTransfers,
        'errors' => $errors,
        'message' =>
            "Import completed. {$imported} added for review, {$duplicates} duplicate(s), " .
            "{$possibleTransfers} possible ownership transfer(s), {$skipped} skipped."
    ]);

} catch (Throwable $e) {
    error_log('Superadmin homeowner migration import error: ' . $e->getMessage());

    respond([
        'success' => false,
        'message' => 'Import could not be completed. ' . $e->getMessage()
    ], 500);
}
