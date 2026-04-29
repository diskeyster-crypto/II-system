<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;
use DevBridge\Services\GitHubService;

Auth::requireLogin();

$db = Database::getInstance();

// Load roadmap change proposals with task info
$proposals = $db->query(
    'SELECT rcp.*,
            p.name AS project_name,
            r.github_repo,
            t.title AS task_title
     FROM roadmap_change_proposals rcp
     JOIN projects p ON p.id = rcp.project_id
     LEFT JOIN repositories r ON r.id = rcp.repository_id
     LEFT JOIN dev_tasks t ON t.id = rcp.related_task_id
     ORDER BY rcp.created_at DESC'
)->fetchAll();

// Load roadmap versions
$versions = $db->query(
    'SELECT rv.*,
            p.name AS project_name
     FROM roadmap_versions rv
     JOIN projects p ON p.id = rv.project_id
     ORDER BY rv.created_at DESC
     LIMIT 30'
)->fetchAll();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['proposal_id'] ?? 0);

    if ($action === 'approve_proposal') {
        $db->prepare(
            'UPDATE roadmap_change_proposals SET status = "approved", operator_decision = ?, decided_at = NOW() WHERE id = ?'
        )->execute([trim($_POST['operator_decision'] ?? ''), $id]);
        $success = 'Roadmap change approved.';
    } elseif ($action === 'reject_proposal') {
        $db->prepare(
            'UPDATE roadmap_change_proposals SET status = "rejected", operator_decision = ?, decided_at = NOW() WHERE id = ?'
        )->execute([trim($_POST['operator_decision'] ?? ''), $id]);
        $success = 'Roadmap change rejected.';
    } elseif ($action === 'apply_proposal') {
        // Fetch the proposal and apply it to GitHub
        $pStmt = $db->prepare('SELECT * FROM roadmap_change_proposals WHERE id = ?');
        $pStmt->execute([$id]);
        $proposal = $pStmt->fetch();
        if ($proposal) {
            try {
                $repoStmt = $db->prepare('SELECT * FROM repositories WHERE id = ?');
                $repoStmt->execute([$proposal['repository_id']]);
                $repo = $repoStmt->fetch();
                if ($repo) {
                    $gh = new GitHubService();
                    // Get current file SHA
                    try {
                        $existing = $gh->getFileContents($repo['github_owner'], $repo['github_repo'], 'ROADMAP.md', $repo['default_branch']);
                        $sha = $existing['sha'] ?? '';
                    } catch (\Throwable) { $sha = ''; }

                    $gh->createOrUpdateFile(
                        $repo['github_owner'],
                        $repo['github_repo'],
                        'ROADMAP.md',
                        $proposal['proposed_md_diff'],
                        'docs: apply roadmap change #' . $id . ' via DevBridge',
                        $repo['default_branch'],
                        $sha
                    );
                    $db->prepare(
                        'UPDATE roadmap_change_proposals SET status = "applied", decided_at = NOW() WHERE id = ?'
                    )->execute([$id]);
                    $success = 'Roadmap applied to GitHub.';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
    // Reload
    $proposals = $db->query('SELECT rcp.*, p.name AS project_name, r.github_repo, t.title AS task_title FROM roadmap_change_proposals rcp JOIN projects p ON p.id = rcp.project_id LEFT JOIN repositories r ON r.id = rcp.repository_id LEFT JOIN dev_tasks t ON t.id = rcp.related_task_id ORDER BY rcp.created_at DESC')->fetchAll();
}

$pageTitle = 'Roadmaps';
$activeNav = 'roadmaps';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<div class="card">
  <div class="card-title">Roadmap Change Proposals</div>
  <?php if (empty($proposals)): ?>
    <p class="text-muted text-sm">No roadmap change proposals.</p>
  <?php else: ?>
    <?php foreach ($proposals as $p): ?>
    <div style="padding:14px 0;border-bottom:1px solid var(--border)">
      <div class="flex justify-between items-center mb-2">
        <div>
          <span class="badge badge-<?= $p['status'] === 'pending' ? 'waiting' : ($p['status'] === 'approved' || $p['status'] === 'applied' ? 'approved' : 'failed') ?>">
            <?= htmlspecialchars($p['status'], ENT_QUOTES, 'UTF-8') ?>
          </span>
          <strong class="ml-2"><?= htmlspecialchars($p['project_name'], ENT_QUOTES, 'UTF-8') ?></strong>
          <?php if ($p['github_repo']): ?> / <?= htmlspecialchars($p['github_repo'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
          <span class="text-muted text-sm"> · <?= htmlspecialchars($p['branch_code'] ?? 'core', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <span class="text-muted text-sm"><?= htmlspecialchars(substr($p['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <?php if ($p['reason']): ?><p class="text-sm mb-2"><?= htmlspecialchars($p['reason'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
      <?php if ($p['task_title']): ?><p class="text-sm text-muted mb-2">Task: <?= htmlspecialchars($p['task_title'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

      <?php if ($p['status'] === 'pending'): ?>
      <form method="post" class="flex gap-2 items-center mt-2">
        <?= Csrf::field() ?>
        <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
        <input type="text" name="operator_decision" placeholder="Optional note..." style="flex:1">
        <button type="submit" name="action" value="approve_proposal" class="btn btn-success btn-sm">Approve</button>
        <button type="submit" name="action" value="reject_proposal" class="btn btn-danger btn-sm">Reject</button>
      </form>
      <?php elseif ($p['status'] === 'approved' && $p['repository_id']): ?>
      <form method="post" class="mt-2" style="display:inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
        <button type="submit" name="action" value="apply_proposal" class="btn btn-primary btn-sm"
                onclick="return confirm('Apply this roadmap change to GitHub?')">Apply to GitHub</button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-title">Roadmap Versions</div>
  <?php if (empty($versions)): ?>
    <p class="text-muted text-sm">No versions yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Project</th><th>Branch</th><th>Version</th><th>Created</th></tr></thead>
        <tbody>
          <?php foreach ($versions as $v): ?>
          <tr>
            <td><?= htmlspecialchars($v['project_name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($v['branch_code'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($v['version'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-muted text-sm"><?= htmlspecialchars(substr($v['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
