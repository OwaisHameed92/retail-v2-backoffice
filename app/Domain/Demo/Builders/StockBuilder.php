<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\DemoStaff;
use App\Domain\Demo\Support\StockBook;
use App\Domain\Reporting\Demo\DemoShop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;

/**
 * Each shop's stock: what is on hand per product (most between min and max, some low, some out, a few negative),
 * the movements that led there (the last three days of till sales plus everything in the StockBook) with a
 * consistent before → after chain, FIFO cost layers from the latest deliveries and dated batches for short-life
 * lines (some close to their date, a few past it) with the date checks staff made on them.
 */
final class StockBuilder
{
    /** Days of till sales that get their own stock movement. */
    public const SALE_MOVEMENT_DAYS = 3;

    public function handle(DemoBusiness $b, DemoPush $push, StockBook $book): void
    {
        $keyOf = [];

        foreach (array_keys(DemoProducts::all()) as $key) {
            $keyOf[$b->id("product|{$key}")] = $key;
        }

        foreach ($b->shops as ['shop' => $shop]) {
            $this->saleMovements($b, $book, $shop, $keyOf);
            $rng = $b->rng("stock|{$shop->branchId}");

            foreach (DemoProducts::all() as $key => $p) {
                $target = $this->target($rng, $p);
                $this->product($b, $push, $book, $shop, $rng, $key, $p, $target);
            }
        }
    }

    /**
     * @param  array<string, string>  $keyOf  product id => key
     */
    private function saleMovements(DemoBusiness $b, StockBook $book, DemoShop $shop, array $keyOf): void
    {
        $lines = DB::table('sale_lines as l')->join('sales as s', fn ($j) => $j->on('s.id', '=', 'l.sale_id')->on('s.company_id', '=', 'l.company_id'))
            ->where('s.company_id', $b->companyId)->where('s.branch_id', $shop->branchId)->where('s.status', 'completed')
            ->where('s.trading_day', '>=', $b->date(self::SALE_MOVEMENT_DAYS - 1))
            ->get(['l.id', 'l.product_id', 'l.base_qty', 'l.cost_at_sale', 's.id as sale_id', 's.type', 's.user_id', 's.register_id', 's.completed_at']);

        foreach ($lines as $l) {
            $key = $keyOf[(string) $l->product_id] ?? null;

            if ($key === null) {
                continue;
            }

            $qty = (float) $l->base_qty;
            $book->move($shop, $key, $l->type === 'refund' ? 'refund' : 'sale', -$qty, CarbonImmutable::parse((string) $l->completed_at, 'UTC'),
                DemoProducts::get($key)['cost'], 'Sale', (string) $l->sale_id, (string) $l->id, (string) $l->user_id, null, '', (string) $l->register_id);
        }
    }

