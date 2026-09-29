<?php

namespace App\Domain\TillData\Sync;

use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Registry\EntityDefinition;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The pull change feed's counter (module 2.5, contract §8, §19.3): one per company in `sync_hub_counters`. Every
 * row of the feed whose `hub_version` is null (changed on the portal, or accepted from a till and not sent on yet) is
 * stamped with the next value; the pull sends rows with `hub_version > since`. The feed (module 2.9B): hub-owned rows
 * (keyed Setting / RolePermission included), relayed branch rows (§10.2), head-office orders drafted on the portal
 * (§10.6) and portal edits of Company / Branch (§6.1).
 *
 * Concurrency: a stamp first updates the company's counter row inside a transaction (an exclusive row lock on
 * MySQL, the write lock on SQLite) and holds it until commit, so two stamps never get the same number, and a higher
 * version never becomes visible before a lower one (a pull cannot skip a row that commits late). Numbers may have
 * gaps; they never repeat or go down.
 */
final class HubVersions
{
    /**
     * Pulled entities in apply order: a row's parents and the rows it names come first, so a till applying a page in
     * version order never meets an unknown id (a line straight after its transfer or order, §10.2, §10.6). Entities a
     * later contract adds follow, by name.
     */
    private const ORDER = [
        'Company', 'Branch', 'Unit', 'VatRate', 'TaxRule', 'Account', 'ExchangeRate', 'PaymentType', 'Reason',
        'FixedAssetCategory', 'Role', 'RolePermission', 'User', 'Setting', 'Department', 'Category', 'Supplier',
        'Product', 'ProductUnit', 'BranchPrice', 'ProductBarcode', 'ProductAlias', 'ProductSupplier', 'ProductRecall',
        'MedicineClassification', 'PriceHistory', 'PromotionRule', 'PromotionItem', 'PromotionCoupon',
        'RebateAgreement', 'Customer', 'NewsTitle', 'StockTransfer', 'StockTransferLine', 'StockTransferReceipt',
        'StockTransferReceiptLine', 'CustomerTransaction', 'PurchaseOrder', 'PurchaseOrderLine',
    ];

    /** Tenancy rows the portal edits and sends (§6.1). Register is not sent. */
    private const TENANCY = ['Company', 'Branch'];

    private const CHUNK = 500;

    /** @var list<EntityDefinition>|null */
    private static ?array $entities = null;

    /** @var list<EntityDefinition>|null */
    private static ?array $feed = null;

    /**
     * Hub-owned entities with their own table (keyed Setting / RolePermission included), in apply order.
     *
     * @return list<EntityDefinition>
     */
    public static function entities(): array
    {
        return self::$entities ??= array_values(array_filter(self::feed(), fn (EntityDefinition $def) => $def->isHubOwned() && ! $def->tenancy));
    }

    /**
     * Everything the pull can send, in apply order: hub-owned rows, the branch-owned rows the portal relays or drafts
     * (OwnershipRules), and the portal's edits of Company and Branch.
     *
     * @return list<EntityDefinition>
     */
    public static function feed(): array
    {
        if (self::$feed !== null) {
            return self::$feed;
        }

        $names = array_filter(EntityRegistry::names(), function (string $name): bool {
            $def = EntityRegistry::get($name);

            return $def->tenancy ? in_array($name, self::TENANCY, true) : ($def->isHubOwned() || $def->copy !== null);
        });
        $rank = array_flip(self::ORDER);
        usort($names, fn (string $a, string $b) => [$rank[$a] ?? PHP_INT_MAX, $a] <=> [$rank[$b] ?? PHP_INT_MAX, $b]);

        return self::$feed = array_map(fn (string $name) => EntityRegistry::get($name), $names);
    }

    public static function inFeed(EntityDefinition $def): bool
    {
        return in_array($def->entity, array_map(fn (EntityDefinition $d) => $d->entity, self::feed()), true);
    }

    /** The column holding the company: `companies` is the company itself. */
    public static function companyColumn(EntityDefinition $def): string
    {
        return $def->entity === 'Company' ? 'id' : 'company_id';
    }

    /** The last version handed out for the company (0 before the first). */
    public function current(string $companyId): int
    {
        return (int) DB::table('sync_hub_counters')->where('company_id', $companyId)->value('last_version');
    }

