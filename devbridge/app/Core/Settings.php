<?php
declare(strict_types=1);

namespace DevBridge\Core;

use PDO;

class Settings
{
    private static array $cache = [];

    public static function get(string $key, string $default = ''): string
    {
        if (!isset(self::$cache[$key])) {
            try {
                $db   = Database::getInstance();
                $stmt = $db->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
                $stmt->execute([$key]);
                $row = $stmt->fetch();
                self::$cache[$key] = $row ? $row['value'] : $default;
            } catch (\Throwable) {
                return $default;
            }
        }
        return self::$cache[$key];
    }

    public static function set(string $key, string $value): void
    {
        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare(
                'INSERT INTO settings (key_name, value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)'
            );
            $stmt->execute([$key, $value]);
            self::$cache[$key] = $value;
        } catch (\Throwable $e) {
            error_log('DevBridge Settings::set failed: ' . $e->getMessage());
        }
    }

    public static function getDecrypted(string $key, string $default = ''): string
    {
        $val = self::get($key, '');
        if ($val === '') {
            return $default;
        }
        try {
            return Encryption::decrypt($val);
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function setEncrypted(string $key, string $plaintext): void
    {
        $enc = Encryption::encrypt($plaintext);
        self::set($key, $enc);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
