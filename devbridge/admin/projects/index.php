<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Database;

Auth::requireLogin();

$db       = Database::getInstance();
$projects = $db->query('SELECT * FROM projects ORDER BY created_at DESC')->fetchAll();

$pageTitle = t('projects.title');
$activeNav = 'projects';
require APP_ROOT . '/views/layout.php';
?>

<div class="flex justify-between items-center mb-4">
  <div></div>
  <a href="<?= BASE_URL ?>/admin/projects/create.php" class="btn btn-primary"><?= e(t('projects.btn_new')) ?></a>
</div>

<?php if (empty($projects)): ?>
  <div class="card"><p class="text-muted"><?= e(t('projects.no_projects')) ?> <a href="<?= BASE_URL ?>/admin/projects/create.php"><?= e(t('projects.create_first')) ?></a>.</p></div>
<?php else: ?>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th><?= e(t('common.name')) ?></th>
          <th>Code</th>
          <th><?= e(t('projects.tech_stack_col')) ?></th>
          <th><?= e(t('projects.max_parallel_col')) ?></th>
          <th><?= e(t('common.status')) ?></th>
          <th><?= e(t('projects.created_col')) ?></th>
          <th><?= e(t('common.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($projects as $p): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/admin/projects/view.php?id=<?= $p['id'] ?>"><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></a></td>
          <td><code><?= htmlspecialchars($p['code'], ENT_QUOTES, 'UTF-8') ?></code></td>
          <td class="text-sm text-muted"><?= htmlspecialchars(mb_substr($p['tech_stack'] ?? '', 0, 60), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= (int)$p['max_parallel_tasks'] ?></td>
          <td><span class="badge <?= $p['status'] === 'active' ? 'badge-active' : 'badge-draft' ?>"><?= htmlspecialchars($p['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
          <td class="text-sm text-muted"><?= htmlspecialchars(substr($p['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <a href="<?= BASE_URL ?>/admin/projects/edit.php?id=<?= $p['id'] ?>" class="btn btn-secondary btn-sm"><?= e(t('common.edit')) ?></a>
            <a href="<?= BASE_URL ?>/admin/projects/view.php?id=<?= $p['id'] ?>" class="btn btn-secondary btn-sm"><?= e(t('common.view')) ?></a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
