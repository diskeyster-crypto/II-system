<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Database;

Auth::requireLogin();

$db   = Database::getInstance();
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 50;
$type = trim($_GET['type'] ?? '');

$sql    = 'SELECT l.*, t.title AS task_title, p.name AS project_name FROM logs l LEFT JOIN dev_tasks t ON t.id = l.task_id LEFT JOIN projects p ON p.id = l.project_id';
$params = [];
if ($type) {
    $sql    .= ' WHERE l.type = ?';
    $params[] = $type;
}
$sql .= ' ORDER BY l.created_at DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per);

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$types = $db->query('SELECT DISTINCT type FROM logs ORDER BY type')->fetchAll(\PDO::FETCH_COLUMN);

$pageTitle = 'Logs';
$activeNav = 'logs';
require APP_ROOT . '/views/layout.php';
?>

<div class="flex gap-2 items-center mb-4">
  <label class="text-muted text-sm">Filter by type:</label>
  <select onchange="location='?type='+this.value" style="width:auto">
    <option value="">All</option>
    <?php foreach ($types as $t): ?>
      <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>" <?= $type === $t ? 'selected' : '' ?>>
        <?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>
      </option>
    <?php endforeach; ?>
  </select>
  <span class="text-muted text-sm">Page <?= $page ?></span>
</div>

<?php if (empty($logs)): ?>
  <div class="card"><p class="text-muted">No logs.</p></div>
<?php else: ?>
<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Time</th>
          <th>Type</th>
          <th>Message</th>
          <th>Task</th>
          <th>Project</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $l): ?>
        <tr>
          <td class="text-muted" style="white-space:nowrap;font-size:0.82rem"><?= htmlspecialchars(substr($l['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
          <td><span class="badge badge-draft" style="font-size:0.75rem"><?= htmlspecialchars($l['type'], ENT_QUOTES, 'UTF-8') ?></span></td>
          <td style="font-size:0.88rem"><?= htmlspecialchars(mb_substr($l['message'], 0, 150), ENT_QUOTES, 'UTF-8') ?>
            <?php if ($l['context']): ?>
              <details style="display:inline">
                <summary style="cursor:pointer;font-size:0.75rem;color:var(--muted)">context</summary>
                <pre style="font-size:0.75rem;margin:4px 0"><?= htmlspecialchars($l['context'], ENT_QUOTES, 'UTF-8') ?></pre>
              </details>
            <?php endif; ?>
          </td>
          <td><?php if ($l['task_id']): ?><a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $l['task_id'] ?>">#<?= $l['task_id'] ?><?php if ($l['task_title']): ?> – <?= htmlspecialchars(mb_substr($l['task_title'], 0, 30), ENT_QUOTES, 'UTF-8') ?><?php endif; ?></a><?php else: ?>—<?php endif; ?></td>
          <td class="text-sm text-muted"><?= htmlspecialchars($l['project_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="flex gap-2 mt-3">
    <?php if ($page > 1): ?>
      <a href="?type=<?= urlencode($type) ?>&page=<?= $page - 1 ?>" class="btn btn-secondary btn-sm">← Prev</a>
    <?php endif; ?>
    <?php if (count($logs) === $per): ?>
      <a href="?type=<?= urlencode($type) ?>&page=<?= $page + 1 ?>" class="btn btn-secondary btn-sm">Next →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
