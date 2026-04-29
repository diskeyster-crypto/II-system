<?php
declare(strict_types=1);

namespace DevBridge\AI;

interface AiClientInterface
{
    /**
     * Send a chat completion request.
     *
     * @param  array<array{role:string,content:string}> $messages
     * @param  string|null $model       Override model for this request
     * @param  float|null  $temperature Override temperature for this request
     * @return string  Raw text response from the AI
     * @throws \RuntimeException on failure
     */
    public function chat(array $messages, ?string $model = null, ?float $temperature = null): string;

    /**
     * Send a chat request and expect a JSON response.
     * On invalid JSON, performs one repair attempt.
     *
     * @param  array<array{role:string,content:string}> $messages
     * @param  string|null $model       Override model for this request
     * @param  float|null  $temperature Override temperature for this request
     * @return array  Decoded JSON as associative array
     * @throws \RuntimeException if JSON cannot be obtained after repair
     */
    public function chatJson(array $messages, ?string $model = null, ?float $temperature = null): array;

    /**
     * Like chatJson but uses the dedicated json_repair_model for the repair pass.
     *
     * @param  array<array{role:string,content:string}> $messages
     * @param  string|null $model       Override model for the primary request
     * @param  float|null  $temperature Override temperature for the primary request
     * @return array
     * @throws \RuntimeException if JSON cannot be obtained after repair
     */
    public function chatJsonWithRepair(array $messages, ?string $model = null, ?float $temperature = null): array;
}
