<?php

declare(strict_types=1);

if (!function_exists('finance_report_type_labels')) {
    function finance_report_type_labels(): array
    {
        return [
            'full_summary'     => 'Complete Financial Summary',
            'monthly_dues'     => 'Monthly Dues Report',
            'expenses'         => 'Expenses Report',
            'specific_expense' => 'Specific Expense Report',
            'project'          => 'Project / Purchase Report',
            'donations'        => 'Donations Report',
            'cash_flow'        => 'Cash Flow Report',
        ];
    }
}

if (!function_exists('finance_report_type_label')) {
    function finance_report_type_label(string $type): string
    {
        $labels = finance_report_type_labels();
        return $labels[$type] ?? 'Financial Report';
    }
}

if (!function_exists('finance_report_is_valid_type')) {
    function finance_report_is_valid_type(string $type): bool
    {
        return isset(finance_report_type_labels()[$type]);
    }
}

if (!function_exists('finance_report_month_dates')) {
    function finance_report_month_dates(int $year, int $month): array
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));
        return [$start, $end];
    }
}

if (!function_exists('finance_report_request_scope_dates')) {
    function finance_report_request_scope_dates(array $request): array
    {
        $type = (string)($request['report_type'] ?? 'full_summary');

        if (in_array($type, ['full_summary', 'monthly_dues'], true)) {
            return finance_report_month_dates(
                (int)($request['report_year'] ?? date('Y')),
                (int)($request['report_month'] ?? date('n'))
            );
        }

        $from = trim((string)($request['date_from'] ?? ''));
        $to = trim((string)($request['date_to'] ?? ''));

        if ($type === 'specific_expense' && $from !== '') {
            return [$from, $from];
        }

        if ($from !== '' && $to !== '') {
            return [$from, $to];
        }

        return finance_report_month_dates(
            (int)($request['report_year'] ?? date('Y')),
            (int)($request['report_month'] ?? date('n'))
        );
    }
}

if (!function_exists('finance_report_period_label')) {
    function finance_report_period_label(array $request, string $dateFrom, string $dateTo): string
    {
        $type = (string)($request['report_type'] ?? 'full_summary');

        if (in_array($type, ['full_summary', 'monthly_dues'], true)) {
            return date('F Y', strtotime($dateFrom));
        }

        if ($type === 'specific_expense') {
            return date('F d, Y', strtotime($dateFrom));
        }

        if ($dateFrom === $dateTo) {
            return date('F d, Y', strtotime($dateFrom));
        }

        return date('M d, Y', strtotime($dateFrom))
            . ' to '
            . date('M d, Y', strtotime($dateTo));
    }
}

if (!function_exists('finance_report_fetch_requester')) {
    function finance_report_fetch_requester(mysqli $conn, ?int $adminId): array
    {
        $requester = [
            'id' => $adminId,
            'name' => '',
            'email' => '',
            'position' => '',
        ];

        if (!$adminId) {
            return $requester;
        }

        $stmt = $conn->prepare("
            SELECT full_name, email, position
            FROM admins
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $requester['name'] = (string)($row['full_name'] ?? '');
            $requester['email'] = (string)($row['email'] ?? '');
            $requester['position'] = (string)($row['position'] ?? '');
        }

        return $requester;
    }
}

if (!function_exists('finance_report_fetch_expense_items')) {
    function finance_report_fetch_expense_items(mysqli $conn, array $expenseIds): array
    {
        $expenseIds = array_values(array_filter(array_map('intval', $expenseIds)));
        if (!$expenseIds) {
            return [];
        }

        $idList = implode(',', $expenseIds);
        $itemsByExpense = [];

        $result = $conn->query("
            SELECT
                id,
                expense_id,
                item_name,
                quantity,
                unit,
                unit_cost,
                line_total,
                notes
            FROM finance_expense_items
            WHERE expense_id IN ($idList)
            ORDER BY expense_id, id
        ");

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $expenseId = (int)($row['expense_id'] ?? 0);
                $itemsByExpense[$expenseId][] = $row;
            }
            $result->free();
        }

        return $itemsByExpense;
    }
}

