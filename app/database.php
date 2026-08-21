<?php

declare(strict_types=1);

function app_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = (string) app_config('DB_DSN', '');
    if ($dsn === '') {
        app_require_config(['DB_HOST', 'DB_NAME', 'DB_USER']);
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) app_config('DB_HOST'),
            (int) app_config('DB_PORT', 3306),
            (string) app_config('DB_NAME')
        );
    }

    $pdo = new PDO(
        $dsn,
        (string) app_config('DB_USER', ''),
        (string) app_config('DB_PASSWORD', ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_PERSISTENT => false,
        ]
    );

    return $pdo;
}

function app_transaction(PDO $pdo, callable $callback): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $callback($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

