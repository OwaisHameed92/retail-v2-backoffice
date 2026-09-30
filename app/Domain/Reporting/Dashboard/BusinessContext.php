<?php

namespace App\Domain\Reporting\Dashboard;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;

/**
 * Which shop and till the business dashboard shows (module 3.3). The shop is the top-bar switcher's (so the whole
 * portal agrees), or — for a user limited to one shop — always theirs, even when that shop was closed or removed
 * (then it shows nothing: fail closed, never every shop). The till comes from the URL and only counts when it is
 * one of that shop's. Tenant code: branches and registers through the company scope.
 */
final class BusinessContext
{
    /**
     * @return array{restricted: bool, branch: array{id: string, name: string, code: string}|null, till: array{id: string, label: string}|null, tills: list<array{id: string, code: string, name: string, active: bool}>}
     */
    public static function for(CurrentCompany $current, ?Branch $switcherBranch, ?string $tillId): array
    {
        $restricted = $current->restrictedBranchId();
        $branchId = $restricted ?? $switcherBranch?->id;

        if ($branchId === null) {
            return ['restricted' => false, 'branch' => null, 'till' => null, 'tills' => []];
        }

        $branch = Branch::query()->withTrashed()->find($branchId, ['id', 'name', 'code']);
        $tills = Register::query()->withTrashed()->where('branch_id', $branchId)->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active', 'deleted_at'])
            ->map(fn (Register $r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'active' => $r->is_active && $r->deleted_at === null])
            ->values()->all();
        $till = collect($tills)->firstWhere('id', $tillId);

        return [
            'restricted' => $restricted !== null,
            'branch' => ['id' => $branchId, 'name' => $branch->name ?? 'Your shop', 'code' => $branch->code ?? ''],
            'till' => $till === null ? null : ['id' => $till['id'], 'label' => trim($till['code'].' – '.$till['name'], ' –')],
            'tills' => $tills,
        ];
    }
}
