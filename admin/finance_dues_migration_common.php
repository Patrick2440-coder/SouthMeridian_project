<?php

declare(strict_types=1);

require_once __DIR__ . '/finance_audit_logger.php';

if (!function_exists('fdm_json')) {
    function fdm_json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('fdm_table_exists')) {
    function fdm_table_exists(mysqli $conn, string $table): bool
    {
        $safe = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return $res instanceof mysqli_result && $res->num_rows > 0;
    }
}

if (!function_exists('fdm_column_exists')) {
    function fdm_column_exists(mysqli $conn, string $table, string $column): bool
    {
        $safeTable = $conn->real_escape_string($table);
        $safeColumn = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $res instanceof mysqli_result && $res->num_rows > 0;
    }
}

if (!function_exists('fdm_current_admin')) {
    function fdm_current_admin(mysqli $conn, int $adminId): array
    {
        $stmt = $conn->prepare('SELECT id, role, position, phase FROM admins WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return $row;
    }
}

if (!function_exists('fdm_assert_treasurer')) {
    function fdm_assert_treasurer(mysqli $conn, int $adminId): array
    {
        $admin = fdm_current_admin($conn, $adminId);
        if (!$admin || (string)($admin['position'] ?? '') !== 'Treasurer') {
            fdm_json(['success' => false, 'message' => 'Only the Treasurer can manage historical dues migration.'], 403);
        }
        return $admin;
    }
}

if (!function_exists('fdm_assert_csrf')) {
    function fdm_assert_csrf(string $token): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $expected = (string)($_SESSION['csrf_finance_dues'] ?? '');
        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            fdm_json(['success' => false, 'message' => 'Your session token is no longer valid. Refresh the page and try again.'], 419);
        }
    }
}

