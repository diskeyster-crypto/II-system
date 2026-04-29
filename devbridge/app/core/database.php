<?php
declare(strict_types=1);

namespace DevBridge\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $cfg = require dirname(__DIR__, 2) . '/config/config.php';
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $cfg['db_host'],
                $cfg['db_port'],
                $cfg['db_name']
            );
            try {
                self::$instance = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                error_log('DevBridge DB connection failed: ' . $e->getMessage());
                throw new \RuntimeException('Database connection failed.');
            }
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
