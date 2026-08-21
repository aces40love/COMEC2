<?php

declare(strict_types=1);

require_once __DIR__ . '/square.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/registrations.php';

function app_verify_square_webhook(string $rawBody, string $providedSignature): bool
{
    app_require_config(['SQUARE_WEBHOOK_SIGNATURE_KEY', 'SQUARE_WEBHOOK_NOTIFICATION_URL']);
    if ($providedSignature === '') {
        return false;
    }
    $expected = base64_encode(hash_hmac(
        'sha256',
        (string) app_config('SQUARE_WEBHOOK_NOTIFICATION_URL') . $rawBody,
        (string) app_config('SQUARE_WEBHOOK_SIGNATURE_KEY'),
        true
    ));
    return hash_equals($expected, $providedSignature);
}

function app_enqueue_square_event(
    PDO $pdo,
    string $eventId,
    string $eventType,
    ?string $objectId,
    string $merchantId,
    string $payloadHash
): bool
{
    try {
        $insert = $pdo->prepare(
            'INSERT INTO webhook_events '
            . '(event_id, event_type, object_id, merchant_id, payload_sha256, processing_status, attempts, available_at, created_at, updated_at) '
            . "VALUES (?, ?, ?, ?, ?, 'queued', 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))"
        );
        $insert->execute([$eventId, $eventType, $objectId, $merchantId, $payloadHash]);
        return true;
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() !== '23000') {
            throw $exception;
        }
    }

    return app_transaction($pdo, static function (PDO $pdo) use ($eventId, $payloadHash): bool {
        $select = $pdo->prepare(
            'SELECT payload_sha256 FROM webhook_events WHERE event_id = ? FOR UPDATE'
        );
        $select->execute([$eventId]);
        $event = $select->fetch();
        if (!$event) {
            throw new RuntimeException('Webhook deduplication row disappeared.');
        }
        if (!hash_equals((string) $event['payload_sha256'], $payloadHash)) {
            throw new ApiException(400, 'event_id_conflict', 'The webhook event identifier conflicts with an earlier event.');
        }
        return false;
    });
}

function app_claim_queued_square_event(PDO $pdo): ?array
{
    return app_transaction($pdo, static function (PDO $pdo): ?array {
        $statement = $pdo->query(
            "SELECT * FROM webhook_events WHERE "
            . "(processing_status = 'queued' AND available_at <= UTC_TIMESTAMP(6)) "
            . "OR (processing_status = 'processing' AND locked_at < UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE) "
            . 'ORDER BY created_at ASC LIMIT 1 FOR UPDATE'
        );
        $event = $statement->fetch();
        if (!$event) {
            return null;
        }

        $update = $pdo->prepare(
            "UPDATE webhook_events SET processing_status = 'processing', attempts = attempts + 1, "
            . 'locked_at = UTC_TIMESTAMP(6), last_error = NULL, updated_at = UTC_TIMESTAMP(6) WHERE event_id = ?'
        );
        $update->execute([(string) $event['event_id']]);
        $event['attempts'] = (int) $event['attempts'] + 1;
        return $event;
    });
}

function app_finish_square_event(PDO $pdo, string $eventId, string $status, string $result): void
{
    if (!in_array($status, ['processed', 'ignored'], true)) {
        throw new InvalidArgumentException('Invalid webhook completion status.');
    }
    $statement = $pdo->prepare(
        'UPDATE webhook_events SET processing_status = ?, result = ?, last_error = NULL, locked_at = NULL, '
        . 'processed_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6) WHERE event_id = ?'
    );
    $statement->execute([$status, substr($result, 0, 100), $eventId]);
}

