<?php
declare(strict_types=1);

namespace DevBridge\Core;

class Csrf
{
    private const TOKEN_KEY = '_csrf_token';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::TOKEN_KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function verify(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $token  = $_SESSION[self::TOKEN_KEY] ?? '';
        $posted = $_POST['_csrf'] ?? '';
        if (!$token || !hash_equals($token, $posted)) {
            http_response_code(403);
            die('CSRF token mismatch.');
        }
        // Rotate token after use
        unset($_SESSION[self::TOKEN_KEY]);
    }
}
