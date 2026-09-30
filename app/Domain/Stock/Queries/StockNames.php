<?php

namespace App\Domain\Stock\Queries;

use App\Domain\Stock\Data\StockFilters;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Supplier;

/**
 * Names and filter options for the stock screens (module 5.1), in the current company's scope. A one-shop user is
 * offered only their own shop.
 */
final class StockNames
{
    /** @return array<string, string> shop id => name */
    public static function shops(): array
    {
        return Branch::query()->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    public static function options(StockFilters $f): array
    {
        $option = fn ($id, $name) => ['value' => (string) $id, 'label' => (string) $name !== '' ? (string) $name : 'Unnamed'];

        return [
            'shops' => Branch::query()->when($f->shopLocked, fn ($q) => $q->whereKey($f->shop))->orderBy('name')->get(['id', 'name'])
                ->map(fn (Branch $b) => $option($b->id, $b->name))->values()->all(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $d) => $option($d->id, $d->name))->values()->all(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Supplier $s) => $option($s->id, $s->name))->values()->all(),
        ];
    }
}
