<?php

namespace App\Domain\Ai\MorningSummary\Data;

use App\Domain\Ai\MorningSummary\Queries\MorningFacts;
use App\Domain\Reporting\Data\SalesTotals;

/**
 * One business's morning-summary facts for one trading day, per shop (module 6.3). Computed from data by
 * {@see MorningFacts}; cut per user by SummaryView. Money as decimal strings.
 */
final readonly class CompanyFacts
{
    /**
     * @param  string  $day  yesterday (Y-m-d, London trading day)
     * @param  array<string, string>  $shops  active shop names by id, by name
     * @param  array<string, SalesTotals>  $yesterday
     * @param  array<string, SalesTotals>  $lastWeek  same weekday a week earlier
     * @param  array<string, SalesTotals>  $lastYear  same weekday 52 weeks earlier
     * @param  array<string, array{days: int, refunds: string, voids: string, discounts: string}>  $baseline
     * @param  array<string, array<string, array{name: string, now: string, before: string}>>  $products
     * @param  array<string, list<array{name: string, onHand: string, soldWeek: string}>>  $fastSellers
     * @param  array<string, list<array{type: string, text: string}>>  $tills
     * @param  array<string, array{total: int, counts: array<string, int>, items: list<string>}>  $cash
     * @param  array<string, array{total: int, counts: array<string, int>, items: list<string>}>  $compliance  `*` = every shop
     */
    public function __construct(
        public string $day,
        public array $shops,
        public array $yesterday = [],
        public array $lastWeek = [],
        public array $lastYear = [],
        public array $baseline = [],
        public array $products = [],
        public array $fastSellers = [],
        public array $tills = [],
        public array $cash = [],
        public array $compliance = [],
    ) {}

    public static function empty(string $day): self
    {
        return new self($day, []);
    }
}
