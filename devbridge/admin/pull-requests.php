<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Database;

Auth::requireLogin();

$db = Database::getInstance();

// Tasks with PRs
$tasks = $db->query(
    'SELECT t.*, p.name AS project_name, r.github_owner, r.github_repo
     FROM dev_tasks t
     JOIN projects p ON p.id = t.project_id
     JOIN repositories r ON r.id = t.repository_id
     WHERE t.github_pr_number IS NOT NULL
     ORDER BY t.updated_at DESC'
)->fetchAll();

$pageTitle = 'Pull Requests';
$activeNav = 'pull-requests';
require APP_ROOT . '/views/layout.php';
?>

<?php if (empty($tasks)): ?>
  <div class="card"><p class="text-muted">No pull requests yet.</p></div>
<?php else: ?>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Task</th>
          <th>Project</th>
          <th>Repository</th>
          <th>Branch</th>
          <th>PR</th>
          <th>Status</th>
          <th>Review Cycles</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tasks as $t): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>">#<?= $t['id'] ?> <?= htmlspecialchars(mb_substr($t['title'], 0, 40), ENT_QUOTES, 'UTF-8') ?></a></td>
          <td class="text-sm"><?= htmlspecialchars($t['project_name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td class="text-sm"><?= htmlspecialchars($t['github_owner'] . '/' . $t['github_repo'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?php if ($t['branch_name']): ?><code style="font-size:0.78rem"><?= htmlspecialchars(mb_substr($t['branch_name'], 0, 35), ENT_QUOTES, 'UTF-8') ?></code><?php else: ?>—<?php endif; ?></td>
          <td><a href="<?= htmlspecialchars($t['github_pr_url'] ?? '#', ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">#<?= (int)$t['github_pr_number'] ?> ↗</a></td>
          <td><span class="badge badge-draft"><?= htmlspecialchars(str_replace('_', ' ', $t['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
          <td><?= (int)$t['review_cycles'] ?>/3</td>
          <td>
            <a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>" class="btn btn-secondary btn-sm">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
