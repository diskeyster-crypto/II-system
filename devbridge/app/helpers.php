<?php
declare(strict_types=1);

use DevBridge\Core\I18n;

if (!function_exists('t')) {
    function t(string $key, array $params = []): string
    {
        return I18n::translate($key, $params);
    }
}

if (!function_exists('e')) {
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
