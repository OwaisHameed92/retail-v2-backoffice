<?php

namespace App\Domain\TillHealth\Queries;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;

/**
 * "Shops and tills" on the tenant dashboard (module 2.7, read only): the current business's active shops (or the
 * one picked in the branch switcher) with their tills and health. Tenant code: branches and registers through the
 * company scope; the health is worked out for this business only. No install ids.
 */
final class ShopsStatus
{
    /**
     * @return array{shops: list<array<string, mixed>>, thresholds: array<string, int|string>}
     */
    public static function for(Company $company, ?string $branchId, CarbonImmutable $now): array
    {
        $health = CompanyHealth::for($company->id, $now, admin: false);

        $branches = Branch::query()->active()
            ->when($branchId !== null, fn ($query) => $query->whereKey($branchId))
            ->with(['registers' => fn ($query) => $query->where('is_active', true)->orderBy('code')])
            ->orderBy('name')
            ->get();

        $shops = $branches->map(fn (Branch $branch) => [
            'id' => $branch->id,
            'name' => $branch->name,
            'code' => $branch->code,
            'health' => $health['branches'][$branch->id] ?? null,
            'tills' => $branch->registers->map(fn (Register $register) => [
                'id' => $register->id,
                'name' => $register->name,
                'code' => $register->code,
                'isMainTill' => $register->is_main_till,
                'health' => $health['tills'][$register->id] ?? null,
            ])->values()->all(),
        ])->values()->all();

        return ['shops' => $shops, 'thresholds' => $health['thresholds']];
    }
}
