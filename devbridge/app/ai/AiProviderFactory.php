<?php
declare(strict_types=1);

namespace DevBridge\AI;

use DevBridge\Core\Settings;

/**
 * Returns the configured AI client.
 *
 * Default provider is Gemini.
 * OpenRouterClient is kept as a legacy option and can be returned when
 * the setting ai_provider = 'openrouter' is explicitly set.
 */
class AiProviderFactory
{
    /**
     * Return the default AI client (uses configured provider).
     */
    public static function make(): AiClientInterface
    {
        $provider = Settings::get('ai_provider') ?: 'gemini';
        if ($provider === 'openrouter') {
            return new OpenRouterClient();
        }
        return new GeminiClient();
    }

    /**
     * Return a client configured for the 'planner' role.
     */
    public static function forPlanner(): AiClientInterface
    {
        if (self::isLegacyProvider()) {
            return OpenRouterClient::forPlanner();
        }
        return GeminiClient::forPlanner();
    }

    /**
     * Return a client configured for the 'reviewer' role.
     */
    public static function forReviewer(): AiClientInterface
    {
        if (self::isLegacyProvider()) {
            return OpenRouterClient::forReviewer();
        }
        return GeminiClient::forReviewer();
    }

    /**
     * Return a client configured for the 'critic' role.
     */
    public static function forCritic(): AiClientInterface
    {
        if (self::isLegacyProvider()) {
            return OpenRouterClient::forPlanner(); // legacy fallback
        }
        return GeminiClient::forCritic();
    }

    /**
     * Return a client configured for the 'prompt_builder' role.
     */
    public static function forPromptBuilder(): AiClientInterface
    {
        if (self::isLegacyProvider()) {
            return OpenRouterClient::forPlanner(); // legacy fallback
        }
        return GeminiClient::forPromptBuilder();
    }

    // -----------------------------------------------------------------------

    private static function isLegacyProvider(): bool
    {
        return (Settings::get('ai_provider') ?: 'gemini') === 'openrouter';
    }
}
