<?php

namespace App\Domain\Ai\Support;

use App\Domain\Ai\Enums\AiFeature;

/**
 * Typed reads of `config('ai')`.
 */
final class AiSettings
{
    public static function enabled(): bool
    {
        return (bool) config('ai.enabled', true);
    }

    /** The model id for a feature (its tier in `ai.features.*.model` → `ai.models.<tier>`). */
    public static function modelFor(AiFeature $feature): string
    {
        $tier = (string) config("ai.features.{$feature->value}.model", 'default');

        return (string) config("ai.models.{$tier}", config('ai.models.default', 'claude-sonnet-5'));
    }

    public static function fastModel(): string
    {
        return (string) config('ai.models.fast', 'claude-haiku-4-5-20251001');
    }

    public static function effortFor(AiFeature $feature): ?string
    {
        $effort = config("ai.features.{$feature->value}.effort");

        return is_string($effort) && $effort !== '' ? $effort : null;
    }

    public static function maxTokensFor(AiFeature $feature): int
    {
        return max(256, (int) config("ai.features.{$feature->value}.max_tokens", 16000));
    }

    public static function maxSteps(): int
    {
        return max(1, (int) config('ai.max_steps', 8));
    }

    public static function maxToolResultChars(): int
    {
        return max(1000, (int) config('ai.max_tool_result_chars', 20000));
    }

    public static function historyMessages(): int
    {
        return max(2, (int) config('ai.history_messages', 40));
    }

    public static function pendingActionTtlMinutes(): int
    {
        return max(1, (int) config('ai.pending_action_ttl_minutes', 15));
    }

    /**
     * @return array{thinking: string|null, effort: bool, fallbacks: bool}
     */
    public static function modelOptions(string $model): array
    {
        $options = (array) config("ai.model_options.{$model}", []);

        return [
            'thinking' => is_string($options['thinking'] ?? null) ? $options['thinking'] : null,
            'effort' => (bool) ($options['effort'] ?? false),
            'fallbacks' => (bool) ($options['fallbacks'] ?? false),
        ];
    }
}