function app_retry_square_event(PDO $pdo, array $event, Throwable $exception): void
{
    $error = get_class($exception);
    if ($exception instanceof SquareApiException) {
        $error .= ':square_http_' . $exception->httpStatus;
    }
    $attempts = (int) $event['attempts'];
    $maxAttempts = max(1, (int) app_config('WEBHOOK_MAX_ATTEMPTS', 12));
    $terminal = $attempts >= $maxAttempts;
    $delay = min(3600, 30 * (2 ** min(7, max(0, $attempts - 1))));
    $availableAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . $delay . ' seconds')
        ->format('Y-m-d H:i:s.u');

    $statement = $pdo->prepare(
        'UPDATE webhook_events SET processing_status = ?, available_at = ?, locked_at = NULL, last_error = ?, '
        . 'updated_at = UTC_TIMESTAMP(6) WHERE event_id = ?'
    );
    $statement->execute([
        $terminal ? 'failed' : 'queued',
        $availableAt,
        substr($error, 0, 255),
        (string) $event['event_id'],
    ]);
}

function app_process_square_event(PDO $pdo, array $event): array
{
    app_require_config(['SQUARE_MERCHANT_ID']);
    $merchantId = trim((string) ($event['merchant_id'] ?? ''));
    if ($merchantId === '' || !hash_equals((string) app_config('SQUARE_MERCHANT_ID'), $merchantId)) {
        return ['status' => 'ignored', 'result' => 'wrong_merchant'];
    }

    $type = (string) ($event['event_type'] ?? $event['type'] ?? '');
    if (!in_array($type, ['payment.updated', 'refund.updated'], true)) {
        return ['status' => 'ignored', 'result' => 'unhandled_event_type'];
    }
    $data = $event['data'] ?? null;
    $objectId = trim((string) ($event['object_id'] ?? (is_array($data) ? ($data['id'] ?? '') : '')));
    if ($objectId === '' || strlen($objectId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $objectId)) {
        return ['status' => 'ignored', 'result' => 'invalid_object_id'];
    }

    if ($type === 'payment.updated') {
        return app_process_square_payment($pdo, $objectId);
    }
    if ($type === 'refund.updated') {
        return app_process_square_refund($pdo, $objectId);
    }
    return ['status' => 'ignored', 'result' => 'unhandled_event_type'];
}

function app_process_square_payment(PDO $pdo, string $paymentId): array
{
    $payment = app_square_get_payment($paymentId);
    if ((string) ($payment['id'] ?? '') !== $paymentId) {
        return ['status' => 'ignored', 'result' => 'payment_id_mismatch'];
    }

    $registration = app_registration_for_square_payment($pdo, $payment);
    if ($registration === null) {
        return ['status' => 'ignored', 'result' => 'unmapped_order'];
    }

    $verification = app_verify_payment_against_registration($payment, $registration);
    if ($verification !== null) {
        app_log('critical', 'Square payment verification rejected', [
            'reference' => $registration['public_reference'],
            'reason' => $verification,
            'payment_id' => $paymentId,
        ]);
        return ['status' => 'ignored', 'result' => $verification];
    }

    $paymentStatus = strtoupper((string) ($payment['status'] ?? 'UNKNOWN'));
    if ($paymentStatus === 'COMPLETED' && app_square_datetime($payment['completed_at'] ?? null) === null) {
        throw new SquareApiException(503, [], 'Square has not supplied the completed payment timestamp yet.');
    }
    app_store_square_payment($pdo, $registration, $payment);

    return [
        'status' => 'processed',
        'result' => $paymentStatus === 'COMPLETED' ? 'registration_paid' : 'payment_' . strtolower($paymentStatus),
    ];
}

