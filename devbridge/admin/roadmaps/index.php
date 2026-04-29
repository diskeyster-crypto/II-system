<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;
use DevBridge\AI\OpenRouterClient;
use DevBridge\Services\GitHubService;
use DevBridge\Core\Logger;

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

// Load projects for generate-v2 form
$projects = $db->query('SELECT id, name, global_rules, business_goal, tech_stack FROM projects ORDER BY name')->fetchAll();

$error         = '';
$success       = '';
$v2RoadmapMd   = '';
$v2RoadmapJson = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['proposal_id'] ?? 0);

    if ($action === 'approve_proposal') {
        $db->prepare(
            'UPDATE roadmap_change_proposals SET status = "approved", operator_decision = ?, decided_at = NOW() WHERE id = ?'
        )->execute([trim($_POST['operator_decision'] ?? ''), $id]);
        $success = t('roadmaps.approve') . ' OK';
    } elseif ($action === 'reject_proposal') {
        $db->prepare(
            'UPDATE roadmap_change_proposals SET status = "rejected", operator_decision = ?, decided_at = NOW() WHERE id = ?'
        )->execute([trim($_POST['operator_decision'] ?? ''), $id]);
        $success = t('roadmaps.reject') . ' OK';
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
                    $success = t('roadmaps.apply') . ' OK';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'generate_v2') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $project   = null;
        foreach ($projects as $p) {
            if ($p['id'] === $projectId) { $project = $p; break; }
        }

        if (!$project) {
            $error = t('roadmaps.no_project_selected');
        } else {
            try {
                // Gather existing tasks for context
                $taskRows = $db->prepare(
                    'SELECT title, status, risk_level FROM dev_tasks WHERE project_id = ? ORDER BY created_at DESC LIMIT 30'
                );
                $taskRows->execute([$projectId]);
                $taskList = implode("\n", array_map(
                    fn($t) => '- ' . $t['title'] . ' [' . $t['status'] . '] risk:' . $t['risk_level'],
                    $taskRows->fetchAll()
                ));

                $systemMsg = <<<SYS
You are an expert software architect. Your job is to generate a DevBridge v2 roadmap.

Project: {$project['name']}
Tech Stack: {$project['tech_stack']}
Business Goal: {$project['business_goal']}
Project Rules:
{$project['global_rules']}

Known tasks:
$taskList

You must return valid JSON with exactly two keys:
- "roadmap_md": full content for ROADMAP.md (Markdown)
- "roadmap_json": object for .devbridge/roadmap.json

The roadmap must cover the DevBridge v2 self-upgrade workflow:
1. Project + Repository connection
2. Task discussion with AI planner
3. Final spec generation
4. GitHub Issue creation
5. PR linking (manual)
6. AI code review
7. PR comment posting
8. Operator decisions
9. Ready for manual merge

Return ONLY valid JSON, no explanation, no markdown wrapper.
SYS;

                $ai     = OpenRouterClient::forPlanner();
                $result = $ai->chatJsonWithRepair([
                    ['role' => 'system', 'content' => $systemMsg],
                    ['role' => 'user',   'content' => 'Generate the DevBridge v2 roadmap now.'],
                ]);

                $v2RoadmapMd   = $result['roadmap_md']   ?? '';
                $v2RoadmapJson = is_array($result['roadmap_json'] ?? null)
                    ? json_encode($result['roadmap_json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    : ($result['roadmap_json'] ?? '');

                $success = t('roadmaps.v2_generated');
                Logger::log('ai_request', 'Generated v2 roadmap for project ' . $projectId);
            } catch (\Throwable $e) {
                $error = t('roadmaps.v2_failed') . ': ' . $e->getMessage();
                Logger::log('error', 'v2 roadmap generation failed: ' . $e->getMessage());
            }
        }
    }

    // Reload proposals
    $proposals = $db->query('SELECT rcp.*, p.name AS project_name, r.github_repo, t.title AS task_title FROM roadmap_change_proposals rcp JOIN projects p ON p.id = rcp.project_id LEFT JOIN repositories r ON r.id = rcp.repository_id LEFT JOIN dev_tasks t ON t.id = rcp.related_task_id ORDER BY rcp.created_at DESC')->fetchAll();
}

