<?php
declare(strict_types=1);

namespace DevBridge\AI;

use DevBridge\Core\Logger;
use DevBridge\Core\Settings;

class OpenRouterClient implements AiClientInterface
{
    private string $apiKey;
    private string $model;
    private float  $temperature;
    private int    $maxPromptChars;

    public function __construct(
        string $apiKey       = '',
        string $model        = 'openai/gpt-4o',
        float  $temperature  = 0.2,
        int    $maxPromptChars = 32000
    ) {
        $this->apiKey        = $apiKey ?: Settings::getDecrypted('openrouter_api_key');
        $this->model         = $model  ?: (Settings::get('openrouter_model') ?: 'openai/gpt-4o');
        $this->temperature   = $temperature  ?: (float)(Settings::get('ai_temperature') ?: 0.2);
        $this->maxPromptChars = $maxPromptChars ?: (int)(Settings::get('max_prompt_chars') ?: 32000);
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    public function chat(array $messages, ?string $model = null, ?float $temperature = null): string
    {
        $resolvedModel = $model ?? $this->model;
        $resolvedTemp  = $temperature ?? $this->temperature;

        $messages = $this->truncateMessages($messages);

        $payload = json_encode([
            'model'       => $resolvedModel,
            'messages'    => $messages,
            'temperature' => $resolvedTemp,
        ], JSON_UNESCAPED_UNICODE);

        $context = json_encode(['model' => $resolvedModel, 'messages_count' => count($messages)]);
        Logger::log('ai_request', 'OpenRouter chat request', null, null, $context);

        $raw      = $this->sendRequest($payload);
        $content  = $this->extractContent($raw, $resolvedModel);
        Logger::log('ai_request', 'OpenRouter chat response received', null, null, json_encode(['length' => strlen($content)]));
        return $content;
    }

    public function chatJson(array $messages, ?string $model = null, ?float $temperature = null): array
    {
        $raw     = $this->chat($messages, $model, $temperature);
        $decoded = json_decode($this->extractJson($raw), true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        Logger::log('ai_request', t('ai.repairing_json'));
        return $this->doRepair($messages, $raw, $model, $temperature);
    }

    public function chatJsonWithRepair(array $messages, ?string $model = null, ?float $temperature = null): array
    {
        $raw     = $this->chat($messages, $model, $temperature);
        $decoded = json_decode($this->extractJson($raw), true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Use dedicated repair model if configured
        $repairModel = Settings::get('json_repair_model') ?: null;
        Logger::log('ai_request', t('ai.repairing_json') . ' (repair model: ' . ($repairModel ?? 'default') . ')');
        return $this->doRepair($messages, $raw, $repairModel, 0.0);
    }

    // -----------------------------------------------------------------------
    // Convenience factory methods
    // -----------------------------------------------------------------------

    public static function forPlanner(): self
    {
        $model = Settings::get('planner_model') ?: Settings::get('openrouter_model') ?: 'openai/gpt-4o';
        $temp  = (float)(Settings::get('planner_temperature') ?: Settings::get('ai_temperature') ?: 0.4);
        return new self('', $model, $temp);
    }

    public static function forReviewer(): self
    {
        $model = Settings::get('reviewer_model') ?: Settings::get('openrouter_model') ?: 'openai/gpt-4o';
        $temp  = (float)(Settings::get('reviewer_temperature') ?: Settings::get('ai_temperature') ?: 0.2);
        return new self('', $model, $temp);
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    private function doRepair(array $originalMessages, string $badRaw, ?string $model, ?float $temperature): array
    {
        $repairMessages   = $originalMessages;
        $repairMessages[] = ['role' => 'assistant', 'content' => $badRaw];
        $repairMessages[] = [
            'role'    => 'user',
            'content' => 'Your previous response was not valid JSON. Return ONLY valid JSON, no markdown, no explanation.',
        ];
        $raw2    = $this->chat($repairMessages, $model, $temperature);
        $decoded = json_decode($this->extractJson($raw2), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            Logger::log('error', 'AI returned invalid JSON after repair', null, null, substr($raw2, 0, 500));
            throw new \RuntimeException(t('ai.invalid_json') . ' ' . json_last_error_msg());
        }
        return $decoded;
    }

    private function sendRequest(string $payload): string
    {
        if (!$this->apiKey) {
            throw new \RuntimeException(t('ai.request_failed') . ' OpenRouter API key is not configured.');
        }

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'HTTP-Referer: ' . (defined('BASE_URL') ? BASE_URL : 'https://devbridge.local'),
                'X-Title: DevBridge',
            ],
            CURLOPT_TIMEOUT        => 120,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Logger::log('error', 'OpenRouter curl error: ' . $error);
            throw new \RuntimeException(t('ai.request_failed') . ' ' . $error);
        }
        if ($httpCode !== 200) {
            // Log safely – never expose API key
            $safePayload = json_decode($payload, true);
            if (is_array($safePayload)) { unset($safePayload['api_key']); }
            Logger::log('error', 'OpenRouter HTTP ' . $httpCode, null, null, substr($response, 0, 1000));
            throw new \RuntimeException(t('ai.request_failed') . ' HTTP ' . $httpCode . ': ' . $this->safeErrorMessage($response));
        }

        return $response;
    }

    private function extractContent(string $response, string $model): string
    {
        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            Logger::log('error', 'OpenRouter unexpected response structure', null, null, substr($response, 0, 500));
            throw new \RuntimeException(t('ai.request_failed') . ' Response missing content field.');
        }
        return $data['choices'][0]['message']['content'];
    }

    /** Extract JSON from a response that may be wrapped in markdown code fences. */
    private function extractJson(string $text): string
    {
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $text, $m)) {
            return $m[1];
        }
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }
        return trim($text);
    }

    /** Return a safe error message that never contains auth tokens. */
    private function safeErrorMessage(string $raw): string
    {
        $data = json_decode($raw, true);
        if (isset($data['error']['message'])) {
            return $data['error']['message'];
        }
        // Strip anything that looks like a token
        return preg_replace('/Bearer\s+[^\s"]+/', 'Bearer [REDACTED]', substr($raw, 0, 300));
    }

    private function truncateMessages(array $messages): array
    {
        $total = 0;
        foreach ($messages as $m) {
            $total += strlen($m['content'] ?? '');
        }
        if ($total <= $this->maxPromptChars) {
            return $messages;
        }
        $budget = $this->maxPromptChars;
        $result = [];
        foreach ($messages as $i => $m) {
            $len = strlen($m['content'] ?? '');
            if ($budget - $len < 0 && $i > 0 && $i < count($messages) - 1) {
                $m['content'] = substr($m['content'], 0, max(200, $budget)) . ' [TRUNCATED]';
            }
            $budget -= strlen($m['content']);
            $result[] = $m;
        }
        return $result;
    }
}

