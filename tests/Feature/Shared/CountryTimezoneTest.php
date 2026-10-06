<?php

use App\Domain\Billing\Support\BillingDates;
use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Reporting\Reports\ReportCsv;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Shared\Country\Country;
use App\Domain\TillHealth\Support\HealthThresholds;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

// Phase P1: every shop day, trading day, schedule and printed time uses the country profile's time zone.

/** A Pakistan instance: COUNTRY=PK, the profile rebuilt, and the zone-derived config files loaded again after it. */
function asPakistanInstance(): void
{
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);
    config(['reporting' => require config_path('reporting.php'), 'till-health' => require config_path('till-health.php')]);
}

/** A datetime cell as the report CSV writes it. */
function csvTime(string $utc): string
{
    $table = new ReportTable('t', 'T', [ReportTable::col('at', 'At', 'datetime')], [['at' => $utc]]);
    $lines = ReportCsv::lines(new ReportResult([], [$table]), []);

    return end($lines)[0];
}

// GB golden tests: the UK keeps Europe/London everywhere.

it('resolves Europe/London for reporting, till health, billing and mail on GB', function () {
    expect(Country::zone())->toBe('Europe/London')
        ->and(config('reporting.timezone'))->toBe('Europe/London')
        ->and(TradingDay::timezone()->getName())->toBe('Europe/London')
        ->and(config('till-health.timezone'))->toBe('Europe/London')
        ->and(HealthThresholds::fromConfig()->timezone)->toBe('Europe/London')
        ->and((new HealthThresholds)->timezone)->toBe('Europe/London');
});

it('keeps London trading days, billing days and printed times on GB', function () {
    // 23:30 UTC on a summer day is 00:30 BST the next day.
    $at = CarbonImmutable::parse('2026-07-01 23:30:00', 'UTC');

    expect(TradingDay::of($at))->toBe(['2026-07-02', 0])
        ->and(array_map(fn (CarbonImmutable $t) => $t->toDateTimeString(), TradingDay::window('2026-07-02')))->toBe(['2026-07-01 23:00:00', '2026-07-02 23:00:00'])
        ->and(TradingDay::today($at)->toDateString())->toBe('2026-07-02')
        ->and(BillingDates::today($at)->toDateString())->toBe('2026-07-02')
        ->and(RenewalTerm::endOfLocalDay('2026-07-02')->toDateTimeString())->toBe('2026-07-02 22:59:59')
        ->and(MailFormat::dateTime($at))->toBe('2 July 2026 at 00:30')
        ->and(csvTime('2026-07-01T23:30:00Z'))->toBe('2026-07-02 00:30');
});

it('runs the daily schedules on London time on GB', function () {
    $zones = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'alerts:digest') || str_contains((string) $event->command, 'labels:queue-offers') || str_contains((string) $event->command, '--daily'))
        ->map(fn ($event) => (string) $event->timezone);

    expect($zones)->toHaveCount(3)
        ->and($zones->unique()->values()->all())->toBe(['Europe/London']);
});

// Pakistan: Asia/Karachi (UTC+5, no daylight saving).

it('resolves Asia/Karachi for reporting, till health and the profile on PK', function () {
    asPakistanInstance();

    expect(Country::zone())->toBe('Asia/Karachi')
        ->and(config('reporting.timezone'))->toBe('Asia/Karachi')
        ->and(TradingDay::timezone()->getName())->toBe('Asia/Karachi')
        ->and(HealthThresholds::fromConfig()->timezone)->toBe('Asia/Karachi');
})->group('country-pk');

it('puts the trading day and report day boundary at midnight Karachi on PK', function () {
    asPakistanInstance();

    // 19:30 UTC is 00:30 the next day in Karachi (still 20:30 the same day in London).
    expect(TradingDay::of('2026-07-01 19:30:00'))->toBe(['2026-07-02', 0])
        ->and(TradingDay::of('2026-07-01 18:59:59'))->toBe(['2026-07-01', 23])
        ->and(array_map(fn (CarbonImmutable $t) => $t->toDateTimeString(), TradingDay::window('2026-07-02')))->toBe(['2026-07-01 19:00:00', '2026-07-02 19:00:00'])
        ->and(TradingDay::today(CarbonImmutable::parse('2026-07-01 19:30:00', 'UTC'))->toDateString())->toBe('2026-07-02');
})->group('country-pk');

it('dates billing days and licence expiries in Karachi on PK', function () {
    asPakistanInstance();

    expect(BillingDates::today(CarbonImmutable::parse('2026-07-01 19:30:00', 'UTC'))->toDateString())->toBe('2026-07-02')
        ->and(RenewalTerm::endOfLocalDay('2026-07-02')->toDateTimeString())->toBe('2026-07-02 18:59:59');
})->group('country-pk');

it('prints mail and CSV times in Karachi on PK', function () {
    asPakistanInstance();
    $at = CarbonImmutable::parse('2026-07-01 19:30:00', 'UTC');

    expect(MailFormat::dateTime($at))->toBe('2 July 2026 at 00:30')
        ->and(MailFormat::date($at))->toBe('2 July 2026')
        ->and(csvTime('2026-07-01T19:30:00Z'))->toBe('2026-07-02 00:30');
})->group('country-pk');
