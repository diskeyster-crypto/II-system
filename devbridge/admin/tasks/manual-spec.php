<?php
declare(strict_types=1);

define('APP_ROOT', dirname(dirname(__DIR__)));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Auth;
use DevBridge\Core\Csrf;
use DevBridge\Core\Database;
use DevBridge\Core\Logger;
use DevBridge\Services\IssueCreator;
use DevBridge\Services\GitHubService;
use DevBridge\Services\ConflictDetector;

Auth::requireLogin();

$db     = Database::getInstance();
$taskId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare(
    'SELECT t.*,
            p.name AS project_name, p.global_rules, p.tech_stack, p.business_goal,
            r.github_owner, r.github_repo, r.default_branch
     FROM dev_tasks t
     JOIN projects p ON p.id = t.project_id
     JOIN repositories r ON r.id = t.repository_id
     WHERE t.id = ?'
);
$stmt->execute([$taskId]);
$task = $stmt->fetch();
if (!$task) { http_response_code(404); die('Task not found.'); }

$error   = '';
$success = '';

// -----------------------------------------------------------------------
// POST handler
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? 'save_spec';

    if ($action === 'save_spec') {
        $spec           = trim($_POST['final_task_spec'] ?? '');
        $acceptanceRaw  = trim($_POST['acceptance_criteria_raw'] ?? '');
        $allowedRaw     = trim($_POST['allowed_files_raw'] ?? '');
        $forbiddenRaw   = trim($_POST['forbidden_files_raw'] ?? '');
        $expectedRaw    = trim($_POST['expected_files_raw'] ?? '');
        $testPlan       = trim($_POST['test_plan_raw'] ?? '');
        $roadmapBranch  = trim($_POST['roadmap_branch'] ?? '');
        $roadmapCode    = trim($_POST['roadmap_item_code'] ?? '');
        $riskLevel      = $_POST['risk_level'] ?? $task['risk_level'];

        if (!$spec) {
            $error = t('tasks.spec_required');
        } else {
            // Parse line-delimited lists
            $acceptance  = parseLines($acceptanceRaw);
            $allowedFiles = parseLines($allowedRaw);
            $forbiddenFiles = parseLines($forbiddenRaw);
            $expectedFiles = parseLines($expectedRaw);

            // Build full spec markdown if no spec was provided manually
            // (spec is stored as-is; the renderer uses structured fields)
            $db->prepare(
                'UPDATE dev_tasks SET
                    final_task_spec        = ?,
                    acceptance_criteria_json = ?,
                    allowed_files_json     = ?,
                    forbidden_files_json   = ?,
                    expected_files_json    = ?,
                    roadmap_branch         = ?,
                    roadmap_item_code      = ?,
                    risk_level             = ?,
                    status                 = "ready_to_run",
                    updated_at             = NOW()
                 WHERE id = ?'
            )->execute([
                $spec,
                json_encode($acceptance, JSON_UNESCAPED_UNICODE),
                json_encode($allowedFiles, JSON_UNESCAPED_UNICODE),
                json_encode($forbiddenFiles, JSON_UNESCAPED_UNICODE),
                json_encode($expectedFiles, JSON_UNESCAPED_UNICODE),
                $roadmapBranch,
                $roadmapCode,
                in_array($riskLevel, ['low','medium','high','critical']) ? $riskLevel : 'medium',
                $taskId,
            ]);

            // If test_plan given, append it to the spec (it isn't a separate column; stored inline)
            // We store test_plan in the spec text itself if user provided it
            if ($testPlan) {
                $currentSpec = $spec;
                if (!str_contains($currentSpec, '## Test Plan')) {
                    $currentSpec .= "\n\n## Test Plan\n" . $testPlan;
                    $db->prepare('UPDATE dev_tasks SET final_task_spec = ? WHERE id = ?')
                       ->execute([$currentSpec, $taskId]);
                }
            }

            Logger::log('task', "Manual spec saved for task $taskId, status set to ready_to_run", $taskId, $task['project_id']);
            $success = t('tasks.spec_saved');

            // Reload
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
        }
    } elseif ($action === 'create_issue') {
        $override = isset($_POST['override_conflict']);
        try {
            $gh       = new GitHubService();
            $detector = new ConflictDetector();
            $creator  = new IssueCreator($gh, $detector);
            $issue    = $creator->create($taskId, $override);
            $success  = t('github.issue_created') . " #{$issue['number']}: {$issue['html_url']}";
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// -----------------------------------------------------------------------
// Helper: decode stored JSON array for display
// -----------------------------------------------------------------------
function decodeList(string|null $json): string
{
    $arr = json_decode($json ?? '[]', true) ?: [];
    return implode("\n", $arr);
}

function parseLines(string $raw): array
{
    $lines = preg_split('/\r?\n/', $raw);
    return array_values(array_filter(array_map('trim', $lines)));
}

$pageTitle = t('tasks.manual_spec_title') . ': ' . $task['title'];
$activeNav = 'tasks';
require APP_ROOT . '/views/layout.php';
?>

<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<div class="flex gap-2 mb-4 items-center flex-wrap">
  <a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $taskId ?>" class="btn btn-secondary btn-sm">← <?= e(t('tasks.details')) ?></a>
  <span class="badge badge-draft"><?= e(t('statuses.' . $task['status'])) ?></span>
  <span class="text-muted text-sm"><?= htmlspecialchars($task['github_owner'] . '/' . $task['github_repo'], ENT_QUOTES, 'UTF-8') ?></span>
</div>

<!-- Mode badge -->
<div class="alert" style="background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.4);color:var(--text);margin-bottom:16px">
  📋 <strong><?= e(t('tasks.mode_manual_spec')) ?></strong> — <?= e(t('tasks.mode_manual_spec_hint')) ?>
</div>

<form method="post">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="save_spec">

  <div class="flex gap-3" style="align-items:flex-start">
    <!-- Left: main spec -->
    <div style="flex:1.4">
      <div class="card">
        <div class="card-title">📋 <?= e(t('tasks.final_spec')) ?></div>
        <div class="form-group">
          <label><?= e(t('tasks.spec_paste_label')) ?></label>
          <textarea name="final_task_spec" style="min-height:320px;font-family:monospace;font-size:0.85rem"
                    placeholder="<?= e(t('tasks.spec_paste_placeholder')) ?>"><?= htmlspecialchars($task['final_task_spec'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
          <small class="text-muted"><?= e(t('tasks.spec_paste_hint')) ?></small>
        </div>
        <div class="form-group">
          <label><?= e(t('tasks.test_plan')) ?></label>
          <textarea name="test_plan_raw" style="min-height:120px"
                    placeholder="<?= e(t('tasks.test_plan_placeholder')) ?>"><?php
            // Extract test plan from existing spec if present
            if ($task['final_task_spec'] && preg_match('/## Test Plan\s*([\s\S]*?)(?:##|$)/i', $task['final_task_spec'], $m)) {
                echo htmlspecialchars(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }
          ?></textarea>
        </div>
      </div>
    </div>

    <!-- Right: structured fields -->
    <div style="flex:1">
      <div class="card">
        <div class="card-title"><?= e(t('tasks.structured_fields')) ?></div>

        <div class="form-group">
          <label><?= e(t('tasks.acceptance')) ?></label>
          <textarea name="acceptance_criteria_raw" style="min-height:100px"
                    placeholder="<?= e(t('tasks.one_per_line')) ?>"><?= htmlspecialchars(decodeList($task['acceptance_criteria_json']), ENT_QUOTES, 'UTF-8') ?></textarea>
          <small class="text-muted"><?= e(t('tasks.one_per_line')) ?></small>
        </div>

        <div class="form-group">
          <label><?= e(t('tasks.allowed_files')) ?></label>
          <textarea name="allowed_files_raw" style="min-height:80px"
                    placeholder="<?= e(t('tasks.one_per_line')) ?>"><?= htmlspecialchars(decodeList($task['allowed_files_json']), ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div class="form-group">
          <label><?= e(t('tasks.forbidden_files')) ?></label>
          <textarea name="forbidden_files_raw" style="min-height:80px"
                    placeholder="<?= e(t('tasks.one_per_line')) ?>"><?= htmlspecialchars(decodeList($task['forbidden_files_json']), ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div class="form-group">
          <label><?= e(t('tasks.expected_files')) ?></label>
          <textarea name="expected_files_raw" style="min-height:80px"
                    placeholder="<?= e(t('tasks.one_per_line')) ?>"><?= htmlspecialchars(decodeList($task['expected_files_json']), ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div class="flex gap-2">
          <div class="form-group" style="flex:1">
            <label><?= e(t('tasks.roadmap_branch_label')) ?></label>
            <input type="text" name="roadmap_branch"
                   value="<?= htmlspecialchars($task['roadmap_branch'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="e.g. v2-core">
          </div>
          <div class="form-group" style="flex:1">
            <label><?= e(t('tasks.roadmap_item_code_label')) ?></label>
            <input type="text" name="roadmap_item_code"
                   value="<?= htmlspecialchars($task['roadmap_item_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="e.g. V2-05">
          </div>
        </div>

        <div class="form-group">
          <label><?= e(t('tasks.risk_level')) ?></label>
          <select name="risk_level">
            <?php foreach (['low','medium','high','critical'] as $v): ?>
              <option value="<?= $v ?>" <?= ($task['risk_level'] === $v) ? 'selected' : '' ?>>
                <?= e(t('statuses.risk_' . $v)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Project context (read-only) -->
      <div class="card">
        <div class="card-title"><?= e(t('tasks.project_context')) ?></div>
        <table>
          <tr><td class="text-muted" style="width:90px"><?= e(t('tasks.project')) ?></td><td><?= htmlspecialchars($task['project_name'], ENT_QUOTES, 'UTF-8') ?></td></tr>
          <tr><td class="text-muted"><?= e(t('tasks.repository')) ?></td><td><?= htmlspecialchars($task['github_owner'] . '/' . $task['github_repo'], ENT_QUOTES, 'UTF-8') ?></td></tr>
          <?php if ($task['tech_stack']): ?>
          <tr><td class="text-muted"><?= e(t('settings.tech_stack')) ?></td><td><?= htmlspecialchars($task['tech_stack'], ENT_QUOTES, 'UTF-8') ?></td></tr>
          <?php endif; ?>
        </table>
        <?php if ($task['global_rules']): ?>
          <details class="mt-2">
            <summary class="text-muted text-sm" style="cursor:pointer"><?= e(t('tasks.project_rules')) ?></summary>
            <pre style="font-size:0.8rem;white-space:pre-wrap;margin-top:6px"><?= htmlspecialchars($task['global_rules'], ENT_QUOTES, 'UTF-8') ?></pre>
          </details>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="flex gap-2 mt-3">
    <button type="submit" class="btn btn-primary">💾 <?= e(t('tasks.btn_save_spec')) ?></button>
    <a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $taskId ?>" class="btn btn-secondary"><?= e(t('common.cancel')) ?></a>
  </div>
</form>

<!-- Create GitHub Issue (only when spec is saved and status is ready_to_run) -->
<?php if ($task['final_task_spec'] && in_array($task['status'], ['ready_to_run','clarifying'])): ?>
<div class="card" style="margin-top:20px;border:2px solid rgba(99,102,241,.4)">
  <div class="card-title">🚀 <?= e(t('tasks.btn_create_issue')) ?></div>
  <p class="text-muted text-sm mb-3"><?= e(t('tasks.create_issue_hint')) ?></p>

  <?php if ($task['conflict_status'] === 'blocking'): ?>
    <div class="alert alert-danger mb-3"><?= e(t('tasks.blocking_conflict')) ?></div>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="create_issue">
      <label class="flex gap-2 items-center mb-3">
        <input type="checkbox" name="override_conflict" value="1">
        <?= e(t('tasks.btn_override_conflict')) ?>
      </label>
      <button type="submit" class="btn btn-danger"
              onclick="return confirm(<?= htmlspecialchars(json_encode(t('tasks.override_confirm')), ENT_QUOTES, 'UTF-8') ?>)">
        <?= e(t('tasks.btn_create_issue')) ?>
      </button>
    </form>
  <?php elseif (!$task['github_issue_number']): ?>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="create_issue">
      <button type="submit" class="btn btn-success"
              onclick="return confirm(<?= htmlspecialchars(json_encode(t('tasks.create_issue_confirm')), ENT_QUOTES, 'UTF-8') ?>)">
        🐙 <?= e(t('tasks.btn_create_issue')) ?>
      </button>
    </form>
  <?php else: ?>
    <p>✅ <?= e(t('github.issue_created')) ?> <a href="<?= htmlspecialchars($task['github_issue_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank">#<?= $task['github_issue_number'] ?></a></p>
    <a href="<?= BASE_URL ?>/admin/tasks/view.php?id=<?= $taskId ?>" class="btn btn-primary btn-sm"><?= e(t('tasks.details')) ?> →</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require APP_ROOT . '/views/layout_footer.php'; ?>
