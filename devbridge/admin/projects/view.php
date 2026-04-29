<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(dirname(__DIR__))));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Database;

Auth::requireLogin();

$db      = Database::getInstance();
$id      = (int)($_GET['id'] ?? 0);
$stmt    = $db->prepare('SELECT * FROM projects WHERE id = ?');
$stmt->execute([$id]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    die('Project not found.');
}

// Repos and tasks
$repos = $db->prepare('SELECT * FROM repositories WHERE project_id = ? ORDER BY created_at DESC');
$repos->execute([$id]);
$repos = $repos->fetchAll();

$tasks = $db->prepare(
    'SELECT t.*, r.github_repo
     FROM dev_tasks t
     JOIN repositories r ON r.id = t.repository_id
     WHERE t.project_id = ?
     ORDER BY t.created_at DESC LIMIT 20'
);
$tasks->execute([$id]);
$tasks = $tasks->fetchAll();

$pageTitle = $project['name'];
$activeNav = 'projects';
require APP_ROOT . '/views/layout.php';
?>

<?php if (isset($_GET['created'])): ?>
  <div class="alert alert-success">Project created successfully.</div>
<?php endif; ?>

<div class="flex gap-2 mb-4">
  <a href="<?= BASE_URL ?>/admin/projects/edit.php?id=<?= $id ?>" class="btn btn-secondary btn-sm">✏ Edit</a>
  <a href="<?= BASE_URL ?>/admin/repositories/create.php?project_id=<?= $id ?>" class="btn btn-primary btn-sm">+ Add Repository</a>
</div>

<div class="flex gap-3" style="align-items:flex-start">
  <div style="flex:1">
    <div class="card">
      <div class="card-title">Project Details</div>
      <table style="width:100%">
        <tr><td style="width:140px" class="text-muted">Code</td><td><code><?= htmlspecialchars($project['code'], ENT_QUOTES, 'UTF-8') ?></code></td></tr>
        <tr><td class="text-muted">Status</td><td><span class="badge <?= $project['status'] === 'active' ? 'badge-active' : 'badge-draft' ?>"><?= htmlspecialchars($project['status'], ENT_QUOTES, 'UTF-8') ?></span></td></tr>
        <tr><td class="text-muted">Max Parallel</td><td><?= (int)$project['max_parallel_tasks'] ?></td></tr>
        <tr><td class="text-muted">AI Model</td><td><?= htmlspecialchars($project['default_ai_model'] ?? 'default', ENT_QUOTES, 'UTF-8') ?></td></tr>
      </table>

      <?php if ($project['description']): ?>
        <hr class="divider">
        <p><?= htmlspecialchars($project['description'], ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>

      <?php if ($project['global_rules']): ?>
        <hr class="divider">
        <div class="card-title text-sm">Global Rules</div>
        <pre style="font-size:0.82rem"><?= htmlspecialchars($project['global_rules'], ENT_QUOTES, 'UTF-8') ?></pre>
      <?php endif; ?>
    </div>
  </div>

  <div style="flex:2">
    <div class="card">
      <div class="flex justify-between items-center mb-3">
        <div class="card-title" style="margin:0">Repositories</div>
        <a href="<?= BASE_URL ?>/admin/repositories/create.php?project_id=<?= $id ?>" class="btn btn-primary btn-sm">+ Add</a>
      </div>
      <?php if (empty($repos)): ?>
        <p class="text-muted text-sm">No repositories yet.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Repo</th><th>Branch</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($repos as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['github_owner'] . '/' . $r['github_repo'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><code><?= htmlspecialchars($r['default_branch'], ENT_QUOTES, 'UTF-8') ?></code></td>
                <td><span class="badge <?= $r['status'] === 'active' ? 'badge-active' : 'badge-draft' ?>"><?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><a href="<?= BASE_URL ?>/admin/repositories/view.php?id=<?= $r['id'] ?>" class="btn btn-secondary btn-sm">View</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="flex justify-between items-center mb-3">
        <div class="card-title" style="margin:0">Recent Tasks</div>
        <a href="<?= BASE_URL ?>/admin/tasks/create.php?project_id=<?= $id ?>" class="btn btn-primary btn-sm">+ New Task</a>
      </div>
      <?php if (empty($tasks)): ?>
        <p class="text-muted text-sm">No tasks yet.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>#</th><th>Title</th><th>Repo</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($tasks as $t): ?>
              <tr>
                <td><a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>">#<?= $t['id'] ?></a></td>
                <td><?= htmlspecialchars(mb_substr($t['title'], 0, 60), ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-sm text-muted"><?= htmlspecialchars($t['github_repo'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="badge badge-draft"><?= htmlspecialchars(str_replace('_', ' ', $t['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