    /**
     * Stamps the given rows of one entity that are still unstamped. Returns the highest version given, or null.
     *
     * @param  list<string>  $ids
     */
    public function stamp(string $companyId, string $entity, array $ids): ?int
    {
        $def = EntityRegistry::get($entity);

        if ($ids === [] || ! self::inFeed($def)) {
            return null;
        }

        return $this->locked($companyId, function (int $version) use ($companyId, $def, $ids): int {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $version = $this->assign($companyId, $def, $this->pending($companyId, $def, $chunk), $version);
            }

            return BranchDepartures::assign($companyId, $version);
        });
    }

    /**
     * Stamps every unstamped row of the feed (rows accepted from tills, and any portal change whose after-commit
     * stamp did not run), parents first. Cheap when there are none: one indexed read per table.
     */
    public function stampPending(string $companyId): ?int
    {
        $waiting = array_filter(self::feed(), fn (EntityDefinition $def) => $this->pendingQuery($companyId, $def)->exists());

        if ($waiting === [] && ! BranchDepartures::pending($companyId)) {
            return null;
        }

        return $this->locked($companyId, function (int $version) use ($companyId): int {
            // Every table again, in order: stamping a dispatched transfer or a receipt re-queues its lines after it.
            foreach (self::feed() as $def) {
                $ids = $this->pending($companyId, $def, null);
                $this->requeueLines($companyId, $def, $ids);
                $version = $this->assign($companyId, $def, $ids, $version);
            }

            return BranchDepartures::assign($companyId, $version);   // shops a row moved away from (ANSWERS-b A.3)
        });
    }

    /**
     * §10.2: every line goes with its transfer ("in the same pull page as the header or straight after it"). Lines
     * stamped while the transfer was only requested (not relayed then) are stamped again after the dispatched
     * header; a receipt's lines after the receipt.
     *
     * @param  list<string>  $ids
     */
    private function requeueLines(string $companyId, EntityDefinition $def, array $ids): void
    {
        [$table, $column] = match ($def->entity) {
            'StockTransfer' => ['stock_transfer_lines', 'transfer_id'],
            'StockTransferReceipt' => ['stock_transfer_receipt_lines', 'receipt_id'],
            default => [null, null],
        };

        if ($table === null) {
            return;
        }

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            DB::table($table)->where('company_id', $companyId)->whereIn($column, $chunk)->whereNotNull('hub_version')->update(['hub_version' => null]);
        }
    }

    /**
     * @param  callable(int): int  $assign  gets the last version, returns the new last version
     */
    private function locked(string $companyId, callable $assign): ?int
    {
        DB::table('sync_hub_counters')->insertOrIgnore(['company_id' => $companyId, 'last_version' => 0, 'updated_at' => now('UTC')]);

        return DB::transaction(function () use ($companyId, $assign): ?int {
            $counter = DB::table('sync_hub_counters')->where('company_id', $companyId);
            $counter->clone()->update(['last_version' => DB::raw('last_version')]);   // takes the lock first
            $last = (int) $counter->clone()->value('last_version');
            $new = $assign($last);

            if ($new === $last) {
                return null;
            }

            $counter->clone()->update(['last_version' => $new, 'updated_at' => now('UTC')]);

            return $new;
        }, 3);
    }

    /**
     * Ids still unstamped, oldest change first.
     *
     * @param  list<string>|null  $ids  null = all of the company's
     * @return list<string>
     */
    private function pending(string $companyId, EntityDefinition $def, ?array $ids): array
    {
        return $this->pendingQuery($companyId, $def)
            ->when($ids !== null, fn ($query) => $query->whereIn('id', (array) $ids))
            ->orderBy('updated_at')->orderBy('id')
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** Unstamped rows; of a drafted table only the portal's own drafts (a shop's rows are never in the feed). */
    private function pendingQuery(string $companyId, EntityDefinition $def): Builder
    {
        return DB::table($def->table)
            ->where(self::companyColumn($def), $companyId)
            ->whereNull('hub_version')
            ->when($def->copy === 'draft', fn (Builder $query) => $query->whereNotNull('hub_drafted_at')->whereNull('origin_branch_id'));
    }

    /**
     * @param  list<string>  $ids
     */
    private function assign(string $companyId, EntityDefinition $def, array $ids, int $version): int
    {
        $table = DB::getQueryGrammar()->wrapTable($def->table);

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $bindings = [];

            foreach ($chunk as $id) {
                array_push($bindings, $id, ++$version);
            }

            $cases = implode(' ', array_fill(0, count($chunk), 'WHEN ? THEN ?'));
            $in = implode(', ', array_fill(0, count($chunk), '?'));

            $company = DB::getQueryGrammar()->wrap(self::companyColumn($def));

            DB::update(
                "UPDATE {$table} SET hub_version = CASE id {$cases} END WHERE {$company} = ? AND hub_version IS NULL AND id IN ({$in})",
                [...$bindings, $companyId, ...$chunk],
            );
        }

        return $version;
    }
}
