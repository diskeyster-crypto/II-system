<?php
/**
 * Shared admin layout.
 * Usage: define $pageTitle and $activeNav before including this file.
 * Then include layout_footer.php to close tags.
 */
use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;

$adminUser = Auth::adminUsername();
$nav = [
    'dashboard'          => ['icon' => '🏠', 'label' => 'Dashboard',          'href' => BASE_URL . '/admin/dashboard.php'],
    'projects'           => ['icon' => '📁', 'label' => 'Projects',            'href' => BASE_URL . '/admin/projects/'],
    'repositories'       => ['icon' => '🗄',  'label' => 'Repositories',        'href' => BASE_URL . '/admin/repositories/'],
    'roadmaps'           => ['icon' => '🗺',  'label' => 'Roadmaps',            'href' => BASE_URL . '/admin/roadmaps/'],
    'board'              => ['icon' => '📋', 'label' => 'Task Board',           'href' => BASE_URL . '/admin/tasks/board.php'],
    'tasks'              => ['icon' => '⚡', 'label' => 'Dev Tasks',            'href' => BASE_URL . '/admin/tasks/'],
    'pull-requests'      => ['icon' => '🔀', 'label' => 'Pull Requests',        'href' => BASE_URL . '/admin/pull-requests.php'],
    'reviews'            => ['icon' => '🔍', 'label' => 'Reviews',              'href' => BASE_URL . '/admin/reviews/'],
    'operator-decisions' => ['icon' => '⚠', 'label' => 'Operator Decisions',   'href' => BASE_URL . '/admin/operator-decisions.php'],
    'settings'           => ['icon' => '⚙', 'label' => 'Settings',             'href' => BASE_URL . '/admin/settings.php'],
    'logs'               => ['icon' => '📄', 'label' => 'Logs',                 'href' => BASE_URL . '/admin/logs.php'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? 'DevBridge', ENT_QUOTES, 'UTF-8') ?> – DevBridge</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/public/css/app.css">
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-brand">⚙ DevBridge</div>
    <nav class="sidebar-nav">
      <?php foreach ($nav as $key => $item): ?>
        <a href="<?= $item['href'] ?>" class="nav-item <?= ($activeNav ?? '') === $key ? 'active' : '' ?>">
          <span class="nav-icon"><?= $item['icon'] ?></span>
          <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <span class="nav-user">👤 <?= htmlspecialchars($adminUser, ENT_QUOTES, 'UTF-8') ?></span>
      <form method="post" action="<?= BASE_URL ?>/logout.php" style="display:inline">
        <?= Csrf::field() ?>
        <button type="submit" class="btn-logout">Logout</button>
      </form>
    </div>
  </aside>
  <main class="main-content">
    <div class="page-header">
      <h1 class="page-title"><?= htmlspecialchars($pageTitle ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="page-body">
