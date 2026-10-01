<?php

namespace App\Domain\Promotions\Actions;

use App\Domain\Labels\Actions\QueueChangedLabels;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\TillData\Models\PromotionRule;
use Carbon\CarbonImmutable;

/**
 * Ends an offer now (module 4.3): `isActive` false and, when it has started, `effectiveTo` = today (London), sent to
 * every till in its next pull. The row is kept (sales and redemptions point at it) and can be switched on again from
 * its form. Ending an ended offer changes nothing.
 */
final class EndPromotion
{
    public function __construct(private readonly RecordAudit $audit, private readonly QueueChangedLabels $labels) {}

    public function handle(PromotionRule $rule): PromotionRule
    {
        $today = CarbonImmutable::now('Europe/London')->startOfDay();
        $wasLive = $this->labels->snapshot($rule);
        $before = ['is_active' => $rule->is_active, 'effective_to' => $rule->effective_to?->toDateString()];

        $rule->is_active = false;

        if ($rule->effective_from->lessThanOrEqualTo($today) && ($rule->effective_to === null || $rule->effective_to->greaterThan($today))) {
            $rule->effective_to = CarbonImmutable::parse($today->toDateString(), 'UTC');
        }

        if (! $rule->isDirty()) {
            return $rule;
        }

        $rule->row_version = (int) $rule->row_version + 1;
        $rule->save();

        $this->audit->handle('promotion.ended', $rule, $before, ['is_active' => false, 'effective_to' => $rule->effective_to?->toDateString()], ['name' => $rule->name]);
        $this->labels->offer($rule, $wasLive); // Shelf labels (gap #6).

        return $rule;
    }
}
