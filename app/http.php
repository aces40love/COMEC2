<?php

declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

final class ApiResult
{
    public function __construct(public readonly array $body, public readonly int $status = 200)
    {
    }
}

function app_api(array $allowedMethods, callable $handler, bool $sameOrigin = true): never
{
    $requestId = bin2hex(random_bytes(8));
    app_security_headers($requestId);

    try {
        app_require_https();
        if ($sameOrigin) {
            app_enforce_same_origin();
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'OPTIONS') {
            header('Allow: ' . implode(', ', array_unique(array_merge($allowedMethods, ['OPTIONS']))));
            header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
            header('Access-Control-Allow-Headers: Content-Type, Authorization, Idempotency-Key');
            http_response_code(204);
            exit;
        }

        if (!in_array($method, $allowedMethods, true)) {
            header('Allow: ' . implode(', ', $allowedMethods));
            throw new ApiException(405, 'method_not_allowed', 'This request method is not allowed.');
        }

        $result = $handler();
        if ($result instanceof ApiResult) {
            app_json_response($result->body, $result->status, $requestId);
        }
        if (is_array($result)) {
            app_json_response($result, 200, $requestId);
        }

        throw new RuntimeException('API handler did not return a valid result.');
    } catch (ApiException $exception) {
        $error = [
            'ok' => false,
            'error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ],
            'request_id' => $requestId,
        ];
        if ($exception->details !== []) {
            $error['error']['fields'] = $exception->details;
        }

        if ($exception->httpStatus >= 500) {
            app_log('error', 'API request failed', [
                'request_id' => $requestId,
                'error_code' => $exception->errorCode,
                'exception' => get_class($exception),
            ]);
        }
        app_json_response($error, $exception->httpStatus, $requestId);
    } catch (Throwable $exception) {
        app_log('error', 'Unhandled API exception', [
            'request_id' => $requestId,
            'exception' => get_class($exception),
            'file' => basename($exception->getFile()),
            'line' => $exception->getLine(),
        ]);
        app_json_response([
            'ok' => false,
            'error' => [
                'code' => 'server_error',
                'message' => 'The request could not be completed. Please try again or contact COMEC.',
            ],
            'request_id' => $requestId,
        ], 500, $requestId);
    }
}

function app_security_headers(string $requestId): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
    header('X-Request-ID: ' . $requestId);
}

function app_require_https(): void
{
    if (!(bool) app_config('HTTPS_ONLY', true)) {
        return;
    }

    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https === 'on' || $https === '1') {
        return;
    }

    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $trusted = app_config('TRUSTED_PROXY_IPS', []);
    if (in_array($remote, $trusted, true)) {
        $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($forwardedProto === 'https') {
            return;
        }
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ((string) app_config('APP_ENV') !== 'production' && preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host)) {
        return;
    }

    throw new ApiException(400, 'https_required', 'A secure HTTPS connection is required.');
}

function app_enforce_same_origin(): void
{
    $allowed = app_config('ALLOWED_ORIGINS', []);
    $appOrigin = app_origin((string) app_config('APP_URL'));
    if ($appOrigin !== null) {
        $allowed[] = $appOrigin;
    }
    $allowed = array_values(array_unique(array_filter(array_map(
        static fn ($origin) => app_origin((string) $origin),
        $allowed
    ))));

    $originHeader = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($originHeader === '') {
        return;
    }

    $origin = app_origin($originHeader);
    if ($origin === null || !in_array($origin, $allowed, true)) {
        throw new ApiException(403, 'origin_not_allowed', 'This request origin is not allowed.');
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

function app_origin(string $url): ?string
{
    $parts = parse_url(trim($url));
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    $origin = $scheme . '://' . strtolower((string) $parts['host']);
    if (isset($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }
    return $origin;
}

function app_json_response(array $body, int $status, string $requestId): never
{
    http_response_code($status);
    $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false) {
        app_log('error', 'Failed to encode API response', ['request_id' => $requestId]);
        http_response_code(500);
        $encoded = '{"ok":false,"error":{"code":"server_error","message":"The response could not be encoded."}}';
    }
    echo $encoded;
    exit;
}

function app_read_json(int $maxBytes = 32768): array
{
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        throw new ApiException(415, 'json_required', 'Send the request as application/json.');
    }

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxBytes) {
        throw new ApiException(413, 'request_too_large', 'The request is too large.');
    }

    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || $raw === '') {
        throw new ApiException(400, 'invalid_json', 'A JSON request body is required.');
    }
    if (strlen($raw) > $maxBytes) {
        throw new ApiException(413, 'request_too_large', 'The request is too large.');
    }

    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new ApiException(400, 'invalid_json', 'The JSON request body is invalid.', [], $exception);
    }

    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new ApiException(400, 'invalid_json', 'The JSON request body must be an object.');
    }
    return $decoded;
}

