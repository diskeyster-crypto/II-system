<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Database;
use DevBridge\Core\Logger;

Auth::requireLogin();

$db = Database::getInstance();

$stats = [];
try {
    $stats['projects']      = (int)$db->query('SELECT COUNT(*) FROM projects WHERE status = "active"')->fetchColumn();
    $stats['repositories']  = (int)$db->query('SELECT COUNT(*) FROM repositories WHERE status = "active"')->fetchColumn();
    $stats['active_tasks']  = (int)$db->query(
        'SELECT COUNT(*) FROM dev_tasks WHERE status NOT IN ("draft","merged","failed","cancelled")'
    )->fetchColumn();
    $stats['waiting']       = (int)$db->query(
        'SELECT COUNT(*) FROM dev_tasks WHERE status = "waiting_for_operator"'
    )->fetchColumn();
    $stats['prs_reviewing'] = (int)$db->query(
        'SELECT COUNT(*) FROM dev_tasks WHERE status IN ("reviewing","pr_created")'
    )->fetchColumn();

    $recentTasks = $db->query(
        'SELECT t.id, t.title, t.status, t.priority, t.created_at,
                p.name AS project_name, r.github_repo
         FROM dev_tasks t
         JOIN projects p ON p.id = t.project_id
         JOIN repositories r ON r.id = t.repository_id
         ORDER BY t.created_at DESC LIMIT 10'
    )->fetchAll();

    $recentLogs = Logger::recent(20);
} catch (\Throwable $e) {
    $stats = ['projects' => 0, 'repositories' => 0, 'active_tasks' => 0, 'waiting' => 0, 'prs_reviewing' => 0];
    $recentTasks = [];
    $recentLogs  = [];
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require APP_ROOT . '/views/layout.php';

function statusBadge(string $status): string {
    $map = [
        'draft'               => 'badge-draft',
        'clarifying'          => 'badge-reviewing',
        'ready_to_run'        => 'badge-active',
        'issue_created'       => 'badge-active',
        'assigned_to_agent'   => 'badge-active',
        'pr_created'          => 'badge-reviewing',
        'reviewing'           => 'badge-reviewing',
        'changes_requested'   => 'badge-waiting',
        'waiting_for_agent'   => 'badge-waiting',
        'waiting_for_operator'=> 'badge-waiting',
        'approved_by_gpt'     => 'badge-approved',
        'ready_for_manual_merge' => 'badge-approved',
        'merged'              => 'badge-merged',
        'failed'              => 'badge-failed',
        'cancelled'           => 'badge-draft',
    ];
    $cls = $map[$status] ?? 'badge-draft';
    return '<span class="badge ' . $cls . '">' . htmlspecialchars(str_replace('_', ' ', $status), ENT_QUOTES, 'UTF-8') . '</span>';
}
?>

<div class="stats-row">
  <div class="stat-card">
    <div class="stat-value"><?= $stats['projects'] ?></div>
    <div class="stat-label">Projects</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['repositories'] ?></div>
    <div class="stat-label">Repositories</div>
  </div>
  <div class="stat-card">
    <div class="stat-value"><?= $stats['active_tasks'] ?></div>
    <div class="stat-label">Active Dev Tasks</div>
  </div>
  <div class="stat-card">
    <div class="stat-value" style="color:var(--warning)"><?= $stats['waiting'] ?></div>
    <div class="stat-label">Waiting for Operator</div>
  </div>
  <div class="stat-card">
    <div class="stat-value" style="color:var(--accent)"><?= $stats['prs_reviewing'] ?></div>
    <div class="stat-label">PRs Under Review</div>
  </div>
</div>

<div class="flex gap-3" style="align-items:flex-start">
  <div style="flex:1.5">
    <div class="card">
      <div class="card-title">Recent Dev Tasks</div>
      <?php if (empty($recentTasks)): ?>
        <p class="text-muted text-sm">No tasks yet.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Title</th>
              <th>Project</th>
              <th>Status</th>
              <th>Priority</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentTasks as $t): ?>
            <tr>
              <td><a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>">#<?= $t['id'] ?></a></td>
              <td><a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>"><?= htmlspecialchars($t['title'], ENT_QUOTES, 'UTF-8') ?></a></td>
              <td class="text-sm text-muted"><?= htmlspecialchars($t['project_name'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= statusBadge($t['status']) ?></td>
              <td><span class="badge badge-<?= htmlspecialchars($t['priority'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($t['priority'], ENT_QUOTES, 'UTF-8') ?></span></td>
              <td class="text-sm text-muted"><?= htmlspecialchars(substr($t['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div style="flex:1">
    <div class="card">
      <div class="card-title">Recent Logs</div>
      <?php if (empty($recentLogs)): ?>
        <p class="text-muted text-sm">No logs yet.</p>
      <?php else: ?>
        <?php foreach (array_slice($recentLogs, 0, 15) as $log): ?>
          <div style="padding:6px 0;border-bottom:1px solid var(--border)">
            <span class="badge badge-draft text-sm"><?= htmlspecialchars($log['type'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="text-sm" style="margin-left:6px"><?= htmlspecialchars(mb_substr($log['message'], 0, 80), ENT_QUOTES, 'UTF-8') ?></span>
            <div class="text-muted" style="font-size:0.75rem"><?= htmlspecialchars(substr($log['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        <?php endforeach; ?>
        <div class="mt-3"><a href="<?= BASE_URL ?>/admin/logs.php" class="btn btn-secondary btn-sm">View all logs</a></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
