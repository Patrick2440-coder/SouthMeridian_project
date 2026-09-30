<?php

declare(strict_types=1);

require_once __DIR__ . '/finance_helpers.php';
require_once __DIR__ . '/admin_access.php';
require_once __DIR__ . '/finance_dues_migration_common.php';

requireAccess('finance');
require_admin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');
$conn = db_conn();
$adminId = admin_id();
$admin = fdm_assert_treasurer($conn, $adminId);

if (!fdm_table_exists($conn, 'finance_dues_import_queue')) {
    fdm_json(['success'=>false,'message'=>'Historical dues migration table is not installed yet. Run finance_dues_migration_setup.sql first.'], 409);
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    fdm_json(['success'=>false,'message'=>'Invalid import request.'], 400);
}

fdm_assert_csrf((string)($payload['csrf'] ?? ''));

$phase = trim((string)($payload['phase'] ?? ''));
$adminPhase = trim((string)($admin['phase'] ?? ''));
if (!in_array($phase, ['Phase 1','Phase 2','Phase 3'], true) || $phase !== $adminPhase) {
    fdm_json(['success'=>false,'message'=>'The imported phase does not match your Treasurer account phase.'], 403);
}

$sourceFilename = mb_substr(trim((string)($payload['filename'] ?? '')), 0, 255);
$rows = $payload['rows'] ?? [];
if (!is_array($rows) || !$rows) {
    fdm_json(['success'=>false,'message'=>'No historical dues rows were found in the selected file.'], 422);
}
if (count($rows) > 2000) {
    fdm_json(['success'=>false,'message'=>'Please import no more than 2,000 rows at a time.'], 422);
}

$batchId = date('YmdHis') . '-' . bin2hex(random_bytes(8));
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$today = date('Y-m-d');
$normalizedRows = [];
$blankRows = 0;
$errors = [];

foreach ($rows as $index => $raw) {
    if (!is_array($raw)) {
        $blankRows++;
        continue;
    }

    $sourceRow = max(1, (int)($raw['_source_row'] ?? ($index + 4)));
    $homeownerName = mb_substr(trim((string)($raw['homeowner_name'] ?? '')), 0, 255);
    $rowPhase = trim((string)($raw['phase'] ?? ''));
    $block = mb_substr(trim((string)($raw['block'] ?? '')), 0, 50);
    $lot = mb_substr(trim((string)($raw['lot'] ?? '')), 0, 50);
    $street = mb_substr(trim((string)($raw['street_address'] ?? '')), 0, 255);
    $mobile = mb_substr(trim((string)($raw['mobile_number'] ?? '')), 0, 50);
    $email = mb_substr(trim((string)($raw['email'] ?? '')), 0, 255);
    $year = (int)($raw['pay_year'] ?? 0);
    $month = fdm_month_number($raw['pay_month'] ?? '');
    $amount = (float)($raw['amount'] ?? 0);
    $paidAt = fdm_parse_paid_date($raw['paid_at'] ?? '');
    $reference = mb_substr(trim((string)($raw['reference_no'] ?? '')), 0, 100);
    $notes = mb_substr(trim((string)($raw['notes'] ?? '')), 0, 255);
    $sourceRef = mb_substr(trim((string)($raw['source_reference'] ?? '')), 0, 255);

    $isBlank = ($homeownerName === '' && $rowPhase === '' && $block === '' && $lot === '' && $year === 0 && $amount <= 0 && $paidAt === null);
    if ($isBlank) {
        $blankRows++;
        continue;
    }

    $rowProblems = [];
    if ($homeownerName === '') $rowProblems[] = 'Homeowner Name is required';
    if ($rowPhase !== $phase) $rowProblems[] = 'Phase must be ' . $phase;
    if ($block === '') $rowProblems[] = 'Block is required';
    if ($lot === '') $rowProblems[] = 'Lot is required';
    if ($year < 2000 || $year > $currentYear) $rowProblems[] = 'Payment Year is invalid';
    if ($month < 1 || $month > 12) $rowProblems[] = 'Payment Month is invalid';
    if ($year === $currentYear && $month > $currentMonth) $rowProblems[] = 'Future payment months are not allowed';
    if ($amount <= 0 || $amount > 1000000) $rowProblems[] = 'Amount Paid must be greater than zero';
    if ($paidAt === null) $rowProblems[] = 'Paid Date is invalid';
    if ($paidAt !== null && substr($paidAt, 0, 10) > $today) $rowProblems[] = 'Future Paid Date is not allowed';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $rowProblems[] = 'Email format is invalid';

    if ($rowProblems) {
        $errors[] = 'Excel row ' . $sourceRow . ': ' . implode('; ', $rowProblems) . '.';
        if (count($errors) >= 20) {
            break;
        }
        continue;
    }

    $normalizedRows[] = [
        'source_row'=>$sourceRow,
        'raw_row_data'=>json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'homeowner_name'=>$homeownerName,
        'phase'=>$phase,
        'block'=>$block,
        'lot'=>$lot,
        'street_address'=>$street,
        'mobile_number'=>$mobile,
        'email'=>$email,
        'pay_year'=>$year,
        'pay_month'=>$month,
        'amount'=>$amount,
        'paid_at'=>(string)$paidAt,
        'reference_no'=>$reference,
        'notes'=>$notes,
        'source_reference'=>$sourceRef,
    ];
}

