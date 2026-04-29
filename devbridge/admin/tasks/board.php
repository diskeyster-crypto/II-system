<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(dirname(__DIR__))));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Database;

Auth::requireLogin();

$db = Database::getInstance();

$tasks = $db->query(
    'SELECT t.*, p.name AS project_name, r.github_repo
     FROM dev_tasks t
     JOIN projects p ON p.id = t.project_id
     JOIN repositories r ON r.id = t.repository_id
     WHERE t.status != "cancelled"
     ORDER BY FIELD(t.priority,"urgent","high","normal","low"), t.created_at DESC'
)->fetchAll();

// Group by board columns
$columns = [
    'Draft'               => ['draft', 'clarifying'],
    'Ready'               => ['ready_to_run', 'blocked_by_dependency'],
    'Running'             => ['issue_created', 'assigned_to_agent', 'pr_created'],
    'Waiting for Agent'   => ['waiting_for_agent'],
    'Waiting for Operator'=> ['waiting_for_operator'],
    'Reviewing'           => ['reviewing', 'changes_requested'],
    'Ready for Merge'     => ['approved_by_gpt', 'ready_for_manual_merge'],
    'Done'                => ['merged'],
    'Failed'              => ['failed'],
];

$grouped = [];
foreach ($columns as $col => $statuses) {
    $grouped[$col] = array_filter($tasks, fn($t) => in_array($t['status'], $statuses));
}

$pageTitle = 'Task Board';
$activeNav = 'board';
require APP_ROOT . '/views/layout.php';
?>

<style>
.board { padding-bottom: 20px; }
.priority-urgent .board-card-title::before { content: '🔴 '; }
.priority-high   .board-card-title::before { content: '🟠 '; }
.priority-normal .board-card-title::before { content: '🟡 '; }
.priority-low    .board-card-title::before { content: '⚪ '; }
</style>

<div class="flex gap-2 mb-4">
  <a href="<?= BASE_URL ?>/admin/tasks/create.php" class="btn btn-primary btn-sm">+ New Task</a>
</div>

<div class="board">
  <?php foreach ($grouped as $colName => $colTasks): ?>
  <div class="board-col">
    <div class="board-col-header">
      <?= htmlspecialchars($colName, ENT_QUOTES, 'UTF-8') ?>
      <span class="board-col-count"><?= count($colTasks) ?></span>
    </div>
    <?php if (empty($colTasks)): ?>
      <div class="board-card text-muted text-sm">empty</div>
    <?php else: ?>
      <?php foreach ($colTasks as $t): ?>
      <a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>" style="text-decoration:none;color:inherit;display:block">
        <div class="board-card priority-<?= htmlspecialchars($t['priority'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="board-card-title"><?= htmlspecialchars(mb_substr($t['title'], 0, 45), ENT_QUOTES, 'UTF-8') ?></div>
          <div class="board-card-meta">
            <?= htmlspecialchars($t['project_name'], ENT_QUOTES, 'UTF-8') ?>
            · <?= htmlspecialchars($t['github_repo'], ENT_QUOTES, 'UTF-8') ?>
          </div>
          <?php if ($t['branch_name']): ?>
            <div class="board-card-meta" style="font-size:0.72rem;margin-top:2px"><code><?= htmlspecialchars(mb_substr($t['branch_name'], 0, 35), ENT_QUOTES, 'UTF-8') ?></code></div>
          <?php endif; ?>
          <div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap">
            <?php if ($t['conflict_status'] !== 'none'): ?>
              <span class="badge badge-<?= htmlspecialchars($t['conflict_status'], ENT_QUOTES, 'UTF-8') ?>" style="font-size:0.7rem"><?= htmlspecialchars($t['conflict_status'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <?php if ($t['github_issue_number']): ?>
              <span class="badge badge-draft" style="font-size:0.7rem">I#<?= $t['github_issue_number'] ?></span>
            <?php endif; ?>
            <?php if ($t['github_pr_number']): ?>
              <span class="badge badge-reviewing" style="font-size:0.7rem">PR#<?= $t['github_pr_number'] ?></span>
            <?php endif; ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
