<?php
declare(strict_types=1);

require_once __DIR__ . '/Env.php';

final class Database
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $name = env('DB_NAME', 'pet_hotel_site');
        self::$connection = self::createConnection(is_string($name) ? $name : 'pet_hotel_site');

        return self::$connection;
    }

    public static function createConnection(?string $databaseName = null): PDO
    {
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $user = env('DB_USER', 'root');
        $pass = env('DB_PASS', '');
        $charset = env('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $host, $port, $charset);
        if (is_string($databaseName) && $databaseName !== '') {
            $dsn = sprintf('%s;dbname=%s', $dsn, $databaseName);
        }

        return new PDO(
            $dsn,
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
}