$pageTitle = t('roadmaps.title');
$activeNav = 'roadmaps';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<!-- Generate v2 Roadmap -->
<div class="card">
  <div class="card-title">🗺 <?= e(t('roadmaps.btn_generate_v2')) ?></div>
  <p class="text-muted text-sm" style="margin-bottom:12px"><?= e(t('roadmaps.v2_note')) ?></p>
  <form method="post" class="flex gap-2 items-center flex-wrap">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="generate_v2">
    <select name="project_id" style="min-width:200px">
      <option value="">— <?= e(t('roadmaps.select_project')) ?> —</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= $p['id'] ?>" <?= (isset($_POST['project_id']) && (int)$_POST['project_id'] === $p['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary"><?= e(t('roadmaps.btn_generate_v2')) ?></button>
  </form>

  <?php if ($v2RoadmapMd || $v2RoadmapJson): ?>
    <hr class="divider">
    <div class="card-title"><?= e(t('roadmaps.v2_section')) ?></div>

    <?php if ($v2RoadmapMd): ?>
      <div class="form-group">
        <label><strong><?= e(t('roadmaps.v2_roadmap_md')) ?></strong></label>
        <textarea style="min-height:300px;font-family:monospace;font-size:0.82rem" readonly><?= htmlspecialchars($v2RoadmapMd, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
    <?php endif; ?>

    <?php if ($v2RoadmapJson): ?>
      <div class="form-group">
        <label><strong><?= e(t('roadmaps.v2_roadmap_json')) ?></strong></label>
        <textarea style="min-height:200px;font-family:monospace;font-size:0.82rem" readonly><?= htmlspecialchars($v2RoadmapJson, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-title"><?= e(t('roadmaps.change_proposals')) ?></div>
  <?php if (empty($proposals)): ?>
    <p class="text-muted text-sm"><?= e(t('roadmaps.no_proposals_text')) ?></p>
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
      <?php if ($p['task_title']): ?><p class="text-sm text-muted mb-2"><?= e(t('roadmaps.task')) ?>: <?= htmlspecialchars($p['task_title'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

      <?php if ($p['status'] === 'pending'): ?>
      <form method="post" class="flex gap-2 items-center mt-2">
        <?= Csrf::field() ?>
        <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
        <input type="text" name="operator_decision" placeholder="<?= e(t('roadmaps.optional_note')) ?>" style="flex:1">
        <button type="submit" name="action" value="approve_proposal" class="btn btn-success btn-sm"><?= e(t('roadmaps.proposal_approve')) ?></button>
        <button type="submit" name="action" value="reject_proposal" class="btn btn-danger btn-sm"><?= e(t('roadmaps.proposal_reject')) ?></button>
      </form>
      <?php elseif ($p['status'] === 'approved' && $p['repository_id']): ?>
      <form method="post" class="mt-2" style="display:inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
        <button type="submit" name="action" value="apply_proposal" class="btn btn-primary btn-sm"
                onclick="return confirm('Apply this roadmap change to GitHub?')"><?= e(t('roadmaps.proposal_apply')) ?></button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-title"><?= e(t('roadmaps.roadmap_versions')) ?></div>
  <?php if (empty($versions)): ?>
    <p class="text-muted text-sm"><?= e(t('roadmaps.no_version')) ?></p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th><?= e(t('roadmaps.version_project')) ?></th>
            <th><?= e(t('roadmaps.version_branch')) ?></th>
            <th><?= e(t('roadmaps.versions')) ?></th>
            <th><?= e(t('roadmaps.version_created')) ?></th>
          </tr>
        </thead>
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