function app_status_bearer_token(): string
{
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''));
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0 && is_string($value)) {
                $authorization = trim($value);
                break;
            }
        }
    }
    return preg_match('/^Bearer\s+([A-Za-z0-9_-]{43})$/i', $authorization, $matches) === 1
        ? $matches[1]
        : '';
}

function app_client_ip(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    $trusted = app_config('TRUSTED_PROXY_IPS', []);
    if (in_array($remote, $trusted, true)) {
        $forwarded = array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
        for ($index = count($forwarded) - 1; $index >= 0; $index--) {
            $candidate = $forwarded[$index];
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!in_array($candidate, $trusted, true)) {
                return $candidate;
            }
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
}

function app_ip_hash(): string
{
    app_require_config(['RATE_LIMIT_SECRET']);
    $secret = (string) app_config('RATE_LIMIT_SECRET');
    if (strlen($secret) < 32) {
        throw new RuntimeException('RATE_LIMIT_SECRET or APP_KEY must contain at least 32 characters.');
    }
    return hash_hmac('sha256', app_client_ip(), $secret);
}

function app_rate_limit(PDO $pdo, string $action, int $limit, int $windowSeconds): void
{
    if ($limit < 1 || $windowSeconds < 1) {
        throw new RuntimeException('Invalid rate-limit configuration.');
    }

    $keyHash = app_ip_hash();
    $seededAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $seed = $pdo->prepare(
        'INSERT INTO rate_limits (action, key_hash, window_started_at, hit_count, expires_at, updated_at) '
        . 'VALUES (?, ?, ?, 0, ?, ?) ON DUPLICATE KEY UPDATE action = VALUES(action)'
    );
    $seed->execute([
        $action,
        $keyHash,
        $seededAt->format('Y-m-d H:i:s.u'),
        $seededAt->modify('+' . $windowSeconds . ' seconds')->format('Y-m-d H:i:s.u'),
        $seededAt->format('Y-m-d H:i:s.u'),
    ]);

    $allowed = app_transaction($pdo, static function (PDO $pdo) use ($action, $keyHash, $limit, $windowSeconds): bool {
        $select = $pdo->prepare(
            'SELECT hit_count, window_started_at FROM rate_limits WHERE action = ? AND key_hash = ? FOR UPDATE'
        );
        $select->execute([$action, $keyHash]);
        $row = $select->fetch();

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!$row) {
            throw new RuntimeException('Rate-limit row disappeared.');
        }

        $windowStart = new DateTimeImmutable((string) $row['window_started_at'], new DateTimeZone('UTC'));
        if ($windowStart->modify('+' . $windowSeconds . ' seconds') <= $now) {
            $update = $pdo->prepare(
                'UPDATE rate_limits SET window_started_at = ?, hit_count = 1, expires_at = ?, updated_at = ? '
                . 'WHERE action = ? AND key_hash = ?'
            );
            $update->execute([
                $now->format('Y-m-d H:i:s.u'),
                $now->modify('+' . $windowSeconds . ' seconds')->format('Y-m-d H:i:s.u'),
                $now->format('Y-m-d H:i:s.u'),
                $action,
                $keyHash,
            ]);
            return true;
        }

        if ((int) $row['hit_count'] >= $limit) {
            return false;
        }

        $update = $pdo->prepare(
            'UPDATE rate_limits SET hit_count = hit_count + 1, updated_at = ? WHERE action = ? AND key_hash = ?'
        );
        $update->execute([$now->format('Y-m-d H:i:s.u'), $action, $keyHash]);
        return true;
    });

    if (!$allowed) {
        header('Retry-After: ' . $windowSeconds);
        throw new ApiException(429, 'rate_limited', 'Too many requests. Please wait and try again.');
    }
}

function app_append_query(string $url, array $parameters): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
}
