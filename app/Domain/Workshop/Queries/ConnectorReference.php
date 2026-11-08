<?php

namespace App\Domain\Workshop\Queries;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\VatRate;
use App\Domain\Workshop\Models\WorkshopConnection;
use Carbon\CarbonImmutable;

/**
 * `GET /api/Stores` and `GET /api/TaxRates` (WORKSHOP-BIKEBOOK.md §3.1, §3.2).
 */
final class ConnectorReference
{
    /**
     * One store: the connected shop.
     *
     * @return list<array{id: string, name: string, address: string}>
     */
    public function stores(WorkshopConnection $connection): array
    {
        $branch = Branch::withoutCompanyScope()->whereKey($connection->branch_id)->first();

        if ($branch === null) {
            return [];
        }

        $address = implode(', ', array_filter(array_map(fn ($part) => trim((string) $part), [$branch->address, $branch->town, $branch->postcode])));

        return [['id' => $branch->id, 'name' => $branch->name, 'address' => $address]];
    }

    /**
     * The business's VAT rates in force today, exactly one `isDefault` (the rate marked default, else the highest).
     *
     * @return list<array{id: string, name: string, rate: float, isDefault: bool}>
     */
    public function taxRates(): array
    {
        $today = CarbonImmutable::now('UTC')->format('Y-m-d');
        $rates = VatRate::query()->where('effective_from', '<=', $today.' 23:59:59')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->orderByDesc('is_default')->orderByDesc('percentage')->orderBy('name')->get();
        $default = $rates->first()?->id;

        return $rates->map(fn (VatRate $rate) => [
            'id' => $rate->id,
            'name' => $rate->name,
            'rate' => (float) $rate->percentage,
            'isDefault' => $rate->id === $default,
        ])->values()->all();
    }

    /** An active VAT rate of the business, by id. */
    public function activeRate(string $id): ?VatRate
    {
        $today = CarbonImmutable::now('UTC')->format('Y-m-d');

        return VatRate::query()->whereKey($id)->where('effective_from', '<=', $today.' 23:59:59')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->first();
    }
}
