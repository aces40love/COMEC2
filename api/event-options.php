<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/packages.php';

app_api(['GET'], static function (): array {
    $eventValue = $_GET['event_code'] ?? '';
    $eventCode = is_string($eventValue) ? trim($eventValue) : '';
    return app_public_event_options($eventCode);
});

