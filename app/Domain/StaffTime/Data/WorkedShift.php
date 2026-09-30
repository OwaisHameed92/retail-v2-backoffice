<?php

namespace App\Domain\StaffTime\Data;

use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;

/**
 * One person's shift built from the till's clock events (module 5.6). Instants are UTC; the shift belongs to the
 * London day it started on, so an overnight shift counts on the day it began.
 *
 * Status: `complete` (clocked in and out), `open` (still clocked in, recently), `missingOut` (clocked in, never
 * clocked out: another clock-in followed or it is older than the open-shift limit) or `missingIn` (a clock-out with
 * no clock-in before it). Only complete shifts count towards hours.
 */
final class WorkedShift
{
    public const COMPLETE = 'complete';

    public const OPEN = 'open';

    public const MISSING_OUT = 'missingOut';

    public const MISSING_IN = 'missingIn';

    /** Paid minutes after rounding (set by HoursMath::round). */
    public int $paidMinutes = 0;

    /** Minutes of this shift beyond the weekly overtime threshold (set by HoursMath::overtime). */
    public int $overtimeMinutes = 0;

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable|null}>  $breaks
     * @param  list<string>  $flags  `breakNotEnded`, `overMaxShift`
     */
    public function __construct(
        public readonly string $userId,
        public readonly ?string $branchId,
        public readonly ?string $registerId,
        public readonly ?CarbonImmutable $clockIn,
        public ?CarbonImmutable $clockOut,
        public string $status,
        public array $breaks = [],
        public array $flags = [],
        public readonly ?string $id = null,
    ) {}

    public function startsAt(): CarbonImmutable
    {
        /** @var CarbonImmutable $at */
        $at = $this->clockIn ?? $this->clockOut;

        return $at;
    }

    public function isComplete(): bool
    {
        return $this->status === self::COMPLETE;
    }

    /** London trading day the shift started on ("Y-m-d"). */
    public function day(): string
    {
        return TradingDay::of($this->startsAt())[0];
    }

    /** Monday of the shift's London week ("Y-m-d"). */
    public function weekStart(): string
    {
        return CarbonImmutable::parse($this->day(), 'UTC')->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    /** Unpaid break minutes (a break still open at clock-out ends at clock-out). */
    public function breakMinutes(): int
    {
        $seconds = 0;

        foreach ($this->breaks as $break) {
            $end = $break['end'] ?? $this->clockOut;

            if ($end !== null && $end->greaterThan($break['start'])) {
                $seconds += $end->getTimestamp() - $break['start']->getTimestamp();
            }
        }

        return (int) round($seconds / 60);
    }

    /** Minutes worked: clock-in to clock-out (real elapsed time, so DST is right) less breaks; 0 unless complete. */
    public function workedMinutes(): int
    {
        if (! $this->isComplete() || $this->clockIn === null || $this->clockOut === null) {
            return 0;
        }

        $elapsed = (int) round(($this->clockOut->getTimestamp() - $this->clockIn->getTimestamp()) / 60);

        return max(0, $elapsed - $this->breakMinutes());
    }
}
