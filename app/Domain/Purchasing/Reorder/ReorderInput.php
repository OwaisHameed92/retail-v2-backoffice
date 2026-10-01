<?php

namespace App\Domain\Purchasing\Reorder;

use Carbon\CarbonImmutable;

/**
 * Everything ReorderCalculator needs for one product at one shop (module 6.4). Quantities are units as 4 dp strings.
 * `events`: seasonal events overlapping the order's days with their multiplier and where it came from
 * (`lastYear` = this product's sales at last year's event, `department` = the till's uplift for its department).
 */
final readonly class ReorderInput
{
    /**
     * @param  list<array{name: string, from: string, to: string, factor: string, basis: 'lastYear'|'department'}>  $events
     */
    public function __construct(
        public CarbonImmutable $today,
        public DemandForecast $demand,
        public int $caseQty,
        public string $onHand,
        public string $onOrder = '0',
        public string $inTransit = '0',
        public ?string $minLevel = null,
        public ?string $maxLevel = null,
        public ?string $reorderQty = null,
        public int $leadDays = 2,
        public int $reviewDays = 7,
        public int $safetyDays = 2,
        public ?int $shelfLifeDays = null,
        public array $events = [],
    ) {}

    /**
     * Seasonal multiplier per day ("Y-m-d" → factor); the larger one when events overlap.
     *
     * @return array<string, string>
     */
    public function factors(): array
    {
        $factors = [];

        foreach ($this->events as $event) {
            for ($day = CarbonImmutable::parse($event['from']); $day->toDateString() <= $event['to']; $day = $day->addDay()) {
                $key = $day->toDateString();
                $factors[$key] = isset($factors[$key]) && bccomp($factors[$key], $event['factor'], 6) >= 0 ? $factors[$key] : $event['factor'];
            }
        }

        return $factors;
    }
}
