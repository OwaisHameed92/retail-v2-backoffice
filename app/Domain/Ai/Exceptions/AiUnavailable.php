<?php

namespace App\Domain\Ai\Exceptions;

use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Enums\AiUnavailableReason;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * An AI call cannot be made (switched off, not set up, not in the plan, allowance used, provider down).
 * The message is written for the end user; never includes provider error details or keys.
 */
final class AiUnavailable extends RuntimeException
{
    public function __construct(public readonly AiUnavailableReason $reason, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function disabled(): self
    {
        return new self(AiUnavailableReason::Disabled, 'AI features are switched off at the moment. Please try again later.');
    }

    public static function notConfigured(): self
    {
        return new self(AiUnavailableReason::NotConfigured, 'AI features are not set up yet. Please contact Switch & Save support.');
    }

    public static function companyInactive(): self
    {
        return new self(AiUnavailableReason::CompanyInactive, 'AI features are not available while this account is on hold.');
    }

    public static function notInPlan(AiFeature $feature): self
    {
        return new self(
            AiUnavailableReason::NotInPlan,
            "Your plan does not include the {$feature->label()}. Please contact Switch & Save to add it.",
        );
    }

    public static function budgetExhausted(string $resetsOn): self
    {
        return new self(
            AiUnavailableReason::BudgetExhausted,
            "You have used this month's AI allowance. It resets on {$resetsOn}.",
        );
    }

    public static function rateLimited(?Throwable $previous = null): self
    {
        return new self(AiUnavailableReason::RateLimited, 'The AI service is busy. Please try again in a minute.', $previous);
    }

    public static function overloaded(?Throwable $previous = null): self
    {
        return new self(AiUnavailableReason::RateLimited, 'The AI service is very busy right now. Please try again in a minute or two.', $previous);
    }

    public static function timedOut(?Throwable $previous = null): self
    {
        return new self(AiUnavailableReason::ProviderError, 'The AI service took too long to answer. Please try again, or ask a narrower question.', $previous);
    }

    public static function providerError(?Throwable $previous = null): self
    {
        return new self(AiUnavailableReason::ProviderError, 'The AI service could not answer just now. Please try again shortly.', $previous);
    }

    /**
     * An expected, user-facing condition: log one line with the reason only (never a chained provider error, whose
     * message can echo prompt content), instead of Laravel's default stack trace.
     */
    public function report(): void
    {
        Log::info('AI unavailable.', ['reason' => $this->reason->value]);
    }
}
