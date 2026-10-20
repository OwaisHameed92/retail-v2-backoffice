<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The shops a handheld user may be given on the portal: the current business's open shops (only their own shop for a
 * one-shop portal user).
 */
final class HandheldShops
{
    /**
     * @return Collection<int, Branch>
     */
    public static function options(): Collection
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return Branch::query()->where('is_active', true)
            ->when($restricted !== null, fn ($query) => $query->whereKey($restricted))
            ->orderBy('name')->get(['id', 'company_id', 'name', 'code']);
    }

    /**
     * @throws ValidationException
     */
    public static function openShop(string $branchId): Branch
    {
        $shop = self::options()->firstWhere('id', $branchId);

        if (! $shop instanceof Branch) {
            throw ValidationException::withMessages(['branch_id' => 'Choose one of your open shops.']);
        }

        return $shop;
    }
}