if (!function_exists('finance_report_dues_section')) {
    function finance_report_dues_section(
        mysqli $conn,
        string $phase,
        int $year,
        int $month,
        string $periodEnd
    ): array {
        $stmt = $conn->prepare("
            SELECT monthly_dues
            FROM finance_dues_settings
            WHERE phase = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $phase);
        $stmt->execute();
        $monthlyDues = (float)($stmt->get_result()->fetch_assoc()['monthly_dues'] ?? 0);
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM finance_payments
            WHERE phase = ?
              AND status = 'paid'
              AND pay_year = ?
              AND pay_month = ?
        ");
        $stmt->bind_param('sii', $phase, $year, $month);
        $stmt->execute();
        $collected = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS eligible_count
            FROM homeowners
            WHERE phase = ?
              AND status = 'approved'
              AND DATE(created_at) <= ?
        ");
        $stmt->bind_param('ss', $phase, $periodEnd);
        $stmt->execute();
        $eligibleCount = (int)($stmt->get_result()->fetch_assoc()['eligible_count'] ?? 0);
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT p.homeowner_id) AS paid_count
            FROM finance_payments p
            JOIN homeowners h
              ON h.id = p.homeowner_id
             AND h.phase = p.phase
            WHERE p.phase = ?
              AND p.pay_year = ?
              AND p.pay_month = ?
              AND p.status = 'paid'
              AND h.status = 'approved'
              AND DATE(h.created_at) <= ?
        ");
        $stmt->bind_param('siis', $phase, $year, $month, $periodEnd);
        $stmt->execute();
        $paidCount = (int)($stmt->get_result()->fetch_assoc()['paid_count'] ?? 0);
        $stmt->close();

        $unpaidCount = max(0, $eligibleCount - $paidCount);

        $stmt = $conn->prepare("
            SELECT
                p.id,
                p.homeowner_id,
                p.pay_year,
                p.pay_month,
                p.amount,
                p.status,
                p.paid_at,
                p.reference_no,
                p.notes,
                h.public_id,
                CONCAT_WS(' ', h.first_name, NULLIF(h.middle_name, ''), h.last_name) AS homeowner_name,
                h.block,
                h.lot,
                h.house_lot_number,
                h.status AS homeowner_status
            FROM finance_payments p
            LEFT JOIN homeowners h
              ON h.id = p.homeowner_id
            WHERE p.phase = ?
              AND p.pay_year = ?
              AND p.pay_month = ?
              AND p.status = 'paid'
            ORDER BY COALESCE(p.paid_at, p.created_at), p.id
            LIMIT 1000
        ");
        $stmt->bind_param('sii', $phase, $year, $month);
        $stmt->execute();
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return [
            'summary' => [
                'monthly_dues' => $monthlyDues,
                'eligible_homeowners' => $eligibleCount,
                'paid_homeowners' => $paidCount,
                'unpaid_homeowners' => $unpaidCount,
                'expected_dues' => $eligibleCount * $monthlyDues,
                'pending_dues' => $unpaidCount * $monthlyDues,
                'dues_collected' => $collected,
            ],
            'records' => $records,
        ];
    }
}

if (!function_exists('finance_report_donations_section')) {
    function finance_report_donations_section(
        mysqli $conn,
        string $phase,
        string $dateFrom,
        string $dateTo
    ): array {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM finance_donations
            WHERE phase = ?
              AND donation_date BETWEEN ? AND ?
        ");
        $stmt->bind_param('sss', $phase, $dateFrom, $dateTo);
        $stmt->execute();
        $total = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT
                id,
                homeowner_id,
                donor_name,
                donor_email,
                amount,
                donation_date,
                receipt_no,
                message,
                created_by_admin_id,
                created_at
            FROM finance_donations
            WHERE phase = ?
              AND donation_date BETWEEN ? AND ?
            ORDER BY donation_date, id
            LIMIT 1000
        ");
        $stmt->bind_param('sss', $phase, $dateFrom, $dateTo);
        $stmt->execute();
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return [
            'summary' => [
                'total_donations' => $total,
                'donation_count' => count($records),
            ],
            'records' => $records,
        ];
    }
}

