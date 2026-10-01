<?php

namespace App\Domain\Demo\Support;

use App\Domain\Reporting\Demo\DemoShop;
use Carbon\CarbonImmutable;

/**
 * Every stock movement the demo makes (deliveries, damages, returns, transfers, counts, wastage, sales), collected
 * per shop and product so StockBuilder can write them with a consistent qtyBefore → qtyAfter chain that ends at the
 * shop's stock on hand.
 *
 * @phpstan-type Movement array{id: string, type: string, delta: float, at: CarbonImmutable, cost: int, refType: string, refId: string, refLineId: string, reasonId: string|null, note: string, userId: string, registerId: string|null}
 */
final class StockBook
{
    /** @var array<string, array<string, list<Movement>>> branch id => product key => movements */
    private array $moves = [];

    /** @var array<string, array<string, array{at: CarbonImmutable, refId: string, cost: int}>> branch id => product key => last delivery */
    private array $deliveries = [];

    public function move(DemoShop $shop, string $key, string $type, float $delta, CarbonImmutable $at, int $cost, string $refType, string $refId, string $refLineId, string $userId, ?string $reasonId = null, string $note = '', ?string $registerId = null): void
    {
        if ($delta == 0) {
            return;
        }

        $this->moves[$shop->branchId][$key][] = [
            'id' => $shop->id("movement|{$type}|{$refId}|{$refLineId}|{$key}"), 'type' => $type, 'delta' => $delta, 'at' => $at,
            'cost' => $cost, 'refType' => $refType, 'refId' => $refId, 'refLineId' => $refLineId, 'reasonId' => $reasonId,
            'note' => $note, 'userId' => $userId, 'registerId' => $registerId,
        ];
    }

    /** Remembers the newest delivery of a product to a shop (its FIFO layer comes from it). */
    public function delivered(DemoShop $shop, string $key, CarbonImmutable $at, string $refId, int $cost): void
    {
        $last = $this->deliveries[$shop->branchId][$key] ?? null;

        if ($last === null || $last['at'] < $at) {
            $this->deliveries[$shop->branchId][$key] = ['at' => $at, 'refId' => $refId, 'cost' => $cost];
        }
    }

    /**
     * @return array{at: CarbonImmutable, refId: string, cost: int}|null
     */
    public function lastDelivery(DemoShop $shop, string $key): ?array
    {
        return $this->deliveries[$shop->branchId][$key] ?? null;
    }

    /**
     * Movements of one shop and product, oldest first.
     *
     * @return list<Movement>
     */
    public function of(DemoShop $shop, string $key): array
    {
        $moves = $this->moves[$shop->branchId][$key] ?? [];
        usort($moves, fn (array $a, array $b) => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);

        return $moves;
    }

    public function net(DemoShop $shop, string $key): float
    {
        return array_sum(array_column($this->moves[$shop->branchId][$key] ?? [], 'delta'));
    }
}
