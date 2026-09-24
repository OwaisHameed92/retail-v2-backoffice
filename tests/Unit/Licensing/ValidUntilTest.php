<?php

use App\Domain\Licensing\Signing\ValidUntil;
use Carbon\CarbonImmutable;

$utc = fn (string $time) => CarbonImmutable::parse($time, 'UTC');

it('uses the offline limit when the licence runs well past it', function () use ($utc) {
    $result = ValidUntil::compute($utc('2026-09-24 10:00:00'), 14, $utc('2027-09-24 00:00:00'), 7);

    expect($result->toIso8601ZuluString())->toBe('2026-10-08T10:00:00Z');
});

it('uses expiry plus grace when that comes first', function () use ($utc) {
    $result = ValidUntil::compute($utc('2026-09-24 10:00:00'), 14, $utc('2026-09-28 00:00:00'), 3);

    expect($result->toIso8601ZuluString())->toBe('2026-10-01T00:00:00Z');
});

it('returns the shared value when both limits are equal', function () use ($utc) {
    $result = ValidUntil::compute($utc('2026-09-24 00:00:00'), 14, $utc('2026-10-01 00:00:00'), 7);

    expect($result->toIso8601ZuluString())->toBe('2026-10-08T00:00:00Z');
});

it('handles zero grace days (validUntil = expiresAt)', function () use ($utc) {
    $result = ValidUntil::compute($utc('2026-09-24 00:00:00'), 14, $utc('2026-09-30 12:30:00'), 0);

    expect($result->toIso8601ZuluString())->toBe('2026-09-30T12:30:00Z');
});

it('handles zero offline days (validUntil = issuedAt)', function () use ($utc) {
    $result = ValidUntil::compute($utc('2026-09-24 08:00:00'), 0, $utc('2027-01-01 00:00:00'), 7);

    expect($result->toIso8601ZuluString())->toBe('2026-09-24T08:00:00Z');
});

it('returns a time in the past for a licence already beyond its grace period', function () use ($utc) {
    $result = ValidUntil::compute($utc('2026-09-24 00:00:00'), 14, $utc('2026-09-01 00:00:00'), 7);

    expect($result->toIso8601ZuluString())->toBe('2026-09-08T00:00:00Z')
        ->and($result->lessThan($utc('2026-09-24 00:00:00')))->toBeTrue();
});

it('counts days as exact 24 hours in UTC across a UK clock change', function () {
    // 25 Oct 2026 is the BST -> GMT change; 14 days from London 12:00 BST (11:00Z) is 11:00Z, i.e. 11:00 GMT.
    $issued = CarbonImmutable::parse('2026-10-20 12:00:00', 'Europe/London');
    $result = ValidUntil::compute($issued, 14, CarbonImmutable::parse('2027-10-20', 'UTC'), 7);

    expect($result->getTimezone()->getName())->toBeIn(['UTC', '+00:00', 'Z'])
        ->and($result->toIso8601ZuluString())->toBe('2026-11-03T11:00:00Z')
        ->and($result->getTimestamp() - $issued->getTimestamp())->toBe(14 * 86400);
});

it('normalises inputs given in other time zones', function () {
    $result = ValidUntil::compute(
        CarbonImmutable::parse('2026-09-24T10:00:00+05:00'),
        14,
        CarbonImmutable::parse('2026-09-25T00:00:00-04:00'),
        1,
    );

    expect($result->toIso8601ZuluString())->toBe('2026-09-26T04:00:00Z');
});

it('truncates to whole seconds, never rounding up', function () use ($utc) {
    $result = ValidUntil::compute($utc('2026-09-24 10:00:00.999999'), 14, $utc('2030-01-01'), 7);

    expect($result->format('Y-m-d H:i:s.u'))->toBe('2026-10-08 10:00:00.000000');
});

it('rejects negative day counts', function (int $offline, int $grace) use ($utc) {
    ValidUntil::compute($utc('2026-09-24'), $offline, $utc('2026-10-24'), $grace);
})->throws(InvalidArgumentException::class)->with([[-1, 7], [14, -1]]);
