<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/registrations.php';

app_api(['POST'], static function (): ApiResult {
    $pdo = app_db();
    app_rate_limit(
        $pdo,
        'create_registration',
        (int) app_config('REGISTRATION_RATE_LIMIT', 8),
        (int) app_config('REGISTRATION_RATE_WINDOW_SECONDS', 900)
    );
    $input = app_read_json(65536);
    $headerKey = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    $bodyValue = $input['idempotency_key'] ?? '';
    $bodyKey = is_string($bodyValue) ? trim($bodyValue) : '';
    if ($headerKey !== '' && $bodyKey !== '' && !hash_equals($headerKey, $bodyKey)) {
        throw new ApiException(400, 'idempotency_key_mismatch', 'The Idempotency-Key values do not match.');
    }
    $idempotencyKey = $headerKey !== '' ? $headerKey : $bodyKey;
    $validated = app_validate_registration_payload($input);
    return new ApiResult(app_create_registration($pdo, $validated, $idempotencyKey), 201);
});
