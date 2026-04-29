<?php
declare(strict_types=1);

namespace DevBridge\Services;

use DevBridge\Core\Database;
use DevBridge\Core\Logger;
use DevBridge\Core\Settings;

class IssueCreator
{
    private GitHubService  $github;
    private ConflictDetector $conflicts;

    public function __construct(GitHubService $github, ConflictDetector $conflicts)
    {
        $this->github    = $github;
        $this->conflicts = $conflicts;
    }

    /**
     * Create a GitHub issue for a dev task.
     * Pre-checks dependencies, parallel limits, and conflicts.
     *
     * @throws \RuntimeException on blocking conditions
     */
    public function create(int $taskId, bool $overrideConflict = false): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT t.*,
                    p.name AS project_name, p.global_rules, p.tech_stack, p.code AS project_code,
                    r.github_owner, r.github_repo, r.default_branch, r.max_parallel_tasks AS repo_max
             FROM dev_tasks t
             JOIN projects p ON p.id = t.project_id
             JOIN repositories r ON r.id = t.repository_id
             WHERE t.id = ?'
        );
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) {
            throw new \RuntimeException(t('tasks.not_found'));
        }

        // Verify prerequisites
        $githubToken = Settings::getDecrypted('github_token');
        if (!$githubToken) {
            throw new \RuntimeException(t('github.not_configured'));
        }

        if (empty($task['final_task_spec'])) {
            throw new \RuntimeException(t('tasks.no_spec'));
        }

        if ($task['status'] !== 'ready_to_run') {
            throw new \RuntimeException(t('tasks.not_ready') . ' (status: ' . $task['status'] . ')');
        }

        // Check dependencies
        $depends = json_decode($task['depends_on_json'] ?? '[]', true) ?: [];
        if (!empty($depends)) {
            $placeholders = implode(',', array_fill(0, count($depends), '?'));
            $depStmt = $db->prepare(
                "SELECT id, title, status FROM dev_tasks WHERE id IN ($placeholders)"
            );
            $depStmt->execute($depends);
            $depTasks = $depStmt->fetchAll();
            foreach ($depTasks as $dep) {
                if (!in_array($dep['status'], ['merged', 'cancelled'])) {
                    throw new \RuntimeException(
                        t('tasks.dependency_not_merged', ['id' => $dep['id'], 'title' => $dep['title'], 'status' => $dep['status']])
                    );
                }
            }
        }

        // Conflict detection
        $allowed   = json_decode($task['allowed_files_json'] ?? '[]', true) ?: [];
        $forbidden = json_decode($task['forbidden_files_json'] ?? '[]', true) ?: [];
        $conflict  = $this->conflicts->detect($taskId, $task['project_id'], $task['repository_id'], $allowed, $forbidden);

        if ($conflict['level'] === ConflictDetector::BLOCKING && !$overrideConflict) {
            $db->prepare('UPDATE dev_tasks SET conflict_status = ?, conflict_details_json = ? WHERE id = ?')
               ->execute([ConflictDetector::BLOCKING, json_encode($conflict['details']), $taskId]);
            throw new \RuntimeException(
                t('tasks.blocking_conflict') . ' ' . json_encode($conflict['details'])
            );
        }

        // Generate branch name
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($task['title']));
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 40);
        $branchName = 'devbridge/task-' . $taskId . '-' . $slug;

        // Build issue body using TaskSpecRenderer
        $renderer = new TaskSpecRenderer();
        $specJson  = null;
        if (!empty($task['spec_json'])) {
            $specJson = json_decode($task['spec_json'], true);
        }
        $body = $renderer->render($task, $branchName, $specJson ?: null);

        // Create issue on GitHub
        $owner = $task['github_owner'];
        $repo  = $task['github_repo'];
        $issue = $this->github->createIssue($owner, $repo, $task['title'], $body, ['devbridge', 'ai-task']);

        // Update task record
        $db->prepare(
            'UPDATE dev_tasks
             SET branch_name = ?, github_issue_number = ?, github_issue_url = ?,
                 conflict_status = ?, conflict_details_json = ?, status = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([
            $branchName,
            $issue['number'],
            $issue['html_url'],
            $conflict['level'],
            json_encode($conflict['details']),
            'issue_created',
            $taskId,
        ]);

        Logger::log(
            'github_request',
            t('github.issue_created') . " #{$issue['number']} for task $taskId: {$issue['html_url']}",
            $taskId,
            $task['project_id']
        );

        return $issue;
    }
}
