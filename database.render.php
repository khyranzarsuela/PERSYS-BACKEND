<?php
declare(strict_types=1);

// PERSYS database configuration for Render.
// Database credentials are supplied through environment variables.

if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 86400);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('DB_HOST');
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $password = getenv('DB_PASSWORD');

    if (!$host || !$name || !$user || $password === false) {
        throw new RuntimeException(
            'Missing database environment configuration.'
        );
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $caPath = getenv('DB_SSL_CA');

    error_log(
    'PERSYS SSL diagnostic: ' .
    'DB_SSL_CA=' . ($caPath ?: '[not set]') .
    '; file_exists=' . ($caPath && file_exists($caPath) ? 'YES' : 'NO') .
    '; readable=' . ($caPath && is_readable($caPath) ? 'YES' : 'NO') .
    '; expected_file_exists=' . (file_exists('/etc/secrets/aiven-ca.pem') ? 'YES' : 'NO')
);

    if (!$caPath || !is_file($caPath) || !is_readable($caPath)) {
    error_log('PERSYS SSL certificate missing or unreadable.');
    throw new RuntimeException('Database SSL configuration failed.');
}

    if ($caPath) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    try {
        $pdo = new PDO($dsn, $user, $password, $options);
        return $pdo;
    } catch (PDOException $e) {
        error_log('PERSYS database error: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed.', 0, $e);
    }
}
