<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Database;

Auth::requireLogin();

$db   = Database::getInstance();
$rows = $db->query(
    'SELECT r.*, p.name AS project_name
     FROM repositories r
     JOIN projects p ON p.id = r.project_id
     ORDER BY r.created_at DESC'
)->fetchAll();

$pageTitle = 'Repositories';
$activeNav = 'repositories';
require APP_ROOT . '/views/layout.php';
?>

<div class="flex justify-between items-center mb-4">
  <div></div>
  <a href="<?= BASE_URL ?>/admin/repositories/create.php" class="btn btn-primary">+ Connect Repository</a>
</div>

<?php if (empty($rows)): ?>
  <div class="card"><p class="text-muted">No repositories yet. <a href="<?= BASE_URL ?>/admin/repositories/create.php">Connect one</a>.</p></div>
<?php else: ?>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Repository</th>
          <th>Project</th>
          <th>Branch</th>
          <th>Type</th>
          <th>Max Parallel</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <a href="<?= BASE_URL ?>/admin/repositories/view.php?id=<?= $r['id'] ?>">
              <?= htmlspecialchars($r['github_owner'] . '/' . $r['github_repo'], ENT_QUOTES, 'UTF-8') ?>
            </a>
          </td>
          <td><a href="<?= BASE_URL ?>/admin/projects/view.php?id=<?= $r['project_id'] ?>"><?= htmlspecialchars($r['project_name'], ENT_QUOTES, 'UTF-8') ?></a></td>
          <td><code><?= htmlspecialchars($r['default_branch'], ENT_QUOTES, 'UTF-8') ?></code></td>
          <td class="text-sm text-muted"><?= htmlspecialchars($r['repository_type'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= (int)$r['max_parallel_tasks'] ?></td>
          <td><span class="badge <?= $r['status'] === 'active' ? 'badge-active' : 'badge-draft' ?>"><?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
          <td>
            <a href="<?= BASE_URL ?>/admin/repositories/view.php?id=<?= $r['id'] ?>" class="btn btn-secondary btn-sm">View</a>
            <a href="<?= BASE_URL ?>/admin/repositories/edit.php?id=<?= $r['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
