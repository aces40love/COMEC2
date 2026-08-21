<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/registrations.php';

app_api(['GET'], static function (): array {
    $pdo = app_db();
    app_rate_limit(
        $pdo,
        'registration_status',
        (int) app_config('STATUS_RATE_LIMIT', 120),
        (int) app_config('STATUS_RATE_WINDOW_SECONDS', 300)
    );
    return app_registration_status($pdo, app_status_bearer_token());
});
