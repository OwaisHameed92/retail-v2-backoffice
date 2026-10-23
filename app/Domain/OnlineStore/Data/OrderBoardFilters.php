<?php

namespace App\Domain\OnlineStore\Data;

use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The online orders board's filters (portal → Online orders): one collection shop or every shop, and one day in
 * shop time (default today). Lenient: anything unreadable falls back to the default. A one-shop user is pinned to
 * their own shop, whatever the request says.
 */
final readonly class OrderBoardFilters
{
    /** How far from today the day picker may go. */
    private const MAX_DAYS = 400;

    public function __construct(
        public ?string $shop,
        public string $day,
        public bool $shopLocked,
        public string $today,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input, ?string $restrictedBranchId, ?CarbonImmutable $now = null): self
    {
        $zone = OnlineStoreSwitch::timezone();
        $today = ($now ?? CarbonImmutable::now())->setTimezone($zone)->toDateString();
        $shop = is_string($input['shop'] ?? null) && Ulid::isValid($input['shop']) ? $input['shop'] : null;

        return new self(
            shop: $restrictedBranchId ?? $shop,
            day: self::day($input['day'] ?? null, $today, $zone),
            shopLocked: $restrictedBranchId !== null,
            today: $today,
        );
    }

    public function isToday(): bool
    {
        return $this->day === $this->today;
    }

    /**
     * The day's start and end (exclusive) in UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(): array
    {
        $start = CarbonImmutable::parse($this->day.' 00:00:00', OnlineStoreSwitch::timezone());

        return [$start->utc(), $start->addDay()->utc()];
    }

    /**
     * @return array{shop: string|null, day: string, today: string, isToday: bool, shopLocked: bool}
     */
    public function toArray(): array
    {
        return ['shop' => $this->shop, 'day' => $this->day, 'today' => $this->today, 'isToday' => $this->isToday(), 'shopLocked' => $this->shopLocked];
    }

    private static function day(mixed $value, string $today, string $zone): string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $today;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, $zone);
        } catch (Throwable) {
            return $today;
        }

        if (! $day instanceof CarbonImmutable || $day->toDateString() !== $value) {
            return $today;
        }

        return abs($day->diffInDays(CarbonImmutable::parse($today, $zone))) > self::MAX_DAYS ? $today : $value;
    }
}