function app_process_square_refund(PDO $pdo, string $refundId): array
{
    $refund = app_square_get_refund($refundId);
    if ((string) ($refund['id'] ?? '') !== $refundId) {
        return ['status' => 'ignored', 'result' => 'refund_id_mismatch'];
    }
    if (strtoupper((string) ($refund['status'] ?? '')) !== 'COMPLETED') {
        return ['status' => 'processed', 'result' => 'refund_not_completed'];
    }

    $paymentId = trim((string) ($refund['payment_id'] ?? ''));
    if ($paymentId === '') {
        return ['status' => 'ignored', 'result' => 'refund_missing_payment'];
    }
    $payment = app_square_get_payment($paymentId);
    if ((string) ($payment['id'] ?? '') !== $paymentId) {
        return ['status' => 'ignored', 'result' => 'refund_payment_id_mismatch'];
    }
    if (strtoupper((string) ($payment['status'] ?? '')) !== 'COMPLETED'
        || app_square_datetime($payment['completed_at'] ?? null) === null) {
        throw new SquareApiException(503, [], 'Square has not supplied the completed payment details yet.');
    }
    $registration = app_registration_for_square_payment($pdo, $payment);
    if ($registration === null) {
        return ['status' => 'ignored', 'result' => 'refund_unmapped_order'];
    }

    $verification = app_verify_payment_against_registration($payment, $registration);
    if ($verification !== null) {
        app_log('critical', 'Square refund payment verification rejected', [
            'reference' => $registration['public_reference'],
            'reason' => $verification,
            'payment_id' => $paymentId,
        ]);
        return ['status' => 'ignored', 'result' => $verification];
    }

    $refundMoney = $refund['amount_money'] ?? null;
    if (!is_array($refundMoney)
        || strtoupper((string) ($refundMoney['currency'] ?? '')) !== (string) $registration['currency']
        || (int) ($refundMoney['amount'] ?? 0) <= 0
        || (string) ($refund['location_id'] ?? '') !== (string) app_config('SQUARE_LOCATION_ID')) {
        app_log('critical', 'Square refund verification rejected', [
            'reference' => $registration['public_reference'],
            'reason' => 'refund_money_or_location_mismatch',
            'payment_id' => $paymentId,
        ]);
        return ['status' => 'ignored', 'result' => 'refund_money_or_location_mismatch'];
    }

    $refundedMoney = $payment['refunded_money'] ?? null;
    $canonicalRefunded = is_array($refundedMoney)
        && strtoupper((string) ($refundedMoney['currency'] ?? '')) === (string) $registration['currency']
        ? max(0, (int) ($refundedMoney['amount'] ?? 0))
        : 0;
    if ($canonicalRefunded < (int) $refundMoney['amount']) {
        throw new SquareApiException(503, [], 'Square has not yet reflected the completed refund on the payment.');
    }

    app_store_square_payment($pdo, $registration, $payment);

    return ['status' => 'processed', 'result' => 'refund_recorded'];
}

function app_registration_for_square_payment(PDO $pdo, array $payment): ?array
{
    $orderId = trim((string) ($payment['order_id'] ?? ''));
    if ($orderId === '') {
        return null;
    }
    $statement = $pdo->prepare('SELECT * FROM registrations WHERE square_order_id = ? LIMIT 1');
    $statement->execute([$orderId]);
    $registration = $statement->fetch();
    return $registration ?: null;
}

function app_verify_payment_against_registration(array $payment, array $registration): ?string
{
    if ((string) ($payment['order_id'] ?? '') !== (string) $registration['square_order_id']) {
        return 'payment_order_mismatch';
    }
    if ((string) ($payment['location_id'] ?? '') !== (string) app_config('SQUARE_LOCATION_ID')
        || (string) $registration['square_location_id'] !== (string) app_config('SQUARE_LOCATION_ID')) {
        return 'payment_location_mismatch';
    }
    $money = $payment['amount_money'] ?? null;
    if (!is_array($money)
        || (int) ($money['amount'] ?? -1) !== (int) $registration['amount_cents']
        || strtoupper((string) ($money['currency'] ?? '')) !== (string) $registration['currency']) {
        return 'payment_amount_mismatch';
    }
    return null;
}

