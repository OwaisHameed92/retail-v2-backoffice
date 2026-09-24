<?php

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Data\AiTokenUsage;
use App\Domain\Ai\Enums\AiUsageStatus;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiUsage;
use App\Domain\Ai\Support\AiCost;

/**
 * Writes one ai_usage row per model call (also failed and refused ones, with their tokens, usually zero).
 */
final class RecordAiUsage
{
    public function handle(
        AiContext $context,
        string $model,
        AiTokenUsage $usage,
        AiUsageStatus $status,
        int $latencyMs = 0,
        ?string $stopReason = null,
        ?AiConversation $conversation = null,
    ): AiUsage {
        return AiUsage::query()->create([
            'company_id' => $context->companyId(),
            'user_id' => $context->userId(),
            'admin_id' => $context->adminId(),
            'conversation_id' => $conversation?->id,
            'feature' => $context->feature,
            'model' => $model,
            'status' => $status,
            'stop_reason' => $stopReason,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_read_tokens' => $usage->cacheReadTokens,
            'cache_write_tokens' => $usage->cacheWriteTokens,
            'total_tokens' => $usage->total(),
            'cost_gbp' => AiCost::pounds($model, $usage),
            'latency_ms' => max(0, $latencyMs),
        ]);
    }
}
