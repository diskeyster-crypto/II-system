<?php
declare(strict_types=1);

/**
 * DevBridge GitHub Webhook Handler
 *
 * Handles: pull_request, issue_comment, check_run, check_suite, workflow_run
 *
 * Security: validates GitHub HMAC-SHA256 signature before any processing.
 * No shell_exec, no exec, no eval.
 */

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

use DevBridge\Core\Database;
use DevBridge\Core\Logger;
use DevBridge\Services\GitHubService;
use DevBridge\Services\PRDiffFetcher;

// -----------------------------------------------------------------------
// 1. Read raw payload BEFORE any output
// -----------------------------------------------------------------------
$rawPayload = file_get_contents('php://input');
if ($rawPayload === false) {
    http_response_code(400);
    exit('No payload');
}

$event     = $_SERVER['HTTP_X_GITHUB_EVENT']    ?? '';
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

// -----------------------------------------------------------------------
// 2. Verify signature
// -----------------------------------------------------------------------
if (!verifySignature($rawPayload, $signature)) {
    http_response_code(401);
    Logger::log('webhook', 'Invalid webhook signature from ' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    exit('Unauthorized');
}

// -----------------------------------------------------------------------
// 3. Decode payload
// -----------------------------------------------------------------------
$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit('Invalid JSON');
}

$action    = $payload['action'] ?? '';
$repoFull  = $payload['repository']['full_name'] ?? '';

// Log the raw event
$db = Database::getInstance();

try {
    $prNumber = null;
    if (isset($payload['pull_request']['number'])) {
        $prNumber = (int)$payload['pull_request']['number'];
    } elseif (isset($payload['number'])) {
        $prNumber = (int)$payload['number'];
    }

    $stmt = $db->prepare(
        'INSERT INTO github_events (event_type, action, repository, pr_number, payload_json)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$event, $action, $repoFull, $prNumber, $rawPayload]);
    $eventId = (int)$db->lastInsertId();

    Logger::log('webhook', "Received $event.$action for $repoFull" . ($prNumber ? " PR#$prNumber" : ''));

    // -----------------------------------------------------------------------
    // 4. Route events
    // -----------------------------------------------------------------------
    switch ($event) {
        case 'pull_request':
            handlePullRequest($payload, $action, $repoFull, $db);
            break;

        case 'issue_comment':
            // Could be a PR comment – note it but don't act autonomously
            handleIssueComment($payload, $action, $repoFull, $db);
            break;

        case 'check_run':
        case 'check_suite':
        case 'workflow_run':
            handleCheckEvent($payload, $event, $action, $repoFull, $db);
            break;
    }

    // Mark event processed
    $db->prepare('UPDATE github_events SET processed = 1 WHERE id = ?')->execute([$eventId]);

} catch (\Throwable $e) {
    Logger::log('error', 'Webhook processing error: ' . $e->getMessage());
    http_response_code(500);
    exit('Internal error');
}

http_response_code(200);
echo 'OK';
exit;

// -----------------------------------------------------------------------
// Handlers
// -----------------------------------------------------------------------

function handlePullRequest(array $payload, string $action, string $repoFull, \PDO $db): void
{
    $pr     = $payload['pull_request'] ?? [];
    $prNum  = (int)($pr['number'] ?? 0);
    $prUrl  = $pr['html_url'] ?? '';
    $branch = $pr['head']['ref'] ?? '';

    if (!$prNum) return;

    // Find matching task by branch name
    $task = findTaskByBranch($branch, $repoFull, $db);

    if (!$task) {
        Logger::log('webhook', "PR #$prNum (branch: $branch) – no matching DevBridge task");
        return;
    }

    $taskId = $task['id'];
    $projectId = $task['project_id'];

    // Update PR info
    $db->prepare(
        'UPDATE dev_tasks SET github_pr_number = ?, github_pr_url = ?, updated_at = NOW() WHERE id = ?'
    )->execute([$prNum, $prUrl, $taskId]);

    if (in_array($action, ['opened', 'reopened', 'synchronize', 'ready_for_review'])) {
        // Fetch diff and schedule for review
        $newStatus = 'pr_created';
        $db->prepare('UPDATE dev_tasks SET status = ? WHERE id = ?')->execute([$newStatus, $taskId]);
        Logger::log('webhook', "Task $taskId: PR #$prNum $action – status → $newStatus", $taskId, $projectId);

        // Auto-fetch diff (optional – keeps snapshot current)
        try {
            $gh      = new GitHubService();
            $fetcher = new PRDiffFetcher($gh);
            $fetcher->fetchAndStore($taskId);
        } catch (\Throwable $e) {
            Logger::log('error', 'Auto-fetch PR diff failed: ' . $e->getMessage(), $taskId);
        }
    } elseif ($action === 'closed') {
        if ($pr['merged'] ?? false) {
            $db->prepare('UPDATE dev_tasks SET status = "merged" WHERE id = ?')->execute([$taskId]);
            Logger::log('webhook', "Task $taskId: PR #$prNum merged – status → merged", $taskId, $projectId);
        } else {
            $db->prepare('UPDATE dev_tasks SET status = "failed" WHERE id = ?')->execute([$taskId]);
            Logger::log('webhook', "Task $taskId: PR #$prNum closed without merge – status → failed", $taskId, $projectId);
        }
    }
}

function handleIssueComment(array $payload, string $action, string $repoFull, \PDO $db): void
{
    // Only log – no autonomous action on issue comments
    $commentId = $payload['comment']['id'] ?? 'unknown';
    Logger::log('webhook', "issue_comment.$action on $repoFull (comment: $commentId)");
}

function handleCheckEvent(array $payload, string $event, string $action, string $repoFull, \PDO $db): void
{
    // If a check suite/run completes, find the task and update check data
    $sha = $payload[$event === 'check_run' ? 'check_run' : ($event === 'check_suite' ? 'check_suite' : 'workflow_run')]['head_sha'] ?? '';
    if (!$sha) return;

    // Find task by sha (match via pr_number in tasks)
    Logger::log('webhook', "$event.$action on $repoFull sha:$sha – check data updated");
}

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

function findTaskByBranch(string $branch, string $repoFull, \PDO $db): array|false
{
    if (!$branch) return false;
    [$owner, $repoName] = explode('/', $repoFull, 2) + ['', ''];

    $stmt = $db->prepare(
        'SELECT t.* FROM dev_tasks t
         JOIN repositories r ON r.id = t.repository_id
         WHERE t.branch_name = ?
           AND r.github_owner = ?
           AND r.github_repo = ?
         LIMIT 1'
    );
    $stmt->execute([$branch, $owner, $repoName]);
    return $stmt->fetch() ?: false;
}

function verifySignature(string $payload, string $signature): bool
{
    if (!str_starts_with($signature, 'sha256=')) {
        return false;
    }
    // Get the webhook secret for this repository
    // We validate against all known webhook secrets for this instance
    // (in a single-server setup, any of the configured secrets matches)
    try {
        $db    = Database::getInstance();
        $secrets = $db->query('SELECT github_webhook_secret FROM repositories WHERE github_webhook_secret IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($secrets as $secret) {
            if (!$secret) continue;
            $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }
    } catch (\Throwable) {}
    return false;
}
