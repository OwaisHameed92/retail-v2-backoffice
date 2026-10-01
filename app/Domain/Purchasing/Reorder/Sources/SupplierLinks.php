<?php

namespace App\Domain\Purchasing\Reorder\Sources;

use App\Domain\Shared\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Which supplier each product is ordered from (module 6.4): the preferred link, else the cheapest per unit, else the
 * first by supplier name. Only open suppliers. One supplier per product, so a product is never suggested twice.
 * Unit cost ex VAT = the link's case cost ÷ case size (else the product's cost price, filled in by the caller).
 *
 * @phpstan-type Link array{supplierId: string, supplierName: string, caseQty: int, unitCost: string|null, supplierSku: string|null, minimumOrder: string|null, defaultLeadDays: int|null}
 */
final class SupplierLinks
{
    /**
     * @return array<string, Link> product id → its supplier
     */
    public static function chosen(string $companyId, ?string $supplierId = null): array
    {
        $rows = DB::table('product_suppliers as ps')
            ->join('suppliers as s', fn ($j) => $j->on('s.id', '=', 'ps.supplier_id')->where('s.company_id', $companyId)->whereNull('s.deleted_at'))
            ->where('ps.company_id', $companyId)->whereNull('ps.deleted_at')
            ->where(fn ($q) => $q->where('s.is_active', true)->orWhereNull('s.is_active'))
            ->get(['ps.product_id', 'ps.supplier_id', 'ps.case_qty', 'ps.case_cost', 'ps.supplier_sku', 'ps.is_preferred', 's.name',
                's.minimum_order_value', 's.default_lead_days']);

        $best = [];

        foreach ($rows as $row) {
            $caseQty = max(1, (int) ($row->case_qty ?? 1));
            $unitCost = $row->case_cost !== null && Money::compare($row->case_cost, '0') > 0
                ? Money::round(bcdiv(Money::parse($row->case_cost), (string) $caseQty, 8), 4)
                : null;
            $candidate = [
                'supplierId' => (string) $row->supplier_id,
                'supplierName' => (string) ($row->name ?? '') !== '' ? (string) $row->name : 'Unnamed supplier',
                'caseQty' => $caseQty,
                'unitCost' => $unitCost,
                'supplierSku' => $row->supplier_sku !== null && $row->supplier_sku !== '' ? (string) $row->supplier_sku : null,
                'minimumOrder' => $row->minimum_order_value !== null ? Money::normalise($row->minimum_order_value, 2) : null,
                'defaultLeadDays' => $row->default_lead_days !== null ? (int) $row->default_lead_days : null,
                'preferred' => (bool) $row->is_preferred,
            ];
            $product = (string) $row->product_id;

            if (! isset($best[$product]) || self::better($candidate, $best[$product])) {
                $best[$product] = $candidate;
            }
        }

        $chosen = [];

        foreach ($best as $product => $link) {
            if ($supplierId === null || $link['supplierId'] === $supplierId) {
                unset($link['preferred']);
                $chosen[$product] = $link;
            }
        }

        return $chosen;
    }

    /**
     * @param  array{supplierName: string, unitCost: string|null, preferred: bool}  $a
     * @param  array{supplierName: string, unitCost: string|null, preferred: bool}  $b
     */
    private static function better(array $a, array $b): bool
    {
        if ($a['preferred'] !== $b['preferred']) {
            return $a['preferred'];
        }

        if ($a['unitCost'] !== null && $b['unitCost'] !== null && Money::compare($a['unitCost'], $b['unitCost']) !== 0) {
            return Money::compare($a['unitCost'], $b['unitCost']) < 0;
        }

        if (($a['unitCost'] === null) !== ($b['unitCost'] === null)) {
            return $a['unitCost'] !== null;
        }

        return strcasecmp($a['supplierName'], $b['supplierName']) < 0;
    }
}
