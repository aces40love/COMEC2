<?php

declare(strict_types=1);

/**
 * Loads configuration from an optional PHP file outside the document root,
 * then lets environment variables override scalar values.
 */
function app_config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $webRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
        $fileConfig = [];
        $configFile = getenv('COMEC_CONFIG_FILE');

        if (is_string($configFile) && trim($configFile) !== '') {
            $resolved = realpath(trim($configFile));
            if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
                throw new RuntimeException('COMEC_CONFIG_FILE is not a readable file.');
            }

            if (app_path_is_within($resolved, $webRoot)) {
                throw new RuntimeException('COMEC_CONFIG_FILE must be outside the public web root.');
            }

            $loaded = require $resolved;
            if (!is_array($loaded)) {
                throw new RuntimeException('COMEC_CONFIG_FILE must return an array.');
            }
            $fileConfig = $loaded;
        }

        $defaults = [
            'APP_ENV' => 'production',
            'APP_URL' => 'https://www.comec.org',
            'APP_KEY' => '',
            'HTTPS_ONLY' => true,
            'ERROR_LOG_FILE' => '',
            'ALLOWED_ORIGINS' => [],
            'TRUSTED_PROXY_IPS' => [],
            'RATE_LIMIT_SECRET' => '',
            'REGISTRATION_RATE_LIMIT' => 8,
            'REGISTRATION_RATE_WINDOW_SECONDS' => 900,
            'STATUS_RATE_LIMIT' => 120,
            'STATUS_RATE_WINDOW_SECONDS' => 300,

            'DB_DSN' => '',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => 3306,
            'DB_NAME' => '',
            'DB_USER' => '',
            'DB_PASSWORD' => '',

            'SQUARE_ENVIRONMENT' => 'production',
            'SQUARE_API_VERSION' => '2026-07-15',
            'SQUARE_ACCESS_TOKEN' => '',
            'SQUARE_LOCATION_ID' => '',
            'SQUARE_MERCHANT_ID' => '',
            'SQUARE_WEBHOOK_SIGNATURE_KEY' => '',
            'SQUARE_WEBHOOK_NOTIFICATION_URL' => '',
            'SQUARE_CATALOG_VARIATIONS' => [],
            'EVENT_DISCLOSURE_MODE' => 'payment_confirmation_only',
            'PACKAGE_BENEFITS' => [],
            'CHECKOUT_RETURN_URL' => '',
            'WEBHOOK_MAX_ATTEMPTS' => 12,
            'WEBHOOK_BATCH_SIZE' => 25,

            'ADMIN_USERNAME' => '',
            'ADMIN_PASSWORD_HASH' => '',

            'MAIL_TRANSPORT' => 'mail',
            'MAIL_FROM_EMAIL' => '',
            'MAIL_FROM_NAME' => 'COMEC',
            'MAIL_REPLY_TO' => '',
            'MAIL_SMTP_HOST' => '',
            'MAIL_SMTP_PORT' => 587,
            'MAIL_SMTP_ENCRYPTION' => 'tls',
            'MAIL_SMTP_USERNAME' => '',
            'MAIL_SMTP_PASSWORD' => '',
            'MAIL_SMTP_TIMEOUT' => 10,
            'MAIL_MAX_ATTEMPTS' => 5,
            'MAIL_BATCH_SIZE' => 25,
            'EMAIL_OUTBOX_RETENTION_DAYS' => 90,
            'INTERNAL_NOTIFICATION_RECIPIENTS' => [
                'pboals77@yahoo.com',
                'comecnonprofit@gmail.com',
                'underwoodudl@gmail.com',
            ],
        ];

        $config = array_replace($defaults, $fileConfig);

        $scalarEnvKeys = [
            'APP_ENV', 'APP_URL', 'APP_KEY', 'ERROR_LOG_FILE', 'RATE_LIMIT_SECRET',
            'DB_DSN', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
            'SQUARE_ENVIRONMENT', 'SQUARE_API_VERSION', 'SQUARE_ACCESS_TOKEN',
            'SQUARE_LOCATION_ID', 'SQUARE_MERCHANT_ID', 'SQUARE_WEBHOOK_SIGNATURE_KEY',
            'SQUARE_WEBHOOK_NOTIFICATION_URL', 'EVENT_DISCLOSURE_MODE', 'CHECKOUT_RETURN_URL',
            'ADMIN_USERNAME', 'ADMIN_PASSWORD_HASH', 'MAIL_TRANSPORT',
            'MAIL_FROM_EMAIL', 'MAIL_FROM_NAME', 'MAIL_REPLY_TO', 'MAIL_SMTP_HOST',
            'MAIL_SMTP_ENCRYPTION', 'MAIL_SMTP_USERNAME', 'MAIL_SMTP_PASSWORD',
        ];

        foreach ($scalarEnvKeys as $envKey) {
            $value = getenv($envKey);
            if ($value !== false) {
                $config[$envKey] = trim((string) $value);
            }
        }

        $config['EVENT_DISCLOSURE_MODE'] = app_disclosure_mode_value($config['EVENT_DISCLOSURE_MODE']);

        foreach ([
            'DB_PORT', 'REGISTRATION_RATE_LIMIT', 'REGISTRATION_RATE_WINDOW_SECONDS',
            'STATUS_RATE_LIMIT', 'STATUS_RATE_WINDOW_SECONDS', 'MAIL_SMTP_PORT',
            'MAIL_SMTP_TIMEOUT', 'MAIL_MAX_ATTEMPTS', 'MAIL_BATCH_SIZE',
            'EMAIL_OUTBOX_RETENTION_DAYS',
            'WEBHOOK_MAX_ATTEMPTS', 'WEBHOOK_BATCH_SIZE',
        ] as $intKey) {
            $value = getenv($intKey);
            if ($value !== false && filter_var($value, FILTER_VALIDATE_INT) !== false) {
                $config[$intKey] = (int) $value;
            } else {
                $config[$intKey] = (int) $config[$intKey];
            }
        }

        $httpsOnly = getenv('HTTPS_ONLY');
        if ($httpsOnly === false) {
            $config['HTTPS_ONLY'] = (bool) $config['HTTPS_ONLY'];
        } else {
            $parsedHttpsOnly = filter_var($httpsOnly, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($parsedHttpsOnly === null) {
                throw new RuntimeException('HTTPS_ONLY must be true or false.');
            }
            $config['HTTPS_ONLY'] = $parsedHttpsOnly;
        }

        foreach (['ALLOWED_ORIGINS', 'TRUSTED_PROXY_IPS', 'INTERNAL_NOTIFICATION_RECIPIENTS'] as $listKey) {
            $value = getenv($listKey);
            if ($value !== false) {
                $config[$listKey] = array_values(array_filter(array_map('trim', explode(',', $value))));
            } elseif (!is_array($config[$listKey])) {
                $config[$listKey] = [];
            }
        }

        $catalogJson = getenv('SQUARE_CATALOG_VARIATIONS_JSON');
        if ($catalogJson !== false && trim($catalogJson) !== '') {
            $decoded = json_decode($catalogJson, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new RuntimeException('SQUARE_CATALOG_VARIATIONS_JSON must be a JSON object.');
            }
            $config['SQUARE_CATALOG_VARIATIONS'] = $decoded;
        } elseif (!is_array($config['SQUARE_CATALOG_VARIATIONS'])) {
            $config['SQUARE_CATALOG_VARIATIONS'] = [];
        }

        $benefitsJson = getenv('PACKAGE_BENEFITS_JSON');
        if ($benefitsJson !== false && trim($benefitsJson) !== '') {
            $decoded = json_decode($benefitsJson, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new RuntimeException('PACKAGE_BENEFITS_JSON must be a JSON object.');
            }
            $config['PACKAGE_BENEFITS'] = $decoded;
        } elseif (!is_array($config['PACKAGE_BENEFITS'])) {
            $config['PACKAGE_BENEFITS'] = [];
        }

        if ($config['CHECKOUT_RETURN_URL'] === '') {
            $config['CHECKOUT_RETURN_URL'] = rtrim((string) $config['APP_URL'], '/') . '/registration-status.html';
        }
        if ($config['SQUARE_WEBHOOK_NOTIFICATION_URL'] === '') {
            $config['SQUARE_WEBHOOK_NOTIFICATION_URL'] = rtrim((string) $config['APP_URL'], '/') . '/api/square-webhook.php';
        }
        if ($config['RATE_LIMIT_SECRET'] === '') {
            $config['RATE_LIMIT_SECRET'] = (string) $config['APP_KEY'];
        }
    }

    if ($key === null) {
        return $config;
    }

    return array_key_exists($key, $config) ? $config[$key] : $default;
}

function app_disclosure_mode_value(mixed $value): string
{
    if (!is_string($value) || !in_array($value, ['payment_confirmation_only', 'benefit_fmv'], true)) {
        throw new RuntimeException(
            'EVENT_DISCLOSURE_MODE must be exactly payment_confirmation_only or benefit_fmv.'
        );
    }

    return $value;
}

function app_path_is_within(string $path, string $directory): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $directory = rtrim(str_replace('\\', '/', $directory), '/');
    $caseInsensitive = DIRECTORY_SEPARATOR === '\\';

    if ($caseInsensitive) {
        $path = strtolower($path);
        $directory = strtolower($directory);
    }

    return $path === $directory || str_starts_with($path, $directory . '/');
}

function app_require_config(array $keys): void
{
    $missing = [];
    foreach ($keys as $key) {
        $value = app_config($key);
        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            $missing[] = $key;
        }
    }

    if ($missing !== []) {
        throw new RuntimeException('Missing required configuration: ' . implode(', ', $missing));
    }
}
