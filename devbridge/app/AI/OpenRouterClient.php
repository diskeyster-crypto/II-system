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

    public function chat(array $messages): string
    {
        // Truncate combined content if needed
        $messages = $this->truncateMessages($messages);

        $payload = json_encode([
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $this->temperature,
        ], JSON_UNESCAPED_UNICODE);

        $context = json_encode(['model' => $this->model, 'messages_count' => count($messages)]);
        Logger::log('ai_request', 'OpenRouter chat request', null, null, $context);

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
            throw new \RuntimeException('AI request failed: ' . $error);
        }
        if ($httpCode !== 200) {
            Logger::log('error', 'OpenRouter HTTP ' . $httpCode . ': ' . $response);
            throw new \RuntimeException('AI request returned HTTP ' . $httpCode . ': ' . $response);
        }

        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            Logger::log('error', 'OpenRouter unexpected response: ' . $response);
            throw new \RuntimeException('AI response missing content.');
        }

        $content = $data['choices'][0]['message']['content'];
        Logger::log('ai_request', 'OpenRouter chat response received', null, null, json_encode(['length' => strlen($content)]));
        return $content;
    }

    public function chatJson(array $messages): array
    {
        $raw = $this->chat($messages);
        $decoded = json_decode($this->extractJson($raw), true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // One repair attempt
        Logger::log('ai_request', 'JSON repair attempt for invalid AI response');
        $repairMessages = $messages;
        $repairMessages[] = ['role' => 'assistant', 'content' => $raw];
        $repairMessages[] = [
            'role'    => 'user',
            'content' => 'Your previous response was not valid JSON. Please return ONLY valid JSON, no markdown, no explanation.',
        ];
        $raw2    = $this->chat($repairMessages);
        $decoded = json_decode($this->extractJson($raw2), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            Logger::log('error', 'AI returned invalid JSON after repair attempt', null, null, $raw2);
            throw new \RuntimeException('AI response is not valid JSON after repair: ' . json_last_error_msg());
        }
        return $decoded;
    }

    /** Extract JSON from a response that may be wrapped in markdown code fences. */
    private function extractJson(string $text): string
    {
        // Try to find JSON block in markdown
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/i', $text, $m)) {
            return $m[1];
        }
        // Find first { ... } block
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }
        return trim($text);
    }

    private function truncateMessages(array $messages): array
    {
        // Count total chars; trim system/first messages if over limit
        $total = 0;
        foreach ($messages as $m) {
            $total += strlen($m['content'] ?? '');
        }
        if ($total <= $this->maxPromptChars) {
            return $messages;
        }
        // Trim middle messages (keep first system and last user)
        $budget = $this->maxPromptChars;
        $result = [];
        foreach ($messages as $i => $m) {
            $len = strlen($m['content'] ?? '');
            if ($budget - $len < 0 && $i > 0 && $i < count($messages) - 1) {
                // Truncate this message
                $m['content'] = substr($m['content'], 0, max(200, $budget)) . ' [TRUNCATED]';
            }
            $budget -= strlen($m['content']);
            $result[] = $m;
        }
        return $result;
    }
}
