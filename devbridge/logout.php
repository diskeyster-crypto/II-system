<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);
require __DIR__ . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;

Auth::start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
}
Auth::logout();
header('Location: ' . BASE_URL . '/login.php');
exit;
