<?php

namespace App\Domain\Reporting\Demo;

use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * One shop's trading day of demo sales as sync envelopes (`demo:sales`): footfall by weekday and hour inside
 * opening hours, a slow upward trend, baskets of 1–6 lines that follow the time of day (milk and papers in the
 * morning, alcohol in the evening), multi-buys, carrier bags, cash (with change) or card (sometimes cashback),
 * about 1.2% refunds and 0.8% voided baskets. Seeded by shop and date, so a day always comes out the same; sales
 * after `$now` are left out (today so far).
 */
final class DemoShopDay
{
    public const STREAM = 'demo-sales';

    public function __construct(private readonly DemoBasketBuilder $builder) {}

    /**
     * @param  string  $day  trading day "Y-m-d" (Europe/London)
     * @return array{changes: list<array<string, mixed>>, sales: int, refunds: int, voids: int}
     */
    public function build(DemoShop $shop, string $day, CarbonImmutable $now): array
    {
        $rng = new Randomizer(new Mt19937(crc32("{$shop->seed}|{$day}")));
        $local = CarbonImmutable::parse($day, TradingDay::timezone());
        $ordinal = (int) CarbonImmutable::parse('2024-01-01', 'UTC')->diffInDays(CarbonImmutable::parse($day, 'UTC'));
        $count = (int) round($shop->baseTransactions() * DemoCatalogue::WEEKDAY[$local->dayOfWeekIso] * (1 + $ordinal * 0.0004) * (0.9 + 0.2 * $rng->nextFloat()));
        [$open, $close] = DemoCatalogue::HOURS[$local->dayOfWeekIso === 7 ? 'sunday' : 'week'];
        $hours = array_filter(DemoCatalogue::HOUR_WEIGHTS, fn (int $h) => $h >= $open && $h <= $close, ARRAY_FILTER_USE_KEY);

        $times = [];

        for ($i = 0; $i < $count; $i++) {
            $times[] = $local->setTime(self::pick($rng, $hours), $rng->getInt(0, 59), $rng->getInt(0, 59))->utc();
        }

        sort($times);
        $numbers = [];
        $done = [];
        $rows = [];
        $stats = ['sales' => 0, 'refunds' => 0, 'voids' => 0];

        foreach ($times as $at) {
            $till = count($shop->registers) === 1 || $rng->nextFloat() < 0.62 ? 0 : $rng->getInt(1, count($shop->registers) - 1);
            $register = $shop->registers[$till];
            $hour = (int) $at->setTimezone(TradingDay::timezone())->format('G');
            $user = $shop->cashiers[(($hour < 12 ? 0 : ($hour < 17 ? 1 : 2)) + $till) % 3];
            $numbers[$till] = ($numbers[$till] ?? $ordinal * 10000) + 1;
            $roll = $rng->nextFloat();

            if ($roll < 0.012 && $done !== []) {
                $original = $done[$rng->getInt(0, count($done) - 1)];
                $line = $original['lines'][$rng->getInt(0, count($original['lines']) - 1)];
                $basket = $this->builder->refund($shop, $register, $user, $at, $numbers[$till], $line, $original['tender']);
                $stats['refunds'] += $at <= $now ? 1 : 0;
            } else {
                $items = $this->items($rng, $hour);
                $pay = $this->payment($rng, $items);
                $voided = $roll > 0.992;
                $sale = $this->builder->sale($shop, $register, $user, $at, $numbers[$till], $items, $pay, $voided);
                $basket = $sale['rows'];
                $stats[$voided ? 'voids' : 'sales'] += $at <= $now ? 1 : 0;

                if (! $voided) {
                    $done[] = ['lines' => $sale['lines'], 'tender' => $pay['tender']];
                }
            }

            if ($at <= $now) {
                array_push($rows, ...$basket);
            }
        }

        return ['changes' => $this->envelopes($shop, $rows, $ordinal), ...$stats];
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function items(Randomizer $rng, int $hour): array
    {
        $weights = [];

        foreach (DemoCatalogue::PRODUCTS as $key => $p) {
            $boost = match (true) {
                $p[6] === 'morning' && $hour < 11 => 2.2,
                $p[6] === 'evening' && $hour >= 17 => 2.4,
                $p[6] === 'evening' && $hour < 12 => 0.3,
                default => 1.0,
            };
            $weights[$key] = (int) round($p[5] * $boost * 10);
        }

        $lines = self::pick($rng, DemoCatalogue::BASKET_LINES);
        $items = [];

        while (count($items) < $lines && $weights !== []) {
            $key = self::pick($rng, $weights);
            unset($weights[$key]);
            $qty = isset(DemoCatalogue::MULTIBUYS[$key]) && $rng->nextFloat() < 0.3 ? 2 : self::pick($rng, [1 => 82, 2 => 14, 3 => 4]);
            $items[] = [(string) $key, (int) $qty];
        }

        if ($lines >= 2 && $rng->nextFloat() < 0.18) {
            $items[] = ['bag', 1];
        }

        return $items;
    }

    /**
     * @param  list<array{0: string, 1: int}>  $items
     * @return array{tender: string, cashback: int, tendered: int}
     */
    private function payment(Randomizer $rng, array $items): array
    {
        $total = 0;

        foreach ($items as [$key, $qty]) {
            $total += $qty * ($key === 'bag' ? DemoCatalogue::BAG['price'] : DemoCatalogue::PRODUCTS[$key][2]);
        }

        $cash = $rng->nextFloat() < ($total < 300 ? 0.5 : 0.3);
        $note = [0, 500, 1000, 2000][self::pick($rng, [0 => 25, 1 => 35, 2 => 25, 3 => 15])];
        $tendered = $note === 0 ? $total : (int) (ceil($total / $note) * $note);

        return [
            'tender' => $cash ? 'cash' : 'card',
            'cashback' => ! $cash && $total >= 500 && $rng->nextFloat() < 0.04 ? [1000, 2000][$rng->getInt(0, 1)] : 0,
            'tendered' => $tendered,
        ];
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>}>  $rows
     * @return list<array<string, mixed>>
     */
    private function envelopes(DemoShop $shop, array $rows, int $ordinal): array
    {
        $out = [];

        foreach ($rows as $i => [$entity, $payload]) {
            $out[] = [
                'seq' => $ordinal * 1000000 + $i + 1,
                'entity' => $entity,
                'entityId' => $payload['id'],
                'op' => 'I',
                'version' => 1,
                'companyId' => $shop->companyId,
                'branchId' => $entity === 'Sale' ? $shop->branchId : '',
                'registerId' => $entity === 'Sale' ? $payload['registerId'] : '',
                'at' => $payload['updatedAt'],
                'payload' => $payload,
                'key' => "{$entity}:{$payload['id']}:1",
            ];
        }

        return $out;
    }

    /**
     * A weighted random key.
     *
     * @template K of array-key
     *
     * @param  array<K, int|float>  $weights
     * @return K
     */
    private static function pick(Randomizer $rng, array $weights): int|string
    {
        $target = $rng->nextFloat() * array_sum($weights);

        foreach ($weights as $key => $weight) {
            $target -= $weight;

            if ($target < 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
