<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

/**
 * One shared PDO connection per request. Emulated prepares are disabled so
 * PDO sends real parameterized queries to MySQL instead of interpolating
 * bound values into the SQL string client-side before sending it.
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $host = Env::get('DB_HOST', '127.0.0.1');
            $port = Env::get('DB_PORT', '3306');
            $name = Env::get('DB_NAME', 'inventory_api');
            $user = Env::get('DB_USER', 'root');
            $pass = Env::get('DB_PASS', '');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

            try {
                self::$connection = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                // Never leak host/credentials/driver detail to the client (rule 20/29).
                throw new \RuntimeException('Database connection failed.', previous: $e);
            }
        }

        return self::$connection;
    }
}
