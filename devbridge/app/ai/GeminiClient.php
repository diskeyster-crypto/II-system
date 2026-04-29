<?php
declare(strict_types=1);

namespace DevBridge\AI;

use DevBridge\Core\Logger;
use DevBridge\Core\Settings;

/**
 * Google Gemini REST API client.
 *
 * Implements AiClientInterface using the Gemini generateContent endpoint.
 * API key is never written to logs or shown in the UI.
 */
class GeminiClient implements AiClientInterface
{
    private const API_BASE    = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const API_SUFFIX  = ':generateContent';

    private string $apiKey;
    private string $model;
    private float  $temperature;
    private int    $maxOutputTokens;
    private int    $maxPromptChars;

    /** Model used for the JSON-repair retry pass. */
    private string $repairModel;
    private float  $repairTemperature;

    public function __construct(
        string $apiKey          = '',
        string $model           = 'gemini-2.5-flash',
        float  $temperature     = 0.2,
        int    $maxOutputTokens = 8192,
        int    $maxPromptChars  = 32000
    ) {
        $this->apiKey          = $apiKey ?: Settings::getDecrypted('gemini_api_key');
        $this->model           = $model;
        $this->temperature     = $temperature;
        $this->maxOutputTokens = $maxOutputTokens;
        $this->maxPromptChars  = $maxPromptChars;

        $this->repairModel       = Settings::get('gemini_json_repair_model') ?: 'gemini-2.5-flash-lite';
        $this->repairTemperature = 0.0;
    }

    // -----------------------------------------------------------------------
    // AiClientInterface implementation
    // -----------------------------------------------------------------------

    public function chat(array $messages, ?string $model = null, ?float $temperature = null): string
    {
        $resolvedModel = $model ?? $this->model;
        $resolvedTemp  = $temperature ?? $this->temperature;
        $messages      = $this->truncateMessages($messages);

        $payload = $this->buildPayload($messages, $resolvedTemp, false);

        Logger::log('ai_request', 'Gemini chat request', null, null,
            json_encode(['model' => $resolvedModel, 'messages_count' => count($messages)]));

        $raw     = $this->sendRequest($resolvedModel, $payload);
        $content = $this->extractText($raw, $resolvedModel);

        Logger::log('ai_request', 'Gemini chat response received', null, null,
            json_encode(['length' => strlen($content)]));

        return $content;
    }

