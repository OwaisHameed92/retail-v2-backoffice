<?php

namespace App\Domain\Licensing\Queries;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Admin queries over licences of every company (the documented `withoutCompanyScope()` escape hatch), joined to
 * their company, branch, till and plan so lists can search, sort and filter in SQL.
 *
 * `whereEffectiveStatus()` is the SQL twin of LicenceState::for(); tests check both agree for every case.
 */
final class LicenceQuery
{
    /** Sortable columns the admin table may name (select aliases or licence columns). */
    public const SORTABLE = ['company_name', 'branch_name', 'register_name', 'plan_name', 'ends_at', 'last_check_in_at', 'created_at'];

    /**
     * @return Builder<Licence>
     */
    public static function admin(): Builder
    {
        return Licence::withoutCompanyScope()
            ->join('companies', 'companies.id', '=', 'licences.company_id')
            ->join('branches', 'branches.id', '=', 'licences.branch_id')
            ->join('registers', 'registers.id', '=', 'licences.register_id')
            ->join('plans', 'plans.id', '=', 'licences.plan_id')
            ->select('licences.*')
            ->addSelect([
                'companies.name as company_name',
                'branches.name as branch_name',
                'registers.name as register_name',
                'registers.code as register_code',
                'plans.name as plan_name',
            ])
            ->with(['company', 'branch', 'register', 'plan']);
    }

    /**
     * Search by full licence key (exact, via its hash), last 4 characters, device id or name, or business name.
     *
     * @param  Builder<Licence>  $query
     */
    public static function search(Builder $query, string $term, bool $includeBusiness = true): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $key = LicenceKey::tryParse($term);
        if ($key !== null) {
            $query->whereIn('licences.key_hash', $key->hashCandidates());

            return;
        }

        $like = '%'.$term.'%';
        $last4 = LicenceKey::normalise($term);

        $query->where(function (Builder $q) use ($like, $last4, $includeBusiness): void {
            if ($last4 !== '' && strlen($last4) <= 4) {
                $q->orWhere('licences.key_last4', 'like', '%'.$last4.'%');
            }
            $q->orWhere('licences.device_id', 'like', $like)
                ->orWhere('licences.device_name', 'like', $like);

            if ($includeBusiness) {
                $q->orWhere('companies.name', 'like', $like);
            }
        });
    }

    /**
     * Keep licences whose effective status (LicenceState) is `$status`. Needs the joins from admin().
     *
     * @param  Builder<Licence>  $query
     */
    public static function whereEffectiveStatus(Builder $query, LicenceStatus $status, CarbonImmutable $now): void
    {
        $revoked = LicenceStatus::Revoked->value;
        $suspended = LicenceStatus::Suspended->value;

        match ($status) {
            LicenceStatus::Revoked => $query->where('licences.status', $revoked),
            LicenceStatus::Suspended => $query->where('licences.status', '!=', $revoked)->where(function (Builder $q) use ($suspended): void {
                $q->where('licences.status', $suspended)->orWhere(fn (Builder $w) => self::blockedByContext($w->getQuery()));
            }),
            default => self::dateStatus($query->whereNotIn('licences.status', [$revoked, $suspended])->where(fn (Builder $w) => self::openContext($w->getQuery())), $status, $now),
        };
    }

    /**
     * Count of licences per effective status for the filter menu.
     *
     * @param  Builder<Licence>  $base
     * @return array<string, int>
     */
    public static function countsByStatus(Builder $base, CarbonImmutable $now): array
    {
        $counts = [];

        foreach (LicenceStatus::cases() as $status) {
            $query = $base->clone();
            self::whereEffectiveStatus($query, $status, $now);
            $counts[$status->value] = $query->toBase()->getCountForPagination();
        }

        return $counts;
    }

    /**
     * @param  Builder<Licence>  $query
     */
    private static function dateStatus(Builder $query, LicenceStatus $status, CarbonImmutable $now): void
    {
        match ($status) {
            LicenceStatus::Issued => $query->whereNull('licences.activated_at'),
            LicenceStatus::Trial => $query->whereNotNull('licences.activated_at')->whereNull('licences.expires_at')->where('licences.ends_at', '>', $now),
            LicenceStatus::Active => $query->whereNotNull('licences.activated_at')->whereNotNull('licences.expires_at')->where('licences.ends_at', '>', $now),
            LicenceStatus::Grace => $query->whereNotNull('licences.activated_at')->where('licences.ends_at', '<=', $now)->where('licences.grace_ends_at', '>', $now),
            LicenceStatus::Expired => $query->whereNotNull('licences.activated_at')->where(fn (Builder $q) => $q->whereNull('licences.ends_at')->orWhere('licences.grace_ends_at', '<=', $now)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** The company, branch or till stops the licence (LicenceState steps 3-5). */
    private static function blockedByContext(QueryBuilder $query): void
    {
        $query->whereIn('companies.status', [CompanyStatus::Suspended->value, CompanyStatus::Cancelled->value])
            ->orWhereNotNull('companies.deleted_at')
            ->orWhere('branches.is_active', false)
            ->orWhereNotNull('branches.deleted_at')
            ->orWhere('registers.is_active', false)
            ->orWhereNotNull('registers.deleted_at');
    }

    /** Nothing in the company, branch or till stops the licence. */
    private static function openContext(QueryBuilder $query): void
    {
        $query->whereNotIn('companies.status', [CompanyStatus::Suspended->value, CompanyStatus::Cancelled->value])
            ->whereNull('companies.deleted_at')
            ->where('branches.is_active', true)
            ->whereNull('branches.deleted_at')
            ->where('registers.is_active', true)
            ->whereNull('registers.deleted_at');
    }
}
