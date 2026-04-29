<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);
require __DIR__ . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;

// Redirect if already logged in
Auth::start();
if (Auth::check()) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (Auth::login($username, $password)) {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }
    $error = 'Invalid username or password.';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DevBridge – Login</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/public/css/app.css">
</head>
<body class="auth-page">
<div class="auth-card">
  <div class="auth-logo">⚙ DevBridge</div>
  <p class="auth-subtitle">AI Development Management System</p>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="post" class="auth-form">
    <?= Csrf::field() ?>
    <div class="form-group">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" autocomplete="username" autofocus required
             value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="form-group">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn btn-primary btn-full">Sign In</button>
  </form>
</div>
</body>
</html>
