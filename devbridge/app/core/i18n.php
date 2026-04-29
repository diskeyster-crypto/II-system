<?php
declare(strict_types=1);

namespace DevBridge\Core;

class I18n
{
    private static string $locale = 'ru';
    private static string $fallback = 'en';
    /** @var array<string, array<string, mixed>> */
    private static array $loaded = [];

    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
    }

    public static function getLocale(): string
    {
        return self::$locale;
    }

    public static function setFallback(string $locale): void
    {
        self::$fallback = $locale;
    }

    public static function translate(string $key, array $params = []): string
    {
        // key format: "file.dot.path" e.g. "menu.dashboard" or "common.buttons.save"
        $parts = explode('.', $key, 2);
        $file  = $parts[0];
        $path  = $parts[1] ?? '';

        $value = self::lookup($file, $path, self::$locale)
              ?? self::lookup($file, $path, self::$fallback)
              ?? $key;

        if (!is_string($value)) {
            $value = $key;
        }

        foreach ($params as $placeholder => $replacement) {
            $value = str_replace(':' . $placeholder, (string)$replacement, $value);
        }

        return $value;
    }

    public static function has(string $key): bool
    {
        $parts = explode('.', $key, 2);
        $file  = $parts[0];
        $path  = $parts[1] ?? '';
        return self::lookup($file, $path, self::$locale) !== null
            || self::lookup($file, $path, self::$fallback) !== null;
    }

    private static function lookup(string $file, string $path, string $locale): mixed
    {
        $cacheKey = $locale . '.' . $file;
        if (!isset(self::$loaded[$cacheKey])) {
            $filePath = defined('APP_ROOT')
                ? APP_ROOT . '/resources/lang/' . $locale . '/' . $file . '.php'
                : dirname(__DIR__, 2) . '/resources/lang/' . $locale . '/' . $file . '.php';
            if (file_exists($filePath)) {
                self::$loaded[$cacheKey] = require $filePath;
            } else {
                self::$loaded[$cacheKey] = [];
            }
        }

        $data = self::$loaded[$cacheKey];
        if ($path === '') {
            return is_string($data) ? $data : null;
        }

        foreach (explode('.', $path) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return is_string($data) ? $data : null;
    }
}