    public function chatJson(array $messages, ?string $model = null, ?float $temperature = null): array
    {
        $resolvedModel = $model ?? $this->model;
        $resolvedTemp  = $temperature ?? $this->temperature;
        $messages      = $this->truncateMessages($messages);

        $payload  = $this->buildPayload($messages, $resolvedTemp, true);
        $raw      = $this->sendRequest($resolvedModel, $payload);
        $text     = $this->extractText($raw, $resolvedModel);
        $decoded  = json_decode($this->extractJson($text), true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        Logger::log('ai_request', t('ai.repairing_json'));
        return $this->doRepair($messages, $text, $resolvedModel, $resolvedTemp);
    }

    public function chatJsonWithRepair(array $messages, ?string $model = null, ?float $temperature = null): array
    {
        $resolvedModel = $model ?? $this->model;
        $resolvedTemp  = $temperature ?? $this->temperature;
        $messages      = $this->truncateMessages($messages);

        $payload  = $this->buildPayload($messages, $resolvedTemp, true);
        $raw      = $this->sendRequest($resolvedModel, $payload);
        $text     = $this->extractText($raw, $resolvedModel);
        $decoded  = json_decode($this->extractJson($text), true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        Logger::log('ai_request',
            t('ai.repairing_json') . ' (repair model: ' . $this->repairModel . ')');
        return $this->doRepair($messages, $text, $this->repairModel, $this->repairTemperature);
    }

    // -----------------------------------------------------------------------
    // JSON generation helper (alias with semantic name)
    // -----------------------------------------------------------------------

    /**
     * Send a request expecting a structured JSON response.
     *
     * @param  string      $systemPrompt
     * @param  string      $userPrompt
     * @param  string|null $schemaName   Reserved for future responseSchema support
     * @param  array       $options      Optional: model, temperature, max_output_tokens
     * @return array
     */
    public function generateJson(
        string  $systemPrompt,
        string  $userPrompt,
        ?string $schemaName = null,
        array   $options    = []
    ): array {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ];
        $model = $options['model']       ?? $this->model;
        $temp  = isset($options['temperature']) ? (float)$options['temperature'] : $this->temperature;
        return $this->chatJson($messages, $model, $temp);
    }

    // -----------------------------------------------------------------------
    // Factory methods – map named roles to configured models/temperatures
    // -----------------------------------------------------------------------

    public static function forPlanner(): self
    {
        return self::forRole('planner', 'gemini_planner_model', 'gemini_balanced_model',
            'gemini-2.5-flash', 'planner_temperature', 0.4);
    }

    public static function forCritic(): self
    {
        return self::forRole('critic', 'gemini_critic_model', 'gemini_balanced_model',
            'gemini-2.5-flash', 'critic_temperature', 0.2);
    }

    public static function forPromptBuilder(): self
    {
        return self::forRole('prompt_builder', 'gemini_prompt_builder_model', 'gemini_balanced_model',
            'gemini-2.5-flash', 'prompt_builder_temperature', 0.2);
    }

    public static function forReviewer(): self
    {
        return self::forRole('reviewer', 'gemini_reviewer_model', 'gemini_balanced_model',
            'gemini-2.5-flash', 'reviewer_temperature', 0.1);
    }

    /**
     * Resolve a named role to the configured model/temperature.
     *
     * Priority: role-specific key → profile key → hard-coded default
     */
    private static function forRole(
        string $role,
        string $roleModelKey,
        string $profileModelKey,
        string $fallbackModel,
        string $tempKey,
        float  $fallbackTemp
    ): self {
        $model = Settings::get($roleModelKey)
              ?: Settings::get($profileModelKey)
              ?: $fallbackModel;
        $temp  = (float)(Settings::get($tempKey) ?: $fallbackTemp);
        $tokens = (int)(Settings::get('max_output_tokens') ?: 8192);

        return new self('', $model, $temp, $tokens);
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Build the Gemini request payload.
     *
     * Messages array uses OpenAI-style {role, content}.
     * 'system' role is lifted into system_instruction; remaining become contents.
     *
     * @param  bool $json  If true, request JSON MIME type.
     */
    private function buildPayload(array $messages, float $temperature, bool $json): string
    {
        $systemText = '';
        $contents   = [];

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemText .= ($systemText !== '' ? "\n\n" : '') . ($msg['content'] ?? '');
            } else {
                // Gemini roles: user / model (not 'assistant')
                $geminiRole = ($msg['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
                $contents[] = [
                    'role'  => $geminiRole,
                    'parts' => [['text' => $msg['content'] ?? '']],
                ];
            }
        }

        // Gemini requires alternating user/model turns; if first message is not user,
        // prepend an empty user turn.
        if (!empty($contents) && $contents[0]['role'] !== 'user') {
            array_unshift($contents, ['role' => 'user', 'parts' => [['text' => '']]]);
        }

        $genConfig = [
            'temperature'     => $temperature,
            'maxOutputTokens' => $this->maxOutputTokens,
        ];
        if ($json) {
            $genConfig['responseMimeType'] = 'application/json';
        }

        $body = [
            'contents'         => $contents,
            'generationConfig' => $genConfig,
        ];

        if ($systemText !== '') {
            $body['system_instruction'] = [
                'parts' => [['text' => $systemText]],
            ];
        }

        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Send a cURL request to the Gemini generateContent endpoint. */
    private function sendRequest(string $model, string $payload): string
    {
        if (!$this->apiKey) {
            throw new \RuntimeException(t('ai.gemini_key_missing'));
        }

        $url = self::API_BASE . urlencode($model) . self::API_SUFFIX . '?key=' . urlencode($this->apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            Logger::log('error', 'Gemini curl error: ' . $curlErr, null, null,
                json_encode(['model' => $model]));
            throw new \RuntimeException(t('ai.request_failed') . ' ' . $curlErr);
        }

        if ($httpCode !== 200) {
            $msg = $this->parseErrorMessage($response, $httpCode);
            Logger::log('error', 'Gemini HTTP ' . $httpCode, null, null,
                json_encode(['model' => $model, 'status' => $httpCode, 'msg' => $msg]));
            throw new \RuntimeException($msg);
        }

        return $response;
    }

    /** Extract the text from the Gemini response JSON. */
    private function extractText(string $responseJson, string $model): string
    {
        $data = json_decode($responseJson, true);

        if (isset($data['error']['message'])) {
            throw new \RuntimeException(t('ai.request_failed') . ' ' . $data['error']['message']);
        }

        $candidate = $data['candidates'][0] ?? null;

        if ($candidate === null) {
            // promptFeedback may explain a block
            $reason = $data['promptFeedback']['blockReason'] ?? null;
            $msg = $reason
                ? t('ai.gemini_blocked') . ' (' . $reason . ')'
                : t('ai.gemini_no_candidates');
            Logger::log('error', $msg, null, null, json_encode(['model' => $model]));
            throw new \RuntimeException($msg);
        }

        // Safety filter or other finish reason
        $finishReason = $candidate['finishReason'] ?? 'STOP';
        if (!in_array($finishReason, ['STOP', 'MAX_TOKENS', ''], true)) {
            $msg = t('ai.gemini_blocked') . ' (finishReason: ' . $finishReason . ')';
            Logger::log('error', $msg, null, null, json_encode(['model' => $model]));
            throw new \RuntimeException($msg);
        }

        $text = $candidate['content']['parts'][0]['text'] ?? null;

        if ($text === null || $text === '') {
            $msg = t('ai.gemini_empty_response');
            Logger::log('error', $msg, null, null, json_encode(['model' => $model]));
            throw new \RuntimeException($msg);
        }

        return $text;
    }

    /** Parse a Gemini HTTP error response into a clean user-facing message. */
    private function parseErrorMessage(string $raw, int $httpCode): string
    {
        $data = json_decode($raw, true);
        $apiMsg = $data['error']['message'] ?? null;

        $prefix = match ($httpCode) {
            400     => t('ai.gemini_err_400'),
            401,403 => t('ai.gemini_err_401'),
            429     => t('ai.gemini_err_429'),
            500,503 => t('ai.gemini_err_500'),
            default => t('ai.request_failed') . ' HTTP ' . $httpCode,
        };

        return $prefix . ($apiMsg ? ': ' . $apiMsg : '');
    }

    /** Extract JSON object from text that may have markdown fences. */
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

    /** Repair pass: send back the broken JSON and ask the model to fix it. */
    private function doRepair(
        array   $originalMessages,
        string  $badText,
        string  $model,
        float   $temperature
    ): array {
        $repairMessages   = $originalMessages;
        $repairMessages[] = ['role' => 'assistant', 'content' => $badText];
        $repairMessages[] = [
            'role'    => 'user',
            'content' => 'Your previous response was not valid JSON. Return ONLY valid JSON, no markdown, no explanation.',
        ];

        $payload  = $this->buildPayload($repairMessages, $temperature, true);
        $raw2     = $this->sendRequest($model, $payload);
        $text2    = $this->extractText($raw2, $model);
        $decoded  = json_decode($this->extractJson($text2), true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            Logger::log('error', 'Gemini returned invalid JSON after repair', null, null,
                substr($text2, 0, 500));
            throw new \RuntimeException(t('ai.invalid_json') . ' ' . json_last_error_msg());
        }

        return $decoded;
    }

    /** Truncate messages to stay within the prompt character budget. */
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
