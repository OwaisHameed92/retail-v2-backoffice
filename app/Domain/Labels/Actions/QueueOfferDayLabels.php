<?php

namespace App\Domain\Labels\Actions;

use App\Domain\Labels\Enums\LabelReason;
use App\Domain\Labels\Support\PromotionProducts;
use App\Domain\Promotions\Support\PromotionSummary;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\BusinessFailures;
use App\Domain\TillData\Models\PromotionRule;
use Carbon\CarbonImmutable;

/**
 * The daily run of `labels:queue-offers` (gap #6): products of every business's offers that start today, or whose
 * last day was yesterday (`effectiveTo` is the last day, London), go into their shops' label queues. Offers saved or
 * ended while live are queued straight away by QueueChangedLabels. Idempotent (dedupe). Returns rows queued.
 * One offer's error is reported with its company id and the other offers still run (audit DATA-8;
 * `failedCompanyIds()`, the command exits non-zero).
 */
final class QueueOfferDayLabels
{
    public const TASK = 'labels:queue-offers';

    public function __construct(
        private readonly QueueLabels $queue,
        private readonly BusinessFailures $failures,
    ) {}

    public function handle(?CarbonImmutable $today = null): int
    {
        $today = ($today ?? CarbonImmutable::now(Country::zone()))->setTimezone(Country::zone())->startOfDay();
        $queued = 0;
        $this->failures->forget(self::TASK);

        PromotionRule::withoutCompanyScope()->where('is_active', true)
            ->where(fn ($q) => $q->whereDate('effective_from', $today->toDateString())->orWhereDate('effective_to', $today->subDay()->toDateString()))
            ->orderBy('id')->each(function (PromotionRule $rule) use (&$queued, $today) {
                $queued += $this->failures->attempt(self::TASK, $rule->company_id, function () use ($rule, $today) {
                    // An offer with no first day that ended yesterday has no effective_from.
                    $from = $rule->getAttribute('effective_from');
                    $starting = $from instanceof CarbonImmutable && $from->toDateString() === $today->toDateString();

                    return $this->queue->handle($rule->company_id, PromotionProducts::ids($rule), $rule->branch_id === null ? null : [$rule->branch_id],
                        $starting ? LabelReason::PromotionStarted : LabelReason::PromotionEnded, mb_substr((string) $rule->name, 0, 120).' · '.PromotionSummary::deal($rule));
                }, 0);
            });

        return $queued;
    }

    /**
     * The businesses with an offer that failed in the last run (each reported already).
     *
     * @return list<string>
     */
    public function failedCompanyIds(): array
    {
        return $this->failures->companyIds(self::TASK);
    }
}
