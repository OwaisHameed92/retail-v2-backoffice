<?php

namespace App\Domain\Demo\Actions;

use App\Domain\Demo\Support\DemoPush;
use App\Domain\Reporting\Demo\DemoShopDay;
use App\Domain\Reporting\Support\DirtyDays;
use App\Domain\TillData\EntityRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Removes the rows the demo made for one business, and only those: every row recorded in the push ledger under the
 * demo streams ("demo-seed", "demo-sales"), whatever its entity, plus the demo staff's shop links. A till's own
 * rows (other streams) and every other business are never touched. The shop-days of the sales it removes are marked
 * dirty first, so their `rpt_*` rows are rebuilt (emptied) rather than left behind.
 */
final class ForgetDemoData
{
    public const STREAMS = [DemoPush::STREAM, DemoShopDay::STREAM];

    private const CHUNK = 500;

    /**
     * @param  list<string>  $streams
     * @return array{rows: int, oldestDay: string|null}
     */
    public function handle(string $companyId, array $streams = self::STREAMS): array
    {
        $ledger = DB::table('sync_applied_changes')->where('company_id', $companyId)->whereIn('stream', $streams);
        $entities = (clone $ledger)->distinct()->pluck('entity')->map(fn ($e) => (string) $e)->all();
        $rows = 0;
        $oldest = null;

        foreach ($entities as $entity) {
            if (! EntityRegistry::has($entity) || EntityRegistry::isLocal($entity)) {
                continue;
            }

            $table = EntityRegistry::get($entity)->table;
            $ids = (clone $ledger)->where('entity', $entity)->distinct()->pluck('entity_id')->map(fn ($id) => (string) $id)->all();

            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                if ($entity === 'Sale') {
                    $day = DirtyDays::markSales($companyId, $chunk);
                    $oldest = $day !== null && ($oldest === null || $day < $oldest) ? $day : $oldest;
                }

                if ($entity === 'User') {
                    DB::table('till_user_branches')->where('company_id', $companyId)->whereIn('till_user_id', $chunk)->delete();
                }

                $rows += DB::table($table)->where('company_id', $companyId)->whereIn('id', $chunk)->delete();
            }
        }

        $ledger->delete();

        return ['rows' => $rows, 'oldestDay' => $oldest];
    }

    /**
     * Demo sales made before the demo had a catalogue (their lines name no real product): a business has them when
     * the "demo-sales" stream holds rows but "demo-seed" never stored a product.
     */
    public static function hasProductlessSales(string $companyId): bool
    {
        $ledger = DB::table('sync_applied_changes')->where('company_id', $companyId);

        return (clone $ledger)->where('stream', DemoShopDay::STREAM)->exists()
            && ! (clone $ledger)->where('stream', DemoPush::STREAM)->where('entity', 'Product')->exists();
    }
}
