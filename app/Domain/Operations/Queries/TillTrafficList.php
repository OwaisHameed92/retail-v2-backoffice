<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recent till traffic per business, from each shop's sync bookkeeping (sync_branch_status: last hello, push, pull,
 * rows accepted today). `recent`: the 10 businesses that pushed most recently. `quiet`: businesses that pushed in the
 * last 30 days but not in the last 24 hours (amber), trading businesses only.
 *
 * @phpstan-type TrafficRow array{business: array{id: string, name: string}, shops: int, lastPushAt: string|null, lastPullAt: string|null, lastHelloAt: string|null, acceptedToday: int, rejectedToday: int, quiet: bool}
 */
final class TillTrafficList
{
    public const LIMIT = 10;

    public const QUIET_HOURS = 24;

    /** A business silent for longer than this has probably stopped trading (or left); it is not flagged. */
    public const QUIET_MAX_DAYS = 30;

    /**
     * @return array{recent: list<TrafficRow>, quiet: list<TrafficRow>, quietTotal: int, businesses: int}
     */
    public static function compute(CarbonImmutable $now): array
    {
        $today = $now->setTimezone(Country::zone())->toDateString();
        $closed = [CompanyStatus::Suspended->value, CompanyStatus::Cancelled->value];

        $rows = DB::table('sync_branch_status')
            ->join('companies', 'companies.id', '=', 'sync_branch_status.company_id')
            ->groupBy('sync_branch_status.company_id', 'companies.name', 'companies.status', 'companies.deleted_at')
            ->selectRaw('sync_branch_status.company_id, companies.name, companies.status, companies.deleted_at, count(*) as shops, '
                .'max(last_push_at) as last_push, max(last_pull_at) as last_pull, max(last_hello_at) as last_hello, '
                .'sum(case when substr(rows_day, 1, 10) = ? then rows_accepted_today else 0 end) as accepted, '
                .'sum(case when substr(rows_day, 1, 10) = ? then rows_rejected_today else 0 end) as rejected', [$today, $today])
            ->get();

        $quietBefore = $now->subHours(self::QUIET_HOURS)->utc()->format('Y-m-d H:i:s');
        $quietAfter = $now->subDays(self::QUIET_MAX_DAYS)->utc()->format('Y-m-d H:i:s');
        $recent = [];
        $quiet = [];

        foreach ($rows as $row) {
            $push = $row->last_push === null ? null : (string) $row->last_push;
            $trading = $row->deleted_at === null && ! in_array((string) $row->status, $closed, true);
            $isQuiet = $trading && $push !== null && $push < $quietBefore && $push >= $quietAfter;
            $item = self::row($row, $isQuiet);

            if ($push !== null) {
                $recent[] = ['push' => $push, 'row' => $item];
            }

            if ($isQuiet) {
                $quiet[] = ['push' => $push, 'row' => $item];
            }
        }

        $byPush = fn (array $a, array $b) => strcmp($b['push'], $a['push']);
        usort($recent, $byPush);
        usort($quiet, $byPush);

        return [
            'recent' => array_column(array_slice($recent, 0, self::LIMIT), 'row'),
            'quiet' => array_column(array_slice($quiet, 0, self::LIMIT), 'row'),
            'quietTotal' => count($quiet),
            'businesses' => $rows->count(),
        ];
    }

    /**
     * @return TrafficRow
     */
    private static function row(object $row, bool $quiet): array
    {
        $iso = fn (mixed $value) => $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->toIso8601ZuluString();

        return [
            'business' => ['id' => (string) $row->company_id, 'name' => (string) $row->name],
            'shops' => (int) $row->shops,
            'lastPushAt' => $iso($row->last_push),
            'lastPullAt' => $iso($row->last_pull),
            'lastHelloAt' => $iso($row->last_hello),
            'acceptedToday' => (int) $row->accepted,
            'rejectedToday' => (int) $row->rejected,
            'quiet' => $quiet,
        ];
    }
}