function app_store_square_payment(PDO $pdo, array $registration, array $payment): void
{
    app_transaction($pdo, static function (PDO $pdo) use ($registration, $payment): void {
        $lock = $pdo->prepare('SELECT * FROM registrations WHERE id = ? FOR UPDATE');
        $lock->execute([(int) $registration['id']]);
        $lockedRegistration = $lock->fetch();
        if (!$lockedRegistration) {
            throw new RuntimeException('Registration disappeared during payment processing.');
        }

        $refundedMoney = $payment['refunded_money'] ?? null;
        $incomingRefundedCents = is_array($refundedMoney)
            && strtoupper((string) ($refundedMoney['currency'] ?? '')) === (string) $lockedRegistration['currency']
            ? max(0, (int) ($refundedMoney['amount'] ?? 0))
            : 0;
        $storedPayment = app_upsert_payment_row(
            $pdo,
            $lockedRegistration,
            $payment,
            $incomingRefundedCents
        );

        if (strtoupper((string) $storedPayment['status']) !== 'COMPLETED') {
            return;
        }

        $completedAt = app_square_datetime($storedPayment['completed_at'] ?? null);
        if ($completedAt === null) {
            throw new SquareApiException(503, [], 'Square has not supplied the completed payment timestamp yet.');
        }

        $grossCents = (int) $lockedRegistration['amount_cents'];
        $refundedCents = app_monotonic_refunded_cents(
            0,
            (int) $storedPayment['refunded_amount_cents'],
            $grossCents
        );
        if ((string) $lockedRegistration['status'] === 'refunded') {
            $refundedCents = $grossCents;
            $paymentRefundUpdate = $pdo->prepare(
                'UPDATE payments SET refunded_amount_cents = GREATEST(refunded_amount_cents, ?), '
                . 'updated_at = UTC_TIMESTAMP(6) WHERE square_payment_id = ?'
            );
            $paymentRefundUpdate->execute([$grossCents, (string) $payment['id']]);
        }

        $registrationStatus = app_completed_registration_status(
            (string) $lockedRegistration['status'],
            $refundedCents,
            $grossCents
        );
        $update = $pdo->prepare(
            'UPDATE registrations SET status = ?, paid_at = COALESCE(paid_at, ?), '
            . 'refunded_at = IF(? > 0, COALESCE(refunded_at, UTC_TIMESTAMP(6)), refunded_at), '
            . 'updated_at = UTC_TIMESTAMP(6) WHERE id = ?'
        );
        $update->execute([
            $registrationStatus,
            $completedAt,
            $refundedCents,
            (int) $lockedRegistration['id'],
        ]);

        $roster = app_load_registration_roster($pdo, (int) $lockedRegistration['id']);
        app_enqueue_paid_confirmation($pdo, $lockedRegistration, $roster, $payment);
        app_enqueue_internal_paid_notifications($pdo, $lockedRegistration, $roster, $payment);

        if ($refundedCents > 0) {
            $fullyRefunded = $refundedCents >= $grossCents;
            app_enqueue_refund_confirmation($pdo, $lockedRegistration, $refundedCents, $fullyRefunded);
            app_enqueue_internal_refund_notifications(
                $pdo,
                $lockedRegistration,
                $refundedCents,
                $fullyRefunded,
                $payment
            );
        }
    });
}

