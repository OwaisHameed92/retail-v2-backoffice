<?php

namespace App\Domain\TillData\Sync;

use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Registry\EntityDefinition;
use Illuminate\Support\Facades\DB;

/**
 * The pull change feed's counter (module 2.5, contract §8, §19.3): one per company in `sync_hub_counters`. Every
 * hub-owned row whose `hub_version` is null (changed on the portal, or accepted from a till and not sent on yet) is
 * stamped with the next value; the pull sends rows with `hub_version > since`.
 *
 * Concurrency: a stamp first updates the company's counter row inside a transaction (an exclusive row lock on
 * MySQL, the write lock on SQLite) and holds it until commit, so two stamps never get the same number, and a higher
 * version never becomes visible before a lower one (a pull cannot skip a row that commits late). Numbers may have
 * gaps; they never repeat or go down.
 */
final class HubVersions
{
    /**
     * Hub-owned entities in apply order: a row's parents and the rows it names come first, so a till applying a
     * page in version order never meets an unknown id. Entities a later contract adds follow, by name.
     */
    private const ORDER = [
        'Unit', 'VatRate', 'TaxRule', 'Account', 'ExchangeRate', 'PaymentType', 'Reason', 'FixedAssetCategory',
        'Role', 'User', 'Department', 'Category', 'Supplier', 'Product', 'ProductUnit', 'BranchPrice', 'ProductBarcode',
        'ProductAlias', 'ProductSupplier', 'ProductRecall', 'MedicineClassification', 'PriceHistory',
        'PromotionRule', 'PromotionItem', 'PromotionCoupon', 'RebateAgreement', 'Customer', 'NewsTitle',
    ];

    private const CHUNK = 500;

    /** @var list<EntityDefinition>|null */
    private static ?array $entities = null;

    /**
     * Hub-owned entities with their own table, in apply order (keyed rows excluded until their pull is built).
     *
     * @return list<EntityDefinition>
     */
    public static function entities(): array
    {
        if (self::$entities !== null) {
            return self::$entities;
        }

        // Keyed rows (Setting, RolePermission, §10.3) have their own pull envelope: not in the feed yet (module 2.9 part B).
        $hub = array_filter(EntityRegistry::names(), function (string $name): bool {
            $def = EntityRegistry::get($name);

            return $def->isHubOwned() && ! $def->tenancy && ! $def->isKeyed();
        });
        $rank = array_flip(self::ORDER);
        usort($hub, fn (string $a, string $b) => [$rank[$a] ?? PHP_INT_MAX, $a] <=> [$rank[$b] ?? PHP_INT_MAX, $b]);

        return self::$entities = array_map(fn (string $name) => EntityRegistry::get($name), $hub);
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

        if (! $def->isHubOwned() || $def->tenancy || $def->isKeyed() || $ids === []) {
            return null;
        }

        return $this->locked($companyId, function (int $version) use ($companyId, $def, $ids): int {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $version = $this->assign($companyId, $def, $this->pending($companyId, $def, $chunk), $version);
            }

            return $version;
        });
    }

    /**
     * Stamps every unstamped hub-owned row of the company (rows accepted from tills, and any portal change whose
     * after-commit stamp did not run), parents first. Cheap when there are none: one indexed read per table.
     */
    public function stampPending(string $companyId): ?int
    {
        $waiting = array_filter(self::entities(), fn (EntityDefinition $def) => DB::table($def->table)
            ->where('company_id', $companyId)->whereNull('hub_version')->exists());

        if ($waiting === []) {
            return null;
        }

        return $this->locked($companyId, function (int $version) use ($companyId, $waiting): int {
            foreach ($waiting as $def) {
                $version = $this->assign($companyId, $def, $this->pending($companyId, $def, null), $version);
            }

            return $version;
        });
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
        return DB::table($def->table)
            ->where('company_id', $companyId)
            ->whereNull('hub_version')
            ->when($ids !== null, fn ($query) => $query->whereIn('id', (array) $ids))
            ->orderBy('updated_at')->orderBy('id')
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
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

            DB::update(
                "UPDATE {$table} SET hub_version = CASE id {$cases} END WHERE company_id = ? AND hub_version IS NULL AND id IN ({$in})",
                [...$bindings, $companyId, ...$chunk],
            );
        }

        return $version;
    }
}
