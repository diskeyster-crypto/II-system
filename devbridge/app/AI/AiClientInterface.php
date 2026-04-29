<?php
declare(strict_types=1);

namespace DevBridge\AI;

interface AiClientInterface
{
    /**
     * Send a chat completion request.
     *
     * @param  array<array{role:string,content:string}> $messages
     * @return string  Raw text response from the AI
     * @throws \RuntimeException on failure
     */
    public function chat(array $messages): string;

    /**
     * Send a chat request and expect a JSON response.
     * On invalid JSON, performs one repair attempt.
     *
     * @param  array<array{role:string,content:string}> $messages
     * @return array  Decoded JSON as associative array
     * @throws \RuntimeException if JSON cannot be obtained after repair
     */
    public function chatJson(array $messages): array;
}
