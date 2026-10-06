<?php

namespace App\Domain\Labels\Actions;

use App\Domain\Labels\Enums\LabelReason;
use App\Domain\Labels\Support\PromotionProducts;
use App\Domain\Promotions\Support\PromotionSummary;
use App\Domain\Shared\Country\Country;
use App\Domain\TillData\Models\PromotionRule;
use Carbon\CarbonImmutable;

/**
 * The daily run of `labels:queue-offers` (gap #6): products of every business's offers that start today, or whose
 * last day was yesterday (`effectiveTo` is the last day, London), go into their shops' label queues. Offers saved or
 * ended while live are queued straight away by QueueChangedLabels. Idempotent (dedupe). Returns rows queued.
 */
final class QueueOfferDayLabels
{
    public function __construct(private readonly QueueLabels $queue) {}

    public function handle(?CarbonImmutable $today = null): int
    {
        $today = ($today ?? CarbonImmutable::now(Country::zone()))->setTimezone(Country::zone())->startOfDay();
        $queued = 0;

        PromotionRule::withoutCompanyScope()->where('is_active', true)
            ->where(fn ($q) => $q->whereDate('effective_from', $today->toDateString())->orWhereDate('effective_to', $today->subDay()->toDateString()))
            ->orderBy('id')->each(function (PromotionRule $rule) use (&$queued, $today) {
                $starting = $rule->effective_from->toDateString() === $today->toDateString();
                $queued += $this->queue->handle($rule->company_id, PromotionProducts::ids($rule), $rule->branch_id === null ? null : [$rule->branch_id],
                    $starting ? LabelReason::PromotionStarted : LabelReason::PromotionEnded, mb_substr((string) $rule->name, 0, 120).' · '.PromotionSummary::deal($rule));
            });

        return $queued;
    }
}