if (!function_exists('finance_report_expenses_section')) {
    function finance_report_expenses_section(
        mysqli $conn,
        string $phase,
        string $dateFrom,
        string $dateTo,
        ?int $specificExpenseId = null,
        ?string $projectName = null
    ): array {
        $where = "e.phase = ?";
        $types = 's';
        $params = [$phase];

        if ($specificExpenseId) {
            $where .= " AND e.id = ?";
            $types .= 'i';
            $params[] = $specificExpenseId;
        } else {
            $where .= " AND e.expense_date BETWEEN ? AND ?";
            $types .= 'ss';
            $params[] = $dateFrom;
            $params[] = $dateTo;
        }

        if ($projectName !== null && trim($projectName) !== '') {
            $where .= " AND e.project_name = ?";
            $types .= 's';
            $params[] = trim($projectName);
        }

        $stmt = $conn->prepare("
            SELECT
                e.*,
                a.full_name AS recorded_by_name,
                a.position AS recorded_by_position
            FROM finance_expenses e
            LEFT JOIN admins a
              ON a.id = e.created_by_admin_id
            WHERE $where
            ORDER BY e.expense_date, e.id
            LIMIT 1000
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $expenseIds = array_map(
            static fn(array $r): int => (int)($r['id'] ?? 0),
            $records
        );
        $itemsByExpense = finance_report_fetch_expense_items($conn, $expenseIds);

        $total = 0.0;
        $breakdownMap = [];

        foreach ($records as &$record) {
            $expenseId = (int)($record['id'] ?? 0);
            $record['items'] = $itemsByExpense[$expenseId] ?? [];

            $amount = (float)($record['amount'] ?? 0);
            $total += $amount;

            $category = (string)($record['category'] ?? 'other');
            $breakdownMap[$category] = ($breakdownMap[$category] ?? 0) + $amount;
        }
        unset($record);

        arsort($breakdownMap);

        $breakdown = [];
        foreach ($breakdownMap as $category => $categoryTotal) {
            $breakdown[] = [
                'category' => $category,
                'total' => $categoryTotal,
            ];
        }

        return [
            'summary' => [
                'total_expenses' => $total,
                'expense_count' => count($records),
            ],
            'breakdown' => $breakdown,
            'records' => $records,
        ];
    }
}

if (!function_exists('finance_report_cash_flow_section')) {
    function finance_report_cash_flow_section(
        mysqli $conn,
        string $phase,
        string $dateFrom,
        string $dateTo
    ): array {
        $stmt = $conn->prepare("
            SELECT opening_balance, as_of
            FROM finance_opening_balance
            WHERE phase = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $phase);
        $stmt->execute();
        $openingRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $configuredOpening = (float)($openingRow['opening_balance'] ?? 0);
        $configuredAsOf = trim((string)($openingRow['as_of'] ?? ''));

        $openingKnown = true;
        $openingBalance = 0.0;
        $openingBasis = 'Recorded finance history before the report start date.';

        if ($configuredAsOf !== '' && $dateFrom > $configuredAsOf) {
            $openingBalance = $configuredOpening;
            $openingBasis = 'Configured opening balance plus recorded movements before the report start date.';

            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(amount), 0) AS total
                FROM finance_payments
                WHERE phase = ?
                  AND status = 'paid'
                  AND DATE(COALESCE(paid_at, created_at)) > ?
                  AND DATE(COALESCE(paid_at, created_at)) < ?
            ");
            $stmt->bind_param('sss', $phase, $configuredAsOf, $dateFrom);
            $stmt->execute();
            $openingBalance += (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(amount), 0) AS total
                FROM finance_donations
                WHERE phase = ?
                  AND donation_date > ?
                  AND donation_date < ?
            ");
            $stmt->bind_param('sss', $phase, $configuredAsOf, $dateFrom);
            $stmt->execute();
            $openingBalance += (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(amount), 0) AS total
                FROM finance_expenses
                WHERE phase = ?
                  AND expense_date > ?
                  AND expense_date < ?
            ");
            $stmt->bind_param('sss', $phase, $configuredAsOf, $dateFrom);
            $stmt->execute();
            $openingBalance -= (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

        } elseif ($configuredAsOf !== '' && $dateFrom <= $configuredAsOf) {
            $openingKnown = false;
            $openingBasis = 'Opening balance cannot be reconstructed because the configured opening-balance date is on/after this report start date.';
        } else {
            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(amount), 0) AS total
                FROM finance_payments
                WHERE phase = ?
                  AND status = 'paid'
                  AND DATE(COALESCE(paid_at, created_at)) < ?
            ");
            $stmt->bind_param('ss', $phase, $dateFrom);
            $stmt->execute();
            $openingBalance += (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(amount), 0) AS total
                FROM finance_donations
                WHERE phase = ?
                  AND donation_date < ?
            ");
            $stmt->bind_param('ss', $phase, $dateFrom);
            $stmt->execute();
            $openingBalance += (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();

            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(amount), 0) AS total
                FROM finance_expenses
                WHERE phase = ?
                  AND expense_date < ?
            ");
            $stmt->bind_param('ss', $phase, $dateFrom);
            $stmt->execute();
            $openingBalance -= (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM finance_payments
            WHERE phase = ?
              AND status = 'paid'
              AND DATE(COALESCE(paid_at, created_at)) BETWEEN ? AND ?
        ");
        $stmt->bind_param('sss', $phase, $dateFrom, $dateTo);
        $stmt->execute();
        $dues = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM finance_donations
            WHERE phase = ?
              AND donation_date BETWEEN ? AND ?
        ");
        $stmt->bind_param('sss', $phase, $dateFrom, $dateTo);
        $stmt->execute();
        $donations = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM finance_expenses
            WHERE phase = ?
              AND expense_date BETWEEN ? AND ?
        ");
        $stmt->bind_param('sss', $phase, $dateFrom, $dateTo);
        $stmt->execute();
        $expenses = (float)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $income = $dues + $donations;
        $netMovement = $income - $expenses;

        return [
            'opening_balance_known' => $openingKnown,
            'opening_balance' => $openingBalance,
            'opening_basis' => $openingBasis,
            'configured_opening_balance' => $configuredOpening,
            'configured_as_of' => $configuredAsOf,
            'dues_inflow' => $dues,
            'donations_inflow' => $donations,
            'total_inflow' => $income,
            'expenses_outflow' => $expenses,
            'net_movement' => $netMovement,
            'closing_balance' => $openingKnown ? ($openingBalance + $netMovement) : null,
        ];
    }
}

if (!function_exists('buildFinanceReportSnapshotV2')) {
    function buildFinanceReportSnapshotV2(
        mysqli $conn,
        array $request,
        ?int $requestedByAdminId,
        string $approvedByEmail,
        string $approvalRemarks,
        string $approvedAt
    ): array {
        $phase = (string)($request['phase'] ?? '');
        $type = (string)($request['report_type'] ?? 'full_summary');

        if (!finance_report_is_valid_type($type)) {
            throw new RuntimeException('Unsupported finance report type.');
        }

        [$dateFrom, $dateTo] = finance_report_request_scope_dates($request);

        $year = (int)($request['report_year'] ?? date('Y', strtotime($dateFrom)));
        $month = (int)($request['report_month'] ?? date('n', strtotime($dateFrom)));

        $snapshot = [
            'version' => 2,
            'phase' => $phase,
            'report_type' => $type,
            'report_type_label' => finance_report_type_label($type),
            'report_title' => (string)($request['report_title'] ?? ''),
            'request_purpose' => (string)($request['request_purpose'] ?? ''),
            'report_year' => $year,
            'report_month' => $month,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'period_label' => finance_report_period_label($request, $dateFrom, $dateTo),
            'target_type' => (string)($request['target_type'] ?? ''),
            'target_id' => isset($request['target_id']) ? (int)$request['target_id'] : null,
            'project_name' => (string)($request['project_name'] ?? ''),
            'requester' => finance_report_fetch_requester($conn, $requestedByAdminId),
            'approval' => [
                'approved_by_email' => $approvedByEmail,
                'approved_at' => $approvedAt,
                'remarks' => $approvalRemarks,
            ],
            'summary' => [],
            'sections' => [],
            'snapshot_created_at' => $approvedAt,
        ];

        if (in_array($type, ['full_summary', 'monthly_dues'], true)) {
            $dues = finance_report_dues_section($conn, $phase, $year, $month, $dateTo);
            $snapshot['sections']['dues'] = $dues;
            $snapshot['summary'] = array_merge($snapshot['summary'], $dues['summary']);
        }

        if (in_array($type, ['full_summary', 'donations'], true)) {
            $donations = finance_report_donations_section($conn, $phase, $dateFrom, $dateTo);
            $snapshot['sections']['donations'] = $donations;
            $snapshot['summary']['donations'] = (float)($donations['summary']['total_donations'] ?? 0);
            $snapshot['summary']['donation_count'] = (int)($donations['summary']['donation_count'] ?? 0);
        }

        if (in_array($type, ['full_summary', 'expenses', 'specific_expense', 'project'], true)) {
            $specificExpenseId = $type === 'specific_expense'
                ? (int)($request['target_id'] ?? 0)
                : null;

            $projectName = $type === 'project'
                ? (string)($request['project_name'] ?? '')
                : null;

            $expenses = finance_report_expenses_section(
                $conn,
                $phase,
                $dateFrom,
                $dateTo,
                $specificExpenseId ?: null,
                $projectName
            );

            $snapshot['sections']['expenses'] = $expenses;
            $snapshot['summary']['expenses'] = (float)($expenses['summary']['total_expenses'] ?? 0);
            $snapshot['summary']['expense_count'] = (int)($expenses['summary']['expense_count'] ?? 0);

            // Keep legacy-friendly keys.
            $snapshot['expense_breakdown'] = $expenses['breakdown'];
            $snapshot['top_expenses'] = array_slice($expenses['records'], 0, 20);
        }

        if (in_array($type, ['full_summary', 'cash_flow'], true)) {
            $snapshot['sections']['cash_flow'] =
                finance_report_cash_flow_section($conn, $phase, $dateFrom, $dateTo);
        }

        if ($type === 'full_summary') {
            $duesCollected = (float)($snapshot['summary']['dues_collected'] ?? 0);
            $donationsTotal = (float)($snapshot['summary']['donations'] ?? 0);
            $expensesTotal = (float)($snapshot['summary']['expenses'] ?? 0);

            $snapshot['summary']['total_income'] = $duesCollected + $donationsTotal;
            $snapshot['summary']['net'] =
                $snapshot['summary']['total_income'] - $expensesTotal;
        }

        if ($type === 'monthly_dues') {
            $snapshot['summary']['total_income'] =
                (float)($snapshot['summary']['dues_collected'] ?? 0);
        }

        if ($type === 'donations') {
            $snapshot['summary']['total_income'] =
                (float)($snapshot['summary']['donations'] ?? 0);
        }

        if ($type === 'specific_expense') {
            $records = $snapshot['sections']['expenses']['records'] ?? [];
            if (!$records) {
                throw new RuntimeException('The selected expense could not be found for the report.');
            }
        }

        return $snapshot;
    }
}

if (!function_exists('saveFinanceReportSnapshotV2')) {
    function saveFinanceReportSnapshotV2(
        mysqli $conn,
        int $requestId,
        string $phase,
        int $year,
        int $month,
        array $snapshot,
        string $approvedByEmail,
        string $approvedAt
    ): void {
        $snapshotJson = json_encode(
            $snapshot,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($snapshotJson === false) {
            throw new RuntimeException('Unable to encode financial report snapshot.');
        }

        $stmt = $conn->prepare("
            INSERT INTO finance_report_snapshots
            (
                report_request_id,
                phase,
                report_year,
                report_month,
                snapshot_json,
                approved_by_email,
                approved_at
            )
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                snapshot_json = VALUES(snapshot_json),
                approved_by_email = VALUES(approved_by_email),
                approved_at = VALUES(approved_at),
                created_at = CURRENT_TIMESTAMP
        ");

        $stmt->bind_param(
            'isiisss',
            $requestId,
            $phase,
            $year,
            $month,
            $snapshotJson,
            $approvedByEmail,
            $approvedAt
        );
        $stmt->execute();
        $stmt->close();
    }
}
