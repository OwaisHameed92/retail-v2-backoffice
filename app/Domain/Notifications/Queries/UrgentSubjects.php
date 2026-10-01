<?php

namespace App\Domain\Notifications\Queries;

use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Notifications\Data\UrgentSubject;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The urgent problems behind owner alerts (module 7.8), read from the Till health alerts (module 2.7) that
 * `till-health:refresh` raises and clears: till offline (raised only during the shop's opening hours, after
 * `till-health.alert_offline_hours` hours of silence) and sync failing / stalled. Raw queries, always by company.
 */
final class UrgentSubjects
{
    /**
     * Open problems, by company id and key.
     *
     * @param  list<string>|null  $companyIds
     * @return array<string, array<string, UrgentSubject>>
     */
    public static function open(?array $companyIds = null): array
    {
        $rows = self::base()->whereNull('a.resolved_at')
            ->when($companyIds !== null, fn (Builder $q) => $q->whereIn('a.company_id', $companyIds ?? []))
            ->get();
        $out = [];

        foreach ($rows as $row) {
            $subject = self::subject($row);

            if ($subject !== null) {
                $out[$subject->companyId][$subject->key] = $subject;
            }
        }

        return $out;
    }

    /**
     * The latest alert behind each cleared key of one business (for the "resolved" email).
     *
     * @param  list<string>  $keys
     * @return array<string, UrgentSubject>
     */
    public static function cleared(string $companyId, array $keys): array
    {
        $licenceIds = array_values(array_unique(array_map(fn (string $key) => (string) explode('|', $key, 2)[1], $keys)));
        $rows = self::base()->where('a.company_id', $companyId)->whereIn('a.licence_id', $licenceIds)
            ->whereNotNull('a.resolved_at')->orderBy('a.first_seen_at')->get();
        $out = [];

        foreach ($rows as $row) {
            $subject = self::subject($row);

            if ($subject !== null && in_array($subject->key, $keys, true)) {
                $out[$subject->key] = $subject; // the newest wins (ordered oldest first)
            }
        }

        return $out;
    }

    private static function base(): Builder
    {
        return DB::table('licence_alerts as a')
            ->join('licences as l', 'l.id', '=', 'a.licence_id')
            ->join('companies as c', 'c.id', '=', 'a.company_id')
            ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->leftJoin('registers as r', 'r.id', '=', 'l.register_id')
            ->whereIn('a.type', AlertType::licenceAlertValues())
            ->select(['a.company_id', 'a.licence_id', 'a.type', 'a.details', 'a.first_seen_at', 'a.resolved_at', 'l.branch_id', 'l.status as licence_status',
                'c.name as business', 'c.status as company_status', 'b.name as shop', 'r.name as till']);
    }

    private static function subject(object $row): ?UrgentSubject
    {
        $problem = LicenceAlertType::tryFrom((string) $row->type);
        $type = $problem !== null ? AlertType::fromLicenceAlert($problem) : null;

        if ($problem === null || $type === null) {
            return null;
        }

        $details = is_string($row->details) ? (array) json_decode($row->details, true) : [];
        $licence = LicenceStatus::tryFrom((string) $row->licence_status);
        $company = CompanyStatus::tryFrom((string) $row->company_status);

        return new UrgentSubject(
            key: UrgentSubject::key($problem->value, (string) $row->licence_id),
            companyId: (string) $row->company_id,
            businessName: (string) $row->business,
            type: $type,
            problem: $problem->value,
            branchId: $row->branch_id !== null ? (string) $row->branch_id : null,
            shopName: $row->shop !== null ? (string) $row->shop : 'your shop',
            tillName: $row->till !== null ? (string) $row->till : (isset($details['deviceName']) ? (string) $details['deviceName'] : null),
            summary: isset($details['summary']) ? (string) $details['summary'] : null,
            since: CarbonImmutable::parse((string) $row->first_seen_at, 'UTC'),
            resolvedAt: $row->resolved_at !== null ? CarbonImmutable::parse((string) $row->resolved_at, 'UTC') : null,
            genuinelyResolved: $licence !== null && $licence->canTrade() && ! in_array($company, [CompanyStatus::Suspended, CompanyStatus::Cancelled, null], true),
        );
    }
}