if ($errors) {
    fdm_json(['success'=>false,'message'=>'The Excel file contains invalid rows. Nothing was imported.','errors'=>$errors], 422);
}
if (!$normalizedRows) {
    fdm_json(['success'=>false,'message'=>'No usable historical dues rows were found in the selected file.'], 422);
}

$statusCounts = ['ready'=>0,'duplicate'=>0,'conflict'=>0,'unmatched'=>0,'needs_review'=>0];
$added = 0;

$conn->begin_transaction();
try {
    $insert = $conn->prepare(
        "INSERT INTO finance_dues_import_queue
        (batch_id, source_row, source_filename, raw_row_data, homeowner_name, phase, block, lot, street_address, mobile_number, email,
         pay_year, pay_month, amount, paid_at, reference_no, notes, source_reference,
         status, imported_by_admin_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'unmatched', ?)"
    );

    foreach ($normalizedRows as $row) {
        $sourceRow = (int)$row['source_row'];
        $rawRowData = (string)($row['raw_row_data'] ?? '');
        $homeownerName = (string)$row['homeowner_name'];
        $block = (string)$row['block'];
        $lot = (string)$row['lot'];
        $street = (string)$row['street_address'];
        $mobile = (string)$row['mobile_number'];
        $email = (string)$row['email'];
        $year = (int)$row['pay_year'];
        $month = (int)$row['pay_month'];
        $amount = (float)$row['amount'];
        $paidAt = (string)$row['paid_at'];
        $reference = (string)$row['reference_no'];
        $notes = (string)$row['notes'];
        $sourceRef = (string)$row['source_reference'];

        $insert->bind_param(
            'sisssssssssiidssssi',
            $batchId,
            $sourceRow,
            $sourceFilename,
            $rawRowData,
            $homeownerName,
            $phase,
            $block,
            $lot,
            $street,
            $mobile,
            $email,
            $year,
            $month,
            $amount,
            $paidAt,
            $reference,
            $notes,
            $sourceRef,
            $adminId
        );
        $insert->execute();
        $queueId = (int)$conn->insert_id;
        $result = fdm_recompute_queue_row($conn, $queueId);
        $status = (string)($result['status'] ?? 'unmatched');
        if (isset($statusCounts[$status])) {
            $statusCounts[$status]++;
        }
        $added++;
    }
    $insert->close();
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Historical dues import failed: ' . $e->getMessage());
    fdm_json(['success'=>false,'message'=>'The historical dues file could not be imported. No rows were saved.'], 500);
}

fdm_log(
    $conn,
    $adminId,
    $phase,
    'Historical dues Excel import processed',
    sprintf(
        'Batch %s: %d row(s) imported for review. Ready: %d; duplicates: %d; conflicts: %d; unmatched: %d; needs review: %d.',
        $batchId,
        $added,
        $statusCounts['ready'],
        $statusCounts['duplicate'],
        $statusCounts['conflict'],
        $statusCounts['unmatched'],
        $statusCounts['needs_review']
    ),
    'historical_dues_batch',
    null,
    null,
    [
        'batch_id'=>$batchId,
        'source_filename'=>$sourceFilename,
        'row_count'=>$added,
        'counts'=>$statusCounts
    ],
    $batchId
);

fdm_json([
    'success'=>true,
    'message'=>$added . ' historical dues row(s) imported for review.',
    'batch_id'=>$batchId,
    'added'=>$added,
    'skipped_blank'=>$blankRows,
    'counts'=>$statusCounts,
]);
