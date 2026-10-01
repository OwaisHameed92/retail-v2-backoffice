<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\DemoStaff;
use App\Domain\Demo\Support\StockBook;
use App\Domain\Reporting\Demo\DemoShop;

/**
 * Two stock takes per shop: a full count of confectionery three weeks ago, approved, whose variances became stock
 * movements; and a blind count of the spirits shelf yesterday, in review with one line flagged for a recount. Plus
 * the day-to-day losses: out-of-date waste, breakages, count corrections, a theft and expired stock.
 */
final class StockTakeBuilder
{
    public function handle(DemoBusiness $b, DemoPush $push, StockBook $book): void
    {
        foreach ($b->shops as ['shop' => $shop]) {
            $this->count($b, $push, $book, $shop, 1, 'department', 'confectionery', 'Confectionery full count', 21, true);
            $this->count($b, $push, $book, $shop, 2, 'category', 'spirits', 'Spirits shelf (blind count)', 1, false);
            $this->losses($b, $book, $shop);
        }
    }

    private function count(DemoBusiness $b, DemoPush $push, StockBook $book, DemoShop $shop, int $number, string $scope, string $target, string $name, int $daysAgo, bool $approved): void
    {
        $rng = $b->rng("stocktake|{$shop->branchId}|{$number}");
        $field = $scope === 'department' ? 'department' : 'category';
        $products = array_filter(DemoProducts::all(), fn (array $p) => $p[$field] === $target);
        $started = $b->at($daysAgo, 18, 30);
        $approvedAt = $started->addHours(2);
        $takeId = $shop->id("stocktake|{$number}");
        $manager = DemoStaff::manager($shop);
        $counter = $shop->cashiers[0];
        $sections = [$shop->id("stocktake|{$number}|front") => 'Front of shop', $shop->id("stocktake|{$number}|back") => 'Stock room'];
        $sectionIds = array_keys($sections);
        $recountDone = false;

        foreach ($sections as $sectionId => $sectionName) {
            $push->add($shop, 'StockTakeSection', $sectionId, [
                'stockTakeId' => $takeId, 'name' => $sectionName, 'assignedUserId' => $sectionName === 'Stock room' ? $manager : $counter,
                'isComplete' => true, 'branchId' => $shop->branchId,
            ], $approvedAt, $started);
        }

        foreach (array_values($products) as $i => $p) {
            $snapshot = $rng->getInt(0, 3 * (int) $p['case']);
            $variance = match (true) {
                $rng->nextFloat() < 0.72 => 0, $rng->nextFloat() < 0.8 => -$rng->getInt(1, 3), default => $rng->getInt(1, 2)
            };
            $counted = max(0, $snapshot + $variance);
            $variance = $counted - $snapshot;
            $recount = ! $approved && ! $recountDone && abs($variance) >= 2;
            $recountDone = $recountDone || $recount;
            $countedAt = $started->addMinutes(3 + $i * 2);
            $lineId = $shop->id("stocktake|{$number}|{$p['key']}");

            $push->add($shop, 'StockTakeLine', $lineId, [
                'stockTakeId' => $takeId, 'productId' => $b->id("product|{$p['key']}"), 'productName' => $p['name'], 'sectionId' => $sectionIds[$i % 2],
                'snapshotQty' => $snapshot, 'countedQty' => $counted, 'unitCost' => $p['cost'] / 100, 'countCount' => $recount ? 1 : ($variance !== 0 && $approved ? 2 : 1),
                'countedByUserId' => $i % 2 === 0 ? $counter : $manager, 'countedAt' => DemoBusiness::iso($countedAt),
                'note' => $variance < 0 && $approved ? 'Checked twice' : '', 'recountRequired' => $recount, 'varianceQty' => $variance,
                'varianceCost' => $variance * $p['cost'] / 100, 'branchId' => $shop->branchId,
            ], $countedAt, $started);

            if ($approved) {
                $book->move($shop, $p['key'], 'stockTake', $variance, $approvedAt, $p['cost'], 'StockTake', $takeId, $lineId, $manager, $b->id('reason|adjust-count'), $name);
            }
        }

        $push->add($shop, 'StockTake', $takeId, [
            'reference' => sprintf('ST-%s-%04d', $shop->branchCode, $number), 'name' => $name, 'scope' => $scope, 'scopeId' => $b->id("{$scope}|{$target}"),
            'status' => $approved ? 'approved' : 'review', 'isBlind' => ! $approved, 'countUncountedAsZero' => false, 'startedByUserId' => $manager,
            'startedAt' => DemoBusiness::iso($started), 'approvedByUserId' => $approved ? $manager : '', 'approvedAt' => $approved ? DemoBusiness::iso($approvedAt) : null,
            'cancelledAt' => null, 'recountThresholdValue' => 10, 'recountThresholdPercent' => 10, 'approvalOverValue' => 100,
            'note' => $approved ? 'Quarterly confectionery count' : 'Spot check after a till refund query', 'isHighValueCount' => ! $approved, 'branchId' => $shop->branchId,
        ], $approved ? $approvedAt : $started->addHour(), $started);
    }

    /** Waste, breakages, corrections, theft and expired stock over the history window. */
    private function losses(DemoBusiness $b, StockBook $book, DemoShop $shop): void
    {
        $rng = $b->rng("losses|{$shop->branchId}");
        $short = array_keys(array_filter(DemoProducts::all(), fn (array $p) => $p['expiry'] && $p['department'] !== 'frozen'));
        $all = array_keys(DemoProducts::all());
        $kinds = [
            'wastage' => [0.9, $short, 'reason|waste-date', 'Out of date'], 'damaged' => [0.15, $all, 'reason|waste-damaged', 'Dropped and broken'],
            'adjustment' => [0.12, $all, 'reason|adjust-count', 'Shelf count correction'], 'theft' => [0.04, $all, null, 'Seen on CCTV, reported'],
            'expiry' => [0.1, $short, 'reason|waste-date', 'Expired on shelf'],
        ];

        for ($d = $b->history; $d >= 1; $d--) {
            foreach ($kinds as $type => [$rate, $keys, $reason, $note]) {
                if ($rng->nextFloat() >= $rate) {
                    continue;
                }

                $key = $keys[$rng->getInt(0, count($keys) - 1)];
                $p = DemoProducts::get($key);
                $qty = $type === 'adjustment' && $rng->nextFloat() < 0.4 ? $rng->getInt(1, 3) : -$rng->getInt(1, 3);
                $at = $b->at($d, $type === 'wastage' ? 8 : 15, $rng->getInt(0, 59));
                $book->move($shop, $key, $type, $qty, $at, $p['cost'], 'Adjustment', $shop->id("loss|{$d}|{$type}"), '', DemoStaff::manager($shop), $reason === null ? null : $b->id($reason), $note);
            }
        }
    }
}