    /**
     * Stock on hand: 1.5% negative (sold before the delivery was booked in), 6% low or out, the rest between
     * min and max. Newspapers are not stock-tracked.
     *
     * @param  array<string, mixed>  $p
     */
    private function target(Randomizer $rng, array $p): int
    {
        $case = (int) $p['case'];
        $min = max(2, intdiv($case, 2));
        $roll = $rng->nextFloat();

        return match (true) {
            $p['department'] === 'newspapers' => 0,
            $roll < 0.015 => -$rng->getInt(1, 4),
            $roll < 0.075 => $rng->getInt(0, $min - 1),
            default => $rng->getInt($min, $case * 3),
        };
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function product(DemoBusiness $b, DemoPush $push, StockBook $book, DemoShop $shop, Randomizer $rng, string $key, array $p, int $target): void
    {
        $productId = $b->id("product|{$key}");
        $tracked = $p['department'] !== 'newspapers';
        $moves = $tracked ? $book->of($shop, $key) : [];
        $start = $target - array_sum(array_column($moves, 'delta'));
        $manager = DemoStaff::manager($shop);
        $register = $shop->registers[0]['id'];

        if ($start > 0) {
            array_unshift($moves, [
                'id' => $shop->id("movement|opening|{$key}"), 'type' => 'openingStock', 'delta' => (float) $start, 'at' => $b->at($b->history + 1, 7),
                'cost' => $p['cost'], 'refType' => 'StockTake', 'refId' => $shop->id('opening-count'), 'refLineId' => '', 'reasonId' => null,
                'note' => 'Opening stock count', 'userId' => $manager, 'registerId' => null,
            ]);
            $start = 0;
        }

        $qty = (float) $start;

        foreach ($moves as $m) {
            $push->add($shop, 'StockMovement', $m['id'], [
                'productId' => $productId, 'type' => $m['type'], 'qtyDelta' => $m['delta'], 'qtyBefore' => $qty, 'qtyAfter' => $qty + $m['delta'],
                'unitCost' => $m['cost'] / 100, 'reasonId' => $m['reasonId'], 'note' => $m['note'], 'refType' => $m['refType'], 'refId' => $m['refId'],
                'refLineId' => $m['refLineId'], 'userId' => $m['userId'], 'at' => DemoBusiness::iso($m['at']), 'registerId' => $m['registerId'] ?? $register,
                'branchId' => $shop->branchId,
            ], $m['at']);
            $qty += $m['delta'];
        }

        $case = (int) $p['case'];
        $push->add($shop, 'BranchProduct', $shop->id("branch-product|{$key}"), [
            'productId' => $productId, 'isActive' => true, 'priceOverride' => null, 'qtyOnHand' => $target, 'qtyReserved' => 0,
            'qtyAvailable' => $target, 'isStockTracked' => $tracked, 'reorderPoint' => $tracked ? max(2, intdiv($case, 2)) : null,
            'minQty' => $tracked ? max(2, intdiv($case, 2)) : null, 'maxQty' => $tracked ? $case * 3 : null, 'branchId' => $shop->branchId,
        ], $b->now->subMinutes(10), $b->at($b->history + 1, 7));

        if ($target > 0) {
            $this->layers($b, $push, $book, $shop, $rng, $key, $p, $target);
        }
    }

    /**
     * FIFO layers (newest delivery first, the rest from an older, slightly cheaper one) and, for short-life lines,
     * dated batches with their date checks.
     *
     * @param  array<string, mixed>  $p
     */
    private function layers(DemoBusiness $b, DemoPush $push, StockBook $book, DemoShop $shop, Randomizer $rng, string $key, array $p, int $target): void
    {
        $productId = $b->id("product|{$key}");
        $last = $book->lastDelivery($shop, $key) ?? ['at' => $b->at($b->history + 1, 7), 'refId' => $shop->id('opening-count'), 'cost' => $p['cost']];
        $newest = min($target, max(1, (int) $p['case']));
        $layers = [[$newest, $last['at'], $last['cost'], $last['refId']]];

        if ($target > $newest) {
            $layers[] = [$target - $newest, $last['at']->subDays(7), (int) round($last['cost'] * 0.97), $shop->id('opening-count')];
        }

        foreach ($layers as $i => [$qty, $at, $cost, $ref]) {
            $push->add($shop, 'FifoStockLayer', $shop->id("fifo|{$key}|{$i}"), [
                'productId' => $productId, 'receivedAt' => DemoBusiness::iso($at), 'unitCost' => $cost / 100, 'qtyRemaining' => $qty,
                'refType' => $i === 0 && $ref !== $shop->id('opening-count') ? 'GoodsReceipt' : 'OpeningStock', 'refId' => $ref, 'branchId' => $shop->branchId,
            ], $at);

            if (! $p['expiry']) {
                continue;
            }

            $days = match (true) {
                $p['department'] === 'frozen' => $rng->getInt(40, 200), $rng->nextFloat() < 0.04 => -$rng->getInt(1, 2), $rng->nextFloat() < 0.14 => $rng->getInt(0, 2), default => $rng->getInt(3, 14)
            } + $i * 2;
            $expiry = CarbonImmutable::parse($b->today, 'UTC')->addDays($days);
            $layerId = $shop->id("batch|{$key}|{$i}");
            $push->add($shop, 'StockLayer', $layerId, [
                'productId' => $productId, 'grnLineId' => '', 'qtyRemaining' => $qty, 'unitCost' => $cost / 100, 'receivedAt' => DemoBusiness::iso($at),
                'expiryDate' => $expiry->toDateString(), 'batchNo' => 'L'.$at->format('ymd').substr(md5($layerId), 0, 2), 'branchId' => $shop->branchId,
            ], $at);

            if ($days <= 2) {
                $action = $days < 0 ? 'wasted' : ($days === 0 ? 'reduced' : 'ok');
                $checked = $b->at(0, 7, 40) > $b->now ? $b->at(1, 18, 0) : $b->at(0, 7, 40);
                $push->add($shop, 'DateCheck', $shop->id("datecheck|{$key}|{$i}"), [
                    'stockLayerId' => $layerId, 'productId' => $productId, 'checkedByUserId' => $shop->cashiers[0], 'checkedAt' => DemoBusiness::iso($checked),
                    'action' => $action, 'markdownPercent' => $action === 'reduced' ? 50 : null,
                    'note' => match ($action) {
                        'wasted' => 'Past date, binned', 'reduced' => 'Yellow sticker, half price', default => 'Within date, front of shelf'
                    },
                    'branchId' => $shop->branchId,
                ], $checked);
            }
        }
    }
}
