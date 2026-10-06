<?php

namespace App\Domain\Demo\Support;

use App\Domain\Reporting\Demo\DemoIds;
use App\Domain\Reporting\Demo\DemoShop;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * One business being filled by `demo:seed`: its shops (each a Branch and the DemoShop the sales use, so ids match),
 * the days to fill and the clock. Every id is derived from a seed (DemoIds), so a second run makes the same ids.
 */
final readonly class DemoBusiness
{
    /** Days of history the back-office records (deliveries, rota, checks…) cover at least, whatever --days is. */
    public const MIN_HISTORY = 14;

    public string $companyId;

    /** Local date of the first and last (today) trading day, Y-m-d. */
    public string $from;

    public string $today;

    /** Days of back-office history (≥ MIN_HISTORY, ≤ 56). */
    public int $history;

    /**
     * @param  list<array{branch: Branch, shop: DemoShop}>  $shops  active shops with tills, by code
     */
    public function __construct(
        public Company $company,
        public array $shops,
        public int $days,
        public CarbonImmutable $now,
    ) {
        $this->companyId = (string) $company->getKey();
        $this->today = TradingDay::today($now)->format('Y-m-d');
        $this->from = CarbonImmutable::parse($this->today, 'UTC')->subDays(max(1, $days) - 1)->toDateString();
        $this->history = min(56, max(self::MIN_HISTORY, $days));
    }

    /** A fixed id for a business-wide thing (product, supplier, customer…); the same scheme as DemoShop::id. */
    public function id(string $what): string
    {
        return DemoIds::fixed("demo|{$this->companyId}|{$what}");
    }

    /** A repeatable random source for one part of the demo. */
    public function rng(string $seed): Randomizer
    {
        return new Randomizer(new Mt19937(crc32("demo|{$this->companyId}|{$seed}")));
    }

    /** The main shop: business-wide rows are pushed from its till, as a real till would. */
    public function main(): DemoShop
    {
        return $this->shops[0]['shop'];
    }

    /** A local (shop time zone) time on a day "n days ago" (0 = today), as UTC. */
    public function at(int $daysAgo, int $hour, int $minute = 0, int $second = 0): CarbonImmutable
    {
        return CarbonImmutable::parse($this->today, TradingDay::timezone())->subDays($daysAgo)->setTime($hour, $minute, $second)->utc();
    }

    /** Local date "n days ago" (negative = ahead), Y-m-d. */
    public function date(int $daysAgo): string
    {
        return CarbonImmutable::parse($this->today, 'UTC')->subDays($daysAgo)->toDateString();
    }

    /** Whether a time has happened yet (nothing is made in the future except rota and expected deliveries). */
    public function past(CarbonImmutable $at): bool
    {
        return $at <= $this->now;
    }

    public static function iso(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** Pence → pounds as the till sends money (a JSON number). */
    public static function pounds(int $pence): float
    {
        return $pence / 100;
    }

    /**
     * A weighted random key.
     *
     * @template K of array-key
     *
     * @param  array<K, int|float>  $weights
     * @return K
     */
    public static function pick(Randomizer $rng, array $weights): int|string
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
