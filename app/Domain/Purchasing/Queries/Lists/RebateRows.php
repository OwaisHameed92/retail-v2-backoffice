<?php

namespace App\Domain\Purchasing\Queries\Lists;

use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\RebateAgreement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Supplier rebate agreements (company-wide) with what the shops have accrued against them (`RebateAccrual`, the
 * tills' rows). "Unsettled" is accrued but not yet turned into a credit note. A shop filter narrows the accruals.
 *
 * @extends DocumentRows<RebateAgreement>
 */
final class RebateRows extends DocumentRows
{
    private ?string $shop = null;

    protected function base(): Builder
    {
        return RebateAgreement::query();
    }

    public function scoped(?string $shop): Builder
    {
        $this->shop = $shop;

        return $this->base();
    }

    public function statuses(): array
    {
        return ['active', 'inactive'];
    }

    protected function status(Builder $query, string $status): void
    {
        $query->where('is_active', $status === 'active');
    }

    protected function sortable(): array
    {
        return ['name', 'period_to', 'rate'];
    }

    protected function defaultSort(): array
    {
        return ['period_to', 'desc'];
    }

    protected function search(Builder $query, string $like): void
    {
        $query->where('name', 'like', $like)->orWhere('note', 'like', $like);
        $this->supplierMatch($query, $like);
    }

    protected function rows(array $rows, PurchasingNames $names): array
    {
        $sums = $this->accruals()->whereIn('agreement_id', array_map(fn ($r) => $r->id, $rows))->groupBy('agreement_id')
            ->selectRaw('agreement_id, sum(amount) as accrued, sum(case when settled_at is null then amount else 0 end) as unsettled')
            ->get()->keyBy('agreement_id');

        return array_map(fn (RebateAgreement $a) => [
            'id' => $a->id,
            'reference' => $a->name ?: 'Rebate',
            'status' => $a->is_active ? 'active' : 'inactive',
            'supplier' => $names->supplier($a->supplier_id),
            'scope' => $a->scope?->value,
            'basis' => $a->basis?->value,
            'rate' => $a->rate,
            'from' => $a->period_from->format('Y-m-d'),
            'to' => $a->period_to->format('Y-m-d'),
            'threshold' => $a->threshold,
            'gross' => Money::normalise($sums->get($a->id)->accrued ?? 0),
            'unsettled' => Money::normalise($sums->get($a->id)->unsettled ?? 0),
        ], $rows);
    }

    public function stats(Builder $query): array
    {
        $accruals = $this->accruals()->whereIn('agreement_id', (clone $query)->select('id'));

        return [
            self::stat('Active agreements', (clone $query)->where('is_active', true)->count(), 'count', 'primary'),
            self::stat('Accrued', Money::normalise((clone $accruals)->sum('amount') ?: 0), 'money', 'success', 'Earned from purchases'),
            self::stat('Not yet credited', Money::normalise((clone $accruals)->whereNull('settled_at')->sum('amount') ?: 0), 'money', 'warning', 'Claim from the supplier'),
        ];
    }

    private function accruals(): \Illuminate\Database\Query\Builder
    {
        return DB::table('rebate_accruals')->where('company_id', app(CurrentCompany::class)->id())->whereNull('deleted_at')
            ->when($this->shop !== null, fn ($q) => $q->where('branch_id', $this->shop));
    }
}
