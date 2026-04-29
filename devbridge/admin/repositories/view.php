<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;
use DevBridge\Services\GitHubService;
use DevBridge\Services\RepositoryBootstrapper;

Auth::requireLogin();

$db  = Database::getInstance();
$id  = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT r.*, p.name AS project_name FROM repositories r JOIN projects p ON p.id = r.project_id WHERE r.id = ?');
$stmt->execute([$id]);
$repo = $stmt->fetch();

if (!$repo) { http_response_code(404); die('Repository not found.'); }

$error   = '';
$success = '';
$bootstrapResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'bootstrap') {
        try {
            $gh          = new GitHubService();
            $bootstrapper = new RepositoryBootstrapper($gh);
            $bootstrapResult = $bootstrapper->bootstrap($id, isset($_POST['overwrite']));
            $success = 'Bootstrap completed. See results below.';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'install_webhook') {
        try {
            $gh = new GitHubService();
            $bootstrapper = new RepositoryBootstrapper($gh);
            $r = $bootstrapper->bootstrap($id, false);
            $webhookResult = $r['webhook'] ?? [];
            if ($webhookResult['ok'] ?? false) {
                $success = 'Webhook installed.';
            } else {
                $error = $webhookResult['error'] ?? $webhookResult['reason'] ?? 'Unknown error';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// Tasks for this repo
$tasks = $db->prepare(
    'SELECT id, title, status, priority, created_at FROM dev_tasks WHERE repository_id = ? ORDER BY created_at DESC LIMIT 15'
);
$tasks->execute([$id]);
$tasks = $tasks->fetchAll();

$pageTitle = $repo['github_owner'] . '/' . $repo['github_repo'];
$activeNav = 'repositories';
require APP_ROOT . '/views/layout.php';
?>

<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Repository connected successfully.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<div class="flex gap-3" style="align-items:flex-start">
  <div style="flex:1">
    <div class="card">
      <div class="card-title">Repository Info</div>
      <table>
        <tr><td class="text-muted" style="width:130px">Project</td><td><a href="<?= BASE_URL ?>/admin/projects/view.php?id=<?= $repo['project_id'] ?>"><?= htmlspecialchars($repo['project_name'], ENT_QUOTES, 'UTF-8') ?></a></td></tr>
        <tr><td class="text-muted">Owner</td><td><?= htmlspecialchars($repo['github_owner'], ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td class="text-muted">Repo</td><td><?= htmlspecialchars($repo['github_repo'], ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td class="text-muted">Branch</td><td><code><?= htmlspecialchars($repo['default_branch'], ENT_QUOTES, 'UTF-8') ?></code></td></tr>
        <tr><td class="text-muted">Type</td><td><?= htmlspecialchars($repo['repository_type'], ENT_QUOTES, 'UTF-8') ?></td></tr>
        <tr><td class="text-muted">Status</td><td><span class="badge <?= $repo['status'] === 'active' ? 'badge-active' : 'badge-draft' ?>"><?= htmlspecialchars($repo['status'], ENT_QUOTES, 'UTF-8') ?></span></td></tr>
        <tr><td class="text-muted">Max Parallel</td><td><?= (int)$repo['max_parallel_tasks'] ?></td></tr>
        <tr><td class="text-muted">Webhook Secret</td><td><code>****<?= htmlspecialchars(substr($repo['github_webhook_secret'] ?? '', -4), ENT_QUOTES, 'UTF-8') ?></code></td></tr>
      </table>
      <div class="flex gap-2 mt-3">
        <a href="<?= BASE_URL ?>/admin/repositories/edit.php?id=<?= $id ?>" class="btn btn-secondary btn-sm">✏ Edit</a>
        <a href="https://github.com/<?= urlencode($repo['github_owner']) . '/' . urlencode($repo['github_repo']) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Open on GitHub ↗</a>
      </div>
    </div>

    <div class="card">
      <div class="card-title">Bootstrap Repository</div>
      <p class="text-muted text-sm">Creates DevBridge files, default labels, and installs webhook.</p>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="bootstrap">
        <div class="form-group">
          <label><input type="checkbox" name="overwrite"> Overwrite existing DevBridge files</label>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">🚀 Bootstrap Repository</button>
      </form>

      <?php if ($bootstrapResult): ?>
        <hr class="divider">
        <pre style="font-size:0.78rem;max-height:300px;overflow:auto"><?= htmlspecialchars(json_encode($bootstrapResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></pre>
      <?php endif; ?>
    </div>
  </div>

  <div style="flex:2">
    <div class="card">
      <div class="flex justify-between items-center mb-3">
        <div class="card-title" style="margin:0">Dev Tasks</div>
        <a href="<?= BASE_URL ?>/admin/tasks/create.php?repository_id=<?= $id ?>" class="btn btn-primary btn-sm">+ New Task</a>
      </div>
      <?php if (empty($tasks)): ?>
        <p class="text-muted text-sm">No tasks for this repository.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>#</th><th>Title</th><th>Status</th><th>Priority</th></tr></thead>
            <tbody>
              <?php foreach ($tasks as $t): ?>
              <tr>
                <td><a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $t['id'] ?>">#<?= $t['id'] ?></a></td>
                <td><?= htmlspecialchars(mb_substr($t['title'], 0, 60), ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="badge badge-draft"><?= htmlspecialchars(str_replace('_', ' ', $t['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><span class="badge badge-normal"><?= htmlspecialchars($t['priority'], ENT_QUOTES, 'UTF-8') ?></span></td>
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
