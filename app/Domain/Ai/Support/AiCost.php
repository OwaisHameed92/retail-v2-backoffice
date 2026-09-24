<?php

namespace App\Domain\Ai\Support;

use App\Domain\Ai\Data\AiTokenUsage;
use App\Domain\Shared\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Cost of one call in pounds: tokens x list price (USD per million, `config('ai.pricing')`) x `ai.usd_to_gbp`.
 * bcmath strings throughout. An unknown model is priced at the default model's rates and logged.
 */
final class AiCost
{
    public static function pounds(string $model, AiTokenUsage $usage): string
    {
        $prices = self::pricesFor($model);

        $usd = Money::sum([
            Money::mul($usage->inputTokens, $prices['input'], 12),
            Money::mul($usage->outputTokens, $prices['output'], 12),
            Money::mul($usage->cacheWriteTokens, $prices['cache_write'], 12),
            Money::mul($usage->cacheReadTokens, $prices['cache_read'], 12),
        ], 12);

        $perToken = bcdiv($usd, '1000000', 12);

        return Money::mul($perToken, (string) config('ai.usd_to_gbp', '0.79'), CostCast::SCALE);
    }

    /**
     * @return array{input: string, output: string, cache_write: string, cache_read: string}
     */
    private static function pricesFor(string $model): array
    {
        /** @var array<string, array<string, string>> $pricing */
        $pricing = (array) config('ai.pricing', []);

        $prices = $pricing[$model] ?? null;

        if ($prices === null) {
            Log::warning('AI pricing missing for model; using the default model price.', ['model' => $model]);
            $prices = $pricing[(string) config('ai.models.default')] ?? [];
        }

        return [
            'input' => (string) ($prices['input'] ?? '0'),
            'output' => (string) ($prices['output'] ?? '0'),
            'cache_write' => (string) ($prices['cache_write'] ?? '0'),
            'cache_read' => (string) ($prices['cache_read'] ?? '0'),
        ];
    }
}
