<?php
declare(strict_types=1);

// PSR-4 style autoloader for DevBridge (app/ maps to DevBridge\)
spl_autoload_register(function (string $class): void {
    $prefix = 'DevBridge\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = __DIR__ . '/' . strtolower(str_replace('\\', '/', $relative)) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Path constants (filesystem, no trailing slash)
if (!defined('BASE_PATH')) {
    define('BASE_PATH',    APP_ROOT);
    define('APP_PATH',     APP_ROOT . '/app');
    define('STORAGE_PATH', APP_ROOT . '/storage');
    define('CONFIG_PATH',  APP_ROOT . '/config');
    define('PUBLIC_PATH',  APP_ROOT . '/public');
}

// BASE_URL: the HTTP root of the devbridge installation (no trailing slash).
// Rules (in priority order):
//   1. Already defined (caller set it manually).
//   2. config/config.php has 'app_url' — use it.
//   3. Fallback: derive from SCRIPT_NAME by finding /admin/, /webhook/, /public/ boundaries.
if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $baseUrl = null;

    // 1. Try config/config.php
    $configFile = CONFIG_PATH . '/config.php';
    if (file_exists($configFile)) {
        try {
            $cfg = require $configFile;
            if (!empty($cfg['app_url'])) {
                $baseUrl = rtrim((string)$cfg['app_url'], '/');
            }
        } catch (\Throwable) {
            // ignore malformed config during early bootstrap
        }
    }

    // 2. Fallback: detect from SCRIPT_NAME
    if ($baseUrl === null) {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');

        // Markers that indicate where the app root ends
        $base = null;
        foreach (['/admin/', '/webhook/', '/public/'] as $marker) {
            $pos = strpos($scriptName, $marker);
            if ($pos !== false) {
                $base = substr($scriptName, 0, $pos); // everything before /admin/ etc.
                break;
            }
        }

        // Root-level scripts: derive from script directory
        if ($base === null) {
            $dir  = str_replace('\\', '/', dirname($scriptName));
            $base = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
        }

        $baseUrl = $scheme . '://' . $host . $base;
    }

    define('BASE_URL', $baseUrl);
}
