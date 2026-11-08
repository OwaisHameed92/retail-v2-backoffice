<?php

namespace App\Domain\Salon\Actions;

use App\Domain\Salon\Calendar\FeedToken;
use App\Domain\Salon\Calendar\ICalWriter;
use App\Domain\Salon\Calendar\StylistCalendar;
use App\Domain\Salon\Models\SalonCalendarFeed;
use App\Domain\Salon\Support\SalonOnline;
use App\Domain\Salon\Support\SalonShops;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\SalonStaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Serves `GET /salon/ical/{branchId}/{userId}/{feedToken}.ics` (salon README §12, W3b). Null (→ 404, the same for
 * every reason, so nothing can be learnt by probing) when: the switch is off, the token is malformed, unknown, turned
 * off or regenerated, it belongs to another shop or stylist, the shop is closed or no longer a salon, or the business
 * is cancelled. Otherwise the stylist's .ics, built in the business's tenant scope.
 *
 * Records when the feed was last read (at most every 15 minutes) so the owner can see a phone is subscribed.
 * The token is never logged or stored in the clear.
 */
final class BuildCalendarFeed
{
    private const FETCH_STAMP_MINUTES = 15;

    public function __construct(private readonly CurrentCompany $tenancy, private readonly StylistCalendar $calendar) {}

    public function handle(string $branchId, string $userId, #[SensitiveParameter] string $token, CarbonImmutable $now): ?string
    {
        if (! SalonOnline::enabled() || ! FeedToken::wellFormed($token)) {
            return null;
        }

        // Public address, no company context yet: found by its token hash, then everything runs in its business's scope.
        $feed = SalonCalendarFeed::withoutCompanyScope()
            ->where('token_hash', FeedToken::hash($token))
            ->where('branch_id', $branchId)
            ->where('user_id', $userId)
            ->where('enabled', true)
            ->first();
        $company = $feed !== null ? Company::query()->find($feed->company_id) : null;

        if ($feed === null || $company === null || $company->status === CompanyStatus::Cancelled) {
            return null;
        }

        return $this->tenancy->runAs($company, function () use ($feed, $userId, $now): ?string {
            $branch = Branch::query()->active()->find($feed->branch_id);

            if ($branch === null || ! SalonShops::isSalonBranch($feed->company_id, $branch->id)) {
                return null;
            }

            $events = $this->calendar->handle($branch, $userId, $now);
            $this->stamp($feed, $now);

            return ICalWriter::render($this->name($branch, $userId), app(Country::class)->timezone(), $events, $now);
        });
    }

    /** "Sarah – Leeds Kirkgate" (the stylist's own diary name on the till), or "Salon diary – Leeds Kirkgate". */
    private function name(Branch $branch, string $userId): string
    {
        $display = trim((string) SalonStaffProfile::query()->where('branch_id', $branch->id)->where('user_id', $userId)->value('display_name'));

        return ($display !== '' ? $display : 'Salon diary').' – '.$branch->name;
    }

    private function stamp(SalonCalendarFeed $feed, CarbonImmutable $now): void
    {
        if ($feed->last_fetched_at === null || $feed->last_fetched_at->lessThan($now->subMinutes(self::FETCH_STAMP_MINUTES))) {
            DB::table('salon_calendar_feeds')->where('id', $feed->id)->update(['last_fetched_at' => $now->utc()->format('Y-m-d H:i:s')]);
        }
    }
}
