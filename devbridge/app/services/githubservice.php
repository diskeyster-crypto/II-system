<?php
declare(strict_types=1);

namespace DevBridge\Services;

use DevBridge\Core\Logger;
use DevBridge\Core\Settings;

class GitHubService
{
    private string $token;
    private string $defaultOwner;
    private const API_BASE = 'https://api.github.com';

    public function __construct(string $token = '', string $defaultOwner = '')
    {
        $this->token        = $token        ?: Settings::getDecrypted('github_token');
        $this->defaultOwner = $defaultOwner ?: Settings::get('github_default_owner');
    }

    // -----------------------------------------------------------------------
    // Connection test
    // -----------------------------------------------------------------------

    public function testConnection(): array
    {
        $response = $this->request('GET', '/user');
        return $response;
    }

    // -----------------------------------------------------------------------
    // Repositories
    // -----------------------------------------------------------------------

    public function createRepository(string $owner, string $name, string $description = '', bool $private = true): array
    {
        // Determine if owner is org or user
        $endpoint = '/user/repos';
        try {
            $org = $this->request('GET', '/orgs/' . urlencode($owner));
            if (isset($org['login'])) {
                $endpoint = '/orgs/' . urlencode($owner) . '/repos';
            }
        } catch (\Throwable) {
            // assume user
        }
        return $this->request('POST', $endpoint, [
            'name'        => $name,
            'description' => $description,
            'private'     => $private,
            'auto_init'   => false,
        ]);
    }

    // -----------------------------------------------------------------------
    // File management
    // -----------------------------------------------------------------------

    public function createOrUpdateFile(
        string $owner,
        string $repo,
        string $path,
        string $content,
        string $message,
        string $branch = 'main',
        string $sha = ''
    ): array {
        $body = [
            'message' => $message,
            'content' => base64_encode($content),
            'branch'  => $branch,
        ];
        if ($sha !== '') {
            $body['sha'] = $sha;
        }
        return $this->request('PUT', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/contents/' . ltrim($path, '/'), $body);
    }

    public function getFileContents(string $owner, string $repo, string $path, string $branch = 'main'): array
    {
        return $this->request('GET', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/contents/' . ltrim($path, '/') . '?ref=' . urlencode($branch));
    }

    // -----------------------------------------------------------------------
    // Issues
    // -----------------------------------------------------------------------

    public function createIssue(string $owner, string $repo, string $title, string $body, array $labels = []): array
    {
        return $this->request('POST', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/issues', [
            'title'  => $title,
            'body'   => $body,
            'labels' => $labels,
        ]);
    }

    public function getIssue(string $owner, string $repo, int $number): array
    {
        return $this->request('GET', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/issues/' . $number);
    }

    // -----------------------------------------------------------------------
    // Pull Requests
    // -----------------------------------------------------------------------

    public function getPullRequest(string $owner, string $repo, int $number): array
    {
        return $this->request('GET', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/pulls/' . $number);
    }

    public function getPullRequestFiles(string $owner, string $repo, int $number): array
    {
        return $this->request('GET', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/pulls/' . $number . '/files');
    }

    public function getPullRequestDiff(string $owner, string $repo, int $number): string
    {
        return $this->rawRequest('GET', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/pulls/' . $number, 'application/vnd.github.v3.diff');
    }

    public function createPullRequestComment(string $owner, string $repo, int $number, string $body): array
    {
        return $this->request('POST', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/issues/' . $number . '/comments', [
            'body' => $body,
        ]);
    }

    // -----------------------------------------------------------------------
    // Webhooks
    // -----------------------------------------------------------------------

    public function createWebhook(string $owner, string $repo, string $url, string $secret): array
    {
        return $this->request('POST', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/hooks', [
            'name'   => 'web',
            'active' => true,
            'events' => ['pull_request', 'pull_request_review', 'check_run', 'check_suite', 'workflow_run', 'issue_comment'],
            'config' => [
                'url'          => $url,
                'content_type' => 'json',
                'insecure_ssl' => '0',
                'secret'       => $secret,
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // Check runs
    // -----------------------------------------------------------------------

    public function getCheckRuns(string $owner, string $repo, string $ref): array
    {
        return $this->request('GET', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/commits/' . urlencode($ref) . '/check-runs');
    }

    // -----------------------------------------------------------------------
    // Labels
    // -----------------------------------------------------------------------

    public function createLabels(string $owner, string $repo, array $labels): array
    {
        $results = [];
        foreach ($labels as $label) {
            try {
                $results[] = $this->request('POST', '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/labels', $label);
            } catch (\Throwable $e) {
                // Label may already exist (422) — not a fatal error
                $results[] = ['error' => $e->getMessage(), 'label' => $label['name']];
            }
        }
        return $results;
    }

    // -----------------------------------------------------------------------
    // Internal HTTP helpers
    // -----------------------------------------------------------------------

    private function request(string $method, string $endpoint, array $body = []): array
    {
        $url = self::API_BASE . $endpoint;
        $ch  = curl_init($url);
        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: DevBridge/1.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            $json = json_encode($body, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Logger::log('error', 'GitHub API curl error: ' . $error . ' [' . $method . ' ' . $endpoint . ']');
            throw new \RuntimeException('GitHub request failed: ' . $error);
        }

        $data = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            $msg = $data['message'] ?? $response;
            Logger::log('error', 'GitHub API error ' . $httpCode . ': ' . $msg . ' [' . $method . ' ' . $endpoint . ']');
            throw new \RuntimeException('GitHub API error ' . $httpCode . ': ' . $msg);
        }

        Logger::log('github_request', $method . ' ' . $endpoint . ' → ' . $httpCode);
        return $data;
    }

    private function rawRequest(string $method, string $endpoint, string $accept): string
    {
        $url = self::API_BASE . $endpoint;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Accept: ' . $accept,
                'Authorization: Bearer ' . $this->token,
                'User-Agent: DevBridge/1.0',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        if ($error) {
            throw new \RuntimeException('GitHub raw request failed: ' . $error);
        }
        if ($httpCode >= 400) {
            throw new \RuntimeException('GitHub raw request returned HTTP ' . $httpCode);
        }
        return is_string($response) ? $response : '';
    }
}
