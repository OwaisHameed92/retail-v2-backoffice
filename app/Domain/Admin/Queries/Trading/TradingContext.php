<?php

namespace App\Domain\Admin\Queries\Trading;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The drill-down of the admin trading dashboard (module 3.2): the chosen business (deleted ones included, their
 * figures still exist), the chosen shop (only one of that business; another business's shop is ignored) and the
 * business's shops for the shop picker. An unknown business id is a 404.
 */
final class TradingContext
{
    /**
     * @return array{company: array{id: string, name: string}|null, branch: array{id: string, name: string}|null, branches: list<array{id: string, name: string, code: string, active: bool}>}
     *
     * @throws ModelNotFoundException
     */
    public static function for(?string $companyId, ?string $branchId): array
    {
        if ($companyId === null) {
            return ['company' => null, 'branch' => null, 'branches' => []];
        }

        $company = Company::query()->withTrashed()->findOrFail($companyId, ['id', 'name']);
        $branches = Branch::withoutCompanyScope()->withTrashed()->where('company_id', $company->id)
            ->orderBy('name')->get(['id', 'name', 'code', 'is_active', 'deleted_at'])
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'active' => $b->is_active && $b->deleted_at === null])
            ->values()->all();
        $branch = collect($branches)->firstWhere('id', $branchId);

        return [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'branch' => $branch === null ? null : ['id' => $branch['id'], 'name' => $branch['name']],
            'branches' => $branches,
        ];
    }
}
