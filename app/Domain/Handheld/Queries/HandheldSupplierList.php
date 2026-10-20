<?php

namespace App\Domain\Handheld\Queries;

use App\Domain\Handheld\Support\PdaPage;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

/**
 * `GET /api/pda/suppliers`: the business's active suppliers (company-wide, like on the till) for the order's supplier
 * picker; `search` = part of the name, code or phone. Ordered by name.
 */
final class HandheldSupplierList
{
    /**
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public function handle(?string $search, int $perPage, int $page): array
    {
        $search = trim((string) $search);

        $query = Supplier::query()->where('is_active', true)
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('name')->orderBy('id');

        return PdaPage::of($query, $perPage, $page, fn ($suppliers) => $suppliers->map(fn (Supplier $s) => [
            'id' => $s->id,
            'supplier_name' => $s->name,
            'supplier_code' => $s->code,
            'contact_name' => $s->contact_name,
            'phone_number' => $s->phone,
            'email' => $s->email,
            'lead_days' => $s->default_lead_days,
        ])->values()->all());
    }
}
