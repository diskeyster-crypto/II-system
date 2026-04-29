<?php
declare(strict_types=1);

namespace DevBridge\Core;

use PDO;

class Auth
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION['admin_id']);
    }

    public static function requireLogin(): void
    {
        self::start();
        if (!self::check()) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }

    public static function login(string $username, string $password): bool
    {
        self::start();
        $db   = Database::getInstance();
        $stmt = $db->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? AND status = ? LIMIT 1');
        $stmt->execute([$username, 'active']);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin_id']       = $row['id'];
        $_SESSION['admin_username'] = $row['username'];
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function adminId(): ?int
    {
        self::start();
        return isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    }

    public static function adminUsername(): string
    {
        self::start();
        return $_SESSION['admin_username'] ?? '';
    }
}
