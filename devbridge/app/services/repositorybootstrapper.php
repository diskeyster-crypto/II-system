<?php
declare(strict_types=1);

namespace DevBridge\Services;

use DevBridge\Core\Database;
use DevBridge\Core\Logger;
use DevBridge\Core\Settings;

class RepositoryBootstrapper
{
    private GitHubService $github;

    public function __construct(GitHubService $github)
    {
        $this->github = $github;
    }

    /**
     * Bootstrap a repository: create required files, labels, and webhook.
     *
     * @param  int    $repositoryId
     * @param  bool   $overwrite  Overwrite existing files?
     * @return array  Results for each step
     */
    public function bootstrap(int $repositoryId, bool $overwrite = false): array
    {
        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT r.*, p.name AS project_name, p.code AS project_code,
                    p.description AS project_description, p.tech_stack,
                    p.global_rules, p.business_goal
             FROM repositories r
             JOIN projects p ON p.id = r.project_id
             WHERE r.id = ?'
        );
        $stmt->execute([$repositoryId]);
        $repo = $stmt->fetch();
        if (!$repo) {
            throw new \RuntimeException("Repository $repositoryId not found.");
        }

        $owner  = $repo['github_owner'];
        $name   = $repo['github_repo'];
        $branch = $repo['default_branch'] ?: 'main';
        $results = [];

        // 1. Create repository if it doesn't exist yet
        if ($repo['status'] === 'pending_creation') {
            try {
                $created = $this->github->createRepository($owner, $name, $repo['project_description'] ?? '', true);
                $db->prepare('UPDATE repositories SET status = ? WHERE id = ?')->execute(['active', $repositoryId]);
                $results['create_repo'] = ['ok' => true, 'url' => $created['html_url'] ?? ''];
                Logger::log('github_request', "Created repository $owner/$name", null, $repo['project_id']);
            } catch (\Throwable $e) {
                $results['create_repo'] = ['ok' => false, 'error' => $e->getMessage()];
                Logger::log('error', "Failed to create repository: " . $e->getMessage(), null, $repo['project_id']);
                return $results;
            }
        }

        // 2. Create standard files
        $files = $this->buildFileContents($repo);
        foreach ($files as $path => $content) {
            $results['files'][$path] = $this->ensureFile($owner, $name, $path, $content, $branch, $overwrite);
        }

        // 3. Create default labels
        $results['labels'] = $this->github->createLabels($owner, $name, $this->defaultLabels());

        // 4. Install webhook
        $results['webhook'] = $this->installWebhook($repo, $owner, $name);

        // 5. Update status
        $db->prepare('UPDATE repositories SET status = ? WHERE id = ?')->execute(['active', $repositoryId]);

        Logger::log('github_request', "Bootstrap complete for $owner/$name", null, $repo['project_id']);
        return $results;
    }

    // -----------------------------------------------------------------------

    private function ensureFile(string $owner, string $repo, string $path, string $content, string $branch, bool $overwrite): array
    {
        $sha = '';
        try {
            $existing = $this->github->getFileContents($owner, $repo, $path, $branch);
            if (!$overwrite) {
                return ['ok' => true, 'skipped' => true, 'reason' => 'exists'];
            }
            $sha = $existing['sha'] ?? '';
        } catch (\Throwable) {
            // File doesn't exist – create it
        }

        try {
            $this->github->createOrUpdateFile(
                $owner, $repo, $path, $content,
                'chore: bootstrap ' . $path . ' via DevBridge',
                $branch, $sha
            );
            return ['ok' => true, 'created' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function installWebhook(array $repo, string $owner, string $name): array
    {
        $baseUrl = Settings::get('webhook_base_url');
        if (!$baseUrl) {
            return ['ok' => false, 'reason' => 'webhook_base_url not configured'];
        }
        $secret = $repo['github_webhook_secret'] ?? '';
        if (!$secret) {
            $secret = bin2hex(random_bytes(20));
            $db = Database::getInstance();
            $db->prepare('UPDATE repositories SET github_webhook_secret = ? WHERE id = ?')
               ->execute([$secret, $repo['id']]);
        }
        $url = rtrim($baseUrl, '/') . '/webhook/github.php';
        try {
            $result = $this->github->createWebhook($owner, $name, $url, $secret);
            return ['ok' => true, 'id' => $result['id'] ?? null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function buildFileContents(array $repo): array
    {
        $project     = $repo['project_name'];
        $code        = $repo['project_code'];
        $tech        = $repo['tech_stack'] ?? '';
        $desc        = $repo['project_description'] ?? '';
        $owner       = $repo['github_owner'];
        $repoName    = $repo['github_repo'];
        $rules       = $repo['global_rules'] ?? '';
        $goal        = $repo['business_goal'] ?? '';

        $roadmapJson = json_encode([
            'version'  => '1.0',
            'project'  => $code,
            'branches' => ['core', 'security', 'github'],
            'items'    => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $projectJson = json_encode([
            'project'    => $project,
            'code'       => $code,
            'tech_stack' => $tech,
            'goal'       => $goal,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $coreRoadmap = json_encode([
            'branch'      => 'core',
            'description' => 'Core application milestones',
            'items'       => [],
        ], JSON_PRETTY_PRINT);

        $securityRoadmap = json_encode([
            'branch'      => 'security',
            'description' => 'Security hardening milestones',
            'items'       => [],
        ], JSON_PRETTY_PRINT);

        $githubRoadmap = json_encode([
            'branch'      => 'github',
            'description' => 'GitHub integration milestones',
            'items'       => [],
        ], JSON_PRETTY_PRINT);

        return [
            'README.md'                         => "# $project\n\n$desc\n\n## Tech Stack\n$tech\n",
            'ROADMAP.md'                        => "# Roadmap – $project\n\n> Managed by DevBridge.\n\n## Core\n\n- [ ] Initial setup\n",
            'ARCHITECTURE.md'                   => "# Architecture – $project\n\n## Overview\n\n$desc\n\n## Tech Stack\n\n$tech\n",
            'CONTRIBUTING.md'                   => "# Contributing\n\nAll tasks are managed by DevBridge.\nDo not open PRs manually unless instructed.\n",
            '.gitignore'                        => ".env\n*.log\nvendor/\nnode_modules/\n",
            '.devbridge/project.json'           => $projectJson,
            '.devbridge/rules.md'               => "# DevBridge Rules for $project\n\n$rules\n",
            '.devbridge/roadmap.json'           => $roadmapJson,
            '.devbridge/roadmaps/core.json'     => $coreRoadmap,
            '.devbridge/roadmaps/security.json' => $securityRoadmap,
            '.devbridge/roadmaps/github.json'   => $githubRoadmap,
        ];
    }

    private function defaultLabels(): array
    {
        return [
            ['name' => 'devbridge',    'color' => '0075ca', 'description' => 'Managed by DevBridge'],
            ['name' => 'ai-task',      'color' => '7057ff', 'description' => 'AI-generated task'],
            ['name' => 'needs-review', 'color' => 'e4e669', 'description' => 'Needs GPT/operator review'],
            ['name' => 'approved',     'color' => '0e8a16', 'description' => 'Approved by GPT'],
            ['name' => 'changes-req',  'color' => 'd93f0b', 'description' => 'Changes requested by GPT'],
        ];
    }
}
