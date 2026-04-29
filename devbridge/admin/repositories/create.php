<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;
use DevBridge\Services\GitHubService;

Auth::requireLogin();

$db       = Database::getInstance();
$error    = '';
$success  = false;
$testResult = null;

// Pre-fill project if provided
$preProjectId = (int)($_GET['project_id'] ?? 0);
$projects = $db->query('SELECT id, name FROM projects WHERE status = "active" ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? 'save';

    $projectId     = (int)($_POST['project_id'] ?? 0);
    $owner         = trim($_POST['github_owner'] ?? '');
    $repo          = trim($_POST['github_repo'] ?? '');
    $branch        = trim($_POST['default_branch'] ?? 'main');
    $protectedB    = trim($_POST['protected_branch'] ?? '');
    $repoType      = $_POST['repository_type'] ?? 'existing';
    $maxPar        = max(1, (int)($_POST['max_parallel_tasks'] ?? 3));

    if ($action === 'test') {
        try {
            $gh = new GitHubService();
            $info = $gh->getPullRequest($owner, $repo, 1); // just test connection via repo access
            $testResult = ['ok' => true, 'msg' => 'Connection successful.'];
        } catch (\Throwable $e) {
            // Try a lighter test
            try {
                $gh2 = new GitHubService();
                $info = $gh2->getIssue($owner, $repo, 1);
                $testResult = ['ok' => true, 'msg' => 'Connection successful.'];
            } catch (\Throwable $e2) {
                $testResult = ['ok' => false, 'msg' => $e2->getMessage()];
            }
        }
    } elseif ($action === 'save') {
        if (!$projectId) $error = 'Project is required.';
        elseif (!$owner)  $error = 'GitHub owner is required.';
        elseif (!$repo)   $error = 'Repository name is required.';

        if (!$error) {
            $webhookSecret = bin2hex(random_bytes(20));
            $status = $repoType === 'new' ? 'pending_creation' : 'active';
            try {
                $db->prepare(
                    'INSERT INTO repositories
                     (project_id, github_owner, github_repo, default_branch, protected_branch,
                      repository_type, status, max_parallel_tasks, github_webhook_secret)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$projectId, $owner, $repo, $branch, $protectedB, $repoType, $status, $maxPar, $webhookSecret]);
                $newId = $db->lastInsertId();
                header('Location: ' . BASE_URL . '/admin/repositories/view.php?id=' . $newId . '&created=1');
                exit;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Connect Repository';
$activeNav = 'repositories';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($testResult): ?>
  <div class="alert <?= $testResult['ok'] ? 'alert-success' : 'alert-danger' ?>"><?= htmlspecialchars($testResult['msg'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post" class="card" style="max-width:640px">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="save">

  <div class="form-group">
    <label>Project *</label>
    <select name="project_id" required>
      <option value="">— select project —</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= $p['id'] ?>" <?= ($p['id'] == ($preProjectId ?: ($_POST['project_id'] ?? 0))) ? 'selected' : '' ?>>
          <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>GitHub Owner (user or org) *</label>
    <input type="text" name="github_owner" required value="<?= htmlspecialchars($_POST['github_owner'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Repository Name *</label>
    <input type="text" name="github_repo" required value="<?= htmlspecialchars($_POST['github_repo'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Default Branch</label>
    <input type="text" name="default_branch" value="<?= htmlspecialchars($_POST['default_branch'] ?? 'main', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Protected Branch (optional)</label>
    <input type="text" name="protected_branch" value="<?= htmlspecialchars($_POST['protected_branch'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Repository Type</label>
    <select name="repository_type">
      <option value="existing">Connect existing</option>
      <option value="new">Create new on GitHub</option>
    </select>
  </div>
  <div class="form-group">
    <label>Max Parallel Tasks</label>
    <input type="number" name="max_parallel_tasks" min="1" max="20" value="<?= (int)($_POST['max_parallel_tasks'] ?? 3) ?>">
  </div>

  <div class="flex gap-2">
    <button type="submit" class="btn btn-primary">Save Repository</button>
    <button type="submit" name="action" value="test" class="btn btn-secondary">Test Connection</button>
    <a href="<?= BASE_URL ?>/admin/repositories/" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
