<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Database;

Auth::requireLogin();

$db     = Database::getInstance();
$filter = $_GET['status'] ?? '';

$sql = 'SELECT t.*, p.name AS project_name, r.github_repo
        FROM dev_tasks t
        JOIN projects p ON p.id = t.project_id
        JOIN repositories r ON r.id = t.repository_id';
$params = [];
if ($filter) {
    $sql    .= ' WHERE t.status = ?';
    $params[] = $filter;
}
$sql .= ' ORDER BY t.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

$statuses = [
    'draft','clarifying','ready_to_run','blocked_by_dependency',
    'issue_created','assigned_to_agent','pr_created','reviewing',
    'changes_requested','waiting_for_agent','waiting_for_operator',
    'approved_by_gpt','ready_for_manual_merge','merged','failed','cancelled',
];

$pageTitle = 'Dev Tasks';
$activeNav = 'tasks';
require APP_ROOT . '/views/layout.php';
?>

<div class="flex justify-between items-center mb-4">
  <div class="flex gap-2 items-center">
    <label class="text-muted text-sm">Filter:</label>
    <select onchange="location='?status='+this.value" style="width:auto">
      <option value="">All statuses</option>
      <?php foreach ($statuses as $s): ?>
        <option value="<?= $s ?>" <?= $filter === $s ? 'selected' : '' ?>><?= str_replace('_', ' ', $s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <a href="<?= BASE_URL ?>/admin/tasks/create.php" class="btn btn-primary">+ New Task</a>
</div>

<?php if (empty($tasks)): ?>
  <div class="card"><p class="text-muted">No tasks found.</p></div>
<?php else: ?>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Title</th>
          <th>Project</th>
          <th>Repo</th>
          <th>Status</th>
          <th>Priority</th>
          <th>Risk</th>
          <th>Issue</th>
          <th>PR</th>
          <th>Created</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tasks as $t): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>">#<?= $t['id'] ?></a></td>
          <td><a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>"><?= htmlspecialchars(mb_substr($t['title'], 0, 55), ENT_QUOTES, 'UTF-8') ?></a></td>
          <td class="text-sm text-muted"><?= htmlspecialchars($t['project_name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td class="text-sm text-muted"><?= htmlspecialchars($t['github_repo'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><span class="badge badge-draft"><?= htmlspecialchars(str_replace('_', ' ', $t['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
          <td><span class="badge badge-<?= htmlspecialchars($t['priority'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t['priority'], ENT_QUOTES, 'UTF-8') ?></span></td>
          <td><span class="badge badge-<?= htmlspecialchars($t['risk_level'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t['risk_level'], ENT_QUOTES, 'UTF-8') ?></span></td>
          <td><?php if ($t['github_issue_url']): ?><a href="<?= htmlspecialchars($t['github_issue_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">#<?= $t['github_issue_number'] ?></a><?php else: ?>—<?php endif; ?></td>
          <td><?php if ($t['github_pr_url']): ?><a href="<?= htmlspecialchars($t['github_pr_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">#<?= $t['github_pr_number'] ?></a><?php else: ?>—<?php endif; ?></td>
          <td class="text-sm text-muted"><?= htmlspecialchars(substr($t['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
