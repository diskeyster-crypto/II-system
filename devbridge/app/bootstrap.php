<?php
declare(strict_types=1);

// PSR-4 style autoloader for DevBridge (app/ maps to DevBridge\)
spl_autoload_register(function (string $class): void {
    $prefix = 'DevBridge\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// BASE_URL: the URL path to the devbridge/ directory (no trailing slash).
// Files set this before including bootstrap if they need a custom value.
if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // SCRIPT_NAME for a file like /devbridge/admin/dashboard.php
    // We want the path up to and including /devbridge
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    // Walk up directories until we reach one named "devbridge" or two levels up from deepest admin page
    $parts  = array_filter(explode('/', $scriptDir));
    $base   = '';
    $found  = false;
    foreach ($parts as $part) {
        $base .= '/' . $part;
        if (strtolower($part) === 'devbridge') {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $base = '/' . ltrim($scriptDir, '/');
    }
    define('BASE_URL', $scheme . '://' . $host . rtrim($base, '/'));
}
