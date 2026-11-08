<?php

namespace App\Domain\Salon\Queries;

use App\Domain\Salon\Models\SalonCalendarFeed;
use App\Domain\Salon\Support\SalonOnline;
use App\Domain\Salon\Support\SalonShops;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Enums\SalonOnlineBookingStatus;
use App\Domain\TillData\Models\SalonOnlineBooking;
use App\Domain\TillData\Models\SalonStaffProfile;
use Carbon\CarbonImmutable;

/**
 * The read-only salon card on the admin tenant page (W3b): for a salon business while the salon switch is on, else
 * null. Counts only: salon shops, stylists on the tills, calendar feeds on, online booking requests this week
 * (Monday, Europe/London) and requests waiting for the till. No client data.
 */
final class SalonAdminSummary
{
    /**
     * @return array{salonShops: int, stylists: int, feedsOn: int, requestsThisWeek: int, pendingRequests: int, weekStartsOn: string}|null
     */
    public static function for(Company $company, CarbonImmutable $now): ?array
    {
        if (! SalonOnline::availableFor($company)) {
            return null;
        }

        return app(CurrentCompany::class)->runAs($company, function () use ($company, $now): array {
            $shopIds = Branch::query()->pluck('id')->filter(fn (string $id) => SalonShops::isSalonBranch($company->id, $id))->values()->all();
            $week = $now->setTimezone(app(Country::class)->timezone())->startOfWeek(CarbonImmutable::MONDAY);
            $requests = SalonOnlineBooking::query()->whereIn('branch_id', $shopIds);

            return [
                'salonShops' => count($shopIds),
                'stylists' => SalonStaffProfile::query()->whereIn('branch_id', $shopIds)->get(['branch_id', 'user_id'])
                    ->unique(fn (SalonStaffProfile $p) => $p->branch_id.'|'.$p->user_id)->count(),
                'feedsOn' => SalonCalendarFeed::query()->whereIn('branch_id', $shopIds)->where('enabled', true)->whereNotNull('token_hash')->count(),
                'requestsThisWeek' => (clone $requests)->where('created_at', '>=', $week->utc()->format('Y-m-d H:i:s'))->count(),
                'pendingRequests' => (clone $requests)->where('status', SalonOnlineBookingStatus::Pending->value)->count(),
                'weekStartsOn' => $week->toDateString(),
            ];
        });
    }
}
