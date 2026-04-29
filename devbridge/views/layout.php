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
    'dashboard'          => ['icon' => '🏠', 'label' => t('menu.dashboard'),          'href' => BASE_URL . '/admin/dashboard.php'],
    'projects'           => ['icon' => '📁', 'label' => t('menu.projects'),            'href' => BASE_URL . '/admin/projects/'],
    'repositories'       => ['icon' => '🗄',  'label' => t('menu.repositories'),        'href' => BASE_URL . '/admin/repositories/'],
    'roadmaps'           => ['icon' => '🗺',  'label' => t('menu.roadmaps'),            'href' => BASE_URL . '/admin/roadmaps/'],
    'board'              => ['icon' => '📋', 'label' => t('menu.board'),               'href' => BASE_URL . '/admin/tasks/board.php'],
    'tasks'              => ['icon' => '⚡', 'label' => t('menu.tasks'),               'href' => BASE_URL . '/admin/tasks/'],
    'pull-requests'      => ['icon' => '🔀', 'label' => t('menu.pull_requests'),       'href' => BASE_URL . '/admin/pull-requests.php'],
    'reviews'            => ['icon' => '🔍', 'label' => t('menu.reviews'),             'href' => BASE_URL . '/admin/reviews/'],
    'operator-decisions' => ['icon' => '⚠', 'label' => t('menu.operator_decisions'),  'href' => BASE_URL . '/admin/operator-decisions.php'],
    'settings'           => ['icon' => '⚙', 'label' => t('menu.settings'),            'href' => BASE_URL . '/admin/settings.php'],
    'logs'               => ['icon' => '📄', 'label' => t('menu.logs'),               'href' => BASE_URL . '/admin/logs.php'],
    'system-check'       => ['icon' => '🩺', 'label' => t('menu.system_check'),       'href' => BASE_URL . '/admin/system-check.php'],
];
?><!DOCTYPE html>
<html lang="<?= e(DevBridge\Core\I18n::getLocale()) ?>">
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
        <button type="submit" class="btn-logout"><?= e(t('menu.logout')) ?></button>
      </form>
    </div>
  </aside>
  <main class="main-content">
    <div class="page-header">
      <h1 class="page-title"><?= htmlspecialchars($pageTitle ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <div class="page-body">
