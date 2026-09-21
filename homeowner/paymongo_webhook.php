<?php

/*
|--------------------------------------------------------------------------
| Error logging
|--------------------------------------------------------------------------
*/

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$logErrFile =
    dirname(__DIR__) .
    '/admin/private/paymongo_webhook_errors.log';

ini_set('error_log', $logErrFile);

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

require_once '../config/database.php';


function log_err(string $message): void
{
    error_log(
        '[' . date('c') . '] ' . $message
    );
}


function webhook_reply(
    int $statusCode,
    string $message
): void {

    http_response_code($statusCode);

    echo $message;

    exit;
}


/*
|--------------------------------------------------------------------------
| Parking permit validity helpers
|--------------------------------------------------------------------------
|
| Final validity is assigned only when a permit is actually activated.
|
| New permit:
|   start = today
|
| Renewal:
|   start = later of:
|     - today
|     - previous permit valid_until + 1 day
|
*/

function compute_parking_permit_dates(
    string $duration,
    string $startDate
): array {

    $start =
        new DateTime($startDate);

    $end =
        clone $start;

    switch ($duration) {

        case '1_month':
            $end
                ->modify('+1 month')
                ->modify('-1 day');
            break;

        case '3_months':
            $end
                ->modify('+3 months')
                ->modify('-1 day');
            break;

        case '6_months':
            $end
                ->modify('+6 months')
                ->modify('-1 day');
            break;

        case '1_year':
            $end
                ->modify('+1 year')
                ->modify('-1 day');
            break;

        default:
            throw new InvalidArgumentException(
                'Invalid parking permit duration.'
            );
    }

    return [
        $start->format('Y-m-d'),
        $end->format('Y-m-d'),
    ];
}


