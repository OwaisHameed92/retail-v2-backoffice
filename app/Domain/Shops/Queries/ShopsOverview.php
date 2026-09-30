<?php

namespace App\Domain\Shops\Queries;

use App\Domain\Licensing\Models\Licence;
use App\Domain\Shops\Data\ShopRequest;
use App\Domain\Shops\Support\TillLicenceView;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillHealth\Queries\CompanyHealth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Shops and tills, the list (module 4.7): the business's shops (a one-shop user: theirs only) with address, open or
 * closed, tills used of allowed, a licence summary and live health (2.7). Tenant code: everything through the company
 * scope; a fixed number of queries whatever the number of shops.
 */
final class ShopsOverview
{
    /**
     * @return array<string, mixed>
     */
    public static function for(CurrentCompany $tenancy, CarbonImmutable $now): array
    {
        $company = $tenancy->require();
        $restricted = $tenancy->restrictedBranchId();
        $health = CompanyHealth::for($company->id, $now, admin: false);

        $branches = Branch::query()
            ->when($restricted !== null, fn ($query) => $query->whereKey($restricted))
            ->withCount(['registers as tills_active' => fn ($query) => $query->where('is_active', true)])
            ->orderByDesc('is_active')->orderBy('name')
            ->get();

        $licences = self::liveLicences($company, $branches->modelKeys())->groupBy('branch_id');

        $shops = $branches->map(function (Branch $branch) use ($licences, $health, $now) {
            /** @var Collection<int, Licence> $own */
            $own = $licences->get($branch->id, collect());
            $views = $own->map(fn (Licence $licence) => TillLicenceView::of($licence, $now));

            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'isActive' => $branch->is_active,
                'address' => self::oneLine($branch->address, $branch->town, $branch->postcode),
                'phone' => $branch->phone,
                'tillsActive' => (int) $branch->getAttribute('tills_active'),
                'tillsAllowed' => $branch->max_registers,
                'licence' => [
                    'kind' => $branch->licence_kind->value,
                    'statuses' => $views->groupBy('status')->map(fn (Collection $group) => [
                        'status' => $group->first()['status'],
                        'label' => $group->first()['statusLabel'],
                        'count' => $group->count(),
                    ])->values()->all(),
                    'nextEndsAt' => $views->pluck('endsAt')->filter()->sort()->first(),
                    'needsAttention' => $views->contains(fn (array $view) => ! $view['canTrade'] && $view['status'] !== 'issued'),
                ],
                'health' => $health['branches'][$branch->id] ?? null,
            ];
        })->values()->all();

        return [
            'shops' => $shops,
            'summary' => self::summary($shops, $company),
            'requests' => ShopRequests::recent(),
            'requestOptions' => [
                'shops' => $branches->where('is_active', true)->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code])->values()->all(),
                'maxTills' => ShopRequest::MAX_TILLS,
                'canAskForShop' => $restricted === null,
            ],
            'can' => [
                'manage' => $tenancy->can(Ability::ShopsManage),
                'manageBusiness' => $tenancy->can(Ability::BusinessManage) && $restricted === null,
            ],
            'restricted' => $restricted !== null,
            'thresholds' => $health['thresholds'],
        ];
    }

    /**
     * The live licences of these shops with what LicenceState needs, in one query each.
     *
     * @param  list<string>  $branchIds
     * @return Collection<int, Licence>
     */
    public static function liveLicences(Company $company, array $branchIds): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return Licence::query()->live()->whereIn('branch_id', $branchIds)
            ->with(['branch', 'register', 'plan'])
            ->get()
            ->each(fn (Licence $licence) => $licence->setRelation('company', $company));
    }

    /**
     * @param  list<array<string, mixed>>  $shops
     * @return array<string, mixed>
     */
    private static function summary(array $shops, Company $company): array
    {
        $open = array_values(array_filter($shops, fn (array $shop) => $shop['isActive']));
        $online = array_sum(array_map(fn (array $shop) => $shop['health']['tillsOnline'] ?? 0, $open));
        $ends = array_values(array_filter(array_map(fn (array $shop) => $shop['licence']['nextEndsAt'], $open)));
        sort($ends);

        return [
            'shops' => count($open),
            'shopsAllowed' => $company->max_branches,
            'tills' => array_sum(array_map(fn (array $shop) => $shop['tillsActive'], $open)),
            'tillsAllowed' => array_sum(array_map(fn (array $shop) => $shop['tillsAllowed'], $open)),
            'tillsOnline' => $online,
            'nextEndsAt' => $ends[0] ?? null,
            'attention' => count(array_filter($open, fn (array $shop) => $shop['licence']['needsAttention'])),
        ];
    }

    public static function oneLine(?string ...$parts): ?string
    {
        $line = implode(', ', array_filter(array_map(fn (?string $part) => $part === null ? null : trim(preg_replace('/\s*\R\s*/', ', ', $part) ?? $part), $parts)));

        return $line === '' ? null : $line;
    }
}