function app_upsert_payment_row(PDO $pdo, array $registration, array $payment, int $refundedCents): array
{
    $money = $payment['amount_money'];
    $paymentId = (string) $payment['id'];
    $orderId = (string) $payment['order_id'];
    $amountCents = (int) $money['amount'];
    $currency = strtoupper((string) $money['currency']);
    $incomingStatus = strtoupper((string) ($payment['status'] ?? 'UNKNOWN'));
    $incomingReceipt = app_safe_receipt_url($payment['receipt_url'] ?? null);
    $incomingCompletedAt = app_square_datetime($payment['completed_at'] ?? null);
    $incomingUpdatedAt = app_square_datetime($payment['updated_at'] ?? null);
    $refundedCents = app_monotonic_refunded_cents(
        0,
        $refundedCents,
        (int) $registration['amount_cents']
    );

    $select = $pdo->prepare('SELECT * FROM payments WHERE square_payment_id = ? FOR UPDATE');
    $select->execute([$paymentId]);
    $existing = $select->fetch();

    if (!$existing) {
        $insert = $pdo->prepare(
            'INSERT INTO payments '
            . '(registration_id, square_payment_id, square_order_id, status, amount_cents, refunded_amount_cents, currency, '
            . 'receipt_url, completed_at, square_updated_at, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
        );
        $insert->execute([
            (int) $registration['id'], $paymentId, $orderId, $incomingStatus, $amountCents,
            $refundedCents, $currency, $incomingReceipt, $incomingCompletedAt, $incomingUpdatedAt,
        ]);
        return [
            'registration_id' => (int) $registration['id'],
            'status' => $incomingStatus,
            'refunded_amount_cents' => $refundedCents,
            'receipt_url' => $incomingReceipt,
            'completed_at' => $incomingCompletedAt,
            'square_updated_at' => $incomingUpdatedAt,
        ];
    }

    if ((int) $existing['registration_id'] !== (int) $registration['id']
        || (string) $existing['square_order_id'] !== $orderId
        || (int) $existing['amount_cents'] !== $amountCents
        || strtoupper((string) $existing['currency']) !== $currency) {
        throw new RuntimeException('Square payment conflicts with its stored registration snapshot.');
    }

    $fresh = app_square_update_is_current($existing['square_updated_at'] ?? null, $incomingUpdatedAt);
    $effectiveStatus = $fresh ? $incomingStatus : (string) $existing['status'];
    $effectiveRefundedCents = app_monotonic_refunded_cents(
        (int) $existing['refunded_amount_cents'],
        $refundedCents,
        (int) $registration['amount_cents']
    );
    $effectiveReceipt = $fresh
        ? ($incomingReceipt ?? app_safe_receipt_url($existing['receipt_url'] ?? null))
        : app_safe_receipt_url($existing['receipt_url'] ?? null);
    $effectiveCompletedAt = app_square_datetime($existing['completed_at'] ?? null) ?? $incomingCompletedAt;
    $effectiveUpdatedAt = $fresh && $incomingUpdatedAt !== null
        ? $incomingUpdatedAt
        : app_square_datetime($existing['square_updated_at'] ?? null);

    $update = $pdo->prepare(
        'UPDATE payments SET status = ?, refunded_amount_cents = ?, receipt_url = ?, completed_at = ?, '
        . 'square_updated_at = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?'
    );
    $update->execute([
        $effectiveStatus,
        $effectiveRefundedCents,
        $effectiveReceipt,
        $effectiveCompletedAt,
        $effectiveUpdatedAt,
        (int) $existing['id'],
    ]);

    return [
        'registration_id' => (int) $registration['id'],
        'status' => $effectiveStatus,
        'refunded_amount_cents' => $effectiveRefundedCents,
        'receipt_url' => $effectiveReceipt,
        'completed_at' => $effectiveCompletedAt,
        'square_updated_at' => $effectiveUpdatedAt,
    ];
}

function app_monotonic_refunded_cents(int $existingCents, int $incomingCents, int $grossCents): int
{
    $grossCents = max(0, $grossCents);
    return min($grossCents, max(0, $existingCents, $incomingCents));
}

function app_square_update_is_current(mixed $existingUpdatedAt, mixed $incomingUpdatedAt): bool
{
    $existing = app_square_datetime($existingUpdatedAt);
    if ($existing === null) {
        return true;
    }
    $incoming = app_square_datetime($incomingUpdatedAt);
    return $incoming !== null && strcmp($incoming, $existing) >= 0;
}

function app_completed_registration_status(string $currentStatus, int $refundedCents, int $grossCents): string
{
    if ($currentStatus === 'refunded' || ($grossCents > 0 && $refundedCents >= $grossCents)) {
        return 'refunded';
    }
    if ($currentStatus === 'partially_refunded' || $refundedCents > 0) {
        return 'partially_refunded';
    }
    return 'paid';
}

function app_safe_receipt_url(mixed $url): ?string
{
    return app_validated_square_receipt_url($url);
}

function app_square_datetime(mixed $value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    } catch (Throwable) {
        return null;
    }
}