function parking_activation_dates(
    mysqli $conn,
    array $permit
): array {

    $today =
        new DateTime('today');

    $start =
        clone $today;

    $requestType =
        strtolower(
            trim(
                (string)(
                    $permit['request_type']
                    ?? 'new'
                )
            )
        );

    $renewOfId =
        (int)(
            $permit['renew_of_id']
            ?? 0
        );

    $homeownerId =
        (int)(
            $permit['homeowner_id']
            ?? 0
        );

    $phase =
        (string)(
            $permit['phase']
            ?? ''
        );

    if (
        $requestType === 'renew' &&
        $renewOfId > 0
    ) {

        $stmt =
            $conn->prepare("
                SELECT valid_until
                FROM parking_permits
                WHERE id = ?
                  AND homeowner_id = ?
                  AND phase = ?
                LIMIT 1
                FOR UPDATE
            ");

        $stmt->bind_param(
            'iis',
            $renewOfId,
            $homeownerId,
            $phase
        );

        $stmt->execute();

        $previousPermit =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        if (
            !empty(
                $previousPermit['valid_until']
            )
        ) {

            $afterPrevious =
                new DateTime(
                    (string)
                    $previousPermit['valid_until']
                );

            $afterPrevious
                ->modify('+1 day');

            /*
             * Preserve unused time on the old permit.
             * If the old permit has already ended,
             * the renewal starts today instead.
             */
            if ($afterPrevious > $start) {
                $start =
                    $afterPrevious;
            }
        }
    }

    return compute_parking_permit_dates(
        (string)(
            $permit['permit_duration']
            ?? ''
        ),
        $start->format('Y-m-d')
    );
}


/*
|--------------------------------------------------------------------------
| Read raw request ONCE
|--------------------------------------------------------------------------
*/

$rawPayload =
    file_get_contents('php://input');

if (
    $rawPayload === false ||
    trim($rawPayload) === ''
) {

    webhook_reply(
        400,
        'Empty webhook payload.'
    );
}


/*
|--------------------------------------------------------------------------
| Load private configuration
|--------------------------------------------------------------------------
*/

$secretsFile =
    dirname(__DIR__) .
    '/admin/private/hoa_secrets.php';

if (!is_file($secretsFile)) {

    log_err(
        'Webhook secrets file not found.'
    );

    webhook_reply(
        500,
        'Webhook configuration unavailable.'
    );
}

$secrets =
    require $secretsFile;

$webhookSecret =
    trim(
        (string)(
            $secrets['paymongo_webhook_secret']
            ?? ''
        )
    );

if ($webhookSecret === '') {

    log_err(
        'PayMongo webhook signing secret is missing.'
    );

    webhook_reply(
        500,
        'Webhook signing secret is not configured.'
    );
}


/*
|--------------------------------------------------------------------------
| Decode PayMongo event
|--------------------------------------------------------------------------
*/

$event =
    json_decode(
        $rawPayload,
        true
    );

if (!is_array($event)) {

    webhook_reply(
        400,
        'Invalid JSON payload.'
    );
}


/*
|--------------------------------------------------------------------------
| Require PayMongo signature
|--------------------------------------------------------------------------
*/

$signatureHeader =
    trim(
        (string)(
            $_SERVER['HTTP_PAYMONGO_SIGNATURE']
            ?? ''
        )
    );

if ($signatureHeader === '') {

    webhook_reply(
        401,
        'Missing PayMongo signature.'
    );
}


/*
|--------------------------------------------------------------------------
| Parse Paymongo-Signature
|--------------------------------------------------------------------------
|
| t  = timestamp
| te = test mode
| li = live mode
|
*/

$signatureParts = [];

foreach (
    explode(',', $signatureHeader)
    as $part
) {

    $pair =
        explode(
            '=',
            trim($part),
            2
        );

    if (count($pair) !== 2) {
        continue;
    }

    $key =
        trim($pair[0]);

    $value =
        trim($pair[1]);

    if (
        $key === '' ||
        $value === ''
    ) {
        continue;
    }

    /*
     * Store as array because webhook signature
     * headers can potentially contain more than
     * one signature value.
     */
    if (!isset($signatureParts[$key])) {
        $signatureParts[$key] = [];
    }

    $signatureParts[$key][] =
        $value;
}


/*
|--------------------------------------------------------------------------
| Timestamp
|--------------------------------------------------------------------------
*/

$timestamp =
    (string)(
        $signatureParts['t'][0]
        ?? ''
    );

if (
    $timestamp === '' ||
    !ctype_digit($timestamp)
) {

    webhook_reply(
        401,
        'Invalid PayMongo signature.'
    );
}


/*
|--------------------------------------------------------------------------
| Determine test/live mode
|--------------------------------------------------------------------------
*/

$isLiveMode =
    !empty(
        $event['data']['attributes']['livemode']
    );

$signatureKey =
    $isLiveMode
        ? 'li'
        : 'te';

$receivedSignatures =
    $signatureParts[$signatureKey]
    ?? [];

if (empty($receivedSignatures)) {

    webhook_reply(
        401,
        'PayMongo signature is unavailable.'
    );
}


/*
|--------------------------------------------------------------------------
| Calculate expected signature
|--------------------------------------------------------------------------
*/

$signedPayload =
    $timestamp .
    '.' .
    $rawPayload;

$expectedSignature =
    hash_hmac(
        'sha256',
        $signedPayload,
        $webhookSecret
    );


/*
|--------------------------------------------------------------------------
| Verify signature
|--------------------------------------------------------------------------
*/

$signatureValid = false;

foreach (
    $receivedSignatures
    as $receivedSignature
) {

    if (
        hash_equals(
            $expectedSignature,
            (string)$receivedSignature
        )
    ) {

        $signatureValid = true;

        break;
    }
}

if (!$signatureValid) {

    log_err(
        'Rejected webhook with invalid signature.'
    );

    webhook_reply(
        401,
        'Invalid PayMongo signature.'
    );
}


/*
|--------------------------------------------------------------------------
| Event information
|--------------------------------------------------------------------------
*/

$eventId =
    (string)(
        $event['data']['id']
        ?? ''
    );

$eventType =
    (string)(
        $event['data']['attributes']['type']
        ?? ''
    );


/*
|--------------------------------------------------------------------------
| Only handle successful Checkout payments
|--------------------------------------------------------------------------
*/

if (
    $eventType !==
    'checkout_session.payment.paid'
) {

    webhook_reply(
        200,
        'OK'
    );
}


/*
|--------------------------------------------------------------------------
| Checkout Session
|--------------------------------------------------------------------------
*/

$checkoutSession =
    $event['data']['attributes']['data']
    ?? null;

if (!is_array($checkoutSession)) {

    webhook_reply(
        400,
        'Missing checkout session.'
    );
}

$checkoutSessionId =
    trim(
        (string)(
            $checkoutSession['id']
            ?? ''
        )
    );

if ($checkoutSessionId === '') {

    webhook_reply(
        400,
        'Missing checkout session ID.'
    );
}


/*
|--------------------------------------------------------------------------
| Find successful payment inside Checkout Session
|--------------------------------------------------------------------------
*/

$payments =
    $checkoutSession['attributes']['payments']
    ?? [];

$payment = null;

if (is_array($payments)) {

    foreach ($payments as $candidate) {

        if (!is_array($candidate)) {
            continue;
        }

        $candidateStatus =
            strtolower(
                trim(
                    (string)(
                        $candidate['attributes']['status']
                        ?? ''
                    )
                )
            );

        /*
         * Prefer a payment explicitly marked paid.
         */
        if ($candidateStatus === 'paid') {

            $payment = $candidate;

            break;
        }

        /*
         * Fallback for Checkout payloads where
         * status may not be included.
         */
        if (
            $payment === null &&
            !empty($candidate['id'])
        ) {

            $payment = $candidate;
        }
    }
}

if (!$payment) {

    log_err(
        'Paid checkout has no payment object. ' .
        'checkout_session_id=' .
        $checkoutSessionId
    );

    webhook_reply(
        500,
        'Payment information unavailable.'
    );
}


/*
|--------------------------------------------------------------------------
| Payment information
|--------------------------------------------------------------------------
*/

$paymentId =
    trim(
        (string)(
            $payment['id']
            ?? ''
        )
    );

$amountCentavos =
    (int)(
        $payment['attributes']['amount']
        ?? 0
    );

$currency =
    strtoupper(
        trim(
            (string)(
                $payment['attributes']['currency']
                ?? 'PHP'
            )
        )
    );

if ($amountCentavos <= 0) {

    log_err(
        'Invalid paid amount for checkout ' .
        $checkoutSessionId
    );

    webhook_reply(
        500,
        'Invalid payment amount.'
    );
}

if ($currency !== 'PHP') {

    log_err(
        'Unexpected currency for checkout ' .
        $checkoutSessionId .
        ': ' .
        $currency
    );

    webhook_reply(
        400,
        'Invalid payment currency.'
    );
}


/*
|--------------------------------------------------------------------------
| Start transaction
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | FIRST: Check if this belongs to MONTHLY DUES
    |--------------------------------------------------------------------------
    */

    $stmt =
        $conn->prepare("
            SELECT
                id,
                homeowner_id,
                phase,
                pay_year,
                pay_month,
                amount,
                status
            FROM finance_paymongo_checkouts
            WHERE checkout_session_id = ?
            LIMIT 1
            FOR UPDATE
        ");

    $stmt->bind_param(
        's',
        $checkoutSessionId
    );

    $stmt->execute();

    $financeCheckout =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | MONTHLY DUES PAYMENT
    |--------------------------------------------------------------------------
    */

    if ($financeCheckout) {

        $checkoutId =
            (int)$financeCheckout['id'];

        $homeownerId =
            (int)$financeCheckout['homeowner_id'];

        $phase =
            (string)$financeCheckout['phase'];

        $year =
            (int)$financeCheckout['pay_year'];

        $month =
            (int)$financeCheckout['pay_month'];

        $expectedAmount =
            (float)$financeCheckout['amount'];

        $expectedCentavos =
            (int)round(
                $expectedAmount * 100
            );


        /*
        |--------------------------------------------------------------------------
        | Verify exact amount
        |--------------------------------------------------------------------------
        */

        if (
            $amountCentavos !==
            $expectedCentavos
        ) {

            $conn->rollback();

            log_err(
                'DUES amount mismatch. Checkout=' .
                $checkoutSessionId .
                ', expected=' .
                $expectedCentavos .
                ', received=' .
                $amountCentavos
            );

            webhook_reply(
                400,
                'Payment amount mismatch.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Mark checkout paid
        |--------------------------------------------------------------------------
        */

        $stmt =
            $conn->prepare("
                UPDATE finance_paymongo_checkouts
                SET
                    status = 'paid',
                    payment_id = ?,
                    paid_at = NOW(),
                    last_event_type = ?,
                    last_event_id = ?
                WHERE id = ?
            ");

        $stmt->bind_param(
            'sssi',
            $paymentId,
            $eventType,
            $eventId,
            $checkoutId
        );

        $stmt->execute();

        $stmt->close();


        /*
        |--------------------------------------------------------------------------
        | Insert/update Finance payment
        |--------------------------------------------------------------------------
        */

        $reference =
            $paymentId !== ''
                ? $paymentId
                : $checkoutSessionId;

        $notes =
            'PayMongo';

        $stmt =
            $conn->prepare("
                INSERT INTO finance_payments
                (
                    homeowner_id,
                    phase,
                    pay_year,
                    pay_month,
                    amount,
                    status,
                    paid_at,
                    reference_no,
                    notes,
                    created_by_admin_id
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'paid',
                    NOW(),
                    ?,
                    ?,
                    NULL
                )

                ON DUPLICATE KEY UPDATE

                    amount =
                        VALUES(amount),

                    status =
                        'paid',

                    paid_at =
                        NOW(),

                    reference_no =
                        VALUES(reference_no),

                    notes =
                        VALUES(notes),

                    created_by_admin_id =
                        NULL
            ");

        $stmt->bind_param(
            'isiidss',
            $homeownerId,
            $phase,
            $year,
            $month,
            $expectedAmount,
            $reference,
            $notes
        );

        $stmt->execute();

        $stmt->close();


        $conn->commit();


        log_err(
            'PayMongo dues payment processed. ' .
            'checkout=' .
            $checkoutSessionId .
            ', event=' .
            $eventId
        );


        webhook_reply(
            200,
            'OK'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SECOND: Check PARKING checkout
    |--------------------------------------------------------------------------
    */

    $stmt =
        $conn->prepare("
            SELECT
                pc.id AS checkout_id,
                pc.permit_id,
                pc.homeowner_id,
                pc.phase,
                pc.amount,
                pc.status AS checkout_status,

                p.status AS permit_status,
                p.payment_status AS permit_payment_status,
                p.payment_method,
                p.permit_duration,
                p.request_type,
                p.renew_of_id

            FROM parking_paymongo_checkouts pc

            INNER JOIN parking_permits p
                ON p.id = pc.permit_id
               AND p.homeowner_id =
                   pc.homeowner_id
               AND p.phase =
                   pc.phase

            WHERE
                pc.checkout_session_id = ?

            LIMIT 1

            FOR UPDATE
        ");

    $stmt->bind_param(
        's',
        $checkoutSessionId
    );

    $stmt->execute();

    $parkingCheckout =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | Unknown Checkout Session
    |--------------------------------------------------------------------------
    */

    if (!$parkingCheckout) {

        $conn->rollback();

        /*
         * Valid PayMongo event, but it doesn't
         * belong to one of our saved checkouts.
         */
        log_err(
            'Unknown PayMongo checkout session: ' .
            $checkoutSessionId .
            ', event=' .
            $eventId
        );

        webhook_reply(
            200,
            'OK'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PARKING PAYMENT
    |--------------------------------------------------------------------------
    */

    $parkingCheckoutId =
        (int)$parkingCheckout['checkout_id'];

    $permitId =
        (int)$parkingCheckout['permit_id'];

    $homeownerId =
        (int)$parkingCheckout['homeowner_id'];

    $phase =
        (string)$parkingCheckout['phase'];

    $expectedAmount =
        (float)$parkingCheckout['amount'];

    $expectedCentavos =
        (int)round(
            $expectedAmount * 100
        );

    $permitStatus =
        strtolower(
            trim(
                (string)$parkingCheckout['permit_status']
            )
        );

    $permitPaymentStatus =
        strtolower(
            trim(
                (string)$parkingCheckout['permit_payment_status']
            )
        );

    $paymentMethod =
        strtolower(
            trim(
                (string)$parkingCheckout['payment_method']
            )
        );


    /*
    |--------------------------------------------------------------------------
    | Verify parking amount
    |--------------------------------------------------------------------------
    */

    if (
        $amountCentavos !==
        $expectedCentavos
    ) {

        $conn->rollback();

        log_err(
            'PARKING amount mismatch. Checkout=' .
            $checkoutSessionId .
            ', permit=' .
            $permitId .
            ', expected=' .
            $expectedCentavos .
            ', received=' .
            $amountCentavos
        );

        webhook_reply(
            400,
            'Payment amount mismatch.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Verify this really is an online permit
    |--------------------------------------------------------------------------
    */

    if ($paymentMethod !== 'online') {

        $conn->rollback();

        log_err(
            'Online PayMongo checkout matched a non-online permit. ' .
            'Permit=' .
            $permitId
        );

        webhook_reply(
            400,
            'Invalid permit payment method.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Mark parking checkout as paid
    |--------------------------------------------------------------------------
    */

    $stmt =
        $conn->prepare("
            UPDATE parking_paymongo_checkouts
            SET
                status = 'paid',
                payment_id = ?,
                paid_at = NOW(),
                last_event_type = ?,
                last_event_id = ?
            WHERE id = ?
        ");

    $stmt->bind_param(
        'sssi',
        $paymentId,
        $eventType,
        $eventId,
        $parkingCheckoutId
    );

    $stmt->execute();

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | Prevent additional checkout attempts
    |--------------------------------------------------------------------------
    |
    | Once one checkout has genuinely been paid,
    | all other pending checkouts for this permit
    | are expired.
    |
    */

    $stmt =
        $conn->prepare("
            UPDATE parking_paymongo_checkouts
            SET status = 'expired'
            WHERE permit_id = ?
              AND id <> ?
              AND status = 'pending'
        ");

    $stmt->bind_param(
        'ii',
        $permitId,
        $parkingCheckoutId
    );

    $stmt->execute();

    $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | Activate the permit
    |--------------------------------------------------------------------------
    |
    | The normal expected state is:
    |
    | permit status   = pending
    | payment status  = for payment
    |
    | We also allow payment_status = paid here
    | in case a previous webhook partially completed
    | before the process stopped.
    |
    */

    $canActivate =
        $permitStatus === 'pending' &&
        in_array(
            $permitPaymentStatus,
            [
                'for payment',
                'paid'
            ],
            true
        );

    $alreadyActiveAndPaid =
        $permitStatus === 'active' &&
        $permitPaymentStatus === 'paid';


    /*
    |--------------------------------------------------------------------------
    | First successful activation
    |--------------------------------------------------------------------------
    */

    if ($canActivate) {

        try {

            [
                $validFrom,
                $validUntil
            ] =
                parking_activation_dates(
                    $conn,
                    $parkingCheckout
                );

        } catch (InvalidArgumentException $e) {

            $conn->rollback();

            log_err(
                'PARKING invalid permit duration. ' .
                'Permit=' .
                $permitId .
                ', checkout=' .
                $checkoutSessionId .
                ', error=' .
                $e->getMessage()
            );

            /*
             * Returning 500 allows PayMongo to retry after
             * the bad permit data has been corrected.
             */
            webhook_reply(
                500,
                'Parking permit data requires review.'
            );
        }

        $stickerYear =
            (int)substr(
                $validFrom,
                0,
                4
            );

        $stmt =
            $conn->prepare("
                UPDATE parking_permits
                SET
                    payment_status = 'paid',
                    status = 'active',
                    valid_from = ?,
                    valid_until = ?,
                    sticker_year = ?
                WHERE id = ?
                  AND homeowner_id = ?
                  AND phase = ?
                  AND payment_method = 'online'
                  AND status = 'pending'
                  AND LOWER(
                        COALESCE(
                            payment_status,
                            ''
                        )
                      ) IN (
                        'for payment',
                        'paid'
                      )
            ");

        $stmt->bind_param(
            'ssiiis',
            $validFrom,
            $validUntil,
            $stickerYear,
            $permitId,
            $homeownerId,
            $phase
        );

        $stmt->execute();

        if ($stmt->affected_rows <= 0) {

            $stmt->close();

            throw new RuntimeException(
                'Parking permit activation state changed unexpectedly.'
            );
        }

        $stmt->close();


        log_err(
            'Parking permit activated from verified PayMongo payment. ' .
            'permit=' .
            $permitId .
            ', valid_from=' .
            $validFrom .
            ', valid_until=' .
            $validUntil .
            ', checkout=' .
            $checkoutSessionId .
            ', event=' .
            $eventId
        );


    /*
    |--------------------------------------------------------------------------
    | Duplicate / repeated paid webhook
    |--------------------------------------------------------------------------
    |
    | Do NOT recalculate validity here. The permit is already active,
    | so changing the start date on a repeated webhook would incorrectly
    | extend or shift the permit.
    |
    */

    } elseif ($alreadyActiveAndPaid) {

        log_err(
            'Duplicate parking paid webhook handled idempotently. ' .
            'permit=' .
            $permitId .
            ', checkout=' .
            $checkoutSessionId .
            ', event=' .
            $eventId
        );


    /*
    |--------------------------------------------------------------------------
    | Payment received but permit is no longer activatable
    |--------------------------------------------------------------------------
    |
    | Examples:
    | - rejected
    | - revoked
    | - expired
    | - another unexpected state change
    |
    | Preserve the fact that payment was received, but do not reactivate.
    |
    */

    } else {

        $stmt =
            $conn->prepare("
                UPDATE parking_permits
                SET payment_status = 'paid'
                WHERE id = ?
                  AND homeowner_id = ?
                  AND phase = ?
                  AND payment_method = 'online'
            ");

        $stmt->bind_param(
            'iis',
            $permitId,
            $homeownerId,
            $phase
        );

        $stmt->execute();

        $stmt->close();


        log_err(
            'Parking payment received but permit was NOT activated. ' .
            'Manual review required. Permit=' .
            $permitId .
            ', permit_status=' .
            $permitStatus .
            ', previous_payment_status=' .
            $permitPaymentStatus .
            ', checkout=' .
            $checkoutSessionId .
            ', event=' .
            $eventId
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Commit parking transaction
    |--------------------------------------------------------------------------
    */

    $conn->commit();


    webhook_reply(
        200,
        'OK'
    );


} catch (Throwable $e) {

    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    log_err(
        'PayMongo webhook DB failure. ' .
        'Checkout=' .
        $checkoutSessionId .
        ', event=' .
        $eventId .
        ', error=' .
        $e->getMessage()
    );

    webhook_reply(
        500,
        'Webhook processing failed.'
    );
}