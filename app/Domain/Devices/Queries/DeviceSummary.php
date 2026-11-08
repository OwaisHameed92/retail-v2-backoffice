<?php

namespace App\Domain\Devices\Queries;

use App\Domain\Devices\Data\DeviceFilters;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Devices page summary strip, over every business and ignoring the list filters: live tills, tills seen today
 * (since midnight in the shops' time zone), tills not seen for 7+ days, tills on a version older than the gate,
 * signed-in handhelds. Three aggregate queries whatever the number of devices.
 *
 * @phpstan-type Summary array{tills: int, liveToday: int, notSeen: int, notSeenDays: int, oldVersion: int, gate: string|null, handhelds: int}
 */
final class DeviceSummary
{
    /**
     * @param  list<string>  $oldVersions  Versions older than the gate (EposVersions::below()).
     * @return Summary
     */
    public static function compute(CarbonImmutable $now, ?string $gate, array $oldVersions): array
    {
        $today = CarbonImmutable::now(Country::zone())->startOfDay()->utc();
        $cutoff = $now->subDays(DeviceFilters::DEFAULT_DAYS);
        $sql = fn (CarbonImmutable $at) => $at->utc()->format('Y-m-d H:i:s');

        $tills = DB::query()->fromSub(TillDevices::query(null, $now), 'devices')
            ->selectRaw('count(*) as tills')
            ->selectRaw('sum(case when last_seen_at >= ? then 1 else 0 end) as live_today', [$sql($today)])
            ->selectRaw('sum(case when last_seen_at < ? then 1 else 0 end) as not_seen', [$sql($cutoff)])
            ->first();

        $old = $oldVersions === [] ? 0 : DB::query()->fromSub(TillDevices::query(null, $now, $oldVersions), 'devices')->count();
        $handhelds = DB::query()->fromSub(HandheldDevices::query(DeviceStatus::SignedIn), 'devices')->count();

        return [
            'tills' => (int) ($tills->tills ?? 0),
            'liveToday' => (int) ($tills->live_today ?? 0),
            'notSeen' => (int) ($tills->not_seen ?? 0),
            'notSeenDays' => DeviceFilters::DEFAULT_DAYS,
            'oldVersion' => $old,
            'gate' => $gate,
            'handhelds' => $handhelds,
        ];
    }
}
