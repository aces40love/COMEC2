<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/webhooks.php';

app_api(['POST'], static function (): array {
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        throw new ApiException(415, 'json_required', 'The webhook body must be JSON.');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1_000_000) {
        throw new ApiException(413, 'request_too_large', 'The webhook body is too large.');
    }

    $rawBody = file_get_contents('php://input', false, null, 0, 1_000_001);
    if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 1_000_000) {
        throw new ApiException(400, 'invalid_webhook', 'The webhook body is invalid.');
    }
    $signature = trim((string) ($_SERVER['HTTP_X_SQUARE_HMACSHA256_SIGNATURE'] ?? ''));
    if (!app_verify_square_webhook($rawBody, $signature)) {
        throw new ApiException(403, 'invalid_webhook_signature', 'The webhook signature is invalid.');
    }
    app_require_config(['SQUARE_MERCHANT_ID']);

    try {
        $event = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new ApiException(400, 'invalid_webhook', 'The webhook body is invalid.', [], $exception);
    }
    if (!is_array($event)) {
        throw new ApiException(400, 'invalid_webhook', 'The webhook body is invalid.');
    }

    $eventId = trim((string) ($event['event_id'] ?? ''));
    $eventType = trim((string) ($event['type'] ?? ''));
    if ($eventId === '' || strlen($eventId) > 255 || !preg_match('/^[\x21-\x7E]+$/', $eventId)
        || $eventType === '' || strlen($eventType) > 100 || !preg_match('/^[\x21-\x7E]+$/', $eventType)) {
        throw new ApiException(400, 'invalid_webhook', 'The webhook event metadata is invalid.');
    }

    $merchantValue = $event['merchant_id'] ?? '';
    $merchantId = is_string($merchantValue) ? trim($merchantValue) : '';
    if ($merchantId === '' || strlen($merchantId) > 192 || !preg_match('/^[\x21-\x7E]+$/', $merchantId)) {
        throw new ApiException(400, 'invalid_webhook', 'The webhook merchant identifier is invalid.');
    }
    $configuredMerchant = trim((string) app_config('SQUARE_MERCHANT_ID', ''));
    if (!hash_equals($configuredMerchant, $merchantId)) {
        throw new ApiException(403, 'wrong_merchant', 'The webhook merchant is not recognized.');
    }

    $data = $event['data'] ?? null;
    $objectIdValue = is_array($data) ? ($data['id'] ?? null) : null;
    $objectId = is_string($objectIdValue) ? trim($objectIdValue) : null;
    if (in_array($eventType, ['payment.updated', 'refund.updated'], true)
        && ($objectId === null || $objectId === '' || strlen($objectId) > 255
            || !preg_match('/^[\x21-\x7E]+$/', $objectId))) {
        throw new ApiException(400, 'invalid_webhook', 'The webhook object identifier is invalid.');
    }

    $pdo = app_db();
    $enqueued = app_enqueue_square_event(
        $pdo,
        $eventId,
        $eventType,
        $objectId,
        $merchantId,
        hash('sha256', $rawBody)
    );
    if (!$enqueued) {
        return ['ok' => true, 'duplicate' => true];
    }
    return ['ok' => true, 'queued' => true];
}, false);
