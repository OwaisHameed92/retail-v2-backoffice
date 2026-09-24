<?php

namespace App\Domain\Leads\Queries;

use App\Domain\Leads\Data\LeadStatsData;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Mail\Support\MailFormat;
use Carbon\CarbonImmutable;

/**
 * Lead numbers for the lead list and the admin dashboard (module 1.9). Archived leads are left out.
 *
 * - newThisWeek: leads received since Monday 00:00 (Europe/London)
 * - awaitingContact: leads still "new" (nobody has marked them contacted)
 * - followUpsDue: open leads with a follow-up by the end of today (overdue included); `overdueFollowUps` of those
 *   are already late
 * - conversionRate: of the leads received in the last 90 days, the share converted to a trial tenant (0–100,
 *   one decimal; null when there were none)
 */
final class LeadStats
{
    public const CONVERSION_WINDOW_DAYS = 90;

    public static function compute(?CarbonImmutable $now = null): LeadStatsData
    {
        $now ??= CarbonImmutable::now();
        $weekStart = $now->setTimezone(MailFormat::TIMEZONE)->startOfWeek(CarbonImmutable::MONDAY)->utc();
        $windowStart = $now->subDays(self::CONVERSION_WINDOW_DAYS);

        $received = Lead::query()->where('created_at', '>=', $windowStart)->count();
        $converted = Lead::query()->where('created_at', '>=', $windowStart)->where('status', LeadStatus::Converted->value)->count();

        return new LeadStatsData(
            newThisWeek: Lead::query()->where('created_at', '>=', $weekStart)->count(),
            awaitingContact: Lead::query()->where('status', LeadStatus::New->value)->count(),
            followUpsDue: Lead::query()->open()->whereNotNull('follow_up_at')->where('follow_up_at', '<=', LeadQuery::endOfToday($now))->count(),
            overdueFollowUps: Lead::query()->open()->whereNotNull('follow_up_at')->where('follow_up_at', '<', $now)->count(),
            conversionRate: $received === 0 ? null : round($converted / $received * 100, 1),
            receivedInWindow: $received,
            convertedInWindow: $converted,
            windowDays: self::CONVERSION_WINDOW_DAYS,
        );
    }
}
