<?php

namespace App\Domain\Licensing\Signing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * validUntil = min(issuedAt + offlineDays, expiresAt + graceDays), per docs/specs/licence-api-v1.md.
 *
 * Pure: no clock, no config. Days are exact 24-hour periods in UTC (no DST shifts); the result is UTC,
 * truncated to whole seconds (never later than the exact value).
 */
final class ValidUntil
{
    public static function compute(
        CarbonInterface $issuedAt,
        int $offlineDays,
        CarbonInterface $expiresAt,
        int $graceDays,
    ): CarbonImmutable {
        if ($offlineDays < 0 || $graceDays < 0) {
            throw new InvalidArgumentException('offlineDays and graceDays must not be negative.');
        }

        $offlineLimit = CarbonImmutable::instance($issuedAt)->utc()->addSeconds($offlineDays * 86400);
        $paidLimit = CarbonImmutable::instance($expiresAt)->utc()->addSeconds($graceDays * 86400);

        return ($offlineLimit->lessThanOrEqualTo($paidLimit) ? $offlineLimit : $paidLimit)->startOfSecond();
    }
}