if (!function_exists('fdm_normalize_text')) {
    function fdm_normalize_text(?string $value): string
    {
        $value = mb_strtolower(trim((string)$value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}

if (!function_exists('fdm_normalize_phone')) {
    function fdm_normalize_phone(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        }
        return $digits;
    }
}

if (!function_exists('fdm_normalize_email')) {
    function fdm_normalize_email(?string $value): string
    {
        return mb_strtolower(trim((string)$value), 'UTF-8');
    }
}

if (!function_exists('fdm_month_number')) {
    function fdm_month_number(mixed $value): int
    {
        if (is_numeric($value)) {
            $n = (int)$value;
            return ($n >= 1 && $n <= 12) ? $n : 0;
        }
        $name = fdm_normalize_text((string)$value);
        $map = [
            'january'=>1,'jan'=>1,'february'=>2,'feb'=>2,'march'=>3,'mar'=>3,
            'april'=>4,'apr'=>4,'may'=>5,'june'=>6,'jun'=>6,'july'=>7,'jul'=>7,
            'august'=>8,'aug'=>8,'september'=>9,'sep'=>9,'sept'=>9,
            'october'=>10,'oct'=>10,'november'=>11,'nov'=>11,'december'=>12,'dec'=>12,
        ];
        return $map[$name] ?? 0;
    }
}

if (!function_exists('fdm_parse_paid_date')) {
    function fdm_parse_paid_date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d 00:00:00', $ts);
    }
}

if (!function_exists('fdm_homeowner_name_variants')) {
    function fdm_homeowner_name_variants(array $h): array
    {
        $first = trim((string)($h['first_name'] ?? ''));
        $middle = trim((string)($h['middle_name'] ?? ''));
        $last = trim((string)($h['last_name'] ?? ''));
        $variants = [];
        $variants[] = fdm_normalize_text(trim("{$first} {$middle} {$last}"));
        $variants[] = fdm_normalize_text(trim("{$first} {$last}"));
        if ($middle !== '') {
            $middleInitial = mb_substr($middle, 0, 1, 'UTF-8');
            $variants[] = fdm_normalize_text(trim("{$first} {$middleInitial} {$last}"));
        }
        return array_values(array_unique(array_filter($variants)));
    }
}

if (!function_exists('fdm_find_candidates')) {
    function fdm_find_candidates(mysqli $conn, string $phase, string $block, string $lot): array
    {
        $stmt = $conn->prepare(
            "SELECT id, public_id, first_name, middle_name, last_name, contact_number, email, phase, block, lot, street, house_lot_number, status
             FROM homeowners
             WHERE phase=? AND block=? AND lot=? AND status IN ('approved','former')
             ORDER BY CASE WHEN status='approved' THEN 0 ELSE 1 END, id DESC"
        );
        $stmt->bind_param('sss', $phase, $block, $lot);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('fdm_get_transfer_rows')) {
    function fdm_get_transfer_rows(mysqli $conn, string $phase, string $block, string $lot): array
    {
        if (!fdm_table_exists($conn, 'homeowner_ownership_transfers')) {
            return [];
        }
        $stmt = $conn->prepare(
            "SELECT previous_homeowner_id, new_homeowner_id, completed_at
             FROM homeowner_ownership_transfers
             WHERE phase=? AND block=? AND lot=? AND status='completed'
             ORDER BY completed_at ASC, id ASC"
        );
        $stmt->bind_param('sss', $phase, $block, $lot);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('fdm_temporal_transfer_warning')) {
    function fdm_temporal_transfer_warning(mysqli $conn, int $homeownerId, string $phase, string $block, string $lot, string $paidAt): ?string
    {
        $paidTs = strtotime($paidAt);
        if ($paidTs === false) {
            return null;
        }
        foreach (fdm_get_transfer_rows($conn, $phase, $block, $lot) as $tr) {
            $completedTs = strtotime((string)($tr['completed_at'] ?? ''));
            if ($completedTs === false) {
                continue;
            }
            $oldId = (int)($tr['previous_homeowner_id'] ?? 0);
            $newId = (int)($tr['new_homeowner_id'] ?? 0);
            if ($homeownerId === $newId && $paidTs < $completedTs) {
                return 'Payment date is before this homeowner became the recorded owner. Review ownership history.';
            }
            if ($homeownerId === $oldId && $paidTs > $completedTs) {
                return 'Payment date is after this homeowner transferred the property. Review ownership history.';
            }
        }
        return null;
    }
}

if (!function_exists('fdm_match_homeowner')) {
    function fdm_match_homeowner(mysqli $conn, array $row): array
    {
        $phase = trim((string)($row['phase'] ?? ''));
        $block = trim((string)($row['block'] ?? ''));
        $lot = trim((string)($row['lot'] ?? ''));
        $name = fdm_normalize_text((string)($row['homeowner_name'] ?? ''));
        $phone = fdm_normalize_phone((string)($row['mobile_number'] ?? ''));
        $email = fdm_normalize_email((string)($row['email'] ?? ''));
        $street = fdm_normalize_text((string)($row['street_address'] ?? ''));
        $paidAt = (string)($row['paid_at'] ?? '');

        $candidates = fdm_find_candidates($conn, $phase, $block, $lot);
        if (!$candidates) {
            return ['homeowner_id'=>null,'status'=>'unmatched','method'=>null,'score'=>0,'note'=>'No approved/former homeowner was found for this Phase, Block, and Lot.'];
        }

        $scored = [];
        foreach ($candidates as $h) {
            $score = 0;
            $methods = [];
            $nameMatch = in_array($name, fdm_homeowner_name_variants($h), true);
            if ($nameMatch) {
                $score += 60;
                $methods[] = 'name+property';
            }
            $candidatePhone = fdm_normalize_phone((string)($h['contact_number'] ?? ''));
            if ($phone !== '' && $candidatePhone !== '' && $phone === $candidatePhone) {
                $score += 20;
                $methods[] = 'mobile';
            }
            $candidateEmail = fdm_normalize_email((string)($h['email'] ?? ''));
            if ($email !== '' && $candidateEmail !== '' && $email === $candidateEmail) {
                $score += 25;
                $methods[] = 'email';
            }
            $candidateStreet = fdm_normalize_text((string)($h['street'] ?? ''));
            $candidateHouse = fdm_normalize_text((string)($h['house_lot_number'] ?? ''));
            if ($street !== '' && ($street === $candidateStreet || $street === $candidateHouse)) {
                $score += 10;
                $methods[] = 'address';
            }
            $scored[] = ['homeowner'=>$h,'score'=>$score,'methods'=>$methods,'name_match'=>$nameMatch];
        }

        usort($scored, fn($a,$b) => $b['score'] <=> $a['score']);
        $best = $scored[0];
        $secondScore = $scored[1]['score'] ?? -1;

        if ($best['score'] <= 0) {
            return ['homeowner_id'=>null,'status'=>'unmatched','method'=>null,'score'=>0,'note'=>'Property exists, but the imported homeowner identity does not confidently match the system record.'];
        }

        $homeownerId = (int)$best['homeowner']['id'];
        $method = implode('+', $best['methods']);
        $status = 'needs_review';
        $note = 'Supporting information matched, but the homeowner name needs review.';

        if ($best['name_match'] && $best['score'] > $secondScore) {
            $status = 'ready';
            $note = 'Name and property matched an existing homeowner.';
        }
        if ($best['score'] === $secondScore) {
            $status = 'needs_review';
            $note = 'More than one homeowner has the same match score for this property.';
        }

        if ($status === 'ready') {
            $warning = fdm_temporal_transfer_warning($conn, $homeownerId, $phase, $block, $lot, $paidAt);
            if ($warning !== null) {
                $status = 'needs_review';
                $note = $warning;
            }
        }

        return ['homeowner_id'=>$homeownerId,'status'=>$status,'method'=>$method,'score'=>$best['score'],'note'=>$note];
    }
}

if (!function_exists('fdm_payment_state')) {
    function fdm_payment_state(mysqli $conn, int $homeownerId, int $year, int $month, float $amount, string $paidAt, string $referenceNo): array
    {
        $stmt = $conn->prepare(
            'SELECT id, amount, status, paid_at, reference_no FROM finance_payments WHERE homeowner_id=? AND pay_year=? AND pay_month=? LIMIT 1'
        );
        $stmt->bind_param('iii', $homeownerId, $year, $month);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$existing) {
            return ['status'=>'ready','existing_payment_id'=>null,'note'=>null];
        }
        $existingId = (int)$existing['id'];
        if ((string)$existing['status'] !== 'paid') {
            return ['status'=>'ready','existing_payment_id'=>$existingId,'note'=>'An unpaid placeholder exists and will be converted to the historical paid record when finalized.'];
        }

        $sameAmount = abs((float)$existing['amount'] - $amount) < 0.005;
        $sameDate = substr((string)($existing['paid_at'] ?? ''), 0, 10) === substr($paidAt, 0, 10);
        $importRef = fdm_normalize_text($referenceNo);
        $existingRef = fdm_normalize_text((string)($existing['reference_no'] ?? ''));
        $sameRef = ($importRef === '' || $existingRef === '' || $importRef === $existingRef);

        if ($sameAmount && $sameDate && $sameRef) {
            return ['status'=>'duplicate','existing_payment_id'=>$existingId,'note'=>'This homeowner already has the same paid record for this month.'];
        }
        return ['status'=>'conflict','existing_payment_id'=>$existingId,'note'=>'A paid record already exists for this month, but its amount/date/reference differs.'];
    }
}

if (!function_exists('fdm_recompute_queue_row')) {
    function fdm_recompute_queue_row(mysqli $conn, int $queueId, ?int $forcedHomeownerId = null): array
    {
        $stmt = $conn->prepare('SELECT * FROM finance_dues_import_queue WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $queueId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['success'=>false,'message'=>'Migration row not found.'];
        }

        if ($forcedHomeownerId !== null && $forcedHomeownerId > 0) {
            $stmt = $conn->prepare("SELECT id, public_id, first_name, middle_name, last_name, contact_number, email, phase, block, lot, street, house_lot_number, status FROM homeowners WHERE id=? AND phase=? AND status IN ('approved','former') LIMIT 1");
            $stmt->bind_param('is', $forcedHomeownerId, $row['phase']);
            $stmt->execute();
            $h = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$h) {
                return ['success'=>false,'message'=>'Selected homeowner is not valid for this phase.'];
            }
            $matchedId = (int)$h['id'];
            $status = 'ready';
            $method = 'manual_admin_match';
            $score = 100;
            $note = 'Homeowner manually matched by Treasurer.';
            $warning = fdm_temporal_transfer_warning($conn, $matchedId, (string)$row['phase'], (string)$row['block'], (string)$row['lot'], (string)$row['paid_at']);
            if ($warning !== null) {
                $note .= ' Ownership-history warning reviewed by Treasurer: ' . $warning;
            }
        } else {
            $match = fdm_match_homeowner($conn, $row);
            $matchedId = (int)($match['homeowner_id'] ?? 0);
            $status = (string)$match['status'];
            $method = (string)($match['method'] ?? '');
            $score = (int)($match['score'] ?? 0);
            $note = (string)($match['note'] ?? '');
        }

        $existingId = null;
        if ($matchedId > 0 && $status === 'ready') {
            $payment = fdm_payment_state(
                $conn,
                $matchedId,
                (int)$row['pay_year'],
                (int)$row['pay_month'],
                (float)$row['amount'],
                (string)$row['paid_at'],
                (string)($row['reference_no'] ?? '')
            );
            $existingId = $payment['existing_payment_id'];
            if ($payment['status'] !== 'ready') {
                $status = $payment['status'];
                $note = (string)$payment['note'];
            } elseif (!empty($payment['note'])) {
                $note = trim($note . ' ' . $payment['note']);
            }
        }

        $matchedNullable = $matchedId > 0 ? $matchedId : null;
        $stmt = $conn->prepare('UPDATE finance_dues_import_queue SET matched_homeowner_id=?, match_method=?, match_score=?, match_note=?, existing_payment_id=?, status=? WHERE id=?');
        $stmt->bind_param('isisisi', $matchedNullable, $method, $score, $note, $existingId, $status, $queueId);
        $stmt->execute();
        $stmt->close();

        return ['success'=>true,'status'=>$status,'homeowner_id'=>$matchedId,'note'=>$note];
    }
}

if (!function_exists('fdm_log')) {
    function fdm_log(
        mysqli $conn,
        int $adminId,
        string $phase,
        string $action,
        string $details,
        string $entityType = 'historical_dues_migration',
        ?int $entityId = null,
        mixed $beforeData = null,
        mixed $afterData = null,
        ?string $batchId = null
    ): void
    {
        try {
            $ip = mb_substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
            $module = 'finance';
            $stmt = $conn->prepare('INSERT INTO activity_logs (admin_id, phase, action, module_key, details, ip_address) VALUES (?,?,?,?,?,?)');
            $stmt->bind_param('isssss', $adminId, $phase, $action, $module, $details, $ip);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('Finance dues migration activity log failed: ' . $e->getMessage());
        }

        if (function_exists('finance_audit_log')) {
            finance_audit_log(
                $conn,
                $action,
                $entityType,
                $entityId,
                $details,
                $beforeData,
                $afterData,
                $batchId,
                $adminId,
                $phase
            );
        }
    }
}

// Finalization helper.
if (!function_exists('fdm_finalize_queue_row')) {
    function fdm_finalize_queue_row(mysqli $conn, int $queueId, int $adminId, string $phase): array
    {
        $stmt = $conn->prepare(
            "SELECT * FROM finance_dues_import_queue
             WHERE id=? AND phase=?
             LIMIT 1 FOR UPDATE"
        );
        $stmt->bind_param('is', $queueId, $phase);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['success'=>false,'finalized'=>false,'message'=>'Historical dues row not found.'];
        }
        if ((string)$row['status'] !== 'ready') {
            return ['success'=>false,'finalized'=>false,'message'=>'Only rows marked Ready can be finalized.'];
        }

        $homeownerId = (int)($row['matched_homeowner_id'] ?? 0);
        if ($homeownerId <= 0) {
            return ['success'=>false,'finalized'=>false,'message'=>'The row has no matched homeowner.'];
        }

        $stmt = $conn->prepare("SELECT id, status FROM homeowners WHERE id=? AND phase=? AND status IN ('approved','former') LIMIT 1");
        $stmt->bind_param('is', $homeownerId, $phase);
        $stmt->execute();
        $homeowner = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$homeowner) {
            return ['success'=>false,'finalized'=>false,'message'=>'The matched homeowner is no longer valid for this phase.'];
        }

        $year = (int)$row['pay_year'];
        $month = (int)$row['pay_month'];
        $amount = (float)$row['amount'];
        $paidAt = (string)$row['paid_at'];
        $reference = mb_substr(trim((string)($row['reference_no'] ?? '')), 0, 100);
        $sourceRef = trim((string)($row['source_reference'] ?? ''));
        $importNotes = trim((string)($row['notes'] ?? ''));
        $notesParts = ['Migrated historical dues'];
        if ($sourceRef !== '') $notesParts[] = 'Source: ' . $sourceRef;
        if ($importNotes !== '') $notesParts[] = $importNotes;
        $notes = mb_substr(implode(' | ', $notesParts), 0, 255);

        $stmt = $conn->prepare(
            'SELECT id, homeowner_id, phase, pay_year, pay_month, amount, status, paid_at, reference_no, notes, created_by_admin_id, created_at FROM finance_payments WHERE homeowner_id=? AND pay_year=? AND pay_month=? LIMIT 1 FOR UPDATE'
        );
        $stmt->bind_param('iii', $homeownerId, $year, $month);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $paymentBefore = $existing ?: null;

        if ($existing && (string)$existing['status'] === 'paid') {
            $state = fdm_payment_state($conn, $homeownerId, $year, $month, $amount, $paidAt, $reference);
            $newStatus = (string)$state['status'];
            $note = (string)($state['note'] ?? 'Existing paid record detected during finalization.');
            $existingPaymentId = (int)($state['existing_payment_id'] ?? $existing['id']);
            $stmt = $conn->prepare('UPDATE finance_dues_import_queue SET status=?, existing_payment_id=?, match_note=? WHERE id=?');
            $stmt->bind_param('sisi', $newStatus, $existingPaymentId, $note, $queueId);
            $stmt->execute();
            $stmt->close();
            return ['success'=>false,'finalized'=>false,'message'=>$note,'status'=>$newStatus];
        }

        if ($existing) {
            $paymentId = (int)$existing['id'];
            $stmt = $conn->prepare(
                "UPDATE finance_payments
                 SET phase=?, amount=?, status='paid', paid_at=?, reference_no=?, notes=?, created_by_admin_id=?
                 WHERE id=? AND homeowner_id=?"
            );
            $stmt->bind_param('sdsssiii', $phase, $amount, $paidAt, $reference, $notes, $adminId, $paymentId, $homeownerId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO finance_payments
                 (homeowner_id, phase, pay_year, pay_month, amount, status, paid_at, reference_no, notes, created_by_admin_id)
                 VALUES (?,?,?,?,?,'paid',?,?,?,?)"
            );
            $stmt->bind_param('isiidsssi', $homeownerId, $phase, $year, $month, $amount, $paidAt, $reference, $notes, $adminId);
            $stmt->execute();
            $paymentId = (int)$conn->insert_id;
            $stmt->close();
        }

        $stmt = $conn->prepare(
            "UPDATE finance_dues_import_queue
             SET status='finalized', finalized_payment_id=?, finalized_by_admin_id=?, finalized_at=NOW(), match_note='Historical dues payment finalized into finance_payments.'
             WHERE id=?"
        );
        $stmt->bind_param('iii', $paymentId, $adminId, $queueId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare(
            'SELECT id, homeowner_id, phase, pay_year, pay_month, amount, status, paid_at, reference_no, notes, created_by_admin_id, created_at
             FROM finance_payments WHERE id=? LIMIT 1'
        );
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        $paymentAfter = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'success'=>true,
            'finalized'=>true,
            'payment_id'=>$paymentId,
            'homeowner_id'=>$homeownerId,
            'year'=>$year,
            'month'=>$month,
            'amount'=>$amount,
            'payment_before'=>$paymentBefore,
            'payment_after'=>$paymentAfter ?: null
        ];
    }
}
