<?php
declare(strict_types=1);

namespace DevBridge\Services;

use DevBridge\Core\Database;
use DevBridge\Core\Logger;

/**
 * Fetches PR metadata, files, diff, and check runs from GitHub
 * and stores a reproducible review snapshot in dev_task_reviews.
 */
class PRDiffFetcher
{
    private GitHubService $github;

    public function __construct(GitHubService $github)
    {
        $this->github = $github;
    }

    public function fetchAndStore(int $taskId): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT t.*, r.github_owner, r.github_repo
             FROM dev_tasks t
             JOIN repositories r ON r.id = t.repository_id
             WHERE t.id = ?'
        );
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task || !$task['github_pr_number']) {
            throw new \RuntimeException("Task $taskId has no PR number.");
        }

        $owner  = $task['github_owner'];
        $repo   = $task['github_repo'];
        $prNum  = (int)$task['github_pr_number'];

        $prMeta    = $this->github->getPullRequest($owner, $repo, $prNum);
        $files     = $this->github->getPullRequestFiles($owner, $repo, $prNum);
        $diff      = $this->github->getPullRequestDiff($owner, $repo, $prNum);
        $headSha   = $prMeta['head']['sha'] ?? '';
        $checkRuns = $headSha ? $this->github->getCheckRuns($owner, $repo, $headSha) : [];

        $insert = $db->prepare(
            'INSERT INTO dev_task_reviews
               (task_id, pr_meta_json, pr_files_json, pr_diff, check_status_json, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $insert->execute([
            $taskId,
            json_encode($prMeta, JSON_UNESCAPED_UNICODE),
            json_encode($files, JSON_UNESCAPED_UNICODE),
            $diff,
            json_encode($checkRuns, JSON_UNESCAPED_UNICODE),
        ]);

        Logger::log('github_request', "PR snapshot stored for task $taskId, PR #$prNum", $taskId, $task['project_id']);

        return [
            'pr_meta'     => $prMeta,
            'files'       => $files,
            'diff'        => $diff,
            'check_runs'  => $checkRuns,
        ];
    }
}
