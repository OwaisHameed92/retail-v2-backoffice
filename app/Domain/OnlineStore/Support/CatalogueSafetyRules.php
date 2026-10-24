<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\TillData\Enums\ProductRecallStatus;
use App\Domain\TillData\Models\Product;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Two more rules for what the online store shows (online store audit OSA-4, OSA-23), merged into
 * OnlineCatalogueQuery's rules, and (OS-11) `noName`: a till name with fewer than 2 letters or digits (",", "-").
 * Read only on the till's tables, filtered on the business explicitly.
 *
 * - `recalled` (OSA-4): a product with a recall (`product_recalls`, not deleted) that this shop has not closed
 *   (`product_recall_branch_states`: no closed row for the shop = still open there, as RecallShops reads it).
 * - `unavailable` (OSA-23): the till's availability window. The contract types `availabilityFrom` / `availabilityTo`
 *   as free strings; a value starting with a date ("2026-12-01", "2026-12-01T00:00:00Z") is read as the first / last
 *   day on sale (shop date), anything else (empty, a time of day) is ignored. A product tied to a seasonal event
 *   (`availability_seasonal_event_id`, an active event for the business or this shop) is on sale only from the
 *   event's first to its last day.
 */
final class CatalogueSafetyRules
{
    public const REASONS = [
        'recalled' => 'Under recall',
        'unavailable' => 'Not on sale now (availability dates)',
        'noName' => 'No proper name on the till',
    ];

    /** Punctuation and spaces left out when counting a name's letters and digits (`noName`). */
    private const NAME_NOISE = [' ', ',', '.', '-', '_', '/', '\\', '*', '#', '+', '&', '(', ')', "'", '"', '!', '?', ':', ';', '=', '~', '|'];

    /**
     * @return array<string, Closure(Builder<Product>): mixed>
     */
    public static function rules(OnlineStoreShop $shop, ?CarbonImmutable $at): array
    {
        $companyId = $shop->company_id;
        $today = ($at ?? CarbonImmutable::now())->setTimezone(OnlineStoreSwitch::timezone())->format('Y-m-d');
        $dated = fn (string $column) => "{$column} LIKE '____-__-__%'";

        $letters = 'TRIM(COALESCE(products.name, \'\'))';

        foreach (self::NAME_NOISE as $char) {
            $letters = "REPLACE({$letters}, ?, '')";
        }

        return [
            // OS-11: a product named "," or "-" on the till is never shown (fewer than 2 letters or digits).
            'noName' => fn (Builder $q) => $q->whereRaw("LENGTH({$letters}) >= 2", self::NAME_NOISE),
            'recalled' => fn (Builder $q) => $q->whereNotExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('product_recalls as prc')
                ->whereColumn('prc.product_id', 'products.id')->where('prc.company_id', $companyId)->whereNull('prc.deleted_at')
                ->whereNotExists(fn (QueryBuilder $c) => $c->selectRaw('1')->from('product_recall_branch_states as prs')
                    ->whereColumn('prs.recall_id', 'prc.id')->where('prs.company_id', $companyId)->where('prs.branch_id', $shop->branch_id)
                    ->whereNull('prs.deleted_at')->where('prs.status', ProductRecallStatus::Closed->value))),
            'unavailable' => fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w->whereNull('products.availability_from')->orWhereRaw('NOT ('.$dated('products.availability_from').')')
                    ->orWhereRaw('SUBSTR(products.availability_from, 1, 10) <= ?', [$today]))
                ->where(fn (Builder $w) => $w->whereNull('products.availability_to')->orWhereRaw('NOT ('.$dated('products.availability_to').')')
                    ->orWhereRaw('SUBSTR(products.availability_to, 1, 10) >= ?', [$today]))
                ->where(fn (Builder $w) => $w->whereNull('products.availability_seasonal_event_id')
                    ->orWhereNotExists(fn (QueryBuilder $s) => self::event($s, $companyId, $shop->branch_id))
                    ->orWhereExists(fn (QueryBuilder $s) => self::event($s, $companyId, $shop->branch_id)
                        ->where('sev.starts_on', '<=', $today)->where('sev.ends_on', '>=', $today))),
        ];
    }

    private static function event(QueryBuilder $query, string $companyId, string $branchId): QueryBuilder
    {
        return $query->selectRaw('1')->from('seasonal_events as sev')
            ->whereColumn('sev.id', 'products.availability_seasonal_event_id')->where('sev.company_id', $companyId)
            ->whereNull('sev.deleted_at')->where(fn (QueryBuilder $w) => $w->whereNull('sev.is_active')->orWhere('sev.is_active', true))
            ->where(fn (QueryBuilder $w) => $w->whereNull('sev.branch_id')->orWhere('sev.branch_id', $branchId))
            ->whereNotNull('sev.starts_on')->whereNotNull('sev.ends_on');
    }
}
