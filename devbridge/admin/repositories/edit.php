<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;

Auth::requireLogin();

$db  = Database::getInstance();
$id  = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT r.*, p.name AS project_name FROM repositories r JOIN projects p ON p.id = r.project_id WHERE r.id = ?');
$stmt->execute([$id]);
$repo = $stmt->fetch();
if (!$repo) { http_response_code(404); die('Not found.'); }

$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $owner   = trim($_POST['github_owner'] ?? '');
    $name    = trim($_POST['github_repo'] ?? '');
    $branch  = trim($_POST['default_branch'] ?? 'main');
    $protB   = trim($_POST['protected_branch'] ?? '');
    $maxPar  = max(1, (int)($_POST['max_parallel_tasks'] ?? 3));
    $status  = $_POST['status'] ?? 'active';

    if (!$owner || !$name) {
        $error = 'Owner and repo name are required.';
    } else {
        try {
            $db->prepare(
                'UPDATE repositories SET github_owner=?, github_repo=?, default_branch=?,
                 protected_branch=?, max_parallel_tasks=?, status=? WHERE id=?'
            )->execute([$owner, $name, $branch, $protB, $maxPar, $status, $id]);
            $success = true;
            $repo = array_merge($repo, compact('owner', 'name', 'branch', 'maxPar', 'status'));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$projects = $db->query('SELECT id, name FROM projects WHERE status = "active" ORDER BY name')->fetchAll();

$pageTitle = 'Edit Repository';
$activeNav = 'repositories';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($success): ?><div class="alert alert-success">Saved.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<form method="post" class="card" style="max-width:640px">
  <?= Csrf::field() ?>
  <div class="form-group">
    <label>GitHub Owner</label>
    <input type="text" name="github_owner" required value="<?= htmlspecialchars($repo['github_owner'], ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Repository Name</label>
    <input type="text" name="github_repo" required value="<?= htmlspecialchars($repo['github_repo'], ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Default Branch</label>
    <input type="text" name="default_branch" value="<?= htmlspecialchars($repo['default_branch'], ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Protected Branch</label>
    <input type="text" name="protected_branch" value="<?= htmlspecialchars($repo['protected_branch'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="form-group">
    <label>Max Parallel Tasks</label>
    <input type="number" name="max_parallel_tasks" min="1" max="20" value="<?= (int)$repo['max_parallel_tasks'] ?>">
  </div>
  <div class="form-group">
    <label>Status</label>
    <select name="status">
      <?php foreach (['active', 'archived', 'error'] as $s): ?>
        <option value="<?= $s ?>" <?= $repo['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="flex gap-2">
    <button type="submit" class="btn btn-primary">Save</button>
    <a href="<?= BASE_URL ?>/admin/repositories/view.php?id=<?= $id ?>" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
