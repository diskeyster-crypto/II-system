<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__ . '/..');
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
Auth::requireLogin();

header('Location: ' . BASE_URL . '/admin/dashboard.php');
exit;
