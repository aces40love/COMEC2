<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/http.php';

date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$logFile = (string) app_config('ERROR_LOG_FILE', '');
if ($logFile !== '') {
    ini_set('error_log', $logFile);
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function app_log(string $level, string $message, array $context = []): void
{
    $safeContext = [];
    foreach ($context as $key => $value) {
        if (preg_match('/password|secret|token|authorization|signature/i', (string) $key)) {
            $safeContext[$key] = '[redacted]';
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $safeContext[$key] = $value;
        }
    }

    $entry = [
        'timestamp' => gmdate('c'),
        'level' => strtoupper($level),
        'message' => $message,
        'context' => $safeContext,
    ];

    error_log(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}

